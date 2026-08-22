<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\Seeds\SeedContext;
use Dataphyre\Database\Seeds\SeedDefinition;
use Dataphyre\Database\Seeds\SeedFileLoader;
use Dataphyre\Database\Seeds\SeedLedger;
use Dataphyre\Database\Seeds\SeedManager;
use Dataphyre\Database\Seeds\SqlSeedLedger;

$dp_seed_framework=dirname(__DIR__).'/Framework/Seeds';
foreach(['SeedDefinition','SeedContext','SeedExecutionException','SeedLedger','SeedFileLoader','SeedManager','SqlSeedLedger'] as $dp_seed_class){
	require_once $dp_seed_framework.'/'.$dp_seed_class.'.php';
}

/**
 * Runs the Dataphyre SQL seed command without forcing process exit in tests.
 *
 * @param array<int,string> $argv CLI argument vector.
 */
function dp_sql_seed_main(
	array $argv,
	?bool $cli=null,
	?callable $write_out=null,
	?callable $write_err=null,
	?SeedManager $manager=null,
): int {
	$cli??=PHP_SAPI==='cli';
	$write_out??=static function(string $message): void { echo $message; };
	$write_err??=static function(string $message): void { fwrite(STDERR, $message); };
	$json_requested=in_array('--json', $argv, true);
	$requested_command=isset($argv[1]) && !str_starts_with((string)$argv[1], '--') ? strtolower(trim((string)$argv[1])) : 'help';
	if(!$cli){
		http_response_code(404);
		$write_out("SQL seed management is only available from CLI.\n");
		return 2;
	}
	try{
		$options=dp_sql_seed_options($argv);
		$command=$options['command'];
		if($command==='help'){
			dp_sql_seed_usage($write_out);
			return 0;
		}
		if($command==='reset'){
			throw new RuntimeException('Destructive seed reset is intentionally unsupported. Roll back one explicitly reversible seed at a time.');
		}
		if(!in_array($command, ['list', 'status', 'apply', 'rollback'], true)){
			throw new RuntimeException('Unknown seed command: '.$command);
		}
		$data_environment=$options['data_environment'] ?? null;
		$execute=static function() use (&$manager,$options,$command): mixed {
			$manager??=dp_sql_seed_manager($options);
			return match($command){
				'list'=>$manager->catalog(),
				'status'=>$manager->status(),
				'apply'=>($options['dry_run'] ?? false)
					? ['dry_run'=>true, 'pending'=>$manager->planApply($options['ids'])]
					: $manager->apply($options['ids']),
				'rollback'=>dp_sql_seed_rollback_command($manager, $options),
			};
		};
		$result=$manager===null
			? dp_sql_seed_in_prepared_runtime($options,$execute)
			: dp_sql_seed_in_data_environment($data_environment,$execute);
		dp_sql_seed_write_result($command, $result, (bool)$options['json'], $write_out);
		return 0;
	}catch(Throwable $throwable){
		if($json_requested){
			$write_err(json_encode([
				'ok'=>false,
				'command'=>$requested_command,
				'error'=>$throwable->getMessage(),
			], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES).PHP_EOL);
		}else{
			$write_err($throwable->getMessage().PHP_EOL);
		}
		return 1;
	}
}

(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__) && exit(dp_sql_seed_main(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []));

/** Runs definition discovery, ledger access, and callbacks inside one configured data environment. */
function dp_sql_seed_in_data_environment(mixed $data_environment,callable $execute): mixed {
	if(!is_string($data_environment) || $data_environment==='' || $data_environment==='live') return $execute();
	if(!class_exists(\Dataphyre\Database\DataEnvironment::class)){
		throw new RuntimeException('Dataphyre data environments are unavailable for seed management.');
	}
	return \Dataphyre\Database\DataEnvironment::run($data_environment,$execute);
}

