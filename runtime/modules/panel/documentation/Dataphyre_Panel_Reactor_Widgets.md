# Panel Reactor widget bridge

Panel's Reactor bridge is an optional production adapter for progressively enhanced `Widget` instances. Panel remains usable without Reactor. Loading the bridge creates no static fallback and Reactor has no dependency on Panel.

## Closed host registration

The application registers the Reactor component first, then creates one exact Panel definition and one host-owned binding:

```php
use Dataphyre\Panel\Bridges\Reactor\PanelReactorWidgetBinding;
use Dataphyre\Panel\Bridges\Reactor\PanelReactorWidgetRuntimeAdapter;
use Dataphyre\Panel\PanelWidgetInteractionDefinition;

$definition=PanelWidgetInteractionDefinition::make('reactor', 'order-counter')
    ->action('increment', 'Increment')
    ->refresh('manual');

$binding=PanelReactorWidgetBinding::make('orders', $definition, 'orders.counter')
    ->actions(['increment'=>'increase'])
    ->surfaces(['dashboard']);

$adapter=(new PanelReactorWidgetRuntimeAdapter(
    $reactorManager,
    $snapshotVersionStore,
    '/panel/widgets/runtime/reactor'
))->bind($binding);

$panel->registerWidgetRuntimeAdapter($adapter);
```

Every public Panel action requires an explicit Reactor action mapping. Binding fails if the action sets differ, the route or definition is duplicated, or the Reactor component is not already registered on that manager. Request JSON never carries a Reactor component, PHP class, adapter, surface authority, or mapped Reactor action. A route surface must satisfy the binding policy; a follow-up request must also prove the exact Panel scope through its host-issued binding tag and Reactor snapshot.

The adapter strips body-provided `_reactor_signed` and `_panel_widget` values before adding its own trusted `_panel_widget` metadata. A browser therefore cannot smuggle a Reactor signed-parameter envelope or replace the host idempotency metadata through the Panel payload.

`surfaces('*')` is an explicit opt-in for applications with dynamic Panel surfaces. It does not let the body choose a component. Non-mount requests remain bound to the original panel, surface, principal, tenant, session, island id, binding route, definition fingerprint, and Reactor component through both Panel's binding tag and Reactor's signed scope. A snapshot issued for one island or binding cannot be replayed through another island or through a second definition that happens to target the same Reactor component.

## Controller boundary

Mount a POST route matching the adapter endpoint:

```text
/panel/widgets/runtime/reactor/{panel_widget_binding}/{panel_widget_surface}
```

Neither loading the bridge nor registering the adapter mounts this route. The
bridge also does not authenticate a session, construct the trusted
`PanelRequest`, issue CSRF tokens, choose an origin policy, configure Reactor
signing keys or transport authorization, or install snapshot-version
persistence. The host must provide every one of those boundaries explicitly.

Create `PanelReactorWidgetController` with required host callbacks. The origin callback receives `(string $origin, PanelRequest $request)`. The CSRF callback receives `(PanelRequest $request, string $xCsrfToken, string $ability)` where the ability is `panel.widget.interact`.

```php
$controller=new PanelReactorWidgetController(
    $panel,
    $exactSameOriginValidator,
    $csrfValidator
);

$result=$controller->dispatch(
    $panelRequest,
    $boundedRawJsonBody,
    'reactor',
    $routeBinding,
    $routeSurface
);
```

The host must pass the raw body, not a reconstructed form payload. The controller enforces POST, exact JSON request media type, standards-compatible JSON response negotiation, the `DataphyrePanelWidget` custom header, identity encoding, no query or upload channel, a syntactically valid allowed Origin, fail-closed CSRF, exact declared length when present, a configurable body bound, an object-shaped JSON body, and the closed Panel interaction grammar. `Accept: application/json`, an applicable positive-quality wildcard, or an omitted `Accept` header can negotiate the JSON response; a more-specific `application/json;q=0` still rejects JSON even when a broader wildcard is positive. Adapter and binding resolution occurs only after those guards.

The controller validates the injected origin and CSRF policies but is not a
primary authentication system. The host must authenticate first and populate
the `PanelRequest` from server-trusted identity, tenant, and session state; body
or route values are never authoritative scope claims.

HTTP runtime routes use the adapter's canonical name. The registered route alias, adapter identity, and returned definition adapter must match exactly; the controller rejects alias pivots and cross-adapter definitions before context resolution or dispatch.

