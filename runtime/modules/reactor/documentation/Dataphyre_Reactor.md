# Dataphyre Reactor

Dataphyre Reactor is the server-driven component lifecycle module used for
Livewire-style interactions outside any one UI shell.

Reactor is intentionally route-agnostic and data-model-agnostic. Panel views,
application surfaces, templating fragments, or application routes can all consume
the same component runtime.

Current scope: Reactor provides the reusable component lifecycle and transport
foundation. It is not yet a full Livewire replacement; nested component
ergonomics, deep model binding, broad testing helpers, scaffolding, and
ecosystem-level components are still tracked as Panel/Reactor parity gaps.

## Responsibilities

Reactor owns:

- component registration and discovery
- signed component snapshots
- request hydration and response dehydration
- public state and locked state keys
- locked action parameters
- action calls
- computed state
- partial HTML responses
- JSON transport
- lifecycle tracing for Flightdeck and diagnostics

Reactor does not know about Panel resources, ORM records, routes, Laravel,
Livewire, or application-specific models.

## Component

```php
use Dataphyre\Reactor\Reactor;

Reactor::register(
	Reactor::component('seller-health')
		->state(['seller_id'=>42, 'score'=>86])
		->locked('seller_id')
		->computed('label', fn(array $state): string => $state['score'].' / 100')
		->hydrated(fn(array $state): array => $state + ['status'=>'ready'])
		->rules(['score'=>'required|numeric|min:0|max:100'])
		->action('refresh', function(array $state, array $params, $component, $effects): array {
			$effects->toast('Seller health refreshed.', 'success');
			return ['score'=>min(100, $state['score'] + 1)];
		})
		->render('<strong>{{ label }}</strong>')
);
```

## Lifecycle Hooks

Components can observe and mutate state at stable lifecycle points. Hooks receive
`array $state`, `array $context`, the component, and a `ReactorEffects`
collector. Returning an array merges replacement state. Returning
`['state'=>..., 'effects'=>...]` can update both state and browser effects.

Available hook helpers:

- `hydrating(...)`
- `hydrated(...)`
- `actionCalling(...)`
- `actionCalled(...)`
- `rendering(...)`
- `rendered(...)`
- `dehydrating(...)`
- `dehydrated(...)`
- `lifecycle('event_name', ...)` for named custom lifecycle slots

```php
Reactor::component('order-editor')
	->hydrating(function(array $state, array $context): array {
		$state['request_started_at']=microtime(true);
		return $state;
	})
	->actionCalling(function(array $state, array $context, $component, $effects): array {
		$state['last_action']=(string)($context['action'] ?? '');
		$effects->fragment('status', '<strong>Working</strong>');
		return $state;
	})
	->rendered(function(array $state, array $context): array {
		$state['last_html_bytes']=(int)($context['html_length'] ?? 0);
		return $state;
	});
```

Lifecycle hooks run during both server dispatch and initial component mounts
where applicable. This gives Panel and app surfaces one reusable place to attach
form hydration, action instrumentation, validation side effects, and Flightdeck
visibility without baking those concerns into Panel itself.

## Snapshot

Snapshots are signed state manifests. A client sends the snapshot back when it
calls an action. Reactor verifies the signature before it hydrates the component.

```php
$snapshot=Reactor::snapshot('seller-health', ['score'=>91]);
```

Locked state keys are restored from the component definition during hydration.
This prevents the client from replacing identifiers such as tenant ids, user ids,
or record ids.

Locked values provided at mount time are preserved from the signed snapshot on
later requests. This means dynamic values such as record ids and tenant ids do
not need to live in component defaults to stay protected.

Nested paths can be locked:

```php
Reactor::component('order-editor')
	->state(['order'=>['id'=>null], 'title'=>''])
	->locked('order.id');
```

## Authorization

Components can declare a request guard. It receives the hydrated locked state,
the request, the component, and the action name:

```php
Reactor::component('order-editor')
	->authorize(function(array $state, $request, $component, ?string $action): bool|string|array {
		return ($state['can_edit'] ?? false)
			? true
			: ['status'=>403, 'message'=>'You cannot edit this order.'];
	});
```

Returning `true` or `null` allows the request. Returning `false`, a string, or an
array with `status` and `message` denies it. Authorization runs before model
hooks and actions.

