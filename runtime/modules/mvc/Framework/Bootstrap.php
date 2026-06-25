<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

$kernelEntry=dirname(__DIR__).'/kernel/mvc.main.php';
if(is_file($kernelEntry)){
	require_once($kernelEntry);
}

if(class_exists('\dataphyre\core', false)){
	\dataphyre\core::load_framework_modules(['http', 'routing', 'templating', 'sql']);
}
