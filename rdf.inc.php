<?php
{/*PhpDoc:
title: rdf.inc.php - classes utilisées par exp.php pour gérer les données RDF - 18/5/2023
doc: |
  La classe PropVal facilite l'utilisation en Php de la représentation JSON-LD en définissant
  une structuration d'une valeur RDF d'une propriété RDF d'une ressource.

  La classe abstraite RdfResource porte une grande partie du code et est la classe mère de toutes les classes Php
  traduisant les classes RDF.
  
  Chaque classe RDF est soit traduite en une classe Php, soit, si elle ne porte pas de traitement spécifique,
  fusionnée dans la classe GenResource.
  Les classes non fusionnées définissent la constante de classe PROP_KEY_URI qui liste les propriétés RDF en définissant
  leur raccourci,
  La classe GenResource définit la méthode prop_key_uri() qui retourne la même liste en fonction du type de l'objet.
  
  A voir:
    - 
journal: |
 28/5/2023:
  - ajout classe RdfGraph pour gérer les ressources par graphe
 27/5/2023:
  - scission de la clase PropVal en RdfLiteral et RdfResRef
 21/5/2023:
  - regroupement dans la classe GenResource de classes simples n'ayant aucun traitement spécifique
  - première version de l'opération frame sur les objets d'une classe
 18/5/2023:
  - création par scission de exp.php
  - rectification des mbox et hasEmail qui doivent être des ressources dont l'URI commence par mailto:
*/}
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

{/* Classe des valeurs RDF d'une propriété RDF
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
** Les URI sont des RdfResRef et les autres des RdfLiteral
*/}
abstract class PropVal {
  static function create(array $pval) {
    if (array_keys($pval) == ['@id'])
      return new RdfResRef($pval);
    else
      return new RdfLiteral($pval);
  }
  
  abstract function isA(): string;
  abstract function keys(): array;
  abstract function asJsonLd(): array;
};

// Classe des littéraux RDF
class RdfLiteral extends PropVal {
  public readonly string $value;
  public readonly ?string $language;
  public readonly ?string $type;
  
  function __construct(array $pval) {
    switch (array_keys($pval)) {
      case ['@value'] : {
        $this->value = $pval['@value']; $this->language = null; $this->type = null; break;
      }
      case ['@type','@value'] : {
        $this->value = $pval['@value']; $this->language = null; $this->type = $pval['@type']; break;
      }
      case ['@language','@value'] : {
        $this->value = $pval['@value']; $this->language = $pval['@language']; $this->type = null; break;
      }
      default: {
        print_r(array_keys($pval));
        throw new Exception("Dans RdfLiteral::__construct() keys='".implode(',',$this->keys)."' inconnu");
      }
    }
  }
  
  function isA(): string { return 'RdfLiteral'; }
  
  function keys(): array {
    return $this->language ? ['@language','@value'] : ($this->type ? ['@type','@value'] : ['@value']);
  }
  
  function asJsonLd(): array { // regénère une structure JSON-LD 
    if ($this->language)
      return ['@language'=> $this->language, '@value'=> $this->value];
    elseif ($this->type)
      return ['@type'=> $this->type, '@value'=> $this->value];
    else
      return ['@value'=> $this->value];
  }
  
  // simplification d'une des valeurs d'une propriété, $pKey est le nom court de la prop.
  function simplifPval(RdfGraph $graph, string $pKey): string|array {
    if ($this->type) {
      if (in_array($this->type, ['http://www.w3.org/2001/XMLSchema#dateTime','http://www.w3.org/2001/XMLSchema#date']))
        return $this->value;
      else
        return $this->value.'['.$this->type.']';
    }
    elseif ($this->language) { // SI $pval contient exactement les 2 champs '@value' et '@language' alors simplif dans cette valeur concaténée avec '@' et la langue
      return $this->value.'@'.$this->language;
    }
    else { // SI $pval ne contient qu'un champ '@value' alors simplif par cette valeur
      return $this->value;
    }
  }
};

