<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Access\Auth;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DP_ACCESS_CFG')){
	define('DP_ACCESS_CFG',[
		'default_auth_type'=>'cookie',
		'auth_types'=>'invalid',
	]);
}

require_once \Dataphyre\Test\dataphyre_path().'/runtime/modules/access/Framework/Auth.php';

test('access auth absence coverage fails closed before the kernel is loaded',static function(Context $t): void {
	$t->same('cookie',Auth::defaultType());
	$t->same('cookie',Auth::currentType());
	$t->same(['session'],Auth::enabledTypes());
	$t->isFalse(Auth::check());
	$t->isTrue(Auth::guest());
	$t->same(null,Auth::user());
	$t->same(null,Auth::id());
	$t->isFalse(Auth::loggedIn());
	$t->same(null,Auth::userId());
	$t->isTrue(Auth::access(false,false));
	$t->isFalse(Auth::access(true,false));
	$t->isFalse(Auth::access(false,true));
});
