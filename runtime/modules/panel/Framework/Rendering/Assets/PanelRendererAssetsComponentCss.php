<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits component-level CSS for generated Panel UI elements.
 *
 * The trait groups static styles for modals, reactive controls, navigation
 * components, field widgets, cards, charts, tables, and feature surfaces. CSS is
 * returned as strings and relies on generated classes, data attributes, and theme
 * custom properties rather than PHP interpolation.
 */
trait PanelRendererAssetsComponentCss {
	/**
	 * Supplies modal and unsaved-change overlay styles for generated panel dialogs.
	 *
	 * this asset owns fixed overlay stacking, responsive dialog sizing,
	 * scroll containment, and close/action chrome shared by content modals,
	 * resource confirmation modals, and dirty-form warnings.
	 */
	private static function modalCss(): string {
		return 'body.dp-panel-modal-open,body.dp-panel-unsaved-open{overflow:hidden}.dp-panel-modal-root,.dp-panel-unsaved-root{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;background:rgba(15,23,42,.55);padding:20px}.dp-panel-unsaved-root{z-index:10001;background:rgba(15,23,42,.66);backdrop-filter:blur(5px)}.dp-panel-modal-root[hidden],.dp-panel-unsaved-root[hidden]{display:none}.dp-panel-unsaved-dialog{display:grid;grid-template-columns:auto minmax(0,1fr);gap:14px;width:min(100%,520px);border:1px solid #98a2b3;border-radius:20px;background:#fff;color:#101828;box-shadow:0 30px 90px rgba(15,23,42,.42),0 0 0 1px rgba(15,23,42,.08);padding:18px}body[data-dp-theme-effects~="flat_minima"] .dp-panel-unsaved-dialog{border-color:#667085;box-shadow:0 22px 70px rgba(15,23,42,.38),0 0 0 1px rgba(15,23,42,.14)}.dp-panel-unsaved-icon{display:grid;place-items:center;width:42px;height:42px;border-radius:14px;background:#fef0c7;color:#93370d;font-weight:950}.dp-panel-unsaved-copy{display:grid;gap:5px}.dp-panel-unsaved-copy h2{margin:0;color:#101828;font-size:19px;line-height:1.16}.dp-panel-unsaved-copy p{margin:0;color:#344054;font-size:13px;line-height:1.45}.dp-panel-unsaved-actions{grid-column:1/-1;display:flex;justify-content:flex-end;gap:9px;flex-wrap:wrap;padding-top:6px}.dp-panel-unsaved-dialog .dp-panel-button-secondary{background:#f2f4f7!important;background-image:none!important;border-color:#98a2b3!important;color:#101828!important;text-shadow:none!important}.dp-panel-unsaved-dialog .dp-panel-action-danger{background:#b42318!important;background-image:none!important;border-color:#912018!important;color:#fff!important;text-shadow:none!important}.dp-panel-unsaved-dialog .dp-panel-button:focus-visible{outline:3px solid rgba(37,99,235,.35)!important;outline-offset:2px!important}.dp-panel-modal{display:grid;grid-template-rows:auto minmax(0,1fr);width:min(100%,640px);max-height:min(86vh,900px);border:1px solid var(--dp-border);border-radius:var(--dp-radius);background:var(--dp-surface);color:var(--dp-text);box-shadow:0 24px 70px rgba(15,23,42,.35);overflow:hidden}.dp-panel-modal-xs{width:min(100%,360px)}.dp-panel-modal-sm{width:min(100%,480px)}.dp-panel-modal-md{width:min(100%,640px)}.dp-panel-modal-lg{width:min(100%,860px)}.dp-panel-modal-xl{width:min(100%,1080px)}.dp-panel-modal-full{width:min(100%,calc(100vw - 32px));height:calc(100vh - 32px);max-height:none}.dp-panel-modal-slide_over{justify-self:end;height:calc(100vh - 40px);max-height:none}.dp-panel-modal-header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:16px 18px;border-bottom:1px solid var(--dp-border_soft);background:var(--dp-surface)}.dp-panel-modal-title{display:grid;gap:4px}.dp-panel-modal-title h2{margin:0;font-size:18px}.dp-panel-modal-title p{margin:0;color:var(--dp-text_muted)}.dp-panel-modal-close{border:0;border-radius:999px;background:var(--dp-neutral_bg);color:var(--dp-neutral_text);width:32px;height:32px;font-size:20px;line-height:1;cursor:pointer}.dp-panel-modal-body{overflow:auto;padding:18px;background:var(--dp-surface_muted)}.dp-panel-modal-body>.dp-panel-form{max-width:none}.dp-panel-form-loading{opacity:.68;pointer-events:none}.dp-panel-modal-loading{margin:0;color:var(--dp-text_muted)}.dp-panel-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}body[data-dp-theme-mode="dark"] .dp-panel-unsaved-dialog{background:#111827;color:#f8fafc;border-color:#475467;box-shadow:0 30px 90px rgba(0,0,0,.62),0 0 0 1px rgba(255,255,255,.08)}body[data-dp-theme-mode="dark"] .dp-panel-unsaved-copy h2{color:#f8fafc}body[data-dp-theme-mode="dark"] .dp-panel-unsaved-copy p{color:#d0d5dd}@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"] .dp-panel-unsaved-dialog{background:#111827;color:#f8fafc;border-color:#475467;box-shadow:0 30px 90px rgba(0,0,0,.62),0 0 0 1px rgba(255,255,255,.08)}body[data-dp-theme-mode="system"] .dp-panel-unsaved-copy h2{color:#f8fafc}body[data-dp-theme-mode="system"] .dp-panel-unsaved-copy p{color:#d0d5dd}}@media(max-width:760px){.dp-panel-modal-root,.dp-panel-unsaved-root{padding:10px;align-items:end}.dp-panel-unsaved-dialog{grid-template-columns:1fr;border-radius:20px 20px 0 0}.dp-panel-unsaved-actions{display:grid;grid-template-columns:1fr}.dp-panel-modal,.dp-panel-modal-xs,.dp-panel-modal-sm,.dp-panel-modal-md,.dp-panel-modal-lg,.dp-panel-modal-xl,.dp-panel-modal-full,.dp-panel-modal-slide_over{width:100%;height:auto;max-height:92vh}.dp-panel-modal-header,.dp-panel-modal-body{padding:14px}}';
	}

	/**
	 * Supplies reactive form, live refresh, and Ajax loading state styles.
	 *
	 * these selectors bind JavaScript-owned state attributes to visual
	 * feedback for required, hidden, dirty, invalid, syncing, paused, errored, and
	 * freshly-updated controls without changing server-rendered markup.
	 */
	private static function reactivityCss(): string {
		return '.dp-panel-field-required>span:after{content:" *";color:var(--dp-danger-600);font-weight:700}.dp-panel-field[hidden],.dp-panel-field-hidden{display:none!important}.dp-panel-field-dirty>span:before{content:"";display:inline-block;width:7px;height:7px;margin-right:6px;border-radius:999px;background:var(--dp-warning-500,#f79009);box-shadow:0 0 0 3px color-mix(in srgb,var(--dp-warning-100,#fef0c7) 70%,transparent);vertical-align:1px}.dp-panel-field-dirty input,.dp-panel-field-dirty select,.dp-panel-field-dirty textarea{border-color:color-mix(in srgb,var(--dp-warning-500,#f79009) 44%,var(--dp-control_border,#cad3df))!important}.dp-panel-field-invalid>span,.dp-panel-field-invalid>.dp-panel-help{color:var(--dp-danger-700)!important}.dp-panel-field-invalid input,.dp-panel-field-invalid select,.dp-panel-field-invalid textarea{border-color:var(--dp-danger-600)!important;background:color-mix(in srgb,var(--dp-danger-50,#fff1f0) 58%,var(--dp-control_bg,#fff))!important;box-shadow:0 0 0 4px color-mix(in srgb,var(--dp-danger-100,#fee4e2) 78%,transparent)!important}.dp-panel-field-invalid:has(input:focus),.dp-panel-field-invalid:has(select:focus),.dp-panel-field-invalid:has(textarea:focus){outline:0}.dp-panel-heading-tools{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}.dp-panel-live-control{display:inline-flex;gap:3px;align-items:center;border:1px solid var(--dp-border,rgba(148,163,184,.30));border-radius:999px;background:var(--dp-neutral_bg,rgba(255,255,255,.78));padding:3px}.dp-panel-live-control button{display:inline-flex;align-items:center;gap:6px;min-height:30px;border:0;border-radius:999px;background:transparent;color:var(--dp-neutral_text,#344054);padding:6px 10px;font:inherit;font-size:12px;font-weight:850;cursor:pointer}.dp-panel-live-control button:hover{background:var(--dp-surface,#fff)}.dp-panel-live-control [data-dp-panel-live-toggle]{background:var(--dp-success-100,#dcfae6);color:var(--dp-success-800,#085d3a)}.dp-panel-live-control [data-dp-panel-live-toggle] small{border-radius:999px;background:color-mix(in srgb,var(--dp-success-800,#085d3a) 10%,transparent);padding:1px 6px;font-size:10px;font-weight:900}.dp-panel-live-control[data-dp-panel-live-tone=syncing] [data-dp-panel-live-toggle]{background:var(--dp-info-100,#b2ddff);color:var(--dp-info-800,#065986)}.dp-panel-live-control[data-dp-panel-live-tone=error] [data-dp-panel-live-toggle]{background:var(--dp-danger-100,#fee4e2);color:var(--dp-danger-800,#912018)}.dp-panel-live-control[data-dp-panel-live-tone=success] [data-dp-panel-live-toggle]{background:var(--dp-success-100,#dcfae6);color:var(--dp-success-800,#085d3a)}.dp-panel-live-control.dp-panel-live-paused [data-dp-panel-live-toggle]{background:var(--dp-warning-100,#fef0c7);color:var(--dp-warning-800,#93370d)}.dp-panel-live-control.dp-panel-live-paused [data-dp-panel-live-toggle] small{background:color-mix(in srgb,var(--dp-warning-800,#93370d) 10%,transparent)}.dp-panel-live-updated{display:inline-flex;align-items:center;min-height:30px;color:var(--dp-text_muted,#667085);padding:0 9px;font-size:11px;font-weight:850;white-space:nowrap}.dp-panel-live-control[data-dp-panel-live-tone=error] .dp-panel-live-updated{color:var(--dp-danger-700,#b42318)}.dp-panel-live-control[data-dp-panel-live-tone=syncing] .dp-panel-live-updated{color:var(--dp-info-700,#026aa2)}.dp-panel[data-dp-panel-update-flash="1"].dp-panel-live-fresh>header{box-shadow:0 0 0 4px color-mix(in srgb,var(--dp-success-100,#dcfae6) 80%,transparent),0 18px 50px rgba(15,23,42,.08);transition:box-shadow .3s ease}.dp-panel[data-dp-panel-update-flash="1"] .dp-panel-row-entered td{animation:dp-panel-row-entered 2.2s ease-out}.dp-panel[data-dp-panel-update-flash="1"] .dp-panel-row-updated td{animation:dp-panel-row-updated 2.2s ease-out}@keyframes dp-panel-row-entered{0%{background:color-mix(in srgb,var(--dp-success-100,#dcfae6) 82%,var(--dp-surface,#fff));box-shadow:inset 4px 0 0 var(--dp-success-600,#079455)}100%{background:transparent;box-shadow:inset 0 0 0 transparent}}@keyframes dp-panel-row-updated{0%{background:color-mix(in srgb,var(--dp-info-100,#b2ddff) 66%,var(--dp-surface,#fff));box-shadow:inset 4px 0 0 var(--dp-info-600,#026aa2)}100%{background:transparent;box-shadow:inset 0 0 0 transparent}}.dp-panel-ajax-loading{position:relative;cursor:progress}.dp-panel-ajax-loading:after{content:"";position:fixed;left:0;right:0;top:0;height:3px;background:linear-gradient(90deg,var(--dp-primary-600,#1f6feb),var(--dp-info-600,#026aa2));animation:dp-panel-ajax-bar .9s ease-in-out infinite;z-index:9998}@keyframes dp-panel-ajax-bar{0%{transform:scaleX(.12);transform-origin:left}50%{transform:scaleX(.78);transform-origin:left}51%{transform-origin:right}100%{transform:scaleX(.12);transform-origin:right}}@media(max-width:760px){.dp-panel-heading-tools{justify-content:flex-start}.dp-panel-live-updated{width:100%;padding-left:10px}}';
	}

	/**
	 * Supplies responsive styles for the operator theme selector.
	 *
	 * the selector reads panel theme tokens from the document body and
	 * adapts to dark, glass, desktop, and narrow mobile contexts while preserving a
	 * single generated control shape.
	 */
	private static function themeSelectorCss(): string {
		return '.dp-panel-theme-select{flex:1 0 100%;display:flex;justify-content:flex-end;margin-top:2px}.dp-panel-theme-select label{display:inline-grid;grid-template-columns:auto minmax(132px,auto);align-items:center;gap:7px;min-height:36px;border:1px solid var(--dp-border,rgba(148,163,184,.30));border-radius:999px;background:color-mix(in srgb,var(--dp-neutral_bg,#eef2f7) 84%,transparent);padding:3px 3px 3px 11px;box-shadow:inset 0 0 0 1px color-mix(in srgb,#fff 8%,transparent)}.dp-panel-theme-select span{color:var(--dp-text_muted,#667085);font-size:11px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap}.dp-panel-theme-select select{height:30px;min-height:30px;border:0;border-radius:999px;background:var(--dp-surface,#fff);color:var(--dp-text,#18202a);padding:0 30px 0 10px;font-size:12px;font-weight:850;outline:none;box-shadow:0 1px 0 color-mix(in srgb,var(--dp-border,#d0d7e2) 70%,transparent)}.dp-panel-theme-select select:focus{box-shadow:0 0 0 3px color-mix(in srgb,var(--dp-primary-600,#2563eb) 17%,transparent)}body[data-dp-theme-effects~="glass"] .dp-panel-theme-select label{background:color-mix(in srgb,var(--dp-glass_surface_bg,rgba(255,255,255,.54)) 82%,transparent);backdrop-filter:blur(var(--dp-glass_blur,18px));-webkit-backdrop-filter:blur(var(--dp-glass_blur,18px))}body[data-dp-theme-mode="dark"] .dp-panel-theme-select select{background:var(--dp-control_bg,#111827);color:var(--dp-text,#f7fafc)}@media(max-width:860px){.dp-panel-theme-select{justify-content:flex-start}.dp-panel-theme-select label{width:100%;grid-template-columns:auto minmax(0,1fr)}.dp-panel-theme-select select{width:100%}}@media(max-width:560px){.dp-panel-theme-select label{grid-template-columns:1fr;border-radius:16px;padding:9px}.dp-panel-theme-select select{height:38px;min-height:38px;border:1px solid var(--dp-border,rgba(148,163,184,.30))}}';
	}

