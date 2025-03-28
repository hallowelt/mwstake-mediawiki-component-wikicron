<?php

use MediaWiki\MediaWikiServices;

return [
	'MWStake.WikiCronRegistry' => static function ( MediaWikiServices $services ) {
		$config = $GLOBALS['mwsgWikiCronHandlerRegistry'];
		$legacyConfig = $GLOBALS['mwsgRunJobsTriggerHandlerRegistry'];
		$specs = array_merge( $legacyConfig, $config );
		return new \MWStake\MediaWiki\Component\WikiCron\Registry(
			$specs, $services->getHookContainer(), $services->getObjectFactory()
		);
	},
	'MWStake.WikiCronManager' => static function ( MediaWikiServices $services ) {
		return new MWStake\MediaWiki\Component\WikiCron\WikiCronManager(
			$services->getConnectionProvider(),
			$services->getObjectCacheFactory()
		);
	},
];
