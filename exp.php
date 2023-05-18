<?php
{/*PhpDoc:
title: export.php - script de lecture de l'export du catalogue Ecosphères - 18/5/2023
doc: |
  L'objectif de ce script est de lire l'export DCAT d'Ecosphères en JSON-LD afin d'y détecter d'éventuelles erreurs.
  Chaque clase RDF est traduite par une classe Php avec un mapping défini dans RdfClass::CLASS_URI_TO_PHP_NAME
  Outre la détection et correction d'erreurs, le script affiche différents types d'objets de manière simplifiée
  et plus lisible pour les néophytes.
  Cette simplification correspond d'une part à une "compaction JSON-LD" avec un contexte non explicité
  et d'autre part à un embedding d'un certain nombre de ressources associées, par exemple les publisher d'un Dataset.
  Ces ressources associées sont définies par les propriétées définies dans PropVal::PROP_RANGE.
  L'affichage est finalement effectuée en Yaml.

  La classe PropVal facilite l'utilisation en Php de la représentation JSON-LD en définissant
  une structuration d'une valeur RDF d'une propriété RDF d'une ressource.

  La classe abstraite RdfClass porte une grande partie du code et est la classe mère de toutes les classes Php
  traduisant les classes RDF. Pour chacune de ces dernières:
   - la constante de classe PROP_KEY_URI liste les propriétés RDF en définissant leur raccourci,
     dans l'export DCAT sans y être défini, par exemple dans la classe Standard que l'URI https://tools.ietf.org/html/rfc4287'
     correspond au format de syndication Atom,
   - la propriété statique $all contient les objets correspondant aux ressources lues en mémoire à partir du fichier JSON-LD.
  
  Le script utilise un registre stocké dans le fichier registre.yaml qui permet d'associer des étiquettes à un certain
  nombre d'URIs non définis dans l'export.
  
  Prolongations éventuelles:
   - il erait utile de formaliser le contexte associé à la simplification et de s'assurer de son exactitude
   - l'affichage simplifié pourrait être un export DCAT valide en YAML-LD
   - il serait utile de réexporter le contenu importé pour bénéficier des corrections, y compris en le paginant
   - il serait intéressant de définir des shapes SHACL pour valider le graphe DCAT

journal: |
 18/5/2023:
  - ajout classes Standard et LicenseDocument avec leur registre
  - ajout gestion des Location avec URI INSEE
  - transfert des registres associés aux classes dans le fichier registre.yaml
  - amélioration du choix de traitement en fonction des arguments du script
 17/5/2023:
  - réécriture de Statement::rectifStatements()
  - réécriture de Dataset::concat()
 16/5/2023:
  - définition de la classe PropVal
  - définition des mathodes cleanYml() et yamlToPropVal()
  - ajout de qqs prop. / disparition des json-ld
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
*/}
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/statem.inc.php';
require_once __DIR__.'/registre.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

ini_set('memory_limit', '1G');

{/* Classe dont chaque objet correspond à une valeur RDF d'une propriété RDF
** En JSON-LD une PropVal est structurée sous la forme [{key} => {val}]
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
** et PROP_RANGE indiquant le range de certaines propriétés afin de permettre le déréférencement
*/}
class PropVal {
  // indique par propriété sa classe d'arrivée (range), nécessaire pour le déréférencement
  const PROP_RANGE = [
    'publisher' => 'Organization',
    'creator' => 'Organization',
    'rightsHolder' => 'Organization',
    'spatial' => 'Location',
    'temporal' => 'PeriodOfTime',
    'isPrimaryTopicOf' => 'CatalogRecord',
    'inCatalog' => 'Catalog',
    'contactPoint' => 'Kind',
    'conformsTo' => 'Standard',
    'accessRights' => 'RightsStatement',
    'license' => 'LicenseDocument',
    'provenance' => 'ProvenanceStatement',
    'format' => 'MediaTypeOrExtent',
    'mediaType' => 'MediaType',
    'accrualPeriodicity' => 'Frequency',
    'accessService' => 'DataService',
    'distribution' => 'Distribution',
  ];

