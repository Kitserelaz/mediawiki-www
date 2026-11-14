<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';
class VerifyWikibaseAssets extends MediaWiki\Maintenance\Maintenance {
 public function execute() {
  $loader = $this->getServiceContainer()->getResourceLoader();
  $ctx = new MediaWiki\ResourceLoader\Context($loader, new MediaWiki\Request\FauxRequest(['lang'=>'en','skin'=>'vector']), ['vector']);
  $names = array_filter($loader->getModuleNames(), static fn($n) => preg_match('/^(wikibase|dataValues|valueview)\./i', $n));
  if (count($names) < 10) { throw new RuntimeException('Expected Wikibase modules are missing'); }
  $failed=[]; $ok=0;
  foreach ($names as $name) {
   try { $m=$loader->getModule($name); $m->getModuleContent($ctx); $ok++; }
   catch (Throwable $e) { $failed[$name]=$e->getMessage(); }
  }
  $this->output(json_encode(['checked'=>count($names),'passed'=>$ok,'failed'=>$failed],JSON_THROW_ON_ERROR)."\n");
  if ($failed) { throw new RuntimeException('Wikibase asset build failed'); }
 }
}
return VerifyWikibaseAssets::class;
