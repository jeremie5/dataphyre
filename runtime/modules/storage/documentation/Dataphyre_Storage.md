# Dataphyre Storage

Dataphyre Storage is a file storage abstraction layer for local files,
S3-compatible object storage, and custom provider drivers. Vestra can appear in
Storage in two distinct ways: its S3-compatible surface is configured through
the generic `s3` driver, while the `vestra` driver is only a thin logical-path
alias bridge to Vestra Fabric object references.

The kernel API is exposed through `\dataphyre\storage`. The optional framework
API lives under `\Dataphyre\Storage` and is loaded with:

```php
\dataphyre\core::load_framework_module('storage');
```

For apps that do not auto-load optional modules, load the kernel entry during
bootstrap:

```php
if($module=dp_module_present('storage')){
	require_once($module[0]);
}
```

`StorageManager` registers Dataphyre's bundled driver factories when it is
constructed. Configured disks using `local`, `memory`, `s3`, `r2`, `vestra`,
and the bundled wrapper drivers such as `audit`, `integrity`,
`versioned`, `scoped`, `policy`, and `rate_limited` resolve without each app
manually registering factories. Apps still call `Storage::extend(...)` when
adding a custom driver or replacing a bundled driver.

## Configuration

Configuration is read from `common/dataphyre/config/storage.php` and
`applications/<app>/backend/dataphyre/config/storage.php`.

Example:

```php
return [
	'default_disk'=>'local',
	'disks'=>[
		'local'=>[
			'driver'=>'local',
			'root'=>ROOTPATH['dataphyre'].'storage',
			'url'=>null,
			'signing_key'=>'...',
			'max_bytes'=>104857600,
			'allowed_extensions'=>[],
			'allowed_mime_types'=>[],
			'encryption'=>[
				'enabled'=>true,
				'key'=>'base64:...',
			],
		],
		'testing'=>[
			'driver'=>'memory',
			'prefix'=>'tests',
		],
		'vestra'=>[
			'driver'=>'vestra',
			'manifest'=>ROOTPATH['dataphyre'].'cache/storage-vestra-manifest.json',
			'tenant'=>'shopiro-store-content',
		],
		'vestra_s3'=>[
			'driver'=>'s3',
			'endpoint'=>'https://vestra.example.com/s3',
			'bucket'=>'store-content',
			'region'=>'auto',
			'access_key'=>'...',
			'secret_key'=>'...',
			'style'=>'path',
			'public_url'=>null,
		],
		's3'=>[
			'driver'=>'s3',
			'endpoint'=>'https://s3.amazonaws.com',
			'bucket'=>'app-files',
			'region'=>'us-east-1',
			'access_key'=>'...',
			'secret_key'=>'...',
			'style'=>'path',
		],
		'mirror'=>[
			'driver'=>'mirror',
			'read'=>'local',
			'writes'=>['local', 's3'],
		],
		'tenant_media'=>[
			'driver'=>'scoped',
			'disk'=>'local',
			'prefix'=>'tenants/example/media',
		],
		'public_assets_readonly'=>[
			'driver'=>'readonly',
			'disk'=>'local',
		],
		'tenant_uploads_limited'=>[
			'driver'=>'quota',
			'disk'=>'tenant_media',
			'max_bytes'=>10737418240,
			'max_objects'=>100000,
		],
		'resilient_media'=>[
			'driver'=>'failover',
			'reads'=>['s3', 'local'],
			'write'=>'s3',
		],
		'hot_media'=>[
			'driver'=>'cached',
			'disk'=>'s3',
			'cache'=>'local',
			'prefix'=>'_dataphyre_cache/media',
			'manifest'=>ROOTPATH['dataphyre'].'cache/storage-hot-media.json',
			'ttl'=>300,
			'write_through'=>true,
		],
		'compressed_exports'=>[
			'driver'=>'compressed',
			'disk'=>'s3',
			'manifest'=>ROOTPATH['dataphyre'].'cache/storage-compressed-exports.json',
			'level'=>6,
			'min_bytes'=>1024,
			'skip_extensions'=>['jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'gz', 'mp4', 'pdf'],
		],
		'retained_records'=>[
			'driver'=>'retention',
			'disk'=>'versioned_documents',
			'manifest'=>ROOTPATH['dataphyre'].'cache/storage-retention-records.json',
			'retain_for'=>'7 years',
			'legal_hold'=>false,
		],
		'temporary_exports'=>[
			'driver'=>'lifecycle',
			'disk'=>'compressed_exports',
			'manifest'=>ROOTPATH['dataphyre'].'cache/storage-lifecycle-exports.json',
			'rules'=>[
				['prefix'=>'exports/tmp', 'delete_after'=>'24 hours'],
				['prefix'=>'exports/monthly', 'delete_after'=>'18 months', 'extensions'=>['csv', 'json']],
			],
		],
		'scanned_uploads'=>[
			'driver'=>'scanned',
			'disk'=>'tenant_uploads_limited',
			'quarantine_disk'=>'local',
			'quarantine_prefix'=>'_dataphyre_quarantine/uploads',
			'manifest'=>ROOTPATH['dataphyre'].'cache/storage-scan-uploads.json',
			'scanner_command'=>'clamscan --no-summary {file}',
			'require_scanner'=>false,
			'deny_patterns'=>[],
		],
		'tagged_media'=>[
			'driver'=>'tagged',
			'disk'=>'scanned_uploads',
			'manifest'=>ROOTPATH['dataphyre'].'cache/storage-tags-media.json',
		],
		'evented_media'=>[
			'driver'=>'evented',
			'disk'=>'tagged_media',
			'log'=>ROOTPATH['dataphyre'].'logs/storage-events.jsonl',
			'listeners'=>[],
		],
		'policy_media'=>[
			'driver'=>'policy',
			'disk'=>'evented_media',
			'default_allow'=>true,
			'rules'=>[
				['effect'=>'deny', 'actions'=>['delete', 'write'], 'prefix'=>'protected'],
				['effect'=>'deny', 'actions'=>['write'], 'prefix'=>'uploads', 'extensions'=>['php', 'phtml', 'phar']],
			],
		],
		'throttled_media'=>[
			'driver'=>'rate_limited',
			'disk'=>'policy_media',
			'state'=>ROOTPATH['dataphyre'].'cache/storage-rate-limits-media.json',
			'limits'=>[
				'write'=>['limit'=>120, 'window'=>'1 minute'],
				'temporary_url'=>['limit'=>600, 'window'=>'1 minute'],
			],
		],
		'versioned_documents'=>[
			'driver'=>'versioned',
			'disk'=>'local',
			'prefix'=>'_dataphyre_versions/documents',
			'manifest'=>ROOTPATH['dataphyre'].'cache/storage-versions.json',
			'keep'=>25,
		],
		'deduplicated_media'=>[
			'driver'=>'deduplicated',
			'disk'=>'local',
			'prefix'=>'_dataphyre_blobs/media',
			'manifest'=>ROOTPATH['dataphyre'].'cache/storage-deduplicated-media.json',
			'algorithm'=>'sha256',
		],
		'verified_documents'=>[
			'driver'=>'integrity',
			'disk'=>'versioned_documents',
			'manifest'=>ROOTPATH['dataphyre'].'cache/storage-integrity.json',
			'algorithm'=>'sha256',
		],
		'audited_documents'=>[
			'driver'=>'audit',
			'disk'=>'verified_documents',
			'log'=>ROOTPATH['dataphyre'].'logs/storage-audit.jsonl',
		],
	],
];
```

