# Dataphyre Panel Capability Audit

Audited: 2026-05-10

This document is the current source of truth for what Dataphyre Panel can
actually do today compared with a Filament-style target. It separates runtime
capabilities from demo-only proof points so we do not mistake a good showroom
for a complete framework contract.

## Status Legend

- `solid`: implemented as reusable Panel or Reactor framework code.
- `partial`: real framework exists, but the ergonomics, depth, persistence,
  testing, or production workflow is not yet Filament-level.
- `demo-only`: shown in the local `/debug` example, but not a complete reusable
  production framework guarantee.
- `missing`: not present as a first-party framework capability.

## Framework Capabilities

| Capability | Status | Evidence | Gap |
| --- | --- | --- | --- |
| Route-agnostic panels | solid | `Framework/Core/Panel.php`, `PanelInstance.php`, `PanelManager.php`, `PanelHost.php`, `PanelRoute.php`, `PanelRouteController.php` | Panel stays URL-agnostic by default and can optionally mount into Routing/MVC with prefixed page, asset, and upload endpoints. |
| Providers and plugins | solid | `PanelProvider.php`, `PanelPlugin.php`, `PluginManifest.php` | Needs more lifecycle examples for package authors and production package discovery. |
| Resources | solid | `Framework/Resources/Resource.php`, `ResourceForm.php`, `ResourceTable.php`, `ResourceManifest.php` | Needs stronger ORM/repository binding conventions beyond callbacks and arrays. |
| Forms and schemas | solid | `Framework/Forms/Field.php`, `FormSection.php`, `Framework/Schemas/*`, `PanelRendererForms.php` | Component catalog is improving, but still needs first-class editor packages and more advanced relationship-aware fields. |
| Tables | solid | `Column.php`, `TableFilter.php`, `TableView.php`, `TableGroup.php`, `TableSummary.php`, `PanelRendererTables.php` | Mobile and dense layouts still need hardening across all table modes. |
| Actions and action groups | solid | `Action.php`, `ActionGroup.php`, `ActionManifest.php`, `PanelRendererActions.php` | Needs continued polish around nested menus, placement, and per-theme presentation. |
| Modals and slide-overs | solid | Rendering support in `PanelRendererActions.php`, modal assets in renderer assets | Needs a stricter modal layout contract for cramped screens and modal history. |
| Widgets | solid | `Framework/Widgets/Widget.php`, `WidgetManifest.php`, dashboard renderers | More chart types and data adapters are still needed. |
| Navigation trees and layouts | solid | `NavigationItem.php`, `NavigationCluster.php`, `NavigationManifest.php`, renderer navigation assets | Horizontal navigation and responsive collapse still need more verification. |
| Theme presets and tokens | solid | `PanelTheme.php`, `PanelThemeRegistry.php`, `PanelThemePreset.php`, theme renderer assets | Themes exist, but third-party theme authoring docs and regression checks are thin. |
| Localization/i18n foundation | partial | `Framework/Localization/PanelLocalization.php`, `PanelLocalizationScope.php`, `Panel::localization()`, `PanelInstance::localization()` | Locale, fallback locale, catalogues, scoped keys, interpolation, and JSON manifests exist; renderer copy is not yet wired through the translator and pluralization/file loading are still future work. |
| Manifests and introspection | solid | `PanelManifest.php`, resource/table/action/widget/theme/tenant/search manifests | Needs richer docs explaining which manifest fields are stable public contracts. |
| Flightdeck trace hooks | solid | `PanelTrace.php` and debugbar integration work | Needs deeper lifecycle summaries and better correlation with Reactor requests. |
| Global search | partial | `PanelSearchProvider.php`, `PanelSearchResult.php`, search manifests | Needs async provider patterns, ranking hooks, permissions examples, and production indexing guidance. |
| Relation managers | partial | `RelationManager.php`, relation manifests, attach/detach/associate/dissociate/reorder/pivot contracts | Relationship operation manifests now cover nested resources, pivot fields, ordering, and custom handlers; still needs renderer polish for drag/drop, nested relation pages, and bulk relation workflows. |
| File and image fields | partial | `Field.php` upload helpers, renderer file controls, `Framework/Media/*` | Storage-neutral collections, validation, variants, and item manifests exist; still needs production adapters, upload progress, scanning, derivative execution, and cleanup workers. |
| Rich text, markdown, code editing | partial | Field component metadata, preview surfaces, and renderer classes exist | Still needs full editor packages, sanitization policies, upload adapters, syntax engines, and richer toolbar/plugin APIs. |
| Import and export | partial | `PanelRendererImports.php` handles CSV/JSON export, CSV import, mapping, preview, validation, confirmed import | No queue-backed jobs, chunking, resume, progress, failure reports, or long-running import dashboard. |
| Queue-ready data jobs | partial | `Framework/Operations/PanelDataJob.php`, `PanelDataJobResult.php`, `Panel::importJob()`, `Panel::exportJob()` | Chunk plans, progress events, failures, and artifacts exist; still needs a persistent queue adapter, resumable execution, and dashboard controls. |
| Tenancy | partial | `PanelTenant.php`, `TenantManifest.php`, tenant-aware links/search/resource context | Needs a resolver/switcher contract, tenant onboarding, tenant policies, tenant storage isolation examples, and billing-adjacent hooks. |
| Authorization | partial | Panel/resource/action `authorize`, `policy`, `can`, disabled reasons | Needs generated policy patterns, denial audit trails, and first-party test assertions. |
| Reactor-driven reactivity | partial | `runtime/modules/reactor/Framework/*`, Panel partial transport | Nested child slots, model binding metadata, and a test harness now exist; still not full Livewire parity for deep model binding, scaffolding, and ecosystem components. |
| First-party testing DSL | partial | `Framework/Testing/PanelTestHarness.php`, `PanelAccessibilityAudit.php`, `PanelBrowserRegressionManifest.php`, `PanelRegressionSuite.php`, `Panel::test()`, `PanelInstance::test()` | Route-free assertions now cover results, tables, forms, actions, navigation, commands, redirects, notifications, manifests, baseline accessibility audits, named regression suite reports, and CLI-friendly browser/a11y/screenshot plans; still needs a browser runner and generated test scaffolds. |
| Generators and scaffolding | partial | `Framework/Scaffolding/PanelScaffolder.php`, `Panel::scaffold()`, `PanelInstance::scaffold()` | Artifact generation exists for resources, pages, providers, plugins, themes, and tests; still needs CLI wrappers, overwrite policies, app-specific namespace discovery, and richer blueprints. |
| Documentation catalog | partial | `Framework/Documentation/PanelDocumentationCatalog.php`, `PanelDocumentationEntry.php`, `Panel::documentationCatalog()`, `PanelInstance::documentationCatalog()` | Structured entries now cover status, category, public API references, cookbook examples, links, tags, search, and manifests; still needs generated extraction, a complete docs site renderer, versioning, and package compatibility matrices. |
| Package compatibility matrix | partial | `Framework/Packages/PanelPackageManifest.php`, `PanelCompatibilityMatrix.php`, `Panel::packageManifest()`, `Panel::compatibilityMatrix()` | Packages can now declare runtime requirements, provided surfaces, support metadata, links, and compatibility results; still needs package discovery, lockfiles, signing, and marketplace policies. |
| Package templates | partial | `Framework/Packages/PanelPackageTemplate.php`, `Panel::packageTemplate()` | Template artifacts now cover source stubs, provider/plugin/theme files, docs, tests, package JSON, and marketplace listing data; still needs CLI writers, overwrite prompts, namespace discovery, and publishing workflow. |
| Package discovery and locks | partial | `Framework/Packages/PanelPackageRepository.php`, `PanelPackageLock.php`, `Panel::packageRepository()` | Repositories can discover package manifests from folders or generated artifacts and emit deterministic lock manifests; still needs installers, signature checks, remote registries, and update policies. |
| Package trust policies | partial | `Framework/Packages/PanelPackageTrustPolicy.php`, `PanelPackageTrustReport.php`, `Panel::packageTrustPolicy()` | Hosts can evaluate signature metadata, trusted publishers, trusted keys, allowed statuses, revoked packages, and revoked signatures; still needs real cryptographic verification, transparency logs, and marketplace enforcement. |
| Package install dry-run | partial | `Framework/Packages/PanelPackageInstallPlan.php`, `Panel::packageInstallPlan()` | Installer plans now combine package artifacts, target paths, compatibility, trust, overwrite policy, conflicts, and byte counts without writing files; still needs a CLI/apply layer, backups, rollback, and signed artifact verification. |
| Package rollback planning | partial | `Framework/Packages/PanelPackageRollbackPlan.php`, `Panel::packageRollbackPlan()` | Rollback plans derive delete, restore, snapshot, leave-alone, and blocked-conflict steps from install plans; still needs a real apply layer, backup storage, restore execution, and transactional filesystem semantics. |
| Browser-side asset runtime | partial | Split `PanelRendererAssets*.php` files | Needs a documented public JS extension API, stable events, and stronger no-JSON-dump failure containment. |
| Mobile-first table rendering | partial | CSS and renderer support exist | Recent screenshots still show compression and overflow in table cards; this needs dedicated responsive table contracts. |
| Local live example | demo-only | `common/debug/dataphyre-panel-live-example/index.php` | Session-backed local exerciser only; it proves APIs are callable, not that every workflow is production-complete. |
| Session-backed mutations | demo-only | Live example state store | Useful for demos, not a persistence abstraction. |
| Feature showcase metrics | demo-only | Live example manifests and dashboard pages | Should not be treated as runtime coverage unless backed by framework tests. |
| Showroom regression target | partial | `PanelRegressionSuite.php`, `PanelRegressionReport.php`, `PanelBrowserRegressionManifest.php`, live `/debug` regression inventory | Named route-free checks now produce pass/fail/skip reports and suites can serialize browser-level URL, viewport, interaction, selector, console, screenshot, accessibility, and result-output contracts; still needs the external browser execution adapter, artifact snapshots, and CI adapters. |

