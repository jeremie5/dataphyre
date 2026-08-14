<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$kernel=realpath((string)($argv[1] ?? ''));
$project=realpath((string)($argv[2] ?? ''));
$state=realpath((string)($argv[3] ?? ''));
$runtime=realpath((string)($argv[4] ?? ''));
if(!is_string($kernel) || !is_string($project) || !is_string($state) || !is_string($runtime)) exit(64);
putenv('DATAPHYRE_PREFLIGHT_PROJECT_ROOT='.$project);
putenv('DATAPHYRE_PREFLIGHT_APPLICATION=_Runtime$Probe');
putenv('DATAPHYRE_PREFLIGHT_STATE_ROOT='.$state);
putenv('DATAPHYRE_RUNTIME_PROJECT_ROOT='.$project);
putenv('DATAPHYRE_RUNTIME_POOL=health-preflight');
putenv('DATAPHYRE_RUNTIME_POOL_ROLE=health-preflight');
putenv('DATAPHYRE_SCHEDULER_ACTIVATION_MODE=record_only');
putenv('DATAPHYRE_SCHEDULER_STATE_ROOT='.$state);
putenv('DATAPHYRE_RUNTIME_TEST_FRAMEWORK_ROOT='.$runtime);
putenv('DATAPHYRE_RUNTIME_TEST_STATE_ROOT='.$state);
$_SERVER=[ // dataphyre-test-architecture: exempt[raw-superglobal] reason="Exact process fixture enters the framework-owned preflight HTTP router with canonical server metadata."
	'REQUEST_URI'=>'/health',
	'REQUEST_METHOD'=>'GET',
	'SCRIPT_FILENAME'=>$kernel.'/application_release_preflight_router.php',
	'SERVER_PROTOCOL'=>'HTTP/1.1',
	'SERVER_ADDR'=>'127.0.0.1','SERVER_NAME'=>'127.0.0.1','SERVER_PORT'=>'8080',
	'HTTP_HOST'=>'127.0.0.1','REMOTE_ADDR'=>'127.0.0.1',
];
require $kernel.'/application_release_preflight_router.php';
