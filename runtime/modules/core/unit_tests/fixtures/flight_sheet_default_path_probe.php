<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$source=realpath((string)($argv[1] ?? ''));
if(!is_string($source)) exit(64);
require_once $source;
require_once dirname($source,4).'/modules/testing/tooling/bootstrap.php';
$path=(new \Dataphyre\Test\Context('flight-sheet default path'))
	->nonPublic(\dataphyre\flight_sheet::class)
	->invoke('path', ['rootpaths'=>[]]);
echo json_encode(['path'=>$path], JSON_THROW_ON_ERROR);
