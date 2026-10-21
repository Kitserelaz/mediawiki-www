<?php

/**
 * Some of this code is based on the AbuseFilter extension.
 * You can find the extension's code and list of authors here:
 * https://github.com/wikimedia/mediawiki-extensions-AbuseFilter
 *
 * AbuseFilter's code is licensed under GPLv2
 */

namespace RatePage\Special;

use BadRequestError;
use Html;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use MediaWiki\Widget\CheckMatrixWidget;
use OOUI;
use OOUI\FieldLayout;
use PermissionsError;
use RatePage\ContestDB;
use RatePage\Pager\ContestResultsPager;
use RatePage\Pager\ContestsPager;
use RatePage\Rights;
use SpecialPage;
use Status;
use stdClass;
use Title;
use WebRequest;
use Wikimedia\Rdbms\DBError;
use Xml;

class RatePageContests extends SpecialPage {
	public static $mLoadedRow = null;

	/** @var string */
	private $contestId;

	/** @var string */
	private $action;

	private $mPermManager;

	public function __construct() {
		parent::__construct( 'RatePageContests',
			'ratepage-contests-view-list' );

		$this->mPermManager = MediaWikiServices::getInstance()->getPermissionManager();
	}

	/**
	 * @return bool
	 */
	public function doesWrites() : bool {
		return true;
	}

	function execute( $subPage ) {
		$out = $this->getOutput();
		$request = $this->getRequest();

		$this->setHeaders();
		$this->addHelpLink( 'Extension:RatePage' );
		$out->enableOOUI();
		$out->addModuleStyles( 'ext.ratePage.contests' );

		$this->checkPermissions();

		if ( $request->getVal( 'result' ) == 'success' ) {
			$changedFilter = $request->getVal( 'changedcontest' );
			$out->wrapWikiMsg( '<p class="success">$1</p>',
				[ 'ratePage-edit-done',
					$changedFilter ] );
		}

		if ( strlen( $subPage ) ) {
			$action = '';
			$exploded = explode( '/', $subPage );
			if ( count( $exploded ) > 2 ) {
				throw new BadRequestError( 'error', 'ratepage-contests-invalid-path' );
			}
			if ( count( $exploded ) === 2 ) {
				$subPage = $exploded[0];
				$action = $exploded[1];
			}

			$this->contestId = $subPage;
			$this->action = $action;
			$this->showEditView();
		} else {
			$this->showListView();
		}

		// Links at the top
		$this->addSubtitle();
	}

