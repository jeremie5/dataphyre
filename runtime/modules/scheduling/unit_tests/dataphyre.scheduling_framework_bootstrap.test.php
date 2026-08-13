<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Scheduling\Period;
use Dataphyre\Scheduling\ScheduledTask;
use Dataphyre\Scheduling\Scheduling;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

test('scheduling framework bootstrap loads its kernel and public types idempotently',static function(Context $t): void {
	$t->phpBootstrap(dirname(__DIR__).'/Framework/Bootstrap.php')
		->providesTypes(\dataphyre\scheduling::class,Period::class,ScheduledTask::class,Scheduling::class)
		->reloadsSafely();
})->tag('scheduling','framework-bootstrap','lifecycle')->group('framework-coverage');
