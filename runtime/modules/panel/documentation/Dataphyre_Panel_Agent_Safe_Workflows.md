# Dataphyre Panel agent-safe workflows

Panel's agent-safe workflow subsystem is a host-agnostic security boundary for bounded tool plans. It accepts structured proposals, validates exact argument contracts, asks the host to authorize every tool and approver, signs immutable plans, enforces human approvals, executes registered adapters, and records a redacted hash-chained audit trail.

It is not an LLM client. It never executes model text, PHP names, callbacks, shell commands, routes, URLs, or classes supplied by a model. It installs no HTTP routes and has no process-global catalog, identity, keyring, or store.

## Ownership boundary

Panel owns:

- immutable tenant, principal, session, panel, and request scope envelopes;
- exact JSON-schema-like argument normalization with depth, node, and byte limits;
- an instance-owned layered tool catalog with explicit contributor provenance and collision policy;
- immutable plan and step hashes bound to catalog revision, policy fingerprint, and the cached host confirmation-verifier fingerprint;
- rotating-key HMAC plan and approval intents with audience, expiry, nonce, parent, scope, and subject claims;
- risk floors, host-verified human confirmation evidence, one- or two-person approval, and separation of duties;
- optimistic revisions, fenced/renewable idempotency reservations, nonce replay rejection, cancellation checks, and a kill switch;
- output, error, redaction, and audit limits;
- append-only SHA-256-linked receipts; and
- exact in-process checkpoint/restore for mutable reference services.

The host must own and configure:

- authenticated identity, tenant resolution, and session binding;
- the `PanelAgentPolicyResolver`, including tool permissions and approver permissions;
- a `PanelAgentConfirmationVerifier` that authenticates a short-lived human gesture and binds opaque evidence to the exact plan and request context;
- a random rotating keyring, retained verification keys, key rotation, and revocation procedures;
- route registration, authentication middleware, CSRF protection, origin checks, request-size limits, and rate limiting;
- operation of either the first-party durable local store, the first-party
  shared-SQL store, or another host-selected `PanelAgentWorkflowStore`
  appropriate to the deployment topology;
- executor-specific cancellation, timeouts, circuit breakers, and downstream authorization;
- the model provider, prompts, prompt retention policy, and the conversion from model output to a structured proposal; and
- operational audit export and retention.

The supplied `InMemoryPanelAgentWorkflowStore` is a bounded deterministic reference implementation. It has expiring/reclaimable execution leases and hard capacity limits, but it is process-local, not durable, and unsuitable for horizontally scaled production execution. Capacity exhaustion fails closed instead of silently evicting idempotency, nonce, cancellation, or audit state.

## Durable local workflow store

`PanelAtomicAgentWorkflowStore` is the first-party crash-safe adapter for one
host or multiple PHP processes sharing a correctly operated local filesystem.
It uses a cross-process lock and immutable, hash-chained, atomically renamed
state snapshots. It persists only hashes/tags for idempotency keys and intent
nonces, validates the complete newest snapshot before use, rejects stale lease
owners, and never falls back to an older snapshot after corruption.

```php
use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceRunner;
use Dataphyre\Panel\PanelAtomicAgentWorkflowStore;

$workflowStore=new PanelAtomicAgentWorkflowStore(
    directory: $stateRoot.'/agent-workflows',
    leaseSeconds: 120,
    maxEntries: 10000,
    retentionSeconds: 86400 * 30,
    retainSnapshots: 32,
);

$report=(new PanelAdapterConformanceRunner())->run(
    PanelAdapterConformanceCatalog::agentWorkflowStore(),
    $disposableStoreWithEquivalentConfiguration,
    ['allow_destructive'=>true],
);

if (!$report->passed()) {
    throw new RuntimeException('Agent workflow store conformance failed.');
}
```

The conformance pack proves optimistic append conflicts, renewable fencing,
stale-owner rejection, exact completed replay, request-bound idempotency,
cancellation tombstones, audit chaining, redaction, and secret-free manifests.
It mutates the adapter and the interface intentionally has no generic deletion
method, so run it only against a disposable deployment scope.

