# Dataphyre Panel Operations OS

Panel Operations OS is the opt-in, governed execution layer that sits above
Panel's builders and production platform services. It turns portable domain
manifests into signed runtime artifacts and binds work, policy, AI operators,
semantics, lineage, compliance, fleet state, releases, local-first sync,
marketplace review, and Studio branches to one explicit trust root.

It is not a second application framework, an ORM, an identity provider, a
model host, or a documentation engine. Hosts keep those deployment choices.
Datadoc remains the universal documentation engine for Dataphyre.

## Capability Map

| Layer | Public entry point | Contract |
| --- | --- | --- |
| Domain as code | `PanelDomainCompiler` | Deterministic compilation, diagnostics, signed artifacts, immutable version history, and migration-aware diffs. |
| Universal work graph | `PanelWorkGraph` | Durable queues, assignments, states, SLA, relationships, comments, replay, optimistic concurrency, idempotency, and real undo. |
| Unified policy | `PanelPolicyControlPlane` | Portable callback-free rules, signed bundles, deny-overrides, obligations, checkpoints, and kill switches. |
| Governed AI operators | `PanelOperatorRuntime` | Model routing, structured proposals, evaluation, independent approvals, confirmation, policy revalidation, and sanitized evidence. |
| Semantic operations | `PanelSemanticCatalog`, `PanelLineageGraph` | Shared metrics, field-level lineage, impact analysis, process intelligence, and counterfactual simulation. |
| Continuous compliance | `PanelComplianceLedger`, `PanelComplianceAutomation` | Typed host collectors, source-bound reference profiles, fingerprint-pinned plans, freshness, failure isolation, signed runs, crosswalk-aware evidence coverage and drift, signed hash-chained evidence, evidence packs, legal holds, and hold-aware retention. |
| Fleet federation | `PanelFederationControlPlane` | Signed expiring attestations, anti-replay, desired-state drift, capability quorum, and portable checkpoints. |
| Release control | `PanelReleaseControlPlane` | Signed artifacts, SBOM/provenance binding, rings, health gates, promotion, rollback, pause, and deterministic flags. |
| Local-first work | `PanelLocalReplica` | Field-register CRDT documents, vector clocks, tombstones, signed envelopes, deterministic conflict evidence, and replay guards. |
| Marketplace governance | `PanelMarketplaceGovernance` | Trust, SBOM, provenance, compatibility, vulnerability, permission, quarantine, approval, and least-privilege activation gates. |
| Studio delivery | `PanelStudioBranchManager` | Optimistic branches and commits, path-level three-way conflicts, independent review, merge strategies, and checkpoints. |
| Operations console | `PanelOperationsConsole`, `PanelPlatformTemplate::operationsOs()` | Bounded tenant-scoped read model, redacted journals and event streams, attention routing, crash recovery, projector controls, and policy-gated command dispatch. |

`PanelOperationsOs` is the composition root. `PanelPlatform` registers each
typed component as a service but its manifest refuses to report the domain as
ready if a same-type service belongs to a different runtime graph.

## Configuration

Operations OS is disabled unless `operations_os` is explicitly present.
An explicit master key of at least 32 bytes is mandatory. The runtime derives
domain-separated keys for policy, domain compilation, releases, compliance,
federation, sync, and approvals. A public manifest exposes key identifiers, but
never key material or raw configuration.

```php
use Dataphyre\Panel\PanelPlatform;

$platform=PanelPlatform::defaults([
	'state_root'=>__DIR__.'/var/panel',
	'operations_os'=>[
		'master_key'=>$secretFromTheHost,
		'key_id'=>'primary',
		'policy_bundles'=>[[
			'id'=>'operations',
			'version'=>'1.0.0',
			'rules'=>[
				'operators'=>[
					'effect'=>'allow',
					'abilities'=>['work.*', 'sync.*'],
					'when'=>['path'=>'actor.roles', 'op'=>'contains', 'value'=>'operator'],
				],
			],
		]],
		'domains'=>[$ordersDomain],
		'metrics'=>[
			'operations.backlog'=>[
				'entity'=>'work_item',
				'aggregation'=>'count',
				'dimensions'=>['queue', 'state'],
			],
		],
	],
	'authentication'=>false,
	'media'=>false,
]);

$os=$platform->operationsOs();
```

