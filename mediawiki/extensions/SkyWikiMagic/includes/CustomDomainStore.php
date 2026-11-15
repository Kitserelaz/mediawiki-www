<?php

namespace WikiOasis\WikiOasisMagic;

use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use stdClass;
use Wikimedia\Rdbms\IDatabase;

final class CustomDomainStore {
	private const STATE = 'cw_custom_domain_state';
	private const CLAIMS = 'cw_custom_domain_claims';
	private const CLOUDFLARE = 'cw_custom_domain_cloudflare';

	public function __construct(
		private readonly IDatabase $db,
		private readonly RemoteWikiFactory $remoteWikis,
	) {
	}

	public function wiki( string $wiki, bool $lock = false ): ?stdClass {
		$query = $this->db->newSelectQueryBuilder()
			->select( [ 'wiki_url', 'wiki_deleted', 'wiki_locked' ] )
			->from( 'cw_wikis' )->where( [ 'wiki_dbname' => $wiki ] )->caller( __METHOD__ );
		return ( $lock ? $query->forUpdate() : $query )->fetchRow() ?: null;
	}

	public function state( string $wiki, bool $lock = false ): ?stdClass {
		$query = $this->db->newSelectQueryBuilder()->select( '*' )->from( self::STATE )
			->where( [ 'cd_wiki' => $wiki ] )->caller( __METHOD__ );
		return ( $lock ? $query->forUpdate() : $query )->fetchRow() ?: null;
	}

	public function active( string $wiki, bool $lock = false ): ?string {
		$query = $this->db->newSelectQueryBuilder()->select( 'cdc_host' )->from( self::CLAIMS )
			->where( [ 'cdc_wiki' => $wiki, 'cdc_active' => 1 ] )->caller( __METHOD__ );
		return ( $lock ? $query->forUpdate() : $query )->fetchField() ?: null;
	}

	public function begin( string $wiki, string $host, int $requester ): ?string {
		return $this->atomic( __METHOD__, function () use ( $wiki, $host, $requester ) {
			$row = $this->wiki( $wiki, true );
			if ( !$row || $row->wiki_deleted || $row->wiki_locked ) {
				return 'wiki-unavailable';
			}
			$active = $this->active( $wiki, true );
			if ( $active === $host ) {
				return 'already-active';
			}
			$state = $this->state( $wiki, true );
			if ( $state && $state->cd_candidate && $state->cd_expires > time() ) {
				return 'pending';
			}
			$currentHost = parse_url( $row->wiki_url ?? '', PHP_URL_HOST );
			$builtin = CustomDomainConfig::slug( $wiki ) . '.' . \MirahezeFunctions::getPrimaryDomain( $wiki );
			if ( $currentHost && CustomDomainConfig::hostname( $currentHost ) !== $builtin && !$active ) {
				return 'legacy';
			}
			$this->deleteClaim( $wiki, false );
			$fields = [
				'cd_candidate' => $host,
				'cd_token' => bin2hex( random_bytes( 24 ) ),
				'cd_requester' => $requester,
				'cd_expires' => time() + 86400,
				'cd_due' => null,
				'cd_error' => null,
				'cd_attempts' => 0,
			];
			$this->db->newInsertQueryBuilder()->insertInto( self::STATE )
				->row( [ 'cd_wiki' => $wiki ] + $fields )
				->onDuplicateKeyUpdate()->uniqueIndexFields( [ 'cd_wiki' ] )->set( $fields )
				->caller( __METHOD__ )->execute();
			return null;
		} );
	}

	public function schedule( string $wiki, string $token ): ?string {
		return $this->atomic( __METHOD__, function () use ( $wiki, $token ) {
			[ $row, $state ] = $this->lockCandidate( $wiki, $token );
			if ( !$row || !$state || $state->cd_expires <= time() ) {
				return 'expired';
			}
			if ( $state->cd_due === null || $state->cd_due <= time() ) {
				$this->update( $wiki, [ 'cd_due' => time() ] );
			}
			return null;
		} );
	}

	public function claim( string $wiki, string $token ): ?string {
		return $this->atomic( __METHOD__, function () use ( $wiki, $token ) {
			[ $row, $state ] = $this->lockCandidate( $wiki, $token );
			if ( !$row || !$state || $state->cd_expires <= time() ) {
				return 'expired';
			}
			$host = $state->cd_candidate;
			// Include domains assigned before the hostname registry existed, including deleted wikis.
			$urls = $this->db->newSelectQueryBuilder()->select( 'wiki_url' )->from( 'cw_wikis' )
				->where( $this->db->expr( 'wiki_url', '!=', null ) )->caller( __METHOD__ )->fetchFieldValues();
			foreach ( $urls as $url ) {
				$current = parse_url( str_starts_with( $url, '//' ) ? "https:$url" : $url, PHP_URL_HOST );
				if ( $current && CustomDomainConfig::hostname( $current ) === $host ) {
					return 'taken';
				}
			}
			$this->db->newInsertQueryBuilder()->insertInto( self::CLAIMS )
				->row( [ 'cdc_host' => $host, 'cdc_wiki' => $wiki, 'cdc_active' => 0 ] )
				->ignore()->caller( __METHOD__ )->execute();
			$owned = $this->db->newSelectQueryBuilder()->select( 'cdc_host' )->from( self::CLAIMS )
				->where( [ 'cdc_host' => $host, 'cdc_wiki' => $wiki, 'cdc_active' => 0 ] )
				->forUpdate()->caller( __METHOD__ )->fetchField();
			return $owned ? null : 'taken';
		} );
	}

