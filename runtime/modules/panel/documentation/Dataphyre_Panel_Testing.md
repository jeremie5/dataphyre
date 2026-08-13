# Dataphyre Panel testing

Panel tests have two module-owned surfaces:

- `testing/tooling/PanelTestKit.php` expresses server-side Panel behavior as typed TestKit journeys.
- `testing/panel_browser_scenarios.js` gives every browser regression a contract, stable ID, tags, and watched source paths.

Both surfaces are testing-only. Normal Panel requests do not load the testing bootstrap or either runner.

## Server-side contracts

Load the framework modules and the Panel testing extension at the top of a Panel test file, then declare what the suite protects:

```php
<?php
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\TestIsolation;
use Dataphyre\Test\TestLayer;
use Dataphyre\Test\TestRisk;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel', 'http']);
require_once dirname(__DIR__).'/testing/bootstrap.php';

suite('Panel pretty-route bulk operation lifecycle')
	->contract('panel.bulk.pretty-route-lifecycle', 1)
	->layer(TestLayer::Integration)
	->risk(TestRisk::Critical)
	->watches('module:panel', 'module:http', 'symbol:PanelRouteParser::infer')
	->through('http.request', 'panel.request', 'panel.route-parser', 'panel.manager', 'panel.response')
	->isolation(TestIsolation::File)
	->tag('panel', 'http', 'bulk', 'pretty-route')
	->group('framework-coverage');
```

The metadata is executable selection and reporting data, not a prose label:

- `contract(name, version)` names the behavior independently of a test sentence.
- `layer(...)` says whether the suite is a unit, contract, integration, or other TestKit layer.
- `risk(...)` records the consequence of regression.
- `watches(...)` declares modules, extensions, symbols, or other dependency selectors that should select the suite when they change.
- `through(...)` describes the observable boundary chain in causal order.
- `isolation(...)` states the lifecycle boundary the runner must preserve. Panel request journeys normally use file isolation.
- `tag(...)` and `group(...)` provide human and release-lane selection vocabulary.

Put business behavior in the test name and contract. Put setup plumbing in a named resource fixture or the Panel kit.

## The `$t->panel()` extension

`testing/bootstrap.php` registers a lazy `panel` context extension. Each test context receives one cached `PanelTestKit` instance:

```php
use Dataphyre\Panel\Testing\PanelTestKit;

test('the Panel kit is a typed context extension', static function(Context $t): void {
	$explicit = $t->extension('panel', PanelTestKit::class);
	$fluent = $t->panel();

	$t->same($explicit, $fluent);
});
```

Use `$t->panel()` in scenarios. Use `extension('panel', PanelTestKit::class)` when an explicit runtime type check helps tooling or a framework-extension contract.

The kit can:

- `using($panel)` return a copy backed by a particular `PanelInstance`, `PanelManager`, or `PanelTestHarness`;
- `registerResource($resource)` register a `Resource` or resource definition array with its harness;
- `journey($segments, $method)` start a real mounted route through `HttpRequest`, `PanelRequest`, and the Panel manager;
- preserve ambient query state while proving that requested pretty-route identity wins.

Journeys dispatch lazily. Reading `request()`, calling `dispatch()`, or making a result assertion performs the minimum required work; repeated assertions share the same request and result.

## Ambient and stale route identity

`underAmbientQuery()` describes current-page query state that is present but should not redefine the requested pretty route. `underStaleRouteIdentity()` adds deliberately wrong `resource`, `operation`, `record`, `action`, and `relation` values; supplied view state remains available.

In the example below, `panel_orders_fixture()` is a test-local `Resource` factory with records `100` and `200` eligible for the `approve` transition. Its domain name keeps fixture construction separate from the behavior under test.

```php
test('bulk transition ignores stale identity and preserves the live view', static function(Context $t): void {
	$returnTo = '/panel/orders?view=queue';

	$t->panel()
		->registerResource(panel_orders_fixture())
		->underStaleRouteIdentity(['view' => 'queue'])
		->bulkTransition('orders', 'approve', ['100', '200'])
		->returningTo($returnTo)
		->asModal()
		->expectIdentity([
			'resource' => 'orders',
			'operation' => 'bulk_transition',
			'action' => null,
			'record' => null,
		])
		->expectQuery(['transition' => 'approve', 'view' => 'queue'])
		->expectRedirect($returnTo)
		->expectData([
			'kind' => 'bulk_transition',
			'transitioned' => ['100', '200'],
			'unavailable' => [],
			'failed' => [],
			'denied' => [],
		])
		->expectNotificationCount(1);
});
```

This reads as the contract: a transition requested from stale ambient state still acts on the selected orders, keeps the unrelated view, and returns to the table.

## Meaningful journey methods

The bulk helpers encode the request vocabulary shared by Panel resources:

| Method | Route and transport it describes |
| --- | --- |
| `bulkUpdate($resource, $selected)` | `POST /panel/{resource}/bulk_update` with `selected[]` input |
| `bulkTransition($resource, $transition, $selected)` | `POST /panel/{resource}/bulk_transition` with a `transition` query value |
| `bulkExport($resource, $selected, $format = 'csv')` | `POST /panel/{resource}/bulk_export` with a `format` query value |
| `confirmedBulkAction($resource, $action, $selected)` | `POST /panel/{resource}/action/{action}` with selected records and `__panel_action_confirm=1` |

Compose a journey only where its contract needs more detail:

- `query()`, `input()`, and `headers()` add request values.
- `selected()` supplies normalized string record keys.
- `returningTo()` supplies `return_to`.
- `asModal()` declares the modal partial query and `DataphyrePanelModal` request header.
- `asFragment()` declares the fragment partial query and `DataphyrePanelFragment` request header.

Use structured expectations instead of extracting and transforming raw arrays between assertions:

