<?php
{/*PhpDoc:
title: rdf.inc.php - classes utilisées par exp.php pour gérer les données RDF - 18/5/2023
doc: |
  La classe PropVal facilite l'utilisation en Php de la représentation JSON-LD en définissant
  une structuration d'une valeur RDF d'une propriété RDF d'une ressource.

  La classe abstraite RdfClass porte une grande partie du code et est la classe mère de toutes les classes Php
  traduisant les classes RDF. Pour chacune de ces dernières:
   - la constante de classe PROP_KEY_URI liste les propriétés RDF en définissant leur raccourci,
   - la propriété statique $all contient les objets correspondant aux ressources lues à partir du registre et des fichiers
     JSON-LD.

journal: |
 18/5/2023:
  - création par scission de exp.php
  - rectification des mbox et hasEmail qui doivent être des ressources dont l'URI commence par mailto:
*/}
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

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
    'language' => 'LinguisticSystem',
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
            if (defined('STDERR'))
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
    'http://purl.org/dc/terms/LinguisticSystem' => 'LinguisticSystem',
    'http://xmlns.com/foaf/0.1/Organization' => 'Organization',
    'http://www.w3.org/2006/vcard/ns#Kind' => 'Kind',
  ];
  
  static array $stats = [
    "nbre de ressources lues"=> 0,
  ]; // statistiques
  static array $rectifStats = []; // [{type} => {nbre}] - nbre de rectifications effectuées par type
  
  protected string $id; // le champ '@id' de la repr. JSON-LD, cad l'URI de la ressource ou l'id blank node
  protected array $types; // le champ '@type' de la repr. JSON-LD, cad la liste des URI des classes RDF de la ressource
  protected array $props=[]; // dict. des propriétés de la ressource de la forme [{propUri} => [PropVal]]
  
  static function add(array $resource): void { // ajout d'une ressource à la classe
    $class = get_called_class();
    if (!isset($class::$all[$resource['@id']])) {
      $class::$all[$resource['@id']] = new $class($resource);
    }
    else {
      $class::$all[$resource['@id']]->concat($resource);
    }
    $class::$all[$resource['@id']]->rectification();
    self::increment('stats', "nbre de ressources pour $class");
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
  
  static function show(bool $echo=true): string { // affiche en Yaml les ressources de la classe hors blank nodes 
    //echo "Appel de ",get_called_class(),"::show()\n";
    //var_dump((get_called_class())::$all); die();
    $result = '';
    foreach ((get_called_class())::$all as $id => $resource) {
      if (substr($id, 0, 2) <> '_:') {
        if ($echo)
          echo Yaml::dump([$id => $resource->simplify()], 7, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        else
          $result .= Yaml::dump([$id => $resource->simplify()], 7, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
      }
    }
    return $result;
  }
  
  static function showIncludingBlankNodes(): void { // affiche en Yaml toutes les ressources de la classe y compris les blank nodes 
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
  
  static function increment(string $var, string $label): void { // incrémente une des sous-variables de la variable $var
    self::$$var[$label] = 1 + (self::$$var[$label] ?? 0);
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
          self::increment('rectifStats', "rectification langue");
        }
        elseif ((count($pvals)==2) && ($pvals[0]->keys == ['@value']) && ($pvals[1]->keys == ['@id'])) {
          $pvals = [$pvals[1]]; // si URI et chaine alors je ne conserve que l'URI
          //echo 'language rectifié = '; print_r($pvals);
          self::increment('rectifStats', "rectification langue");
        }
        elseif ((count($pvals)==1) && ($pvals[0]->keys == ['@value'])) { // si chaine encodée en Yaml avec URI alors URI
          if ($pvals[0]->value == "{'uri': 'http://publications.europa.eu/resource/authority/language/FRA'}") {
            $pvals = [new PropVal(['@id'=> 'http://publications.europa.eu/resource/authority/language/FRA'])];
            //echo 'language rectifié = '; print_r($pvals);
            self::increment('rectifStats', "rectification langue");
          }
        }
        continue;
      }
      
      // rectification des mbox et hasEmail qui doivent être des ressources dont l'URI commence par mailto:
      if (in_array($pUri, ['http://xmlns.com/foaf/0.1/mbox','http://www.w3.org/2006/vcard/ns#hasEmail'])) {
        if (count($pvals) <> 1) {
          var_dump($pvals);
          throw new Exception("Erreur, 0 ou plusieurs valeurs pour foaf:mbox ou vcard:hasEmail");
        }
        //print_r($pvals);
        if ($pvals[0]->keys == ['@value']) {
          $pvals = [new PropVal(['@id'=> 'mailto:'.$pvals[0]->value])];
          self::increment('rectifStats', "rectification mbox");
        }
        elseif (($pvals[0]->keys == ['@id']) && (substr($pvals[0]->id, 7, 0)<>'mailto:')) {
          $pvals = [new PropVal(['@id'=> 'mailto:'.$pvals[0]->id])];
          self::increment('rectifStats', "rectification mbox");
        }
        continue;
      }
      
      { // les chaines de caractères comme celles du titre sont dupliquées avec un élément avec langue et l'autre sans
        if ((count($pvals)==2) && ($pvals[0]->value == $pvals[1]->value)) {
          if ($pvals[0]->language && !$pvals[1]->language) {
            $pvals = [$pvals[0]];
            self::increment('rectifStats', "duplication littéral avec et sans langue");
            continue;
          }
          elseif (!$pvals[0]->language && $pvals[1]->language) {
            $pvals = [$pvals[1]];
            self::increment('rectifStats', "duplication littéral avec et sans langue");
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
            self::increment('rectifStats', "dates dupliquées avec un élément dateTime et l'autre date");
            continue;
          }
          elseif (($pvals[0]->type == 'http://www.w3.org/2001/XMLSchema#dateTime')
           && ($pvals[1]->type == 'http://www.w3.org/2001/XMLSchema#date')) {
            //echo "pUri=$pUri\n";
            //print_r($pvals);
            $pvals = [$pvals[1]];
            //echo "rectification -> "; print_r($this->props[$pUri]);
            self::increment('rectifStats', "dates dupliquées avec un élément dateTime et l'autre date");
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
            self::increment('rectifStats', "propriété contenant une chaine encodée en Yaml");
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
        if (defined('STDERR'))
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

  static function exportAsJsonLd(): array { // extraction du contenu en JSON-LD comme array Php
    $jsonld = [];
    foreach (self::CLASS_URI_TO_PHP_NAME as $className) {
      foreach ($className::$all as $resource)
        $jsonld[] = $resource->asJsonLd();
    }
    return $jsonld;
  }
  
  function asJsonLd(): array {
    $jsonld = [
      '@id' => $this->id,
      '@type'=> $this->types,
    ];
    foreach ($this->props as $propUri => $objects) {
      $jsonld[$propUri] = [];
      foreach ($objects as $object) {
        $jsonld[$propUri][] = $object->asJsonLd();
      }
    }
    return $jsonld;
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

class LinguisticSystem extends RdfClass {
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
