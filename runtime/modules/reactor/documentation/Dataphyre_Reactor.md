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

Snapshots are signed state manifests. Schema v2 binds the normalized component,
state, locked keys, creation and expiry, a random instance id, a monotonic CAS
version, and a keyed host-scope tag. A client sends the snapshot back when it
calls an action. Reactor verifies the complete envelope and exact host scope
before it resolves or hydrates the component.

Snapshot state is a strict deterministic JSON-value tree: null, booleans,
integers, finite floats, valid UTF-8 strings, lists, and maps only. Objects,
`JsonSerializable`, resources, non-finite floats, invalid UTF-8, excessive
depth/node counts, and oversized keys/strings fail before signing. Object
serializers are never invoked while validating untrusted or server state.

```php
use Dataphyre\Reactor\ReactorSecurityContext;

$scope=ReactorSecurityContext::fromTrusted([
	'tenant_id'=>(string)$authenticatedTenant->id,
	'principal_id'=>(string)$authenticatedUser->id,
	'session_id'=>$serverSessionId,
	'audience'=>'operator-panel',
]);
$snapshot=Reactor::snapshot('seller-health', ['score'=>91], $scope);
```

Scope input is mandatory for initial `snapshot()` and `mount()` issuance. Supply
it directly, install `ReactorManager::withHostSecurityContext()`, or configure a
host-owned `security_context_resolver`. Resolution occurs before a configured
component loader, lifecycle callback, computed initializer, or renderer can run.
Reactor never reads `security_context`, tenant, principal, session, or audience
from the client body or headers. Those values are untrusted application data even
when their names resemble the canonical host context fields.

Initial issuance is not an authorization shortcut. The same fail-closed transport
authorizer runs with `operation=mount` or `operation=snapshot_issue` before
component lookup. Its envelope contains bounded input key names, never input or
component-state values. After lookup and initial hydration, the component's
domain `authorize()` callback must also allow issuance. A mount does not render
after domain denial, and no snapshot is registered before both stages succeed.

The serialized scope tag is a domain-separated HMAC, not a plain hash of often
low-entropy ids. Current and retained signing keys verify it during rotation. A
snapshot issued under an old key remains valid only while that key is retained;
keep old keys for at least the maximum snapshot TTL, then retire them.

Locked state keys are restored from the component definition during hydration.
This prevents the client from replacing identifiers such as tenant ids, user ids,
or record ids.

Locked values provided at mount time are preserved from the signed snapshot on
later requests. This means dynamic values such as record ids and tenant ids do
not need to live in component defaults to stay protected.

Reactor never uses a repository-known fallback signing secret. Configure either
one private `secret`, or a versioned keyring with `signing_keys` and
`current_signing_key`. Production secrets must contain at least 32 bytes. Old
keys can remain temporarily in `previous_signing_secrets` while clients finish
round trips signed before a rotation:

```php
'reactor' => [
	'production' => true,
	'signing_keys' => [
		'2026-07' => getenv('REACTOR_SIGNING_KEY_CURRENT'),
		'2026-06' => getenv('REACTOR_SIGNING_KEY_PREVIOUS'),
	],
	'current_signing_key' => '2026-07',
],
```

A one-key keyring selects its sole key even when the kernel's
`current_signing_key` default is null. Multi-key rotation is deliberately
ambiguous without an explicit current key and fails closed.

When no key is configured outside production, Reactor creates a random,
process-local development key. That is safe from a public fallback-secret
attack, but signatures cannot cross PHP workers or restarts; configure a real
key for any multi-worker development server. Production fails closed when its
key is missing or weak. Unsigned debug payloads are disabled by default and can
only be enabled explicitly with `allow_unsigned_in_debug=true` outside
production.

Offline state transactions use the same keyed signer. They never downgrade to
an unkeyed checksum, and transaction identifiers require cryptographic
randomness; an unavailable signer or entropy source stops the operation instead
of producing forgeable state.

Signed snapshots are required by default for every action, state update, or
parameter-bearing dispatch. Snapshot timestamps reject invalid or future-dated
payloads and expire after 24 hours in every environment by default. Set a shorter
strict-integer `snapshot_max_age_seconds` where the workflow permits it;
configured lifetimes are capped at 30 days. Expiry is inclusive:
`expires_at <= current_time` is expired in verification and version stores.

### Snapshot CAS and replay semantics

A signed `version` field alone cannot reject replay. Reactor therefore uses an
interface-driven `ReactorSnapshotVersionStore` and a reservation/finalize/abort
protocol before component code:

1. atomically reserve the exact instance/scope/component/version;
2. run component hydration, domain policy, model hooks, action, and rendering;
3. finalize the reservation to the next version and return its signed successor;
4. abort on denial or failure so the client is not permanently stranded.

