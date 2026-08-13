<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	final class dpanel {
		public static bool $follow_dependency_diagnostics=true;
		public static function diagnose_module(string $module): void {
			\Dataphyre\Test\TestState::channel('helper.deep')->append('diagnosed',$module);
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	$dp_helper_kernel=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/core/kernel';
	require_once $dp_helper_kernel.'/helper_functions.php';

	test('helper functions cover isolated roots module dependencies and config lifecycle', static function(Context $t): void {
		$state=$t->state('helper.deep',['diagnosed'=>[]]);
		$workspace=$t->workspace('helper-functions');
		$base=$workspace->root();
		$common=$workspace->directory('common');
		$app=$workspace->directory('app');
		$workspace->directory('common/config');
		$workspace->directory('app/config');
		$workspace->directory('app/cache/config');
		$rootOverride=$t->global('DATAPHYRE_HELPER_ROOTPATH_OVERRIDE')->unsetValue();
		$runMode=$t->global('DATAPHYRE_HELPER_RUN_MODE_OVERRIDE')->unsetValue();

		$t->same(ROOTPATH, dp_helper_rootpath());
		$t->same('ci', dp_helper_run_mode());

		$rootOverride->replace(false);
		$t->same(null, dp_helper_rootpath());
		dp_modcache_save_if_changed(['unwritten'=>false]);
		$t->same([], dp_config_candidate_files('core'));
		$t->same(null, dp_module_config_app_file('core'));
		$t->same([], dp_define_core_config('DP_HELPER_COV_NO_ROOT_CORE'));
		$t->same([], dp_define_module_config('no_root', 'DP_HELPER_COV_NO_ROOT_MODULE', ['ignored'=>true]));

		$roots=[
			'common_dataphyre'=>$common.'/',
			'dataphyre'=>$app.'/',
		];
		$rootOverride->replace($roots);
		$t->same($roots, dp_helper_rootpath());
		dp_modcache_save_if_changed(['core'=>[$base.'/core.main.php', '2.0']]);
		$modcache=$app.'/modcache.php';
		$t->isTrue(is_file($modcache));
		$before=(string)file_get_contents($modcache);
		dp_modcache_save_if_changed(['core'=>[$base.'/core.main.php', '2.0']]);
		$t->same($before, (string)file_get_contents($modcache));

		$t->type('array', dp_module_present('core'));
		$t->isFalse(dp_module_present('helper_missing'));
		$t->throws(
			static fn()=>dp_module_required('consumer', 'helper_missing'),
			RuntimeException::class
		);
		$t->throws(
			static fn()=>dp_module_required('consumer', 'core', '9.0'),
			RuntimeException::class
		);
		$t->throws(
			static fn()=>dp_module_required('consumer', 'core', '1.0', '1.5'),
			RuntimeException::class
		);
		dp_module_required('consumer', 'core', '1.0', '3.0');
		$t->isTrue(true);

		$runMode->replace('diagnostic');
		$state->put('diagnosed',[]);
		dp_module_required('consumer', 'helper_missing');
		dp_module_required('consumer', 'core', '9.0');
		dp_module_required('consumer', 'core', '1.0', '3.0');
		$t->same(['core'], $state->get('diagnosed'));
		$runMode->replace('');
		$t->same('ci', dp_helper_run_mode());

		$t->same('DP_MODULE_CFG', dp_module_config_constant_name('  '));
		$t->same('DP_MAILER_HTTP_CFG', dp_module_config_constant_name(' mailer-http '));
		$t->same([
			$common.'/config/helper_deep.php',
			$app.'/config/helper_deep.php',
			$app.'/cache/config/helper_deep.compiled.php',
		], dp_config_candidate_files(' helper_deep '));
		$t->same([
			$common.'/config/helper_deep.php',
			$app.'/config/helper_deep.php',
		], dp_config_candidate_files('helper_deep', false));
		$t->same([], dp_config_candidate_files('  '));
		$rootOverride->replace([
			'common_dataphyre'=>'',
			'dataphyre'=>$app.'/',
		]);
		$t->same([
			$app.'/config/only_app.php',
			$app.'/cache/config/only_app.compiled.php',
		], dp_config_candidate_files('only_app'));
		$rootOverride->replace($roots);

		$t->same(['nested'=>1], dp_core_config_extract(['dataphyre'=>['nested'=>1]]));
		$t->same(['direct'=>2], dp_core_config_extract(['direct'=>2]));
		$t->same(['nested'=>3], dp_module_config_extract(['dataphyre'=>['helper_deep'=>['nested'=>3]]], 'helper_deep'));
		$t->same(['direct'=>4], dp_module_config_extract(['direct'=>4], 'helper_deep'));
		$t->same(null, dp_module_config_app_file('  '));
		$t->same($app.'/config/helper_deep.php', dp_module_config_app_file(' helper_deep '));
		$t->contains("return array (", dp_module_config_template('ignored', ['value'=>1]));

		$workspace->file('common/config/core.php', "<?php return ['dataphyre'=>['common'=>['one'=>1]]];\n");
		$workspace->file('app/config/core.php', "<?php return false;\n");
		$workspace->file('app/cache/config/core.compiled.php', "<?php return ['compiled'=>true, 'common'=>['two'=>2]];\n");
		$t->same([
			'common'=>['one'=>1, 'two'=>2],
			'compiled'=>true,
		], dp_define_core_config('DP_HELPER_COV_CORE'));
		define('DP_HELPER_COV_EXISTING_CORE', ['existing'=>true]);
		define('DP_HELPER_COV_SCALAR_CORE', 'invalid');
		$t->same(['existing'=>true], dp_define_core_config('DP_HELPER_COV_EXISTING_CORE'));
		$t->same([], dp_define_core_config('DP_HELPER_COV_SCALAR_CORE'));

		$workspace->file('common/config/helper_deep.php', "<?php return ['dataphyre'=>['helper_deep'=>['common'=>1]]];\n");
		$workspace->file('app/config/helper_deep.php', "<?php return false;\n");
		$workspace->file('app/cache/config/helper_deep.compiled.php', "<?php return ['compiled'=>2];\n");
		$t->same([
			'default'=>0,
			'common'=>1,
			'compiled'=>2,
		], dp_define_module_config('helper_deep', 'DP_HELPER_COV_MODULE', ['default'=>0]));
		define('DP_HELPER_COV_EXISTING_MODULE', ['existing'=>true]);
		define('DP_HELPER_COV_SCALAR_MODULE', 'invalid');
		$t->same(['existing'=>true], dp_define_module_config('ignored', 'DP_HELPER_COV_EXISTING_MODULE'));
		$t->same([], dp_define_module_config('ignored', 'DP_HELPER_COV_SCALAR_MODULE'));

		$t->isFalse(dp_write_module_config_defaults('empty', []));
		$rootOverride->replace(false);
		$t->isFalse(dp_write_module_config_defaults('no_root', ['value'=>1]));
		$rootOverride->replace($roots);
		$t->isFalse(dp_write_module_config_defaults('helper_deep', ['value'=>1]));
		$t->isTrue(dp_write_module_config_defaults('direct_write', ['value'=>1]));
		$t->same(['value'=>1], require $app.'/config/direct_write.php');
		$t->same(['seed'=>1], dp_define_module_config('seeded', 'DP_HELPER_COV_SEEDED', ['seed'=>1]));
		$t->same(['seed'=>1], require $app.'/config/seeded.php');

		$blocked=$workspace->file('blocked', 'not a directory');
		$rootOverride->replace(['dataphyre'=>$blocked.'/']);
		$t->isFalse(dp_write_module_config_defaults('mkdir_failure', ['value'=>1]));
		$rootOverride->replace($roots);
		$workspace->directory('app/config/write_failure.php');
		$t->isFalse(dp_write_module_config_defaults('write_failure', ['value'=>1]));
	})->tag('core', 'helper-functions', 'coverage')->group('framework-coverage');
}
