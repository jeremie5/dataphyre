<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

require_once __DIR__.'/Assets/PanelRendererAssetsCoreCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsComponentCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsLayoutCoreCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsPresentationCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsNavigationCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsMobileCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsMobileNavigationCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsThemeCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsFeatureCss.php';

/**
 * Built-in Panel CSS and JavaScript asset registry.
 *
 * The trait exposes a tiny public asset contract for panel front controllers:
 * normalize requested names, generate configured asset URLs, compute stable
 * content hashes for cache-busting, and return bundled CSS/JS payloads with
 * content types.
 */
trait PanelRendererAssets {
	use PanelRendererAssetsCoreCss;
	use PanelRendererAssetsScripts;
	use PanelRendererAssetsComponentCss;
	use PanelRendererAssetsLayoutCoreCss;
	use PanelRendererAssetsPresentationCss;
	use PanelRendererAssetsNavigationCss;
	use PanelRendererAssetsMobileCss;
	use PanelRendererAssetsMobileNavigationCss;
	use PanelRendererAssetsThemeCss;
	use PanelRendererAssetsFeatureCss;

	/**
	 * Resolves a public URL for a known panel asset.
	 *
	 * Unknown names return an empty string so callers can avoid exposing arbitrary
	 * path input through the asset route.
	 *
	 * @param string $asset Requested asset filename.
	 * @return string Configured asset URL, or an empty string for unknown assets.
	 */
	public static function assetUrl(string $asset): string {
		$asset=self::assetName($asset);
		if($asset===''){
			return '';
		}
		return PanelConfig::assetUrl($asset);
	}

	/**
	 * Computes the cache-busting version for a known panel asset.
	 *
	 * Versions are derived from bundled content rather than filesystem mtime so
	 * deployments with generated or embedded assets remain deterministic.
	 *
	 * @param string $asset Requested asset filename.
	 * @return string Sixteen-character SHA-256 prefix, or "missing" for unknown assets.
	 */
	public static function assetVersion(string $asset): string {
		$content=self::assetContent($asset);
		if($content===null){
			return 'missing';
		}
		return substr(hash('sha256', $content['content']), 0, 16);
	}

	/**
	 * Returns the HTTP response body and content type for a known panel asset.
	 *
	 * Only the built-in panel.css, panel.js, and panel-head.js bundles are served
	 * by this registry. The returned content is generated in-process from trait
	 * methods loaded above.
	 *
	 * @param string $asset Requested asset filename.
	 * @return ?array{content_type: string, content: string} Asset response payload, or null for unknown assets.
	 */
	public static function assetContent(string $asset): ?array {
		$asset=self::assetName($asset);
		return match($asset){
			'panel.css'=>[
				'content_type'=>'text/css; charset=UTF-8',
				'content'=>self::panelStylesheet(),
			],
			'panel.js'=>[
				'content_type'=>'application/javascript; charset=UTF-8',
				'content'=>self::panelScriptBundle(),
			],
			'panel-head.js'=>[
				'content_type'=>'application/javascript; charset=UTF-8',
				'content'=>self::panelHeadScript(),
			],
			default=>null,
		};
	}

	/**
	 * Normalizes and validates a requested asset name.
	 *
	 * @param string $asset User or route supplied asset path.
	 * @return string Canonical asset filename, or an empty string when unsupported.
	 */
	private static function assetName(string $asset): string {
		$asset=strtolower(basename(str_replace('\\', '/', trim($asset))));
		return in_array($asset, ['panel.css', 'panel.js', 'panel-head.js'], true) ? $asset : '';
	}

	/**
	 * Concatenates all panel CSS modules into the public stylesheet bundle.
	 *
	 * @return string Complete panel.css content.
	 */
	private static function panelStylesheet(): string {
		return self::css().self::showCss().self::infolistCss().self::recordPulseCss().self::tablePulseCss()
			.self::boardPulseCss().self::formPulseCss().self::alertsCss().self::insightsCss().self::linksCss()
			.self::contactsCss().self::locationsCss().self::approvalsCss().self::tagsCss().self::boardCss()
			.self::tasksCss().self::taskFormCss().self::activityCss().self::changesCss().self::itemsCss()
			.self::totalsCss().self::paymentsCss().self::shipmentsCss().self::attachmentsCss().self::messagesCss()
			.self::notesCss().self::modalCss().self::reactivityCss().self::themeSelectorCss().self::sidebarCss()
			.self::sidebarSearchCss().self::actionGroupCss().self::tabsCss().self::stepsCss().self::repeaterCss()
			.self::fieldComponentCss().self::themeOverrideCss().self::surfaceGuidanceCss().self::chartCss().self::actionPolishCss()
			.self::tableShellCss().self::columnDescriptionCss().self::commandPaletteCss().self::appFrameCss()
			.self::advancedGridCss().self::nextLevelUiCss().self::shellLayoutCss().self::commandbarCss().self::commandbarModeCss()
			.self::tableGroupCss().self::dataWorkspaceCss().self::selectionCss().self::rowActionsCss()
			.self::relationManagerCss().self::tableKeyboardCss().self::presentationCss().self::navigationExperienceCss()
			.self::mobileReactCss().self::mobileNavigationCss().self::brutalistThemeCss().self::glassThemeCss().self::chromeAttachmentCss()
			.self::tableActionHeaderCss().self::sidebarRailBreakpointCss().self::authCss().self::tableMetaCompactCss().self::joinedSearchCss()
			.self::compactCommandbarPrimaryCss().self::labeledActionIconCleanupCss().self::panelProductStabilizerCss();
	}

