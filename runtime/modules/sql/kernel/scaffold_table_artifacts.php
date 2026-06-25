<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

$runtime_root=dirname(__DIR__, 3);
$package_root=dirname($runtime_root);
$project_root=resolve_project_root($package_root);

require_once($runtime_root.'/modules/core/kernel/bootstrap.php');
require_once($runtime_root.'/modules/core/kernel/core_functions.php');
\dataphyre\autoloader::register($runtime_root.'/modules');
\dataphyre\core::load_framework_module('sql');

[$options, $positionals]=parse_cli_arguments(array_slice($argv, 1));

$application_name=$options['application'] ?? ($positionals[0] ?? null);
$entity_name=$options['entity'] ?? ($positionals[1] ?? null);
$table_name=$options['table'] ?? ($positionals[2] ?? null);
$primary_key=$options['primary-key'] ?? ($options['primary_key'] ?? ($positionals[3] ?? null));
$columns=parse_columns($options['columns'] ?? array_slice($positionals, 4));
$force=array_key_exists('force', $options);

if(empty($application_name) || empty($entity_name) || empty($table_name) || empty($primary_key)){
	fwrite(STDERR, "Usage: php runtime/modules/sql/kernel/scaffold_table_artifacts.php <application> <entity> <table> <primary_key> [columns]\n");
	fwrite(STDERR, "   or: php runtime/modules/sql/kernel/scaffold_table_artifacts.php --application=example_app --entity=Machine --table=machines --primary-key=machine_id --columns=machine_id,tenant_id,name,status [--force]\n");
	exit(1);
}

$result=\Dataphyre\Database\Tools\ScaffoldTableArtifacts::scaffold(
	$project_root,
	(string)$application_name,
	(string)$entity_name,
	(string)$table_name,
	(string)$primary_key,
	$columns,
	$force
);

fwrite(STDOUT, "Scaffolded {$result['entity']} for {$result['application']} in {$result['framework_directory']}\n");
foreach($result['generated'] as $artifact=>$status){
	fwrite(STDOUT, sprintf("[%s] %s (%s)\n", $artifact, $status['path'], $status['status']));
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
