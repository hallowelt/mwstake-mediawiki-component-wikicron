<?php

namespace MWStake\MediaWiki\Component\WikiCron;

use DateTime;
use DeferredUpdates;
use Exception;
use MWStake\MediaWiki\Component\ProcessManager\ManagedProcess;
use Poliander\Cron\CronExpression;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\IResultWrapper;

class WikiCronManager {

	/**
	 * @var ILoadBalancer
	 */
	protected $lb;

	/**
	*  @var array
	*/
	private $registry = [];

	/**
	 * @param ILoadBalancer $lb
	 */
	public function __construct( ILoadBalancer $lb ) {
		$this->lb = $lb;
		DeferredUpdates::addCallableUpdate( function () {
			foreach ( $this->registry as $key => $data ) {
				$this->doRegisterCron( $key, $data['interval'], $data['process'] );
			}
		} );
	}

	/**
	 * @param string $key
	 * @param string $interval
	 * @param ManagedProcess $process
	 * @return void
	 */
	public function registerCron( string $key, string $interval, ManagedProcess $process ) {
		$this->registry[$key] = [
			'interval' => $interval,
			'process' => $process
		];
	}

	/**
	 * @param string $key
	 * @param string $interval
	 * @param ManagedProcess $process
	 * @return void
	 */
	private function doRegisterCron( string $key, string $interval, ManagedProcess $process ) {
		if ( !$this->isSetUp() ) {
			return;
		}
		$ce = new CronExpression( $interval );
		if ( !$ce->isValid() ) {
			throw new \InvalidArgumentException( 'Invalid cron expression' );
		}
		$has = $this->hasCron( $key );
		$data = [
			'wc_name' => $key,
			'wc_interval' => $interval,
			'wc_steps' => json_encode( $process->getSteps() ),
			'wc_timeout' => $process->getTimeout()
		];
		if ( $has ) {
			$this->lb->getConnection( DB_PRIMARY )->update(
				'wiki_cron',
				$data,
				[ 'wc_name' => $key ]
			);
		} else {
			$data['wc_enabled'] = 1;
			$this->lb->getConnection( DB_PRIMARY )->insert(
				'wiki_cron',
				$data
			);
		}
	}

	/**
	 * @param string $key
	 * @param string $interval
	 * @return void
	 */
	public function setInterval( string $key, string $interval ) {
		if ( !$this->hasCron( $key ) ) {
			throw new \InvalidArgumentException( 'Cron not found' );
		}
		if ( $interval !== 'default' ) {
			$ce = new CronExpression( $interval );
			if ( !$ce->isValid() ) {
				throw new \InvalidArgumentException( 'Invalid cron expression' );
			}
			$this->lb->getConnection( DB_PRIMARY )->update(
				'wiki_cron',
				[ 'wc_manual_interval' => $interval ],
				[ 'wc_name' => $key ]
			);
		} else {
			$this->lb->getConnection( DB_PRIMARY )->update(
				'wiki_cron',
				[ 'wc_manual_interval' => null ],
				[ 'wc_name' => $key ]
			);
		}
	}

	/**
	 * @param string $name
	 * @return DateTime|null
	 */
	public function getLastRun( string $name ): array {
		$row = $this->lb->getConnection( DB_REPLICA )->selectRow(
			[ 'wch' => 'wiki_cron_history', 'p' => 'processes' ],
			[ 'wch_time', 'p_state', 'p_exitstatus' ],
			[ 'wch_cron' => $name ],
			__METHOD__,
			[ 'ORDER BY' => 'wch_time DESC' ],
			[ 'p' => [ 'LEFT JOIN', [ 'wch_pid=p_pid' ] ] ]
		);
		if ( !$row ) {
			return [
				'time' => null,
				'status' => ''
			];
		}
		$lr = DateTime::createFromFormat( 'YmdHis', $row->wch_time );
		if ( $lr === false ) {
			return [
				'time' => null,
				'status' => ''
			];
		}
		return [
			'time' => $lr,
			'status' => $row->p_state,
			'exitstatus' => $row->p_exitstatus
		];
	}

	/**
	 * @param string $name
	 * @param string $pid
	 * @return void
	 */
	public function storeHistory( string $name, string $pid ) {
		$this->lb->getConnection( DB_PRIMARY )->insert(
			'wiki_cron_history',
			[
				'wch_cron' => $name,
				'wch_pid' => $pid,
				'wch_time' => wfTimestampNow()
			],
			__METHOD__
		);
	}