S3-compatible disks also cover providers such as Vestra S3 mode, Cloudflare R2,
DigitalOcean Spaces, MinIO, and compatible private object stores by changing
`endpoint`, `region`, credentials, and optional `public_url`.

## Vestra Boundary

Use the S3-compatible driver when Vestra should behave like object storage with
bucket and key semantics:

```php
'store_content'=>[
	'driver'=>'s3',
	'endpoint'=>'https://vestra.example.com/s3',
	'bucket'=>'store-content',
	'region'=>'auto',
	'access_key'=>'...',
	'secret_key'=>'...',
	'style'=>'path',
	'public_url'=>null,
],
```

Use the `vestra` driver only when application code wants Storage's logical path
API while the persisted value is a Vestra Fabric reference object. The driver
does not emulate S3 and does not persist delivery URLs. Its manifest maps paths
to stable references:

```json
{
  "catalog/hero.jpg": {
    "driver": "vestra",
    "object_id": 123456789,
    "tenant": "shopiro-store-content",
    "fabric": {
      "blockid": 123456789,
      "tenant_url_template": "/v/{tenant}/{rate}/{blockid}",
      "rate_source": "tenant_context"
    },
    "tokens": {
      "passkey": "..."
    }
  }
}
```

The Vestra module owns Vestra Fabric behavior: propagation, full references,
current tenant/rate resolution, token issuance, application usage accounting,
tenant-aware delivery URL generation, and resource ingestion.
Storage owns file/disk ergonomics: logical paths, streaming, temporary URLs,
wrappers, sync, quotas, and diagnostics.

## Kernel API

