<html><head><meta charset='utf-8'><title>esasld</title></head><body>
<h2>Ecosphères en données liées (esasld)</h2>

Le premier objectif de ce projet est de lire l'export DCAT d'Ecosphères en JSON-LD afin d'y détecter d'éventuelles erreurs.<br>
Le résultat est formalisé ci-dessous qui est le résultat de la commande rectifStats et qui est la liste des erreurs
rencontrées et corrigées.
<pre>
<?php echo file_get_contents('rectifStats.txt'); ?>
</pre>

Le second objectif est de corriger ces erreurs et d'afficher le catalogue de manière plus compréhensible.<br>
Le résultat est un affichage simplifié des JdD du catalogue fondé sur le format Yaml-LD imbriqué (framed)
et compacté avec un contexte défini dans <a href='exp.php?outputFormat=context.jsonld'>contexte</a>.<br>
Les pages de l'export DCAT restructurés en Yaml-LD sont disponibles <a href='exp.php'>ici</a>.
</p>

Le troisième objectif est de me familiariser et de tester:<ul>
  <li>EasyRdf - https://www.easyrdf.org/ - A PHP library designed to make it easy to consume and produce RDF.</li>
  <li>JsonLD - https://github.com/lanthaler/JsonLD - A fully conforming JSON-LD (1.0) processor written in PHP.</li>
</ul>
Sur EasyRdf, j'ai identifié différents bugs et la seule fonctionnalité utile est la conversion de JSON-LD en Turtle
<a href='exp.php?outputFormat=turtle'>utilisée ici</a>.</p>
Sur JsonLD:<ul>
  <li>JsonLD::compact() fonctionne bien</li>
  <li>JsonLD::frame() ne fonctionne pas,
  c'est peut-être du au fait que j'ai utilisé JSON-LD 1.1 alors que JsonLD implémente JSON-LD 1.0</li>
  <li>JsonLD::flatten() ne semble pas fonctionner, il utilise des blank nodes qu'il ne définit pas</li>
  <li>JsonLD::expand() semble fonctionner correctement</li>
</ul>

Le quatrième objectif est de charger le contenu du catalogue Ecosphères dans Jena pour l'interroger en SPARQL.<br>
Cela est réalisé mais un hébergement d'un moteur SPARQL est nécessaire pour le montrer.
Un hébergement d'Apache Jena est en cours d'étude sur EcoCompose.
</p>
Le code développé est dispo. sur 