<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

const DATAPHYRE_APPLICATION_DATABASE_RUNTIME_CONTRACT='dataphyre.application_database_runtime.v1';
const DATAPHYRE_APPLICATION_DATABASE_MARKER='DATAPHYRE_CLOUD_DATABASE_BINDING_PRIMARY_SHA256';

/** Emit only the bounded, value-free identity contract. */
function dataphyre_application_database_result(bool $ok, ?string $connectionSha256=null): never {
	while(ob_get_level()>0){
		ob_end_clean();
	}
	$payload=[
		'contract'=>DATAPHYRE_APPLICATION_DATABASE_RUNTIME_CONTRACT,
		'ok'=>$ok,
		'purpose'=>'primary',
		'connection_sha256'=>$connectionSha256,
	];
	$encoded=json_encode($payload, JSON_UNESCAPED_SLASHES);
	fwrite(STDOUT, (is_string($encoded) ? $encoded : '{"contract":"dataphyre.application_database_runtime.v1","ok":false,"purpose":"primary","connection_sha256":null}')."\n");
	exit($ok ? 0 : 69);
}

/** @return array{project_root:string,application:string,environment:string} */
function dataphyre_application_database_options(array $arguments): array {
	$options=[];
	foreach(array_slice($arguments, 1) as $argument){
		if(!is_string($argument) || preg_match('/^--(project-root|application|environment)=(.+)$/D', $argument, $match)!==1){
			throw new RuntimeException('invalid_options');
		}
		$key=str_replace('-', '_', $match[1]);
		if(isset($options[$key]) || strlen($match[2])>4096 || preg_match('/[\x00-\x1f\x7f]/', $match[2])===1){
			throw new RuntimeException('invalid_options');
		}
		$options[$key]=$match[2];
	}
	if(array_keys($options)!==['project_root', 'application', 'environment']
		|| in_array($options['application'], ['.', '..'], true)
		|| preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D', $options['application'])!==1
		|| preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $options['environment'])!==1){
		throw new RuntimeException('invalid_options');
	}
	$resolved=realpath($options['project_root']);
	if($resolved===false || !is_dir($resolved) || !is_readable($resolved)){
		throw new RuntimeException('invalid_project');
	}
	$options['project_root']=rtrim(str_replace('\\', '/', $resolved), '/');
	return $options;
}

/** @return array<string,mixed> */
function dataphyre_application_database_definition(string $applicationRoot, string $application): array {
	$definition=\dataphyre\application_definition::from_conventions($application, $applicationRoot);
	$file=$applicationRoot.'/app.php';
	$value=require $file;
	if($value instanceof \dataphyre\application_definition){
		$definition=$value;
	}elseif(is_array($value)){
		$definition=$definition->with_overrides($value);
	}else{
		throw new RuntimeException('invalid_application_definition');
	}
	return ['definition'=>$definition, 'root'=>$applicationRoot];
}

/** @return array<string,mixed> */
function dataphyre_application_database_rootpaths(\dataphyre\application_definition $definition, string $runtimeRoot): array {
	if(is_string($definition->rootpath_file) && $definition->rootpath_file!=='' && is_file($definition->rootpath_file)){
		require $definition->rootpath_file;
	}
	if(defined('ROOTPATH') && is_array(ROOTPATH)){
		return ROOTPATH;
	}
	$applicationRoot=rtrim($definition->root_directory, '/\\');
	$dataphyreRoot=is_dir($applicationRoot.'/backend/dataphyre')
		? $applicationRoot.'/backend/dataphyre'
		: $applicationRoot.'/dataphyre';
	return [
		'root'=>$applicationRoot.'/',
		'dataphyre'=>rtrim($dataphyreRoot, '/\\').'/',
		'common_dataphyre'=>rtrim(dirname($runtimeRoot), '/\\').'/',
		'common_dataphyre_runtime'=>rtrim($runtimeRoot, '/\\').'/',
	];
}

/** @return array<string,mixed> */
function dataphyre_application_database_cluster(array $sql, array $core): array {
	$clusterName=trim((string)($sql['default_cluster'] ?? ''));
	$datacenter=trim((string)($core['datacenter'] ?? ''));
	$cluster=$sql['datacenters'][$datacenter]['dbms_clusters'][$clusterName] ?? null;
	if($clusterName==='' || $datacenter==='' || !is_array($cluster)){
		throw new RuntimeException('database_cluster_unavailable');
	}
	$dbms=strtolower(trim((string)($cluster['dbms'] ?? '')));
	if(!in_array($dbms, ['postgres', 'postgresql', 'yugabyte', 'yugabytedb'], true)){
		throw new RuntimeException('database_engine_unsupported');
	}
	return $cluster;
}

