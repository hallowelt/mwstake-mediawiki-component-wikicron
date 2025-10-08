<?php

use MediaWiki\MediaWikiServices;

return [

	'MWStake.WikiCronManager' => static function ( MediaWikiServices $services ) {
		$store = $services->getObjectFactory()->createObject( $GLOBALS['mwsgWikiCronStore'] );
		if ( !( $store instanceof \MWStake\MediaWiki\Component\WikiCron\ICronStore ) ) {
			throw new RuntimeException( 'Invalid WikiCron store configuration' );
		}
		return new MWStake\MediaWiki\Component\WikiCron\WikiCronManager(
			$store,
			$services->getObjectCacheFactory(),
			\MediaWiki\Logger\LoggerFactory::getInstance( 'MWStake.WikiCron' )
		);
	},
];
