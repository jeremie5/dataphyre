# Dataphyre Core Module

The core module is Dataphyre's runtime foundation. It owns bootstrap flow, application discovery, module loading, autoload registration, configuration state, dialbacks, URL helpers, and common date/time utilities. Request-local environment state lives in the optional framework `Dataphyre\Env` repository.

The kernel remains the lowest-level path through `\dataphyre\core`, `\dataphyre\runtime`, `\dataphyre\app_locator`, and `\dataphyre\application_definition`. The optional framework layer gives those same capabilities a cleaner, application-facing API under the `Dataphyre\...` namespace.

## Kernel Layer

The kernel side is responsible for:

- loading and booting the current application
- locating applications across configured application roots
- loading module kernel and framework entrypoints
- holding runtime configuration state
- registering and firing dialbacks
- shared URL, date, CSRF, and utility helpers

Important kernel entrypoints include:

- `\dataphyre\core::load_framework_module(...)`
- `\dataphyre\core::load_framework_modules(...)`
- `\dataphyre\core::add_config(...)`
- `\dataphyre\core::get_config(...)`
- `\dataphyre\core::config_all()`
- `\dataphyre\core::register_dialback(...)`
- `\dataphyre\core::dialback(...)`
- `\dataphyre\runtime::boot(...)`
- `\dataphyre\runtime::resolve_application_definition(...)`
- `\dataphyre\runtime::current_application_definition()`

## Application Release Preflight

Dataphyre exposes one application-neutral executable preflight:

```text
php runtime/modules/core/kernel/application_release_preflight.php --project-root=<application-project> --application=<id> --environment=<id>
```

The command validates the existing flight sheet and application definition,
uses the existing `dataphyre.app.json` name to bind a standalone application
repository to its Dataphyre application id, runs the native PostgreSQL
migration command in automatic dry-run mode or applies a declarative SQL-only
SQLite manifest inside one disposable application-data root when exactly one
complete profile and immutable manifest are declared, verifies the
application-resolved primary PostgreSQL
identity when the release platform declares a managed primary binding, boots
through a fixed loopback router, and probes only `GET /health`. It does not
accept an application release script, command, executable path, health path,
database path, or migration mode. SQLite health and realtime-registration
checks receive the same disposable migrated state, which is removed before the
preflight returns.

Every fixed preflight child runs without a shell in its own immutable POSIX
session. Stdout and stderr are drained together into separate 256 KiB maxima.
A stage timeout sends `SIGTERM` once to the whole owned process group, waits
500 ms, then sends `SIGKILL`, closes both pipes, and reaps the direct child
within a second fixed deadline. An application descendant cannot extend the
preflight by ignoring `SIGTERM` or retaining an inherited output pipe; timeout
remains exit `124` for the fixed child contract.

After health succeeds, the same preflight loads the application's ordinary
Framework bootstrap in the fixed realtime-registration context. Evidence
contains the route count and a SHA-256 of the sorted paths, the scheduler
definition count and a path-independent SHA-256 over task/dependency contents
and scheduling semantics, and the bounded registered table-definition count
and sorted-set SHA-256 produced by the fixed materializer authority. The table
inventory is read-only: preflight does not hydrate tables or write schema.
Cloud must match that inventory, run declared application migrations to
completion, and only then run the fixed registered-table materializer. The
immutable migration manifest owns ordered bootstrap replay; current registered
definitions describe the resulting schema and must not precreate future tables
or indexes ahead of that history. When no migrations are declared, the
materializer is the first schema stage. Scheduling runs in `record_only` mode
under a private temporary state root; application cache/config/log bytes are
unchanged, no lock, cadence timestamp, task, or shutdown callback can be created, and an
ignored invalid, duplicate, or unpersisted registration fails the preflight.
Table names, callbacks, credentials, headers, task paths, and event payloads never enter the
report. Cloud must add exact-image proof of the four direct runtime children,
the eight FPM workers,
scheduler callback execution with claim-bound success receipts and lock
cleanup, a framework listener roundtrip, execution and
strict invalid-Origin rejection by every registered application authorization
callback, and WebSocket ping/pong and close
before promotion.

The conventional PostgreSQL or SQLite profile and manifest are executable
migration inputs, so exactly one engine's pair may be present as readable
regular files beneath non-symbolic-link directories. One-sided pairs, two
declared engines, symbolic links, broken links, directories, pipes, and
unreadable entries fail closed as invalid migration configuration; both
engines absent means the migration check is not applicable.

When `DATAPHYRE_DATABASE_BINDING_PRIMARY_SHA256` is present, release preflight
invokes the same fixed `application_runtime_database_identity.php
--purpose=primary` probe used by the one-shot runtime. The probe accepts only
the Cloud-projected primary binding, opens its PostgreSQL connection, and
returns a purpose- and binding-bound connection hash derived from the binding
marker plus `current_database()` and `current_user`. The public preflight
evidence contains only that opaque connection hash, declaration state, and
purpose; it never contains a DSN, host, user, password, config path, query
output, or database error. Cloud compares the hash with its independent
exact-image connection proof before migration and promotion. An absent marker
makes `database_runtime` not applicable; an invalid marker, driver, connection,
identity query, binding mismatch, or response fails closed with exit 69 and
`application_database_identity_failed`.

The fixed one-shot PostgreSQL migration and registered-table materializer may
receive an optional typed purpose; the fixed seed operation requires one. Root accepts only
`[a-z][a-z0-9_]{0,31}`, requires its opaque binding marker and all six fixed
`DSN`, `HOST`, `PORT`, `NAME`, `USER`, and `PASSWORD` values, and reprojects
that binding onto the canonical `DATAPHYRE_DATABASE_*` names plus the primary
marker before privilege drop. An absent purpose preserves the existing
canonical/default environment. SQLite migration, Artisan, and unrelated
one-shot operations do not accept this control. For materialization, the root
broker seals the purpose into its private child attestation rather than argv.
A non-primary purpose runs hydration inside the existing SQL `DataEnvironment`
of the same name and requires that environment to configure a cluster override;
the context is restored on success or failure. Primary and omitted purposes
retain ordinary live hydration behavior. Before the child starts, every
unselected named binding and credential is removed; only the canonical selected
binding and its primary marker remain. The seed operation requires one profile
and an explicit demo acknowledgement bit. It atomically applies that entire
non-empty profile through the configured PostgreSQL Dataphyre SQL connection.
The application bootstrap is trusted, side-effect-free release startup code:
it runs under the selected environment identity before the database transaction,
may load only configuration/autoload support, and may not load Dataphyre SQL.
Definition discovery, preflight and apply callbacks, ledger changes, convergence,
environment unwind, and deferred-query rejection all finish inside the outer
transaction before commit. The transaction boundary rejects raw transaction
control, a different cluster or Fiber, and deferred queues; direct public driver
entry points enforce the same owner, cluster, and transaction-control rules.
Application callbacks remain trusted committed release code;
direct extension/native-handle database access and non-database filesystem or
network effects are outside the Dataphyre SQL atomicity guarantee. PID 1 captures
both child streams, never forwards raw bytes,
and accepts only one bounded canonical `dataphyre.managed_seed_apply.v1` object
whose identities and exit status match the root-owned request; malformed,
multiple, warning-bearing, oversized, missing, or forged output becomes a
generic root-owned failure. The application root, seed directory, bootstrap,
ledger, cluster, mode, and seed selection are not caller-controlled; `primary`
maps to the `live` data environment and another purpose maps to the data
environment of the same name. There is no shell, application script, rollback,
reset, or arbitrary Artisan command in this path. Because commit precedes the
root evidence write, an interruption or missing result after commit
acknowledgement is outcome-unknown. A convergence failure happens before commit
and rolls the SQL batch back. Cloud resolves only the ambiguous delivery window
by retrying the same immutable, idempotent whole-profile operation.

The application-owned `/health` response must be a JSON object with a top-level
`missing_environment_keys` list. The list is canonical: sorted, unique, no more
than 64 entries, and each entry matches `[A-Z_][A-Z0-9_]{0,119}`. It contains
names only—never values or value-bearing explanations—and is empty when no
configuration key is missing. A missing field, malformed object, duplicate or
invalid name, oversized header/body, or non-JSON response fails closed as
`application_health_evidence_invalid`; the preflight discards the raw body.
Valid non-empty names fail as `application_environment_keys_missing`, including
when the route incorrectly returned 2xx.

