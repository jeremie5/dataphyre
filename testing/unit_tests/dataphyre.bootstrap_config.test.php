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

require_once dirname(__DIR__, 2).'/runtime/bootstrap_config.php';

test('bootstrap config keeps standalone installs rooted at the install directory', static function(Context $t): void {
	$install_root=sys_get_temp_dir().'/dataphyre-standalone-'.bin2hex(random_bytes(4)).'/dataphyre';
	dataphyre_bootstrap_config_make_install($install_root);
	try{
		$result=\dataphyre\bootstrap_config::resolve($install_root.'/runtime');
		$t->same(str_replace('\\', '/', $install_root).'/', str_replace('\\', '/', $result['project_root']));
		$t->same(str_replace('\\', '/', $install_root).'/applications', str_replace('\\', '/', $result['application_roots'][0] ?? ''));
	}
	finally{
		dataphyre_bootstrap_config_remove(dirname($install_root));
	}
})->tag('bootstrap', 'package');

test('bootstrap config keeps embedded common/dataphyre installs rooted at the project directory', static function(Context $t): void {
	$project_root=sys_get_temp_dir().'/dataphyre-embedded-'.bin2hex(random_bytes(4)).'/project';
	$install_root=$project_root.'/common/dataphyre';
	dataphyre_bootstrap_config_make_install($install_root);
	try{
		$result=\dataphyre\bootstrap_config::resolve($install_root.'/runtime');
		$t->same(str_replace('\\', '/', $project_root).'/', str_replace('\\', '/', $result['project_root']));
		$t->same(str_replace('\\', '/', $project_root).'/applications', str_replace('\\', '/', $result['application_roots'][0] ?? ''));
	}
	finally{
		dataphyre_bootstrap_config_remove(dirname($project_root));
	}
})->tag('bootstrap', 'embedded');

test('bootstrap config supports vendor installs with explicit project root', static function(Context $t): void {
	$consumer_root=sys_get_temp_dir().'/dataphyre-vendor-'.bin2hex(random_bytes(4)).'/consumer';
	$install_root=$consumer_root.'/vendor/dataphyre/dataphyre';
	mkdir($install_root.'/runtime', 0775, true);
	file_put_contents($consumer_root.'/flight_sheet.php', "<?php return ['bootstrap'=>['application_roots'=>['applications']]];\n");
	$had_previous=array_key_exists('DATAPHYRE_PROJECT_ROOT', $_SERVER);
	$previous=$_SERVER['DATAPHYRE_PROJECT_ROOT'] ?? null;
	$_SERVER['DATAPHYRE_PROJECT_ROOT']=$consumer_root;
	try{
		$result=\dataphyre\bootstrap_config::resolve($install_root.'/runtime');
		$t->same(str_replace('\\', '/', $consumer_root).'/', str_replace('\\', '/', $result['project_root']));
		$t->same(str_replace('\\', '/', $consumer_root).'/applications', str_replace('\\', '/', $result['application_roots'][0] ?? ''));
	}
	finally{
		if($had_previous){
			$_SERVER['DATAPHYRE_PROJECT_ROOT']=$previous;
		}
		else
		{
			unset($_SERVER['DATAPHYRE_PROJECT_ROOT']);
		}
		dataphyre_bootstrap_config_remove(dirname($consumer_root));
	}
})->tag('bootstrap', 'package', 'vendor');

function dataphyre_bootstrap_config_make_install(string $install_root): void {
	mkdir($install_root.'/runtime', 0775, true);
	file_put_contents($install_root.'/flight_sheet.php', "<?php return ['bootstrap'=>['application_roots'=>['applications']]];\n");
}

function dataphyre_bootstrap_config_remove(string $path): void {
	if(!is_dir($path)){
		return;
	}
	$iterator=new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach($iterator as $item){
		$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
	}
	@rmdir($path);
}
