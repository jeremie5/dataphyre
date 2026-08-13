<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Minimal layout and status treatment for interactive widget islands. */
trait PanelRendererAssetsWidgetRuntimeCss {
	private static function widgetRuntimeCss(): string {
		return <<<'CSS'
.dp-panel-widget-island{display:grid;min-width:0;gap:0}.dp-panel-widget-island>.dp-panel-widget-fallback{min-width:0}.dp-panel-widget-island>.dp-panel-widget-fallback>.dp-panel-widget{width:100%}.dp-panel-widget-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-top:1px solid var(--dp-border_soft);background:var(--dp-surface,#fff);padding:10px 16px}.dp-panel-widget-action{appearance:none;display:inline-flex;align-items:center;justify-content:center;min-height:44px;border:1px solid var(--dp-border,#d0d5dd);border-radius:8px;background:var(--dp-control_bg,var(--dp-surface,#fff));color:var(--dp-text,#18202a);padding:6px 10px;font:inherit;font-size:12px;font-weight:750;cursor:pointer}.dp-panel-widget-action:hover:not(:disabled){border-color:var(--dp-primary-500,#2e6ce6);color:var(--dp-primary-700,#175cd3)}.dp-panel-widget-action:focus-visible{outline:3px solid color-mix(in srgb,var(--dp-primary-500,#2e6ce6) 36%,transparent);outline-offset:2px}.dp-panel-widget-action:disabled{cursor:wait;opacity:.62}.dp-panel-widget-status{margin:0;color:var(--dp-text_muted,#667085);font-size:12px;line-height:1.4}.dp-panel-widget-island[data-dp-widget-status="error"]>.dp-panel-widget-status,.dp-panel-widget-island[data-dp-widget-status="offline"]>.dp-panel-widget-status{color:var(--dp-danger-700,#b42318)}.dp-panel-widget-island[data-dp-widget-status="loading"]>.dp-panel-widget-status{color:var(--dp-primary-700,#175cd3)}body[data-dp-theme-mode="dark"] .dp-panel-widget-action{background:var(--dp-control_bg,#111827);border-color:var(--dp-border,#40506a);color:var(--dp-text,#f8fafc)}@media(max-width:520px){.dp-panel-widget-actions{display:grid;grid-template-columns:minmax(0,1fr)}.dp-panel-widget-action{width:100%}}@media(prefers-reduced-motion:reduce){.dp-panel-widget-action{animation:none;transition:none}}
CSS;
	}
}
