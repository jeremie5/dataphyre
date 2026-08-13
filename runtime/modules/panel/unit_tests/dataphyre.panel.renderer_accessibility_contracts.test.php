<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelAccessibilityAudit;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

dataset('panel accessibility outcome contracts', [
	'empty surface warns but passes'=>['<main></main>', true, null],
	'named text button'=>['<button>Save</button>', true, null],
	'aria labelled button'=>['<button aria-label="Save"></button>', true, null],
	'title labelled button'=>['<button title="Save"></button>', true, null],
	'unnamed button'=>['<button><svg></svg></button>', false, 'button_name'],
	'decorative image'=>['<img src="shape.svg" aria-hidden="true">', true, null],
	'empty alt image'=>['<img src="shape.svg" alt="">', true, null],
	'missing image alt'=>['<img src="photo.jpg">', false, 'image_alt'],
	'placeholder input'=>['<input name="q" placeholder="Search">', true, null],
	'aria labelled input'=>['<input name="q" aria-label="Search">', true, null],
	'for labelled input'=>['<label for="q">Search</label><input id="q" name="q">', true, null],
	'unlabelled input'=>['<input name="q">', false, 'input_label'],
	'duplicate ids'=>['<div id="same"></div><span id="same"></span>', false, 'duplicate_id'],
	'valid aria reference'=>['<h2 id="title">Dialog</h2><section aria-labelledby="title"></section>', true, null],
	'missing aria reference'=>['<section aria-labelledby="missing"></section>', false, 'aria_reference'],
	'labelled dialog warning only'=>['<section role="dialog" aria-modal="true" aria-label="Details"></section>', true, null],
	'live region valid'=>['<div aria-live="polite"></div>', true, null],
	'live region invalid warning only'=>['<div aria-live="loud"></div>', true, null],
	'reduced motion hook'=>['<style>@media (prefers-reduced-motion:reduce){*{animation:none}}</style>', true, null],
]);

test('renderer accessibility audits catch blocking markup defects without promoting warnings', static function(Context $t, string $html, bool $passed, ?string $errorRule): void {
	$audit=PanelAccessibilityAudit::from($html, ['surface'=>'contract']);
	$t->same($passed, $audit->passed());
	$t->same('contract', $audit->toArray()['meta']['surface'] ?? null);
	$rules=array_column($audit->issues('error'), 'rule');
	if($errorRule!==null){
		$t->contains($errorRule, $rules);
	}
	else {
		$t->same([], $rules);
	}
})->with('panel accessibility outcome contracts')->tag('panel', 'renderer', 'accessibility')->maxMillis(1000);

dataset('panel accessibility metric contracts', [
	'buttons'=>['<button>One</button><button>Two</button>', 'buttons', 2],
	'links'=>['<a href="/one">One</a><a href="/two">Two</a>', 'links', 2],
	'images'=>['<img alt="" src="one"><img alt="Two" src="two">', 'images', 2],
	'inputs'=>['<input aria-label="One"><select aria-label="Two"></select><textarea aria-label="Three"></textarea>', 'inputs', 3],
	'dialogs'=>['<div role="dialog" aria-label="One"></div>', 'dialogs', 1],
	'aria references'=>['<div id="x"></div><span aria-describedby="x"></span>', 'aria_references', 1],
	'live regions'=>['<div aria-live="polite"></div>', 'live_regions', 1],
	'duplicate ids'=>['<i id="x"></i><b id="x"></b>', 'duplicate_ids', 1],
	'reduced motion'=>['<style>@media(prefers-reduced-motion:reduce){}</style>', 'reduced_motion_hooks', 1],
]);

test('renderer accessibility audits publish deterministic structural metrics', static function(Context $t, string $html, string $metric, int $expected): void {
	$audit=PanelAccessibilityAudit::from($html);
	$t->same($expected, $audit->metrics()[$metric] ?? null);
	$t->same($audit->toArray(), $audit->jsonSerialize());
})->with('panel accessibility metric contracts')->tag('panel', 'renderer', 'accessibility', 'metrics')->maxMillis(1000);

