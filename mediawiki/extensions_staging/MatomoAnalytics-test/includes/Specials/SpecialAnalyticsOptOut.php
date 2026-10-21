<?php
namespace Miraheze\MatomoAnalytics\Specials;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\MatomoAnalytics\MatomoAnalyticsViewer;

class SpecialAnalyticsOptOut extends SpecialPage {
	public function __construct() {
		parent::__construct( 'AnalyticsOptOut');
	}
	public function execute( $par ) {
		$output = $this->getOutput();
		$this->setHeaders();
		$param = $request->getText( 'param' );
		$wikitext = 'If all has gone well, there should now be a placeholder here.';
		$output->addWikiTextAsInterface( $wikitext );
	}
}
