<?php
// A missing wiki may be created or routed here later; never cache this response.
header( 'Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate' );
header( 'CDN-Cache-Control: no-store' );
header( 'Cloudflare-CDN-Cache-Control: no-store' );
header( 'Pragma: no-cache' );
header( 'Expires: Thu, 01 Jan 1970 00:00:00 GMT' );
$metaUrl = require __DIR__ . '/MetaUrl.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wiki not found - CloudWiki</title>
</head>
<body style="font-family: sans-serif; text-align: center; padding: 3rem 1rem;">
    <h1>Wiki not found</h1>
    <p>We couldn't find the wiki you are trying to access.</p>
    <p><a href="<?= $metaUrl ?>/">Return to Meta</a></p>
</body>
</html>
