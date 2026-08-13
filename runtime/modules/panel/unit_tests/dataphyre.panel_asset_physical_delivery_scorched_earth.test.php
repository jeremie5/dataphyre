<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Http\Request;
use Dataphyre\Panel\PanelAssetCapabilityManifest;
use Dataphyre\Panel\PanelAssetController;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http', 'panel']);

test('physical delivery emits real ordered cacheable style and runtime chunks', static function(Context $t): void {
	$graph=PanelAssetCapabilityManifest::make(['navigation','modal','board','data-surface','widget-runtime','studio-editor'], 'physical');
	$t->same('physical', $graph->mode());
	$t->isTrue($graph->isPhysical());
	$t->isFalse($graph->isFull());
	$t->same('physical', PanelAssetCapabilityManifest::make(['shell'], 'chunked')->mode());
	$t->same('physical', PanelAssetCapabilityManifest::make(['shell'], 'physical_chunks')->mode());
	$t->same('capability', PanelAssetCapabilityManifest::make(['shell'], 'split')->mode());

	$manifest=PanelContext::run([
		'asset_url_builder'=>static fn(string $asset): string=>'/panel/assets/'.$asset,
	], static fn(): array=>PanelRenderer::assetManifest(
		$graph->capabilities(),
		'physical',
		['integrity'=>true, 'nonce'=>'physical-nonce'],
	));

	$t->same('physical', $manifest['mode']);
	$t->same('physical', $manifest['delivery']['strategy'] ?? null);
	$t->same(true, $manifest['delivery']['physical'] ?? null);
	$t->same([
		'tokens','foundation','layout','experience','themes','accessibility',
	], $manifest['delivery']['style_chunks'] ?? null);
	$t->same([
		'kernel','interaction','transport','form','editor','studio-editor','quality','data-surface','widget-runtime','modal','board',
	], $manifest['delivery']['runtime_chunks'] ?? null);
	$t->same(17, $manifest['delivery']['built_in_requests'] ?? null);
	$t->same('window.DataphyrePanel.runtimeChunks', $manifest['delivery']['runtime_namespace'] ?? null);
	$t->same(['styles'=>'panel.css','scripts'=>'panel.js'], $manifest['delivery']['legacy_fallback'] ?? null);

	$styleNames=array_column(array_filter(
		$manifest['styles'],
		static fn(array $asset): bool=>($asset['physical'] ?? false)===true,
	), 'name');
	$runtime=array_values(array_filter(
		$manifest['scripts'],
		static fn(array $asset): bool=>($asset['physical'] ?? false)===true,
	));
	$t->same([
		'panel-style-tokens.css',
		'panel-style-foundation.css',
		'panel-style-layout.css',
		'panel-style-experience.css',
		'panel-style-themes.css',
		'panel-style-accessibility.css',
	], $styleNames);
	$t->same([
		'panel-runtime-kernel.js',
		'panel-runtime-interaction.js',
		'panel-runtime-transport.js',
		'panel-runtime-form.js',
		'panel-runtime-editor.js',
		'panel-runtime-studio-editor.js',
		'panel-runtime-quality.js',
		'panel-runtime-data-surface.js',
		'panel-runtime-widget-runtime.js',
		'panel-runtime-modal.js',
		'panel-runtime-board.js',
	], array_column($runtime, 'name'));
	$t->same([], $runtime[0]['dependencies'] ?? null);
	$t->same(['kernel'], $runtime[1]['dependencies'] ?? null);
	$t->same(['kernel','transport','form','quality'], $runtime[9]['dependencies'] ?? null);
	$t->contains('dp_panel_caps=', $manifest['styles'][0]['url'] ?? '');
	$t->contains('dp_panel_v=', $manifest['styles'][0]['url'] ?? '');
	$t->notContains('dp_panel_caps=', $runtime[0]['url'] ?? '');
	$t->contains('dp_panel_v=', $runtime[0]['url'] ?? '');
	$t->same('physical-nonce', $runtime[0]['attributes']['nonce'] ?? null);
	$t->contains('sha384-', $runtime[0]['attributes']['integrity'] ?? '');

	$tags=PanelRenderer::assetTags($manifest, 'style').PanelRenderer::assetTags($manifest, 'script');
	$t->contains('href="/panel/assets/panel-style-foundation.css?', $tags);
	$t->contains('src="/panel/assets/panel-runtime-kernel.js?', $tags);
	$t->lessThan(
		strpos($tags, 'panel-runtime-interaction.js'),
		strpos($tags, 'panel-runtime-kernel.js'),
	);
})->tag('panel','assets','physical','manifest','chunks')->maxMillis(8000);

