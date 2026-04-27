# Dataphyre Routing Module

The routing module turns route definitions into compiled manifests, then dispatches incoming requests against those manifests. It supports exact paths, named parameters, splat parameters, controller handlers, include-file handlers, middleware, and API-aware compiled routes.

The kernel routing layer serves config-driven applications. The framework layer is the recommended surface for framework route files because it is explicit, compilable, and easier to inspect.

## Kernel Layer

The kernel side is responsible for:

- loading config-driven routing configuration
- matching compiled route manifests at request time
- publishing matched route parameters into `\dataphyre\routing::$bindings`
- dispatching file handlers, controller handlers, middleware pipelines, and API-aware compiled routes
- falling back to 404 behavior when nothing matches

Important kernel entrypoints include:

- `\dataphyre\routing\compiled_route_dispatcher::dispatch_file(...)`
- `\dataphyre\routing\compiled_route_dispatcher::dispatch_manifest(...)`
- `\dataphyre\routing::check_route(...)`
- `\dataphyre\routing::not_found()`

## Optional Framework Layer

Load it explicitly:

```php
\dataphyre\core::load_framework_module('routing');
```

The framework namespace is:

```php
use Dataphyre\Routing\Route;
use Dataphyre\Routing\ControllerAction;
use Dataphyre\Routing\RouteCompiler;
use Dataphyre\Routing\RouteManifest;
use Dataphyre\Routing\CompilableRoute;
use Dataphyre\Routing\Tools\CompileApplicationRoutes;
```

The framework layer is intentionally small. It focuses on route definition, controller metadata, manifest compilation, and route-file tooling.

## At A Glance

Use this map when you know what you need to do, but not which routing surface owns it:

| Task | Primary surface |
| --- | --- |
| Define a simple GET route | `Route::get(...)` |
| Define a controller-backed route | `Route` + `ControllerAction` |
| Attach middleware | `Route::middleware(...)` |
| Match a named parameter | `/{id}` in `Route::get(...)` |
| Capture the rest of the path | `/{...segments}` in `Route::any(...)` |
| Compile one routes file | `RouteCompiler::compile_file(...)` |
| Write a manifest file | `RouteCompiler::write_manifest_file(...)` |
| Compile a manifest from route objects | `RouteManifest::compile(...)` |
| Compile an application's routes end to end | `CompileApplicationRoutes::compile(...)` |
| Build a custom route builder | `CompilableRoute` |

## Mental Model

Routing breaks down into a short pipeline:

1. a routes file returns an array of `Route` objects or compiled route arrays
2. the compiler turns that into a manifest with `version`, `metadata`, and compiled `routes`
3. the dispatcher matches the incoming method and path against the compiled entries
4. matched parameters are published and the handler runs directly or through middleware

That gives the routing framework layer a simple division of responsibility:

- `Route`: define one route
- `ControllerAction`: describe a controller handler in a manifest-safe way
- `RouteManifest`: compile route objects into one manifest payload
- `RouteCompiler`: compile and persist manifests from route files
- `CompileApplicationRoutes`: compile a whole application's configured route file

## Recommended Shape

For most framework-first applications, the clean default is:

1. return `Route` objects from the application's routes file
2. use `ControllerAction` for controller handlers instead of ad hoc runtime callables
3. keep middleware explicit on the route
4. compile the routes file into a manifest
5. let the compiled dispatcher handle matching and execution

That usually looks like this:

```php
<?php

use Dataphyre\Routing\ControllerAction;
use Dataphyre\Routing\Route;

return [
	Route::get('/orders/{order_id}', ControllerAction::static(
		'app\\Http\\OrderController',
		'show',
		['bootstrap'=>__DIR__.'/bootstrap.php']
	))->middleware('auth'),
];
```

## Choosing A Surface

Use the framework layer when:

- your application has a framework routes file
- you want manifest-safe route definitions
- you want controller handlers, middleware, and route compilation to stay explicit

Use the kernel directly when:

