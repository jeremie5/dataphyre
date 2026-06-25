# Dataphyre MCP

## Goal

Dataphyre MCP is the local AI development server for agents building Dataphyre applications. Design guidance assumes the overwhelming majority of MCP users are application agents, not Dataphyre framework contributors. Their normal work is application work: reading module docs, inspecting route artifacts, reviewing safe config/schema metadata, planning changes, and running route-free diagnostics without editing Dataphyre itself. Maintainer and framework-improvement guidance is available, but it is secondary and should activate only for explicit Dataphyre internals, release-surface, or shared hot-path work. The stdio server currently targets MCP protocol `2025-11-25`.

The first transport is stdio so editors and coding agents can run it from the project root:

```powershell
php common\dataphyre\runtime\modules\mcp\kernel\dataphyre_mcp.php
```

Client configs must use `common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php` as the MCP stdio server entrypoint. `common/dataphyre/runtime/modules/mcp/kernel/mcp.main.php` is only the Dataphyre runtime module bootstrap; it is linted and packaged with the module, but it is not a stdio MCP server.

## Current Capabilities

- `dataphyre_application_info`: PHP version, runtime presence, git status, module list, and unsafe-tool state.
- `dataphyre_application_catalog`: bounded local `applications/*` discovery with detected Dataphyre roots, config file names, namespace hints, and path confidence without app bootstrap or config value reads.
- `dataphyre_package_metadata_read`: safe Composer/package metadata reader for dependencies, autoload keys, and script names without command execution.
- `dataphyre_api_docs_static_summary`: static endpoint declaration and OpenAPI surface summary for Dataphyre API code without app bootstrap.
- `dataphyre_api_scaffold_plan`: dry-run API endpoint, handler, OpenAPI, and verification workflow plan without writing files or dispatching routes.
- `dataphyre_api_recipe_catalog`: dry-run API implementation recipe catalog for common endpoint patterns without file writes or dispatch.
- `dataphyre_api_cache_static_summary`: static endpoint cache, identity, trace payload, storage-layout, and clear-cache contract summary without touching cache storage.
- `dataphyre_openapi_static_contract_summary`: static OpenAPI generator, documentation route, Swagger UI, and publish contract summary without application bootstrap.
- `dataphyre_openapi_runtime_readiness_plan`: read-only readiness contract for any future unsafe-gated runtime OpenAPI reader without application bootstrap.
- `dataphyre_source_api_summary`: token-based PHP namespace/class/function/method summary without requiring source files.
- `dataphyre_list_modules`: module names plus Framework, kernel, unit-test, and documentation coverage.
- `dataphyre_module_describe`: bounded per-module inventory of docs, Framework classes, kernel files, tests, and version files.
- `dataphyre_module_dependency_map`: static module dependency, SQL table, include, class, and function map without executing module code.
- `dataphyre_runtime_version_summary`: static bootstrap, module version-file, and bundled package version metadata without loading the runtime.
- `dataphyre_module_docs_pack`: bounded docs bundle for one module plus baseline AI guidelines and runtime indexes.
- `dataphyre_search_docs`: local markdown search over Dataphyre docs and project planning docs.
- `dataphyre_read_doc`: repo-local documentation reader with workspace path guards.
- `dataphyre_docs_chunks_export`: bounded, semantic-ready documentation chunks for retrieval indexes or task packs.
- `dataphyre_docs_index_plan`: read-only client-side documentation indexing plan for local docs, optional remote docs, and semantic search payloads.
- `dataphyre_embeddings_readiness_plan`: read-only readiness contract for client-owned documentation embeddings without MCP embedding API calls.
- `dataphyre_remote_docs_readiness_plan`: read-only readiness contract for a client-owned remote documentation fetcher without MCP network requests.
- `dataphyre_datadoc_static_summary`: static Datadoc indexing, tokenizer/highlighter, SQL table, route, and UI contract summary without querying Datadoc.
- `dataphyre_datadoc_runtime_readiness_plan`: read-only readiness contract for any future unsafe-gated Datadoc SQL-backed reader without querying Datadoc records.
- `dataphyre_list_routes`: route-related PHP/JSON artifact discovery.
- `dataphyre_route_manifest_read`: read and summarize compiled route manifests without dispatching.
- `dataphyre_route_url_preview`: generate relative or base-URL absolute named route URLs from compiled manifests without dispatching.
- `dataphyre_route_match_preview`: dry-match method/path/host against compiled manifests without dispatching.
- `dataphyre_route_source_static_summary`: static source-level route declaration provenance without app bootstrap or dispatch.
- `dataphyre_route_source_ambiguity_report`: static report of non-literal route values, handlers, and chained metadata that require manifest confirmation or source review.
- `dataphyre_route_runtime_provenance_plan`: read-only readiness contract for any future unsafe-gated runtime route provenance reader without app bootstrap or dispatch.
- `dataphyre_controller_source_summary`: static MVC controller class, public action, and literal handler-string provenance without loading controllers.
- `dataphyre_middleware_source_summary`: static route/controller middleware declarations, built-in aliases, config keys, and middleware class provenance without running middleware.
- `dataphyre_mvc_config_static_summary`: static MVC config keys, route source forms, namespace, middleware, provider, model binding, and manifest cache contract summary.
- `dataphyre_mvc_route_cache_summary`: static MVC route list/cache/clear CLI and manifest-cache planning summary without running cache commands.
- `dataphyre_list_config_keys`: config file discovery and top-level key extraction without returning secret values.
- `dataphyre_config_shape_read`: redacted nested key-path shape for one PHP/JSON config without returning values, with data-safety metadata.
- `dataphyre_config_value_preview`: exact-key preview for non-secret scalar config values with sensitive-key denial and data-safety metadata.
- `dataphyre_storage_config_summary`: redacted storage disk config and driver-class inventory without storage operations, with data-safety metadata.
- `dataphyre_storage_driver_catalog`: static Storage driver contract and public method inventory without instantiating drivers, with data-safety metadata.
- `dataphyre_sql_tables_list`: list runtime/config table names and cluster assignments without credentials, with data-safety metadata.
- `dataphyre_sql_schema_read`: read first-party runtime table definitions without connecting to a database, with data-safety metadata.
- `dataphyre_sql_clusters_list`: summarize SQL datacenters, clusters, DBMS, and table assignments with usernames, passwords, endpoints, and database names omitted, with data-safety metadata.
- `dataphyre_sql_query_plan`: classify proposed SQL read statements, enforce static verb/table/row-limit guardrails, and return bounded preview SQL without connecting to a database, with data-safety metadata.
- `dataphyre_sql_query_runner_contract`: unsafe-gated execution contract for any future read-only SQL runner, including planner preflight, audit output, rejection rules, and non-exposed credential fields.
- `dataphyre_sql_runtime_readiness_plan`: read-only readiness contract for any future unsafe-gated SQL read runner without database connections or credential exposure.
- `dataphyre_tracelog_artifacts_list`: list bounded Tracelog and log artifacts without reading contents.
- `dataphyre_tracelog_read`: read redacted previews from repo-local Tracelog or log artifacts with diagnostic safety metadata.
- `dataphyre_tracelog_search`: search Tracelog/log artifacts with bounded reads, redaction, short snippets, and diagnostic safety metadata.
- `dataphyre_diagnostics_last_error`: extract recent redacted error-looking snippets from repo-local Tracelog/log artifacts without executing diagnostics, with summary-first sharing guidance.
- `dataphyre_browser_diagnostics_readiness_plan`: read-only readiness contract for any future unsafe-gated browser diagnostics runner without browser launch or HTTP requests, including diagnostic safety metadata.
- `dataphyre_flightdeck_surfaces_list`: list Flightdeck surface files, route strings, assets, and classes without dispatching.
- `dataphyre_unit_tests_list`: list Dataphyre JSON unit-test manifests without executing test code.
- `dataphyre_unit_test_manifest_read`: summarize one JSON unit-test manifest without executing test code.
- `dataphyre_browser_regression_manifest_summary`: static Panel browser regression and accessibility manifest contract summary without launching a browser.
- `dataphyre_agent_context_generate`: generate read-only Dataphyre instruction file content for Codex, Claude, Cursor, or generic agents.
- `dataphyre_scaffold_plan_generate`: generate dry-run implementation plans for Panel resources, routes/controllers, SQL tables, MVC controllers, and runtime modules with an extension boundary that keeps application behavior in app code, config, callbacks, dialbacks, plugins, MCP metadata, adapters, or reusable contracts before runtime internals.
- `dataphyre_app_builder_plan_generate`: generate the ordinary app first step. Use `payload_profile=compact` for the first call: it returns a first-read `builder_response` with `next_action`, `next_detail_page`, concrete scaffold artifacts, naming, write-readiness, scaffold completion, focused verification handoff, policy attention when relevant, and collapsed governance. It omits `builder_plan`, raw `handoff_fields`, implementation recipes, acceptance reviews, verification execution bodies, data-model handoff bodies, and skeleton bodies by default; open the one page named by `next_detail_page`, and use `payload_profile=full` only when the agent is ready to adapt app-owned skeleton bodies or needs cross-page context.
- `dataphyre_panel_scaffold_catalog`: static Panel scaffolding/package-template/test surface inventory without executing generators.
- `dataphyre_panel_package_manifest_summary`: static Panel package manifest, template, repository, install, rollback, trust, and compatibility contract summary.
- `dataphyre_panel_theme_manifest_summary`: static Panel theme manifest, preset, asset, library, and preview contract summary without rendering previews.
- `dataphyre_panel_documentation_catalog_summary`: static Panel documentation catalog and entry manifest contract summary without building documentation.
- `dataphyre_panel_media_manifest_summary`: static Panel media library, collection, item, upload endpoint, and storage integration contract summary without touching storage.
- `dataphyre_task_pack_generate`: generate optional builder-profile context after the app plan with `builder_first_read`, one compact `builder_response`, balanced module docs, optional scaffold guidance, focused verification, extension-boundary guardrails, and collapsed governance. The default builder profile omits `builder_view`, `builder_plan`, `app_builder_lane`, and raw `handoff_fields`; open `dataphyre_app_builder_plan_generate` for the detail page needed next.
- `dataphyre_apply_audit_plan`: read-only audit envelope for proposed change sets, including path checks, extension-boundary review, risk classification, verification, rollback notes, and future unsafe apply contract.
- `dataphyre_apply_runtime_readiness_plan`: read-only readiness contract for any future unsafe-gated apply runner without file writes, commands, or git mutations, including an extension-boundary gate before runtime-internal writes.
- `dataphyre_run_panel_regression`: wrapper around the route-free Panel regression CLI.
- `dataphyre_run_panel_field_catalog_check`: wrapper around the route-free Panel field catalog harness.
- `dataphyre_verification_surface_catalog`: static catalog of JSON unit-test manifests, diagnostic files, route-free checks, regression scripts, and MCP/release helper scripts without executing them; ordinary app agents use it as discovery, not as a release gate.
- `dataphyre_php_lint`: focused `php -l` checks for repo-local PHP files.
- `dataphyre_release_check`: maintainer Dataphyre public release checks for release or framework claims, not routine app behavior proof.
- `dataphyre_release_triage_summary`: maintainer release check failure summary grouped by actionable categories.
- `dataphyre_release_fix_plan`: ordered read-only maintainer repair batches from release-check failures.
- `dataphyre_mcp_manifest_export`: client-visible manifest of tools, prompts, resources, groups, schemas, protocol, and safety posture.
- `dataphyre_prompt_pack_export`: reusable workflow prompt bundles for clients and agents.
- `dataphyre_mcp_prompt_catalog`: read-only catalog of registered workflow prompts with themes, related tools, resources, and export guidance.
- `dataphyre_mcp_skill_catalog`: read-only catalog of registered Dataphyre MCP skills with targets, themes, related tools, prompts, and resources.
- `dataphyre_mcp_skill_manifest_export`: portable skill registration metadata for client authors without writing files.
- `dataphyre_mcp_skill_registration_audit`: audit of registered skill metadata for missing related surfaces and product-local coupling risks.
- `dataphyre_mcp_skill_pack_export`: read-only skill instruction packs for Codex, Claude, Cursor, or generic clients without installing files.
- `dataphyre_mcp_skill_install_plan`: target-aware, read-only skill registration plan with proposed client-owned file templates and verification steps.
- `dataphyre_mcp_skill_file_install_plan`: read-only skill file writer contract with caller-owned roots, proposed writes, rollback, and verification steps.
- `dataphyre_mcp_client_config_summary`: read-only stdio client configuration examples, portable environment knobs, client-audience boundaries, safety notes, and maintainer-helper metadata marked as metadata only, not an app-agent checklist.
- `dataphyre_mcp_client_install_checklist`: portable install checklist for Codex, Claude, Cursor, or generic MCP clients with config, prompt, manifest, and verification steps.
- `dataphyre_mcp_client_config_install_plan`: target-aware, read-only client config writer contract with proposed caller-owned writes, rollback, and verification steps.
- `dataphyre_mcp_smoke_test_export`: portable stdio smoke-test requests and scripts for MCP clients without executing them.
- `dataphyre_mcp_client_onboarding_pack`: read-only client onboarding bundle with config, checklist, smoke tests, prompt catalog, manifest excerpt, and validation plan.
- `dataphyre_mcp_client_troubleshoot`: read-only client setup diagnostics for common stdio, PHP, cwd, stale-manifest, and unsafe-mode symptoms.
- `dataphyre_mcp_client_compatibility_matrix`: read-only compatibility matrix for Codex, Claude, Cursor, and generic stdio client workflows.
- `dataphyre_mcp_client_config_audit`: read-only audit of proposed MCP client config snippets for portable Dataphyre stdio setup issues.
- `dataphyre_mcp_safety_boundary_report`: read-only safety boundary report for default posture, unsafe gates, denied surfaces, redaction policy, and verification expectations.
- `dataphyre_mcp_status_board`: compact live summary of counts, coverage readiness, safety posture, doctor status, remaining gaps, and recommended next actions.
- `dataphyre_mcp_enterprise_adoption_audit`: read-only checklist audit for proposed features against the agentic enterprise contract, runtime quality gates, extension boundary, proof, and release-facing verification expectations.
- `dataphyre_mcp_capability_matrix`: release-facing capability matrix grouped by tool family, safety level, execution posture, ordinary verification, publication validation, and enterprise verification policy.
- `dataphyre_mcp_release_notes_generate`: read-only MCP release notes generated from live capability, status, readiness, and safety metadata.
- `dataphyre_mcp_surface_changelog`: read-only changelog snapshot generated from live tool, resource, prompt, client-helper, validation, and safety surfaces.
- `dataphyre_mcp_tool_call_examples_export`: read-only JSON-RPC `tools/call` examples for common Dataphyre MCP workflows.
- `dataphyre_mcp_workflow_playbook_export`: ordered read-only MCP workflow playbooks for common Dataphyre agent tasks.
- `dataphyre_mcp_workflow_readiness_audit`: read-only workflow audit that checks playbooks against registered tools, prompts, examples, and docs coverage.
- `dataphyre_mcp_workflow_session_export`: portable read-only JSON-RPC message sessions for ready workflow playbooks, with optional stdio frames.
- `dataphyre_mcp_workflow_transcript_schema_export`: redaction-aware transcript schema for recording workflow request and response summaries.
- `dataphyre_mcp_workflow_state_schema_export`: client-side workflow state schema for carrying MCP workflow progress between turns.
- `dataphyre_mcp_workflow_state_audit`: read-only audit for client-owned workflow state shape, redaction, phase, decision, and registered-tool consistency.
- `dataphyre_mcp_workflow_state_summary_export`: compact safe agent handoff summary from client-owned workflow state.
- `dataphyre_mcp_workflow_state_transition_export`: suggested client-side workflow state transition patch from state and next-action guidance.
- `dataphyre_mcp_workflow_state_sync_pack_export`: read-only continuity bundle with workflow state schema, audit, summary, transition, and next-action guidance.
- `dataphyre_mcp_workflow_state_timeline_export`: compact timeline view of current, next, pending, and inferred completed workflow phases from client-owned state.
- `dataphyre_mcp_workflow_state_resume_brief_export`: compact agent resume brief from client-owned workflow state continuity data.
- `dataphyre_mcp_workflow_transcript_audit`: read-only audit for client-captured workflow transcript shape, redaction, status, and tool consistency.
- `dataphyre_mcp_workflow_transcript_summary_export`: compact safe agent handoff summary from a client-captured workflow transcript, with a default 20-step summary window, hard cap 50, and omitted-step metadata.
- `dataphyre_mcp_workflow_checkpoint_export`: compact read-only progress checkpoint from a client-captured workflow transcript, with matching step-window progress metadata.
- `dataphyre_mcp_workflow_handoff_pack_export`: pre-run workflow bundle with playbook, readiness, session messages, and transcript guidance.
- `dataphyre_mcp_workflow_catalog`: read-only index of available workflows, readiness, prompts, step counts, and handoff tools.
- `dataphyre_mcp_workflow_lifecycle_export`: read-only workflow lifecycle runbook from task start through checkpoint and verification.
- `dataphyre_mcp_workflow_next_action_export`: read-only workflow next-action decision from task text, optional transcript state, or client-owned workflow state; app-building decisions mirror `builder_response.first_read.next_action` and `builder_response.first_read.next_detail_page` as compact `app_builder_next_action` with write-readiness and focused verification guidance.
- `dataphyre_mcp_workflow_recommend`: deterministic workflow recommendations for a task description or symptoms, with app-builder `builder_response.first_read.next_action`, `next_detail_page`, continuation-call, and write-readiness guidance for ordinary app work.
- `dataphyre_mcp_workflow_recommendation_handoff_export`: read-only recommendation plus selected workflow handoff reference for task text. Ordinary app-building handoffs propagate app-builder `builder_response.first_read.next_action`, `next_detail_page`, deferred entity chunks, write-readiness, focused verification guidance, and `handoff_pack_ref`; non-app, release-facing, or escalated workflows can still inline the selected handoff pack.
- `dataphyre_mcp_task_start_pack_export`: optional read-only cold-start context with `builder_first_read` and one compact `builder_response` for build-shaped work, `inspection_view` for read-only discovery, discovery summaries, workflow handoff summary, and fetchable context links by default.
- `dataphyre_mcp_agent_brief_export`: direct compact read-only app-builder brief with `builder_first_read`, compact `app_builder_next_action`, at most two focused next actions, context links, selected workflow, and optional policy attention/elevated review pointers. Build-shaped briefs use the app-builder fast lane instead of wrapping the broader task start pack; detail-page maps and payload-budget policy metadata stay behind the linked app-builder/start-pack detail calls.
- `dataphyre_mcp_tool_finder`: focused discovery helper for registered tools by search text or capability family without scraping the full manifest.
- `dataphyre_mcp_resource_finder`: focused discovery helper for registered resources and workflow prompts by search text.
- `dataphyre_mcp_docs_coverage_report`: read-only MCP/release-surface coverage check that compares live tools, resources, prompts, skills, and safety terms against MCP docs.
- `dataphyre_mcp_readiness_report`: live agentic capability coverage, safety posture, gap list, and next-slice recommendations from registered MCP surfaces.
- `dataphyre_mcp_live_validate`: bounded local stdio validation for client setup or MCP publication checks, not app behavior proof.
- `dataphyre_mcp_verify_all`: aggregate maintainer MCP verification suite for MCP/release-surface claims, not routine app verification.
- `dataphyre_mcp_doctor`: fast MCP module health check for MCP wiring, docs, tools, and app-coupling guardrails, not application behavior proof.

