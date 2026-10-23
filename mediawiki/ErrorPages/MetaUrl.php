<?php

// Error pages can render before the wiki configuration has finished loading.
$metaServer = $GLOBALS['wgCentralHost'] ?? (
    'https://meta.' . ( getenv( 'MW_DOMAIN' ) ?: FARM_DEFAULT_SERVERS[getenv( 'MW_REALM' ) ?: 'default'] )
);

return htmlspecialchars( rtrim( $metaServer, '/' ), ENT_QUOTES, 'UTF-8' );
