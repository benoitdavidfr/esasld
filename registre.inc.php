<?php
// registre.inc.php - charge le registre pour l'utiliser 
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class Registre {
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
    foreach ($registre['properties'] as $ciri => $prop) {
      self::$properties[$ciri] = $prop;
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
};
