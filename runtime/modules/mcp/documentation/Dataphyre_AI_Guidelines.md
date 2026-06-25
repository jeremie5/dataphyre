# Dataphyre AI Guidelines

These guidelines are for AI coding agents working inside Dataphyre applications. The default MCP use case is building and maintaining applications with Dataphyre; framework-maintainer guidance applies only when the task explicitly touches Dataphyre internals, release surfaces, or shared hot paths.

## Application-Agent Default Lane

- Start with docs, registered MCP metadata, safe route/config/storage/SQL and diagnostic metadata, and dry-run plans.
- For ordinary app creation, Panel CRUD, resources, schemas, filters, actions, or verification work, start with `dataphyre_app_builder_plan_generate` using `payload_profile=compact`; use `dataphyre_task_pack_generate` when broader but still builder-first context is needed.
- Use `entity_input_contract` to distinguish explicit app model input from inferred prose entities; confirm or override inferred entities before broad multi-resource writes.
- Use `scaffold_completion_summary` before handoff or writes; a chunked scaffold is complete only when `complete=true` and no deferred entities remain. When present, `scaffold_completion_summary.next_continuation` is the compact pointer to the next app-builder call; use it before opening full workflow handoff context.
- Use `data_sensitivity_summary` for schema-derived credential, identity/contact, billing/financial, tenant/access, or regulated-data field hints before writing app-owned storage, access, redaction, and validation behavior.
- Use `relationship_contract_summary` for planned targets, external references, and cross-chunk relationship hints before writing app-owned repository/query adapters or relation UI.
- Use `app_contract_summary.decision_prompts` for schema-aware app-owned ownership, tenant/workspace, audit, lifecycle, and relationship policy choices before opening governance-heavy audits.
- Use `policy_decision_register` as the compact app-owned decision list before writes; it turns contract prompts and sensitivity hints into required ownership, tenant/workspace, audit, lifecycle, relationship, and redaction decisions without requiring an enterprise audit for ordinary app policy choices.
- Treat generated `code_skeletons` as read-only previews; use compact `code_skeleton_summary.sensitive_field_policy.path_reasons` first, then full skeleton `adaptation_notes` and `sensitive_field_policy` when writing app-owned query adapters, namespaces, manifests, SQL config registration, sensitive field handling, and regression checks.
- Use `write_plan_summary` to sequence app-owned data model artifacts, Panel resources, manifests, regression manifests, and focused verification before opening full skeleton bodies.
- Use `verification_evidence` to keep focused app/module proof summaries for concrete app paths without collecting maintainer release evidence.
- Use `verification_handoff` after focused checks to share copy-safe completion evidence: tool names, concrete app paths or arguments, pass/fail summaries, failing check names, and app-owned follow-up edits. Do not include raw logs, secrets, tenant/customer identifiers, maintainer release proof, or Dataphyre benchmark output.
- Use `diagnostic_handoff_hint` only after a focused app/module check fails; copy `diagnostic_summary.copy_safe_evidence` with `verification_handoff` instead of raw logs, maintainer proof, aggregate MCP validation, or benchmark output.
- Run `prewrite_checklist` before writing app-owned files; it separates hard gates in `prewrite_checklist.prewrite_blockers` from app-owned relationship, field metadata, and contract work in `prewrite_checklist.implementation_obligations`, plus procedural `prewrite_reminders` such as adaptation notes, app-owned boundaries, and focused verification. Use `ready_to_write` as the compact machine-readable gate without opening governance.
- Before any future caller-owned write-capable apply workflow, use `app_builder_next_action.apply_audit_handoff` to call `dataphyre_apply_audit_plan` after `write_readiness.status=ready_for_app_owned_writes`; this is app-owned write preflight, not maintainer release validation.
- For larger app scaffolds, follow `entity_planning.continuation_calls` until no `deferred_entities` remain; continuation calls are intended to be copied as executable planner calls and may carry chunk-scoped nested fields plus `dependency_context` for cross-chunk relationships.
- Put application behavior in app code, install config, callbacks, dialbacks, plugins, MCP metadata, or application-owned adapters before proposing Dataphyre runtime-internal edits.
- Verify app behavior with focused application or module checks.
- Do not treat `dataphyre_mcp_verify_all`, maintainer evidence, hot-path benchmarks, or unsafe MCP mode as default app-agent requirements.

