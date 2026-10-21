<?php
use MediaWiki\Config\SiteConfiguration;
use MediaWiki\MediaWikiServices;

/** Resolve remote ManageWiki configuration independently of the caller's merged cache. */
class FarmManageWikiSiteConfiguration extends SiteConfiguration {
    public function __construct(private readonly SiteConfiguration $farm) {
    }

    public function get($settingName, $wiki, $site = null, $params = [], $wikiTags = []) {
        if (in_array($settingName, ['wgMetaNamespace', 'wgMetaNamespaceTalk'], true) && $wiki !== 'default') {
            $factory = MediaWikiServices::getInstance()->get('ManageWikiModuleFactory');
            $namespace = $settingName === 'wgMetaNamespace' ? NS_PROJECT : NS_PROJECT_TALK;
            $live = $factory->namespaces($wiki)->list($namespace)['name'];
            if ($live !== null) { return $live; }
            // Explicit per-wiki farm settings take precedence over the derived
            // default. ConfigSettings' default otherwise uses the caller's $wi.
            if (array_key_exists($wiki, $this->farm->settings[$settingName] ?? [])) {
                return $this->farm->get($settingName, $wiki, $site, $params, $wikiTags);
            }
            $name = MediaWikiServices::getInstance()->get('RemoteWikiFactory')->newInstance($wiki)->getSitename();
            return str_replace([' ', ':'], '_', $name . ($namespace === NS_PROJECT_TALK ? '_talk' : ''));
        }
        return $this->farm->get($settingName, $wiki, $site, $params, $wikiTags);
    }
}
