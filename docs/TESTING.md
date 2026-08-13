# Testing

Dataphyre supports two explicit CLI test shapes:

- JSON unit-test manifests for dpanel/module diagnostics.
- Code-defined PHP tests for framework and application behavior.

Neither shape is loaded during normal web requests. Tests are discovered and run
by project tooling or CI.

The canonical TestKit and isolated PHP worker live under
`runtime/modules/testing/tooling/`. The module intentionally exposes no runtime
entrypoint, and module-specific tests stay with their owners rather than in a
central test directory. See the [TestKit guide](../runtime/modules/testing/documentation/Dataphyre_Testing.md)
for suites, framework setup, managed state, non-public seams, and semantic
assertions.

`tooling/bootstrap.php` is the sole test entrypoint. TestKit types are
one-per-file and lazily loaded from `tooling/TestKit/`; the concrete `Context`
extends a lifecycle base and assembles named capability traits under the
aggregate `Contracts\TestContext` interface. The former monolithic
`TestKit.php` path does not exist and is not retained as a compatibility layer.
The runner likewise resolves only the module-owned `tooling/code_worker.php`
and module `unit_tests/` roots; retired root-level worker and test locations are
not probed or forwarded.

## Code-Defined Tests

Place PHP tests in a `unit_tests` folder with the `*.test.php` suffix:

```text
runtime/modules/<module>/unit_tests/example.test.php
applications/<app>/backend/dataphyre/unit_tests/example.test.php
```

A test file is plain PHP. It imports the Dataphyre test functions, declares
tests, and lets the runner execute each expanded case in a bounded worker.

```php
<?php
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\test;
use function Dataphyre\Test\todo;

dataset('money values', [
    'zero' => [0, '0.00'],
    'cad' => [1299, '12.99'],
]);

test('formats minor units', static function(Context $t, int $minor, string $expected): void {
    $amount=sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    $t->expect($amount)->toBe($expected);
})->with('money values')->tag('money');

test('uses an automatically cleaned workspace', static function(Context $t): void {
    $path=$t->workspace('billing')->file('result.txt');
    file_put_contents($path, 'ok');
    $t->expect(file_get_contents($path))->toBe('ok');
})->tag('filesystem');

todo('documents future billing edge', 'waiting on provider fixture');
```

### Files, cases, workers, and assertions

These are deliberately different counts. A `*.test.php` file is a discoverable
manifest owned by one module. Each `test()` call declares a named behavioral
contract. A dataset expands that contract into independently addressable named
cases, and repeat/property runs may expand it further. Workers are bounded PHP
processes used to execute those cases; assertions are the individual facts the
cases prove. Consequently, a repository can have thousands of visible cases
without—and should not have—thousands of tiny test files.

Use the catalog to see the exact expanded contracts rather than inferring them
from a summary count:

```powershell
php bin/dataphyre-test list --scope=framework --cases
php bin/dataphyre-test list --owner=panel --path=data_surface --cases --json
```

Every entry includes its source file, line, stable case ID, dataset label,
suite, tags, lifecycle/isolation policy, and declared contract metadata.

## Assertions

The `Context` object provides focused assertions:

```php
$t->same($expected, $actual);
$t->equals($expected, $actual);
$t->notSame($expected, $actual);
$t->notEquals($expected, $actual);
$t->isTrue($actual);
$t->isFalse($actual);
$t->isNull($actual);
$t->notNull($actual);
$t->contains($needle, $haystack);
$t->notContains($needle, $haystack);
$t->matches('/pattern/', $actual);
$t->startsWith('prefix', $actual);
$t->endsWith('suffix', $actual);
$t->length(3, $actual);
$t->count(3, $items);
$t->type('string', $actual);
$t->instanceOf(Service::class, $actual);
$t->throws(static fn()=>throw new RuntimeException(), RuntimeException::class);
$t->throwsLike(static fn()=>throw new RuntimeException('token', 409), RuntimeException::class, 'token', 409);
```

For compact expectation-style checks:

```php
$t->expect($payload)
    ->toHaveKey('tenant')
    ->toHavePathValue('items.0.id', 42)
    ->toHaveCount(2);

$t->expect($amount)->toBeGreaterThan(0)->toBeLessThan(10000);
$t->expect('dataphyre-core')->not()->toBe('laravel');
```

Failed assertions return readable messages and structured expected/actual
details to the worker result.

## Common Surfaces

The DSL includes small helpers for common app contracts:

```php
$t->between(1, 5, $value);
$t->approximately(1.0, $actual, 0.01);
$t->isMinorUnits($price_minor);
$t->moneyAmount('12.99', 1299);

$t->hasPath('items.0.id', $payload);
$t->pathEquals('data.status', 'active', $payload);
$t->subset(['tenant' => 'demo_tenant'], $payload);

$t->responseStatus(202, $response);
$t->responseHeader('content-type', 'application/json', $response);
$t->responseJsonPath('data.id', 42, $response);

$t->panelHasField($panel, 'status');
$t->panelHasFilter($panel, 'status');
$t->panelHasAction($panel, 'archive');

$t->schemaHasColumn($schema, 'price_minor');
$t->queryMatches($query, '/from products/i', [42]);
$t->traceContains($trace, 'dialback', ['name' => 'DATAPHYRE_STORAGE_SIGNED_URL']);
$t->eventContains($events, 'reactor.dispatched', ['payload' => ['channel' => 'orders']]);

$t->htmlHasSelector($html, 'button#save.primary');
$t->htmlAttribute($html, '#save', 'data-state', 'ready');

$db=$t->fakeDatabase(['orders' => ['id' => 'integer', 'total_minor' => 'integer']]);
$db->insert('orders', ['id' => 1, 'total_minor' => 1299]);
$t->tableHas($db, 'orders', ['total_minor' => 1299]);
$db->begin()->insert('orders', ['id' => 2])->rollback();

$permissions=$t->fakePermissions()->allow('orders.update', ['id' => 1], ['id' => 7]);
$t->permits($permissions, ['id' => 7], 'orders.update', ['id' => 1]);
```