Call `collectGarbage()` explicitly under an operator-owned retention policy.
Completed or long-abandoned reservation state can be pruned after the configured
retention period; cancellation tombstones require a separate explicit opt-in,
and the contiguous audit chain is never compacted. Operate the state root with
least-privileged filesystem permissions, backups, capacity monitoring, and one
consistent lock/filesystem domain. Network filesystems whose rename or lock
semantics are not equivalent must not be assumed safe.

## Distributed shared-SQL workflow store

`PanelPdoAgentWorkflowStore` implements the same contract for MySQL,
PostgreSQL, and SQLite supplied by the host. It adds explicit idempotent schema
setup, a global optimistic revision/audit fence, cross-node renewable
reservations, durable replay and cancellation, domain-separated nonce and
idempotency digests, verified canonical result storage, explicit garbage
collection, a payload-free retained change feed, and stable secret-free storage
errors.

See
[Dataphyre_Panel_Distributed_Agent_Workflows.md](Dataphyre_Panel_Distributed_Agent_Workflows.md)
for schema ownership, configuration bounds, transaction behavior, scaling
tradeoffs, retention, stored-data policy, conformance, and live-database
evidence. Multi-node deployments should use this adapter or another
transactional implementation that passes the destructive conformance pack;
they must not treat a convenient network filesystem as a distributed lock.

## Deferred execution on leased operation workers

`PanelAgentWorkflowOperationBridge` can register agent execution as one handler
in Panel's optional leased-operation runtime. The operation record persists an
immutable `PanelAgentWorkflowJob`, not execution authority. Its repository
reference is converted to a one-way SHA-256 tag; its fingerprint commits the
exact plan, scope, resolver configuration, expiry, queue, display name, and
attempt limit. The record never contains the signed plan or approval intents,
confirmation evidence, raw idempotency key, raw identity, or resolver callback.

The host keeps that sensitive material in an application-owned secure
repository and resolves it only after a worker proves a live fenced operation
lease. A callback adapter is provided for that boundary:

```php
use Dataphyre\Panel\PanelAgentCallbackWorkflowJobResolver;
use Dataphyre\Panel\PanelAgentDeferredExecution;
use Dataphyre\Panel\PanelAgentWorkflowOperationBridge;

$deferred=new PanelAgentDeferredExecution(
    plan: $plan,
    planIntent: $planIntent,
    context: $trustedContext,
    approvalIntents: $approvalIntents,
    idempotencyKey: $privateIdempotencyKey,
    expectedStoreRevision: $workflowStore->revision(),
    confirmationEvidence: $confirmationEvidence,
    expiresAt: $planIntentExpiresAt,
);

$resolver=new PanelAgentCallbackWorkflowJobResolver(
    fingerprint: hash('sha256', $stableSecureRepositoryConfiguration),
    resolver: function ($job, $worker) use ($secureRepository, $workflowStore) {
        $material=$secureRepository->get($job->reference(), $worker);

        // Rebuild the runtime-only value with the current optimistic revision.
        // The queued fingerprint intentionally does not commit this revision.
        return new PanelAgentDeferredExecution(
            plan: $material->plan(),
            planIntent: $material->planIntent(),
            context: $material->context(),
            approvalIntents: $material->approvalIntents(),
            idempotencyKey: $material->idempotencyKey(),
            expectedStoreRevision: $workflowStore->revision(),
            confirmationEvidence: $material->confirmationEvidence(),
            expiresAt: $material->expiresAt(),
        );
    },
);

$bridge=new PanelAgentWorkflowOperationBridge($runtime, $resolver);
$bridge->register($distributedOperationHandlers);

$job=$bridge->job(
    reference: $privateRepositoryReference,
    execution: $deferred,
    options: [
        'queue'=>'agent_workflows',
        'name'=>'Deferred agent workflow', // never place private data here
        'max_attempts'=>3,
    ],
);

// Index secure material by the one-way tag, then persist only the safe job.
$secureRepository->put($job->reference(), $deferred);
$operation=$bridge->submit($distributedOperationRunner, $job);
```

