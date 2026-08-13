<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

test('panel assets stay inside the ratcheted architecture budgets', static function(Context $t): void {
	$css=(string)(PanelRenderer::assetContent('panel.css')['content'] ?? '');
	$javascript=(string)(PanelRenderer::assetContent('panel.js')['content'] ?? '');
	$budgets=dp_panel_asset_budgets();

	// Brick/Masonry v3 responsive item contract: 10 KB full-fallback budget.
	// The detected collection-layout capability keeps plain shell and unconfigured
	// table/navigation aggregates at their stricter pre-feature ratchets.
	// Includes the framework-owned viewport, isolated-container reflow, and
	// bounded external editor-host contracts. Capability splitting can ratchet
	// this raw bundle lower later.
	$t->lessThanOrEqual($budgets['cssBytes'], strlen($css));
	// Includes optional Widget lifecycle ownership plus the manifest-driven AJAX
	// capability handoff; scoped pages still exclude unneeded controllers and the
	// core editor seam delegates to its separately delivered browser package.
	$t->lessThanOrEqual($budgets['jsBytes'], strlen($javascript));
	$t->notContains('/**', $javascript);
	$t->contains('rule==="*"||rule==="*/*"', $javascript);
	$t->same(0, substr_count(strtolower($css), '!important'));
	$t->notContains(',}', $css);
	$t->notContains(',@media', $css);
	$t->contains('@layer dp-tokens,dp-panel,dp-accessibility;@layer dp-tokens{', $css);
	$t->contains('@layer dp-panel{', $css);
	$t->contains('@layer dp-accessibility{@media(prefers-reduced-motion:reduce){', $css);
	$t->contains('--dp-vs-control-md:44px', $css);
	$t->contains('.dp-panel-widget-action{appearance:none;display:inline-flex;align-items:center;justify-content:center;min-height:44px;', $css);
	$t->contains('.dp-panel-table .dp-panel-row-more>summary,.dp-panel-table .dp-panel-entry-copy,.dp-panel-relation .dp-panel-actions button{height:auto;min-height:var(--dp-vs-control-md)}', $css);
	$t->contains('body[data-dp-theme-effects~="brutalist"] .dp-panel-table td.dp-panel-actions .dp-panel-row-more>summary{min-height:44px;border-width:2px;font-weight:950;}', $css);
	$t->contains('.dp-panel-table td.dp-panel-actions .dp-panel-row-link,.dp-panel-table td.dp-panel-actions .dp-panel-action,.dp-panel-table td.dp-panel-actions .dp-panel-row-more>summary{min-height:44px;height:auto;', $css);
	$t->contains('.dp-panel .dp-panel-table td.dp-panel-select{position:absolute;inset:auto;inset-block-start:10px;inset-inline-end:10px;width:max-content;min-width:0;max-width:44px;', $css);
	$t->contains('.dp-panel .dp-panel-table td.dp-panel-select+td{padding-inline-end:52px}', $css);
	$t->contains('.dp-panel .dp-panel-table td.dp-panel-select+td>*{margin-inline-end:10px}', $css);
	$t->contains('.dp-panel .dp-panel-table td.dp-panel-actions{display:flex;gap:7px;flex-wrap:wrap}', $css);
	$t->contains('.dp-panel .dp-panel-table td.dp-panel-actions>*{flex:1 1 82px;min-width:82px;margin:0}', $css);
	$t->contains('.dp-panel .dp-panel-table td.dp-panel-actions>:is(.dp-panel-row-more,.dp-panel-action-group){display:grid;grid-template-columns:minmax(0,1fr);justify-items:stretch}', $css);
	$t->contains('.dp-panel .dp-panel-table td.dp-panel-actions>:is(.dp-panel-row-more,.dp-panel-action-group)[open]{flex-basis:100%}', $css);
	$t->contains('.dp-panel[data-dp-panel-kind="board"] .dp-panel-board-card .dp-panel-actions .dp-panel-action,.dp-panel[data-dp-panel-kind="board"] .dp-panel-board-card .dp-panel-actions .dp-panel-row-link{min-height:44px;', $css);
	$t->contains('body[data-dp-theme-mode] .dp-panel-tabs>.dp-panel-tab-panel,body[data-dp-theme-mode] .dp-panel-steps>.dp-panel-step-panel{border:0;border-radius:0;background:transparent;box-shadow:none;padding:0}', $css);
	$t->contains('.dp-panel-commandbar-top:has(.dp-panel-action-group[open]){z-index:2}', $css);
	$t->contains('@media(max-width:1024px){body .dp-panel .dp-panel-commandbar-actions>.dp-panel-column-picker[open]{display:grid;grid-column:1/-1}.dp-panel-column-picker[open]>form{min-width:0}}', $css);
	$t->contains('body[data-dp-theme-mode="system"] .dp-panel-modal-expand{color:#dbeafe}', $css);
	$t->notContains('.dp-panel[data-dp-panel-kind="board"] .dp-panel-board-card .dp-panel-row-more-floating .dp-panel-row-more-menu{position:absolute', $css);
	$t->contains('if(other!==details&&!other.contains(details)&&!details.contains(other)){', $javascript);
	$t->contains('if(except&&(details===except||details.contains(except)||except.contains(details))){return;}', $javascript);
	$t->contains('if(!details.open){menu.querySelectorAll(".dp-panel-action-group[open]")', $javascript);
	$t->contains('function dpPanelSyncPayloadAssets(payload,type){', $javascript);
	$t->contains('return dpPanelSyncPayloadAssets(payload,"style").then(function(){return payload;});', $javascript);
	$t->contains('return dpPanelSyncPayloadAssets(payload,"script").then(function(){', $javascript);
	$t->contains('if(typeof dpPanelCloseTransientPanels==="function"){dpPanelCloseTransientPanels(null);}', $javascript);
	$t->contains('region.toggleAttribute("inert",open)', $javascript);
	$t->contains('unsavedRoot&&!unsavedRoot.hidden&&dpPanelTrapFocus(unsavedRoot,event)', $javascript);
	$t->contains('id=\"dp-panel-modal-title\" dir=\"auto\"', $javascript);
	$t->contains('class=\"dp-panel-modal-confirmation-copy\"><strong dir=\"auto\"', $javascript);
	$t->contains('status.dir="auto"', $javascript);
	$t->contains('var fitStaticMenu=function(){', $javascript);
	$t->contains('menu.style.maxHeight=Math.max(120,viewportHeight-staticRect.top-12)+"px";', $javascript);
	$t->contains('@media(max-width:639px){body :is(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-display]{--dp-collection-columns-active:1}', $css);
	$t->contains('.dp-panel-record-actions>.dp-panel-record-action-overflow{display:grid;grid-template-columns:minmax(0,1fr);align-items:stretch}', $css);
	$t->contains('.dp-panel-show-field-copyable{grid-template-columns:minmax(0,1fr) auto;column-gap:12px}', $css);
	$t->contains('.dp-panel-show-field-copyable>.dp-panel-entry-copy{grid-column:2;grid-row:1/span 2;align-self:start;justify-self:end}', $css);
	$t->notContains('.dp-panel-show-field-copyable:after', $css);
	$t->contains('@container dp-record-heading (max-width:540px){.dp-panel-record-actions{display:grid;grid-template-columns:minmax(0,1fr);', $css);
	$t->contains('.dp-panel[data-dp-panel-kind="board"] .dp-panel-row-more-menu .dp-panel-action-menu{width:100%;min-width:0;max-width:100%}', $css);
	$t->notContains('body[data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action,body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action,', $css);
	$t->contains('body[data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action-success,', $css);
	$t->contains('background:var(--dp-success-800,#085d3a);', $css);
	$t->contains('body[data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action-info,', $css);
	$t->contains('background:var(--dp-info-800,#065986);', $css);
	$t->contains('.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar{background-clip:padding-box}', $css);
	$t->notContains('.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar{background-clip:padding-box;contain:paint}', $css);
})->tag('panel', 'assets', 'architecture', 'budget')->maxMillis(4000);