Snapshot assertions compare stable files under `unit_tests/__snapshots__`:

```php
$t->snapshot('contract payload', ['name' => 'Asset', 'fields' => ['id', 'name']]);
```

Set `DATAPHYRE_UPDATE_SNAPSHOTS=1` only when intentionally refreshing those
expected files. Snapshot failures include a compact unified diff in the worker
result.

### Managed state and implementation seams

Use test-owned state handles instead of editing superglobals, rebuilding nested
arrays between assertions, calling reflection directly, or managing temporary
paths by hand:

```php
$session=$t->globalMap('_SESSION');
$session->putPath(['oauth','state'], 'expected-state');
$t->same('expected-state', $session->getPath(['oauth','state']));

$provider=$t->nonPublic($provider);
$t->same('client-one', $provider->readProperty('clientId'));
$authentication=$provider->capture(
    'clientAuthHeadersAndPayload',
    payload: [],
    configKey: 'token_auth_method',
);
$t->hasKey('Authorization', $authentication->result());
$t->same('client-one', $authentication->argument('payload')['client_id']);

$workspace=$t->workspace('oauth-discovery');
$workspace->file('response.json', '{"authorization_endpoint":"https://example.test/authorize"}');
```

Every handle records the original value or owns its resource and restores it at
case teardown, including exceptional exits. `getPath()` and `putPath()` reject
invalid paths and scalar/map collisions so fixture setup fails where the intent
becomes ambiguous. `NonPublicAccess` also preserves by-reference arguments,
which keeps tests declarative without weakening production visibility.

### Output, JSON artifacts, and subprocesses

Use one named probe instead of interleaving output buffers, JSON transforms,
temporary log files, and process plumbing between assertions:

```php
$captured=$t->captureOutput(static function(): int {
    echo 'indexed';
    return 42;
});
$t->same('indexed', $captured->output());
$t->same(42, $captured->unwrap());

$artifact=$t->workspace('indexer')->file('result.json', '{"count":42}');
$t->same(['count'=>42], $t->readJsonArray($artifact));

$process=$t->phpProcess(
    ['bin/index.php', '--json'],
    stdin: '{"tenant":"demo"}',
    environment: ['APP_ENV'=>'testing'],
    timeout_millis:5000,
);
$t->isTrue($process->succeeded());
$t->same(42, $process->json()['count']);
```

`captureOutput()` restores nested buffers and preserves normal exception
propagation; use `captureExecution()` only when the throwable itself is the
contract. `decodeJson()`, `jsonArray()`, `tryJsonArray()`, `readJson()`, and
`readJsonArray()` use exception-based decoding and make the expected JSON shape
explicit. `tryJsonArray()` returns `null` when a command intentionally
alternates between human-readable and machine output. `process()` accepts a command argument list and never invokes a
shell; `phpProcess()` additionally selects the ordinary PHP CLI when coverage
is running under phpdbg. Both capture stdout and stderr in managed files so
Windows cannot deadlock on supposedly non-blocking pipes, and expose command,
exit, timeout, duration, text, and decoded JSON through `ProcessResult`.
Use `startProcess()` or `startPhpProcess()` when a concurrency contract needs
several children alive together, then call `wait()` on each handle; unfinished
children are terminated automatically during case cleanup.
The repository architecture gate rejects new test-side `ob_start()` and
`proc_open()` plumbing. A typed, line-scoped exemption is permitted only when
the native buffer or process primitive itself is the contract under test.

## Test Fakes

The test kit includes small in-memory fakes for common app boundaries:

```php
$clock=$t->fakeClock('2026-01-01 00:00:00 UTC')->advance(60);
$storage=$t->fakeStorage();
$mailer=$t->fakeMailer();
$http=$t->fakeHttp();
$auth=$t->fakeAuth(['id'=>42]);
$sql=$t->fakeSql()->rejectUnboundWrites();
$db=$t->fakeDatabase();
$queue=$t->fakeQueue($clock);
$dialbacks=$t->fakeDialbacks('framework');
$callbacks=$t->fakeCallbacks('app');
$reactor=$t->fakeReactor();
$permissions=$t->fakePermissions();
```

Fakes also expose focused assertion methods:

```php
$storage->assertStored($t, 'tenant/logo.txt', 'logo');
$mailer->assertSent($t, 'ops@example.test', 'Ready', ['tenant' => 'demo_tenant']);
$http->assertRequested($t, 'POST', 'https://example.test/hook', ['id' => 42]);
$auth->assertAuthenticatedAs($t, 42);
$sql->assertQueried($t, '/update products/i', [1299, 42]);
$sql->assertNoUnboundWrites($t);
$queue->assertPushed($t, 'sync-product', ['id' => 42]);
$dialbacks->assertCalled($t, 'DATAPHYRE_STORAGE_SIGNED_URL', 'framework');
$reactor->assertDispatched($t, 'product.saved', ['id' => 42]);
```

The fakes expose common adapter-shaped methods such as storage `read/write`,
HTTP `get/post/put/delete`, queued mail, rollbackable database tables, delayed
jobs, scoped hook calls, and permission decisions. They are only loaded by test
workers and help application tests cover service code without touching real
databases, mail providers, remote HTTP services, object storage, queues, or
sessions.

