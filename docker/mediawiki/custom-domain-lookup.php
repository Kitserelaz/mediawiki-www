<?php

// Invoked by the private Caddy listener via its own FastCGI pool.
use WikiOasis\WikiOasisMagic\CustomDomainConfig;

require (rtrim(getenv('MW_INSTALL_PATH') ?: '/var/www/mediawiki', '/') . '/extensions/SkyWikiMagic/includes/CustomDomainConfig.php');

$path = strtok( $_SERVER['REQUEST_URI'] ?? '', '?' );
$numericRoute = $path === '/route-numeric';
$ask = $path === '/tls-allow';
$probe = $path === '/probe-saas';
$saas = $probe || $path === '/route-saas';
$name = $ask ? ( $_GET['domain'] ?? '' ) : ( $_SERVER['CLOUDWIKI_LOOKUP_HOST'] ?? '' );
$host = is_string( $name ) ? CustomDomainConfig::hostname( $name ) : null;
if ( !$host ||
	( !$numericRoute && ( !CustomDomainConfig::routingEnabled() ||
		( !$ask && !$saas && $path !== '/route' ) || CustomDomainConfig::isReserved( $host ) ) )
) {
	http_response_code( $ask ? 403 : 404 );
	return;
}
if ( $numericRoute && getenv( 'MW_NUMERIC_WIKI_IDS' ) !== '1' ) {
	http_response_code( 204 );
	return;
}
if ( !$ask && !$saas && ( $_SERVER['CLOUDWIKI_LOOKUP_SCHEME'] ?? '' ) !== 'http' &&
	CustomDomainConfig::hostname( $_SERVER['CLOUDWIKI_LOOKUP_SNI'] ?? '' ) !== $host
) {
	http_response_code( 421 );
	return;
}
$challenge = $_GET['challenge'] ?? '';
$nonce = $_GET['nonce'] ?? '';
if ( $probe && ( !CustomDomainConfig::enabled() || !is_string( $challenge ) ||
	!is_string( $nonce ) || !preg_match( '/^[a-f0-9]{48}$/D', $challenge ) || !preg_match( '/^[a-f0-9]{32}$/D', $nonce )
) ) {
	http_response_code( 404 );
	return;
}

try {
	$secrets = json_decode( file_get_contents( (rtrim(getenv('MW_SECRETS_DIRECTORY') ?: '/run/secrets', '/') . '/farm.json') ), true, 512, JSON_THROW_ON_ERROR );
	$db = new PDO(
		'mysql:host=' . ( getenv( 'MW_DB_HOST' ) ?: 'database' ) . ';port=' . (getenv('MW_DB_PORT') ?: '3306') . ';dbname=wikidb',
		getenv( 'MW_DB_USER' ) ?: 'mediawiki', $secrets['db_password'], [ PDO::ATTR_TIMEOUT => 2 ]
	);
	if ( $numericRoute ) {
		header( 'Cache-Control: no-store' );
		$query = $db->prepare(
			"SELECT 1 FROM cw_wikis w LEFT JOIN farm_wiki_identity i
				ON w.wiki_dbname = CONCAT(i.numeric_id, 'wiki')
			WHERE w.wiki_deleted = 0 AND (w.wiki_url = CONCAT('https://', ?) OR i.hostname = ? OR i.farm_host = ?)
			LIMIT 1"
		);
		$query->execute( [ $host, $host, $host ] );
		http_response_code( $query->fetchColumn() ? 204 : 404 );
		return;
	}
	$sql = "SELECT c.cdc_wiki FROM cw_custom_domain_claims c
		JOIN cw_wikis w ON w.wiki_dbname = c.cdc_wiki AND w.wiki_deleted = 0
		LEFT JOIN cw_custom_domain_cloudflare f ON f.cdf_host = c.cdc_host
		WHERE c.cdc_host = ?";
	$params = [ $host ];
	if ( $probe ) {
		$sql .= ' AND f.cdf_id IS NOT NULL AND f.cdf_request = ? AND c.cdc_active = 0 AND w.wiki_locked = 0 AND EXISTS (
			SELECT 1 FROM cw_custom_domain_state s WHERE s.cd_wiki = c.cdc_wiki AND s.cd_candidate = c.cdc_host
				AND s.cd_token = f.cdf_request AND s.cd_expires > UNIX_TIMESTAMP()
		)';
		$params[] = $challenge;
	} else {
		$sql .= " AND c.cdc_active = 1 AND w.wiki_url = CONCAT('https://', c.cdc_host)";
		$sql .= $saas ? ' AND f.cdf_id IS NOT NULL' : ' AND f.cdf_host IS NULL';
	}
	$query = $db->prepare( $sql );
	$query->execute( $params );
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
if ( $probe ) {
	header( 'Cache-Control: no-store' );
	header( 'Content-Type: text/plain; charset=UTF-8' );
	echo CustomDomainConfig::probeProof( $host, $challenge, $nonce, $secrets['secret_key'] );
	return;
}
if ( !$ask ) {
	header( "X-CloudWiki-Route-Wiki: $wiki" );
	header( "X-CloudWiki-Route-Host: $host" );
}
http_response_code( 204 );
