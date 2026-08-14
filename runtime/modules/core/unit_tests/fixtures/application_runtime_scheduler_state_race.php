<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli' || ($argc ?? 0)!==5) exit(64);
define('DATAPHYRE_INTERNAL_SCHEDULER_STATE_TEST_ROOT',$argv[4]);
require $argv[1].'/application_runtime_scheduler_state.php';
$now=(int)$argv[3];
$identity=['cloud_application'=>'serve_shop','framework_application'=>'Serve','environment'=>'9-preview'];
$definition=[
	'name'=>'serve.race','task_sha256'=>'sha256:'.hash('sha256','task'),
	'dependency_sha256'=>['sha256:'.hash('sha256','dependency')],
	'frequency_milliseconds'=>1000,'timeout_milliseconds'=>2000,'memory_limit'=>'128M',
];
$release='dep_'.str_repeat('a',40);$generation='gen_'.str_repeat('b',32);
$nonce=hash('sha256',$argv[2]);
$claimed=DataphyreApplicationRuntimeSchedulerState::claim(
	$identity,$definition,$release,$generation,$nonce,$now,
);
if($claimed) usleep(200000);
fwrite(STDOUT,json_encode(compact('claimed','nonce'),JSON_THROW_ON_ERROR)."\n");