For a production adapter that specifically accepts `PDO`, use the scripted PDO
protocol double when the test is about prepared-statement behavior rather than
SQL engine semantics:

```php
$pdo=$t->scriptedPdo('pgsql')
    ->queueRows([['id'=>1]])
    ->queueScalar(1);

$adapter=new ProductPdoAdapter($pdo);
$t->same([['id'=>1]], $adapter->all());
$t->same(1, $adapter->count());
$t->same(PDO::PARAM_INT, $pdo->statements()[0]->bindings()[':p1']['type']);
```

`ScriptedPdo` subclasses the real PDO protocol but needs no installed database
driver. It records prepare/bind/execute/fetch/close calls and can script misses,
false execution results, and exceptions. Use a real engine separately when SQL
syntax, query planning, transactions, or database behavior is the contract.

For live database checks, wrap a test PDO connection:

```php
$database=$t->pdoDatabase($pdo);
$database->transaction(static function($database) use ($t): void {
    $database->assertSchemaHasColumn($t, 'orders', 'total_minor');
    $database->assertTableHas($t, 'orders', ['id' => 1]);
});
```

`transaction()` rolls back by default, which keeps local integration checks from
leaking rows into a shared development database.

## Real Engines

The lightweight HTML helpers above parse strings in PHP. For real browser work,
install the application-owned Node test dependencies and use the browser bridge
from the consuming project's test runner.

```php
$result=$t->browser()->assertHtml($t, $html, [
    'expect_selectors' => ['#save', '[data-state=ready]'],
    'expect_text' => ['Save'],
    'assert_a11y' => true,
    'assert_axe' => true,
    'axe_tags' => ['wcag2a', 'wcag2aa'],
    'screenshot_path' => 'cache/ci/save-page.png',
]);
```

The worker uses Playwright Core against the system Chrome or Edge executable.
It can assert selectors/text, run built-in accessibility checks for common
missing-name issues, run axe-core when `assert_axe` is enabled, and write
screenshot artifacts or visual baselines.
Use `visualSnapshot(..., update: true)` or `DATAPHYRE_UPDATE_VISUAL_SNAPSHOTS=1`
to refresh a baseline intentionally.
Exact hashes are the default for visual checks. Pass `visual_pixel_threshold`,
`visual_max_diff_pixels`, or `visual_max_diff_ratio` to compare PNG pixels with a
tolerance and emit a `.diff.png` artifact.
If Playwright or a browser executable is absent, the test skips with an explicit
reason instead of pretending a browser ran.

For Dataphyre module surfaces that expose safe test APIs, use the module bridge.
The SQL framework bridge loads real query/schema classes:

```php
$sql=$t->dataphyreModules()->sqlFramework();

$compiled=$sql->querySpec()
    ->whereEq('tenant_id', 7)
    ->whereIn('status', ['paid', 'open'])
    ->compile(false);

$schema=$sql->schema('orders', ['id', 'total_minor'], [], 'id', ['total_minor' => 'int']);
$t->same(['total_minor' => 1299], $schema->fields(['total_minor' => '1299']));
```

For true Dataphyre SQL kernel smoke tests, use an isolated SQLite database. This
requires the PHP `SQLite3` extension; tests should skip clearly when the extension
is not available:

```php
if(!extension_loaded('sqlite3')){
    $t->skip('SQLite3 extension is not available.');
}

$sql=$t->dataphyreModules()->sqlKernel();
$sql->createTable('CREATE TABLE proof (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$sql->insert('proof', ['id' => 1, 'name' => 'ready']);
$t->same(1, $sql->count('proof', 'id=?', [1]));
```

Portable live database checks can still wrap an explicit PDO connection with
`pdoDatabase()` when the application owns the connection setup.

Storage can be tested against the real manager memory driver and event surface:

```php
$manager=$t->dataphyreModules()->storage();
$events=$t->dataphyreModules()->storageEvents($manager);
$manager->put('tenant/product.txt', 'ready');
$events->assertRecorded($t, 'storage.write', ['path' => 'tenant/product.txt']);
```

The bridge can also exercise the real Permission matrix and Reactor harness:

```php
$permission=$t->dataphyreModules()->permission([
    'roles' => ['manager' => ['products.view']],
]);

$t->pathEquals('ok', true, $permission::testMatrix([
    'manager' => ['roles' => ['manager']],
], [
    'manager' => ['allow' => ['products.view']],
]));

$reactor=$t->dataphyreModules()->reactor();
$reactor->register([
    'name' => 'counter',
    'state' => ['count' => 1],
    'render' => static fn(array $state): string => 'Count '.(int)$state['count'],
]);

$t->htmlContainsText($reactor->mount('counter')['html'], 'Count 1');
```

Those bridges load real framework managers, harnesses, and in-memory drivers.
They do not monkey-patch modules that lack a safe test seam.

Application request tests can dispatch through the real MVC framework without
starting a web server:

```php
$appRoot=rtrim((string)ROOTPATH['root'], '/\\');
$mvc=$t->dataphyreModules()->mvc()
    ->autoload('DataphyreCloud\\', $appRoot.'/src')
    ->registerFromConfig('dataphyre_cloud', $appRoot.'/backend/dataphyre/config/mvc.php');

$response=$mvc->dispatch('dataphyre_cloud', 'GET', '/cloud/health', [
    'headers' => ['Host' => 'dataphyre.test', 'Accept' => 'application/json'],
]);

$t->same(200, $response->status);
$t->pathEquals('ok', true, $mvc->json($response));
```

Use this for route/config/controller behavior that can run in-process. Full
front-controller or legacy bootstrap smoke tests should use an external server
probe instead.

