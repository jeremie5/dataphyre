<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
	fwrite(STDERR, "Dataphyre MCP config generator is CLI-only.\n");
	exit(2);
}

$workspace=dataphyre_mcp_config_workspace_root(__DIR__);
if(!is_array($workspace)){
	fwrite(STDERR, "Unable to resolve Dataphyre source checkout root. Expected common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php below a workspace root or runtime/modules/mcp/kernel/dataphyre_mcp.php below a standalone checkout.\n");
	exit(2);
}
$root=(string)$workspace['cwd'];
$server=(string)$workspace['server'];

$php=dataphyre_mcp_config_option($argv, '--php')
	?? dataphyre_mcp_config_env('DATAPHYRE_PHP')
	?? (PHP_BINARY !== '' ? PHP_BINARY : 'php');
$unsafe=in_array('--allow-unsafe', $argv, true);
$args=[$server];
if($unsafe){
	$args[]='--allow-unsafe';
}

$config=[
	'mcpServers'=>[
		'dataphyre'=>[
			'command'=>$php,
			'args'=>$args,
			'cwd'=>$root,
		],
	],
];

echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

function dataphyre_mcp_config_option(array $argv, string $name): ?string {
	foreach($argv as $index=>$argument){
		if($argument===$name && isset($argv[$index + 1])){
			return (string)$argv[$index + 1];
		}
		if(str_starts_with((string)$argument, $name.'=')){
			return substr((string)$argument, strlen($name)+1);
		}
	}
	return null;
}

function dataphyre_mcp_config_env(string $name): ?string {
	$value=getenv($name);
	if(!is_string($value) || trim($value)===''){
		return null;
	}
	return $value;
}

function dataphyre_mcp_config_workspace_root(string $tool_dir): ?array {
	$real_tool_dir=realpath($tool_dir);
	if(!is_string($real_tool_dir)){
		return null;
	}
	$candidates=[
		realpath($real_tool_dir.'/../../../../..'),
		getcwd() ?: null,
	];
	foreach($candidates as $candidate){
		if(!is_string($candidate) || $candidate===''){
			continue;
		}
		$root=rtrim(str_replace('\\', '/', $candidate), '/');
		if(is_file($root.'/common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php')){
			return [
				'cwd'=>$root,
				'server'=>'common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php',
			];
		}
		if(is_file($root.'/runtime/modules/mcp/kernel/dataphyre_mcp.php')){
			return [
				'cwd'=>$root,
				'server'=>'runtime/modules/mcp/kernel/dataphyre_mcp.php',
			];
		}
	}
	return null;
}
