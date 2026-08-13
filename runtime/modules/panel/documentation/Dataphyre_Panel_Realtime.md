<!-- SPDX-License-Identifier: MIT -->
# Dataphyre Panel Realtime

Dataphyre Panel Realtime is a transport-neutral change-stream foundation. It
defines tenant and principal bound subscriptions, signed connection and resume
intents, bounded broker replay, explicit reset semantics, a pull-driven stream
session, and framework-neutral Server-Sent Events responses.

The package lives entirely in `Framework/Realtime`. It does not install a route,
read PHP globals, choose an authentication mechanism, or register browser assets.
Those remain host responsibilities.

## Contract overview

1. The host derives a `PanelRealtimeContext` from authenticated server state.
2. The host creates a `PanelRealtimeSubscription` with an exact channel, topic
   allowlist, and optional scalar metadata filters.
3. A trusted application boundary issues a short-lived subscription intent and
   configures an atomic replay policy. Reusable intents require an explicit opt-in.
4. The host route passes the exact subscription, intent, optional
   `Last-Event-ID`, trusted context, and cancellation signal to
   `PanelRealtimeEndpoint::open()`.
5. The endpoint verifies intent signatures and scope binding, invokes the
   mandatory host authorizer, and returns `PanelRealtimeSseResponse`.
6. The host emits status and headers, then schedules non-blocking calls to
   `nextChunk()`. An empty string means no chunk is ready. `null` means closed.

The endpoint is HTTP-method neutral. A host may use GET, POST, or another route
shape. Fetch-streamed SSE over POST is recommended when authorization, CSRF, or
subscription credentials must be carried in headers or a body. The host still
owns origin checks, CSRF policy, cookie policy, authorization headers, rate
limits, proxy buffering configuration, and disconnect detection.

## Stable delivery model

Events are ordered by a broker-assigned integer sequence within one panel,
tenant, and channel stream. The wire envelope has schema version 1 and includes:

- `sequence`
- `channel`
- `topic`
- `type`
- `occurred_at`
- bounded JSON `payload`
- bounded JSON object `metadata`

Every delivered event uses a signed, expiring resume intent as its SSE `id`.
The client returns that value as `Last-Event-ID`. The endpoint verifies the
resume signature, retained key id, expiry, tenant, principal, panel, and exact
subscription binding before using its cursor.

Delivery is at least once across reconnect. A disconnect can occur after the
server emits an event but before the client persists its id, so consumers must
apply events idempotently. The package does not claim exactly-once delivery.

## Resets and backpressure

The stream closes after emitting `panel.reset` when incremental replay is not
safe. Its `reason` is one of:

- `retention_gap`: the requested cursor is older than retained history
- `source_reset`: the cursor is ahead of a restarted or replaced source
- `slow_consumer`: broker lag exceeded the configured pending-event bound
- `event_too_large`: one encoded frame exceeded the connection byte bound

The required client action is `rehydrate`: fetch a fresh authoritative snapshot,
replace local state, then open a new subscription. A reset is never presented as
a successful replay.

Topic filters can skip stored events. When a broker scan advances without a
matching event, the stream emits `panel.cursor` with a signed id. This prevents
the same irrelevant range from being rescanned on every reconnect.

Heartbeats are SSE comments. They keep intermediaries active but do not advance
the cursor. Connection lifetime, heartbeat interval, replay batch size, batch
bytes, pending lag, retry interval, and resume TTL are all bounded by
`PanelRealtimeStreamOptions`.

## Broker contracts

`PanelRealtimeBroker` is the pull interface. Its optional cancellation argument
lets production adapters interrupt network or queue reads when the host
disconnects or the connection deadline expires. `PanelRealtimePublisher` is a
separate optional append interface so read-only change feeds remain honest.
Every result is capped at 1,000 events and 4 MiB of encoded event data. A stream
also rejects adapters that return more entries than the requested read limit or
an event outside the exact channel, stream, topic, and metadata filter scope.

`PanelInMemoryRealtimeBroker` is the local reference adapter. It provides
per-stream ordering, retained replay, topic and metadata filtering, retention
gap detection, bounded stream count, bounded event size, and cancellation checks.
It is process-local, non-durable, non-distributed, and not suitable as a shared
production broker.