/**
 * Establishes the selected environment identity before the fixed application bootstrap.
 *
 * The bootstrap is a trusted configuration/autoload phase and is not part of the seed
 * transaction. It may not load Dataphyre SQL itself. Definition discovery, callbacks,
 * ledger changes, and convergence execute later inside the configured environment.
 */
function dp_sql_seed_in_prepared_runtime(array $options,callable $execute): mixed {
	$environment=dp_sql_seed_prepare_runtime_environment($options);
	return dp_sql_seed_in_resolved_environment($environment,$execute);
}

/**
 * Loads trusted startup/configuration under the selected identity, then resolves
 * the configured environment after that temporary frame has been fully released.
 *
 * @return array{name:string,cluster:?string,cache_namespace:?string}
 */
function dp_sql_seed_prepare_runtime_environment(array $options): array {
	$prepared=dp_sql_seed_resolve_runtime($options);
	$data_environment=$options['data_environment'] ?? null;
	$name=is_string($data_environment) && $data_environment!=='' ? $data_environment : 'live';
	$dataEnvironmentFile=$prepared['runtime_root'].'/modules/sql/Framework/DataEnvironment.php';
	if(is_link($dataEnvironmentFile) || !is_file($dataEnvironmentFile)
		|| !hash_equals($dataEnvironmentFile,(string)realpath($dataEnvironmentFile))){
		throw new RuntimeException('Dataphyre data-environment bootstrap is unavailable.');
	}
	require_once $dataEnvironmentFile;
	\Dataphyre\Database\DataEnvironment::run(
		$name,
		static function() use ($prepared): void {
			dp_sql_seed_boot_prepared_runtime($prepared,true);
		},
		['cluster'=>null,'cache_namespace'=>$name==='live' ? null : $name],
	);
	$environment=\Dataphyre\Database\DataEnvironment::run(
		$name,
		static fn(array $current): array=>$current,
	);
	return [
		'name'=>(string)$environment['name'],
		'cluster'=>is_string($environment['cluster']) ? $environment['cluster'] : null,
		'cache_namespace'=>is_string($environment['cache_namespace']) ? $environment['cache_namespace'] : null,
	];
}

/** Runs work inside one already-resolved immutable data-environment frame. */
function dp_sql_seed_in_resolved_environment(array $environment,callable $execute): mixed {
	$name=$environment['name'] ?? null;
	$cluster=$environment['cluster'] ?? null;
	$cacheNamespace=$environment['cache_namespace'] ?? null;
	if(!is_string($name) || $name==='' || ($cluster!==null && !is_string($cluster))
		|| ($cacheNamespace!==null && !is_string($cacheNamespace))){
		throw new RuntimeException('Resolved seed data environment is invalid.');
	}
	return \Dataphyre\Database\DataEnvironment::run(
		$name,
		static fn(): mixed=>$execute(),
		['cluster'=>$cluster,'cache_namespace'=>$cacheNamespace],
	);
}

