<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$kernel=realpath((string)($argv[1] ?? ''));
if(getmypid()!==1 || !is_string($kernel) || !is_file($kernel.'/application_runtime_environment.php')) exit(64);
require_once $kernel.'/application_runtime_environment.php';
$setup=(string)($argv[6] ?? '');
if($setup==='symlink' && !symlink('/dev/null',DataphyreApplicationRuntimeEnvironment::CHANNEL)) exit(70);
try{
	$result=DataphyreApplicationRuntimeEnvironment::consume(
		(string)($argv[2] ?? ''),(string)($argv[3] ?? ''),(string)($argv[4] ?? ''),(string)($argv[5] ?? ''),
	);
	echo json_encode(['ok'=>true,'result'=>$result],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
	exit(0);
}catch(RuntimeException $failure){
	echo json_encode(['ok'=>false,'error'=>$failure->getMessage()],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
	exit(78);
}
