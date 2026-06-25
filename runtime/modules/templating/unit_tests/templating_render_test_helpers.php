<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace {
	if(!function_exists('tracelog')){
		function tracelog(...$args): void {}
	}
	if(!function_exists('dp_module_required')){
		function dp_module_required(string $module, string $dependency): void {}
	}
	if(!defined('ROOTPATH')){
		define('ROOTPATH', [
			'dataphyre'=>sys_get_temp_dir().'/dataphyre-templating-unit/',
		]);
	}
}

namespace dataphyre {
	if(!function_exists(__NAMESPACE__.'\\tracelog')){
		function tracelog(...$args): void {}
	}
}

namespace DataphyreUnitTests {

	require_once __DIR__.'/../kernel/templating.main.php';

	function templating_legacy_extend_render_json(): string {
		$root=sys_get_temp_dir().'/dataphyre_templating_extend_'.bin2hex(random_bytes(4));
		$cache=$root.'/cache/';
		$templates=$root.'/templates/';
		mkdir($cache, 0777, true);
		mkdir($templates, 0777, true);
		$base=$templates.'base.tpl';
		$child=$templates.'child.tpl';
		file_put_contents($base, '<main>{{ block_content "body" }}</main>');
		file_put_contents($child, '{{ extend "base.tpl" }}{{ block "body" }}Hello {{name}}{{ endblock }}');
		try{
			\dataphyre\templating::init(true, $cache, false);
			$html=(string)\dataphyre\templating::render($child, ['name'=>'Ada']);
		}
		finally{
			@unlink($child);
			@unlink($base);
			@rmdir($templates);
			@rmdir($cache);
			@rmdir($root);
		}
		return json_encode([
			'html'=>$html,
			'has_wrapper'=>str_starts_with($html, '<div class='),
		], JSON_UNESCAPED_SLASHES);
	}
}
