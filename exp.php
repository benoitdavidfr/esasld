<?php
{/*PhpDoc:
title: export.php - script de lecture de l'export du catalogue Ecosphères - 18/5/2023
doc: |
  L'objectif de ce script est de lire l'export DCAT d'Ecosphères en JSON-LD afin d'y détecter d'éventuelles erreurs.
  Chaque clase RDF est traduite par une classe Php avec un mapping trivial défini dans RdfClass::CLASS_URI_TO_PHP_NAME
  Outre la détection et correction d'erreurs, le script affiche différents types d'objets de manière simplifiée
  et plus lisible pour les néophytes.
  Cette simplification correspond, d'une part, à une "compaction JSON-LD" avec un contexte non explicité
  et, d'autre part, à un embedding d'un certain nombre de ressources associées, par exemple les publisher d'un Dataset.
  Ces ressources associées sont définies par les propriétées définies dans PropVal::PROP_RANGE.
  L'affichage est finalement effectuée en Yaml.
  
  Le script utilise un registre stocké dans le fichier registre.yaml qui permet d'associer des étiquettes à un certain
  nombre d'URIs utilisés mais non définis dans l'export DCAT ; par exemple dans la classe Standard l'URI
  'https://tools.ietf.org/html/rfc4287' correspond au format de syndication Atom,
  
  Prolongations éventuelles:
   - tester JsonLD pour compacter une page avec le contexte utilisé pour la simplification
     afin d'afficher la page en JSON-LD compactée et/ou en YAML-LD.
   - formaliser le contexte associé à la simplification et vérifier son exactitude
   - générer un affichage simplifié qui soit un export DCAT valide en YAML-LD
   - réexporter le contenu importé pour bénéficier des corrections, y compris en le paginant
   - définir des shapes SHACL pour valider le graphe DCAT en s'inspirant de ceux de DCTA-AP

journal: |
 19/5/2023:
  - gestion des ressources PagedCollection comme les autres ressources Rdf permettant de les visualiser
  - extension du registre avec la déf. des ontologies
 18/5/2023:
  - ajout classes Standard et LicenseDocument avec leur registre
  - ajout gestion des Location avec URI INSEE
  - transfert des registres associés aux classes dans le fichier registre.yaml
  - amélioration du choix de traitement en fonction des arguments du script
  - affichage de stats sur les erreurs détectées et corrigées
  - affichage interactif de la version corrigée page par page en Yaml, JSON-LD ou Turtle
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
  - refonte de l'architecture
*/}
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/rdf.inc.php';
require_once __DIR__.'/statem.inc.php';
require_once __DIR__.'/registre.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
use ML\JsonLD\JsonLD;

ini_set('memory_limit', '1G');


class StdErr { // afffichage de messages d'info, d'alerte ou d'erreur non fatale 
  static array $messages=[]; // [{message} => {nbre}]
  
  static function write(string $message): void {
    if (!defined('STDERR')) { // en non CLI les messages sont justes stockés sans répétition en gardant le nombre d'itération
      self::$messages[$message] = (self::$messages[$message] ?? 0) + 1;
    }
    elseif (!isset(self::$messages[$message])) { // en CLI si le message est nouveau 
      fwrite(STDERR, "$message\n"); // alors affichage sur STDERR
      self::$messages[$message] = 1; // et stockage du message
    }
    else { // en CLI si le message est déjà apparu
      self::$messages[$message]++; // alors le nbre d'itérations est incrémenté
    }
  }
};

// extrait le code HTTP de retour de l'en-tête HTTP
function httpResponseCode(array $header) { return substr($header[0], 9, 3); }

{/* importe l'export JSON-LD et construit les objets chacun dans leur classe
  lorque le fichier est absent:
    si $skip est faux alors le site est interrogé
    sinon ($skip vrai) alors la page est sautée et marquée comme erreur
  Si $lastPage est indiquée et différente de 0 alors la lecture s'arrête à cette page,
  sinon elle vaut 0 et le numéro de la dernière page est lu dans une des pages.
  Si $firstPage est indiquée alors la lecture commence à cette page, sinon elle vaut 1.
*/}
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
    //StdErr::write("Info: nbelts de la page $page = ".count($content)."\n");
    
    foreach ($content as $no => $resource) {
      RdfClass::increment('stats', "nbre de ressources lues");
      $types = implode(', ', $resource['@type']); 
      if ($className = (RdfClass::CLASS_URI_TO_PHP_NAME[$types] ?? null)) {
        $resource = $className::add($resource);
        if (($className == 'PagedCollection') && ($lastPage == 0)) {
          $lastPage = $resource->lastPage();
          StdErr::write("Info: lastPage=$lastPage\n");
        }
      }
      else
        throw new Exception("Types $types non traité");
    }
  }
  Dataset::rectifStatements(); // correction des propriétés accessRights qui nécessite que tous les objets soient chargés 
  return $errors;
}


$urlPrefix = 'https://preprod.data.developpement-durable.gouv.fr/dcat/catalog';

