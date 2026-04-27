<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

$project_root=dirname(__DIR__, 4);

require_once($project_root.'/common/dataphyre/modules/core/kernel/bootstrap.php');
require_once($project_root.'/common/dataphyre/modules/core/kernel/core_functions.php');
\dataphyre\core::load_framework_modules(['routing', 'api', 'sql', 'fulltext_engine']);

$application_name=$argv[1] ?? ($_SERVER['HTTP_X_DATAPHYRE_APPLICATION'] ?? null);

if(empty($application_name)){
	fwrite(STDERR, "Usage: php common/dataphyre/modules/routing/kernel/compile_app_routes.php <application>\n");
	exit(1);
}

$target=\Dataphyre\Routing\Tools\CompileApplicationRoutes::compile($project_root, $application_name);

fwrite(STDOUT, "Compiled routes written to {$target}\n");
