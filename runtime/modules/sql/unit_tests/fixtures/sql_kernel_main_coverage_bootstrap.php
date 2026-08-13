<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	if(!function_exists(__NAMESPACE__.'\\dp_define_module_config')){
		function dp_define_module_config(string $module,string $constant,array $defaults=[]): void {
			if(!defined($constant)){
				define($constant,$defaults);
			}
		}
	}

	if(!class_exists(core::class,false)){
		final class core {
			public static function dialback(string $name,mixed ...$arguments): mixed {
				$state=\dp_sql_kernel_fixture_state();
				if($state===null){ return null; }
				$state->append('dialback_calls',[$name,$arguments]);
				$dialbacks=$state->get('dialbacks',[]);
				if(!array_key_exists($name,$dialbacks)){
					return null;
				}
				$value=$dialbacks[$name];
				if(is_array($value) && array_is_list($value)){
					$result=array_shift($value);
					$dialbacks[$name]=$value;
					$state->put('dialbacks',$dialbacks);
					return $result;
				}
				return is_callable($value) ? $value(...$arguments) : $value;
			}

			public static function load_framework_module(string $module): bool {
				return (bool)(\dp_sql_kernel_fixture_state()?->get('framework_available',true) ?? true);
			}

			public static function file_put_contents_forced(string $file,string $contents): int|false {
				$directory=dirname($file);
				if(!is_dir($directory)){
					mkdir($directory,0777,true);
				}
				return file_put_contents($file,$contents);
			}

			public static function force_rmdir(string $directory): bool {
				if(!is_dir($directory)){
					return true;
				}
				$iterator=new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),
					\RecursiveIteratorIterator::CHILD_FIRST
				);
				foreach($iterator as $item){
					$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
				}
				return rmdir($directory);
			}

			public static function unavailable(mixed ...$arguments): null {
				\dp_sql_kernel_fixture_state()?->append('unavailable',$arguments);
				return null;
			}

			public static function get_password(string $endpoint): string {
				return '';
			}
		}
	}

	if(!class_exists(cache::class,false)){
		final class cache {
			public static array $values=[];
			public static array $expires=[];
			/** @var array<string,callable(string,mixed):void> */
			public static array $getCallbacks=[];
			public static bool $shared=true;

			public static function isShared(): bool {
				return self::$shared;
			}

			public static function get(string $key): mixed {
				$value=self::$values[$key] ?? null;
				if(isset(self::$getCallbacks[$key])) self::$getCallbacks[$key]($key,$value);
				return $value;
			}

			public static function set(string $key,mixed $value,mixed $expires=null): bool {
				self::$values[$key]=$value;
				self::$expires[$key]=$expires;
				return true;
			}

			public static function increment(string $key): int {
				return self::$values[$key]=(int)(self::$values[$key] ?? 0)+1;
			}

			public static function delete(string $key): bool {
				unset(self::$values[$key],self::$expires[$key]);
				return true;
			}
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use Dataphyre\Test\GlobalState;
	use Dataphyre\Test\NonPublicAccess;
	use Dataphyre\Test\TestState;

	function dp_sql_kernel_fixture_state(): ?TestState {
		return TestState::channelIfActive('sql.kernel-main');
	}

	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {
			dp_sql_kernel_fixture_state()?->append('traces',$arguments);
		}
	}
	if(!function_exists('dataphyre_shutdown_log')){
		function dataphyre_shutdown_log(string $message,?Throwable $exception=null): void {
			dp_sql_kernel_fixture_state()?->append('shutdown_logs',[$message,$exception]);
		}
	}
	if(!function_exists('log_error')){
		function log_error(mixed ...$arguments): void {
			dp_sql_kernel_fixture_state()?->append('error_logs',$arguments);
		}
	}
	if(!function_exists('dp_module_present')){
		function dp_module_present(string $module): bool {
			return (bool)(dp_sql_kernel_fixture_state()?->get('modules',[])[$module] ?? false);
		}
	}

	$dbms=defined('DP_SQL_KERNEL_TEST_DBMS') ? (string)DP_SQL_KERNEL_TEST_DBMS : 'sqlite';
	if(!defined('DATAPHYRE_MODULE_POLICY')){
		define('DATAPHYRE_MODULE_POLICY',[
			'enabled'=>['core'=>true,'sql'=>true],
			'disabled'=>[],
			'core_implicit'=>true,
		]);
	}
	if(!defined('DP_CORE_CFG')){
		define('DP_CORE_CFG',['datacenter'=>'coverage']);
	}
	if(!defined('DP_SQL_CFG')){
		$hashType=defined('DP_SQL_KERNEL_TEST_HASH_TYPE') ? (string)DP_SQL_KERNEL_TEST_HASH_TYPE : 'md5';
		$safeDelete=defined('DP_SQL_KERNEL_TEST_SAFE_DELETE') ? (bool)DP_SQL_KERNEL_TEST_SAFE_DELETE : true;
		define('DP_SQL_CFG',[
			'default_cluster'=>'main',
			'default_database_location'=>'',
			'safe_delete'=>$safeDelete,
			'caching'=>[
				'rolling_db_cache_size'=>3,
				'default_policy'=>[
					'type'=>'session',
					'max_lifespan'=>'30 minute',
					'hash_type'=>$hashType,
				],
			],
			'datacenters'=>[
				'coverage'=>[
					'dbms_clusters'=>[
						'main'=>[
							'dbms'=>$dbms,
							'endpoints'=>['coverage-endpoint'],
							'database_name'=>':memory:',
							'dbms_username'=>'coverage',
							'dbms_port'=>5432,
							'password'=>'',
						],
					],
				],
			],
			'tables'=>[
				'no_cache'=>['caching'=>false],
				'session_override'=>['caching'=>['type'=>'session','max_lifespan'=>'1 minute','hash_type'=>'sha256']],
				'fs_override'=>['caching'=>['type'=>'fs','max_lifespan'=>'1 minute','hash_type'=>'md5']],
				'shared_override'=>['caching'=>['type'=>'shared_cache','max_lifespan'=>'1 minute','hash_type'=>'md5']],
			],
		]);
	}

	$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
	require_once $modulesRoot.'/core/kernel/autoloader.php';
	\dataphyre\autoloader::register($modulesRoot);
	\dataphyre\autoloader::register_framework_modules(['sql']);
	require_once $modulesRoot.'/sql/Framework/TableSchema.php';
	require_once $modulesRoot.'/sql/Framework/TableDefinition.php';
	require_once $modulesRoot.'/sql/kernel/sql.main.php';

	final class DpSqlKernelFixture {
		public function __construct(
			public TestState $state,
			public GlobalState $session,
			public GlobalState $writeBlocks,
			public NonPublicAccess $sql,
		) {}
	}

	function dp_sql_kernel_reset(Context $t): DpSqlKernelFixture {
		$state=$t->state('sql.kernel-main',[
			'dialbacks'=>[],
			'dialback_calls'=>[],
			'traces'=>[],
			'error_logs'=>[],
			'shutdown_logs'=>[],
			'unavailable'=>[],
			'modules'=>['cache'=>true],
			'framework_available'=>true,
			'driver_results'=>[],
		]);
		$session=$t->globalMap('_SESSION')->clear();
		$writeBlocks=$t->global('dataphyre_flightdeck_replay_write_blocks')->replace(0);
		\dataphyre\cache::$values=[];
		\dataphyre\cache::$expires=[];
		\dataphyre\cache::$getCallbacks=[];
		\dataphyre\mysql_query_builder::$queued_queries=[];
		\dataphyre\postgresql_query_builder::$queued_queries=[];
		\dataphyre\sqlite_query_builder::$queued_queries=[];
		$sql=$t->nonPublic(\dataphyre\sql::class);
		$sql->replacePropertyForTest('observers',[]);
		$sql->replacePropertyForTest('last_query_error',null);
		$sql->replacePropertyForTest('table_definition_registry',[]);
		$sql->replacePropertyForTest('loaded_table_definition_files',[]);
		$sql->replacePropertyForTest('structure_hydration_retrying',[]);
		return new DpSqlKernelFixture($state,$session,$writeBlocks,$sql);
	}
}
