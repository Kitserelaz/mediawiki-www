<?php

namespace RatePage\Pager;

use MediaWiki\Linker\LinkRenderer;
use MWException;
use RatePage\Special\RatePageContests;
use SpecialPage;
use TablePager;

class ContestsPager extends TablePager {

	protected $linkRenderer;

	public $mPage;

	public function __construct( RatePageContests $page, LinkRenderer $linkRenderer ) {
		$this->mPage = $page;
		$this->linkRenderer = $linkRenderer;
		parent::__construct( $this->mPage->getContext() );
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
		return [ 'tables' => [ 'ratepage_contest' ],
			'fields' => [ 'rpc_id',
				'rpc_description',
				'rpc_enabled' ] ];
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
	 * @return string
	 * @throws MWException
	 */
	function formatValue( $name, $value ) : string {
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'rpc_id':
				return $this->linkRenderer->makeLink( SpecialPage::getTitleFor( 'RatePageContests',
					$value ),
					$value );
			case 'rpc_description':
				return $this->linkRenderer->makeLink( SpecialPage::getTitleFor( 'RatePageContests',
					$row->rpc_id ),
					$value );
			case 'rpc_enabled':
				if ( $value ) {
					return $this->msg( 'ratePage-contest-enabled' )->parse();
				} else {
					return $this->msg( 'ratePage-contest-disabled' )->parse();
				}
			default:
				throw new MWException( "Unknown row type $name!" );
		}
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
		return 'rpc_id';
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

		$headers = [ 'rpc_id' => 'ratePage-contests-list-id',
			'rpc_description' => 'ratePage-contests-list-description',
			'rpc_enabled' => 'ratePage-contests-list-enabled', ];

		foreach ( $headers as &$msg ) {
			$msg = $this->msg( $msg )->text();
		}

		return $headers;
	}
}