	public function activate( string $wiki, string $token ): string|false|null {
		return $this->atomic( __METHOD__, function () use ( $wiki, $token ) {
			[ $row, $state ] = $this->lockCandidate( $wiki, $token );
			if ( !$row || !$state || $state->cd_expires <= time() ) {
				return false;
			}
			$previous = $this->active( $wiki, true );
			$this->deleteClaim( $wiki, true );
			$this->db->newUpdateQueryBuilder()->update( self::CLAIMS )->set( [ 'cdc_active' => 1 ] )
				->where( [ 'cdc_host' => $state->cd_candidate, 'cdc_wiki' => $wiki, 'cdc_active' => 0 ] )
				->caller( __METHOD__ )->execute();
			if ( $this->db->affectedRows() !== 1 ) {
				throw new \RuntimeException( 'Custom domain claim disappeared during activation' );
			}
			$this->changeUrl( $wiki, "https://{$state->cd_candidate}" );
			$this->clearCandidate( $wiki, $state, true );
			return $previous;
		} );
	}

	public function cancel( string $wiki, string $token ): void {
		$this->atomic( __METHOD__, function () use ( $wiki, $token ) {
			[ , $state ] = $this->lockCandidate( $wiki, $token );
			if ( $state ) {
				$this->deleteClaim( $wiki, false );
				$this->clearCandidate( $wiki, $state );
			}
		} );
	}

	public function remove( string $wiki, string $expected ): bool {
		return $this->atomic( __METHOD__, function () use ( $wiki, $expected ) {
			$row = $this->wiki( $wiki, true );
			if ( !$row || $row->wiki_deleted || $this->active( $wiki, true ) !== $expected ) {
				return false;
			}
			$this->deleteClaim( $wiki, false );
			$this->deleteClaim( $wiki, true );
			$this->changeUrl( $wiki, '' );
			$this->clearCandidate( $wiki, $this->state( $wiki, true ), true );
			return true;
		} );
	}

	public function fail( string $wiki, string $token, string $error, bool $retry, bool $keepClaim = false ): void {
		$this->atomic( __METHOD__, function () use ( $wiki, $token, $error, $retry, $keepClaim ) {
			[ , $state ] = $this->lockCandidate( $wiki, $token );
			if ( !$state ) {
				return;
			}
			$attempts = min( $state->cd_attempts + 1, 10 );
			if ( !$keepClaim ) {
				$this->deleteClaim( $wiki, false );
			}
			$this->update( $wiki, [
				'cd_due' => $retry ? time() + min( 1800, 60 * ( 2 ** $attempts ) ) : null,
				'cd_attempts' => $attempts,
				'cd_error' => $error,
			] );
		} );
	}

	public function cloudflareEntry( string $host ): ?stdClass {
		return $this->db->newSelectQueryBuilder()->select( '*' )->from( self::CLOUDFLARE )
			->where( [ 'cdf_host' => $host ] )->caller( __METHOD__ )->fetchRow() ?: null;
	}

	public function recordCloudflareIntent( string $wiki, string $token ): bool {
		return $this->atomic( __METHOD__, function () use ( $wiki, $token ) {
			[ $row, $state ] = $this->lockCandidate( $wiki, $token );
			if ( !$row || !$state || $state->cd_expires <= time() ) {
				return false;
			}
			$exists = $this->db->newSelectQueryBuilder()->select( 'cdc_host' )->from( self::CLAIMS )
				->where( [ 'cdc_host' => $state->cd_candidate, 'cdc_wiki' => $wiki, 'cdc_active' => 0 ] )
				->forUpdate()->caller( __METHOD__ )->fetchField();
			if ( !$exists ) {
				return false;
			}
			$this->db->newInsertQueryBuilder()->insertInto( self::CLOUDFLARE )->row( [
				'cdf_host' => $state->cd_candidate, 'cdf_request' => $token, 'cdf_created' => time(),
			] )->ignore()->caller( __METHOD__ )->execute();
			return $this->db->affectedRows() === 1;
		} );
	}

	public function recordCloudflareId( string $host, string $token, string $id ): void {
		$this->db->newUpdateQueryBuilder()->update( self::CLOUDFLARE )->set( [ 'cdf_id' => $id ] )
			->where( [ 'cdf_host' => $host, 'cdf_request' => $token ] )->caller( __METHOD__ )->execute();
	}

