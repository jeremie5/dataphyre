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

	function dpanel_diagnostic_value(string $key, mixed $default=null): mixed {
		$state=TestState::channelIfActive('dpanel.diagnostics');
		return $state===null ? $default : $state->get($key, $default);
	}

	function defined(string $constant): bool {
		static $runModeProbe=true;
		if($constant==='RUN_MODE' && $runModeProbe){
			$runModeProbe=false;
			return false;
		}
		return !in_array($constant, dpanel_diagnostic_value('undefined_constants', []), true) && \defined($constant);
	}

	function define(string $constant, mixed $value, bool $caseInsensitive=false): bool {
		if($constant==='RUN_MODE' && \defined('RUN_MODE')){
			return true;
		}
		return \define($constant, $value, $caseInsensitive);
	}

	function function_exists(string $function): bool {
		$state=TestState::channelIfActive('dpanel.diagnostics');
		if($state!==null){
			$sequences=$state->get('function_exists_sequences', []);
			if(isset($sequences[$function]) && is_array($sequences[$function]) && $sequences[$function]!==[]){
				$value=(bool)array_shift($sequences[$function]);
				$state->put('function_exists_sequences', $sequences);
				return $value;
			}
		}
		return !in_array($function, dpanel_diagnostic_value('missing_functions', []), true) && \function_exists($function);
	}

	function is_file(string $path): bool {
		$states=dpanel_diagnostic_value('is_file', []);
		return array_key_exists($path, $states) ? (bool)$states[$path] : \is_file($path);
	}

	function file_get_contents(string $path, mixed ...$arguments): string|false {
		$reads=dpanel_diagnostic_value('file_reads', []);
		return array_key_exists($path, $reads) ? $reads[$path] : \file_get_contents($path, ...$arguments);
	}

	function glob(string $pattern, int $flags=0): array|false {
		if(dpanel_diagnostic_value('throw_from_glob', false)){
			throw new \RuntimeException('Diagnostic fixture glob failed.');
		}
		return \glob($pattern, $flags);
	}

	function tracelog(mixed ...$arguments): void {
		TestState::channelIfActive('dpanel.diagnostics')?->append('tracelog', $arguments);
	}

	final class DpanelDiagnosticHooks {
		public static function pre_tests(): void { TestState::channel('dpanel.diagnostics')->increment('pre_tests'); }
		public static function post_tests(): void { TestState::channel('dpanel.diagnostics')->increment('post_tests'); }
		public static function tests(): void { TestState::channel('dpanel.diagnostics')->increment('tests'); }
	}
}

