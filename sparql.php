<?php
// sparql.php - interrogation du triplestore en SparQl

require_once __DIR__.'/http.inc.php';

$query = $_POST['query'] ?? '';
{ // formulaire 
  echo "<html><head><title>sparql</title></head><body>
    <form method='POST'><table>
    <tr><td><textarea name='query' rows='30' cols='120'>",htmlspecialchars($query),"</textarea></td></tr>
    <tr><td><input type='submit' value='Submit' /></td></tr>
    </table></form><pre>\n";
}
if ($query) {
  // interroge le serveur en Sparql et retourne le résultat en CSV, TSV ou JSON
  $fusekiUrl = 'http://172.19.3:3030';
  //$accept = 'application/sparql-results+json';
  $accept = 'text/tab-separated-values; charset=utf-8';
  $result = http::request("$fusekiUrl/catalog/sparql?query=".urlencode($query), ['Accept'=> $accept]);
  echo htmlspecialchars($result['body']);
  echo "--\n",'headers='; print_r($result['headers']);
}
