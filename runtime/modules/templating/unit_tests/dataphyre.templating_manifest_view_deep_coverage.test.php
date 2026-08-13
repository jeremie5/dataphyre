<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Templating\AssetPolicy;
use Dataphyre\Templating\CallableBinding;
use Dataphyre\Templating\ConditionalBinding;
use Dataphyre\Templating\SearchQueryBinding;
use Dataphyre\Templating\SqlQueryBinding;
use Dataphyre\Templating\TemplateManifest;
use Dataphyre\Templating\TemplatePlan;
use Dataphyre\Templating\TemplateView;
use Dataphyre\Templating\TemplatingManager;
use Dataphyre\Test\Context;
use Dataphyre\Test\TempWorkspace;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',[
		'enabled'=>['core'=>true,'templating'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dpTemplateManifestViewModulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $dpTemplateManifestViewModulesRoot.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dpTemplateManifestViewModulesRoot);
\dataphyre\autoloader::register_framework_modules(['core','templating']);
require_once $dpTemplateManifestViewModulesRoot.'/templating/unit_tests/templating_render_test_helpers.php';

require_once __DIR__.'/fixtures/templating_optional_dependency_probes.php';

/** @return array{0:TempWorkspace,1:string} */
function dp_template_manifest_view_init(Context $t): array {
	$workspace=$t->workspace('template-manifest-view');
	$cache=rtrim($workspace->directory('cache'), '/\\').DIRECTORY_SEPARATOR;
	\dataphyre\templating::init(false,$cache,false);
	TemplatingManager::flush();
	return [$workspace,$cache];
}

test('templating manifest-view manifest exposes rich normalized metadata and cached summaries',static function(Context $t): void {
	$payload=[
		'template_name'=>'account.tpl',
		'inline'=>true,
		'cache_strategy'=>'persistent',
		'cache_used'=>true,
		'strict_mode'=>true,
		'render_trace_id'=>'trace-template-1',
		'asset_policy'=>['scripts'=>['defer'=>true],'styles'=>['media'=>'print']],
		'data_keys'=>['account','locale'],
		'theme_value_keys'=>['accent'],
		'slot_names'=>['body'],
		'duration_ms'=>12.75,
		'content_length'=>321,
		'failed'=>true,
		'failure_message'=>'render stopped',
		'templates'=>['account.tpl','layout.tpl'],
		'partials'=>['nav.tpl'],
		'components'=>['button.tpl'],
		'imports'=>['macros.tpl'],
		'layouts'=>['layout.tpl'],
		'assets'=>['app.css','app.js'],
		'dependencies'=>['font.woff2'],
		'translations'=>['account.title'],
		'undefined_variables'=>['missing_name'],
		'missing_references'=>['missing.tpl'],
		'tags'=>['if'],
		'filters'=>['escape'],
		'helpers'=>['route'],
		'extensions'=>['commerce'],
		'contracts'=>[['required'=>['account']]],
		'bindings'=>[['path'=>'account']],
		'binding_trace'=>[['path'=>'account','status'=>'resolved']],
		'binding_errors'=>[['path'=>'bad','message'=>'failed']],
		'binding_warnings'=>[['path'=>'slow','message'=>'slow']],
		'binding_planner'=>[['path'=>'account','cacheable'=>true]],
		'contract_violations'=>[['path'=>'account.id']],
		'errors'=>['render stopped'],
	];
	$manifest=TemplateManifest::fromArray($payload);
	$t->same('account.tpl',$manifest->templateName());
	$t->isTrue($manifest->isInline());
	$t->same('persistent',$manifest->cacheStrategy());
	$t->isTrue($manifest->cacheUsed());
	$t->isTrue($manifest->strictMode());
	$t->same('trace-template-1',$manifest->renderTraceId());
	$policy=$manifest->assetPolicy();
	$t->instanceOf(AssetPolicy::class,$policy);
	$t->same($policy,$manifest->assetPolicy());
	$t->same(['account','locale'],$manifest->dataKeys());
	$t->same(['accent'],$manifest->themeValueKeys());
	$t->same(['body'],$manifest->slotNames());
	$t->same(12.75,$manifest->durationMs());
	$t->same(321,$manifest->contentLength());
	$t->isTrue($manifest->failed());
	$t->same('render stopped',$manifest->failureMessage());
	$t->same(['account.tpl','layout.tpl'],$manifest->templates());
	$t->same(['nav.tpl'],$manifest->partials());
	$t->same(['button.tpl'],$manifest->components());
	$t->same(['macros.tpl'],$manifest->imports());
	$t->same(['layout.tpl'],$manifest->layouts());
	$t->same(['app.css','app.js'],$manifest->assets());
	$t->same(['font.woff2'],$manifest->dependencies());
	$t->same(['account.title'],$manifest->translations());
	$t->same(['missing_name'],$manifest->undefinedVariables());
	$t->same(['missing.tpl'],$manifest->missingReferences());
	$t->same(['if'],$manifest->tags());
	$t->same(['escape'],$manifest->filters());
	$t->same(['route'],$manifest->helpers());
	$t->same(['commerce'],$manifest->extensions());
	$t->same([['required'=>['account']]],$manifest->contracts());
	$t->same([['path'=>'account']],$manifest->bindings());
	$t->same([['path'=>'account','status'=>'resolved']],$manifest->bindingTrace());
	$t->same([['path'=>'bad','message'=>'failed']],$manifest->bindingErrors());
	$t->same([['path'=>'slow','message'=>'slow']],$manifest->bindingWarnings());
	$t->same([['path'=>'account','cacheable'=>true]],$manifest->bindingPlanner());
	$t->same([['path'=>'account.id']],$manifest->contractViolations());
	$t->same(['render stopped'],$manifest->errors());
	$t->isTrue($manifest->hasMissingReferences());
	$t->isTrue($manifest->hasErrors());
	$t->isTrue($manifest->hasContractViolations());
	$t->isTrue($manifest->hasBindingErrors());
	$t->isTrue($manifest->hasBindingWarnings());
	$t->isTrue($manifest->hasBindingPlanner());
	$summary=$manifest->summary();
	$t->same(2,$summary['template_count']);
	$t->same(3,$summary['asset_count']);
	$t->same(1,$summary['binding_error_count']);
	$t->same(1,$summary['binding_warning_count']);
	$t->same(1,$summary['contract_violation_count']);
	$t->same($summary,$manifest->summary());
	$t->same($payload,$manifest->toArray());
})->tag('templating','manifest-view','deep-coverage')->group('framework-coverage');

