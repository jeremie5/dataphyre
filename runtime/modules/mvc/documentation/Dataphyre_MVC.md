# Dataphyre MVC

Dataphyre MVC is a native framework module that composes the existing HTTP,
Routing, Templating, and SQL modules into a small application layer. It is meant
for Dataphyre applications that want controller classes, view results, route
groups, middleware, redirects, JSON responses, and lightweight models without
bringing in an external web framework.

MVC routes compile through `Dataphyre\Routing\Route` and are matched by the
routing module's compiled route dispatcher. The MVC module coordinates
controller execution and adapts MVC-specific results, while Dataphyre Routing
owns path, method, manifest, middleware, and controller descriptor mechanics and
Dataphyre HTTP owns request, response, and action argument primitives.

The dispatcher caches the compiled manifest for the current route collection
revision and recompiles automatically when routes are added or an existing route
definition is renamed or given middleware.

## Loading

```php
\dataphyre\core::load_framework_modules(['mvc']);
```

The MVC bootstrap loads `http`, `routing`, `templating`, and `sql` framework
classes when the core loader is available.

## Configuration

Install config lives in `config/mvc.php`.

```php
return [
	'default_app'=>'shop',
	'apps'=>[
		'shop'=>[
			'controllers'=>['namespace'=>'App\\Controllers'],
			'models'=>['namespace'=>'App\\Models'],
			'views'=>['path'=>ROOTPATH['app'].'views'],
			'routes'=>function(\Dataphyre\Mvc\RouteCollection $routes): void {
				$routes->get('/', 'HomeController@index');
				$routes->get('/products/{id}', 'ProductController@show');
			},
		],
	],
];
```

A copyable starting point is available at `config/mvc.example.php`.

Per-app config inherits top-level config. Associative values such as
`controllers`, `views`, and `middleware` merge deeply; list-like values such as
`routes` replace the inherited list for that app.

Apps may also be registered programmatically:

```php
\Dataphyre\Mvc\Mvc::register('shop', [
	'controllers'=>['namespace'=>'App\\Controllers'],
	'routes'=>function(\Dataphyre\Mvc\RouteCollection $routes): void {
		$routes->get('/', 'HomeController@index')->name('home');
	},
]);
```

`routes` may be a closure, a single associative route definition array, a PHP
route file, a directory of `*.php` route files, or a mixed list of closures,
files, `RouteDefinition` instances, and array route definitions. Route files
receive `$routes` and `$app` variables and may also return a closure, route
definition, single route array, or array of route definitions.
Array route definitions may use `handler`, `view`/`template`,
`redirect`/`location`, or `redirect_route`/`to_route` entries, plus common
route options such as `name`, `middleware`, `method`, `methods`, `parameters`,
`query`, and `status` for redirects.

Set `manifest_cache` to a file path or `true` to cache the compiled Routing
manifest. Cached manifests include a signature built from the route collection
revision and route file mtimes, and are refreshed automatically when they change.
Routes backed by closures still dispatch normally, but are skipped for file cache
writing because PHP cannot safely export closures. Cache files are written via
`Dataphyre\Routing\RouteCompiler`, which also owns route source discovery,
manifest signatures, manifest reads, and exportability checks.

## Controllers

```php
namespace App\Controllers;

use Dataphyre\Http\Request;
use Dataphyre\Mvc\Controller;

final class HomeController extends Controller {
	public function index(Request $request): mixed {
		return $this->view('home.index', ['title'=>'Dataphyre MVC']);
	}
}
```

Controller actions receive the current `Dataphyre\Http\Request` first, followed
by route parameters in path order.
Action invocation runs through the app `Dataphyre\Mvc\Container`, which can
autowire controller constructors and method dependencies while preserving request,
route parameter, MVC context, form request, and model binding injection.
`Dataphyre\Http\Request` supports instance macros for project-specific request
helpers:

```php
\Dataphyre\Http\Request::macro('tenant', fn(string $default='default'): string =>
	(string)$this->input('tenant', $default)
);
```