test('panel stylesheet sources never depend on runtime cascade rewriting', static function(Context $t): void {
	$rendering=dirname(__DIR__).'/Framework/Rendering';
	$files=glob($rendering.'/Assets/PanelRendererAssets*Css.php') ?: [];
	$files[]=$rendering.'/PanelRendererAssets.php';
	$violations=[];
	foreach($files as $file){
		$source=(string)file_get_contents($file);
		if(str_contains(strtolower($source), '!important') || str_contains($source, 'str_ireplace')){
			$violations[]=basename($file);
		}
	}

	$t->same([], $violations);
	$t->same(true, count($files)>=11);
})->tag('panel', 'assets', 'architecture', 'source')->maxMillis(2000);

test('visual invariants cover generated modal surfaces and system dark controls', static function(Context $t): void {
	$css=(string)(PanelRenderer::assetContent('panel.css')['content'] ?? '');

	$t->contains('.dp-panel .dp-panel-search,.dp-panel-modal-root .dp-panel-search{display:grid;', $css);
	$t->contains('body :is(.dp-panel,.dp-panel-modal-root) :is(.dp-panel-density,.dp-panel-table-views,.dp-panel-table-groups):not([data-dp-display]){display:flex;', $css);
	$t->contains('.dp-panel-form,.dp-panel-field{grid-template-columns:minmax(0,1fr)}', $css);
	$t->contains(':is(.dp-panel,.dp-panel-modal-root) [data-dp-display="brick"]>:is(a,button,form,details,.dp-panel-inline-action){width:100%;min-width:0;margin:0}', $css);
	$t->contains(':where(.dp-panel,.dp-panel-modal-root) :is([data-dp-display="brick"],[data-dp-fit="fill"],[data-dp-display="masonry"][data-dp-masonry="rows"]) :is(.dp-panel-action,.dp-panel-button){width:100%;', $css);
	$t->contains(':is(.dp-panel-notice,.dp-panel-alert)>:is(a,button){grid-area:action;justify-self:end;display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;', $css);
	$t->contains(':is(.dp-panel,.dp-panel-modal-root) .dp-panel-table tr.dp-panel-empty-row>td.dp-panel-empty{display:table-cell;', $css);
	$t->contains(':is(.dp-panel,.dp-panel-modal-root) :is(.dp-panel-checkbox input[type="checkbox"],.dp-panel-field>input[type="checkbox"]){appearance:none;', $css);
	$t->contains('body[data-dp-theme-mode="system"] :where(.dp-panel,.dp-panel-modal-root) select option:checked{background:#2459b8;color:#fff}', $css);
	$t->contains(':is([data-dp-display="brick"],[data-dp-fit="fill"],[data-dp-display="masonry"][data-dp-masonry="rows"])>:is(.dp-panel-action-group,.dp-panel-column-picker)>summary{width:100%;height:100%;min-height:52px;', $css);
	$t->contains(':is(.dp-panel-form,.dp-panel-show){container:dp-form/inline-size}', $css);
	$t->contains(':is(.dp-panel,.dp-panel-modal-root) .dp-panel-choice>input{flex:0 0 20px;width:20px;height:20px;min-width:20px;min-height:20px;margin:0;padding:0}', $css);
	$t->contains('@container dp-form (max-width:760px){.dp-panel-form-grid{grid-template-columns:minmax(0,1fr)}.dp-panel-field{width:100%;container:dp-field/inline-size}', $css);
	$t->contains('.dp-panel-grid-item-auto{grid-column:auto/span min(var(--dp-grid-cols-active),var(--dp-grid-auto-span-active))}', $css);
	$t->contains('@container dp-form (min-width:761px) and (max-width:1040px){.dp-panel-form-grid{--dp-grid-cols-active:var(--dp-grid-cols-md,var(--dp-grid-cols-sm,var(--dp-grid-cols,1)));', $css);
	$t->contains('grid-template-columns:repeat(var(--dp-grid-cols-active),minmax(0,1fr))}:is(.dp-panel-field,.dp-panel-show-field):not(.dp-panel-grid-item-auto){grid-column:var(--dp-grid-column-md,', $css);
	$t->notContains('calc(var(--dp-grid-auto-span-active) + 1)', $css);
	$t->contains('.dp-panel-switch-track{display:block;flex:0 0 42px;width:42px;height:24px;', $css);
	$t->contains('.dp-panel-switch:has(>input[type="checkbox"]:checked) .dp-panel-switch-track>span{background:#fff;transform:translateX(18px)}', $css);
	$t->contains('@container dp-field (max-width:360px){.dp-panel-input-shell{flex-wrap:wrap}.dp-panel-input-control{flex-basis:0;min-width:44px}', $css);
	$t->contains('.dp-panel-main-region,.dp-panel-modal-body{container-name:dp-panel-layout;container-type:inline-size}', $css);
	$t->contains('@container dp-panel-layout (max-width:1023px){body :is(.dp-panel,.dp-panel-modal-root) [data-dp-display]{--dp-collection-basis-active:', $css);
	$t->contains('@container dp-panel-layout (max-width:400px){body :is(.dp-panel,.dp-panel-modal-root) [data-dp-display]{--dp-collection-basis-active:100%;--dp-collection-columns-active:1}', $css);
	$t->contains('.dp-panel{container-name:dp-panel-shell;container-type:inline-size}', $css);
	$t->contains('@container dp-panel-shell (max-width:1180px){.dp-panel[data-dp-panel-navigation-layout="sidebar"]>.dp-panel-main-region{grid-column:1/-1;inline-size:100cqi;', $css);
	$t->contains('.dp-panel[data-dp-panel-kind="board"] .dp-panel-board{grid-template-columns:minmax(0,1fr)}', $css);
	$t->contains('.dp-panel[data-dp-panel-kind="dashboard"] .dp-panel-nav-group .dp-panel-grid{grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:10px}', $css);
	$t->contains('.dp-panel .dp-panel-relation .dp-panel-relation-aside{width:100%;min-width:0;max-width:100%;justify-items:start}', $css);
	$t->contains('.dp-panel .dp-panel-relation .dp-panel-relation-meta{width:100%;min-width:0;max-width:100%;justify-content:flex-start}', $css);
	$t->contains('@container dp-panel-shell (max-width:640px){.dp-panel[data-dp-panel-navigation-layout="sidebar"]>.dp-panel-main-region{padding:12px}', $css);
	$t->contains('@container dp-panel-shell (max-width:400px){.dp-panel :is(.dp-panel-search,.dp-panel-global-search,', $css);
	$t->contains('.dp-panel :is(.dp-panel-search,.dp-panel-global-search)>:is(input[type="search"],button[type="submit"],a.dp-panel-button){grid-column:1;width:100%;min-width:0;', $css);
	$t->contains('body .dp-panel[data-dp-panel-kind] .dp-panel-custom-page{grid-template-columns:minmax(0,1fr);width:100%;min-width:0;max-width:100%;gap:6px}', $css);
	$t->contains('body .dp-panel[data-dp-panel-kind] :is(.dp-panel-commandbar-top,.dp-panel-commandbar-bottom,.dp-panel-commandbar-primary,.dp-panel-commandbar-view,.dp-panel-commandbar-utility,.dp-panel-commandbar-actions){display:grid;grid-template-columns:minmax(0,1fr);', $css);
	$t->contains('body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar-view .dp-panel-per-page,body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar-view .dp-panel-per-page label{display:grid;grid-template-columns:minmax(0,1fr);', $css);
	$t->contains('body .dp-panel[data-dp-panel-kind] .dp-panel-table-group-row button{grid-template-columns:minmax(0,1fr);width:100%;min-width:0;max-width:100%;', $css);
	$t->contains('body .dp-panel[data-dp-panel-kind] .dp-panel-show-field-copyable{grid-template-columns:minmax(0,1fr);column-gap:0;row-gap:6px}', $css);
	$t->contains('body .dp-panel[data-dp-panel-kind] .dp-panel-field:has(>input[type="checkbox"]){gap:6px;padding:6px}', $css);
	$t->contains('.dp-panel-input-adornments-append{display:flex;flex:1 0 100%;border-top:1px solid var(--dp-border_soft)}', $css);
	$t->contains('[data-dp-display]{--dp-collection-columns-active:1;--dp-collection-basis-active:100%}', $css);
	$t->notContains('.dp-panel .dp-panel-field-boolean input[type="checkbox"]', $css);
})->tag('panel', 'assets', 'visual-system', 'modal', 'dark', 'responsive')->maxMillis(4000);