Resources expose the module index, runtime README, this plan, AI guidelines, the agentic enterprise contract, capabilities, and discovered markdown documentation. Core resource URIs are `dataphyre://module-index`, `dataphyre://runtime-readme`, `dataphyre://mcp-plan`, `dataphyre://ai-guidelines`, `dataphyre://agentic-enterprise`, and `dataphyre://mcp-capabilities`. Prompts provide reusable Dataphyre feature planning, debug triage, and Panel workflow guidance.

The MCP `initialize` response and high-risk tool descriptions name the Application-Agent Default Lane before clients call manifests, resources, or prompts. Treat command-backed validation tools in `tools/list` as scoped maintainer or MCP/release-surface tools unless a payload explicitly says otherwise.
Workflow playbooks make the same distinction: client playbooks may use `dataphyre_mcp_live_validate` only as local MCP client setup validation for stdio/server-entrypoint changes, not as ordinary app behavior proof; application work still uses focused app/module checks from the app-builder verification handoff or verification catalog.

## Internal Module Declarations

Public module release coverage comes from `docs/MODULES.md` and the release/export
scripts. Internal worktrees can still expose redacted modules to MCP through
private plugin declarations in `plugins/mcp/*.json`.

Those JSON files are install-level plugin metadata and are omitted from prepared
public exports. MCP reads them without booting Dataphyre or executing plugin PHP,
then annotates `dataphyre_list_modules`, `dataphyre_module_describe`, and
`dataphyre_runtime_version_summary` with `visibility`, `release`, `declared_by`,
and declaration notes. This keeps private adapters available for internal
diagnostics and planning while leaving the public docs and package index clean.

The `dataphyre://ai-guidelines` resource and `dataphyre_runtime_guidelines` prompt provide baseline framework rules for agents before they edit Dataphyre runtime or application code. Additional workflow prompts include `dataphyre_feature_plan`, `dataphyre_debug_triage`, `dataphyre_panel_workflow`, `dataphyre_release_triage`, `dataphyre_sql_schema_workflow`, `dataphyre_route_manifest_workflow`, and `dataphyre_diagnostics_workflow` for app planning, diagnostics, Panel work, release triage, SQL schema inspection, route manifest inspection, and diagnostic handoffs.

The `dataphyre://agentic-enterprise` resource exposes the high-level enterprise contract for agent-first corporate Dataphyre work, including extension boundaries, MCP safety, and the release/benchmark expectations that apply only to explicit framework, corporate-ready, release-facing, or shared hot-path work.

The `dataphyre://mcp-capabilities` resource returns the compact discovery snapshot: tools, prompts, resources, default safety posture, entrypoint and transport/path boundaries, package-release boundary, application-agent boundaries, compact workload and diagnostic handoff summaries, app-builder readiness summaries, compact `apply_readiness`, and intentionally unexposed unsafe surfaces.

Use that resource for lightweight client discovery. It keeps the proportional-overhead rule visible before clients open the full readiness report: app-builder or read-only inspection first, compact essentials inline, broad contracts and publication validation collapsed until explicitly requested for an escalation decision, and no `dataphyre_mcp_verify_all`, Dataphyre hot-path benchmarks, or runtime-internal edits as ordinary app ceremony.

The same resource links the fuller contracts by name: `transport_and_filesystem_boundary`, `agent_workload_summary`, `diagnostic_handoff_summary`, `app_builder_readiness.*_contract`, and `apply_readiness`. Diagnostic handoff tells agents to share `diagnostic_summary.copy_safe_evidence` instead of raw logs; app-builder readiness tells clients that first-read builder/start/brief payloads carry the actionable resume cursor while compact top-level mirrors use refs; apply readiness says no write-capable apply runner is exposed by default and future apply workflows must follow `apply_next_action`.

Readiness and status payloads also expose `default_app_workflow`, a compact
six-step ordinary app lane: start the compact app-builder plan, follow
continuation calls until chunks are complete, resolve prewrite blockers, prepare
app-owned writes, run focused verification, and complete the copy-safe done
review. It keeps optional task/start/brief context separate and lists
`dataphyre_mcp_verify_all`, Dataphyre hot-path
benchmarks, and runtime-internal edits as not-default app work.

Use `dataphyre_mcp_manifest_export` when a client or agent needs a structured, tool-callable manifest with optional input schemas, grouped tool names, prompt metadata, resource metadata, and safety rules.

Manifest payloads expose `server_entrypoint_contract`, `transport_and_filesystem_boundary`, `application_agent_operating_contract`, `ordinary_app_work`, and `tool_audience_boundaries`, so the first structured discovery response tells agents the stdio server path, transport/path safety boundary, and that ordinary Dataphyre MCP use is for application agents building apps: read-only metadata first, app-owned extension points, and focused app/module checks. `tool_audience_boundaries.publication_validation_tools` names command-backed or release-facing tools such as `dataphyre_mcp_verify_all` without making them ordinary app-agent requirements. `tool_audience_boundaries.publication_validation_tool_boundaries` and manifest `tool_groups.*.tool_boundaries` carry the machine-readable proof scope for flat tool lists; agents should read `audience_scope`, `claim_boundary`, and `not_app_behavior_proof` before turning a listed tool into an action. Do not treat `dataphyre_mcp_verify_all`, hot-path benchmarks, unsafe MCP mode, or Dataphyre runtime-internal edits as default requirements just because they appear elsewhere in the manifest.

The ordinary app-work ownership rule keeps the consuming application as owner: agents place behavior in application files, configuration, callbacks, dialbacks, plugins, MCP metadata, or app-owned adapters unless the task explicitly escalates to Dataphyre framework work.

