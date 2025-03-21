<?php

use MediaWiki\MediaWikiServices;

return [

	'MWStake.WikiCronManager' => static function ( MediaWikiServices $services ) {
		return new MWStake\MediaWiki\Component\WikiCron\WikiCronManager(
			$services->getConnectionProvider(),
			$services->getObjectCacheFactory()
		);
	},
];
