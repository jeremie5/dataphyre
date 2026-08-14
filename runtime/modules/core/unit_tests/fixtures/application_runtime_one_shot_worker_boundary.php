<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/application_runtime_child_environment.php';
$operation=$argv[1] ?? null;
if($operation==='database_identity') usleep(750000);
try{DataphyreApplicationRuntimeChildEnvironment::consumeInherited('one-shot');}
catch(Throwable){exit(78);}
exit($operation==='artisan_migrate' ? 42 : 0);
