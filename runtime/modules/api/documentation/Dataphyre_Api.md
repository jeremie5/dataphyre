### Dataphyre API

Dataphyre API is a framework module for declaring discoverable HTTP endpoints, generating OpenAPI documents, serving Swagger UI, enforcing endpoint auth, validating request input, and executing endpoint logic through compiled Dataphyre routes.

### Mental Model

Dataphyre API is built from a small set of constructs with clear responsibilities:

- `Api`: the top-level facade for declaring endpoints, groups, profiles, discovery, OpenAPI generation, internal dispatch, and cache clearing.
- `Endpoint`: a compiled route definition with API metadata, request validation, auth, bindings, lifecycle hooks, trace, cache, and execution targets.
- `ApiGroup`: a reusable set of defaults for prefixes, auth, tags, hooks, trace, and internal-dispatch behavior.
- `ApiContext`: the runtime execution context passed to endpoint targets. It exposes request input, validated input, auth state, resolved bindings, and internal dispatch helpers.
- `SecurityScheme`: the bridge between runtime auth behavior and the public OpenAPI security contract.
- `ApiManager`: the low-level runtime and discovery engine behind the facade. Use it when you need direct control over docs, discovery, dispatch, or cache clearing.

The recommended mental model is:

1. Define endpoints with `Api` or `ApiGroup`.
2. Validate request input with `schema(...)`.
3. Resolve reusable execution inputs with bindings.
4. Run endpoint logic through `execute(...)`.
5. Add `withTrace()` and `cache(...)` when the endpoint benefits from observability or replay.
6. Publish the contract through `Api::documentationRoutes(...)` or `Api::openApiDocument(...)`.

### Start Here

Load the framework module:

```php
\dataphyre\core::load_framework_module('api');
```

This loads the API framework bootstrap and prepares the routing and HTTP framework dependencies used by the module.

Declare endpoints directly in an application's `routes.php` file:

```php
<?php

use Dataphyre\Api\Api;

return [
	Api::get('/api/ping')
		->summary('Ping the API')
		->tag('System')
		->execute('app\\framework\\Api\\SystemEndpoints::ping')
		->jsonResponse(200, [
			'type'=>'object',
			'properties'=>[
				'ok'=>['type'=>'boolean'],
				'timestamp'=>['type'=>'string'],
			],
			'required'=>['ok', 'timestamp'],
		]),
];
```

Recommended endpoint shape:

```php
use Dataphyre\Api\Api;
use Dataphyre\Api\ApiContext;
use Dataphyre\Api\SecurityScheme;
use app\framework\Repositories\OrderRepository;

Api::get('/api/orders/{order_id}')
	->summary('Show one order')
	->operationId('orders.show')
	->tag('Orders')
	->pathParameter('order_id', ['type'=>'string'])
	->schema([
		'order_id'=>'required|slug',
		'include_lines'=>'boolean',
	], [], [
		'sources'=>['route', 'query'],
	])
	->auth(SecurityScheme::jwtGuard())
	->withQueryIdentity(
		'order',
		OrderRepository::query()
			->asStoredMoney('priced_total', [
				'amount_column'=>'priced_total_amount_minor',
				'currency_column'=>'priced_total_currency',
				'base_amount_column'=>'base_total_amount_minor',
				'base_currency_column'=>'base_total_currency',
				'rate_column'=>'exchange_rate',
				'rate_source_column'=>'exchange_source',
				'rate_timestamp_column'=>'exchange_timestamp',
			]),
		'first_record'
	)
	->cache(120, [
		'names'=>['orders.show'],
	])
	->withTrace(true, [
		'include_bindings'=>true,
		'include_sql'=>true,
	])
	->execute('app\\framework\\Api\\OrderEndpoints::show')
	->jsonResponse(200, [
		'type'=>'object',
		'properties'=>[
			'ok'=>['type'=>'boolean'],
			'order'=>['type'=>'object'],
		],
		'required'=>['ok', 'order'],
	]);
```

Create shared groups or named profiles when multiple endpoints use the same prefix, auth, hooks, trace, or batch defaults:

```php
use Dataphyre\Api\Api;
use Dataphyre\Api\SecurityScheme;

$dev=Api::profile('example.dev.v1', [
	'prefix'=>'/api/dev/v1',
	'tags'=>['Developer API'],
	'trace'=>[
		'enabled'=>true,
		'include_auth'=>true,
		'include_bindings'=>true,
	],
	'dispatch'=>[
		'limit'=>128,
		'continue_on_error'=>true,
	],
])
	->auth(SecurityScheme::apiKey('devKey', 'X-Developer-Key', 'header', [
		'resolver'=>'app\\framework\\Api\\DeveloperAuth::authorize',
	]))
	->beforeExecute('app\\framework\\Api\\DeveloperApiHooks::begin')
	->afterExecute('app\\framework\\Api\\DeveloperApiHooks::log')
	->onError('app\\framework\\Api\\DeveloperApiHooks::fail');

return [
	$dev->get('/orders')
		->aliases('get/orders/list', 'dev/orders/list')
		->summary('List developer orders')
		->execute('app\\framework\\Api\\DeveloperOrderEndpoints::index'),
];
```

