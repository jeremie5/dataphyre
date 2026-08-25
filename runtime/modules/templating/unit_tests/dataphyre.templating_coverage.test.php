<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Templating\AssetPolicy;
use Dataphyre\Templating\BindingContext;
use Dataphyre\Templating\BindingResolution;
use Dataphyre\Templating\CallableBinding;
use Dataphyre\Templating\RenderedTemplate;
use Dataphyre\Templating\TemplateContract;
use Dataphyre\Templating\TemplatePlan;
use Dataphyre\Templating\TemplateView;
use Dataphyre\Templating\Templating;
use Dataphyre\Templating\TemplatingManager;
use Dataphyre\Templating\TemplatingState;
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
$dp_templating_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_templating_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_templating_modules_root);
\dataphyre\autoloader::register_framework_modules(['core', 'templating']);
require_once $dp_templating_modules_root.'/templating/unit_tests/templating_render_test_helpers.php';

/** @return array{0:TempWorkspace,1:string} */
function dp_templating_coverage_init(Context $t): array {
	$workspace=$t->workspace('templating-coverage');
	$cache=rtrim($workspace->directory('cache'), '/\\').DIRECTORY_SEPARATOR;
	\dataphyre\templating::init(false, $cache, false, [
		'scripts'=>['defer'=>true, 'module'=>false],
		'styles'=>['media'=>'all'],
	]);
	return [$workspace,$cache];
}

