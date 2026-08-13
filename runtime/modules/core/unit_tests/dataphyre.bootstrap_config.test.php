<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once \Dataphyre\Test\dataphyre_path().'/runtime/bootstrap_config.php';

test('bootstrap config keeps standalone installs rooted at the install directory', static function(Context $t): void {
	$workspace=$t->workspace('bootstrap-standalone');
	$install_root=$workspace->directory('dataphyre');
	$workspace->directory('dataphyre/runtime');
	$workspace->file('dataphyre/flight_sheet.php',"<?php return ['bootstrap'=>['application_roots'=>['applications']]];\n");
	$result=\dataphyre\bootstrap_config::resolve($install_root.'/runtime');
	$t->same(str_replace('\\', '/', $install_root).'/', str_replace('\\', '/', $result['project_root']));
	$t->same(str_replace('\\', '/', $install_root).'/applications', str_replace('\\', '/', $result['application_roots'][0] ?? ''));
})->tag('bootstrap', 'package');

test('bootstrap config keeps canonical embedded dataphyre installs rooted at the project directory', static function(Context $t): void {
	$workspace=$t->workspace('bootstrap-embedded');
	$project_root=$workspace->directory('project');
	$install_root=$project_root.'/dataphyre';
	$workspace->directory('project/dataphyre/runtime');
	$workspace->file('project/flight_sheet.php',"<?php return ['bootstrap'=>['application_roots'=>['applications'],'modules'=>['enabled'=>[' SQL ','routing','blocked'],'disabled'=>['blocked','ACCESS']]]];\n");
	$result=\dataphyre\bootstrap_config::resolve($install_root.'/runtime');
	$t->same(str_replace('\\', '/', $project_root).'/', str_replace('\\', '/', $result['project_root']));
	$t->same(str_replace('\\', '/', $project_root).'/applications', str_replace('\\', '/', $result['application_roots'][0] ?? ''));
	$t->same(['core'=>true, 'sql'=>true, 'routing'=>true], $result['modules']['enabled']);
	$t->same(['blocked'=>true, 'access'=>true], $result['modules']['disabled']);
})->tag('bootstrap', 'embedded');

test('bootstrap config retains legacy common dataphyre root resolution without making it canonical', static function(Context $t): void {
	$workspace=$t->workspace('bootstrap-legacy');
	$project_root=$workspace->directory('project');
	$install_root=$project_root.'/common/dataphyre';
	$workspace->directory('project/common/dataphyre/runtime');
	$workspace->file('project/flight_sheet.php',"<?php return ['bootstrap'=>['application_roots'=>['applications']]];\n");
	$result=\dataphyre\bootstrap_config::resolve($install_root.'/runtime');
	$t->same(str_replace('\\', '/', $project_root).'/', str_replace('\\', '/', $result['project_root']));
})->tag('bootstrap', 'legacy');

test('bootstrap config supports vendor installs with explicit project root', static function(Context $t): void {
	$workspace=$t->workspace('bootstrap-vendor');
	$consumer_root=$workspace->directory('consumer');
	$install_root=$consumer_root.'/vendor/dataphyre/dataphyre';
	$workspace->directory('consumer/vendor/dataphyre/dataphyre/runtime');
	$workspace->file('consumer/flight_sheet.php',"<?php return ['bootstrap'=>['application_roots'=>['applications']]];\n");
	$t->globalMap('_SERVER')->put('DATAPHYRE_PROJECT_ROOT',$consumer_root);
	$result=\dataphyre\bootstrap_config::resolve($install_root.'/runtime');
	$t->same(str_replace('\\', '/', $consumer_root).'/', str_replace('\\', '/', $result['project_root']));
	$t->same(str_replace('\\', '/', $consumer_root).'/applications', str_replace('\\', '/', $result['application_roots'][0] ?? ''));
})->tag('bootstrap', 'package', 'vendor');

test('bootstrap config rejects relative overrides and ignores malformed legacy and list entries', static function(Context $t): void {
	$workspace=$t->workspace('bootstrap-edge');
	$install_root=$workspace->directory('dataphyre');
	$workspace->directory('dataphyre/runtime');
	$workspace->file('dataphyre/runtime/config.php','<?php return "invalid";');
	$workspace->file(
		'dataphyre/flight_sheet.php',
		"<?php return ['bootstrap'=>['application_roots'=>['', ' applications ', '/shared/apps'],'modules'=>['enabled'=>['', 'Bad Name!', 'SQL']]]];\n"
	);
	$server=$t->globalMap('_SERVER')->put('DATAPHYRE_PROJECT_ROOT','relative/project');
	$t->throws(static fn()=>\dataphyre\bootstrap_config::resolve($install_root.'/runtime'), RuntimeException::class);
	$server->forget('DATAPHYRE_PROJECT_ROOT');
	$result=\dataphyre\bootstrap_config::resolve($install_root.'/runtime');
	$t->same(['core'=>true, 'sql'=>true], $result['modules']['enabled']);
	$t->same(2, count($result['application_roots']));
	$t->same('/shared/apps', $result['application_roots'][1] ?? null);
})->tag('bootstrap', 'edge', 'coverage');
