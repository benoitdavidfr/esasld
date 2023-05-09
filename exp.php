<?php
/* export.php - script d'export du catalogue Ecosphères - 8/5/2023
 8/5/2023:
  - refonte de l'aechitecture
*/
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

ini_set('memory_limit', '1G');

abstract class RdfClass {
  // Dict. [{URI de classe RDF ou liste d'URI} => {Nom de classe Php}]
  const CLASS_URI_TO_PHP_NAME = [
    'http://www.w3.org/ns/dcat#Catalog' => 'Catalog',
    'http://www.w3.org/ns/dcat#CatalogRecord' => 'CatalogRecord',
    'http://www.w3.org/ns/dcat#Dataset' => 'Dataset',
    'http://www.w3.org/ns/dcat#Dataset, http://www.w3.org/ns/dcat#DatasetSeries' => 'Dataset',
    'http://www.w3.org/ns/dcat#DatasetSeries, http://www.w3.org/ns/dcat#Dataset' => 'Dataset',
    'http://www.w3.org/ns/dcat#DataService' => 'DataService',
    'http://www.w3.org/ns/dcat#Distribution' => 'Distribution',
    'http://purl.org/dc/terms/Location' => 'Location',
    'http://purl.org/dc/terms/RightsStatement' => 'RightsStatement',
    'http://purl.org/dc/terms/ProvenanceStatement' => 'ProvenanceStatement',
    'http://purl.org/dc/terms/MediaTypeOrExtent' => 'MediaTypeOrExtent',
    'http://purl.org/dc/terms/PeriodOfTime' => 'PeriodOfTime',
    'http://xmlns.com/foaf/0.1/Organization' => 'Organization',
    'http://www.w3.org/2006/vcard/ns#Kind' => 'Kind',
  ];

  protected string $id;
  protected array $types;
  protected array $prop; // liste des propriétés de l'objet en repr. JSON-LD

  static function add(array $elt): void {
    /*$titleOrName = 
      isset($elt['http://purl.org/dc/terms/title']) ? $elt['http://purl.org/dc/terms/title'][0]['@value'] :
      (isset($elt['http://xmlns.com/foaf/0.1/name']) ? $elt['http://xmlns.com/foaf/0.1/name'][0]['@value'] :
       'NoTitleAndNoName');
    echo "Appel de ",get_called_class(),"::add(\"",$titleOrName,"\")\n";*/
    //print_r($elt);
    if (!isset((get_called_class())::$all[$elt['@id']])) {
      (get_called_class())::$all[$elt['@id']] = new (get_called_class())($elt);
    }
    else {
      (get_called_class())::$all[$elt['@id']]->concat($elt);
    }
  }

  static function deref(string $id): array|string {
    if (isset((get_called_class())::$all[$id]))
      return (get_called_class())::$all[$id]->simplify();
    else
      return ["DEREF_ERROR on $id"];
  }
    