## Locked Action Parameters

State locks protect component state. Action parameters protect the values sent
by buttons, forms, row actions, modal actions, and bulk operations.

Use `lockedParams()` when a client-visible action parameter must match trusted
server state or a fixed literal value:

```php
Reactor::component('order-row')
	->state(['order'=>['id'=>42], 'operation'=>'ship'])
	->locked('order.id')
	->lockedParams([
		'id'=>'state:order.id',
		'operation'=>'ship',
	])
	->action('ship', function(array $state, array $params): array {
		// $params['id'] is guaranteed to still be the locked order id.
		return $state;
	});
```

Passing a string locks the parameter against the same state path:

```php
->lockedParams('order.id')
```

If a locked parameter is missing, cannot be resolved, or no longer matches its
trusted value, Reactor returns `419` and skips the action.

Signed parameter envelopes are useful when a row action or modal action needs
trusted values that do not belong in public component state:

```php
$component=Reactor::component('orders-table')
	->requireSignedParams()
	->action('ship', function(array $state, array $params): array {
		// $params['id'] came from the signed server envelope.
		return $state;
	});

echo '<button data-dp-reactor-action="ship" data-dp-reactor-params="'.
	htmlspecialchars($component->signedParamsJson('ship', ['id'=>42]), ENT_QUOTES, 'UTF-8').
	'">Ship</button>';
```

Signed envelopes are verified against the component name, action name, payload,
and Reactor secret. When a signed envelope is present, its payload is merged into
the action params after verification and wins over same-named unsigned params.
`requireSignedParams()` makes the envelope mandatory for every action on that
component.

## Dispatch

```php
$response=Reactor::dispatch([
	'component'=>'seller-health',
	'action'=>'refresh',
	'snapshot'=>$snapshot->jsonSerialize(),
]);

echo $response->html();
```

For route endpoints:

```php
use Dataphyre\Reactor\ReactorEndpoint;

ReactorEndpoint::emit();
```

Applications can also route to the reusable endpoint file:

```php
ROOTPATH['common_dataphyre_runtime'].'modules/reactor/kernel/endpoint.php'
```

The reusable endpoint accepts normal single requests and bundled JSON requests.
Bundled requests are still dispatched one item at a time through the same
component lifecycle:

```php
ReactorEndpoint::emitBatch();
```

`ReactorEndpoint` returns JSON with:

- `status`
- `ok`
- `html`
- `state`
- `effects`
- `message`

Effects are optional response instructions for the browser. Reactor currently
ships:

- `events`: browser `CustomEvent` dispatches
- `toasts`: neutral notification payloads for the host UI
- `redirect`: location changes
- `errors`: validation messages keyed by field path
- `fragments`: targeted fragment updates
- `focus` and `scroll`: target element movement
- `title`: document title updates
- `copy`, `open`, and `download`: browser utility actions
- `replace`: `morph` or `inner`
- `skip_render`: skip the root HTML morph while still returning state/effects
- `snapshot`: the next signed component snapshot

Common browser effects can be emitted from actions:

```php
->action('save', function(array $state, array $params, $component, $effects): array {
	$effects
		->fragment('toolbar-count', '<strong>12</strong>')
		->focus('[name="title"]')
		->scroll('[data-row="newest"]')
		->title('Orders updated');

	return $state;
})
```

Fragments target elements inside the mounted root by default:

```html
<span data-dp-reactor-fragment="toolbar-count"></span>
```

Use `$effects->fragment('name', $html, scope: 'document')` for document-level
targets such as shared modals or shell badges.

Actions that only emit fragments, browser events, notifications, redirects, or
server-owned state changes can avoid a full mounted-root redraw:

```php
->action('acknowledge', function(array $state, array $params, $component, $effects): array {
	$effects->toast('Acknowledged.', 'success')->skipRender();
	return ['acknowledged'=>true];
})
```

## Mounting

Any Dataphyre or application view can mount a Reactor component without using
Panel:

```php
echo Reactor::mount('seller-health', ['score'=>91], [
	'class'=>'seller-health-card',
	'data-dp-reactor-endpoint'=>'/reactor',
]);

echo \Dataphyre\Reactor\ReactorView::script('/reactor');
```