test('physical chunk payloads preserve cascade ownership and fail-fast runtime imports', static function(Context $t): void {
	$capabilities=['navigation','modal','board','data-surface','widget-runtime','studio-editor'];
	$styles=[
		'panel-style-tokens.css',
		'panel-style-foundation.css',
		'panel-style-layout.css',
		'panel-style-experience.css',
		'panel-style-themes.css',
		'panel-style-accessibility.css',
	];
	$runtime=[
		'panel-runtime-kernel.js',
		'panel-runtime-interaction.js',
		'panel-runtime-transport.js',
		'panel-runtime-form.js',
		'panel-runtime-editor.js',
		'panel-runtime-studio-editor.js',
		'panel-runtime-quality.js',
		'panel-runtime-data-surface.js',
		'panel-runtime-widget-runtime.js',
		'panel-runtime-modal.js',
		'panel-runtime-board.js',
	];
	$css='';
	foreach($styles as $asset){
		$payload=PanelRenderer::assetContent($asset, $capabilities);
		$t->notNull($payload);
		$t->same('text/css; charset=UTF-8', $payload['content_type'] ?? null);
		$t->contains('@layer dp-tokens,dp-panel,dp-accessibility;', $payload['content'] ?? '');
		$css.=$payload['content'] ?? '';
	}
	foreach([
		'dp-owner:foundation',
		'dp-owner:components',
		'dp-owner:layout',
		'dp-owner:presentation',
		'dp-owner:navigation',
		'dp-owner:responsive',
		'dp-owner:themes',
		'dp-owner:system',
		'dp-owner:visual-system',
		'dp-owner:brick-v2',
	] as $owner){
		$t->contains($owner, $css);
	}

	foreach($runtime as $index=>$asset){
		$payload=PanelRenderer::assetContent($asset);
		$t->notNull($payload);
		$t->same('application/javascript; charset=UTF-8', $payload['content_type'] ?? null);
		$t->contains('dp-panel-runtime-chunk:', $payload['content'] ?? '');
		$t->contains('registerRuntimeChunk(', $payload['content'] ?? '');
		$t->contains('sourceURL=dataphyre-panel/', $payload['content'] ?? '');
		if($index===0){
			$t->contains('panel.requireRuntimeChunks=function', $payload['content'] ?? '');
			$t->contains('panel.registerRuntimeChunk=function', $payload['content'] ?? '');
		}
		else {
			$t->contains('panel.requireRuntimeChunks(', $payload['content'] ?? '');
		}
		$t->notContains('/**', $payload['content'] ?? '');
	}
	$t->contains('dp-panel-runtime-owner:kernel', PanelRenderer::assetContent('panel-runtime-kernel.js')['content'] ?? '');
	$t->contains('dp-panel-runtime-owner:shell', PanelRenderer::assetContent('panel-runtime-kernel.js')['content'] ?? '');
	$t->contains('dp-panel-runtime-owner:command', PanelRenderer::assetContent('panel-runtime-interaction.js')['content'] ?? '');
	$t->contains('dp-panel-runtime-owner:state-table', PanelRenderer::assetContent('panel-runtime-interaction.js')['content'] ?? '');
	$t->contains('dp-panel-runtime-owner:navigation', PanelRenderer::assetContent('panel-runtime-interaction.js')['content'] ?? '');
	$t->contains('dp-panel-runtime-owner:validation-upload', PanelRenderer::assetContent('panel-runtime-quality.js')['content'] ?? '');
	$t->contains('dp-panel-runtime-owner:accessibility', PanelRenderer::assetContent('panel-runtime-quality.js')['content'] ?? '');
	$t->contains('dp-panel-runtime-owner:theme', PanelRenderer::assetContent('panel-runtime-quality.js')['content'] ?? '');
	$t->same(null, PanelRenderer::assetContent('panel-runtime-unknown.js'));
	$t->same(null, PanelRenderer::assetContent('panel-style-unknown.css', $capabilities));
	$t->same(null, PanelRenderer::assetContent('panel-runtime-kernel.css'));
	$renderer=$t->nonPublic(PanelRenderer::class);
	$t->same(null, $renderer->invoke('physicalStyleChunk', 'unknown', PanelAssetCapabilityManifest::make($capabilities, 'physical')));
	$t->same(null, $renderer->invoke('physicalRuntimeChunk', 'unknown'));
	$publicManifest=PanelRenderer::assetContent('panel-assets.json', $capabilities);
	$t->same('application/json; charset=UTF-8', $publicManifest['content_type'] ?? null);
	$publicManifestData=json_decode($publicManifest['content'] ?? '', true, flags: JSON_THROW_ON_ERROR);
	$t->same('physical', $publicManifestData['mode'] ?? null);
	$t->same(true, $publicManifestData['delivery']['physical'] ?? null);
	$t->same(false, str_contains($publicManifest['content'] ?? '', 'physical-nonce'));
	$t->notSame('missing', PanelRenderer::assetVersion('panel-runtime-kernel.js'));
	$t->contains('sha384-', PanelRenderer::assetIntegrity('panel-style-foundation.css', $capabilities));
})->tag('panel','assets','physical','content','runtime','css')->maxMillis(12000);