  static function show(): void { // affiche les objets hors blank node
    //echo "Appel de ",get_called_class(),"::show()\n";
    //var_dump((get_called_class())::$all); die();
    foreach ((get_called_class())::$all as $id => $elt) {
      if (substr($id, 0, 2) <> '_:') {
        echo Yaml::dump([$id => $elt->simplify()], 5, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
      }
    }
  }
  
  static function showIncludingBlankNodes(): void { // affiche tous les objets
    foreach ((get_called_class())::$all as $id => $elt) {
      echo Yaml::dump([$id => $elt->simplify()], 5, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
  }

  function __construct(array $elt) {
    $this->id = $elt['@id'];
    unset($elt['@id']);
    $this->types = $elt['@type'];
    unset($elt['@type']);
    $this->props = $elt;
  }
  
  // simplification d'une valeur de propriété
  function simplifProperty(array $objects, string $pKey): mixed {
    if ((count($objects) == 1) && (count($objects[0]) == 1) && isset($objects[0]['@value']))
      return $objects[0]['@value'];
    if ((count($objects) == 1) && (count($objects[0]) == 2)
        && isset($objects[0]['@value']) && isset($objects[0]['@language']))
      return $objects[0]['@value'].'@'.$objects[0]['@language'];
    if ((count($objects) == 1) && (count($objects[0]) == 1) && isset($objects[0]['@id'])) {
      $id = $objects[0]['@id'];
      if (substr($id, 0, 2) <> '_:') // PAS blank node
        return $id;
      // cas où le pointeur pointe sur un blank node
      if (!($class = (RdfPredicate::PROP_RANGE[$pKey] ?? null)))
        die("Erreur $pKey absent de RdfPredicate::PROP_RANGE\n");
      return $class::deref($id);
    }
    if ((count($objects) == 1) && (count($objects[0]) == 2) && isset($objects[0]['@type'])
      && in_array($objects[0]['@type'], [
        'http://www.w3.org/2001/XMLSchema#dateTime',
        'http://www.w3.org/2001/XMLSchema#date'
        ]))
      return $objects[0]['@value'];
    
    // liste d'URI, chaque objet ne contient qu'un champ @id
    $uriList = [];
    foreach ($objects as $object) {
      if ((count($object) == 1) && isset($object['@id'])) {
        $objId = $object['@id'];
        if (substr($objId, 0, 2) <> '_:') {  // PAS blank node
          $uriList[] = $objId;
        }
        else {
          if (!($class = (RdfPredicate::PROP_RANGE[$pKey] ?? null)))
            die("Erreur $pKey absent de RdfPredicate::PROP_RANGE\n");
          $uriList[] = $class::deref($objId);
        }
      }
    }
    if (count($objects) == count($uriList))
      return $uriList;
    
    // langage
    if (($pKey == 'language') && ($objects == [['@value'=>'fr'],['@id'=>'fr']])) {
      return 'fr';
    }
    
    // pas interprété
    return ['json-ld'=> $objects];
  }
  
  function simplify(): string|array {
    $simple = [];
    $jsonld = $this->props;
    $propConstUri = (get_called_class())::PROP_KEY_URI;
    foreach ($propConstUri as $uri => $key) {
        if (isset($this->props[$uri])) {
          $p = $this->simplifProperty($this->props[$uri], $key);
          if (!in_array($p, ["{'fr': [], 'en': []}", "{'fr': '', 'en': ''}"]))
            $simple[$key] = $p;
          unset($jsonld[$uri]);
        }
    }
    if ($jsonld)
      $simple['json-ld'] = $jsonld;
    //foreach ([''])
    return $simple;
  }
};

class RdfPredicate {
  // indique par propriété sa classe d'arrivée, nécessaire pour le déréférencement
  const PROP_RANGE = [
    'publisher' => 'Organization',
    'rightsHolder' => 'Organization',
    'spatial' => 'Location',
    'isPrimaryTopicOf' => 'CatalogRecord',
    'inCatalog' => 'Catalog',
    'contactPoint' => 'Kind',
    'accessRights' => 'RightsStatement',
    'distribution' => 'Distribution',
    'provenance' => 'ProvenanceStatement',
  ];
};

class Dataset extends RdfClass {
  const PROP_KEY_URI = [
    'http://purl.org/dc/terms/title' => 'title',
    'http://purl.org/dc/terms/description' => 'description',
    'http://purl.org/dc/terms/issued' => 'issued',
    'http://purl.org/dc/terms/created' => 'created',
    'http://purl.org/dc/terms/modified' => 'modified',
    'http://purl.org/dc/terms/publisher' => 'publisher',
    'http://www.w3.org/ns/dcat#publisher' => 'publisher', // semble une erreur
    'http://purl.org/dc/terms/identifier' => 'identifier',
    'http://www.w3.org/ns/dcat#theme' => 'theme',
    'http://www.w3.org/ns/dcat#keyword' => 'keyword',
    'http://purl.org/dc/terms/language' => 'language',
    'http://purl.org/dc/terms/spatial' => 'spatial',
    'http://purl.org/dc/terms/accessRights' => 'accessRights',
    'http://purl.org/dc/terms/rights_holder' => 'rightsHolder', // ERREUR
    'http://xmlns.com/foaf/0.1/homepage' => 'homepage',
    'http://www.w3.org/ns/dcat#landingPage' => 'landingPage',
    'http://purl.org/dc/terms/conformsTo' => 'conformsTo',
    'http://purl.org/dc/terms/provenance' => 'provenance',
    'http://www.w3.org/ns/adms#versionNotes' => 'versionNotes',
    'http://www.w3.org/ns/adms#status' => 'status',
    'http://xmlns.com/foaf/0.1/isPrimaryTopicOf' => 'isPrimaryTopicOf',
    'http://www.w3.org/ns/dcat#dataset' => 'dataset',
    'http://www.w3.org/ns/dcat#inSeries' => 'inSeries',
    'http://www.w3.org/ns/dcat#distribution' => 'distribution',
    
  ];
  static array $all=[]; // [{id}=> self] -- dict. des objets de la classe
    
  function concat(array $elt): void { // concatene 2 valeurs pour un même URI 
    foreach (['http://www.w3.org/ns/dcat#catalog',
              'http://www.w3.org/ns/dcat#record',
              'http://www.w3.org/ns/dcat#dataset',
              'http://www.w3.org/ns/dcat#service'] as $p) {
      if (isset($this->props[$p]) && isset($elt[$p])) {
        $this->props[$p] = array_merge($this->props[$p], $elt[$p]);
      }
      elseif (!isset($this->props[$p]) && isset($elt[$p])) {
        $this->props[$p] = $elt[$p];
      }
    }
  }
};

class Catalog extends Dataset {
  static array $all=[]; // [{id}=> self] -- dict. de tous les objets de la classe
};

class CatalogRecord extends RdfClass {
  const PROP_KEY_URI = [
    'http://purl.org/dc/terms/identifier' => 'identifier',
    'http://purl.org/dc/terms/language' => 'language',
    'http://purl.org/dc/terms/modified' => 'modified',
    'http://www.w3.org/ns/dcat#contactPoint' => 'contactPoint',
    'http://www.w3.org/ns/dcat#inCatalog' => 'inCatalog',
  ];
  static array $all;
};

class DataService {
  static function add(array $elt): void {}
};

class Organization extends RdfClass {
  const PROP_KEY_URI = [
    'http://xmlns.com/foaf/0.1/name' => 'name',
    'http://xmlns.com/foaf/0.1/mbox' => 'mbox',
    'http://xmlns.com/foaf/0.1/phone' => 'phone',
    'http://xmlns.com/foaf/0.1/workplaceHomepage' => 'workplaceHomepage',
  ];
  static array $all;
  
  function concat(array $elt): void {}
};

class Location extends RdfClass {
  static array $all;
  
  function simplify(): string|array {
    if (isset($this->props['http://www.w3.org/ns/locn#geometry'])) {
      foreach ($this->props['http://www.w3.org/ns/locn#geometry'] as $geom) {
        if ($geom['@type'] == 'http://www.opengis.net/ont/geosparql#wktLiteral')
          return ['geometry' => $geom['@value']];
      }
    }
    if (isset($this->props['http://www.w3.org/ns/dcat#bbox'])) {
      foreach ($this->props['http://www.w3.org/ns/dcat#bbox'] as $bbox) {
        if ($bbox['@type'] == 'http://www.opengis.net/ont/geosparql#wktLiteral')
          return ['bbox' => $bbox['@value']];
      }
    }
    return array_merge(['@id'=> $this->id, '@type'=> $this->types], $this->props);
  }
};

class Distribution extends RdfClass {
  const PROP_KEY_URI = [
  ];

  static array $all;
};

class RightsStatement extends RdfClass {
  const PROP_KEY_URI = [
    'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
  ];

  static array $all;
};

class ProvenanceStatement extends RdfClass {
  const PROP_KEY_URI = [
    'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
  ];

  static array $all;
};

class MediaTypeOrExtent extends RdfClass {
  const PROP_KEY_URI = [
    'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
  ];

  static array $all;
};

class PeriodOfTime extends RdfClass {
  const PROP_KEY_URI = [
    'http://www.w3.org/ns/dcat#startDate' => 'startDate',
    'http://www.w3.org/ns/dcat#endDate' => 'endDate',
  ];

  static array $all;
};

class Kind extends RdfClass {
  const PROP_KEY_URI = [
    'http://www.w3.org/2006/vcard/ns#fn' => 'fn',
    'http://www.w3.org/2006/vcard/ns#hasEmail' => 'hasEmail',
    'http://www.w3.org/2006/vcard/ns#hasURL' => 'hasURL',
  ];
  
  static array $all;
};


$urlPrefix = 'https://preprod.data.developpement-durable.gouv.fr/dcat/catalog';

if ($argc == 1) {
  echo "usage: php $argv[0] {action}\n";
  echo " où {action} vaut:\n";
  echo "  - import - lecture du catalogue depuis Ecosphères en JSON-LD et copie dans des fichiers locaux\n";
  echo "  - errors - afffichage des erreurs rencontrées lors de la lecture du catalogue\n";
  echo "  - catalogs - lecture du catalogue puis affichage des catalogues\n";
  echo "  - datasets - lecture du catalogue puis affichage des jeux de données\n";
  die();
}

// extrait le code HTTP de retour de l'en-tête HTTP
function httpResponseCode(array $header) { return substr($header[0], 9, 3); }

// importe l'export JSON-LD et construit les objets chacun dans leur classe
// lorque le fichier est absent:
//   si $skip est faux alors le site est interrogé
//   sinon ($skip vrai) alors la page est sautée et comptée comme erreur
// Si $lastPage est indiquée alors la lecture s'arrête à cette page,
// sinon elle vaut 0 et la dernière page est lue dans une des pages.
// Si $firstPage est fixée alors la lecture commence à cette page, sinon elle vaut 1.
function import(string $urlPrefix, bool $skip=false, int $lastPage=0, int $firstPage=1): array {
  $errors = []; // erreur en array [{nopage} => {libellé}]
  for ($page = $firstPage; ($lastPage == 0) || ($page <= $lastPage); $page++) {
    if (!is_file("json/export$page.json")) { // le fichier n'existe pas
      if ($skip) {
        $errors[$page] = "fichier absent";
        continue;
      }
      $urlPage = "$urlPrefix/jsonld?page=$page";
      echo "lecture de $urlPage\n";
      $content = @file_get_contents($urlPage);
      if (httpResponseCode($http_response_header) <> 200) { // erreur de lecture
        echo "code=",httpResponseCode($http_response_header),"\n";
        echo "http_response_header[0] = ",$http_response_header[0],"\n";
        $errors[$page] = $http_response_header[0];
        continue;
      }
      else { // la lecture s'est bien passée -> j'enregistre le résultat
        file_put_contents("json/export$page.json", $content);
      }
    }
    else {
      $content = file_get_contents("json/export$page.json");
    }
    $content = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    
    //echo "content of page $page = "; print_r($content);
    echo "nbelts of page $page = ",count($content),"\n";
    
    foreach ($content as $no => $elt) {
      $types = implode(', ', $elt['@type']);
      if ($className = (RdfClass::CLASS_URI_TO_PHP_NAME[$types] ?? null)) {
        $className::add($elt);
      }
      elseif ($types == 'http://www.w3.org/ns/hydra/core#PagedCollection') {
        if ($lastPage == 0) {
          $lastPage = $elt['http://www.w3.org/ns/hydra/core#lastPage'][0]['@value'];
          if (!preg_match('!\?page=(\d+)$!', $lastPage, $m))
            die("erreur de preg_match sur $lastPage\n");
          $lastPage = $m[1];
          echo "lastPage=$lastPage\n";
        }
      }
      else
        die("Types $types non traité\n");
    }
  }
  return $errors;
}

switch ($argv[1]) {
  case 'import': {
    import($urlPrefix, true);
    break;
  }
  case 'errors': {
    $errors = import($urlPrefix, true);
    echo "Pages en erreur:\n";
    foreach ($errors as $page => $error)
      echo "  $page: $error\n";
    break;
  }
  case 'catalogs': {
    import($urlPrefix, true);
    Catalog::show();
    break;
  }
  case 'datasets': {
    import($urlPrefix, true);
    Dataset::show();
    break;
  }
  case 'catalogRecords': {
    import($urlPrefix, true);
    catalogRecord::showIncludingBlankNodes();
    break;
  }
  case 'Kinds': {
    import($urlPrefix, true);
    Kind::showIncludingBlankNodes();
    break;
  }
  case 'RightsStatements': {
    import($urlPrefix, true);
    RightsStatement::showIncludingBlankNodes();
    break;
  }
  case 'ProvenanceStatements': {
    import($urlPrefix, true);
    ProvenanceStatement::showIncludingBlankNodes();
    break;
  }
  case 'PeriodOfTimes': {
    import($urlPrefix, true);
    PeriodOfTime::showIncludingBlankNodes();
    break;
  }
  case 'MediaTypeOrExtents': {
    import($urlPrefix, true);
    MediaTypeOrExtent::showIncludingBlankNodes();
    break;
  }
  
  default: {
    die("$argv[1] ne correspond à aucune action\n");
  }
}
