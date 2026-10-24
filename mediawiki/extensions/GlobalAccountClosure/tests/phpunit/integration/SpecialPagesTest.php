<?php

namespace MediaWiki\Extension\GlobalAccountClosure\Tests;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Tests\Specials\SpecialPageTestBase;
use MediaWiki\WikiMap\WikiMap;

/**
 * @covers \MediaWiki\Extension\GlobalAccountClosure\Special\SpecialRestoreClosedAccount
 * @covers \MediaWiki\Extension\GlobalAccountClosure\Special\SpecialCloseMyAccount
 * @covers \SpecialGlobalContributions
 * @covers \GlobalUserContribs
 * @group Database
 */
class SpecialPagesTest extends SpecialPageTestBase {
	private string $pageName = 'RestoreClosedAccount';

	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( $this->pageName );
	}

	public function testRestoreFormRendersForAnonymous(): void {
		[ $html ] = $this->executeSpecialPage( '', null, 'en' );
		$this->assertStringContainsString( 'Enter your global password to restore access.', $html );
		$this->assertStringContainsString( 'Global username', $html );
		$this->assertStringContainsString( 'type=\'password\'', $html );
		$this->assertTrue( $this->newSpecialPage()->doesWrites() );
	}

	public function testOwnClosureFormRendersForRegisteredOwner(): void {
		$this->pageName = 'CloseMyAccount';
		$account = $this->getMutableTestUser();
		$user = $account->getUser();
		$central = CentralAuthUser::getPrimaryInstance( $user );
		$central->register( $account->getPassword(), null );
		$central->attach( WikiMap::getCurrentWikiId() );
		[ $html ] = $this->executeSpecialPage( '', null, 'en', $account->getAuthority() );
		$this->assertStringContainsString( 'Confirm your global password', $html );
		$this->assertStringContainsString( 'Close my account', $html );
		$this->assertFalse( $central->isClosed() );
	}

	public function testGlobalContributionsFormRenders(): void {
		$this->pageName = 'GlobalContributions';
		[ $html ] = $this->executeSpecialPage( '', null, 'qqx' );
		$this->assertStringContainsString( 'guc-form-user', $html );
	}

	public function testContributionsUseCurrentActorCommentAndBlockSchema(): void {
		$account = $this->getMutableTestUser();
		$user = $account->getUser();
		$this->editPage( 'Global contributions integration', 'Example contribution',
			'Contribution test summary', NS_MAIN, $account->getAuthority() );
		$wiki = WikiMap::getCurrentWikiId();
		$contributions = new class( $user, RequestContext::getMain() ) extends \GlobalUserContribs {
			protected function getWikisToQuery() {
				return [ WikiMap::getCurrentWikiId() ];
			}
			protected function getForeignScript( $wiki, $type = 'index' ) {
				return 'https://wiki.example/index.php';
			}
			protected function getForeignNSName( $wiki, $nsid ) {
				return '';
			}
			public function renderContribution( $row ) {
				return $this->formatRow( 'testwiki', $row );
			}
		};
		$data = $contributions->getContribs();
		$this->assertArrayHasKey( $wiki, $data );
		$rows = iterator_to_array( $data[$wiki]['revisions'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Contribution test summary', $rows[0]->rev_comment );
		$this->assertFalse( $data[$wiki]['block'] );
		$html = $contributions->renderContribution( $rows[0] );
		$this->assertStringContainsString( 'Contribution test summary', $html );
		$this->assertStringNotContainsString( 'class="minoredit"', $html );
		$this->getServiceContainer()->getDatabaseBlockStore()->insertBlockWithParams( [
			'targetUser' => $user,
			'by' => $this->getTestSysop()->getUser(),
			'expiry' => 'infinity',
			'hideName' => true,
		] );
		$this->assertSame( [], $contributions->getContribs(), 'Suppressed accounts must not expose contributions' );
	}
}