`PanelAgentDeferredExecution` is deliberately immutable and supplies no generic
persistence codec. Reconstruct it from protected material at resolve time and
never serialize this object through the operation queue.

Operation delivery is at least once. Agent execution converges exactly through
the agent store's scope/plan/idempotency reservation. If the tool effect and
agent receipt complete but the operation lease expires before acknowledgement,
the next fenced worker resolves the same authority with the current store
revision and receives `idempotent_replay` without invoking the executor again.
Copied payloads, changed queue envelopes, stale resolver fingerprints, expired
jobs, mismatched material, and stale operation owners fail closed. Resolver and
runtime exception text is replaced with stable generic operation errors.

This bridge does not install a daemon, scheduler, broker, route, model client,
secure repository, or remote queue adapter. The host must operate workers that
call `PanelLeasedOperationRunner::work()`, retain sensitive material only until
the signed expiry, and remove it after terminal completion or cancellation.

## Tool declaration

`PanelAgentTool` contains only bounded descriptive data. An executor is registered separately and is omitted from public manifests.

```php
use Dataphyre\Panel\PanelAgentTool;

$tool=new PanelAgentTool(
    name: 'orders.release',
    version: '2026.7',
    description: 'Release one validated order to fulfillment.',
    permission: 'orders.release',
    risk: 'critical',
    dryRunSupported: true,
    confirmationRequired: true,
    approvalCount: 2,
    separationOfDuties: true,
    inputSchema: [
        'type'=>'object',
        'required'=>['order_id'],
        'additionalProperties'=>false,
        'properties'=>[
            'order_id'=>[
                'type'=>'string',
                'minLength'=>3,
                'maxLength'=>64,
                'pattern'=>'/^ord-[0-9]+$/',
            ],
        ],
    ],
    outputByteLimit: 65536,
    errorByteLimit: 2048,
    metadata: ['owner'=>'fulfillment'],
);
```

Supported schema keywords are deliberately limited to `type`, `properties`, `required`, `additionalProperties`, `items`, `enum`, string lengths and patterns, numeric minimum/maximum, and array item limits. Unknown keywords, unknown object properties, coercion, objects, resources, non-finite numbers, excessive depth, excessive nodes, and excessive bytes fail closed.

Do not place credentials or raw prompts in tool metadata, schemas, arguments, plans, results, or audit details. Common secret-bearing keys are redacted as defense in depth, not as a substitute for data minimization.

## Host authorization

The policy resolver has separate decisions for execution and approval. A host
must not treat "authenticated" as "authorized".

```php
use Dataphyre\Panel\PanelAgentPlan;
use Dataphyre\Panel\PanelAgentPolicyDecision;
use Dataphyre\Panel\PanelAgentPolicyResolver;
use Dataphyre\Panel\PanelAgentRequestContext;
use Dataphyre\Panel\PanelAgentTool;

final class AppAgentPolicy implements PanelAgentPolicyResolver
{
    public function decide(
        PanelAgentRequestContext $context,
        PanelAgentTool $tool,
        array $arguments,
    ): PanelAgentPolicyDecision {
        return $this->hostPermissionsAllow($context, $tool->permission(), $arguments)
            ? PanelAgentPolicyDecision::allow('Host permission policy allowed this tool.')
            : PanelAgentPolicyDecision::deny('Host permission policy denied this tool.');
    }

    public function approve(
        PanelAgentRequestContext $approver,
        PanelAgentPlan $plan,
    ): PanelAgentPolicyDecision {
        return $this->hostPermissionsAllowApproval($approver, $plan)
            ? PanelAgentPolicyDecision::allow('Host approval policy allowed this principal.')
            : PanelAgentPolicyDecision::deny('Host approval policy denied this principal.');
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->stablePolicyVersionAndConfiguration());
    }

    private function hostPermissionsAllow(
        PanelAgentRequestContext $context,
        string $permission,
        array $arguments,
    ): bool {
        return false;
    }

    private function hostPermissionsAllowApproval(
        PanelAgentRequestContext $approver,
        PanelAgentPlan $plan,
    ): bool {
        return false;
    }

    private function stablePolicyVersionAndConfiguration(): string
    {
        return 'app-agent-policy-v1';
    }
}
```