| Expectation | Observable contract |
| --- | --- |
| `expectIdentity([...])` | Parsed resource, operation, record, action, and relation accessors |
| `expectQuery([...])` | Individual parsed query values |
| `expectStatus($status)` | HTTP result status |
| `expectRedirect($url, $status = 303)` | Redirect status and destination |
| `expectHeader($name, $value)` | Exact response header, case-insensitive by name |
| `expectHeaderContains($name, $fragment)` | Required response-header fragment |
| `expectData([...])` | Result data paths and values; dotted paths are supported by TestKit |
| `expectContentContains(...$fragments)` | Required response content |
| `expectContentMissing(...$fragments)` | Forbidden response content |
| `expectNotificationCount($count)` | Result notification count |

Prefer the most specific helper. For example, native CSV export should assert its attachment header and selected-record content, while a confirmed custom action should assert its action identity, redirect, and action-state data. Keeping them as separate journeys prevents an Ajax modal response from accidentally satisfying a native-navigation contract.

## Browser scenario contracts

`panel_browser_scenarios.js` is the single catalog for `panel_interaction_regression.js`. Every entry owns:

- a unique, descriptive scenario name that exactly matches one executable `probe(...)` body;
- a stable contract such as `panel.bulk.transport-contracts`;
- an ID derived from that contract, such as `panel.browser.panel.bulk.transport.contracts`;
- tags, automatically including `panel` and `browser`;
- one or more watched source globs.

When adding a browser regression, add its catalog entry and a probe with the exact same name. The asset architecture audit rejects missing or orphaned probe ownership, duplicate/unstable identity, empty watch ownership, broken HTTP impact selection, and broken conservative fallback.

List the catalog without finding or launching a browser:

```powershell
node runtime/modules/panel/testing/panel_interaction_regression.js --list
```

Run all registered scenarios:

```powershell
node runtime/modules/panel/testing/panel_interaction_regression.js `
  --base-url http://127.0.0.1:8088/debug `
  --report .tmp/panel-interaction/report.json
```

### Smart browser partials

Selection happens before browser launch:

| Option | Selection rule |
| --- | --- |
| `--scenario <text>` | Case-insensitive substring of scenario name or contract; `/pattern/flags` is also accepted; repeatable |
| `--tag <tag>` | Required tag; repeated tags use AND semantics |
| `--changed-path <path>` | Selects scenarios whose watched globs contain the changed path; repeatable |
| `--why-selected` | Prints each selected ID, name, and causal selection reasons before execution |

Different selector dimensions combine: a selected scenario must satisfy the supplied name/contract selectors, all supplied tags, and the changed-path dimension. If any changed path has no catalog owner, the changed-path dimension falls back conservatively instead of silently skipping coverage. Explicit scenario and tag restrictions still apply.

Examples:

```powershell
# One named contract.
node runtime/modules/panel/testing/panel_interaction_regression.js `
  --base-url http://127.0.0.1:8088/debug `
  --scenario panel.bulk.transport-contracts

# All scenarios tagged as both modal and failure.
node runtime/modules/panel/testing/panel_interaction_regression.js `
  --base-url http://127.0.0.1:8088/debug `
  --tag modal --tag failure

# Source-aware partial with an auditable explanation.
node runtime/modules/panel/testing/panel_interaction_regression.js `
  --base-url http://127.0.0.1:8088/debug `
  --changed-path runtime/modules/panel/Framework/Http/PanelRequest.php `
  --why-selected
```

With no selectors, the runner selects the full catalog. A selector matching nothing fails rather than reporting a false green run.

## Causal completion and failure evidence

Browser completion should be tied to the observable cause: a request, its response, a runtime event, and the resulting DOM or data state. For example, the selected-order transition regression gates its redirected fragment request, proves the modal remains busy and its submit control disabled until that request settles, then waits for the updated row and closed modal. Short delays may stabilize rendering; they are not the success condition.

Each selected scenario runs in a fresh browser context. Unexpected browser console errors fail its probe. The JSON report records contract metadata, selection reasons, duration, and result.

On failure, the runner writes evidence beside the report:

```text
interaction-artifacts/<scenario-id>/failure.png
interaction-artifacts/<scenario-id>/failure.html
interaction-artifacts/<scenario-id>/network.json
```

The network trace contains recent response/request-failure metadata, not response bodies or request headers. The failed result also contains a focused `replay` command for its contract. Use that command first; it preserves the base URL, scenario selector, and report path.

## Architecture audit and release gate

The asset audit protects both the generated Panel assets and the browser registry:

```powershell
node runtime/modules/panel/testing/panel_asset_architecture_audit.js `
  --base-url http://127.0.0.1:8088/debug `
  --report .tmp/panel-asset-architecture.json
```

Its complete report embeds the browser catalog and the registry-integrity checks. A catalog drift is therefore a release-facing architecture failure, not an optional browser-runner warning.

The release gate runs the same audit and interaction runner as lanes and forwards smart-partial selectors:

```powershell
node runtime/modules/panel/testing/panel_release_gate.js `
  --lanes asset,interaction `
  --base-url http://127.0.0.1:8088/debug `
  --changed-path runtime/modules/panel/Framework/Http/PanelRequest.php `
  --why-selected `
  --artifact-dir .tmp/panel-release-gate
```

Use `--interaction-scenario <csv>` or `--interaction-tag <csv>` for release-gate-specific browser filtering. `--changed-path` is forwarded to the interaction lane. `--why-selected` retains the selection explanation in the lane output. The aggregate release report records the chosen paths, scenarios, tags, lane reports, exit status, and bounded stdout/stderr tails.

For a release, run the relevant partial while iterating, then run the unfiltered interaction lane before declaring the Panel browser contract complete.
