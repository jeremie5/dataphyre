<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

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

$dp_templating_parsing_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_templating_parsing_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_templating_parsing_modules_root);
\dataphyre\autoloader::register_framework_modules(['core', 'templating']);
require_once $dp_templating_parsing_modules_root.'/templating/unit_tests/templating_render_test_helpers.php';

/** @return array{0:TempWorkspace,1:string} */
function dp_templating_parsing_root(Context $t): array {
	$workspace=$t->workspace('templating-parsing');
	$root=rtrim($workspace->root(), '/\\').DIRECTORY_SEPARATOR;
	\dataphyre\templating::init(false, $workspace->directory('cache'), false, []);
	return [$workspace,$root];
}

function dp_templating_parsing_reference(string $path): string {
	return str_replace('\\', '/', $path);
}

test('templating parsing covers local directives placeholders controls and diagnostics', static function(Context $t): void {
	$templatingParsingInternals=$t->nonPublic(\dataphyre\templating::class);
	$t->same('plain', $templatingParsingInternals->invokeWithArguments('parse_lazy_load_components', ['plain', []]));
	$t->same(
		"<div class='lazy-component' data-component='profile'>Loading...</div>",
		$templatingParsingInternals->invokeWithArguments('parse_lazy_load_components', ["{{lazyLoadComponent 'profile'}}", []])
	);

	$slots=$templatingParsingInternals->invokeWithArguments('parse_slots', [
		'{{ slot "hero" }}Default hero{{ endslot }}|{{slot "aside"}}Default aside{{endslot}}',
		[],
		['hero'=>'Custom hero'],
	]);
	$t->same('Custom hero|Default aside', $slots);

	$t->same('unstyled', $templatingParsingInternals->invokeWithArguments('parse_scoped_styles', ['unstyled', 'card']));
	$scoped=$templatingParsingInternals->invokeWithArguments('parse_scoped_styles', [
		'<style scoped>.card { color:red; } .child { color:blue; }</style><section class="card child">Body</section>',
		'card',
	]);
	$t->matches('/^<div class=\'comp_card_[^\']+\'>/', $scoped);
	$t->contains('<style>.comp_card_', $scoped);
	$t->contains('-child { color:blue; }</style>', $scoped);

	$t->same('class="alpha beta"', $templatingParsingInternals->invokeWithArguments('parse_attributes', ['{{addClass "alpha beta"}}', []]));
	$t->same('clean', $templatingParsingInternals->invokeWithArguments('parse_php_blocks', ['clean']));
	$t->same('beforeafter', $templatingParsingInternals->invokeWithArguments('parse_php_blocks', ['before{{php echo "unsafe"; }}after']));

	$debug=$templatingParsingInternals->invokeWithArguments('parse_debug', [
		'{{debug present}}|{{debug missing}}',
		['present'=>['html'=>'<b>']],
	]);
	$t->contains('&lt;b&gt;', $debug);
	$t->contains('<pre>undefined</pre>', $debug);

	$placeholders=$templatingParsingInternals->invokeWithArguments('replace_placeholders', [
		'{{title}}|{{profile.name}}|{{profile.details.age}}|{{object.value}}',
		[
			'title'=>'<Title>',
			'profile'=>['name'=>'Ada', 'details'=>['age'=>37]],
			'object'=>(object)['value'=>'Object'],
		],
	]);
	$t->same('&lt;Title&gt;|Ada|37|Object', $placeholders);

	$t->same('', $templatingParsingInternals->invokeWithArguments('parse_loop_controls', ['{{loopmissing}}x{{endloop}}', []]));
	$t->same(
		'Ada:1;Lin:2;',
		$templatingParsingInternals->invokeWithArguments('parse_loop_controls', [
			'{{loopitems}}{{name}}:{{value}};{{endloop}}',
			['items'=>[['name'=>'Ada', 'value'=>1], ['name'=>'Lin', 'value'=>2]]],
		])
	);
	$t->same('before', $templatingParsingInternals->invokeWithArguments('parse_loop_controls', [
		'{{loopitems}}before{{break}}after{{endloop}}',
		['items'=>[['name'=>'Ada'], ['name'=>'Lin']]],
	]));
	$t->same('skip-skip-', $templatingParsingInternals->invokeWithArguments('parse_loop_controls', [
		'{{loopitems}}skip-{{continue}}after{{endloop}}',
		['items'=>[['name'=>'Ada'], ['name'=>'Lin']]],
	]));
})->tag('templating', 'coverage')->group('framework-coverage');

