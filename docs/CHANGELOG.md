# Changelog

- Added a fixed, brokered three-process shared-cache release probe so platforms
  can prove Memcached capability and cross-process write/read/delete behavior
  without inline PHP, arbitrary cache keys, or secret-bearing environment files.

All notable Dataphyre changes are tracked here.

## Unreleased

### Fixed

- Bound Cloud-selected PostgreSQL migration targets to one generic data
  environment before application SQL, with one tightly application-prefixed
  profile alias for already-immutable legacy migrations. Platforms no longer
  need application-specific migration wrappers or database-name inference to
  distinguish live and sandbox targets.
- Accepted the exact inert `CAP_SETUID|CAP_SETGID` bounding-set residue for a
  root-brokered one-shot child when its UID/GID/groups are already `10001`, all
  usable capability sets are empty, and `NoNewPrivs` is set. Container PID 1
  does not need the broader `CAP_SETPCAP` merely to erase that ceiling; every
  other rootless role and every active capability set remains unchanged.
- Extended the application-neutral secret redactor to treat PIN and passcode
  fields as credential material, including normalized snake-case and camel-case
  variants, so structured diagnostics cannot disclose short access secrets.
- Restored the optional `cache` module compatibility facade and made missing or
  unhealthy Memcached infrastructure degrade to request-local memory instead of
  making production requests unavailable.
- Fixed associative `APP_MODULES` configuration resolution in the module
  registry so configured modules can be discovered without a method-name fatal.

### Added

- Added bounded native multi-row SQL mutation planning for `TableRepository`,
  `RepositoryQuery`, and `TableQuery`. Compatible PostgreSQL/YugabyteDB and
  SQLite `createMany(...)` / `upsertMany(...)` calls now group equal row shapes,
  honor conservative configurable row/parameter ceilings, preserve schema
  casting/defaults and per-input batch accounting, correlate PostgreSQL
  `RETURNING` rows through supplied primary keys or an explicit stable input
  column for database-generated identities, require explicit portable upsert
  conflict targets, invalidate caches per physical statement, and emit
  bulk-aware trace context. Uncorrelatable generated-identity inserts, MySQL,
  multipoint writes, custom legacy upsert expressions, and queued callback
  batches retain their compatible per-row paths. Rejected statements replay
  with the same
  row/conflict semantics outside transactions and fail all statement children
  without doomed retries inside an aborted Framework transaction. Focused
  contracts include native SQLite execution and prove 1,000 compatible rows
  become eight bounded statements at the default 128-row ceiling.
- Added one fixed, application-neutral release preflight command shared by local
  users, Dataphyre MCP, and release platforms. It validates application
  bootstrap configuration, runs the existing native PostgreSQL migration
  command in automatic dry-run mode when declared, boots the application on
  loopback, and probes only `GET /health`. The JSON contract always returns a
  boolean `likely_to_deploy` with actionable configuration, dependency, or
  verification failures and stable exit statuses; arbitrary scripts, commands,
  executable paths, health paths, and migration modes are not accepted.
  Migration discovery now distinguishes true absence from symbolic links and
  other non-regular entries, so invalid profile/manifest pairs cannot collapse
  into a false not-applicable result.
- Release preflight now records a bounded count and sorted-set digest of the
  registered table definitions through the fixed materializer authority without
  hydrating schema. Release platforms can match that evidence, complete declared
  application migrations, and only then run the shell-free materializer so the
  current registry cannot precreate schema ahead of immutable bootstrap replay.
- Added a fixed, shell-free PostgreSQL migration command that accepts only
  typed project, application, environment, and deployment-mode argv; loads the
  existing immutable migration profile and manifest from conventional paths;
  reads credentials only from the process environment; delegates to the native
  advisory-locking migration runner; and emits canonical secret-safe JSON with
  stable exit statuses. It never boots application PHP or accepts scripts,
  executable paths, arbitrary commands, rollback, or caller-selected migration
  files.
- Fixed one-shot PostgreSQL migration and registered-table materialization to
  optionally select a typed managed-database purpose. Root validates the
  complete six-field binding and opaque marker, then projects only that fixed
  binding onto the canonical database environment before privilege drop;
  omitting the purpose preserves existing external/default configuration. A
  non-primary materializer also runs inside the purpose's configured SQL data
  environment and fails closed when it has no cluster override.
