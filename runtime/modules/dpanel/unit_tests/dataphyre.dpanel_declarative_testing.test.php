<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!function_exists('dataphyre\\tracelog') || !function_exists('dataphyre\\dp_module_present')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
if(!function_exists(__NAMESPACE__.'\\tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
if(!function_exists(__NAMESPACE__.'\\dp_module_present')){
	function dp_module_present(string $module): array|bool {
		return \Dataphyre\Test\TestState::channel('dpanel.declarative')->get('module_files', [])[$module] ?? false;
	}
}
PHP);
}

require_once __DIR__.'/../kernel/dpanel.main.php';

function dp_dpanel_declarative_join(string $left, string $right): string {
	return $left.'-'.$right;
}

function dp_dpanel_declarative_identity(mixed $value): mixed {
	return $value;
}

function dp_dpanel_declarative_constant(): string {
	return 'portable';
}

function dp_dpanel_declarative_slow(): string {
	usleep(2_000);
	return 'complete';
}

test('Dpanel declarative scalar assertions compose strict portable operators', static function(Context $t): void {
	$dpanel=$t->nonPublic(\dataphyre\dpanel::class);
	$t->isTrue($dpanel->invoke('unit_test_assertion_matches', 'alpha', [
		'type'=>'string',
		'same'=>'alpha',
		'not_same'=>'beta',
		'not_empty'=>true,
		'matches'=>'/^a.+a$/',
	]));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', 'alpha', ['same'=>'beta']));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', 'alpha', ['not_same'=>'alpha']));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', '', ['not_empty'=>true]));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', 'alpha', ['matches'=>'/^z/']));
	$t->isTrue($dpanel->invoke('unit_test_assertion_matches', new stdClass(), ['type'=>stdClass::class]));
	$t->throws(static fn()=>$dpanel->invoke('unit_test_assertion_matches', 'alpha', ['executable'=>'never']), InvalidArgumentException::class);
})->tag('dpanel','declarative-tests','assertions')->group('framework-coverage');

test('Dpanel declarative collection assertions cover containment counts and exact keys', static function(Context $t): void {
	$dpanel=$t->nonPublic(\dataphyre\dpanel::class);
	$t->isTrue($dpanel->invoke('unit_test_assertion_matches', ['alpha','beta'], [
		'type'=>'array',
		'contains'=>['alpha','beta'],
		'not_contains'=>['gamma'],
		'count'=>['min'=>2,'max'=>2,'same'=>2],
	]));
	$t->isTrue($dpanel->invoke('unit_test_assertion_matches', ['alpha'=>1,'beta'=>2], ['keys'=>['alpha','beta'],'count'=>2]));
	$t->isTrue($dpanel->invoke('unit_test_assertion_matches', '<b>safe</b>', ['contains'=>['<b>'],'not_contains'=>['<script']]));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', ['alpha'], ['contains'=>['missing']]));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', ['alpha'], ['not_contains'=>['alpha']]));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', ['alpha'], ['count'=>2]));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', 'not-countable', ['count'=>1]));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', ['alpha'], ['count'=>['min'=>2]]));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', ['alpha','beta'], ['count'=>['max'=>1]]));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', ['alpha'], ['count'=>['same'=>2]]));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', ['alpha'=>1], ['keys'=>['beta']]));
	$t->throws(static fn()=>$dpanel->invoke('unit_test_assertion_matches', ['alpha'], ['count'=>['approximately'=>1]]), InvalidArgumentException::class);
	$t->throws(static fn()=>$dpanel->invoke('unit_test_assertion_matches', ['alpha'], ['count'=>'one']), InvalidArgumentException::class);
})->tag('dpanel','declarative-tests','assertions')->group('framework-coverage');