## Datasets And Properties

Use `Dataset` when a small matrix of explicit cases is clearer than many
separate tests:

```php
test('accepts supported states', static function(Context $t, string $currency, string $state): void {
    $t->contains($currency, ['CAD', 'USD']);
    $t->contains($state, ['draft', 'paid']);
})->with(Dataset::matrix([
    'currency' => ['CAD', 'USD'],
    'state' => ['draft', 'paid'],
]));
```

Use `Generators` and `forAll()` for compact property checks:

```php
$t->forAll(Generators::integers(1, 10, 20, seed: 123), static function(Context $t, int $value): void {
    $t->between(1, 10, $value);
});
```

Use `fuzz()` with `GeneratedCases` when failures should be replayable:

```php
$t->fuzz(Generators::fuzzIntegers(1, 100, 32, seed: 20260706), static function(Context $t, int $value): void {
    $t->between(1, 100, $value);
});
```

When a fuzz case fails, the assertion details include a replay token for
`DATAPHYRE_FUZZ_REPLAY` and a shrunk candidate when the generator can shrink.

## Spies, Mocks, And Performance

Spies record calls to callbacks; mock objects record dynamic method calls:

```php
$spy=$t->spy(static fn(int $value): int => $value * 2);
$spy(4);
$spy->assertCalledWith($t, [4]);

$mock=$t->mock(['totalMinor' => static fn(): int => 1299]);
$mock->totalMinor();
$mock->spy('totalMinor')->assertCalled($t);

$spy=$t->functionPatch('App\\Tests\\clock_now', static fn(): int => 123);
\App\Tests\clock_now();
$spy->assertCalled($t);

$proxy=$t->staticProxy(DateTimeImmutable::class);
$proxy->call('createFromFormat', 'Y-m-d', '2026-07-06');
$proxy->spy('createFromFormat')->assertCalled($t);
```

Function patches only work for namespaced functions that are not already loaded.
Existing built-ins and already-declared functions cannot be replaced by PHP
without external extensions, so tests should patch before loading the code under
test or use explicit adapters.

Performance helpers are for local proof in tests, not production request code:

```php
$result=$t->performanceUnder(static fn() => strtolower('DATAPHYRE'), 50, iterations: 10);
$t->greaterThanOrEqual(10, $result->iterations());
```

## Lifecycle

Use `before_all`, `before_each`, `after_each`, and `after_all` for worker-local
setup. Code-defined cases still execute in isolated workers, so lifecycle hooks
are for preparing one case worker, not sharing mutable state across cases.

## Skips, Todos, And Focus

Tests can be skipped at declaration time or from inside the body:

```php
test('uses optional extension', static function(Context $t): void {
    $t->skip('extension not installed');
});

test('external provider contract', static function(): void {
    // ...
})->skipUnless(getenv('RUN_PROVIDER_TESTS'), 'provider tests disabled');
```

Use `todo()` for deliberately recorded future behavior. Use `->only()` only for
local focus; committed `->only()` markers fail unless the runner is called with
`--allow-only`.

For larger suites, code-defined tests also support explicit grouping and simple
dependencies:

```php
test('schema exists', static function(Context $t): void {
    $t->isTrue(true);
})->group('billing')->order(10);

test('repository uses schema', static function(Context $t): void {
    $t->isTrue(true);
})->group('billing')->dependsOn('schema exists')->order(20);
```

Dependency-bearing code tests run in order so a failed prerequisite can skip the
dependent case instead of producing noisy follow-on failures. Unrelated tests
remain eligible for the normal parallel worker pool.

## Running Tests

Dataphyre ships the test kit, worker contracts, and the standalone
`php bin/dataphyre-test` runner. Embedded consuming projects can wrap the same
runner contract to add application discovery while retaining the framework
filters, CI reports, and machine-readable output.

Useful filters:

- `--scope=framework|apps|all`
- `--app=<name>` for application tests
- `--owner=<module-or-app>` for a single module or app owner
- `--path=<substring>` to prefilter files before code-case discovery
- `--changed[=<base>]` for tests affected by working-tree or base-branch changes
- `--why-selected` to print the owner, path, changed-file, or watch declaration
  responsible for every selected test
- `--kind=code` for `*.test.php` files
- `--kind=json` for existing JSON manifests
- `--tag=<tag>` for code-defined test tags
- `--group=<group>` for code-defined test groups
- `--name=<text|/regex/>` for code-defined test names
- `--case=<index>` for a single expanded case index
- `--id=<stable-id>` for one durable contract identity
- `--parallel=<workers>` for bounded code-test worker concurrency
- `--isolate=auto|case|file` to use adaptive file batching, force one worker per
  case, or require one strict file-lifecycle worker
- `--parallel-json` with `--parallel-json-allow=<path-prefix>` for an explicit diagnostic lane that parallelizes only allow-listed JSON workers
- `--junit=<path>` for a CI-readable JUnit report
- `--profile[=<path>]` for per-case durations, lifecycle reasons, contract
  metadata, and adaptive-isolation decisions
- `--coverage=<path>` for a code-test coverage summary using Xdebug or phpdbg exact line data when available, or included-file coverage otherwise
- `--coverage-min-files=<count>` to fail when included-file coverage is too small
- `--coverage-min-percent=<percent>` to fail when exact line coverage is below the threshold
- `--coverage-require=xdebug|phpdbg|included_files` to require a specific coverage engine
- `--coverage-source=<path,...>` and `--coverage-closed-world` to define and
  enforce the complete first-party source inventory
- `--source-epoch` to require a stable coverage-source inventory during an
  ordinary run; exact-engine, line-threshold, and closed-world coverage enable
  this non-bypassable certification guard automatically