- Added one fixed, shell-free managed seed one-shot. It consumes only the
  selected root-only typed PostgreSQL binding, applies an entire non-empty
  profile inside one guarded Dataphyre SQL transaction, captures rather than
  forwarding application output, emits one bounded redacted convergence object,
  and accepts no seed id, cluster, ledger, rollback, reset, script, mode, or
  tenant command. Seed definition and delegated content discovery are confined
  to regular non-symbolic files beneath the application root with explicit
  count and byte ceilings. Convergence and environment unwind precede commit;
  post-commit evidence-delivery ambiguity is an idempotent-retry outcome, never
  an inferred rollback or success. Its bootstrap and callbacks are trusted
  committed release code; the atomic guarantee covers Dataphyre SQL APIs, not
  direct native handles or unrelated filesystem/network side effects. The
  fixed launcher drops to the application UID before applying its zero-process
  creation ceiling, so the final PHP exec remains possible while tenant process
  creation stays unavailable. Its PID 1 retains `CAP_KILL` only long enough to
  hard-stop and reap that different-UID child after authenticated evidence; the
  child has no active capabilities and cannot regain any under `NoNewPrivs`.
- Added Datadoc's producer-neutral static documentation engine, integrity-
  checked build and publication values, dependency-free responsive portal,
  local search and version protocols, preview-first universal CLI, exact-tree
  verification, immutable idempotency, and atomic SemVer release publication.
  Panel now supplies only its generated corpus and a thin compatibility
  adapter; rendering, browser assets, security policy, and publication are
  explicitly owned by Datadoc and directly proven on PHP 8.2 and 8.4.
- Added a first-party durable shared-SQL Panel agent workflow store for MySQL,
  PostgreSQL, and SQLite. A global optimistic revision and audit-head fence
  coordinates renewable reservations, monotonic lease fencing, expiry reclaim,
  scope-bound idempotent result replay, single-use intent nonce digests,
  durable cancellation, verified canonical results, explicit retention, and a
  payload-free change feed. Schema installation remains explicit and
  idempotent; active caller transactions, incompatible schema, corrupt rows,
  stale workers, reused replay material, and bounded lock exhaustion fail
  closed through stable typed errors. Static manifests expose no connection,
  credentials, table prefix, SQL, live counts, raw idempotency keys, or raw
  nonces. Dual-PHP conformance, a two-process SQLite race, focused exact
  coverage, and live MySQL 8.4/PostgreSQL 17 probes certify the adapter without
  installing a database, route, model, secure-material repository, worker,
  scheduler, or exactly-once external-effect claim.
- Added a first-party durable shared-SQL Panel migration store for MySQL,
  PostgreSQL, and SQLite. Independently locked scope/tenant documents provide
  cross-node exclusive leases, monotonic fencing, durable idempotent runs,
  integrity-bounded state and pre-run backups, compensation/snapshot recovery,
  expiry recovery, a payload-free retained change feed, and stable typed
  storage failures. Handler-side SQL and its checkpoint share the exact
  constructor PDO transaction, while handler transactions are deliberately not
  replayed after ambiguous failures. Schema installation remains explicit and
  idempotent; Panel creates no connection, credentials, schema, worker,
  scheduler, or database authority. Dual-PHP conformance, independent
  connections/processes, focused exact coverage, and live MySQL 8.4/PostgreSQL
  17 probes certify the adapter.
- Added a first-party durable shared-SQL Panel leased-operation store for
  MySQL, PostgreSQL, and SQLite. It combines optimistic operation rows, exact
  idempotent creation with a domain-separated lookup digest, renewable leases,
  monotonic fencing, skip-locked reservation, deterministic expiry recovery,
  and a retained payload-free metadata feed. Schema installation is explicit
  and idempotent; caller-owned transactions, incompatible schemas, corrupt
  rows, lock exhaustion, stale workers, and forged bearer proofs fail closed
  through typed stable errors. Raw lease tokens, connection details, table
  prefixes, SQL, live counts, and operation payloads never enter manifests.
  Dual-PHP conformance, cross-connection durability, a three-process SQLite
  reservation race, focused exact coverage, and live MySQL/PostgreSQL probes
  certify the adapter without installing a connection, service, schema,
  handler, queue worker, or infrastructure authority.
