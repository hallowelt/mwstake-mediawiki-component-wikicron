<?php

use MWStake\MediaWiki\ComponentLoader\Bootstrapper;

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_WIKICRON_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_WIKICRON_VERSION', '1.0.0' );

Bootstrapper::getInstance()
	->register( 'wikicron', static function () {
		$GLOBALS['wgServiceWiringFiles'][] = __DIR__ . '/includes/ServiceWiring.php';

		$GLOBALS['wgHooks']['LoadExtensionSchemaUpdates'][] = static function ( DatabaseUpdater $updater ) {
			$dbType = $updater->getDB()->getType();

			$updater->addExtensionTable(
				'wiki_cron',
				__DIR__ . '/db/' . $dbType . '/wiki-cron.sql'
			);
			$updater->addExtensionTable(
				'wiki_cron_history',
				__DIR__ . '/db/' . $dbType . '/wiki-cron.sql'
			);
		};
		$GLOBALS['mwsgProcessManagerPlugins']['wikicron'] = [
			'class' => 'MWStake\MediaWiki\Component\WikiCron\WikiCronPlugin'
		];
	} );