test('the executable asset auditor gates bundle size duplication specificity and listener growth', static function(Context $t): void {
	$auditor=(string)file_get_contents(dirname(__DIR__).'/testing/panel_asset_architecture_audit.js');
	$budgets=dp_panel_asset_budgets();

	$t->contains("require('./panel_asset_budgets.json')", $auditor);
	$t->contains("require('./panel_release_contract.json')", $auditor);
	$t->contains("report.id==='interaction'", $auditor);
	$t->same([
		// The compatibility bundle pays the exact first-party Data Surface,
		// Studio, and scoped collaboration-workspace costs; scoped unrelated
		// shells do not.
		'cssBytes'=>954330,
		'jsBytes'=>656280,
		'importantDeclarations'=>0,
		'duplicateSelectorOccurrences'=>2021,
		'exactDuplicateRules'=>0,
		'maxSpecificity'=>90,
		'chainedPanelRoots'=>37,
		// Data Surface and Studio lifecycle listeners stay abortable; dynamic discovery remains consolidated.
		'managedGlobalListeners'=>68,
		'unmanagedGlobalListeners'=>0,
		'globalFunctionDeclarations'=>0,
	], $budgets);
	$t->contains("checks.push({name:'cascadeLayers'", $auditor);
	$t->contains("checks.push({name:'cascadeLayerOrder'", $auditor);
	$t->contains("'visual-system','brick-v2'", $auditor);
	$t->contains('documentListeners', $auditor);
})->tag('panel', 'assets', 'architecture', 'audit')->maxMillis(1000);

