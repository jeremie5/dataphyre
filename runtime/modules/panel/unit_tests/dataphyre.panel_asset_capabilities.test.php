<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelAssetCapabilityManifest;
use Dataphyre\Panel\PanelAssetController;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Http\Request;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http', 'panel']);

test('panel shell discovers additive asset capabilities from kinds markup and declarations', static function(Context $t): void {
	$renderer=$t->nonPublic(PanelRenderer::class);
	$content='<section class="dp-panel-table dp-panel-form dp-panel-board dp-panel-chart dp-panel-modal" '
		.'data-dp-panel-table data-dp-panel-form data-dp-panel-editor data-dp-panel-uploader '
		.'data-dp-panel-editor-assets-trigger data-dp-panel-editor-assets-host '
		.'data-dp-panel-board data-dp-panel-chart data-dp-panel-modal data-dp-panel-collaboration '
		.'data-dp-panel-media data-dp-widget-island data-dp-data-surface data-dp-studio-editor><input type="file"></section>';
	$capabilities=$renderer->invoke('pageAssetCapabilities', $content, [
		'asset_capabilities'=>'extensions, quality-client',
	], 'board', 'sidebar');
	$t->same([
		'board', 'chart', 'collaboration', 'data-surface', 'editor', 'editor-assets', 'extensions', 'form', 'media',
		'modal', 'navigation', 'quality-client', 'shell', 'studio-editor', 'table', 'upload', 'widget-runtime',
	], $capabilities);

	$expectedKinds=[
		'index'=>['shell','table'],
		'board'=>['board','shell','table'],
		'create'=>['form','shell'],
		'store'=>['form','shell'],
		'edit'=>['form','shell'],
		'update'=>['form','shell'],
		'action_form'=>['form','modal','shell'],
		'import'=>['form','shell','upload'],
		'import_preview'=>['form','shell','table'],
		'show'=>['record','shell'],
		'custom'=>['shell'],
	];
	foreach($expectedKinds as $kind=>$expected){
		$t->same($expected, $renderer->invoke('pageAssetCapabilities', '', [], $kind, 'none'));
	}
	$t->same(['collection-layout','shell'], $renderer->invoke(
		'pageAssetCapabilities',
		'<div data-dp-display="masonry"><span data-dp-item-layout="1">Layout</span></div>',
		[],
		'custom',
		'none',
	));
	$t->same(['shell','table'], $renderer->invoke(
		'pageAssetCapabilities',
		'<div class="dp-panel-table" data-dp-display="brick"></div>',
		[],
		'index',
		'none',
	));
	$t->same(['collection-layout','shell','table'], $renderer->invoke(
		'pageAssetCapabilities',
		'<div class="dp-panel-table" data-dp-display="brick"><button data-dp-item-responsive="1">Responsive</button></div>',
		[],
		'index',
		'none',
	));

	$t->same(['kept','shell','table'], $renderer->invoke('pageAssetCapabilities', '', [
		'asset_capabilities'=>[
			'kept'=>true,
			'disabled'=>false,
			new stdClass(),
			'***',
			'table',
		],
	], 'custom', 'none'));
	$t->same(['shell'], $renderer->invoke('pageAssetCapabilities', '', [
		'asset_capabilities'=>new stdClass(),
	], 'custom', 'none'));
})->tag('panel', 'assets', 'capabilities')->maxMillis(2000);

