<?php
// fuseki.inc.php - chargement dans Fuseki d'un ensemble de ressources définies en JSON-LD

require_once __DIR__.'/http.inc.php';

class Fuseki {
  const FUSEKI_URL = 'http://172.19.3:3030';
  const LOGIN_PWD = 'admin:benoit';
  
  static function load(string $data, $contentType='application/ld+json'): array {
    return Http::request(self::FUSEKI_URL.'/catalog/data', [
      'method'=> 'POST',
      'Content-Type'=> $contentType,
      'content'=> $data,
      'Authorization'=> 'Basic '.base64_encode(self::LOGIN_PWD),
    ]);
  }
};