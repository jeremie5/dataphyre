<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

// PHP class names are case-insensitive, so the Framework-facing Runtime API
// must be composed into this canonical kernel class rather than declared as a
// second Dataphyre\Runtime class. The conditional include is cycle-safe in
// both load orders because Framework/Runtime.php uses require_once in return.
if(!trait_exists('Dataphyre\\RuntimeFrameworkSurface', false)){
	require_once dirname(__DIR__).'/Framework/Runtime.php';
}

/**
 * Boots a Dataphyre application from its project root and application definition.
 *
 * Runtime boot resolves the application directory, loads conventional or explicit `app.php`
 * metadata, registers application-specific autoload prefixes, then selects one executable path:
 * compiled routes first, Framework bootstrap second, and legacy bootstrap only when the
 * definition permits it.
 */
final class runtime {
	use \Dataphyre\RuntimeFrameworkSurface;

	/**
	 * Application definition selected by the most recent successful boot.
	 */
	private static ?application_definition $current_application_definition=null;

	/**
	 * Project root selected by the most recent successful boot.
	 */
	private static ?string $current_project_root=null;

	/**
	 * Boots an application by name from the configured project/application roots.
	 *
	 * The method mutates process state by setting the current application definition/root and by
	 * registering application autoload prefixes before dispatching a route or requiring a
	 * bootstrap file.
	 *
	 * @param string $project_root Project root used for app location and current-root tracking.
	 * @param string $application_name Application name to locate.
	 * @param array<int, string> $application_roots Optional app root candidates.
	 * @return void
	 *
	 * @throws \RuntimeException When the app cannot be found or has no executable boot path.
	 */
	public static function boot(string $project_root, string $application_name, array $application_roots=[]): void {
		$bootstrapOnly=\class_exists(\Dataphyre\InternalApplicationBootstrapOnly::class,false)
			? \Dataphyre\InternalApplicationBootstrapOnly::context()
			: null;
		if($bootstrapOnly!==null){
			$resolvedProject=\realpath($project_root);
			if(!\is_string($resolvedProject)
				|| !\hash_equals($bootstrapOnly['project_root'],$resolvedProject)
				|| !\hash_equals($bootstrapOnly['application'],$application_name)){
				throw new \RuntimeException('Bootstrap-only runtime application identity is invalid.');
			}
		}
		$application_directory=app_locator::locate($project_root, $application_name, $application_roots);
		if($application_directory===null){
			throw new \RuntimeException("Application {$application_name} was not found in any configured application root.");
		}
		$definition=self::load_application_definition($application_name, $application_directory);
		self::$current_application_definition=$definition;
		self::$current_project_root=rtrim($project_root, '/\\');
		self::register_application_autoload($definition);
		if(self::boot_internal_runtime_route($definition)===true){
			return;
		}
		if($bootstrapOnly===null && self::boot_compiled_routes($definition)===true){
			return;
		}
		if(self::boot_framework_application($definition)===true){
			return;
		}
		if($bootstrapOnly!==null){
			throw new \RuntimeException("Application {$application_name} has no registration-safe Framework bootstrap path.");
		}
		if($definition->should_fallback_to_legacy_bootstrap()===true){
			self::boot_legacy_application($application_directory, $definition);
			return;
		}
		throw new \RuntimeException("Application {$application_name} has no executable bootstrap path.");
	}

	/**
	 * Resolves an application definition without booting it.
	 *
	 * @param string $project_root Project root used for app location.
	 * @param string $application_name Application name to locate.
	 * @param array<int, string> $application_roots Optional app root candidates.
	 * @return application_definition|null Loaded definition, or null when the app directory cannot be found.
	 */
	public static function resolve_application_definition(string $project_root, string $application_name, array $application_roots=[]): ?application_definition {
		$application_directory=app_locator::locate($project_root, $application_name, $application_roots);
		if($application_directory===null){
			return null;
		}
		return self::load_application_definition($application_name, $application_directory);
	}

	/**
	 * Returns the application definition selected by the active runtime boot.
	 *
	 * @return application_definition|null Current boot definition, or null before boot.
	 */
	public static function current_application_definition(): ?application_definition {
		return self::$current_application_definition;
	}

	/**
	 * Returns the project root selected by the active runtime boot.
	 *
	 * @return string|null Current project root, or null before boot.
	 */
	public static function current_project_root(): ?string {
		return self::$current_project_root;
	}