test('panel shell only delivers optional browser packages to surfaces that require them', static function(Context $t): void {
	$config=[
		'navigation_layout'=>'none',
		'asset_url_builder'=>static fn(string $asset): string=>'/assets/'.$asset,
	];
	$plain=PanelContext::run($config, static fn(): PanelPageResult=>$t->nonPublic(PanelRenderer::class)->invoke(
		'page',
		'Plain',
		'<section>Plain content</section>',
		['kind'=>'custom', 'navigation_state'=>[]],
	));
	$t->contains('data-dp-panel-assets="shell"', $plain->content());
	$t->contains('/assets/panel.css', $plain->content());
	$t->contains('/assets/panel.js', $plain->content());
	$t->notContains('/assets/panel-editor.js', $plain->content());
	$t->notContains('/assets/panel-editor-assets.js', $plain->content());
	$t->notContains('/assets/panel-extensions.js', $plain->content());
	$t->same(['shell'], $plain->data()['asset_capabilities'] ?? null);
	$layout=PanelContext::run($config, static fn(): PanelPageResult=>$t->nonPublic(PanelRenderer::class)->invoke(
		'page',
		'Layout',
		'<section data-dp-display="masonry"><button data-dp-item-layout="1">Responsive item</button></section>',
		['kind'=>'custom', 'navigation_state'=>[]],
	));
	$t->contains('data-dp-panel-assets="shell collection-layout"', $layout->content());
	$t->contains('dp_panel_caps=shell.collection-layout', $layout->content());
	$t->same(['shell','collection-layout'], $layout->data()['asset_capabilities'] ?? null);

	$enhanced=PanelContext::run($config, static fn(): PanelPageResult=>$t->nonPublic(PanelRenderer::class)->invoke(
		'page',
		'Editor',
		'<section data-dp-panel-editor>Editor content</section>',
		[
			'kind'=>'edit',
			'navigation_state'=>[],
			'asset_capabilities'=>['extensions'=>true],
		],
	));
	$t->contains('data-dp-panel-assets="shell form editor extensions"', $enhanced->content());
	$t->contains('/assets/panel-editor.js', $enhanced->content());
	$t->notContains('/assets/panel-editor-assets.js', $enhanced->content());
	$t->contains('/assets/panel-extensions.js', $enhanced->content());
	$t->same(['shell','form','editor','extensions'], $enhanced->data()['asset_capabilities'] ?? null);

	$assetEditor=PanelContext::run($config, static fn(): PanelPageResult=>$t->nonPublic(PanelRenderer::class)->invoke(
		'page',
		'Asset editor',
		'<section data-dp-panel-editor><button data-dp-panel-editor-assets-trigger></button><div data-dp-panel-editor-assets-host></div></section>',
		['kind'=>'edit', 'navigation_state'=>[]],
	));
	$t->contains('data-dp-panel-assets="shell form editor editor-assets"', $assetEditor->content());
	$t->contains('/assets/panel-editor.js', $assetEditor->content());
	$t->contains('/assets/panel-editor-assets.js', $assetEditor->content());
	$t->same(['shell','form','editor','editor-assets'], $assetEditor->data()['asset_capabilities'] ?? null);

	$surface=PanelContext::run($config, static fn(): PanelPageResult=>$t->nonPublic(PanelRenderer::class)->invoke(
		'page',
		'Orders',
		'<section data-dp-data-surface>Signed order window</section>',
		['kind'=>'custom', 'navigation_state'=>[]],
	));
	$t->contains('data-dp-panel-assets="shell data-surface"', $surface->content());
	$t->contains('dp_panel_caps=shell.data-surface', $surface->content());
	$t->same(['shell','data-surface'], $surface->data()['asset_capabilities'] ?? null);
	$surfaceGraph=PanelRenderer::assetCapabilityManifest($surface->data()['asset_capabilities'] ?? []);
	$t->contains('data-surface', $surfaceGraph->styleChunks());
	$t->contains('data-surface', $surfaceGraph->runtimeChunks());
})->tag('panel', 'assets', 'capabilities', 'shell')->maxMillis(3000);

