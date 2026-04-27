### Dataphyre CDN Client

The CDN module provides the client-side API for propagating files to a CDN, generating block URLs, rewriting HTML resources, and tracking CDN block usage.

The kernel API is exposed through `\dataphyre\cdn`. The optional framework API lives under `\Dataphyre\Cdn` and is only loaded when an application explicitly calls `\dataphyre\core::load_framework_module('cdn')`.

#### Configuration

The module reads its configuration from `common/dataphyre/config/cdn.php` and `applications/<app>/backend/dataphyre/config/cdn.php`.

The owning kernel exposes the merged readonly config as `DP_CDN_CFG`.

- `base_url`
  - Base URL of the CDN control/API endpoint.
- `block_storage_url`
  - Base URL used to serve propagated blocks.

Example:

```php
return [
	'base_url'=>'https://cdn.example.com/',
	'block_storage_url'=>'https://cdn.example.com/vault/',
];
```

#### Kernel API

The kernel surface is centered around `\dataphyre\cdn`.

- `configured(): bool`
  - Returns `true` when the module has enough CDN configuration to operate.
- `block_url(string $encoded_blockpath, array $parameters=[]): string`
  - Builds a full block URL from an encoded block identifier.
- `asset_url(string $blockpath, string $extension='', array $parameters=[]): string`
  - Builds a CDN asset URL from a block path and optional extension.
- `encode_blockpath(string $blockpath): string`
  - Encodes a slash-delimited block path into a CDN-safe identifier.
- `decode_blockpath(string $blockpath): string`
  - Decodes an encoded block path back to its numeric path segments.
- `blockpath_to_blockid(string $blockpath): int`
  - Converts a numeric block path into a compact integer block ID.
- `blockid_to_blockpath(int $blockid): string`
  - Converts a block ID back into its numeric block path.
- `update_use_count(string $blockpath, int $amount): bool|int`
  - Increments or decrements a block use count. When the count reaches zero, the client requests a purge from the CDN server.
- `ingest_resources(string $html, ?int $resource_limit=null, array $known_changes=[]): array`
  - Rewrites ingestable HTML resources to CDN URLs and returns:
    - `new_html`
    - `changes`
- `propagate(string $file, bool $encryption=false): bool|string`
  - Pushes a local file or remote URL to the CDN and returns the propagated block path.

#### Resource ingestion

`ingest_resources(...)` scans and rewrites common asset references, including:

- images
- `<source>` media URLs
- scripts
- stylesheets
- audio
- iframes
- CSS `url(...)`
- favicons
- `@font-face` URLs
- picture `srcset`
- embedded PDF URLs
- SVG image references

`$known_changes` can be passed to reuse already-propagated block paths without transmitting the same asset again.

#### Framework API

Load the framework layer only when you need it:

```php
\dataphyre\core::load_framework_module('cdn');
```

Framework classes:

- `\Dataphyre\Cdn\Client`
  - Static convenience facade for common CDN operations.
- `\Dataphyre\Cdn\CdnManager`
  - Instance-oriented wrapper around the kernel CDN API.
- `\Dataphyre\Cdn\BlockPath`
  - Helper for encoding, decoding, and converting block paths.
- `\Dataphyre\Cdn\IngestionResult`
  - Value object for `ingest(...)` results.

Example:

```php
\dataphyre\core::load_framework_module('cdn');

$url=\Dataphyre\Cdn\Client::assetUrl($blockpath, 'jpg');
$result=\Dataphyre\Cdn\Client::ingest($html);

if($result->changed()){
	$html=$result->html();
}
```

#### Local loader route

The module also exposes the local route:

- `/dataphyre/cdn/{filename}`

This loader is primarily used as a local origin during propagation, allowing the CDN server to pull a freshly staged file from the current node before it is deleted or moved.

#### Operational notes

- `propagate(...)` retries transmission up to ten times before failing.
- Repeated propagation of the same unencrypted local file can short-circuit when the file hash is already known by the CDN database.
- The framework layer adds no baseline kernel overhead unless it is explicitly loaded.
