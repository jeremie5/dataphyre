# Dataphyre Templating

The `templating` module has two layers:

- kernel: `\dataphyre\templating`
- framework: `\Dataphyre\Templating\...`

The kernel owns parsing and rendering. The framework gives applications a typed API for file views, inline templates, scoped state overrides, and render inspection.

## Kernel

The kernel entrypoint is:

```php
\dataphyre\templating::init(
	is_dev_mode: false,
	cache_dir: ROOTPATH['dataphyre'].'cache/templating/',
	strict_mode: false,
	asset_policy: []
);
```

Main kernel methods:

- `render(string $template_file, array $data=[], array $theme_values=[], array $slots=[]): string`
- `plan(string $template_file): array`
- `asset_manifest(string $template_file): array`
- `inspect(string $template_file, array $data=[], array $theme_values=[], array $slots=[]): array`
- `full_render(string $template_file, array $data=[], array $theme_values=[], array $slots=[]): string`
- `render_string(string $template, array $data=[], array $theme_values=[], array $slots=[], string $template_name='inline.tpl'): string`
- `plan_string(string $template, string $template_name='inline.tpl'): array`
- `asset_manifest_string(string $template, string $template_name='inline.tpl'): array`
- `inspect_string(string $template, array $data=[], array $theme_values=[], array $slots=[], string $template_name='inline.tpl'): array`
- `render_with_fallback(string $template_file, array $data=[], string $fallback_file='fallback.tpl'): string`
- `async_render(string $template_file, array $data=[]): object`

Kernel state helpers:

- `state(): array`
- `apply_state(array $overrides): void`
- `global_context(): array`
- `add_to_global_context(string $key, mixed $value): void`
- `clear_global_context(): void`
- `strict_mode(): bool`
- `set_strict_mode(bool $strict_mode): void`
- `asset_policy(): array`
- `set_asset_policy(array $asset_policy): void`
- `register_template_contract(string $template_name, array $contract): void`
- `template_contract(string $template_name): ?array`
- `clear_template_contract(?string $template_name=null): void`
- `resolve_component_template(string $reference): ?string`
- `register_component_contract(string $reference, array $contract): void`
- `component_contract(string $reference): ?array`
- `clear_component_contract(string $reference): void`

Kernel registration helpers:

- `register_tag(string $tag, callable $callback): void`
- `register_filter(string $filter, callable $callback): void`
- `register_extension(string $name, callable $callback): void`
- `register_helper(string $name, callable $callback): void`
- `register_event_hook(string $event, callable $callback): void`
- `register_preprocessing_hook(callable $hook): void`
- `register_postprocessing_hook(callable $hook): void`

Kernel example:

```php
\dataphyre\templating::add_to_global_context('brand_name', 'Example App');

\dataphyre\templating::register_tag('build', fn()=>APP_VERSION);
\dataphyre\templating::register_filter('upper', fn($value)=>mb_strtoupper((string)$value));

\dataphyre\templating::register_template_contract('/var/www/app/views/home.tpl', [
	'required'=>['title'],
	'optional'=>['user'],
]);

\dataphyre\templating::register_component_contract('cards/status', [
	'required'=>['title'],
	'required_slots'=>['content'],
]);

$plan=\dataphyre\templating::plan('/var/www/app/views/home.tpl');
$assets=\dataphyre\templating::asset_manifest('/var/www/app/views/home.tpl');
$inspection=\dataphyre\templating::inspect('/var/www/app/views/home.tpl', [
	'title'=>'Dashboard',
]);

$html=\dataphyre\templating::render_with_fallback(
	'/var/www/app/views/home.tpl',
	['title'=>'Dashboard'],
	'/var/www/app/views/fallback.tpl'
);

$inline=\dataphyre\templating::render_string(
	'<h1>{{title}}</h1><small>{{brand_name}}</small>',
	['title'=>'Inline render']
);
```

### Slots

Slots are structural template regions. They are parsed before normal data binding, filters, undefined-variable reporting, and post-processing so directive tags such as `{{endslot}}` are not treated as missing data.

Define slots in a template with `{{slot "name"}}...{{endslot}}`:

```html
<main>
	{{slot "content"}}Default content{{endslot}}
</main>
```

Provide slot content through the fourth render argument:

