<?php
/** @license GPL-2.0-or-later */
namespace MediaWiki\Extension\ArchiveWikiImport;

use MediaWiki\MediaWikiServices;
use RuntimeException;
use JsonException;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

/** Durable state is wiki-scoped; lock files are distinct from atomically replaced state. */
class RunStore {
	public const MAX_ACTIVE = 3;
	public const MAX_DAILY = 5;
	public const LEASE_SECONDS = 300;
	public const MAX_RECOVERIES = 3;
	private string $root;
	private DateTimeZone $timezone;
	private Closure $clock;
	public function __construct( ?string $directory = null, ?string $wiki = null,
		?string $timezone = null, ?callable $clock = null ) {
		$runtimeConfig = $directory === null || $wiki === null;
		$this->timezone = new DateTimeZone( $timezone ?? ( $runtimeConfig
			? ( MediaWikiServices::getInstance()->getMainConfig()->get( 'Localtimezone' ) ?: 'UTC' ) : 'UTC' ) );
		$this->clock = Closure::fromCallable( $clock ?? 'time' );
		$directory ??= MediaWikiServices::getInstance()->getMainConfig()->get( 'ArchiveWikiImportDirectory' ) ?: '';
		if ( !$directory || !str_starts_with( $directory, '/' ) ) {
			throw new RuntimeException( 'Set $wgArchiveWikiImportDirectory to a private, shared absolute directory outside the web root.' );
		}
		$wiki ??= MediaWikiServices::getInstance()->getMainConfig()->get( 'DBname' ) . ':' .
			MediaWikiServices::getInstance()->getMainConfig()->get( 'DBprefix' );
		$this->root = rtrim( $directory, '/' ) . '/' . hash( 'sha256', $wiki );
		if ( !is_dir( $this->root ) && !mkdir( $this->root, 0700, true ) && !is_dir( $this->root ) ) {
			throw new RuntimeException( 'Cannot create private import storage.' );
		}
	}
	public function directory( string $id ): string {
		if ( !preg_match( '/^[a-f0-9]{32}$/D', $id ) ) {
			throw new RuntimeException( 'Invalid import run identifier.' );
		}
		return $this->root . '/' . $id;
	}
	public function create( array $state ): string {
		return $this->withAdmissionLock( function () use ( $state ): string {
			$this->checkDailyCapacity();
			if ( in_array( $state['status'] ?? 'queued', [ 'queued', 'running' ], true ) ) {
				$this->checkCapacity();
			}
			$state['created'] = gmdate( 'c', ( $this->clock )() );
			$id = $this->createUnlocked( $state );
			// Reconcile after saving: a crash before the ledger write is recoverable from created.
			$this->dailyUsageUnlocked();
			return $id;
		} );
	}
	private function createUnlocked( array $state ): string {
		$id = bin2hex( random_bytes( 16 ) );
		if ( !mkdir( $this->directory( $id ), 0700 ) ) {
			throw new RuntimeException( 'Cannot create import run.' );
		}
		$this->save( $id, $state + [ 'status' => 'queued', 'pages' => 0, 'revisions' => 0,
			'images' => 0, 'imageOffset' => 0, 'skippedImages' => 0, 'warnings' => [],
			'phase' => 'pages', 'updated' => gmdate( 'c' ) ] );
		return $id;
	}
	/** Early UI check; create() repeats it under the same lock when actually admitting the run. */
	public function assertCanStart(): void {
		$this->withAdmissionLock( function (): void {
			$this->checkDailyCapacity();
			$this->checkCapacity();
		} );
	}
	public function dailyUsage(): array {
		return $this->withAdmissionLock( fn () => $this->dailyUsageUnlocked() );
	}
	private function checkDailyCapacity(): void {
		$usage = $this->dailyUsageUnlocked();
		if ( $usage['count'] >= self::MAX_DAILY ) {
			throw new RuntimeException( 'This wiki has reached its daily limit of ' . self::MAX_DAILY .
				' new imports. The limit resets at ' . $usage['resets'] . ' (' . $usage['timezone'] .
				'). Existing imports can still be resumed.' );
		}
	}
	/** Keep accounting separate from reports so cleanup and later status changes cannot refund starts. */
	private function dailyUsageUnlocked(): array {
		$path = $this->root . '/daily-admissions.json';
		$exists = is_file( $path );
		$admissions = [];
		if ( $exists ) {
			$data = json_decode( file_get_contents( $path ), true, 64, JSON_THROW_ON_ERROR );
			if ( !is_array( $data ) || !isset( $data['admissions'] ) || !is_array( $data['admissions'] ) ) {
				throw new RuntimeException( 'Daily import accounting is invalid; administrator repair is required.' );
			}
			$admissions = $data['admissions'];
		}
		$changed = !$exists;
		foreach ( $this->reports() as $id => $state ) {
			if ( !isset( $admissions[$id] ) ) {
				// Older reports predate created; retain the first observed update as their baseline.
				$admissions[$id] = $state['created'] ?? $state['updated'];
				$changed = true;
			}
		}
		$now = ( new DateTimeImmutable( '@' . ( $this->clock )() ) )->setTimezone( $this->timezone );
		$start = $now->setTime( 0, 0 );
		$reset = $start->modify( '+1 day' );
		$count = 0;
		foreach ( $admissions as $id => $created ) {
			if ( !preg_match( '/^[a-f0-9]{32}$/D', $id ) || !is_string( $created ) ) {
				throw new RuntimeException( 'Daily import accounting is invalid; administrator repair is required.' );
			}
			$timestamp = new DateTimeImmutable( $created );
			if ( $timestamp >= $start && $timestamp < $reset ) {
				$count++;
			}
		}
		if ( $changed && ( file_put_contents( $path . '.tmp', json_encode( [ 'admissions' => $admissions ],
			JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT ) ) === false || !rename( $path . '.tmp', $path ) ) ) {
			throw new RuntimeException( 'Cannot save daily import accounting.' );
		}
		return [ 'count' => $count, 'limit' => self::MAX_DAILY, 'timezone' => $this->timezone->getName(),
			'resets' => $reset->format( 'Y-m-d H:i:s P' ) ];
	}
	public function activeCount(): int {
		return $this->listRuns( 'queued' )['total'] + $this->listRuns( 'running' )['total'];
	}
	private function checkCapacity(): void {
		if ( $this->activeCount() >= self::MAX_ACTIVE ) {
			throw new RuntimeException( 'This wiki already has ' . self::MAX_ACTIVE .
				' active imports (queued or running). Wait for an import to finish before starting or resuming another.' );
		}
	}
	private function withAdmissionLock( callable $callback ) {
		$lock = fopen( $this->root . '/admission.lock', 'c' );
		if ( !$lock || !flock( $lock, LOCK_EX ) ) {
			if ( $lock ) {
				fclose( $lock );
			}
			throw new RuntimeException( 'Cannot lock this wiki’s import admissions.' );
		}
		try {
			return $callback();
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}
	/** Reserve a slot before publishing a resumed job. Already admitted runs reuse theirs. */
	public function resume( string $id ): void {
		$lock = $this->lock( $id );
		if ( !$lock ) {
			// A worker holds the run lock, so it is already handling this request.
			return;
		}
		try {
			$this->withAdmissionLock( function () use ( $id ): void {
				$state = $this->read( $id );
				if ( $state['status'] === 'complete' ) {
					throw new RuntimeException( 'This import is already complete.' );
				}
				if ( !in_array( $state['status'], [ 'queued', 'running' ], true ) ) {
					$this->checkCapacity();
					$state['status'] = 'queued';
					unset( $state['error'], $state['recoveryAttempts'], $state['recoveryProgress'],
						$state['recoveryDue'], $state['recoveryPublished'] );
					$this->save( $id, $state );
				}
			} );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}
	/** Called with the run lock held; covers CLI/direct jobs as well as queued jobs. */
	public function activate( string $id ): array {
		return $this->withAdmissionLock( function () use ( $id ): array {
			$state = $this->read( $id );
			if ( !in_array( $state['status'], [ 'queued', 'running' ], true ) ) {
				$this->checkCapacity();
			}
			$state['status'] = 'running';
			unset( $state['error'], $state['recoveryDue'], $state['recoveryPublished'] );
			$this->save( $id, $state );
			return $state;
		} );
	}
	/** The caller holds worker.lock. Heartbeats never rewrite page/image checkpoints. */
	public function heartbeat( string $id, string $worker ): void {
		$now = ( $this->clock )();
		$path = $this->directory( $id ) . '/heartbeat.json';
		$data = [ 'worker' => $worker, 'heartbeat' => $now, 'leaseExpires' => $now + self::LEASE_SECONDS ];
		if ( file_put_contents( $path . '.tmp', json_encode( $data, JSON_THROW_ON_ERROR ) ) === false ||
			!rename( $path . '.tmp', $path ) ) {
			throw new RuntimeException( 'Cannot renew import worker lease.' );
		}
	}
	public function workerActivity( string $id ): ?array {
		$path = $this->directory( $id ) . '/heartbeat.json';
		if ( !is_file( $path ) ) {
			return null;
		}
		$data = json_decode( file_get_contents( $path ), true, 64, JSON_THROW_ON_ERROR );
		return is_array( $data ) && isset( $data['worker'], $data['heartbeat'], $data['leaseExpires'] ) ? $data : null;
	}
	/** Recovery owns worker.lock through enqueue, including the state-to-queue crash window. */
	public function recover( callable $enqueue ): array {
		$result = [ 'scheduled' => 0, 'requeued' => 0, 'stalled' => 0, 'failed' => 0 ];
		foreach ( $this->reports() as $id => $candidate ) {
			if ( !in_array( $candidate['status'], [ 'queued', 'running' ], true ) ) {
				continue;
			}
			$lock = $this->lock( $id );
			if ( !$lock ) {
				$activity = $this->workerActivity( $id );
				if ( ( $activity['leaseExpires'] ?? strtotime( $candidate['updated'] ) + self::LEASE_SECONDS ) < ( $this->clock )() ) {
					$result['stalled']++;
				}
				continue;
			}
			try {
				$this->withAdmissionLock( function () use ( $id, $enqueue, &$result ): void {
					$state = $this->read( $id );
					if ( !in_array( $state['status'], [ 'queued', 'running' ], true ) ) {
						return;
					}
					$now = ( $this->clock )();
					if ( isset( $state['recoveryDue'] ) ) {
						if ( $now < $state['recoveryDue'] ) {
							return;
						}
						// Publish first. A crash can cause a duplicate, which worker.lock makes harmless.
						$enqueue( $id );
						unset( $state['recoveryDue'] );
						$state['recoveryPublished'] = $now;
						$this->save( $id, $state );
						$result['requeued']++;
						return;
					}
					$activity = $state['status'] === 'running' ? $this->workerActivity( $id ) : null;
					$expires = $activity['leaseExpires'] ??
						( ( $state['recoveryPublished'] ?? strtotime( $state['updated'] ) ) + self::LEASE_SECONDS );
					if ( $now < $expires ) {
						return;
					}
					$progress = [ $state['phase'], $state['pages'], $state['revisions'], $state['images'], $state['imageOffset'] ?? 0 ];
					$attempts = ( $state['recoveryProgress'] ?? null ) === $progress
						? (int)( $state['recoveryAttempts'] ?? 0 ) : 0;
					if ( $attempts >= self::MAX_RECOVERIES ) {
						$state['status'] = 'failed';
						$state['error'] = 'Automatic recovery stopped after three attempts without committed progress. Check worker logs and resume manually.';
						$result['failed']++;
					} else {
						$state['status'] = 'queued';
						$state['recoveryAttempts'] = $attempts + 1;
						$state['recoveryProgress'] = $progress;
						$state['recoveryDue'] = $now + 60 * ( 2 ** $attempts );
						$result['scheduled']++;
					}
					$this->save( $id, $state );
				} );
			} finally {
				flock( $lock, LOCK_UN );
				fclose( $lock );
			}
		}
		return $result;
	}

	public function read( string $id ): array {
		$path = $this->directory( $id ) . '/state.json';
		if ( !is_file( $path ) ) {
			throw new RuntimeException( 'Import run not found on this wiki.' );
		}
		return json_decode( file_get_contents( $path ), true, 64, JSON_THROW_ON_ERROR );
	}
	public function save( string $id, array $state ): void {
		$state['updated'] = gmdate( 'c' );
		$path = $this->directory( $id ) . '/state.json';
		if ( file_put_contents( $path . '.tmp', json_encode( $state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT ) ) === false ||
			!rename( $path . '.tmp', $path ) ) {
			throw new RuntimeException( 'Cannot save import progress.' );
		}
	}
	/** List only this wiki's runs. Atomically replaced or damaged reports cannot break the queue. */
	public function listRuns( string $status = 'active', ?int $requester = null, string $item = '',
		int $offset = 0, int $limit = 50 ): array {
		$runs = [];
		foreach ( $this->reports() as $id => $state ) {
			if ( ( $status === 'active' && !in_array( $state['status'], [ 'queued', 'running', 'needs-attention', 'failed' ], true ) ) ||
				( $status !== 'active' && $status !== 'all' && $state['status'] !== $status ) ||
				( $requester !== null && (int)( $state['user'] ?? 0 ) !== $requester ) ||
				( $item !== '' && stripos( $state['item'], $item ) === false ) ) {
				continue;
			}
			$runs[] = [ 'id' => $id ] + array_intersect_key( $state, array_flip( [
				'item', 'user', 'status', 'phase', 'pages', 'revisions', 'images', 'updated'
			] ) );
		}
		usort( $runs, static fn ( $a, $b ) => strcmp( $b['updated'], $a['updated'] ) ?: strcmp( $b['id'], $a['id'] ) );
		return [ 'total' => count( $runs ), 'runs' => array_slice( $runs, max( 0, $offset ), min( 100, max( 1, $limit ) ) ) ];
	}
	private function reports(): iterable {
		foreach ( glob( $this->root . '/*/state.json' ) ?: [] as $path ) {
			$id = basename( dirname( $path ) );
			if ( !preg_match( '/^[a-f0-9]{32}$/D', $id ) || is_link( dirname( $path ) ) || is_link( $path ) ) {
				continue;
			}
			$json = @file_get_contents( $path );
			if ( $json === false ) {
				continue;
			}
			try {
				$state = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
			} catch ( JsonException $e ) {
				continue;
			}
			if ( !is_array( $state ) || !isset( $state['status'], $state['item'], $state['updated'] ) ||
				!is_string( $state['status'] ) || !is_string( $state['item'] ) || !is_string( $state['updated'] ) ) {
				continue;
			}
			yield $id => $state;
		}
	}
	public function lock( string $id ) {
		$path = $this->directory( $id );
		if ( !is_dir( $path ) ) {
			throw new RuntimeException( 'Import run not found.' );
		}
		$lock = fopen( $path . '/worker.lock', 'c' );
		if ( !$lock || !flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( $lock ) {
				fclose( $lock );
			}
			return false;
		}
		return $lock;
	}
}
