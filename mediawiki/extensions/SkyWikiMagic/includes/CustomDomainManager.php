<?php

namespace WikiOasis\WikiOasisMagic;

use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use stdClass;
use Wikimedia\Rdbms\IDBAccessObject;

final class CustomDomainManager {
	public readonly CustomDomainStore $store;

	public function __construct( private readonly MediaWikiServices $services ) {
		$this->store = new CustomDomainStore(
			$services->get( 'CreateWikiDatabaseUtils' )->getGlobalPrimaryDB(),
			$services->get( 'RemoteWikiFactory' ),
		);
	}

	public function authorize( UserIdentity $user, string $wiki ): ?CentralAuthUser {
		if ( !$user->isRegistered() ) {
			return null;
		}
		$account = CentralAuthUser::getPrimaryInstance( $user );
		return $this->canManage( $account, $wiki ) ? $account : null;
	}

	private function canManage( CentralAuthUser $account, string $wiki ): bool {
		if ( !$account->exists() || !$account->isAttached() || $account->isLocked() ) {
			return false;
		}
		if ( in_array( 'steward', CentralAuthServices::getGlobalPermissionManager()->getUserEffectiveGroups( $account ), true ) ) {
			return true;
		}
		if ( !$account->attachedOn( $wiki ) ) {
			return false;
		}
		$id = $account->getLocalId( $wiki, IDBAccessObject::READ_LATEST );
		return $id && in_array( 'bureaucrat', $this->services->getUserGroupManagerFactory()
			->getUserGroupManager( $wiki )
			->getUserGroups( new UserIdentityValue( $id, $account->getName(), $wiki ), IDBAccessObject::READ_LATEST ), true );
	}

	public function remove( string $wiki, string $host, CentralAuthUser $actor ): bool {
		if ( !$this->store->remove( $wiki, $host ) ) {
			return false;
		}
		$db = $this->services->get( 'CreateWikiDatabaseUtils' )->getGlobalPrimaryDB();
		$db->onTransactionCommitOrIdle( function () use ( $wiki, $host, $actor ) {
			$this->log( $wiki, $actor, $host, null );
		}, __METHOD__ );
		return true;
	}

	public function run(): void {
		foreach ( $this->store->expired() as $entry ) {
			$this->store->cancel( $entry->cd_wiki, $entry->cd_token );
		}
		if ( CustomDomainConfig::enabled() ) {
			foreach ( $this->store->due() as $entry ) {
				$this->process( $entry );
			}
		}
		$this->publish();
	}

	private function process( stdClass $entry ): void {
		$wiki = $entry->cd_wiki;
		$token = $entry->cd_token;
		$host = $entry->cd_candidate;
		if ( $entry->cd_expires <= time() ) {
			$this->store->cancel( $wiki, $token );
			return;
		}
		$actor = CentralAuthUser::newPrimaryInstanceFromId( (int)$entry->cd_requester );
		$row = $this->store->wiki( $wiki );
		if ( !$actor || !$row || $row->wiki_locked || $row->wiki_deleted || !$this->canManage( $actor, $wiki ) ) {
			$this->store->fail( $wiki, $token, 'wiki-unavailable', false );
			return;
		}
		if ( CustomDomainConfig::isReserved( $host ) ) {
			$this->store->fail( $wiki, $token, 'invalid-host', false );
			return;
		}
		if ( !$this->ownsHostname( $host, $token ) ) {
			$this->store->fail( $wiki, $token, 'dns-ownership', true );
			return;
		}
		if ( !$this->pointsToCloudWiki( $host ) ) {
			$this->store->fail( $wiki, $token, 'dns-routing', true );
			return;
		}
		$error = $this->store->claim( $wiki, $token );
		if ( $error ) {
			$this->store->fail( $wiki, $token, $error, false );
			return;
		}
		if ( !$this->canConnect( $host ) ) {
			$this->store->fail( $wiki, $token, 'https', true );
			return;
		}
		// Re-read authorization after network operations. The store locks the wiki and checks the
		// request token again when committing, so cancellation or replacement invalidates this work.
		$actor = CentralAuthUser::newPrimaryInstanceFromId( (int)$entry->cd_requester );
		if ( !$actor || !$this->canManage( $actor, $wiki ) ) {
			$this->store->fail( $wiki, $token, 'wiki-unavailable', false );
			return;
		}
		$previous = $this->store->activate( $wiki, $token );
		if ( $previous !== false ) {
			$db = $this->services->get( 'CreateWikiDatabaseUtils' )->getGlobalPrimaryDB();
			$db->onTransactionCommitOrIdle( function () use ( $wiki, $actor, $previous, $host ) {
				$this->log( $wiki, $actor, $previous, $host );
			}, __METHOD__ );
		}
	}