- you are maintaining the config-driven routing path
- you are working inside low-level bootstrap or dispatch code
- you need the kernel `routing::check_route(...)` behavior exactly as-is

As a rule of thumb:

- prefer `Dataphyre\Routing\...` in route files and route tooling
- prefer `\dataphyre\routing\...` in kernel dispatch or compatibility code

## Route Lifecycle

The normal framework-first route lifecycle is:

1. load the routing framework module
2. create `Route` objects in the application's routes file
3. use `ControllerAction` when the handler needs class + method metadata
4. compile the route file into a manifest
5. let the compiled dispatcher match and execute the manifest at runtime

A minimal routes file looks like this:

```php
<?php

use Dataphyre\Routing\ControllerAction;
use Dataphyre\Routing\Route;

return [
	Route::get('/orders/{order_id}', ControllerAction::static(
		'app\\Http\\OrderController',
		'show',
	)),
	Route::any('/legacy/{...segments}', __DIR__.'/views/legacy.php'),
];
```

## Route Matching Rules

Compiled route matching follows a few clear rules:

- methods are normalized to uppercase
- paths are normalized to a leading slash and without a trailing slash, except `/`
- exact static paths compile to `exact_path`
- parameterized paths compile to `path_regex`
- `{id}` captures one path segment as a named parameter
- `{...segments}` captures the remainder of the path and is exposed as an array of segments

Examples:

```php
Route::get('/orders', __DIR__.'/views/orders.php');
Route::get('/orders/{order_id}', __DIR__.'/views/order.php');
Route::any('/files/{...segments}', __DIR__.'/views/file_browser.php');
```

At dispatch time:

- `/orders` matches the first route exactly
- `/orders/42` publishes `['order_id'=>'42']`
- `/files/a/b/c` publishes `['segments'=>['a', 'b', 'c']]`

Matched parameters are published into `\dataphyre\routing::$bindings`. Controller-backed and middleware-backed routes also receive a captured `Request` object with those path parameters.

## Handler Model

Routing supports several handler shapes:

- include-file paths as strings
- manifest-safe controller handlers through `ControllerAction`
- compiled handler arrays
- API-generated compiled route handlers

The safest application-facing handlers for compiled manifests are:

- file paths
- `ControllerAction`
- compiled arrays produced by other Dataphyre modules

Example include-file route:

```php
Route::any('/legacy/{...segments}', __DIR__.'/legacy/api.php');
```

Example controller-backed route:

```php
Route::get('/orders/{order_id}', ControllerAction::static(
	'app\\Http\\OrderController',
	'show',
	['bootstrap'=>__DIR__.'/bootstrap.php']
));
```

`ControllerAction::static(...)` and `ControllerAction::instance(...)` both compile into controller metadata. The dispatcher loads the handler bootstrap first when one is provided, then invokes the target method with:

- `Request $request`
- `array $route`

## Middleware Model

`Route::middleware(...)` accepts string or array definitions and normalizes them into manifest-safe middleware entries.

Supported shapes include:

- alias strings like `'auth'`
- alias strings with parameters like `'auth:admin'`
- class strings like `'App\\Http\\Middleware\\EnsureTenant'`
- arrays with `class`, `alias`, `parameters`, `module` or `modules`, and `bootstrap`

Example:

```php
Route::get('/dashboard', ControllerAction::static(
	'app\\Http\\DashboardController',
	'show'
))->middleware(
	'auth',
	[
		'class'=>'app\\Http\\Middleware\\EnsureTenant',
		'parameters'=>['strict'],
		'bootstrap'=>__DIR__.'/bootstrap.php',
	]
);
```

Built-in aliases are:

- `auth`
- `guest`

At dispatch time, middleware is resolved into a pipeline of objects exposing a `handle(...)` method. For Dataphyre namespaced middleware and controllers, the dispatcher also infers framework modules from the class namespace where possible. You can also pass explicit `modules` when you need a different or additional module load.

## Compiled Manifest Shape

