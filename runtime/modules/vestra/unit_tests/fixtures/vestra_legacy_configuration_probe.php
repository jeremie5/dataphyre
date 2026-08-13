<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace {
	define('CFG', [
		'vestra_url'=>'https://legacy-node.test',
	]);

	function config(string $key): mixed {
		return $key==='vestra_rate' ? 'legacy-rate' : null;
	}

	define('DATAPHYRE_VESTRA_NO_DISPATCH', true);
	require_once dirname(__DIR__, 2).'/kernel/vestra.main.php';

	\dataphyre\vestra::resetRuntime([
		'config'=>array_replace(\dataphyre\vestra::defaults(), [
			'base_url'=>'',
			'object_url'=>'',
			'rate'=>'',
		]),
		'env'=>static fn(string $name): false=>false,
		'trace'=>static fn(mixed ...$arguments): null=>null,
	]);

	echo json_encode([
		'configured'=>\dataphyre\vestra::configured(),
		'object_url'=>\dataphyre\vestra::object_url(
			['object_id'=>17,'tenant'=>'legacy-tenant'],
			['token'=>'legacy-token'],
		),
	], JSON_THROW_ON_ERROR);
}
