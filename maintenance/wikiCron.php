<?php

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\ProcessManager\ProcessManager;
use MWStake\MediaWiki\Component\WikiCron\WikiCronManager;
use Wikimedia\Rdbms\IResultWrapper;

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

class WikiCron extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Wiki scheduled task information' );
		$this->addOption( 'name', 'Name of the cron to get information about', false, true );
		$this->addOption( 'disable', 'Disable cron' );
		$this->addOption( 'enable', 'Enable cron' );
		$this->addOption( 'interval', 'Set cron interval', false, true );
		$this->addOption( 'force-run', 'Force run a cron' );
	}

	/**
	 * @return bool|void|null
	 */
	public function execute() {
		/** @var WikiCronManager $manager */
		$manager = MediaWikiServices::getInstance()->getService( 'MWStake.WikiCronManager' );
		$name = $this->getOption( 'name' );
		if ( $name ) {
			if ( $this->hasOption( 'disable' ) ) {
				$this->disableCron( $name, $manager );
				return;
			}
			if ( $this->hasOption( 'enable' ) ) {
				$this->enableCron( $name, $manager );
				return;
			}
			if ( $this->hasOption( 'interval' ) ) {
				$interval = $this->getOption( 'interval' );
				$manager->setInterval( $name, $interval );
				$this->output( "Cron \"$name\" interval set to \"$interval\"\n" );
				return;
			}
			if ( $this->hasOption( 'force-run' ) ) {
				$this->forceRun( $name, $manager );
				return;
			}
			try {
				$cron = $manager->getCron( $name );
				$history = $manager->getHistory( $name );
			} catch ( Exception $e ) {
				$this->error( "Requested cron not found\n" );
				$this->outputList( $manager );
				return;
			}

			$this->outputCronInfo( $cron, $manager );
			$this->outputHistory( $history );
			return;
		}
		$this->outputList( $manager );
	}

	/**
	 * @param string $name
	 * @param WikiCronManager $manager
	 * @return void
	 */
	private function forceRun( string $name, WikiCronManager $manager ) {
		$cron = $manager->getProcessFromCronName( $name );
		/** @var ProcessManager $processManager */
		$processManager = MediaWikiServices::getInstance()->getService( 'ProcessManager' );
		$pid = $processManager->startProcess( $cron );
		$manager->storeHistory( $name, $pid );
		$this->output( "Started process: $pid" );
	}

	/**
	 * @param WikiCronManager $manager
	 * @return void
	 */
	private function outputList( WikiCronManager $manager ) {
		$crons = $manager->getAll();

		$this->output( str_repeat( '-', 110 ) . "\n" );
		$this->output(
			str_pad( 'Interval', 22 ) .
			str_pad( 'Cron key', 40 ) .
			str_pad( 'Enabled', 10 ) .
			str_pad( 'Last run', 25 ) .
			"Last Status\n"
		);
		$this->output( str_repeat( '-', 110 ) . "\n" );
		foreach ( $crons as $cron ) {
			$cron = $this->getCronInfo( $cron, $manager );
			$this->output(
				str_pad( $cron['wc_interval'], 22 ) .
				str_pad( $cron['wc_name'], 40 ) .
				str_pad( $cron['wc_enabled'], 10 ) .
				str_pad( $cron['last_run'], 25 ) .
				$cron['last_status'] . "\n"
			);
		}
		$this->output( str_repeat( '-', 110 ) . "\n" );
	}

	/**
	 * @param array $cron
	 * @param WikiCronManager $manager
	 * @return array
	 */
	private function getCronInfo( array $cron, WikiCronManager $manager ): array {
		$lastRun = $manager->getLastRun( $cron['wc_name'] );
		$lastRun['time'] = $lastRun['time'] instanceof DateTime ?
			$lastRun['time']->format( 'Y-m-d H:i:s' ) : 'Never';
		return [
			'wc_name' => $cron['wc_name'],
			'wc_interval' => $cron['wc_manual_interval'] ?
				$cron['wc_manual_interval'] . ' (ovr)' : $cron['wc_interval'],
			'wc_enabled' => $cron['wc_enabled'] ? 'Yes' : 'No',
			'wc_steps' => json_encode( json_decode( $cron['wc_steps'], true ), JSON_PRETTY_PRINT ),
			'last_run' => $lastRun['time'],
			'last_status' => $lastRun['exitstatus'] ?? '-',
		];
	}

	/**
	 * @param string $name
	 * @param WikiCronManager $manager
	 * @return void
	 */
	private function disableCron( string $name, WikiCronManager $manager ) {
		$manager->setEnabled( $name, false );
		$this->output( "Cron \"$name\" disabled!\n" );
	}

	/**
	 * @param string $name
	 * @param WikiCronManager $manager
	 * @return void
	 */
	private function enableCron( string $name, WikiCronManager $manager ) {
		$manager->setEnabled( $name, true );
		$this->output( "Cron \"$name\" enabled!\n" );
	}

	/**
	 * @param array $cron
	 * @param WikiCronManager $manager
	 * @return void
	 */
	private function outputCronInfo( array $cron, WikiCronManager $manager ) {
		$info = $this->getCronInfo( $cron, $manager );

		$this->output( "Cron key: {$cron['wc_name']}\n" );
		$this->output( "Interval: {$info['wc_interval']}\n" );
		$this->output( "Enabled: {$cron['wc_enabled']}\n" );
		$this->output( "Last run: {$info['last_run']}\n" );
		$this->output( "Last status: {$info['last_status']}\n" );
		$this->output( str_repeat( '-', 110 ) . "\n" );
		$this->output( "Steps:\n" );
		$this->output( $info['wc_steps'] . "\n" );
		$this->output( str_repeat( '-', 110 ) . "\n" );
	}

	/**
	 * @param IResultWrapper $history
	 * @return void
	 */
	private function outputHistory( IResultWrapper $history ) {
		$this->output( "Execution history (max 20):\n" );

		$this->output( str_repeat( '-', 110 ) . "\n" );
		$this->output(
			str_pad( 'Time', 25 ) .
			str_pad( 'State', 20 ) .
			str_pad( 'Exit code', 15 ) .
			"Output\n"
		);
		$this->output( str_repeat( '-', 110 ) . "\n" );
		foreach ( $history as $row ) {
			$this->output(
				str_pad( $row->wch_time, 25 ) .
				str_pad( $row->p_state, 20 ) .
				str_pad( $row->p_exitcode, 15 ) .
				implode(
					"\n" . str_repeat( ' ', 60 ),
					str_split( $row->p_output, 100 )
				) . "\n"
			);
		}
		$this->output( str_repeat( '-', 110 ) . "\n" );
	}

}

$maintClass = WikiCron::class;
require_once RUN_MAINTENANCE_IF_MAIN;