/** @return array{command:string,paths:list<string>,ids:list<string>,profiles:list<string>,app:?string,bootstrap:?string,project_root:?string,ledger_table:string,cluster:?string,data_environment:?string,json:bool,dry_run:bool,confirm:bool,allow_demo:bool} */
function dp_sql_seed_options(array $argv): array {
	$options=[
		'command'=>'help',
		'paths'=>[],
		'ids'=>[],
		'profiles'=>['default'],
		'app'=>null,
		'bootstrap'=>null,
		'project_root'=>null,
		'ledger_table'=>'dataphyre_seed_ledger',
		'cluster'=>null,
		'data_environment'=>null,
		'json'=>false,
		'dry_run'=>false,
		'confirm'=>false,
		'allow_demo'=>false,
	];
	$command_set=false;
	$profile_set=false;
	foreach(array_slice($argv, 1) as $argument){
		$argument=trim((string)$argument);
		if($argument==='--help' || $argument==='-h' || $argument==='help'){
			$options['command']='help';
			$command_set=true;
			continue;
		}
		if(!str_starts_with($argument, '--')){
			if($command_set){
				throw new RuntimeException('Unexpected positional seed argument: '.$argument);
			}
			$options['command']=strtolower($argument);
			$command_set=true;
			continue;
		}
		if($argument==='--json'){
			$options['json']=true;
			continue;
		}
		if($argument==='--dry-run'){
			$options['dry_run']=true;
			continue;
		}
		if($argument==='--confirm' || $argument==='--force'){
			$options['confirm']=true;
			continue;
		}
		if($argument==='--allow-demo'){
			$options['allow_demo']=true;
			continue;
		}
		[$name, $value]=array_pad(explode('=', substr($argument, 2), 2), 2, null);
		if($value===null || trim($value)===''){
			throw new RuntimeException('Seed option requires a value: --'.$name);
		}
		switch($name){
			case 'path':
				$options['paths'][]=$value;
				break;
			case 'id':
				foreach(explode(',', $value) as $id){
					$id=strtolower(trim($id));
					if($id==='' || preg_match('/^[a-z][a-z0-9._:-]{0,190}(?:@[1-9][0-9]*)?$/', $id)!==1){
						throw new RuntimeException('Invalid seed selector in --id: '.($id==='' ? '<empty>' : $id));
					}
					$options['ids'][]=$id;
				}
				break;
			case 'profile':
				if(!$profile_set){
					$options['profiles']=[];
					$profile_set=true;
				}
				foreach(explode(',', $value) as $profile){
					$profile=strtolower(trim($profile));
					if(preg_match('/^[a-z][a-z0-9._:-]{0,63}$/', $profile)!==1){
						throw new RuntimeException('Invalid seed profile: '.($profile==='' ? '<empty>' : $profile));
					}
					$options['profiles'][]=$profile;
				}
				break;
			case 'app':
			case 'bootstrap':
			case 'cluster':
				$options[$name]=trim($value);
				break;
			case 'data-environment':
				$value=strtolower(trim($value));
				if(preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$value)!==1){
					throw new RuntimeException('Invalid seed data environment: '.$value);
				}
				$options['data_environment']=$value;
				break;
			case 'project-root':
				$options['project_root']=trim($value);
				break;
			case 'ledger-table':
				$options['ledger_table']=trim($value);
				break;
			default:
				throw new RuntimeException('Unknown seed option: --'.$name);
		}
	}
	$options['paths']=array_values(array_unique($options['paths']));
	$options['ids']=array_values(array_unique($options['ids']));
	$options['profiles']=array_values(array_unique($options['profiles']));
	return $options;
}