Manifest `tool_groups.verification` is the ordinary focused verification lane; aggregate MCP proof, release checks, doctor, live validation, and docs coverage are grouped under `tool_groups.publication_validation` for MCP/release-surface claims instead of ordinary app behavior proof. The group-level `audience_scope` is the compact decision field: ordinary app agents follow `focused_app_or_module_verification`, while `publication_validation_not_ordinary_app_work` and `local_client_setup_not_app_behavior` stay collapsed unless the task is explicitly about release, MCP publication, or client wiring.

Use `dataphyre_prompt_pack_export` when a client needs reusable Dataphyre workflow prompts in one payload instead of separate `prompts/get` calls.

Use `dataphyre_mcp_prompt_catalog` when a client or agent needs to choose workflow prompts by theme and understand their related tools and resources before fetching prompt text.

Prompt catalog payloads are lightweight discovery surfaces: they list prompt themes, related tools, related resources, and `context_links` instead of inlining full contracts. App/module prompts keep `governance_notes` and links compact; runtime-guidelines and release prompts may inline governance contracts because those are explicit governance/release lanes.

App-building prompts tell agents to first call `dataphyre_app_builder_plan_generate`, read `builder_response.first_read`, add `dataphyre_task_pack_generate payload_profile=builder` only when focused module docs or a ready prompt are needed, use `dataphyre_mcp_agent_brief_export` for compact cold starts or handoffs, and use `dataphyre_mcp_task_start_pack_export payload_profile=builder` only when broader bounded workflow context is needed. Build-shaped work starts with the first-read builder page; read-only discovery starts with `inspection_view`.

Builder-profile task packs and docs chunks prioritize concrete app modules such as Panel and SQL before MCP documentation. They keep practical construction sections first, cap ordinary task-pack docs at 8 chunks even when `max_chunks` asks for more, expose MCP/AI guidance through optional links, use `dataphyre_mcp_task_start_pack_export payload_profile=builder` for broader ordinary app context, and reserve task-pack `payload_profile=governance` for inline guardrails. Docs chunks expose stable metadata for caller-owned indexing and focused reads: `chunk_index`, legacy `index`, `line_start`, `line_end`, and `content_sha256`. Use direct app-builder `payload_profile=full` for detailed planning fields or skeleton bodies; reserve start-pack `detail`/`deep` profiles for explicit full-contract or escalation work. Maintainer gates, `dataphyre_mcp_verify_all`, hot-path benchmarks, and runtime-internal edits are not ordinary prompt workflow requirements.

Task start packs and compact agent briefs use `inspection_view` for read-only
inspection, routing, diagnostics, and discovery tasks. Lightweight builder-mode
start packs expose `builder_first_read` and one compact `builder_response` for
build-shaped tasks, with `context_policy` links to
`dataphyre_app_builder_plan_generate payload_profile=full` or an explicit
escalation profile when a
caller needs the omitted `builder_view`, `builder_start`, or `app_builder_lane`
detail.

For build-shaped tasks, compact agent briefs are the direct app-builder fast
lane: they derive `builder_first_read`, `app_builder_next_action`, and
`builder_first_read.next_detail_page` from the app-builder planner helpers without first
assembling the broader task start pack. Ordinary compact briefs do not inline
the application-agent contract, tool-audience boundary, proportional guidance,
status board, safety boundary, enterprise audit objects, or bulky app-builder
detail; they omit top-level `governance_notes`, `builder_view`,
`app_builder_lane`, `app_builder_summary`, `detail_pagination`, and
`payload_budget` by default. `app_builder_summary` is broader start/task-pack
context, not a compact agent-brief field. Ordinary policy hints appear as compact
`policy_attention`; elevated tasks get a compact `elevated_review` pointer; and
`context_links` tell agents how to open direct app-builder `payload_profile=full`,
start-pack `detail`/`deep`, `dataphyre_mcp_safety_boundary_report`, or
`dataphyre_mcp_enterprise_adoption_audit` only when the task scope calls for it.
Open the full or detail app-builder plan only when the selected
`next_detail_page`, payload-budget, or escalation policy metadata is the next
decision.
This keeps inspection agents from inventing files to create while preserving
the app-builder expansion path when the task becomes implementation work.

Use `dataphyre_app_builder_plan_generate` as the golden-path entrypoint when the
task is ordinary application creation. The first-read
`builder_response.first_read` gives agents the immediate build decision:
`next_action`, `next_action.resume_cursor`, `next_detail_page`,
`files_summary`, `schema_summary`, `naming_contract`, `write_readiness`,
`scaffold_completion_summary`, `verification_handoff`, `open_details`, and
collapsed governance. `next_detail_page` is the compact one-page recommendation
for planning, implementation, verification, or controls; open that page only
when the first read or `next_action` points there.

Default to `payload_profile=compact`. It is a first-page response, not a
full-plan dump: it preserves the immediate next action, concrete
files/schema/Panel scaffold artifacts when the plan is small enough, bounded
files/schema summaries when the plan is broad, write-readiness, continuation
guidance, active app-owned policy summaries, the implementation matrix, focused
verification handoff, and detail pagination while omitting `builder_plan`, raw
`handoff_fields`, implementation recipes, acceptance review bodies,
verification execution bodies, data-model handoff bodies, and skeleton bodies.
Use `builder_response.compact_detail_policy.detail_counts` as the compact table
of contents for deciding which planning, implementation, verification, controls,
or skeleton detail to open next. Bulky handoffs and execution bodies such as
data-model detail, relationship adapters, implementation recipe items, local
convention probes, verification execution plans, acceptance review plans,
lifecycle/access/reliability/support/change/integration handoff objects,
verification fixtures, and recovery branches are listed in
`builder_response.compact_detail_policy.collapsed_sections` with
`open_with=dataphyre_app_builder_plan_generate payload_profile=full`. Rerun with
`payload_profile=full` only when the agent is ready to inspect detailed planning
fields or adapt app-owned skeleton bodies.

Explicit model input wins over prose. App-builder surfaces accept `entities`, a single-entity `fields` map/list, nested per-entity maps such as `fields.Project` and `fields.Ticket`, or list entries shaped as `{entity, fields}`. Metadata preserves `options`/`choices`/`enum`, `default`/`default_value`, `json`/`jsonb`, explicit relationship hints such as `foreign_key_target`, and explicit non-relationship identifiers such as `not_foreign_key`; `_id` suffixes alone are not treated as relationships.

For broad apps, chunking is part of the contract. `max_entities` defaults to 4 and caps at 12. `entity_planning` reports `planned_entities`, `deferred_entities`, `truncated`, and executable `continuation_calls`; continuation calls preserve chunk-scoped `fields`, `field_scope: chunk_entities`, `application_path`, `app_namespace`, `payload_profile`, and dependency context when those were supplied.

For mixed Panel/API or self-service app requests, compact plans may include a
bounded `companion_surface_handoff.endpoint_queue`. Treat that queue as the
next app-owned API planning work after entity chunks are complete; it keeps the
endpoint method/path/checks and follow-up app-builder arguments visible without
opening the full plan or governance context.

The builder lane keeps enterprise safety proportional. Non-sensitive scaffolds keep `governance_notes: none triggered`; schema-derived sensitive fields use `governance_notes.status=app_owned_policy_attention` with `mode=lightweight_app_owned_policy`, pointing to `data_sensitivity_summary` and `policy_decision_register` as app-owned obligations while enterprise audits stay collapsed unless an explicit escalation decision requires them.

App-owned extension boundaries are visible without becoming ceremony. `extension_boundary_summary` tells agents to keep behavior in app code, config, callbacks, dialbacks, plugins, MCP metadata, or application-owned adapters first; `placement_decision.runtime_internal_allowed=false` for ordinary app work. `extension_boundary_summary.app_owned_placement_checklist` maps ordinary behavior to application code, configuration, dialbacks/callbacks, plugins, or application adapters before any runtime-internal idea. Dataphyre runtime-internal edits, maintainer release proof, and hot-path benchmarks are only for explicit framework, release-facing, governance-sensitive, or shared hot-path work.

Focused verification remains the app lane. In compact mode, `verification_handoff` and `verification_evidence_summary` name app-owned PHP lint, Panel compatibility/regression, SQL metadata, or route/static checks where applicable, plus copy-safe completion fields. Full `verification_execution_plan` tool-call bodies and `acceptance_review_plan` done-review bodies are detail pages opened with `payload_profile=full` when the agent is ready to run or review focused checks. `verification_handoff.focused_completion_packet` is the compact closure shape for changed app-owned files, focused check pass/fail summaries, acceptance review, failed-check diagnostic evidence, remaining app follow-ups, and `not_release_proof=true`. Its `completion_decision` classifies closure as `ready_to_share`, `incomplete_missing_evidence`, or `failed_with_app_followups` without opening release validation. They exclude raw logs, secrets, tenant/customer identifiers, maintainer release proof, and Dataphyre benchmark output. Use deeper scaffold/task-pack surfaces only when an agent needs additional dry-run planning or focused module docs.

`field_input_contract.accepted_metadata` repeats accepted field metadata keys in machine-readable form so direct app-builder callers can validate relationship, JSON, option, default, and non-relationship hints without opening readiness context.

When explicit entities are provided but `fields` are omitted, default relationship fields stay app-aware: a ticket tracker with `Project` keeps project/user defaults, field-service models with `WorkOrder` attach `Ticket` to the work order, and SaaS-shaped models with `Account`, `Contact`, `Workspace`, or `Customer` attach `Ticket` to those local entities instead of inventing project relationships. Explicit `fields` remain the source of truth whenever supplied.

Generated app-builder entity class-like names preserve PascalCase compound words and common enterprise acronyms such as API, ID, SSO, SAML, SLA, MFA, TOTP, URL, URI, UUID, JWT, and OAuth while keeping paths and table names snake_case.

Chunked app-builder plans also expose `entity_planning.dependency_summary`, and each continuation call may include `dependency_context`. Preserve those fields when running continuation calls so later chunks can stitch relationships back to resources planned in earlier chunks without re-planning the whole app.

For chunked app scaffolds, `field_metadata_summary` includes explicit options/default metadata from `entity_planning.continuation_calls[*].arguments.fields` so deferred chunks do not lose caller-supplied field obligations. Compact first-read payloads keep concrete schema and field metadata inline for small explicit plans; broad or oversized plans keep bounded schema/field summaries plus detail-page pointers, while `builder_response.compact_detail_policy.collapsed_sections.data_model_handoff` points to the full data-model detail page for app-owned TableSchema required flags, casts, typed defaults, option sets, and explicit relationship decisions. Concrete schema and skeleton summaries remain bounded to the current chunk.

`scaffold_completion_summary` gives the compact completion state for chunked scaffolds. Treat the scaffold as incomplete until `complete=true` and `deferred_entities` is empty; otherwise keep following `entity_planning.continuation_calls`. Its `next_continuation` field points compact handoffs at the next tool, chunk, entities, field scope, dependency-context status, and `entity_planning.continuation_calls[0].arguments` source without duplicating full continuation arguments. Its `continuation_queue` lists every deferred chunk with the same compact fields plus each `entity_planning.continuation_calls[n].arguments` pointer, so agents can execute all remaining planner calls without reopening governance context or relying on chat memory.

App-builder plans also expose `app_contract_summary`, a lightweight schema-aware app-owned contract hint for ownership, tenant/workspace scope, lifecycle, audit, and relationship targets. Its `decision_prompts` convert those hints into ordinary app-owned questions before writes, without opening the enterprise audit gate.

`data_sensitivity_summary` adds schema-derived hints for credential, identity/contact, billing/financial, tenant/access, and regulated personal-data fields. It also uses entity and table names from the current chunk plus deferred `entity_planning.continuation_calls`, so billing, tenant/workspace, usage, audit, subscription, invoice, payment, seat, and webhook-shaped resources stay visible in the first-read builder lane without expanding the current write surface. Signals include compact app-owned handling actions such as write-only or adapter-resolved credential fields, permission-gated contact search, masked billing export checks, tenant-scoped repository policy, and explicit retention/redaction policy for regulated data. Use it for app-owned access, redaction, storage, validation, and focused checks; open governance only for release-facing or public framework claims, corporate or enterprise-readiness claims, security/identity/session/credential/governance/tenant/billing/privacy/compliance/data-residency/retention/legal-hold/access-policy work, Dataphyre framework internals or reusable contributions, or shared production hot-path changes.

`policy_decision_register` combines app-contract prompts and sensitivity hints into a compact app-owned decision list before writes. It names required ownership, tenant/workspace, lifecycle, audit, relationship, and sensitive-data decisions, the source summary to inspect, and the escalation boundary; it is not an enterprise audit gate for ordinary app scaffolds.

Before writing app-owned files, `prewrite_checklist.app_contract_decisions` points agents back to `builder_response.app_contract_summary.decision_prompts` so ownership, tenant/workspace scope, lifecycle, audit, and relationship-policy decisions are handled as ordinary application choices without opening the enterprise audit lane.