test('physical asset routes are immutable conditional and reject token smuggling', static function(Context $t): void {
	$token='shell.table';
	$style=PanelAssetController::response(
		'panel-style-foundation.css',
		Request::create('GET', '/panel/assets/panel-style-foundation.css', ['dp_panel_caps'=>$token]),
	);
	$t->same(200, $style->status);
	$t->same('physical', $style->headers['X-Dataphyre-Panel-Asset-Mode'] ?? null);
	$t->same('shell,table', $style->headers['X-Dataphyre-Panel-Capabilities'] ?? null);
	$t->same('public, max-age=31536000, immutable', $style->headers['Cache-Control'] ?? null);
	$t->contains('text/css', $style->headers['Content-Type'] ?? '');
	$t->contains('dp-owner:foundation', (string)$style->body);

	$runtime=PanelAssetController::response(
		'panel-runtime-kernel.js',
		Request::create('GET', '/panel/assets/panel-runtime-kernel.js'),
	);
	$t->same(200, $runtime->status);
	$t->same('physical', $runtime->headers['X-Dataphyre-Panel-Asset-Mode'] ?? null);
	$t->same(false, isset($runtime->headers['X-Dataphyre-Panel-Capabilities']));
	$t->contains('application/javascript', $runtime->headers['Content-Type'] ?? '');
	$t->contains('registerRuntimeChunk', (string)$runtime->body);
	$manifest=PanelAssetController::response(
		'panel-assets.json',
		Request::create('GET', '/panel/assets/panel-assets.json', ['dp_panel_caps'=>$token]),
	);
	$t->same(200, $manifest->status);
	$t->same('physical', $manifest->headers['X-Dataphyre-Panel-Asset-Mode'] ?? null);
	$t->contains('application/json', $manifest->headers['Content-Type'] ?? '');
	$t->same('physical', json_decode((string)$manifest->body, true, flags: JSON_THROW_ON_ERROR)['mode'] ?? null);

	$head=PanelAssetController::response(
		'panel-runtime-kernel.js',
		Request::create('HEAD', '/panel/assets/panel-runtime-kernel.js'),
	);
	$t->same('', (string)$head->body);
	$t->same((string)strlen((string)$runtime->body), $head->headers['Content-Length'] ?? null);

	$smuggled=PanelAssetController::response(
		'panel-runtime-kernel.js',
		Request::create('GET', '/panel/assets/panel-runtime-kernel.js', ['dp_panel_caps'=>'shell']),
	);
	$t->same(404, $smuggled->status);
	$t->same('no-store', $smuggled->headers['Cache-Control'] ?? null);
	$t->same(404, PanelAssetController::response('panel-style-unknown.css')->status);
	$t->same(404, PanelAssetController::response('panel-runtime-kernel.css')->status);

	$routeHandled=PanelAssetController::handle(
		Request::create('GET', '/panel/assets/panel-runtime-kernel.js'),
		['asset'=>'panel-runtime-kernel.js'],
	);
	$t->same(200, $routeHandled->status);
	$queryHandled=PanelAssetController::handle(
		Request::create('GET', '/panel/assets', ['asset'=>'panel-runtime-kernel.js']),
	);
	$t->same(200, $queryHandled->status);
	$invoked=(new PanelAssetController())(Request::create('GET', '/panel/assets/panel-runtime-kernel.js'));
	$t->same(200, $invoked->status);
	$controller=$t->nonPublic(PanelAssetController::class);
	$t->same([], $controller->invoke('responseHeaders', new stdClass()));
	$t->same(200, $controller->invoke('responseStatus', new stdClass()));
})->tag('panel','assets','physical','controller','security')->maxMillis(6000);

test('shells can opt into physical delivery while retaining aggregate rollback', static function(Context $t): void {
	$config=[
		'navigation_layout'=>'none',
		'asset_url_builder'=>static fn(string $asset): string=>'/assets/'.$asset,
		'asset_mode'=>'physical',
	];
	$page=PanelContext::run($config, static fn(): PanelPageResult=>$t->nonPublic(PanelRenderer::class)->invoke(
		'page',
		'Physical table',
		'<section class="dp-panel-table"><table><tbody><tr><td>One</td></tr></tbody></table></section>',
		['kind'=>'index', 'navigation_state'=>[]],
	));
	$html=$page->content();
	$t->same('physical', $page->data()['asset_manifest']['mode'] ?? null);
	$t->contains('/assets/panel-style-foundation.css?', $html);
	$t->contains('/assets/panel-runtime-kernel.js?', $html);
	$t->contains('/assets/panel-runtime-interaction.js?', $html);
	$t->notContains('href="/assets/panel.css"', $html);
	$t->notContains('src="/assets/panel.js"', $html);

	$fallback=PanelContext::run(array_replace($config, ['asset_mode'=>'full']), static fn(): PanelPageResult=>$t->nonPublic(PanelRenderer::class)->invoke(
		'page',
		'Fallback table',
		'<section class="dp-panel-table"></section>',
		['kind'=>'index', 'navigation_state'=>[]],
	));
	$t->same('full', $fallback->data()['asset_manifest']['mode'] ?? null);
	$t->contains('href="/assets/panel.css"', $fallback->content());
	$t->contains('src="/assets/panel.js"', $fallback->content());
})->tag('panel','assets','physical','shell','fallback')->maxMillis(8000);

