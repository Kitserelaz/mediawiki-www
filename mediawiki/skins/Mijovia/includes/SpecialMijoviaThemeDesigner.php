<?php

namespace MediaWiki\Skins\Mijovia;

use MediaWiki\Content\JsonContent;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\ResourceLoader\SkinModule;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDBAccessObject;

final class SpecialMijoviaThemeDesigner extends SpecialPage {
	private const TOKEN_SALT = 'mijovia-theme-designer';

	public function __construct() {
		parent::__construct( 'MijoviaThemeDesigner' );
	}

	public function getRestriction(): string {
		return ThemeDesignerConfig::RIGHT;
	}

	public function execute( $subPage ) {
		$this->checkPermissions();
		$this->getOutput()->disableClientCache();
		$request = $this->getRequest();
		$user = $this->getUser();
		$validPost = $request->wasPosted() &&
			$user->matchEditToken( $request->getText( 'token' ), self::TOKEN_SALT, $request );
		if ( $subPage === 'preview' ) {
			$this->sendPreview( $validPost );
			return;
		}

		$this->setHeaders();
		$services = MediaWikiServices::getInstance();
		$options = $services->getUserOptionsManager();
		$published = ThemeDesignerConfig::revision( IDBAccessObject::READ_LATEST );
		$revisionId = $published?->getId() ?? 0;
		$defaults = ThemeDesignerConfig::resolve( [] )['values'];
		$publishedValues = ThemeDesignerConfig::resolve( ThemeDesignerConfig::valuesFromRevision( $published ) )['values'];
		$draft = ThemeDesignerConfig::decode( (string)$options->getOption( $user, ThemeDesignerConfig::DRAFT ) );
		$values = is_array( $draft['values'] ?? null ) ? $draft['values'] : $publishedValues;
		$base = is_int( $draft['base'] ?? null ) ? $draft['base'] : $revisionId;
		$article = is_string( $draft['article'] ?? null ) ? $draft['article'] : '';
		$notice = null;
		$errors = [];

		if ( $request->wasPosted() ) {
			if ( !$validPost ) {
				$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'sessionfailure' )->escaped() ) );
			} else {
				$this->checkReadOnly();
				$action = $request->getVal( 'theme-action' );
				$values = $this->readValues();
				$base = $request->getInt( 'base' );
				$article = $this->inputText( 'article' );
				if ( $action === 'reset' ) {
					$values = $defaults;
				}
				if ( $action === 'rebase' ) {
					$base = $request->getInt( 'published' );
				}

				// Retain the working copy even when publication encounters an edit conflict.
				if ( in_array( $action, [ 'draft', 'reset', 'rebase', 'publish' ], true ) ) {
					$options->setOption( $user, ThemeDesignerConfig::DRAFT, self::encode( [
						'values' => $values,
						'base' => $base,
						'article' => $article,
					] ) );
					$options->saveOptions( $user );
					$notice = 'mijovia-theme-draft-saved';
				}

				if ( $action === 'publish' || $action === 'restore' ) {
					$restore = $action === 'restore';
					$toPublish = $values;
					if ( $restore ) {
						$toPublish = $this->previousValues( $published );
					}
					if ( $toPublish === null ) {
						$errors[] = 'mijovia-theme-error-restore';
					} else {
						$resolved = ThemeDesignerConfig::resolve( $toPublish );
						$errors = $restore ? [] : $resolved['errors'];
						if ( !$errors ) {
							$error = $this->publish(
								$resolved['values'],
								$restore ? $request->getInt( 'published' ) : $base,
								$restore
							);
							if ( $error !== null ) {
								$errors[] = $error;
								if ( $error === 'mijovia-theme-stale' ) {
									$revisionId = ThemeDesignerConfig::revision( IDBAccessObject::READ_LATEST )?->getId() ?? 0;
								}
							} else {
								if ( !$restore ) {
									$options->setOption( $user, ThemeDesignerConfig::DRAFT, null );
									$options->saveOptions( $user );
								}
								$this->getOutput()->redirect( $this->getPageTitle()->getLocalURL( [
									'theme-status' => $restore ? 'restored' : 'published',
								] ), '303' );
								return;
							}
						}
					}
				}
			}
		} else {
			$notice = match ( $request->getVal( 'theme-status' ) ) {
				'published' => 'mijovia-theme-published',
				'restored' => 'mijovia-theme-restored',
				default => null,
			};
		}
		if ( $notice && !$errors ) {
			$this->getOutput()->addHTML( Html::successBox( $this->msg( $notice )->escaped() ) );
		}
		if ( $base !== $revisionId && !in_array( 'mijovia-theme-stale', $errors, true ) ) {
			$this->getOutput()->addHTML( Html::warningBox( $this->msg( 'mijovia-theme-stale' )->escaped() ) );
		}
		foreach ( $errors as $field => $message ) {
			$label = is_string( $field ) ? $this->fieldLabel( $field ) . ' ' : '';
			$this->getOutput()->addHTML( Html::errorBox( htmlspecialchars( $label ) . $this->msg( $message )->escaped() ) );
		}
		$this->renderDesigner( $values, $base, $revisionId, $article );
	}

	private function sendPreview( bool $validPost ): void {
		$this->getOutput()->disable();
		$response = $this->getRequest()->response();
		$response->header( 'Cache-Control: private, no-store' );
		$response->header( 'Content-Type: application/json; charset=UTF-8', true, $validPost ? 200 : 403 );
		if ( !$validPost ) {
			echo self::encode( [ 'error' => $this->msg( 'sessionfailure' )->text() ] );
			return;
		}
		$resolved = ThemeDesignerConfig::resolve( $this->readValues() );
		$errors = [];
		foreach ( $resolved['errors'] as $field => $message ) {
			$errors[$field] = $this->msg( $message )->text();
		}
		$article = Title::newFromText( $this->inputText( 'article' ) );
		$articleUrl = $article && !$article->isExternal() && $article->canExist() && $article->exists()
			&& $this->getAuthority()->definitelyCan( 'read', $article )
			? $article->getLocalURL( [ 'useskin' => 'mijovia' ] )
			: null;
		echo self::encode( [
			'css' => $resolved['css'],
			'logoUrl' => $resolved['logoUrl'],
			'hideLogo' => $resolved['hideLogo'],
			'errors' => $errors,
			'articleUrl' => $articleUrl,
		] );
	}

	private function previousValues( ?RevisionRecord $published ): ?array {
		if ( !$published ) {
			return null;
		}
		if ( !$published->getParentId() ) {
			return [];
		}
		$previous = MediaWikiServices::getInstance()->getRevisionStore()
			->getRevisionById( $published->getParentId(), IDBAccessObject::READ_LATEST );
		return $previous?->getContent( SlotRecord::MAIN ) instanceof JsonContent
			? ThemeDesignerConfig::valuesFromRevision( $previous ) : null;
	}

	private function publish( array $values, int $expectedRevision, bool $restore ): ?string {
		$title = ThemeDesignerConfig::title();
		$authority = $this->getAuthority();
		if ( !$authority->authorizeWrite( 'edit', $title )
			|| ( !$expectedRevision && !$authority->authorizeWrite( 'create', $title ) )
		) {
			return 'mijovia-theme-error-permission';
		}
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		$updater = $page->newPageUpdater( $authority );
		$parent = $updater->grabParentRevision();
		if ( ( $parent?->getId() ?? 0 ) !== $expectedRevision ) {
			return 'mijovia-theme-stale';
		}
		$updater->setContent( SlotRecord::MAIN, new JsonContent( self::encode( $values ) ) );
		$updater->saveRevision(
			$this->msg( $restore ? 'mijovia-theme-restore-summary' : 'mijovia-theme-publish-summary' )->inContentLanguage()->text(),
			$parent ? EDIT_UPDATE : EDIT_NEW
		);
		$status = $updater->getStatus();
		if ( $status->failedBecauseOfConflict() || $status->failedBecausePageExists() || $status->failedBecausePageMissing() ) {
			return 'mijovia-theme-stale';
		}
		return $updater->wasSuccessful() ? null : 'mijovia-theme-error-save';
	}

	private function inputText( string $name ): string {
		return mb_substr( trim( $this->getRequest()->getText( $name ) ), 0, 255 );
	}

	private function readValues(): array {
		$prefill = $this->getRequest()->getArray( 'prefill', [] );
		$hiddenImages = $this->getRequest()->getArray( 'hide-image', [] );
		$readField = function ( string $name, bool $image = false ) use ( $prefill, $hiddenImages ): string {
			if ( $image && in_array( $name, $hiddenImages, true ) ) {
				return ThemeDesignerConfig::NO_IMAGE;
			}
			$value = trim( $this->getRequest()->getText( $name ) );
			return $value === ( $prefill[$name] ?? null ) ? '' : mb_substr( $value, 0, 255 );
		};
		$values = [ 'logo' => $readField( 'logo', true ) ];
		foreach ( ThemeDesignerConfig::MODES as $mode ) {
			foreach ( [ 'banner', ...array_keys( ThemeDesignerConfig::COLORS ) ] as $field ) {
				$values[$mode][$field] = $readField( "$mode-$field", $field === 'banner' );
			}
		}
		return $values;
	}

	private static function encode( array $data ): string {
		return json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR );
	}

	private function fieldLabel( string $name ): string {
		if ( $name === 'logo' ) {
			return $this->msg( 'mijovia-theme-logo' )->text();
		}
		[ $mode, $field ] = explode( '-', $name, 2 );
		return $this->msg( "mijovia-theme-$mode" )->text() . ' · ' . $this->msg( "mijovia-theme-$field" )->text();
	}

	private function field( string $name, string $field, mixed $value, string $default ): string {
		$color = isset( ThemeDesignerConfig::COLORS[$field] );
		$id = 'theme-' . $name;
		$hideImage = !$color && $value === ThemeDesignerConfig::NO_IMAGE;
		$inherited = $hideImage || !is_string( $value ) || $value === '';
		$value = $inherited ? $default : $value;
		$label = Html::element( 'label', [ 'for' => $id ], $this->msg( "mijovia-theme-$field" )->text() );
		$input = Html::input( $name, $value, 'text', [
			'id' => $id,
			'maxlength' => $color ? 7 : 255,
			'placeholder' => $this->msg( 'mijovia-theme-default' )->text(),
			'aria-describedby' => "$id-feedback",
			'list' => $color ? null : 'mijovia-theme-images',
			'data-theme-image' => $color ? null : '',
			'autocomplete' => 'off',
		] );
		$hideControl = '';
		if ( $color ) {
			$input = Html::input( '', ThemeDesignerConfig::normalizedColor( $value ) ?? $default, 'color', [
				'aria-label' => $this->fieldLabel( $name ),
				'data-color-for' => $id,
			] ) . $input;
		} else {
			$hideControl = Html::rawElement( 'label', [],
				Html::input( 'hide-image[]', $name, 'checkbox', [
					'checked' => $hideImage,
					'data-hide-image-for' => $id,
				] ) .
				' ' . $this->msg( "mijovia-theme-no-$field" )->escaped()
			);
		}
		return Html::rawElement( 'div', [ 'class' => 'mijovia-theme-field' ], $label .
			Html::rawElement( 'div', [ 'class' => 'mijovia-theme-inputs' ], $input ) .
			$hideControl .
			( $inherited ? Html::hidden( "prefill[$name]", $default ) : '' ) .
			Html::element( 'span', [ 'id' => "$id-feedback", 'aria-live' => 'polite' ] )
		);
	}

	private function renderDesigner( array $values, int $base, int $revision, string $article ): void {
		$out = $this->getOutput();
		$out->addModules( 'skins.mijovia.themeDesigner' );
		$out->addModuleStyles( 'skins.mijovia.themeDesigner.styles' );
		$out->addJsConfigVars( 'wgMijoviaThemeDesigner', [
			'previewUrl' => $this->getPageTitle( 'preview' )->getLocalURL(),
			'homeUrl' => Title::newMainPage()->getLocalURL( [ 'useskin' => 'mijovia' ] ),
		] );
		$defaults = ThemeDesignerConfig::displayDefaults(
			$this->getConfig(),
			SkinModule::getAvailableLogos( $this->getConfig(), $this->getLanguage()->getCode() ),
			$values
		);
		$fields = $this->field( 'logo', 'logo', $values['logo'] ?? '', $defaults['logo'] );
		foreach ( ThemeDesignerConfig::MODES as $mode ) {
			$palette = Html::element( 'legend', [], $this->msg( "mijovia-theme-$mode" )->text() );
			$modeValues = is_array( $values[$mode] ?? null ) ? $values[$mode] : [];
			foreach ( [ 'banner', ...array_keys( ThemeDesignerConfig::COLORS ) ] as $field ) {
				$palette .= $this->field( "$mode-$field", $field, $modeValues[$field] ?? '', $defaults[$mode][$field] );
			}
			$fields .= Html::rawElement( 'fieldset', [], $palette );
		}
		$buttons = '';
		$actions = [ 'draft', 'publish', 'reset' ];
		if ( $base !== $revision ) {
			$actions[] = 'rebase';
		}
		if ( $revision ) {
			$actions[] = 'restore';
		}
		foreach ( $actions as $action ) {
			$buttons .= Html::element( 'button', [
				'type' => 'submit',
				'name' => 'theme-action',
				'value' => $action,
			], $this->msg( "mijovia-theme-$action" )->text() );
		}
		$message = fn ( string $key ) => $this->msg( "mijovia-theme-$key" )->text();
		$modeSelect = Html::rawElement( 'select', [ 'id' => 'theme-preview-mode' ],
			Html::element( 'option', [ 'value' => 'light' ], $message( 'light' ) ) .
			Html::element( 'option', [ 'value' => 'dark' ], $message( 'dark' ) )
		);
		$pageSelect = Html::rawElement( 'select', [ 'id' => 'theme-preview-page' ],
			Html::element( 'option', [ 'value' => 'home' ], $message( 'home' ) ) .
			Html::element( 'option', [ 'value' => 'article' ], $message( 'preview-article' ) )
		);
		$history = $revision ? Html::element( 'a', [
			'href' => ThemeDesignerConfig::title()->getLocalURL( [ 'action' => 'history' ] ),
		], $message( 'history' ) ) : '';
		$preview = Html::element( 'h2', [], $message( 'preview' ) ) .
			Html::rawElement( 'div', [ 'class' => 'mijovia-theme-preview-controls' ],
				Html::element( 'label', [ 'for' => 'theme-preview-mode' ], $message( 'preview-mode' ) ) . $modeSelect .
				Html::element( 'label', [ 'for' => 'theme-preview-page' ], $message( 'preview-page' ) ) . $pageSelect .
				Html::element( 'label', [ 'for' => 'theme-preview-article' ], $message( 'article-title' ) ) .
				Html::input( 'article', $article, 'text', [ 'id' => 'theme-preview-article', 'maxlength' => 255 ] )
			) .
			Html::element( 'p', [ 'id' => 'theme-preview-status', 'role' => 'status' ] ) .
			Html::element( 'iframe', [ 'id' => 'theme-preview-frame', 'title' => $message( 'preview' ),
				'sandbox' => 'allow-same-origin' ] );
		$out->addHTML( Html::rawElement( 'form', [
			'id' => 'mijovia-theme-designer',
			'method' => 'post',
			'action' => $this->getPageTitle()->getLocalURL(),
		],
			Html::hidden( 'token', $this->getUser()->getEditToken( self::TOKEN_SALT, $this->getRequest() ) ) .
			Html::hidden( 'base', $base ) .
			Html::hidden( 'published', $revision ) .
			Html::element( 'datalist', [ 'id' => 'mijovia-theme-images' ] ) .
			Html::rawElement( 'div', [ 'class' => 'mijovia-theme-editor' ],
				Html::element( 'p', [], $message( 'help' ) ) . $fields .
				Html::rawElement( 'div', [ 'class' => 'mijovia-theme-buttons' ], $buttons . $history )
			) .
			Html::rawElement( 'section', [ 'class' => 'mijovia-theme-preview' ], $preview )
		) );
	}

	protected function getGroupName() {
		return 'wiki';
	}
}
