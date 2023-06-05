<?php
{/*PhpDoc:
title: rdfcomp.inc.php - gestion d'un graphe compacté, cad défini par rapport à un contexte - 2/6/2023
doc: |
  Cette forme est plus compliquée à gérer que les graphes épandus dont la structure est plus régulière
  Le code de ce fichier permet principalement:
   1) de créer le graphe compacté à partir du graphe épandu en utilisant JsonLD::compact() puis en stockant le résultat
   2) de gérer le tri des propriétés pour l'affichage.
  
  Il doit être possible de repasser à un graphe épandu en utilisant JsonLD::expand() 
  
  Les graphes compactés sont gérés par la classe RdfCompactGraph, le contexte est défini par RdfContext
  Les ressources sont gérées au moyen de la classe RdfCompactResource ;
  Les valeurs de propriété sont gérées par la classe abstraite RdfCompactElt dont les sous classes concrètes sont:
   - RdfCompactRefRes pour les références à des ressources,
   - RdfCompactLiteral pour les littéraux et
   - RdfCompactList pour les listes de valeurs.
journal: |
 5/6/2023:
  - ajout des constantes ID et TYPE pour s'ajuster au contexte et vérif. de la cohérence de ces déf. avec le contexte
 2/6/2023:
  - scission de rdf.inc.php
*/}
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
use ML\JsonLD\JsonLD;

// Les 2 constantes ID et TYPE doivent être définis en accord avec le contexte ; une vérif. est effectuée à l'init. du contexte
//define ('ID', '@id');
define ('ID', '$id'); // à définir ssi le contexte remplace '@id' par '$id'
//define ('TYPE', '@type');
define ('TYPE', 'isA'); // à définir ssi le contexte replace '@type' par 'isA'

class RdfContext { // Contexte JSON-LD 
  protected array $content;
  
  function __construct(array $content) {
    $this->content = $content;
    
    // vérif. des valeurs des constantes ID et TYPE
    $newIdLabel = '@id';
    foreach ($content as $key => $value) {
      if ($value == '@id') {
        $newIdLabel = $key;
        break;
      }
    }
    //echo "newIdLabel=$newIdLabel\n";
    if (ID <> $newIdLabel)
      throw new Exception("Erreur, ID devrait être '$newIdLabel'");
    
    $newTypeLabel = '@type';
    foreach ($content as $key => $value) {
      if ($value == '@type') {
        $newTypeLabel = $key;
        break;
      }
    }
    //echo "newTypeLabel=$newTypeLabel\n";
    if (TYPE <> $newTypeLabel)
      throw new Exception("Erreur, TYPE devrait être '$newTypeLabel'");
  }
  
  function content(): array { return $this->content; }
};

abstract class RdfCompactElt { // Une ressource, une référence ou un littéral dans le cas compact ou une liste 
  static function create($value): self {
    if (is_array($value) && array_is_list($value)) {
      return new RdfCompactList($value);
    }
    elseif (is_array($value) && isset($value[TYPE]))
      return new RdfCompactResource($value);
    elseif (is_array($value) && isset($value[ID]))
      return new RdfCompactRefRes($value);
    else
      return new RdfCompactLiteral($value);
  }
  
  // génère une structure Php structurée selon JSON-LD en triant les propriétés
  // propIds: [string|[string: [string]]], 2e cas appel récursif
  abstract function jsonld(array $propIds); 
};

class RdfCompactRefRes extends RdfCompactElt { // référence vers Ressource 
  protected string $id; // la référence
  
  function __construct(array $val) { $this->id = $val[ID]; }
  
  function jsonld(array $propIds): array { return [ID=> $this->id]; }
};

class RdfCompactLiteral extends RdfCompactElt {
  protected $value;
  
  function __construct($value) { $this->value = $value; }

  function jsonld(array $propIds) { return $this->value; }
};

class RdfCompactList extends RdfCompactElt {
  protected array $list=[];
  
