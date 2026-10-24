<?php
/**
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CentralAuth\Special;

use MediaWiki\Extension\CentralAuth\GlobalGroup\GlobalGroupAssignmentService;
use MediaWiki\Extension\CentralAuth\GlobalGroup\GlobalGroupManager;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUserHelper;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Language\FormatterFactory;
use MediaWiki\Specials\SpecialUserRights;
use MediaWiki\Status\Status;
use MediaWiki\User\MultiFormatUserIdentityLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupAssignmentService;
use MediaWiki\User\UserGroupManagerFactory;
use MediaWiki\User\UserGroupMembership;
use MediaWiki\User\UserNamePrefixSearch;
use MediaWiki\User\UserNameUtils;
use MediaWiki\Watchlist\WatchlistManager;
use Wikimedia\Message\MessageValue;
use function str_starts_with;
use function wfAppendQuery;

/**
 * Core user-rights page with CentralAuth global-group membership management.
 */
class SpecialUserRightsWithGlobalGroups extends SpecialUserRights {
	private ?CentralAuthUser $targetGlobalUser = null;

	public function __construct(
		UserGroupManagerFactory $userGroupManagerFactory,
		UserNameUtils $userNameUtils,
		UserNamePrefixSearch $userNamePrefixSearch,
		UserFactory $userFactory,
		WatchlistManager $watchlistManager,
		UserGroupAssignmentService $userGroupAssignmentService,
		MultiFormatUserIdentityLookup $multiFormatUserIdentityLookup,
		FormatterFactory $formatterFactory,
		private readonly CentralAuthUserHelper $centralAuthUserHelper,
		private readonly GlobalGroupAssignmentService $globalGroupAssignmentService,
		private readonly GlobalGroupManager $globalGroupManager
	) {
		parent::__construct(
			$userGroupManagerFactory,
			$userNameUtils,
			$userNamePrefixSearch,
			$userFactory,
			$watchlistManager,
			$userGroupAssignmentService,
			$multiFormatUserIdentityLookup,
			$formatterFactory
		);
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$targetName = $subPage ?? $this->getRequest()->getText( 'user' );
		if ( $targetName === '' ) {
			$globalUserStatus = null;
		} elseif ( str_starts_with( $targetName, '#' ) ) {
			$globalUserStatus = $this->centralAuthUserHelper->getCentralAuthUserByIdFromPrimary(
				(int)substr( $targetName, 1 ), $this->getAuthority()
			);
		} else {
			$globalUserStatus = $this->centralAuthUserHelper->getCentralAuthUserByNameFromPrimary(
				$targetName, $this->getAuthority()
			);
		}
		$saveStatus = null;
		if ( $globalUserStatus && $globalUserStatus->isOK() ) {
			'@phan-var CentralAuthUser $globalUser';
			$globalUser = $globalUserStatus->getValue();
			$this->targetGlobalUser = $globalUser;
		}

		if (
			$globalUserStatus && $globalUserStatus->isOK() &&
			$this->getRequest()->wasPosted() &&
			$this->getRequest()->getCheck( 'saveglobalgroups' ) &&
			$this->getUser()->matchEditToken(
				$this->getRequest()->getVal( 'wpEditToken' ), $targetName
			)
		) {
			'@phan-var CentralAuthUser $globalUser';
			$globalUser = $globalUserStatus->getValue();
			$saveStatus = $this->saveGlobalGroups( $globalUser );
			if ( $saveStatus->isOK() ) {
				$this->getRequest()->getSession()->set( 'specialUserrightsGlobalGroupsSaved', 1 );
				$this->getOutput()->redirect( wfAppendQuery(
					$this->getPageTitle()->getLocalURL(), [ 'user' => $targetName ]
			) );
				return;
			}
		}

		parent::execute( $subPage );

		if ( !$globalUserStatus || !$globalUserStatus->isOK() ) {
			return;
		}

		'@phan-var CentralAuthUser $globalUser';
		$globalUser = $globalUserStatus->getValue();
		if ( $this->getRequest()->getSession()->get( 'specialUserrightsGlobalGroupsSaved' ) ) {
			$this->getRequest()->getSession()->remove( 'specialUserrightsGlobalGroupsSaved' );
			$this->getOutput()->addHTML( Html::successBox(
				$this->msg( 'centralauth-userrights-globalgroups-saved' )->escaped()
			) );
		}

		if ( $saveStatus && !$saveStatus->isOK() ) {
			foreach ( $saveStatus->getMessages() as $message ) {
				$this->getOutput()->addHTML( Html::errorBox( $this->msg( $message )->parse() ) );
			}
		}

	}

