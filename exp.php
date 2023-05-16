<?php
/* export.php - script d'export du catalogue Ecosphères - 10/5/2023
 16/5/2023:
  - définition de la classe PropVal
 15/5/2023:
  - amélioration des rectifications sur les textes encodées en Yaml
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
require_once __DIR__.'/statem.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

ini_set('memory_limit', '1G');

/* Chaque objet de la classe PropVal correspond à une valeur RDF d'une propriété RDF
** la prop. $props est le dict. des propriétés de la ressource en repr. JSON-LD
** pour chaque objet de RdfClass, $props est de la forme [{propUri} => [{propVal}]] / {propVal} ::= [{key} => {val}] /
**  - {propUri} est l'URI de la propriété
**  - {propVal} correspond à une des valeurs pour la propriété
**  - {key} contient une des valeurs
**    - '@id' indique que {val} correspond à un URI ou un id de blank node
**    - '@type' définit que {val} correspond au type de @value
**    - '@language' définit dans {val} la langue du libellé dans @value
**    - '@value' indique que {val} correspond à la valeur elle-même
**  - {val} est la valeur associée encodée comme chaine de caractères UTF-8
** La liste des combinaisons possibles de {key} est:
**  - ["@id"] - pour un URI
**  - ["@value"] - pour une valeur ni typée ni définie dans une langue
**  - ["@type","@value"] - pour une valeur typée
**  - ["@language","@value"] - pour une chaine définie dans une langue
** Exemples de valeurs:
    // un URI
    "http://xmlns.com/foaf/0.1/homepage": [
      { "@id": "http://catalogue.geo-ide.developpement-durable.gouv.fr" }
    ]
    // une valeur non typée
    "http://www.w3.org/ns/hydra/core#itemsPerPage": [
      { "@value": 100 }
    ]
    // un libellé en français
    "http://purl.org/dc/terms/title": [
      { "@language": "fr", "@value": "GéoIDE Catalogue" }
    ]
    // une date
    "http://purl.org/dc/terms/modified": [
      { "@type": "http://www.w3.org/2001/XMLSchema#dateTime", "@value": "2022-09-21T13:31:46.000249" }
    ],
*/
class PropVal {
  public readonly array $keys; // liste des clés de la représentation JSON-LD
  public readonly ?string $id;
  public readonly ?string $value;
  public readonly ?string $language;
  public readonly ?string $type;
  
  function __construct(array $pval) {
    $this->keys = array_keys($pval);
    switch ($this->keys) {
      case ['@id'] : {
        $this->id = $pval['@id']; $this->value = null; $this->language = null; $this->type = null; break;
      }
      case ['@value'] : {
        $this->id = null; $this->value = $pval['@value']; $this->language = null; $this->type = null; break;
      }
      case ['@type','@value'] : {
        $this->id = null; $this->value = $pval['@value']; $this->language = null; $this->type = $pval['@type']; break;
      }
      case ['@language','@value'] : {
        $this->id = null; $this->value = $pval['@value']; $this->language = $pval['@language']; $this->type = null; break;
      }
      default: {
        print_r(array_keys($pval));
        throw new Exception("Dans PropVal::__construct() keys='".implode(',',$this->keys)."' inconnu");
      }
    }
  }
  
  function asJsonLd(): array {
    if ($this->id)
      return ['@id'=> $this->id];
    elseif ($this->language)
      return ['@language'=> $this->language, '@value'=> $this->value];
    elseif ($this->type)
      return ['@type'=> $this->type, '@value'=> $this->value];
    else
      return ['@value'=> $this->value];
  }

