### Scheduling Module

The **Scheduling** module in Dataphyre is a request-driven background task trigger with a small Framework layer. It lets a request register work that should run later through Dataphyre's internal scheduler route, while keeping execution frequency, timeout, memory, and lock state under the module's control.

The module is intentionally small, but now has two surfaces:

- build named tasks with `Dataphyre\Scheduling\Scheduling` and `ScheduledTask`
- set periods with readable values like `15 minutes`, `hourly`, `daily`, or `Period::hours(2)`
- register a named task
- persist the task definition under Dataphyre cache
- trigger the internal scheduler route on shutdown when the task is due
- run the task file once the scheduler request reaches `task_runner.php`
- execute managed application work only in a fresh fixed framework CGI child, never
  in the long-lived scheduler server process
- wait within the smaller of the validated task timeout and the remaining
  aggregate runtime-tick budget
- load the policy-enabled Core and Scheduling lifecycles at Dataphyre's internal runtime boundary
- authenticate each callback with internal-traffic provenance, a one-time claim, and a short-lived purpose-bound signature
- let the root gateway publish the fixed success receipt only after the callback
  child exits successfully; application output is never a receipt

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

Applications register schedules unconditionally during their ordinary bootstrap.
In Dataphyre's fixed managed runtime, the framework derives ownership from the
fixed pool role: web and realtime processes validate definitions without writing
source-local scheduler state, while the signed scheduler process alone records
the canonical definition inventory and may dispatch due tasks.
Applications must not branch on Dataphyre's internal pool or bootstrap variables.
Outside the managed runtime, the legacy request-driven behavior remains available.

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

Behavior outside managed web and realtime request pools:

- the task definition is persisted to `cache/scheduling/<name>/properties.json`
- a successful task publishes the `last_run` timestamp and exact
  `last_success` claim, which are accepted only as one complete state pair
- `running_lock` prevents overlapping runs
- an expired lock is reclaimed only after a non-blocking exclusive lock proves
  the same regular, single-link inode and exact claim
- managed callbacks use a per-task signed wall-clock budget inside one
  295-second aggregate application-work ceiling, whose monotonic deadline is
  fixed when the signed tick is accepted, before application bootstrap

Managed web and realtime bootstrap still normalizes and validates every
definition, but returns without creating `cache/scheduling` or any definition
file. Release preflight retains its private attested state root, and the signed
scheduler supervisor retains its path-independent in-memory inventory. An
explicit ordinary self-hosted `record_only` mode continues to persist definitions
without creating dispatch claims.

Release preflight and the managed runtime use one canonical, path-independent
definition evidence producer. It hashes task contents and dependency contents
in declared bootstrap order, records frequency and timeout as integer
milliseconds, and excludes filesystem paths and the legacy application
override from the evidence contract.

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

Managed scheduler requests never enter application MVC routing. PID 1 sends one
canonical signed request to the private scheduler gateway, the gateway creates
one fresh UID/GID `10001` PHP CGI only after the gateway has consumed PID 1's
one-time claim. The CGI must consume its bound managed context before application
bootstrap. The CGI
uses the fixed `scheduler-task` run mode and does not start visitor sessions,
request-only modules, or load shedding.

The root gateway retains only `CAP_KILL`, `CAP_SETUID`, and `CAP_SETGID`. Each
fresh capability-free CGI owns a separate session/process group; the gateway
terminates and verifies that whole group on success, failure, timeout, and
handler interruption. Scheduler headers and decoded bodies are independently
limited to 4096 bytes and transfer encoding is rejected before body allocation.
Callback/no-op output is limited to 64 KiB; registration output is limited to
the 512 KiB signed transport plus 64 KiB. These are framework constants, not
application settings.

```php
if(\dataphyre\scheduling::in_task_runner()){
	my_module::run_headless((string)(\dataphyre\scheduling::current_scheduler_name() ?? ''));
}
```

---

#### Execution Model

1. PID 1 obtains complete definitions through a signed, one-time registration
   POST to `/dataphyre/runtime/scheduler/register`.