- Added a first-party durable shared-SQL Panel realtime adapter for MySQL,
  PostgreSQL, and SQLite. It combines atomic per-stream publication, bounded
  retained replay, snapshot reads, explicit reset detection, and distributed
  single-use initial-connect protection backed only by domain-separated nonce
  digests. Schema installation is explicit and idempotent; nested caller
  transactions, incompatible schemas, row corruption, capacity exhaustion, and
  storage failures fail closed through stable public codes. Redacted manifests,
  cross-connection and three-process SQLite races, reusable broker conformance,
  dual-PHP execution, focused exact coverage, and live MySQL/PostgreSQL probes
  cover the adapter without installing routes, connections, credentials, or
  database authority.
- Added optional deferred Panel agent execution through the fenced leased-
  operation runtime. Immutable queued jobs persist only one-way repository tags
  and exact plan/scope/resolver/expiry/queue commitments; host-owned resolvers
  retain signed intents, confirmation evidence, raw idempotency keys, and
  identity. Operation-envelope tampering, stale resolvers, expired jobs, copied
  payloads, and leaking resolver/runtime failures fail closed. At-least-once
  operation retries converge through the agent store's exact idempotent replay,
  including a covered lease-loss-after-effect crash path that does not invoke
  the executor twice. Panel still installs no secure repository, worker daemon,
  scheduler, broker, route, model client, or remote queue adapter.
- Added a crash-safe, cross-process `PanelAtomicAgentWorkflowStore` with
  immutable hash-chained snapshots, optimistic revisions, renewable fenced
  reservations, durable scope-bound replay and cancellation, stale-owner
  rejection, hashed replay material, explicit retention, and fail-closed
  corruption handling. A destructive production conformance pack now verifies
  compatible local or remote stores without serializing credentials, raw
  idempotency keys, signed-intent nonces, or workflow identities.
- Added a first-party Panel editor asset-provider stack with immutable asset,
  page, and result contracts; a default-deny callback adapter for application
  media libraries; a tenant-scoped `PanelMediaManager` provider; and a
  route-neutral, verification-required endpoint. The progressive native picker
  supports accessible search, bounded pagination, validated uploads, insertion,
  two-step deletion, focus restoration, narrow-container reflow, request abort,
  same-turn DOM moves, detached-root cleanup, and provider unregister. The
  browser accepts only exact provider-issued root-relative or HTTPS references,
  while the server sanitizer and provider normalization remain authoritative.
  Panel installs no route, authentication, tenant context, origin/CSRF policy,
  rate limit, or storage authority for the host.
- Added optional first-party Panel browser-editor adapters for TinyMCE,
  CKEditor 5, Monaco, host-registered Tiptap and CodeMirror 6 module builds,
  Prism, and highlight.js. Versioned secret-free manifests, canonical textarea
  synchronization, command/insert routing, async mount cancellation, detached
  root and back/forward-cache teardown, late registration, explicit native or
  source fallback, and inert token reconstruction are covered by PHP and live
  Chromium contracts. Panel does not install or remotely load vendor packages,
  and browser availability never replaces server sanitation or validation.
- Added Dataphyre's first-party `testing` module and runner: module-owned
  `test()` contracts, self-describing suite/case metadata, datasets, managed
  workspaces and global state, non-public seams without `eval()`, covered
  subprocess and browser probes, content-addressed discovery, changed-file
  smart partials, adaptive isolation, dependency-aware parallel scheduling,
  architecture policy, property/mutation/replay foundations, JUnit and timing
  profiles, and exact closed-world coverage with a non-bypassable source epoch.
- Added a production-oriented Panel SQL/PDO data source for MySQL, PostgreSQL,
  and SQLite with immutable table/column/relation schemas, typed fail-closed
  tenant and authorization predicates, fully parameterized v2 query-AST
  compilation, deterministic primary-key tie breaking, rotating-key HMAC
  keyset cursors, nested `EXISTS` quantifiers, projection/search/aggregate and
  unknown-total support, stable redacted error/manifests, real SQLite
  conformance, dual-PHP tests, and exact closed-world source coverage.
- Added a production-oriented, read-only Panel remote HTTP data source with an
  immutable endpoint and capability pin, required approved-scope projection,
  exact bounded POST/JSON envelopes, authenticated query/scope-bound local
  cursors, deadlines, cancellation, read-only retries, circuit health, stable
  public failures, dual-PHP conformance, and exact closed-world coverage. Panel
  does not bundle a client or install network authority; transports,
  credentials, DNS/proxy/egress policy, scope mapping, and registration remain
  explicit host responsibilities.