  function __construct(array $list) {
    foreach ($list as $elt) {
      $this->list[] = self::create($elt);
    }
  }
  
  function jsonld(array $propIds): array {
    $list = [];
    foreach ($this->list as $elt)
      $list[] = $elt->jsonld($propIds);
    return $list;
  }
};

class RdfCompactResource extends RdfCompactElt {
  protected ?string $id=null; // le champ ID de la repr. JSON-LD, cad l'URI de la ressource, null si blank node
  protected string|array $type; // le champ TYPE de la repr. JSON-LD
  protected array $props=[]; // dict. des propriétés de la ressource de la forme [{propId} => RdfCompactElt]
  
  function __construct(array $resource) {
    foreach ($resource as $propId => $value) {
      switch ($propId) {
        case ID: { $this->id = $value; break; }
        case TYPE: { $this->type = $value; break; }
        default: { $this->props[$propId] = RdfCompactElt::create($value); }
      }
    }
    //print_r($this);
  }
   
  // génère une structure Php structurée selon JSON-LD en triant les propriétés
  // propIds: [string|[string: [string]]], 2e cas appel récursif
  function jsonld(array $propIds): array { 
    $jsonld = [];
    $pIds = []; // liste des propId comme liste de chaines
    if ($this->id)
      $jsonld[ID] = $this->id;
    $jsonld[TYPE] = $this->type;
    foreach ($propIds as $key => $propId) {
      //echo 'propId='; print_r($propId); echo "\n";
      if (is_string($propId)) { 
        if (isset($this->props[$propId])) {
          $jsonld[$propId] = $this->props[$propId]->jsonld([]);
          $pIds[] = $propId;
        }
      }
      else {
        if (isset($this->props[$key])) {
          $jsonld[$key] = $this->props[$key]->jsonld($propId);
          $pIds[] = $key;
        }
      }
    }
    foreach ($this->props as $propId => $value) {
      if (!in_array($propId, $pIds)) {
        $jsonld[$propId] = $value->jsonld([]);
      }
    }
    return $jsonld;
  }
};

class RdfCompactGraph { // Graphe compacté, cad paramétré par un contexte
  protected array $resources=[]; // [{resid}=> {Resource}]
  protected RdfContext $context;

  function __construct(RdfContext $context, array $jsonld) { // Compacte un extrait de graphe par rapport à un contexte 
    $this->context = $context;
    if (!is_dir('tmp')) mkdir('tmp');
    file_put_contents('tmp/context.jsonld', json_encode($context->content()));
    file_put_contents('tmp/expanded.jsonld', json_encode($jsonld));
    try {
      $comped = JsonLD::compact('tmp/expanded.jsonld', 'tmp/context.jsonld');
      //unset($comped->{'@context'});
      $comped = json_decode(json_encode($comped, JSON_OPTIONS), true); // suppr. StdClass
    } catch (ML\JsonLD\Exception\JsonLdException $e) {
      throw new Exception($e->getMessage());
    }
    //echo '$comped='; print_r($comped);
    if (!isset($comped['@graph'])) { // une seule ressources 
      unset($comped['@context']);
      $this->resources[$comped[ID]] = RdfCompactElt::create($comped);
    }
    else { // plusieurs ressources 
      foreach ($comped['@graph'] as $resource) {
        $this->resources[$resource[ID]] = RdfCompactElt::create($resource);
        //echo "1stResource="; print_r($this->resources); die("FIN");
      }
    }
  }
  
  function jsonld(array $propIds): array { // génère une structure Php structurée selon JSON-LD en triant les propriétés 
    $jsonld = [
      '@context'=> $this->context->content(),
      '@graph'=> [],
    ];
    foreach ($this->resources as $res) {
      $e = $res->jsonld($propIds);
      //echo '$res='; print_r($res);
      //echo Yaml::dump($e);
      $jsonld['@graph'][] = $e;
     // die("FIN");
    }
    return $jsonld;
  }
};
