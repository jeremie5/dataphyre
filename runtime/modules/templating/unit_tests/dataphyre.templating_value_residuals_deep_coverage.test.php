<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Templating\AssetManifest;
use Dataphyre\Templating\AssetPolicy;
use Dataphyre\Templating\BindingCacheIdentityProvider;
use Dataphyre\Templating\BindingContext;
use Dataphyre\Templating\BindingMetadataProvider;
use Dataphyre\Templating\BindingPersistentCacheProvider;
use Dataphyre\Templating\CachedBinding;
use Dataphyre\Templating\DataBinding;
use Dataphyre\Templating\RememberedBinding;
use Dataphyre\Templating\RenderedTemplate;
use Dataphyre\Templating\TemplateContract;
use Dataphyre\Templating\TemplateManifest;
use Dataphyre\Templating\TemplatePlan;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['core', 'templating']);

final class DpTemplatingWaveBareBinding implements DataBinding {
	public function __construct(private string $bindingName='bare', private mixed $value='bare-value') {}
	public function name(): string { return $this->bindingName; }
	public function resolve(BindingContext $context): mixed { return $this->value; }
}

final class DpTemplatingWaveFullBinding implements BindingMetadataProvider, BindingCacheIdentityProvider, BindingPersistentCacheProvider {
	public function name(): string { return 'full'; }
	public function metadata(): array { return ['source'=>'full-binding']; }
	public function cacheIdentity(BindingContext $context): mixed { return ['delegated'=>$context->templateName()]; }
	public function persistentCache(BindingContext $context): ?array { return ['ttl'=>30, 'names'=>['full']]; }
	public function resolve(BindingContext $context): mixed { return 'full-value'; }
}

final class DpTemplatingWaveIdentityFactory {
	public static function zero(): string { return 'static-zero'; }
	public static function one(BindingContext $context): array { return ['template'=>$context->templateName()]; }
	public function method(BindingContext $context): int { return strlen($context->templateName()); }
	public function __invoke(): bool { return true; }
}

test('templating template contract and asset policy normalize builders caches aliases and disabled CORS', static function(Context $t): void {
	$raw=[
		'required'=>[' name ', '', 'name', 12],
		'optional'=>'invalid',
		'slots'=>[' body ', 'body', null],
		'optional_slots'=>['aside'],
		'defaults'=>[' title '=>'Untitled', ''=>'ignored', 2=>'ignored'],
		'types'=>[' title '=>' STRING ', 'blank'=>' ', 2=>'string', 'bad'=>[]],
		'allow_additional_data'=>false,
		'allow_additional_slots'=>false,
	];
	$contract=TemplateContract::fromArray($raw);
	$t->same($contract->toArray(), TemplateContract::fromArray($raw)->toArray());
	TemplateContract::fromArray(['required'=>['other']]);
	$t->same($contract->toArray(), TemplateContract::fromArray($raw)->toArray());
	$built=TemplateContract::define(['id'], ['subtitle'])
		->required('name', ' ', 'id')
		->optional('note', 'subtitle')
		->requiredProp('price', ' FLOAT ', 0)
		->requiredProp('plain')
		->optionalProp('description', ' STRING ', null)
		->optionalProp('untyped')
		->requiredSlots('body', 'body')
		->optionalSlots('aside', 'footer')
		->allowAdditionalData(false)
		->allowAdditionalSlots(false)
		->defaults(['note'=>'n/a', '   '=>'ignored', 1=>'ignored'])
		->defaultValue(' status ', 'draft')
		->defaultValue(' ', 'ignored')
		->propType('count', ' INTEGER ')
		->propType('', 'string')
		->propType('invalid', ' ')
		->propTypes(['active'=>' BOOLEAN ', 'blank'=>' ', 2=>'string', 'bad'=>[]]);
	$t->contains('price', $built->toArray()['required']);
	$t->same(0, $built->toArray()['defaults']['price']);
	$t->same(null, $built->toArray()['defaults']['description']);
	$t->same('integer', $built->toArray()['prop_types']['count']);
	$t->isFalse($built->toArray()['allow_additional_data']);

	$policyRaw=[
		'preload'=>['style'=>false, 'script'=>true, 'image'=>'invalid', 'font'=>false],
		'scripts'=>['strategy'=>' DEFER ', 'type'=>' MODULE '],
		'styles'=>['media'=>' print '],
		'fonts'=>['crossorigin'=>'use-credentials'],
	];
	$policy=AssetPolicy::fromArray($policyRaw);
	$t->same($policy->toArray(), AssetPolicy::fromArray($policyRaw)->toArray());
	AssetPolicy::fromArray(['scripts'=>['strategy'=>'async']]);
	$t->same($policy->toArray(), AssetPolicy::fromArray($policyRaw)->toArray());
	$custom=AssetPolicy::defaults()
		->withoutPreload('css', 'js', 'img', 'font', 'unknown')
		->preload('style', 'script', 'image', 'fonts', 'unknown')
		->scriptBlocking()
		->scriptDefer()
		->scriptAsync()
		->scriptStrategy('unknown')
		->autoScriptType()
		->moduleScripts()
		->classicScripts()
		->scriptType('unknown')
		->styleMedia(' ')
		->styleMedia('screen')
		->fontCrossorigin('use-credentials')
		->fontCrossorigin('none');
	$t->same(null, $custom->toArray()['fonts']['crossorigin']);
	$t->same(null, $custom->summary()['font_crossorigin']);
	$t->same('blocking', $custom->summary()['script_strategy']);
	$t->same('auto', $custom->summary()['script_type']);
	$t->same('screen', $custom->summary()['style_media']);
	$t->same(null, AssetPolicy::fromArray(['fonts'=>['crossorigin'=>false]])->toArray()['fonts']['crossorigin']);
	$t->same('anonymous', AssetPolicy::fromArray(['fonts'=>'invalid'])->toArray()['fonts']['crossorigin']);
})->tag('templating', 'coverage', 'templating-residual-wave')->group('framework-coverage');