  // simplification d'une des valeurs d'une propriété, $pKey est le nom court de la prop.
  function simplifPval(string $pKey): string|array {
    switch ($this->keys) {
      case ['@id'] : { // SI $pval ne contient qu'un seul champ '@id' alors
        $id = $this->id;
        if (substr($id, 0, 2) <> '_:') {// si PAS blank node alors retourne l'URI + evt. déref.
          if (!($class = (RdfClass::PROP_RANGE[$pKey] ?? null)))
            return "<$id>";
          try {
            $simple = $class::get($id)->simplify();
          } catch (Exception $e) {
            return "<$id>";
          }
          return array_merge(['@id'=> $id], $simple);
        }
        // si le pointeur pointe sur un blank node alors déréférencement du pointeur
        if (!($class = (RdfClass::PROP_RANGE[$pKey] ?? null)))
          throw new Exception("Erreur $pKey absent de RdfClass::PROP_RANGE");
        return $class::get($id)->simplify();
      }
      case ['@value'] : { // SI $pval ne contient qu'un champ '@value' alors simplif par cette valeur
        return $this->value;
      }
      case ['@type','@value'] : {
        if (in_array($this->type, ['http://www.w3.org/2001/XMLSchema#dateTime','http://www.w3.org/2001/XMLSchema#date']))
          return $this->value;
        else
          return $this->value.'['.$this->type.']';
      }
      case ['@language','@value'] : { // SI $pval contient exactement les 2 champs '@value' et '@language' alors simplif dans cette valeur concaténée avec '@' et la langue
        return $this->value.'@'.$this->language;
      }
    }
  }
  
  // simplification des valeurs de propriété $pvals de la forme [[{key} => {value}]], $pKey est le nom court de la prop.
  static function simplifPvals(array $pvals, string $pKey): string|array {
    // $pvals ne contient qu'un seul $pval alors simplif de cette valeur
    if (count($pvals) == 1)
      return $pvals[0]->simplifPval($pKey);
    
    // SI $pvals est une liste de $pval alors simplif de chaque valeur
    $list = [];
    foreach ($pvals as $pval) {
      $list[] = $pval->simplifPval($pKey);
    }
    return $list;
  }
};

