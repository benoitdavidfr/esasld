<?php
/* export.php - script d'export du catalogue Ecosphères - 10/5/2023
 12/5/2023:
  - ajout de la rectifications des accessRights
 10/5/2023:
  - ajout d'une phase de rectifications après le chargement
 9/5/2023:
  - améliorations
 8/5/2023:
  - refonte de l'aechitecture
*/
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/rightsstat.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

ini_set('memory_limit', '1G');

// Classe abstraite portant les méthodes communes à toutes les classes RDF
// ainsi que les constantes CLASS_URI_TO_PHP_NAME définissant le mapping URI -> nom Php
// et PROP_RANGE indiquant le range de certaines propriétés afin de permettre le déréférencement
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
  // indique par propriété sa classe d'arrivée (range), nécessaire pour le déréférencement
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
    'format' => 'MediaTypeOrExtent',
    'accessService' => 'DataService',
  ];
  
  protected string $id; // le champ '@id' de l'objet en repr. JSON-LD, cad l'URI de l'objet et l'id blank node
  protected array $types; // le champ '@type' de l'objet en repr. JSON-LD, cad la liste des URI des classes
  /* $props est le dict. des propriétés de l'objet en repr. JSON-LD
  ** pour chaque objet de RdfClass, $props est de la forme [{propUri} => [{propVal}]] / {propVal} ::= [{key} => {val}] /
  **  - {propUri} est l'URI de la propriété
  **  - {propVal} correspond à une valeur pour la propriété
  **  - {key} contient une des valeurs
  **    - '@id' indique que {val} contient un URI ou un id de blank node
  **    - '@type' indique le type de {val}
  **    - '@value' indique que {val} contient la valeur elle-même
  **    - '@language' indique la langue du libellé dans {val}
  **  - {val} est la valeur associée encodée comme chaine de caractères UTF8
  */
  protected array $props;
  
  // ajout d'une ressource à la classe
  static function add(array $resource): void {
    /*$titleOrName = 
      isset($elt['http://purl.org/dc/terms/title']) ? $elt['http://purl.org/dc/terms/title'][0]['@value'] :
      (isset($elt['http://xmlns.com/foaf/0.1/name']) ? $elt['http://xmlns.com/foaf/0.1/name'][0]['@value'] :
       'NoTitleAndNoName');
    echo "Appel de ",get_called_class(),"::add(\"",$titleOrName,"\")\n";*/
    //print_r($elt);
    if (!isset((get_called_class())::$all[$resource['@id']])) {
      (get_called_class())::$all[$resource['@id']] = new (get_called_class())($resource);
    }
    else {
      (get_called_class())::$all[$resource['@id']]->concat($resource);
    }
    (get_called_class())::$all[$resource['@id']]->rectification();
  }

  static function get(string $id) { // retourne la ressource de la classe get_called_class() ayant cet $id 
    if (isset((get_called_class())::$all[$id]))
      return (get_called_class())::$all[$id];
    else
      throw new Exception("DEREF_ERROR on $id");
  }
    
  static function show(): void { // affiche les ressources de la classe hors blank nodes 
    //echo "Appel de ",get_called_class(),"::show()\n";
    //var_dump((get_called_class())::$all); die();
    foreach ((get_called_class())::$all as $id => $elt) {
      if (substr($id, 0, 2) <> '_:') {
        echo Yaml::dump([$id => $elt->simplify()], 7, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
      }
    }
  }
  
  static function showIncludingBlankNodes(): void { // affiche tous les ressources de la classe y compris les blank nodes 
    foreach ((get_called_class())::$all as $id => $elt) {
      echo Yaml::dump([$id => $elt->simplify()], 7, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
  }

  function __construct(array $resource) {
    $this->id = $resource['@id'];
    unset($resource['@id']);
    $this->types = $resource['@type'];
    unset($resource['@type']);
    $this->props = $resource;
  }
  
  // corrections d'erreurs objet par objet sauf celles qui nécessittent un accès à d'autres ressources
  function rectification(): void {
    foreach ($this->props as $pUri => &$pvals) {
      // Dans la propriété language l'URI est souvent dupliqué avec une chaine bizarrement formée
      // Dans certains cas seule cette chaine est présente et l'URI est absent
      if ($pUri == 'http://purl.org/dc/terms/language') {
        //echo 'language = '; print_r($pvals);
        if ((count($pvals)==2) && isset($pvals[0]['@id']) && isset($pvals[1]['@value'])) {
          $pvals = [$pvals[0]];
          //echo 'language rectifié = '; print_r($pvals);
        }
        elseif ((count($pvals)==2) && isset($pvals[0]['@value']) && isset($pvals[1]['@id'])) {
          $pvals = [$pvals[1]];
          //echo 'language rectifié = '; print_r($pvals);
        }
        elseif ((count($pvals)==1) && isset($pvals[0]['@value'])) {
          if ($pvals[0]['@value'] == "{'uri': 'http://publications.europa.eu/resource/authority/language/FRA'}") {
            $pvals = [['@id'=> 'http://publications.europa.eu/resource/authority/language/FRA']];
            //echo 'language rectifié = '; print_r($pvals);
          }
        }
      }
      
      { // les chaines de caractères comme celles du titre sont dupliquées avec un élément avec langue et l'autre sans
        if ((count($pvals)==2) && isset($pvals[0]['@value']) && isset($pvals[1]['@value'])
         && ($pvals[0]['@value'] == $pvals[1]['@value'])) {
          if (isset($pvals[0]['@language']) && !isset($pvals[1]['@language'])) {
            //echo "pUri=$pUri\n";
            //print_r($pvals);
            $pvals = [$pvals[0]];
            //echo "rectification -> "; print_r($this->props[$pUri]);
          }
          elseif (!isset($pvals[0]['@language']) && isset($pvals[1]['@language'])) {
            //echo "pUri=$pUri\n";
            //print_r($pvals);
            $pvals = [$pvals[1]];
            //echo "rectification -> "; print_r($this->props[$pUri]);
          }
        }
      }
      
      { // certaines dates sont dupliquées avec un élément dateTime et l'autre date
        if ((count($pvals)==2) && isset($pvals[0]['@type']) && isset($pvals[1]['@type'])
         && isset($pvals[0]['@value']) && isset($pvals[1]['@value'])) {
          if (($pvals[0]['@type'] == 'http://www.w3.org/2001/XMLSchema#date')
           && ($pvals[1]['@type'] == 'http://www.w3.org/2001/XMLSchema#dateTime')) {
            //echo "pUri=$pUri\n";
            //print_r($pvals);
            $pvals = [$pvals[0]];
            //echo "rectification -> "; print_r($this->props[$pUri]);
          }
          elseif (($pvals[0]['@type'] == 'http://www.w3.org/2001/XMLSchema#dateTime')
           && ($pvals[1]['@type'] == 'http://www.w3.org/2001/XMLSchema#date')) {
            //echo "pUri=$pUri\n";
            //print_r($pvals);
            $pvals = [$pvals[1]];
            //echo "rectification -> "; print_r($this->props[$pUri]);
          }
        }
        
      }
    }
  }
  
  // simplification d'une des valeurs d'une propriété $pval de la forme [{key} => {value}], $pKey est le nom court de la prop.
  function simplifPval(array $pval, string $pKey): string|array {
    // SI $pval ne contient qu'un champ '@value' alors simplif par cette valeur
    if ((count($pval) == 1) && isset($pval['@value']))
      return $pval['@value'];
    // SI $pval contient exactement 2 champ '@value' et '@language' alors simplif dans cette valeur concaténée avec '@' et la langue
    if ((count($pval) == 2) && isset($pval['@value']) && isset($pval['@language']))
      return $pval['@value'].'@'.$pval['@language'];
    // SI $pval ne contient qu'un seul champ '@id' alors
    if ((count($pval) == 1) && isset($pval['@id'])) {
      $id = $pval['@id'];
      if (substr($id, 0, 2) <> '_:') // si PAS blank node retourne l'URI
        return $id;
      // si le pointeur pointe sur un blank node alors déréférencement du pointeur
      if (!($class = (RdfClass::PROP_RANGE[$pKey] ?? null)))
        die("Erreur $pKey absent de RdfClass::PROP_RANGE\n");
      return $class::get($id)->simplify();
    }
    // SI $pval est une date ou une date+time ALORS simplif par cette valeur
    if ((count($pval) == 2) && isset($pval['@type'])
      && in_array($pval['@type'], [
        'http://www.w3.org/2001/XMLSchema#dateTime',
        'http://www.w3.org/2001/XMLSchema#date'
        ]))
      return $pval['@value'];
    // SI $pval est de type http://www.w3.org/1999/02/22-rdf-syntax-ns#langString ALORS simplif par cette valeur concaténée
    // avec le type entouré de []
    if ((count($pval) == 2) && isset($pval['@type'])
     && ($pval['@type'] == 'http://www.w3.org/1999/02/22-rdf-syntax-ns#langString'))
      return $pval['@value'].'['.$pval['@type'].']';
    return $pval;
  }
  
  // simplification des valeurs de propriété $pvals de la forme [[{key} => {value}]], $pKey est le nom court de la prop.
  function simplifPvals(array $pvals, string $pKey): string|array {
    // $pvals ne contient qu'un seul $pval alors simplif de cette valeur
    if (count($pvals) == 1)
      return $this->simplifPval($pvals[0], $pKey);
    
    // SI $pvals est une liste de $pval alors simplif de chaque valeur
    $list = [];
    foreach ($pvals as $pval) {
      $list[] = $this->simplifPval($pval, $pKey);
    }
    
    /*if ($pKey == 'language') {
      print_r($pvals);
    }*/
    return $list;
  }
  
  // simplification des valeurs des propriétés 
  function simplify(): string|array {
    $simple = [];
    $jsonld = $this->props;
    $propConstUri = (get_called_class())::PROP_KEY_URI;
    foreach ($propConstUri as $uri => $key) {
      if (isset($this->props[$uri])) {
        $p = $this->simplifPvals($this->props[$uri], $key);
        if (!in_array($p, ["{'fr': [], 'en': []}", "{'fr': '', 'en': ''}"]))
          $simple[$key] = $p;
        unset($jsonld[$uri]);
      }
    }
    if ($jsonld)
      $simple['json-ld'] = $jsonld;
    return $simple;
  }
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
    'http://www.w3.org/ns/dcat#contactPoint' => 'contactPoint',
    'http://purl.org/dc/terms/identifier' => 'identifier',
    'http://www.w3.org/ns/dcat#theme' => 'theme',
    'http://www.w3.org/ns/dcat#keyword' => 'keyword',
    'http://purl.org/dc/terms/language' => 'language',
    'http://purl.org/dc/terms/spatial' => 'spatial',
    'http://purl.org/dc/terms/accessRights' => 'accessRights',
    'http://purl.org/dc/terms/rights_holder' => 'rightsHolder', // ERREUR
    'http://xmlns.com/foaf/0.1/homepage' => 'homepage',
    'http://www.w3.org/ns/dcat#landingPage' => 'landingPage',
    'http://xmlns.com/foaf/0.1/page' => 'page',
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

  static function rectifAccessRights(): void { // rectifie la propriété accessRights 
    foreach (self::$all as $id => $dataset) {
      foreach ($dataset->props as $pUri => &$pvals) {
        // Dans la propriété http://purl.org/dc/terms/accessRights, les ressources RightsStatement sont parfois dupliquées
        // dans une chaine bizarrement formattée ;
        // Dans d'autre cas il y a juste une chaine et pas de ressource RightsStatement
        if ($pUri == 'http://purl.org/dc/terms/accessRights') {
          try {
            $pvals = RightsStatement::rectifAccessRights($pvals);
          } catch (Exception $e) {
            echo '$dataset = '; var_dump($dataset);
            throw new Exception("Erreur dans RightsStatements::rectification()");
          }
        }
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

class DataService extends Dataset {
  const PROP_KEY_URI = [
    'http://purl.org/dc/terms/conformsTo' => 'conformsTo',
  ];
  static array $all;
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
    'http://purl.org/dc/terms/title' => 'title',
    'http://purl.org/dc/terms/format' => 'format',
    'http://purl.org/dc/terms/license' => 'license',
    'http://www.w3.org/ns/dcat#accessService' => 'accessService',
    'http://www.w3.org/ns/dcat#accessURL' => 'accessURL',
    'http://www.w3.org/ns/dcat#downloadURL' => 'downloadURL',
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
    
    foreach ($content as $no => $resource) {
      $types = implode(', ', $resource['@type']); 
      if ($className = (RdfClass::CLASS_URI_TO_PHP_NAME[$types] ?? null)) {
        $className::add($resource);
      }
      elseif ($types == 'http://www.w3.org/ns/hydra/core#PagedCollection') {
        if ($lastPage == 0) {
          $lastPage = $resource['http://www.w3.org/ns/hydra/core#lastPage'][0]['@value'];
          if (!preg_match('!\?page=(\d+)$!', $lastPage, $m))
            throw new Exception("erreur de preg_match sur $lastPage");
          $lastPage = $m[1];
          echo "lastPage=$lastPage\n";
        }
      }
      else
        throw new Exception("Types $types non traité");
    }
  }
  Dataset::rectifAccessRights(); // correction des propriétés accessRights qui nécessite que tous les objets soient chargés 
  return $errors;
}

$firstPage = 1;
$lastPage = 0; // non définie
//$firstPage = 2; $lastPage = 2; // on se limite à la page 2 qui contient des fiches Géo-IDE

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
    //print_r(RdfClass::$pkeys);
    Catalog::show();
    break;
  }
  case 'RightsStatements': {
    import($urlPrefix, true);
    print_r(RightsStatement::$all);
    //Dataset::rectifAccessRights(); // correction des propriétés accessRights qui nécessite que tous les objets soient chargés 
    break;
  }
  case 'datasets': {
    import($urlPrefix, true, $lastPage, $firstPage);
    Dataset::show();
    break;
  }
  case 'DataServices': {
    import($urlPrefix, true, $lastPage, $firstPage);
    DataService::showIncludingBlankNodes();
    break;
  }
  case 'catalogRecords': {
    import($urlPrefix, true, $lastPage, $firstPage);
    catalogRecord::showIncludingBlankNodes();
    break;
  }
  case 'Distributions': {
    import($urlPrefix, true, $lastPage, $firstPage);
    Distribution::showIncludingBlankNodes();
    break;
  }
  case 'Kinds': {
    import($urlPrefix, true, $lastPage, $firstPage);
    Kind::showIncludingBlankNodes();
    break;
  }
  case 'RightsStatements': {
    import($urlPrefix, true, $lastPage, $firstPage);
    RightsStatement::showIncludingBlankNodes();
    break;
  }
  case 'ProvenanceStatements': {
    import($urlPrefix, true, $lastPage, $firstPage);
    ProvenanceStatement::showIncludingBlankNodes();
    break;
  }
  case 'PeriodOfTimes': {
    import($urlPrefix, true, $lastPage, $firstPage);
    PeriodOfTime::showIncludingBlankNodes();
    break;
  }
  case 'MediaTypeOrExtents': {
    import($urlPrefix, true, $lastPage, $firstPage);
    MediaTypeOrExtent::showIncludingBlankNodes();
    break;
  }
  
  default: {
    die("$argv[1] ne correspond à aucune action\n");
  }
}