`PanelPdoRealtimeAdapter` is the first-party durable shared-SQL adapter for
MySQL, PostgreSQL, and SQLite. One instance implements `PanelRealtimeBroker`,
`PanelRealtimePublisher`, and
`PanelRealtimeSubscriptionIntentReplayPolicy`, so publication, retained replay,
and initial-connect nonce consumption can share one operational database. It
provides:

- atomic monotonic sequence allocation within each panel/tenant/channel stream
- snapshot-consistent reads with explicit retention-gap and source-reset results
- retained-event pruning in the same transaction as acknowledged publication
- cross-process and multi-node serialization through database transactions and
  row locks (`BEGIN IMMEDIATE` is used for SQLite writes)
- bounded stream, event, replay-entry, and transaction-retry policies
- domain-separated SHA-256 nonce digests at rest; raw nonces are never stored
- stable fail-closed storage, migration, corruption, and capacity failures
- a secret-free manifest that never queries live counts or exposes a DSN,
  credentials, table prefix, SQL, payloads, metadata, or nonce values

Schema mutation is never automatic. Run the idempotent schema installation from
an authorized migration/deployment process before the adapter receives traffic:

```php
use Dataphyre\Panel\PanelPdoRealtimeAdapter;

$broker=new PanelPdoRealtimeAdapter($pdo, [
    'table_prefix'=>'panel_realtime',
    'retained_events_per_stream'=>2048,
    'maximum_streams'=>100000,
    'maximum_event_bytes'=>196608,
    'maximum_replay_entries'=>100000,
    'replay_retention_grace_seconds'=>60,
    'transaction_retries'=>3,
]);

// Explicit deployment/migration step, never a request-time side effect.
$migration=$broker->installSchema();
```

`schemaStatements()` returns the exact idempotent plan when the host migration
runner, rather than the adapter helper, must execute DDL. `schemaStatementsFor()`
can generate an auditable plan without opening a connection. All processes that
share the tables should use the same prefix and operational bounds. The adapter
refuses to run inside a caller-owned PDO transaction because it must own commit,
rollback, retry, snapshot, and lock semantics atomically.

The host still owns connection creation and credentials, database TLS and
network policy, availability/replication, backups, migration authorization,
query/lock timeouts, monitoring, and disaster recovery. Cancellation is checked
before and while rows are hydrated; generic PDO cannot portably interrupt a
driver call already in flight, so database-side statement timeouts remain a host
requirement. SQLite is appropriate for shared local processes on one filesystem;
multi-node deployments should select MySQL, PostgreSQL, or another conforming
broker rather than place SQLite on a network filesystem.

`PanelRedisRealtimeAdapter` is the first-party non-SQL Redis Streams adapter.
It implements the same broker, publisher, and initial-connect replay-policy
interfaces without depending on phpredis or Predis. A narrow
`PanelRedisRealtimeTransport` boundary has first-party callback, phpredis, and
Predis bridges; the host creates and configures the actual client.

The adapter requires Redis 6.2 or newer. Every key in one adapter namespace
uses the same generated Redis Cluster hash tag, so each fixed Lua script remains
cluster-valid. Redis Streams are the sole per-stream head authority: successful
publication uses an explicit integer stream id and `XADD MAXLEN =` for exact
append-and-trim behavior. Reads derive the retained head and earliest sequence
from the stream, advance across non-matching events, verify a domain-separated
SHA-256 digest over every stored record, and fail closed on missing registration,
identifier gaps, malformed fields, or changed JSON bytes. A sorted set stores
only domain-separated nonce hashes and atomically rejects duplicate initial
connects across processes.

```php
use Dataphyre\Panel\PanelPhpRedisRealtimeTransport;
use Dataphyre\Panel\PanelRedisRealtimeAdapter;

$transport=new PanelPhpRedisRealtimeTransport($connectedPhpRedisClient);
$broker=new PanelRedisRealtimeAdapter($transport, [
    'key_prefix'=>'panel:realtime',
    'retained_events_per_stream'=>2048,
    'maximum_streams'=>100000,
    'maximum_event_bytes'=>196608,
    'maximum_replay_entries'=>100000,
    'replay_retention_grace_seconds'=>60,
]);

// Explicit namespace/version initialization, never a request-time side effect.
$initialization=$broker->installSchema();
```

