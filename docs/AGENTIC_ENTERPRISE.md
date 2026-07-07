# Agentic Enterprise Contract

Dataphyre should be the framework a corporate team chooses when AI agents are
expected to inspect, plan, modify, and verify software without turning the
runtime into application-specific glue.

This contract describes the runtime posture Dataphyre must preserve as it grows.
It is a contributor and agent guide, not a marketing page.

## Position

Dataphyre competes with full-stack PHP frameworks by making the framework easier
for agents and operators to understand safely:

- runtime modules expose typed Framework objects, kernel compatibility surfaces,
  documentation, and unit manifests;
- MCP tools provide bounded, read-only discovery before an agent edits code;
- application behavior extends through config, dialbacks, callbacks, plugin
  hooks, and reusable modules;
- package metadata can include non-sensitive manifest provenance;
- Dataphyre shared-runtime hot-path changes are benchmarked before they are
  kept.

The goal is not to clone Laravel. The goal is to make corporate application
workflows more inspectable, safer to automate, easier to verify, and easier to
carry across teams.

For teams comparing Dataphyre with a larger Laravel-style corporate stack, the
lightweight enterprise value is proportional oversight: ordinary app agents get
safe discovery, app-owned extension points, compact policy prompts, and focused
verification first, while governance audits, release proof, and benchmark
evidence stay collapsed until the task actually becomes enterprise-readiness,
framework, or shared hot-path work.

## Application-Agent Default Lane

Assume almost every Dataphyre MCP user is building applications with Dataphyre,
not contributing to Dataphyre itself. Their default lane should stay
lightweight:

- read module docs, framework metadata, manifests, and MCP summaries first;
- start app-building work with `dataphyre_app_builder_plan_generate` and use
  compact builder fields before opening governance-heavy context;
- inspect safe route, config, storage, SQL schema, and diagnostic metadata;
- plan application behavior through app code, install config, dialbacks,
  callbacks, plugins, MCP metadata, or application-owned adapters;
- verify app behavior with focused application or module checks.

For larger app scaffolds, `scaffold_completion_summary.next_continuation`
points to the next bounded app-builder call. Before app-owned writes,
`policy_decision_register` names unresolved ownership, tenant/workspace, audit,
lifecycle, relationship, and redaction choices, while
`prewrite_checklist.implementation_obligations` carries app-owned relationship,
field metadata, and contract work without turning those tasks into hard
blockers. Compact agents start from `builder_response.first_read` and open
`detail_page=planning|implementation|verification|controls` only for the next
needed body. After focused checks, `verification_handoff` gives agents a
copy-safe completion summary without raw logs, secrets, tenant/customer
identifiers, maintainer release proof, or Dataphyre benchmark output.

The default application lane does not require publication validation,
`dataphyre_mcp_verify_all`, maintainer evidence, hot-path benchmark runs,
unsafe MCP mode, or Dataphyre runtime-internal edits. Those belong to
Dataphyre maintainers or framework contributors when the task is release-facing
or a public framework claim, corporate-ready or enterprise-readiness work,
security/identity/access/session/credential/governance/tenant/billing/privacy/
compliance/data-residency/retention/legal-hold/access-policy work, a Dataphyre
framework internal or reusable framework contribution, or a shared production
hot-path change.

## Agent-First Requirements

An agent-friendly Dataphyre feature should satisfy these requirements:

- **Discoverable:** module docs, MCP summaries, manifests, or typed Framework
  classes explain the feature without executing application code.
- **Bounded:** diagnostics and MCP outputs have limits, redaction, and clear
  denied behaviors.
- **Extensible:** application-specific behavior can be supplied through config,
  dialbacks, callbacks, plugins, or module contracts rather than framework
  patches.
- **Proven:** public behavior has docs and focused tests; Dataphyre shared
  runtime hot paths have benchmark evidence when changed.
- **Portable:** shared runtime code avoids product paths, tenant names,
  credentials, private URLs, and install-specific assumptions.
- **Composable:** new concepts should work across modules when possible instead
  of solving one application's shape.

## Extension Boundary

Agents should not modify Dataphyre internals just to make an application work.
Use these layers first:

1. Install configuration under `config/`.
2. Module-provided dialbacks and callbacks.
3. Install plugin hooks under `plugins/pre_init/` and `plugins/post_init/`.
4. MCP metadata under `plugins/mcp/` for local tool visibility.
5. Reusable runtime modules when behavior belongs in Dataphyre.