The built-in policy engine is default-deny without a resolver. Resolver exceptions fail closed. Risk floors cannot be weakened by a host decision:

| Risk | Minimum confirmation | Minimum approvals | Separation of duties |
| --- | --- | --- | --- |
| low | tool/policy choice | tool/policy choice | tool/policy choice |
| medium | required | tool/policy choice | tool/policy choice |
| high | required | one | tool/policy choice |
| critical | required | two | required |

An approver must be separately authorized, belong to the same panel and tenant, and be distinct from the requester when separation of duties applies. Two approvals must have distinct subject fingerprints.

`true` is not confirmation evidence. For every plan that requires confirmation, the runtime requires an installed `PanelAgentConfirmationVerifier` and opaque evidence. The verifier must validate a direct authenticated human gesture, expiry, purpose, exact plan hash, and exact tenant/principal/session context. Its stable configuration fingerprint is read once when the runtime is constructed, included in the immutable plan and signed intent, and rechecked at execution. Missing, forged, cross-plan, cross-scope, throwing, or stale-verifier confirmation fails closed. Raw evidence is never placed in plans, tool requests, results, receipts, or manifests.

## Catalog and executors

`PanelAgentToolCatalog` supports `deny`, `replace`, and `priority` collision policies. `deny` is the recommended default. Every registration records a bounded contributor name and priority. Hidden tools remain addressable only to trusted internal catalog callers and are omitted from discovery and rejected from model proposals.

```php
use Dataphyre\Panel\PanelAgentToolCatalog;

$catalog=new PanelAgentToolCatalog('deny');
$catalog->register($tool, $executor, contributor: 'app.orders');
```

Public catalog serialization contains tool manifests, active provenance, revision, and fingerprints. It contains no executor, callback, class name, credential, or hidden tool declaration. Catalog checkpoints are self-contained, integrity-digested, instance-bound, trusted in-process snapshots. They retain typed tool and executor object references so long-lived plugin rollback baselines do not expire after later checkpoints. They are strictly bounded and validated before mutation, but they are not serialization or persistence payloads and cannot be moved to another catalog instance.

`PanelAgentToolExecutor::execute()` receives only a verified `PanelAgentToolExecutionRequest`. Executors must:

- honor `dryRun()` without external side effects;
- pass `idempotencyKey()` to the real downstream atomic operation;
- apply their own downstream timeout and cancellation signal;
- poll `cancellationRequested()` and honor `deadlineAt()` during long operations; both use the runtime's clock domain;
- return JSON-only data through `PanelAgentToolExecutionResult`;
- return generic errors without credentials, class names, stack traces, or raw prompts; and
- keep downstream authorization in place.

`PanelAgentToolExecutorConformance` publishes the required scenarios and performs pure result-shape inspection without invoking a tool.

## Preparing, approving, and executing

The proposal accepted by `prepare()` has exactly two top-level fields:

```php
$proposal=[
    'title'=>'Release approved order',
    'steps'=>[
        [
            'tool'=>'orders.release',
            'arguments'=>['order_id'=>'ord-1001'],
            'dry_run'=>false,
        ],
    ],
];
```

Lifecycle:

1. The host authenticates the request and constructs `PanelAgentRequestContext` from trusted server-side identity.
2. `prepare()` validates the exact proposal, normalizes every argument, resolves only visible catalog tools, evaluates host policy, stamps requirements, signs the plan, and appends a `plan_validated` receipt.
3. Each human approval calls `approve()` with the original plan intent, execution context, authenticated approver context, and current store revision. Panel revalidates the plan, scope, separation rules, and host approval policy before issuing a parent-bound approval intent and receipt.
4. `execute()` verifies current catalog, policy, and confirmation-verifier fingerprints, exact plan and tool fingerprints, scope, host-authenticated confirmation evidence, approval count, approval uniqueness, signatures, expiry, and nonce parentage.
5. The store atomically checks optimistic revision, cancellation, idempotency, and nonce replay before reserving execution. Before each executor call, Panel atomically renews the same fenced reservation for at least the step deadline plus completion grace.
6. Panel executes normalized steps in order, checking the kill switch, step deadline, and cancellation before and after every executor call and between steps. It stops at the first failure. A success returned after cancellation is recorded as cancelled rather than committed as success.
7. Bounded redacted results and an audit receipt are completed atomically in the store.
8. Repeating the same scope, plan, and idempotency key returns the stored result without invoking the executor while the lifecycle evidence remains valid. The authenticated `result()` recovery method retrieves a completed scope/plan-bound result without requiring an unexpired bearer intent or confirmation evidence. Reusing the key for a different request, or reusing an intent with another key, fails closed.

The store revision returned by every envelope/result must be supplied to the next new mutation. A stale revision returns `revision_conflict`. The sole exception is an already completed, scope-bound idempotent replay: the store resolves the matching request before requiring a current revision, while mismatched and in-progress keys still fail. Callers must reload state instead of silently retrying any other mutation.

## Signed intents and key rotation

`PanelAgentIntentSigner` uses HS256 with explicit key IDs. Plan and approval audiences differ. Exact claims bind:

- audience and format version;
- plan, catalog, and policy fingerprints;
- confirmation-verifier fingerprint (or an explicit empty claim when no verifier is attached);
- tenant/principal/session scope fingerprint;
- subject fingerprint;
- issued-at, expiry, and random nonce; and
- approval parent nonce.

Use an object-like key map containing one to eight uniquely normalized key IDs and at least 32 random bytes per key. Keep the current signing key and only the retained verification keys needed for the maximum 15-minute intent lifetime. Rotate by constructing a new signer with a new current key ID and retained old keys. Remove a compromised key immediately; intents signed by it will then fail verification. Key material is never serialized.

Signed intents are bearer authorizations. Transport them only through authenticated, same-origin, CSRF-protected requests and never log them. Panel's JSON representation intentionally contains the token because clients must carry it; the host remains responsible for log redaction and secure transport.

## Existing Automation actions

`PanelAgentAutomationToolExecutor` bridges an explicitly named `AutomationExecutor` action. The bridge preserves Automation schema validation, policy, confirmation, idempotency, execution receipts, and failures. It does not register actions, add routes, synthesize confirmation, or bypass Automation policy.

If an Automation action requires a phrase, configure that exact phrase in the bridge and still require Panel agent confirmation. Host permissions supplied to the bridge are an additional Automation actor input; they do not replace the independent `PanelAgentPolicyResolver` decision.

## Cancellation and kill switch

`cancel()` verifies the signed plan and exact requester scope, then records cancellation optimistically. Cancellation remains available when catalog or policy revisions have changed, including after the kill switch is engaged. Execution checks cancellation and the kill switch before every step.

This boundary cannot forcibly preempt PHP already running inside one executor call. Long-running adapters must poll the request's cooperative cancellation/deadline signal. Panel checks again after the call and fails closed if the adapter ignored cancellation. The reference store permits an expired pending lease to be reclaimed only by the same scope, plan, request, idempotency key, and original nonce set. Renewal advances a fencing revision. The current fenced owner may still atomically finalize after its wall-clock expiry when no claimant has replaced it, which preserves the failure receipt for a late-returning executor. A reclaimed owner cannot finalize; every executor must therefore pass the deterministic downstream step idempotency key to prevent duplicate effects. A production durable store must implement equivalent atomic fencing, renewal, ownership, and recovery.

## Audit and persistence

