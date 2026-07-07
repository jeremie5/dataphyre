<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
$dataphyre_runtime=__DIR__.'/vendor/dataphyre/dataphyre/runtime/bootstrap.php';
if(is_file($dataphyre_runtime)){
	$_SERVER['DATAPHYRE_PROJECT_ROOT']=__DIR__;
	require $dataphyre_runtime;
	return;
}
require __DIR__.'/runtime/bootstrap.php';