	private function ownsHostname( string $host, string $token ): bool {
		foreach ( @dns_get_record( "_cloudwiki-verify.$host", DNS_TXT ) ?: [] as $record ) {
			if ( isset( $record['txt'] ) && hash_equals( $token, $record['txt'] ) ) {
				return true;
			}
		}
		return false;
	}

	private function pointsToCloudWiki( string $host ): bool {
		$target = CustomDomainConfig::target();
		$allowed = $target === '' ? [] : $this->addresses( $target );
		$actual = $this->addresses( $host );
		if ( !$actual || array_diff( $actual, $allowed ) ) {
			return false;
		}
		foreach ( @dns_get_record( $host, DNS_CNAME ) ?: [] as $record ) {
			if ( CustomDomainConfig::hostname( $record['target'] ?? '' ) !== $target ) {
				return false;
			}
		}
		return true;
	}

	private function addresses( string $host ): array {
		$addresses = [];
		foreach ( @dns_get_record( $host, DNS_A | DNS_AAAA ) ?: [] as $record ) {
			$ip = $record['ip'] ?? $record['ipv6'] ?? null;
			if ( $ip !== null ) {
				$addresses[] = inet_pton( $ip );
			}
		}
		return $addresses;
	}

	private function canConnect( string $host ): bool {
		$options = [ 'peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true ];
		$trustedCa = getenv( 'MW_CUSTOM_DOMAINS_PROBE_CA' );
		if ( $trustedCa ) {
			$options['cafile'] = $trustedCa;
		}
		$socket = @stream_socket_client( 'tls://web:443', $errno, $error, 20, STREAM_CLIENT_CONNECT,
			stream_context_create( [ 'ssl' => $options ] ) );
		if ( $socket === false ) {
			return false;
		}
		fclose( $socket );
		return true;
	}

	private function publish(): void {
		$entries = $this->store->pendingPublication();
		if ( !$entries ) {
			return;
		}
		$this->services->get( 'CreateWikiDataStore' )->resetDatabaseLists( true, requirePublished: true );
		$factory = $this->services->get( 'ManageWikiDataStoreFactory' );
		foreach ( $entries as $entry ) {
			if ( $this->store->wiki( $entry->cd_wiki ) ) {
				$factory->newInstance( $entry->cd_wiki )->resetWikiData( true );
			}
			$this->store->markPublished( $entry );
		}
	}

	private function log( string $wiki, CentralAuthUser $actor, ?string $old, ?string $new ): void {
		$builtin = CustomDomainConfig::slug( $wiki ) . '.' . \MirahezeFunctions::getPrimaryDomain( $wiki );
		$entry = new ManualLogEntry( 'managewiki', 'custom-domain' );
		$entry->setPerformer( $this->services->getUserFactory()->newFromName( $actor->getName() ) );
		$entry->setTarget( SpecialPage::getTitleValueFor( 'CustomDomains', CustomDomainConfig::slug( $wiki ) ) );
		$entry->setParameters( [ '4::previous' => $old ?? $builtin, '5::current' => $new ?? $builtin ] );
		$entry->publish( $entry->insert() );
	}
}
