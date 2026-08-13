<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

require_once dirname(__DIR__, 3).'/cli.php';

/** Composable command boundary for SQL entity/table artifact generation. */
final class dataphyre_sql_scaffold_command {
	/** Runs the command unless an embedding runtime requested library-only loading. */
	public static function dispatch_entrypoint(
		bool $no_dispatch,
		array $arguments,
		?callable $terminator=null,
		array $runtime=[]
	): void {
		if($no_dispatch){
			return;
		}
		$status=self::run($arguments, $runtime);
		$terminator??='\\dataphyre\\cli_terminate';
		$terminator($status);
	}

	/**
	 * Executes one scaffold command through injectable bootstrap and output seams.
	 *
	 * @param array<int,string> $arguments Full command argument vector.
	 * @param array<string,mixed> $runtime Optional environment, output, bootstrap, and scaffold seams.
	 */
	public static function run(array $arguments, array $runtime=[]): int {
		$write_out=$runtime['write_out'] ?? static fn(string $message): int|false=>fwrite(STDOUT, $message);
		$write_error=$runtime['write_error'] ?? static fn(string $message): int|false=>fwrite(STDERR, $message);
		$sapi=(string)($runtime['sapi'] ?? PHP_SAPI);
		if(!in_array($sapi, ['cli', 'phpdbg'], true)){
			($runtime['status'] ?? static fn(int $status): bool=>http_response_code($status))(404);
			$write_out("SQL table artifact scaffold is only available from CLI.\n");
			return 2;
		}
		if(in_array('--help', $arguments, true) || in_array('-h', $arguments, true) || in_array('help', $arguments, true)){
			$write_out(scaffold_table_artifacts_usage());
			return 0;
		}

		$runtime_root=(string)($runtime['runtime_root'] ?? dirname(__DIR__, 3));
		$package_root=dirname($runtime_root);
		$project_root=(string)($runtime['project_root'] ?? resolve_project_root($package_root));
		$bootstrap=$runtime['bootstrap'] ?? null;
		if(is_callable($bootstrap)){
			$bootstrap_state=$bootstrap($runtime_root);
		}else{
			require_once($runtime_root.'/bootstrap_config.php');
			$bootstrap_state=\dataphyre\bootstrap_config::resolve($runtime_root);
		}
		if(!defined('DATAPHYRE_BOOTSTRAP_CONFIG')){
			define('DATAPHYRE_BOOTSTRAP_CONFIG', $bootstrap_state['bootstrap']);
		}
		if(!defined('DATAPHYRE_MODULE_POLICY')){
			define('DATAPHYRE_MODULE_POLICY', $bootstrap_state['modules']);
		}
		$module_loader=$runtime['module_loader'] ?? null;
		if(is_callable($module_loader)){
			$module_loader($runtime_root);
		}else{
			require_once($runtime_root.'/modules/core/kernel/bootstrap.php');
			require_once($runtime_root.'/modules/core/kernel/core_functions.php');
			require_once($runtime_root.'/modules/core/kernel/application_definition.php');
			require_once($runtime_root.'/modules/core/kernel/app_locator.php');
			\dataphyre\autoloader::register($runtime_root.'/modules');
			\dataphyre\core::load_framework_module('sql');
			require_once($runtime_root.'/modules/sql/Framework/Tools/ScaffoldTableArtifacts.php');
		}

		[$options, $positionals]=parse_cli_arguments(array_slice($arguments, 1));
		$application_name=$options['application'] ?? ($positionals[0] ?? null);
		$entity_name=$options['entity'] ?? ($positionals[1] ?? null);
		$table_name=$options['table'] ?? ($positionals[2] ?? null);
		$primary_key=$options['primary-key'] ?? ($options['primary_key'] ?? ($positionals[3] ?? null));
		$columns=parse_columns($options['columns'] ?? array_slice($positionals, 4));
		$force=array_key_exists('force', $options);
		if(empty($application_name) || empty($entity_name) || empty($table_name) || empty($primary_key)){
			$write_error(scaffold_table_artifacts_usage());
			return 1;
		}

		$scaffolder=$runtime['scaffolder'] ?? static fn(...$parameters): array=>\Dataphyre\Database\Tools\ScaffoldTableArtifacts::scaffold(...$parameters);
		$result=$scaffolder(
			$project_root,
			(string)$application_name,
			(string)$entity_name,
			(string)$table_name,
			(string)$primary_key,
			$columns,
			$force
		);
		$write_out("Scaffolded {$result['entity']} for {$result['application']} in {$result['framework_directory']}\n");
		foreach($result['generated'] as $artifact=>$status){
			$write_out(sprintf("[%s] %s (%s)\n", $artifact, $status['path'], $status['status']));
		}
		return 0;
	}
}

