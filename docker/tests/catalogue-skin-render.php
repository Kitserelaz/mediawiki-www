<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';

class VerifyCatalogueSkinRender extends MediaWiki\Maintenance\Maintenance {
    public function execute() {
        $path = getenv('MW_CATALOGUE_COMPONENT');
        if (!str_starts_with($path, 'skins/')) { throw new RuntimeException('A skin manifest is required'); }
        $manifest = json_decode(file_get_contents('/var/www/mediawiki/' . $path), true, 512, JSON_THROW_ON_ERROR);
        $context = MediaWiki\Context\RequestContext::getMain();
        $title = MediaWiki\Title\Title::newFromText('Main Page');
        $context->setTitle($title);
        $GLOBALS['wgTitle'] = $title;
        $output = $context->getOutput();
        $output->setPageTitle('Catalogue compatibility check');
        $output->addHTML('<p>Catalogue compatibility render</p>');
        $skins = [];
        $names = array_keys($manifest['ValidSkinNames'] ?? []);
        if (!$names) {
            foreach (array_keys($this->getServiceContainer()->getSkinFactory()->getInstalledSkins()) as $name) {
                if (strcasecmp($name, $manifest['name']) === 0) { $names[] = $name; }
            }
        }
        foreach ($names as $name) {
            $skin = $this->getServiceContainer()->getSkinFactory()->makeSkin($name);
            $skin->setContext($context);
            $context->setSkin($skin);
            MediaWiki\Output\OutputPage::setupOOUI();
            if (strcasecmp($skin->getSkinName(), $name) !== 0) { throw new RuntimeException('Skin factory silently fell back for ' . $name); }
            ob_start();
            try {
                $skin->outputPage();
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            // SkinTemplate returns the body fragment; OutputPage adds the
            // surrounding HTML document during a real web request.
            if (!str_contains($html, 'Catalogue compatibility render') || strlen($html) < 1000) {
                throw new RuntimeException('Representative page did not render in ' . $name);
            }
            // Build actual ResourceLoader content, including LESS and package
            // callbacks. Registration and HTML alone do not exercise these APIs.
            $loader = $this->getServiceContainer()->getResourceLoader();
            $resourceContext = new MediaWiki\ResourceLoader\Context($loader,
                new MediaWiki\Request\FauxRequest(['lang' => 'en', 'skin' => $name]), [$name]);
            $modules = [];
            $build = function ($moduleName) use (&$build, &$modules, $loader, $resourceContext) {
                if (isset($modules[$moduleName])) { return; }
                $module = $loader->getModule($moduleName);
                if (!$module) { throw new RuntimeException('Missing ResourceLoader dependency: ' . $moduleName); }
                $modules[$moduleName] = null;
                foreach ($module->getDependencies($resourceContext) as $dependency) { $build($dependency); }
                $content = $module->getModuleContent($resourceContext);
                $modules[$moduleName] = hash('sha256', serialize($content));
            };
            foreach (array_unique(array_merge(array_keys($manifest['ResourceModules'] ?? []),
                $output->getModules(), $output->getModuleStyles())) as $moduleName) { $build($moduleName); }
            $skins[] = ['name' => $name, 'bytes' => strlen($html), 'html_sha256' => hash('sha256', $html),
                'resource_modules' => $modules];
        }
        if (!$skins) { throw new RuntimeException('No registered skin names found'); }
        $this->output(json_encode(['manifest' => $path, 'skin_render' => 'passed', 'skins' => $skins], JSON_THROW_ON_ERROR) . "\n");
    }
}
return VerifyCatalogueSkinRender::class;