## Runtime Shape

- Treat Dataphyre as a modular PHP runtime, not as a single routed application.
- Runtime modules live under `common/dataphyre/runtime/modules/<module>`.
- Use `common/dataphyre/docs/AGENTIC_ENTERPRISE.md` as the high-level contract
  for agent-first corporate work: extension boundaries, MCP safety, release
  hygiene, and benchmark expectations.
- Prefer module `Framework/` classes for public contracts and `kernel/` files for bootstrap or compatibility surfaces.
- Keep reusable framework work route-free unless a module explicitly owns routing.
- Avoid product-specific paths, URLs, names, credentials, and assumptions in shared Dataphyre runtime code.

## Editing Rules

- Read the module documentation before changing a module.
- Use docs packs or docs chunk export when an agent needs broad module context.
- Use docs index plans when clients need local markdown chunks, optional remote documentation templates, semantic-search metadata, refresh triggers, and embedding payloads without MCP network calls or index writes.
- Use Datadoc runtime readiness plans before discussing future Datadoc SQL-backed readers; default MCP workflows must not query Datadoc records, dispatch Datadoc UI routes, or execute tokenizers/highlighters.
- Use dependency maps before changing shared modules with unclear contracts or bootstrap dependencies.
- Use OpenAPI runtime readiness plans before discussing future runtime OpenAPI readers; default MCP workflows must not bootstrap applications, dispatch routes, or generate OpenAPI at runtime.
- Use route runtime provenance plans before discussing future runtime route readers; default MCP workflows must not bootstrap applications, dispatch routes, execute middleware, invoke controllers, or write route cache files.
- Use SQL runtime readiness plans before discussing future read-only SQL runners; default MCP workflows must not connect to databases, execute SQL, hydrate schemas, or expose credentials.
- Use browser diagnostics readiness plans before discussing future browser runners; default MCP workflows must not launch browsers, start servers, send HTTP requests, click UI, or write browser reports.
- Use apply runtime readiness plans before discussing future write-capable apply runners; default MCP workflows must not write files, run commands, mutate git state, or revert user changes.
- Use runtime version summaries when an agent needs bootstrap, module, or bundled package version metadata; do not bootstrap Dataphyre just to read version files.
- Use MCP manifest export when a client needs a stable tool, prompt, resource, and safety snapshot instead of scraping protocol listings.
- Use prompt pack export when a client needs reusable Dataphyre workflow prompts in one read-only bundle.
- Use MCP prompt catalogs to choose workflow prompts by theme and related tools before fetching prompt text.
- Use MCP skill catalogs when clients or agents need target-aware Dataphyre skill discovery without scraping manifests.
- Use MCP skill manifests when client authors need portable skill registration metadata without writing files.
- Use MCP skill registration audits before publishing skill packs so related MCP surfaces and product-neutral metadata are checked.
- Use MCP skill packs when clients need copyable Codex, Claude, Cursor, or generic skill instructions without installation side effects.
- Use MCP skill install plans when clients need target-aware registration steps, proposed skill file templates, rollback notes, and verification guidance without server-side writes.
- Use MCP smoke-test exports when documenting client setup so stdio frames, server paths, and expected responses stay consistent.
- Use client onboarding packs when a client author needs one portable setup payload instead of separate config, prompt, manifest, and smoke-test calls.
- Use MCP client troubleshooting when setup symptoms mention timeouts, Content-Length framing, missing tools, wrong working directory, PHP binary problems, or unsafe expectations.
- Use the client compatibility matrix when documenting target-specific Codex, Claude, Cursor, or generic stdio setup posture.
- Use the client config audit before sharing MCP client snippets so missing server args, unsafe defaults, cwd assumptions, and product-local paths are caught.
- Use the MCP safety boundary report before invoking command-backed tools or explaining why live SQL, route dispatch, schema hydration, and config secrets are not exposed by default.
- Use the MCP surface changelog when summarizing recent server capabilities, client-helper coverage, validation surfaces, or denied safety boundaries.
- Use MCP tool-call examples when documenting or testing common client workflows so request payloads stay aligned with live tool names.
- Use MCP workflow playbooks when choosing ordered Dataphyre inspection steps so agents do not guess tool sequences from memory.
- Use MCP workflow readiness audits before publishing workflow guidance so playbooks, prompts, examples, and docs coverage stay aligned.
- Use MCP workflow session exports when client authors need portable initialize and `tools/call` message sequences without executing the workflow.
- Use MCP workflow transcript schemas when capturing client-side responses so transcripts stay redacted, bounded, and useful to agents.
- Use MCP workflow state schemas when clients need client-owned progress state across agent turns without server persistence.
- Use MCP workflow state audits before sharing client-owned progress state so phase names, decisions, tool references, checkpoint status, and redaction safety are checked.
- Use MCP workflow state summaries to hand agents compact progress, references, audit status, and next tools instead of raw state envelopes.
- Use MCP workflow state transitions when clients need a suggested patch for their own state after next-action guidance; never treat the server as state storage.
- Use MCP workflow state sync packs when clients need schema, audit, summary, transition, and next-action guidance in one portable handoff payload.
- Use MCP workflow state timelines when agents need a quick current/next phase map without loading the full state sync pack.
- Use MCP workflow state resume briefs when a new agent needs the shortest safe continuation packet from client-owned state.
- Use MCP workflow transcript audits before sharing captured workflow results so required fields, status values, tool names, and redaction safety are checked.
- Use MCP workflow transcript summaries to hand agents compact step results and next tools instead of full raw response bodies.
- Use MCP workflow checkpoints for compact progress status, completion counts, safe handoff flags, and next actions from captured transcripts.
- Use MCP workflow handoff packs when client authors need one pre-run bundle with playbook, readiness, session, and transcript guidance.
- Use MCP workflow catalogs when choosing the right workflow before exporting a larger handoff pack.
- Use MCP workflow lifecycle exports when agents or clients need the full runbook from task start through checkpoint and verification.
- Use MCP workflow next-action exports when agents need a machine-readable decision from task text, transcript state, or client-owned workflow state.
- Use MCP workflow recommendations when task or symptom text needs to be mapped to a ranked workflow before exporting a handoff pack; for ordinary app-building tasks, read `app_builder_next_action` before opening broader handoff context.
- Use MCP workflow recommendation handoff exports when task text should immediately become a pre-run workflow bundle; for app-building handoffs, keep following `entity_planning.continuation_calls` from the app builder until `deferred_entities` is empty.
- For ordinary app-building, call `dataphyre_app_builder_plan_generate payload_profile=compact` first and read `builder_response.first_read` before opening broader context.
- Use MCP agent briefs when fresh or resumed agents need compact task direction with `builder_first_read` and `app_builder_next_action` without full start-pack, session, or transcript payloads.
- Use MCP task start packs only when an agent needs broader bounded startup context with status, safety, guidance references, discovery matches, and a recommended workflow.
- Follow proportional guidance in start packs and briefs: keep ordinary development lightweight, and escalate enterprise/runtime/governance review only for release-facing or public Dataphyre framework claims, corporate-ready or enterprise-readiness claims, security/identity/access/session/credential/governance/tenant isolation/billing/privacy/compliance/data residency/retention/legal-hold/access-policy work, Dataphyre framework internals or reusable framework contributions, or shared production hot-path changes.
- Use the live MCP stdio validator after MCP surface changes so client framing, tools, prompts, and resources are checked through a spawned server process.
- Use focused application or module verification for app behavior. Use the aggregate MCP verifier only for MCP/release-surface claims or after larger MCP slices so lint, live stdio validation, self-test, doctor, and app-coupling checks run together.
- Use client config summaries for portable MCP setup examples; keep product-local PHP paths and app server scripts out of shared docs and code.
- Use client install checklists for target-aware setup plans; they do not write client files or permit product-local paths in shared MCP code.
- Use MCP status boards for quick health and coverage snapshots; use readiness reports and manifests for full MCP/framework or release-surface detail, not ordinary app proof.
- Use enterprise adoption audits before claiming a feature is agent-first or corporate-ready; read `claim_summary` first, review the returned runtime quality gates, follow the extension strategy before framework edits, and keep missing docs, tests, release checks, or extension boundaries explicit.
- Use readiness report governance baseline fields before corporate-ready claims; tenant boundaries, access policy, audit evidence, redaction, and verification ownership should be inspectable or listed as missing evidence.
- Use MCP capability matrices for release-facing summaries; keep claims tied to live tool registration.
- Use MCP release notes generation for markdown summaries; do not hand-claim capabilities that are absent from the live matrix.
- Use MCP tool finder for quick task-to-tool discovery; use manifest export when full input schemas are needed.
- Use MCP resource finder for quick resource and prompt discovery before reading resources or fetching prompt text.
- Use MCP docs coverage reports after adding public tools, resources, prompts, skills, or safety boundaries.
- Use skill file install plans before discussing client-specific skill writers; default MCP workflows must not create skill directories, write SKILL.md files, or modify client registries.
- Use client config install plans before discussing client-specific config writers; default MCP workflows must not write client config files or hardcode target paths.
- Use embeddings readiness plans before discussing client-owned semantic documentation search; default MCP workflows must not call embedding APIs or write vector indexes.
- Use remote docs readiness plans before discussing client-owned remote documentation fetchers; default MCP workflows must not fetch URLs, execute remote scripts, or write remote docs caches.
- Use scaffold plans as dry-run guidance; do not treat them as permission to write files or execute unsafe workflows.
- Use task packs to gather builder-first app context, balanced module docs, guardrails, optional scaffold plans, and focused verification before a focused implementation.
- Use apply audit plans to describe proposed writes, risks, verification, and rollback before any unsafe apply workflow; audit plans are not file-write permission.
- Prefer existing module conventions over new abstraction styles.
- Follow DCS-1 naming when touching Dataphyre-owned PHP: kernel code uses snake_case in the lowercase `dataphyre` namespace, while Framework code uses PascalCase namespaces/classes and camelCase methods, properties, parameters, and local variables.
- Keep changes scoped to the relevant module and its docs/tests.
- Do not call SQL query, hydration, dispatch, or mutating install methods from diagnostics unless the tool is explicitly unsafe and opt-in.
- Do not expose config secrets. Config tools should report keys, shape, DBMS, cluster names, or table names, not passwords, usernames, endpoints, tokens, cookies, or database names.
- Config shape tools may report nested key paths and value kinds, but never raw values.
- Config value previews must be exact-key, opt-in, scalar-only, and must deny sensitive key names even when values are null or placeholder.
- Package metadata readers may report dependency constraints, autoload keys, and script names, but must not execute package scripts or expose package-manager config values.
- Source API summaries must use parsing/tokenization only; do not require PHP source files just to inspect signatures.
- API scaffold plans are dry-run recipes only; do not write endpoint files, dispatch endpoints, clear endpoint cache, or generate OpenAPI at runtime from read-only tooling.
- API recipe catalogs are planning aids only; pair them with static API summaries and scaffold plans before any endpoint implementation.
- API cache summaries are static contract summaries only; do not read, write, delete, or clear endpoint cache files from read-only tooling.
- OpenAPI contract summaries must stay static; do not bootstrap applications, discover manifests, call documentation controllers, fetch Swagger assets, or generate OpenAPI documents from read-only tooling.

