<?php
/* statemen.inc.php - déf. des classes RightsStatement et MLString - 12/5/2023
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
  
  // initialise un MLString à partir d'une propriété label d'un RightsStatement
  static function fromRightsStatementLabel(array $label): self {
    $langstr = []; // [{lang} => {str}]
    foreach ($label as $pval) {
      if (isset($pval['@language']) && isset($pval['@value'])) {
        $langstr[$pval['@language']] = $pval['@value'];
      }
      else {
        echo '$label = '; print_r($label);
        throw new Exception("Erreur dans MLString::fromRightsStatementLabel()");
      }
    }
    if (!isset($langstr['fr'])) {
      echo '$label = '; print_r($label);
      throw new Exception("Erreur dans MLString::fromRightsStatementLabel()");
    }
    return new self($langstr);
  }
  
  function md5(): string { return md5($this->langstr['fr']); } // calcule le MD5 sur la chaine française
  
  function toRightsStatementLabel(): array {
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

class RightsStatement extends RdfClass { // Correspond à une ressource RightsStatement
  // propriétés définies sur RightsStatement
  const PROP_KEY_URI = [
    'http://www.w3.org/2000/01/rdf-schema#label' => 'label',
  ];
  // RightsStatement définis par un URI
  const REGISTRE = [
    'http://inspire.ec.europa.eu/metadata-codelist/LimitationsOnPublicAccess/noLimitations' => [
      'en' => "no limitations to public access", // source registre Inspire
      'fr' => "Pas de restriction d’accès public selon INSPIRE", // chaine souvent utilisée
    ],
  ];

  static array $all; // stocke les ressources RightsStatement [{id} => RightsStatement]
  
  function label(): array { // retourne la propriété label comme [[{key} => {val}]]
    return $this->props['http://www.w3.org/2000/01/rdf-schema#label'];
  }

  // corrige si nécessaire une liste de valeurs correspondant à une propriété accessRights
  static function rectifAccessRights(array $pvals): array {
    $arrayOfMLStrings = []; // [{md5} => ['mlStr'=> MLString, 'bn'=>{bn}]] - liste de chaines correspondant au $pvals
    
    //echo 'accessRights (input) = '; var_dump($pvals);
    
    foreach ($pvals as $pval) {
      if (isset($pval['@value'])) { // défini comme liste de labels
        try {
          $elts = Yaml::parse($pval['@value']);
        } catch (ParseException $e) {
          //var_dump($pval['@value']);
          //throw new Exception("Erreur de Yaml::parse() dans RightsStatements::rectification()");
          $elts = [['label'=> ['fr'=> "ERREUR DE DECODAGE YAML SUR accessRights"]]];
        }
        //echo '$labels = '; print_r($labels);
        foreach ($elts as $elt) {
          if (isset($elt['uri'])) { // l'URI est défini
            if (!isset(self::REGISTRE[$elt['uri']])) {
              echo "URI '$elt[uri]' absent du registre RightsStatements::REGISTRE\n";
              throw new Exception("URI '$elt[uri]' absent du registre RightsStatements::REGISTRE");
            }
            $mlStr = new MLString(self::REGISTRE[$elt['uri']]);
          }
          elseif (isset($elt['label'])) {
            $mlStr = new MLString($elt['label']);
          }
          else {
            echo '$elts = '; print_r($elts);
            throw new Eception("ERREUR");
          }
          if (!isset($arrayOfMLStrings[$mlStr->md5()]))
            $arrayOfMLStrings[$mlStr->md5()] = ['mlStr'=> $mlStr];
        }
      }
      elseif (isset($pval['@id'])) { // défini comme blank node vers un RightsStatement
        $rightsStatement = RightsStatement::get($pval['@id']);
        //echo '$rightsStatement = '; print_r($rightsStatement);
        $mlStr = MLString::fromRightsStatementLabel($rightsStatement->label());
        $arrayOfMLStrings[$mlStr->md5()] = ['mlStr'=> $mlStr, 'bn'=>$pval['@id']];
      }
    }
    
    //echo '$arrayOfMLStrings = '; print_r($arrayOfMLStrings);
    
    $pvals = [];
    foreach ($arrayOfMLStrings as $md5 => $mlStrAndBn) {
      if (isset($mlStrAndBn['bn']))
        $pvals[] = ['@id'=> $mlStrAndBn['bn']];
      else {
        $id = '_:md5-'.$md5; // définition d'un id de BN à partir du MD5
        $resource = [
          '@id'=> $id,
          '@type'=> ['http://purl.org/dc/terms/RightsStatement'],
          'http://www.w3.org/2000/01/rdf-schema#label'=> $mlStrAndBn['mlStr']->toRightsStatementLabel(),
        ];
        RightsStatement::$all[$id] = new RightsStatement($resource);
        $pvals[] = ['@id'=> $id];
      }
    }
    //echo 'accessRights (rectified) = '; print_r($pvals);
    return $pvals;
  }
};
