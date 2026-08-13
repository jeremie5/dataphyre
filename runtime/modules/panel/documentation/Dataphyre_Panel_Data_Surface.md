# Panel DataSurface

Panel DataSurface is the bounded-data protocol for large tables, alternate
record collections, and advanced data canvases. It supports `table`, `list`,
`cards`, `timeline`, `calendar`, `gallery`, `spreadsheet`, `pivot`, `tree`,
`graph`, `gantt`, `heatmap`, `map`, and `canvas` from one typed definition. The
first window is useful server-rendered HTML; the browser runtime progressively
replaces only the records inside that surface when a signed previous, next,
refresh, or cross-filter intent is used.

DataSurface does not turn client input into a data-source query. A client holds
an expiring, HMAC-signed window intent. The server verifies that intent against
the current panel, tenant, principal, resource, source, surface, projection,
query fingerprint, and requested range before it resolves a source or query.

## Security contract

The following rules are part of the protocol rather than application advice:

- Every registry requires an authorization callback. Authorization runs before
  source lookup, capability inspection, query resolution, or query execution.
- Intents use HMAC-SHA-256 and an explicit key ID. The active key signs new
  intents; retained keys may verify unexpired intents during rotation.
- Signing keys contain at least 32 bytes and never appear in registry,
  definition, signer, or platform manifests.
- Intents are bound to the trusted panel, tenant, and principal. Tenant and
  principal values are represented by domain-separated keyed tags rather than
  their original values.
- Query state is JSON-bounded and inspectable. Tenant, authorization, metadata,
  upstream cursors, credentials, passwords, tokens, secrets, and private keys
  are rejected recursively. Do not treat a signed token as encrypted storage.
- Requests contain `intent` and may contain one bounded `interaction` object.
  The maximum token, interaction, state, record, range, response, and TTL sizes
  are enforced before use. Cross-filter values are authenticated by count and
  digest and are never copied into public authorization envelopes.
- A result must provide a scalar stable key for every record. Duplicate keys,
  unprojectable records, inconsistent page metadata, excessive records, and
  unsupported adapter capabilities fail closed.
- Upstream cursor values are never returned as response fields. The server
  embeds them only in newly signed continuation intents.
- The endpoint emits `Cache-Control: no-store, private` and
  `X-Content-Type-Options: nosniff`, and returns stable public error messages
  without adapter or exception details.

The host route still owns session authentication, CSRF enforcement for
cookie-authenticated requests, rate limits, and construction of the trusted
`PanelDataSurfaceContext`. Never populate tenant or principal values from the
request body.

## Define and register a surface