## Verification

- Run `php -l` for touched PHP files.
- Prefer route-free harnesses such as Panel regression and field catalog checks.
- Use verification surface catalogs as inventories only; do not execute diagnostic files, custom manifest helpers, regression scripts, or release helpers from read-only tooling unless a bounded MCP wrapper exists.
- Use MCP self-test after changing MCP protocol, tool, resource, prompt, MCP
  SQL/route tooling, or public MCP documentation behavior. For ordinary
  application SQL/routes, use focused app or module checks.
- Report failing release checks as existing hygiene work unless the task explicitly asks to fix them.

## Panel

- Panel is route-free and manifest-oriented.
- Demo/live examples should prove framework APIs, not become the only implementation.
- Prefer `Panel`, `PanelInstance`, manifests, renderers, test harnesses, and regression suites over hardcoded admin URLs.
- Documentation catalog inspection should stay static; summarize catalog and entry contracts without building or rendering a docs site.
- Media/upload inspection should stay static; do not move upload chunks, call Storage drivers, create temporary URLs, or dispatch upload routes from read-only tooling.
- Keep accessibility, localization, upload, relation, and package workflows covered by route-free checks.

## Routing

- Reading manifests and generating URLs is safe.
- Matching previews are safe only when they do not dispatch handlers.
- Source route summaries should tokenize declarations only; do not execute route files or route-group callbacks.
- Route ambiguity reports identify incomplete static provenance; confirm dynamic route values with compiled manifests or direct source review instead of executing route files.
- Controller source summaries should tokenize classes and literal handler strings only; do not autoload, instantiate, or invoke controllers.
- Middleware source summaries should tokenize declarations, aliases, config keys, and classes only; do not resolve, instantiate, or execute middleware.
- MVC config summaries should report config shape and secret-key names only; do not read or return `signed_url_secret` or app runtime config values.
- MVC route-cache summaries should describe CLI and manifest-cache contracts only; do not write or delete route cache files from read-only tooling.
- Do not call route dispatch methods from read-only tools.
- Use `RouteManifest` and `RouteCompiler` for manifest work.