dataset('panel renderer asset contracts', [
	'css type'=>['panel.css', 'type', 'text/css; charset=UTF-8'],
	'css marker'=>['panel.css', 'contains', '.dp-panel'],
	'css reduced motion'=>['panel.css', 'contains', 'prefers-reduced-motion'],
	'css responsive breakpoint'=>['panel.css', 'contains', '@media'],
	'css dark native select scheme'=>['panel.css', 'contains', 'select{color-scheme:dark}'],
	'css dark native option surface'=>['panel.css', 'contains', 'select option,body[data-dp-theme-mode="dark"] select optgroup{background:#0f1724;color:#f8fafc}'],
	'css drawer close affordance'=>['panel.css', 'contains', '.dp-panel-mobile-nav-dismiss{display:none}'],
	'css drawer composition owner'=>['panel.css', 'contains', 'grid-auto-rows:max-content'],
	'css safe feedback scrolling'=>['panel.css', 'contains', 'scroll-padding-block:96px 120px'],
	'css workspace search logical icon offset'=>['panel.css', 'contains', '.dp-panel-global-search{position:relative;--dp-global-search-icon-start:17px}'],
	'css workspace search visible icon stack'=>['panel.css', 'contains', 'z-index:1;width:14px;height:14px;border:2px solid var(--dp-text_soft,var(--dp-text_muted,#98a2b3))'],
	'css workspace search bounded logical padding'=>['panel.css', 'contains', '.dp-panel-global-search input{padding-inline:46px 44px}'],
	'css table summary hierarchy'=>['panel.css', 'contains', '.dp-panel[data-dp-panel-kind="index"] .dp-panel-summary{position:relative;display:grid;grid-auto-flow:row;'],
	'css rounded form accent inset'=>['panel.css', 'contains', '.dp-panel-form-section:before{content:"";position:absolute;inset:14px auto 14px 0;width:4px;border-radius:999px;'],
	'css rounded commandbar accent inset'=>['panel.css', 'contains', '.dp-panel-commandbar:before{content:"";position:absolute;inset:0 14px auto;height:3px;border-radius:999px;'],
	'css wrapped row action menu'=>['panel.css', 'contains', '.dp-panel-row-more-menu>section{display:flex;flex-wrap:wrap;gap:5px;min-width:0;max-width:100%}'],
	'css open table row shadow isolation'=>['panel.css', 'contains', '.dp-panel-table tbody tr:has(.dp-panel-row-more[open]),.dp-panel-table tbody tr:has(.dp-panel-action-group[open]){box-shadow:none;transition:none}'],
	'css theme-safe selected table row'=>['panel.css', 'contains', 'body :is(.dp-panel,.dp-panel-modal-root) .dp-panel-table tbody :is(.dp-panel-row-selected,.dp-panel-row-selected>td){background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 16%,var(--dp-surface))}'],
	'css developer diagnostic popup stays hidden'=>['panel.css', 'contains', '.dp-panel-a11y-dev-popup[hidden]{display:none}'],
	'javascript type'=>['panel.js', 'type', 'application/javascript; charset=UTF-8'],
	'javascript modal runtime'=>['panel.js', 'contains', 'dp-panel-modal'],
	'javascript strict closure'=>['panel.js', 'contains', '(function'],
	'javascript preserves open drawer during refresh'=>['panel.js', 'contains', 'preserveMobileNavigation'],
	'javascript in-place actions preserve viewport'=>['panel.js', 'contains', "preserveScroll:true,\nform:form"],
	'javascript developer diagnostics inherit normalized controls'=>['panel.js', 'contains', 'dp-panel-a11y-dev-badge dp-panel-row-link'],
	'javascript optimized tables fill wider wrappers'=>['panel.js', 'contains', 'table.style.setProperty("width","100%","important")'],
	'head type'=>['panel-head.js', 'type', 'application/javascript; charset=UTF-8'],
	'head local storage'=>['panel-head.js', 'contains', 'localStorage'],
	'head theme dataset'=>['panel-head.js', 'contains', 'dpThemeMode'],
	'uppercase normalization'=>['PANEL.CSS', 'type', 'text/css; charset=UTF-8'],
	'windows traversal basename'=>['..\\panel.css', 'type', 'text/css; charset=UTF-8'],
	'posix traversal basename'=>['../panel.js', 'type', 'application/javascript; charset=UTF-8'],
]);

