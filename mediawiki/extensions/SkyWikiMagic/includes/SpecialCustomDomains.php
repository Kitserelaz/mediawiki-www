<?php

namespace WikiOasis\WikiOasisMagic;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\ConfigNames;
use stdClass;

final class SpecialCustomDomains extends SpecialPage {
	private const FORM_PICKER = 'cd-picker';
	private const FORM_BEGIN = 'cd-begin';
	private const FORM_VERIFY = 'cd-verify';
	private const FORM_CANCEL = 'cd-cancel';
	private const FORM_REMOVE = 'cd-remove';

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CustomDomainManager $domains,
	) {
		parent::__construct( 'CustomDomains' );
	}

	public function execute( $subPage ): void {
		$this->setHeaders();
		$out = $this->getOutput();
		$out->enableClientCache( false );
		$out->setRobotPolicy( 'noindex,nofollow' );
		if ( !$this->databaseUtils->isCurrentWikiCentral() ) {
			throw new ErrorPageError( 'errorpagetitle', 'createwiki-wikinotcentralwiki' );
		}
		if ( !CustomDomainConfig::routingEnabled() ) {
			throw new ErrorPageError( 'customdomains', 'customdomains-disabled' );
		}
		$this->requireLogin();
		$request = $this->getRequest();
		$slug = $subPage ?? $request->getText( 'wiki' );
		if ( $slug === '' ) {
			$this->showPicker();
			return;
		}
		$wiki = CustomDomainConfig::wikiId( $slug );
		$row = $wiki === null ? null : $this->domains->store->wiki( $wiki );
		if ( !$row || $row->wiki_deleted ) {
			throw new ErrorPageError( 'customdomains', 'customdomains-wiki-unavailable' );
		}
		$actor = $this->domains->authorize( $this->getUser(), $wiki );
		if ( !$actor ) {
			throw new ErrorPageError( 'customdomains', 'customdomains-permission' );
		}
		$state = $this->domains->store->state( $wiki );
		$active = $this->domains->store->active( $wiki );
		$locked = (bool)$row->wiki_locked;
		$salt = self::class . $wiki;

		// HTMLForm silently discards submissions whose session token no longer matches;
		// keep the explicit expired-session notice the previous dispatch showed.
		if ( $request->wasPosted() &&
			$request->getVal( 'wpFormIdentifier' ) !== self::FORM_PICKER &&
			!$this->getUser()->matchEditToken( $request->getText( 'wpEditToken' ), $salt )
		) {
			$out->addHTML( Html::errorBox( $this->msg( 'customdomains-token' )->escaped() ) );
		}

		$builtin = "$slug." . \MirahezeFunctions::getPrimaryDomain( $wiki );
		$current = $active ?? ( parse_url( $row->wiki_url ?? '', PHP_URL_HOST ) ?: $builtin );
		$out->addHTML( Html::element( 'p', [], $this->msg( 'customdomains-current', $current )->text() ) );
		if ( $active ) {
			if ( $this->showActionForm( self::FORM_REMOVE, $salt, 'customdomains-remove', [],
				fn ( array $data ): bool|Status => $this->submitRemove( $data, $wiki, $slug, $active, $actor ),
				$active
			) ) {
				return;
			}
		}
		if ( $state?->cd_candidate && $state->cd_expires > time() ) {
			$this->showCandidate( $state );
			if ( $state->cd_due !== null ) {
				$out->addHTML( Html::element( 'p', [], $this->msg( 'customdomains-queued' )->text() ) );
			} elseif ( CustomDomainConfig::enabled() && !$locked ) {
				if ( $this->showActionForm( self::FORM_VERIFY, $salt, 'customdomains-verify', [],
					fn ( array $data ): bool|Status => $this->submitVerify( $data, $wiki, $slug, $state, $locked ),
					$state->cd_token
				) ) {
					return;
				}
			}
			if ( $this->showActionForm( self::FORM_CANCEL, $salt, 'customdomains-cancel', [],
				fn ( array $data ): bool|Status => $this->submitCancel( $data, $wiki, $slug, $state ),
				$state->cd_token
			) ) {
				return;
			}
		} elseif ( $locked ) {
			$out->addHTML( Html::element( 'p', [], $this->msg( 'customdomains-wiki-unavailable' )->text() ) );
		} elseif ( !CustomDomainConfig::enabled() ) {
			$out->addHTML( Html::element( 'p', [], $this->msg( 'customdomains-paused' )->text() ) );
		} elseif ( !$active && $current !== $builtin ) {
			$out->addHTML( Html::element( 'p', [], $this->msg( 'customdomains-legacy' )->text() ) );
		} else {
			if ( $this->showActionForm( self::FORM_BEGIN, $salt, 'customdomains-begin', [
					'hostname' => [
						'type' => 'text',
						'label-message' => 'customdomains-hostname',
						'placeholder' => 'wiki.example.org',
						'required' => true,
						'validation-callback' =>
							fn ( $value ) => $this->validHostname( (string)$value ) !== null
								? true
								: $this->msg( 'customdomains-invalid-host' ),
					],
				],
				fn ( array $data ): bool|Status => $this->submitBegin( $data, $wiki, $slug, $row, $actor )
			) ) {
				return;
			}
		}
	}

	private function showPicker(): void {
		$this->getOutput()->addModuleStyles( 'ext.skywiki.customDomains.styles' );
		$form = HTMLForm::factory( 'ooui', [
			'wiki' => [
				'type' => 'textwithbutton',
				'label-message' => 'customdomains-wiki',
				'required' => true,
				'buttontype' => 'button',
				'buttonflags' => [],
				'buttonid' => 'customdomains-subdomain-suffix',
				'buttondefault' => '.' . $this->getConfig()->get( ConfigNames::Subdomain ),
			],
		], $this->getContext() );
		$form->setFormIdentifier( self::FORM_PICKER );
		$form->setMethod( 'post' );
		$form->setSubmitTextMsg( 'customdomains-open' );
		$form->setSubmitCallback( function ( array $data ): bool {
			$this->redirect( $data['wiki'] );
			return true;
		} );
		$form->show();
	}

	private function showCandidate( stdClass $state ): void {
		$out = $this->getOutput();
		$host = $state->cd_candidate;
		$target = CustomDomainConfig::target();
		$out->addHTML( Html::element( 'h2', [], $this->msg( 'customdomains-dns-heading' )->text() ) );
		$out->addHTML( Html::element( 'p', [],
			$this->msg( 'customdomains-txt', "_cloudwiki-verify.$host", $state->cd_token )->text() ) );
		$out->addHTML( Html::element( 'p', [], $this->msg( 'customdomains-cname', $host, $target )->text() ) );
		$out->addHTML( Html::element( 'p', [], $this->msg( 'customdomains-apex', $target )->text() ) );
		if ( $state->cd_error ) {
			$out->addHTML( Html::errorBox( $this->msg( 'customdomains-' . $state->cd_error )->escaped() ) );
		}
	}

	private function showActionForm(
		string $name, string $salt, string $submitMsg, array $fields, callable $callback, string $expected = ''
	): bool {
		$form = HTMLForm::factory( 'ooui',
			[ 'expected' => [ 'type' => 'hidden', 'default' => $expected ] ] + $fields,
			$this->getContext()
		);
		$form->setFormIdentifier( $name );
		$form->setMethod( 'post' );
		$form->setTokenSalt( $salt );
		$form->setSubmitTextMsg( $submitMsg );
		$form->setSubmitCallback( $callback );
		$result = $form->show();
		return $result === true || ( $result instanceof Status && $result->isGood() );
	}

	private function validHostname( string $value ): ?string {
		$host = CustomDomainConfig::hostname( $value );
		if ( $host !== null && strlen( "_cloudwiki-verify.$host" ) <= 253 && !CustomDomainConfig::isReserved( $host ) ) {
			return $host;
		}
		return null;
	}

	private function submitRemove( array $data, string $wiki, string $slug, string $active, CentralAuthUser $actor ): bool|Status {
		if ( $data['expected'] !== $active ) {
			return Status::newFatal( 'customdomains-changed' );
		}
		if ( !$this->domains->remove( $wiki, $data['expected'], $actor ) ) {
			return Status::newFatal( 'customdomains-changed' );
		}
		$this->redirect( $slug );
		return true;
	}

	private function submitCancel( array $data, string $wiki, string $slug, stdClass $state ): bool|Status {
		if ( $data['expected'] !== $state->cd_token ) {
			return Status::newFatal( 'customdomains-changed' );
		}
		$this->domains->store->cancel( $wiki, $data['expected'] );
		$this->redirect( $slug );
		return true;
	}

	private function submitVerify( array $data, string $wiki, string $slug, stdClass $state, bool $locked ): bool|Status {
		if ( !CustomDomainConfig::enabled() ) {
			return Status::newFatal( 'customdomains-paused' );
		}
		if ( $locked ) {
			return Status::newFatal( 'customdomains-wiki-unavailable' );
		}
		if ( $data['expected'] !== $state->cd_token ) {
			return Status::newFatal( 'customdomains-changed' );
		}
		$error = $this->domains->store->schedule( $wiki, $data['expected'] );
		if ( $error !== null ) {
			return Status::newFatal( "customdomains-$error" );
		}
		$this->redirect( $slug );
		return true;
	}

	private function submitBegin( array $data, string $wiki, string $slug, stdClass $row, CentralAuthUser $actor ): bool|Status {
		if ( !CustomDomainConfig::enabled() ) {
			return Status::newFatal( 'customdomains-paused' );
		}
		if ( $row->wiki_locked ) {
			return Status::newFatal( 'customdomains-wiki-unavailable' );
		}
		$host = $this->validHostname( $data['hostname'] );
		if ( $host === null ) {
			return Status::newFatal( 'customdomains-invalid-host' );
		}
		$error = $this->domains->store->begin( $wiki, $host, $actor->getId() );
		if ( $error !== null ) {
			return Status::newFatal( "customdomains-$error" );
		}
		$this->redirect( $slug );
		return true;
	}

	private function redirect( string $slug ): void {
		$this->getOutput()->redirect( $this->getPageTitle( $slug )->getFullURL(), '303' );
	}

	protected function getGroupName(): string {
		return 'wikimanage';
	}
}
