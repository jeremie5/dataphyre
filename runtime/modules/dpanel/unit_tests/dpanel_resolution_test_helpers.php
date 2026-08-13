<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */if(!defined('ROOTPATH')){
	$package_root=dirname(__DIR__, 4);
	$root=dirname($package_root);
	define('ROOTPATH', [
		'root'=>$root,
		'common_dataphyre_runtime'=>$package_root.'/runtime/',
		'common_dataphyre'=>$package_root.'/',
		'dataphyre'=>$package_root.'/',
		'common_root'=>$root.'/',
	]);
}
if(!function_exists('tracelog')){
	function tracelog(...$args): void {}
}
require_once __DIR__.'/../kernel/dpanel.main.php';

function dp_dpanel_unit_resolution_json(): string {
	$standard=ROOTPATH['common_dataphyre_runtime'].'modules/core/kernel/core.main.php';
	$legacy=ROOTPATH['common_dataphyre_runtime'].'modules/aceit_engine/aceit_engine.main.php';
	$resolved=dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\dpanel::class,'resolve_module_entrypoint',['aceit_engine']);
	$package_root=realpath((string)(ROOTPATH['common_dataphyre'] ?? ''));
	$base=$package_root!==false
		? rtrim(str_replace('\\', '/', dirname($package_root)), '/')
		: rtrim(str_replace('\\', '/', (string)(ROOTPATH['common_root'] ?? ROOTPATH['root'])), '/');
	$relative=function(string $path)use($base): string {
		$real=realpath($path);
		$normalized=str_replace('\\', '/', $real!==false ? $real : $path);
		return ltrim(substr($normalized, strlen($base)), '/');
	};
	return json_encode([
		'legacy_root'=>$relative(dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\dpanel::class,'module_root_from_entrypoint',[$legacy])),
		'resolved_path'=>is_array($resolved) ? $relative((string)$resolved[0]) : null,
		'standard_root'=>$relative(dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\dpanel::class,'module_root_from_entrypoint',[$standard])),
	], JSON_UNESCAPED_SLASHES);
}