test('templating asset manifest and template plan expose complete typed read models and malformed fallbacks', static function(Context $t): void {
	$manifestPayload=[
		'items'=>['a'], 'stylesheets'=>['style.css'], 'scripts'=>['app.js'], 'images'=>['hero.png'],
		'fonts'=>['font.woff2'], 'preloads'=>['style.css'], 'head_items'=>['style.css'], 'body_items'=>['app.js'],
		'missing'=>['missing.js'], 'stylesheet_tags'=>['<link>'], 'script_tags'=>['<script>'], 'preload_tags'=>['<preload>'],
		'head_tags'=>['<link>', '<preload>'], 'body_tags'=>['<script>'], 'all_tags'=>['<link>', '<script>'],
		'policy'=>['scripts'=>['strategy'=>'defer']], 'signature'=>'asset-signature',
	];
	$manifest=AssetManifest::fromArray($manifestPayload);
	foreach([
		'items'=>'a', 'stylesheets'=>'style.css', 'scripts'=>'app.js', 'images'=>'hero.png', 'fonts'=>'font.woff2',
		'preloads'=>'style.css', 'headItems'=>'style.css', 'bodyItems'=>'app.js', 'missing'=>'missing.js',
		'stylesheetTags'=>'<link>', 'scriptTags'=>'<script>', 'preloadTags'=>'<preload>', 'headTags'=>'<link>',
		'bodyTags'=>'<script>', 'allTags'=>'<link>',
	] as $method=>$first){
		$t->same($first, $manifest->{$method}()[0]);
	}
	$t->same("<link>\n<preload>", $manifest->headHtml());
	$t->same('<script>', $manifest->bodyHtml());
	$t->same("<link>\n<script>", $manifest->html());
	$t->same('defer', $manifest->policy()->summary()['script_strategy']);
	$t->same('asset-signature', $manifest->signature());
	$t->isTrue($manifest->hasMissingAssets());
	$t->same(1, $manifest->summary()['missing_count']);
	$t->producesStableResult(static fn()=>$manifest->summary());
	$t->same($manifestPayload, $manifest->toArray());
	$explicit=AssetManifest::fromArray(['head_html'=>'HEAD', 'body_html'=>'BODY', 'html'=>'ALL', 'missing'=>'invalid']);
	$t->same('HEAD', $explicit->headHtml());
	$t->same('BODY', $explicit->bodyHtml());
	$t->same('ALL', $explicit->html());
	$t->isFalse($explicit->hasMissingAssets());
	$t->same([], AssetManifest::fromArray(['items'=>'invalid'])->items());
	$t->same(0, AssetManifest::fromArray(['items'=>'invalid', 'policy'=>'invalid'])->summary()['item_count']);

	$listKeys=['all_templates','unresolved_references','data_paths','top_level_data_keys','slot_names','partials','components','imports','layouts','assets','dependencies','translations','tags','filters','helpers','extensions','features'];
	$planPayload=[
		'template_name'=>'page.tpl', 'inline'=>true, 'cache_mode'=>'disk', 'source_hash'=>'hash',
		'graph'=>['nodes'=>['root'], 'edges'=>['include']],
		'aggregate'=>['data_paths'=>['user.name'], 'slot_names'=>['body'], 'partials'=>['header'], 'components'=>['card'], 'imports'=>['macros'], 'layouts'=>['base'], 'assets'=>['a.css'], 'dependencies'=>['a.js']],
		'asset_manifest'=>$manifestPayload,
		'suggested_contract'=>['required'=>['user']],
	];
	foreach($listKeys as $key){
		$planPayload[$key]=[$key.'.value'];
	}
	$plan=TemplatePlan::fromArray($planPayload);
	$t->same('page.tpl', $plan->templateName());
	$t->isTrue($plan->isInline());
	$t->same('disk', $plan->cacheMode());
	$t->same('hash', $plan->sourceHash());
	$t->same(['root'], $plan->graphNodes());
	$t->same(['include'], $plan->graphEdges());
	foreach([
		'allTemplates'=>'all_templates', 'unresolvedReferences'=>'unresolved_references', 'dataPaths'=>'data_paths',
		'topLevelDataKeys'=>'top_level_data_keys', 'slotNames'=>'slot_names', 'partials'=>'partials',
		'components'=>'components', 'imports'=>'imports', 'layouts'=>'layouts', 'assets'=>'assets',
		'dependencies'=>'dependencies', 'translations'=>'translations', 'tags'=>'tags', 'filters'=>'filters',
		'helpers'=>'helpers', 'extensions'=>'extensions', 'features'=>'features',
	] as $method=>$key){
		$t->same($key.'.value', $plan->{$method}()[0]);
	}
	$t->same('asset-signature', $plan->assetManifest()->signature());
	$t->producesStableResult(static fn()=>$plan->assetManifest());
	$t->contains('user', $plan->suggestedContract()->toArray()['required']);
	$t->producesStableResult(static fn()=>$plan->suggestedContract());
	$t->same(1, $plan->summary()['component_count']);
	$t->producesStableResult(static fn()=>$plan->summary());
	$t->same($planPayload, $plan->toArray());
	$malformed=TemplatePlan::fromArray([
		'graph'=>'invalid', 'aggregate'=>'invalid', 'all_templates'=>'invalid', 'unresolved_references'=>'invalid',
		'asset_manifest'=>'invalid', 'suggested_contract'=>'invalid', 'data_paths'=>'invalid',
	]);
	$t->same('template.tpl', $malformed->templateName());
	$t->isFalse($malformed->isInline());
	$t->same('memory', $malformed->cacheMode());
	$t->same([], $malformed->graph());
	$t->same([], $malformed->graphNodes());
	$t->same([], $malformed->graphEdges());
	$t->same(0, $malformed->summary()['template_count']);
})->tag('templating', 'coverage', 'templating-residual-wave')->group('framework-coverage');

