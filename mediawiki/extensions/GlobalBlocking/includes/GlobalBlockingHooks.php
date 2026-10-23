<?php

namespace MediaWiki\Extension\GlobalBlocking;

use MediaWiki\Block\AbstractBlock;
use MediaWiki\Block\Block;
use MediaWiki\Block\BlockTargetFactory;
use MediaWiki\Block\CompositeBlock;
use MediaWiki\Block\Hook\GetUserBlockHook;
use MediaWiki\Block\Hook\SpreadAnyEditBlockHook;
use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\GlobalBlocking\Services\GlobalBlockingLinkBuilder;
use MediaWiki\Extension\GlobalBlocking\Services\GlobalBlockingUserVisibilityLookup;
use MediaWiki\Extension\GlobalBlocking\Services\GlobalBlockLookup;
use MediaWiki\Extension\GlobalBlocking\Services\GlobalBlockManager;
use MediaWiki\Exception\PermissionsError;
use MediaWiki\Hook\GetBlockErrorMessageKeyHook;
use MediaWiki\Html\Html;
use MediaWiki\Logging\LogEventsList;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\Authority;
use MediaWiki\SpecialPage\Hook\SpecialPageBeforeExecuteHook;
use MediaWiki\SpecialPage\ContributionsSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Specials\Hook\ContributionsToolLinksHook;
use MediaWiki\Specials\Hook\GetLogTypesOnUserHook;
use MediaWiki\Specials\Hook\OtherBlockLogLinkHook;
use MediaWiki\Specials\Hook\SpecialLogAddLogSearchRelationsHook;
use MediaWiki\Specials\Hook\SpecialContributionsBeforeMainOutputHook;
use MediaWiki\Title\Title;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\User;
use MediaWiki\User\UserNameUtils;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\IPUtils;

/**
 * MediaWiki hook handlers for the GlobalBlocking extension
 *
 * @license GPL-2.0-or-later
 */