	/**
	 * Supplies the primary resource sidebar navigation layout.
	 *
	 * this asset owns sticky desktop navigation, collapsed rail behavior,
	 * pinned links, badge tones, responsive horizontal overflow, and brand/link
	 * truncation rules for panel resource navigation.
	 */
	private static function sidebarCss(): string {
		return '.dp-panel-with-sidebar{display:grid;grid-template-columns:286px minmax(0,1fr);grid-auto-rows:max-content;column-gap:24px;align-items:start;width:min(100%,1680px)}.dp-panel-with-sidebar>*:not(.dp-panel-sidebar){grid-column:2;min-width:0}.dp-panel-sidebar{grid-column:1;grid-row:1;position:sticky;top:18px;display:grid;gap:14px;max-height:calc(100vh - 36px);overflow:auto;border:1px solid var(--dp-border,rgba(148,163,184,.28));border-radius:18px;background:color-mix(in srgb,var(--dp-surface,#fff) 88%,transparent);box-shadow:0 18px 50px rgba(15,23,42,.08);backdrop-filter:blur(16px);padding:14px}.dp-panel-sidebar-top{display:grid;grid-template-columns:minmax(0,1fr) 36px;gap:8px;align-items:start}.dp-panel-sidebar-brand{display:grid;grid-template-columns:40px minmax(0,1fr);grid-template-rows:auto auto;gap:1px 10px;align-items:center;min-width:0;border-radius:12px;color:var(--dp-text,#18202a);padding:6px;text-decoration:none}.dp-panel-sidebar-brand:hover{background:var(--dp-neutral_bg,#eef2f7)}.dp-panel-sidebar-brand>span{grid-row:1/3;display:grid;place-items:center;width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,var(--dp-primary-600,#1f6feb),var(--dp-info-600,#026aa2));color:#fff;font-size:12px;font-weight:900;letter-spacing:.02em}.dp-panel-sidebar-brand strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:14px;font-weight:900}.dp-panel-sidebar-brand small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--dp-text_muted,#667085);font-size:11px;font-weight:750}.dp-panel-sidebar-top button{display:grid;place-items:center;width:36px;height:36px;border:1px solid var(--dp-border,rgba(148,163,184,.3));border-radius:12px;background:var(--dp-surface,#fff);color:var(--dp-text_muted,#667085);cursor:pointer}.dp-panel-sidebar-top button span,.dp-panel-sidebar-top button span:before,.dp-panel-sidebar-top button span:after{display:block;width:13px;height:2px;border-radius:999px;background:currentColor;content:""}.dp-panel-sidebar-top button span:before{transform:translateY(-5px)}.dp-panel-sidebar-top button span:after{transform:translateY(3px)}.dp-panel-sidebar-nav{display:grid;gap:8px}.dp-panel-sidebar-item{display:grid;grid-template-columns:minmax(0,1fr) 34px;gap:6px;align-items:center}.dp-panel-sidebar-item>.dp-panel-sidebar-link{min-width:0}.dp-panel-sidebar-pin{display:grid;place-items:center;width:34px;height:34px;border:1px solid var(--dp-border_soft,rgba(226,232,240,.9));border-radius:10px;background:var(--dp-surface,#fff);color:var(--dp-text_soft,#98a2b3);font-size:0;cursor:pointer}.dp-panel-sidebar-pin:before{content:"+";font-size:16px;font-weight:950;line-height:1}.dp-panel-sidebar-pin[aria-pressed=true]{border-color:color-mix(in srgb,var(--dp-primary-600,#1f6feb) 35%,var(--dp-border));background:color-mix(in srgb,var(--dp-primary-50,#e8f1ff) 70%,var(--dp-surface));color:var(--dp-primary-700,#175cd3)}.dp-panel-sidebar-pin[aria-pressed=true]:before{content:"-"}.dp-panel-sidebar-pinned{border-top:0;margin-top:0;padding-top:0}.dp-panel-sidebar-pinned .dp-panel-sidebar-link{border-color:color-mix(in srgb,var(--dp-primary-600,#1f6feb) 22%,var(--dp-border));background:color-mix(in srgb,var(--dp-primary-50,#e8f1ff) 48%,var(--dp-surface))}.dp-panel-sidebar-group{display:grid;gap:6px;margin-top:8px;padding-top:10px;border-top:1px solid var(--dp-border_soft,rgba(226,232,240,.9))}.dp-panel-sidebar-group h2{margin:0 0 2px;padding:0 8px;color:var(--dp-text_soft,#98a2b3);font-size:10px;font-weight:900;letter-spacing:.09em;text-transform:uppercase}.dp-panel-sidebar-link{position:relative;display:grid;grid-template-columns:34px minmax(0,1fr) auto;gap:9px;align-items:center;min-height:44px;border:1px solid transparent;border-radius:12px;color:var(--dp-text,#18202a);padding:5px 8px;text-decoration:none;transition:background .14s ease,border-color .14s ease,box-shadow .14s ease,transform .14s ease}.dp-panel-sidebar-link:hover{border-color:var(--dp-border,rgba(148,163,184,.28));background:var(--dp-surface,#fff);box-shadow:0 8px 18px rgba(15,23,42,.055);transform:translateX(1px)}.dp-panel-sidebar-link.active{border-color:color-mix(in srgb,var(--dp-primary-600,#1f6feb) 35%,var(--dp-border,rgba(148,163,184,.28)));background:color-mix(in srgb,var(--dp-primary-50,#e8f1ff) 72%,var(--dp-surface,#fff));color:var(--dp-primary-800,#1849a9);box-shadow:inset 3px 0 0 var(--dp-primary-600,#1f6feb),0 8px 20px rgba(37,99,235,.08)}.dp-panel-sidebar-icon{display:grid;place-items:center;width:34px;height:34px;border-radius:10px;background:var(--dp-neutral_bg,#eef2f7);color:var(--dp-neutral_text,#344054);font-size:10px;font-weight:950;letter-spacing:.03em}.dp-panel-sidebar-link.active .dp-panel-sidebar-icon{background:var(--dp-primary-600,#1f6feb);color:#fff}.dp-panel-sidebar-copy{display:grid;gap:1px;min-width:0}.dp-panel-sidebar-copy strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;font-weight:850}.dp-panel-sidebar-copy small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--dp-text_muted,#667085);font-size:11px;font-weight:650}.dp-panel-sidebar-badge{justify-self:end;min-width:20px;border-radius:999px;background:var(--dp-neutral_bg,#eef2f7);color:var(--dp-neutral_text,#344054);padding:3px 7px;font-size:10px;font-weight:900}.dp-panel-sidebar-badge-primary{background:var(--dp-primary-50,#e8f1ff);color:var(--dp-primary-700,#175cd3)}.dp-panel-sidebar-badge-success{background:var(--dp-success-50,#ecfdf3);color:var(--dp-success-700,#067647)}.dp-panel-sidebar-badge-warning{background:var(--dp-warning-50,#fffaeb);color:var(--dp-warning-700,#b54708)}.dp-panel-sidebar-badge-danger{background:var(--dp-danger-50,#fff1f0);color:var(--dp-danger-700,#b42318)}.dp-panel-sidebar-badge-info{background:var(--dp-info-50,#eff8ff);color:var(--dp-info-700,#026aa2)}.dp-panel-sidebar-collapsed{grid-template-columns:76px minmax(0,1fr)}.dp-panel-sidebar-collapsed .dp-panel-sidebar{padding:10px}.dp-panel-sidebar-collapsed .dp-panel-sidebar-top{grid-template-columns:1fr;gap:8px}.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand{grid-template-columns:1fr;padding:4px;justify-items:center}.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand>span{grid-row:auto}.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand strong,.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand small,.dp-panel-sidebar-collapsed .dp-panel-sidebar-copy,.dp-panel-sidebar-collapsed .dp-panel-sidebar-group h2,.dp-panel-sidebar-collapsed .dp-panel-sidebar-badge,.dp-panel-sidebar-collapsed .dp-panel-sidebar-pin{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}.dp-panel-sidebar-collapsed .dp-panel-sidebar-item{grid-template-columns:1fr}.dp-panel-sidebar-collapsed .dp-panel-sidebar-link{grid-template-columns:1fr;justify-items:center;padding:5px}.dp-panel-sidebar-collapsed .dp-panel-sidebar-icon{width:38px;height:38px}.dp-panel-sidebar-collapsed .dp-panel-sidebar-top button{width:100%}@media(max-width:1100px){.dp-panel-with-sidebar{display:block;width:min(100%,1440px)}.dp-panel-sidebar{position:relative;top:auto;max-height:none;margin:0 0 18px;overflow:visible}.dp-panel-sidebar-nav{display:flex;gap:8px;overflow:auto;padding-bottom:2px;scrollbar-width:thin}.dp-panel-sidebar-group{display:contents}.dp-panel-sidebar-group h2{display:none}.dp-panel-sidebar-item{display:grid;grid-template-columns:minmax(190px,1fr) 34px;flex:0 0 auto}.dp-panel-sidebar-link{min-width:190px}.dp-panel-sidebar-collapsed{display:block}.dp-panel-sidebar-collapsed .dp-panel-sidebar-copy,.dp-panel-sidebar-collapsed .dp-panel-sidebar-group h2,.dp-panel-sidebar-collapsed .dp-panel-sidebar-badge,.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand strong,.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand small{position:static;width:auto;height:auto;overflow:hidden;clip:auto;white-space:nowrap}.dp-panel-sidebar-collapsed .dp-panel-sidebar-item{grid-template-columns:minmax(190px,1fr) 34px}.dp-panel-sidebar-collapsed .dp-panel-sidebar-link{grid-template-columns:34px minmax(0,1fr) auto;justify-items:stretch}.dp-panel-sidebar-collapsed .dp-panel-sidebar{padding:14px}.dp-panel-sidebar-top button{display:none}}@media(max-width:760px){.dp-panel-sidebar{border-radius:15px;padding:10px}.dp-panel-sidebar-nav{margin-inline:-2px}.dp-panel-sidebar-link{min-width:160px;grid-template-columns:30px minmax(0,1fr);padding:5px 7px}.dp-panel-sidebar-icon{width:30px;height:30px}.dp-panel-sidebar-badge,.dp-panel-sidebar-pin{display:none}.dp-panel-sidebar-copy small{display:none}}';
	}

	/**
	 * Supplies sidebar search and keyboard focus styles.
	 *
	 * search styling is isolated from navigation layout so filter counts,
	 * focus-visible rings, and empty-result states can be emitted only when the
	 * sidebar search feature is present.
	 */
	private static function sidebarSearchCss(): string {
		return '.dp-panel-sidebar-search{position:relative;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px;align-items:center}.dp-panel-sidebar-search input{width:100%;min-height:36px;border:1px solid var(--dp-border_soft,rgba(226,232,240,.9));border-radius:12px;background:var(--dp-control_bg,var(--dp-surface,#fff));color:var(--dp-text,#18202a);padding:7px 10px;font-size:12px;font-weight:750;outline:none}.dp-panel-sidebar-search input:focus{border-color:var(--dp-primary-600,#1f6feb);box-shadow:0 0 0 3px color-mix(in srgb,var(--dp-primary-600,#1f6feb) 14%,transparent)}.dp-panel-sidebar-link:focus-visible{outline:0;border-color:var(--dp-primary-600,#1f6feb)!important;box-shadow:0 0 0 3px color-mix(in srgb,var(--dp-primary-600,#1f6feb) 16%,transparent)!important}.dp-panel-sidebar-search span{display:inline-flex;align-items:center;justify-content:center;min-width:34px;min-height:28px;border-radius:999px;background:var(--dp-neutral_bg,#eef2f7);color:var(--dp-neutral_text,#344054);font-size:11px;font-weight:900}.dp-panel-sidebar-search span:empty,.dp-panel-sidebar-search span[hidden]{display:none}.dp-panel-sidebar-search-empty .dp-panel-sidebar-nav:after{content:"No navigation matches";display:block;border:1px dashed var(--dp-border_soft,rgba(226,232,240,.9));border-radius:12px;color:var(--dp-text_muted,#667085);padding:12px;text-align:center;font-size:12px;font-weight:800}.dp-panel-sidebar-group h2 button{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;border:0;background:transparent;color:inherit;padding:0;font:inherit;font-weight:inherit;letter-spacing:inherit;text-transform:inherit;cursor:pointer}.dp-panel-sidebar-group h2 button i{width:7px;height:7px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg);opacity:.75;transition:transform .14s ease}.dp-panel-sidebar-group-collapsed h2 button i{transform:rotate(-45deg)}.dp-panel-sidebar-group-collapsed>*:not(h2){display:none!important}.dp-panel-sidebar-searching .dp-panel-sidebar-group-collapsed>*:not(h2){display:grid!important}.dp-panel-sidebar-recent{border-top-style:dashed}.dp-panel-sidebar-link-recent{background:color-mix(in srgb,var(--dp-surface_muted,#f8fafc) 74%,var(--dp-surface,#fff));border-color:color-mix(in srgb,var(--dp-border_soft,rgba(226,232,240,.9)) 84%,transparent)}.dp-panel-sidebar-link-recent .dp-panel-sidebar-icon{background:color-mix(in srgb,var(--dp-info-50,#eff8ff) 72%,var(--dp-neutral_bg,#eef2f7));color:var(--dp-info-700,#026aa2)}.dp-panel-sidebar-collapsed .dp-panel-sidebar-search{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}@media(max-width:1100px){.dp-panel-sidebar-search{max-width:360px}}@media(max-width:760px){.dp-panel-sidebar-search{max-width:none}.dp-panel-sidebar-search input{min-height:38px}}';
	}

	/**
	 * Supplies grouped action menu and dropdown positioning styles.
	 *
	 * action groups depend on runtime attributes for open, floating,
	 * alignment, width, and tone state. The CSS keeps menu placement and command
	 * affordances stable across row actions, toolbars, and modal contexts.
	 */
	private static function actionGroupCss(): string {
		return '.dp-panel-action-group{position:relative;display:inline-block;text-align:left}.dp-panel-action-group>summary{display:inline-flex;align-items:center;gap:7px;list-style:none;user-select:none}.dp-panel-action-group>summary::-webkit-details-marker{display:none}.dp-panel-action-group-icon{font-size:12px;line-height:1}.dp-panel-action-menu{position:absolute;right:0;z-index:20;display:grid;gap:4px;min-width:190px;margin-top:6px;border:1px solid var(--dp-border);border-radius:var(--dp-radius);background:var(--dp-surface);box-shadow:0 14px 35px rgba(15,23,42,.16);padding:6px}.dp-panel-action-menu .dp-panel-inline-action{display:block}.dp-panel-action-menu .dp-panel-action,.dp-panel-actions .dp-panel-action-menu .dp-panel-action{display:block;width:100%;margin:0;border:0;border-radius:calc(var(--dp-radius) - 2px);padding:8px 10px;text-align:left;text-decoration:none;font:inherit;font-weight:700;white-space:nowrap}.dp-panel-action-menu .dp-panel-action-neutral,.dp-panel-actions .dp-panel-action-menu .dp-panel-action-neutral{background:var(--dp-neutral_bg,#eef2f7);color:var(--dp-neutral_text,#1f2937)}.dp-panel-action-menu .dp-panel-action-primary,.dp-panel-actions .dp-panel-action-menu .dp-panel-action-primary{background:var(--dp-primary-600,#1f6feb);color:#fff}.dp-panel-action-menu .dp-panel-action-success,.dp-panel-actions .dp-panel-action-menu .dp-panel-action-success{background:var(--dp-success-600,#079455);color:#fff}.dp-panel-action-menu .dp-panel-action-warning,.dp-panel-actions .dp-panel-action-menu .dp-panel-action-warning{background:var(--dp-warning-600,#dc6803);color:#fff}.dp-panel-action-menu .dp-panel-action-danger,.dp-panel-actions .dp-panel-action-menu .dp-panel-action-danger{background:var(--dp-danger-600,#d92d20);color:#fff}.dp-panel-actions .dp-panel-action-group{margin-left:10px}.dp-panel-actions .dp-panel-action-group>summary{margin-left:0}.dp-panel-bulk-bar .dp-panel-action-menu{bottom:100%;top:auto;margin-top:0;margin-bottom:6px}.dp-panel-board-card .dp-panel-action-menu{left:0;right:auto}@media(max-width:760px){.dp-panel-action-menu{left:0;right:auto;min-width:170px}.dp-panel-toolbar-actions .dp-panel-action-menu{right:0;left:auto}}';
	}

	/**
	 * Supplies tab navigation and tab panel visibility styles.
	 *
	 * tabs are driven by generated active classes and ARIA state. This
	 * asset defines scrollable tab lists, active indicators, disabled affordances,
	 * and panel hiding rules without introducing client-side layout assumptions.
	 */
	private static function tabsCss(): string {
		return '.dp-panel-tabs{display:grid;gap:14px;margin:0 0 16px;overflow-anchor:none}.dp-panel-tab-list{display:flex;flex-wrap:wrap;gap:6px;border-bottom:1px solid var(--dp-border_soft);padding-bottom:6px;overflow-anchor:none}.dp-panel-tab-list button{border:0;border-radius:999px;background:var(--dp-neutral_bg);color:var(--dp-neutral_text);padding:8px 12px;font:inherit;font-size:13px;font-weight:700;cursor:pointer}.dp-panel-tab-list button[aria-selected=true]{background:var(--dp-primary-600);color:#fff}.dp-panel-tab-panel{display:grid;gap:14px;overflow-anchor:none}.dp-panel-tab-panel[hidden]{display:none!important}';
	}

