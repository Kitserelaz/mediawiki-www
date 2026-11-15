<?php

namespace WikiOasis\WikiOasisMagic;

use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use RuntimeException;
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
		$cloudflare = CloudflareCustomHostnames::fromEnvironment();
		if ( $cloudflare ) {
			$this->cleanupCloudflare( $cloudflare );
		}
		if ( CustomDomainConfig::enabled() ) {
			foreach ( $this->store->due() as $entry ) {
				if ( !$cloudflare ) {
					$this->store->fail( $entry->cd_wiki, $entry->cd_token, 'cloudflare-config', true, true );
					continue;
				}
				$this->process( $entry, $cloudflare );
			}
		}
		$this->publish();
	}

	private function process( stdClass $entry, CloudflareCustomHostnames $cloudflare ): void {
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
			$this->store->fail( $wiki, $token, 'dns-ownership', true, true );
			return;
		}
		$addresses = $this->addresses( $host );
		if ( !$addresses || !$this->matchesTarget( $host ) ) {
			$this->store->fail( $wiki, $token, 'dns-routing', true, true );
			return;
		}
		$error = $this->store->claim( $wiki, $token );
		if ( $error ) {
			$retry = $error === 'quota-busy';
			$this->store->fail( $wiki, $token, $error, $retry, $retry || $error === 'quota-full' );
			return;
		}
		try {
			$hostname = $this->provisionCloudflare( $entry, $cloudflare );
		} catch ( RuntimeException $error ) {
			error_log( $error->getMessage() );
			$this->store->fail( $wiki, $token, 'cloudflare-api', true, true );
			return;
		}
		if ( $hostname === null ) {
			return;
		}
		if ( ( $hostname['status'] ?? null ) !== 'active' || ( $hostname['ssl']['status'] ?? null ) !== 'active' ) {
			$this->store->fail( $wiki, $token, 'cloudflare-pending', true, true );
			return;
		}
		if ( !$this->canConnect( $host, $token, $addresses ) ) {
			$this->store->fail( $wiki, $token, 'cloudflare-https', true, true );
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

	private function matchesTarget( string $host ): bool {
		$target = CustomDomainConfig::target();
		// Flattened apexes and proxied customer zones may omit the CNAME in DNS.
		// Cloudflare's activation status and the HTTPS origin proof cover those cases.
		foreach ( @dns_get_record( $host, DNS_CNAME ) ?: [] as $record ) {
			if ( CustomDomainConfig::hostname( $record['target'] ?? '' ) !== $target ) {
				return false;
			}
		}
		return $target !== '';
	}

	private function addresses( string $host ): array {
		$addresses = [];
		foreach ( @dns_get_record( $host, DNS_A | DNS_AAAA ) ?: [] as $record ) {
			$ip = $record['ip'] ?? $record['ipv6'] ?? null;
			if ( $ip === null ) {
				continue;
			}
			if ( !filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE ) ) {
				return [];
			}
			// Try IPv4 first and allow one attempt per family.
			$addresses[str_contains( $ip, ':' ) ? 6 : 4] ??= $ip;
		}
		ksort( $addresses );
		return $addresses;
	}

	private function canConnect( string $host, string $token, array $addresses ): bool {
		$nonce = bin2hex( random_bytes( 16 ) );
		$secret = $this->services->getMainConfig()->get( 'SecretKey' );
		$proof = CustomDomainConfig::probeProof( $host, $token, $nonce, $secret );
		foreach ( $addresses as $family => $ip ) {
			$address = $family === 6 ? "[$ip]" : $ip;
			$curl = curl_init( "https://$host/.well-known/cloudwiki-saas-check/$token/$nonce" );
			$body = '';
			curl_setopt_array( $curl, [
				CURLOPT_RESOLVE => [ "$host:443:$address" ],
				CURLOPT_PROXY => '',
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT => 10,
				CURLOPT_WRITEFUNCTION => static function ( $curl, string $chunk ) use ( &$body ): int {
					if ( strlen( $body ) + strlen( $chunk ) > 64 ) {
						return 0;
					}
					$body .= $chunk;
					return strlen( $chunk );
				},
			] );
			if ( curl_exec( $curl ) && curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ) === 200 &&
				hash_equals( $proof, $body )
			) {
				return true;
			}
		}
		return false;
	}

	private function provisionCloudflare( stdClass $entry, CloudflareCustomHostnames $cloudflare ): ?array {
		$host = $entry->cd_candidate;
		$token = $entry->cd_token;
		$registration = $this->store->cloudflareEntry( $host );
		if ( $registration && $registration->cdf_request !== $token ) {
			$this->store->fail( $entry->cd_wiki, $token, 'cloudflare-pending', true, true );
			return null;
		}
		if ( !$registration ) {
			if ( $cloudflare->find( $host ) ) {
				$this->store->fail( $entry->cd_wiki, $token, 'cloudflare-conflict', false );
				return null;
			}
			// Save the intent before the request so an interrupted create can be reconciled.
			if ( !$this->store->recordCloudflareIntent( $entry->cd_wiki, $token ) ) {
				return null;
			}
			$hostname = $this->createCloudflare( $cloudflare, $host, $token );
		} else {
			$hostname = $registration->cdf_id ? $cloudflare->get( $registration->cdf_id ) : $cloudflare->find( $host );
			$hostname ??= $this->createCloudflare( $cloudflare, $host, $token );
		}
		if ( ( $hostname['hostname'] ?? null ) !== $host || !self::validCloudflareId( $hostname['id'] ?? '' ) ) {
			throw new RuntimeException( 'Cloudflare returned an invalid custom hostname response' );
		}
		$this->store->recordCloudflareId( $host, $token, $hostname['id'] );
		return $hostname;
	}

	private function createCloudflare( CloudflareCustomHostnames $cloudflare, string $host, string $token ): array {
		try {
			return $cloudflare->create( $host );
		} catch ( RuntimeException $error ) {
			$status = $error->getCode();
			if ( $status >= 400 && $status < 500 && $status !== 408 && $status !== 429 ) {
				// A rejected create must never make an existing external registration ours.
				$this->store->forgetCloudflareEntry( $host, $token );
			}
			throw $error;
		}
	}

	private function cleanupCloudflare( CloudflareCustomHostnames $cloudflare ): void {
		foreach ( $this->store->orphanedCloudflareEntries() as $entry ) {
			try {
				$id = $entry->cdf_id ?? $cloudflare->find( $entry->cdf_host )['id'] ?? null;
				if ( $id !== null ) {
					if ( !self::validCloudflareId( $id ) ) {
						throw new RuntimeException( 'Invalid Cloudflare hostname ID during cleanup' );
					}
					$cloudflare->delete( $id );
				}
				$this->store->forgetCloudflareEntry( $entry->cdf_host, $entry->cdf_request );
			} catch ( RuntimeException $error ) {
				error_log( $error->getMessage() );
			}
		}
	}

	private static function validCloudflareId( string $id ): bool {
		return (bool)preg_match( '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $id );
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
