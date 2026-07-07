# Testing

Dataphyre supports two explicit CLI test shapes:

- JSON unit-test manifests for dpanel/module diagnostics.
- Code-defined PHP tests for framework and application behavior.

Neither shape is loaded during normal web requests. Tests are discovered and run
by project tooling or CI.

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
use Dataphyre\Test\Dataset;
use Dataphyre\Test\Generators;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\fixture;
use function Dataphyre\Test\test;
use function Dataphyre\Test\todo;

dataset('money values', [
    'zero' => [0, '0.00'],
    'cad' => [1299, '12.99'],
]);

fixture('temp_file', static function(): string {
    $path=sys_get_temp_dir().'/dataphyre-test-'.bin2hex(random_bytes(4));
    touch($path);
    return $path;
}, static function(string $path): void {
    if(is_file($path)){
        unlink($path);
    }
});

test('formats minor units', static function(Context $t, int $minor, string $expected): void {
    $amount=sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    $t->expect($amount)->toBe($expected);
})->with('money values')->tag('money');

test('uses isolated fixtures', static function(Context $t): void {
    $path=$t->fixture('temp_file');
    file_put_contents($path, 'ok');
    $t->expect(file_get_contents($path))->toBe('ok');
})->uses('temp_file')->tag('filesystem');

todo('documents future billing edge', 'waiting on provider fixture');
```

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
$t->subset(['tenant' => 'shopiro'], $payload);

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
$mailer->assertSent($t, 'ops@example.test', 'Ready', ['tenant' => 'shopiro']);
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
install the Node dev dependencies and use the browser bridge:

```bash
npm ci
php tools/unit_tests.php run --kind=code --tag=browser
```

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
dependent case instead of producing noisy follow-on failures.

## Running Tests

In a ShopiCore project checkout:

```bash
php tools/unit_tests.php list
php tools/unit_tests.php run --scope=framework
php tools/unit_tests.php run --scope=apps --app=shopirocs
php tools/unit_tests.php run --kind=code
php tools/unit_tests.php run --kind=code --tag=money
php tools/unit_tests.php run --kind=code --group=billing
php tools/unit_tests.php run --kind=code --name="/minor units/"
php tools/unit_tests.php ci --parallel=4 --junit=cache/ci/unit-tests.junit.xml
php tools/unit_tests.php ci --parallel=4 --coverage=cache/ci/unit-tests.coverage.json --coverage-min-files=2
php tools/unit_tests.php run --kind=code --json
```

Useful filters:

- `--scope=framework|apps|all`
- `--app=<name>` for application tests
- `--owner=<module-or-app>` for a single module or app owner
- `--kind=code` for `*.test.php` files
- `--kind=json` for existing JSON manifests
- `--tag=<tag>` for code-defined test tags
- `--group=<group>` for code-defined test groups
- `--name=<text|/regex/>` for code-defined test names
- `--case=<index>` for a single expanded case index
- `--parallel=<workers>` for bounded code-test worker concurrency
- `--parallel-json` with `--parallel-json-allow=<path-prefix>` for an explicit diagnostic lane that parallelizes only allow-listed JSON workers
- `--junit=<path>` for a CI-readable JUnit report
- `--coverage=<path>` for a code-test coverage summary using Xdebug line data when available, or included-file coverage otherwise
- `--coverage-min-files=<count>` to fail when included-file coverage is too small
- `--coverage-min-percent=<percent>` to fail when Xdebug line coverage is below the threshold
- `--coverage-require=xdebug|included_files` to require a specific coverage engine
- `--github-annotations` for GitHub Actions error annotations
- `--json` for machine-readable summaries and failures
- `--fail-skipped` or `--fail-todo` for stricter CI lanes
- `--include-dynamic` for generated diagnostic manifests

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
