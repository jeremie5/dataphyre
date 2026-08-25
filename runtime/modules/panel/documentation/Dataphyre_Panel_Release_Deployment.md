# Dataphyre Panel release deployment adapters

Panel's release execution engine is a durable prepare, activate, verify, and
rollback state machine. The declarative deployment adapter binds that engine to
five production target profiles without placing cloud credentials, shell
commands, SDK clients, or network policy inside Panel.

Supported profiles are:

| Driver | Required target identity | Phase intent examples |
| --- | --- | --- |
| Kubernetes | namespace, workload kind, workload, container | stage revision, promote workload, verify rollout, rollback workload |
| Nomad | namespace, job, group, task | register version, promote job, verify deployment, rollback version |
| ECS | region, cluster, service, container | register task revision, update service, verify deployment, rollback service |
| Compose | project, service | prepare release, promote service, verify service, rollback service |
| Filesystem | root reference, current-link reference | stage directory, switch current release, verify current release, restore previous release |

Filesystem and Compose identifiers are references understood by the host
transport. They are not raw paths or command fragments.

## Configure an adapter

```php
use Dataphyre\Panel\PanelCallbackReleaseDeploymentTransport;
use Dataphyre\Panel\PanelDeclarativeReleaseDeploymentAdapter;
use Dataphyre\Panel\PanelReleaseDeploymentProfile;

$profile=PanelReleaseDeploymentProfile::kubernetes(
    'production_primary',
    [
        'cluster_ref'=>'primary',
        'namespace'=>'example-app',
        'workload_kind'=>'Deployment',
        'workload'=>'panel',
        'container'=>'web',
    ],
    'canary',
    [
        'canary_percent'=>5,
        'verify_timeout_seconds'=>600,
        'drain_timeout_seconds'=>120,
        'max_unavailable_percent'=>0,
        'max_surge_percent'=>25,
    ],
);

$transport=new PanelCallbackReleaseDeploymentTransport(
    'deployment_control_plane',
    static fn(array $signedRequest): array => $deploymentClient->dispatch($signedRequest),
);

$adapter=new PanelDeclarativeReleaseDeploymentAdapter(
    $profile,
    $transport,
    $deploymentReceiptKeys,
    $currentDeploymentReceiptKeyId,
);
```

Inject `$adapter` through `operations_os.release_deployment_adapter`. The
existing `PanelReleaseExecutionEngine` then owns scheduling, health-gate
blocking, idempotency, signed durable state, leases, monotonic fences, crash
recovery, activation commit, and automatic rollback.

The callback transport is an application boundary. It can call an approved
HTTP service, cloud SDK wrapper, RPC worker, or local supervisor. It must not
silently weaken the target identity, operation key, fence, or receipt checks.

## Signed requests

Every request binds:

- profile digest, driver, target, strategy, and public target identifiers;
- phase-specific intent and desired artifact digest;
- artifact identity, version, aggregate digest, and component digests;
- release ring and a one-way tenant hash;
- one-way execution and deployment hashes;
- one-way idempotency-key hash, attempt, and monotonic fence;
- the active key identifier, canonical request digest, and HMAC signature.

The raw operation key, tenant, execution ID, deployment ID, credentials,
transport endpoint, and key material are not serialized. Repeating `preview()`
with the same context produces the same request.

The remote worker should verify the request signature before acting. The
operation-key hash is the stable downstream idempotency key. The worker must
reject an older fence for the same operation.

## Bound receipts

The worker returns a signed receipt containing a boolean outcome, normalized
code, request digest, operation-key hash, fence, driver, target, and phase. A
PHP deployment worker can produce the envelope with:

```php
$receipt=PanelDeclarativeReleaseDeploymentAdapter::sealReceipt(
    $request,
    true,
    'activate_complete',
    ['provider_revision'=>$revision],
    $receiptKeyId,
    $receiptKey,
);
```

Panel verifies the receipt HMAC and every request-binding field. A stale,
replayed-for-another-phase, mismatched-target, wrong-fence, altered, malformed,
or untrusted receipt fails closed. Receipt details are sanitized and folded
into a digest; the release journal retains only the bounded redacted result.

Receipt key rotation uses a trusted keyring plus one current signing key. Keep
old verification keys only for the intended overlap window. Changing the
adapter profile, transport manifest, or trusted key configuration changes the
adapter fingerprint and prevents an in-flight execution from resuming under a
different authority graph.

## Rollout behavior

Profiles support `rolling`, `blue_green`, and `canary` strategies. Rollout
values are bounded integers for verification timeout, drain timeout, maximum
unavailable percentage, maximum surge percentage, and canary percentage.
Unknown profile or rollout keys are rejected, including secret-shaped ad hoc
configuration.

The adapter emits intent. It does not pretend that Kubernetes, Nomad, ECS,
Compose, or a filesystem deployment succeeded. The host transport performs the
provider-specific operation and obtains the provider's revision and health
evidence before signing a positive receipt.

If prepare, activate, or verify throws or returns a signed negative receipt,
the execution engine transitions the deployment through failure and invokes a
separately idempotent rollback phase. The release is marked active only after a
positive signed verify receipt and the final control-plane commit.

## Host responsibilities

The host still owns credentials, TLS, DNS and egress policy, SDK versions,
provider permissions, artifact transport, image or package registries,
deployment-worker supervision, health semantics, timeouts, cancellation,
provider-side idempotency storage, stale-fence rejection, logs, monitoring, and
disaster recovery.

Panel never shells out to `kubectl`, `nomad`, `aws`, `docker`, or filesystem
mutation commands by enabling this adapter. This keeps the release state
machine testable and portable while deployment authority remains explicit.