Controller strings such as `HomeController@index` compile through
`Dataphyre\Routing\ControllerAction`, with the app controller namespace applied
before the route manifest is built. A controller string without an explicit
method, such as `HomeController`, dispatches to `__invoke`.

Actions may also type-hint `Dataphyre\Mvc\MvcRouteContext` to receive route
metadata while keeping parameter injection:

```php
public function show(Request $request, MvcRouteContext $context, string $id): mixed {
	return ['route'=>$context->name(), 'id'=>$id];
}
```

Controller helpers include `view()`, `json()`, `created()`, `noContent()`,
`redirect()`, `route()`, `redirectToRoute()`, and `back()`.

## Container And Providers

Each `MvcApplication` owns a lightweight `Dataphyre\Mvc\Container` and
`Dataphyre\Mvc\ProviderRegistry`.

```php
$app->container()->bind(LoggerContract::class, FileLogger::class);
$app->container()->singleton(CacheStore::class);
```

Configured `providers` are registered when the app is created and booted before
the first dispatch. Providers may bind services, add routes, or prepare app
state:

```php
final class AppServiceProvider extends \Dataphyre\Mvc\ServiceProvider {
	public function register($app, $providers): void {
		parent::register($app, $providers);
		$app->container()->bind(LoggerContract::class, FileLogger::class);
	}
}
```

## Validation

Use `Dataphyre\Mvc\Validator` directly or inject a `FormRequest` subclass into a
controller action. Form requests validate automatically before the action is
called. Validation failures return JSON 422 responses unless a custom
`error_handler` handles the `ValidationException` or the app opts into browser
redirects.
Supported rule primitives include `bail`, `required`, `sometimes`, `nullable`,
`string`, `int`/`integer`, `numeric`, `boolean`, `accepted`, `array`, `alpha`,
`alpha_num`, `distinct`, `in`, `same`, `different`, `confirmed`, `regex`,
`email`, `url`, `date`, `before`, `after`, `before_or_equal`,
`after_or_equal`, `present`, `required_if`, `required_unless`,
`required_with`, `required_without`, `prohibited`, `prohibited_if`,
`prohibited_unless`, `exclude`, `exclude_if`, `exclude_unless`,
`exclude_with`, `exclude_without`, `starts_with`, `ends_with`, `digits`,
`digits_between`, `min`, `max`, `size`, `between`, `file`, `image`, `mimes`,
and `mimetypes`.
Rule arrays may also include callables. A callable receives
`($value, $field, $data)` and may return `true`/`null` to pass, `false` for the
default custom error, or a string error message.
Use `bail` to stop validating a field after its first failure, or call
`Validator::make(...)->stopOnFirstFailure()` to stop the whole validator after
the first failing field.
Nested input can be validated with dot paths and `*` wildcards for repeatable
array items; validated output preserves the same nested shape.
Custom messages and attribute labels may also target wildcard keys such as
`items.*.sku.alpha_num` and `items.*.sku`.

```php
$validated=$this->validate($request, [
	'name'=>'required|string|min:3',
	'email'=>'required|email',
	'user.company.name'=>'required_if:user.type,company',
	'items.*.sku'=>'required|alpha_num|distinct',
	'items.*.quantity'=>'required|integer|min:1',
	'items.*.shipping_weight'=>'exclude_if:items.*.kind,digital|numeric|min:1',
]);
```

`Controller::validate()` and `Mvc::validate()` validate the same merged request
data shape as form requests: query, body, uploaded files, and route parameters.

```php
final class StoreProductRequest extends \Dataphyre\Mvc\FormRequest {
	protected function prepareForValidation(): void {
		$this->merge(['slug'=>strtolower((string)$this->input('slug'))]);
	}

	public function rules(): array {
		return ['name'=>'required|string|min:3'];
	}

	protected function passedValidation(): void {
		// Runs after validation succeeds.
	}
}
```