## Missing Filament-Level Pieces

| Capability | Status | Needed Work |
| --- | --- | --- |
| Generators and scaffolding | partial | Add CLI wrappers, relation/widget/import/export blueprints, namespace discovery, dry-run diffs, overwrite prompts, and generated browser/a11y test files on top of the new scaffold artifacts. |
| First-party testing DSL | partial | Add the browser runner for the new browser/a11y/screenshot manifest contract, generated fixtures, import/export job assertions, CI adapters, and package compatibility tests on top of the route-free harness and regression suite reports. |
| Queue-backed import/export | partial | Add persistent queue adapters, resumable execution, retry controls, long-running dashboards, and storage-backed downloads on top of the new queue-ready data job contract. |
| Production media manager | partial | `Framework/Media/PanelMediaLibrary.php`, `PanelMediaCollection.php`, `PanelMediaItem.php` now define collections, validation, variants, visibility, cleanup metadata, and manifests; still needs storage disks/adapters, actual image transformation execution, upload progress, virus scanning, and cleanup jobs. |
| Full Livewire-equivalent ergonomics | partial | Reactor has nested components, model binding metadata, event effects, stable browser hooks, and route-free test helpers; it still needs deeper model binding ergonomics, scaffolding, and an ecosystem-level component library before it can claim parity. |
| Advanced relationship UX | partial | Phase 8 added relation operation contracts for attach, detach, associate, dissociate, reorder, pivot fields, and pivot updates; still needs polished drag/drop UI, nested relation pages, bulk relation operations, and dedicated policy test helpers. |
| Notifications and database inbox | partial | `PanelNotificationAdapter.php`, `PanelInMemoryNotificationAdapter.php`, `PanelNotificationInbox.php`, and `PanelInboxNotification.php` define an adapter contract plus in-memory behavior for durable notification records, recipients, read/unread state, dismissal, action links, timestamps, counts, delivery channels, and manifests; still needs SQL/cache adapters, UI center, broadcast transport, and policy tests. |
| Full API reference and cookbook | partial | `PanelDocumentationCatalog.php` and `PanelDocumentationEntry.php` now provide a structured reference/cookbook contract; still needs exhaustive entry coverage, generated extraction, versioned docs, and a public docs renderer. |
| Plugin ecosystem | partial | Package manifests, compatibility matrices, package templates, repositories, lock manifests, trust policies, dry-run install plans, and rollback plans now exist; still needs real signature verification, marketplace review, distribution, installers, remote registries, transactional apply/rollback, and compatibility CI. |
| Accessibility regression suite | partial | `PanelAccessibilityAudit.php` adds route-free checks for names, labels, duplicate ids, ARIA references, dialogs, live regions, and reduced-motion hooks, while `PanelBrowserRegressionManifest.php` can declare browser-level a11y gates for a future runner; still needs keyboard, focus trap, contrast, axe, and screen-reader execution. |

