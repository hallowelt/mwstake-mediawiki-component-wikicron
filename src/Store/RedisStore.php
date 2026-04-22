<?php

namespace MWStake\MediaWiki\Component\WikiCron\Store;

use DateTime;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\WikiMap\WikiMap;
use MWStake\MediaWiki\Component\ProcessManager\ManagedProcess;
use MWStake\MediaWiki\Component\WikiCron\ICronStore;
use Psr\Log\LoggerInterface;
use RedisException;
use Wikimedia\ObjectCache\RedisConnectionPool;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IResultWrapper;

class RedisStore implements ICronStore {

	/** @var RedisConnectionPool */
	private $redisPool;

	/** @var string */
	private $server;

	/** @var string */
	private $wikiId;

	/** @var LoggerInterface */
	private $logger;

	/**
	 * @param array $params Possible keys:
	 *   - redisConfig : An array of parameters to RedisConnectionPool::__construct().
	 *                   The serializer option is forced to "none".
	 *   - redisServer : A hostname/port combination or the absolute path of a UNIX socket.
	 *                   If a hostname is specified but no port, port 6379 will be used.
	 */
	public function __construct( array $params ) {
		$params['redisConfig']['serializer'] = 'none';
		$this->server = $params['redisServer'];
		$this->redisPool = RedisConnectionPool::singleton( $params['redisConfig'] );
		$this->wikiId = WikiMap::getCurrentWikiId();
		$this->logger = LoggerFactory::getInstance( 'WikiCron' );
	}

