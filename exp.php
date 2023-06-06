<?php
{/*PhpDoc:
title: exp.php - lecture de l'export du catalogue Ecosphères - 1/6/2023
doc: |
  Le premier objectif de ce script est de lire l'export DCAT d'Ecosphères en JSON-LD afin d'y détecter d'éventuelles erreurs.
  Le résultat est formalisé par la cmde rectifStats qui retourne la liste des erreurs rencontrées et corrigées.
  
  Le second objectif est de corriger ces erreurs et d'afficher le catalogue de manière plus compréhensible.
  Le résultat est un affichage simplifié des JdD du catalogue fondé sur le format Yaml-LD imbriqué (framed)
  et compacté avec un contexte défini dans context.yaml.
  
  Le troisième objectif est de me familiariser avec le traitement du JSON-LD en testant notamment l'utilisation de:
   - EasyRdf - https://www.easyrdf.org/ - A PHP library designed to make it easy to consume and produce RDF.
   - JsonLD - https://github.com/lanthaler/JsonLD - A fully conforming JSON-LD (1.0) processor written in PHP. 
  Sur easyRdf, j'ai identifié des bugs et la seule fonctionnalité intéressante est la conversion de JSON-LD en Turtle
  utilisée dans la commande !cli 'turtle'
  
  Sur JsonLD:
   - JsonLD::compact() fonctionne bien, illustré par la cmde !cli JsonLD::compact
   - JsonLD::frame() ne fonctionne pas, illustré par la cmde !cli JsonLD::frame
     c'est peut-être du au fait que j'ai utilisé JSON-LD 1.1 alors que JsonLD implémente JSON-LD 1.0
   - JsonLD::flatten() ne semble pas fonctionner, il utilise des blank nodes qu'il ne définit pas
   - JsonLD::expand() semble fonctionner correctement, il pourrait être utilisé pour fabriquer un RdfExpGraph à partir
     d'un RdfCompactGraph (suppression du context)
  
  Le quatrième objectif est de charger le contenu du catalogue Ecosphères dans Jena pour l'interroger en SparQl.
  Cela est réalisé le 3/6.
  
  Le script utilise un registre stocké dans le fichier registre.yaml qui associe des étiquettes à un certain
  nombre d'URIs utilisés mais non définis dans l'export DCAT ; par exemple dans la classe Standard l'URI
  'https://tools.ietf.org/html/rfc4287' correspond au format de syndication Atom,
  
  
  A VOIR:
    - ajouter dans le graphe une propriété phpClassNameOfUri contenant le mapping URI -> phpClassName
      - pour simplifier le code, notamment get()
    - boucler la boucle en faisant sur la sortie Yaml-LD un Yaml::parse(), expand et flattening
      et comparer le résultat avec le JSON-LD initial
      - modifier la boucle en comparant des graphes imbriqués et non aplanis
    - mieux gérer les constantes et si possible les déduire de la déf. des ontologies
  
  Prolongations éventuelles:
   - définir des shapes SHACL pour valider le graphe DCAT en s'inspirant de ceux de DCAT-AP
     - voir les outils proposés par Jena
*/}
{/*journal: |
 6/6/2023:
  - mise au point du schema JSON sur les datasets pour contrôler leur structuration et test partiel
 5/6/2023:
  - ajout mapping de '@id' et '@type' pour améliorer le Yaml
  - ajout construction de l'index URI -> page et affichage d'un JdD sur son URI
 4/6/2023:
  - prise en compte évols registre
 3/6/2023:
  - chargement des pages restantes
  - chargement de l'export dans Jena
 2/6/2023:
  - la boucle ne fonctionne pas car l'opération flatten() génère des réf à des blank node sans les définir
 1/6/2023:
  - gestion d'un graphe compacté avec tri des propriétés
  - manque une phase d'embellissement du Yaml
  - la correction sur les thèmes génère un bug
 30/5/2023:
  - phase d'amélioration (improve) du contenu initial JSON-LD
      - définition de la langue française par défaut pour la plupart des propriétés littérales
      - A FAIRE - réduction des dateTime à des dates
 29/5/2023:
  - test affichage Yaml-LD d'une version framed avec RdfExpGraph puis compactée avec JsonLD
    - nécessite une phase d'amélioration (improve) du contenu initial JSON-LD
 28/5/2023:
  - ajout classe RdfExpGraph pour gérer les ressources par graphe
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
  - définition de la classe ExpPropVal
  - définition des mathodes cleanYml() et yamlToExpPropVal()
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
require_once __DIR__.'/lib.inc.php';
require_once __DIR__.'/rdfexpand.inc.php';
require_once __DIR__.'/rdfcomp.inc.php';
require_once __DIR__.'/registre.inc.php';
require_once __DIR__.'/fuseki.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
use ML\JsonLD\JsonLD;

ini_set('memory_limit', '2G');


class Constant { // Classe support de constantes 
  const FRAME_PARAM = [
    'Dataset' => [
      'http://purl.org/dc/terms/publisher',
      'http://purl.org/dc/terms/creator',
      'http://www.w3.org/ns/dcat#contactPoint',
      'http://purl.org/dc/terms/temporal',
      'http://purl.org/dc/terms/accrualPeriodicity',
      'http://purl.org/dc/terms/conformsTo',
      'http://purl.org/dc/terms/accessRights',
      'http://purl.org/dc/terms/provenance',
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
    'creator',
    'contactPoint',
    'status' => ['prefLabel','inScheme'],
    'inSeries',
    'issued',
    'issuedT',
    'modified',
    'modifiedT',
    'created',
    'temporal'=> ['startDate','endDate'],
    'accrualPeriodicity' => ['prefLabel','inScheme'],
    'provenance',
    'conformsTo',
    'theme' => ['prefLabel','inScheme'],
    'keyword',
    'landingPage',
    'page',
    'language',
    'accessRights',
    'rightsHolder',
    'identifier',
    'spatial',
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
    echo "  - import - lecture du catalogue depuis Ecosphères en JSON-LD ou des fichiers locaux\n";
    echo "  - importSsSkip - lecture sans skip du catalogue depuis Ecosphères en JSON-LD et copie dans des fichiers locaux\n";
    echo "  - fuseki - import des fichiers locaux dans Fuseki\n";
    echo "  - errors - afffichage des erreurs rencontrées lors de la lecture du catalogue\n";
    echo "  - catalogs - lecture du catalogue puis affichage des catalogues\n";
    echo "  - datasets - lecture du catalogue puis affichage des jeux de données\n";
    echo "  - yamlldfc - affiche Yaml-ld framed (RdfExpGraph::frame()) et le contexte context.yaml puis compacté avec JsonLD\n";
    echo "  - buildIndex - construit l'index des URI des fiches de MD\n";
    foreach (array_unique(array_values(RdfExpGraph::CLASS_URI_TO_PHP_NAME)) as $className)
      echo "  - $className - affiche les objets de la classe $className y compris les blank nodes\n";
    die();
  }

  // Par défaut lecture de toutes les pages
  $firstPage = $argv[2] ?? 1; // Par défaut démarrage à la première page
  $lastPage = $argv[3] ?? 0;  // Par défaut fin à la dernière page définie dans l'import

  $graph = new RdfExpGraph('default');
  $registre = new Registre(__DIR__.'/registre.yaml');
  $registre->import($graph); // importe les ressources bien connues dans le graphe

  switch ($argv[1]) {
    case 'rectifStats': {
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      echo '$stats = '; print_r($graph->stats());
      echo '$rectifStats = '; print_r($graph->rectifStats());
      break;
    }
    case 'registre': { // effectue uniquement l'import du registre et affiche ce qui a été importé 
      $registre->show();
      echo "\nRessources prédéfinies:\n";
      $graph->showInYaml();
      break;
    }
    case 'import': { // effectue uniquement l'import de l'export
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      break;
    }
    case 'importSsSkip': { // effectue uniquement l'import de l'export sans skip
      $graph->import($urlPrefix, false, $lastPage, $firstPage);
      break;
    }
    case 'fuseki': { // import de chargement dans Fuseki
      $stats = new Stats;
      for ($page = $firstPage; ($lastPage == 0) || ($page <= $lastPage); $page++) {
        if (is_file("json/export$page.json")) { // le fichier existe
          $graph = new RdfExpGraph('default');
          $registre = new Registre(__DIR__.'/registre.yaml');
          $registre->import($graph); // importe les ressources bien connues dans le graphe
          $content = file_get_contents("json/export$page.json");
          $content = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
          foreach ($content as $no => $resource) {
            $stats->increment("nbre de ressources lues");
            $types = implode(', ', $resource['@type']); 
            if (!($className = (RdfExpGraph::CLASS_URI_TO_PHP_NAME[$types] ?? null))) {
              throw new Exception("Types $types non traité");
            }
            $resource = $graph->addResource($resource, $className);
            if (($className == 'PagedCollection') && ($lastPage == 0)) {
              $lastPage = $resource->lastPage();
              StdErr::write("Info: lastPage=$lastPage\n");
            }
          }
          $graph->rectifAllStatements();
          $result = Fuseki::load(json_encode($graph->allAsJsonLd()));
          echo 'headers='; print_r($result['headers']);
          echo 'body=',$result['body'];
        }
      }
      break;
    }
    case 'buildIndex': { // construction de l'index des datasets
      $index = []; // [{URI}=> {noPage}]
      $stats = new Stats;
      for ($page = $firstPage; ($lastPage == 0) || ($page <= $lastPage); $page++) {
        if (!is_file("json/export$page.json")) continue; // le fichier n'existe pas
        $graph = new RdfExpGraph('default');
        $registre = new Registre(__DIR__.'/registre.yaml');
        $registre->import($graph); // importe les ressources bien connues dans le graphe
        $content = file_get_contents("json/export$page.json");
        $content = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        foreach ($content as $no => $resource) {
          $stats->increment("nbre de ressources lues");
          $types = implode(', ', $resource['@type']); 
          if (!($className = (RdfExpGraph::CLASS_URI_TO_PHP_NAME[$types] ?? null)))
            throw new Exception("Types $types non traité");
          $resource = $graph->addResource($resource, $className);
          if (($className == 'PagedCollection') && ($lastPage == 0)) {
            $lastPage = $resource->lastPage();
            StdErr::write("Info: lastPage=$lastPage\n");
          }
        }
        foreach ($graph->getClassResources('Dataset') as $dataset) {
          //echo 'dataset ='; print_r($dataset);
          $index[$dataset->asJsonLd()['@id']] = $page;
        }
      }
      file_put_contents(__DIR__.'/json/index.pser', serialize($index));
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
      //print_r(RdfExpResource::$pkeys);
      $graph->showInYaml('Catalog');
      break;
    }
    case 'datasets': { // import du registre et de l'export puis affichage des datasets
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      $graph->showInYaml('Dataset');
      break;
    }
    case 'frameDatasets': {
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      $graph->frame('Dataset', ['http://purl.org/dc/terms/publisher']);
      echo json_encode($graph->classAsJsonLd('Dataset'), JSON_OPTIONS);
      break;
    }
    case 'yamlldfc': { // affiche Yaml-ld framed (RdfExpGraph::frame()) et le contexte context.yaml puis compacté avec JsonLD
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      //print_r($graph);
      $graph->frame(Constant::FRAME_PARAM);
      $comped = new RdfCompactGraph(new RdfContext(Yaml::parseFile('context.yaml')), $graph->classAsJsonLd('Dataset'));
      //print_r($comped);
      echo Yaml::dump($comped->jsonld(Constant::PROP_IDS), 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK); // convertit en Yaml
      break;
    }
    case 'checkSchema': { // test de conformité au schéma JSON
      require_once __DIR__.'/../../schema/jsonschema.inc.php';
      
      $stats = new Stats;
      $errorNbres = []; // [{errorMd5} => {nbre}]
      $errorLabels = []; // [errorMd5 => ['label'=> {errorLabel}, 'nbre'=>{nbre}, 'uris'=> [{uri}]]]
      $warningNbres = []; // [{errorMd5} => {nbre}]
      $warningLabels = []; // [errorMd5 => ['label'=> {errorLabel}, 'nbre'=>{nbre}, 'uris'=> [{uri}]]]
      for ($page = $firstPage; ($lastPage == 0) || ($page <= $lastPage); $page++) {
        // chargement de la page dans $graph
        if (!is_file("json/export$page.json")) continue; // le fichier n'existe pas
        $graph = new RdfExpGraph('default');
        $registre = new Registre(__DIR__.'/registre.yaml');
        $registre->import($graph); // importe les ressources bien connues dans le graphe
        $content = file_get_contents("json/export$page.json");
        $content = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        foreach ($content as $no => $resource) {
          $stats->increment("nbre de ressources lues");
          $types = implode(', ', $resource['@type']); 
          if (!($className = (RdfExpGraph::CLASS_URI_TO_PHP_NAME[$types] ?? null)))
            throw new Exception("Types $types non traité");
          $resource = $graph->addResource($resource, $className);
          if (($className == 'PagedCollection') && ($lastPage == 0)) {
            $lastPage = $resource->lastPage();
            StdErr::write("Info: lastPage=$lastPage\n");
          }
        }
        $graph->rectifAllStatements();
        
        // Frame et Compact
        $graph->frame(Constant::FRAME_PARAM);
        $comped = new RdfCompactGraph(new RdfContext(Yaml::parseFile('context.yaml')), $graph->classAsJsonLd('Dataset'));
        //print_r($comped);
        $comped = $comped->jsonld(Constant::PROP_IDS); // formattage en JSON-LD
        //print_r($comped);
        
        // test de conformité des JdD de la page au schéma JSON
        $schema = new JsonSchema(__DIR__.'/dataset.schema.yaml');
        foreach ($comped['@graph'] as $dataset) {
          //echo $dataset['$id']," :\n";
          $status = $schema->check($dataset);
          //print_r($status->errors());
          foreach ($status->errors() as $error) {
            $md5 = md5(json_encode($error));
            $errorNbres[$md5] = 1 + ($errorNbres[$md5] ?? 0);
            $errorLabels[$md5]['label'] = $error;
            $errorLabels[$md5]['nbre'] = $errorNbres[$md5];
            $errorLabels[$md5]['uris'][] = $dataset['$id'];
          }
          foreach ($status->warnings() as $warning) {
            $md5 = md5(json_encode($warning));
            $warningNbres[$md5] = 1 + ($warningNbres[$md5] ?? 0);
            $warningLabels[$md5]['label'] = $warning;
            $warningLabels[$md5]['nbre'] = $warningNbres[$md5];
            $warningLabels[$md5]['uris'][] = $dataset['$id'];
          }
        }
      }
      if ($errorNbres) {
        arsort($errorNbres);
        foreach ($errorNbres as $errorMd5 => $nbre) {
          print_r($errorLabels[$errorMd5]);
        }
      }
      else {
        echo "No error\n";
        if ($warningNbres) {
          arsort($warningNbres);
          foreach ($warningNbres as $md5 => $nbre) {
            print_r($warningLabels[$md5]);
          }
        }
        else {
          echo "No warning\n";
        }
      }
      break;
    }
    case 'showOneDS': { // affichage d'une fiche définie par son URI
      if ($dsuri = $_GET['dsuri'] ?? '') {
        $index = unserialize(file_get_contents(__DIR__.'/json/index.pser'));
        //print_r($index);
        $page = $index[$dsuri] ?? 1;
      }
      echo "</pre><form>
        <input type='hidden' name='outputFormat' value='$_GET[outputFormat]' />
        <input type='hidden' name='page' value='$page' />
        <input type='text' name='dsuri' size='150' value='$dsuri' />
      </form><pre>\n";
      if (!$dsuri) break;
      //print_r($index);
      if (!($page = $index[$dsuri] ?? null)) {
        echo "URI $dsuri ne correspond pas à un Dataset\n";
        break;
      }
      $graph = new RdfExpGraph('default');
      $registre = new Registre(__DIR__.'/registre.yaml');
      $registre->import($graph); // importe les ressources bien connues dans le graphe
      if ($errors = $graph->import($urlPrefix, true, $page, $page)) {
        echo 'errors = '; print_r($errors);
        break;
      }
      $graph->frame(Constant::FRAME_PARAM);
      $comped = new RdfCompactGraph(new RdfContext(Yaml::parseFile('context.yaml')), $graph->classAsJsonLd('Dataset'));
      $comped = $comped->jsonld(Constant::PROP_IDS);
      $resource = null;
      foreach ($comped['@graph'] as $r) {
        if ($r['$id'] == $dsuri) {
          $resource = $r;
          break;
        }
      }
      if ($resource) {
        $scriptUrl = "$_SERVER[REQUEST_SCHEME]://$_SERVER[SERVER_NAME]$_SERVER[SCRIPT_NAME]";
        $resource = array_merge(['@context'=> "$scriptUrl?outputFormat=context.jsonld"], $resource);
        echo htmlspecialchars(Yaml::dump($resource, 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)); // convertit en Yaml
      }
      break;
    }
    
    default: {
      $graph->import($urlPrefix, true, $lastPage, $firstPage);
      if (in_array($argv[1], RdfExpGraph::CLASS_URI_TO_PHP_NAME)) {
        $graph->showInYaml($argv[1], true, true);
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
  $outputFormat = $_GET['outputFormat'] ?? 'yamlldfc';
  if (!in_array($outputFormat, ['context.jsonld'])) { // formulaire 
    echo "<html><head><title>exp $outputFormat</title></head><body>
      <form>
      <a href='?page=",$page-1,isset($_GET['outputFormat']) ? "&outputFormat=$_GET[outputFormat]" : '',"'>&lt;</a>
      Page 
      <!-- input type='hidden' name='page' value='$page' / -->
      <input type='text' name='page' size='3' value='$page' />
      <a href='?page=",$page+1,isset($_GET['outputFormat']) ? "&outputFormat=$_GET[outputFormat]" : '',"'>&gt;</a>
      <select name='outputFormat' id='outputFormat'>\n",
      Html::selectOptions($outputFormat, [
        'yamlldfc'=> "Yaml-LD imbriqué (framed) et compacté utilisant le contexte ci-dessous",
        'context.jsonld'=> "contexte en JSON-LD utilisé pour le Yaml-LD ci-dessus",
        'yaml'=> "Yaml simplifié spécifique NON conforme Yaml-LD",
        'jsonld'=> "JSON-LD corrigé",
        'print_r'=> "print_r(graph)",
        'turtle'=> "Turtle créé à partir du JSON-LD avec EasyRdf",
        'checkSchema'=> "Test de conformité du Yaml-LD au schema JSON",
        //'jsonLdContext'=> "contexte JSON-LD généré par le Registre - abandonné",
        'JsonLD::compact'=> "JSON-LD compacté avec JsonLD::compact() - abandonné",
        //'JsonLD::frame'=> "JSON-LD imbriqué avec JsonLD::frame(), ne fonctionne pas correctement",
        //'flatten'=> "flatten(expand(Yaml-ld framed et compacté))",
        //'boucle'=> "boucle cad flatten(expand(yamlldfc)) == JSON-LD ?",
        'showOneDS'=> "showOneDS", // affichage d'une fiche définie par son URI
      ]),
      "      </select>
      <input type='submit' value='Submit' /></form><pre>\n";
  }
  
  $graph = new RdfExpGraph('default');
  $registre = new Registre(__DIR__.'/registre.yaml');
  $registre->import($graph); // importe les ressources bien connues dans le graphe
  if ($errors = $graph->import($urlPrefix, true, $page, $page)) {
    echo 'errors = '; print_r($errors);
    echo "---\n";
  }
  switch ($outputFormat) {
    case 'yaml': { // affichage simplifié des datasets en Yaml 
      if (0) { // Test de update, ne fonctionne que sur une page particulière 
        $graph->update('Dataset', 'http://catalogue.geo-ide.developpement-durable.gouv.fr/catalogue/srv/fre/catalog.search#/metadata/fr-120066022-jdd-fd487b54-55e6-4a8c-a095-1748206dc329', 'http://purl.org/dc/terms/title', 0, "Titre modifié");
        
      }
      $output = $graph->showInYaml('Dataset', false);
      if (StdErr::$messages) {
        echo 'StdErr::$messages = '; print_r(StdErr::$messages);
        echo "---\n";
      }
      echo htmlspecialchars($output); // affichage Yaml
      break;
    }
    case 'jsonld': { // affiche le JSON-LD rectifié généré par $graph 
      echo htmlspecialchars(json_encode($graph->allAsJsonLd(), JSON_OPTIONS));
      break;
    }
    case 'print_r': { // affichage du graphe avec print_r()
      print_r($graph);
      break;
    }
    case 'turtle': { // traduction en Turtle avec EasyRdf
      $erGraph = new \EasyRdf\Graph('https://preprod.data.developpement-durable.gouv.fr/');
      $erGraph->parse(json_encode($graph->allAsJsonLd()), 'jsonld', 'https://preprod.data.developpement-durable.gouv.fr/');
      echo htmlspecialchars($erGraph->serialise('turtle'));
      break;
    }
    /*case 'jsonLdContext': { // affiche le JSON-LD contexte généré par le registre 
      echo htmlspecialchars(json_encode(Registre::jsonLdContext(), JSON_OPTIONS));
      break;
    }*/
    case 'JsonLD::compact': { // affiche le JSON-LD compacté avec JsonLD et le contexte déduit du registre
      if (!is_dir('tmp')) mkdir('tmp');
      file_put_contents('tmp/document.jsonld', json_encode($graph->allAsJsonLd(), JSON_OPTIONS));
      file_put_contents('tmp/context.jsonld', json_encode(Registre::jsonLdContext(), JSON_OPTIONS));
      $compacted = JsonLD::compact('tmp/document.jsonld', 'tmp/context.jsonld');
      echo htmlspecialchars(json_encode($compacted, JSON_OPTIONS));
      break;
    }
    /*case 'JsonLD::frame': { // affiche le JSON-LD imbriqué (framed) avec JsonLD - ne fonctionne pas correctement
      if (!is_dir('tmp')) mkdir('tmp');
      file_put_contents('tmp/document.jsonld', json_encode($graph->allAsJsonLd(), JSON_OPTIONS));
      file_put_contents('tmp/frame.jsonld', json_encode(Registre::jsonLdFrame(), JSON_OPTIONS));
      $framed = JsonLD::frame('tmp/document.jsonld', 'tmp/frame.jsonld');
      echo htmlspecialchars(json_encode($framed, JSON_OPTIONS));
      break;
    }*/
    case 'context.jsonld': { // context adhoc en JSON-LD
      header('Content-type: application/json');
      die(json_encode(Yaml::parseFile('context.yaml'), JSON_OPTIONS));
    }
    case 'yamlldfc': { // affiche Yaml-ld framed (RdfExpGraph::frame()) et le contexte context.yaml puis compacté avec JsonLD
      //print_r($graph);
      if ($errors) break;
      $graph->frame(Constant::FRAME_PARAM);
      $comped = new RdfCompactGraph(new RdfContext(Yaml::parseFile('context.yaml')), $graph->classAsJsonLd('Dataset'));
      //print_r($comped);
      $comped = $comped->jsonld(Constant::PROP_IDS); // formattage en JSON-LD
      //print_r($comped);
      //print_r($_SERVER);
      $scriptUrl = "$_SERVER[REQUEST_SCHEME]://$_SERVER[SERVER_NAME]$_SERVER[SCRIPT_NAME]";
      $comped['@context'] = "$scriptUrl?outputFormat=context.jsonld";
      echo htmlspecialchars(Yaml::dump($comped, 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)); // convertit en Yaml
      break;
    }
    /*case 'flatten': { // flatten(expand(Yaml-ld framed et compacté)) - ne semble pas fonctionner
      //print_r($graph);
      $graph->frame(Constant::FRAME_PARAM);
      $comped = new RdfCompactGraph(new RdfContext(Yaml::parseFile('context.yaml')), $graph->classAsJsonLd('Dataset'));
      //print_r($comped);
      $yaml = Yaml::dump($comped->jsonld(Constant::PROP_IDS), 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK); // convertit en Yaml
      $comped = Yaml::parse($yaml);
      file_put_contents('tmp/compacted.jsonld', json_encode($comped));
      $expanded = JsonLD::expand('tmp/compacted.jsonld');
      file_put_contents('tmp/expanded.jsonld', json_encode($expanded));
      $flattened = JsonLD::flatten('tmp/expanded.jsonld');
      $flattened = json_decode(json_encode($flattened), true);
      $flattened = new RdfExpGraph('flattened', $flattened);
      echo htmlspecialchars(json_encode($flattened->allAsJsonLd(), JSON_OPTIONS));
      break;
    }*/
    /*case 'boucle': { // boucle cad flatten(expand(yamlldfc)) == JSON-LD ? - ne fonctionne pas
      //print_r($graph);
      $graph->frame(Constant::FRAME_PARAM);
      $comped = new RdfCompactGraph(new RdfContext(Yaml::parseFile('context.yaml')), $graph->classAsJsonLd('Dataset'));
      //print_r($comped);
      $yaml = Yaml::dump($comped->jsonld(Constant::PROP_IDS), 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK); // convertit en Yaml
      $comped = Yaml::parse($yaml);
      file_put_contents('tmp/compacted.jsonld', json_encode($comped));
      $expanded = JsonLD::expand('tmp/compacted.jsonld');
      file_put_contents('tmp/expanded.jsonld', json_encode($expanded));
      $flattened = JsonLD::flatten('tmp/expanded.jsonld');
      $flattened = json_decode(json_encode($flattened), true);
      $flattened = new RdfExpGraph('flattened', $flattened);
      // réimport du graphe initial qui a été modifié
      $graph = new RdfExpGraph('default');
      Registre::import($graph);
      $graph->import($urlPrefix, true, $page, $page);
      if (0) {
        $graph->update(
          'Dataset', 'https://preprod.data.developpement-durable.gouv.fr/dataset/only',
          'http://purl.org/dc/terms/title', 0, "Titre modifié");
      }
      $flattened->includedIn($graph);
      break;
    }*/
    case 'checkSchema': { // test de conformité à un schéma JSON
      require_once __DIR__.'/../../schema/jsonschema.inc.php';
      if ($errors) break;
      $graph->frame(Constant::FRAME_PARAM);
      $comped = new RdfCompactGraph(new RdfContext(Yaml::parseFile('context.yaml')), $graph->classAsJsonLd('Dataset'));
      //print_r($comped);
      $comped = $comped->jsonld(Constant::PROP_IDS); // formattage en JSON-LD
      //print_r($comped);
      $errorNbres = []; // [{errorMd5} => {nbre}]
      $errorLabels = []; // [errorMd5 => ['label'=> {errorLabel}, 'nbre'=>{nbre}, 'uris'=> [{uri}]]]
      $schema = new JsonSchema(__DIR__.'/dataset.schema.yaml');
      foreach ($comped['@graph'] as $dataset) {
        //echo $dataset['$id']," :\n";
        $status = $schema->check($dataset);
        //print_r($status->errors());
        foreach ($status->errors() as $error) {
          $md5 = md5(json_encode($error));
          $errorNbres[$md5] = 1 + ($errorNbres[$md5] ?? 0);
          $errorLabels[$md5]['label'] = $error;
          $errorLabels[$md5]['nbre'] = $errorNbres[$md5];
          $errorLabels[$md5]['uris'][] = $dataset['$id'];
        }
        //print_r($errorNbres);
        //print_r($errorLabels);
      }
      arsort($errorNbres);
      foreach ($errorNbres as $errorMd5 => $nbre) {
        print_r($errorLabels[$errorMd5]);
      }
      break;
    }
    case 'showOneDS': { // affichage d'une fiche définie par son URI
      if ($dsuri = $_GET['dsuri'] ?? '') {
        $index = unserialize(file_get_contents(__DIR__.'/json/index.pser'));
        //print_r($index);
        $page = $index[$dsuri] ?? 1;
      }
      echo "</pre><form>
        <input type='hidden' name='outputFormat' value='$_GET[outputFormat]' />
        <input type='hidden' name='page' value='$page' />
        <input type='text' name='dsuri' size='150' value='$dsuri' />
      </form><pre>\n";
      if (!$dsuri) break;
      //print_r($index);
      if (!($page = $index[$dsuri] ?? null)) {
        echo "URI $dsuri ne correspond pas à un Dataset\n";
        break;
      }
      $graph = new RdfExpGraph('default');
      $registre = new Registre(__DIR__.'/registre.yaml');
      $registre->import($graph); // importe les ressources bien connues dans le graphe
      if ($errors = $graph->import($urlPrefix, true, $page, $page)) {
        echo 'errors = '; print_r($errors);
        break;
      }
      $graph->frame(Constant::FRAME_PARAM);
      $comped = new RdfCompactGraph(new RdfContext(Yaml::parseFile('context.yaml')), $graph->classAsJsonLd('Dataset'));
      $comped = $comped->jsonld(Constant::PROP_IDS);
      $resource = null;
      foreach ($comped['@graph'] as $r) {
        if ($r['$id'] == $dsuri) {
          $resource = $r;
          break;
        }
      }
      if ($resource) {
        $scriptUrl = "$_SERVER[REQUEST_SCHEME]://$_SERVER[SERVER_NAME]$_SERVER[SCRIPT_NAME]";
        $resource = array_merge(['@context'=> "$scriptUrl?outputFormat=context.jsonld"], $resource);
        echo htmlspecialchars(Yaml::dump($resource, 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)); // convertit en Yaml
      }
      break;
    }
    
    default: {
      throw new Exception("Format $outputFormat inconnu");
    }
  }
  die("</pre>\n");
}
