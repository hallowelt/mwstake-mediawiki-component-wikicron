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

			if ( $cron === null ) {
				$this->error( "Requested cron not found\n" );
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
		if ( !$cron ) {
			$this->error( "Requested cron not found\n" );
			return;
		}
		/** @var ProcessManager $processManager */
		$processManager = MediaWikiServices::getInstance()->getService( 'ProcessManager' );
		$pid = $processManager->startProcess( $cron );
		$manager->storeHistory( $name, '', $pid );
		$this->output( "Started process: $pid" );
	}

	/**
	 * @param WikiCronManager $manager
	 * @return void
	 */
	private function outputList( WikiCronManager $manager ) {
		$crons = $manager->getAll();
		$columns = [
			'interval' => 22,
			'key' => 40,
			'enabled' => 10,
			'lastRun' => 25,
			'status' => 13,
		];
		$tableWidth = array_sum( $columns );

		$this->output( str_repeat( '-', $tableWidth ) . "\n" );
		$this->output(
			$this->style( str_pad( 'Interval', $columns['interval'] ), 'cyan' ) .
			$this->style( str_pad( 'Cron key', $columns['key'] ), 'cyan' ) .
			$this->style( str_pad( 'Enabled', $columns['enabled'] ), 'cyan' ) .
			$this->style( str_pad( 'Last run', $columns['lastRun'] ), 'cyan' ) .
			$this->style( str_pad( 'Last status', $columns['status'] ), 'cyan' ) . "\n"
		);
		$this->output( str_repeat( '-', $tableWidth ) . "\n" );
		foreach ( $crons as $cron ) {
			if ( !$manager->isRegistered( $cron['wc_name'] ) ) {
				continue;
			}
			$cron = $this->getCronInfo( $cron, $manager );
			$this->outputWrappedRow( [
				'interval' => $cron['wc_interval'],
				'key' => $cron['wc_name'],
				'enabled' => $cron['wc_enabled'],
				'lastRun' => $cron['last_run'],
				'status' => (string)$cron['last_status'],
			], $columns );
		}
		$this->output( str_repeat( '-', $tableWidth ) . "\n" );
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

		$this->output( $this->style( "Cron key:", 'cyan' ) . " {$cron['wc_name']}\n" );
		$this->output( $this->style( "Interval:", 'cyan' ) . " {$info['wc_interval']}\n" );
		$this->output( $this->style( "Enabled:", 'cyan' ) . " {$this->styleEnabled( $info['wc_enabled'] )}\n" );
		$this->output( $this->style( "Last run:", 'cyan' ) . " {$info['last_run']}\n" );
		$this->output( $this->style( "Last status:", 'cyan' ) . " {$this->styleStatus( $info['last_status'] )}\n" );
		$this->output( str_repeat( '-', 110 ) . "\n" );
		$this->output( $this->style( "Steps:", 'cyan' ) . "\n" );
		$this->output( $info['wc_steps'] . "\n" );
		$this->output( str_repeat( '-', 110 ) . "\n" );
	}

	/**
	 * @param array $row
	 * @param array $columns
	 * @return void
	 */
	private function outputWrappedRow( array $row, array $columns ): void {
		$wrapped = [];
		$maxLines = 1;
		foreach ( $columns as $key => $width ) {
			$wrapped[$key] = $this->splitForColumn( (string)( $row[$key] ?? '' ), $width );
			$maxLines = max( $maxLines, count( $wrapped[$key] ) );
		}
		for ( $line = 0; $line < $maxLines; $line++ ) {
			$out = '';
			foreach ( $columns as $key => $width ) {
				$cell = str_pad( $wrapped[$key][$line] ?? '', $width );
				if ( $key === 'enabled' ) {
					$cell = $this->styleEnabled( $cell );
				} elseif ( $key === 'status' ) {
					$cell = $this->styleStatus( $cell );
				}
				$out .= $cell;
			}
			$this->output( $out . "\n" );
		}
	}

	/**
	 * @param string $value
	 * @param int $width
	 * @return array
	 */
	private function splitForColumn( string $value, int $width ): array {
		$chunks = str_split( $value, $width );
		return $chunks ?: [ '' ];
	}

	/**
	 * @return bool
	 */
	private function supportsAnsiColors(): bool {
		if ( !function_exists( 'posix_isatty' ) ) {
			return false;
		}
		return posix_isatty( STDOUT );
	}

	/**
	 * @param string $text
	 * @param string $color
	 * @return string
	 */
	private function style( string $text, string $color ): string {
		if ( !$this->supportsAnsiColors() ) {
			return $text;
		}
		$map = [
			'red' => "\033[31m",
			'green' => "\033[32m",
			'yellow' => "\033[33m",
			'cyan' => "\033[36m",
		];
		if ( !isset( $map[$color] ) ) {
			return $text;
		}
		return $map[$color] . $text . "\033[0m";
	}

	/**
	 * @param string $enabled
	 * @return string
	 */
	private function styleEnabled( string $enabled ): string {
		$enabledTrimmed = trim( $enabled );
		if ( $enabledTrimmed === 'Yes' ) {
			return $this->style( $enabled, 'green' );
		}
		if ( $enabledTrimmed === 'No' ) {
			return $this->style( $enabled, 'red' );
		}
		return $enabled;
	}

	/**
	 * @param mixed $status
	 * @return string
	 */
	private function styleStatus( $status ): string {
		$statusText = (string)$status;
		$statusLower = strtolower( trim( $statusText ) );
		if ( in_array( $statusLower, [ '0', 'ok', 'success', 'done' ] ) ) {
			return $this->style( $statusText, 'green' );
		}
		if ( in_array( $statusLower, [ '-', 'running', 'queued', 'pending' ] ) ) {
			return $this->style( $statusText, 'yellow' );
		}
		if ( $statusLower !== '' ) {
			return $this->style( $statusText, 'red' );
		}
		return $statusText;
	}

	/**
	 * @param IResultWrapper $history
	 * @return void
	 */
	private function outputHistory( IResultWrapper $history ) {
		$this->output( "Last run status:\n" );

		$this->output( str_repeat( '-', 110 ) . "\n" );
		$this->output(
			str_pad( 'Time', 25 ) .
			str_pad( 'State', 20 ) .
			str_pad( 'Exit code', 15 ) .
			"Output\n"
		);
		$this->output( str_repeat( '-', 110 ) . "\n" );
		foreach ( $history as $row ) {
			if ( !$row->p_state ) {
				// Missing process
				continue;
			}
			$this->output(
				str_pad( $row->wch_time, 25 ) .
				str_pad( $row->p_state, 20 ) .
				str_pad( $row->p_exitcode, 15 ) .
				implode(
					"\n" . str_repeat( ' ', 60 ),
					str_split( $row->p_output, 100 )
				) . "\n"
			);
			$this->output( str_repeat( '-', 110 ) . "\n" );
		}
		$this->output( str_repeat( '-', 110 ) . "\n" );
	}

}

$maintClass = WikiCron::class;
require_once RUN_MAINTENANCE_IF_MAIN;
