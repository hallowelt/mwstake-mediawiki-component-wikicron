<?php

namespace MWStake\MediaWiki\Component\WikiCron;

use MWStake\MediaWiki\Component\ProcessManager\IProcessManagerPlugin;
use MWStake\MediaWiki\Component\ProcessManager\ProcessInfo;
use MWStake\MediaWiki\Component\ProcessManager\ProcessManager;

class WikiCronPlugin implements IProcessManagerPlugin {

	/**
	 * @param ProcessManager $manager
	 * @return ProcessInfo|null
	 */
	public function run( ProcessManager $manager ): ?ProcessInfo {
		return null;
	}

	/**
	 * @return string
	 */
	public function getKey(): string {
		return 'wikicron';
	}
}
