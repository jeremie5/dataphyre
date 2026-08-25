### Dataphyre Vestra Client

The Vestra module provides the client-side API for propagating files to Vestra,
generating object URLs, rewriting HTML resources, and tracking application-owned
Vestra object usage.

Dataphyre stores Vestra Fabric references, not bare object ids. A reference can
include the object id, tenant, node-provided links, passkeys, persistent tokens,
templates, and metadata needed to generate valid delivery URLs later.
Generated URLs are not persisted as canonical state. The module resolves the
current tenant context, including the current billing rate/plan, each time a URL
is requested so older files follow tenant billing changes.

This module integrates with Vestra Fabric. Use `dataphyre/storage` with an `s3`
disk when an application wants Vestra's S3-compatible bucket/key surface. Use
this module when an application wants Vestra Fabric references, propagation,
tenant-aware delivery URLs, resource ingestion, and application usage accounting.

#### Configuration

The module reads its configuration from `dataphyre/config/vestra.php` and
`applications/<app>/backend/dataphyre/config/vestra.php`.

The owning kernel exposes the merged readonly config as `DP_VESTRA_CFG`.

- `base_url`
  - Base URL of the Vestra control/API endpoint.
- `object_url`
  - Optional public Vestra Fabric base URL. When omitted, the client uses
    `base_url` for `/v/{tenant}/{rate}/{blockid}` delivery.
- `tenant`
  - Legacy/default application tenant used for application accounting and Fabric
    delivery context when `default_tenant` is not set. `VESTRA_TENANT` is the
    final fallback only when neither explicit configuration nor legacy config
    supplies a tenant.
- `default_tenant`
  - Tenant profile key used when a reference or call does not specify a tenant.
- `rate`
  - Optional default Fabric rate for the flat/default profile. Applications should prefer
    `CALL_VESTRA_RESOLVE_TENANT_CONTEXT` when the rate depends on billing state.
    `VESTRA_RATE` is the final fallback before the framework's `s` default; an
    explicit flat or tenant-profile rate always wins.
- `tenants`
  - Map of Fabric tenant ids or aliases to tenant-specific profile overrides.
    Each profile can set `tenant`, `base_url`, `object_url`, `rate`,
    `api_token`, `write_api_token`, `tenant_read_token`, `write_token`,
    `node_token`, token defaults, and `allow_unsigned`.
- `api_token`
  - Vestra Control credential used to mint scoped access tokens. It remains the
    backwards-compatible fallback for write Control calls when
    `write_api_token` is omitted.
- `write_api_token`
  - Vestra Control credential used only for scoped write-token issuance and
    object reservation. Dedicated values can carry less authority than the
    access-side `api_token`.
- `tenant_read_token`
  - Pre-issued tenant delivery token used when scoped access-token issuance is
    unavailable.
- `write_token`
  - Scoped Vestra write token used by Vestra writes. Dataphyre private keys and
    node tokens are not sent to Vestra object APIs.
- `node_token`
  - Node token used only for Vestra signer/operator routes such as
    `POST /tenant/token/issue`.
- `token_ttl`, `token_grace`, `use_tenant_grant`
  - Defaults used when the module asks Vestra Fabric to issue a tenant token.
    Tenant grants are enabled by default so a render pass can reuse one
    tenant/rate token across many asset URLs instead of requesting object access
    for every URL. Object-expiring URLs still use object-bound tokens because
    the expiry is signed into the token.
- `allow_unsigned`
  - Local-development escape hatch for unsigned `/v/...` URLs. Keep this `false`
    for signed Fabric deployments.

An explicitly supplied runtime `cache_directory` remains authoritative for
Vestra staging and for the local loader route. Without that override, ordinary
local and self-managed runtimes retain the existing
`ROOTPATH['common_dataphyre']/cache/vestra` default. Immutable managed
releases (`DATAPHYRE_APPLICATION_RELEASE=dep_<40 lowercase hex>`) instead use
the process' system temporary directory under `dataphyre/vestra`; their source
tree may be read-only, while the application UID can create and remove staging
files inside its private workload filesystem. Dataphyre creates this implicit
managed directory with owner-only permissions. It is ephemeral staging, not
durable application storage.