test('physical delivery release budgets ratchet raw gzip brotli parse and browser runtime costs', static function(Context $t): void {
	$testing=dirname(__DIR__).'/testing';
	$budgetPath=$testing.'/panel_asset_delivery_budgets.json';
	$auditPath=$testing.'/panel_asset_delivery_audit.js';
	$budgets=json_decode((string)file_get_contents($budgetPath), true, flags: JSON_THROW_ON_ERROR);
	$t->same('dataphyre.panel.asset-delivery-budgets.v1', $budgets['schema'] ?? null);
	$t->same(['shell','table','form','modal','full'], array_keys($budgets['profiles'] ?? []));
	$t->same(250, $budgets['runtime']['maxParseMilliseconds'] ?? null);
	$t->same(350, $budgets['runtime']['maxBootstrapMilliseconds'] ?? null);
	$t->same(0, $budgets['runtime']['maxLongTasks'] ?? null);
	$t->same(0, $budgets['runtime']['maxPageErrors'] ?? null);

	foreach($budgets['profiles'] as $profile=>$budget){
		$token=$budget['capabilities'];
		$capabilities=$token===null ? '*' : PanelAssetCapabilityManifest::decodeToken($token);
		$t->notSame(null, $capabilities, $profile.' capability token');
		$manifest=PanelRenderer::assetManifest($capabilities, 'physical');
		$assets=array_values(array_filter(
			array_merge($manifest['styles'], $manifest['scripts']),
			static fn(array $asset): bool=>($asset['external'] ?? false)!==true,
		));
		$raw=0;
		$gzip=0;
		$maxRaw=0;
		$maxGzip=0;
		foreach($assets as $asset){
			$scoped=str_starts_with($asset['name'], 'panel-style-') ? $manifest['bundle_capabilities'] : null;
			$content=(string)(PanelRenderer::assetContent($asset['name'], $scoped)['content'] ?? '');
			$compressed=gzencode($content, 9);
			$t->notSame(false, $compressed, $asset['name'].' gzip');
			$bytes=strlen($content);
			$gzipBytes=strlen((string)$compressed);
			$raw+=$bytes;
			$gzip+=$gzipBytes;
			$maxRaw=max($maxRaw, $bytes);
			$maxGzip=max($maxGzip, $gzipBytes);
		}
		$styles=count(array_filter($assets, static fn(array $asset): bool=>$asset['type']==='style'));
		$scripts=count($assets)-$styles;
		$t->lessThanOrEqual($budget['maxStyleChunks'], $styles, $profile.' style chunks');
		$t->lessThanOrEqual($budget['maxScriptChunks'], $scripts, $profile.' script chunks');
		$t->lessThanOrEqual($budget['maxTotalChunks'], count($assets), $profile.' total chunks');
		$t->lessThanOrEqual($budget['maxRawBytes'], $raw, $profile.' raw bytes');
		$t->lessThanOrEqual($budget['maxGzipBytes'], $gzip, $profile.' gzip bytes');
		$t->lessThanOrEqual($budget['maxChunkRawBytes'], $maxRaw, $profile.' largest raw chunk');
		$t->lessThanOrEqual($budget['maxChunkGzipBytes'], $maxGzip, $profile.' largest gzip chunk');
	}

	$auditor=(string)file_get_contents($auditPath);
	foreach([
		"require('./panel_asset_delivery_budgets.json')",
		'zlib.brotliCompressSync',
		'new vm.Script',
		'compileMilliseconds',
		'runtimeDependencyOrder',
		'panel-assets.json',
		'registerRuntimeChunk(',
		'PerformanceObserver.supportedEntryTypes',
		'maxBootstrapMilliseconds',
		'maxChunkBootstrapMilliseconds',
		'maxLongTasks',
		'maxPageErrors',
		'puppeteer.launch',
	] as $contract){
		$t->contains($contract, $auditor);
	}
})->tag('panel','assets','physical','budget','gzip','brotli','browser','release')->maxMillis(20000);
