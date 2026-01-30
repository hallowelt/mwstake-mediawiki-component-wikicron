<?php

use MediaWiki\Maintenance\Maintenance;

//phpcs:disable MediaWiki.NamingConventions.PrefixedGlobalFunctions.allowedPrefix

/**
 * @return string
 */
function getMaintenancePath() {
	if ( isset( $argv[1] ) && file_exists( $argv[1] ) ) {
		return $argv[1];
	}
	return dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/maintenance/Maintenance.php';
}

require_once getMaintenancePath();

class ClearCrons extends Maintenance {

	public function execute() {
		$db = $this->getDB( DB_PRIMARY );
		if ( !$db->tableExists( 'wiki_cron' ) ) {
			$this->output( "Table 'wiki_cron' does not exist, skipping truncation.\n" );
			return false;
		}
		$db->truncateTable( 'wiki_cron' );
		$this->output( "Truncated table 'wiki_cron'.\n" );
		return true;
	}
}

$maintClass = ClearCrons::class;
require_once RUN_MAINTENANCE_IF_MAIN;
