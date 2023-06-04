<?php
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

// extrait le code HTTP de retour de l'en-tête HTTP
function httpResponseCode(array $header) { return substr($header[0], 9, 3); }

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

class MLString { // chaine de caractères multilingue
  protected array $langstr=[]; // [{lang} => {str}] avec au moins français rempli
  
  function __construct(array $label) { // $label : [{lang} => {str}]
    //echo '$label = '; print_r($label);
    foreach ($label as $lang => $string) {
      //echo "lang=$lang, string=$string\n";
      if ($string)
        $this->langstr[$lang] = $string;
    }
    if (!isset($this->langstr['fr']))
      throw new Exception("Erreur dans MLString::__construct()");
  }
  
  // initialise un MLString à partir d'une propriété label d'un Statement
  static function fromStatementLabel(array $label): self {
    $langstr = []; // [{lang} => {str}]
    foreach ($label as $pval) {
      if ($pval->keys() == ['@language','@value']) {
        $langstr[$pval->language] = $pval->value;
      }
      else {
        echo '$label = '; print_r($label);
        throw new Exception("Erreur dans MLString::fromStatementLabel()");
      }
    }
    if (!isset($langstr['fr'])) {
      echo '$label = '; print_r($label);
      throw new Exception("Erreur dans MLString::fromStatementLabel()");
    }
    return new self($langstr);
  }
  
  function md5(): string { return md5($this->langstr['fr']); } // calcule le MD5 sur la chaine française
  
  function toStatementLabel(): array { // génère la liste de valeurs JSON-LD correspondant au MLString
    $label = [];
    foreach ($this->langstr as $lang => $str) {
      $label[] = [
        '@language' => $lang,
        '@value' => $str,
      ];
    }
    return $label;
  }
};