Each purpose can use a host-managed rotating keyring instead of the derived
default:

```php
'operations_os'=>[
	'master_key'=>$master,
	'domain_keys'=>['2026_q2'=>$old, '2026_q3'=>$current],
	'domain_key_id'=>'2026_q3',
	'sync_keys'=>['2026_q3'=>$syncCurrent],
	'sync_key_id'=>'2026_q3',
]
```

The same pattern is available through `policy_keys`, `release_keys`,
`compliance_keys`, `federation_keys`, and `approval_keys`. A configured current
identifier must exist in its keyring; startup fails closed otherwise.

## Domain as Code

A domain manifest declares entities, fields, relationships, policies,
commands, workflows, metrics, queues, surfaces, and bounded agent authority.
Compilation emits data-only resource, Studio, work-graph, workflow, command,
policy, semantic, lineage, and agent artifacts. It does not evaluate generated
PHP.

```php
$orders=[
	'id'=>'orders',
	'version'=>'1.0.0',
	'entities'=>[
		'order'=>[
			'primary_key'=>'id',
			'states'=>['open', 'review', 'closed'],
			'fields'=>[
				'id'=>['type'=>'uuid', 'required'=>true, 'immutable'=>true],
				'status'=>['type'=>'enum', 'enum'=>['open', 'review', 'closed']],
				'total'=>['type'=>'money', 'classification'=>'confidential'],
			],
		],
	],
	'commands'=>[
		'review'=>[
			'entity'=>'order',
			'operation'=>'review',
			'risk'=>'high',
			'reversible'=>true,
			'approval'=>1,
		],
	],
	'metrics'=>[
		'order_value'=>[
			'entity'=>'order',
			'aggregation'=>'sum',
			'field'=>'total',
			'dimensions'=>['status'],
		],
	],
];

$v1=$os->installDomain($orders);
$os->verifyCompilation($v1);                         // true
$os->compilationAt('orders', '1.0.0');
$os->compilationHistory('orders');
```

An installed domain version is immutable. Reinstalling identical source is
idempotent; reusing the same version for different source is rejected. New
versions retain their signed history and can be compared directly:

```php
$os->installDomain($ordersV2);
$diff=$os->diffDomainVersions('orders', '1.0.0', '2.0.0');

$diff->changed();
$diff->breaking();
$diff->migrationSteps();
```

The active domain's metric and lineage namespaces are replaced together only
after the next manifest has compiled and validated. A failed compilation does
not remove the active semantic definitions.

## Work, Policy, and Operators

The work graph is the common execution vocabulary for records, cases, tickets,
approvals, incidents, and background operations. Every mutation requires an
actor, tenant scope, idempotency key, and—after creation—an expected version.
Events form a tamper-evident timeline and reversible events carry enough prior
state to perform a real compensating undo.

```php
$receipt=$os->workGraph()->create(
	'Tenant:1',
	[
		'id'=>'case:42',
		'type'=>'risk_review',
		'title'=>'Review order SO-42',
		'queue'=>'risk',
		'priority'=>80,
		'subject'=>['type'=>'order', 'id'=>'SO-42'],
	],
	'Operator:7',
	'create-case-42',
);

$os->workGraph()->transition(
	'Tenant:1', 'case:42', 'review', 'Operator:7',
	$receipt->item()->version(), 'review-case-42',
);
```

Policy rules are portable data. Deny rules override allows. An absent allow,
untrusted bundle, failed condition, evaluator failure, stale policy revision,
model drift, missing approval, or missing confirmation leaves an operator run
non-executable.