`PanelPredisRealtimeTransport` maps the same fixed scripts through
`executeRaw()`. `PanelCallbackRedisRealtimeTransport` supports another Redis
SDK without widening the production interface. Transport callbacks, clients,
connection details, credentials, key prefixes, concrete keys, and scripts are
never serialized; stable script digests are public for deployment audit.

Redis persistence and acknowledged-write safety depend on host-selected RDB,
AOF, replication, Sentinel or Cluster, `WAIT`/`WAITAOF` policy, and failover
configuration, so the adapter deliberately reports durability as
`host_configured` rather than claiming it. A lost acknowledgement can be
retried as a duplicate because the generic publisher API has no idempotency
key. Delivery therefore remains at least once. The host also owns ACLs, TLS,
timeouts, reconnect and redirection behavior, eviction policy, memory sizing,
monitoring, backup, and disaster recovery. Pinning one logical adapter
namespace to one cluster slot preserves multi-key script correctness but makes
that slot the namespace scaling boundary; use separate key prefixes to shard
independent Panel deployments deliberately.

`PanelDataSubscriptionRealtimeBroker::fromTrustedTenantSource()` adapts the
existing `PanelSubscribableDataSource` and `PanelDataSubscription` pull contract.
The host must supply a source already scoped to exactly one tenant and a trusted
principal-aware projector. The projector returns the only payload and metadata
allowed onto the wire, may suppress a change with `null`, and fails closed when
absent, invalid, or unavailable. Raw `before`, `after`, and source metadata are
never copied automatically. The generic data-subscription API does not expose a
retention head, interruptible blocking read, or gap signal, so this bridge
explicitly reports those properties as source-defined or unsupported.

Production adapters should implement the reusable
`testing/PanelRealtimeBrokerConformance.php` pack and document:

- ordering scope and cursor durability
- retention and reset detection
- read cancellation and deadline behavior
- maximum event and batch sizes
- cross-process and multi-node behavior
- publication acknowledgement semantics
- duplicate behavior during reconnect and failover

## Server example

```php
use Dataphyre\Panel\PanelInMemoryRealtimeBroker;
use Dataphyre\Panel\PanelInMemoryRealtimeIntentReplayPolicy;
use Dataphyre\Panel\PanelRealtimeContext;
use Dataphyre\Panel\PanelRealtimeEndpoint;
use Dataphyre\Panel\PanelRealtimeIntentSigner;
use Dataphyre\Panel\PanelRealtimeSubscription;

$context=PanelRealtimeContext::fromTrusted('operations', [
    'tenant_id'=>$authenticatedTenantId,
    'principal_id'=>$authenticatedUserId,
    'correlation_id'=>$requestId,
]);

$subscription=PanelRealtimeSubscription::fromTrusted(
    $context,
    'orders',
    ['orders.created', 'orders.updated'],
    ['market'=>'ca']
);

$signer=new PanelRealtimeIntentSigner(
    ['2026-07'=>$currentSecret, '2026-06'=>$previousSecret],
    '2026-07'
);

// Issue this only from an authenticated, authorized server-render or API flow.
$subscriptionIntent=$signer->issueSubscription($subscription, 300)->token();

$endpoint=(new PanelRealtimeEndpoint($broker, $signer))
	->protectSubscriptionIntents(new PanelInMemoryRealtimeIntentReplayPolicy())
    ->authorizeHost(static function(
        string $operation,
        PanelRealtimeSubscription $requested,
        PanelRealtimeContext $trusted,
        int $cursor
    ): bool {
        return $operation==='subscribe'
            && host_can_stream($trusted, $requested->channel());
    });

$response=$endpoint->open(
    $subscription,
    $request->header('X-Dataphyre-Realtime-Intent'),
    $request->header('Last-Event-ID'),
    $context,
    $disconnectCancellation
);

// Map status and headers into the host framework once.
// Schedule nextChunk() through the host event loop or bounded emitter.
```

