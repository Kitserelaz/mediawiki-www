<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';
use Wikibase\Repo\WikibaseRepo;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\Property;
use MediaWiki\User\User;
class VerifyWikibaseMigration extends MediaWiki\Maintenance\Maintenance {
 public function execute() {
  if (getenv('MW_ENV') !== 'development' || getenv('MW_WIKIBASE_TEST_ALLOW_WRITES') !== '1' || $this->getConfig()->get('DBname') !== 'testwiki') { throw new RuntimeException('Wikibase fixture requires an explicitly authorized disposable development testwiki'); }
  $registry = MediaWiki\Registration\ExtensionRegistry::getInstance();
  foreach (['WikibaseRepository', 'WikibaseClient', 'WikibaseLexeme'] as $name) {
   if (!$registry->isLoaded($name)) { throw new RuntimeException('Component not loaded: ' . $name); }
  }
  $user = User::newSystemUser('Wikibase Migration Test', ['steal' => true]);
  $store = WikibaseRepo::getEntityStore();
  $lookup = WikibaseRepo::getEntityLookup();
  $item = new Item();
  $item->setLabel('en', 'Migration test item');
  $revision = $store->saveEntity($item, 'Migration test', $user, EDIT_NEW);
  $id = $revision->getEntity()->getId();
  $loaded = $lookup->getEntity($id);
  if (!$loaded || $loaded->getFingerprint()->getLabel('en')->getText() !== 'Migration test item') { throw new RuntimeException('Item creation/read failed'); }
  $loaded->setLabel('en', 'Updated migration test item');
  $store->saveEntity($loaded, 'Migration test update', $user, EDIT_UPDATE);
  $reloaded = $lookup->getEntity($id);
  if ($reloaded->getFingerprint()->getLabel('en')->getText() !== 'Updated migration test item') { throw new RuntimeException('Item update/read failed'); }
  $property = Property::newFromType('string');
  $property->setLabel('en', 'Migration test property ' . bin2hex(random_bytes(4))); 
  $pr = $store->saveEntity($property, 'Migration test property', $user, EDIT_NEW);
  if (!$lookup->getEntity($pr->getEntity()->getId())) { throw new RuntimeException('Property creation/read failed'); }
  $lemmas = new Wikibase\DataModel\Term\TermList([new Wikibase\DataModel\Term\Term('en', 'migration lexeme')]);
  $lexeme = new Wikibase\Lexeme\Domain\Model\Lexeme(null, $lemmas, $id, $id);
  $lr = $store->saveEntity($lexeme, 'Migration test lexeme', $user, EDIT_NEW);
  $l = $lookup->getEntity($lr->getEntity()->getId());
  if (!$l || $l->getLemmas()->getByLanguage('en')->getText() !== 'migration lexeme') { throw new RuntimeException('Lexeme creation/read failed'); }
  $this->output("Item create/update/read, property and lexeme create/read passed\n");
 }
}
return VerifyWikibaseMigration::class;