test('browser runtime controllers are bounded abortable and own every global listener', static function(Context $t): void {
	$rendering=dirname(__DIR__).'/Framework/Rendering';
	$modules=glob($rendering.'/Assets/PanelRendererAssets*RuntimeScripts.php') ?: [];
	$modules[]=$rendering.'/Assets/PanelRendererAssetsScripts.php';
	$modules[]=$rendering.'/Assets/PanelRendererAssetsRuntimeKernelScripts.php';
	$oversized=[];
	foreach($modules as $module){
		if(filesize($module)>102000){
			$oversized[]=basename($module);
		}
	}
	$javascript=(string)(PanelRenderer::assetContent('panel.js')['content'] ?? '');
	$unmanaged=[];
	foreach(glob($rendering.'/*.php') ?: [] as $file){
		if(str_contains((string)file_get_contents($file), 'document.addEventListener(')){
			$unmanaged[]=basename($file);
		}
	}
	foreach(glob($rendering.'/Assets/PanelRendererAssets*.php') ?: [] as $file){
		if(str_contains((string)file_get_contents($file), 'document.addEventListener(')){
			$unmanaged[]=basename($file);
		}
	}

	$moduleNames=array_map('basename', $modules);
	sort($moduleNames, SORT_STRING);
	$t->same([
		'PanelRendererAssetsAccessibilityRuntimeScripts.php',
		'PanelRendererAssetsAjaxRuntimeScripts.php',
		'PanelRendererAssetsAssetHandoffRuntimeScripts.php',
		'PanelRendererAssetsCommandRuntimeScripts.php',
		'PanelRendererAssetsDataSurfaceRuntimeScripts.php',
		'PanelRendererAssetsEditorRuntimeScripts.php',
		'PanelRendererAssetsFieldRuntimeScripts.php',
		'PanelRendererAssetsNavigationRuntimeScripts.php',
		'PanelRendererAssetsRuntimeKernelScripts.php',
		'PanelRendererAssetsScripts.php',
		'PanelRendererAssetsStateTableRuntimeScripts.php',
		'PanelRendererAssetsValidationUploadRuntimeScripts.php',
		'PanelRendererAssetsWidgetRuntimeScripts.php',
	], $moduleNames);
	$t->same([], $oversized);
	$t->same([], array_values(array_unique($unmanaged)));
	$t->contains('runtimeController', $javascript);
	$t->contains('new AbortController()', $javascript);
	$t->contains('dpPanelListen(document,', $javascript);
	$t->contains('dpPanelBeginController("validation_upload")', $javascript);
	$t->contains('dpPanelBeginController("accessibility")', $javascript);
	$t->contains('dpPanelBeginController("data_surface")', $javascript);
	$t->contains('dpPanelBeginController("widget_runtime")', $javascript);
	$t->contains('(function(window,document){', $javascript);
	$t->contains('var observerSelector=".dp-panel,.dp-panel-modal-root,.dp-panel-main-region,.dp-panel-relation,', $javascript);
	$t->contains('function dpPanelA11yRefreshTableOptimizations(root)', $javascript);
	$t->contains('if(preserveAdaptive){dpPanelA11yRefreshTableOptimizations(root);}', $javascript);
})->tag('panel', 'assets', 'runtime', 'controllers', 'lifecycle')->maxMillis(4000);