```php
\dataphyre\storage::put('invoices/1001.pdf', $bytes);
$bytes=\dataphyre\storage::get('invoices/1001.pdf');
$stream=\dataphyre\storage::read_stream('large/video.mp4');
$exists=\dataphyre\storage::exists('avatars/u1.jpg');
$metadata=\dataphyre\storage::metadata('avatars/u1.jpg');
$files=\dataphyre\storage::list('avatars');
$hash=\dataphyre\storage::checksum('avatars/u1.jpg');
\dataphyre\storage::copy('avatars/u1.jpg', 'avatars/archive/u1.jpg');
\dataphyre\storage::move('tmp/import.csv', 'imports/current.csv');
\dataphyre\storage::put_uploaded_file('avatars/u1.jpg', $_FILES['avatar'], 'local');
$uploadUrl=\dataphyre\storage::temporary_upload_url('imports/client.csv', time()+600, 's3');
$partUrls=\dataphyre\storage::temporary_multipart_upload_urls('videos/raw.mp4', $uploadId, 8, time()+900, 's3');
$versions=\dataphyre\storage::versions('contracts/acme.pdf', 'versioned_documents');
\dataphyre\storage::prune_versions('contracts/acme.pdf', 'versioned_documents', ['keep'=>10]);
$dedupe=\dataphyre\storage::deduplication_report('products', 'deduplicated_media');
$quota=\dataphyre\storage::quota_report('', 'tenant_uploads_limited');
$cache=\dataphyre\storage::cache_report('products', 'hot_media');
$compression=\dataphyre\storage::compression_report('exports', 'compressed_exports');
$retention=\dataphyre\storage::retention_report('contracts', 'retained_records');
$lifecycle=\dataphyre\storage::lifecycle_report('exports', 'temporary_exports');
$scan=\dataphyre\storage::scan_report('uploads', 'scanned_uploads');
$tagged=\dataphyre\storage::find_by_tags(['hero', 'approved'], 'tagged_media');
$policy=\dataphyre\storage::policy_report('', 'policy_media');
$limits=\dataphyre\storage::rate_limit_report('', 'throttled_media');
$events=\dataphyre\storage::event_trail('products/sku-100/hero.jpg', 'evented_media');
$manifests=\dataphyre\storage::manifest_report();
$health=\dataphyre\storage::diagnostics('local');
$sync=\dataphyre\storage::sync('local', 's3', 'media', ['dry_run'=>true]);
$integrity=\dataphyre\storage::verify_integrity('contracts/acme.pdf', 'verified_documents');
$events=\dataphyre\storage::audit_trail('contracts/acme.pdf', 'audited_documents');
\dataphyre\storage::delete('tmp/import.csv');
```

Storage intentionally avoids registering standalone helper functions. Use `\dataphyre\storage` from Dataphyre runtime code, or the framework facade below from application code.

## Framework API

```php
use Dataphyre\Storage\Storage;

Storage::put('avatars/u1.jpg', fopen($upload, 'rb'), 'local');
Storage::putUploadedFile('avatars/u1.jpg', $_FILES['avatar'], 'local');
$stream=Storage::readStream('avatars/u1.jpg', 'local');
$url=Storage::temporaryUrl('avatars/u1.jpg', time()+300, 's3');
$uploadUrl=Storage::temporaryUploadUrl('imports/client.csv', time()+600, 's3', [
	'content_type'=>'text/csv',
]);
$partUrls=Storage::temporaryMultipartUploadUrls('videos/raw.mp4', $uploadId, 8, time()+900, 's3');
$versions=Storage::versions('contracts/acme.pdf', 'versioned_documents');
Storage::pruneVersions('contracts/acme.pdf', 'versioned_documents', ['keep'=>10]);
$dedupe=Storage::deduplicationReport('products', 'deduplicated_media');
$quota=Storage::quotaReport('', 'tenant_uploads_limited');
$cache=Storage::cacheReport('products', 'hot_media');
$compression=Storage::compressionReport('exports', 'compressed_exports');
$retention=Storage::retentionReport('contracts', 'retained_records');
$lifecycle=Storage::lifecycleReport('exports', 'temporary_exports');
$scan=Storage::scanReport('uploads', 'scanned_uploads');
$tagged=Storage::findByTags(['hero', 'approved'], 'tagged_media');
$policy=Storage::policyReport('', 'policy_media');
$limits=Storage::rateLimitReport('', 'throttled_media');
$events=Storage::eventTrail('products/sku-100/hero.jpg', 'evented_media');
$manifests=Storage::manifestReport();
$health=Storage::diagnostics('local');
$sync=Storage::sync('local', 's3', 'media', ['dry_run'=>true]);
$integrity=Storage::verifyIntegrity('contracts/acme.pdf', 'verified_documents');
$events=Storage::auditTrail('contracts/acme.pdf', 'audited_documents');
$hash=Storage::checksum('avatars/u1.jpg', 'local');
```

`Storage::disk($name)` returns the underlying driver instance when lower-level
control is needed.

## Streaming

`put(...)` accepts either a string or a readable stream resource. `readStream(...)`
returns a stream resource so callers can pass data to response emitters, hashing
pipelines, media processors, or other streaming consumers without committing to a
string-only workflow.

Current remote drivers buffer response bodies through PHP streams. The contract
is stream-first, so drivers can later use native multipart or provider SDK
streaming without changing application code.

## Testing And Fake Disks

The `memory` driver stores objects in process memory. It is useful for unit
tests, CI, and local workflows that should not touch local disk, S3, or Vestra
providers.

