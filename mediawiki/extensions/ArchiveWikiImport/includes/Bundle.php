<?php
/** @license GPL-2.0-or-later */
namespace MediaWiki\Extension\ArchiveWikiImport;

use RuntimeException;

/** Extracts only streams, never archive-controlled filesystem paths or symlinks. */
class Bundle {
	public function __construct( private string $binary, private int $maxBytes, private int $maxEntries, private $pulse = null, private $workerLock = null ) {
	}
	public function entries( string $archive, string $scratch ): array {
		$this->command( [ 'l', '-slt', '-ba', '--', $archive ], $scratch, 16777216 );
		$listing = file_get_contents( $scratch );
		unlink( $scratch );
		$entries = [];
		$total = 0;
		foreach ( preg_split( '/\r?\n\r?\n/', trim( $listing ) ) as $block ) {
			$fields = [];
			foreach ( preg_split( '/\r?\n/', $block ) as $line ) {
				$pair = explode( ' = ', $line, 2 );
				if ( count( $pair ) === 2 ) {
					$fields[$pair[0]] = $pair[1];
				}
			}
			if ( !isset( $fields['Path'] ) ) {
				continue;
			}
			$name = $fields['Path'];
			if ( !ArchiveSource::safePath( $name ) ) {
				throw new RuntimeException( 'Archive contains an unsafe entry name.' );
			}
			if ( ( $fields['Folder'] ?? '' ) === '+' || str_starts_with( $fields['Attributes'] ?? '', 'D' ) ) {
				continue;
			}
			if ( !isset( $fields['Size'] ) || !ctype_digit( $fields['Size'] ) ) {
				throw new RuntimeException( 'Archive contains an entry without a declared size.' );
			}
			$size = (int)$fields['Size'];
			$total += $size;
			if ( $total > $this->maxBytes || count( $entries ) >= $this->maxEntries || isset( $entries[$name] ) ) {
				throw new RuntimeException( 'Archive exceeds expansion/entry limits or contains duplicate paths.' );
			}
			$entries[$name] = [ 'name' => $name, 'size' => $size ];
		}
		if ( !$entries ) {
			throw new RuntimeException( 'Archive contains no usable entries.' );
		}
		return array_values( $entries );
	}
	public function extract( string $archive, array $entry, string $target ): void {
		$this->command( [ 'x', '-so', '-spd', '-y', '--', $archive, $entry['name'] ], $target, $entry['size'] );
		if ( filesize( $target ) !== $entry['size'] ) {
			throw new RuntimeException( 'Extracted file size differs from the archive manifest.' );
		}
	}
	public function zstd( string $binary, string $archive, string $target ): void {
		( new self( $binary, $this->maxBytes, $this->maxEntries, $this->pulse, $this->workerLock ) )->command(
			[ "-d", "-c", "--", $archive ], $target, $this->maxBytes
		);
	}
	private function command( array $args, string $target, int $limit ): void {
		if ( !is_executable( $this->binary ) ) {
			throw new RuntimeException( 'Install/configure the archive decompressor: ' . $this->binary );
		}
		$errorFile = $target . '.stderr';
		$descriptors = [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'file', $target, 'w' ],
			2 => [ 'file', $errorFile, 'w' ] ];
		// A decompressor surviving SIGKILL must keep the run locked until it exits.
		if ( is_resource( $this->workerLock ) ) {
			$descriptors[3] = $this->workerLock;
		}
		$process = proc_open( array_merge( [ $this->binary ], $args ), $descriptors, $pipes );
		if ( !is_resource( $process ) ) {
			throw new RuntimeException( 'Cannot start 7-Zip.' );
		}
		$start = microtime( true );
		$exceeded = false;
		try {
			do {
				if ( $this->pulse ) { ( $this->pulse )(); }
				$status = proc_get_status( $process );
				clearstatcache( true, $target );
				clearstatcache( true, $errorFile );
				if ( filesize( $target ) > $limit || filesize( $errorFile ) > 1048576 || microtime( true ) - $start > 3600 ) {
					proc_terminate( $process, 9 );
					$exceeded = true;
					break;
				}
				if ( $status['running'] ) {
					usleep( 100000 );
				}
			} while ( $status['running'] );
		} catch ( \Throwable $e ) {
			proc_terminate( $process, 9 );
			throw $e;
		} finally {
			$exit = proc_close( $process );
		}
		unlink( $errorFile );
		if ( $exceeded || ( $status['exitcode'] !== 0 && $exit !== 0 ) ) {
			unlink( $target );
			throw new RuntimeException( 'Decompression failed or exceeded its extraction limits.' );
		}
	}
}
