<?php

namespace WikiOasis\WikiOasisMagic;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use stdClass;

final class SpecialCustomDomains extends SpecialPage {
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
			$out->showErrorPage( 'errorpagetitle', 'createwiki-wikinotcentralwiki' );
			return;
		}
		if ( !CustomDomainConfig::routingEnabled() ) {
			$out->showErrorPage( 'customdomains', 'customdomains-disabled' );
			return;
		}
		$this->requireLogin();
		$request = $this->getRequest();
		$slug = $subPage ?? $request->getText( 'wiki' );
		if ( $slug === '' ) {
			$out->addHTML( Html::rawElement( 'form', [ 'method' => 'get', 'action' => $this->getConfig()->get( 'Script' ) ],
				Html::hidden( 'title', $this->getPageTitle()->getPrefixedText() ) .
				Html::element( 'label', [ 'for' => 'customdomains-wiki' ], $this->msg( 'customdomains-wiki' )->text() ) . ' ' .
				Html::input( 'wiki', '', 'text', [ 'id' => 'customdomains-wiki', 'required' => true ] ) . ' ' .
				Html::submitButton( $this->msg( 'customdomains-open' )->text() )
			) );
			return;
		}
		$wiki = CustomDomainConfig::wikiId( $slug );
		$row = $wiki === null ? null : $this->domains->store->wiki( $wiki );
		if ( !$row || $row->wiki_deleted ) {
			$out->showErrorPage( 'customdomains', 'customdomains-wiki-unavailable' );
			return;
		}
		$actor = $this->domains->authorize( $this->getUser(), $wiki );
		if ( !$actor ) {
			$out->showErrorPage( 'customdomains', 'customdomains-permission' );
			return;
		}
		$state = $this->domains->store->state( $wiki );
		$active = $this->domains->store->active( $wiki );

		if ( $request->wasPosted() ) {
			$error = 'changed';
			$action = $request->getText( 'domain-action' );
			$expected = $request->getText( 'expected' );
			if ( !$this->getUser()->matchEditToken( $request->getText( 'wpEditToken' ), self::class . $wiki ) ) {
				$error = 'token';
			} elseif ( $action === 'remove' && $active !== null && $active === $expected ) {
				$error = $this->domains->remove( $wiki, $expected, $actor ) ? null : 'changed';
			} elseif ( $action === 'cancel' && $state?->cd_token === $expected ) {
				$this->domains->store->cancel( $wiki, $expected );
				$error = null;
			} elseif ( !CustomDomainConfig::enabled() ) {
				$error = 'paused';
			} elseif ( $row->wiki_locked ) {
				$error = 'wiki-unavailable';
			} elseif ( $action === 'begin' ) {
				$host = CustomDomainConfig::hostname( $request->getText( 'hostname' ) );
				$error = $host && strlen( "_cloudwiki-verify.$host" ) <= 253 && !CustomDomainConfig::isReserved( $host )
					? $this->domains->store->begin( $wiki, $host, $actor->getId() ) : 'invalid-host';
			} elseif ( $action === 'verify' && $state?->cd_token === $expected ) {
				$error = $this->domains->store->schedule( $wiki, $expected );
			}
			if ( $error === null ) {
				$out->redirect( $this->getPageTitle( $slug )->getFullURL(), '303' );
				return;
			}
			$out->addHTML( Html::errorBox( $this->msg( "customdomains-$error" )->escaped() ) );
		}

		$builtin = "$slug." . \MirahezeFunctions::getPrimaryDomain( $wiki );
		$current = $active ?? ( parse_url( $row->wiki_url ?? '', PHP_URL_HOST ) ?: $builtin );
		$out->addHTML( Html::element( 'p', [], $this->msg( 'customdomains-current', $current )->text() ) );
		if ( $active ) {
			$out->addHTML( $this->form( $slug, $wiki, 'remove', $active ) );
		}
		if ( $state?->cd_candidate && $state->cd_expires > time() ) {
			$this->showCandidate( $slug, $wiki, $state, (bool)$row->wiki_locked );
		} elseif ( $row->wiki_locked ) {
			$out->addHTML( Html::element( 'p', [], $this->msg( 'customdomains-wiki-unavailable' )->text() ) );
		} elseif ( !CustomDomainConfig::enabled() ) {
			$out->addHTML( Html::element( 'p', [], $this->msg( 'customdomains-paused' )->text() ) );
		} elseif ( !$active && $current !== $builtin ) {
			$out->addHTML( Html::element( 'p', [], $this->msg( 'customdomains-legacy' )->text() ) );
		} else {
			$input = Html::element( 'label', [ 'for' => 'customdomains-hostname' ],
				$this->msg( 'customdomains-hostname' )->text() ) . ' ' .
				Html::input( 'hostname', '', 'text', [
					'id' => 'customdomains-hostname', 'placeholder' => 'wiki.example.org', 'required' => true,
				] ) . ' ';
			$out->addHTML( $this->form( $slug, $wiki, 'begin', '', $input ) );
		}
	}

	private function showCandidate( string $slug, string $wiki, stdClass $state, bool $locked ): void {
		$out = $this->getOutput();
		$host = $state->cd_candidate;
		$target = CustomDomainConfig::target();
		$out->addHTML( Html::element( 'h2', [], $this->msg( 'customdomains-dns-heading' )->text() ) );
		$out->addHTML( Html::element( 'p', [], $this->msg( 'customdomains-txt', "_cloudwiki-verify.$host", $state->cd_token )->text() ) );
		$out->addHTML( Html::element( 'p', [], $this->msg( 'customdomains-cname', $host, $target )->text() ) );
		$out->addHTML( Html::element( 'p', [], $this->msg( 'customdomains-apex', $target )->text() ) );
		if ( $state->cd_error ) {
			$out->addHTML( Html::errorBox( $this->msg( 'customdomains-' . $state->cd_error )->escaped() ) );
		}
		if ( $state->cd_due !== null ) {
			$out->addHTML( Html::element( 'p', [], $this->msg( 'customdomains-queued' )->text() ) );
		} elseif ( CustomDomainConfig::enabled() && !$locked ) {
			$out->addHTML( $this->form( $slug, $wiki, 'verify', $state->cd_token ) );
		}
		$out->addHTML( $this->form( $slug, $wiki, 'cancel', $state->cd_token ) );
	}

	private function form( string $slug, string $wiki, string $action, string $expected, string $fields = '' ): string {
		return Html::rawElement( 'form', [ 'method' => 'post', 'action' => $this->getPageTitle( $slug )->getLocalURL() ],
			Html::hidden( 'wpEditToken', $this->getUser()->getEditToken( self::class . $wiki ) ) .
			Html::hidden( 'expected', $expected ) .
			Html::hidden( 'domain-action', $action ) .
			$fields . Html::submitButton( $this->msg( "customdomains-$action" )->text() )
		);
	}

	protected function getGroupName(): string {
		return 'wikimanage';
	}
}
