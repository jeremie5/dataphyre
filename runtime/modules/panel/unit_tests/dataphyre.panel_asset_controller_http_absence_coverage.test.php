<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel {
	if(!class_exists(PanelRenderer::class,false)){
		final class PanelRenderer {
			/** @var array<string,array{content:string,content_type:string}> */
			public static array $assets=[
				'fallback.css'=>['content'=>'body{}','content_type'=>'text/css; charset=UTF-8'],
			];
			public static function assetContent(string $asset): ?array {
				return self::$assets[$asset] ?? null;
			}
		}
	}
	if(!class_exists(PanelTrace::class,false)){
		final class PanelTrace {
			public static function record(string $event,array $context=[]): void {}
		}
	}
}

namespace {
	use Dataphyre\Panel\PanelAssetController;
	use Dataphyre\Panel\PanelPageResult;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	$dpPanelAssetAbsenceModules=\Dataphyre\Test\dataphyre_path().'/runtime/modules';
	require_once $dpPanelAssetAbsenceModules.'/panel/Framework/Http/PanelPageResult.php';
	require_once $dpPanelAssetAbsenceModules.'/panel/Framework/Http/PanelAssetController.php';

	test('panel asset controller HTTP absence coverage returns PanelPageResult fallbacks for found and missing assets',static function(Context $t): void {
		$found=PanelAssetController::response('fallback.css');
		$t->instanceOf(PanelPageResult::class,$found);
		$t->same(200,$found->status());
		$t->same('body{}',$found->content());
		$missing=PanelAssetController::response('missing.css');
		$t->instanceOf(PanelPageResult::class,$missing);
		$t->same(404,$missing->status());
	})->tag('panel','panel-manifest-http-residual-exact','asset-controller','http-absence','deep-coverage')->group('framework-coverage');
}