Reactor constructs and JSON-validates the complete response, including state,
HTML, effects, and successor snapshot, before it finalizes or registers the next
version. Invalid UTF-8 or another serialization failure therefore aborts the
reservation and lets the same client snapshot retry. Initial component mounts
defer every parent and child snapshot registration until the complete root tree
has rendered. The current store contract registers one instance at a time, so a
partial registration failure only triggers best-effort `revoke()` calls; it is
not an atomic multi-snapshot transaction. A custom adapter that needs strict
all-or-none tree issuance must provide that transaction at its storage boundary.

Component dehydration is split into a pure state transform and a deferred
session-binding commit. Failed response serialization or CAS finalize never
advances Reactor session state. After the snapshot ledger advances, session
persistence is best effort so a session backend failure cannot strand the client
on an already-consumed version. This leaves an explicit crash window between the
authoritative snapshot commit and the session write; snapshot and PHP-session
state are not claimed to be atomic.

`ReactorInMemorySnapshotVersionStore` is deterministic only inside one manager
process and is not production safe. `ReactorFileSnapshotVersionStore` publishes
immutable generations with private-directory confinement, symlink rejection,
bounded expiry pruning, an advisory lock, and same-directory atomic rename. It
also normalizes pre-existing ledger directories to owner-only permissions and
clears the filesystem stat cache before verifying the resulting mode; an
unreadable or still-group/world-accessible directory fails closed. It is
production-capable only after the operator verifies that every worker shares a
private filesystem with correct cross-worker `flock` and atomic-rename behavior.
For distributed deployments, install an adapter backed by a database or other
linearizable CAS service.

Reservations prevent completed replay and concurrent component execution within
the adapter's declared coordination scope. They do not make arbitrary action
side effects transactional. A worker crash after an external side effect but
before finalize leaves a bounded reservation that becomes retryable; action and
domain mutation handlers still need idempotency keys or their own transaction.
`snapshot_reservation_ttl_seconds` is a strict integer from 5 through 300. Store
adapters also reject leases beyond 300 seconds or beyond the snapshot's own
expiry, and reject lease timestamps that are not strictly in the future, so
direct store callers cannot create an invalid or arbitrarily long busy claim.

Legacy unscoped v1 snapshots are disabled by default and always disabled in
production. A temporary non-production migration can set the exact named policy
`legacy_snapshot_policy=allow_unscoped_debug_v1`. Accepted v1 requests have no
stateful replay guarantee and are upgraded to scoped v2 in the response. Remove
the policy as soon as old local clients have refreshed.

Nested paths can be locked:

```php
Reactor::component('order-editor')
	->state(['order'=>['id'=>null], 'title'=>''])
	->locked('order.id');
```

## Transport and Domain Authorization

Every dispatch has two deliberately separate authorization stages. The first is
a fail-closed transport/envelope policy and runs before component resolution,
hydration, lifecycle/model/action/render callbacks, and CAS-protected component
execution. It receives only component/action, bounded request key names/counts,
verified snapshot schema/version/time metadata, secret-free scope-presence facts,
and the trusted host context. Snapshot state and request values are absent.
File uploads count as mutation input even when action, state, and parameter maps
are otherwise empty.

```php
$manager
	->withHostSecurityContext($scope)
	->authorizeTransport(function(array $envelope, ReactorSecurityContext $host): bool {
		return $host->get('authenticated')===true
			&& $policy->allows((string)$envelope['component'], $envelope['action']);
	});
```

No authorizer means `transport_security_required`; an exception becomes
`transport_authorization_unavailable`; a non-true result becomes
`transport_denied`. These public error envelopes contain an error code and safe
correlation id, but no HTML or state. Security traces retain component/action,
status, and public code only; callback exceptions and state values are not
serialized. Batch items are independently denied while sharing the one trusted
host context resolved at the batch boundary.

For a request-facing endpoint, configure trusted host callbacks rather than
copying scope-looking client headers:

```php
'reactor'=>[
	'security_context_resolver'=>fn(): array=>[
		'tenant_id'=>(string)HostAuth::tenantId(),
		'principal_id'=>(string)HostAuth::principalId(),
		'session_id'=>HostAuth::sessionId(),
		'audience'=>'operator-panel',
		'authenticated'=>HostAuth::check(),
	],
	'transport_authorizer'=>fn(array $envelope, ReactorSecurityContext $host): bool=>
		$host->get('authenticated')===true,
	'snapshot_version_store'=>new ReactorFileSnapshotVersionStore(
		$privateLedgerPath,
		sharedFilesystemAttested:true,
	),
],
```

The second stage is the component's domain authorization. It intentionally runs
after verified state hydration and locked-state restoration because record-level
policy often needs that domain state, but still before model hooks and actions.

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

### Migration checklist

1. Install a stable 32-byte-or-longer signing key/keyring on every worker.
2. Resolve a canonical tenant/principal/session tuple or explicit host audience
   at the server boundary; never accept scope from Reactor payload fields.
3. Pass that scope into initial mounts/snapshots and dispatch capture.
4. Install a stateful version-store adapter covering every worker that can handle
   the same browser snapshot; production rejects the process-local memory store.
5. Install the transport authorizer, then keep component `authorize()` callbacks
   for hydrated domain policy. Exercise both dispatch and the initial `mount` /
   `snapshot_issue` operations in policy tests.