```php
$html=\dataphyre\templating::render(
	'/var/www/app/views/layout.tpl',
	['title'=>'Dashboard'],
	[],
	[
		'content'=>'<section>Runtime status</section>',
	]
);
```

Slot names support letters, numbers, underscores, and dashes. Template contracts can require or allow slots with `required_slots`, `optional_slots`, and `allow_additional_slots`.

## Framework

Load the framework layer with:

```php
\dataphyre\core::load_framework_module('templating');
```

Main facade:

```php
use Dataphyre\Templating\Templating;
```

Main framework classes:

- `Templating`
- `TemplatingManager`
- `TemplatingContext`
- `TemplateView`
- `RenderedTemplate`
- `BindingContext`
- `DataBinding`
- `BindingMetadataProvider`
- `BindingCacheIdentityProvider`
- `BindingPersistentCacheProvider`
- `BindingResolution`
- `CallableBinding`
- `CachedBinding`
- `RememberedBinding`
- `ConditionalBinding`
- `SqlQueryBinding`
- `SearchQueryBinding`
- `TemplateContract`
- `TemplateManifest`
- `TemplatePlan`
- `AssetPolicy`
- `AssetManifest`
- `TemplatingState`

### Facade examples

Render a file template:

```php
$view=Templating::render('/var/www/app/views/home.tpl', [
	'title'=>'Dashboard',
	'user'=>$user,
]);

echo $view->headHtml();
echo $view->content();
echo $view->bodyHtml();
```

Build a component view directly:

```php
$card=Templating::component('cards/status')
	->withProps(['title'=>'System'])
	->slot('content', '<strong>Healthy</strong>');
```

Bind lazy data to a view explicitly:

```php
$view=Templating::template('/var/www/app/views/orders.tpl')
	->withData(['tenant_id'=>$tenant_id])
	->withBinding('orders', Templating::binding(
		fn(BindingContext $context)=>OrderRepository::query()
			->whereEq('tenant_id', $context->get('tenant_id'))
			->latest('created_at')
			->getRecords(),
		'orders.query'
	));
```

Bind a SQL query directly without wrapping it in a manual closure:

```php
$view=Templating::template('/var/www/app/views/orders.tpl')
	->withQuery(
		'orders',
		OrderRepository::query()
			->whereEq('tenant_id', $tenant_id)
			->latest('created_at'),
		'records',
		[
			'binding_cache'=>[
				'ttl'=>60,
				'names'=>['orders.summary'],
			],
		]
	);
```

When a query exposes `fingerprint()`, you can opt into using that explicit query identity for binding reuse and cache alignment:

```php
$view=Templating::template('/var/www/app/views/orders.tpl')
	->withQueryIdentity(
		'orders',
		OrderRepository::query()
			->whereEq('tenant_id', $tenant_id)
			->latest('created_at'),
		'records'
	);
```

Bind a fulltext search directly:

```php
$view=Templating::template('/var/www/app/views/search.tpl')
	->withSearch(
		'results',
		Search::query('products')->where('title', $term),
		'results'
	);
```

Wrap a callable binding with an explicit cache identity so repeated uses in the same render can reuse it safely:

```php
$metrics=Templating::cachedBinding(
	fn()=>expensive_metrics_lookup($tenant_id),
	['tenant_id'=>$tenant_id, 'binding'=>'metrics']
);
```

Persist a binding result across renders with an explicit TTL and cache names:

```php
$metrics=Templating::rememberBinding(
	fn()=>expensive_metrics_lookup($tenant_id),
	['tenant_id'=>$tenant_id, 'binding'=>'metrics'],
	ttl: 60,
	names: ['dashboard.metrics']
);
```

Make a binding conditional so it only executes when the render context needs it:

```php
$view=Templating::template('/var/www/app/views/orders.tpl')
	->withData([
		'tenant_id'=>$tenant_id,
		'show_orders'=>$show_orders,
	])
	->withQueryWhen(
		'orders',
		OrderRepository::query()->whereEq('tenant_id', $tenant_id)->latest('created_at'),
		fn(BindingContext $context)=>$context->get('show_orders')===true,
		'records'
	);
```

Inspect a render and get a manifest of what it actually used:

```php
$result=Templating::inspect('/var/www/app/views/home.tpl', [
	'title'=>'Dashboard',
	'user'=>$user,
]);

$manifest=$result->manifest();
$summary=$manifest?->summary();
$render_trace_id=$result->renderTraceId();
$binding_trace=$result->bindingTrace();
```