namespace dataphyre\dpanel_loaded {
	final class diagnostic {
		public static function tests(): void {
			\Dataphyre\Test\TestState::channel('dpanel.diagnostics')->increment('loaded_diagnostic');
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use Dataphyre\Test\TestState;
	use Dataphyre\Test\TempWorkspace;
	use function Dataphyre\Test\test;

	function dp_module_present(string $module): array|bool {
		$state=TestState::channelIfActive('dpanel.diagnostics');
		if((bool)($state?->get('throw_module_lookup', false) ?? false)){
			throw new RuntimeException('Module lookup fixture failed.');
		}
		return $state?->get('modules', [])[$module] ?? false;
	}

	function dpanel_diagnostics_identity(mixed $value=null): mixed { return $value; }

	$diagnosticRuntime=str_replace('\\', '/', __DIR__.'/fixtures/dpanel_diagnostics_runtime/');
	$diagnosticRootpath=ROOTPATH;
	$diagnosticRootpath['dataphyre']='';
	$diagnosticRootpath['common_dataphyre']=$diagnosticRuntime;
	$diagnosticRootpath['common_dataphyre_runtime']=$diagnosticRuntime;
	if(!defined('dataphyre\\ROOTPATH')){
		define('dataphyre\\ROOTPATH', $diagnosticRootpath);
	}

	require_once __DIR__.'/../kernel/dpanel.main.php';

	/** Resets Dpanel's mutable diagnostic controls for one isolated scenario. */
	function dp_dpanel_diagnostics_reset(Context $t, array $modules=[]): TestState {
		$state=$t->state('dpanel.diagnostics', ['modules'=>$modules]);
		\dataphyre\dpanel::$run_unit_tests=true;
		\dataphyre\dpanel::$load_module_entrypoints=false;
		\dataphyre\dpanel::$follow_dependency_diagnostics=true;
		\dataphyre\dpanel::$bootstrap_core_before_module=false;
		$internals=$t->nonPublic(\dataphyre\dpanel::class);
		$internals->writeProperty('diagnosing_modules', []);
		$internals->writeProperty('core_diagnostics_bootstrapped', false);
		$internals->invoke('reset_unit_test_dedupe');
		\dataphyre\dpanel::get_verbose();
		\dataphyre\dpanel::get_tracelog();
		return $state;
	}

	/** Creates a conventional module entrypoint under a managed test workspace. */
	function dp_dpanel_diagnostic_module(Context $t, string $module, string $source="<?php\n"): array {
		$workspace=$t->workspace('dpanel-diagnostic-'.$module);
		$entrypoint=$workspace->file('modules/'.$module.'/kernel/'.$module.'.main.php', $source);
		return [$workspace, $entrypoint];
	}

	/** Adds a JSON definition beside a managed module entrypoint. */
	function dp_dpanel_diagnostic_manifest(TempWorkspace $workspace, string $module, string $name, array $definitions): string {
		return $workspace->file(
			'modules/'.$module.'/unit_tests/'.$name.'.json',
			(string)json_encode($definitions, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)
		);
	}

	test('Dpanel validates PHP and resolves kernel and legacy module entrypoints', static function(Context $t): void {
		$state=dp_dpanel_diagnostics_reset($t);
		$t->isTrue(\dataphyre\dpanel::validate_php('<?php $ready=true;'));
		$error=\dataphyre\dpanel::validate_php('<?php function broken( {');
		$t->isTrue(is_string($error));
		$t->contains('Line 1:', $error);

		$internals=$t->nonPublic(\dataphyre\dpanel::class);
		$t->endsWith('/modules/core', str_replace('\\', '/', $internals->invoke('module_root_from_entrypoint', __DIR__.'/fixtures/dpanel_diagnostics_runtime/modules/core/kernel/core.main.php')));
		$t->endsWith('/modules/legacy', str_replace('\\', '/', $internals->invoke('module_root_from_entrypoint', __DIR__.'/fixtures/dpanel_diagnostics_runtime/modules/legacy/legacy.main.php')));
		$core=$internals->invoke('resolve_module_entrypoint', 'core');
		$t->isTrue(is_array($core));
		$t->same('coverage-fixture', $core[1]);
		$legacy=$internals->invoke('resolve_module_entrypoint', 'legacy');
		$t->isTrue(is_array($legacy));
		$t->endsWith('/legacy/legacy.main.php', str_replace('\\', '/', $legacy[0]));
		$t->isFalse($internals->invoke('resolve_module_entrypoint', 'missing'));
		$state->put('undefined_constants', ['ROOTPATH']);
		$t->isFalse($internals->invoke('resolve_module_entrypoint', 'core'));
	})->tag('dpanel','diagnostics','validation','resolution','coverage')->group('framework-coverage');

	test('Dpanel diagnoses reentrancy helper loss and missing modules explicitly', static function(Context $t): void {
		$state=dp_dpanel_diagnostics_reset($t);
		$internals=$t->nonPublic(\dataphyre\dpanel::class);
		$internals->writeProperty('diagnosing_modules', ['cycle'=>true]);
		$t->isTrue(\dataphyre\dpanel::diagnose_module('cycle'));

		$internals->writeProperty('diagnosing_modules', []);
		$helper=\dataphyre\ROOTPATH['common_dataphyre_runtime'].'modules/core/kernel/helper_functions.php';
		$state->merge(['missing_functions'=>['\\dp_module_present'], 'is_file'=>[$helper=>false]]);
		$t->isFalse(\dataphyre\dpanel::diagnose_module('helperless'));
		$t->same('module_lookup_unavailable', \dataphyre\dpanel::get_verbose()[0]['type']);

		$state->merge(['missing_functions'=>[], 'is_file'=>[]]);
		$t->isFalse(\dataphyre\dpanel::diagnose_module('missing'));
		$t->same('module_missing', \dataphyre\dpanel::get_verbose()[0]['type']);
	})->tag('dpanel','diagnostics','reentrancy','missing-modules','coverage')->group('framework-coverage');

	test('Dpanel procedure distinguishes unreadable invalid and intentionally skipped modules', static function(Context $t): void {
		[, $unreadable]=dp_dpanel_diagnostic_module($t, 'unreadable');
		$state=dp_dpanel_diagnostics_reset($t, ['unreadable'=>[$unreadable]]);
		$state->put('file_reads', [$unreadable=>false]);
		$t->isFalse(\dataphyre\dpanel::diagnose_module('unreadable'));
		$t->same('file_missing', \dataphyre\dpanel::get_verbose()[0]['type']);

		[, $invalid]=dp_dpanel_diagnostic_module($t, 'invalid_php', '<?php function broken( {');
		$state->merge(['modules'=>['invalid_php'=>[$invalid]], 'file_reads'=>[]]);
		$t->isFalse(\dataphyre\dpanel::diagnose_module('invalid_php'));
		$validation=\dataphyre\dpanel::get_verbose()[0];
		$t->same('php_validation_error', $validation['type']);
		$t->contains('Line 1:', $validation['error']);

		[, $skipped]=dp_dpanel_diagnostic_module($t, 'skipped');
		$state->put('modules', ['skipped'=>[$skipped]]);
		\dataphyre\dpanel::$run_unit_tests=false;
		$t->isTrue(\dataphyre\dpanel::diagnose_module('skipped'));
	})->tag('dpanel','diagnostics','procedure','validation-regression','coverage')->group('framework-coverage');

	test('Dpanel loads an entrypoint captures trace evidence and accepts a module without manifests', static function(Context $t): void {
		[, $entrypoint]=dp_dpanel_diagnostic_module($t, 'traceable', "<?php\n\\dataphyre\\dpanel::tracelog_bypass(__FILE__, __LINE__, '', '', 'Traceable module loaded.', 'info');\n");
		dp_dpanel_diagnostics_reset($t, ['traceable'=>[$entrypoint]]);
		\dataphyre\dpanel::$load_module_entrypoints=true;
		$t->isTrue(\dataphyre\dpanel::diagnose_module('traceable'));
		$verbose=\dataphyre\dpanel::get_verbose();
		$t->same('tracelog', $verbose[0]['type']);
		$t->same('Traceable module loaded.', $verbose[0]['tracelog'][0]['message']);
	})->tag('dpanel','diagnostics','entrypoint','tracelog','coverage')->group('framework-coverage');

	test('Dpanel scans sorted module manifests and reports a fully passing suite', static function(Context $t): void {
		[$workspace, $entrypoint]=dp_dpanel_diagnostic_module($t, 'passing');
		dp_dpanel_diagnostic_manifest($workspace, 'passing', 'z-pass', [[
			'name'=>'ordinary pass', 'function'=>'dpanel_diagnostics_identity', 'args'=>['ready'], 'expected'=>'ready',
		]]);
		dp_dpanel_diagnostic_manifest($workspace, 'passing', 'construct-pass', [[
			'name'=>'construct-named pass', 'function'=>'dpanel_diagnostics_identity', 'args'=>['constructed'], 'expected'=>'constructed',
		]]);
		dp_dpanel_diagnostic_manifest($workspace, 'passing', 'dpanel_mock_ignored', []);
		dp_dpanel_diagnostic_manifest($workspace, 'passing', 'unit_test', []);
		dp_dpanel_diagnostics_reset($t, ['passing'=>[$entrypoint]]);
		$t->isTrue(\dataphyre\dpanel::diagnose_module('passing'));
		$messages=array_column(\dataphyre\dpanel::get_verbose(), 'message');
		$t->contains('Unit tests passed for module passing', $messages);
	})->tag('dpanel','diagnostics','manifest-scan','passing','coverage')->group('framework-coverage');

	test('Dpanel reports aggregate module failure when any scanned manifest fails', static function(Context $t): void {
		[$workspace, $entrypoint]=dp_dpanel_diagnostic_module($t, 'failing');
		dp_dpanel_diagnostic_manifest($workspace, 'failing', 'failure', [[
			'name'=>'intentional mismatch', 'function'=>'dpanel_diagnostics_identity', 'args'=>['actual'], 'expected'=>'expected',
		]]);
		dp_dpanel_diagnostics_reset($t, ['failing'=>[$entrypoint]]);
		$t->isFalse(\dataphyre\dpanel::diagnose_module('failing'));
		$messages=array_column(\dataphyre\dpanel::get_verbose(), 'message');
		$t->contains('Unit tests failed for module failing', $messages);
	})->tag('dpanel','diagnostics','manifest-scan','failure','coverage')->group('framework-coverage');

	test('Dpanel contains unexpected scanner exceptions as module diagnostics', static function(Context $t): void {
		[$workspace, $entrypoint]=dp_dpanel_diagnostic_module($t, 'scanner_exception');
		dp_dpanel_diagnostic_manifest($workspace, 'scanner_exception', 'present', []);
		$state=dp_dpanel_diagnostics_reset($t, ['scanner_exception'=>[$entrypoint]]);
		$state->put('throw_from_glob', true);
		$t->isFalse(\dataphyre\dpanel::diagnose_module('scanner_exception'));
		$t->same('php_exception', \dataphyre\dpanel::get_verbose()[0]['type']);
	})->tag('dpanel','diagnostics','scanner','exception','coverage')->group('framework-coverage');

	test('Dpanel module diagnostic companions cover loaded core missing and throwing forms', static function(Context $t): void {
		$state=dp_dpanel_diagnostics_reset($t);
		$internals=$t->nonPublic(\dataphyre\dpanel::class);

		[$loadedWorkspace, $loadedEntrypoint]=dp_dpanel_diagnostic_module($t, 'dpanel_loaded');
		$loadedWorkspace->file('modules/dpanel_loaded/kernel/dpanel_loaded.diagnostic.php', "<?php\n");
		$internals->invoke('run_module_diagnostics', 'dpanel_loaded', $loadedEntrypoint);
		$t->same(1, $state->get('loaded_diagnostic'));

		[$coreWorkspace, $coreEntrypoint]=dp_dpanel_diagnostic_module($t, 'core');
		$coreWorkspace->file('modules/core/kernel/core.diagnostic.php', <<<'PHP'
<?php
namespace dataphyre\core;
final class diagnostic {
	public static function pre_tests(): void {}
	public static function post_tests(): void {}
}
PHP);
		$internals->invoke('run_module_diagnostics', 'core', $coreEntrypoint);

		[, $plainEntrypoint]=dp_dpanel_diagnostic_module($t, 'plain');
		$internals->invoke('run_module_diagnostics', 'plain', $plainEntrypoint);

		[$throwingWorkspace, $throwingEntrypoint]=dp_dpanel_diagnostic_module($t, 'throwing');
		$throwingWorkspace->file('modules/throwing/kernel/throwing.diagnostic.php', "<?php\nthrow new \\RuntimeException('diagnostic failed');\n");
		$internals->invoke('run_module_diagnostics', 'throwing', $throwingEntrypoint);
		$t->same('diagnostic_exception', \dataphyre\dpanel::get_verbose()[0]['type']);
	})->tag('dpanel','diagnostics','companions','coverage')->group('framework-coverage');

	test('Dpanel diagnostic class hooks distinguish core phases from module tests', static function(Context $t): void {
		$state=dp_dpanel_diagnostics_reset($t);
		$internals=$t->nonPublic(\dataphyre\dpanel::class);
		$internals->invoke('run_diagnostic_class', \dataphyre\DpanelDiagnosticHooks::class, 'core');
		$internals->invoke('run_diagnostic_class', \dataphyre\DpanelDiagnosticHooks::class, 'feature');
		$t->same(1, $state->get('pre_tests'));
		$t->same(1, $state->get('post_tests'));
		$t->same(1, $state->get('tests'));
	})->tag('dpanel','diagnostics','hooks','coverage')->group('framework-coverage');

	test('Dpanel helper bootstrap and recursive folder scans use conventional module ownership', static function(Context $t): void {
		$state=dp_dpanel_diagnostics_reset($t);
		$internals=$t->nonPublic(\dataphyre\dpanel::class);
		$helper=\dataphyre\ROOTPATH['common_dataphyre_runtime'].'modules/core/kernel/helper_functions.php';
		$state->put('function_exists_sequences', ['\\dp_module_present'=>[false, true]]);
		$t->isTrue($internals->invoke('ensure_module_helper_functions'));

		[, $localizationEntrypoint]=dp_dpanel_diagnostic_module($t, 'localization');
		$state->put('modules', ['localization'=>[$localizationEntrypoint]]);
		\dataphyre\dpanel::$load_module_entrypoints=true;
		\dataphyre\dpanel::$run_unit_tests=true;
		$internals->invoke('ensure_unit_test_dependency', 'function', 'locale');
		$t->isTrue(\dataphyre\dpanel::$run_unit_tests);
		$state->put('throw_module_lookup', true);
		$internals->invoke('ensure_unit_test_dependency', 'function', 'dataphyre\\localization');
		$t->isTrue(\dataphyre\dpanel::$run_unit_tests);
		$state->put('throw_module_lookup', false);

		[$workspace, $entrypoint]=dp_dpanel_diagnostic_module($t, 'folder_owned');
		$state->put('modules', ['folder_owned'=>[$entrypoint]]);
		\dataphyre\dpanel::$run_unit_tests=false;
		\dataphyre\dpanel::diagnose_modules_in_folder($workspace->path('modules'));
		$t->isTrue(is_file($helper));
		$t->same([], $t->nonPublic(\dataphyre\dpanel::class)->readProperty('diagnosing_modules'));
	})->tag('dpanel','diagnostics','helper-bootstrap','folder-scan','coverage')->group('framework-coverage');

	test('Dpanel bootstraps core diagnostics once before loading the requested module', static function(Context $t): void {
		[, $entrypoint]=dp_dpanel_diagnostic_module($t, 'after_core');
		dp_dpanel_diagnostics_reset($t, ['after_core'=>[$entrypoint]]);
		\dataphyre\dpanel::$bootstrap_core_before_module=true;
		\dataphyre\dpanel::$load_module_entrypoints=true;
		\dataphyre\dpanel::$run_unit_tests=false;
		$t->isTrue(\dataphyre\dpanel::diagnose_module('after_core'));
		$t->isTrue($t->nonPublic(\dataphyre\dpanel::class)->readProperty('core_diagnostics_bootstrapped'));
	})->tag('dpanel','diagnostics','core-bootstrap','coverage')->group('framework-coverage');

	test('Dpanel stops the requested diagnosis when core bootstrap cannot read its entrypoint', static function(Context $t): void {
		[, $entrypoint]=dp_dpanel_diagnostic_module($t, 'after_failed_core');
		$state=dp_dpanel_diagnostics_reset($t, ['after_failed_core'=>[$entrypoint]]);
		$coreEntrypoint=\dataphyre\ROOTPATH['common_dataphyre_runtime'].'modules/core/kernel/core.main.php';
		$state->put('file_reads', [$coreEntrypoint=>false]);
		\dataphyre\dpanel::$bootstrap_core_before_module=true;
		\dataphyre\dpanel::$load_module_entrypoints=true;
		\dataphyre\dpanel::$run_unit_tests=false;
		$t->isFalse(\dataphyre\dpanel::diagnose_module('after_failed_core'));
		$t->isFalse($t->nonPublic(\dataphyre\dpanel::class)->readProperty('core_diagnostics_bootstrapped'));
	})->tag('dpanel','diagnostics','core-bootstrap','failure','coverage')->group('framework-coverage');
}