Choose the simplest construct that matches the endpoint:

- Use a plain `Api::get(...)` or `Api::post(...)` endpoint when the route stands alone.
- Use `Api::group(...)` when multiple endpoints share prefix, tags, trace, or lifecycle hooks but do not need a named profile.
- Use `Api::profile(...)` when compatibility wrappers, aliases, or internal dispatch need a stable profile name like `example.mobile` or `example.dev.v1`.
- Use `execute(...)` for the main runtime path. Keep controller-backed handlers for adoption or interop.
- Use `withBinding(...)` for request-shaped runtime logic and `withQueryIdentity(...)` / `withSearchIdentity(...)` for static query snapshots that should participate in identity, cache, and trace.

### Endpoint Builder

`Dataphyre\Api\Endpoint` compiles into a normal Dataphyre route manifest entry with API metadata.

Alias-aware endpoint:

```php
Api::get('/api/orders/{order_id}')
	->alias('get/orders/show')
	->aliases('dev/orders/show', 'mobile/orders/show')
	->execute('app\\framework\\Api\\OrderEndpoints::show');
```

Verb builders:

```php
Api::methods(['GET', 'HEAD'], '/api/export')
	->summary('Export machine data')
	->execute('app\\framework\\Api\\ExportEndpoints::show');

Api::put('/api/machines/{machine_id}')
	->execute('app\\framework\\Api\\MachineEndpoints::replace');

Api::patch('/api/machines/{machine_id}')
	->execute('app\\framework\\Api\\MachineEndpoints::update');

Api::delete('/api/machines/{machine_id}')
	->execute('app\\framework\\Api\\MachineEndpoints::delete');

Api::any('/api/hooks/testing')
	->execute('app\\framework\\Api\\HookEndpoints::handle');
```

Extended endpoint definition:

```php
Api::patch('/api/machines/{machine_id}')
	->middleware('auth', 'audit')
	->summary('Update one machine')
	->deprecated()
	->headerParameter('X-Tenant', ['type'=>'string'], [
		'description'=>'Tenant selector',
	])
	->cookieParameter('currency', ['type'=>'string'])
	->server('https://api.example.com', 'Production API')
	->profile('example.dev.v1')
	->dispatchDefaults([
		'limit'=>32,
	])
	->execute('app\\framework\\Api\\MachineEndpoints::update');
```

### Groups And Profiles

`Api::group(...)` creates an unnamed endpoint group. `Api::profile(...)` creates a named group whose profile metadata is compiled into the route manifest.

Unnamed group:

```php
$mobile=Api::group([
	'prefix'=>'/api/mobile/v1',
	'tags'=>['Mobile API'],
	'dispatch'=>[
		'limit'=>128,
	],
])->withTrace();

$endpoint=$mobile->post('/session/start')
	->execute('app\\framework\\Api\\MobileSessionEndpoints::start');
```

Named profile:

```php
$dev=Api::profile('example.dev.v1', [
	'prefix'=>'/api/dev/v1',
	'tags'=>['Developer API'],
	'dispatch'=>[
		'limit'=>128,
		'limit_error'=>'too_many_chainlinks',
	],
]);
```

Common group/profile methods:

```php
$group=Api::group()
	->prefix('/api/dev/v1')
	->middleware('auth')
	->tag('Developer API')
	->auth(SecurityScheme::jwtGuard())
	->authAll(SecurityScheme::apiKey('tenantKey', 'X-Tenant-Key'))
	->withTrace(true, ['include_auth'=>true])
	->beforeExecute('app\\framework\\Api\\Hooks::begin')
	->afterExecute('app\\framework\\Api\\Hooks::log')
	->onError('app\\framework\\Api\\Hooks::fail')
	->dispatchDefaults([
		'limit'=>128,
		'continue_on_error'=>true,
	]);
```

Apply a group to a pre-built endpoint:

```php
$base=Api::get('/status')
	->summary('Show API status')
	->execute('app\\framework\\Api\\SystemEndpoints::status');

$ops=Api::group([
	'prefix'=>'/api/ops',
	'tags'=>['Operations'],
]);

$route=$ops->apply($base);
```

The group's prefix is applied to each generated endpoint path. Named profiles add `profile` metadata to compiled API routes, and `dispatchDefaults(...)` becomes the default option set for `ApiContext::dispatch(...)`, `dispatchBatch(...)`, and `dispatchChain(...)`.