`RouteManifest::compile(...)` returns a manifest with this high-level shape:

```php
[
	'version'=>1,
	'metadata'=>[
		'application'=>'shopiro',
	],
	'routes'=>[
		[
			'methods'=>['GET'],
			'exact_path'=>'/orders',
			'handler'=>__DIR__.'/views/orders.php',
		],
	],
]
```

Each compiled route includes:

- `methods`
- `handler`
- `middleware` when present
- `exact_path` for static routes
- `path_regex` for parameterized routes
- `splat_parameters` when a splat segment is present

## Cross-Module Patterns

Routing is usually the entry surface for other framework modules.

### Routing + API

The API module compiles endpoints into routing manifests. That means the routing dispatcher can authorize and execute API-aware compiled routes without a separate router.

```php
\dataphyre\core::load_framework_modules(['routing', 'api']);
```

### Routing + HTTP

Controller-backed and middleware-backed routes use the HTTP framework request/response layer.

```php
\dataphyre\core::load_framework_modules(['routing', 'http']);
```

### Routing + Access

The built-in `auth` and `guest` middleware aliases resolve through the access module.

```php
\dataphyre\core::load_framework_modules(['routing', 'access']);
```

## Common Workflows

### Define A Controller-Backed Route File

```php
<?php

use Dataphyre\Routing\ControllerAction;
use Dataphyre\Routing\Route;

return [
	Route::get('/orders/{order_id}', ControllerAction::static(
		'app\\Http\\OrderController',
		'show',
		['bootstrap'=>__DIR__.'/bootstrap.php']
	))->middleware('auth'),
];
```

### Compile A Routes File Into A Manifest

```php
\dataphyre\core::load_framework_module('routing');

use Dataphyre\Routing\RouteCompiler;

$manifest=RouteCompiler::compile_file(__DIR__.'/routes.php', [
	'application'=>'shopiro',
	'compiled_at'=>gmdate('c'),
]);

RouteCompiler::write_manifest_file(__DIR__.'/compiled_routes.php', $manifest);
```

### Compile An Application By Name

```php
\dataphyre\core::load_framework_modules(['core', 'routing']);

use Dataphyre\Routing\Tools\CompileApplicationRoutes;

$manifest_file=CompileApplicationRoutes::compile(
	'C:/Projects/ExampleDataphyreApp',
	'example_app'
);
```

### Add Middleware With Parameters

```php
Route::post('/admin/reports', ControllerAction::static(
	'app\\Http\\ReportController',
	'store'
))->middleware(
	'auth:admin',
	[
		'class'=>'app\\Http\\Middleware\\EnsureTenant',
		'parameters'=>['required'],
	]
);
```

### Use A Splat Route For Compatibility Paths

```php
Route::any('/mobapi/{...segments}', __DIR__.'/compat/mobapi.php');
```

### Build A Custom Compilable Route

```php
use Dataphyre\Routing\CompilableRoute;

final class HealthRoute implements CompilableRoute {

	public function compile(): array {
		return [
			'methods'=>['GET'],
			'exact_path'=>'/_health',
			'handler'=>__DIR__.'/views/health.php',
		];
	}
}
```

## Common Pitfalls

- Do not rely on raw closures in compiled route files. Manifest writing uses `var_export(...)`, so manifest-safe handlers are the reliable default.
- Do not use `Route::any(...)` when a narrower method list expresses intent more clearly.
- Do not treat `/{...segments}` like a single string parameter. The dispatcher publishes splat parameters as arrays.
- Do not assume middleware aliases are open-ended. The dispatcher only provides built-in aliases unless you use explicit middleware classes.
- Do not forget that controller methods receive `Request` and route metadata, while plain callable and include-file handlers follow different execution paths.

## Troubleshooting

### A compiled route never matches

Check:

- the request method matches the compiled `methods`
- the path normalizes to the route path you expect
- the route compiled to `exact_path` or `path_regex` the way you expect

Use:

```php
$manifest=RouteCompiler::compile_file(__DIR__.'/routes.php');
$routes=$manifest['routes'];
```

### A splat route returns an empty array

That means the route matched, but there were no remaining path segments after the splat point.

Example:

- route: `/files/{...segments}`
- request: `/files`
- published params: `['segments'=>[]]`

### Middleware alias resolution fails

Check:

- the alias is one of the built-in aliases
- class-based middleware is available and has a `handle(...)` method
- any required framework modules or bootstrap files are available

If you need custom behavior, prefer an explicit middleware class definition instead of assuming alias registration exists.

### A controller route fails at dispatch time

Check:

- the class and method names are correct
- the optional bootstrap file exists
- the controller method signature accepts `Request $request, array $route`

### `CompileApplicationRoutes::compile(...)` says the app has no framework routes file

That means the application definition does not expose a valid `routes_file`, or the file does not exist.

Check:

- the application's `app.php`
- the conventional application definition paths
- the resolved application directory

## `Dataphyre\Routing\Route`

`Route` is the main route builder for framework route files.

Static builders include:

- `methods(...)`
- `get(...)`
- `post(...)`
- `put(...)`
- `patch(...)`
- `delete(...)`
- `any(...)`

Instance methods include:

- `middleware(...)`
- `compile()`

Example:

```php
$route=Route::get('/orders/{order_id}', ControllerAction::static(
	'app\\Http\\OrderController',
	'show'
))->middleware('auth');

$compiled=$route->compile();
```

## `Dataphyre\Routing\ControllerAction`

`ControllerAction` is the manifest-safe controller handler descriptor used by `Route`.

Static builders include:

- `static(...)`
- `instance(...)`

Instance methods include:

- `compile()`

Example:

```php
$action=ControllerAction::instance(
	'app\\Http\\OrderController',
	'show',
	['bootstrap'=>__DIR__.'/bootstrap.php']
);

$compiled=$action->compile();
```

## `Dataphyre\Routing\RouteCompiler`

`RouteCompiler` compiles routes files and writes manifest files.

Methods include:

- `compile_file(...)`
- `write_manifest_file(...)`

Example:

```php
$manifest=RouteCompiler::compile_file(__DIR__.'/routes.php', [
	'application'=>'volumetrix',
]);

RouteCompiler::write_manifest_file(__DIR__.'/compiled_routes.php', $manifest);
```

## `Dataphyre\Routing\RouteManifest`

`RouteManifest` compiles arrays of route objects or precompiled route arrays into one manifest payload.

Methods include:

- `compile(...)`

Example:

```php
$manifest=RouteManifest::compile([
	Route::get('/ping', __DIR__.'/views/ping.php'),
], [
	'application'=>'shopiro',
]);
```

## `Dataphyre\Routing\CompilableRoute`

`CompilableRoute` is the interface for custom route builders that emit compiled route arrays.

Methods include:

- `compile()`

Example:

```php
final class DocsRoute implements CompilableRoute {

	public function compile(): array {
		return [
			'methods'=>['GET'],
			'exact_path'=>'/docs',
			'handler'=>__DIR__.'/views/docs.php',
		];
	}
}
```

## `Dataphyre\Routing\Tools\CompileApplicationRoutes`

`CompileApplicationRoutes` compiles the configured framework routes file for a named application and writes the compiled manifest to that application's configured compiled routes file.

Methods include:

- `compile(...)`

Example:

```php
$target=CompileApplicationRoutes::compile(
	'C:/Projects/ExampleDataphyreApp',
	'example_app'
);
```

## Design Notes

The routing framework layer stays intentionally focused:

- route files stay explicit and compilable
- dispatch-time work stays in the kernel dispatcher
- controller metadata stays serializable
- middleware stays normalized before runtime
- API endpoints can share the same compiled routing path

That keeps routing aligned with Dataphyre's general model: explicit definitions up front, compiled artifacts in the middle, and predictable runtime behavior at the boundary.
