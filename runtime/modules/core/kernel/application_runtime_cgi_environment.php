<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/application_runtime_child_environment.php';
$role=match((string)($_SERVER['SERVER_PORT'] ?? '')){'8081'=>'scheduler','8083'=>'web',default=>''};
if($role==='') throw new RuntimeException('Application CGI role is invalid.');
$GLOBALS['DATAPHYRE_INTERNAL_CGI_ENVIRONMENT']=DataphyreApplicationRuntimeChildEnvironment::consumeCgi($role);