Every invocation writes one JSON object to stdout and leaves stderr unused.
`likely_to_deploy` is always boolean. Exit `0` means every check passed; `64`
means invalid typed invocation or runtime, `66` means the project is
unavailable, `69` means a dependency could not be verified, `70` means
executable verification failed, `75` means the application did not become
healthy, and `78` means application, migration, or environment configuration
is invalid. Dataphyre Cloud must run this exact command inside the exact built
candidate and preserve source, image, environment, and traffic identity before
promotion; a local pass is a prediction, not proof that a different image will
work.

## Fixed Managed Application Runtime

Dataphyre application images have exactly four framework-owned direct child
roles: the private rootless HTTP gateway on `127.0.0.1:8083`, its rootless
PHP-FPM master with eight workers on a fixed Unix socket, the root scheduler
gateway on the root-only `/run/dataphyre/scheduler/gateway.sock`, and public
realtime ingress on `0.0.0.0:8080`. The realtime ingress handles authenticated
WebSocket upgrades and safely forwards ordinary HTTP to the private web
gateway. PID 1 exposes status and scheduler claims only on the root-only
`/run/dataphyre/control/runtime.sock`. Neither private control surface has a TCP
listener. Applications do not declare processes, commands, listeners, ports,
pool sizes, or sidecars.

The web gateway, FPM master and workers, and realtime role are UID/GID `10001`,
capability-free, and `NoNewPrivs`. Dynamic web requests stay within the
persistent FPM pool. Only the root scheduler gateway retains `CAP_KILL`,
`CAP_SETUID`, and `CAP_SETGID`; it uses the identity capabilities solely to
create one fresh UID/GID `10001`, capability-free `php-cgi` process for each
accepted signed scheduler request, and `CAP_KILL` solely to terminate that
child's complete owned process group after success, failure, or timeout.
Normal image PHP configuration and extensions remain active; Cloud must not
launch any role with `-n`.

PID 1 is the only process that consumes the root-only, read-only application
environment mount. Secret-bearing child environments are never published to a
path and never enter `argv`, `envp`, or `/proc/<pid>/environ`. Immediately
before each final exec, the trusted launcher creates one unnamed Unix
socketpair and maps only its child endpoint to fixed descriptor `198`. The
canonical envelope is bounded to 524,288 bytes, one role, one nonce, the exact
child PID and Linux start-time tick, and every ancestor PID/start-time pair
through PID 1. The child validates that identity after exec, projects values,
returns one canonical acknowledgement, zeroizes the transport bytes, and
closes both the PHP stream and native descriptor. The broker then closes its
endpoint. There is no refetch, replay, same-process second read, sibling claim,
PID-reuse acceptance, or tenant-readable fallback file.

PHP cannot safely reopen an anonymous socket through `php://fd`; the public
runtime therefore includes the tiny `dataphyre_environment_fd` extension. Its
one-shot callable surface duplicates only descriptor `198` into a PHP stream,
closes only descriptor `198`, and closes every non-stdio inherited descriptor
except `198` in the fixed pre-exec process; callers cannot select descriptors.
Its managed FPM surface consumes the same envelope during module startup and
returns one sealed request context per worker request. Image construction must
compile and enable the extension for CLI, CGI, and FPM and fail closed if the
required callable is unavailable. One-shot database identity,
release preflight, supported migration commands, and `php artisan migrate`
use the same one-child broker. Arbitrary application shell or release scripts
remain unsupported.
The one-shot PID 1 starts that child through the immutable `/usr/bin/setsid`
and owns its process group. Each operation is bounded by its fixed identity,
migration, or public release-preflight maximum. `SIGTERM` and `SIGINT` are
converted to one group-wide `SIGTERM`; after 500 ms PID 1 escalates to
group-wide `SIGKILL`, reaps adopted descendants, and exits without waiting on
tenant-controlled descendants.
The fixed registered-table materialization operation accepts the private
`/var/lib/dataphyre/application` mount only when the root launcher proves that
it is one distinct read-write directory owned by UID/GID `10001`. That mount is
optional for materialization so PostgreSQL-backed applications remain
mountless; it is required only by the fixed SQLite migration operation. The
verified path is projected through the root-only environment envelope and is
never accepted from an application argument or container environment entry.
The same broker exposes one fixed `dataphyre_shared_cache_probe` operation with
a 10-second inner deadline. Root accepts only a detect/write/read-delete phase
and a 64-character lowercase hexadecimal challenge, then dispatches the
image-owned cache probe. Detect is networkless; write and read-delete must run
as separate network-enabled one-shots. Keys, values, endpoints, TTLs, scripts,
commands, and environment files are never selectable through this boundary.

Managed HTTP traffic has one framework-owned topology. A rootless
`web-http-gateway` listens on `127.0.0.1:8083`, serves safe immutable files
directly from the application's canonical `public/` directory, and forwards
all dynamic requests over `/run/dataphyre/web/php-fpm.sock`. A separate
rootless PHP-FPM master owns eight static workers, recycles each worker after
500 requests, and terminates requests after 300 seconds. The gateway never
receives application environment values or a managed bootstrap key. The FPM
master consumes those values once from descriptor 198 before forking; the
native request boundary restores the sealed environment, cwd, and umask for
every worker request. Applications cannot supply a web command, release
script, pool size, socket, timeout, or process-manager setting.

The former per-request web `php-cgi` path is deleted. Fresh `php-cgi` remains
only behind the signed scheduler gateway, where one accepted scheduler claim
still maps to one reaped child. Realtime remains its own persistent,
capability-free framework process.

Applications register realtime code while their normal `framework_bootstrap.php`
is loaded with `$_SERVER['DATAPHYRE_RUNTIME_REALTIME_BOOTSTRAP'] === '1'`:

```php
\dataphyre\realtime::register(
    '/orders/events',
    static function (array $handshake): array|false {
        // Validate the required Origin and an application credential here.
        return $authorized ? ['subject' => $subjectId] : false;
    },
    static function (array $authorization, ?string $cursor): array {
        return ['cursor' => $nextCursor, 'events' => $events];
    },
);
```

The authorization callback runs before the `101` response. It receives a
bounded handshake containing `path`, required `origin`, normalized lowercase
`headers`, parsed scalar `query`, and `remote_address`; it returns `false` or a
bounded authorization-context array. The event callback returns exactly a
cursor and a list of JSON-serializable events. Dataphyre owns client, header,
frame, queue, callback-time, polling, ping/pong, and backpressure limits.
Inbound application data frames are rejected; control ping, pong, and close
frames follow the WebSocket protocol. The reserved
`/dataphyre/runtime/realtime/probe` path is framework-owned and cannot be
registered by applications.

Inside a running exact candidate image, the platform runs:

```text
php runtime/modules/core/kernel/application_runtime_realtime_probe.php
```

The command accepts no arguments, ports, paths, or credentials. Through the
private supervisor it exercises the fixed public listener on loopback, verifies
the `101` handshake, receives one framework-owned event, and proves ping/pong
and close frames. Realtime startup separately invokes every application
authorization callback with the reserved invalid Origin
`https://dataphyre.invalid` and requires strict rejection. Its bounded evidence
separates `framework_listener_roundtrip` from
`application_authorization_rejections`; neither is a claim that a production
application credential was accepted. Applications requiring a successful-auth
release assertion must supply that application-owned proof separately.
The evidence also reports the sealed application registration count and
`registration_sha256`; the exact-image probe must match the registration digest
recorded by release preflight.

The same exact candidate image must also pass the fixed private runtime release
probe:

```text
php runtime/modules/core/kernel/application_runtime_release_probe.php
```

It accepts no arguments or application settings. It warms `GET /health` three
times, measures 20 dynamic requests against a fixed 750 ms p95 budget, requires
eight concurrent dynamic requests to complete within 3000 ms, and reads the
private supervisor cadence evidence. Promotion requires at least one completed
successful cadence cycle; any measured per-definition start, completion, or
recurrence deadline breach makes the measured result fail. Empty, claimed-only,
and partially observed cycles preserve that result; only a later nonempty cycle
that observes every due definition within its cadence returns it to `ok`.
Cadence deadlines include one fixed scheduler wake interval and one second for
durable timestamp precision; neither is application-configurable. This does not
change a definition's cadence or callback timeout. Canonical evidence is bounded
to 2048 bytes and the fixed command budget is 30 seconds.