Group verb helpers mirror the top-level `Api` facade:

```php
$ops=Api::group(['prefix'=>'/api/ops']);

$ops->methods(['GET', 'HEAD'], '/health')
	->execute('app\\framework\\Api\\HealthEndpoints::show');

$ops->put('/machines/{machine_id}')
	->execute('app\\framework\\Api\\MachineEndpoints::replace');

$ops->patch('/machines/{machine_id}')
	->execute('app\\framework\\Api\\MachineEndpoints::update');

$ops->delete('/machines/{machine_id}')
	->execute('app\\framework\\Api\\MachineEndpoints::delete');

$ops->any('/hooks/testing')
	->execute('app\\framework\\Api\\HookEndpoints::handle');
```

Compatibility wrapper pattern:

```php
$mobile=Api::profile('example.mobile', [
	'prefix'=>'/api/mobile/v1',
	'tags'=>['Mobile API'],
	'dispatch'=>[
		'limit'=>128,
		'limit_error'=>'too_many_chainlinks',
	],
]);

$routes[]=$mobile->post('/chained')
	->execute('app\\framework\\Api\\MobileCompatEndpoints::chain');
```

```php
final class MobileCompatEndpoints {

	public static function chain(ApiContext $context): array {
		$chain=$context->body('chain', []);
		$requests=array_map(
			static fn(array $entry): array => [
				'alias'=>$entry['endpoint'] ?? $entry['path'] ?? null,
				'get'=>$entry['get'] ?? [],
				'post'=>$entry['post'] ?? [],
				'profile'=>'example.mobile',
			],
			is_array($chain) ? $chain : []
		);

		return $context->dispatchChain($requests);
	}
}
```

Execution-first endpoint:

```php
use Dataphyre\Api\Api;
use Dataphyre\Api\SecurityScheme;

$endpoint=Api::get('/api/machines/{machine_id}')
	->summary('Show one machine')
	->description('Returns one machine record.')
	->operationId('machines.show')
	->tag('Machines')
	->pathParameter('machine_id', ['type'=>'string'], [
		'description'=>'Machine identifier',
	])
	->queryParameter('include_metrics', ['type'=>'boolean'], [
		'description'=>'Include live metric payloads',
	])
	->schema([
		'machine_id'=>'required|slug',
		'include_metrics'=>'boolean',
	], [], [
		'sources'=>['route', 'query'],
		'message'=>'Machine request validation failed.',
	])
	->auth(SecurityScheme::jwtGuard())
	->cache(60, [
		'names'=>['machines.show'],
	])
	->withTrace()
	->execute('app\\framework\\Api\\MachineEndpoints::show')
	->jsonResponse(200, [
		'type'=>'object',
		'properties'=>[
			'ok'=>['type'=>'boolean'],
			'machine'=>['type'=>'object'],
		],
		'required'=>['ok', 'machine'],
	])
	->response(404, [
		'description'=>'Machine not found',
	]);
```

Controller-backed endpoint:

```php
use Dataphyre\Api\Api;
use Dataphyre\Routing\ControllerAction;

Api::post('/api/machines', ControllerAction::static('app\\framework\\Http\\MachineController', 'store'))
	->summary('Create one machine')
	->tag('Machines')
	->jsonBody([
		'type'=>'object',
		'properties'=>[
			'name'=>['type'=>'string'],
			'status'=>['type'=>'string'],
		],
		'required'=>['name'],
	], true)
	->jsonResponse(201, [
		'type'=>'object',
		'properties'=>[
			'ok'=>['type'=>'boolean'],
			'id'=>['type'=>'string'],
		],
		'required'=>['ok', 'id'],
	]);
```

### Execution Targets

`execute(...)` is the recommended runtime surface for compiled API endpoints. Use callable references that survive route compilation.

Static method string:

```php
Api::get('/api/machines')->execute('app\\framework\\Api\\MachineEndpoints::index');
```

Static class-method array:

```php
Api::get('/api/machines')->execute(['app\\framework\\Api\\MachineEndpoints', 'index']);
```

Instance method definition:

```php
Api::post('/api/machines')->execute([
	'class'=>'app\\framework\\Api\\MachineEndpoints',
	'method'=>'store',
	'static'=>false,
]);
```

Execution target with bootstrap:

```php
Api::get('/api/runtime')->execute([
	'class'=>'app\\framework\\Api\\RuntimeEndpoints',
	'method'=>'show',
	'bootstrap'=>__DIR__.'/framework_bootstrap.php',
]);
```

### ApiContext

Execution targets receive `Dataphyre\Api\ApiContext` as the first argument. The context exposes the captured request, route metadata, merged input, and validated data.

