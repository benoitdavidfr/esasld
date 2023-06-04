<?php
/*registre.inc.php - charge le registre et fournit les méthodes pour l'utiliser
*/
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/lib.inc.php';
require_once __DIR__.'/rdfexpand.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class ConceptScheme extends RdfExpResource {
  const PROP_KEY_URI = [
    'http://purl.org/dc/terms/title' => 'title',
  ];
  
  static function registre2JsonLd(string $classUri, array $resource): array { // transforme la structure registre en structure JSON-LD 
    {/* Structure Registre 
        description: ressource skos:ConceptScheme
        type: object
        required: [ $id, title ]
        additionalProperties: false
        properties:
          $id:
            description: URI de la ressource
            type: string
            format: uri
          title:
            description: titre du schéma de concepts
            $ref: '#/definitions/multiLingualLabel'
          publisher:
            description: éditeur défini comme URI si possible défini comme ressource de foaf:Organization
            type: string
            format: uri
    */}
    //echo 'structureRegistre = '; print_r($resource);
    $jsonLd = [
      '@id'=> $resource['$id'],
      '@type'=> [$classUri],
      'http://purl.org/dc/terms/title' => [
        [
          '@language'=> 'fr',
          '@value'=> $resource['title']['fr'],
        ],
      ],
    ];
    //echo 'structureJsonLd = '; print_r($jsonLd);
    return $jsonLd;
  }
};

class Concept extends RdfExpResource {
  const PROP_KEY_URI = [
    'http://www.w3.org/2004/02/skos/core#prefLabel' => 'prefLabel',
    'http://www.w3.org/2004/02/skos/core#inScheme' => 'inScheme',
  ];
  
  static function registre2JsonLd(string $classUri, array $resource): array {
    {/* Structure Registre 
        description: ressource skos:Concept
        type: object
        required: [ $id, prefLabel ]
        additionalProperties: false
        properties:
          $id:
            description: URI de la ressource
            type: string
          prefLabel:
            description: étiquette préférentielle multi-lingue
            $ref: '#/definitions/multiLingualLabel'
          inScheme:
            description: schémas de concepts dans lesquels le concept est défini
            type: array
            items: {type: string, format: uri}
    */}
    //echo 'structureRegistre = '; print_r($resource);
    $jsonLd = [
      '@id'=> $resource['$id'],
      '@type'=> [$classUri],
      'http://www.w3.org/2004/02/skos/core#prefLabel' => [
        [
          '@language'=> 'fr',
          '@value'=> $resource['prefLabel']['fr'],
        ],
      ],
    ];
    foreach ($resource['inScheme'] ?? [] as $inScheme)
      $jsonLd['http://www.w3.org/2004/02/skos/core#inScheme'][] = [ '@id'=> $inScheme ];
    //echo 'structureJsonLd = '; print_r($jsonLd);
    return $jsonLd;
  }
};

class Organization extends RdfExpResource { // http://xmlns.com/foaf/0.1/Organization
  const PROP_KEY_URI = [
    'http://xmlns.com/foaf/0.1/name' => 'name',
    'http://xmlns.com/foaf/0.1/mbox' => 'mbox',
    'http://xmlns.com/foaf/0.1/phone' => 'phone',
    'http://xmlns.com/foaf/0.1/homepage' => 'homepage',
    'http://xmlns.com/foaf/0.1/workplaceHomepage' => 'workplaceHomepage',
  ];
  
  static function registre2JsonLd(string $classUri, array $resource): array {
    {/* structure Registre
        description: ressource foaf:Organization
        type: object
        additionalProperties: false
        properties:
          $id:
            description: URI de la ressource
            type: string
          name:
            description: nom de l'organisation
            $ref: '#/definitions/multiLingualLabel'
          homepage:
            description: pade internet d'accueil de l'organisation
            type: string
    */}
    //echo 'structureRegistre = '; print_r($resource);
    $jsonLd = [
      '@id'=> $resource['$id'],
      '@type'=> [$classUri],
      'http://xmlns.com/foaf/0.1/name' => [ [ '@language'=> 'fr', '@value'=> $resource['name']['fr'] ] ],
      'http://xmlns.com/foaf/0.1/homepage' => [ [ '@id'=> $resource['homepage'] ] ],
    ];
    //echo 'structureJsonLd = '; print_r($jsonLd);
    return $jsonLd;
  }
};



class Property { // méthodes sur les propriétés - ANCIENNE DEFINITION 
  static array $names = []; // stockage des noms courts des propriétés comme clé pour tester les collisions
  
  // construit les champs utiles de l'inverse pour addContextForProperty()
  static function inverse(array $prop): array {
    return isset($prop['domain']) ? ['range'=> $prop['domain']] : [];
  }
  
