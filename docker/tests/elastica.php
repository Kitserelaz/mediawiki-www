<?php
// Exercise the vendored compatibility shim, not just its parser syntax.
require '/var/www/mediawiki/extensions/Elastica/vendor/autoload.php';
$legacy = 'Elastica\\Query\\Match';
$query = new $legacy();
$query->setFieldQuery( 'title', 'upgrade' );
if ( !$query instanceof Elastica\Query\MatchQuery ||
    $query->toArray() !== [ 'match' => [ 'title' => [ 'query' => 'upgrade' ] ] ]
) {
    throw new RuntimeException( 'Elastica legacy query construction or serialization changed' );
}
echo 'Elastica deprecated query alias and serialization passed on PHP ', PHP_VERSION, ".\n";
