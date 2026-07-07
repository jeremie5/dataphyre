# Agent Guidance

Dataphyre is framework source. When building or fixing an application that uses
Dataphyre, do not modify Dataphyre internals just to make that application work.

## Application-Agent Default Lane

Assume the dominant MCP user is an application agent building or maintaining an
app with Dataphyre, not a Dataphyre framework contributor. Start with docs,
metadata, safe route/config/storage/SQL and diagnostic inspection, and dry-run
plans. Put application behavior in app code, install config, callbacks,
dialbacks, plugins, MCP metadata, or application-owned adapters before proposing
Dataphyre runtime-internal edits.

For app-building MCP work, start with `dataphyre_app_builder_plan_generate`.
Use its compact first-read fields before opening governance-heavy context:
`builder_response.first_read.next_action` for the immediate decision,
`scaffold_completion_summary.next_continuation` for chunked scaffolds,
`policy_decision_register` for app-owned policy choices before writes,
`prewrite_checklist.prewrite_blockers`, `implementation_obligations`, and
`ready_to_write` for ordinary app write readiness, and `verification_handoff`
for copy-safe focused verification evidence. Open
`detail_page=planning|implementation|verification|controls|governance` only for
the next body needed; reserve `payload_profile=full` for skeleton bodies or
cross-page context.

Verify app behavior with focused application or module checks. Do not treat
`dataphyre_mcp_verify_all`, maintainer evidence,
hot-path benchmarks, or unsafe MCP mode as default app-agent requirements.

Prefer these extension points:

- install configuration under `config/`
- dialbacks and callbacks exposed by the relevant module
- install plugin hooks under `plugins/pre_init/` and `plugins/post_init/`
- MCP metadata under `plugins/mcp/` for local agent/tool integration
- reusable runtime modules when behavior belongs in the framework

Use existing dialback names exactly; they are runtime extension contracts.
New kernel/runtime dialbacks should be explicit module-scoped events, normally
`CALL_<MODULE>_<ACTION>`. New Framework-owned dialbacks should use
`CALL_<MODULE>_FRAMEWORK_<SURFACE_OR_CONCEPT>_<ACTION>` unless the Framework code
is intentionally bridging an existing kernel hook. Document new public hooks
where the module exposes them. Use the global `tracelog()` helper for Dataphyre
runtime instrumentation, but do not add `tracelog()` to functions that can run
1000+ times per request unless the retained overhead is proven with maintainer
benchmarks. Log `function_call_with_test` only for functions suitable for
dynamic unit-test discovery. Functions with `#[SensitiveParameter]` should use
`function_call` and no argument payload, and traces must not include secrets or
tenant-private payloads.

Core edits are appropriate for framework development: bugs in Dataphyre itself,
performance work, public API changes, module behavior, and documentation.

Before changing framework runtime code, changes should be reusable across
modules, inspectable, provenance-aware, verified, and small in runtime surface.

For the broader agent-first enterprise posture, use
`docs/AGENTIC_ENTERPRISE.md` as the contract for extension boundaries, MCP
safety, and release/benchmark expectations for explicit framework,
corporate-ready, release-facing, or shared hot-path work.



