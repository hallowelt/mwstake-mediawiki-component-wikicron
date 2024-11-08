<?php

namespace MWStake\MediaWiki\Component\WikiCron;

use MWStake\MediaWiki\Component\ProcessManager\ManagedProcess;
class WikiCronManager {

	/**
	 * @param string $key
	 * @param string $interval
	 * @param ManagedProcess $process
	 * @return true
	 */
	public function registerCron( string $key, string $interval, ManagedProcess $process ) {
		return true;
	}
}