TLS terminates at the Dataphyre Cloud edge. The fixed container ingress accepts
the edge's plain HTTP connection and must never be published directly without
that platform TLS and traffic-identity boundary.

PID 1 owns scheduler registration, durable cadence state, and one ephemeral
Ed25519 keypair per container generation. It balances the immutable registration
over absolute cadence phases and keeps recurring callbacks on those phases, so
completion timing cannot collapse later cycles into bursts. It issues canonical,
one-time signed `registration`, `noop`, and `callback` requests to the private
scheduler CGI gateway. The gateway must claim the exact request from PID 1 before
loading the ordinary application bootstrap. The private key never enters a
child; the public key alone is provided through that child's single-use
environment.

Registration must produce a complete, unique, immutable definition set. PID 1
retains the full definitions privately and exposes only their count and digest.
The cadence snapshot counts every due definition, including work already
claimed by another cycle, while dispatch receives only the currently claimable
subset. A claimed definition therefore remains unmeasured and cannot make a
partial cycle look complete. For each claimable definition, PID 1 obtains one
durable generation- and release-bound claim before dispatch. Success is
recorded only after the exact callback process exits successfully and is reaped;
the root gateway discards all child output and constructs the fixed callback
receipt itself. A failure releases that claim and marks the cycle failed without
starving later definitions. Deactivation stops new claims after the current
callback drains. Task paths, claims, callback output, signing keys, and
credentials never enter status evidence.

The private status contract `dataphyre.application_runtime.v6` exposes the
supervisor identity, immutable application/release identity, activation mode,
active state, `scheduler_cycle_in_progress`, the rootless HTTP gateway, FPM
master and eight worker identities, fixed socket and native-generation hashes,
spawn-captured scheduler/realtime process identities, root-only control and
scheduler Unix-socket identities, bounded scheduler registration and noop proof,
durable scheduler-state identity, and business cadence. In signal mode,
`SIGUSR1` activates dispatch and `SIGUSR2` deactivates it. The decision is
persisted at the fixed framework path
`/var/lib/dataphyre/runtime-control/activation` as an atomic root-owned
`0600` file inside a root-owned `0700` directory before the in-memory state
changes. A restart of the same container therefore preserves activation;
recreating a container starts inactive because the image has no latch file.

## Kernel Config Topology

Dataphyre now treats kernel module config as readonly module-local arrays instead of one shared mutable `dataphyre` config bag.

Use these stores:

- `CFG`
  - application config
- `DP_CORE_CFG`
  - core kernel config
- `DP_<MODULE>_CFG`
  - readonly config constant defined by the owning module kernel

Kernel modules define their config with `dp_define_module_config(...)`, which merges:

- `dataphyre/config/<module>.php`
- `applications/<app>/backend/dataphyre/config/<module>.php`
- `applications/<app>/backend/dataphyre/cache/config/<module>.compiled.php`

Those config files should return arrays.

Example:

```php
return [
	'default_guard'=>'session',
	'guards'=>[
		'session'=>[
			'driver'=>'session',
			'provider'=>'users',
		],
	],
];
```

Kernel and framework code for a Dataphyre module should then read the effective constant, for example:

```php
$default_guard=DP_ACCESS_CFG['framework']['default_guard'] ?? 'session';
```

`Config` and the `config(...)` helpers are still useful for application config and scoped runtime access. They are no longer the preferred documentation surface for Dataphyre kernel module config.

## Optional Framework Layer

Load it explicitly:

```php
\dataphyre\core::load_framework_module('core');
```

The framework namespace is:

```php
use Dataphyre\App;
use Dataphyre\Application;
use Dataphyre\ApplicationCatalog;
use Dataphyre\Bootstrap;
use Dataphyre\BootstrapPlan;
use Dataphyre\BootstrapCatalog;
use Dataphyre\Csrf;
use Dataphyre\CsrfToken;
use Dataphyre\Config;
use Dataphyre\ConfigRepository;
use Dataphyre\ConfigSnapshot;
use Dataphyre\ClientAddress;
use Dataphyre\Env;
use Dataphyre\EnvRepository;
use Dataphyre\EnvSnapshot;
use Dataphyre\Url;
use Dataphyre\UrlValue;
use Dataphyre\Date;
use Dataphyre\DateValue;
use Dataphyre\Dialback;
use Dataphyre\DialbackEvent;
use Dataphyre\DialbackCatalog;
use Dataphyre\Runtime;
use Dataphyre\RuntimeState;
use Dataphyre\RuntimeTrace;
use Dataphyre\Module;
use Dataphyre\ModuleDefinition;
use Dataphyre\ModuleCatalog;
```

The framework layer stays thin on purpose. It does not replace the kernel. It gives common application code a more direct, readable surface for the same runtime primitives.

## At A Glance

Use this map when you know what you need to do, but not which core object owns it:

| Task | Primary surface |
| --- | --- |
| Find the active application | `App::current()` or `Runtime::application()` |
| List known applications | `Application::catalog()` or `App::catalog()` |
| Check whether an app can boot | `Bootstrap::resolve(...)` or `$application->bootstrapPlan()` |
| Inspect runtime state | `Runtime::state()` |
| Load another framework module | `App::loadFrameworkModule(...)` or `Module::loadFramework(...)` |
| Inspect effective module availability | `Module::catalog()` |
| Read nested config | `Config::get(...)` or `Config::scope(...)` |
| Hold an immutable config snapshot | `Config::snapshot(...)` |
| Store request-local state | `Env::set(...)` or `Env::scope(...)` |
| Generate or validate CSRF tokens | `Csrf::token(...)` / `Csrf::validate(...)` |
| Inspect the resolved client IP | `Runtime::clientAddress()` |
| Work with typed URLs | `Url::value(...)` or `Url::currentValue(...)` |
| Work with typed date/time values | `Date::value(...)` or `Date::nowValue(...)` |
| Inspect registered dialbacks | `Dialback::catalog(...)` |
| Inspect cross-module execution traces | `Runtime::trace(...)` |

## Mental Model

Core breaks down into a few clear concerns:

- `App`, `Application`, `ApplicationCatalog`: application discovery and identity
- `Bootstrap`, `BootstrapPlan`, `BootstrapCatalog`: how an application will boot and whether it is bootable
- `Runtime`, `RuntimeState`, `RuntimeTrace`: current runtime context and execution observability
- `Module`, `ModuleDefinition`, `ModuleCatalog`: module discovery, effective availability, and framework loading
- `Config`, `ConfigRepository`, `ConfigSnapshot`: configuration state and scoped config access
- `Env`, `EnvRepository`, `EnvSnapshot`: in-process runtime state and scoped env access
- `Csrf`, `CsrfToken`, `ClientAddress`: request-adjacent safety and identity helpers
- `Url`, `UrlValue`, `Date`, `DateValue`: typed convenience objects over the shared URL and date helpers
- `Dialback`, `DialbackEvent`, `DialbackCatalog`: callback registration and dialback introspection

That gives the core framework layer a simple progression:

1. discover the application and modules
2. inspect boot/runtime state
3. read or mutate config/env
4. use request-adjacent helpers like CSRF and client identity
5. inspect cross-module execution through runtime traces when needed

## Choosing A Surface

Use the framework layer when application code wants readability, typed objects, or scoped helper objects.

Use the kernel directly when:

- bootstrapping happens before framework modules are loaded
- a low-level integration already depends on `\dataphyre\core` or `\dataphyre\runtime`
- you need the exact primitive behavior without the framework object layer

As a rule of thumb:

- prefer `Dataphyre\...` in application and framework code
- prefer `\dataphyre\...` in bootstrap, deep kernel, or compatibility code

## Kernel To Framework Mapping

If you already know the kernel layer, this is the shortest translation table:

| Kernel primitive | Framework surface | Use it when |
| --- | --- | --- |
| `\dataphyre\core::load_framework_module(...)` | `App::loadFrameworkModule(...)` or `Module::loadFramework(...)` | application code wants readable module loading |
| `\dataphyre\core::load_framework_modules(...)` | `App::loadFrameworkModules(...)` or `Module::loadFrameworkMany(...)` | you are enabling several framework modules together |
| `\dataphyre\core::get_config(...)` | `Config::get(...)` | you want nested reads with defaults |
| `\dataphyre\core::add_config(...)` | `Config::set(...)` / `Config::merge(...)` | you want scoped config mutation instead of raw arrays |
| `\dataphyre\core::register_dialback(...)` | `Dialback::register(...)` | you want typed dialback registration and later inspection |
| `\dataphyre\core::dialback(...)` | `Dialback::fire(...)` | you want a named dialback dispatch path |
| `\dataphyre\runtime::resolve_application_definition(...)` | `Application::discover(...)` or `Bootstrap::resolve(...)` | you need typed application or boot planning |
| `\dataphyre\runtime::current_application_definition()` | `Application::current()` or `Runtime::applicationDefinition()` | you need the current application through the framework layer |

## Object Selection Guide

When there are multiple valid surfaces, this is the shortest way to choose:

| If you need... | Prefer... | Why |
| --- | --- | --- |
| the active application only | `App::current()` or `Runtime::application()` | shortest direct path |
| many applications | `Application::catalog()` | typed collection instead of raw names |
| boot planning | `BootstrapPlan` | separates planning from execution |
| live runtime state | `Runtime::state()` | current application + modules + tracing in one snapshot |
| mutable nested config access | `ConfigRepository` | scoped reads and writes |
| immutable config view | `ConfigSnapshot` | safe to pass around without mutation |
| mutable request-local state | `EnvRepository` | prefix-scoped writes |
| immutable request-local state | `EnvSnapshot` | stable inspection view |
| one module’s effective metadata | `ModuleDefinition` | typed effective module state |
| many modules | `ModuleCatalog` | filtering and iteration |
| one dialback event | `DialbackEvent` | typed callback inspection |
| many dialback events | `DialbackCatalog` | bulk introspection and prefix filtering |
| typed URL mutation | `UrlValue` | query/path/fragment manipulation |
| typed date/time mutation | `DateValue` | timezone conversion and formatting |

As a rule:

- use the facade when you want the shortest direct operation
- use the typed object when you want to inspect, pass around, or compose state
- use the snapshot variant when you want read stability
- use the repository variant when you want scoped mutation

## Core Rules

There are a few core rules worth keeping in mind while using this layer:

- `Config` is durable application/runtime configuration; `Env` is in-process mutable state for the current request or execution path.
- `Config` scopes understand nested slash paths like `app/features/api_trace`; `Env` scopes are key prefixes like `request/id`.
- `Bootstrap` answers "how would this app boot?"; `Runtime` answers "what is active right now?"
- `Module::definition(...)` reflects effective module state, including app-level disable markers, not just raw config.
- `Runtime::trace(...)` is for observability and debugging; it is intentionally suppressed when `IS_PRODUCTION === true`.

## Surface Comparisons

These are the comparisons that come up most often in real code reviews:

| If you are deciding between... | Reach for... | Because |
| --- | --- | --- |
| `App` and `Application` | `App` for the active app and quick framework loads; `Application` for a concrete application object | `App` is the short facade, `Application` is the inspectable value object |
| `Bootstrap` and `Runtime` | `Bootstrap` for planning; `Runtime` for live state | one answers "can this boot?", the other answers "what is active?" |
| `Config` and `Env` | `Config` for policy and durable settings; `Env` for request or execution state | config is stable intent, env is mutable in-process context |
| `ConfigRepository` and `ConfigSnapshot` | repository for mutation; snapshot for stable reads | repositories stay live, snapshots freeze a view |
| `EnvRepository` and `EnvSnapshot` | repository for scoped request state; snapshot for handoff or logging | the snapshot stays safe after later writes |
| `ModuleDefinition` and `ModuleCatalog` | definition for one module; catalog for filtering and iteration | one gives depth, the other gives breadth |
| `RuntimeState` and `RuntimeTrace` | state for topology; trace for execution history | state is static context, trace is what actually ran |

## Recommended Flow

For most application-facing code, the strongest sequence is:

1. identify the active application through `App` or `Runtime`
2. inspect runtime and module state through `Runtime::state()` and `Module::catalog()`
3. work inside scoped config or env repositories instead of raw nested arrays
4. use typed URL/date/CSRF/client helpers when a raw string would otherwise leak through the code
5. use `Runtime::trace(...)` only when you actually need execution visibility

## By Code Location

Core gets much easier to use when you pick the surface based on where the code lives:

| Code location | Start with | Why |
| --- | --- | --- |
| bootstrap or early runtime setup | kernel helpers, `Application`, `Bootstrap` | these paths often run before other framework modules are loaded |
| application services and handlers | `App`, `Runtime`, `Config`, `Env`, `Module` | this is the normal framework-facing path |
| request and form handling | `Csrf`, `ClientAddress`, `Env` | request-local state and safety helpers stay explicit |
| cross-module debugging | `Runtime::trace(...)`, `RuntimeState`, `DialbackCatalog` | these surfaces explain live behavior without dropping to raw globals |
| normalization or formatting helpers | `UrlValue`, `DateValue` | typed values keep strings from leaking through service code |

## Safe Handoff Patterns

When core state crosses a boundary, the safest object is usually not the same object you started with:

| If you are handing state to... | Prefer... | Why |
| --- | --- | --- |
| a service that only reads config | `ConfigSnapshot` | avoids later writes changing the caller's assumptions |
| a logger, queue payload, or diagnostic bundle | `EnvSnapshot` plus `RuntimeState::summary()` | captures request-local state without keeping live mutable handles |
| a downstream helper that needs one module's status | `ModuleDefinition` | keeps the effective state explicit |
| UI or API output that needs URL or time values | `UrlValue` / `DateValue` | typed values keep formatting and mutation rules local |
| security-sensitive form handling | `CsrfToken` and `ClientAddress` | keeps token and client identity behavior explicit |

As a rule:

- repositories are good local working surfaces
- snapshots are good boundary surfaces
- typed value objects are good transport surfaces

## Boundary Checklist

Before you pass core state into another layer, check these first:

- Do you need live mutable state or a stable snapshot?
- Is the data durable configuration or request-local execution context?
- Are you planning a future boot or inspecting the active runtime?
- Do you need effective module availability or just raw module existence?
- Could tracing be unavailable because production mode suppresses it?

If the answer is unclear, the safer default is usually:

- `ConfigSnapshot` or `EnvSnapshot` at boundaries
- `RuntimeState` for topology
- `RuntimeTrace` only for diagnostics
- `ModuleDefinition` for one effective module decision

## Boot Modes

Core recognizes three application boot paths:

- `compiled_routes`
  Use this when the application boots through a compiled routing manifest.
  This is usually the strongest fast-path for framework-first applications.
- `framework`
  Use this when the application has a dedicated framework bootstrap file.
  This is the normal path when the application is framework-native but not route-compiled.
- `legacy`
  Use this when the application falls back to the older bootstrap path.
  This is primarily for compatibility and transition paths.

`Application::bootMode()` and `BootstrapPlan::bootMode()` tell you which path wins for a given application.

`BootstrapPlan::availableBootModes()` tells you which files are present.

`BootstrapPlan::missingBootModes()` tells you which paths are absent.

## Start Here

For most application code, the happy path is:

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\App;
use Dataphyre\Config;
use Dataphyre\Csrf;
use Dataphyre\Env;
use Dataphyre\Module;
use Dataphyre\Runtime;

$application_id=App::id();
$timezone=Config::get('app/base_timezone', 'UTC');
$request_id=Env::get('request_id');
$sql=Module::definition('sql');
$runtime=Runtime::state();
$boot=Runtime::bootstrap();
$csrf=Csrf::token('login_form');
$client=Runtime::clientAddress();
```

When you need lower-level control, drop straight back to the kernel:

```php
$timezone=\dataphyre\core::get_config('app/base_timezone');
```

## Common Workflows

### Inspect Application Bootability

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Bootstrap;

$plan=Bootstrap::resolve('example_app');

if($plan===null){
	throw new RuntimeException('Application not found.');
}

if(!$plan->canBoot()){
	$missing=$plan->missingBootModes();
}
```