Every accepted lifecycle mutation produces a `PanelAgentAuditReceipt` with sequence, event, scope/actor fingerprints, plan hash, stable code, redacted bounded details, previous hash, timestamp, and current hash. Stores reject gaps, forks, and invalid previous hashes.

Hash chaining detects modification or deletion when an external trusted head is retained; it is not a digital signature and does not prevent a database administrator from rewriting an entire chain and its head. Production deployments should export or anchor audit heads in a separately controlled system.

A production `PanelAgentWorkflowStore` implementation must make these operations atomic:

- optimistic revision comparison and append;
- idempotency lookup and reservation;
- cancellation check and nonce consumption;
- fenced lease renewal before each step;
- execution completion and receipt append; and
- uniqueness of the scope-bound idempotency hash;
- lease expiry/reclaim ownership without releasing consumed nonces to another request; and
- bounded retention or explicit capacity backpressure that never silently removes live replay/audit guarantees.

Do not use the in-memory store as evidence of durability.

## HTTP integration checklist

Panel deliberately installs no agent route. A host controller should, in order:

1. enforce method, content type, body size, authentication, tenant, direct-session policy, CSRF, origin, and rate limit;
2. construct context only from trusted server-side identity, never request body identity fields;
3. decode JSON with a bounded depth and reject duplicate object keys at the transport layer if the decoder permits them;
4. call exactly one runtime lifecycle method;
5. translate `PanelAgentException::errorCode()` and `httpStatus()` to a generic response;
6. omit exception traces and internal classes; and
7. prevent signed intents, idempotency keys, arguments, and results from entering access logs.

## Negative guarantees and limitations

- No model provider, prompt builder, prompt persistence, autonomous loop, or arbitrary output interpreter is included.
- No route, controller, authentication, CSRF, origin, or rate-limit middleware is auto-installed.
- No identity, permission, tenant, or approver authority is inferred.
- No executor, callback, class, signing secret, raw tenant/principal/session identifier, hidden tool, or raw prompt is included in public manifests.
- No daemon, scheduler, broker, or secure execution-material repository is included. The optional operation bridge uses Panel's leased runner and may be backed by the explicit-migration PDO leased-operation store, but the host must provision its database and run and supervise every worker.
- No running executor can be forcibly interrupted by portable PHP; adapters need cooperative cancellation and timeouts.
- HMAC intents require all verifying processes to protect shared symmetric keys.
- Redaction is defense in depth. The primary control is to keep secrets and raw prompts outside the subsystem.

## Attach the exact graph to one Panel surface

Agent-safe workflows are not part of `PanelPlatform::defaults()`. Construct the
security boundary explicitly, register every named service from the same object
graph, and then attach it to the intended surface:

```php
use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelAgentIntentSigner;
use Dataphyre\Panel\PanelAgentPolicyEngine;
use Dataphyre\Panel\PanelAgentRuntime;
use Dataphyre\Panel\PanelAgentToolCatalog;
use Dataphyre\Panel\PanelPlatform;

$catalog=new PanelAgentToolCatalog('deny');
$policy=new PanelAgentPolicyEngine($hostPolicyResolver);
$signer=new PanelAgentIntentSigner(
    ['2026-07'=>$currentAgentSigningKey, '2026-06'=>$retainedAgentSigningKey],
    '2026-07'
);

// $workflowStore must be a durable atomic implementation in production.
$runtime=new PanelAgentRuntime(
    $catalog,
    $policy,
    $signer,
    $workflowStore,
    confirmationVerifier: $hostConfirmationVerifier,
);

$platform=PanelPlatform::make()
    ->register('agents.catalog', $catalog)
    ->register('agents.policy', $policy)
    ->register('agents.signer', $signer)
    ->register('agents.store', $workflowStore)
    ->register('agents.runtime', $runtime);

$panel=Panel::make('operations')->usePlatform($platform);

if (!$panel->hasAgentWorkflows()) {
    throw new RuntimeException('The agent workflow graph is incomplete.');
}

$panel->registerAgentTool($tool, $executor);
$agentRuntime=$panel->agentRuntime();
$publicContract=$panel->agentWorkflowManifest();
```