	/**
	 * @param string $name
	 * @return IResultWrapper
	 */
	public function getHistory( string $name ): IResultWrapper {
		return $this->lb->getConnection( DB_REPLICA )->select(
			[ 'wch' => 'wiki_cron_history', 'p' => 'processes' ],
			[ 'wch_time', 'p_state', 'p_exitcode', 'p_output' ],
			[ 'wch_cron' => $name ],
			__METHOD__,
			[ 'ORDER BY' => 'wch_time DESC', 'LIMIT' => 20 ],
			[ 'p' => [ 'LEFT JOIN', [ 'wch_pid=p_pid' ] ] ]
		);
	}

	/**
	 * @param int|null $lastRun
	 * @return array
	 * @throws Exception
	 */
	public function getDue( ?int $lastRun ): array {
		$due = [];
		// If last run was more than a minute ago, get timestamps for each minute since
		// then, otherwise just get the current minute
		$date = new \DateTime();
		$date->setTime( $date->format( 'H' ), $date->format( 'i' ), 0 );
		if ( $lastRun !== null ) {
			$lastRun = new \DateTime( "@$lastRun" );
			while ( $date > $lastRun ) {
				$due = array_merge( $this->evaluate( $date, $due ), $due );
				$date->modify( '-1 minute' );
			}
		} else {
			$date->setTime( $date->format( 'H' ), $date->format( 'i' ), 0 );
			$due = $this->evaluate( $date );
		}
		$due = array_unique( $due );
		$processes = [];
		foreach ( $due as $name ) {
			$processes[$name] = $this->getProcessFromCronName( $name );
		}
		return $processes;
	}

	/**
	 * @param DateTime $time
	 * @param array $exclude
	 * @return array
	 * @throws Exception
	 */
	private function evaluate( DateTime $time, array $exclude = [] ): array {
		$due = [];
		$possible = $this->getPossibleIntervals( $exclude );
		foreach ( $possible as $name => $interval ) {
			$exp = new CronExpression( $interval );
			if ( $exp->isValid() && $exp->isMatching( $time ) ) {
				$due[] = $name;
			}
		}
		return $due;
	}

	/**
	 * @param array $exclude
	 * @return array
	 */
	private function getPossibleIntervals( array $exclude = [] ) {
		$intervals = [];
		$db = $this->lb->getConnection( DB_REPLICA );
		$conds = [ 'wc_enabled' => 1 ];
		if ( $exclude ) {
			$conds[] = 'wc_name NOT IN (' . $db->makeList( $exclude ) . ')';
		}
		$res = $db->select(
			'wiki_cron',
			[ 'wc_name', 'wc_interval', 'wc_manual_interval' ],
			$conds
		);
		foreach ( $res as $row ) {
			if ( $row->wc_manual_interval ) {
				$intervals[$row->wc_name] = $row->wc_manual_interval;
				continue;
			}
			$intervals[$row->wc_name] = $row->wc_interval;
		}
		return $intervals;
	}

	/**
	 * @param string $key
	 * @param bool $enabled
	 * @return void
	 */
	public function setEnabled( string $key, bool $enabled = true ) {
		if ( !$this->hasCron( $key ) ) {
			throw new \InvalidArgumentException( 'Cron not found' );
		}
		$this->lb->getConnection( DB_PRIMARY )->update(
			'wiki_cron',
			[ 'wc_enabled' => $enabled ? 1 : 0 ],
			[ 'wc_name' => $key ]
		);
	}

	/**
	 * @param string $key
	 * @return array
	 */
	public function getCron( string $key ): array {
		$row = $this->lb->getConnection( DB_REPLICA )->selectRow(
			'wiki_cron',
			'*',
			[ 'wc_name' => $key ]
		);
		if ( !$row ) {
			throw new \InvalidArgumentException( 'Cron not found' );
		}
		return (array)$row;
	}

	/**
	 * @return array
	 */
	public function getAll(): array {
		$res = $this->lb->getConnection( DB_REPLICA )->select(
			'wiki_cron',
			'*'
		);
		$ret = [];
		foreach ( $res as $row ) {
			$ret[] = (array)$row;
		}
		return $ret;
	}

	/**
	 * @param string $key
	 * @return bool
	 */
	private function hasCron( string $key ): bool {
		return (bool)$this->lb->getConnection( DB_REPLICA )->selectField(
			'wiki_cron',
			'1',
			[ 'wc_name' => $key ]
		);
	}

	/**
	 * @param string $name
	 * @return ManagedProcess
	 */
	public function getProcessFromCronName( string $name ): ManagedProcess {
		$cron = $this->getCron( $name );
		return new ManagedProcess( json_decode( $cron['wc_steps'], true ), (int)$cron['wc_timeout'] );
	}

	/**
	 * @return bool
	 */
	private function isSetUp(): bool {
		return $this->lb->getConnection( DB_REPLICA )->tableExists( 'wiki_cron' );
	}
}
