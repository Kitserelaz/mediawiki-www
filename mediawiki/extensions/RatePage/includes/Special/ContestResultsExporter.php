<?php

namespace RatePage\Special;

use MediaWiki\Config\ServiceOptions;
use MessageLocalizer;
use RatePage\ContestDB;
use Title;
use Wikimedia\Rdbms\DBConnRef;

class ContestResultsExporter {

	public const CONSTRUCTOR_OPTIONS = [
		'RPRatingMin',
		'RPRatingMax'
	];

	public const MODE_WIKITABLE = 1;

	/** @var ServiceOptions */
	private $options;

	/** @var DBConnRef */
	private $db;

	/** @var MessageLocalizer */
	private $msg;

	/** @var string */
	private $contestId;

	public function __construct(
		ServiceOptions $options,
		DBConnRef $db,
		MessageLocalizer $msg,
		string $contestId
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->options = $options;
		$this->db = $db;
		$this->msg = $msg;
		$this->contestId = $contestId;
	}

	/**
	 * @param int $mode one of MODE_* constants
	 *
	 * @return string
	 */
	public function export( int $mode ) : string {
		$data = $this->getData();
		$res = $this->getHeader();
		$minRating = $this->options->get( 'RPRatingMin' );
		$maxRating = $this->options->get( 'RPRatingMax' );

		foreach ( $data as $row ) {
			$title = Title::newFromRow( $row );
			$res .= "\n|-\n! [[:" . $title->getPrefixedText() . ']]';
			$res .= "\n| " . $row->ans_avg;
			$res .= "\n| " . $row->ans_count;

			for ( $i = $minRating; $i <= $maxRating; $i++ ) {
				$name = "ans_$i";
				$res .= "\n| " . $row->$name;
			}
		}
		$res .= "\n|}";

		return $res;
	}

	private function getData() : iterable {
		$queryInfo = ContestDB::getResultsQueryInfo(
			$this->contestId,
			$this->options->get( 'RPRatingMin' ),
			$this->options->get( 'RPRatingMax' )
		);

		$queryInfo['tables'][] = 'page';
		$queryInfo['fields'][] = 'page_namespace';
		$queryInfo['fields'][] = 'page_title';
		$queryInfo['join_conds']['page'] = [ 'JOIN', 'rv_page_id = page_id' ];

		return $this->db->select(
			$queryInfo['tables'],
			$queryInfo['fields'],
			$queryInfo['conds'],
			__METHOD__,
			$queryInfo['options'],
			$queryInfo['join_conds']
		);
	}

	private function getHeader() : string {
		$res = '{| class="wikitable sortable"';
		$res .= "\n! " . $this->msg->msg( 'ratePage-results-list-page' )->plain();
		$res .= "\n! " . $this->msg->msg( 'ratePage-results-list-avg' )->plain();
		$res .= "\n! " . $this->msg->msg( 'ratePage-results-list-count' )->plain();

		for (
			$i = $this->options->get( 'RPRatingMin' );
			$i <= $this->options->get( 'RPRatingMax' );
			$i++
		) {
			$res .= "\n! " . $this->msg->msg( 'ratePage-results-list-ans', $i )->plain();
		}

		return $res;
	}
}