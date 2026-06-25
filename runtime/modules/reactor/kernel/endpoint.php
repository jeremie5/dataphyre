<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
if(class_exists('\dataphyre\core', false)){
	\dataphyre\core::load_framework_module('reactor');
}
else{
	$bootstrap=dirname(__DIR__).'/Framework/Bootstrap.php';
	if(is_file($bootstrap)){
		require_once($bootstrap);
	}
}

$is_batch=strcasecmp((string)($_SERVER['HTTP_X_DATAPHYRE_REACTOR_BATCH'] ?? ''), '1')===0
	|| strcasecmp((string)($_SERVER['HTTP_X_DATAPHYRE_REACTOR_BATCH'] ?? ''), 'true')===0;
if($is_batch){
	\Dataphyre\Reactor\ReactorEndpoint::emitBatch();
	return;
}
\Dataphyre\Reactor\ReactorEndpoint::emit();
