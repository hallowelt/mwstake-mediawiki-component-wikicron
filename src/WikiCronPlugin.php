<?php

namespace MWStake\MediaWiki\Component\WikiCron;

use Exception;
use MWStake\MediaWiki\Component\ProcessManager\IProcessManagerPlugin;
use MWStake\MediaWiki\Component\ProcessManager\ProcessInfo;
use MWStake\MediaWiki\Component\ProcessManager\ProcessManager;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

class WikiCronPlugin implements IProcessManagerPlugin, LoggerAwareInterface {

	/**
	 * @var WikiCronManager
	 */
	protected $cronManager;

	/**
	 * @var LoggerInterface
	 */
	protected $logger;

	/**
	 * @param WikiCronManager $cronManager
	 */
	public function __construct( WikiCronManager $cronManager ) {
		$this->cronManager = $cronManager;
	}

	/**
	 * @param ProcessManager $manager
	 * @param int|null $lastRun
	 * @return ProcessInfo[]
	 */
	public function run( ProcessManager $manager, ?int $lastRun ): array {
		$infos = [];
		try {
			$due = $this->cronManager->getDue( $lastRun );
			foreach ( $due as $name => $wikiProcesses ) {
				foreach ( $wikiProcesses as $wikiId => $process ) {
					$pid = $manager->startProcess( $process );
					$info = $manager->getProcessInfo( $pid );
					$this->cronManager->storeHistory( $name, $wikiId, $pid );
					if ( $info instanceof ProcessInfo ) {
						$infos[] = $info;
					}
				}

			}
		} catch ( Exception $e ) {
			$this->logger->error( "Wiki-cron plugin: failed getting due crons: " . $e->getMessage() );
		}

		return $infos;
	}

	/**
	 * @return string
	 */
	public function getKey(): string {
		return 'wikicron';
	}

	/**
	 * @param LoggerInterface $logger
	 * @return void
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}
}