- `--github-annotations` for GitHub Actions error annotations
- `--json` for machine-readable summaries and failures
- `--fail-skipped` or `--fail-todo` for stricter CI lanes
- `--include-dynamic` for generated diagnostic manifests
- `--no-test-cache` to bypass content-addressed case discovery while diagnosing
  environment-dependent declarations

`--isolate=auto` is the normal lane. Dependency-free cases without an explicit
lifecycle declaration are speculatively executed together once. If shared
process state breaks that batch but every case passes in an isolated retry, the
runner accepts the isolated outcomes and records the file's content fingerprint
in `cache/unit-tests/isolation-index.json`. The next run goes directly to case
workers until the test, any TestKit component, worker, or module bootstrap changes. Explicit
`->isolation('case')` and `->isolation('process')` declarations bypass batching;
explicit file isolation is strict and never receives an adaptive fallback.

Code-case discovery is cached as one content-addressed shard per test manifest
under `cache/unit-tests/code-case-index.d/`. A focused `--owner`, `--path`, or
`--changed` run reads only the selected shards; it never decodes or rewrites a
repository-wide cache index.

Source-derived datasets can keep discovery caching without becoming stale. Add
`// @dataphyre-test-discovery-dependency framework-source` to a PHP test whose
declared cases are computed from framework product classes. Its cache key then
includes the sorted content fingerprint of request-time framework PHP and
excludes tests, documentation, and static-analysis fixtures. The marker is read
only from PHP comments, so an example string cannot accidentally activate it.

A module may register a runtime-inert test DSL in
`runtime/modules/<module>/testing/bootstrap.php`; applications use
`<app>/testing/bootstrap.php`. The worker loads that bootstrap after TestKit but
before the owning test file, and its hash participates in discovery and
isolation fingerprints.

When Xdebug is not installed, run the orchestrator itself through phpdbg so its
child workers inherit the same exact-line engine:

```powershell
phpdbg -d memory_limit=512M -qrr bin/dataphyre-test run --scope=framework --kind=code `
  --coverage=cache/ci/framework.coverage.json --coverage-require=phpdbg
```

### Docker-backed local testing

Hosts without a local PHP runtime can use the repository-owned PHP 8.4 image.
The wrapper mounts product source read-only, keeps `cache/` writable for indexes
and reports, and leaves disposable test workspaces under the container's `/tmp`:

```bash
docker build --file docker/testing/Dockerfile --tag dataphyre-test:php8.4 .

DATAPHYRE_TEST_SKIP_BUILD=1 sh bin/dataphyre-test-docker run \
  --owner=mcp --path=closed_world --why-selected
```

Use smart partials while developing. `--owner`, `--path`, and `--changed`
select before case execution, the sharded discovery cache avoids touching
unselected test metadata, and `--why-selected` makes the selection reason inspectable:

```bash
DATAPHYRE_TEST_SKIP_BUILD=1 sh bin/dataphyre-test-docker run \
  --changed --parallel=8 --why-selected
```

When the framework is embedded below a larger Git worktree, the Docker wrapper
mounts that repository read-only only for `--changed`, translates repository
paths back to framework-relative paths, and ignores sibling-project changes.
The same canonical smart-partial command therefore works in standalone and
embedded distributions without exposing the parent repository to ordinary runs.

Reserve exact closed-world coverage for certification. A bounded parallel pool
amortizes phpdbg process startup without changing case isolation or coverage
semantics; `--profile` identifies the remaining expensive contracts:

```bash
DATAPHYRE_TEST_SKIP_BUILD=1 sh bin/dataphyre-test-docker run \
  --owner=mcp --kind=code --parallel=8 \
  --profile=cache/ci/mcp.profile.json \
  --coverage=cache/ci/mcp.coverage.json \
  --coverage-require=phpdbg \
  --coverage-source=runtime/modules/mcp/kernel \
  --coverage-closed-world --coverage-min-percent=100
```

`--no-test-cache` is a diagnostic switch, not the normal development lane.
It deliberately discards one of the runner's primary smart-partial savings.

Exact reports merge child-worker maps with the orchestrator's own map. This
keeps the first-party `Runner.php` in the closed-world product inventory instead
of treating orchestration as invisible. Only `code_worker.php` is explicitly
excluded because it must stop coverage before serializing its own result.
phpdbg maps are reduced immediately to bounded, detached filename/line maps;
the worker, covered-process transport, and orchestrator therefore share one
allocator-safe evidence boundary even when a debugger returns malformed keys.
The orchestrator removes each exact map from its worker result as soon as it is
received. `CoverageAccumulator` serializes that payload into a temporary spool
which spills after 1 MiB, then decodes and unions one payload at a time into an
owned in-place aggregate. A full-framework certification therefore retains
small test outcomes in memory without retaining thousands of debugger maps or
rebuilding the complete aggregate for every worker.

Certification also has a source epoch. Immediately before workers start, the
runner inventories the selected coverage source as sorted framework-relative
paths with SHA-256 content hashes. It repeats that inventory after worker and
orchestrator coverage capture. A changed, added, or removed product file fails
the run as `source-epoch-changed`, even when every test passed. The JSON summary,
profile, and coverage report retain both fingerprints, file counts, activation
reason, and the complete path-level delta; human output names a bounded subset
for quick diagnosis. Unit-test definitions, documentation, static-analysis
fixtures, the code-worker transport, caches, temp workers, and JSON/XML report
outputs are outside the product inventory, so runner artifacts cannot invalidate
their own epoch. Use `--source-epoch` for the same guarantee on a focused run
without coverage.

CI enforces the same contract on Ubuntu PHP 8.4 with Xdebug. Its source boundary
is `runtime/modules`: every module production file must be observed and every
reported executable line must be covered. JUnit, per-case profile, JSON summary,
and exact coverage artifacts are retained even when the gate fails.

Exact certification can set `--coverage-memory-default=1G` as a covered-worker
fallback without changing the ordinary 256M lane. A suite or case with
`coverageMemoryLimit('2G')` remains self-describing and takes precedence over
that fallback; the broad `--memory=limit` option remains the final operator
override when every worker intentionally needs the same ceiling.

On Windows the runner captures child stdout and stderr in managed per-run files.
Native process pipes can remain blocking even after a non-blocking request,
which previously delayed status polling, timeout enforcement, and parallel queue
progress until the child exited. File-backed capture keeps those contracts
portable and also avoids deadlocks when a worker emits a large diagnostic.

For Panel, follow the runner with the module-owned source-completeness gate. It
requires every production Panel PHP file to be present in the exact report, so a
never-loaded file cannot be mistaken for covered code:

```powershell
phpdbg -d memory_limit=1G -qrr bin/dataphyre-test run --scope=framework `
  --owner=panel --kind=code --no-test-cache --parallel=1 `
  --coverage=cache/ci/panel.coverage.json --coverage-require=phpdbg `
  --coverage-source=runtime/modules/panel/Framework,runtime/modules/panel/kernel `
  --coverage-closed-world --coverage-min-percent=100

