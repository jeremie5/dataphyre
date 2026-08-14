<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$kernel=realpath((string)($argv[1] ?? ''));
if(!is_string($kernel) || !is_file($kernel.'/application_runtime_environment.php')) exit(64);
require_once $kernel.'/application_runtime_environment.php';
try{DataphyreApplicationRuntimeEnvironment::assertCleanRootEnvironment(['HOME'=>'/root']);}
catch(RuntimeException $failure){
	echo json_encode(['rejected'=>$failure->getMessage()==='Root PHP startup files are not disabled.'],JSON_THROW_ON_ERROR);
	exit(0);
}
exit(1);
