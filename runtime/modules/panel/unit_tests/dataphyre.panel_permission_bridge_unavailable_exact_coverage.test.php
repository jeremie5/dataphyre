<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelPermissionBridge;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DP_PANEL_CFG')){
	define('DP_PANEL_CFG',['permission'=>true]);
}
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',['enabled'=>['core'=>true,'panel'=>true],'disabled'=>['permission'=>true],'core_implicit'=>true]);
}
$dpPanelPermissionUnavailableModules=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $dpPanelPermissionUnavailableModules.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dpPanelPermissionUnavailableModules);
\dataphyre\autoloader::register_framework_modules(['panel']);
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

test('panel permission bridge remains permissive when configured backend is unavailable',static function(Context $t): void {
	$t->isTrue(PanelPermissionBridge::configured());
	$t->isFalse(PanelPermissionBridge::available());
	$t->isTrue(PanelPermissionBridge::allows('panel.orders.view'));
})->tag('panel','panel-permission-unavailable-exact')->group('framework-coverage');
