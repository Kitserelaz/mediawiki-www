<?php
/** Static requirements evidence only; registration and behavior need separate checks. */
require '/var/www/mediawiki/vendor/autoload.php';
use Composer\Semver\Semver;

$root = '/var/www/mediawiki';
preg_match("/define\( 'MW_VERSION', '([^']+)'/", file_get_contents("$root/includes/Defines.php"), $match);
$version = $match[1];
$files = array_merge(glob("$root/extensions/*/extension*.json"),
    glob("$root/extensions/*/*/extension*.json"), glob("$root/skins/*/skin.json"));
$available = [];
foreach (array_merge($files, glob("$root/extensions/*/*/extension.json")) as $file) {
    $manifest = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $available[$manifest['name']] = $manifest;
}
$report = ['core' => $version, 'php' => PHP_VERSION, 'kind' => 'static-requirements-only', 'components' => []];
foreach ($files as $file) {
    $manifest = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $issues = [];
    $requires = $manifest['requires'] ?? [];
    if (isset($requires['MediaWiki']) && !Semver::satisfies($version, $requires['MediaWiki'])) {
        $issues[] = 'core requirement: ' . $requires['MediaWiki'];
    }
    foreach (($requires['platform'] ?? []) as $name => $constraint) {
        if ($name === 'php') {
            if (!Semver::satisfies(PHP_VERSION, $constraint)) { $issues[] = "PHP requirement: $constraint"; }
        } elseif (str_starts_with($name, 'ext-') && !extension_loaded(substr($name, 4))) {
            $issues[] = "missing PHP extension: $name";
        }
    }
    foreach (array_merge($requires['extensions'] ?? [], $requires['skins'] ?? []) as $name => $constraint) {
        if (!isset($available[$name])) {
            $issues[] = "missing component dependency: $name ($constraint)";
        } elseif ($constraint !== '*' && isset($available[$name]['version']) &&
            !Semver::satisfies($available[$name]['version'], $constraint)) {
            $issues[] = "component dependency version: $name ($constraint)";
        }
    }
    $report['components'][] = [
        'path' => substr(dirname($file), strlen($root) + 1),
        'name' => $manifest['name'], 'issues' => $issues,
        'runtime_status' => 'not-tested',
    ];
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
exit(count(array_filter($report['components'], static fn($entry) => $entry['issues'] !== [])) ? 1 : 0);