test('templating manifest-view manifest defaults malformed partial payloads safely',static function(Context $t): void {
	$manifest=new TemplateManifest([
		'asset_policy'=>'invalid',
		'data_keys'=>'invalid',
		'templates'=>'invalid',
		'assets'=>'invalid',
		'dependencies'=>'invalid',
		'binding_errors'=>'invalid',
		'binding_warnings'=>'invalid',
		'render_trace_id'=>'',
		'failure_message'=>false,
	]);
	$t->same('template.tpl',$manifest->templateName());
	$t->isFalse($manifest->isInline());
	$t->same('runtime',$manifest->cacheStrategy());
	$t->isFalse($manifest->cacheUsed());
	$t->isFalse($manifest->strictMode());
	$t->same(null,$manifest->renderTraceId());
	$t->instanceOf(AssetPolicy::class,$manifest->assetPolicy());
	$t->same([],$manifest->dataKeys());
	$t->same([],$manifest->templates());
	$t->same(0.0,$manifest->durationMs());
	$t->same(0,$manifest->contentLength());
	$t->isFalse($manifest->failed());
	$t->same(null,$manifest->failureMessage());
	$t->isFalse($manifest->hasMissingReferences());
	$t->isFalse($manifest->hasErrors());
	$t->isFalse($manifest->hasContractViolations());
	$t->isFalse($manifest->hasBindingErrors());
	$t->isFalse($manifest->hasBindingWarnings());
	$t->isFalse($manifest->hasBindingPlanner());
	$summary=$manifest->summary();
	$t->same(0,$summary['template_count']);
	$t->same(0,$summary['asset_count']);
	$t->same(0,$summary['binding_error_count']);
})->tag('templating','manifest-view','deep-coverage')->group('framework-coverage');

