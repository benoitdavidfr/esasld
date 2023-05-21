<?php
/* statem.inc.php - déf. de la classes MLString - 21/5/2023
*/
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

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
      if ($pval->keys == ['@language','@value']) {
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