For a shared deployment, pass the same `PanelPdoRealtimeAdapter` or
`PanelRedisRealtimeAdapter` as both `$broker` and the argument to
`protectSubscriptionIntents()`. The in-memory objects in this compact example
are intentionally single-process references. Redis durability is not inferred
from adapter selection; it remains an explicit host configuration and claim.

### Attach the exact graph to one Panel surface

Realtime is not part of `PanelPlatform::defaults()`. A host that wants the
surface facade and root manifest must register the same broker and signer
objects wrapped by the endpoint, then attach that platform explicitly:

```php
use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelPlatform;

$platform=PanelPlatform::make()
    ->register('realtime.broker', $broker)
    ->register('realtime.signer', $signer)
    ->register('realtime.endpoint', $endpoint);

$panel=Panel::make('operations')->usePlatform($platform);

if (!$panel->hasRealtime()) {
    throw new RuntimeException('The realtime dependency graph is incomplete.');
}

$attachedEndpoint=$panel->realtime();
$publicContract=$panel->realtimeManifest();
```

The three names are one dependency graph, not independent aliases. The endpoint
must return the exact registered objects from `broker()` and `signer()`.
Registering a second compatible broker or signer produces a split graph;
`PanelPlatformManifest`, `hasRealtime()`, and the surface manifest reject it.
An unresolved lazy factory is reported as pending and is never invoked merely
to build a manifest. Resolving a singleton factory is a revisioned platform
lifecycle mutation.

`realtimeManifest().attachment.configured=true` means only that the typed graph
is resolved and identity-cohesive. It does not prove that a route, emitter,
origin or CSRF policy, authorization middleware, replay-policy backend, proxy
configuration, or durable broker is operational. The manifest never opens a
stream or calls the broker or replay-policy adapter.

Do not put subscription intents in query strings. Query strings are commonly
retained by access logs, browser history, analytics, and referrer processing.
Treat subscription and resume intents as short-lived bearer credentials. Redact
`Last-Event-ID`, intent headers, and request bodies from infrastructure logs.

## Optional fetch-streamed client

`PanelRealtimeClient.js` is an isolated browser runtime. It uses Fetch,
`ReadableStream`, `TextDecoder`, and `AbortController`; it never constructs a
native `EventSource`. This permits POST requests and lets the host supply
authorization, CSRF, and other protected headers without placing bearer intents
in a URL.

The runtime is not registered in Panel's shared asset graph. A host may expose
the versioned body through `PanelRealtimeClientAssets`:

```php
$version=\Dataphyre\Panel\PanelRealtimeClientAssets::version();
$asset=\Dataphyre\Panel\PanelRealtimeClientAssets::content(true);
// Serve from a URL containing the exact $version before enabling immutable cache.
// Map content_type, body, etag, and cache_control into the host response.
```

When the asset URL is not content-versioned, call `content()` without `true`; its
safe default is `no-cache`.

After loading that script, create the client with explicit transport policy:

```js
const client=DataphyrePanelRealtime.create({
    url: "/admin/realtime/orders",
    method: "POST",
    subscriptionIntent: bootstrap.realtimeIntent,
    headers: {
        "Authorization": `Bearer ${session.accessToken}`,
        "X-CSRF-Token": session.csrfToken,
    },
    bodyFactory: ({hasResumeCursor}) => ({
        subscription: "orders-table",
        hasResume: hasResumeCursor,
    }),
    subscriptionIntentProvider: async ({reason, hasResumeCursor}) => {
        // Host-owned authenticated API. It returns a fresh subscription intent.
        return fetchFreshRealtimeIntent({reason, hasResumeCursor});
    },
    credentials: "same-origin",
    loadCursor: () => sessionStorage.getItem("orders-realtime-cursor"),
    saveCursor: value => sessionStorage.setItem("orders-realtime-cursor", value),
    clearCursor: () => sessionStorage.removeItem("orders-realtime-cursor"),
    random: cryptoSafeUnitRandom,
});

client.addEventListener(
    DataphyrePanelRealtime.events.event,
    event => applyIdempotently(event.detail.envelope)
);

client.addEventListener(
    DataphyrePanelRealtime.events.reset,
    async event => {
        await replaceWithFreshSnapshot(event.detail.reason);
        await client.reconfigure(await fetchNewSubscriptionIntent(), true);
    }
);

client.start();
```

