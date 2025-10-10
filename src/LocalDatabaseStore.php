<?php

namespace MWStake\MediaWiki\Component\WikiCron;

use MediaWiki\WikiMap\WikiMap;
use MWStake\MediaWiki\Component\ProcessManager\ManagedProcess;
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
		$this->getDB( DB_PRIMARY )?->newInsertQueryBuilder()
			->insert( 'wiki_cron' )
			->row( $data )
			->caller( __METHOD__ )
			->execute();

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function updateCron( string $key, string $interval, ManagedProcess $process ): bool {
		$data = $this->getCronRowData( $key, $interval, $process );
		$this->getDB( DB_PRIMARY )?->newUpdateQueryBuilder()
			->update( 'wiki_cron' )
			->set( $data )
			->where( [ 'wc_name' => $key, 'wc_wiki_id' => $this->getWikiId() ] )
			->caller( __METHOD__ )
			->execute();

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
		$this->getDB( DB_PRIMARY )?->newUpdateQueryBuilder()
			->update( 'wiki_cron' )
			->set( [ 'wc_manual_interval' => $interval ] )
			->where( [ 'wc_name' => $key, 'wc_wiki_id' => $this->getWikiId() ] )
			->caller( __METHOD__ )
			->execute();

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function hasCron( string $name ): bool {
		return (bool)$this->getDB( DB_REPLICA )?->newSelectQueryBuilder()
			->from( 'wiki_cron' )
			->select( '1' )
			->where( [ 'wc_name' => $name, 'wc_wiki_id' => $this->getWikiId() ] )
			->fetchRowCount() > 0;
	}

	/**
	 * @inheritDoc
	 */
	public function getCron( string $name, ?string $wikiId = null ): ?array {
		$wikiId = $wikiId ?? $this->getWikiId();
		$row = $this->getDB( DB_REPLICA )?->newSelectQueryBuilder()
			->from( 'wiki_cron' )
			->select( IDatabase::ALL_ROWS )
			->where( [ 'wc_name' => $name, 'wc_wiki_id' => $wikiId ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row ) {
			return null;
		}

		return (array)$row;
	}

	/**
	 * @inheritDoc
	 */
	public function getAll(): array {
		$res = $this->getDB( DB_REPLICA )->newSelectQueryBuilder()
			->from( 'wiki_cron' )
			->select( IDatabase::ALL_ROWS )
			->where( [ 'wc_wiki_id' => $this->getWikiId() ] )
			->caller( __METHOD__ )
			->fetchResultSet();

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
		$this->getDB( DB_PRIMARY )->newUpdateQueryBuilder()
			->update( 'wiki_cron' )
			->set( [ 'wc_enabled' => $enabled ? 1 : 0 ] )
			->where( [ 'wc_name' => $key, 'wc_wiki_id' => $this->getWikiId() ] )
			->caller( __METHOD__ )
			->execute();

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getPossibleIntervals( array $exclude = [] ): array {
		$intervals = [];
		$db = $this->getDB( DB_REPLICA );
		$query = $db->newSelectQueryBuilder();
		$query->from( 'wiki_cron' );
		$query->conds( [ 'wc_enabled' => 1 ] );
		if ( $exclude ) {
			$query->conds( 'wc_name NOT IN (' . $db->makeList( $exclude ) . ')' );
		}
		$query->select( [ 'wc_name', 'wc_interval', 'wc_manual_interval', 'wc_wiki_id' ] );

		$res = $query->caller( __METHOD__ )->fetchResultSet();
		foreach ( $res as $row ) {
			if ( !isset( $intervals[$row->wc_name] ) ) {
				$intervals[$row->wc_name] = [];
			}
			if ( $row->wc_manual_interval ) {
				$intervals[$row->wc_name][$row->wc_wiki_id] = $row->wc_manual_interval;
				continue;
			}
			$intervals[$row->wc_name][$row->wc_wiki_id] = $row->wc_interval;
		}
		return $intervals;
	}

	/**
	 * @inheritDoc
	 */
	public function storeHistory( string $key, string $wikiId, string $pid ): bool {
		$this->getDB( DB_PRIMARY )->newInsertQueryBuilder()
			->insert( 'wiki_cron_history' )
			->row( [
				'wch_cron' => $key,
				'wch_pid' => $pid,
				'wch_time' => wfTimestampNow(),
				'wch_wiki_id' => $wikiId,
			] )
			->caller( __METHOD__ )
			->execute();

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getHistory( string $key ): IResultWrapper {
		return $this->getDB( DB_REPLICA )->newSelectQueryBuilder()
			->from( 'wiki_cron_history', 'wch' )
			->from( 'processes', 'p' )
			->select( [ 'wch_time', 'p_state', 'p_exitcode', 'p_output' ] )
			->where( [ 'wch_cron' => $key, 'wch_wiki_id' => $this->getWikiId() ] )
			->caller( __METHOD__ )
			->orderBy( [ 'wch_time' ], 'DESC' )
			->limit( 20 )
			->leftJoin( 'processes', 'p', [ 'wch_pid = p_pid' ] )
			->fetchResultSet();
	}

	/**
	 * @inheritDoc
	 */
	public function getLastRun( string $key ): array {
		$row = $this->getDB( DB_REPLICA )->newSelectQueryBuilder()
			->from( 'wiki_cron_history', 'wch' )
			->from( 'processes', 'p' )
			->select( [ 'wch_time', 'p_state', 'p_exitstatus' ] )
			->where( [ 'wch_cron' => $key, 'wch_wiki_id' => $this->getWikiId() ] )
			->caller( __METHOD__ )
			->orderBy( [ 'wch_time' ], 'DESC' )
			->leftJoin( 'processes', 'p', [ 'wch_pid = p_pid' ] )
			->fetchRow();
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
		return (bool)$this->getDB( DB_REPLICA )?->tableExists( 'wiki_cron' );
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
	 * @param IDatabase $db
	 * @return void
	 */
	protected function tryCloseDb( IDatabase $db ) {
		try {
			$db->close();
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