test('asset capability graphs normalize aliases close dependencies and reject forged route tokens', static function(Context $t): void {
	$board=PanelAssetCapabilityManifest::make(['boards', 'quality_client', 'vendor-lab']);
	$t->same(['board','quality-client','vendor-lab'], $board->requested());
	$t->same(['shell','table','board','quality-client','vendor-lab'], $board->capabilities());
	$t->same(['shell','table','board'], $board->bundleCapabilities());
	$t->same('shell.table.board', $board->token());
	$t->same(['core','table','board'], $board->styleChunks());
	$t->same(['kernel','shell','command','state-table','navigation','transport','validation-upload','accessibility','theme','board'], $board->runtimeChunks());
	$t->same(['shell','table','board'], PanelAssetCapabilityManifest::decodeToken('shell.table.board'));
	$t->same(null, PanelAssetCapabilityManifest::decodeToken('table.shell'));
	$t->same(null, PanelAssetCapabilityManifest::decodeToken('shell.shell'));
	$t->same(null, PanelAssetCapabilityManifest::decodeToken('shell.vendor'));
	$t->same(null, PanelAssetCapabilityManifest::decodeToken('../shell'));
	$widget=PanelAssetCapabilityManifest::make(['widgets']);
	$t->same(['widget-runtime'], $widget->requested());
	$t->same(['shell','widget-runtime'], $widget->capabilities());
	$t->same(['shell','widget-runtime'], $widget->bundleCapabilities());
	$t->same('shell.widget-runtime', $widget->token());
	$t->same(['core','widget-runtime'], $widget->styleChunks());
	$t->same(['kernel','shell','command','state-table','navigation','transport','validation-upload','accessibility','theme','widget-runtime'], $widget->runtimeChunks());
	$t->same(['shell','widget-runtime'], PanelAssetCapabilityManifest::decodeToken('shell.widget-runtime'));
	$layout=PanelAssetCapabilityManifest::make(['masonry']);
	$t->same(['collection-layout'], $layout->requested());
	$t->same(['shell','collection-layout'], $layout->capabilities());
	$t->same(['shell','collection-layout'], $layout->bundleCapabilities());
	$t->same('shell.collection-layout', $layout->token());
	$t->same(['core','collection-layout'], $layout->styleChunks());
	$t->same(['shell','collection-layout'], PanelAssetCapabilityManifest::decodeToken($layout->token()));
	$studio=PanelAssetCapabilityManifest::make(['studio_editor']);
	$t->same(['studio-editor'], $studio->requested());
	$t->same(['shell','form','studio-editor'], $studio->capabilities());
	$t->same(['shell','form','studio-editor'], $studio->bundleCapabilities());
	$t->same('shell.form.studio-editor', $studio->token());
	$t->same(['core','form','studio-editor'], $studio->styleChunks());
	$t->contains('studio-editor', $studio->runtimeChunks());
	$t->isTrue(PanelAssetCapabilityManifest::make(['studioeditor'])->has('studio-editor'));
	$t->same(['shell','form','studio-editor'], PanelAssetCapabilityManifest::decodeToken($studio->token()));
	$editorAssets=PanelAssetCapabilityManifest::make(['editor_assets']);
	$t->same(['editor-assets'], $editorAssets->requested());
	$t->same(['shell','form','editor','editor-assets'], $editorAssets->capabilities());
	$t->same(['shell','form','editor','editor-assets'], $editorAssets->bundleCapabilities());
	$t->same('shell.form.editor.editor-assets', $editorAssets->token());
	$t->same(['core','form','editor-assets'], $editorAssets->styleChunks());
	$t->same(['shell','form','editor','editor-assets'], PanelAssetCapabilityManifest::decodeToken($editorAssets->token()));

	$mapped=PanelAssetCapabilityManifest::make([
		'forms'=>true,
		'editor'=>1,
		'upload'=>'yes',
		'disabled'=>false,
		'zero'=>0,
		new stdClass(),
	]);
	$t->same(['form','upload','editor'], $mapped->requested());
	$t->same(['shell','form','upload','editor'], $mapped->capabilities());
	$t->same(true, $mapped->has('forms'));
	$t->same(false, $mapped->has('disabled'));

	$modal=PanelAssetCapabilityManifest::make(['modal']);
	$t->same(['shell','form','upload','editor','editor-assets','modal'], $modal->capabilities());
	$t->same(['shell','form','editor','editor-assets','modal'], $modal->bundleCapabilities());
	$t->same('shell.form.editor.editor-assets.modal', $modal->token());

	$upload=PanelAssetCapabilityManifest::make(['upload']);
	$form=PanelAssetCapabilityManifest::make(['form']);
	$t->same(['shell','form','upload'], $upload->capabilities());
	$t->same(['shell','form'], $upload->bundleCapabilities());
	$t->same($form->token(), $upload->token());
	$t->same(
		PanelRenderer::assetContent('panel.css', $form->bundleCapabilities())['content'] ?? null,
		PanelRenderer::assetContent('panel.css', $upload->bundleCapabilities())['content'] ?? null,
	);
	$t->same(
		PanelRenderer::assetContent('panel.js', $form->bundleCapabilities())['content'] ?? null,
		PanelRenderer::assetContent('panel.js', $upload->bundleCapabilities())['content'] ?? null,
	);
	$t->same(['shell','form'], PanelAssetCapabilityManifest::decodeToken($upload->token()));
	$t->contains('upload', $modal->capabilities());
	$t->same(['shell','form','upload','editor','editor-assets','modal'], PanelAssetCapabilityManifest::make(
		PanelAssetCapabilityManifest::decodeToken($modal->token()) ?? [],
	)->capabilities());

	$full=PanelAssetCapabilityManifest::make('*');
	$t->same(PanelAssetCapabilityManifest::knownCapabilities(), $full->requested());
	$t->same(PanelAssetCapabilityManifest::fullCapabilities(), $full->capabilities());
	$t->same('full', PanelAssetCapabilityManifest::make(['shell'], 'typo')->mode());
	$t->same('capability', PanelAssetCapabilityManifest::make([], 'split')->mode());
	$t->same([], PanelAssetCapabilityManifest::make(true)->requested());
	$t->same([], PanelAssetCapabilityManifest::make(new stdClass())->requested());
	$t->same($board->toArray(), $board->jsonSerialize());
	$t->same($board->toArray()['id'], PanelAssetCapabilityManifest::make(['vendor-lab','quality-client','board'])->toArray()['id']);
})->tag('panel', 'assets', 'capabilities', 'graph', 'security')->maxMillis(2000);

