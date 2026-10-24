<?php
/** @license GPL-2.0-or-later */
use MediaWiki\Extension\ArchiveWikiImport\ImportJob;
use MediaWiki\Extension\ArchiveWikiImport\RunStore;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Registration\ExtensionRegistry;

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class RecoverArchiveWikiImports extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Recover abandoned archive imports; run once per minute for each wiki.' );
	}
	public function execute() {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'ArchiveWikiImport' ) ) {
			return;
		}
		$result = ( new RunStore() )->recover( function ( string $id ): void {
			$this->getServiceContainer()->getJobQueueGroup()->push( new ImportJob( [ 'run' => $id ] ) );
		} );
		$this->output( json_encode( $result, JSON_THROW_ON_ERROR ) . "\n" );
	}
}
$maintClass = RecoverArchiveWikiImports::class;
require_once RUN_MAINTENANCE_IF_MAIN;