php -d memory_limit=1G runtime/modules/panel/testing/panel_php_coverage_gate.php `
  --coverage=cache/ci/panel.coverage.json --require-engine=phpdbg `
  --minimum-percent=100
```

Panel's release orchestrator combines that exact coverage check with the asset,
interaction, and responsive/accessibility browser lanes and writes one aggregate
CI report:

```powershell
node runtime/modules/panel/testing/panel_release_gate.js `
  --base-url=http://127.0.0.1:8098/panel `
  --coverage=cache/ci/panel.coverage.json --coverage-engine=phpdbg `
  --require-coverage --artifact-dir=cache/ci/panel-release
```

For a release rather than an exploratory run, the gate can bind every report,
screenshot, and diagnostic byte to the prepared-package source tree, release
contract, optional release manifest, inclusive matrix, runner, and expiry. The
host keeps the HMAC key and supplies the authoritative package-tree digest. The
gate writes the signed bundle outside the strict artifact root and immediately
verifies it before returning success:

```powershell
node runtime/modules/panel/testing/panel_release_gate.js `
  --base-url=http://127.0.0.1:8098/panel `
  --artifact-dir=cache/ci/panel-release `
  --evidence-key-file=C:\run\secrets\panel-quality-hmac `
  --evidence-key-id=quality-v2 `
  --evidence-source-digest=$preparedPackageTreeSha256 `
  --evidence-release-digest=$releaseManifestSha256 `
  --evidence-run-id=$env:GITHUB_RUN_ID `
  --evidence-bundle=cache/ci/panel-release-evidence.json
```

`PanelReleaseEvidenceBundle` requires independent source and contract
expectations during verification, rejects missing, extra, changed, or linked
artifacts in strict mode, and emits a stable replay key for host persistence.
It accepts automated `php`, `browser`, and real lab `adapter` channels, but not
`manual`; native assistive-technology declarations remain separate. See
`runtime/modules/panel/documentation/Dataphyre_Panel_Release_Evidence.md`.

Panel's central release contract is
`runtime/modules/panel/testing/panel_release_contract.json`. It is a bounded,
machine-readable inventory of the asset-capability graph, browser result
counts, inclusive evidence split, prepared-package boundary, and CI ownership.
Contract schema v2 declares the unit, exact-coverage, asset, interaction,
responsive/container, accessibility/inclusive, committed-pixel, Datadoc, and
Panel-to-Datadoc evidence jobs plus the one aggregate job that consumes them.
The validator continues to accept schema v1 for existing package evidence, but
aggregate validation is deliberately v2-only because v1 did not declare every
release gate.
The validator compares those declarations with generated PHP assets rather than
trusting the JSON alone. Every capability that appears in an aggregate cache
token must change CSS or JavaScript bytes; built-in capabilities reject host
asset duplicates, while a probe capability proves that external delivery still
works. Upload keeps its complete form dependency and behavior, but shares the
byte-identical `shell.form` cache token instead of creating a no-op cache key.
The route-free Studio editor is a built-in `studio-editor` capability: host
shells that keep its default `inline_assets=false` mode select the aggregate
`shell.form.studio-editor` bundle, while duplicate host URLs are rejected.

Run the adversarial parser tests and source probe with each release PHP:

```powershell
node runtime/modules/panel/testing/panel_release_contract.js --self-test
node runtime/modules/panel/testing/panel_release_contract.js `
  --mode=source --php=php `
  --report=cache/ci/panel-release-contract/source.json
```

CI executes that source probe on PHP 8.2 and 8.4. The asset, browser, and
committed-pixel jobs feed their real reports back to the same validator. PHP
8.4 also prepares a public export outside the checkout and verifies every
packaged path, byte count, file digest, and release tree digest. Local
`.codex-tmp/` and `.tmp/` tooling trees are excluded before copying and are
forbidden in a prepared package.

The final `panel-release` job runs even when a dependency fails and passes the
exact GitHub job-result map through the same dependency-free validator:

```powershell
node runtime/modules/panel/testing/panel_release_contract.js `
  --mode=aggregate `
  --job-results='{"panel-release-contract":"success","panel-unit":"success","panel-exact-coverage":"success","panel-assets":"success","panel-browser":"success","panel-visual-regression":"success","datadoc-documentation":"success","datadoc-documentation-browser":"success"}'