When SQL query bindings are involved, the easiest unified path is the core runtime trace:

```php
use Dataphyre\Runtime;

$trace=Runtime::trace($result);
$summary=$trace->summary();
$bindings=$trace->bindingsWithSql();
$queries=$trace->queryFingerprints();
```

When `IS_PRODUCTION === true`, Dataphyre disables templating render-trace ids, binding-trace payloads, and correlated SQL trace capture.

The lower-level SQL trace API provides direct access to the SQL observer buffer:

```php
use Dataphyre\Database\DB;

$sql_traces=DB::recentTracesByContext([
	'render_trace_id'=>$result->renderTraceId(),
]);
```

Clear persistent binding cache groups explicitly:

```php
Templating::clearBindingCache('orders.summary', 'dashboard.metrics');
```

Compile a reusable static plan for a template:

```php
$plan=Templating::plan('/var/www/app/views/components/card.tpl');
$suggested_contract=$plan->suggestedContract();
$graph=$plan->graphNodes();
$assets=$plan->assetManifest();
$head=$assets->headHtml();
```

Set an asset delivery policy once:

```php
Templating::setAssetPolicy(
	AssetPolicy::defaults()
		->scriptDefer()
		->withoutPreload('images')
);
```

Built-in money formatting works in both helper and filter form:

```tpl
{{ order.total_money | money }}
{{ order.display_total | money('CAD') }}
{{ money(order.total_money, 'USD') }}
```

Register a contract and render in strict mode:

```php
Templating::registerComponentContract(
	'components/card',
	TemplateContract::define()
		->requiredProp('title', 'string')
		->optionalProp('variant', 'string', 'neutral')
		->requiredSlots('content')
		->allowAdditionalData(false)
);

$card=Templating::component('components/card')
	->strict()
	->withProps(['title'=>'Status'])
	->slot('content', '<strong>Healthy</strong>');
```

Render an inline template:

```php
$output=Templating::renderString(
	'<h1>{{title}}</h1><p>{{user.name}}</p>',
	[
		'title'=>'Welcome',
		'user'=>['name'=>'Avery'],
	]
);

echo $output;
```

Build a reusable view object:

```php
$card=Templating::template('/var/www/app/views/components/card.tpl')
	->mergeData(['title'=>'Status'])
	->slot('content', '<strong>Healthy</strong>');

echo $card->content();
```

### Context overrides

Use a context when you want a temporary templating state without mutating the global kernel state permanently:

```php
$context=Templating::context(
	is_dev_mode: true,
	cache_dir: ROOTPATH['dataphyre'].'cache/templating/dev',
	global_context: ['tenant'=>'example_app'],
	asset_policy: AssetPolicy::defaults()->scriptDefer()
);

echo $context->render('/var/www/app/views/home.tpl')->content();
```

Context example with globals, contracts, and inline source rendering:

```php
$context=Templating::context()
	->withDevMode(true)
	->withGlobal('tenant', 'example_app')
	->withStrictMode(true)
	->withTemplateContract(
		'/var/www/app/views/orders.tpl',
		TemplateContract::define(['orders'])->requiredSlots('actions')
	)
	->withComponentContract(
		'cards/status',
		TemplateContract::define()->requiredProp('title', 'string')
	);

$output=$context->source(
	'<section><h1>{{title}}</h1><p>{{tenant}}</p></section>',
	'tenant.inline.tpl'
)->withData([
	'title'=>'Scoped render',
])->content();
```

Context helpers:

- `withDevMode(bool $is_dev_mode): self`
- `withCacheDir(string $cache_dir): self`
- `withStrictMode(bool $strict_mode): self`
- `withAssetPolicy(array|AssetPolicy $asset_policy): self`
- `withBindingGuardrails(array|bool $binding_guardrails): self`
- `withGlobals(array $globals): self`
- `withGlobal(string $key, mixed $value): self`
- `withTemplateContract(string $template_file, array|TemplateContract $contract): self`
- `withComponentContract(string $reference, array|TemplateContract $contract): self`
- `template(string $template_file): TemplateView`
- `component(string $reference): TemplateView`
- `source(string $template, string $template_name='inline.tpl'): TemplateView`
- `binding(callable $resolver, ?string $name=null): CallableBinding`
- `cachedBinding(DataBinding|callable $binding, string|array|callable $identity, ?string $name=null): CachedBinding`
- `rememberBinding(DataBinding|callable $binding, string|array|callable|null $identity=null, int $ttl=300, array|string $names=[], ?string $name=null): RememberedBinding`
- `whenBinding(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): ConditionalBinding`
- `unlessBinding(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): ConditionalBinding`
- `queryBinding(object $query, string $mode='records', array $options=[]): SqlQueryBinding`
- `queryBindingInheritingIdentity(object $query, string $mode='records', array $options=[]): SqlQueryBinding`
- `searchBinding(object $query, string $mode='results', array $options=[]): SearchQueryBinding`
- `searchBindingInheritingIdentity(object $query, string $mode='results', array $options=[]): SearchQueryBinding`
- `clearBindingCache(string ...$names): int`
- `plan(string $template_file): TemplatePlan`
- `assetManifest(string $template_file): AssetManifest`
- `planString(string $template, string $template_name='inline.tpl'): TemplatePlan`
- `assetManifestString(string $template, string $template_name='inline.tpl'): AssetManifest`
- `render(...)`
- `inspect(...)`
- `renderString(...)`
- `inspectString(...)`

### View objects

`TemplateView` is the chainable app-facing object for one template or one inline source.

Main methods:

- `withData(array $data): self`
- `mergeData(array $data): self`
- `withProps(array $props): self`
- `mergeProps(array $props): self`
- `withBinding(string $path, DataBinding|callable $binding): self`
- `withBindings(array $bindings): self`
- `withQuery(string $path, object $query, string $mode='records', array $options=[]): self`
- `withQueryIdentity(string $path, object $query, string $mode='records', array $options=[]): self`
- `withSearch(string $path, object $query, string $mode='results', array $options=[]): self`
- `withSearchIdentity(string $path, object $query, string $mode='results', array $options=[]): self`
- `withBindingWhen(string $path, DataBinding|callable $binding, bool|callable $condition, mixed $default=null): self`
- `withBindingUnless(string $path, DataBinding|callable $binding, bool|callable $condition, mixed $default=null): self`
- `withQueryWhen(string $path, object $query, bool|callable $condition, string $mode='records', array $options=[], mixed $default=null): self`
- `withQueryUnless(string $path, object $query, bool|callable $condition, string $mode='records', array $options=[], mixed $default=null): self`
- `withSearchWhen(string $path, object $query, bool|callable $condition, string $mode='results', array $options=[], mixed $default=null): self`
- `withSearchUnless(string $path, object $query, bool|callable $condition, string $mode='results', array $options=[], mixed $default=null): self`
- `withThemeValues(array $theme_values): self`
- `mergeThemeValues(array $theme_values): self`
- `withSlots(array $slots): self`
- `slot(string $slot, mixed $content): self`
- `withFallback(string $template_file): self`
- `strict(bool $strict_mode=true): self`
- `withContract(array|TemplateContract $contract): self`
- `withComponentContract(array|TemplateContract $contract): self`
- `withAssetPolicy(array|AssetPolicy $asset_policy): self`
- `withBindingGuardrails(array|bool $binding_guardrails): self`
- `plan(): TemplatePlan`
- `assetManifest(): AssetManifest`
- `headHtml(): string`
- `bodyHtml(): string`
- `render(): RenderedTemplate`
- `inspect(): RenderedTemplate`
- `content(): string`
- `async(): object`

Binding inspection stays explicit. `RenderedTemplate::bindings()` and `TemplateManifest::bindings()` include the binding type, mode, target, duration, result type, skipped state, cacheability, cache identity, cache key, cache scope, cache state (`hit`, `miss`, `store`, `bypass`), cache names, cache TTL, reuse state, query fingerprint when the source query exposes one, query identity mode, actual query identity source, and any binding error. Each binding record also carries a normalized `trace` block, and `RenderedTemplate::bindingTrace()` plus `TemplateManifest::bindingTrace()` return that stitched trace list directly.

Guardrails are available through `binding_guardrails`, either on a context or a single view:

```php
$view=Templating::template('/var/www/app/views/orders.tpl')
	->withBindingGuardrails([
		'slow_ms'=>25,
		'warn_unused'=>true,
		'warn_duplicate_targets'=>true,
	]);
```

