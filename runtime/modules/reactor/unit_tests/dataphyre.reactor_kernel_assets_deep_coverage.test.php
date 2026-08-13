<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	if(!function_exists(__NAMESPACE__.'\\tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}

	final class core {
		/** @var array<int,array<int,string>> */
		public static array $frameworkLoads=[];

		public static function load_framework_modules(array $modules): void {
			self::$frameworkLoads[]=$modules;
		}
	}

	final class routing {
		/** @var array<string,mixed> */
		public static array $bindings=[];
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\suite;
	use function Dataphyre\Test\test;

	suite('Reactor asset entrypoint HTTP contracts')
		->contract('reactor.asset-entrypoint', 1)
		->layer('integration')
		->risk('high')
		->watches('module:reactor')
		->through('asset-route', 'bootstrap', 'conditional-request', 'head-response', 'browser-runtime')
		->isolation('case')
		->tag('reactor', 'assets')
		->group('framework-coverage');

	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
			if(!defined($constant)){
				define($constant, $defaults);
			}
		}
	}

	function dp_reactor_asset_entrypoint(
		Context $t,
		string $asset,
		string $method='GET',
		?string $ifModifiedSince=null,
		?string $bootstrapOverride=null
	): array {
		if($bootstrapOverride!==null && !defined('DATAPHYRE_REACTOR_BOOTSTRAP_FILE')){
			define('DATAPHYRE_REACTOR_BOOTSTRAP_FILE', $bootstrapOverride);
		}
		\dataphyre\routing::$bindings=['asset'=>$asset];
		$t->globalMap('_GET')->clear();
		$server=$t->globalMap('_SERVER')
			->merge([
				'REQUEST_METHOD'=>$method,
				'REQUEST_URI'=>'/dataphyre/reactor/assets/'.$asset,
			])
			->forget('HTTP_IF_NONE_MATCH')
			->forget('HTTP_IF_MODIFIED_SINCE');
		if($ifModifiedSince!==null){
			$server->put('HTTP_IF_MODIFIED_SINCE', $ifModifiedSince);
		}
		http_response_code(200);
		$body=$t->captureOutput(static function(): void {
			require rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/reactor/kernel/assets.php';
		})->output();
		return ['status'=>http_response_code(), 'body'=>$body];
	}

	test('reactor asset entrypoint returns a controlled not-found response when bootstrap is unavailable', static function(Context $t): void {
		$result=dp_reactor_asset_entrypoint($t, 'reactor.js', 'GET', null, __DIR__.'/fixtures/missing-reactor-bootstrap.php');
		$t->same(404, $result['status']);
		$t->same('Not found', $result['body']);
	})->tag('reactor', 'coverage')->group('framework-coverage');

	test('reactor asset entrypoint returns not found for an unknown asset', static function(Context $t): void {
		$result=dp_reactor_asset_entrypoint($t, 'missing.js');
		$t->same(404, $result['status']);
		$t->same('Not found', $result['body']);
		$t->contains(['async','templating'], \dataphyre\core::$frameworkLoads);
	})->tag('reactor', 'coverage')->group('framework-coverage');

	test('reactor asset entrypoint emits the embedded browser runtime for GET', static function(Context $t): void {
		$result=dp_reactor_asset_entrypoint($t, 'reactor.js');
		$t->same(200, $result['status']);
		$t->contains('window.DataphyreReactor', $result['body']);
		$t->contains('dataphyre:reactor-before-request', $result['body']);
	})->tag('reactor', 'coverage')->group('framework-coverage');

	test('reactor asset entrypoint suppresses the response body for HEAD', static function(Context $t): void {
		$result=dp_reactor_asset_entrypoint($t, 'reactor.js', 'HEAD');
		$t->same(200, $result['status']);
		$t->same('', $result['body']);
	})->tag('reactor', 'coverage')->group('framework-coverage');

	test('reactor asset entrypoint returns not modified for a fresh conditional request', static function(Context $t): void {
		$result=dp_reactor_asset_entrypoint($t, 'reactor.js', 'GET', 'Tue, 01 Jan 2100 00:00:00 GMT');
		$t->same(304, $result['status']);
		$t->same('', $result['body']);
	})->tag('reactor', 'coverage')->group('framework-coverage');
}