	/**
	 * Supplies stepper and wizard progress styles.
	 *
	 * step markup uses generated state classes for active, complete, and
	 * disabled steps. The CSS turns those states into responsive progress chrome
	 * while keeping form lifecycle logic in PHP and JavaScript.
	 */
	private static function stepsCss(): string {
		return '.dp-panel-steps{display:grid;gap:16px;margin:0 0 16px}.dp-panel-step-list{display:flex;flex-wrap:wrap;gap:8px}.dp-panel-step-list button{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--dp-border);border-radius:999px;background:var(--dp-surface);color:var(--dp-neutral_text);padding:7px 11px;font:inherit;font-size:13px;font-weight:700;cursor:pointer}.dp-panel-step-list button span{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:999px;background:var(--dp-neutral_bg);color:var(--dp-neutral_text);font-size:12px}.dp-panel-step-list button[aria-current=step]{border-color:var(--dp-primary-600);color:var(--dp-primary-700)}.dp-panel-step-list button[aria-current=step] span{background:var(--dp-primary-600);color:#fff}.dp-panel-step-panel{display:grid;gap:14px}.dp-panel-step-panel[hidden]{display:none!important}.dp-panel-step-actions{display:flex;justify-content:space-between;gap:10px;margin-top:4px}@media(max-width:760px){.dp-panel-step-list{display:grid}.dp-panel-step-list button{justify-content:flex-start}}';
	}

	/**
	 * Supplies repeated field-group layout and row action styles.
	 *
	 * repeater markup can be added, removed, reordered, or collapsed by
	 * client behavior. This asset keeps item chrome, controls, drag affordances, and
	 * empty spacing predictable for nested form data.
	 */
	private static function repeaterCss(): string {
		return '.dp-panel-repeater{display:grid;gap:10px}.dp-panel-repeater-items{display:grid;gap:10px}.dp-panel-repeater-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:start;border:1px solid var(--dp-border_soft);border-radius:var(--dp-radius);background:var(--dp-surface_muted);padding:10px}.dp-panel-repeater-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}.dp-panel-repeater-row .dp-panel-button{white-space:nowrap}@media(max-width:760px){.dp-panel-repeater-row{grid-template-columns:1fr}.dp-panel-repeater-row .dp-panel-button{justify-self:start}}';
	}

	/**
	 * Supplies specialized field component styles.
	 *
	 * field components cover rich inputs such as choices, uploads,
	 * ratings, ranges, builders, and display-only values. The CSS binds generated
	 * field classes to accessible density, focus, disabled, and mobile states.
	 */
	private static function fieldComponentCss(): string {
		return '.dp-panel-editor{display:grid;gap:8px;min-width:0}.dp-panel-editor-toolbar{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:30px;border:1px solid var(--dp-border_soft,rgba(226,232,240,.9));border-radius:12px;background:var(--dp-surface_muted,#f8fafc);padding:5px 8px}.dp-panel-editor-toolbar span{color:var(--dp-text,#18202a)!important;font-size:12px!important;font-weight:850!important;letter-spacing:0!important;text-transform:none!important}.dp-panel-editor-toolbar small{display:inline-flex;align-items:center;min-height:20px;border-radius:999px;background:var(--dp-neutral_bg,#eef2f7);color:var(--dp-neutral_text,#344054);padding:2px 7px;font-size:10px;font-weight:850}.dp-panel-editor textarea{width:100%;min-width:0}.dp-panel-editor-preview{min-height:44px;border:1px solid var(--dp-border_soft,rgba(226,232,240,.9));border-radius:12px;background:var(--dp-surface,#fff);color:var(--dp-text,#18202a);padding:10px 11px;overflow:auto;overflow-wrap:anywhere;line-height:1.45}.dp-panel-editor-preview-empty{color:var(--dp-text_muted,#667085);font-style:italic}.dp-panel-editor-preview-code{max-height:260px;margin:0;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;white-space:pre}.dp-panel-editor-preview-markdown code{display:inline-block;border:1px solid var(--dp-border_soft,rgba(226,232,240,.9));border-radius:6px;background:var(--dp-surface_muted,#f8fafc);padding:0 4px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.92em}.dp-panel-field input[data-dp-panel-mask],.dp-panel-field input[list]{padding-right:34px}.dp-panel-input-shell{display:flex;align-items:stretch;width:100%;min-width:0;min-height:44px;border:1px solid var(--dp-control_border,rgba(148,163,184,.42));border-radius:12px;background:var(--dp-control_bg,rgba(255,255,255,.94));color:var(--dp-text,#18202a);box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 10%,transparent);overflow:hidden;transition:border-color .14s ease,box-shadow .14s ease,background .14s ease}.dp-panel-input-shell:focus-within{border-color:var(--dp-primary-500,#3b82f6);box-shadow:var(--dp-ui-focus,0 0 0 4px rgba(59,130,246,.13));background:var(--dp-surface,#fff)}.dp-panel-input-control{display:flex;align-items:stretch;min-width:0;flex:1 1 auto}.dp-panel-field .dp-panel-input-shell input:not([type=checkbox]):not([type=radio]),.dp-panel-field .dp-panel-input-shell select,.dp-panel-field .dp-panel-input-shell textarea{min-height:42px!important;width:100%!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;color:inherit!important;padding:10px 11px!important;outline:0!important}.dp-panel-field .dp-panel-input-shell input:focus,.dp-panel-field .dp-panel-input-shell select:focus,.dp-panel-field .dp-panel-input-shell textarea:focus{box-shadow:none!important;background:transparent!important}.dp-panel-input-adornments{display:inline-flex;align-items:stretch;flex:0 0 auto;min-width:0}.dp-panel-input-addon,.dp-panel-input-button{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:42px;border:0;border-radius:0;background:color-mix(in srgb,var(--dp-surface_muted,#f8fafc) 84%,transparent);color:var(--dp-text_muted,#667085);padding:0 11px;font-size:12px;font-weight:850;line-height:1;white-space:nowrap;text-decoration:none}.dp-panel-input-addon-prepend,.dp-panel-input-button-prepend{border-right:1px solid var(--dp-border_soft,rgba(226,232,240,.9))}.dp-panel-input-addon-append,.dp-panel-input-button-append{border-left:1px solid var(--dp-border_soft,rgba(226,232,240,.9))}.dp-panel-input-button{cursor:pointer;color:var(--dp-primary-700,#175cd3);background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 54%,var(--dp-surface,#fff));box-shadow:none!important}.dp-panel-input-button:hover{background:color-mix(in srgb,var(--dp-primary-100,#dbeafe) 70%,var(--dp-surface,#fff));color:var(--dp-primary-800,#1849a9)}.dp-panel-input-button[data-dp-panel-field-button-state="copied"],.dp-panel-input-button[data-dp-panel-field-button-state="active"]{background:color-mix(in srgb,var(--dp-success-100,#dcfae6) 82%,var(--dp-surface,#fff));color:var(--dp-success-800,#085d3a)}.dp-panel-input-button-success{color:var(--dp-success-700,#067647);background:color-mix(in srgb,var(--dp-success-50,#ecfdf3) 74%,var(--dp-surface,#fff))}.dp-panel-input-button-warning{color:var(--dp-warning-700,#b54708);background:color-mix(in srgb,var(--dp-warning-50,#fffaeb) 78%,var(--dp-surface,#fff))}.dp-panel-input-button-danger{color:var(--dp-danger-700,#b42318);background:color-mix(in srgb,var(--dp-danger-50,#fff1f0) 76%,var(--dp-surface,#fff))}.dp-panel-input-button-icon{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;border-radius:999px;background:color-mix(in srgb,currentColor 12%,transparent);font-size:10px;font-weight:900}.dp-panel-field select[data-dp-panel-searchable="1"]{background-image:linear-gradient(45deg,transparent 50%,currentColor 50%),linear-gradient(135deg,currentColor 50%,transparent 50%);background-position:calc(100% - 18px) 50%,calc(100% - 13px) 50%;background-size:5px 5px,5px 5px;background-repeat:no-repeat}.dp-panel-field select[data-dp-panel-native="0"]{box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--dp-primary-600,#2563eb) 18%,transparent)}body[data-dp-theme-mode="dark"] .dp-panel-editor-toolbar,body[data-dp-theme-mode="dark"] .dp-panel-editor-preview{background:#111827;border-color:#34445d;color:#f8fafc}body[data-dp-theme-mode="dark"] .dp-panel-editor-preview-empty{color:#9fb0c5}body[data-dp-theme-mode="dark"] .dp-panel-input-shell{background:var(--dp-control_bg,#111827);border-color:var(--dp-control_border,#34445d);color:var(--dp-text,#f8fafc)}body[data-dp-theme-mode="dark"] .dp-panel-input-addon,body[data-dp-theme-mode="dark"] .dp-panel-input-button{background:color-mix(in srgb,var(--dp-surface_muted,#1f2937) 82%,transparent);border-color:var(--dp-border_soft,#34445d);color:var(--dp-text_muted,#cbd5e1)}body[data-dp-theme-mode="dark"] .dp-panel-input-button{color:var(--dp-primary-200,#bfdbfe)}body[data-dp-theme-mode="dark"] .dp-panel-input-button[data-dp-panel-field-button-state="copied"],body[data-dp-theme-mode="dark"] .dp-panel-input-button[data-dp-panel-field-button-state="active"]{background:#123124;color:#bbf7d0}body[data-dp-theme-mode="system"] .dp-panel-editor-toolbar,body[data-dp-theme-mode="system"] .dp-panel-editor-preview{background:var(--dp-surface,#fff);border-color:var(--dp-border_soft,rgba(226,232,240,.9));color:var(--dp-text,#18202a)}@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"] .dp-panel-editor-toolbar,body[data-dp-theme-mode="system"] .dp-panel-editor-preview{background:#111827!important;border-color:#34445d!important;color:#f8fafc!important}body[data-dp-theme-mode="system"] .dp-panel-editor-preview-empty{color:#9fb0c5!important}body[data-dp-theme-mode="system"] .dp-panel-input-shell{background:#111827!important;border-color:#34445d!important;color:#f8fafc!important}body[data-dp-theme-mode="system"] .dp-panel-input-addon,body[data-dp-theme-mode="system"] .dp-panel-input-button{background:#1f2937!important;border-color:#34445d!important;color:#cbd5e1!important}}@media(max-width:760px){.dp-panel-editor-toolbar{align-items:flex-start;flex-direction:column}.dp-panel-editor-preview-code{max-height:210px}.dp-panel-input-shell{min-height:42px}.dp-panel-input-addon,.dp-panel-input-button{min-height:40px;padding:0 9px}.dp-panel-input-button span:not(.dp-panel-input-button-icon){max-width:76px;overflow:hidden;text-overflow:ellipsis}}';
	}

	/**
	 * Supplies record alert card styles.
	 *
	 * alert tone classes originate from normalized resource data. This
	 * asset maps those tones to attention surfaces and action affordances without
	 * coupling alert rendering to a specific resource module.
	 */
	private static function alertsCss(): string {
		return '.dp-panel-alerts{display:grid;gap:8px;margin:0 0 12px}.dp-panel-alert-card{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;border:1px solid #d9e0ea;border-left:4px solid #98a2b3;border-radius:8px;background:#fff;padding:12px}.dp-panel-alert-card strong{display:block;color:#18202a;font-size:15px}.dp-panel-alert-card p{margin:4px 0 0;color:#344054;overflow-wrap:anywhere}.dp-panel-alert-card small{display:flex;flex-wrap:wrap;gap:7px;margin-top:7px;color:#667085}.dp-panel-alert-card small span+span:before{content:"";display:inline-block;width:4px;height:4px;margin:0 7px 2px 0;border-radius:999px;background:#cad3df}.dp-panel-alert-card .dp-panel-action{white-space:nowrap}.dp-panel-alert-primary{border-left-color:#1f6feb;background:#f5f9ff}.dp-panel-alert-success{border-left-color:#079455;background:#f6fef9}.dp-panel-alert-warning{border-left-color:#dc6803;background:#fffcf5}.dp-panel-alert-danger{border-left-color:#d92d20;background:#fff8f7}.dp-panel-alert-info{border-left-color:#026aa2;background:#f5fbff}@media(max-width:760px){.dp-panel-alert-card{display:grid}.dp-panel-alert-card .dp-panel-action{justify-self:start}}';
	}

	/**
	 * Supplies record insight metric card styles.
	 *
	 * insights may render as anchors or articles with optional icons,
	 * labels, values, descriptions, and tones. The CSS keeps compact metric cards
	 * scannable in both dashboard and record-detail contexts.
	 */
	private static function insightsCss(): string {
		return '.dp-panel-insights{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin:0 0 12px}.dp-panel-insight{display:grid;gap:4px;position:relative;border:1px solid #d9e0ea;border-top:3px solid #98a2b3;border-radius:8px;background:#fff;color:#18202a;padding:12px;text-decoration:none}.dp-panel-insight-label{color:#667085;font-size:12px;font-weight:700;text-transform:uppercase}.dp-panel-insight strong{font-size:22px;line-height:1.15;color:#18202a}.dp-panel-insight small{color:#667085}.dp-panel-insight-icon{position:absolute;right:12px;top:10px;color:#98a2b3;font-size:13px}.dp-panel-insight-primary{border-top-color:#1f6feb}.dp-panel-insight-success{border-top-color:#079455}.dp-panel-insight-warning{border-top-color:#dc6803}.dp-panel-insight-danger{border-top-color:#d92d20}.dp-panel-insight-info{border-top-color:#026aa2}.dp-panel-insight:hover strong{color:#1f6feb}';
	}

	/**
	 * Supplies record link grid styles.
	 *
	 * link cards expose sanitized destinations plus optional group, icon,
	 * description, and tone metadata. This asset owns the responsive card grid and
	 * external-link affordance styling.
	 */
	private static function linksCss(): string {
		return '.dp-panel-links{border:1px solid #d9e0ea;border-radius:8px;background:#fff;margin:0 0 12px;padding:12px}.dp-panel-links>header{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 10px}.dp-panel-links h2{font-size:16px;margin:0;color:#18202a}.dp-panel-links>header span{font-size:12px;color:#667085}.dp-panel-link-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:8px}.dp-panel-link{display:grid;gap:5px;border:1px solid #d9e0ea;border-left:3px solid #98a2b3;border-radius:8px;background:#f8fafc;color:#18202a;padding:10px;text-decoration:none;min-width:0}.dp-panel-link:hover{border-color:#b7c4d6;background:#fff}.dp-panel-link strong{font-size:14px;color:#18202a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.dp-panel-link span{font-size:12px;color:#667085}.dp-panel-link-top{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:16px}.dp-panel-link-top small{color:#667085;text-transform:uppercase;font-size:11px;font-weight:700}.dp-panel-link-icon{color:#667085}.dp-panel-link-primary{border-left-color:#1f6feb}.dp-panel-link-success{border-left-color:#079455}.dp-panel-link-warning{border-left-color:#dc6803}.dp-panel-link-danger{border-left-color:#d92d20}.dp-panel-link-info{border-left-color:#026aa2}';
	}

	/**
	 * Supplies record contact list styles.
	 *
	 * contact cards can include identity, role, status, email, telephone,
	 * company, location, and profile links. The CSS preserves readable hierarchy and
	 * tone badges without knowing the source contact model.
	 */
	private static function contactsCss(): string {
		return '.dp-panel-contacts{display:grid;gap:0;margin:18px 0;border:1px solid #d9e0ea;border-radius:8px;background:#fff;overflow:hidden}.dp-panel-contacts>header{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0;padding:13px 14px;border-bottom:1px solid #e7ecf2}.dp-panel-contacts>header h2{margin:0;font-size:16px}.dp-panel-contacts>header span{color:#667085;font-size:12px;font-weight:700}.dp-panel-contact-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(235px,1fr));gap:10px;padding:12px}.dp-panel-contact{display:grid;gap:8px;border:1px solid #e7ecf2;border-left:3px solid #98a2b3;border-radius:8px;background:#f8fafc;padding:11px}.dp-panel-contact-primary{border-left-color:#1f6feb}.dp-panel-contact-success{border-left-color:#079455}.dp-panel-contact-warning{border-left-color:#dc6803}.dp-panel-contact-danger{border-left-color:#d92d20}.dp-panel-contact-info{border-left-color:#026aa2}.dp-panel-contact header{display:flex;justify-content:space-between;gap:8px;align-items:flex-start;margin:0}.dp-panel-contact header strong,.dp-panel-contact header a{color:#18202a;font-weight:700;text-decoration:none;overflow-wrap:anywhere}.dp-panel-contact header a:hover,.dp-panel-contact-details a:hover{color:#1f6feb;text-decoration:underline}.dp-panel-contact header small{display:block;color:#667085;margin-top:2px}.dp-panel-contact-details{display:flex;flex-wrap:wrap;gap:7px;color:#667085}.dp-panel-contact-details a,.dp-panel-contact-details span{color:#667085;text-decoration:none}.dp-panel-contact-details a+*,.dp-panel-contact-details span+*{position:relative}.dp-panel-contact-details a+*:before,.dp-panel-contact-details span+*:before{content:"";display:inline-block;width:4px;height:4px;margin:0 7px 2px 0;border-radius:999px;background:#cad3df}';
	}

