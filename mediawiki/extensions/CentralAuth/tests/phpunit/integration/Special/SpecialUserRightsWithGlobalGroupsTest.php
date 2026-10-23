<?php
/**
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CentralAuth\Tests\Phpunit\Integration\Special;

use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\Extension\CentralAuth\Config\CAMainConfigNames;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Specials\SpecialPageTestBase;
use MediaWiki\WikiMap\WikiMap;

/**
 * @covers \MediaWiki\Extension\CentralAuth\Special\SpecialUserRightsWithGlobalGroups
 * @group Database
 */
class SpecialUserRightsWithGlobalGroupsTest extends SpecialPageTestBase {

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( CAMainConfigNames::CentralAuthEnableSul3, false );
		CentralAuthServices::getDatabaseManager( $this->getServiceContainer() )
			->getCentralPrimaryDB()->newInsertQueryBuilder()
			->insertInto( 'global_group_permissions' )
			->ignore()
			->row( [
				'ggp_group' => 'userrights-global-test',
				'ggp_permission' => 'userrights-global-test-right',
			] )
			->caller( __METHOD__ )
			->execute();
	}

	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'Userrights' );
	}

	public function testDisplayAndSaveGlobalGroups(): void {
		$testUser = $this->getMutableTestUser();
		$target = $testUser->getUser();
		$centralUser = CentralAuthUser::getPrimaryInstance( $target );
		$centralUser->register( $testUser->getPassword(), null );
		$centralUser->attach( WikiMap::getCurrentWikiId() );

		$performer = $this->getTestSysop()->getUser();
		$authority = new UltimateAuthority( $performer );
		[ $html ] = $this->executeSpecialPage( $target->getName(), null, 'qqx', $authority );
		$this->assertStringContainsString( 'globalgroup-userrights-global-test', $html );

		$request = new FauxRequest( [
			'user' => $target->getName(),
			'saveglobalgroups' => '1',
			'globalgroup-userrights-global-test' => '1',
			'conflictcheck-originalgroups' => '',
			'wpEditToken' => $performer->getEditToken( $target->getName() ),
		], true );
		$this->executeSpecialPage( $target->getName(), $request, 'qqx', $authority );

		$centralUser->invalidateCache();
		$this->assertSame( [ 'userrights-global-test' ], $centralUser->getGlobalGroups() );
	}
}