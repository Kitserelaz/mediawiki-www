<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';
require '/opt/catalogue-classes.php';

class VerifyCatalogueRegistration extends MediaWiki\Maintenance\Maintenance {
    public function execute() {
        $manifestPath = getenv('MW_CATALOGUE_COMPONENT');
        $legacy = ['extensions/SocialProfile/SocialProfile.php' => 'SocialProfile',
            'extensions/Validator/Validator.php' => 'Validator'];
        $manifest = isset($legacy[$manifestPath]) ? ['name' => $legacy[$manifestPath]] :
            json_decode(file_get_contents('/var/www/mediawiki/' . $manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $registry = MediaWiki\Registration\ExtensionRegistry::getInstance();
        if (str_starts_with($manifestPath, 'extensions/ConfirmEdit/') && !$registry->isLoaded('ConfirmEdit')) {
            throw new RuntimeException('CAPTCHA provider loaded without its ConfirmEdit parent');
        }
        $legacyCredits = [];
        foreach ($GLOBALS['wgExtensionCredits'] ?? [] as $credits) {
            foreach ($credits as $credit) { $legacyCredits[] = $credit['name']; }
        }
        if (str_starts_with($manifestPath, 'extensions/SocialProfile/') && !in_array('SocialProfile', $legacyCredits, true)) {
            throw new RuntimeException('SocialProfile module loaded without its legacy parent');
        }
        if (!$registry->isLoaded($manifest['name']) && !in_array($manifest['name'], $legacyCredits, true)) {
            throw new RuntimeException('Target component did not register');
        }
        $classes = catalogueRuntimeClasses($manifestPath, $manifest);
        $this->output(json_encode([
            'manifest' => $manifestPath, 'name' => $manifest['name'],
            'core' => MW_VERSION, 'php' => PHP_VERSION, 'registration' => 'passed',
            'loaded_components' => array_keys($registry->getAllThings()),
            'runtime_class_loading' => 'passed-for-available-dependencies',
            'runtime_classes_checked' => count($classes['loaded']),
            'unavailable_optional_integrations' => $classes['unavailable_optional_integrations'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
return VerifyCatalogueRegistration::class;
