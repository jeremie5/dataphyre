# Dataphyre Panel remote HTTP data source

`PanelHttpDataSource` is Panel's strict read-only adapter for a server-owned
remote HTTP service. It implements the normal `PanelDataSource` contract but
does not bundle an HTTP client, credentials, DNS policy, proxy policy, or egress
allowlist. Those concerns belong to an injected `PanelHttpDataSourceTransport`.

The adapter accepts no URL, header, transport class, callback, or credential
from a `PanelDataQuery`. One immutable `PanelHttpDataSourceDefinition` owns the
endpoint and all protocol limits. Its public manifest confirms that an endpoint
is configured without serializing the URL.

## Closed server registration

Define an exact capability pin rather than discovering whatever an upstream
happens to support at request time:

```php
use Dataphyre\Panel\PanelHttpDataSource;
use Dataphyre\Panel\PanelHttpDataSourceCapabilityPin;
use Dataphyre\Panel\PanelHttpDataSourceDefinition;
use Dataphyre\Panel\PanelHttpDataSourceScope;
use Dataphyre\Panel\PanelHttpDataSourceScopeMapper;
use Dataphyre\Panel\PanelDataSourceResourceBridge;

$pin = PanelHttpDataSourceCapabilityPin::readOnly('id', [
    'include' => true,
    'relations' => true,
    'relation_depth' => 2,
    'max_limit' => 100,
], version: 3);

$definition = new PanelHttpDataSourceDefinition(
    'catalog_remote',
    $_ENV['PANEL_CATALOG_ENDPOINT'],
    $pin,
    [
        'cursor_keys' => [
            '2026_07' => $_ENV['PANEL_REMOTE_CURSOR_KEY_CURRENT'],
            '2026_06' => $_ENV['PANEL_REMOTE_CURSOR_KEY_PREVIOUS'],
        ],
        'cursor_active_key' => '2026_07',
        'cursor_ttl' => 900,
        'timeout_ms' => 3000,
        'max_attempts' => 2,
        'circuit_failure_threshold' => 5,
        'circuit_open_ms' => 30000,
    ],
);

$scopeMapper = new class implements PanelHttpDataSourceScopeMapper {
    public function map($query, $definition): PanelHttpDataSourceScope
    {
        // This is an explicit projection. Do not copy authorizationMetadata().
        $authority = $query->authorizationMetadata();

        return PanelHttpDataSourceScope::make(
            principal: (string) $authority['actor_id'],
            tenant: $query->tenantKey(),
            authorization: [
                'permissions' => array_values($authority['permissions'] ?? []),
            ],
        );
    }
};

$source = new PanelHttpDataSource(
    $applicationHttpTransport,
    $definition,
    $scopeMapper,
    $requestAwareRuntime, // Optional; supplies deadline/cancellation behavior.
);
```

The mapper is required and exceptions deny the request. `PanelDataQuery`
authorization and metadata are never serialized wholesale. The approved scope
DTO rejects secret-bearing keys such as tokens, cookies, passwords,
credentials, and authorization headers. Transport authentication remains in
the injected transport and is not part of the scope DTO.

## Platform and Panel registration

The HTTP foundation is advertised through the optional `data` platform domain,
but availability does not construct or register a source. Register the normal
data source only after constructing the trusted transport, definition, scope
mapper, and optional request-aware runtime above:

`PanelPlatformManifest` publishes the `http_adapter`, `http_definition`,
`http_capability_pin`, `http_scope`, `http_scope_mapper`, `http_transport`,
`http_cursor`, and `http_runtime` feature keys. The scripted transport is a test
fixture and is intentionally not advertised as a production platform feature.

```php
$platform->dataSources()->register('catalog_remote', $source);

// Equivalent when this PanelInstance has a configured platform whose
// data.registry service is already ready; otherwise it fails closed.
$panel->registerDataSource('catalog_remote', $source);

$resource = PanelDataSourceResourceBridge::using(
    $panel->dataSources()->get('catalog_remote'),
    ['per_page' => 50],
)->bind($resource);
```

Inside `PanelInstance::run()` or another active surface context,
`PanelConfig::hasDataSources()` and the fail-closed
`PanelConfig::dataSources()` resolve that same registry without introducing a
process-global fallback.

The root Panel manifest exposes a secret-free `data_sources` snapshot only when
that exact platform registry is already attached and ready. Manifest generation
does not resolve a lazy factory and does not invoke live adapter code. The
registry records the adapter's bounded capability snapshot at registration;
the public output never contains the source object, transport, endpoint,
headers, credentials, query payload, callbacks, or runtime object.

Panel registers no inbound route for this outbound adapter. It also creates no
HTTP client, credential provider, service-discovery policy, DNS guard, proxy,
egress allowlist, or authorization scope. Those remain explicit host-owned
inputs, and registering a source is not evidence that its remote dependency is
reachable or authorized.

## Exact POST/JSON protocol

Every exchange is `POST` with `application/json; charset=utf-8`; the response
must be `application/json` with optional UTF-8 charset. The transport request
offers fixed method/content negotiation and the encoded body. It has no API for
request-supplied arbitrary headers.

