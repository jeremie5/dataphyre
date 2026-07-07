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

if(in_array('--help', $argv, true) || in_array('-h', $argv, true)){
	echo <<<'HELP'
Usage:
  php dev/tools/public/mcp_config.php [--php <path-or-command>] [--allow-unsafe]

Options:
  --php           PHP executable to place in the generated MCP client config.
                  Defaults to DATAPHYRE_PHP, then the current PHP binary.
  --allow-unsafe  Include --allow-unsafe in the generated server args.
  -h, --help      Show this help text.

Prints a JSON MCP client config for the local Dataphyre source tree.

HELP;
	exit(0);
}

$workspace=dataphyre_mcp_config_workspace_root(__DIR__);
if(!is_array($workspace)){
	fwrite(STDERR, "Unable to resolve Dataphyre source tree root. Expected common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php below a workspace root or runtime/modules/mcp/kernel/dataphyre_mcp.php below a standalone source tree.\n");
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