	/**
	 * Concatenates all panel runtime scripts into the public JavaScript bundle.
	 *
	 * @return string Complete panel.js content.
	 */
	private static function panelScriptBundle(): string {
		return "/* dp-panel-modal-submit-fallback-v2 */\n".self::script().self::themeModeRuntimeScript().self::modalScript().self::boardScript();
	}

	/**
	 * Returns layout CSS for inline commandbar bottom controls.
	 *
	 * @return string CSS fragment appended to the panel stylesheet bundle.
	 */
	private static function commandbarModeCss(): string {
		return <<<'CSS'
.dp-panel-commandbar[data-dp-panel-commandbar-bottom-mode="inline"] .dp-panel-commandbar-bottom{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:12px!important;flex-wrap:wrap}.dp-panel-commandbar[data-dp-panel-commandbar-bottom-mode="inline"] .dp-panel-commandbar-view,.dp-panel-commandbar[data-dp-panel-commandbar-bottom-mode="inline"] .dp-panel-commandbar-utility{flex:0 1 auto;min-width:0}.dp-panel-commandbar[data-dp-panel-commandbar-bottom-mode="inline"] .dp-panel-commandbar-utility{display:flex!important;align-items:center!important;justify-content:flex-end!important;gap:9px!important;flex-wrap:wrap}@media(max-width:1180px){.dp-panel-commandbar[data-dp-panel-commandbar-bottom-mode="inline"] .dp-panel-commandbar-bottom{display:grid!important;grid-template-columns:1fr!important}.dp-panel-commandbar[data-dp-panel-commandbar-bottom-mode="inline"] .dp-panel-commandbar-utility{justify-content:flex-start!important}}
CSS;
	}

	/**
	 * Returns the early theme-mode bootstrap script.
	 *
	 * @return string JavaScript that applies the stored theme mode before full panel scripts load.
	 */
	private static function panelHeadScript(): string {
		return <<<'JS'
(function(){
	var script=document.currentScript;
	var fallback=script ? (script.getAttribute("data-dp-panel-theme-mode") || "light") : "light";
	var mode=fallback;
	try {
		mode=localStorage.getItem("dataphyre_panel_theme_mode") || fallback;
	} catch(error) {}
	if(["light", "dark", "system"].indexOf(mode)===-1){
		mode=fallback;
	}
	document.documentElement.dataset.dpThemeMode=mode;
})();
JS;
	}