```php
'testing'=>[
	'driver'=>'memory',
	'prefix'=>'tests',
],
```

```php
Storage::put('avatars/u1.jpg', 'fake-bytes', 'testing');
$bytes=Storage::get('avatars/u1.jpg', 'testing');

Storage::fakeFlush();
$snapshot=Storage::fakeSnapshot();
```

`fakeFlush()` resets every memory disk. `fakeSnapshot()` returns the in-memory
object map for assertions.

## Copy, Move, And Checksums

`copy(...)` and `move(...)` work across disks by streaming the source object
through the manager and writing it to the target disk.

```php
Storage::copy('exports/latest.csv', 'exports/latest.csv', 'local', 's3');
Storage::move('tmp/upload.bin', 'uploads/upload.bin', 'local', 'vestra');
```

`checksum(...)` hashes the decrypted stream when disk encryption is enabled:

```php
$sha256=Storage::checksum('exports/latest.csv');
$md5=Storage::checksum('exports/latest.csv', null, 'md5');
```

## Mirror Disks

Mirror disks write to multiple disks while reading from one primary disk. This is
useful for migrations, hot backups, or gradual Vestra/object-storage rollout.

```php
'media'=>[
	'driver'=>'mirror',
	'read'=>'local',
	'writes'=>['local', 's3', 'vestra'],
],
```

```php
Storage::put('products/sku-100.jpg', $stream, 'media');
```

The write succeeds only if every configured write disk accepts the object.

## Scoped, Read-Only, And Failover Disks

Scoped disks constrain all paths to a prefix on another disk:

```php
'tenant_42'=>[
	'driver'=>'scoped',
	'disk'=>'s3',
	'prefix'=>'tenants/42',
],
```

```php
Storage::put('avatar.jpg', $stream, 'tenant_42');
```

Read-only disks expose an existing disk for reads, lists, metadata, and URLs but
refuse writes and deletes:

```php
'published_assets'=>[
	'driver'=>'readonly',
	'disk'=>'vestra',
],
```

Failover disks read from the first available disk and write to a configured
primary:

```php
'media_failover'=>[
	'driver'=>'failover',
	'reads'=>['s3', 'local'],
	'write'=>'s3',
],
```

This is useful when object storage is primary but a warmed local mirror is
available for graceful degradation.

## Cached And Tiered Disks

Cached disks wrap an authoritative disk with a faster cache disk. Reads hit the
cache while entries are fresh; misses are streamed from the source and written
back into the cache. Writes are write-through by default so newly stored objects
are immediately hot.

```php
'hot_media'=>[
	'driver'=>'cached',
	'disk'=>'s3',
	'cache'=>'local',
	'prefix'=>'_dataphyre_cache/media',
	'manifest'=>ROOTPATH['dataphyre'].'cache/storage-hot-media.json',
	'ttl'=>300,
	'write_through'=>true,
],
```

```php
$image=Storage::get('products/sku-100.jpg', 'hot_media');
$cache=Storage::cacheReport('products', 'hot_media');
Storage::purgeCache('products/sku-100.jpg', 'hot_media');
```

`ttl` is measured in seconds. Set it to `0` for entries that remain fresh until
purged. Metadata returned through a cached disk includes cache status under
`extra.cache`.

## Compressed Disks

Compressed disks wrap another disk and transparently gzip payloads that benefit
from compression. Reads return the original bytes, while the wrapped provider
stores the smaller payload.

```php
'compressed_exports'=>[
	'driver'=>'compressed',
	'disk'=>'s3',
	'manifest'=>ROOTPATH['dataphyre'].'cache/storage-compressed-exports.json',
	'level'=>6,
	'min_bytes'=>1024,
	'skip_extensions'=>['jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'gz', 'mp4', 'pdf'],
],
```

```php
Storage::put('exports/orders.json', $json, 'compressed_exports');
$json=Storage::get('exports/orders.json', 'compressed_exports');
$report=Storage::compressionReport('exports', 'compressed_exports');
```

Objects smaller than `min_bytes`, skipped extensions, and payloads that do not
shrink are stored raw. Per write, pass `['compress'=>true]` to force a compression
attempt or `['compress'=>false]` to bypass it. Metadata includes compression
details under `extra.compression`.

## Retention And Object-Lock Disks

Retention disks wrap another disk and block overwrites or deletes while an
object is under retention or legal hold. This is useful for invoices, contracts,
audit exports, and compliance records where accidental removal should be
impossible through normal storage calls.

```php
'retained_records'=>[
	'driver'=>'retention',
	'disk'=>'versioned_documents',
	'manifest'=>ROOTPATH['dataphyre'].'cache/storage-retention-records.json',
	'retain_for'=>'7 years',
	'legal_hold'=>false,
],
```

```php
Storage::put('contracts/acme.pdf', $pdf, 'retained_records', [
	'retain_until'=>'2033-01-01',
]);

$report=Storage::retentionReport('contracts', 'retained_records');
```

