<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

$dp_helper_key_array_kernel=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/core/kernel';
require_once $dp_helper_key_array_kernel.'/helper_functions.php';

test('helper functions define core config lazily and select the newest rotated key', static function(Context $t): void {
	$workspace=$t->workspace('helper-key-array');
	$app=$workspace->directory('app');
	$t->global('DATAPHYRE_HELPER_ROOTPATH_OVERRIDE')->replace([
		'common_dataphyre'=>'',
		'dataphyre'=>$app.'/',
	]);
	$workspace->file('app/config/core.php',"<?php return ['private_key'=>['old-key', 'new-key']];\n");
	$t->same(['old-key', 'new-key'], dpvks());
	$t->same('new-key', dpvk());
	$t->same('new-key', dpvk());
})->tag('core', 'helper-functions', 'private-key', 'rotation', 'coverage')->group('framework-coverage');