Form requests may override `prepareForValidation()`, `messages()`,
`attributes()`, `withValidator()`, `failedValidation()`, `passedValidation()`,
`authorizationMessage()`, `authorizationStatus()`, and
`failedAuthorization()`. Set `protected bool $stopOnFirstFailure=true` or call
`$validator->stopOnFirstFailure()` inside `withValidator()` to short-circuit
validation. Set `protected string $errorBag='profile'` to flash browser
redirect validation errors into a named error bag.

Form requests include uploaded files from `Request::files()` in their validation
data. File `min` and `max` values are measured in kilobytes:

```php
final class StoreAvatarRequest extends \Dataphyre\Mvc\FormRequest {
	public function rules(): array {
		return ['avatar'=>'required|file|image|mimes:jpg,png|max:2048'];
	}
}
```

```php
'validation_redirect'=>true,
'validation_redirect_fallback'=>'/',
```

With `validation_redirect` enabled, status `422` validation failures redirect
back to the request referer, flash old input, and flash errors. Other validation
statuses, such as authorization failures, continue to use their normal response.
Requests that expect JSON through `Accept: application/json` or AJAX-style
headers also continue to receive JSON validation responses.

## Sessions

MVC includes a small session helper and default `session` middleware alias.
`Session` uses native `$_SESSION` when PHP sessions are active and a static
fallback for CLI/tests. Flash data is aged by terminable middleware:

```php
$routes->post('/profile', 'ProfileController@store')->middleware('session');

\Dataphyre\Mvc\Session::flash('notice', 'Saved');
\Dataphyre\Mvc\Session::put('cart.items.0.sku', 'ABC123');
$sku=\Dataphyre\Mvc\Session::get('cart.items.0.sku');
$notice=\Dataphyre\Mvc\Session::pull('notice');
$summary=\Dataphyre\Mvc\Session::remember('cart.summary', static fn(): array => ['items'=>1]);
$items=\Dataphyre\Mvc\Session::increment('cart.summary.items');
\Dataphyre\Mvc\Session::push('cart.events', 'updated');
\Dataphyre\Mvc\Session::flashInput($request->input());
$name=\Dataphyre\Mvc\Session::old('name');
```

Redirects can flash data, old input, and validation-style errors:

```php
return $this->back()
	->with('notice', 'Please check the form.')
	->withInput($request->input())
	->withErrors(['email'=>['Email is required.']]);

$errors=\Dataphyre\Mvc\Session::errors();
$email=\Dataphyre\Mvc\Session::old('user.email');
$email_errors=\Dataphyre\Mvc\Session::error('user.email');
$has_errors=\Dataphyre\Mvc\Session::hasErrors();
```

## CSRF Protection

MVC registers a default `csrf` middleware alias. It uses the session token store,
allows safe methods, and rejects unsafe requests with status `419` when the
submitted token does not match. Tokens may be submitted as `_token` input or the
`X-CSRF-Token` header:

```php
$routes->post('/profile', 'ProfileController@store')->middleware('session', 'csrf');

$token=\Dataphyre\Mvc\Session::token();
```

Controllers may call `$this->csrfToken()` when rendering forms.

## Route Model Binding

Actions may type-hint `Dataphyre\Mvc\Model` subclasses for route parameters.
MVC resolves models after Routing matches the path and before action invocation.
Models can define `routeKeyName()` or `resolveRouteBinding()`; app config or
route options may also provide explicit `model_bindings`.

```php
$routes->get('/products/{product}', 'ProductController@show', [
	'bindings'=>[
		'product'=>['model'=>Product::class, 'key'=>'slug'],
	],
]);
```

Missing bound models become 404 responses, using `not_found_handler` when one is
configured.

`not_found_handler` and `error_handler` may be set in app config. Both handlers
use the container call path, so they can type-hint the current request; error
handlers can also type-hint `Throwable`, `Exception`, or the concrete exception
class before returning any normal MVC/HTTP response value.

