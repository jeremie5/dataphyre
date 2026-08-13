<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$projectRoot=dirname(__DIR__,2);$modules=$projectRoot.'/runtime/modules';
require_once $modules.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($modules);
\dataphyre\autoloader::register_prefixes(['Dataphyre\\Panel\\'=>$modules.'/panel/Framework','Dataphyre\\'=>$modules.'/core/Framework']);

$result=\Dataphyre\Panel\PanelFilamentMigrationCli::execute($argv,getcwd()?:null);
$stream=$result['exit_code']===0?STDOUT:STDERR;
fwrite($stream,json_encode($result['payload'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
exit($result['exit_code']);
