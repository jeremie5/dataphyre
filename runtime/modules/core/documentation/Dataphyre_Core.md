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

- `common/dataphyre/config/<module>.php`
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

`Dialback` is the framework facade for registering and firing kernel dialbacks.

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
