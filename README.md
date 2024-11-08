# Process Manager

This library allows you to create async background processes, that can be accessed later from anywhere,
to check the progress and retrieve output. When you start the process it will be enqueue, and wait for the processRunner to execute it.

# Usage

Process works based on steps provided to it, it will execute steps sequentialy, passing output data from one step
as an input for the next, until the end. Last step will return its output as the output of the whole process.

Steps are defined as `ObjectFactory` specs. Object produced from such specs must be instance of `MWStake\MediaWiki\Component\ProcessManager\IProcessStep`.

## Sample step
```php
class Foo implements IProcessStep {

	/** @var ILoadBalancer */
	private $lb;
	/** @var string */
	private $name;

	public function __construct( ILoadBalancer $lb, $name ) {
		$this->lb = $lb;
		$this->name = $name;
	}

	public function execute( $data = [] ): array  {
	// Add "_bar" to the name passed as the argument in the spec and return it
		$name = $this->name . '_bar';

		// some lenghty code

		return [ 'modifiedName' => $name ];
	}
}
```

## Creating process
```php
// Create process that has a single step, Foo, defined above
// new ManagerProcess( array $steps, int $timeout );
$process = new ManagedProcess( [
	'foo-step' => [
		'class' => Foo::class,
		'args' => [ 'Bar-name' ],
		'services' => [ 'DBLoadBalancer' ]
	]
], 300 );

$processManager = MediaWikiServices::getInstance()->getService( 'ProcessManager' );

// ProcessManager::startProcess() returns unique process ID that is required
// later on to check on the process state
echo $processManager->startProcess( $process );
// 1211a33123aae2baa6ed1d9a1846da9d
```

## Checking process status

Once the process is started using the procedure above, and we obtain the process id, we can check on its status
anytime, from anywhere, even from different process then the one that started the process

```php
$processManager = MediaWikiServices::getInstance()->getService( 'ProcessManager' );
echo $processManager->getProcessInfo( $pid );
// Returns JSON
{
	"pid": "1211a33123aae2baa6ed1d9a1846da9d",
	"started_at": "20220209125814",
	"status": "finished",
	"output": { /*JSON-encoded string of whatever the last step returned as output*/ }
}
```

In case of an error, response will contain status `error`, and show Exception message and callstack.

## Interrupting processes
Sometimes, we want to pause between steps, and re-evaluate data returned.

This can be achieved if step implements `MWStake\MediaWiki\Component\ProcessManager\InterruptingProcessStep` instead of `MWStake\MediaWiki\Component\ProcessManager\IProcessStep`.
In case process comes across an instance of this interface, it will pause the processing and report back data that was returned from the step.

To continue the process, you must call `$processManager->proceed( $pid, $data )`. In this case, `$pid` is the ID of the paused process, 
and `$data` is any modified data to be passed to the next step. This data will be merged with data returned from previous step (the one that paused the process).
This call will return the PID of the process, which should be the same as the one passed (same process continues).

## Executing steps synchronously
This is a spin-off of this component functionality. It allows you to execute steps synchronously, without the need to start a process.

```php
$executor = new \MWStake\MediaWiki\Component\ProcessManager\StepExecutor(
MediaWikiServices::getInstance()->getObjectNameUtils()
);
// Optional, if all necessary data is passed in the spec, omit this
$data = [
    'input' => 'data for the first step'
];

$executor->execute( [
    'foo-step' => [
        'class' => Foo::class,
        'args' => [
            $someArg1,
            $someArg2
        ]
    ],
    'bar-step' => [
        'class' => Bar::class,
        'args' => [
            $someArg1,
            $someArg3,
            $someArg4
        ]
    ]
], $data );
```

## Notes

- This component requires an DB table, so `update.php` will be necessary

## Setup
This mechanism has the following main parts:
- `ProcessManager` - a service that manages processes, and allows to start processes, check on their status
- `processRunner.php` - script that retrieves processes from the queue and executes them. This is a long-running script that should be started as a background process
- `processExecution.php` - script that actually runs individual processes. This is a short-lived script and is alive only for the durarion of single process execution

### Setting up processRunner.php
Script `processRunner.php` should be started by a crontab. There are two modes of operation:
- executing processes that are currently in the queue
- always running and waiting for new processes to be added to the queue
This is the same operation as `runJobs.php` in MediaWiki core.

Parameters:
- first param should be the full path to the `Mainetenance.php` file in MediaWiki core. This is due to this being
a component, which does not have a dedicated place in the codebase structure, and can be installed anywhere.
- `--wait` - wait for new processes to be added to the queue. In this mode, script will create a lock file, that will
prevent other runners to be started. This is useful when you want to have only one runner running at a time. In case it crashes
or is otherwise killed, the lock file will be removed and other runners will be able to start.
- `--max-jobs` - maximum number of processes to execute in one run. This is useful when you want to limit the number of processes.
- `--script-args` - arguments to be passed to `processExecution.php` script. Avoid using if not sure what you are doing.

Crontab example:
Should be executed as either the webserver user or root.
```
* * * * * /usr/bin/php /var/www/html/mw/vendor/mwstake/mediawiki-component-processmanager/maintenance/processRunner.php /var/www/html/mw/maintenance/Maintenance.php --wait'
```

** When `--wait` is specified, `--max-processes` has no effect**


### Logging
Normally, runner logs into `ProcessRunner` channel of debug log mechanism, but it might also be useful to capture
output of the script directly (in crontab line) and pipe that into some log, so we can catch any errors in the runner itself.

## Consirederations that were taken in implementation
This is not an idea way to set up background processing, but we have taken following considerations into account:
- we want to have a parent for all processes, so they dont end up as zombies, and we can capture any output of them
- we do NOT want to need to setup any separate services on machines, like Redis, RabbitMQ, etc. Ideally, no additional setup would be required, but crontab line is necessary.
- we do NOT want to wait for crontab to execute the process, we want to be able to start it immediately, therefore we have the `--wait` parameter
- it has to work on both Linux and Windows
