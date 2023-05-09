<?php
/* export.php - script d'export du catalogue Ecosphères - 3/5/2023
*/
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

ini_set('memory_limit', '1G');

class Rdf {
  // transfomation dun type Location
  static function location(array $derefObject): mixed {
    //return $derefObject;
    foreach ($derefObject['http://www.w3.org/ns/locn#geometry'] as $geom) {
      if ($geom['@type']=='http://www.opengis.net/ont/geosparql#wktLiteral')
        return $geom['@value'];
    }
    die("geosparql#wktLiteral non trouvé pour ".$derefObject['@id']."\n");
  }
  
  static function object(array $tuples, string $id, string $prop): mixed {
    $object = $tuples[$id][$prop];
    if ((count($object) == 1) && isset($object[0]['@value']))
      return $object[0]['@value'];
    elseif ((count($object) == 1) && isset($object[0]['@id'])) {
      $objId = $object[0]['@id'];
      if (!isset($tuples[$objId])) // réf. externe
        return $objId;
      // réf. interne
      $derefObject = $tuples[$objId];
      switch ($type = $derefObject['@type'][0]) {
        case 'http://xmlns.com/foaf/0.1/Organization': {
          if (isset($derefObject['http://xmlns.com/foaf/0.1/name'])
             && isset($derefObject['http://xmlns.com/foaf/0.1/name'][0]['@value'])) {
               $name = $derefObject['http://xmlns.com/foaf/0.1/name'][0]['@value'];
               return "[$name]($objId)";
          }
          else
            return $derefObject;
        }
        case 'http://purl.org/dc/terms/Location': {
          return Rdf::location($derefObject);
        }
        case 'http://www.w3.org/ns/dcat#Distribution': {
          return [];
        }
        default: {
          die("Aucun traitement prévu pour $type");
        }
      }
    }
    return null;
  }
};

class Catalog {
  static array $datasets=[];
  static array $organizations=[];

  static function addCatalog(array $tuples, string $id): void {
    echo 'Catalog = '; print_r($tuples[$id]);
  }
  
  static function addDataset(array $tuples, string $id): void {
    self::$datasets[$id] = [
      '@id' => $id,
    ];
    self::$datasets[$id] = [];
    foreach ([
        'title' => 'http://purl.org/dc/terms/title',
        'description' => 'http://purl.org/dc/terms/description',
        'issued' => 'http://purl.org/dc/terms/issued',
        'modified' => 'http://purl.org/dc/terms/modified',
        'publisher' => 'http://purl.org/dc/terms/publisher',
        'identifier' => 'http://purl.org/dc/terms/identifier',
        'language' => 'http://purl.org/dc/terms/language',
        'spatial' => 'http://purl.org/dc/terms/spatial',
        'accessRights' => 'http://purl.org/dc/terms/accessRights',
        //'distribution' => 'http://www.w3.org/ns/dcat#distribution',
        
      ] as $key => $uri) {
        if (isset($tuples[$id][$uri])) {
          //self::$datasets[$id][$key] = Rdf::object($tuples, $id, $uri);
          unset($tuples[$id][$uri]);
        }
    }
    if ($tuples[$id]['http://purl.org/dc/terms/provenance'] ==  [['@value'=> "{'fr': [], 'en': []}" ]])
      unset($tuples[$id]['http://purl.org/dc/terms/provenance']);
    if ($tuples[$id]['http://www.w3.org/ns/adms#versionNotes'] == [['@value'=> "{'fr': '', 'en': ''}"]])
      unset($tuples[$id]['http://www.w3.org/ns/adms#versionNotes']);
    unset($tuples[$id]['@type']);
    unset($tuples[$id]['@id']);
    self::$datasets[$id]['jsonld'] = $tuples[$id];
  }
  
