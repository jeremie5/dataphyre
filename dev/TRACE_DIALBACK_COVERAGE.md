# Trace/Dialback Coverage Inventory

This maintainer inventory is for Dataphyre Framework instrumentation review. It is not a requirement to add blanket `tracelog()` or dialback calls.

Coverage means each meaningful surface is classified as one of:

- `must_trace`: coarse lifecycle, mutation, security, integration, or external-effect boundary where a trace materially improves diagnosis.
- `must_dialback`: extension/governance boundary where application or corporate policy code needs a scoped hook.
- `already_covered`: delegated to an existing kernel trace, Framework trace helper, or existing event/dialback system.
- `intentionally_uninstrumented_hot_noisy`: path can be high-frequency or low-signal; do not instrument without benchmark evidence.
- `deferred`: plausible future coverage, but not a release blocker.

New Framework-owned dialbacks use `CALL_<MODULE>_FRAMEWORK_<SURFACE_OR_CONCEPT>_<ACTION>`. Framework wrappers that bridge existing kernel hooks may keep established `CALL_<MODULE>_<ACTION>` names.

## Inventory Command

```powershell
.\dev\tools\report_trace_dialback_coverage.ps1 -Root . -CandidatesOnly
.\dev\tools\report_trace_dialback_coverage.ps1 -Root . -CandidatesOnly -Format Json -Output .\dev\trace_dialback_inventory.generated.json
.\dev\tools\check_trace_dialback_usage.ps1 -Root .
```

The generated JSON is a review artifact. Do not treat every candidate row as a required runtime edit.

## Trace Coverage Implemented

- `runtime/modules/localization/Framework/LocalizationManager.php`: definition save/delete/batch plus sync/rebuild trace maintenance outcomes without logging locale string contents.
- `runtime/modules/fulltext_engine/Framework/SearchManager.php`: index create/delete/sync trace index lifecycle outcomes without tracing search/tokenize/score hot paths. Configured sync delegates to traced sync.
- `runtime/modules/async/Framework/AsyncManager.php`: public single-task dispatch and batch normalization trace driver, task type/count, and argument counts without logging task payloads or argument values.
- `runtime/modules/scheduling/Framework/ScheduledTask.php`: scheduler registration traces task name, period, timeout, dependency count, and success/failure.
- `runtime/modules/panel/Framework/Packages/PanelPackageInstallPlan.php`: package apply traces dry-run, written/skipped/blocked/backup counts, and success/failure without logging artifact contents.
- `runtime/modules/mailer/Framework/MailerManager.php`: send, batch send, and async handoff trace provider/status/count summaries without logging recipients, subjects, message bodies, provider credentials, or raw provider responses.
- `runtime/modules/api/Framework/ApiManager.php`: internal dispatch, batch/chain dispatch, compiled route execution, route authorization, lifecycle phases/hooks, and custom auth resolvers trace route/scheme/phase/status/count summaries without logging request bodies, headers, credentials, or response payloads.

## Dialback Coverage Implemented

- `runtime/modules/storage/Framework/StorageManager.php`: lifecycle apply, quarantine purge, and cross-disk sync expose scoped `CALL_STORAGE_FRAMEWORK_*` before/after hooks with summary payloads. Array return values replace operation results; other return values are ignored.
- `runtime/modules/panel/Framework/Packages/PanelPackageInstallPlan.php`: package apply exposes scoped before/after hooks. Array or `PanelPackageApplyResult` return values replace operation results; other return values are ignored.
- `runtime/modules/access/Framework/Auth.php`: login, login-by-id, credential attempt, and logout expose scoped before/after hooks with guard, remember flag, type/key summaries, and success state only. Boolean return values replace operation results.
- `runtime/modules/access/Framework/OAuthClient/Provider.php`: OAuth local-user resolution and login expose scoped hooks with provider/guard/type summaries and success state only. Resolve hooks may return a replacement local user; login hooks may return a boolean operation result.
- `runtime/modules/mailer/Framework/MailerManager.php`: send, batch send, and async handoff expose scoped before/after hooks with summary payloads only. `SendResult`, array, or async-result return values replace operation results according to method return shape.
- `runtime/modules/api/Framework/ApiManager.php`: API lifecycle phase/hook and custom auth resolver boundaries expose scoped `CALL_API_FRAMEWORK_*` hooks. Payloads are route/scheme/phase/type summaries only; `Response` or normalized authorization values can replace results according to the method contract.

## Must Trace Queue

No audited must-trace rows are open. Regenerate the JSON inventory before adding new runtime instrumentation.

## Must Dialback

No audited must-dialback rows are open. Regenerate the JSON inventory before adding new Framework dialbacks.

## Already Covered

- `runtime/modules/panel/Framework/Core/PanelManager.php`: panel request dispatch, auth, actions, saves, deletes, imports, and uploads already use `PanelTrace`.
- `runtime/modules/reactor/Framework/Core/ReactorManager.php`: Reactor request/action/lifecycle/security/effect surfaces already use `ReactorTrace`.
- `runtime/modules/permission/Framework/PermissionEngine.php`: permission checks, cache, compile, and subject resolution already use `PermissionTrace` and subject dialbacks.
- `runtime/modules/sql/Framework/DB.php`: SQL query/mutation execution is covered by SQL trace context and guardrail tracing.
- `runtime/modules/storage/Framework/StorageManager.php`: storage write/delete/copy/move already emit storage before/after events.

## Intentionally Uninstrumented Hot/Noisy

- `runtime/modules/sql/Framework/TableQuery.php`: per-row create/update/delete wrappers can run 1000+ times per request; rely on SQL trace/kernel coverage.
- `runtime/modules/fulltext_engine/Framework/SearchManager.php`: search/tokenize/score are hot read/scoring paths.
- `runtime/modules/mailer/Framework/MailerManager.php`: template render is noisy; trace send outcome instead.
- `runtime/modules/mvc/Framework/MvcDispatcher.php`: generic HTTP dispatch/middleware is per-request baseline noise unless an MVC trace facility is added broadly.
- Panel renderer, field, option, and state paths are already covered by `PanelTrace` and should not receive extra dialbacks.

## Deferred

- `runtime/modules/mvc/Framework/ProviderRegistry.php`: provider register/boot is worth tracing later, but lower priority than API/auth/storage/package effects.
- `runtime/modules/routing/Framework/RouteCompiler.php`: route compile cache/build diagnostics can be added later; this is mostly trusted build-time config.
- `runtime/modules/sanitation/Framework/Sanitation.php`: preset registration is an extension surface, but not a runtime mutation/security boundary unless presets become tenant/plugin-controlled.
