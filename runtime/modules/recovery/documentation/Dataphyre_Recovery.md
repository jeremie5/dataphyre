# Dataphyre Recovery

Dataphyre Recovery is the application-neutral failure and corrective-action
contract for HTTP applications. It complements internal issue reporting and
fatal-request recovery handling; it does not expose either system's private
diagnostics.

The module provides:

- stable, localized problem definitions compatible with RFC 9457;
- permission- and scope-aware corrective actions;
- explicit retry, data-trust, severity, and incident policies;
- allowlist-only, bounded support evidence;
- irreversible incident fingerprints generated from approved dimensions;
- a compatibility adapter that can enrich an existing JSON API without
  replacing its legacy fields;
- an application-owned observer hook for incident persistence.

## Bootstrap

```php
\dataphyre\core::load_framework_module('recovery');
```

The module depends on Dataphyre HTTP only for `ProblemResponse`. Its catalog and
resolution objects can otherwise be used independently.

## Register a catalog

```php
use Dataphyre\Recovery\ProblemDefinition;
use Dataphyre\Recovery\Recovery;
use Dataphyre\Recovery\RecoveryActionDefinition;
use Dataphyre\Recovery\RecoveryManager;
use Dataphyre\Recovery\RecoveryRegistry;

$registry=(new RecoveryRegistry())
    ->registerAction(new RecoveryActionDefinition(
        'retry',
        ['en'=>'Try again', 'fr'=>'Réessayer'],
        ['kind'=>'retry', 'retry_safe'=>true]
    ))
    ->registerProblem(new ProblemDefinition(
        'service_unavailable',
        ['en'=>'This service is temporarily unavailable', 'fr'=>'Ce service est temporairement indisponible'],
        503,
        ['en'=>'Wait a moment and try again.', 'fr'=>'Attendez un moment, puis réessayez.'],
        [
            'help_topic'=>'connection',
            'retry_policy'=>'backoff',
            'retry_after_seconds'=>15,
            'data_state'=>'unknown',
            'incident_policy'=>'aggregate',
            'evidence_keys'=>['provider', 'surface'],
            'fingerprint_keys'=>['provider'],
            'actions'=>['retry'],
        ]
    ), ['provider_unreachable'])
    ->fallback('service_unavailable');

Recovery::use(new RecoveryManager(
    $registry,
    'https://example.test/problems',
    static fn(ProblemDefinition $definition): string =>
        'https://example.test/help/'.$definition->helpTopic(),
    static function($problem, $context): void {
        // Persist or queue reportable incidents in the application.
    }
));
```

Applications own their catalog, localization copy, access resolver, help URLs,
and incident storage. Dataphyre never assumes a tenant model or writes an
incident row.

## Resolve a problem

```php
use Dataphyre\Recovery\ProblemResponse;
use Dataphyre\Recovery\Recovery;
use Dataphyre\Recovery\RecoveryContext;

$context=new RecoveryContext(
    permissions:['orders.retry'],
    scope:['scope_type'=>'store', 'store_id'=>42],
    locale:'fr-CA',
    requestMethod:'POST',
    requestPath:'/api/orders'
);

$problem=Recovery::problem(
    'provider_unreachable',
    $context,
    evidence:['provider'=>'payments', 'authorization'=>'never exposed']
);

return ProblemResponse::compatibility($problem, [
    'ok'=>false,
    'status'=>'provider_unreachable',
]);
```

The compatibility response keeps `ok` and the legacy string `status`, while the
nested `problem.status` remains the numeric HTTP status defined by RFC 9457.
New APIs may use `ProblemResponse::make()` to emit
`application/problem+json` directly.

## Safety boundaries

- Evidence is empty unless a definition explicitly allowlists a path.
- Secret-bearing path names are rejected even if accidentally allowlisted.
- Evidence is bounded by depth, item count, and string length.
- Incident fingerprints use only approved scope dimensions and fingerprint
  evidence; raw values cannot be recovered from the hash.
- Corrective actions are emitted only after their permission, scope, and
  optional eligibility policies pass.
- The incident observer is called only for definitions whose incident policy is
  `aggregate` or `individual`.
- A public problem is operator/customer guidance, not an exception dump. Do not
  put SQL, stack traces, tokens, credentials, private payloads, or internal
  paths into titles or details.

## Extension boundary

Add application behavior through `RecoveryRegistry`, action eligibility
callbacks, the help resolver, and the incident observer. Do not patch this
module with product-specific statuses, permissions, routes, or tenant fields.