`RenderedTemplate::bindingWarnings()` and `TemplateManifest::bindingWarnings()` return warnings for slow bindings, duplicate SQL/search targets, and bindings whose paths are not referenced by the template plan. `RenderedTemplate::bindingPlanner()` and `TemplateManifest::bindingPlanner()` return higher-level suggestions, including when a SQL or search binding could inherit an explicit query fingerprint for stronger cache and reuse alignment. Per-render binding reuse is deterministic: bindings with the same explicit cache identity reuse the first resolved value inside that render. Persistent binding caches are file-backed inside the templating cache directory and only apply when a binding exposes an explicit persistent cache policy. When both the SQL and templating frameworks are loaded, SQL named cache invalidations automatically clear matching templating binding cache names too.

Official SQL and search bindings do not silently change identity mode. They expose the source query fingerprint in metadata, and you can opt into using it explicitly through `withQueryIdentity(...)`, `withSearchIdentity(...)`, or `Templating::queryBinding(...)->inheritIdentity()`. Binding metadata reports that through `query_fingerprint`, `query_identity_mode`, and `query_identity_source`, so reuse and cache alignment stay inspectable instead of implicit.

The normalized binding trace is meant to answer the practical execution questions quickly:

- what binding path executed
- which driver and target it used
- whether it hit render cache, persistent cache, or stored a fresh value
- which persistent binding cache names were used
- which SQL query cache names the binding depended on
- which SQL invalidation names can clear the binding cache automatically

Correlation is automatic for official SQL query bindings. During those binding resolutions, SQL traces inherit the same `render_trace_id` and `binding_trace_id`, so one render can be followed across templating inspection and SQL observability without guessing.

That unified runtime trace also summarizes canonical query fingerprints, so mixed SQL and search-backed renders can answer "which exact queries shaped this render?" from one place instead of by manually inspecting binding metadata.

### Binding constructs

Custom bindings can implement the plain contract or add metadata and cache identity explicitly:

```php
final class TenantMetricsBinding implements
	DataBinding,
	BindingMetadataProvider,
	BindingCacheIdentityProvider,
	BindingPersistentCacheProvider {

	public function name(): string {
		return 'tenant.metrics';
	}

	public function metadata(): array {
		return [
			'driver'=>'custom',
			'type'=>'metrics',
		];
	}

	public function cacheIdentity(BindingContext $context): mixed {
		return [
			'tenant_id'=>$context->get('tenant_id'),
		];
	}

	public function persistentCache(BindingContext $context): ?array {
		return [
			'ttl'=>60,
			'names'=>['tenant.metrics'],
		];
	}

	public function resolve(BindingContext $context): mixed {
		if($context->get('show_metrics')!==true){
			return BindingResolution::skipped([
				'count'=>0,
			]);
		}

		return BindingResolution::value([
			'count'=>42,
			'tenant'=>$context->get('tenant_id'),
		]);
	}
}

$view=Templating::template('/var/www/app/views/dashboard.tpl')
	->withData([
		'tenant_id'=>$tenant_id,
		'show_metrics'=>true,
	])
	->withBinding('metrics', new TenantMetricsBinding());
```

Binding wrappers compose cleanly:

```php
$base=Templating::binding(
	fn(BindingContext $context)=>order_metrics_for($context->get('tenant_id')),
	'orders.metrics'
);

$cached=Templating::cachedBinding(
	$base,
	fn(BindingContext $context)=>['tenant_id'=>$context->get('tenant_id')]
);

$remembered=Templating::rememberBinding(
	$cached,
	ttl: 120,
	names: ['orders.metrics']
);

$conditional=Templating::whenBinding(
	$remembered,
	fn(BindingContext $context)=>$context->get('show_metrics')===true,
	['count'=>0]
);
```

SQL and search bindings can be created directly and then attached to a view:

```php
$orders_binding=Templating::queryBinding(
	OrderRepository::query()->whereEq('tenant_id', $tenant_id)->latest('created_at'),
	'records',
	['binding_cache'=>60]
)->inheritIdentity();

$search_binding=Templating::searchBinding(
	Search::query('products')->where('title', $term),
	'results'
)->inheritIdentity();

$view=Templating::template('/var/www/app/views/search.tpl')
	->withBinding('orders', $orders_binding)
	->withBinding('results', $search_binding);
```

## Registration

Framework registration methods just delegate to the kernel:

```php
Templating::registerTag('currency', function(array $args, array $data){
	$minor_units=(int)($data[$args[0]] ?? 0);
	return '$'.number_format($minor_units/100, 2);
});

Templating::registerFilter('upper', fn($value)=>mb_strtoupper((string)$value));
Templating::registerExtension('badge', fn($label)=>"<span class='badge'>$label</span>");
Templating::registerHelper('truncate', function($text, $limit){
	$text=(string)$text;
	$limit=(int)$limit;
	return mb_strlen($text)>$limit ? mb_substr($text, 0, $limit).'...' : $text;
});
```

Event and hook registration:

```php
Templating::on('before_render', function(string $template_name, array $data){
	tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Rendering $template_name");
});

Templating::before(function(string $template, array $data){
	return str_replace('{{build}}', APP_VERSION, $template);
});

Templating::after(function(string $template, array $data){
	return str_replace('</body>', '<!-- rendered -->'."\n".'</body>', $template);
});
```

Contract helpers:

```php
Templating::setStrictMode(true);

Templating::registerContract(
	'/var/www/app/views/profile.tpl',
	TemplateContract::define(['user', 'tenant'])
		->optional('subtitle')
		->requiredSlots('actions')
		->allowAdditionalData(false)
);

$contract=Templating::contract('/var/www/app/views/profile.tpl');
Templating::clearContract('/var/www/app/views/profile.tpl');

Templating::registerComponentContract(
	'cards/status',
	TemplateContract::define()
		->requiredProp('title', 'string')
		->optionalProp('variant', 'string', 'neutral')
);

$component_contract=Templating::componentContract('cards/status');
Templating::clearComponentContract('cards/status');
```

Asset policy helpers:

```php
$policy=Templating::assetPolicy();

Templating::setAssetPolicy(
	$policy
		->scriptAsync()
		->styleMedia('screen')
);
```

## Globals

Global context is shared across renders unless a framework context overrides it temporarily.

```php
Templating::addGlobal('brand_name', 'Example App');

$state=Templating::state();
$globals=$state->globalContext();

Templating::clearGlobals();
```

`TemplatingState` gives a typed snapshot of the active framework state:

- `isDevMode(): bool`
- `cacheDir(): string`
- `globalContext(): array`
- `strictMode(): bool`
- `hasGlobal(string $key): bool`
- `global(string $key, mixed $default=null): mixed`
- `templateContracts(): array`
- `assetPolicy(): AssetPolicy`
- `hasTemplateContract(string $template_name): bool`
- `templateContract(string $template_name): ?TemplateContract`
- `toArray(): array`

Example:

```php
$state=Templating::state();

$is_dev_mode=$state->isDevMode();
$cache_dir=$state->cacheDir();
$tenant=$state->global('tenant', 'default');
$strict=$state->strictMode();
$policy=$state->assetPolicy();
$contract=$state->templateContract('/var/www/app/views/profile.tpl');
```

## Returned objects

`RenderedTemplate` gives a typed result instead of a bare string-only API:

- `content(): string`
- `templateName(): string`
- `data(): array`
- `themeValues(): array`
- `slots(): array`
- `isInline(): bool`
- `renderTraceId(): ?string`
- `hasManifest(): bool`
- `manifest(): ?TemplateManifest`
- `hasAssetManifest(): bool`
- `assetManifest(): AssetManifest`
- `headTags(): array`
- `bodyTags(): array`
- `headHtml(): string`
- `bodyHtml(): string`
- `assetHtml(): string`
- `hasBindings(): bool`
- `bindings(): array`
- `bindingTrace(): array`
- `bindingErrors(): array`
- `hasBindingErrors(): bool`
- `bindingWarnings(): array`
- `hasBindingWarnings(): bool`
- `bindingPlanner(): array`
- `hasBindingPlanner(): bool`
- `__toString(): string`

Example:

```php
$result=Templating::inspect('/var/www/app/views/orders.tpl', [
	'orders'=>$orders,
]);

echo $result->headHtml();
echo $result->content();
echo $result->bodyHtml();

$trace_id=$result->renderTraceId();
$bindings=$result->bindings();
$binding_trace=$result->bindingTrace();
$binding_warnings=$result->bindingWarnings();
$binding_planner=$result->bindingPlanner();
```

`BindingContext` is the typed execution context for lazy bindings:

- `templateName(): string`
- `isInline(): bool`
- `data(): array`
- `themeValues(): array`
- `slots(): array`
- `overrides(): array`
- `has(string $path): bool`
- `get(string $path, mixed $default=null): mixed`
- `themeValue(string $path, mixed $default=null): mixed`
- `slot(string $name, mixed $default=null): mixed`

Example:

```php
$binding=Templating::binding(function(BindingContext $context){
	return [
		'tenant'=>$context->get('tenant.name'),
		'theme'=>$context->themeValue('accent', '#000'),
		'footer'=>$context->slot('footer', ''),
		'render_trace_id'=>$context->renderTraceId(),
	];
}, 'tenant.snapshot');
```

`DataBinding` is the binding contract:

- `name(): string`
- `resolve(BindingContext $context): mixed`

`CallableBinding` wraps a plain callable as a `DataBinding`:

- `make(callable $resolver, ?string $name=null): self`
- `name(): string`
- `resolve(BindingContext $context): mixed`

Example:

```php
$binding=CallableBinding::make(
	fn(BindingContext $context)=>customer_summary($context->get('customer_id')),
	'customer.summary'
);
```

`TemplateContract` gives a typed way to define expectations:

- `define(array $required=[], array $optional=[]): self`
- `fromArray(array $definition): self`
- `required(string ...$keys): self`
- `optional(string ...$keys): self`
- `requiredProp(string $key, ?string $type=null, mixed $default=...): self`
- `optionalProp(string $key, ?string $type=null, mixed $default=...): self`
- `requiredSlots(string ...$slots): self`
- `optionalSlots(string ...$slots): self`
- `defaults(array $defaults): self`
- `defaultValue(string $key, mixed $value): self`
- `propType(string $key, string $type): self`
- `propTypes(array $types): self`
- `allowAdditionalData(bool $allow=true): self`
- `allowAdditionalSlots(bool $allow=true): self`
- `toArray(): array`

Example:

```php
$contract=TemplateContract::define(['title'])
	->optional('subtitle')
	->requiredSlots('content')
	->requiredProp('title', 'string')
	->optionalProp('variant', 'string', 'neutral')
	->defaultValue('subtitle', 'Overview')
	->allowAdditionalData(false);
```

`TemplateManifest` gives an optional inspection view over the render:

- `templateName(): string`
- `isInline(): bool`
- `cacheStrategy(): string`
- `cacheUsed(): bool`
- `strictMode(): bool`
- `assetPolicy(): AssetPolicy`
- `failed(): bool`
- `failureMessage(): ?string`
- `dataKeys(): array`
- `themeValueKeys(): array`
- `slotNames(): array`
- `templates(): array`
- `partials(): array`
- `components(): array`
- `imports(): array`
- `layouts(): array`
- `assets(): array`
- `dependencies(): array`
- `translations(): array`
- `undefinedVariables(): array`
- `missingReferences(): array`
- `tags(): array`
- `filters(): array`
- `helpers(): array`
- `extensions(): array`
- `bindings(): array`
- `bindingTrace(): array`
- `bindingErrors(): array`
- `bindingWarnings(): array`
- `bindingPlanner(): array`
- `contracts(): array`
- `contractViolations(): array`
- `errors(): array`
- `hasBindingErrors(): bool`
- `hasBindingWarnings(): bool`
- `hasBindingPlanner(): bool`
- `hasContractViolations(): bool`
- `renderTraceId(): ?string`
- `durationMs(): float`
- `summary(): array`
- `toArray(): array`

Example:

```php
$manifest=$result->manifest();

$templates=$manifest?->templates();
$components=$manifest?->components();
$missing=$manifest?->missingReferences();
$binding_trace=$manifest?->bindingTrace();
$binding_planner=$manifest?->bindingPlanner();
$summary=$manifest?->summary();
```

`TemplatePlan` gives a compiled static view over one template:

- `templateName(): string`
- `isInline(): bool`
- `cacheMode(): string`
- `sourceHash(): string`
- `graph(): array`
- `graphNodes(): array`
- `graphEdges(): array`
- `allTemplates(): array`
- `unresolvedReferences(): array`
- `aggregate(): array`
- `assetManifest(): AssetManifest`
- `dataPaths(): array`
- `topLevelDataKeys(): array`
- `slotNames(): array`
- `partials(): array`
- `components(): array`
- `imports(): array`
- `layouts(): array`
- `assets(): array`
- `dependencies(): array`
- `translations(): array`
- `tags(): array`
- `filters(): array`
- `helpers(): array`
- `extensions(): array`
- `features(): array`
- `suggestedContract(): TemplateContract`
- `summary(): array`
- `toArray(): array`