	/**
	 * Supplies record location list styles.
	 *
	 * location cards render address lines, status, type, timezone, and
	 * coordinates from normalized resource data. The asset styles address semantics
	 * and map-link affordances while leaving geocoding out of the renderer.
	 */
	private static function locationsCss(): string {
		return '.dp-panel-locations{display:grid;gap:0;margin:18px 0;border:1px solid #d9e0ea;border-radius:8px;background:#fff;overflow:hidden}.dp-panel-locations>header{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0;padding:13px 14px;border-bottom:1px solid #e7ecf2}.dp-panel-locations>header h2{margin:0;font-size:16px}.dp-panel-locations>header span{color:#667085;font-size:12px;font-weight:700}.dp-panel-location-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:10px;padding:12px}.dp-panel-location{display:grid;gap:8px;border:1px solid #e7ecf2;border-left:3px solid #98a2b3;border-radius:8px;background:#f8fafc;padding:11px}.dp-panel-location-primary{border-left-color:#1f6feb}.dp-panel-location-success{border-left-color:#079455}.dp-panel-location-warning{border-left-color:#dc6803}.dp-panel-location-danger{border-left-color:#d92d20}.dp-panel-location-info{border-left-color:#026aa2}.dp-panel-location header{display:flex;justify-content:space-between;gap:8px;align-items:flex-start;margin:0}.dp-panel-location header strong,.dp-panel-location header a{color:#18202a;font-weight:700;text-decoration:none;overflow-wrap:anywhere}.dp-panel-location header a:hover{color:#1f6feb;text-decoration:underline}.dp-panel-location header small{display:block;color:#667085;margin-top:2px}.dp-panel-location address{display:grid;gap:2px;margin:0;color:#344054;font-style:normal;overflow-wrap:anywhere}.dp-panel-location-meta{display:flex;flex-wrap:wrap;gap:7px;color:#667085}.dp-panel-location-meta span+span:before{content:"";display:inline-block;width:4px;height:4px;margin:0 7px 2px 0;border-radius:999px;background:#cad3df}';
	}

	/**
	 * Supplies record tag chip and tag mutation form styles.
	 *
	 * tag chips can be display-only or include add/remove controls guarded
	 * by resource abilities. The CSS keeps inline forms, confirmation buttons, tone
	 * chips, and empty states visually coherent.
	 */
	private static function tagsCss(): string {
		return '.dp-panel-tags{display:grid;gap:0;margin:18px 0;border:1px solid #d9e0ea;border-radius:8px;background:#fff;overflow:hidden}.dp-panel-tags>header{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0;padding:13px 14px;border-bottom:1px solid #e7ecf2}.dp-panel-tags>header h2{margin:0;font-size:16px}.dp-panel-tags>header span{color:#667085;font-size:12px;font-weight:700}.dp-panel-tag-list{display:flex;flex-wrap:wrap;gap:8px;padding:12px}.dp-panel-tag{display:inline-flex;align-items:center;gap:6px;border:1px solid #d9e0ea;border-left:3px solid #98a2b3;border-radius:999px;background:#f8fafc;color:#344054;padding:6px 9px;font-size:12px;font-weight:700}.dp-panel-tag-primary{border-left-color:#1f6feb}.dp-panel-tag-success{border-left-color:#079455}.dp-panel-tag-warning{border-left-color:#dc6803}.dp-panel-tag-danger{border-left-color:#d92d20}.dp-panel-tag-info{border-left-color:#026aa2}.dp-panel-tag form{display:inline}.dp-panel-tag button{display:inline-grid;place-items:center;border:0;border-radius:999px;background:#e7ecf2;color:#344054;width:18px;height:18px;padding:0;line-height:1;cursor:pointer}.dp-panel-tag button:hover{background:#d0d8e4}.dp-panel-tag-form{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;padding:13px 14px;border-top:1px solid #e7ecf2;background:#f8fafc}.dp-panel-tag-form label{display:grid;gap:6px}.dp-panel-tag-form span{font-weight:700}.dp-panel-tag-form input{border:1px solid #cad3df;border-radius:6px;padding:10px;font-size:14px;background:#fff;color:#18202a}';
	}

	/**
	 * Supplies record item list styles.
	 *
	 * item rows represent order, invoice, inventory, or other line-style
	 * data normalized by the record renderer. The CSS owns row hierarchy, status
	 * badges, metadata wrapping, and responsive density.
	 */
	private static function itemsCss(): string {
		return '.dp-panel-items{display:grid;gap:0;margin:18px 0;border:1px solid #d9e0ea;border-radius:8px;background:#fff;overflow:hidden}.dp-panel-items>header{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0;padding:13px 14px;border-bottom:1px solid #e7ecf2}.dp-panel-items>header h2{margin:0;font-size:16px}.dp-panel-items>header span{color:#667085;font-size:12px;font-weight:700}.dp-panel-item-list{display:grid;gap:0}.dp-panel-item{display:grid;gap:8px;padding:13px 14px;border-left:3px solid #98a2b3}.dp-panel-item+.dp-panel-item{border-top:1px solid #f0f3f7}.dp-panel-item-primary{border-left-color:#1f6feb}.dp-panel-item-success{border-left-color:#079455}.dp-panel-item-warning{border-left-color:#dc6803}.dp-panel-item-danger{border-left-color:#d92d20}.dp-panel-item-info{border-left-color:#026aa2}.dp-panel-item header{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin:0}.dp-panel-item header strong,.dp-panel-item header a{color:#18202a;font-weight:700;text-decoration:none;overflow-wrap:anywhere}.dp-panel-item header a:hover{color:#1f6feb;text-decoration:underline}.dp-panel-item small{display:flex;flex-wrap:wrap;gap:9px;color:#667085}.dp-panel-item small span b{color:#344054}.dp-panel-item small span+span:before{content:"";display:inline-block;width:4px;height:4px;margin:0 9px 2px 0;border-radius:999px;background:#cad3df}';
	}

	/**
	 * Supplies record total summary card styles.
	 *
	 * totals are preformatted by PHP and may carry status-derived tones.
	 * This asset keeps financial or aggregate summaries distinct from line items
	 * while using the same panel token system.
	 */
	private static function totalsCss(): string {
		return '.dp-panel-totals{display:grid;gap:0;margin:18px 0;border:1px solid #d9e0ea;border-radius:8px;background:#fff;overflow:hidden}.dp-panel-totals>header{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0;padding:13px 14px;border-bottom:1px solid #e7ecf2}.dp-panel-totals>header h2{margin:0;font-size:16px}.dp-panel-totals>header span{color:#667085;font-size:12px;font-weight:700}.dp-panel-total-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;padding:12px}.dp-panel-total{display:grid;gap:4px;border:1px solid #e7ecf2;border-top:3px solid #98a2b3;border-radius:8px;background:#f8fafc;padding:11px}.dp-panel-total-primary{border-top-color:#1f6feb}.dp-panel-total-success{border-top-color:#079455}.dp-panel-total-warning{border-top-color:#dc6803}.dp-panel-total-danger{border-top-color:#d92d20}.dp-panel-total-info{border-top-color:#026aa2}.dp-panel-total span{color:#667085;font-size:11px;font-weight:700;text-transform:uppercase}.dp-panel-total strong{font-size:19px;color:#18202a;overflow-wrap:anywhere}.dp-panel-total small{color:#667085}';
	}

	/**
	 * Supplies approval workflow card styles.
	 *
	 * approval markup may include pending counts, requester metadata, and
	 * guarded approve/reject forms. The CSS separates workflow state, mutation
	 * controls, and explanatory copy for operator review.
	 */
	private static function approvalsCss(): string {
		return '.dp-panel-approvals{display:grid;gap:0;margin:18px 0;border:1px solid #d9e0ea;border-radius:8px;background:#fff;overflow:hidden}.dp-panel-approvals>header{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0;padding:13px 14px;border-bottom:1px solid #e7ecf2}.dp-panel-approvals>header h2{margin:0;font-size:16px}.dp-panel-approvals>header span{color:#667085;font-size:12px;font-weight:700}.dp-panel-approval-list{display:grid;gap:0}.dp-panel-approval{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;padding:13px 14px;border-left:3px solid #98a2b3}.dp-panel-approval+.dp-panel-approval{border-top:1px solid #f0f3f7}.dp-panel-approval-primary{border-left-color:#1f6feb}.dp-panel-approval-success{border-left-color:#079455}.dp-panel-approval-warning{border-left-color:#dc6803}.dp-panel-approval-danger{border-left-color:#d92d20}.dp-panel-approval-info{border-left-color:#026aa2}.dp-panel-approval-resolved{background:#fafbfc}.dp-panel-approval-body{display:grid;gap:4px}.dp-panel-approval-body strong{color:#18202a}.dp-panel-approval-body p{margin:0;color:#344054;overflow-wrap:anywhere}.dp-panel-approval-body small{display:flex;flex-wrap:wrap;gap:7px;color:#667085}.dp-panel-approval-body small span+span:before{content:"";display:inline-block;width:4px;height:4px;margin:0 7px 2px 0;border-radius:999px;background:#cad3df}.dp-panel-approval-side{display:grid;gap:8px;justify-items:end;align-content:start}.dp-panel-approval-actions{display:flex;flex-wrap:wrap;gap:7px;justify-content:flex-end}@media(max-width:760px){.dp-panel-approval{grid-template-columns:1fr}.dp-panel-approval-side{justify-items:start}.dp-panel-approval-actions{justify-content:flex-start}}';
	}

	/**
	 * Supplies status-board and kanban-like table view styles.
	 *
	 * board views reuse table data but render status columns, pulses,
	 * draggable-looking cards, recommendations, and empty lanes. This asset owns
	 * the visual contract while data grouping stays in the table renderer.
	 */
	private static function boardCss(): string {
		return '.dp-panel-board-card[draggable=true]{cursor:grab}.dp-panel-board-card.dp-panel-board-dragging{opacity:.55;cursor:grabbing}.dp-panel-board-column.dp-panel-board-drop{outline:2px solid #1f6feb;outline-offset:-4px;background:#eff6ff}.dp-panel-board-column.dp-panel-board-drop-blocked{outline:2px solid #d92d20;outline-offset:-4px;background:#fff1f0}';
	}

	/**
	 * Supplies record task list and task transition styles.
	 *
	 * task markup can include completion state, due metadata, assignee,
	 * guarded state-change forms, and tone classes. The CSS makes those states
	 * readable without embedding task semantics in the client script.
	 */
	private static function tasksCss(): string {
		return '.dp-panel-tasks{display:grid;gap:0;margin:18px 0;border:1px solid #d9e0ea;border-radius:8px;background:#fff;overflow:hidden}.dp-panel-tasks>header{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0;padding:13px 14px;border-bottom:1px solid #e7ecf2}.dp-panel-tasks>header h2{margin:0;font-size:16px}.dp-panel-tasks>header span{color:#667085;font-size:12px;font-weight:700}.dp-panel-task-list{display:grid;gap:0}.dp-panel-task{display:grid;grid-template-columns:28px minmax(0,1fr) auto;gap:10px;align-items:start;padding:13px 14px;border-left:3px solid #98a2b3}.dp-panel-task+.dp-panel-task{border-top:1px solid #f0f3f7}.dp-panel-task-success{border-left-color:#079455}.dp-panel-task-warning{border-left-color:#dc6803}.dp-panel-task-danger{border-left-color:#d92d20}.dp-panel-task-info{border-left-color:#026aa2}.dp-panel-task-primary{border-left-color:#1f6feb}.dp-panel-task-check{display:grid;place-items:center;width:20px;height:20px;border:1px solid #cad3df;border-radius:999px;color:#079455;font-weight:700}.dp-panel-task-complete .dp-panel-task-check{border-color:#079455;background:#ecfdf3}.dp-panel-task-body{display:grid;gap:4px}.dp-panel-task-body strong{color:#18202a}.dp-panel-task-complete .dp-panel-task-body strong{text-decoration:line-through;color:#667085}.dp-panel-task-body p{margin:0;color:#344054;overflow-wrap:anywhere}.dp-panel-task-body small{display:flex;flex-wrap:wrap;gap:7px;color:#667085}.dp-panel-task-body small span+span:before{content:"";display:inline-block;width:4px;height:4px;margin:0 7px 2px 0;border-radius:999px;background:#cad3df}.dp-panel-task-action{display:flex;justify-content:flex-end}@media(max-width:760px){.dp-panel-task{grid-template-columns:28px minmax(0,1fr)}.dp-panel-task-action{grid-column:2;justify-content:flex-start}}';
	}

	/**
	 * Supplies add-task form layout styles.
	 *
	 * task creation forms are emitted inside modal content and include due
	 * date and assignee controls. This asset keeps the compact form layout stable in
	 * both dialog and slide-over containers.
	 */
	private static function taskFormCss(): string {
		return '.dp-panel-task-form{display:grid;gap:10px;padding:13px 14px;border-top:1px solid #e7ecf2;background:#f8fafc}.dp-panel-task-form label{display:grid;gap:6px}.dp-panel-task-form span{font-weight:700}.dp-panel-task-form input,.dp-panel-task-form textarea{border:1px solid #cad3df;border-radius:6px;padding:10px;font-size:14px;background:#fff;color:#18202a}.dp-panel-task-form textarea{resize:vertical}.dp-panel-task-form-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.dp-panel-task-form button{justify-self:start}@media(max-width:760px){.dp-panel-task-form-row{grid-template-columns:1fr}}';
	}

	/**
	 * Supplies chronological activity feed styles.
	 *
	 * activity entries include title, message, actor, time, URL, meta, and
	 * tone data. The CSS owns timeline dots, spacing, and muted metadata treatment
	 * while the renderer controls audit content.
	 */
	private static function activityCss(): string {
		return '.dp-panel-activity{display:grid;gap:0;margin:18px 0;border:1px solid #d9e0ea;border-radius:8px;background:#fff;overflow:hidden}.dp-panel-activity>header{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0;padding:13px 14px;border-bottom:1px solid #e7ecf2}.dp-panel-activity>header h2{margin:0;font-size:16px}.dp-panel-activity>header span{color:#667085;font-size:12px;font-weight:700}.dp-panel-activity-item{display:grid;grid-template-columns:18px minmax(0,1fr);gap:10px;position:relative;padding:13px 14px}.dp-panel-activity-item+.dp-panel-activity-item{border-top:1px solid #f0f3f7}.dp-panel-activity-dot{width:10px;height:10px;border-radius:999px;background:#98a2b3;margin-top:5px;box-shadow:0 0 0 4px #eef2f7}.dp-panel-activity-primary .dp-panel-activity-dot{background:#1f6feb}.dp-panel-activity-success .dp-panel-activity-dot{background:#079455}.dp-panel-activity-warning .dp-panel-activity-dot{background:#dc6803}.dp-panel-activity-danger .dp-panel-activity-dot{background:#d92d20}.dp-panel-activity-info .dp-panel-activity-dot{background:#026aa2}.dp-panel-activity-body{display:grid;gap:4px}.dp-panel-activity-body strong,.dp-panel-activity-body a{color:#18202a;font-weight:700;text-decoration:none}.dp-panel-activity-body a:hover{color:#1f6feb;text-decoration:underline}.dp-panel-activity-body p{margin:0;color:#344054;overflow-wrap:anywhere}.dp-panel-activity-body small{display:flex;flex-wrap:wrap;gap:7px;color:#667085}.dp-panel-activity-body small span+span:before{content:"";display:inline-block;width:4px;height:4px;margin:0 7px 2px 0;border-radius:999px;background:#cad3df}';
	}

