# Dataphyre TestKit

Dataphyre tests should read as executable contracts. A developer familiar with
PHP should be able to understand a test without first learning its bootstrap,
filesystem cleanup, or reflection mechanics.

## A self-describing test file

```php
<?php
declare(strict_types=1);

use Dataphyre\Panel\PanelThemeLibrary;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Panel theme-library contracts')
    ->framework(['panel'])
    ->tag('panel', 'theme')
    ->group('framework-coverage');

test('theme manifests preserve their public identity', function (Context $t): void {
    $theme=PanelThemeLibrary::make()->register(['name'=>'minimal']);

    $t->hasAccessorValues([
        'isValid'=>true,
    ], $theme);
    $t->hasPathValues([
        'presets.minimal.name'=>'minimal',
    ], $theme->manifest());
});
```

The suite declaration states shared intent once. Individual tests retain only
behavior unique to that contract. Suite and case names appear in CLI listings,
worker progress, diagnostics, and Flightdeck's searchable code-test catalog.

## Framework setup

Use `suite()->framework()` when every test in a file shares the same modules:

```php
suite('API production behavior')
    ->framework(['api', 'http', 'routing'], [
        'constants'=>['Dataphyre\\Api\\IS_PRODUCTION'=>true],
        'functions'=>['Dataphyre\\Api\\tracelog'],
        'files'=>['api/kernel/api.main.php'],
    ]);
```

Use the standalone `framework()` function when setup is not a suite default.
Both forms define `DATAPHYRE_MODULE_POLICY`, load the core autoloader, register
the requested modules, and return a `Framework` object with readable `path()`
and `require()` helpers.

## Test location and ownership

TestKit is a framework module at `runtime/modules/testing/tooling/bootstrap.php`;
it is not part of a normal request bootstrap. Keep each module's tests beside
the module they describe:

```text
runtime/modules/<module>/
├── kernel/ or Framework/
└── unit_tests/
    ├── dataphyre.<module>.<contract>.test.php
    ├── dataphyre.<module>.<contract>.json
    └── fixtures/
```

Committed adapters, source samples, and subprocess probes belong in that
module's `unit_tests/fixtures` directory. The runner treats nested fixture JSON
as fixture data, not as an executable manifest. A changed fixture selects its
owning module, so `--changed` remains both safe and selective.

### TestKit source architecture

The bootstrap is the only entrypoint. It registers `TestKitAutoloader`, loads
the small PHP runtime/path inventories, and declares the function DSL that PHP
cannot autoload. Every class, enum, interface, and trait otherwise lives in its
own file beneath `tooling/TestKit/` and loads only when a test uses it. There is
no legacy `TestKit.php` wrapper or duplicate compatibility hierarchy. The
isolated code and browser workers are likewise resolved only from this module's
`tooling/` directory; root-level worker adapters and `testing/unit_tests`
fallback discovery are intentionally absent.

`Context` is intentionally an assembly type rather than another monolith. It
extends `AbstractContext`, which owns lifecycle state, deferred cleanup, and
assertion accounting; named traits own assertions, processes, managed state,
temporary files, doubles, structured data, quality probes, and snapshots.
`Contracts\TestContext` extends the narrower assertion, runtime, process,
double, and extension contracts. TestKit collaborators and module-specific kits
type against the narrowest contract they consume, while test callbacks may keep
the concrete `Context` type for the complete fluent API.

The `dataphyre.testing.testkit_architecture.test.php` suite enforces this shape,
the canonical bootstrap, one path-derived type per autoloaded source, and a
750-line ceiling for every TestKit component. New behavior belongs in the
smallest meaningful capability or collaborator, never back in `Context.php`.

Do not equate files, cases, workers, and assertions. One module-owned test file
can declare several `test()` contracts; datasets, repeats, and property inputs
expand them into separately named cases. The runner schedules those cases in
bounded workers, and each case can make several assertions. `list --cases`
shows every expanded case with its file, line, dataset label, stable ID, suite,
tags, and lifecycle metadata, so a large case count is inspectable rather than
hidden behind the summary:

```powershell
php bin/dataphyre-test list --scope=framework --cases
php bin/dataphyre-test list --owner=panel --cases --json
```

## Repository paths

Use `dataphyre_path()` when a test needs a repository-wide Dataphyre path rather
than a fixture owned by its module:

```php
use function Dataphyre\Test\dataphyre_path;

$bootstrap=dataphyre_path('runtime/modules/core/kernel/bootstrap.php');
```

The helper resolves from `ROOTPATH['common_dataphyre']` when the worker provides
it and falls back to the package root inferred from TestKit's installed module
location. It normalizes separators and dot segments, rejects absolute paths and
null bytes, and prevents `..` traversal from escaping the Dataphyre root.

Prefer `__DIR__.'/fixtures'` for fixtures beside a test and `dirname(__DIR__)`
for the current module. `dataphyre_path()` is for the comparatively rare case
where a contract intentionally crosses module or package boundaries.

## Non-public seams

Non-public behavior is occasionally a legitimate framework contract. State that
intent directly instead of declaring one-off reflection helpers:

```php
$themeInternals=$t->nonPublic($theme);

$resolved=$themeInternals->invoke('resolveReference', 'minimal');
$definitions=$themeInternals->readProperty('definitions');
$themeInternals->writeProperty('definitions', $definitions);
```

Use `replacePropertyForTest()` when the original property must be restored
automatically. Use `invokeWithArguments()` only when the argument array contains
references or is retained from an existing argument-array contract; prefer the
readable variadic `invoke()` otherwise.

When the production method mutates an argument by reference, `capture()` reads
that declaration from the method itself. Named arguments keep the scenario
readable and remove handwritten `ReflectionMethod`/`invokeArgs` plumbing:

```php
$authentication=$t->nonPublic($provider)->capture(
    'clientAuthHeadersAndPayload',
    payload: [],
    configKey: 'token_auth_method',
);

$t->hasKey('Authorization', $authentication->result());
$t->same('client-one', $authentication->argument('payload')['client_id']);
```

## Public API inventories

Use `inventory()` when a contract intentionally walks a class's documented API.
It owns `ReflectionClass`, method selection, public invocation, and constructor
dispatch while still exposing `ReflectionMethod`/`ReflectionParameter` metadata
to domain-specific argument builders:

```php
$api=$t->inventory(Resource::class);

foreach($api->declaredPublicMethods(false) as $method){
    $arguments=array_map(resource_contract_argument(...), $method->getParameters());
    $api->invokeWithArguments($method, Resource::make('orders'), $arguments);
}
```

Use `publicMethods()`, `declaredPublicMethods()`, or `protectedMethods()` to state
the selection rule. `methodShape()` provides visibility, staticness, parameter
count, and return type without handwritten reflection transforms.

## Portable fixture seams

Prefer an ordinary committed PHP fixture when a fake namespace, class, or
function has more than a few lines:

```php
$t->loadStub(__DIR__.'/fixtures/oauth_transport_stub.php');
```

For a small symbol that is genuinely local to one test file, `defineSymbols()`
parses it, writes it to an isolated temporary PHP file, requires it, and removes
the file automatically:

```php
$t->defineSymbols(<<<'PHP'
namespace Example\Transport;
function connect(): string { return 'fixture'; }
PHP);
```

This produces real file-backed PHP stack frames and works in CLI environments
where runtime code evaluation is disabled. Keep business scenarios out of PHP
strings; only declarations that must exist before the production file loads
belong here.

When production accepts a real `PDO` but the contract under test is its
prepare/bind/execute/fetch protocol, use the driver-independent scripted double:

```php
$pdo=$t->scriptedPdo('pgsql')
    ->queueRows([['id'=>1]])
    ->queueScalar(1)
    ->queueExecResult(1);

$adapter=new ProductPdoAdapter($pdo);
$t->same([['id'=>1]], $adapter->all());
$t->same([':p1' => 42], $pdo->statements()[0]->bindingValues());
$t->same([':p1' => PDO::PARAM_INT], $pdo->statements()[0]->bindingTypes());
```