/** @return array{database:string,user:string} */
function dataphyre_application_database_connect(array $cluster): array {
	$endpoints=$cluster['endpoints'] ?? [];
	if(!is_array($endpoints) || !array_is_list($endpoints) || $endpoints===[]){
		throw new RuntimeException('database_endpoint_unavailable');
	}
	$port=(int)($cluster['dbms_port'] ?? 5432);
	$database=trim((string)($cluster['database_name'] ?? ''));
	$user=trim((string)($cluster['dbms_username'] ?? ''));
	$password=(string)($cluster['password'] ?? '');
	if($port<1 || $port>65535 || $database==='' || $user===''){
		throw new RuntimeException('database_configuration_invalid');
	}
	foreach($endpoints as $endpoint){
		$host=trim((string)$endpoint);
		if($host==='' || preg_match('/^[A-Za-z0-9._:-]{1,253}$/D', $host)!==1){
			continue;
		}
		try{
			$pdo=new PDO(
				'pgsql:host='.$host.';port='.$port.';dbname='.$database.';connect_timeout=5',
				$user,
				$password,
				[
					PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
					PDO::ATTR_EMULATE_PREPARES=>false,
				]
			);
			$row=$pdo->query('SELECT current_database() AS database_name, current_user AS database_user')->fetch();
			if(is_array($row) && is_string($row['database_name'] ?? null) && is_string($row['database_user'] ?? null)){
				return ['database'=>$row['database_name'], 'user'=>$row['database_user']];
			}
		}catch(Throwable){
			continue;
		}
	}
	throw new RuntimeException('database_connection_failed');
}

/** Execute the fixed database identity child. */
function dataphyre_application_database_main(array $arguments): never {
	ob_start();
	ini_set('display_errors', '0');
	try{
	$options=dataphyre_application_database_options($arguments);
	$marker=trim((string)(getenv(DATAPHYRE_APPLICATION_DATABASE_MARKER) ?: ''));
	if(preg_match('/^sha256:[0-9a-f]{64}$/D', $marker)!==1){
		throw new RuntimeException('database_marker_invalid');
	}
	$runtimeRoot=rtrim(str_replace('\\', '/', dirname(__DIR__, 3)), '/');
	$_SERVER['DATAPHYRE_PROJECT_ROOT']=$options['project_root'];
	$_SERVER['DATAPHYRE_ENVIRONMENT']=$options['environment'];
	putenv('DATAPHYRE_ENVIRONMENT='.$options['environment']);
	if(!defined('DATAPHYRE_PROJECT_ROOT')) define('DATAPHYRE_PROJECT_ROOT', $options['project_root'].'/');
	if(!defined('DATAPHYRE_RUNTIME_ROOT')) define('DATAPHYRE_RUNTIME_ROOT', $runtimeRoot.'/');
	if(!defined('APP')) define('APP', $options['application']);
	if(!defined('RUN_MODE')) define('RUN_MODE', 'preflight');

	require_once $runtimeRoot.'/bootstrap_config.php';
	require_once $runtimeRoot.'/modules/core/kernel/application_definition.php';
	require_once $runtimeRoot.'/modules/core/kernel/app_locator.php';
	require_once $runtimeRoot.'/modules/core/kernel/autoloader.php';
	$bootstrap=\dataphyre\bootstrap_config::resolve($runtimeRoot);
	if(!defined('DATAPHYRE_MODULE_POLICY')) define('DATAPHYRE_MODULE_POLICY', $bootstrap['modules']);
	if(!defined('DATAPHYRE_APPLICATION_ROOTS')) define('DATAPHYRE_APPLICATION_ROOTS', $bootstrap['application_roots']);
	\dataphyre\autoloader::register($runtimeRoot.'/modules');
	$applicationRoot=\dataphyre\app_locator::locate(
		$options['project_root'],
		$options['application'],
		$bootstrap['application_roots']
	);
	if($applicationRoot===null){
		throw new RuntimeException('application_unavailable');
	}
	$resolved=dataphyre_application_database_definition($applicationRoot, $options['application']);
	$definition=$resolved['definition'];
	\dataphyre\autoloader::register_prefixes($definition->autoload);
	$GLOBALS['DATAPHYRE_HELPER_ROOTPATH_OVERRIDE']=dataphyre_application_database_rootpaths($definition, $runtimeRoot);
	require_once $runtimeRoot.'/modules/core/kernel/helper_functions.php';
	$core=dp_define_core_config('DP_CORE_CFG');
	$sql=dp_define_module_config('sql', 'DP_SQL_CFG');
	$cluster=dataphyre_application_database_cluster($sql, $core);
	$identity=dataphyre_application_database_connect($cluster);
	$connectionSha='sha256:'.hash(
		'sha256',
		"dataphyre.cloud.database_connection.v1\0".$marker."\0".$identity['database']."\0".$identity['user']
	);
		dataphyre_application_database_result(true, $connectionSha);
	}catch(Throwable){
		dataphyre_application_database_result(false);
	}
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__){
	dataphyre_application_database_main(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []);
}
