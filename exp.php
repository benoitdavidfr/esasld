<?php
{/*PhpDoc:
title: exp.php - script de lecture de l'export du catalogue Ecosphères - 29/5/2023
doc: |
  L'objectif de ce script est de lire l'export DCAT d'Ecosphères en JSON-LD afin d'y détecter d'éventuelles erreurs.
  Les classes RDF sont traduites par une classe Php avec un mapping défini dans RdfResource::CLASS_URI_TO_PHP_NAME
  Outre la détection et correction d'erreurs, le script affiche différents types d'objets de manière simplifiée
  et plus lisible pour les néophytes.
  Cette simplification correspond, d'une part, à une "compaction JSON-LD" avec un contexte non explicité
  et, d'autre part, à un embedding d'un certain nombre de ressources associées, par exemple les publisher d'un Dataset.
  Ces ressources associées sont définies par les propriétées définies dans PropVal::PROP_RANGE.
  L'affichage est finalement effectuée en Yaml.
  
  Le script utilise un registre stocké dans le fichier registre.yaml qui permet d'associer des étiquettes à un certain
  nombre d'URIs utilisés mais non définis dans l'export DCAT ; par exemple dans la classe Standard l'URI
  'https://tools.ietf.org/html/rfc4287' correspond au format de syndication Atom,
  
  A VOIR:
    - gestion des dataTime comme date
    - gestion des Location
  
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
   - définir des shapes SHACL pour valider le graphe DCAT en s'inspirant de ceux de DCAT-AP

journal: |
 1/6/2023:
  - gestion d'un graphe compacté avec tri des propriétés
  - manque
    - puis une phase d'embellissement du Yaml
  - la correction sur les thèmes génère un bug
 30/5/2023:
  - phase d'amélioration (improve) du contenu initial JSON-LD
      - définition de la langue française par défaut pour la plupart des propriétés littérales
      - A FAIRE - réduction des dateTime à des dates
 29/5/2023:
  - test affichage Yaml-LD d'une version framed avec RdfGraph puis compactée avec JsonLD
    - nécessite une phase d'amélioration (improve) du contenu initial JSON-LD
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

class Constant { // Classe support de constantes 
  const FRAME_PARAM = [
        'Dataset' => [
          'http://purl.org/dc/terms/publisher',
          'http://purl.org/dc/terms/conformsTo',
          'http://purl.org/dc/terms/accessRights',
          'http://purl.org/dc/terms/language',
          'http://purl.org/dc/terms/spatial',
          'http://www.w3.org/ns/dcat#theme',
          'http://www.w3.org/ns/dcat#distribution',
          'http://purl.org/dc/terms/rightsHolder',
          'http://xmlns.com/foaf/0.1/isPrimaryTopicOf',
          'http://www.w3.org/ns/adms#status',
        ],
        'Distribution' => [
          'http://purl.org/dc/terms/license',
          'http://purl.org/dc/terms/format',
          'http://purl.org/dc/terms/conformsTo',
          'http://www.w3.org/ns/dcat#accessService',
        ],
        'CatalogRecord' => [
          'http://purl.org/dc/terms/language',
          'http://www.w3.org/ns/dcat#contactPoint',
          'http://www.w3.org/ns/dcat#inCatalog',
        ],
        'DataService' => [
          'http://purl.org/dc/terms/conformsTo',
        ],
      ]; // paramètres de la fonction frame()
  const PROP_IDS = [
        'title',
        'description',
        'publisher',
        'status',
        'inSeries',
        'issued',
        'modified',
        'created',
        'conformsTo',
        'accessRights',
        'rightsHolder',
        'theme',
        'keyword',
        'landingPage',
        'page',
        'language',
        'identifier',
        'dct:spatial',
        'isPrimaryTopicOf' => ['contactPoint','inCatalog','modified','modifiedT','language','identifier'],        
        'distribution' => ['title','license','accessService','format','accessURL','downloadURL'],        
      ]; // ordre des propriétés dans la sortie 
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
    echo "  - yamlldfc2 - affiche Yaml-ld framed (RdfGraph::frame()) et le contexte context.yaml puis compacté avec JsonLD\n";
    foreach (array_unique(array_values(RdfResource::CLASS_URI_TO_PHP_NAME)) as $classUri => $className)
      echo "  - $className - affiche les objets de la classe $className y compris les blank nodes\n";
    die();
  }

  // Par défaut lecture de toutes les pages
  $firstPage = $argv[2] ?? 1; // Par défaut démarrage à la première page
  $lastPage = $argv[3] ?? 0;  // Par défaut fin à la dernière page définie dans l'import

  $graph = new RdfGraph('default');
  Registre::import($graph);

  switch ($argv[1]) {
    case 'rectifStats': {
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      echo '$stats = '; print_r($graph->stats());
      echo '$rectifStats = '; print_r($graph->rectifStats());
      break;
    }
    case 'registre': { // effectue uniquement l'import du registre et affiche ce qui a été importé 
      Registre::show();
      echo "\nRessources prédéfinies:\n";
      foreach (RdfResource::CLASS_URI_TO_PHP_NAME as $classUri => $className)
        $graph->show($className);
      break;
    }
    case 'import': { // effectue uniquement l'import de l'export
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      break;
    }
    case 'errors': {
      $errors = $graph->import($urlPrefix, true, $lastPage, $firstPage);
      echo "Pages en erreur:\n";
      foreach ($errors as $page => $error)
        echo "  $page: $error\n";
      break;
    }
    case 'catalogs': {
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      //print_r(RdfResource::$pkeys);
      $graph->show('Catalog');
      break;
    }
    case 'datasets': { // import du registre et de l'export puis affichage des datasets
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      $graph->show('Dataset');
      break;
    }
    
    case 'frameDatasets': {
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      $graph->frame('Dataset', ['http://purl.org/dc/terms/publisher']);
      echo json_encode($graph->exportClassAsJsonLd('Dataset'), JSON_OPTIONS);
      break;
    }
    
    case 'yamlldfc2': { // affiche Yaml-ld framed (RdfGraph::frame()) et le contexte context.yaml puis compacté avec JsonLD
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      //print_r($graph);
      $graph->frame(Constant::FRAME_PARAM);
      //echo Yaml::dump($graph->exportClassAsJsonLd('Dataset'), 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
      $comped = new RdfCompactGraph(new RdfContext(Yaml::parseFile('context.yaml')), $graph->exportClassAsJsonLd('Dataset'));
      
      //print_r($comped);
      echo Yaml::dump($comped->jsonld(Constant::PROP_IDS), 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK); // convertit en Yaml
      break;
    }
    
    default: {
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      if (in_array($argv[1], RdfResource::CLASS_URI_TO_PHP_NAME)) {
        $graph->showIncludingBlankNodes($argv[1]);
      }
      else {
        die("Ereur, $argv[1] ne correspond à aucune action\n");        
      }
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
        <!-- <option",($outputFormat=='jsonldf') ? " selected='selected'": ''," value='jsonldf'>JSON-LD imbriqué</option> -->
        <option",($outputFormat=='turtle') ? " selected='selected'": ''," value='turtle'>Turtle</option>
        <option",($outputFormat=='yamlldfc') ? " selected='selected'": ''," value='yamlldfc'>Yaml-ld framed et compacté</option>
        <option",($outputFormat=='yamlldfc2') ? " selected='selected'": ''," value='yamlldfc2'>Yaml-ld framed et compacté 2</option>
      </select>
      <input type='submit' value='Submit' /></form><pre>\n";
  }
  
  $graph = new RdfGraph('default');
  Registre::import($graph);
  if ($errors = $graph->import($urlPrefix, true, $page, $page)) {
    echo 'errors = '; print_r($errors);
  }
  echo "---\n";
  switch ($outputFormat) {
    case 'yaml': { // affichage simplifié des datasets en Yaml 
      $output = $graph->show('Dataset', false);
      if (StdErr::$messages) {
        echo 'StdErr::$messages = '; print_r(StdErr::$messages);
        echo "---\n";
      }
      echo htmlspecialchars($output); // affichage Yaml
      break;
    }
    
    case 'jsonld': { // affiche le JSON-LD rectifié généré par $graph 
      echo htmlspecialchars(json_encode($graph->exportAllAsJsonLd(), JSON_OPTIONS));
      break;
    }
    
    case 'jsonLdContext': {
      echo htmlspecialchars(json_encode(Registre::jsonLdContext(), JSON_OPTIONS));
      break;
    }
    
    case 'jsonldc': { // affiche le JSON-LD compacté avec JsonLD et le contexte déduit du registre
      if (!is_dir('tmp')) mkdir('tmp');
      file_put_contents('tmp/document.jsonld', json_encode($graph->exportAllAsJsonLd(), JSON_OPTIONS));
      file_put_contents('tmp/context.jsonld', json_encode(Registre::jsonLdContext(), JSON_OPTIONS));
      $compacted = JsonLD::compact('tmp/document.jsonld', 'tmp/context.jsonld');
      echo htmlspecialchars(json_encode($compacted, JSON_OPTIONS));
      break;
    }
    
    /*case 'jsonldf': { // affiche le JSON-LD structuré (framed) avec JsonLD
      if (!is_dir('tmp')) mkdir('tmp');
      file_put_contents('tmp/document.jsonld', json_encode($graph->exportAllAsJsonLd(), JSON_OPTIONS));
      file_put_contents('tmp/frame.jsonld', json_encode(Registre::jsonLdFrame(), JSON_OPTIONS));
      $framed = JsonLD::frame('tmp/document.jsonld', 'tmp/frame.jsonld');
      echo htmlspecialchars(json_encode($framed, JSON_OPTIONS));
      break;
    }*/
    case 'turtle': { // traduction en Turtle avec EasyRdf
      $erGraph = new \EasyRdf\Graph('https://preprod.data.developpement-durable.gouv.fr/');
      $erGraph->parse(json_encode($graph->exportAllAsJsonLd()), 'jsonld', 'https://preprod.data.developpement-durable.gouv.fr/');
      echo htmlspecialchars($erGraph->serialise('turtle'));
      break;
    }
    
    case 'yamlldfc': { // affiche Yaml-ld framed (RdfGraph::frame()) et le contexte context.yaml puis compacté avec JsonLD
      $graph->frame(['Dataset' => ['http://purl.org/dc/terms/publisher']]);
      //echo Yaml::dump($graph->exportClassAsJsonLd('Dataset'), 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
      file_put_contents('tmp/context.jsonld', json_encode(Yaml::parseFile('context.yaml')));
      file_put_contents('tmp/expanded.jsonld', json_encode($graph->exportClassAsJsonLd('Dataset')));
      try {
        $comped = JsonLD::compact('tmp/expanded.jsonld', 'tmp/context.jsonld');
        //unset($comped->{'@context'});
        $comped = json_decode(json_encode($comped, JSON_OPTIONS), true); // suppr. StdClass
        //print_r($comped);
        $comped = Yaml::dump($comped, 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK); // convertit en Yaml
        echo $comped;
      } catch (ML\JsonLD\Exception\JsonLdException $e) {
        echo $e->getMessage();
        $comped = '';
      }
      break;
    }

    case 'yamlldfc2': { // affiche Yaml-ld framed (RdfGraph::frame()) et le contexte context.yaml puis compacté avec JsonLD
      //print_r($graph);
      $graph->frame(Constant::FRAME_PARAM);
      //echo Yaml::dump($graph->exportClassAsJsonLd('Dataset'), 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
      $comped = new RdfCompactGraph(new RdfContext(Yaml::parseFile('context.yaml')), $graph->exportClassAsJsonLd('Dataset'));
      
      //print_r($comped);
      echo Yaml::dump($comped->jsonld(Constant::PROP_IDS), 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK); // convertit en Yaml
      break;
    }
    default: {
      throw new Exception("Format $outputFormat inconnu");
    }
  }
  die("</pre>\n");
}