The version-1 request has these exact top-level members:

```text
type, version, operation, request_id, source, definition_fingerprint,
capability, query_fingerprint, read_idempotency_key, query, cursor,
record_key, scope, execution
```

The query member is constructed field by field. It contains the typed filter
AST, sorts, search, projection, includes, pagination, and aggregates, but no
tenant, authorization, metadata, endpoint, class, or header. Tenant and
approved authorization claims exist only in `scope`.

A success response must echo operation, request ID, definition fingerprint,
capability version/fingerprint, and query fingerprint. Its exact data object is:

```text
items, page, projection, aggregates, included
```

Unknown keys, loose result shapes, an incorrect content type, a changed
capability pin, or a mismatched request/query identity fail with a stable public
error. Panel never calls `PanelDataResult::normalize()` on upstream data.

Records are flat JSON objects whose exact keys match the response projection.
Every record must contain the configured string/integer record key and keys may
not repeat within a page. Aggregate aliases must exactly match the request and
their values are validated by aggregate function. Included relation names must
exactly match the request and contain bounded lists of JSON objects.

## Capability negotiation

The pin has an integer version and SHA-256 fingerprint. Both are sent on every
request and must be echoed on every response, including errors. There is no
runtime capability discovery or silent downgrade.

`PanelQueryCapabilities` now also enforces explicitly declared support for
search, projection, includes, aggregates, cursor, offset, tenant,
authorization, and `max_limit`. Legacy maps that omit these older keys retain
their previous behavior; a new remote pin declares every key explicitly.

## Cursor boundary

An upstream cursor is opaque server-to-server state capped at 2048 bytes. Panel
wraps it in a local HMAC-SHA-256 envelope capped at 4096 bytes. The envelope is
bound to:

- semantic query fields excluding page offset and limit;
- the approved scope fingerprint;
- operation and record key;
- capability and immutable definition fingerprints; and
- issue/expiry time plus a retained local key identifier.

The signature is verified before expiry or binding failures are classified.
Changing a filter, projection, include, aggregate, tenant/principal scope,
capability pin, operation, or definition invalidates the cursor. Raw upstream
cursors and signing keys never appear in manifests or result metadata.

## Deadlines, cancellation, retries, and circuit health

The request body and injected transport request carry an absolute deadline,
timeout, attempt number, and cancellation support marker. A request-aware
`PanelHttpDataSourceRuntime` can expose cancellation during a transport call and
owns retry waiting. The default process runtime supplies a clock and short
sleep implementation but no external cancellation signal.

Retries are limited to read operations (`query` and `find`), at most three
attempts, and use one stable read-idempotency key across attempts. That key does
not provide exactly-once delivery; it only lets an upstream coalesce repeated
reads. Retry statuses and backoff are immutable server configuration.

Circuit health reports only stable counters, state, timestamps, latency, and
capability identity. It never reports the URL, transport class, headers,
payloads, credentials, or raw upstream errors. Transport and upstream exception
messages are replaced with stable Panel errors.

## Migration from callbacks or repositories

1. Inventory every query feature currently used by the resource and encode it
   in a `PanelHttpDataSourceCapabilityPin`. Do not start with a permissive pin.
2. Choose one stable record-key field and make the upstream return it in every
   projection.
3. Replace implicit authorization-array forwarding with a host mapper that
   emits the smallest approved scope DTO.
4. Implement the exact version-1 POST/JSON envelopes upstream. Reject unknown
   request keys there as Panel rejects unknown response keys here.
5. Configure local cursor keys and retain the previous key for at least the
   maximum cursor TTL.
6. Run `PanelAdapterConformanceCatalog::dataSource()` against the source, then
   exercise stale cursors, capability mismatch, cancellation, retry, and open
   circuit behavior before switching the resource registration.

The old source may remain registered under a different name during migration.
Changing the resource registry entry is the only switchover; no renderer or
Panel instance changes are needed.

## Deliberate limitations

- The adapter is read-only. It has no mutation, upload, subscription, or command
  channel.
- It does not discover capabilities, refresh credentials, or choose endpoints.
- It does not guarantee snapshot consistency across pages or a distributed
  transaction with the remote system.
- It buffers one bounded JSON response; streaming and incremental decoding are
  not supported.
- Immediate Panel retries are still ordinary at-least-once HTTP delivery. The
  upstream owns read coalescing if it wants it.
- Included records use bounded JSON objects but do not carry an independent
  relation schema. Applications needing typed relation guarantees should use
  separate registered data sources or relation workspaces.
- Platform and root manifests are registration snapshots, not liveness probes.
  They never execute the transport, scope mapper, or remote service.

## Verification

The focused suite is
`runtime/modules/panel/unit_tests/dataphyre.panel_http_datasource_scorched_earth.test.php`.
It runs on PHP 8.2 and 8.4, executes the generic data-source conformance pack,
and uses `PanelScriptedHttpDataSourceTransport` without network access. The
closed-world phpdbg gate covers every executable line under
`Framework/Data/Http` plus the shared `PanelQueryCapabilities` preflight.