	/** @return stdClass[] */
	public function orphanedCloudflareEntries(): array {
		// Keep active registrations and valid candidates. A retired record survives
		// cancellation/removal until the worker has confirmed its cleanup at Cloudflare.
		$rows = $this->db->newSelectQueryBuilder()->select( [ 'f.cdf_host', 'f.cdf_request', 'f.cdf_id' ] )
			->from( self::CLOUDFLARE, 'f' )
			->leftJoin( self::CLAIMS, 'c', 'c.cdc_host = f.cdf_host' )
			->leftJoin( self::STATE, 's', 's.cd_wiki = c.cdc_wiki AND s.cd_token = f.cdf_request' )
			->where( '(c.cdc_host IS NULL OR (c.cdc_active = 0 AND s.cd_wiki IS NULL))' )
			->andWhere( $this->db->expr( 'f.cdf_created', '<=', time() - 60 ) )
			->orderBy( 'f.cdf_created' )->limit( 8 )->caller( __METHOD__ )->fetchResultSet();
		return iterator_to_array( $rows, false );
	}

	public function forgetCloudflareEntry( string $host, string $token ): void {
		$this->db->newDeleteQueryBuilder()->deleteFrom( self::CLOUDFLARE )
			->where( [ 'cdf_host' => $host, 'cdf_request' => $token ] )->caller( __METHOD__ )->execute();
	}

	/** @return stdClass[] */
	public function due(): array {
		return iterator_to_array( $this->db->newSelectQueryBuilder()->select( '*' )->from( self::STATE )
			->where( $this->db->expr( 'cd_due', '<=', time() ) )
			->orderBy( 'cd_due' )->limit( 8 )->caller( __METHOD__ )->fetchResultSet(), false );
	}

	/** @return stdClass[] */
	public function expired(): array {
		return iterator_to_array( $this->db->newSelectQueryBuilder()->select( [ 'cd_wiki', 'cd_token' ] )
			->from( self::STATE )->where( $this->db->expr( 'cd_expires', '<=', time() ) )
			->orderBy( 'cd_expires' )->limit( 50 )->caller( __METHOD__ )->fetchResultSet(), false );
	}

	/** @return stdClass[] */
	public function pendingPublication(): array {
		return iterator_to_array( $this->db->newSelectQueryBuilder()->select( [ 'cd_wiki', 'cd_cache_version' ] )
			->from( self::STATE )->where( [ 'cd_cache_pending' => 1 ] )->caller( __METHOD__ )->fetchResultSet(), false );
	}

	public function markPublished( stdClass $entry ): void {
		$this->db->newUpdateQueryBuilder()->update( self::STATE )->set( [ 'cd_cache_pending' => 0 ] )
			->where( [ 'cd_wiki' => $entry->cd_wiki, 'cd_cache_version' => $entry->cd_cache_version ] )
			->caller( __METHOD__ )->execute();
	}

	private function lockCandidate( string $wiki, string $token ): array {
		// All state transitions take the same per-wiki lock, including concurrent form submissions.
		$row = $this->wiki( $wiki, true );
		$state = $this->state( $wiki, true );
		return [ $row && !$row->wiki_locked && !$row->wiki_deleted ? $row : null,
			$state && $state->cd_token === $token ? $state : null ];
	}

	private function clearCandidate( string $wiki, stdClass $state, bool $publish = false ): void {
		$fields = [
			'cd_candidate' => null, 'cd_token' => null, 'cd_requester' => null, 'cd_expires' => null,
			'cd_due' => null, 'cd_attempts' => 0, 'cd_error' => null,
		];
		if ( $publish ) {
			$fields += [ 'cd_cache_pending' => 1, 'cd_cache_version' => $state->cd_cache_version + 1 ];
		}
		$this->update( $wiki, $fields );
	}

	private function update( string $wiki, array $fields ): void {
		$this->db->newUpdateQueryBuilder()->update( self::STATE )->set( $fields )
			->where( [ 'cd_wiki' => $wiki ] )->caller( __METHOD__ )->execute();
	}

	private function deleteClaim( string $wiki, bool $active ): void {
		$this->db->newDeleteQueryBuilder()->deleteFrom( self::CLAIMS )
			->where( [ 'cdc_wiki' => $wiki, 'cdc_active' => (int)$active ] )->caller( __METHOD__ )->execute();
	}

	private function changeUrl( string $wiki, string $url ): void {
		$remote = $this->remoteWikis->newInstance( $wiki );
		$remote->setServerName( $url );
		$remote->commit( publishCaches: false );
	}

	private function atomic( string $caller, callable $callback ): mixed {
		return $this->db->doAtomicSection( $caller, $callback, IDatabase::ATOMIC_CANCELABLE );
	}
}