test('capability aggregates preserve the full legacy bytes and remove unneeded source chunks', static function(Context $t): void {
	$legacyCss=(string)(PanelRenderer::assetContent('panel.css')['content'] ?? '');
	$legacyJs=(string)(PanelRenderer::assetContent('panel.js')['content'] ?? '');
	$fullCss=(string)(PanelRenderer::assetContent('panel.css', '*')['content'] ?? '');
	$fullJs=(string)(PanelRenderer::assetContent('panel.js', '*')['content'] ?? '');
	$shellCss=(string)(PanelRenderer::assetContent('panel.css', ['shell'])['content'] ?? '');
	$shellJs=(string)(PanelRenderer::assetContent('panel.js', ['shell'])['content'] ?? '');
	$collectionLayoutCss=(string)(PanelRenderer::assetContent('panel.css', ['collection-layout'])['content'] ?? '');
	$formCss=(string)(PanelRenderer::assetContent('panel.css', ['form'])['content'] ?? '');
	$recordCss=(string)(PanelRenderer::assetContent('panel.css', ['record'])['content'] ?? '');
	$formJs=(string)(PanelRenderer::assetContent('panel.js', ['form'])['content'] ?? '');
	$tableJs=(string)(PanelRenderer::assetContent('panel.js', ['table'])['content'] ?? '');
	$tableCss=(string)(PanelRenderer::assetContent('panel.css', ['table'])['content'] ?? '');
	$widgetCss=(string)(PanelRenderer::assetContent('panel.css', ['widget-runtime'])['content'] ?? '');
	$widgetJs=(string)(PanelRenderer::assetContent('panel.js', ['widget-runtime'])['content'] ?? '');
	$studioCss=(string)(PanelRenderer::assetContent('panel.css', ['studio-editor'])['content'] ?? '');
	$studioJs=(string)(PanelRenderer::assetContent('panel.js', ['studio-editor'])['content'] ?? '');
	$editorCss=(string)(PanelRenderer::assetContent('panel.css', ['editor'])['content'] ?? '');
	$editorAssetCss=(string)(PanelRenderer::assetContent('panel.css', ['editor-assets'])['content'] ?? '');

	$t->same($legacyCss, $fullCss);
	$t->same($legacyJs, $fullJs);
	$t->lessThan(strlen($legacyCss), strlen($shellCss));
	$t->lessThan(strlen($legacyJs), strlen($shellJs));
	$t->same(true, strlen($shellCss)<=((int)(strlen($legacyCss)*0.73)));
	$t->same(true, strlen($shellJs)<=((int)(strlen($legacyJs)*0.73)));
	$t->contains('dpPanelBeginController("state_table")', $shellJs);
	$t->notContains('dpPanelBeginController("widget_runtime")', $shellJs);
	$t->notContains('.dp-panel-widget-island', $shellCss);
	$t->notContains('dp-owner:brick-v2', $shellCss);
	$t->contains('dp-owner:brick-v2', $collectionLayoutCss);
	$t->contains('[data-dp-item-responsive="1"]', $collectionLayoutCss);
	$t->contains('dp-owner:brick-v2', $tableCss);
	$t->notContains('[data-dp-item-responsive="1"]', $tableCss);
	$t->contains('.dp-panel-choice>input{flex:0 0 20px', $formCss);
	$t->contains('.dp-panel-grid-item-auto{grid-column:auto/span min(', $formCss);
	$t->contains('.dp-panel-grid-item-auto{grid-column:auto/span min(', $recordCss);
	$t->contains('.dp-panel-switch-track{display:block;flex:0 0 42px', $formCss);
	$t->notContains('.dp-panel-choice>input{flex:0 0 20px', $shellCss);
	$t->notContains('.dp-panel-choice>input{flex:0 0 20px', $tableCss);
	$t->notContains('.dp-panel-grid-item-auto{grid-column:auto/span min(', $shellCss);
	$t->notContains('.dp-panel-grid-item-auto{grid-column:auto/span min(', $tableCss);
	$t->lessThan(strlen($collectionLayoutCss), strlen($shellCss));
	$t->contains('dpPanelBeginController("widget_runtime")', $widgetJs);
	$t->contains('.dp-panel-widget-island{display:grid;min-width:0;gap:0}', $widgetCss);
	$t->contains('.dp-panel-widget-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-top:1px solid var(--dp-border_soft);background:var(--dp-surface,#fff);padding:10px 16px}', $widgetCss);
	$t->contains('.dp-studio{', $studioCss);
	$t->contains('dpPanelBeginController("studio_editor")', $studioJs);
	$t->contains('window.DataphyrePanelStudioEditor=', $studioJs);
	$t->contains('.dp-panel-editor-external-host', $editorCss);
	$t->notContains('.dp-panel-editor-assets-dialog', $editorCss);
	$t->contains('.dp-panel-editor-external-host', $editorAssetCss);
	$t->contains('.dp-panel-editor-assets-dialog', $editorAssetCss);
	$t->lessThan(strlen($editorAssetCss), strlen($editorCss));
	$t->contains('DataphyrePanelEditors', (string)(PanelRenderer::assetContent('panel-editor.js')['content'] ?? ''));
	$t->notContains('registerAssets', (string)(PanelRenderer::assetContent('panel-editor.js')['content'] ?? ''));
	$t->contains('registerAssets', (string)(PanelRenderer::assetContent('panel-editor-assets.js')['content'] ?? ''));
	$t->notContains('.dp-studio{', $formJs);
	$t->notContains('window.DataphyrePanelStudioEditor=', $formJs);
	$t->notContains('dpPanelBeginController("fields")', $shellJs);
	$t->contains('dpPanelBeginController("navigation")', $shellJs);
	$t->contains('dpPanelBeginController("fields")', $formJs);
	$t->contains('dpPanelBeginController("validation_upload")', $formJs);
	$t->contains('dpPanelBeginController("state_table")', $tableJs);
	$t->notContains('dpPanelBeginController("fields")', $tableJs);
	$navigationCss=(string)(PanelRenderer::assetContent('panel.css', ['navigation'])['content'] ?? '');
	$t->lessThan(strlen($navigationCss), strlen($shellCss));
	$t->same(true, strlen($navigationCss)-strlen($shellCss)>90000);
	$t->same(PanelRenderer::assetVersion('panel.js', ['shell']), substr(hash('sha256', $shellJs), 0, 16));
	$t->same('sha384-'.base64_encode(hash('sha384', $shellJs, true)), PanelRenderer::assetIntegrity('panel.js', ['shell']));
	$t->notSame(PanelRenderer::assetVersion('panel.js', ['shell']), PanelRenderer::assetVersion('panel.js', ['form']));
})->tag('panel', 'assets', 'capabilities', 'bundles', 'budget')->maxMillis(6000);