test('templating manifest-view view composes nested sql search and conditional bindings immutably',static function(Context $t): void {
	[$workspace,$cache]=dp_template_manifest_view_init($t);
	$manager=TemplatingManager::instance();
	$base=$manager->source('{{node.child}}','binding-view.tpl')->withData(['node'=>'scalar','kept'=>'yes']);
	$sql=new \Dataphyre\Database\RepositoryQuery();
	$search=new \Dataphyre\FulltextEngine\Query();
	$existing=CallableBinding::make(static fn(): string=>'existing','existing');
	$view=$base
		->withBindings([0=>static fn(): string=>'ignored','   '=>static fn(): string=>'ignored','flat'=>$existing])
		->withBinding('...',static fn(): string=>'ignored')
		->withBinding('node.child',static fn(): string=>'nested')
		->withBinding('existing.binding',$existing)
		->withQuery('sql.rows',$sql,'records',['columns'=>['id']])
		->withQueryIdentity('sql.identity',$sql,'first')
		->withSearch('search.results',$search,'results')
		->withSearchIdentity('search.identity',$search,'first')
		->withQueryWhen('conditional.query_when',$sql,true,'count',[],-1)
		->withQueryUnless('conditional.query_unless',$sql,false,'records',[],[])
		->withSearchWhen('conditional.search_when',$search,true,'results',[],[])
		->withSearchUnless('conditional.search_unless',$search,false,'raw',[],0);

	$t->same(['node'=>'scalar','kept'=>'yes'],$t->nonPublic($base)->readProperty('data'));
	$data=$t->nonPublic($view)->readProperty('data');
	$t->same('yes',$data['kept']);
	$t->instanceOf(CallableBinding::class,$data['node']['child']);
	$t->same($existing,$data['flat']);
	$t->same($existing,$data['existing']['binding']);
	$t->instanceOf(SqlQueryBinding::class,$data['sql']['rows']);
	$t->instanceOf(SqlQueryBinding::class,$data['sql']['identity']);
	$t->instanceOf(SearchQueryBinding::class,$data['search']['results']);
	$t->instanceOf(SearchQueryBinding::class,$data['search']['identity']);
	$t->instanceOf(ConditionalBinding::class,$data['conditional']['query_when']);
	$t->instanceOf(ConditionalBinding::class,$data['conditional']['query_unless']);
	$t->instanceOf(ConditionalBinding::class,$data['conditional']['search_when']);
	$t->instanceOf(ConditionalBinding::class,$data['conditional']['search_unless']);

})->tag('templating','manifest-view','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('templating manifest-view view dispatches file inline fallback inspection planning and async paths',static function(Context $t): void {
	[$workspace,$cache]=dp_template_manifest_view_init($t);
	$primary=$workspace->file('cache/primary.tpl','Primary {{name}}');
	$fallback=$workspace->file('cache/fallback.tpl','Fallback {{name}}');
	$missing=$cache.'missing.tpl';
	$manager=TemplatingManager::instance();
	$plain=$manager->template($primary)->withData(['name'=>'Ada']);
	$t->same('Primary Ada',$plain->render()->content());
	$t->instanceOf(TemplatePlan::class,$plain->plan());
	$t->same('Primary Ada',$plain->inspect()->content());
	$plainAsync=$plain->async();
	$t->instanceOf(\dataphyre\async\promise::class,$plainAsync);
	$t->same(null,$plainAsync->reason);
	$t->notEmpty($plainAsync->value);

	$existingFallback=$plain->withFallback($fallback);
	$t->same('Primary Ada',$existingFallback->render()->content());
	$t->instanceOf(TemplatePlan::class,$existingFallback->plan());
	$t->same('Primary Ada',$existingFallback->inspect()->content());

	$missingFallback=$manager->template($missing)->withData(['name'=>'Grace'])->withFallback($fallback);
	$t->same('Fallback Grace',$missingFallback->render()->content());
	$t->instanceOf(TemplatePlan::class,$missingFallback->plan());
	$t->same('Fallback Grace',$missingFallback->inspect()->content());

	$inline=$manager->source('Inline {{name}}','inline-manifest-view.tpl')->withData(['name'=>'Lin']);
	$t->same('Inline Lin',$inline->render()->content());
	$t->instanceOf(TemplatePlan::class,$inline->plan());
	$t->same('Inline Lin',$inline->inspect()->content());
	$inlineAsync=$inline->async();
	$t->instanceOf(\dataphyre\async\promise::class,$inlineAsync);
	$t->same(null,$inlineAsync->reason);
	$t->notEmpty($inlineAsync->value);

})->tag('templating','manifest-view','deep-coverage')->group('framework-coverage')->maxMillis(10000);