test('templating binding context covers accessors trace cloning and array object dotted paths', static function(Context $t): void {
	$object=(object)['profile'=>(object)['name'=>'Ada'], 'nullable'=>null];
	$context=new BindingContext(
		' page.tpl ', true,
		['simple'=>'literal', 'user'=>['name'=>'Grace', 'null'=>null], 'object'=>$object],
		['simple'=>'theme-simple', 'palette'=>['primary'=>'blue']], ['body'=>null], ['override'=>true],
		['render_trace_id'=>' render-1 ', 'binding_trace_id'=>' binding-1 ']
	);
	$t->same(' page.tpl ', $context->templateName());
	$t->isTrue($context->isInline());
	$t->same('Grace', $context->data()['user']['name']);
	$t->same('blue', $context->themeValues()['palette']['primary']);
	$t->same(null, $context->slots()['body']);
	$t->isTrue($context->overrides()['override']);
	$t->same('render-1', $context->renderTraceId());
	$t->same('binding-1', $context->bindingTraceId());
	$t->same('literal', $context->get('simple'));
	$t->same('Grace', $context->get('user.name'));
	$t->same(null, $context->get('user.null', 'fallback'));
	$t->same('Ada', $context->get('object.profile.name'));
	$t->same('fallback', $context->get('object.missing', 'fallback'));
	$t->same('fallback', $context->get('', 'fallback'));
	$t->isTrue($context->has('simple'));
	$t->isTrue($context->has('object.nullable'));
	$t->isFalse($context->has('object.missing'));
	$t->isFalse($context->has(' '));
	$t->same('blue', $context->themeValue('palette.primary'));
	$t->same('theme-simple', $context->themeValue('simple'));
	$t->same('fallback', $context->themeValue('missing', 'fallback'));
	$t->same('fallback', $context->themeValue('palette.missing', 'fallback'));
	$t->same(null, $context->slot('body', 'fallback'));
	$t->same('fallback', $context->slot('missing', 'fallback'));
	$traced=$context->withTraceContext(['binding_trace_id'=>'binding-2', 'span'=>'child']);
	$t->same('binding-2', $traced->bindingTraceId());
	$t->same('child', $traced->traceContext()['span']);
	$invalid=new BindingContext('invalid', false, traceContext:['render_trace_id'=>[], 'binding_trace_id'=>' ']);
	$t->same(null, $invalid->renderTraceId());
	$t->same(null, $invalid->bindingTraceId());
})->tag('templating', 'coverage', 'templating-residual-wave')->group('framework-coverage');

