<?php
/*registre.inc.php - charge le registre et fournit les méthodes pour l'utiliser
*/
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class Property { // méthodes sur les propriétés 
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

class Registre { // stockage du registre 
  static array $namespaces=[]; // [{prefix} => {iri}]
  static array $ontologies=[];
  static array $classes=[];
  static array $properties=[];
  
  static function expandCiri(string $ciri): string {
    $parts = explode(':', $ciri);
    return self::$namespaces[$parts[0]].$parts[1];
  }
  
  static function import(): array {
    try {
      $registre = Yaml::parseFile(__DIR__.'/registre.yaml');
    } catch (ParseException $exception) {
      throw new Exception('Unable to parse the YAML file: '. $exception->getMessage());
    }
    foreach ($registre['namespaces'] as $prefix => $iri) {
      self::$namespaces[$prefix] = $iri;
    }
    //var_dump(self::$namespaces);
    foreach ($registre['ontologies'] as $ciri => $ontology) {
      self::$ontologies[$ciri] = $ontology;
    }
    foreach ($registre['classes'] as $classCiri => $classe) {
      self::$classes[$classCiri] = $classe;
      if (isset($classe['resources'])) {
        $classUri = self::expandCiri($classCiri);
        if (!($className = RdfClass::CLASS_URI_TO_PHP_NAME[$classUri] ?? null))
          throw new Exception("Erreur, classe $classUri inconnue");
        foreach ($classe['resources'] as $resource) {
          $r = [
            '@id'=> $resource['$id'],
            '@type'=> [$classUri],
            'http://www.w3.org/2000/01/rdf-schema#label'=> [],
          ];
          foreach ($resource['label'] as $lang => $value) {
            $r['http://www.w3.org/2000/01/rdf-schema#label'][] =
              $lang ? ['@language'=> $lang, '@value'=> $value] : ['@value'=> $value];
          }
          $className::add($r);
        }
      }
    }
    $names = [];
    foreach ($registre['properties'] as $ciri => $prop) {
      self::$properties[$ciri] = $prop;
      /*$parts = explode(':', $ciri);
      if (isset($names[$parts[1]])) {
        throw new Exception("Erreur de collision sur $ciri avec ".$names[$parts[1]]);
      }
      $names[$parts[1]] = $ciri;*/
    }
    return [];
  }

  static function show(): void {
    echo Yaml::dump([
      '$namespaces'=> self::$namespaces,
      '$ontologies'=> self::$ontologies,
      '$classes'=> self::$classes,
      '$properties'=> self::$properties,
    ], 5);
  }
  
  static function jsonLdContext(): array { // contexte JSON-LD de exportAllAsJsonLd() comme array Php
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
  
  static function jsonLdFrame(): array {
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
