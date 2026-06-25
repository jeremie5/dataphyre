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

The module reads its configuration from `common/dataphyre/config/vestra.php` and
`applications/<app>/backend/dataphyre/config/vestra.php`.

The owning kernel exposes the merged readonly config as `DP_VESTRA_CFG`.

- `base_url`
  - Base URL of the Vestra control/API endpoint.
- `object_url`
  - Optional public Vestra Fabric base URL. When omitted, the client uses
    `base_url` for `/v/{tenant}/{rate}/{blockid}` delivery.
- `tenant`
  - Legacy/default application tenant used for application accounting and Fabric
    delivery context when `default_tenant` is not set.
- `default_tenant`
  - Tenant profile key used when a reference or call does not specify a tenant.
- `rate`
  - Optional default Fabric rate for the flat/default profile. Applications should prefer
    `CALL_Vestra_RESOLVE_TENANT_CONTEXT` when the rate depends on billing state.
- `tenants`
  - Map of Fabric tenant ids or aliases to tenant-specific profile overrides.
    Each profile can set `tenant`, `base_url`, `object_url`, `rate`,
    `write_token`, `node_token`, token defaults, and `allow_unsigned`.
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

Example:

```php
return [
	'base_url'=>'https://vestra.example.com/',
	'object_url'=>'https://vestra.example.com/',
	'default_tenant'=>'example-store-content',
	'use_tenant_grant'=>true,
	'write_token'=>'w1...',
	'node_token'=>'node...',
	'tenants'=>[
		'example-store-content'=>[
			'tenant'=>'example-store-content',
			'rate'=>'s',
			'write_token'=>'w1...',
			'node_token'=>'node...',
		],
		'private-app-assets'=>[
			'tenant'=>'private-app-assets',
			'rate'=>'internal',
			'object_url'=>'https://vestra-internal.example.com/',
			'write_token'=>'w1.internal...',
			'node_token'=>'node.internal...',
		],
	],
];
```

#### Kernel API

The kernel surface is centered around `\dataphyre\vestra`.

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

Applications that need billing-aware delivery should register dialbacks instead
of modifying Dataphyre:

```php
\dataphyre\core::register_dialback(
	'CALL_Vestra_RESOLVE_TENANT_CONTEXT',
	static function(array $reference, array $parameters, array $context): array {
		return [
			'tenant'=>'example-store-content',
			'rate'=>current_store_content_rate(),
		];
	}
);
```

`CALL_Vestra_ISSUE_TENANT_TOKEN` may be used when an application or plugin owns
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
deleted or moved.
