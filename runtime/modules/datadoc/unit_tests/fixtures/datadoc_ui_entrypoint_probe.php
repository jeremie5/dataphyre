<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	final class routing { public static array $bindings=[]; }

	final class core {
		public static function url_self(): string { return 'https://docs.example.test/'; }
		public static function url_self_updated_querystring(array $updates): string {
			return 'https://docs.example.test/dataphyre/datadoc?'.http_build_query($updates);
		}
	}

	final class datadoc {
		public static string $scenario='default';

		public static function logged_in(): bool { return self::$scenario!=='unauthenticated'; }
		public static function auth_context(): array {
			if(self::$scenario==='header-logged-out'){
				return ['logged_in'=>false,'can_logout'=>false,'label'=>''];
			}
			if(self::$scenario==='header-label'){
				return ['logged_in'=>true,'can_logout'=>false,'label'=>'Company SSO'];
			}
			return ['logged_in'=>true,'can_logout'=>true,'label'=>'Flightdeck console'];
		}
		public static function logout(): bool { return true; }
		public static function index_url(): string { return 'https://docs.example.test/dataphyre/datadoc'; }
		public static function get_project(string $project): ?array {
			if(self::$scenario==='missing-project'){ return null; }
			if(self::$scenario==='untitled-project'){ return ['id'=>1,'name'=>'docs','title'=>'','path'=>'']; }
			return ['id'=>1,'name'=>$project!=='' ? $project : 'docs','title'=>'Documentation','path'=>'/srv/docs'];
		}
		public static function normalize_manual_path(string|array $path): string {
			return trim(str_replace('\\','/',is_array($path) ? implode('/',$path) : $path),'/');
		}
		public static function get_manudoc(string $project,string $path): ?array {
			return match(self::$scenario){
				'manual-missing'=>null,
				'manual-array-html'=>['titles'=>'Array HTML','contents'=>['html'=>'<p>Nested HTML</p>']],
				'manual-array-json'=>['content'=>['payload'=>['one','two']]],
				default=>['title'=>'Getting started','contents'=>"<section id='intro'>Introduction</section><p>Welcome</p>"],
			};
		}
		public static function get_menu_branch(string $project,string $kind,array $path): array {
			return [['node_type'=>'record','record'=>['id'=>7,'type'=>'class','class'=>'Order']]];
		}
		public static function render_procedural_menu_nodes(string $project,string $kind,array $branch,array $path=[],int $depth=0): void {
			echo 'menu:'.$project.':'.$kind.':'.$depth.':'.count($branch);
		}
		public static function sync_project_file_if_changed(string $file,string $project): array {
			return ['changed'=>in_array(self::$scenario,['dynamic-refresh','dynamic-refresh-empty'],true),'deleted'=>false];
		}
	}
}

namespace dataphyre\datadoc {
	final class highlighter {
		public static function highlight_code(string $source,string $language='php',array $options=[]): string {
			return '<code>'.htmlspecialchars($source,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</code>';
		}
		public static function linkify_php(string $source,string $project,string $namespace='',string $class='',string $function=''): string {
			return $source;
		}
	}
}

namespace {
	final class DatadocFlightdeckAuthProbe {
		public static function login_url(string $return): string { return '/flightdeck/login?'.http_build_query(['return'=>$return]); }
	}

