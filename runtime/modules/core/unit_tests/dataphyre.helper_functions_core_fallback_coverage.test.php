<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	final class core {
		/** @return array<string,mixed> */
		public static function &config_all(): array {
			static $config=[
				'dataphyre'=>[
					'from_core'=>'core',
					'core_module'=>['from_core'=>'module'],
				],
			];
			return $config;
		}
		public static function file_put_contents_forced(string $file, string $contents): int|false {
			$state=\Dataphyre\Test\TestState::channel('helper.core.fallback');
			if($state->get('write_enabled',true)!==true){
				return false;
			}
			$writer=$state->get('workspace_writer');
			return is_callable($writer) ? $writer($file,$contents) : false;
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	$dp_helper_core_kernel=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/core/kernel';
	require_once $dp_helper_core_kernel.'/helper_functions.php';
	test('helper functions merge runtime core fallback and use the forced writer', static function(Context $t): void {
		$state=$t->state('helper.core.fallback',['write_enabled'=>true]);
		$workspace=$t->workspace('helper-core-fallback');
		$workspaceRoot=rtrim(str_replace('\\','/',$workspace->root()),'/').'/';
		$state->put('workspace_writer',static function(string $file,string $contents) use($workspace,$workspaceRoot): int|false {
			$file=str_replace('\\','/',$file);
			if(!str_starts_with($file,$workspaceRoot)){
				return false;
			}
			$workspace->file(substr($file,strlen($workspaceRoot)),$contents);
			return strlen($contents);
		});
		$common=$workspace->directory('common');
		$app=$workspace->directory('app');
		$t->global('DATAPHYRE_HELPER_ROOTPATH_OVERRIDE')->replace([
			'common_dataphyre'=>$common.'/',
			'dataphyre'=>$app.'/',
		]);
		$workspace->file('common/config/core.php',"<?php return null;\n");
		$workspace->file('common/config/core_module.php',"<?php return null;\n");
		$t->same(
			['from_core'=>'core', 'core_module'=>['from_core'=>'module']],
			dp_define_core_config('DP_HELPER_CORE_FALLBACK_CORE')
		);
		$t->same(
			['default'=>true, 'from_core'=>'module'],
			dp_define_module_config('core_module', 'DP_HELPER_CORE_FALLBACK_MODULE', ['default'=>true])
		);
		$t->isTrue(dp_write_module_config_defaults('forced_write', ['value'=>1]));
		$t->same(['value'=>1], require $app.'/config/forced_write.php');
		$state->put('write_enabled',false);
		$t->isFalse(dp_write_module_config_defaults('forced_failure', ['value'=>2]));
	})->tag('core', 'helper-functions', 'core-fallback', 'coverage')->group('framework-coverage');
}