### Compare Planned Boot State To Active Runtime

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Bootstrap;
use Dataphyre\Runtime;

$planned=Bootstrap::resolve('example_app');
$active=Runtime::bootstrap();

$planned_mode=$planned?->bootMode();
$active_mode=$active?->bootMode();
```

### Plan Then Boot Explicitly

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Bootstrap;

$plan=Bootstrap::resolve('example_app');

if($plan===null){
	throw new RuntimeException('Application not found.');
}

if(!$plan->canBoot()){
	throw new RuntimeException('Application is not bootable.');
}

$plan->boot();
```

### Work Inside Scoped Config And Env

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Config;
use Dataphyre\Env;

$app=Config::scope('app');
$request=Env::scope('request');

$app_debug=$app->get('debug', false);
$request->set('id', 'rq_123');
```

### Use Config Snapshots Before Mutating Runtime State

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Config;

$before=Config::snapshot('app/features');
$features=Config::scope('app/features');

$features->merge([
	'api_trace'=>false,
]);
```

### Capture Stable State Before Passing It Across Layers

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Config;
use Dataphyre\Env;
use Dataphyre\Runtime;

$state=Runtime::state();
$config=Config::snapshot('app');
$request=Env::snapshot('request');

$payload=[
	'runtime'=>$state->summary(),
	'config'=>$config->only(['name', 'base_timezone']),
	'request'=>$request->only(['id', 'tenant_id']),
];
```

### Freeze Request Context Before Logging Or Queueing

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Env;
use Dataphyre\Runtime;

$request=Env::snapshot('request');
$client=Runtime::clientAddress();

$context=[
	'request'=>$request->only(['id', 'tenant_id', 'user_id']),
	'client'=>$client->toArray(),
];
```

### Build Request-Safe Form State

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Csrf;
use Dataphyre\Runtime;

$token=Csrf::token('profile_form');
$client=Runtime::clientAddress();

$field=$token->hiddenField();
$ip=$client->ip();
```

### Inspect Module Availability Before Loading Framework Features

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Module;

$modules=Module::catalog();
$sql=$modules->get('sql');

if($sql!==null && $sql->hasFramework()){
	Module::loadFramework('sql');
}
```

### Inspect Cross-Module Runtime Execution

```php
\dataphyre\core::load_framework_module('core');
\dataphyre\core::load_framework_modules(['templating', 'sql']);

use Dataphyre\Runtime;
use Dataphyre\Templating\Templating;

$result=Templating::inspect('/var/www/app/views/orders.tpl', [
	'tenant_id'=>$tenant_id,
]);

$trace=Runtime::trace($result);
$summary=$trace->summary();
```

### Resolve Core State Before Loading Another Module

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\App;
use Dataphyre\Module;
use Dataphyre\Runtime;

$runtime=Runtime::state();
$sql=Module::definition('sql');

if($sql!==null && $sql->hasFramework()){
	App::loadFrameworkModule('sql');
}
```

## Cross-Module Patterns

Core is usually the first framework layer you load, then it becomes the coordination surface for the rest of Dataphyre.

### Core + SQL

Use core to discover state and SQL to execute:

```php
\dataphyre\core::load_framework_modules(['core', 'sql']);

use Dataphyre\Module;
use Dataphyre\Runtime;

$runtime=Runtime::state();
$sql=Module::definition('sql');
```

### Core + Templating

Use core to inspect runtime and templating to render:

```php
\dataphyre\core::load_framework_modules(['core', 'templating']);

use Dataphyre\Runtime;
use Dataphyre\Templating\Templating;

$result=Templating::inspect('/var/www/app/views/home.tpl', []);
$trace=Runtime::trace($result);
```

### Core + API

Use core to inspect environment, bootability, and client identity around API execution:

```php
\dataphyre\core::load_framework_modules(['core', 'api']);

use Dataphyre\Runtime;

$client=Runtime::clientAddress();
$boot=Runtime::bootstrap();
```

## Common Pitfalls

- `Config::set('app/debug', true)` writes nested config. If you need a literal top-level key containing a slash, write through the kernel config array directly.
- `Env::scope('request')` is a prefix helper, not a nested array helper. It manages keys like `request/id`, `request/user_id`, and `request/tenant_id`.
- `BootstrapPlan::boot()` hands execution over to the kernel runtime. Treat it as an execution boundary, not just another inspection helper.
- `CsrfToken::equals(...)` compares against the currently generated token value. `CsrfToken::validate(...)` uses the kernel validator path.
- `Runtime::trace(...)` may return an empty runtime trace in production by design, even when the same code path is rich in development.
- `ClientAddress::ip()` can come from a trusted forwarded header. Use `forwarded()` and `sourceHeader()` when that distinction matters.
- `dp_module_required($module, $required, $min)` treats the dependency as `$min+` by default. Pass the fourth `$max_version` argument only when you intentionally want an upper bound.

## Avoid These Defaults

- Do not put request ids, tenant ids, or per-user execution state into `Config`; keep them in `Env`.
- Do not pass live repositories into logging, queueing, or reporting paths when a snapshot would do; freeze the state first.
- Do not call `BootstrapPlan::boot()` just to inspect an application; use `summary()`, `bootMode()`, and `canBoot()` until you are ready to hand execution over.
- Do not build business logic on `Runtime::trace(...)`; traces are diagnostic surfaces and may be intentionally empty in production.
- Do not use raw module existence as a proxy for effective availability; prefer `Module::definition(...)` when enablement matters.
- Do not pin module dependencies to a patch version unless the caller is genuinely incompatible with newer patch releases.

## Troubleshooting

### `Application::current()` or `App::current()` returns `null`

Check:

- the project root can be resolved
- the application id is correct
- the application exists inside a configured application root

Use:

```php
$applications=Application::catalog();
$names=$applications->names();
```

### `Bootstrap::resolve(...)` finds the app but `canBoot()` is `false`

Inspect:

```php
$plan=Bootstrap::resolve('example_app');
$paths=$plan?->bootPaths();
$missing=$plan?->missingBootModes();
```

That usually means the application definition exists, but none of the executable boot paths are present.

### `Module::definition(...)` returns `null`

That means the module is not effectively available.

Check:

- whether the module exists at all
- whether it is disabled by config
- whether the app-level `-module` disable marker is present

Use:

```php
$known=Module::known('sql');
$effective=Module::definition('sql');
```

### `Runtime::trace(...)` is empty in development

Check:

- the source object actually carries a render trace id
- the relevant modules are loaded
- the call is not happening in production mode

Use:

```php
$trace=Runtime::trace($result);
$summary=$trace->summary();
```

### `Config::snapshot(...)` or `Env::snapshot(...)` does not reflect later writes

That is expected. Snapshots are immutable views.

Use:

```php
$app=Config::scope('app');
$snapshot=Config::snapshot('app');

$app->set('debug', true);
$after=$app->get('debug');
$before=$snapshot->get('debug');
```

If you need live reads after mutation, stay on the repository or facade instead of the snapshot object.

### `Csrf::validate(...)` is `false`

Check:

- the same form name is used for generation and validation
- the token came from the current session
- the token was not compared after refresh or session loss

Use:

```php
$token=Csrf::token('profile_form');
$matches=$token->equals($_POST['csrf'] ?? null);
```

### `ClientAddress::ip()` differs from `REMOTE_ADDR`

That can be correct when Dataphyre trusts a forwarded proxy header.

Inspect:

```php
$client=Runtime::clientAddress();

$ip=$client->ip();
$remote=$client->remoteAddress();
$forwarded=$client->forwarded();
$header=$client->sourceHeader();
```

Use `remoteAddress()` when you specifically need the socket peer, and use `ip()` when you need Dataphyre's resolved client address.

## `Dataphyre\App`

`App` is the framework facade for current-application and framework-loading concerns.

Methods include:

- `current(...)`
- `find(...)`
- `has(...)`
- `available(...)`
- `catalog(...)`
- `discoverMany(...)`
- `roots(...)`
- `bootstrap(...)`
- `id()`
- `root()`
- `option(...)`
- `loadFrameworkModule(...)`
- `loadFrameworkModules(...)`

Example:

```php
$current=App::current();

