<?php

// Invoked by the private Caddy listener via its own FastCGI pool.
use WikiOasis\WikiOasisMagic\CustomDomainConfig;

require '/var/www/mediawiki/extensions/SkyWikiMagic/includes/CustomDomainConfig.php';

$path = strtok( $_SERVER['REQUEST_URI'] ?? '', '?' );
$ask = $path === '/tls-allow';
$name = $ask ? ( $_GET['domain'] ?? '' ) : ( $_SERVER['CLOUDWIKI_LOOKUP_HOST'] ?? '' );
$host = is_string( $name ) ? CustomDomainConfig::hostname( $name ) : null;
if ( !CustomDomainConfig::routingEnabled() || ( !$ask && $path !== '/route' ) ||
	!$host || CustomDomainConfig::isReserved( $host )
) {
	http_response_code( $ask ? 403 : 404 );
	return;
}
if ( !$ask && ( $_SERVER['CLOUDWIKI_LOOKUP_SCHEME'] ?? '' ) !== 'http' &&
	CustomDomainConfig::hostname( $_SERVER['CLOUDWIKI_LOOKUP_SNI'] ?? '' ) !== $host
) {
	http_response_code( 421 );
	return;
}

try {
	$password = json_decode( file_get_contents( '/run/secrets/farm.json' ), true, 512, JSON_THROW_ON_ERROR )['db_password'];
	$db = new PDO(
		'mysql:host=' . ( getenv( 'MW_DB_HOST' ) ?: 'database' ) . ';dbname=wikidb',
		getenv( 'MW_DB_USER' ) ?: 'mediawiki', $password, [ PDO::ATTR_TIMEOUT => 2 ]
	);
	$sql = "SELECT c.cdc_wiki FROM cw_custom_domain_claims c
		JOIN cw_wikis w ON w.wiki_dbname = c.cdc_wiki AND w.wiki_deleted = 0
		WHERE c.cdc_host = ? AND ((c.cdc_active = 1 AND w.wiki_url = CONCAT('https://', c.cdc_host))";
	if ( $ask && CustomDomainConfig::enabled() ) {
		$sql .= ' OR (c.cdc_active = 0 AND w.wiki_locked = 0 AND EXISTS (
			SELECT 1 FROM cw_custom_domain_state s
			WHERE s.cd_wiki = c.cdc_wiki AND s.cd_candidate = c.cdc_host AND s.cd_expires > UNIX_TIMESTAMP()
		))';
	}
	$query = $db->prepare( $sql . ')' );
	$query->execute( [ $host ] );
	$wiki = $query->fetchColumn();
} catch ( Throwable $error ) {
	error_log( 'Custom domain registry lookup failed: ' . get_class( $error ) . ' (' . $error->getCode() . ')' );
	http_response_code( 503 );
	return;
}
if ( !$wiki ) {
	http_response_code( $ask ? 403 : 404 );
	return;
}
if ( !$ask ) {
	header( "X-CloudWiki-Route-Wiki: $wiki" );
	header( "X-CloudWiki-Route-Host: $host" );
}
http_response_code( 204 );
