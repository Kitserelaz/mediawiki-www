<?php
/**
 * @file
 * @ingroup Maintenance
 */
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class AddMissingContests extends LoggedUpdateMaintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Adds missing contest entries based on the ratepage_vote table.' );
	}

	/**
	 * Do the actual work. All child classes will need to implement this.
	 * Return true to log the update as done or false (usually on failure).
	 * @return bool
	 */
	protected function doDBUpdates() : bool {
		$dbw = $this->getDB( DB_PRIMARY );

		$res = $dbw->select(
			[
				'ratepage_vote',
				'ratepage_contest'
			],
			'rv_contest',
			[
				"rv_contest != ''",
				'rpc_id IS NULL'
			],
			__METHOD__,
			[ 'DISTINCT' ],
			[
				'ratepage_contest' => [
					'LEFT JOIN',
					[ 'rpc_id = rv_contest' ]
				]
			]
		);

		$toInsert = [];

		foreach ( $res as $row ) {
			$toInsert[] = [
				'rpc_id' => $row->rv_contest,
				'rpc_description' => '',
				'rpc_enabled' => 0,
				'rpc_allowed_to_vote' => '*',
				'rpc_allowed_to_see' => '*'
			];
		}

		if ( !empty( $toInsert ) ) {
			$dbw->insert(
				'ratepage_contest',
				$toInsert,
				__METHOD__
			);
		}

		return true;
	}

	/**
	 * Get the update key name to go in the update log table
	 * @return string
	 */
	protected function getUpdateKey() : string {
		return __CLASS__;
	}
}