```php
<?php

namespace app\framework\Api;

use Dataphyre\Api\ApiContext;

final class MachineEndpoints {

	public static function show(ApiContext $context): array {
		$machine_id=$context->validated('machine_id');
		$include_metrics=$context->validated('include_metrics', false);

		return [
			'ok'=>true,
			'machine'=>[
				'id'=>$machine_id,
				'include_metrics'=>$include_metrics,
				'request_path'=>$context->path(),
			],
		];
	}
}
```

Common `ApiContext` methods:

```php
$request=$context->request();
$route=$context->route();

$method=$context->method();
$path=$context->path();

$route_parameters=$context->parameters();
$machine_id=$context->parameters('machine_id');

$query=$context->query();
$page=$context->query('page', 1);

$body=$context->body();
$status=$context->body('status');

$merged=$context->all();
$tenant_id=$context->input('tenant_id');
$header=$context->header('x-tenant-key');
$cookie=$context->cookie('session');
$server_name=$context->server('SERVER_NAME');

$validated_input=$context->validated();
$has_validated_input=$context->hasValidatedInput();
$validation_result=$context->validation();
```

Auth-aware context methods:

```php
$auth=$context->auth();
$has_auth=$context->hasAuth();
$scheme=$context->authScheme();
$identity=$context->authIdentity();
$scopes=$context->authScopes();
$application=$context->authContext('application');
$rate_limit=$context->authMeta('rate_limit');
```

Security resolvers can attach authenticated principal data, application metadata, rate-limit state, client capabilities, and other compatibility context through `identity`, `context`, and `meta`. Execution targets and lifecycle hooks can read that data directly from `ApiContext`.

Context-side validation:

```php
$result=$context->validate([
	'tenant_id'=>'required|numeric',
	'search'=>'nullable|string',
], [], [
	'sources'=>['query', 'body'],
]);

if($result->failed()){
	return \Dataphyre\Http\Response::json([
		'ok'=>false,
		'errors'=>$result->errors(),
	], 422);
}

$validated=$context->validated();
```

Internal API dispatch from an execution target:

```php
$profile=$context->dispatch([
	'alias'=>'get/profile/show',
	'profile'=>'example.mobile',
	'query'=>[
		'include'=>'stats',
	],
]);

$profile_ok=$profile['ok'];
$profile_status=$profile['status'];
$profile_payload=$profile['json'];
```

Batch or chained dispatch from an execution target:

```php
$batch=$context->dispatchBatch([
	[
		'key'=>'get/profile/show',
		'profile'=>'example.mobile',
	],
	[
		'key'=>'post/orders/search',
		'profile'=>'example.mobile',
		'body'=>[
			'status'=>'open',
		],
	],
]);

$chain=$context->dispatchChain([
	'get/profile/show'=>[
		'profile'=>'example.mobile',
	],
	'post/orders/search'=>[
		'profile'=>'example.mobile',
		'post'=>[
			'status'=>'open',
		],
	],
]);
```

`dispatch(...)`, `dispatchBatch(...)`, and `dispatchChain(...)` inherit the current request headers, cookies, server state, and trusted auth context by default when they are called from `ApiContext`. Batch request items accept `query` or `get`, `body` or `post`, plus optional `headers`, `cookies`, `server`, and `attributes`.

Alias-driven dispatch can target legacy endpoint keys directly:

```php
$result=$context->dispatch([
	'alias'=>'get/orders/show',
	'profile'=>'example.dev.v1',
	'query'=>[
		'order_id'=>$context->query('order_id'),
	],
]);
```

Parameterised alias dispatch can provide route values explicitly too:

```php
$result=$context->dispatch([
	'alias'=>'get/orders/show',
	'route'=>[
		'order_id'=>'ord_123',
	],
]);
```

When a batch or chain item key is a non-path string and no `path`, `uri`, `alias`, or `endpoint` is supplied, Dataphyre treats that key as an alias candidate automatically. Use `profile` when the same legacy alias exists in more than one API profile.

Batch response shape:

```php
[
	'ok'=>true,
	'count'=>2,
	'failures'=>0,
	'duration_ms'=>4.21,
	'responses'=>[
		[
			'key'=>'get/profile/show',
			'ok'=>true,
			'status'=>200,
			'json'=>['ok'=>true],
		],
	],
]
```

Internal dispatch targets compiled Dataphyre API routes by path or alias and returns normalized child response records. This pass supports execution-first endpoints and controller-backed handlers. Route middleware is not executed by internal dispatch.

### Endpoint Bindings

API endpoints can declare binding-backed execution inputs before `execute(...)` runs. Bindings resolve once per request, become available through `ApiContext`, and participate in trace output when binding traces are enabled.

Callable binding:

```php
Api::get('/api/dashboard')
	->schema([
		'tenant_id'=>'required|numeric',
	], [], [
		'sources'=>['query'],
	])
	->withBinding('summary', 'app\\framework\\Api\\DashboardBindings::summary')
	->execute('app\\framework\\Api\\DashboardEndpoints::show');
```

