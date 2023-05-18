<?php
/* statemen.inc.php - déf. des classes RightsStatement et MLString - 17/5/2023
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
      throw new Exception("Erreur dans MLString::fromRightsStatementLabel()");
    }
    return new self($langstr);
  }
  
  function md5(): string { return md5($this->langstr['fr']); } // calcule le MD5 sur la chaine française
  
  function toStatementLabel(): array {
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

class RightsStatement extends Statement { // Correspond à une ressource RightsStatement
  // propriétés définies sur RightsStatement
  const PROP_KEY_URI = [
    'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
  ];

  static array $all=[]; // stocke les ressources RightsStatement [{id} => RightsStatement]
};

class ProvenanceStatement extends Statement {
  const PROP_KEY_URI = [
    'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
  ];

  static array $all=[];
};

// classe portant la méthode statique rectifStatements()
class Statement extends RdfClass {

  // corrige si nécessaire une liste de valeurs correspondant à une propriété accessRights ou provenance
  static function rectifStatements(array $pvals, string $statementClass): array {
    $arrayOfMLStrings = []; // [{md5} => ['mlStr'=> MLString, 'bn'=>{bn}]] - liste de chaines correspondant au $pvals
    
    foreach ($pvals as $pval) {
      switch ($pval->keys) {
        case ['@language','@value'] : {
          self::increment('rectifStats', "propriété contenant un Littéral alors qu'elle exige une Resource");
          if ($pval->language == 'fr') {
            $md5 = md5($pval->value);
            if (!isset($arrayOfMLStrings[$md5]))
              $arrayOfMLStrings[$md5] = ['mlStr'=> new MLString(['fr'=> $pval->value])];
          }
          else {
            throw new Exception("Langue ".$pval->language." non traitée");
          }
          break;
        }
        case ['@id'] : {
          $statement = $statementClass::get($pval->id);
          $mlStr = MLString::fromStatementLabel($statement->label());
          $arrayOfMLStrings[$mlStr->md5()] = ['mlStr'=> $mlStr, 'bn'=>$pval->id];
          break;
        }
        default: {
          throw new Exception("Keys ".implode(',', $pval->keys)." non traité");
        }
      }
    }
    
    $pvals = [];
    foreach ($arrayOfMLStrings as $md5 => $mlStrAndBn) {
      if (isset($mlStrAndBn['bn']))
        $pvals[] = new PropVal(['@id'=> $mlStrAndBn['bn']]);
      else {
        $id = '_:md5-'.$md5; // définition d'un id de BN à partir du MD5
        $resource = [
          '@id'=> $id,
          '@type'=> ["http://purl.org/dc/terms/$statementClass"],
          'http://www.w3.org/2000/01/rdf-schema#label'=> $mlStrAndBn['mlStr']->toStatementLabel(),
        ];
        $statementClass::$all[$id] = new $statementClass($resource);
        $pvals[] = new PropVal(['@id'=> $id]);
      }
    }
    return $pvals;
  }
  
  function label(): array { // retourne la propriété label comme [PropVal]
    return $this->props['http://www.w3.org/2000/01/rdf-schema#label'];
  }
};