	/**
	 * Supplies before/after change-history styles.
	 *
	 * change cards render field names plus old and new values from audit
	 * payloads. This asset keeps diffs legible and wraps long values without
	 * changing the audit data shape.
	 */
	private static function changesCss(): string {
		return '.dp-panel-changes{display:grid;gap:0;margin:18px 0;border:1px solid #d9e0ea;border-radius:8px;background:#fff;overflow:hidden}.dp-panel-changes>header{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0;padding:13px 14px;border-bottom:1px solid #e7ecf2}.dp-panel-changes>header h2{margin:0;font-size:16px}.dp-panel-changes>header span{color:#667085;font-size:12px;font-weight:700}.dp-panel-change-list{display:grid;gap:0}.dp-panel-change{display:grid;gap:10px;padding:13px 14px;border-left:3px solid #98a2b3}.dp-panel-change+.dp-panel-change{border-top:1px solid #f0f3f7}.dp-panel-change-primary{border-left-color:#1f6feb}.dp-panel-change-success{border-left-color:#079455}.dp-panel-change-warning{border-left-color:#dc6803}.dp-panel-change-danger{border-left-color:#d92d20}.dp-panel-change-info{border-left-color:#026aa2}.dp-panel-change header{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin:0}.dp-panel-change header strong,.dp-panel-change header a{color:#18202a;font-weight:700;text-decoration:none}.dp-panel-change header a:hover{color:#1f6feb;text-decoration:underline}.dp-panel-change header small{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:7px;color:#667085}.dp-panel-change header small span+span:before{content:"";display:inline-block;width:4px;height:4px;margin:0 7px 2px 0;border-radius:999px;background:#cad3df}.dp-panel-change-values{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.dp-panel-change-values div{display:grid;gap:4px;border:1px solid #e7ecf2;border-radius:8px;background:#f8fafc;padding:9px}.dp-panel-change-values span{color:#667085;font-size:11px;font-weight:700;text-transform:uppercase}.dp-panel-change-values code{white-space:pre-wrap;overflow-wrap:anywhere;color:#18202a;font-family:Consolas,Monaco,monospace;font-size:12px}.dp-panel-change p{margin:0;color:#344054;overflow-wrap:anywhere}@media(max-width:760px){.dp-panel-change header{display:grid}.dp-panel-change header small{justify-content:flex-start}.dp-panel-change-values{grid-template-columns:1fr}}';
	}

	/**
	 * Supplies payment summary card styles.
	 *
	 * payment cards are display-only surfaces for amounts, status, type,
	 * provider, reference, time, and optional dashboard links. The CSS expresses
	 * settlement tone while avoiding payment processor coupling.
	 */
	private static function paymentsCss(): string {
		return '.dp-panel-payments{display:grid;gap:0;margin:18px 0;border:1px solid #d9e0ea;border-radius:8px;background:#fff;overflow:hidden}.dp-panel-payments>header{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0;padding:13px 14px;border-bottom:1px solid #e7ecf2}.dp-panel-payments>header h2{margin:0;font-size:16px}.dp-panel-payments>header span{color:#667085;font-size:12px;font-weight:700}.dp-panel-payment-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:10px;padding:12px}.dp-panel-payment{display:grid;gap:8px;border:1px solid #e7ecf2;border-left:3px solid #98a2b3;border-radius:8px;background:#f8fafc;padding:11px}.dp-panel-payment-primary{border-left-color:#1f6feb}.dp-panel-payment-success{border-left-color:#079455}.dp-panel-payment-warning{border-left-color:#dc6803}.dp-panel-payment-danger{border-left-color:#d92d20}.dp-panel-payment-info{border-left-color:#026aa2}.dp-panel-payment header{display:flex;justify-content:space-between;gap:8px;align-items:flex-start;margin:0}.dp-panel-payment header strong,.dp-panel-payment header a{color:#18202a;font-weight:700;text-decoration:none;overflow-wrap:anywhere}.dp-panel-payment header a:hover{color:#1f6feb;text-decoration:underline}.dp-panel-payment-amount{font-size:20px;font-weight:700;color:#18202a}.dp-panel-payment small{display:flex;flex-wrap:wrap;gap:7px;color:#667085}.dp-panel-payment small span+span:before{content:"";display:inline-block;width:4px;height:4px;margin:0 7px 2px 0;border-radius:999px;background:#cad3df}';
	}

	/**
	 * Supplies shipment and tracking card styles.
	 *
	 * shipment cards expose tracking numbers, carrier, service, route, ETA,
	 * and status tone from normalized payloads. The CSS makes tracking identifiers
	 * and fulfillment metadata scannable.
	 */
	private static function shipmentsCss(): string {
		return '.dp-panel-shipments{display:grid;gap:0;margin:18px 0;border:1px solid #d9e0ea;border-radius:8px;background:#fff;overflow:hidden}.dp-panel-shipments>header{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0;padding:13px 14px;border-bottom:1px solid #e7ecf2}.dp-panel-shipments>header h2{margin:0;font-size:16px}.dp-panel-shipments>header span{color:#667085;font-size:12px;font-weight:700}.dp-panel-shipment-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:10px;padding:12px}.dp-panel-shipment{display:grid;gap:8px;border:1px solid #e7ecf2;border-left:3px solid #98a2b3;border-radius:8px;background:#f8fafc;padding:11px}.dp-panel-shipment-primary{border-left-color:#1f6feb}.dp-panel-shipment-success{border-left-color:#079455}.dp-panel-shipment-warning{border-left-color:#dc6803}.dp-panel-shipment-danger{border-left-color:#d92d20}.dp-panel-shipment-info{border-left-color:#026aa2}.dp-panel-shipment header{display:flex;justify-content:space-between;gap:8px;align-items:flex-start;margin:0}.dp-panel-shipment header strong,.dp-panel-shipment header a{color:#18202a;font-weight:700;text-decoration:none;overflow-wrap:anywhere}.dp-panel-shipment header a:hover{color:#1f6feb;text-decoration:underline}.dp-panel-shipment code{border:1px solid #d9e0ea;border-radius:6px;background:#fff;color:#18202a;padding:6px 8px;font-family:Consolas,Monaco,monospace;font-size:12px;overflow-wrap:anywhere}.dp-panel-shipment small{display:flex;flex-wrap:wrap;gap:7px;color:#667085}.dp-panel-shipment small span+span:before{content:"";display:inline-block;width:4px;height:4px;margin:0 7px 2px 0;border-radius:999px;background:#cad3df}';
	}

	/**
	 * Supplies notes, attachments, and messages record-section styles.
	 *
	 * these surfaces mix historical entries with optional guarded modal
	 * forms. This asset owns list density, metadata treatment, empty states, and
	 * upload/message/note form layout.
	 */
	private static function notesCss(): string {
		return '.dp-panel-notes{display:grid;gap:0;margin:18px 0;border:1px solid #d9e0ea;border-radius:8px;background:#fff;overflow:hidden}.dp-panel-notes>header{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0;padding:13px 14px;border-bottom:1px solid #e7ecf2}.dp-panel-notes>header h2{margin:0;font-size:16px}.dp-panel-notes>header span{color:#667085;font-size:12px;font-weight:700}.dp-panel-note-list{display:grid;gap:0}.dp-panel-note{display:grid;gap:6px;padding:13px 14px}.dp-panel-note+.dp-panel-note{border-top:1px solid #f0f3f7}.dp-panel-note p{margin:0;color:#344054;white-space:pre-wrap;overflow-wrap:anywhere}.dp-panel-note small{display:flex;flex-wrap:wrap;gap:7px;color:#667085}.dp-panel-note small span+span:before{content:"";display:inline-block;width:4px;height:4px;margin:0 7px 2px 0;border-radius:999px;background:#cad3df}.dp-panel-note-form{display:grid;gap:10px;padding:13px 14px;border-top:1px solid #e7ecf2;background:#f8fafc}.dp-panel-note-form label{display:grid;gap:6px}.dp-panel-note-form span{font-weight:700}.dp-panel-note-form textarea{border:1px solid #cad3df;border-radius:6px;padding:10px;font-size:14px;background:#fff;color:#18202a;resize:vertical}.dp-panel-note-form button{justify-self:start}';
	}