`ScriptedPdo` is deliberately not a SQL interpreter. It records prepare,
bind, execute, fetch, row-count, direct `exec`, and transaction behavior. It
defaults to exception mode and can model driver-inspection, prepare, execute,
begin, and rollback failures on machines without a particular PDO driver. Use
a real database engine when SQL semantics, isolation, locking, or query
planning are the behavior being asserted.

## Scenario state instead of fixture globals

Test-only adapters share state through a named `TestState` channel. The test
creates and owns the channel; a committed fixture function reads the same
channel by name:

```php
$transport=$t->state('oauth.transport', [
    'responses'=>[],
    'calls'=>[],
]);
$transport->append('responses', ['status'=>200, 'body'=>'{}']);

// Inside the fixture adapter:
$state=TestState::channel('oauth.transport');
$state->append('calls', ['url'=>$url]);
return $state->shift('responses');
```

Channels support `get`, `has`, `put`, `merge`, `forget`, `append`, `shift`,
`increment`, `replace`, and `clear`, and restore any previous nested channel
after the case. Use one stable, domain-named channel per adapter instead of a
table of prefixed `$GLOBALS` keys.

Adapters that are also loaded outside their scenario should use
`TestState::channelIfActive('oauth.transport')`. It returns the channel or
`null`, removing repeated `try/catch` probes while making the optional seam
explicit. Use strict `TestState::channel()` when an absent scenario is itself a
test error.

`global()` and `globalMap()` are reserved for behavior whose production contract
really is a PHP global. They expose the same intention-named map operations,
can temporarily `unsetValue()`, and restore both the original value and original
existence automatically. For a real nested global such as session-backed module
state, use `putPath(['module','key'], $value)` and
`getPath(['module','key'], $default)` instead of unpacking and rebuilding raw
PHP arrays. Scoped contracts should use `withGlobal()` or `withoutGlobal()`;
their callback receives the managed value and native state is restored before
the method returns, including when the callback throws:

```php
$session=$t->withGlobal('_SESSION', [], static function(GlobalState $state): array {
    ProductionSessionWriter::persist();
    return $state->map();
});

$created=$t->withoutGlobal('legacy_buffer', static function(GlobalState $state): bool {
    ProductionBuffer::append('event');
    return $state->exists();
});
```

Do not use managed globals to disguise test-only message buses.

Declarative JSON cases run in a deliberately smaller worker rather than loading
all of TestKit. Their committed helper files use
`dataphyre_dpanel_worker_fixture_state` for SQL responses, call history, and the
rare non-public seam, plus `dataphyre_dpanel_worker_application_state` for
explicit server, query, cookie, session, and genuine application-global
contracts. Module helpers should wrap those primitives in scenario names; the
business assertion should never know a fixture-state key.

When a JSON contract needs an application filesystem, declare
`"worker_workspace": "readable-scenario-name"`. The worker replaces only the
case's application root and exposes `dataphyre_dpanel_worker_workspace::active()`
for traversal-safe `path()`, `directory()`, `file()`, and `removeFile()` calls.
The workspace is removed at process shutdown even when the case fails.

## HTTP contracts

`FakeHttp` owns response routing, queues, request decoding, and assertions:

```php
$http=$t->fakeHttp()
    ->respondJson('GET', $discoveryUrl, [
        'authorization_endpoint'=>'https://provider.test/authorize',
    ])
    ->respondFailure('POST', $tokenUrl, 503, 'unavailable');

$provider=new Provider('example', [
    'http'=>['handler'=>$http->handler()],
]);

$http->assertRequested($t, 'GET', $discoveryUrl);
$http->assertFormRequested($t, 'POST', $tokenUrl, ['grant_type'=>'authorization_code']);
```

Use `respondNext()` for retry sequences and `respondUsing()` when the response
depends on a `FakeHttpRequest`. Request objects expose semantic `form()`,
`json()`, and case-insensitive `header()` access. Tests should not manually
encode a response array or maintain response and call globals.

## Scripted collaborators

A `Spy` records calls and can describe sequential collaborator behavior without
manual counters or queues:

```php
$clockRead=$t->spy()->willReturnInOrder(100, 101, 105);
$sender=$t->spy()
    ->thenReturn(['accepted'=>true])
    ->thenThrow(new RuntimeException('offline'));

$sender->assertCalledWithSubset($t, [['recipient'=>'dev@example.test']]);
```

Use `thenCall()` for a response derived from arguments. `lastCall()` and
`call($index)` are available when the arguments themselves are the contract.

## Managed temporary state

Temporary resources clean themselves up after fixtures and teardown hooks:

```php
$directory=$t->tempDirectory('theme-export');
$manifest=$t->tempFile('{}', 'manifest', $directory);
$workspace=$t->workspace('theme-package');
$config=$workspace->file('config/theme.json', '{}');
$workspace->copy(__DIR__.'/fixtures/logo.svg', 'public/logo.svg');

$t->setEnvironmentForTest(['APP_ENV'=>'testing']);
$t->setGlobalsForTest(['request_id'=>'test-request']);
$t->cleanup(fn()=>release_external_resource());
```

Cleanup callbacks run in LIFO order even when the test fails. Avoid handwritten
recursive-delete loops and large `try/finally` blocks for resources TestKit can
own safely. `TempWorkspace::path()` rejects absolute paths, null bytes, and
attempts to escape the workspace root.

## Execution and artifact probes

Output, JSON, and child processes are common test mechanics and should not be
rebuilt inside business scenarios:

```php
$captured=$t->captureOutput(static function(): string {
	echo 'ready';
	return 'receipt-1';
});
$t->same('ready', $captured->output());
$t->same('receipt-1', $captured->unwrap());

$artifact=$t->workspace('receipt')->file('receipt.json', '{"ok":true}');
$t->isTrue($t->readJsonArray($artifact)['ok']);

$result=$t->phpProcess(
	['bin/receipt.php', '--json'],
	stdin:'{"id":1}',
	environment:['APP_ENV'=>'testing'],
	timeout_millis:5000,
);
$t->isTrue($result->succeeded());
$t->same(1, $result->json()['id']);
```

`captureOutput()` restores every nested buffer and rethrows callback failures.
Use `captureExecution()` when a test intentionally needs a `CapturedExecution`
containing output, return value, and throwable. `decodeJson()`/`jsonArray()` decode text;
`tryJsonArray()` models commands that may emit either human text or a JSON object;
`readJson()`/`readJsonArray()` own artifact reads and shape checks.
`process()` is shell-free and `phpProcess()` resolves the ordinary PHP CLI even
inside phpdbg. `ProcessResult` exposes command, exit code, stdout, stderr,
timeout, duration, success, and decoded JSON. Child output uses managed files,
not blocking pipes, so the same timeout contract works on Windows and Linux.
`startProcess()` and `startPhpProcess()` return cleanup-owned running handles
for genuine concurrency tests; start every sibling first, then call `wait()` on
each result instead of rebuilding a `proc_open()` pool.

Suite `beforeEach()` and `afterEach()` callbacks are captured only by cases in
that suite. Top-level `before_each()` and `after_each()` remain file-global.

## Assertions that communicate intent

Prefer semantic assertions over implementation-shaped expressions:

```php
$t->isNull($result);                       // not same(null, $result)
$t->isEmpty($rows);                        // not same([], $rows)
$t->count(3, $records);                    // not same(3, count($records))
$t->hasKeys(['id','status'], $record);     // not containsAll(..., array_keys($record))
$t->sameKeys(['id','status'], $record);    // not same(..., array_keys($record))
$t->instanceOf(Order::class, $order);      // not isTrue($order instanceof Order)
$t->responseStatus(201, $response);        // not same(201, $response->status)
```

Use a strict recursive subset for a cohesive array contract:

```php
$t->subset([
    'name'=>'orders',
    'permissions'=>['view'=>true],
], $manifest);
```

Use path values when the contract is sparse or deeply nested:

```php
$t->hasPathValues([
    'resource.name'=>'orders',
    'pagination.total'=>42,
], $payload);
```

Use accessor values for related zero-argument object contracts:

```php
$t->hasAccessorValues([
    'name'=>'orders',
    'isSearchable'=>true,
    'defaultSort'=>'created_at',
], $resource);
```

Use `containsAll()` for several required fragments and
`hasConsistentSerialization()` for `toArray()`/`JsonSerializable` parity.
Use `producesStableResult(fn()=>...)` when the contract is specifically that a
cache, singleton, or deterministic accessor returns the same strict result on
repeated calls; do not duplicate the expression on both sides of `same()`.

## Fast focused runs

Select files before PHP case discovery starts:

```console
php bin/dataphyre-test run --scope=framework --kind=code --path=runtime/modules/panel/unit_tests/dataphyre.panel_theme --parallel=8
```

Add `--name`, `--tag`, or `--group` for case-level filtering. `--changed`
selects tests affected by working-tree module, application, and test-file
changes; `--changed=main` also includes committed changes since `main`.
Discovery metadata is content-addressed, so unchanged files do not launch list
workers again. Each manifest owns an independent shard under
`cache/unit-tests/code-case-index.d/`, so a focused run never loads or rewrites
unselected discovery metadata. Use `--no-test-cache` only to diagnose a test file
whose declared cases intentionally depend on external environment state.

When a PHP test derives its case or dataset inventory from framework product
source, declare that dependency in a PHP comment:

```php
// @dataphyre-test-discovery-dependency framework-source
```

The discovery-cache key then includes a deterministic content fingerprint of
all request-time framework PHP, while continuing to ignore unit tests,
documentation, and static-analysis fixtures. The marker is recognized only in
PHP comments, never in strings or generated case values. Use it only when source
actually changes the declared case list; ordinary tests retain the cheaper
test-file, complete TestKit source inventory, worker, and module-bootstrap
fingerprint.

Changes to shared helpers, fixtures, or snapshots under a `unit_tests` directory
select the owning module or application instead of being mistaken for one test
manifest. Coverage gates use raw covered/executable counts: a
`--coverage-min-percent=100` run passes only when every executable line is
covered. Xdebug and phpdbg both provide exact executable-line maps; invoke the
test orchestrator through `phpdbg -qrr` when Xdebug is unavailable. Coverage
JSON also exposes `line_coverage_complete` for an unambiguous
machine-readable exactness check.

phpdbg snapshots pass through `PhpdbgLineMap` before a second debugger API is
called. The boundary retains only bounded filename and line-key evidence in
ordinary PHP arrays. This avoids keeping debugger-owned hash keys alive across
snapshots and rejects malformed filename metadata before it can become an
allocation request; workers, covered subprocesses, and the orchestrator all use
the same transport contract.

The orchestrator consumes exact worker maps immediately instead of retaining
them inside every test result. `CoverageAccumulator` serializes the detached
payloads into a temporary spool that spills after 1 MiB, then decodes and unions
one payload at a time into an in-place aggregate. This bounds the heap for broad
coverage runs while preserving every exact line and keeping ordinary reportable
test outcomes compact.

Exact-engine, positive line-threshold, and closed-world coverage runs also
enforce a non-bypassable source epoch. The runner hashes the sorted
framework-relative coverage-source inventory before workers start and again
after worker and orchestrator coverage capture. Changed, added, or removed
product files invalidate the run as `source-epoch-changed`; the JSON summary,
profile, and coverage artifact retain both SHA-256 fingerprints and the full
path delta for diagnosis. Add `--source-epoch` to give an ordinary focused run
the same consistency contract. Test definitions, generated runner state, temp
workers, caches, and report outputs are not product coverage sources and cannot
invalidate the epoch by writing their own artifacts.

On Windows, prefer `--changed`, `--owner`, or `--path` during development and
reserve the all-framework Xdebug/phpdbg run for the final gate. Worker process startup,
real-time filesystem scanning, and coverage-file merging cost more than the PHP
assertions themselves, especially from a synchronized workspace. Runner output
uses managed capture files instead of supposedly non-blocking process pipes;
Windows otherwise blocks while reading a live child and cannot enforce its
timeout or keep the parallel queue moving. The runner's
workers default to a 12-second timeout and 256M memory ceiling; broad coverage
contracts can opt into `--timeout=N` and `--memory=limit` without weakening the
fast lane or relying on the parent process's PHP configuration. When a specific
contract needs extra memory only for PHPDBG/Xdebug line maps, declare both
ceilings instead of permanently loosening its ordinary worker:

