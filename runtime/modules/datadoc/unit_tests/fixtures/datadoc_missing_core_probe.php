<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	/** Keeps the standalone bootstrap probe from discovering a real core kernel. */
	function is_dir(string $path): bool { return false; }
}

namespace {
	function tracelog(mixed ...$arguments): void {}
	function dp_module_required(string $module,string $dependency): void {}
	function dp_define_module_config(string $module,string $constant): void {
		if(!defined($constant)){ define($constant,[]); }
	}

	$main=(string)($argv[1] ?? '');
	$root=rtrim((string)($argv[2] ?? ''),'/\\').DIRECTORY_SEPARATOR;
	if($main==='' || $root===DIRECTORY_SEPARATOR){
		throw new InvalidArgumentException('DataDoc main entrypoint and isolated root arguments are required.');
	}
	define('ROOTPATH',[
		'common_dataphyre_runtime'=>$root,
		'common_dataphyre'=>$root,
		'dataphyre'=>$root,
	]);
	try{
		require $main;
		echo json_encode(['threw'=>false,'message'=>''],JSON_THROW_ON_ERROR);
	}catch(RuntimeException $failure){
		echo json_encode(['threw'=>true,'message'=>$failure->getMessage()],JSON_THROW_ON_ERROR);
	}
}
