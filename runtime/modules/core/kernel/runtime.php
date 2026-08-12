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
		if(self::boot_compiled_routes($definition)===true){
			return;
		}
		if(self::boot_framework_application($definition)===true){
			return;
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
	 * Dispatches Dataphyre-owned routes before application route stacks take over.
	 *
	 * Framework-only applications do not load the legacy global routing config, so internal
	 * scheduler callbacks must be recognized at the common runtime boundary. The route is
	 * intentionally exact, GET-only, internal-traffic-only, and protected by a short-lived
	 * purpose-bound signature before the scheduling kernel or task file can execute.
	 *
	 * @param application_definition $definition Loaded application definition.
	 * @param array<string,mixed> $runtime Optional deterministic request, verifier, loader, response, and runner seams.
	 * @return bool `true` when the request belonged to a Dataphyre internal route.
	 */
	private static function boot_internal_runtime_route(application_definition $definition, array $runtime=[]): bool {
		$server=is_array($runtime['server'] ?? null) ? $runtime['server'] : $_SERVER;
		$scheduler_name=self::scheduler_route_name($server);
		if($scheduler_name===null){
			return false;
		}

		self::prime_rootpaths($definition);
		$respond=$runtime['respond'] ?? static function(int $status, string $body): void {
			http_response_code($status);
			if(!headers_sent()){
				header('Content-Type: text/plain; charset=utf-8');
				header('Cache-Control: no-store');
			}
			echo $body;
		};
		$token=trim((string)($server['HTTP_X_DATAPHYRE_SCHEDULER_KEY'] ?? ''));
		$dispatch_claim=trim((string)($server['HTTP_X_DATAPHYRE_SCHEDULER_CLAIM'] ?? ''));
		$verify=$runtime['verify'] ?? static fn(string $candidate, string $name, string $claim): bool =>
			function_exists('dp_verify_shared_request_key')
			&& dp_verify_shared_request_key($candidate, 'app_override_key', 'scheduler_dispatch', $name.'|'.$claim, 1);
		$authorized=strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'))==='GET'
			&& (string)($server['HTTP_X_TRAFFIC_SOURCE'] ?? '')==='internal_traffic'
			&& preg_match('/^[a-f0-9]{64}$/D', $dispatch_claim)===1
			&& $token!==''
			&& is_callable($verify)
			&& $verify($token, $scheduler_name, $dispatch_claim)===true;
		if($authorized!==true){
			$respond(404, 'Not found');
			return true;
		}

		$core_loader=$runtime['core_loader'] ?? static function(): bool {
			$core_entry=__DIR__.'/core.main.php';
			if(!is_file($core_entry)){
				return false;
			}
			require_once $core_entry;
			return class_exists('dataphyre\\core', false);
		};
		if(!is_callable($core_loader) || $core_loader()!==true){
			$respond(503, 'Scheduler unavailable');
			return true;
		}

		$loader=$runtime['module_loader'] ?? static fn(string $module): bool =>
			method_exists('dataphyre\\core', 'load_framework_module')
			&& \dataphyre\core::load_framework_module($module);
		$loaded=is_callable($loader) && $loader('scheduling')===true;
		$scheduling_available=array_key_exists('scheduling_available', $runtime)
			? (bool)$runtime['scheduling_available']
			: class_exists('dataphyre\\scheduling', false);
		if($loaded!==true && $scheduling_available!==true){
			$respond(503, 'Scheduler unavailable');
			return true;
		}

		$runner=$runtime['task_runner'] ?? static function(string $name, string $claim): void {
			if(!class_exists('dataphyre_scheduling_task_runner', false)){
				if(!defined('DATAPHYRE_SCHEDULING_TASK_RUNNER_NO_DISPATCH')){
					define('DATAPHYRE_SCHEDULING_TASK_RUNNER_NO_DISPATCH', true);
				}
				require dirname(__DIR__, 2).'/scheduling/kernel/task_runner.php';
			}
			\dataphyre_scheduling_task_runner::dispatch(null, null, [
				'scheduler_name'=>$name,
				'scheduler_claim'=>$claim,
			]);
		};
		$runner($scheduler_name, $dispatch_claim);
		return true;
	}

	/**
	 * Extracts one exact path-safe scheduler name from the current request URI.
	 *
	 * @param array<string,mixed> $server Request server values.
	 * @return ?string Scheduler name, or null when this is not the internal scheduler route.
	 */
	private static function scheduler_route_name(array $server): ?string {
		$path=parse_url((string)($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
		if(!is_string($path)){
			return null;
		}
		$path=rawurldecode($path);
		if(preg_match('#^/dataphyre/scheduler/([A-Za-z0-9._-]{1,128})$#D', $path, $matches)!==1){
			return null;
		}
		$name=(string)$matches[1];
		return in_array($name, ['.', '..'], true) ? null : $name;
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
