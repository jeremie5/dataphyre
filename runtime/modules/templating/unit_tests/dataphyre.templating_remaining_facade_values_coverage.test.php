<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Templating\AssetManifest;
use Dataphyre\Templating\BindingCacheIdentityProvider;
use Dataphyre\Templating\BindingContext;
use Dataphyre\Templating\BindingMetadataProvider;
use Dataphyre\Templating\BindingPersistentCacheProvider;
use Dataphyre\Templating\BindingResolution;
use Dataphyre\Templating\CachedBinding;
use Dataphyre\Templating\CallableBinding;
use Dataphyre\Templating\ConditionalBinding;
use Dataphyre\Templating\DataBinding;
use Dataphyre\Templating\RememberedBinding;
use Dataphyre\Templating\RenderedTemplate;
use Dataphyre\Templating\TemplateContract;
use Dataphyre\Templating\TemplatePlan;
use Dataphyre\Templating\TemplateView;
use Dataphyre\Templating\Templating;
use Dataphyre\Templating\TemplatingContext;
use Dataphyre\Templating\TemplatingState;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'templating'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_templating_remaining_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_templating_remaining_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_templating_remaining_modules_root);
\dataphyre\autoloader::register_framework_modules(['core', 'templating']);
require_once $dp_templating_remaining_modules_root.'/templating/unit_tests/templating_render_test_helpers.php';

require_once __DIR__.'/fixtures/templating_optional_dependency_probes.php';

final class DpTemplatingCallableTarget {
	public function arrayResolver(): string { return 'array'; }
	public static function staticResolver(BindingContext $context): string { return $context->templateName(); }
	public function __invoke(BindingContext $context): string { return $context->isInline() ? 'invokable' : 'file'; }
}

final class DpTemplatingConditionTarget {
	public function withContext(BindingContext $context): bool { return $context->isInline(); }
	public static function yes(): bool { return true; }
	public function __invoke(BindingContext $context): bool { return $context->templateName()==='inline.tpl'; }
}

final class DpTemplatingMetadataBinding implements BindingMetadataProvider, BindingCacheIdentityProvider, BindingPersistentCacheProvider {
	public function name(): string { return 'metadata'; }
	public function resolve(BindingContext $context): mixed { return $context->templateName(); }
	public function metadata(): array { return ['source'=>'test']; }
	public function cacheIdentity(BindingContext $context): mixed { return ['template'=>$context->templateName()]; }
	public function persistentCache(BindingContext $context): ?array { return ['ttl'=>30, 'identity'=>$this->cacheIdentity($context)]; }
}

test('templating callable and conditional bindings cover every callable reflection shape', static function(Context $t): void {
	$context=new BindingContext('inline.tpl', true, ['name'=>'Ada']);
	$target=new DpTemplatingCallableTarget();
	$t->same('array', CallableBinding::make([$target, 'arrayResolver'])->resolve($context));
	$t->same('inline.tpl', CallableBinding::make(DpTemplatingCallableTarget::class.'::staticResolver')->resolve($context));
	$t->same('invokable', CallableBinding::make($target)->resolve($context));

	$binding=new DpTemplatingMetadataBinding();
	$active=ConditionalBinding::when($binding, true);
	$t->same(['template'=>'inline.tpl'], $active->cacheIdentity($context));
	$t->same(['ttl'=>30, 'identity'=>['template'=>'inline.tpl']], $active->persistentCache($context));
	$t->same('inline.tpl', $active->resolve($context));
	$t->same(['source'=>'test', 'conditional'=>true, 'condition_mode'=>'when', 'condition_type'=>'bool'], $active->metadata());

	$t->same('inline.tpl', ConditionalBinding::when($binding, static fn(): bool=>true)->resolve($context));
	$t->same('inline.tpl', ConditionalBinding::when($binding, static fn(BindingContext $active): bool=>$active->isInline())->resolve($context));
	$conditions=new DpTemplatingConditionTarget();
	$t->same('inline.tpl', ConditionalBinding::when($binding, [$conditions, 'withContext'])->resolve($context));
	$t->same('inline.tpl', ConditionalBinding::when($binding, DpTemplatingConditionTarget::class.'::yes')->resolve($context));
	$t->same('inline.tpl', ConditionalBinding::when($binding, $conditions)->resolve($context));
	$t->same('inline.tpl', ConditionalBinding::unless($binding, false)->resolve($context));

	$value=BindingResolution::value('ready');
	$t->same('ready', $value->result());
	$t->isFalse($value->isSkipped());
})->tag('templating', 'binding', 'deep-coverage')->group('framework-coverage');