/** Returns the command help text for output seams and native streams. */
function scaffold_table_artifacts_usage(): string {
	return "Usage: php runtime/modules/sql/kernel/scaffold_table_artifacts.php <application> <entity> <table> <primary_key> [columns]\n"
		."   or: php runtime/modules/sql/kernel/scaffold_table_artifacts.php --application=example_app --entity=Machine --table=machines --primary-key=machine_id --columns=machine_id,tenant_id,name,status [--force]\n"
		."Set DATAPHYRE_PROJECT_ROOT when scaffolding from a Composer vendor install.\n";
}

/**
 * Parses scaffold CLI flags and positional arguments.
 *
 * Long flags support --name=value and boolean --force style values. Non-flag
 * arguments are preserved in order so the command can support both terse and
 * explicit invocation forms.
 *
 * @param array<int, string> $arguments Raw CLI arguments after the script name.
 * @return array{0: array<string, mixed>, 1: array<int, string>} Parsed options and positional values.
 */
function parse_cli_arguments(array $arguments): array {
	$options=[];
	$positionals=[];
	foreach($arguments as $argument){
		$argument=(string)$argument;
		if(str_starts_with($argument, '--')===false){
			$positionals[]=$argument;
			continue;
		}
		$argument=substr($argument, 2);
		if($argument===''){
			continue;
		}
		[$name, $value]=array_pad(explode('=', $argument, 2), 2, true);
		$options[$name]=$value===true ? true : $value;
	}
	return [$options, $positionals];
}

/**
 * Resolves the project root used for application discovery.
 *
 * DATAPHYRE_PROJECT_ROOT wins when set. Embedded installs under common/ resolve
 * to the parent project; standalone packages resolve to the package root.
 *
 * @param string $package_root Runtime package root inferred from this script path.
 * @return string Normalized project root path without a trailing separator.
 */
function resolve_project_root(string $package_root): string {
	$env=getenv('DATAPHYRE_PROJECT_ROOT');
	if(is_string($env) && trim($env)!==''){
		$resolved=realpath($env);
		return rtrim($resolved!==false ? $resolved : $env, '/\\');
	}
	$parent=dirname($package_root);
	if(basename($parent)==='common'){
		$embedded_root=dirname($parent);
		$resolved=realpath($embedded_root);
		return rtrim($resolved!==false ? $resolved : $embedded_root, '/\\');
	}
	if(
		strtolower(basename($package_root))==='dataphyre'
		&& (is_file($parent.'/flight_sheet.php') || is_file($parent.'/dataphyre.project.json') || is_dir($parent.'/applications'))
	){
		$resolved=realpath($parent);
		return rtrim($resolved!==false ? $resolved : $parent, '/\\');
	}
	$resolved=realpath($package_root);
	return rtrim($resolved!==false ? $resolved : $package_root, '/\\');
}

/**
 * Normalizes column CLI input into a flat column list.
 *
 * Callers may pass one comma-delimited string, repeated positional chunks, or an
 * array of both. Empty entries are discarded before validation by the scaffold
 * service.
 *
 * @param string|array<int, string> $columns Raw --columns value or positional column chunks.
 * @return array<int, string> Trimmed non-empty column names.
 */
function parse_columns(string|array $columns): array {
	if(is_string($columns)){
		$columns=[$columns];
	}
	$normalized=[];
	foreach($columns as $chunk){
		foreach(explode(',', (string)$chunk) as $column){
			$column=trim($column);
			if($column===''){
				continue;
			}
			$normalized[]=$column;
		}
	}
	return $normalized;
}

dataphyre_sql_scaffold_command::dispatch_entrypoint(
	defined('DATAPHYRE_SQL_SCAFFOLD_NO_DISPATCH')===true,
	$argv ?? [],
);
