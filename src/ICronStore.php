<?php

namespace MWStake\MediaWiki\Component\WikiCron;

use MWStake\MediaWiki\Component\ProcessManager\ManagedProcess;
use Wikimedia\Rdbms\IResultWrapper;

interface ICronStore {

	/**
	 * @param string $key
	 * @param string $interval
	 * @param ManagedProcess $process
	 * @return bool
	 */
	public function insertCron( string $key, string $interval, ManagedProcess $process ): bool;

	/**
	 * @param string $key
	 * @param string $interval
	 * @param ManagedProcess $process
	 * @return bool
	 */
	public function updateCron( string $key, string $interval, ManagedProcess $process ): bool;

	/**
	 * @param string $key
	 * @param string|null $interval
	 * @return bool
	 */
	public function setInterval( string $key, ?string $interval ): bool;

	/**
	 * @param string $name
	 * @return bool
	 */
	public function hasCron( string $name ): bool;

	/**
	 * @param string $name
	 * @param string|null $wikiId
	 * @return array|null
	 */
	public function getCron( string $name, ?string $wikiId = null ): ?array;

	/**
	 * @return string
	 */
	public function getWikiId(): string;

	/**
	 * @return array
	 */
	public function getAll(): array;

	/**
	 * @param string $key
	 * @param bool $enabled
	 * @return bool
	 */
	public function setEnabled( string $key, bool $enabled ): bool;

	/**
	 * @param array $exclude
	 * @return array
	 */
	public function getPossibleIntervals( array $exclude = [] ): array;

	/**
	 * @param string $key
	 * @param string $wikiId
	 * @param string $pid
	 * @return bool
	 */
	public function storeHistory( string $key, string $wikiId, string $pid ): bool;

	/**
	 * @param string $key
	 * @return IResultWrapper
	 */
	public function getHistory( string $key ): IResultWrapper;

	/**
	 * @param string $key
	 * @return array
	 */
	public function getLastRun( string $key ): array;

	/**
	 * @param string $key
	 * @param string $interval
	 * @param ManagedProcess $process
	 * @return bool|null
	 */
	public function hasChanges( string $key, string $interval, ManagedProcess $process ): ?bool;

	/**
	 * Check if store is ready
	 *
	 * @return bool
	 */
	public function isReady(): bool;

	/**
	 * @param string $name
	 * @param string|null $wikiId
	 * @return array
	 */
	public function getProcessAdditionalArgs( string $name, ?string $wikiId = null ): array;
}