	/**
	 * Returns compact table metadata control CSS.
	 *
	 * @return string CSS fragment for dense table meta controls and mobile wrapping.
	 */
	private static function tableMetaCompactCss(): string {
		return <<<'CSS'
.dp-panel-table-meta{min-width:0}.dp-panel.dp-panel .dp-panel-table-meta-controls{display:inline-flex!important;align-items:center!important;justify-content:flex-end!important;gap:8px!important;flex:0 1 auto!important;min-width:0!important;max-width:100%!important;margin-left:auto!important;overflow:visible!important}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-view,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-utility,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-actions,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-groups{display:inline-flex!important;grid-template-columns:none!important;align-items:center!important;justify-content:flex-end!important;gap:8px!important;flex:0 1 auto!important;min-width:0!important;max-width:100%!important;width:auto!important;flex-wrap:nowrap!important}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-view:empty,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-utility:empty,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-actions:empty,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-groups:empty{display:none!important}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-per-page,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker{display:inline-flex!important;align-items:center!important;flex:0 0 auto!important;width:auto!important;min-width:0!important;max-width:max-content!important}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-per-page label{display:inline-flex!important;align-items:center!important;gap:5px!important;width:auto!important;min-width:0!important;height:28px!important;min-height:28px!important;margin:0!important;color:var(--dp-text_muted)!important;font-size:11px!important;font-weight:850!important;white-space:nowrap!important}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-per-page label>span{display:inline!important;width:auto!important;min-width:0!important;min-height:0!important;height:auto!important;border:0!important;border-radius:0!important;background:transparent!important;padding:0!important;color:var(--dp-text_muted)!important;font-size:11px!important;font-weight:850!important;line-height:1!important}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-per-page select{width:auto!important;min-width:48px!important;max-width:64px!important;height:28px!important;min-height:28px!important;max-height:28px!important;block-size:28px!important;min-block-size:28px!important;border-radius:8px!important;padding:1px 20px 1px 8px!important;color:var(--dp-text_muted)!important;font-size:11px!important;font-weight:850!important;line-height:1!important;box-shadow:none!important}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker summary{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:5px!important;width:auto!important;min-width:0!important;max-width:max-content!important;height:28px!important;min-height:28px!important;max-height:28px!important;block-size:28px!important;min-block-size:28px!important;border-radius:8px!important;padding:1px 8px!important;color:var(--dp-text_muted)!important;font-size:11px!important;font-weight:850!important;line-height:1!important;white-space:nowrap!important;box-shadow:none!important}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker summary small{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-width:0!important;height:16px!important;min-height:16px!important;border-radius:999px!important;padding:1px 5px!important;font-size:10px!important;line-height:1!important}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker form{right:0!important;left:auto!important;max-width:min(320px,calc(100vw - 32px))!important}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-button{height:28px!important;min-height:28px!important;max-height:28px!important;border-radius:8px!important;padding:1px 8px!important;font-size:11px!important;box-shadow:none!important}@media(max-width:900px){.dp-panel.dp-panel .dp-panel-table-meta{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:8px!important;flex-wrap:wrap!important}.dp-panel.dp-panel .dp-panel-table-meta-controls{justify-content:flex-start!important;flex:1 1 100%!important;margin-left:0!important}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker summary{width:auto!important;max-width:max-content!important}}@media(max-width:560px){.dp-panel.dp-panel .dp-panel-table-meta-controls{display:inline-flex!important;grid-template-columns:none!important;width:auto!important;flex-wrap:wrap!important}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-view,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-utility,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-actions,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-groups{display:inline-flex!important;grid-template-columns:none!important;width:auto!important;flex-wrap:wrap!important}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-per-page,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker summary{width:auto!important;max-width:max-content!important}}
CSS;
	}

