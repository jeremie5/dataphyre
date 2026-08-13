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
use Dataphyre\Templating\CachedBinding;
use Dataphyre\Templating\RememberedBinding;
use Dataphyre\Templating\RenderedTemplate;
use Dataphyre\Templating\SearchQueryBinding;
use Dataphyre\Templating\SqlQueryBinding;
use Dataphyre\Templating\TemplateContract;
use Dataphyre\Templating\TemplatePlan;
use Dataphyre\Templating\TemplateView;
use Dataphyre\Templating\TemplatingContext;
use Dataphyre\Templating\TemplatingManager;
use Dataphyre\Test\Context;
use Dataphyre\Test\TempWorkspace;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'templating'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_templating_kernel_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_templating_kernel_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_templating_kernel_modules_root);
\dataphyre\autoloader::register_framework_modules(['core', 'templating']);
require_once $dp_templating_kernel_modules_root.'/templating/unit_tests/templating_render_test_helpers.php';

require_once __DIR__.'/fixtures/templating_optional_dependency_probes.php';

final class DpTemplatingKernelProps {
	public function __construct(private array $values) {}
	public function toArray(): array { return $this->values; }
}

/** @return array{0:TempWorkspace,1:string} */
function dp_templating_kernel_wave_init(Context $t, bool $dev=false, bool $strict=false): array {
	$workspace=$t->workspace('templating-kernel-wave');
	$cache=rtrim($workspace->directory('cache'), '/\\').DIRECTORY_SEPARATOR;
	\dataphyre\templating::init($dev, $cache, $strict, ['scripts'=>['strategy'=>'defer']]);
	TemplatingManager::flush();
	return [$workspace,$cache];
}

test('templating context covers immutable overrides contracts views bindings rendering planning async and cache clearing', static function(Context $t): void {
	[$workspace,$cache]=dp_templating_kernel_wave_init($t);
	$template=$workspace->file('cache/page.tpl','<main>{{name}} {{tone}}</main>');
	$component=$workspace->file('cache/component.tpl','<strong>{{label}}</strong>');
	$manager=TemplatingManager::instance();
	$context=$manager->context()
		->withDevMode(false)
		->withCacheDir($cache)
		->withStrictMode(false)
		->withAssetPolicy(AssetPolicy::defaults()->scriptDefer())
		->withAssetPolicy(['styles'=>['media'=>'screen']])
		->withBindingGuardrails(true)
		->withBindingGuardrails(['max_depth'=>4])
		->withGlobals(['base'=>'global'])
		->withGlobals(['second'=>'value'])
		->withGlobal('third', 3)
		->withTemplateContract($template, TemplateContract::define(['name']))
		->withTemplateContract('logical.tpl', ['optional'=>['note']]);
	$t->instanceOf(TemplatingContext::class, $context);
	$t->isFalse($context->state()->isDevMode());
	$t->same($cache, $context->state()->cacheDir());
	$t->same(3, $context->state()->global('third'));
	$componentContext=$context->withComponentContract($component, TemplateContract::define(['label']));
	$t->instanceOf(TemplatingContext::class, $componentContext);
	$t->isTrue($componentContext->state()->hasTemplateContract($component));
	$t->throws(static fn()=>$context->withComponentContract('missing-component-wave', []), RuntimeException::class);
	$t->instanceOf(TemplateView::class, $context->template($template));
	$t->instanceOf(TemplateView::class, $context->component($component));
	$t->instanceOf(TemplateView::class, $context->source('Hello {{name}}', 'source.tpl'));

	$binding=$context->binding(static fn(): string=>'bound', 'bound');
	$t->same('bound', $binding->name());
	$t->instanceOf(CachedBinding::class, $context->cachedBinding($binding, 'identity'));
	$t->instanceOf(RememberedBinding::class, $context->rememberBinding($binding, 'identity', 10, 'wave'));
	$t->same('default', $context->whenBinding(static fn(): string=>'value', false, 'default')->resolve(new \Dataphyre\Templating\BindingContext('x', false))->result());
	$t->same('default', $context->unlessBinding(static fn(): string=>'value', true, 'default')->resolve(new \Dataphyre\Templating\BindingContext('x', false))->result());
	$sqlQuery=new \Dataphyre\Database\RepositoryQuery();
	$searchQuery=new \Dataphyre\FulltextEngine\Query();
	$t->instanceOf(SqlQueryBinding::class, $context->queryBinding($sqlQuery));
	$t->instanceOf(SqlQueryBinding::class, $context->queryBindingInheritingIdentity($sqlQuery, 'first'));
	$t->instanceOf(SearchQueryBinding::class, $context->searchBinding($searchQuery));
	$t->instanceOf(SearchQueryBinding::class, $context->searchBindingInheritingIdentity($searchQuery, 'first'));

	$t->instanceOf(RenderedTemplate::class, $context->render($template, ['name'=>'Ada'], ['tone'=>'warm']));
	$t->instanceOf(TemplatePlan::class, $context->plan($template));
	$t->instanceOf(AssetManifest::class, $context->assetManifest($template));
	$t->instanceOf(RenderedTemplate::class, $context->inspect($template, ['name'=>'Grace']));
	$t->instanceOf(RenderedTemplate::class, $context->renderString('Hi {{name}}', ['name'=>'Lin'], templateName:'render-string.tpl'));
	$t->instanceOf(RenderedTemplate::class, $context->inspectString('Hi {{name}}', ['name'=>'Edsger'], templateName:'inspect-string.tpl'));
	$t->instanceOf(TemplatePlan::class, $context->planString('Hi {{name}}', 'plan-string.tpl'));
	$t->instanceOf(AssetManifest::class, $context->assetManifestString('Hi {{name}}', 'assets-string.tpl'));
	$t->isTrue(is_object($context->asyncRender($template, ['name'=>'Async'])));
	$t->isTrue($context->clearBindingCache('wave')>=0);

})->tag('templating', 'coverage', 'templating-residual-wave')->group('framework-coverage');