- Added Panel DataSurface for bounded `table`, `list`, `cards`, `timeline`,
  `calendar`, and `gallery` windows with rotating-key signed intents bound to
  panel, tenant, principal, resource, source, projection, query, and range;
  strict stable-key projection; offset/cursor continuation privacy; SSR and
  progressive mobile/RTL/a11y rendering; contributor-layered registration;
  transactional rollback; secret-free manifests; and PHP, Node, and Chromium
  evidence. Panel does not synthesize a signer or authorizer or mount the
  host-owned authenticated, CSRF- and rate-limit-protected endpoint.
- Added a deterministic Panel inclusive-quality lane for locale, script,
  direction, timezone, number/date/plural formatting, long text, pseudo-locales,
  input methods, display preferences, and explicitly bounded assistive-
  technology proxies. Canonical PHP validation, bounded same-origin browser
  execution, matrix-bound artifact evidence, independent adapter/manual results,
  release budgets, and CI integration fail closed without claiming native AT
  execution.
- Added an optional tenant-scoped Panel Studio control plane with both a
  backward-compatible portable blueprint envelope and trusted execution:
  immutable typed property/child/component schemas, an instance-scoped
  provenance- and fingerprint-bound registry, path diagnostics, an audited
  callback-free materializer to actual Panel builders, masonry-aware runtime
  collection bindings, and host-registerable page bundles. Revision and receipt
  artifacts bind the source/normalized definitions, registry, compiler,
  materializer, and builder contract across save, approval, publication, and
  rollback; stale registries and unbound legacy revisions require an explicit
  re-save/migration. The optimistic/idempotent stores, independent approvals,
  preview capabilities, hash chain, rollback, platform domain, and conformance
  pack remain. Registry/materializer contract version 2 now covers all 26
  envelope kinds: `board` emits a read-only `Resource`, `board_column` emits a
  typed lane contract with brick/masonry metadata, and `infolist` emits a
  first-class `Infolist`. Hosts attach authorized board save/transition handlers
  explicitly; existing artifacts require the normal re-save, approval, and
  preview migration because their fingerprints intentionally become stale. A
  first-party accessible route-free Studio editor now adds useful SSR/no-JS
  output, progressive keyboard and pointer composition, typed property controls,
  undo/redo, optimistic save conflicts, and server-owned checkpoints. A new
  opt-in route-neutral `PanelStudioVisualRuntime` reauthorizes and renders
  unsaved sessions, signed revisions, and published revisions across all 26
  trusted kinds using actual Panel builders. Its JSON-only datasets are bounded
  and recursively redacted; values and bearer tokens never enter manifests.
  Empty-permissions sandboxed frames inline only allow-listed capability-scoped
  first-party Panel CSS while stripping scripts, base elements, and external
  link assets; they stay fully styled without same-origin permission. Strict
  per-frame/preview budgets, content-free failure surfaces, content-bound ETags,
  and glass/Flat-Minima desktop/mobile browser evidence keep previews
  non-mutating. Hosts still own authenticated routes, identity/authorization
  context, CSRF, transport, and checkpoint persistence; the Studio manager
  reports `visual_editor_runtime=true` only when its exact adapter is attached.
- Added an optional production Reactor-to-Panel widget bridge with exact
  definition/component/action/surface bindings, scope-bound snapshots, CAS
  rotations, deferred child issuance, bounded public state, fail-closed exact
  unmount revocation, stable JSON transport errors, and a guarded controller.
  Loading or registering the bridge installs no route, authentication,
  origin/CSRF policy, keys, transport authorization, persistent version store,
  or business idempotency; public manifests report the bridge only while its
  truthfully declaring adapter is active.
- Added an optional adapter-neutral Panel tenant IAM control plane with
  immutable principals and service accounts, versioned role/permission
  memberships, expiry and lifecycle operations, optimistic revisions, atomic
  fingerprint-bound idempotent receipts, policy-rechecked replays, distinct
  requester/approver enforcement for high-risk grants, secret-free service
  credential metadata rotation, request-scoped authorized queries, bounded
  rotating-key HMAC audit history, memory and crash-safe atomic stores, an
  optional platform domain, a production adapter conformance pack, and exact
  focused source coverage.
