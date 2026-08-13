<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Access\AccessTokenBroker;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

$dpAccessTokenAbsenceModules=\Dataphyre\Test\dataphyre_path().'/runtime/modules';
require_once $dpAccessTokenAbsenceModules.'/access/Framework/AccessTokenBroker.php';

test('access token broker absence coverage rejects storage operations when SQL helpers are unavailable',static function(Context $t): void {
	$t->same(null,AccessTokenBroker::instance()->create('password-reset',1,'person@example.test'));
	$t->same(null,AccessTokenBroker::instance()->find('password-reset','token'));
	$t->same(null,AccessTokenBroker::instance()->consume('password-reset','token'));
})->tag('access','access-residual-exact','deep-coverage')->group('framework-coverage');