Retention can also be changed after an object exists:

```php
Storage::setRetention('contracts/acme.pdf', 'retained_records', [
	'legal_hold'=>true,
]);

Storage::releaseRetention('contracts/acme.pdf', 'retained_records', [
	'release_legal_hold'=>true,
]);
```

Metadata includes the active retention state under `extra.retention`. A legal
hold always blocks writes and deletes until released; a `retain_until` timestamp
blocks writes and deletes until it passes.

## Lifecycle Policy Disks

Lifecycle disks wrap another disk and track writes in a manifest so scheduled
jobs can dry-run or apply deletion policies. This is useful for temp uploads,
generated exports, old import staging files, and other files with predictable
expiration windows.

```php
'temporary_exports'=>[
	'driver'=>'lifecycle',
	'disk'=>'compressed_exports',
	'manifest'=>ROOTPATH['dataphyre'].'cache/storage-lifecycle-exports.json',
	'rules'=>[
		['prefix'=>'exports/tmp', 'delete_after'=>'24 hours'],
		['prefix'=>'exports/monthly', 'delete_after'=>'18 months', 'extensions'=>['csv', 'json']],
	],
],
```

```php
Storage::put('exports/tmp/import-preview.json', $json, 'temporary_exports');

$preview=Storage::lifecycleReport('exports', 'temporary_exports');
$deleted=Storage::applyLifecycle('exports', 'temporary_exports');
```

`lifecycleReport(...)` is always a dry run. `applyLifecycle(...)` deletes eligible
objects and removes them from the lifecycle manifest. Pass `['dry_run'=>true]`
to `applyLifecycle(...)` when a scheduler wants the same command path without
mutating storage.

## Scanned And Quarantined Disks

Scanned disks wrap another disk and inspect each write before it becomes a
readable application object. Clean files are stored normally. Blocked files are
written to quarantine storage and the write returns `false`.

```php
'scanned_uploads'=>[
	'driver'=>'scanned',
	'disk'=>'tenant_uploads_limited',
	'quarantine_disk'=>'local',
	'quarantine_prefix'=>'_dataphyre_quarantine/uploads',
	'manifest'=>ROOTPATH['dataphyre'].'cache/storage-scan-uploads.json',
	'scanner_command'=>'clamscan --no-summary {file}',
	'require_scanner'=>false,
	'deny_patterns'=>[],
],
```

```php
if(Storage::put('uploads/avatar.png', $bytes, 'scanned_uploads')){
	// Clean and stored.
}

$report=Storage::scanReport('uploads', 'scanned_uploads');
Storage::purgeQuarantine('uploads', 'scanned_uploads');
```

`scanner_command` receives a temporary file path through `{file}` and should exit
with `0` for clean content. `deny_patterns` can block known signatures or test
fixtures without a scanner binary. Metadata includes scan status under
`extra.scan`.

## Tagged And Indexed Disks

Tagged disks wrap another disk and maintain a lightweight manifest of object
tags and custom metadata. This gives storage-backed media libraries, moderation
queues, exports, and tenant file browsers a searchable index without requiring a
separate database table for every file.

```php
'tagged_media'=>[
	'driver'=>'tagged',
	'disk'=>'scanned_uploads',
	'manifest'=>ROOTPATH['dataphyre'].'cache/storage-tags-media.json',
],
```

```php
Storage::put('products/sku-100/hero.jpg', $bytes, 'tagged_media', [
	'tags'=>['product', 'hero', 'approved'],
	'metadata'=>['sku'=>'sku-100', 'locale'=>'en'],
]);

Storage::tagObject('products/sku-100/hero.jpg', 'tagged_media', [
	'tags'=>['homepage'],
]);

$heroes=Storage::findByTags(['hero', 'approved'], 'tagged_media');
$report=Storage::tagReport('products', 'tagged_media');
```

`findByTags(...)` matches all requested tags by default. Pass
`['match_all'=>false]` for any-tag matching or `['prefix'=>'products']` to limit
the search scope. Metadata includes `extra.tags` and `extra.custom_metadata`.

## Policy And ACL Disks

Policy disks wrap another disk and allow or deny storage actions by prefix,
extension, action, and optional actor. This keeps upload and deletion rules close
to storage, where they are hardest to accidentally bypass.

```php
'policy_media'=>[
	'driver'=>'policy',
	'disk'=>'evented_media',
	'default_allow'=>true,
	'rules'=>[
		['effect'=>'deny', 'actions'=>['delete', 'write'], 'prefix'=>'protected'],
		['effect'=>'deny', 'actions'=>['write'], 'prefix'=>'uploads', 'extensions'=>['php', 'phtml', 'phar']],
	],
],
```

```php
Storage::put('uploads/avatar.jpg', $bytes, 'policy_media');
Storage::delete('protected/legal.pdf', 'policy_media'); // false

$policy=Storage::policyReport('', 'policy_media');
```