Credential inheritance is fail closed per tenant profile. Omitting `api_token`,
`write_api_token`, `tenant_read_token`, `write_token`, or `node_token` from a
profile preserves its flat module, legacy application config, and `VESTRA_*`
environment inheritance chain. When every dedicated `write_api_token` source is
omitted or empty, write Control calls inherit `api_token` for backwards
compatibility. Declaring a tenant profile's `write_api_token` as empty or `null`
explicitly disables that fallback. Declaring any other credential key empty or
`null` likewise disables inheritance for that credential.

An explicit empty or `null` `write_token` disables both static-token inheritance
and write-token minting. To mint scoped write tokens with `write_api_token`, omit
`write_token` from that tenant profile.

Example:

```php
return [
	'base_url'=>'https://vestra.example.com/',
	'object_url'=>'https://vestra.example.com/',
	'default_tenant'=>'example-store-content',
	'use_tenant_grant'=>true,
	'api_token'=>'control.access...',
	'write_api_token'=>'control.write...',
	'node_token'=>'node...',
	'tenants'=>[
		'example-store-content'=>[
			'tenant'=>'example-store-content',
			'rate'=>'s',
			'api_token'=>'control.access...',
			'write_api_token'=>'control.write...',
			'node_token'=>'node...',
		],
		'private-app-assets'=>[
			'tenant'=>'private-app-assets',
			'rate'=>'internal',
			'object_url'=>'https://vestra-internal.example.com/',
			'api_token'=>'control.internal-access...',
			'write_api_token'=>'control.internal-write...',
			'node_token'=>'node.internal...',
		],
		'isolated-no-credentials'=>[
			'tenant'=>'isolated-no-credentials',
			'rate'=>'s',
			'api_token'=>null,
			'write_api_token'=>null,
			'tenant_read_token'=>null,
			'write_token'=>null,
			'node_token'=>null,
		],
	],
];
```

#### Kernel API

The kernel surface is centered around `\dataphyre\vestra`.

- `SEPARATE_CONTROL_CREDENTIALS_VERSION`
  - Public capability marker. Version `1` guarantees independent `api_token`
    and `write_api_token` resolution with fail-closed tenant overrides.
- `configured(): bool`
  - Returns `true` when the module has enough Vestra configuration to operate.
- `object_url(array $reference, array $parameters=[]): string|false`
  - Builds a current Fabric URL from a Vestra reference and tenant context.
- `asset_url(array $reference, string $extension='', array $parameters=[]): string|false`
  - Builds a Vestra asset URL from a Vestra Fabric reference and optional extension.
- `update_use_count(array $reference, int $amount): bool|int`
  - Increments or decrements application use count. When the count reaches zero,
    the client requests a purge from the Vestra server.
- `ingest_resources(string $html, ?int $resource_limit=null, array $known_changes=[]): array`
  - Rewrites ingestable HTML resources to Vestra URLs and returns `new_html` plus
    `changes`, a URL-to-reference map.
- `propagate(string $file, bool $encryption=false): bool|array`
  - Pushes a local file or remote URL to Vestra and returns the propagated reference.

Direct local uploads reserve the content-addressed object key
`dataphyre/sha256/{digest}`. The surrounding reservation and idempotency identity
also bind the canonical tenant and resolved rate. Source paths, temporary
basenames, and wall-clock dates do not participate. Retrying identical bytes in
the same tenant/rate scope therefore reuses the same reservation, while changed
bytes select a different identity.

Vestra is an external object system, so its writes are not part of a caller's SQL
transaction. A SQL rollback after propagation can leave a bounded, same-tenant,
content-addressed Vestra reservation or object. A retry reuses that exact identity
instead of creating path-, date-, or attempt-specific residue; callers must not
describe the external object write as SQL-atomic.

