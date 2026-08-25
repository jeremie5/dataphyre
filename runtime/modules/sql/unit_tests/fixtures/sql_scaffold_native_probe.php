<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

$script=(string)($argv[1] ?? '');
$project=(string)($argv[2] ?? '');
if(!is_file($script) || !is_dir($project)){
	fwrite(STDERR, 'SQL native scaffold probe requires script and project paths.');
	exit(2);
}
define('DATAPHYRE_SQL_SCAFFOLD_NO_DISPATCH', true);
putenv('DATAPHYRE_PROJECT_ROOT='.$project);
require $script;
$application=$project.'/applications/example_app/framework';
if(!is_dir($application) && !mkdir($application, 0777, true) && !is_dir($application)){
	throw new RuntimeException('Unable to create native scaffold application fixture.');
}
$output=[];
$errors=[];
$runtime=[
	'write_out'=>static function(string $message) use (&$output): int { $output[]=$message; return strlen($message); },
	'write_error'=>static function(string $message) use (&$errors): int { $errors[]=$message; return strlen($message); },
];
$missing=dataphyre_sql_scaffold_command::run(['scaffold.php'], $runtime);
$success=dataphyre_sql_scaffold_command::run([
	'scaffold.php', 'example_app', 'Widget', 'widgets', 'widget_id', 'widget_id,name',
], $runtime);
echo json_encode([
	'missing_status'=>$missing,
	'success_status'=>$success,
	'entity_exists'=>is_file($project.'/applications/example_app/framework/Record/WidgetRecord.php'),
	'table_exists'=>is_file($project.'/applications/example_app/framework/Schema/WidgetTableSchema.php'),
	'output'=>$output,
	'errors'=>$errors,
], JSON_THROW_ON_ERROR);
