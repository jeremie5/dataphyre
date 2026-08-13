<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

final class McpModuleBootstrapProbe {
	/** @var list<array<int,mixed>> */
	public static array $events=[];
}

function tracelog(mixed ...$arguments): void {
	McpModuleBootstrapProbe::$events[]=$arguments;
}

$target=(string)($argv[1] ?? '');
if($target==='' || !is_file($target)){
	fwrite(STDERR,"MCP module bootstrap target is unavailable.\n");
	exit(2);
}

require $target;

$events=[];
foreach(McpModuleBootstrapProbe::$events as $arguments){
	$events[]=[
		'file'=>basename((string)($arguments[0] ?? '')),
		'line'=>(int)($arguments[1] ?? 0),
		'class'=>(string)($arguments[2] ?? ''),
		'function'=>(string)($arguments[3] ?? ''),
		'message'=>(string)($arguments[4] ?? ''),
	];
}

echo json_encode(['events'=>$events],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