test('templating kernel caching covers compiled fragment legacy expiry corruption and plan cache states', static function(Context $t): void {
	[$workspace,$cache]=dp_templating_kernel_wave_init($t);
	$templatingInternals=$t->nonPublic(\dataphyre\templating::class);
	$source=$workspace->file('cache/source.tpl','Source');
	$t->contains('plans'.DIRECTORY_SEPARATOR, $templatingInternals->invokeWithArguments('plan_cache_dir'));
	$t->same(null, $templatingInternals->invokeWithArguments('load_from_cache', [$source]));
	$compiled=$templatingInternals->invokeWithArguments('save_to_cache', ['Compiled', $source]);
	$t->isTrue(is_file($compiled));
	$t->same('Compiled', $templatingInternals->invokeWithArguments('load_from_cache', [$source]));
	touch($source, time()+5);
	$t->same(null, $templatingInternals->invokeWithArguments('load_from_cache', [$source]));
	\dataphyre\templating::apply_state(['is_dev_mode'=>true]);
	$t->same(null, $templatingInternals->invokeWithArguments('load_from_cache', [$source]));
	\dataphyre\templating::apply_state(['is_dev_mode'=>false]);
	$t->same(null, $templatingInternals->invokeWithArguments('load_from_cache', [$cache.'missing.tpl']));

	$t->same('first', $templatingInternals->invokeWithArguments('conditional_cache', ['first', ['id'=>1], 'condition']));
	$t->same('first', $templatingInternals->invokeWithArguments('conditional_cache', ['second', ['id'=>1], 'condition']));
	$block='{{cache "wave-block" 30}}First block{{endcache}}';
	$t->same('First block', $templatingInternals->invokeWithArguments('parse_fragment_cache', [$block]));
	$t->same('First block', $templatingInternals->invokeWithArguments('parse_fragment_cache', ['{{cache "wave-block" 30}}Second block{{endcache}}']));
	$templatingInternals->invokeWithArguments('store_in_cache', ['clamped', 'clamped-value', 0]);
	$t->same('clamped-value', $templatingInternals->invokeWithArguments('get_from_cache', ['clamped']));
	$t->same(null, $templatingInternals->invokeWithArguments('get_from_cache', ['missing']));
	$workspace->file('cache/empty.cache','');
	$t->same(null, $templatingInternals->invokeWithArguments('get_from_cache', ['empty']));
	$workspace->file('cache/legacy.cache','legacy-value');
	$t->same('legacy-value', $templatingInternals->invokeWithArguments('get_from_cache', ['legacy']));
	$workspace->file('cache/expired.cache',json_encode(['expires_at'=>time()-1, 'content'=>'expired']));
	$t->same(null, $templatingInternals->invokeWithArguments('get_from_cache', ['expired']));
	$workspace->file('cache/nonstring.cache',json_encode(['expires_at'=>time()+60, 'content'=>['bad']]));
	$t->same(null, $templatingInternals->invokeWithArguments('get_from_cache', ['nonstring']));

	$t->same(null, $templatingInternals->invokeWithArguments('load_plan_from_cache', ['missing-plan', 10]));
	$planDir=$templatingInternals->invokeWithArguments('plan_cache_dir');
	$workspace->directory('cache/plans');
	$workspace->file('cache/plans/empty-plan.json','');
	$t->same(null, $templatingInternals->invokeWithArguments('load_plan_from_cache', ['empty-plan']));
	$workspace->file('cache/plans/invalid-plan.json','{invalid');
	$t->same(null, $templatingInternals->invokeWithArguments('load_plan_from_cache', ['invalid-plan']));
	$workspace->file('cache/plans/wrong-plan.json',json_encode(['plan'=>'invalid']));
	$t->same(null, $templatingInternals->invokeWithArguments('load_plan_from_cache', ['wrong-plan']));
	$templatingInternals->invokeWithArguments('save_plan_to_cache', ['valid-plan', ['nodes'=>['root']], 123]);
	$t->same(['nodes'=>['root']], $templatingInternals->invokeWithArguments('load_plan_from_cache', ['valid-plan', 123]));
	$t->same(null, $templatingInternals->invokeWithArguments('load_plan_from_cache', ['valid-plan', 124]));

})->tag('templating', 'coverage', 'templating-residual-wave')->group('framework-coverage');

