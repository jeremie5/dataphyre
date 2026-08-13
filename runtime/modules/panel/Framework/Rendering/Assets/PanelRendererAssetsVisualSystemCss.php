<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Canonical Panel visual-system layer.
 *
 * Earlier renderer fragments remain available while surfaces migrate, but their
 * cascade escalation is removed at source. Rules in this trait own tokens,
 * interaction sizes, focus treatment, and responsive shell invariants.
 */
trait PanelRendererAssetsVisualSystemCss {
	/**
	 * Returns visual-system-only tokens in their low-priority ownership layer.
	 */
	private static function visualSystemTokensCss(): string {
		return <<<'CSS'
:where(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root){
--dp-vs-space-2:8px;
--dp-vs-space-3:12px;
--dp-vs-space-4:16px;
--dp-vs-space-5:20px;
--dp-vs-control-sm:44px;
--dp-vs-control-md:44px;
--dp-vs-radius-sm:8px;
--dp-vs-radius-md:12px;
--dp-vs-focus:0 0 0 3px color-mix(in srgb,var(--dp-primary-600,#2563eb) 28%,transparent);
}
CSS;
	}

	/**
	 * Returns the authoritative visual-system tokens and cross-surface invariants.
	 *
	 * @return string CSS appended after the compatibility renderer fragments.
	 */
	private static function visualSystemCss(): string {
		return <<<'CSS'
.dp-panel,.dp-panel *,.dp-panel *:before,.dp-panel *:after,.dp-panel-modal-root,.dp-panel-modal-root *,.dp-panel-modal-root *:before,.dp-panel-modal-root *:after,.dp-panel-command-root,.dp-panel-command-root *,.dp-panel-unsaved-root,.dp-panel-unsaved-root *{box-sizing:border-box}
.dp-panel :where(a,button,input,select,textarea,summary,[role="button"]):focus-visible,:is(.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) :where(a,button,input,select,textarea,summary,[role="button"]):focus-visible{outline:2px solid var(--dp-primary-600,#2563eb);outline-offset:2px;box-shadow:var(--dp-vs-focus)}
.dp-panel :where(button,.dp-panel-button,.dp-panel-action,summary){touch-action:manipulation}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button{height:auto;min-height:var(--dp-vs-control-sm);padding-block:var(--dp-vs-space-2)}
.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-group h2 button{height:auto;min-height:var(--dp-vs-control-md);padding-block:var(--dp-vs-space-2)}
.dp-panel-nav-sidebar .dp-panel-sidebar-link{min-height:var(--dp-vs-control-md)}
body .dp-panel[data-dp-panel-kind] :is(.dp-panel-tab-list,.dp-panel-step-list) button{min-height:var(--dp-vs-control-sm);padding:8px 14px}
.dp-panel-table-group-heading:is(button){min-height:var(--dp-vs-control-md)}
.dp-panel-entry-copy{min-height:var(--dp-vs-control-sm);padding:8px 12px}
.dp-panel-a11y-dev-slot{position:relative;display:inline-flex;max-width:100%}body .dp-panel[data-dp-panel-kind] [data-dp-panel-a11y-dev-badge]{min-height:var(--dp-vs-control-sm);padding:7px 10px}.dp-panel-a11y-dev-popup{left:0;top:calc(100% + 6px);width:min(360px,calc(100vw - 32px))}.dp-panel-a11y-dev-popup[hidden]{display:none}.dp-panel-a11y-dev-popup :where(h4,p,small){margin:0;color:inherit;white-space:normal}
.dp-panel-mobile-nav-toggle{min-width:var(--dp-vs-control-md);min-height:var(--dp-vs-control-md)}
.dp-panel-modal-root :where(button,a[href],summary,[role="button"],.dp-panel-button,.dp-panel-action){height:auto;min-height:var(--dp-vs-control-md);touch-action:manipulation}
.dp-panel-modal-root input:where(:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]):not([type="file"])),.dp-panel-modal-root select,.dp-panel-modal-root textarea{height:auto;min-height:var(--dp-vs-control-md)}
.dp-panel-modal-root .dp-panel-modal-close{width:var(--dp-vs-control-md);min-width:var(--dp-vs-control-md);height:var(--dp-vs-control-md);min-height:var(--dp-vs-control-md)}
body .dp-panel[data-dp-panel-kind] :where(.dp-panel-button,.dp-panel-action,.dp-panel-filter-trigger,.dp-panel-row-link,.dp-panel-action-group>summary,.dp-panel-row-more>summary,.dp-panel-saved-view-menu>summary){min-height:44px}
.dp-panel{container-name:dp-panel-shell;container-type:inline-size}
.dp-panel-main-region,.dp-panel-form,.dp-panel-table-shell,.dp-panel-table-wrap,.dp-panel-modal,.dp-panel-relation,.dp-panel-relation>*{min-width:0;max-width:100%}
.dp-panel-form,.dp-panel-field{grid-template-columns:minmax(0,1fr)}
.dp-panel-table-views,.dp-panel-tab-list,.dp-panel-step-list{scrollbar-width:thin;overscroll-behavior-inline:contain}
.dp-panel-table .dp-panel-row-more>summary,.dp-panel-table .dp-panel-entry-copy,.dp-panel-relation .dp-panel-actions button{height:auto;min-height:var(--dp-vs-control-md)}
.dp-panel[data-dp-panel-kind="board"] .dp-panel-board-card .dp-panel-row-more>summary{height:auto;min-height:var(--dp-vs-control-md)}
.dp-panel-editor-toolbar{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:start;gap:var(--dp-vs-space-2);min-height:0;padding:var(--dp-vs-space-2);border-radius:var(--dp-vs-radius-md)}
.dp-panel-editor-toolbar>span:first-child{align-self:center;min-width:max-content}
.dp-panel-editor-tools{display:flex;align-items:center;gap:4px;min-width:0;max-width:100%;overflow-x:auto;padding:1px;scrollbar-width:thin}
.dp-panel-editor-tool-group,.dp-panel-editor-mode-switch{display:inline-flex;align-items:center;gap:2px;flex:0 0 auto;border:1px solid var(--dp-border_soft);border-radius:var(--dp-vs-radius-sm);background:var(--dp-surface);padding:3px}
.dp-panel-editor-tools button,.dp-panel-editor-mode-switch button{display:inline-flex;align-items:center;justify-content:center;min-width:var(--dp-vs-control-sm);min-height:var(--dp-vs-control-sm);border:0;border-radius:6px;background:transparent;color:var(--dp-text_muted);padding:7px 9px;font:inherit;font-size:12px;font-weight:800;line-height:1;cursor:pointer}
.dp-panel-editor-tools button:hover,.dp-panel-editor-mode-switch button:hover{background:var(--dp-neutral_bg);color:var(--dp-text)}
.dp-panel-editor-tools button[aria-pressed="true"],.dp-panel-editor-mode-switch button[aria-pressed="true"]{background:var(--dp-primary-600,#2563eb);color:#fff}
.dp-panel-editor-tools button:disabled{cursor:not-allowed;opacity:.42}
.dp-panel-editor-toolbar>small{grid-column:1/-1;justify-self:end}
.dp-panel-editor-visual{min-height:180px;border:1px solid var(--dp-control_border);border-radius:var(--dp-vs-radius-sm);background:var(--dp-control_bg);color:var(--dp-text);padding:14px;outline:0;overflow-wrap:anywhere}
.dp-panel-editor-visual:focus{border-color:var(--dp-primary-600,#2563eb);box-shadow:var(--dp-vs-focus)}
.dp-panel-builder-actions>.dp-panel-button{height:auto;min-height:var(--dp-vs-control-md)}
.dp-panel .dp-panel-column-picker .dp-panel-column-search{height:auto;min-height:var(--dp-vs-control-md)}
.dp-panel .dp-panel-field .dp-panel-input-shell,.dp-panel .dp-panel-field .dp-panel-input-shell input:not([type="checkbox"]):not([type="radio"]),.dp-panel .dp-panel-field .dp-panel-input-shell select,.dp-panel .dp-panel-field .dp-panel-input-shell textarea,.dp-panel .dp-panel-input-shell .dp-panel-input-button{height:auto;min-height:var(--dp-vs-control-md)}
.dp-panel .dp-panel-field>select{height:auto;min-height:var(--dp-vs-control-md)}
.dp-panel[data-dp-panel-kind="show"] .dp-panel-record-actions .dp-panel-action,.dp-panel[data-dp-panel-kind="show"] .dp-panel-record-actions .dp-panel-row-link,.dp-panel[data-dp-panel-kind="show"] .dp-panel-record-actions .dp-panel-button,.dp-panel[data-dp-panel-kind="show"] .dp-panel-record-actions .dp-panel-action-group>summary{height:auto;min-height:var(--dp-vs-control-md)}
.dp-panel[data-dp-panel-kind="show"] .dp-panel-record-actions .dp-panel-action-menu .dp-panel-action{height:auto;min-height:var(--dp-vs-control-md)}
.dp-panel .dp-panel-entry-copy{min-width:var(--dp-vs-control-md)}
.dp-panel .dp-panel-filter select,.dp-panel[data-dp-panel-kind="show"] .dp-panel-relation .dp-panel-per-page select,.dp-panel[data-dp-panel-kind="show"] .dp-panel-relation .dp-panel-per-page button{min-width:44px;min-height:var(--dp-vs-control-md)}
.dp-panel[data-dp-panel-page="feature_showcase"] a.dp-panel-button,.dp-panel[data-dp-panel-page="feature_showcase"] .dp-panel-table-view,.dp-panel[data-dp-panel-page="feature_showcase"] .dp-panel-table-group{height:auto;min-height:var(--dp-vs-control-md)}
body[data-dp-theme-effects~="flat_minima"]{background:var(--dp-body_bg,#f8fafc);background-image:none}
body[data-dp-theme-effects~="flat_minima"] .dp-panel{--dp-vs-surface-shadow:0 1px 2px color-mix(in srgb,var(--dp-text,#0f172a) 5%,transparent)}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-main-region{gap:var(--dp-vs-space-4)}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-main-region>header{padding:18px 20px;border-color:var(--dp-border);border-radius:var(--dp-vs-radius-md);background:var(--dp-surface);box-shadow:var(--dp-vs-surface-shadow);backdrop-filter:none}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-main-region>header .dp-panel-breadcrumbs{margin-bottom:8px}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-heading-row h1{font-size:clamp(28px,2.1vw,34px);line-height:1.08;letter-spacing:-.025em}
body[data-dp-theme-effects~="flat_minima"] .dp-panel :where(.dp-panel-card,.dp-panel-widget,.dp-panel-summary,.dp-panel-commandbar,.dp-panel-table-shell,.dp-panel-page-table,.dp-panel-form-section,.dp-panel-form-details,.dp-panel-show,.dp-panel-record-heading,.dp-panel-relation,.dp-panel-tasks,.dp-panel-messages,.dp-panel-notes){border-color:var(--dp-border);border-radius:var(--dp-vs-radius-md);box-shadow:var(--dp-vs-surface-shadow)}
body[data-dp-theme-effects~="flat_minima"] .dp-panel :where(.dp-panel-button,.dp-panel-action,.dp-panel-filter-trigger,.dp-panel-column-picker>summary,.dp-panel-density,.dp-panel-table-view,.dp-panel-table-group){border-radius:var(--dp-vs-radius-sm);box-shadow:none}
body[data-dp-theme-effects~="flat_minima"] .dp-panel :where(input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]),select,textarea){border-radius:var(--dp-vs-radius-sm);box-shadow:none}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-commandbar{gap:var(--dp-vs-space-3);padding:var(--dp-vs-space-4)}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-commandbar-top{gap:var(--dp-vs-space-3);padding-bottom:var(--dp-vs-space-3);border-bottom:1px solid var(--dp-border_soft)}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-commandbar-bottom{gap:var(--dp-vs-space-3);padding-top:0;border-top:0}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-commandbar :where(.dp-panel-search,.dp-panel-per-page){margin:0;padding:0;border:0;background:transparent}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-table-views{border-radius:var(--dp-vs-radius-md);padding:3px}
body[data-dp-theme-effects~="flat_minima"] .dp-panel[data-dp-panel-kind] .dp-panel-main-region>.dp-panel-widgets[data-dp-display]{--dp-collection-gap:1px;gap:1px;border:1px solid var(--dp-border);border-radius:var(--dp-vs-radius-md);background:var(--dp-border_soft);box-shadow:var(--dp-vs-surface-shadow);overflow:hidden}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-main-region>.dp-panel-widgets .dp-panel-widget{min-height:104px;margin:0;border:0;border-radius:0;background:var(--dp-surface);padding:16px;box-shadow:none}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-main-region>.dp-panel-widgets .dp-panel-widget strong{font-size:26px;letter-spacing:-.025em}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-summaries{gap:var(--dp-vs-space-3)}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-summary{min-height:96px;padding:14px 16px}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-summary strong{font-size:clamp(24px,2.3vw,32px);letter-spacing:-.025em}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-table-shell{overflow:hidden}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-table :where(th,td){border-color:var(--dp-border_soft)}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-form{gap:var(--dp-vs-space-4)}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-form-section{gap:var(--dp-vs-space-4);padding:20px}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-section-heading{padding-bottom:var(--dp-vs-space-3);border-bottom:1px solid var(--dp-border_soft)}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-record-heading{padding:20px}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-relation{grid-template-columns:minmax(0,1fr);overflow:hidden}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-relation>*{width:100%}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-relation .dp-panel-table-scroll{width:100%;max-width:100%;overflow-x:auto}
@media(max-width:1180px){
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"]{display:block;width:100%;max-width:100%;padding:0}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar{position:fixed;inset:0 auto 0 0;z-index:90;width:min(318px,88vw);max-width:min(318px,88vw);height:100dvh;max-height:100dvh;margin:0;overflow:auto;overscroll-behavior:contain;transform:translateX(-104%);border-radius:0}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"].dp-panel-mobile-nav-open .dp-panel-sidebar{transform:translateX(0)}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-mobile-nav-backdrop{position:fixed;inset:0;z-index:79}
body.dp-panel-mobile-nav-open{overflow:hidden}
}
@media(max-width:760px){
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button,.dp-panel-nav-sidebar .dp-panel-sidebar-link,.dp-panel-tab-list button,.dp-panel-step-list button,.dp-panel-table-group-heading:is(button),.dp-panel-entry-copy{min-height:var(--dp-vs-control-md)}
.dp-panel :where(.dp-panel-button,.dp-panel-action,.dp-panel-filter-trigger,.dp-panel-row-link,.dp-panel-action-group>summary,.dp-panel-row-more>summary,.dp-panel-saved-view-menu>summary){min-height:var(--dp-vs-control-md)}
.dp-panel :where(input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]),select,textarea){font-size:16px}
.dp-panel-main-region{width:auto;max-width:100%;min-width:0}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-main-region{gap:var(--dp-vs-space-3)}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-main-region>header{padding:14px}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-heading-row h1{font-size:27px}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-form-section{padding:14px}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-main-region>.dp-panel-widgets{grid-template-columns:minmax(0,1fr)}
body[data-dp-theme-effects~="flat_minima"] .dp-panel-main-region>.dp-panel-widgets .dp-panel-widget{min-height:96px;margin-right:0;border-right:0}
.dp-panel-editor-toolbar{grid-template-columns:minmax(0,1fr)}
.dp-panel-editor-toolbar>span:first-child,.dp-panel-editor-toolbar>small{justify-self:start}
.dp-panel-editor-tools{width:100%;flex-wrap:wrap;overflow:visible}.dp-panel-editor-tool-group{display:contents}
.dp-panel-editor-mode-switch{width:100%}
.dp-panel-editor-mode-switch button{flex:1 1 50%;min-height:var(--dp-vs-control-md)}
.dp-panel-editor-visual{min-height:220px}
.dp-panel-relation,.dp-panel-relation>*,.dp-panel-relation-title,.dp-panel-relation-aside,.dp-panel-relation .dp-panel-toolbar-actions,.dp-panel-relation .dp-panel-per-page,.dp-panel-relation .dp-panel-summaries{width:100%;min-width:0;max-width:100%}
.dp-panel-relation-header,.dp-panel-relation .dp-panel-toolbar{width:100%;min-width:0;max-width:100%;grid-template-columns:minmax(0,1fr)}
.dp-panel-relation>.dp-panel-toolbar>.dp-panel-toolbar-actions{display:grid;grid-template-columns:minmax(0,1fr);width:100%;min-width:0;max-width:100%;justify-content:stretch}
.dp-panel-relation>.dp-panel-toolbar>.dp-panel-toolbar-actions>*{width:100%;min-width:0;max-width:100%;flex:1 1 100%}
.dp-panel-relation>.dp-panel-toolbar>.dp-panel-toolbar-actions>.dp-panel-per-page{display:grid;grid-template-columns:minmax(0,1fr)}
.dp-panel-relation>.dp-panel-toolbar>.dp-panel-toolbar-actions>.dp-panel-per-page label{width:100%;min-width:0}
.dp-panel-relation>.dp-panel-toolbar>.dp-panel-toolbar-actions>.dp-panel-per-page select{width:100%}
.dp-panel[data-dp-panel-kind="show"] .dp-panel-record-actions .dp-panel-action,.dp-panel[data-dp-panel-kind="show"] .dp-panel-record-actions .dp-panel-action-group>summary{height:auto;min-height:var(--dp-vs-control-md)}
.dp-panel-table .dp-panel-row-more>summary,.dp-panel-table .dp-panel-entry-copy,.dp-panel-relation .dp-panel-actions button{min-height:var(--dp-vs-control-md)}
.dp-panel[data-dp-panel-kind="board"] .dp-panel-board-card .dp-panel-row-more>summary{min-height:var(--dp-vs-control-md)}
}
.dp-panel .dp-panel-mobile-nav-dismiss{display:none}
@media(min-width:1181px){
.dp-panel-nav-sidebar .dp-panel-sidebar{scrollbar-width:thin;scrollbar-color:color-mix(in srgb,var(--dp-text_muted) 42%,transparent) transparent;scrollbar-gutter:stable}
.dp-panel-nav-sidebar .dp-panel-sidebar::-webkit-scrollbar{width:8px}
.dp-panel-nav-sidebar .dp-panel-sidebar::-webkit-scrollbar-track{background:transparent}
.dp-panel-nav-sidebar .dp-panel-sidebar::-webkit-scrollbar-thumb{border:2px solid transparent;border-radius:999px;background:color-mix(in srgb,var(--dp-text_muted) 42%,transparent);background-clip:padding-box}
.dp-panel-nav-sidebar .dp-panel-sidebar-group{border-top:0}
.dp-panel-nav-sidebar .dp-panel-sidebar-group:after{content:"";position:absolute;inset-inline:0;top:0;height:1px;background:var(--dp-nav-section-border);pointer-events:none}
}
@media(min-width:1181px) and (max-width:1320px){.dp-panel-nav-sidebar .dp-panel-sidebar{--dp-nav-shell-padding:8px}}
@media(max-width:1024px){.dp-panel-modal{--dp-modal-pad:12px}.dp-panel-modal-body:before,.dp-panel-modal-body:after{margin-inline:calc(var(--dp-modal-pad) * -1)}}
body[data-dp-theme-mode="dark"] .dp-panel-nav-sidebar .dp-panel-sidebar-group{background:transparent}
@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"] .dp-panel-nav-sidebar .dp-panel-sidebar-group{background:transparent}}
@media(max-width:1180px){
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-mobile-nav-backdrop{background:rgba(2,6,23,.68);backdrop-filter:blur(3px)}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar{display:grid;align-content:start;grid-auto-rows:max-content;gap:0;width:min(336px,92vw);max-width:min(336px,92vw);padding:0;border:0;border-right:1px solid var(--dp-nav-shell-border);background:var(--dp-nav-shell-bg);box-shadow:none;scrollbar-width:thin;scrollbar-color:color-mix(in srgb,var(--dp-text_muted) 44%,transparent) transparent;scrollbar-gutter:stable}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"].dp-panel-mobile-nav-open .dp-panel-sidebar{box-shadow:26px 0 70px rgba(2,6,23,.34)}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar::-webkit-scrollbar{width:8px}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar::-webkit-scrollbar-track{background:transparent}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar::-webkit-scrollbar-thumb{border:2px solid transparent;border-radius:999px;background:color-mix(in srgb,var(--dp-text_muted) 44%,transparent);background-clip:padding-box}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-top{position:sticky;top:0;z-index:4;display:grid;grid-template-columns:minmax(0,1fr) 44px;align-items:center;gap:10px;width:100%;height:auto;min-height:72px;margin:0;padding:12px;border:0;border-bottom:1px solid var(--dp-nav-section-border);background:color-mix(in srgb,var(--dp-surface) 94%,transparent);backdrop-filter:blur(18px)}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-brand{grid-template-columns:40px minmax(0,1fr);grid-template-areas:"icon name";gap:10px;width:100%;min-width:0;min-height:44px;padding:2px;border:0;background:transparent;box-shadow:none;backdrop-filter:none;-webkit-backdrop-filter:none}
body[data-dp-theme-effects~="glass"] .dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-brand{border-color:transparent;background:transparent;box-shadow:none;backdrop-filter:none;-webkit-backdrop-filter:none}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-brand>span{grid-area:icon;width:40px;height:40px;border-radius:12px}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-brand strong{grid-area:name;font-size:14px;font-weight:850}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-brand small{display:none}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-mobile-nav-dismiss{position:relative;display:grid;place-items:center;width:44px;height:44px;min-height:44px;border:1px solid var(--dp-nav-brand-border);border-radius:13px;background:var(--dp-nav-brand-bg);color:var(--dp-text);box-shadow:none;cursor:pointer}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-mobile-nav-dismiss span,.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-mobile-nav-dismiss span:after{position:absolute;left:50%;top:50%;display:block;width:16px;height:2px;border-radius:999px;background:currentColor;content:"";transform-origin:center}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-mobile-nav-dismiss span{transform:translate(-50%,-50%) rotate(45deg)}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-mobile-nav-dismiss span:after{left:0;top:0;transform:rotate(90deg)}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-search{margin:12px 12px 0}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-context{margin:10px 12px 0}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-nav{display:grid;grid-template-columns:minmax(0,1fr);gap:3px;width:100%;margin:0;padding:10px 12px 24px;overflow:visible}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-pinned,.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-recent,.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-group.active{order:initial}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-group{position:relative;display:grid;grid-template-columns:minmax(0,1fr);gap:3px;margin:8px 0 0;padding:10px 0 0;border:0;background:transparent;box-shadow:none}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-group:after{content:none}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-group h2{display:block;width:100%;min-height:30px;margin:0 0 2px;padding:0 7px;color:var(--dp-nav-section-label)}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-group h2 button{min-height:44px;padding:0;border-radius:8px;background:transparent}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-item{grid-template-columns:minmax(0,1fr);gap:0}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-pin{display:none}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-link,.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-submenu>summary{min-height:44px;border:1px solid transparent;border-radius:12px;background:transparent;padding:5px 7px;box-shadow:none}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-link:hover,.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-submenu>summary:hover{border-color:transparent;background:var(--dp-nav-item-hover-bg)}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-link.active{border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 38%,transparent);background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 18%,var(--dp-surface));color:var(--dp-text);box-shadow:inset 3px 0 0 var(--dp-primary-600,#2563eb)}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-submenu.active>summary{border-color:transparent;background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 9%,transparent);color:var(--dp-text);box-shadow:none}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-link.active .dp-panel-sidebar-icon,.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-link.active .dp-panel-sidebar-badge{background:var(--dp-primary-600,#2563eb);color:#fff}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-submenu.active>summary .dp-panel-sidebar-icon{background:var(--dp-nav-icon-bg);color:var(--dp-nav-icon-color)}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-submenu-items{gap:2px;margin:2px 0 2px 16px;padding:2px 0 2px 10px;border-left:1px solid color-mix(in srgb,var(--dp-nav-submenu-rail) 72%,transparent)}
}
.dp-panel-form{scroll-padding-block:96px 120px}
.dp-panel :where(.dp-panel-field-invalid,[aria-invalid="true"],.dp-panel-notice,.dp-panel-alert,[data-dp-panel-refresh-status]){scroll-margin-block:96px 120px}
@media(max-width:760px){.dp-panel-toast-root{top:max(8px,env(safe-area-inset-top));right:8px;bottom:auto;left:8px;width:auto;max-height:calc(100dvh - 16px);overflow:auto}.dp-panel-toast{width:100%;max-width:none}}
@media(max-width:620px){body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar-primary{grid-template-columns:repeat(auto-fit,minmax(min(100%,156px),1fr))}body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar-primary>:last-child:nth-child(odd){grid-column:1/-1}}
:where(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) :where(button,.dp-panel-button,.dp-panel-action,.dp-panel-filter-trigger,.dp-panel-row-link,summary[role="button"]){box-sizing:border-box;min-height:var(--dp-vs-control-md);border-radius:var(--dp-vs-radius-sm);font:inherit;font-weight:850;line-height:1.1}
:where(.dp-panel,.dp-panel-modal-root) :where(input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]),select,textarea){box-sizing:border-box;width:100%;min-width:0;min-height:var(--dp-vs-control-md);border:1px solid var(--dp-control_border);border-radius:var(--dp-vs-radius-sm);background:var(--dp-control_bg);color:var(--dp-text);font:inherit;line-height:1.35}
.dp-panel-commandbar :where(.dp-panel-search input,.dp-panel-search .dp-panel-button,.dp-panel-filter-trigger,.dp-panel-button,.dp-panel-action,.dp-panel-column-picker>summary){height:48px;min-height:48px;max-height:48px}
.dp-panel-commandbar .dp-panel-filter-trigger{display:inline-flex;align-items:center;justify-content:center;padding-block:0}
.dp-panel .dp-panel-search,.dp-panel-modal-root .dp-panel-search{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:stretch;gap:0;width:100%;min-width:0;max-width:none;margin:0;padding:0;border:1px solid var(--dp-control_border);border-radius:var(--dp-vs-radius-sm);background:var(--dp-control_bg);overflow:hidden}
:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search input[type="search"]{width:100%;min-width:0;height:46px;min-height:46px;border:0;border-radius:0;background:transparent;color:var(--dp-text);box-shadow:none;outline:0;padding-inline:12px}
:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search>button[type="submit"]{height:46px;min-height:46px;margin:0;border-width:0 0 0 1px;border-color:var(--dp-control_border);border-radius:0;box-shadow:none;white-space:nowrap}
:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search>a.dp-panel-button{height:46px;min-height:46px;margin:0;border-width:0 0 0 1px;border-color:var(--dp-control_border);border-radius:0;box-shadow:none;white-space:nowrap}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-search>.dp-panel-search,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-search>.dp-panel-search,.dp-panel .dp-panel-relation>.dp-panel-toolbar>.dp-panel-search{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:stretch;gap:0;border:1px solid var(--dp-control_border);border-radius:var(--dp-vs-radius-sm);background:var(--dp-control_bg);overflow:hidden}
:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search>button[type="submit"].dp-panel-button{margin:0;border-width:0 0 0 1px;border-radius:0}
:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search>button[type="submit"].dp-panel-button+a.dp-panel-button,:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search input[type="search"]+.dp-panel-button+.dp-panel-button{margin:0;border-width:0 0 0 1px;border-radius:0}
:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search:focus-within{border-color:var(--dp-primary-600,#2563eb);box-shadow:var(--dp-vs-focus)}
:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search:focus-within input,:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search input:focus{box-shadow:none}
body :is(.dp-panel,.dp-panel-modal-root) :is(.dp-panel-density,.dp-panel-table-views,.dp-panel-table-groups):not([data-dp-display]){display:flex;align-items:stretch;align-content:center;flex-wrap:wrap;gap:4px;width:max-content;max-width:100%;height:auto;min-height:44px;overflow:visible;overscroll-behavior:auto;border-radius:var(--dp-vs-radius-md);padding:4px}
body :is(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is(.dp-panel-density a,.dp-panel-table-view,.dp-panel-table-group){flex:0 1 auto;min-width:0;white-space:normal;text-align:center}
@media(max-width:1180px){
body :is(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is(.dp-panel-density,.dp-panel-table-views,.dp-panel-table-groups){width:100%}
body :is(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) .dp-panel-density a{flex:1 1 96px}
body :is(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) .dp-panel-table-view{flex:1 1 150px}
body :is(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) .dp-panel-table-group{flex:1 1 112px}
}
:is(.dp-panel,.dp-panel-modal-root) .dp-panel-table tr.dp-panel-empty-row>td.dp-panel-empty{display:table-cell;width:auto;max-width:none;padding:0;border:0;border-radius:0;background:var(--dp-surface);text-align:center}
:is(.dp-panel,.dp-panel-modal-root) .dp-panel-table tr.dp-panel-empty-row>td.dp-panel-empty .dp-panel-empty-state{width:100%;max-width:none;min-height:150px;margin:0;border:0;border-radius:0;background:var(--dp-surface_muted);box-shadow:none;padding:28px}
:is(.dp-panel,.dp-panel-modal-root) .dp-panel-empty-row td:before{display:none}
:is(.dp-panel,.dp-panel-modal-root) .dp-panel-table tfoot td{background:var(--dp-surface_muted);color:var(--dp-text_muted)}
:is(.dp-panel,.dp-panel-modal-root) :is(.dp-panel-checkbox,.dp-panel-field:has(>input[type="checkbox"])){position:relative;display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:52px;border:1px solid var(--dp-border_soft);border-radius:var(--dp-vs-radius-md);background:var(--dp-surface);padding:10px 12px;box-shadow:none}
:is(.dp-panel,.dp-panel-modal-root) .dp-panel-field:has(>input[type="checkbox"])>span{flex:1 1 auto;min-width:0}
:is(.dp-panel,.dp-panel-modal-root) :is(.dp-panel-checkbox input[type="checkbox"],.dp-panel-field>input[type="checkbox"]){appearance:none;-webkit-appearance:none;display:block;box-sizing:border-box;align-self:center;flex:0 0 22px;width:22px;height:22px;min-width:22px;min-height:22px;margin:0;padding:0;border:1.5px solid var(--dp-control_border);border-radius:6px;background-color:var(--dp-control_bg);background-position:center;background-repeat:no-repeat;background-size:15px 15px;box-shadow:none;filter:none;cursor:pointer}
:is(.dp-panel,.dp-panel-modal-root) :is(.dp-panel-checkbox input[type="checkbox"],.dp-panel-field>input[type="checkbox"]):checked{border-color:var(--dp-primary-600,#2563eb);background-color:var(--dp-primary-600,#2563eb);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3E%3Cpath fill='none' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round' d='M4 10l4 4 8-9'/%3E%3C/svg%3E")}
:is(.dp-panel,.dp-panel-modal-root) :is(.dp-panel-checkbox input[type="checkbox"],.dp-panel-field>input[type="checkbox"]):focus-visible{outline:2px solid var(--dp-primary-600,#2563eb);outline-offset:2px;box-shadow:var(--dp-vs-focus)}
:where(.dp-panel,.dp-panel-modal-root) input[type="file"]{min-height:48px;border:1px solid var(--dp-control_border);border-radius:var(--dp-vs-radius-sm);background:var(--dp-control_bg);color:var(--dp-text_muted);padding:5px 8px;line-height:34px;overflow:hidden}
:where(.dp-panel,.dp-panel-modal-root) input[type="file"]::file-selector-button{min-height:34px;margin:-1px 10px -1px -3px;border:1px solid var(--dp-border_soft);border-radius:7px;background:var(--dp-neutral_bg);color:var(--dp-neutral_text);padding:6px 11px;font:inherit;font-weight:850;cursor:pointer}
:where(.dp-panel,.dp-panel-modal-root) input[type="file"]::file-selector-button:hover{border-color:var(--dp-primary-600,#2563eb);background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 12%,var(--dp-neutral_bg))}
body[data-dp-theme-mode="dark"] :where(.dp-panel,.dp-panel-modal-root) :where(input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]),select,textarea),body[data-dp-theme-mode="dark"] :where(.dp-panel,.dp-panel-modal-root) input[type="file"]{color-scheme:dark;border-color:var(--dp-control_border,#40506a);background:var(--dp-control_bg,#111827);color:var(--dp-text,#f8fafc);-webkit-text-fill-color:var(--dp-text,#f8fafc)}
body[data-dp-theme-mode="dark"]:not([data-dp-theme-effects~="glass"]) :where(.dp-panel,.dp-panel-modal-root) :where(input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]),select,textarea):focus{color-scheme:dark;background:var(--dp-control_bg,#111827);color:var(--dp-text,#f8fafc);-webkit-text-fill-color:var(--dp-text,#f8fafc)}
body[data-dp-theme-mode="dark"] :where(.dp-panel,.dp-panel-modal-root) :where(input,textarea)::placeholder{color:var(--dp-text_soft,#95a3b7);-webkit-text-fill-color:var(--dp-text_soft,#95a3b7);opacity:1}
body[data-dp-theme-mode="dark"] :where(.dp-panel,.dp-panel-modal-root) select :where(option,optgroup){background:#111827;color:#f8fafc}
body[data-dp-theme-mode="dark"] :where(.dp-panel,.dp-panel-modal-root) select option:checked{background:#2459b8;color:#fff}
:where(.dp-panel,.dp-panel-modal-root) :where(.dp-panel-action-copy,.dp-panel-action-label){min-inline-size:0;max-inline-size:100%;overflow-wrap:anywhere;white-space:normal}
.dp-panel :is(.dp-panel-actions,.dp-panel-record-actions) :is(.dp-panel-action,.dp-panel-row-link) .dp-panel-action-copy{display:grid;min-inline-size:0;max-inline-size:100%;white-space:normal}
.dp-panel :is(.dp-panel-actions,.dp-panel-record-actions) :is(.dp-panel-action,.dp-panel-row-link) .dp-panel-action-label{display:block;min-inline-size:0;max-inline-size:100%;overflow-wrap:anywhere;white-space:normal}
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar-top{border-bottom:1px solid var(--dp-border_soft)}
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar-bottom{border-top:0;padding-top:0}
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta{border:0;border-radius:0;background:transparent;box-shadow:none;padding:0 2px}
body[data-dp-theme-effects~="glass"] .dp-panel-board-column>header{border-radius:0;box-shadow:none}
body[data-dp-theme-effects~="glass"] .dp-panel-board-card :where(.dp-panel-action,.dp-panel-row-link,.dp-panel-row-more>summary){min-height:44px;border-radius:var(--dp-vs-radius-sm);padding:8px 11px;line-height:1.1}
.dp-panel[data-dp-panel-kind="board"] .dp-panel-board-card .dp-panel-actions .dp-panel-action,.dp-panel[data-dp-panel-kind="board"] .dp-panel-board-card .dp-panel-actions .dp-panel-row-link,.dp-panel[data-dp-panel-kind="board"] .dp-panel-board-card .dp-panel-row-more>summary{height:44px;min-height:44px;border-radius:var(--dp-vs-radius-sm);padding:8px 11px;font-size:12px;line-height:1.1}
.dp-panel[data-dp-panel-kind="board"] .dp-panel-row-more-menu>section,.dp-panel[data-dp-panel-kind="board"] .dp-panel-row-more-menu .dp-panel-inline-action,.dp-panel[data-dp-panel-kind="board"] .dp-panel-row-more-menu form,.dp-panel[data-dp-panel-kind="board"] .dp-panel-row-more-menu .dp-panel-action-group{display:block;width:100%;min-width:0;margin:0}
.dp-panel[data-dp-panel-kind="board"] .dp-panel-row-more-menu .dp-panel-action-menu{width:100%;min-width:0;max-width:100%}
.dp-panel[data-dp-panel-kind="board"] .dp-panel-board-card .dp-panel-row-more-menu .dp-panel-action,.dp-panel[data-dp-panel-kind="board"] .dp-panel-board-card .dp-panel-row-more-menu .dp-panel-row-link,.dp-panel[data-dp-panel-kind="board"] .dp-panel-board-card .dp-panel-row-more-menu .dp-panel-action-group>summary{display:flex;width:100%;min-width:0;height:44px;min-height:44px;margin:0;justify-content:flex-start;text-align:left}
body[data-dp-theme-effects~="glass"] .dp-panel[data-dp-panel-kind="board"] .dp-panel-board-column{border-width:1px}
body[data-dp-theme-effects~="glass"] .dp-panel[data-dp-panel-kind="board"] .dp-panel-board-column:before{content:none}
body[data-dp-theme-effects~="glass"] .dp-panel[data-dp-panel-kind="board"] .dp-panel-row-more-menu{background:color-mix(in srgb,var(--dp-surface) 94%,transparent);border-color:color-mix(in srgb,var(--dp-border) 82%,transparent);backdrop-filter:blur(20px) saturate(1.12);-webkit-backdrop-filter:blur(20px) saturate(1.12)}
.dp-panel[data-dp-panel-kind="board"] .dp-panel-board-card :where(.dp-panel-action-label,.dp-panel-action-copy,.dp-panel-action-group-chevron){color:inherit}
body[data-dp-theme-effects~="glass"] .dp-panel[data-dp-panel-kind="board"] .dp-panel-row-more-menu .dp-panel-action-description{color:color-mix(in srgb,currentColor 72%,transparent)}
.dp-panel .dp-panel-column-picker:not([open])>form,.dp-panel .dp-panel-action-group:not([open])>.dp-panel-action-menu,.dp-panel .dp-panel-saved-view-menu:not([open])>div,.dp-panel .dp-panel-row-more:not([open])>.dp-panel-row-more-menu,.dp-panel .dp-panel-horizontal-group:not([open])>div{display:none}
body :where(.dp-panel[data-dp-panel-kind]) [data-dp-display]{--dp-collection-gap:var(--dp-vs-space-3,10px);--dp-collection-columns-active:var(--dp-collection-columns,1);min-width:0;max-width:100%}
body .dp-panel[data-dp-panel-kind] [data-dp-gap="none"]{--dp-collection-gap:0}
body .dp-panel[data-dp-panel-kind] [data-dp-gap="compact"]{--dp-collection-gap:var(--dp-vs-space-2,6px)}
body .dp-panel[data-dp-panel-kind] [data-dp-gap="roomy"]{--dp-collection-gap:var(--dp-vs-space-5,18px)}
body .dp-panel[data-dp-panel-kind] [data-dp-display="inline"],body .dp-panel[data-dp-panel-kind] [data-dp-display="segmented"]{display:flex;align-items:stretch;align-content:center;flex-wrap:wrap;gap:var(--dp-collection-gap);width:100%;max-width:100%;height:auto;overflow:visible}
body .dp-panel[data-dp-panel-kind] [data-dp-display="stack"]{display:grid;grid-template-columns:minmax(0,1fr);gap:var(--dp-collection-gap);width:100%;height:auto;overflow:visible}
body .dp-panel[data-dp-panel-kind] [data-dp-display="grid"],body .dp-panel[data-dp-panel-kind] [data-dp-display="brick"]{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,var(--dp-collection-min,180px)),1fr));gap:var(--dp-collection-gap);align-items:stretch;width:100%;height:auto;overflow:visible;border-radius:var(--dp-vs-radius-md)}
body .dp-panel[data-dp-panel-kind] [data-dp-display="grid"][data-dp-fit="fixed"],body .dp-panel[data-dp-panel-kind] [data-dp-display="brick"][data-dp-fit="fixed"]{grid-template-columns:repeat(var(--dp-collection-columns-active),minmax(0,1fr))}
body .dp-panel[data-dp-panel-kind] [data-dp-display="masonry"]{display:block;width:100%;height:auto;columns:var(--dp-collection-columns-active) var(--dp-collection-min,220px);column-gap:var(--dp-collection-gap);overflow:visible}
body .dp-panel[data-dp-panel-kind] [data-dp-display="masonry"]>*{break-inside:avoid;margin:0 0 var(--dp-collection-gap)}
body .dp-panel[data-dp-panel-kind] :is(.dp-panel-table-views,.dp-panel-table-groups,.dp-panel-tab-list,.dp-panel-step-list)[data-dp-display="brick"]{border:0;background:transparent;box-shadow:none;padding:0}
body .dp-panel[data-dp-panel-kind] :is(.dp-panel-table-views,.dp-panel-table-groups)[data-dp-display="brick"]>:is(a,button),body .dp-panel[data-dp-panel-kind] :is(.dp-panel-tab-list,.dp-panel-step-list)[data-dp-display="brick"]>button{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;min-width:0;min-height:58px;border:1px solid var(--dp-border_soft);border-radius:var(--dp-vs-radius-md);background:var(--dp-surface);color:var(--dp-text);padding:12px 14px;text-align:left;white-space:normal;box-shadow:var(--dp-vs-surface-shadow);transition:border-color .14s ease,background .14s ease,box-shadow .14s ease,transform .14s ease}
body .dp-panel[data-dp-panel-kind] :is(.dp-panel-table-views,.dp-panel-table-groups)[data-dp-display="brick"]>:is(a,button):hover,body .dp-panel[data-dp-panel-kind] :is(.dp-panel-tab-list,.dp-panel-step-list)[data-dp-display="brick"]>button:hover{transform:translateY(-1px);border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 38%,var(--dp-border));background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 38%,var(--dp-surface));box-shadow:var(--dp-present-shadow-soft)}
body .dp-panel[data-dp-panel-kind] :is(.dp-panel-table-views,.dp-panel-table-groups)[data-dp-display="brick"]>.active,body .dp-panel[data-dp-panel-kind] .dp-panel-tab-list[data-dp-display="brick"]>button[aria-selected="true"],body .dp-panel[data-dp-panel-kind] .dp-panel-step-list[data-dp-display="brick"]>button[aria-current="step"]{border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 58%,var(--dp-border));background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 15%,var(--dp-surface));color:var(--dp-text);box-shadow:inset 3px 0 0 var(--dp-primary-600,#2563eb),var(--dp-present-shadow-soft)}
body .dp-panel[data-dp-panel-kind] [data-dp-display="brick"]>.dp-panel-summary{height:100%;min-height:104px}
body .dp-panel[data-dp-panel-kind] .dp-panel-choice-list[data-dp-display="brick"]>.dp-panel-choice{align-items:flex-start;min-height:64px;height:100%;border-color:var(--dp-border);background:var(--dp-surface);padding:12px 14px;box-shadow:var(--dp-vs-surface-shadow);transition:border-color .14s ease,background .14s ease,box-shadow .14s ease,transform .14s ease}
body .dp-panel[data-dp-panel-kind] .dp-panel-choice-list[data-dp-display="brick"]>.dp-panel-choice:hover{transform:translateY(-1px);border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 38%,var(--dp-border));box-shadow:var(--dp-present-shadow-soft)}
body .dp-panel[data-dp-panel-kind] .dp-panel-choice-list[data-dp-display="brick"]>.dp-panel-choice:has(input:checked){border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 58%,var(--dp-border));background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 12%,var(--dp-surface));box-shadow:inset 3px 0 0 var(--dp-primary-600,#2563eb),var(--dp-present-shadow-soft)}
body .dp-panel[data-dp-panel-kind] .dp-panel-choice-list[data-dp-display="brick"]>.dp-panel-choice small{display:block;margin-top:3px;color:var(--dp-text_muted);font-size:12px;font-weight:600;line-height:1.35}
body .dp-panel[data-dp-panel-kind] [data-dp-display="brick"]>.dp-panel-filter{min-width:0;height:100%;border:1px solid var(--dp-border_soft);border-radius:var(--dp-vs-radius-md);background:var(--dp-surface);padding:12px;box-shadow:var(--dp-vs-surface-shadow)}
:is(.dp-panel,.dp-panel-modal-root) [data-dp-display="brick"]>:is(a,button,form,details,.dp-panel-inline-action){width:100%;min-width:0;margin:0}:where(.dp-panel,.dp-panel-modal-root) :is([data-dp-display="brick"],[data-dp-fit="fill"],[data-dp-display="masonry"][data-dp-masonry="rows"]) :is(.dp-panel-action,.dp-panel-button){width:100%;min-width:0;height:100%;min-height:52px;white-space:normal}
body .dp-panel[data-dp-panel-kind] [data-dp-density="compact"][data-dp-display="brick"]>:is(a,button,.dp-panel-summary,.dp-panel-filter){min-height:48px;padding:9px 11px}
body .dp-panel[data-dp-panel-kind] [data-dp-density="roomy"][data-dp-display="brick"]>:is(a,button,.dp-panel-summary,.dp-panel-filter){min-height:72px;padding:16px 18px}
body .dp-panel-modal-root [data-dp-display]{--dp-collection-gap:var(--dp-vs-space-3,10px);--dp-collection-columns-active:var(--dp-collection-columns,1);--dp-collection-basis-active:var(--dp-collection-basis,var(--dp-collection-min,180px));min-width:0;max-width:100%}
body .dp-panel-modal-root [data-dp-gap="none"]{--dp-collection-gap:0}
body .dp-panel-modal-root [data-dp-gap="compact"]{--dp-collection-gap:var(--dp-vs-space-2,6px)}
body .dp-panel-modal-root [data-dp-gap="roomy"]{--dp-collection-gap:var(--dp-vs-space-5,18px)}
body .dp-panel-modal-root :is([data-dp-display="inline"],[data-dp-display="segmented"]){display:flex;align-items:stretch;align-content:center;flex-wrap:wrap;gap:var(--dp-collection-gap);width:100%;max-width:100%;height:auto;overflow:visible}
body .dp-panel-modal-root [data-dp-display="stack"]{display:grid;grid-template-columns:minmax(0,1fr);gap:var(--dp-collection-gap);width:100%;height:auto;overflow:visible}
body .dp-panel-modal-root :is([data-dp-display="grid"],[data-dp-display="brick"]){display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,var(--dp-collection-min,180px)),1fr));gap:var(--dp-collection-gap);align-items:stretch;width:100%;height:auto;overflow:visible}
body .dp-panel-modal-root :is([data-dp-display="grid"],[data-dp-display="brick"])[data-dp-fit="fixed"]{grid-template-columns:repeat(var(--dp-collection-columns-active),minmax(0,1fr))}
body .dp-panel-modal-root [data-dp-display="masonry"][data-dp-masonry="columns"]{display:block;width:100%;height:auto;columns:var(--dp-collection-columns-active) var(--dp-collection-min,220px);column-gap:var(--dp-collection-gap);overflow:visible}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-display="masonry"][data-dp-masonry="columns"][data-dp-columns="auto"]{columns:auto var(--dp-collection-min,220px)}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-display="masonry"][data-dp-masonry="columns"]>*{break-inside:avoid;margin:0 0 var(--dp-collection-gap)}
body .dp-panel[data-dp-panel-kind] :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-display="grid"][data-dp-fit="fill"],[data-dp-display="brick"][data-dp-fit="fill"]),body .dp-panel-modal-root :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-display="grid"][data-dp-fit="fill"],[data-dp-display="brick"][data-dp-fit="fill"]){display:flex;flex-flow:row wrap;align-items:stretch;align-content:flex-start;gap:var(--dp-collection-gap);width:100%;height:auto;columns:auto;overflow:visible}
body .dp-panel[data-dp-panel-kind] :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-display="grid"][data-dp-fit="fill"],[data-dp-display="brick"][data-dp-fit="fill"])>*,body .dp-panel-modal-root :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-display="grid"][data-dp-fit="fill"],[data-dp-display="brick"][data-dp-fit="fill"])>*{flex:var(--dp-item-fill-grow-active,var(--dp-item-grow-active,var(--dp-item-grow,1))) var(--dp-item-shrink-active,var(--dp-item-shrink,1)) var(--dp-item-basis-active,var(--dp-collection-basis-active,var(--dp-collection-min,180px)));min-width:min(100%,var(--dp-item-min-active,var(--dp-collection-min,180px)));max-width:var(--dp-item-max-active,100%);margin:0;break-inside:auto}
body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-fit="fill"])>.dp-panel-inline-action{height:auto;min-height:52px;align-self:stretch}
body .dp-panel[data-dp-panel-kind] :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-display="grid"][data-dp-fit="fill"],[data-dp-display="brick"][data-dp-fit="fill"])[data-dp-fit="fixed"]>*,body .dp-panel-modal-root :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-display="grid"][data-dp-fit="fill"],[data-dp-display="brick"][data-dp-fit="fill"])[data-dp-fit="fixed"]>*{flex-grow:0}
@media(min-width:640px){body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-display]{--dp-collection-basis-active:var(--dp-collection-basis-sm,var(--dp-collection-basis,var(--dp-collection-min,180px)));--dp-collection-columns-active:var(--dp-collection-columns-sm,var(--dp-collection-columns,1))}}
@media(min-width:768px){body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-display]{--dp-collection-basis-active:var(--dp-collection-basis-md,var(--dp-collection-basis-sm,var(--dp-collection-basis,var(--dp-collection-min,180px))));--dp-collection-columns-active:var(--dp-collection-columns-md,var(--dp-collection-columns-sm,var(--dp-collection-columns,1)))}}
@media(min-width:1024px){body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-display]{--dp-collection-basis-active:var(--dp-collection-basis-lg,var(--dp-collection-basis-md,var(--dp-collection-basis-sm,var(--dp-collection-basis,var(--dp-collection-min,180px)))));--dp-collection-columns-active:var(--dp-collection-columns-lg,var(--dp-collection-columns-md,var(--dp-collection-columns-sm,var(--dp-collection-columns,1))))}}
@media(min-width:1280px){body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-display]{--dp-collection-basis-active:var(--dp-collection-basis-xl,var(--dp-collection-basis-lg,var(--dp-collection-basis-md,var(--dp-collection-basis-sm,var(--dp-collection-basis,var(--dp-collection-min,180px))))));--dp-collection-columns-active:var(--dp-collection-columns-xl,var(--dp-collection-columns-lg,var(--dp-collection-columns-md,var(--dp-collection-columns-sm,var(--dp-collection-columns,1)))))}}
@media(min-width:1536px){body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-display]{--dp-collection-basis-active:var(--dp-collection-basis-2xl,var(--dp-collection-basis-xl,var(--dp-collection-basis-lg,var(--dp-collection-basis-md,var(--dp-collection-basis-sm,var(--dp-collection-basis,var(--dp-collection-min,180px)))))));--dp-collection-columns-active:var(--dp-collection-columns-2xl,var(--dp-collection-columns-xl,var(--dp-collection-columns-lg,var(--dp-collection-columns-md,var(--dp-collection-columns-sm,var(--dp-collection-columns,1))))))}}
@media(max-width:639px){body :is(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) [data-dp-display]{--dp-collection-columns-active:1}}
@media(max-width:639px){body :where(.dp-panel[data-dp-panel-kind],.dp-panel-modal-root) :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-display="grid"][data-dp-fit="fill"],[data-dp-display="brick"][data-dp-fit="fill"]){--dp-collection-basis-active:100%;--dp-collection-columns-active:1}body .dp-panel[data-dp-panel-kind] :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-display="grid"][data-dp-fit="fill"],[data-dp-display="brick"][data-dp-fit="fill"])>*,body .dp-panel-modal-root :is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-display="grid"][data-dp-fit="fill"],[data-dp-display="brick"][data-dp-fit="fill"])>*{flex-basis:100%;min-width:0}}
@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"] :where(.dp-panel,.dp-panel-modal-root) :where(input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]),select,textarea),body[data-dp-theme-mode="system"] :where(.dp-panel,.dp-panel-modal-root) input[type="file"]{color-scheme:dark;border-color:#40506a;background:#111827;color:#f8fafc;-webkit-text-fill-color:#f8fafc}body[data-dp-theme-mode="system"]:not([data-dp-theme-effects~="glass"]) .dp-panel input:where(:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"])):focus,body[data-dp-theme-mode="system"]:not([data-dp-theme-effects~="glass"]) .dp-panel select:focus,body[data-dp-theme-mode="system"]:not([data-dp-theme-effects~="glass"]) .dp-panel textarea:focus,body[data-dp-theme-mode="system"]:not([data-dp-theme-effects~="glass"]) .dp-panel-modal-root input:where(:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"])):focus,body[data-dp-theme-mode="system"]:not([data-dp-theme-effects~="glass"]) .dp-panel-modal-root select:focus,body[data-dp-theme-mode="system"]:not([data-dp-theme-effects~="glass"]) .dp-panel-modal-root textarea:focus{color-scheme:dark;background:#111827;color:#f8fafc;-webkit-text-fill-color:#f8fafc}body[data-dp-theme-mode="system"] :where(.dp-panel,.dp-panel-modal-root) :where(input,textarea)::placeholder{color:#95a3b7;-webkit-text-fill-color:#95a3b7;opacity:1}body[data-dp-theme-mode="system"] :where(.dp-panel,.dp-panel-modal-root) select :where(option,optgroup){background:#111827;color:#f8fafc}body[data-dp-theme-mode="system"] .dp-panel-modal-expand{color:#dbeafe}}
@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"] :where(.dp-panel,.dp-panel-modal-root) select option:checked{background:#2459b8;color:#fff}}
@media(max-width:1024px){:is(.dp-panel,.dp-panel-modal-root) .dp-panel-table tr.dp-panel-empty-row{display:grid;grid-column:1/-1;width:100%;overflow:visible}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-table tr.dp-panel-empty-row>td.dp-panel-empty{display:grid;grid-template-columns:1fr;place-items:center;width:100%;grid-column:1/-1}}
@media(max-width:1024px){:is(.dp-panel,.dp-panel-modal-root) .dp-panel-table td{min-width:0;max-width:100%;overflow-wrap:anywhere;word-break:break-word}}
@media(max-width:1024px){body .dp-panel .dp-panel-commandbar-actions>.dp-panel-column-picker[open]{display:grid;grid-column:1/-1}.dp-panel-column-picker[open]>form{min-width:0}}
@media(max-width:760px){:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search,.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-search>.dp-panel-search,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-search>.dp-panel-search,.dp-panel .dp-panel-relation>.dp-panel-toolbar>.dp-panel-search{grid-template-columns:minmax(0,1fr) auto;gap:0}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search>a.dp-panel-button{grid-column:1/-1;width:100%;margin:0;border-width:1px 0 0;border-radius:0}}
@media(max-width:780px){.dp-panel[data-dp-panel-kind="dashboard"] .dp-panel-custom-page,.dp-panel[data-dp-panel-kind="custom_page"] .dp-panel-custom-page{grid-template-columns:minmax(0,1fr)}}
@media(max-width:400px){.dp-panel-main-region{width:auto;max-width:100%;margin-inline:0}.dp-panel-tabs,.dp-panel-steps,.dp-panel-tab-panel,.dp-panel-step-panel{min-width:0;max-width:100%}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search,.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-search>.dp-panel-search,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-search>.dp-panel-search,.dp-panel .dp-panel-relation>.dp-panel-toolbar>.dp-panel-search{grid-template-columns:minmax(0,1fr)}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search input[type="search"],:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search>button[type="submit"],:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search>a.dp-panel-button{grid-column:1;width:100%;min-width:0;border-width:0;border-radius:0}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search>button[type="submit"],:is(.dp-panel,.dp-panel-modal-root) .dp-panel-search>a.dp-panel-button{border-top:1px solid var(--dp-control_border)}}
@media(max-width:220px){.dp-panel-modal-root{padding:4px}.dp-panel-modal{--dp-modal-pad:8px;border-radius:12px}.dp-panel-modal-header{min-width:0;padding:8px 52px 8px 8px}.dp-panel-modal-title,.dp-panel-modal-title h2,.dp-panel-modal-title p{min-width:0;max-width:100%;overflow-wrap:anywhere}.dp-panel-modal-body{min-width:0;max-width:100%;padding:var(--dp-modal-pad)}.dp-panel-modal-body:before,.dp-panel-modal-body:after{margin-inline:calc(var(--dp-modal-pad) * -1)}.dp-panel-modal-body>div{width:100%;min-width:0;max-width:100%}.dp-panel-filter-modal-content{display:grid;grid-template-columns:minmax(0,1fr);gap:6px;width:100%;min-width:0;max-width:100%}.dp-panel-filter-modal-content .dp-panel-filters,.dp-panel-filter-modal-content .dp-panel-filter-chips{box-sizing:border-box;width:100%;min-width:0;max-width:100%;padding:6px}.dp-panel-filter-modal-content .dp-panel-filter-chip{display:flex;flex-wrap:wrap;width:100%;min-width:0;max-width:100%;gap:3px;padding:5px 6px}.dp-panel-filter-modal-content .dp-panel-filter-chip>*{min-width:0;max-width:100%;overflow-wrap:anywhere}.dp-panel-filter-modal-content :is(.dp-panel-filter,.dp-panel-filter-range,.dp-panel-filters>.dp-panel-button){grid-column:1;width:100%;min-width:0;max-width:100%}.dp-panel-modal-actions,.dp-panel-modal-form-actions{display:grid;grid-template-columns:minmax(0,1fr);gap:6px}.dp-panel-modal-actions>*,.dp-panel-modal-form-actions>*{width:100%;min-width:0;max-width:100%;padding-inline:6px}}
@container dp-panel-shell (max-width:1180px){
.dp-panel[data-dp-panel-navigation-layout="sidebar"]>.dp-panel-main-region{grid-column:1/-1;inline-size:100cqi;max-inline-size:100cqi;min-width:0;padding:16px}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"]>.dp-panel-sidebar{position:fixed;inset:0 auto 0 0;z-index:1090;display:block;width:min(326px,88cqi);max-width:min(326px,88cqi);height:100dvh;max-height:100dvh;margin:0;overflow:auto;overscroll-behavior:contain;transform:translateX(-104%);transition:transform .18s ease;border:0;border-right:1px solid var(--dp-border_soft);border-radius:0;background:var(--dp-surface);box-shadow:0 24px 70px rgba(15,23,42,.2);padding:16px}
[dir="rtl"] .dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"]>.dp-panel-sidebar{inset:0 0 0 auto;transform:translateX(104%);border-right:0;border-left:1px solid var(--dp-border_soft)}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"].dp-panel-mobile-nav-open>.dp-panel-sidebar{transform:translateX(0)}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-mobile-nav-toggle{display:inline-grid;place-items:center;position:relative;width:44px;min-width:44px;height:44px;min-height:44px;margin:0;border:1px solid var(--dp-border);border-radius:10px;background:var(--dp-surface);color:var(--dp-text);box-shadow:none}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"]>.dp-panel-mobile-nav-backdrop{display:none;position:fixed;inset:0;z-index:1080;border:0;background:rgba(15,23,42,.42)}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"].dp-panel-mobile-nav-open>.dp-panel-mobile-nav-backdrop{display:block}
.dp-panel[data-dp-panel-navigation-layout="sidebar"]>.dp-panel-main-region>header{display:grid;grid-template-columns:auto minmax(0,1fr);align-items:center;column-gap:10px;row-gap:8px}
.dp-panel[data-dp-panel-navigation-layout="sidebar"]>.dp-panel-main-region>header>.dp-panel-mobile-nav-toggle{grid-column:1;grid-row:1}
.dp-panel[data-dp-panel-navigation-layout="sidebar"]>.dp-panel-main-region>header>.dp-panel-breadcrumbs{grid-column:2;grid-row:1;min-width:0;margin:0}
.dp-panel[data-dp-panel-navigation-layout="sidebar"]>.dp-panel-main-region>header>:is(.dp-panel-brand,.dp-panel-heading-row){grid-column:1/-1;min-width:0}
.dp-panel :where(.dp-panel-button,.dp-panel-action,.dp-panel-filter-trigger,.dp-panel-row-link,.dp-panel-action-group>summary,.dp-panel-row-more>summary,.dp-panel-saved-view-menu>summary,.dp-panel-column-picker>summary,.dp-panel-table-view,.dp-panel-density>a){min-width:44px;min-height:44px;height:auto}
.dp-panel :where(input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]),select,textarea){min-height:44px}
}
@container dp-panel-shell (max-width:1024px){
.dp-panel[data-dp-panel-navigation-layout="sidebar"]>.dp-panel-main-region{gap:12px}
.dp-panel[data-dp-panel-navigation-layout="sidebar"]>.dp-panel-main-region>header{padding:14px}
.dp-panel :is(.dp-panel-commandbar,.dp-panel-commandbar-top,.dp-panel-commandbar-bottom,.dp-panel-commandbar-search,.dp-panel-commandbar-primary,.dp-panel-commandbar-view,.dp-panel-commandbar-utility,.dp-panel-commandbar-actions,.dp-panel-commandbar-groups,.dp-panel-toolbar,.dp-panel-table-toolbar,.dp-panel-form-toolbar,.dp-panel-page-table>header,.dp-panel-record-heading,.dp-panel-relation-header){width:100%;min-width:0;max-width:100%}
.dp-panel :is(.dp-panel-commandbar,.dp-panel-toolbar,.dp-panel-table-toolbar,.dp-panel-form-toolbar,.dp-panel-page-table>header,.dp-panel-record-heading,.dp-panel-relation-header){display:grid;grid-template-columns:minmax(0,1fr);gap:10px}
.dp-panel .dp-panel-relation .dp-panel-relation-aside{width:100%;min-width:0;max-width:100%;justify-items:start}
.dp-panel .dp-panel-relation .dp-panel-relation-meta{width:100%;min-width:0;max-width:100%;justify-content:flex-start}
.dp-panel :is(.dp-panel-commandbar-top,.dp-panel-commandbar-bottom,.dp-panel-commandbar-search,.dp-panel-commandbar-view,.dp-panel-commandbar-utility){display:grid;grid-template-columns:minmax(0,1fr);gap:10px}
.dp-panel .dp-panel-commandbar-primary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
body .dp-panel[data-dp-panel-kind] :is(.dp-panel-commandbar-top,.dp-panel-commandbar-bottom,.dp-panel-commandbar-search,.dp-panel-commandbar-view,.dp-panel-commandbar-utility){display:grid;grid-template-columns:minmax(0,1fr);width:100%;min-width:0;max-width:100%;gap:10px}
body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar-search>.dp-panel-search{display:grid;grid-template-columns:minmax(0,1fr);grid-column:1;width:100%;min-width:0;max-width:100%;height:auto}
body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar-search>.dp-panel-filter-panel{grid-column:1;width:100%;min-width:0;max-width:100%;height:auto}
body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar-search>.dp-panel-filter-panel>summary{width:100%;min-width:0}
body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar :is(.dp-panel-density,.dp-panel-table-views,.dp-panel-table-groups){display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,72px),1fr));width:100%;min-width:0;max-width:100%;height:auto;max-height:none;overflow:visible}
body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar :is(.dp-panel-density,.dp-panel-table-views,.dp-panel-table-groups)>*{width:100%;min-width:0;max-width:100%;min-height:44px;white-space:normal}
.dp-panel :is(.dp-panel-commandbar-primary,.dp-panel-commandbar-utility,.dp-panel-commandbar-actions,.dp-panel-toolbar-actions,.dp-panel-actions,.dp-panel-page-actions,.dp-panel-record-actions){width:100%;min-width:0}
.dp-panel :is(.dp-panel-commandbar-actions,.dp-panel-toolbar-actions,.dp-panel-actions,.dp-panel-page-actions,.dp-panel-record-actions){display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-items:stretch;gap:8px}
.dp-panel :is(.dp-panel-commandbar-actions,.dp-panel-toolbar-actions,.dp-panel-actions,.dp-panel-page-actions,.dp-panel-record-actions)>*{min-width:0;max-width:100%}
.dp-panel :is(.dp-panel-commandbar-actions,.dp-panel-toolbar-actions,.dp-panel-actions,.dp-panel-page-actions,.dp-panel-record-actions) :is(.dp-panel-button,.dp-panel-action,.dp-panel-row-link){width:100%;min-width:0;max-width:100%;white-space:normal}
.dp-panel .dp-panel-relation>.dp-panel-toolbar>.dp-panel-toolbar-actions{--dp-relation-per-page-columns:minmax(0,1fr) auto;--dp-relation-per-page-button-width:auto;display:grid;grid-template-columns:minmax(0,1fr);width:100%;min-width:0;max-width:100%}
.dp-panel .dp-panel-relation>.dp-panel-toolbar .dp-panel-per-page{display:grid;grid-template-columns:var(--dp-relation-per-page-columns);width:100%;min-width:0;max-width:100%;gap:8px}
.dp-panel .dp-panel-relation>.dp-panel-toolbar .dp-panel-per-page label{display:grid;grid-template-columns:auto minmax(0,1fr);width:100%;min-width:0;max-width:100%}
.dp-panel .dp-panel-relation>.dp-panel-toolbar .dp-panel-per-page select{width:100%;min-width:0;max-width:100%}
.dp-panel .dp-panel-relation>.dp-panel-toolbar .dp-panel-per-page .dp-panel-button{width:var(--dp-relation-per-page-button-width);min-width:0;max-width:100%;margin:0}
.dp-panel :is(.dp-panel-search,.dp-panel-global-search,.dp-panel-table-views,.dp-panel-density,.dp-panel-column-picker,.dp-panel-saved-view-menu,.dp-panel-bulk-form){width:100%;min-width:0;max-width:100%}
.dp-panel :is(.dp-panel-table-views,.dp-panel-density,.dp-panel-commandbar-groups,.dp-panel-tab-list,.dp-panel-step-list){display:flex;flex-wrap:wrap;height:auto;max-height:none;overflow:visible}
.dp-panel :is(.dp-panel-table-views,.dp-panel-density,.dp-panel-commandbar-groups)>*{flex:1 1 120px;min-width:0;max-width:100%}
.dp-panel :is(.dp-panel-widgets,.dp-panel-summaries,.dp-panel-insights,.dp-panel-custom-page,.dp-panel-show-grid){grid-template-columns:minmax(0,1fr)}
.dp-panel[data-dp-panel-kind="dashboard"] .dp-panel-custom-page>*,.dp-panel[data-dp-panel-kind="custom_page"] .dp-panel-custom-page>*{grid-column:1/-1;min-width:0}
.dp-panel[data-dp-panel-kind="board"] .dp-panel-board{grid-template-columns:minmax(0,1fr)}
.dp-panel .dp-panel-board-column{width:100%;min-width:0;max-width:100%}
.dp-panel .dp-panel-column-picker form,.dp-panel .dp-panel-action-menu,.dp-panel .dp-panel-row-more-menu,.dp-panel .dp-panel-saved-view-menu>div{position:static;inset:auto;width:100%;min-width:0;max-width:100%;margin-top:8px;box-shadow:none}
.dp-panel .dp-panel-column-picker form>*{min-width:0;max-width:100%}
}
@container dp-panel-shell (max-width:640px){
.dp-panel[data-dp-panel-navigation-layout="sidebar"]>.dp-panel-main-region{padding:12px}
.dp-panel[data-dp-panel-navigation-layout="sidebar"]>.dp-panel-main-region>header{grid-template-columns:auto minmax(0,1fr);padding:12px}
.dp-panel[data-dp-panel-navigation-layout="sidebar"]>.dp-panel-main-region>header>.dp-panel-heading-row{grid-column:1/-1;grid-row:2}
.dp-panel :is(.dp-panel-commandbar-actions,.dp-panel-toolbar-actions,.dp-panel-actions,.dp-panel-page-actions,.dp-panel-record-actions){grid-template-columns:minmax(0,1fr)}
.dp-panel .dp-panel-commandbar-primary{grid-template-columns:minmax(0,1fr)}
.dp-panel :is(.dp-panel-column-actions,.dp-panel-column-footer){display:grid;grid-template-columns:minmax(0,1fr);gap:8px}
.dp-panel :is(.dp-panel-column-actions,.dp-panel-column-footer)>*{width:100%;min-width:0}
.dp-panel .dp-panel-page-table{padding:12px}
.dp-panel .dp-panel-table-scroll{overflow:visible;border:0;background:transparent;box-shadow:none}
.dp-panel .dp-panel-table{display:grid;width:100%;min-width:0;border:0;background:transparent;box-shadow:none}
.dp-panel .dp-panel-table thead{display:none}
.dp-panel .dp-panel-table tbody{display:grid;gap:10px;min-width:0}
.dp-panel .dp-panel-table tr{display:grid;position:relative;width:100%;min-width:0;gap:0;border:1px solid var(--dp-border_soft);border-radius:16px;background:var(--dp-surface);overflow:hidden}
.dp-panel .dp-panel-table td{display:grid;grid-template-columns:minmax(0,1fr);gap:4px;width:100%;min-width:0;max-width:100%;border:0;border-bottom:1px solid var(--dp-border_soft);padding:9px 10px;white-space:normal;overflow-wrap:anywhere;word-break:break-word}
.dp-panel .dp-panel-table td:last-child{border-bottom:0}
.dp-panel .dp-panel-table td:before{content:attr(data-label);min-width:0;color:var(--dp-text_muted);font-size:9px;font-weight:950;letter-spacing:.05em;text-transform:uppercase}
.dp-panel .dp-panel-table td>*{min-width:0;max-width:100%;overflow-wrap:anywhere}
.dp-panel .dp-panel-table td.dp-panel-select{position:absolute;inset:auto;inset-block-start:10px;inset-inline-end:10px;width:max-content;min-width:0;max-width:44px;padding:0;border:0;z-index:2}
.dp-panel .dp-panel-table td.dp-panel-select:before{content:none;display:none}
.dp-panel .dp-panel-table td.dp-panel-select+td{padding-inline-end:52px}
.dp-panel .dp-panel-table td.dp-panel-select+td>*{margin-inline-end:10px}
.dp-panel .dp-panel-table td.dp-panel-actions{display:flex;gap:7px;flex-wrap:wrap}
.dp-panel .dp-panel-table td.dp-panel-actions:before{flex:1 0 100%;margin:0}
.dp-panel .dp-panel-table td.dp-panel-actions>*{flex:1 1 82px;min-width:82px;margin:0}
.dp-panel .dp-panel-table td.dp-panel-actions>:is(.dp-panel-row-more,.dp-panel-action-group){display:grid;grid-template-columns:minmax(0,1fr);justify-items:stretch}
.dp-panel .dp-panel-table td.dp-panel-actions>:is(.dp-panel-row-more,.dp-panel-action-group)[open]{flex-basis:100%}
}
@container dp-panel-shell (max-width:400px){
.dp-panel :is(.dp-panel-search,.dp-panel-global-search,.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-search>.dp-panel-search,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-search>.dp-panel-search){display:grid;grid-template-columns:minmax(0,1fr)}
.dp-panel :is(.dp-panel-search,.dp-panel-global-search)>:is(input[type="search"],button[type="submit"],a.dp-panel-button){grid-column:1;width:100%;min-width:0;border-width:0;border-radius:0}
.dp-panel :is(.dp-panel-search,.dp-panel-global-search)>:is(button[type="submit"],a.dp-panel-button){border-top:1px solid var(--dp-control_border)}
body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar-search>.dp-panel-search{grid-template-columns:minmax(0,1fr)}
body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar-search>.dp-panel-search>:is(input[type="search"],button[type="submit"],a.dp-panel-button){display:flex;grid-column:1;width:100%;min-width:44px;max-width:100%;border-width:0;border-radius:0}
body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar-search>.dp-panel-search>:is(button[type="submit"],a.dp-panel-button){border-top:1px solid var(--dp-control_border)}
}
@container dp-panel-shell (max-width:220px){
.dp-panel[data-dp-panel-navigation-layout="sidebar"]>.dp-panel-main-region{gap:6px;padding:4px}
.dp-panel[data-dp-panel-navigation-layout="sidebar"]>.dp-panel-main-region>header{column-gap:6px;row-gap:6px;padding:8px}
.dp-panel .dp-panel-heading-row h1{font-size:20px;line-height:1.08}
.dp-panel :is(.dp-panel-commandbar,.dp-panel-toolbar,.dp-panel-table-toolbar,.dp-panel-form-toolbar,.dp-panel-page-table,.dp-panel-record-heading,.dp-panel-relation,.dp-panel-card,.dp-panel-form-section,.dp-panel-tab-panel,.dp-panel-step-panel){min-width:0;max-width:100%;padding:6px}
.dp-panel :is(.dp-panel-form,.dp-panel-tabs,.dp-panel-steps,.dp-panel-tab-panel,.dp-panel-step-panel,.dp-panel-form-grid){width:100%;min-width:0;max-width:100%}
.dp-panel :where(h1,h2,h3,p){min-width:0;max-width:100%;overflow-wrap:anywhere;word-break:break-word}
body .dp-panel[data-dp-panel-kind] .dp-panel-custom-page{grid-template-columns:minmax(0,1fr);width:100%;min-width:0;max-width:100%;gap:6px}
body .dp-panel[data-dp-panel-kind] .dp-panel-custom-page>*{grid-column:1;grid-row:auto;width:100%;min-width:0;max-width:100%;margin-inline:0}
body .dp-panel[data-dp-panel-kind] :is(.dp-panel-commandbar-top,.dp-panel-commandbar-bottom,.dp-panel-commandbar-primary,.dp-panel-commandbar-view,.dp-panel-commandbar-utility,.dp-panel-commandbar-actions){display:grid;grid-template-columns:minmax(0,1fr);width:100%;min-width:0;max-width:100%;gap:6px}
body .dp-panel[data-dp-panel-kind] :is(.dp-panel-commandbar-primary,.dp-panel-commandbar-actions)>:is(.dp-panel-inline-action,.dp-panel-button,.dp-panel-action-group,.dp-panel-column-picker){display:block;width:100%;min-width:0;max-width:100%;height:auto}
body .dp-panel[data-dp-panel-kind] :is(.dp-panel-commandbar-primary,.dp-panel-commandbar-actions) :is(.dp-panel-button,.dp-panel-action,.dp-panel-action-group>summary,.dp-panel-column-picker>summary){width:100%;min-width:0;max-width:100%;height:auto;padding-inline:4px;white-space:normal}
body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar-view .dp-panel-per-page,body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar-view .dp-panel-per-page label{display:grid;grid-template-columns:minmax(0,1fr);width:100%;min-width:0;max-width:100%;gap:4px}
body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar-view .dp-panel-per-page :is(span,select,.dp-panel-button){width:100%;min-width:0;max-width:100%;white-space:normal}
body .dp-panel[data-dp-panel-kind] .dp-panel-commandbar-view .dp-panel-per-page select{padding-inline:6px 20px}
body .dp-panel[data-dp-panel-kind] .dp-panel-table-group-row button{grid-template-columns:minmax(0,1fr);width:100%;min-width:0;max-width:100%;gap:5px;padding:6px}
body .dp-panel[data-dp-panel-kind] .dp-panel-table-group-row button>:is(span:first-child,small,.dp-panel-table-group-chips,i){grid-column:1;grid-row:auto;justify-self:start;min-width:0;max-width:100%;margin-inline:0;white-space:normal}
body .dp-panel[data-dp-panel-kind] :is(.dp-panel-widget-label,.dp-panel-summary-value){min-width:0;max-width:100%;overflow-wrap:anywhere;word-break:break-word;white-space:normal}
body .dp-panel[data-dp-panel-kind] .dp-panel-summary-value{font-size:18px}
body .dp-panel[data-dp-panel-kind] .dp-panel-show-field-copyable{grid-template-columns:minmax(0,1fr);column-gap:0;row-gap:6px}
body .dp-panel[data-dp-panel-kind] .dp-panel-show-field-copyable>header,body .dp-panel[data-dp-panel-kind] .dp-panel-show-field-copyable>.dp-panel-entry-copy,body .dp-panel[data-dp-panel-kind] .dp-panel-show-field-copyable>strong{grid-column:1;grid-row:auto;width:100%;min-width:0;max-width:100%;justify-self:stretch}
body .dp-panel[data-dp-panel-kind] .dp-panel-show-field-copyable>header{grid-template-columns:minmax(0,1fr)}
.dp-panel[data-dp-panel-kind] .dp-panel-form>.dp-panel-toolbar:last-child{position:static;display:grid;grid-template-columns:minmax(0,1fr);justify-content:stretch;gap:6px;padding:6px}
.dp-panel[data-dp-panel-kind] .dp-panel-form>.dp-panel-toolbar:last-child>.dp-panel-button{width:100%;min-width:0;max-width:100%;padding-inline:6px}
.dp-panel .dp-panel-relation>.dp-panel-toolbar{--dp-relation-per-page-columns:minmax(0,1fr);--dp-relation-per-page-button-width:100%}
.dp-panel :is(.dp-panel-button,.dp-panel-action,.dp-panel-row-link){max-width:100%;padding-inline:6px;white-space:normal}
.dp-panel :is(.dp-panel-checkbox,.dp-panel-switch,.dp-panel-field-boolean){min-width:0;max-width:100%;padding-inline:6px}
.dp-panel :is(.dp-panel-switch-copy,.dp-panel-checkbox span,.dp-panel-field-boolean span){min-width:0;max-width:100%;overflow-wrap:anywhere;word-break:break-word}
body .dp-panel[data-dp-panel-kind] .dp-panel-field:has(>input[type="checkbox"]){gap:6px;padding:6px}
body .dp-panel[data-dp-panel-kind] .dp-panel-field:has(>input[type="checkbox"])>span{min-width:0;max-width:100%;overflow-wrap:anywhere;word-break:break-word}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"]>.dp-panel-sidebar{padding:8px}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-brand{grid-template-columns:32px minmax(0,1fr);gap:6px}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-brand>span{width:32px;height:32px}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] :is(.dp-panel-sidebar-nav,.dp-panel-sidebar-group,.dp-panel-sidebar-submenu,.dp-panel-sidebar-submenu-items,.dp-panel-sidebar-item,.dp-panel-sidebar-link,.dp-panel-sidebar-copy){width:100%;min-width:0;max-width:100%;margin-inline:0;padding-inline:0}
.dp-panel[data-dp-panel-navigation-layout="sidebar"][data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-copy :is(strong,small){min-width:0;max-width:100%;overflow-wrap:anywhere}
}
body :is(.dp-panel,.dp-panel-modal-root) .dp-panel-table tbody :is(.dp-panel-row-selected,.dp-panel-row-selected>td){background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 16%,var(--dp-surface))}
:is(.dp-panel-form,.dp-panel-show){container:dp-form/inline-size}
@container dp-form (max-width:760px){.dp-panel-form-grid{grid-template-columns:minmax(0,1fr)}.dp-panel-field{width:100%;container:dp-field/inline-size}:is(.dp-panel-field,.dp-panel-show-field,.dp-panel-field-full,[class*="dp-panel-field-span-"]){grid-column:1/-1;grid-row:auto}:is(.dp-panel-form-section[data-layout="aside"],.dp-panel-sidebar-grid,.dp-panel-split){grid-template-columns:minmax(0,1fr)}.dp-panel-form-section[data-layout="aside"]>.dp-panel-section-heading{position:static}.dp-panel-form-section[data-layout="aside"]>.dp-panel-form-grid{grid-column:auto}}
@container dp-field (max-width:360px){.dp-panel-input-shell{flex-wrap:wrap}.dp-panel-input-control{flex-basis:0;min-width:44px}.dp-panel-input-adornments-append{display:flex;flex:1 0 100%;border-top:1px solid var(--dp-border_soft)}.dp-panel-input-adornments-append>*{flex:1 1 88px}.dp-panel-input-adornments-append :is(.dp-panel-input-addon,.dp-panel-input-button){border-left:0}[data-dp-display]{--dp-collection-columns-active:1;--dp-collection-basis-active:100%}:is([data-dp-display="masonry"][data-dp-masonry="rows"],[data-dp-fit="fill"])>*{flex-basis:100%;min-width:0}}
@container dp-field (max-width:160px){.dp-panel-input-shell{display:grid;grid-template-columns:minmax(0,1fr);overflow:visible}.dp-panel-input-control{width:100%;min-width:0;max-width:100%}.dp-panel-input-adornments-append{display:grid;grid-template-columns:minmax(0,1fr);width:100%;min-width:0;max-width:100%}.dp-panel-input-adornments-append>*{width:100%;min-width:0;max-width:100%;flex:none}.dp-panel-input-adornments-append :is(.dp-panel-input-addon,.dp-panel-input-button){padding-inline:6px;white-space:normal}}
CSS;
	}

	/** Breakpoint- and container-aware default spans for unplaced form/show fields. */
	private static function adaptiveFieldGridCss(): string {
		return <<<'CSS'
.dp-panel-form-grid{--dp-grid-cols-active:var(--dp-grid-cols,1);--dp-grid-auto-span-active:var(--dp-grid-auto-span,1)}
.dp-panel-grid-item-auto{grid-column:auto/span min(var(--dp-grid-cols-active),var(--dp-grid-auto-span-active))}
@media(min-width:640px){.dp-panel-form-grid{--dp-grid-cols-active:var(--dp-grid-cols-sm,var(--dp-grid-cols,1));--dp-grid-auto-span-active:var(--dp-grid-auto-span-sm,var(--dp-grid-auto-span,1))}}
@media(min-width:768px){.dp-panel-form-grid{--dp-grid-cols-active:var(--dp-grid-cols-md,var(--dp-grid-cols-sm,var(--dp-grid-cols,1)));--dp-grid-auto-span-active:var(--dp-grid-auto-span-md,var(--dp-grid-auto-span-sm,var(--dp-grid-auto-span,1)))}}
@media(min-width:1024px){.dp-panel-form-grid{--dp-grid-cols-active:var(--dp-grid-cols-lg,var(--dp-grid-cols-md,var(--dp-grid-cols-sm,var(--dp-grid-cols,1))));--dp-grid-auto-span-active:var(--dp-grid-auto-span-lg,var(--dp-grid-auto-span-md,var(--dp-grid-auto-span-sm,var(--dp-grid-auto-span,1))))}}
@media(min-width:1280px){.dp-panel-form-grid{--dp-grid-cols-active:var(--dp-grid-cols-xl,var(--dp-grid-cols-lg,var(--dp-grid-cols-md,var(--dp-grid-cols-sm,var(--dp-grid-cols,1)))));--dp-grid-auto-span-active:var(--dp-grid-auto-span-xl,var(--dp-grid-auto-span-lg,var(--dp-grid-auto-span-md,var(--dp-grid-auto-span-sm,var(--dp-grid-auto-span,1)))))}}
@media(min-width:1536px){.dp-panel-form-grid{--dp-grid-cols-active:var(--dp-grid-cols-2xl,var(--dp-grid-cols-xl,var(--dp-grid-cols-lg,var(--dp-grid-cols-md,var(--dp-grid-cols-sm,var(--dp-grid-cols,1))))));--dp-grid-auto-span-active:var(--dp-grid-auto-span-2xl,var(--dp-grid-auto-span-xl,var(--dp-grid-auto-span-lg,var(--dp-grid-auto-span-md,var(--dp-grid-auto-span-sm,var(--dp-grid-auto-span,1))))))}}
@container dp-form (min-width:761px) and (max-width:1040px){.dp-panel-form-grid{--dp-grid-cols-active:var(--dp-grid-cols-md,var(--dp-grid-cols-sm,var(--dp-grid-cols,1)));--dp-grid-auto-span-active:var(--dp-grid-auto-span-md,var(--dp-grid-auto-span-sm,var(--dp-grid-auto-span,1)));grid-template-columns:repeat(var(--dp-grid-cols-active),minmax(0,1fr))}:is(.dp-panel-field,.dp-panel-show-field):not(.dp-panel-grid-item-auto){grid-column:var(--dp-grid-column-md,var(--dp-grid-column-sm,var(--dp-grid-column,auto)))}:is(.dp-panel-field,.dp-panel-show-field){grid-row:var(--dp-grid-row-md,var(--dp-grid-row-sm,var(--dp-grid-row,auto)))}}
@container dp-form (max-width:760px){.dp-panel-grid-item-auto{grid-column:1/-1}}
@media(max-width:760px){.dp-panel-grid-item-auto{grid-column:1/-1}}
CSS;
	}

	/** Record-capability action hierarchy, overflow menu, and isolated-container reflow. */
	private static function recordActionOverflowCss(): string {
		return <<<'CSS'
.dp-panel-record-action-count{display:inline-grid;place-items:center;min-width:20px;height:20px;border-radius:999px;background:color-mix(in srgb,currentColor 12%,transparent);padding:0 6px;font-size:10px;font-weight:950;line-height:1}
.dp-panel-record-actions>.dp-panel-record-action-overflow{display:grid;grid-template-columns:minmax(0,1fr);align-items:stretch}
.dp-panel-record-action-overflow>.dp-panel-record-action-menu{width:min(22rem,calc(100vw - 32px));max-width:min(22rem,calc(100vw - 32px));max-height:min(70vh,34rem);overflow-y:auto;overscroll-behavior:contain}
.dp-panel-record-action-menu>.dp-panel-action-group{display:grid;width:100%;min-width:0}
.dp-panel-record-action-menu>.dp-panel-action-group>summary{width:100%;justify-content:flex-start}
.dp-panel-record-action-menu>.dp-panel-action-group>.dp-panel-action-menu{position:static;inset:auto;width:100%;min-width:0;max-width:100%;margin-top:5px;border-color:var(--dp-border_soft);box-shadow:inset 0 1px 0 color-mix(in srgb,var(--dp-border_soft) 72%,transparent)}
@container dp-record-heading (max-width:540px){.dp-panel-record-actions{display:grid;grid-template-columns:minmax(0,1fr);align-items:stretch;width:100%}.dp-panel-record-actions>form,.dp-panel-record-actions>.dp-panel-inline-action,.dp-panel-record-actions>.dp-panel-action-group,.dp-panel-record-actions>.dp-panel-row-more,.dp-panel-record-actions>.dp-panel-button,.dp-panel-record-actions>a,.dp-panel-record-actions>button{width:100%;min-width:0;max-width:100%;flex:none}.dp-panel-record-action-overflow>.dp-panel-record-action-menu{position:static;inset:auto;width:100%;max-width:100%;max-height:min(56dvh,30rem);overflow-y:auto;margin-top:8px;box-shadow:none}}
CSS;
	}

	/** Form-capability choice geometry and semantic interaction states. */
	private static function choiceNormalizationCss(): string {
		return <<<'CSS'
:is(.dp-panel,.dp-panel-modal-root) .dp-panel-choice{min-height:var(--dp-vs-control-md);cursor:pointer}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-choice>input{flex:0 0 20px;width:20px;height:20px;min-width:20px;min-height:20px;margin:0;padding:0}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-choice>span{flex:1 1 auto;min-width:0}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-choice:focus-within{outline:2px solid var(--dp-primary-600,#2563eb);outline-offset:2px}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-choice:has(>input:checked){border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 58%,var(--dp-border));background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 12%,var(--dp-surface))}:is(.dp-panel,.dp-panel-modal-root) :is(.dp-panel-choice-disabled,.dp-panel-choice:has(>input:disabled)){cursor:not-allowed;opacity:.58}:is(.dp-panel,.dp-panel-modal-root) :is(.dp-panel-field-invalid,.dp-panel-field:has([aria-invalid="true"])) .dp-panel-choice{border-color:var(--dp-danger-600,#dc2626)}
:is(.dp-panel,.dp-panel-modal-root) .dp-panel-field-boolean{display:grid;gap:8px}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-field-boolean .dp-panel-switch{position:relative;display:flex;align-items:center;justify-content:flex-start;gap:10px;width:100%;min-width:0;min-height:52px;border:1px solid var(--dp-border_soft);border-radius:14px;background:color-mix(in srgb,var(--dp-surface) 86%,var(--dp-surface_muted));padding:10px 12px;cursor:pointer}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-switch>input[type="checkbox"]{position:absolute;width:1px;height:1px;min-width:1px;min-height:1px;margin:-1px;padding:0;overflow:hidden;clip-path:inset(50%);white-space:nowrap}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-switch-track{display:block;flex:0 0 42px;width:42px;height:24px;border:1px solid var(--dp-control_border);border-radius:999px;background:var(--dp-neutral_bg);padding:2px;transition:border-color .14s ease,background .14s ease}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-switch-track>span{display:block;width:18px;height:18px;border-radius:999px;background:var(--dp-text_muted);box-shadow:0 1px 3px color-mix(in srgb,var(--dp-text) 24%,transparent);transform:translateX(0);transition:transform .14s ease,background .14s ease}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-switch-copy{flex:1 1 auto;min-width:0;overflow-wrap:anywhere}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-switch:has(>input[type="checkbox"]:checked){border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 58%,var(--dp-border));background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 12%,var(--dp-surface))}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-switch:has(>input[type="checkbox"]:checked) .dp-panel-switch-track{border-color:var(--dp-primary-600,#2563eb);background:var(--dp-primary-600,#2563eb)}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-switch:has(>input[type="checkbox"]:checked) .dp-panel-switch-track>span{background:#fff;transform:translateX(18px)}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-switch:focus-within{outline:2px solid var(--dp-primary-600,#2563eb);outline-offset:2px}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-switch:has(>input[type="checkbox"]:disabled){cursor:not-allowed;opacity:.58}
@media(forced-colors:active){:is(.dp-panel,.dp-panel-modal-root) .dp-panel-choice:has(>input:checked){border-color:Highlight;background:Canvas}:is(.dp-panel,.dp-panel-modal-root) :is(.dp-panel-choice,.dp-panel-switch):focus-within{outline-color:Highlight}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-switch-track{forced-color-adjust:none;border-color:CanvasText;background:Canvas}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-switch-track>span{background:CanvasText}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-switch:has(>input[type="checkbox"]:checked) .dp-panel-switch-track{border-color:Highlight;background:Highlight}:is(.dp-panel,.dp-panel-modal-root) .dp-panel-switch:has(>input[type="checkbox"]:checked) .dp-panel-switch-track>span{background:HighlightText}}
CSS;
	}

	/**
	 * The final CSSOM accessibility layer outranks component and theme declarations.
	 * Priority flags are forbidden by the asset contract; BrickV2 delimiter integrity
	 * keeps this rule in its intended top-level layer.
	 */
	private static function reducedMotionOverrideCss(): string {
		return <<<'CSS'
@media(prefers-reduced-motion:reduce){
:is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root),:is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) *,:is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) *:before,:is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) *:after{scroll-behavior:auto;animation-duration:.001ms;animation-delay:0ms;animation-iteration-count:1;transition-duration:.001ms;transition-delay:0ms}
}
CSS;
	}
}
