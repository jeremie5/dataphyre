<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$kernel=realpath((string)($argv[1] ?? ''));
if(!is_string($kernel) || !is_dir($kernel)){
	fwrite(STDERR,"Core kernel fixture root is unavailable.\n");
	exit(64);
}

try{
	require $kernel.'/application_runtime_cgi_environment.php';
	fwrite(STDERR,"Invalid CGI role was unexpectedly accepted.\n");
	exit(70);
}catch(RuntimeException $failure){
	echo json_encode([
		'contract'=>'dataphyre.application_runtime_cgi_environment_boundary.v1',
		'rejected'=>true,
		'error'=>$failure->getMessage(),
	],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
}