```php
use Dataphyre\Panel\PanelArrayDataSource;
use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelDataSourceRegistry;
use Dataphyre\Panel\PanelDataSurfaceContext;
use Dataphyre\Panel\PanelDataSurfaceDefinition;
use Dataphyre\Panel\PanelDataSurfaceIntentSigner;
use Dataphyre\Panel\PanelDataSurfaceProjection;
use Dataphyre\Panel\PanelDataSurfaceRange;
use Dataphyre\Panel\PanelDataSurfaceRegistry;

$sources=(new PanelDataSourceRegistry())->register(
    'orders',
    new PanelArrayDataSource($orders, ['name'=>'orders']),
);

$projection=PanelDataSurfaceProjection::make(
    fields: ['id', 'number', 'customer.name', 'status', 'total'],
    stableKey: 'id',
    slots: [
        'title'=>'number',
        'description'=>'customer.name',
        'badge'=>'status',
        'meta'=>'total',
    ],
    labels: [
        'number'=>'Order',
        'customer.name'=>'Customer',
        'total'=>'Total',
    ],
);

$ordersSurface=PanelDataSurfaceDefinition::make(
    id: 'orders_window',
    resource: 'orders',
    source: 'orders',
    surface: 'table',
    projection: $projection,
    defaultRange: PanelDataSurfaceRange::make(
        start: 0,
        length: 40,
        overscanBefore: 8,
        overscanAfter: 12,
    ),
    queryResolver: static function(
        PanelDataSurfaceContext $context,
        array $publicState,
    ): PanelDataQuery {
        return PanelDataQuery::fromArray($publicState)->sort('id');
    },
    options: [
        'title'=>'Orders',
        'description'=>'A bounded live order window.',
        'empty_message'=>'No orders match this view.',
        'endpoint'=>'/admin/data-surface',
        'estimated_item_size'=>52,
        'virtualize'=>true,
    ],
);

$signer=new PanelDataSurfaceIntentSigner(
    keys: [
        '2026_07'=>app_secret_bytes('panel_data_surface_current'),
        '2026_06'=>app_secret_bytes('panel_data_surface_previous'),
    ],
    currentKeyId: '2026_07',
);

$surfaceRegistry=(new PanelDataSurfaceRegistry(
    sources: $sources,
    signer: $signer,
    authorize: static function(
        array $envelope,
        PanelDataSurfaceContext $context,
    ): bool {
        return app_policy_allows(
            principal: $context->principal(),
            tenant: $context->tenant(),
            resource: (string)$envelope['resource'],
            operation: (string)$envelope['operation'],
        );
    },
))->register($ordersSurface);

$panel->useDataSurfaces($surfaceRegistry);
```

`PanelInstance::useDataSurfaces()` is deliberately explicit: Panel never
constructs a weak signer or permissive authorizer as a fallback. The attached
registry is available through `dataSurfaces()`, `dataSurfaceEndpoint()`, the
request-scoped `PanelConfig::dataSurfaces()`, and the root Panel manifest.
`withoutDataSurfaces()` removes the attachment without destroying the detached
registry.

Attachment registers no HTTP route and supplies no session authentication,
CSRF policy, rate limiter, signing key, authorizer, source persistence, or
background worker. The root manifest is a secret-free registration snapshot;
it exposes neither key material nor live adapter objects and never calls source
capability code while serializing. A configured manifest therefore means the
registry is attached, not that a host endpoint has been mounted or authorized.

Registrations are instance-owned and contribution-layered. Code running inside
a Panel plugin can call `registerDataSurface()`; the active plugin id is recorded
as provenance, conflicts follow `reject`, `keep_first`, or `replace`, and
unloading a replacement reveals the previous definition. Registry checkpoints
are exact in-process rollback values. Plugin registration, boot, unload, and
reload restore in-place definition changes atomically. Checkpoints include live
trusted objects and must never be persisted or exposed as a public manifest.
When installing the same registry in `PanelPlatform`, register it as
`data_surfaces.registry`; the generic `PanelCheckpointableService` transaction
contract then gives platform rollback the same semantics.

`PanelDataSourceRegistry` snapshots source capabilities at registration.
DataSurface checks the required cursor, offset, select, search, filter, and sort
capabilities again when it materializes the bounded query. A definition cannot
silently emulate a cursor on an offset-only source or vice versa.

## Render the first window

```php
use Dataphyre\Panel\PanelDataSurfaceWindowRequest;
use Dataphyre\Panel\PanelRenderer;

$context=PanelDataSurfaceContext::fromTrusted('operations', [
    'tenant_id'=>$authenticatedTenantId,
    'principal_id'=>$authenticatedOperatorId,
    'correlation_id'=>$requestCorrelationId,
]);

$intent=$surfaceRegistry->issue(
    'orders_window',
    $context,
    PanelDataQuery::make()->where('status', 'review')->sort('id'),
);
$window=$surfaceRegistry->execute(
    PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),
    $context,
);

echo PanelRenderer::dataSurface($ordersSurface, $window, $intent, [
    'id'=>'review-orders',
]);
```