test('templating component and rendering kernels cover components lazy placeholders cache strict markdown async dev docs themes and errors', static function(Context $t): void {
	[$workspace,$cache]=dp_templating_kernel_wave_init($t);
	$templatingInternals=$t->nonPublic(\dataphyre\templating::class);
	$template=$workspace->file('cache/page.tpl','<main>Hello {{name}}</main>');
	$fallback=$workspace->file('cache/fallback.tpl','<aside>Fallback {{name}}</aside>');
	$markdown=$workspace->file('cache/readme.md','# Heading');
	$component=str_replace('\\','/',$workspace->file('cache/card.tpl','<style scoped>.card{color:red}</style><article class="card">{{label}}</article>'));
	$components=
		'{{ component "'.$component.'" props=objectProps }}'.
		'{{ component "'.$component.'" props=plainProps }}'.
		'{{ component "'.$component.'" props=scalarProps }}'.
		'{{ component "'.$component.'" }}'.
		'{{ component "'.$cache.'missing-card.tpl" }}';
	$componentHtml=$templatingInternals->invokeWithArguments('parse_components', [$components, [
		'objectProps'=>new DpTemplatingKernelProps(['label'=>'Object']),
		'plainProps'=>(object)['label'=>'Plain'],
		'scalarProps'=>'invalid',
	]]);
	$t->contains('Object', $componentHtml);
	$t->contains('Plain', $componentHtml);
	$t->isFalse(str_contains($componentHtml, 'missing-card'));
	$lazy=$templatingInternals->invokeWithArguments('lazy_load_components', ['{{lazyComponent "card&details"}}', []]);
	$t->contains('card&amp;details', $lazy);

	$t->contains('Heading', (string)\dataphyre\templating::render($markdown));
	$t->contains('Hello Ada', (string)\dataphyre\templating::render($template, ['name'=>'Ada']));
	$t->contains('Hello', (string)\dataphyre\templating::render($template));
	$t->contains('Hello', (string)\dataphyre\templating::render($template));
	$t->contains('Hello Grace', (string)\dataphyre\templating::render_with_fallback($template, ['name'=>'Grace'], $fallback));
	$t->contains('Fallback Lin', (string)\dataphyre\templating::render_with_fallback($cache.'missing.tpl', ['name'=>'Lin'], $fallback));
	$t->contains('Hello Edsger', (string)\dataphyre\templating::full_render($template, ['name'=>'Edsger']));
	$t->contains('Inline Barbara', \dataphyre\templating::render_string('Inline {{name}}', ['name'=>'Barbara']));

	\dataphyre\templating::apply_state(['strict_mode'=>true, 'inspection_enabled'=>false]);
	$t->contains('Hello Strict', (string)\dataphyre\templating::render($template, ['name'=>'Strict']));
	$t->contains('Inline Strict', \dataphyre\templating::render_string('Inline {{name}}', ['name'=>'Strict'], template_name:'strict-inline.tpl'));
	\dataphyre\templating::apply_state(['strict_mode'=>false, 'inspection_enabled'=>false]);

	$promise=\dataphyre\templating::async_render($template, ['name'=>'Async']);
	$t->contains('Async', (string)$promise->value);
	$rejected=\dataphyre\templating::async_render($cache.'missing-async.tpl');
	$t->isTrue($rejected->value!==null || $rejected->reason!==null);
	$t->same('unchanged', $templatingInternals->invokeWithArguments('apply_theme_values', ['unchanged', []]));
	$themed=$templatingInternals->invokeWithArguments('apply_theme_values', ['{{palette}} {{tone}}', ['palette'=>['primary'=>'blue'], 'tone'=>'warm&bright']]);
	$t->contains('warm&amp;bright', $themed);

	\dataphyre\templating::apply_state(['is_dev_mode'=>true]);
	\dataphyre\templating::render_string('Development docs', template_name:'dev-docs.tpl');
	$t->isTrue(is_dir($cache.'docs'));
	\dataphyre\templating::apply_state(['is_dev_mode'=>false]);
	\dataphyre\templating::register_preprocessing_hook(static function(): never { throw new RuntimeException('wave preprocessing failure'); });
	$error=\dataphyre\templating::render_string('Broken render', template_name:'broken-wave.tpl');
	$t->contains('wave preprocessing failure', $error);
	$inspection=\dataphyre\templating::inspect_string('Broken inspection', template_name:'broken-inspection-wave.tpl');
	$t->isTrue($inspection['manifest']['failed']);
	$t->contains('wave preprocessing failure', $inspection['manifest']['failure_message']);

})->tag('templating', 'coverage', 'templating-residual-wave')->group('framework-coverage');