2. When one definition is due, PID 1 creates a generation- and release-bound
   durable claim and sends its name, definition digest, and wall-clock budget in
   a canonical Ed25519-signed POST to `/dataphyre/runtime/scheduler/callback`.
3. The root gateway accepts only root callers through
   `/run/dataphyre/scheduler/gateway.sock`, verifies the exact canonical request
   and public key, and asks PID 1 through
   `/run/dataphyre/control/runtime.sock` to consume that request once. Replay or
   any mismatch returns `404` before application PHP exists. There is no private
   scheduler or control TCP listener; `SERVER_PORT=8081` is only the fixed CGI
   request projection supplied to a claimed callback process.
4. After the claim succeeds, the gateway creates one fresh capability-free
   scheduler CGI. The signed budget also bounds that process from the gateway,
   outside application PHP.
5. The claimed CGI boots the ordinary application once in record-only mode,
   re-derives the complete registration, and requires the named definition's
   digest to match before loading dependencies and task code. Task output and
   headers are discarded.
6. The CGI communicates success only with its process exit. After it is reaped,
   the root gateway discards every application byte and constructs the fixed
   bounded receipt itself. PID 1 records cadence only for that trusted receipt
   and completed claim; failure releases the claim and cannot starve later
   definitions.

On boot or activation, PID 1 deterministically balances stable task names across
absolute cadence phases (with a one-minute maximum horizon) instead of
cold-booting every definition together. Recurring executions remain on those
phases rather than being re-anchored to completion time, so independently
balanced callbacks cannot converge into later completion waves. Callback fan-out
is derived internally from the VM's Linux CPU affinity and cgroup quota, capped
by the gateway's fixed 32-child ceiling. Neither value is an application setting
or deployment knob. This keeps the same definition and cadence contract on
local, CI, and Cloud runtimes while respecting the compute boundary Cloud
actually assigned.

Cadence assessment allows exactly one fixed scheduler wake interval plus one
second for durable success timestamp precision. Both tolerances are
framework-owned and are neither application settings nor deployment knobs; they
do not change a definition's cadence or callback timeout.

Failed callbacks write one private, bounded line through the existing root
gateway stderr log. Its validated task name, gateway phase, exit/timeout kind,
exit code, gateway exception class, and fixed message are authoritative. An
exact clean router record may add allowlisted `application_reported_phase` and
`application_reported_exception_class` hints; application code owns that
process, so those two fields are explicitly non-authoritative. Throwable
messages, claims, signatures, paths, environment values, SQL, headers, stack
traces, and raw task stdout/stderr are never retained or returned. This is
internal diagnostic evidence only; it adds no runtime status, release state,
application setting, or customer-visible deployment concept.

There is no second scheduler worker, runtime environment file, reusable
dispatch secret, shell, or application-selectable process. Outside the managed
Cloud pool, the request-driven scheduling API remains available for compatible
self-hosted applications.

---

#### Scheduler State Files

Each scheduler lives under:

```text
dataphyre/cache/scheduling/<name>/
```

Files:

- `properties.json`: persisted task definition
- `last_run`: latest successful completion timestamp
- `last_success`: exact claim completed by the latest successful task
- `running_lock`: overlap-prevention lock
- `tracelog.html`: optional tracelog output from the runner

---

#### Design Notes

- Scheduler names are validated before they touch the filesystem.
- Internal callback signatures are purpose-, scheduler-name-, and one-time-claim-bound, time limited, and transported in request headers rather than the URL.
- Pending dispatch creation is atomic across PHP workers, and the task runner holds the claim lock through execution to reject concurrent replay.
- Managed signed ticks fail closed unless every unique registration is due or suppressed, every due claim completes, each completion receipt matches its claim, and every accepted definition is lock-free at report time.
- Framework-only and legacy applications share the same authenticated runtime route, so application routing cannot accidentally shadow scheduler execution.
- Task definitions are rewritten when their configuration changes; the cache is not write-once.
- The internal runner validates dependency and task-file paths before requiring them.
- Stale locks are reclaimed only while the framework holds the exact expired
  claim inode exclusively; a live, changed, linked, malformed, or contended lock
  defers dispatch.
- The module is request-driven. It is meant for low-friction background maintenance, not a full external worker system.
