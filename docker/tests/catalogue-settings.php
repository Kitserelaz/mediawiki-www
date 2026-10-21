<?php
// Explicit CLI-only configuration for isolated component registration checks.
if (PHP_SAPI !== 'cli' || getenv('MW_ENV') === 'production' || !getenv('MW_CATALOGUE_COMPONENT')) {
    throw new RuntimeException('Catalogue checks require an explicit development CLI target');
}
require '/var/www/mediawiki/LocalSettings.php';

$catalogueRoot = '/var/www/mediawiki';
$catalogueLegacy = ['extensions/SocialProfile/SocialProfile.php', 'extensions/Validator/Validator.php'];
if (in_array(getenv('MW_CATALOGUE_COMPONENT'), $catalogueLegacy, true)) {
    require_once $catalogueRoot . '/' . getenv('MW_CATALOGUE_COMPONENT');
    return;
}
if (str_starts_with(getenv('MW_CATALOGUE_COMPONENT'), 'extensions/SocialProfile/')) {
    // SocialProfile's supported entry point loads its shared classes and six
    // bundled modules; UserWelcome is an optional seventh module.
    require_once "$catalogueRoot/extensions/SocialProfile/SocialProfile.php";
}
$catalogueFiles = array_merge(
    glob("$catalogueRoot/extensions/*/extension*.json"),
    glob("$catalogueRoot/extensions/*/*/extension.json"),
    glob("$catalogueRoot/skins/*/skin.json")
);
$catalogueByName = [];
$catalogueByPath = [];
foreach ($catalogueFiles as $file) {
    $manifest = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $catalogueByName[$manifest['name']] = $file;
    $catalogueByPath[substr($file, strlen($catalogueRoot) + 1)] = $file;
}
$catalogueTarget = $catalogueByPath[getenv('MW_CATALOGUE_COMPONENT')] ?? null;
if ($catalogueTarget === null) { throw new RuntimeException('Unknown catalogue manifest path'); }
if (str_starts_with(getenv('MW_CATALOGUE_COMPONENT'), 'extensions/ConfirmEdit/')) {
    // CAPTCHA providers are alternatives. Test the selected provider in place
    // of the farm's hCaptcha default, rather than registering two owners of
    // CaptchaClass in one process. This affects only the isolated CLI fixture.
    $registry = MediaWiki\Registration\ExtensionRegistry::getInstance();
    $queue = $registry->getQueue();
    $registry->clearQueue();
    foreach (array_keys($queue) as $file) {
        if ($file === "$catalogueRoot/extensions/ConfirmEdit/extension.json" ||
            !str_starts_with($file, "$catalogueRoot/extensions/ConfirmEdit/")) { $registry->queue($file); }
    }
    $provider = json_decode(file_get_contents($catalogueTarget), true, 512, JSON_THROW_ON_ERROR);
    $GLOBALS['wgCaptchaClass'] = $provider['config']['CaptchaClass']['value'];
}
$catalogueQueued = [];
$catalogueQueue = static function (string $file) use (&$catalogueQueue, &$catalogueQueued, $catalogueByName): void {
    if (isset($catalogueQueued[$file])) { return; }
    $catalogueQueued[$file] = true;
    $manifest = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    // Exercise shipped optional integrations while loading runtime declarations.
    // Missing providers stay absent and are explicitly recorded by the class gate.
    $optional = [
        'CheckUser' => ['IPInfo'],
        'DiscussionTools' => ['Thanks'],
        'Gadgets' => ['CodeEditor', 'CodeMirror'],
        'Math' => ['WikibaseRepository', 'WikibaseClient'],
        'PageImages' => ['MobileFrontend'],
        'Replace Text' => ['Admin Links'],
        'Scribunto' => ['CodeEditor', 'CodeMirror'],
        'TemplateStyles' => ['CodeEditor', 'CodeMirror'],
        'Thanks' => ['Flow'],
    ];
    foreach ($optional[$manifest['name']] ?? [] as $name) {
        if (isset($catalogueByName[$name])) { $catalogueQueue($catalogueByName[$name]); }
    }
    // These integrations declare repository dependencies in callbacks.
    if (in_array($manifest['name'], ['WikibaseLexeme', 'WikibaseLexemeCirrusSearch', 'WikibaseMediaInfo'], true)) {
        $catalogueQueue($catalogueByName['WikibaseRepository']);
    }
    if ($manifest['name'] === 'WikibaseRepository') {
        // Upstream's local entity-source fixture avoids external repositories.
        require_once dirname($file) . '/repo/config/Wikibase.example.php';
    }
    foreach (array_merge($manifest['requires']['extensions'] ?? [], $manifest['requires']['skins'] ?? []) as $name => $_) {
        if (!isset($catalogueByName[$name])) { throw new RuntimeException("Missing catalogue dependency: $name"); }
        $catalogueQueue($catalogueByName[$name]);
    }
    MediaWiki\Registration\ExtensionRegistry::getInstance()->queue($file);
};
$catalogueQueue($catalogueTarget);
foreach (json_decode(getenv('MW_CATALOGUE_EXTRA_COMPONENTS_JSON') ?: '[]', true, 512, JSON_THROW_ON_ERROR) as $name) {
    if (!is_string($name) || !isset($catalogueByName[$name])) {
        throw new RuntimeException('Unknown explicit extra catalogue fixture component');
    }
    $catalogueQueue($catalogueByName[$name]);
}