## Phase Recommendations

1. Make docs honest: every public doc should distinguish `solid`, `partial`,
   `demo-only`, and `missing` when describing Filament-style parity.
2. Build the missing component catalog: rich editor, markdown preview, code
   editor, polished date/time pickers, relation selects, async searchable
   selects, media uploaders, and repeatable nested schemas. Phase 2 started this
   by adding component metadata, datalist suggestions, input affordances, and
   previewable editor surfaces.
3. Promote Reactor from foundation to product: nested components, model binding,
   browser hooks, action effects, and testing helpers. Phase 3 added nested child
   slots, server-side model binding metadata, and a route-free test harness.
4. Add framework tests before adding more demo surfaces, especially for tables,
   modals, actions, imports, and responsive behavior. Phase 4 added the
   route-free `PanelTestHarness` and demo assertions for render output, table
   state, actions, navigation, commands, and manifests.
5. Build generator ergonomics so features are easy to adopt. Phase 5 added
   `PanelScaffolder` artifacts for resources, pages, providers, plugins, themes,
   and tests, exposed through `Panel::scaffold()` and `$panel->scaffold()`.
6. Move import/export toward production scale. Phase 6 added queue-ready
   `PanelDataJob` and `PanelDataJobResult` contracts with chunking, progress
   events, failure reports, generated artifacts, and import/export helpers.