Inside rendered HTML, any element with `data-dp-reactor-action` calls the named
component action and replaces only the mounted component body:

```html
<button data-dp-reactor-action="refresh">Refresh</button>
```

Action parameters can be passed with `data-dp-reactor-params` as JSON.

```html
<button
	data-dp-reactor-action="ship"
	data-dp-reactor-params='{"id":42,"operation":"ship"}'
>
	Ship
</button>
```

Form submits can use the same action attribute. Fields with
`data-dp-reactor-model` are sent as component state and ordinary form fields are
sent as action parameters:

```html
<form data-dp-reactor-action="save">
	<input data-dp-reactor-model="title" name="title">
	<button>Save</button>
</form>
```

Forms with file inputs automatically use multipart transport. Uploaded files are
normalized into `$params['_uploads']` and remain temporary PHP uploads until the
action decides what to do with them:

```html
<form data-dp-reactor-action="import">
	<input type="file" name="catalog">
	<button>Import</button>
</form>
```

```php
->action('import', function(array $state, array $params): array {
	$upload=$params['_uploads']['catalog'] ?? null;
	if($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){
		// Move or inspect $upload['tmp_name'] here.
	}
	return $state;
})
```

Model fields can request reactive updates without a custom Panel script:

```html
<input data-dp-reactor-model="query" data-dp-reactor-live data-dp-reactor-debounce="300">
<select data-dp-reactor-model="status" data-dp-reactor-change></select>
<input data-dp-reactor-model="title" data-dp-reactor-blur data-dp-reactor-model-action="validate-title">
```

Components can also declare their model bindings server-side. The declarations
are emitted on mounted roots as `data-dp-reactor-models` and included in the
Reactor manifest, so tests, debug tools, and host renderers can inspect model
intent without scraping the HTML controls:

```php
Reactor::component('orders-filter')
	->state(['query'=>'', 'status'=>'all'])
	->models([
		'query'=>['mode'=>'live', 'debounce_ms'=>250],
		'status'=>['mode'=>'change'],
	]);
```

When a model update has no action, Reactor simply hydrates state and re-renders
the component. Fast updates cancel older in-flight requests so stale responses
do not overwrite newer state.

## Nested Components

Components can mount child components into named slots. Children keep their own
signed snapshots, lifecycle hooks, actions, model bindings, and effects. The
parent only decides where the child belongs and what initial state it receives.

```php
Reactor::register(
	Reactor::component('order-summary')
		->render('<span>{{ label }}</span><strong>{{ status }}</strong>')
);

Reactor::register(
	Reactor::component('order-shell')
		->state(['order'=>['status'=>'review']])
		->child('summary', 'order-summary', function(array $parent_state): array {
			return [
				'label'=>'Current status',
				'status'=>(string)($parent_state['order']['status'] ?? 'unknown'),
			];
		})
		->render('<section>{{ reactor:summary }}</section>')
);
```

Slots can be written as `{{ reactor:summary }}` or as an element with
`data-dp-reactor-child-slot="summary"`. If no slot exists, Reactor appends the
child output after the parent HTML so a missing placeholder does not hide the
child. Mounted child roots include `data-dp-reactor-parent` and
`data-dp-reactor-slot` for diagnostics and browser tooling.

Roots can opt into same-tick request bundling:

```html
<section data-dp-reactor-component="orders-grid" data-dp-reactor-bundle="16">
	...
</section>
```

Bundling groups JSON requests for the same endpoint into one HTTP round trip.
Each response is then applied to its originating root. Multipart requests with
file inputs bypass bundling automatically.

Mounted roots can poll on an interval:

```html
<section data-dp-reactor-component="orders" data-dp-reactor-poll="5000"></section>
```

`data-dp-reactor-poll-action` can name an action. Polling pauses while the tab is
hidden unless `data-dp-reactor-poll-hidden="1"` is present.

Mounted roots can also lazy-load when they enter the viewport:

```html
<section data-dp-reactor-component="sales-chart" data-dp-reactor-lazy></section>
```

`data-dp-reactor-lazy-action` can name the action to run, and
`data-dp-reactor-lazy-margin` controls the `IntersectionObserver` root margin.

## URL And Persistence

Components can bind model fields to the query string for shareable table and
filter state:

```php
Reactor::component('orders-table')
	->state(['search'=>'', 'status'=>'all'])
	->url([
		'search'=>'q',
		'status'=>['as'=>'status', 'history'=>'replace'],
	]);
```

The client reads matching query values on mount, refreshes the component if a
binding changed field state, and updates the URL after successful responses.
Use `history => 'push'` when a change should create a browser history entry.

State can also be persisted in browser storage:

```php
Reactor::component('orders-table')
	->state(['density'=>'compact', 'columns'=>[]])
	->persist([
		'density'=>['driver'=>'local', 'key'=>'orders.density'],
		'columns'=>['driver'=>'session', 'key'=>'orders.columns'],
	]);
```

Persistence is field-based and host-UI neutral. It is meant for preferences like
density, open sections, selected columns, and draft filter state.

For server-owned sticky state, bind fields to the PHP session:

```php
Reactor::component('orders-table')
	->state(['density'=>'compact', 'filters'=>[]])
	->session([
		'density'=>['key'=>'orders.density'],
		'filters'=>['key'=>'orders.filters'],
	]);
```

Session-backed fields hydrate from `$_SESSION['dataphyre_reactor']` when the
incoming snapshot/request does not already contain that field, then persist back
after dehydration. This is useful for table preferences, wizard progress, and
modal state that should remain server-owned.

## Lifecycle Hooks

Components can react to model changes without inventing action names for every
field:

```php
Reactor::component('search-box')
	->state(['query'=>''])
	->updated('query', function($value, array $state, array $change, $component, $effects): array {
		$effects->dispatch('search:changed', ['query'=>$value]);
		return ['query'=>trim((string)$value)];
	});
```

`updating(...)` runs before `updated(...)`. Use `'*'` or pass only a callback to
watch all model fields. The change payload contains `field`, `old`, `value`, and
`event`.

## Component Events

Components can dispatch browser events through effects and other mounted Reactor
components can listen for them:

```php
Reactor::component('orders-table')
	->listen('order:saved', 'refresh')
	->action('refresh', fn(array $state): array => ['reloads'=>($state['reloads'] ?? 0) + 1]);

Reactor::component('order-modal')
	->action('save', function(array $state, array $params, $component, $effects): array {
		$effects->dispatch('order:saved', ['id'=>$state['id'] ?? null]);
		return $state;
	});
```

Listeners are mounted as document-level browser listeners, so sibling islands can
coordinate without a shared parent component. Listener callbacks receive the
event payload in `$params['event']` and metadata in `$params['_reactor']`.

Events can be broadcast, targeted to a component name, or constrained to the
originating mounted root:

```php
$effects->dispatch('orders:changed', ['id'=>$order_id]);
$effects->dispatchTo('orders-table', 'orders:changed', ['id'=>$order_id]);
$effects->dispatchSelf('modal:closed');
```

Targeted events add routing metadata to the event detail. The client filters
listeners before calling the server, so unrelated mounted roots do not wake up.

## Testing

`ReactorTestHarness` gives components a route-free test surface. It can mount a
component, dispatch an action, and inspect normalized response snapshots without
depending on Panel or a browser:

```php
$harness=Reactor::test();
$harness->register(
	Reactor::component('counter')
		->state(['count'=>0])
		->action('inc', fn(array $state): array => ['count'=>($state['count'] ?? 0) + 1])
		->render('<strong>{{ count }}</strong>')
);

$mounted=$harness->mount('counter');
ReactorTestHarness::assertHtmlContains($mounted, '0');

$response=$harness->dispatch('counter', 'inc', ['count'=>0]);
ReactorTestHarness::assertOk($response);
ReactorTestHarness::assertState($response, 'count', 1);

$snapshot=ReactorTestHarness::responseSnapshot($response);
```

The snapshot includes status, HTML length, state keys, effect keys, and the raw
effect payload. This is intentionally small enough for framework tests and
Flightdeck assertions.

## Loading And Dirty State

The client marks changed fields and roots with `data-dp-reactor-dirty` until the
next successful response. Loading state is also addressable:

```html
<button data-dp-reactor-action="save" data-dp-reactor-disable>Save</button>
<span data-dp-reactor-loading="save" hidden>Saving...</span>
<span data-dp-reactor-loading-remove="save">Ready</span>
```