test('renderer bundles expose stable content types and visual runtime hooks', static function(Context $t, string $asset, string $assertion, string $expected): void {
	$content=PanelRenderer::assetContent($asset);
	$t->same(true, is_array($content));
	if($assertion==='type'){
		$t->same($expected, $content['content_type'] ?? null);
	}
	else {
		$t->contains($expected, (string)($content['content'] ?? ''));
	}
	$t->same(1, preg_match('/^[a-f0-9]{16}$/', PanelRenderer::assetVersion($asset)));
})->with('panel renderer asset contracts')->tag('panel', 'renderer', 'asset')->maxMillis(2000);

dataset('panel renderer rejected asset contracts', [
	'empty'=>'',
	'unknown'=>'theme.css',
	'php'=>'panel.php',
	'query suffix'=>'panel.css?x=1',
	'null byte'=>"panel.css\0.js",
	'directory only'=>'../',
	'lookalike'=>'panel.css.js',
	'head lookalike'=>'panel-head.js.css',
]);

test('renderer asset registry rejects every unsupported public filename', static function(Context $t, string $asset): void {
	$t->same(null, PanelRenderer::assetContent($asset));
	$t->same('missing', PanelRenderer::assetVersion($asset));
})->with('panel renderer rejected asset contracts')->tag('panel', 'renderer', 'asset', 'failure')->maxMillis(1000);

test('the canonical visual system owns the renderer cascade without important escalation', static function(Context $t): void {
	$css=PanelRenderer::assetContent('panel.css')['content'] ?? '';
	$t->contains('dp-owner:visual-system', $css);
	$t->contains('--dp-vs-control-md:44px', $css);
	$t->same(0, substr_count(strtolower($css), '!important'));
})->tag('panel', 'renderer', 'visual-system', 'cascade')->maxMillis(3000);

test('the shell provides deterministic favicon and mobile drawer close contracts', static function(Context $t): void {
	$shell=(string)file_get_contents(dirname(__DIR__).'/Framework/Rendering/PanelRendererShell.php');
	$t->contains('<link rel="icon" href="data:,">', $shell);
	$t->contains('class="dp-panel-mobile-nav-dismiss"', $shell);
	$t->contains('data-dp-panel-mobile-nav-backdrop', $shell);
})->tag('panel', 'renderer', 'shell', 'navigation', 'regression')->maxMillis(1000);

test('browser regression gates cover long pages accessibility and keyboard interactions', static function(Context $t): void {
	$visual=(string)file_get_contents(dirname(__DIR__).'/testing/panel_visual_regression.js');
	$interaction=(string)file_get_contents(dirname(__DIR__).'/testing/panel_interaction_regression.js');
	$t->contains('selectedScrollSamples', $visual);
	$t->contains("searchParams.set('panel_theme',args.theme)", $visual);
	$t->contains("['top','middle','bottom']", $visual);
	$t->contains('orders_empty', $visual);
	$t->contains('orders_board', $visual);
	$t->contains('sellers_create', $visual);
	$t->contains('state_loading_error', $visual);
	$t->contains('state_validation_disabled', $visual);
	$t->contains('state_dense_long', $visual);
	$t->contains('state_relation_upload', $visual);
	$t->contains('state_modal', $visual);
	$t->contains('orders_filter_modal', $visual);
	$t->contains('context=await browser.createBrowserContext()', $visual);
	$t->contains('page=await context.newPage()', $visual);
	$t->contains('await context.close()', $visual);
	$t->notContains('page=await browser.newPage()', $visual);
	$t->contains("scope:'browser_context_per_scenario_environment'", $visual);
	$t->contains("cookies:'isolated'", $visual);
	$t->contains("storage:'isolated'", $visual);
	$t->contains("document.readyState==='complete'", $visual);
	$t->contains('Filter modal did not open after its runtime became ready. Diagnostics:', $visual);
	$t->contains('mobile_drawer', $visual);
	$t->contains('settlePage', $visual);
	$t->contains("await settlePage(page);\n\t\t\t\tawait prepareScenario(page,scenario);", $visual);
	$t->contains('triggerHitTarget:', $visual);
	$t->contains('undersizedTargets', $visual);
	$t->contains('overflowSources', $visual);
	$t->contains('overflowElements.filter(visible).map(element=>', $visual);
	$t->contains('data-dp-panel-overflow-policy="scroll-x"', $visual);
	$t->contains('blockingOverflowSources', $visual);
	$t->contains('allowedOverflowSources', $visual);
	$t->contains('overflowSummary:{visible:', $visual);
	$t->contains("policies.push('positioned-child')", $visual);
	$t->contains("policies.push('native-value-scroll')", $visual);
	$t->contains("element.matches('input:not([type=\"hidden\"]),select,textarea')", $visual);
	$t->contains('visible internal overflow sources require an explicit policy.', $visual);
	$t->contains('mobile drawer opens', $interaction);
	$t->contains('dark native selects keep a dark popup color contract', $interaction);
	$t->contains('Workspace search icon and label are not optically aligned in both directions', $interaction);
	$t->contains('in-place actions preserve the viewport around their result', $interaction);
	$t->contains("page.keyboard.press('Tab')", $interaction);
	$t->contains('required form validation', $interaction);
	$t->contains('record actions keep a bounded primary set and keyboard-operable overflow', $interaction);
	$t->contains('copyable show entries reserve a collision-free logical action column', $interaction);
	$t->contains('Summary card hierarchy collapsed', $interaction);
	$t->contains('form and infolist grids retain real tracks at intermediate container widths', $interaction);
	$t->contains('Embedded relation table retained stale desktop inline sizing.', $interaction);
	$t->contains('feature showcase renders', $interaction);
	$t->contains('runtime controllers replace global listeners without duplication', $interaction);
	$t->contains('board action labels inherit readable control colors across shipped themes', $interaction);
	$t->contains('browser.createBrowserContext()', $interaction);
	$t->contains("document.readyState==='complete'", $interaction);
	$t->contains("style.pointerEvents!=='none'", $interaction);
	$t->contains('const editRequestPromise=page.waitForRequest', $interaction);
	$t->contains('Hidden modal DOM is retained to preserve live parent history', $interaction);
})->tag('panel', 'renderer', 'visual-regression', 'browser', 'accessibility')->maxMillis(1000);

