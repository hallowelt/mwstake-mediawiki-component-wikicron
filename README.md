# Wiki cron
Mechanism for executing scheduled tasks in MediaWiki

This allows extension to run [ProcessManager processes](https://github.com/hallowelt/mwstake-mediawiki-component-processmanager/blob/main/README.md)
 on a schedule, using standard crontab expression.

## Compatibility
- `1.0.x` -> MediaWiki 1.43

## Declare a cron

Use the `MWStake.WikiCronManager` service to declare a cron.
The cron key is a unique identifier for the cron, the cron expression is a standard crontab expression,
and the process is a `ManagedProcess` object (see above linked ProcessManager docu for more info).

```php
$GLOBALS['wgExtensionFunctions'][] = static function () {
    /** @var WikiCronManager $cronManager */
    $cronManager = MediaWikiServices::getInstance()->getService( 'MWStake.WikiCronManager' );
    $cronManager->registerCron( 'notify', '*/5 * * * *', new ManagedProcess( [
        'test' => [ 'class' => TestStep::class ]
    ] ) );
};
```
Note: This does not need to be declared in an `extension function` but needs to be late enough to prevent 
premature service access.

Thats it! This process will be executed as declared in the cron expression. (as long as `processRunner` is running).

# Info script

See what crons are declared and their statuses.

## List crons

```bash
> php maintenance/wikiCron.php
--------------------------------------------------------------------------------------------------------------
Interval            Cron key                                Enabled   Last run                 Last Status
--------------------------------------------------------------------------------------------------------------
*/5 * * * *         notify                                  Yes       2024-11-08 14:20:57      terminated
-------------------------------------------------------------------------------------------------------------- 
``` 

Note: If interval was manually override, it will be marked with `(ovr)` in the list/details.

## Get full info and history on a cron
    
```bash
> php maintenance/wikiCron.php --name=notify

Cron key: notify
Interval: */5 * * * *
Enabled: 1
Last run: 2024-11-08 14:30:06
Last status: terminated
--------------------------------------------------------------------------------------------------------------
Steps:
{
"test": {
"class": "MediaWiki\\Extension\\NotifyMe\\TestStep"
}
}
--------------------------------------------------------------------------------------------------------------
Execution history (max 20):
--------------------------------------------------------------------------------------------------------------
Time                     State               Exit code      Output
--------------------------------------------------------------------------------------------------------------
20241108143006           terminated          0              {"some":"data"}
20241108142501           terminated          0              {"some":"data"}
20241108142057           terminated          0              {"some":"data"}
20241108141652           terminated          0              {"some":"data"}
20241108141551           terminated          0              {"some":"data"}
20241108141450           terminated          0              {"some":"data"}
20241108141348           terminated          0              {"some":"data"}
20241108141247           terminated          0              {"some":"data"}
20241108141146           terminated          0              {"some":"data"}
20241108141045           terminated          0              {"some":"data"}
20241108140944           terminated          0              {"some":"data"}
20241108140842           terminated          0              {"some":"data"}
20241108140741           terminated          0              {"some":"data"}
20241108140640           terminated          0              {"some":"data"}
20241108140538           terminated          0              {"some":"data"}
20241108140437           terminated          0              {"some":"data"}
20241108140335           terminated          0              {"some":"data"}
20241108140234           terminated          0              {"some":"data"}
20241108140133           terminated          0              {"some":"data"}
20241108140031           terminated          0              {"some":"data"}
--------------------------------------------------------------------------------------------------------------
```

## Functions

Disable a cron
    
    > php maintenance/wikiCron.php --name=notify --disable

Enable a cron
    
    > php maintenance/wikiCron.php --name=notify --enable

Set interval

    > php maintenance/wikiCron.php --name=notify --interval="*/10 * * * *"

Reset interval to default

    > php maintenance/wikiCron.php --name=notify --interval="default"

Force run

    > php maintenance/wikiCron.php --name=notify --force-run