	private function saveGlobalGroups( CentralAuthUser $target ): Status {
		if ( !$this->getAuthority()->isAllowed( 'globalgroupmembership' ) ) {
			return Status::newFatal( 'badaccess-group0', 'globalgroupmembership' );
		}

		$currentGroups = $target->getGlobalGroupsWithExpiration();
		$currentMemberships = [];
		foreach ( $currentGroups as $group => $expiry ) {
			$currentMemberships[$group] = new UserGroupMembership( $target->getId(), $group, $expiry );
		}
		if ( $this->getRequest()->getVal( 'conflictcheck-originalglobalgroups' ) !==
			$this->makeConflictCheckKey( $currentMemberships )
		) {
			return Status::newFatal( 'userrights-conflict' );
		}

		$changeable = $this->globalGroupAssignmentService->getChangeableGroups(
			$this->getAuthority(), $target
		);
		$addGroups = [];
		$removeGroups = [];
		$expiries = [];
		foreach ( $this->globalGroupManager->getDefinedGroups() as $group ) {
			$isMember = array_key_exists( $group, $currentGroups );
			$requested = $this->getRequest()->getCheck( 'globalgroup-' . $group );
			if ( $requested && !$isMember && in_array( $group, $changeable['add'], true ) ) {
				$addGroups[] = $group;
				$expiries[$group] = null;
			} elseif ( !$requested && $isMember && in_array( $group, $changeable['remove'], true ) ) {
				$removeGroups[] = $group;
			}
		}

		$invalidGroups = $this->globalGroupAssignmentService->validateUserGroups(
			$this->getAuthority(), $target, $addGroups, $removeGroups, $expiries, $currentMemberships
		);
		if ( $invalidGroups ) {
			$this->globalGroupAssignmentService->logAccessToPrivateConditions(
				$this->getAuthority(), $target, $addGroups, $expiries, $currentMemberships
			);
			return $this->formatInvalidGroupsStatus( $invalidGroups, $target->getName() );
		}

		$this->globalGroupAssignmentService->saveChangesToUserGroups(
			$this->getAuthority(),
			$target,
			$addGroups,
			$removeGroups,
			$expiries,
			$this->getRequest()->getText( 'globalgroup-reason' ),
			MessageValue::new( 'centralauth-automatic-global-groups-reason-global' )
		);

		return Status::newGood();
	}

	protected function getAdditionalGroupFields(): array {
		if ( !$this->targetGlobalUser ) {
			return [];
		}

		$target = $this->targetGlobalUser;
		$currentGroups = $target->getGlobalGroupsWithExpiration();
		$changeable = $this->globalGroupAssignmentService->getChangeableGroups(
			$this->getAuthority(), $target
		);
		$fields = [
			'conflictcheck-originalglobalgroups' => [
				'type' => 'hidden',
				'name' => 'conflictcheck-originalglobalgroups',
				'default' => $this->makeConflictCheckKey( $this->getCurrentGlobalMemberships( $target ) ),
			],
		];

		foreach ( $this->globalGroupManager->getDefinedGroups() as $group ) {
			$isMember = array_key_exists( $group, $currentGroups );
			$fields['globalgroup-' . $group] = [
				'type' => 'check',
				'name' => 'globalgroup-' . $group,
				'id' => 'globalgroup-' . $group,
				'default' => $isMember,
				'label' => $this->getLanguage()->getGroupMemberName( $group, $target->getName() ),
				'cssclass' => 'mw-userrights-groupcheckbox',
				'disabled' => $isMember
					? !in_array( $group, $changeable['remove'], true )
					: !in_array( $group, $changeable['add'], true ),
			];
		}

		if ( $this->canChangeAdditionalGroups() ) {
			$fields['globalgroup-reason'] = [
				'type' => 'text',
				'name' => 'globalgroup-reason',
				'label' => $this->msg( 'centralauth-userrights-globalgroups-reason' )->text(),
			];
		}

		return $fields;
	}

	protected function canChangeAdditionalGroups(): bool {
		if ( !$this->targetGlobalUser ) {
			return false;
		}

		$changeable = $this->globalGroupAssignmentService->getChangeableGroups(
			$this->getAuthority(), $this->targetGlobalUser
		);
		return $changeable['add'] !== [] || $changeable['remove'] !== [];
	}

	protected function modifyGroupsForm( HTMLForm $form ): void {
		if ( $this->canChangeAdditionalGroups() ) {
			$form->addButton( [
				'name' => 'saveglobalgroups',
				'value' => '1',
				'label-message' => 'centralauth-userrights-globalgroups-save',
			] );
		}
	}

	protected function categorizeUserGroupsForDisplay( array $userGroups ): array {
		$categories = parent::categorizeUserGroupsForDisplay( $userGroups );
		if ( $this->targetGlobalUser ) {
			$globalMemberships = $this->getCurrentGlobalMemberships( $this->targetGlobalUser );
			$categories['userrights-groupsmember'] = array_merge(
				$categories['userrights-groupsmember'],
				array_values( $globalMemberships )
			);
		}
		return $categories;
	}

	private function getCurrentGlobalMemberships( CentralAuthUser $target ): array {
		$memberships = [];
		foreach ( $target->getGlobalGroupsWithExpiration() as $group => $expiry ) {
			$memberships[$group] = new UserGroupMembership( $target->getId(), $group, $expiry );
		}
		return $memberships;
	}
}