```php
<?php

namespace app\framework\Api;

use Dataphyre\Api\ApiContext;

final class DashboardBindings {

	public static function summary(ApiContext $context): array {
		return [
			'tenant_id'=>$context->validated('tenant_id'),
			'generated_at'=>date('c'),
		];
	}
}
```

SQL query binding:

```php
use Dataphyre\Api\Api;
use app\framework\Repositories\OrderRepository;

Api::get('/api/orders/latest')
	->withQueryIdentity(
		'orders',
		OrderRepository::query()
			->latest('created_at')
			->asMoney('total_amount_minor', 'currency', 'total'),
		'records',
		[
			'columns'=>['order_id', 'total_amount_minor', 'currency', 'created_at'],
		]
	)
	->execute('app\\framework\\Api\\OrderEndpoints::latest');
```

Search query binding:

```php
use Dataphyre\Api\Api;
use Dataphyre\FulltextEngine\Search;

Api::get('/api/search/help')
	->withSearchIdentity(
		'articles',
		Search::query('help_articles')
			->where('published', '1')
			->limit(10),
		'results'
	)
	->execute('app\\framework\\Api\\HelpEndpoints::search');
```

Access bindings inside the execution target:

```php
public static function latest(ApiContext $context): array {
	return [
		'ok'=>true,
		'orders'=>$context->binding('orders', []),
		'summary'=>$context->binding('summary'),
	];
}
```

Common binding methods on `ApiContext`:

```php
$bindings=$context->bindings();
$orders=$context->binding('orders', []);
$first_order_total=$context->binding('orders.0.total');
$has_summary=$context->hasBinding('summary');
$binding_trace=$context->bindingTrace();
$binding_data=$context->bindingData();
```

Binding methods on `Endpoint`:

```php
Api::get('/api/example')
	->alias('get/example/show')
	->aliases('dev/example/show', 'mobile/example/show')
	->withBinding('summary', 'app\\framework\\Api\\ExampleBindings::summary')
	->withBindings([
		'meta'=>'app\\framework\\Api\\ExampleBindings::meta',
	])
	->withQuery('rows', ExampleRepository::query(), 'rows')
	->withQueryIdentity('records', ExampleRepository::query()->latest(), 'records')
	->withSearch('results', Search::query('example'), 'results')
	->withSearchIdentity('hits', Search::query('example')->limit(5), 'hits');
```

`withQuery(...)` and `withSearch(...)` capture a compiled execution-state snapshot of the query object. Use them for bindings that can be declared statically in the route definition. Use `withBinding(...)` when the bound result depends on request-specific execution logic.

Facade-level internal dispatch is available too:

```php
$response=Dataphyre\Api\Api::dispatch([
	'alias'=>'get/system/ping',
]);

$batch=Dataphyre\Api\Api::dispatchBatch([
	'get/system/ping'=>[],
]);

$chain=Dataphyre\Api\Api::dispatchChain([
	'get/system/ping'=>[],
]);
```

Advanced callable binding class:

```php
use Dataphyre\Api\ApiCallableBinding;

$binding=new ApiCallableBinding(
	'app\\framework\\Api\\DashboardBindings::summary',
	'api.binding.summary',
	'app\\framework\\Api\\DashboardBindings::summary',
	static fn($binding_context): array => [
		'tenant_id'=>$binding_context->get('query.tenant_id'),
	]
);

$binding_name=$binding->name();
$binding_metadata=$binding->metadata();
```

### Lifecycle Hooks

Endpoints can attach compiled lifecycle hooks around execution. These hooks are useful for request logging, compatibility envelopes, analytics, and application-specific audit trails.

Before, after, and error hooks:

```php
Api::get('/api/dev/orders')
	->auth(SecurityScheme::apiKey('devKey', 'X-Developer-Key', 'header', [
		'resolver'=>'app\\framework\\Api\\DeveloperAuth::authorize',
	]))
	->beforeExecute('app\\framework\\Api\\DeveloperApiHooks::begin')
	->afterExecute('app\\framework\\Api\\DeveloperApiHooks::log')
	->onError('app\\framework\\Api\\DeveloperApiHooks::fail')
	->execute('app\\framework\\Api\\DeveloperOrderEndpoints::index');
```

Hook signatures stay flexible and arity-based:

```php
public static function begin(ApiContext $context): ?\Dataphyre\Http\Response
public static function log(
	ApiContext $context,
	mixed $result,
	\Dataphyre\Http\Response $response,
	?array $trace=null
): ?\Dataphyre\Http\Response
public static function fail(ApiContext $context, \Throwable $exception): ?\Dataphyre\Http\Response
```

Returning `null` continues the request flow. Returning a `Dataphyre\Http\Response` short-circuits or replaces the response for that phase.

