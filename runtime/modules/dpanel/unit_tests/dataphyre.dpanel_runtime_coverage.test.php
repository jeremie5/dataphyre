<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	use Dataphyre\Test\TestState;

	function dpanel_runtime_value(string $key, mixed $default=null): mixed {
		$state=TestState::channelIfActive('dpanel.runtime');
		return $state===null ? $default : $state->get($key, $default);
	}

	function defined(string $constant): bool {
		static $runModeProbe=true;
		if($constant==='RUN_MODE' && $runModeProbe){
			$runModeProbe=false;
			return false;
		}
		return !in_array($constant, dpanel_runtime_value('undefined_constants', []), true) && \defined($constant);
	}

	function define(string $constant, mixed $value, bool $caseInsensitive=false): bool {
		if($constant==='RUN_MODE' && \defined('RUN_MODE')){
			return true;
		}
		return \define($constant, $value, $caseInsensitive);
	}

	function ini_get(string $option): string|false {
		return $option==='memory_limit' ? (string)dpanel_runtime_value('memory_limit', \ini_get($option)) : \ini_get($option);
	}

	function memory_get_usage(bool $realUsage=false): int {
		$state=TestState::channelIfActive('dpanel.runtime');
		return $state===null
			? \memory_get_usage($realUsage)
			: (int)$state->shift('memory_usage_values', \memory_get_usage($realUsage));
	}

	function function_exists(string $function): bool {
		return !in_array($function, dpanel_runtime_value('missing_functions', []), true) && \function_exists($function);
	}

	function strpos(string $haystack, string $needle, int $offset=0): int|false {
		if(dpanel_runtime_value('bypass_prefixed_root_markers', false) && str_starts_with($haystack, 'common/dataphyre/')){
			return false;
		}
		return \strpos($haystack, $needle, $offset);
	}

	function is_file(string $path): bool {
		$states=dpanel_runtime_value('is_file', []);
		return array_key_exists($path, $states) ? (bool)$states[$path] : \is_file($path);
	}

	function file_exists(string $path): bool {
		$states=dpanel_runtime_value('file_exists', []);
		return array_key_exists($path, $states) ? (bool)$states[$path] : \file_exists($path);
	}

	function file_get_contents(string $path, mixed ...$arguments): string|false {
		$reads=dpanel_runtime_value('file_reads', []);
		return array_key_exists($path, $reads) ? $reads[$path] : \file_get_contents($path, ...$arguments);
	}

	function date(string $format, ?int $timestamp=null): string {
		return $timestamp===null ? \date($format) : \date($format, $timestamp);
	}

	function tracelog(mixed ...$arguments): void {
		TestState::channelIfActive('dpanel.runtime')?->append('tracelog', $arguments);
	}

	class core {
		public static function file_put_contents_forced(string $path, string $contents): int {
			$state=TestState::channel('dpanel.runtime');
			$writes=$state->get('writes', []);
			$writes[$path]=$contents;
			$state->put('writes', $writes);
			return strlen($contents);
		}
	}

	function dpanel_runtime_identity(mixed $value=null): mixed {
		return $value;
	}

	function dpanel_runtime_throwing(): never {
		throw new \RuntimeException('fixture inference failed');
	}

	class DpanelRuntimeSubject {
		public function __construct() {}
		public function instanceValue(string $value='instance'): string { return $value; }
		public static function staticValue(string $value='static'): string { return $value; }
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	function dp_module_present(string $module): array|bool {
		return \Dataphyre\Test\TestState::channelIfActive('dpanel.runtime')?->get('modules', [])[$module] ?? false;
	}

	require_once __DIR__.'/../kernel/dpanel.main.php';

	final class DpanelRuntimeResetProbe {
		public int $resets=0;
		public function reset(): void { $this->resets++; }
	}

	/** Writes a readable declarative manifest through TestKit's managed workspace. */
	function dp_dpanel_runtime_manifest(Context $t, array $definitions, string $prefix='dpanel-runtime-'): string {
		return $t->tempFile((string)json_encode($definitions, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), $prefix, null);
	}

	test('Dpanel buffers diagnostic evidence and describes memory and value shapes', static function(Context $t): void {
		$state=$t->state('dpanel.runtime', ['memory_limit'=>'-1']);
		\dataphyre\dpanel::get_verbose();
		\dataphyre\dpanel::add_verbose(null);
		\dataphyre\dpanel::add_verbose([['message'=>'first'], ['message'=>'second']]);
		$t->count(2, \dataphyre\dpanel::get_verbose(false));
		$t->count(2, \dataphyre\dpanel::get_verbose());
		$t->same([], \dataphyre\dpanel::get_verbose());

		\dataphyre\dpanel::tracelog_bypass(text:42);
		\dataphyre\dpanel::tracelog_bypass('fixture.php', 10, 'Fixture', 'run', 'captured', 'info', ['id'=>1]);
		$t->count(1, \dataphyre\dpanel::get_tracelog(false));
		$t->same('captured', \dataphyre\dpanel::get_tracelog()[0]['message']);
		$t->same([], \dataphyre\dpanel::get_tracelog());

		$internals=$t->nonPublic(\dataphyre\dpanel::class);
		$t->same(-1, $internals->invoke('memory_limit_bytes'));
		$t->isFalse($internals->invoke('memory_near_limit'));
		foreach(['1G'=>1073741824, '2M'=>2097152, '3K'=>3072, '512'=>512] as $value=>$bytes){
			$state->put('memory_limit', $value);
			$t->same($bytes, $internals->invoke('memory_limit_bytes'));
		}
		$t->same('unlimited', $internals->invoke('memory_label', -1));
		$t->same('2 MB', $internals->invoke('memory_label', 2*1048576));
		$t->same('2 KB', $internals->invoke('memory_label', 2048));
		$t->same('12 B', $internals->invoke('memory_label', 12));
		$state->merge(['memory_limit'=>'16M', 'memory_usage_values'=>[16*1048576, 4*1048576]]);
		$t->isTrue($internals->invoke('memory_near_limit'));
		$t->isFalse($internals->invoke('memory_near_limit'));
		$internals->invoke('add_memory_skip', 'fixture.json', ['name'=>'memory boundary']);
		$skip=\dataphyre\dpanel::get_verbose()[0];
		$t->same('memory boundary', $skip['test_name']);
		$t->contains('near the active limit', $skip['message']);

		$resource=fopen('php://memory', 'r');
		$t->same(['array', ['int', 'string']], \dataphyre\dpanel::get_type_shape([1, 'ready', 1]));
		$t->same(stdClass::class, \dataphyre\dpanel::get_type_shape(new stdClass()));
		$t->same('null', \dataphyre\dpanel::get_type_shape(null));
		$t->same('true', \dataphyre\dpanel::get_type_shape(true));
		$t->same('string', \dataphyre\dpanel::get_type_shape('ready'));
		$t->same('int', \dataphyre\dpanel::get_type_shape(1));
		$t->same('float', \dataphyre\dpanel::get_type_shape(1.5));
		$t->same('unknown', \dataphyre\dpanel::get_type_shape($resource));
		fclose($resource);
		$t->same(md5(serialize(['array', ['int']])), \dataphyre\dpanel::get_type_shape_signature(['array', ['int']]));
	})->tag('dpanel','diagnostics','memory','shapes','coverage')->group('framework-coverage');

	test('Dpanel dynamic tests persist stable shapes and metadata without touching project state', static function(Context $t): void {
		$state=$t->state('dpanel.runtime', ['writes'=>[]]);
		$shape=\dataphyre\dpanel::get_type_shape('ready');
		$signature=\dataphyre\dpanel::get_type_shape_signature($shape);
		$base=ROOTPATH['dataphyre'].'unit_tests/dynamic/.dataphyre\\dpanel_runtime_identity/'.$signature;
		$metaPath=$base.'.meta.json';
		$testPath=$base.'.json';
		$state->merge([
			'is_file'=>[$metaPath=>true],
			'file_reads'=>[$metaPath=>'{' . '"calls":2' . '}'],
			'file_exists'=>[$testPath=>false],
		]);
		\dataphyre\dpanel::generate_dynamic_unit_test(__FILE__, '10', null, 'dataphyre\\dpanel_runtime_identity', ['ready'], 'ready');
		$writes=$state->get('writes');
		$t->hasKey($metaPath, $writes);
		$t->hasKey($testPath, $writes);
		$t->same(3, json_decode($writes[$metaPath], true)['calls']);

		$state->merge(['writes'=>[], 'file_exists'=>[$testPath=>true]]);
		\dataphyre\dpanel::generate_dynamic_unit_test(__FILE__, '11', null, 'dataphyre\\dpanel_runtime_identity', ['ready'], 'ready');
		$t->same([$metaPath], array_keys($state->get('writes')));

		$state->merge(['writes'=>[], 'is_file'=>[], 'file_reads'=>[], 'file_exists'=>[]]);
		\dataphyre\dpanel::generate_dynamic_unit_test(__FILE__, '12', null, 'dataphyre\\dpanel_runtime_identity', ['inferred']);
		$t->count(2, $state->get('writes'));
		\dataphyre\dpanel::generate_dynamic_unit_test(__FILE__, '13', null, 'dataphyre\\dpanel_runtime_throwing', []);
		$t->notEmpty($state->get('tracelog', []));
		\dataphyre\dpanel::generate_dynamic_unit_test(function:null, arguments:[]);
	})->tag('dpanel','dynamic-tests','persistence','coverage')->group('framework-coverage');

	test('Dpanel reports disabled unreadable malformed and memory-bounded manifests', static function(Context $t): void {
		$state=$t->state('dpanel.runtime', ['memory_limit'=>'-1']);
		$valid=dp_dpanel_runtime_manifest($t, [[
			'name'=>'portable identity', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>['ready'], 'expected'=>'ready',
		]]);
		\dataphyre\dpanel::$run_unit_tests=false;
		$t->isTrue(\dataphyre\dpanel::unit_test($valid));
		$t->contains('disabled unit-test execution', \dataphyre\dpanel::get_verbose()[0]['message']);
		\dataphyre\dpanel::$run_unit_tests=true;

		$unreadable=$t->tempFile('[]', 'dpanel-unreadable-', null);
		$state->put('file_reads', [$unreadable=>false]);
		$t->isFalse(\dataphyre\dpanel::unit_test($unreadable));
		$t->same('JSON file unreadable.', \dataphyre\dpanel::get_verbose()[0]['message']);

		$state->put('file_reads', []);
		$invalid=$t->tempFile('{invalid', 'dpanel-invalid-', null);
		$t->isFalse(\dataphyre\dpanel::unit_test($invalid));
		$t->contains('Invalid JSON format', \dataphyre\dpanel::get_verbose()[0]['message']);
		$t->isFalse(\dataphyre\dpanel::unit_test($t->tempFile('', 'dpanel-empty-', null)));
		$t->isTrue(is_bool(\dataphyre\dpanel::unit_test('/dataphyre/runtime/modules/dpanel/unit_tests/dataphyre.dpanel.resolution.json')));
		\dataphyre\dpanel::get_verbose();

		$state->merge(['memory_limit'=>'16M', 'memory_usage_values'=>[16*1048576]]);
		$t->isFalse(\dataphyre\dpanel::unit_test($valid));
		$t->contains('near the active limit', \dataphyre\dpanel::get_verbose()[0]['message']);
	})->tag('dpanel','manifest','failure-modes','coverage')->group('framework-coverage');

	test('Dpanel manifest expectations cover ranges regexes types arrays alternatives and mismatches', static function(Context $t): void {
		$t->state('dpanel.runtime', ['memory_limit'=>'-1']);
		$manifest=dp_dpanel_runtime_manifest($t, [
			['name'=>'range', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>[5], 'expected'=>['min'=>4, 'max'=>6]],
			['name'=>'regex', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>['alpha'], 'expected'=>'regex:/^a/'],
			['name'=>'integer type', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>[5], 'expected'=>'int'],
			['name'=>'false shape', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>[false], 'expected'=>'false'],
			['name'=>'null shape', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>[null], 'expected'=>'null'],
			['name'=>'object class', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>[['fixture'=>'dpanel_runtime_object']], 'expected'=>stdClass::class],
			['name'=>'nested array structure', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>[[[1], ['ready']]], 'expected'=>['array', [['array', ['integer']], ['array', ['string']]]]],
			['name'=>'flat array structure', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>[[1, 2]], 'expected'=>['array', ['integer']]],
			['name'=>'array structure rejects a scalar', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>['not-an-array'], 'expected'=>['array', ['integer']]],
			['name'=>'array structure rejects an element type', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>[['not-an-integer']], 'expected'=>['array', ['integer']]],
			['name'=>'numeric equivalence', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>[5], 'expected'=>5.0],
			['name'=>'record list', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>[[['id'=>1], ['id'=>2]]], 'expected'=>[['id'=>1], ['id'=>2]]],
			['name'=>'alternative outcomes', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>['ready'], 'expected'=>['missing', 'ready']],
			['name'=>'visible mismatch', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>['actual'], 'expected'=>'expected'],
		]);
		$t->isFalse(\dataphyre\dpanel::unit_test($manifest));
		$messages=array_column(\dataphyre\dpanel::get_verbose(), 'message');
		$t->isTrue(count(array_filter($messages, static fn(string $message): bool=>str_contains($message, 'passed in')))>=10);
		$t->isTrue(count(array_filter($messages, static fn(string $message): bool=>str_contains($message, 'expected one of')))===3);
	})->tag('dpanel','manifest','expectations','coverage')->group('framework-coverage');

	function dpanel_runtime_object(): stdClass { return new stdClass(); }

	test('Dpanel manifest execution covers dependencies files classes constructors and call styles', static function(Context $t): void {
		$t->state('dpanel.runtime', ['memory_limit'=>'-1']);
		if(!\defined('DPANEL_RUNTIME_CONSTANT')){
			\define('DPANEL_RUNTIME_CONSTANT', true);
		}
		$t->global('DPANEL_RUNTIME_GLOBAL')->replace('ready');
		$userAgent=new DpanelRuntimeResetProbe();
		$ipAddress=new DpanelRuntimeResetProbe();
		if(!\defined('REQUEST_USER_AGENT')){
			\define('REQUEST_USER_AGENT', $userAgent);
		}
		if(!\defined('REQUEST_IP_ADDRESS')){
			\define('REQUEST_IP_ADDRESS', $ipAddress);
		}

		$definitions=[
			['name'=>'invalid structure', 'function'=>'dataphyre\\dpanel_runtime_identity', 'expected'=>'ready'],
			['name'=>'request state reset', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>['ready'], 'expected'=>'ready', 'server'=>['HTTP_USER_AGENT'=>'changed'], 'session'=>['account'=>1], 'globals'=>['DPANEL_MANIFEST_VALUE'=>'ready']],
			['name'=>'readable fixture file', 'file'=>__FILE__, 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>['ready'], 'expected'=>'ready'],
			['name'=>'missing fixture file', 'file'=>'missing-dpanel-runtime-fixture.php', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>['ready'], 'expected'=>'ready'],
			['name'=>'dependency success', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>['ready'], 'expected'=>'ready', 'dependencies'=>[
				'function'=>['dataphyre\\dpanel_runtime_identity', 'dataphyre\\DpanelRuntimeSubject::staticValue'],
				'class'=>[stdClass::class], 'constant'=>['DPANEL_RUNTIME_CONSTANT'], 'global_variable'=>['DPANEL_RUNTIME_GLOBAL'],
			]],
			['name'=>'class method dependency fallback', 'class'=>\dataphyre\DpanelRuntimeSubject::class, 'static_method'=>true, 'function'=>'staticValue', 'args'=>['ready'], 'expected'=>'ready', 'dependencies'=>['function'=>['staticValue']]],
			['name'=>'custom dependency failure', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>['ready'], 'expected'=>'ready', 'dependencies'=>['function'=>[['dpanel_missing_dependency'=>'Dependency is intentionally absent.']]]],
			['name'=>'default dependency failure', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>['ready'], 'expected'=>'ready', 'dependencies'=>['class'=>['DpanelMissingClass']]],
			['name'=>'missing class', 'class'=>'DpanelMissingClass', 'function'=>'run', 'args'=>[], 'expected'=>'ready'],
			['name'=>'static class method', 'class'=>\dataphyre\DpanelRuntimeSubject::class, 'static_method'=>true, 'function'=>'staticValue', 'args'=>['static'], 'expected'=>'static'],
			['name'=>'instance class method', 'class'=>\dataphyre\DpanelRuntimeSubject::class, 'function'=>'instanceValue', 'args'=>['instance'], 'expected'=>'instance'],
			['name'=>'constructor method', 'class'=>\dataphyre\DpanelRuntimeSubject::class, 'function'=>'__construct', 'args'=>[], 'expected'=>\dataphyre\DpanelRuntimeSubject::class],
			['name'=>'missing instance method', 'class'=>\dataphyre\DpanelRuntimeSubject::class, 'function'=>'missing', 'args'=>[], 'expected'=>'ready'],
			['name'=>'static string call', 'function'=>'dataphyre\\DpanelRuntimeSubject::staticValue', 'args'=>['string-call'], 'expected'=>'string-call'],
			['name'=>'missing static string call', 'function'=>'DpanelMissingClass::missing', 'args'=>[], 'expected'=>'ready'],
			['name'=>'missing function', 'function'=>'dpanel_missing_function', 'args'=>[], 'expected'=>'ready'],
			['name'=>'duplicate one', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>['duplicate'], 'expected'=>'duplicate'],
			['name'=>'duplicate two', 'function'=>'dataphyre\\dpanel_runtime_identity', 'args'=>['duplicate'], 'expected'=>'duplicate'],
		];
		$manifest=dp_dpanel_runtime_manifest($t, $definitions);
		$t->isFalse(\dataphyre\dpanel::unit_test($manifest));
		$verbose=\dataphyre\dpanel::get_verbose();
		$types=array_count_values(array_column($verbose, 'type'));
		$t->greaterThanOrEqual(1, $types['unit_test_skip'] ?? 0);
		$t->same(1, $userAgent->resets);
		$t->same(1, $ipAddress->resets);

		$internals=$t->nonPublic(\dataphyre\dpanel::class);
		$internals->invoke('report_unit_test_dedupe', 'runtime');
		$dedupe=\dataphyre\dpanel::get_verbose()[0];
		$t->same(1, $dedupe['duplicates']);
	})->tag('dpanel','manifest','dependencies','callables','coverage')->group('framework-coverage');

	test('Dpanel stops before constructing an instance when memory crosses the safety reserve', static function(Context $t): void {
		$t->state('dpanel.runtime', [
			'memory_limit'=>'16M',
			'memory_usage_values'=>[0, 16*1048576],
		]);
		$manifest=dp_dpanel_runtime_manifest($t, [[
			'name'=>'instance memory boundary',
			'class'=>\dataphyre\DpanelRuntimeSubject::class,
			'function'=>'instanceValue',
			'args'=>['ready'],
			'expected'=>'ready',
		]]);
		$t->isFalse(\dataphyre\dpanel::unit_test($manifest));
		$t->contains('near the active limit', \dataphyre\dpanel::get_verbose()[0]['message']);
	})->tag('dpanel','manifest','memory','construction','coverage')->group('framework-coverage');

	test('Dpanel helper inventories canonicalize identities and resolve every supported root form', static function(Context $t): void {
		$state=$t->state('dpanel.runtime', ['memory_limit'=>'-1']);
		$internals=$t->nonPublic(\dataphyre\dpanel::class);
		\dataphyre\dpanel::$load_module_entrypoints=false;
		$internals->invoke('ensure_unit_test_dependency', 'function', 'locale');
		$internals->invoke('ensure_unit_test_dependency', 'class', 'Unrelated');
		$t->isFalse($internals->invoke('unit_test_class_method_dependency_exists', 'sql::db_insert'));
		$t->isTrue($internals->invoke('unit_test_class_method_dependency_exists', \dataphyre\DpanelRuntimeSubject::class.'::staticValue'));
		$t->isFalse($internals->invoke('unit_test_class_method_dependency_exists', 'MissingClass::missing'));

		$first=['function'=>'ready', 'args'=>['b'=>2, 'a'=>1], 'expected'=>['z'=>3, 'a'=>1]];
		$second=['expected'=>['a'=>1, 'z'=>3], 'args'=>['a'=>1, 'b'=>2], 'function'=>'ready'];
		$t->same(
			$internals->invoke('unit_test_signature', $first, 'C:\\fixture.php'),
			$internals->invoke('unit_test_signature', $second, 'C:/fixture.php')
		);
		$state->put('missing_functions', ['array_is_list']);
		$t->isTrue($internals->invoke('array_is_list_compatible', ['a', 'b']));
		$t->isFalse($internals->invoke('array_is_list_compatible', [1=>'a']));
		$state->put('missing_functions', []);

		$internals->invoke('reset_unit_test_dedupe');
		$internals->invoke('report_unit_test_dedupe', 'empty');
		$t->same('', $internals->invoke('resolve_unit_test_case_file', ''));
		$t->contains('/common/fixture.php', str_replace('\\', '/', $internals->invoke('resolve_unit_test_case_file', '/common/fixture.php')));
		$t->same('C:/fixture.php', str_replace('\\', '/', $internals->invoke('resolve_unit_test_case_file', 'C:/fixture.php')));
		$state->put('bypass_prefixed_root_markers', true);
		$t->contains('/runtime/modules/core/kernel/core.main.php', str_replace('\\', '/', $internals->invoke('resolve_unit_test_case_file', 'common/dataphyre/runtime/modules/core/kernel/core.main.php')));
		$t->contains('/modules/core/kernel/core.main.php', str_replace('\\', '/', $internals->invoke('resolve_unit_test_case_file', 'common/dataphyre/'.'modules/core/kernel/core.main.php')));
		$state->put('bypass_prefixed_root_markers', false);
		$t->contains('/common/fixture.php', str_replace('\\', '/', $internals->invoke('resolve_unit_test_case_file', 'common/fixture.php')));
		$t->contains('/applications/example/file.php', str_replace('\\', '/', $internals->invoke('resolve_unit_test_case_file', 'applications/example/file.php')));
	})->tag('dpanel','inventory','paths','coverage')->group('framework-coverage');
}