test('templating manager renders plans inspects and restores temporary state', static function(Context $t): void {
	[$workspace,$cache]=dp_templating_coverage_init($t);
	$root=rtrim($workspace->directory('cache/templates'),'/\\').DIRECTORY_SEPARATOR;
	$template=$workspace->file('cache/templates/hello.tpl','<main>Hello {{name}}</main>');
	$fallback=$workspace->file('cache/templates/fallback.tpl','<aside>Fallback {{name}}</aside>');
	TemplatingManager::flush();
	$manager=TemplatingManager::instance();
	$t->instanceOf(TemplatingManager::class, $manager);
	$state=$manager->state([
		'is_dev_mode'=>true,
		'cache_dir'=>$cache,
		'global_context'=>['app'=>'Dataphyre'],
		'strict_mode'=>false,
		'unknown'=>'ignored',
	]);
	$t->instanceOf(TemplatingState::class, $state);
	$t->isTrue($state->isDevMode());
	$t->same($cache, $state->cacheDir());
	$t->same('Dataphyre', $state->global('app'));
	$t->isTrue($state->hasGlobal('app'));
	$t->same('fallback', $state->global('missing', 'fallback'));
	$t->isFalse($state->strictMode());
	$t->notEmpty($state->toArray());
	$t->instanceOf(AssetPolicy::class, $state->assetPolicy());
	$t->instanceOf(AssetPolicy::class, $state->assetPolicy());
	$t->isFalse($state->hasTemplateContract('missing.tpl'));
	$t->same(null, $state->templateContract('missing.tpl'));

	$manager->addGlobal('company', 'Example Publisher');
	$t->same('Example Publisher', $manager->globals()['company']);
	$manager->clearGlobals();
	$t->isFalse(array_key_exists('company', $manager->globals()));
	$manager->setStrictMode(false);
	$manager->setAssetPolicy(['scripts'=>['defer'=>true], 'styles'=>['media'=>'print']]);
	$t->instanceOf(AssetPolicy::class, $manager->assetPolicy());
	$manager->setAssetPolicy(AssetPolicy::fromArray(['scripts'=>['module'=>true]]));

	$contract=TemplateContract::define(['name'], ['subtitle'])->requiredSlots('body');
	$manager->registerContract($template, $contract);
	$t->instanceOf(TemplateContract::class, $manager->contract($template));
	$manager->registerComponentContract($template, $contract);
	$t->instanceOf(TemplateContract::class, $manager->componentContract($template));
	$t->same(null, $manager->resolveComponentTemplate('ui:missing'));

	$rendered=$manager->render($template, ['name'=>'Ada']);
	$t->instanceOf(RenderedTemplate::class, $rendered);
	$t->contains('Hello Ada', $rendered->content());
	$t->isTrue(is_string($rendered->bodyHtml()));
	$t->instanceOf(TemplatePlan::class, $manager->plan($template));
	$t->notEmpty($manager->assetManifest($template)->summary());
	$t->instanceOf(RenderedTemplate::class, $manager->inspect($template, ['name'=>'Ada']));

	$inline='<section>Hello {{name}}</section>';
	$t->contains('Hello Grace', $manager->renderString($inline, ['name'=>'Grace'])->content());
	$t->instanceOf(RenderedTemplate::class, $manager->inspectString($inline, ['name'=>'Grace']));
	$t->instanceOf(TemplatePlan::class, $manager->planString($inline, 'coverage-inline.tpl'));
	$t->notEmpty($manager->assetManifestString($inline, 'coverage-inline.tpl')->summary());
	$t->contains('Fallback Ada', $manager->renderWithFallback($root.'missing.tpl', $fallback, ['name'=>'Ada'])->content());
	$t->contains('Hello Ada', $manager->renderWithFallback($template, $fallback, ['name'=>'Ada'])->content());

	$before=$manager->state()->toArray();
	$value=$manager->withStateOverrides(['strict_mode'=>true, 'template_contracts'=>['inline.tpl'=>['required'=>['name']]]], static fn(): bool=>(bool)\dataphyre\templating::state()['strict_mode']);
	$t->isTrue($value);
	$t->same($before, $manager->state()->toArray());
	$t->same('direct', $manager->withStateOverrides([], static fn(): string=>'direct'));

	$manager->clearContract($template);
	$t->same(null, $manager->contract($template));
	$manager->clearContract();
	$manager->clearComponentContract($template);
	$t->same(null, $manager->componentContract($template));
	$t->same(0, $manager->clearBindingCache('missing-cache-name'));
	$t->isTrue($manager->clearBindingCache()>=0);

})->tag('templating', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('templating bindings template views extensions and hooks expose coherent contracts', static function(Context $t): void {
	[$workspace,$cache]=dp_templating_coverage_init($t);
	$manager=TemplatingManager::instance();
	$context=new BindingContext('inline.tpl', true, ['name'=>'Ada'], ['tone'=>'warm'], ['body'=>'Slot'], ['name'=>'Override'], ['render_trace_id'=>'trace-1']);
	$zero=$manager->binding(static fn(): string=>'zero', 'zero');
	$aware=$manager->binding(static fn(BindingContext $context): string=>(string)$context->get('name'), 'aware');
	$t->same('zero', $zero->resolve($context));
	$t->same('Ada', $aware->resolve($context));
	$t->same('zero', $zero->name());
	$t->same('aware', $aware->name());

	$cached=$manager->cachedBinding($aware, ['id'=>7], 'cached');
	$t->notEmpty($cached->cacheIdentity($context));
	$t->same('Ada', $cached->resolve($context));
	$remembered=$manager->rememberBinding($aware, ['id'=>7], 60, ['users', 'tenant:7'], 'remembered');
	$t->notEmpty($remembered->persistentCache($context));
	$t->same('Ada', $remembered->resolve($context));
	$t->same('Ada', $manager->whenBinding($aware, true, 'default')->resolve($context));
	$skippedWhen=$manager->whenBinding($aware, false, 'default')->resolve($context);
	$t->instanceOf(BindingResolution::class, $skippedWhen);
	$t->same('default', $skippedWhen->result());
	$t->same('Ada', $manager->unlessBinding($aware, false, 'default')->resolve($context));
	$skippedUnless=$manager->unlessBinding($aware, true, 'default')->resolve($context);
	$t->instanceOf(BindingResolution::class, $skippedUnless);
	$t->same('default', $skippedUnless->result());
	$t->throws(static fn()=>$manager->queryBinding(new stdClass()), InvalidArgumentException::class);
	$t->throws(static fn()=>$manager->searchBinding(new stdClass()), InvalidArgumentException::class);

	$manager->registerTag('coverage_tag', static fn(): string=>'tag');
	$manager->registerFilter('coverage_filter', static fn(mixed $value): mixed=>$value);
	$manager->registerExtension('coverage_extension', static fn(): array=>[]);
	$manager->registerHelper('coverage_helper', static fn(): string=>'helper');
	$manager->registerEventHook('rendered', static fn(): null=>null);
	$manager->registerPreprocessingHook(static fn(string $source): string=>$source);
	$manager->registerPostprocessingHook(static fn(string $content): string=>$content);

	$view=$manager->source('<article>{{title}} {{lazy}}</article>', 'view.tpl')
		->withData(['title'=>'First'])
		->withProps(['subtitle'=>'Sub'])
		->mergeData(['title'=>'Second'])
		->mergeProps(['count'=>2])
		->withBinding('lazy', static fn(): string=>'Bound')
		->withBindings(['other'=>static fn(): string=>'Other'])
		->withBindingWhen('enabled', static fn(): string=>'Yes', true, 'No')
		->withBindingUnless('disabled', static fn(): string=>'Yes', true, 'No')
		->withThemeValues(['tone'=>'warm'])
		->mergeThemeValues(['accent'=>'blue'])
		->withSlots(['body'=>'Body'])
		->slot('aside', 'Aside')
		->strict(false)
		->withContract(['required'=>['title']])
		->withComponentContract(['optional'=>['subtitle']])
		->withAssetPolicy(['scripts'=>['defer'=>true]])
		->withBindingGuardrails(['enabled'=>true, 'warn_unused'=>true]);
	$t->instanceOf(TemplateView::class, $view);
	$t->contains('Second Bound', $view->render()->content());
	$t->instanceOf(TemplatePlan::class, $view->plan());
	$t->notEmpty($view->assetManifest()->summary());
	$t->isTrue(is_string($view->headHtml()));
	$t->isTrue(is_string($view->bodyHtml()));
	$t->instanceOf(RenderedTemplate::class, $view->inspect());
	$t->contains('Second Bound', $view->content());
	$t->instanceOf(TemplateView::class, $manager->template('missing.tpl'));
	$t->throws(static fn()=>$manager->component('ui:missing'), RuntimeException::class);
	$t->instanceOf(TemplateView::class, $manager->context(false, $cache)->source('Hello {{name}}')->withData(['name'=>'Context']));

})->tag('templating', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('templating facade delegates state rendering registration and contract operations', static function(Context $t): void {
	[$workspace,$cache]=dp_templating_coverage_init($t);
	$component=$workspace->file('cache/facade-component.tpl','Component {{name}}');
	Templating::flush();
	$t->instanceOf(TemplatingManager::class, Templating::manager());
	$t->instanceOf(TemplatingState::class, Templating::state());
	$t->instanceOf(TemplateView::class, Templating::source('Hi {{name}}')->withData(['name'=>'Ada']));
	$t->instanceOf(CallableBinding::class, Templating::binding(static fn(): int=>7));
	$t->contains('Hi Ada', Templating::renderString('Hi {{name}}', ['name'=>'Ada'])->content());
	$t->instanceOf(RenderedTemplate::class, Templating::inspectString('Hi {{name}}', ['name'=>'Ada']));
	$t->instanceOf(TemplatePlan::class, Templating::planString('Hi {{name}}'));
	$t->notEmpty(Templating::assetManifestString('Hi {{name}}')->summary());

	Templating::registerTag('facade_tag', static fn(): string=>'tag');
	Templating::registerFilter('facade_filter', static fn(mixed $value): mixed=>$value);
	Templating::registerExtension('facade_extension', static fn(): array=>[]);
	Templating::registerHelper('facade_helper', static fn(): string=>'helper');
	Templating::on('rendered', static fn(): null=>null);
	Templating::before(static fn(string $source): string=>$source);
	Templating::after(static fn(string $content): string=>$content);
	Templating::addGlobal('facade', true);
	$t->isTrue(Templating::globals()['facade']);
	Templating::clearGlobals();
	$t->same(0, Templating::clearBindingCache('missing'));
	$t->instanceOf(AssetPolicy::class, Templating::assetPolicy());
	Templating::setAssetPolicy(['scripts'=>['defer'=>true]]);
	Templating::setStrictMode(false);
	Templating::registerContract('facade.tpl', ['required'=>['name']]);
	$t->instanceOf(TemplateContract::class, Templating::contract('facade.tpl'));
	Templating::registerComponentContract($component, ['required'=>['name']]);
	$t->instanceOf(TemplateContract::class, Templating::componentContract($component));
	$t->same(null, Templating::resolveComponentTemplate('ui:missing'));
	Templating::clearContract('facade.tpl');
	Templating::clearComponentContract($component);
	$t->isTrue(is_string(Templating::adapt(['Hello ', 'World'], true)));

})->tag('templating', 'coverage')->group('framework-coverage')->maxMillis(10000);