test('Dpanel declarative paths existence some and comparisons describe nested contracts', static function(Context $t): void {
	$dpanel=$t->nonPublic(\dataphyre\dpanel::class);
	$result=[
		'after'=>['panel.orders.update'=>true],
		'nullable'=>null,
		'findings'=>[['type'=>'info'],['type'=>'conflicting_rule']],
		'high'=>5,
		'low'=>3,
	];
	$t->isTrue($dpanel->invoke('unit_test_assertion_matches', $result, [
		'paths'=>[
			'after/panel.orders.update'=>['same'=>true],
			'nullable'=>['exists'=>true,'same'=>null],
			'absent'=>['exists'=>false],
			'findings'=>['some'=>['paths'=>['type'=>['same'=>'conflicting_rule']]]],
		],
		'compare'=>[
			['left'=>'high','operator'=>'greater_than','right'=>'low'],
			['left'=>'high','operator'=>'greater_than_or_equal','right'=>'high'],
			['left'=>'low','operator'=>'less_than','right'=>'high'],
			['left'=>'low','operator'=>'less_than_or_equal','right'=>'low'],
			['left'=>'high','operator'=>'same','right'=>'high'],
			['left'=>'high','operator'=>'not_same','right'=>'low'],
		],
	]));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', $result, ['paths'=>['absent'=>['exists'=>true]]]));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', $result, ['paths'=>['findings'=>['some'=>['same'=>'missing']]]]));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', $result, ['compare'=>[['left'=>'low','operator'=>'greater_than','right'=>'high']]]));
	$t->throws(static fn()=>$dpanel->invoke('unit_test_assertion_matches', $result, ['compare'=>[['left'=>'high','operator'=>'execute','right'=>'low']]]), InvalidArgumentException::class);
})->tag('dpanel','declarative-tests','assertions')->group('framework-coverage');

test('Dpanel declarative assertions reject malformed nested contracts and missing comparison paths', static function(Context $t): void {
	$dpanel=$t->nonPublic(\dataphyre\dpanel::class);
	$object=(object)['nested'=>(object)['value'=>7]];
	$t->isTrue($dpanel->invoke('unit_test_assertion_matches', $object, [
		'paths'=>[
			'$'=>['type'=>'object'],
			'nested/value'=>['same'=>7],
		],
	]));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', 7, ['type'=>'string']));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', 'scalar', ['some'=>['same'=>'scalar']]));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', ['scalar'], ['some'=>'scalar']));
	$t->isFalse($dpanel->invoke('unit_test_assertion_matches', ['left'=>1], [
		'compare'=>['left'=>'left','operator'=>'same','right'=>'missing'],
	]));
	$t->throws(static fn()=>$dpanel->invoke('unit_test_assertion_matches', [], ['paths'=>'invalid']), InvalidArgumentException::class);
	$t->throws(static fn()=>$dpanel->invoke('unit_test_assertion_matches', [], ['paths'=>['child'=>'invalid']]), InvalidArgumentException::class);
	$t->throws(static fn()=>$dpanel->invoke('unit_test_assertion_matches', [], ['compare'=>'invalid']), InvalidArgumentException::class);
	$t->throws(static fn()=>$dpanel->invoke('unit_test_assertion_matches', [], ['compare'=>[['left'=>'missing']]]), InvalidArgumentException::class);
})->tag('dpanel','declarative-tests','assertions','validation')->group('framework-coverage');