## SQL

- Schema inspection should use `TableDefinition` and `TableSchema` metadata.
- Listing clusters should omit usernames, passwords, hosts, endpoints, and database names.
- SQL query plans are static safety checks only; bounded SQL previews are not permission to execute queries from read-only tooling.
- SQL runner contracts describe future unsafe execution boundaries only; do not implement or run live queries unless the runner enforces the planner, row limits, timeout, audit output, and credential redaction.
- Do not call `query`, `select`, `insert`, `update`, `delete`, `upsert`, `hydrate`, or `hydrateColumn` from read-only tooling.
- `createQueries()` is acceptable as a string preview only.

## Diagnostics

- Dpanel, Flightdeck, Datadoc, Tracelog, and Panel regression are the preferred diagnostic families.
- Diagnostic output should be bounded and summarized.
- Share `diagnostic_summary.copy_safe_evidence` with `handoff_status: copy_safe_summary_ready` for app-owned issue notes or handoffs instead of raw logs.
- Unit-test manifest readers must not execute helper files, dynamic file expressions, or custom scripts.
- Flightdeck surface inspectors may parse files and route strings, but must not dispatch browser surfaces or require authenticated state.
- Tracelog/log artifact readers must keep previews bounded and redact secrets from trace text.
- Last-error diagnostics should extract focused snippets only; do not execute diagnostic collectors, browser runners, route dispatch, or external services from read-only tooling.
- Browser or local-server checks should be unsafe opt-in because they can start processes, send HTTP requests, or write reports.

## Documentation

- Keep `docs/MODULES.md`, `docs/README.md`, `runtime/README.md`, and module docs in sync when adding public modules.
- Add public docs for new MCP tools, resources, prompts, and security boundaries.
- Generated agent context should be returned as proposed content unless a caller explicitly asks for file writes through a separate, unsafe workflow.
- Datadoc static summaries may describe index tables, tokenizer/highlighter surfaces, and UI routes, but must not query Datadoc SQL records, sync files, render UI, or authenticate to Flightdeck.
- Prefer examples that use `php` or `DATAPHYRE_MCP_PHP_BINARY`, not product-local PHP paths.