Operator adapters receive only a typed task, selected model descriptor, and
bounded tool manifest. Model output is parsed into a `PanelOperatorProposal`;
raw callbacks or model prose cannot directly mutate application state. Tool
arguments reject secret-shaped keys, evidence is sanitized, and the runtime
rechecks policy and model identity immediately before execution.

## Semantics, Lineage, and Process Intelligence

Semantic metrics support `count`, `sum`, `average`, `minimum`, `maximum`,
`distinct_count`, and `ratio`. Query dimensions must have been declared by the
metric. The same definitions can feed tables, dashboards, APIs, and operators.

Lineage nodes cover fields, entities, metrics, commands, policies, and
surfaces. Derivation edges are cycle guarded. `upstream()`, `downstream()`, and
`impact()` return deterministic bounded traversals suitable for change review.

`PanelProcessIntelligence` consumes work events to discover variants,
transition counts, average/p95/maximum transition time, bottlenecks, and
conformance violations. Microsecond event precision is preserved.

`PanelCounterfactualLab` runs a host-supplied side-effect-free simulator with
deterministic scenario/run seeds. It reports objective ranges and deltas and
ranks interventions without claiming that a simulation performed a mutation.

## Compliance, Federation, and Releases

Compliance controls and evidence are stored atomically. Evidence entries use a
rotating-key HMAC hash chain. Retention advances the chain anchor, while active
global or control-specific legal holds prevent covered evidence from being
pruned. Evidence packs are signed point-in-time exports and reject unknown
control identifiers.

The optional collector graph is composed by default inside
`PanelOperationsOs::fromConfig()`. `PanelComplianceCollectorRegistry` pins every
host collector to an implementation fingerprint and contributor.
`PanelComplianceFrameworkCatalog` includes reference-only NIST CSF 2.0, GDPR,
HIPAA Security Rule, and PCI DSS 4.0.1 profiles plus host-defined or licensed
packs. Collection plans bind exact pack, control, collector, subject, input,
freshness, and deadline commitments. Dependency drift fails closed. Collector
exceptions and budget overruns remain isolated `error` observations and cannot
be upgraded to positive evidence.

Each completed collection is signed as a `PanelComplianceCollectionRun` using
the ledger trust root. `PanelComplianceCoverageReport` distinguishes current,
stale, missing, negative, indeterminate, and errored evidence and compares that
state with a prior report. Its coverage values are evidence inventory only, not
a compliance score or certification. See
[Dataphyre_Panel_Compliance_Automation.md](Dataphyre_Panel_Compliance_Automation.md)
for the trust boundary, official source links, collector examples, and host
responsibilities.

Federation accepts only signed attestations that are currently valid and have a
strictly newer per-node sequence. Desired-state reconciliation distinguishes an
online convergence action from an expired node waiting for a heartbeat.
`checkpoint()` and `restore()` preserve desired state and trusted attestations;
the host owns durable checkpoint transport.

Release artifacts bind code/domain/policy digests, SBOM components, provenance,
creation time, and signing key. Rings are ordered and can require numeric
health gates. Promotion requires an active deployment in the source ring and
authorizes both the promotion and destination deployment. Deploy and rollback
idempotency keys reject payload reuse and accurately report replayed receipts.

`PanelReleaseExecutionEngine` durably drives prepare, activate, verify, and
automatic rollback behind fenced leases. The first-party declarative adapter
provides signed target profiles for Kubernetes, Nomad, ECS, Compose, and
filesystem promotion. Requests and receipts are bound to the artifact,
operation-key hash, phase, target, and live fence; credentials and transports
remain host-owned. See
[Dataphyre_Panel_Release_Deployment.md](Dataphyre_Panel_Release_Deployment.md).

## Local-First Sync

Each replica owns an actor-scoped vector clock. Changes update field registers;
deletes create tombstones. Concurrent values retain bounded conflict evidence
and converge through a deterministic last-writer register ordering. Signed
envelopes are source/sequence bound and replay protected.