/* Classe abstraite portant les méthodes communes à toutes les classes RDF
** ainsi que les constantes CLASS_URI_TO_PHP_NAME définissant le mapping URI -> nom Php
** et PROP_RANGE indiquant le range de certaines propriétés afin de permettre le déréférencement
*/
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
    'http://purl.org/dc/terms/Frequency' => 'Frequency',
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
    'provenance' => 'ProvenanceStatement',
    'format' => 'MediaTypeOrExtent',
    'accrualPeriodicity' => 'Frequency',
    'accessService' => 'DataService',
    'distribution' => 'Distribution',
  ];
  
  protected string $id; // le champ '@id' de la repr. JSON-LD, cad l'URI de la ressource ou l'id blank node
  protected array $types; // le champ '@type' de la repr. JSON-LD, cad la liste des URI des classes de la ressource
  protected array $props=[]; // dict. des propriétés de la ressource de la forme [{propUri} => [PropVal]]
  
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
    foreach ($resource as $pUri => $pvals) {
      foreach ($pvals as $pval) {
        $this->props[$pUri][] = new PropVal($pval);
      }
    }
  }
  
  // corrections d'erreurs ressource par ressource et pas celles qui nécessittent un accès à d'autres ressources
  function rectification(): void {
    return;
    foreach ($this->props as $pUri => &$pvals) {
    
      // Dans la propriété language l'URI est souvent dupliqué avec une chaine encodée en Yaml
      // Dans certains cas seule cette chaine est présente et l'URI est absent
      if ($pUri == 'http://purl.org/dc/terms/language') {
        //echo 'language = '; print_r($pvals);
        if ((count($pvals)==2) && isset($pvals[0]['@id']) && isset($pvals[1]['@value'])) {
          $pvals = [$pvals[0]]; // si URI et chaine alors je ne conserve que l'URI
          //echo 'language rectifié = '; print_r($pvals);
        }
        elseif ((count($pvals)==2) && isset($pvals[0]['@value']) && isset($pvals[1]['@id'])) {
          $pvals = [$pvals[1]]; // si URI et chaine alors je ne conserve que l'URI
          //echo 'language rectifié = '; print_r($pvals);
        }
        elseif ((count($pvals)==1) && isset($pvals[0]['@value'])) { // si chaine encodée en Yaml avec URI alors URI
          if ($pvals[0]['@value'] == "{'uri': 'http://publications.europa.eu/resource/authority/language/FRA'}") {
            $pvals = [['@id'=> 'http://publications.europa.eu/resource/authority/language/FRA']];
            //echo 'language rectifié = '; print_r($pvals);
          }
        }
        continue;
      }
      
      // les licences contiennent parfois une chaine structurée en Yaml avec un URI
      // https://preprod.data.developpement-durable.gouv.fr/dataset/606123c6-d537-485d-ba99-182b0b54d971:
      //  license: '[{''label'': {''fr'': '''', ''en'': ''''}, ''type'': [], ''uri'': ''https://spdx.org/licenses/etalab-2.0''}]'
      if (($pUri == 'http://purl.org/dc/terms/license') && (count($pvals)==1) && isset($pvals[0]['@value'])) {
        if (substr($pvals[0]['@value'], 0, 2)=='[{') {
          try {
            $elts = Yaml::parse($pvals[0]['@value']);
            //echo '$elts = '; print_r($elts);
            if ((count($elts)==1) && isset($elts[0]) && isset($elts[0]['label']) && ($elts[0]['label'] == ['fr'=>'', 'en'=>''])
             && isset($elts[0]['uri']) && $elts[0]['uri']) {
              $pvals = [['@id'=> $elts[0]['uri']]];
            }
          } catch (ParseException $e) {
            var_dump($pvals[0]['@value']);
            throw new Exception("Erreur de Yaml::parse() dans RdfClass::rectification()");
          }
          continue;
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
            continue;
          }
          elseif (!isset($pvals[0]['@language']) && isset($pvals[1]['@language'])) {
            //echo "pUri=$pUri\n";
            //print_r($pvals);
            $pvals = [$pvals[1]];
            //echo "rectification -> "; print_r($this->props[$pUri]);
            continue;
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
      
      { // certaines propriétés contiennent des chaines encodées en Yaml
        if ((count($pvals)==1) && (count($pvals[0])==1) && isset($pvals[0]['@value'])
         && in_array(substr($pvals[0]['@value'], 0, 1), ['{','['])) {
          if ($yaml = self::cleanYaml($pvals[0]['@value'])) {
            $pvals = [$yaml];
          }
          else {
            unset($this->props[$pUri]);
          }
        }
      }
      
      /*{ // certaines propriétés contiennent des chaines encodées en Yaml et sans information
        // '{''fr'': [], ''en'': []}' ou '{''fr'': '''', ''en'': ''''}'
        if ((count($pvals)==1) && (count($pvals[0])==1) && isset($pvals[0]['@value'])
          && in_array($pvals[0]['@value'], ["{'fr': [], 'en': []}", "{'fr': '', 'en': ''}"])) {
          //echo "suppression2 de \"{'fr': [], 'en': []}\" ou \"{'fr': '', 'en': ''}\"\n";
          unset($this->props[$pUri]);
          //echo "count(props) = ",count($this->props),", @id=",$this->id,"\n";
          continue;
        }
      }*/
      
      /*{ // chaines de caractères encodées en Yaml, comme le titre
        //     title: '{''fr'': ''Accès au lien ATOM de téléchargement'', ''en'': ''''}'
        if ((count($pvals)==1) && (count($pvals[0])==1) && isset($pvals[0]['@value'])
         && (substr($pvals[0]['@value'], 0, 1)=='{')) {
          try {
            //echo '$pvals[0][@value] = ',$pvals[0]['@value'],"\n";
            $elts = Yaml::parse($pvals[0]['@value']);
            //echo '$elts = '; print_r($elts);
            if ((count($elts)==2) && isset($elts['fr']) && $elts['fr'] && isset($elts['en']) && !$elts['en']) {
              if (is_string($elts['fr'])) {
                $pvals = [['@value'=> $elts['fr'], '@language'=> 'fr']];
              }
              elseif (is_array($elts['fr']) && (count($elts['fr'])==1) && is_string($elts['fr'][0])) {
                $pvals = [['@value'=> $elts['fr'][0], '@language'=> 'fr']];
              }
              else {
                echo '$elts non interprété, $elts = '; print_r($elts);
                die();
              }
              //echo "Test ok\n";
              //echo '$pvals = '; print_r($pvals);
            }
            else {
              //echo "Test KO\n";
            }
          } catch (ParseException $e) {
            var_dump($pvals[0]['@value']);
            throw new Exception("Erreur de Yaml::parse() dans RdfClass::rectification()");
          }
          continue;
        }
      }*/

    }
  }
  
  // simplification des valeurs des propriétés 
  function simplify(): string|array {
    $simple = [];
    $jsonld = $this->props;
    $propConstUri = (get_called_class())::PROP_KEY_URI;
    foreach ($propConstUri as $uri => $key) {
      if (isset($this->props[$uri])) {
        $simple[$key] = PropVal::simplifPvals($this->props[$uri], $key);
        unset($jsonld[$uri]);
      }
    }
    foreach ($jsonld as $puri => $pvals) {
      foreach ($vals as $pval) {
        $simple['json-ld'][$puri][] = $pval->asJsonLd();
      }
    }
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
    'http://purl.org/dc/terms/accrualPeriodicity' => 'accrualPeriodicity',
    'http://xmlns.com/foaf/0.1/isPrimaryTopicOf' => 'isPrimaryTopicOf',
    'http://www.w3.org/ns/dcat#dataset' => 'dataset',
    'http://www.w3.org/ns/dcat#inSeries' => 'inSeries',
    'http://www.w3.org/ns/dcat#seriesMember' => 'seriesMember',
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

  static function rectifStatements(): void { // rectifie les propriétés accessRights et provenance
    return;
    foreach (self::$all as $id => $dataset) {
      foreach ($dataset->props as $pUri => &$pvals) {
        // Dans la propriété http://purl.org/dc/terms/accessRights, les ressources RightsStatement sont parfois dupliquées
        // dans une chaine bizarrement formattée ;
        // Dans d'autre cas il y a juste une chaine et pas de ressource RightsStatement
        if ($pUri == 'http://purl.org/dc/terms/accessRights') {
          try {
            $pvals = Statement::rectifStatements($pvals, 'RightsStatement');
          } catch (Exception $e) {
            echo '$dataset = '; var_dump($dataset);
            throw new Exception("Erreur dans Statement::rectifStatements()");
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
        if ($geom->type == 'http://www.opengis.net/ont/geosparql#wktLiteral')
          return ['geometry' => $geom->value];
      }
    }
    if (isset($this->props['http://www.w3.org/ns/dcat#bbox'])) {
      foreach ($this->props['http://www.w3.org/ns/dcat#bbox'] as $bbox) {
        if ($bbox->type == 'http://www.opengis.net/ont/geosparql#wktLiteral')
          return ['bbox' => $bbox->value];
      }
    }
    $simple = ['@id'=> $this->id, '@type'=> $this->types];
    foreach ($this->props as $pUri => $pvals) {
      foreach ($pvals as $pval)
        $simple[$pUri][] = $pval->asJsonLd();
    }
    return $simple;
  }
};

class Distribution extends RdfClass {
  const PROP_KEY_URI = [
    'http://purl.org/dc/terms/title' => 'title',
    'http://purl.org/dc/terms/description' => 'description',
    'http://purl.org/dc/terms/format' => 'format',
    'http://purl.org/dc/terms/rights' => 'rights',
    'http://purl.org/dc/terms/license' => 'license',
    'http://purl.org/dc/terms/issued' => 'issued',
    'http://purl.org/dc/terms/created' => 'created',
    'http://purl.org/dc/terms/modified' => 'modified',
    'http://www.w3.org/ns/dcat#accessService' => 'accessService',
    'http://www.w3.org/ns/dcat#accessURL' => 'accessURL',
    'http://www.w3.org/ns/dcat#downloadURL' => 'downloadURL',
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

class Frequency extends RdfClass {
  const PROP_KEY_URI = [
    'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
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
  Dataset::rectifStatements(); // correction des propriétés accessRights qui nécessite que tous les objets soient chargés 
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