`prewrite_checklist.prewrite_blockers` is reserved for hard gates: unconfirmed inferred entities, incomplete chunks, unresolved placeholder paths, and sensitive-data decisions only when task text explicitly asks for elevated security, privacy, compliance, governance, tenant/billing/audit policy, retention, legal-hold, or access-policy behavior.

Ordinary schema-derived sensitive fields stay visible as `data_sensitivity_summary`, `policy_decision_register`, proportional `governance_notes`, and `prewrite_checklist.implementation_obligations`. Relationship adapters, field metadata, app-contract decisions, `adaptation_notes`, app-owned placement, and after-write focused verification stay in obligations or reminders instead of blocking clean explicit scaffolds.

The `prewrite_checklist` with explicit `prewrite_blockers`, app-owned `implementation_obligations`, `sensitivity_gate_policy`, `write_readiness` is the compact first-page gate for ordinary application writes. It tells agents what must be resolved now, what remains an app-owned implementation obligation, and what can wait for focused verification.

`prewrite_checklist.resolution_plan` gives ordinary app agents machine-readable resolution sources and acceptable resolutions for blockers, obligations, and reminders. In compact mode it points to first-page fields and detail refs such as `entity_input_contract`, `app_path_context`, `policy_decision_register`, `detail_pagination`, and `compact_detail_policy.collapsed_sections`; full-profile resolution can then open `local_convention_probe`, `implementation_recipe`, or `verification_execution_plan` only when those are the next concrete step. It does this instead of opening governance, release validation, runtime internals, or benchmark proof.

App-builder surfaces also accept optional `application_path` and `app_namespace` hints. When omitted, `app_path_context.placeholder_mode=true` preserves portable placeholders and `replace_placeholders` remains a prewrite blocker until the agent supplies a concrete app path.

`application_path` is a repo-relative app hint, not a filesystem path. Use `applications/<app>` or `applications/<app>/backend/dataphyre`; absolute paths, URLs, and `..` traversal are rejected with `app_path_context.path_input_valid=false`, `discovery_hint.status=invalid_application_path`, and `replace_placeholders` as a prewrite blocker.

`app_namespace` must be a valid PHP namespace such as `App`, `AcmePortal`, or `Acme\Portal`. Invalid namespace hints set `app_path_context.namespace_input_valid=false`, fall generated namespace hints back to `App`, scrub invalid namespace values from continuation calls, and keep `replace_placeholders` blocked until the agent reruns with a valid namespace.

When `application_path` is supplied but the resolved app-owned Dataphyre root does not exist locally, `replace_placeholders` remains a prewrite blocker with `status=verify_concrete_app_path_exists`. `app_path_context.discovery_hint.status=concrete_app_path_not_found` points to `dataphyre_application_catalog`; agents should correct the path or rerun the builder before writing files.

`app_path_context.discovery_hint` keeps the unblocker lightweight: call the read-only `dataphyre_application_catalog` tool for bounded app candidates, then supply `application_path` and optionally `app_namespace` back to the builder. Use `dataphyre_application_info` only when broader startup/runtime context is needed. Ordinary app path discovery does not require governance context, MCP/release-surface validation, or Dataphyre hot-path benchmark evidence.

When supplied, `app_path_context` carries the concrete app-owned Dataphyre root, Framework artifact path, Panel resource namespace, and Framework namespace through direct app-builder plans, builder task packs, task start packs, and compact agent briefs.

`builder_response.first_read`, `builder_first_read`, compact `builder_response`, and `next_detail_page` give agents a machine-readable overhead budget for ordinary app work: read the first page now, use `next_detail_page` as the one-page detail recommendation, then follow `builder_response.agent_workload.phase_read_plan` for blocker resolution, write preparation, focused verification, done review, and escalation only when explicitly triggered; `detail_pagination` remains the backing page map for that recommendation. Prefer the default chunk size unless the caller explicitly accepts a larger first payload, open direct app-builder `payload_profile=full` only for the detail page or skeleton bodies needed next, open `dataphyre_mcp_task_start_pack_export payload_profile=builder` or `dataphyre_task_pack_generate payload_profile=builder` only when broader context or module docs are needed, keep start-pack `detail`/`deep`, status/safety/enterprise/publication validation collapsed until explicitly requested for an escalation decision, and do not treat maintainer validation, maintainer tools, hot-path benchmarks, or runtime-internal edits as ordinary app ceremony.

Builder start-pack `workflow_handoff` summaries do not repeat the full app-builder next-action contract. They keep `workflow_handoff.app_builder_next_action.contract_collapsed=true` with refs back to `builder_first_read.next_action` and the top-level `app_builder_next_action`, so broader workflow context cannot quietly re-expand the first page.

Workflow next-action payloads expose `app_builder_next_action.apply_audit_handoff` as the bridge between app-builder readiness and any future caller-owned write-capable apply workflow; full-profile direct builder payloads repeat the same bridge at `builder_response.write_handoff.apply_audit_handoff`, while compact direct/brief payloads keep the bridge discoverable through `builder_response.first_read.next_detail_page`, `builder_response.detail_refs`, `builder_first_read.open_details`, and the backing `detail_pagination` map instead of inlining write-handoff detail. It points to `dataphyre_apply_audit_plan` only after `write_readiness.status=ready_for_app_owned_writes`, uses `apply_next_action.status` as the decision field, and keeps maintainer release gates, `dataphyre_mcp_verify_all`, runtime-internal review, and Dataphyre hot-path benchmark proof out of ordinary app-owned writes.

`write_handoff.handoff_fields` preserves the context in full-profile detail needed to act on implementation obligations across resumes: `app_contract_summary`, `relationship_contract_summary`, `relationship_adapter_handoff`, `local_convention_probe`, `implementation_recipe`, `field_metadata_summary`, `data_model_handoff`, `data_sensitivity_summary`, `policy_decision_register`, and `prewrite_checklist.prewrite_reminders`. Compact responses keep raw handoff fields collapsed and expose `compact_detail_policy.detail_counts`, `compact_detail_policy.collapsed_sections`, `detail_refs`, and first-read resume cursors so agents can continue app-owned edits without opening governance or release validation surfaces.

`implementation_matrix.work_items` stays inline in compact mode as the app-owned obligation map. `implementation_recipe.items` is a full-profile detail page that gives ordinary app agents a capped file-by-file edit queue from skeleton adaptation notes, implementation obligations, relationship adapter touchpoints, and focused verification recovery branches without inlining full skeleton bodies. Open full `implementation_recipe` or `code_skeletons` only when ready to adapt app-owned file contents.

`local_convention_probe.items` is a full-profile detail page for the app-inspection list before app-owned writes. Compact mode points to it through first-read resume cursors and `compact_detail_policy.collapsed_sections` so agents can open it only after write-readiness says local convention inspection is the next step. It maps planned skeleton kinds to local `inspect_globs`, style `signals`, and the builder fields those signals feed, so generated resources adapt to local Panel, SQL, API, and regression conventions without opening governance, release readiness, `dataphyre_mcp_verify_all`, or benchmark proof.

`next_action.resume_cursor` gives ordinary app agents the precise continuation pointer for the current phase: continuation call arguments for chunked scaffolds, the first prewrite blocker when blocked, local convention probe plus write sources when ready to write, and focused verification plus acceptance review after writes. Compact agent briefs keep resume cursors as pointers, not payload transport: inline `copy_forward` arrays are replaced by `copy_forward_count` plus `copy_forward_ref`, and full copy-forward detail stays behind the direct app-builder detail page. It is a compact resume aid, not a separate workflow gate.

`verification_execution_plan.items` is the full-profile detail page for ordered focused checks after app-owned writes, including concrete MCP tool arguments, related recipe paths, copy-safe result fields, and failure branches. Compact mode keeps `verification_handoff` and `verification_evidence_summary` inline, then points to the full execution plan only when focused checks are the next action. It is not a release gate and excludes `dataphyre_mcp_verify_all`, release validation, and hot-path benchmark proof for ordinary application work.

`acceptance_review_plan.items` is the full-profile detail page for criterion-by-criterion done review after app-owned writes and focused verification. `acceptance_review_plan.obligation_review_items` mirrors implementation obligation ids such as field metadata, relationship adapters, data integrity, sensitivity, and app-owned corporate controls into compact pass/fail review items. Compact mode keeps acceptance review as an explicit collapsed page, not an inline first-read body. These plans tie acceptance criteria and obligations to `implementation_recipe`, `verification_execution_plan`, `verification_handoff`, field/relationship summaries, app path context, corporate-control summaries, and extension-boundary evidence without opening MCP release validation or maintainer benchmark gates.

Full app-builder `code_skeletons` include `adaptation_notes` for app-owned replacements such as repository/query adapters, namespace changes, manifest registration, SQL config registration, and focused regression expansion. Panel resource previews use the placeholder namespace `App\Panel\Resources` and manifest imports so copied skeletons are internally consistent, but agents must replace that namespace with the consuming application's convention before writing. When sensitive field names are inferred, skeletons also include `sensitive_field_policy` with category-level `recommended_actions` and per-field actions so app agents can remove, mask, make write-only, or permission-gate fields before writing Panel resources. Compact `code_skeleton_summary.sensitive_field_policy` keeps skeleton bodies collapsed while exposing `categories`, paths, and `path_reasons` with the fields/actions that caused each path to need sensitive handling. Open full skeletons only when the agent is ready to adapt and write app-owned files.

Compact brief and start-pack payloads surface `diagnostic_handoff_hint`, `code_skeleton_summary` with `sensitive_field_policy.path_reasons` so agents can keep the first read small while still seeing why a generated file needs masking, permission gates, write-only treatment, or follow-up diagnostics.

App-builder and scaffold payloads also expose an `extension_decision_ladder` or `extension_boundary.decision_ladder`. It orders placement choices from app code, configuration, dialbacks/callbacks, plugins, and application adapters through reusable module contracts and finally runtime internals, with runtime internals reserved for reusable framework behavior, public/release claims, sensitive shared behavior, or Dataphyre hot-path work with proof.

The released tool metadata is app-builder first: `tools/list` and `dataphyre_mcp_manifest_export` expose `dataphyre_app_builder_plan_generate` ahead of generic agent context surfaces, app-builder/start-pack/brief descriptions name the `agent_workload` overhead budget where their compact payloads expose it, and the generic agent-context tool description points ordinary app creation back to the builder planner. This keeps clients that choose from tool descriptions on the practical app-building path before opening broad runtime instructions.

Prompt catalog metadata follows the same progressive disclosure rule. App-building prompts such as `dataphyre_feature_plan`, `dataphyre_panel_workflow`, `dataphyre_sql_schema_workflow`, route inspection, and diagnostics list concrete module documentation resources first; runtime guidelines and the agentic enterprise contract stay attached to runtime/release/governance prompts instead of every app prompt.

`resources/list` also exposes stable app-builder module documentation resources for Panel, SQL, Routing, Tracelog, and Issue docs before bounded markdown discovery. Skills and prompt catalogs can depend on those module docs without relying on discovery order or opening broad governance resources.

Task packs separate ordinary `verification` from `publication_validation`. The ordinary list is for focused application or module behavior checks; MCP doctor and aggregate MCP verification belong to MCP surface changes, published shared MCP setup docs, release notes, or MCP/release-surface claims, not routine app behavior proof.

App-builder `verification_evidence` tells agents what focused proof to keep from ordinary checks: tool name, concrete app-owned arguments, and pass/fail summaries. It is app/module behavior evidence, not maintainer release evidence.

Diagnostic and verification discovery tools follow the same split: redacted Tracelog/error summaries support summary-first app or module triage, browser diagnostics readiness is a future unsafe-runner contract, and `dataphyre_verification_surface_catalog` is discovery for focused checks rather than a release gate. Their safety payloads keep redaction/execution rules inline, expose compact `ordinary_app_summary`, and point full audience boundaries through `boundary_refs` instead of inlining release-oriented contracts.

Diagnostic summaries expose `copy_safe_evidence`, a concrete handoff object with `handoff_status: copy_safe_summary_ready`, the redacted finding, bounded evidence, next reads, `diagnostic_next_action`, copy fields, and explicit `not_included` exclusions for raw logs, unredacted snippets, secrets, tenant identifiers, product identifiers, local usernames, and machine-local absolute paths. Copy-safe means app-team summary safe, not paste-anywhere safe: `internal_share_default=app_team_summary_ok`, `external_share_default=remove_or_generalize_identifiers_first`, and `safe_to_paste_externally=false` keep repo-relative paths, scopes, queries, and artifact names under review before external sharing.

