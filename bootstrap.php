<?php

use MWStake\MediaWiki\ComponentLoader\Bootstrapper;

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_WIKICRON_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_WIKICRON_VERSION', '2.0.0' );

Bootstrapper::getInstance()
	->register( 'wikicron', static function () {
		$GLOBALS['wgServiceWiringFiles'][] = __DIR__ . '/includes/ServiceWiring.php';

		$GLOBALS['wgExtensionFunctions'][] = static function () {
			$hookContainer = \MediaWiki\MediaWikiServices::getInstance()->getHookContainer();
			$hookContainer->register( 'LoadExtensionSchemaUpdates', static function ( DatabaseUpdater $updater ) {
				$dbType = $updater->getDB()->getType();

				$updater->addExtensionTable(
					'scheduled_tasks',
					__DIR__ . '/db/' . $dbType . '/scheduled_tasks.sql'
				);
				$updater->addExtensionTable(
					'scheduled_tasks_history',
					__DIR__ . '/db/' . $dbType . '/scheduled_tasks.sql'
				);
			} );
		};
		$GLOBALS['mwsgProcessManagerPlugins']['wikicron'] = [
			'class' => 'MWStake\MediaWiki\Component\WikiCron\WikiCronPlugin',
			'services' => [ 'MWStake.WikiCronManager' ]
		];
		$GLOBALS['mwsgWikiCronOptions'] = [
			"*" => [
				"basetime" => [ 1, 0, 0 ],
				"once-a-week-day" => "sunday"
			]
		];

		$GLOBALS['mwsgRunJobsTriggerOptions'] = [
			"*" => [
				"basetime" => [ 1, 0, 0 ],
				"once-a-week-day" => "sunday"
			]
		];

		$GLOBALS['mwsgWikiCronHandlerRegistry'] = [];
	} );