```php
suite('Source-derived contracts')
    ->memoryLimit('256M')
    ->coverageMemoryLimit('1G');
```

`coverageMemoryLimit()` is inherited by cases and covered PHP subprocesses only
while coverage is active. The normal ceiling remains authoritative otherwise;
`--coverage-memory-default=1G` can establish a certification-wide fallback
without changing ordinary workers; an inherited `coverageMemoryLimit()` remains
more specific. An explicit CLI `--memory=limit` remains the final operator
override. The runner's
	per-manifest content-addressed discovery shards and module-aware changed selection are the
smart partials; `--why-selected` explains each changed-run decision. The default
`--isolate=auto` lane also speculatively batches dependency-free, unannotated
cases by file. When shared process state alone breaks a batch, isolated retries
become the accepted result and the exact file/TestKit-source/worker/bootstrap
fingerprint is remembered for direct case isolation on later runs. Explicit case/process
metadata bypasses speculation, while explicit file isolation stays strict.
Disabling the cache or repeatedly requesting whole-repository coverage defeats
these savings. Performance contracts receive a small Windows scheduler
grace, while `DATAPHYRE_DPANEL_PERFORMANCE_GRACE_MILLIS` can make timing tests
deterministic.

## Enforced architecture boundaries

`TestArchitectureAudit` in `tooling/TestArchitecture.php` builds one compact,
process-cached index for the complete modules tree. Each relevant source is read
and parsed once; every rule consumes the same extracted facts, and the global
`dataphyre.testing_architecture_guard.test.php` sentinel reports all violations
together instead of stopping after the first rule. The suite fails when new
code introduces:

- runtime `eval` or executable `custom_script`/`file_dynamic` JSON;
- direct `$GLOBALS`, `global`, or request/session superglobal access;
- handwritten `ReflectionClass`, `ReflectionMethod`, `ReflectionProperty`,
  `invokeArgs()`, or `setAccessible()`;
- unmanaged `tempnam()`, `tmpfile()`, or `sys_get_temp_dir()` calls;
- raw `ob_start()` output ownership or `proc_open()` process launch in tests.

Use `TestState`, `GlobalState`, `NonPublicAccess`, `TypeInventory`,
`TempWorkspace`, `captureOutput()`, `process()`/`startProcess()`,
`FakeHttp`, and `Spy` instead. Runtime evaluation, executable JSON,
handwritten reflection, and `global` declarations have no escape hatch.

The few necessary native PHP boundary contracts use a typed, line-scoped
exemption with a useful justification:

```php
return \tempnam($directory, $prefix); // dataphyre-test-architecture: exempt[unmanaged-temporary-file] reason="Namespace failure shim delegates successful calls to the native function."
```

Only temporary-file, system-temporary-directory, raw-global, raw-superglobal,
raw-output-buffer, and raw-process-control rules are exemptable. Unknown rules,
legacy markers, reasons shorter than 20 characters, and a valid exemption
naming the wrong rule all remain violations.

The suite declares `watches('module:*')`, so watch-aware changed runs always
include this repository-wide sentinel. Its distribution-aware
`@dataphyre-changed-run-sentinel framework('*');` source metadata expands to
the modules present in the current package; a self-test proves that the
resolved declaration remains identical to that package's module inventory.

## What not to abstract

- Do not hide a business scenario behind a generic helper name such as `setup()`.
- Do not merge unrelated assertions just to reduce line count.
- Do not use a dataset for several steps of one scenario; datasets create
  separately isolated cases and workers.
- Do not test private implementation details unless the seam is an intentional
  framework compatibility contract.
- Do not keep tautologies such as
  `same($value->jsonSerialize(), $value->jsonSerialize())`.

The goal is semantic compression: fewer lines because infrastructure is owned by
TestKit, while domain behavior becomes more obvious.