test('templating parsing covers layouts imports and partial resolution branches', static function(Context $t): void {
	[$workspace,$root]=dp_templating_parsing_root($t);
	$templatingParsingInternals=$t->nonPublic(\dataphyre\templating::class);
	$layout=$workspace->file('layout.tpl','Header {{block content}} Footer');
	$legacy_layout=$workspace->file('legacy-layout.tpl','Legacy {{ block_content "content" }} Footer');
	$import=$workspace->file('import.tpl','Imported {{name}}');
	$partial=$workspace->file('partial.tpl','Partial {{name}}');

	$layout_ref=dp_templating_parsing_reference($layout);
	$legacy_ref=dp_templating_parsing_reference($legacy_layout);
	$import_ref=dp_templating_parsing_reference($import);
	$partial_ref=dp_templating_parsing_reference($partial);
	$missing_ref=dp_templating_parsing_reference($root.'missing.tpl');

	$t->same('plain', $templatingParsingInternals->invokeWithArguments('parse_layout_inheritance', ['plain']));
	$t->same('Header Child Footer', $templatingParsingInternals->invokeWithArguments('parse_layout_inheritance', [
		'{{ extends "'.$layout_ref.'" }}{{ block content }}Child{{ endblock }}',
	]));
	$missing_layout='{{ extends "'.$missing_ref.'" }}Child';
	$t->same($missing_layout, $templatingParsingInternals->invokeWithArguments('parse_layout_inheritance', [$missing_layout]));

	$t->same('plain', $templatingParsingInternals->invokeWithArguments('parse_inheritance', ['plain']));
	$t->same('Legacy Child Footer', $templatingParsingInternals->invokeWithArguments('parse_inheritance', [
		'{{ extend "'.$legacy_ref.'" }}{{ block "content" }}Child{{ endblock }}',
	]));
	$missing_legacy='{{ extend "'.$missing_ref.'" }}Child';
	$t->same($missing_legacy, $templatingParsingInternals->invokeWithArguments('parse_inheritance', [$missing_legacy]));

	$imports=$templatingParsingInternals->invokeWithArguments('parse_dynamic_imports', [
		'{{ import "'.$import_ref.'" if flags.enabled }}|'.
		'{{ import "'.$import_ref.'" if flags.disabled }}|'.
		'{{ import "'.$missing_ref.'" if flags.enabled }}',
		['name'=>'Ada', 'flags'=>['enabled'=>true, 'disabled'=>false]],
	]);
	$t->same('Imported Ada||', $imports);

	$partials=$templatingParsingInternals->invokeWithArguments('parse_partials', [
		'{{ include "'.$partial_ref.'" }}|'.
		'{{ include "'.$partial_ref.'" with profile }}|'.
		'{{ include "'.$partial_ref.'" with scalar }}|'.
		'{{ include "'.$missing_ref.'" }}',
		['name'=>'Root', 'profile'=>['name'=>'Scoped'], 'scalar'=>'not-an-array'],
	]);
	$t->same('Partial Root|Partial Scoped|Partial Root|', $partials);

})->tag('templating', 'coverage')->group('framework-coverage')->maxMillis(10000);