- Added a vendor-neutral, instance-owned Panel observability runtime with
  versioned trace/span/event/measurement envelopes, strict W3C trace-context
  propagation, privacy-bounded baggage, deterministic sampling, recursively
  redacted attributes and error fingerprints, fail-safe exporter fan-out, a
  bounded in-memory reference exporter, cross-surface correlation bridges, and
  a production exporter conformance pack.
- Added a transactional, versioned Panel state-migration lifecycle with strict
  semantic/schema edges, dependency and tenant-aware planning, stale-plan
  digests, authorization/capability preflight, dry runs, bounded resumable
  batches, idempotency, exclusive leases and fencing, integrity-checked backups,
  reverse compensation, snapshot recovery, recursively redacted receipts, an
  atomic local adapter, default-deny execution/rollback authorization, an
  explicit trusted-runner clone for already secured maintenance contexts, an
  optional platform domain, and a production conformance pack for
  database/broker adapters.
- Added dependency-closed Panel asset capability manifests with canonical route
  tokens, content hashes, SRI/nonce metadata, host/Reactor asset adapters, and
  scoped snapshot/audit tooling.
- Added immutable Panel query-expression DTOs for grouped comparisons, null,
  range, membership, explicit nested relations, safe null-aware sorting,
  deterministic serialization, and versioned URL round trips.
- Added fail-closed data-adapter capability negotiation and permission/tenant
  scoping for every explicit nested-resource query hop.
- Added backward-compatible row-filling masonry to Panel collection
  presentation. Page widgets/toolbars, table views and related collections,
  form/show sections, fields, infolist entries, and field options can opt into
  responsive incomplete-row fill without changing DOM order.
- Added versioned signed Panel navigation intents with canonical internal
  targets, tenant/principal/surface binding, bounded parent chains, HMAC key
  rotation, optional atomic replay protection, secret-free manifests, fluent
  Panel/action configuration, and fail-closed request verification before
  privileged mutations.
- Added `installer/init_consumer.php`, a small Composer consumer initializer
  that prepares `flight_sheet.php`, `index.php`, and the minimal application
  outside `vendor/`.
- Updated Composer consumer validation to exercise the shipped initializer
  before booting the minimal app.
- Added rotating Reactor HMAC keyrings, secret-free signing manifests, bounded
  recursive trace sanitization, and production-safe aggregate-only trace
  manifests with explicit operator opt-in for detailed diagnostics.

### Changed

- Panel root manifests now expose separate secret-free `data_sources`,
  `data_surfaces`, `studio_editor`, and `widget_runtime` contracts. Registry
  manifests use bounded registration snapshots and do not resolve lazy
  factories, execute adapters, or serialize endpoints, credentials, signing
  keys, callbacks, component state, or server checkpoints.
- Panel shells now deliver capability-scoped CSS and JavaScript aggregates by
  default. The legacy `panel.css`/`panel.js` monoliths remain byte-compatible
  through explicit `asset_mode=full` and token-free asset requests.
- Provider browse/upload/delete UI now uses the dependency-closed
  `editor-assets` capability and independently cacheable
  `panel-editor-assets.js`. Provider-free editor pages retain
  `panel-editor.js` without downloading picker runtime or picker CSS; modal
  shells include the capability so dynamically introduced daughter editors
  remain functional. Full-mode compatibility assets remain byte-compatible.
- Made the typed Panel query tree canonical while retaining legacy `where()`,
  flat filter/sort arrays, and 2.x URL parsing. Legacy public filter URL shapes
  are deprecated for removal in 3.0; protected tenant and authorization values
  are never restored from encoded URL state.
- Made `<project>/dataphyre` the canonical embedded framework layout and moved
  installer, documentation, examples, test fixtures, and MCP package-relative
  paths out of `<project>/common/dataphyre`.
- Generated Panel page, modal, relation, action, save, cancel, and exit flows now
  preserve a paired `return_to` and `navigation_intent`. Unsigned unprivileged
  same-panel returns remain available only through an explicit deprecated
  migration policy; cross-context and privileged returns require verification.
- Made `bootstrap.modules.enabled` and `bootstrap.modules.disabled` in the
  selected flight sheet the authoritative module policy. Omitted or disabled
  modules no longer load or register Framework autoload prefixes merely because
  their directories exist.
