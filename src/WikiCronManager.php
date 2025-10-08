<?php

namespace MWStake\MediaWiki\Component\WikiCron;

use DateTime;
use Exception;
use MediaWiki\Deferred\DeferredUpdates;
use MWStake\MediaWiki\Component\ProcessManager\ManagedProcess;
use ObjectCacheFactory;
use Poliander\Cron\CronExpression;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IResultWrapper;

class WikiCronManager {

	/** @var IConnectionProvider */
	protected $connectionProvider;

	/** @var ObjectCacheFactory */
	private $objectCacheFactory;

	/** @var array */
	private $registry = [];

	/**
	 * @param IConnectionProvider $connectionProvider
	 * @param ObjectCacheFactory $objectCacheFactory
	 */
	public function __construct( IConnectionProvider $connectionProvider, ObjectCacheFactory $objectCacheFactory ) {
		$this->connectionProvider = $connectionProvider;
		$this->objectCacheFactory = $objectCacheFactory;
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
		$data = [
			'wc_name' => $key,
			'wc_interval' => $interval,
			'wc_steps' => json_encode( $process->getSteps() ),
			'wc_timeout' => $process->getTimeout()
		];
		$hasChanges = $this->hasChanges( $key, $data );
		if ( $hasChanges === true ) {
			$this->connectionProvider->getPrimaryDatabase()->update(
				'wiki_cron',
				$data,
				[ 'wc_name' => $key ],
				__METHOD__
			);
		} elseif ( $hasChanges === null ) {
			$data['wc_enabled'] = 1;
			$this->connectionProvider->getPrimaryDatabase()->insert(
				'wiki_cron',
				$data,
				__METHOD__
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
			$this->connectionProvider->getPrimaryDatabase()->update(
				'wiki_cron',
				[ 'wc_manual_interval' => $interval ],
				[ 'wc_name' => $key ],
				__METHOD__
			);
		} else {
			$this->connectionProvider->getPrimaryDatabase()->update(
				'wiki_cron',
				[ 'wc_manual_interval' => null ],
				[ 'wc_name' => $key ],
				__METHOD__
			);
		}
	}

	/**
	 * @param string $name
	 * @return DateTime|null
	 */
	public function getLastRun( string $name ): array {
		$row = $this->connectionProvider->getReplicaDatabase()->selectRow(
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
		$this->connectionProvider->getPrimaryDatabase()->insert(
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
		return $this->connectionProvider->getReplicaDatabase()->select(
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
			$process = $this->getProcessFromCronName( $name );
			if ( $process ) {
				$processes[$name] = $process;
			}
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
		$dbr = $this->connectionProvider->getReplicaDatabase();
		$conds = [ 'wc_enabled' => 1 ];
		if ( $exclude ) {
			$conds[] = 'wc_name NOT IN (' . $dbr->makeList( $exclude ) . ')';
		}
		$res = $dbr->select(
			'wiki_cron',
			[ 'wc_name', 'wc_interval', 'wc_manual_interval' ],
			$conds,
			__METHOD__
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
		$this->connectionProvider->getPrimaryDatabase()->update(
			'wiki_cron',
			[ 'wc_enabled' => $enabled ? 1 : 0 ],
			[ 'wc_name' => $key ],
			__METHOD__
		);
	}

	/**
	 * @param string $key
	 * @return array
	 */
	public function getCron( string $key ): ?array {
		$objectCache = $this->objectCacheFactory->getLocalServerInstance();
		$fname = __METHOD__;

		return $objectCache->getWithSetCallback(
			$objectCache->makeKey( 'mwscomponentwikicron-getcron', $key ),
			$objectCache::TTL_PROC_SHORT,
			function () use ( $key, $fname ) {
				$dbr = $this->connectionProvider->getReplicaDatabase();
				$row = $dbr->newSelectQueryBuilder()
					->table( 'wiki_cron' )
					->field( $dbr::ALL_ROWS )
					->where( [ 'wc_name' => $key ] )
					->caller( $fname )
					->fetchRow();

				if ( !$row ) {
					return null;
				}

				return (array)$row;
			}
		);
	}

	/**
	 * @return array
	 */
	public function getAll(): array {
		$res = $this->connectionProvider->getReplicaDatabase()->select(
			'wiki_cron',
			'*',
			'',
			__METHOD__
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
		return (bool)$this->connectionProvider->getReplicaDatabase()->selectField(
			'wiki_cron',
			'1',
			[ 'wc_name' => $key ],
			__METHOD__
		);
	}

	/**
	 * @param string $key
	 * @param array $data
	 * @return bool|null null if cannot find cron, true if has changes, false if no changes
	 */
	private function hasChanges( string $key, array $data ): ?bool {
		$cron = $this->getCron( $key );
		if ( !$cron ) {
			return null;
		}
		$diff = array_diff_assoc( $data, $cron );
		return !empty( $diff );
	}

	/**
	 * @param string $name
	 * @return ManagedProcess
	 */
	public function getProcessFromCronName( string $name ): ?ManagedProcess {
		$cron = $this->getCron( $name );
		if ( !$cron ) {
			return null;
		}
		return new ManagedProcess( json_decode( $cron['wc_steps'], true ), (int)$cron['wc_timeout'] );
	}

	/**
	 * @return bool
	 */
	private function isSetUp(): bool {
		$objectCache = $this->objectCacheFactory->getLocalServerInstance();
		$fname = __METHOD__;

		return $objectCache->getWithSetCallback(
			$objectCache->makeKey( 'mwscomponentwikicron-issetup' ),
			$objectCache::TTL_PROC_SHORT,
			function () use ( $fname ) {
				/** @var DBConnRef $dbr */
				$dbr = $this->connectionProvider->getReplicaDatabase();
				return $dbr->tableExists( 'wiki_cron', $fname );
			}
		);
	}
}