/** @param array<string,mixed> $options */
function dp_sql_seed_manager(array $options): SeedManager {
	$profiles=is_array($options['profiles'] ?? null) ? array_values($options['profiles']) : ['default'];
	if(in_array('demo',$profiles,true) && ($options['allow_demo'] ?? false)!==true){
		throw new RuntimeException('The demo seed profile requires explicit --allow-demo acknowledgement.');
	}
	$prepared=dp_sql_seed_prepare_runtime($options);
	$project_root=$prepared['project_root'];$app=$prepared['application'];

	$paths=$options['paths'] ?? [];
	if($paths===[] && defined('DP_SQL_CFG') && is_array(DP_SQL_CFG['seeds']['paths'] ?? null)){
		$paths=array_values(DP_SQL_CFG['seeds']['paths']);
	}
	if($paths===[]){
		$paths[]=$app!==''
			? $project_root.'/applications/'.$app.'/database/seeds'
			: $project_root.'/database/seeds';
	}
	$paths=array_map(static fn(string $path): string=>dp_sql_seed_absolute_path($project_root, $path), $paths);
	$definitions=SeedFileLoader::load($paths,$project_root);
	$ledger_table=(string)($options['ledger_table'] ?? 'dataphyre_seed_ledger');
	if($ledger_table==='dataphyre_seed_ledger' && defined('DP_SQL_CFG')){
		$ledger_table=(string)(DP_SQL_CFG['seeds']['ledger_table'] ?? $ledger_table);
	}
	$cluster=isset($options['cluster']) && is_string($options['cluster']) ? trim($options['cluster']) : null;
	$cluster=$cluster!=='' ? $cluster : null;
	$data_environment=isset($options['data_environment']) && is_string($options['data_environment'])
		? $options['data_environment'] : null;
	if($cluster!==null && $data_environment!==null){
		throw new RuntimeException('Seed cluster and data environment cannot both be selected.');
	}
	if($data_environment!==null && $data_environment!=='live'){
		$config=defined('DP_SQL_CFG') && is_array(DP_SQL_CFG) ? DP_SQL_CFG : [];
		$definition=$config['data_environments'][$data_environment] ?? null;
		$resolved=is_array($definition) ? trim((string)($definition['cluster'] ?? '')) : '';
		if($resolved==='' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D',$resolved)!==1){
			throw new RuntimeException('Seed data environment has no configured SQL cluster.');
		}
		$cluster=$resolved;
	}
	$context=new SeedContext(null, null, [
		'project_root'=>$project_root,
		'application'=>$options['app'] ?? null,
		'seed_paths'=>$paths,
		'database_cluster'=>$cluster,
		'data_environment'=>$data_environment,
		'seed_profiles'=>$profiles,
		'allow_demo'=>($options['allow_demo'] ?? false)===true,
	], $cluster);
	return new SeedManager(
		$definitions,
		new SqlSeedLedger($ledger_table, $cluster, null, null, $context->dbms()),
		$context,
		$profiles,
	);
}

/** @param array<string,mixed> $options @return array{runtime_root:string,project_root:string,application:string,bootstrap:?string} */
function dp_sql_seed_resolve_runtime(array $options): array {
	$runtime_root=dirname(__DIR__,3);$package_root=dirname($runtime_root);
	$project_root=dp_sql_seed_project_root($package_root,$options['project_root'] ?? null);
	$app=trim((string)($options['app'] ?? ''));
	$bootstrap=dp_sql_seed_bootstrap_path($project_root,$app,$options['bootstrap'] ?? null);
	return ['runtime_root'=>$runtime_root,'project_root'=>$project_root,'application'=>$app,'bootstrap'=>$bootstrap];
}

/** Loads one already-resolved trusted application bootstrap, then boots Dataphyre SQL. */
function dp_sql_seed_boot_prepared_runtime(array $prepared,bool $reject_sql_bootstrap=false): void {
	$runtime_root=$prepared['runtime_root'] ?? null;$bootstrap=$prepared['bootstrap'] ?? null;
	if(!is_string($runtime_root) || $runtime_root==='' || ($bootstrap!==null && !is_string($bootstrap))){
		throw new RuntimeException('Seed runtime preparation is invalid.');
	}
	$sqlWasLoaded=class_exists('\dataphyre\sql',false);
	if($bootstrap!==null){
		if(is_link($bootstrap) || !is_file($bootstrap) || !is_readable($bootstrap)
			|| !hash_equals(str_replace('\\','/',$bootstrap),str_replace('\\','/',(string)realpath($bootstrap)))){
			throw new RuntimeException('Seed bootstrap must be one readable regular non-symbolic file.');
		}
		require_once $bootstrap;
	}
	if($reject_sql_bootstrap && !$sqlWasLoaded && class_exists('\dataphyre\sql',false)){
		throw new RuntimeException('The application seed bootstrap loaded Dataphyre SQL before its managed transaction.');
	}
	dp_sql_seed_boot_sql($runtime_root);
}

/** @param array<string,mixed> $options @return array{runtime_root:string,project_root:string,application:string} */
function dp_sql_seed_prepare_runtime(array $options): array {
	$prepared=dp_sql_seed_resolve_runtime($options);
	dp_sql_seed_boot_prepared_runtime($prepared);
	return [
		'runtime_root'=>$prepared['runtime_root'],'project_root'=>$prepared['project_root'],
		'application'=>$prepared['application'],
	];
}