// Classe des références vers une ressource
// PROP_RANGE indique le range de certaines propriétés afin de permettre leur déréférencement
class RdfResRef extends PropVal {
  // indique par propriété sa classe d'arrivée (range), nécessaire pour le déréférencement pour la simplification
  const PROP_RANGE = [
    'publisher' => 'GenResource',
    'creator' => 'GenResource',
    'rightsHolder' => 'GenResource',
    'spatial' => 'Location',
    'temporal' => 'GenResource',
    'isPrimaryTopicOf' => 'GenResource',
    'inCatalog' => 'Catalog',
    'contactPoint' => 'GenResource',
    'conformsTo' => 'GenResource',
    'accessRights' => 'GenResource',
    'license' => 'GenResource',
    'provenance' => 'GenResource',
    'format' => 'GenResource',
    'mediaType' => 'GenResource',
    'language' => 'GenResource',
    'accrualPeriodicity' => 'GenResource',
    'accessService' => 'DataService',
    'distribution' => 'GenResource',
  ];
  
  public readonly ?string $id;

  function __construct(array $pval) {
    if (array_keys($pval) == ['@id']) {
      $this->id = $pval['@id'];
    }
    else {
      print_r(array_keys($pval));
      throw new Exception("Dans PropVal::__construct() keys='".implode(',',$this->keys)."' inconnu");
    }
  }
  
  function isA(): string { return 'RdfResRef'; }
  
  function keys(): array { return ['@id']; }
  
  function asJsonLd(): array { // regénère une structure JSON-LD 
    return ['@id'=> $this->id];
  }
  
  // simplification d'une des valeurs d'une propriété, $pKey est le nom court de la prop.
  function simplifPval(RdfGraph $graph, string $pKey): string|array {
    $id = $this->id;
    if (substr($id, 0, 2) <> '_:') {// si PAS blank node alors retourne l'URI + evt. déref.
      if (!($class = (self::PROP_RANGE[$pKey] ?? null)))
        return "<$id>";
      try {
        $simple = $graph->get($class, $id)->simplify($graph);
      } catch (Exception $e) {
        StdErr::write("Alerte, ressource $id non trouvée dans $class");
        return "<$id>";
      }
      return array_merge(['@id'=> $id], $simple);
    }
    // si le pointeur pointe sur un blank node alors déréférencement du pointeur
    if (!($class = (self::PROP_RANGE[$pKey] ?? null)))
      throw new Exception("Erreur $pKey absent de RdfResRef::PROP_RANGE");
    return $graph->get($class, $id)->simplify($graph);
  }
};


{/* Classe abstraite portant les méthodes communes à toutes les ressources RDF
** ainsi que la constantes CLASS_URI_TO_PHP_NAME définissant le mapping URI du type ou liste des URI -> nom de la classe Php
** La propriété $props est le dict. des propriétés de la ressource de la forme [{propUri} => [PropVal|RdfResource]]
** Lorsque la représentation est applatie (flatten) la forme est [{propUri} => [PropVal]]
*/}
abstract class RdfResource {
  // Dict. [{URI de classe RDF ou liste d'URI} => {Nom de classe Php}]
  const CLASS_URI_TO_PHP_NAME = [
    'http://www.w3.org/ns/dcat#Catalog' => 'Catalog',
    'http://www.w3.org/ns/dcat#Dataset' => 'Dataset',
    'http://www.w3.org/ns/dcat#Dataset, http://www.w3.org/ns/dcat#DatasetSeries' => 'Dataset',
    'http://www.w3.org/ns/dcat#DatasetSeries, http://www.w3.org/ns/dcat#Dataset' => 'Dataset',
    'http://www.w3.org/ns/dcat#DataService' => 'DataService',
    'http://www.w3.org/ns/dcat#Distribution' => 'GenResource',
    'http://www.w3.org/ns/dcat#CatalogRecord' => 'GenResource',
    'http://purl.org/dc/terms/Location' => 'Location',
    'http://purl.org/dc/terms/Standard' => 'GenResource',
    'http://purl.org/dc/terms/LicenseDocument' => 'GenResource',
    'http://purl.org/dc/terms/RightsStatement' => 'GenResource',
    'http://purl.org/dc/terms/ProvenanceStatement' => 'GenResource',
    'http://purl.org/dc/terms/MediaTypeOrExtent' => 'GenResource',
    'http://purl.org/dc/terms/MediaType' => 'GenResource',
    'http://purl.org/dc/terms/PeriodOfTime' => 'GenResource',
    'http://purl.org/dc/terms/Frequency' => 'GenResource',
    'http://purl.org/dc/terms/LinguisticSystem' => 'GenResource',
    'http://xmlns.com/foaf/0.1/Organization' => 'GenResource',
    'http://www.w3.org/2006/vcard/ns#Kind' => 'GenResource',
    'http://www.w3.org/ns/hydra/core#PagedCollection' => 'PagedCollection',
  ];
    