The renderer outputs a real semantic table for a table surface and real list
and article structure for the other five collection surfaces. Advanced surfaces
use dedicated table, grid, tree, graph, timeline, spatial, or canvas semantics
from a typed server-projected model. The signed configuration is
encoded as inert JSON. Text, labels, identifiers, URLs, and values are escaped
or scheme-checked before entering markup.

`ResourceTable::dataSurface($ordersSurface)` attaches the typed definition to a
resource table manifest. It does not bypass the registry, authorization, or
endpoint steps shown above.

## Advanced DataCanvas definitions

Advanced surfaces use `PanelDataCanvasSpec` to map semantic roles, aggregation,
selection, linked filtering, drill-through, editing declarations, frozen
spreadsheet fields, labels, legends, zoom, and snap behavior. Pivot, heatmap,
tree, graph, Gantt, map, and freeform canvas geometry is projected on the server.
The browser validates and renders that model; it does not silently recompute
aggregates, graph topology, dates, or spatial coordinates.

```php
use Dataphyre\Panel\PanelDataCanvasSpec;

$projection=PanelDataSurfaceProjection::make(
    fields: ['id', 'region', 'quarter', 'revenue', 'status'],
    stableKey: 'id',
    slots: [
        'row'=>'region',
        'column'=>'quarter',
        'value'=>'revenue',
        'cross_filter'=>'status',
    ],
);

$pivot=PanelDataSurfaceDefinition::make(
    id: 'revenue_matrix',
    resource: 'orders',
    source: 'orders',
    surface: 'pivot',
    projection: $projection,
    options: [
        'canvas'=>PanelDataCanvasSpec::make('pivot', $projection, [
            'aggregate'=>'sum',
            'selection'=>'multiple',
            'cross_filter_group'=>'orders',
            'cross_filter_field'=>'status',
            'drill_url'=>'/admin/orders',
            'drill_parameter'=>'record',
        ]),
    ],
);
```

Linked canvases post an interaction shaped as
`{"type":"cross_filter","values":[...]}` beside the signed intent. The
registry reauthorizes the current definition and context, verifies the
definition fingerprint, applies the predicate through `PanelDataQuery`, and
returns a fresh signed window. A selection never becomes an ad hoc browser-side
query.

Studio contract version 3 exposes the same primitive as a data-only
`data_surface` component. It materializes to a callback-free
`PanelDataSurfaceDefinition`; a `PanelStudioPageBundle` can register it only
into an explicitly supplied `PanelDataSurfaceRegistry`. Studio visual previews
use the redacted bounded preview dataset and never execute the named source.

## Mount the window endpoint

```php
use Dataphyre\Panel\PanelDataSurfaceEndpoint;

// Authenticate and enforce the host's CSRF/rate-limit policy first.
$result=(new PanelDataSurfaceEndpoint($surfaceRegistry))->handle(
    file_get_contents('php://input'),
    'operations',
    [
        'tenant_id'=>$authenticatedTenantId,
        'principal_id'=>$authenticatedOperatorId,
        'correlation_id'=>$requestCorrelationId,
    ],
);

http_response_code($result['status']);
foreach($result['headers'] as $name=>$value){
    header($name.': '.$value);
}
echo json_encode($result['body'], JSON_THROW_ON_ERROR);
```

The browser runtime sends JSON with the exact shape below. It rejects a
cross-origin endpoint even when the rendered configuration contains one.

```json
{"intent":"signed-window-intent"}
```

For linked filtering the request is:

```json
{
  "intent": "signed-window-intent",
  "interaction": {
    "type": "cross_filter",
    "values": ["review", "blocked"]
  }
}
```

The successful response exposes projected records, visible positions, bounded
window metadata, explicit total semantics, and optional signed continuations.
It never exposes an upstream cursor.

## Window semantics

`start` and `length` describe the visible window. `overscan_before` and
`overscan_after` permit a bounded set of neighboring records so keyboard and
scroll transitions do not flash an empty region. All four values are signed.

For offset sources, previous and next windows advance by the visible length.
For cursor sources, the source cursor is consumed server-side and re-signed
inside the continuation intent. `total` is nullable:

- a numeric total sets `total_known` to `true`;
- `null` sets `total_known` to `false`;
- `has_after` may be `true`, `false`, or `null` when an unknown-total source
  cannot prove whether another window exists;
- no next intent is emitted without a usable source cursor, even when
  `has_after` is unknown.

## Progressive enhancement and accessibility

The SSR fallback remains visible and operable when JavaScript does not run.
Enhancement is scoped to the nearest `.dp-panel` root, stores state in weak
collections, and cleans observers and listeners on page teardown. It does not
install a document-wide mutation observer.

When enhanced, DataSurface:

- preserves semantic table, list, article, heading, and description structure;
- announces window changes through a polite live region;
- enables previous, next, and refresh controls only after configuration
  validation;
- supports Arrow, Home, End, Page Up, and Page Down navigation within the
  bounded records;
- replaces records with `textContent` and created elements rather than HTML
  string injection;
- keeps a bounded DOM window with start and end spacers;
- uses server-projected advanced models without virtual spacers for aggregate
  or spatial canvases;
- supports roving focus, single or multiple selection, and linked server-side
  cross-filter updates with a bounded latest-request queue;
- uses a same-origin fetch with JSON content type and an available CSRF meta
  token;
- adapts tables into labelled record rows at narrow container widths without a
  horizontal page scrollbar;
- supports LTR and RTL direction, reduced motion, forced colors, print, touch
  target sizing, and container-query fallback behavior.

The `data-dp-data-surface` marker activates the `data-surface` asset capability.
Pages without that marker or an explicit `data-surface` capability declaration
do not load the optional DataSurface CSS or runtime.

## Key rotation

Add a new key ID and make it current before removing the old key. Keep the old
key only for the longest issued TTL plus the configured verification leeway.
New continuation intents always use the current key. Removing a retained key
immediately invalidates its remaining intents, which is appropriate for an
incident response but not a routine deploy.

The default intent TTL is 300 seconds. The accepted range is 30 through 3600
seconds. Prefer short TTLs and issue a fresh first window when the user's query,
permissions, tenant, projection, or resource definition changes.

## Migration from an unbounded resource table

1. Choose a unique, immutable scalar stable key. Do not use a display index.
2. Register the existing source in `PanelDataSourceRegistry` and declare its
   real pagination and query capabilities.
3. Create the smallest projection needed by the chosen surface; map semantic
   slots for non-table renderers.
4. Move tenant and permission constraints into trusted context and the
   mandatory authorization callback. Keep only public view state in the query.
5. Choose a bounded visible length and conservative overscan values.
6. Render an authorized first window on the server.
7. Mount the endpoint behind the host's authentication, CSRF, and rate-limit
   middleware.
8. Test known and unknown totals, first and last windows, stale definitions,
   revoked principals, key rotation, duplicate keys, mobile widths, RTL, forced
   colors, reduced motion, keyboard navigation, and JavaScript-disabled SSR.

## Verification

The focused suite runs on PHP 8.2 and 8.4:

```text
php runtime/modules/panel/testing/panel_data_surface_test_runner.php
```

The exact phpdbg proof fails unless every executable line in the dedicated
protocol, renderer, and asset traits is executed:

```text
phpdbg -qrr runtime/modules/panel/testing/panel_data_surface_phpdbg_coverage.php
```

The client and real-browser gates are:

```text
node runtime/modules/panel/testing/panel_data_surface_runtime.test.js
php -S 127.0.0.1:8098 runtime/modules/panel/testing/fixtures/panel_browser_showroom.php
node runtime/modules/panel/testing/panel_data_surface_browser_evidence.js
```

The browser evidence covers 320, 390, 768, and 1440 pixel viewports in LTR and
RTL, performs a real signed continuation request, checks keyboard movement and
semantic structure, and records screenshots plus a machine-readable report in
`.tmp/panel-data-surface-evidence`.