Use `dataphyre_openapi_runtime_readiness_plan` when a client needs the explicit bootstrap, redaction, audit, output-bound, and denied-behavior contract before considering any future runtime OpenAPI document reader. The plan includes `application_agent_operating_contract` plus `ordinary_app_work` so ordinary app agents keep using static OpenAPI, API docs, route source, and scaffold metadata before any unsafe runtime reader is considered.

Use `dataphyre_route_runtime_provenance_plan` when a client needs the explicit bootstrap, no-dispatch, no-middleware, no-cache-write, redaction, audit, and output-bound contract before considering any future runtime route provenance reader.

Route and MVC inspection payloads expose `route_safety` so application agents can use compiled route manifest metadata, URL previews, dry route matches, static route declarations, controller and middleware provenance, and route-cache command contracts for ordinary app planning without application bootstrap, route dispatch, middleware execution, controller invocation, endpoint execution, OpenAPI runtime generation, route-cache writes, or HTTP requests. The contract embeds `application_agent_operating_contract` plus `ordinary_app_work`, so route and MVC discovery stays on the app-agent default lane unless the task becomes release-facing or public Dataphyre framework claims, corporate-ready or enterprise-readiness claims, security/identity/access/session/credential/governance/tenant/privacy/compliance/data-residency/retention/legal-hold/access-policy work, Dataphyre framework internals or reusable framework contributions, or shared production hot-path changes.

Use `dataphyre_sql_runtime_readiness_plan` when a client needs the explicit planner, adapter, credential-redaction, row-bound, timeout, audit, and denied-behavior contract before considering any future unsafe-gated SQL read runner.

Config, storage, and SQL metadata payloads expose `data_safety` so application agents can use key paths, value kinds, redaction flags, driver contracts, table names, schema columns, cluster aliases, and non-executing SQL plans for ordinary app planning. The contract embeds `application_agent_operating_contract` plus `ordinary_app_work`, names what is not returned, including secrets, usernames, resolved hosts/endpoints, database names, tenant-identifying values, and sensitive bucket names, and keeps heavier governance review reserved for release-facing or public Dataphyre framework claims, corporate-ready or enterprise-readiness claims, security/identity/access/session/credential/governance/tenant/privacy/compliance/data-residency/retention/legal-hold/access-policy work, Dataphyre framework internals or reusable framework contributions, or shared production hot-path changes.

Use `dataphyre_browser_diagnostics_readiness_plan` when a client needs the explicit browser lifecycle, URL allow-list, artifact, redaction, mutation, timeout, and denied-output contract before considering any future browser diagnostics runner.

Use `dataphyre_apply_runtime_readiness_plan` when a client needs the explicit audit-envelope, extension-boundary, path-scope, dirty-worktree, rollback, verification, publication-validation, and denied-behavior contract before considering any future unsafe-gated apply runner. `dataphyre_apply_audit_plan` keeps ordinary `verification` focused on touched-file checks and puts maintainer MCP or release evidence in `publication_validation`. The apply plans include `ordinary_app_work` and `tool_audience_boundaries` to keep the consuming application as the ordinary owner while making future unsafe apply behavior an explicit escalation. They also expose `apply_next_action`, a compact placement decision that tells future apply clients to use app-owned extension points for ordinary changes or escalate framework/MCP paths through enterprise adoption review before any write-capable runner. Future apply workflows must not modify Dataphyre runtime internals just to make one application work when app code, config, callbacks, dialbacks, plugins, MCP metadata, or an application-owned adapter can carry the behavior.

Use `dataphyre_docs_index_plan` when a client needs a safe plan for local markdown chunks, optional remote documentation sources, semantic-search metadata, refresh triggers, and embedding payloads without MCP network calls or index writes. The plan includes `application_agent_operating_contract` plus `ordinary_app_work`, so ordinary app-agent documentation discovery stays on bounded local metadata and caller-owned indexing rather than maintainer tooling.

Source and documentation discovery payloads such as `dataphyre_package_metadata_read`, `dataphyre_api_docs_static_summary`, `dataphyre_source_api_summary`, `dataphyre_module_docs_pack`, `dataphyre_search_docs`, `dataphyre_read_doc`, and `dataphyre_docs_chunks_export` expose `discovery_safety` with `application_agent_operating_contract` plus `ordinary_app_work`. This keeps first-touch app-agent context gathering bounded to local metadata and text: no bootstrap, route dispatch, controller invocation, dependency installation, package scripts, SQL execution, secret resolution, or file writes.

Module inventory payloads such as `dataphyre_list_modules`, `dataphyre_module_describe`, `dataphyre_module_dependency_map`, and `dataphyre_runtime_version_summary` expose `module_inventory_safety` with `application_agent_operating_contract` plus `ordinary_app_work`. Use them for module selection, docs routing, dependency awareness, and app-owned extension planning without module bootstrap, route dispatch, controller invocation, SQL execution, secret resolution, or file writes.

Use `dataphyre_embeddings_readiness_plan` when a client needs the explicit embedding provider, model, dimension, token-budget, batching, cache, redaction, and denied-output contract before adding semantic documentation search. The plan includes `application_agent_operating_contract` plus `ordinary_app_work`, keeping ordinary app-agent docs discovery on bounded chunks and index metadata while embedding APIs and vector indexes stay client-owned.

Use `dataphyre_remote_docs_readiness_plan` when a client needs the explicit base URL, allow-list, timeout, byte-limit, cache, redaction, and denied-output contract before adding remote docs fetching. The plan includes `application_agent_operating_contract` plus `ordinary_app_work`, keeping normal app-agent docs work on local docs/chunks/index metadata while remote fetching stays client-owned.

Use `dataphyre_datadoc_runtime_readiness_plan` when a client needs the explicit project-filter, SQL-readiness, output-bound, redaction, and denied-behavior contract before considering any future Datadoc SQL-backed reader. The plan includes `application_agent_operating_contract` plus `ordinary_app_work`, so app agents use Datadoc static summaries, docs chunks, docs index plans, and SQL planning metadata before treating SQL-backed reads as an unsafe extension.

Use `dataphyre_mcp_skill_catalog` when a client or agent needs to discover registered Dataphyre MCP skills by target, theme, and related MCP surfaces. Registered skills are `dataphyre-app-builder`, `dataphyre-runtime-guidelines`, `dataphyre-mcp-client-setup`, `dataphyre-route-inspection`, `dataphyre-sql-schema-safety`, and `dataphyre-workflow-continuity`. The catalog exposes `dataphyre-app-builder` first for ordinary application work; that skill points to the app-builder planner first, optional builder task packs for module docs or ready prompts, optional cold-start/brief surfaces with task-specific first views, Panel/SQL module docs, and focused app/module verification without attaching runtime governance resources by default.

Use `dataphyre_mcp_skill_manifest_export` when a client author needs portable skill registration metadata without writing client files.

Use `dataphyre_mcp_skill_registration_audit` before publishing skill packs so related tools, prompts, resources, and product-neutral metadata are checked.

Use `dataphyre_mcp_skill_pack_export` when a client needs copyable skill instructions for Codex, Claude, Cursor, or generic MCP workflows.

Use `dataphyre_mcp_skill_install_plan` when a client needs target-aware skill registration steps, proposed file templates, rollback notes, and verification guidance without writing skill files.

Runtime guidance skill metadata includes `dataphyre_mcp_enterprise_adoption_audit` so client-installed agent instructions can check enterprise/corporate/release claims through the same read-only contract used by start packs and workflow recommendations. Use that runtime-guidelines skill for framework, release-facing/public, corporate-ready or enterprise-readiness, security/identity/access/session/credential/governance/tenant/billing/privacy/compliance/data-residency/retention/legal-hold/access-policy, or shared hot-path work, not as the first installed-skill lane for routine app creation.

Use `dataphyre_mcp_skill_file_install_plan` before building client-specific skill file writers; it defines caller-owned skill roots, selected skill writes, backup, rollback, audit, and denied writes without touching files.

Skill install payloads keep ordinary client installation lightweight: local caller-owned installs compare proposed writes against exported skill packs, preserve backup/rollback scope, and use client-side skill discovery. Run `dataphyre_mcp_live_validate` only when local MCP server wiring changed. Use `dataphyre_mcp_skill_registration_audit` before publishing shared skill packs or shared skill-writer guidance, and use `dataphyre_mcp_verify_all` only before publishing shared skill guidance, release notes, or MCP/release-surface claims.

Skill catalog payloads are lightweight discovery surfaces: they list app-builder first, include skill instructions and related tools/resources, and expose `governance_notes` plus `context_links` instead of inlining full contracts.

App-builder-only skill packs point agents to `builder_response.first_read`, `builder_response.recovery_hints`, focused Panel/SQL metadata checks, redacted diagnostics, `code_skeleton_summary`, `write_readiness`, and `verification_handoff`. Detailed `agent_workload` context remains available for phase-specific work, but the default pass starts from first-read and opens details only when needed. Broader skill surfaces may expose `application_agent_operating_contract`, `ordinary_app_work`, and `tool_audience_boundaries` only when their scope needs those boundaries inline.

Skill workflows default to application agents building apps: use read-only MCP metadata, client-owned skill files, app-owned extension points, and focused app/module checks. Runtime-internal edits, `dataphyre_mcp_verify_all`, hot-path benchmarks, unsafe MCP mode, and maintainer evidence are not ordinary app-agent requirements.

Panel static metadata payloads expose `panel_metadata_safety` so application agents can inspect scaffold, package, theme, documentation, and media contracts for Panel app planning without executing generators, installing packages, rendering previews, building docs, touching storage, dispatching uploads, or writing files. The contract embeds `application_agent_operating_contract` plus `ordinary_app_work`, keeping ordinary Panel app work owned by the consuming application with focused app/module checks while heavier review is reserved for release-facing Panel package/theme/media claims, security-sensitive upload/storage behavior, Dataphyre framework changes, or shared hot-path work.

Use `dataphyre_mcp_client_config_summary` when a client or agent needs portable stdio setup JSON, environment flags, and unsafe-mode notes without hardcoding product-local paths or relying on publication validation.

Use `dataphyre_mcp_client_install_checklist` when a client or agent needs a target-aware setup plan that combines MCP config, generated agent instructions, manifest export, prompt pack export, portable smoke-test steps, and optional local live-validation steps without writing client files.

Use `dataphyre_mcp_client_config_install_plan` before building client-specific config writers; it defines caller-owned config paths, merge rules, backup, rollback, audit, and denied writes without touching files.

Use `dataphyre_mcp_smoke_test_export` when a client author needs portable framed JSON-RPC requests or PowerShell, Bash, Node.js, or PHP smoke snippets for validating stdio wiring. Smoke exports include `transport_and_filesystem_boundary`, and snippets compute `Content-Length` as the byte length of the JSON body.

Use `dataphyre_mcp_client_onboarding_pack` when a client author wants a single portable payload for MCP config, target notes, install steps, smoke tests, prompt discovery, manifest groups, and validation workflow.

Client setup payloads keep ordinary application installation lightweight: use config audits and smoke-test exports for local client wiring, then use live stdio validation only when MCP wiring changed, smoke output is inconclusive, or a client author is diagnosing setup failures. These payloads expose `client_setup_next_action` so client authors can move from config summary to config audit, smoke fixtures, optional live stdio validation, or onboarding without opening publication validation. `dataphyre_mcp_verify_all` and maintainer evidence belong to published shared MCP setup docs, release notes, MCP server wiring changes, or MCP/release-surface claims.

Client setup payloads expose `client_audience`, `application_agent_operating_contract`, `ordinary_app_work`, `tool_audience_boundaries`, `client_setup_next_action`, `app_after_setup_next_action`, and `transport_and_filesystem_boundary` so application agents can distinguish ordinary portable read-only setup from maintainer-only publication validation while still seeing the stdio framing and path-boundary rules.

Onboarding manifest excerpts carry the same ordinary app-work, tool-audience, and transport/path boundary metadata instead of relying on the top-level pack alone. The ordinary lane uses config review, config audit, smoke-test export, optional live stdio validation for wiring changes or failures, and focused app/module checks.

After local MCP setup works, `app_after_setup_next_action` points application agents to `dataphyre_app_builder_plan_generate payload_profile=compact`, `builder_response.first_read`, and `builder_response.first_read.next_detail_page` without requiring full manifest scraping, prompt-pack export, publication validation, or release proof first. It explicitly does not require `dataphyre_mcp_verify_all`, maintainer self-test evidence, Dataphyre hot-path benchmarks, or Dataphyre runtime-internal edits for app agents.

Use `dataphyre_mcp_client_troubleshoot` when a client author has setup symptoms or error snippets and needs likely causes plus portable next checks. The payload includes `transport_and_filesystem_boundary`, so framing and path-boundary fixes can be diagnosed from the client setup lane without opening release validation or developer artifacts.

