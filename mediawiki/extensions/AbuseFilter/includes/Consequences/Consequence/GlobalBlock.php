<?php

namespace MediaWiki\Extension\AbuseFilter\Consequences\Consequence;

use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\FilterUser;
use MediaWiki\Extension\AbuseFilter\GlobalNameUtils;
use MediaWiki\Extension\GlobalBlocking\Services\GlobalBlockManager;
use MediaWiki\Language\MessageLocalizer;
use Psr\Log\LoggerInterface;
use Wikimedia\IPUtils;

/**
 * Consequence that globally blocks the user who triggered a filter.
 */
class GlobalBlock extends Consequence implements HookAborterConsequence {

	public function __construct(
		Parameters $params,
		private readonly string $expiry,
		private readonly bool $preventsTalk,
		private readonly GlobalBlockManager $globalBlockManager,
		private readonly FilterUser $filterUser,
		private readonly MessageLocalizer $messageLocalizer,
		private readonly LoggerInterface $logger
	) {
		parent::__construct( $params );
	}

	/**
	 * @inheritDoc
	 */
	public function execute(): bool {
		$filter = $this->parameters->getFilter();
		$target = $this->parameters->getUser()->getName();
		$options = IPUtils::isIPAddress( $target ) ? [] : [ 'enable-autoblock' ];
		$localOptions = $this->preventsTalk ? [
			'isCreateAccountBlocked' => true,
			'isEmailBlocked' => false,
			'isUserTalkEditBlocked' => true,
			'isHardBlock' => false,
			'isAutoblocking' => true,
		] : null;
		$status = $this->globalBlockManager->block(
			$target,
			$this->messageLocalizer->msg(
				'abusefilter-blockreason',
				$filter->getName(),
				GlobalNameUtils::buildGlobalName( $filter->getID(), $this->parameters->getIsGlobalFilter() )
			)->inContentLanguage()->text(),
			$this->expiry,
			$this->filterUser->getUserIdentity(),
			$options,
			$localOptions
		);

		if ( !$status->isOK() ) {
			$this->logger->warning(
				'AbuseFilter global block of {block_target} failed: {errors}',
				[ 'block_target' => $target, 'errors' => $status->__toString() ]
			);
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getMessage(): array {
		$filter = $this->parameters->getFilter();
		return [
			'abusefilter-globalblocked-display',
			$filter->getName(),
			GlobalNameUtils::buildGlobalName( $filter->getID(), $this->parameters->getIsGlobalFilter() )
		];
	}
}