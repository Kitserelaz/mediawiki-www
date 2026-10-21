<?php

namespace RatePage\Pager;

use IContextSource;
use MediaWiki\Linker\LinkRenderer;
use Message;
use MWException;
use RatePage\ContestDB;
use TablePager;
use Title;

class ContestResultsPager extends TablePager {

	public $contestId;
	private $ratingMin, $ratingMax, $linkRenderer;

	public function __construct( $contestId, IContextSource $context, LinkRenderer $linkRenderer ) {
		$this->contestId = $contestId;
		$this->linkRenderer = $linkRenderer;

		$this->ratingMin = $this->getConfig()->get( 'RPRatingMin' );
		$this->ratingMax = $this->getConfig()->get( 'RPRatingMax' );

		parent::__construct( $context,
			$linkRenderer );
	}

	/**
	 * Provides all parameters needed for the main paged query. It returns
	 * an associative array with the following elements:
	 *    tables => Table(s) for passing to Database::select()
	 *    fields => Field(s) for passing to Database::select(), may be *
	 *    conds => WHERE conditions
	 *    options => option array
	 *    join_conds => JOIN conditions
	 *
	 * @return array
	 */
	function getQueryInfo() : array {
		return ContestDB::getResultsQueryInfo( $this->contestId, $this->ratingMin, $this->ratingMax );
	}

	/**
	 * Return true if the named field should be sortable by the UI, false
	 * otherwise
	 *
	 * @param string $field
	 *
	 * @return bool
	 */
	function isFieldSortable( $field ) : bool {
		return true;
	}

	/**
	 * Format a table cell. The return value should be HTML, but use an empty
	 * string not &#160; for empty cells. Do not include the <td> and </td>.
	 *
	 * The current result row is available as $this->mCurrentRow, in case you
	 * need more context.
	 *
	 * @protected
	 *
	 * @param string $name The database field name
	 * @param string $value The value retrieved from the database
	 *
	 * @return Message|string
	 * @throws MWException
	 */
	function formatValue( $name, $value ) {
		if ( strpos( $name,
				'ans_' ) === 0 ) {
			return $this->getLanguage()->formatNum( $value );
		}

		if ( $name == 'rv_page_id' ) {
			$title = Title::newFromID( $value );

			if ( $title ) {
				return $this->linkRenderer->makeLink( $title );
			} else {
				return $this->msg( 'ratePage-deleted-page', $value )->parse();
			}
		}

		throw new MWException( "Unknown row type $name!" );
	}

	/**
	 * The database field name used as a default sort order.
	 *
	 * Note that this field will only be sorted on if isFieldSortable returns
	 * true for this field. If not (e.g. paginating on multiple columns), this
	 * should return empty string, and getIndexField should be overridden.
	 *
	 * @protected
	 *
	 * @return string
	 */
	function getDefaultSort() : string {
		return 'rv_page_id';
	}

	/**
	 * An array mapping database field names to a textual description of the
	 * field name, for use in the table header. The description should be plain
	 * text, it will be HTML-escaped later.
	 *
	 * @return array
	 */
	function getFieldNames() : array {
		static $headers = null;

		if ( !empty( $headers ) ) {
			return $headers;
		}

		$headers = [ 'rv_page_id' => 'ratePage-results-list-page',
			'ans_avg' => 'ratePage-results-list-avg',
			'ans_count' => 'ratePage-results-list-count' ];

		foreach ( $headers as &$msg ) {
			$msg = $this->msg( $msg )->text();
		}

		for ( $i = $this->ratingMin; $i <= $this->ratingMax; $i++ ) {
			$headers["ans_$i"] = $this->msg( 'ratePage-results-list-ans', $i )->text();
		}

		return $headers;
	}
}