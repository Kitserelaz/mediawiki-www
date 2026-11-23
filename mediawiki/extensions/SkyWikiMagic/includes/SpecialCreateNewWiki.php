<?php

namespace WikiOasis\WikiOasisMagic;

use FarmDispatch;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Html\Html;
use MediaWiki\Language\RawMessage;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Helpers\WikiCategoryLabels;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use OOUI;
use Throwable;
use Wikimedia\ScopedCallback;

class SpecialCreateNewWiki extends FormSpecialPage {

	public const MAX_SITENAME_LENGTH = 128;

	private CreateWikiDatabaseUtils $databaseUtils;
	private WikiManagerFactory $wikiManagerFactory;
	private CreateWikiValidator $validator;

	public function __construct(
		CreateWikiDatabaseUtils $databaseUtils,
		WikiManagerFactory $wikiManagerFactory,
		CreateWikiValidator $validator,
	) {
		parent::__construct( 'CreateNewWiki' );

		$this->databaseUtils = $databaseUtils;
		$this->wikiManagerFactory = $wikiManagerFactory;
		$this->validator = $validator;
	}

	/**
	 * @param ?string $par
	 */
	public function execute( $par ): void {
		if ( !$this->databaseUtils->isCurrentWikiCentral() ) {
			throw new ErrorPageError( 'errorpagetitle', 'createwiki-wikinotcentralwiki' );
		}

		$request = $this->getRequest();
		if ( $par === 'progress' && (getenv('MW_FARM_RUNTIME') || getenv('MW_DOCKER')) ) {
			$this->showProgress( $request->getText( 'ticket' ) );
			return;
		}
		if ( !$par && !$request->wasPosted() && $request->getVal( 'sitename' ) !== null ) {
			$this->checkExecutePermissions( $this->getUser() );
			$sitename = trim( $request->getText( 'sitename' ) );
			if ( $sitename === '' || mb_strlen( $sitename ) > self::MAX_SITENAME_LENGTH ) {
				$this->setHeaders();
				$this->getOutput()->addWikiMsg( 'createnewwiki-inline-invalid-name', self::MAX_SITENAME_LENGTH );
				return;
			}
			$error = $this->continueWithName( $sitename );
			if ( $error ) {
				$this->setHeaders();
				$this->getOutput()->addHTML( $error->parseAsBlock() );
			}
			return;
		}

		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormFields(): array {
		if ( $this->par !== 'wiki' ) {
			return [
				'sitename' => [
					'label-message' => 'createwiki-label-sitename',
					'placeholder-message' => 'createnewwiki-placeholder-sitename',
					'type' => 'text',
					'required' => true,
					'filter-callback' => static fn ( $value ) => trim( $value ),
				],
			];
		}

                $this->getOutput()->enableOOUI();
                $this->getOutput()->addModuleStyles( [ 'oojs-ui.styles.icons-content' ] );
		$this->getOutput()->addModules( 'ext.skywiki.createNewWiki' );
		$formDescriptor = [
			'subdomainauto' => [
				'type' => 'hidden',
				'default' => $this->getRequest()->getText(
					'wpsubdomainauto', $this->getRequest()->getText( 'wpdbname' ) === '' ? '1' : '0'
				),
			],
			'dbname' => [
				'label-message' => 'createnewwiki-label-subdomain',
				'help-message' => 'createwiki-help-subdomain',
				'section' => 'placeholder-subdomain',
				#'label' => "." . $this->getConfig()->get( 'CreateWikiSubdomain' ),
				'cssclass' => 'subdomain',
				'placeholder-message' => 'createnewwiki-placeholder-subdomain',
				'default' => $this->getRequest()->getText( 'wpdbname' ),
				'type' => 'textwithbutton',
				'required' => true,
				'validation-callback' => [ $this, 'isAllowedSubdomain' ],
				'buttontype' => 'button',
				'buttonflags' => [],
				'buttonid' => 'inline-subdomain',
				'buttondefault' => '.' . $this->getConfig()->get( ConfigNames::Subdomain ),
			],
			'sitename' => [
				'section' => 'createnewwiki-placeholder-sitename',
				'label-message' => 'createwiki-label-sitename',
				'help-message' => 'createwiki-help-sitename',
				'placeholder-message' => 'createnewwiki-placeholder-sitename',
				'type' => 'text',
				'size' => 20,
				'default' => $this->getRequest()->getText( 'wpsitename' ),
			],
			'language' => [
				'section' => 'language',
				'type' => 'language',
				'label-message' => 'createwiki-label-language',
				'default' => $this->getLanguage()->getCode(),
			],
		];

		if ( $this->getConfig()->get( ConfigNames::UsePrivateWikis ) ) {
			$formDescriptor['private'] = [
				'type' => 'check',
				'label-message' => 'createwiki-label-private',
				'help-message' => 'createwiki-help-private',
			];
		}

		if ( $this->getConfig()->get( ConfigNames::Categories ) ) {
			$this->getOutput()->addModules( 'ext.skywiki.wikiCategoryIcon' );
			$formDescriptor['category'] = [
				'section' => 'category',
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'help-message' => 'createwiki-help-category',
				'options' => WikiCategoryLabels::getOptions(
					$this->getContext(), $this->getConfig()->get( ConfigNames::Categories )
				),
				'default' => 'uncategorised',
			];
			$formDescriptor['categoryIcon'] = [
                                'section' => 'category',
                                'type' => 'info',
                                'default' => new OOUI\IconWidget(
                                        [
                                                'icon' => 'folderPlaceholder',
                                                'title' => 'Folder Placeholder',
                                        ]
                                )
                        ];
		}


		return $formDescriptor;
	}

	/**
	 * @inheritDoc
	 */
	public function onSubmit( array $formData ): bool|Message {
		if ( $this->par !== 'wiki' ) {
			return $this->continueWithName( $formData['sitename'] ) ?? true;
		}


		if ( !$this->getUser()->isRegistered() ) {
			$returnQuery = [];
			foreach ( [ 'dbname', 'sitename', 'language', 'private', 'category', 'subdomainauto' ] as $field ) {
				if ( array_key_exists( $field, $formData ) ) {
					$returnQuery['wp' . $field] = $formData[$field];
				}
			}
			$this->getOutput()->redirect( SpecialPage::getTitleFor( 'CreateAccount' )->getFullURL( [
				'returnto' => $this->getPageTitle( 'wiki' )->getPrefixedDBkey(),
				'returntoquery' => wfArrayToCgi( $returnQuery ),
			] ) );
			return true;
		}

		// Recheck the creation permission before provisioning, even on direct submissions.
		$this->checkPermissions();
		$this->checkReadOnly();
		$subdomainStatus = $this->isAllowedSubdomain( $formData['dbname'] );
		if ( $subdomainStatus !== true ) {
			return $subdomainStatus;
		}

                if ( $this->getUser()->pingLimiter( 'createnewwiki' ) ) {
                        throw new ErrorPageError( 'createnewwiki', 'createnewwiki-too-many-creations' );
                }

		$wiki = $this->validator->getValidSubdomain( $formData['dbname'] ) .
			$this->getConfig()->get( ConfigNames::DatabaseSuffix );
		$url = $this->validator->getValidUrl( $wiki );
		$identityDb = null;
		$reservedWiki = null;
		$created = false;
		try {
			if ( function_exists( 'farmNumericIdsEnabled' ) && \farmNumericIdsEnabled() ) {
				require_once MW_INSTALL_PATH . '/docker/mediawiki/wiki-identity-admin.php';
				$identityDb = \farmLockDirectWikiIdentity();
				try {
					$reservedWiki = \farmReserveDirectWikiIdentity( $identityDb, parse_url( $url, PHP_URL_HOST ) );
				} catch ( \DomainException ) {
					return $this->msg( 'createwiki-error-subdomaintaken' );
				}
				$wiki = $reservedWiki;
			}
			$error = $this->createWikiWithUrl( $wiki, $url, $formData );
			$created = $error === null;
		} finally {
			if ( $reservedWiki !== null ) {
				\farmFinishDirectWikiIdentity( $identityDb, $reservedWiki, $created );
			}
			$identityDb = null;
		}
		if ( $error !== null ) {
			return ( new RawMessage( '$1' ) )->rawParams( $error );
		}

		$output = $this->getOutput();
		$output->redirect( $url );
		if ( (getenv('MW_FARM_RUNTIME') || getenv('MW_DOCKER')) ) {
			// The farm publishes the Redis event from an earlier callback on this connection.
			$this->databaseUtils->getGlobalPrimaryDB()->onTransactionCommitOrIdle(
				function () use ( $output, $wiki, $url, $formData ): void {
					$progress = $this->getProgressUrl( $wiki, $url, $formData['sitename'],
						(bool)( $formData['private'] ?? false ) );
					if ( $progress ) {
						$output->redirect( $progress, '303' );
					}
				},
				__METHOD__
			);
		}
		return true;
	}

	private function createWikiWithUrl( string $wiki, string $url, array $formData ): ?string {
		$wikiManager = $this->wikiManagerFactory->newInstance( $wiki );
		// Set the URL once the wiki is registered, before provisioning publishes its metadata.
		$domainHook = $this->getHookContainer()->scopedRegister(
			'CreateWikiCreation',
			function ( string $createdWiki ) use ( $wiki, $url ): void {
				if ( $createdWiki === $wiki ) {
					$this->databaseUtils->getGlobalPrimaryDB()->newUpdateQueryBuilder()
						->update( 'cw_wikis' )
						->set( [ 'wiki_url' => $url ] )
						->where( [ 'wiki_dbname' => $wiki, 'wiki_url' => null ] )
						->caller( __METHOD__ )
						->execute();
				}
			}
		);
		try {
			return $wikiManager->create(
				sitename: $formData['sitename'],
				language: $formData['language'],
				private: $formData['private'] ?? false,
				category: $formData['category'] ?? 'uncategorised',
				requester: $this->getContext()->getUser()->getName(),
				actor: $this->getContext()->getUser()->getName(),
				reason: $this->msg( 'createnewwiki-creation-reason' )->inContentLanguage()->text(),
				extra: []
			);
		} finally {
			ScopedCallback::consume( $domainHook );
		}
	}

	private function getProgressUrl( string $wiki, string $url, string $siteName, bool $private ): ?string {
		try {
			$redis = FarmDispatch::connect();
			$event = $redis->hGet( FarmDispatch::statusKey( $wiki ), 'event' );
			if ( !$event ) {
				return null;
			}
			$token = bin2hex( random_bytes( 32 ) );
			$ticket = json_encode( [
				'event' => $event,
				'wiki' => $wiki,
				'host' => parse_url( $url, PHP_URL_HOST ),
				'private' => $private,
				'owner' => $this->getUser()->getId(),
				'siteName' => $siteName,
			], JSON_THROW_ON_ERROR );
			if ( $redis->setex( FarmDispatch::creationTicketKey( $token ), 86400, $ticket ) ) {
				return $this->getPageTitle( 'progress' )->getLocalURL( [ 'ticket' => $token ] );
			}
		} catch ( Throwable $error ) {
			wfLogWarning( 'Could not open wiki creation progress: ' . $error->getMessage() );
		}
		return null;
	}

	private function showProgress( string $token ): void {
		require_once dirname( __DIR__, 3 ) . '/docker/mediawiki/dispatch.php';
		$this->setHeaders();
		$output = $this->getOutput();
		$output->disableClientCache();
		$this->getRequest()->response()->header( 'Referrer-Policy: no-referrer' );
		$ticket = null;
		if ( $this->getUser()->isRegistered() && preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
			$ticket = json_decode( FarmDispatch::connect()->get( FarmDispatch::creationTicketKey( $token ) ) ?: 'null', true );
		}
		if ( !$ticket || $ticket['owner'] !== $this->getUser()->getId() ) {
			$output->setStatusCode( 404 );
			$output->addWikiMsg( 'createnewwiki-progress-unavailable' );
			return;
		}

		$output->setPageTitleMsg( $this->msg( 'createnewwiki-progress-title' )->plaintextParams( $ticket['siteName'] ) );
		$output->addHTML( Html::element( 'p', [ 'id' => 'skywiki-creation-status', 'role' => 'status' ],
			$this->msg( 'createnewwiki-progress-wait' )->text() ) );
		$output->addHTML( Html::element( 'a', [ 'id' => 'skywiki-creation-link',
			'href' => 'https://' . $ticket['host'] ], $this->msg( 'createnewwiki-progress-open' )->text() ) );
		$output->addJsConfigVars( 'wgSkyWikiCreationEvents', '/wiki-creation/events?ticket=' . $token );
		$output->addModules( 'ext.skywiki.creationProgress' );
	}

	private function continueWithName( string $sitename ): ?Message {
		$subdomain = ( new SubdomainSuggester( $this->getSubdomainValidator() ) )->suggest(
			$sitename, $this->getLanguage()->getCode()
		);
		if ( $subdomain === null ) {
			return $this->msg( 'createnewwiki-subdomain-unavailable' );
		}

		$this->getOutput()->redirect( $this->getPageTitle( 'wiki' )->getFullURL( [
			'wpsitename' => $sitename,
			'wpdbname' => $subdomain,
			'wpsubdomainauto' => '1',
		] ) );
		return null;
	}

	public function isValidDatabase( ?string $dbname ): bool|string|Message {
		if ( !$dbname || ctype_space( $dbname ) ) {
			return $this->msg( 'htmlform-required' );
		}

		$wikiManager = $this->wikiManagerFactory->newInstance( $dbname . 'wiki' );
		$check = $this->validator->validateDatabaseName(
			dbname: $dbname . 'wiki',
			exists: false
		);

		if ( $check ) {
			// Will return a string — the error it received
			return $check;
		}

		return true;
	}

	public function isAllowedSubdomain( ?string $subdomain ): bool|Message {
		return $this->getSubdomainValidator()->validate( $subdomain );
	}

	private function getSubdomainValidator(): SubdomainValidator {
		$config = $this->getConfig();
		return new SubdomainValidator(
			$this->validator, $config->get( ConfigNames::Subdomain ), $config->get( ConfigNames::DisallowedSubdomains )
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function alterForm( HTMLForm $form ): void {
		$form->setTitle( $this->getPageTitle( $this->par === 'wiki' ? 'wiki' : false ) );
		if ( $this->par !== 'wiki' ) {
			$form->setSubmitTextMsg( 'createnewwiki-continue' );
		} elseif ( !$this->getUser()->isRegistered() ) {
			$form->setSubmitTextMsg( 'createnewwiki-account-setup' );
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'wiki';
	}

	/**
	 * @inheritDoc
	 */
	protected function preHtml(): string {
		if ( $this->par !== 'wiki' ) {
			return $this->msg( 'createnewwiki-name-intro' )->parseAsBlock();
		}

		return $this->msg( 'createnewwiki-description' ) . "
			<style>
				#inline-subdomain button {
					cursor:auto;
					pointer-events:none
				}
				#mw-htmlform-placeholder-subdomain .mw-htmlform-field-HTMLInfoField {
					margin-top: 1em;
				}
				#mw-input-wpcategory .oo-ui-dropdownWidget-handle {
					border-top-right-radius: 0;
					border-bottom-right-radius: 0;
				}
				.oo-ui-fieldLayout-body .oo-ui-iconElement-icon {
					margin-right: .25rem;
				}
				.oo-ui-panelLayout {
                                        margin: 0;
                                        padding: 0;
                                        border: none;
                                        width: 100%;
                                }
                                #mw-content-text .oo-ui-fieldsetLayout-header {
                                        display: none;
                                }
                                .oo-ui-fieldsetLayout-group > .oo-ui-widget > div {
                                        display: flex;
                                        align-items: center;
                                }
                                .oo-ui-fieldsetLayout-group > .oo-ui-widget > div > .mw-htmlform-field-HTMLInfoField:has(#mw-input-wpcategoryIcon) {
                                        background: #2b52c6;
                                        border-radius: 0 0.5em 0.5em 0;
                                        width: 24px;
                                        padding: 0.3em 0em 0em 0.15em;
                                        box-sizing: border-box;
                                        color: white;
					align-self: end;
					height: 32px;
                                }
                                .oo-ui-fieldsetLayout-group > .oo-ui-widget > div > .mw-htmlform-field-HTMLInfoField:has(#mw-input-wpcategoryIcon):has(.fa-solid) {
                                        display: flex;
                                        align-items: center;
                                        justify-content: center;
                                        padding: 0rem 0.3rem 0rem 0.23rem;
                                        font-size: 0.9em;
                                }
                                 .mw-htmlform-field-HTMLInfoField .oo-ui-iconWidget {
                                        filter: invert(100%);
                                        scale: 0.625;
                                }
                                .oo-ui-fieldsetLayout-group > .oo-ui-widget > div > * {
                                        margin: 0;
				}
				/* Keep the category icon beside the dropdown when help wraps below it. */
				.oo-ui-fieldsetLayout-group > .oo-ui-widget > #mw-htmlform-category {
					display: grid;
					grid-template-columns: max-content 24px minmax(0, 1fr);
				}
				#mw-htmlform-category > .mw-htmlform-field-HTMLSelectField,
				#mw-htmlform-category > .mw-htmlform-field-HTMLSelectField > .oo-ui-fieldLayout-body {
					display: contents;
				}
				#mw-htmlform-category .mw-htmlform-field-HTMLSelectField .oo-ui-fieldLayout-header {
					grid-area: 1 / 1 / 2 / -1;
				}
				#mw-htmlform-category #mw-input-wpcategory {
					vertical-align: top;
				}
				#mw-htmlform-category .mw-htmlform-field-HTMLSelectField .oo-ui-fieldLayout-field {
					grid-area: 2 / 1;
				}
				#mw-htmlform-category > .mw-htmlform-field-HTMLInfoField {
					grid-area: 2 / 2;
				}
				#mw-htmlform-category .oo-ui-inline-help {
					grid-area: 3 / 1 / 4 / -1;
				}
				#mw-htmlform-category .oo-ui-fieldLayout-messages {
					grid-area: 4 / 1 / 5 / -1;
				}
                                #mw-content-text .mw-htmlform-field-HTMLTextField {
                                        width: 100%;
                                        max-width: 50em;
                                        margin-right: 1rem;
                                }
			</style>
		";
	}
	public function getRestriction(): string {
		return $this->getUser()->isRegistered() ? 'createnewwiki' : '';
	}

}
