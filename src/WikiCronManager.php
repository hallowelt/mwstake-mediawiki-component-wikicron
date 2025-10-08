<?php

namespace MWStake\MediaWiki\Component\WikiCron;

use DateTime;
use Exception;
use MediaWiki\Deferred\DeferredUpdates;
use MWStake\MediaWiki\Component\ProcessManager\ManagedProcess;
use ObjectCacheFactory;
use Poliander\Cron\CronExpression;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IResultWrapper;

class WikiCronManager {

	/** @var array */
	private $registry = [];

	/**
	 * @param ICronStore $cronStore
	 * @param ObjectCacheFactory $objectCacheFactory
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		private readonly ICronStore $cronStore,
		private readonly ObjectCacheFactory $objectCacheFactory,
		private readonly LoggerInterface $logger
	) {
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

		$hasChanges = $this->cronStore->hasChanges( $key, $interval, $process );
		if ( $hasChanges === true ) {
			if ( !$this->cronStore->updateCron( $key, $interval, $process ) ) {
				$this->logger->error( 'Failed to update cron {key}', [ 'key' => $key ] );
			}
		} elseif ( $hasChanges === null ) {
			if ( !$this->cronStore->insertCron( $key, $interval, $process ) ) {
				$this->logger->error( 'Failed to insert cron {key}', [ 'key' => $key ] );
			}
		}
	}

	/**
	 * @param string $key
	 * @param string $interval
	 * @return void
	 */
	public function setInterval( string $key, string $interval ) {
		if ( !$this->cronStore->hasCron( $key ) ) {
			throw new \InvalidArgumentException( 'Cron not found' );
		}
		if ( $interval !== 'default' ) {
			$ce = new CronExpression( $interval );
			if ( !$ce->isValid() ) {
				throw new \InvalidArgumentException( 'Invalid cron expression' );
			}
			$this->cronStore->setInterval( $key, $interval );
		} else {
			$this->cronStore->setInterval( $key, null );
		}
	}

	/**
	 * @param string $name
	 * @return DateTime|null
	 */
	public function getLastRun( string $name ): array {
		return $this->cronStore->getLastRun( $name );
	}

	/**
	 * @param string $name
	 * @param string $wikiId
	 * @param string $pid
	 * @return void
	 */
	public function storeHistory( string $name, string $wikiId, string $pid ) {
		$this->cronStore->storeHistory( $name, $wikiId, $pid );
	}

	/**
	 * @param string $name
	 * @return IResultWrapper
	 */
	public function getHistory( string $name ): IResultWrapper {
		return $this->cronStore->getHistory( $name );
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
				$due = array_merge_recursive( $this->evaluate( $date, $due ), $due );
				$date->modify( '-1 minute' );
			}
		} else {
			$date->setTime( $date->format( 'H' ), $date->format( 'i' ), 0 );
			$due = $this->evaluate( $date );
		}
		$due = array_map( static function ( $a ) {
			return array_unique( $a );
		}, $due );
		$processes = [];
		foreach ( $due as $name => $wikis ) {
			foreach ( $wikis as $wikiId ) {
				$process = $this->getProcessFromCronName( $name, $wikiId );
				if ( $process ) {
					if ( !isset( $processes[$name] ) ) {
						$processes[$name] = [];
					}
					$processes[$name][$wikiId] = $process;
				}
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
		$possible = $this->cronStore->getPossibleIntervals( $exclude );
		foreach ( $possible as $name => $wikis ) {
			foreach ( $wikis as $wikiId => $interval ) {
				$exp = new CronExpression( $interval );
				if ( $exp->isValid() && $exp->isMatching( $time ) ) {
					if ( !isset( $due[$name] ) ) {
						$due[$name] = [];
					}
					$due[$name][] = $wikiId;
				}
			}

		}
		return $due;
	}

	/**
	 * @param string $key
	 * @param bool $enabled
	 * @return void
	 */
	public function setEnabled( string $key, bool $enabled = true ) {
		if ( !$this->cronStore->hasCron( $key ) ) {
			throw new \InvalidArgumentException( 'Cron not found' );
		}
		$this->cronStore->setEnabled( $key, $enabled );
	}

	/**
	 * @param string $key
	 * @return array
	 */
	public function getCron( string $key, ?string $wikiId = null ): ?array {
		$objectCache = $this->objectCacheFactory->getLocalServerInstance();
		$fname = __METHOD__;
		$wikiId = $wikiId ?? $this->cronStore->getWikiId();

		return $objectCache->getWithSetCallback(
			$objectCache->makeKey( 'mwscomponentwikicron-getcron', $key, $wikiId ),
			$objectCache::TTL_PROC_SHORT,
			function () use ( $key, $fname, $wikiId ) {
				return $this->cronStore->getCron( $key, $wikiId );
			}
		);
	}

	/**
	 * @return array
	 */
	public function getAll(): array {
		return $this->cronStore->getAll();
	}

	/**
	 * @param string $name
	 * @return ManagedProcess
	 */
	public function getProcessFromCronName( string $name, ?string $wikiId = null ): ?ManagedProcess {
		$wikiId = $wikiId ?? $this->cronStore->getWikiId();
		$cron = $this->getCron( $name, $wikiId );
		if ( !$cron ) {
			return null;
		}
		$process = new ManagedProcess( json_decode( $cron['wc_steps'], true ), (int)$cron['wc_timeout'] );
		$additionalArgs = $this->cronStore->getProcessAdditionalArgs( $name, $wikiId );
		$process->setAdditionalArgs( $additionalArgs );
		return $process;
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
				return $this->cronStore->isReady();
			}
		);
	}
}