`bodyFactory` is synchronous and its output is byte bounded. Authentication,
CSRF, origin validation, route selection, and subscription reconstruction remain
host responsibilities. The runtime rejects cross-origin URLs unless
the exact normalized target origin appears in `allowedOrigins`. HTTPS pages can
never downgrade realtime transport to HTTP. It rejects URL query parameters
unless `allowQuery` is enabled, and still rejects normalized credential-like
query names such as API keys, passwords, session ids, signatures, and tokens.

Reserved transport headers are overwritten by the runtime. The subscription
intent is sent in `X-Dataphyre-Realtime-Intent`, and a verified resume credential
is sent in `Last-Event-ID`. Host-provided `Accept`, cache, intent, or resume
headers cannot replace those values. Browser-controlled headers such as `Origin`,
`Host`, `Cookie`, `Connection`, and `Content-Length` are rejected from custom
configuration.

Subscription intents, resume ids, custom headers, and request bodies live in
closure-private client state. Public state and event details expose only boolean
cursor presence. The synchronous body factory and explicit load/save cursor
callbacks are trusted host seams. The optional async subscription-intent provider
is time bounded, receives only reason, attempt, and cursor-presence metadata, and
runs before reconnect or after `intent_expired`. This lets a finite stream renew
its connection credential without placing it in a URL.

The runtime exposes typed `CustomEvent` names under
`DataphyrePanelRealtime.events`:

- `state`, `open`, and `close`
- `event` for stable application envelopes
- `cursor` and `heartbeat`
- `retry` with the bounded delay and reason
- `pause` and `resume`
- `reset` for mandatory snapshot rehydration
- `error` with stable code, phase, and retryability

No event data is inserted into HTML or executed as code. The parser accepts
bounded UTF-8 JSON objects only. It limits request bytes, parser buffer bytes,
frame bytes, event bytes, and events processed per network read.

Retries use a capped exponential delay. Jitter is injected through `random` for
deterministic tests or a host-selected entropy source. Server `retry` fields are
accepted only as bounded integers. Consecutive connection failures stop at
`maxAttempts`; established finite streams may reconnect normally after their
server-owned lifetime. Non-retryable `panel.error` envelopes and every
`panel.reset` are terminal until the host explicitly reconfigures the client.
Retry attempts reset only after a connection survives the stable-window bound.
Waiting for response headers has its own bounded connect timeout. A complete SSE
frame resets the separate heartbeat deadline; arbitrary byte chunks do not, so a
slow byte drip cannot keep an incomplete frame alive forever. The response media
type must be exactly `text/event-stream`, with optional parameters.

The client pauses and aborts its active fetch while the browser is offline or the
document is hidden. It resumes only after every active pause reason is removed.
`stop()` aborts fetch, cancels pending backoff, releases lifecycle listeners, and
prevents another retry. Heartbeat timeout also aborts the active request before a
bounded reconnect.

## Authorization and key rotation

The endpoint is fail closed. Without a replay policy, opening a stream returns
`replay_policy_required`. Without `authorizeHost()`, an otherwise configured
endpoint returns `host_authorization_required`. A callback exception becomes the
stable `host_authorization_unavailable` response and no internal message is exposed.

The recommended path installs an atomic nonce consumer for fresh connections:

```php
$endpoint=$endpoint->protectSubscriptionIntents($distributedReplayPolicy);
```

Legacy hosts may deliberately call `allowReusableSubscriptionIntents()`. This is
an explicit immutable opt-in, and the endpoint manifest reports
`reusable_explicit_opt_in`. It must not be enabled accidentally.

`PanelRealtimeSubscriptionIntentReplayPolicy::consume()` must atomically insert
the verified subscription nonce and return false when it already exists. It runs
only after host authorization succeeds. A duplicate fresh connection returns
`subscription_intent_replayed`.

Resume intents are deliberately never consumed. A reconnect with a valid signed
`Last-Event-ID` may reuse the original subscription intent, because reconnect
must remain possible after the initial nonce was consumed. A client that loses
its resume id needs a newly issued subscription intent before opening a fresh
single-use connection.

