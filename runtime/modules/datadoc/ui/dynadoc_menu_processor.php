<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
if(\dataphyre\datadoc::logged_in()!==true){
	exit();
}

$project_name=(string)(\dataphyre\routing::$bindings['project'] ?? ($_GET['project'] ?? ''));
$project=\dataphyre\datadoc::get_project($project_name);
if($project===null){
	http_response_code(404);
	exit('Invalid project.');
}

$kind=strtolower(trim((string)($_GET['kind'] ?? 'dynamic')));
$path=json_decode((string)($_GET['path'] ?? '[]'), true);
if(!is_array($path)){
	$path=[];
}

$branch=\dataphyre\datadoc::get_menu_branch($project['name'], $kind, $path);
\dataphyre\datadoc::render_procedural_menu_nodes($project['name'], $kind, $branch, $path, count($path)+1);