Hook argument order:

- `beforeExecute(...)`: `ApiContext`, `Request`, route array
- `afterExecute(...)`: `ApiContext`, execution result, `Response`, trace payload, `Request`, route array
- `onError(...)`: `ApiContext`, thrown exception, `Request`, route array

### Request Validation

`schema(...)` applies runtime sanitation before the execution target runs. By default the input set is merged from query, body, and route parameters, with route parameters taking precedence.

```php
Api::post('/api/machines/{machine_id}')
	->schema([
		'machine_id'=>'required|slug',
		'name'=>'required|string|min:2|max:120',
		'status'=>'required|in:active,pending,disabled',
		'notes'=>'nullable|basic_html',
	], [], [
		'sources'=>['route', 'body'],
		'message'=>'Machine payload validation failed.',
		'labels'=>[
			'machine_id'=>'machine id',
		],
	]);
```

Validation failures return JSON:

```json
{
  "ok": false,
  "error": "Machine payload validation failed.",
  "errors": {
    "name": "The name field is required."
  }
}
```

The default validation status is `422`. Override it with `status` in the schema options when needed.

### Trace-Aware Endpoints

`withTrace()` assigns an API trace id to the request, wraps SQL execution in trace context when the SQL framework is available, and can add trace data to JSON responses.

When `IS_PRODUCTION === true`, Dataphyre disables API trace capture and response trace injection regardless of endpoint-level `withTrace(...)` configuration.

Basic trace:

```php
Api::get('/api/dashboard')
	->withTrace(true, [
		'include_auth'=>true,
		'include_bindings'=>true,
	])
	->execute('app\\framework\\Api\\DashboardEndpoints::show');
```

Custom trace options:

```php
Api::get('/api/dashboard')
	->withTrace(true, [
		'include_auth'=>true,
		'include_bindings'=>true,
		'include_sql'=>true,
		'sql_limit'=>25,
		'response_key'=>'debug_trace',
		'header'=>'X-Debug-Trace',
	])
	->execute('app\\framework\\Api\\DashboardEndpoints::show');
```

Trace-enabled array responses include the trace block directly:

```php
return [
	'ok'=>true,
	'metrics'=>$metrics,
];
```

Result:

```json
{
  "ok": true,
  "metrics": {},
  "trace": {
    "api_trace_id": "...",
    "endpoint": {
      "path": "/api/dashboard",
      "method": "GET"
    },
    "duration_ms": 3.14,
    "auth": {
      "scheme": "jwtAuth",
      "identity_type": "array"
    },
    "bindings": [
      {
        "path": "orders",
        "binding": "sql.query.records",
        "driver": "sql",
        "query_mode": "records",
        "cache_state": "miss",
        "trace": {
          "correlation": {
            "api_trace_id": "...",
            "binding_trace_id": "....b0001"
          }
        }
      }
    ],
    "sql": []
  }
}
```

Generated JSON responses also include the configured trace header.

When an execution target returns a `Dataphyre\Http\Response`, Dataphyre preserves the response body and adds the trace id header instead of reshaping the payload.

### Endpoint Caching

API endpoints can cache their final normalized response:

```php
Api::get('/api/dashboard')
	->withQueryIdentity(
		'orders',
		OrderRepository::query()
			->latest('created_at')
			->cacheNames('orders.dashboard'),
		'records'
	)
	->cache(60, [
		'names'=>['dashboard'],
		'vary_headers'=>['Accept-Language'],
	])
	->withTrace(true, [
		'include_bindings'=>true,
		'include_sql'=>true,
	])
	->execute('app\\framework\\Api\\DashboardEndpoints::show');
```

`cache(...)` stores the final response body, status, and headers in Dataphyre's API cache and replays that response on a cache hit. The cache identity combines endpoint metadata, request input, authenticated context, and binding identities when bindings expose them.

Common cache options:

```php
->cache(300, [
	'names'=>['dashboard', 'tenant.dashboard'],
	'vary_headers'=>['Accept-Language', 'X-Tenant'],
	'vary_cookies'=>['currency'],
	'store_errors'=>false,
	'allow_untracked_bindings'=>false,
	'inherit_binding_cache_names'=>true,
])
```

Trace-enabled cached responses include a cache block:

```json
{
  "trace": {
    "cache": {
      "enabled": true,
      "cacheable": true,
      "state": "hit",
      "layer": "persistent",
      "names": ["dashboard", "orders.dashboard"]
    }
  }
}
```

Endpoint cache hits bypass binding resolution, lifecycle hooks, and execution. This keeps the cache path cheap and predictable. When a binding does not expose cache identity, endpoint caching bypasses by default instead of storing an unsafe response. Set `allow_untracked_bindings` to `true` only when that tradeoff is intentional.