test('Dpanel resolves module runtime and committed fixture declarations without source strings', static function(Context $t): void {
	$dpanel=$t->nonPublic(\dataphyre\dpanel::class);
	$t->state('dpanel.declarative', ['module_files'=>['dpanel'=>[__FILE__]]]);
	$moduleFile=$dpanel->invoke('resolve_unit_test_file_definition', ['module'=>'dpanel']);
	$t->same(basename(__FILE__), basename($moduleFile));
	$t->isTrue(is_readable($moduleFile));
	$runtimeFile=$dpanel->invoke('resolve_unit_test_file_definition', ['runtime'=>'modules/dpanel/kernel/dpanel.main.php']);
	$t->isTrue(str_ends_with(str_replace('\\','/',$runtimeFile), '/modules/dpanel/kernel/dpanel.main.php'));
	$t->isTrue(is_readable($runtimeFile));
	$relativeRuntime=$dpanel->invoke('resolve_unit_test_case_file', 'dataphyre/runtime/modules/dpanel/kernel/dpanel.main.php');
	$t->isTrue(str_ends_with(str_replace('\\','/',$relativeRuntime), '/modules/dpanel/kernel/dpanel.main.php'));
	$t->isTrue(is_readable($relativeRuntime));
	$relativeFramework=$dpanel->invoke('resolve_unit_test_case_file', 'dataphyre/dataphyre.manifest.json');
	$t->isTrue(str_ends_with(str_replace('\\','/',$relativeFramework), '/dataphyre.manifest.json'));
	$t->isTrue(is_readable($relativeFramework));
	$t->same('left-right', $dpanel->invoke('resolve_unit_test_value', [
		'fixture'=>['call'=>'dp_dpanel_declarative_join','args'=>['left','right']],
	], __FILE__, [], 'args'));
	$t->throws(static fn()=>$dpanel->invoke('resolve_unit_test_value', ['custom_script'=>'return 1;'], __FILE__, [], 'args'), InvalidArgumentException::class);
	$t->throws(static fn()=>$dpanel->invoke('resolve_unit_test_file_definition', ['runtime'=>'../outside.php']), InvalidArgumentException::class);
})->tag('dpanel','declarative-tests','fixtures')->group('framework-coverage');

test('Dpanel fixture declarations validate their callable file options and argument shape', static function(Context $t): void {
	$dpanel=$t->nonPublic(\dataphyre\dpanel::class);
	$t->same('portable', $dpanel->invoke('resolve_unit_test_value', [
		'fixture'=>'dp_dpanel_declarative_constant',
	], __FILE__, [], 'args'));
	$t->same('with-file', $dpanel->invoke('resolve_unit_test_value', [
		'fixture'=>[
			'call'=>'dp_dpanel_declarative_identity',
			'args'=>['with-file'],
			'file'=>__FILE__,
		],
	], __FILE__, [], 'args'));
	$staticFile=$dpanel->invoke('resolve_unit_test_file_definition', __FILE__);
	$t->same(basename(__FILE__), basename($staticFile));
	$t->isTrue(is_readable($staticFile));

	foreach([null, [], ['args'=>[]]] as $invalid){
		$t->throws(
			static fn()=>$dpanel->invoke('resolve_unit_test_fixture', $invalid, __FILE__, [], 'args'),
			InvalidArgumentException::class
		);
	}
	$t->throws(static fn()=>$dpanel->invoke('resolve_unit_test_fixture', [
		'call'=>'dp_dpanel_declarative_identity',
		'unknown'=>true,
	], __FILE__, [], 'args'), InvalidArgumentException::class);
	$t->throws(static fn()=>$dpanel->invoke('resolve_unit_test_fixture', [
		'call'=>'dp_dpanel_declarative_identity',
		'file'=>'missing-dpanel-fixture.php',
	], __FILE__, [], 'args'), RuntimeException::class);
	$t->throws(static fn()=>$dpanel->invoke('resolve_unit_test_fixture', [
		'call'=>'dp_dpanel_fixture_that_does_not_exist',
	], __FILE__, [], 'args'), RuntimeException::class);
	$t->throws(static fn()=>$dpanel->invoke('resolve_unit_test_fixture', [
		'call'=>'dp_dpanel_declarative_identity',
		'args'=>['named'=>'not-a-list'],
	], __FILE__, [], 'args'), InvalidArgumentException::class);
	$t->throws(static fn()=>$dpanel->invoke('resolve_unit_test_file_definition', []), InvalidArgumentException::class);
	$t->throws(static fn()=>$dpanel->invoke('resolve_unit_test_file_definition', ['module'=>'../unsafe']), InvalidArgumentException::class);
	$t->throws(static fn()=>$dpanel->invoke('resolve_unit_test_file_definition', ['unknown'=>'value']), InvalidArgumentException::class);
})->tag('dpanel','declarative-tests','fixtures','validation')->group('framework-coverage');

