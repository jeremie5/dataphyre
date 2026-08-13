<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace {
	require_once __DIR__.'/../../testing/tooling/bootstrap.php';

	if(!function_exists('tracelog')){
		function tracelog(...$args): void {}
	}
	if(!function_exists('dp_module_required')){
		function dp_module_required(string $module, string $dependency): void {}
	}
	if(!defined('ROOTPATH')){
		define('ROOTPATH', [
			'dataphyre'=>__DIR__.'/fixtures/json-app/',
		]);
	}
}

namespace dataphyre {
	if(!function_exists(__NAMESPACE__.'\\tracelog')){
		function tracelog(...$args): void {}
	}
}

namespace DataphyreUnitTests {
	use Dataphyre\Test\Context;
	use Dataphyre\Test\TempWorkspace;

	require_once __DIR__.'/../kernel/templating.main.php';

	/** Gives legacy JSON fixtures the same owned workspace lifecycle as code tests. */
	final class TemplatingRenderFixtureOwner {
		public static function run(callable $scenario): mixed {
			$context=new Context('templating legacy JSON fixture', file:__FILE__, suite:'templating');
			$workspace=$context->workspace('templating-json-render');
			try{
				return $scenario($workspace);
			}finally{
				$context->runDeferred();
			}
		}
	}

	function templating_legacy_extend_render_json(): string {
		return TemplatingRenderFixtureOwner::run(static function(TempWorkspace $workspace): string {
			$cache=rtrim($workspace->directory('cache'),'/\\').DIRECTORY_SEPARATOR;
			$base=$workspace->file('templates/base.tpl', '<main>{{ block_content "body" }}</main>');
			$child=$workspace->file('templates/child.tpl', '{{ extend "base.tpl" }}{{ block "body" }}Hello {{name}}{{ endblock }}');
			\dataphyre\templating::init(true, $cache, false);
			$html=(string)\dataphyre\templating::render($child, ['name'=>'Ada']);
			return json_encode([
				'html'=>$html,
				'has_wrapper'=>str_starts_with($html, '<div class='),
			], JSON_UNESCAPED_SLASHES);
		});
	}
}
