<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	final class routing {
		public static array $bindings=['asset'=>'viewer.css'];
	}
}

namespace {
	define('DATAPHYRE_TRACELOG_ASSET_NO_DISPATCH', true);
	require_once dirname(__DIR__, 2).'/kernel/assets.php';

	$emitted=null;
	$response=\dataphyre_tracelog_asset_endpoint::bootstrap(true, [
		'query'=>[],
		'server'=>['REQUEST_METHOD'=>'GET'],
		'emit'=>static function(array $response) use (&$emitted): void {
			$emitted=$response;
		},
	]);
	echo json_encode([
		'status'=>$response['status'] ?? null,
		'emitted_status'=>$emitted['status'] ?? null,
		'content_type'=>$response['headers']['Content-Type'] ?? null,
	], JSON_THROW_ON_ERROR);
}