## Routes

```php
use Dataphyre\Mvc\Mvc;

$routes=Mvc::routes('shop');
$routes->group(['prefix'=>'admin', 'middleware'=>['auth']], function($routes): void {
	$routes->get('/orders', 'Admin\\OrderController@index')->name('admin.orders');
});
$routes->prefix('api', function($routes): void {
	$routes->middleware('auth', function($routes): void {
		$routes->name('api.', function($routes): void {
			$routes->get('/orders/{order}', 'Api\\OrderController@show')->name('orders.show');
		});
	});
});
$routes->controller('OrderController', function($routes): void {
	$routes->get('/orders', 'index')->name('orders.index');
	$routes->get('/orders/{order}', 'show')->name('orders.show');
});
$routes->domain('{tenant}.example.com', function($routes): void {
	$routes->get('/orders/{order}', 'Tenant\\OrderController@show')
		->whereNumber('order')
		->name('tenant.orders.show');
});
```

Supported helpers are `get`, `post`, `head`, `put`, `patch`, `delete`,
`options`, `any`, `view`, `redirect`, `redirectToRoute`, `fallback`, and
`match`. `resource` registers conventional index/create/store/show/edit/update/
destroy routes by calling those same helpers. `apiResource` delegates to
`resource` with `create` and `edit` omitted. `singletonResource` registers
create/store/show/edit/update/destroy routes without an identifier parameter,
and `apiSingletonResource` omits `create` and `edit`.

Group helpers `prefix()`, `name()`, `middleware()`, `domain()`, `controller()`,
`defaults()`, `where()`, `whereNumber()`, `whereAlpha()`, `whereAlphaNumeric()`,
`whereUuid()`, `whereUlid()`, and `whereIn()` are callback wrappers over
`group([...])`, so they compose with the same option merging rules.

`controller()` groups let routes reference action names without repeating the
controller class. The lower-level `group(['controller'=>...])` option works the
same way and still compiles through `Dataphyre\Routing\ControllerAction`.

Named routes can generate URLs:

```php
$routes->get('/products/{id}', 'ProductController@show')->name('products.show');
$url=$routes->url('products.show', ['id'=>42], ['preview'=>1]);
$signed=$routes->signedUrl('products.show', ['id'=>42], ['preview'=>1]);
$routes->redirectToRoute('/latest-product', 'products.show', ['id'=>42]);
```

MVC URL generation delegates to `Dataphyre\Routing\RouteManifest::namedUrl()`
and `Dataphyre\Routing\Route::url()`, so named-route lookup and path placeholder
replacement are shared with the Routing module. Domain route placeholders are
filled from the same parameter array:

```php
$url=$routes->url('tenant.orders.show', ['tenant'=>'acme', 'order'=>42]);
// //acme.example.com/orders/42
```

Route parameters can be constrained with `where()` or convenience helpers:

```php
$routes->get('/posts/{slug}', 'PostController@show')
	->where('slug', '[a-z0-9-]+');
$routes->get('/orders/{order}', 'OrderController@show')->whereNumber('order');
$routes->get('/reports/{filter?}', 'ReportController@index')->whereAlpha('filter');
$routes->get('/states/{state}', 'StateController@show')->whereIn('state', ['open', 'closed']);
$routes->get('/events/{event}', 'EventController@show')->whereUlid('event');
```

App config can define global `route_patterns` for parameter names. `patterns`
and `constraints` are accepted aliases; route and group constraints override
app-level defaults:

```php
'route_patterns'=>[
	'id'=>'[0-9]+',
	'slug'=>'[a-z0-9-]+',
],
```

Optional parameters are omitted from named URLs when no value is supplied:

```php
$routes->url('reports.index'); // /reports
$routes->url('reports.index', ['filter'=>'open']); // /reports/open
```

Defaults can fill missing optional route parameters during dispatch and named
URL generation:

```php
$routes->get('/{locale?}/products/{product}', 'ProductController@show')
	->defaults('locale', 'en')
	->name('localized.products.show');
```

When Dataphyre Localization is loaded, controllers and `Mvc` can delegate
translation lookups without owning locale catalogs:

```php
$title=$this->translate('local:products.title', 'Products', ['count'=>$count], $locale);
$label=$this->choice($count, 'products.one', 'products.many', 'products.none');
$exists=$this->translationHas('global:navigation.home', $locale);
```

When Dataphyre Currency is loaded, controllers and `Mvc` can format and convert
money through the currency module:

```php
$price=$this->moneyFormat(19.99, false, 'CAD');
$display=$this->moneyToDisplay($product_price, true);
$shares=$this->moneyAllocate(10.00, 'CAD', [1, 3]);
```

Dataphyre Date Translation can also be reached through `translateDate()` or
`localizedDate()`:

```php
$date=$this->translateDate('March 15th', 'fr-CA', 'F jS');
```

App config can define global `route_defaults` for optional parameters. The
`defaults` key is accepted as an alias; group and route defaults override
app-level values:

```php
'route_defaults'=>[
	'locale'=>'en',
	'format'=>'json',
],
```

`RouteDefinition::macro()` can register project-level fluent route helpers that
compose the native mutators:

```php
RouteDefinition::macro('admin', function(string $name): RouteDefinition {
	return $this
		->middleware('auth', 'verified')
		->defaults('guard', 'admin')
		->name($name);
});

$routes->get('/admin/reports/{filter?}', 'Admin\\ReportController@index')
	->admin('admin.reports.index');
```

`RouteCollection::macro()` can register higher-level route registration helpers:

```php
RouteCollection::macro('adminPage', function(string $path, string $name, mixed $handler): ?RouteDefinition {
	$this->prefix('admin', function(RouteCollection $routes) use ($path, $name, $handler): void {
		$routes->get($path, $handler)
			->middleware('auth')
			->name('admin.'.$name);
	});
	return $this->named('admin.'.$name);
});

$routes->adminPage('/reports', 'reports.index', 'Admin\\ReportController@index');
```

Signed URLs use `signed_url_secret` or `DATAPHYRE_MVC_SIGNING_KEY` and add a
`signature` query value. Temporary signed URLs also add `expires`.
`SignedUrl::valid($request, $secret)` validates the current request path and
query.
MVC registers a default `signed` middleware alias, so routes can guard signed
links declaratively:

```php
$routes->get('/download/{id}', 'DownloadController@show')
	->name('downloads.show')
	->middleware('signed');
```

MVC route method and path normalization also delegates to
`Dataphyre\Routing\Route`, keeping route strings consistent between MVC and
compiled Routing manifests.

MVC matches routes using `Request::effective_method()`, so HTML forms can submit
POST requests with `_method=PATCH`, `_method=PUT`, or `_method=DELETE` while
controllers can still inspect the original method through `method()`.

MVC stores its source route pointer in compiled route `metadata['mvc']`, so the
Routing manifest keeps a generic shape while MVC can still map a match back to
the live route definition.

Route collections can expose an introspection list for tooling:

```php
$routes=Mvc::routes('shop')->list();
$routes=\Dataphyre\Mvc\Mvc::routeList('shop');
```

Each entry includes `methods`, `domain`, `path`, `name`, `action`, `middleware`,
`bindings`, and `constraints`.

The same data is available from the CLI:

```powershell
php common/dataphyre/runtime/modules/mvc/kernel/route_list.php shop
php common/dataphyre/runtime/modules/mvc/kernel/route_list.php --config=common/dataphyre/config/mvc.example.php --json
```

Compiled route manifests can be warmed ahead of the first request when
`manifest_cache` points at a writable file:

```powershell
php common/dataphyre/runtime/modules/mvc/kernel/cache_routes.php shop
php common/dataphyre/runtime/modules/mvc/kernel/cache_routes.php --config=common/dataphyre/config/mvc.example.php
php common/dataphyre/runtime/modules/mvc/kernel/clear_cached_routes.php shop
```

