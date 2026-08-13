<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

final class DataphyreModuleBridge {

	private string $runtime;
	private ?string $scratch;

	public function __construct(string $runtime_root='', ?string $scratch_root=null) {
		$this->runtime=rtrim(str_replace('\\', '/', $runtime_root), '/');
		$scratch_root=$scratch_root===null ? null : rtrim(str_replace('\\', '/', trim($scratch_root)), '/');
		$this->scratch=$scratch_root!=='' ? $scratch_root : null;
	}

	public function storage(array $config=[]): object {
		$this->loadStorage();
		if(!class_exists('\Dataphyre\Storage\StorageManager')){
			throw new \RuntimeException('Dataphyre Storage framework classes could not be loaded.');
		}
		$config=array_replace_recursive([
			'default_disk'=>'memory',
			'disks'=>[
				'memory'=>[
					'driver'=>'memory',
				],
			],
		], $config);
		if(!defined('DP_STORAGE_CFG')){
			define('DP_STORAGE_CFG', $config);
		}
		\Dataphyre\Storage\StorageManager::flushInstance();
		$manager=\Dataphyre\Storage\StorageManager::instance();
		$manager->fakeFlush();
		return $manager;
	}

	public function storageEvents(object $manager): StorageEventRecorder {
		$recorder=new StorageEventRecorder();
		if(method_exists($manager, 'listen')){
			$manager->listen('*', [$recorder, 'record']);
		}
		return $recorder;
	}

	public function permission(array $config=[]): string {
		$this->loadPermission();
		if(!class_exists('\Dataphyre\Permission\Permission')){
			throw new \RuntimeException('Dataphyre Permission framework classes could not be loaded.');
		}
		if(!defined('DP_PERMISSION_CFG')){
			define('DP_PERMISSION_CFG', array_replace_recursive([
				'default_roles'=>[],
				'roles'=>[],
				'aliases'=>[],
				'super_permissions'=>['*'],
				'storage'=>[
					'auto_hydrate'=>false,
				],
				'cache'=>[
					'enabled'=>false,
				],
				'trace'=>[
					'enabled'=>true,
					'max_entries'=>256,
					'include_context'=>true,
				],
			], $config));
		}
		\Dataphyre\Permission\Permission::flush();
		\Dataphyre\Permission\Permission::trace(true);
		return \Dataphyre\Permission\Permission::class;
	}

	public function sqlFramework(): DataphyreSqlFrameworkBridge {
		$this->loadSqlFramework();
		if(!class_exists('\Dataphyre\Database\QuerySpec') || !class_exists('\Dataphyre\Database\TableSchema') || !class_exists('\Dataphyre\Database\TableDefinition')){
			throw new \RuntimeException('Dataphyre SQL framework classes could not be loaded.');
		}
		return new DataphyreSqlFrameworkBridge();
	}

