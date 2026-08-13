<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	/** Keeps the standalone bootstrap probe from loading helpers from the real repository. */
	function is_file(string $path): bool { return false; }
}

namespace {
	function tracelog(mixed ...$arguments): void {}

	$main=(string)($argv[1] ?? '');
	if($main===''){
		throw new InvalidArgumentException('DataDoc main entrypoint argument is required.');
	}
	try{
		require $main;
		echo json_encode(['threw'=>false,'message'=>''],JSON_THROW_ON_ERROR);
	}catch(RuntimeException $failure){
		echo json_encode(['threw'=>true,'message'=>$failure->getMessage()],JSON_THROW_ON_ERROR);
	}
}