test('templating cached and remembered bindings cover metadata identity arity callable shapes and persistence', static function(Context $t): void {
	$context=new BindingContext('binding.tpl', false);
	$bare=new DpTemplatingWaveBareBinding();
	$full=new DpTemplatingWaveFullBinding();
	$t->same('bare', CachedBinding::make($bare, 'key')->name());
	$t->same('named', CachedBinding::make(static fn(): string=>'callable', 'key', ' named ')->name());
	$t->same(['key'=>'cache-key'], CachedBinding::make($bare, ' cache-key ')->cacheIdentity($context));
	$t->same(null, CachedBinding::make($bare, ' ')->cacheIdentity($context));
	$t->same(['tenant'=>7], CachedBinding::make($bare, ['tenant'=>7])->cacheIdentity($context));
	$t->same(['value'=>42], CachedBinding::make($bare, static fn(): int=>42)->cacheIdentity($context));
	$t->same(['template'=>'binding.tpl'], CachedBinding::make($bare, DpTemplatingWaveIdentityFactory::class.'::one')->cacheIdentity($context));
	$factory=new DpTemplatingWaveIdentityFactory();
	$t->same(['value'=>11], CachedBinding::make($bare, [$factory, 'method'])->cacheIdentity($context));
	$t->same(['value'=>true], CachedBinding::make($bare, $factory)->cacheIdentity($context));
	$t->same(null, CachedBinding::make($bare, static fn(): object=>new stdClass())->cacheIdentity($context));
	$t->same('bare-value', CachedBinding::make($bare, 'key')->resolve($context));
	$t->same(null, CachedBinding::make($bare, 'key')->persistentCache($context));
	$cachedFull=CachedBinding::make($full, ['id'=>1]);
	$t->same('full-binding', $cachedFull->metadata()['source']);
	$t->same('array', $cachedFull->metadata()['cache_identity_mode']);
	$t->same(30, $cachedFull->persistentCache($context)['ttl']);
	$t->same('callable', CachedBinding::make($bare, static fn(): string=>'x')->metadata()['cache_identity_mode']);

	$remembered=RememberedBinding::make($full, null, 0, [' group ', '', 'group', 3], ' remembered ');
	$t->same('remembered', $remembered->name());
	$t->same(1, $remembered->metadata()['persistent_cache_ttl']);
	$t->same(['group'], $remembered->metadata()['persistent_cache_names']);
	$t->same(['delegated'=>'binding.tpl'], $remembered->cacheIdentity($context));
	$t->same(null, $remembered->persistentCache($context)['identity']);
	$t->same('full-value', $remembered->resolve($context));
	$t->same(null, RememberedBinding::make($bare)->cacheIdentity($context));
	$t->same(['key'=>'remember-key'], RememberedBinding::make($bare, ' remember-key ', 5, 'tag')->cacheIdentity($context));
	$t->same(null, RememberedBinding::make($bare, ' ')->cacheIdentity($context));
	$t->same(['id'=>9], RememberedBinding::make($bare, ['id'=>9])->cacheIdentity($context));
	$t->same(['value'=>7], RememberedBinding::make($bare, static fn(): int=>7)->cacheIdentity($context));
	$t->same(['template'=>'binding.tpl'], RememberedBinding::make($bare, DpTemplatingWaveIdentityFactory::class.'::one')->cacheIdentity($context));
	$t->same(['value'=>11], RememberedBinding::make($bare, [$factory, 'method'])->cacheIdentity($context));
	$t->same(['value'=>true], RememberedBinding::make($bare, $factory)->cacheIdentity($context));
	$t->same(null, RememberedBinding::make($bare, static fn(): object=>new stdClass())->cacheIdentity($context));
	$t->same('callable', RememberedBinding::make($bare, static fn(): string=>'id')->metadata()['cache_identity_mode']);
	$t->same('array', RememberedBinding::make($bare, ['id'=>1])->metadata()['cache_identity_mode']);
	$t->same('string', RememberedBinding::make($bare, 'id')->metadata()['cache_identity_mode']);
})->tag('templating', 'coverage', 'templating-residual-wave')->group('framework-coverage');

