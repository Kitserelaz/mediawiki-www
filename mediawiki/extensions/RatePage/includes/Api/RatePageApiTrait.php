<?php

namespace RatePage\Api;

use ApiBase;
use ApiResult;
use ApiUsageException;
use IContextSource;
use MediaWiki\MediaWikiServices;
use MWException;
use RatePage\ContestDB;
use RatePage\Rights;
use RatePage\Rating;
use Title;
use User;

/**
 * Trait RatePage\Api\RatePageApiTrait
 * Common code for both API endpoints.
 */
trait RatePageApiTrait {
	/**
	 * @var string
	 */
	protected $contestId;

	/**
	 * @var array
	 */
	protected $permissions;

	/**
	 * @var int
	 */
	protected $seeBeforeVote = 0;

	/**
	 * @var string
	 */
	protected $userName;

	/**
	 * @var User
	 */
	protected $user;

	/**
	 * @var string
	 */
	protected $ip;

	/**
	 * Processes the contest parameter and checks user permissions.
	 *
	 * @param array $params
	 * @param IContextSource $context
	 * @param ApiBase $parent
	 *
	 * @throws ApiUsageException
	 * @throws MWException
	 */
	protected function processParams( array $params, IContextSource $context, ApiBase $parent ) : void {
		$this->user = $context->getUser();
		$this->ip = $context->getRequest()->getIP();
		if ( $this->user->getName() == '' ) {
			$this->userName = $this->ip;
		} else {
			$this->userName = $this->user->getName();
		}

		$this->contestId = '';
		$this->permissions = [
			'vote' => $this->user->isAllowed( 'ratepage-vote' ),
			'see' => true
		];

		$contestId = trim( $params['contest'] ?? '' );
		if ( strlen( $contestId ) > 255 ) {
			$parent->dieWithError(
				'apierror-ratepage-contest-id-too-long',
				'contestidtoolong'
			);
		}

		if ( $contestId ) {
			if ( !ctype_alnum( $contestId ) ) {
				$parent->dieWithError(
					'apierror-ratepage-contest-id-allowed-chars',
					'contestidinvalidchars'
				);
			}

			$this->contestId = $contestId;
			$contest = ContestDB::loadContest( $this->contestId );

			if ( !$contest ) {
				$parent->dieWithError(
					[ 'apierror-ratepage-contest-not-exists', $this->contestId ],
					'contestnotexists'
				);
			}

			$this->permissions = Rights::checkUserPermissionsOnContest(
				$contest,
				$this->user
			);
			$this->seeBeforeVote = (int) $contest->rpc_see_before_vote;
		} else {
			$config = MediaWikiServices::getInstance()->getMainConfig();
			$this->seeBeforeVote = $config->get( 'RPShowResultsBeforeVoting' ) ? 1 : 0;
		}
	}

	/**
	 * Adds a title to API results given a path.
	 *
	 * @param Title $title
	 * @param array|null $path
	 * @param ApiResult $result
	 */
	protected function addTitleToResults( Title $title, ?array $path, ApiResult $result ) : void {
		$userVote = Rating::getUserVote(
			$title,
			$this->userName,
			$this->contestId
		);

		if ( $this->permissions['see'] ) {
			$pageRating = Rating::getPageRating(
				$title,
				$this->contestId
			);
			$result->addValue(
				$path,
				'pageRating',
				$pageRating
			);
		}

		$result->addValue(
			$path,
			'showResultsBeforeVoting',
			$this->seeBeforeVote
		);
		$result->addValue(
			$path,
			'userVote',
			$userVote
		);
		$result->addValue(
			$path,
			'canVote',
			(int) $this->permissions['vote']
		);
		$result->addValue(
			$path,
			'canSee',
			(int) $this->permissions['see']
		);
	}
}
