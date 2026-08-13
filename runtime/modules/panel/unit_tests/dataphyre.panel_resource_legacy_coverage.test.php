<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);
if(!class_exists('dataphyre\panel',false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class panel { public static function config(string $key,mixed $default=null): mixed { return $key==="default_icon" ? "coverage-icon" : $default; } }');
}

test('panel resource legacy facade supplies the configured default icon',static function(Context $t): void {
	$t->same('coverage-icon',$t->nonPublic(Resource::class)->invoke('defaultIcon'));
})->tag('panel','resource','coverage')->group('framework-coverage');
