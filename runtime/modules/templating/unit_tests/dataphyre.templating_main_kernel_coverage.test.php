<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use Dataphyre\Test\NonPublicAccess;
use Dataphyre\Test\TempWorkspace;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'templating'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_templating_main_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_templating_main_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_templating_main_modules_root);
\dataphyre\autoloader::register_framework_modules(['core', 'templating']);
require_once $dp_templating_main_modules_root.'/templating/unit_tests/templating_render_test_helpers.php';

/** @return array{0:TempWorkspace,1:string,2:NonPublicAccess} */
function dp_templating_main_scenario(Context $t,string $name): array {
	$workspace=$t->workspace('templating-main-'.$name);
	$root=rtrim($workspace->root(),'/\\').DIRECTORY_SEPARATOR;
	$templating=$t->nonPublic(\dataphyre\templating::class);
	foreach([
		'initialized','global_context','parent_context','current_render_data','dev_hooks','prod_hooks',
		'inspection_enabled','active_manifest','compiled_plan_cache','asset_policy',
	] as $property){
		$templating->replacePropertyForTest($property,$templating->readProperty($property));
	}
	\dataphyre\templating::init(false,$workspace->directory('cache'),false,[]);
	return [$workspace,$root,$templating];
}

test('templating main initialization binding transformations and legacy helpers cover edge behavior', static function(Context $t): void {
	[$workspace,$root,$templating]=dp_templating_main_scenario($t,'helpers');
	$fresh_cache=$root.'fresh-cache';
	\dataphyre\templating::init(true, $fresh_cache, true, [
		'preload'=>['style'=>false, 'script'=>true, 'image'=>false, 'font'=>true],
		'scripts'=>['strategy'=>'async', 'type'=>'module'],
		'styles'=>['media'=>'print'],
		'fonts'=>['crossorigin'=>'use-credentials'],
	]);
	$t->isTrue(is_dir($fresh_cache));
	$helpers=$templating->readProperty('helpers');
	$filters=$templating->readProperty('filters');
	$t->same('2024', $helpers['date_format']('2024-05-06', 'Y'));
	$t->same('hello-world', $helpers['slugify'](' Hello, world! '));
	$t->same('12', $helpers['money']('12'));
	$t->same('8', $filters['money'](8));

	$legacy=new \dataphyre\templating(true);
	$t->instanceOf(\dataphyre\templating::class, $legacy);
	$templating->writeProperty('initialized', false);
	$t->isTrue(\dataphyre\templating::strict_mode());
	\dataphyre\templating::init(false, $root.'runtime-cache', false, []);

	\dataphyre\templating::apply_state([
		'is_dev_mode'=>true,
		'cache_dir'=>$root.'state-cache',
		'global_context'=>['base'=>'global'],
		'strict_mode'=>true,
		'template_contracts'=>[0=>['required'=>[]], 'logical.tpl'=>['required'=>['name']]],
		'asset_policy'=>['scripts'=>['strategy'=>'defer']],
	]);
	$t->isTrue(is_dir($root.'state-cache'));
	$t->same('global', \dataphyre\templating::global_context()['base']);
	$t->throws(static fn()=>\dataphyre\templating::plan($root.'missing.tpl'), RuntimeException::class);
	$t->throws(static fn()=>\dataphyre\templating::register_component_contract('missing-component', []), RuntimeException::class);
	$t->throws(static fn()=>$templating->invokeWithArguments('load_template_file', [$root.'missing.tpl']), RuntimeException::class);

	$object=(object)['child'=>(object)['value'=>'object-value']];
	$t->same('object-value', $templating->invokeWithArguments('get_value_by_path', [$object, 'child.value']));
	$t->same(null, $templating->invokeWithArguments('get_value_by_path', [['child'=>[]], 'child.missing']));
	$t->isTrue($templating->invokeWithArguments('data_path_exists', [['child'=>['value'=>null]], 'child.value']));
	$t->isTrue($templating->invokeWithArguments('data_path_exists', [$object, 'child.value']));
	$t->isFalse($templating->invokeWithArguments('data_path_exists', [$object, 'child.missing']));

	$templating->invokeWithArguments('bind_if', ['visible', 7, static fn(): bool=>true]);
	$templating->invokeWithArguments('bind_if', ['hidden', 8, static fn(): bool=>false]);
	$templating->invokeWithArguments('set_local', ['local', 'value']);
	$t->same('value', \dataphyre\templating::global_context()['local']);
	$templating->invokeWithArguments('unset_local', ['local']);
	$t->isFalse(array_key_exists('local', \dataphyre\templating::global_context()));
	$scoped=$templating->invokeWithArguments('with_context', [['temporary'=>'inside'], static fn(): string=>(string)\dataphyre\templating::global_context()['temporary']]);
	$t->same('inside', $scoped);
	$t->isFalse(array_key_exists('temporary', \dataphyre\templating::global_context()));
	$loop=$templating->invokeWithArguments('for_each_scoped', [[10, 20], static fn(int $item, array $scope): string=>$item.':'.($scope['first'] ? 'F' : 'N').':'.($scope['last'] ? 'L' : 'N').'|']);
	$t->same('10:F:N|20:N:L|', $loop);
	$templating->writeProperty('global_context', ['scope'=>(object)['value'=>'current']]);
	$templating->writeProperty('parent_context', ['fallback'=>'parent']);
	$t->same('current', $templating->invokeWithArguments('get_scoped_value', ['scope.value']));
	$t->same('parent', $templating->invokeWithArguments('get_scoped_value', ['fallback']));

	$t->same('leftmiddleright', $templating->invokeWithArguments('trim_whitespace', ['left{% - middle - %}right']));
	$undefined=$templating->invokeWithArguments('handle_undefined_variables', ['{{endif}}/{{missing}}/{{present}}', ['present'=>'yes']]);
	$t->same('{{endif}}/[Undefined]/{{present}}', $undefined);
	$nested=$templating->invokeWithArguments('replace_nested_placeholders', [
		'{{product.price.amount}}/{{product.meta.label}}',
		'product',
		['price'=>['amount'=>'<5>'], 'meta'=>(object)['label'=>'Object']],
	]);
	$t->same('&lt;5&gt;/Object', $nested);

	\dataphyre\templating::register_tag('tm_tag', static fn(array $args, array $data): string=>($data['prefix'] ?? '').implode('|', $args));
	$tagged=$templating->invokeWithArguments('apply_tags', ['{{ tm_tag one, two }} {{ unknown x }}', ['prefix'=>'P:']]);
	$t->contains('P:one|two', $tagged);
	\dataphyre\templating::register_filter('tm_suffix', static fn(mixed $value, string $suffix=''): string=>(string)$value.$suffix);
	$templating->writeProperty('current_render_data', []);
	$filtered=$templating->invokeWithArguments('apply_filters', ['{{ user.name | tm_suffix("!") }} {{user.name|unknown}}', ['user'=>['name'=>'Ada']]]);
	$t->contains('Ada!', $filtered);

	\dataphyre\templating::register_preprocessing_hook(static fn(string $source, array $data): string=>$source.'-'.$data['phase']);
	\dataphyre\templating::register_postprocessing_hook(static fn(string $source, array $data): string=>strtoupper($source).$data['suffix']);
	$t->same('pre-before', $templating->invokeWithArguments('apply_preprocessing_hooks', ['pre', ['phase'=>'before']]));
	$t->same('POST!', $templating->invokeWithArguments('apply_postprocessing_hooks', ['post', ['suffix'=>'!']]));
	$t->same('3', \dataphyre\templating::apply_functions('{{sum(1,2)}}', ['sum'=>static fn(string $a, string $b): int=>(int)$a+(int)$b]));
	$t->same('X', \dataphyre\templating::apply_transformations('{{upper(x)}}', ['upper'=>static fn(string $value): string=>strtoupper($value)], []));

	$css=$workspace->file('site.css','body{}');
	$js=$workspace->file('app.js','void 0;');
	$dependencies=$templating->invokeWithArguments('resolve_dependencies', ['{{ requireCSS "'.$css.'" }}{{ requireJS "'.$js.'" }}']);
	$t->contains("rel='stylesheet'", $dependencies);
	$t->contains('<script', $dependencies);
	$conditional=$templating->invokeWithArguments('conditional_asset_import', [
		'{{loadCSS "'.$css.'"}}{{loadCSS "'.$css.'" ifshow}}{{loadCSS "'.$css.'" ifhide}}'.
		'{{loadJS "'.$js.'"}}{{loadJS "'.$js.'" ifshow}}{{loadJS "'.$js.'" ifhide}}',
		['show'=>true, 'hide'=>false],
	]);
	$t->contains('{{loadCSS "'.$css.'" ifhide}}', $conditional);
	$t->contains('{{loadJS "'.$js.'" ifhide}}', $conditional);

	$templating->writeProperty('dev_hooks', [static fn(string $source, array $data): string=>$source.$data['dev']]);
	$templating->writeProperty('prod_hooks', [static fn(string $source, array $data): string=>$source.$data['prod']]);
	$t->same('D-dev', $templating->invokeWithArguments('apply_conditional_hooks', ['D', ['dev'=>'-dev'], 'dev']));
	$t->same('P-prod', $templating->invokeWithArguments('apply_conditional_hooks', ['P', ['prod'=>'-prod'], 'prod']));
	$t->contains('- alpha', $templating->invokeWithArguments('generate_template_docs', ['{{alpha}}{{beta|trim}}']));
	require_once __DIR__.'/fixtures/templating_localization_probe.php';
	$t->same('L:greeting', $templating->invokeWithArguments('parse_translations', ['{{ trans "greeting" }}']));
})->tag('templating', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('managed templating compiles in memory without source-local cache writes',static function(Context $t): void {
	$workspace=$t->workspace('templating-managed-source-write-boundary');
	$template=$workspace->file('templates/managed.tpl','managed-body');
	$probe=$t->phpProcess([
		__DIR__.'/fixtures/templating_managed_source_write_boundary.php',
		dirname(__DIR__,2),
		$workspace->root(),
		$template,
	]);
	$t->processSucceeded($probe);
	$t->same('',$probe->stderr());
	$t->same([
		'write_allowed'=>false,
		'rendered'=>'managed-body',
		'development_rendered'=>'managed-body',
		'plan_has_graph'=>true,
		'binding_contents'=>['managed-binding','managed-binding'],
		'binding_calls'=>2,
		'clear_count'=>0,
		'cache_paths_absent'=>true,
	],$probe->json());
})->tag('templating','main','managed-runtime','cache')->group('framework-coverage');

test('templating main resolution manifests and contracts cover strict and recursive paths', static function(Context $t): void {
	[$workspace,$root,$templating]=dp_templating_main_scenario($t,'contracts');
	$main=$workspace->file('main.tpl','main');
	$child=$workspace->file('child.tpl','child');
	$component=$workspace->file('components/widget.tpl','widget');

	$t->same('', $templating->invokeWithArguments('normalize_template_reference', ['']));
	$t->same(null, $templating->invokeWithArguments('resolve_template_reference', ['']));
	$templating->invokeWithArguments('push_template_context', [$main]);
	$t->same(realpath(dirname($main)), $templating->invokeWithArguments('current_template_dir'));
	$t->same(realpath($child), $templating->invokeWithArguments('resolve_template_reference', ['child.tpl']));
	$t->same(realpath($main), $templating->invokeWithArguments('resolve_template_reference', [$main]));
	$t->same(null, $templating->invokeWithArguments('resolve_template_reference', ['missing.tpl']));
	$t->same(null, $templating->invokeWithArguments('resolve_component_reference', ['']));
	$t->same(realpath($component), $templating->invokeWithArguments('resolve_component_reference', ['widget']));
	$t->same(realpath($component), $templating->invokeWithArguments('resolve_component_reference', [$component]));

	\dataphyre\templating::register_component_contract('widget', [
		'required'=>['title'],
		'optional'=>['subtitle'],
		'required_slots'=>['body'],
		'optional_slots'=>['aside'],
		'defaults'=>['subtitle'=>'Default'],
		'prop_types'=>['title'=>'string'],
		'allow_additional_data'=>false,
		'allow_additional_slots'=>false,
	]);
	$summary=$templating->invokeWithArguments('component_contract_summary', [$component]);
	$t->same(['title'], $summary['required']);
	$t->same(null, $templating->invokeWithArguments('component_contract_summary', [null]));
	$t->same(null, $templating->invokeWithArguments('component_contract_summary', ['']));
	$t->same(null, $templating->invokeWithArguments('component_contract_summary', [$root.'missing.tpl']));
	$templating->invokeWithArguments('pop_template_context');
	$t->same(null, $templating->invokeWithArguments('current_template_dir'));

	$templating->writeProperty('inspection_enabled', false);
	$templating->invokeWithArguments('record_manifest_value', ['ignored', 'value']);
	$capture=$templating->invokeWithArguments('with_manifest_capture', [
		static function() use($templating): string {
			$templating->invokeWithArguments('record_template_render', ['captured.tpl', true]);
			$templating->invokeWithArguments('record_manifest_value', ['custom', '']);
			$templating->writeProperty('active_manifest', array_replace($templating->readProperty('active_manifest'), ['custom'=>'invalid', 'structured'=>[7], 'broken_structured'=>'invalid']));
			$templating->invokeWithArguments('record_manifest_value', ['custom', 'alpha']);
			$templating->invokeWithArguments('record_manifest_value', ['custom', 'alpha']);
			$templating->invokeWithArguments('record_manifest_structured', ['broken_structured', ['id'=>0]]);
			$templating->invokeWithArguments('record_manifest_structured', ['structured', ['id'=>1]]);
			$templating->invokeWithArguments('record_manifest_structured', ['structured', ['id'=>1]]);
			$templating->invokeWithArguments('record_missing_reference', ['partial', 'missing.tpl']);
			return 'captured';
		},
		['template_name'=>'captured.tpl', 'inline'=>true, 'data_keys'=>['a', 'a'], 'theme_value_keys'=>['tone'], 'slot_names'=>['body']],
	]);
	$t->same('captured', $capture['content']);
	$t->same(['alpha'], $capture['manifest']['custom']);
	$t->same(2, count($capture['manifest']['structured']));
	$t->throws(static fn()=>$templating->invokeWithArguments('with_manifest_capture', [
		static fn(): never=>throw new LogicException('capture failure'),
		['template_name'=>'failing.tpl'],
	]), LogicException::class);

	$normalized=$templating->invokeWithArguments('normalize_template_contract', [[
		'required'=>[' name ', 'name', 7, ''],
		'optional'=>['optional'],
		'slots'=>[' body '],
		'optional_slots'=>['aside'],
		'defaults'=>[7=>'skip', ''=>'skip', ' profile.name '=>'Ada'],
		'types'=>[7=>'string', ' age '=>' INT ', 'blank'=>''],
		'allow_additional_data'=>false,
		'allow_additional_slots'=>false,
	]]);
	$t->same(['name'], $normalized['required']);
	$t->same(['body'], $normalized['required_slots']);
	$t->same(['age'=>'int'], $normalized['prop_types']);
	$direct=$templating->invokeWithArguments('normalize_template_contract', [['required_slots'=>['direct'], 'prop_types'=>['value'=>'string']]]);
	$t->same(['direct'], $direct['required_slots']);
	$t->same([], $templating->invokeWithArguments('normalize_template_contract', [[]])['required_slots']);
	$t->same([], $templating->invokeWithArguments('normalize_template_contract', [['required_slots'=>'invalid', 'slots'=>'invalid']])['required_slots']);

	$t->same(['plain'=>'value'], $templating->invokeWithArguments('validate_template_contract', ['unregistered.tpl', ['plain'=>'value'], []]));
	\dataphyre\templating::register_template_contract('strict.tpl', [
		'required'=>['missing.path'],
		'optional'=>['allowed'],
		'required_slots'=>['body'],
		'optional_slots'=>['aside'],
		'defaults'=>['profile.name'=>'Ada'],
		'prop_types'=>['typed'=>'int', 'absent'=>'string'],
		'allow_additional_data'=>false,
		'allow_additional_slots'=>false,
	]);
	$templating->writeProperty('inspection_enabled', true);
	$templating->writeProperty('active_manifest', $templating->invokeWithArguments('new_manifest', [['template_name'=>'strict.tpl']]));
	$validated=$templating->invokeWithArguments('validate_template_contract', ['strict.tpl', ['typed'=>'wrong', 'extra'=>true], ['extra_slot'=>'x']]);
	$t->same('Ada', $validated['profile']['name']);
	$manifest=$templating->readProperty('active_manifest');
	$t->isTrue(count($manifest['contract_violations'])>=4);

	\dataphyre\templating::set_strict_mode(false);
	$templating->invokeWithArguments('enforce_strict_mode', ['strict.tpl']);
	\dataphyre\templating::set_strict_mode(true);
	$templating->writeProperty('active_manifest', $templating->invokeWithArguments('new_manifest', [['template_name'=>'clean.tpl']]));
	$templating->invokeWithArguments('enforce_strict_mode', ['clean.tpl']);
	$strict_manifest=$templating->readProperty('active_manifest');
	$strict_manifest['contract_violations']=[['message'=>'bad']];
	$strict_manifest['missing_references']=[['reference'=>'missing']];
	$strict_manifest['undefined_variables']=['missing'];
	$strict_manifest['errors']=[['message'=>'error']];
	$templating->writeProperty('active_manifest', $strict_manifest);
	$t->throws(static fn()=>$templating->invokeWithArguments('enforce_strict_mode', ['strict.tpl']), RuntimeException::class);
	$t->isTrue($templating->readProperty('active_manifest')['failed']);
	$templating->writeProperty('inspection_enabled', false);
	\dataphyre\templating::set_strict_mode(false);

	$t->isTrue($templating->invokeWithArguments('path_exists', [(object)['child'=>(object)['value'=>null]], 'child.value']));
	$t->isFalse($templating->invokeWithArguments('path_exists', [(object)['child'=>[]], 'child.missing']));
	$t->same(['alpha', 'beta'], $templating->invokeWithArguments('top_level_contract_keys', [[7, '', 'alpha.one', 'alpha.two', 'beta']]));
	$t->same(['valid'=>'x'], $templating->invokeWithArguments('normalize_contract_defaults', [[7=>'bad', ''=>'bad', ' valid '=>'x']]));
	$t->same(['count'=>'int'], $templating->invokeWithArguments('normalize_contract_type_map', [[7=>'int', 'blank'=>'', ' count '=>' INT ']]));
	$defaults=$templating->invokeWithArguments('apply_contract_defaults', [['present'=>null], [7=>'bad', ''=>'bad', 'present'=>'keep', 'nested.value'=>9]]);
	$t->same(null, $defaults['present']);
	$t->same(9, $defaults['nested']['value']);
	$blank=[];
	$blankWrite=$templating->capture('set_data_path_value',data:$blank,path:' . ',value:'ignored');
	$t->same([], $blankWrite->argument('data'));

	$array=[];
	$array=$templating->capture('assign_data_path_value',current:$array,segments:['leaf'],value:1)->argument('current');
	$array=$templating->capture('assign_data_path_value',current:$array,segments:['branch','leaf'],value:2)->argument('current');
	$array['object']=(object)[];
	$array=$templating->capture('assign_data_path_value',current:$array,segments:['object','leaf'],value:3)->argument('current');
	$array=$templating->capture('assign_data_path_value',current:$array,segments:[7],value:4)->argument('current');
	$object_target=(object)[];
	$object_target=$templating->capture('assign_data_path_value',current:$object_target,segments:['leaf'],value:5)->argument('current');
	$object_target=$templating->capture('assign_data_path_value',current:$object_target,segments:['branch','leaf'],value:6)->argument('current');
	$object_target->scalar='replace';
	$object_target=$templating->capture('assign_data_path_value',current:$object_target,segments:['scalar','leaf'],value:7)->argument('current');
	$t->same(7, $object_target->scalar['leaf']);

	$type_samples=[
		'mixed'=>null, 'string'=>'x', 'int'=>1, 'integer'=>1, 'float'=>1.2, 'double'=>1.2,
		'numeric'=>'12', 'bool'=>true, 'boolean'=>false, 'array'=>['a'=>1], 'list'=>[1, 2],
		'scalar'=>'x', 'object'=>(object)[], 'callable'=>static fn(): null=>null, 'custom'=>new stdClass(),
	];
	foreach($type_samples as $type=>$value){
		$t->isTrue($templating->invokeWithArguments('contract_value_matches_type', [$value, $type]));
	}
})->tag('templating', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('templating main recursive planner covers rich directives caches cycles and fallbacks', static function(Context $t): void {
	[$workspace,$root,$templating]=dp_templating_main_scenario($t,'planner');
	$main=$workspace->path('root.tpl');
	$partial=$workspace->file('partial.tpl','Partial {{partial.value}} {{ include "missing-nested.tpl" }}');
	$child=$workspace->file('child.tpl','Child {{child.value}} {{ include "root.tpl" }}');
	$layout=$workspace->file('layout.tpl','Layout {{layout.value}}');
	$layout_two=$workspace->file('layout2.tpl','Layout two');
	$component=$workspace->file('components/widget.tpl','Widget {{widget.title}}');
	\dataphyre\templating::register_helper('tm_helper', static fn(string $value): string=>$value);
	\dataphyre\templating::register_extension('tm_extension', static fn(string $value): string=>$value);
	\dataphyre\templating::register_tag('tm_plan_tag', static fn(): string=>'tag');
	$source=implode("\n", [
		'{{ user.name }}',
		'{{ user.name | tm_filter | second_filter("x") }}',
		'{{ import "child.tpl" if flags.enabled }}',
		'{{ include "partial.tpl" with partial }}',
		'{{ include "missing.tpl" }}',
		'{{ component "widget" props=widget.props }}',
		'{{slot "hero"}}',
		'{{ extends "layout.tpl" }}',
		'{{ extend "layout2.tpl" }}',
		'{{asset "photo.png"}}',
		'{{ requireCSS "site" }}',
		'{{ requireJS "app" }}',
		'{{loadCSS "print" ifprint}}',
		'{{loadJS "extra"}}',
		'{{ trans "hello" }} {{ translate "world" }}',
		'{{tm_helper("x")}} {{tm_extension("x")}} {{ tm_plan_tag anything }}',
		'{{cache "fragment" 10}} {{loopitems}} {{if ready}} {{php echo 1}} {{form action}}',
		'# Markdown',
	]);
	$workspace->file('root.tpl',$source);

	$plan=\dataphyre\templating::plan($main);
	$t->isTrue(count($plan['graph']['nodes'])>=5);
	$t->notEmpty($plan['unresolved_references']);
	$t->same($plan, \dataphyre\templating::plan($main));
	$templating->writeProperty('compiled_plan_cache', []);
	$t->same($plan['source_hash'], \dataphyre\templating::plan($main)['source_hash']);
	$inline=\dataphyre\templating::plan_string($source, $main);
	$t->same($inline, \dataphyre\templating::plan_string($source, $main));
	$t->contains('user.name', implode(',', $inline['data_paths']));
	$t->contains('tm_helper', implode(',', $inline['helpers']));
	$t->contains('tm_extension', implode(',', $inline['extensions']));
	$t->contains('tm_plan_tag', implode(',', $inline['tags']));

	$custom=[
		'template_name'=>'custom.tpl', 'inline'=>true, 'cache_mode'=>'memory',
		'data_paths'=>[], 'top_level_data_keys'=>[], 'slot_names'=>[],
		'partials'=>[7, ['reference'=>'missing', 'template'=>null], ['reference'=>'seen', 'template'=>$child]],
		'components'=>[], 'imports'=>[], 'layouts'=>[], 'assets'=>[], 'dependencies'=>[],
		'translations'=>[], 'tags'=>[], 'filters'=>[], 'helpers'=>[], 'extensions'=>[],
	];
	$visited=[$child=>true];
	$expandedCall=$templating->capture('expand_template_plan',plan:$custom,visited:$visited,depth:2);
	$expanded=$expandedCall->result();
	$t->same(2, $expanded['graph']['nodes'][0]['depth']);
	$t->same(1, count($expanded['unresolved_references']));
	$t->isTrue(count($expandedCall->argument('visited'))>=1);

	$merged=$templating->invokeWithArguments('merge_plan_aggregate', [['existing'=>['a']], ['existing'=>['b'], 'new'=>['c'], 'ignored'=>'scalar']]);
	$t->same(['a', 'b'], $merged['existing']);
	$t->same(['c'], $merged['new']);
	$aggregate=$templating->invokeWithArguments('normalize_plan_aggregate', [[
		'data_paths'=>'invalid', 'top_level_data_keys'=>[], 'slot_names'=>[], 'assets'=>[], 'translations'=>[],
		'tags'=>[], 'filters'=>[], 'helpers'=>[], 'extensions'=>[], 'partials'=>'invalid', 'components'=>[],
		'imports'=>[], 'layouts'=>[], 'dependencies'=>[],
	]]);
	$t->same([], $aggregate['data_paths']);
	$t->same(['a', 'b'], $templating->invokeWithArguments('unique_string_values', [[7, '', ' a ', 'a', 'b']]));
	$t->same([['a'=>1], ['a'=>2]], $templating->invokeWithArguments('unique_structured_values', [[7, ['a'=>1], ['a'=>1], ['a'=>2]]]));
	$t->notEmpty($templating->invokeWithArguments('render_cache_signature'));
})->tag('templating', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('templating main asset policies descriptors manifests and adapt cover every asset family', static function(Context $t): void {
	[$workspace,$root,$templating]=dp_templating_main_scenario($t,'assets');
	$files=[];
	foreach(['css', 'js', 'mjs', 'png', 'woff2', 'txt'] as $extension){
		$files[$extension]=$workspace->file('asset.'.$extension,'asset');
	}
	$workspace->file('bare.css','asset');
	$workspace->file('bare.js','asset');

	$defaults=$templating->invokeWithArguments('normalize_asset_policy', [[
		'preload'=>'invalid', 'scripts'=>'invalid', 'styles'=>'invalid', 'fonts'=>'invalid',
	]]);
	$t->same('blocking', $defaults['scripts']['strategy']);
	$aliases=$templating->invokeWithArguments('normalize_asset_policy', [[
		'preload'=>['style'=>false, 'script'=>false, 'image'=>false, 'font'=>false],
		'scripts'=>['strategy'=>' async ', 'type'=>' module '],
		'styles'=>['media'=>''],
		'fonts'=>['crossorigin'=>'none'],
	]]);
	$t->isFalse($aliases['preload']['styles']);
	$t->same('all', $aliases['styles']['media']);
	$t->same(null, $aliases['fonts']['crossorigin']);
	$t->same('async', $templating->invokeWithArguments('normalize_script_strategy', [' ASYNC ']));
	$t->same('defer', $templating->invokeWithArguments('normalize_script_strategy', ['defer']));
	$t->same('blocking', $templating->invokeWithArguments('normalize_script_strategy', ['other']));
	$t->same('classic', $templating->invokeWithArguments('normalize_script_type', ['classic']));
	$t->same('module', $templating->invokeWithArguments('normalize_script_type', ['module']));
	$t->same('auto', $templating->invokeWithArguments('normalize_script_type', ['other']));
	$t->same('use-credentials', $templating->invokeWithArguments('normalize_font_crossorigin', [' use-credentials ']));
	$t->same(null, $templating->invokeWithArguments('normalize_font_crossorigin', [false]));
	$t->same('anonymous', $templating->invokeWithArguments('normalize_font_crossorigin', [true]));
	$templating->writeProperty('asset_policy', ['fonts'=>[]]);
	$t->same(" crossorigin='anonymous'", $templating->invokeWithArguments('font_crossorigin_attribute'));

	\dataphyre\templating::set_asset_policy([
		'preload'=>['styles'=>false, 'scripts'=>false, 'images'=>false, 'fonts'=>false],
		'scripts'=>['strategy'=>'async', 'type'=>'module'],
		'styles'=>['media'=>'print'],
		'fonts'=>['crossorigin'=>'use-credentials'],
	]);
	foreach(['style', 'script', 'image', 'font', 'asset'] as $type){
		$t->isFalse($templating->invokeWithArguments('descriptor_should_preload', [['type'=>$type]]));
	}
	$t->same('module', $templating->invokeWithArguments('descriptor_script_type', [['extension'=>'js']]));
	$t->contains("type='module'", $templating->invokeWithArguments('script_attributes_from_descriptor', [['extension'=>'js']]));
	$t->contains('async', $templating->invokeWithArguments('script_attributes_from_descriptor', [['extension'=>'js']]));
	$t->same(" media='print'", $templating->invokeWithArguments('stylesheet_attributes_from_descriptor', [[]]));
	$t->same(" crossorigin='use-credentials'", $templating->invokeWithArguments('font_crossorigin_attribute'));

	\dataphyre\templating::set_asset_policy(['scripts'=>['strategy'=>'defer', 'type'=>'classic'], 'styles'=>['media'=>'all'], 'fonts'=>['crossorigin'=>'none']]);
	$t->same(null, $templating->invokeWithArguments('descriptor_script_type', [['extension'=>'mjs']]));
	$t->same(' defer', $templating->invokeWithArguments('script_attributes_from_descriptor', [['extension'=>'js']]));
	$t->same('', $templating->invokeWithArguments('stylesheet_attributes_from_descriptor', [[]]));
	$t->same('', $templating->invokeWithArguments('font_crossorigin_attribute'));
	\dataphyre\templating::set_asset_policy(['scripts'=>['strategy'=>'defer', 'type'=>'auto'], 'styles'=>['media'=>'  ']]);
	$t->same('module', $templating->invokeWithArguments('descriptor_script_type', [['extension'=>'mjs']]));
	$t->same(null, $templating->invokeWithArguments('descriptor_script_type', [['extension'=>'js']]));
	$t->same(" type='module'", $templating->invokeWithArguments('script_attributes_from_descriptor', [['extension'=>'mjs']]));
	\dataphyre\templating::set_asset_policy(['scripts'=>['strategy'=>'blocking', 'type'=>'classic']]);
	$t->same('', $templating->invokeWithArguments('script_attributes_from_descriptor', [['extension'=>'js']]));

	$external=$templating->invokeWithArguments('resolve_asset_descriptor', ['https://cdn.example.test/site.css', 'css']);
	$t->isTrue($external['exists']);
	$t->same('style', $external['type']);
	$t->isTrue($templating->invokeWithArguments('resolve_asset_descriptor', [$root.'bare', 'css'])['exists']);
	$t->isTrue($templating->invokeWithArguments('resolve_asset_descriptor', [$root.'bare', 'js'])['exists']);
	$t->isFalse($templating->invokeWithArguments('resolve_asset_descriptor', ['relative-missing', 'asset'])['exists']);
	foreach(['css'=>'style', 'js'=>'script', 'mjs'=>'script', 'png'=>'image', 'woff2'=>'font', 'txt'=>'asset'] as $extension=>$type){
		$descriptor=$templating->invokeWithArguments('resolve_asset_descriptor', [$files[$extension], 'asset']);
		$t->same($type, $descriptor['type']);
	}
	foreach([
		['css', 'asset', 'style'], ['js', 'asset', 'script'], ['png', 'asset', 'image'],
		['woff', 'asset', 'font'], ['', 'css', 'style'], ['', 'js', 'script'], ['', 'asset', 'asset'],
	] as [$extension, $hint, $expected]){
		$t->same($expected, $templating->invokeWithArguments('asset_type_from_extension', [$extension, $hint]));
	}
	$t->same('asset', $templating->invokeWithArguments('asset_type_from_extension', ['unknown', 'unknown']));
	foreach(['style'=>'style', 'script'=>'script', 'image'=>'image', 'font'=>'font', 'asset'=>null] as $type=>$expected){
		$t->same($expected, $templating->invokeWithArguments('asset_preload_type', [$type]));
	}
	$t->contains("rel='stylesheet'", $templating->invokeWithArguments('asset_tag_from_descriptor', [['path'=>'style.css', 'type'=>'style']]));
	$t->contains('<script', $templating->invokeWithArguments('asset_tag_from_descriptor', [['path'=>'app.js', 'type'=>'script', 'extension'=>'js']]));
	$t->same('photo.png', $templating->invokeWithArguments('asset_tag_from_descriptor', [['path'=>'photo.png', 'type'=>'image']]));
	$t->contains("as='font'", $templating->invokeWithArguments('preload_tag_from_descriptor', [['path'=>'font.woff2', 'type'=>'font', 'preload_as'=>'font']]));
	$t->contains("as='style'", $templating->invokeWithArguments('preload_tag_from_descriptor', [['path'=>'style.css', 'type'=>'style', 'preload_as'=>'style']]));

	\dataphyre\templating::set_asset_policy([
		'preload'=>['styles'=>true, 'scripts'=>true, 'images'=>true, 'fonts'=>true],
		'scripts'=>['strategy'=>'defer', 'type'=>'auto'],
		'styles'=>['media'=>'screen'],
		'fonts'=>['crossorigin'=>'anonymous'],
	]);
	$plan=[
		'aggregate'=>[
			'assets'=>[7, '', $files['css'], $files['js'], $files['png'], $files['woff2'], $files['txt'], $files['css'], $root.'missing.png'],
			'dependencies'=>[7, ['type'=>'css', 'reference'=>''], ['type'=>'css', 'reference'=>$files['css']], ['type'=>'js', 'reference'=>$files['mjs'], 'condition'=>'ready'], ['type'=>'js', 'reference'=>$root.'missing.js']],
		],
	];
	$manifest=$templating->invokeWithArguments('build_asset_manifest_from_plan', [$plan]);
	$t->notEmpty($manifest['stylesheets']);
	$t->notEmpty($manifest['scripts']);
	$t->notEmpty($manifest['images']);
	$t->notEmpty($manifest['fonts']);
	$t->notEmpty($manifest['missing']);
	$t->notEmpty($manifest['signature']);
	$fallback_manifest=$templating->invokeWithArguments('build_asset_manifest_from_plan', [['assets'=>[$files['png']], 'dependencies'=>[]]]);
	$t->same(1, count($fallback_manifest['items']));
	$unique=$templating->invokeWithArguments('unique_asset_descriptors', [[7, ['path'=>'a', 'type'=>'style', 'preload_as'=>'style'], ['path'=>'a', 'type'=>'style', 'preload_as'=>'style'], ['path'=>'b', 'type'=>'script', 'preload_as'=>'script']]]);
	$t->same(2, count($unique));

	if(!defined('VISITOR_CTX')){
		define('VISITOR_CTX', (object)['theme_mode'=>'default']);
	}
	$mode=(string)VISITOR_CTX->theme_mode;
	$t->same('tone', \dataphyre\templating::adapt([$mode=>'tone']));
	$t->same(' tone ', \dataphyre\templating::adapt([$mode=>'tone'], true));
	$t->same('', \dataphyre\templating::adapt([]));
})->tag('templating', 'coverage')->group('framework-coverage')->maxMillis(10000);
