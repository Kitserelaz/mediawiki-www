<?php

namespace RatePage;
use Exception;
use ExtensionRegistry;
use MediaWiki\Extension\Disambiguator;
use MediaWiki\MediaWikiServices;
use RatePage\SMW\DataRebuilder;
use Title;

/**
 * RatePage page rating code
 *
 * TODO: servicify this.
 *
 * @file
 * @ingroup Extensions
 */
class Rating {

	/**
	 * @param Title $title
	 *
	 * @return bool
	 */
	public static function canPageBeRated( Title $title ) : bool {
		global $wgRPRatingAllowedNamespaces, $wgRPRatingPageBlacklist;

		if ( $title->getArticleID() < 1 ) {
			// no such page
			return false;
		}

		if ( $title->isRedirect() ) {
			return false;
		}

		if ( !is_null( $wgRPRatingAllowedNamespaces ) &&
			!in_array( $title->getNamespace(), $wgRPRatingAllowedNamespaces )
		) {
			return false;
		}

		if ( !is_null( $wgRPRatingPageBlacklist ) &&
			(
				in_array( $title->getPrefixedText(), $wgRPRatingPageBlacklist ) ||
				in_array( $title->getPrefixedDBkey(), $wgRPRatingPageBlacklist )
			)
		) {
			return false;
		}

		if ( ExtensionRegistry::getInstance()->isLoaded( 'Disambiguator' ) ) {
			/** @var Disambiguator\Lookup $lookup */
			$lookup = MediaWikiServices::getInstance()->getService( 'DisambiguatorLookup' );
			if ( $lookup->isDisambiguationPage( $title ) ) {
				return false;
			}
		}

		return true;
	}

	public static function getPageContestRatings( Title $title, bool $onlyPubliclyViewable ) : array {
		$pageId = $title->getArticleID();
		if ( $pageId < 0 ) {
			// no such page
			return [];
		}

		$tables = [ 'ratepage_vote' ];
		$fields = [
			'rv_answer as answer',
			"count(rv_page_id) as 'count'",
			'rv_contest as contest'
		];
		$conds = [
			'rv_page_id' => $pageId,
			"rv_contest <> ''"
		];

		if ( $onlyPubliclyViewable ) {
			$tables[] = 'ratepage_contest';
			$fields[] = 'rpc_allowed_to_see';
			$conds[] = 'rv_contest = rpc_id';
		}

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			$tables,
			$fields,
			$conds,
			__METHOD__,
			[
				'GROUP BY' => [
					'rv_answer',
					'rv_contest'
				],
				'ORDER BY' => [
					'rv_contest'
				]
			]
		);

		$contestMap = [];
		$previousContest = '';
		$contestBuffer = [];
		foreach ( $res as $row ) {
			if ( $onlyPubliclyViewable ) {
				$groups = explode( ',', $row->rpc_allowed_to_see );
				if ( !in_array( '*', $groups ) ) {
					continue;
				}
			}

			if ( $previousContest !== $row->contest ) {
				if ( $contestBuffer !== [] ) {
					$contestMap[$previousContest] = self::buildRatingTableFromRows( $contestBuffer );
					$contestBuffer = [];
				}

				$previousContest = $row->contest;
			}

			$contestBuffer[] = $row;
		}

		if ( $contestBuffer !== [] ) {
			$contestMap[$previousContest] = self::buildRatingTableFromRows( $contestBuffer );
		}

		return $contestMap;
	}

	/**
	 * @param Title $title
	 * @param string|null $contest
	 *
	 * @return array
	 */
	public static function getPageRating( Title $title, string $contest = '' ) : array {
		$pageId = $title->getArticleID();
		if ( $pageId < 0 ) {
			// no such page
			return [];
		}

		$where = [
			'rv_page_id' => $pageId,
			'rv_contest' => $contest
		];

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'ratepage_vote',
			[
				'rv_answer as answer',
				"count(rv_page_id) as 'count'"
			],
			$where,
			__METHOD__,
			[
				'GROUP BY' => 'rv_answer'
			]
		);

		return self::buildRatingTableFromRows( $res );
	}

	/**
	 * @param iterable $rows
	 *
	 * @return array
	 */
	private static function buildRatingTableFromRows( iterable $rows ) : array {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$ratingMin = $config->get( 'RPRatingMin' );
		$ratingMax = $config->get( 'RPRatingMax' );

		$pageRating = [];
		for ( $i = $ratingMin; $i <= $ratingMax; $i++ ) {
			$pageRating[$i] = 0;
		}

		foreach ( $rows as $row ) {
			$pageRating[$row->answer] = (int) $row->count;
		}

		return $pageRating;
	}

	/**
	 * @param Title $title
	 * @param string $user
	 * @param string|null $contest
	 *
	 * @return bool|int
	 */
	public static function getUserVote( Title $title, string $user, string $contest = '' ) {
		$pageId = $title->getArticleID();
		if ( $pageId < 0 ) {
			// no such page
			return false;
		}

		$where = [
			'rv_page_id' => $pageId,
			'rv_user' => $user,
			'rv_contest' => $contest
		];

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->selectField(
			'ratepage_vote',
			'rv_answer',
			$where,
			__METHOD__
		);

		if ( $res !== false && !is_null( $res ) ) {
			return (int) $res;
		}

		return -1;
	}

	/**
	 * Vote on a page. Returns bool indicating whether the vote was successful.
	 *
	 * @param Title $title
	 * @param string $user
	 * @param string $ip
	 * @param int $answer
	 * @param string|null $contest
	 *
	 * @return bool
	 */
	public static function voteOnPage( Title $title, string $user, string $ip, int $answer, string $contest = '' ) : bool {
		$pageId = $title->getArticleID();
		if ( $pageId < 0 ) {
			// no such page
			return false;
		}

		$where = [
			'rv_page_id' => $pageId,
			'rv_user' => $user,
			'rv_contest' => $contest
		];

		//check whether the user has voted during a transaction to avoid a duplicate vote
		$dbw = wfGetDB( DB_PRIMARY );
		$dbw->startAtomic( __METHOD__ );
		$res = $dbw->selectField(
			'ratepage_vote',
			'count(rv_user)',
			$where,
			__METHOD__
		);

		if ( $res > 0 ) {
			//the user has already voted, change the vote
			$dbw->update(
				'ratepage_vote',
				[
					'rv_answer' => $answer,
					'rv_date' => date( 'Y-m-d H:i:s' )
				],
				$where,
				__METHOD__ );

			$dbw->endAtomic( __METHOD__ );

			return true;
		}

		//insert the vote
		$dbw->insert(
			'ratepage_vote',
			[ 'rv_page_id' => $title->getArticleID(),
				'rv_user' => $user,
				'rv_ip' => $ip,
				'rv_answer' => $answer,
				'rv_date' => date( 'Y-m-d H:i:s' ),
				'rv_contest' => $contest ],
			__METHOD__
		);

		$dbw->endAtomic( __METHOD__ );

		// Rebuild SMW data if needed
		$dataRebuilder = new DataRebuilder();

		try {
			$dataRebuilder->rebuildSemanticData( $title );
		} catch ( Exception $e ) {
			// If the SMW update failed for some reason, we can safely ignore the error.
			// The data will be updated on the next page update.
		}

		return true;
	}
}