#### Storage References

Framework-level storage references are JSON objects:

```json
{
  "driver": "vestra",
  "object_id": 123456789,
  "tenant": "example-store-content",
  "fabric": {
    "blockid": 123456789,
    "tenant_url_template": "/v/{tenant}/{rate}/{blockid}",
    "rate_source": "tenant_context"
  },
  "tokens": {
    "passkey": "..."
  }
}
```

Applications should keep ownership data, such as store id or product id, in their
own tables. Vestra tenant should represent the application content boundary, not
one tenant per store.

When a reference includes `"tenant": "example-store-content"`, URL generation
uses the matching `tenants.example-store-content` profile. Callers may also pass
`['tenant'=>'profile-alias']`; if that profile defines its own `tenant`, the
profile value becomes the actual Fabric tenant sent to Vestra.

Write operations retain the profile alias for configuration and credential
lookup, but use that profile's canonical `tenant` in Control endpoint paths,
write-token scope templates, reservation idempotency, and persisted references.
This keeps aliases local to application configuration instead of sending them as
Fabric tenant identities. When the alias differs, propagated references store
the canonical id in `tenant` and the exact local alias in `tenant_profile` so
later signing and purge operations can recover the same profile without an
ambiguous reverse lookup. A marker that does not resolve to the persisted
canonical tenant is rejected.

Applications that need billing-aware delivery should register dialbacks instead
of modifying Dataphyre:

```php
\dataphyre\core::register_dialback(
	'CALL_VESTRA_RESOLVE_TENANT_CONTEXT',
	static function(array $reference, array $parameters, array $context): array {
		return [
			'tenant'=>'example-store-content',
			'rate'=>current_store_content_rate(),
		];
	}
);
```

`CALL_VESTRA_ISSUE_TENANT_TOKEN` may be used when an application or plugin owns
token issuance. Dataphyre caches issued tokens in-process by tenant/rate and, for
object-bound tokens, by block id. Otherwise the module calls Vestra Fabric
`POST /tenant/token/issue` with `tenant`, `rate`, `blockid`, TTL/grace, optional
object expiry, and optional `tenant_grant`.

`dataphyre/storage` may wrap this reference model with a logical path manifest,
but that bridge is intentionally thin. It does not make the Vestra module an S3
implementation and does not persist generated delivery URLs.

#### Resource Ingestion

`ingest_resources(...)` scans and rewrites common asset references, including
images, media sources, scripts, stylesheets, audio, iframes, CSS `url(...)`,
favicons, `@font-face` URLs, picture `srcset`, embedded PDFs, and SVG image
references.

`$known_changes` can be passed to reuse already-propagated references without
transmitting the same asset again.

#### Framework API

Load the framework layer only when you need it:

```php
\dataphyre\core::load_framework_module('vestra');
```

Framework classes:

- `\Dataphyre\Vestra\Client`
  - Static convenience facade for common Vestra object operations.
- `\Dataphyre\Vestra\VestraManager`
  - Instance-oriented wrapper around the kernel Vestra API.
- `\Dataphyre\Vestra\IngestionResult`
  - Value object for `ingest(...)` results.

Example:

```php
\dataphyre\core::load_framework_module('vestra');

$url=\Dataphyre\Vestra\Client::assetUrl($reference, 'jpg');
$result=\Dataphyre\Vestra\Client::ingest($html);

if($result->changed()){
	$html=$result->html();
}
```

#### Local Loader Route

The module also exposes the local route:

- `/dataphyre/vestra/{filename}`

This loader is primarily used as a local origin during propagation, allowing the
Vestra server to pull a freshly staged file from the current node before it is
deleted or moved. It resolves the same explicit, managed-release, or local cache
directory as `\dataphyre\vestra::propagate()`, so the origin endpoint never reads
from a different staging root than the writer.