Groups can prefix route names with `as`:

```php
$routes->group(['prefix'=>'admin', 'as'=>'admin.'], function($routes): void {
	$routes->get('/dashboard', 'DashboardController@index')->name('dashboard');
	$routes->get('/reports', 'ReportController@index', ['name'=>'reports']);
});
```

Both fluent `->name(...)` calls and route option `name` values receive the group
name prefix once.

Resource routes are intentionally thin sugar over normal MVC routes:

```php
$routes->resource('products', 'ProductController', [
	'except'=>['destroy'],
	'param'=>'product',
	'names'=>['show'=>'products.display'],
]);

$routes->apiResource('api/products', 'ProductController');
$routes->singletonResource('profile', 'ProfileController');
$routes->apiSingletonResource('settings', 'SettingsController');

$routes->resources([
	'products'=>'ProductController',
	'orders'=>'OrderController',
	'articles'=>[
		'controller'=>'ArticleController',
		'only'=>['index', 'show'],
		'param'=>'article',
	],
]);
$routes->apiResources(['api/products'=>'ProductController']);
$routes->singletonResources(['profile'=>'ProfileController']);
$routes->apiSingletonResources(['settings'=>'SettingsController']);

$routes->resource('catalog/products', 'ProductController', [
	'parameters'=>['products'=>'item'],
]);

$routes->resource('admin/products', 'ProductController', [
	'name'=>'products',
]);

$routes->resource('legacy/products', 'ProductController', [
	'verbs'=>['create'=>'new', 'edit'=>'modify'],
]);

$routes->resource('legacy/products', 'ProductController', [
	'only'=>['index', 'show'],
	'actions'=>[
		'index'=>'listing',
		'show'=>'display',
	],
]);

$routes->resource('posts/{post}/comments', 'CommentController', [
	'shallow'=>true,
]);

$routes->resource('optioned/products', 'ProductController', [
	'middleware_for'=>['show'=>'auth'],
	'without_middleware_for'=>['index'=>'cache'],
	'action_options'=>[
		'show'=>['where'=>['product'=>'[0-9]+']],
	],
]);
```

The default names are `products.index`, `products.create`, `products.store`,
`products.show`, `products.edit`, `products.update`, and `products.destroy`.
Singleton default names use the resource name and action, such as
`profile.show`. Use `only` or `except` to filter actions. Resource parameter
maps may target the full resource path or the leaf segment for nested resources.
Use `name` or `as` to override the generated base route name, and `names` for
per-action route names.
Use app config `resource_verbs` or `resource_uri_verbs` to replace the default
`create` and `edit` URI segments globally; route-level `verbs` or `uri_verbs`
override those defaults.
Use `actions` to map conventional resource actions to custom controller method
names without changing route names or HTTP semantics. Nested resources omit
placeholder segments from generated route names; with `shallow=>true`,
collection actions stay nested while member actions use the leaf path and name,
such as `comments.show`. `action_options` and `options_for` can apply normal
route options to one generated action, while `middleware_for` and
`without_middleware_for` are shortcuts for action-specific middleware changes.

Middleware uses the same compiled definition shape as Dataphyre Routing.
Parameters from aliases such as `role:admin` are passed to `handle()` after the
`$request` and `$next` arguments. MVC delegates middleware string normalization
to `Dataphyre\Routing\Route::normalizeMiddleware()`.

App middleware aliases live in the app's `middleware` config and are resolved by
the Routing dispatcher. Aliases may point to middleware classes, class
definition arrays, or callables; app aliases override built-in Routing aliases
for that MVC app.

Apps may also define `global_middleware` and `middleware_groups`. Global
middleware wraps every matched route. Groups expand before alias resolution, so
route definitions can use `$routes->get(...)->middleware('web')` while still
delegating individual middleware aliases to the Routing resolver.