  protected string $id; // le champ '@id' de la repr. JSON-LD, cad l'URI de la ressource ou l'id blank node
  protected array $types; // le champ '@type' de la repr. JSON-LD, cad la liste des URI des classes RDF de la ressource
  protected array $props=[]; // dict. des propriétés de la ressource de la forme [{propUri} => [PropVal|RdfResource]]
    
  // retourne PROP_KEY_URI, redéfini sur GenResource pour retourner le PROP_KEY_URI en fonction du type de l'objet
  function prop_key_uri(): array { return (get_called_class())::PROP_KEY_URI; }
  
  // crée un objet à partir de la description JSON-LD
  function __construct(array $resource) {
    foreach ($resource as $pUri => $pvals) {
      switch($pUri) {
        case '@id': { $this->id = $resource['@id']; break; }
        case '@type': { $this->types = $resource['@type']; break; }
        default: {
          foreach ($pvals as $pval) {
            $this->props[$pUri][] = PropVal::create($pval);
          }
        }
      }
    }
  }
  
  function __toString(): string { // génère une chaine pour afficher l'objet 
    return Yaml::dump([$this->asJsonLd()]);
  }
  
  // corrections d'erreurs ressource par ressource et pas celles qui nécessittent un accès à d'autres ressources
  function rectification(RdfGraph $graph): void {
    // remplacer les URI erronés de propriétés
    foreach ([
        'http://purl.org/dc/terms/rights_holder' => 'http://purl.org/dc/terms/rightsHolder',
        'http://www.w3.org/ns/dcat#publisher' => 'http://purl.org/dc/terms/publisher',
      ] as $bad => $valid) {
        if (isset($this->props[$bad])) {
          $this->props[$valid] = $this->props[$bad];
          unset($this->props[$bad]);
        }
    }
      
    foreach ($this->props as $pUri => &$pvals) {
    
      // Dans la propriété language l'URI est souvent dupliqué avec une chaine encodée en Yaml
      // Dans certains cas seule cette chaine est présente et l'URI est absent
      if ($pUri == 'http://purl.org/dc/terms/language') {
        //echo 'language = '; print_r($pvals);
        if ((count($pvals)==2) && ($pvals[0]->keys() == ['@id']) && ($pvals[1]->keys() == ['@value'])) {
          $pvals = [$pvals[0]]; // si URI et chaine alors je ne conserve que l'URI
          //echo 'language rectifié = '; print_r($pvals);
          $graph->increment('rectifStats', "rectification langue");
        }
        elseif ((count($pvals)==2) && ($pvals[0]->keys() == ['@value']) && ($pvals[1]->keys() == ['@id'])) {
          $pvals = [$pvals[1]]; // si URI et chaine alors je ne conserve que l'URI
          //echo 'language rectifié = '; print_r($pvals);
          $graph->increment('rectifStats', "rectification langue");
        }
        elseif ((count($pvals)==1) && ($pvals[0]->keys() == ['@value'])) { // si chaine encodée en Yaml avec URI alors URI
          if ($pvals[0]->value == "{'uri': 'http://publications.europa.eu/resource/authority/language/FRA'}") {
            //echo 'language avant rectif = '; print_r($pvals);
            $pvals = [PropVal::create(['@id'=> 'http://publications.europa.eu/resource/authority/language/FRA'])];
            //echo 'language rectifié = '; print_r($pvals);
            $graph->increment('rectifStats', "rectification langue");
          }
        }
        if ((count($pvals)==1) && ($pvals[0]->keys() == ['@id'])) { // si URI 'fr'
          if ($pvals[0]->id == 'fr') {
            $pvals = [PropVal::create(['@id'=> 'http://publications.europa.eu/resource/authority/language/FRA'])];
            //echo 'language rectifié = '; print_r($pvals);
            $graph->increment('rectifStats', "rectification langue");
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
        if ($pvals[0]->keys() == ['@value']) {
          $pvals = [PropVal::create(['@id'=> 'mailto:'.$pvals[0]->value])];
          $graph->increment('rectifStats', "rectification mbox");
        }
        elseif (($pvals[0]->keys() == ['@id']) && (substr($pvals[0]->id, 7, 0)<>'mailto:')) {
          $pvals = [PropVal::create(['@id'=> 'mailto:'.$pvals[0]->id])];
          $graph->increment('rectifStats', "rectification mbox");
        }
        continue;
      }
      
      { // les chaines de caractères comme celles du titre sont dupliquées avec un élément avec langue et l'autre sans
        if ((count($pvals)==2) && ($pvals[0]->isA()=='RdfLiteral') && ($pvals[1]->isA()=='RdfLiteral')
         && ($pvals[0]->value == $pvals[1]->value)) {
          if ($pvals[0]->language && !$pvals[1]->language) {
            $pvals = [$pvals[0]];
            $graph->increment('rectifStats', "duplication littéral avec et sans langue");
            continue;
          }
          elseif (!$pvals[0]->language && $pvals[1]->language) {
            $pvals = [$pvals[1]];
            $graph->increment('rectifStats', "duplication littéral avec et sans langue");
            continue;
          }
        }
      }
      
      { // certaines dates sont dupliquées avec un élément dateTime et l'autre date
        if ((count($pvals)==2) && ($pvals[0]->isA()=='RdfLiteral') && ($pvals[1]->isA()=='RdfLiteral')) {
          if (($pvals[0]->type == 'http://www.w3.org/2001/XMLSchema#date')
           && ($pvals[1]->type == 'http://www.w3.org/2001/XMLSchema#dateTime')) {
            //echo "pUri=$pUri\n";
            //print_r($pvals); print_r($this);
            $pvals = [$pvals[0]];
            //echo "rectification -> "; print_r($this->props[$pUri]);
            $graph->increment('rectifStats', "dates dupliquées avec un élément dateTime et l'autre date");
            continue;
          }
          elseif (($pvals[0]->type == 'http://www.w3.org/2001/XMLSchema#dateTime')
           && ($pvals[1]->type == 'http://www.w3.org/2001/XMLSchema#date')) {
            //echo "pUri=$pUri\n";
            //print_r($pvals);
            $pvals = [$pvals[1]];
            //echo "rectification -> "; print_r($this->props[$pUri]);
            $graph->increment('rectifStats', "dates dupliquées avec un élément dateTime et l'autre date");
            continue;
          }
        }
      }
      
      { // certaines propriétés contiennent des chaines encodées en Yaml
        $rectifiedPvals = []; // [ PropVal ]
        foreach ($pvals as $pval) {
          if (($pval->keys() == ['@value']) && ((substr($pval->value, 0, 1) == '{') || (substr($pval->value, 0, 2) == '[{'))) {
            if ($yaml = self::cleanYaml($pval->value)) {
              $rectifiedPvals = array_merge($rectifiedPvals, $yaml);
            }
            $graph->increment('rectifStats', "propriété contenant une chaine encodée en Yaml");
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
  
  static function get(string $id): ?self { return null; } // interprétation d'un URI spécifique à la classe
  
  // construit un PropVal à partir d'une structure Yaml en excluant les listes
  function yamlToPropVal(array $yaml): PropVal {
    // Le Yaml est un label avec uniquement la langue française de fournie
    if ((array_keys($yaml) == ['label']) && is_array($yaml['label'])
      && (array_keys($yaml['label']) == ['fr','en']) && $yaml['label']['fr'] && !$yaml['label']['en'])
        return new RdfLiteral(['@language'=> 'fr', '@value'=> $yaml['label']['fr']]);
    
    // Le Yaml est un label avec uniquement la langue française de fournie + un type vide
    if ((array_keys($yaml) == ['label','type']) && !$yaml['type'] && is_array($yaml['label'])
      && (array_keys($yaml['label']) == ['fr','en']) && $yaml['label']['fr'] && !$yaml['label']['en'])
        return new RdfLiteral(['@language'=> 'fr', '@value'=> $yaml['label']['fr']]);
    
    // Le Yaml est un label sans le champ label avec uniquement la langue française de fournie
    if ((array_keys($yaml) == ['fr','en']) && $yaml['fr'] && !$yaml['en'] && is_string($yaml['fr'])) {
        //echo "Dans yamlToPropVal2: "; print_r($yaml);
        return new RdfLiteral(['@language'=> 'fr', '@value'=> $yaml['fr']]);
    }
    
    // Le Yaml est un label sans le champ label avec uniquement la langue française de fournie et $yaml['fr] est un array
    if ((array_keys($yaml) == ['fr','en']) && $yaml['fr'] && !$yaml['en']
      && is_array($yaml['fr']) && array_is_list($yaml['fr']) && (count($yaml['fr']) == 1)) {
        //echo "Dans yamlToPropVal2: "; print_r($yaml);
        return new RdfLiteral(['@language'=> 'fr', '@value'=> $yaml['fr'][0]]);
    }
    
    // Le Yaml définit un URI
    // https://preprod.data.developpement-durable.gouv.fr/dataset/606123c6-d537-485d-ba99-182b0b54d971:
    //  license: '[{''label'': {''fr'': '''', ''en'': ''''}, ''type'': [], ''uri'': ''https://spdx.org/licenses/etalab-2.0''}]'
    if (isset($yaml['uri']) && $yaml['uri'])
      return new RdfResRef(['@id'=> $yaml['uri']]);
    
    echo "Dans yamlToPropVal: "; print_r($yaml);
    throw new Exception("Cas non traité dans yamlToPropVal()");
  }
  
  // nettoie une valeur codée en Yaml, renvoie une [PropVal] ou []
  // Certaines chaines sont mal encodées en Yaml
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
      //fwrite(STDERR, "Erreur de Yaml::parse() dans RdfResource::rectification() sur $value\n");
      $value2 = str_replace("\\'", "''", $value);
      //fwrite(STDERR, "value=$value\n\n");
      try {
        $yaml = Yaml::parse($value2);
        //echo "value=$value\n";
      } catch (ParseException $e) {
        StdErr::write("Erreur2 de Yaml::parse() dans RdfResource::rectification() sur $value\n");
        return [PropVal::create(['@value'=> "Erreur de yaml::parse() sur $value"])];
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
  
  function asJsonLd(): array { // retourne la ressource comme JSON-LD 
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
    
  // simplification des valeurs des propriétés 
  function simplify(RdfGraph $graph): string|array {
    $simple = [];
    $jsonld = $this->props;
    foreach ($this->prop_key_uri() as $uri => $key) {
      if (isset($this->props[$uri])) {
        $simple[$key] = $graph->simplifPvals($this->props[$uri], $key);
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

  // applique frame sur les objets de la classe
  /*static function frameAll(array $propUris): void {
    foreach ((get_called_class())::$all as $id => &$resource) {
      $resource->frame($propUris);
    }
  }*/
  
  // modifie l'objet en intégrant pour les propriétés définies (par la liste [{propUri}]),
  // les références à une ressource par la ressource elle-même
  function frame(array $propUris): void {
    foreach ($this->props as $pUri => &$pvals) {
      if (!in_array($pUri, $propUris)) continue;
      $propShortName = $this->prop_key_uri()[$pUri];
      $rangeClass = RdfResRef::PROP_RANGE[$propShortName];
      foreach ($pvals as $i => $pval) {
        if ($pval->keys == ['@id']) {
          $pvals[$i] = $rangeClass::get($pval->id);
        }
      }
    }
  }
};

// Classe générique regroupant les ressources RDF n'ayant pas de traitement spécifique 
class GenResource extends RdfResource {
  const PROP_KEY_URI_PER_TYPE = [
    'http://www.w3.org/ns/dcat#CatalogRecord' => [
      'http://purl.org/dc/terms/identifier' => 'identifier',
      'http://purl.org/dc/terms/language' => 'language',
      'http://purl.org/dc/terms/modified' => 'modified',
      'http://www.w3.org/ns/dcat#contactPoint' => 'contactPoint',
      'http://www.w3.org/ns/dcat#inCatalog' => 'inCatalog',
    ],
    'http://xmlns.com/foaf/0.1/Organization' => [
      'http://xmlns.com/foaf/0.1/name' => 'name',
      'http://xmlns.com/foaf/0.1/mbox' => 'mbox',
      'http://xmlns.com/foaf/0.1/phone' => 'phone',
      'http://xmlns.com/foaf/0.1/workplaceHomepage' => 'workplaceHomepage',
    ],
    'http://purl.org/dc/terms/Standard' => [
      'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
    ],
    'http://purl.org/dc/terms/LicenseDocument' => [
      'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
    ],
    'http://purl.org/dc/terms/RightsStatement' => [
      'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
    ],
    'http://purl.org/dc/terms/ProvenanceStatement' => [
      'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
    ],
    'http://purl.org/dc/terms/MediaTypeOrExtent' => [
      'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
    ],
    'http://purl.org/dc/terms/MediaType' => [
      'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
    ],
    'http://purl.org/dc/terms/Frequency' => [
      'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
    ],
    'http://purl.org/dc/terms/LinguisticSystem' => [
      'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
    ],
    'http://purl.org/dc/terms/PeriodOfTime' => [
      'http://www.w3.org/ns/dcat#startDate' => 'startDate',
      'http://www.w3.org/ns/dcat#endDate' => 'endDate',
    ],
    'http://www.w3.org/2006/vcard/ns#Kind' => [
      'http://www.w3.org/2006/vcard/ns#fn' => 'fn',
      'http://www.w3.org/2006/vcard/ns#hasEmail' => 'hasEmail',
      'http://www.w3.org/2006/vcard/ns#hasURL' => 'hasURL',
    ],
    'http://www.w3.org/ns/dcat#Distribution' => [
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
    ],
  ]; // dict. [{typeUri}=> [{propUri} => {$propName}]]
  
  // retourne le dictionnaire PROP_KEY_URI pour l'objet
  function prop_key_uri(): array {
    $type = $this->types[0];
    if ($prop_key_uri = self::PROP_KEY_URI_PER_TYPE[$type] ?? null) {
      return $prop_key_uri;
    }
    else {
      print_r($this);
      throw new Exception("Erreur, PROP_KEY_URI non défini pour le type $type et l'objet ci-dessus");
    }
  }
  
  // retourne la liste [PropVal] correspondant pour l'objet à la propriété définie par son nom court
  function __get(string $name): ?array {
    //echo "__get($name) sur "; print($this);
    $uri = null;
    foreach ($this->prop_key_uri() as $pUri => $pName) {
      if ($pName == $name) {
        $uri = $pUri;
        break;
      }
    }
    if (!$uri) {
      echo "__get($name) retourne null\n";
      throw new Exception("$name non défini dans GenResource::__get()");
      //return null;
    }
    //echo "uri=$uri\n";
    return $this->props[$uri] ?? [];
  }
  
  // je fais l'hypothèse que les objets autres que Catalog quand ils sont définis plusieurs fois ont des defs identiques
  function concat(array $resource): void {}
};

class Dataset extends RdfResource {
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
    'http://purl.org/dc/terms/rightsHolder' => 'rightsHolder',
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
    
  function concat(array $resource): void { // concatene 2 valeurs pour un même URI 
    foreach (['http://www.w3.org/ns/dcat#catalog',
              'http://www.w3.org/ns/dcat#record',
              'http://www.w3.org/ns/dcat#dataset',
              'http://www.w3.org/ns/dcat#service'] as $pUri) {
      foreach ($resource[$pUri] ?? [] as $pval) {
        $this->props[$pUri][] = PropVal::create($pval);
      }
    }
  }

  static function rectifAllStatements(array $datasets, RdfGraph $graph): void { // rectifie les propriétés accessRights et provenance
    foreach ($datasets as $id => $dataset) {
      foreach ($dataset->props as $pUri => &$pvals) {
        switch ($pUri) {
          case 'http://purl.org/dc/terms/accessRights': {
            $pvals = self::rectifOneStatement($pvals, 'RightsStatement', $graph);
            break;
          }
          case 'http://purl.org/dc/terms/provenance': {
            $pvals = self::rectifOneStatement($pvals, 'ProvenanceStatement', $graph);
            break;
          }
        }
      }
    }
  }
  
  // corrige si nécessaire une liste de valeurs correspondant à une propriété accessRights ou provenance
  static function rectifOneStatement(array $pvals, string $statementClass, RdfGraph $graph): array {
    $arrayOfMLStrings = []; // [{md5} => ['mlStr'=> MLString, 'bn'=>{bn}]] - liste de chaines correspondant au $pvals
    
    foreach ($pvals as $pval) {
      switch ($pval->keys()) {
        case ['@language','@value'] : {
          $graph->increment('rectifStats', "propriété contenant un Littéral alors qu'elle exige une Resource");
          if ($pval->language == 'fr') {
            $md5 = md5($pval->value);
            if (!isset($arrayOfMLStrings[$md5]))
              $arrayOfMLStrings[$md5] = ['mlStr'=> new MLString(['fr'=> $pval->value])];
          }
          else {
            throw new Exception("Langue ".$pval->language." non traitée");
          }
          break;
        }
        case ['@id'] : {
          $statement = $graph->get('GenResource', $pval->id);
          $mlStr = MLString::fromStatementLabel($statement->label);
          $arrayOfMLStrings[$mlStr->md5()] = ['mlStr'=> $mlStr, 'bn'=>$pval->id];
          break;
        }
        default: {
          throw new Exception("Keys ".implode(',', $pval->keys)." non traité");
        }
      }
    }
    
    $pvals = [];
    foreach ($arrayOfMLStrings as $md5 => $mlStrAndBn) {
      if (isset($mlStrAndBn['bn']))
        $pvals[] = PropVal::create(['@id'=> $mlStrAndBn['bn']]);
      else {
        $id = '_:md5-'.$md5; // définition d'un id de BN à partir du MD5
        $resource = [
          '@id'=> $id,
          '@type'=> ["http://purl.org/dc/terms/$statementClass"],
          'http://www.w3.org/2000/01/rdf-schema#label'=> $mlStrAndBn['mlStr']->toStatementLabel(),
        ];
        $graph->addResource($resource, 'GenResource');
        $pvals[] = PropVal::create(['@id'=> $id]);
      }
    }
    return $pvals;
  }
};

class Catalog extends Dataset {
};

class DataService extends Dataset {
  const PROP_KEY_URI = [
    'http://purl.org/dc/terms/conformsTo' => 'conformsTo',
  ];
};

class Location extends RdfResource {
  const TYPES_INSEE = [
    'region' => "Région",
    'departement' => "Département",
    'commune' => "Commune",
  ];
  const PROP_KEY_URI = [
    'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
  ];
  
  static function get(string $id): ?Location { // retourne la ressource de la classe ayant cet $id 
    $class = get_called_class();
    if (preg_match('!^http://id.insee.fr/geo/(region|departement|commune)/(.*)$!', $id, $matches)) {
      $type_insee = self::TYPES_INSEE[$matches[1]] ?? 'type inconnu';
      return new Location([
        '@id'=> $id, 
        '@type'=> ["http://purl.org/dc/terms/$class"],
        'http://www.w3.org/2000/01/rdf-schema#label' => [['@language'=> 'fr', '@value'=> "$type_insee $matches[2]"]],
      ]);
    }
    else
      return null;
  }
  
  function simplify(RdfGraph $graph): string|array {
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
    return parent::simplify($graph);
  }
};

class PagedCollection extends RdfResource {
  const PROP_KEY_URI = [
    'http://www.w3.org/ns/hydra/core#firstPage' => 'firstPage',
    'http://www.w3.org/ns/hydra/core#lastPage' => 'lastPage',
    'http://www.w3.org/ns/hydra/core#nextPage' => 'nextPage',
    'http://www.w3.org/ns/hydra/core#previousPage' => 'previousPage',
    'http://www.w3.org/ns/hydra/core#itemsPerPage' => 'itemsPerPage',
    'http://www.w3.org/ns/hydra/core#totalItems' => 'totalItems',
  ];

  function lastPage(): int {
    $lastPage = $this->props['http://www.w3.org/ns/hydra/core#lastPage'][0]->value;
    if (!preg_match('!\?page=(\d+)$!', $lastPage, $m))
      throw new Exception("erreur de preg_match sur $lastPage");
    return $m[1];
  }
};


// extrait le code HTTP de retour de l'en-tête HTTP
function httpResponseCode(array $header) { return substr($header[0], 9, 3); }

class RdfGraph { // graphe RDF
  protected string $name; // nom du graphe
  protected array $stats = ["nbre de ressources lues"=> 0]; // statistiques
  protected array $rectifStats = []; // [{type} => {nbre}] - nbre de rectifications effectuées par type
  protected array $resources; // [{className} => [{resid}=> {Resource}]]
  
  function __construct(string $name) { $this->name = $name; }
  
  function increment(string $var, string $label): void { // incrémente une des sous-variables de la variable $var
    $this->$var[$label] = 1 + ($this->$var[$label] ?? 0);
  }
  
  function stats(): array { return $this->stats; }
  function rectifStats(): array { return $this->rectifStats; }
  
  function addResource(array $resource, string $className): RdfResource { // ajoute la ressource à la classe $className
    if (!isset($this->resources[$className][$resource['@id']])) {
      $this->resources[$className][$resource['@id']] = new $className($resource);
    }
    else {
      $this->resources[$className][$resource['@id']]->concat($resource);
    }
    $this->resources[$className][$resource['@id']]->rectification($this);
    $this->increment('stats', "nbre de ressources pour $className");
    return $this->resources[$className][$resource['@id']];
  }
  
  function import(string $urlPrefix, bool $skip=false, int $lastPage=0, int $firstPage=1): array {
    {/* importe l'export JSON-LD et construit les objets chacun dans leur classe
      lorque le fichier est absent:
        si $skip est faux alors le site est interrogé
        sinon ($skip vrai) alors la page est sautée et marquée comme erreur
      Si $lastPage est indiquée et différente de 0 alors la lecture s'arrête à cette page,
      sinon elle vaut 0 et le numéro de la dernière page est lu dans une des pages.
      Si $firstPage est indiquée alors la lecture commence à cette page, sinon elle vaut 1.
    */}
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
      //StdErr::write("Info: nbelts de la page $page = ".count($content)."\n");
    
      foreach ($content as $no => $resource) {
        $this->increment('stats', "nbre de ressources lues");
        $types = implode(', ', $resource['@type']); 
        if (!($className = (RdfResource::CLASS_URI_TO_PHP_NAME[$types] ?? null))) {
          throw new Exception("Types $types non traité");
        }
        $resource = $this->addResource($resource, $className);
        if (($className == 'PagedCollection') && ($lastPage == 0)) {
          $lastPage = $resource->lastPage();
          StdErr::write("Info: lastPage=$lastPage\n");
        }
      }
    }
     // rectification des propriétés accessRights et provenance qui nécessitent que tous les objets soient chargés avant la rectification
    Dataset::rectifAllStatements($this->resources['Dataset'], $this);
    return $errors;
  }

  function get(string $className, string $id): RdfResource { // retourne la ressource de la classe $className ayant cet $id 
    if (isset($this->resources[$className][$id]))
      return $this->resources[$className][$id];
    elseif ($res = $className::get($id))
      return $res;
    else {
      echo "RdfGraph::get($className, $id) sur le graphe $this->name\n";
      throw new Exception("DEREF_ERROR on $id");
    }
  }

  // simplification des valeurs de propriété $pvals de la forme [[{key} => {value}]], $pKey est le nom court de la prop.
  function simplifPvals(array $pvals, string $pKey): string|array {
    // $pvals ne contient qu'un seul $pval alors simplif de cette valeur
    if (count($pvals) == 1)
      return $pvals[0]->simplifPval($this, $pKey);
    
    // SI $pvals est une liste de $pval alors simplif de chaque valeur
    $list = [];
    foreach ($pvals as $pval) {
      $list[] = $pval->simplifPval($this, $pKey);
    }
    return $list;
  }
  
  function show(string $className, bool $echo=true): string { // affiche en Yaml les ressources de la classe hors blank nodes 
    $result = '';
    foreach ($this->resources[$className] ?? [] as $id => $resource) {
      if (substr($id, 0, 2) <> '_:') {
        if ($echo)
          echo Yaml::dump([$id => $resource->simplify($this)], 7, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        else
          $result .= Yaml::dump([$id => $resource->simplify($this)], 7, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
      }
    }
    return $result;
  }
  
  function showIncludingBlankNodes(string $className): void { // affiche en Yaml toutes les ressources de la classe y compris les blank nodes 
    foreach ($this->resources[$className] as $id => $elt) {
      echo Yaml::dump([$id => $elt->simplify($this)], 7, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
  }

  function exportAllAsJsonLd(): array { // extraction du contenu en JSON-LD comme array Php
    $jsonld = [];
    foreach (RdfResource::CLASS_URI_TO_PHP_NAME as $className) {
      foreach ($this->resources[$className] ?? [] as $id => $resource)
        $jsonld[] = $resource->asJsonLd();
    }
    return $jsonld;
  }
  
  function exportClassAsJsonLd(string $className): array { // extraction du contenu de la classe en JSON-LD comme array Php
    $jsonld = [];
    foreach ($this->resources[$className] as $id => $resource)
      $jsonld[] = $resource->asJsonLd();
    return $jsonld;
  }
};