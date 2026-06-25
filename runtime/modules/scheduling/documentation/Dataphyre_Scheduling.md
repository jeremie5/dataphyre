### Scheduling Module

The **Scheduling** module in Dataphyre is a request-driven background task trigger with a small Framework layer. It lets a request register work that should run later through Dataphyre's internal scheduler route, while keeping execution frequency, timeout, memory, and lock state under the module's control.

The module is intentionally small, but now has two surfaces:

- build named tasks with `Dataphyre\Scheduling\Scheduling` and `ScheduledTask`
- set periods with readable values like `15 minutes`, `hourly`, `daily`, or `Period::hours(2)`
- register a named task
- persist the task definition under Dataphyre cache
- trigger the internal scheduler route on shutdown when the task is due
- run the task file once the scheduler request reaches `task_runner.php`

---

#### Start Here

Use the scheduling module when:

- a normal request should opportunistically trigger maintenance or background work
- the task can be identified by a stable scheduler name
- the task can run from a plain PHP file plus a small list of dependencies

The module does **not** provide a full queue abstraction. It is a scheduler that works by registering task files and running them through Dataphyre's internal route:

```text
/dataphyre/scheduler/{scheduler}
```

---

#### Public API

##### Framework Facade

Load the Framework surface through `\dataphyre\core::load_framework_modules('scheduling')` or require `modules/scheduling/Framework/Bootstrap.php` directly when working outside the framework loader.

```php
use Dataphyre\Scheduling\Scheduling;

Scheduling::task('catalog.reindex', __DIR__.'/tasks/reindex_catalog.php')
	->every('15 minutes')
	->timeout('10 minutes')
	->memory('256M')
	->dependency(__DIR__.'/bootstrap_catalog.php')
	->run();
```

##### `Scheduling::task(string $name, ?string $filePath=null): ScheduledTask`

Starts a fluent scheduler definition. Call `run()` or `register()` to persist and dispatch when due.

##### `ScheduledTask` period tools

Periods normalize to scheduler-compatible seconds:

```php
Scheduling::task('reports.hourly', __DIR__.'/tasks/reports.php')
	->hourly()
	->timeout('5 minutes')
	->run();

Scheduling::task('feeds.daily', __DIR__.'/tasks/feed.php')
	->setPeriod(\Dataphyre\Scheduling\Period::days(1))
	->setTimeout(\Dataphyre\Scheduling\Period::hours(2))
	->run();
```

Supported period inputs:

- numeric seconds: `300`
- compact values: `30s`, `5m`, `2h`, `1d`, `1w`
- readable values: `30 seconds`, `5 minutes`, `2 hours`, `1 day`, `1 week`
- aliases: `secondly`, `minutely`, `hourly`, `daily`, `weekly`, `monthly`
- `DateInterval`

##### `Scheduling::run(...)`

Registers a task in one call with the same period parsing:

```php
Scheduling::run(
	'catalog.reindex',
	__DIR__.'/tasks/reindex_catalog.php',
	'15 minutes',
	'10 minutes',
	'256M',
	[__DIR__.'/bootstrap_catalog.php'],
);
```

##### Kernel API

##### `run(string $name, string $file_path, float $frequency, float $timeout, string $memory_limit, array $dependencies, ?string $app_override=null): bool`

Registers and dispatches a scheduler task when it is due.

```php
\dataphyre\scheduling::run(
	'daily_backup',
	'/path/to/backup.php',
	86400,
	3600,
	'512M',
	[
		'/path/to/bootstrap_dependency.php',
	],
);
```

Parameters:

- `$name`: Stable scheduler identifier. Allowed characters are letters, digits, `.`, `_`, and `-`.
- `$file_path`: PHP file that should be executed by the scheduler runner.
- `$frequency`: Minimum seconds between task starts.
- `$timeout`: Seconds after which a stale running lock is treated as timed out.
- `$memory_limit`: Memory limit applied inside the scheduler runner.
- `$dependencies`: Files that must be required before the task file runs.
- `$app_override`: Optional app override used when the internal scheduler request is dispatched.

Behavior:

- the task definition is persisted to `cache/scheduling/<name>/properties.json`
- `last_run` is updated when the task is dispatched
- `running_lock` prevents overlapping runs
- stale locks are treated as timed out once `timeout` is exceeded

##### `valid_scheduler_name(string $name): bool`

Checks whether a scheduler name is safe for the cache path and route segment.

##### `read_scheduler(string $name): ?array`

Reads and normalizes a persisted scheduler definition from cache.

##### `scheduler_directory(string $name): string`

Returns the scheduler cache directory for a valid scheduler name.

##### `scheduler_properties_file(string $name): string`

Returns the scheduler `properties.json` path.

##### `running_lock_file(string $name): string`

Returns the scheduler lock-file path.

##### `last_run_file(string $name): string`

Returns the scheduler `last_run` path.

---

#### Example Usage

##### Opportunistic maintenance from a request

```php
register_shutdown_function(function(){
	\dataphyre\scheduling::run(
		'expired_cache_cleanup',
		__FILE__,
		0.5,
		30,
		'128M',
		[
			\dp_module_present('core')[0],
		],
	);
});
```

##### Task runner pattern

Scheduler requests enter legacy bootstrap as `RUN_MODE='headless'`, and the scheduling module also exposes an explicit runner context. Task files can branch on either, but the scheduler context is the safer scheduling-specific signal:

```php
if(\dataphyre\scheduling::in_task_runner()){
	my_module::run_headless((string)(\dataphyre\scheduling::current_scheduler_name() ?? ''));
}
```

---

#### Execution Model

1. A normal request calls `\dataphyre\scheduling::run(...)`.
2. The scheduler definition is written to `cache/scheduling/<name>/properties.json`.
3. If the task is due and not actively locked, the module creates `running_lock` and records `last_run`.
4. On shutdown, Dataphyre dispatches an internal HTTP request to `/dataphyre/scheduler/<name>`.
5. `task_runner.php` loads the persisted definition, applies timeout and memory settings, loads dependencies, and includes the task file.
6. On shutdown, the runner writes `last_run`, clears the lock, and persists tracelog output when available.

---

#### Scheduler State Files

Each scheduler lives under:

```text
common/dataphyre/cache/scheduling/<name>/
```

Files:

- `properties.json`: persisted task definition
- `last_run`: last dispatch timestamp
- `running_lock`: overlap-prevention lock
- `tracelog.html`: optional tracelog output from the runner

---

#### Design Notes

- Scheduler names are validated before they touch the filesystem.
- Task definitions are rewritten when their configuration changes; the cache is not write-once.
- The internal runner validates dependency and task-file paths before requiring them.
- Stale locks are treated as timed out instead of blocking the task forever.
- The module is request-driven. It is meant for low-friction background maintenance, not a full external worker system.