- Replaced directory-scanning module hot paths and the disk-backed discovery
  cache with bootstrap-normalized associative lookup sets. `core` remains the
  documented implicit bootstrap dependency.
- Removed Reactor's public development signing fallback and implicit unsigned
  debug acceptance. Development now uses an unpredictable process-local key;
  production requires a private key of at least 32 bytes and fails closed.
- Reactor keyrings now self-select only when exactly one key exists; multi-key
  rotation requires an explicit current key and malformed selectors fail closed.
- Removed the unkeyed checksum and predictable-id fallbacks from Reactor offline
  state transactions; sealing and transaction creation now require the keyed
  signer and cryptographic randomness.
- Production Reactor mutations now require a verified snapshot, reject invalid
  and future timestamps, and enforce a bounded snapshot lifetime (24 hours by
  default, with configured lifetimes capped at 30 days).

### Fixed

- Fixed transaction retry classification for YugabyteDB conflict and read-
  restart failures whose SQLSTATE is lost by a driver or YSQL Connection
  Manager path. Dataphyre now recognizes only YugabyteDB's concrete transient
  message signatures while leaving generic retry prose, constraint violations,
  and deterministic errors non-retryable.
- Fixed strict SQL first-row reads treating PostgreSQL's successful no-row
  `false` sentinel as a database failure. Repository and table queries now
  consult the kernel's recorded query error, preserving `null` for absence while
  still failing closed for real errors and malformed payloads.
- Restored the public Memcached cache module that SQL's `shared_cache` policy
  depends on, exposed `cache::isShared()` so request-local fail-open state is
  never mistaken for cross-worker cache state, and restored the module to
  release/export inventories.
- Fixed Reactor private snapshot stores misreading cached pre-`chmod()`
  permissions on PHP 8.2/Linux. Existing ledger directories are narrowed to
  owner-only access, the stat cache is cleared before verification, and an
  unreadable or still-broad mode now fails closed.
- Fixed public MCP validation and self-test root discovery for workspace-rooted
  `common/dataphyre` checkouts, added a PHP 8.2 JSON-validation fallback for the
  exhaustive MCP contract matrix, and made command execution reject a missing
  working directory before `proc_open()` can fall back elsewhere.
- Fixed source release checks treating canonical Shopiro copyright ownership as
  application fixture coupling and treating empty local artifact directories as
  publishable modules. Executable/test fixture product leakage remains blocked.
- Fixed Panel schema-blueprint PHP generation accepting namespace or table input
  that could produce invalid or injectable source; generated namespace/class
  identifiers are now validated before interpolation, and bounded JSON-only
  metadata prevents object/closure `__set_state` expressions in generated PHP.
- Fixed Panel quality matrices silently accepting empty axes and misreading a
  single associative viewport as multiple values; axes and values are now
  canonical, JSON-only, bounded, and rejected before combinatorial expansion.
- Fixed decorated required/dirty fields rendering duplicate clipped markers
  inside input shells, and aligned tab/step modal section gutters with their
  navigation controls.
- Updated project-root detection, installer fallback roots, application
  discovery, install-plan paths, and standalone CLI bootstrapping for the new
  embedded layout. Legacy `common/dataphyre` resolution remains supported as a
  narrow compatibility fallback.

## 2.0.3 - 2026-07-07

### Added

- Added clean Composer vendor-install boot validation that keeps
  `flight_sheet.php`, `index.php`, and `applications/` in the consumer project
  root instead of writing install-local files into `vendor/`.
- Made the public minimal entrypoint and flight sheet templates work in both
  source/embedded installs and Composer consumer projects.

### Fixed

- Added `DATAPHYRE_PROJECT_ROOT` support during bootstrap config resolution so
  Composer vendor installs can boot applications outside the package directory.
- Aligned public release/version metadata for the bootstrap and MCP surfaces.

## 2.0.2 - 2026-07-07

### Added

- Added source CI coverage for Composer consumer installs from package artifacts
  and release tags.
- Documented the GitHub VCS Composer install path for projects whose default
  Composer repositories do not yet resolve `dataphyre/dataphyre`.

### Fixed

- Fixed PHP 8.1 compatibility in package lint checks by avoiding newer return
  type syntax in shipped PHP files.
- Fixed release validation portability across Windows PowerShell and PowerShell
  7 on Ubuntu.
