<?php

use MWStake\MediaWiki\ComponentLoader\Bootstrapper;

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_WIKICRON_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_WIKICRON_VERSION', '2.0.3' );

Bootstrapper::getInstance()
	->register( 'wikicron', static function () {
		$GLOBALS['wgServiceWiringFiles'][] = __DIR__ . '/includes/ServiceWiring.php';

		$GLOBALS['wgExtensionFunctions'][] = static function () {
			$hookContainer = \MediaWiki\MediaWikiServices::getInstance()->getHookContainer();
			$hookContainer->register( 'LoadExtensionSchemaUpdates', static function ( DatabaseUpdater $updater ) {
				$dbType = $updater->getDB()->getType();

				$updater->addExtensionTable(
					'wiki_cron',
					__DIR__ . '/db/' . $dbType . '/wiki-cron.sql'
				);
				$updater->addExtensionTable(
					'wiki_cron_history',
					__DIR__ . '/db/' . $dbType . '/wiki-cron.sql'
				);
				$updater->addExtensionField(
					'wiki_cron',
					'wc_wiki_id',
					__DIR__ . '/db/' . $dbType . '/patch_cron_wiki_id.sql'
				);
				$updater->addExtensionField(
					'wiki_cron_history',
					'wch_wiki_id',
					__DIR__ . '/db/' . $dbType . '/patch_cron_history_wiki_id.sql'
				);
				$updater->addExtensionIndex(
					'wiki_cron',
					'_fake_index_',
					__DIR__ . '/db/' . $dbType . '/patch_cron_pk.sql'
				);
			} );
		};
		$GLOBALS['mwsgProcessManagerPlugins']['wikicron'] = [
			'class' => 'MWStake\MediaWiki\Component\WikiCron\WikiCronPlugin',
			'services' => [ 'MWStake.WikiCronManager' ]
		];

		$GLOBALS['mwsgWikiCronStore'] = [
			'class' => \MWStake\MediaWiki\Component\WikiCron\LocalDatabaseStore::class,
			'services' => [ "DBLoadBalancer" ]
		];
	} );