	public function sqlKernel(?string $database_path=null): DataphyreSqlKernelHarness {
		if(!extension_loaded('sqlite3') || !class_exists('\SQLite3')){
			throw new \RuntimeException('The SQLite3 extension is required for the Dataphyre SQL kernel test harness.');
		}
		$database_path=$database_path!==null && trim($database_path)!==''
			? $database_path
			: ($this->scratch ?? $this->projectRoot().'/cache/unit-test-sql').'/sql-kernel-'.bin2hex(random_bytes(6)).'.sqlite';
		$database_path=str_replace('\\', '/', $database_path);
		$dir=dirname($database_path);
		if(!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)){
			throw new \RuntimeException('Unable to create SQL kernel test database directory: '.$dir);
		}
		$this->loadSqlKernel($database_path);
		return new DataphyreSqlKernelHarness($database_path, 'sql');
	}

	public function mvc(): DataphyreMvcTestHarness {
		$this->loadMvcFramework();
		if(!class_exists('\Dataphyre\Mvc\Mvc') || !class_exists('\Dataphyre\Http\Request') || !class_exists('\Dataphyre\Http\Response')){
			throw new \RuntimeException('Dataphyre MVC framework classes could not be loaded.');
		}
		\Dataphyre\Mvc\Mvc::flush();
		return new DataphyreMvcTestHarness();
	}

	public function reactor(array $config=[]): object {
		$this->loadReactor();
		if(!class_exists('\Dataphyre\Reactor\Reactor')){
			throw new \RuntimeException('Dataphyre Reactor framework classes could not be loaded.');
		}
		if(!defined('DP_REACTOR_CFG')){
			define('DP_REACTOR_CFG', array_replace_recursive([
				'secret'=>'dataphyre-testing-secret',
				'allow_unsigned_in_debug'=>false,
				'components'=>[],
			], $config));
		}
		\Dataphyre\Reactor\Reactor::reset();
		return \Dataphyre\Reactor\Reactor::test();
	}

	private function loadStorage(): void {
		if(class_exists('\Dataphyre\Storage\StorageManager', false)){
			return;
		}
		$base=$this->runtime.'/modules/storage/Framework';
		foreach([
			'Contracts/StorageDriver.php',
			'FileMetadata.php',
			'StorageResult.php',
			'Support/Path.php',
			'Support/Stream.php',
			'Support/Encryption.php',
			'Support/AwsSignatureV4.php',
			'Drivers/LocalDriver.php',
			'Drivers/MemoryDriver.php',
			'Drivers/VestraDriver.php',
			'Drivers/S3CompatibleDriver.php',
			'Drivers/MirrorDriver.php',
			'Drivers/ScopedDriver.php',
			'Drivers/ReadOnlyDriver.php',
			'Drivers/QuotaDriver.php',
			'Drivers/FailoverDriver.php',
			'Drivers/CachedDriver.php',
			'Drivers/CompressedDriver.php',
			'Drivers/RetentionDriver.php',
			'Drivers/LifecycleDriver.php',
			'Drivers/ScannedDriver.php',
			'Drivers/TaggedDriver.php',
			'Drivers/EventedDriver.php',
			'Drivers/PolicyDriver.php',
			'Drivers/RateLimitedDriver.php',
			'Drivers/VersionedDriver.php',
			'Drivers/DeduplicatedDriver.php',
			'Drivers/IntegrityDriver.php',
			'Drivers/AuditDriver.php',
			'StorageManager.php',
			'Storage.php',
		] as $file){
			$path=$base.'/'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
	}

	private function loadPermission(): void {
		if(class_exists('\Dataphyre\Permission\Permission', false)){
			return;
		}
		$base=$this->runtime.'/modules/permission/Framework';
		foreach([
			'PermissionRule.php',
			'PermissionRepository.php',
			'PermissionCatalog.php',
			'PermissionAudit.php',
			'PermissionManifest.php',
			'PermissionNamer.php',
			'PermissionCondition.php',
			'PermissionTrace.php',
			'PermissionTest.php',
			'PermissionSimulator.php',
			'PermissionSnapshot.php',
			'PermissionOptimizer.php',
			'Exceptions/AuthorizationException.php',
			'Middleware/AuthorizeWhen.php',
			'Middleware/AuthorizeAnyWhen.php',
			'PermissionSet.php',
			'SubjectResolver.php',
			'PermissionEngine.php',
			'PermissionSubject.php',
			'Permission.php',
		] as $file){
			$path=$base.'/'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
	}

	private function loadSqlFramework(): void {
		if(class_exists('\Dataphyre\Database\QuerySpec', false) && class_exists('\Dataphyre\Database\TableSchema', false) && class_exists('\Dataphyre\Database\TableDefinition', false)){
			return;
		}
		$base=$this->runtime.'/modules/sql/Framework';
		foreach([
			'SqlError.php',
			'QuerySpec.php',
			'TableSchema.php',
			'TableDefinition.php',
		] as $file){
			$path=$base.'/'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
	}

	private function loadMvcFramework(): void {
		if(class_exists('\Dataphyre\Mvc\Mvc', false) && class_exists('\Dataphyre\Http\Request', false) && class_exists('\dataphyre\routing\compiled_route_dispatcher', false)){
			return;
		}
		$this->loadHttpFramework();
		$this->loadRoutingFramework();
		$this->loadTemplatingResponseTypes();
		$base=$this->runtime.'/modules/mvc/Framework';
		foreach([
			'ContainerException.php',
			'HttpException.php',
			'ValidationException.php',
			'RouteModelNotFoundException.php',
			'Container.php',
			'Controller.php',
			'ServiceProviderContract.php',
			'ServiceProvider.php',
			'ProviderRegistry.php',
			'Session.php',
			'Model.php',
			'RouteModelBinder.php',
			'ResponseResult.php',
			'RedirectResult.php',
			'ViewResult.php',
			'MvcRouteContext.php',
			'RouteDefinition.php',
			'RouteCollection.php',
			'RouteList.php',
			'AccessMiddleware.php',
			'CacheMiddleware.php',
			'CallbackServiceProvider.php',
			'CsrfMiddleware.php',
			'FormRequest.php',
			'GuestMiddleware.php',
			'PermissionAnyMiddleware.php',
			'PermissionMiddleware.php',
			'SessionMiddleware.php',
			'SignedUrl.php',
			'SignedUrlMiddleware.php',
			'ThrottleMiddleware.php',
			'Validator.php',
			'MvcApplication.php',
			'MvcDispatcher.php',
			'MvcManager.php',
			'MvcHost.php',
			'Mvc.php',
		] as $file){
			$path=$base.'/'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
	}

	private function loadHttpFramework(): void {
		if(class_exists('\Dataphyre\Http\Request', false) && class_exists('\Dataphyre\Http\Response', false)){
			return;
		}
		$base=$this->runtime.'/modules/http/Framework';
		foreach([
			'UploadedFile.php',
			'Request.php',
			'Response.php',
			'ResponseEmitter.php',
			'ActionArguments.php',
		] as $file){
			$path=$base.'/'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
	}

	private function loadRoutingFramework(): void {
		if(class_exists('\Dataphyre\Routing\RouteCompiler', false) && class_exists('\dataphyre\routing\compiled_route_dispatcher', false)){
			return;
		}
		$framework=$this->runtime.'/modules/routing/Framework';
		foreach([
			'CompilableRoute.php',
			'Route.php',
			'ControllerAction.php',
			'RouteManifest.php',
			'RouteCompiler.php',
		] as $file){
			$path=$framework.'/'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
		$dispatcher=$this->runtime.'/modules/routing/kernel/compiled_route_dispatcher.php';
		if(is_file($dispatcher)){
			require_once $dispatcher;
		}
	}

	private function loadTemplatingResponseTypes(): void {
		if(class_exists('\Dataphyre\Templating\RenderedTemplate', false) && class_exists('\Dataphyre\Templating\TemplateView', false)){
			return;
		}
		$base=$this->runtime.'/modules/templating/Framework';
		foreach([
			'RenderedTemplate.php',
			'TemplateView.php',
		] as $file){
			$path=$base.'/'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
	}

	private function loadSqlKernel(string $database_path): void {
		if(class_exists('\dataphyre\sql', false)){
			$config=defined('DP_SQL_CFG') ? \constant('DP_SQL_CFG') : [];
			$datacenter=defined('DP_CORE_CFG') ? (string)((\constant('DP_CORE_CFG')['datacenter'] ?? 'test') ?: 'test') : 'test';
			$active=(string)($config['datacenters'][$datacenter]['dbms_clusters']['sql']['database_name'] ?? '');
			if($active!=='' && str_replace('\\', '/', $active)!==$database_path){
				throw new \RuntimeException('Dataphyre SQL kernel is already loaded for another database in this worker.');
			}
			return;
		}
		$this->defineSqlKernelTestStubs($database_path);
		$entry=$this->runtime.'/modules/sql/kernel/sql.main.php';
		if(!is_file($entry)){
			throw new \RuntimeException('Dataphyre SQL kernel entrypoint is missing.');
		}
		require_once $entry;
	}

	private function defineSqlKernelTestStubs(string $database_path): void {
		if(!isset($_SESSION) || !is_array($_SESSION)){
			$_SESSION=[];
		}
		$root=$this->projectRoot();
		if(!defined('DP_CORE_CFG')){
			define('DP_CORE_CFG', ['datacenter'=>'test']);
		}
		$datacenter=(string)((\constant('DP_CORE_CFG')['datacenter'] ?? 'test') ?: 'test');
		if(!defined('DP_SQL_CFG')){
			define('DP_SQL_CFG', [
				'default_cluster'=>'sql',
				'default_database_location'=>'',
				'safe_delete'=>true,
				'caching'=>[
					'rolling_db_cache_size'=>256,
					'default_policy'=>[
						'type'=>'session',
						'max_lifespan'=>'30 minute',
						'hash_type'=>'md5',
					],
				],
				'datacenters'=>[
					$datacenter=>[
						'dbms_clusters'=>[
							'sql'=>[
								'dbms'=>'sqlite',
								'database_name'=>$database_path,
								'endpoints'=>[$database_path],
							],
						],
					],
				],
				'tables'=>[
					'raw'=>[
						'cluster'=>'sql',
						'caching'=>false,
					],
				],
			]);
		}
		if(!defined('ROOTPATH')){
			define('ROOTPATH', [
				'root'=>$root,
				'common_root'=>$root,
				'common_dataphyre'=>$root.'/dataphyre',
				'common_dataphyre_runtime'=>$this->runtime,
				'applications'=>$root.'/applications',
			]);
		}
		if(!defined('RUN_MODE')){
			define('RUN_MODE', 'unit_test');
		}
		if(!function_exists('\dataphyre\tracelog')){
			PhpStub::define('namespace dataphyre { function tracelog(...$args): void {} }');
		}
		if(!function_exists('\dataphyre\log_error')){
			PhpStub::define('namespace dataphyre { function log_error(...$args): void {} }');
		}
		if(!function_exists('\dataphyre\dp_module_present')){
			PhpStub::define('namespace dataphyre { function dp_module_present(...$args): bool { return false; } }');
		}
		if(!function_exists('\dataphyre\dp_define_module_config')){
			PhpStub::define('namespace dataphyre { function dp_define_module_config(string $module, string $constant, array $defaults=[]): void { if(!defined($constant)){ define($constant, $defaults); } } }');
		}
		if(!function_exists('dataphyre_shutdown_log')){
			PhpStub::define('namespace { function dataphyre_shutdown_log(...$args): void {} }');
		}
		if(!function_exists('log_error')){
			PhpStub::define('namespace { function log_error(...$args): void {} }');
		}
		if(!class_exists('\dataphyre\core', false)){
			PhpStub::define('namespace dataphyre { class core { public static function dialback(...$args): mixed { return null; } public static function unavailable(...$args): never { throw new \RuntimeException((string)($args[4] ?? "Dataphyre unavailable.")); } public static function load_framework_module(...$args): bool { return false; } public static function file_put_contents_forced(string $file, string $data): int|false { $dir=dirname($file); if(!is_dir($dir)){ mkdir($dir, 0775, true); } return file_put_contents($file, $data); } public static function force_rmdir(string $path): void { if(!is_dir($path)){ return; } $items=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST); foreach($items as $item){ $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); } rmdir($path); } public static function get_password(...$args) { return null; } public static function log(...$args): void {} } }');
		}
	}

	private function loadReactor(): void {
		if(class_exists('\Dataphyre\Reactor\ReactorTestHarness', false)){
			return;
		}
		$modules_root=$this->runtime.'/modules';
		$core_autoloader=$modules_root.'/core/kernel/autoloader.php';
		if(is_file($core_autoloader)){
			require_once $core_autoloader;
			if(class_exists('\dataphyre\autoloader', false)){
				\dataphyre\autoloader::register($modules_root);
				// This bridge is an explicit test-owned module request, so it must not
				// inherit an application's runtime module policy while loading fixtures.
				\dataphyre\autoloader::register_prefixes([
					'Dataphyre\\Reactor\\'=>$modules_root.'/reactor/Framework',
				]);
				if(class_exists('\Dataphyre\Reactor\ReactorTestHarness')){
					return;
				}
			}
		}
	}

	private function projectRoot(): string {
		return rtrim(str_replace('\\', '/', dirname($this->runtime, 3)), '/');
	}
}