	/**
	 * Loads an application definition from conventions plus an optional `app.php` override.
	 *
	 * @param string $application_name Application name.
	 * @param string $application_directory Resolved application directory.
	 * @return application_definition Application definition ready for boot decisions.
	 *
	 * @throws \RuntimeException When `app.php` returns an unsupported value.
	 */
	private static function load_application_definition(string $application_name, string $application_directory): application_definition {
		$conventional_definition=application_definition::from_conventions($application_name, $application_directory);
		$definition_file=$application_directory.'/app.php';
		if(!is_file($definition_file)){
			return $conventional_definition;
		}
		$definition=require($definition_file);
		if($definition instanceof application_definition){
			return $definition;
		}
		if(is_array($definition)){
			return $conventional_definition->with_overrides($definition);
		}
		throw new \RuntimeException("Application definition must return an array or application_definition: {$definition_file}");
	}

	/**
	 * Dispatches the self-hosted scheduler callback before application routes run.
	 *
	 * The request-driven scheduling default deliberately uses the application host
	 * rather than the managed scheduler pool. Keep this route at the common runtime
	 * boundary so Framework-only, compiled-route, and legacy applications share the
	 * same authenticated callback surface. Managed scheduler callbacks are handled
	 * earlier by the signed POST runtime router and never enter this branch.
	 *
	 * @param application_definition $definition Loaded application definition.
	 * @return bool `true` when the request belonged to the internal scheduler route.
	 */
	private static function boot_internal_runtime_route(application_definition $definition,array $runtime=[]): bool {
		$server=is_array($runtime['server'] ?? null) ? $runtime['server'] : $_SERVER;
		$scheduler_name=self::scheduler_route_name($server);
		if($scheduler_name===null){
			return false;
		}

		$respond=$runtime['respond'] ?? static function(int $status,string $body): void {
			http_response_code($status);
			if(!headers_sent()){
				header('Content-Type: text/plain; charset=utf-8');
				header('Cache-Control: no-store');
			}
			echo $body;
		};
		$claim=trim((string)($server['HTTP_X_DATAPHYRE_SCHEDULER_CLAIM'] ?? ''));
		$key=trim((string)($server['HTTP_X_DATAPHYRE_SCHEDULER_KEY'] ?? ''));
		$budget=trim((string)($server['HTTP_X_DATAPHYRE_SCHEDULER_BUDGET_MS'] ?? ''));
		$issuedAt=trim((string)($server['HTTP_X_DATAPHYRE_SCHEDULER_ISSUED_AT'] ?? ''));
		$dispatchSecret=self::scheduler_dispatch_secret_file();
		$verify=$runtime['verify'] ?? static function(
			string $candidate,string $name,string $candidateClaim,int $candidateBudget,int $candidateIssuedAt,
		)use($dispatchSecret): bool {
			return function_exists('dp_verify_shared_request_key')
				&& dp_verify_shared_request_key(
					$candidate,
					$dispatchSecret,
					'scheduler_dispatch_v2',
					$name.'|'.$candidateClaim.'|'.$candidateBudget.'|'.$candidateIssuedAt,
					1,
					$candidateIssuedAt,
					1,
				);
		};
		$authorized=strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'))==='GET'
			&& (string)($server['HTTP_X_TRAFFIC_SOURCE'] ?? '')==='internal_traffic'
			&& preg_match('/^[a-f0-9]{64}$/D',$claim)===1
			&& preg_match('/^[a-f0-9]{64}$/D',$key)===1
			&& preg_match('/^[1-9][0-9]{0,5}$/D',$budget)===1
			&& (int)$budget<=300000
			&& preg_match('/^[1-9][0-9]{9}$/D',$issuedAt)===1
			&& abs(time()-(int)$issuedAt)<=30
			&& is_callable($verify)
			&& $verify($key,$scheduler_name,$claim,(int)$budget,(int)$issuedAt);
		if($authorized!==true){
			$respond(404,'Not found');
			return true;
		}

		self::prime_rootpaths($definition);
		$core_loader=$runtime['core_loader'] ?? static function(): bool {
			$core_entry=__DIR__.'/core.main.php';
			if(!is_file($core_entry)) return false;
			require_once $core_entry;
			return class_exists('dataphyre\\core',false);
		};
		if(!is_callable($core_loader) || $core_loader()!==true){
			$respond(503,'Scheduler unavailable');
			return true;
		}
		$module_loader=$runtime['module_loader'] ?? static fn(string $module): bool =>
			method_exists('dataphyre\\core','load_framework_module')
			&& \dataphyre\core::load_framework_module($module);
		$loaded=is_callable($module_loader) && $module_loader('scheduling')===true;
		$scheduling_available=array_key_exists('scheduling_available',$runtime)
			? (bool)$runtime['scheduling_available']
			: class_exists('dataphyre\\scheduling',false);
		if($loaded!==true && $scheduling_available!==true){
			$respond(503,'Scheduler unavailable');
			return true;
		}
		if(!defined('DATAPHYRE_SCHEDULING_TASK_RUNNER_NO_DISPATCH')){
			define('DATAPHYRE_SCHEDULING_TASK_RUNNER_NO_DISPATCH',true);
		}
		$runner=$runtime['task_runner'] ?? static function(string $name,string $candidateClaim): void {
			if(!class_exists('dataphyre_scheduling_task_runner',false)){
				require_once dirname(__DIR__,2).'/scheduling/kernel/task_runner.php';
			}
			\dataphyre_scheduling_task_runner::dispatch(null,null,[
				'scheduler_name'=>$name,
				'scheduler_claim'=>$candidateClaim,
			]);
		};
		if(!is_callable($runner)){
			$respond(503,'Scheduler unavailable');
			return true;
		}
		$runner($scheduler_name,$claim);
		return true;
	}