test('responsive navigation and modal decorations remain inside measured geometry', static function(Context $t): void {
	$visual=(string)file_get_contents(dirname(__DIR__).'/Framework/Rendering/Assets/PanelRendererAssetsVisualSystemCss.php');
	$mobile=(string)file_get_contents(dirname(__DIR__).'/Framework/Rendering/Assets/PanelRendererAssetsMobileCss.php');
	$navigation=(string)file_get_contents(dirname(__DIR__).'/Framework/Rendering/Assets/PanelRendererAssetsMobileNavigationCss.php');
	$navigationExperience=(string)file_get_contents(dirname(__DIR__).'/Framework/Rendering/Assets/PanelRendererAssetsNavigationCss.php');
	$component=(string)file_get_contents(dirname(__DIR__).'/Framework/Rendering/Assets/PanelRendererAssetsComponentCss.php');
	$theme=(string)file_get_contents(dirname(__DIR__).'/Framework/Rendering/Assets/PanelRendererAssetsThemeCss.php');
	$t->notContains('.dp-panel-sidebar-group.active:before', $visual);
	$t->notContains('.dp-panel-sidebar-group.active:before', $navigation);
	$t->notContains('.dp-panel-sidebar-group.active:before', $navigationExperience);
	$t->notContains('--dp-nav-section-active-rail', $navigation);
	$t->contains('.dp-panel-sidebar-group:after{content:"";position:absolute;inset-inline:0;', $visual);
	$t->notContains('left:calc(var(--dp-nav-shell-padding,14px) * -1)', $visual);
	$t->contains('.dp-panel-modal{--dp-modal-pad:12px}', $visual);
	$t->contains(':where(.dp-panel-action-copy,.dp-panel-action-label){min-inline-size:0;max-inline-size:100%;overflow-wrap:anywhere;white-space:normal}', $visual);
	$t->contains(':is(.dp-panel-actions,.dp-panel-record-actions) :is(.dp-panel-action,.dp-panel-row-link) .dp-panel-action-label{display:block;', $visual);
	$t->contains('.dp-panel-table-views,.dp-panel-step-list,.dp-panel-tab-list,.dp-panel-horizontal-track{margin-inline:0;padding-inline:2px}', $mobile);
	$t->same(2, substr_count($navigation, '.dp-panel-sidebar-group h2 button>i{flex:0 0 auto;margin-inline:2px}'));
	$t->contains('@media(max-width:220px){.dp-panel-modal-root{padding:4px}', $visual);
	$t->contains('@container dp-panel-shell (max-width:220px){', $visual);
	$t->contains('@container dp-field (max-width:160px){', $visual);
	$t->contains('.dp-panel-column-picker:not([open])>form', $visual);
	$t->contains('--dp-relation-per-page-columns:minmax(0,1fr) auto;', $visual);
	$t->contains('.dp-panel .dp-panel-relation>.dp-panel-toolbar .dp-panel-per-page{display:grid;grid-template-columns:var(--dp-relation-per-page-columns);width:100%;min-width:0;max-width:100%;gap:8px}', $visual);
	$t->contains('--dp-relation-per-page-button-width:100%', $visual);
	$t->contains('min-width:var(--dp-table-actions-min-width,208px);', $component);
	$t->contains('--dp-table-actions-min-width:224px;', $theme);
	$t->contains('padding-inline:0;', $theme);
	$t->contains('margin-inline:0;', $theme);
	$t->notContains('margin-inline:-4px;', $theme);
})->tag('panel', 'renderer', 'visual-system', 'navigation', 'modal', 'overflow', 'regression')->maxMillis(1000);