/**
 * Resolves an explicit seed bootstrap or the conventional optional app bootstrap.
 *
 * Explicit paths always win. App names are validated before being interpolated
 * into a project path, preventing `--app` traversal outside applications/.
 */
function dp_sql_seed_bootstrap_path(string $project_root, string $app, ?string $explicit=null): ?string {
	$app=trim($app);
	if($app!=='' && preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D',$app)!==1){
		throw new RuntimeException('Invalid seed application name: '.$app);
	}
	if($explicit!==null && trim($explicit)!==''){
		return dp_sql_seed_absolute_path($project_root, $explicit);
	}
	if($app===''){
		return null;
	}
	$conventional=rtrim($project_root, '/\\').'/applications/'.$app.'/database/seeds/bootstrap.php';
	return is_file($conventional) ? $conventional : null;
}

function dp_sql_seed_boot_sql(string $runtime_root): void {
	if(class_exists('\dataphyre\sql', false)){
		dp_sql_seed_boot_cache($runtime_root);
		return;
	}
	if(!defined('RUN_MODE')) define('RUN_MODE', 'migration');
	$_SESSION['db_cache']=$_SESSION['db_cache'] ?? [];
	$_SESSION['db_cache_count']=$_SESSION['db_cache_count'] ?? 0;
	require_once $runtime_root.'/bootstrap_config.php';
	$bootstrap_state=\dataphyre\bootstrap_config::resolve($runtime_root);
	if(!defined('DATAPHYRE_BOOTSTRAP_CONFIG')) define('DATAPHYRE_BOOTSTRAP_CONFIG', $bootstrap_state['bootstrap']);
	if(!defined('DATAPHYRE_MODULE_POLICY') && isset($bootstrap_state['modules']) && is_array($bootstrap_state['modules'])){
		define('DATAPHYRE_MODULE_POLICY', $bootstrap_state['modules']);
	}
	require_once $runtime_root.'/modules/core/kernel/bootstrap.php';
	require_once $runtime_root.'/modules/core/kernel/core_functions.php';
	require_once $runtime_root.'/modules/core/kernel/helper_functions.php';
	if(!defined('DP_CORE_CFG')){
		dp_define_core_config();
	}
	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}
	if(!function_exists('dataphyre_shutdown_log')){
		function dataphyre_shutdown_log(string $message, ?object $exception=null): void {}
	}
	\dataphyre\autoloader::register($runtime_root.'/modules');
	\dataphyre\core::load_framework_module('sql');
	if(!class_exists('\dataphyre\sql', false)){
		require_once $runtime_root.'/modules/sql/kernel/sql.main.php';
	}
	if(!class_exists('\dataphyre\sql', false)){
		throw new RuntimeException('Dataphyre SQL could not be booted for seed management.');
	}
	dp_sql_seed_boot_cache($runtime_root);
}

/**
 * Loads the optional cache kernel selected by the host module policy.
 *
 * SQL seeding boots outside the ordinary request flight, so framework-only SQL
 * loading does not otherwise expose the cache facade. Loading the policy-approved
 * kernel here lets inferred seed writes advance the same shared table generations
 * as web and worker processes. A missing or disabled cache module remains optional.
 */
function dp_sql_seed_boot_cache(string $runtime_root): void {
	if(class_exists('\dataphyre\cache', false)) return;
	$present=false;
	if(function_exists('dp_module_present')){
		$present=dp_module_present('cache');
	}elseif(class_exists('\dataphyre\module_registry', false)){
		$present=\dataphyre\module_registry::kernel_module_present('cache');
	}
	if(!is_array($present)) return;
	$entry=$present[0] ?? null;
	if(!is_string($entry) || !is_file($entry)) return;
	require_once $entry;
	if(!class_exists('\dataphyre\cache', false)){
		throw new RuntimeException('Dataphyre cache could not be booted for SQL seed invalidation.');
	}
}

