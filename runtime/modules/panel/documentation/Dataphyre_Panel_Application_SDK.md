# Dataphyre Panel Application SDK

Panel can compile host-bound application contracts into deterministic PHP and
TypeScript clients. This is separate from the extension SDK: the extension SDK
owns in-process hooks and browser lifecycle, while the application SDK owns
typed HTTP operations, event payloads, Studio artifacts, and compatibility.

The SDK compiler does not install routes, open network connections, write files,
or embed credentials. The host chooses every route and supplies authentication,
CSRF, retry, tracing, and transport behavior.

## First-party protocol contract

`PanelSdkProtocolCatalog::firstParty()` exposes Panel's stable protocol shapes
only for routes explicitly supplied by the host:

```php
use Dataphyre\Panel\PanelDeveloperToolkit;

$contract=PanelDeveloperToolkit::sdkContract(
    'example-operations',
    '1.0.0',
    [
        'data_surface'=>'/api/panel/data-surfaces/{surface}',
        'command'=>'/api/panel/commands',
        'events'=>'/api/panel/events',
        'studio_artifact'=>'/api/panel/studio/{document}',
    ],
    [
        'bindings'=>[
            'source_epoch'=>hash('sha256', $sourceEpoch),
            'platform'=>hash('sha256', $platformManifest),
        ],
    ],
);
```

The preset contains:

- signed DataSurface window and cross-filter requests;
- host-resolved idempotent command requests and signed command receipts;
- signed event-fabric envelopes;
- portable Studio document and definition artifacts;
- Panel's stable public error envelope.

Command clients cannot select tenant, actor, ability, or authority evidence.
Those values are resolved and authorized by the host before it creates a
`PanelCommandEnvelope`.

## Custom operations

`PanelSdkSchema` is a closed, bounded JSON Schema subset. Supported contracts
include scalar constraints, enumerations, arrays, objects, unions, nullability,
formats, patterns, required properties, and typed additional properties.

```php
use Dataphyre\Panel\PanelSdkContract;
use Dataphyre\Panel\PanelSdkOperation;
use Dataphyre\Panel\PanelSdkSchema;

$body=PanelSdkSchema::object([
    'name'=>PanelSdkSchema::string(['minLength'=>1, 'maxLength'=>80]),
], ['name']);

$response=PanelSdkSchema::object([
    'id'=>PanelSdkSchema::string(['minLength'=>1, 'maxLength'=>80]),
    'name'=>PanelSdkSchema::string(['minLength'=>1, 'maxLength'=>80]),
], ['id', 'name']);

$operation=PanelSdkOperation::post(
    'create_item',
    '/api/items/{collection}',
    $response,
    [
        'body'=>$body,
        'scopes'=>['items.write'],
        'idempotent'=>true,
    ],
);

$contract=PanelSdkContract::make('acme-panel', '1.0.0')
    ->withOperation($operation)
    ->withEvent('item.created', $response)
    ->withArtifact('studio.item', $body);
```

Operation paths must be same-origin absolute paths. Path schemas contain exactly
the required placeholders. Duplicate method/path pairs, request bodies on GET or
DELETE, unsupported schema keywords, unbounded structures, non-JSON values, and
credential-like metadata fail before generation.

## Generate both clients

```php
$package=PanelDeveloperToolkit::sdkGenerator()->generate($contract, [
    'php_namespace'=>'Acme\\PanelSdk',
    'php_class'=>'PanelClient',
    'composer_package'=>'acme/panel-sdk',
    'typescript_package'=>'@acme/panel-sdk',
]);

if(!$package->verify()) {
    throw new RuntimeException('Generated SDK integrity check failed.');
}

$files=$package->files(); // In-memory only. The host chooses publication.
```

The package includes the canonical contract, an integrity manifest, per-file
SHA-256 digests, Composer metadata, npm metadata, source, and usage notes.
Generation is deterministic for the same contract and options.

The PHP client injects a `PanelTransport`. The TypeScript client injects a
transport or uses `fetch` with `credentials: "same-origin"`. Both clients:

- validate path, query, and body values before transport;
- validate successful and declared error responses;
- ignore unknown response properties for additive forward compatibility;
- enforce depth and 20,000-node validation budgets;
- validate formats, patterns, bounds, enumerations, and unique items;
- preserve public error codes, HTTP status, correlation id, and bounded
  validation diagnostics.

## Compatibility and semantic versions

```php
$report=PanelDeveloperToolkit::sdkCompatibility($released, $candidate);

if(!$report->versionCompliant()) {
    throw new RuntimeException(
        'SDK release requires a '.$report->requiredBump().' version bump.'
    );
}
```

Compatibility analysis is directional. Request schemas may widen without
breaking existing clients; response schemas may narrow. Removed operations,
changed routes or methods, newly required request properties, expanded response
enumerations, and new security scopes are breaking. Added operations and optional
request properties are additive. Source or platform fingerprint changes are
reported as metadata changes so callers can regenerate without falsely claiming
an API break.

## Host responsibilities

Generated clients do not replace the application security boundary. The host
still owns:

- authenticated route registration and origin policy;
- actor and tenant resolution;
- authorization and command obligation checks;
- CSRF issuance and validation;
- rate limiting, retries, circuit breaking, and telemetry;
- TLS, cookies, bearer tokens, signing keys, and secret storage;
- package publication and generated-file writes.

No token, cookie, password, credential, private key, callable, or transport
endpoint secret is accepted in SDK metadata or emitted into public manifests.