Use `dataphyre_mcp_client_compatibility_matrix` when a client author needs target-specific setup posture, validation tools, instruction paths, and caveats for supported stdio clients. It also exposes `transport_and_filesystem_boundary` once at the matrix level, keeping target rows compact while preserving Content-Length framing, parse-error recovery, invalid-request handling for non-object or methodless JSON-RPC messages, and `safe_repo_path` boundary guidance.

Use `dataphyre_mcp_client_config_audit` when reviewing a proposed client config snippet for missing Dataphyre server args, unsafe flags, cwd assumptions, PHP command issues, product-local paths, or app-specific setup assumptions.

Use `dataphyre_mcp_safety_boundary_report` when a client, maintainer, or agent needs the current read-only default, unsafe opt-in knobs, intentionally denied surfaces, redaction policy, app-agent operating contract, ordinary app-work boundary, tool-audience boundaries, `safety_next_action`, and unsafe decision contract in one payload. The default-allowed lane covers metadata and dry-run planning, not command-backed self-validation; command-backed validators remain bounded or unsafe-gated. `safety_next_action` defaults to `stay_read_only` for ordinary app and client setup work, or to bounded verification-surface selection when unsafe mode is already explicitly enabled. The decision contract keeps the default answer at `do_not_enable`, names the few bounded local verification cases where unsafe mode may be discussed, and repeats which surfaces remain intentionally unexposed. Redaction policy covers secrets, auth headers, cookies, private keys, connection strings, signed URLs, tenant names, product identifiers, and machine-local paths before diagnostics are shared.

High-level safety, status, capability, and readiness payloads expose `application_agent_operating_contract`, `ordinary_app_work`, and `tool_audience_boundaries`. The default lane is application agents building apps with read-only metadata first: read docs and MCP metadata, inspect safe route/config/storage/SQL/diagnostic metadata, plan app changes through app code, config, callbacks, dialbacks, plugins, MCP metadata, or application adapters, and use focused app/module verification. Dataphyre runtime-internal edits, `dataphyre_mcp_verify_all`, maintainer evidence, Dataphyre hot-path benchmarks, and unsafe MCP mode are not default app-agent requirements.

Startup payloads such as `dataphyre_application_info` expose `startup_safety` with `application_agent_operating_contract` plus `ordinary_app_work` for local capability selection. They also expose `copy_safe_startup_summary` so agents can share PHP/runtime/module-count/unsafe-state evidence and next read-only MCP tools without copying local root paths or raw git output. `dataphyre_application_catalog` is the focused app-path discovery surface; it reports local application candidates, detected roots, config file names, namespace hints, and confidence metadata without booting apps, dispatching routes, executing SQL, writing files, or reading config values. Treat root paths, git status text, branch names, dirty-file paths, and local app names as local-only diagnostics unless they are already public test fixtures; redact machine-local paths, tenant/customer/product names, and incident-like branch or file names before sharing output outside the local agent session.

Diagnostic payloads expose `diagnostic_safety` so application agents can share bounded summaries without guessing redaction policy. It classifies outputs as diagnostic metadata or redacted previews, names redaction targets such as secrets, signed URLs, tenant names, product identifiers, and machine-local paths, exposes compact `ordinary_app_summary` plus `boundary_refs` for the full audience contracts, and keeps heavier governance review for release-facing or public Dataphyre framework claims, corporate-ready or enterprise-readiness claims, security/identity/access/session/credential/governance/tenant/privacy/compliance/data-residency/retention/legal-hold/access-policy work, Dataphyre framework internals or reusable framework contributions, or shared production hot-path changes.

Tracelog and last-error payloads also expose `diagnostic_handoff`, a summary-first contract for agent handoffs, and `diagnostic_summary`, a concrete copy-safe evidence object built from already-redacted bounded diagnostics. The summary includes the surface, owner, finding, safe evidence counts/paths/severity where available, next read-only tools, and `diagnostic_next_action` statuses such as `inspect_redacted_artifact`, `inspect_redacted_matches`, `triage_redacted_error`, or `broaden_bounded_diagnostic_search`. Agents can pass `diagnostic_summary` between handoffs without copying raw logs, tenant/product identifiers, signed URLs, credentials, or machine-local absolute paths.

Verification metadata payloads expose `verification_safety` with compact `ordinary_app_summary` plus `boundary_refs` so agents can choose focused app/module checks without confusing catalogs with proof or inlining release-oriented audience contracts. They cover Flightdeck surface inventories, unit-test manifests, browser regression schemas, and the verification surface catalog; they do not execute helpers, launch browsers, dispatch routes, run SQL, write files, or prove application behavior.

The verification surface catalog exposes `verification_next_action`, focused `recommended_mcp_tools`, publication-only validation tools, and `verification_handoff`. Copy-safe handoff fields include tool, surface, concrete app paths or arguments, pass/fail summary, failing check names, and app-owned follow-up edits without raw logs, secrets, tenant/customer identifiers, release proof, `dataphyre_mcp_verify_all` output, or Dataphyre benchmark output. App-builder handoffs also expose `focused_completion_packet` so agents can close ordinary app work with one compact, copy-safe evidence packet.

Command-backed validation and release payloads such as `dataphyre_release_check`, `dataphyre_release_triage_summary`, `dataphyre_mcp_live_validate`, `dataphyre_mcp_verify_all`, and `dataphyre_mcp_doctor` expose `application_agent_operating_contract`, `ordinary_app_work`, and maintainer tool boundaries. Treat their evidence as maintainer proof for release, MCP wiring, or public framework claims, not as a routine requirement for application agents or proof of application behavior.

Use `dataphyre_mcp_status_board` when an agent needs a compact health and progress snapshot before deciding whether to inspect the full manifest or readiness report. Status snapshots include `app_builder_readiness`, compact `apply_readiness`, audience-tagged app-only `recommended_next_slices`, separate `publication_next_slices`, `publication_next_action`, collapsed `publication_or_framework_context`, `ordinary_app_work`, and an app-first verification policy so application agents start with `dataphyre_app_builder_plan_generate`, add task/start packs only as optional context, and do not treat MCP readiness as proof of application behavior or maintainer validation as routine app work.

Use `dataphyre_mcp_enterprise_adoption_audit` before describing a Dataphyre framework feature as enterprise-ready or agent-first. Pass the feature/module label, touched files, and release-facing flag; read `claim_summary` first to decide whether to claim readiness or report missing evidence.

The audit returns contract checklist status, runtime quality gate status, governance baseline checks, change classification, extension strategy, module evidence, portability signals, missing evidence, and next actions. `evidence_next_action` and `governance_next_action` narrow the next proof item so agents do not turn the whole audit into default app-work ceremony.

Governance checks cover tenant/application boundaries, access policy, audit evidence, redaction/data classification, and framework-versus-application verification ownership. Benchmarks are required only for Dataphyre shared production hot-path candidates; application changes and MCP/docs control-plane changes use focused verification instead, and released MCP guidance must not ask application agents or released installs to run benchmark tooling.

Use `dataphyre_mcp_capability_matrix` when preparing release notes, client
capability summaries, or public documentation that should be derived from live
tool registration. Its `ordinary_app_work` boundary keeps application agents on
app-owned extension points and focused app/module checks. The matrix exposes
`verification_lanes` plus per-family `audience_lanes`: ordinary app work lists
focused checks such as PHP lint, Panel regression, Panel field catalog checks,
and the verification surface catalog; publication validation lists release
checks, live MCP validation, `dataphyre_mcp_verify_all`,
`dataphyre_mcp_doctor`, and `dataphyre_mcp_docs_coverage_report`. These lanes
include `tool_boundaries` beside their flat `tools` arrays, so agents can
distinguish focused app/module evidence from `not_app_behavior_proof`
publication/setup diagnostics without opening the full readiness report. Family
`verification` rows are ordinary/focused checks; `publication_validation` rows
name maintainer evidence for MCP or release-surface claims. Its
enterprise verification policy names `dataphyre_mcp_verify_all` as a bounded
first-party command bundle for MCP/release-surface claims, not a replacement for
focused runtime behavior tests. Its app-first verification policy repeats that
capability readiness is not proof of application behavior.

Use `dataphyre_mcp_release_notes_generate` when a maintainer, client author, or agent needs a markdown release summary backed by the current status board, capability matrix, and readiness report. Generated notes cover enterprise readiness, governance baseline, Dataphyre-only hot-path benchmark scope, app-builder/apply readiness, app-first verification, the Application-Agent Default Lane, `tool_audience_boundaries`, `publication_next_action`, and verification claim boundaries.

Agent-facing notes keep ordinary app work lightweight: first call `dataphyre_app_builder_plan_generate`, read `builder_response.first_read`, add `dataphyre_task_pack_generate payload_profile=builder` only when focused module docs are needed, use `dataphyre_mcp_agent_brief_export` for compact cold starts or handoffs, and use `dataphyre_mcp_task_start_pack_export payload_profile=builder` only when broader bounded workflow context is needed. Maintainers are pointed to `dataphyre_mcp_enterprise_adoption_audit` before agent-first, corporate-ready, public, or release-facing Dataphyre framework claims.

Use `dataphyre_mcp_surface_changelog` when a maintainer, client author, or agent needs a compact changelog-style snapshot of current MCP surface counts, client helpers, validation tools, safety-denied surfaces, default-lane guidance, ordinary app-work boundary, and recommended follow-up checks. Agent highlights tell ordinary app agents to first copy `dataphyre_app_builder_plan_generate` and open task packs, start packs, or briefs only as optional context. Changelog validation separates ordinary local validation from publication validation so application agents do not treat `dataphyre_mcp_verify_all` as a default app-work requirement.

Release repair payloads expose `application_agent_operating_contract` plus `ordinary_app_work` when they produce read-only release-fix plans. Treat `dataphyre_release_triage_summary` and `dataphyre_release_fix_plan` as maintainer/release-surface planning for release-check output, not as routine application verification or a reason to run publication validation during normal app work.

Use `dataphyre_mcp_tool_call_examples_export` when a client or agent needs concrete `tools/call` request payloads for app-building, docs, routes, SQL planning, diagnostics, client setup, safety, or validation workflows. The `workflow: app` examples include `workflow_policy.app.first_copy=dataphyre_app_builder_plan_generate`, `optional_context=dataphyre_task_pack_generate payload_profile=builder`, `compact_handoff=dataphyre_mcp_agent_brief_export`, and `broader_cold_start=dataphyre_mcp_task_start_pack_export payload_profile=builder`, so ordinary app agents can copy the smallest useful call before opening broader context.
App examples demonstrate nested per-entity fields plus bounded `required`/`options`/`default`, explicit `foreign_key_target` relationship metadata, non-relationship identifiers such as `external_id:"string nullable not a foreign key"`, JSON fields, and phrase-style required/nullable/enum/default/foreign-key hints, then point agents at compact `field_metadata_summary`, `panel_fields[].field_metadata`, `filters[].filter_metadata`, app-owned Panel array field/filter definitions, and full-profile `data_model_handoff` only when TableSchema/repository detail is the next step. App examples mark task packs, compact briefs, start packs, and detail profiles as optional context rather than mandatory startup ceremony.

Use `dataphyre_mcp_workflow_playbook_export` when a client or agent needs ordered read-only workflow steps for feature planning, route inspection, SQL inspection, diagnostics triage, client setup, or release validation. The feature playbook starts with `dataphyre_app_builder_plan_generate` for ordinary app work, then searches focused Panel/SQL app resource docs, loads `dataphyre_task_pack_generate payload_profile=builder` only when module-doc context is useful, and chooses focused verification surfaces. It does not search guideline material as a normal feature-planning step.

Use `dataphyre_mcp_workflow_readiness_audit` when a client or agent needs to confirm a workflow playbook has registered tools, registered prompts, matching examples, and documented public surfaces before following it.

Tool-call examples and workflow playbooks default to application agents building apps, so read them as metadata-first orchestration for app-owned changes. `workflow: app` examples keep the builder lane lightweight with `governance_notes` and `context_links`, then send agents to `dataphyre_app_builder_plan_generate` before optional context.

Deeper handoff/session exports and release playbooks remain explicit orchestration or publication-validation surfaces. Validation examples carry `audience_scope`, and any validation or release example that mentions `dataphyre_mcp_verify_all` is MCP/release-surface guidance, not routine app verification.

Use `dataphyre_mcp_workflow_session_export` when a client author needs an ordered initialize/tools-list/tools-call message sequence for a ready workflow without executing it.

Use `dataphyre_mcp_workflow_transcript_schema_export` when a client author needs a safe response-capture format for workflow transcripts, including redaction rules and summary fields.

Use `dataphyre_mcp_workflow_state_schema_export` when a client needs a compact client-owned state envelope for carrying task, workflow, phase, decision, transcript, and checkpoint references between agent turns.