$catalog=App::catalog();
$boot=App::bootstrap();

$known_apps=App::available();

$has_example_app=App::has('example_app');

App::loadFrameworkModules(['sql', 'access']);
```

## `Dataphyre\Application`

`Application` is the typed framework object for an application definition. It extends the kernel `application_definition` and adds convenience helpers.

Static helpers include:

- `current(...)`
- `discover(...)`
- `exists(...)`
- `discoverMany(...)`
- `roots(...)`
- `available(...)`
- `catalog(...)`
- `legacy(...)`

Instance helpers include:

- `option(...)`
- `hasOption(...)`
- `hasRootpathFile()`
- `hasRoutesFile()`
- `hasCompiledRoutes()`
- `hasAutoload()`
- `autoloadPrefixes()`
- `hasFrameworkBootstrap()`
- `hasLegacyBootstrap()`
- `fallbackToLegacyBootstrap()`
- `bootMode()`
- `canBoot()`
- `bootstrapPlan(...)`
- `toArray()`
- `jsonSerialize()`

Example:

```php
$application=Application::current();

if($application!==null && $application->canBoot()){
	$boot_mode=$application->bootMode();
	$autoload=$application->autoloadPrefixes();
	$plan=$application->bootstrapPlan();
}
```

## `Dataphyre\ApplicationCatalog`

`ApplicationCatalog` is the typed collection returned by `Application::catalog(...)` and `Application::discoverMany(...)`.

Methods include:

- `projectRoot()`
- `all()`
- `names()`
- `first()`
- `get(...)`
- `has(...)`
- `count()`
- `getIterator()`
- `toArray()`
- `jsonSerialize()`

Example:

```php
$catalog=Application::catalog();

foreach($catalog as $application){
	$boot_mode=$application->bootMode();
}

$example_app=$catalog->get('example_app');
```

## `Dataphyre\Config`

`Config` is the framework facade over Dataphyre configuration state.

Methods include:

- `get(...)`
- `has(...)`
- `set(...)`
- `merge(...)`
- `all()`
- `repository(...)`
- `scope(...)`
- `snapshot(...)`
- `only(...)`
- `except(...)`
- `keys(...)`

Example:

```php
$debug=Config::get('app/debug', false);

Config::set('app/debug', true);

Config::merge([
	'app'=>[
		'default_timezone'=>'UTC',
	],
]);

$app=Config::scope('app');
$snapshot=Config::snapshot('app');
```

## `Dataphyre\ConfigRepository`

`ConfigRepository` is the typed scoped config object returned by `Config::repository(...)` and `Config::scope(...)`.

Methods include:

- `path()`
- `exists()`
- `value(...)`
- `get(...)`
- `has(...)`
- `set(...)`
- `merge(...)`
- `all()`
- `only(...)`
- `except(...)`
- `keys()`
- `isEmpty()`
- `scope(...)`
- `snapshot()`
- `toArray()`
- `jsonSerialize()`

Example:

```php
$app=Config::scope('app');

$timezone=$app->get('base_timezone', 'UTC');
$app->set('debug', true);
$app->merge([
	'features'=>[
		'api_trace'=>false,
	],
]);

$features=$app->scope('features')->all();
```

## `Dataphyre\ConfigSnapshot`

`ConfigSnapshot` is the immutable config snapshot returned by `Config::snapshot(...)` and `ConfigRepository::snapshot()`.

Methods include:

- `path()`
- `exists()`
- `value(...)`
- `get(...)`
- `has(...)`
- `all()`
- `only(...)`
- `except(...)`
- `keys()`
- `isEmpty()`
- `scope(...)`
- `toArray()`
- `jsonSerialize()`

Example:

```php
$snapshot=Config::snapshot('app');

$timezone=$snapshot->get('base_timezone', 'UTC');
$feature_snapshot=$snapshot->scope('features');
```

## `Dataphyre\Env`

`Env` is the framework facade over in-process runtime environment state. It is independent from `\dataphyre\core` and does not read or write PHP's OS-level environment table.

Methods include:

- `all()`
- `get(...)`
- `has(...)`
- `set(...)`
- `merge(...)`
- `forget(...)`
- `pull(...)`
- `repository(...)`
- `scope(...)`
- `snapshot(...)`
- `only(...)`
- `except(...)`
- `keys()`

Example:

```php
Env::set([
	'request_id'=>'rq_123',
	'tenant_id'=>'TENANT_1',
]);

$tenant_id=Env::get('tenant_id');
$request_id=Env::pull('request_id');

$request=Env::scope('request');
$request->set('id', 'rq_456');

Env::forget('tenant_id');
```

## `Dataphyre\EnvRepository`

`EnvRepository` is the typed scoped environment object returned by `Env::repository(...)` and `Env::scope(...)`.

Methods include:

- `prefix()`
- `separator()`
- `get(...)`
- `has(...)`
- `set(...)`
- `merge(...)`
- `forget(...)`
- `pull(...)`
- `all()`
- `only(...)`
- `except(...)`
- `keys()`
- `isEmpty()`
- `scope(...)`
- `snapshot()`
- `toArray()`
- `jsonSerialize()`

Example:

```php
$request=Env::scope('request');

$request->set('id', 'rq_123');
$request->merge([
	'user_id'=>42,
	'tenant_id'=>'TENANT_1',
]);

$request_data=$request->all();
```

## `Dataphyre\EnvSnapshot`

`EnvSnapshot` is the immutable environment snapshot returned by `Env::snapshot(...)` and `EnvRepository::snapshot()`.

Methods include:

- `prefix()`
- `separator()`
- `all()`
- `get(...)`
- `has(...)`
- `only(...)`
- `except(...)`
- `keys()`
- `isEmpty()`
- `scope(...)`
- `toArray()`
- `jsonSerialize()`

Example:

```php
$snapshot=Env::snapshot('request');

$request_id=$snapshot->get('id');
$auth=$snapshot->scope('auth');
```

## `Dataphyre\Csrf`

`Csrf` is the framework facade for core CSRF token generation and validation.

Methods include:

- `token(...)`
- `value(...)`
- `validate(...)`
- `hiddenField(...)`

Example:

```php
$token=Csrf::token('login_form');
$value=Csrf::value('login_form');
$hidden=Csrf::hiddenField('login_form');
$valid=Csrf::validate('login_form', $_POST['csrf'] ?? null);
```

## `Dataphyre\CsrfToken`

`CsrfToken` is the typed CSRF token object returned by `Csrf::token(...)`.

Methods include:

- `for(...)`
- `formName()`
- `value()`
- `refresh()`
- `validate(...)`
- `equals(...)`
- `hiddenField(...)`
- `toArray()`
- `jsonSerialize()`
- `__toString()`

Example:

```php
$token=Csrf::token('account_update');

$field=$token->hiddenField();
$matches=$token->equals($_POST['csrf'] ?? null);
```

## `Dataphyre\ClientAddress`

`ClientAddress` is the typed client-address object returned by `Runtime::clientAddress()`.

Methods include:

- `current()`
- `fromArray(...)`
- `ip()`
- `remoteAddress()`
- `source()`
- `sourceHeader()`
- `trustedProxy()`
- `forwarded()`
- `trustedHeaders()`
- `trustedProxies()`
- `isIpv4()`
- `isIpv6()`
- `isLoopback()`
- `isPrivate()`
- `toArray()`
- `jsonSerialize()`
- `__toString()`

Example:

```php
$client=Runtime::clientAddress();

$ip=$client->ip();
$forwarded=$client->forwarded();
$source=$client->sourceHeader();
```

## `Dataphyre\Bootstrap`

`Bootstrap` is the framework facade for typed boot planning and application handoff into the kernel runtime.

Methods include:

- `current(...)`
- `resolve(...)`
- `for(...)`
- `catalog(...)`
- `boot(...)`

Example:

```php
$plan=Bootstrap::resolve('example_app');

