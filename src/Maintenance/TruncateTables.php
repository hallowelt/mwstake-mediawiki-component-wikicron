<?php

namespace MWStake\MediaWiki\Component\WikiCron\Maintenance;

use MediaWiki\Maintenance\LoggedUpdateMaintenance;

class TruncateTables extends LoggedUpdateMaintenance {

	/**
	 * @return bool
	 */
	protected function doDBUpdates() {
		$db = $this->getDB( DB_PRIMARY );
		if ( !$db->tableExists( 'wiki_cron' ) ) {
			$this->output( "Table 'wiki_cron' does not exist, skipping truncation.\n" );
			return false;
		}
		$db->truncateTable( 'wiki_cron' );
		$this->output( "Truncated table 'wiki_cron'.\n" );
		return true;
	}

	/**
	 * @return string
	 */
	protected function getUpdateKey() {
		return 'mwstake-wikicron-truncate-tables';
	}
}