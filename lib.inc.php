<?php

class Stats { // classe utilisée pour mémoriser des stats sous la forme [{label} => {nbre d'occurences}]
  protected array $contents=[]; // [{label}=> {nbre}]
  function __construct(array $contents=[]) { $this->contents = $contents; }
  
  function increment(string $label): void { // incrémente une des sous-variables
    $this->contents[$label] = 1 + ($this->contents[$label] ?? 0);
  }
  
  function contents(): array { return $this->contents; }
};

class CallContext { // définit le contexte d'appel pour une comparaison entre 2 éléments 
  public readonly array $p1; // contexte du premier paramètre sous la forme [{var} => {val}]
  public readonly array $p2; // contexte du second  paramètre sous la forme [{var} => {val}]
  public readonly array $common; // contexte commun sous la forme [{var} => {val}]
  public readonly ?CallContext $previous; // contexte précédent
  
  function __construct(array $p1, array $p2, array $common=[], ?CallContext $previous=null) {
    $this->p1=$p1;
    $this->p2=$p2;
    $this->common = $common;
    $this->previous = $previous;
  }
  
  function asArray(): array {
    return [
      'p1'=> $this->p1,
      'p2'=> $this->p2,
      'common'=> $this->common,
      'previous'=> $this->previous ? $this->previous->asArray() : [],
    ];
  }
  
  function __toString(): string { return json_encode($this->asArray(), JSON_OPTIONS); }
};
