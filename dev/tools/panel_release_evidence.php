<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

$projectRoot=dirname(__DIR__,2);$modules=$projectRoot.'/runtime/modules';
require_once $modules.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($modules);
\dataphyre\autoloader::register_prefixes(['Dataphyre\\Panel\\'=>$modules.'/panel/Framework','Dataphyre\\'=>$modules.'/core/Framework']);

/** Injectable entry point for exact CLI tests. */
function dp_panel_release_evidence_cli_main(array $arguments,?string $cwd=null,mixed $stdout=null,mixed $stderr=null):int {
	$result=\Dataphyre\Panel\PanelReleaseEvidenceCli::execute($arguments,$cwd);$code=(int)($result['exit_code']??2);$payload=is_array($result['payload']??null)?$result['payload']:['ok'=>false,'message'=>'Release evidence CLI returned no payload.'];
	$json=json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";$stream=$code===2?($stderr??STDERR):($stdout??STDOUT);
	if(!is_resource($stream)||fwrite($stream,$json)===false){return 2;}return $code;
}

if(!defined('DATAPHYRE_PANEL_RELEASE_EVIDENCE_CLI_TEST')){exit(dp_panel_release_evidence_cli_main($argv,getcwd()));}