```php
$laptop=$os->replica('Operator:Laptop');
$laptop->change('Order:42', [
	['op'=>'set', 'path'=>'owner.id', 'value'=>'Operator:7'],
	['op'=>'set', 'path'=>'status', 'value'=>'review'],
]);

$batch=$laptop->envelope();
$server->merge($batch);
```

`checkpoint()` persists local documents and received cursors. The host decides
where that checkpoint is encrypted and stored.

## Marketplace and Studio Governance

Marketplace review combines the existing package manifest and trust report
with submission SBOM, provenance, compatibility, permissions, vulnerabilities,
and a network allowlist. Critical findings reject; accumulated high risk
quarantines; only a clean candidate can receive the configured independent
approval quorum. Activation returns a digest-bound least-privilege sandbox
contract with no process execution, filesystem roots, or secret access.

An optional `PanelPackageMarketplaceTrustNetwork` adds signed Merkle-tree
checkpoints, consistency and witness verification, durable contiguous event
ingestion, scoped revocation, and publisher-evidence profiles. Governance must
share the network's exact registries and rechecks both at review and activation.
See [Dataphyre Panel Marketplace Trust](Dataphyre_Panel_Marketplace_Trust.md).

Studio branches sit above Studio's durable revision system. Commits use an
expected head hash. Three-way merges return path-level digest evidence and can
remain unresolved (`manual`) or select the target/source side (`ours` or
`theirs`). The merge actor cannot satisfy the independent reviewer quorum.

## Operations Console

`PanelOperationsConsole` is the first-party operator read model for the whole
Operations OS graph. It is not a raw serializer. Every section is projected
through an explicit allowlist and captured independently, so one unavailable
subsystem produces a stable section error and attention signal instead of
taking down the control room.

The console exposes bounded metadata for runtime health, tenant work, command
receipts, signed event headers, subscribers, active domain drift, policy,
governed models, semantic and lineage counts, compliance-chain state,
federation nodes, release rings, and marketplace review. It never exposes
command inputs, idempotency keys, work data, event payloads, evidence contents,
credentials, signatures, or lease/fencing tokens. Actor identifiers in work,
command, and event history are one-way hashed.

`PanelPlatform::defaults()` registers the console as
`operations_os.console` when Operations OS is enabled. The console must wrap
the exact `PanelOperationsOs` instance owned by the platform; the platform
manifest fails cohesion with `operations_os.console.runtime` if a split graph
is injected.

```php
$platform=PanelPlatform::defaults([
	'state_root'=>__DIR__.'/var/panel',
	'operations_os'=>[
		'master_key'=>$master,
		'console_maximum_limit'=>100,
	],
	'platform'=>[
		'authorize'=>$hostAuthorizer,
		'csrf'=>$hostCsrfValidator,
	],
]);

$console=$platform->operationsConsole();
$snapshot=$console->snapshot('Tenant:1', [
	'limit'=>25,
	'event_cursor'=>0,
	'work_cursor'=>0,
	'work'=>['queue'=>'risk', 'overdue'=>true],
]);

$page=$platform->operationsOsPage([
	'action_url'=>'/admin/operations-os',
], $request);
```

The opt-in page catalogue includes `platform_operations_os` when the domain is
ready. It renders on `GET`/`HEAD` and delegates `POST` to the same controller,
but Panel still installs no route. A host may mount that page or call
`PanelPlatformController::operationsOs()` and
`PanelPlatformController::operateOperationsOs()` from its own authenticated
route.

Reads require `operations_os.console.view`. Mutations require one of:

- `operations_os.console.dispatch`
- `operations_os.console.recover_stale`
- `operations_os.console.drain_subscriber`

Every mutation passes the controller's independent authentication,
authorization, CSRF, and security-audit boundary. A dispatched command is then
re-evaluated by the portable Operations OS policy fabric. Actor identity,
roles, permissions, MFA level, and tenant ownership come from trusted
`PanelSecurityContext`; request input cannot replace them. If the request has
no explicit tenant, an authenticated tenant context is inherited. A supplied
command tenant must match both the request and authenticated tenant boundary.