test('asset manifests are content addressed CSP compatible and extensible without trusting markup', static function(Context $t): void {
	$options=[
		'integrity'=>true,
		'nonce'=>'nonce-123',
		'attributes'=>[
			'all'=>['referrerpolicy'=>'same-origin', 'onclick'=>'bad()'],
			'script'=>['fetchpriority'=>'low'],
		],
		'capability_urls'=>[
			'reactor'=>['url'=>'/reactor/reactor.js?v=1', 'type'=>'script'],
			'vendor-lab'=>['url'=>'https://cdn.example.test/vendor.css', 'type'=>'style', 'attributes'=>['media'=>'screen', 'onload'=>'bad()']],
			'unsafe'=>['url'=>'javascript:alert(1)', 'type'=>'script'],
		],
	];
	$manifest=PanelContext::run([
		'asset_url_builder'=>static fn(string $asset): string=>'/panel/assets/'.$asset.'?host=1',
	], static fn(): array=>PanelRenderer::assetManifest(['table','reactor','vendor-lab','unsafe'], 'capability', $options));

	$t->same('capability', $manifest['mode']);
	$t->same(['shell','table','reactor','unsafe','vendor-lab'], $manifest['capabilities']);
	$t->same(['unsafe'], $manifest['missing_capabilities']);
	$t->same(['core','table','reactor'], $manifest['chunks']['styles']);
	$t->contains('dp_panel_caps=shell.table.reactor', $manifest['styles'][0]['url']);
	$t->contains('dp_panel_v=', $manifest['styles'][0]['url']);
	$t->same('nonce-123', $manifest['styles'][0]['attributes']['nonce']);
	$t->same('anonymous', $manifest['styles'][0]['attributes']['crossorigin']);
	$t->same('same-origin', $manifest['styles'][0]['attributes']['referrerpolicy']);
	$t->same(false, isset($manifest['styles'][0]['attributes']['onclick']));
	$t->same('vendor-lab', $manifest['styles'][1]['capability']);
	$t->same('screen', $manifest['styles'][1]['attributes']['media']);
	$t->same(false, isset($manifest['styles'][1]['attributes']['onload']));
	$t->same('reactor', $manifest['scripts'][0]['capability']);
	$t->same('panel.js', $manifest['scripts'][1]['name']);

	$tags=PanelRenderer::assetTags($manifest, 'style').PanelRenderer::assetTags($manifest, 'script');
	$t->contains('integrity="sha384-', $tags);
	$t->contains('nonce="nonce-123"', $tags);
	$t->contains('src="/reactor/reactor.js?v=1" defer', $tags);
	$t->notContains('onclick=', $tags);
	$t->notContains('onload=', $tags);
	$t->notContains('javascript:', $tags);

	$other=PanelContext::run([
		'asset_url_builder'=>static fn(string $asset): string=>'https://assets.example.test/'.$asset,
	], static fn(): array=>PanelRenderer::assetManifest(['vendor-lab','reactor','table','unsafe'], 'capability', $options));
	$t->same($manifest['id'], $other['id']);
	$t->same($manifest['content_id'], $other['content_id']);

	$full=PanelContext::run([
		'asset_url_builder'=>static fn(string $asset): string=>'/legacy/'.$asset,
	], static fn(): array=>PanelRenderer::assetManifest(['table'], 'full'));
	$t->same('full', $full['mode']);
	$t->same('/legacy/panel.css', $full['styles'][0]['url']);
	$t->same('/legacy/panel.js', $full['scripts'][0]['url']);
})->tag('panel', 'assets', 'manifest', 'csp', 'integrity', 'extensions')->maxMillis(6000);