	/** Extracts one exact path-safe scheduler name from the current request URI. */
	private static function scheduler_route_name(array $server): ?string {
		$path=parse_url((string)($server['REQUEST_URI'] ?? '/'),PHP_URL_PATH);
		if(!is_string($path)) return null;
		$path=rawurldecode($path);
		if(preg_match('#^/dataphyre/scheduler/([A-Za-z0-9._-]{1,128})$#D',$path,$matches)!==1){
			return null;
		}
		$name=(string)$matches[1];
		return in_array($name,['.','..'],true) ? null : $name;
	}

	/** Resolves the same host-owned scheduler signature file as the scheduling kernel. */
	private static function scheduler_dispatch_secret_file(): string {
		$value=getenv('DATAPHYRE_SCHEDULER_DISPATCH_SECRET_FILE');
		if(!is_string($value) || trim($value)==='') return 'app_override_key';
		$value=trim($value);
		$absolute=$value[0]==='/' || $value[0]==='\\' || preg_match('/^[A-Za-z]:[\\\/]/D',$value)===1;
		return $absolute && !is_link($value) && is_file($value) && is_readable($value)
			? $value
			: 'app_override_key';
	}

	/**
	 * Dispatches a compiled route manifest when the application definition points to one.
	 *
	 * @param application_definition $definition Loaded application definition.
	 * @return bool `true` when compiled-route dispatch matched and ran, otherwise `false`.
	 */
	private static function boot_compiled_routes(application_definition $definition): bool {
		if(empty($definition->compiled_routes_file) || !is_file($definition->compiled_routes_file)){
			return false;
		}
		if(class_exists('\dataphyre\routing\compiled_route_dispatcher')===false){
			return false;
		}
		self::prime_rootpaths($definition);
		return \dataphyre\routing\compiled_route_dispatcher::dispatch_file($definition->compiled_routes_file);
	}

	/**
	 * Requires the Framework bootstrap file for an application definition.
	 *
	 * @param application_definition $definition Loaded application definition.
	 * @return bool `true` when a bootstrap file existed and was required.
	 */
	private static function boot_framework_application(application_definition $definition): bool {
		if(empty($definition->framework_bootstrap_file) || !is_file($definition->framework_bootstrap_file)){
			return false;
		}
		self::prime_rootpaths($definition);
		require($definition->framework_bootstrap_file);
		return true;
	}

	/**
	 * Requires the legacy application bootstrap file.
	 *
	 * @param string $application_directory Resolved application directory.
	 * @param ?application_definition $definition Loaded definition that may override the legacy bootstrap path.
	 * @return void
	 *
	 * @throws \RuntimeException When the legacy bootstrap file cannot be found.
	 */
	private static function boot_legacy_application(string $application_directory, ?application_definition $definition=null): void {
		$legacy_bootstrap=$definition?->legacy_bootstrap_file ?? ($application_directory.'/application_bootstrap.php');
		if(!is_file($legacy_bootstrap)){
			throw new \RuntimeException("Application bootstrap not found: {$legacy_bootstrap}");
		}
		require($legacy_bootstrap);
	}

	/**
	 * Loads an application's ROOTPATH definition before route or Framework bootstrap execution.
	 *
	 * @param application_definition $definition Loaded application definition.
	 * @return void
	 */
	private static function prime_rootpaths(application_definition $definition): void {
		if(defined('ROOTPATH') || empty($definition->rootpath_file) || !is_file($definition->rootpath_file)){
			return;
		}
		require($definition->rootpath_file);
	}

	/**
	 * Registers application-level autoload prefixes declared in the application definition.
	 *
	 * @param application_definition $definition Loaded application definition.
	 * @return void
	 */
	private static function register_application_autoload(application_definition $definition): void {
		if(empty($definition->autoload)){
			return;
		}
		autoloader::register_prefixes($definition->autoload);
	}
}