	function adapt(array $variants,mixed $theme=null): string {
		return (string)($variants['dark'] ?? $variants['light'] ?? '');
	}
	function sql_select(mixed ...$arguments): mixed {
		$table=(string)($arguments[1] ?? '');
		if($table==='datadoc.projects'){
			if(\dataphyre\datadoc::$scenario==='sidebar-unavailable'){ return false; }
			return [
				['id'=>1,'name'=>'docs','title'=>'Documentation'],
				['name'=>'api','title'=>''],
			];
		}
		static $dataCalls=0;
		$dataCalls++;
		if(
			$table!=='dataphyre.datadoc_data'
			|| \dataphyre\datadoc::$scenario==='dynamic-empty'
			|| (\dataphyre\datadoc::$scenario==='dynamic-refresh-empty' && $dataCalls>1)
		){
			return [];
		}
		$type=match(\dataphyre\datadoc::$scenario){
			'dynamic-namespace'=>'namespace',
			'dynamic-class-global','dynamic-invalid-tags'=>'class',
			'dynamic-variable'=>'variable',
			'dynamic-default'=>'trait',
			default=>'function',
		};
		$tags=\dataphyre\datadoc::$scenario==='dynamic-invalid-tags'
			? 'not-json'
			: json_encode([
				'version'=>'2.0','author'=>'Dataphyre','package'=>'Docs',
				'subpackage'=>\dataphyre\datadoc::$scenario==='dynamic-package' ? '' : 'Runtime',
				'example'=>'echo "example";','warning'=>'Be careful',
			],JSON_THROW_ON_ERROR);
		return [[
			'id'=>42,'project'=>'docs','type'=>$type,
			'namespace'=>in_array(\dataphyre\datadoc::$scenario,['dynamic-class-global','dynamic-variable'],true) ? '' : 'Acme\\Docs',
			'class'=>$type==='namespace' ? '' : 'Order',
			'function'=>$type==='function' ? 'approve' : '',
			'content'=>$type==='variable'
				? 'status'
				: (\dataphyre\datadoc::$scenario==='dynamic-private'
					? 'private function approve(): bool { return true; }'
					: 'protected static function approve(): bool { return true; }'),
			'file'=>'/srv/docs/Order.php','line'=>17,
			'phpdoc_description'=>'Approves an order.','phpdoc_tags'=>$tags,
		]];
	}
	function sql_count(mixed ...$arguments): mixed {
		return \dataphyre\datadoc::$scenario==='dashboard-nonnumeric' ? 'unknown' : 3;
	}

	$ui=rtrim((string)($argv[1] ?? ''),'/\\');
	$view=basename((string)($argv[2] ?? ''),'.php');
	$scenario=(string)($argv[3] ?? 'default');
	$root=rtrim((string)($argv[4] ?? ''),'/\\').DIRECTORY_SEPARATOR;
	$allowed=['dynadoc_menu_processor','dynamic_document','footer','header','index','left_sidebar','login','manual_document','project_dashboard','project_settings'];
	if($ui==='' || !in_array($view,$allowed,true) || $root===DIRECTORY_SEPARATOR){
		throw new InvalidArgumentException('DataDoc UI directory, supported view, scenario, and root are required.');
	}
	define('ROOTPATH',['dataphyre'=>$root]);
	\dataphyre\datadoc::$scenario=$scenario;
	\dataphyre\routing::$bindings=['project'=>'docs','documentid'=>'guides/getting-started'];
	if($scenario==='manual-root'){
		\dataphyre\routing::$bindings['documentid']='';
	}
	if($scenario==='menu-query-project'){
		\dataphyre\routing::$bindings=[];
	}
	if($scenario==='login-flightdeck'){
		class_alias(DatadocFlightdeckAuthProbe::class,'dataphyre_flightdeck_auth');
	}
	$_GET=[]; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Standalone DataDoc UI fixture must model the native query-string boundary."
	$_SERVER=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/dataphyre/datadoc']; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Standalone DataDoc UI fixture must model the native HTTP request boundary."
	if($scenario==='header-logout'){
		$_GET['logout']=1; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Header fixture must exercise the native logout query boundary."
	}
	if($scenario==='menu-invalid-path'){
		$_GET=['kind'=>'manual','path'=>'not-json']; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Menu fixture must exercise malformed native query input."
	}
	if($scenario==='menu-query-project'){
		$_GET=['project'=>'docs','kind'=>'dynamic','path'=>'[]']; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Menu fixture must exercise the native query fallback when route bindings are absent."
	}
	if($scenario==='dynamic-filters'){
		$_GET=['namespace'=>'Acme%','class'=>'Order','type'=>'function','function'=>'approve','content'=>'approve']; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Dynamic document fixture must exercise every native filter boundary."
	}
	require $ui.'/'.$view.'.php';
}
