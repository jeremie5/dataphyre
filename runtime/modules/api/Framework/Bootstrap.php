<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Api;

$kernelEntry=dirname(__DIR__).'/kernel/api.main.php';
if(is_file($kernelEntry)){
	require_once($kernelEntry);
}

if(class_exists('\dataphyre\core', false)){
	\dataphyre\core::load_framework_modules(['routing', 'http']);
}