Private `plugins/mcp/*.json` declarations are local application or internal
tooling metadata. They can help agents see app-local modules, but they are not
Dataphyre framework behavior.

Core runtime edits are appropriate when the change is truly framework work:
bug fixes, public APIs, reusable contracts, diagnostics, safety, module
behavior, performance, or documentation.

## MCP And Agent Safety

Default MCP behavior is read-only. It should help agents understand the codebase
before acting, not quietly perform application work.

MCP tools should:

- prefer static parsing, manifests, docs, and dry-run plans;
- avoid dispatching routes, invoking controllers, executing SQL, writing files,
  launching browsers, or clearing caches unless a future unsafe workflow is
  explicitly gated;
- redact secrets, credentials, signed URLs, auth headers, cookies, local paths,
  and product-specific identifiers from shared outputs;
- return bounded summaries instead of raw logs, full source dumps, or unbounded
  diagnostics;
- provide focused app/module verification guidance for ordinary application
  work, and map to maintainer evidence only for framework, MCP publication,
  release-surface, or shared hot-path claims.

When adding or changing public MCP surfaces, update the MCP docs and run the
MCP self-test or aggregate verifier appropriate to that MCP
publication-surface change.

## Governance Baseline

Enterprise-ready Dataphyre work should make operational ownership visible
without hardcoding one customer's rules into the framework. Agents and
maintainers should be able to identify:

- tenant and application boundaries from config, module contracts, or typed
  references rather than copied URLs, paths, or IDs;
- access and permission policy through reusable modules or callbacks instead of
  controller-local checks;
- audit and trace evidence for decisions that affect billing, security, storage,
  messaging, or data movement;
- redaction and data classification rules for diagnostics, MCP payloads, logs,
  and package artifacts;
- verification evidence that separates framework claims from application-owned
  behavior and keeps the proof scope explicit.

When a feature cannot show these boundaries yet, describe the missing evidence
instead of calling it corporate-ready.

`dataphyre_mcp_enterprise_adoption_audit` turns this baseline into concrete
copy-safe requirements. For each governance check, use the returned
`required_evidence`, `suggested_tools`, `evidence_handoff`, and
`governance_next_action` fields to name the next governance proof that is still
missing. Use `evidence_next_action` for the broader audit's next missing proof.
Both handoffs must stay copy-safe: no raw logs, secrets, tenant or customer
identifiers, signed URLs, machine-local paths, or benchmark output.

Use the phrase application-owned behavior deliberately: enterprise claims should
make clear which proof belongs to Dataphyre and which proof belongs to the
application.

## Performance Discipline

Enterprise-grade does not mean adding abstraction to every call. Benchmark proof
applies to Dataphyre shared production hot-path changes, not to
application code using Dataphyre. For shared hot-path code, maintainers should use the
performance contract to:

- add or reuse a focused benchmark scenario;
- compare baseline CLI, opcache, and opcache-JIT profiles;
- record accepted and rejected decisions in benchmark notes;
- keep rejected-candidate notes when a tempting change is mixed or regressive;
- prefer clear reusable code unless benchmark data proves the lower-level path
  is worth keeping.

Docs, cold-path diagnostics, and package review do not need microbenchmarks by
default, but they still need focused verification.

## Enterprise Adoption Checklist

Before presenting a feature as enterprise-ready, verify:

- public docs explain the runtime and extension boundary;
- MCP or static diagnostics can inspect the feature without unsafe execution;
- configuration shape avoids leaking secrets and app-local values;
- tests or manifests cover the intended public contract;
- package validation and manifest checks pass where relevant;
- package manifest provenance is present where the package includes it;
- agent guidance explains how to extend the feature without patching core for an
  application.

## Useful Source Documents

- [Agent guidance](AGENTS.md)
- [Architecture](ARCHITECTURE.md)
- [Configuration](CONFIGURATION.md)
- [Module index](MODULES.md)
- [Stability policy](STABILITY.md)
- [Security policy](SECURITY.md)
- [MCP tooling](../runtime/modules/mcp/documentation/Dataphyre_MCP.md)
- [MCP AI guidelines](../runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md)

Dataphyre is provided under the MIT License; enterprise-readiness guidance is a
framework quality posture, not an additional support warranty.