`data-dp-reactor-target` can be used on loading or disabled elements when the
target action should be listed separately from the display behavior.

Busy state can be scoped with comma-separated targets. The action name is always
included, trigger-level `data-dp-reactor-target` values are added, and root-level
`data-dp-reactor-targets` values can group related UI:

```html
<section data-dp-reactor-component="orders" data-dp-reactor-targets="table">
	<button data-dp-reactor-action="refresh" data-dp-reactor-target="table" data-dp-reactor-disable>
		Refresh
	</button>
	<div data-dp-reactor-loading="table" hidden>Refreshing table...</div>
	<div data-dp-reactor-busy-class="is-refreshing" data-dp-reactor-target="table"></div>
</section>
```

`data-dp-reactor-busy-class` toggles the provided class list while the matching
target is busy.

The client morphs the mounted component body instead of replacing it wholesale.
Use `data-dp-reactor-key` on repeated elements when identity matters across
updates. A mounted root can opt into a hard inner replacement with
`data-dp-reactor-replace="inner"`.

Third-party widgets can opt out of morphing:

```html
<div data-dp-reactor-ignore id="chart"></div>
<div data-dp-reactor-ignore-self data-widget-shell></div>
```

`data-dp-reactor-ignore` leaves the element and its children untouched.
`data-dp-reactor-ignore-self` keeps the element attributes untouched but still
morphs its children.

Actions can ask for confirmation without custom JavaScript:

```html
<button data-dp-reactor-action="delete" data-dp-reactor-confirm="Delete this item?">
	Delete
</button>
```

The client emits cancellable and post-update lifecycle events:

- `dataphyre:reactor-before-request`
- `dataphyre:reactor-before-morph`
- `dataphyre:reactor-after-morph`
- `dataphyre:reactor-updated`
- `dataphyre:reactor-error`

The client toggles `data-dp-reactor-busy` and `aria-busy` on the mounted root.
Visual update flashes are not built into Reactor; applications opt into those
styles when they want them.

Offline state is exposed on mounted roots with `data-dp-reactor-offline`.
Elements marked with `data-dp-reactor-offline-indicator` show only while offline;
`data-dp-reactor-online-indicator` does the inverse.

## Validation

Rules are component-owned and can be attached to all actions or selected actions:

```php
Reactor::component('profile-form')
	->state(['email'=>''])
	->rules(['email'=>'required|email'], actions: ['save'])
	->action('save', function(array $state, array $params, $component, $effects): array {
		$effects->toast('Profile saved.', 'success');
		return $state;
	});
```

Model updates can opt into live validation without running a final action:

```php
Reactor::component('profile-form')
	->state(['email'=>''])
	->rules(['email'=>'required|email'], actions: ['save'])
	->validateOnUpdate('email');
```

`validateOnUpdate(true)` validates changed fields that have rules.
`validateOnUpdate(['email', 'profile.name'])` limits live validation to a named
subset. Live validation emits the same `errors` effect as action validation, so
existing error slots update without a custom client script.

When validation fails, the action is skipped and the client marks matching
fields with `data-dp-reactor-invalid`, `aria-invalid`, and
`data-dp-reactor-error`. Error slots can render messages without a full redraw:

```html
<input data-dp-reactor-model="email" name="email">
<p data-dp-reactor-error-for="email" hidden></p>
```

## Introspection

Reactor exposes a manifest for Flightdeck, Panel diagnostics, and app-level
debug pages:

```php
$manifest=Reactor::manifest();
```

The manifest includes the module version, registered component count, component
capabilities, client bindings, listeners, and the current trace summary. It is
safe to inspect because it describes component shape, not private state values.

Routes that want a JSON manifest can delegate directly:

```php
ReactorEndpoint::emitManifest();
```

`ReactorTrace::events()` returns the bounded lifecycle event list for the
current request. Dispatch now records request creation, component lookup,
snapshot verification, authorization failures, model changes, validation,
actions, effects, response assembly, and span timing.

## Panel Integration Direction

Panel should adapt widgets, forms, modal actions, table tools, and relation
managers into Reactor components. Reactor remains the lifecycle engine; Panel
remains the admin UI and resource shell.
