<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Closes every inherited descriptor except stdio and the one-shot broker, then performs one exact exec. */
if(PHP_SAPI!=='cli' || ($argc ?? 0)<2 || !function_exists('pcntl_exec')
	|| !function_exists('dataphyre_close_unlisted_inherited_fds')
	|| dataphyre_close_unlisted_inherited_fds()!==true){
	exit(70);
}
$executable=$argv[1] ?? null;$arguments=array_slice($argv,2);
if(!is_string($executable) || !str_starts_with($executable,'/') || is_link($executable)
	|| !is_file($executable) || !is_executable($executable)
	|| !hash_equals($executable,(string)realpath($executable))){
	exit(70);
}
$environment=getenv();
if(!is_array($environment) || count($environment)>128) exit(70);
foreach($environment as $name=>$value){
	if(!is_string($name) || preg_match('/^[A-Z][A-Z0-9_]{0,119}$/D',$name)!==1
		|| !is_string($value) || strlen($value)>8192 || preg_match('/[\x00-\x1f\x7f]/D',$value)===1){
		exit(70);
	}
}
pcntl_exec($executable,$arguments,$environment);exit(70);
