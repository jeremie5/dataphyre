<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelInstance;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',[
		'enabled'=>['core'=>true,'panel'=>true],
		'disabled'=>['access'=>true,'permission'=>true],
		'core_implicit'=>true,
	]);
}
$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $modulesRoot.'/core/kernel/autoloader.php';
require_once $modulesRoot.'/core/kernel/core_functions.php';
if(!function_exists('dataphyre\\tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; function tracelog(mixed ...$arguments): void {}');
}
\dataphyre\autoloader::register($modulesRoot);
\dataphyre\autoloader::register_framework_modules(['panel']);

test('panel instance optional modules integrate when available or report actionable errors',static function(Context $t): void {
	$checks=[
		'access-auth'=>static fn(PanelInstance $panel): PanelInstance=>$panel->accessAuth(),
		'access-permissions'=>static fn(PanelInstance $panel): PanelInstance=>$panel->accessPermissions(),
		'permission-admin'=>static fn(PanelInstance $panel): PanelInstance=>$panel->permissionAdmin(),
	];
	foreach($checks as $name=>$check){
		$panel=PanelInstance::make('optional-'.$name);
		try{
			$t->same($panel,$check($panel),'available optional module returns the panel instance');
		}catch(RuntimeException $exception){
			$t->contains('framework is required',$exception->getMessage());
		}
	}
})->tag('panel','instance','coverage')->group('framework-coverage');