  static function addOrganization(array $tuples, string $id): void {
    self::$organizations[$id] = [];
    foreach ([
        'name' => 'http://xmlns.com/foaf/0.1/name',
        'mbox'=> 'http://xmlns.com/foaf/0.1/mbox',
        'phone'=> 'http://xmlns.com/foaf/0.1/phone',
        'workplaceHomepage'=> 'http://xmlns.com/foaf/0.1/workplaceHomepage',
      ] as $key => $uri) {
        if (isset($tuples[$id][$uri]))
          self::$organizations[$id][$key] = Rdf::object($tuples, $id, $uri);
    }
    self::$organizations[$id]['jsonld'] = $tuples[$id];
  }
  
  static function show() {
    //echo 'datasets = '; print_r(self::$datasets);
    echo Yaml::dump(['datasets'=> self::$datasets], 5, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    //echo 'organizations = '; print_r(self::$organizations);
    //echo Yaml::dump(['organizations'=> self::$organizations], 5, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
  }
};

$urlPrefix = 'https://preprod.data.developpement-durable.gouv.fr/dcat/catalog';

if ($argc == 1) {
  echo "usage: php $argv[0] {action}\n";
  echo " où {action} vaut:\n";
  echo "  - importTtl - import du catalogue depuis Ecosphères dans des fichiers locaux\n";
  echo "  - importJsonLD - lecture du catalogue depuis Ecosphères en JSON-LD et copie dans des fichiers locaux\n";
  //echo "  - checkMultiple - détecte les triplets présents plusieurs fois\n";
  echo "  - checkBlank - teste si les blankNodes sont utilisés plusieurs fois\n";
  die();
}

// extrait le code HTTP de retour de l'en-tête HTTP
function httpResponseCode(array $header) { return substr($header[0], 9, 3); }

/* fonction remplacée par import()
function importJsonLd(string $url, int $page=1): void {
  if (!is_file("export$page.json")) { // le fichier n'existe pas
    $content = file_get_contents($url);
    if (httpResponseCode($http_response_header) <> 200) { // erreur de lecture
      echo "code=",httpResponseCode($http_response_header),"\n";
      echo "http_response_header[0] = ",$http_response_header[0],"\n";
    }
    else { // la lecture s'est bien passée -> j'enregistre le résultat
      file_put_contents("export$page.json", $content);
      $content = json_decode($content, true, 512,  JSON_THROW_ON_ERROR);
    }
  }
  else { // le fichier existe
    $content = json_decode(file_get_contents("export$page.json"), true, 512,  JSON_THROW_ON_ERROR);
  }
  if (!$content) { // le contenu n'a pu être lu, je génère l'URL de la page suivante
    $nextPage = substr($url, 0, strpos($url, '?'))."?page=".($page+1);
    echo "nextPage=$nextPage\n";
    importJsonLd($nextPage, $page+1);
    die();
  }
  else { // le contenu a été lu, extraction de l'URL de la page suivante
    foreach ($content as $elt) {
      if (in_array('http://www.w3.org/ns/hydra/core#PagedCollection', $elt['@type'])) {
        //print_r($elt);
        $nextPage = $elt['http://www.w3.org/ns/hydra/core#nextPage'][0]['@value'];
        $lastPage = $elt['http://www.w3.org/ns/hydra/core#lastPage'][0]['@value'];
        echo "nextPage=$nextPage / lastPage=$lastPage\n";
        importJsonLd($nextPage, $page+1);
        die();
      }
    }
  }
}*/

// importe l'export Turtle ou JSON-LD
// Pour le format JSON-LD le catalogue est construit
// lorque le fichier est absent:
//   si $skip est faux alors le site est interrogé
//   sinon ($skip vrai) alors la page est sautée et comptée comme erreur
// Si $lastPage est indiquée alors la lecture s'arrête à cette page,
// sinon elle vaut 0 et la dernière page est lue dans une des pages.
// Si $firstPage est fixée alors la lecture commence à cette page, sinon elle vaut 1.
function import(string $urlPrefix, string $format, bool $skip=false, int $lastPage=0, int $firstPage=1): array {
  $fmt = ($format=='ttl') ? 'ttl' : 'json';
  $errors = []; // erreur en array [{nopage} => {libellé}]
  for ($page = $firstPage; ($lastPage == 0) || ($page <= $lastPage); $page++) {
    if (!is_file("$fmt/export$page.$fmt")) { // le fichier n'existe pas
      if ($skip) {
        $errors[$page] = "fichier absent";
        continue;
      }
      $urlPage = "$urlPrefix/$format?page=$page";
      echo "lecture de $urlPage\n";
      $content = @file_get_contents($urlPage);
      if (httpResponseCode($http_response_header) <> 200) { // erreur de lecture
        echo "code=",httpResponseCode($http_response_header),"\n";
        echo "http_response_header[0] = ",$http_response_header[0],"\n";
        $errors[$page] = $http_response_header[0];
        $content = false;
      }
      else { // la lecture s'est bien passée -> j'enregistre le résultat
        file_put_contents("$fmt/export$page.$fmt", $content);
      }
    }
    else {
      $content = file_get_contents("$fmt/export$page.$fmt");
    }
    if ($content && ($format == 'jsonld')) { // si format est JSOn-LD alors content transformé en array
      $content = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
    
    // détermination de $lastPage si elle est indéfinie et si $content est défini
    if ($content && ($lastPage == 0)) {
      if ($format == 'ttl') {
        if (!preg_match('!\shydra:lastPage "http[^?]+\?page=(\d+)"\s!', $content, $m)) {
          die("lastPage non détectée\n");
        }
        $lastPage = $m[1];
      }
      elseif ($format == 'jsonld') {
        foreach ($content as $elt) {
          if (in_array('http://www.w3.org/ns/hydra/core#PagedCollection', $elt['@type'])) {
            //print_r($elt);
            $lastPage = $elt['http://www.w3.org/ns/hydra/core#lastPage'][0]['@value'];
            if (!preg_match('!\?page=(\d+)$!', $lastPage, $m))
              die("erreur de preg_match sur $lastPage\n");
            $lastPage = $m[1];
          }
        }
      }
      else
        die("format $format inconnu\n");
      echo "lastPage=$lastPage\n";
    }
    
    // Si content et jsonld alors analyse des données pour structurer le catalogue
    if ($content && ($format == 'jsonld')) {
      //echo "content of page $page = "; print_r($content);
      echo "nbelts of page $page = ",count($content),"\n";
      $tuples = []; // éléments indexés sur @id
      foreach ($content as $no => $elt) {
        $tuples[$elt['@id']] = $elt;
        unset($content[$no]);
      }
      $content = null;
      //echo 'tuples = '; print_r($tuples);
      foreach ($tuples as $id => $tuple) {
        if (in_array('http://www.w3.org/ns/dcat#Catalog', $tuple['@type']))
          Catalog::addCatalog($tuples, $id);
        if (in_array('http://www.w3.org/ns/dcat#Dataset', $tuple['@type']))
          Catalog::addDataset($tuples, $id);
        elseif (in_array('http://xmlns.com/foaf/0.1/Organization', $tuple['@type']))
          Catalog::addOrganization($tuples, $id);
      }
    }
  }
  Catalog::show();
  return $errors;
}

// vérifie qu'un triplet (o, p, v) n'est pas défini plusieurs fois
/*function checkMultiple($lastPage = 0, $firstPage = 1): void {
  for ($page = $firstPage; ($lastPage == 0) || ($page <= $lastPage); $page++) {
    if (is_file("json/export$page.json")) {
      $content = file_get_contents("json/export$page.json");
      $content = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
      
      // détermination de $lastPage si elle est indéfinie
      if ($lastPage == 0) {
        foreach ($content as $elt) {
          if (in_array('http://www.w3.org/ns/hydra/core#PagedCollection', $elt['@type'])) {
            //print_r($elt);
            $lastPage = $elt['http://www.w3.org/ns/hydra/core#lastPage'][0]['@value'];
          }
        }
        if (!preg_match('!\?page=(\d+)$!', $lastPage, $m))
          die("erreur de preg_match sur $lastPage\n");
        $lastPage = $m[1];
        echo "lastPage=$lastPage\n";
      }
    
      $triplets = []; // [{objet} => [{property} => [{encodedValue} => {nbre}]]]
      foreach ($content as $elt) {
        //echo 'elt ='; print_r($elt);
        $id = $elt['@id'];
        unset($elt[$id]);
        foreach ($elt as $p => $value) {
          $encVal = md5(json_encode($value));
          if (!isset($triplets[$id][$p][$encVal]))
            $triplets[$id][$p][$encVal] = 1;
          else
            $triplets[$id][$p][$encVal]++;
        }
      }
    }
  }
  foreach ($triplets as $id => $pval) {
    foreach ($pval as $p => $vals) {
      foreach ($vals as $val => $nbre) {
        if ($nbre <> 1)
          echo "$id $p $val -> $nbre\n";
      }
    }
  }
}*/

// teste si les blankNodes sont utilisés plusieurs fois
function checkBlank($lastPage = 0, $firstPage = 1): void {
  for ($page = $firstPage; ($lastPage == 0) || ($page <= $lastPage); $page++) {
    if (is_file("json/export$page.json")) {
      echo "page $page\n";
      $content = file_get_contents("json/export$page.json");
      $content = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
      
      // détermination de $lastPage si elle est indéfinie
      if ($lastPage == 0) {
        foreach ($content as $elt) {
          if (in_array('http://www.w3.org/ns/hydra/core#PagedCollection', $elt['@type'])) {
            //print_r($elt);
            $lastPage = $elt['http://www.w3.org/ns/hydra/core#lastPage'][0]['@value'];
          }
        }
        if (!preg_match('!\?page=(\d+)$!', $lastPage, $m))
          die("erreur de preg_match sur $lastPage\n");
        $lastPage = $m[1];
        echo "lastPage=$lastPage\n";
      }
    
      $blankNodes = []; // [$id => ['def'=> 1, 'usedBy'=> [{id_utilisateur}]]] 
      foreach ($content as $elt) {
        $id = $elt['@id'];
        if (preg_match('!^_:!', $id)) {
          if (!isset($blankNodes[$id]['def']))
            $blankNodes[$id]['def'] = 1;
          else
            die("$id défini plusieurs fois\n");
        }
      }
      
      foreach ($content as $elt) {
        //echo 'elt = '; print_r($elt);
        $id = $elt['@id'];
        unset($elt['@id']);
        foreach ($elt as $p => $values) {
          if (!is_array($values)) {
            echo "$p not an array\n";
            continue;
          }
          foreach ($values as $value) {
            if (isset($value['@id']) && preg_match('!^_:!', $value['@id'])) {
              $bnid = $value['@id'];
              //echo "$bnid est l'id d'un blank node\n";
              if (!isset($blankNodes[$bnid]['usedBy']))
                $blankNodes[$bnid]['usedBy'] = [];
              $blankNodes[$bnid]['usedBy'][] = $id;
            }
          }
        }
      }
      foreach ($blankNodes as $id => $blankNode) {
        if( ($blankNode['def']<>1) || (count($blankNode['usedBy'])<>1))
          print_r($blankNode);
      }
    }
  }
}

switch ($argv[1]) {
  case 'importTtl': {
    $errors = import($urlPrefix, 'ttl');
    break;
  }
  
  case 'importJsonLD': {
    $errors = import($urlPrefix, 'jsonld', true);
    break;
  }
  
  /*case 'checkMultiple': {
    checkMultiple();
    die();
  }*/

  case 'checkBlank': {
    checkBlank();
    die();
  }
  
  default: {
    die("$argv[1] ne correspond à aucune action\n");
  }
}

if ($errors) {
  echo "Pages en erreur:\n";
  foreach ($errors as $page => $error)
    echo "  $page: $error\n";
}


if (0) { // conversion en Turtle et affichage
  $graph = new \EasyRdf\Graph('http://ontologies.georef.eu/2021/8/covadis-ppr');
  $graph->parse(json_encode($data), 'jsonld', 'http://ontologies.georef.eu/2021/8/covadis-ppr');

  header('Content-type: text/plain; charset="utf-8"');
  die($graph->serialise('turtle'));
}
