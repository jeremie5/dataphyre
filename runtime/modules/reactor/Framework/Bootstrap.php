<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

$kernelEntry=dirname(__DIR__).'/kernel/reactor.main.php';
if(is_file($kernelEntry)){
	require_once($kernelEntry);
}

if(class_exists('\dataphyre\core', false)){
	\dataphyre\core::load_framework_modules(['templating']);
}
