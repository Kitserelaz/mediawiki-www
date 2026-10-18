<?php
// Runtime-only configuration. Never bake production credentials into an image.
$path = getenv('MW_SECRETS_FILE') ?: '/run/secrets/farm.json';
$farmSecrets = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
foreach (['db_password', 'secret_key', 'upgrade_key'] as $key) {
    if (empty($farmSecrets[$key])) { throw new RuntimeException("Missing runtime secret: $key"); }
}
$wgDBpassword = $farmSecrets['db_password'];
$wgSecretKey = $farmSecrets['secret_key'];
$wgUpgradeKey = $farmSecrets['upgrade_key'];
$wgHCaptchaSiteKey = $farmSecrets['hcaptcha_site_key'] ?? '';
$wgHCaptchaSecretKey = $farmSecrets['hcaptcha_secret_key'] ?? '';
$wgMatomoAnalyticsTokenAuth = $farmSecrets['matomo_token'] ?? '';
$wgCFCachePurgeZoneId = $farmSecrets['cloudflare_zone'] ?? '';
$wgCFCachePurgeApiToken = $farmSecrets['cloudflare_token'] ?? '';
$wmgGlobalDiscordWebhookUrl = $farmSecrets['discord_webhook'] ?? '';