  public readonly array $keys; // liste des clés de la représentation JSON-LD définissant le type de PropVal
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
  
  function asJsonLd(): array { // regénère une structure JSON-LD 
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
          if (!($class = (self::PROP_RANGE[$pKey] ?? null)))
            return "<$id>";
          try {
            $simple = $class::get($id)->simplify();
          } catch (Exception $e) {
            fwrite(STDERR, "Alerte, ressource $id non trouvée dans $class\n");
            return "<$id>";
          }
          return array_merge(['@id'=> $id], $simple);
        }
        // si le pointeur pointe sur un blank node alors déréférencement du pointeur
        if (!($class = (self::PROP_RANGE[$pKey] ?? null)))
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

{/* Classe abstraite portant les méthodes communes à toutes les classes RDF
** ainsi que les constantes CLASS_URI_TO_PHP_NAME définissant le mapping URI -> nom Php
*/}
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
    'http://purl.org/dc/terms/Standard' => 'Standard',
    'http://purl.org/dc/terms/LicenseDocument' => 'LicenseDocument',
    'http://purl.org/dc/terms/RightsStatement' => 'RightsStatement',
    'http://purl.org/dc/terms/ProvenanceStatement' => 'ProvenanceStatement',
    'http://purl.org/dc/terms/MediaTypeOrExtent' => 'MediaTypeOrExtent',
    'http://purl.org/dc/terms/MediaType' => 'MediaType',
    'http://purl.org/dc/terms/PeriodOfTime' => 'PeriodOfTime',
    'http://purl.org/dc/terms/Frequency' => 'Frequency',
    'http://xmlns.com/foaf/0.1/Organization' => 'Organization',
    'http://www.w3.org/2006/vcard/ns#Kind' => 'Kind',
  ];
  
  protected string $id; // le champ '@id' de la repr. JSON-LD, cad l'URI de la ressource ou l'id blank node
  protected array $types; // le champ '@type' de la repr. JSON-LD, cad la liste des URI des classes RDF de la ressource
  protected array $props=[]; // dict. des propriétés de la ressource de la forme [{propUri} => [PropVal]]
  
  static function add(array $resource): void { // ajout d'une ressource à la classe
    if (!isset((get_called_class())::$all[$resource['@id']])) {
      (get_called_class())::$all[$resource['@id']] = new (get_called_class())($resource);
    }
    else {
      (get_called_class())::$all[$resource['@id']]->concat($resource);
    }
    (get_called_class())::$all[$resource['@id']]->rectification();
  }

  static function get(string $id) { // retourne la ressource de la classe get_called_class() ayant cet $id 
    $class = get_called_class();
    if (isset($class::$all[$id]))
      return $class::$all[$id];
    /* code utilisé pour lire le contenu des registres associé éventuellement à chaque classe (périmé)
    elseif (defined($class.'::REGISTRE') && ($resource = $class::REGISTRE[$id] ?? null)) {
      $jsonld = [
        '@id'=> $id, 
        '@type'=> ["http://purl.org/dc/terms/$class"],
        'http://www.w3.org/2000/01/rdf-schema#label' => [],
      ];
      foreach ($resource as $lang => $value) {
        if ($lang)
          $jsonld['http://www.w3.org/2000/01/rdf-schema#label'][] = ['@language'=> $lang, '@value'=> $value];
        else
          $jsonld['http://www.w3.org/2000/01/rdf-schema#label'][] = ['@value'=> $value];
      }
      return new $class($jsonld);
    }*/
    else
      throw new Exception("DEREF_ERROR on $id");
  }
  
  static function show(): void { // affiche les ressources de la classe hors blank nodes 
    //echo "Appel de ",get_called_class(),"::show()\n";
    //var_dump((get_called_class())::$all); die();
    foreach ((get_called_class())::$all as $id => $resource) {
      if (substr($id, 0, 2) <> '_:') {
        echo Yaml::dump([$id => $resource->simplify()], 7, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
      }
    }
  }
  
  static function showIncludingBlankNodes(): void { // affiche tous les ressources de la classe y compris les blank nodes 
    foreach ((get_called_class())::$all as $id => $elt) {
      echo Yaml::dump([$id => $elt->simplify()], 7, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
  }

  function __construct(array $resource) { // crée un objet à partir de la description JSON-LD 
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
    foreach ($this->props as $pUri => &$pvals) {
    
      // Dans la propriété language l'URI est souvent dupliqué avec une chaine encodée en Yaml
      // Dans certains cas seule cette chaine est présente et l'URI est absent
      if ($pUri == 'http://purl.org/dc/terms/language') {
        //echo 'language = '; print_r($pvals);
        if ((count($pvals)==2) && ($pvals[0]->keys == ['@id']) && ($pvals[1]->keys == ['@value'])) {
          $pvals = [$pvals[0]]; // si URI et chaine alors je ne conserve que l'URI
          //echo 'language rectifié = '; print_r($pvals);
        }
        elseif ((count($pvals)==2) && ($pvals[0]->keys == ['@value']) && ($pvals[1]->keys == ['@id'])) {
          $pvals = [$pvals[1]]; // si URI et chaine alors je ne conserve que l'URI
          //echo 'language rectifié = '; print_r($pvals);
        }
        elseif ((count($pvals)==1) && ($pvals[0]->keys == ['@value'])) { // si chaine encodée en Yaml avec URI alors URI
          if ($pvals[0]->value == "{'uri': 'http://publications.europa.eu/resource/authority/language/FRA'}") {
            $pvals = [new PropVal(['@id'=> 'http://publications.europa.eu/resource/authority/language/FRA'])];
            //echo 'language rectifié = '; print_r($pvals);
          }
        }
        continue;
      }
      
      { // les chaines de caractères comme celles du titre sont dupliquées avec un élément avec langue et l'autre sans
        if ((count($pvals)==2) && ($pvals[0]->value == $pvals[1]->value)) {
          if ($pvals[0]->language && !$pvals[1]->language) {
            //echo "pUri=$pUri\n";
            //print_r($pvals); print_r($this);
            $pvals = [$pvals[0]];
            //echo "rectification -> "; print_r($this->props[$pUri]);
            continue;
          }
          elseif (!$pvals[0]->language && $pvals[1]->language) {
            //echo "pUri=$pUri\n";
            //print_r($pvals);
            $pvals = [$pvals[1]];
            //echo "rectification -> "; print_r($this->props[$pUri]);
            continue;
          }
        }
      }
      
      { // certaines dates sont dupliquées avec un élément dateTime et l'autre date
        if ((count($pvals)==2) && $pvals[0]->value && $pvals[1]->value) {
          if (($pvals[0]->type == 'http://www.w3.org/2001/XMLSchema#date')
           && ($pvals[1]->type == 'http://www.w3.org/2001/XMLSchema#dateTime')) {
            //echo "pUri=$pUri\n";
            //print_r($pvals); print_r($this);
            $pvals = [$pvals[0]];
            //echo "rectification -> "; print_r($this->props[$pUri]);
            continue;
          }
          elseif (($pvals[0]->type == 'http://www.w3.org/2001/XMLSchema#dateTime')
           && ($pvals[1]->type == 'http://www.w3.org/2001/XMLSchema#date')) {
            //echo "pUri=$pUri\n";
            //print_r($pvals);
            $pvals = [$pvals[1]];
            //echo "rectification -> "; print_r($this->props[$pUri]);
            continue;
          }
        }
      }
      
      { // certaines propriétés contiennent des chaines encodées en Yaml
        $rectifiedPvals = []; // [ PropVal ]
        foreach ($pvals as $pval) {
          if (($pval->keys == ['@value']) && ((substr($pval->value, 0, 1) == '{') || (substr($pval->value, 0, 2) == '[{'))) {
            if ($yaml = self::cleanYaml($pval->value)) {
              $rectifiedPvals = array_merge($rectifiedPvals, $yaml);
            }
          }
          else {
            $rectifiedPvals[] = $pval;
          }
        }
        if ($rectifiedPvals)
          $pvals = $rectifiedPvals;
        else
          unset($this->props[$pUri]);
      }
    }
  }
  
  // construit un PropVal à partir d'une structure Yaml en excluant les listes
  function yamlToPropVal(array $yaml): PropVal {
    // Le Yaml est un label avec uniquement la langue française de fournie
    if ((array_keys($yaml) == ['label']) && is_array($yaml['label'])
      && (array_keys($yaml['label']) == ['fr','en']) && $yaml['label']['fr'] && !$yaml['label']['en'])
        return new PropVal(['@language'=> 'fr', '@value'=> $yaml['label']['fr']]);
    
    // Le Yaml est un label avec uniquement la langue française de fournie + un type vide
    if ((array_keys($yaml) == ['label','type']) && !$yaml['type'] && is_array($yaml['label'])
      && (array_keys($yaml['label']) == ['fr','en']) && $yaml['label']['fr'] && !$yaml['label']['en'])
        return new PropVal(['@language'=> 'fr', '@value'=> $yaml['label']['fr']]);
    
    // Le Yaml est un label sans le champ label avec uniquement la langue française de fournie
    if ((array_keys($yaml) == ['fr','en']) && $yaml['fr'] && !$yaml['en'] && is_string($yaml['fr'])) {
        //echo "Dans yamlToPropVal2: "; print_r($yaml);
        return new PropVal(['@language'=> 'fr', '@value'=> $yaml['fr']]);
    }
    
    // Le Yaml est un label sans le champ label avec uniquement la langue française de fournie et $yaml['fr] est un array
    if ((array_keys($yaml) == ['fr','en']) && $yaml['fr'] && !$yaml['en']
      && is_array($yaml['fr']) && array_is_list($yaml['fr']) && (count($yaml['fr']) == 1)) {
        //echo "Dans yamlToPropVal2: "; print_r($yaml);
        return new PropVal(['@language'=> 'fr', '@value'=> $yaml['fr'][0]]);
    }
    
    // Le Yaml définit un URI
    // https://preprod.data.developpement-durable.gouv.fr/dataset/606123c6-d537-485d-ba99-182b0b54d971:
    //  license: '[{''label'': {''fr'': '''', ''en'': ''''}, ''type'': [], ''uri'': ''https://spdx.org/licenses/etalab-2.0''}]'
    if (isset($yaml['uri']) && $yaml['uri'])
      return new PropVal(['@id'=> $yaml['uri']]);
    
    echo "Dans yamlToPropVal: "; print_r($yaml);
    throw new Exception("Cas non traité dans yamlToPropVal()");
  }
  
  // nettoie une valeur codée en Yaml, renvoie une [PropVal] ou []
  // Certaines chaines sont mal encodées en yaml
  function cleanYaml(string $value): array {
    // certaines propriétés contiennent des chaines encodées en Yaml et sans information
    //        '{''fr'': [], ''en'': []}' ou '{''fr'': '''', ''en'': ''''}'
    if (in_array($value, ["{'fr': [], 'en': []}", "{'fr': '', 'en': ''}"])) {
      return [];
    }
    try {
      $yaml = Yaml::parse($value);
      //echo "value=$value\n";
    } catch (ParseException $e) {
      //fwrite(STDERR, "Erreur de Yaml::parse() dans RdfClass::rectification() sur $value\n");
      $value2 = str_replace("\\'", "''", $value);
      //fwrite(STDERR, "value=$value\n\n");
      try {
        $yaml = Yaml::parse($value2);
        //echo "value=$value\n";
      } catch (ParseException $e) {
        fwrite(STDERR, "Erreur2 de Yaml::parse() dans RdfClass::rectification() sur $value\n");
        return [new PropVal(['@value'=> "Erreur de yaml::parse() sur $value"])];
      }
    }
      
    if (array_is_list($yaml)) {
      $list = [];
      foreach ($yaml as $elt) {
        $list[] = self::yamlToPropVal($elt);
      }
      return $list;
    }
    else {
      return [self::yamlToPropVal($yaml)];
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
      foreach ($pvals as $pval) {
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
    'http://purl.org/dc/terms/creator' => 'creator',
    'http://www.w3.org/ns/dcat#contactPoint' => 'contactPoint',
    'http://purl.org/dc/terms/identifier' => 'identifier',
    'http://www.w3.org/ns/dcat#theme' => 'theme',
    'http://www.w3.org/ns/dcat#keyword' => 'keyword',
    'http://purl.org/dc/terms/language' => 'language',
    'http://purl.org/dc/terms/spatial' => 'spatial',
    'http://purl.org/dc/terms/temporal' => 'temporal',
    'http://purl.org/dc/terms/accessRights' => 'accessRights',
    'http://purl.org/dc/terms/rights_holder' => 'rightsHolder', // ERREUR
    'http://xmlns.com/foaf/0.1/homepage' => 'homepage',
    'http://www.w3.org/ns/dcat#landingPage' => 'landingPage',
    'http://xmlns.com/foaf/0.1/page' => 'page',
    'http://purl.org/dc/terms/MediaType' => 'MediaType',
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
    
  function concat(array $resource): void { // concatene 2 valeurs pour un même URI 
    foreach (['http://www.w3.org/ns/dcat#catalog',
              'http://www.w3.org/ns/dcat#record',
              'http://www.w3.org/ns/dcat#dataset',
              'http://www.w3.org/ns/dcat#service'] as $pUri) {
      foreach ($resource[$pUri] ?? [] as $pval) {
        $this->props[$pUri][] = new PropVal($pval);
      }
    }
  }

  static function rectifStatements(): void { // rectifie les propriétés accessRights et provenance
    foreach (self::$all as $id => $dataset) {
      foreach ($dataset->props as $pUri => &$pvals) {
        switch ($pUri) {
          case 'http://purl.org/dc/terms/accessRights': {
            $pvals = Statement::rectifStatements($pvals, 'RightsStatement');
            break;
          }
          case 'http://purl.org/dc/terms/provenance': {
            $pvals = Statement::rectifStatements($pvals, 'ProvenanceStatement');
            break;
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
  static array $all=[];
};

class DataService extends Dataset {
  const PROP_KEY_URI = [
    'http://purl.org/dc/terms/conformsTo' => 'conformsTo',
  ];
  static array $all=[];
};

class Organization extends RdfClass {
  const PROP_KEY_URI = [
    'http://xmlns.com/foaf/0.1/name' => 'name',
    'http://xmlns.com/foaf/0.1/mbox' => 'mbox',
    'http://xmlns.com/foaf/0.1/phone' => 'phone',
    'http://xmlns.com/foaf/0.1/workplaceHomepage' => 'workplaceHomepage',
  ];
  static array $all=[];
  
  function concat(array $elt): void {}
};

class Location extends RdfClass {
  const TYPES_INSEE = [
    'region' => "Région",
    'departement' => "Département",
    'commune' => "Commune",
  ];
  const PROP_KEY_URI = [
    'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
  ];

  static array $all=[];
  
  static function get(string $id) { // retourne la ressource de la classe get_called_class() ayant cet $id 
    $class = get_called_class();
    if (isset($class::$all[$id]))
      return $class::$all[$id];
    elseif (preg_match('!^http://id.insee.fr/geo/(region|departement|commune)/(.*)$!', $id, $matches)) {
      $type_insee = self::TYPES_INSEE[$matches[1]] ?? 'type inconnu';
      return new $class([
        '@id'=> $id, 
        '@type'=> ["http://purl.org/dc/terms/$class"],
        'http://www.w3.org/2000/01/rdf-schema#label' => [['@language'=> 'fr', '@value'=> "$type_insee $matches[2]"]],
      ]);
    }
    else
      throw new Exception("DEREF_ERROR on $id");
  }
  
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
    return parent::simplify();
  }
};

class Standard extends RdfClass {
  const PROP_KEY_URI = [
    'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
  ];

  static array $all=[];
};

class LicenseDocument extends RdfClass {
  const PROP_KEY_URI = [
    'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
  ];

  static array $all=[];
};

class Distribution extends RdfClass {
  const PROP_KEY_URI = [
    'http://purl.org/dc/terms/title' => 'title',
    'http://purl.org/dc/terms/description' => 'description',
    'http://purl.org/dc/terms/format' => 'format',
    'http://www.w3.org/ns/dcat#mediaType' => 'mediaType',
    'http://purl.org/dc/terms/rights' => 'rights',
    'http://purl.org/dc/terms/license' => 'license',
    'http://purl.org/dc/terms/issued' => 'issued',
    'http://purl.org/dc/terms/created' => 'created',
    'http://purl.org/dc/terms/modified' => 'modified',
    'http://www.w3.org/ns/dcat#accessService' => 'accessService',
    'http://www.w3.org/ns/dcat#accessURL' => 'accessURL',
    'http://www.w3.org/ns/dcat#downloadURL' => 'downloadURL',
  ];

  static array $all=[];
};

class MediaTypeOrExtent extends RdfClass {
  const PROP_KEY_URI = [
    'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
  ];

  static array $all=[];
};

class MediaType extends RdfClass {
  const PROP_KEY_URI = [];

  static array $all=[];
};

class PeriodOfTime extends RdfClass {
  const PROP_KEY_URI = [
    'http://www.w3.org/ns/dcat#startDate' => 'startDate',
    'http://www.w3.org/ns/dcat#endDate' => 'endDate',
  ];

  static array $all=[];
};

class Frequency extends RdfClass {
  const PROP_KEY_URI = [
    'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
  ];

  static array $all=[];
};

class Kind extends RdfClass {
  const PROP_KEY_URI = [
    'http://www.w3.org/2006/vcard/ns#fn' => 'fn',
    'http://www.w3.org/2006/vcard/ns#hasEmail' => 'hasEmail',
    'http://www.w3.org/2006/vcard/ns#hasURL' => 'hasURL',
  ];
  
  static array $all=[];
};


$urlPrefix = 'https://preprod.data.developpement-durable.gouv.fr/dcat/catalog';

if ($argc == 1) {
  echo "usage: php $argv[0] {action} [{firstPage} [{lastPage}]]\n";
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
    //fwrite(STDERR, "Info: nbelts de la page $page = ".count($content)."\n");
    
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
          fwrite(STDERR, "Info: lastPage=$lastPage\n");
        }
      }
      else
        throw new Exception("Types $types non traité");
    }
  }
  Dataset::rectifStatements(); // correction des propriétés accessRights qui nécessite que tous les objets soient chargés 
  return $errors;
}


// Par défaut lecture de toutes les pages
$firstPage = $argv[2] ?? 1; // Par défaut démarrage à la première page
$lastPage = $argv[3] ?? 0;  // Par défaut fin à la dernière page définie dans l'import

switch ($argv[1]) {
  case 'registre': { // effectue uniquement l'import du registre et affiche ce qui a été importé 
    Registre::import();
    foreach (RdfClass::CLASS_URI_TO_PHP_NAME as $classUri => $className)
      $className::show();
    break;
  }
  case 'import': { // effectue uniquement l'import de l'export
    import($urlPrefix, true, $lastPage, $firstPage);
    break;
  }
  case 'errors': {
    $errors = import($urlPrefix, true, $lastPage, $firstPage);
    echo "Pages en erreur:\n";
    foreach ($errors as $page => $error)
      echo "  $page: $error\n";
    break;
  }
  case 'catalogs': {
    Registre::import();
    import($urlPrefix, true, $lastPage, $firstPage);
    //print_r(RdfClass::$pkeys);
    Catalog::show();
    break;
  }
  case 'datasets': { // import du registre et de l'export puis affichage des datasets
    Registre::import();
    import($urlPrefix, true, $lastPage, $firstPage);
    Dataset::show();
    break;
  }
  
  default: {
    foreach (RdfClass::CLASS_URI_TO_PHP_NAME as $classUri => $className) {
      if ($argv[1] == $className) {
        Registre::import();
        import($urlPrefix, true, $lastPage, $firstPage);
        $className::showIncludingBlankNodes();
        die();
      }
    }
    
    die("$argv[1] ne correspond à aucune action\n");
  }
}