  // ajoute dans le contexte les éléments pour la propriété et si elle existe son inverse, retourne le contexte modifié
  static function addContextForProperty(array $context, string $ciri, ?array $prop): array {
    $parts = explode(':', $ciri);
    $duplicate = isset(self::$names[$parts[1]]);
    if (($prop['range'] ?? ['rdfs:Literal']) == ['rdfs:Literal']) {
      if (!$duplicate)
        $context[$parts[1]] = $ciri;
    }
    elseif (!$duplicate)
      $context[$parts[1]] = ['@id' => $ciri, '@type'=> '@id'];
    else
      $context[$ciri] = ['@id' => $ciri, '@type'=> '@id'];
    self::$names[$parts[1]] = 1;
    if (isset($prop['inverse']))
      return self::addContextForProperty($context, $prop['inverse'], self::inverse($prop));
    else
      return $context;
  }
};

class RdfProperty {
  protected string $ciri; // compact URI 
  protected ?array $array;
  
  function __construct(string $ciri, ?array $array) { $this->ciri = $ciri; $this->array = $array; }
  
  function asArray(): ?array { return $this->array; }
};

{/* Structure Registre 
  class:
    description:
    type: object
    additionalProperties: false
    required: [definition, subClassOf]
    properties:
      label: { description: "libellé s'il ne se déduit pas facilement de son URI (rdfs:label)", type: string}
      labelFr: { description: "libellé en français", type: string}
      definition:
        description: 'définition officielle de la classe (skos:definition)'
        $ref: '#/definitions/definition'
      comment: { description: 'commentaire officiel sur la classe (rdfs:comment)', type: string }
      subClassOf:
        description: liste des classes parentes définies par leur URI compact (rdfs:subClassOf)
        type: array
        items:
          type: string
          pattern: '^[a-z]+:[A-Za-z]+$'
      resources:
        description: liste de ressources bien connues de la classe et utiles
        $ref: '#/definitions/resources'
*/}
class RdfClass { // description d'une classe 
  protected string $ciri; // compact URI 
  protected array $array; // description identique au fichier Yaml 
  
  function __construct(string $ciri, array $array, Registre $registre, RdfExpGraph $graph) {
    $this->ciri = $ciri;
    $this->array = $array;
    foreach ($array['resources'] ?? [] as $resource) {
      $classUri = $registre->expandCiri($ciri);
      if (!($className = RdfExpGraph::CLASS_URI_TO_PHP_NAME[$classUri] ?? null))
        throw new Exception("Erreur, classe $classUri inconnue dans RdfExpGraph::CLASS_URI_TO_PHP_NAME");
      $graph->addResource($className::registre2JsonLd($classUri, $resource), $className);
    }
    unset($this->array['resources']);
  }
  
  function asArray(): array { return $this->array; }
};

{/* Déf. schema yaml
  ontology:
    description: description de l'ontologie
    type: object
    additionalProperties: false
    properties:
      title: { description: "", type: string }
      source:
        description: 
        type: array
        items: {type: string}
      classes:
        description: 
        type: object
        additionalProperties: false
        patternProperties:
          '^[a-z]+:[A-Za-z]+$':
            description: description d'une classe et de ses ressources bien connues
            $ref: '#/definitions/class'
      properties:
        description: 
        type: object
        additionalProperties: false
        patternProperties:
          '^[a-z]+:[a-zA-Z]+$':
            oneOf:
              - description: description de la propriété
                $ref: '#/definitions/property'
              - description: propriété non détaillée dont uniquement l'URI est fournie
                type: 'null'
*/}
class Ontology {
  protected string $title; // titre de l'ontologie
  protected array $source=[]; // liste de références aux documents de référence sur cette ontologie
  protected array $classes=[]; // dictionnaire de classes indexées sur leur URI compact
  protected array $properties=[]; // dictionnaire de propriétés indexées sur leur URI compact
  
  function __construct(array $array, Registre $registre, RdfExpGraph $graph) {
    $this->title = $array['title'];
    $this->source = $array['source'];
    foreach ($array['classes'] ?? [] as $ciri => $class)
      $this->classes[$ciri] = new RdfClass($ciri, $class, $registre, $graph);
    foreach ($array['properties'] ?? [] as $ciri => $property)
      $this->properties[$ciri] = new RdfProperty($ciri, $property, $registre);
  }
  
  function asArray(): array {
    foreach ($this->classes ?? [] as $ciri => $class)
      $classes[$ciri] = $class->asArray();
    foreach ($this->properties ?? [] as $ciri => $property)
      $properties[$ciri] = $property->asArray();
    return [
      'title'=> $this->title, 
      'source'=> $this->source,
      'classes'=> $classes ?? [],
      'properties'=> $properties ?? [],
    ];
  }
};

class Registre { // stockage du registre 
  protected array $namespaces=[]; // [{prefix} => {iri}]
  protected array $ontologies=[]; // [{prefix} => Ontology]
  
