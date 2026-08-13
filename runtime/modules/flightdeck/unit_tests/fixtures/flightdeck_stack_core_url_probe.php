<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	final class core {
		public static function url_self(): string { return 'https://console.example.test/app/'; }
	}
}

namespace {
	$root=rtrim((string)($argv[1] ?? ''),'/\\');
	$stackFile=(string)($argv[2] ?? '');
	if($root==='' || !is_file($stackFile)){
		throw new InvalidArgumentException('Dataphyre root and stack helper are required.');
	}
	require_once $root.'/runtime/modules/testing/tooling/bootstrap.php';
	require $stackFile;
	$context=new \Dataphyre\Test\Context('Flightdeck stack core URL probe');
	$url=$context->nonPublic(dataphyre_flightdeck_stack_snippets::class)->invoke('datadoc_base_url');
	echo json_encode(['url'=>$url],JSON_THROW_ON_ERROR)."\n";
}