Rules are evaluated in order and the last matching rule wins. Use `actions=>'*'`
or omit actions to match every operation.

## Rate-Limited Disks

Rate-limited disks wrap another disk and apply fixed-window limits per operation
and identity. This is useful for noisy tenants, upload bursts, aggressive signed
URL generation, and background jobs that need backpressure.

```php
'throttled_media'=>[
	'driver'=>'rate_limited',
	'disk'=>'policy_media',
	'state'=>ROOTPATH['dataphyre'].'cache/storage-rate-limits-media.json',
	'limits'=>[
		'write'=>['limit'=>120, 'window'=>'1 minute'],
		'temporary_url'=>['limit'=>600, 'window'=>'1 minute'],
	],
],
```

```php
Storage::put('uploads/a.jpg', $bytes, 'throttled_media', [
	'actor'=>$tenantId,
]);

$limits=Storage::rateLimitReport('', 'throttled_media');
Storage::resetRateLimits('', 'throttled_media');
```

The bucket identity comes from `rate_limit_key`, then `actor`, then remote IP,
then `global`. Actions without a configured limit pass through.

## Events And Hooks

Storage emits global events for core operations such as reads, writes, deletes,
copies, and stream reads. Applications can attach listeners through the framework
facade:

```php
Storage::listen('storage.write', function(array $event): void {
	// Queue thumbnail generation, notify moderation, publish metrics, etc.
});
```

Evented disks wrap another disk and can also call disk-specific listeners or
write a JSONL event trail:

```php
'evented_media'=>[
	'driver'=>'evented',
	'disk'=>'tagged_media',
	'log'=>ROOTPATH['dataphyre'].'logs/storage-events.jsonl',
	'listeners'=>[],
],
```

```php
Storage::put('products/sku-100/hero.jpg', $bytes, 'evented_media');
$events=Storage::eventTrail('products/sku-100/hero.jpg', 'evented_media');
```

Supported event names include `storage.before_write`, `storage.write`,
`storage.read`, `storage.read_stream`, `storage.before_delete`, `storage.delete`,
and `storage.copy`. Register `*` as the event name to receive every global
storage event.

## Manifest Export And Import

Many advanced disks keep JSON manifests for versions, integrity records, cached
objects, deduplicated aliases, retention locks, lifecycle state, scan results,
tags, and event/audit logs. Dataphyre can report, export, and import those
configured manifest files for backups or migrations.

```php
$report=Storage::manifestReport();

$bundle=Storage::exportManifests(null, [
	'path'=>ROOTPATH['dataphyre'].'backup/storage-manifests.json',
]);

$result=Storage::importManifests(
	ROOTPATH['dataphyre'].'backup/storage-manifests.json',
	['mode'=>'replace']
);
```

Pass a disk name to export or report one disk's manifest. `mode` can be
`replace` or `merge`; merge keeps existing JSON keys and overlays imported
values. Non-JSON log files are preserved in the bundle as `_raw` content.

## Diagnostics

Diagnostics verify that configured disks can instantiate and perform basic
operations. By default, Dataphyre runs a write/read/delete probe, list probe,
temporary URL capability check, manifest discovery, and common config warnings.

```php
$local=Storage::diagnostics('local');
$all=Storage::diagnostics(null, ['probe_write'=>false]);
```

Use `probe_write=>false` for read-only or production checks where a non-mutating
health report is preferred. The result contains per-disk checks, warnings, and a
top-level `ok` flag that is suitable for deployment checks or admin dashboards.

## Disk Synchronization

Storage can reconcile one disk into another for provider migrations, local-to-S3
rollouts, cache repair, disaster recovery, or mirror backfills. Sync defaults to
dry-run mode.

```php
$preview=Storage::sync('local', 's3', 'media', [
	'dry_run'=>true,
]);

$result=Storage::sync('local', 's3', 'media', [
	'dry_run'=>false,
	'delete_extra'=>true,
]);
```

By default, sync compares checksums and copies missing or changed objects.
`delete_extra=>true` removes target-only objects under the same prefix. Use
`compare=>'size'` for faster but less strict reconciliation.

## Quota-Limited Disks

Quota disks wrap another disk and reject writes that would exceed configured
byte or object limits. They are useful for tenant media, import staging areas,
free-plan upload caps, and operational safety limits.

```php
'tenant_uploads_limited'=>[
	'driver'=>'quota',
	'disk'=>'tenant_42',
	'max_bytes'=>10737418240,
	'max_objects'=>100000,
],
```

```php
if(Storage::put('catalog/image.jpg', $bytes, 'tenant_uploads_limited')){
	// Stored within quota.
}

$usage=Storage::quotaReport('', 'tenant_uploads_limited');
```

The report includes current bytes, object count, configured limits, and remaining
capacity. Metadata returned through a quota disk includes the same usage snapshot
under `extra.quota`.

## Object Attributes And Write Guards

Writes accept common object attributes:

```php
Storage::put('assets/app.css', $css, 's3', [
	'visibility'=>'public',
	'content_type'=>'text/css',
	'cache_control'=>'public, max-age=31536000, immutable',
	'content_disposition'=>'inline',
]);
```

Local disks persist these attributes beside the file for metadata lookups. S3
compatible disks send the equivalent object headers.

Disks can also reject oversized files, unexpected extensions, or unexpected
caller-provided content types:

```php
'avatars'=>[
	'driver'=>'scoped',
	'disk'=>'local',
	'prefix'=>'avatars',
	'max_bytes'=>5242880,
	'allowed_extensions'=>['jpg', 'jpeg', 'png', 'webp'],
	'allowed_mime_types'=>['image/jpeg', 'image/png', 'image/webp'],
],
```

## Upload Intake

`putUploadedFile(...)` accepts a single `$_FILES[...]` entry, checks PHP's upload
error code, applies disk write guards, stores the original file name as metadata
when the driver supports local attributes, and writes through the same streaming
path as `putFile(...)`.

```php
if(Storage::putUploadedFile('avatars/'.$userId.'.jpg', $_FILES['avatar'], 'avatars')){
	// Stored.
}
```

Pass explicit attributes when browser-provided metadata should not be trusted:

```php
Storage::putUploadedFile('avatars/'.$userId.'.jpg', $_FILES['avatar'], 'avatars', [
	'content_type'=>'image/jpeg',
	'visibility'=>'private',
]);
```

## Direct Upload URLs

S3-compatible disks can generate presigned PUT URLs for direct browser or worker
uploads. The method returns `false` for drivers that do not support direct upload
signing.

```php
$url=Storage::temporaryUploadUrl('imports/'.$token.'.csv', time()+600, 's3', [
	'content_type'=>'text/csv',
	'visibility'=>'private',
]);
```

For S3-compatible downloads, `temporaryUrl(...)` returns `public_url` when
configured, otherwise it generates a SigV4 presigned GET URL.

## Multipart Direct Uploads

S3-compatible disks support multipart workflows for large browser or worker
uploads:

```php
$session=Storage::initiateMultipartUpload('videos/raw.mp4', 's3', [
	'content_type'=>'video/mp4',
	'visibility'=>'private',
]);

$partUrls=Storage::temporaryMultipartUploadUrls(
	'videos/raw.mp4',
	$session['upload_id'],
	12,
	time()+900,
	's3',
	['content_type'=>'video/mp4']
);
```

The client uploads each part with `PUT`, records the returned `ETag` header, and
the server completes the upload:

```php
Storage::completeMultipartUpload('videos/raw.mp4', $session['upload_id'], [
	['part_number'=>1, 'etag'=>$etag1],
	['part_number'=>2, 'etag'=>$etag2],
], 's3');
```

Abort abandoned sessions when the client cancels:

```php
Storage::abortMultipartUpload('videos/raw.mp4', $session['upload_id'], 's3');
```

The storage layer caps generated part URLs to S3's 10,000 part limit.

## Versioned And Soft-Delete Disks

Versioned disks wrap another disk. Before an existing object is overwritten or
deleted, Dataphyre snapshots it into a version prefix and records the version in
a manifest.

```php
'versioned_documents'=>[
	'driver'=>'versioned',
	'disk'=>'s3',
	'prefix'=>'_dataphyre_versions/documents',
	'manifest'=>ROOTPATH['dataphyre'].'cache/storage-versions.json',
	'keep'=>25,
],
```

```php
Storage::put('contracts/acme.pdf', $draft, 'versioned_documents');
Storage::put('contracts/acme.pdf', $final, 'versioned_documents');

$versions=Storage::versions('contracts/acme.pdf', 'versioned_documents');
Storage::restoreVersion('contracts/acme.pdf', $versions[0]['id'], 'versioned_documents');
```

Deletes are soft by default on versioned disks because the current object is
snapshotted before removal:

```php
Storage::delete('contracts/acme.pdf', 'versioned_documents');
Storage::restoreVersion('contracts/acme.pdf', $deletedVersionId, 'versioned_documents');
```

Use `purgeVersion(...)` to remove a stored version permanently.

Lifecycle pruning can be run by a scheduler:

```php
$result=Storage::pruneVersions(null, 'versioned_documents', [
	'keep'=>25,
	'older_than'=>'90 days ago',
]);
```

Pass a path to prune one object's history, or `null` to evaluate every object in
the manifest. The result includes `ok`, `pruned`, and `deleted_ids`.

## Deduplicated And Content-Addressed Disks

Deduplicated disks wrap another disk, hash each write, store the bytes under a
content-addressed blob path, and map logical paths to that blob in a manifest.
If two logical paths contain identical bytes, the wrapped disk stores one blob.

```php
'deduplicated_media'=>[
	'driver'=>'deduplicated',
	'disk'=>'s3',
	'prefix'=>'_dataphyre_blobs/media',
	'manifest'=>ROOTPATH['dataphyre'].'cache/storage-deduplicated-media.json',
	'algorithm'=>'sha256',
],
```