The five names are one dependency graph, not interchangeable service aliases.
The runtime's `catalog()`, `policy()`, `signer()`, and `store()` must return the
exact objects registered separately. A same-type replacement creates a
split-brain graph and is rejected by `PanelPlatformManifest`,
`hasAgentWorkflows()`, and the surface manifest. Unresolved lazy factories are
reported as pending and are not invoked by manifest generation.

`agentWorkflowManifest().attachment.configured=true` means only that the typed
graph is resolved and identity-cohesive. It does not prove that a model, route,
identity boundary, CSRF/origin policy, confirmation ceremony, durable store,
worker, executor timeout, or downstream authorization is operational. Public
manifests never invoke executors, live policy decisions, or confirmation
verification. The sealed runtime snapshot reads the store's current revision;
production stores must keep `revision()` bounded, side-effect free, and free of
identity or secret material.

## Plugin contributions and transactional rollback

Register tools through `PanelInstance::registerAgentTool()` inside a plugin so
the active plugin id becomes catalog provenance automatically:

```php
final class FulfillmentAgentPlugin implements \Dataphyre\Panel\PanelPlugin
{
    public function __construct(
        private readonly \Dataphyre\Panel\PanelAgentTool $tool,
        private readonly \Dataphyre\Panel\PanelAgentToolExecutor $executor,
    ) {}

    public function id(): string { return 'fulfillment-agent-tools'; }

    public function register(\Dataphyre\Panel\PanelInstance $panel): void
    {
        $panel->registerAgentTool($this->tool, $this->executor, priority: 20);
    }

    public function boot(\Dataphyre\Panel\PanelInstance $panel): void {}
}
```

Plugin register, boot, unload, and reload operate against one surface/platform
checkpoint. A failure restores the catalog layers, active executor references,
checkpoint-aware policy/store state, platform services and factories, platform
revision, and the other checkpoint-aware surface registries. A singleton
factory resolved during the failed transaction returns to its prior factory
state. Checkpoints are bounded, integrity-checked, instance-bound, trusted
in-process objects; they are not serialization or persistence formats.

Rollback cannot undo arbitrary external effects or mutations inside a service
that does not implement `PanelCheckpointableService`. Plugin registration and
boot callbacks should therefore declare framework contributions only. Make any
unavoidable external work independently idempotent and compensatable.

## Migration and compatibility notes

- Direct construction and use of `PanelAgentRuntime` remains supported.
  Registering that same graph under the five `agents.*` names adds scoped facade
  access and truthful manifests without installing routes or a model client.
- A boolean, checkbox value, or model assertion is not confirmation evidence.
  Plans whose effective policy requires confirmation now need a host-owned
  `PanelAgentConfirmationVerifier`; migrate the authenticated human gesture and
  exact plan/scope binding into that verifier.
- Existing Automation actions remain supported. Wrapping one with
  `PanelAgentAutomationToolExecutor` adds an independent agent policy and intent
  boundary; it does not replace Automation policy, confirmation, or receipts.
- Catalog, policy, or confirmation-verifier fingerprint changes intentionally
  invalidate outstanding plans. Re-prepare instead of weakening stale-plan
  validation during deployments.
- `InMemoryPanelAgentWorkflowStore` remains a local reference implementation,
  not a production migration target. Use `PanelAtomicAgentWorkflowStore` for a
  correctly operated single-host/cross-process filesystem deployment or
  `PanelPdoAgentWorkflowStore` for a host-operated MySQL, PostgreSQL, or SQLite
  deployment. Any other multi-node adapter must preserve the same revision,
  idempotency, nonce, fencing, cancellation, completion, and audit semantics and
  pass `PanelAdapterConformanceCatalog::agentWorkflowStore()`.
- No agent-safe workflow PHP symbol is marked deprecated in this release.