if (php_sapi_name()=='cli') { // traitement CLI en fonction de l'action demandée 
  if ($argc == 1) {
    echo "usage: php $argv[0] {action} [{firstPage} [{lastPage}]]\n";
    echo " où {action} vaut:\n";
    echo "  - rectifStats - affiche des stats des rectifications effectuées\n";
    echo "  - registre - effectue uniquement l'import du registre et affiche ce qui a été importé \n";
    echo "  - import - lecture du catalogue depuis Ecosphères en JSON-LD et copie dans des fichiers locaux\n";
    echo "  - errors - afffichage des erreurs rencontrées lors de la lecture du catalogue\n";
    echo "  - catalogs - lecture du catalogue puis affichage des catalogues\n";
    echo "  - datasets - lecture du catalogue puis affichage des jeux de données\n";
    foreach (RdfClass::CLASS_URI_TO_PHP_NAME as $classUri => $className)
      echo "  - $className - affiche les objets de la classe $className y compris les blank nodes\n";
    die();
  }

  // Par défaut lecture de toutes les pages
  $firstPage = $argv[2] ?? 1; // Par défaut démarrage à la première page
  $lastPage = $argv[3] ?? 0;  // Par défaut fin à la dernière page définie dans l'import

  switch ($argv[1]) {
    case 'rectifStats': {
      Registre::import();
      import($urlPrefix, true, $lastPage, $firstPage);
      echo '$stats = '; print_r(RdfClass::$stats);
      echo '$rectifStats = '; print_r(RdfClass::$rectifStats);
      break;
    }
    case 'registre': { // effectue uniquement l'import du registre et affiche ce qui a été importé 
      Registre::import();
      Registre::show();
      echo "\nRessources prédéfinies:\n";
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
}
else { // affichage interactif de la version corrigée page par page en Yaml, JSON-LD ou Turtle
  echo "";
  $page = $_GET['page'] ?? 1;
  $outputFormat = $_GET['outputFormat'] ?? 'yaml';
  { // formulaire 
    echo "<html><head><title>exp $outputFormat</title></head><body>
      <form>
      <a href='?page=",$page-1,isset($_GET['outputFormat']) ? "&outputFormat=$_GET[outputFormat]" : '',"'>&lt;</a>
      Page $page
      <a href='?page=",$page+1,isset($_GET['outputFormat']) ? "&outputFormat=$_GET[outputFormat]" : '',"'>&gt;</a>
      <input type='hidden' name='page' value='$page' />
      <select name='outputFormat' id='outputFormat'>
        <option",($outputFormat=='yaml') ? " selected='selected'": ''," value='yaml'>Yaml</option>
        <option",($outputFormat=='jsonld') ? " selected='selected'": ''," value='jsonld'>JSON-LD</option>
        <option",($outputFormat=='jsonLdContext') ? " selected='selected'": ''," value='jsonLdContext'>JSON-LD contexte</option>
        <option",($outputFormat=='jsonldc') ? " selected='selected'": ''," value='jsonldc'>JSON-LD compacté</option>
        <option",($outputFormat=='jsonldf') ? " selected='selected'": ''," value='jsonldf'>JSON-LD imbriqué</option>
        <option",($outputFormat=='turtle') ? " selected='selected'": ''," value='turtle'>Turtle</option>
      </select>
      <input type='submit' value='Submit' /></form><pre>\n";
  }
  
  Registre::import();
  if ($errors = import($urlPrefix, true, $page, $page)) {
    echo 'errors = '; print_r($errors);
  }
  echo "---\n";
  define('JSON_OPTIONS',
          JSON_PRETTY_PRINT|JSON_UNESCAPED_LINE_TERMINATORS|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  switch ($outputFormat) {
    case 'yaml': { // affichage simplifié des datasets en Yaml 
      $output = Dataset::show(false);
      if (StdErr::$messages) {
        echo 'StdErr::$messages = '; print_r(StdErr::$messages);
        echo "---\n";
      }
      echo htmlspecialchars($output); // affichage Yaml
      break;
    }
    case 'jsonld': { // affiche le JSON-LD généré par RdfClass 
      echo htmlspecialchars(json_encode(RdfClass::exportAsJsonLd(), JSON_OPTIONS));
      break;
    }
    case 'jsonLdContext': {
      echo htmlspecialchars(json_encode(Registre::jsonLdContext(), JSON_OPTIONS));
      break;
    }
    case 'jsonldc': { // affiche le JSON-LD compacté avec JsonLD
      if (!is_dir('tmp')) mkdir('tmp');
      file_put_contents('tmp/document.jsonld', json_encode(RdfClass::exportAsJsonLd(), JSON_OPTIONS));
      file_put_contents('tmp/context.jsonld', json_encode(Registre::jsonLdContext(), JSON_OPTIONS));
      $compacted = JsonLD::compact('tmp/document.jsonld', 'tmp/context.jsonld');
      echo htmlspecialchars(json_encode($compacted, JSON_OPTIONS));
      break;
    }
    case 'jsonldf': { // affiche le JSON-LD structuré (framed) avec JsonLD
      if (!is_dir('tmp')) mkdir('tmp');
      file_put_contents('tmp/document.jsonld', json_encode(RdfClass::exportAsJsonLd(), JSON_OPTIONS));
      file_put_contents('tmp/frame.jsonld', json_encode(Registre::jsonLdFrame(), JSON_OPTIONS));
      $framed = JsonLD::frame('tmp/document.jsonld', 'tmp/frame.jsonld');
      echo htmlspecialchars(json_encode($framed, JSON_OPTIONS));
      break;
    }
    case 'turtle': { // traduction en Turtle avec EasyRdf
      $graph = new \EasyRdf\Graph('https://preprod.data.developpement-durable.gouv.fr/');
      $graph->parse(json_encode(RdfClass::exportAsJsonLd()), 'jsonld', 'https://preprod.data.developpement-durable.gouv.fr/');
      echo htmlspecialchars($graph->serialise('turtle'));
      break;
    }
    default: {
      throw new Exception("Format $outputFormat inconnu");
    }
  }
  die("</pre>\n");
}