Browser requests forward `X-CSRF-Token` when the host exposes a bounded token through `data-dp-widget-csrf`, `window.DataphyrePanel.csrfToken`, or `<meta name="csrf-token">`. A missing token is never synthesized; the host validator still decides whether a request is valid. The controller rejects empty, line-breaking, or greater-than-4096-byte CSRF header values before invoking that validator.

All responses are JSON with `no-store`, `nosniff`, same-origin referrer policy, and Origin variance. Valid interaction failures use the normal exact `panel_widget_interaction_result` envelope. Pre-resolution transport failures use the exact `panel_widget_runtime_error` envelope so probing cannot discover registered adapters or components.

## Lifecycle mapping

| Panel operation | Reactor behavior | Public Panel version |
| --- | --- | --- |
| `mount` | Initial render-only dispatch; root and child snapshot issuance commits after the complete root tree renders | Reactor version `0` becomes Panel version `1` |
| `hydrate` | Signed render-only dispatch; consumes Reactor CAS and rotates the snapshot | Reactor version + 1 |
| `action` | Signed dispatch through the binding's explicit action map | Reactor version + 1 |
| `refresh` | Signed render-only dispatch; consumes Reactor CAS and rotates the snapshot | Reactor version + 1 |
| `unmount` | Proves v2 signature and exact scope before expiry classification, runs the fail-closed Reactor transport policy, then revokes the exact ledger entry without component resolution, hydration, rendering, callbacks, or successor issuance | Existing Panel version |

Panel accepts snapshots up to 8192 bytes. A larger Reactor snapshot fails closed with `widget_snapshot_too_large`; applications must reduce public component state or use another interaction boundary. Public Reactor state must also satisfy Panel's bounded, secret-key-rejecting JSON contract.

Hydrate and refresh are real CAS rotations even though they do not call a Reactor action. Unmount is a one-time exact revoke. Replaying a revoked or missing authentic snapshot returns the same stable conflict and no component callback runs. Forged and cross-scope snapshots return the same invalid-snapshot outcome; only an authentic, correctly scoped snapshot may receive the distinct expired outcome. A successful unmount response deliberately carries `null` endpoint, snapshot, and binding-tag fields, so the consumed snapshot is not reflected into the response.

## Host revocation obligations

The Reactor manager used by the adapter must have the same injected `ReactorSnapshotVersionStore` and a fail-closed transport authorizer. Production must use a store whose manifest truthfully declares `production_safe=true`; the process-local memory store is only a reference and test adapter.

The transport authorizer must explicitly recognize the value-free `snapshot_revoke` operation. It receives the verified component name, mutation flag, bounded verification metadata, and public host-scope facts. It does not receive snapshot state, snapshot id, scope hash, signature, action parameters, uploads, or request values. Panel supplies its trusted request, widget context, and interaction request as server-side `ReactorSecurityContext` attributes for the policy to inspect. The policy must authorize from those trusted attributes and must not try to recover authority from browser JSON.

Revocation transport failures are intentional fail-closed outcomes: a missing policy or denial returns forbidden, a thrown policy or unavailable ledger returns a retryable service failure, and the ledger is untouched. The policy decision occurs immediately before the exact store revoke. Hosts must not wrap unmount in component/domain callbacks or treat a replayed conflict as success.

## Delivery and idempotency limits

Reactor snapshot CAS prevents two completed requests from advancing the same snapshot version. It does not make application side effects, session writes, the HTTP response, and the snapshot ledger one atomic transaction. The adapter forwards the Panel idempotency value to `params._panel_widget.idempotency_key`, but it does not retain or replay results and it does not claim exactly-once delivery.

Business actions that charge, send, provision, publish, or otherwise leave durable effects must enforce idempotency in the owning application domain. The same rule applies when the HTTP client retries a 5xx response.

The adapter manifest exposes the selected version-store coordination scope and production-safety claim, the `reactor_version_plus_one` mapping, the 8192-byte compatibility budget, deferred child issuance, render-only rotations, exact unmount revocation, and the absence of idempotent replay or exactly-once effects. The Panel widget registry reports `reactor_bridge=true` only while an active adapter truthfully declares `production_reactor_bridge=true`.

Those manifests do not serialize signing keys, snapshots, component state,
sessions, callbacks, CSRF values, or authorizer internals. Unregistering the
adapter only detaches the Panel registry entry; it does not revoke Reactor
snapshots or delete version-store records. Incident response and lifecycle
cleanup must revoke or expire those records through the host-owned Reactor
boundary before detachment when immediate invalidation is required.