Example:

```php
$plan=Templating::plan('/var/www/app/views/orders.tpl');

$graph=$plan->graph();
$aggregate=$plan->aggregate();
$paths=$plan->dataPaths();
$assets=$plan->assetManifest();
$suggested_contract=$plan->suggestedContract();
```

`AssetPolicy` gives a typed asset delivery policy:

- `defaults(): self`
- `fromArray(array $definition): self`
- `preload(string ...$types): self`
- `withoutPreload(string ...$types): self`
- `scriptStrategy(string $strategy): self`
- `scriptBlocking(): self`
- `scriptDefer(): self`
- `scriptAsync(): self`
- `scriptType(string $type): self`
- `autoScriptType(): self`
- `moduleScripts(): self`
- `classicScripts(): self`
- `styleMedia(string $media): self`
- `fontCrossorigin(?string $value='anonymous'): self`
- `summary(): array`
- `toArray(): array`

Example:

```php
$policy=AssetPolicy::defaults()
	->preload('styles', 'scripts')
	->moduleScripts()
	->styleMedia('screen')
	->fontCrossorigin('anonymous');
```

`AssetManifest` gives a deduped page-level asset bundle derived from the transitive template graph:

- `items(): array`
- `stylesheets(): array`
- `scripts(): array`
- `images(): array`
- `fonts(): array`
- `preloads(): array`
- `headItems(): array`
- `bodyItems(): array`
- `missing(): array`
- `stylesheetTags(): array`
- `scriptTags(): array`
- `preloadTags(): array`
- `headTags(): array`
- `bodyTags(): array`
- `allTags(): array`
- `headHtml(): string`
- `bodyHtml(): string`
- `html(): string`
- `policy(): AssetPolicy`
- `signature(): string`
- `hasMissingAssets(): bool`
- `summary(): array`
- `toArray(): array`

Example:

```php
$asset_manifest=Templating::template('/var/www/app/views/home.tpl')
	->withAssetPolicy($policy)
	->assetManifest();

$head_tags=$asset_manifest->headTags();
$body_tags=$asset_manifest->bodyTags();
$signature=$asset_manifest->signature();
$missing_assets=$asset_manifest->missing();
```

## Notes

- `render()` only reuses file-cache output for static file renders with no explicit data, theme values, slots, or global context.
- `plan()` compiles a static template plan and caches file-template plans by template mtime.
- `plan()` walks the transitive partial/component/import/layout graph and returns both direct metadata and an aggregated graph view.
- `assetManifest()` turns that transitive graph into a deduped asset bundle with stylesheet, script, and preload tags, plus ready-to-insert head/body HTML.
- Asset tags and manifest bundles respect the active asset policy for preload toggles, script strategy, script type, style media, and font crossorigin handling.
- `RenderedTemplate` carries the same asset bundle, so application code can render the view content and asset blocks from one typed object.
- View bindings resolve explicitly before render, and `inspect()` records which bindings ran, how long they took, and which ones failed.
- The built-in `money` helper and filter understand Dataphyre `Money` objects directly and can also format decimal display-boundary values.
- Static file-render caching includes the active asset policy and helper/filter/tag/extension registry signature, so asset-policy changes do not leave stale cached HTML behind.
- `strict_mode` turns missing references, undefined variables, render errors, and contract violations into an error-template path instead of a silent partial render.
- `strict_mode` also bypasses static output caching so the actual render graph is validated.
- Template contracts support required data keys, optional data keys, required slots, optional slots, and optional rejection of unexpected data or slots.
- Template contracts also support prop defaults and prop types, which makes component-style views much more predictable.
- Component entries in both plans and inspection manifests include a contract summary when one is registered for that component.
- `inspect()` and `inspectString()` intentionally bypass static output caching so the returned manifest reflects the actual render graph.
- `render_string()` is the framework-safe path for inline template content.
- Partials, layouts, dynamic imports, and components resolve relative to the rendering template file before falling back to legacy root-relative lookup.
- The framework layer adds no kernel overhead unless it is explicitly loaded.
- The parser is Dataphyre's existing parser. The framework API improves the application-facing surface without introducing a second rendering engine.
