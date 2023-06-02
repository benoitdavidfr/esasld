<?php
{/*PhpDoc:
title: rdfcomp.inc.php - classes utilisées par exp.php pour gérer les données RDF - 2/6/2023
doc: |
  Gestion d'un grahe compacté, cad défini par rapport à un contexte.
  Cette forme est plus compliquée à gérer que les graphes épandus dont la structure est plus régulière
  Le code de ce fichier permet principalement:
   1) de créer le graphe compacté en utilisant JsonLD::compact() en stockant les ressources résultantes
   2) de gérer le tri des propriétés pour l'affichage.
  
  Le graphe est un objet de RdfCompactGraph, le contexte est défini par RdfContext
  Les ressources sont gérées au moyen de la classe RdfCompactResource et des sous-classes de RdfCompactElt
  (RdfCompactRefRes, RdfCompactLiteral et RdfCompactList)
journal: |
 2/6/2023:
  - scission de rdf.inc.php
*/}
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
use ML\JsonLD\JsonLD;

class RdfContext { // Contexte JSON-LD 
  protected array $content;
  
  function __construct(array $content) { $this->content = $content; }
  
  function content(): array { return $this->content; }
};

abstract class RdfCompactElt { // Une ressource, une référence ou un littéral dans le cas compact ou une liste 
  static function create($value): self {
    if (is_array($value) && array_is_list($value)) {
      return new RdfCompactList($value);
    }
    elseif (is_array($value) && isset($value['@type']))
      return new RdfCompactResource($value);
    elseif (is_array($value) && isset($value['@id']))
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
  
  function __construct(array $val) { $this->id = $val['@id']; }
  
  function jsonld(array $propIds): array { return ['@id'=> $this->id]; }
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
  protected ?string $id=null; // le champ '@id' de la repr. JSON-LD, cad l'URI de la ressource, null si blank node
  protected string|array $type; // le champ '@type' de la repr. JSON-LD
  protected array $props=[]; // dict. des propriétés de la ressource de la forme [{propId} => RdfCompactElt]
  
  function __construct(array $resource) {
    foreach ($resource as $propId => $value) {
      switch ($propId) {
        case '@id': { $this->id = $value; break; }
        case '@type': { $this->type = $value; break; }
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
      $jsonld['@id'] = $this->id;
    $jsonld['@type'] = $this->type;
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
      $this->resources[$comped['@id']] = RdfCompactElt::create($comped);
    }
    else { // plusieurs ressources 
      foreach ($comped['@graph'] as $resource) {
        $this->resources[$resource['@id']] = RdfCompactElt::create($resource);
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