```php
Storage::put('products/a/hero.jpg', $imageBytes, 'deduplicated_media');
Storage::put('products/b/hero.jpg', $imageBytes, 'deduplicated_media');

$report=Storage::deduplicationReport('products', 'deduplicated_media');
```

Deleting one logical path only removes its manifest entry. The physical blob is
removed automatically after the last logical reference disappears. Metadata from
a deduplicated disk includes the content hash, blob path, and reference count
under `extra.deduplicated`.

## Integrity-Verified Disks

Integrity disks wrap another disk and record checksums after successful writes.
They can verify one object or scan every recorded object under a prefix.

```php
'verified_documents'=>[
	'driver'=>'integrity',
	'disk'=>'versioned_documents',
	'manifest'=>ROOTPATH['dataphyre'].'cache/storage-integrity.json',
	'algorithm'=>'sha256',
],
```

```php
Storage::put('contracts/acme.pdf', $bytes, 'verified_documents');

$result=Storage::verifyIntegrity('contracts/acme.pdf', 'verified_documents');
$report=Storage::integrityReport('contracts', 'verified_documents');
```

Integrity records are removed when the wrapped object is deleted. Metadata from
an integrity disk includes the recorded checksum under `extra.integrity`.

## Audited Disks

Audit disks wrap another disk and append JSONL events for reads, writes, lists,
metadata calls, temporary URL creation, and deletes.

```php
'audited_documents'=>[
	'driver'=>'audit',
	'disk'=>'verified_documents',
	'log'=>ROOTPATH['dataphyre'].'logs/storage-audit.jsonl',
],
```

```php
Storage::put('contracts/acme.pdf', $bytes, 'audited_documents', [
	'actor'=>$userId,
	'reason'=>'contract upload',
]);

$events=Storage::auditTrail('contracts/acme.pdf', 'audited_documents', [
	'limit'=>20,
]);
```

Audit rows include `operation`, `path`, `ok`, `actor`, `request_id`, and `ip`
when those values are available.

## Local Signed URLs

Local disks can generate signed URLs when both `url` and `signing_key` are set:

```php
$url=Storage::temporaryUrl('private/report.pdf', time()+300, 'local');
```

The signature format is intentionally simple so a route/controller can validate
it before serving the file:

```php
$valid=\Dataphyre\Storage\Drivers\LocalDriver::verifyTemporaryUrl(
	$path,
	(int)($_GET['expires'] ?? 0),
	(string)($_GET['signature'] ?? ''),
	$signingKey
);
```

## Encryption

Set `disks.<name>.encryption.enabled` to `true` to encrypt objects before they
leave the application process. The encrypted object format is chunked
AES-256-GCM with per-chunk IVs and authentication tags.

Keys can be configured directly:

```php
'encryption'=>[
	'enabled'=>true,
	'key'=>'base64:...',
],
```

or through a file:

```php
'encryption'=>[
	'enabled'=>true,
	'key_file'=>ROOTPATH['dataphyre'].'config/static/storage.key',
],
```

Per-call encryption can be forced with:

```php
Storage::put('secrets/export.json', $json, 's3', ['encrypt'=>true]);
$json=Storage::get('secrets/export.json', 's3', ['encrypt'=>true]);
```

## Vestra Disk

The `vestra` driver wraps the Vestra Fabric client as a path-to-reference
adapter. Writes are propagated through the Vestra module and tracked in a local
manifest that maps application file paths to structured Vestra object references.

```php
Storage::putFile('catalog/hero.jpg', $local_file, 'vestra');
$url=Storage::temporaryUrl('catalog/hero.jpg', time()+3600, 'vestra');
```

This gives application code stable logical paths while the Vestra module keeps
fabric-specific references, tenant delivery, and application usage accounting out
of the generic storage abstraction. For bucket/key storage against Vestra, prefer
an `s3` disk configured for Vestra's S3-compatible endpoint.

The manifest stores Vestra Fabric references, not generated delivery URLs.
`temporaryUrl()` asks the Vestra module to resolve the current tenant/rate
context and issue a current Fabric token, so existing references can follow later
billing-plan changes without rewriting every stored object reference.

## Custom Drivers

Drivers implement `Dataphyre\Storage\Contracts\StorageDriver`.

```php
Storage::extend('azure_blob', function(array $config){
	return new AzureBlobStorageDriver($config);
});
```

Once registered, use it from config:

```php
'azure'=>[
	'driver'=>'azure_blob',
	'container'=>'files',
],
```

## Security Notes

- Local paths are normalized before touching disk.
- Local writes use a temporary file and atomic rename.
- Encryption happens before provider writes, so cloud providers receive
  ciphertext rather than plaintext.
- Prefer long-lived keys from a secret file or secret manager rather than
  hardcoding keys in committed config.