if($plan!==null && $plan->canBoot()){
	$summary=$plan->summary();
}
```

## `Dataphyre\BootstrapPlan`

`BootstrapPlan` is the typed boot plan for a single application.

Methods include:

- `projectRoot()`
- `application()`
- `applicationId()`
- `bootMode()`
- `canBoot()`
- `usesCompiledRoutes()`
- `usesFrameworkBootstrap()`
- `usesLegacyBootstrap()`
- `fallbackToLegacyBootstrap()`
- `hasRootpathFile()`
- `rootpathPrimingRequired()`
- `autoloadPrefixes()`
- `bootPaths()`
- `availableBootModes()`
- `missingBootModes()`
- `summary()`
- `boot()`
- `toArray()`
- `jsonSerialize()`

Example:

```php
$plan=Bootstrap::resolve('example_app');

if($plan!==null){
	$boot_mode=$plan->bootMode();
	$paths=$plan->bootPaths();
	$needs_rootpaths=$plan->rootpathPrimingRequired();
}
```

## `Dataphyre\BootstrapCatalog`

`BootstrapCatalog` is the typed collection returned by `Bootstrap::catalog(...)` and `Runtime::bootstraps()`.

Methods include:

- `projectRoot()`
- `all()`
- `names()`
- `first()`
- `get(...)`
- `has(...)`
- `bootable()`
- `unbootable()`
- `bootableNames()`
- `unbootableNames()`
- `count()`
- `getIterator()`
- `toArray()`
- `jsonSerialize()`

Example:

```php
$catalog=Bootstrap::catalog();

$bootable=$catalog->bootableNames();
$example_app=$catalog->get('example_app');
```

## `Dataphyre\Url`

`Url` wraps the common URL helpers from the core kernel.

Methods include:

- `base()`
- `baseValue()`
- `current(...)`
- `currentValue(...)`
- `full()`
- `fullValue()`
- `withQuery(...)`
- `currentWithQuery(...)`
- `value(...)`

Example:

```php
$base=Url::base();
$full=Url::full();
$updated=Url::currentWithQuery(['page'=>2], ['token']);
$url=Url::value('https://example.com/orders?page=2');
```

## `Dataphyre\UrlValue`

`UrlValue` is the typed URL object returned by `Url::value(...)`, `Url::currentValue(...)`, `Url::baseValue()`, and `Url::fullValue()`.

Methods include:

- `fromString(...)`
- `raw()`
- `scheme()`
- `host()`
- `port()`
- `user()`
- `pass()`
- `path()`
- `fragment()`
- `query()`
- `hasQuery(...)`
- `queryValue(...)`
- `isAbsolute()`
- `isSecure()`
- `base()`
- `withQuery(...)`
- `withoutQuery(...)`
- `withPath(...)`
- `withFragment(...)`
- `toArray()`
- `jsonSerialize()`
- `__toString()`

Example:

```php
$url=Url::value('https://example.com/orders?page=2&sort=desc#summary');

$page=$url->queryValue('page');
$filtered=$url->withoutQuery(['sort'])->withFragment('details');
```

## `Dataphyre\Date`

`Date` wraps the shared Dataphyre time and formatting helpers and exposes typed date values.

Methods include:

- `now(...)`
- `nowValue(...)`
- `format(...)`
- `toUser(...)`
- `toServer(...)`
- `serverTimezone()`
- `defaultUserTimezone()`
- `value(...)`
- `serverValue(...)`
- `userValue(...)`
- `normalizeTimezone(...)`
- `normalizeUserTimezone(...)`

Example:

```php
$now=Date::now();
$display=Date::format('2026-04-03 12:30:00');
$user_time=Date::toUser('2026-04-03 12:30:00', 'America/Toronto');
$point=Date::serverValue('2026-04-03 12:30:00');
```

## `Dataphyre\DateValue`

`DateValue` is the typed date/time object returned by `Date::value(...)`, `Date::serverValue(...)`, `Date::userValue(...)`, and `Date::nowValue(...)`.

Methods include:

- `fromDateTime(...)`
- `fromValue(...)`
- `datetime()`
- `timezone()`
- `timestamp()`
- `format(...)`
- `translated(...)`
- `inTimezone(...)`
- `toUser(...)`
- `toServer()`
- `iso8601()`
- `sql(...)`
- `date()`
- `time(...)`
- `toArray()`
- `jsonSerialize()`

Example:

```php
$point=Date::serverValue('2026-04-03 12:30:00');
$user_point=$point->toUser('America/Toronto');
$iso=$user_point->iso8601();
$sql=$user_point->sql();
```

## `Dataphyre\Dialback`

`Dialback` is the framework facade for registering and firing dialbacks through
the shared core registry. Dialback event names are exact runtime contracts:
preserve existing names when maintaining a module, register callbacks before
firing, and add new kernel/runtime events with a module-scoped
`CALL_<MODULE>_<ACTION>` name. New Framework-owned extension points should use
`CALL_<MODULE>_FRAMEWORK_<SURFACE_OR_CONCEPT>_<ACTION>` unless the Framework code
is intentionally bridging an existing kernel hook.

Methods include:

- `fire(...)`
- `register(...)`
- `has(...)`
- `callbacks(...)`
- `names(...)`
- `count(...)`
- `callbackCount(...)`
- `event(...)`
- `events(...)`
- `catalog(...)`

Example:

```php
Dialback::register('CALL_APP_EXAMPLE', static function(string $value): string{
	return strtoupper($value);
});

$result=Dialback::fire('CALL_APP_EXAMPLE', 'hello');
$event=Dialback::event('CALL_APP_EXAMPLE');
$catalog=Dialback::catalog('CALL_APP_');
```

The lower-case kernel API remains available for legacy modules:

```php
\dataphyre\core::register_dialback('CALL_APP_EXAMPLE', static fn(string $value): string=>$value);
$result=\dataphyre\core::dialback('CALL_APP_EXAMPLE', 'hello');
```

Do not use dialbacks as hidden application rewrites. They are narrow extension
points for explicitly named behavior, diagnostics, and policy overrides. For
ordinary application behavior, prefer application code, config, callbacks,
plugins, or application-owned adapters.

## `Dataphyre\DialbackEvent`

`DialbackEvent` is the typed framework object for one dialback event and its registered callbacks.

Methods include:

- `name()`
- `callbacks()`
- `callbackDescriptions()`
- `callbackCount()`
- `hasCallbacks()`
- `isEmpty()`
- `matchesPrefix(...)`
- `register(...)`
- `fire(...)`
- `toArray()`
- `jsonSerialize()`

Example:

```php
$event=Dialback::event('CALL_APP_EXAMPLE');

if($event->hasCallbacks()){
	$descriptions=$event->callbackDescriptions();
}
```

## `Dataphyre\DialbackCatalog`

`DialbackCatalog` is the typed collection returned by `Dialback::catalog(...)` and `Dialback::events(...)`.

Methods include:

- `prefix()`
- `all()`
- `names()`
- `first()`
- `get(...)`
- `has(...)`
- `count()`
- `callbackCount()`
- `scope(...)`
- `only(...)`
- `getIterator()`
- `toArray()`
- `jsonSerialize()`

Example:

```php
$catalog=Dialback::catalog('CALL_CORE_');

$event_names=$catalog->names();
$callback_count=$catalog->callbackCount();
$csrf=$catalog->get('CALL_CORE_CSRF');
```

## `Dataphyre\Runtime`

`Runtime` is the framework facade for the current Dataphyre runtime context.

Methods include:

- `tracingEnabled()`
- `projectRoot()`
- `applicationId()`
- `hasApplication()`
- `application()`
- `applicationDefinition()`
- `applicationRoots()`
- `availableApplications()`
- `applications()`
- `bootstrap()`
- `bootstraps()`
- `clientIp()`
- `clientAddress()`
- `modules()`
- `enabledModules()`
- `disabledModules()`
- `state()`
- `trace(...)`
- `traceById(...)`

Example:

```php
$project_root=Runtime::projectRoot();
$application_id=Runtime::applicationId();
$applications=Runtime::applications();
$boot=Runtime::bootstrap();
$client_ip=Runtime::clientIp();
$modules=Runtime::enabledModules();
$state=Runtime::state();

