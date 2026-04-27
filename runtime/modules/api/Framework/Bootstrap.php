<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Api;

$kernel_entry=dirname(__DIR__).'/kernel/api.main.php';
if(is_file($kernel_entry)){
	require_once($kernel_entry);
}

if(class_exists('\dataphyre\core', false)){
	\dataphyre\core::load_framework_modules(['routing', 'http']);
}