test('modal runtime preserves nested state and owns failure recovery across reloads', static function(Context $t): void {
	$javascript=(string)(PanelRenderer::assetContent('panel.js')['content'] ?? '');

	$t->contains('window.DataphyrePanel.modalState=dpPanelModalState', $javascript);
	$t->contains('dpPanelReleaseOrphanedModalUi(dpPanelExistingModalRoot)', $javascript);
	$t->contains('dpPanelModalValidationDirty', $javascript);
	$t->contains('dpPanelModalStructuralDirty', $javascript);
	$t->contains('function dpPanelHandleModalPopstate()', $javascript);
	$t->contains('dpPanelRequestBackModal(false,true)', $javascript);
	$t->contains('dpPanelModalHistoryDrop(historyDepth)', $javascript);
	$t->contains('typeof dpPanelHandleModalPopstate==="function"&&dpPanelHandleModalPopstate()', $javascript);
	$t->contains('dpPanelModalDefaultControlValue(control)', $javascript);
	$t->contains('control.tagName==="SELECT"&&control.multiple', $javascript);
	$t->contains('dpPanelResetModalDirtyState(activeRoot)', $javascript);
	$t->contains('dpPanelBeginModalRequest({method:method,kind:"ajax-form"})', $javascript);
	$t->contains('dpPanelModalRequestIsCurrent(request)', $javascript);
	$t->contains('root.dataset.dpPanelModalStatus==="working"', $javascript);
	$t->contains('modalRoot&&!modalRoot.hidden', $javascript);
	$t->contains('dpPanelScopeCommandPalette(root)', $javascript);
	$t->contains('dialog.setAttribute("inert","")', $javascript);
	$t->contains('category:"Modal command"', $javascript);
})->tag('panel', 'assets', 'runtime', 'modal', 'lifecycle')->maxMillis(4000);