$trace=Runtime::trace($rendered_template);
$summary=$trace->summary();
```

`Runtime::trace(...)` accepts either:

- a templating `RenderedTemplate`
- a templating `TemplateManifest`
- a raw `render_trace_id`

When `IS_PRODUCTION === true`, `Runtime::trace(...)` returns an empty runtime trace and Dataphyre suppresses module-level trace capture.

When templating and SQL are both loaded, the returned `RuntimeTrace` object stitches together:

- render trace id
- template manifest context when available
- binding trace entries from templating
- correlated SQL traces from `DB::recentTracesByContext(...)`
- canonical query fingerprint summaries for SQL and fulltext-backed bindings

That gives application code one runtime-facing path for understanding `template -> binding -> SQL/cache` execution without manually pairing templating and SQL APIs every time.

## `Dataphyre\RuntimeState`

`RuntimeState` is the typed runtime snapshot returned by `Runtime::state()`.

Methods include:

- `tracingEnabled()`
- `projectRoot()`
- `hasApplication()`
- `application()`
- `applicationId()`
- `applicationRoots()`
- `applications()`
- `modules()`
- `enabledModules()`
- `disabledModules()`
- `summary()`
- `toArray()`
- `jsonSerialize()`

Example:

```php
$state=Runtime::state();

$enabled_modules=$state->enabledModules()->names();
$applications=$state->applications()->names();
$summary=$state->summary();
```

## `Dataphyre\RuntimeTrace`

`RuntimeTrace` is the typed framework object returned by `Runtime::trace(...)`.

Methods include:

- `renderTraceId()`
- `templateName()`
- `hasManifest()`
- `manifest()`
- `hasBindings()`
- `bindingTrace()`
- `hasSqlTraces()`
- `sqlTraces()`
- `sqlTraceArrays()`
- `sqlTracesForBinding(...)`
- `orphanSqlTraces()`
- `bindingsWithSql()`
- `queryFingerprints()`
- `sqlQueryFingerprints()`
- `searchQueryFingerprints()`
- `summary()`
- `toArray()`

Example:

```php
$result=Templating::inspect('/var/www/app/views/orders.tpl', [
	'tenant_id'=>$tenant_id,
]);

$trace=Runtime::trace($result);

$bindings=$trace->bindingsWithSql();
$query_fingerprints=$trace->queryFingerprints();
$sql=$trace->sqlTraceArrays();
```

## `Dataphyre\Module`

`Module` is the framework facade for module discovery, metadata, and framework loading.

Methods include:

- `all()`
- `enabled()`
- `disabled()`
- `has(...)`
- `known(...)`
- `enabledForApp(...)`
- `metadata(...)`
- `definition(...)`
- `definitions(...)`
- `catalog(...)`
- `enabledCatalog()`
- `disabledCatalog()`
- `kernelEntry(...)`
- `kernelVersion(...)`
- `frameworkEntry(...)`
- `version(...)`
- `directory(...)`
- `commonDirectory(...)`
- `appDirectory(...)`
- `frameworkNamespace(...)`
- `hasKernel(...)`
- `hasFramework(...)`
- `loadFramework(...)`
- `loadFrameworkMany(...)`

Example:

```php
$modules=Module::enabledCatalog();

$access=Module::definition('access');

if(Module::hasFramework('sql')){
	Module::loadFramework('sql');
}
```

## `Dataphyre\ModuleDefinition`

`ModuleDefinition` is the typed framework object for module discovery metadata.

Methods include:

- `module()`
- `name()`
- `version()`
- `enabled()`
- `directory()`
- `commonDirectory()`
- `appDirectory()`
- `hasCommonSource()`
- `hasAppSource()`
- `isCommonOnly()`
- `isAppOnly()`
- `isHybrid()`
- `kernelEntry()`
- `frameworkEntry()`
- `frameworkDirectory()`
- `frameworkNamespace()`
- `hasKernel()`
- `hasFramework()`
- `toArray()`
- `jsonSerialize()`

Example:

```php
$sql=Module::definition('sql');

if($sql!==null && $sql->hasFramework()){
	$namespace=$sql->frameworkNamespace();
	$source=$sql->isHybrid() ? 'hybrid' : 'single';
}
```

## `Dataphyre\ModuleCatalog`

`ModuleCatalog` is the typed collection returned by `Module::catalog(...)`, `Module::enabledCatalog()`, and `Runtime::modules()`.

Methods include:

- `all()`
- `names()`
- `enabledNames()`
- `disabledNames()`
- `first()`
- `get(...)`
- `has(...)`
- `enabled()`
- `disabled()`
- `count()`
- `getIterator()`
- `toArray()`
- `jsonSerialize()`

Example:

```php
$catalog=Module::catalog();

$enabled_names=$catalog->enabledNames();
$disabled=$catalog->disabled();
$sql=$catalog->get('sql');
```

## Design Notes

The core framework layer is intentionally light:

- it keeps the kernel as the source of truth
- it adds readability without hiding execution
- it does not add request-time overhead unless the framework module is loaded
- it gives application code stable, named entrypoints for common runtime concerns

That keeps the Dataphyre model intact: explicit kernel primitives underneath, optional framework ergonomics on top.

## Common Recipes

### Load Framework Modules From Core

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\App;

App::loadFrameworkModules([
	'sql',
	'templating',
	'api',
]);
```

### Compare Declared And Effective Module State

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Module;

$all=Module::catalog();
$enabled=$all->enabledNames();
$disabled=$all->disabledNames();
$sql=Module::definition('sql');
```

### Snapshot Config Before Mutating Runtime State

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Config;

$before=Config::snapshot('app/features');

Config::scope('app/features')->merge([
	'api_trace'=>false,
]);
```

### Translate Kernel Habits Into Framework Code

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Config;
use Dataphyre\Dialback;
use Dataphyre\Env;

$timezone=Config::get('app/base_timezone', 'UTC');
Env::set('request_id', 'rq_123');
Dialback::register('CALL_APP_EXAMPLE', static function(): void{
});
```

### Track Request Context In Env

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Env;

$request=Env::scope('request');
$request->merge([
	'id'=>'rq_123',
	'tenant_id'=>'TENANT_1',
	'user_id'=>42,
]);
```

### Inspect Registered Dialbacks

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Dialback;

$catalog=Dialback::catalog('CALL_CORE_');
$csrf=$catalog->get('CALL_CORE_CSRF');
$descriptions=$csrf?->callbackDescriptions() ?? [];
```

### Work With Typed URL And Date Values

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Date;
use Dataphyre\Url;

$url=Url::currentValue(true)->withoutQuery(['token']);
$point=Date::nowValue()->toUser('America/Toronto');
```

### Build An Internal "Runtime Summary" Object

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Runtime;

$runtime=Runtime::state();

$summary=[
	'application'=>$runtime->applicationId(),
	'modules'=>$runtime->enabledModules()->names(),
	'client'=>Runtime::clientIp(),
	'tracing'=>$runtime->tracingEnabled(),
];
```

### Gate Optional Diagnostics Cleanly

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Runtime;

$state=Runtime::state();
$diagnostics=[
	'tracing_enabled'=>$state->tracingEnabled(),
];

if($state->tracingEnabled()){
	$trace=Runtime::trace($result);
	$diagnostics['trace']=$trace->summary();
}
```

### Build A Support Diagnostic Bundle

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Env;
use Dataphyre\Module;
use Dataphyre\Runtime;

$runtime=Runtime::state();
$request=Env::snapshot('request');
$modules=Module::enabledCatalog();

$diagnostic=[
	'runtime'=>$runtime->summary(),
	'request'=>$request->only(['id', 'tenant_id']),
	'modules'=>$modules->enabledNames(),
	'client'=>Runtime::clientAddress()->toArray(),
];
```

### Gate Optional Module Features Without Hard Failure

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Module;

$sql=Module::definition('sql');

if($sql!==null && $sql->enabled() && $sql->hasFramework()){
	Module::loadFramework('sql');
}
```

### Keep Request Context Separate From Application Config

```php
\dataphyre\core::load_framework_module('core');

use Dataphyre\Config;
use Dataphyre\Env;

$app=Config::scope('app');
$request=Env::scope('request');

$timezone=$app->get('base_timezone', 'UTC');
$request->set('locale', 'en-CA');
```