Use `dataphyre_mcp_workflow_state_audit` when a client or agent needs to check client-owned workflow state for required fields, registered tool references, phase and decision names, checkpoint status, and secret-looking residue before sharing it.

Use `dataphyre_mcp_workflow_state_summary_export` when a client or agent needs a compact progress handoff with state id, task summary, phase, decision, checkpoint references, pending tools, completed tools, and audit status.

Use `dataphyre_mcp_workflow_state_transition_export` when a client needs a read-only suggested patch for its own workflow state after next-action guidance; the server does not persist or apply the patch.

Use `dataphyre_mcp_workflow_state_sync_pack_export` when a client needs one portable state continuity payload containing schema rules, audit findings, safe handoff summary, suggested patch, and next-action guidance.

Use `dataphyre_mcp_workflow_state_timeline_export` when a client or agent needs a quick phase-by-phase continuity map from client-owned workflow state without reading the full sync pack.

Use `dataphyre_mcp_workflow_state_resume_brief_export` when a new agent needs the shortest safe resume packet: status line, task summary, current phase, next tool, client patch, and links back to deeper state tools.

Use `dataphyre_mcp_workflow_transcript_audit` when a client or agent needs to check a captured workflow transcript for required fields, registered tool references, status values, and secret-looking residue before sharing it.

Use `dataphyre_mcp_workflow_transcript_summary_export` when a client or agent needs a compact transcript handoff with step summaries, audit status, result keys, and safe next tools. Transcript summaries and checkpoints are step-windowed by default (`max_summary_steps` defaults to 20, hard capped at 50) and report omitted steps in `step_window`.

Use `dataphyre_mcp_workflow_checkpoint_export` when a client or agent needs progress counts, checkpoint status, safe handoff flags, and next actions from a captured workflow transcript.

Use `dataphyre_mcp_workflow_handoff_pack_export` when a client author wants one pre-run payload containing the workflow playbook, readiness audit, ordered session messages, transcript schema, and post-run transcript tools.

Use `dataphyre_mcp_workflow_catalog` when a client or agent needs to choose among available workflows before exporting a full handoff pack.

Use `dataphyre_mcp_workflow_lifecycle_export` when a client or agent needs the full workflow lifecycle from task start, recommendation, handoff, client run, transcript capture, audit, checkpoint, summary, and verification. Its verify phase points to `dataphyre_verification_surface_catalog` for focused application or module verification. Aggregate MCP proof such as `dataphyre_mcp_verify_all` remains in `publication_validation` with the `publication_validation_not_ordinary_app_work` audience label, not the ordinary verify phase.

Use `dataphyre_mcp_workflow_next_action_export` when an agent has task text, a captured transcript, or client-owned workflow state and needs a machine-readable decision for whether to start, review, fix, summarize, verify, or finish.

Use `dataphyre_mcp_workflow_recommend` when a client or agent has task or symptom text and needs ranked workflow choices plus handoff arguments. For ordinary app-building tasks it also returns `app_builder_entrypoint` and compact `app_builder_next_action`, so agents can call `dataphyre_app_builder_plan_generate`, pass explicit `entities`, `fields`, and `max_entities` when known, follow `entity_planning.continuation_calls` until `deferred_entities` is empty, read `builder_response.first_read` before any wider context, and open only the page named by `builder_response.first_read.next_detail_page` for the next app-owned planning, implementation, verification, or controls step.

For app-building workflow routing, `governance_notes.status=app_owned_policy_attention` is a lightweight signal when task text names billing, subscription, entitlement, workspace/tenant scope, webhook, audit, retention, or regulated-data concepts. It keeps `next_tool=dataphyre_app_builder_plan_generate` and points agents to `builder_response.data_sensitivity_summary` plus `policy_decision_register` instead of turning ordinary SaaS scaffolds into enterprise audits.

Agents should follow `builder_response.first_read.scaffold_completion_summary.next_continuation` or `entity_planning.continuation_calls` until `deferred_entities` is empty before opening broader workflow context; `app_builder_next_action` names `decision_field=builder_response.first_read.next_action`, mirrored compact next-action fields, allowed write-readiness statuses, compact handoff pages, and focused verification handoff to preserve while continuing.

Use `dataphyre_mcp_workflow_recommendation_handoff_export` when a client or agent wants one response that ranks workflows and points to the top workflow handoff. For ordinary app-building handoffs, the payload propagates the same compact `app_builder_next_action` continuation, `decision_field=builder_response.first_read.next_action`, write-readiness, focused verification contract, `boundary_refs`, and `handoff_pack_ref` instead of inlining runnable workflow session messages. Fetch the referenced handoff pack only when a client is ready to run workflow messages; non-app, release-facing, or escalated handoffs can still inline the relevant handoff and boundary contracts. This keeps selected workflows from hiding unfinished entity chunks or turning ordinary app proof into release validation.

Workflow checkpoints, resume briefs, handoff packs, and lifecycle payloads keep ordinary application workflow verification focused on the application or affected module. Machine-readable `pending_tools`, `next_tools`, and `follow_up_tools` stay app-first so agents do not inherit maintainer gates from normal handoffs.

Compact `continuity_policy` tells resumed agents to continue from audited state, checkpoints, summaries, result keys, and `next.tool`/`next.arguments` before opening full transcripts; share `diagnostic_summary.copy_safe_evidence` instead of raw logs; and keep `dataphyre_mcp_verify_all`, hot-path benchmarks, and runtime-internal edits out of ordinary app handoffs.

`copy_safe_resume` and `ordinary_app_work` keep handoffs copy-safe and application-owned. When a payload must name aggregate MCP proof, it uses `publication_validation` or an explicit MCP/release-surface field, not routine proof for app behavior.

Workflow continuity payloads expose `application_agent_operating_contract`, `ordinary_app_work`, and `tool_audience_boundaries` across the session, transcript, state, audit, summary, transition, sync, timeline, resume, checkpoint, handoff, catalog, lifecycle, next-action, recommendation, task-start, and compact-brief surfaces.

This keeps mid-task handoffs aligned with the Application-Agent Default Lane: client-owned state, redacted summaries, app-owned extension points, and focused app/module verification unless the task explicitly becomes release-facing, corporate-ready, governance-sensitive, Dataphyre framework/internal, reusable-framework, or shared hot-path work.

Workflow recommendations and next-action payloads include `tool_audience_boundaries`, and include `enterprise_preflight` when task text implies enterprise, corporate, public, agent-first, or release-facing claims. Treat preflight as a read-only prompt to run `dataphyre_mcp_enterprise_adoption_audit` and confirm maintainer runtime quality evidence before making those claims; it does not execute application code and does not add benchmark requirements for applications.

Use `dataphyre_mcp_task_start_pack_export` when an agent is beginning from task text and needs compact direction before deeper context.

- `payload_profile: builder` starts build-shaped tasks with `builder_first_read`, one compact `builder_response`, and `context_policy` links to omitted detail. Start-pack `payload_profile: detail` adds contracts and full discovery metadata, but still omits top-level `builder_view`, `builder_start`, and `app_builder_lane`; open app-builder detail pages for planning, implementation, verification, or controls. Task packs omit `builder_view`, `builder_plan`, `app_builder_lane`, `app_builder_summary`, and raw `handoff_fields`.
- Read-only inspection, routing, diagnostics, and discovery tasks start with active `inspection_view`; `builder_view` remains inactive until the task becomes build/scaffold work.
- `next_action`, `next_detail_page`, `field_metadata_summary`, `write_readiness`, `diagnostic_handoff_hint`, and compact collapsed-detail refs mirror the direct app-builder lane without duplicating full skeleton bodies or full execution handoffs; `detail_pagination` stays supporting metadata for the chosen page rather than first-read work.
- Compact builder payloads expose `semantic_contract` to distinguish machine-usable contracts from paginated bodies: continuation calls, scaffold continuation state, field metadata, write readiness, and focused verification handoffs stay readable in compact mode, while larger file/schema/implementation/acceptance bodies may move behind detail refs.
- Use `dataphyre_app_builder_plan_generate` with `payload_profile=compact` and `detail_page=planning|implementation|verification|controls|governance` to materialize one named detail page without opening the full builder plan. Use `payload_profile=full` only when skeleton bodies or cross-page context are needed.
- `payload_profile: detail` opens full contracts/discovery. `payload_profile: deep` opens inline status, safety, enterprise, and workflow handoff context.
- Proportional guidance identifies release-facing/public framework claims, corporate-ready claims, security/governance-sensitive work, Dataphyre internals or reusable framework contributions, and shared production hot-path changes, but start-pack `detail`/`deep` remains an explicit caller choice.

Task packs and docs chunk exports keep requested module documentation balanced before broader reference or governance docs. Builder-profile task packs omit MCP guideline chunks entirely, skip status/audit docs such as capability audits, and rank construction-oriented module sections first, so ordinary Panel plus SQL app tasks spend the bounded payload on concrete Resource, Schema, Table, RepositoryQuery, and Scaffolding context instead of policy material. Governance-profile task packs can include broader governance context when explicitly requested or escalated.

Use `dataphyre_mcp_agent_brief_export` when an agent needs compact task direction without the full start-pack payload. For app-building tasks, the brief is a direct first-page app-builder fast lane: it exposes `builder_first_read`, compact `app_builder_next_action`, at most two next actions, context links, and optional policy attention without assembling start-pack discovery, workflow handoff, pagination maps, payload-budget metadata, or governance context first. Top-level `app_builder_next_action` stays glanceable with status/action/tool plus refs such as `resume_cursor_ref`, `copy_forward_count`, and `handoff_pages_ref`; the actionable cursor stays in `builder_first_read.next_action`. Open `dataphyre_app_builder_plan_generate payload_profile=compact detail_page=<page>` for the next planning, implementation, verification, controls, or governance page; use a start/task-pack detail profile only when broader context is needed.

For app-building tasks, active first-read builder payloads carry the immediate `next_action`, file/schema summaries, naming contract, write readiness, scaffold completion, verification handoff, and links to detail pages. Read-only discovery starts from active `inspection_view` instead.

The top-level `app_builder_next_action` is the compact resume pointer: call `dataphyre_app_builder_plan_generate`, follow `entity_planning.continuation_calls`, use `resume_cursor_ref` to read the richer cursor in `builder_first_read.next_action`, stay on `builder` by default, open `detail` or `deep` only for broader context, and request task-pack `governance` only when inline extension/publication guardrails are needed. Generic workflow recommendation payloads expose `resume_cursor_source`; compact briefs keep full cursor bodies out of the top-level mirror.

Generated agent context, task packs, task start packs, and compact briefs include the Application-Agent Default Lane. Ordinary app-start payloads keep contracts collapsed until `payload_profile: detail`, `include_detail_context`, or an explicit escalation decision asks for them; start-pack detail profiles add those contracts without re-inlining full builder lanes, and `payload_profile: deep` is reserved for explicit escalation evidence.

Task packs support `payload_profile: builder` for ordinary app work and `payload_profile: governance` for inline extension/publication guardrails. Builder/start/brief surfaces use progressive disclosure: build-path details first, optional skeleton detail later, and governance/publication validation collapsed unless explicitly requested for an escalation decision.

Task packs prioritize practical module documentation before MCP governance guidelines, so app builders get Panel/SQL/routing construction context before policy context.

Use `dataphyre_mcp_tool_finder` when a client or agent needs to discover the best registered tools for a task before requesting full schemas from the manifest.

Use `dataphyre_mcp_resource_finder` when a client or agent needs to find relevant resources or workflow prompts before calling `resources/read` or `prompts/get`.

For ordinary app work, resource discovery is module-first. App-building or Panel/SQL queries can return `documentation` matches for local module docs such as Panel and SQL paths; read those with `dataphyre_read_doc`. Core governance resources like `dataphyre://ai-guidelines` remain available, but should not outrank concrete module docs for ordinary app-building searches.

For ordinary app-building wording such as "build an internal admin app," "create a Panel resource," or "add CRUD with filters," discovery leads with `dataphyre_app_builder_plan_generate`.

`dataphyre_mcp_tool_finder` ranks that tool first and exposes `recommended_first_call` with `payload_profile=compact`, `decision_field=builder_response.first_read.next_action`, and optional arguments for explicit `entities`, nested `fields`, and `max_entities`. It points agents to `payload_profile=full` only when they are ready to adapt app-owned skeletons.

Tool-finder matches include `audience_scope`, `tool_boundary`, and `use_policy`. Read `matches[*].use_policy.call_when` and `default_action` before calling a matched tool: app-builder and read-only inspection matches can be used for ordinary app work, focused verification stays app/module scoped, and publication/client-setup tools stay collapsed unless the task is release, MCP setup, security, governance, or Dataphyre-internal.

