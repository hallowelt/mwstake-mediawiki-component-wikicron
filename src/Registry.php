<?php

namespace MWStake\MediaWiki\Component\WikiCron;

class Registry {

	/**
	 * @param array $specs
	 * @param \MediaWiki\HookContainer\HookContainer $hookContainer
	 * @param \MediaWiki\ObjectFactory\ObjectFactory $objectFactory
	 */
	public function __construct(
		private readonly array $specs,
		private readonly \MediaWiki\HookContainer\HookContainer $hookContainer,
		private readonly \MediaWiki\ObjectFactory\ObjectFactory $objectFactory
	) {}

	/**
	 * @return array
	 */
	public function getAllHandlers(): array {
		$this->assertLoaded();
		return $this->handlers;
	}

	/**
	 * @param string $key
	 * @return IHandler|null
	 */
	public function getHandler( string $key ): ?IHandler {
		$this->assertLoaded();
		return $this->handlers[$key] ?? null;
	}

	/**
	 * @return void
	 */
	private function assertLoaded() {
		if ( $this->handlers === null ) {
			$this->load();
		}
	}

	/**
	 * @return void
	 */
	private function load() {
		$this->handlers = [];
		foreach ( $this->specs as $spec ) {
			$handler = $this->objectFactory->createObject( $spec );
			$this->handlers[$handler->getKey()] = $handler;
		}
		$this->hookContainer->run( 'MWStakeMediaWikiComponentWikiCronRegisterHandlers', [ &$this->handlers ] );
		foreach ( $this->handlers as $key => $handler ) {
			if ( !( $handler instanceof IHandler ) ) {
				throw new \RuntimeException( "Handler $key does not implement IHandler" );
			}
		}
	}
}