<?php

namespace MWStake\MediaWiki\Component\WikiCron\Store;

use MediaWiki\WikiMap\WikiMap;
use MWStake\MediaWiki\Component\ProcessManager\ManagedProcess;
use MWStake\MediaWiki\Component\WikiCron\ICronStore;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\IResultWrapper;

class LocalDatabaseStore implements ICronStore {

	public function __construct(
		protected readonly ILoadBalancer $loadBalancer
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function insertCron( string $key, string $interval, ManagedProcess $process ): bool {
		$data = $this->getCronRowData( $key, $interval, $process );
		$data['wc_enabled'] = 1;
		$data['wc_wiki_id'] = $this->getWikiId();
		$db = $this->getDB( DB_PRIMARY );
		$db?->newInsertQueryBuilder()
			->insert( 'wiki_cron' )
			->row( $data )
			->caller( __METHOD__ )
			->execute();
		$this->tryCloseDb( $db );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function updateCron( string $key, string $interval, ManagedProcess $process ): bool {
		$data = $this->getCronRowData( $key, $interval, $process );
		$db = $this->getDB( DB_PRIMARY );
		$db?->newUpdateQueryBuilder()
			->update( 'wiki_cron' )
			->set( $data )
			->where( [ 'wc_name' => $key, 'wc_wiki_id' => $this->getWikiId() ] )
			->caller( __METHOD__ )
			->execute();
		$this->tryCloseDb( $db );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function hasChanges( string $key, string $interval, ManagedProcess $process ): ?bool {
		$data = $this->getCronRowData( $key, $interval, $process );
		$cron = $this->getCron( $key );
		if ( !$cron ) {
			return null;
		}
		$diff = array_diff_assoc( $data, $cron );
		return !empty( $diff );
	}

	/**
	 * @inheritDoc
	 */
	public function setInterval( string $key, ?string $interval ): bool {
		$db = $this->getDB( DB_PRIMARY );
		$db?->newUpdateQueryBuilder()
			->update( 'wiki_cron' )
			->set( [ 'wc_manual_interval' => $interval ] )
			->where( [ 'wc_name' => $key, 'wc_wiki_id' => $this->getWikiId() ] )
			->caller( __METHOD__ )
			->execute();
		$this->tryCloseDb( $db );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function hasCron( string $name ): bool {
		$db = $this->getDB();
		$res = (bool)$db?->newSelectQueryBuilder()
			->from( 'wiki_cron' )
			->select( '1' )
			->where( [ 'wc_name' => $name, 'wc_wiki_id' => $this->getWikiId() ] )
			->fetchRowCount() > 0;

		$this->tryCloseDb( $db );
		return $res;
	}

	/**
	 * @inheritDoc
	 */
	public function getCron( string $name, ?string $wikiId = null ): ?array {
		$wikiId = $wikiId ?? $this->getWikiId();
		$db = $this->getDB();
		$row = $db?->newSelectQueryBuilder()
			->from( 'wiki_cron' )
			->select( IDatabase::ALL_ROWS )
			->where( [ 'wc_name' => $name, 'wc_wiki_id' => $wikiId ] )
			->caller( __METHOD__ )
			->fetchRow();
		$this->tryCloseDb( $db );

		if ( !$row ) {
			return null;
		}

		return (array)$row;
	}

	/**
	 * @inheritDoc
	 */
	public function getAll(): array {
		$db = $this->getDB();
		$res = $db?->newSelectQueryBuilder()
			->from( 'wiki_cron' )
			->select( IDatabase::ALL_ROWS )
			->where( [ 'wc_wiki_id' => $this->getWikiId() ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$this->tryCloseDb( $db );

		$ret = [];
		foreach ( $res as $row ) {
			$ret[] = (array)$row;
		}
		return $ret;
	}

	/**
	 * @inheritDoc
	 */
	public function setEnabled( string $key, bool $enabled ): bool {
		$db = $this->getDB( DB_PRIMARY );
		$db?->newUpdateQueryBuilder()
			->update( 'wiki_cron' )
			->set( [ 'wc_enabled' => $enabled ? 1 : 0 ] )
			->where( [ 'wc_name' => $key, 'wc_wiki_id' => $this->getWikiId() ] )
			->caller( __METHOD__ )
			->execute();
		$this->tryCloseDb( $db );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getPossibleIntervals( array $exclude = [] ): array {
		$intervals = [];
		$db = $this->getDB();
		$query = $db->newSelectQueryBuilder();
		$query->from( 'wiki_cron' );
		$query->from( 'wiki_cron_history', 'wch' );
		$query->leftJoin( 'wiki_cron_history', 'wch', [ 'wc_name = wch_cron', 'wc_wiki_id = wch_wiki_id' ] );
		$query->conds( [ 'wc_enabled' => 1 ] );
		if ( $exclude ) {
			$query->conds( 'wc_name NOT IN (' . $db->makeList( $exclude ) . ')' );
		}
		$query->select( [ 'wc_name', 'wc_interval', 'wc_manual_interval', 'wc_wiki_id', 'wch_time' ] );

		$res = $query->caller( __METHOD__ )->fetchResultSet();
		foreach ( $res as $row ) {
			if ( !isset( $intervals[$row->wc_name] ) ) {
				$intervals[$row->wc_name] = [];
			}
			$interval = $row->wc_interval;
			if ( $row->wc_manual_interval ) {
				$interval = $row->wc_manual_interval;
			}
			$lastRun = \DateTime::createFromFormat( 'YmdHis', $row->wch_time );
			$lastRunTime = null;
			if ( $lastRun instanceof \DateTime ) {
				$lastRunTime = $lastRun;
			}
			$intervals[$row->wc_name][$row->wc_wiki_id] = [
				'interval' => $interval,
				'lastRun' => $lastRunTime,
			];
		}
		return $intervals;
	}

	/**
	 * @inheritDoc
	 */
	public function storeHistory( string $key, string $wikiId, string $pid ): bool {
		$db = $this->getDB( DB_PRIMARY );
		// Delete all past history
		$db?->newDeleteQueryBuilder()
			->delete( 'wiki_cron_history' )
			->where( [ 'wch_cron' => $key, 'wch_wiki_id' => $wikiId ] )
			->caller( __METHOD__ )
			->execute();

		$db?->newInsertQueryBuilder()
			->insert( 'wiki_cron_history' )
			->row( [
				'wch_cron' => $key,
				'wch_pid' => $pid,
				'wch_time' => wfTimestampNow(),
				'wch_wiki_id' => $wikiId,
			] )
			->caller( __METHOD__ )
			->execute();

		$this->tryCloseDb( $db );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getHistory( string $key ): IResultWrapper {
		$db = $this->getDB( DB_REPLICA );
		$res = $db?->newSelectQueryBuilder()
			->from( 'wiki_cron_history', 'wch' )
			->from( 'processes', 'p' )
			->select( [ 'wch_time', 'p_state', 'p_exitcode', 'p_output' ] )
			->where( [ 'wch_cron' => $key, 'wch_wiki_id' => $this->getWikiId() ] )
			->caller( __METHOD__ )
			->orderBy( [ 'wch_time' ], 'DESC' )
			->leftJoin( 'processes', 'p', [ 'wch_pid = p_pid' ] )
			->fetchResultSet();
		$this->tryCloseDb( $db );
		return $res;
	}

	/**
	 * @inheritDoc
	 */
	public function getLastRun( string $key, ?string $wikidId = null ): array {
		$db = $this->getDB();
		$row = $db?->newSelectQueryBuilder()
			->from( 'wiki_cron_history', 'wch' )
			->from( 'processes', 'p' )
			->select( [ 'wch_time', 'p_state', 'p_exitstatus' ] )
			->where( [ 'wch_cron' => $key, 'wch_wiki_id' => $wikidId ?? $this->getWikiId() ] )
			->caller( __METHOD__ )
			->orderBy( [ 'wch_time' ], 'DESC' )
			->leftJoin( 'processes', 'p', [ 'wch_pid = p_pid' ] )
			->fetchRow();

		$this->tryCloseDb( $db );
		if ( !$row ) {
			return [
				'time' => null,
				'status' => ''
			];
		}
		$lr = \DateTime::createFromFormat( 'YmdHis', $row->wch_time );
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
	 * @inheritDoc
	 */
	public function getWikiId(): string {
		return WikiMap::getCurrentWikiId();
	}

	/**
	 * @inheritDoc
	 */
	public function isReady(): bool {
		return (bool)$this->getDB()?->tableExists( 'wiki_cron' );
	}

	/**
	 * @inheritDoc
	 */
	public function getProcessAdditionalArgs( string $name, ?string $wikiId = null ): array {
		return [];
	}

	/**
	 * @param int $type
	 * @return \Wikimedia\Rdbms\IDatabase|null
	 */
	protected function getDB( int $type = DB_REPLICA ): ?IDatabase {
		return $this->loadBalancer->getConnection( $type ) ?: null;
	}

	/**
	 * @param IDatabase|null $db
	 * @return void
	 */
	protected function tryCloseDb( ?IDatabase $db ) {
		try {
			if ( $db ) {
				$db->close();
			}
		} catch ( \Exception $e ) {
			// NOOP
		}
	}

	/**
	 * @param string $key
	 * @param string $interval
	 * @param ManagedProcess $process
	 * @return array
	 */
	protected function getCronRowData( string $key, string $interval, ManagedProcess $process ): array {
		return [
			'wc_name' => $key,
			'wc_interval' => $interval,
			'wc_steps' => json_encode( $process->getSteps() ),
			'wc_timeout' => $process->getTimeout(),
		];
	}
}
