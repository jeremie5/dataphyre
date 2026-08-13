<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelStudioEditorAssets;
use Dataphyre\Panel\PanelStudioEditorOptions;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

test('Studio editor external delivery selects one sealed aggregate bundle', static function(Context $t): void {
	$options=PanelStudioEditorOptions::make([
		'action_url'=>'/studio/edit',
		'csrf_token'=>str_repeat('S', 32),
		'inline_assets'=>false,
	]);
	$t->isFalse($options->inlineAssets());

	$graph=PanelRenderer::assetCapabilityManifest(['studio_editor']);
	$t->same(['shell','form','studio-editor'], $graph->capabilities());
	$t->same('shell.form.studio-editor', $graph->token());
	$t->contains('studio-editor', $graph->styleChunks());
	$t->contains('studio-editor', $graph->runtimeChunks());

	$css=(string)(PanelRenderer::assetContent('panel.css', $graph->bundleCapabilities())['content'] ?? '');
	$javascript=(string)(PanelRenderer::assetContent('panel.js', $graph->bundleCapabilities())['content'] ?? '');
	$t->contains('.dp-studio{', $css);
	$t->contains('window.DataphyrePanelStudioEditor=', $javascript);
	$t->contains('dpPanelListen(document,"DOMContentLoaded",start,{once:true})', $javascript);
	$t->contains('dpPanelListen(window,"pagehide",stop,{once:true})', $javascript);
	$t->notContains('document.addEventListener("DOMContentLoaded",start,{once:true})', $javascript);
	$t->notContains('window.addEventListener("pagehide",stop,{once:true})', $javascript);
	$t->notSame(hash('sha256', PanelRenderer::assetContent('panel.css', ['form'])['content'] ?? ''), hash('sha256', $css));
	$t->notSame(hash('sha256', PanelRenderer::assetContent('panel.js', ['form'])['content'] ?? ''), hash('sha256', $javascript));
	$t->same(hash('sha256', PanelStudioEditorAssets::css()), PanelStudioEditorAssets::manifest()['styles']['sha256'] ?? null);

	$manifest=PanelRenderer::assetManifest(['studio-editor'], 'capability', [
		'capability_urls'=>[
			'studio-editor'=>['url'=>'https://evil.example.test/studio-editor.js', 'type'=>'script'],
		],
	]);
	$descriptors=array_merge($manifest['styles'], $manifest['scripts']);
	$t->same(['panel.css','panel.js'], array_column($descriptors, 'name'));
	$t->same(false, in_array('studio-editor', array_column($descriptors, 'capability'), true));
	$t->notContains('evil.example.test', json_encode($manifest, JSON_THROW_ON_ERROR));
	$t->same([], $manifest['missing_capabilities']);

	$page=PanelContext::run([
		'navigation_layout'=>'none',
		'asset_url_builder'=>static fn(string $asset): string=>'/assets/'.$asset,
	], static fn(): PanelPageResult=>$t->nonPublic(PanelRenderer::class)->invoke(
		'page',
		'Studio',
		'<section data-dp-studio-editor>Studio surface</section>',
		['kind'=>'custom', 'navigation_state'=>[]],
	));
	$t->same(['shell','form','studio-editor'], $page->data()['asset_capabilities'] ?? null);
	$t->contains('data-dp-panel-assets="shell form studio-editor"', $page->content());
	$t->contains('dp_panel_caps=shell.form.studio-editor', $page->content());
	$t->notContains('/assets/panel-editor.js', $page->content());
})->tag('panel', 'studio', 'assets', 'capabilities', 'security')->maxMillis(6000);