test('renderer data tables explicitly own audited horizontal scrolling', static function(Context $t): void {
	$pages=(string)file_get_contents(dirname(__DIR__).'/Framework/Rendering/PanelRendererPages.php');
	$data=(string)file_get_contents(dirname(__DIR__).'/Framework/Rendering/PanelRendererData.php');
	$policy='data-dp-panel-overflow-policy="scroll-x" data-dp-panel-overflow-reason="data-table"';
	$t->same(2, substr_count($pages, $policy));
	$t->same(1, substr_count($data, $policy));
})->tag('panel', 'renderer', 'visual-regression', 'overflow', 'table')->maxMillis(1000);

test('visual regression runner exposes an opt-in responsive accessibility matrix without changing defaults', static function(Context $t): void {
	$visual=(string)file_get_contents(dirname(__DIR__).'/testing/panel_visual_regression.js');
	$t->contains("const defaultViewportNames=['desktop','laptop','mobile'];",$visual);
	foreach(["'320':{width:320, height:568}","'375':{width:375, height:667}","'768':{width:768, height:1024}","'2560':{width:2560, height:1440}"]as$profile){$t->contains($profile,$visual);}
	foreach(['--audit-only','--theme-mode','--direction','--zoom','--reduced-motion','--forced-colors','--container-width']as$option){$t->contains($option,$visual);}
	$t->contains('Default matrix: desktop, laptop, and mobile with no environment overrides.',$visual);
	$t->contains('selectedEnvironments',$visual);
	$t->contains('executionPlan',$visual);
	$t->contains("function zoomArtifactSlug(zoom){return artifactSlug(String(zoom))+'x';}",$visual);
	$t->contains("parts.push('mode-'",$visual);
	$t->contains("parts.push('dir-'",$visual);
	$t->contains("parts.push('zoom-'",$visual);
	$t->contains("parts.push('motion-'",$visual);
	$t->contains("parts.push('colors-'",$visual);
	$t->contains("parts.push('container-'",$visual);
	$t->contains("applyEnvironment(page,environment,'after_navigation')",$visual);
	$t->contains("applyEnvironment(page,environment,'sample_'",$visual);
	$t->contains('const root=document.documentElement;',$visual);
	$t->contains('settings.themeMode!==null&&root',$visual);
	$t->contains('environmentFailures(audit,environment,resultApplications)',$visual);
	$t->contains('environmentApplications.slice(applicationResultOffset)',$visual);
	$t->contains('parentRect.left-rect.left',$visual);
	$t->contains("const portalSelector='.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-toast-root,.dp-panel-unsaved-root';",$visual);
	$t->contains("const boundary=portal===element ? 'viewport' : 'region';",$visual);
	$t->contains('candidatePortal&&candidatePortal!==regionPortal',$visual);
	$t->contains("const boundary=portal===element ? 'viewport' : 'parent';",$visual);
	$t->contains("reason:args.auditOnly?'audit_only':null",$visual);
	$t->contains("forcedColors:{requested:environment.forcedColors,supported:null",$visual);
	$t->contains("status=entries.length===0?'not_requested'",$visual);
	$t->contains('matrix:{...matrixReport(environments)',$visual);
	$t->contains('runnableCombinations:plan.runnable.length',$visual);
	$t->contains("mode:args.auditOnly?'audit_only':'visual_regression'",$visual);
})->tag('panel','renderer','visual-regression','matrix','accessibility','source-contract')->maxMillis(1000);
