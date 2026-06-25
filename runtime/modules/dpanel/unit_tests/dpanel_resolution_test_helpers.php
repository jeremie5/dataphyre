<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */if(!defined('ROOTPATH')){
	$root=dirname(__DIR__, 6);
	define('ROOTPATH', [
		'root'=>$root,
		'common_dataphyre_runtime'=>$root.'/common/dataphyre/runtime/',
		'common_dataphyre'=>$root.'/common/dataphyre/',
		'dataphyre'=>$root.'/common/dataphyre/',
		'common_root'=>$root.'/common/',
	]);
}
if(!function_exists('tracelog')){
	function tracelog(...$args): void {}
}
require_once __DIR__.'/../kernel/dpanel.main.php';

function dp_dpanel_unit_resolution_json(): string {
	$reflection=new ReflectionClass(\dataphyre\dpanel::class);
	$module_root=$reflection->getMethod('module_root_from_entrypoint');
	$module_root->setAccessible(true);
	$resolve=$reflection->getMethod('resolve_module_entrypoint');
	$resolve->setAccessible(true);
	$standard=ROOTPATH['common_dataphyre_runtime'].'modules/core/kernel/core.main.php';
	$legacy=ROOTPATH['common_dataphyre_runtime'].'modules/aceit_engine/aceit_engine.main.php';
	$resolved=$resolve->invoke(null, 'aceit_engine');
	$base=rtrim(str_replace('\\', '/', (string)realpath((string)(ROOTPATH['common_root'] ?? ROOTPATH['root']))), '/');
	$relative=function(string $path)use($base): string {
		$real=realpath($path);
		$normalized=str_replace('\\', '/', $real!==false ? $real : $path);
		return ltrim(substr($normalized, strlen($base)), '/');
	};
	return json_encode([
		'legacy_root'=>$relative($module_root->invoke(null, $legacy)),
		'resolved_path'=>is_array($resolved) ? $relative((string)$resolved[0]) : null,
		'standard_root'=>$relative($module_root->invoke(null, $standard)),
	], JSON_UNESCAPED_SLASHES);
}
