<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP controller, middleware, and MVC inspection surfaces.
 */
trait dataphyre_mcp_inspection_mvc_surfaces {

	/**
	 * Summarizes MVC controller classes and literal handler references from source files.
	 *
	 * scans bounded repo-local PHP roots, tokenizes source, and reports public controller actions plus
	 * Controller@action strings. Controllers are not autoloaded, instantiated, resolved through containers, or invoked.
	 *
	 * @param array{paths?: array<int, string>, limit?: int} $args Optional scan roots and file limit.
	 * @return array{write_policy: string, execution: string, scanned_files: int, controller_count: int, handler_reference_count: int, controllers: array, handler_references: array, notes: array<int, string>} Controller source inventory.
	 */
	private function controller_source_summary(array $args): array {
		$limit=max(1, min((int)($args['limit'] ?? 80) ?: 80, 250));
		$roots=[];
		if(is_array($args['paths'] ?? null) && $args['paths']!==[]){
			foreach($args['paths'] as $path){
				$roots[]=(string)$path;
			}
		}else{
			$roots[]='common/dataphyre/runtime/modules/mvc';
		}
		$files=[];
		foreach($roots as $root){
			$safe=$this->safe_repo_path($root);
			if(is_file($safe)){
				if(strtolower(pathinfo($safe, PATHINFO_EXTENSION))==='php'){
					$files[]=$safe;
				}
				continue;
			}
			if(is_dir($safe)){
				foreach($this->all_files($safe, $limit * 8) as $file){
					if(strtolower(pathinfo($file, PATHINFO_EXTENSION))!=='php'){
						continue;
					}
					$relative=strtolower(str_replace('\\', '/', $this->relative_path($file)));
					if(str_contains($relative, '/documentation/') || str_contains($relative, '/vendor/')){
						continue;
					}
					$files[]=$file;
					if(count($files)>=$limit){
						break 2;
					}
				}
			}
			if(count($files)>=$limit){
				break;
			}
		}
		$controllers=[];
		$handler_references=[];
		foreach(array_slice(array_values(array_unique($files)), 0, $limit) as $file){
			$summary=$this->php_source_api_file_summary($file);
			foreach($summary['classes'] ?? [] as $class){
				$name=(string)($class['fqcn'] ?? $class['name'] ?? '');
				$short=(string)($class['name'] ?? '');
				if(!str_ends_with($short, 'Controller') && $short!=='Controller'){
					continue;
				}
				$actions=[];
				foreach($class['methods'] ?? [] as $method){
					if(($method['visibility'] ?? 'public')!=='public'){
						continue;
					}
					$method_name=(string)($method['name'] ?? '');
					if($method_name===''){
						continue;
					}
					$actions[]=[
						'name'=>$method_name,
						'line'=>$method['line'] ?? null,
						'static'=>$method['static'] ?? false,
						'signature'=>$method['signature'] ?? '',
						'resource_action'=>in_array($method_name, ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy', '__invoke'], true),
					];
				}
				$controllers[]=[
					'file'=>$summary['path'] ?? $this->relative_path($file),
					'name'=>$name,
					'line'=>$class['line'] ?? null,
					'action_count'=>count($actions),
					'actions'=>$actions,
				];
			}
			$handler_references=array_merge($handler_references, $this->controller_handler_references_from_text((string)file_get_contents($file), $file));
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'route_safety'=>$this->route_safety_contract('controller_source_summary'),
			'scanned_files'=>count(array_unique($files)),
			'controller_count'=>count($controllers),
			'handler_reference_count'=>count($handler_references),
			'controllers'=>array_slice($controllers, 0, 180),
			'handler_references'=>array_slice($handler_references, 0, 240),
			'notes'=>[
				'Only PHP source tokens and literal Controller@action strings are inspected.',
				'Controller classes are not autoloaded, instantiated, or invoked.',
				'Protected helper methods on base controllers are intentionally omitted from action summaries.',
			],
		];
	}

	/**
	 * Finds literal controller action handler strings inside source text.
	 *
	 * regex extraction is intentionally narrow and reports only strings shaped like controller
	 * handlers. Missing action names normalize to __invoke so route provenance callers can compare handler forms.
	 *
	 * @param string $text PHP source text.
	 * @param string $path Absolute source path for location reporting.
	 * @return array<int, array{file: string, line: int, class: string, method: string, handler: string}> Handler references.
	 */
	private function controller_handler_references_from_text(string $text, string $path): array {
		$references=[];
		if(preg_match_all('/([\'"])([A-Za-z_\\\\][A-Za-z0-9_\\\\]*Controller)(?:@([A-Za-z_][A-Za-z0-9_]*))?\1/', $text, $matches, PREG_OFFSET_CAPTURE)===false){
			return [];
		}
		foreach($matches[2] ?? [] as $index=>$match){
			$class=(string)$match[0];
			$method=(string)($matches[3][$index][0] ?? '');
			$offset=(int)($match[1] ?? 0);
			$references[]=[
				'file'=>$this->relative_path($path),
				'line'=>$this->line_number_for_offset($text, $offset),
				'class'=>$class,
				'method'=>$method!=='' ? $method : '__invoke',
				'handler'=>$class.'@'.($method!=='' ? $method : '__invoke'),
			];
		}
		return $references;
	}

	/**
	 * Summarizes middleware classes, route middleware declarations, and MVC middleware config keys.
	 *
	 * reports tokenized class and literal alias data only. Middleware is not autoloaded, constructed,
	 * chained, or executed, and config values are treated as source-level surfaces rather than live application state.
	 *
	 * @param array{paths?: array<int, string>, limit?: int} $args Optional scan roots and file limit.
	 * @return array{write_policy: string, execution: string, scanned_files: int, middleware_class_count: int, declaration_count: int, config_surface_count: int, builtin_routing_aliases: array, middleware_classes: array, declarations: array, config_surfaces: array, notes: array<int, string>} Middleware source inventory.
	 */
	private function middleware_source_summary(array $args): array {
		$limit=max(1, min((int)($args['limit'] ?? 80) ?: 80, 250));
		$roots=[];
		if(is_array($args['paths'] ?? null) && $args['paths']!==[]){
			foreach($args['paths'] as $path){
				$roots[]=(string)$path;
			}
		}else{
			$roots[]='common/dataphyre/runtime/modules/mvc';
			$roots[]='common/dataphyre/runtime/modules/routing';
		}
		$files=[];
		foreach($roots as $root){
			$safe=$this->safe_repo_path($root);
			if(is_file($safe)){
				if(strtolower(pathinfo($safe, PATHINFO_EXTENSION))==='php'){
					$files[]=$safe;
				}
				continue;
			}
			if(is_dir($safe)){
				foreach($this->all_files($safe, $limit * 8) as $file){
					if(strtolower(pathinfo($file, PATHINFO_EXTENSION))!=='php'){
						continue;
					}
					$relative=strtolower(str_replace('\\', '/', $this->relative_path($file)));
					if(str_contains($relative, '/documentation/') || str_contains($relative, '/vendor/')){
						continue;
					}
					$files[]=$file;
					if(count($files)>=$limit){
						break 2;
					}
				}
			}
			if(count($files)>=$limit){
				break;
			}
		}
		$middleware_classes=[];
		$declarations=[];
		$config_surfaces=[];
		foreach(array_slice(array_values(array_unique($files)), 0, $limit) as $file){
			$summary=$this->php_source_api_file_summary($file);
			foreach($summary['classes'] ?? [] as $class){
				$short=(string)($class['name'] ?? '');
				$methods=$class['methods'] ?? [];
				$handle=null;
				foreach($methods as $method){
					if(($method['name'] ?? null)==='handle' && ($method['visibility'] ?? 'public')==='public'){
						$handle=$method;
						break;
					}
				}
				if($handle===null && !str_ends_with($short, 'Middleware')){
					continue;
				}
				$middleware_classes[]=[
					'file'=>$summary['path'] ?? $this->relative_path($file),
					'name'=>$class['fqcn'] ?? $short,
					'line'=>$class['line'] ?? null,
					'has_handle'=>$handle!==null,
					'handle_signature'=>$handle['signature'] ?? null,
				];
			}
			$text=(string)file_get_contents($file);
			$declarations=array_merge($declarations, $this->middleware_declarations_from_file($text, $file));
			$config_surfaces=array_merge($config_surfaces, $this->middleware_config_surfaces_from_text($text, $file));
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'route_safety'=>$this->route_safety_contract('middleware_source_summary'),
			'scanned_files'=>count(array_unique($files)),
			'middleware_class_count'=>count($middleware_classes),
			'declaration_count'=>count($declarations),
			'config_surface_count'=>count($config_surfaces),
			'builtin_routing_aliases'=>$this->builtin_routing_middleware_aliases(),
			'middleware_classes'=>array_slice($middleware_classes, 0, 180),
			'declarations'=>array_slice($declarations, 0, 260),
			'config_surfaces'=>array_slice($config_surfaces, 0, 120),
			'notes'=>[
				'Only source tokens and literal middleware strings are inspected.',
				'Middleware classes are not autoloaded, instantiated, or invoked.',
				'MVC app middleware config values are reported as source-level key usage, not runtime config values.',
			],
		];
	}

	/**
	 * Extracts literal middleware declarations from one PHP source buffer.
	 *
	 * recognizes middleware calls in route/controller chains and ordinary calls, returning literal
	 * aliases and normalized alias keys while leaving dynamic middleware expressions unreported.
	 *
	 * @param string $text PHP source text.
	 * @param string $path Absolute path used for source locations.
	 * @return array<int, array{file: string, line: mixed, scope: string, values: array<int, string>, aliases: array<int, string>}> Middleware declarations.
	 */
	private function middleware_declarations_from_file(string $text, string $path): array {
		$tokens=token_get_all($text);
		$declarations=[];
		for($i=0, $count=count($tokens); $i<$count; $i++){
			$token=$tokens[$i];
			if(!is_array($token) || $token[0]!==T_STRING || strtolower($token[1])!=='middleware'){
				continue;
			}
			$args=$this->call_arguments_after_token($tokens, $i);
			if($args===null){
				continue;
			}
			$values=[];
			foreach($args as $arg){
				$literal=$this->literal_string_from_expression($arg);
				if($literal!==null){
					$values[]=$literal;
				}
			}
			if($values===[]){
				continue;
			}
			$scope=$this->previous_meaningful_token_id($tokens, $i)===T_OBJECT_OPERATOR ? 'route_or_controller_chain' : 'call';
			$declarations[]=[
				'file'=>$this->relative_path($path),
				'line'=>$token[2] ?? null,
				'scope'=>$scope,
				'values'=>$values,
				'aliases'=>array_values(array_unique(array_map(static fn(string $value): string => strtolower(strtok($value, ':') ?: $value), $values))),
			];
		}
		return $declarations;
	}

	/**
	 * Locates middleware-related config keys inside a PHP source buffer.
	 *
	 * detects key presence and line numbers only, avoiding config evaluation and value disclosure.
	 * This makes the result suitable for planning middleware provenance without reading secrets or bootstrapping apps.
	 *
	 * @param string $text PHP source text.
	 * @param string $path Absolute path used for source locations.
	 * @return array<int, array{file: string, line: int, config_key: string}> Middleware config key sightings.
	 */
	private function middleware_config_surfaces_from_text(string $text, string $path): array {
		$surfaces=[];
		foreach(['middleware', 'middleware_stack', 'global_middleware', 'middleware_groups'] as $key){
			if(preg_match_all('/[\'"]'.preg_quote($key, '/').'[\'"]\s*=>/', $text, $matches, PREG_OFFSET_CAPTURE)===false){
				continue;
			}
			foreach($matches[0] ?? [] as $match){
				$surfaces[]=[
					'file'=>$this->relative_path($path),
					'line'=>$this->line_number_for_offset($text, (int)$match[1]),
					'config_key'=>$key,
				];
			}
		}
		return $surfaces;
	}

	/**
	 * Defines built-in routing middleware aliases known to the MCP source inspector.
	 *
	 * this is static metadata used for documentation and planning. It does not prove that a module is
	 * installed, configured, or enabled for a specific application.
	 *
	 * @return array<string, array{class: string, modules: array<int, string>}> Alias map.
	 */
	private function builtin_routing_middleware_aliases(): array {
		return [
			'auth'=>['class'=>'Dataphyre\\Access\\Middleware\\Authenticate', 'modules'=>['access']],
			'guest'=>['class'=>'Dataphyre\\Access\\Middleware\\Guest', 'modules'=>['access']],
			'can'=>['class'=>'Dataphyre\\Permission\\Middleware\\Authorize', 'modules'=>['permission']],
			'permission'=>['class'=>'Dataphyre\\Permission\\Middleware\\Authorize', 'modules'=>['permission']],
			'can_any'=>['class'=>'Dataphyre\\Permission\\Middleware\\AuthorizeAny', 'modules'=>['permission']],
			'permission_any'=>['class'=>'Dataphyre\\Permission\\Middleware\\AuthorizeAny', 'modules'=>['permission']],
			'can_when'=>['class'=>'Dataphyre\\Permission\\Middleware\\AuthorizeWhen', 'modules'=>['permission']],
			'permission_when'=>['class'=>'Dataphyre\\Permission\\Middleware\\AuthorizeWhen', 'modules'=>['permission']],
			'can_any_when'=>['class'=>'Dataphyre\\Permission\\Middleware\\AuthorizeAnyWhen', 'modules'=>['permission']],
			'permission_any_when'=>['class'=>'Dataphyre\\Permission\\Middleware\\AuthorizeAnyWhen', 'modules'=>['permission']],
		];
	}

	/**
	 * Summarizes MVC configuration contracts and relevant public source APIs.
	 *
	 * tokenizes first-party MVC files and reports the config keys, route-source forms, middleware
	 * defaults, and planning tools that define the MVC boundary. No application object is created and no config value or
	 * route source file is executed.
	 *
	 * @return array<string, mixed> Read-only MVC configuration contract summary.
	 */
	private function mvc_config_static_summary(): array {
		$files=[
			'common/dataphyre/runtime/modules/mvc/Framework/Mvc.php',
			'common/dataphyre/runtime/modules/mvc/Framework/MvcManager.php',
			'common/dataphyre/runtime/modules/mvc/Framework/MvcApplication.php',
			'common/dataphyre/runtime/modules/mvc/Framework/MvcDispatcher.php',
			'common/dataphyre/runtime/modules/mvc/Framework/RouteCollection.php',
			'common/dataphyre/runtime/modules/mvc/Framework/RouteDefinition.php',
			'common/dataphyre/runtime/modules/mvc/kernel/cache_routes.php',
			'common/dataphyre/runtime/modules/mvc/kernel/clear_cached_routes.php',
			'common/dataphyre/runtime/modules/mvc/kernel/route_list.php',
		];
		$classes=[];
		$existing=[];
		foreach($files as $relative){
			if(!is_file($this->root.'/'.$relative)){
				continue;
			}
			$existing[]=$relative;
			$summary=$this->php_source_api_file_summary($this->safe_repo_path($relative));
			foreach($summary['classes'] ?? [] as $class){
				$methods=[];
				foreach($class['methods'] ?? [] as $method){
					if(($method['visibility'] ?? 'public')!=='public'){
						continue;
					}
					$name=(string)($method['name'] ?? '');
					if(in_array($name, ['config', 'app', 'register', 'defaultApp', 'routes', 'routeList', 'appConfig', 'mergeMiddlewareDefaults', 'manifestCacheFile', 'manifestCacheEnabled', 'routeSources', 'controllerNamespace', 'modelNamespace', 'viewPath'], true)){
						$methods[]=[
							'name'=>$name,
							'static'=>$method['static'] ?? false,
							'signature'=>$method['signature'] ?? '',
						];
					}
				}
				if($methods===[]){
					continue;
				}
				$classes[]=[
					'file'=>$summary['path'] ?? $relative,
					'name'=>$class['fqcn'] ?? $class['name'] ?? '',
					'public_methods'=>$methods,
				];
			}
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'module'=>'mvc',
			'route_safety'=>$this->route_safety_contract('mvc_config_static_summary'),
			'config_files'=>$existing,
			'classes'=>$classes,
			'config_contract'=>[
				'top_level_keys'=>['default_app', 'apps', 'controllers', 'models', 'views', 'middleware', 'global_middleware', 'middleware_stack', 'middleware_groups', 'providers', 'model_bindings', 'signed_url_secret', 'routes', 'manifest_cache', 'response_headers', 'not_found_handler', 'error_handler'],
				'per_app_inherited_keys'=>['controllers', 'models', 'views', 'middleware', 'global_middleware', 'middleware_stack', 'middleware_groups', 'providers', 'model_bindings', 'signed_url_secret', 'routes', 'manifest_cache', 'response_headers', 'not_found_handler', 'error_handler'],
				'namespace_keys'=>['controllers.namespace', 'models.namespace'],
				'view_keys'=>['views.path'],
				'route_source_forms'=>['closure', 'route_file', 'route_directory', 'single_route_array', 'route_array_list', 'RouteDefinition instance', 'callable returning route data'],
				'route_array_keys'=>['path', 'method', 'methods', 'handler', 'view', 'template', 'redirect', 'location', 'redirect_route', 'to_route', 'parameters', 'query', 'status', 'name', 'middleware', 'where', 'defaults'],
				'manifest_cache_forms'=>['false_or_null_disabled', 'true_default_cache_path', 'string_cache_file', 'array_file_key'],
				'secret_keys_redacted'=>['signed_url_secret'],
			],
			'middleware_defaults'=>[
				'auth'=>'Dataphyre\\Mvc\\AccessMiddleware',
				'cache'=>'Dataphyre\\Mvc\\CacheMiddleware',
				'can'=>'Dataphyre\\Mvc\\PermissionMiddleware',
				'can_any'=>'Dataphyre\\Mvc\\PermissionAnyMiddleware',
				'csrf'=>'Dataphyre\\Mvc\\CsrfMiddleware',
				'guest'=>'Dataphyre\\Mvc\\GuestMiddleware',
				'session'=>'Dataphyre\\Mvc\\SessionMiddleware',
				'signed'=>'Dataphyre\\Mvc\\SignedUrlMiddleware',
				'throttle'=>'Dataphyre\\Mvc\\ThrottleMiddleware',
			],
			'planning_tools'=>[
				'dataphyre_list_config_keys',
				'dataphyre_config_shape_read',
				'dataphyre_route_source_static_summary',
				'dataphyre_controller_source_summary',
				'dataphyre_middleware_source_summary',
				'dataphyre_route_manifest_read',
			],
			'safety_notes'=>[
				'This summary tokenizes first-party MVC source files only.',
				'No MVC application is created, no route file is required, no provider is booted, and no config secret value is read.',
			],
		];
	}

	/**
	 * Describes MVC route cache command surfaces and manifest cache semantics.
	 *
	 * reads command source text to identify options, write/delete behavior, and cache contracts. The
	 * command scripts are not invoked, so no manifest is written, listed through runtime routes, or deleted.
	 *
	 * @return array<string, mixed> Route cache summary and safety notes.
	 */
	private function mvc_route_cache_summary(): array {
		$scripts=[
			'list'=>'common/dataphyre/runtime/modules/mvc/kernel/route_list.php',
			'cache'=>'common/dataphyre/runtime/modules/mvc/kernel/cache_routes.php',
			'clear'=>'common/dataphyre/runtime/modules/mvc/kernel/clear_cached_routes.php',
		];
		$script_summaries=[];
		foreach($scripts as $name=>$path){
			$text=$this->read_repo_text($path, 120000);
			$script_summaries[$name]=[
				'path'=>$path,
				'exists'=>is_file($this->root.'/'.$path),
				'cli_only'=>str_contains($text, "PHP_SAPI!=='cli'"),
				'options'=>$this->mvc_route_cache_script_options($text),
				'writes_cache'=>str_contains($text, 'try_write_manifest_file') || str_contains($text, 'tryWriteManifestFile'),
				'deletes_cache'=>str_contains($text, 'unlink($cache_file)'),
				'lists_routes'=>str_contains($text, 'routeList') || str_contains($text, 'routes()->list()'),
			];
		}
		return [
			'write_policy'=>'read_only_summary',
			'execution'=>'not_executed',
			'module'=>'mvc',
			'route_safety'=>$this->route_safety_contract('mvc_route_cache_summary'),
			'scripts'=>$script_summaries,
			'commands'=>[
				'list_table'=>'php common/dataphyre/runtime/modules/mvc/kernel/route_list.php --app=default',
				'list_json'=>'php common/dataphyre/runtime/modules/mvc/kernel/route_list.php --app=default --json',
				'list_with_config'=>'php common/dataphyre/runtime/modules/mvc/kernel/route_list.php --config=common/dataphyre/config/mvc.example.php --app=default --json',
				'cache'=>'php common/dataphyre/runtime/modules/mvc/kernel/cache_routes.php --config=common/dataphyre/config/mvc.example.php --app=default',
				'clear'=>'php common/dataphyre/runtime/modules/mvc/kernel/clear_cached_routes.php --config=common/dataphyre/config/mvc.example.php --app=default',
			],
			'manifest_cache_contract'=>[
				'config_key'=>'manifest_cache',
				'enabled_when'=>'MvcApplication::manifestCacheFile() returns a non-null cache file.',
				'forms'=>['true uses default cache path when ROOTPATH is available', 'string path', 'array with file key', 'false/null disabled'],
				'signature_inputs'=>['app name', 'route collection revision', 'route source mtimes'],
				'exportability_rule'=>'RouteCompiler::try_write_manifest_file skips non-exportable manifests, including closures that cannot be safely exported.',
			],
			'safety_notes'=>[
				'This MCP tool does not invoke route_list.php, cache_routes.php, or clear_cached_routes.php.',
				'cache_routes.php can write a manifest file, and clear_cached_routes.php can delete it; run those manually or through future unsafe-gated tooling only.',
				'Use dataphyre_route_source_static_summary, dataphyre_mvc_config_static_summary, and dataphyre_route_manifest_read for read-only planning before cache commands.',
			],
		];
	}

	/**
	 * Extracts supported CLI option markers from MVC route cache script source.
	 *
	 * simple source-text detection supports the cache summary without parsing or running the CLI
	 * script. Only known option prefixes are reported.
	 *
	 * @param string $text Route cache script source.
	 * @return array<int, string> Option markers present in the script.
	 */
	private function mvc_route_cache_script_options(string $text): array {
		$options=[];
		foreach(['--app=', '--config=', '--format=', '--json'] as $option){
			if(str_contains($text, $option)){
				$options[]=$option;
			}
		}
		return $options;
	}

}