Routes may opt out of route/group middleware with `withoutMiddleware()`:

```php
$routes->get('/public-preview', 'PreviewController@show')
	->middleware('web')
	->withoutMiddleware('auth');
```

This only filters middleware attached through routes or route groups; app-wide
`global_middleware` and `middleware_stack` still run.

Controllers may declare middleware in their constructor with
`$this->middleware('auth')` or parameterized aliases such as
`$this->middleware('tag:admin')`. Controller middleware runs through the same
MVC dispatcher middleware runner, group expansion, and Dataphyre Routing alias
resolver as route middleware. Use `only()` or `except()` to scope controller
middleware to action names:

```php
final class ProductController extends Controller {
	public function __construct(){
		$this->middleware('auth')->except('index', 'show');
		$this->middleware('csrf')->only(['store', 'update', 'destroy']);
	}
}
```

Object middleware may also expose `terminate(Request $request, Response $response,
...$parameters)`. MVC calls terminable middleware after the action result has
been normalized to a `Dataphyre\Http\Response`.

MVC registers default `session` and `csrf` middleware aliases for browser-style
routes. A typical `web` group can compose them:

```php
'middleware_groups'=>[
	'web'=>['session', 'csrf'],
],
```

MVC also registers default `auth` and `guest` aliases that delegate to the
existing `dataphyre\access` module. Auth types can be passed as middleware
parameters, and controllers may read the same module through `$this->loggedIn()`,
`$this->userId()`, and `$this->authContext()`:

```php
$routes->get('/dashboard', 'DashboardController@index')->middleware('auth');
$routes->get('/api/profile', 'Api\\ProfileController@show')->middleware('auth:api');
$routes->get('/login', 'LoginController@create')->middleware('guest');
```

MVC registers default `can` and `can_any` aliases that delegate authorization to
the existing `dataphyre\permission` module. Controller helpers mirror those
checks through `$this->can()`, `$this->canAny()`, `$this->authorize()`, and
`$this->authorizeAny()`:

```php
$routes->get('/orders', 'OrderController@index')->middleware('can:orders.view');
$routes->post('/orders/{order}/refund', 'OrderController@refund')
	->middleware('can_any:orders.refund,orders.force_refund');
```

MVC registers a default `throttle` middleware alias for in-process rate limits:

```php
$routes->get('/api/orders', 'OrderController@index')->middleware('throttle:60,60,orders');
```

The parameters are `max_attempts`, `decay_seconds`, and optional bucket name.

MVC registers a default `cache` middleware alias for response cache headers and
conditional `304 Not Modified` handling:

```php
$routes->get('/reports', 'ReportController@index')->middleware('cache:300,public,reports-v1');
```

The parameters are `seconds`, visibility (`public` or `private`), optional ETag,
and optional last-modified timestamp.

For stored values, controllers and `Mvc` can delegate to the Dataphyre Cache
module with `cacheGet()`, `cachePut()`, `cacheRemember()`, `cacheForget()`,
`cacheIncrement()`, and `cacheDecrement()`:

```php
$summary=$this->cacheRemember('dashboard.summary', 300, fn(): array => $this->buildSummary());
$views=$this->cacheIncrement('dashboard.views');
```

When Dataphyre Sanitation is loaded, controllers and `Mvc` can clean request
data through the Sanitation module's value, bag, schema, preset, and fail-fast
APIs:

```php
$name=$this->sanitize($request->input('name'));
$email=$this->inputBag($request)->email('email');
$profile=$this->sanitized($request, [
	'name'=>'default',
	'email'=>'email',
	'age'=>'integer',
]);
$safe=$this->sanitizedPresetOrFail('profile.update', $request);
```

When Dataphyre Async is loaded, controllers and `Mvc` can dispatch application
tasks without owning the async runtime:

```php
$task=$this->asyncDispatch([ReportJob::class, 'run'], [$report_id], 'coroutine');
$inline=$this->asyncInline(fn(): array => $this->buildPreview());
$timer=$this->asyncAfter(fn(): mixed => $this->warmCache(), 250);
```

