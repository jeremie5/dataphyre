<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

$project_root=dirname(__DIR__, 4);

require_once($project_root.'/common/dataphyre/modules/core/kernel/bootstrap.php');
require_once($project_root.'/common/dataphyre/modules/core/kernel/core_functions.php');
\dataphyre\core::load_framework_module('sql');

[$options, $positionals]=parse_cli_arguments(array_slice($argv, 1));

$application_name=$options['application'] ?? ($positionals[0] ?? null);
$entity_name=$options['entity'] ?? ($positionals[1] ?? null);
$table_name=$options['table'] ?? ($positionals[2] ?? null);
$primary_key=$options['primary-key'] ?? ($options['primary_key'] ?? ($positionals[3] ?? null));
$columns=parse_columns($options['columns'] ?? array_slice($positionals, 4));
$force=array_key_exists('force', $options);

if(empty($application_name) || empty($entity_name) || empty($table_name) || empty($primary_key)){
	fwrite(STDERR, "Usage: php common/dataphyre/modules/sql/kernel/scaffold_table_artifacts.php <application> <entity> <table> <primary_key> [columns]\n");
	fwrite(STDERR, "   or: php common/dataphyre/modules/sql/kernel/scaffold_table_artifacts.php --application=volumetrix --entity=Machine --table=machines --primary-key=machine_id --columns=machine_id,tenant_id,name,status [--force]\n");
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
