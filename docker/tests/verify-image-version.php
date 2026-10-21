<?php
preg_match("/define\( 'MW_VERSION', '([^']+)'/",
    file_get_contents('/var/www/mediawiki/includes/Defines.php'), $match);
if (($match[1] ?? '') !== ($argv[1] ?? '') || PHP_VERSION !== ($argv[2] ?? '') ||
    getenv('MIRAHEZE_WIKI_VERSION') !== ($argv[3] ?? '')) {
    throw new RuntimeException('Image core/PHP/farm-version identity differs from the expected release');
}
echo 'Verified image: MediaWiki ' . $match[1] . ', PHP ' . PHP_VERSION .
    ', farm version ' . getenv('MIRAHEZE_WIKI_VERSION') . ".\n";