/** @param array<string,mixed> $options @return array<string,mixed> */
function dp_sql_seed_rollback_command(SeedManager $manager, array $options): array {
	$ids=$options['ids'] ?? [];
	if(count($ids)!==1){
		throw new RuntimeException('Rollback requires exactly one --id=<seed-id[@version]>.');
	}
	if(($options['dry_run'] ?? false)===true){
		return ['dry_run'=>true, 'rollback'=>$manager->planRollback($ids[0])];
	}
	return $manager->rollback($ids[0], (bool)($options['confirm'] ?? false));
}

function dp_sql_seed_project_root(string $package_root, ?string $override=null): string {
	$override=$override!==null && trim($override)!=='' ? trim($override) : (string)(getenv('DATAPHYRE_PROJECT_ROOT') ?: '');
	if($override!==''){
		$resolved=realpath($override);
		return rtrim($resolved!==false ? $resolved : $override, '/\\');
	}
	$parent=dirname($package_root);
	if(strtolower(basename($parent))==='common'){
		return rtrim(dirname($parent), '/\\');
	}
	if(strtolower(basename($package_root))==='dataphyre' && (is_dir($parent.'/applications') || is_file($parent.'/flight_sheet.php'))){
		return rtrim($parent, '/\\');
	}
	return rtrim($package_root, '/\\');
}

function dp_sql_seed_absolute_path(string $project_root, string $path): string {
	$path=trim($path);
	$windows_absolute=strlen($path)>=3
		&& ctype_alpha($path[0])
		&& $path[1]===':'
		&& ($path[2]==='/' || $path[2]==='\\');
	if($path!=='' && ($path[0]==='/' || $path[0]==='\\' || $windows_absolute)){
		return rtrim($path, '/\\');
	}
	return rtrim($project_root, '/\\').'/'.ltrim($path, '/\\');
}

function dp_sql_seed_usage(callable $write): void {
	$write("Usage: php runtime/modules/sql/kernel/seeds.php <list|status|apply|rollback> [options]\n");
	$write("  --app=<name>                 Use applications/<name>/database/seeds\n");
	$write("  --path=<path>                Add a seed file or directory (repeatable)\n");
	$write("  --bootstrap=<file>           Load an application-owned CLI SQL bootstrap\n");
	$write("  --project-root=<path>        Override application/seed discovery root\n");
	$write("  --cluster=<name>             Bind callbacks, ledger queries, and transaction to one cluster\n");
	$write("  --data-environment=<name>    Bind cluster and cache namespace to one configured data environment\n");
	$write("  --ledger-table=<name>        Override the portable seed ledger table\n");
	$write("  --id=<id[,id@version]>       Select seeds; rollback requires exactly one\n");
	$write("  --profile=<name>              Activate a non-default seed profile (repeatable)\n");
	$write("  --allow-demo                  Explicitly acknowledge demo-profile data\n");
	$write("  --dry-run                    Plan without invoking seed callbacks\n");
	$write("  --confirm                    Required for rollback execution\n");
	$write("  --json                       Emit machine-readable output\n");
	$write("Reset-all is intentionally unavailable.\n");
}

function dp_sql_seed_write_result(string $command, mixed $result, bool $json, callable $write): void {
	if($json){
		$write(json_encode(['ok'=>true, 'command'=>$command, 'result'=>$result], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
		return;
	}
	if($command==='list' || $command==='status'){
		$rows=is_array($result) ? $result : [];
		$write("Key\tStatus\tRollback\tDescription\n");
		foreach($rows as $row){
			if(!is_array($row)) continue;
			$write(implode("\t", [
				(string)($row['key'] ?? ''),
				(string)($row['status'] ?? 'defined'),
				($row['rollback_available'] ?? false) ? 'yes' : 'no',
				(string)($row['description'] ?? ''),
			]).PHP_EOL);
		}
		return;
	}
	$write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
}