  function expandCiri(string $ciri): string {
    $parts = explode(':', $ciri);
    if (!isset($this->namespaces[$parts[0]])) {
      throw new Exception("Erreur, espace de nom $parts[0] non défini");
    }
    return $this->namespaces[$parts[0]].$parts[1];
  }
  
  function import(RdfExpGraph $graph): array { // importe le registre dans le graphe 
    try {
      $registre = Yaml::parseFile(__DIR__.'/registre.yaml');
    } catch (ParseException $exception) {
      throw new Exception('Unable to parse the YAML file: '. $exception->getMessage());
    }
    foreach ($registre['namespaces'] as $prefix => $iri) {
      $this->namespaces[$prefix] = $iri;
    }
    foreach ($registre['ontologies'] as $prefix => $ontology) {
      $this->ontologies[$prefix] = new Ontology($ontology, $this, $graph);
    }
    return [];
  }

  function show(): void {
    foreach ($this->ontologies as $prefix => $ontology)
      $ontologies[$prefix] = $ontology->asArray();
    echo Yaml::dump([
      'namespaces'=> $this->namespaces,
      'ontologies'=> $ontologies,
    ], 9, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
  }
  
  function checkIntegrity(): void {
    
  }
  
  function jsonLdContext(): array { // contexte JSON-LD de allAsJsonLd() comme array Php
    $context = [
      '@language'=> 'fr',
      '@base'=> 'http://base/',
    ];

    foreach (self::$namespaces as $prefix => $iri) {
      $context[$prefix] = $iri;
    }

    $names = []; // stockage des noms courts des classes comme clé pour tester les collisions
    foreach (self::$classes as $ciri => $classe) {
      $parts = explode(':', $ciri);
      $duplicate = isset($names[$parts[1]]);
      if (!$duplicate)
        $context[$parts[1]] = $ciri;
      $names[$parts[1]] = 1;
    }

    foreach (self::$properties as $ciri => $prop) {
      $context = Property::addContextForProperty($context, $ciri, $prop);
    }
    return $context;
    
    /*return [
      'dcat' => 'http://www.w3.org/ns/dcat#',
      'dct' => 'http://purl.org/dc/terms/',
      'foaf' => 'http://xmlns.com/foaf/0.1/homepage',
      'Catalog' => 'dcat:Catalog',
      'title' => 'dct:title',
      'homepage' => [
        '@id' => 'http://xmlns.com/foaf/0.1/homepage',
        '@type' => '@id',
      ],
      'dataset' => 'http://www.w3.org/ns/dcat#dataset',
      '@language' => 'fr',
    ];*/
    /* Exemple:
      {
        "name": "http://schema.org/name",
        ↑ This means that 'name' is shorthand for 'http://schema.org/name'
        "image": {
          "@id": "http://schema.org/image",
          ↑ This means that 'image' is shorthand for 'http://schema.org/image'
          "@type": "@id"
          ↑ This means that a string value associated with 'image'
            should be interpreted as an identifier that is an IRI
        },
      }
      {
        "@context": {
          "name": "http://example.org/name",
          "occupation": "http://example.org/occupation",
          ...
          "@language": "ja"
        },
        "name": "花澄",
        "occupation": "科学者"
      }
    */
  }
  
  function jsonLdFrame(): array {
    return [
      '@context'=> self::jsonLdContext(),
      '@type'=> 'Dataset',
      'isPrimaryTopicOf'=> [
        '@type'=> 'CatalogRecord',
      ],
      'language'=> [
        '@type'=> 'LinguisticSystem',
      ],
      'conformsTo'=> [
        '@type'=> 'Standard',
      ],
      'publisher'=> [
        '@type'=> 'Organization',
      ],
      'spatial'=> [
        '@type'=> 'Location',
      ],
      'contactPoint'=> [
        '@type' => 'Kind',
      ],
      'accessRights'=> [
        '@type'=> 'RightsStatement',
      ],
      'distribution'=> [
        '@type'=> 'Distribution',
      ]
    ];
    /* Exemples
    {
      "@context": {
        "@version": 1.1,
        "@vocab": "http://example.org/"
      },
      "@type": "Library",
      "contains": {
        "@type": "Book",
        "contains": {
          "@type": "Chapter"
        }
      }
    }
    */
  }
};


// Exécution de test en !CLI
if ((php_sapi_name()=='cli') || ($_SERVER['REQUEST_URI'] <> $_SERVER['SCRIPT_NAME'])) return;

echo "<html><head><title>registre</title></head><body><pre>\n";
$registre = new Registre;
$graph = new RdfExpGraph('default');
$registre->import($graph);
$registre->show();
//echo '$graph = '; print_r($graph);
echo "<h2>Le graphe</h2>\n";
$graph->showInYaml();
$registre->checkIntegrity();
