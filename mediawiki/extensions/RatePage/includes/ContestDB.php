<?php

namespace RatePage;
use Exception;
use IContextSource;
use ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use stdClass;

class ContestDB {

	/**
	 * @param string $contest
	 *
	 * @return bool|stdClass
	 */
	public static function loadContest( string $contest ) {
		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$contest = $dbr->selectRow( [ 'ratepage_contest', ],
			'*',
			[ 'rpc_id' => $contest, ],
			__METHOD__ );

		return $contest;
	}

	/**
	 * @param string $contest
	 *
	 * @return array
	 */
	public static function loadVotes( string $contest ) : array {
		$votes = [];
		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();

		$votesRes = $dbr->select( [ 'ratepage_vote' ],
			[ 'rv_page_id',
				'rv_answer',
				'answer_count' => 'COUNT(rv_user)' ],
			[ 'rv_contest' => $contest ],
			__METHOD__,
			[ 'GROUP BY' => 'rv_page_id', ] );

		if ( !empty( $votesRes ) ) {
			foreach ( $votesRes as $res ) {
				$votes[$res->rv_page_id][$res->rv_answer] = $res->answer_count;
			}
		}

		return $votes;
	}

	public static function saveContest( $newRow, IContextSource $context ) {
		$dbw = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();

		$data = [
			'rpc_description' => $newRow->rpc_description ?? '',
			'rpc_enabled' => $newRow->rpc_enabled ?? 0,
			'rpc_allowed_to_vote' => $newRow->rpc_allowed_to_vote ?? '',
			'rpc_allowed_to_see' => $newRow->rpc_allowed_to_see ?? '',
			'rpc_see_before_vote' => $newRow->rpc_see_before_vote ?? 0
		];

		$id = $newRow->rpc_id;
		$dbw->startAtomic( __METHOD__ );

		try {
			$res = $dbw->selectField( 'ratepage_contest',
				'rpc_id',
				[ 'rpc_id' => $id ],
				__METHOD__ );

			if ( !$res ) {
				$dbw->insert( 'ratepage_contest',
					$data + [ 'rpc_id' => $id ],
					__METHOD__ );

				$subtype = 'create';
			} else {
				$dbw->update( 'ratepage_contest',
					$data,
					[ 'rpc_id' => $id ],
					__METHOD__ );

				$subtype = 'change';
			}

			$dbw->endAtomic( __METHOD__ );
		} catch ( Exception $exception ) {
			$dbw->endAtomic( __METHOD__ );
			throw $exception;
		}

		$logEntry = new ManualLogEntry( 'ratepage-contest',
			$subtype );
		$logEntry->setPerformer( $context->getUser() );
		$logEntry->setTarget( Title::newFromText( "Special:RatePageContests/$id" ) );
		$logEntry->setParameters( [
			'id' => $id,
			'description' => $data['rpc_description'],
			'enabled' => $data['rpc_enabled'],
			'allowed_to_vote' => $data['rpc_allowed_to_vote'],
			'allowed_to_see' => $data['rpc_allowed_to_see'],
			'see_before_vote' => $data['rpc_see_before_vote'],
		] );
		$logid = $logEntry->insert();
		$logEntry->publish( $logid );
	}

	public static function validateId( string $id ) : ?string {
		if ( strlen( $id ) > 255 ) {
			return 'ratePage-contest-id-too-long';
		}
		if ( strlen( $id ) > 0 && !ctype_alnum( $id ) ) {
			return 'ratePage-contest-id-invalid';
		}

		return null;
	}

	public static function checkContestExists( $id ) : bool {
		if ( !$id ) {
			return true;
		}

		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$res = $dbr->selectField( 'ratepage_contest',
			'rpc_id',
			[ 'rpc_id' => $id ] );

		return (bool) $res;
	}

	public static function getResultsQueryInfo( string $contestId, int $minRating, int $maxRating ) : array {
		$res = [
			'tables' => [ 'ratepage_vote' ],
			'fields' => [
				'rv_page_id',
				'ans_avg' => 'AVG(rv_answer)',
				'ans_count' => 'COUNT(rv_answer)'
			],
			'conds' => [ 'rv_contest' => $contestId ],
			'options' => [ 'GROUP BY' => 'rv_page_id' ]
		];

		for ( $i = $minRating; $i <= $maxRating; $i++ ) {
			$res['fields']["ans_$i"] = "sum(case when rv_answer = $i then 1 else 0 end)";
		}

		return $res;
	}
}