	protected function showListView() {
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'ratePage-contest-list-title' )->text() );

		// New contest button
		if ( $this->userCanEdit() ) {
			$link = new OOUI\ButtonWidget( [
				'label' => $this->msg( 'ratePage-contests-new' )->text(),
				'href' => $this->getPageTitle( '!new' )->getFullURL(),
			] );
			$out->addHTML( $link );
		}

		$pager = new ContestsPager( $this,
			$this->getLinkRenderer() );

		//TODO: add some filtering crap

		$this->getOutput()->addHTML( '<br><br>' . $pager->getFullOutput()->getText() );
		$this->getOutput()->addModules( $pager->getModuleStyles() );
	}

	protected function showEditView() {
		// check permissions
		if ( !$this->userCanViewDetails() ) {
			throw new PermissionsError( 'ratepage-contests-view-details' );
		}

		$new = $this->contestId === "!new";

		if ( $new && !$this->userCanEdit() ) {
			throw new PermissionsError( 'ratepage-contests-edit' );
		}

		if ( $this->action && $this->action !== 'export' ) {
			throw new BadRequestError(
				'error',
				'ratepage-contests-invalid-path'
			);
		}

		$out = $this->getOutput();
		$request = $this->getRequest();
		$out->setPageTitle( $this->msg( 'ratePage-contest-edit-title' )->text() );

		if ( !$new && !ContestDB::checkContestExists( $this->contestId ) ) {
			$out->addHTML( Html::errorBox(
				$this->msg( 'ratePage-no-such-contest', $this->contestId )->parse()
			) );
			return;
		}

		if ( $this->action === 'export' ) {
			$exporter = new ContestResultsExporter(
				new ServiceOptions(
					ContestResultsExporter::CONSTRUCTOR_OPTIONS,
					$this->getConfig()
				),
				wfGetDB( DB_REPLICA ),
				$this,
				$this->contestId
			);
			$result = $exporter->export( ContestResultsExporter::MODE_WIKITABLE );

			$out->addHTML( new OOUI\FieldLayout(
				new OOUI\MultilineTextInputWidget( [
					'rows' => 20,
					'readOnly' => true,
					'value' => $result
				] ),
				[ 'align' => 'top' ]
			) );
			$out->addHTML( new OOUI\FieldLayout(
				new OOUI\ButtonWidget( [
					'label' => $this->msg( 'feedback-back' )->text(),
					'href' => $this->getPageTitle( $this->contestId )->getFullURL(),
					'flags' => [ 'progressive' ]
				] )
			) );
			return;
		}

		// show details
		$contest = $this->contestId;
		$newRow = $this->loadRequest( $contest );

		$editToken = $this->getRequest()->getVal( 'wpEditToken' );
		$tokenMatches = $this->getUser()->matchEditToken( $editToken,
			[ 'ratepagecontest',
				$this->contestId ],
			$this->getRequest() );

		if ( $tokenMatches && $this->userCanEdit() ) {
			$status = $this->saveContest( $newRow,
				$request );

			if ( !$status->isGood() ) {
				$err = $status->getErrors();
				$msg = $err[0]['message'];
				if ( $status->isOK() ) {
					$out->addHTML( $this->buildEditor( $newRow ) );
				} else {
					$out->addWikiMsg( $msg );
				}
			} else {
				if ( $status->getValue() === false ) {
					// No change
					$out->redirect( $this->getPageTitle()->getLocalURL() );
				} else {
					$new_id = $status->getValue();
					$out->redirect( $this->getPageTitle( $new_id )->getLocalURL( [ 'result' => 'success',
						'changedcontest' => $new_id, ] ) );
				}
			}
		} else {
			if ( $tokenMatches ) {
				// Lost rights meanwhile
				$out->addHTML( Html::errorBox( $this->msg( 'ratePage-edit-notallowed' )->text() ) );
			} elseif ( $request->wasPosted() ) {
				// Warn the user to re-attempt save
				$out->addHTML( Html::warningBox( $this->msg( 'ratePage-edit-token-not-match' )->text() ) );
			}

			$out->addHTML( $this->buildEditor( $newRow ) );
		}
	}

	protected function buildEditor( $row ) : string {
		$new = $this->contestId == "!new";

		// Figure out which permissions were selected
		$selectedPermissions = [];
		if ( !$new ) {
			$selectedPermissions = array_merge( $selectedPermissions,
				array_map( function ( $a ) {
					return "vote-$a";
				},
					explode( ',',
						$row->rpc_allowed_to_vote ) ) );

			$selectedPermissions = array_merge( $selectedPermissions,
				array_map( function ( $a ) {
					return "see-$a";
				},
					explode( ',',
						$row->rpc_allowed_to_see ) ) );
		}

		// Read-only attribute
		$readOnlyAttrib = [];

		if ( !$this->userCanEdit() ) {
			$readOnlyAttrib['disabled'] = 'disabled';
		}

		$form = '';

		$fieldset = new OOUI\FieldsetLayout( [ 'label' => $this->msg( 'ratePage-contest-edit-main' )->text() ] );

		$fieldset->addItems( [
			//TODO: add some info for end user on allowed characters
			//TODO: add edit filter
			new FieldLayout(
				new OOUI\TextInputWidget(
					[
						'value' => $new ? '' : $row->rpc_id,
						'disabled' => !$new
					] + ( $new ? [ 'name' => 'wpContestId' ] : [] )
				),
				[
					'label' => $this->msg( 'ratePage-edit-id' )->text(),
					'align' => 'top'
				]
			),
			new FieldLayout(
				new OOUI\TextInputWidget(
					[
						'name' => 'wpContestDescription',
						'value' => isset( $row->rpc_description ) ? $row->rpc_description : ''
					] + $readOnlyAttrib
				),
				[
					'label' => $this->msg( 'ratePage-edit-description' )->text(),
					'align' => 'top'
				]
			),
			new FieldLayout(
				new OOUI\CheckboxInputWidget(
					[
						'name' => 'wpContestEnabled',
						'id' => 'wpContestEnabled',
						'selected' => isset( $row->rpc_enabled ) ? $row->rpc_enabled : 1
					] + $readOnlyAttrib
				),
				[
					'label' => $this->msg( 'ratePage-edit-enabled' )->text(),
					'align' => 'inline'
				]
			),
			new FieldLayout(
				new OOUI\CheckboxInputWidget(
					[
						'name' => 'wpSeeBeforeVote',
						'id' => 'wpSeeBeforeVote',
						'selected' => isset( $row->rpc_see_before_vote ) ? $row->rpc_see_before_vote : 0
					] + $readOnlyAttrib
				),
				[
					'label' => $this->msg( 'ratePage-edit-see-before-vote' )->text(),
					'align' => 'inline'
				]
			),
			new FieldLayout(
				new CheckMatrixWidget(
					[
						'name' => 'wpContestPermissions',
						'columns' => [
							$this->msg( 'ratePage-edit-allowed-to-vote' )->text() => 'vote',
							$this->msg( 'ratePage-edit-allowed-to-see' )->text() => 'see'
						],
						'rows' => Rights::getGroupsAsColumns( $this->getContext() ),
						'values' => $selectedPermissions
					] + $readOnlyAttrib
				)
			),
		] );

		$form .= $fieldset;

		if ( !$new ) {
			$form .= Html::hidden( 'wpContestId',
				$this->contestId );
		}

		//TODO: add a button for clearing results
		if ( $this->userCanEdit() ) {
			$form .= new OOUI\FieldLayout( new OOUI\ButtonInputWidget( [ 'type' => 'submit',
				'label' => $this->msg( 'ratePage-edit-save' )->text(),
				'useInputTag' => true,
				'accesskey' => 's',
				'flags' => [ 'progressive',
					'primary' ] ] ) );
			$form .= Html::hidden( 'wpEditToken',
				$this->getUser()->getEditToken( [ 'ratepagecontest',
					$this->contestId ] ) );
		}

		$form = Xml::tags(
			'form',
			[
				'action' => $this->getPageTitle( $this->contestId )->getFullURL(),
				'method' => 'post'
			],
			$form
		);

		if ( !$new ) {
			$pager = new ContestResultsPager(
				$row->rpc_id,
				$this->getContext(),
				$this->getLinkRenderer()
			);
			$form .= '<br><br>' . $pager->getFullOutput()->getText();
			$form .= new OOUI\FieldLayout(
				new OOUI\ButtonWidget( [
					'label' => $this->msg( 'ratePage-edit-export' )->text(),
					'href' => $this->getPageTitle( $this->contestId . '/export' )->getFullURL(),
					'flags' => [ 'progressive' ]
				] )
			);

			$this->getOutput()->addModules( $pager->getModuleStyles() );
		}

		return $form;
	}

	/**
	 * @param $row
	 * @param WebRequest $request
	 *
	 * @return Status
	 */
	protected function saveContest( $row, WebRequest $request ) : Status {
		$validationStatus = Status::newGood();

		$id = $request->getVal( 'wpContestId' );
		if ( !$id ) {
			$validationStatus->error( 'ratePage-contest-missing-id' );

			return $validationStatus;
		}

		$errorKey = ContestDB::validateId( $id );
		if ( $errorKey ) {
			$validationStatus->error( $errorKey );

			return $validationStatus;
		}

		if ( !$this->userCanEdit() ) {
			$validationStatus->error( 'ratePage-edit-notallowed' );

			return $validationStatus;
		}

		try {
			ContestDB::saveContest( $row,
				$this->getContext() );
		} catch ( DBError $dbe ) {
			$validationStatus->error( 'ratePage-duplicate-id' );

			return $validationStatus;
		}

		$validationStatus->value = $id;

		return $validationStatus;
	}

	protected function loadRequest( $contest ) {
		$row = self::$mLoadedRow;
		$request = $this->getRequest();

		if ( !is_null( $row ) ) {
			return $row;
		} elseif ( $request->wasPosted() ) {
			// Nothing, we do it all later
		} else {
			return ContestDB::loadContest( $contest );
		}

		$row = new stdClass();
		$textLoads = [
			'rpc_id' => 'wpContestId',
			'rpc_description' => 'wpContestDescription'
		];

		foreach ( $textLoads as $col => $field ) {
			if ( $col == 'rpc_id' && isset( $row->rpc_id ) ) {
				// Disallow overwriting contest ID
				continue;
			}

			$row->$col = trim( $request->getVal( $field, '' ) );
		}

		$row->rpc_enabled = $request->getCheck( 'wpContestEnabled' );
		$row->rpc_see_before_vote = $request->getCheck( 'wpSeeBeforeVote' );

		$perm = $request->getArray( 'wpContestPermissions' ) ?? [];
		$pVote = [];
		$pSee = [];

		foreach ( $perm as $p ) {
			if ( strpos( $p,
					'vote-' ) === 0 ) {
				$pVote[] = substr( $p,
					5 );
			} elseif ( strpos( $p,
					'see-' ) === 0 ) {
				$pSee[] = substr( $p,
					4 );
			}
		}

		$row->rpc_allowed_to_vote = implode( ',', $pVote );
		$row->rpc_allowed_to_see = implode( ',', $pSee );

		self::$mLoadedRow = $row;

		return $row;
	}

	protected function addSubtitle() {
		$elems = [];
		$lr = $this->getLinkRenderer();
		$out = $this->getOutput();

		if ( isset( $this->contestId ) ) {
			if ( $this->contestId == "!new" ) {
				$elems[] = $this->msg( 'ratePage-new-contest-sub' )->escaped();
			} else {
				$elems[] = $this->msg( 'ratePage-edit-contest-sub', $this->contestId )->escaped();
			}
		}

		$homePage = Title::newFromText( 'Special:RatePageContests' );
		$elems[] = $lr->makeLink( $homePage, $this->msg( 'ratePage-contest-home' )->text() );

		$linkStr = $this->getLanguage()->pipeList( $elems );
		$out->getOutput()->setSubtitle( $linkStr );
	}

	public function userCanViewDetails() : bool {
		return $this->mPermManager->userHasRight( $this->getUser(),
			'ratepage-contests-view-details' );
	}

	public function userCanEdit() : bool {
		return $this->mPermManager->userHasRight( $this->getUser(),
			'ratepage-contests-edit' );
	}

	public function userCanClearResults() : bool {
		return $this->mPermManager->userHasRight( $this->getUser(),
			'ratepage-contests-clear' );
	}
}
