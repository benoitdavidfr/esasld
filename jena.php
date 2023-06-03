<?php
// Test utilisation de Jena - 3/6/2023
// IHM dispo sur http://fuseki/
// Le JdD doit être créé/détruit avec l'IHM ; je ne sais pas le faire par l'API

require_once 'http.inc.php';

class Html {
  static function selectOptions(string $outputFormat, array $options): string {
    $html = '';
    foreach ($options as $key => $label) {
      $html .= "        <option".(($outputFormat==$key) ? " selected='selected'": '')." value='$key'>$label</option>\n";
    }
    return $html;
  }
};

//$fusekiUrl = 'http://fuseki/$/server'; // ne fonctionne pas - fonctionne uniquement depuis Firefox
$fusekiUrl = 'http://172.19.3:3030';

if (php_sapi_name()=='cli') { // traitement CLI en fonction de l'action demandée 
  if ($argc == 1) {
    echo "usage: php $argv[0] {action}\n";
    echo " où {action} vaut:\n";
    echo "  - registre - effectue uniquement l'import du registre et affiche ce qui a été importé \n";
    echo "  - import - lecture du catalogue depuis Ecosphères en JSON-LD et copie dans des fichiers locaux\n";
    die();
  }
  switch ($argv[1]) {
    default: {
      die("Ereur, $argv[1] ne correspond à aucune action\n");        
    }  
  }
}
else { // !cli
  set_time_limit(60);
  $action = $_GET['action'] ?? '';
  { // formulaire 
    echo "<html><head><title>jena $action</title></head><body>
      <form>
      <select name='action' />\n",
      Html::selectOptions($action, [
        'getDescription'=> "getDescription",
        'postData'=> "postData",
        'putData'=> "putData",
        'getData'=> "getData",
        'sparql'=> "sparql",
      ]),
      "      </select>
      <input type='submit' value='Submit' /></form><pre>\n";
  }
  switch ($action) {
    case 'getDescription': {
      $result = Http::request("$fusekiUrl/\$/server", [
        'Accept'=> 'application/json',
        'Authorization'=> 'Basic '.base64_encode("admin:benoit"),
      ]);
      echo $result['body'];
      break;
    }
    case 'postData': {
      $result = Http::request("$fusekiUrl/catalog/data", [
        'method'=> 'POST',
        'Content-Type'=> 'application/ld+json',
        'content'=> file_get_contents('page1corrected.jsonld'),
        'Authorization'=> 'Basic '.base64_encode("admin:benoit"),
      ]);
      echo 'headers='; print_r($result['headers']);
      echo htmlspecialchars($result['body']);
      break;
    }
    case 'putData': {
      $result = Http::request("$fusekiUrl/catalog/data", [
        'method'=> 'PUT',
        'Content-Type'=> 'application/ld+json',
        'content'=> file_get_contents('page1corrected.jsonld'),
        'Authorization'=> 'Basic '.base64_encode("admin:benoit"),
      ]);
      echo 'headers='; print_r($result['headers']);
      echo htmlspecialchars($result['body']);
      break;
    }
    case 'getData': {
      //$accept='application/x-turtle';
      $accept='application/ld+json';
      $result = Http::request("$fusekiUrl/catalog/data", ['Accept'=> $accept]);
      echo 'headers='; print_r($result['headers']);
      echo htmlspecialchars($result['body']);
      break;
    }
    case 'sparql': {
      $sparql= <<<EOT
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX dc: <http://purl.org/dc/terms/>
PREFIX foaf: <http://xmlns.com/foaf/0.1/>
PREFIX dcat: <http://www.w3.org/ns/dcat#>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
PREFIX loc: <http://www.w3.org/ns/locn#> 
PREFIX geo: <https://www.iana.org/assignments/media-types/application/vnd.geo+> 
PREFIX geosparql: <http://www.opengis.net/ont/geosparql#>
PREFIX hydra: <http://www.w3.org/ns/hydra/core#> 

SELECT ?dataset ?name
WHERE {
  ?dataset a dcat:Dataset .
  ?dataset dc:publisher ?publisher .
  ?publisher foaf:name ?name
}
LIMIT 25
EOT;
      // interroge le serveur en Sparql et retourne le résultat en CSV, TSV ou JSON
      //'application/sparql-results+json'
      $accept = 'text/tab-separated-values; charset=utf-8';
      $result = http::request("$fusekiUrl/catalog/sparql?query=".urlencode($sparql), ['Accept'=> $accept]);
      echo htmlspecialchars($result['body']);
      echo "--\n",htmlspecialchars($sparql),"\n";
      echo "--\n",'headers='; print_r($result['headers']);
      break;
    }
    default: {
      throw new Exception("Action $action inconnue");
    }
  }
  die("</pre>\n");
}
