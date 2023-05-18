<?php
// registre.inc.php
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class Registre {
  static function import(): array {
    try {
      $registre = Yaml::parseFile(__DIR__.'/registre.yaml');
    } catch (ParseException $exception) {
      throw new Exception('Unable to parse the YAML file: '. $exception->getMessage());
    }
    foreach ($registre['registre'] as $classUri => $resources) {
      if (!($className = RdfClass::CLASS_URI_TO_PHP_NAME[$classUri] ?? null))
        throw new Exception("Erreur, classe $classUri inconnue");
      foreach ($resources as $resource) {
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
    return [];
  }
};
