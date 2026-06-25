<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits optional visual-effect styles for Panel theme presets.
 *
 * The renderer composes these CSS blocks into the panel asset bundle when a
 * preset enables matching data-dp-theme-effects tokens. The strings are static
 * assets: they read CSS custom properties and DOM data attributes, perform no
 * request I/O, and rely on the surrounding renderer to decide when to inject
 * them.
 */
trait PanelRendererAssetsThemeCss {
	/**
	 * Returns the brutalist theme-effect stylesheet.
	 *
	 * The block hardens panel surfaces into square, high-contrast controls with
	 * explicit focus treatment, mobile adjustments, print-safe fallbacks, and dark
	 * or system color-mode overrides keyed by body data attributes.
	 *
	 * @return string CSS emitted for panels using the brutalist theme effect.
	 */
	private static function brutalistThemeCss(): string {
		return <<<'CSS'
body[data-dp-theme-effects~="brutalist"]{
	color-scheme:light;
	background:var(--dp-body_bg,#f4f1e8)!important;
	--dp-brutalist-border:var(--dp-border,#111);
	--dp-brutalist-shadow:var(--dp-brutalist_shadow,5px 5px 0 #111);
	--dp-brutalist-shadow-soft:var(--dp-brutalist_shadow_soft,3px 3px 0 #111);
	--dp-brutalist-focus:var(--dp-brutalist_focus,0 0 0 3px #fffdf4,0 0 0 6px #111);
	--dp-radius:0px;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="brutalist"]{color-scheme:dark}
@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"][data-dp-theme-effects~="brutalist"]{color-scheme:dark}}
body[data-dp-theme-effects~="brutalist"] *,
body[data-dp-theme-effects~="brutalist"] *:before,
body[data-dp-theme-effects~="brutalist"] *:after{
	border-radius:0!important;
	text-shadow:none!important;
	backdrop-filter:none!important;
	-webkit-backdrop-filter:none!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel{
	max-width:none!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-main-region>header,
body[data-dp-theme-effects~="brutalist"] .dp-panel-sidebar,
body[data-dp-theme-effects~="brutalist"] .dp-panel-horizontal-nav,
body[data-dp-theme-effects~="brutalist"] .dp-panel-card,
body[data-dp-theme-effects~="brutalist"] .dp-panel-widget,
body[data-dp-theme-effects~="brutalist"] .dp-panel-summary,
body[data-dp-theme-effects~="brutalist"] .dp-panel-insight,
body[data-dp-theme-effects~="brutalist"] .dp-panel-page-table,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table-shell,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table,
body[data-dp-theme-effects~="brutalist"] .dp-panel-commandbar,
body[data-dp-theme-effects~="brutalist"] .dp-panel-board-column,
body[data-dp-theme-effects~="brutalist"] .dp-panel-board-card,
body[data-dp-theme-effects~="brutalist"] .dp-panel-form-section,
body[data-dp-theme-effects~="brutalist"] .dp-panel-record-heading,
body[data-dp-theme-effects~="brutalist"] .dp-panel-show-field,
body[data-dp-theme-effects~="brutalist"] .dp-panel-modal,
body[data-dp-theme-effects~="brutalist"] .dp-panel-command,
body[data-dp-theme-effects~="brutalist"] .dp-panel-notice,
body[data-dp-theme-effects~="brutalist"] .dp-panel-alert,
body[data-dp-theme-effects~="brutalist"] .dp-panel-filter-panel,
body[data-dp-theme-effects~="brutalist"] .dp-panel-filter-chips{
	border:2px solid var(--dp-brutalist-border)!important;
	background:var(--dp-surface,#fffdf4)!important;
	box-shadow:var(--dp-brutalist-shadow)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-main-region>header:before,
body[data-dp-theme-effects~="brutalist"] .dp-panel-modal:before{
	height:8px!important;
	background:repeating-linear-gradient(90deg,var(--dp-primary-600,#2563eb) 0 24px,var(--dp-warning-500,#facc15) 24px 48px,var(--dp-danger-600,#dc2626) 48px 72px)!important;
	opacity:1!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-card:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-widget:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-page-table:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table-shell:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-commandbar:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-show-field:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-widget:before{
	display:none!important;
	content:none!important;
}
body[data-dp-theme-effects~="brutalist"] h1,
body[data-dp-theme-effects~="brutalist"] h2,
body[data-dp-theme-effects~="brutalist"] h3,
body[data-dp-theme-effects~="brutalist"] .dp-panel-widget strong,
body[data-dp-theme-effects~="brutalist"] .dp-panel-summary strong,
body[data-dp-theme-effects~="brutalist"] .dp-panel-cell-primary,
body[data-dp-theme-effects~="brutalist"] .dp-panel-modal-title h2{
	font-weight:950!important;
	letter-spacing:-.01em!important;
	text-transform:none!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-heading-row p,
body[data-dp-theme-effects~="brutalist"] .dp-panel-widget-label,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table th,
body[data-dp-theme-effects~="brutalist"] .dp-panel-field span,
body[data-dp-theme-effects~="brutalist"] .dp-panel-show-field span,
body[data-dp-theme-effects~="brutalist"] .dp-panel-filter span{
	color:var(--dp-text,#111)!important;
	font-weight:950!important;
	letter-spacing:.06em!important;
	text-transform:uppercase!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-button,
body[data-dp-theme-effects~="brutalist"] .dp-panel-action,
body[data-dp-theme-effects~="brutalist"] .dp-panel-row-link,
body[data-dp-theme-effects~="brutalist"] .dp-panel-row-more>summary,
body[data-dp-theme-effects~="brutalist"] .dp-panel-action-group>summary,
body[data-dp-theme-effects~="brutalist"] .dp-panel-column-picker summary,
body[data-dp-theme-effects~="brutalist"] .dp-panel-saved-view-menu>summary,
body[data-dp-theme-effects~="brutalist"] .dp-panel-theme-toggle,
body[data-dp-theme-effects~="brutalist"] .dp-panel-theme-select select,
body[data-dp-theme-effects~="brutalist"] .dp-panel-theme-preset,
body[data-dp-theme-effects~="brutalist"] .dp-panel-live-control,
body[data-dp-theme-effects~="brutalist"] .dp-panel-density,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table-view,
body[data-dp-theme-effects~="brutalist"] .dp-panel-filter-chip,
body[data-dp-theme-effects~="brutalist"] .dp-panel-badge,
body[data-dp-theme-effects~="brutalist"] .dp-panel-page-disabled{
	border:2px solid var(--dp-brutalist-border)!important;
	background:var(--dp-control_bg,var(--dp-surface))!important;
	color:var(--dp-text,#111)!important;
	box-shadow:var(--dp-brutalist-shadow-soft)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-button,
body[data-dp-theme-effects~="brutalist"] .dp-panel-action-primary,
body[data-dp-theme-effects~="brutalist"] .dp-panel-theme-toggle button[aria-pressed=true],
body[data-dp-theme-effects~="brutalist"] .dp-panel-density a.active,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table-view.active{
	background:var(--dp-primary-600,#2563eb)!important;
	color:#fff!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-action-success{background:var(--dp-success-600,#16a34a)!important;color:#fff!important}
body[data-dp-theme-effects~="brutalist"] .dp-panel-action-warning{background:var(--dp-warning-500,#facc15)!important;color:#111!important}
body[data-dp-theme-effects~="brutalist"] .dp-panel-action-danger{background:var(--dp-danger-600,#dc2626)!important;color:#fff!important}
body[data-dp-theme-effects~="brutalist"] .dp-panel-button:hover,
body[data-dp-theme-effects~="brutalist"] .dp-panel-action:hover,
body[data-dp-theme-effects~="brutalist"] .dp-panel-row-link:hover,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table-view:hover,
body[data-dp-theme-effects~="brutalist"] .dp-panel-widget:hover,
body[data-dp-theme-effects~="brutalist"] .dp-panel-card:hover{
	transform:translate(-2px,-2px)!important;
	box-shadow:7px 7px 0 var(--dp-brutalist-border)!important;
}
body[data-dp-theme-effects~="brutalist"] input,
body[data-dp-theme-effects~="brutalist"] select,
body[data-dp-theme-effects~="brutalist"] textarea{
	border:2px solid var(--dp-brutalist-border)!important;
	background:var(--dp-control_bg,var(--dp-surface))!important;
	color:var(--dp-text,#111)!important;
	box-shadow:inset 3px 3px 0 color-mix(in srgb,var(--dp-brutalist-border) 10%,transparent)!important;
}
body[data-dp-theme-effects~="brutalist"] a:focus-visible,
body[data-dp-theme-effects~="brutalist"] button:focus-visible,
body[data-dp-theme-effects~="brutalist"] summary:focus-visible,
body[data-dp-theme-effects~="brutalist"] input:focus-visible,
body[data-dp-theme-effects~="brutalist"] select:focus-visible,
body[data-dp-theme-effects~="brutalist"] textarea:focus-visible,
body[data-dp-theme-effects~="brutalist"] [tabindex]:focus-visible{
	outline:0!important;
	box-shadow:var(--dp-brutalist-focus)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-table th{
	background:var(--dp-neutral_bg,#e6e0cf)!important;
	border-bottom:2px solid var(--dp-brutalist-border)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-table td{
	border-bottom:2px solid var(--dp-brutalist-border)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-table tbody tr:nth-child(even) td{
	background:color-mix(in srgb,var(--dp-surface_muted,#ede8d8) 72%,var(--dp-surface))!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-table tbody tr:hover td{
	background:var(--dp-warning-100,#fef3c7)!important;
	color:#111!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-sidebar-link,
body[data-dp-theme-effects~="brutalist"] .dp-panel-sidebar-brand,
body[data-dp-theme-effects~="brutalist"] .dp-panel-horizontal-link,
body[data-dp-theme-effects~="brutalist"] .dp-panel-horizontal-group>summary{
	border:2px solid transparent!important;
	box-shadow:none!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-sidebar-link:hover,
body[data-dp-theme-effects~="brutalist"] .dp-panel-horizontal-link:hover,
body[data-dp-theme-effects~="brutalist"] .dp-panel-horizontal-group>summary:hover{
	border-color:var(--dp-brutalist-border)!important;
	background:var(--dp-warning-500,#facc15)!important;
	color:#111!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-sidebar-link.active,
body[data-dp-theme-effects~="brutalist"] .dp-panel-horizontal-link.active{
	border-color:var(--dp-brutalist-border)!important;
	background:var(--dp-primary-600,#2563eb)!important;
	color:#fff!important;
	box-shadow:var(--dp-brutalist-shadow-soft)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-sidebar-link.active:before{
	display:none!important;
	content:none!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-modal-root,
body[data-dp-theme-effects~="brutalist"] .dp-panel-command-root{
	background:rgba(0,0,0,.72)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-action-menu,
body[data-dp-theme-effects~="brutalist"] .dp-panel-row-more-menu,
body[data-dp-theme-effects~="brutalist"] .dp-panel-column-picker form,
body[data-dp-theme-effects~="brutalist"] .dp-panel-saved-view-menu>div{
	border:2px solid var(--dp-brutalist-border)!important;
	background:var(--dp-surface,#fffdf4)!important;
	box-shadow:var(--dp-brutalist-shadow)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-chart-card,
body[data-dp-theme-effects~="brutalist"] .dp-panel-chart,
body[data-dp-theme-effects~="brutalist"] .dp-panel-tab-list,
body[data-dp-theme-effects~="brutalist"] .dp-panel-step-list,
body[data-dp-theme-effects~="brutalist"] .dp-panel-relation,
body[data-dp-theme-effects~="brutalist"] .dp-panel-item,
body[data-dp-theme-effects~="brutalist"] .dp-panel-total,
body[data-dp-theme-effects~="brutalist"] .dp-panel-task,
body[data-dp-theme-effects~="brutalist"] .dp-panel-message,
body[data-dp-theme-effects~="brutalist"] .dp-panel-note{
	border:2px solid var(--dp-brutalist-border)!important;
	background:var(--dp-surface,#fffdf4)!important;
	box-shadow:var(--dp-brutalist-shadow-soft)!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="brutalist"] .dp-panel-table tbody tr:hover td,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="brutalist"] .dp-panel-sidebar-link:hover,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="brutalist"] .dp-panel-horizontal-link:hover{
	background:#facc15!important;
	color:#111!important;
}
@media(prefers-color-scheme:dark){
body[data-dp-theme-mode="system"][data-dp-theme-effects~="brutalist"] .dp-panel-table tbody tr:hover td,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="brutalist"] .dp-panel-sidebar-link:hover,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="brutalist"] .dp-panel-horizontal-link:hover{
	background:#facc15!important;
	color:#111!important;
}
}
@media(max-width:760px){
body[data-dp-theme-effects~="brutalist"] .dp-panel{
	padding:10px!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-main-region>header,
body[data-dp-theme-effects~="brutalist"] .dp-panel-commandbar,
body[data-dp-theme-effects~="brutalist"] .dp-panel-page-table{
	box-shadow:3px 3px 0 var(--dp-brutalist-border)!important;
}
}
body[data-dp-theme-effects~="brutalist"]:before{
	content:"";
	position:fixed;
	inset:0;
	z-index:-1;
	pointer-events:none;
	background-image:linear-gradient(var(--dp-brutalist-border) 1px,transparent 1px),linear-gradient(90deg,var(--dp-brutalist-border) 1px,transparent 1px);
	background-size:40px 40px;
	opacity:.055;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-main-region>header{
	padding-top:24px!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-main-region>header h1{
	display:inline;
	box-decoration-break:clone;
	-webkit-box-decoration-break:clone;
	background:transparent!important;
	padding-inline:4px!important;
	margin-inline:-4px!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-heading-tools{
	gap:12px!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-live-control,
body[data-dp-theme-effects~="brutalist"] .dp-panel-theme-toggle,
body[data-dp-theme-effects~="brutalist"] .dp-panel-theme-select{
	background:var(--dp-surface,#fffdf4)!important;
	padding:4px!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-theme-select{
	background:transparent!important;
	border:0!important;
	box-shadow:none!important;
	padding:0!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-theme-select label{
	border:2px solid var(--dp-brutalist-border)!important;
	border-radius:0!important;
	background:var(--dp-surface,#fffdf4)!important;
	box-shadow:var(--dp-brutalist-shadow-soft)!important;
	padding:4px 4px 4px 10px!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-theme-select select{
	border:0!important;
	border-left:2px solid var(--dp-brutalist-border)!important;
	border-radius:0!important;
	background:transparent!important;
	box-shadow:none!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-theme-toggle button,
body[data-dp-theme-effects~="brutalist"] .dp-panel-theme-preset button,
body[data-dp-theme-effects~="brutalist"] .dp-panel-live-control button,
body[data-dp-theme-effects~="brutalist"] .dp-panel-density a{
	border:2px solid transparent!important;
	color:var(--dp-text,#111)!important;
	font-weight:950!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-theme-toggle button[aria-pressed=true],
body[data-dp-theme-effects~="brutalist"] .dp-panel-theme-preset button[aria-pressed=true],
body[data-dp-theme-effects~="brutalist"] .dp-panel-live-control button[aria-pressed=true],
body[data-dp-theme-effects~="brutalist"] .dp-panel-density a.active{
	border-color:var(--dp-brutalist-border)!important;
	transform:translate(-1px,-1px)!important;
	box-shadow:2px 2px 0 var(--dp-brutalist-border)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-commandbar{
	display:grid!important;
	gap:14px!important;
	padding:16px!important;
	background:linear-gradient(180deg,var(--dp-surface,#fffdf4),var(--dp-surface_muted,#ede8d8))!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-commandbar:before,
body[data-dp-theme-effects~="brutalist"] .dp-panel-page-table>header:before,
body[data-dp-theme-effects~="brutalist"] .dp-panel-record-heading:before{
	content:"";
	display:block;
	grid-column:1/-1;
	height:6px;
	margin:-2px -2px 6px;
	background:repeating-linear-gradient(90deg,var(--dp-brutalist-border) 0 18px,transparent 18px 30px);
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-global-search input,
body[data-dp-theme-effects~="brutalist"] .dp-panel-search input{
	font-size:16px!important;
	font-weight:800!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-global-search:before,
body[data-dp-theme-effects~="brutalist"] .dp-panel-global-search:after{
	display:none!important;
	content:none!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-table-shell{
	background:var(--dp-surface,#fffdf4)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-table-scroll{
	border:2px solid var(--dp-brutalist-border)!important;
	background:var(--dp-surface,#fffdf4)!important;
	box-shadow:var(--dp-brutalist-shadow-soft)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-table{
	border:0!important;
	box-shadow:none!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-table th{
	position:sticky!important;
	top:0!important;
	z-index:9!important;
	box-shadow:0 2px 0 var(--dp-brutalist-border)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-table th.dp-panel-actions,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table td.dp-panel-actions{
	border-left:2px solid var(--dp-brutalist-border)!important;
	box-shadow:-4px 0 0 color-mix(in srgb,var(--dp-brutalist-border) 10%,transparent)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-table td.dp-panel-actions .dp-panel-actions{
	gap:7px!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-table td.dp-panel-actions .dp-panel-action,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table td.dp-panel-actions .dp-panel-row-link,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table td.dp-panel-actions .dp-panel-row-more>summary{
	min-height:32px!important;
	border-width:2px!important;
	font-weight:950!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-row-more-menu>header,
body[data-dp-theme-effects~="brutalist"] .dp-panel-action-menu header,
body[data-dp-theme-effects~="brutalist"] .dp-panel-saved-view-menu header{
	margin:-8px -8px 8px!important;
	padding:10px!important;
	border-bottom:2px solid var(--dp-brutalist-border)!important;
	background:var(--dp-warning-500,#facc15)!important;
	color:#111!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-row-more-menu a,
body[data-dp-theme-effects~="brutalist"] .dp-panel-row-more-menu button,
body[data-dp-theme-effects~="brutalist"] .dp-panel-action-menu a,
body[data-dp-theme-effects~="brutalist"] .dp-panel-action-menu button,
body[data-dp-theme-effects~="brutalist"] .dp-panel-saved-view-menu a,
body[data-dp-theme-effects~="brutalist"] .dp-panel-saved-view-menu button{
	border:2px solid transparent!important;
	font-weight:950!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-row-more-menu a:hover,
body[data-dp-theme-effects~="brutalist"] .dp-panel-row-more-menu button:hover,
body[data-dp-theme-effects~="brutalist"] .dp-panel-action-menu a:hover,
body[data-dp-theme-effects~="brutalist"] .dp-panel-action-menu button:hover,
body[data-dp-theme-effects~="brutalist"] .dp-panel-saved-view-menu a:hover,
body[data-dp-theme-effects~="brutalist"] .dp-panel-saved-view-menu button:hover{
	border-color:var(--dp-brutalist-border)!important;
	background:var(--dp-primary-600,#2563eb)!important;
	color:#fff!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-widget,
body[data-dp-theme-effects~="brutalist"] .dp-panel-summary,
body[data-dp-theme-effects~="brutalist"] .dp-panel-insight{
	position:relative!important;
	padding-top:20px!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-widget:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-summary:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-insight:after{
	content:""!important;
	display:block!important;
	position:absolute!important;
	inset:0 0 auto!important;
	height:8px!important;
	background:var(--dp-primary-600,#2563eb)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-widget-success:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-summary-success:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-insight-success:after{background:var(--dp-success-600,#16a34a)!important}
body[data-dp-theme-effects~="brutalist"] .dp-panel-widget-warning:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-summary-warning:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-insight-warning:after{background:var(--dp-warning-500,#facc15)!important}
body[data-dp-theme-effects~="brutalist"] .dp-panel-widget-danger:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-summary-danger:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-insight-danger:after{background:var(--dp-danger-600,#dc2626)!important}
body[data-dp-theme-effects~="brutalist"] .dp-panel-widget-info:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-summary-info:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-insight-info:after{background:var(--dp-info-600,#0891b2)!important}
body[data-dp-theme-effects~="brutalist"] .dp-panel-modal{
	border-width:3px!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-modal-header{
	border-bottom:3px solid var(--dp-brutalist-border)!important;
	background:var(--dp-surface,#fffdf4)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-modal-body{
	background:var(--dp-surface_muted,#ede8d8)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-modal-close{
	border:2px solid var(--dp-brutalist-border)!important;
	background:var(--dp-danger-600,#dc2626)!important;
	color:#fff!important;
	box-shadow:var(--dp-brutalist-shadow-soft)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-sidebar-icon,
body[data-dp-theme-effects~="brutalist"] .dp-panel-horizontal-icon,
body[data-dp-theme-effects~="brutalist"] .dp-panel-action-icon,
body[data-dp-theme-effects~="brutalist"] .dp-panel-entry-icon{
	border:2px solid var(--dp-brutalist-border)!important;
	background:var(--dp-surface,#fffdf4)!important;
	color:var(--dp-text,#111)!important;
	box-shadow:2px 2px 0 var(--dp-brutalist-border)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-sidebar-link.active .dp-panel-sidebar-icon,
body[data-dp-theme-effects~="brutalist"] .dp-panel-horizontal-link.active .dp-panel-horizontal-icon{
	background:#fff!important;
	color:#111!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-bulk-bar{
	border:3px solid var(--dp-brutalist-border)!important;
	background:var(--dp-warning-500,#facc15)!important;
	box-shadow:var(--dp-brutalist-shadow)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-bulk-status{
	color:#111!important;
	font-weight:950!important;
}
body[data-dp-theme-effects~="brutalist"] ::selection{
	background:var(--dp-warning-500,#facc15);
	color:#111;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="brutalist"] .dp-panel-button,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="brutalist"] .dp-panel-action-primary,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="brutalist"] .dp-panel-theme-toggle button[aria-pressed=true],
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="brutalist"] .dp-panel-density a.active,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="brutalist"] .dp-panel-table-view.active{
	color:#fff!important;
}
@media(prefers-color-scheme:dark){
body[data-dp-theme-mode="system"][data-dp-theme-effects~="brutalist"] .dp-panel-button,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="brutalist"] .dp-panel-action-primary,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="brutalist"] .dp-panel-theme-toggle button[aria-pressed=true],
body[data-dp-theme-mode="system"][data-dp-theme-effects~="brutalist"] .dp-panel-density a.active,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="brutalist"] .dp-panel-table-view.active{
	color:#fff!important;
}
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-form-pulse,
body[data-dp-theme-effects~="brutalist"] .dp-panel-form-pulse *,
body[data-dp-theme-effects~="brutalist"] .dp-panel-form-pulse *:before,
body[data-dp-theme-effects~="brutalist"] .dp-panel-form-pulse *:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-nav-group,
body[data-dp-theme-effects~="brutalist"] .dp-panel-nav-group *,
body[data-dp-theme-effects~="brutalist"] .dp-panel-nav-group *:before,
body[data-dp-theme-effects~="brutalist"] .dp-panel-nav-group *:after,
body[data-dp-theme-effects~="brutalist"] .dp-panel-commandbar,
body[data-dp-theme-effects~="brutalist"] .dp-panel-commandbar *,
body[data-dp-theme-effects~="brutalist"] .dp-panel-commandbar *:before,
body[data-dp-theme-effects~="brutalist"] .dp-panel-commandbar *:after{
	border-radius:0!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-form-pulse,
body[data-dp-theme-effects~="brutalist"] .dp-panel-nav-group,
body[data-dp-theme-effects~="brutalist"] .dp-panel-commandbar{
	border:2px solid var(--dp-brutalist-border)!important;
	background:var(--dp-surface,#fffdf4)!important;
	box-shadow:var(--dp-brutalist-shadow)!important;
	overflow:visible!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-form-pulse header,
body[data-dp-theme-effects~="brutalist"] .dp-panel-nav-group header{
	border-bottom:2px solid var(--dp-brutalist-border)!important;
	background:var(--dp-warning-500,#facc15)!important;
	color:#111!important;
	padding:10px 12px!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-heading-row{
	background:transparent!important;
	background-image:none!important;
	box-shadow:none!important;
	border:0!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-table-scroll th.dp-panel-actions,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table-scroll td.dp-panel-actions,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table th.dp-panel-actions,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table td.dp-panel-actions{
	position:static!important;
	right:auto!important;
	z-index:auto!important;
	border-left:2px solid var(--dp-brutalist-border)!important;
	background:inherit!important;
	box-shadow:none!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-table-scroll tr:hover td.dp-panel-actions{
	background:var(--dp-warning-100,#fef3c7)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-table td.dp-panel-actions>.dp-panel-actions,
body[data-dp-theme-effects~="brutalist"] .dp-panel-table-scroll td.dp-panel-actions>.dp-panel-actions{
	position:relative!important;
	right:auto!important;
	background:transparent!important;
	box-shadow:none!important;
}
body[data-dp-theme-effects~="brutalist"]{
	--dp-radius:0px!important;
	--dp-nav-shell-radius:0px!important;
	--dp-nav-item-radius:0px!important;
	--dp-nav-radius:0px!important;
}
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root),
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) [class*="dp-panel-"],
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) [class*="dp-panel-"]:before,
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) [class*="dp-panel-"]:after,
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) button,
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) a,
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) summary,
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) input,
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) select,
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) textarea,
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) img,
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) i,
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) span,
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) small,
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) em,
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) strong{
	border-radius:0!important;
}
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) ::-webkit-scrollbar-track,
body[data-dp-theme-effects~="brutalist"] :is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root) ::-webkit-scrollbar-thumb{
	border-radius:0!important;
}
CSS;
	}

	/**
	 * Returns the glass theme-effect stylesheet.
	 *
	 * The block layers translucent surfaces, backdrop filters, tone-aware borders,
	 * reduced-transparency fallbacks, forced-colors handling, dark/system mode
	 * overrides, and print/mobile constraints for panels using glass effects.
	 *
	 * @return string CSS emitted for panels using the glass theme effect.
	 */
	private static function glassThemeCss(): string {
		return <<<'CSS'
body[data-dp-theme-effects~="glass"]{color-scheme:light;--dp-glass-primary:var(--dp-primary-600,#0284c7);--dp-glass-success:var(--dp-success-600,#059669);--dp-glass-warning:var(--dp-warning-600,#d97706);--dp-glass-danger:var(--dp-danger-600,#e11d48);--dp-glass-info:var(--dp-info-600,#0891b2)}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"]{color-scheme:dark}
@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"]{color-scheme:dark}}
body[data-dp-theme-effects~="glass"]:after{content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;opacity:var(--dp-glass_noise_opacity,.10);background-image:linear-gradient(115deg,rgba(255,255,255,.38) 0 1px,transparent 1px 9px),linear-gradient(35deg,rgba(15,23,42,.10) 0 1px,transparent 1px 11px);background-size:34px 34px,42px 42px;mix-blend-mode:soft-light}
body[data-dp-theme-effects~="glass"] .dp-panel-main-region>header:before{height:3px!important;background:linear-gradient(90deg,color-mix(in srgb,var(--dp-glass-primary) 88%,transparent),color-mix(in srgb,var(--dp-glass-info) 82%,transparent),color-mix(in srgb,var(--dp-success-600,#16a34a) 62%,transparent))!important;filter:drop-shadow(0 0 14px color-mix(in srgb,var(--dp-glass-info) 34%,transparent))}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-primary,body[data-dp-theme-effects~="glass"] .dp-panel-summary-primary,body[data-dp-theme-effects~="glass"] .dp-panel-insight-primary,body[data-dp-theme-effects~="glass"] .dp-panel-board-column-primary,body[data-dp-theme-effects~="glass"] .dp-panel-alert-primary,body[data-dp-theme-effects~="glass"] .dp-panel-surface-guidance-primary{--dp-glass-tone:var(--dp-glass-primary)}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-success,body[data-dp-theme-effects~="glass"] .dp-panel-summary-success,body[data-dp-theme-effects~="glass"] .dp-panel-insight-success,body[data-dp-theme-effects~="glass"] .dp-panel-board-column-success,body[data-dp-theme-effects~="glass"] .dp-panel-alert-success,body[data-dp-theme-effects~="glass"] .dp-panel-notice-success,body[data-dp-theme-effects~="glass"] .dp-panel-surface-guidance-success{--dp-glass-tone:var(--dp-glass-success)}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-warning,body[data-dp-theme-effects~="glass"] .dp-panel-summary-warning,body[data-dp-theme-effects~="glass"] .dp-panel-insight-warning,body[data-dp-theme-effects~="glass"] .dp-panel-board-column-warning,body[data-dp-theme-effects~="glass"] .dp-panel-alert-warning,body[data-dp-theme-effects~="glass"] .dp-panel-notice-warning,body[data-dp-theme-effects~="glass"] .dp-panel-surface-guidance-warning{--dp-glass-tone:var(--dp-glass-warning)}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-danger,body[data-dp-theme-effects~="glass"] .dp-panel-summary-danger,body[data-dp-theme-effects~="glass"] .dp-panel-insight-danger,body[data-dp-theme-effects~="glass"] .dp-panel-board-column-danger,body[data-dp-theme-effects~="glass"] .dp-panel-alert-danger,body[data-dp-theme-effects~="glass"] .dp-panel-notice-error,body[data-dp-theme-effects~="glass"] .dp-panel-surface-guidance-danger{--dp-glass-tone:var(--dp-glass-danger)}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-info,body[data-dp-theme-effects~="glass"] .dp-panel-summary-info,body[data-dp-theme-effects~="glass"] .dp-panel-insight-info,body[data-dp-theme-effects~="glass"] .dp-panel-board-column-info,body[data-dp-theme-effects~="glass"] .dp-panel-alert-info,body[data-dp-theme-effects~="glass"] .dp-panel-notice-info,body[data-dp-theme-effects~="glass"] .dp-panel-surface-guidance-info{--dp-glass-tone:var(--dp-glass-info)}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-primary,body[data-dp-theme-effects~="glass"] .dp-panel-widget-success,body[data-dp-theme-effects~="glass"] .dp-panel-widget-warning,body[data-dp-theme-effects~="glass"] .dp-panel-widget-danger,body[data-dp-theme-effects~="glass"] .dp-panel-widget-info,body[data-dp-theme-effects~="glass"] .dp-panel-summary-primary,body[data-dp-theme-effects~="glass"] .dp-panel-summary-success,body[data-dp-theme-effects~="glass"] .dp-panel-summary-warning,body[data-dp-theme-effects~="glass"] .dp-panel-summary-danger,body[data-dp-theme-effects~="glass"] .dp-panel-summary-info,body[data-dp-theme-effects~="glass"] .dp-panel-insight-primary,body[data-dp-theme-effects~="glass"] .dp-panel-insight-success,body[data-dp-theme-effects~="glass"] .dp-panel-insight-warning,body[data-dp-theme-effects~="glass"] .dp-panel-insight-danger,body[data-dp-theme-effects~="glass"] .dp-panel-insight-info,body[data-dp-theme-effects~="glass"] .dp-panel-board-column-primary,body[data-dp-theme-effects~="glass"] .dp-panel-board-column-success,body[data-dp-theme-effects~="glass"] .dp-panel-board-column-warning,body[data-dp-theme-effects~="glass"] .dp-panel-board-column-danger,body[data-dp-theme-effects~="glass"] .dp-panel-board-column-info,body[data-dp-theme-effects~="glass"] .dp-panel-alert-card,body[data-dp-theme-effects~="glass"] .dp-panel-notice,body[data-dp-theme-effects~="glass"] .dp-panel-surface-guidance{border-color:color-mix(in srgb,var(--dp-glass-tone,var(--dp-glass-primary)) 28%,var(--dp-glass_border))!important;background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass-tone,var(--dp-glass-primary)) var(--dp-glass_tone_strength,14%),transparent),var(--dp-glass_surface_bg))!important}
body[data-dp-theme-effects~="glass"] .dp-panel-widget:before{inset:auto -36px -46px auto!important;width:128px!important;height:128px!important;background:radial-gradient(circle,color-mix(in srgb,var(--dp-glass-tone,var(--dp-glass-primary)) 42%,transparent),transparent 64%)!important;opacity:.28!important}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon,body[data-dp-theme-effects~="glass"] .dp-panel-entry-icon,body[data-dp-theme-effects~="glass"] .dp-panel-action-icon{background:color-mix(in srgb,var(--dp-glass-tone,var(--dp-glass-primary)) 18%,var(--dp-glass_control_bg))!important;border:1px solid color-mix(in srgb,var(--dp-glass-tone,var(--dp-glass-primary)) 25%,var(--dp-glass_border))!important;box-shadow:var(--dp-glass_edge)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon{display:grid!important;place-items:center!important;right:14px!important;top:13px!important;max-width:48px!important;width:32px!important;height:32px!important;overflow:hidden!important;color:var(--dp-text_muted)!important;font-size:0!important;line-height:0!important;white-space:nowrap!important;opacity:.96!important}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon[hidden]{display:none!important}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon:before{content:"";display:block;width:14px;height:14px;border-radius:5px;background:currentColor;color:var(--dp-text_muted);box-shadow:inset 0 0 0 2px color-mix(in srgb,var(--dp-glass-tone,var(--dp-glass-primary)) 30%,var(--dp-glass_border))}
body[data-dp-theme-effects~="glass"] .dp-panel-chart svg{filter:drop-shadow(0 14px 24px color-mix(in srgb,currentColor 14%,transparent))}
body[data-dp-theme-effects~="glass"] .dp-panel-chart-grid{stroke:color-mix(in srgb,var(--dp-glass_border) 68%,transparent)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-chart-area{opacity:.22!important}
body[data-dp-theme-effects~="glass"] .dp-panel-chart-fill{filter:drop-shadow(0 8px 14px color-mix(in srgb,currentColor 18%,transparent))}
body[data-dp-theme-effects~="glass"] .dp-panel-chart-ring-bg{stroke:color-mix(in srgb,var(--dp-glass_border) 72%,transparent)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-tab-list,body[data-dp-theme-effects~="glass"] .dp-panel-step-list{border:1px solid var(--dp-glass_border)!important;border-radius:999px!important;background:var(--dp-glass_surface_muted_bg)!important;box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;padding:5px!important;backdrop-filter:var(--dp-glass_blur)!important;-webkit-backdrop-filter:var(--dp-glass_blur)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-tab-list button,body[data-dp-theme-effects~="glass"] .dp-panel-step-list button{border:1px solid transparent!important;background:transparent!important;color:var(--dp-text_muted)!important;box-shadow:none!important}
body[data-dp-theme-effects~="glass"] .dp-panel-tab-list button[aria-selected=true],body[data-dp-theme-effects~="glass"] .dp-panel-step-list button[aria-current=step]{background:linear-gradient(135deg,var(--dp-primary-600,#2563eb),var(--dp-info-600,#0891b2))!important;border-color:color-mix(in srgb,#fff 24%,transparent)!important;color:#fff!important;box-shadow:0 14px 30px color-mix(in srgb,var(--dp-primary-600,#2563eb) 24%,transparent),inset 0 1px 0 rgba(255,255,255,.24)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:has(input[type=checkbox]:checked),body[data-dp-theme-effects~="glass"] .dp-panel-row-selected td{background:linear-gradient(90deg,color-mix(in srgb,var(--dp-primary-600,#2563eb) 18%,transparent),color-mix(in srgb,var(--dp-glass_surface_bg) 72%,transparent))!important}
body[data-dp-theme-effects~="glass"] .dp-panel-table [data-dp-panel-row]:focus-visible td,body[data-dp-theme-effects~="glass"] .dp-panel-table .dp-panel-row-focused td{background:linear-gradient(90deg,color-mix(in srgb,var(--dp-info-600,#0891b2) 18%,transparent),color-mix(in srgb,var(--dp-glass_surface_bg) 74%,transparent))!important;box-shadow:inset 0 1px 0 color-mix(in srgb,var(--dp-glass_border) 72%,transparent),inset 0 -1px 0 color-mix(in srgb,var(--dp-glass_border) 62%,transparent)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-search:focus-within,body[data-dp-theme-effects~="glass"] .dp-panel-global-search:focus-within,body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll:focus,body[data-dp-theme-effects~="glass"] .dp-panel-field:focus-within{box-shadow:var(--dp-glass_focus)!important;border-radius:calc(var(--dp-radius,16px) + 2px)}
body[data-dp-theme-effects~="glass"] .dp-panel-toast-success{--dp-glass-tone:var(--dp-glass-success)}body[data-dp-theme-effects~="glass"] .dp-panel-toast-warning{--dp-glass-tone:var(--dp-glass-warning)}body[data-dp-theme-effects~="glass"] .dp-panel-toast-error{--dp-glass-tone:var(--dp-glass-danger)}body[data-dp-theme-effects~="glass"] .dp-panel-toast-info{--dp-glass-tone:var(--dp-glass-info)}
body[data-dp-theme-effects~="glass"] .dp-panel-toast{border-color:color-mix(in srgb,var(--dp-glass-tone,var(--dp-glass-primary)) 30%,var(--dp-glass_border))!important;background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass-tone,var(--dp-glass-primary)) 12%,transparent),var(--dp-glass_menu_bg))!important}
body[data-dp-theme-effects~="glass"] .dp-panel-toast-icon{background:color-mix(in srgb,var(--dp-glass-tone,var(--dp-glass-primary)) 18%,var(--dp-glass_control_bg))!important;color:var(--dp-text)!important;border:1px solid color-mix(in srgb,var(--dp-glass-tone,var(--dp-glass-primary)) 22%,var(--dp-glass_border))!important}
body[data-dp-theme-effects~="glass"] *{scrollbar-color:var(--dp-glass_scroll_thumb,rgba(14,165,233,.36)) var(--dp-glass_scroll_track,rgba(255,255,255,.24));scrollbar-width:thin}
body[data-dp-theme-effects~="glass"] ::-webkit-scrollbar{width:11px;height:11px}
body[data-dp-theme-effects~="glass"] ::-webkit-scrollbar-track{background:var(--dp-glass_scroll_track,rgba(255,255,255,.24));border-radius:999px}
body[data-dp-theme-effects~="glass"] ::-webkit-scrollbar-thumb{border:3px solid transparent;border-radius:999px;background:var(--dp-glass_scroll_thumb,rgba(14,165,233,.36));background-clip:padding-box}
body[data-dp-theme-effects~="glass"] ::-webkit-scrollbar-thumb:hover{background:color-mix(in srgb,var(--dp-glass_scroll_thumb,rgba(14,165,233,.36)) 76%,var(--dp-primary-600,#2563eb));background-clip:padding-box}
body[data-dp-theme-effects~="glass"] .dp-panel-empty-state{position:relative;overflow:hidden;border-color:var(--dp-glass_border)!important;background:radial-gradient(circle at 20% 0,color-mix(in srgb,var(--dp-glass-info) 16%,transparent),transparent 18rem),var(--dp-glass_surface_muted_bg)!important;box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;backdrop-filter:var(--dp-glass_blur)!important;-webkit-backdrop-filter:var(--dp-glass_blur)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-empty-state:before{content:"";position:absolute;inset:-45% auto auto -20%;width:46%;height:160%;transform:rotate(18deg);background:var(--dp-glass_shimmer);opacity:.62;pointer-events:none}
body[data-dp-theme-effects~="glass"] .dp-panel-empty{border:1px dashed color-mix(in srgb,var(--dp-glass_border) 74%,transparent);border-radius:14px;background:var(--dp-glass_surface_muted_bg);padding:14px!important;box-shadow:var(--dp-glass_edge)}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-loading{position:relative;overflow:hidden;border:1px solid var(--dp-glass_border);border-radius:18px;background:var(--dp-glass_surface_muted_bg);padding:18px;box-shadow:var(--dp-glass_edge)}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-loading:before,body[data-dp-theme-effects~="glass"] .dp-panel-form-loading:after,body[data-dp-theme-effects~="glass"] .dp-panel-action-loading:after{content:"";position:absolute;inset:0;background:var(--dp-glass_shimmer);transform:translateX(-100%);animation:dp-panel-glass-shimmer 1.25s ease-in-out infinite;pointer-events:none}
body[data-dp-theme-effects~="glass"] .dp-panel-form-loading{position:relative;overflow:hidden;border-radius:inherit}
body[data-dp-theme-effects~="glass"] .dp-panel-action-loading{position:relative;overflow:hidden}
@keyframes dp-panel-glass-shimmer{to{transform:translateX(100%)}}
body[data-dp-theme-effects~="glass"] .dp-panel-record-pulse-grid>*,body[data-dp-theme-effects~="glass"] .dp-panel-table-pulse-grid>*,body[data-dp-theme-effects~="glass"] .dp-panel-board-pulse-grid>*,body[data-dp-theme-effects~="glass"] .dp-panel-form-pulse-grid>*{background:var(--dp-glass_surface_muted_bg)!important;border-color:var(--dp-glass_border)!important;box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;backdrop-filter:var(--dp-glass_blur)!important;-webkit-backdrop-filter:var(--dp-glass_blur)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-record-pulse header,body[data-dp-theme-effects~="glass"] .dp-panel-table-pulse header,body[data-dp-theme-effects~="glass"] .dp-panel-board-pulse header,body[data-dp-theme-effects~="glass"] .dp-panel-form-pulse header{border-color:color-mix(in srgb,var(--dp-glass_border) 78%,transparent)!important;background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass-info) 10%,transparent),transparent)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-link.active,body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-submenu.active>summary,body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-link.active,body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-submenu.active>summary{box-shadow:var(--dp-glass_active_glow),inset 0 1px 0 rgba(255,255,255,.26)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-icon,body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-submenu.active>summary .dp-panel-sidebar-icon{box-shadow:inset 0 1px 0 rgba(255,255,255,.28),0 8px 20px rgba(15,23,42,.10)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-table-can-scroll-left:before{background:linear-gradient(90deg,color-mix(in srgb,var(--dp-primary-600,#2563eb) 18%,transparent),transparent)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-table-can-scroll-right:after{background:linear-gradient(270deg,color-mix(in srgb,var(--dp-primary-600,#2563eb) 18%,transparent),transparent)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-column-resizer:after{background:color-mix(in srgb,var(--dp-glass_border) 38%,transparent)}
body[data-dp-theme-effects~="glass"] .dp-panel-column-resizer:hover:after,body.dp-panel-column-resizing[data-dp-theme-effects~="glass"] .dp-panel-column-resizer:after{background:var(--dp-glass-info)!important;box-shadow:0 0 0 4px color-mix(in srgb,var(--dp-glass-info) 18%,transparent)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-board-drop{outline:2px solid color-mix(in srgb,var(--dp-glass-success) 72%,transparent)!important;outline-offset:3px;background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass-success) 16%,transparent),var(--dp-glass_surface_muted_bg))!important;box-shadow:var(--dp-glass_active_glow)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-board-drop-blocked{outline:2px solid color-mix(in srgb,var(--dp-glass-danger) 70%,transparent)!important;outline-offset:3px;background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass-danger) 14%,transparent),var(--dp-glass_surface_muted_bg))!important}
body[data-dp-theme-effects~="glass"] .dp-panel-board-dragging{opacity:.72;transform:scale(.985) rotate(.35deg)!important;box-shadow:var(--dp-glass_shadow_lifted)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-relation{background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass-info) 8%,transparent),var(--dp-glass_surface_bg))!important}
body[data-dp-theme-effects~="glass"] .dp-panel-relation-aside,body[data-dp-theme-effects~="glass"] .dp-panel-relation-meta span,body[data-dp-theme-effects~="glass"] .dp-panel-relation-meta strong{background:var(--dp-glass_surface_muted_bg)!important;border-color:var(--dp-glass_border)!important;box-shadow:var(--dp-glass_edge)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-filter-chip{background:var(--dp-glass_control_bg)!important;border-color:var(--dp-glass_border)!important;box-shadow:var(--dp-glass_edge)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-filter-chip small{color:var(--dp-primary-700,#175cd3)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-breadcrumbs a:hover{background:var(--dp-glass_control_bg)!important;box-shadow:var(--dp-glass_edge)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-page-table,
body[data-dp-theme-effects~="glass"] .dp-panel-table-shell,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll,
body[data-dp-theme-effects~="glass"] .dp-panel-table,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar,
body[data-dp-theme-effects~="glass"] .dp-panel-toolbar,
body[data-dp-theme-effects~="glass"] .dp-panel-page-table:has(.dp-panel-row-more[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-table-shell:has(.dp-panel-row-more[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll:has(.dp-panel-row-more[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-page-table:has(.dp-panel-action-group[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-table-shell:has(.dp-panel-action-group[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll:has(.dp-panel-action-group[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-page-table:has(.dp-panel-column-picker[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar:has(.dp-panel-column-picker[open]){
	overflow:visible!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table-shell,
body[data-dp-theme-effects~="glass"] .dp-panel-page-table,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar{
	z-index:auto!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table-shell:has(.dp-panel-row-more[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-page-table:has(.dp-panel-row-more[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-table-shell:has(.dp-panel-action-group[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-page-table:has(.dp-panel-action-group[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar:has(.dp-panel-column-picker[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar:has(.dp-panel-saved-view-menu[open]){
	z-index:16000!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-floating .dp-panel-row-more-menu{
	position:fixed!important;
	left:var(--dp-row-menu-left)!important;
	top:var(--dp-row-menu-top)!important;
	width:var(--dp-row-menu-width)!important;
	max-height:var(--dp-row-menu-max-height)!important;
	overflow:auto!important;
	z-index:2147483000!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-row-more[open]>summary,
body[data-dp-theme-effects~="glass"] .dp-panel-action-group[open]>summary,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker[open]>summary,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu[open]>summary{
	position:relative!important;
	z-index:2147482999!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-group[open]>summary.dp-panel-action-primary,
body[data-dp-theme-effects~="glass"] .dp-panel-action-group[open]>summary.dp-panel-action-style-outline.dp-panel-action-primary,
body[data-dp-theme-effects~="glass"] .dp-panel-action-group[open]>summary.dp-panel-action-primary:not(.dp-panel-action-disabled){
	background:linear-gradient(135deg,var(--dp-primary-600,#2563eb),var(--dp-info-600,#0891b2))!important;
	border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 78%,#fff 12%)!important;
	color:#fff!important;
	box-shadow:0 14px 34px color-mix(in srgb,var(--dp-primary-600,#2563eb) 24%,transparent),var(--dp-glass_edge)!important;
	text-shadow:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-group[open]>summary.dp-panel-action-primary *,
body[data-dp-theme-effects~="glass"] .dp-panel-action-group[open]>summary.dp-panel-action-primary .dp-panel-action-label,
body[data-dp-theme-effects~="glass"] .dp-panel-action-group[open]>summary.dp-panel-action-primary .dp-panel-action-group-chevron{
	color:#fff!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-group[open]>summary.dp-panel-action-primary .dp-panel-action-icon{
	background:rgba(255,255,255,.24)!important;
	border-color:rgba(255,255,255,.32)!important;
	color:#fff!important;
	box-shadow:inset 0 0 0 1px rgba(255,255,255,.24)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker form,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu>div{
	z-index:2147482000!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-row-link{
	color:var(--dp-text,#0f172a)!important;
	text-shadow:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action:focus-visible,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action:focus-visible,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-row-link:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-row-link:focus-visible{
	background:linear-gradient(135deg,rgba(255,255,255,.96),rgba(232,240,252,.9))!important;
	border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 24%,var(--dp-glass_border))!important;
	color:#0f172a!important;
	box-shadow:0 8px 20px rgba(15,23,42,.10),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action-info:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action-info:focus-visible,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action-info:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action-info:focus-visible{
	background:linear-gradient(135deg,var(--dp-info-600,#0891b2),color-mix(in srgb,var(--dp-info-600,#0891b2) 74%,var(--dp-primary-600,#2563eb)))!important;
	border-color:color-mix(in srgb,var(--dp-info-600,#0891b2) 78%,#fff 12%)!important;
	color:#fff!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action-info:hover *,
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action-info:focus-visible *,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action-info:hover *,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action-info:focus-visible *{
	color:#fff!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action-info:hover .dp-panel-action-description,
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action-info:focus-visible .dp-panel-action-description,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action-info:hover .dp-panel-action-description,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action-info:focus-visible .dp-panel-action-description{
	color:rgba(255,255,255,.86)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action-info:hover .dp-panel-action-icon,
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action-info:focus-visible .dp-panel-action-icon,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action-info:hover .dp-panel-action-icon,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action-info:focus-visible .dp-panel-action-icon{
	background:rgba(255,255,255,.22)!important;
	border-color:rgba(255,255,255,.28)!important;
	color:#fff!important;
	box-shadow:inset 0 0 0 1px rgba(255,255,255,.22)!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-row-link{
	color:#f8fafc!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action:hover,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action:focus-visible,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action:hover,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action:focus-visible,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-row-link:hover,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-row-link:focus-visible{
	background:linear-gradient(135deg,rgba(51,65,85,.94),rgba(15,23,42,.82))!important;
	border-color:color-mix(in srgb,var(--dp-primary-500,#3b82f6) 34%,var(--dp-glass_border))!important;
	color:#fff!important;
}
@media(prefers-color-scheme:dark){
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-row-link{
	color:#f8fafc!important;
}
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action:hover,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-action-menu .dp-panel-action:focus-visible,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action:hover,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-action:focus-visible,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-row-link:hover,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu .dp-panel-row-link:focus-visible{
	background:linear-gradient(135deg,rgba(51,65,85,.94),rgba(15,23,42,.82))!important;
	border-color:color-mix(in srgb,var(--dp-primary-500,#3b82f6) 34%,var(--dp-glass_border))!important;
	color:#fff!important;
}
}
body[data-dp-theme-effects~="glass"] .dp-panel-button-secondary,
body[data-dp-theme-effects~="glass"] .dp-panel-action-neutral,
body[data-dp-theme-effects~="glass"] .dp-panel-row-link,
body[data-dp-theme-effects~="glass"] .dp-panel-table-view,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker summary,
body[data-dp-theme-effects~="glass"] .dp-panel-filter-trigger,
body[data-dp-theme-effects~="glass"] .dp-panel-modal-header-actions>a,
body[data-dp-theme-effects~="glass"] .dp-panel-modal-header-actions>button{
	color:var(--dp-text,#0f172a)!important;
	border-color:color-mix(in srgb,var(--dp-glass_border) 72%,var(--dp-text) 18%)!important;
	text-shadow:none!important;
}
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-button-secondary,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-action-neutral,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-row-link,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-table-view,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-column-picker summary,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-filter-trigger,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-modal-header-actions>a,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-modal-header-actions>button{
	background:linear-gradient(135deg,rgba(255,255,255,.92),rgba(255,255,255,.68))!important;
	box-shadow:0 8px 24px rgba(15,23,42,.075),var(--dp-glass_edge)!important;
}
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-modal .dp-panel-modal-back,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-modal .dp-panel-modal-open-full,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-modal .dp-panel-modal-copy-link,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-modal .dp-panel-modal-refresh,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-modal .dp-panel-modal-expand,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-modal .dp-panel-button-secondary,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-modal .dp-panel-action-neutral,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-modal .dp-panel-row-link{
	color:#1d2939!important;
	background:linear-gradient(135deg,rgba(255,255,255,.96),rgba(242,246,252,.86))!important;
	border-color:#cbd5e1!important;
	box-shadow:0 10px 28px rgba(15,23,42,.10),inset 0 0 0 1px rgba(255,255,255,.52)!important;
	text-shadow:none!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-button-secondary,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-action-neutral,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-row-link,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-table-view,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-column-picker summary,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-filter-trigger,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-modal-header-actions>a,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-modal-header-actions>button{
	color:var(--dp-text,#f8fafc)!important;
	background:linear-gradient(135deg,rgba(51,65,85,.86),rgba(15,23,42,.68))!important;
}
@media(prefers-color-scheme:dark){
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-button-secondary,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-action-neutral,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-row-link,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-table-view,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-column-picker summary,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-filter-trigger,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-modal-header-actions>a,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-modal-header-actions>button{
	color:var(--dp-text,#f8fafc)!important;
	background:linear-gradient(135deg,rgba(51,65,85,.86),rgba(15,23,42,.68))!important;
}
}
body[data-dp-theme-effects~="glass"] .dp-panel-table-views{
	display:flex!important;
	align-items:center!important;
	gap:8px!important;
	width:auto!important;
	max-width:100%!important;
	min-height:0!important;
	height:auto!important;
	margin:0 0 12px!important;
	padding:4px!important;
	overflow-x:auto!important;
	overflow-y:hidden!important;
	flex-wrap:nowrap!important;
	border:1px solid color-mix(in srgb,var(--dp-glass_border) 70%,transparent)!important;
	border-radius:999px!important;
	background:color-mix(in srgb,var(--dp-glass_surface_muted_bg) 54%,transparent)!important;
	box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 20%,transparent)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
	scrollbar-width:thin!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table-views:before,
body[data-dp-theme-effects~="glass"] .dp-panel-table-views:after{
	content:none!important;
	display:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table-view{
	flex:0 0 auto!important;
	min-width:max-content!important;
	min-height:34px!important;
	height:34px!important;
	padding:0 12px!important;
	background:transparent!important;
	color:var(--dp-text_muted,#475467)!important;
	box-shadow:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table-view.active{
	background:linear-gradient(135deg,var(--dp-primary-600,#2563eb),color-mix(in srgb,var(--dp-primary-600,#2563eb) 72%,var(--dp-info-600,#0891b2)))!important;
	border-color:transparent!important;
	color:#fff!important;
	box-shadow:0 10px 24px color-mix(in srgb,var(--dp-primary-600,#2563eb) 22%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table-view small{
	background:color-mix(in srgb,var(--dp-surface) 88%,transparent)!important;
	color:var(--dp-text,#0f172a)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table-view.active small{
	background:rgba(255,255,255,.22)!important;
	color:#fff!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker form,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu>div{
	color:var(--dp-text,#0f172a)!important;
	background:color-mix(in srgb,var(--dp-glass_menu_bg,var(--dp-surface)) 86%,var(--dp-surface))!important;
	border-color:color-mix(in srgb,var(--dp-glass_border) 78%,var(--dp-text) 10%)!important;
	box-shadow:0 24px 70px rgba(15,23,42,.20),var(--dp-glass_edge)!important;
	backdrop-filter:blur(22px) saturate(1.2)!important;
	-webkit-backdrop-filter:blur(22px) saturate(1.2)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu a,
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu button,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu a,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu button,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker label,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu a,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu button{
	color:var(--dp-text,#0f172a)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu a:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu button:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu a:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu button:hover{
	background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 12%,var(--dp-surface))!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-table-view,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-action-menu a,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-action-menu button,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu a,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu button,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-column-picker label,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu a,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu button{
	color:var(--dp-text,#f8fafc)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget strong,
body[data-dp-theme-effects~="glass"] .dp-panel-summary strong,
body[data-dp-theme-effects~="glass"] .dp-panel-insight strong,
body[data-dp-theme-effects~="glass"] .dp-panel-show-field strong,
body[data-dp-theme-effects~="glass"] .dp-panel-cell-primary,
body[data-dp-theme-effects~="glass"] .dp-panel-cell-stack strong,
body[data-dp-theme-effects~="glass"] .dp-panel-table td{
	color:var(--dp-text,#0f172a)!important;
	text-shadow:0 1px 0 color-mix(in srgb,var(--dp-surface) 42%,transparent);
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget small,
body[data-dp-theme-effects~="glass"] .dp-panel-summary small,
body[data-dp-theme-effects~="glass"] .dp-panel-insight small,
body[data-dp-theme-effects~="glass"] .dp-panel-show-field span,
body[data-dp-theme-effects~="glass"] .dp-panel-cell-stack small,
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta,
body[data-dp-theme-effects~="glass"] .dp-panel-pagination{
	color:var(--dp-text_muted,#475569)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table{
	background:color-mix(in srgb,var(--dp-surface) 86%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table th{
	background:color-mix(in srgb,var(--dp-surface) 88%,transparent)!important;
	color:var(--dp-text_muted,#475569)!important;
	text-shadow:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr{
	background:color-mix(in srgb,var(--dp-surface) 76%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:nth-child(odd){
	background:color-mix(in srgb,var(--dp-surface) 82%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:nth-child(even){
	background:color-mix(in srgb,var(--dp-glass_surface_muted_bg) 62%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll tr:hover td.dp-panel-actions{
	background:linear-gradient(90deg,color-mix(in srgb,var(--dp-primary-600,#2563eb) 10%,transparent),color-mix(in srgb,var(--dp-surface) 88%,transparent))!important;
}
body[data-dp-theme-effects~="glass"] input::placeholder,
body[data-dp-theme-effects~="glass"] textarea::placeholder{
	color:color-mix(in srgb,var(--dp-text_muted,#64748b) 82%,transparent)!important;
	opacity:1!important;
}
body[data-dp-theme-effects~="glass"] select option{
	background:var(--dp-surface,#fff)!important;
	color:var(--dp-text,#0f172a)!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] select option{
	background:#111827!important;
	color:#f8fafc!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-widget strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-summary strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-insight strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-show-field strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-cell-primary,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-cell-stack strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-table td{
	color:#f8fafc!important;
	text-shadow:0 1px 0 rgba(0,0,0,.24);
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon{
	color:color-mix(in srgb,var(--dp-glass-tone,var(--dp-primary-600,#2563eb)) 70%,var(--dp-text_muted))!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon[hidden]{
	display:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon:before,
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon:after{
	content:"";
	position:absolute;
	display:block;
	color:currentColor;
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon:before{
	width:14px;
	height:14px;
	border:2px solid currentColor;
	border-radius:5px;
	background:transparent!important;
	box-shadow:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon[data-dp-panel-icon="wallet"]:before{
	width:16px;
	height:11px;
	border-radius:4px;
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon[data-dp-panel-icon="wallet"]:after{
	width:4px;
	height:4px;
	right:8px;
	top:14px;
	border-radius:999px;
	background:currentColor;
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon[data-dp-panel-icon="shopping_bag"]:before,
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon[data-dp-panel-icon="shopping-bag"]:before{
	width:14px;
	height:13px;
	top:12px;
	border-radius:4px;
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon[data-dp-panel-icon="shopping_bag"]:after,
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon[data-dp-panel-icon="shopping-bag"]:after{
	width:8px;
	height:5px;
	top:8px;
	left:12px;
	border:2px solid currentColor;
	border-bottom:0;
	border-radius:8px 8px 0 0;
	background:transparent;
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon[data-dp-panel-icon="package_check"]:before,
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon[data-dp-panel-icon="package-check"]:before{
	transform:rotate(45deg);
	border-radius:3px;
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon[data-dp-panel-icon="package_check"]:after,
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon[data-dp-panel-icon="package-check"]:after{
	width:8px;
	height:4px;
	border-left:2px solid currentColor;
	border-bottom:2px solid currentColor;
	transform:rotate(-45deg);
	top:14px;
	left:13px;
	background:transparent;
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon[data-dp-panel-icon="shield_alert"]:before,
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon[data-dp-panel-icon="shield-alert"]:before{
	width:14px;
	height:16px;
	border-radius:8px 8px 10px 10px;
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget-icon[data-dp-panel-icon="activity"]:before{
	width:17px;
	height:10px;
	border:0;
	border-radius:0;
	background:linear-gradient(90deg,transparent 0 16%,currentColor 16% 28%,transparent 28% 40%,currentColor 40% 52%,transparent 52% 64%,currentColor 64% 76%,transparent 76%)!important;
	clip-path:polygon(0 60%,18% 60%,28% 10%,42% 92%,55% 36%,68% 60%,100% 60%,100% 74%,62% 74%,55% 58%,42% 100%,27% 34%,21% 74%,0 74%);
}
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar{
	border-color:color-mix(in srgb,var(--dp-glass_border) 82%,var(--dp-primary-600,#2563eb) 10%)!important;
	background:radial-gradient(circle at 8% 0,color-mix(in srgb,var(--dp-glass-primary) 13%,transparent),transparent 18rem),linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_surface_strong_bg) 86%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 74%,transparent))!important;
	box-shadow:0 22px 70px color-mix(in srgb,var(--dp-glass-primary) 10%,rgba(15,23,42,.10)),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar:after{
	opacity:.62!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar-top,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar-bottom,
body[data-dp-theme-effects~="glass"] .dp-panel-table-controls{
	border-color:color-mix(in srgb,var(--dp-glass_border) 70%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar .dp-panel-search input,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar .dp-panel-per-page select{
	background:color-mix(in srgb,var(--dp-glass_control_bg) 86%,var(--dp-surface) 14%)!important;
	border-color:color-mix(in srgb,var(--dp-glass_border) 72%,var(--dp-text) 10%)!important;
	box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 24%,transparent),0 10px 26px rgba(15,23,42,.055)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar .dp-panel-button,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar .dp-panel-action{
	border-color:color-mix(in srgb,#fff 22%,transparent)!important;
	box-shadow:0 14px 34px color-mix(in srgb,currentColor 14%,transparent),inset 0 1px 0 rgba(255,255,255,.18)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar .dp-panel-button-secondary,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar .dp-panel-action-neutral,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar .dp-panel-filter-trigger,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar .dp-panel-column-picker summary{
	background:color-mix(in srgb,var(--dp-glass_control_bg) 78%,var(--dp-surface) 22%)!important;
	color:var(--dp-text,#0f172a)!important;
	box-shadow:0 10px 24px rgba(15,23,42,.065),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-density,
body[data-dp-theme-effects~="glass"] .dp-panel-table-groups,
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta,
body[data-dp-theme-effects~="glass"] .dp-panel-pagination{
	border:1px solid color-mix(in srgb,var(--dp-glass_border) 72%,transparent)!important;
	background:color-mix(in srgb,var(--dp-glass_surface_muted_bg) 68%,transparent)!important;
	box-shadow:var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta{
	border-radius:16px!important;
	padding:8px 10px!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table-counts span,
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta-action,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu>summary{
	background:color-mix(in srgb,var(--dp-glass_control_bg) 82%,var(--dp-surface) 18%)!important;
	border-color:color-mix(in srgb,var(--dp-glass_border) 76%,transparent)!important;
	box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 18%,transparent)!important;
	color:var(--dp-text_muted,#475569)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-bulk-bar{
	position:relative!important;
	overflow:hidden!important;
	border:1px solid color-mix(in srgb,var(--dp-primary-600,#2563eb) 22%,var(--dp-glass_border))!important;
	border-radius:22px!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-primary-600,#2563eb) 10%,transparent),var(--dp-glass_surface_strong_bg))!important;
	box-shadow:0 18px 52px color-mix(in srgb,var(--dp-primary-600,#2563eb) 12%,transparent),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-bulk-bar:before{
	content:"";
	position:absolute;
	inset:0 auto 0 0;
	width:4px;
	background:linear-gradient(180deg,var(--dp-primary-600,#2563eb),var(--dp-info-600,#0891b2));
	opacity:.86;
}
body[data-dp-theme-effects~="glass"] .dp-panel-bulk-bar>*{
	position:relative;
	z-index:1;
}
body[data-dp-theme-effects~="glass"] .dp-panel-filter-modal-panel,
body[data-dp-theme-effects~="glass"] .dp-panel-filter-panel{
	border-color:color-mix(in srgb,var(--dp-glass_border) 82%,var(--dp-info-600,#0891b2) 10%)!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_surface_strong_bg) 86%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 78%,transparent))!important;
	box-shadow:0 16px 44px rgba(15,23,42,.10),var(--dp-glass_edge)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-filter-modal-panel>summary,
body[data-dp-theme-effects~="glass"] .dp-panel-filter-panel>summary{
	color:var(--dp-text,#0f172a)!important;
	background:color-mix(in srgb,var(--dp-glass_control_bg) 72%,transparent)!important;
	border-color:color-mix(in srgb,var(--dp-glass_border) 72%,transparent)!important;
	box-shadow:var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-filters{
	background:color-mix(in srgb,var(--dp-glass_surface_muted_bg) 74%,transparent)!important;
	border-color:color-mix(in srgb,var(--dp-glass_border) 70%,transparent)!important;
	box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 18%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-filter span,
body[data-dp-theme-effects~="glass"] .dp-panel-field span,
body[data-dp-theme-effects~="glass"] .dp-panel-field .dp-panel-help{
	color:var(--dp-text_muted,#475569)!important;
	text-shadow:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal{
	border-color:color-mix(in srgb,var(--dp-glass_border) 82%,var(--dp-primary-600,#2563eb) 8%)!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_surface_strong_bg) 88%,transparent),color-mix(in srgb,var(--dp-glass_surface_bg) 82%,transparent))!important;
	box-shadow:0 36px 120px rgba(15,23,42,.34),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-header{
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_surface_strong_bg) 88%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 70%,transparent))!important;
	border-color:color-mix(in srgb,var(--dp-glass_border) 76%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-title h2{
	color:var(--dp-text,#0f172a)!important;
	text-shadow:0 1px 0 color-mix(in srgb,var(--dp-surface) 36%,transparent);
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-title p{
	color:var(--dp-text_muted,#475569)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-body{
	background:color-mix(in srgb,var(--dp-glass_surface_muted_bg) 62%,transparent)!important;
}
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-modal,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-modal-loading{
	background:#fff!important;
}
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-modal-header,
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-modal-body{
	background:#fff!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-close{
	background:color-mix(in srgb,var(--dp-glass_control_bg) 88%,var(--dp-surface) 12%)!important;
	color:var(--dp-text,#0f172a)!important;
	border:1px solid color-mix(in srgb,var(--dp-glass_border) 78%,transparent)!important;
	box-shadow:0 10px 26px rgba(15,23,42,.10),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-close:hover{
	background:color-mix(in srgb,var(--dp-danger-600,#dc2626) 12%,var(--dp-glass_control_bg))!important;
	color:var(--dp-danger-700,#b42318)!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-commandbar .dp-panel-button-secondary,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-commandbar .dp-panel-action-neutral,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-commandbar .dp-panel-filter-trigger,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-commandbar .dp-panel-column-picker summary,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-filter-modal-panel>summary,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-filter-panel>summary,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-modal-close{
	color:#f8fafc!important;
	background:linear-gradient(135deg,rgba(51,65,85,.84),rgba(15,23,42,.64))!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-modal-close:hover{
	color:#fecaca!important;
	background:linear-gradient(135deg,rgba(127,29,29,.74),rgba(15,23,42,.64))!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-nav{
	border-color:color-mix(in srgb,var(--dp-glass_border) 82%,var(--dp-primary-600,#2563eb) 8%)!important;
	background:radial-gradient(circle at 18% 0,color-mix(in srgb,var(--dp-glass-primary) 12%,transparent),transparent 15rem),linear-gradient(180deg,color-mix(in srgb,var(--dp-glass_surface_strong_bg) 90%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 72%,transparent))!important;
	box-shadow:0 22px 70px color-mix(in srgb,var(--dp-glass-primary) 9%,rgba(15,23,42,.12)),var(--dp-glass_edge)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-brand,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-context,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-search input{
	border-color:color-mix(in srgb,var(--dp-glass_border) 76%,var(--dp-primary-600,#2563eb) 8%)!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_control_bg) 84%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 60%,transparent))!important;
	box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 20%,transparent),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle]{
	border-color:color-mix(in srgb,var(--dp-glass_border) 76%,transparent)!important;
	background:color-mix(in srgb,var(--dp-glass_control_bg) 82%,transparent)!important;
	box-shadow:var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-group{
	border-top-color:color-mix(in srgb,var(--dp-glass_border) 62%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-group h2,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button{
	color:color-mix(in srgb,var(--dp-text_muted,#64748b) 86%,var(--dp-text) 14%)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button:hover{
	color:var(--dp-text,#0f172a)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-link,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-link,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-group>summary,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-submenu>summary{
	border-color:color-mix(in srgb,var(--dp-glass_border) 42%,transparent)!important;
	background:color-mix(in srgb,var(--dp-glass_control_bg) 30%,transparent)!important;
	box-shadow:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-link:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-link:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-group>summary:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-submenu>summary:hover{
	border-color:color-mix(in srgb,var(--dp-glass_border) 76%,var(--dp-primary-600,#2563eb) 12%)!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_control_bg) 78%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 52%,transparent))!important;
	box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-link.active,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-submenu.active>summary,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-link.active,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-submenu.active>summary{
	border-color:color-mix(in srgb,#fff 24%,transparent)!important;
	background:linear-gradient(135deg,var(--dp-primary-600,#2563eb),color-mix(in srgb,var(--dp-primary-600,#2563eb) 70%,var(--dp-info-600,#0891b2)))!important;
	color:#fff!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-icon,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-icon{
	background:color-mix(in srgb,var(--dp-glass_control_bg) 78%,var(--dp-primary-600,#2563eb) 8%)!important;
	border:1px solid color-mix(in srgb,var(--dp-glass_border) 70%,transparent)!important;
	box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 18%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-icon,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-submenu.active>summary .dp-panel-sidebar-icon,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-link.active .dp-panel-horizontal-icon{
	background:rgba(255,255,255,.22)!important;
	color:#fff!important;
	border-color:rgba(255,255,255,.22)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-sidebar-badge,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-badge,
body[data-dp-theme-effects~="glass"] .dp-panel-badge,
body[data-dp-theme-effects~="glass"] .dp-panel-filter-chip,
body[data-dp-theme-effects~="glass"] .dp-panel-table-counts span,
body[data-dp-theme-effects~="glass"] .dp-panel-page-disabled{
	color:var(--dp-text,#0f172a)!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_control_bg) 88%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 56%,transparent))!important;
	border-color:color-mix(in srgb,var(--dp-glass_border) 72%,transparent)!important;
	box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 18%,transparent),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-filter-chips{
	border:1px solid color-mix(in srgb,var(--dp-glass_border) 60%,transparent)!important;
	border-radius:18px!important;
	background:color-mix(in srgb,var(--dp-glass_surface_muted_bg) 52%,transparent)!important;
	padding:10px!important;
	box-shadow:var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-live-control,
body[data-dp-theme-effects~="glass"] .dp-panel-theme-toggle,
body[data-dp-theme-effects~="glass"] .dp-panel-theme-select,
body[data-dp-theme-effects~="glass"] .dp-panel-theme-preset{
	border-color:color-mix(in srgb,var(--dp-glass_border) 76%,transparent)!important;
	background:color-mix(in srgb,var(--dp-glass_surface_muted_bg) 66%,transparent)!important;
	box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-theme-toggle button,
body[data-dp-theme-effects~="glass"] .dp-panel-theme-preset button,
body[data-dp-theme-effects~="glass"] .dp-panel-live-control button{
	color:var(--dp-text_muted,#64748b)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-theme-toggle button[aria-pressed=true],
body[data-dp-theme-effects~="glass"] .dp-panel-theme-preset button[aria-pressed=true],
body[data-dp-theme-effects~="glass"] .dp-panel-live-control button[aria-pressed=true]{
	background:linear-gradient(135deg,var(--dp-primary-600,#2563eb),color-mix(in srgb,var(--dp-primary-600,#2563eb) 72%,var(--dp-info-600,#0891b2)))!important;
	color:#fff!important;
	box-shadow:0 10px 24px color-mix(in srgb,var(--dp-primary-600,#2563eb) 24%,transparent),inset 0 1px 0 rgba(255,255,255,.24)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-theme-select select{
	background:color-mix(in srgb,var(--dp-glass_control_bg) 86%,var(--dp-surface) 14%)!important;
	color:var(--dp-text,#0f172a)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-pagination{
	border-radius:18px!important;
	padding:8px 10px!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-pagination .dp-panel-button,
body[data-dp-theme-effects~="glass"] .dp-panel-pagination .dp-panel-page-disabled{
	min-height:36px!important;
	border-radius:12px!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-sidebar-badge,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-nav-badge,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-badge,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-filter-chip,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-table-counts span,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-page-disabled{
	color:#f8fafc!important;
	background:linear-gradient(135deg,rgba(51,65,85,.80),rgba(15,23,42,.58))!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-brand,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-context,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-search input,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-theme-select select{
	color:#f8fafc!important;
	background:linear-gradient(135deg,rgba(51,65,85,.82),rgba(15,23,42,.62))!important;
}
@media(prefers-color-scheme:dark){
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-sidebar-badge,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-nav-badge,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-badge,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-filter-chip,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-table-counts span,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-page-disabled{
	color:#f8fafc!important;
	background:linear-gradient(135deg,rgba(51,65,85,.80),rgba(15,23,42,.58))!important;
}
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-brand,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-context,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-search input,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-theme-select select{
	color:#f8fafc!important;
	background:linear-gradient(135deg,rgba(51,65,85,.82),rgba(15,23,42,.62))!important;
}
}
body[data-dp-theme-effects~="glass"] .dp-panel-search input,
body[data-dp-theme-effects~="glass"] .dp-panel-global-search input,
body[data-dp-theme-effects~="glass"] .dp-panel-filter input,
body[data-dp-theme-effects~="glass"] .dp-panel-filter select,
body[data-dp-theme-effects~="glass"] .dp-panel-per-page select,
body[data-dp-theme-effects~="glass"] .dp-panel-field input,
body[data-dp-theme-effects~="glass"] .dp-panel-field select,
body[data-dp-theme-effects~="glass"] .dp-panel-field textarea,
body[data-dp-theme-effects~="glass"] .dp-panel-message-form input,
body[data-dp-theme-effects~="glass"] .dp-panel-message-form select,
body[data-dp-theme-effects~="glass"] .dp-panel-message-form textarea,
body[data-dp-theme-effects~="glass"] .dp-panel-attachment-form input[type=file]{
	border-color:color-mix(in srgb,var(--dp-glass_border) 78%,var(--dp-text) 8%)!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_control_bg) 88%,var(--dp-surface) 12%),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 54%,var(--dp-surface) 18%))!important;
	color:var(--dp-text,#0f172a)!important;
	box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 26%,transparent),0 10px 24px rgba(15,23,42,.045)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-search input:focus,
body[data-dp-theme-effects~="glass"] .dp-panel-global-search input:focus,
body[data-dp-theme-effects~="glass"] .dp-panel-filter input:focus,
body[data-dp-theme-effects~="glass"] .dp-panel-filter select:focus,
body[data-dp-theme-effects~="glass"] .dp-panel-per-page select:focus,
body[data-dp-theme-effects~="glass"] .dp-panel-field input:focus,
body[data-dp-theme-effects~="glass"] .dp-panel-field select:focus,
body[data-dp-theme-effects~="glass"] .dp-panel-field textarea:focus,
body[data-dp-theme-effects~="glass"] .dp-panel-message-form input:focus,
body[data-dp-theme-effects~="glass"] .dp-panel-message-form select:focus,
body[data-dp-theme-effects~="glass"] .dp-panel-message-form textarea:focus{
	border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 58%,var(--dp-glass_border))!important;
	background:color-mix(in srgb,var(--dp-glass_control_bg) 92%,var(--dp-surface) 8%)!important;
	box-shadow:var(--dp-glass_focus),inset 0 1px 0 color-mix(in srgb,#fff 28%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-checkbox,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker label,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu label{
	border-radius:12px!important;
}
body[data-dp-theme-effects~="glass"] input[type=checkbox],
body[data-dp-theme-effects~="glass"] input[type=radio]{
	accent-color:var(--dp-primary-600,#2563eb)!important;
	filter:drop-shadow(0 3px 8px color-mix(in srgb,var(--dp-primary-600,#2563eb) 16%,transparent));
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker form,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu>div{
	padding:8px!important;
	border-radius:18px!important;
	overflow:hidden!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu a,
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu button,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu a,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu button,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu a,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu button,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker label{
	min-height:36px!important;
	border-radius:12px!important;
	margin:0!important;
	padding:8px 10px!important;
	gap:8px!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu a:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu button:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu a:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu button:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu a:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu button:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker label:hover{
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-primary-600,#2563eb) 12%,var(--dp-glass_control_bg)),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 78%,transparent))!important;
	box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 18%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table th,
body[data-dp-theme-effects~="glass"] .dp-panel-table td{
	border-bottom-color:color-mix(in srgb,var(--dp-glass_border) 58%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table th+th,
body[data-dp-theme-effects~="glass"] .dp-panel-table td+td{
	border-left:1px solid color-mix(in srgb,var(--dp-glass_border) 22%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table th:first-child,
body[data-dp-theme-effects~="glass"] .dp-panel-table td:first-child{
	border-left:0!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-sort{
	display:inline-flex!important;
	align-items:center!important;
	gap:5px!important;
	min-height:24px!important;
	border-radius:999px!important;
	color:var(--dp-text_muted,#475569)!important;
	text-decoration:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-sort:hover{
	color:var(--dp-primary-700,#175cd3)!important;
	background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 9%,transparent)!important;
	padding-inline:7px!important;
	margin-inline:-7px!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-show-field,
body[data-dp-theme-effects~="glass"] .dp-panel-form-section,
body[data-dp-theme-effects~="glass"] .dp-panel-form-details,
body[data-dp-theme-effects~="glass"] .dp-panel-message,
body[data-dp-theme-effects~="glass"] .dp-panel-attachment,
body[data-dp-theme-effects~="glass"] .dp-panel-shortcut-group,
body[data-dp-theme-effects~="glass"] .dp-panel-row-preview dl div{
	border-color:color-mix(in srgb,var(--dp-glass_border) 76%,transparent)!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_surface_bg) 84%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 56%,transparent))!important;
	box-shadow:var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-notice,
body[data-dp-theme-effects~="glass"] .dp-panel-alert,
body[data-dp-theme-effects~="glass"] .dp-panel-empty{
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-search input,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-global-search input,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-filter input,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-filter select,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-per-page select,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-field input,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-field select,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-field textarea,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-message-form input,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-message-form select,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-message-form textarea,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-attachment-form input[type=file]{
	color:#f8fafc!important;
	background:linear-gradient(135deg,rgba(51,65,85,.84),rgba(15,23,42,.62))!important;
	border-color:rgba(148,163,184,.34)!important;
}
@media(prefers-color-scheme:dark){
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-search input,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-global-search input,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-filter input,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-filter select,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-per-page select,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-field input,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-field select,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-field textarea,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-message-form input,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-message-form select,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-message-form textarea,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-attachment-form input[type=file]{
	color:#f8fafc!important;
	background:linear-gradient(135deg,rgba(51,65,85,.84),rgba(15,23,42,.62))!important;
	border-color:rgba(148,163,184,.34)!important;
}
}
body[data-dp-theme-effects~="glass"] .dp-panel-chart,
body[data-dp-theme-effects~="glass"] .dp-panel-chart-card,
body[data-dp-theme-effects~="glass"] .dp-panel-board-column,
body[data-dp-theme-effects~="glass"] .dp-panel-summary,
body[data-dp-theme-effects~="glass"] .dp-panel-insight,
body[data-dp-theme-effects~="glass"] .dp-panel-alert-card{
	position:relative!important;
	overflow:hidden!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-chart:after,
body[data-dp-theme-effects~="glass"] .dp-panel-chart-card:after,
body[data-dp-theme-effects~="glass"] .dp-panel-board-column:after,
body[data-dp-theme-effects~="glass"] .dp-panel-summary:after,
body[data-dp-theme-effects~="glass"] .dp-panel-insight:after,
body[data-dp-theme-effects~="glass"] .dp-panel-alert-card:after{
	content:""!important;
	position:absolute!important;
	inset:0!important;
	pointer-events:none!important;
	border-radius:inherit!important;
	background:linear-gradient(135deg,rgba(255,255,255,.24),transparent 32%,rgba(255,255,255,.08) 64%,transparent)!important;
	opacity:.58!important;
	mix-blend-mode:soft-light!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-chart>*,
body[data-dp-theme-effects~="glass"] .dp-panel-chart-card>*,
body[data-dp-theme-effects~="glass"] .dp-panel-board-column>*,
body[data-dp-theme-effects~="glass"] .dp-panel-summary>*,
body[data-dp-theme-effects~="glass"] .dp-panel-insight>*,
body[data-dp-theme-effects~="glass"] .dp-panel-alert-card>*{
	position:relative!important;
	z-index:1!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-chart,
body[data-dp-theme-effects~="glass"] .dp-panel-chart-card{
	border:1px solid color-mix(in srgb,var(--dp-glass_border) 76%,var(--dp-info-600,#0891b2) 10%)!important;
	border-radius:20px!important;
	background:radial-gradient(circle at 12% 0,color-mix(in srgb,var(--dp-info-600,#0891b2) 13%,transparent),transparent 16rem),linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_surface_bg) 86%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 62%,transparent))!important;
	box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-chart svg{
	isolation:isolate!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-chart text,
body[data-dp-theme-effects~="glass"] .dp-panel-chart-label,
body[data-dp-theme-effects~="glass"] .dp-panel-chart-legend{
	fill:var(--dp-text_muted,#475569)!important;
	color:var(--dp-text_muted,#475569)!important;
	text-shadow:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-chart-axis,
body[data-dp-theme-effects~="glass"] .dp-panel-chart-line-muted{
	stroke:color-mix(in srgb,var(--dp-glass_border) 72%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-board{
	gap:14px!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-board-column{
	border-radius:20px!important;
	background:linear-gradient(180deg,color-mix(in srgb,var(--dp-glass_surface_bg) 78%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 66%,transparent))!important;
	box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-board-column>header{
	border-bottom:1px solid color-mix(in srgb,var(--dp-glass_border) 62%,transparent)!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_control_bg) 64%,transparent),transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-board-column>header h2,
body[data-dp-theme-effects~="glass"] .dp-panel-board-column>header strong{
	color:var(--dp-text,#0f172a)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-board-card{
	border-color:color-mix(in srgb,var(--dp-glass_border) 74%,transparent)!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_control_bg) 72%,var(--dp-surface) 12%),color-mix(in srgb,var(--dp-glass_surface_bg) 58%,transparent))!important;
	box-shadow:0 10px 24px rgba(15,23,42,.07),var(--dp-glass_edge)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-board-card:hover{
	border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 30%,var(--dp-glass_border))!important;
	box-shadow:0 18px 38px color-mix(in srgb,var(--dp-primary-600,#2563eb) 12%,rgba(15,23,42,.08)),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-board-card:has(.dp-panel-row-more[open]){
	backdrop-filter:none!important;
	-webkit-backdrop-filter:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-summary,
body[data-dp-theme-effects~="glass"] .dp-panel-insight{
	border-radius:18px!important;
	padding:14px!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-summary span,
body[data-dp-theme-effects~="glass"] .dp-panel-insight span,
body[data-dp-theme-effects~="glass"] .dp-panel-alert-card span{
	color:var(--dp-text_muted,#475569)!important;
	letter-spacing:.06em!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-summary strong,
body[data-dp-theme-effects~="glass"] .dp-panel-insight strong{
	letter-spacing:0!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-status{
	margin:0 0 12px!important;
	border:1px solid color-mix(in srgb,var(--dp-glass_border) 76%,transparent)!important;
	border-radius:14px!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_control_bg) 78%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 58%,transparent))!important;
	color:var(--dp-text,#0f172a)!important;
	box-shadow:var(--dp-glass_edge)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-status-success{border-color:color-mix(in srgb,var(--dp-success-600,#16a34a) 36%,var(--dp-glass_border))!important;background:linear-gradient(135deg,color-mix(in srgb,var(--dp-success-600,#16a34a) 14%,transparent),var(--dp-glass_surface_muted_bg))!important}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-status-warning{border-color:color-mix(in srgb,var(--dp-warning-600,#d97706) 36%,var(--dp-glass_border))!important;background:linear-gradient(135deg,color-mix(in srgb,var(--dp-warning-600,#d97706) 14%,transparent),var(--dp-glass_surface_muted_bg))!important}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-status-error{border-color:color-mix(in srgb,var(--dp-danger-600,#dc2626) 36%,var(--dp-glass_border))!important;background:linear-gradient(135deg,color-mix(in srgb,var(--dp-danger-600,#dc2626) 14%,transparent),var(--dp-glass_surface_muted_bg))!important}
body[data-dp-theme-effects~="glass"] .dp-panel-record-pulse,
body[data-dp-theme-effects~="glass"] .dp-panel-table-pulse,
body[data-dp-theme-effects~="glass"] .dp-panel-board-pulse,
body[data-dp-theme-effects~="glass"] .dp-panel-form-pulse{
	border-color:color-mix(in srgb,var(--dp-glass_border) 76%,transparent)!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_surface_bg) 74%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 58%,transparent))!important;
	box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-record-pulse-grid>*,
body[data-dp-theme-effects~="glass"] .dp-panel-table-pulse-grid>*,
body[data-dp-theme-effects~="glass"] .dp-panel-board-pulse-grid>*,
body[data-dp-theme-effects~="glass"] .dp-panel-form-pulse-grid>*{
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_control_bg) 72%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 58%,transparent))!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-chart text,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-chart-label,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-chart-legend,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-summary span,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-insight span,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-alert-card span{
	color:#cbd5e1!important;
	fill:#cbd5e1!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-board-column>header h2,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-board-column>header strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-modal-status{
	color:#f8fafc!important;
}
@media(prefers-color-scheme:dark){
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-chart text,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-chart-label,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-chart-legend,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-summary span,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-insight span,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-alert-card span{
	color:#cbd5e1!important;
	fill:#cbd5e1!important;
}
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-board-column>header h2,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-board-column>header strong,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-modal-status{
	color:#f8fafc!important;
}
}
body[data-dp-theme-effects~="glass"] .dp-panel-button,
body[data-dp-theme-effects~="glass"] .dp-panel-action,
body[data-dp-theme-effects~="glass"] .dp-panel-row-link,
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta-action,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu>summary,
body[data-dp-theme-effects~="glass"] .dp-panel-filter-trigger,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker summary{
	position:relative!important;
	overflow:hidden!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-button:after,
body[data-dp-theme-effects~="glass"] .dp-panel-action:after,
body[data-dp-theme-effects~="glass"] .dp-panel-row-link:after,
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta-action:after,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu>summary:after,
body[data-dp-theme-effects~="glass"] .dp-panel-filter-trigger:after,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker summary:after{
	content:""!important;
	position:absolute!important;
	inset:0!important;
	pointer-events:none!important;
	background:linear-gradient(135deg,rgba(255,255,255,.28),transparent 38%,rgba(255,255,255,.08))!important;
	opacity:.44!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-button>*,
body[data-dp-theme-effects~="glass"] .dp-panel-action>*,
body[data-dp-theme-effects~="glass"] .dp-panel-row-link>*,
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta-action>*,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu>summary>*,
body[data-dp-theme-effects~="glass"] .dp-panel-filter-trigger>*,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker summary>*{
	position:relative!important;
	z-index:1!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-button:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-action:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-row-link:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta-action:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu>summary:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-filter-trigger:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker summary:hover{
	border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 30%,var(--dp-glass_border))!important;
	filter:saturate(1.04)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-button:active,
body[data-dp-theme-effects~="glass"] .dp-panel-action:active,
body[data-dp-theme-effects~="glass"] .dp-panel-row-link:active,
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta-action:active,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu>summary:active,
body[data-dp-theme-effects~="glass"] .dp-panel-filter-trigger:active,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker summary:active{
	transform:translateY(0)!important;
	box-shadow:inset 0 2px 8px rgba(15,23,42,.12),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] button:disabled,
body[data-dp-theme-effects~="glass"] .dp-panel-button[aria-disabled=true],
body[data-dp-theme-effects~="glass"] .dp-panel-action[aria-disabled=true],
body[data-dp-theme-effects~="glass"] .dp-panel-page-disabled,
body[data-dp-theme-effects~="glass"] [data-dp-panel-step-disabled="1"]{
	cursor:not-allowed!important;
	opacity:.58!important;
	filter:saturate(.72)!important;
	box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 14%,transparent),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-loading{
	color:transparent!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-loading:before{
	content:""!important;
	position:absolute!important;
	left:50%!important;
	top:50%!important;
	z-index:2!important;
	width:16px!important;
	height:16px!important;
	margin:-8px 0 0 -8px!important;
	border:2px solid rgba(255,255,255,.45)!important;
	border-top-color:#fff!important;
	border-radius:999px!important;
	animation:dp-panel-glass-spin .75s linear infinite!important;
}
@keyframes dp-panel-glass-spin{to{transform:rotate(360deg)}}
body[data-dp-theme-effects~="glass"] .dp-panel-density,
body[data-dp-theme-effects~="glass"] .dp-panel-table-groups,
body[data-dp-theme-effects~="glass"] .dp-panel-tab-list,
body[data-dp-theme-effects~="glass"] .dp-panel-step-list{
	isolation:isolate!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-density a,
body[data-dp-theme-effects~="glass"] .dp-panel-table-group,
body[data-dp-theme-effects~="glass"] .dp-panel-table-group-link,
body[data-dp-theme-effects~="glass"] .dp-panel-tab-list button,
body[data-dp-theme-effects~="glass"] .dp-panel-step-list button{
	min-height:34px!important;
	border-radius:999px!important;
	transition:background .14s ease,color .14s ease,box-shadow .14s ease,transform .14s ease!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-density a:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-table-group:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-table-group-link:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-tab-list button:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-step-list button:hover{
	background:color-mix(in srgb,var(--dp-glass_control_bg) 76%,transparent)!important;
	color:var(--dp-text,#0f172a)!important;
	box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 18%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-density a.active,
body[data-dp-theme-effects~="glass"] .dp-panel-table-group.active,
body[data-dp-theme-effects~="glass"] .dp-panel-table-group-link.active{
	background:linear-gradient(135deg,var(--dp-primary-600,#2563eb),color-mix(in srgb,var(--dp-primary-600,#2563eb) 72%,var(--dp-info-600,#0891b2)))!important;
	color:#fff!important;
	box-shadow:0 12px 26px color-mix(in srgb,var(--dp-primary-600,#2563eb) 24%,transparent),inset 0 1px 0 rgba(255,255,255,.24)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:has(input[type=checkbox]:checked) td,
body[data-dp-theme-effects~="glass"] .dp-panel-row-selected td{
	border-bottom-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 24%,var(--dp-glass_border))!important;
	box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 16%,transparent),inset 3px 0 0 var(--dp-primary-600,#2563eb)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-board-drop,
body[data-dp-theme-effects~="glass"] .dp-panel-board-drop-blocked{
	position:relative!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-board-drop:before,
body[data-dp-theme-effects~="glass"] .dp-panel-board-drop-blocked:before{
	content:""!important;
	position:absolute!important;
	inset:8px!important;
	z-index:0!important;
	border-radius:16px!important;
	border:1px dashed currentColor!important;
	opacity:.48!important;
	pointer-events:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-board-drop:before{color:var(--dp-success-600,#16a34a)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-board-drop-blocked:before{color:var(--dp-danger-600,#dc2626)!important}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-density a:hover,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-table-group:hover,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-table-group-link:hover,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-tab-list button:hover,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-step-list button:hover{
	color:#f8fafc!important;
	background:rgba(51,65,85,.72)!important;
}
@media(prefers-color-scheme:dark){
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-density a:hover,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-table-group:hover,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-table-group-link:hover,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-tab-list button:hover,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-step-list button:hover{
	color:#f8fafc!important;
	background:rgba(51,65,85,.72)!important;
}
}
body[data-dp-theme-effects~="glass"] .dp-panel-links,
body[data-dp-theme-effects~="glass"] .dp-panel-contacts,
body[data-dp-theme-effects~="glass"] .dp-panel-locations,
body[data-dp-theme-effects~="glass"] .dp-panel-tags,
body[data-dp-theme-effects~="glass"] .dp-panel-items,
body[data-dp-theme-effects~="glass"] .dp-panel-totals,
body[data-dp-theme-effects~="glass"] .dp-panel-payments,
body[data-dp-theme-effects~="glass"] .dp-panel-shipments,
body[data-dp-theme-effects~="glass"] .dp-panel-attachments,
body[data-dp-theme-effects~="glass"] .dp-panel-messages,
body[data-dp-theme-effects~="glass"] .dp-panel-notes,
body[data-dp-theme-effects~="glass"] .dp-panel-tasks,
body[data-dp-theme-effects~="glass"] .dp-panel-activity,
body[data-dp-theme-effects~="glass"] .dp-panel-changes,
body[data-dp-theme-effects~="glass"] .dp-panel-relation{
	position:relative!important;
	overflow:hidden!important;
	border-color:color-mix(in srgb,var(--dp-glass_border) 78%,var(--dp-info-600,#0891b2) 8%)!important;
	border-radius:20px!important;
	background:radial-gradient(circle at 10% 0,color-mix(in srgb,var(--dp-info-600,#0891b2) 9%,transparent),transparent 18rem),linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_surface_bg) 84%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 64%,transparent))!important;
	box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-links>header,
body[data-dp-theme-effects~="glass"] .dp-panel-contacts>header,
body[data-dp-theme-effects~="glass"] .dp-panel-locations>header,
body[data-dp-theme-effects~="glass"] .dp-panel-tags>header,
body[data-dp-theme-effects~="glass"] .dp-panel-items>header,
body[data-dp-theme-effects~="glass"] .dp-panel-totals>header,
body[data-dp-theme-effects~="glass"] .dp-panel-payments>header,
body[data-dp-theme-effects~="glass"] .dp-panel-shipments>header,
body[data-dp-theme-effects~="glass"] .dp-panel-attachments>header,
body[data-dp-theme-effects~="glass"] .dp-panel-messages>header,
body[data-dp-theme-effects~="glass"] .dp-panel-notes>header,
body[data-dp-theme-effects~="glass"] .dp-panel-tasks>header,
body[data-dp-theme-effects~="glass"] .dp-panel-activity>header,
body[data-dp-theme-effects~="glass"] .dp-panel-changes>header,
body[data-dp-theme-effects~="glass"] .dp-panel-relation-header{
	border-bottom-color:color-mix(in srgb,var(--dp-glass_border) 64%,transparent)!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_control_bg) 62%,transparent),transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-link,
body[data-dp-theme-effects~="glass"] .dp-panel-contact,
body[data-dp-theme-effects~="glass"] .dp-panel-location,
body[data-dp-theme-effects~="glass"] .dp-panel-tag,
body[data-dp-theme-effects~="glass"] .dp-panel-item,
body[data-dp-theme-effects~="glass"] .dp-panel-total,
body[data-dp-theme-effects~="glass"] .dp-panel-payment,
body[data-dp-theme-effects~="glass"] .dp-panel-shipment,
body[data-dp-theme-effects~="glass"] .dp-panel-attachment,
body[data-dp-theme-effects~="glass"] .dp-panel-message,
body[data-dp-theme-effects~="glass"] .dp-panel-note,
body[data-dp-theme-effects~="glass"] .dp-panel-task,
body[data-dp-theme-effects~="glass"] .dp-panel-activity-item,
body[data-dp-theme-effects~="glass"] .dp-panel-change,
body[data-dp-theme-effects~="glass"] .dp-panel-relation-aside{
	border-color:color-mix(in srgb,var(--dp-glass_border) 74%,transparent)!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_control_bg) 74%,var(--dp-surface) 10%),color-mix(in srgb,var(--dp-glass_surface_bg) 56%,transparent))!important;
	box-shadow:0 10px 24px rgba(15,23,42,.055),var(--dp-glass_edge)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-link:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-contact:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-location:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-attachment:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-message:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-note:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-task:hover{
	border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 26%,var(--dp-glass_border))!important;
	box-shadow:0 14px 32px color-mix(in srgb,var(--dp-primary-600,#2563eb) 10%,rgba(15,23,42,.06)),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-link strong,
body[data-dp-theme-effects~="glass"] .dp-panel-contact strong,
body[data-dp-theme-effects~="glass"] .dp-panel-contact a,
body[data-dp-theme-effects~="glass"] .dp-panel-location strong,
body[data-dp-theme-effects~="glass"] .dp-panel-attachment strong,
body[data-dp-theme-effects~="glass"] .dp-panel-attachment a,
body[data-dp-theme-effects~="glass"] .dp-panel-message strong,
body[data-dp-theme-effects~="glass"] .dp-panel-note strong,
body[data-dp-theme-effects~="glass"] .dp-panel-task strong,
body[data-dp-theme-effects~="glass"] .dp-panel-change strong{
	color:var(--dp-text,#0f172a)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-link span,
body[data-dp-theme-effects~="glass"] .dp-panel-contact small,
body[data-dp-theme-effects~="glass"] .dp-panel-contact-details,
body[data-dp-theme-effects~="glass"] .dp-panel-location small,
body[data-dp-theme-effects~="glass"] .dp-panel-attachment small,
body[data-dp-theme-effects~="glass"] .dp-panel-message small,
body[data-dp-theme-effects~="glass"] .dp-panel-note small,
body[data-dp-theme-effects~="glass"] .dp-panel-task small,
body[data-dp-theme-effects~="glass"] .dp-panel-change small{
	color:var(--dp-text_muted,#475569)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-message-form,
body[data-dp-theme-effects~="glass"] .dp-panel-attachment-form{
	border-top-color:color-mix(in srgb,var(--dp-glass_border) 68%,transparent)!important;
	background:color-mix(in srgb,var(--dp-glass_surface_muted_bg) 62%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-body[data-dp-panel-modal-content="confirmation"]{
	padding:clamp(16px,2.2vw,24px)!important;
	background:linear-gradient(180deg,color-mix(in srgb,var(--dp-glass_surface_muted_bg) 74%,transparent),color-mix(in srgb,var(--dp-glass_surface_bg) 76%,transparent))!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-confirmation{
	border:1px solid color-mix(in srgb,var(--dp-glass_border) 76%,transparent)!important;
	border-radius:20px!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_surface_bg) 88%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 64%,transparent))!important;
	box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;
	padding:clamp(18px,2vw,24px)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-confirmation .dp-panel-modal-actions{
	padding-top:clamp(14px,1.7vw,20px)!important;
	margin-top:clamp(8px,1.2vw,12px)!important;
	border-top-color:color-mix(in srgb,var(--dp-glass_border) 78%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-confirmation-icon{
	background:color-mix(in srgb,var(--dp-glass-tone,var(--dp-primary-600,#2563eb)) 18%,var(--dp-glass_control_bg))!important;
	border:1px solid color-mix(in srgb,var(--dp-glass-tone,var(--dp-primary-600,#2563eb)) 28%,var(--dp-glass_border))!important;
	box-shadow:var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-confirmation-copy strong{
	color:var(--dp-text,#0f172a)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-confirmation-copy p{
	color:var(--dp-text_muted,#475569)!important;
}
@media(max-width:685px){body[data-dp-theme-effects~="glass"] .dp-panel-modal-body[data-dp-panel-modal-content="confirmation"]{padding:12px!important}body[data-dp-theme-effects~="glass"] .dp-panel-modal-confirmation{padding:16px!important}}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-link strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-contact strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-contact a,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-location strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-attachment strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-attachment a,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-message strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-note strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-task strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-change strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-modal-confirmation-copy strong{
	color:#f8fafc!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-link span,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-contact small,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-contact-details,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-location small,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-attachment small,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-message small,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-note small,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-task small,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-change small,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-modal-confirmation-copy p{
	color:#cbd5e1!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-main-region>header h1,
body[data-dp-theme-effects~="glass"] .dp-panel-modal-title h2,
body[data-dp-theme-effects~="glass"] .dp-panel-section-heading h2,
body[data-dp-theme-effects~="glass"] .dp-panel-record-heading h2,
body[data-dp-theme-effects~="glass"] .dp-panel-card h2,
body[data-dp-theme-effects~="glass"] .dp-panel-custom-page h2{
	color:var(--dp-text,#0f172a)!important;
	text-shadow:0 1px 0 color-mix(in srgb,var(--dp-surface) 34%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-main-region>header p,
body[data-dp-theme-effects~="glass"] .dp-panel-section-heading p,
body[data-dp-theme-effects~="glass"] .dp-panel-record-heading p,
body[data-dp-theme-effects~="glass"] .dp-panel-card p,
body[data-dp-theme-effects~="glass"] .dp-panel-custom-page p{
	color:var(--dp-text_muted,#475569)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-section-heading,
body[data-dp-theme-effects~="glass"] .dp-panel-record-heading,
body[data-dp-theme-effects~="glass"] .dp-panel-page-table>header,
body[data-dp-theme-effects~="glass"] .dp-panel-form-section>.dp-panel-section-heading{
	border-bottom-color:color-mix(in srgb,var(--dp-glass_border) 58%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-card,
body[data-dp-theme-effects~="glass"] .dp-panel-custom-page>section,
body[data-dp-theme-effects~="glass"] .dp-panel-custom-page>article,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-card,
body[data-dp-theme-effects~="glass"] .dp-panel-search-result{
	position:relative!important;
	overflow:hidden!important;
	border-color:color-mix(in srgb,var(--dp-glass_border) 78%,transparent)!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_surface_bg) 84%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 58%,transparent))!important;
	box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-card:before,
body[data-dp-theme-effects~="glass"] .dp-panel-custom-page>section:before,
body[data-dp-theme-effects~="glass"] .dp-panel-custom-page>article:before,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-card:before,
body[data-dp-theme-effects~="glass"] .dp-panel-search-result:before{
	content:""!important;
	position:absolute!important;
	inset:0!important;
	pointer-events:none!important;
	border-radius:inherit!important;
	background:linear-gradient(135deg,rgba(255,255,255,.22),transparent 34%,rgba(255,255,255,.06))!important;
	opacity:.58!important;
	mix-blend-mode:soft-light!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-card>*,
body[data-dp-theme-effects~="glass"] .dp-panel-custom-page>section>*,
body[data-dp-theme-effects~="glass"] .dp-panel-custom-page>article>*,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-card>*,
body[data-dp-theme-effects~="glass"] .dp-panel-search-result>*{
	position:relative!important;
	z-index:1!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-card:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-search-result:hover{
	border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 28%,var(--dp-glass_border))!important;
	box-shadow:0 18px 42px color-mix(in srgb,var(--dp-primary-600,#2563eb) 10%,rgba(15,23,42,.08)),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-show{
	border-color:color-mix(in srgb,var(--dp-glass_border) 78%,var(--dp-primary-600,#2563eb) 8%)!important;
	background:radial-gradient(circle at 16% 0,color-mix(in srgb,var(--dp-primary-600,#2563eb) 9%,transparent),transparent 18rem),linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_surface_bg) 86%,transparent),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 58%,transparent))!important;
	box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-show-field{
	min-height:84px!important;
	align-content:start!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-show-field span,
body[data-dp-theme-effects~="glass"] .dp-panel-field span,
body[data-dp-theme-effects~="glass"] .dp-panel-filter span,
body[data-dp-theme-effects~="glass"] .dp-panel-table th,
body[data-dp-theme-effects~="glass"] .dp-panel-table td:before{
	color:color-mix(in srgb,var(--dp-text_muted,#64748b) 88%,var(--dp-text) 12%)!important;
	font-weight:900!important;
	letter-spacing:.055em!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-show-field strong,
body[data-dp-theme-effects~="glass"] .dp-panel-cell-primary,
body[data-dp-theme-effects~="glass"] .dp-panel-cell-stack strong{
	font-weight:850!important;
	letter-spacing:0!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-cell-stack{
	gap:4px!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-cell-stack small{
	line-height:1.35!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table-counts,
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta-actions{
	gap:8px!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table-counts span strong,
body[data-dp-theme-effects~="glass"] .dp-panel-table-view small,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-card small{
	color:var(--dp-text,#0f172a)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-empty-state{
	min-height:190px!important;
	text-align:center!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-empty-state strong,
body[data-dp-theme-effects~="glass"] .dp-panel-empty-state h2,
body[data-dp-theme-effects~="glass"] .dp-panel-empty-state h3{
	color:var(--dp-text,#0f172a)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-empty-state span,
body[data-dp-theme-effects~="glass"] .dp-panel-empty-state p{
	color:var(--dp-text_muted,#475569)!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-main-region>header h1,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-modal-title h2,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-section-heading h2,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-record-heading h2,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-card h2,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-custom-page h2,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-empty-state strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-empty-state h2,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-empty-state h3,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-table-counts span strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-table-view small,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-nav-card small{
	color:#f8fafc!important;
	text-shadow:0 1px 0 rgba(0,0,0,.24)!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-main-region>header p,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-section-heading p,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-record-heading p,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-card p,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-custom-page p,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-empty-state span,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-empty-state p{
	color:#cbd5e1!important;
}
@media(prefers-color-scheme:dark){
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-main-region>header h1,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-modal-title h2,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-section-heading h2,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-record-heading h2,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-card h2,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-custom-page h2,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-empty-state strong,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-empty-state h2,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-empty-state h3,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-table-counts span strong,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-table-view small,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-nav-card small{
	color:#f8fafc!important;
	text-shadow:0 1px 0 rgba(0,0,0,.24)!important;
}
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-main-region>header p,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-section-heading p,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-record-heading p,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-card p,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-custom-page p,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-empty-state span,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-empty-state p{
	color:#cbd5e1!important;
}
}
body[data-dp-theme-effects~="glass"] .dp-panel-command-root,
body[data-dp-theme-effects~="glass"] .dp-panel-modal-root{
	backdrop-filter:blur(10px) saturate(1.08)!important;
	-webkit-backdrop-filter:blur(10px) saturate(1.08)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-root[data-dp-panel-modal-style="slide_over"]{
	background:rgba(15,23,42,.18)!important;
	backdrop-filter:none!important;
	-webkit-backdrop-filter:none!important;
}
body[data-dp-theme-mode="light"][data-dp-theme-effects~="glass"] .dp-panel-modal-root[data-dp-panel-modal-style="slide_over"]{
	background:rgba(15,23,42,.16)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-command{
	border-color:color-mix(in srgb,var(--dp-glass_border) 84%,var(--dp-primary-600,#2563eb) 12%)!important;
	background:radial-gradient(circle at 18% 0,color-mix(in srgb,var(--dp-primary-600,#2563eb) 12%,transparent),transparent 18rem),linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_surface_strong_bg) 90%,transparent),color-mix(in srgb,var(--dp-glass_surface_bg) 78%,transparent))!important;
	box-shadow:0 34px 110px rgba(15,23,42,.28),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-command input{
	border-color:color-mix(in srgb,var(--dp-glass_border) 78%,var(--dp-primary-600,#2563eb) 12%)!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass_control_bg) 90%,var(--dp-surface) 10%),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 58%,transparent))!important;
	box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 26%,transparent),0 12px 28px rgba(15,23,42,.07)!important;
	color:var(--dp-text,#0f172a)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-command-context,
body[data-dp-theme-effects~="glass"] .dp-panel-command-footer{
	color:var(--dp-text_muted,#475569)!important;
	border-color:color-mix(in srgb,var(--dp-glass_border) 62%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-command-list{
	border-radius:18px!important;
	background:color-mix(in srgb,var(--dp-glass_surface_muted_bg) 44%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-command-item{
	border-color:color-mix(in srgb,var(--dp-glass_border) 58%,transparent)!important;
	background:color-mix(in srgb,var(--dp-glass_control_bg) 28%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-command-item:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-command-item[aria-selected=true]{
	border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 30%,var(--dp-glass_border))!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-primary-600,#2563eb) 12%,var(--dp-glass_control_bg)),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 72%,transparent))!important;
	box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-toast-root{
	z-index:2147482500!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-toast{
	border-radius:18px!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-glass-tone,var(--dp-primary-600,#2563eb)) 12%,transparent),color-mix(in srgb,var(--dp-glass_menu_bg) 88%,var(--dp-surface) 12%))!important;
	box-shadow:0 20px 70px rgba(15,23,42,.24),var(--dp-glass_edge)!important;
	backdrop-filter:blur(22px) saturate(1.18)!important;
	-webkit-backdrop-filter:blur(22px) saturate(1.18)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-toast-copy strong{
	color:var(--dp-text,#0f172a)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-toast-copy span{
	color:var(--dp-text_muted,#475569)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-toast-progress{
	background:linear-gradient(90deg,var(--dp-glass-tone,var(--dp-primary-600,#2563eb)),color-mix(in srgb,var(--dp-glass-tone,var(--dp-primary-600,#2563eb)) 60%,#fff))!important;
	box-shadow:0 0 18px color-mix(in srgb,var(--dp-glass-tone,var(--dp-primary-600,#2563eb)) 34%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-body>.dp-panel-form>.dp-panel-toolbar:last-child,
body[data-dp-theme-effects~="glass"] .dp-panel-modal-body .dp-panel-form>.dp-panel-toolbar:last-child{
	border-top:1px solid color-mix(in srgb,var(--dp-glass_border) 70%,transparent)!important;
	background:linear-gradient(180deg,color-mix(in srgb,var(--dp-glass_surface_muted_bg) 54%,transparent),color-mix(in srgb,var(--dp-glass_surface_strong_bg) 86%,transparent))!important;
	box-shadow:0 -18px 44px rgba(15,23,42,.08),var(--dp-glass_edge)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-bulk-bar{
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-bulk-bar:after{
	content:""!important;
	position:absolute!important;
	inset:0!important;
	pointer-events:none!important;
	border-radius:inherit!important;
	background:linear-gradient(135deg,rgba(255,255,255,.20),transparent 40%,rgba(255,255,255,.06))!important;
	opacity:.58!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-bulk-bar>*{
	position:relative!important;
	z-index:1!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-row-more[open]>summary,
body[data-dp-theme-effects~="glass"] .dp-panel-action-group[open]>summary,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker[open]>summary,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu[open]>summary{
	border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 34%,var(--dp-glass_border))!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-primary-600,#2563eb) 10%,var(--dp-glass_control_bg)),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 70%,transparent))!important;
	box-shadow:var(--dp-glass_active_glow),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu header,
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu header,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu header{
	border-bottom:1px solid color-mix(in srgb,var(--dp-glass_border) 64%,transparent)!important;
	background:color-mix(in srgb,var(--dp-glass_surface_muted_bg) 58%,transparent)!important;
	color:var(--dp-text_muted,#475569)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-ajax-loading:after{
	background:linear-gradient(90deg,var(--dp-primary-600,#2563eb),var(--dp-info-600,#0891b2),var(--dp-success-600,#16a34a))!important;
	box-shadow:0 0 22px color-mix(in srgb,var(--dp-info-600,#0891b2) 42%,transparent)!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-command input,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-toast-copy strong{
	color:#f8fafc!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-command-context,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-command-footer,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-toast-copy span,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu header,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-action-menu header,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu header{
	color:#cbd5e1!important;
}
@media(prefers-color-scheme:dark){
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-command input,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-toast-copy strong{
	color:#f8fafc!important;
}
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-command-context,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-command-footer,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-toast-copy span,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-row-more-menu header,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-action-menu header,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu header{
	color:#cbd5e1!important;
}
}
body[data-dp-theme-effects~="glass"] a:focus-visible,
body[data-dp-theme-effects~="glass"] button:focus-visible,
body[data-dp-theme-effects~="glass"] summary:focus-visible,
body[data-dp-theme-effects~="glass"] input:focus-visible,
body[data-dp-theme-effects~="glass"] select:focus-visible,
body[data-dp-theme-effects~="glass"] textarea:focus-visible,
body[data-dp-theme-effects~="glass"] [tabindex]:focus-visible{
	outline:2px solid color-mix(in srgb,var(--dp-primary-600,#2563eb) 76%,#fff)!important;
	outline-offset:3px!important;
	box-shadow:0 0 0 5px color-mix(in srgb,var(--dp-primary-600,#2563eb) 18%,transparent),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-field:has(.dp-panel-error) input,
body[data-dp-theme-effects~="glass"] .dp-panel-field:has(.dp-panel-error) select,
body[data-dp-theme-effects~="glass"] .dp-panel-field:has(.dp-panel-error) textarea,
body[data-dp-theme-effects~="glass"] input[aria-invalid=true],
body[data-dp-theme-effects~="glass"] select[aria-invalid=true],
body[data-dp-theme-effects~="glass"] textarea[aria-invalid=true]{
	border-color:color-mix(in srgb,var(--dp-danger-600,#dc2626) 62%,var(--dp-glass_border))!important;
	background:linear-gradient(135deg,color-mix(in srgb,var(--dp-danger-600,#dc2626) 9%,var(--dp-glass_control_bg)),color-mix(in srgb,var(--dp-glass_surface_muted_bg) 58%,transparent))!important;
	box-shadow:0 0 0 4px color-mix(in srgb,var(--dp-danger-600,#dc2626) 14%,transparent),inset 0 1px 0 color-mix(in srgb,#fff 20%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-field:has(.dp-panel-error) span,
body[data-dp-theme-effects~="glass"] .dp-panel-error{
	color:color-mix(in srgb,var(--dp-danger-700,#b42318) 88%,var(--dp-text))!important;
	font-weight:850!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-error{
	display:inline-flex!important;
	align-items:center!important;
	gap:6px!important;
	width:max-content!important;
	max-width:100%!important;
	border:1px solid color-mix(in srgb,var(--dp-danger-600,#dc2626) 28%,var(--dp-glass_border))!important;
	border-radius:999px!important;
	background:color-mix(in srgb,var(--dp-danger-600,#dc2626) 10%,var(--dp-glass_surface_muted_bg))!important;
	padding:5px 8px!important;
	box-shadow:var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-alert,
body[data-dp-theme-effects~="glass"] .dp-panel-notice{
	position:relative!important;
	overflow:hidden!important;
	border-radius:16px!important;
	box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-alert:before,
body[data-dp-theme-effects~="glass"] .dp-panel-notice:before{
	content:""!important;
	position:absolute!important;
	inset:0 auto 0 0!important;
	width:4px!important;
	background:var(--dp-glass-tone,var(--dp-primary-600,#2563eb))!important;
	opacity:.86!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-alert>*,
body[data-dp-theme-effects~="glass"] .dp-panel-notice>*{
	position:relative!important;
	z-index:1!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-notice-success{--dp-glass-tone:var(--dp-success-600,#16a34a)}
body[data-dp-theme-effects~="glass"] .dp-panel-notice-warning{--dp-glass-tone:var(--dp-warning-600,#d97706)}
body[data-dp-theme-effects~="glass"] .dp-panel-notice-error,
body[data-dp-theme-effects~="glass"] .dp-panel-alert{--dp-glass-tone:var(--dp-danger-600,#dc2626)}
body[data-dp-theme-effects~="glass"] .dp-panel-notice-info{--dp-glass-tone:var(--dp-info-600,#0891b2)}
body[data-dp-theme-effects~="glass"] .dp-panel-notice span,
body[data-dp-theme-effects~="glass"] .dp-panel-alert span,
body[data-dp-theme-effects~="glass"] .dp-panel-alert strong,
body[data-dp-theme-effects~="glass"] .dp-panel-notice strong{
	color:var(--dp-text,#0f172a)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-danger:focus-visible,
body[data-dp-theme-effects~="glass"] .dp-panel-action-warning:focus-visible,
body[data-dp-theme-effects~="glass"] .dp-panel-action-success:focus-visible{
	outline-color:rgba(255,255,255,.92)!important;
	box-shadow:0 0 0 5px color-mix(in srgb,currentColor 24%,transparent),var(--dp-glass_edge)!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-field:has(.dp-panel-error) span,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-error{
	color:#fecaca!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-notice span,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-alert span,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-alert strong,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-notice strong{
	color:#f8fafc!important;
}
@media(prefers-color-scheme:dark){
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-field:has(.dp-panel-error) span,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-error{
	color:#fecaca!important;
}
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-notice span,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-alert span,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-alert strong,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-notice strong{
	color:#f8fafc!important;
}
}
@media(forced-colors:active){
body[data-dp-theme-effects~="glass"] *,
body[data-dp-theme-effects~="glass"] *:before,
body[data-dp-theme-effects~="glass"] *:after{
	box-shadow:none!important;
	text-shadow:none!important;
	backdrop-filter:none!important;
	-webkit-backdrop-filter:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-main-region>header,
body[data-dp-theme-effects~="glass"] .dp-panel-card,
body[data-dp-theme-effects~="glass"] .dp-panel-widget,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar,
body[data-dp-theme-effects~="glass"] .dp-panel-page-table,
body[data-dp-theme-effects~="glass"] .dp-panel-table,
body[data-dp-theme-effects~="glass"] .dp-panel-modal,
body[data-dp-theme-effects~="glass"] .dp-panel-command,
body[data-dp-theme-effects~="glass"] .dp-panel-sidebar{
	border:1px solid CanvasText!important;
	background:Canvas!important;
	color:CanvasText!important;
}
body[data-dp-theme-effects~="glass"] a:focus-visible,
body[data-dp-theme-effects~="glass"] button:focus-visible,
body[data-dp-theme-effects~="glass"] summary:focus-visible,
body[data-dp-theme-effects~="glass"] input:focus-visible,
body[data-dp-theme-effects~="glass"] select:focus-visible,
body[data-dp-theme-effects~="glass"] textarea:focus-visible{
	outline:2px solid Highlight!important;
	outline-offset:3px!important;
}
}
body[data-dp-theme-effects~="glass"] .dp-panel-page-table,
body[data-dp-theme-effects~="glass"] .dp-panel-table-shell{
	overflow:hidden!important;
	max-width:100%!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll:has(.dp-panel-row-more[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll:has(.dp-panel-action-group[open]){
	overflow:auto!important;
	max-width:100%!important;
	overscroll-behavior-x:contain!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-page-table:has(.dp-panel-row-more[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-table-shell:has(.dp-panel-row-more[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-page-table:has(.dp-panel-action-group[open]),
body[data-dp-theme-effects~="glass"] .dp-panel-table-shell:has(.dp-panel-action-group[open]){
	overflow:hidden!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-floating .dp-panel-row-more-menu{
	position:fixed!important;
	contain:layout paint!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel[data-dp-panel-kind="dashboard"] .dp-panel-widget-icon,
body[data-dp-theme-effects~="glass"] .dp-panel[data-dp-panel-kind="custom_page"] .dp-panel-widget-icon{
	display:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-theme-select{
	background:transparent!important;
	box-shadow:none!important;
	backdrop-filter:none!important;
	-webkit-backdrop-filter:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-theme-select select{
	background:color-mix(in srgb,var(--dp-glass_control_bg) 62%,transparent)!important;
	box-shadow:var(--dp-glass_edge)!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-theme-select select{
	background:color-mix(in srgb,rgba(30,41,59,.76) 68%,transparent)!important;
}
@media(prefers-color-scheme:dark){
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-theme-select select{
	background:color-mix(in srgb,rgba(30,41,59,.76) 68%,transparent)!important;
}
}
body[data-dp-theme-effects~="glass"] .dp-panel[data-dp-panel-kind="dashboard"] .dp-panel-global-search input,
body[data-dp-theme-effects~="glass"] .dp-panel[data-dp-panel-kind="custom_page"] .dp-panel-global-search input{
	padding-left:14px!important;
	padding-right:14px!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel[data-dp-panel-kind="dashboard"] .dp-panel-global-search:before,
body[data-dp-theme-effects~="glass"] .dp-panel[data-dp-panel-kind="dashboard"] .dp-panel-global-search:after,
body[data-dp-theme-effects~="glass"] .dp-panel[data-dp-panel-kind="custom_page"] .dp-panel-global-search:before,
body[data-dp-theme-effects~="glass"] .dp-panel[data-dp-panel-kind="custom_page"] .dp-panel-global-search:after{
	display:none!important;
	content:none!important;
}
body[data-dp-theme-effects~="glass"] ::selection{
	background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 28%,transparent);
	color:var(--dp-text,#0f172a);
}
body[data-dp-theme-effects~="glass"] mark{
	display:inline;
	border:1px solid color-mix(in srgb,var(--dp-warning-600,#d97706) 34%,var(--dp-glass_border));
	border-radius:8px;
	background:color-mix(in srgb,var(--dp-warning-600,#d97706) 18%,var(--dp-glass_surface_muted_bg));
	color:var(--dp-text,#0f172a);
	padding:.08em .28em;
	box-decoration-break:clone;
	-webkit-box-decoration-break:clone;
}
body[data-dp-theme-effects~="glass"] kbd,
body[data-dp-theme-effects~="glass"] code,
body[data-dp-theme-effects~="glass"] pre{
	border:1px solid color-mix(in srgb,var(--dp-glass_border) 74%,transparent)!important;
	background:color-mix(in srgb,var(--dp-glass_control_bg) 82%,var(--dp-surface) 12%)!important;
	color:var(--dp-text,#0f172a)!important;
	box-shadow:var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] kbd{
	display:inline-flex;
	align-items:center;
	justify-content:center;
	min-height:22px;
	border-radius:7px;
	padding:2px 7px;
	font-size:.82em;
	font-weight:850;
	line-height:1;
}
body[data-dp-theme-effects~="glass"] code{
	border-radius:7px;
	padding:.12em .35em;
}
body[data-dp-theme-effects~="glass"] pre{
	border-radius:16px!important;
	padding:14px!important;
	overflow:auto!important;
	white-space:pre-wrap!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:nth-child(odd){
	background:color-mix(in srgb,var(--dp-glass_surface_muted_bg) 38%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:nth-child(even){
	background:color-mix(in srgb,var(--dp-glass_surface_strong_bg) 34%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:hover{
	background:linear-gradient(90deg,color-mix(in srgb,var(--dp-primary-600,#2563eb) 10%,var(--dp-glass_surface_muted_bg)),color-mix(in srgb,var(--dp-glass_surface_strong_bg) 68%,transparent))!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-loading,
body[data-dp-theme-effects~="glass"] .dp-panel-form-loading,
body[data-dp-theme-effects~="glass"] .dp-panel-action-loading,
body[data-dp-theme-effects~="glass"] .dp-panel-record-pulse-grid>*,
body[data-dp-theme-effects~="glass"] .dp-panel-table-pulse-grid>*,
body[data-dp-theme-effects~="glass"] .dp-panel-board-pulse-grid>*,
body[data-dp-theme-effects~="glass"] .dp-panel-form-pulse-grid>*{
	position:relative!important;
	overflow:hidden!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-record-pulse-grid>*:after,
body[data-dp-theme-effects~="glass"] .dp-panel-table-pulse-grid>*:after,
body[data-dp-theme-effects~="glass"] .dp-panel-board-pulse-grid>*:after,
body[data-dp-theme-effects~="glass"] .dp-panel-form-pulse-grid>*:after{
	content:""!important;
	position:absolute!important;
	inset:0!important;
	background:linear-gradient(90deg,transparent,color-mix(in srgb,#fff 24%,transparent),transparent)!important;
	transform:translateX(-100%)!important;
	animation:dp-panel-glass-shimmer 1.45s ease-in-out infinite!important;
	pointer-events:none!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] ::selection{
	background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 44%,transparent);
	color:#f8fafc;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] mark{
	background:color-mix(in srgb,var(--dp-warning-600,#d97706) 24%,rgba(15,23,42,.72));
	color:#f8fafc;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] kbd,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] code,
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] pre{
	background:rgba(15,23,42,.70)!important;
	color:#f8fafc!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:nth-child(odd){
	background:rgba(15,23,42,.38)!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:nth-child(even){
	background:rgba(30,41,59,.34)!important;
}
body[data-dp-theme-mode="dark"][data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:hover{
	background:linear-gradient(90deg,color-mix(in srgb,var(--dp-primary-600,#2563eb) 20%,rgba(15,23,42,.72)),rgba(30,41,59,.56))!important;
}
@media(prefers-color-scheme:dark){
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] ::selection{
	background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 44%,transparent);
	color:#f8fafc;
}
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] mark{
	background:color-mix(in srgb,var(--dp-warning-600,#d97706) 24%,rgba(15,23,42,.72));
	color:#f8fafc;
}
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] kbd,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] code,
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] pre{
	background:rgba(15,23,42,.70)!important;
	color:#f8fafc!important;
}
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:nth-child(odd){
	background:rgba(15,23,42,.38)!important;
}
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:nth-child(even){
	background:rgba(30,41,59,.34)!important;
}
body[data-dp-theme-mode="system"][data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:hover{
	background:linear-gradient(90deg,color-mix(in srgb,var(--dp-primary-600,#2563eb) 20%,rgba(15,23,42,.72)),rgba(30,41,59,.56))!important;
}
}
@media(prefers-reduced-transparency:reduce){
body[data-dp-theme-effects~="glass"]{
	--dp-glass_blur:none;
	--dp-glass_surface_bg:var(--dp-surface,#fff);
	--dp-glass_surface_strong_bg:var(--dp-surface,#fff);
	--dp-glass_surface_muted_bg:color-mix(in srgb,var(--dp-surface,#fff) 94%,var(--dp-border) 6%);
	--dp-glass_control_bg:var(--dp-surface,#fff);
}
body[data-dp-theme-effects~="glass"] *,
body[data-dp-theme-effects~="glass"] *:before,
body[data-dp-theme-effects~="glass"] *:after{
	backdrop-filter:none!important;
	-webkit-backdrop-filter:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel:before,
body[data-dp-theme-effects~="glass"] .dp-panel-sidebar:before,
body[data-dp-theme-effects~="glass"] .dp-panel-main-region>header:after,
body[data-dp-theme-effects~="glass"] .dp-panel-card:after,
body[data-dp-theme-effects~="glass"] .dp-panel-widget:after,
body[data-dp-theme-effects~="glass"] .dp-panel-page-table:after,
body[data-dp-theme-effects~="glass"] .dp-panel-table-shell:after,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar:after,
body[data-dp-theme-effects~="glass"] .dp-panel-show-field:after{
	opacity:.18!important;
}
}
@media print{
body[data-dp-theme-effects~="glass"]{
	background:#fff!important;
	color:#000!important;
}
body[data-dp-theme-effects~="glass"] *,
body[data-dp-theme-effects~="glass"] *:before,
body[data-dp-theme-effects~="glass"] *:after{
	box-shadow:none!important;
	text-shadow:none!important;
	backdrop-filter:none!important;
	-webkit-backdrop-filter:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel{
	width:100%!important;
	max-width:none!important;
	padding:0!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-sidebar,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar,
body[data-dp-theme-effects~="glass"] .dp-panel-toolbar-actions,
body[data-dp-theme-effects~="glass"] .dp-panel-actions,
body[data-dp-theme-effects~="glass"] .dp-panel-modal-root,
body[data-dp-theme-effects~="glass"] .dp-panel-toast-region{
	display:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-with-sidebar,
body[data-dp-theme-effects~="glass"] .dp-panel-main-region{
	display:block!important;
	width:100%!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-main-region>header,
body[data-dp-theme-effects~="glass"] .dp-panel-card,
body[data-dp-theme-effects~="glass"] .dp-panel-widget,
body[data-dp-theme-effects~="glass"] .dp-panel-page-table,
body[data-dp-theme-effects~="glass"] .dp-panel-table,
body[data-dp-theme-effects~="glass"] .dp-panel-show-field{
	break-inside:avoid!important;
	border:1px solid #cbd5e1!important;
	background:#fff!important;
	color:#000!important;
}
}
@media(max-width:760px){
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar,
body[data-dp-theme-effects~="glass"] .dp-panel-page-table,
body[data-dp-theme-effects~="glass"] .dp-panel-table-shell,
body[data-dp-theme-effects~="glass"] .dp-panel-modal{
	border-radius:18px!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar-top,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar-bottom,
body[data-dp-theme-effects~="glass"] .dp-panel-table-controls{
	padding-bottom:10px!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta,
body[data-dp-theme-effects~="glass"] .dp-panel-pagination{
	border-radius:14px!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-bulk-bar{
	border-radius:18px!important;
	align-items:stretch!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-header{
	background:color-mix(in srgb,var(--dp-glass_surface_strong_bg) 86%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-density,
body[data-dp-theme-effects~="glass"] .dp-panel-table-groups,
body[data-dp-theme-effects~="glass"] .dp-panel-table-views,
body[data-dp-theme-effects~="glass"] .dp-panel-tab-list,
body[data-dp-theme-effects~="glass"] .dp-panel-step-list{
	max-width:100%!important;
	overflow-x:auto!important;
	overflow-y:hidden!important;
	scroll-snap-type:x proximity!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-density a,
body[data-dp-theme-effects~="glass"] .dp-panel-table-group,
body[data-dp-theme-effects~="glass"] .dp-panel-table-group-link,
body[data-dp-theme-effects~="glass"] .dp-panel-table-view,
body[data-dp-theme-effects~="glass"] .dp-panel-tab-list button,
body[data-dp-theme-effects~="glass"] .dp-panel-step-list button{
	scroll-snap-align:start!important;
	white-space:nowrap!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-button,
body[data-dp-theme-effects~="glass"] .dp-panel-action,
body[data-dp-theme-effects~="glass"] .dp-panel-row-link{
	min-height:42px!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-board{
	grid-template-columns:1fr!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-board-column,
body[data-dp-theme-effects~="glass"] .dp-panel-chart,
body[data-dp-theme-effects~="glass"] .dp-panel-chart-card{
	border-radius:18px!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker form,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu>div{
	max-width:calc(100vw - 28px)!important;
}
}
@media(prefers-reduced-motion:no-preference){body[data-dp-theme-effects~="glass"] .dp-panel-widget,body[data-dp-theme-effects~="glass"] .dp-panel-card,body[data-dp-theme-effects~="glass"] .dp-panel-show-field,body[data-dp-theme-effects~="glass"] .dp-panel-board-card{transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}body[data-dp-theme-effects~="glass"] .dp-panel-widget:hover,body[data-dp-theme-effects~="glass"] .dp-panel-card:hover,body[data-dp-theme-effects~="glass"] .dp-panel-show-field:hover,body[data-dp-theme-effects~="glass"] .dp-panel-board-card:hover{transform:translateY(-2px)}}
@media(max-width:760px){body[data-dp-theme-effects~="glass"]{--dp-glass_blur:var(--dp-glass_mobile_blur,blur(14px) saturate(1.12));--dp-glass_shadow:var(--dp-glass_shadow_soft)}body[data-dp-theme-effects~="glass"] .dp-panel-tab-list,body[data-dp-theme-effects~="glass"] .dp-panel-step-list{border-radius:18px!important;overflow-x:auto!important}body[data-dp-theme-effects~="glass"] .dp-panel-main-region>header:after,body[data-dp-theme-effects~="glass"] .dp-panel-card:after,body[data-dp-theme-effects~="glass"] .dp-panel-widget:after,body[data-dp-theme-effects~="glass"] .dp-panel-page-table:after,body[data-dp-theme-effects~="glass"] .dp-panel-table-shell:after,body[data-dp-theme-effects~="glass"] .dp-panel-commandbar:after,body[data-dp-theme-effects~="glass"] .dp-panel-form-section:after,body[data-dp-theme-effects~="glass"] .dp-panel-show-field:after{opacity:.52}body[data-dp-theme-effects~="glass"] .dp-panel-empty-state:before{opacity:.38}}
@media(prefers-reduced-motion:reduce){body[data-dp-theme-effects~="glass"] .dp-panel-modal-loading:before,body[data-dp-theme-effects~="glass"] .dp-panel-form-loading:after,body[data-dp-theme-effects~="glass"] .dp-panel-action-loading:after{animation:none!important;display:none!important}body[data-dp-theme-effects~="glass"] .dp-panel-widget:hover,body[data-dp-theme-effects~="glass"] .dp-panel-card:hover,body[data-dp-theme-effects~="glass"] .dp-panel-show-field:hover,body[data-dp-theme-effects~="glass"] .dp-panel-board-card:hover{transform:none!important}}
CSS;
	}

}