test('Dpanel executes declarative JSON assertions and fixture arguments end to end', static function(Context $t): void {
	$manifest=$t->tempFile((string)json_encode([[
		'name'=>'declarative contract integration',
		'function'=>'dp_dpanel_declarative_identity',
		'args'=>[[
			'fixture'=>['call'=>'dp_dpanel_declarative_join','args'=>['portable','contract']],
		]],
		'expected'=>['assert'=>['type'=>'string','same'=>'portable-contract','contains'=>['portable'],'not_contains'=>['eval']]],
	]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), 'dpanel-declarative-', null);
	\dataphyre\dpanel::$run_unit_tests=true;
	$t->isTrue(\dataphyre\dpanel::unit_test($manifest));
	$verbose=\dataphyre\dpanel::get_verbose();
	$t->isTrue((bool)($verbose[array_key_last($verbose)]['passed'] ?? false));
})->tag('dpanel','declarative-tests','integration')->group('framework-coverage');

test('Dpanel rejects legacy executable JSON fields instead of interpreting them', static function(Context $t): void {
	$manifest=$t->tempFile((string)json_encode([
		[
			'name'=>'legacy dynamic file is rejected',
			'function'=>'dp_dpanel_declarative_identity',
			'args'=>['value'],
			'expected'=>'value',
			'file_dynamic'=>'forbidden',
		],
		[
			'name'=>'legacy result script is rejected',
			'function'=>'dp_dpanel_declarative_identity',
			'args'=>['value'],
			'expected'=>['custom_script'=>'forbidden'],
		],
		[
			'name'=>'mixed assertion envelope is rejected',
			'function'=>'dp_dpanel_declarative_identity',
			'args'=>['value'],
			'expected'=>['assert'=>['same'=>'value'],'extra'=>'forbidden'],
		],
	], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), 'dpanel-legacy-fields-', null);
	\dataphyre\dpanel::$run_unit_tests=true;
	$t->isFalse(\dataphyre\dpanel::unit_test($manifest));
	$messages=array_column(\dataphyre\dpanel::get_verbose(), 'message');
	$t->contains('Dpanel JSON file_dynamic is no longer supported; use file.module, file.runtime, or a static path.', $messages);
	$t->contains('Dpanel JSON custom_script assertions are no longer supported; use the declarative assert vocabulary.', $messages);
	$t->contains('A Dpanel declarative assertion must contain only an assert operator map.', $messages);
})->tag('dpanel','declarative-tests','legacy-rejection')->group('framework-coverage');

test('Dpanel performance limits expose deterministic scheduler-grace overrides', static function(Context $t): void {
	if(!defined('DATAPHYRE_DPANEL_PERFORMANCE_GRACE_MILLIS')){
		define('DATAPHYRE_DPANEL_PERFORMANCE_GRACE_MILLIS', 0.0);
	}
	$manifest=$t->tempFile((string)json_encode([[
		'name'=>'deterministic performance limit',
		'function'=>'dp_dpanel_declarative_slow',
		'args'=>[],
		'expected'=>'complete',
		'max_millis'=>0,
	]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), 'dpanel-performance-grace-', null);
	\dataphyre\dpanel::$run_unit_tests=true;
	$t->isFalse(\dataphyre\dpanel::unit_test($manifest));
	$warnings=array_values(array_filter(
		\dataphyre\dpanel::get_verbose(),
		static fn(array $entry): bool=>($entry['type'] ?? '')==='performance_warning'
	));
	$t->count(1, $warnings);
	$t->contains('declared 0ms + 0ms scheduler grace', (string)$warnings[0]['message']);
})->tag('dpanel','declarative-tests','performance')->group('framework-coverage');