	/**
	 * Returns CSS for joined search input and submit controls.
	 *
	 * @return string CSS fragment for desktop joined controls and mobile stacked fallback.
	 */
	private static function joinedSearchCss(): string {
		return <<<'CSS'
.dp-panel .dp-panel-search,.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-search>.dp-panel-search,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-search>.dp-panel-search{display:flex!important;align-items:stretch!important;gap:0!important;flex-wrap:nowrap!important;min-width:0!important;max-width:100%!important}.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-search>.dp-panel-search,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-search>.dp-panel-search{grid-template-columns:none!important}.dp-panel .dp-panel-search input[type="search"]{flex:1 1 auto!important;min-width:0!important;width:auto!important;height:46px!important;min-height:46px!important;border-top-right-radius:0!important;border-bottom-right-radius:0!important;border-start-end-radius:0!important;border-end-end-radius:0!important}.dp-panel .dp-panel-commandbar-search>.dp-panel-search input[type="search"]{height:48px!important;min-height:48px!important}.dp-panel .dp-panel-search>button[type="submit"].dp-panel-button,.dp-panel .dp-panel-search input[type="search"]+.dp-panel-button{flex:0 0 auto!important;align-self:stretch!important;height:46px!important;min-height:46px!important;margin-left:-1px!important;border-top-left-radius:0!important;border-bottom-left-radius:0!important;border-start-start-radius:0!important;border-end-start-radius:0!important;white-space:nowrap!important}.dp-panel .dp-panel-commandbar-search>.dp-panel-search>button[type="submit"].dp-panel-button,.dp-panel .dp-panel-commandbar-search>.dp-panel-search input[type="search"]+.dp-panel-button{height:48px!important;min-height:48px!important;min-width:112px!important}.dp-panel .dp-panel-search>button[type="submit"].dp-panel-button+a.dp-panel-button,.dp-panel .dp-panel-search input[type="search"]+.dp-panel-button+.dp-panel-button{margin-left:8px!important;border-radius:10px!important}.dp-panel .dp-panel-search input[type="search"]:focus+button[type="submit"].dp-panel-button,.dp-panel .dp-panel-search:focus-within>button[type="submit"].dp-panel-button{border-color:var(--dp-primary-600,#2563eb)!important}@media(max-width:760px){.dp-panel .dp-panel-search,.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-search>.dp-panel-search,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-search>.dp-panel-search{display:grid!important;grid-template-columns:1fr!important;gap:8px!important}.dp-panel .dp-panel-search input[type="search"]{width:100%!important;border-radius:12px!important}.dp-panel .dp-panel-search>button[type="submit"].dp-panel-button,.dp-panel .dp-panel-search input[type="search"]+.dp-panel-button,.dp-panel .dp-panel-search>button[type="submit"].dp-panel-button+a.dp-panel-button,.dp-panel .dp-panel-search input[type="search"]+.dp-panel-button+.dp-panel-button{width:100%!important;margin-left:0!important;border-radius:12px!important}}
CSS;
	}

	/**
	 * Returns CSS that compacts primary commandbar actions.
	 *
	 * @return string CSS fragment for index and board commandbar action layout.
	 */
	private static function compactCommandbarPrimaryCss(): string {
		return <<<'CSS'
@media(min-width:1181px){.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-top,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-top{display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;align-items:center!important;gap:10px!important}.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-search,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-search{min-width:0!important}.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-primary,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-primary{align-self:stretch!important}}
@media(min-width:861px){.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-primary,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-primary{display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:9px!important;flex-wrap:wrap!important;width:auto!important;max-width:100%!important}.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-primary>.dp-panel-inline-action,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-primary>.dp-panel-inline-action,.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-primary>.dp-panel-button,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-primary>.dp-panel-button,.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-primary>.dp-panel-action-group,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-primary>.dp-panel-action-group{display:inline-flex!important;flex:0 0 auto!important;width:auto!important;min-width:0!important;max-width:100%!important}.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-primary .dp-panel-action,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-primary .dp-panel-action,.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-primary .dp-panel-button,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-primary .dp-panel-button,.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-create,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-create{width:auto!important;min-width:124px!important;max-width:max-content!important;justify-content:center!important}}
CSS;
	}

	/**
	 * Returns CSS that hides redundant icons on already-labeled actions.
	 *
	 * @return string CSS fragment for create and row-link action cleanup.
	 */
	private static function labeledActionIconCleanupCss(): string {
		return <<<'CSS'
.dp-panel-commandbar-create .dp-panel-action-icon,.dp-panel-row-link .dp-panel-action-icon{display:none!important}.dp-panel-commandbar-create,.dp-panel-row-link{gap:0!important}
CSS;
	}

	/**
	 * Returns Panel stabilization CSS.
	 *
	 * This late bundle preserves layout, commandbar, footer, and responsive
	 * behavior for dense application panel screens.
	 *
	 * @return string CSS fragment appended after shared panel styles.
	 */
	private static function panelProductStabilizerCss(): string {
		return <<<'CSS'
.dp-panel,body:has(.dp-panel){font-family:var(--dp-font_family,Inter,Arial,sans-serif)!important}
body:has(.dp-panel){color:var(--dp-text,#18202a)!important;background:var(--dp-body_bg,var(--dp-app-bg,#f4f7fb))!important}
body[data-dp-theme-mode="dark"]:has(.dp-panel){background:var(--dp-body_bg,#020617)!important;color:var(--dp-text,#f8fafc)!important;color-scheme:dark}
.dp-panel{max-width:none!important;min-height:100dvh!important;font-family:var(--dp-font_family,Inter,Arial,sans-serif)!important;color:var(--dp-text,#18202a)!important}
.dp-panel.dp-panel-nav-sidebar{grid-template-columns:320px minmax(0,1fr)!important;column-gap:24px!important;padding:0!important}
.dp-panel-nav-sidebar .dp-panel-sidebar{border-radius:0!important;border:0!important;border-right:1px solid var(--dp-border_soft,#e7ecf2)!important;background:var(--dp-surface,#fff)!important;box-shadow:none!important;padding:18px 16px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-top{position:relative!important;top:auto!important;margin:0!important;padding:0 0 16px!important;background:transparent!important;border-radius:0!important;box-shadow:none!important;backdrop-filter:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand{border:0!important;border-radius:8px!important;background:transparent!important;box-shadow:none!important;padding:0!important;min-height:44px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand:hover{background:transparent!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand>span{width:44px!important;height:44px!important;border-radius:8px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand strong{font-size:14px!important;font-weight:800!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand small{font-size:12px!important;font-weight:600!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-search,.dp-panel-nav-sidebar .dp-panel-sidebar-context,.dp-panel-nav-sidebar .dp-panel-sidebar-pin{display:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-nav{gap:10px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group{display:grid!important;gap:4px!important;margin:8px 0 0!important;padding:12px 0 0!important;border:0!important;border-top:1px solid var(--dp-border_soft,#e7ecf2)!important;border-radius:0!important;background:transparent!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2{margin:0!important;padding:0 8px!important;color:var(--dp-text_muted,#667085)!important;font-size:11px!important;font-weight:750!important;letter-spacing:.04em!important;text-transform:uppercase!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button{min-height:28px!important;padding:0!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link{min-height:38px!important;border:0!important;border-radius:8px!important;background:transparent!important;box-shadow:none!important;color:var(--dp-text,#18202a)!important;padding:5px 8px!important;transform:none!important;grid-template-columns:34px minmax(0,1fr) auto!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link:hover{background:var(--dp-surface_muted,#f8fafc)!important;border:0!important;box-shadow:none!important;transform:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active{background:var(--dp-primary-600,#2563eb)!important;color:#fff!important;border:0!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active strong,.dp-panel-nav-sidebar .dp-panel-sidebar-link.active small{color:#fff!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-icon{width:30px!important;height:30px!important;border-radius:8px!important;background:var(--dp-neutral_bg,#eef2f7)!important;color:var(--dp-neutral_text,#344054)!important;font-size:10px!important;font-weight:750!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-icon{background:rgba(255,255,255,.16)!important;color:#fff!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy strong{font-size:13px!important;font-weight:750!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy small{font-size:12px!important;font-weight:500!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-badge{background:transparent!important;color:inherit!important;font-size:11px!important;font-weight:700!important;padding:0!important}
.dp-panel .dp-panel-main-region{--dp-panel-main-pad-right:28px;display:flex!important;flex-direction:column!important;align-content:stretch!important;min-width:0!important;min-height:100dvh!important;gap:18px!important;padding:24px var(--dp-panel-main-pad-right) 0 0!important}
.dp-panel .dp-panel-mobile-nav-backdrop,.dp-panel .dp-panel-mobile-nav-toggle{display:none!important}
.dp-panel .dp-panel-main-region>header,.dp-panel .dp-panel-header{border:0!important;border-bottom:1px solid var(--dp-border_soft,#e7ecf2)!important;border-radius:0!important;box-shadow:none!important;background:var(--dp-surface,#fff)!important;margin:0!important;padding:22px 24px!important}
.dp-panel .dp-panel-commandbar{border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;padding:0!important}
.dp-panel.dp-panel .dp-panel-search{border:1px solid var(--dp-border,#d9e0ea)!important;border-radius:10px!important;background:var(--dp-surface,#fff)!important;padding:0!important;overflow:hidden!important;box-shadow:none!important}
.dp-panel.dp-panel .dp-panel-search input[type="search"]{border:0!important;outline:0!important;box-shadow:none!important;background:var(--dp-control_bg,#fff)!important;padding-left:12px!important;padding-right:12px!important}
.dp-panel.dp-panel .dp-panel-search .dp-panel-button{border:0!important;border-left:1px solid var(--dp-border,#d9e0ea)!important;box-shadow:none!important;border-radius:0!important}
.dp-panel.dp-panel input:not([type="checkbox"]):not([type="radio"]):not([type="button"]):not([type="submit"]):not([type="reset"]):not([type="range"]):not([type="color"]):not([type="file"]),.dp-panel.dp-panel textarea{padding-left:12px!important;padding-right:12px!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta{display:grid!important;grid-template-columns:auto minmax(320px,1fr) auto!important;align-items:center!important;gap:12px!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta-with-header-controls{align-items:center!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-counts{display:inline-flex!important;align-items:center!important;gap:10px!important;min-width:max-content!important;white-space:nowrap!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls{--dp-panel-table-header-control-height:32px;display:grid!important;grid-template-columns:minmax(220px,1fr) auto auto!important;align-items:center!important;align-self:center!important;gap:8px!important;min-width:0!important;width:100%!important;height:auto!important;min-height:var(--dp-panel-table-header-control-height)!important;max-height:none!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls>*{align-self:center!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-search-compact{box-sizing:border-box!important;display:flex!important;align-items:stretch!important;gap:0!important;min-width:0!important;width:100%!important;height:var(--dp-panel-table-header-control-height)!important;min-height:var(--dp-panel-table-header-control-height)!important;max-height:var(--dp-panel-table-header-control-height)!important;align-self:center!important;margin:0!important;padding:0!important;border-radius:10px!important;overflow:hidden!important;line-height:1!important;box-shadow:none!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-search-compact input[type="search"]{box-sizing:border-box!important;height:var(--dp-panel-table-header-control-height)!important;min-height:var(--dp-panel-table-header-control-height)!important;max-height:var(--dp-panel-table-header-control-height)!important;padding:0 12px!important;border-radius:10px 0 0 10px!important;line-height:1.2!important;align-self:stretch!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-search-compact .dp-panel-button{box-sizing:border-box!important;height:var(--dp-panel-table-header-control-height)!important;min-height:var(--dp-panel-table-header-control-height)!important;max-height:var(--dp-panel-table-header-control-height)!important;min-width:0!important;border-radius:0 10px 10px 0!important;padding:0 11px!important;font-size:12px!important;line-height:1.2!important;box-shadow:none!important;white-space:nowrap!important;align-self:stretch!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-filter-trigger,.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-create{box-sizing:border-box!important;height:var(--dp-panel-table-header-control-height)!important;min-height:var(--dp-panel-table-header-control-height)!important;max-height:var(--dp-panel-table-header-control-height)!important;min-width:0!important;border-radius:9px!important;padding:0 11px!important;font-size:12px!important;line-height:1.2!important;box-shadow:none!important;white-space:nowrap!important;align-self:center!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-create{background:var(--dp-primary-600,#2563eb)!important;border-color:var(--dp-primary-600,#2563eb)!important;color:#fff!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-create .dp-panel-action-icon{display:none!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-create .dp-panel-action-label{background:transparent!important;color:inherit!important;box-shadow:none!important;padding:0!important;border:0!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-filter-panel,.dp-panel[data-dp-panel-kind="board"] .dp-panel-table-header-controls .dp-panel-filter-panel{display:inline-flex!important;align-items:center!important;align-self:center!important;width:auto!important;min-width:0!important;max-width:max-content!important;border:0!important;background:transparent!important;box-shadow:none!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-filter-trigger,.dp-panel[data-dp-panel-kind="board"] .dp-panel-table-header-controls .dp-panel-filter-trigger{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:7px!important;width:auto!important;min-width:0!important;max-width:max-content!important;margin:0!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-table-header-primary{display:flex!important;justify-content:flex-end!important;min-width:0!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta-with-header-controls,.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta-with-header-controls .dp-panel-table-meta-controls,.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta-with-header-controls .dp-panel-commandbar-view,.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta-with-header-controls .dp-panel-commandbar-utility,.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta-with-header-controls .dp-panel-commandbar-actions{min-height:32px!important;height:auto!important;max-height:none!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta-with-header-controls .dp-panel-table-counts span,.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta-controls .dp-panel-per-page label{box-sizing:border-box!important;height:32px!important;min-height:32px!important;max-height:32px!important}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta-controls .dp-panel-per-page select,.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta-controls .dp-panel-column-picker,.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta-controls .dp-panel-column-picker summary{box-sizing:border-box!important;height:32px!important;min-height:32px!important;max-height:32px!important;block-size:32px!important;min-block-size:32px!important;max-block-size:32px!important}
.dp-panel.dp-panel .dp-panel-table-shell,.dp-panel.dp-panel .dp-panel-page-table{border:1px solid var(--dp-border,#d9e0ea)!important;border-radius:10px!important;background:var(--dp-surface,#fff)!important;box-shadow:none!important}
.dp-panel.dp-panel .dp-panel-table-scroll,.dp-panel.dp-panel .dp-panel-table{border:0!important;border-color:var(--dp-border_soft,#e7ecf2)!important;box-shadow:none!important;background:var(--dp-surface,#fff)!important}
.dp-panel.dp-panel .dp-panel-table th{background:var(--dp-surface_muted,#f8fafc)!important;color:var(--dp-text_muted,#667085)!important;font-size:11px!important;font-weight:750!important;letter-spacing:.04em!important}
.dp-panel.dp-panel .dp-panel-empty-state{border:0!important;background:transparent!important;box-shadow:none!important}
.dp-panel .dp-panel-modal-root[data-dp-panel-modal-style="slide_over"]{backdrop-filter:blur(2px)!important;background:rgba(15,23,42,.18)!important}
body[data-dp-theme-mode="dark"] .dp-panel .dp-panel-modal-root[data-dp-panel-modal-style="slide_over"]{background:rgba(2,6,23,.34)!important}
.dp-panel.dp-panel .dp-panel-footer{box-sizing:border-box!important;display:block!important;position:relative!important;bottom:auto!important;z-index:auto!important;margin:0 calc(-1 * var(--dp-panel-main-pad-right,0px)) 0 0!important;margin-top:auto!important;align-self:end!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;width:calc(100% + var(--dp-panel-main-pad-right,0px))!important;max-width:none!important;overflow:hidden!important}
.dp-panel.dp-panel .dp-panel-footer-slim{box-sizing:border-box!important;display:flex!important;align-items:center!important;gap:14px!important;flex-wrap:wrap!important;width:100%!important;max-width:none!important;border-top:1px solid var(--dp-border_soft,#e7ecf2)!important;background:var(--dp-surface,#fff)!important;color:var(--dp-text,#18202a)!important;padding:14px 20px!important;font-size:13px!important}
.dp-panel.dp-panel .dp-panel-footer-slim p{margin:0!important;font-weight:650!important}
.dp-panel.dp-panel .dp-panel-footer-slim nav{display:inline-flex!important;align-items:center!important;gap:12px!important;flex-wrap:wrap!important}
.dp-panel.dp-panel .dp-panel-footer-slim a{color:var(--dp-primary-700,#175cd3)!important;text-decoration:none!important;font-weight:650!important}
.dp-panel.dp-panel .dp-panel-footer-identity{color:var(--dp-text_muted,#667085)!important}
.dp-panel.dp-panel .dp-panel-footer-language{display:inline-flex!important;align-items:center!important;gap:8px!important;margin-left:auto!important}
.dp-panel.dp-panel .dp-panel-footer-language label{display:inline-flex!important;align-items:center!important;gap:6px!important}
.dp-panel.dp-panel .dp-panel-footer-language select,.dp-panel.dp-panel .dp-panel-footer-language button{min-height:32px!important;border:1px solid var(--dp-border,#d9e0ea)!important;border-radius:8px!important;background:var(--dp-control_bg,#fff)!important;color:var(--dp-text,#18202a)!important;padding:5px 9px!important;font:inherit!important}
.dp-panel.dp-panel .dp-panel-footer-theme-toggle{min-height:34px!important}
@media(min-width:1181px){.dp-panel.dp-panel .dp-panel-footer{transform:translateX(-2px)!important}}
.dp-panel[data-dp-panel-sidebar-animation]:not([data-dp-panel-sidebar-animation="none"]) .dp-panel-sidebar-link,.dp-panel[data-dp-panel-sidebar-animation]:not([data-dp-panel-sidebar-animation="none"]) .dp-panel-sidebar-icon{transition:background var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease),color var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease),border-color var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease),box-shadow var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease),transform var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease),opacity var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease)!important}
@media(max-width:1180px){.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"]{display:block!important;width:100%!important;max-width:100%!important;padding:0!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region{--dp-panel-main-pad-inline:16px;--dp-panel-main-pad-right:var(--dp-panel-main-pad-inline);padding:16px!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>.dp-panel-footer{margin-inline:calc(-1 * var(--dp-panel-main-pad-inline,0px))!important;width:calc(100% + (2 * var(--dp-panel-main-pad-inline,0px)))!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-mobile-nav-toggle{display:inline-grid!important;place-items:center!important;position:relative!important;width:42px!important;height:42px!important;margin:0 0 8px!important;border:1px solid var(--dp-border,#d9e0ea)!important;border-radius:10px!important;background:var(--dp-surface,#fff)!important;color:var(--dp-text,#18202a)!important;box-shadow:none!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-mobile-nav-toggle span{display:block!important;width:17px!important;height:2px!important;border-radius:999px!important;background:currentColor!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-mobile-nav-backdrop{display:none!important;position:fixed!important;inset:0!important;z-index:79!important;border:0!important;background:rgba(15,23,42,.36)!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"].dp-panel-mobile-nav-open .dp-panel-mobile-nav-backdrop{display:block!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar{position:fixed!important;inset:0 auto 0 0!important;z-index:90!important;width:min(326px,88vw)!important;max-width:min(326px,88vw)!important;height:100dvh!important;max-height:100dvh!important;margin:0!important;overflow:auto!important;overscroll-behavior:contain!important;transform:translateX(-104%)!important;transition:transform .18s ease!important;border:0!important;border-right:1px solid var(--dp-border_soft,#e7ecf2)!important;border-radius:0!important;background:var(--dp-surface,#fff)!important;box-shadow:0 24px 70px rgba(15,23,42,.18)!important;padding:16px!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"].dp-panel-mobile-nav-open .dp-panel-sidebar{transform:translateX(0)!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-top{position:relative!important;top:auto!important;display:block!important;margin:0!important;padding:0 0 14px!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-brand{display:grid!important;grid-template-columns:44px minmax(0,1fr)!important;gap:10px!important;align-items:center!important;width:100%!important;min-height:44px!important;padding:0!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-brand small{display:none!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-nav{display:grid!important;grid-template-columns:1fr!important;gap:8px!important;width:100%!important;overflow:visible!important;padding:0!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-group{display:grid!important;grid-template-columns:1fr!important;gap:4px!important;flex:0 1 auto!important;margin:8px 0 0!important;padding:12px 0 0!important;border:0!important;border-top:1px solid var(--dp-border_soft,#e7ecf2)!important;border-radius:0!important;background:transparent!important;box-shadow:none!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-group h2{display:block!important;grid-column:1/-1!important;width:100%!important;margin:0 0 4px!important;padding:0 8px!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-group h2 button{display:flex!important;align-items:center!important;justify-content:space-between!important;min-height:28px!important;width:100%!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-link{width:100%!important;min-width:0!important;max-width:none!important;min-height:40px!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-item{display:grid!important;grid-template-columns:minmax(0,1fr)!important;gap:0!important}.dp-panel-footer-language{margin-left:0!important}body.dp-panel-mobile-nav-open{overflow:hidden!important}}
.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header{display:grid!important;grid-template-columns:auto minmax(0,1fr)!important;align-items:center!important;column-gap:10px!important;row-gap:8px!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header>.dp-panel-mobile-nav-toggle{grid-column:1!important;grid-row:1!important;margin:0!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header>.dp-panel-breadcrumbs{grid-column:2!important;grid-row:1!important;min-width:0!important;margin:0!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header>.dp-panel-brand,.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header>.dp-panel-heading-row{grid-column:1/-1!important}
@media(max-width:480px){.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header{row-gap:0!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header>.dp-panel-heading-row{grid-column:2!important;grid-row:1!important;align-self:center!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header>.dp-panel-heading-row h1{font-size:clamp(22px,7vw,30px)!important;line-height:1.05!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header>.dp-panel-heading-row p{margin:0 0 2px!important}}
@media(max-width:1180px){.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="none"] .dp-panel-sidebar{transition:none!important}.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="slide"] .dp-panel-sidebar{transition:transform var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease)!important}.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="slide_fade"] .dp-panel-sidebar{opacity:0!important;transition:transform var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease),opacity var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease)!important}.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="slide_fade"].dp-panel-mobile-nav-open .dp-panel-sidebar{opacity:1!important}.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="fade"] .dp-panel-sidebar{transform:translateX(0)!important;opacity:0!important;pointer-events:none!important;transition:opacity var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease)!important}.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="fade"].dp-panel-mobile-nav-open .dp-panel-sidebar{opacity:1!important;pointer-events:auto!important}.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="scale"] .dp-panel-sidebar{transform:translateX(-10px) scale(.985)!important;transform-origin:left center!important;opacity:0!important;pointer-events:none!important;transition:transform var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease),opacity var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease)!important}.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="scale"].dp-panel-mobile-nav-open .dp-panel-sidebar{transform:translateX(0) scale(1)!important;opacity:1!important;pointer-events:auto!important}}
@media(prefers-reduced-motion:reduce){.dp-panel[data-dp-panel-sidebar-animation] .dp-panel-sidebar,.dp-panel[data-dp-panel-sidebar-animation] .dp-panel-sidebar-link,.dp-panel[data-dp-panel-sidebar-animation] .dp-panel-sidebar-icon{transition:none!important;animation:none!important}}
@media(max-width:1080px){.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta{grid-template-columns:1fr auto!important;align-items:center!important}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls{grid-column:1/-1!important;grid-row:2!important;grid-template-columns:minmax(0,1fr) auto!important;justify-content:start!important;max-width:760px!important}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-search-compact{grid-column:1/-1!important;width:100%!important}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-table-header-primary{grid-column:auto!important;justify-content:flex-start!important}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-create{width:auto!important;min-width:120px!important}}
@media(max-width:900px){.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta{grid-template-columns:1fr!important;align-items:stretch!important}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls{grid-template-columns:minmax(0,1fr) auto!important;max-width:none!important}}
@media(max-width:760px){.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region{--dp-panel-main-pad-inline:12px;--dp-panel-main-pad-right:var(--dp-panel-main-pad-inline);padding:12px!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header{padding:14px!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar{width:min(318px,88vw)!important;max-width:min(318px,88vw)!important;padding:12px!important}.dp-panel.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-copy small{display:none!important}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls{grid-template-columns:1fr!important}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-search-compact{display:flex!important;grid-template-columns:none!important;align-items:stretch!important;width:100%!important}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-search-compact input[type="search"]{flex:1 1 auto!important;width:auto!important;min-width:0!important;border-radius:10px 0 0 10px!important}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-search-compact .dp-panel-button{flex:0 0 auto!important;width:auto!important;min-width:96px!important;margin-left:-1px!important;border-radius:0 10px 10px 0!important}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-filter-panel,.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-filter-trigger,.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-create{width:100%!important;max-width:none!important}.dp-panel.dp-panel .dp-panel-footer-slim{display:grid!important;gap:10px!important;padding:12px!important}.dp-panel.dp-panel .dp-panel-footer-language{display:grid!important;grid-template-columns:1fr auto!important}.dp-panel.dp-panel .dp-panel-footer-theme-toggle{width:max-content!important}}
CSS;
	}

	/**
	 * Escapes text for safe HTML output when asset helpers emit markup fragments.
	 *
	 * @param string $value Raw text.
	 * @return string UTF-8 HTML-escaped text.
	 */
	private static function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