```

Missing, extra, duplicate, malformed, failed, cancelled, or skipped results all
fail closed. The Datadoc jobs execute both the universal workspace corpus and
Panel's real generated API corpus; the latter is published by `panel_docs.php`
through `PanelDocumentationPortal` and exercised in desktop/mobile Chromium.
Panel's publication tests also execute beneath a restrictive POSIX `umask` and
prove exact `0755` directory/`0644` file modes, non-mutating previews, and
idempotent repair of legacy private modes.

### Inclusive locale, input, and assistive-technology evidence

Panel's versioned inclusive-quality matrix covers locale/script/direction,
timezones, number/date/plural formatting, long text, pseudo-locales, keyboard,
touch/coarse pointer, forced colors, reduced motion, CSS-viewport zoom-reflow
proxies, synthetic IME composition, and accessibility-tree proxies. The default
matrix contains 126 cases: 78 executable browser cases and 48 adapter/manual
declarations. Proxy evidence never claims that NVDA, JAWS, VoiceOver, TalkBack,
Dragon, Voice Control, Voice Access, a physical switch, a native IME, or native
browser zoom actually ran.

Generate, execute, and gate the lane from the repository root:

```powershell
php dev/tools/panel_developer.php inclusive-quality --name=panel_release `
  --url=/panel --output=cache/ci/panel-inclusive/matrix.json

node runtime/modules/panel/testing/panel_inclusive_quality_regression.js `
  --php=php --manifest=cache/ci/panel-inclusive/matrix.json `
  --base-url=http://127.0.0.1:8098 `
  --report=cache/ci/panel-inclusive/report.json `
  --capabilities=cache/ci/panel-inclusive/capabilities.json

node runtime/modules/panel/testing/panel_release_gate.js --lanes=inclusive `
  --php=php --inclusive-manifest=cache/ci/panel-inclusive/matrix.json `
  --inclusive-capabilities=cache/ci/panel-inclusive/capabilities.json `
  --inclusive-evidence=cache/ci/panel-inclusive/report.json `
  --artifact-dir=cache/ci/panel-inclusive/release
```

The PHP validator reconstructs the matrix, authenticates its digest, and returns
the canonical browser case mapping. The Node runner requires exact profile and
contract payload parity, an exact case/URL set, bounded JSON input, a Chromium
sandbox unless `--allow-no-sandbox` is explicit, and a same-origin top-frame
navigation policy before contact. Subresource origins remain the mounted page's
policy. Every passed or failed evidence record is bound to the exact matrix
digest; stale or unbound reports fail closed. Capability status must include a
source and execution channel. `declared` is not treated as `available`.

Adapter/manual results stay in `declared_manual` and have independent budgets.
Use a real lab adapter and artifact for native AT results; a browser proxy cannot
be relabelled as native evidence. The commands and JSON schemas are portable
between Windows and Linux; only the PHP/browser executable paths differ.

The standalone repository can run the release gate's asset lane without a host
application. Generate the bundles first, then pass both local files together:

```powershell
php runtime/modules/panel/testing/panel_asset_snapshot.php `
  --output-dir=cache/ci/panel-assets

node runtime/modules/panel/testing/panel_release_gate.js --lanes=asset `
  --css-file=cache/ci/panel-assets/panel.css `
  --js-file=cache/ci/panel-assets/panel.js `
  --artifact-dir=cache/ci/panel-release-assets
```

The snapshot command defaults to the historical full bundles used by the
architecture gate. Capability delivery has a separate deterministic lane; use
the same declarations a surface reports in `asset_manifest`:

```powershell
php runtime/modules/panel/testing/panel_asset_snapshot.php `
  --output-dir=cache/ci/panel-assets-table `
  --mode=capability --capabilities=shell,table,navigation

node runtime/modules/panel/testing/panel_asset_architecture_audit.js `
  --css-file=cache/ci/panel-assets-table/panel.css `
  --js-file=cache/ci/panel-assets-table/panel.js `
  --max-css-bytes=806000 --max-js-bytes=385000
```

With a live asset endpoint, pass the canonical ordered token instead:

```powershell
node runtime/modules/panel/testing/panel_asset_architecture_audit.js `
  --base-url=http://127.0.0.1:8098/panel `
  --capabilities=shell.navigation.table `
  --max-css-bytes=806000 --max-js-bytes=385000
```

Physical delivery has its own public-manifest and browser-runtime gate. The
snapshot command publishes every first-party file selected by the closed
capability graph when `--asset` is omitted:

```powershell
php runtime/modules/panel/testing/panel_asset_snapshot.php `
  --output-dir=cache/ci/panel-assets-physical-table `
  --mode=physical --capabilities=shell,table,navigation `
  --report=cache/ci/panel-assets-physical-table.json

node runtime/modules/panel/testing/panel_asset_delivery_audit.js `
  --manifest-url=http://127.0.0.1:8098/panel/assets/panel-assets.json `
  --browser --report=cache/ci/panel-asset-delivery.json
```

The delivery audit verifies every same-origin response, immutable caching,
`nosniff`, MIME type, bytes, SHA-256, SRI, dependency order, gzip/Brotli size,
parse time, Chromium bootstrap time, long tasks, and page errors against
checked-in profile ratchets. Use `--report-only` only while intentionally
recalibrating those budgets; the release lane must omit it.

Never update visual baselines as part of an asset-splitting change. Run the
normal interaction and pixel comparison lanes against the physical-delivery
showroom; use `asset_mode=capability` or `asset_mode=full` only as explicit
compatibility comparisons.

The interaction and visual lanes intentionally require `--base-url`. The
repository-owned browser showroom is the canonical framework release fixture:
it builds real Panel resources, forms, tables, boards, relations, imports,
actions, modals, widgets, themes, navigation, and assets from the current source
tree. Its deterministic mutable records live only in the browser session; it has
no database or application dependency.

Start the fixture from the Dataphyre root:

```powershell
php -S 127.0.0.1:8098 `
  runtime/modules/panel/testing/fixtures/panel_browser_showroom.php
```