test('templating state exposes globals contracts and typed contract lookup', static function(Context $t): void {
	$state=TemplatingState::fromArray([
		'global_context'=>['app'=>'Dataphyre'],
		'template_contracts'=>[
			'inline.tpl'=>['required'=>['name'], 'optional'=>['subtitle']],
		],
	]);
	$t->same(['app'=>'Dataphyre'], $state->globalContext());
	$t->same(['inline.tpl'=>['required'=>['name'], 'optional'=>['subtitle']]], $state->templateContracts());
	$t->instanceOf(TemplateContract::class, $state->templateContract('inline.tpl'));
})->tag('templating', 'state', 'deep-coverage')->group('framework-coverage');

test('templating facade covers remaining context binding file and asynchronous delegates', static function(Context $t): void {
	$workspace=$t->workspace('templating-facade');
	$tmp=rtrim($workspace->directory('cache'), '/\\').DIRECTORY_SEPARATOR;
	$template=$workspace->file('cache/template.tpl','<main>Hello {{name}}</main>');
	$missing=$tmp.'missing.tpl';
	\dataphyre\templating::init(false, $tmp, false, ['scripts'=>[], 'styles'=>[]]);
	Templating::flush();
	try{
		$t->instanceOf(TemplatingContext::class, Templating::context(false, $tmp, ['app'=>'Dataphyre'], false));
		$t->instanceOf(TemplateView::class, Templating::template($template));
		$t->throws(static fn()=>Templating::component('ui:missing'), RuntimeException::class);

		$binding=Templating::binding(static fn(): string=>'ready');
		$t->instanceOf(CachedBinding::class, Templating::cachedBinding($binding, ['id'=>1], 'cached'));
		$t->instanceOf(RememberedBinding::class, Templating::rememberBinding($binding, ['id'=>1], 60, ['tests'], 'remembered'));
		$t->instanceOf(ConditionalBinding::class, Templating::whenBinding($binding, true));
		$t->instanceOf(ConditionalBinding::class, Templating::unlessBinding($binding, false));

		$t->throws(static fn()=>Templating::queryBinding(new stdClass()), InvalidArgumentException::class);
		$t->throws(static fn()=>Templating::queryBindingInheritingIdentity(new stdClass()), InvalidArgumentException::class);
		$t->throws(static fn()=>Templating::searchBinding(new stdClass()), InvalidArgumentException::class);
		$t->throws(static fn()=>Templating::searchBindingInheritingIdentity(new stdClass()), InvalidArgumentException::class);

		$t->instanceOf(RenderedTemplate::class, Templating::render($template, ['name'=>'Ada']));
		$t->instanceOf(TemplatePlan::class, Templating::plan($template));
		$t->instanceOf(AssetManifest::class, Templating::assetManifest($template));
		$t->instanceOf(RenderedTemplate::class, Templating::inspect($template, ['name'=>'Ada']));
		$t->contains('Hello Grace', Templating::renderWithFallback($missing, $template, ['name'=>'Grace'])->content());
		$t->isTrue(is_object(Templating::asyncRender($template, ['name'=>'Async'])));
	}finally{
		Templating::flush();
	}
})->tag('templating', 'facade', 'deep-coverage')->group('framework-coverage')->maxMillis(10000);