- Fixed release manifest verification for portable JSON integer types.
- Fixed MCP command validation on PHP 8.1 by preserving child-process exit
  codes when command output is collected.
- Fixed MCP Git-worktree path handling so symlinked `common/dataphyre`
  layouts continue to return portable package-relative paths.

## 2.0.1 - 2026-07-07

### Fixed

- Fixed standalone package installs so `DATAPHYRE_PROJECT_ROOT` resolves to the
  Dataphyre install root instead of its parent directory. Embedded
  `common/dataphyre` installs continue to resolve to the directory above
  `common`.
- Added code-defined regression coverage for standalone and embedded bootstrap
  root resolution.
- Normalized package metadata when `.gitattributes` contains directory-level
  package-boundary rules.
- Corrected getting-started and runtime documentation for standalone and
  embedded root resolution.

## 2.0 - 2026-06-25

### Changed

- Added [Dataphyre 2.0 migration notes](changelog/v2.0.md) summarizing the
  `a682534470207a31460a5c6626b760d792647e3b` to
  `6adbcbc56000c24be4c94199a9beaa2a4d24ecb3` release jump.
- Prepared Dataphyre for a public MIT re-release.
- Normalized Dataphyre-owned PHP headers to MIT/SPDX.
- Clarified the repository layout as an embedded Dataphyre installation with
  `runtime/` as the reusable engine boundary.
- Added a complete module index with status labels for core, optional, adapter,
  legacy, and experimental modules.
- Added public release entries and docs index links for newer runtime modules,
  including Mailer, MCP, MVC, Permission, Reactor, and Storage.
- Added compact public documentation for every released module under
  `runtime/modules/`.
- Added release verification tooling, including
  checks for Composer package contract drift, fragile PHP self-load guards,
  valid JSON fixtures, missing MIT/SPDX headers, and release hygiene markers.
- Added public architecture, package contract, stability, export, and
  third-party notice documentation.
- Added package-boundary verification for local install files, high-confidence
  secret markers, local filesystem/deployment path markers, and app-owned
  runtime or asset ownership markers.
- Removed product-specific, policy-specific, and internal adapter modules from
  public package artifacts.
- Kept local MCP declarations out of public release packages when internal
  integrations are present in a private worktree.
- Added release checks for required `.gitignore` and `.distignore` rules
  covering install-local config, plugin declarations, generated state, modcache
  files, vendor state, and Composer lock state.
- Normalized prepared export metadata so `.gitattributes` does not retain
  private adapter paths in public release packages.
- Added package-boundary checks for app-owned adapter markers in package
  metadata and documentation.
- Kept GitHub CI, pull request, and issue template metadata out of public
  runtime packages.
- Added GitHub Actions CI for Composer metadata validation, package surface
  checks, package-boundary checks, PHP linting, MCP self-test, and MCP live
  stdio validation.
- Added `RELEASE_MANIFEST.json` to package artifacts with public module
  inventory, bundled component inventory, file hashes, and a deterministic export
  tree hash, plus public schema documentation and a machine-readable JSON
  Schema.
- Clarified application-agent release boundaries: MCP users default to
  application agents building apps, while publication validation,
  `dataphyre_mcp_verify_all`, release gates, and Dataphyre hot-path benchmarks
  remain project evidence for framework, MCP publication, release-surface, or
  shared production hot-path work.
- Added standalone release manifest verification tooling for package artifacts.
- Added machine-readable release-boundary fields for ordinary app-agent
  entrypoints, verification, extension ownership, escalation, non-ceremony,
  release content, and project evidence, plus matching Composer app-agent
  entrypoint/profile metadata.
- Renamed Dpanel JSON fixtures from `.php` to `.json` and fixed malformed JSON.
- Normalized Stripe unit-test fixture keys so package-boundary secret scanning
  does not flag fake test credentials as live secrets.
- Fixed PHP lint blockers in Access diagnostics and Localization mutation
  helpers.

### Notes

- Dataphyre is now released under the MIT License.
- Product-specific embedded adapters are application-owned and are not core
  runtime dependencies.
- Local MCP plugin declarations can still describe private integrations for
  application-owned tooling without making them public runtime modules.
- Legacy and experimental modules remain labeled in `MODULES.md` until their
  public APIs, schemas, and configuration contracts are fully stable.