class GlobalBlockingHooks implements
	GetUserBlockHook,
	GetBlockErrorMessageKeyHook,
	OtherBlockLogLinkHook,
	SpecialContributionsBeforeMainOutputHook,
	GetLogTypesOnUserHook,
	ContributionsToolLinksHook,
	SpreadAnyEditBlockHook,
	SpecialPageBeforeExecuteHook,
	SpecialLogAddLogSearchRelationsHook
{
	public function __construct(
		private readonly BlockTargetFactory $blockTargetFactory,
		private readonly Config $config,
		private readonly CommentFormatter $commentFormatter,
		private readonly CentralIdLookup $lookup,
		private readonly GlobalBlockingLinkBuilder $globalBlockLinkBuilder,
		private readonly GlobalBlockLookup $globalBlockLookup,
		private readonly UserNameUtils $userNameUtils,
		private readonly GlobalBlockingUserVisibilityLookup $globalBlockingUserVisibilityLookup,
		private readonly GlobalBlockManager $globalBlockManager,
		private readonly GlobalBlockingLinkBuilder $globalBlockingLinkBuilder,
		private readonly IConnectionProvider $dbProvider,
	) {
	}

	/** @inheritDoc */
	public function onSpecialPageBeforeExecute( $special, $subPage ) {
		$protectedPages = [
			'GlobalBlock',
			'GlobalBlockList',
			'GlobalBlockStatus',
			'MassGlobalBlock',
			'RemoveGlobalBlock',
		];
		$isGlobalBlockLog = $special->getName() === 'Log' && (
			$subPage === 'gblblock' || $special->getRequest()->getVal( 'type' ) === 'gblblock'
		);

		if (
			( in_array( $special->getName(), $protectedPages, true ) || $isGlobalBlockLog ) &&
			!$this->canViewGlobalBlocks( $special->getAuthority() )
		) {
			throw new PermissionsError( 'viewglobalblocks' );
		}
	}

	/** @inheritDoc */
	public function onSpecialLogAddLogSearchRelations( $type, $request, &$qc ) {
		if ( $this->canViewGlobalBlocks( RequestContext::getMain()->getAuthority() ) ) {
			return;
		}

		if ( $type === 'gblblock' ) {
			throw new PermissionsError( 'viewglobalblocks' );
		}

		if ( $type === '' ) {
			$qc[] = $this->dbProvider->getReplicaDatabase()->expr( 'log_type', '!=', 'gblblock' );
		}
	}

	private function canViewGlobalBlocks( Authority $authority ): bool {
		return $authority->isAllowed( 'viewglobalblocks' );
	}

	/**
	 * Add a global block. If there are any existing blocks, add
	 * the global block into a CompositeBlock.
	 *
	 * @param User $user
	 * @param string|null $ip null unless we're checking the session user
	 * @param AbstractBlock|null &$block
	 * @return bool
	 */
	public function onGetUserBlock( $user, $ip, &$block ) {
		if ( !$this->config->get( 'ApplyGlobalBlocks' ) ) {
			return true;
		}

		$globalBlock = $this->globalBlockLookup->getUserBlock( $user, $ip );
		if ( !$globalBlock ) {
			return true;
		}

		if ( !$block ) {
			$block = $globalBlock;
			return true;
		}

		// User is locally blocked and globally blocked. We need a CompositeBlock.
		$allBlocks = $block->toArray();
		$allBlocks[] = $globalBlock;
		$blockTarget = $this->blockTargetFactory->newFromString( $ip ?? $user->getName() );
		$block = new CompositeBlock( [
			'target' => $blockTarget,
			'reason' => new Message( 'blockedtext-composite-reason' ),
			'originalBlocks' => $allBlocks,
		] );
		return true;
	}

	/**
	 * @param Block $block
	 * @param string &$key
	 *
	 * @return bool
	 */
	public function onGetBlockErrorMessageKey( Block $block, string &$key ) {
		if ( $block instanceof GlobalBlock ) {
			if ( $block->getType() === Block::TYPE_AUTO ) {
				$key = 'globalblocking-blockedtext-autoblock';
				if ( $block->getXff() ) {
					// Generates globalblocking-blockedtext-autoblock-xff
					$key .= '-xff';
				}
			} elseif ( $block->getXff() ) {
				$key = 'globalblocking-blockedtext-xff';
			} elseif ( IPUtils::isValid( $block->getTargetName() ) ) {
				$key = 'globalblocking-blockedtext-ip';
			} elseif ( IPUtils::isValidRange( $block->getTargetName() ) ) {
				$key = 'globalblocking-blockedtext-range';
			} elseif ( $this->userNameUtils->isTemp( $block->getTargetName() ) ) {
				$key = 'globalblocking-blockedtext-temp';
			} else {
				$key = 'globalblocking-blockedtext-user';
			}
			return false;
		}
		return true;
	}

	/**
	 * Creates a link to the global block log
	 * @param array &$msg Message with a link to the global block log
	 * @param string $target The username or IP address to be checked
	 *
	 * @return bool true
	 */
	public function onOtherBlockLogLink( &$msg, $target ) {
		$authority = RequestContext::getMain()->getAuthority();
		if ( !$this->canViewGlobalBlocks( $authority ) ) {
			return true;
		}
		// If the target is a username, then we need the central ID for this user to do the lookup.
		$centralId = 0;
		$ip = null;
		if ( IPUtils::isIPAddress( $target ) ) {
			$ip = $target;
		} elseif ( !$this->globalBlockingUserVisibilityLookup->checkAuthorityCanSeeUser( $target, $authority ) ) {
			// If the current user cannot see the target, then we should not show the global block link even if
			// a global block exists for this user.
			return true;
		} else {
			$centralId = $this->lookup->centralIdFromName( $target, $authority );
		}

		// Check to see if the target is globally blocked, skipping the local disable check as we're only interested
		// if a global block exists for this target (as opposed to whether it actually is applied for this user).
		// Also exclude global autoblocks so we don't reveal the IP address being autoblocked.
		$block = $this->globalBlockLookup->getGlobalBlockingBlock(
			$ip, $centralId, GlobalBlockLookup::SKIP_LOCAL_DISABLE_CHECK | GlobalBlockLookup::SKIP_AUTOBLOCKS
		);
		if ( $block ) {
			// If the target is globally blocked, then add a link to the global block list for this target.
			$msg[] = Html::rawElement(
				'span',
				[ 'class' => 'mw-globalblock-loglink plainlinks' ],
				wfMessage( 'globalblocking-loglink', $target )->parse()
			);
		}

		return true;
	}

	/**
	 * Show global block notice on Special:Contributions.
	 * @param int $userId
	 * @param User $user
	 * @param ContributionsSpecialPage $sp
	 *
	 * @return bool
	 */
	public function onSpecialContributionsBeforeMainOutput( $userId, $user, $sp ) {
		$name = $user->getName();

		if ( IPUtils::isIPAddress( $name ) ) {
			$ip = $name;
			$centralId = 0;
		} elseif ( !$this->globalBlockingUserVisibilityLookup->checkAuthorityCanSeeUser(
			$name, $sp->getAuthority()
		) ) {
			// If the current user cannot see the target, then we should not show the global block log entry.
			return true;
		} else {
			$ip = null;
			$centralId = $this->lookup->centralIdFromName( $name, $sp->getAuthority() );
		}
		// Always skip autoblocks, otherwise we would leak the IP address target of global autoblocks which is
		// private data.
		$block = $this->globalBlockLookup->getGlobalBlockingBlock(
			$ip, $centralId, GlobalBlockLookup::SKIP_AUTOBLOCKS
		);

		if ( $block ) {
			$blockNoticeHtml = $sp->msg( 'globalblocking-contribs-notice', $name )->parseAsBlock();
			$out = $sp->getOutput();
			$out->addHTML( Html::warningBox( $blockNoticeHtml ) );

			if (
				$this->canViewGlobalBlocks( $sp->getAuthority() ) &&
				$sp->shouldShowBlockLogExtract( $user )
			) {
				$title = Title::makeTitleSafe( NS_USER, $name );
				if ( $title ) {
					$logHtml = '';
					LogEventsList::showLogExtract(
						$logHtml,
						'gblblock',
						$title->getPrefixedText(),
						'',
						[ 'lim' => 1, 'showIfEmpty' => false ]
					);
					$out->addHTML( $logHtml );
				}
			}
		}

		return true;
	}

	/**
	 * Adds a link on Special:Contributions to Special:GlobalBlock for privileged users.
	 *
	 * @param int $id User ID
	 * @param Title $title User page title
	 * @param array &$tools Tool links
	 * @param SpecialPage $specialPage SpecialPage instance for context and services.
	 * @return bool|void
	 */
	public function onContributionsToolLinks( $id, Title $title, array &$tools, SpecialPage $specialPage ) {
		if ( !$this->canViewGlobalBlocks( $specialPage->getAuthority() ) ) {
			return;
		}

		// Normalise the target to ensure that the call to GlobalBlockLookup::getGlobalBlockId
		// works as intended (as it expects a normalised target).
		$target = $title->getText();
		if ( IPUtils::isValidRange( $target ) ) {
			$target = IPUtils::sanitizeRange( $target );
		} elseif ( IPUtils::isIPAddress( $target ) ) {
			$target = IPUtils::sanitizeIP( $target );
		} elseif ( !$this->globalBlockingUserVisibilityLookup->checkAuthorityCanSeeUser(
			$target, $specialPage->getAuthority()
		) ) {
			// If the current user cannot see the target, then we should not show any contribution tool links
			// to avoid leaking that a user exists with this username and whether this hidden user is globally
			// blocked.
			return;
		} else {
			$target = $this->userNameUtils->getCanonical( $target );
		}

		if ( !$target ) {
			// If the target is invalid, then we will have no links to show and should return early.
			return;
		}

		$linkRenderer = $specialPage->getLinkRenderer();
		if ( $specialPage->getAuthority()->isAllowed( 'globalblock' ) ) {
			if ( $this->globalBlockLookup->getGlobalBlockId( $target ) === 0 ) {
				$tools['globalblock'] = $linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( 'GlobalBlock', $target ),
					$specialPage->msg( 'globalblocking-contribs-block' )->text()
				);
			} else {
				$tools['globalblock'] = $linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( 'GlobalBlock', $target ),
					$specialPage->msg( 'globalblocking-contribs-modify' )->text()
				);

				$tools['globalunblock'] = $linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( 'RemoveGlobalBlock', $target ),
					$specialPage->msg( 'globalblocking-contribs-remove' )->text()
				);
			}
		}

		$tools['globalblocklog'] = Html::rawElement(
			'span', [ 'class' => 'plainlinks' ],
			$this->globalBlockLinkBuilder->getLinkToCentralWikiSpecialPage(
				'Log', $specialPage->msg( 'globalblocking-contribs-log' )->text(),
				$specialPage->getFullTitle(), [ 'type' => 'gblblock', 'page' => $target ]
			)
		);
	}

	/**
	 * So users can just type in a username for target and it'll work
	 * @param array &$types
	 * @return bool
	 */
	public function onGetLogTypesOnUser( &$types ) {
		if ( !$this->canViewGlobalBlocks( RequestContext::getMain()->getAuthority() ) ) {
			return true;
		}

		$types[] = 'gblblock';

		return true;
	}

	/** @inheritDoc */
	public function onSpreadAnyEditBlock( $user, bool &$blockWasSpread ) {
		// Check if the local $user is globally blocked and that the global block enables autoblocks, returning
		// that no blocks were spread if both are not the case.
		// The IP is not specified because ::getUserBlock can only return one global block and we always want a
		// user block. An IP block may be selected by ::getUserBlock if it disables account creation but
		// the user block does not.
		$globalBlock = $this->globalBlockLookup->getUserBlock( $user, null );
		if ( !$globalBlock || !$globalBlock->isAutoblocking() ) {
			return;
		}

		// Actually perform the autoblock for the user's current IP address.
		$autoblockStatus = $this->globalBlockManager->autoblock( $globalBlock->getId(), $user->getRequest()->getIP() );

		// Indicate to the caller that a block was spread if a global autoblock was actually performed.
		$wasGlobalAutoBlockCreated = $autoblockStatus->isGood() && ( $autoblockStatus->getValue()['id'] ?? 0 );
		if ( $wasGlobalAutoBlockCreated ) {
			$blockWasSpread = true;
		}
	}
}