Generic dispatch allows only `operations_os.*` by default. A host can widen
the namespace deliberately with the controller option
`allowed_commands`, for example `['operations_os.*', 'orders.*']`. Command,
ability, tenant, and idempotency key are mandatory. Safe receipt summaries
contain status, replay state, event count, completion time, stable error code,
and matched handler pattern only.

Operations OS registers an ability-bound `operations_os.*` handler by default:

| Command | Required command ability |
| --- | --- |
| `operations_os.policy.engage` | `operations_os.policy.engage` |
| `operations_os.policy.release` | `operations_os.policy.release` |
| `operations_os.release.pause` | `release.ring.pause` |
| `operations_os.release.rollback` | `release.rollback` |
| `operations_os.federation.desired` | `operations_os.federation.desired` |

The handler emits signed events through the command fabric and rejects an
ability mismatch. Set `register_operations_os_control_handler` to `false` only
when the host deliberately supplies its own equivalent wildcard handler.

The SSR template is a dense, line-based control room rather than a nested-card
dashboard. Data tables reflow into labelled rows when the actual page container
is 52rem or narrower, anchor controls wrap, touch targets remain at least 44px,
and the stylesheet includes RTL, reduced-motion, forced-colors, light, and dark
contracts. The deterministic browser showroom route
`/panel?dp_panel_operations_console=1` exercises every table and control shape.

## Persistence and Host Boundaries

| State | Built-in durability | Host responsibility |
| --- | --- | --- |
| Command fabric | Atomic filesystem store by default; explicit-migration `PanelPdoCommandFabricStore` for MySQL/PostgreSQL/SQLite. | Connection and schema lifecycle, worker supervision, retention, downstream idempotency, and non-SQL broker adapters. |
| Work graph | Atomic filesystem store by default; custom `PanelWorkGraphStore` accepted. | Shared database/broker adapter for multi-host topology. |
| Policy | Signed bundles and portable checkpoint. | Bundle distribution, key custody, and checkpoint storage. |
| Compliance | Atomic snapshots and signed evidence chain. | Backup, retention policy, legal process, and external archive. |
| Releases | Atomic snapshots, trusted artifact rehydration, durable fenced release execution, and signed declarative Kubernetes/Nomad/ECS/Compose/filesystem adapters. | Credentials, artifact/provider transport, deployment worker, provider-side stale-fence rejection, health semantics, telemetry, and disaster recovery. |
| Federation | Signed portable checkpoint. | Durable checkpoint transport and node heartbeat delivery. |
| Local replicas | Portable checkpoint. | Device encryption, transport, and identity binding. |
| Marketplace | Atomic local transparency projection, trusted checkpoints, scoped revocation, publisher evidence, and in-process governed review state. | Registry and remote-log transport, trust anchors and key custody, witness operation, complete event delivery, monitored freshness, and durable publication workflow. |
| Studio branches | Portable checkpoint above durable Studio revisions. | Checkpoint storage and deployment promotion. |

Panel never installs routes, databases, model credentials, schedulers, worker
daemons, package transports, deployment executors, or identity providers as a
side effect of enabling Operations OS.

## Verification

The focused Operations OS corpus covers domain compilation, work storage,
policy, operator routing/execution, semantics, lineage, process mining,
simulation, compliance retention and holds, federation, releases, CRDT sync,
marketplace governance, Studio branching, composition, platform cohesion, and
adversarial restoration. Its closed source inventory is certified with phpdbg
at exact line coverage; full Panel owner tests remain the integration gate.

Public documentation is Markdown in this module and can be published through
Panel's existing `PanelDocumentationPortal` adapter. Datadoc owns the universal
HTML shell, navigation, search, versions, browser assets, integrity policy, and
immutable publication protocol.
