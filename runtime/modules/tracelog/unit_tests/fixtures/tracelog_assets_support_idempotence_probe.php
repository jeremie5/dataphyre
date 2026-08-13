<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

$support=(string)($argv[1] ?? '');
if(!is_file($support)){
	fwrite(STDERR, 'Tracelog asset support path is required.');
	exit(2);
}
require $support;
$second=require $support;
echo json_encode([
	'second_include'=>$second,
	'asset_name'=>dataphyre_tracelog_asset_name('viewer.css'),
], JSON_THROW_ON_ERROR);