test('templating conditional parsing covers loops blocks and expression grammar', static function(Context $t): void {
	$templatingParsingInternals=$t->nonPublic(\dataphyre\templating::class);
	$t->same('0:A;1:B;', $templatingParsingInternals->invokeWithArguments('parse_loops', [
		'{{loopitems}}{{loop.index}}:{{name}};{{endloop}}',
		['items'=>[['name'=>'A'], ['name'=>'B']]],
	]));
	$t->same('', $templatingParsingInternals->invokeWithArguments('parse_loops', ['{{loopmissing}}x{{endloop}}', ['missing'=>'scalar']]));

	$t->same('yes||', $templatingParsingInternals->invokeWithArguments('parse_conditionals', [
		'{{ifready}}yes{{endif}}|{{ifoff}}no{{endif}}|{{ifmissing}}no{{endif}}',
		['ready'=>true, 'off'=>false],
	]));
	$t->same('yes|', $templatingParsingInternals->invokeWithArguments('parse_inline_conditionals', [
		'{{if user.active && user.age >= 18}}yes{{endif}}|{{if false}}no{{endif}}',
		['user'=>['active'=>true, 'age'=>37]],
	]));

	$t->isFalse($templatingParsingInternals->invokeWithArguments('evaluate_condition', ['', []]));
	$t->isTrue($templatingParsingInternals->invokeWithArguments('evaluate_condition', ['false || true', []]));
	$t->isFalse($templatingParsingInternals->invokeWithArguments('evaluate_condition', ['true && false', []]));
	$t->isTrue($templatingParsingInternals->invokeWithArguments('evaluate_condition', ['true && true', []]));
	$t->isFalse($templatingParsingInternals->invokeWithArguments('evaluate_condition', ['false || false', []]));

	$t->isTrue($templatingParsingInternals->invokeWithArguments('evaluate_condition_atom', ['(((true)))', []]));
	$t->isFalse($templatingParsingInternals->invokeWithArguments('evaluate_condition_atom', ['()', []]));
	$t->isTrue($templatingParsingInternals->invokeWithArguments('evaluate_condition_atom', ['!false', []]));
	foreach([
		'1 === 1'=>true,
		'1 !== 2'=>true,
		'1 == "1"'=>true,
		'1 != 2'=>true,
		'2 >= 1'=>true,
		'1 <= 2'=>true,
		'2 > 1'=>true,
		'1 < 2'=>true,
	] as $expression=>$expected){
		$t->same($expected, $templatingParsingInternals->invokeWithArguments('evaluate_condition_atom', [$expression, []]));
	}
	$t->isTrue($templatingParsingInternals->invokeWithArguments('evaluate_condition_atom', ['value', ['value'=>'present']]));

	$t->same(null, $templatingParsingInternals->invokeWithArguments('condition_value', ['', []]));
	$t->same("it's", $templatingParsingInternals->invokeWithArguments('condition_value', ["'it\\'s'", []]));
	$t->same('quoted', $templatingParsingInternals->invokeWithArguments('condition_value', ['"quoted"', []]));
	$t->isTrue($templatingParsingInternals->invokeWithArguments('condition_value', ['TRUE', []]));
	$t->isFalse($templatingParsingInternals->invokeWithArguments('condition_value', ['FALSE', []]));
	$t->same(null, $templatingParsingInternals->invokeWithArguments('condition_value', ['NULL', []]));
	$t->same(12, $templatingParsingInternals->invokeWithArguments('condition_value', ['12', []]));
	$t->same(1.5, $templatingParsingInternals->invokeWithArguments('condition_value', ['1.5', []]));
	$t->same('exact', $templatingParsingInternals->invokeWithArguments('condition_value', ['profile.name', ['profile.name'=>'exact', 'profile'=>['name'=>'nested']]]));
	$t->same('nested', $templatingParsingInternals->invokeWithArguments('condition_value', ['profile.name', ['profile'=>['name'=>'nested']]]));
	$t->same(null, $templatingParsingInternals->invokeWithArguments('condition_value', ['profile.missing', ['profile'=>'scalar']]));

	$advanced=$templatingParsingInternals->invokeWithArguments('parse_advanced_conditionals', [
		'{{if true}}first{{elseif false}}second{{else}}third{{endif}}|'.
		'{{if false}}first{{elseif true}}second{{else}}third{{endif}}|'.
		'{{if false}}first{{elseif false}}second{{else}}third{{endif}}|'.
		'{{if false}}removed{{endif}}',
		[],
	]);
	$t->same('first|second|third|', $advanced);
})->tag('templating', 'coverage')->group('framework-coverage');
