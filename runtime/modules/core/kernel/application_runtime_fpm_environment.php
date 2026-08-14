<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/application_runtime_child_environment.php';
if(PHP_SAPI!=='fpm-fcgi' || (string)($_SERVER['SERVER_PORT'] ?? '')!=='8083'){
	throw new RuntimeException('Managed PHP web-pool request role is invalid.');
}
$GLOBALS['DATAPHYRE_INTERNAL_FPM_ENVIRONMENT']=
	DataphyreApplicationRuntimeChildEnvironment::activateManagedWebPoolRequest();
