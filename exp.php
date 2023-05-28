<?php
{/*PhpDoc:
title: export.php - script de lecture de l'export du catalogue Ecosphères - 18/5/2023
doc: |
  L'objectif de ce script est de lire l'export DCAT d'Ecosphères en JSON-LD afin d'y détecter d'éventuelles erreurs.
  Chaque clase RDF est traduite par une classe Php avec un mapping trivial défini dans RdfResource::CLASS_URI_TO_PHP_NAME
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
   - générer un affichage simplifié qui soit un export DCAT valide en YAML-LD
     - l'enjeeu est
       - d'une part de définir le contexte adhoc qui formalise la structure de données d'export
       - d'autre part d'effectuer le framing adhoc
      - on pourrait avoir les exports suivants
        - les catalogues moissonnés sans les JD
        - les JdD d'un catalogue particulier
        - les organizations
        - les JdD d'une organization donnée dans les différents catalogues
   - réexporter le contenu importé pour bénéficier des corrections, y compris en le paginant
   - définir des shapes SHACL pour valider le graphe DCAT en s'inspirant de ceux de DCTA-AP

  21/5 17h30 Les dernières modifs ne machent pas
journal: |
 28/5/2023:
  - ajout classe RdfGraph pour gérer les ressources par graphe
 21/5/2023:
  - regroupement dans la classe GenResource de classes simples n'ayant aucun traitement spécifique
 19/5/2023:
  - gestion des ressources PagedCollection comme les autres ressources Rdf permettant de les visualiser
  - extension du registre avec la déf. des ontologies
  - mise en oeuvre d'un contexte JSON-LD et d'un frame, à débugger et améliorer
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

ini_set('memory_limit', '2G');


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


define('JSON_OPTIONS',
        JSON_PRETTY_PRINT|JSON_UNESCAPED_LINE_TERMINATORS|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
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
    foreach (array_unique(array_values(RdfResource::CLASS_URI_TO_PHP_NAME)) as $classUri => $className)
      echo "  - $className - affiche les objets de la classe $className y compris les blank nodes\n";
    die();
  }

  // Par défaut lecture de toutes les pages
  $firstPage = $argv[2] ?? 1; // Par défaut démarrage à la première page
  $lastPage = $argv[3] ?? 0;  // Par défaut fin à la dernière page définie dans l'import

  switch ($argv[1]) {
    case 'rectifStats': {
      $graph = new RdfGraph('default');
      Registre::import($graph);
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      echo '$stats = '; print_r($graph->stats());
      echo '$rectifStats = '; print_r($graph->rectifStats());
      break;
    }
    case 'registre': { // effectue uniquement l'import du registre et affiche ce qui a été importé 
      $graph = new RdfGraph('default');
      Registre::import($graph);
      Registre::show();
      echo "\nRessources prédéfinies:\n";
      foreach (RdfResource::CLASS_URI_TO_PHP_NAME as $classUri => $className)
        $className::show();
      break;
    }
    case 'import': { // effectue uniquement l'import de l'export
      $graph = new RdfGraph('default');
      Registre::import($graph);
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      break;
    }
    case 'errors': {
      $graph = new RdfGraph('default');
      Registre::import($graph);
      $errors = $graph->import($urlPrefix, true, $lastPage, $firstPage);
      echo "Pages en erreur:\n";
      foreach ($errors as $page => $error)
        echo "  $page: $error\n";
      break;
    }
    case 'catalogs': {
      $graph = new RdfGraph('default');
      Registre::import($graph);
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      //print_r(RdfResource::$pkeys);
      Catalog::show();
      break;
    }
    case 'datasets': { // import du registre et de l'export puis affichage des datasets
      $graph = new RdfGraph('default');
      Registre::import($graph); // importe le registre dans le graphe
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      $graph->show('Dataset');
      break;
    }
    
    case 'frameDatasets': {
      $graph = new RdfGraph('default');
      Registre::import($graph);
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      Dataset::frameAll(['http://purl.org/dc/terms/publisher']);
      echo json_encode(Dataset::exportClassAsJsonLd(), JSON_OPTIONS);
      break;
    }
    
    default: {
      foreach (RdfResource::CLASS_URI_TO_PHP_NAME as $classUri => $className) {
        if ($argv[1] == $className) {
          $graph = new RdfGraph('default');
          Registre::import($graph);
          $graph->import($urlPrefix, true, $lastPage, $firstPage);
          $graph->showIncludingBlankNodes($className);
          die();
        }
      }
    
      die("$argv[1] ne correspond à aucune action\n");
    }
  }
}
else { // affichage interactif de la version corrigée page par page en Yaml, JSON-LD ou Turtle
  set_time_limit(60);
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
    case 'jsonld': { // affiche le JSON-LD généré par RdfResource 
      echo htmlspecialchars(json_encode(RdfResource::exportAllAsJsonLd(), JSON_OPTIONS));
      break;
    }
    case 'jsonLdContext': {
      echo htmlspecialchars(json_encode(Registre::jsonLdContext(), JSON_OPTIONS));
      break;
    }
    case 'jsonldc': { // affiche le JSON-LD compacté avec JsonLD
      if (!is_dir('tmp')) mkdir('tmp');
      file_put_contents('tmp/document.jsonld', json_encode(RdfResource::exportAllAsJsonLd(), JSON_OPTIONS));
      file_put_contents('tmp/context.jsonld', json_encode(Registre::jsonLdContext(), JSON_OPTIONS));
      $compacted = JsonLD::compact('tmp/document.jsonld', 'tmp/context.jsonld');
      echo htmlspecialchars(json_encode($compacted, JSON_OPTIONS));
      break;
    }
    case 'jsonldf': { // affiche le JSON-LD structuré (framed) avec JsonLD
      if (!is_dir('tmp')) mkdir('tmp');
      file_put_contents('tmp/document.jsonld', json_encode(RdfResource::exportAllAsJsonLd(), JSON_OPTIONS));
      file_put_contents('tmp/frame.jsonld', json_encode(Registre::jsonLdFrame(), JSON_OPTIONS));
      $framed = JsonLD::frame('tmp/document.jsonld', 'tmp/frame.jsonld');
      echo htmlspecialchars(json_encode($framed, JSON_OPTIONS));
      break;
    }
    case 'turtle': { // traduction en Turtle avec EasyRdf
      $graph = new \EasyRdf\Graph('https://preprod.data.developpement-durable.gouv.fr/');
      $graph->parse(json_encode(RdfResource::exportAllAsJsonLd()), 'jsonld', 'https://preprod.data.developpement-durable.gouv.fr/');
      echo htmlspecialchars($graph->serialise('turtle'));
      break;
    }
    default: {
      throw new Exception("Format $outputFormat inconnu");
    }
  }
  die("</pre>\n");
}
