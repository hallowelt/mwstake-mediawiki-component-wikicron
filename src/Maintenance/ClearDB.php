<?php

namespace MWStake\MediaWiki\Component\WikiCron\Maintenance;

use MediaWiki\Maintenance\LoggedUpdateMaintenance;

$maintPath = dirname( __DIR__, 5 ) . '/maintenance/Maintenance.php';
if ( file_exists( $maintPath ) ) {
	require_once $maintPath;
}
class ClearDB extends LoggedUpdateMaintenance {
	/**
	 * @return bool
	 */
	public function doDBUpdates() {
		$db = $this->getDB( DB_PRIMARY );
		if ( !$db->tableExists( 'wiki_cron' ) ) {
			$this->output( "Table 'wiki_cron' does not exist, skipping truncation.\n" );
			return true;
		}
		$db->truncateTable( 'wiki_cron' );
		$this->output( "Truncated table 'wiki_cron'.\n" );
		return true;
	}

	/**
	 * @return string
	 */
	protected function getUpdateKey() {
		return 'mws-wikicron-clear-db';
	}
}

$maintClass = ClearDB::class;
require_once RUN_MAINTENANCE_IF_MAIN;
