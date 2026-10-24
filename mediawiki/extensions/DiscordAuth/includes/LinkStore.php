<?php
/** @license GPL-2.0-or-later */

namespace MediaWiki\Extension\DiscordAuth;

use MediaWiki\Extension\CentralAuth\CentralAuthDatabaseManager;
use RuntimeException;

/** All wikis share one mapping to immutable CentralAuth IDs, including after renames. */
class LinkStore {
	public function __construct( private CentralAuthDatabaseManager $databases ) {
	}

	public function globalId( string $discordId ): ?int {
		$id = $this->databases->getCentralPrimaryDB()->newSelectQueryBuilder()
			->select( 'dal_global_user' )->from( 'discordauth_link' )
			->where( [ 'dal_discord_id' => $discordId ] )->caller( __METHOD__ )->fetchField();
		return $id === false ? null : (int)$id;
	}

	public function discordId( int $globalId ): ?string {
		$id = $this->databases->getCentralPrimaryDB()->newSelectQueryBuilder()
			->select( 'dal_discord_id' )->from( 'discordauth_link' )
			->where( [ 'dal_global_user' => $globalId ] )->caller( __METHOD__ )->fetchField();
		return $id === false ? null : (string)$id;
	}

	public function link( string $discordId, int $globalId ): void {
		if ( $globalId <= 0 ) {
			throw new RuntimeException( 'Discord requires an attached global account' );
		}
		$db = $this->databases->getCentralPrimaryDB();
		$db->newInsertQueryBuilder()->insertInto( 'discordauth_link' )->ignore()->row( [
			'dal_discord_id' => $discordId,
			'dal_global_user' => $globalId,
			'dal_created' => $db->timestamp(),
		] )->caller( __METHOD__ )->execute();
		// Both unique indexes are authoritative, even when two callbacks race.
		if ( $this->globalId( $discordId ) !== $globalId ) {
			throw new RuntimeException( 'Discord account is already linked' );
		}
	}

	public function unlink( string $discordId, int $globalId ): void {
		$this->databases->getCentralPrimaryDB()->newDeleteQueryBuilder()
			->deleteFrom( 'discordauth_link' )
			->where( [ 'dal_discord_id' => $discordId, 'dal_global_user' => $globalId ] )
			->caller( __METHOD__ )->execute();
	}
}