When Dataphyre Reactor is loaded, MVC can mount server-driven components and
serve Reactor transport responses through normal routes:

```php
$html=$this->reactorMount('seller-health', ['seller_id'=>42]);
return $this->reactorDispatch($request->input());
return $this->reactorBatch($request->input('batch', []));
```

## Results

Actions may return:

- `Dataphyre\Http\Response`
- `Dataphyre\Mvc\ViewResult`
- `Dataphyre\Mvc\RedirectResult`
- `Dataphyre\Mvc\ValidationException` responses
- `Dataphyre\Templating\RenderedTemplate`
- `Dataphyre\Templating\TemplateView`
- arrays or `JsonSerializable` values for JSON
- strings for HTML
- `null` for `204 No Content`

MVC normalizes action results through `Dataphyre\Http\Response::normalize()`
after adapting MVC-specific view and redirect result objects.
Configured `response_headers` are applied through `Response::withHeaders()`.
HTTP responses, view results, and redirect results can attach cookies with
`withCookie()` or expire them with `withoutCookie()`.
Controllers and `Mvc` can also delegate directly to Dataphyre Templating for
inline rendering and asset manifests:

```php
$html=$this->renderTemplateString('Hello {{ name }}', ['name'=>'Avery']);
$head=$this->templateAssetHtml('layouts/app.tpl', 'head');
$assets=$this->templateAssets('layouts/app.tpl');
```

Controllers and `Mvc` also expose `file()` and `download()` helpers for local
file responses.
When the Storage module is loaded, MVC can serve stored objects through
`storageFile()` and `storageDownload()`, or ask the storage disk for a temporary
URL:

```php
return $this->storageFile('invoices/2026-05.pdf', 'private');
return $this->storageDownload('exports/orders.csv', 'reports', 'orders.csv');
$url=$this->storageTemporaryUrl('uploads/avatar.png', time()+300, 'public');
```

When the Mailer module is loaded, controllers and `Mvc` can delegate message
sending, queueing, and template rendering through `sendMail()`, `queueMail()`,
and `renderMail()`:

```php
$result=$this->sendMail([
	'to'=>'customer@example.com',
	'subject'=>'Receipt',
	'html'=>'<p>Thanks for your order.</p>',
], 'sendgrid');

$queued=$this->queueMail($message, 'log', ['delay'=>60]);
$preview=$this->renderMail('mail.receipt', ['order'=>$order]);
```

`Dataphyre\Http\Response` supports static and instance macros for project
response builders:

```php
\Dataphyre\Http\Response::macro('problem', static fn(string $title, int $status=400) =>
	\Dataphyre\Http\Response::json(['title'=>$title, 'status'=>$status], $status)
);
```

Controllers and `Mvc` expose `abort()`, `abortIf()`, and `abortUnless()` for
intentional HTTP error responses:

```php
$this->abortUnless($userCanEdit, 403, 'Forbidden');
```

Abort responses use HTML by default and structured JSON when the request expects
JSON.

## Models

```php
namespace App\Models;

use Dataphyre\Mvc\Model;

final class Product extends Model {
	protected static ?string $table='products';
}

$product=Product::find(10);
$query=Product::query()->where('active', '=', 1);
```

The base model intentionally stays thin and delegates database behavior to the
Dataphyre SQL module.

## Host

```php
\Dataphyre\Mvc\Mvc::host('shop')->emit();
```

`emit()` dispatches the captured request and sends the normalized HTTP response.

## Regression Check

```powershell
.local\shopiro\php\php.exe -c .local\shopiro\php\php.ini common\dataphyre\runtime\modules\mvc\kernel\mvc_regression.php
```

The runner verifies route parameters, controller string resolution, named URL
generation, JSON responses, redirects, grouped middleware, parameterized
middleware, and 404 handling without requiring a full application bootstrap.