Clear endpoint cache entries explicitly:

```php
Dataphyre\Api\Api::clearEndpointCache('dashboard', 'orders.dashboard');
```

When SQL invalidates named caches and Dataphyre API is loaded, matching endpoint cache names are cleared automatically.

### Security Schemes

Security schemes describe both runtime auth behavior and the OpenAPI security scheme.

JWT guard:

```php
SecurityScheme::jwtGuard();
```

Guard with custom OpenAPI description:

```php
SecurityScheme::guard('sessionAuth', 'session', [
	'type'=>'apiKey',
	'in'=>'cookie',
	'name'=>'PHPSESSID',
], [
	'description'=>'Authenticated browser session',
]);
```

Bearer token with custom resolver:

```php
SecurityScheme::bearer('machineToken', [
	'resolver'=>'app\\framework\\Auth\\MachineTokenGuard::authorize',
	'bearer_format'=>'MachineToken',
	'description'=>'Machine API bearer token',
]);
```

Header API key:

```php
SecurityScheme::apiKey('tenantKey', 'X-Tenant-Key', 'header', [
	'resolver'=>'app\\framework\\Auth\\TenantKeyGuard::authorize',
]);
```

Basic auth:

```php
SecurityScheme::basic('basicAuth', [
	'resolver'=>'app\\framework\\Auth\\BasicAuthGuard::authorize',
]);
```

OAuth2 or OpenID Connect:

```php
SecurityScheme::oauth2('oauth', [
	'clientCredentials'=>[
		'tokenUrl'=>'https://example.com/oauth/token',
		'scopes'=>[
			'machines:read'=>'Read machine records',
		],
	],
], [
	'guard'=>'jwt',
]);

SecurityScheme::openIdConnect('oidc', 'https://example.com/.well-known/openid-configuration', [
	'guard'=>'jwt',
]);
```

Custom documented scheme:

```php
$signature=SecurityScheme::custom(
	'signatureAuth',
	[
		'type'=>'apiKey',
		'in'=>'header',
		'name'=>'X-Signature',
	],
	[
		'type'=>'callback',
		'resolver'=>'app\\framework\\Auth\\SignatureGuard::authorize',
	]
);

$scheme_name=$signature->name();
$scheme_scopes=$signature->scopes();
$scheme_definition=$signature->toArray();
```

Custom auth resolver signature:

```php
public static function authorize(
	mixed $credentials,
	\Dataphyre\Http\Request $request,
	array $route,
	array $scopes,
	array $runtime
): bool|array|\Dataphyre\Http\Response
```

Resolver results:

- `true` authorizes the request.
- `false` denies the request with the scheme's failure settings.
- `['authorized'=>true]` authorizes the request.
- `['authorized'=>true, 'identity'=>$user, 'context'=>['application'=>$app], 'meta'=>['rate_limit'=>$limit]]` authorizes the request and makes auth state available through `ApiContext`.
- `['authorized'=>false, 'status'=>403, 'message'=>'Forbidden']` denies the request with a custom payload.
- `Dataphyre\Http\Response` returns a fully custom failure response.

### Request Bodies And Responses

JSON request body:

```php
Api::post('/api/machines')
	->execute('app\\framework\\Api\\MachineEndpoints::store')
	->jsonBody([
		'type'=>'object',
		'properties'=>[
			'name'=>['type'=>'string'],
			'status'=>['type'=>'string'],
		],
		'required'=>['name'],
	], true, 'Machine payload')
	->jsonResponse(201, [
		'type'=>'object',
		'properties'=>[
			'ok'=>['type'=>'boolean'],
			'id'=>['type'=>'string'],
		],
		'required'=>['ok', 'id'],
	]);
```

Custom content types:

```php
Api::post('/api/upload')
	->execute('app\\framework\\Api\\UploadEndpoints::store')
	->requestBody([
		'multipart/form-data'=>[
			'schema'=>[
				'type'=>'object',
				'properties'=>[
					'file'=>['type'=>'string', 'format'=>'binary'],
				],
				'required'=>['file'],
			],
		],
	], true);
```

Response passthrough from an execution target:

```php
use Dataphyre\Http\Response;

return Response::json([
	'ok'=>true,
	'id'=>$machine_id,
], 201);
```

### Swagger Routes

Serve OpenAPI JSON and Swagger UI for the current application:

```php
use Dataphyre\Api\Api;

return array_merge([
	// api endpoints...
], Api::documentationRoutes([
	'bootstrap'=>__DIR__.'/framework_bootstrap.php',
	'docs_path'=>'/_framework/api/docs',
	'spec_path'=>'/_framework/api/openapi.json',
	'title'=>'Example API',
	'version'=>'1.0.0',
]));
```

Low-level controller routes:

```php
use Dataphyre\Routing\ControllerAction;
use Dataphyre\Routing\Route;

$spec=Route::get(
	'/_framework/api/openapi.json',
	ControllerAction::static('Dataphyre\\Api\\OpenApiController', 'show', [
		'bootstrap'=>__DIR__.'/framework_bootstrap.php',
	])
)->compile();
$spec['api_docs']=[
	'application'=>'example_app',
	'title'=>'Example API',
	'version'=>'1.0.0',
];

$docs=Route::get(
	'/_framework/api/docs',
	ControllerAction::static('Dataphyre\\Api\\SwaggerUiController', 'show', [
		'bootstrap'=>__DIR__.'/framework_bootstrap.php',
	])
)->compile();
$docs['api_docs']=[
	'spec_path'=>'/_framework/api/openapi.json',
	'title'=>'Example API',
];
```

### Discovery

Dataphyre API discovers endpoints from the current application's compiled route manifest.

```php
$endpoints=Api::discoverApplication();
$openapi=Api::openApiDocument();
```

Target a specific application id when the current runtime has a project root:

```php
$openapi=Api::openApiDocument('example_app');
```

Discover from a compiled manifest directly:

```php
$manifest=require ROOTPATH['applications'].'example_app/cache/compiled_routes.php';
$endpoints=Api::discoverManifest($manifest);
```

Low-level manager and OpenAPI generator:

```php
use Dataphyre\Api\Api;
use Dataphyre\Api\OpenApiGenerator;

$manager=Api::manager();
$endpoints=$manager->discoverApplication('example_app');

$document=(new OpenApiGenerator())->generate($endpoints, [
	'title'=>'Example API',
	'version'=>'1.0.0',
	'servers'=>[
		['url'=>'https://api.example.com'],
	],
]);
```

### Common Recipes

Public read endpoint:

```php
Api::get('/api/public/catalog')
	->summary('List public catalog entries')
	->queryParameter('page', ['type'=>'integer'])
	->schema([
		'page'=>'integer|min_value:1',
	], [
		'page'=>1,
	], [
		'sources'=>['query'],
	])
	->cache(300, [
		'names'=>['catalog.public'],
		'vary_headers'=>['Accept-Language'],
	])
	->execute('app\\framework\\Api\\CatalogEndpoints::index');
```

Authenticated write endpoint:

```php
Api::post('/api/machines')
	->summary('Create one machine')
	->auth(SecurityScheme::jwtGuard())
	->jsonBody([
		'type'=>'object',
		'properties'=>[
			'name'=>['type'=>'string'],
			'status'=>['type'=>'string'],
		],
		'required'=>['name', 'status'],
	], true)
	->schema([
		'name'=>'required|string|min:2|max:120',
		'status'=>'required|in:active,pending,disabled',
	], [], [
		'sources'=>['body'],
	])
	->withTrace(true, [
		'include_auth'=>true,
		'include_sql'=>true,
	])
	->execute('app\\framework\\Api\\MachineEndpoints::store');
```

Compatibility wrapper endpoint:

```php
$mobile=Api::profile('example.mobile', [
	'prefix'=>'/api/mobile/v1',
	'dispatch'=>[
		'limit'=>128,
		'continue_on_error'=>true,
	],
]);

$mobile->post('/chained')
	->auth(SecurityScheme::apiKey('mobileKey', 'X-Mobile-Key', 'header', [
		'resolver'=>'app\\framework\\Api\\MobileAuth::authorize',
	]))
	->execute('app\\framework\\Api\\MobileCompatEndpoints::chain');
```

Internally composed dashboard endpoint:

```php
Api::get('/api/dashboard')
	->withBinding('summary', 'app\\framework\\Api\\DashboardBindings::summary')
	->withQueryIdentity(
		'orders',
		OrderRepository::query()->latest('created_at'),
		'records'
	)
	->withSearchIdentity(
		'alerts',
		Dataphyre\FulltextEngine\Search::query('alerts')->limit(10),
		'results'
	)
	->cache(90, [
		'names'=>['dashboard'],
	])
	->withTrace(true, [
		'include_bindings'=>true,
		'include_sql'=>true,
	])
	->execute('app\\framework\\Api\\DashboardEndpoints::show');
```

### Notes

- API endpoints compile into normal Dataphyre route manifests.
- `execute(...)` uses compiled callable metadata, not anonymous closures.
- `alias(...)` and `aliases(...)` let compatibility wrappers target endpoints by stable legacy keys instead of hardcoded route paths.
- `cache(...)` stores final endpoint responses and uses request, auth, and binding identities to keep cache hits explicit.
- `schema(...)` enforces runtime validation; explicit OpenAPI request bodies and parameters document the public contract.
- `withTrace()` adds SQL trace context only when the SQL framework is available.
- Swagger UI uses the configured asset URLs from the documentation route options.