	/**
	 * @inheritDoc
	 */
	public function insertCron( string $key, string $interval, ManagedProcess $process ): bool {
		$data = $this->getCronRowData( $key, $interval, $process );
		$data['wc_enabled'] = 1;
		$data['wc_wiki_id'] = $this->getWikiId();
		$data['wc_manual_interval'] = null;

		$conn = $this->getConnection();
		if ( !$conn ) {
			return false;
		}
		try {
			$conn->multi();
			$conn->set( $this->cronKey( $key ), json_encode( $data ) );
			$conn->sAdd( $this->cronNamesKey(), $key );
			$conn->sAdd( $this->globalIndexKey(), $this->getWikiId() . ':' . $key );
			$results = $conn->exec();
			return $results !== false;
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function updateCron( string $key, string $interval, ManagedProcess $process ): bool {
		$conn = $this->getConnection();
		if ( !$conn ) {
			return false;
		}
		try {
			$raw = $conn->get( $this->cronKey( $key ) );
			if ( $raw === false ) {
				return false;
			}
			$existing = json_decode( $raw, true );
			$updated = $this->getCronRowData( $key, $interval, $process );
			$data = array_merge( $existing, $updated );
			return (bool)$conn->set( $this->cronKey( $key ), json_encode( $data ) );
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return false;
		}
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
		return $this->updateField( $key, 'wc_manual_interval', $interval );
	}

	/**
	 * @inheritDoc
	 */
	public function hasCron( string $name ): bool {
		$conn = $this->getConnection();
		if ( !$conn ) {
			return false;
		}
		try {
			return $conn->exists( $this->cronKey( $name ) ) > 0;
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getCron( string $name, ?string $wikiId = null ): ?array {
		$wikiId = $wikiId ?? $this->getWikiId();
		$conn = $this->getConnection();
		if ( !$conn ) {
			return null;
		}
		try {
			$key = $this->cronKeyForWiki( $name, $wikiId );
			$raw = $conn->get( $key );
			if ( $raw === false ) {
				return null;
			}
			return json_decode( $raw, true );
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return null;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getWikiId(): string {
		return $this->wikiId;
	}

	/**
	 * @inheritDoc
	 */
	public function getAll(): array {
		$conn = $this->getConnection();
		if ( !$conn ) {
			return [];
		}
		try {
			$names = $conn->sMembers( $this->cronNamesKey() );
			if ( !$names ) {
				return [];
			}
			$result = [];
			foreach ( $names as $name ) {
				$raw = $conn->get( $this->cronKey( $name ) );
				if ( $raw !== false ) {
					$result[] = json_decode( $raw, true );
				}
			}
			return $result;
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return [];
		}
	}

	/**
	 * @inheritDoc
	 */
	public function setEnabled( string $key, bool $enabled ): bool {
		return $this->updateField( $key, 'wc_enabled', $enabled ? 1 : 0 );
	}

	/**
	 * @inheritDoc
	 */
	public function getPossibleIntervals( array $exclude = [] ): array {
		$conn = $this->getConnection();
		if ( !$conn ) {
			return [];
		}
		try {
			$members = $conn->sMembers( $this->globalIndexKey() );
			if ( !$members ) {
				return [];
			}
			$excludeNames = array_keys( $exclude );
			$intervals = [];
			foreach ( $members as $member ) {
				$parts = explode( ':', $member, 2 );
				if ( count( $parts ) !== 2 ) {
					continue;
				}
				[ $wikiId, $name ] = $parts;
				if ( in_array( $name, $excludeNames ) ) {
					continue;
				}
				$cronKey = $this->cronKeyForWiki( $name, $wikiId );
				$raw = $conn->get( $cronKey );
				if ( $raw === false ) {
					continue;
				}
				$data = json_decode( $raw, true );
				if ( !$data || empty( $data['wc_enabled'] ) ) {
					continue;
				}
				if ( !isset( $intervals[$name] ) ) {
					$intervals[$name] = [];
				}
				$intervals[$name][$wikiId] = $data['wc_manual_interval'] ?? $data['wc_interval'];
			}
			return $intervals;
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return [];
		}
	}

	/**
	 * @inheritDoc
	 */
	public function storeHistory( string $key, string $wikiId, string $pid ): bool {
		$entry = [
			'wch_cron' => $key,
			'wch_pid' => $pid,
			'wch_time' => wfTimestampNow(),
			'wch_wiki_id' => $wikiId,
		];
		$conn = $this->getConnection();
		if ( !$conn ) {
			return false;
		}
		try {
			$histKey = $this->historyKeyForWiki( $key, $wikiId );
			$conn->lPush( $histKey, json_encode( $entry ) );
			// Keep only the last 100 entries
			$conn->lTrim( $histKey, 0, 99 );
			return true;
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getHistory( string $key ): IResultWrapper {
		$conn = $this->getConnection();
		if ( !$conn ) {
			return new FakeResultWrapper( [] );
		}
		try {
			$histKey = $this->historyKey( $key );
			$entries = $conn->lRange( $histKey, 0, 19 );
			if ( !$entries ) {
				return new FakeResultWrapper( [] );
			}

			$rows = [];
			foreach ( $entries as $rawEntry ) {
				$entry = json_decode( $rawEntry, true );
				$process = $this->lookupProcessData( $conn, $entry['wch_wiki_id'], $entry['wch_pid'] );
				$rows[] = (object)[
					'wch_time' => $entry['wch_time'],
					'p_state' => $process['p_state'] ?? null,
					'p_exitcode' => $process['p_exitcode'] ?? null,
					'p_output' => $process['p_output'] ?? null,
				];
			}
			return new FakeResultWrapper( $rows );
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return new FakeResultWrapper( [] );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getLastRun( string $key ): array {
		$conn = $this->getConnection();
		if ( !$conn ) {
			return [ 'time' => null, 'status' => '' ];
		}
		try {
			$histKey = $this->historyKey( $key );
			$rawEntry = $conn->lIndex( $histKey, 0 );
			if ( $rawEntry === false ) {
				return [ 'time' => null, 'status' => '' ];
			}

			$entry = json_decode( $rawEntry, true );
			$process = $this->lookupProcessData( $conn, $entry['wch_wiki_id'], $entry['wch_pid'] );

			$lr = DateTime::createFromFormat( 'YmdHis', $entry['wch_time'] );
			if ( $lr === false ) {
				return [ 'time' => null, 'status' => '' ];
			}
			return [
				'time' => $lr,
				'status' => $process['p_state'] ?? null,
				'exitstatus' => $process['p_exitstatus'] ?? null,
			];
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return [ 'time' => null, 'status' => '' ];
		}
	}

	/**
	 * @inheritDoc
	 */
	public function isReady(): bool {
		return $this->getConnection() !== false;
	}

	/**
	 * @inheritDoc
	 */
	public function getProcessAdditionalArgs( string $name, ?string $wikiId = null ): array {
		return [];
	}

	// -- Key helpers --

	/**
	 * @param string $name
	 * @return string Redis key for a cron entry scoped to current wiki
	 */
	private function cronKey( string $name ): string {
		return $this->cronKeyForWiki( $name, $this->getWikiId() );
	}

	/**
	 * @param string $name
	 * @param string $wikiId
	 * @return string Redis key for a cron entry scoped to a specific wiki
	 */
	private function cronKeyForWiki( string $name, string $wikiId ): string {
		return rawurlencode( $wikiId ) . ':wikicron:cron:' . rawurlencode( $name );
	}

	/**
	 * @return string Redis key for the set of cron names on this wiki
	 */
	private function cronNamesKey(): string {
		return $this->getKeyPrefix() . ':cron-names';
	}

	/**
	 * @param string $name
	 * @return string Redis key for cron history scoped to current wiki
	 */
	private function historyKey( string $name ): string {
		return $this->historyKeyForWiki( $name, $this->getWikiId() );
	}

	/**
	 * @param string $name
	 * @param string $wikiId
	 * @return string Redis key for cron history scoped to a specific wiki
	 */
	private function historyKeyForWiki( string $name, string $wikiId ): string {
		return rawurlencode( $wikiId ) . ':wikicron:history:' . rawurlencode( $name );
	}

	/**
	 * @return string Cross-wiki global index key
	 */
	private function globalIndexKey(): string {
		return 'wikicron:global:cron-index';
	}

	/**
	 * @return string Key prefix scoped to the current wiki
	 */
	private function getKeyPrefix(): string {
		return rawurlencode( $this->getWikiId() ) . ':wikicron';
	}

	/**
	 * Construct a process data key matching the ProcessManager RedisQueue key format.
	 *
	 * @param string $wikiId
	 * @param string $pid
	 * @return string
	 */
	private function processDataKey( string $wikiId, string $pid ): string {
		return rawurlencode( $wikiId ) . ':processmanager:process:' . $pid;
	}

	// -- Internal helpers --

	/**
	 * @param string $key
	 * @param string $interval
	 * @param ManagedProcess $process
	 * @return array
	 */
	private function getCronRowData( string $key, string $interval, ManagedProcess $process ): array {
		return [
			'wc_name' => $key,
			'wc_interval' => $interval,
			'wc_steps' => json_encode( $process->getSteps() ),
			'wc_timeout' => $process->getTimeout(),
		];
	}

	/**
	 * Update a single field on a cron entry.
	 *
	 * @param string $key
	 * @param string $field
	 * @param mixed $value
	 * @return bool
	 */
	private function updateField( string $key, string $field, $value ): bool {
		$conn = $this->getConnection();
		if ( !$conn ) {
			return false;
		}
		try {
			$cronKey = $this->cronKey( $key );
			$raw = $conn->get( $cronKey );
			if ( $raw === false ) {
				return false;
			}
			$data = json_decode( $raw, true );
			$data[$field] = $value;
			return (bool)$conn->set( $cronKey, json_encode( $data ) );
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return false;
		}
	}

	/**
	 * Look up process data from the ProcessManager's Redis keys.
	 *
	 * @param \Redis $conn
	 * @param string $wikiId
	 * @param string $pid
	 * @return array|null
	 */
	private function lookupProcessData( $conn, string $wikiId, string $pid ): ?array {
		$raw = $conn->get( $this->processDataKey( $wikiId, $pid ) );
		if ( $raw === false ) {
			return null;
		}
		return json_decode( $raw, true );
	}

	/**
	 * @return \Redis|false
	 */
	private function getConnection() {
		$conn = $this->redisPool->getConnection( $this->server, $this->logger );
		if ( !$conn ) {
			$this->logger->error( 'Unable to connect to Redis server {server}', [
				'server' => $this->server,
			] );
			return false;
		}
		return $conn;
	}

	/**
	 * @param \Redis $conn
	 * @param RedisException $e
	 */
	private function handleError( $conn, RedisException $e ): void {
		$this->redisPool->handleError( $conn, $e );
		$this->logger->error( 'Redis error in WikiCron: {message}', [
			'message' => $e->getMessage(),
		] );
	}
}