6. Refresh clients onto v2. Use the named v1 debug policy only if a local staged
   migration is unavoidable, and never in production.
7. Retain rotated keys for the maximum snapshot TTL and verify stale, cross-scope,
   upload-only, batch-denial, serialization-failure retry, partial mount commit,
   and worker-crash behavior in the deployment topology.

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

## Transaction Security And Offline Replay

`ReactorTransactions` provides compare-and-swap state patches, exact inverse
receipts, idempotency, bounded offline replay, and finite SSE batches. Both the
coordinator and HTTP-neutral endpoint fail closed. A request-facing integration
must configure mutation authorization, stream authorization, and a host transport
boundary:

```php
use Dataphyre\Reactor\ReactorStateTransaction;
use Dataphyre\Reactor\ReactorTransactions;

$coordinator=ReactorTransactions::filesystem($stateRoot.'/reactor')
	->authorize(function(
		ReactorStateTransaction $transaction,
		array $currentState,
		int $version,
		array $securityContext
	): bool {
		return $securityContext['user']?->can('update', $transaction->component()) === true;
	})
	->authorizeStream(function(string $component, array $securityContext): bool {
		return $securityContext['user']?->can('view', $component) === true;
	});

$endpoint=ReactorTransactions::endpoint($coordinator)
	->validateOrigin(fn(array $context): bool => $hostSecurity->validOrigin($context))
	->validateCsrf(fn(array $context): bool => $hostSecurity->validCsrf($context))
	->authorizeTransport(function(string $operation, array $context): bool {
		return $context['user'] !== null && $context['session_valid'] === true;
	});

$result=$endpoint->dispatch($requestBody, true, [
	'user'=>$authenticatedUser,
	'session_valid'=>$sessionIsValid,
	'origin'=>$requestOrigin,
	'csrf'=>$csrfToken,
	'correlation_id'=>$requestId,
]);
```

The security context is trusted host input, not a structure copied from the
transaction body. Reactor cannot infer an application's session, origin, CSRF,
tenant, or authorization policy and therefore never claims to validate those on
its own. `allowUnauthenticatedTransactions()`, `allowUnauthenticatedStreams()`,
and `allowInsecureLegacyTransport()` are explicit compatibility escape hatches
for already-protected internal calls. Do not use them on routes.

Endpoint failures use a stable versioned envelope containing `status`, `ok`,
`error.code`, `error.message`, and `error.correlation_id`. Exceptions raised by
host security, mutation, validation, persistence, or parsing code are not exposed.
Authorization runs before an idempotent receipt is returned, so knowing another
request's idempotency key cannot disclose its receipt or state.

Browser offline storage is disabled unless all four scope values are present:

```html
<section
	data-dp-reactor-component="orders"
	data-dp-reactor-tenant="tenant-7"
	data-dp-reactor-user="operator-12"
	data-dp-reactor-session="session-93"
	data-dp-reactor-contract-version="2"
	data-dp-reactor-csrf="..."
></section>
```

Queues are partitioned by tenant, user, session, contract version, and component.
Records have a bounded TTL, per-item limit, item-count limit, and total byte limit;
the runtime returns `offline_rejected` instead of silently evicting work when
backpressure is reached. Legacy component-only queues are never replayed. Before
clearing the host identity during logout, purge the current scope:

```js
DataphyreReactorTransactions.purge({
	tenant: tenantId,
	user: userId,
	session: sessionId,
	contractVersion: "2"
});
```

The same purge runs when the host dispatches `dataphyre:reactor-logout` with the
scope in `event.detail`.

SSE batches retain their named events. The browser registers listeners for
`transaction.committed`, rejection/conflict events, and `reactor.error`, while
also accepting unnamed `message` events. It persists a scope-specific cursor in
session storage, supplies `after_sequence` on a fresh connection, honors the
browser's `Last-Event-ID` reconnect behavior, and deduplicates by sequence and
transaction id. The host route must derive the next cursor from its validated
query/header input and pass the same verified security context:

```php
$after=max(
	(int)($request->query('after_sequence') ?? 0),
	(int)($request->header('Last-Event-ID') ?? 0)
);
echo $endpoint->eventStream('orders', $after, 100, $securityContext);
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
after the dehydrated response version commits successfully. Persistence is best
effort and does not roll back the snapshot ledger; use an application transaction
for domain state that requires atomic durability. This is useful for table
preferences, wizard progress, and modal state that should remain server-owned.

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
capabilities, client bindings, listeners, a secret-free signing capability
description, and the current trace summary. It describes component shape and
state keys, never component state values or signing secrets.

Detailed recent trace records are exposed by default only outside production.
Production manifests retain aggregate event counts but clear `latest` and
`active_spans`; an authenticated operator-only diagnostic route can opt back in
with `expose_trace_manifest=true`. Trace context is recursively bounded,
UTF-8-normalized, and redacts credential-shaped keys and strings. Exception
records retain only the source basename rather than an absolute server path.

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