test('capability manifests ratchet delivery below the legacy bundle without changing full fallback bytes', static function(Context $t): void {
	$legacyCss=(string)(PanelRenderer::assetContent('panel.css')['content'] ?? '');
	$legacyJs=(string)(PanelRenderer::assetContent('panel.js')['content'] ?? '');
	$coreCss=(string)(PanelRenderer::assetContent('panel.css', ['shell'])['content'] ?? '');
	$coreJs=(string)(PanelRenderer::assetContent('panel.js', ['shell'])['content'] ?? '');
	$tableNavigationCss=(string)(PanelRenderer::assetContent('panel.css', ['table','navigation'])['content'] ?? '');
	$tableNavigationJs=(string)(PanelRenderer::assetContent('panel.js', ['table','navigation'])['content'] ?? '');

	$t->same($legacyCss, (string)(PanelRenderer::assetContent('panel.css', '*')['content'] ?? ''));
	$t->same($legacyJs, (string)(PanelRenderer::assetContent('panel.js', '*')['content'] ?? ''));
	$t->lessThanOrEqual(625000, strlen($coreCss));
	// Shell owns the small manifest handoff so AJAX surfaces can activate a
	// destination bundle atomically instead of exposing unstyled controls.
	$t->lessThanOrEqual(387208, strlen($coreJs));
	// Table delivery closes over the historical mixed table-view/summary
	// foundation so an empty or summarized table never loses base layout rules.
	// Container-aware collection breakpoints remain in table/navigation delivery
	// because narrow embedded tables and slide-overs must not inherit viewport XL rules.
	// Responsive overflow containment adds table/navigation-owned geometry but
	// the scoped payload must still save at least 100 KB over the compatibility
	// bundle. Ratchet the measured container-safe payload without speculative
	// growth headroom so later additions must justify every extra byte.
	$t->lessThanOrEqual(816930, strlen($tableNavigationCss));
	$t->greaterThanOrEqual(100000, strlen($legacyCss)-strlen($tableNavigationCss));
	$t->lessThanOrEqual(387208, strlen($tableNavigationJs));
	$t->same(1, substr_count($coreJs, 'dpPanelBeginController("state_table")'));
	$t->same(1, substr_count($tableNavigationJs, 'dpPanelBeginController("state_table")'));
	$t->same(1, substr_count($tableNavigationJs, 'dpPanelBeginController("navigation")'));

	$rendering=dirname(__DIR__).'/Framework/Rendering';
	$assetSource=(string)file_get_contents($rendering.'/PanelRendererAssets.php');
	$shellSource=(string)file_get_contents($rendering.'/PanelRendererShell.php');
	$controllerSource=(string)file_get_contents(dirname(__DIR__).'/Framework/Http/PanelAssetController.php');
	$t->contains('PanelAssetCapabilityManifest::make', $assetSource);
	$t->contains("PanelConfig::config('asset_mode', 'capability')", $shellSource);
	$t->contains('PanelAssetCapabilityManifest::decodeToken', $controllerSource);
	$t->notContains('eval(', $coreJs);
	$t->notContains('new Function(', $coreJs);
})->tag('panel', 'assets', 'architecture', 'capabilities', 'budget')->maxMillis(6000);
