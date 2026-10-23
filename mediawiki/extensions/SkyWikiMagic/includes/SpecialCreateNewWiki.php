<?php

namespace WikiOasis\WikiOasisMagic;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use OOUI;

class SpecialCreateNewWiki extends FormSpecialPage {

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
				'section' => 'placeholder-subdomain',
				#'label' => "." . $this->getConfig()->get( 'CreateWikiSubdomain' ),
				'cssclass' => 'subdomain',
				'placeholder-message' => 'createnewwiki-placeholder-subdomain',
				'default' => $this->getRequest()->getText( 'wpdbname' ),
				'type' => 'textwithbutton',
				'required' => true,
				'validation-callback' => [ $this->validator, 'validateSubdomain' ],
				'buttontype' => 'button',
				'buttonflags' => [],
				'buttonid' => 'inline-subdomain',
				'buttondefault' => '.' . $this->getConfig()->get( ConfigNames::Subdomain ),
			],
			'sitename' => [
				'section' => 'createnewwiki-placeholder-sitename',
				'label-message' => 'createwiki-label-sitename',
				'placeholder-message' => 'createnewwiki-placeholder-sitename',
				'type' => 'text',
				'size' => 20,
				'default' => $this->getRequest()->getText( 'wpsitename' ),
			],
			'language' => [
				'section' => 'language',
				'type' => 'language',
				'label-message' => 'createwiki-label-language',
				'default' => 'en',
			],
		];

		if ( $this->getConfig()->get( ConfigNames::UsePrivateWikis ) ) {
			$formDescriptor['private'] = [
				'type' => 'check',
				'label-message' => 'createwiki-label-private',
			];
		}

		if ( $this->getConfig()->get( ConfigNames::Categories ) ) {
                        $formDescriptor['category'] = [
                                'section' => 'category',
                                'type' => 'select',
                                'label-message' => 'createwiki-label-category',
                                'options' => $this->getConfig()->get( ConfigNames::Categories ),
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
			$subdomain = $this->suggestSubdomain( $formData['sitename'] );
			if ( $subdomain === null ) {
				return $this->msg( 'createnewwiki-subdomain-unavailable' );
			}

			$this->getOutput()->redirect( $this->getPageTitle( 'wiki' )->getFullURL( [
				'wpsitename' => trim( $formData['sitename'] ),
				'wpdbname' => $subdomain,
				'wpsubdomainauto' => '1',
			] ) );
			return true;
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

                if ( $this->getUser()->pingLimiter( 'createnewwiki' ) ) {
                        throw new ErrorPageError( 'createnewwiki', 'createnewwiki-too-many-creations' );
                }

		$wikiManager = $this->wikiManagerFactory->newInstance( $formData['dbname'] . 'wiki' );
		$wikiManager->create(
			sitename: $formData['sitename'],
			language: $formData['language'],
			private: $formData['private'] ?? false,
			category: $formData['category'] ?? 'uncategorised',
			requester: $this->getContext()->getUser()->getName(),
			actor: $this->getContext()->getUser()->getName(),
			reason: $this->msg( 'createnewwiki-creation-reason' )->inContentLanguage()->text(),
			extra: []
		);
		$this->getOutput()->addHTML(
			Html::successBox(
				$this->msg( 'createwiki-success' )->escaped()
			)
		);
		return true;
	}

	/**
	 * Suggest a valid, currently available subdomain; final submission validates it again.
	 */
	private function suggestSubdomain( string $sitename ): ?string {
		return ( new SubdomainSuggester( $this->validator ) )->suggest( $sitename );
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

	public function isAllowedSubdomain( ?string $subdomain ): bool|string|Message {
		$check = $this->validator->validateSubdomain(
                        subdomain: $subdomain
                );

                if ( $check ) {
                        // Will return a string — the error it received
                        return $check;
                }
		return true;
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
                                #mw-content-text .mw-htmlform-field-HTMLTextField {
                                        width: 100%;
                                        max-width: 50em;
                                        margin-right: 1rem;
                                }
			</style>
                        <script>
                                const icons = {
                                        'Art & Architecture': 'palette',
                                        'Automotive': 'car',
                                        'Business & Finance': 'coins',
                                        'Community': 'comments',
                                        'Education': 'school',
                                        'Electronics': 'computer',
                                        'Entertainment': 'film',
                                        'Fandom': 'heart',
                                        'Fantasy': 'dragon',
                                        'Gaming': 'gamepad',
                                        'Geography': 'map',
                                        'History': 'landmark-dome',
                                        'Humour/Satire': 'face-laugh',
                                        'Language/Linguistics': 'language',
                                        'Leisure': 'mountain-city',
                                        'Literature/Writing': 'book',
                                        'Media/Journalism': 'newspaper',
                                        'Medicine/Medical': 'pills',
                                        'Military/War': 'bomb',
                                        'Music': 'music',
                                        'Podcast': 'podcast',
                                        'Politics': 'bullhorn',
                                        'Private': 'lock',
                                        'Religion': 'person-praying',
                                        'Science': 'flask',
                                        'Software/Computing': 'database',
                                        'Song Contest': 'guitar',
                                        'Sports': 'baseball',
                                        'Uncategorised': 'folder',
				};
				 addEventListener('load', () => {
					const observer = new MutationObserver(() => {
mw.loader.using( ['oojs-ui-core', 'ext.fontawesome', 'ext.fontawesome.far', 'ext.fontawesome.fas', 'ext.fontawesome.fab'], () => {
						observer.disconnect();
						console.log('ready!')
                                                document.querySelector('#mw-input-wpcategoryIcon').innerHTML = `<i class=\"fa-solid fa-\${icons[document.querySelector('#mw-input-wpcategory .oo-ui-dropdownWidget .oo-ui-labelElement-label').textContent.trim()] ?? 'question'}\"></i>`//'&#xf53f;'
						const mutationObserver = new MutationObserver(() => {
							console.log('OK IT CHANGED')
                                                        document.querySelector('#mw-input-wpcategoryIcon').innerHTML = `<i class=\"fa-solid fa-\${icons[document.querySelector('#mw-input-wpcategory .oo-ui-dropdownWidget .oo-ui-labelElement-label').textContent.trim()] ?? 'question'}\"></i>`//'&#xf53f;'

                                                });
console.log(document.querySelector('#mw-input-wpcategory .oo-ui-dropdownWidget .oo-ui-labelElement-label'))
                                                mutationObserver.observe(document.querySelector('#mw-input-wpcategory .oo-ui-dropdownWidget .oo-ui-labelElement-label'), {
                                                        characterData: true,
                                                        childList: true,
                                                        subtree: true
                                                })
})
                                        });
                                        observer.observe(document.querySelector('#mw-input-wpcategory'), {
                                                childList: true,
                                                subtree: true,
					});
                                })
                        </script>
		";
	}
	public function getRestriction(): string {
		return $this->getUser()->isRegistered() ? 'createnewwiki' : '';
	}

}
