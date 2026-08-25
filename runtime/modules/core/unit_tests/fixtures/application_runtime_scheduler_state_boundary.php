<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli' || ($argc ?? 0)!==3) exit(64);
define('DATAPHYRE_INTERNAL_SCHEDULER_STATE_TEST_ROOT',$argv[2]);
require $argv[1].'/application_runtime_scheduler_state.php';
$identity=['deployment_application'=>'fixture-shop','framework_application'=>'FixtureApp','environment'=>'9-preview'];
$definition=[
	'name'=>'fixture.maximum','task_sha256'=>'sha256:'.hash('sha256','task'),
	'dependency_sha256'=>['sha256:'.hash('sha256','dependency')],
	'frequency_milliseconds'=>0,'timeout_milliseconds'=>300000,'memory_limit'=>'128M',
];
$release='dep_'.str_repeat('a',40);$firstGeneration='gen_'.str_repeat('b',32);
$secondGeneration='gen_'.str_repeat('c',32);$startedAt=1776073500;
$first=DataphyreApplicationRuntimeSchedulerState::claim(
	$identity,$definition,$release,$firstGeneration,str_repeat('d',64),$startedAt,
);
$atWorkerBoundary=DataphyreApplicationRuntimeSchedulerState::claim(
	$identity,$definition,$release,$secondGeneration,str_repeat('e',64),$startedAt+300,
);
$atTransportMargin=DataphyreApplicationRuntimeSchedulerState::claim(
	$identity,$definition,$release,$secondGeneration,str_repeat('e',64),$startedAt+450,
);
$afterExpiry=DataphyreApplicationRuntimeSchedulerState::claim(
	$identity,$definition,$release,$secondGeneration,str_repeat('e',64),$startedAt+452,
);
fwrite(STDOUT,json_encode(compact(
	'first','atWorkerBoundary','atTransportMargin','afterExpiry',
),JSON_THROW_ON_ERROR)."\n");