test('asset public APIs close optional package URL and attribute edge cases', static function(Context $t): void {
	$t->same('missing', PanelRenderer::assetVersion('not-an-asset.bin'));
	$t->same('', PanelRenderer::assetIntegrity('not-an-asset.bin'));
	$t->same('', PanelContext::run([
		'asset_url_builder'=>static fn(string $asset): string=>'javascript:'.$asset,
	], static fn(): string=>PanelRenderer::assetUrl('panel.css')));
	$t->same(['shell','table'], PanelRenderer::assetCapabilityManifest(['table'])->capabilities());

	$options=[
		'integrity'=>1,
		'attributes'=>['title'=>'Panel asset'],
		'capability_urls'=>[
			'empty-url'=>'',
			'invalid-def'=>new stdClass(),
			'vendor-js'=>'/vendor.js#runtime',
			'vendor-style'=>['url'=>'/vendor.css', 'type'=>'css'],
		],
	];
	$manifest=PanelContext::run([
		'asset_url_builder'=>static fn(string $asset): string=>'/assets/'.$asset.'#release',
	], static fn(): array=>PanelRenderer::assetManifest([
		'platform', 'quality-client', 'empty-url', 'invalid-def', 'vendor-js', 'vendor-style',
	], 'capability', $options));

	$t->contains('dp_panel_v=', $manifest['styles'][0]['url']);
	$t->contains('#release', $manifest['styles'][0]['url']);
	$t->same('Panel asset', $manifest['styles'][0]['attributes']['title'] ?? null);
	$t->same(['platform'], $manifest['styles'][1]['chunks'] ?? null);
	$t->same('vendor-style', $manifest['styles'][2]['capability'] ?? null);
	$t->same(['quality-client'], $manifest['scripts'][1]['chunks'] ?? null);
	$t->same('vendor-js', $manifest['scripts'][2]['capability'] ?? null);
	$t->contains('#runtime', $manifest['scripts'][2]['url'] ?? '');
	$t->same(['empty-url','invalid-def'], $manifest['missing_capabilities']);
	$t->same(true, strlen((string)(PanelRenderer::assetContent('panel-platform.css')['content'] ?? ''))>0);
	$t->same(true, strlen((string)(PanelRenderer::assetContent('panel-quality.js')['content'] ?? ''))>0);
	$t->same(true, strlen((string)(PanelRenderer::assetContent('panel-editor-assets.js')['content'] ?? ''))>0);
	$unknownDescriptor=$t->nonPublic(PanelRenderer::class)->invoke(
		'assetDescriptor',
		'unknown.css',
		'style',
		null,
		PanelAssetCapabilityManifest::make(['shell']),
		false,
		'',
		[],
	);
	$t->same([], $unknownDescriptor['chunks'] ?? null);

	$stringIntegrity=PanelRenderer::assetManifest(['shell'], 'capability', ['integrity'=>'yes']);
	$t->contains('sha384-', $stringIntegrity['styles'][0]['attributes']['integrity'] ?? '');

	$reactorFallback=PanelRenderer::assetManifest(['reactor'], 'capability', ['capability_urls'=>[]]);
	if(in_array('reactor', $reactorFallback['missing_capabilities'], true)){
		require_once dirname(__DIR__, 2).'/reactor/Framework/Client/ReactorClientAssets.php';
		$reactorFallback=PanelRenderer::assetManifest(['reactor'], 'capability', ['capability_urls'=>[]]);
	}
	$t->same('reactor', $reactorFallback['scripts'][0]['capability'] ?? null);
	$t->contains('/dataphyre/reactor/assets/reactor.js?', $reactorFallback['scripts'][0]['url'] ?? '');
})->tag('panel', 'assets', 'manifest', 'packages', 'security')->maxMillis(6000);