7. Build media on contracts first. Phase 7 added `PanelMediaLibrary`,
   `PanelMediaCollection`, and `PanelMediaItem` so upload fields can point at
   named collections with validation, variants, visibility, cleanup metadata,
   and manifests before any storage adapter is chosen.
8. Bring relations closer to nested resources. Phase 8 added relation operation
   contracts for associate/dissociate, reorder handlers, pivot fields, and pivot
   update handlers so renderers can build Filament-style relation workflows from
   one manifest.
9. Promote notifications beyond toasts. Phase 9 added adapter-ready notification
   inbox records with recipients, read/unread state, dismissal, action links,
   counts, and manifests. The first-pass adapter contract now adds in-memory
   persistence behavior, delivery channel manifests, and route-free assertions
   while leaving SQL/cache storage and real broadcast transports to production
   adapters.
10. Add route-free accessibility guardrails. Phase 10 added
   `PanelAccessibilityAudit` plus harness assertions for duplicate ids,
   accessible names, labels, ARIA references, dialogs, live regions, and
   reduced-motion hooks.
11. Build documentation from the framework shape. Phase 11 added
   `PanelDocumentationCatalog` and `PanelDocumentationEntry` so packages and
   demos can describe APIs, cookbook examples, links, categories, tags, and
   completion status as structured data.
12. Treat `/debug` as a showroom and regression target, not as proof that a
   capability is production-ready. Phase 12 added `PanelRegressionSuite` and
   `PanelRegressionReport` so the live example can publish named route-free
   checks with pass/fail/skip status, timings, details, and failure metadata.
   The suite can now also carry `PanelBrowserRegressionManifest` entries for
   CLI-friendly browser/a11y/screenshot plans without requiring PHP to launch a
   browser.
13. Make packages inspectable before they are installable. Phase 13 added
   `PanelPackageManifest` and `PanelCompatibilityMatrix` so packages can declare
   PHP, Panel, Reactor, module, theme, provided-surface, support, and link
   metadata, then evaluate compatibility against the current runtime.
14. Give package authors a starting contract. Phase 14 added
   `PanelPackageTemplate` so package definitions can produce source, provider,
   plugin, theme, docs, tests, package JSON, and marketplace listing artifacts
   without writing files by default.
15. Make package state reproducible. Phase 15 added `PanelPackageRepository`
   and `PanelPackageLock` so hosts can discover package manifests from folders
   or generated artifacts, merge registered plugins, evaluate compatibility, and
   produce deterministic lock manifests with checksums.
16. Separate compatibility from trust. Phase 16 added `PanelPackageTrustPolicy`
   and `PanelPackageTrustReport` so hosts can evaluate signature metadata,
   trusted publishers, trusted keys, allowed statuses, revoked packages, and
   revoked signatures before accepting package code.
17. Plan installs before writing anything. Phase 17 added
   `PanelPackageInstallPlan` so package templates can be evaluated against
   runtime compatibility, trust policy, target paths, overwrite policy, file
   conflicts, and byte counts as a dry run.
18. Plan the reverse path too. Phase 18 added `PanelPackageRollbackPlan` so
   dry-run install steps can produce delete, restore, snapshot, leave-alone, and
   blocked-conflict rollback manifests before any package files are written.