test('templating rendered template exposes assets manifests traces errors warnings planner and string casting', static function(Context $t): void {
	$assets=AssetManifest::fromArray([
		'head_tags'=>['<link>'], 'body_tags'=>['<script>'], 'head_html'=>'HEAD', 'body_html'=>'BODY', 'html'=>'ALL',
	]);
	$bindings=[
		['path'=>'ok', 'ok'=>true, 'trace'=>['id'=>'trace-ok']],
		['path'=>'bad', 'ok'=>false, 'trace'=>['id'=>'trace-bad']],
		['path'=>'empty', 'trace'=>[]],
	];
	$rendered=new RenderedTemplate(
		'<main>Ready</main>', 'page.tpl', ['name'=>'Ada'], ['tone'=>'warm'], ['body'=>'Body'], true,
		' render-explicit ', null, $assets, $bindings, [['message'=>'slow']], ['steps'=>['bindings']]
	);
	$t->same('<main>Ready</main>', $rendered->content());
	$t->same('page.tpl', $rendered->templateName());
	$t->same('Ada', $rendered->data()['name']);
	$t->same('warm', $rendered->themeValues()['tone']);
	$t->same('Body', $rendered->slots()['body']);
	$t->isTrue($rendered->isInline());
	$t->same(' render-explicit ', $rendered->renderTraceId());
	$t->isFalse($rendered->hasManifest());
	$t->same(null, $rendered->manifest());
	$t->isTrue($rendered->hasAssetManifest());
	$t->same(['<link>'], $rendered->headTags());
	$t->same(['<script>'], $rendered->bodyTags());
	$t->same('HEAD', $rendered->headHtml());
	$t->same('BODY', $rendered->bodyHtml());
	$t->same('ALL', $rendered->assetHtml());
	$t->isTrue($rendered->hasBindings());
	$t->same($bindings, $rendered->bindings());
	$t->same(2, count($rendered->bindingTrace()));
	$t->producesStableResult(static fn()=>$rendered->bindingTrace());
	$t->same('bad', $rendered->bindingErrors()[0]['path']);
	$t->same('bad', $rendered->bindingErrors()[0]['path']);
	$t->isTrue($rendered->hasBindingErrors());
	$t->isTrue($rendered->hasBindingWarnings());
	$t->same('slow', $rendered->bindingWarnings()[0]['message']);
	$t->isTrue($rendered->hasBindingPlanner());
	$t->same(['bindings'], $rendered->bindingPlanner()['steps']);
	$t->same('<main>Ready</main>', (string)$rendered);

	$manifest=TemplateManifest::fromArray([
		'render_trace_id'=>'manifest-trace',
		'binding_trace'=>[['id'=>'manifest-binding']],
		'binding_planner'=>['manifest'=>true],
	]);
	$manifestRendered=new RenderedTemplate('manifest', 'manifest.tpl', manifest:$manifest);
	$t->same('manifest-trace', $manifestRendered->renderTraceId());
	$t->isTrue($manifestRendered->hasManifest());
	$t->same([['id'=>'manifest-binding']], $manifestRendered->bindingTrace());
	$t->isTrue($manifestRendered->bindingPlanner()['manifest']);
	$t->isFalse($manifestRendered->hasAssetManifest());
	$t->same([], $manifestRendered->assetManifest()->items());

	$errorFirst=new RenderedTemplate('error', 'error.tpl', bindings:[['ok'=>false]]);
	$t->isTrue($errorFirst->hasBindingErrors());
	$t->isTrue($errorFirst->hasBindingErrors());
	$clean=new RenderedTemplate('clean', 'clean.tpl', bindings:[['ok'=>true]]);
	$t->isFalse($clean->hasBindingErrors());
	$t->isFalse($clean->hasBindingErrors());
	$t->same([], $clean->bindingErrors());
	$t->isFalse($clean->hasBindingWarnings());
	$t->isFalse($clean->hasBindingPlanner());
})->tag('templating', 'coverage', 'templating-residual-wave')->group('framework-coverage');
