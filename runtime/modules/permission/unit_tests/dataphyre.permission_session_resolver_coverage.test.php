<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Permission\SubjectResolver;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

if(!defined('DP_PERMISSION_CFG')){
	define('DP_PERMISSION_CFG',['roles'=>[],'default_roles'=>[],'subject'=>[]]);
}
framework(['permission']);

test('permission subject resolver falls back to the legacy session user id',static function(Context $t): void {
	$session=$t->globalMap('_SESSION');
	$session->put('userid','session-91');
	$t->same('session-91',SubjectResolver::id(null));
	$session->forget('userid');
})->tag('permission','coverage')->group('framework-coverage');