Then run all 51 interaction contracts and the complete default
responsive/accessibility visual audit from another terminal:

```powershell
node runtime/modules/panel/testing/panel_interaction_regression.js `
  --base-url=http://127.0.0.1:8098/panel `
  --report=cache/ci/panel-browser/interaction.json

node runtime/modules/panel/testing/panel_visual_regression.js `
  --base-url=http://127.0.0.1:8098/panel --audit-only `
  --artifact-dir=cache/ci/panel-browser/default `
  --report=cache/ci/panel-browser/default/report.json
```

CI also runs bounded environment matrices. These commands reproduce the axes
without creating an unbounded Cartesian product:

```powershell
# 320px and desktop, light/dark/system, and both writing directions.
node runtime/modules/panel/testing/panel_visual_regression.js `
  --base-url=http://127.0.0.1:8098/panel --audit-only --theme=glass `
  --scenario=orders_index,orders_create,orders_board,orders_show,feature_showcase `
  --viewport=320,desktop --theme-mode=light,dark,system --direction=ltr,rtl `
  --artifact-dir=cache/ci/panel-browser/theme-direction

# Mobile/laptop reflow at 200%, reduced motion, and real forced-colors emulation.
node runtime/modules/panel/testing/panel_visual_regression.js `
  --base-url=http://127.0.0.1:8098/panel --audit-only --theme=glass `
  --scenario=orders_index,orders_create,orders_show,feature_showcase `
  --viewport=mobile,laptop --zoom=2 --reduced-motion=reduce --forced-colors=active `
  --artifact-dir=cache/ci/panel-browser/reflow-media

# Keep a desktop viewport while constraining the Panel container. This is
# deliberately stricter than matching viewport width to container width.
node runtime/modules/panel/testing/panel_visual_regression.js `
  --base-url=http://127.0.0.1:8098/panel --audit-only --theme=glass `
  --scenario=orders_index,orders_board,orders_create,feature_showcase `
  --viewport=desktop --container-width=320,768,1024 --direction=ltr,rtl `
  --artifact-dir=cache/ci/panel-browser/container `
  --report=cache/ci/panel-browser/container/report.json
```

The isolated-container lane must not be replaced with matching viewport sizes:
its purpose is to expose components that respond only to viewport media queries.
On failure, the JSON report identifies the escaping region, element, target size,
container width, and direction so the responsive contract remains actionable.

The bounded Ubuntu matrices above are audit-only and never create or approve
screenshots. A separate `panel-visual-regression` Windows job runs the complete
52-result default matrix against the committed source-showroom references.
It fails on missing references, dimension changes, structural or accessibility
regressions, console errors, and pixel differences above the approved threshold.
The job also asserts the exact result count so a silently narrowed matrix cannot
pass.

Reference images may only be refreshed by an explicit, reviewable local
`--update-baselines` run on Windows. The CI comparison invokes the visual runner
without `--audit-only` or `--update-baselines`; it cannot create, replace, or
self-approve references. Do not combine baseline generation and comparison in
the same CI run.

ShopiCore's live example remains a separate consuming-application integration
fixture. It is useful for proving application routes, identity, and seed data,
but it is not the authoritative source for the framework browser gate.

For a sibling ShopiCore checkout, the source-tree router pins that external
showroom to the Dataphyre checkout under test. Start it from the Dataphyre root:

```powershell
$env:DP_PANEL_LIVE_EXAMPLE_ENTRY = (Resolve-Path `
  '..\ShopiCore\applications\shopiro\shared\debug\dataphyre-panel-live-example\index.php').Path
$env:DP_PANEL_RUNTIME_ROOT = (Resolve-Path '.').Path
php -S 127.0.0.1:8097 dev/tools/public/panel_live_example_router.php
```

Then run the browser contracts from another terminal:

```powershell
node runtime/modules/panel/testing/panel_interaction_regression.js `
  --base-url=http://127.0.0.1:8097/debug `
  --report=cache/ci/panel-interaction.json
```

The browser runners require `puppeteer-core` below `.tmp/puppeteer-check/`,
`.tmp/`, or the Panel testing directory, plus a system Chrome or Edge executable
(or `--browser`). The external application router is deliberately under
export-ignored `dev/`; the canonical framework showroom stays under Panel's
testing tree and is never loaded by production bootstrap.

The default lane skips `unit_tests/dynamic/`. Those files are useful for deeper
diagnostics, but they are not part of the fast test path.

## Worker Isolation

Each code-defined case is expanded before execution. Dataset rows become
separate cases, and each case runs in its own worker process by default. The
worker defines the same `ROOTPATH`, `RUN_MODE`, `BS_VERSION`, `IS_PRODUCTION`,
session, and server defaults used by the JSON test worker, then loads only the
test kit and the target test file.

`--parallel` is applied to independent code-defined tests. Code tests with
declared dependencies run in order. JSON dpanel manifests run sequentially by
default; `--parallel-json` only parallelizes JSON tests under
`--parallel-json-allow` path prefixes after that specific diagnostic lane is
known to tolerate concurrent workers.

Application tests receive the application rootpath map, including declared
sibling application include roots when the project has them installed.