	/**
	 * Supplies hardening overrides for dark and system theme edge cases.
	 *
	 * these rules compensate for token combinations that can lose contrast
	 * in dark or glass modes. The asset is deliberately late in the component CSS
	 * sequence so it can override earlier generated surfaces.
	 */
	private static function themeOverrideCss(): string {
		return <<<'CSS'
.dp-panel{max-width:var(--dp-max_width);padding:var(--dp-panel_padding)}.dp-panel-grid,.dp-panel-widgets,.dp-panel-board,.dp-panel-form,.dp-panel-form-grid,.dp-panel-alerts,.dp-panel-insights,.dp-panel-search-results,.dp-panel-page-table,.dp-panel-custom-page{gap:var(--dp-gap)}body{color:var(--dp-text);font-family:var(--dp-font_family,Arial,sans-serif)}.dp-panel>header{border-color:var(--dp-border);background:color-mix(in srgb,var(--dp-surface) 92%,transparent);border-radius:calc(var(--dp-radius) + 8px)}.dp-panel-heading-row{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.dp-panel-heading-row>div{min-width:0}.dp-panel-brand{margin:0 0 10px}.dp-panel-brand img{display:block;max-width:220px;max-height:56px;object-fit:contain}.dp-panel-brand-logo-dark{display:none!important}[data-dp-theme-mode="dark"] .dp-panel-brand-logo-dark{display:block!important}[data-dp-theme-mode="dark"] .dp-panel-brand-logo-dark+.dp-panel-brand-logo,[data-dp-theme-mode="dark"] .dp-panel-brand-logo:has(+.dp-panel-brand-logo-dark){display:none!important}.dp-panel-theme-toggle{display:inline-flex;gap:3px;border:1px solid var(--dp-border);border-radius:999px;background:var(--dp-neutral_bg);padding:3px}.dp-panel-theme-toggle button{border:0;border-radius:999px;background:transparent;color:var(--dp-neutral_text);padding:7px 10px;font:inherit;font-size:12px;font-weight:800;cursor:pointer}.dp-panel-theme-toggle button[aria-pressed=true]{background:var(--dp-surface);color:var(--dp-primary-700);box-shadow:0 4px 12px rgba(16,24,40,.10)}.dp-panel-breadcrumbs,.dp-panel-empty,.dp-panel-empty-state span,header p,.dp-panel-nav-group header span,.dp-panel-nav-card small,.dp-panel-widget small,.dp-panel-widget-label,.dp-panel-filter span,.dp-panel-table th,.dp-panel-pagination,.dp-panel-show-field span,.dp-panel-record-heading p,.dp-panel-record-heading span{color:var(--dp-text_muted)}.dp-panel-breadcrumbs a,.dp-panel-cell-link,.dp-panel-actions a,.dp-panel-actions button,.dp-panel-search-result:hover strong,.dp-panel-nav-card:hover span,.dp-panel-board-title:hover,.dp-panel-activity-body a:hover,.dp-panel-change header a:hover,.dp-panel-payment header a:hover,.dp-panel-shipment header a:hover,.dp-panel-attachment a:hover{color:var(--dp-primary-600)}.dp-panel-nav-card,.dp-panel-widget,.dp-panel-table,.dp-panel-form-section,.dp-panel-form-details,.dp-panel-search-result,.dp-panel-summary,.dp-panel-record-heading,.dp-panel-show-field,.dp-panel-filter-chip,.dp-panel-custom-page>section,.dp-panel-custom-page>article,.dp-panel-custom-page pre,.dp-panel-board-card,.dp-panel-board-column>header,.dp-panel-alert-card,.dp-panel-links,.dp-panel-contacts,.dp-panel-locations,.dp-panel-tags,.dp-panel-items,.dp-panel-totals,.dp-panel-approvals,.dp-panel-tasks,.dp-panel-activity,.dp-panel-changes,.dp-panel-payments,.dp-panel-shipments,.dp-panel-attachments,.dp-panel-messages,.dp-panel-notes{background:var(--dp-surface);border-color:var(--dp-border);color:var(--dp-text);border-radius:var(--dp-radius)}.dp-panel-nav-card,.dp-panel-widget,.dp-panel-table,.dp-panel-form-section,.dp-panel-summary,.dp-panel-record-heading,.dp-panel-search-result,.dp-panel-custom-page>section,.dp-panel-custom-page>article{box-shadow:0 12px 32px rgba(15,23,42,.055)}.dp-panel-board-column,.dp-panel-payment,.dp-panel-shipment,.dp-panel-location,.dp-panel-contact,.dp-panel-link,.dp-panel-item,.dp-panel-total,.dp-panel-task-form,.dp-panel-note-form,.dp-panel-message-form,.dp-panel-attachment-form,.dp-panel-repeater-row{background:var(--dp-surface_muted);border-color:var(--dp-border_soft);border-radius:var(--dp-radius)}.dp-panel-button,.dp-panel-action,.dp-panel-density a.active,.dp-panel-table-view.active{background:var(--dp-primary-600);border-color:var(--dp-primary-600);color:#fff}.dp-panel-button-secondary,.dp-panel-action-neutral,.dp-panel-column-picker summary,.dp-panel-density,.dp-panel-page-disabled,.dp-panel-badge,.dp-panel-nav-badge{background:var(--dp-neutral_bg);color:var(--dp-neutral_text);border-color:var(--dp-border)}.dp-panel-search input,.dp-panel-filter input,.dp-panel-filter select,.dp-panel-field input,.dp-panel-field select,.dp-panel-field textarea,.dp-panel-per-page select,.dp-panel-global-search input,.dp-panel-message-form input,.dp-panel-message-form select,.dp-panel-message-form textarea,.dp-panel-note-form textarea,.dp-panel-attachment-form input[type=file]{background:var(--dp-control_bg);border-color:var(--dp-control_border);color:var(--dp-text);border-radius:calc(var(--dp-radius) - 2px);padding:var(--dp-input_padding)}.dp-panel-button,.dp-panel-action,.dp-panel-density a,.dp-panel-column-picker summary,.dp-panel-page-disabled,.dp-panel-table-view,.dp-panel-filter-chip{padding:var(--dp-control_padding)}.dp-panel-table th,.dp-panel-table td{padding:var(--dp-table_cell_padding);border-color:var(--dp-border_soft)}.dp-panel-nav-card,.dp-panel-widget,.dp-panel-form-details,.dp-panel-search-result,.dp-panel-summary,.dp-panel-record-heading,.dp-panel-show-field,.dp-panel-custom-page>section,.dp-panel-custom-page>article,.dp-panel-board-card,.dp-panel-alert-card,.dp-panel-link,.dp-panel-contact,.dp-panel-location,.dp-panel-tag,.dp-panel-item,.dp-panel-total,.dp-panel-task,.dp-panel-payment,.dp-panel-shipment,.dp-panel-attachment{padding:var(--dp-section_padding)}.dp-panel-board-column>header,.dp-panel-contacts>header,.dp-panel-locations>header,.dp-panel-tags>header,.dp-panel-items>header,.dp-panel-totals>header,.dp-panel-approvals>header,.dp-panel-tasks>header,.dp-panel-activity>header,.dp-panel-changes>header,.dp-panel-payments>header,.dp-panel-shipments>header,.dp-panel-attachments>header,.dp-panel-messages>header,.dp-panel-notes>header{border-color:var(--dp-border_soft)}.dp-panel-table{overflow:hidden}.dp-panel-table th{background:var(--dp-surface_muted)}.dp-panel-table tbody tr:hover{background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 45%,var(--dp-surface))}.dp-panel-widget-primary,.dp-panel-summary-primary,.dp-panel-board-column-primary,.dp-panel-approval-primary,.dp-panel-task-primary,.dp-panel-change-primary,.dp-panel-payment-primary,.dp-panel-shipment-primary,.dp-panel-message-primary{border-left-color:var(--dp-primary-600);border-top-color:var(--dp-primary-600)}.dp-panel-widget-success,.dp-panel-summary-success,.dp-panel-board-column-success,.dp-panel-approval-success,.dp-panel-task-success,.dp-panel-change-success,.dp-panel-payment-success,.dp-panel-shipment-success,.dp-panel-message-success{border-left-color:var(--dp-success-600);border-top-color:var(--dp-success-600)}.dp-panel-widget-warning,.dp-panel-summary-warning,.dp-panel-board-column-warning,.dp-panel-approval-warning,.dp-panel-task-warning,.dp-panel-change-warning,.dp-panel-payment-warning,.dp-panel-shipment-warning,.dp-panel-message-warning{border-left-color:var(--dp-warning-600);border-top-color:var(--dp-warning-600)}.dp-panel-widget-danger,.dp-panel-summary-danger,.dp-panel-board-column-danger,.dp-panel-approval-danger,.dp-panel-task-danger,.dp-panel-change-danger,.dp-panel-payment-danger,.dp-panel-shipment-danger,.dp-panel-message-danger{border-left-color:var(--dp-danger-600);border-top-color:var(--dp-danger-600)}.dp-panel-widget-info,.dp-panel-summary-info,.dp-panel-board-column-info,.dp-panel-approval-info,.dp-panel-task-info,.dp-panel-change-info,.dp-panel-payment-info,.dp-panel-shipment-info,.dp-panel-message-info{border-left-color:var(--dp-info-600);border-top-color:var(--dp-info-600)}.dp-panel-action-primary{background:var(--dp-primary-600)}.dp-panel-action-success{background:var(--dp-success-600)}.dp-panel-action-warning{background:var(--dp-warning-600)}.dp-panel-action-danger{background:var(--dp-danger-600)}.dp-panel-badge-primary,.dp-panel-nav-badge-primary{background:var(--dp-primary-100);color:var(--dp-primary-800)}.dp-panel-badge-success,.dp-panel-nav-badge-success{background:var(--dp-success-100);color:var(--dp-success-800)}.dp-panel-badge-warning,.dp-panel-nav-badge-warning{background:var(--dp-warning-100);color:var(--dp-warning-800)}.dp-panel-badge-danger,.dp-panel-nav-badge-danger{background:var(--dp-danger-100);color:var(--dp-danger-800)}.dp-panel-badge-info,.dp-panel-nav-badge-info{background:var(--dp-info-100);color:var(--dp-info-800)}.dp-panel-modal-root{backdrop-filter:blur(8px)}.dp-panel-modal{border-radius:calc(var(--dp-radius) + 4px)}.dp-panel-tab-list,.dp-panel-step-list{gap:8px}.dp-panel-tab-list button,.dp-panel-step-list button{box-shadow:0 4px 12px rgba(15,23,42,.04)}.dp-panel-nav-card{display:grid;grid-template-columns:auto minmax(0,1fr);grid-template-areas:"icon label" "icon detail";column-gap:14px;row-gap:3px;align-items:center;min-height:112px}.dp-panel-nav-icon{grid-area:icon;display:grid;place-items:center;width:44px;height:44px;border-radius:14px;background:var(--dp-primary-100);color:var(--dp-primary-800);font-style:normal;font-size:13px;font-weight:900;letter-spacing:.02em;box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--dp-primary-600) 16%,transparent)}.dp-panel-nav-card span{grid-area:label;display:flex;align-items:center;justify-content:space-between;gap:10px;min-width:0}.dp-panel-nav-card span strong{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:15px;font-weight:850}.dp-panel-nav-card small{grid-area:detail;margin:0;line-height:1.35}.dp-panel-table-views{width:max-content;max-width:100%;padding:4px;background:var(--dp-surface_muted);border:1px solid var(--dp-border_soft);border-radius:999px}.dp-panel-table-view{border-color:transparent;background:transparent;box-shadow:none}.dp-panel-table-view-dot{width:8px;height:8px;border-radius:999px;background:currentColor;opacity:.52;flex:0 0 auto}.dp-panel-table-view.active .dp-panel-table-view-dot{opacity:1;background:#fff}.dp-panel-table-view-primary.active{background:var(--dp-primary-600);border-color:var(--dp-primary-600)}.dp-panel-table-view-success.active{background:var(--dp-success-600);border-color:var(--dp-success-600)}.dp-panel-table-view-warning.active{background:var(--dp-warning-600);border-color:var(--dp-warning-600)}.dp-panel-table-view-danger.active{background:var(--dp-danger-600);border-color:var(--dp-danger-600)}.dp-panel-table-view-info.active{background:var(--dp-info-600);border-color:var(--dp-info-600)}.dp-panel-page-table{display:grid;border:1px solid var(--dp-border);border-radius:calc(var(--dp-radius) + 2px);background:var(--dp-surface);padding:18px;box-shadow:0 14px 36px rgba(15,23,42,.055)}.dp-panel-page-table>header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin:0}.dp-panel-page-table>header h2{margin:0 0 3px;font-size:16px}.dp-panel-page-table>header p{margin:0;color:var(--dp-text_muted)}.dp-panel-page-table>header>span{display:inline-flex;min-height:28px;align-items:center;border:1px solid var(--dp-border_soft);border-radius:999px;background:var(--dp-surface_muted);color:var(--dp-text_muted);padding:4px 10px;font-size:12px;font-weight:800;white-space:nowrap}.dp-panel-page-table>.dp-panel-table{box-shadow:none}.dp-panel-search,.dp-panel-filters{padding:10px;border:1px solid var(--dp-border_soft);border-radius:var(--dp-radius);background:var(--dp-surface_muted)}.dp-panel-filter-chips{margin-top:-2px}.dp-panel-card>.dp-panel-grid{margin-top:14px}@media(max-width:760px){.dp-panel-heading-row{display:grid}.dp-panel-theme-toggle{justify-self:start}.dp-panel-page-table>header{display:grid}.dp-panel-table-views{width:100%;border-radius:var(--dp-radius)}}
CSS;
	}

	/**
	 * Supplies final action button, menu, inline-edit, and filter-chip polish.
	 *
	 * this late-stage asset normalizes action sizes, icon-only controls,
	 * menu descriptions, outline/ghost/link styles, inline edits, and filter chips
	 * after component-specific CSS has declared its base surfaces.
	 */
	private static function actionPolishCss(): string {
		return '.dp-panel-action-icon{display:inline-grid;place-items:center;min-width:20px;height:20px;border-radius:7px;background:rgba(255,255,255,.22);color:inherit;font-style:normal;font-size:10px;font-weight:900;letter-spacing:.02em}.dp-panel-action-neutral .dp-panel-action-icon,.dp-panel-button-secondary .dp-panel-action-icon,.dp-panel-row-link .dp-panel-action-icon{background:var(--dp-surface);color:var(--dp-primary-700);box-shadow:inset 0 0 0 1px var(--dp-border_soft)}.dp-panel-action span,.dp-panel-row-link span{line-height:1}.dp-panel-action-badge{display:inline-flex;align-items:center;justify-content:center;min-height:18px;border-radius:999px;background:rgba(255,255,255,.22);color:inherit;padding:2px 6px;font-size:10px;font-weight:950;line-height:1;white-space:nowrap}.dp-panel-action-neutral .dp-panel-action-badge{background:var(--dp-surface);color:var(--dp-text_muted);box-shadow:inset 0 0 0 1px var(--dp-border_soft)}.dp-panel-action-badge-primary{background:var(--dp-primary-50,#eff6ff);color:var(--dp-primary-700,#175cd3)}.dp-panel-action-badge-success{background:var(--dp-success-50,#ecfdf3);color:var(--dp-success-700,#067647)}.dp-panel-action-badge-warning{background:var(--dp-warning-50,#fffaeb);color:var(--dp-warning-700,#b54708)}.dp-panel-action-badge-danger{background:var(--dp-danger-50,#fff1f0);color:var(--dp-danger-700,#b42318)}.dp-panel-action-badge-info{background:var(--dp-info-50,#eff8ff);color:var(--dp-info-700,#026aa2)}.dp-panel-row-link{display:inline-flex;align-items:center;gap:6px;min-height:30px;border:1px solid var(--dp-border_soft);border-radius:9px;background:var(--dp-surface_muted);color:var(--dp-neutral_text)!important;padding:5px 9px;font-size:12px;font-weight:820;text-decoration:none}.dp-panel-row-link:hover{border-color:var(--dp-primary-600);color:var(--dp-primary-700)!important;text-decoration:none!important}.dp-panel-row-link .dp-panel-action-icon{min-width:17px;height:17px;border-radius:6px;font-size:9px}.dp-panel-actions .dp-panel-action{min-height:32px;padding:6px 10px;border:1px solid transparent}.dp-panel-actions .dp-panel-action-neutral{background:var(--dp-neutral_bg);color:var(--dp-neutral_text);border-color:var(--dp-border)}.dp-panel-actions .dp-panel-action-primary{background:var(--dp-primary-600);color:#fff}.dp-panel-actions .dp-panel-action-success{background:var(--dp-success-600);color:#fff}.dp-panel-actions .dp-panel-action-warning{background:var(--dp-warning-600);color:#fff}.dp-panel-actions .dp-panel-action-danger{background:var(--dp-danger-600);color:#fff}.dp-panel-actions .dp-panel-action-icon{min-width:18px;height:18px;border-radius:6px;font-size:9px}.dp-panel-action-disabled,.dp-panel-button:disabled,.dp-panel-action:disabled{cursor:not-allowed!important;filter:saturate(.62)!important;box-shadow:none!important;transform:none!important}.dp-panel-action-disabled{background:var(--dp-neutral_bg)!important;border-color:var(--dp-border)!important;color:var(--dp-text_muted)!important}.dp-panel-action-disabled .dp-panel-action-icon{background:var(--dp-surface)!important;color:var(--dp-text_muted)!important}.dp-panel-table thead th{position:sticky;top:0;z-index:1}.dp-panel-table tbody tr:has(input[type=checkbox]:checked){background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 68%,var(--dp-surface))}.dp-panel-select input{accent-color:var(--dp-primary-600);cursor:pointer}.dp-panel-bulk-bar{position:sticky;bottom:14px;z-index:8;justify-content:flex-start;width:max-content;max-width:100%;margin-left:auto;padding:8px;border:1px solid var(--dp-border);border-radius:999px;background:color-mix(in srgb,var(--dp-surface) 94%,transparent);box-shadow:0 18px 48px rgba(15,23,42,.16);backdrop-filter:blur(12px)}.dp-panel-bulk-bar:before{content:attr(data-dp-panel-selected-label);display:inline-flex;align-items:center;min-height:32px;border-right:1px solid var(--dp-border_soft);color:var(--dp-text_muted);padding:0 12px 0 5px;font-size:12px;font-weight:850;white-space:nowrap}.dp-panel-bulk-bar-empty{opacity:.58}.dp-panel-bulk-bar-empty .dp-panel-action{pointer-events:none}.dp-panel-bulk-bar:empty{display:none}.dp-panel-action-menu{gap:6px;padding:7px}.dp-panel-action-menu .dp-panel-action{display:flex!important;align-items:center;justify-content:flex-start;gap:8px}.dp-panel-toolbar-actions{min-width:0}.dp-panel-toolbar-actions>.dp-panel-button,.dp-panel-toolbar-actions>.dp-panel-action,.dp-panel-toolbar-actions>.dp-panel-inline-action>.dp-panel-action{white-space:nowrap}.dp-panel-global-search{position:relative}.dp-panel-global-search:focus-within,.dp-panel-search:focus-within{box-shadow:0 0 0 4px rgba(37,99,235,.10)}.dp-panel-empty-state{min-height:190px;text-align:center}.dp-panel-empty-state strong{font-size:18px}.dp-panel-empty-state .dp-panel-button{margin-top:4px}.dp-panel-form-submitting{cursor:progress}.dp-panel-form-submitting .dp-panel-button,.dp-panel-form-submitting .dp-panel-action{transition:none}.dp-panel-submitter-busy{opacity:.86;pointer-events:none}.dp-panel-loading-spinner{width:13px;height:13px;border:2px solid currentColor;border-right-color:transparent;border-radius:999px;animation:dp-panel-spin .65s linear infinite}.dp-panel [aria-disabled=true]{pointer-events:none;opacity:.72}@keyframes dp-panel-spin{to{transform:rotate(360deg)}}@media(max-width:760px){.dp-panel-bulk-bar{position:static;width:100%;border-radius:var(--dp-radius);margin-left:0}.dp-panel-bulk-bar .dp-panel-action{flex:1}.dp-panel-actions .dp-panel-action span,.dp-panel-actions .dp-panel-row-link span{display:none}.dp-panel-actions .dp-panel-action-badge{display:inline-flex}.dp-panel-actions .dp-panel-action-icon,.dp-panel-actions .dp-panel-row-link .dp-panel-action-icon{margin:0}}';
	}

	/**
	 * Supplies guidance and empty-state surface styles.
	 *
	 * guidance surfaces are rendered by panel helpers to explain resource
	 * state, onboarding, or missing data. This asset keeps those helper surfaces
	 * visually distinct from records and actions.
	 */
	private static function surfaceGuidanceCss(): string {
		return '.dp-panel-surface-guidance{display:grid;gap:4px;margin:0 0 16px;border:1px solid var(--dp-border);border-left:4px solid var(--dp-info-600);border-radius:calc(var(--dp-radius) + 2px);background:color-mix(in srgb,var(--dp-surface) 92%,var(--dp-info-50,#eff8ff));color:var(--dp-text);padding:13px 15px;box-shadow:0 10px 28px rgba(15,23,42,.055)}.dp-panel-surface-guidance>span{color:var(--dp-text_muted);font-size:11px;font-weight:900;letter-spacing:.045em;text-transform:uppercase}.dp-panel-surface-guidance strong{font-size:15px;font-weight:850}.dp-panel-surface-guidance p{margin:0;color:var(--dp-text_muted)}.dp-panel-surface-guidance div{display:flex;flex-wrap:wrap;gap:7px;margin-top:3px}.dp-panel-surface-guidance div span{display:inline-flex;align-items:center;min-height:24px;border:1px solid var(--dp-border_soft);border-radius:999px;background:var(--dp-surface);color:var(--dp-text_muted);padding:3px 9px;font-size:12px;font-weight:800}.dp-panel-surface-guidance-primary{border-left-color:var(--dp-primary-600);background:color-mix(in srgb,var(--dp-surface) 92%,var(--dp-primary-50,#eff6ff))}.dp-panel-surface-guidance-success{border-left-color:var(--dp-success-600);background:color-mix(in srgb,var(--dp-surface) 92%,var(--dp-success-50,#ecfdf3))}.dp-panel-surface-guidance-warning{border-left-color:var(--dp-warning-600);background:color-mix(in srgb,var(--dp-surface) 92%,var(--dp-warning-50,#fffaeb))}.dp-panel-surface-guidance-danger{border-left-color:var(--dp-danger-600);background:color-mix(in srgb,var(--dp-surface) 92%,var(--dp-danger-50,#fff1f0))}@media(max-width:760px){.dp-panel-surface-guidance{padding:12px}}';
	}

	/**
	 * Supplies chart container and legend styles.
	 *
	 * chart renderers provide semantic containers and data attributes,
	 * while this asset establishes panel-consistent sizing, captions, legends,
	 * placeholder states, and responsive behavior.
	 */
	private static function chartCss(): string {
		return '.dp-panel-widget-chart{grid-column:span 2;min-height:260px!important;align-content:start!important}.dp-panel-chart{display:grid;gap:8px;margin-top:8px;min-height:var(--dp-chart-height,190px)}.dp-panel-chart svg{display:block;width:100%;height:var(--dp-chart-height,190px);overflow:visible}.dp-panel-chart-grid{stroke:var(--dp-border_soft,#e7ecf2);stroke-width:1}.dp-panel-chart-axis text{fill:var(--dp-text_soft,#98a2b3);font-size:11px;font-weight:800}.dp-panel-chart-line{fill:none;stroke:currentColor;stroke-width:4;stroke-linecap:round;stroke-linejoin:round}.dp-panel-chart-area{fill:currentColor;opacity:.14}.dp-panel-chart-fill{fill:currentColor;rx:7;ry:7}.dp-panel-chart-primary{color:var(--dp-primary-600,#2563eb)}.dp-panel-chart-success{color:var(--dp-success-600,#16a34a)}.dp-panel-chart-warning{color:var(--dp-warning-600,#d97706)}.dp-panel-chart-danger{color:var(--dp-danger-600,#dc2626)}.dp-panel-chart-info{color:var(--dp-info-600,#0891b2)}.dp-panel-chart-neutral{color:var(--dp-neutral-500,#667085)}.dp-panel-chart-legend{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.dp-panel-chart-legend-item{display:inline-flex;align-items:center;gap:6px;color:var(--dp-text_muted);font-size:11px;font-weight:850}.dp-panel-chart-legend-item:before{content:"";width:9px;height:9px;border-radius:999px;background:currentColor}.dp-panel-chart-legend-primary{color:var(--dp-primary-600,#2563eb)}.dp-panel-chart-legend-success{color:var(--dp-success-600,#16a34a)}.dp-panel-chart-legend-warning{color:var(--dp-warning-600,#d97706)}.dp-panel-chart-legend-danger{color:var(--dp-danger-600,#dc2626)}.dp-panel-chart-legend-info{color:var(--dp-info-600,#0891b2)}.dp-panel-chart-donut{place-items:center}.dp-panel-chart-donut svg{max-width:240px}.dp-panel-chart-ring-bg{fill:none;stroke:var(--dp-border_soft,#e7ecf2);stroke-width:8}.dp-panel-chart-ring{fill:none;stroke:currentColor;stroke-width:8;transform:rotate(-90deg);transform-origin:50% 50%;transition:stroke-dasharray .2s ease}.dp-panel-chart-donut text{dominant-baseline:middle;fill:var(--dp-text);font-size:10px;font-weight:950}.dp-panel-chart-empty{place-items:center;border:1px dashed var(--dp-border_soft);border-radius:14px;color:var(--dp-text_muted);font-weight:850}body[data-dp-theme-mode="dark"] .dp-panel-chart-grid{stroke:#34445d}body[data-dp-theme-mode="dark"] .dp-panel-chart-axis text{fill:#9eacc0}body[data-dp-theme-mode="dark"] .dp-panel-chart-ring-bg{stroke:#34445d}@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"] .dp-panel-chart-grid{stroke:#34445d}body[data-dp-theme-mode="system"] .dp-panel-chart-axis text{fill:#9eacc0}body[data-dp-theme-mode="system"] .dp-panel-chart-ring-bg{stroke:#34445d}}@media(max-width:900px){.dp-panel-widget-chart{grid-column:span 1}}';
	}

	/**
	 * Supplies table shell, toolbar, and row-interaction styles.
	 *
	 * the table renderer emits search, pagination, density, selection,
	 * row actions, relation tables, and responsive wrappers. This asset owns the
	 * shared chrome that keeps those features aligned across resources.
	 */
	private static function tableShellCss(): string {
		return '.dp-panel-table-shell{position:relative;display:grid;gap:8px}.dp-panel-table-meta{display:flex;justify-content:space-between;gap:10px;align-items:center;color:var(--dp-text_muted);font-size:12px;font-weight:820}.dp-panel-table-scroll{position:relative;overflow:auto;border-radius:var(--dp-radius);outline:none}.dp-panel-table-scroll:focus{box-shadow:0 0 0 4px rgba(37,99,235,.10)}.dp-panel-table-shell:before,.dp-panel-table-shell:after{content:"";position:absolute;top:35px;bottom:0;z-index:4;width:28px;pointer-events:none;opacity:0;transition:opacity .15s ease}.dp-panel-table-shell:before{left:0;background:linear-gradient(90deg,rgba(15,23,42,.14),transparent)}.dp-panel-table-shell:after{right:0;background:linear-gradient(270deg,rgba(15,23,42,.14),transparent)}.dp-panel-table-can-scroll-left:before,.dp-panel-table-can-scroll-right:after{opacity:1}.dp-panel-table-scroll .dp-panel-table{min-width:100%;box-shadow:none}.dp-panel-row-selected td{background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 72%,var(--dp-surface))!important}.dp-panel-row-selected td:first-child{box-shadow:inset 3px 0 0 var(--dp-primary-600)}.dp-panel-column-picker summary{display:inline-flex;align-items:center;gap:7px}.dp-panel-column-picker summary small{display:inline-flex;align-items:center;min-height:19px;border-radius:999px;background:var(--dp-surface);color:var(--dp-text_muted);padding:2px 7px;font-size:11px;font-weight:850}.dp-panel-column-picker form{min-width:290px;max-width:min(360px,calc(100vw - 28px))}.dp-panel-column-search{width:100%;border:1px solid var(--dp-control_border);border-radius:calc(var(--dp-radius) - 2px);background:var(--dp-control_bg);color:var(--dp-text);padding:9px 10px;outline:none}.dp-panel-column-search:focus{border-color:var(--dp-primary-600);box-shadow:0 0 0 4px rgba(37,99,235,.10)}.dp-panel-column-actions,.dp-panel-column-footer{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.dp-panel-column-options{display:grid;gap:3px;max-height:260px;overflow:auto;padding:2px}.dp-panel-column-options label{display:flex;align-items:center;gap:8px;border-radius:8px;padding:7px 8px}.dp-panel-column-options label:hover{background:var(--dp-surface_muted)}.dp-panel-column-options input{accent-color:var(--dp-primary-600)}.dp-panel-column-options span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.dp-panel-column-picker-empty .dp-panel-column-options:after{content:"";display:block;min-height:10px;color:var(--dp-text_muted);padding:10px;text-align:center}@media(max-width:760px){.dp-panel-table-meta{display:grid;gap:2px}.dp-panel-table-shell:before,.dp-panel-table-shell:after{top:44px}.dp-panel-column-picker form{left:0;right:auto;width:calc(100vw - 28px)}}';
	}

	/**
	 * Supplies late table/action/header compatibility overrides.
	 *
	 * this asset is emitted as a nowdoc because it contains a large set of
	 * targeted overrides for table action bars, sidebar/navigation collisions,
	 * badges, dark mode, and mobile breakpoints that must win over earlier rules.
	 */
	private static function tableActionHeaderCss(): string {
		return <<<'CSS'
.dp-panel-table thead th.dp-panel-actions,
.dp-panel-table-scroll thead th.dp-panel-actions{
	display:table-cell!important;
	position:sticky!important;
	top:0!important;
	right:auto!important;
	left:auto!important;
	z-index:2!important;
	min-width:112px!important;
	width:1%!important;
	text-align:right!important;
	vertical-align:middle!important;
	white-space:nowrap!important;
	background:color-mix(in srgb,var(--dp-surface_muted,#f8fafc) 86%,var(--dp-surface,#fff))!important;
	box-shadow:none!important;
	border-left:1px solid var(--dp-border_soft,rgba(226,232,240,.9))!important;
}
.dp-panel-table thead th.dp-panel-actions:before,
.dp-panel-table thead th.dp-panel-actions:after,
.dp-panel-table-scroll thead th.dp-panel-actions:before,
.dp-panel-table-scroll thead th.dp-panel-actions:after{
	content:none!important;
	display:none!important;
}
.dp-panel-table thead .dp-panel-column-group-row th.dp-panel-actions-group,
.dp-panel-table-scroll thead .dp-panel-column-group-row th.dp-panel-actions-group{
	min-width:112px!important;
	width:1%!important;
	border-left:1px solid var(--dp-border_soft,rgba(226,232,240,.9))!important;
	background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 42%,var(--dp-surface_muted,#f8fafc))!important;
	box-shadow:none!important;
}
body[data-dp-theme-mode="dark"] .dp-panel-table thead th.dp-panel-actions,
body[data-dp-theme-mode="dark"] .dp-panel-table-scroll thead th.dp-panel-actions{
	background:#151f2e!important;
	border-left-color:#34445d!important;
}
body[data-dp-theme-mode="dark"] .dp-panel-table thead .dp-panel-column-group-row th.dp-panel-actions-group,
body[data-dp-theme-mode="dark"] .dp-panel-table-scroll thead .dp-panel-column-group-row th.dp-panel-actions-group{
	background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 12%,#151f2e)!important;
	border-left-color:#34445d!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table thead th.dp-panel-actions,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll thead th.dp-panel-actions,
body[data-dp-theme-effects~="glass"] .dp-panel-table thead .dp-panel-column-group-row th.dp-panel-actions-group,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll thead .dp-panel-column-group-row th.dp-panel-actions-group{
	background:color-mix(in srgb,var(--dp-surface) 72%,transparent)!important;
	box-shadow:none!important;
	backdrop-filter:none!important;
	-webkit-backdrop-filter:none!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-table thead th.dp-panel-actions,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table-scroll thead th.dp-panel-actions,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table thead .dp-panel-column-group-row th.dp-panel-actions-group,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table-scroll thead .dp-panel-column-group-row th.dp-panel-actions-group{
	border-left:2px solid var(--dp-brutalist-border,#111)!important;
	background:inherit!important;
	box-shadow:none!important;
}
@media(max-width:900px){
	.dp-panel-table thead th.dp-panel-actions,
	.dp-panel-table-scroll thead th.dp-panel-actions,
	.dp-panel-table thead .dp-panel-column-group-row th.dp-panel-actions-group,
	.dp-panel-table-scroll thead .dp-panel-column-group-row th.dp-panel-actions-group{
		display:none!important;
	}
}
CSS;
	}

	/**
	 * Supplies the sidebar rail breakpoint override block.
	 *
	 * the rail breakpoint reconciles full sidebar navigation with compact
	 * rail behavior at narrower widths. It is intentionally isolated so future
	 * layout audits can adjust the breakpoint without touching base sidebar CSS.
	 */
	private static function sidebarRailBreakpointCss(): string {
		return <<<'CSS'
@media(max-width:1320px) and (min-width:1181px){
	.dp-panel-nav-sidebar,
	.dp-panel-with-sidebar{
		grid-template-columns:minmax(244px,276px) minmax(0,1fr)!important;
		column-gap:16px!important;
	}
	.dp-panel-nav-sidebar .dp-panel-sidebar,
	.dp-panel-with-sidebar>.dp-panel-sidebar,
	.dp-panel-sidebar{
		width:auto!important;
		max-width:none!important;
		overflow:auto!important;
		padding:12px!important;
	}
	.dp-panel-nav-sidebar .dp-panel-sidebar-top,
	.dp-panel-with-sidebar .dp-panel-sidebar-top,
	.dp-panel-sidebar-top{
		display:grid!important;
		grid-template-columns:minmax(0,1fr)!important;
		justify-items:stretch!important;
		gap:8px!important;
	}
	.dp-panel-nav-sidebar .dp-panel-sidebar-brand,
	.dp-panel-with-sidebar .dp-panel-sidebar-brand,
	.dp-panel-sidebar-brand{
		display:grid!important;
		grid-template-columns:38px minmax(0,1fr)!important;
		justify-items:stretch!important;
		width:100%!important;
		min-width:0!important;
		padding:7px!important;
		overflow:hidden!important;
	}
	.dp-panel-nav-sidebar .dp-panel-sidebar-brand>span,
	.dp-panel-with-sidebar .dp-panel-sidebar-brand>span,
	.dp-panel-sidebar-brand>span{
		width:38px!important;
		height:38px!important;
	}
	.dp-panel-nav-sidebar .dp-panel-sidebar-brand strong,
	.dp-panel-nav-sidebar .dp-panel-sidebar-brand small,
	.dp-panel-nav-sidebar .dp-panel-sidebar-copy,
	.dp-panel-nav-sidebar .dp-panel-sidebar-group h2,
	.dp-panel-with-sidebar .dp-panel-sidebar-brand strong,
	.dp-panel-with-sidebar .dp-panel-sidebar-brand small,
	.dp-panel-with-sidebar .dp-panel-sidebar-copy,
	.dp-panel-with-sidebar .dp-panel-sidebar-group h2{
		position:static!important;
		width:auto!important;
		height:auto!important;
		overflow:hidden!important;
		clip:auto!important;
		white-space:nowrap!important;
	}
	.dp-panel-nav-sidebar .dp-panel-sidebar-search,
	.dp-panel-nav-sidebar .dp-panel-sidebar-context,
	.dp-panel-nav-sidebar .dp-panel-sidebar-badge,
	.dp-panel-nav-sidebar .dp-panel-sidebar-pin,
	.dp-panel-with-sidebar .dp-panel-sidebar-search,
	.dp-panel-with-sidebar .dp-panel-sidebar-context,
	.dp-panel-with-sidebar .dp-panel-sidebar-badge,
	.dp-panel-with-sidebar .dp-panel-sidebar-pin{
		display:none!important;
	}
	.dp-panel-nav-sidebar .dp-panel-sidebar-nav,
	.dp-panel-with-sidebar .dp-panel-sidebar-nav,
	.dp-panel-sidebar-nav{
		display:grid!important;
		grid-template-columns:1fr!important;
		justify-items:stretch!important;
		gap:6px!important;
		overflow:visible!important;
		padding:0!important;
	}
	.dp-panel-nav-sidebar .dp-panel-sidebar-group,
	.dp-panel-with-sidebar .dp-panel-sidebar-group,
	.dp-panel-sidebar-group{
		display:grid!important;
		grid-template-columns:1fr!important;
		justify-items:stretch!important;
		width:100%!important;
		min-width:0!important;
		gap:6px!important;
		margin:8px 0 0!important;
		padding:10px 0 0!important;
		border-top:1px solid var(--dp-border_soft)!important;
		background:transparent!important;
		box-shadow:none!important;
	}
	.dp-panel-nav-sidebar .dp-panel-sidebar-item,
	.dp-panel-with-sidebar .dp-panel-sidebar-item,
	.dp-panel-sidebar-item{
		display:grid!important;
		grid-template-columns:minmax(0,1fr)!important;
		justify-items:stretch!important;
		width:100%!important;
		min-width:0!important;
	}
	.dp-panel-nav-sidebar .dp-panel-sidebar-link,
	.dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary,
	.dp-panel-with-sidebar .dp-panel-sidebar-link,
	.dp-panel-with-sidebar .dp-panel-sidebar-submenu>summary,
	.dp-panel-sidebar-link,
	.dp-panel-sidebar-submenu>summary{
		display:grid!important;
		grid-template-columns:32px minmax(0,1fr) auto!important;
		justify-items:stretch!important;
		width:100%!important;
		min-width:0!important;
		max-width:none!important;
		min-height:42px!important;
		margin:0!important;
		padding:6px 7px!important;
		overflow:hidden!important;
		border-radius:12px!important;
	}
	.dp-panel-nav-sidebar .dp-panel-sidebar-icon,
	.dp-panel-with-sidebar .dp-panel-sidebar-icon,
	.dp-panel-sidebar-icon{
		width:32px!important;
		height:32px!important;
		border-radius:10px!important;
	}
	.dp-panel-nav-sidebar .dp-panel-sidebar-submenu,
	.dp-panel-with-sidebar .dp-panel-sidebar-submenu,
	.dp-panel-sidebar-submenu{
		position:relative!important;
		display:grid!important;
		justify-items:stretch!important;
		width:100%!important;
		min-width:0!important;
		gap:0!important;
	}
	.dp-panel-sidebar-copy small{
		display:none!important;
	}
}
.dp-panel-modal-body .dp-panel-form-grid .dp-panel-field-boolean{
	grid-column:1 / -1!important;
	grid-column-start:1!important;
	grid-column-end:-1!important;
}
.dp-panel-heading-tools{
	background:transparent!important;
	background-image:none!important;
	border:0!important;
	border-radius:0!important;
	backdrop-filter:none!important;
	box-shadow:none!important;
	outline:0!important;
	padding:0!important;
}
.dp-panel-relation>.dp-panel-toolbar{
	grid-template-columns:minmax(280px,1fr) max-content!important;
	align-items:center!important;
}
.dp-panel-relation>.dp-panel-toolbar>.dp-panel-search{
	display:grid!important;
	grid-template-columns:minmax(0,1fr) auto!important;
	align-items:center!important;
	gap:10px!important;
	width:100%!important;
	min-width:0!important;
	margin:0!important;
}
.dp-panel-relation>.dp-panel-toolbar>.dp-panel-search input[type="search"]{
	width:100%!important;
	min-width:0!important;
}
.dp-panel-relation>.dp-panel-toolbar>.dp-panel-toolbar-actions{
	display:flex!important;
	align-items:center!important;
	justify-content:flex-end!important;
	flex-wrap:nowrap!important;
	gap:9px!important;
	min-width:max-content!important;
}
.dp-panel-relation>.dp-panel-toolbar .dp-panel-per-page{
	display:flex!important;
	align-items:center!important;
	flex-wrap:nowrap!important;
	gap:6px!important;
	margin:0!important;
}
.dp-panel-relation>.dp-panel-toolbar .dp-panel-per-page label{
	display:flex!important;
	align-items:center!important;
	gap:8px!important;
	min-height:44px!important;
	margin:0!important;
}
.dp-panel-relation>.dp-panel-toolbar .dp-panel-per-page select{
	min-height:42px!important;
}
.dp-panel-relation>.dp-panel-toolbar .dp-panel-per-page .dp-panel-button{
	min-height:42px!important;
}
body[data-dp-theme-mode="dark"] .dp-panel-badge,
body[data-dp-theme-mode="dark"] .dp-panel-nav-badge{
	border:1px solid #475569!important;
	background:#1f2937!important;
	color:#f8fafc!important;
}
body[data-dp-theme-mode="dark"] .dp-panel-badge-primary,
body[data-dp-theme-mode="dark"] .dp-panel-nav-badge-primary{
	border-color:#60a5fa!important;
	background:#1e3a8a!important;
	color:#dbeafe!important;
}
body[data-dp-theme-mode="dark"] .dp-panel-badge-success,
body[data-dp-theme-mode="dark"] .dp-panel-nav-badge-success{
	border-color:#34d399!important;
	background:#064e3b!important;
	color:#d1fae5!important;
}
body[data-dp-theme-mode="dark"] .dp-panel-badge-warning,
body[data-dp-theme-mode="dark"] .dp-panel-nav-badge-warning{
	border-color:#fbbf24!important;
	background:#713f12!important;
	color:#fef3c7!important;
}
body[data-dp-theme-mode="dark"] .dp-panel-badge-danger,
body[data-dp-theme-mode="dark"] .dp-panel-nav-badge-danger{
	border-color:#f87171!important;
	background:#7f1d1d!important;
	color:#fee2e2!important;
}
body[data-dp-theme-mode="dark"] .dp-panel-badge-info,
body[data-dp-theme-mode="dark"] .dp-panel-nav-badge-info{
	border-color:#22d3ee!important;
	background:#164e63!important;
	color:#cffafe!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-badge,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-nav-badge{
	background:rgba(15,23,42,.82)!important;
	background-image:none!important;
	border-color:rgba(148,163,184,.72)!important;
	color:#f8fafc!important;
	box-shadow:inset 0 1px 0 rgba(255,255,255,.14)!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-badge-primary,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-nav-badge-primary{
	background:#1d4ed8!important;
	border-color:#93c5fd!important;
	color:#eff6ff!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-badge-success,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-nav-badge-success{
	background:#047857!important;
	border-color:#6ee7b7!important;
	color:#ecfdf5!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-badge-warning,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-nav-badge-warning{
	background:#b45309!important;
	border-color:#fde68a!important;
	color:#fffbeb!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-badge-danger,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-nav-badge-danger{
	background:#b91c1c!important;
	border-color:#fecaca!important;
	color:#fff1f2!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-badge-info,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-nav-badge-info{
	background:#0e7490!important;
	border-color:#a5f3fc!important;
	color:#ecfeff!important;
}
@media(prefers-color-scheme:dark){
	body[data-dp-theme-mode="system"] .dp-panel-badge,
	body[data-dp-theme-mode="system"] .dp-panel-nav-badge{border:1px solid #475569!important;background:#1f2937!important;color:#f8fafc!important}
	body[data-dp-theme-mode="system"] .dp-panel-badge-primary,
	body[data-dp-theme-mode="system"] .dp-panel-nav-badge-primary{border-color:#60a5fa!important;background:#1e3a8a!important;color:#dbeafe!important}
	body[data-dp-theme-mode="system"] .dp-panel-badge-success,
	body[data-dp-theme-mode="system"] .dp-panel-nav-badge-success{border-color:#34d399!important;background:#064e3b!important;color:#d1fae5!important}
	body[data-dp-theme-mode="system"] .dp-panel-badge-warning,
	body[data-dp-theme-mode="system"] .dp-panel-nav-badge-warning{border-color:#fbbf24!important;background:#713f12!important;color:#fef3c7!important}
	body[data-dp-theme-mode="system"] .dp-panel-badge-danger,
	body[data-dp-theme-mode="system"] .dp-panel-nav-badge-danger{border-color:#f87171!important;background:#7f1d1d!important;color:#fee2e2!important}
	body[data-dp-theme-mode="system"] .dp-panel-badge-info,
	body[data-dp-theme-mode="system"] .dp-panel-nav-badge-info{border-color:#22d3ee!important;background:#164e63!important;color:#cffafe!important}
	body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-badge,
	body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-nav-badge{background:rgba(15,23,42,.82)!important;background-image:none!important;border-color:rgba(148,163,184,.72)!important;color:#f8fafc!important;box-shadow:inset 0 1px 0 rgba(255,255,255,.14)!important}
	body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-badge-primary,
	body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-nav-badge-primary{background:#1d4ed8!important;border-color:#93c5fd!important;color:#eff6ff!important}
	body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-badge-success,
	body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-nav-badge-success{background:#047857!important;border-color:#6ee7b7!important;color:#ecfdf5!important}
	body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-badge-warning,
	body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-nav-badge-warning{background:#b45309!important;border-color:#fde68a!important;color:#fffbeb!important}
	body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-badge-danger,
	body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-nav-badge-danger{background:#b91c1c!important;border-color:#fecaca!important;color:#fff1f2!important}
	body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-badge-info,
	body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-nav-badge-info{background:#0e7490!important;border-color:#a5f3fc!important;color:#ecfeff!important}
}
.dp-panel-inline-edit{display:grid;grid-template-columns:minmax(64px,1fr) auto auto;align-items:center;gap:6px;min-width:min(100%,180px);max-width:100%;margin:0}
.dp-panel-inline-edit-control{width:100%;min-width:0;min-height:34px;border:1px solid var(--dp-border,#d0d7e2);border-radius:10px;background:var(--dp-surface,#fff);color:var(--dp-text,#101828);padding:6px 9px;font:inherit;font-size:13px;font-weight:760;box-shadow:inset 0 1px 2px rgba(15,23,42,.035)}
.dp-panel-inline-edit-control:focus{outline:0;border-color:var(--dp-primary-600,#2563eb);box-shadow:0 0 0 3px color-mix(in srgb,var(--dp-primary-600,#2563eb) 14%,transparent)}
.dp-panel-inline-edit-save{display:inline-flex;align-items:center;justify-content:center;min-width:32px;min-height:32px;border:1px solid var(--dp-border_soft,#e2e8f0);border-radius:9px;background:var(--dp-surface_muted,#f1f5f9);color:var(--dp-text,#101828);font-size:0;font-weight:900;cursor:pointer}
.dp-panel-inline-edit-save:before{content:"OK";font-size:10px;letter-spacing:.02em}
.dp-panel-inline-edit-save:hover{border-color:var(--dp-primary-600,#2563eb);color:var(--dp-primary-700,#1d4ed8)}
.dp-panel-inline-edit-status{min-width:34px;color:var(--dp-text_muted,#667085);font-size:10px;font-weight:900;text-transform:uppercase}
.dp-panel-inline-edit[data-dp-panel-inline-tone="working"] .dp-panel-inline-edit-status{color:var(--dp-primary-700,#1d4ed8)}
.dp-panel-inline-edit[data-dp-panel-inline-tone="success"] .dp-panel-inline-edit-status{color:var(--dp-success-700,#067647)}
.dp-panel-inline-edit[data-dp-panel-inline-tone="error"] .dp-panel-inline-edit-status{color:var(--dp-danger-700,#b42318)}
.dp-panel-inline-edit-toggle{display:inline-flex;align-items:center;gap:7px;width:max-content;max-width:100%;min-height:34px;border:1px solid var(--dp-border,#d0d7e2);border-radius:999px;background:var(--dp-surface,#fff);padding:5px 9px;color:var(--dp-text,#101828);font-size:12px;font-weight:860;cursor:pointer}
.dp-panel-inline-edit-toggle input{accent-color:var(--dp-primary-600,#2563eb)}
body[data-dp-theme-mode="dark"] .dp-panel-inline-edit-control,body[data-dp-theme-mode="dark"] .dp-panel-inline-edit-toggle{background:#101827;border-color:#34445d;color:#f8fafc}
body[data-dp-theme-mode="dark"] .dp-panel-inline-edit-save{background:#1f2937;border-color:#34445d;color:#f8fafc}
@media(max-width:900px){.dp-panel-inline-edit{grid-template-columns:1fr auto}.dp-panel-inline-edit-status{grid-column:1/-1}.dp-panel-table td .dp-panel-inline-edit{width:100%}}
.dp-panel-sr-only{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.dp-panel-action-size-xs{min-height:28px!important;padding:5px 8px!important;font-size:11px!important}
.dp-panel-action-size-sm{min-height:34px!important;padding:7px 10px!important;font-size:12px!important}
.dp-panel-action-size-lg{min-height:48px!important;padding:12px 18px!important;font-size:14px!important}
.dp-panel-action-size-xl{min-height:54px!important;padding:14px 22px!important;font-size:15px!important}
.dp-panel-action-icon-only{width:38px!important;min-width:38px!important;max-width:38px!important;padding:0!important;aspect-ratio:1!important}
.dp-panel-action-icon-only.dp-panel-action-size-xs{width:30px!important;min-width:30px!important;max-width:30px!important}
.dp-panel-action-icon-only.dp-panel-action-size-sm{width:34px!important;min-width:34px!important;max-width:34px!important}
.dp-panel-action-icon-only.dp-panel-action-size-lg{width:46px!important;min-width:46px!important;max-width:46px!important}
.dp-panel-action-icon-only.dp-panel-action-size-xl{width:54px!important;min-width:54px!important;max-width:54px!important}
.dp-panel-action-icon-only .dp-panel-action-icon{margin:0!important}
.dp-panel-action-copy{display:inline-grid;align-items:center;min-width:0;gap:1px;text-align:inherit}
.dp-panel-action-description{display:none;color:var(--dp-text_muted,#667085);font-size:11px;font-weight:700;line-height:1.25;text-transform:none;letter-spacing:0;white-space:normal}
.dp-panel-action-menu .dp-panel-action-copy,.dp-panel-row-more-menu .dp-panel-action-copy{justify-items:start;text-align:left}
.dp-panel-action-menu .dp-panel-action-description,.dp-panel-row-more-menu .dp-panel-action-description{display:block}
.dp-panel-action-menu .dp-panel-action,.dp-panel-row-more-menu .dp-panel-action{min-height:44px}
.dp-panel-action-menu .dp-panel-action-icon,.dp-panel-row-more-menu .dp-panel-action-icon{align-self:start;margin-top:2px}
.dp-panel-action-menu-section{display:grid;gap:2px;padding:7px 9px 3px;color:var(--dp-text_muted,#667085);text-align:left}
.dp-panel-action-menu-section strong{color:var(--dp-text,#101828);font-size:11px;font-weight:950;letter-spacing:.08em;line-height:1.15;text-transform:uppercase}
.dp-panel-action-menu-section small{color:var(--dp-text_muted,#667085);font-size:11px;font-weight:700;line-height:1.25}
.dp-panel-action-menu-divider{width:100%;height:1px;border:0;background:var(--dp-border_soft,#e4e7ec);margin:5px 0}
.dp-panel-action-style-outline{background:transparent!important;color:var(--dp-text,#101828)!important;border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 38%,var(--dp-border,#d0d7e2))!important;box-shadow:none!important}
.dp-panel-action-style-outline.dp-panel-action-primary{color:var(--dp-primary-700,#1d4ed8)!important}
.dp-panel-action-style-outline.dp-panel-action-success{color:var(--dp-success-700,#067647)!important;border-color:color-mix(in srgb,var(--dp-success-600,#16a34a) 48%,var(--dp-border,#d0d7e2))!important}
.dp-panel-action-style-outline.dp-panel-action-warning{color:var(--dp-warning-700,#b54708)!important;border-color:color-mix(in srgb,var(--dp-warning-600,#d97706) 52%,var(--dp-border,#d0d7e2))!important}
.dp-panel-action-style-outline.dp-panel-action-danger{color:var(--dp-danger-700,#b42318)!important;border-color:color-mix(in srgb,var(--dp-danger-600,#dc2626) 48%,var(--dp-border,#d0d7e2))!important}
.dp-panel-action-style-outline.dp-panel-action-info{color:var(--dp-info-700,#026aa2)!important;border-color:color-mix(in srgb,var(--dp-info-600,#0891b2) 48%,var(--dp-border,#d0d7e2))!important}
.dp-panel-action-style-ghost{background:transparent!important;border-color:transparent!important;box-shadow:none!important;color:var(--dp-text_muted,#667085)!important}
.dp-panel-action-style-ghost:hover{background:color-mix(in srgb,var(--dp-neutral_bg,#eef2f7) 78%,transparent)!important;border-color:transparent!important;color:var(--dp-text,#101828)!important}
.dp-panel-action-style-link{background:transparent!important;border-color:transparent!important;box-shadow:none!important;color:var(--dp-primary-700,#1d4ed8)!important;text-decoration:underline;text-underline-offset:3px}
.dp-panel-action-style-link:hover{background:transparent!important;color:var(--dp-primary-800,#1e40af)!important}
.dp-panel-action-group-chevron{display:inline-flex;align-items:center;justify-content:center;margin-left:2px;font-size:11px;opacity:.72}
.dp-panel-action-group-width-xs .dp-panel-action-menu{min-width:10rem!important}
.dp-panel-action-group-width-sm .dp-panel-action-menu{min-width:13rem!important}
.dp-panel-action-group-width-md .dp-panel-action-menu{min-width:16rem!important}
.dp-panel-action-group-width-lg .dp-panel-action-menu{min-width:20rem!important}
.dp-panel-action-group-width-xl .dp-panel-action-menu{min-width:26rem!important}
.dp-panel-action-group-width-auto .dp-panel-action-menu{min-width:max-content!important}
.dp-panel-action-group-align-start .dp-panel-action-menu{left:0!important;right:auto!important}
.dp-panel-action-group-align-center .dp-panel-action-menu{left:50%!important;right:auto!important;transform:translateX(-50%)}
.dp-panel-action-group-align-end .dp-panel-action-menu{left:auto!important;right:0!important}
.dp-panel-action-group-floating>.dp-panel-action-menu{position:fixed!important;left:var(--dp-action-menu-left)!important;top:var(--dp-action-menu-top)!important;right:auto!important;transform:none!important;width:var(--dp-action-menu-width)!important;max-height:var(--dp-action-menu-max-height)!important;overflow:auto!important;margin:0!important;z-index:16000!important}
.dp-panel-action-group .dp-panel-action-menu .dp-panel-action{width:100%;justify-content:flex-start}
body[data-dp-theme-mode="dark"] .dp-panel-action-style-outline{color:#f8fafc!important;border-color:#526178!important}
body[data-dp-theme-mode="dark"] .dp-panel-action-style-outline.dp-panel-action-primary{color:#bfdbfe!important}
body[data-dp-theme-mode="dark"] .dp-panel-action-style-outline.dp-panel-action-success{color:#bbf7d0!important;border-color:#34d399!important}
body[data-dp-theme-mode="dark"] .dp-panel-action-style-outline.dp-panel-action-warning{color:#fde68a!important;border-color:#fbbf24!important}
body[data-dp-theme-mode="dark"] .dp-panel-action-style-outline.dp-panel-action-danger{color:#fecaca!important;border-color:#f87171!important}
body[data-dp-theme-mode="dark"] .dp-panel-action-style-outline.dp-panel-action-info{color:#a5f3fc!important;border-color:#22d3ee!important}
body[data-dp-theme-mode="dark"] .dp-panel-action-style-ghost{color:#cbd5e1!important}
body[data-dp-theme-mode="dark"] .dp-panel-action-style-ghost:hover{background:#203047!important;color:#fff!important}
body[data-dp-theme-mode="dark"] .dp-panel-action-style-link{color:#93c5fd!important}
body[data-dp-theme-mode="dark"] .dp-panel-action-description{color:#cbd5e1}
body[data-dp-theme-mode="dark"] .dp-panel-action-menu-section strong{color:#f8fafc}
body[data-dp-theme-mode="dark"] .dp-panel-action-menu-section,body[data-dp-theme-mode="dark"] .dp-panel-action-menu-section small{color:#cbd5e1}
body[data-dp-theme-mode="dark"] .dp-panel-action-menu-divider{background:#334155}
.dp-panel-filter-chips{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.dp-panel-filter-chip{display:inline-flex!important;align-items:center!important;gap:7px!important;min-height:34px!important}
.dp-panel-filter-chip strong{min-width:0;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dp-panel-filter-chip-primary{border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 34%,var(--dp-border,#d0d7e2))!important}
.dp-panel-filter-chip-success{border-color:color-mix(in srgb,var(--dp-success-600,#16a34a) 36%,var(--dp-border,#d0d7e2))!important}
.dp-panel-filter-chip-warning{border-color:color-mix(in srgb,var(--dp-warning-600,#d97706) 42%,var(--dp-border,#d0d7e2))!important}
.dp-panel-filter-chip-danger{border-color:color-mix(in srgb,var(--dp-danger-600,#dc2626) 38%,var(--dp-border,#d0d7e2))!important}
.dp-panel-filter-chip-info{border-color:color-mix(in srgb,var(--dp-info-600,#0891b2) 38%,var(--dp-border,#d0d7e2))!important}
.dp-panel-filter-chip-reset{margin-left:auto;background:color-mix(in srgb,var(--dp-danger-50,#fff1f0) 42%,var(--dp-surface,#fff))!important;border-color:color-mix(in srgb,var(--dp-danger-600,#dc2626) 28%,var(--dp-border,#d0d7e2))!important}
.dp-panel-empty-icon{display:inline-grid;place-items:center;width:44px;height:44px;border:1px solid color-mix(in srgb,var(--dp-primary-600,#2563eb) 22%,var(--dp-border,#d0d7e2));border-radius:14px;background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 68%,var(--dp-surface,#fff));color:var(--dp-primary-700,#1d4ed8);font-style:normal;font-size:13px;font-weight:950;letter-spacing:.02em}
CSS;
	}

}
