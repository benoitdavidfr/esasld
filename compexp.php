<?php
/* compexp.php - Compact or Expand
*/
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
use ML\JsonLD\JsonLD;

define('JSON_OPTIONS',
        JSON_PRETTY_PRINT|JSON_UNESCAPED_LINE_TERMINATORS|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

$context = $_POST['context'] ?? "{}";
$exped = $_POST['exped'] ?? "{}";
$comped = $_POST['comped'] ?? "{}";
$choice = $_POST['choice'] ?? '';

if ($choice == 'compact') {
  file_put_contents('tmp/context.jsonld', json_encode(Yaml::parse($context)));
  file_put_contents('tmp/expanded.jsonld', json_encode(Yaml::parse($exped)));
  try {
    $comped = JsonLD::compact('tmp/expanded.jsonld', 'tmp/context.jsonld');
    //unset($comped->{'@context'});
    $comped = json_decode(json_encode($comped, JSON_OPTIONS), true); // suppr. StdClass
    $comped = Yaml::dump($comped, 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK); // convertit en Yaml
    //print_r($comped);
    // convertit $context de JSON en YAML
    //$context = Yaml::dump(json_decode($context, true), 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
  } catch (ML\JsonLD\Exception\JsonLdException $e) {
    echo $e->getMessage();
    $comped = '';
  }
}
elseif ($choice == 'expand') {
  file_put_contents('tmp/compacted.jsonld', $comped);
  try {
    $exped = JsonLD::expand('tmp/compacted.jsonld');
    $exped = json_encode($exped, JSON_OPTIONS);
  } catch (ML\JsonLD\Exception\JsonLdException $e) {
    echo $e->getMessage();
    $exped = '';
  }
}

echo "<html><head><title>context</title></head><body>\n";
//echo "<h2>calculette sur les heures et minutes</h2>\n";
echo "<table><form method='post'>"; // table 1
echo "<tr><td><table>"; // table 2
echo "<tr><td><textarea name='context' rows='20' cols='80'>",htmlspecialchars($context),"</textarea></td></tr>\n";
echo "<tr><td><textarea name='exped' rows='20' cols='80'>",htmlspecialchars($exped),"</textarea></td></tr>\n";
echo "</table></td>\n"; // fin table 2
echo "<td><textarea name='comped' rows='40' cols='80'>",htmlspecialchars($comped),"</textarea></td></tr>\n";
echo "<tr><td><center><select name='choice'>
  <option value='compact'",($choice=='compact'?'selected':''),">Compact</option>
  <option value='expand'",($choice=='expand'?'selected':''),">Expand</option>
</select></center></td>\n";
echo "<td><center><input type='submit'></center></td></tr>\n";
echo "</form></table>\n"; // fin table 1