test('built-in capability URLs cannot inject duplicate aggregate assets', static function(Context $t): void {
	$options=['capability_urls'=>[
		'data-surface'=>['url'=>'https://evil.example.test/data-surface.js', 'type'=>'script'],
		'widget-runtime'=>['url'=>'https://evil.example.test/widget-runtime.css', 'type'=>'style'],
		'studio-editor'=>['url'=>'https://evil.example.test/studio-editor.js', 'type'=>'script'],
		'vendor-map'=>['url'=>'/assets/vendor-map.js', 'type'=>'script'],
	]];
	$manifest=PanelRenderer::assetManifest(['data-surface','widget-runtime','studio-editor','vendor-map'], 'capability', $options);
	$descriptors=array_merge($manifest['styles'], $manifest['scripts']);
	$capabilities=array_values(array_filter(array_map(
		static fn(array $asset): ?string=>is_string($asset['capability'] ?? null) ? $asset['capability'] : null,
		$descriptors,
	)));

	$t->same(false, in_array('data-surface', $capabilities, true));
	$t->same(false, in_array('widget-runtime', $capabilities, true));
	$t->same(false, in_array('studio-editor', $capabilities, true));
	$t->same(1, count(array_filter($capabilities, static fn(string $name): bool=>$name==='vendor-map')));
	$t->contains('data-surface', $manifest['chunks']['styles']);
	$t->contains('data-surface', $manifest['chunks']['runtime']);
	$t->contains('widget-runtime', $manifest['chunks']['styles']);
	$t->contains('widget-runtime', $manifest['chunks']['runtime']);
	$t->contains('studio-editor', $manifest['chunks']['styles']);
	$t->contains('studio-editor', $manifest['chunks']['runtime']);
	$t->notContains('evil.example.test', json_encode($manifest, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
	$t->contains('/assets/vendor-map.js', json_encode($manifest, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
	$t->same([], $manifest['missing_capabilities']);
})->tag('panel', 'assets', 'manifest', 'security', 'data-surface', 'widget-runtime', 'studio')->maxMillis(6000);

test('asset controller serves immutable scoped variants and fails closed on invalid tokens', static function(Context $t): void {
	$token='shell.table';
	$request=Request::create('GET', '/panel/assets/panel.js', ['dp_panel_caps'=>$token]);
	$response=PanelAssetController::response('panel.js', $request);
	$t->same(200, $response->status);
	$t->same('capability', $response->headers['X-Dataphyre-Panel-Asset-Mode'] ?? null);
	$t->same('shell,table', $response->headers['X-Dataphyre-Panel-Capabilities'] ?? null);
	$t->same('public, max-age=31536000, immutable', $response->headers['Cache-Control'] ?? null);
	$t->same((string)strlen((string)$response->body), $response->headers['Content-Length'] ?? null);
	$t->contains('dpPanelBeginController("state_table")', (string)$response->body);
	$t->notContains('dpPanelBeginController("fields")', (string)$response->body);

	$head=PanelAssetController::response('panel.js', Request::create('HEAD', '/panel/assets/panel.js', ['dp_panel_caps'=>$token]));
	$t->same('', (string)$head->body);
	$t->same((string)strlen((string)$response->body), $head->headers['Content-Length'] ?? null);

	foreach(['table.shell','shell.shell','shell.vendor','../shell'] as $invalid){
		$rejected=PanelAssetController::response('panel.js', Request::create('GET', '/panel/assets/panel.js', ['dp_panel_caps'=>$invalid]));
		$t->same(404, $rejected->status);
		$t->same('no-store', $rejected->headers['Cache-Control'] ?? null);
	}
	$wrongAsset=PanelAssetController::response('panel-head.js', Request::create('GET', '/panel/assets/panel-head.js', ['dp_panel_caps'=>'shell']));
	$t->same(404, $wrongAsset->status);
})->tag('panel', 'assets', 'controller', 'http', 'security')->maxMillis(5000);

test('shell capability delivery supports full fallback nonces integrity and reactor discovery', static function(Context $t): void {
	$config=[
		'navigation_layout'=>'none',
		'asset_url_builder'=>static fn(string $asset): string=>'/assets/'.$asset,
		'asset_integrity'=>true,
		'asset_nonce'=>'surface-nonce',
		'asset_capability_urls'=>['reactor'=>'/reactor/reactor.js'],
	];
	$scoped=PanelContext::run($config, static fn(): PanelPageResult=>$t->nonPublic(PanelRenderer::class)->invoke(
		'page',
		'Reactive table',
		'<section class="dp-panel-table" data-dp-reactor="orders"></section>',
		['kind'=>'custom', 'navigation_state'=>[]],
	));
	$html=$scoped->content();
	$t->contains('dp_panel_caps=shell.table.reactor', $html);
	$t->contains('src="/reactor/reactor.js" defer', $html);
	$t->contains('nonce="surface-nonce"', $html);
	$t->contains('integrity="sha384-', $html);
	$t->contains('<style nonce="surface-nonce">', $html);
	$t->contains('data-dp-panel-command-state nonce="surface-nonce"', $html);
	$t->same('capability', $scoped->data()['asset_manifest']['mode'] ?? null);

	$full=PanelContext::run(array_replace($config, ['asset_mode'=>'full']), static fn(): PanelPageResult=>$t->nonPublic(PanelRenderer::class)->invoke(
		'page',
		'Legacy full',
		'<section>Content</section>',
		['kind'=>'custom', 'navigation_state'=>[]],
	));
	$t->contains('href="/assets/panel.css"', $full->content());
	$t->contains('src="/assets/panel.js"', $full->content());
	$t->notContains('dp_panel_caps=', $full->content());
	$t->same('full', $full->data()['asset_manifest']['mode'] ?? null);
})->tag('panel', 'assets', 'shell', 'csp', 'reactor', 'fallback')->maxMillis(5000);
