# Dataphyre Panel Module

The Panel module is the first-party resource layer for building internal control
surfaces on top of Dataphyre SQL, Access, and Templating. It provides typed
resource definitions instead of forcing applications to hand-write every CRUD
screen from raw templates.

Panel is intentionally a framework module. Loading it registers the panel
namespace and loads the SQL, Access, and Templating framework layers when the
core framework loader is available. It is not a routed application; it is a set
of reusable resource, form, table, action, widget, and rendering primitives.

## Reality Status

The current capability audit lives in
[Dataphyre_Panel_Capability_Audit.md](Dataphyre_Panel_Capability_Audit.md).
Use it as the source of truth for what is solid, partial, demo-only, or missing
before comparing Panel to Filament-style admin builders. The `/debug` live
example is a useful exerciser, but it is not by itself a production-completeness
claim.

For PHPStan/Psalm generics, typed callback signatures, extension macro stubs,
and the analyzer-free contract gate, see
[Dataphyre_Panel_Static_Analysis.md](Dataphyre_Panel_Static_Analysis.md).

For bounded virtual tables and alternate record collections, signed window
intents, cursor privacy, progressive enhancement, and migration guidance, see
[Dataphyre_Panel_Data_Surface.md](Dataphyre_Panel_Data_Surface.md).

For deterministic host-bound PHP and TypeScript clients, protocol schemas,
semantic compatibility reports, and publication boundaries, see
[Dataphyre_Panel_Application_SDK.md](Dataphyre_Panel_Application_SDK.md).

For collector-driven evidence, source-bound framework reference profiles,
fingerprint-pinned plans, signed runs, freshness, crosswalks, and evidence drift,
see
[Dataphyre_Panel_Compliance_Automation.md](Dataphyre_Panel_Compliance_Automation.md).

For the strict outbound POST/JSON adapter, immutable capability pins, approved
scope projection, cursor binding, and host-owned network policy, see
[Dataphyre_Panel_HTTP_Data_Source.md](Dataphyre_Panel_HTTP_Data_Source.md).

For the accessible route-free Studio workspace and the optional production
Reactor widget transport, see
[Dataphyre_Panel_Studio_Editor.md](Dataphyre_Panel_Studio_Editor.md) and
[Dataphyre_Panel_Reactor_Widgets.md](Dataphyre_Panel_Reactor_Widgets.md).

For host-wired change streams, bounded model-proposed tool plans, and their
shared-SQL workflow store, see
[Dataphyre_Panel_Realtime.md](Dataphyre_Panel_Realtime.md) and
[Dataphyre_Panel_Agent_Safe_Workflows.md](Dataphyre_Panel_Agent_Safe_Workflows.md),
then
[Dataphyre_Panel_Distributed_Agent_Workflows.md](Dataphyre_Panel_Distributed_Agent_Workflows.md).

For shared-SQL leased jobs, the distributed command/event fabric, and
transactional state migrations, see
[Dataphyre_Panel_Distributed_Operations.md](Dataphyre_Panel_Distributed_Operations.md),
[Dataphyre_Panel_Distributed_Command_Fabric.md](Dataphyre_Panel_Distributed_Command_Fabric.md),
and
[Dataphyre_Panel_Distributed_Migrations.md](Dataphyre_Panel_Distributed_Migrations.md).

For the cohesive domain-as-code, universal work, policy, governed operator,
semantic, compliance, federation, release, local-first, marketplace, and Studio
branch control plane plus its bounded redacted operator console, see
[Dataphyre_Panel_Operations_OS.md](Dataphyre_Panel_Operations_OS.md).

For the non-executing, preview-first Filament 3/4/5 resource exit path, see
[Dataphyre_Panel_Filament_Migration.md](Dataphyre_Panel_Filament_Migration.md).

For signed Kubernetes, Nomad, ECS, Compose, and filesystem release intents and
fence-bound deployment receipts, see
[Dataphyre_Panel_Release_Deployment.md](Dataphyre_Panel_Release_Deployment.md).

For source-bound, exact-tree, expiring, signed browser and quality evidence,
independent verification, and replay keys, see
[Dataphyre_Panel_Release_Evidence.md](Dataphyre_Panel_Release_Evidence.md).

```php
\dataphyre\core::load_framework_module('panel');
```

The generated surface is URL-agnostic. Panel does not own application paths;
generated forms, links, redirects, breadcrumbs, relation actions, bulk actions,
and global search resolve through a host-provided `url_builder` or fall back to
query-state on the current host page.

```php
return [
	'dataphyre'=>[
		'panel'=>[
			// Optional. Without this, Panel links back to the current page with
			// resource, operation, record, relation, and action query parameters.
			'url_builder'=>'app_panel_url',
		],
	],
];

function app_panel_url(string $target, array $query): string {
	$base='/workspace';
	return $base.($target!=='' ? '/'.$target : '').($query!==[] ? '?'.http_build_query($query) : '');
}
```

### Routing and MVC Mounts

Panel remains route-agnostic, but it can now be mounted inside Dataphyre
Routing or MVC when an application wants clean path URLs instead of query-state
URLs. `Panel::mountedRoutes()` registers the page catch-all plus Panel asset and
upload endpoints. During dispatch, the route controller injects mounted URL
builders so generated links, CSS/JS, and custom uploader endpoints stay under
the same prefix.

When a route reuses a Panel surface below an application base path, the
controller resolves the effective mount from the current request path. For
example, a route configured with the inner prefix `/admin` and reached at
`/backoffice/admin/orders` generates assets, uploads, forms, and navigation
under `/backoffice/admin` for that request.

```php
use Dataphyre\Panel\Panel;

// Dataphyre Routing
return [
	...Panel::mountedRoutes('/admin', 'default', [
		'name'=>'admin.panel',
		'middleware'=>['auth'],
	]),
];

// Dataphyre MVC
Panel::mvcMountedRoutes($app->routes(), '/admin', 'default', [
	'name'=>'admin.panel',
	'middleware'=>['auth'],
]);
```

The mounted URL contract is canonical:

```php
Panel::routeUrlBuilder('/admin')('orders/edit/42');        // /admin/orders/42/edit
Panel::routeAssetUrl('/admin', 'panel.css');               // /admin/assets/panel.css?v=...
Panel::routeUploadUrl('/admin');                           // /admin/upload
Panel::routeManifest('/admin', 'default', ['name'=>'admin.panel']);
```

### Secure standalone front controller

Applications that do not load Dataphyre Routing or MVC can mount the same
surface through `Panel::standaloneHost()`. This is a complete front-controller
boundary, not a shortcut around application security:

```php
use Dataphyre\Panel\Panel;

$host=Panel::standaloneHost('default', '/admin')
	->authenticateUsing(fn($request)=>current_user($request))
	->tenantUsing(fn($request)=>trusted_workspace_for($request))
	->authorizeUsing(fn(string $ability, $user, $tenant)=>policy_allows($user, $ability, $tenant))
	->rateLimitUsing(fn($request)=>panel_rate_limit($request))
	->originUsing(fn(string $origin)=>hash_equals('https://console.example.com', $origin))
	->csrfUsing(
		fn(string $scope)=>issue_panel_token($scope),
		fn(string $token, string $scope)=>validate_panel_token($scope, $token),
	)
	->allowMutations()
	->allowUploads();

// public/index.php or a PHP built-in-server router:
if(!$host->serve()){
	return false;
}
```

The host is immutable and read-only by default. Assets are public GET/HEAD
routes unless disabled. Pages require authentication (or explicit
`allowAnonymous()`), authorization, and rate limiting. Enabling mutations or
uploads additionally requires a token issuer, token validator, and origin
validator before even a safe form-rendering request is considered ready; this
prevents a write-capable deployment from quietly rendering tokenless forms.
Unsafe requests with a missing/invalid origin or token fail with `403` or `419`.
Missing or throwing policy infrastructure fails with `503`.

The mount uses an exact prefix boundary (`/adminx` never matches `/admin`).
Malformed, nested, slash/backslash-bearing, control-character, dot-traversal,
oversized, or over-deep path/input/header/cookie/upload data is rejected before
Panel dispatch. Reserved `assets/{asset}` and `upload` routes cannot fall
through into page routing. The incoming HTTP request is rebuilt instead of
mutated: query/body route identity, caller-supplied user/tenant attributes, and
tenant headers are removed, while the trusted authentication and tenant
resolvers repopulate those values.

Downstream responses pass through a second boundary that strips hop-by-hop and
`Connection`-nominated headers, preserves multi-value cookies and streams,
normalizes HEAD/204/304 semantics, applies no-store and browser security
headers, and confines redirects to the same Panel mount. A
`redirectUsing()` policy may explicitly approve a syntactically safe HTTP(S)
external target. Error envelopes expose stable codes and correlation IDs;
exception detail appears only after explicit `developmentErrors()`.

`manifest()` is secret-free and reports the exact routes, capability mode,
missing policies, request limits, and deployment prerequisites. PHP/web-server
`post_max_size`, `upload_max_filesize`, header, and body limits must remain at
least as strict as the host limits because PHP may parse form and multipart
input before application code runs.

When a panel is dispatched through a mounted route, `Panel::panelManifest()`
also includes a `routes` section with the current prefix, endpoint paths,
generated examples, and controller classes. Unmounted panels report
`routes.mounted=false` so tooling can distinguish route-free embeds from native
Routing/MVC mounts. The root `widget_runtime` entry is the secret-free manifest
of that exact `PanelInstance` registry; its capability summary reports active
adapter count, persistent binding-key configuration, and Reactor-bridge
installation without serializing keys, callbacks, component state, or sessions.
The root `data_sources` entry is emitted only from an already-ready attached
platform registry and uses its registration snapshots; manifest generation
never resolves a lazy service factory or invokes a live adapter. The separate
`data_surfaces` and `studio_editor` entries report instance attachment and
route-free editor availability without serializing endpoints, credentials,
signing keys, CSRF values, preview bearers, or checkpoints. None of these
manifests proves that a host route, identity boundary, or persistence adapter is
installed.

The root `realtime` and `agent_workflows` entries are also passive integration
contracts. They report a surface as configured only when every required service
is already resolved to the expected type and the endpoint/runtime wraps those
exact registered dependencies. Building a manifest never resolves a factory,
opens a stream, invokes an executor or policy callback, or installs transport.

Use the narrower helpers when an application wants to place endpoints in
separate route groups: `Panel::routes()`, `Panel::assetRoutes()`,
`Panel::uploadRoutes()`, `Panel::mvcRoutes()`, `Panel::mvcAssetRoutes()`, and
`Panel::mvcUploadRoutes()`. Legacy kernel endpoints under
`/dataphyre/panel/assets/...` and `/dataphyre/panel/upload` still work and
delegate to the same controller code used by mounted routes.

### Interactive widget lifecycle

Widgets remain static by default. A widget becomes progressively interactive
only after it receives a `PanelWidgetInteractionDefinition` and the renderer
explicitly mounts it through the owning `PanelInstance` runtime registry.
Serialization, manifests, and state inspection do not create sessions.

The browser request carries only the island id, operation-specific values, an
opaque adapter snapshot, and a public binding tag. Tenant, principal, session,
Panel surface, and authorization context are derived again from the trusted
host request. Mount, hydrate, action, refresh, and unmount requests use strict
operation-specific schemas, optimistic versions, and idempotency keys. The
client validates exact, bounded response shapes and requires the response-body
status to match the HTTP status before committing state or rotating credentials.

```php
use Dataphyre\Panel\PanelInMemoryWidgetRuntimeAdapter;
use Dataphyre\Panel\PanelWidgetInteractionDefinition;
use Dataphyre\Panel\Widget;

$runtime=new PanelInMemoryWidgetRuntimeAdapter('/panel/widgets/runtime', $signingKeys);
$runtime->register(
	'counter',
	['value'=>0],
	['increment'=>fn(array $state,array $payload): array=>[
		'value'=>$state['value']+(int)($payload['by'] ?? 1),
	]],
	fn($definition,$context,$request): bool=>$context->principal()!==null,
);

$panel->registerWidgetRuntimeAdapter($runtime);
$panel->registerWidget(
	Widget::make('counter')
		->value(0)
		->interactive(
			PanelWidgetInteractionDefinition::make('memory','counter')
				->action('increment','Increment')
				->refresh('manual')
		)
);
```

The bundled in-memory adapter is a bounded conformance and single-process host
adapter. It is explicitly non-durable and not multi-process safe. Its handler
replay protects adapter state and responses; external handler side effects are
not exactly once, so hosts must make those effects idempotent. Unmount delivery
uses a best-effort keepalive request and bounded TTL expiration is the abrupt
disconnect fallback.

Panel also ships an optional production `PanelReactorWidgetRuntimeAdapter` and
fail-closed `PanelReactorWidgetController`. Merely loading those classes or
registering an adapter installs no route, authentication, authorization, CSRF
policy, origin policy, signing key, snapshot-version persistence, or business
idempotency. The host must register the exact POST route, construct the trusted
`PanelRequest`, inject the validators and Reactor transport authorizer, and use
a production-safe persistent version store. Public manifests report
`reactor_bridge=false` until an active adapter truthfully declares the
production bridge capability. See the
[Reactor widget bridge guide](Dataphyre_Panel_Reactor_Widgets.md).

Hosts that expose the endpoint must parse `PanelWidgetInteractionRequest`,
resolve the server-registered widget definition, derive a fresh trusted
`PanelWidgetInteractionContext` from the current `PanelRequest`, dispatch via
the instance registry, and serialize the returned result. Browser payloads
must never select an adapter class or supply authoritative scope claims.

### Capability-driven browser assets

Panel has three browser-delivery strategies. `capability` remains the
compatibility default and emits capability-scoped `panel.css` and `panel.js`
aggregates. `physical` emits independently cacheable CSS and JavaScript files
from the same closed capability graph and is the recommended HTTP/2 or HTTP/3
production lane. `full` retains the historical monoliths as an explicit
rollback. Existing asset URL builders work with all three modes.

Capability and physical style URLs carry a canonical `dp_panel_caps` token plus
a content-derived version. The asset controller validates that token before
serving the selected cascade. Forged, reordered, duplicated, or unknown tokens
return a no-store 404. Physical runtime files are universal immutable chunks,
so attaching a capability token to one is rejected instead of creating a
second cache identity.

Capabilities are additive. Panel discovers its own generated markup and page
kind, while a custom page can declare additional requirements through
`asset_capabilities`:

```php
$page=PanelPage::make('operations-map')->renderUsing(static fn()=>[
	'content'=>'<section data-dp-reactor="map">...</section>',
	'data'=>['asset_capabilities'=>['reactor', 'acme-map']],
]);

return PanelContext::run([
	'asset_mode'=>'physical',   // recommended; capability is the compatibility default
	'asset_integrity'=>true,    // optional SRI on framework-owned assets
	'asset_nonce'=>$requestNonce,
	'asset_capability_urls'=>[
		// Optional-module or host-owned capability assets.
		'reactor'=>'/dataphyre/reactor/assets/reactor.js?v=...',
		'acme-map'=>[
			'url'=>'https://cdn.example.test/acme-map.js',
			'type'=>'script',
			'attributes'=>['crossorigin'=>'anonymous'],
		],
	],
], static fn()=>PanelRenderer::customPage($page, $request));
```

Built-in capabilities are dependency-closed: `board` includes `table` and
`shell`; `editor` and `upload` include `form`; `editor-assets` includes
`editor`; `modal` closes over form, upload, editor, and editor-asset support
because daughter payloads can introduce any of them; `collaboration` includes
`reactor`; every surface includes `shell`. Generated markers cover tables,
forms, records, boards, charts, modals, uploads, editors, editor asset pickers,
media, collaboration, authentication, extensions, platform surfaces, quality
tooling, and Reactor islands. A declaration cannot remove a capability required
by rendered content.

Configured brick, masonry, and per-item responsive controls also activate the
`collection-layout` capability. Plain shells do not download that layout
fragment. Tables, forms, records, boards, and interactive widgets retain their
shared v2 collection foundation automatically; a responsive v3 grow, shrink,
order, break, or fill map adds the dedicated cache dimension even on those
surfaces. Custom pages are detected from `data-dp-display` and item markers, so
framework builders do not require a manual `asset_capabilities` declaration.

Framework and package tooling can inspect the same deterministic contract:

```php
$graph=PanelRenderer::assetCapabilityManifest(['board', 'editor']);
$manifest=PanelRenderer::assetManifest(
	$graph->capabilities(),
	'physical',
	['integrity'=>true, 'nonce'=>$requestNonce],
);

foreach($manifest['styles'] as $asset){
	// name, URL, bytes, SHA-256, SRI, attributes, and logical chunks
}
```

Physical delivery keeps the legacy cascade in six ordered files:
`panel-style-tokens.css`, `panel-style-foundation.css`,
`panel-style-layout.css`, `panel-style-experience.css`,
`panel-style-themes.css`, and `panel-style-accessibility.css`. Runtime ownership
is split into dependency-declared `panel-runtime-*.js` files. Kernel,
interaction, transport, and quality are always present; form, editor, Studio
editor, Data Surface, Widget, modal, and board runtimes are selected only when
their closed capabilities require them.

Every physical descriptor includes its exact byte count, SHA-256, optional SRI,
version, and runtime dependencies. The public `panel-assets.json` route exposes
the same secret-free manifest for publishers and release auditors. Runtime
chunks register under `window.DataphyrePanel.runtimeChunks`; implementation
functions live in a private shared runtime scope rather than leaking
`dpPanel*` globals. Dependency checks fail before a chunk executes when an
earlier file is absent. AJAX navigation replaces capability-dependent styles
before revealing the destination and adds each immutable runtime chunk at most
once per document, allowing already downloaded universal chunks to remain
warm.

The aggregate `capability` lane preserves the same CSS source order and compiles
selected controllers into one content-addressed `panel.js`. The base editor
lifecycle remains independently cacheable as `panel-editor.js`;
browse/upload/delete picker behavior and its scoped CSS are delivered only for
`editor-assets` as `panel-editor-assets.js`. Provider-free editor pages
therefore do not download the picker runtime. Modal shells retain the dependency
because a daughter payload can introduce a provider-backed editor after initial
render. Optional extension, platform, quality, Reactor, and host assets remain
independently cacheable manifest entries.

For a CDN that strips query parameters, a host that pre-publishes only the old
monoliths, or a staged migration, set `asset_mode` to `full`. `panel.css`,
`panel.js`, `PanelRenderer::assetContent()`, and direct asset routes retain the
historical full-bundle behavior when no capability token is supplied. Unknown
mode values also fail safe to `full`.

The snapshot tool supports aggregate and physical publication:

```powershell
php runtime/modules/panel/testing/panel_asset_snapshot.php `
  --output-dir=cache/panel-assets/full --mode=full

php runtime/modules/panel/testing/panel_asset_snapshot.php `
  --output-dir=cache/panel-assets/table `
  --mode=capability --capabilities=shell,table,navigation

php runtime/modules/panel/testing/panel_asset_snapshot.php `
  --output-dir=cache/panel-assets/physical-table `
  --mode=physical --capabilities=shell,table,navigation `
  --report=cache/panel-assets/physical-table.json
```

Do not hand-craft `dp_panel_caps`: use the manifest or rendered asset URLs so
dependency ordering and hashes stay canonical. Cache keys must include the query
string. Nonces and allow-listed attributes (`integrity`, `crossorigin`,
`referrerpolicy`, `fetchpriority`, and relevant stylesheet attributes) are
escaped on framework-owned tags; event-handler attributes and unsafe URL schemes
are discarded.

The repository-owned delivery auditor fetches every public descriptor, verifies
same-origin immutable responses, hashes, SRI, MIME types, dependency order,
gzip/Brotli sizes, parse time, and a real Chromium bootstrap. Its checked-in
budgets are release ratchets, not estimates:

```powershell
node runtime/modules/panel/testing/panel_asset_delivery_audit.js `
  --manifest-url=http://127.0.0.1:8098/panel/assets/panel-assets.json `
  --browser --report=cache/panel-asset-delivery-audit.json
```

### Modal Chrome

Panel slide-over modals keep secondary chrome actions in the header, but those
actions wrap within the available header width instead of creating a horizontal
scrollbar. Password reveal controls use the host panel localization keys such as
`common.show`, so localized applications keep generated form controls in the
same language as the surrounding surface.

Create-resource modal descriptions use neutral operator copy by default. The
`table.create_resource_body` localization key tells the operator to add details
and save when ready, without exposing implementation language such as generated
forms or table mechanics.

### Native Localization

Panel has a first-pass, route-agnostic localization layer for translatable
labels and copy. It is intentionally small and framework-native: a
`PanelLocalization` catalogue tracks the active locale, fallback locale, flat or
nested scoped keys, parameter interpolation, and a JSON manifest.

```php
$panel=Panel::make('seller')
	->localization([
		'locale'=>'fr-CA',
		'fallback_locale'=>'en',
		'translations'=>[
			'en'=>[
				'actions.save'=>'Save :resource',
			],
			'fr'=>[
				'actions'=>[
					'save'=>'Enregistrer :resource',
				],
			],
		],
	]);

echo $panel->trans('actions.save', ['resource'=>'orders']);
echo $panel->localization()->scope('actions')->t('save', ['resource'=>'orders']);
```

Lookup checks the requested locale, its base language, the fallback locale, and
the fallback base language. Placeholders support `:name`, `{name}`, and
`{{ name }}` forms. The catalogue serializes through `toArray()` and
`jsonSerialize()` for host manifests without requiring Panel to own routes.

Panel instances can carry their own manager and URL/config context. Use them
when an application exposes more than one surface, or when a package should
provide panel building blocks without touching the process-local default panel.

```php
$seller_panel=Panel::make('seller')
	->label('Seller Console')
	->homeLabel('Overview')
	->urlBuilder('seller_console_url')
	->authorize(fn(string $ability, ?Resource $resource, mixed $user, PanelRequest $request) => $user!==null);

$seller_panel->register(
	$seller_panel->resource('orders')
		->label('Order')
		->pluralLabel('Orders')
		->table('commerce.orders')
		->fields([
			$seller_panel->field('number')->required(),
			$seller_panel->field('status', 'select')->options(['open'=>'Open', 'shipped'=>'Shipped']),
		])
);

$seller_panel->registerNavigationItem(
	$seller_panel->navigationItem('storefront')
		->label('Open Storefront')
		->group('Commerce')
		->icon('external-link')
		->url('/seller/storefront')
		->description('Preview the public selling experience')
);

echo $seller_panel->dispatch(PanelRequest::capture())->content();
```

Packages can expose providers instead of routes. A provider receives the surface
that the host created and registers only definitions.

```php
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelProvider;

final class CommercePanelProvider implements PanelProvider {

	public function panel(PanelInstance $panel): PanelInstance {
		$panel->register(
			$panel->resource('orders')
				->label('Order')
				->pluralLabel('Orders')
		);
		$panel->registerWidget($panel->stat('open_orders', fn() => OrderRepository::openCount()));
		return $panel;
	}
}

$seller_panel->provide(CommercePanelProvider::class);
```

### Native Access Auth

Panel can register native authentication pages through Dataphyre Access. This
adds route-agnostic pages for login, logout, registration, email verification,
password reset, and password change, then protects the rest of the surface with
the active Access guard.

```php
\dataphyre\core::load_framework_module('panel');
\dataphyre\core::load_framework_module('access');

use Dataphyre\Panel\Panel;

$panel=Panel::make('ops')
	->label('Operations')
	->auth([
		'allow_registration'=>true,
		'require_email_verification'=>true,
		'after_login'=>'/debug',
	]);
```

The auth pages use the same Panel URL builder as the rest of the surface. Mail
for verification and password reset is sent through Dataphyre Mailer when the
Mailer framework is available. Applications can provide an identity repository
through `DP_ACCESS_CFG['identity']` callbacks or a configured SQL users table.

### Render hooks

Panel surfaces expose trusted render hooks for small host or package-owned UI
extensions. Hooks do not register routes and they do not require forking the
renderer. They receive a context array and return HTML.

```php
$seller_panel
	->renderHook('content.before', function(array $context): string {
		$tenant=$context['tenant'] ?? null;
		return $tenant===null
			? ''
			: '<div class="panel-scope">Showing tenant '.htmlspecialchars((string)$tenant).'</div>';
	})
	->renderHook('resource.index.after', function(array $context): string {
		$resource=$context['resource'] ?? null;
		if(!$resource instanceof Resource || $resource->name()!=='orders'){
			return '';
		}
		return '<aside>Orders index was extended by a package.</aside>';
	});
```

Available shell hooks:

| Hook | Position |
| --- | --- |
| `head.end` | Before the closing `</head>` tag. |
| `body.start` | Immediately after the opening `<body>` tag. |
| `body.end` | Before the closing `</body>` tag. |
| `header.before` | Before breadcrumbs, brand, and the generated page heading. |
| `header.after` | After the generated page heading tools. |
| `page.before` | Inside the generated `<main>` before page content. |
| `page.after` | Inside the generated `<main>` after page content. |
| `content.before` | Around the content payload before it enters the page shell. |
| `content.after` | Around the content payload after it enters the page shell. |

Available resource hooks:

| Hook | Position |
| --- | --- |
| `resource.index.before` | Before a resource index table surface. |
| `resource.index.after` | After a resource index table surface. |
| `resource.form.before` | Before create/edit forms. |
| `resource.form.after` | After create/edit forms. |
| `resource.show.before` | Before a record show surface. |
| `resource.show.after` | After a record show surface. |

Hook callbacks receive `array $context`, `string $hook`, and `PanelManager
$manager` when their signature asks for them. Common context keys include
`kind`, `title`, `tenant`, `request`, `resource`, `page`, `theme`, `data`, and
`manager`. Resource hooks receive the live `Resource` object; shell hooks receive
the serialized resource/page metadata from the rendered result.

Render hook output is trusted server-side HTML. Escape user content before
returning it.

Named surfaces can also be kept in the registry. This remains route-free: a host
can fetch the same surface from a console command, a page, an API responder, or a
test harness.

```php
Panel::surface('seller')
	->label('Seller Console')
	->urlBuilder('seller_console_url')
	->provide(CommercePanelProvider::class);

$page=Panel::surface('seller')->dispatch(PanelRequest::capture());
```

Hosts can use `PanelHost` when they want Panel to capture the current request
and emit the result. This is still not routing; it is the boundary between a host
that already matched a request and a Panel surface that can answer it.

```php
Panel::host('seller', $current_user)->emit();
```

Use `Panel::standaloneHost()` instead when Panel itself must own exact-prefix
routing, request limits, policy gates, trusted identity rebuilding, response
sanitization, and SAPI emission. `PanelHost` remains the lighter adapter for a
framework that already supplied those boundaries.

For frameworks that own their own response object, keep the result instead:

```php
$result=Panel::host('seller', $current_user)->dispatch();

return app_response(
	$result->content(),
	$result->status(),
	$result->headers()
);
```

## Production Platform Runtime

[`PanelPlatform`](../Framework/Platform/PanelPlatform.php) is the optional,
instance-owned facade for Panel's production services. It complements the
route-agnostic resource renderer; it does not turn Panel into a routed
application or introduce process-global service state. `PanelPlatform::defaults()`
assembles the local atomic reference stack, while `PanelPlatform::make()`,
`register()`, and `factory()` let a host assemble equivalent database, broker,
object-storage, or broadcast-backed domains.

```php
use Dataphyre\Panel\PanelPlatform;

$platform=PanelPlatform::defaults([
	'state_root'=>$stateRoot,
	// Opt in when workers can overlap, restart, or run on multiple processes.
	'distributed_operations'=>[
		'lease_ttl_seconds'=>60,
		'snapshot_retention'=>512,
	],
	// Explicit opt-in: definitions are instance-owned executable code.
	'migrations'=>[
		'definitions'=>$migrationDefinitions,
		'snapshot_retention'=>512,
		'authorize'=>$migrationAuthorizer,
	],
	// Explicit opt-in; inject any PanelTelemetryExporter in production.
	'observability'=>[
		'exporter'=>$telemetryExporter,
		'sample_ratio'=>0.25,
		'sampling_seed'=>'panel-production',
	],
	'authentication'=>[
		'encryption_key'=>$authenticationEncryptionKey,
		'pepper'=>$authenticationPepper,
	],
	// Explicit opt-in: durable tenant IAM, separate from login/session handling.
	'iam'=>[
		'audit_keys'=>[
			'2026-q2'=>$iamAuditKey2026Q2,
			'2026-q3'=>$iamAuditKey2026Q3,
		],
		'current_audit_key_id'=>'2026-q3',
		'authorize'=>$iamAuthorizer,
	],
	// Explicit opt-in: portable envelopes plus trusted schema materialization.
	'studio'=>[
		'authorization'=>$studioAuthorizer,
		'registry_version'=>'2026.1',
		'required_publish_approvals'=>1,
		'preview_keys'=>[
			'2026-q2'=>$studioPreviewKey2026Q2,
			'2026-q3'=>$studioPreviewKey2026Q3,
		],
		'current_preview_key'=>'2026-q3',
	],
	'media'=>[
		'signing_key'=>$mediaSigningKey,
	],
	'security'=>[
		// Strongly recommended: enables HMAC tamper evidence for the audit chain.
		'audit_key'=>$securityAuditKey,
	],
	'platform'=>[
		'csrf'=>$csrfValidator,
		'authorize'=>$platformAbilityAuthorizer,
	],
]);

$runner=$platform->operationRunner();
$sources=$platform->dataSources();
$preferences=$platform->preferences('operator-42', 'operations', 'desktop');
$collaboration=$platform->collaboration();
$tenantIam=$platform->iam()->scope('tenant-7', 'operator-42');
$studio=$platform->studio();
$capabilities=$platform->manifest()->jsonSerialize();
```

`state_root` is required, writable, and may not be a symbolic link.
Authentication encryption/pepper and media signing values must be explicitly
configured with at least 32 bytes. Security audit keys use the same minimum.
IAM is opt-in and requires either `iam.audit_key` for a single key or a named
`iam.audit_keys` keyring whose values are each at least 32 bytes. A keyring with
more than one entry also requires `iam.current_audit_key_id`. Keep retired keys
configured until every audit event signed by them has aged out of the retained
chain; removing a still-required key makes verification fail closed. IAM
manifests expose only key identifiers, counts, and rotation state, never key
material. Top-level `iam_audit_key` and `iam_audit_keys` remain supported for
hosts that inject secrets separately from domain options.
Studio is also opt-in. Its authorization policy defaults to deny, and preview
signing has no fallback key: each configured `studio.preview_keys` value must be
at least 32 bytes and `studio.current_preview_key` selects the issuing key.
Retain retired keys only for the short preview TTL in which their capabilities
must remain valid. Preview nonces are bounded but reusable until expiry; there
is no one-time consumption store. `PanelStudioManager::verifyPreview()` also
requires the token's content hash and revision to remain the current document
head and re-checks that head against the active registry/compiler/materializer,
so any later save or stale execution contract invalidates the intent. Studio
manifests never include private keys, raw tokens, idempotency keys, or host
callbacks.
`studio.registry` and `studio.materializer` may be injected as typed instances;
otherwise Panel creates the default registry and audited materializer. A
`studio.schemas` list may register typed, provenance-labelled schemas, but the
materializer executes only schema contracts matching one of its audited kinds.
New or semantically changed kinds therefore remain validation/portable work
until a materializer release explicitly supports them.
When `security.audit_key` is configured, audit events use HMAC-SHA-256 and
reject checksum downgrade attempts; without it, the compatible SHA-256 chain is
reported as integrity checking rather than tamper evidence. Configure the key
before the first event; archive or explicitly migrate an existing checksum-only
audit before enabling keyed verification. Set any domain to
`false` when it is not required. Defaults never serialize those secrets or the
raw configuration.

Platform reads and mutations are fail-closed. Read pages require an authenticated
security context plus the corresponding `<domain>.view` ability (for example
`operations.view`, `authentication.view`, or `security.view`). Mutations retain
their operation-specific abilities and require CSRF. A custom `platform.authorize`
callback is evaluated only after identity and tenant equality have succeeded, so
it cannot override tenant isolation. Mounted first-party pages pass the active
request through the same controller boundary; a separate page authorizer may
still impose stricter UI policy.

Plugin registration, boot, unload, and reload are transactional across the
surface registries and the platform container. A rollback restores service and
factory structure plus nested services implementing
`PanelCheckpointableService`, including the data-source and DataSurface
registries and the agent tool catalog. Singleton factory resolution is itself a
revisioned lifecycle mutation; rolling back a failed plugin restores the prior
resolved-or-factory state and revision. Checkpoints are trusted, bounded,
in-process transaction units that retain object and closure references. They
are not persistence, cache, queue, or wire payloads. Arbitrary mutable service
objects cannot be rewound automatically; a custom service that plugins may
mutate should implement the typed checkpoint contract or be replaced
atomically instead of being mutated in place. Platform diagnostics report this
boundary rather than claiming full object-graph rollback.

### Transactional adapter packs

[`PanelAdapterPack`](../Framework/Platform/PanelAdapterPack.php) packages a
dependency-ordered set of typed infrastructure integrations into the same
transactional plugin boundary. A
[`PanelAdapterPackBinding`](../Framework/Platform/PanelAdapterPackBinding.php)
can contribute exactly one `platform:`, `search:`, `plugin:`, or `data:` target.
Bindings declare their runtime contract, dependencies, optional framework
classes, accepted configuration keys, replacement default, capabilities, and
an optional production conformance suite. Factories remain process-local and
are never executed by preview or manifest generation.

The bundled [`PanelDataphyreAdapterPack`](../Framework/Adapters/PanelDataphyreAdapterPack.php)
is an opt-in pack for Dataphyre Access, Fulltext, Mailer, and Storage-backed
media. Each binding can be enabled independently:

```php
use Dataphyre\Panel\Panel;

$panel=Panel::make('operations')->usePlatform($platform);
$pack=$panel->dataphyreAdapterPack();

$plan=$panel->planAdapterPack($pack, [
	'adapters'=>[
		'access'=>[
			'options'=>$accessOptions,
		],
		'fulltext'=>[
			'index'=>'orders',
			'manager'=>$searchManager,
			'map'=>$searchHitMapper,
			'options'=>[
				'tenant_scoped'=>true,
				'criteria'=>$searchCriteria,
			],
		],
		'mailer'=>[
			'directory'=>$privateStateRoot.'/panel-notifications',
			'manager'=>$mailerManager,
			'recipient'=>$notificationRecipient,
			'options'=>[
				'provider'=>'transactional',
			],
		],
		'storage_media'=>[
			'storage_manager'=>$storageManager,
			'disk'=>'private',
			'prefix'=>'panel/media',
			'catalog_directory'=>$privateStateRoot.'/panel-media-catalog',
			'signing_key'=>$mediaSigningKey,
			'options'=>[
				'disk'=>[
					'name'=>'private-media',
					'default_max_bytes'=>1024 * 1024 * 1024,
					'write_options'=>['visibility'=>'private'],
				],
				'manager'=>[
					'delivery_url'=>'/panel/media/private',
					'cleanup_grace'=>7 * 86400,
				],
				'retention'=>512,
			],
		],
	],
	'conformance'=>['fulltext', 'storage_media'],
	'conformance_options'=>[
		'fulltext'=>[
			'query'=>'conformance',
			'minimum_results'=>0,
		],
		'storage_media'=>[
			'namespace'=>'panel_conformance_media',
		],
	],
	'allow_destructive_conformance'=>true,
]);

if(!$plan->ready()){
	throw new RuntimeException(implode(' ', $plan->errors()));
}

// Revalidates definition, private configuration, and current Panel state.
$activation=$plan->apply();
```

The `storage_media` binding replaces `platform:media.manager` transactionally.
It accepts an already composed `PanelMediaManager`, or composes
`PanelDataphyreStorageMediaDisk` from a named `Dataphyre\Storage\StorageManager`
disk plus either a `PanelSnapshotStore` or local atomic
`catalog_directory`. Panel paths remain confined below the private prefix and
every backend operation continues through `StorageManager`, preserving its
guards, encryption, decorators, and events. Prefixes, credentials, signing
keys, provider errors, and storage options are absent from public manifests.
The generic Storage contract does not promise compare-and-swap or atomic
rename, so this adapter reports atomic create/write/move as false and uses
verified best-effort compensation for failed replacement and move operations.
Run destructive conformance only in a disposable namespace.

`PanelAdapterPackPlan` performs class, dependency, collision, contract, and
target preflight without resolving an adapter. It records a process-keyed
configuration digest and exact Panel-state fingerprint; applying a stale plan
fails before registration. Public plan, pack, binding, and activation JSON
contain configuration key names and typed target evidence, never raw
configuration values, callback bodies, adapter objects, storage directories,
or credentials. They are diagnostic manifests, not resumable installation
payloads.

Installation constructs bindings in topological order, runs selected
conformance suites, and registers every contribution through rollback-aware
Panel registries. A factory error, type mismatch, target-identity mismatch, or
required conformance failure removes the parent pack and every contribution
already installed in that transaction. Unloading the parent pack likewise
removes its search, nested-plugin, and data-source contributions and restores
replaced platform/data services. Plugin target collisions remain fail-closed;
`replace` never silently swaps an existing nested plugin.

The first-party Fulltext bridge converts bounded Dataphyre search results into
typed `PanelSearchPage` values and isolates mapper/iteration failures as partial
diagnostics. The Mailer bridge retains a durable filesystem inbox and delivery
receipts while delegating the email channel to `MailerManager`; its manifest
does not expose the state directory, resolver, message factory, provider
configuration, or callbacks. The Access bridge registers only when
`Dataphyre\Access\PanelAuth` is available. None of the three installs routes,
credentials, identity, authorization policy, workers, indexes, provider
accounts, or remote transports.

`PanelAdapterConformanceCatalog::searchProvider()` is non-destructive.
`notificationAdapter()` performs a reversible inbox lifecycle and is marked
destructive, so adapter-pack installation runs it only when
`allow_destructive_conformance=true` is explicit. Use that flag only against a
disposable adapter namespace or isolated tenant. Keep
`require_conformance=true` and `allow_skipped_conformance=false` for production
activation unless an independently reviewed deployment policy requires a
different gate.

Authentication inventory and mutation surfaces are owner-bound. Self-service
requests are scoped to the authenticated actor, and every factor, challenge,
trusted-device, and session identifier is resolved through that scope so a
foreign identifier is indistinguishable from a missing one. Targeting another
user requires the operation-specific ability and a separate, audited
`authentication.cross_user` decision. Authentication policy subjects expose
the requested `target`, server-resolved `owner`, and referenced `id`; generated
inventory actions preserve the authorized target user. Grant
`authentication.cross_user` only to administrative roles whose primary
identity policy already permits account recovery or security intervention.

IAM complements, rather than replaces, Authentication, `PanelSecurityPolicy`,
or `PanelTenantRegistry`. Authentication owns factors, challenges, trusted
devices, and sessions; the host still owns primary identity and SSO. The IAM
domain owns immutable tenant principal/service-account descriptors, versioned
memberships, role and permission grants, service credential *metadata*, and a
bounded tamper-evident audit chain. `PanelTenantRegistry` still resolves the
active UI tenant; pass that resolved tenant to `iam()->scope()` for every
request-facing read.

IAM authorization is deliberately fail-closed. Every mutation requires an
explicit tenant, actor, reason, idempotency key, and policy callback. Exact
idempotent replays are fingerprint-bound and re-run the current policy before a
receipt is returned. Request code must use `PanelScopedIamManager`; its tenant
and actor cannot be replaced, and every read/list/audit operation is authorized.
The unscoped manager read methods are trusted-internal APIs for already isolated
maintenance processes. The default high-risk grant patterns are `iam.*`,
`security.*`, and `tenant.owner`; matching grants require a distinct requester
and acting approver unless that protection is explicitly disabled. Adding `*`
to `high_risk_permissions` is an explicit choice to require two-person approval
for every permission grant.

When substituting a connected domain, replace its store, runner/manager, and
related services together rather than leaving a default service bound to the
old store.

| Domain | Production contract and facade entry points |
| --- | --- |
| Platform UI | [`PanelPlatformManifest`](../Framework/Platform/PanelPlatformManifest.php) separates available, configured, and ready capabilities. [`PanelPlatformController`](../Framework/Http/PanelPlatformController.php) and [`PanelPlatformTemplate`](../Framework/Templates/PanelPlatformTemplate.php) expose guarded operations, workflow, automation, relation, notification, media, authentication, security, and developer pages. Use `controller()` and `templateClass()`. |
| Operations | [`PanelOperationRecord`](../Framework/Operations/PanelOperationRecord.php), filesystem store, local queue, handler registry, runner, control, and data-job bridge provide persistent idempotent work, progress, checkpoints, retries, pause/resume/cancel, logs, and artifacts. Use `operationStore()`, `operationHandlers()`, `operationRunner()`, and `operationControl()`. |
| Distributed operations | [`PanelAtomicLeasedOperationStore`](../Framework/Operations/PanelAtomicLeasedOperationStore.php), [`PanelPdoLeasedOperationStore`](../Framework/Operations/PanelPdoLeasedOperationStore.php), [`PanelOperationLease`](../Framework/Operations/PanelOperationLease.php), and [`PanelLeasedOperationRunner`](../Framework/Operations/PanelLeasedOperationRunner.php) provide at-least-once execution with expiring ownership, renewal, monotonic fencing, stale-worker rejection, deterministic recovery, token digests at rest, and bounded change feeds. The explicit-migration PDO adapter adds shared MySQL/PostgreSQL/SQLite durability, optimistic rows, hashed idempotency lookup, and skip-locked reservation without installing a connection, schema, service, or worker. Enable `distributed_operations`, then attach one cohesive graph through `distributedOperationStore()`, `distributedOperationHandlers()`, `distributedOperationRunner()`, and `distributedOperationControl()`. See the [distributed-operations guide](Dataphyre_Panel_Distributed_Operations.md) and run both operation-store conformance packs before activation. |
| Distributed command fabric | [`PanelCommandFabric`](../Framework/Fabric/PanelCommandFabric.php) atomically binds its encrypted command journal, signed receipts, and tamper-evident event outbox. [`PanelPdoCommandFabricStore`](../Framework/Fabric/PanelPdoCommandFabricStore.php) adds explicit-migration MySQL/PostgreSQL/SQLite durability, bounded reset-aware metadata feeds, expiring subscriber leases, monotonic fences, token digests at rest, and cursor advancement atomically conditioned on the live fence. Delivery remains at least once and native handlers/projectors retain downstream idempotency responsibility; cross-database ACID and distributed exactly once are explicitly false. Inject it as `operations_os.fabric_store`, configure a process-specific subscriber worker and lease TTL, read the [distributed command-fabric guide](Dataphyre_Panel_Distributed_Command_Fabric.md), and run both command-fabric conformance packs before activation. |
| Observability | [`PanelTelemetryRuntime`](../Framework/Observability/PanelTelemetryRuntime.php) composes an exporter, deterministic sampler, strict W3C propagator, lifecycle hub, and correlation bridge. Enable `observability`, then use `observability()`, `telemetryExporter()`, `telemetry()`, and `telemetryBridge()`. The bounded memory exporter is a reference/local sink; production deployments should inject their vendor or transport adapter and run `telemetryExporter()` conformance. |
| State migrations | [`PanelMigrationDefinition`](../Framework/Migrations/PanelMigrationDefinition.php), registry, planner, integrity-bound plan, runner, report, [`PanelAtomicMigrationStore`](../Framework/Migrations/PanelAtomicMigrationStore.php), and [`PanelPdoMigrationStore`](../Framework/Migrations/PanelPdoMigrationStore.php) provide strict semantic/schema edges, dependency ordering, tenant scopes, dry-run preflight, bounded resumable batches, idempotency, fenced execution, backups, compensation, snapshot recovery, and redacted receipts. The explicit-migration PDO adapter adds independently locked MySQL/PostgreSQL/SQLite scope documents, same-connection handler/checkpoint transactions without unsafe handler replay, durable cross-node recovery, and a payload-free retained change feed. Enable `migrations`, optionally inject the exact host-owned store, then use `migrationStore()`, `migrationRegistry()`, `migrationRunner()`, `registerMigration()`, and `migrationPlan()`. See the [distributed-migrations guide](Dataphyre_Panel_Distributed_Migrations.md) and run the destructive conformance pack before activation. |
| Filament resource migration | [`PanelFilamentSourceAnalyzer`](../Framework/Migrations/Filament/PanelFilamentSourceAnalyzer.php), [`PanelFilamentMigrationInventory`](../Framework/Migrations/Filament/PanelFilamentMigrationInventory.php), [`PanelFilamentMigrationPlan`](../Framework/Migrations/Filament/PanelFilamentMigrationPlan.php), and the preview-first CLI statically inventory Filament 3/4/5 sources, follow Filament 5 split schema/table companions, map verified literal resource/field/column declarations, and transactionally publish root-confined Panel resource drafts. Source files are never loaded or executed. Generated resources intentionally omit data, mutation, authorization, and tenancy adapters and always report `ready_to_activate=false`. See the [Filament migration guide](Dataphyre_Panel_Filament_Migration.md). |
| Data sources | [`PanelDataSource`](../Framework/Data/PanelDataSource.php), query/result/cursor contracts, registry, array/callback/repository adapters, subscriptions, and [`PanelDataSourceResourceBridge`](../Framework/Data/PanelDataSourceResourceBridge.php) decouple resources from one ORM. The instance-owned registry validates and caches adapter capabilities at registration, supports provenance-aware contribution layers, and exposes exact checkpoint/restore for atomic plugin rollback; the root `data_sources` manifest uses those registration snapshots and never resolves a factory or executes adapter code. The [SQL/PDO guide](Dataphyre_Panel_SQL.md) covers the allowlisted compiler and signed keyset cursors. The [remote HTTP guide](Dataphyre_Panel_HTTP_Data_Source.md) covers the strict read-only POST/JSON adapter; its transport, credentials, DNS/proxy/egress policy, approved scope mapper, runtime, and immutable capability pin remain explicit host inputs. Use `dataSources()` and `registerDataSource()` only after attaching a platform whose `data.registry` service is ready. |
| Data surfaces | [`PanelDataSurfaceRegistry`](../Framework/Data/Surface/PanelDataSurfaceRegistry.php) binds six collection surfaces and eight advanced DataCanvas surfaces to bounded, signed, tenant/principal-scoped window and interaction intents. Definition contributions are layered with provenance and exact checkpoint/restore; manifests use registration snapshots and never run adapter capability code. Attach only an explicitly secured registry with `useDataSurfaces()`, expose its framework-neutral endpoint with `dataSurfaceEndpoint()`, and see the [DataSurface guide](Dataphyre_Panel_Data_Surface.md). No permissive authorizer or signing key is synthesized. |
| Realtime change streams | [`PanelRealtimeEndpoint`](../Framework/Realtime/PanelRealtimeEndpoint.php), signed connect/resume intents, bounded broker replay, reset semantics, framework-neutral SSE responses, and an optional Fetch-streamed client provide tenant/principal-bound at-least-once delivery. [`PanelPdoRealtimeAdapter`](../Framework/Realtime/PanelPdoRealtimeAdapter.php) adds explicit-migration MySQL/PostgreSQL/SQLite publication, retained replay, and distributed hashed connect-intent consumption. [`PanelRedisRealtimeAdapter`](../Framework/Realtime/PanelRedisRealtimeAdapter.php) adds Redis 6.2+ Streams, cluster-slot-safe fixed scripts, exact retention, integrity-checked replay, distributed nonce consumption, and callback/phpredis/Predis transports while leaving durability as an honest host-configured claim. The host must register the exact broker, signer, and endpoint graph, derive trusted subscriptions, authorize each open, and own connections/credentials, persistence/failover policy, schema or namespace rollout, the route, origin/CSRF policy, rate limits, timeouts, emitter, and infrastructure operations. See the [Realtime guide](Dataphyre_Panel_Realtime.md). |
| Workflows | [`WorkflowEngine`](../Framework/Workflows/WorkflowEngine.php) and memory/filesystem stores provide guarded transitions, roles, drafts, assignments, SLA checks, quorum approvals, optimistic versions, idempotency, compensation, rollback, and hash-chained history. Use `workflowEngine()` and `registerWorkflow()`. |
| Automation | [`AutomationExecutor`](../Framework/Automation/AutomationExecutor.php), action registry, schemas, policies, risk/confirmation, dry runs, approval handoffs, redacted receipts, idempotency, and rollback form the machine-readable action graph. Use `automationRegistry()`, `automationExecutor()`, and `registerAutomation()`. |
| Agent-safe workflows | [`PanelAgentRuntime`](../Framework/Agents/PanelAgentRuntime.php) validates bounded structured proposals against an instance-owned catalog, re-evaluates host policy, verifies signed plan/approval intents and host-owned confirmation evidence, reserves idempotent execution, and records redacted hash-chained receipts. [`PanelAtomicAgentWorkflowStore`](../Framework/Agents/PanelAtomicAgentWorkflowStore.php) provides a crash-safe cross-process local adapter. [`PanelAgentWorkflowOperationBridge`](../Framework/Agents/PanelAgentWorkflowOperationBridge.php) adds optional at-least-once leased-worker delivery using a non-secret queued commitment and a host-owned secure resolver; agent-store idempotency converges lease-loss retries without repeating a completed executor call. The host still owns the model, identity, policy, confirmation ceremony, keyring, routes, downstream executors, secure pending-material repository, worker process, state roots, and remote multi-node adapters. Register one cohesive `agents.*` graph and see the [agent-safe workflow guide](Dataphyre_Panel_Agent_Safe_Workflows.md). |
| Authentication | [`PanelAuthenticationManager`](../Framework/Authentication/PanelAuthenticationManager.php), [`PanelAuthenticationAccess`](../Framework/Authentication/PanelAuthenticationAccess.php), and [`PanelScopedAuthenticationManager`](../Framework/Authentication/PanelScopedAuthenticationManager.php) provide encrypted memory/filesystem stores, TOTP enrollment and verification, recovery codes, one-time step-up challenges, trusted devices, session revocation, non-enumerating object ownership, and explicitly audited cross-user elevation. Use `authentication()`; the host still owns primary identity, password, SSO, and administrative recovery policy. |
| Tenant IAM | [`PanelIamManager`](../Framework/Iam/PanelIamManager.php), [`PanelScopedIamManager`](../Framework/Iam/PanelScopedIamManager.php), and memory/atomic stores provide tenant-bound principals, service accounts, versioned memberships, grants, suspension/restoration/revocation, credential-metadata rotation, optimistic revisions, policy-rechecked idempotency, two-person high-risk grants, and a rotating-key HMAC audit chain. Enable `iam`, then use `iam()` and request-facing `iam()->scope($tenantId, $actorId)`. Remote stores should implement `PanelIamStore` atomically and pass its destructive conformance pack. |
| Studio composition and trusted materialization | [`PanelStudioManager`](../Framework/Studio/PanelStudioManager.php), [`PanelStudioSchemaRegistry`](../Framework/Studio/PanelStudioSchemaRegistry.php), [`PanelStudioMaterializer`](../Framework/Studio/PanelStudioMaterializer.php), and memory/filesystem stores provide tenant/principal-scoped immutable drafts, typed schema validation, actual callback-free Panel builders, artifact-bound approval/publication/rollback, optimistic/idempotent changes, impact plans, rotating preview capabilities, hash-chained history, and a portable reset feed. Enable `studio`, then use `studio()`, `studioRegistry()`, `studioMaterializer()`, and `studioStore()`. The compiler's portable envelope remains non-executable by itself; trusted execution requires the registry/materializer path. Connected stores should pass `studioStore()` conformance. |
| Studio editor, signed collaboration transport, and visual runtime | [`PanelStudioEditor`](../Framework/Studio/Editor/PanelStudioEditor.php) adds an accessible SSR/no-JS workspace with progressive keyboard/pointer reordering, typed property controls, undo/redo, optimistic saves, and conflict handling. The optional [`PanelStudioCollaborationConnector`](../Framework/Studio/Connectors/PanelStudioCollaborationConnector.php) projects policy-guarded threads, comments, assignments, watches, presence, typing, and scoped IAM identities while deriving actor and document scope exclusively from the trusted editor session. [`PanelStudioCollaborationIntentSigner`](../Framework/Studio/Collaboration/PanelStudioCollaborationIntentSigner.php), [`PanelStudioCollaborationTransport`](../Framework/Studio/Collaboration/PanelStudioCollaborationTransport.php), and [`PanelStudioCollaborationEndpoint`](../Framework/Studio/Collaboration/PanelStudioCollaborationEndpoint.php) add rotating tenant/document/principal-bound browser intents, visibility-aware delta polling, CSRF-protected state changes, host-custodied presence, safe SSR fragment convergence, and single-attempt mutations. The opt-in [`PanelStudioVisualRuntime`](../Framework/Studio/Visual/PanelStudioVisualRuntime.php) renders unsaved authorized sessions, signed revisions, and published revisions as actual Panel surfaces in bounded empty-permissions frames. It strips executable/external document assets and embeds only allow-listed capability-scoped first-party Panel CSS, so previews remain styled without `allow-same-origin`. Enable `studio.visual_runtime`, then use `renderStudioVisualPreview()` or the runtime's signed/published methods. These adapters register no route and supply no authentication, host authorization policy, CSRF issuance, signing-key store, checkpoint store, presence-token store, or identity authority; those remain host-owned. The manager reports `visual_editor_runtime=true` only for an exact attached adapter. See the [Studio editor guide](Dataphyre_Panel_Studio_Editor.md). |
| Notifications | [`PanelFilesystemNotificationAdapter`](../Framework/Notifications/PanelFilesystemNotificationAdapter.php) and [`PanelNotificationActivityStore`](../Framework/Notifications/PanelNotificationActivityStore.php) provide atomic inboxes, delivery receipts, cursors, preferences, subscriptions, watches, comments, mentions, assignments, and digests. Use `notificationAdapter()` and `notificationActivity()`. |
| Media | [`PanelMediaManager`](../Framework/Media/PanelMediaManager.php) composes a `PanelMediaDisk`, transactional `PanelSnapshotStore`, resumable checksummed uploads, fail-closed scanner/transformer pipeline, quarantine, variants, signed private delivery, cleanup, catalogue, and change feed. [`PanelDataphyreStorageMediaDisk`](../Framework/Media/PanelDataphyreStorageMediaDisk.php) routes bounded verified streams through a named Dataphyre Storage disk, confines a private prefix, redacts provider failures, and compensates failed non-atomic replacements without claiming CAS or atomic rename. Use `media()` locally or activate the optional `storage_media` first-party adapter-pack binding for connected Storage infrastructure. |
| Localization | [`PanelLocalizationRuntime`](../Framework/Localization/PanelLocalizationRuntime.php) combines file/package catalogue loading, namespaces, fallback chains, ICU messages, pluralization, number/currency/date formatting, locale metadata, and RTL attributes. Use `localization()` and `apply()` it to a `PanelInstance`. |
| Relations | [`PanelRelationWorkspace`](../Framework/Relations/PanelRelationWorkspace.php) and `PanelRelationAdapter` provide authorized bulk attach/detach/reorder/pivot commands, idempotency, breadcrumbs, history, and undo; [`RelationManager`](../Framework/Resources/RelationManager.php) also covers associate/dissociate resource operations. Use `arrayRelation()` and `relation()` or supply another adapter. |
| Preferences | [`PanelWorkspacePreferences`](../Framework/Preferences/PanelWorkspacePreferences.php) and memory/filesystem stores provide versioned appearance, density, locale/direction, saved views, recents, pins, notification settings, device overrides, conflicts, history, import/export, and change feeds. Use `preferenceStore()` and `preferences($userId, $profile, $device)`. |
| Collaboration | [`PanelCollaborationManager`](../Framework/Collaboration/PanelCollaborationManager.php) and memory/filesystem stores provide policy-guarded threads, comments, mentions, assignments, watches, subscriptions, presence leases, typing, change feeds, and hash-chained receipts. Use `collaborationStore()` and `collaboration()`. |
| Security | [`PanelSecurityPolicy`](../Framework/Security/PanelSecurityPolicy.php), context, decisions, impersonation sessions, permission simulation, and a permission-hardened hash chain with optional HMAC tamper evidence cover hierarchical permissions, tenant boundaries, MFA/trust gates, and scoped impersonation. Use `securityContext()`, `securityPolicy()`, and `securityAudit()`. |
| Extensions | [`PanelExtensionRegistry`](../Framework/Extensions/PanelExtensionRegistry.php), descriptor, runtime, and optional client assets provide dependency/version ordering, declared assets/capabilities, scoped hooks, events, and browser lifecycle hooks. Use `extensions()`, `extensionRuntime()`, `registerExtension()`, and `onExtension()`. |
| Application SDK compiler | [`PanelSdkContract`](../Framework/Sdk/PanelSdkContract.php), [`PanelSdkProtocolCatalog`](../Framework/Sdk/PanelSdkProtocolCatalog.php), [`PanelSdkGenerator`](../Framework/Sdk/PanelSdkGenerator.php), PHP/TypeScript target compilers, integrity-bound packages, and [`PanelSdkCompatibilityReport`](../Framework/Sdk/PanelSdkCompatibilityReport.php) turn explicitly supplied host routes into deterministic clients for DataSurface interactions, command dispatch, event envelopes, Studio artifacts, and custom operations. The closed schema grammar validates both sides with bounded runtime parity; compatibility is directional and enforces semantic version bumps. Use `PanelDeveloperToolkit::sdkContract()`, `sdkGenerator()`, and `sdkCompatibility()`. Panel does not register the routes, write generated files, open transport, or embed identity, CSRF, credentials, keys, retries, or telemetry. See the [application SDK guide](Dataphyre_Panel_Application_SDK.md). |
| Developer and quality | [`PanelDeveloperToolkit`](../Framework/Development/PanelDeveloperToolkit.php) exposes manifest inspection/diffs, resource blueprints, the default 144-case responsive/accessibility matrix, and CLI JSON output. Generated PHP validates namespace and class identifiers before interpolation, including schema-qualified table names; blueprint metadata is bounded JSON-only data, so objects, closures, non-finite numbers, and `__set_state` expressions cannot enter generated source. Quality matrices canonicalize associative single values and reject empty, unsafe, oversized, non-JSON, or combinatorially over-budget axes. [`PanelQualityGate`](../Framework/Testing/PanelQualityGate.php) plus optional client audits cover semantics, contrast, target size, dialogs, overflow, forced colors, and reduced motion. Use `development()` or `source-checkout-maintainer-tool`. |
| Reactor widget bridge | [`PanelReactorWidgetRuntimeAdapter`](../Framework/Bridges/Reactor/PanelReactorWidgetRuntimeAdapter.php) and [`PanelReactorWidgetController`](../Framework/Bridges/Reactor/PanelReactorWidgetController.php) map an explicitly bound Panel widget definition to one registered Reactor component with scope-bound snapshots, CAS rotation, and exact revocation. They install no route, authentication, CSRF/origin policy, version store, transport authorization, or business idempotency. See the [Reactor widget bridge guide](Dataphyre_Panel_Reactor_Widgets.md). |
| Reactor transactions | [`ReactorTransactions`](../../reactor/Framework/Transactions/ReactorTransactions.php) is a separate Reactor facade for optimistic patches, exact rollback, compare-and-swap, conflict strategies, idempotency, offline queues, retries, atomic persistence, event streams, HTTP/SSE, and browser replay. |

In distributed mode, `PanelOperationControl::recoverStale()` delegates to the
leased store's expiry recovery so lease ownership and operation state cannot
diverge. Heartbeat-timeout recovery remains the local-store fallback only;
workers also recover expired leases before reserving a requested operation.

### Panel Studio portable envelope and trusted materialization

Studio separates safe, data-only composition from executable application code.
`PanelStudioDefinition` accepts one allow-listed component tree, requires unique
sibling keys, and recursively rejects objects, resources, callables, raw HTML,
PHP, sensitive property names, embedded credential fragments, non-finite
numbers, invalid UTF-8, and values beyond its depth/item/string/document bounds.
`PanelStudioCompiler` preserves that deterministic framework-neutral portable
envelope and never emits or evaluates PHP. `PanelStudioManager::saveDraft()`
then requires the instance's typed registry and audited materializer to validate
the same definition and bind an actual-builder contract before it stores a new
draft:

```php
use Dataphyre\Panel\PanelStudioDocument;

$document=PanelStudioDocument::make(
	'tenant-7',
	'orders-workspace',
	'Orders workspace'
);

$saved=$platform->studio()->saveDraft(
	$document,
	[
		'kind'=>'page',
		'key'=>'orders',
		'properties'=>['label'=>'Orders'],
		'children'=>[
			[
				'kind'=>'table',
				'key'=>'orders-table',
				'properties'=>['density'=>'normal'],
				'children'=>[
					[
						'kind'=>'column',
						'key'=>'id',
						'properties'=>['label'=>'Order ID','sortable'=>true],
						'children'=>[],
					],
				],
			],
		],
	],
	0,                         // expected revision
	$requestIdempotencyKey,
	'operator-42'
);

$preview=$platform->studio()->preview(
	'tenant-7',
	'orders-workspace',
	$saved->revision(),
	'operator-42',
	300
);

// The token is a bearer capability. Keep it out of logs and manifests.
$token=$preview->token();

$runtime=$platform->studio()->materialize(
	'tenant-7',
	'orders-workspace',
	'operator-42',
	$saved->revision()
);

// The root is a PanelStudioPageBundle for page definitions. It can be
// registered with a PanelInstance or PanelManager at the trusted host boundary.
$runtime->root()->register($panel);
```

The default registry covers all 30 envelope kinds: page, form/section/field,
table/column/filter/view, show and first-class infolist surfaces,
infolist-entry/section, board/board-column, DataSurface,
workflow/state/transition, action/group, widget/grid, tabs, toolbar, and
navigation contracts. Properties have explicit JSON types, required/default/
nullable rules, enums, bounds, and patterns; child kinds and cardinalities are
checked with path-addressed diagnostics. Identity is sibling-scoped by default,
so two forms or tables may reuse field/column keys while duplicates inside one
parent remain invalid. Registry manifests preserve schema/provider versions and
a deterministic fingerprint. Conflicts reject by default; replacement requires
the same provider and a higher component-schema version.

The materializer uses a hard-coded callback-free mapping to Panel builders. It
does not accept user class names, PHP, callbacks, raw HTML, or stored executable
objects. `PanelStudioMaterialization` keeps runtime builder objects separate
from its JSON manifest, indexes deterministic paths/identities, and carries
`PanelStudioBuilderCollection` presentation bindings for grid, brick, and
masonry-capable filter, table-view, widget, and navigation collections.
`PanelStudioPageBundle` exposes forms, tables, DataSurface definitions,
workflow definitions, infolists, board resources, action groups, and
collections alongside its host-registerable `PanelPage`.
`registerResources()` registers board resources explicitly; `registerAll()`
can also receive an explicitly configured DataSurface registry and workflow
engine, then registers those definitions, board resources, and the page in one
host-bound operation. `registerDataSurfaces()` and `registerWorkflows()` expose
the same boundaries separately.
Studio `action` nodes create declarative `Action` builders only. They do not
install or execute operator mutation handlers; applications must bind any real
mutation behind their normal authorization, validation, CSRF, idempotency, and
audit boundary.

Studio `board` nodes materialize to real `Resource` builders and
`board_column` nodes materialize to typed `PanelStudioBoardColumn` definitions.
Lane/status metadata and brick, grid, or masonry presentation are immediately
usable as a read-only board. Studio never installs a mutation callback. To
enable moves, the trusted host retrieves the materialized resource, attaches
its normal `transitionUsing()` or `saveUsing()` handler, and registers that
derived resource:

```php
$board=$runtime->root(); // Resource when board is the definition root

// Safe without a handler: lanes and cards render, but cannot be dragged.
$panel->register($board);

// Mutation authority remains application-owned.
$panel->register($board->transitionUsing($authorizedTransitionHandler));
```

Every read or mutation crosses `PanelStudioAuthorization`; the platform default
is deny. Saving uses an expected revision and a fingerprint-bound idempotency
receipt. Approval, publication, and rollback append immutable revisions rather
than mutating prior records. When `required_publish_approvals` is positive, the
publisher cannot satisfy their own approval requirement. The history is an
ordered SHA-256 chain that detects corruption and scope/revision reordering; it
is described as tamper-evident, not as a substitute for a separately anchored
audit signature.

`PanelInMemoryStudioStore` and `PanelFilesystemStudioStore` implement the same
`PanelStudioStore` contract. A stale cursor returns a
`studio_state_envelope_v1` snapshot with `schema`, `sequence`, `committed_at`,
`payload`, and `event`. That payload is the full trusted JSON-only document
state, so expose `changesSince()` only behind the same tenant/principal host
boundary as other Studio reads. Database-backed adapters must preserve atomic
optimistic revisions, idempotent receipts, revision ordering, and the reset
envelope, then pass `PanelAdapterConformanceCatalog::studioStore()` with explicit
destructive-probe authority.

Every new revision and receipt binds a `PanelStudioArtifact`: source and
normalized definition hashes, registry version/fingerprint, compiler and
materializer versions/fingerprints, plus the symbolic builder-contract hash.
Approval and publication fail closed when the active registry or implementation
changes. Rollback copies the target publication's artifact instead of silently
recompiling it. Version-one stored revisions remain readable as
`unbound_legacy`, but cannot be approved, published, or used as rollback targets
until explicitly re-saved through the trusted manager.

The portable compiler still validates only the envelope and JSON/security
bounds; callers must not mistake `compile()` output for executable validation.
Registry contract version 3 and materializer version 3 support all 30 envelope
kinds. Their manifests report an empty `portable_only_envelope_kinds` list and
`complete_definition_kind_coverage=true`. The `show` kind remains compatible;
`infolist` now has its own executable schema identity and emits the same typed
`Infolist` builder family. Property names such as `view`, `template`, `class`,
and `html` remain forbidden.

The version change intentionally changes registry and materializer
fingerprints. Existing stored revisions remain immutable and readable, but an
artifact bound to the earlier contract fails closed as stale. Re-save the
definition through `PanelStudioManager`, repeat its normal independent approval
and signed-preview flow, then publish the newly bound revision. A formerly
portable board or infolist needs no JSON shape rewrite; the explicit re-save is
the migration boundary.
The first-party route-free `PanelStudioEditor` exposes that trusted composition
core through accessible SSR and progressive enhancement, but it does not turn
the manager into an installed application controller. The optional
`PanelStudioVisualRuntime` maps all 30 trusted kinds to actual Panel rendering
surfaces for unsaved authorized sessions, signed saved revisions, and published
revisions. It uses recursively redacted bounded JSON datasets, empty-permissions
sandboxed frames, strict byte/surface limits, stable content-free failures, and
content-bound conditional responses. DataSurface previews use the supplied
redacted preview dataset and never execute the configured source. Workflow
previews expose structural reachability and approval information without
executing guards, assignments, compensators, or mutations.
`PanelStudioManager::manifest()` reports
`visual_editor_runtime=true` only when that exact runtime is attached; the
default remains false. The root `studio_editor` manifest reports editor/runtime
availability and `routes_registered=false`. A host must still provide the
authorized action and preview endpoints, identity, CSRF, transport, and trusted
server-side checkpoint storage described in the
[Studio editor guide](Dataphyre_Panel_Studio_Editor.md).

### Tenant IAM control plane

Create mutation envelopes at the outer authorization boundary. The raw
idempotency key is hashed immediately and never appears in receipts, manifests,
audit events, or stored state:

```php
use Dataphyre\Panel\PanelIamMutation;
use Dataphyre\Panel\PanelIamPrincipal;

$iam=$platform->iam();
$principal=PanelIamPrincipal::make('user-42', 'Avery Stone', [
	'email'=>'avery@example.test',
]);

$iam->createPrincipal(
	PanelIamMutation::make(
		'principal.create',
		'tenant-7',
		'principal',
		'user-42',
		'operator-42',
		'Provision approved employee access.',
		$requestId,
	),
	$principal,
);

$iam->grant(
	PanelIamMutation::make(
		'membership.grant',
		'tenant-7',
		'principal',
		'user-42',
		'security-approver-9',
		'Approve tenant administration duties.',
		$grantRequestId,
		0,
		[
			'requester_id'=>'operator-42',
			'approver_id'=>'security-approver-9',
		],
	),
	roles:['tenant-admin'],
	permissions:['iam.membership.read'],
);

$requestIam=$iam->scope('tenant-7', 'operator-42');
$membership=$requestIam->membership('principal', 'user-42');
```

Treat service-account credentials like passwords: generate and deliver raw
material outside Panel. `rotateServiceCredential()` accepts only bounded safe
metadata such as key ID, version, algorithm, provider, state, rotation/expiry
times, and last four characters. Credential-shaped keys and values are rejected
recursively from general metadata. Stores receive one tenant state per
transaction and must commit the new record revision, receipt, and audit event
atomically. `PanelMemoryIamStore` is process-local reference behavior;
`PanelAtomicIamStore` adds locked crash-safe filesystem snapshots. Distributed
deployments should provide a database or broker implementation with equivalent
atomicity and run the IAM conformance pack in a disposable tenant before use.

### Transactional state and data migrations

Panel migrations are executable framework definitions, not install-time file
scripts. A definition owns one monotonic edge between strict semantic and state
schema versions. Its scope and tenant mode prevent a plan made for shared state
from being reused for a tenant, or the reverse. Dependencies must already be
applied or appear earlier in the same contiguous version chain.

```php
use Dataphyre\Panel\PanelMigrationBatch;
use Dataphyre\Panel\PanelMigrationContext;
use Dataphyre\Panel\PanelMigrationDefinition;
use Dataphyre\Panel\PanelMigrationVersion;

$normalizeOrders=PanelMigrationDefinition::make(
	'orders.normalize_customer_email',
	'orders',
	PanelMigrationVersion::make('2.3.0', 7),
	PanelMigrationVersion::make('2.4.0', 8),
	static function(PanelMigrationContext $context): PanelMigrationBatch {
		$state=$context->data();
		$offset=(int)($context->cursor() ?? 0);
		$limit=$context->limit();
		$end=min(count($state['orders']), $offset + $limit);

		for($index=$offset; $index<$end; $index++){
			$state['orders'][$index]['email_before_normalization']=
				$state['orders'][$index]['email'];
			$state['orders'][$index]['email']=strtolower(
				trim((string)$state['orders'][$index]['email'])
			);
		}

		return $end<count($state['orders'])
			? PanelMigrationBatch::more($state, $end, $end-$offset)
			: PanelMigrationBatch::complete($state, $end-$offset);
	},
	[
		'batch_size'=>500,
		'tenant_mode'=>'required',
		'capabilities'=>['orders.write'],
		'preflight'=>static fn(PanelMigrationContext $context): array => [
			'ok'=>isset($context->data()['orders']),
			'issues'=>['orders state is unavailable'],
		],
		'down'=>static function(PanelMigrationContext $context): PanelMigrationBatch {
			$state=$context->data();
			$offset=(int)($context->cursor() ?? 0);
			$end=min(count($state['orders']), $offset + $context->limit());

			for($index=$offset; $index<$end; $index++){
				$state['orders'][$index]['email']=
					$state['orders'][$index]['email_before_normalization'];
				unset($state['orders'][$index]['email_before_normalization']);
			}

			return $end<count($state['orders'])
				? PanelMigrationBatch::more($state, $end, $end-$offset)
				: PanelMigrationBatch::complete($state, $end-$offset);
		},
	]
);

$platform->registerMigration($normalizeOrders);
$plan=$platform->migrationPlan(
	'orders',
	$tenantId,
	PanelMigrationVersion::make('2.4.0', 8)
);

$preview=$platform->migrationRunner()->execute(
	$plan,
	$operator,
	['orders.write'],
	dryRun: true
);

$report=$platform->migrationRunner()->execute(
	$plan,
	$operator,
	['orders.write'],
	maxBatches: 100
);

if($report->status()==='running'){
	$report=$platform->migrationRunner()->resume(
		$plan,
		$operator,
		['orders.write']
	);
}
```

Planning captures the exact state revision/digest and registry digest. Execution
fails closed when either changes. A plan also carries every definition digest,
required capability, and version edge in stable order. The runner evaluates the
`panel.migrations.execute` authorization hook, capability grants, and all
preflight callbacks before acquiring a lease. `dryRun: true` never calls an up
handler or writes state. A missing authorizer denies execution and rollback;
enabling the optional platform domain without `migrations.authorize` remains
useful for inspection and planning but does not grant mutation authority.

Trusted installers and isolated maintenance processes that already enforce an
equivalent outer security boundary may explicitly clone a runner with
`allowTrustedExecution()`. The original remains default-deny, and
`manifest()['authorization']` exposes whether an authorizer or the trusted mode
is active. Do not place the trusted clone in a request container, share it with
untrusted jobs, or treat it as a replacement for tenant-aware authorization.
Rollback uses the separate `panel.migrations.rollback` ability. Execute and
rollback capability grants are normalized through the same safe identifier
contract before policy checks.

Each handler receives only one bounded batch and must return
`PanelMigrationBatch`. The adapter commits the returned state, progress cursor,
checkpoint, receipt, and new integrity digest in the same fenced transaction.
Stopping at `maxBatches` intentionally leaves a resumable run. Replaying the
same plan reuses its idempotency key and completed receipt; a failed or
lease-expired run resumes from its last committed checkpoint. Expired leases
are recovered as `paused`, and a later worker receives a larger fencing number.
Raw bearer proofs are held by the worker only and stored as SHA-256 digests.

The local adapter creates an integrity-checked pre-run backup. `rollback()`
invokes completed and partially applied `down` handlers in reverse order. When
a definition has no compensation handler, or compensation fails, the runner can
atomically restore that backup. Disable snapshot fallback only when the host has
an independently tested recovery procedure. Reports serialize status,
checkpoints, state digests, and bounded receipts; state data, actor objects, and
bearer proofs are excluded, and credential-shaped diagnostics are recursively
redacted. Checkpoints and receipts are sanitized before persistence; keep
credentials in the host's secret manager, never in a migration cursor or
checkpoint. Pre-run backups necessarily contain the migrated state, so protect
and encrypt `state_root` according to the same policy as the source database.

`PanelMigrationStore` is the adapter contract for database and broker hosts. An
implementation must provide one exclusive lease per scope/tenant, monotonic
fences, atomic handler-plus-checkpoint commits, optimistic state digests,
idempotent begin/replay, durable backups, compensation or restore, and expiry
recovery. Do not run a handler outside the transaction and persist its cursor
later: that breaks exactly the crash boundary the contract exists to protect.
Run `PanelAdapterConformanceCatalog::migrationStore()` against a disposable
namespace with `allow_destructive => true` before activation.

`PanelPdoMigrationStore` is the first-party shared-SQL implementation. Inject it
through `migrations.store`; `PanelPlatform` never creates its connection or
schema. Handler-side SQL is atomic with its checkpoint only when it uses the
exact constructor PDO connection, and handler transactions are deliberately not
replayed after ambiguous database failures. Its scope documents include current
state and restorable backups, while its global change feed remains payload-free.
See [Dataphyre_Panel_Distributed_Migrations.md](Dataphyre_Panel_Distributed_Migrations.md)
for schema rollout, topology, retention, encryption, reset, and operational
responsibilities.

Published migrations are immutable. Never change the versions, dependencies,
tenant mode, batch semantics, or handler meaning under an existing migration
ID. Add a new edge instead. To retire an unshipped or compatibility-only edge,
set `deprecated_by` and non-empty `deprecation_guidance`; manifests then expose
the replacement without executing or serializing callbacks. Re-plan after any
registry, target-version, tenant, or state change. Existing ad hoc migration
scripts should first be wrapped as a definition whose `from` version matches the
last independently verified state, dry-run in each tenant, and only then be
removed after its completed receipts and backup retention satisfy host policy.

### Typed query expressions and nested-resource scope

`PanelDataQuery` uses a versioned immutable expression tree while preserving the
historical `where()`, `orWhere()`, `filters()`, `sort()`, `filterList()`, and
`sortList()` APIs. New integrations should build explicit nodes so grouping and
nested-resource intent cannot be lost in flat arrays:

```php
use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelQueryBetween;
use Dataphyre\Panel\PanelQueryComparison;
use Dataphyre\Panel\PanelQueryGroup;
use Dataphyre\Panel\PanelQueryRelation;
use Dataphyre\Panel\PanelQuerySort;
use Dataphyre\Panel\PanelQueryUrlCodec;

$expression=PanelQueryGroup::all(
	PanelQueryComparison::make('status', 'eq', 'open'),
	PanelQueryBetween::make('total', 100, 500),
	PanelQueryRelation::make(
		'items',
		PanelQueryComparison::make('sku', 'starts_with', 'EU-')
	)
);

$query=PanelDataQuery::make()
	->whereExpression($expression)
	->sortBy(PanelQuerySort::make('created_at', 'desc', 'last'));

$queryParameters=PanelQueryUrlCodec::toQuery($query); // dp_query=<versioned state>
$restored=PanelQueryUrlCodec::fromQuery($queryParameters);
```

The public nodes are `PanelQueryGroup`, `PanelQueryComparison`,
`PanelQueryNull`, `PanelQueryBetween`, `PanelQueryIn`, and
`PanelQueryRelation`. `PanelQueryPath` validates field paths, and
`PanelQuerySort` validates direction and explicit `native`, `first`, or `last`
null placement. Their `JsonSerializable` output has a fixed key order;
`PanelQueryExpressionCodec` accepts the same manifests and produces normalized
nodes.

Every data-source query is checked against `PanelQueryCapabilities` before an
adapter callback or repository method runs. Adapters declare supported
operators, AND/OR grouping, relation depth, sorting, and null-placement modes.
An unsupported request throws `PanelUnsupportedQueryException`; adapters must
not silently drop predicates or downgrade a nested query. The built-in array,
callback, and repository adapters advertise the v2 contract. Restrictive
callback/repository adapters should override `operators`, `groups`,
`relations`, `relation_depth`, `sorts`, and `sort_nulls` in their capability
map.

Relation predicates are explicit instead of inferred from dotted field names.
When a query crosses `PanelDataSourceResourceBridge`, every relation hop must be
declared by the current `Resource`, pass `RelationManager::can('view')`, resolve
its `relatedResource()`, and pass that resource's `view_any` policy. The bridge
then injects the related resource's required tenant field into the nested
expression. Missing tenant context, unknown relations, unresolved resources,
and permission failures throw `PanelQueryScopeException` before the adapter is
called. The resulting `nested_scope` metadata is a public
`PanelQueryScopeManifest` audit DTO.

`PanelQueryUrlCodec` is transport encoding, not authorization or a signature.
Its URL state deliberately excludes tenant, authorization, metadata,
aggregates, and cursor values. The server derives protected scope from the
current `PanelRequest` and repeats capability and nested-resource authorization
checks after decoding.

For database-backed resources, `PanelSqlDataSource` and `PanelPdoDataSource`
compile this same typed tree through an immutable `PanelSqlSchema`; requests
cannot provide raw SQL, tables, columns, joins, or parameters. SQL access is
deny-by-default, tenant requirements fail closed, authorization callbacks may
return only `true`, `false`, or a typed expression, and every continuation is a
rotating-key HMAC keyset cursor bound to the effective query and security
scope. See [Dataphyre Panel SQL and PDO data sources](Dataphyre_Panel_SQL.md)
for configuration, null semantics, relation behavior, key rotation, limits,
and the real SQLite conformance gate.

For a server-owned remote service, `PanelHttpDataSource` implements the same
read contract over an exact, bounded POST/JSON protocol. An immutable definition
pins capabilities and the endpoint, a required mapper projects only approved
tenant/principal authorization scope, and local HMAC envelopes bind opaque
upstream cursors to the query, scope, definition, and key rotation. Panel does
not bundle an HTTP client, accept request-selected URLs or headers, discover
capabilities, or supply credentials, DNS/proxy/egress policy, inbound routes,
or distributed transactions. See the
[remote HTTP data-source guide](Dataphyre_Panel_HTTP_Data_Source.md).

#### Legacy filter migration

Legacy filter lists and associative maps remain accepted through Dataphyre 2.x:

```php
PanelDataQuery::fromArray([
	'filters'=>[
		['field'=>'status', 'operator'=>'eq', 'value'=>'open', 'boolean'=>'and'],
	],
]);

PanelQueryUrlCodec::legacy(['filters'=>['status'=>'open'], 'sort'=>'id']);
```

They are deprecated for new adapters as of query contract 2.0 and are planned
for removal from public URL input in 3.0. Existing fluent calls are not
deprecated: they build the same typed tree internally and still expose a flat
compatibility projection when the expression can be represented without
losing parentheses. Migrate stored URLs by decoding the legacy state once and
re-encoding it with `PanelQueryUrlCodec::encode()`.

### Vendor-neutral observability and correlation

Panel observability is instance-owned and opt-in. `PanelTelemetrySignal` defines
versioned, bounded envelopes for traces, spans, events, and measurements.
`PanelTelemetryHub` owns explicit span lifecycle, status/error recording,
deterministic trace-id sampling, current-scope correlation, exporter isolation,
and secret-free manifests. Export failures are counted and swallowed so an
unavailable monitoring backend cannot fail a Panel request or worker. Raw
exception messages, stacks, form/query values, tokens, and credentials are not
put into telemetry; errors carry type, numeric code, and a fingerprint of the
sanitized message.

```php
use Dataphyre\Panel\PanelTelemetryRuntime;

$runtime=PanelTelemetryRuntime::fromConfig([
	'exporters'=>[$otlpExporter, $auditStreamExporter],
	'sample_ratio'=>0.25,
	'sampling_seed'=>'orders-panel',
]);

$result=$runtime->bridge()->request(
	$request,
	fn($request, $context)=>$ordersController($request)
);

$operationOptions=$runtime->bridge()->operationOptions([], $runtime->hub()->current());
$instrumentedHandler=$runtime->bridge()->operationHandler($ordersExportHandler);
```

`PanelTelemetryPropagator` accepts lower/mixed-case W3C `traceparent`, validates
current and forward-compatible versions, emits version `00`, and treats an
invalid `tracestate` independently from a valid trace parent. `baggage` uses a
documented defensive subset: whole entries only, 8 KiB total, 32 entries,
bounded decoded values, valid percent encoding, duplicate removal, and removal
of credential-shaped keys or redacted values. Baggage and tracestate are
untrusted correlation hints, never identity, tenancy, policy, or authorization
inputs. Context manifests expose baggage keys/counts rather than values.

`PanelTelemetryBridge` carries the same trace across Panel requests/routes,
signed-navigation issue/verification events, durable operation metadata and
worker handlers, and Reactor transactions without retaining raw records,
tokens, transaction ids, or user payloads. `PanelInMemoryTelemetryExporter` is
bounded and useful for tests/local diagnostics. `PanelCompositeTelemetryExporter`
attempts every sink and reports only a generic aggregate failure to the hub.
Connected exporters implement `PanelTelemetryExporter`; the framework does not
bundle vendor SDKs, network credentials, or a telemetry backend.

### Production adapter conformance

`PanelAdapterConformanceRunner` executes versioned, transport-neutral behavior
contracts against production adapters without coupling the runtime to TestKit.
The first-party catalogue covers `PanelDataSource`, `PanelMediaDisk`,
`PanelMediaManager`,
`PanelOperationStore`, `PanelLeasedOperationStore`, `PanelMigrationStore`,
`PanelAgentWorkflowStore`, `PanelIamStore`, `PanelStudioStore`, and
`PanelTelemetryExporter`, `PanelNotificationAdapter`, and
`PanelSearchProvider`, including canonical query envelopes, path confinement,
checksummed stream/file lifecycles, resumable upload assembly, transactional
catalogues, signed delivery, change feeds, cleanup, idempotency, optimistic revisions,
lease renewal, fencing, stale-worker rejection, agent reservation/replay and
cancellation semantics, audit-chain integrity, migration backups, checkpoints,
completion/compensation, tenant IAM isolation/rollback/lifecycle/audit behavior,
Studio optimistic/idempotent publication, tenant isolation, hash-chain
verification, rollback and reset-feed contracts, durable notification state and
delivery receipts, bounded typed search pages, sanitized signal export/flush,
secret-free manifests, and deletion visibility.

```php
use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceRunner;

$runner=new PanelAdapterConformanceRunner();

$readReport=$runner->run(
	PanelAdapterConformanceCatalog::dataSource(),
	$productionDataSource,
	[
		'query'=>$tenantScopedProbeQuery,
		'find_scope'=>$tenantScopedProbeQuery,
		'known_id'=>$knownRecordId,
		'missing_id'=>'conformance-missing-record',
	]
);

$mediaReport=$runner->run(
	PanelAdapterConformanceCatalog::mediaDisk(),
	$productionMediaDisk,
	[
		'allow_destructive'=>true,
		'namespace'=>'panel_conformance_owned',
		'meta'=>['deployment'=>$deploymentId],
	]
);

$mediaManagerReport=$runner->run(
	PanelAdapterConformanceCatalog::mediaManager(),
	$productionMediaManager,
	[
		'allow_destructive'=>true,
		'namespace'=>'panel_conformance_media_manager',
		'meta'=>['deployment'=>$deploymentId],
	]
);

$iamReport=$runner->run(
	PanelAdapterConformanceCatalog::iamStore(),
	$productionIamStore,
	[
		'allow_destructive'=>true,
		'tenant'=>'panel-conformance-iam',
	]
);

$agentStoreReport=$runner->run(
	PanelAdapterConformanceCatalog::agentWorkflowStore(),
	$disposableProductionAgentWorkflowStore,
	['allow_destructive'=>true]
);

if(
	!$readReport->passed()
	|| !$mediaReport->passed()
	|| !$mediaManagerReport->passed()
	|| !$iamReport->passed()
	|| !$agentStoreReport->passed()
){
	throw new RuntimeException('Panel adapter conformance failed.');
}
```

Destructive cases never run unless `allow_destructive` is exactly `true`; use a
dedicated, disposable namespace and least-privileged credentials. Capability
requirements distinguish a failed required case from a visible optional skip,
and `passed()` rejects skips by default. A probe that records a failure cannot
later hide it by calling `skip()`. Probe time, evidence, and issue collections
are bounded, malformed adapters are isolated to their case, and reports redact
credential-shaped values recursively before JSON serialization. The runner
does not provision infrastructure, grant permissions, or infer that a skipped
probe passed.

The agent-workflow store interface intentionally exposes no universal deletion
primitive because silently removing replay, nonce, cancellation, or audit state
would weaken its security contract. Run its destructive conformance pack only
against an isolated disposable store or tenant/database reserved for adapter
certification, then discard that complete scope.

Hosts can compose additional immutable suites with
`PanelAdapterConformanceSuite::make()` and
`PanelAdapterConformanceCase::make()`. Keep each probe deterministic and
idempotent, declare required capabilities explicitly, mark mutations as
`destructive`, clean owned artifacts in `finally`, and give the case a bounded
`maxMillis`. Persist the JSON report as deployment evidence rather than
persisting adapter credentials or raw diagnostic payloads.

Reactor transactions can be mounted behind the host's own HTTP boundary or used
directly. The transaction payload is the same contract used for optimistic
browser application, server commit, rollback, and offline replay.

```php
use Dataphyre\Reactor\ReactorTransactions;

$transactions=ReactorTransactions::filesystem($stateRoot.'/reactor')
	->authorize(fn($transaction, $state, $version, $context): bool =>
		$context['user']?->can('update', $transaction->component()) === true
	)
	->authorizeStream(fn($component, $context): bool =>
		$context['user']?->can('view', $component) === true
	);
$result=$transactions->dispatch(
	ReactorTransactions::make('orders', $baseVersion)
		->set('selection.order_id', $orderId)
		->idempotencyKey($requestId)
		->conflictStrategy('rebase')
		->offlineCapable(),
	true,
	$verifiedSecurityContext
);

$endpoint=ReactorTransactions::endpoint($transactions)
	->validateOrigin($hostOriginValidator)
	->validateCsrf($hostCsrfValidator)
	->authorizeTransport($hostTransportAuthorizer);
```

The endpoint and coordinator deny access until these host-owned security
boundaries are installed. See the Reactor transaction security documentation for
offline tenant/user/session/version scoping, queue limits, logout purge, and SSE
cursor handling.

## Plugins

Providers are the lightest way to register definitions. Plugins are for package
features that need a stable identity, options, render hooks, widgets, resources,
theme changes, or boot-time composition.

```php
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelPlugin;

final class AuditTrailPanelPlugin implements PanelPlugin {

	public function id(): string {
		return 'audit_trail';
	}

	public function register(PanelInstance $panel): void {
		$options=$panel->pluginConfig($this->id());

		$panel->renderHook('resource.show.after', function(array $context) use ($options): string {
			if(($options['show_timeline'] ?? true)===false){
				return '';
			}
			return AuditTrailRenderer::recordTimeline($context['resource'] ?? null, $context['record'] ?? null);
		});

		$panel->registerWidget(
			$panel->widget('audit_events')
				->label('Audit events')
				->value(fn() => AuditTrailRepository::todayCount())
		);
	}

	public function extensionPermissions(): array {
		return [
			'render_hook.register',
			'component.widget_types.register',
			'extensible.macro.register',
			'theme.theme.register',
		];
	}

	public function boot(PanelInstance $panel): void {
		// Final cross-resource wiring can happen after register().
	}
}

$seller_panel->plugin(new AuditTrailPanelPlugin(), [
	'show_timeline'=>true,
]);
```

Plugin registration is idempotent per surface. Registering the same plugin id
again only merges the supplied plugin options. Plugins can be configured in
module config alongside providers:

```php
return [
	'dataphyre'=>[
		'panel'=>[
			'plugins'=>[
				AuditTrailPanelPlugin::class=>[
					'show_timeline'=>true,
				],
			],
			'surfaces'=>[
				'seller'=>[
					'plugins'=>[
						[
							'plugin'=>AuditTrailPanelPlugin::class,
							'config'=>['show_timeline'=>false],
						],
					],
				],
			],
		],
	],
];
```

Use `pluginConfig($id)`, `hasPlugin($id)`, and `pluginIds()` from a
`PanelInstance`. During rendering, `PanelConfig::pluginConfig($id)` and
`PanelConfig::pluginIds()` read the active surface context. `describe()` includes
loaded plugin ids, classes, config keys, and optional `label()`, `version()`, or
`description()` metadata. Plugin manifests also expose effective extension
permissions, contribution provenance, and the registry revision.

Plugin `register()` and `boot()` callbacks are transactional. A failure restores
the manager, surface configuration, platform attachment, component descriptors,
macros/configurators, and themes. A plugin may additionally expose a public
`unregister(PanelInstance $panel)` callback for external cleanup. Use
`unloadPlugin($id, cascade: true)` to unload a plugin and its dependants, or
`reloadPlugin($id, $replacement)` to replace it in place. Reloads must preserve
the stable id. Nested plugins are owned by their registering plugin and cannot be
silently unloaded or reloaded around that owner.

The surface replays remaining plugins from the checkpoint captured before the
first plugin. Register application-owned resources and extensions before
installing plugins when hot reload is enabled. Configure
`extension_conflict_policy` as `reject` (default), `replace`, or `keep_first`.
`replace` retains lower contribution layers, so unloading a replacement reveals
the prior descriptor again.

Extension permissions may come from `extensionPermissions()` or plugin config
`extension_permissions`. Set `strict_extension_permissions` to require an
explicit declaration. The host can narrow declarations with
`plugin_extension_permissions`, as a list, plugin-id map, or callable allow-list.
The result is the intersection of plugin and host patterns: policy cannot grant
an unrequested permission, and an empty/missing map entry denies all extension
registration.

## Instance Configuration And Macros

Panel builders are configured per `PanelInstance` by default. Two mounted
surfaces do not share component descriptors, macros, configurators, or named
themes. Static APIs outside a surface remain an explicit process-local migration
adapter for older application boot code.

```php
use Dataphyre\Panel\Field;

$sellerPanel->configureExtensions(function(): void {
	Field::configureUsing(function(Field $field): Field {
		return $field->meta(['surface'=>'seller']);
	});

	Field::macro('opsHint', function(string $hint): Field {
		return $this->help($hint)->meta(['ops_hint'=>$hint]);
	});
});

$field=$sellerPanel->field('next_step', 'select')
	->opsHint('Changes as the operator moves through the workflow.');
```

Configurators run when a builder is created with `make()` or a Panel factory
method such as `$panel->field()` or `$panel->resource()`. If a configurator
returns a new instance, that instance becomes the configured builder; otherwise
the current builder is kept. Pass `important: true` as the second argument to
run a configurator after ordinary defaults.

Macro methods are normalized the same way as resource names. A closure macro is
bound to the builder instance, so `$this` is the field, action, resource, widget,
or table object being extended. Non-closure callables receive the builder as the
first argument. Use `hasMacro()`, `flushMacros()`, and `flushConfigurators()` for
package safety and tests.

Builders returned by `$panel->field()`, `$panel->resource()`,
`$panel->widget()`, and the other instance factories retain their registry for
later macro dispatch. A static builder factory outside a surface is compatible
only when exactly one live surface owns the requested macro/configurator; Panel
throws on ambiguity instead of guessing. Prefer instance factories or
`configureExtensions()` in multi-panel applications.

`PanelComponentRegistry` is a compatibility facade. In a Panel context it reads
and writes `$panel->extensions()`; outside one it uses the legacy adapter. This
state registry is separate from the immutable dependency/asset catalog in
`Framework/Extensions`.

Custom widget, page, and relation renderers are active runtime extension points.
Register their descriptor through `PanelComponentRegistry`, then select it with
a widget `type`, page metadata `page_type`, or relation metadata
`relation_type`. `authorize`, `before_render`, and `after_render` hooks wrap the
custom renderer; normal Panel output remains the fallback when no renderer is
provided.

The extensible builders are `Resource`, `Field`, `Action`, `ActionGroup`,
`Widget`, `PanelPage`, `Schema`, `SchemaComponent`, `FormSection`, `Column`,
`TableFilter`, `TableView`, `TableSummary`, `TableGroup`, `PageTable`, `RelationManager`, and
`NavigationItem`. `PanelCommand` is the URL/client-action command descriptor used
by the command palette state.

## Workspace Experience

Panel renders a progressively enhanced workspace around the registered resources,
pages, widgets, and navigation items. The generated HTML works as regular links
and forms first. JavaScript then adds faster navigation, local preferences, and
keyboard control without changing the server-side resource definitions.

The workspace layer is URL-agnostic. It reads the links generated by the active
`url_builder`, the current page title, and the rendered navigation tree. It does
not register routes and does not require an application to expose a fixed
`/admin` path.

### AJAX updates

Generated Panel links and ordinary GET/POST forms are intercepted when the
browser supports `fetch`, `DOMParser`, and the History API. The request is sent
with `__panel_partial=fragment` and `X-Requested-With:
DataphyrePanelFragment`; the returned Panel fragment is reconciled into the
current `main.dp-panel` element so the shell, focus, scroll position, open
details, and table scroll state remain stable where possible.

The same URL remains valid without JavaScript. Exports, import templates,
external URLs, file uploads, explicit `target` links, and controls marked with
`data-dp-panel-no-ajax="1"` continue to use normal browser navigation.
Opened URLs that contain `__panel_partial=fragment` without a Dataphyre Panel
request header are treated as normal full-page requests, so copied or restored
browser URLs never display raw JSON.

Live refresh is enabled for dashboard, index, board, show, and relation
surfaces when `live_updates` is enabled. It pauses automatically when the page is
hidden, offline, inside a modal, holding unsaved form changes, carrying selected
rows, or while the user is typing. Refreshes are quiet by default: no row flash,
header glow, page fade, or loading bar is shown for background updates. Visible
update feedback is opt-in through Panel result data or configuration:

```php
Panel::configure([
	'live_updates'=>true,
	'live_update_interval_ms'=>15000,
	'content_update_flashes'=>false,
]);
```

During fragment reconciliation, Panel also refreshes the embedded command and
surface state scripts. This keeps command palette entries, navigation metadata,
theme state, and client-side surface metadata in sync after a React-like content
swap without forcing a full page redraw.

### Mobile rendering

Panel emits responsive shell styles with the generated workspace. The mobile
layer is server-owned and does not require application routes or handwritten
per-resource markup. Form grids and compound fields also use named inline-size
containers, so embedded panels, narrow modal forms, sidebars, and split panes
adapt to the width they actually receive instead of relying only on the browser
viewport. At tablet, phone, or constrained-container widths, the shell adapts by:

- stacking the header, tools, search, filters, and page actions into touch-safe
  rows
- turning tables into labelled record cards using each column label as mobile
  context
- making modals and the command palette behave like bottom sheets
- keeping bulk actions, pagination, relation managers, boards, tabs, and
  horizontal navigation usable with touch scrolling
- closing transient menus, row action popovers, column pickers, and horizontal
  navigation groups during navigation or outside taps
- preserving full links and forms when JavaScript is unavailable

Generated resources should still provide concise column labels and action labels,
because those labels become the mobile record context.

### Surface state

Every full Panel response is backed by a `PanelSurfaceState` snapshot. The
snapshot describes the rendered page rather than a single resource primitive:
title, page kind, HTTP status, request context, resource/page identity,
breadcrumbs, notifications, compact navigation state, compact command state,
chrome preferences, and the state fragments present on the page.

```php
$result=Panel::dispatch(['resource'=>'orders']);
$surface=$result->data()['surface_state'] ?? null;

$kind=$surface['kind'] ?? null;
$commands=$surface['commands']['command_count'] ?? 0;
$navigation=$surface['navigation']['entry_count'] ?? 0;
```

The same snapshot is embedded in the response as
`data-dp-panel-surface-state`, which gives reactive clients and browser tools a
single server-owned manifest to reconcile against. It intentionally stores
counts, keys, and page-level metadata instead of full records or form payloads.

### Navigation Layouts

Panel navigation is driven by `PanelNavigationState` and can render as a left
sidebar, a horizontal top navigation bar, or be hidden when the host application
provides its own shell:

```php
Panel::make('ops')
	->navigationLayout('horizontal')
	->navigationMode('edge')
	->headerMode('docked')
	->footerMode('edge')
	->stickyNavigation()
	->stickyHeader()
	->stickyFooter();

Panel::configure([
	'navigation_layout'=>'sidebar', // sidebar, horizontal, or none
	'navigation_mode'=>'floating', // floating, docked, edge, or overlay
	'header_mode'=>'floating', // floating, docked, edge, or overlay
	'footer_mode'=>'floating', // floating, docked, edge, or overlay
	'content_spacing'=>'normal', // normal, compact, or flush
	'custom_page_layout'=>'carded', // carded or flow
	'navigation_features'=>[
		'search'=>true,
		'recent'=>true,
		'pinning'=>true,
	],
	'navigation_sticky'=>false,
	'header_sticky'=>false,
	'footer_sticky'=>false,
]);
```

`contentSpacing('flush')` lets edge chrome sit directly against the browser
edge. Use it for page bodies that draw their own full-bleed shell. For custom
pages that provide their own card grid, prefer normal content spacing and pair
it with `customPageLayout('flow')` so Panel does not wrap the direct page
section in an additional card surface:

```php
Panel::surface('erp')
	->navigationLayout('sidebar')
	->navigationMode('edge')
	->headerMode('edge')
	->footerMode('docked')
	->contentSpacing('normal')
	->customPageLayout('flow')
	->stickyNavigation()
	->navigationFeatures([
		'search'=>false,
		'recent'=>false,
		'pinning'=>false,
	]);
```

The generated sidebar is derived from resources, custom pages, and host-owned
navigation items. It supports:

- a local `Find in panel` search box
- persistent collapsed/expanded navigation groups
- a persistent collapsed sidebar mode
- pinned navigation items
- recent navigation items
- nested submenus and folder-only navigation containers
- keyboard movement across visible links
- group counts and a current-location summary
- group heading links to the first visible group item when group collapse is disabled

Search, recent navigation, and pinning can be disabled independently with
`navigationSearch(false)`, `recentNavigation(false)`,
`pinnedNavigation(false)`, or the grouped `navigationFeatures()` helper.

Sidebar search is a local filter. It does not make a server request and it does
not change the current URL. While searching, collapsed groups are temporarily
revealed so matching links are discoverable.

Pinned and recent navigation are stored in `localStorage` for the current Panel
host path. Pinned links appear ahead of regular groups. Recent links appear
below pinned links and omit the current page and anything already pinned.

Navigation groups can be expanded or collapsed individually from the sidebar, or
globally from the command palette. Collapsed state is also stored locally.
Stored collapsed-group state includes the page path that wrote it. Active
navigation groups reopen from persisted state even if an older or legacy local
preference listed that group as collapsed, so the current workspace remains
readable. A user can still collapse the active group manually during the current
page session.
When a Panel disables group collapse, sidebar group headings render as ordinary
links to the first navigable item in the group, so dense grouped sidebars still
respond when a user clicks the top-level group label.
Panel's generated `panel.css` includes the navigation experience stylesheet, so
clickable sidebar group headings inherit Panel navigation typography, spacing,
hover treatment, and link reset styles instead of browser-default anchor styles.

Horizontal navigation uses the same entries and authorization rules. Groups
render as compact menus in the shell header area, while active entries still
receive `aria-current="page"` and the same state snapshot used by the sidebar.
Nested submenus render in both sidebar and horizontal modes from the same
navigation tree, so hosts do not need separate menu definitions per layout.
Horizontal menus float above the page rather than expanding the scrolling track,
which keeps opening a menu from changing page or toolbar scrollbars.

Navigation mode is separate from layout. Layout decides which navigation
structure is rendered; mode decides how that structure is attached to the
viewport:

- `floating` keeps the current card-like shell behavior.
- `docked` keeps navigation in the page flow but removes extra lift so it reads
  as part of the application frame.
- `edge` clamps the sidebar or horizontal bar to the viewport edge and removes
  outer seams.
- `overlay` reserves a mode for hosts that want navigation to sit above the
  content layer.

The current layout and mode are emitted on the root panel as
`data-dp-panel-navigation-layout` and `data-dp-panel-navigation-mode`, and also
appear in the surface and navigation manifests.

Headers and footers use the same attachment vocabulary. The generated page
header is a named chrome region with `data-dp-panel-header` and
`data-dp-panel-header-mode`; optional footers render when the host supplies
`footer`, `footer_html`, or footer render hooks, and receive
`data-dp-panel-footer` plus `data-dp-panel-footer-mode`. This keeps route
placement, shell chrome, and visual attachment independent from the resources
and pages being rendered.

Stickiness is explicit and independent of mode. `edge` can describe a chrome
region that visually touches the viewport edge, while `stickyNavigation()`,
`stickyHeader()`, and `stickyFooter()` decide whether those regions remain in
view while scrolling. The same switches are available as
`navigation_sticky`, `header_sticky`, and `footer_sticky`; aliases
`nav_sticky`, `sticky_nav`, `sticky_header`, and `sticky_footer` are accepted
for host configs that prefer shorter names.

Footer hooks are intentionally opt-in:

```php
Panel::make('ops')
	->footerMode('docked')
	->renderHook('footer', fn() => '<div class="dp-panel-footer-slim"><p>&copy; 2026 Example Corp.</p><nav><a href="/policies">Policies</a></nav></div>');
```

Panel also has an explicit page-width contract. By default, sidebar layouts use
`constrained` width and horizontal/no-navigation layouts use `fluid` width so
content does not inherit unused sidebar-era gutters. Hosts can override this
with `page_width` or `content_width`:

```php
Panel::configure([
	'navigation_layout'=>'horizontal',
	'page_width'=>'fluid', // fluid, constrained, or compact
]);
```

Navigation chrome is themeable. The renderer keeps stable structure classes for
the shell, but app themes should usually start with tokens instead of replacing
the markup. Navigation tokens are emitted as regular `--dp-*` variables and are
resolved late in the Panel stylesheet:

```php
Panel::theme('merchant_ops')
	->tokens([
		'nav_width'=>'18rem',
		'nav_shell_bg'=>'linear-gradient(180deg,#ffffff,#f7fafc)',
		'nav_shell_radius'=>'1.5rem',
		'nav_brand_bg'=>'#eef6ff',
		'nav_item_radius'=>'0.875rem',
		'nav_item_hover_bg'=>'#eef6ff',
		'nav_item_active_bg'=>'linear-gradient(135deg,#2563eb,#0891b2)',
		'nav_icon_bg'=>'#e2e8f0',
		'nav_submenu_rail'=>'#cbd5e1',
	])
	->darkTokens([
		'nav_shell_bg'=>'linear-gradient(180deg,#111827,#0b1220)',
		'nav_brand_bg'=>'#172033',
		'nav_item_hover_bg'=>'#1f2937',
	]);
```

Useful navigation tokens include `nav_width`, `nav_gap`, `nav_shell_bg`,
`nav_shell_border`, `nav_shell_radius`, `nav_shell_padding`,
`nav_shell_shadow`, `nav_brand_bg`, `nav_brand_border`, `nav_search_bg`,
`nav_current_bg`, `nav_current_border`, `nav_section_gap`,
`nav_section_border`, `nav_section_label`, `nav_section_active_rail`,
`nav_item_bg`, `nav_item_hover_bg`, `nav_item_active_bg`,
`nav_item_active_color`, `nav_item_border`, `nav_item_radius`,
`nav_item_height`, `nav_item_padding`, `nav_icon_bg`, `nav_icon_color`,
`nav_icon_active_bg`, `nav_icon_active_color`, `nav_badge_bg`,
`nav_badge_color`, `nav_submenu_indent`, and `nav_submenu_rail`.

Themes can still ship CSS assets when they need a radically different menu,
for example a branded rail, a compact icon strip, or tenant-specific folder
treatments. Theme CSS is loaded after the generated core stylesheet, and the
stable shell selectors are `.dp-panel-sidebar`, `.dp-panel-sidebar-brand`,
`.dp-panel-sidebar-search`, `.dp-panel-sidebar-context`,
`.dp-panel-sidebar-nav`, `.dp-panel-sidebar-group`,
`.dp-panel-sidebar-link`, `.dp-panel-sidebar-submenu`, and
`.dp-panel-horizontal-nav`.

### Table header controls

Generated resource tables can place compact table controls in the metadata row
with `Panel::tableHeaderControls('compact')` or
`Panel::surface(...)->tableHeaderControls('compact')`. In compact mode the
metadata row owns search, filter launchers, and the create action while the
lower commandbar remains available for extra resource actions. This keeps
common list work close to the record count and page size controls without
requiring per-resource markup.

The compact header layout is responsive. Search keeps normal input padding,
filters stay in the same control group, and the create action drops below the
search field before it can crowd the query input on narrower surfaces. The
resource table empty state remains configurable through `emptyState()` and
`filteredEmptyState()`, so product modules can provide domain-specific empty
copy instead of using the generic generated message.

### Saved views

Generated resource tables include a small table metadata row with record count,
page count, and saved-view controls. A saved view stores the current table URL,
including search, filters, table view, sort, page size, density, and visible
column state. Saved views are local to the browser and the current Panel page.

The saved-view menu can:

- save the current table URL with a label
- remove the current saved view
- jump to any saved view
- copy saved views as JSON
- import saved views from JSON

Saved views are convenience state only. They do not change resource definitions,
authorization, filters, or query handlers.

### Workspace snapshots

The saved-view menu and command palette can copy or restore a workspace
snapshot. A snapshot is JSON containing the current URL, saved views, and local
Panel preferences such as theme mode, sidebar collapse state, live-update pause
state, pinned navigation, recent navigation, collapsed navigation groups, and
client-side column widths.

Snapshots are intended for operators moving their workspace between browsers or
for support/debug handoff. Restoring a snapshot only writes keys beginning with
`dataphyre_panel_` into local storage. If the snapshot contains a URL on the same
origin, the user is asked before navigating to it.

Sidebar panels with docked footers render the main region as a column flex
container. The footer remains in normal document flow, uses `margin-top:auto` to
sit at the viewport bottom on shallow pages, and expands through the main
region's right padding without becoming sticky.

### Keyboard shortcuts

Panel exposes a command palette with `Ctrl+K` or `Cmd+K`. The palette includes
commands, visible navigation links, recent pages, pinned pages, saved views,
actions, filters, column controls, pagination links, breadcrumbs, and table
utilities.

Server-owned commands can be registered independently of routes:

```php
Panel::registerCommand(
	Panel::command('open_billing')
		->label('Open billing')
		->group('Operations')
		->description('Review invoices and billing status')
		->icon('credit-card')
		->url('/billing')
		->keywords(['invoice', 'finance'])
);
```

Every page exposes a `PanelCommandState` snapshot in `PanelPageResult::data()` as
`command_state`, and embeds the same state for the command palette. The snapshot
contains registered commands, built-in workspace commands, visible navigation
entries, and resource-level commands such as create/import/board when the
resource and current policy allow them:

```php
$state=Panel::commandState(PanelRequest::fromArray(['resource'=>'orders']));
$commands=$state->commands();
$matches=Panel::commandState(null, 'import')->matched();
```

The palette still augments this server state with local-only browser commands
such as focused row actions, saved views, pinned navigation, and selected-row
copy commands.

Core shortcuts:

| Shortcut | Behavior |
| --- | --- |
| `Ctrl+K` / `Cmd+K` | Open the command palette. |
| `/` | Focus the current table or global search. |
| `N` | Focus sidebar navigation search. |
| `F` | Toggle the current filter panel when one exists. |
| `C` | Open column controls when a table supports them. |
| `?` | Open the keyboard shortcut reference. |
| `Esc` | Close transient panels and menus. |

Sidebar shortcuts:

| Shortcut | Behavior |
| --- | --- |
| `ArrowDown` / `ArrowUp` | Move through visible sidebar links. |
| `Home` / `End` | Jump to the first or last visible sidebar link. |
| `Enter` in sidebar search | Open the first visible match. |
| `Esc` in sidebar search | Clear the sidebar search. |

Table shortcuts:

| Shortcut | Behavior |
| --- | --- |
| `ArrowDown` / `ArrowUp` | Move focused table row. |
| `Home` / `End` | Jump to the first or last visible table row. |
| `Enter` | Open the focused row's primary link. |
| `Space` | Toggle focused row selection when selection is available. |
| `P` | Open a preview modal for the focused row. |
| `A` | Select visible rows. |
| `X` | Invert visible row selection. |

### Client-side table tools

Table headers can be resized by dragging their right edge. Double-clicking the
edge resets that column's local width. Widths are stored per host path, heading,
and table label.

Focused rows can be previewed in a modal. By default the preview uses visible
table cells, keeps row actions available, and can copy the focused row as JSON
or CSV. Resources can opt into an explicit Preview row action and can provide
server-defined preview fields when the visible columns are not the best summary:

```php
$resource
	->previewFields([
		'number',
		'customer',
		'total'=>fn(array $order): string => money($order['total']),
		['label'=>'Handling note', 'value'=>fn(array $order): string => $order['note'] ?? 'None'],
	])
	->previewAction();
```

`previewFields()` accepts field names, label/value arrays, associative labels,
and callbacks. Callback values receive the record, request, resource, and table.
The command palette can also copy selected rows or visible rows as CSV/JSON.

Applications may configure providers for the default surface and for named
surfaces, then explicitly boot those definitions when needed.

```php
return [
	'dataphyre'=>[
		'panel'=>[
			'providers'=>[
				CoreOperationsPanelProvider::class,
			],
			'plugins'=>[
				AuditTrailPanelPlugin::class=>[
					'show_timeline'=>true,
				],
			],
			'surfaces'=>[
				'seller'=>[
					'panel_label'=>'Seller Console',
					'url_builder'=>'seller_console_url',
					'providers'=>[
						CommercePanelProvider::class,
					],
					'plugins'=>[
						AuditTrailPanelPlugin::class,
					],
				],
			],
		],
	],
];

Panel::bootConfigured();
```

## Resource Definitions

Resources describe the application object, its form, its table view, and the
actions users can take.

```php
use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelConfig;
use Dataphyre\Panel\PanelHost;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelPlugin;
use Dataphyre\Panel\PanelProvider;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelResponseEmitter;
use Dataphyre\Panel\Resource;

Panel::register(
	Panel::resource('projects')
		->label('Project')
		->pluralLabel('Projects')
		->table('datadoc.projects')
		->group('Documentation')
		->icon('folder')
		->navigationDescription('Project docs and source roots')
		->navigationBadge(fn() => ProjectRepository::pendingCount())
		->navigationBadgeTone('warning')
		->recordKeyUsing('name')
		->recordTitleUsing('title')
		->recordSubtitleUsing('path')
		->formColumns(2)
		->fields([
			Panel::field('name')->required()->section('Identity'),
			Panel::field('title')->required()->section('Identity'),
			Panel::field('path')->required()->placeholder('C:/path/to/project')->section('Storage')->columnSpan('full'),
		])
		->columns([
			Panel::column('title')->sortable()->searchable(),
			Panel::column('name')->sortable(),
			Panel::column('path')->searchable(),
			Panel::column('updated_at')->datetime()->hiddenByDefault(),
			Panel::column('id')->toggleable(false),
			Panel::column('created_at')->datetime('Y-m-d H:i'),
			Panel::column('total')->money('CAD'),
		])
		->globalSearchable()
		->globalSearchColumns(['title', 'name', 'path'])
		->views([
			Panel::view('drafts')
				->label('Drafts')
				->tone('warning')
				->where(fn($record) => ($record['status'] ?? null)==='draft'),
			Panel::view('published')
				->label('Published')
				->tone('success')
				->where(fn($record) => ($record['status'] ?? null)==='published'),
		])
		->filters([
			Panel::filter('status', 'select')
				->options(['draft'=>'Draft', 'published'=>'Published']),
			Panel::filter('enabled', 'boolean'),
		])
		->summaries([
			Panel::summary('projects')->count(),
			Panel::summary('gross_total')->sum('total')->money('CAD'),
		])
		->defaultSort('created_at', 'desc')
		->perPageOptions([10, 25, 50, 100])
		->perPage(25)
		->relation(
			Panel::relation('documents')
				->label('Documents')
				->columns([
					Panel::column('title')->searchable(),
					Panel::column('updated_at', 'datetime'),
				])
				->queryUsing(fn($project) => ProjectDocumentRepository::forProject($project))
		)
		->action(
			Panel::action('scan')
				->label('Scan')
				->tone('primary')
		)
);

Panel::registerPage(
	Panel::page('imports')
		->label('Imports')
		->group('Operations')
		->icon('upload')
		->navigationDescription('Review pending imports')
		->navigationBadge('New')
		->navigationBadgeTone('info')
		->renderUsing(function(PanelRequest $request){
			return '<section><h2>Import queue</h2><p>Review pending imports before they touch production data.</p></section>';
		})
		->widget(
			Panel::stat('pending_imports', fn() => ImportRepository::pendingCount())
				->label('Pending imports')
				->tone('warning')
		)
		->table(
			Panel::pageTable('recent_imports')
				->label('Recent imports')
				->description('Latest supplier files and their current status')
				->columns([
					Panel::column('filename'),
					Panel::column('status')->badge([
						'completed'=>'success',
						'failed'=>'danger',
						'pending'=>'warning',
					]),
					Panel::column('created_at')->datetime(),
				])
				->recordsUsing(fn() => ImportRepository::recent(10))
		)
		->action(
			Panel::action('refresh')
				->label('Refresh queue')
				->tone('primary')
				->handle(fn() => ['message'=>'Import queue refreshed.'])
		)
);
```

## Current Surface

- `Panel::resource()` creates a `Resource`.
- `Panel::make()` creates an isolated panel instance with its own manager and
  configuration context.
- `Panel::surface()` retrieves or creates a named surface in the process-local
  panel registry.
- `Panel::registerSurface()` stores an existing panel instance in the named
  registry.
- `Panel::surfaces()` returns registered panel instances keyed by surface name.
- `Panel::bootConfigured()` applies configured providers to the default surface
  and configured named surfaces.
- `Panel::default()` creates an instance wrapper around the process-local default
  panel manager.
- `Panel::configure()` creates an isolated panel instance with only configuration
  overrides.
- `Panel::provide()` applies a provider to the default panel manager.
- `Panel::host()` creates a host adapter for dispatching or emitting a chosen
  surface.
- `Panel::theme()` creates or returns the default theme definition.
- `Panel::palette()` expands a named palette or hex color into Panel theme
  shades.
- `Panel::themePreset()` returns a reusable theme preset definition.
- `Panel::registerThemePreset()` adds a reusable preset recipe.
- `Panel::registerTheme()`, `Panel::namedTheme()`, and `Panel::loadThemes()`
  add and retrieve complete named themes from packages or app directories.
- `Panel::loadThemePresets()` is retained as an alias for preset/theme package
  loading.
- `Panel::page()` creates a custom Panel page definition.
- `Panel::navigationItem()` creates a first-class navigation entry for a
  host-owned URL or external destination.
- `Panel::nav()` is a short alias for `Panel::navigationItem()`.
- `Panel::field()` creates a form field definition.
- `Panel::schema()` creates a form or infolist schema.
- `Panel::schemaTab()` creates a tab component for schemas.
- `Panel::schemaStep()` creates a wizard step component for schemas.
- `Panel::column()` creates a table column definition.
- `Panel::pageTable()` creates a focused table section for a custom page.
- `Panel::pageFilter()` creates a table filter intended for a page table.
- `Panel::view()` creates a generated table view definition.
- `Panel::filter()` creates a table filter definition.
- `Panel::summary()` creates a generated table summary definition.
- `Panel::action()` creates an action definition.
- `Panel::actionGroup()` creates a dropdown group for resource, record, bulk,
  or page actions.
- `Panel::relation()` creates a relation manager definition.
- `Panel::widget()` creates a dashboard widget definition.
- `Panel::pageWidget()` is an alias for widget definitions intended for custom
  pages.
- `Panel::stat()` creates a dashboard stat widget definition.
- `Panel::notify()` creates a notification payload.
- `Panel::notificationInbox()` creates an adapter-ready notification inbox
  manifest.
- `Panel::inboxNotification()` promotes a toast payload, array, or string into a
  durable notification record.
- `Panel::register()` stores a resource in the process-local default panel
  manager.
- `Panel::registerPage()` stores a custom page in the process-local panel
  manager.
- `Panel::registerWidget()` stores a widget in the process-local panel manager.
- `Panel::registerNavigationItem()` stores a host-owned navigation entry in the
  process-local panel manager.
- `Panel::registerNavigationItems()` stores multiple host-owned navigation
  entries.
- `Panel::registerCommand()` stores a server-owned command palette entry.
- `Panel::registerCommands()` stores multiple server-owned command palette
  entries.
- `Panel::navigationLayout()` sets the default surface navigation chrome to
  `sidebar`, `horizontal`, or `none`.
- `Panel::navigationMode()`, `Panel::headerMode()`, and `Panel::footerMode()`
  choose whether Panel chrome floats, docks, attaches to the edge, or overlays.
- `Panel::contentSpacing()` controls the page spacing density with `normal`,
  `compact`, or `flush`.
- `Panel::customPageLayout()` controls direct custom-page section treatment with
  `carded` or `flow`.

## Lightweight Page Templates

`PanelPageTemplate` renders structured custom-page sections such as `stats`,
`action_list`, `description_list`, `color_swatches`, `table`, `form`, `notice`, `chat`, `confidential_fields`, and `document_content` into
escaped Panel HTML. Table rows accept scalar cell values for plain text and
`actions` or `form_actions` descriptors for row actions. POST action cells emit
the MVC session CSRF token by default and also include any configured
`hidden_fields`, either as keyed scalar values or `{name, value}` rows, so native
custom pages can submit record identifiers without raw HTML. Empty array entries
are ignored, which lets custom pages include optional sections without rendering
blank fallback cards when the condition is not active.

Table sections may provide a `pagination` descriptor with authorized
`previous_url` and `next_url` values, `item_count`, optional `current_page`, and
optional labels or summary copy. The template renders the same responsive
`dp-panel-pagination` controls used by generated Panel tables, keeps unavailable
directions visible but disabled to prevent layout movement, and rejects unsafe
URL schemes. Data sources remain responsible for cursor validation, tenant
scope, ordering, and query limits.

`description_list` renders label/value/detail facts as carded rows with explicit
block flow for labels, values, and help text. Labels and values wrap inside their
item instead of relying on inline browser flow, so compact document metadata,
settings summaries, and profile facts remain readable in light, dark, desktop,
and mobile layouts.

`color_swatches` renders labeled palette values as accessible swatch rows. Items
accept `label`, `value`, and optional `detail`; valid shorthand and six-digit hex
values are normalized before being used as CSS custom properties, and invalid
values render as text-only rows. Use this for brand, theme, status, or design
token previews when a visual color sample is more useful than raw hex text.

`record_list` renders title/subtitle/value summaries as stacked record rows.
The row title and subtitle are block-level text inside the primary column, and
the optional value renders as a compact status badge. Rows wrap on mobile and
keep long labels inside the row instead of collapsing adjacent strings together.

`confidential_fields` renders auditable disclosure rows for privacy-sensitive
values. Each item includes `label`, `placeholder`, optional `value`,
`revealed`, optional `meta`, and an optional `action` descriptor. Hidden rows
show the placeholder and a CSRF-protected access request action; revealed rows
show the supplied value. An action may include template `fields` for purpose or
reason capture before submit, plus `hidden_fields` for stable identifiers. The
template does not decide policy or write audit logs; the owning Panel or MVC
handler performs those checks before setting `revealed=true`.

`chat` renders a two-pane messaging workspace with a conversation rail, active
thread header, message bubbles, realtime state placeholder, scrollable message
area, and sticky composer. Native POST composer submits are treated as intentional
navigation, so the dirty-form guard clears before the browser unloads instead of
warning the operator that the message body is unsaved.

Form sections accept `compact=>true` for utility forms that should sit beside
their heading on desktop and collapse back to normal single-column flow on
mobile. Compact forms keep the same CSRF, hidden-field, validation, and submit
lifecycle behavior as standard template forms.
- `Panel::navigationFeatures()` configures optional sidebar search, recent
  navigation, and pinning controls.
- `Panel::authorize()` registers a gate for the default generated Panel surface.
- `Panel::accessPermissions()` / `Panel::permissions()` registers the optional
  Dataphyre Permission bridge for semantic resource, action, and relation
  checks.
- `Panel::permissionAdmin()` registers the optional Permission roles,
  assignments, and catalog resources when the Permission module is installed.
- `Panel::navigation()` returns the visible navigation entries for a request.
- `Panel::navigationState()` returns the normalized navigation, grouping,
  active-entry, and search discovery snapshot for a request.
- `Panel::commands()` returns the generated command entries for a request.
- `Panel::commandState()` returns the normalized command palette snapshot for a
  request.
- `Panel::describe()` returns a serializable resource and navigation manifest.
- `Panel::render()` renders a resource page without reading the current request.
- `Panel::dispatch()` handles a captured or explicit `PanelRequest`.
- `Panel::globalSearch()` searches registered opt-in resources.
- `Panel::trace()` and `Panel::traceSummary()` expose lifecycle events for
  Flightdeck and test assertions.
- `Panel::accessibilityAudit()` runs a route-free baseline accessibility audit
  over generated Panel HTML.
- `Panel::regressionSuite()` creates a named route-free regression suite with
  manifest-backed check results.
- `Panel::documentationCatalog()` creates a structured API reference and
  cookbook catalog.
- `Panel::documentationEntry()` creates a single categorized documentation
  entry with status, API references, examples, links, tags, and metadata.
- `Panel::localization()` gets or configures the default panel localization
  catalogue; `PanelInstance::localization()` does the same for a named surface.
- `Panel::trans()` / `Panel::t()` translate a scoped key with interpolation
  through the default surface catalogue.
- `Panel::packageManifest()` creates a package manifest for a plugin, theme,
  adapter, or package array.
- `Panel::compatibilityMatrix()` evaluates package requirements against a
  runtime snapshot.
- `Panel::packageTemplate()` creates a package starter artifact manifest without
  writing files by default.
- `Panel::packageRepository()` discovers package manifests and emits a
  deterministic package lock.
- `Panel::packageTrustPolicy()` creates a host trust policy for package
  signatures, publishers, keys, statuses, and revocations.
- `Panel::packageSignatureVerifier()` creates a host-keyed Ed25519, RSA-SHA256,
  or ECDSA-SHA256 verifier for the complete canonical artifact bundle.
- `Panel::packageInstallPlan()` creates an installer plan and can apply package
  template artifacts with signature gating, dry-run, atomic recovery, locking,
  overwrite, backup, and blocked-file metadata.
- `Panel::packageRollbackPlan()` creates a rollback plan from an install plan or
  concrete apply result; concrete results can be executed transactionally.

The first implementation focuses on resource metadata, authorization hooks,
query factories, save handlers, action handlers, and generated HTML backed by the
Templating framework when the templating kernel is loaded.

Generated pages include breadcrumbs derived from the current dashboard, resource,
record, relation, action, or custom page. The same trail is exposed as
`breadcrumbs` in `PanelPageResult::data()` for custom emitters and tests.

## Themes

Themes are route-free panel configuration. A theme belongs to the active panel
surface or to the default panel manager; the renderer reads it from
`PanelContext` and emits CSS variables, optional external stylesheets, favicon,
brand assets, and dark-mode metadata with the response.

```php
Panel::theme('operations')
	->preset('flat_minima')
	->colors([
		'primary'=>'#2563eb',
		'success'=>'emerald',
		'warning'=>'amber',
		'danger'=>'rose',
		'info'=>'sky',
		'gray'=>'zinc',
	])
	->font('Inter')
	->darkMode()
	->defaultMode('system')
	->darkBody('#0f172a')
	->darkSurface('#1e293b', '#111827')
	->darkText('#f8fafc', '#cbd5e1', '#94a3b8')
	->maxWidth('1440px')
	->panelPadding('32px')
	->controlPadding('10px 14px')
	->brandName('Operations')
	->brandLogo('/assets/panel/logo.svg')
	->darkModeBrandLogo('/assets/panel/logo-dark.svg')
	->brandLogoHeight('2rem')
	->favicon('/favicon.ico')
	->assetRoot('ops', '/assets/panel')
	->css('/assets/panel/operations.css')
	->stylesheet('ops::forms.css', 'forms', ['media'=>'screen']);
```

Isolated surfaces can carry their own theme without affecting another panel:

```php
$support=Panel::make('support')
	->label('Support')
	->useTheme(
		Panel::themePreset('glass')
			->colors(['primary'=>'teal'])
			->font('Inter')
	);
```

Colors are registered as semantic palettes rather than one-off component
values. The built-in semantic colors are `primary`, `success`, `warning`,
`danger`, `info`, and `gray`. A color may be a named palette, a hex color that
Dataphyre expands into shades, or an explicit shade map using `50`, `100`,
`200`, `300`, `400`, `500`, `600`, `700`, `800`, `900`, and `950`.

`Panel::palette()` returns the same generated shade map for reuse in config or
tests:

```php
Panel::theme()->colors([
	'primary'=>Panel::palette('#2563eb'),
]);
```

Presets are composable recipes. Built-in presets include `flat_minima`, `glass`,
and `brutalist`. A preset can be converted into a theme, applied to an existing
theme, or serialized as a manifest:

```php
$theme=Panel::themePreset('flat_minima')->toTheme('operations');

Panel::theme()
	->preset('flat_minima')
	->colors(['primary'=>'blue']);

Panel::theme('studio')
	->preset('glass')
	->colors(['primary'=>'sky']);

Panel::theme('warehouse')
	->preset('brutalist')
	->colors(['primary'=>'blue']);

Panel::configure([
	'theme'=>[
		'name'=>'ops',
		'presets'=>['flat_minima'],
		'colors'=>['primary'=>'blue'],
	],
]);

$manifest=Panel::theme()->manifest();
$css=Panel::theme()->toCss();
Panel::theme()->exportTo(__DIR__.'/build/panel', 'operations');
```

`manifest()`, `toJson()`, and `toCss()` expose the generated tokens for package
installers, tests, and deployment builds. `writeManifest()`, `writeCss()`, and
`exportTo()` write the same artifacts to disk without requiring a renderer or
route context.

Packages and apps can publish preset and theme files and load them before a
panel is rendered. JSON files may contain a single preset, a single typed theme,
a list of presets, a `presets` array, a `themes` array, or both arrays. PHP
files should return a preset, theme, array definition, or list of definitions:

```php
Panel::loadThemes(__DIR__.'/panel-themes');

Panel::registerThemePreset([
	'name'=>'merchant_ops',
	'colors'=>['primary'=>'emerald'],
	'tokens'=>['radius'=>'7px'],
]);

Panel::registerTheme([
	'type'=>'theme',
	'name'=>'merchant_full',
	'presets'=>['flat_minima'],
	'colors'=>['primary'=>'emerald'],
	'dark_tokens'=>['body_bg'=>'#052e2b'],
]);

Panel::registerTheme([
	'type'=>'theme',
	'name'=>'merchant_audit',
	'extends'=>'merchant_full',
	'tokens'=>['max_width'=>'1600px'],
	'colors'=>['warning'=>'orange'],
]);

Panel::theme()->preset('merchant_ops');
Panel::theme('merchant_audit');

$dense_review=Panel::themeVariant('dense_review', [
	'tokens'=>[
		'max_width'=>'1500px',
		'table_cell_padding'=>'7px 9px',
	],
	'colors'=>['primary'=>'indigo'],
]);

Panel::themeLibrary()->exportTo(__DIR__.'/build/panel-themes');

$diagnostics=Panel::themeDiagnostics();
$preview=Panel::themePreview('merchant_audit');
$preview_html=Panel::themePreviewHtml('merchant_audit');
```

`extends`, `base_theme`, and `base` may reference a registered complete theme,
a preset name, a theme definition, or a list of those values. Bases are applied
first, then local roots, presets, colors, tokens, assets, and brand options are
applied as normal overrides. Theme packages are resolved lazily, so a theme may
extend another theme that appears later in the same manifest. Cyclic references
are skipped during resolution.

`copy()`, `with()`, and `variant()` create derived themes without mutating the
registered theme package. `Panel::themeVariant()` derives from the active panel
theme, which is useful for a surface that needs denser tables, a wider canvas,
or a temporary color adjustment while keeping the shared theme unchanged.

## Schemas

Panel schemas are route-free component trees. They give forms, action forms,
filters, infolists, and custom page regions a shared foundation without binding
the framework to an `/admin` URL or a single renderer.

```php
$product_schema=Panel::schema()
	->columns(2)
	->section(
		Panel::section('Basics')->description('Core catalogue details.'),
		[
			Panel::field('title')->required()->columnSpan('full'),
			Panel::field('sku')->required(),
			Panel::field('status', 'select')->options([
				'draft'=>'Draft',
				'active'=>'Active',
			]),
		]
	)
	->section('Pricing', [
		Panel::field('price_minor', 'integer')->required()->rules('min:0'),
		Panel::field('compare_at_price_minor', 'integer')->rules('min:0'),
		Panel::field('currency', 'select')->options(['CAD'=>'CAD', 'USD'=>'USD']),
	])
	->tab('Publishing', [
		Panel::schemaSection(
			Panel::section('Visibility')->description('Where this record appears.'),
			[
				Panel::field('published_at', 'datetime'),
			]
		),
	])
	->step('Review', [
		Panel::field('review_note', 'textarea')->columnSpan('full'),
	]);

Panel::resource('products')
	->schema($product_schema)
	->infolist(Panel::infolist()
		->columns(3)
		->section('Snapshot', [
			Panel::entry('title')->copyable()->columnSpan('full'),
			Panel::entry('status', 'select')->badge([
				'draft'=>'warning',
				'active'=>'success',
			])->options([
				'draft'=>'Draft',
				'active'=>'Active',
			]),
			Panel::entry('price_minor', 'integer')
				->displayUsing(fn($value) => 'CAD '.number_format(((int)$value)/100, 2))
				->icon('wallet'),
		])
	)
	->bulkSchema(Panel::schema([
		Panel::field('status', 'select')->options(['draft'=>'Draft', 'active'=>'Active']),
	]));

Panel::action('approve')
	->schema(Panel::schema([
		Panel::field('note', 'textarea')->columnSpan('full'),
	]));
```

`ResourceForm::schema()` converts existing form fields and sections into a
schema without losing field callbacks. Passing a schema back into a form replaces
the form's fields, sections, metadata, and column count. This lets older
`fields()` code and newer schema code coexist while the shared schema engine
expands.

`Panel::infolist()` returns a first-class `Infolist` builder for read-only
record presentation. It compiles to the shared schema engine, so show pages,
modals, manifests, tests, and Flightdeck can inspect the same entry tree without
requiring the renderer to be the only source of truth. Resource show pages use
the resource infolist when one is defined and fall back to the form schema when
it is not, so existing resources keep working while richer record views can
diverge from edit forms.

`Panel::entry()`, `Panel::textEntry()`, `Panel::badgeEntry()`, and
`Panel::imageEntry()` create `InfolistEntry` objects. Entries are the read-only
companions to fields: they can use the same labels, options, visibility rules,
display callbacks, sections, tabs, steps, and responsive grid spans as form
fields, while adding record-display presentation helpers:

```php
Panel::entry('order_number')->label('Order')->copyable()->icon('hash');
Panel::entry('status')->badge(['paid'=>'success', 'review'=>'warning']);
Panel::entry('total_minor', 'integer')
	->displayUsing(fn($value) => 'CAD '.number_format(((int)$value)/100, 2))
	->icon('wallet');
Panel::entry('notes')->emptyLabel('No notes yet')->description('Internal team context.');
```

The fluent builder can be used without route or resource assumptions:

```php
$infolist=Panel::infolist()
	->section('Identity', [
		Panel::textEntry('number')->label('Order')->copyable()->icon('hash'),
		Panel::textEntry('customer')->icon('user'),
	])
	->section('Operations', [
		Panel::badgeEntry('status', ['review'=>'warning', 'paid'=>'primary']),
		Panel::badgeEntry('risk', ['low'=>'success', 'critical'=>'danger']),
	])
	->columns(['default'=>1, 'md'=>6, 'xl'=>12]);

Panel::resource('orders')->infolist($infolist);
```

Generated show pages render badge entries with theme tones, boolean entries as
state pills, email and URL entries as links, image entries as media previews,
repeaters as compact lists, and copyable entries with a one-click clipboard
control. `displayUsing()` still owns the resolved value, so resources can keep
formatting logic close to the schema without making the show page route-aware.

Copyable entries reserve a dedicated logical-inline-end action column. The Copy
control remains a 44px touch target without increasing the card above sibling
entries, and the value column wraps independently, including long unbroken
identifiers. The column mirrors automatically in RTL and does not rely on a
card-wide decorative overlay, so theme effects cannot turn the copy affordance
into a tinted or unreadable region.

### Safe HTML and trusted markup

Panel treats HTML rendering intent and markup trust as separate contracts.
`html(true)` and `htmlContent($string)` remain compatible rich-text APIs, but an
ordinary string is always passed through the strict server-side DOM allow list.
The sanitizer removes scriptable elements, event and style attributes, unsafe
URL schemes, SVG/MathML, embedded documents, form controls, and markup that
escapes its parse root. If DOM sanitization is unavailable or rejects the input,
Panel escapes the entire value. A boolean `html` flag can therefore never make a
record, request, translation, or remote-service string executable.

Use `PanelSafeHtml::sanitize()` when application code wants to establish the
sanitized boundary before handing rich text to a field or entry:

```php
use Dataphyre\Panel\PanelSafeHtml;

$safeNotes=PanelSafeHtml::sanitize($record['operator_notes']);

Panel::entry('operator_notes')
	->displayUsing(fn() => $safeNotes);

Panel::field('preview', 'display_only')
	->htmlContent($safeNotes);
```

Markup generated entirely by trusted framework or application code can opt in
to the sharper `PanelSafeHtml::trusted()` boundary. Every external value must be
escaped before interpolation:

```php
$statusHtml=PanelSafeHtml::trusted(
	'<span class="app-status">'.htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>'
);

Panel::entry('status')->displayUsing(fn() => $statusHtml);
Panel::entry('framework_note')->trustedHtml('<span class="app-note">Generated by the application</span>');
Panel::field('framework_preview', 'display_only')->trustedHtmlContent($statusHtml);
```

Never wrap raw user or record data in `trusted()`, `trustedHtml()`, or
`trustedHtmlContent()`. `PanelSafeHtml` JSON-serializes as a plain string on
purpose, so trust does not survive persistence, manifests, queues, HTTP, or
other transport boundaries. Receiving code must sanitize the string or make a
new, explicit trusted-code decision. Display-field component manifests retain
the broad `safe_html` capability for compatibility and add `sanitized_html` or
`trusted_html` when the fixed content source is known in process.

`Resource::infolistState()` returns the same server-owned snapshot used by the
generated show page:

```php
$state=$resource->infolistState($record, $request);

foreach($state->visibleSections() as $section => $entries){
	foreach($entries as $entry){
		$name=$entry['name'];
		$display=$entry['display'];
	}
}
```

`PanelInfolistState` carries the infolist schema, visible entries, sections,
record identity, raw values, display values, entry metadata, and copy/media/badge
hints. This makes detail surfaces reusable by custom pages, modals, tests, and
Flightdeck without asking the renderer to be the only source of truth.

`Schema::tab()` and `Panel::schemaTab()` group fields or sections into generated
tabs. Tabs are preserved when a schema is flattened into a resource form by
carrying tab metadata on the resulting fields and sections, so the same schema
continues to work for action forms, record forms, and infolists.

`Schema::step()` and `Panel::schemaStep()` create wizard-style steps. Steps are
still a single form submission: inactive step controls are temporarily disabled
for browser validation and re-enabled at submit time, while server-side form
validation remains the source of truth.

`themeDiagnostics()` reports loaded theme and preset names, unresolved base
references, missing preset references, inheritance cycles, and contrast checks
for light and dark surfaces. The lower-level
`Panel::themeLibrary()->isValid()` helper returns `true` when no blocking theme
package issues or contrast failures were found.

`themePreview()` returns route-free preview data for the active or named theme:
semantic swatches, light and dark mode tokens, sample surface/action colors,
stylesheet assets, brand assets, and contrast results. `Panel::themeLibrary()
->preview()` returns previews for every registered complete theme.
`themePreviewHtml()` returns the same theme preview as a reusable HTML fragment
with scoped preview CSS. It is a building block for any panel surface, not a
route or admin page.

When dark mode is enabled, generated pages include a compact Light, Dark, and
System mode control. The selected mode is applied immediately, stored in
`localStorage`, and mirrored into a `dataphyre_panel_theme_mode` cookie so the
next server render starts in the same mode. Disable the control with
`->modeToggle(false)` while still keeping a fixed `->defaultMode('dark')` or
`->defaultMode('light')`.

Hosts that expose multiple panel presets can add a route-safe selector beside
the generated mode control. Use an app-specific query parameter such as
`panel_theme` rather than `theme` when the host application already uses
`theme` for its own runtime skin:

```php
Panel::make('operations', [
	'theme_selector'=>true,
	'theme_selector_parameter'=>'panel_theme',
	'theme_selector_presets'=>[
		''=>'Flat Minima',
		'flat_minima'=>'Flat Minima',
		'glass'=>'Glass',
		'brutalist'=>'Brutalist',
	],
]);
```

When a selector is enabled, generated panel URLs preserve the active preset and
the browser stores it in `dataphyre_panel_theme_preset`. This keeps resource,
page, modal, and dashboard navigation visually stable while still allowing a
shared URL such as `?panel_theme=glass`.

Dark mode uses `dark_tokens` as overrides on top of the normal theme tokens.
Partial definitions inherit the flat minimal default dark surface:

```php
Panel::theme()
	->darkTokens([
		'body_bg'=>'#09090b',
		'surface'=>'#18181b',
		'surface_muted'=>'#111113',
		'text'=>'#fafafa',
		'border'=>'#3f3f46',
	])
	->darkToken('control_bg', '#09090b');
```

Generated CSS exposes theme tokens as variables such as `--dp-primary-600`,
`--dp-success-100`, `--dp-surface`, `--dp-text`, `--dp-radius`,
`--dp-max_width`, `--dp-panel_padding`, `--dp-control_padding`,
`--dp-input_padding`, `--dp-table_cell_padding`, and `--dp-gap`. Panel core
components use semantic tokens, and custom CSS is loaded after the core
stylesheet so app-specific styling can stay small.

Custom page CSS should prefer semantic tokens, but it must also tolerate partial
themes and app presets that only define color scales in one mode. When a custom
page owns a large bespoke surface, bridge Panel tokens into page-local variables
with explicit light fallbacks, then consume those local variables throughout the
page CSS:

```css
.ops-schedule {
	--ops-primary: var(--dp-primary-600, #2563eb);
	--ops-surface: var(--dp-surface, #fff);
	--ops-surface-muted: var(--dp-surface_muted, #f8fafc);
	--ops-text: var(--dp-text, #101828);
	--ops-text-muted: var(--dp-text_muted, #667085);
	--ops-border: var(--dp-border, #d7dee8);
	--ops-border-soft: var(--dp-border_soft, #e7ecf2);
}

.ops-schedule-card {
	background: var(--ops-surface);
	border: 1px solid var(--ops-border);
	color: var(--ops-text);
}
```

Avoid writing custom pages against aliases that may only be defined by dark
tokens or by one preset. In particular, do not rely on a global semantic token
unless the page has a fallback path for light mode. After custom CSS changes,
verify both light and dark modes with browser screenshots at the breakpoints the
surface can realistically hit; route-free Panel regressions are useful, but they
do not prove token contrast or responsive overflow.

The `flat_minima` preset sets `theme_effects` to `flat_minima`. It is the
default panel skin and keeps the renderer close to modern Tailwind/Filament
admin interfaces: flat surfaces, subtle borders, compact radius, minimal
shadows, and plain white or slate backgrounds.

The `brutalist` preset sets `theme_effects` to `brutalist`. It keeps panel
behavior unchanged while rendering hard-edged surfaces, heavy borders, flat
colors, and near-zero rounding. The preset exposes `brutalist_shadow`,
`brutalist_shadow_soft`, and `brutalist_focus` tokens for hosts that want to
soften or exaggerate the blocky treatment without replacing the renderer.

The `glass` preset is the first built-in effect theme. It sets
`theme_effects` to `glass`, which adds translucent surfaces, blurred chrome,
soft depth, and fallback behavior for browsers without `backdrop-filter`.
Effect styling still uses tokens, so hosts can keep the glass renderer behavior
while changing the material. The renderer recognizes separate glass layers for
main surfaces, stronger chrome, muted nested panels, controls, menus, overlays,
highlights, and lifted shadows:

```php
Panel::theme('frosted_ops')
	->preset('glass')
	->tokens([
		'glass_surface_bg'=>'linear-gradient(135deg,rgba(255,255,255,.80),rgba(255,255,255,.44))',
		'glass_control_bg'=>'linear-gradient(135deg,rgba(255,255,255,.78),rgba(255,255,255,.48))',
		'glass_menu_bg'=>'linear-gradient(135deg,rgba(255,255,255,.92),rgba(255,255,255,.68))',
		'glass_overlay_bg'=>'rgba(15,23,42,.36)',
		'glass_noise_opacity'=>'.10',
		'glass_tone_strength'=>'14%',
		'glass_focus'=>'0 0 0 4px rgba(14,165,233,.18),0 18px 50px rgba(14,165,233,.13)',
		'glass_active_glow'=>'0 18px 42px rgba(14,165,233,.22)',
		'glass_shimmer'=>'linear-gradient(90deg,transparent,rgba(255,255,255,.46),transparent)',
		'glass_scroll_thumb'=>'rgba(14,165,233,.36)',
		'glass_scroll_track'=>'rgba(255,255,255,.24)',
		'glass_mobile_blur'=>'blur(14px) saturate(1.12)',
		'glass_blur'=>'blur(28px) saturate(1.35)',
		'glass_shadow'=>'0 28px 80px rgba(15,23,42,.16)',
		'glass_shadow_lifted'=>'0 38px 100px rgba(15,23,42,.20)',
	]);
```

Tone-aware components such as widgets, summaries, board lanes, notices, alerts,
and guidance panels automatically tint their frosted surface from the semantic
tone class. The same Glass pass also styles command palette entries, dropdown
menus, table focus/selection, tabs, steps, charts, toasts, and form focus rings.
Glass includes dedicated treatment for scrollbars, empty states, loading
shimmers, board drag/drop targets, active navigation glow, relation managers, and
a lower-blur mobile material so small screens stay responsive.

Spacing tokens have fluent helpers for common layout choices:

```php
Panel::theme()
	->maxWidth('1280px')
	->panelPadding('24px')
	->sectionPadding('14px')
	->controlPadding('8px 12px')
	->inputPadding('8px 10px')
	->tableCellPadding('8px 10px')
	->gap('10px');
```

Stylesheets may be simple URLs or named assets with attributes:

```php
Panel::theme()->css([
	'/vendor/acme/panel/base.css',
	[
		'name'=>'charts',
		'href'=>'/vendor/acme/panel/charts.css',
		'media'=>'screen',
		'integrity'=>'sha384-...',
		'crossorigin'=>'anonymous',
	],
]);
```

Asset roots let packages publish portable presets without knowing the host URL:

```php
Panel::registerThemePreset([
	'name'=>'acme_ops',
	'css'=>[
		'acme::base.css',
		['name'=>'charts', 'href'=>'acme::charts.css'],
	],
]);

Panel::theme()
	->assetRoot('acme', '/assets/vendor/acme-panel')
	->preset('acme_ops');
```

When a preset leaves an alias unresolved, the active theme resolves it using its
own roots as the preset is applied. That lets apps override package asset
locations without editing the preset file.

`cssAssets()` returns the stylesheet URLs for older integrations.
`stylesheetAssets()` returns the full asset manifest used by the renderer.

Resources with status transitions also expose a generated `board` operation. The
board groups records into columns generated from the transition statuses, keeps
the resource search and filters available, and renders row actions directly on
each card so operators can move records through the workflow without leaving the
board. Cards can also be dragged into another status column when the resource
defines a valid transition for that move; the drag action submits the same
generated transition form used by the card buttons.

Generated forms render common control types directly:

- `text`, `email`, `password`, `number`, `integer`, `float`, `decimal`
- `date`, `time`, `datetime`, `datetime_local`, `month`, and `week`
- `textarea`, `markdown`, `code`
- `select` and `enum`, or any field with `options()`
- `boolean`, `bool`, `checkbox`, and `toggle`
- hidden fields through `hidden()`

Field components expose reusable presentation metadata in schema manifests.
This lets renderers, Flightdeck, tests, and documentation know whether a field is
an input, choice, editor, upload, structure, boolean, or date/time control
without guessing from CSS classes.

```php
Panel::field('sku')
	->mask('AAA-999')
	->prependLabel('SKU')
	->appendButton('Upper', 'uppercase')
	->suggestions(['NS-100', 'NS-200'])
	->autocomplete('off');

Panel::field('customer')->titleCase();
Panel::field('internal_code')->uppercase();
Panel::field('release_notes')->sentenceCase();
Panel::field('integration_key')->snakeCase();
Panel::field('webhook_name')->kebabCase();
Panel::field('client_token')->camelCase();

Panel::field('tracking_code')
	->mask('AA-999999', true)
	->maskPlaceholder('AA-000000')
	->appendButton('Copy', 'copy');

Panel::field('total', 'number')
	->prependLabel('CAD')
	->format('currency', ['decimals'=>2, 'on'=>'blur']);

Panel::field('phone', 'tel')
	->appendButton('Clear', 'clear')
	->phone();

Panel::field('email')->email()->copyButton();
Panel::field('website')->url()->prependLabel('https://');
Panel::field('delivery_postal_code')->postalCodeCountryField('market');
Panel::field('tax_reference')->ein();
Panel::field('operator_ssn')->ssn();
Panel::field('compliance_ssn')->mask('999-99-9999', true);
Panel::field('follow_up_date', 'date')->todayButton();
Panel::field('handoff_at', 'datetime')->nowButton();
Panel::field('sample_reference')->setButton('Use sample', 'sample-value');
Panel::field('stock', 'number')->min(0)->step(5)->stepperButtons('-5', '+5');

Panel::field('price', 'number')->currency('CAD');
Panel::field('margin', 'number')->percent(1);
Panel::field('password', 'password')->passwordReveal();

Panel::field('status', 'select')
	->searchable()
	->native(false)
	->clearable();

Panel::field('description', 'markdown')
	->editor('markdown')
	->preview()
	->maxLength(2000);
```

The built-in renderer supports native controls, datalist suggestions, input
masks, preset formatting rules, searchable/custom-select hints, and previewable
editor surfaces. Text-like fields, selects, multi-selects, textareas, and
key/value textareas can declare `prependLabel()`, `appendLabel()`,
`prependButton()`, and `appendButton()` without custom templates. Built-in field
button actions include `clear`, `copy`, `toggle_password`, `today`, `now`,
`uppercase`, `lowercase`, `title_case`, `sentence_case`, `snake_case`,
`kebab_case`, `camel_case`, `digits`, `alpha`, `alphanumeric`, `slug`, and
`set`.
Existing `prefix()` and `suffix()` calls are treated as prepend and append
labels for text-like controls. `clearable()` automatically adds a clear button
to text-like controls, and password fields get a reveal button by default unless
`password_reveal` is disabled in field metadata.
Use `formatOn('blur')`, `formatOn('change')`, or the `on` format option when a
rule should wait until the operator leaves the field. Masks continue to apply
while typing so literal separators stay predictable.

Shortcut builders exist for common field shapes: `currency()`, `percent()`,
`phone()`, `email()`, `url()`, `mapUrl()` / `mapsUrl()`, `domain()` / `hostname()`,
`timezone()`, `locale()`, `json()` / `jsonText()`,
`mimeType()` / `contentType()`, `semver()` / `semanticVersion()`,
`cronExpression()` / `cron()`,
`languageCode()` / `isoLanguage()`,
`countryCode()` / `isoCountry()`, `subdivisionCode()` / `regionCode()`,
`currencyCode()` / `isoCurrency()`, `ipAddress()` /
`ip()`, `ipv4()`, `ipv6()`, `macAddress()` / `mac()`, `uuid()`, `ulid()`,
`hexColor()` / `colorHex()`, `latitude()`, `longitude()`, `coordinates()` /
`latLng()` / `lngLat()`, `postalCode()`, `postalCodeForCountry()`,
`postalCodeCountryField()`, `postalCodeSubdivisionField()`,
`postalCodeLocaleFields()`, `zipCode()` as a US compatibility alias, `ssn()`,
`ein()`, `oneTimeCode()` / `verificationCode()` /
`otp()` / `pinCode()`, `creditCard()`,
`creditCardExpiry()` / `cardExpiry()`, `cardCvc()` / `cvc()` / `cvv()`,
`iban()`, `slug()`, `slugFrom()`, `copyButton()`, `copyNormalizedButton()`,
`clearButton()`, `revealButton()`, `todayButton()`, `nowButton()`, `setButton()`,
`incrementButton()`, `decrementButton()`, `stepperButtons()`, `formatButton()`,
`uppercaseButton()`, `lowercaseButton()`, `titleCaseButton()`, and
`trimButton()`.
Stepper buttons use the field control's native `step`, `min`, and `max`
attributes when adjusting numeric values.
Use `copyable()` on form fields to auto-append a copy button, or
`copyableNormalized()` when the button should copy the same normalized value that
submit sends.
Text-normalization helpers include `uppercase()`, `lowercase()`, `titleCase()`,
`sentenceCase()`, `snakeCase()`, `kebabCase()`, `camelCase()`, `trimmed()`,
`digits()`, `alpha()`, and `alphanumeric()`.
Use `sourceField('title')` / `fromField('title')` after a formatter when the
formatted value should be generated from a sibling field until the operator
edits it manually. Edit forms also recognize preloaded values that still match
the source-derived value, so those fields continue following the source until
they diverge.
Use `characterCounter()` / `charCounter()` / `counter()` to add a live prepend
or append counter adornment; passing a maximum also applies `maxLength()`.
Use `autoResize()` / `autosize()` on textareas to grow the control as operators
type longer content.
Array field definitions accept the same common formatting and masking controls,
including `format_rule`, `format_options`, top-level `country_field` /
`subdivision_field`, `format_placeholder`, `mask_placeholder`,
`submit_unmasked`, `submit_formatted`, `character_counter`, `auto_resize`, and
`copyable`.

Formatted fields submit normalized values by default. For example phone and
credit-card, card-expiry, and CVC rules submit digits, currency and percent
rules submit decimal text, and postal code, IBAN, and alphanumeric rules submit
uppercase compact text. Email fields trim and lowercase text; URL fields trim
whitespace and add `https://` when an operator enters a domain without a scheme.
Domain fields accept pasted URLs and store only the lowercased hostname.
Timezone fields canonicalize common IANA casing, such as `america/toronto` to
`America/Toronto`, and validate against the runtime timezone catalog.
Locale fields normalize BCP-47-style tags, such as `en_ca` to `en-CA`, and
validate language, script, region, and variant shape.
JSON fields render as auto-resizing textareas, pretty-format valid JSON for the
operator, validate JSON syntax, and submit compact normalized JSON.
MIME type fields normalize case and parameter spacing, such as
`Application/JSON ; Charset = UTF-8` to `application/json; charset=utf-8`.
Semantic-version fields normalize optional `v` prefixes, such as `v1.2.3-beta`
to `1.2.3-beta`, and validate SemVer-style major/minor/patch text.
Cron expression fields normalize spacing and casing, and validate standard
five-field cron expressions including ranges, lists, steps, and month/day names.
Language code fields normalize common names and locale tags, such as `English`
or `en-CA` to `en`, and validate ISO-style two-letter language codes.
Country code fields normalize common names and alpha-3 aliases, such as
`Canada` or `CAN` to `CA`, and validate ISO alpha-2 codes.
Subdivision code fields normalize common province, state, and region names, such
as `Quebec` to `QC`, and can validate against a country with
`subdivisionCodeForCountry('CA')` or `subdivisionCodeCountryField('country')`.
Currency code fields normalize common names and symbols, such as `Canadian
dollar` to `CAD` or `€` to `EUR`, and validate ISO 4217 codes.
IP address fields trim text, lowercase IPv6 hex, and validate IPv4/IPv6
semantics with `ipAddress()`, `ipv4()`, or `ipv6()`.
MAC address fields normalize common pasted separators into uppercase
colon-separated pairs.
UUID fields normalize compact or braced UUIDs into lowercase hyphenated text and
validate UUID version and variant bits.
ULID fields normalize pasted lowercase or spaced values into uppercase compact
text and validate Crockford Base32 ULID syntax.
Hex color fields normalize shorthand or bare values into `#rrggbb` text and show
a live swatch adornment beside the input. Use `hideColorSwatch()` or
`colorSwatch(false)` to suppress the preview in dense forms.
Coordinate fields use native number controls, normalize decimal precision, and
validate latitude/longitude bounds.
Use `coordinates()` / `latLng()` for a single text field that stores normalized
`latitude,longitude`, or `lngLat()` to accept pasted longitude-first pairs and
store them in the same normalized order.
Map URL fields accept Google Maps URLs or coordinate pairs and normalize
coordinates into `https://www.google.com/maps?q=...` links.
Slug fields can use `slugFrom('title')` as a convenience wrapper around
`slug()->sourceField('title')`.
Use
`submitFormatted()` when the stored value should keep the visual punctuation, or
`submitNormalized(false)` to opt out explicitly. `copyNormalizedButton()` copies
the same normalized value that submit will send; `copyButton()` keeps copying the
visible field text unless called as `copyButton('Copy', true)`.
Server validation checks both generated patterns and field-specific semantics
where available, including credit-card Luhn checks, future card-expiry checks,
IBAN mod-97 checks, and IP address validation.
Common format rules also generate placeholder hints, such as `+1 000 000 0000`
for international phone fields and `00000-0000` for US postal fields, when no explicit
placeholder is set. Use `formatPlaceholder()` to override the generated hint or
`hideFormatPlaceholder()` to omit it. Phone, postal-code, and credit-card
formatters also receive native `pattern` and validation `title` attributes unless
an explicit `pattern()` or `title` metadata value is set. Formatting can follow
sibling locale fields with `phoneCountryField('market')`,
`postalCodeCountryField('country')`, `postalCodeLocaleFields('country', 'region')`, or the
lower-level `formatCountryField()` / `formatSubdivisionField()` helpers. The
browser refreshes generated placeholders, patterns, titles, input modes, and
country prepend labels when the source field changes; Canadian postal-code
patterns also narrow by province or territory when a subdivision source is
provided or inferred from an unambiguous subdivision-only source, US ZIP patterns
can narrow by known state prefixes, and GB/UK
postcodes use `SW1A 1AA` style spacing and validation. Australian postcodes use
four-digit formatting and can narrow by state or territory; New Zealand
postcodes use the same four-digit style and can narrow by region. EU market
forms can use the subdivision field as the country for FR, DE, NL, and IE postal
formats. Explicit placeholder, pattern, and title values are preserved.
International phone fields can use country/subdivision-aware calling-code
placeholders such as `+44` for GB, `+61` for AU, `+64` for NZ, and `+49` for
Germany; local trunk prefixes such as the leading `0` in `020...` are removed
when the international prefix is applied. The same
country/subdivision-aware rules run during server-side dehydration and
validation, so no-JS submits and visually formatted values are normalized and
checked against the effective locale rule too. When the `geoposition` module is
loaded, server validation also consults its SQL-backed postal-code regex and
reformatting rules, trying both local subdivision codes such as `ON` and
ISO-style codes such as `CA-ON`, before falling back to the panel's built-in
patterns. Browser formatting and server dehydration both scope repeater
child fields to their current row, so `postalCodeCountryField('country')` can live
inside repeated address rows. Text-based locale source fields also refresh
dependent formatters while typing, not only after select changes.

Masks use `9` for digits, `A` for uppercase letters, `a` for lowercase letters,
and `*` for alphanumeric characters. Masks keep their visual punctuation on
submit unless `mask($pattern, true)` or `submitUnmasked()` is used. Use
`submitMasked()` to make that choice explicit for SKU-style values where the
separators are part of the stored identifier. Masked fields receive a generated
placeholder such as `AAA-000` unless a normal placeholder is already set. Use
`maskPlaceholder('AA-000000')` to override it or `hideMaskPlaceholder()` to
disable the hint. Masked fields also receive native `maxlength`, `pattern`, and
validation `title` attributes derived from the mask unless `maxLength()`,
`pattern()`, or explicit `title` metadata is set. Enhanced fields intercept paste
so copied values such as `12 345
6789` can settle into the configured mask before browser length limits drop
meaningful characters. Formatting presets currently include
`phone`, `postal_code_ca`, `credit_card`, `iban`, `currency`, `percent`,
`zip_code_us`, `ssn`, `ein`, `email`, `url`, `digits`, `alpha`,
`alphanumeric`, `uppercase`, `lowercase`, `title_case`, `sentence_case`,
`snake_case`, `kebab_case`, `camel_case`, `trim`, and `slug`.
Apps can register custom browser-side behavior without patching Panel:

```js
window.DataphyrePanel
	.registerFieldFormatter('tracking_code', function(value) {
		return String(value || '').toUpperCase().replace(/[^A-Z0-9-]/g, '');
	})
	.registerFieldButton('normalize_tracking', function(input) {
		input.value=window.DataphyrePanel.fieldFormatters.tracking_code(input.value);
	});
```

### Editor packages and persistence policy

Rich text, HTML, markdown, and code fields expose framework-owned editor
packages. `PanelEditorProfile` is the reusable contract for toolbars, named
plugins, server normalization and validation, HTML allow-list policy, media and
upload adapters, and token-stream syntax highlighting. The contract is not tied
to a particular JavaScript editor, sanitizer library, storage disk, or cloud.

```php
use Dataphyre\Panel\PanelEditorMedia;
use Dataphyre\Panel\PanelEditorBrowserAdapter;
use Dataphyre\Panel\PanelEditorPlugin;
use Dataphyre\Panel\PanelEditorProfile;
use Dataphyre\Panel\PanelEditorSanitizationPolicy;
use Dataphyre\Panel\PanelEditorToolbar;
use Dataphyre\Panel\PanelEditorUpload;

$policy=PanelEditorSanitizationPolicy::strict()
	->allowElement('img', ['src', 'alt', 'title']);

$profile=PanelEditorProfile::make('articles', 'rich_text')
	->sanitizationPolicy($policy)
	->toolbar(
		PanelEditorToolbar::make('articles')
			->command('bold', 'Bold', 'inline')
			->command('mention', 'Mention', 'insert', 'Mention a teammate', 'mentions')
	)
	->plugin(PanelEditorPlugin::make('mentions')->commands('mention'))
	->uploadAdapter(
		PanelEditorUpload::make('article_media', '/panel/editor-media')
			->accept(['image/*'])
			->maxBytes(5 * 1024 * 1024)
	)
	->mediaAdapter(
		PanelEditorMedia::make('article_media')->allowPrefixes('/uploads/articles/')
	)
	->browserAdapter(
		PanelEditorBrowserAdapter::tinyMce(['menubar'=>false])
	)
	->browserSyntaxAdapter(
		PanelEditorBrowserAdapter::prism(['html', 'php'])
	);

Panel::field('body')->richText()->editorProfile($profile);
```

Editors can use a single typed asset provider for browsing, upload validation,
storage, deletion, delivery, and persisted-reference normalization. The generic
callback pack adapts Flysystem, Spatie Media Library, Cloudinary, or an
application repository without making any of them a Panel dependency:

```php
use Dataphyre\Panel\PanelEditorAsset;
use Dataphyre\Panel\PanelEditorAssetPage;
use Dataphyre\Panel\PanelEditorAssetResult;
use Dataphyre\Panel\PanelEditorCallbackAssetProvider;
use Dataphyre\Panel\PanelEditorContext;

$assets=PanelEditorCallbackAssetProvider::make('article_library', '/panel/editor/assets')
	->providerType('flysystem')
	->browserDriver('http')
	->accept(['image/jpeg', 'image/png', 'image/webp'])
	->maxBytes(10 * 1024 * 1024)
	->deletes()
	->authorizeUsing(
		static fn(string $operation, PanelEditorContext $context): bool =>
			$assetPolicy->allows($operation, $context)
	)
	->browseUsing(
		static fn(array $query, PanelEditorContext $context): PanelEditorAssetPage =>
			$assetLibrary->browse($query, $context)
	)
	->findUsing(
		static fn(string $id, PanelEditorContext $context): ?PanelEditorAsset =>
			$assetLibrary->find($id, $context)
	)
	->storeUsing(
		static fn(array $upload, PanelEditorContext $context): PanelEditorAssetResult =>
			$assetLibrary->store($upload, $context)
	)
	->deleteUsing(
		static fn(string $id, PanelEditorContext $context): bool =>
			$assetLibrary->delete($id, $context)
	)
	->normalizeUsing(
		static fn(string $url, PanelEditorContext $context): ?string =>
			$assetLibrary->canonicalReference($url, $context)
	);

Panel::field('body')->richText()->editorAssetProvider($assets);
```

A provider-backed field automatically emits the `editor-assets` capability and
loads `panel-editor-assets.js` after `panel-editor.js`. Ordinary rich-text,
markdown, code, and plain editors load only the base editor package. Custom HTML
can opt in with an `editor-assets` declaration or the generated
`data-dp-panel-editor-assets-trigger` / `data-dp-panel-editor-assets-host`
markers. Do not add the picker script manually to every editor page; use the
capability manifest so ordering, hashes, SRI metadata, scoped CSS, and modal
dependency closure remain deterministic.

All callbacks are runtime-only and default-deny when missing or throwing.
`fromArray()` deliberately restores an inert provider: a manifest never
rehydrates authorization, storage, tenant scope, or other executable authority.
Upload checks use the actual temporary file size and a server MIME detector;
browser MIME metadata is not authoritative.

Applications already using `PanelMediaManager` can use the first-party scoped
pack. `scope` must resolve from trusted host context, never directly from an
untrusted form value, and `authorize` is checked for every provider operation:

```php
use Dataphyre\Panel\PanelEditorContext;
use Dataphyre\Panel\PanelEditorMediaManagerProvider;

$assets=PanelEditorMediaManagerProvider::make(
	$mediaManager,
	'/panel/editor/assets',
	$editorAssetScopeKey, // At least 32 secret bytes; never emitted to a manifest.
	[
		'scope'=>static fn(PanelEditorContext $context): string =>
			$trustedTenancy->scopeFor($context),
		'authorize'=>static fn(string $operation, PanelEditorContext $context, string $scope): bool =>
			$assetPolicy->allows($operation, $context, $scope),
		'accepted'=>['image/jpeg', 'image/png', 'image/webp'],
		'max_bytes'=>10 * 1024 * 1024,
		'prefix'=>'editor-assets',
		'deletes'=>true,
	]
);
```

This pack binds assets, signed pagination cursors, canonical references,
delivery grants, uploads, and deletion to the resolved scope. Uploads use the
media manager's chunk, checksum, processing, and cancellation lifecycle. A
scope resolver or authorizer is mandatory; omitting either leaves the provider
unready.

`PanelEditorAssetEndpoint` is a route-neutral transport helper. Its request
verifier is mandatory and fail-closed. Mount it only after the host has
authenticated the request, enforced same-origin and CSRF policy, resolved the
tenant and principal, applied rate limits, and constructed a trusted
`PanelEditorContext`:

```php
use Dataphyre\Panel\PanelEditorAssetEndpoint;

$endpoint=new PanelEditorAssetEndpoint(
	$assets,
	static fn(string $operation, array $trusted_request): bool =>
		$trusted_request['authenticated'] === true
		&& $trusted_request['same_origin'] === true
		&& $trusted_request['csrf_verified'] === true
		&& $rateLimit->allows('editor-assets', $operation)
);

$result=$endpoint->handle($payload, $files, $editorContext, $trustedRequest);
```

The host remains responsible for installing the route and translating the
returned `status`, `headers`, and JSON `body` into its HTTP response. Panel does
not install a route, authentication, origin checks, CSRF verification, tenant
resolution, rate limiting, or request-context trust. The built-in HTTP bridge
sends same-origin credentials and verification metadata, but those browser
signals are not proof. Provider endpoints and asset references accept only safe
root-relative or HTTPS URLs, reject credentials, fragments, traversal, and
secret-bearing query keys, and return bounded stable non-diagnostic envelopes.

HTML and rich text use a strict server-side DOM sanitizer by default. The same
policy runs in `validateValue()` and after `dehydrateUsing()`, so a custom
dehydration callback cannot reintroduce unsafe markup. Unsafe tags, attributes,
link schemes, protocol-relative URLs, and unapproved embedded media fail closed.
Use `stripUnsafe()` explicitly when stripping with warnings is preferable to
rejecting the value. Embedded media requires both an allow-listed element and a
ready `PanelEditorMediaAdapter`. Media references are checked again after a
custom resolver runs; an allowed root-relative prefix never authorizes other
root-relative or protocol-relative destinations.

Upload adapters are ready only when they declare a non-empty file-type
allow-list. MIME rules use an explicit server detector or `finfo` against the
temporary file rather than the browser-declared MIME value, and the temporary
file's actual size is authoritative. Active document, script, executable, PHP,
and SVG types remain blocked even when a broad wildcard is configured. Adapter
endpoints reject credentials, fragments, traversal, and secret-bearing query
keys, including nested or repeatedly encoded keys.

Markdown, code, and plain editor content remains byte-for-byte unchanged by the
built-in profile. Markdown link, image, reference, and autolink destinations are
still checked against the URL policy. Syntax adapters return `{type, text}`
tokens; Panel escapes every token and never accepts highlighted HTML.

Callback normalizers, validators, resolvers, MIME detectors, and highlighters are
runtime-only. Closures, objects, and secret-looking adapter metadata are omitted
from manifests. A serialized profile that depended on a runtime callback must be
rebound before it accepts content; importing its manifest does not silently drop
the security hook.

Explicit profiles are available to the browser through escaped editor data
attributes. The runtime emits cancelable `dp-panel-editor:command` events and
also exposes `dp-panel-editor:ready`, `dp-panel-editor:insert`, and
`dp-panel-editor:highlight`. Inserted HTML passes through the client preview
sanitizer, and syntax tokens are rendered with `textContent`; the server profile
remains the persistence boundary. Fields without an explicit profile retain the
legacy toolbar and markup contract.

`PanelEditorBrowserAdapter` adds optional first-party browser bridges without
adding a browser-editor dependency to Panel core. The built-in descriptors are:

- `tinyMce()` and `ckEditor5()` for rich HTML surfaces loaded as browser globals;
- `monaco()` for code, plain, markdown, and HTML source editing;
- `tiptap()` and `codeMirror6()` for host-registered module builds;
- `prism()` and `highlightJs()` for inert `{type, text}` syntax token streams.

Panel does not download these packages or emit a CDN URL. A global build must be
loaded by the host before `panel-editor.js`, or the host can load it later and
call `DataphyrePanelEditors.mountAll(document)`. Module builds register an
instance factory explicitly:

```js
const editors=window.DataphyrePanelEditors;

editors.registerSurface(
	'tiptap',
	editors.createTiptapBridge(async context => {
		const instance=await mountApplicationTiptap({
			element: context.host,
			content: context.read(),
			onUpdate: html => context.change(html),
			signal: context.signal,
		});

		return {
			getValue: () => instance.getHTML(),
			setValue: value => instance.commands.setContent(value, false),
			command: command => runApplicationEditorCommand(instance, command),
			destroy: () => instance.destroy(),
		};
	}),
);
```

The same registry exposes `registerSyntax()`, `unregisterSurface()`,
`unregisterSyntax()`, `mount()`, `unmount()`, `sync()`, `state()`, and `list()`.
Every mount is async-safe and instance-owned. Panel keeps the submitted textarea
canonical, flushes it before native or AJAX submission, releases detached roots,
survives same-turn DOM moves and back/forward-cache restoration, and retires a
stale async instance rather than attaching it to a replacement modal. A missing,
unsupported, or failed adapter follows its explicit `native`, `source`, or
`error` fallback and emits `dp-panel-editor:adapter-*` lifecycle events. Browser
manifests report `runtime_probe=browser`; server code never claims a vendor
engine is loaded.

Asset libraries are implemented by `panel-editor-assets.js` and expose the
parallel `registerAssets()`, `unregisterAssets()`, `openAssets()`,
`closeAssets()`, `assetState()`, and `createHttpAssetBridge()` APIs through the
same `DataphyrePanelEditors` namespace. The built-in `http` bridge activates
automatically for a ready same-origin provider. A host-only provider can
register an equivalent bridge after both editor packages are loaded; each
method returns the same versioned `panel_editor_asset_result` envelope as the
endpoint:

```js
window.DataphyrePanelEditors.registerAssets('host_assets', {
	browse(context, query) {
		return applicationAssets.browse(query, {signal: context.signal});
	},
	upload(context, file) {
		return applicationAssets.upload(file, {signal: context.signal});
	},
	delete(context, id) {
		return applicationAssets.delete(id, {signal: context.signal});
	},
	delivery(context, id) {
		return applicationAssets.delivery(id, {signal: context.signal});
	},
});
```

Until a declared bridge is ready, Panel keeps the browse trigger hidden and
disabled. The native picker is progressively enhanced, keyboard and dialog
accessible, responsive through narrow embedded containers, focus-restoring,
abortable, and released on editor unmount, detached roots, runtime disposal, or
provider unregister. Provider-issued media references are tracked per editor;
same-origin lookalike URLs, pasted image HTML, and stale or unapproved references
are stripped client-side before preview or adapter insertion. The server
sanitizer and provider normalization remain the final persistence authority.

Browser options are inert, bounded, and secret-redacted. Runtime callbacks stay
inside the host-registered bridge. TinyMCE, CKEditor, Monaco, Prism, and
highlight.js adapters resolve only declared globals; no `eval` or remote loader
is used. Highlight output is flattened and reconstructed with `textContent`, so
vendor-highlighted HTML never crosses into the preview as trusted markup. Rich
content is still accepted only after the server `PanelEditorProfile` sanitizer,
normalizers, validators, media policy, and authorization path succeed.

Select-like fields can use static options, option groups, or request-aware
dynamic options:

```php
Panel::field('status', 'select')
	->options([
		'open'=>'Open',
		'Closed states'=>[
			'resolved'=>'Resolved',
			'cancelled'=>'Cancelled',
		],
	]);

Panel::field('assignee_id', 'select')
	->optionsUsing(function($record, PanelRequest $request, string $operation){
		return UserRepository::panelAssignableOptions($request->user(), $record, $operation);
	});
```

Dynamic options are resolved when create/edit forms, action forms, and show pages
render. Show pages use the resolved option label for stored values.

Forms can be arranged into generated sections and responsive grids:

```php
$resource=$resource
	->formColumns(2)
	->formSections([
		Panel::formSection('identity')
			->label('Identity')
			->description('Public-facing profile details.')
			->columns(2),
		Panel::formSection('operations')
			->description('Internal workflow controls.')
			->collapsible(),
	])
	->fields([
		Panel::field('name')->section('Identity'),
		Panel::field('email')->section('Identity'),
		Panel::field('status')->section('Operations'),
		Panel::field('bio', 'textarea')->section('Operations')->columnSpan('full'),
	]);
```

`section()` groups fields under a heading. `columnSpan(2)` spans a field across
multiple grid columns, and `columnSpan('full')` spans the whole form row.
`formSection()` adds optional section metadata such as descriptions, per-section
column counts, and collapsible/collapsed behavior. Generated show pages use the
same field sections, form column count, section metadata, and column spans for
the read-only record view. Hidden fields are omitted from the show view.

Fields without an explicit `columnSpan()` or `columnStart()` use Panel's adaptive
grid placement. The renderer derives a safe default span from each responsive
column count (one track through four columns, two through eight, and three
through twelve). Form and show sections resolve their intermediate layout from
their own inline-size container: between `761px` and `1040px`, the `md` column,
span, start, and row definitions are used even when the outer viewport is much
wider; at `760px` and below, every item collapses to the complete row. Explicit
placement is never rewritten. This keeps every declared track positive instead
of producing implicit zero-width tracks when a shell or embedded surface
reflows. The same contract applies to show/detail entries, so a dense
twelve-track schema cannot squeeze an unconfigured native control or value card
into an unusable single track.

`displayUsing()` customizes a field's read-only value on show pages without
changing form hydration, dehydration, validation, or save payloads:

```php
Panel::field('total_minor', 'integer')
	->displayUsing(fn($value) => 'CAD '.number_format(((int)$value)/100, 2));

Panel::field('name')
	->displayUsing(fn($value, $record) => $record['first_name'].' '.$record['last_name']);
```

Fields can be conditionally visible by operation. Invisible fields are not
rendered and are skipped during dehydration and validation:

```php
Panel::field('created_at')->onlyOn('show');
Panel::field('invite_message', 'textarea')->visibleOn('create');
Panel::field('internal_note')->hiddenOn('create', 'show');
Panel::field('approval_code')->visibleUsing(
	fn($operation, $record, $request) => $operation==='edit' && $request->user()?->can('approve')
);
```

Operations normalize `store` to `create`, `update` to `edit`, and `view` to
`show`.

Fields can also depend on other submitted values. Dependency rules are enforced
server-side during dehydration and validation, and generated forms include a
small browser-side refresher so dependent fields appear, disappear, or become
required as the operator changes the controlling value:

```php
Panel::field('requires_review', 'checkbox');

Panel::field('review_note', 'textarea')
	->visibleWhen('requires_review', true)
	->required();

Panel::field('close_reason', 'select')
	->visibleWhen('status', ['closed', 'cancelled'])
	->requiredWhen('status', ['closed', 'cancelled'])
	->options([
		'resolved'=>'Resolved',
		'duplicate'=>'Duplicate',
	]);
```

`hiddenWhen()` is the inverse visibility rule, and `dependsOn()` can be used to
expose a dependency without adding a visibility rule. `requiredWhen()` and
`requiredUnless()` make validation conditional on another submitted value and
update the browser `required` / `aria-required` state for generated forms.
Select and enum fields automatically validate that submitted values exist in
their current static or dynamic option list.

Reactive fields refresh through the server-owned form state model without
redrawing the form. Mark controlling fields with `live()` when they should
trigger updates, and use `optionsDependOn()` on a dynamic select to declare the
state it reads. The generated form posts the current form state to the field
state endpoint and applies only the affected field changes: visibility,
`required` / `aria-required`, dynamic `<select>` options, computed field values,
help text, placeholders, readonly state, and validation messages after live
fields lose focus.

```php
Panel::field('status', 'select')
	->options(OrderStatus::labels())
	->live();

Panel::field('next_step', 'select')
	->optionsUsing(fn($record, $request) => NextSteps::for(
		$request?->input('status', $record['status'] ?? null)
	))
	->optionsDependOn('status')
	->help('Updates when status changes without repainting the form.');
```

Fields can also compute their own browser state from the submitted form values:

```php
Panel::field('sla_minutes', 'number')
	->stateUsing(function($value, array $state){
		$suggested=($state['risk'] ?? null)==='critical' ? 30 : 180;

		return [
			'value'=>$value === null || $value === '' ? $suggested : $value,
			'placeholder'=>(string)$suggested,
			'help'=>'Suggested from the current risk signal.',
		];
	}, 'risk');
```

`stateUsing()` accepts the current value, all submitted field values, the record,
request, field, and operation. Return a scalar to update only the field value, or
an array with `value`, `help`, `placeholder`, `options`, `visible`, `required`,
`readonly`, `errors`, `set`, `fields`, `force_value`, or `propagate`. `set` and
`fields` accept sibling field patches, which gives source fields a `$set`-style
way to update related controls:

```php
Panel::field('risk', 'select')
	->options(Risk::labels())
	->live()
	->stateUsing(fn($value) => [
		'set'=>[
			'priority_handling'=>[
				'value'=>in_array($value, ['high', 'critical'], true) ? '1' : '0',
				'force_value'=>true,
			],
		],
	]);
```

`force_value` allows a server-computed value to replace the current focused
value, and `propagate` dispatches a browser `change` event when the computed
value changes.

The same resolver runs through the schema state snapshot used by dehydration,
validation, and live field updates. Browser reactivity is therefore a preview of
the submitted state, not a separate client-only layer: computed values and
sibling `set` patches are applied again when the form is saved.

Resources can also mutate validated form data before it reaches `saveUsing()`.
Use this for normalization and workflow defaults that belong to the resource,
not to a single field:

```php
Panel::resource('orders')
	->mutateFormDataUsing(fn(array $data) => [
		...$data,
		'email'=>strtolower(trim($data['email'] ?? '')),
	])
	->mutateCreateDataUsing(fn(array $data) => [
		...$data,
		'source'=>'panel',
	])
	->mutateUpdateDataUsing(fn(array $data) => [
		...$data,
		'updated_at'=>date('Y-m-d H:i:s'),
	]);
```

The global mutator runs first. Create and update mutators then run for matching
modes, including imports for create data and bulk updates/transitions for update
data. The mutated array is the array passed to `saveUsing()`.

Resource save lifecycles can wrap the generated form flow without moving that
workflow into `saveUsing()`:

```php
Panel::resource('orders')
	->beforeValidateUsing(fn($record, string $mode, PanelRequest $request) => Audit::touch($request))
	->afterValidateUsing(fn(PanelFormState $state) => $state)
	->beforeSaveUsing(fn(array $data) => [
		...$data,
		'sla_bucket'=>($data['sla_minutes'] ?? 999) <= 30 ? 'urgent' : 'standard',
	])
	->afterSaveUsing(function($result, array $data){
		$result['notifications'][]=[
			'tone'=>'success',
			'title'=>'Saved',
			'body'=>'SLA bucket: '.$data['sla_bucket'],
		];

		return $result;
	});
```

`beforeValidateUsing()` is called before generated validation. `afterValidateUsing()`
may return a replacement `PanelFormState`. `beforeSaveUsing()` may return a
replacement data array after mutation and before `saveUsing()`. `afterSaveUsing()`
may return a replacement save result, which lets resources attach notifications
or redirect metadata without changing persistence logic.

`PanelFormState` is immutable and includes helpers for lifecycle hooks:

```php
->afterValidateUsing(function(PanelFormState $state): PanelFormState {
	if($state->value('risk')==='critical' && trim($state->value('internal_note', ''))===''){
		return $state
			->withError('internal_note', 'Critical orders need an internal handling note.')
			->withMeta(['cross_field_validated'=>true]);
	}

	return $state;
})
```

Use `withValue()`, `withValues()`, `withError()`, `withErrors()`,
`withoutError()`, `withMeta()`, `only()`, and `except()` to return modified form
state without editing the state arrays in place.

Forms and schemas can also produce a full state snapshot without submitting:

```php
$state=$resource->form()->state(
	record: $order,
	request: $request,
	operation: 'edit',
	validate: true,
);

$state->values();            // hydrated current values
$state->initialValues();     // values before submitted input
$state->dehydratedValues();  // save-ready values
$state->dirtyFields();       // fields changed from initial values
$state->stateUpdates();      // computed live patches by field
$state->fieldState('status');
```

`Schema::state()` exposes the same snapshot for route-free schema trees. This is
the foundation for Filament-style form components: one server-owned state model
drives render, live updates, validation, dirty tracking, and action lifecycles.
Under the hood, forms and schemas both use `SchemaLifecycle`, so the same
primitive can be reused by resource forms, action forms, modal workflows,
Reactor islands, tests, or any host that needs form state without owning a Panel
route.

```php
$schema=Panel::schema([
	Panel::field('title')->required(),
	Panel::field('risk', 'select')
		->options(['low'=>'Low', 'critical'=>'Critical'])
		->live(),
	Panel::field('priority', 'range')
		->min(1)
		->max(5)
		->stateUsing(fn(array $values) => ($values['risk'] ?? null)==='critical' ? 5 : null, 'risk'),
]);

$lifecycle=Panel::schemaLifecycle($schema, [
	'surface'=>'seller_intake',
]);

$state=$lifecycle->state(
	record: $existing_record,
	request: $request,
	operation: 'action',
	input: ['risk'=>'critical'],
	validate: true,
);

$state->dirtyFields();
$state->dehydratedValues();
$lifecycle->describe('action');
```

`SchemaLifecycle::hydrate()`, `dehydrate()`, `validate()`, `submit()`, and
`state()` return `PanelFormState`. `SchemaLifecycle::describe()` returns a
structured field manifest with required, readonly, reactive, dependency,
hydration, dehydration, and metadata flags. The manifest is intended for
generated tools, Flightdeck introspection, tests, and non-Panel frontends that
need to understand a form before rendering or submitting it.

When a caller needs the full component tree instead of only fields, use
`Schema::manifest()`, `ResourceForm::manifest()`, or `Panel::schemaManifest()`.
The schema manifest preserves layout nodes, parent ids, component paths,
sections, field/component links, responsive columns, lifecycle field metadata,
and aggregate capabilities:

```php
$manifest=Panel::schemaManifest($schema, 'action');

$manifest['components'];   // flattened tab/step/section/field tree with paths
$manifest['fields'];       // lifecycle field descriptions linked to components
$manifest['sections'];     // generated form sections
$manifest['capabilities']; // layout, live state, validation, and hydration counts
```

Lifecycle hooks can also stop the generated save flow by returning a
`PanelLifecycleResult`:

```php
->beforeSaveUsing(function(array $data){
	if(($data['risk'] ?? null)==='critical' && ($data['market'] ?? null)==='EU'){
		return PanelLifecycleResult::halt(
			'EU critical orders need compliance intake before creation.',
			[PanelNotification::warning('Open compliance intake before saving this order.')]
		);
	}

	return $data;
})
```

`PanelLifecycleResult::halt()` renders a stopped-operation response with
notifications. `PanelLifecycleResult::redirect()` redirects with notifications.
Returning `false` from a lifecycle hook is treated as a generic halt for quick
guards, but explicit lifecycle results are preferred for user-facing flows.

Resources can also mutate the data used to fill generated forms:

```php
Panel::resource('orders')
	->mutateCreateFormDataBeforeFillUsing(fn(array $data) => [
		...$data,
		'market'=>$data['market'] ?? 'CA',
		'status'=>$data['status'] ?? 'review',
	])
	->mutateEditFormDataBeforeFillUsing(fn(array $data) => [
		...$data,
		'email'=>strtolower(trim($data['email'] ?? '')),
	]);
```

`mutateFormDataBeforeFillUsing()` runs for every generated resource form.
Create/edit-specific fill mutators run after it. Fill mutators receive the
hydrated state values, current record, mode, request, and resource. They are
display-time hooks only; submit-time normalization belongs in the save data
mutators.

Fill has its own lifecycle hooks:

```php
Panel::resource('orders')
	->beforeFillUsing(fn($record, string $mode, PanelRequest $request) => Audit::touch($request))
	->afterFillUsing(function(PanelFormState $state){
		return $state->withValue('internal_note', $state->value('internal_note', ''));
	});
```

`beforeFillUsing()` runs before form hydration. `afterFillUsing()` receives the
hydrated, fill-mutated state and may return a replacement `PanelFormState`.
Generated resource forms also resolve live state before `afterFillUsing()` runs,
so computed values and sibling `set` patches are already reflected on the first
render, not only after the first browser-side change.

`reactive()` is available when a field should participate in the live state
model even without dynamic options. `debounce(ms)` or `live(true, ms)` controls
the browser delay before dependent field-state requests are sent. The older
field-options endpoint remains available for lightweight option-only refreshes,
but generated forms use the richer state endpoint by default.

Live validation is scoped. When a generated form validates a field after focus
leaves it, the field-state endpoint returns validation results for that field
only, while still returning visibility and option state for dependent fields.
This prevents one touched required field from revealing every unrelated required
field on the screen. Generated forms also track changed fields locally and add a
subtle dirty state to the modified controls while keeping automatic live updates
paused until the form is saved, reset, or left.

Panel navigation uses the same dirty-state model. Internal links, same-panel
AJAX navigation, history navigation, and unsafe full-page links open a Panel
"Leave with unsaved changes?" dialog instead of the browser confirmation when
JavaScript is available. The browser `beforeunload` prompt is kept only as the
last-resort guard for closing or reloading the tab, where browsers do not allow
custom dialog content.
The Panel dialog uses an opaque, high-contrast surface in light mode and a solid
dark surface in dark/system-dark mode so warnings remain readable even when the
active theme uses flat, minimal, or glass effects.

## Resource Capabilities

Resources can define:

- labels and navigation entries
- grouped dashboard navigation through `group()`, ordered by resource `sort()`
- SQL table names, repository classes, or custom query factories
- form fields with type, rules, default values, hydration/dehydration callbacks,
  custom validation callbacks, options, help text, sections, column spans, and
  metadata
- table columns with sorting/searching flags and optional formatters
- table filters with select, text, boolean, date, or custom predicate matching
- actions with handlers, authorization callbacks, confirmation flags, tone, and
  bulk-action metadata
- relation managers with child tables, query hooks, authorization callbacks, and
  optional related-resource metadata
- dashboard widgets with lazy values, tones, icons, links, and descriptions

Grouped dashboard navigation uses an intrinsic, container-safe card grid. Its
declared card basis never becomes a hard minimum: cards collapse to one track
and fill the available inline size when a Panel is embedded below that basis,
independently of the browser viewport and in both LTR and RTL documents.

The same container contract applies to ultra-narrow embedded resource surfaces,
including hosts as small as 160 CSS pixels. Command-bar actions, per-page
controls, grouped-table headings, summary values, copyable show entries,
checkbox fields, and custom-page card grids collapse into bounded single-track
layouts based on the Panel's inline size rather than the browser viewport.
Controls remain operable and text wraps in place; these surfaces never require
an accidental horizontal scrollbar merely because the surrounding application
mounts Panel in a narrow split pane.

Authorization is explicit. A global gate can deny the generated Panel surface
before resources, widgets, or action handlers run:

```php
Panel::authorize(function(string $ability, ?Resource $resource, mixed $user, PanelRequest $request){
	return $user?->can('use_panel') === true;
});
```

The module config key `authorize` accepts the same callable, or a boolean for
simple environment-level enablement.

Panel can also delegate the global gate to Dataphyre Permission without coupling
the renderer to a concrete app user model:

```php
Panel::surface('admin')
	->auth()
	->permissions([
		'super_permission'=>'panel.*',
		'allow_guest_pages'=>['login', 'register'],
	]);
```

The same bridge can be enabled from `DP_PANEL_CFG`:

```php
[
	'permission'=>[
		'super_permission'=>'panel.*',
	],
]
```

Permission checks receive the Panel request context (`tenant`, `resource`,
`operation`, `record`, `action`, and `relation`) and use semantic names such as
`panel.orders.view_any`, `panel.orders.update`,
`panel.orders.action.review`, and `panel.orders.relation.items.view`. The bridge
uses Dataphyre Access as the subject source when no explicit Panel user is
present, so apps can opt in to Access login first and then layer Permission
policies over the same identity.

`Panel::panelManifest()` includes a `permission` section whenever the manifest is
built. It reports whether the Permission module is available, whether the bridge
is configured, the expected super permission, generated catalog rows, flat
permission names, counts by permission type, and examples. Tooling can use this
to show missing grants or generate role previews without registering the
Permission admin resources.

Each resource manifest also includes `permission.operations`,
`permission.actions`, `permission.relations`, and `permission.permissions` so
builders can display or validate the exact permission string for a resource
operation, action button, or relation operation in place.
Individual action manifests expose `permission.permission`, and relation
manifests expose `permission.operations.view` / `permission.operations.update`,
which lets tooling attach permission guidance directly to a button or relation
panel.
When the Permission bridge is enabled, generated action state and relation
access also consult those permission names, so unauthorized buttons and relation
panels can be hidden or forbidden before their handlers run.
Packages that need to compute the same names can use Panel's internal
`PanelPermissionBridge` helper, which centralizes the configured prefix,
resource normalization, action/relation permission naming, and optional
Dataphyre Permission checks.
Custom pages are permission-aware as well: `panel.reports.view` controls a page
named `reports`, page manifests expose `permission.operations.view`, and
`allow_guest_pages` keeps login/register style pages visible without a subject.

Per-request allow/deny snapshots are available when an app opts in:

```php
Panel::surface('admin')->permissions([
	'manifest_decisions'=>true,
]);
```

With that enabled, `permission.decision_snapshot` includes the current request
context, subject id, roles, raw rules, allowed permissions, denied permissions,
and a decision map for the generated catalog. Keep this disabled for public
manifests unless the caller is trusted.

To expose admin surfaces for roles, assignments, and the generated permission
catalog:

```php
Panel::surface('admin')->permissionAdmin();
```

Resource authorization runs after the global gate:

```php
$resource=$resource->authorize(function(string $ability, mixed $record, mixed $user){
	return $user?->can($ability) === true;
});
```

For Filament-style policy definitions, use `policy()`. Policies may be arrays,
objects, or class names. Array values can be booleans or callbacks:

```php
Panel::resource('orders')
	->policy([
		'viewAny'=>true,
		'view'=>true,
		'create'=>fn($record, $user) => $user?->can('create_orders') === true,
		'update'=>fn($record, $user) => ($record['status'] ?? '') !== 'shipped',
		'delete'=>fn($record) => ($record['status'] ?? '') === 'cancelled',
		'bulkUpdate'=>true,
		'export'=>true,
	]);
```

Policy methods use the same names: `viewAny()`, `view()`, `create()`,
`update()`, `delete()`, `forceDelete()`, `bulkUpdate()`, and so on. Ability
aliases are resolved automatically, so `index` maps to `viewAny`, `show` maps to
`view`, `edit` maps to `update`, and `store` maps to `create`. Scoped abilities
such as `transition:approve` try the exact ability first, then fall back to the
base ability. Generated navigation, create/edit links, export/import controls,
bulk actions, show pages, and save handlers all use the same policy result.

Tenant context is also route-agnostic. The active tenant key is read from the
configured tenant parameter, request input, `X-Dataphyre-Panel-Tenant`, or a
panel-level resolver. Generated URLs preserve the tenant key automatically:

```php
$panel=Panel::make('seller')
	->tenantParameter('store')
	->tenantResolver(fn(PanelRequest $request) => $request->tenantKey());

Panel::resource('orders')
	->tenantScoped('store_id')
	->queryUsing(fn(PanelRequest $request) => OrderRepository::query());
```

`tenantScoped('store_id')` applies the current tenant to array-backed resources
and query builders with a `where()` method. Use `tenantScoped('store_id', false)`
when the resource should show all records until a tenant is selected. For custom
storage, use `tenantUsing()` to resolve the tenant key per resource or
`tenantScopeUsing()` to apply the tenant to a repository/query object yourself.
The resolved tenant is exposed as `PanelRequest::tenantKey()` and included in
`PanelRequest::toArray()` for custom renderers, tests, action handlers, relation
queries, and widgets.

### Tenant lifecycle and isolation

For a multi-tenant operator surface, register tenants on the surface's manager
and resolve membership explicitly. The registry belongs to that manager; two
`PanelInstance` objects never share a mutable tenant registry unless the host
deliberately gives them the same manager.

```php
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelTenant;
use Dataphyre\Panel\PanelTenantMembership;

$panel=Panel::make('operations');
$panel->registerTenants([
	PanelTenant::make('north')->label('North')->url('/panel?tenant=north'),
	PanelTenant::make('south')->label('South')->url('/panel?tenant=south'),
]);

$panel->tenantMembershipsUsing(function($user, PanelRequest $request): array {
	return TenantDirectory::forUser($user?->id)->map(
		fn($row) => PanelTenantMembership::make(
			tenant: $row->tenantKey,
			roles: $row->roles,
			permissions: $row->permissions,
			preferred: $row->preferred,
			expiresAt: $row->expiresAt,
		)
	)->all();
});

$panel->tenantAuthorizationUsing(function(
	mixed $user,
	PanelRequest $request,
	PanelTenant $tenant,
	PanelTenantMembership $membership,
	string $ability,
): bool {
	return $user?->can($ability, $tenant->name()) === true;
});
```

Configured registries fail closed. A tenant is active only when it is
registered, has a live switchable membership, is visible, passes the optional
authorization callback, and passes the optional entitlement hook. Invalid,
expired, hidden, unknown, unauthorized, and blocked tenants produce immutable
inactive contexts with deterministic codes. Search, navigation, commands,
dispatch, page rendering, resource rendering, manifests, and the built-in
switcher consume that authorized context; they do not fall back to an
unvalidated request tenant.

`tenantActiveUsing()` may resolve a tenant from host state. Without it, Panel
uses the request tenant and then the preferred live membership. The callback is
pure resolution: Panel does not read or write PHP sessions.

```php
$panel->tenantActiveUsing(
	fn(PanelRequest $request, array $memberships) =>
		ActiveWorkspace::forUser($request->user()?->id)
);

$context=$panel->tenantContext($request);
if(!$context->isAuthorized()){
	return deny($context->code());
}
```

Switching also performs no implicit I/O. The host supplies the only persistence
callback, and a successful result is returned only when that callback explicitly
accepts the transition.

```php
$panel->tenantPersistenceUsing(function($next, $previous, PanelRequest $request): bool {
	ActiveWorkspace::store(
		userId: $request->user()->id,
		tenant: $next->tenantKey(),
	);
	return true;
});

$result=$panel->switchTenant('south', $request);
if(!$result->ok()){
	logger()->notice('Tenant switch rejected', ['code'=>$result->code()]);
}
```

Do not put credentials, session identifiers, or customer data in tenant
metadata. Public tenant contracts still bound and redact metadata, lazy URLs
accept only relative or HTTP(S) targets, lazy badges collapse to safe scalars,
and exception traces retain the exception class plus a tenant hash instead of
the raw tenant or exception message.

#### Onboarding and compensation

Onboarding is an ordered callback pipeline. Each completed step may define a
compensating rollback; when a later step rejects or throws, compensation runs
in reverse completion order. The result records completed and rolled-back step
names without exposing exception messages.

```php
$panel->tenantOnboardingStep(
	'provision-schema',
	apply: fn(PanelTenant $tenant, PanelRequest $request) =>
		TenantProvisioner::create($tenant->name()),
	rollback: fn(PanelTenant $tenant) =>
		TenantProvisioner::remove($tenant->name()),
);

$panel->tenantOnboardingStep(
	'publish-domain',
	apply: fn(PanelTenant $tenant) => DomainPublisher::publish($tenant->name()),
	rollback: fn(PanelTenant $tenant) => DomainPublisher::unpublish($tenant->name()),
);

$result=$panel->onboardTenant(
	PanelTenant::make('acme')->label('Acme'),
	$request,
	idempotencyKey: 'tenant-create:acme:2026-07-13',
);
```

The registry replays the same result for the same idempotency key and pipeline
fingerprint during its lifetime, and rejects reuse for a different tenant or
step set. Hosts running across requests or nodes must also make each external
step durably idempotent in their own transaction boundary. Apply callbacks
receive the idempotency key as their fifth argument; rollback callbacks receive
it as their sixth argument after the successful step metadata. Panel
deliberately does not invent a database, distributed lock, or provisioning
store.

#### Storage namespaces

`tenantStorageScope()` creates a storage descriptor, not a directory. Tenant
and namespace segments reject traversal, encoded traversal, controls, padding,
reserved device names, repository control directories, and unsupported
characters. Filesystem resolution requires an existing base and tenant root,
dereferences existing links and junctions, rejects aliased tenant roots, and
keeps every resolved path inside the selected tenant root.

```php
$scope=$panel->tenantStorageScope('north', ['imports', 'orders'], $request);

$scope->relativeRoot();                  // tenants/north/imports/orders
$scope->namespaceKey();                   // north:imports:orders
$target=$scope->resolvePath($storageRoot, 'batch-42.csv');

if(!$scope->containsPath($storageRoot, $target)){
	throw new RuntimeException('Storage path is outside the tenant scope.');
}
```

Resolve again at the point of use and keep creation, permissions, locks,
encryption, object storage, and retention in the host storage adapter. This
avoids time-of-check/time-of-use assumptions and keeps Panel free of hidden I/O.

The optional `tenantEntitlementUsing()` hook accepts a boolean, a recognized
active status (`active`, `enabled`, `entitled`, `trialing`, or `grace`), or a
sanitized status array with an explicit `allowed` boolean. Unknown statuses fail
closed. It is a local authorization input only: Panel makes no billing, payment,
subscription, or network call.

Actions can also have their own authorizer:

```php
$action=Panel::action('publish')
	->authorize(fn($record, $user) => $user?->can('publish') === true)
	->handle(fn($record) => publish_record($record));
```

Generated pages render non-bulk actions in the resource toolbar and beside each
record. Action targets are generated from the current panel URL builder and
submit with `POST` by default. Actions marked with `requiresConfirmation()` add a
Panel confirmation step. The generated button carries a confirmation marker, and
the server refuses to execute confirmed actions when that marker is missing. When
modal actions are enabled, confirmation renders in the Panel dialog instead of a
browser prompt.

Actions can define their own fields. When fields are present, the first action
request renders an action form. The confirmed form submission validates those
fields and passes their values to the handler:

```php
Panel::action('reject')
	->tone('danger')
	->field(Panel::field('reason', 'textarea')->required())
	->handle(function($record, array $data){
		return reject_listing($record, $data['reason']);
	});
```

Action fields use the same field controls, sections, validation, and visibility
rules as resource forms. Use `visibleOn('action')` or `visibleUsing()` for
action-specific field behavior.

## Utility Injection

Panel evaluates action, field, and column callbacks through the same utility
resolver. Existing positional callbacks continue to work, but callbacks can now
ask for utilities by parameter name or type:

```php
Panel::action('assign')
	->handle(function(array $data, Resource $resource, PanelRequest $request, Action $action){
		return [
			'resource'=>$resource->name(),
			'assignee'=>$data['assignee'] ?? null,
			'operation'=>$request->operation(),
			'action'=>$action->name(),
		];
	});
```

Common named utilities include `record`, `data`, `request`, `resource`,
`action`, `field`, `column`, `operation`, `mode`, `result`, `exception`,
`meta`, `get`, and `set`. Aliases such as `state`, `values`, `formData`,
`model`, `row`, `arguments`, `schemaGet`, and `schemaSet` map to the same
canonical utilities.

The `get` utility reads from submitted data first, then the record, then the
request input:

```php
Panel::field('next_step', 'select')
	->optionsUsing(function(callable $get){
		return NextSteps::for($get('status'));
	});

Panel::column('customer')
	->stateUsing(fn(callable $get) => trim($get('first_name', '').' '.$get('last_name', '')));
```

Standalone code can use the same resolver:

```php
Panel::evaluate(
	fn(PanelRequest $request, Resource $resource) => [$request->operation(), $resource->name()],
	['request'=>$request, 'resource'=>$resource]
);
```

Actions also have a lifecycle around the handler:

```php
Panel::action('approve')
	->mutateFormDataUsing(fn(array $data) => array_map('trim', $data))
	->afterValidateUsing(function(PanelFormState $state){
		return $state->value('reason')===''
			? $state->withError('reason', 'Add the approval reason.')
			: $state;
	})
	->beforeActionUsing(function($record, array $data){
		if(($record['status'] ?? '')!=='review'){
			return PanelLifecycleResult::halt('Only records in review can be approved.');
		}
		return null;
	})
	->handle(fn($record, array $data) => approve_record($record, $data))
	->afterActionUsing(function($result){
		$result['notifications'][]=PanelNotification::success('Approval recorded.');
		return $result;
	})
	->failure(fn(Throwable $error) => [
		'message'=>'Approval could not be completed.',
		'notification'=>PanelNotification::error($error->getMessage(), 'Action failed'),
	]);
```

`beforeValidateUsing()` runs before an action form is submitted.
`afterValidateUsing()` receives a `PanelFormState` and may return a replacement
state with extra field errors or a `PanelLifecycleResult`. `mutateFormDataUsing()`
runs after validation and before the action handler. `beforeActionUsing()` may
return `null` / `true` to continue, a normal action result to deliberately skip
the handler, or `PanelLifecycleResult::halt()` / `PanelLifecycleResult::redirect()`
to stop with first-class Panel lifecycle metadata. `afterActionUsing()` can
replace or enrich the handler result before redirects and notifications are
resolved. `failure()` hooks can convert thrown exceptions into normal action
results; unhandled exceptions render a Panel failure page and are recorded in the
Panel trace instead of breaking routing. The older `mutateDataUsing()`,
`before()`, and `after()` aliases remain available for compact actions.

Every generated action also owns a `PanelActionState` snapshot. The state is
route-free and follows the action from authorization through form display,
validation, mutation, execution, lifecycle halts, redirects, and failure pages.
It includes the action definition, mode (`action`, `bulk_action`, or
`page_action`), selected-record count, record key when available, current
`PanelFormState`, submitted data keys, lifecycle result, handler result, and a
small stage marker such as `form`, `validated`, `mutated`, or `completed`.

```php
$state=$action->state(
	record: $record,
	request: $request,
	resource: $resource,
	mode: 'action'
);

if($state->hasForm() && $state->valid()){
	$keys=array_keys($state->data());
}
```

Generated resource actions, bulk actions, and page actions expose this snapshot
in `PanelPageResult` data as `action_state` and record `action.state` /
`page_action.state` trace events. This gives Flightdeck, tests, and reactive
frontends the same server-owned lifecycle model that forms and tables use,
without coupling actions to a specific URL or controller.

Actions can also describe themselves before they render. `Action::manifest()`,
`ActionGroup::manifest()`, and `Panel::actionManifest()` produce a route-free
contract that combines resolved presentation, authorization/visibility state,
modal settings, schema manifest, lifecycle hooks, effects, shortcuts, and bulk
metadata:

```php
$manifest=Panel::actionManifest(
	Panel::action('assign')
		->slideOver('Assign owner')
		->schema(Panel::schema([
			Panel::field('owner')->required(),
			Panel::field('note', 'textarea'),
		]))
		->refresh(['widgets', 'table:orders'])
		->dispatchBrowserEvent('orders:assigned'),
	record: $order,
	request: $request,
	resource: $orders,
	mode: 'action'
);

$manifest['presentation'];  // label, icon, tone, badge, tooltip
$manifest['interaction'];   // modal, form, confirmation, shortcuts, bulk flags
$manifest['form'];          // full schema manifest for the action form
$manifest['effects'];       // refresh targets, browser events, modal control
$manifest['lifecycle'];     // hooks, mutators, handler and guard flags
```

This is the action equivalent of the schema manifest: a generated renderer,
Reactor island, Flightdeck panel, test, or package can inspect how an operation
behaves without scraping HTML or assuming a route.

Dashboard navigation is grouped automatically from resources, pages, and
navigation items. Entries without a group appear under `Workspace`; grouped
entries are ordered by their lowest `sort()` value and then by group label:

```php
Panel::resource('orders')->group('Commerce')->sort(10);
Panel::resource('products')->group('Commerce')->sort(20);
Panel::resource('pages')->group('Content')->sort(30);
```

Navigation cards can show short descriptions and badges. Badges may be static
values or lazy callbacks resolved when the dashboard is rendered:

```php
Panel::resource('orders')
	->navigationDescription('Fulfillment and exception queues')
	->navigationBadge(fn(PanelRequest $request, Resource $resource) => OrderRepository::openCount())
	->navigationBadgeTone('warning');

Panel::page('imports')
	->navigationDescription('Incoming supplier files')
	->navigationBadge('3 failed')
	->navigationBadgeTone('danger');
```

Host-owned navigation can be registered without creating a page or resource:

```php
Panel::registerNavigationItem(
	Panel::navigationItem('billing_portal')
		->label('Billing Portal')
		->group('Operations')
		->icon('credit-card')
		->url('/billing')
		->badge(fn(PanelRequest $request) => BillingRepository::openInvoiceCount($request->user()))
		->badgeTone('warning')
);

Panel::registerNavigationItem(
	Panel::nav('status_page')
		->label('Status Page')
		->url('https://status.example.com')
		->newTab()
		->visibleUsing(fn(PanelRequest $request) => $request->user()?->can('view_status') === true)
);
```

Submenus are first-class navigation state. A host can register folder-only
items that group pages and resources without creating a route:

```php
$commerce=Panel::nav('commerce_folder')
	->label('Commerce')
	->group('Operations')
	->icon('shopping-bag')
	->folderOnly();

$fulfillment=Panel::nav('fulfillment_folder')
	->label('Fulfillment')
	->parent($commerce)
	->icon('package-check')
	->folderOnly();

Panel::registerNavigationItems([$commerce, $fulfillment]);

Panel::resource('orders')
	->navigationParent('fulfillment_folder')
	->navigationDescription('Review demand and move fulfillment.');

Panel::page('command_center')
	->navigationParent($commerce);
```

`NavigationItem::child()` can also define explicit inline children. The
normalized `PanelNavigationState` recursively marks active descendants, counts
nested entries, keeps orphaned entries visible if a parent is not registered,
and exposes `allEntries()` for command palettes or host search.

Every generated navigation tree also owns a `PanelNavigationState` snapshot.
The snapshot normalizes resource, page, and host-owned entries into the same
shape, marks the active entry for the current request, groups entries for the
sidebar and dashboard, and can carry global-search discovery metadata:

```php
$request=PanelRequest::fromArray(['resource'=>'orders']);
$state=Panel::navigationState($request, [
	'query'=>'packing',
	'results'=>Panel::globalSearch('packing', $request),
]);

$groups=$state->groups();
$active=$state->active();
```

Generated dashboards expose this snapshot as `navigation_state` in
`PanelPageResult::data()`, `Panel::describe()` includes it in the manifest, and
Flightdeck records it through `navigation.state`. This keeps navigation
route-agnostic while still giving tests, debug tools, and reactive clients a
single state model.

## Dashboard Widgets

Widgets render above the resource directory on the generated dashboard. Static
values are stored as-is. Callable values are resolved when the dashboard is
rendered, which keeps expensive counts out of registration time.

```php
Panel::registerWidget(
	Panel::stat('open_orders', fn() => OrderRepository::openCount())
		->label('Open orders')
		->description('Orders awaiting fulfillment')
		->tone('warning')
		->icon('package')
		->url(PanelConfig::resourceUrl('orders', '', ['status'=>'open']))
		->sort(10)
);

Panel::registerWidget(
	Panel::widget('gross_volume')
		->value(fn() => money_format_compact(SalesRepository::todayTotal()))
		->label('Gross volume')
		->tone('success')
);
```

Widget callbacks receive the current `PanelRequest` and the `Widget` instance:

```php
Panel::stat('active_users', fn($request, $widget) => UserRepository::activeCount());
```

`Widget::state()` resolves the widget into a `PanelWidgetState`. Dashboard and
custom-page renderers use this state internally, while `PanelManager::widgets()`
and `PanelPage::resolvedWidgets()` still return render-compatible arrays for
older integrations.

```php
$state=Panel::widget('revenue_flow', 'chart')
	->chart('area')
	->data(['Mon'=>12, 'Tue'=>19])
	->state($request);

$state->type();        // chart
$state->chart();       // labels, datasets, height, point count
$state->jsonSerialize(); // renderer-compatible widget payload plus state metadata
```

Generated dashboards expose `widget_states` in `PanelPageResult::data()` and
record `widgets.state` trace events. Resource status widgets are wrapped in the
same state object, so dashboard cards, custom page widgets, and generated status
stats share one lifecycle contract.

### Chart Widgets

Chart widgets are regular widgets with `type('chart')` or `chart()`. They render
as responsive, server-generated SVG, so dashboards can show graph cards without a
JavaScript chart dependency.

```php
Panel::registerWidget(
	Panel::widget('revenue_flow', 'chart')
		->label('Revenue flow')
		->value(fn() => money(OrderRepository::todayRevenue()))
		->description('Gross demand by operating window')
		->chart('area')
		->labels(['06:00', '09:00', '12:00', '15:00', '18:00'])
		->dataset('Revenue', fn() => OrderRepository::revenueSeries(), 'primary')
		->height(220)
);

Panel::registerWidget(
	Panel::widget('risk_mix')
		->label('Risk mix')
		->chart('donut')
		->data(fn() => [
			'Low' => 42,
			'Medium' => 17,
			'High' => 8,
			'Critical' => 3,
		])
		->tone('danger')
);
```

Supported chart types are `line`, `area`, `bar`, `donut`, and `sparkline`.
`labels()`, `data()`, and `dataset()` accept arrays or callbacks. Dataset callbacks
receive the same request-aware resolution lifecycle as widget values.

## Custom Pages

Custom pages are for internal workflows that are not CRUD resources: import
queues, reconciliation tools, support consoles, migration previews, and other
operator screens. They share the Panel dashboard navigation and authorization
flow, but own their rendered content.

```php
Panel::registerPage(
	Panel::page('reconciliation')
		->label('Reconciliation')
		->group('Finance')
		->sort(15)
		->content('<section><h2>Today</h2><p>No unmatched payments.</p></section>')
);

Panel::registerPage(
	Panel::page('review_queue')
		->label('Review queue')
		->authorize(fn($ability, $user) => $user?->can('review') === true)
		->renderUsing(function(PanelRequest $request, PanelPage $page, PanelManager $manager){
			$open=ReviewRepository::openItems();

			return [
				'title'=>'Review queue',
				'content'=>render_review_queue($open),
				'data'=>['open_count'=>count($open)],
			];
		})
);
```

The same dispatch flow can render a registered custom page when no resource with the
same name exists. Resources intentionally take precedence so existing CRUD
resource names remain stable. A page renderer may return raw HTML, an array with
`title`, `content`, `status`, `data`, and `notifications`, or a full
`PanelPageResult`.

## Global Search

Global search combines two first-class source types: opt-in resources and
custom `PanelSearchProvider` adapters. Both flow through the same bounded
`PanelSearchCoordinator`, immutable `PanelSearchResult`, and cursor-aware
`PanelSearchPage` contracts. The legacy array-returning
`Panel::globalSearch()` remains compatible; `Panel::globalSearchPage()` adds
completeness, next-cursor, partial-error diagnostics, and execution metadata.

A resource becomes searchable with `globalSearchable()`, then uses
`globalSearchColumns()` or searchable table columns to match array-backed
records. Results link to the generated show page when a record exposes `id`,
`key`, `uuid`, or `name`.

```php
Panel::resource('customers')
	->columns([
		Panel::column('name')->searchable(),
		Panel::column('email')->searchable(),
	])
	->globalSearchable()
	->globalSearchTitleUsing(fn($record) => $record['name'])
	->globalSearchSubtitleUsing(fn($record) => $record['email']);
```

Custom search handlers can delegate to an application repository or search
index. Handlers return either record-like arrays/objects or normalized result
arrays with `title`, `subtitle`, `url`, and `record_key`:

```php
Panel::resource('orders')
	->globalSearchUsing(function(string $query, PanelRequest $request, Resource $resource, int $limit){
		return OrderRepository::panelSearch($query, $limit);
	});
```

`Panel::globalSearch($query, $request, $limit)` returns the same normalized
result shape used by the generated dashboard. Resource handlers may also emit
`score`, `dedupe_key`, `icon`, and JSON-safe `meta`; those fields participate in
the shared ranking pipeline.

Use a custom provider when search is backed by a repository, full-text index,
remote service adapter, or precomputed async index:

```php
Panel::registerSearchProvider(
	PanelSearchProvider::make('operations-index')
		->label('Operations')
		->limit(20)
		->tenantScoped(required: true)
		->visibleUsing(fn(PanelRequest $request) => $request->user() !== null)
		->authorizeUsing(function($user, PanelRequest $request){
			return $user->can('search-operations', $request->tenantKey()) === true;
		})
		->rankUsing(function(PanelSearchResult $result, string $query){
			return SearchRanking::score($query, $result->title(), $result->score());
		})
		->deduplicateUsing(fn(PanelSearchResult $result) => $result->url())
		->pageUsing(function(
			string $query,
			PanelRequest $request,
			PanelSearchProvider $provider,
			int $limit,
			PanelManager $manager,
			PanelSearchContext $context,
		){
			$page=OperationsIndex::search(
				query: $query,
				tenant: $context->tenant(),
				cursor: $context->cursor(),
				limit: $context->providerLimit(),
			);

			return PanelSearchPage::make(
				results: array_map(
					fn($hit) => PanelSearchResult::fromArray([
						'title'=>$hit->title,
						'subtitle'=>$hit->summary,
						'url'=>$hit->panelUrl,
						'record_key'=>$hit->id,
						'score'=>$hit->score,
					]),
					$page->hits,
				),
				nextCursor: $page->nextCursor,
				complete: $page->nextCursor === null,
			);
		})
);
```

Provider callbacks may return a finite iterable of result-like arrays or a
`PanelSearchPage`. Panel deliberately does not poll promises, futures, or jobs
inside an HTTP request. An asynchronous ingestion pipeline should update its
index independently and expose a synchronous, cursor-aware page adapter to the
provider. Cursor accessors preserve the adapter payload, while public
`toArray()`/JSON serialization redacts secret-like keys in cursor arrays.
Applications should use signed opaque strings when a continuation token must be
returned to a client unchanged, and must never put unsigned authority in a
cursor.

Visibility, required tenancy, provider authorization, manager authorization,
and resource policies are evaluated before a registered source executes.
Resolver exceptions deny access and are traced. Provider failures do not take
down successful sources: the returned page is marked partial and contains
bounded, redacted diagnostics. Result URLs reject scriptable/unknown schemes,
metadata is recursively bounded and secret-like keys are redacted, and client
diagnostics never expose exception messages. Search traces retain query length
and exception class rather than raw query text or exception messages.

Ranking is score-descending. Equal scores use provider sort, normalized provider
name, source order, and local result order, so ties are deterministic. The
highest-ranked duplicate wins. Explicit `dedupe_key`/`deduplicateUsing()` values
take precedence, followed by normalized absolute URL, provider+record key, and
provider+text identities. Per-provider results, participating sources,
diagnostics, aggregate candidates, and the public result count all have hard
budgets.

```php
$page=Panel::globalSearchPage(
	'packing',
	$request,
	limit: 12,
	cursor: ['operations-index'=>$priorCursor],
);

$page->results();       // list<PanelSearchResult>
$page->nextCursor();    // provider-keyed cursor map or null
$page->isComplete();    // false when more ranked/index results exist
$page->isPartial();     // true when a participating source degraded
$page->diagnostics();   // bounded JSON-safe adapter diagnostics
iterator_to_array($page); // pages are directly iterable over results
```

`PanelManager`, the static `Panel` facade, and every `PanelInstance` expose
`registerSearchProvider(s)`, `searchProvider()`, `hasSearchProvider()`,
`searchProviders()`, `globalSearch()`, and `globalSearchPage()`. Direct
`PanelSearchProvider::search()`/`searchPage()` calls remain raw adapter test
primitives for backward compatibility. Use `searchAuthorizedPage()` when
calling a provider directly from application code; normal UI code should enter
through the manager/facade coordinator.

Bulk actions render a selection column and receive the selected records as the
first handler argument:

```php
Panel::action('archive')
	->bulk()
	->requiresConfirmation()
	->tone('danger')
	->handle(function(array $records){
		return [
			'message'=>count($records).' projects archived.',
		];
	});
```

## Request Lifecycle

Panel requests are small value objects. They can be captured from the current
HTTP request or passed explicitly by any host surface.

```php
$page=Panel::dispatch([
	'resource'=>'projects',
	'operation'=>'index',
	'query'=>['page'=>1],
]);

echo $page->content();
```

`PanelResponseEmitter::emit($page)` applies the status and headers carried by the
result, then writes the body. Hosts that already own response emission should use
`content()`, `status()`, and `headers()` directly.

Supported operations:

- `index` renders a table.
- `create` renders a blank form.
- `store` runs the resource save handler with form input.
- `edit` renders a form with an existing record.
- `update` runs the resource save handler with form input and the loaded record.
- `show` renders field values.
- `relation` renders one named relation table for a record.
- `action` runs a named resource action.
- `export` downloads the current table view as CSV.
- `import` renders the CSV import form on GET and imports rows on POST.

Custom pages can also expose actions. Page actions render in a toolbar above the
custom content. Actions with fields use the generated form controls; actions
without fields submit directly.

```php
Panel::page('reconciliation')
	->renderUsing(fn() => render_reconciliation_dashboard())
	->widget(
		Panel::stat('unmatched_payments', fn() => ReconciliationRepository::unmatchedCount())
			->label('Unmatched payments')
			->tone('warning')
	)
	->widget(
		Panel::pageWidget('last_settlement')
			->label('Last settlement')
			->value(fn() => ReconciliationRepository::lastSettlementLabel())
			->tone('info')
	)
	->action(
		Panel::action('close_batch')
			->label('Close batch')
			->tone('danger')
			->requiresConfirmation()
			->modalHeading('Close reconciliation batch')
			->modalDescription('Add an audit note before this batch is locked.')
			->modalSubmitLabel('Close batch')
			->modalWidth('lg')
			->field(Panel::field('note', 'textarea')->required())
			->handle(function($record, array $data){
				ReconciliationRepository::closeCurrentBatch($data['note']);
				return Panel::notify('Batch closed.', 'success');
			})
	);
```

Actions support modal intent metadata with `modal()`, `modalHeading()`,
`modalDescription()`, `modalSubmitLabel()`, `modalCancelLabel()`,
`modalWidth()`, `modalSize()`, and `slideOver()`. `modal()` and `slideOver()`
also accept a heading, description, and width so actions can be declared in one
line:

```php
Panel::action('assign')
	->slideOver('Assign owner', 'Move ownership without leaving the table.', 'lg')
	->field(Panel::field('owner', 'select')->options($owners)->required())
	->handle(fn($record, array $data) => Orders::assign($record, $data['owner']));
```

Generated pages progressively enhance form and confirmation actions into dialog
or slide-over interactions by fetching the existing server-rendered action
form. If JavaScript is unavailable, the same URLs still work as full
server-rendered pages without changing handlers.

Confirmation modals use the action `tone()` metadata for their icon and submit
button treatment. Generated resource actions also emit explicit tone metadata
for transitions, restore, duplicate, delete, force delete, approvals, tasks, and
bulk equivalents, with text-based detection kept only as a fallback for older
markup.

Content-only modals use `modalContent()` or `infoModal()`. The content may be
HTML, a stringable value, an associative array rendered as read-only facts, or
a callback. Resource action callbacks receive the current record, request,
resource, and action when those values are available.

```php
Panel::action('snapshot')
	->label('Snapshot')
	->modal('Order snapshot', 'Generated from the selected row.', 'lg')
	->modalContent(fn(array $record) => [
		'Order'=>$record['number'],
		'Status'=>$record['status'],
		'Owner'=>$record['owner'],
	]);
```

Modal actions can declare explicit modal stack behavior with `modalStack()`.
Use `modalStack('push')`, `stackedModal()`, `modalBack()`, or
`preserveModalHistory()` when an action should always preserve the current
dialog and expose a Back control. An action without explicit stack metadata
replaces a top-level modal, but is automatically promoted to `push` when its
trigger lives inside an already-open modal. That nested default makes ordinary
daughter actions reversible without extra configuration. Use `replaceModal()`
to opt into a one-way replacement even inside a modal, and `clearModalStack()`
for terminal actions such as workspace resets.

```php
Panel::action('assign')
	->slideOver('Assign owner', 'Move ownership without leaving the record.', 'lg')
	->stackedModal()
	->backOnModalExit()
	->backToParentModal()
	->fields([
		Panel::field('owner', 'select')->options($owners)->required(),
	])
	->handle(fn(array $record, array $data) => Orders::assign($record, $data['owner']));

Panel::action('reset_workspace')
	->modal('Reset workspace')
	->clearModalStack()
	->requiresConfirmation()
	->handle(fn() => Workspace::reset());
```

`backOnModalExit()` controls dismissal: Cancel, Escape, and the close control
restore the parent snapshot when one exists. `backToParentModal()` controls a
successful submission: after the mutation and requested refreshes complete,
the daughter modal is popped and the live parent modal is restored. For other
dismissal policies, use `closeOnModalExit()` or `stayOnModalExit()`. For other
successful-submit policies, use `modalNavigation('close')` or
`modalNavigation('stay')`.

The same action URLs remain usable as full server-rendered pages. Supply a
panel-local `return_to` query or form value when opening a daughter page; Panel
validates it, preserves it through validation errors, and uses it for Cancel
and the successful POST redirect:

```php
$returnTo=PanelConfig::resourceUrl(
	$orders,
	'show/'.rawurlencode((string)$orderId),
);

$createSellerUrl=PanelConfig::resourceUrl($sellers, 'create', [
	'return_to'=>$returnTo,
]);
```

External, protocol-relative, traversal, and control-character return URLs are
rejected. Unsigned migration targets must also remain inside the mounted Panel
surface. Generated relation Create links already attach this `return_to`
fallback automatically.

### Signed navigation intents

Production surfaces should bind every cross-page and modal return to a signed,
short-lived navigation intent. A token covers the canonical return target,
audience, panel and surface names, tenant and principal identities, operation,
outcome, issue/not-before/expiry times, a nonce, and the bounded parent-modal
chain. Changing either hidden field, replaying a consumed nonce, changing user
or tenant, or moving the token to another surface fails closed before a
mutation handler runs.

Configure at least 32 bytes of application-managed secret material. Keep the
secret outside source control; only the public key id and secret-free capability
metadata appear in Panel manifests.

```php
$panel->navigationIntentKey(
	$_ENV['PANEL_NAVIGATION_KEY'],
	'2026-07',
	[
		'surface'=>'operations',
		'ttl'=>900,
		'unsigned_migration'=>'same_panel',
	]
);
```

For rotation, keep the retiring verification key in the provider until every
token it issued has expired, while selecting the new id for issuance:

```php
use Dataphyre\Panel\PanelNavigationSigningKey;
use Dataphyre\Panel\PanelStaticNavigationKeyProvider;

$keys=new PanelStaticNavigationKeyProvider([
	'2026-06'=>new PanelNavigationSigningKey(
		'2026-06',
		$_ENV['PANEL_NAVIGATION_KEY_2026_06'],
		strtotime('2026-06-01T00:00:00Z'),
		strtotime('2026-08-01T00:00:00Z'),
	),
	'2026-07'=>new PanelNavigationSigningKey(
		'2026-07',
		$_ENV['PANEL_NAVIGATION_KEY_2026_07'],
		strtotime('2026-07-01T00:00:00Z'),
	),
], '2026-07');

$panel->navigationIntentKeyProvider($keys, [
	'surface'=>'operations',
	'unsigned_migration'=>'disabled',
]);
```

Multi-process deployments can implement `PanelNavigationKeyProvider` against a
secret manager and `PanelNavigationReplayGuard` against an atomic shared store.
`PanelInMemoryNavigationReplayGuard` is intentionally process-local and is best
suited to tests or single-process applications. Attach a production guard with
`navigationIntentReplayGuard()` when an intent must be single-use.

Actions can narrow the signed context fluently. Generated action, page, form,
relation, save, cancel, and exit flows then carry the paired hidden
`return_to` and `navigation_intent` fields automatically:

```php
Panel::action('assign')
	->slideOver('Assign owner')
	->navigationIntent(true, 'assign', 'saved')
	->navigationAudience('operations.navigation')
	->navigationReturnTarget('/panel/orders?view=assigned')
	->backToParentModal();
```

The modal runtime sends its live same-origin workspace target on the daughter
GET. The server canonicalizes that target and signs the exact value returned in
the form. On submit, the browser preserves the pair unchanged. It never signs
targets and there is no client signing endpoint. Without a signed pair, the
legacy runtime may replace `return_to` with its live same-panel URL only while
the migration policy permits it.

#### Unsigned migration

`unsigned_migration => 'same_panel'` is a temporary compatibility mode. It
accepts only unsigned, unprivileged same-panel navigation and emits a
deprecation trace. Privileged or mutating requests, cross-context targets, and
external targets still fail closed. Move generated and custom forms to paired
fields, verify any hand-built page links, and then switch to:

```php
$panel->navigationIntentMigration('disabled');
```

Do not disable the feature to complete this migration: `disabled` here is the
unsigned-migration policy, so configured signed intents remain required. A
missing or inactive configured key prevents privileged navigation rather than
silently trusting `return_to`. Applications can inspect
`navigationIntentManifest()` for rollout diagnostics; manifests include key
ids, time bounds, policies, and capabilities but never signing secrets or raw
nonces.

Actions can also declare client effects that are returned with fragment and
modal responses. Effects are hints for the Panel shell after the server action
has completed; they do not replace the handler result or the normal fallback
redirect.

```php
Panel::action('assign')
	->slideOver('Assign owner')
	->stackedModal()
	->refreshTable('orders')
	->refreshWidgets()
	->dispatchBrowserEvent('orders:assigned')
	->handle(fn(array $record, array $data) => [
		'message'=>'Order was assigned.',
		'effects'=>[
			'refresh'=>['table:orders', 'widgets'],
			'event'=>[
				'name'=>'orders:assigned',
				'detail'=>['order'=>$record['number']],
			],
		],
	]);
```

Available helpers are `refresh()`, `refreshPanel()`, `refreshTable()`,
`refreshWidgets()`, `refreshNavigation()`, `withoutRefresh()`, `closeModal()`,
`keepModalOpen()`, `modalNavigation()`, `backToParentModal()`, and
`dispatchBrowserEvent()`. Stack and dismissal helpers include `modalStack()`,
`stackedModal()`, `replaceModal()`, `clearModalStack()`, `modalExit()`,
`backOnModalExit()`, `closeOnModalExit()`, and `stayOnModalExit()`. Returned
`effects` from the handler are merged with the action definition, so a generic
action can declare its usual behavior while a specific outcome can add an event
or override whether the modal goes back, closes, or stays open.

Refresh targets are patch-aware. `refreshPanel()` updates the full Panel
surface. Narrower targets such as `refreshTable('orders')`, `refreshWidgets()`,
or `refreshNavigation()` ask the browser to replace only the matching refresh
regions when the returned page contains them. If a target cannot be matched, the
client falls back to the normal full Panel refresh instead of leaving stale UI
behind.

Custom pages and plugins can expose their own refreshable islands with
`refreshRegion()` or `refreshIsland()`. Actions can then target them with
`refresh('region:system_health')` or `refresh('island:session_pulse')`.

```php
Panel::page('command_center')
	->content(fn() => Panel::refreshIsland(
		'session_pulse',
		'<section class="dp-panel-card">...</section>'
	))
	->action(
		Panel::action('simulate_peak')
			->refresh(['widgets', 'island:session_pulse'])
			->handle(fn() => Operations::simulatePeak())
	);
```

Use `liveRefreshRegion()` or `liveRefreshIsland()` when an island should keep
itself fresh without a full panel redraw. The browser quietly requests a
fragment for only that named target, preserves scroll and focus, and skips the
poll while the user is typing, selecting rows, editing unsaved forms, viewing a
modal, offline, hidden, or paused from the live control.

```php
Panel::page('command_center')
	->content(fn() => Panel::liveRefreshIsland(
		'session_pulse',
		'<section class="dp-panel-card">...</section>',
		15000,
		['aria-live' => 'polite']
	));
```

The lower-level `refreshRegion()` helpers also accept
`data-dp-panel-refresh-interval`, `refresh_interval`, `live_interval`,
`interval_ms`, `poll_interval`, or `poll` attributes. Values below `1000` are
treated as seconds; larger values are milliseconds.

When an island should expose its own controls, render `refreshControls()` next
to it. The generated controls refresh only that target and can pause or resume
that island without disabling the rest of the Panel.

```php
Panel::liveRefreshIsland('session_pulse', $html, 15000)
	.Panel::refreshControls('session_pulse', 'island', [
		'label' => 'Session pulse updates',
		'status' => 'Refreshes every 15s',
	]);
```

Use `lazyRefreshRegion()` or `lazyRefreshIsland()` for expensive sections that
should not block the first page render. The first response emits a placeholder
with the same refresh key; once the Panel is interactive, the browser requests a
deferred fragment for that target and swaps only the island. The deferred
request uses `__panel_defer` internally and does not alter browser history.

```php
Panel::page('command_center')
	->content(fn() => Panel::lazyRefreshIsland(
		'attention_stream',
		fn() => Operations::attentionStreamHtml(),
		'<section class="dp-panel-card dp-panel-lazy-placeholder">Loading...</section>'
	));
```

Lazy regions can wait until they are near the viewport by passing
`lazy_visible`, `visible`, `when_visible`, or `load_when_visible`. The default
look-ahead margin is `360` pixels and can be changed with `lazy_margin`,
`visible_margin`, or `load_margin`.

```php
Panel::lazyRefreshIsland(
	'attention_stream',
	fn() => Operations::attentionStreamHtml(),
	null,
	['lazy_visible' => true, 'lazy_margin' => 260]
);
```

Use `lazy_manual`, `manual`, or `load_on_interaction` when the placeholder should
wait for a user action. Buttons or controls that target an unloaded lazy region
automatically add the deferred fragment hint, so they fetch the real island
instead of re-rendering the placeholder. If a lazy load fails, the placeholder
is unlocked and can be retried.

```php
Panel::lazyRefreshIsland(
	'attention_stream',
	fn() => Operations::attentionStreamHtml(),
	'<section class="dp-panel-card dp-panel-lazy-placeholder">
		<h2>Attention stream</h2>
		<button type="button" data-dp-panel-refresh-now="island:attention_stream">
			Load recommendations
		</button>
	</section>',
	['lazy_manual' => true]
);
```

Manual lazy regions can warm themselves before the user clicks. Pass
`lazy_prefetch`, `prefetch`, `prefetch_on_hover`, or `load_on_hover` to start
the deferred load on pointer hover, keyboard focus, or touch. Use
`lazy_prefetch_delay`, `prefetch_delay`, or `hover_delay` to tune the delay.

```php
Panel::lazyRefreshIsland(
	'attention_stream',
	fn() => Operations::attentionStreamHtml(),
	$placeholder,
	[
		'lazy_manual' => true,
		'lazy_prefetch' => true,
		'lazy_prefetch_delay' => 120,
	]
);
```

Generated resource controls use the same modal transport. Create, edit, import,
and bulk update links open as slide-over forms, view links open as read-only
record surfaces, and generated transition, duplicate, restore, delete, permanent
delete, and their bulk equivalents open as confirmation modals. Board card
titles, relation-manager create/view controls, and resource-backed global search
results use the same modal metadata so users keep their table, board,
dashboard, or parent-record context. These controls still point to normal Panel
URLs, so they remain usable as full pages when JavaScript is unavailable or a
modal request cannot be completed.

The client classifies modal content at runtime as form, confirmation, record
surface, generated facts, or plain content. That classification controls the
interior presentation, scroll affordances, sticky form actions, relation/table
fit, and tone accents while preserving the same server-rendered source.
GET-backed modal triggers expose an "Open full page" control in the modal
header so users can leave the dialog when the work naturally grows beyond a
quick review or edit. They also expose Copy link and Refresh controls so
record previews and search results can be shared or reloaded without leaving
the dialog. POST confirmations and content-only modals omit those controls
because they do not have a safe standalone read URL.
Modal form submits and confirmation actions keep an inline status strip while
the request is running, then briefly acknowledge success before closing and
refreshing the underlying workspace. Validation responses can replace the modal
form in place so the user stays in context.
Dialogs also include an Expand/Normal control, with `Alt+Enter` as a keyboard
toggle, so dense generated forms and record surfaces can temporarily use the
full viewport without abandoning the modal flow.

Page widgets use the same `Widget` definitions as dashboard widgets. They are
resolved for the current `PanelRequest` and rendered above the custom page
content.

Custom pages can also expose local table sections. Page tables reuse Panel
columns and cell formatting without becoming CRUD resources:

```php
Panel::page('review_queue')
	->table(
		Panel::pageTable('pending_reviews')
			->label('Pending reviews')
			->columns([
				Panel::column('title')->truncate(60),
				Panel::column('risk')->badge([
					'high'=>'danger',
					'medium'=>'warning',
					'low'=>'success',
				]),
				Panel::column('submitted_at')->datetime(),
			])
			->filters([
				Panel::pageFilter('risk', 'select')->options([
					'high'=>'High',
					'medium'=>'Medium',
					'low'=>'Low',
				]),
				Panel::pageFilter('submitted_at')->dateRange(),
			])
			->views([
				Panel::view('high_risk')
					->tone('danger')
					->filterValue('risk', 'high'),
				Panel::view('recent')
					->default()
					->search('refund')
					->range('submitted_at', date('Y-m-d', strtotime('-7 days')), null),
			])
			->groups([
				Panel::tableGroup('risk')
					->label('Risk')
					->default()
					->collapsible()
					->descriptionUsing(fn(string $key, array $records) => count($records).' records'),
				Panel::tableGroup('owner')->label('Owner')->collapsible(),
			])
			->recordsUsing(fn(PanelRequest $request) => ReviewRepository::pendingFor($request->user()))
			->defaultSort('submitted_at', 'desc')
			->limit(25)
	);
```

Page table filters use the same `TableFilter` definitions as resources. Their
query parameters are scoped with the table name, so two page tables can use the
same filter names without affecting each other. Page tables also render scoped
search controls. Page table views use the same `TableView` definitions as
resources, and their search/filter query defaults are scoped in the same way.
Page table groups use the same `TableGroup` definitions as generated resource
tables and write `table_group=` query parameters using the table prefix. A
`high_risk` view on the `pending_reviews` table writes
`pending_reviews_view=high_risk` and can default `pending_reviews_risk=high`
without affecting another table on the page.

Forms submit to the generated lifecycle endpoints by convention. Persistence is
explicit through `saveUsing()`:

```php
$resource=$resource->saveUsing(function(array $data, mixed $record, string $mode){
	return ProjectRepository::save($data, $record);
});
```

CSV imports parse to a preview page before records are saved. The preview shows
mapped columns, skipped columns, sample rows, and field validation issues. The
confirmed import validates again; invalid rows block the import rather than
partially saving a broken file. When CSV headers do not match resource field
names or labels, the preview exposes a column mapping control so each CSV column
can be mapped to a field or skipped. The import form also exposes a CSV template
download built from the resource's writable fields, with sample values when the
field metadata can provide them. Imports can reuse `saveUsing()` row by row with
mode `import`, or use a batch import handler:

```php
$resource=$resource->importUsing(function(array $rows){
	foreach($rows as $row){
		ProjectRepository::createFromImport($row);
	}

	return ['imported'=>count($rows)];
});
```

Import handlers receive normalized row arrays keyed by resource field names after
preview confirmation. Returning `imported`, `failed`, `success`, `message`,
`notification`, or `redirect` follows the same result conventions as saves and
actions.

`PanelPageResult` carries the rendered content, HTTP status, headers, and a
machine-readable data payload. If the HTTP framework is loaded, `toResponse()`
returns a `Dataphyre\Http\Response`.

## Generated Tables

Generated index pages support the table metadata declared on columns:

- `searchable()` columns participate in the `?q=` search filter.
- If no columns are marked searchable, search falls back to the visible columns.
- `sortable()` columns render clickable headers using `?sort=` and `?dir=`.
- `defaultSort('column', 'desc')` sets the generated table order when no
  explicit sort is present in the request.
- column types format common values automatically: boolean, date, datetime,
  money/currency, percent/percentage, json, array, badge, url, and email.
- `money()`, `date()`, `datetime()`, `booleanLabels()`, `badge()`, `url()`,
  `email()`, `truncate()`, and `limit()` are convenience helpers for common
  column metadata.
- `editable()`, `inlineEditable()`, `editableType()`, and `editableOptions()`
  make a generated table cell writable in place. Inline edits post the single
  field through the resource's `saveUsing()` handler with the `inline_update`
  mode, so normal save hooks, notifications, authorization, and redirects still
  apply. Text, number, select, and checkbox controls are rendered natively.
- `perPage()` sets the default page size; `?per_page=` may override it per
  request.
- `perPageOptions()` controls the generated row-count selector. The selector
  preserves active search, filters, sort, visible columns, and density.
- `views()` / `view()` add one-click table slices above the generated table.
  Views are applied before filters, search, sorting, pagination, summaries, and
  CSV export.
- `tableGroups()` / `tableGroup()` add one-click row grouping without changing
  the data source. Groups can read a record field directly, use `stateUsing()`
  for a computed grouping key, use `labelUsing()` for section labels, set
  `direction('desc')`, add section context with `description()` or
  `descriptionUsing()`, make sections interactive with `collapsible()`, start
  them closed with `collapsed()`, attach per-group metrics with `summary()` or
  `summaries()` using the same `TableSummary` definitions as table summaries,
  attach drilldown links with `action()` or `actions()`, and mark a default
  group with `default()`. Page tables use `groups()` / `group()` because they
  do not have a navigation group.
- `filters()` / `filter()` add generated controls. Active filters are preserved
  across search, sort, and pagination links.
- Generated filter labels, empty select choices, boolean choices, and range
  placeholders use Panel localization keys such as `common.any`,
  `common.yes`, `common.no`, `table.filter_from`, and `table.filter_to`.
- Filters expose first-class active indicators. Use `indicator()`,
  `indicatorUsing()`, and `indicatorTone()` when the active chip should say
  something more useful than the raw query value. Indicator callbacks can return
  one chip or several chips, and each chip may declare the exact query keys it
  clears, which lets range filters clear their `from` and `to` sides
  independently.
- Filters can be request- and operation-aware with `visible()`, `hidden()`,
  `visibleUsing()`, `hiddenUsing()`, `visibleOn()`, and `hiddenOn()`. Hidden
  filters do not render controls, do not apply stale query parameters, and do
  not create active indicators.
- `summaries()` / `summary()` add generated metrics above the table.
  Array-backed resources calculate summaries after filters, search, and sort,
  before pagination. Paginated query objects summarize the records supplied to
  the page.
- `toggleable(false)` keeps a column visible. Other columns can be shown/hidden
  through the generated column picker, backed by `?visible_columns=`.
- `hiddenByDefault()` keeps a toggleable column out of the initial table while
  still making it available in the column picker. `visibleByDefault(false)` is
  the equivalent explicit form.
- `visible()`, `hidden()`, `visibleUsing()`, `hiddenUsing()`, `visibleOn()`,
  and `hiddenOn()` remove a column from generated tables before saved
  preferences are applied. Use them for request-, tenant-, operation-, or
  feature-aware table layouts; hidden columns do not appear in the column picker
  for that request.
- table density is controlled with `?density=compact`, `normal`, or
  `comfortable`, and is preserved across generated table links.
- column visibility and density are persisted in the PHP session per resource
  once a user changes them. `?reset_table_view=1` clears the saved table view.
- table metadata includes local saved-view controls. A saved view captures the
  current generated table URL, so search, filters, active view, sort, page size,
  density, page, and visible columns can be recalled without changing server
  definitions.
- table columns can be resized in the browser. Width preferences are stored in
  local storage per host path, page heading, and table label; they do not affect
  CSV/JSON exports or server-side column metadata.
- focused rows can be previewed with `P`. The preview uses visible table cells,
  keeps row actions available, and can copy the row as JSON or CSV.
- `Export CSV` downloads the current filtered/searched/sorted view and respects
  visible columns. `Export JSON` uses the same view and returns formatted row
  data plus column metadata.
- `Export selected CSV` and `Export selected JSON` appear in the
  selected-records action bar and download only the checked rows while
  preserving visible columns.
- `Import CSV` appears when `importUsing()` is present, or when a resource has
  form fields and `saveUsing()`. Uploaded or pasted CSV rows are mapped by field
  name or label. CSV without headers uses form field order.
- Rows with an `id`, `key`, `uuid`, or `name` value get View and Edit links.
- Empty tables show a create action; filtered empty states show a reset action.
  `emptyState()` and `filteredEmptyState()` let a resource replace those
  defaults with a heading, description, optional icon, and optional action.
  `emptyStateAction()` and `filteredEmptyStateAction()` can attach actions
  later. Action URLs may be static strings or callbacks that receive the current
  request, resource, table, and whether the table is constrained.

```php
$resource=$resource
	->emptyState(
		'No orders yet.',
		'When demand starts flowing, this table will expose review lanes.',
		'Create order',
		'/debug?resource=orders&operation=create',
		'shopping-bag'
	)
	->filteredEmptyState(
		'No orders match this slice.',
		'Clear the search or reset the current view.',
		'Reset table view',
		fn($request, $resource) => PanelConfig::resourceUrl($resource, '', ['view'=>'all']),
		'filter-x'
	);
```

Tables expose the same resolved state as the generated renderer:

```php
$state=$resource->tableState($request, $records);

$state->allColumns();          // every declared or inferred column
$state->visibleColumns();      // columns after request/session visibility
$state->visibleColumnNames();
$state->summaries();
$state->query();
$state->filterValues();
$state->sort();                // ['column'=>'created_at', 'direction'=>'desc']
$state->activeView();
```

`ResourceTable::state()` and `Resource::tableState()` return immutable
`PanelTableState` snapshots. The generated index page and export pipeline use
the same column resolution model, so table rendering, JSON/CSV export, saved
views, and future reactive table updates all share one table state shape.

Tables can also describe their whole contract before rendering. Use
`ResourceTable::manifest()`, `PageTable::manifest()`, `Resource::tableManifest()`,
or `Panel::tableManifest()` to inspect columns, filters, views, groups,
summaries, row behavior, pagination, sort defaults, action surfaces, and
resource data operations:

```php
$manifest=Panel::tableManifest($orders_resource, request: $request);

$manifest['columns'];      // searchable, sortable, toggleable, computed columns
$manifest['filters'];      // filter controls, ranges, dynamic options
$manifest['views'];        // saved server-defined queues
$manifest['groups'];       // grouping controls, summaries, group actions
$manifest['row_behavior']; // clickable rows, modal row targets, previews
$manifest['operations'];   // import, duplicate, delete, transitions, bulk update
$manifest['actions'];      // action manifests attached to the resource table
```

The table manifest is the table equivalent of schema and action manifests: a
custom renderer, Flightdeck tab, test, generated documentation page, or Reactor
table island can understand a table without scraping generated HTML or assuming
that the table is hosted at a particular URL.

Resources can describe their complete generated surface too. Use
`Resource::resourceManifest()`, `Panel::resourceManifest()`, or
`PanelInstance::resourceManifest()` when an external renderer, test, Flightdeck
tab, or documentation generator needs the whole resource contract:

```php
$manifest=Panel::resourceManifest('orders', $request);

$manifest['identity'];       // record key, title, subtitle, and URL strategy
$manifest['navigation'];     // group, icon, badge, hidden state, route target
$manifest['forms'];          // create, edit, and bulk-update schema manifests
$manifest['infolist'];       // show-surface schema manifest
$manifest['table'];          // table manifest for the index
$manifest['actions'];        // action manifests attached to the resource
$manifest['relations'];      // relation managers and their table manifests
$manifest['record_surface']; // alerts, notes, activity, messages, files, tasks
$manifest['operations'];     // imports, bulk updates, duplicate/delete/restore
```

The resource manifest is intentionally route-free. It is the equivalent of
asking “what can this resource do?” rather than “what HTML did this URL emit?”.
That keeps custom shells, Reactor islands, generated docs, tests, and
Flightdeck lifecycle introspection aligned with the same source of truth.

Relation managers also expose a standalone contract. Use
`RelationManager::manifest()`, `Panel::relationManifest()`, or
`PanelInstance::relationManifest()` when a nested record surface needs to be
inspected without walking through the parent resource manifest:

```php
$manifest=$panel->relationManifest($orders->relationManagers()['items'], $request);

$manifest['presentation'];  // labels, dynamic badges, parent title, empty state
$manifest['data'];          // related resource, storage table, key mapping
$manifest['operations'];    // create, attach, detach, read-only, custom handlers
$manifest['authorization']; // whether an authorizer exists
$manifest['facts'];         // relation-level summaries
$manifest['table'];         // table manifest for the nested records
$manifest['capabilities'];  // table, data, operation, fact, and presentation counts
```

Relation manifests make relation managers closer to nested resources: the
record detail renderer, Flightdeck, generated docs, tests, and external tools
can all understand the related table and its write affordances without touching
callbacks or route state.

Custom pages have their own manifest instead of borrowing the resource model.
Use `PanelPage::pageManifest()`, `Panel::pageManifest()`, or
`PanelInstance::pageManifest()` when a tool needs a custom page contract:

```php
$manifest=$panel->pageManifest('feature_showcase', $request);

$manifest['navigation'];   // group, icon, badge, hidden state, URL
$manifest['rendering'];    // custom renderer, static content, authorization
$manifest['actions'];      // page action manifests
$manifest['widgets'];      // page widgets
$manifest['tables'];       // page table manifests
$manifest['capabilities']; // aggregate page feature counts
```

Page manifests are useful for dashboards, utility pages, settings screens, and
tooling pages that have tables and actions but are not backed by a resource.

Widgets also have a route-free contract. Use `Widget::manifest()`,
`Panel::widgetManifest()`, or `PanelInstance::widgetManifest()` to inspect stat,
chart, trend, lazy, and linked widgets:

```php
$manifest=$panel->widgetManifest(
	$panel->widget('revenue_flow', 'chart')
		->labels(['Mon', 'Tue'])
		->dataset('Revenue', [1200, 1800])
);

$manifest['presentation']; // label, description, tone, icon, group, sort
$manifest['data'];         // static value, lazy flag, optional resolved state
$manifest['interaction'];  // link target and link flag
$manifest['chart'];        // type, height, labels, datasets, point counts
$manifest['capabilities']; // stat/chart/trend, lazy, dynamic data, link
```

Pass `resolve: true` when you explicitly want the widget value and dynamic chart
metadata resolved through its callbacks. The default manifest keeps callback
data private and only describes the shape.

Commands have the same route-free contract. Use `PanelCommand::manifest()`,
`Panel::commandManifest()`, or `PanelInstance::commandManifest()` when a command
palette, documentation generator, shortcut trainer, shell test, or Flightdeck
panel needs to inspect an operation without rendering the palette:

```php
$manifest=$panel->commandManifest('switch_glass_theme', $request);

$manifest['presentation']; // description, icon, tone, sort
$manifest['target'];       // URL, lazy URL, client action, new-tab behavior
$manifest['search'];       // keywords and indexed text
$manifest['visibility'];   // hidden and lazy visibility flags
$manifest['capabilities']; // target, search, presentation, visibility features
```

Panel manifests compose command manifests for all registered commands. Commands
with request-dependent URLs keep that fact visible through `target.url_lazy`, so
tools can distinguish a resolved link from a callback-backed target.

Themes can be described independently from the panel shell. Use
`Panel::themeManifest()` or `PanelInstance::themeManifest()` when a package,
test, visual builder, or Flightdeck pane needs the active theme and theme
library contract:

```php
$manifest=$panel->themeManifest(include_preview: true);

$manifest['active'];       // active theme definition
$manifest['library'];      // registered presets and named themes
$manifest['diagnostics'];  // missing bases, missing presets, cycles, contrast
$manifest['tokens'];       // light/dark token and variable maps
$manifest['modes'];        // dark mode, default mode, mode toggle
$manifest['assets'];       // brand, favicon, fonts, asset roots, stylesheets
$manifest['capabilities']; // counts for colors, tokens, modes, assets, library
$manifest['preview'];      // optional generated preview metadata
```

This keeps Filament-style theme customization inspectable as data. A theme can
radically change navigation, cards, tables, forms, and modals while external
tools still see the same token, asset, mode, and diagnostic contract.

Plugins expose a package contract too. Use `Panel::pluginManifest()`,
`PanelInstance::pluginManifest()`, or `PanelInstance::pluginManifests()` when a
shell, test, package browser, or Flightdeck tab needs to inspect extensions:

```php
$manifest=$panel->pluginManifest('dataphyre_ops_signals');

$manifest['package'];       // id, class, and version
$manifest['configuration']; // safe config shape and redacted scalar values
$manifest['capabilities'];  // metadata, lifecycle, package, config features
$manifest['meta'];          // caller metadata
```

The plugin manifest keeps the `PanelPlugin` interface small. Optional
`label()`, `version()`, and `description()` methods are read when they exist,
configuration values are redacted by sensitive key name, and the lifecycle
contract records whether the plugin exposes `register()` and `boot()`.

Packages can describe broader ecosystem metadata with `Panel::packageManifest()`
and `Panel::compatibilityMatrix()`. A package manifest can represent plugins,
themes, adapters, docs packs, or local packages. It records requirements for PHP,
Panel, Reactor, modules, and themes, then evaluates those requirements against a
runtime snapshot.

```php
$package=Panel::packageManifest([
	'id'=>'seller_trust_pack',
	'label'=>'Seller Trust Pack',
	'version'=>'1.0.0',
	'type'=>'plugin',
	'requires'=>[
		'php'=>'>=8.3',
		'panel'=>'^1.0',
		'reactor'=>'>=2.0',
		'modules'=>['templating'=>'>=2.0'],
		'themes'=>['default'],
	],
	'provides'=>['resources', 'widgets', 'actions'],
]);

$matrix=Panel::compatibilityMatrix([$package]);
$matrix->manifest(); // package counts, compatibility checks, runtime, provides
```

Package authors can also start from a template contract:

```php
$template=Panel::packageTemplate($package)
	->namespace('App\\Panel\\Packages\\SellerTrust')
	->theme(true)
	->marketplace(['category'=>'Trust and safety']);

$template->manifest(); // source, docs, tests, package JSON, marketplace listing
```

The template returns artifacts first. Production tooling can later write those
files, prompt before overwrites, publish marketplace listings, or run generated
regression suites without making the Panel runtime know about a specific app
folder or route.

Hosts can collect packages through a repository contract. `Panel::packageRepository()`
and `$panel->packageRepository()` can register manifests directly, discover
`dataphyre-panel-package.json` files from package folders, read generated
template artifacts, evaluate compatibility, and emit a deterministic lock
manifest.

```php
$repository=$panel->packageRepository()
	->discover('app/Panel/Packages')
	->discoverArtifacts($template->artifacts(), 'seller_trust_template');

$manifest=$repository->manifest(); // sources, errors, compatibility, packages
$lock=$repository->lock();         // stable lock manifest with checksum
```

The repository does not install code. It gives package browsers, CI, and future
marketplace tooling a stable way to inspect what would be installed, why it is
compatible or blocked, and which package versions were evaluated.

Compatibility and locks answer whether packages can run and which versions were
evaluated. Trust policies answer whether the host should accept them.
`Panel::packageTrustPolicy()` and `$panel->packageTrustPolicy()` evaluate package
signature metadata against trusted publishers, trusted key ids, allowed package
statuses, revoked package ids, and revoked signature digests:

```php
$policy=$panel->packageTrustPolicy([
	'require_signature'=>true,
	'allow_unknown_publishers'=>false,
	'trusted_publishers'=>['dataphyre'],
	'trusted_keys'=>['dp-release-key'],
	'revoked_packages'=>['old_theme_pack'],
]);

$report=$policy->report($repository);
$report->summary(); // total, trusted, blocked, signed
```

Trust policy evaluates identity metadata and revocations. Cryptographic package
verification is a separate, stricter boundary because it must see the complete
artifact bundle. The host supplies public keys; key material is never serialized:

```php
$verifier=$panel->packageSignatureVerifier([
	'dp-release-2026'=>[
		'algorithm'=>'ed25519',
		'public_key'=>$releasePublicKey,
	],
]);

$bytes=$verifier->payload($template); // exact domain-separated bytes to sign
$verification=$verifier->verify($template);
$verification->ok();                  // digest + key + detached signature
```

Signatures cover the manifest (excluding its signature field) and the sorted
path, byte length, and SHA-256 digest of every artifact. Ed25519 is preferred;
RSA-SHA256 and ECDSA-SHA256 use OpenSSL and enforce the corresponding OpenSSL
key family, so an RSA key cannot be relabeled as ECDSA or vice versa. Embedded
public keys are fail-closed by default. When explicitly enabled for development
or migration, embedded-key fallback is accepted only for an anonymous signature:
an unknown `key_id` never falls back to package-controlled key material.
Malformed, unsafe, case-colliding, or secret-bearing signature bundles return
check-level diagnostics, and verifier limits bound artifact count, signature/key
size, and aggregate artifact bytes.

Finally, installer tooling can ask for a dry-run plan before writing anything.
`Panel::packageInstallPlan()` and `$panel->packageInstallPlan()` combine a
package template, target path, runtime compatibility, optional trust policy, and
overwrite policy into a list of planned file operations:

```php
$plan=$panel->packageInstallPlan($template, app_path('Panel/Packages'), [
	'overwrite_policy'=>'skip', // fail, skip, or replace
	'trust_policy'=>$policy,
	'signature_verifier'=>$verifier,
	'runtime'=>PanelCompatibilityMatrix::defaultRuntime(),
]);

$manifest=$plan->manifest(); // ready, blocked, steps, conflicts, bytes
```

The plan manifest never writes files. It resolves artifact target paths, counts
creates, replacements, skips, conflicts, invalid artifacts, and bytes, and
blocks the complete package when compatibility, trust, signature, path, or
case-collision checks fail. After a human or deployment policy approves the
manifest, the same plan can produce a storage-safe apply result:

```php
$preview=$plan->apply(app_path('Panel/Packages'), [
	'dry_run'=>true,
	'overwrite_policy'=>'replace',
	'backup_root'=>storage_path('panel-package-backups'),
]);

$preview->toArray(); // ok, written, skipped, backups, blocked, verification
```

`apply()` returns a `PanelPackageApplyResult`. With `dry_run` enabled it reports
the same written and backup metadata without creating directories, copying
backups, or writing package files. With `dry_run` disabled it creates missing
target directories and copies existing files into a unique per-apply namespace
under `backup_root` before replacement. Backup bytes are rehashed after copying.
Every artifact is staged beside its destination, length/digest checked, and
published atomically; planned replacement digests are revalidated under the lock
and immediately before publication. Targets and ancestors that are symbolic
links, escape the requested root, appear/disappear after planning, or change
digest are added to `blocked`. Signed templates are also reverified after the
before-apply hook, so a hook cannot mutate already-approved bytes. Apply is atomic
by default: it shares an exclusive package-root lock with rollback, verifies
private transaction snapshots, and removes creations/restores replacements if a
later write fails. `attempted` and `reverted` preserve that recovery audit while
`written` contains only committed writes. If recovery itself fails, the private
snapshot location is preserved in result metadata for manual repair.
`atomic=false` is an explicit compatibility escape hatch. The per-call
`overwrite_policy` (or legacy `overwrite` boolean) can override the stored plan
without mutating it.

Every install plan can still produce a dry-run rollback plan:

```php
$rollback=$panel->packageRollbackPlan($plan);
$rollback->manifest(); // delete, restore, snapshot, leave, blocked counts
```

Preview rollback plans are manifest-only because they have no concrete backup
paths or installed digests. Created files become delete steps, replaced files
require snapshots and restore steps, skipped files are left alone, and unresolved
install conflicts block preview readiness.

After an install is applied, rollback planning should consume the apply result
instead of guessing what happened on disk:

```php
$result=$plan->apply(app_path('Panel/Packages'), [
	'overwrite'=>true,
	'backup_root'=>storage_path('panel-package-backups'),
]);

$rollback=PanelPackageRollbackPlan::fromApplyResult($result);
$rollback->manifest(); // restore when a backup exists, delete otherwise
$rollbackResult=$rollback->apply([
	'backup_root'=>storage_path('panel-package-backups'),
]);

// A deserialized result must re-establish caller-owned trust boundaries:
$serializedRollback=PanelPackageRollbackPlan::make($storedApplyResult);
$serializedRollback->apply([
	'target_root'=>app_path('Panel/Packages'),
	'backup_root'=>storage_path('panel-package-backups'),
]);
```

`Panel::packageRollbackPlan($result)` and `$panel->packageRollbackPlan($result)`
accept the same apply result object. Replacement writes require a matching
verified backup and become restore steps; creation writes become delete steps.
Skipped files become leave steps, and blocked apply entries remain visible in the
rollback manifest. Executable rollback validates the apply-result structure and
unique targets, rejects incomplete transactions, validates every target against
the original root, refuses symbolic-link targets and stale installed files,
confines backup reads to an allowed backup root, verifies both installed and
backup SHA-256 digests, preflights all steps before mutation, revalidates under
the package lock, and verifies private snapshots so an execution failure is
itself rolled back. Raw/deserialized result arrays require explicit trusted
`target_root`/`target_roots` and backup roots; paths embedded in serialized data
never expand caller authority. Use `dry_run=true` for an exact no-write preview.
`force=true` can bypass stale installed bytes and the missing digest on a legacy
create/delete record, but never backup integrity or path-confinement checks. If
transaction recovery fails, `meta.transaction_snapshot` retains the private
snapshot path for manual repair.

### Signed remote package distribution

For the complete first-party transparency, revocation, publisher-evidence, and
install-time activation model, see
[Dataphyre Panel Marketplace Trust](Dataphyre_Panel_Marketplace_Trust.md).

Panel includes a signer-isolated publisher and a crash-safe filesystem registry
operator. This is a complete first-party publication, discovery, and local
transport path; it is not merely a registry wire-format reader. The signer
callback and verification keys remain host-owned and are never serialized.
Before publication, every package bundle is independently signature-verified,
evaluated against trust policy, checked against the registry authority, and
rebuilt from bounded canonical artifacts. The generated signed index is then
self-verified before it can become a publication:

```php
$registry=Panel::filesystemPackageRegistry(
	$privateRegistryRoot,
	'example_packages',
	'example_org',
);

$publisher=Panel::packageRegistryPublisher(
	'example_packages',
	'example_org',
	'example-registry-2027',
	'ed25519',
	$hostKeyService->detachedSigner('example-registry-2027'),
	$signatureVerifier,
	$trustPolicy,
	$trustedClock,
	[
		'ttl_seconds'=>3600,
		'max_packages'=>2000,
		'max_bundle_bytes'=>64 * 1024 * 1024,
	],
);

$publication=$publisher->publish(
	[
		[
			'template'=>$sellerTrustTemplate,
			'dependencies'=>['audit_base'=>'^2.0.0'],
			'listing'=>[
				'tags'=>['trust', 'operations'],
				'categories'=>['governance'],
			],
		],
	],
	$nextMonotonicSequence,
	$registry->locatorFactory(),
);

$receipt=$registry->commit($publication);
```

`commit()` writes immutable content-addressed objects before atomically advancing
the signed index pointer. It rejects rollback, same-sequence equivocation,
foreign locators, changed registry roots, symlink ancestry, corrupt objects,
unsafe locks, and malformed persisted state. Replaying the exact same
publication is idempotent. `indexLocator()` and `fetch()` make the operator an
existing `PanelPackageTransport`, so the same load, resolve, acquire, install,
and rollback contracts work without a special local code path.

Discovery uses a locator-free read model:

```php
$catalog=$registry->catalog();
$page=$catalog->search(
	'workflow',
	['tag'=>'recommended', 'capability'=>'operator_actions'],
	cursor: $opaqueCursor,
	limit: 24,
);

$latest=$catalog->latest('seller_trust_pack');
$history=$catalog->versions('seller_trust_pack', includeUnavailable: true);
```

Catalog cursors are bound to the exact signed index, query, and filter set.
Search supports status, type, publisher, tag, category, capability, availability,
and all-version filters plus deterministic facets. Catalog rows reconstruct a
strict allowlist and never expose registry locators, package bytes, local paths,
or unknown metadata fields.

The optional Platform `packages` domain wires the same operator as both registry
and transport and provides a responsive, read-only package browser. The page
requires `packages.view`, accepts only bounded canonical search/filter values,
and is mountable with the rest of the production platform pages:

```php
$platform=PanelPlatform::defaults([
	'state_root'=>$privateStateRoot,
	'packages'=>[
		'registry_id'=>'example_packages',
		'publisher'=>'example_org',
		'snapshot_retention'=>256,
	],
]);

$panel->usePlatform($platform)->mountPlatformPages([
	'domains'=>['packages'],
	'packages'=>['base_url'=>'/platform/packages'],
]);
```

This operator is suitable for a single shared filesystem. Multi-host
object-store replication, CDN publication, remote signing services, public
catalog moderation, mirrors, credentials, monitoring, retention policy, and
background refresh remain deployment concerns.

Remote distribution remains an explicit adapter boundary. Panel does not ship
an HTTP client, read a registry URL during boot, refresh in the background, or
accept registry-provided public keys. A host implements `PanelPackageTransport`
for its HTTP client, object store, authenticated gateway, or test fixture. The
locator remains opaque and is passed only to that adapter:

```php
final class PackageGateway implements PanelPackageTransport {
	public function fetch(string $locator, array $request=[]): array {
		// Apply host credentials here. Do not put them in the returned envelope.
		$response=$this->client->get($locator, $request['max_bytes'] ?? null);

		return [
			'ok'=>$response->ok(),
			'status'=>$response->status(),
			'body'=>$response->body(),
			'bytes'=>strlen($response->body()),
			'content_type'=>$response->contentType(),
			'content_encoding'=>$response->contentEncoding(),
		];
	}
}
```

Registry indexes use
`application/vnd.dataphyre.panel-package-registry+json`. A publisher signs the
canonical index payload returned by `PanelPackageRegistryIndex::signaturePayload()`.
The signed body includes `registry`, `publisher`, a positive monotonic
`sequence`, `generated_at`, `expires_at`, package descriptors, artifact SHA-256
digests and sizes, and optional transparency proofs. Every entry is bound to the
authenticated index publisher and key id. Package artifacts use
`application/vnd.dataphyre.panel-package+json`; ZIP, TAR, GZIP, and other archive
formats are deliberately unsupported. Registry times must be canonical RFC 3339
date-times at exact-second precision with an explicit `Z` or numeric timezone;
relative, timezone-free, normalized-invalid, or whitespace-padded values fail
closed.

Load plans make the network transition visible in code. Construction and
`toArray()` do not call the transport. Only `load()` can do so:

```php
$registryLoad=PanelPackageRegistryLoadPlan::make(
	'registry://panel/releases',
	$packageGateway,
	$signatureVerifier, // host-owned public keys
	$trustPolicy,
	[
		'now'=>$trustedHostClock,
		'minimum_sequence'=>$persistedSequence,
		'previous_digest'=>$persistedIndexDigest,
		'require_transparency'=>true,
		'transparency_verifier'=>$hostTransparencyVerifier,
		'require_revocation_check'=>true,
		'revocation_checker'=>$marketplaceTrust->revocations(),
		'require_publisher_trust'=>true,
		'publisher_trust_resolver'=>$marketplaceTrust->publishers(),
	]
);

$registryLoad->toArray(); // no I/O; locator is represented only by a digest
$loaded=$registryLoad->load();
if(!$loaded->ok()) {
	throw new RuntimeException(implode(' ', $loaded->errors()));
}

$index=$loaded->index();
$signedBytes=$loaded->body(); // explicit host-owned persistence, never serialized
```

Freshness is evaluated against the caller's trusted clock. Signed registry
timestamps are evidence, not a clock source. Replay protection uses the
host-persisted minimum sequence and previous canonical body digest. Reusing a
sequence for different content or moving backwards fails closed. Offline mode
never calls transport and requires the host to supply previously verified signed
bytes explicitly:

```php
$offline=PanelPackageRegistryLoadPlan::make(
	'registry://panel/releases',
	$packageGateway,
	$signatureVerifier,
	$trustPolicy,
	[
		'offline'=>true,
		'cached_body'=>$persistedSignedBytes,
		'now'=>$trustedHostClock,
		'minimum_sequence'=>$persistedSequence,
		'previous_digest'=>$persistedIndexDigest,
		'allow_stale_cache'=>true, // explicit offline-only emergency policy
	]
)->load();
```

Resolution is deterministic and confined to exact package ids present in the
authenticated index. There are no capability aliases, implicit public-registry
fallbacks, or external dependency lookups. The compact constraint language
supports `*`, exact semantic versions, `=`, `==`, `>`, `>=`, `<`, `<=`, caret
ranges, and comma-separated conjunctions. Carets follow SemVer rules, including
`^0.2.3 <0.3.0` and `^0.0.3 <0.0.4`. Numeric prerelease identifiers with leading
zeros are invalid, build metadata does not affect precedence, and comparisons do
not use PHP's looser `version_compare()` behavior. Unsupported constraints fail
closed. The resolver prefers the highest eligible version only when there is no
existing pin or `update=true` is explicit. With the default `update=false`, an
authenticated lock version is pinned first, otherwise the installed version is
pinned; an unsatisfied pin fails rather than silently upgrading. It orders
dependencies before dependants, bounds backtracking with `max_attempts`, blocks
cycles and downgrades by default, blocks major upgrades unless allowed, and can
enforce a frozen version-and-digest lock:

```php
$resolution=PanelPackageResolver::make($index)->resolve(
	['seller_trust_pack'=>'^1.0.0'],
	$installedPackages,
	[
		'update'=>false,
		'lock'=>$persistedLock,
		'frozen'=>true,
		'allow_downgrade'=>false,
		'allow_major_updates'=>false,
	]
);

$resolution->toArray(); // deterministic CI manifest and checksum, no locators
```

Artifact acquisition is also explicit. The content-addressed cache derives local
paths only from an authenticated SHA-256 digest; a registry locator is never a
filesystem path. Cache reads rehash complete bytes, validate metadata, size,
content type, symlink ancestry, and host-clock freshness. Writes use a per-digest
lock and an atomically published staged body. A cache hit is not trusted by
itself: the complete package signature, publisher policy, registry publisher/key
binding, artifact paths, and optional transparency proof are reverified every
time.

```php
$cache=PanelPackageArtifactCache::make($hostPrivateCacheDirectory);
$acquisition=PanelPackageAcquisitionPlan::make(
	$resolution,
	$packageGateway,
	$cache,
	$signatureVerifier,
	$trustPolicy,
	[
		'now'=>$trustedHostClock,
		'require_transparency'=>true,
		'transparency_verifier'=>$hostTransparencyVerifier,
		'require_revocation_check'=>true,
		'revocation_checker'=>$marketplaceTrust->revocations(),
		'require_publisher_trust'=>true,
		'publisher_trust_resolver'=>$marketplaceTrust->publishers(),
	]
);

$acquisition->toArray(); // no transport or cache read
$packages=$acquisition->acquire();
$install=$packages->installPlan('seller_trust_pack', 'Panel/Packages', [
	'overwrite_policy'=>'replace',
]);
$applied=$install?->apply($targetRoot, ['backup_root'=>$backupRoot]);
$rollback=$applied?->ok() ? $packages->rollbackPlan($applied) : null;
```

The acquisition result retains a process-local live marketplace gate. Install
preflight evaluates it, and `apply()` evaluates it again under the package lock
immediately before publishing artifacts. A package or publisher revoked after
resolution or download is therefore denied before any package artifact is
written. The callback and its host trust objects are never serialized.

A package bundle is a JSON object with format
`dataphyre.panel.package.bundle.v1`, a signed `package` manifest, and a bounded
list of `{path, contents}` artifacts. It must include
`dataphyre-panel-package.json` with the exact same manifest. Paths must already
be canonical forward-slash relative paths; absolute paths, traversal, Windows
device names, control characters, case collisions, compression/encoding, and
partially supported archives are rejected before installer handoff.

Offline artifact acquisition accepts only a digest-valid cache entry and never
calls transport. Stale cache use requires both `offline=true` and the explicit
`allow_stale_cache=true` policy. Serialized load, resolution, cache,
acquisition, verification, install, and rollback manifests omit raw registry
locators, bundle bytes, local cache paths, signature bytes, public keys, and
credential-shaped metadata. Transparency receipts and durable revocation and
publisher-evidence projections are first-party contracts; remote log transport,
witness operation, and trust-anchor distribution remain host-owned. There is
intentionally no bundled HTTP transport, credential store, archive extractor,
cache eviction daemon, automatic updater, or background refresh loop; hosts own
those operational policies. The bundled filesystem operator is the first-party
self-hosted transport, not an implicit public-registry client.

### Package compatibility CI

`PanelPackageCompatibilityPlan` turns package manifests and explicit runtime
profiles into a transport-neutral conformance matrix. A plan accepts either a
bounded list of runtime profiles or a Cartesian `runtime_axes` definition for
PHP, Panel, Reactor, module-version profiles, theme sets, and feature sets. It
sorts every dimension, orders known package dependencies before dependants, and
produces stable case and plan fingerprints regardless of input order.

```php
$plan=PanelPackageCompatibilityPlan::make([
	'runtime_axes'=>[
		'php'=>['8.3.0', '8.4.0'],
		'panel'=>['2.0.0'],
		'reactor'=>['2.0.0'],
		'modules'=>['standard'=>['panel'=>'2.0.0', 'reactor'=>'2.0.0']],
		'themes'=>['built-in'=>['default', 'glass']],
		'features'=>['signed'=>['signed_packages']],
	],
	'packages'=>[[
		'manifest'=>$package,
		'required_features'=>['signed_packages'],
		'lock'=>$repositoryLock,
		'distribution'=>$authenticatedRegistryIndex,
		'verification'=>$verificationResult,
		'trust'=>$trustPolicy,
		'install_plan'=>$installPlan,
	]],
	'policy'=>[
		'require_lock'=>true,
		'require_authenticated_distribution'=>true,
		'require_signature'=>true,
		'require_trust'=>true,
		'require_install_ready'=>true,
	],
]);

$report=$plan->report($previousMachineReport);
$report->ok();         // CI policy decision
$report->comparison(); // newly blocked, regressions, recoveries, removals
```

Evidence provenance is deliberately explicit. A live
`PanelPackageRegistryIndex` is `runtime_authenticated`; a resolution plan is
`runtime_resolution` and is not promoted to authenticated evidence. Signature
verification results are reported evidence rather than a claim that the matrix
reran cryptography. Trust policies are evaluated locally, while install plans
are inspected through `manifest()` only. Snapshot arrays retain a `snapshot`
origin. Planning and reporting never fetch packages, read a cache, apply an
install plan, or write a baseline.

Machine reports omit registry locators, signature bytes, local paths, and
credential-shaped metadata. Baseline comparison uses stable case keys and
distinguishes added failures from newly blocked cases, recoveries, improvements,
and removed cases. Package, runtime, expanded-case, baseline, input-byte,
canonicalization-depth, and collection ceilings are enforced before expensive
work.

The contributor CLI exposes the same read-only contract:

```console
php source-checkout-maintainer-tool \
  --root . \
  --config source-checkout-maintainer-tool

# Once CI has promoted a prior report as its baseline:
php source-checkout-maintainer-tool \
  --root . \
  --config source-checkout-maintainer-tool \
  --baseline var/panel-package-compatibility-baseline.json
```

Both JSON inputs must resolve to regular, non-symbolic-link files beneath
`--root`. Exit code `0` means policy passed, `1` means the report is valid but
policy failed, and `2` means the arguments or JSON input are invalid. The CLI
does not update the baseline; CI owns artifact persistence and promotion.

Global search has its own manifest as well. Use `Panel::searchManifest()` or
`PanelInstance::searchManifest()` when a command palette, docs exporter,
Flightdeck tab, or test needs searchable providers without rendering the shell:

```php
$manifest=$panel->searchManifest($request, query: 'SO-', limit: 5);

$manifest['providers'];        // visible resource and custom provider descriptors
$manifest['resource_columns']; // indexed columns per provider
$manifest['query'];            // results, cursor, completeness, partial diagnostics
$manifest['capabilities'];     // ranking, paging, tenancy, budgets, provider counts
```

The manifest is cheap when no query is passed. Supplying a query asks the same
coordinator to return a bounded sample. A sampled query with zero hits remains
truthfully marked as sampled. Hidden or denied custom providers are omitted;
tenant scope is reported only when the provider explicitly enables and enforces
it. Provider metadata and diagnostics use the same bounded redaction contract as
runtime pages.

Tenant scope has a standalone manifest as well. Use
`Panel::tenantManifest()` or `PanelInstance::tenantManifest()` when a shell,
action runner, docs exporter, Flightdeck tab, or test needs to know how tenant
context moves through the panel:

```php
$manifest=$panel->tenantManifest($request);

$manifest['parameter'];     // request/query parameter name
$manifest['current'];       // active tenant key, or null when unscoped
$manifest['context'];       // resolution code, source, and authorization state
$manifest['tenants'];       // authorized visible switcher definitions only
$manifest['registry'];      // privacy-safe registry capability description
$manifest['resources'];     // tenant-scoped resources keyed by resource name
$manifest['search'];        // tenant-aware global search provider summary
$manifest['propagation'];   // links, forms, actions, exports, imports, modals
$manifest['capabilities'];  // lifecycle, onboarding, storage, billing, and scope
```

The manifest is built from resource definitions, the manager-owned tenant
registry, the authorized request context, and panel configuration. Hidden,
unknown, and unauthorized tenant definitions are not emitted through the
switcher list. It keeps tenant behavior inspectable without making themes or
host routes understand application-specific tenancy.

Navigation has a standalone shell contract too. Use
`NavigationItem::manifest()`, `Panel::navigationManifest()`, or
`PanelInstance::navigationManifest()` when a sidebar, horizontal nav, mobile
sheet, command surface, test, or documentation generator needs to inspect the
same grouped tree:

```php
$manifest=$panel->navigationManifest(
	request: $request,
	meta: ['navigation_layout'=>'horizontal']
);

$manifest['entries'];      // grouped tree-ready entries
$manifest['entries_flat']; // depth-aware flattened tree
$manifest['groups'];       // grouped navigation sections
$manifest['active'];       // active entry and operation
$manifest['search'];       // current navigation search result metadata
$manifest['counts'];       // entries, folders, leaves, badges, max depth
$manifest['capabilities']; // sidebar/horizontal/mobile support and hierarchy
```

This keeps navigation flexible in the Filament sense without making a theme
scrape rendered markup. Themes can change how navigation looks while tooling
still sees the same tree, active path, folders, badges, descriptions, and
layout hints.

The same idea exists at the whole-panel level. Use
`Panel::panelManifest()` or `PanelInstance::panelManifest()` when a tool needs
to inspect the shell itself:

```php
$manifest=$panel->panelManifest($request);

$manifest['resources'];    // resource manifests keyed by resource name
$manifest['pages'];        // custom pages, page tables, widgets, and actions
$manifest['widgets'];      // dashboard widgets
$manifest['navigation'];   // entries, tree, groups, active state, layout hints
$manifest['commands'];     // command palette entries, groups, keywords, targets
$manifest['theme'];        // active theme, library names, diagnostics, assets
$manifest['plugins'];      // installed panel plugins
$manifest['tenant'];       // tenant parameter, scoped resources, propagation
$manifest['search'];       // global search providers and indexed columns
$manifest['capabilities']; // aggregate counts for tests and Flightdeck
```

Panel manifests are the top of the manifest stack. They compose schema, action,
table, and resource manifests into one stable contract for shell renderers,
visual builders, test assertions, documentation exports, and Flightdeck’s panel
lifecycle view.

## Record Identity

Resources can define how Panel identifies a record. The same identity is used
for generated row links, bulk selection, show page headings, action URLs, and
global search results.

```php
Panel::resource('orders')
	->recordKeyUsing('order_number')
	->recordTitleUsing(fn($record) => 'Order '.$record['order_number'])
	->recordSubtitleUsing(fn($record) => $record['customer_name'].' / '.$record['status']);
```

Custom URLs can point to application-specific destinations while keeping the generated
table and actions aware of the same record:

```php
Panel::resource('tickets')
	->recordKeyUsing('uuid')
	->recordUrlUsing(function($record, string $operation){
		return '/support/tickets/'.$record['uuid'].($operation==='edit' ? '/edit' : '');
	});
```

Array-backed resources are filtered and sorted in the generated renderer. Query
objects may still apply their own filtering or pagination before records reach
Panel. If a query object exposes `paginate()` or `paginateRecords()`, Panel sends
the current page and resolved page size and will not slice that result a second
time.

CSV export prefers unpaginated query methods (`getRecords()` or `get()`) when
available so downloads can include the full current table view. If a query object
only exposes `paginate()` / `paginateRecords()`, export uses the records returned
by that paginated query.

Custom formatters still take precedence over built-in type formatting:

```php
Panel::column('margin', 'percent')->meta(['decimals'=>1]);
Panel::column('total')->money('CAD');
Panel::column('paid')->booleanLabels('Paid', 'Open');
Panel::column('status')->format(fn($value) => strtoupper((string)$value));
Panel::column('priority')
	->sortable()
	->sortUsing(fn(array $record) => match($record['priority'] ?? '') {
		'critical'=>0,
		'high'=>1,
		'medium'=>2,
		'low'=>3,
		default=>99,
	});
Panel::column('customer')
	->searchable()
	->searchUsing(fn(array $record) => [
		$record['customer'] ?? '',
		$record['email'] ?? '',
		$record['company'] ?? '',
	]);
```

Columns can also be computed from the whole record. Computed values participate
in generated display, local search, local sorting, and CSV export:

```php
Panel::column('customer')
	->valueUsing(fn($record) => trim($record['first_name'].' '.$record['last_name']))
	->searchable()
	->sortable();

Panel::column('gross_total')->money('CAD')
	->stateUsing(fn($record) => (float)$record['subtotal']+(float)$record['tax']);
```

HTML table cells can add presentation while exports remain plain text:

```php
Panel::column('status')->badge([
	'published'=>'success',
	'draft'=>'warning',
	'blocked'=>'danger',
]);
Panel::column('website')->url('name')->truncate(40);
Panel::column('support_email')->email();
Panel::column('description')->limit(90);
Panel::column('customer')
	->descriptionUsing(fn(array $record) => $record['email'] ?? 'No email');
Panel::column('sla_minutes')
	->label('SLA')
	->tooltipUsing(fn(array $record) => $record['sla_minutes']<0
		? 'Past target. Prioritize recovery.'
		: 'Minutes remaining before the next operating target.');
Panel::column('order_number')
	->copyable()
	->copyMessage('Order number copied');
Panel::column('order_number')
	->linkTo(fn(array $record) => '/orders/'.$record['id']);
Panel::column('status')
	->badge(['review'=>'warning', 'shipped'=>'success'])
	->group('Operations', 'State and risk signals');
Panel::column('margin')
	->visibleUsing(fn(PanelRequest $request) => $request->query('view')==='premium');
Panel::column('customer')
	->copyValueUsing(fn(array $record) => $record['email'] ?? '')
	->copyMessage('Customer email copied');
Panel::column('status')
	->iconUsing(fn(array $record) => $record['status']==='shipped' ? 'truck' : 'workflow')
	->colorUsing(fn(array $record) => $record['status']==='cancelled' ? 'danger' : 'primary');
Panel::column('risk')
	->headerData('qa', 'orders-risk-header')
	->cellAttributes(fn(array $record): array => [
		'data-qa'=>'orders-risk-cell',
		'data-order-risk'=>$record['risk'] ?? 'unknown',
		'aria-label'=>'Risk: '.ucfirst($record['risk'] ?? 'unknown'),
		'class'=>'risk-cell-'.($record['risk'] ?? 'unknown'),
	]);
```

Generated resources may use equivalent array definitions when a builder emits
configuration instead of fluent PHP:

```php
$resource->columns([
	[
		'name'=>'sku',
		'label'=>'SKU',
		'searchable'=>true,
		'sortable'=>true,
		'copyable'=>true,
		'copy_message'=>'SKU copied',
		'icon'=>'barcode',
		'color'=>'primary',
		'group'=>'Catalog',
		'group_description'=>'Identity and product context',
		'link_to'=>fn(array $record): string => '/products/'.$record['id'],
	],
]);
```

Columns can also render table footers. Footers are resolved against the current
filtered table records, so operators see totals and averages exactly beneath the
columns they are scanning:

```php
Panel::column('total')->money('CAD')->sum('Visible total');
Panel::column('margin')->average('Avg margin');
Panel::column('orders')->count('Rows');
Panel::column('stock')->footerUsing(fn(array $records) => [
	'label'=>'Low stock',
	'value'=>count(array_filter($records, fn(array $record) => $record['stock'] < $record['reorder_at'])),
]);

$resource->columns([
	[
		'name'=>'stock',
		'summary'=>'sum',
		'summary_label'=>'In stock',
	],
]);
```

The same footer definitions work on resource tables, generated page tables, and
relation tables. Built-in summaries support `sum`, `avg` / `average`, `min`,
`max`, and `count`; custom footer callbacks may return a string or an array with
`label` and `value`.

Custom column types registered through
`PanelComponentRegistry::registerColumnType()` can provide `value`, `format`,
`export`, `search`, `sort`, and `summary` hooks plus a cell renderer. Generated
cells consult registered renderers before the built-in badge/link/text renderers,
while exports call `Column::exportValue()` so custom export hooks stay plain-text
friendly. Columns also support lightweight presentation metadata with
`description()`, dynamic cell subtext through `descriptionUsing()`, `tooltip()`,
`tooltipUsing()`, `icon()`, `color()`, and `linkTo()`. Static `tooltip()` values
appear on table headers and cells; `tooltipUsing()` resolves record-aware cell
hints. Use `copyable()` for generated copy buttons, or `copyValueUsing()` when
the copied value should differ from the displayed value. `iconUsing()` and
`colorUsing()` can resolve those visual cues per record while still exporting
plain values. `linkTo()` wraps the primary cell content in a sanitized internal
or HTTP(S) link without changing export values; pass `true` as the second
argument or call `openInNewTab()` for a new-tab target. Use `group()` or the
array keys `group` and `group_description` to render consecutive related columns
under a shared header band. Ungrouped columns keep their normal single header
while grouped columns receive a second-level label row.
`sortUsing()` keeps generated sorting attached to the column while letting the
displayed value differ from the comparable value, such as status pipelines,
priority ranks, natural dates, or nested relation fields. `searchUsing()` does
the same for generated table search; return a scalar or a list of aliases,
normalized terms, related labels, or hidden fields that should match the column.
Column shell attributes can be attached with `headerAttributes()`,
`cellAttributes()`, `extraAttributes()`, `attributes()`, `headerData()`,
`cellData()`, `headerAria()`, and `cellAria()`. Header callbacks receive the
request, column, resource, and table; cell callbacks also receive the record,
raw value, and formatted value. Panel renders only safe `data-*`, `aria-*`,
`class`, `id`, `role`, `tabindex`, `headers`, and `scope` attributes while
keeping internal table labels, sorting state, and responsive markup
authoritative. These attributes render on resource tables, grouped rows,
relation tables, and page tables.

Resource tables can also decorate the generated `<tr>` for each record:

```php
$resource->rowAttributes(fn(array $order): array => [
	'data-qa'=>'orders-table-row',
	'data-order-status'=>$order['status'] ?? 'unknown',
	'data-order-risk'=>$order['risk'] ?? 'unknown',
	'class'=>'order-row order-row-risk-'.($order['risk'] ?? 'unknown'),
]);
```

Use `rowAttributes()`, `recordAttributes()`, `rowAttribute()`, `rowData()`, and
`rowAria()` when the whole row needs host hooks for QA, accessibility, live
updates, or stateful styling. Row callbacks receive the record, request,
resource, and table. Panel keeps its own row focus, record key, internal
`data-dp-panel-*`, and generated row label authoritative; hosts may add safe
`data-*`, `aria-*`, `class`, `id`, and `role` attributes. Row attributes render
on normal resource table rows, grouped rows, and relation manager rows.

Rows can be made directly interactive without relying on the first visible link
inside the row:

```php
$resource->rowClick('show');          // open the show view in the default modal
$resource->rowAction('edit');         // open the edit view
$resource->recordAction('brief');     // open a named resource action
$resource->clickableRows(false);      // disable row activation
$resource->rowUrl(fn($order) => '/orders/'.$order['id']);
```

Clickable rows emit `data-dp-panel-row-url` and reuse Panel's modal metadata
when modal navigation is enabled. Mouse clicks, double clicks, and keyboard
Enter all activate the same row target while controls inside the row still keep
their own behavior. `recordAction()` targets a registered row action by name and
inherits that action's visibility, authorization, disabled state, modal content,
form fields, confirmation copy, and modal width/style metadata. Bulk-only actions
are ignored as row targets. Content-only action URLs also render as normal
fallback pages, so opening a row target outside JavaScript still lands on a
useful action detail page instead of attempting to execute a handler.

Table views turn repeated operational filters into one-click queues:

```php
$resource=$resource->views([
	Panel::view('needs_review')
		->label('Needs review')
		->tone('warning')
		->columns(['id', 'customer', 'status', 'created_at'])
		->filterValue('review_status', 'pending')
		->sort('created_at', 'desc')
		->perPage(50)
		->density('compact')
		->where(fn($record) => ($record['review_status'] ?? null)==='pending'),
	Panel::view('high_risk')
		->label('High risk')
		->tone('danger')
		->filters(['risk_band'=>'high'])
		->range('risk_score', 80, null)
		->where(fn($record) => (int)($record['risk_score'] ?? 0)>=80),
]);
```

Generated pages always include an `All` view. If a view is marked
`default()`, it becomes the initial slice until the operator chooses `All`.
Views may also provide query defaults with `query()`, `search()`,
`filterValue()`, `filters()`, `range()`, `visibleColumns()` / `columns()`,
`sort()`, `perPage()`, and `density()`. Defaults are applied only when the
operator has not supplied an explicit value. Array-backed resources show view
counts automatically.
Paginated query objects receive the resolved view and its defaults in the
`PanelRequest` before the query factory runs, so repository-backed tables can
apply the same queues:

```php
Panel::resource('orders')
	->views([
		Panel::view('open')->default(),
		Panel::view('closed'),
	])
	->queryUsing(function(PanelRequest $request){
		return OrderRepository::queryForPanelView((string)$request->query('view', 'all'));
});
```

### Collection presentation, brick, and row masonry layouts

Collections are not locked to a single pill strip or row. Tables, page tables,
forms, and schemas share a normalized presentation contract with `inline`,
`segmented`, `brick`, `stack`, `grid`, and `masonry` display modes. The owning
primitive controls the sibling layout; individual views, tabs, filters, or
options keep their semantic state and content.

Masonry has two deliberately different flows. The backward-compatible default,
`masonry: columns`, is variable-height CSS-column masonry. `masonry: rows` is a
row-filling control layout: items wrap in DOM order, every row consumes the
available width, and an incomplete final row stretches instead of leaving a
dead cell. Row masonry is normally the right choice for widgets, toolbars,
table views, options, tabs, steps, form fields, and infolist entries. Column
masonry remains the right choice for variable-height media and editorial cards.

```php
$table=ResourceTable::make()
	->viewsPresentation([
		'display'=>'brick',
		'columns'=>['sm'=>2, 'lg'=>4],
		'fit'=>'fixed',
		'density'=>'normal',
		'gap'=>'compact',
		'min_width'=>160,
	])
	->brickGroups()
	->summariesPresentation(['display'=>'masonry', 'masonry'=>'columns'])
	->filtersDisplay('grid');

$table=$table->masonryViews(true, [
	'columns'=>['base'=>2, 'md'=>4, 'xl'=>6],
	'min_width'=>138,
	'gap'=>'compact',
]);

$form=ResourceForm::make()
	->brickTabs()
	->masonryFields(true, ['columns'=>['md'=>3]])
	->masonrySections(true, ['min_width'=>280])
	->stepsPresentation([
		'display'=>'grid',
		'columns'=>['md'=>3],
		'fit'=>'fixed',
	]);

Field::make('channel', 'radio')
	->options(['web'=>'Web', 'store'=>'Store', 'partner'=>'Partner'])
	->masonryOptions(true, ['columns'=>2, 'min_width'=>150]);

$page=PanelPage::make('operations')
	->masonryWidgets(true, ['columns'=>['sm'=>2, 'lg'=>3]])
	->masonryToolbar(true, ['min_width'=>160])
	->masonryForms(true, ['columns'=>['lg'=>2]])
	->masonryTables(true, ['columns'=>['xl'=>2]]);

$section=FormSection::make('commercial')
	->masonryFields(true, ['columns'=>['md'=>3]]);

$infolist=Infolist::make()
	->masonryEntries(true, ['columns'=>['md'=>3, 'xl'=>4]])
	->masonrySections(true, ['min_width'=>300]);

$orders=Resource::make('orders')
	->masonryRecords('payments', true, ['columns'=>['md'=>2, 'xl'=>3]])
	->recordItemPresentation('payments', 'manual-review', [
		'span'=>['base'=>1, 'xl'=>2],
	])
	->recordFinalRow('payments', 'preserve')
	->recordPresentation('shipments', [
		'display'=>'masonry',
		'masonry'=>'columns',
		'min_width'=>260,
	])
	->masonryBoardColumns(true, ['columns'=>['md'=>2, 'xl'=>4]])
	->boardColumnItemPresentation('review', ['span'=>['md'=>2, 'xl'=>1]])
	->collectionFinalRow('board_columns', 'center')
	->masonryBoardCards(true, ['columns'=>2])
	->boardCardItemPresentation('SO-260505-0016', ['fill_remainder'=>true]);
```

Resources own the record-detail collection contract. The supported record
collection keys are `relations`, `alerts`, `insights`, `links`, `contacts`,
`locations`, `tags`, `items`, `totals`, `approvals`, `activity`, `changes`,
`payments`, `shipments`, `notes`, `attachments`, `messages`, and `tasks`.
`recordPresentation()`, `recordItemPresentation()`,
`recordItemPresentations()`, `recordFinalRow()`, and `masonryRecords()` are the
explicit record aliases; the generic collection methods remain available.
Relation managers also expose the local `item*()` builders. Array payloads for
the other record collections can declare `item_presentation` directly or under
their associative `meta` key. Unconfigured record sections retain their legacy
markup, including the historical unwrapped relation-manager sequence.

Brick/Masonry v3 retains the v2 owner targeting contract: a wildcard (`*`), a zero-based
rendered position (`#0`, `#1`, or an integer builder key), or a normalized item
name. Named rules win over positional rules, positional rules win over the
wildcard, and metadata declared on the item wins last. Every item control is
responsive at `base`, `sm`, `md`, `lg`, `xl`, and `2xl`: `span`, `basis`,
`min_width`, `max_width`, `grow`, `shrink`, `order`, `break_before`, and
`fill_remainder`. Existing scalar values retain their original serialized and
rendered contract. A breakpoint map is sparse and inherits its most recent
configured value; precedence merges maps instead of discarding wildcard or
positional breakpoints.

```php
$table=ResourceTable::make()
	->masonryViews(true, ['columns'=>['base'=>2, 'lg'=>4]])
	->viewItemPresentation('*', ['shrink'=>0])
	->viewItemPresentation('attention', [
		'span'=>['base'=>2, 'lg'=>1],
		'grow'=>['base'=>1, 'md'=>2],
		'shrink'=>['base'=>1, 'lg'=>0],
		'order'=>['base'=>2, 'lg'=>-1],
		'fill_remainder'=>['base'=>false, 'lg'=>true],
	])
	->viewItemPresentation(4, [
		'break_before'=>['base'=>false, 'md'=>true],
	])
	->collectionFinalRow('views', 'preserve');

$market=Field::make('market', 'radio')
	->options([
		'ca'=>'Canada',
		'us'=>'United States',
		'eu'=>[
			'label'=>'European Union',
			'item_presentation'=>['fill_remainder'=>true],
		],
	])
	->masonryOptions(true, ['columns'=>2]);

$widget=Widget::make('revenue')
	->itemSpan(['base'=>1, 'lg'=>2])
	->itemMinWidth('16rem')
	->itemGrow(['base'=>1, 'md'=>2])
	->itemShrink(0, 'xl')
	->itemOrder(['base'=>2, 'lg'=>-1])
	->itemBreakBefore(true, 'md')
	->itemFillRemainder(['base'=>false, 'xl'=>true]);

$lineItems=Field::make('line_items')->repeater([
	Field::make('sku')->itemSpan(2),
	Field::make('quantity', 'number'),
])
	->rowsPresentation(['display'=>'masonry', 'masonry'=>'rows'])
	->fieldsPresentation(['display'=>'grid', 'columns'=>2]);

$content=Field::make('content')->builder([
	'hero'=>[
		'label'=>'Hero',
		'fields'=>[Field::make('headline')->itemFillRemainder()],
		'item_presentation'=>['grow'=>2],
	],
	'copy'=>['label'=>'Copy', 'fields'=>[Field::make('body', 'textarea')]],
])
	->rowsPresentation(['display'=>'masonry', 'masonry'=>'rows'])
	->fieldsPresentation(['display'=>'grid', 'columns'=>2])
	->actionsPresentation(['display'=>'masonry', 'masonry'=>'rows']);
```

Every major item primitive exposes the same immutable local builders:
`itemPresentation()`, `itemSpan()`, `itemBasis()`, `itemMinWidth()`,
`itemMaxWidth()`, `itemGrow()`, `itemShrink()`, `itemOrder()`,
`itemBreakBefore()`, and `itemFillRemainder()`. Owner-level convenience methods
include `viewItemPresentation()`, `groupItemPresentation()`,
`summaryItemPresentation()`, `filterItemPresentation()`,
`actionItemPresentation()`, `tabItemPresentation()`,
`stepItemPresentation()`, `optionItemPresentation()`,
`widgetItemPresentation()`, `toolbarItemPresentation()`,
`sectionItemPresentation()`, `fieldItemPresentation()`,
`entryItemPresentation()`, `toolItemPresentation()`,
`formItemPresentation()`, `tableItemPresentation()`,
`boardColumnItemPresentation()`, and `boardCardItemPresentation()`. Use
`collectionItemPresentation()` for extension-defined collections. Status-board
lanes consume `board_columns`, while the cards within every lane consume
`board_cards`. A `TableView` can use its local `item*()` builders to size its
generated lane; owner rules can target cards by normalized record key. Board
drag/drop remains DOM ordered, so presentation changes layout only and never
change transition semantics.

Board lanes can now be declared independently from transitions with
`statusBoardColumns()` or `statusBoardColumn()`. `hasStatusBoard()` reports lane
availability, while `canTransition()` continues to report mutation authority.
This allows a read-only board to render and appear in index navigation without
a callback; cards become draggable only after a save or transition handler is
attached. Transition-derived lanes remain backward compatible and merge with
explicit lanes by status value, so a custom lane key does not create a duplicate
column.

`collectionFinalRow()` accepts `fill`, `preserve`, `center`, or `end`. The
default remains the existing fill behavior. Breaks are emitted as inert layout
sentinels, responsive visual ordering never mutates DOM order, and all item
lengths and numeric controls are normalized to bounded safe values; arbitrary
CSS expressions are rejected. Responsive values resolve from the content
container after viewport rules, so a narrow embedded Panel or modal cannot
accidentally retain its desktop order, growth, break, or fill policy. No item
attributes or extra markup are emitted for unconfigured collections.

The convenience methods are symmetric across the built-in collections:
`brickViews()`, `brickGroups()`, `brickSummaries()`, `brickFilters()`,
`brickActions()`, `brickTabs()`, `brickSteps()`, `brickOptions()`,
`brickWidgets()`, `brickToolbar()`, `brickSections()`, `brickFields()`,
`brickEntries()`, `brickItems()`, `brickRows()`, `brickTools()`,
`brickForms()`, `brickTables()`, `brickBoardColumns()`, and
`brickBoardCards()`. Every major collection also has a `masonry*()` builder;
`brickCollection()` and `rowMasonry()` cover extension-defined collections.
Their corresponding `*Display()` and `*Presentation()` methods expose the
complete contract. `PanelPage` serializes widget and toolbar presentation,
`ResourceTable`/`PageTable` serialize table collections,
`ResourceForm`/`Schema` serialize fields and section collections, each
`FormSection` can override its own field collection, and `Infolist` forwards
entry/section presentation to its schema. `Field` exposes meta-backed
presentation for options, nested rows, fields, actions, items, and tools.
Repeater and builder rows consume `rows`; their nested controls consume
`fields`; builder add-block controls consume `actions`. Field groups use the
same `fields` presentation, and local child-field item metadata wins after the
owner wildcard, position, and named rules.
`PanelPage` also owns its scaffold `forms` and page-level `tables`; these
collections stay unwrapped until configured, while `masonryForms()`,
`masonryTables()`, and the corresponding presentation/item builders opt them
into responsive layout. A `PageTable` can declare local item presentation for
its containing page without changing the table's internal row semantics.
Resources likewise expose `boardColumnsPresentation()`,
`masonryBoardColumns()`, `boardCardsPresentation()`, and
`masonryBoardCards()`. Unconfigured boards retain their historical packed-grid
markup byte for byte apart from unrelated page chrome.

The `Resource` facade routes collection methods to the surface they actually
control. Views, groups, summaries, filters, actions, and tools configure the
resource table; fields, sections, tabs, and steps configure the resource form;
entries configure the infolist; record-detail modules remain available through
`recordPresentation()`, `brickRecords()`, and `masonryRecords()`. Explicit
`tableCollection*()`, `formCollection*()`, and `infolistCollection*()` methods
are available when a plugin needs to avoid any shorthand or target item/final
row metadata directly. Presentation metadata does not leak between surfaces.

`columns` accepts an
integer or responsive `base`, `sm`, `md`, `lg`, `xl`, and `2xl` keys; counts are
clamped from 1 to 12. For row masonry, responsive counts emit deterministic
flex bases with the selected gap, so a 2-column target is exact rather than a
best-effort `min-width` hint. When no count is configured, `min_width` drives
automatic packing. `fit` may be `auto`, `fill`, or `fixed`; density and gap
accept `compact`, `normal`, and `roomy` (`gap` also accepts `none`). Minimum
widths are clamped to safe bounds. Row masonry, fill-mode brick/grid, and fixed
brick/grid collections collapse to one full-width item below 640px; they never
turn into horizontal control scrollers.

Responsive collection and item breakpoints are resolved against the rendered
Panel content surface, not only the browser viewport. A narrow slide-over,
modal body, embedded Panel, or sidebar-constrained main region therefore uses
its own `sm`/`md` configuration even on a wide desktop. The emergency
single-column container guard applies below 400 pixels; between 400 and 639
pixels, row masonry keeps a configured multi-item row when its minimum widths
actually fit. This preserves useful layouts such as a 2+1 market selector in a
medium modal while still making genuinely narrow embedded surfaces full-width.
The renderer carries sparse item values into safe tier variables and marks the
surface for `collection-layout`; direct asset consumers should request that
capability instead of copying the generated marker or CSS variable protocol.

Legacy aliases are normalized: `cards` and `tiles` become `brick`, `tabs` and
`pills` become `segmented`, `row` becomes `inline`, and `stacked` becomes
`stack`. `balanced`, `flow`, `row_masonry`, `masonry_rows`, and `row_fill`
become row masonry. Existing `display('masonry')` remains column masonry, so
upgrading does not change media/card reading order. Existing `choiceColumns()`, `inlineChoices()`, and segmented choice
fields remain supported and are translated into the same renderer contract.

Table summaries cover common aggregate values without a custom dashboard widget:

```php
Panel::summary('orders')->count();
Panel::summary('gross')->sum('total')->money('CAD');
Panel::summary('average_margin')->avg('margin')->percent(1, 1);
Panel::summary('open_orders')
	->label('Open orders')
	->tone('warning')
	->valueUsing(fn(array $records) => count(array_filter(
		$records,
		fn($record) => ($record['status'] ?? null)==='open'
	)));
```

Filters compare against a column with the same name by default, or a custom
column through `column()`:

```php
$resource=$resource->filters([
	Panel::filter('status', 'select')
		->options(['draft'=>'Draft', 'published'=>'Published']),
	Panel::filter('assignee_id', 'select')
		->optionsUsing(fn(PanelRequest $request) => UserRepository::filterOptions($request->user())),
	Panel::filter('enabled', 'boolean'),
	Panel::filter('created_at')->dateRange(),
	Panel::filter('total')->numberRange(),
	Panel::filter('minimum_total')
		->where(fn($record, $value) => (float)$record['total'] >= (float)$value),
]);
```

Select/enum filters accept static options, option groups, or request-aware
`optionsUsing()` callbacks. Invalid option values in the URL are ignored so a
stale link cannot accidentally collapse a table to an impossible state.
Active filters render as chips below the generated controls. Each chip links to
the same table state with only that filter removed; the main Reset link clears
all filters while preserving the current view, search, sort, columns, density,
and page size.
Range filters use `{name}_from` and `{name}_to` query parameters. `dateRange()`
compares ISO date prefixes, while `numberRange()` compares numeric values.

## Notifications And Redirects

Save handlers and action handlers can return a simple string, an
`PanelNotification`, or a structured outcome array.

```php
$resource=$resource->saveUsing(function(array $data){
	save_project($data);

	return [
		'message'=>'Project saved.',
		'redirect'=>PanelConfig::resourceUrl('projects'),
		'notification'=>Panel::notify('Project saved.', 'success', 'Saved'),
	];
});
```

Resources can define native status transitions. Generated row and show pages
render one confirmed POST button per transition that is available for the
record's current status:

```php
$resource=$resource
	->statusField('status')
	->statusTransitions([
		[
			'name'=>'publish',
			'label'=>'Publish',
			'from'=>'draft',
			'to'=>'published',
			'tone'=>'success',
			'confirmation'=>'Publish this order?',
		],
		[
			'name'=>'archive',
			'label'=>'Archive',
			'from'=>['draft', 'published'],
			'to'=>'archived',
			'tone'=>'warning',
		],
	])
	->transitionUsing(function(array $transition, $record){
		OrderRepository::changeStatus($record['id'], $transition['to']);

		return $transition['label'].' completed.';
	});
```

If `transitionUsing()` is not registered, Panel reuses `saveUsing()` with
`[$statusField=>$transition['to']]` and mode `transition`. Transitions check
`transition` and `transition:{name}` authorization, redirect back to the current
table context by default, and use the same outcome contract as saves and
actions. Index tables also expose one selected-records button per transition.
Bulk transitions run the same transition path for each selected record and
summarize changed, unavailable, failed, and denied records.

Declaring transitions also creates table views for every status mentioned in
`from` or `to`. These generated status views behave like normal `view()`
definitions: they show counts, filter local records, affect exports, and can be
overridden by registering a manual view with the same name.

Resources can opt into dashboard status widgets with `statusWidgets()`. Panel
then renders one stat widget per generated status view, with counts, tones, and
links back to the matching resource view:

```php
Panel::resource('orders')
	->statusField('status')
	->statusTransitions([...])
	->statusWidgets();
```

Status widgets use the resource query, respect `dashboard_widgets`
authorization, and stay disabled by default.

Resources can expose record activity on generated show pages with
`activityUsing()`. The handler receives the record, request, and resource, and
returns timeline entries from any source the app owns:

```php
Panel::resource('orders')
	->activityUsing(function($record, PanelRequest $request){
		return [
			[
				'title'=>'Order placed',
				'message'=>'Checkout completed successfully.',
				'time'=>$record['created_at'],
				'actor'=>$record['customer_email'],
				'tone'=>'success',
			],
			[
				'title'=>'Payment review',
				'message'=>'Risk team requested a second look.',
				'time'=>'2026-05-05 10:30:00',
				'actor'=>'Payments',
				'tone'=>'warning',
				'url'=>PanelConfig::resourceUrl('payment-reviews', 'show/'.$record['id']),
			],
		];
	});
```

Activity entries accept `title`, `message`, `time`, `actor`, `tone`, `url`, and
`meta`. String entries are treated as simple titles. The generated renderer
checks `activity` authorization before displaying the section.

Resources can expose record insights on generated show pages with
`insightsUsing()` or `recordInsightsUsing()`. Insights are compact cards for
operator-facing facts such as SLA, margin, risk, fulfillment health, or account
status:

```php
Panel::resource('orders')
	->insightsUsing(fn($record) => [
		[
			'label'=>'Risk',
			'value'=>$record['risk_score'].'%',
			'description'=>'Payment and account review',
			'tone'=>$record['risk_score'] > 60 ? 'danger' : 'success',
		],
		[
			'label'=>'SLA',
			'value'=>'2h left',
			'tone'=>'warning',
			'url'=>PanelConfig::resourceUrl('sla', 'show/'.$record['id']),
		],
	]);
```

Insight entries accept `label`/`title`, `value`, `description`, `tone`, `icon`,
and `url`. Scalar entries are accepted as simple value cards. The generated
renderer checks `insight` authorization before displaying the section.

Resources can expose record alerts on generated show pages with `alertsUsing()`
or `recordAlertsUsing()`. Alerts are short, operator-facing prompts for records
that need review, follow-up, verification, or remediation:

```php
Panel::resource('orders')
	->alertsUsing(fn($record) => $record['risk_score'] > 60 ? [
		[
			'title'=>'Payment review required',
			'message'=>'Risk score is above the automatic release threshold.',
			'tone'=>'danger',
			'action'=>'Review payment',
			'url'=>PanelConfig::resourceUrl('payment-reviews', 'show/'.$record['id']),
			'meta'=>['Risk '.$record['risk_score'].'%', 'Before fulfillment'],
		],
	] : []);
```

Alert entries accept `title`/`label`/`name`, `message`/`description`/`detail`,
`tone`, `url`/`href`/`to`, `action`/`action_label`, and `meta`. Scalar entries
are treated as simple alert messages. Only same-site paths and `http`/`https`
URLs are rendered. The generated renderer checks `alert` authorization before
displaying the section.

Resources can expose record links on generated show pages with `linksUsing()` or
`recordLinksUsing()`. Links are intended for the practical next places an
operator might need: storefront pages, shipment tracking, payment records,
customer profiles, source tickets, logs, or internal app views:

```php
Panel::resource('orders')
	->linksUsing(fn($record) => [
		[
			'label'=>'Storefront order',
			'url'=>'/orders/'.$record['public_id'],
			'description'=>'Open the customer-facing order page',
			'group'=>'Public',
			'tone'=>'primary',
		],
		[
			'label'=>'Carrier tracking',
			'url'=>'https://carrier.example/track/'.$record['tracking_number'],
			'group'=>'Fulfillment',
			'tone'=>'info',
			'external'=>true,
		],
	]);
```

Link entries accept `label`/`title`/`name`, `url`/`href`/`to`, `description`,
`group`, `tone`, `icon`, and `external`. String entries are treated as URLs.
Only same-site paths and `http`/`https` URLs are rendered. The generated renderer
checks `link` authorization before displaying the section.

Resources can expose record contacts with `contactsUsing()` or
`recordContactsUsing()`. Contacts are compact person or organization cards for
customer, seller, owner, assignee, billing, vendor, warehouse, or support
contacts attached to the record:

```php
Panel::resource('orders')
	->contactsUsing(fn($record) => [
		[
			'name'=>$record['customer_name'],
			'role'=>'Customer',
			'email'=>$record['customer_email'],
			'phone'=>$record['customer_phone'],
			'location'=>$record['shipping_city'],
			'status'=>'verified',
			'profile_url'=>PanelConfig::resourceUrl('customers', 'show/'.$record['customer_id']),
		],
	]);
```

Contact entries accept `name`/`label`/`title`/`display_name`, `role`/`type`/
`kind`, `email`/`mail`, `phone`/`telephone`/`mobile`, `company`/`organization`,
`location`/`address`/`city`, `status`/`state`, `url`/`href`/`profile_url`, and
`tone`. String entries are treated as names, or email contacts when they contain
`@`. Only same-site paths and `http`/`https` URLs are rendered. The generated
renderer checks `contact` authorization before displaying the section.

Resources can expose record locations with `locationsUsing()` or
`recordLocationsUsing()`. Locations are compact cards for shipping, billing,
warehouse, pickup, service, office, event, or risk-review addresses:

```php
Panel::resource('orders')
	->locationsUsing(fn($record) => [
		[
			'label'=>'Shipping address',
			'type'=>'Delivery',
			'address1'=>$record['shipping_address1'],
			'address2'=>$record['shipping_address2'],
			'city'=>$record['shipping_city'],
			'province'=>$record['shipping_province'],
			'postal_code'=>$record['shipping_postal_code'],
			'country'=>$record['shipping_country'],
			'status'=>'verified',
			'map_url'=>'https://maps.example/?q='.$record['shipping_postal_code'],
		],
	]);
```

Location entries accept `label`/`title`/`name`, `type`/`kind`/`role`,
`address`/`address1`/`line1`/`street`, `address2`/`line2`/`unit`/`suite`,
`city`/`locality`, `subdivision`/`province`/`state`/`region`, `postal_code`/
`postal`/`zip`, `country`/`country_code`, `lat`/`latitude`, `lng`/`lon`/
`longitude`, `timezone`/`tz`, `status`/`state`, `url`/`href`/`map_url`, and
`tone`. String entries are treated as address text. Only same-site paths and
`http`/`https` URLs are rendered. The generated renderer checks `location`
authorization before displaying the section.

Resources can expose record tags with `tagsUsing()` and optionally add/remove
tags with `tagUsing()` or `updateTagUsing()`:

```php
Panel::resource('orders')
	->tagsUsing(fn($record) => [
		['name'=>'vip', 'label'=>'VIP', 'tone'=>'success'],
		['name'=>'fraud_review', 'label'=>'Fraud review', 'tone'=>'warning'],
	])
	->updateTagUsing(function($record, string $tag, string $action){
		$action === 'add'
			? OrderTags::add($record['id'], $tag)
			: OrderTags::remove($record['id'], $tag);

		return $action === 'add' ? 'Tag added.' : 'Tag removed.';
	});
```

Tag entries accept `name`/`key`/`slug`, `label`/`title`, `description`/
`detail`, `tone`, and `status`. Scalar entries are treated as tag names. The
generated add/remove controls check `tag`, `tag:update`, `tag:add`/
`tag:remove`, and `tag:{name}` authorization before submitting changes.

Resources can expose record items with `itemsUsing()` or `recordItemsUsing()`.
Items are read-only lines for products, services, subscriptions, assets,
devices, packages, invoice lines, or other child units:

```php
Panel::resource('orders')
	->itemsUsing(fn($record) => [
		[
			'title'=>'Wireless keyboard',
			'sku'=>'KB-100',
			'quantity'=>2,
			'price'=>'39.95',
			'total'=>'79.90',
			'currency'=>'CAD',
			'status'=>'fulfilled',
			'item_url'=>PanelConfig::resourceUrl('products', 'show/KB-100'),
		],
	]);
```

Item entries accept `title`/`label`/`name`/`product`/`service`, `sku`/`code`/
`reference`, `type`/`kind`/`category`, `quantity`/`qty`/`count`,
`unit_price`/`price`/`rate`, `total`/`amount`/`subtotal`, `currency`,
`status`/`state`, `url`/`href`/`item_url`, and `tone`. Scalar entries are
treated as item titles. Only same-site paths and `http`/`https` URLs are
rendered. The generated renderer checks `item` authorization before displaying
the section.

Resources can expose record totals with `totalsUsing()` or
`recordTotalsUsing()`. Totals are compact amount cards for subtotal, tax,
shipping, discounts, fees, grand total, paid, refunded, or balance due:

```php
Panel::resource('orders')
	->totalsUsing(fn($record) => [
		'currency'=>'CAD',
		'subtotal'=>$record['subtotal'],
		'tax'=>$record['tax_total'],
		'shipping'=>$record['shipping_total'],
		[
			'label'=>'Balance due',
			'value'=>$record['balance_due'],
			'status'=>$record['balance_due'] > 0 ? 'due' : 'paid',
		],
	]);
```

Total entries accept `label`/`title`/`name`, `value`/`amount`/`total`/
`balance`/`paid`, `currency`, `description`/`detail`, `status`/`state`, and
`tone`. A top-level `currency` value is applied to scalar total entries. The
generated renderer checks `total` authorization before displaying the section.

Resources can expose record approvals with `approvalsUsing()` and resolve them
with `approvalUsing()` or `resolveApprovalUsing()`. Approvals are generated
review actions for workflows such as seller verification, refund release,
payout release, fraud review, catalog publication, or support escalation:

```php
Panel::resource('orders')
	->approvalsUsing(fn($record) => [
		[
			'name'=>'release_refund',
			'title'=>'Release refund',
			'description'=>'Customer refund is waiting for a final review.',
			'requested_by'=>'Support',
			'requested_at'=>'2026-05-05 13:00:00',
			'due_at'=>'2026-05-05 16:00:00',
			'tone'=>'warning',
		],
	])
	->resolveApprovalUsing(function($record, string $approval, string $decision, PanelRequest $request){
		ApprovalQueue::resolve('orders', $record['id'], $approval, $decision, $request->user()?->id);

		return $decision === 'approve' ? 'Approval accepted.' : 'Approval rejected.';
	});
```

Approval entries accept `name`/`id`/`key`, `title`/`label`, `description`,
`status`/`state`, `requested_by`/`requester`/`actor`, `requested_at`/`time`,
`due_at`, and `tone`. Pending approvals render Approve and Reject buttons when a
resolver is registered. The generated renderer checks `approval`,
`approval:resolve`, `approval:{name}`, and `approval:{name}:{decision}`
authorization before submitting a decision.

Resources can expose field-level change history on generated show pages with
`changesUsing()` or `recordChangesUsing()`. The renderer displays each entry as
a before/after comparison with optional actor, time, reason, tone, and source
link:

```php
Panel::resource('orders')
	->changesUsing(fn($record) => AuditLog::forRecord('orders', $record['id'])
		->map(fn($entry) => [
			'field'=>$entry['field'],
			'before'=>$entry['old_value'],
			'after'=>$entry['new_value'],
			'actor'=>$entry['actor_name'],
			'time'=>$entry['created_at'],
			'reason'=>$entry['reason'],
			'tone'=>$entry['field'] === 'status' ? 'info' : 'neutral',
			'url'=>PanelConfig::resourceUrl('audit', 'show/'.$entry['id']),
		])
		->all());
```

Change entries accept `field`/`label`/`name`, `before`/`old`/`from`,
`after`/`new`/`to`, `time`/`changed_at`/`created_at`, `actor`/`user`/`by`,
`reason`/`message`/`description`, `tone`, and `url`/`href`. Scalar entries are
accepted as simple after-values. The generated renderer checks `change`
authorization before displaying the section.

Resources can also expose internal notes on generated show pages. `notesUsing()`
returns existing notes, and `noteUsing()` or `addNoteUsing()` receives new notes
from the generated POST form:

```php
Panel::resource('orders')
	->notesUsing(fn($record) => OrderNotes::forOrder($record['id']))
	->addNoteUsing(function($record, string $note, PanelRequest $request){
		OrderNotes::create([
			'order_id'=>$record['id'],
			'body'=>$note,
			'author_id'=>$request->user()?->id,
		]);

		return Panel::notify('Note added.', 'success');
	});
```

Note entries accept `message`, `note`, `body`, or `text`, plus `author` and
`time`/`created_at`. The generated note form is exposed as a record-section
modal, checks `note` and `note:create` authorization, and redirects back to the
record by default when JavaScript is unavailable.

Resources can expose record messages with `messagesUsing()` and send new
messages with `messageUsing()` or `sendMessageUsing()`. Messages are intended
for outward-facing or system communications, while notes remain internal:

```php
Panel::resource('orders')
	->messagesUsing(fn($record) => MessageLog::forOrder($record['id']))
	->sendMessageUsing(function($record, array $message, PanelRequest $request){
		MessageBus::send([
			'order_id'=>$record['id'],
			'channel'=>$message['channel'],
			'to'=>$message['recipient'],
			'subject'=>$message['subject'],
			'body'=>$message['body'],
			'sent_by'=>$request->user()?->id,
		]);

		return 'Message sent.';
	});
```

Message entries accept `subject`/`title`, `body`/`message`/`text`/`content`,
`channel`/`type`, `status`/`state`, `recipient`/`to`/`customer`,
`sender`/`from`/`actor`, and `time`/`sent_at`/`created_at`. The generated send
form opens as a record-section modal and posts `channel`, `recipient`,
`subject`, and `body`. The renderer checks `message` and `message:send`
authorization before showing or sending messages.

Resources can expose record payments with `paymentsUsing()` or
`recordPaymentsUsing()`. Payments are read-only ledger cards for charges,
refunds, credits, payouts, payment intents, disputes, or account balance events:

```php
Panel::resource('orders')
	->paymentsUsing(fn($record) => [
		[
			'type'=>'charge',
			'title'=>'Card payment',
			'amount'=>'124.95',
			'currency'=>'CAD',
			'status'=>'captured',
			'provider'=>'Stripe',
			'payment_intent'=>$record['payment_intent'],
			'paid_at'=>$record['paid_at'],
			'dashboard_url'=>'https://dashboard.stripe.com/payments/'.$record['payment_intent'],
		],
	]);
```

Payment entries accept `title`/`label`/`name`, `type`/`kind`/`event`,
`amount`/`value`/`total`/`gross`, `amount_label`, `currency`, `status`/`state`,
`provider`/`processor`/`gateway`, `reference`, `transaction_id`,
`payment_intent`, `charge_id`, `refund_id`, `payout_id`, `time`/`paid_at`/
`created_at`, `url`/`href`/`dashboard_url`, and `tone`. Scalar entries are
accepted as simple amount cards. Only same-site paths and `http`/`https` links
are rendered. The generated renderer checks `payment` authorization before
displaying the section.

Resources can expose record shipments with `shipmentsUsing()` or
`recordShipmentsUsing()`. Shipments are rendered as compact fulfillment cards
with carrier, service, tracking number, status, ETA, route, and safe tracking
links:

```php
Panel::resource('orders')
	->shipmentsUsing(fn($record) => [
		[
			'title'=>'Package 1',
			'carrier'=>'Canada Post',
			'service'=>'Expedited Parcel',
			'tracking_number'=>'4000000000000000',
			'status'=>'in_transit',
			'estimated_delivery'=>'2026-05-08',
			'origin'=>'Montreal, QC',
			'destination'=>'Toronto, ON',
			'tracking_url'=>'https://carrier.example/track/4000000000000000',
		],
	]);
```

Shipment entries accept `title`/`label`/`name`, `tracking`/`tracking_number`,
`carrier`/`provider`, `service`/`method`, `status`/`state`, `eta`/
`estimated_delivery`, `origin`/`from`, `destination`/`to`, `url`/`href`/
`tracking_url`, and `tone`. Scalar entries are treated as tracking numbers.
Only same-site paths and `http`/`https` links are rendered. The generated
renderer checks `shipment` authorization before displaying the section.

Resources can expose record attachments with `attachmentsUsing()` and accept new
uploads with `attachUsing()` or `uploadAttachmentUsing()`:

```php
Panel::resource('orders')
	->attachmentsUsing(fn($record) => OrderFiles::forOrder($record['id']))
	->uploadAttachmentUsing(function($record, array $file, PanelRequest $request){
		OrderFiles::storeUploadedFile($record['id'], $file['tmp_name'], $file['name']);

		return Panel::notify('Attachment uploaded.', 'success');
	});
```

Attachment entries accept `name`/`filename`, `url`, `type`/`mime`, `size`,
`uploaded_at`/`created_at`, and `author`. String entries are treated as URLs.
The generated upload form opens as a record-section modal, checks `attachment`
and `attachment:create` authorization, and redirects back to the record by
default.

Resources can expose record tasks with `tasksUsing()` and handle complete/reopen
updates with `taskUsing()` or `updateTaskUsing()`. Add `createTaskUsing()` or
`addTaskUsing()` to render the generated add-task form:

```php
Panel::resource('orders')
	->tasksUsing(fn($record) => [
		[
			'name'=>'verify_address',
			'title'=>'Verify shipping address',
			'description'=>'Confirm the address before purchasing a label.',
			'due_at'=>'2026-05-05 16:00:00',
			'assignee'=>'Support',
			'completed'=>false,
		],
	])
	->updateTaskUsing(function($record, string $task, bool $completed){
		OrderTasks::setCompleted($record['id'], $task, $completed);

		return $completed ? 'Task completed.' : 'Task reopened.';
	})
	->addTaskUsing(function($record, array $task){
		OrderTasks::create($record['id'], $task);

		return 'Task added.';
	});
```

Task entries accept `name`/`id`, `title`/`label`, `description`, `completed`,
`status`, `due_at`, `assignee`, and `tone`. The generated buttons check `task`,
`task:update`, and `task:{name}` authorization. The add-task form checks
`task:create` and opens as a record-section modal. Complete/reopen actions use
the same generated confirmation modal path as other Panel actions. Both paths
redirect back to the record by default when JavaScript is unavailable.

Resources can also define a native delete handler. Generated row and show pages
render a confirmed POST delete button when `deleteUsing()` is present:

```php
$resource=$resource->deleteUsing(function($record){
	OrderRepository::delete($record['id']);

	return [
		'message'=>'Order deleted.',
		'redirect'=>PanelConfig::resourceUrl('orders'),
	];
});
```

Delete handlers use the same outcome contract as saves and actions. If no
redirect is returned, Panel redirects back to the current table context and
flashes the delete notification.

Permanent deletion is a separate handler so soft delete and purge can coexist.
Generated row and show pages render a confirmed POST Force delete button when
`forceDeleteUsing()` is present:

```php
$resource=$resource->forceDeleteUsing(function($record){
	OrderRepository::forceDelete($record['id']);

	return 'Order permanently deleted.';
});
```

Force delete handlers use the same outcome contract as saves, deletes, and
actions. Returning `['force_deleted'=>false]` marks that record as failed during
bulk force delete. Index tables expose a Force delete selected button, resolve
the selected records, check `force_delete` authorization for each record, and
summarize permanently deleted, failed, and denied records.

Resources can define a native duplicate handler. Generated row and show pages
render a confirmed POST duplicate button when `duplicateUsing()` is present:

```php
$resource=$resource->duplicateUsing(function($record){
	$copy=OrderRepository::duplicate($record['id']);

	return [
		'message'=>'Order duplicated.',
		'redirect'=>PanelConfig::resourceUrl('orders', 'edit/'.$copy['id']),
	];
});
```

Duplicate handlers use the same outcome contract as saves, deletes, and actions.
If no redirect is returned, Panel redirects back to the current table context and
flashes the duplicate notification.

Resources can define a native restore handler for soft-deleted or archived
records. Generated row and show pages render a confirmed POST restore button
when `restoreUsing()` is present:

```php
$resource=$resource->restoreUsing(function($record){
	OrderRepository::restore($record['id']);

	return 'Order restored.';
});
```

Restore handlers use the same outcome contract as saves, deletes, duplicates,
and actions. If no redirect is returned, Panel redirects back to the current
table context and flashes the restore notification. Index tables also expose a
native Restore selected button. Bulk restore resolves the selected records,
checks restore authorization for each record, runs the same restore handler, and
summarizes restored, failed, and denied records.

When `duplicateUsing()` is present, index tables also expose a native bulk
duplicate button in the selected-records action bar. Bulk duplicate resolves the
selected records, checks duplicate authorization for each record, runs the same
duplicate handler, and redirects back to the current table context with a summary
notification. Empty selections are rejected before the handler is called.

When `deleteUsing()` is present, index tables also expose a native bulk delete
button in the selected-records action bar. Bulk delete resolves the selected
records, checks delete authorization for each record, runs the same delete
handler, and redirects back to the current table context with a summary
notification. Empty selections are rejected before the handler is called.

Resources can also expose a native bulk update form. Define the editable fields
with `bulkFields()` or `bulkField()`. Panel renders an Edit selected button,
validates the bulk form with the same field lifecycle, and then calls
`bulkUpdateUsing()` once for all records. If no bulk handler is registered,
Panel reuses `saveUsing()` once per selected record with mode `bulk_update`.

```php
$resource=$resource
	->bulkFields([
		Panel::field('status', 'select')
			->required()
			->options(['draft'=>'Draft', 'live'=>'Live']),
		Panel::field('review_note')->rules('max:120'),
	])
	->bulkUpdateUsing(function(array $data, array $records){
		foreach($records as $record){
			OrderRepository::update($record['id'], $data);
		}

		return count($records).' orders updated.';
	});
```

Action handlers use the same contract:

```php
Panel::action('scan')
	->successMessage('Scan queued.')
	->confirmation('Queue a fresh scan for this record?')
	->handle(function($record){
		queue_scan($record);
	});
```

Actions can be grouped without changing their execution route. Groups are
rendering containers: each nested action still resolves by its own action name,
authorization callback, form schema, modal settings, confirmation message, bulk
state, and handler.

```php
Panel::resource('orders')
	->actionGroup('fulfillment', [
		Panel::action('print_label')->label('Print label'),
		Panel::action('mark_shipped')->label('Mark shipped')->tone('success'),
	])
	->actionGroup(
		Panel::actionGroup('review')
			->label('Review')
			->tone('neutral')
			->outlined()
			->compact()
			->dropdownWidth('lg')
			->alignStart()
			->section('Review flow', 'Decision actions')
			->action(Panel::action('approve')->tone('success'))
			->divider()
			->section('Exceptions')
			->action(Panel::action('reject')->tone('danger')->requiresConfirmation())
	);
```

Action groups support the same button presentation language as actions:
`style()` / `variant()`, `outlined()`, `ghost()`, `link()`, `size()`,
`compact()`, `large()`, and `iconOnly()` / `iconButton()`. Use
`dropdownWidth('sm'|'md'|'lg'|'xl'|'auto')` when grouped action labels need more
or less room than the default menu. Use `dropdownAlignment('start'|'center'|'end')`
or `alignStart()`, `alignCenter()`, and `alignEnd()` when the menu should open
from a specific edge of the trigger. The browser runtime keeps open action
menus inside the viewport by clamping fixed-position dropdowns on resize,
scroll, and open. It also assigns menu roles and supports Arrow Up/Down,
Home/End, Escape, and Tab close behavior for generated action-group menus.
`section()` / `heading()` and `divider()` add generated menu structure without
changing the action route or handler. Array-based definitions can use
`Panel::actionGroupSection()` and `Panel::actionGroupDivider()` markers between
child actions.

Record headings use a bounded primary-action policy so a long workflow does not
push the record itself below a wall of buttons on narrow screens. Two `auto`
actions remain visible by default; every remaining permitted action is rendered
server-side in one `More` disclosure with its count, descriptions, forms,
confirmations, modal intent, disabled reasons, and authorization unchanged.
Configure the policy at the resource and action boundaries:

```php
Panel::resource('orders')
	->recordActionLimit(1)
	->recordActionPlacements([
		'edit' => 'primary',
		'transition_cancel' => 'overflow',
		'delete' => 'overflow',
	])
	->actions([
		Panel::action('assign')->recordPrimary(),
		Panel::action('risk_review')->recordOverflow(),
		Panel::actionGroup('ops')->recordOverflow()->actions([...]),
	]);
```

`recordActionLimit()` counts only actions whose placement remains `auto`.
Explicit `primary` actions may exceed the limit intentionally. Resource
overrides win over `Action::recordPlacement()` or
`ActionGroup::recordPlacement()`; aliases `inline` / `visible` normalize to
`primary`, while `menu` / `secondary` normalize to `overflow`. Built-in keys are
`edit`, `preview`, `transition_<name>`, `duplicate`, `restore`, `delete`, and
`force_delete`; custom actions and groups use their normalized names. A limit of
zero is valid when every visible action should be chosen explicitly. The
generated disclosure has menu semantics, Arrow Up/Down and Home/End traversal,
Escape focus restoration, viewport-clamped desktop placement, and a capped
in-flow container treatment on narrow record surfaces, including slide-overs.
Action manifests expose the resolved policy as
`presentation.record_placement`; action-group manifests expose
`record_placement` at the group root, and resource manifests include
`record_action_limit` plus `record_action_placements`.

Generated action buttons use POST/redirect/get by default. If an action handler
does not return a redirect, Panel redirects back to the current Panel table or
record context and flashes the success message. Action forms carry the same
return target through validation and the Cancel button.

Actions can be visible but unavailable. Use `disabled()` when the operator
should understand that a workflow step exists but is blocked by the current
record state:

```php
Panel::action('capture_payment')
	->label('Capture')
	->disabled(
		fn($order) => ($order['status'] ?? null) !== 'paid',
		fn($order) => 'Payment capture requires Paid status.'
	)
	->handle(fn($order) => Payments::capture($order));
```

Disabled actions render with `disabled`, `aria-disabled`, a title, and
`data-dp-panel-disabled-reason`. Direct requests to the action endpoint return an
`Action unavailable` result instead of running the handler. Authorization still
controls whether an action appears at all; disabled state controls whether a
visible action can currently run.

Use `visible()` or `hidden()` when an action should only exist for certain
record or page states:

```php
Panel::action('critical_escalation')
	->visible(fn($order) => ($order['risk'] ?? null) === 'critical')
	->handle(fn($order) => Escalations::open($order));
```

Hidden actions are removed from generated buttons and action groups. Direct
requests to a hidden action return a not-found result for that state instead of
falling through to authorization or execution. Visibility, disabled state, and
authorization are intentionally separate: visibility describes workflow shape,
disabled state explains temporary blockers, and authorization represents user
permission.

Action presentation can also be resolved from the current record or request.
`label()`, `icon()`, and `tone()` accept callbacks, so a single action can
rename itself, swap icons, and change color without cloning the resource:

```php
Panel::action('capture_payment')
	->label(fn($order) => ($order['status'] ?? null) === 'paid' ? 'Capture payment' : 'Capture later')
	->icon(fn($order) => ($order['status'] ?? null) === 'paid' ? 'credit-card' : 'clock')
	->tone(fn($order) => ($order['status'] ?? null) === 'paid' ? 'success' : 'warning')
	->disabled(fn($order) => ($order['status'] ?? null) !== 'paid')
	->handle(fn($order) => Payments::capture($order));
```

Dynamic presentation is resolved for table row actions, record-page actions,
bulk actions, page actions, action groups, confirmation screens, action forms,
modal metadata, and `PanelActionState`. Raw action definitions still expose
`label_dynamic`, `icon_dynamic`, `tone_dynamic`, `badge_dynamic`,
`badge_tone_dynamic`, `tooltip_dynamic`, and `description_dynamic` so tools can
distinguish static configuration from resolved runtime presentation.

Actions can also declare their visual treatment without custom CSS. Use
`style()` / `variant()` for `solid`, `outline`, `ghost`, or `link`, or the
helpers `outlined()`, `ghost()`, `subtle()`, and `link()`. Use `size()` for
`xs`, `sm`, `md`, `lg`, or `xl`, with `compact()` and `large()` as shortcuts.
`iconOnly()` / `iconButton()` hides the text visually while preserving an
accessible label:

```php
Panel::action('snapshot')
	->icon('eye')
	->iconButton()
	->outlined()
	->tooltip('Open a compact read-only snapshot.');

Panel::action('risk_review')
	->tone('danger')
	->large();
```

The renderer emits variant and size classes consistently for row actions,
record-page actions, action groups, bulk actions, and modal triggers. The
action manifest includes `style`, `size`, and `icon_only` so clients can mirror
server-rendered presentation.

Actions may also carry concise badges and tooltips. Both can be static or
record-aware. `description()` / `descriptionUsing()` adds short explanatory copy
for richer generated menus. Toolbar buttons stay compact, while action group
menus and row-more menus reveal the description under the label:

```php
Panel::action('capture_payment')
	->description(fn($order) => 'Capture funds for '.($order['order_number'] ?? 'this order').'.')
	->badge(fn($order) => strtoupper($order['status'] ?? ''))
	->badgeTone(fn($order) => ($order['status'] ?? null) === 'paid' ? 'success' : 'warning')
	->tooltip(fn($order) => ($order['status'] ?? null) === 'paid'
		? 'Ready to capture because this order is paid.'
		: 'Capture unlocks when the order reaches Paid.');
```

Badges are rendered inside generated action buttons and stay present in action
groups, row menus, modal triggers, and mobile action layouts. Tooltips are
emitted as native `title` text plus `data-dp-panel-action-tooltip` for richer
clients. Disabled actions keep the disabled reason as their title so the blocker
remains the primary explanation.

Actions can advertise keyboard bindings with `keyBinding()` or `keyBindings()`.
The generated button receives `data-dp-panel-key-bindings` and
`aria-keyshortcuts`; Panel's client dispatcher activates the first visible,
enabled matching action while ignoring typing fields and disabled controls:

```php
Panel::action('capture_payment')
	->keyBinding('mod+shift+p');

Panel::action('critical_escalation')
	->keyBindings(['mod+shift+e', 'ctrl+alt+e']);
```

Use `mod` for Ctrl on Windows/Linux and Command on macOS. Bindings normalize
common aliases such as `cmd`, `command`, `control`, `option`, `esc`, and
`return`. The command palette reads the same metadata and shows the shortcut
hint beside matching actions, so keyboard affordances stay tied to the action
definition instead of a route or controller.

Generated action controls can carry safe host attributes with
`extraAttributes()`, `attributes()`, `attribute()`, `data()`, or `aria()`.
Static maps and record-aware callbacks are both supported:

```php
Panel::action('capture_payment')
	->extraAttributes(static fn($order): array => [
		'data-qa'=>'capture-payment-action',
		'data-order-status'=>$order['status'] ?? 'unknown',
		'aria-label'=>'Capture payment for '.($order['order_number'] ?? 'this order'),
		'class'=>'qa-critical-action',
	]);

Panel::action('critical_escalation')
	->data('qa', 'critical-escalation-action')
	->aria('label', 'Escalate this critical order');
```

Extra attributes are resolved with the same record, request, resource, and
action context as dynamic labels, badges, and tooltips. Panel keeps its own
internal control attributes authoritative: `data-dp-panel-*`, disabled state,
modal metadata, shortcut metadata, titles, form targets, names, values, event
handlers, and inline styles are reserved. Hosts may add `data-*`, `aria-*`,
`class`, `id`, `role`, `tabindex`, `download`, `target`, and `rel`; false and
null values are omitted, while true values render as boolean attributes.

Modal action forms are progressively enhanced. A direct action form URL renders
as a normal generated page, while requests carrying
`X-Requested-With: DataphyrePanelModal` and, when needed,
`__panel_partial=modal` return only the form fragment with the same status and
structured result metadata. This
keeps custom emitters, non-JavaScript clients, and tests on the full-page path
while letting interactive panels open and re-render action modals without
downloading a complete shell.

Bulk actions receive the selected records as the handler's first argument and
refuse an empty selection unless `allowEmptySelection()` is set:

```php
Panel::action('archive_selected')
	->bulk()
	->requiresConfirmation()
	->successMessage('Selected records archived.')
	->handle(function(array $records){
		foreach($records as $record){
			archive_record($record);
		}
	});
```

Outcome keys:

- `message`: text shown on the result page.
- `notification` or `notifications`: one or more `PanelNotification`, array, or
  string notices.
- `redirect` or `redirect_to`: target URL for a `303` response by default.
- `status`: optional 3xx redirect status.

When a generated save or action response redirects and a PHP session is active,
notifications are flashed into the session and displayed once on the next
generated Panel page.

Notifications use one shape across full-page responses, redirects, modal
submissions, and AJAX fragments. A notification may include a title, icon,
action link, display duration, and persistence flag:

```php
return [
	'message'=>'Order was assigned.',
	'notification'=>PanelNotification::success('Ownership moved to Mina.', 'Assignment complete')
		->icon('user-check')
		->action('Open order', '/debug?resource=orders&operation=show&record=42')
		->duration(5200),
];
```

Use `persistent()` for warnings or failures that should stay visible until the
operator dismisses them. Array notifications accept the same keys:
`type`, `title`, `message`, `icon`, `action_label`, `action_url`,
`duration_ms`, `persistent`, and `meta`. Generated pages render flashed
notifications as inline notices at the top of the page and expose the same
payload to the browser toast system on boot; partial and modal responses carry
the same payload in JSON so custom JavaScript does not need its own notification
format.

`PanelPageResult::isRedirect()`, `redirectTo()`, and `notifications()` expose the
same data for custom emitters and tests.

## Form Lifecycle

Panel forms follow an explicit lifecycle:

1. `state()` builds the server-owned snapshot for current, initial, dehydrated,
   dirty, computed, and optionally validated values.
2. `hydrate()` prepares field values from a record, defaults, or submitted input.
3. `dehydrate()` turns submitted field values into save-ready data.
4. `validate()` applies field rules and custom validators.
5. `submit()` dehydrates then validates.
6. `saveUsing()` runs only when the submitted form is valid.

Failed validation renders the form again with submitted values and inline field
messages. The page result uses HTTP status `422` and includes the structured form
state in `PanelPageResult::data()`.

```php
Panel::field('email')
	->required()
	->rules(['email']);

Panel::field('title')
	->rules(['min:3', 'max:120'])
	->dehydrateUsing(fn($value) => trim((string)$value))
	->validateUsing(function($value){
		return str_contains((string)$value, 'draft')
			? 'Title should not contain draft.'
			: [];
	});

Panel::field('links', 'repeater')
	->label('Useful links')
	->minItems(1)
	->maxItems(5)
	->addItemLabel('Add link')
	->repeaterFields([
		Panel::field('label')->required(),
		Panel::field('url')->rules('url')->required(),
	]);

Panel::field('receipt', 'file_upload')
	->acceptedTypes(['image/*', '.pdf'])
	->maxFileSize(5 * 1024 * 1024);
```

Generated forms include richer field primitives that share the same state,
dehydration, validation, and live update lifecycle:

```php
Panel::field('risk', 'radio')
	->options(['low'=>'Low', 'critical'=>'Critical'])
	->required();

Panel::field('markets', 'checkbox_list')
	->options(['CA'=>'Canada', 'US'=>'United States']);

Panel::field('segments', 'multi_select')
	->options(['retail'=>'Retail', 'wholesale'=>'Wholesale']);

Panel::field('tags', 'tags')
	->tagSeparator(',');

Panel::field('metadata', 'key_value')
	->keyValueSeparators("\n", '=');

Panel::field('brand_color', 'color');

Panel::field('priority', 'range')
	->min(1)
	->max(10)
	->step(1);
```

`radio` submits one option value. `checkbox_list` and `multi_select` submit an
array of option values and validate each submitted value against the current
option list. `tags` dehydrates comma- or newline-separated text into a unique
array of tags. `key_value` accepts JSON or `key=value` lines and dehydrates into
an associative array. `hidden`, `url`, `tel`, `time`, `color`, `range`,
`rich_editor`, and `rich_text` are first-class field types, and custom field
renderers registered through `PanelComponentRegistry::registerFieldType()` are
consulted before the built-in renderer.

Radio and checkbox-list options keep the native input at a fixed `20px` while
the associated choice label remains the minimum `44px` interaction target.
That geometry is identical on full pages and dynamically mounted modal forms;
ordinary text inputs are explicitly excluded without increasing their cascade
specificity. Checked, keyboard-focus, disabled, invalid, dark/system, and
forced-colors states are visible on the complete label target. These rules ship
with the `form` asset capability (and therefore with `modal`), not with unrelated
shell, table, or navigation-only bundles.

Boolean fields render a semantic outer `div` and one control-owning label (never
nested labels). Their switch track is `42px` by `24px`, exposes checked, focus,
disabled, dark/system, and forced-colors states, and participates in the same
adaptive field-span contract. The hidden native checkbox retains submission and
keyboard semantics while the complete switch card remains a minimum `52px`
pointer target.

Built-in field rules:

- `required`
- conditional required helpers: `requiredWhen()` and `requiredUnless()`
- `email`
- `number` / `numeric`
- `integer`
- `url`
- `min:n`
- `max:n`
- `in:a,b,c`

Repeater fields submit as arrays of rows. Blank rows are discarded during
dehydration, child field rules are validated per row, and `minItems()` /
`maxItems()` control the number of accepted rows. The generated UI uses a
disabled template row for client-side add/remove behavior while server-side
validation remains authoritative.

File fields use the `file`, `file_upload`, `upload`, `drag_drop_upload`, or
`image` field types.
Generated create, edit, bulk, and action forms automatically use multipart
encoding when a file field is present. Submitted values are normalized PHP upload
arrays; `multiple()` returns a list of upload arrays, while single upload fields
return one upload array. On edit, leaving the file input empty preserves the
record value. `acceptedTypes()` accepts MIME types, wildcard groups such as
`image/*`, or file extensions, and `maxFileSize()` validates the uploaded byte
size.

Use `customUploader()` or `dragDropUpload()` for the Panel uploader shell with
drag/drop, accepted-type and size policy display, queue rows, transfer status,
progress bars, validation errors, chunked uploads, and retry controls.
`uploadEndpoint()`,
`uploadChunkSize()`, `uploadRetries()`, and `uploadConcurrency()` tune the
runtime. `uploadMinFiles()` and `uploadMaxFiles()` add client and server count
validation for custom uploader payloads. `uploadHeaders()`, `uploadHeader()`,
`uploadFields()`, `uploadField()`, and `uploadCsrf()` attach per-chunk request
metadata for secured custom upload endpoints. `uploadDeleteEndpoint()` (or
`deleteEndpoint()`) makes completed/stored row removal call a backend cleanup
endpoint before the hidden payload is changed. `storageUploader('local',
'panel_uploads/{date}/{filename}')` wires the active Panel upload endpoint
(`/dataphyre/panel/upload` by default, or the mounted route endpoint such as
`/admin/upload` when the panel is dispatched through `Panel::mountedRoutes()`).
The endpoint assembles chunks and persists the completed file through Dataphyre
Storage; it also accepts stored-file delete requests.
The storage path template supports `{date}`, `{field}`, `{collection}`,
`{filename}`, `{original}`, `{name}`, `{ext}`, `{hash}`, and `{id}`.

Calling `rules()` appends rules, so `required()->rules('email')` keeps both
rules. Use `required(false)` to remove the required rule.

## Relation Managers

Relation managers attach child tables to a resource. They can be rendered inside
the parent `show` page and dispatched directly through the `relation` operation.

```php
$resource=$resource->relation(
	Panel::relation('orders')
		->label('Recent orders')
		->parentTitleUsing(fn($customer) => $customer['name'])
		->description(fn($customer) => 'Orders placed by '.$customer['name'].'.')
		->badgeUsing(fn(array $orders) => count($orders).' orders')
		->emptyState('No orders yet.', 'This customer has no related orders in the current workspace.')
		->relatedResource('orders')
		->foreignKey('customer_id')
		->localKey('id')
		->perPage(10)
		->columns([
			Panel::column('order_number')->searchable(),
			Panel::column('status'),
			Panel::column('total', 'money'),
		])
		->filter(Panel::filter('status', 'select')->options([
			'open'=>'Open',
			'paid'=>'Paid',
			'cancelled'=>'Cancelled',
		]))
		->view(Panel::view('open')->where(
			fn($order) => ($order['status'] ?? null) === 'open'
		))
		->facts([
			Panel::summary('order_count')->count(),
			Panel::summary('revenue')->sum('total')->money('CAD')->tone('success'),
		])
		->summary(Panel::summary('total_orders')->count())
		->queryUsing(function($customer){
			return OrderRepository::forCustomer($customer);
		})
);
```

Relation queries can return:

- a plain array of rows or records
- a Dataphyre SQL table/repository query exposing `get()` or `getRecords()`
- a paginated query exposing `paginate()` or `paginateRecords()`

Relation tables use the same table grammar as resources: sortable/searchable
columns, filters, range filters, table views, summaries, default sort,
pagination, and per-page options. When several relations render on the same
record page their state is scoped with relation-prefixed query parameters, so
one child table cannot overwrite another child table's search, filter, view, or
page state.

Relation manager headers are parent-aware. `parentTitleUsing()` controls the
record label shown above the relation, `description()` and `badgeUsing()` may be
static strings or callbacks, and `emptyState()` accepts a heading plus optional
description. `facts()` accepts `TableSummary` definitions and resolves them
against the full related dataset before table filters and pagination, while
regular `summary()` values remain tied to the current relation view.

Generated relation surfaces are backed by `PanelRelationState`. The state carries
the relation definition, parent record identity, relation-scoped request,
resolved columns, all related records, filtered records, current page records,
view counts, facts, empty-state copy, and the nested `PanelTableState`.

Direct relation pages expose this as `relation_state` in the `PanelPageResult`
data, while embedded show-page relations record `relation.state` for Flightdeck
and tests:

```php
$result=Panel::dispatch([
	'resource'=>'customers',
	'operation'=>'relation',
	'record'=>'42',
	'relation'=>'orders',
]);

$state=$result->data()['relation_state'] ?? null;
```

When `relatedResource()` points to another registered resource, relation rows
inherit that resource's row controls: View, Edit, native delete, and record
actions. Submitted buttons carry a safe return target back to the parent record
or direct relation page. Use `readOnly()` when a relation should expose only
record links while hiding mutating controls.

Relations with `relatedResource()`, `foreignKey()`, and `localKey()` also render
a Create button. The child create form receives `prefill[foreign_key]` from the
parent record's local key and carries `return_to` back to the parent relation.
Define the foreign-key field on the child resource, usually as a hidden required
field, when the save handler should receive it:

```php
Panel::resource('orders')
	->field(Panel::field('customer_id', 'integer')->hidden()->required())
	->saveUsing(function(array $data){
		return OrderRepository::create($data);
	});
```

Relations can also expose Filament-style attach and detach operations without
hardcoding routes. Register attachable records plus mutators on the relation:

```php
Panel::relation('items')
	->attachLabel('Attach product')
	->detachLabel('Remove line')
	->attachableRecordsUsing(fn($order) => ProductRepository::availableFor($order))
	->attachUsing(function($order, string $productKey, PanelRequest $request){
		return OrderRepository::attachProduct($order, $productKey);
	})
	->detachUsing(function($order, string $lineKey, PanelRequest $request){
		return OrderRepository::removeLine($order, $lineKey);
	});
```

Panel renders `attachUsing()` as a generated modal in the relation toolbar and
`detachUsing()` as row actions. The generated forms post back through the
relation operation, keep relation-scoped search, filters, view, sort, and per
page state, then redirect back to the current parent context. Authorization can
distinguish `view`, `create`, `attach`, and `detach` inside the relation
authorizer.

### Relation Manager Operations

Advanced relationship operations use the same relation contract. `associate`
and `dissociate` are available for relationships where the related record
already exists and the operation changes ownership rather than creating or
removing a join row. `reorderUsing()` describes sortable child records, and
`pivotFields()` describes editable join metadata:

```php
Panel::relation('items')
	->associateLabel('Associate product')
	->dissociateLabel('Unlink product')
	->reorderLabel('Reorder lines')
	->associateUsing(fn($order, string $productKey) => OrderRepository::associateProduct($order, $productKey))
	->dissociateUsing(fn($order, string $lineKey) => OrderRepository::dissociateLine($order, $lineKey))
	->reorderUsing(fn($order, array $lineKeys) => OrderRepository::reorderLines($order, $lineKeys), 'position')
	->pivotFields([
		Panel::field('quantity', 'number')->required()->min(1),
		Panel::field('supplier_note', 'textarea')->maxLength(180),
	])
	->updatePivotUsing(function($order, string $lineKey, array $values){
		return OrderRepository::updateLinePivot($order, $lineKey, $values);
	});
```

The relation manifest includes these operation labels, handler flags, pivot
field schemas, and the optional order column. `PanelRelationState` exposes the
same operations as structured `entries` for renderers: each entry has a stable
name, label, `enabled` flag, `authorized` flag, modal label, disabled reason,
and operation-specific metadata such as `pivot_fields` or `order_column`.
`readOnly()` keeps the entries visible for inspection but disables mutating
operations so generated toolbars and row actions can hide or explain them
without serializing callbacks. Renderers use the same action form convention for
attach, associate, detach, dissociate, reorder, and update-pivot submissions, so
nested relation pages, slide-over editors, ordering controls, and compact row
actions do not need app-specific route conventions.

Relation authorization is independent from the parent resource:

```php
Panel::relation('payments')
	->authorize(fn($ability, $record, $user) => $user?->can('view_payments') === true);
```

## Inspection Trace

The Panel framework records a compact lifecycle trace for Flightdeck and tests.
It is intentionally framework-local: Flightdeck can read it without the Panel
module needing to render a Flightdeck-specific panel.

```php
$summary=Panel::traceSummary();
$events=Panel::trace();
```

Recorded events include:

- `resource.registered`
- `page.registered`
- `command.registered`
- `request.dispatch`
- `request.render`
- `page.dashboard`
- `page.custom`
- `page.index`
- `global_search.completed`
- `surface.state`
- `navigation.state`
- `commands.state`
- `page.form`
- `page.show`
- `infolist.state`
- `relation.state`
- `relation.action.start`
- `relation.action.completed`
- `widgets.state`
- `form.hydrated`
- `form.dehydrated`
- `form.validated`
- `save.start`
- `save.validation_failed`
- `save.completed`
- `action.state`
- `transition.start`
- `transition.completed`
- `bulk_transition.start`
- `bulk_transition.completed`
- `action.start`
- `action.completed`
- `action.lifecycle_result`
- `page_action.state`
- `page_action.lifecycle_result`
- `export.csv`
- `export.json`
- `bulk_export.start`
- `bulk_export.completed`
- `import.form`
- `import.template`
- `import.start`
- `import.preview`
- `import.completed`
- `relation.render`
- `relation.records`
- `request.forbidden`

Trace entries are capped to the latest 200 events, deduplicated across the
current request and the retained session trace, and sanitize records/objects down
to compact metadata so forms and action payloads do not leak large or sensitive
application data into diagnostics.

## Testing Harness

`Panel::test()` and `$panel->test()` create a route-free test harness for Panel
definitions. The harness dispatches through the same `PanelInstance`,
`PanelManager`, `PanelRequest`, and renderer contracts used by hosted pages, but
it does not require `/admin`, `/debug`, or any application route.

```php
$panel=Panel::make('ops')
	->register($ordersResource);

$test=$panel->test();

$result=$test->render('orders', 'index', ['records'=>$orders]);
PanelTestHarness::assertOk($result);
PanelTestHarness::assertSee($result, 'Orders');

$table=$test->tableState('orders', $orders, ['status'=>'paid']);
PanelTestHarness::assertTableCount($table, count($orders));
PanelTestHarness::assertTableColumn($table, 'total');

$form=$test->validateForm('orders', ['customer'=>'']);
PanelTestHarness::assertFormInvalid($form, 'customer');

$action=$test->actionState('orders', 'approve', $orders[0]);
PanelTestHarness::assertActionVisible($action);
PanelTestHarness::assertActionEnabled($action);
```

The harness currently covers:

- HTML results, status codes, redirects, notifications, and response data.
- Resource and page dispatch through `render()`, `dispatch()`, `fragment()`,
  and `modal()`.
- Table state assertions for record counts, totals, visible/all columns, active
  filters, views, groups, pagination, and summaries through `PanelTableState`.
- Form hydration, dehydration, validation, field values, field errors, and dirty
  state through `PanelFormState`.
- Action visibility, authorization, disabled state, validation, execution
  result, selected counts, and record context through `PanelActionState`.
- Navigation, command palette, and panel manifest inspection without rendering a
  browser shell.
- Route-free accessibility audits through `PanelAccessibilityAudit`.

This is the first-party testing layer for Filament-style resources. Browser
regression testing still belongs to Playwright/Puppeteer, but framework tests can
now prove that a Panel definition, action, form, table, manifest, or modal
contract behaves correctly before a CSS or JavaScript runtime is involved.

### Accessibility Audits

`Panel::accessibilityAudit()`, `$panel->accessibilityAudit()`, and
`$panel->test()->accessibilityAudit()` inspect generated HTML without a hosted
route. The audit is intentionally a baseline regression check, not a replacement
for browser, keyboard, and screen-reader testing.

```php
$result=$panel->test()->render('orders', 'index', ['records'=>$orders]);
$audit=$panel->accessibilityAudit($result);

$audit->passed();     // true when no error-level findings exist
$audit->score();      // simple 0-100 regression score
$audit->issues();     // structured findings

PanelTestHarness::assertAccessible($audit);
```

The route-free audit checks:

- duplicate ids.
- buttons and links without accessible names.
- images missing `alt` or `aria-hidden`.
- form controls without labels, ARIA labels, titles, or placeholders.
- dialogs missing `aria-modal` or labels.
- broken ARIA id references.
- malformed `aria-live` values.
- missing reduced-motion and live-region hooks.

Use this in generated package tests and local examples to catch regressions
early, then layer Playwright/Puppeteer, axe, visual checks, and real assistive
technology passes on top for production confidence.

### Accessibility Policies

Panel fields, form sections, and resource forms can declare browser-enforced
accessibility policies as code. These policies are rendered as
`data-dp-panel-a11y-*` attributes, then the Panel browser runtime evaluates
usable width, label/adornment pressure, touch target size, and contrast after
layout settles.

```php
$panel->field('email', 'email')
	->minUsableCharacters(28)
	->minTouchTarget(44)
	->maxLabelRatio(0.5)
	->maxAdornmentRatio(0.45)
	->contrastPolicy(4.5, 'control');

$panel->schema([...])
	->columns(['default'=>1, 'md'=>6, 'xl'=>12])
	->meta(['accessibility'=>[
		'min_usable_chars'=>24,
		'min_touch_target'=>44,
		'contrast_policy'=>['min_ratio'=>4.5, 'scope'=>'control'],
	]]);
```

When policies are active, Panel summarizes each evaluated container and emits a
`DataphyrePanelAccessibilityPolicy` browser event. The summary includes
`checked`, `issue_count`, `adjustment_count`, `fields`, `issues`, and
`adjustments`. Its `status` is `needs_attention` when issues remain, `adjusted`
when automatic layout recoveries were applied without remaining issues, or
`pass` when all checked fields satisfy policy. Flightdeck listens for that event,
shows issue and adjustment rows in its Accessibility tab, records token counts
for retained snapshots, and keeps the last actionable rows visible if a later
pass report arrives without field rows.

Width recovery is row-aware. Panel first snapshots the original visual rows,
then expands fields that fail usable-width or adornment pressure checks. If a
field moves out of a crowded row, the remaining siblings in that original row are
reflowed across the available grid columns and reported with the `row_reflowed`
adjustment token.

### Regression Suites

`Panel::regressionSuite()` and `$panel->regressionSuite()` turn route-free
assertions into a named manifest. Each check records status, duration, message,
details, metadata, and failure location. This makes local examples and package
fixtures useful as repeatable regression targets instead of informal demos.

```php
$suite=$panel->regressionSuite('orders_showroom')
	->check('Orders index renders', function(PanelTestHarness $test) use ($orders): array {
		$result=$test->render('orders', 'index', ['records'=>$orders]);
		PanelTestHarness::assertOk($result);
		PanelTestHarness::assertSee($result, 'Orders');

		return ['message'=>'Orders index rendered.'];
	})
	->check('Table columns exist', function(PanelTestHarness $test) use ($orders): array {
		$table=$test->tableState('orders', $orders);
		PanelTestHarness::assertTableColumn($table, 'number');
		PanelTestHarness::assertTableColumn($table, 'status');

		return ['visible_columns'=>count($table->visibleColumns())];
	})
	->skip('Browser screenshot parity', 'Handled by Playwright.');

$report=$suite->run();
$report->ok();       // true when no checks failed
$report->toArray();  // serializable regression report
```

The bundled CLI runner executes the live example or a suite file without a
route. It exits `0` when checks pass, `1` when checks fail, and `2` when a suite
cannot be loaded.

```powershell
php dataphyre\runtime\modules\panel\kernel\panel_regression.php --example
php dataphyre\runtime\modules\panel\kernel\panel_regression.php --example --json .tmp\panel-regression.json
```

Regression suites are not a browser replacement. They sit between unit tests and
browser checks: fast enough to run from generated package tests, rich enough for
Flightdeck or docs tooling to show exactly which framework contract failed.

### Exact PHP Coverage Gate

`PanelCoverageGate` closes the blind spot in ordinary percentage-only coverage
checks: an uncompiled source file cannot silently disappear from the denominator.
It inventories every PHP file below Panel's production `Framework/` and `kernel/`
directories, requires an exact Xdebug or phpdbg line map for every file, and
fails when any executable line remains uncovered.

Run the code tests through phpdbg when Xdebug is unavailable, then evaluate the
result with the bundled gate:

```powershell
phpdbg -d memory_limit=1G -qrr bin/dataphyre-test run --scope=framework --owner=panel --kind=code `
  --no-test-cache --parallel=1 --coverage=cache/ci/panel.coverage.json `
  --coverage-require=phpdbg `
  --coverage-source=runtime/modules/panel/Framework,runtime/modules/panel/kernel `
  --coverage-closed-world --coverage-min-percent=100

php -d memory_limit=1G runtime/modules/panel/testing/panel_php_coverage_gate.php `
  --coverage=cache/ci/panel.coverage.json --require-engine=phpdbg `
  --minimum-percent=100
```

The gate exits `0` only when the exact engine, complete source inventory, and
line threshold all pass; `1` represents a coverage failure and `2` represents
invalid input or environment configuration. Add `--json` for the complete
machine-readable report, including missing files and uncovered line numbers.

### Inclusive Quality Matrix

`PanelDeveloperToolkit::inclusiveQualityMatrix()` creates a deterministic,
versioned locale, input-method, display-preference, and assistive-technology
evidence plan. Its default 126 cases keep 78 executable browser checks separate
from 48 adapter/manual declarations. Browser accessibility-tree, switch-keyboard,
voice-name, virtual-cursor, synthetic IME, and CSS-viewport zoom checks are
explicit proxies; they do not prove execution in NVDA, JAWS, VoiceOver,
TalkBack, Dragon, Voice Control, Voice Access, a native IME, a physical switch,
or native browser zoom.

```php
$matrix=PanelDeveloperToolkit::inclusiveQualityMatrix('panel_release','/panel');
$matrix->profiles();          // locale/script/direction/timezone/format metadata
$matrix->contracts();         // execution channel, proof, limits, and caveats
$matrix->browserManifests();  // browser cases only
$matrix->digest();            // canonical matrix identity
```

The developer CLI emits the matrix, authenticates its canonical browser case
mapping, and evaluates artifact-backed evidence. Every passed or failed record,
including manual or adapter evidence, must carry the exact matrix digest so a
stale report cannot satisfy a changed contract. Capability declarations require
a source and execution channel; `declared` does not mean `available`.

The browser runner validates the matrix through PHP before launch, compares the
full case profile and contract to the authenticated top-level objects, uses a
Chromium sandbox by default, and blocks non-allowlisted top-frame navigation
before contact. Input and serialized output have explicit byte/case/depth
budgets. Native AT evidence remains an application/lab adapter responsibility.

### Unified Release Gate

`testing/panel_release_gate.js` runs the asset-architecture, browser interaction,
responsive/accessibility visual, and optional exact PHP coverage lanes with one
fail-closed aggregate result. Each child report remains available beneath the
artifact directory and the aggregate records commands, duration, bounded output
tails, parsed reports, skips, and failures.

```powershell
node runtime/modules/panel/testing/panel_release_gate.js `
  --base-url=http://127.0.0.1:8089/debug `
  --coverage=cache/ci/panel.coverage.json --coverage-engine=phpdbg `
  --require-coverage --artifact-dir=cache/ci/panel-release
```

For a route-free standalone asset check, snapshot the generated bundles and run
only the release gate's asset lane:

```powershell
php runtime/modules/panel/testing/panel_asset_snapshot.php `
  --output-dir=cache/ci/panel-assets

node runtime/modules/panel/testing/panel_release_gate.js --lanes=asset `
  --css-file=cache/ci/panel-assets/panel.css `
  --js-file=cache/ci/panel-assets/panel.js `
  --artifact-dir=cache/ci/panel-release-assets
```

`--css-file` and `--js-file` are a pair. Interaction and visual lanes still
require a mounted `--base-url`; each consuming application supplies its own
integration fixture, routes, authentication, and seed state.

From a Dataphyre source checkout with a consuming application in a sibling
directory, launch its live fixture through the adapter which forces the current
Dataphyre root:

```bash
export DP_PANEL_LIVE_EXAMPLE_ENTRY="$(realpath \
  ../consumer-application/path/to/panel-live-example/index.php)"
export DP_PANEL_RUNTIME_ROOT="$(pwd)"
php -S 127.0.0.1:8097 source-checkout-maintainer-tool
```

The equivalent PowerShell setup is:

```powershell
$env:DP_PANEL_LIVE_EXAMPLE_ENTRY = (Resolve-Path `
  '..\consumer-application\path\to\panel-live-example\index.php').Path
$env:DP_PANEL_RUNTIME_ROOT = (Resolve-Path '.').Path
php -S 127.0.0.1:8097 source-checkout-maintainer-tool
```

In a second terminal, execute the complete interaction registry:

```powershell
node runtime/modules/panel/testing/panel_interaction_regression.js `
  --base-url=http://127.0.0.1:8097/debug `
  --report=cache/ci/panel-interaction.json
```

The runner needs `puppeteer-core` below `.tmp/puppeteer-check/`, `.tmp/`, or the
Panel testing directory, plus a system Chrome or Edge executable (or an explicit
`--browser`). The adapter lives under export-ignored `dev/` intentionally: it is
a source-tree integration aid, not part of the distributed runtime contract.

The default visual lane is audit-only, so it checks layout, overflow,
accessibility, console errors, themes, and configured environment axes without
rewriting screenshot baselines. Use `--visual-regression` for approved baseline
comparison. `--update-baselines` is deliberately explicit and should only be
used after visual review. Every scenario/environment combination runs in a
fresh browser context, isolating cookies, server-side Panel preferences, local
storage, and session storage so screenshots cannot depend on matrix order. The
JSON report publishes this boundary under `isolation`. Visible component-level
horizontal overflow is also fail-closed: hidden and off-canvas DOM is excluded,
ordinary internal clipping is blocking, and intentional data-table scrolling
must be owned by a rendered `data-dp-panel-overflow-policy="scroll-x"` region.
The report separates `blockingOverflowSources` from
`allowedOverflowSources`, retaining the complete counts in `overflowSummary`;
single-line ellipsis, native text-control value scrolling, and positioned
in-viewport children are classified rather than silently suppressing all
`overflow: auto` elements. The canonical ultra-narrow rules continue stacking
form actions, adornments, filter chips, modal content, and drawer navigation
down to the 160 CSS-pixel effective viewport exercised by the 320px/200% zoom
lane. Desktop table action columns reserve enough inline space for the complete
normalized action cluster, while relation toolbars replace max-content action
floors with a bounded grid once their shell reaches the responsive range.
`--lanes` supports
focused local runs, while
`--report-only` preserves a zero process exit for diagnostic collection without
changing the report's failed state.

The first-party interaction suite includes
`panel.editor-adapter.lifecycle`. It verifies asynchronous mount, canonical form
submission, command routing, token rendering, late registration, fallback,
same-turn moves, remount, and detached-root cleanup against the live showroom.
Consuming applications should add route/auth/storage-specific probes for their
chosen vendor package and configuration; those complement rather than replace
the framework lifecycle contract.

## Scaffolding

`Panel::scaffold()` and `$panel->scaffold()` generate starter artifacts without
assuming any route or controller. A scaffold result is inspectable first:
it contains the artifact kind, normalized name, class, suggested path, contents,
byte count, and metadata. Nothing is written until `write()` is called.

```php
$scaffold=$panel->scaffold();

$resource=$scaffold->resource(App\Panel\Resources\OrderResource::class, [
	'name'=>'orders',
	'label'=>'Order',
	'columns'=>['id', 'number', 'status', 'total'],
	'fields'=>['number', 'status', 'total'],
]);

echo $resource->path();
echo $resource->contents();

// Optional, explicit file write.
$resource->write(overwrite: false);
```

First-party scaffold kinds:

- `resource()` creates a `Resource` factory with generated columns and fields.
- `page()` creates a `PanelPage` factory with group, icon, and starter content.
- `provider()` creates a `PanelProvider` that registers generated resources and
  pages.
- `plugin()` creates a `PanelPlugin` with package identity and lifecycle hooks.
- `theme()` creates a `PanelThemePreset` factory.
- `test()` creates a starter class using `PanelTestHarness`.
- `suite()` generates several artifacts from a single blueprint array.

This is deliberately not a CRM-specific generator and not tied to `/admin`.
For production writes, prefer `PanelScaffoldWriter` over writing individual
results. The writer resolves every destination below one existing workspace
root, rejects traversal, NUL paths, symbolic-link escapes, directory collisions,
duplicate case-folded targets, and stale preflight state. It stages the whole
batch before publication, supports explicit `error`, `skip`, and `replace`
conflict policies, rolls an incomplete commit back, and preserves recovery
artifacts if the filesystem prevents rollback.

```php
$writer=$scaffold->writer($projectRoot); // or PanelScaffoldWriter::make(...)

// No filesystem mutation.
$plan=$writer->apply([$resource, $page], policy: 'error', dryRun: true);

// Transactional publication after reviewing the plan.
$result=$writer->apply([$resource, $page], policy: 'replace');
```

`PanelScaffoldWriter::discoverNamespace()` reads both Composer `autoload` and
`autoload-dev` PSR-4 mappings, selecting the longest matching source path and
falling back to a deterministic directory-to-namespace convention.

The first-party CLI is preview-only unless `--write` is present:

```text
php source-checkout-maintainer-tool --kind resource --class OrderResource --root .
php source-checkout-maintainer-tool --config panel-scaffold.json --policy error --write
```

A suite config contains an `artifacts` array using the same `kind`, `class`, and
`options` definitions accepted by `suite()`. Global `namespace`, `base_path`,
`test_namespace`, and `test_base_path` defaults can be overridden per artifact.
Unknown flags, duplicate flags, unsupported kinds, malformed JSON, and ambiguous
single-artifact/config combinations fail closed with machine-readable JSON.

## Data Jobs

`Panel::importJob()`, `Panel::exportJob()`, and `$panel->dataJob()` create a
queue-ready contract for long-running import/export style work. Jobs can run
synchronously today, but the plan/result shape is designed so a queue adapter can
persist and resume the same work later.

```php
$result=$panel->exportJob('orders_snapshot')
	->resource($ordersResource)
	->records($records)
	->chunkSize(250)
	->queue('panel')
	->map(static fn(array $record): array => [
		'number'=>$record['number'],
		'status'=>$record['status'],
		'total'=>$record['total'],
	])
	->run();

$result->status();     // completed, completed_with_failures, failed
$result->processed();  // processed rows
$result->failures();   // compact failure report
$result->artifacts();  // generated export/failure files
```

Data jobs expose:

- chunk plans with `total`, `chunk_size`, queue name, and queueable flag.
- progress events for chunk start/completion.
- per-row success/failure accounting.
- failure reports with compact row shape and generated failure CSV artifacts.
- export artifacts generated from `map()` results.
- `PanelDataJobResult` summaries suitable for tests, Flightdeck, dashboards, and
  future background workers.

The built-in job runner is intentionally storage-neutral. Production adapters can
store job state, generated artifacts, retries, and downloadable failure files
without changing the resource, form, or table definitions that produced the job.

## Notification Inbox

`PanelNotification` still represents immediate toast-style feedback. For durable
notifications, `Panel::notificationInbox()` and `Panel::inboxNotification()`
describe notification records with recipients, read state, dismissal state,
action links, icons, type counts, and an adapter-ready manifest.

```php
$inbox=Panel::notificationInbox();

$inbox->add(
	Panel::notify('Critical orders need review.', 'warning', 'Risk queue')
		->persistent()
		->action('Open risk view', '/admin/orders?view=risk'),
	'operations'
);

$record=Panel::notify('Seller documents arrived.', 'info')->inbox('trust');

$read=$inbox->add([
	'type'=>'success',
	'title'=>'Import complete',
	'message'=>'The order import finished with one failure report.',
	'recipient'=>'operations',
	'action_label'=>'Open import',
	'action_url'=>'/admin/imports/42',
])->markRead();

$inbox->counts();    // total, unread, read, dismissed, by_type
$inbox->manifest();  // serializable inbox contract for renderers/adapters
```

The built-in inbox is intentionally in-memory. Applications can back the same
record shape with SQL, cache, queue workers, email/web-push fanout, or a
per-user notification center without changing action handlers that already emit
`PanelNotification` payloads.

## Media Collections

`Panel::mediaLibrary()`, `Panel::mediaCollection()`, and `$panel->mediaItem()`
describe files above raw upload fields. The contract is storage-neutral: it
names collections, disks, paths, visibility, accepted types, size limits,
variants, preview expectations, cleanup policy, and item manifests without
requiring a CDN, local filesystem, or object-store adapter.

```php
$library=$panel->mediaLibrary();
$library->register(
	$panel->mediaCollection('product_images')
		->disk('vestra')
		->path('products/{collection}')
		->public()
		->images()
		->multiple()
		->maxSize(5 * 1024 * 1024)
		->variant('thumb', ['width'=>320, 'height'=>320, 'fit'=>'cover'])
		->variant('detail', ['width'=>1400, 'height'=>1400, 'fit'=>'contain'])
		->cleanup(['orphan_ttl_days'=>14, 'delete_derivatives'=>true])
);

$item=$library->item('product_images', [
	'name'=>'paper.webp',
	'type'=>'image/webp',
	'size'=>382144,
	'error'=>UPLOAD_ERR_OK,
]);

$item->previewable();       // true
$item->validation();        // collection validation result
$library->manifest();       // portable collection and variant contract
```

Upload fields can reference the same collection so forms, manifests, tests, and
future storage adapters all agree on the policy:

```php
Panel::field('receipt', 'file')
	->acceptedTypes(['application/pdf', 'image/*'])
	->maxFileSize(2 * 1024 * 1024)
	->mediaCollection($library->collection('product_images'));
```

Media collections expose:

- named collection manifests for forms, resources, tests, and Flightdeck.
- accepted MIME/extension policies including `image/*` and `.pdf` style rules.
- minimum and maximum size validation using normal PHP upload arrays.
- per-collection disk, path, visibility, and cleanup metadata.
- image/document variants as declarative transform definitions.
- previewable item metadata without assuming how the file is ultimately served.

Collections remain the declarative policy layer; `PanelMediaManager` is the
byte-owning runtime. `PanelLocalMediaDisk` provides a crash-safe local
reference, while `PanelDataphyreStorageMediaDisk` connects the same manager to
any named Dataphyre Storage disk, including decorated local, memory,
S3-compatible, Vestra, mirrored, encrypted, policy, quota, retention, or
failover compositions selected by the host. Applications install scanners and
transformers on the manager without changing the form or resource definitions
that declared the collection.

### Storage-backed media runtime

```php
use Dataphyre\Panel\PanelAtomicSnapshotStore;
use Dataphyre\Panel\PanelDataphyreStorageMediaDisk;
use Dataphyre\Panel\PanelMediaManager;
use Dataphyre\Panel\PanelPdoSnapshotStore;

$disk=new PanelDataphyreStorageMediaDisk(
	$storageManager,
	'private',
	'panel/media',
	[
		'name'=>'private-media',
		'default_max_bytes'=>1024 * 1024 * 1024,
		'write_options'=>['visibility'=>'private'],
	]
);

$catalog=new PanelAtomicSnapshotStore(
	$privateStateRoot.'/panel-media-catalog',
	'panel.media.catalog',
	['items'=>[], 'uploads'=>[]]
);

// Shared catalogue for multiple Panel nodes. The host owns the PDO connection
// and runs this explicit, idempotent schema installation during deployment.
$catalog=new PanelPdoSnapshotStore(
	$pdo,
	'tenant:'.$tenantId.':private-media',
	'panel.media.catalog',
	['items'=>[], 'uploads'=>[]],
	[
		'table_prefix'=>'panel_snapshot',
		'change_retention'=>16384,
	]
);
$catalog->installSchema();

$media=PanelMediaManager::forDisk(
	$disk,
	$catalog,
	$mediaSigningKey,
	[
		'delivery_url'=>'/panel/media/private',
		'cleanup_grace'=>7 * 86400,
	]
);

$media
	->scanner($malwareScanner, 'malware')
	->transformer($imageTransformer, 'images');

$upload=$media->startUpload(
	'orders/91/invoice.pdf',
	$totalBytes,
	[
		'id'=>$uploadId,
		'chunk_size'=>5 * 1024 * 1024,
		'checksum'=>$sha256,
	]
);

foreach($chunks as $index=>$bytes){
	$media->receiveChunk(
		$uploadId,
		$index,
		$bytes,
		hash('sha256', $bytes)
	);
}

$completion=$media->completeUpload($uploadId, context:[
	'name'=>'Invoice 91',
]);
$delivery=$media->issue(
	$completion['item']['id'],
	ttl: 300,
	disposition: 'attachment',
	audience: $operatorId
);
```

The adapter buffers each transfer only into bounded temporary streams, verifies
size and SHA-256 after Storage accepts a write, and restores the previous object
or removes a partial new object when verification fails. The manager catalogue
is an explicit `PanelSnapshotStore`. Local atomic snapshots remain the
single-node reference. `PanelPdoSnapshotStore` provides the bundled distributed
catalogue for MySQL, PostgreSQL, and SQLite: one explicit-migration table set
hosts SHA-256-isolated scopes, locked scope-row commits, canonical JSON and
size/digest integrity, retained ordered changes, stale/future cursor resets,
host-transaction savepoints, and PHP 8.2-safe manual SQLite write transactions.
The caller mutation is never replayed inside one call; post-callback conflicts
and uncertain commits are explicit retryable errors. Manifests expose
fingerprints and capabilities, never scope/schema names, table prefixes, SQL,
credentials, connection details, or provider errors.

Panel does not create Storage disks, credentials, PDO connections, database
schemas, object-store policies, routes, scanners, transformers, or workers.
Hosts still operate schema rollout, database HA/backup/monitoring, and
external-effect idempotency.

## Documentation Catalog

`Panel::documentationCatalog()`, `Panel::documentationEntry()`, and the matching
instance methods describe docs as data. Entries can carry a category, completion
status, summary, public API references, cookbook examples, links, tags, and
metadata. The catalog can then be searched or exported as a manifest for docs
pages, generated help, compatibility checks, tests, or Flightdeck.

```php
$catalog=$panel->documentationCatalog()
	->meta(['package'=>'operations-panel']);

$catalog->register(
	$panel->documentationEntry('resources', 'Resources')
		->category('Builder')
		->status('solid')
		->summary('Resources define records, forms, tables, actions, policies, search, and manifests.')
		->api(['Panel::resource()', 'Resource::fields()', 'Resource::columns()'])
		->example('Minimal resource', "Panel::resource('orders')->column(Panel::column('number'));")
		->link('Resource docs', 'Dataphyre_Panel.md#resource-builder')
		->tags(['resource', 'table', 'form'])
);

$catalog->search('resource'); // matching PanelDocumentationEntry objects
$catalog->manifest();         // serializable reference/cookbook contract
```

`PanelDocumentationPublisher` turns that catalog into a real release artifact.
It tokenizes PHP source without requiring or executing it, inventories public
namespace-level types and their declared members, attributes, inheritance,
interfaces, traits, public trait adaptations, promoted properties, deprecation
state, and PHP 8.4 property hooks. It emits deterministic Markdown and JSON for
the API, cookbook, and package compatibility matrix under a canonical lowercase
SemVer directory. The returned `PanelDocumentationPublication` remains a dry
plan until it is explicitly applied:

```php
$publication=PanelDocumentationPublisher::make(__DIR__.'/src/Panel')
	->build(
		'2.1.0',
		$catalog,
		Panel::compatibilityMatrix($packageManifests),
		['base_path'=>'docs/panel', 'title'=>'Operations Panel'],
	);

// Optional: adapt Panel's verified corpus to Datadoc's universal portal.
$publication=Panel::documentationPortal()->decorate($publication,[
	'default_theme'=>'system',
	'canonical_base_url'=>'https://docs.example.test/panel/2.1.0/',
]);

$preview=$publication->apply($projectRoot, 'error', true);
$written=$publication->apply($projectRoot, 'error', false);
```

Every source path is realpath-confined, source symlinks are rejected, conditional
and function-local classes are excluded, generated paths are portable and
collision checked, unsafe link schemes are removed after fixed-point decoding,
and secret-shaped manifest keys are redacted. The publication manifest records
every artifact digest plus a deterministic source fingerprint. A write stages a
private complete release tree, verifies its exact contents, and exposes the
whole version with one final directory rename while holding a non-blocking
publisher lock. Existing byte-identical releases are idempotent; partial,
tampered, replacement, and skip publications fail closed and require a new
version. On POSIX, a real write normalizes only the root-confined publication
ancestors and the verified release tree to `0755` directories and `0644` files,
so a separate static-web user can traverse output produced under a restrictive
`umask`. An identical real write repairs legacy modes without claiming that
artifact bytes changed; a dry run never repairs permissions, and the caller's
workspace root is never chmodded. The equivalent CLI is preview-first:

```text
php source-checkout-maintainer-tool --version 2.1.0
php source-checkout-maintainer-tool --version 2.1.0 --catalog docs-catalog.json --packages packages.json --write
```

`--write` is the only switch that publishes. CLI source/config inputs and all
output remain confined to `--root`; there is intentionally no replace or skip
policy for immutable version directories.

Panel does not own a documentation-site renderer. `PanelDocumentationPortal`
is a compatibility adapter: it extracts the Markdown corpus from a verified
Panel publication, delegates HTML, navigation, local search, version metadata,
browser assets, and security policy to Datadoc's `DocumentationPortal`, then
returns the same immutable `PanelDocumentationPublication` type. The adapter
requires the optional Datadoc Framework module only when the portal is
requested. Other Dataphyre modules and products can use Datadoc directly via
`DocumentationPortalPublication` or `source-checkout-maintainer-tool` without
depending on Panel.

The release workflow executes this boundary end to end rather than relying on
the adapter's unit tests alone: `panel_docs.php --portal` generates the real
Panel API corpus, Datadoc publishes its immutable version directory, and the
shared browser regression exercises search, deep links, desktop/mobile
navigation, themes, print, forced colors, reduced motion, overflow, and CSP.

When Flightdeck is installed, `/dataphyre/panel` presents the Panel lifecycle
summary, recent trace events, and the currently registered resources. The Panel
module still owns only the resource language; Flightdeck owns the inspection
surface.