`PanelInMemoryRealtimeIntentReplayPolicy` is a bounded process-local reference
implementation. It is not distributed or durable. Multi-node hosts must inject a
shared atomic store with expiry semantics. Endpoint manifests expose the chosen
mode and policy posture without nonce values. Manifest serialization never calls
the injected broker or replay-policy adapter, so adapter diagnostics, side
effects, and serialization failures cannot cross the public boundary.

Intent keyrings accept one current key and up to seven retained verification
keys. Keys must contain at least 32 bytes. New intents use the current key.
Existing intents remain verifiable while their key id is retained and their TTL
has not expired. Remove old keys only after the maximum subscription and resume
TTL, clock skew allowance, and deployment overlap have elapsed.

Intent bodies contain only audience, purpose, cursor, expiry, nonce, panel, and
keyed binding tags. Raw tenant ids, principal ids, topics, and filter values are
not embedded in tokens. Public manifests and telemetry never include secrets,
tokens, payloads, or identity values.

## Error envelopes

Opening failures use an SSE `panel.error` envelope and an appropriate HTTP
status. Stream-time failures use the same event and close the connection.
Public codes are stable; internal exception text is discarded. Endpoint messages
come from a closed framework mapping, with one fixed generic message for unknown
host-adapter codes. Adapter-provided exception messages are never serialized.

Every SSE response includes `Cache-Control: no-store, private, no-transform`,
`X-Accel-Buffering: no`, `X-Content-Type-Options: nosniff`, and the protocol
version header. Response and endpoint manifests expose none of the subscription
token, resume token, signing secret, or consumed nonce material.

Clients may retry only when `retryable` is true. An `intent_expired` response is
recoverable only when the host configured the bounded subscription-intent
provider; otherwise authentication, authorization, invalid or expired intent,
and reset responses require a host-defined recovery flow.

## Verification

Run the isolated server proof on each supported PHP runtime:

```text
php runtime/modules/panel/testing/panel_realtime_test_runner.php
phpdbg -qrr runtime/modules/panel/testing/panel_realtime_phpdbg_coverage.php
```

The phpdbg proof is closed-world: it targets only `Framework/Realtime/*.php` and
fails unless every executable line is observed. The optional client runtime has
separate Node and real Chromium proofs:

```text
node runtime/modules/panel/testing/panel_realtime_client_runtime.test.js
node runtime/modules/panel/testing/panel_realtime_client_browser_evidence.js
```

The Chromium proof writes its report and screenshot under
`cache/unit-tests/panel-realtime-client-evidence`. It exercises the actual
browser implementations of Fetch, `ReadableStream`, `AbortController`,
`CustomEvent`, `TextDecoder`, and streamed `Response` bodies.

## Migration and compatibility notes

- Direct construction of `PanelRealtimeEndpoint` remains supported. Attaching
  the same objects under `realtime.broker`, `realtime.signer`, and
  `realtime.endpoint` adds surface accessors and truthful manifests without
  changing endpoint behavior.
- A native `EventSource` integration that places a subscription credential in
  the URL should migrate to the Fetch-streamed client or another header/body
  capable transport. Panel provides no automatic query-token compatibility
  shim.
- A fresh connection now fails closed unless an atomic replay policy is
  installed. `allowReusableSubscriptionIntents()` is an explicit legacy
  migration escape hatch, not a production replay guarantee. Its use is
  visible in the endpoint manifest.
- Connect credentials belong in `X-Dataphyre-Realtime-Intent`; resume
  credentials belong in `Last-Event-ID`. Credential-like query names remain
  rejected even when ordinary query parameters are explicitly enabled.
- Existing event consumers must tolerate at-least-once delivery and implement
  `panel.reset` rehydration. Do not migrate by treating reset as an empty or
  successful incremental batch.
- No Realtime PHP symbol is marked deprecated in this release. The discouraged
  patterns above remain host integration patterns, not compatibility promises.

## Deliberate non-goals

- no WebSocket server
- no route or HTTP emitter ownership
- no authentication, CSRF, or origin policy inference
- no shared asset registration
- no durable or distributed claims for the memory adapter
- no exactly-once claim
- no automatic full-state snapshot after reset
- no arbitrary server callback filters in client-controlled subscriptions