`dataphyre_mcp_resource_finder` keeps module documentation first for app-building searches, but it also exposes the same `recommended_first_call` to `dataphyre_app_builder_plan_generate` with `payload_profile=compact`. Treat matched Panel/SQL docs and workflow prompts as read-only context after the builder plan, not as a reason to skip the planner.

Resource-finder matches include `audience_scope` and `open_policy`. Read `matches[*].open_policy.open_when` before opening a resource or prompt: focused module docs open after the compact builder first read points there, baseline app guidance is safe read-only context, and agentic-enterprise/runtime/release resources stay collapsed unless their escalation policy matches the task.

`dataphyre_mcp_workflow_recommend`, tool-call examples, and workflow playbooks repeat the same compact app-builder resume contract: `builder_response.first_read.next_action`, `builder_response.first_read.next_detail_page`, write-readiness status, and chunked continuation instructions before broader workflow context. Detailed app-owned evidence and policy fields stay behind the single builder detail page that first-read points to.

Tool and resource finder payloads are lightweight discovery surfaces: they
return ranked matches, `governance_notes`, `discovery_contract`, and
`context_links` instead of inlining full application-agent contracts or
tool-audience boundaries. Finder `context_links` keep ordinary app guidance
local with `application_agent_default=discovery_contract`,
`ordinary_app_work=discovery_contract.compact_fields`, and
`tool_audience_boundaries=discovery_contract.not_ordinary_app_ceremony`;
`escalation_readiness_report` is the explicit pointer to the heavier readiness
report. For app-builder discovery, `discovery_contract.compact_fields` points
to `extension_boundary_summary.placement_decision`,
`prewrite_checklist.prewrite_blockers`,
`prewrite_checklist.implementation_obligations`,
`diagnostic_summary.copy_safe_evidence`, and `copy_safe_resume` while keeping
`governance_inline=false`. Use found tools, resources, and prompts before
proposing app-owned edits or extensions; open the readiness report or
enterprise audit only when the task is release-facing/public framework claims,
corporate-ready or enterprise-readiness claims,
security/identity/access/session/credential/governance/tenant/billing/privacy/
compliance/data-residency/retention/legal-hold/access-policy work, Dataphyre
framework internals or reusable framework contributions, or shared production
hot-path changes.

Compact start-pack `tool_matches` and `resource_matches` preserve a useful discriminator for each match, such as group, kind, description, module, path, fetch tool, or match reasons, so agents can choose the next read without opening the full manifest first.

Use `dataphyre_mcp_docs_coverage_report` after adding public MCP surfaces so docs, prompts, resources, and safety boundaries stay in sync with live registration. Its payload exposes `application_agent_operating_contract`, `ordinary_app_work`, `tool_audience_boundaries`, and `publication_validation`; treat it as MCP/release-surface documentation evidence, not a routine app-agent requirement or proof of application behavior.

Use `dataphyre_mcp_readiness_report` when an agent or client author needs one JSON planning snapshot for MCP/release-surface readiness. It maps implemented tools to the agentic capability roadmap, highlights intentionally missing unsafe surfaces, names enterprise-readiness gates, and gives next useful server capabilities without hardcoding application paths.

The report is an index of contracts, not routine app proof. It covers:

- `transport_and_filesystem_boundary`: `Content-Length` JSON-RPC support, parse-error recovery, invalid-request handling for non-object or methodless JSON-RPC messages, missing-header and oversized-frame handling, and `safe_repo_path` root-or-child checks that reject sibling-prefix escapes.
- `app_builder_readiness`: app-builder entrypoint, compact start/task/brief contracts, field metadata, naming, chunking, scaffold completion, recovery hints, app-contract summary, extension boundary, verification evidence, data sensitivity, code skeleton summary, `prewrite_checklist`, and compact handoff.
- `apply_readiness`: read-only apply audit/readiness surfaces, no default write-capable apply runner, and `apply_next_action` for future app-owned placement or framework-change escalation.
- `diagnostic_handoff_policy`: share `diagnostic_summary.copy_safe_evidence` instead of raw logs, secrets, tenant/product identifiers, local usernames, or machine-local paths.
- `agent_workload_policy`: inline compact app-builder essentials for ordinary work, keep full contracts/status/safety/enterprise/publication validation linked or collapsed until explicitly requested for an escalation decision, and treat `dataphyre_mcp_verify_all`, Dataphyre hot-path benchmarks, and runtime-internal edits as not ordinary app ceremony.
- `recommended_next_slices`, `publication_next_slices`, and `publication_next_action`: app agents start with `dataphyre_app_builder_plan_generate payload_profile=compact`; publication validation stays maintainer/client-author work and does not prove ordinary application behavior.

The app-first verification policy separates focused application/module proof, ordinary client setup validation, MCP/release-surface verification, and Dataphyre-only hot-path benchmark evidence. It explicitly excludes `dataphyre_mcp_verify_all`, maintainer release proof, and benchmark output from ordinary application behavior evidence.

The readiness prewrite-checklist contract separates hard gates in `prewrite_blockers` from app-owned `implementation_obligations` and procedural `prewrite_reminders`; agents complete obligations and reminders during app-owned edits while using `ready_to_write` as the compact gate. `sensitivity_gate_policy` explains whether sensitive fields are ordinary implementation obligations or elevated hard blockers, and `resolution_plan` maps checklist items back to concrete builder fields plus acceptable app-owned resolutions.

The app-builder readiness chunking contract includes `entity_planning.dependency_summary` and continuation-call `dependency_context`, so clients can treat cross-chunk relationship stitching as a supported app-builder contract.

The readiness report also exposes an app-contract-summary contract proving that first-read and compact app-builder payloads carry `app_contract_summary` without opening governance-heavy audits.

Use `dataphyre_mcp_live_validate` after MCP surface changes when a maintainer or client author needs to verify stdio framing, tool registration, prompt registration, resource registration, doctor output, prompt catalog output, capabilities resources, and warning-free stderr behavior through a spawned server process. Spawned command stdout/stderr returned through MCP is marked `redacted=true` and filtered for credential, signed URL, tenant/customer/product, and machine-local path patterns before it becomes copyable evidence.

Use focused application or module verification for app behavior. Use `dataphyre_mcp_verify_all` only for MCP/release-surface claims or after larger MCP slices when a single tool should run lint, live stdio validation, the full MCP self-test, doctor, and app-coupling guard checks. Its enterprise verification policy limits execution to bounded first-party commands, keeps route dispatch and SQL execution out of scope, and reminds agents that passing aggregate MCP verification supports MCP/release-surface claims rather than replacing focused module behavior tests.

## Agentic Capability Roadmap

## Client Configuration

Point MCP clients at the stdio command from the project root. Example manual JSON shape:

```json
{
  "mcpServers": {
    "dataphyre": {
      "command": "php",
      "args": [
        "common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php"
      ]
    }
  }
}
```

Use `dataphyre_mcp_client_config_summary` to export the same portable JSON shape for a target client. Source-checkout helper scripts are maintainer conveniences only; they are not release artifacts and should not be required of application agents.

For tools that may start local services or write smoke reports, add `--allow-unsafe`:

```json
{
  "mcpServers": {
    "dataphyre": {
      "command": "php",
      "args": [
        "common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php",
        "--allow-unsafe"
      ]
    }
  }
}
```

`dataphyre_mcp_client_config_summary` accepts the same unsafe profile flag when a maintainer intentionally needs unsafe-gated MCP tools.

### Foundation

- Keep MCP route-free and app-embeddable, like Panel and Routing.
- Use stdio first; add Streamable HTTP only after auth and CSRF boundaries are explicit.
- Default to read-only tools. Require `--allow-unsafe` or `DATAPHYRE_MCP_ALLOW_UNSAFE=1` for mutating or query-executing tools.
- Redact config values by default. Expose key names and source paths first, then add allowlisted value reads.

### Tool Roadmap

1. Application intelligence:
   - Runtime version, PHP version, enabled modules, application roots, bootstrap mode, loaded app name.
   - Composer/package metadata when present now.
   - Static runtime bootstrap, module version-file, and bundled package version metadata now.
   - Static API endpoint and OpenAPI surface summaries now.
   - API endpoint and OpenAPI scaffold planning now.
   - API implementation recipe catalog now.
   - Static API endpoint cache and clear-cache contract summary now.
   - Static OpenAPI generator and documentation route contract summary now.
   - Public PHP source API summaries now; Datadoc SQL-backed indexes later.

2. Documentation API:
   - Local docs search now.
   - Generated module knowledge packs from `documentation/*.md`, `docs/MODULES.md`, and Datadoc records.
   - Semantic-ready local markdown chunk export now.
   - Static Datadoc index, tokenizer, SQL table, and UI contract summary now.
   - Optional remote Dataphyre documentation index later.

3. Routes and URLs:
   - Parse compiled route manifests and Routing module declarations.
   - Absolute URL preview with caller-provided HTTP(S) base URL now.
   - Include route names, methods, middleware, controller actions, and source file references.
   - Machine-readable `route_safety` metadata now marks route/API inspection as app-planning metadata without dispatch.
   - Static source-level route declaration provenance now.
   - Static MVC controller action provenance now.
   - Static middleware declaration and alias provenance now.
   - Static MVC config contract summary now.
   - Static MVC route cache CLI and manifest-cache planning summary now.

4. Database and schema:
   - List configured SQL clusters without credentials.
   - Read table definitions through `Dataphyre\Database\DB::definition()` and `TableSchema`.
   - Add read-only query execution behind explicit unsafe opt-in, max-row limits, and SQL verb guardrails.

5. Storage:
   - Summarize storage disk config keys and available driver classes now.
   - Catalog Storage driver classes and contract coverage now.
   - Do not list buckets, read files, generate temporary URLs, or perform writes from MCP.

6. Diagnostics:
   - Dpanel module scans.
   - Flightdeck last request snapshot and browser event summaries.
   - Flightdeck surface inventory now.
   - Tracelog artifact discovery, redacted preview reading, and bounded search now.
   - Dpanel JSON unit-test manifest discovery and summary now.
   - Tracelog search and last-error retrieval.
   - Panel regression and static browser regression manifest summaries now.

7. Code generation helpers:
   - Panel resource scaffolding plans now.
   - Routing controller/action scaffolding plans now.
   - API endpoint/OpenAPI scaffolding plans now.
   - SQL table artifact scaffolding dry-runs now.
   - MVC controller and runtime module scaffolding plans now.
   - Static Panel scaffolding and package-template catalog now.
   - Static Panel package ecosystem manifest summary now.
   - Static Panel theme ecosystem manifest summary now.
   - Static Panel documentation catalog manifest summary now.
   - Static Panel media/upload manifest summary now.
   - Storage/API module recipes later.

8. Agent context:
   - Generate Codex/Claude/Cursor guideline file content from Dataphyre module docs now.
   - Generate task-specific prompt packs with docs chunks, guardrails, and verification now.
   - Keep generated instructions versioned and refreshable.

## Security Model

- Path access is limited to the current workspace and shared Dataphyre runtime root.
- Config tools expose keys, not values.
- Config shape readers report key paths and redaction flags, not values.
- Command tools are allowlisted and use argument arrays.
- Database query execution is not enabled in the initial server.
- Future mutating tools must provide dry-run mode, explicit opt-in, output limits, and audit logs.

## Worker Backlog

- Worker A: route and URL inspector over Routing manifests and compiled route cache.
- Worker B: SQL schema inspector using `DB`, `TableDefinition`, and `TableSchema` without exposing credentials.
- Worker C: Dpanel/Flightdeck diagnostic adapters with bounded output and JSON summaries.
- Worker D: Datadoc-backed documentation index and semantic-ready chunk export.
- Worker E: agent guideline/skill generator for Codex, Claude Code, Cursor, and manual MCP config.
- Worker F: unsafe tool gate for read-only SQL query execution, tinker-style eval alternatives, and scaffolding apply workflows.

## Verification

Run syntax checks:

```powershell
php -l common\dataphyre\runtime\modules\mcp\kernel\dataphyre_mcp.php
php -l common\dataphyre\runtime\modules\mcp\kernel\mcp.main.php
```

Smoke-test with an MCP initialize frame:

```powershell
$body = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
$length = [System.Text.Encoding]::UTF8.GetByteCount($body)
$frame = "Content-Length: $length`r`n`r`n$body"
$frame | php common\dataphyre\runtime\modules\mcp\kernel\dataphyre_mcp.php
```

Before publishing MCP surface changes, confirm MCP self-test evidence. Do not turn MCP publication validation into ordinary app-agent setup work.

Run live stdio validation through a spawned MCP process:

Use `dataphyre_mcp_live_validate` where available, or collect live validation evidence.



