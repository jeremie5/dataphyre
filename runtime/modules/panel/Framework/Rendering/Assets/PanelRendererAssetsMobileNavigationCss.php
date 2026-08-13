<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits responsive navigation and sticky chrome styles for Panel layouts.
 *
 * These static CSS assets bridge renderer-produced body classes, panel data
 * attributes, and JavaScript-opened navigation states. They do not inspect
 * request state directly; the surrounding renderer decides whether mobile
 * navigation, header, footer, and chrome attachment rules belong in the bundle.
 */
trait PanelRendererAssetsMobileNavigationCss {
	/**
	 * Returns the responsive panel navigation stylesheet.
	 *
	 * The block defines sidebar, horizontal, drawer, split, collapsed, edge,
	 * floating, docked, overlay, dark/system, and mobile navigation states. It also
	 * constrains menu stacking and scroll behavior so generated navigation remains
	 * usable across desktop and mobile panel layouts.
	 *
	 * @return string CSS emitted for panel navigation layout modes.
	 */
	private static function mobileNavigationCss(): string {
		return <<<'CSS'
.dp-panel-modal-header{position:relative;padding-right:max(var(--dp-modal-pad,16px),68px)}
.dp-panel-modal-header-actions{position:static;padding-right:42px}
.dp-panel-modal-close{position:absolute;top:12px;right:12px;z-index:8}
@media(max-width:760px){.dp-panel-modal-header{padding-right:58px}.dp-panel-modal-header-actions{justify-content:flex-start;flex-wrap:nowrap;max-width:100%;overflow-x:auto;padding-right:0}.dp-panel-modal-close{top:10px;right:10px}}
.dp-panel-nav-sidebar{
	--dp-panel-sidebar-gap:20px;
	--dp-panel-main-region-pad-right:clamp(24px,1.6vw,36px);
	--dp-panel-pad-inline:clamp(10px,2.6vw,16px);
	--dp-nav-width:300px;
	--dp-nav-gap:20px;
	--dp-nav-shell-bg:linear-gradient(180deg,color-mix(in srgb,var(--dp-surface) 92%,transparent),color-mix(in srgb,var(--dp-surface_muted) 34%,var(--dp-surface)));
	--dp-nav-shell-border:color-mix(in srgb,var(--dp-border) 72%,transparent);
	--dp-nav-shell-radius:26px;
	--dp-nav-shell-padding:14px;
	--dp-nav-shell-shadow:0 22px 58px color-mix(in srgb,#0f172a 10%,transparent);
	--dp-nav-brand-bg:color-mix(in srgb,var(--dp-surface_muted) 54%,transparent);
	--dp-nav-brand-border:color-mix(in srgb,var(--dp-border) 68%,transparent);
	--dp-nav-section-gap:10px;
	--dp-nav-section-border:color-mix(in srgb,var(--dp-border_soft) 70%,transparent);
	--dp-nav-item-bg:transparent;
	--dp-nav-item-hover-bg:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 26%,transparent);
	--dp-nav-item-border:transparent;
	--dp-nav-item-radius:14px;
	--dp-nav-item-height:43px;
	--dp-nav-item-padding:6px 8px;
	--dp-nav-item-active-bg:linear-gradient(90deg,color-mix(in srgb,var(--dp-primary-600,#2563eb) 18%,var(--dp-surface)),color-mix(in srgb,var(--dp-primary-50,#eff6ff) 52%,transparent));
	--dp-nav-item-active-color:var(--dp-primary-800,#1c4bb3);
	--dp-nav-icon-bg:color-mix(in srgb,var(--dp-neutral_bg,#eef2f7) 76%,transparent);
	--dp-nav-icon-color:var(--dp-neutral_text,#344054);
	--dp-nav-icon-active-bg:var(--dp-primary-600,#2563eb);
	--dp-nav-icon-active-color:#fff;
	--dp-nav-submenu-indent:16px;
	--dp-nav-submenu-rail:color-mix(in srgb,var(--dp-border) 58%,transparent);
	--dp-nav-badge-bg:color-mix(in srgb,var(--dp-surface_muted) 70%,transparent);
	--dp-nav-badge-color:var(--dp-text_muted);
	--dp-nav-search-bg:color-mix(in srgb,var(--dp-control_bg,var(--dp-surface)) 88%,transparent);
	--dp-nav-current-bg:linear-gradient(135deg,color-mix(in srgb,var(--dp-primary-50,#eff6ff) 58%,transparent),transparent);
	--dp-nav-current-border:color-mix(in srgb,var(--dp-primary-600,#2563eb) 22%,var(--dp-border));
}
.dp-panel-nav-sidebar{grid-template-columns:minmax(250px,var(--dp-nav-width)) minmax(0,1fr);column-gap:var(--dp-nav-gap)}
.dp-panel-nav-sidebar .dp-panel-main-region{padding-right:var(--dp-panel-main-region-pad-right)}
.dp-panel-nav-sidebar .dp-panel-footer{margin-inline:calc(-1 * var(--dp-panel-pad-inline,0px));margin-bottom:calc(-1 * var(--dp-panel-pad-bottom,0px));width:calc(100% + var(--dp-panel-pad-inline,0px) + var(--dp-panel-pad-inline,0px))}
.dp-panel-nav-sidebar .dp-panel-sidebar{display:flex;flex-direction:column}
.dp-panel-nav-sidebar .dp-panel-sidebar{gap:var(--dp-nav-section-gap);padding:var(--dp-nav-shell-padding);border:1px solid var(--dp-nav-shell-border);border-radius:var(--dp-nav-shell-radius);background:var(--dp-nav-shell-bg);box-shadow:var(--dp-nav-shell-shadow)}
.dp-panel-nav-sidebar .dp-panel-sidebar-top{margin:calc(var(--dp-nav-shell-padding) * -1) calc(var(--dp-nav-shell-padding) * -1) 0;padding:var(--dp-nav-shell-padding) var(--dp-nav-shell-padding) 8px;border-radius:var(--dp-nav-shell-radius) var(--dp-nav-shell-radius) 0 0}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand{border:1px solid var(--dp-nav-brand-border);border-radius:18px;background:var(--dp-nav-brand-bg);box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-search{margin:0;padding:0;background:transparent}
.dp-panel-nav-sidebar .dp-panel-sidebar-search input{height:42px;border:1px solid var(--dp-nav-brand-border);border-radius:17px;background:var(--dp-nav-search-bg);box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-context{border:1px solid var(--dp-nav-current-border);border-radius:18px;background:var(--dp-nav-current-bg);box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-context span{background:transparent;color:var(--dp-primary-700,#175cd3);padding:0;min-height:0}
.dp-panel-nav-sidebar .dp-panel-sidebar-group{display:grid;gap:5px;margin:0;padding:10px 0 0;border:0;border-top:1px solid var(--dp-nav-section-border);border-radius:0;background:transparent;box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2{padding:0 7px}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button{height:28px;border-radius:11px;background:transparent;padding:0}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button:hover{background:transparent;color:var(--dp-text)}
.dp-panel-sidebar-submenu{background:transparent;border:0;box-shadow:none}
.dp-panel-sidebar-submenu>summary,.dp-panel-nav-sidebar .dp-panel-sidebar-link{min-height:var(--dp-nav-item-height);border:1px solid var(--dp-nav-item-border);border-radius:var(--dp-nav-item-radius);background:var(--dp-nav-item-bg);padding:var(--dp-nav-item-padding);box-shadow:none}
.dp-panel-sidebar-submenu>summary:hover,.dp-panel-nav-sidebar .dp-panel-sidebar-link:hover{border-color:var(--dp-nav-item-border);background:var(--dp-nav-item-hover-bg);box-shadow:none;transform:none}
.dp-panel-sidebar-submenu.active>summary,.dp-panel-nav-sidebar .dp-panel-sidebar-link.active{background:var(--dp-nav-item-active-bg);color:var(--dp-nav-item-active-color);box-shadow:none}
.dp-panel-sidebar-submenu.active>summary:before,.dp-panel-nav-sidebar .dp-panel-sidebar-link.active:before{background:var(--dp-primary-600,#2563eb)}
.dp-panel-sidebar-icon{background:var(--dp-nav-icon-bg);color:var(--dp-nav-icon-color)}
.dp-panel-sidebar-submenu.active>summary .dp-panel-sidebar-icon,.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-icon{background:var(--dp-nav-icon-active-bg);color:var(--dp-nav-icon-active-color)}
.dp-panel-sidebar-submenu-items{margin-left:var(--dp-nav-submenu-indent);border-left:1px solid var(--dp-nav-submenu-rail);padding-left:10px}
.dp-panel-sidebar-badge{background:var(--dp-nav-badge-bg);color:var(--dp-nav-badge-color)}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy strong{font-weight:860}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy small{font-weight:640;opacity:.92}
body[data-dp-theme-mode="dark"] .dp-panel-nav-sidebar{
	--dp-nav-shell-bg:linear-gradient(180deg,color-mix(in srgb,#172235 92%,transparent),color-mix(in srgb,#101827 86%,transparent));
	--dp-nav-shell-border:#27364c;
	--dp-nav-brand-bg:color-mix(in srgb,#1b2a3f 74%,transparent);
	--dp-nav-brand-border:#304159;
	--dp-nav-section-border:#27364c;
	--dp-nav-item-hover-bg:color-mix(in srgb,#22334c 68%,transparent);
	--dp-nav-item-active-bg:linear-gradient(90deg,#1e3a60,#172842);
	--dp-nav-item-active-color:#eaf2ff;
	--dp-nav-icon-bg:#233247;
	--dp-nav-icon-color:#dbe7f8;
	--dp-nav-badge-bg:#223247;
	--dp-nav-badge-color:#d6e0ee;
	--dp-nav-search-bg:#101827;
	--dp-nav-current-bg:linear-gradient(135deg,#1c3354,#132034);
	--dp-nav-current-border:#34537f;
	--dp-nav-submenu-rail:#2c3d55;
}
@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"] .dp-panel-nav-sidebar{
	--dp-nav-shell-bg:linear-gradient(180deg,color-mix(in srgb,#172235 92%,transparent),color-mix(in srgb,#101827 86%,transparent));
	--dp-nav-shell-border:#27364c;
	--dp-nav-brand-bg:color-mix(in srgb,#1b2a3f 74%,transparent);
	--dp-nav-brand-border:#304159;
	--dp-nav-section-border:#27364c;
	--dp-nav-item-hover-bg:color-mix(in srgb,#22334c 68%,transparent);
	--dp-nav-item-active-bg:linear-gradient(90deg,#1e3a60,#172842);
	--dp-nav-item-active-color:#eaf2ff;
	--dp-nav-icon-bg:#233247;
	--dp-nav-icon-color:#dbe7f8;
	--dp-nav-badge-bg:#223247;
	--dp-nav-badge-color:#d6e0ee;
	--dp-nav-search-bg:#101827;
	--dp-nav-current-bg:linear-gradient(135deg,#1c3354,#132034);
	--dp-nav-current-border:#34537f;
	--dp-nav-submenu-rail:#2c3d55;
}}
.dp-panel-sidebar-submenu{display:grid;gap:4px;border:0;border-radius:0;background:transparent;padding:0;box-shadow:none}
.dp-panel-sidebar-submenu>summary{display:grid;grid-template-columns:34px minmax(0,1fr) auto 14px;gap:9px;align-items:center;min-height:44px;border:1px solid transparent;border-radius:13px;color:var(--dp-text);padding:6px 8px;cursor:pointer;list-style:none}
.dp-panel-sidebar-submenu>summary::-webkit-details-marker{display:none}
.dp-panel-sidebar-submenu>summary>i{width:8px;height:8px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg);opacity:.64;transition:transform .14s ease}
.dp-panel-sidebar-submenu[open]>summary>i{transform:rotate(225deg)}
.dp-panel-sidebar-submenu>summary:hover{border-color:var(--dp-border_soft);background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 28%,transparent)}
.dp-panel-sidebar-submenu.active>summary{border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 26%,var(--dp-border));background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 48%,transparent);color:var(--dp-primary-800,#1c4bb3)}
.dp-panel-sidebar-submenu-items{display:grid;gap:4px;margin-left:17px;padding:2px 0 4px 12px;border-left:1px solid color-mix(in srgb,var(--dp-border) 72%,transparent)}
.dp-panel-sidebar-submenu-depth-1 .dp-panel-sidebar-submenu-items{margin-left:14px;padding-left:10px}
.dp-panel-sidebar-link-parent{background:transparent;border-style:dashed}
.dp-panel-sidebar-submenu .dp-panel-sidebar-link{min-height:40px}
.dp-panel-sidebar-submenu .dp-panel-sidebar-submenu{margin-left:0}
.dp-panel-horizontal-submenu{position:relative;display:grid}
.dp-panel-horizontal-submenu>summary{display:grid;grid-template-columns:34px minmax(0,1fr) auto;gap:9px;align-items:center;min-height:48px;border:1px solid transparent;border-radius:14px;color:var(--dp-text);padding:7px 9px;cursor:pointer;list-style:none}
.dp-panel-horizontal-submenu>summary::-webkit-details-marker{display:none}
.dp-panel-horizontal-submenu>summary:hover,.dp-panel-horizontal-submenu.active>summary{border-color:var(--dp-border);background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 44%,var(--dp-surface))}
.dp-panel-horizontal-submenu>summary>span{display:grid;place-items:center;width:34px;height:34px;border-radius:12px;background:var(--dp-neutral_bg,#eef2f7);color:var(--dp-neutral_text,#344054);font-size:10px;font-weight:950}
.dp-panel-horizontal-submenu>summary strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;font-weight:880}
.dp-panel-horizontal-submenu>div{position:relative;display:grid;gap:6px;margin:4px 0 0 22px;padding-left:10px;border-left:1px solid var(--dp-border_soft)}
.dp-panel-horizontal-group[open]{z-index:16001}
.dp-panel-horizontal-group[open]>summary{position:relative;z-index:16002}
.dp-panel-horizontal-group[open]>div{position:fixed;left:var(--dp-horizontal-menu-left,10px);right:auto;top:var(--dp-horizontal-menu-top,72px);width:var(--dp-horizontal-menu-width,min(360px,86vw));max-height:var(--dp-horizontal-menu-max-height,min(480px,70vh));overflow:auto;z-index:16000}
@media(max-width:1180px){
.dp-panel-nav-sidebar .dp-panel-sidebar{position:sticky;top:8px;z-index:18;display:grid;gap:10px;margin:0 0 12px;padding:10px;border-radius:20px;background:color-mix(in srgb,var(--dp-surface) 88%,transparent);border:1px solid color-mix(in srgb,var(--dp-border) 82%,transparent);box-shadow:0 18px 42px rgba(15,23,42,.10);backdrop-filter:blur(18px);overflow:visible}
.dp-panel-nav-sidebar .dp-panel-sidebar-top{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;margin:0}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand{min-height:50px;border-radius:16px;padding:8px 10px;background:color-mix(in srgb,var(--dp-surface_muted) 64%,var(--dp-surface))}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand>span{width:36px;height:36px;border-radius:13px}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand strong{font-size:14px}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand small{display:block;font-size:11px}
.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle]{width:46px;height:46px;border-radius:15px;background:var(--dp-primary-600,#2563eb);color:#fff;box-shadow:0 12px 26px color-mix(in srgb,var(--dp-primary-600,#2563eb) 24%,transparent)}
.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle] span{width:13px;height:13px;border-left:2px solid currentColor;border-bottom:2px solid currentColor;border-radius:0;background:transparent;box-shadow:none;transform:translateX(2px) rotate(45deg)}
.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle] span:before,.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle] span:after{content:none}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle]{background:var(--dp-surface_muted);color:var(--dp-text);box-shadow:none}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle] span{transform:translateX(-2px) rotate(225deg)}
.dp-panel-nav-sidebar .dp-panel-sidebar-context{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:8px;margin:0;border:1px solid color-mix(in srgb,var(--dp-primary-600,#2563eb) 22%,var(--dp-border));border-radius:16px;background:linear-gradient(135deg,color-mix(in srgb,var(--dp-primary-50,#eff6ff) 66%,var(--dp-surface)),var(--dp-surface));padding:9px 11px}
.dp-panel-nav-sidebar .dp-panel-sidebar-context span{display:inline-flex;align-items:center;justify-content:center;min-height:24px;border-radius:999px;background:var(--dp-primary-600,#2563eb);color:#fff;padding:3px 9px;font-size:10px;font-weight:950;letter-spacing:.06em;text-transform:uppercase}
.dp-panel-nav-sidebar .dp-panel-sidebar-context strong{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--dp-text);font-size:14px;font-weight:940}
.dp-panel-nav-sidebar .dp-panel-sidebar-context small{justify-self:end;min-width:0;max-width:34vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--dp-text_muted);font-size:11px;font-weight:820}
.dp-panel-nav-sidebar .dp-panel-sidebar-search{display:grid;position:relative;top:auto;margin:0;padding:0;background:transparent;border-radius:0;backdrop-filter:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-search input{height:44px;min-height:44px;border-radius:15px;padding:0 42px 0 38px;font-size:16px;background:var(--dp-control_bg)}
.dp-panel-nav-sidebar .dp-panel-sidebar-search span{right:8px}
.dp-panel-nav-sidebar .dp-panel-sidebar-nav{display:flex;align-items:flex-start;gap:10px;width:100%;max-width:100%;overflow-x:auto;overflow-y:hidden;padding:2px 2px 8px;scroll-snap-type:x mandatory;scroll-padding-inline:8px;scrollbar-width:thin;overscroll-behavior-inline:contain}
.dp-panel-nav-sidebar .dp-panel-sidebar-nav>.dp-panel-sidebar-link{flex:0 0 min(210px,68vw);scroll-snap-align:start}
.dp-panel-nav-sidebar .dp-panel-sidebar-group{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-content:start;gap:8px;flex:0 0 min(430px,88vw);min-width:0;margin:0;padding:10px;border:1px solid var(--dp-border_soft);border-radius:18px;background:color-mix(in srgb,var(--dp-surface_muted) 48%,var(--dp-surface));scroll-snap-align:start}
.dp-panel-nav-sidebar .dp-panel-sidebar-group.active{order:-20;border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 35%,var(--dp-border));box-shadow:0 12px 30px color-mix(in srgb,var(--dp-primary-600,#2563eb) 10%,transparent)}
.dp-panel-nav-sidebar .dp-panel-sidebar-pinned{order:-30}
.dp-panel-nav-sidebar .dp-panel-sidebar-recent{order:-10}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2{display:flex;grid-column:1/-1;margin:0;padding:0;color:var(--dp-text_muted);font-size:10px;font-weight:950;letter-spacing:.08em;text-transform:uppercase}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button{min-height:30px;border-radius:999px;background:color-mix(in srgb,var(--dp-surface) 74%,transparent);padding:4px 8px}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button b{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;border-radius:999px;background:var(--dp-surface_muted);color:var(--dp-text_muted);font-size:10px;font-weight:950}
.dp-panel-nav-sidebar .dp-panel-sidebar-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px;min-width:0}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2+.dp-panel-sidebar-item:last-child{grid-column:1/-1}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu{grid-column:1/-1;min-width:0}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu-items{padding-left:10px}
.dp-panel-nav-sidebar .dp-panel-sidebar-link{display:grid;grid-template-columns:32px minmax(0,1fr) auto;gap:8px;width:100%;min-width:0;max-width:none;min-height:56px;border-radius:15px;padding:8px;background:color-mix(in srgb,var(--dp-surface) 84%,transparent);border:1px solid var(--dp-border_soft)}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active{background:linear-gradient(135deg,var(--dp-primary-600,#2563eb),color-mix(in srgb,var(--dp-primary-600,#2563eb) 70%,var(--dp-info-600,#0891b2)));color:#fff;box-shadow:0 14px 32px color-mix(in srgb,var(--dp-primary-600,#2563eb) 24%,transparent)}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-copy small,.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-copy strong{color:#fff}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-icon,.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-badge{background:rgba(255,255,255,.22);color:#fff}
.dp-panel-nav-sidebar .dp-panel-sidebar-icon{width:32px;height:32px;border-radius:12px}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy{min-width:0}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;line-height:1.15}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:10px;line-height:1.2}
.dp-panel-nav-sidebar .dp-panel-sidebar-pin{display:none}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-search{display:none}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-context{display:none}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-nav{display:none}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar{gap:0}
}
@media(max-width:620px){
.dp-panel-nav-sidebar .dp-panel-sidebar{top:6px;margin-bottom:10px;padding:8px;border-radius:18px}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand{grid-template-columns:34px minmax(0,1fr);grid-template-areas:"icon name";min-height:44px;padding:6px 8px}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand>span{width:34px;height:34px}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand small{display:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle]{width:44px;height:44px}
.dp-panel-nav-sidebar .dp-panel-sidebar-context{grid-template-columns:minmax(0,1fr) auto}
.dp-panel-nav-sidebar .dp-panel-sidebar-context span{display:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-context small{max-width:38vw}
.dp-panel-nav-sidebar .dp-panel-sidebar-group{flex-basis:92vw;grid-template-columns:repeat(2,minmax(0,1fr));padding:9px}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2{grid-column:1/-1}
.dp-panel-nav-sidebar .dp-panel-sidebar-nav>.dp-panel-sidebar-link{flex-basis:78vw}
.dp-panel-nav-sidebar .dp-panel-sidebar-link{min-height:52px}
}
body[data-dp-theme-mode="dark"] .dp-panel-nav-sidebar .dp-panel-sidebar{background:color-mix(in srgb,#121a28 88%,transparent);border-color:#29384d}
body[data-dp-theme-mode="dark"] .dp-panel-nav-sidebar .dp-panel-sidebar-brand,body[data-dp-theme-mode="dark"] .dp-panel-nav-sidebar .dp-panel-sidebar-link,body[data-dp-theme-mode="dark"] .dp-panel-nav-sidebar .dp-panel-sidebar-group{background:#151f2e;border-color:#2f4058}
body[data-dp-theme-mode="dark"] .dp-panel-sidebar-submenu{background:transparent;border-color:transparent}
body[data-dp-theme-mode="dark"] .dp-panel-sidebar-submenu.active>summary{background:#1b2d49;border-color:#3b64a4;color:#dbeafe}
body[data-dp-theme-mode="dark"] .dp-panel-nav-sidebar .dp-panel-sidebar-context{background:linear-gradient(135deg,#1d3354,#151f2e);border-color:#34537f}
@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"] .dp-panel-sidebar-submenu{background:transparent;border-color:transparent}body[data-dp-theme-mode="system"] .dp-panel-sidebar-submenu.active>summary{background:#1b2d49;border-color:#3b64a4;color:#dbeafe}}
@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"] .dp-panel-nav-sidebar .dp-panel-sidebar{background:color-mix(in srgb,#121a28 88%,transparent);border-color:#29384d}body[data-dp-theme-mode="system"] .dp-panel-nav-sidebar .dp-panel-sidebar-brand,body[data-dp-theme-mode="system"] .dp-panel-nav-sidebar .dp-panel-sidebar-link,body[data-dp-theme-mode="system"] .dp-panel-nav-sidebar .dp-panel-sidebar-group{background:#151f2e;border-color:#2f4058}body[data-dp-theme-mode="system"] .dp-panel-nav-sidebar .dp-panel-sidebar-context{background:linear-gradient(135deg,#1d3354,#151f2e);border-color:#34537f}}
.dp-panel-nav-sidebar{
	--dp-nav-width:var(--dp-nav_width,304px);
	--dp-nav-gap:var(--dp-nav_gap,20px);
	--dp-nav-shell-bg:var(--dp-nav_shell_bg,linear-gradient(180deg,color-mix(in srgb,var(--dp-surface) 92%,transparent),color-mix(in srgb,var(--dp-surface_muted) 38%,var(--dp-surface))));
	--dp-nav-shell-border:var(--dp-nav_shell_border,color-mix(in srgb,var(--dp-border) 72%,transparent));
	--dp-nav-shell-radius:var(--dp-nav_shell_radius,26px);
	--dp-nav-shell-padding:var(--dp-nav_shell_padding,14px);
	--dp-nav-shell-shadow:var(--dp-nav_shell_shadow,0 22px 58px color-mix(in srgb,#0f172a 9%,transparent));
	--dp-nav-brand-bg:var(--dp-nav_brand_bg,color-mix(in srgb,var(--dp-surface_muted) 46%,transparent));
	--dp-nav-brand-border:var(--dp-nav_brand_border,color-mix(in srgb,var(--dp-border) 58%,transparent));
	--dp-nav-search-bg:var(--dp-nav_search_bg,color-mix(in srgb,var(--dp-control_bg,var(--dp-surface)) 92%,transparent));
	--dp-nav-current-bg:var(--dp-nav_current_bg,color-mix(in srgb,var(--dp-primary-50,#eff6ff) 42%,transparent));
	--dp-nav-current-border:var(--dp-nav_current_border,color-mix(in srgb,var(--dp-primary-600,#2563eb) 18%,var(--dp-border)));
	--dp-nav-section-gap:var(--dp-nav_section_gap,12px);
	--dp-nav-section-border:var(--dp-nav_section_border,color-mix(in srgb,var(--dp-border_soft) 64%,transparent));
	--dp-nav-section-label:var(--dp-nav_section_label,var(--dp-text_muted));
	--dp-nav-item-bg:var(--dp-nav_item_bg,transparent);
	--dp-nav-item-hover-bg:var(--dp-nav_item_hover_bg,color-mix(in srgb,var(--dp-primary-50,#eff6ff) 30%,transparent));
	--dp-nav-item-active-bg:var(--dp-nav_item_active_bg,linear-gradient(135deg,var(--dp-primary-600,#2563eb),color-mix(in srgb,var(--dp-primary-600,#2563eb) 76%,var(--dp-info-600,#0891b2))));
	--dp-nav-item-active-color:var(--dp-nav_item_active_color,#fff);
	--dp-nav-item-border:var(--dp-nav_item_border,transparent);
	--dp-nav-item-radius:var(--dp-nav_item_radius,15px);
	--dp-nav-item-height:var(--dp-nav_item_height,45px);
	--dp-nav-item-padding:var(--dp-nav_item_padding,7px 8px);
	--dp-nav-icon-bg:var(--dp-nav_icon_bg,color-mix(in srgb,var(--dp-neutral_bg,#eef2f7) 78%,transparent));
	--dp-nav-icon-color:var(--dp-nav_icon_color,var(--dp-neutral_text,#344054));
	--dp-nav-icon-active-bg:var(--dp-nav_icon_active_bg,rgba(255,255,255,.20));
	--dp-nav-icon-active-color:var(--dp-nav_icon_active_color,#fff);
	--dp-nav-badge-bg:var(--dp-nav_badge_bg,color-mix(in srgb,var(--dp-surface_muted) 70%,transparent));
	--dp-nav-badge-color:var(--dp-nav_badge_color,var(--dp-text_muted));
	--dp-nav-submenu-indent:var(--dp-nav_submenu_indent,16px);
	--dp-nav-submenu-rail:var(--dp-nav_submenu_rail,color-mix(in srgb,var(--dp-border) 52%,transparent));
}
body[data-dp-theme-mode="dark"] .dp-panel-nav-sidebar{
	--dp-nav_shell_bg:linear-gradient(180deg,color-mix(in srgb,#142033 95%,transparent),color-mix(in srgb,#0d1421 90%,transparent));
	--dp-nav_shell_border:#26364b;
	--dp-nav_brand_bg:color-mix(in srgb,#1a2940 66%,transparent);
	--dp-nav_brand_border:#2e4058;
	--dp-nav_search_bg:#101827;
	--dp-nav_current_bg:linear-gradient(135deg,#1a3355,#111d30);
	--dp-nav_current_border:#34537f;
	--dp-nav_section_border:#27364c;
	--dp-nav_section_label:#9aa8bb;
	--dp-nav_item_hover_bg:color-mix(in srgb,#20314a 58%,transparent);
	--dp-nav_item_active_bg:linear-gradient(135deg,#2666d8,#0d8aa3);
	--dp-nav_icon_bg:#223249;
	--dp-nav_icon_color:#dbe7f8;
	--dp-nav_badge_bg:#223247;
	--dp-nav_badge_color:#d6e0ee;
	--dp-nav_submenu_rail:#2d3e56;
}
@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"] .dp-panel-nav-sidebar{
	--dp-nav_shell_bg:linear-gradient(180deg,color-mix(in srgb,#142033 95%,transparent),color-mix(in srgb,#0d1421 90%,transparent));
	--dp-nav_shell_border:#26364b;
	--dp-nav_brand_bg:color-mix(in srgb,#1a2940 66%,transparent);
	--dp-nav_brand_border:#2e4058;
	--dp-nav_search_bg:#101827;
	--dp-nav_current_bg:linear-gradient(135deg,#1a3355,#111d30);
	--dp-nav_current_border:#34537f;
	--dp-nav_section_border:#27364c;
	--dp-nav_section_label:#9aa8bb;
	--dp-nav_item_hover_bg:color-mix(in srgb,#20314a 58%,transparent);
	--dp-nav_item_active_bg:linear-gradient(135deg,#2666d8,#0d8aa3);
	--dp-nav_icon_bg:#223249;
	--dp-nav_icon_color:#dbe7f8;
	--dp-nav_badge_bg:#223247;
	--dp-nav_badge_color:#d6e0ee;
	--dp-nav_submenu_rail:#2d3e56;
}}
@media(min-width:1181px){
.dp-panel-nav-sidebar{grid-template-columns:minmax(260px,var(--dp-nav-width)) minmax(0,1fr);column-gap:var(--dp-nav-gap)}
.dp-panel-nav-sidebar .dp-panel-sidebar{position:sticky;top:16px;display:grid;align-content:start;gap:var(--dp-nav-section-gap);max-height:calc(100dvh - 32px);overflow:auto;padding:var(--dp-nav-shell-padding);border:1px solid var(--dp-nav-shell-border);border-radius:var(--dp-nav-shell-radius);background:var(--dp-nav-shell-bg);box-shadow:var(--dp-nav-shell-shadow);backdrop-filter:blur(18px)}
.dp-panel-nav-sidebar .dp-panel-sidebar-top{display:grid;grid-template-columns:minmax(0,1fr) 42px;gap:10px;align-items:center;margin:0;padding:0;border:0;background:transparent}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand{display:grid;grid-template-columns:42px minmax(0,1fr);grid-template-areas:"icon name" "icon tag";align-items:center;gap:0 10px;min-height:56px;padding:8px 10px;border:1px solid var(--dp-nav-brand-border);border-radius:18px;background:var(--dp-nav-brand-bg);box-shadow:none;text-decoration:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand>span{grid-area:icon;width:42px;height:42px;border-radius:15px}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand strong{grid-area:name;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:14px;line-height:1.15}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand small{grid-area:tag;display:block;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;line-height:1.25}
.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle]{width:42px;height:42px;min-height:42px;border-radius:16px;background:var(--dp-nav-brand-bg);border:1px solid var(--dp-nav-brand-border);color:var(--dp-text);box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-search{display:grid;position:relative;top:auto;margin:0;padding:0;background:transparent;border:0;border-radius:0;backdrop-filter:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-search:before{left:13px;top:50%;transform:translateY(-50%);width:15px;height:15px;opacity:.72}
.dp-panel-nav-sidebar .dp-panel-sidebar-search input{height:44px;min-height:44px;padding:0 42px 0 39px;border:1px solid var(--dp-nav-brand-border);border-radius:17px;background:var(--dp-nav-search-bg);box-shadow:none;color:var(--dp-text);font-size:13px}
.dp-panel-nav-sidebar .dp-panel-sidebar-context{display:grid;grid-template-columns:minmax(0,1fr);gap:3px;margin:0;padding:12px 13px;border:1px solid var(--dp-nav-current-border);border-radius:19px;background:var(--dp-nav-current-bg);box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-context span{display:block;min-height:0;padding:0;background:transparent;color:var(--dp-primary-700,#175cd3);font-size:10px;font-weight:950;letter-spacing:.08em;text-transform:uppercase}
.dp-panel-nav-sidebar .dp-panel-sidebar-context strong{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--dp-text);font-size:14px;line-height:1.18;font-weight:900}
.dp-panel-nav-sidebar .dp-panel-sidebar-context small{justify-self:start;min-width:0;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--dp-text_muted);font-size:11px;line-height:1.2;font-weight:720}
.dp-panel-nav-sidebar .dp-panel-sidebar-nav{display:grid;gap:8px;overflow:visible;padding:0}
.dp-panel-nav-sidebar .dp-panel-sidebar-group{position:relative;display:grid;grid-template-columns:1fr;gap:4px;margin:0;padding:12px 0 0;border:0;border-top:1px solid var(--dp-nav-section-border);border-radius:0;background:transparent;box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:26px;margin:0;padding:0 7px;color:var(--dp-nav-section-label);font-size:10px;font-weight:950;letter-spacing:.08em;text-transform:uppercase}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2>span,.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button>b{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:20px;border-radius:999px;background:var(--dp-nav-badge-bg);color:var(--dp-nav-badge-color);padding:0 7px;font-size:10px;line-height:1}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button{display:flex;align-items:center;justify-content:space-between;width:100%;min-height:26px;border:0;border-radius:12px;background:transparent;color:inherit;padding:0;box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button>span{display:block;min-width:0;height:auto;border-radius:0;background:transparent;color:inherit;padding:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.2}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button>i{flex:0 0 auto;margin-inline:2px}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button:hover{background:transparent;color:var(--dp-text)}
.dp-panel-nav-sidebar .dp-panel-sidebar-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px;min-width:0}
.dp-panel-nav-sidebar .dp-panel-sidebar-link,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary{display:grid;grid-template-columns:34px minmax(0,1fr) auto;align-items:center;gap:9px;width:100%;min-width:0;min-height:var(--dp-nav-item-height);border:1px solid var(--dp-nav-item-border);border-radius:var(--dp-nav-item-radius);background:var(--dp-nav-item-bg);color:var(--dp-nav-item-color,var(--dp-text));padding:var(--dp-nav-item-padding);box-shadow:none;text-decoration:none;transform:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-link:hover,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary:hover{border-color:transparent;background:var(--dp-nav-item-hover-bg);box-shadow:none;transform:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu.active>summary{border-color:transparent;background:var(--dp-nav-item-active-bg);color:var(--dp-nav-item-active-color);box-shadow:0 14px 30px color-mix(in srgb,var(--dp-primary-600,#2563eb) 18%,transparent)}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active:before,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu.active>summary:before{content:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-icon{width:34px;height:34px;border-radius:13px;background:var(--dp-nav-icon-bg);color:var(--dp-nav-icon-color);font-size:10px}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-icon,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu.active>summary .dp-panel-sidebar-icon{background:var(--dp-nav-icon-active-bg);color:var(--dp-nav-icon-active-color)}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy{min-width:0;display:grid;gap:1px}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy strong{display:block;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:inherit;font-size:13px;line-height:1.16;font-weight:870}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy small{display:block;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:inherit;font-size:10.5px;line-height:1.2;font-weight:650;opacity:.78}
.dp-panel-nav-sidebar .dp-panel-sidebar-badge{justify-self:end;min-width:22px;height:21px;border:0;border-radius:999px;background:var(--dp-nav-badge-bg);color:var(--dp-nav-badge-color);padding:0 7px;font-size:10px;line-height:1;font-weight:950;box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-badge,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu.active>summary .dp-panel-sidebar-badge{background:rgba(255,255,255,.22);color:#fff}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu{display:grid;gap:3px;border:0;border-radius:0;background:transparent;padding:0;box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary{grid-template-columns:34px minmax(0,1fr) auto 12px;list-style:none;cursor:pointer}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary>i{width:8px;height:8px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg);opacity:.56}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu[open]>summary>i{transform:rotate(225deg)}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu-items{display:grid;gap:3px;margin:3px 0 3px var(--dp-nav-submenu-indent);padding:2px 0 2px 11px;border-left:1px solid var(--dp-nav-submenu-rail)}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu-depth-1 .dp-panel-sidebar-submenu-items{margin-left:12px;padding-left:10px}
.dp-panel-nav-sidebar .dp-panel-sidebar-link-parent{border-color:transparent;border-style:solid;background:transparent}
.dp-panel-nav-sidebar .dp-panel-sidebar-pin{width:26px;height:26px;align-self:center;border-radius:10px;background:transparent;border-color:transparent;color:var(--dp-text_muted);box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-item:hover .dp-panel-sidebar-pin,.dp-panel-nav-sidebar .dp-panel-sidebar-pin:hover{background:var(--dp-nav-item-hover-bg);border-color:transparent;color:var(--dp-text)}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed{grid-template-columns:88px minmax(0,1fr)}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar{padding:10px;border-radius:22px;overflow:visible}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-top{grid-template-columns:1fr}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand{grid-template-columns:42px;grid-template-areas:"icon";justify-content:center;padding:8px}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand strong,.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand small,.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-search,.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-context,.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-nav{display:none}
}
@media(max-width:1180px){
.dp-panel-nav-sidebar .dp-panel-sidebar{background:var(--dp-nav-shell-bg);border-color:var(--dp-nav-shell-border)}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand{background:var(--dp-nav-brand-bg);border-color:var(--dp-nav-brand-border)}
.dp-panel-nav-sidebar .dp-panel-sidebar-context{background:var(--dp-nav-current-bg);border-color:var(--dp-nav-current-border)}
.dp-panel-nav-sidebar .dp-panel-sidebar-nav{gap:8px}
.dp-panel-nav-sidebar .dp-panel-sidebar-group{background:transparent;border-color:var(--dp-nav-section-border);box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-link,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary{background:var(--dp-nav-item-bg);border-color:transparent;box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-link:hover,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary:hover{background:var(--dp-nav-item-hover-bg)}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu.active>summary{background:var(--dp-nav-item-active-bg);color:var(--dp-nav-item-active-color)}
.dp-panel-nav-sidebar .dp-panel-sidebar-group-collapsed>*:not(h2){display:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-searching .dp-panel-sidebar-group-collapsed>*:not(h2){display:grid}
.dp-panel-nav-sidebar .dp-panel-sidebar-group-collapsed h2 button>i{transform:rotate(-45deg)}
}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button>span{display:block;min-width:0;height:auto;border-radius:0;background:transparent;color:inherit;padding:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.2}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2>span,.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button>b{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:20px;border-radius:999px;background:var(--dp-nav-badge-bg);color:var(--dp-nav-badge-color);padding:0 7px;font-size:10px;line-height:1}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button>i{flex:0 0 auto;margin-inline:2px}
.dp-panel-nav-sidebar .dp-panel-sidebar-group-collapsed>*:not(h2){display:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-searching .dp-panel-sidebar-group-collapsed>*:not(h2){display:grid}
.dp-panel-nav-sidebar .dp-panel-sidebar-group-collapsed h2 button>i{transform:rotate(-45deg)}
body[data-dp-theme-effects~="glass"]{background:var(--dp-body_bg);background-attachment:fixed}
body[data-dp-theme-effects~="glass"]:before{content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(circle at 20% 18%,rgba(255,255,255,.34),transparent 20rem),radial-gradient(circle at 84% 12%,rgba(255,255,255,.20),transparent 24rem);opacity:.92}
body[data-dp-theme-effects~="glass"] .dp-panel-main-region>header,
body[data-dp-theme-effects~="glass"] .dp-panel-card,
body[data-dp-theme-effects~="glass"] .dp-panel-widget,
body[data-dp-theme-effects~="glass"] .dp-panel-page-table,
body[data-dp-theme-effects~="glass"] .dp-panel-table-shell,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar,
body[data-dp-theme-effects~="glass"] .dp-panel-filter-chips,
body[data-dp-theme-effects~="glass"] .dp-panel-filter-panel,
body[data-dp-theme-effects~="glass"] .dp-panel-form-section,
body[data-dp-theme-effects~="glass"] .dp-panel-form-details,
body[data-dp-theme-effects~="glass"] .dp-panel-show,
body[data-dp-theme-effects~="glass"] .dp-panel-show-field,
body[data-dp-theme-effects~="glass"] .dp-panel-record-pulse,
body[data-dp-theme-effects~="glass"] .dp-panel-table-pulse,
body[data-dp-theme-effects~="glass"] .dp-panel-board-pulse,
body[data-dp-theme-effects~="glass"] .dp-panel-form-pulse,
body[data-dp-theme-effects~="glass"] .dp-panel-relation,
body[data-dp-theme-effects~="glass"] .dp-panel-alert-card,
body[data-dp-theme-effects~="glass"] .dp-panel-links,
body[data-dp-theme-effects~="glass"] .dp-panel-contacts,
body[data-dp-theme-effects~="glass"] .dp-panel-locations,
body[data-dp-theme-effects~="glass"] .dp-panel-tags,
body[data-dp-theme-effects~="glass"] .dp-panel-items,
body[data-dp-theme-effects~="glass"] .dp-panel-totals,
body[data-dp-theme-effects~="glass"] .dp-panel-approvals,
body[data-dp-theme-effects~="glass"] .dp-panel-tasks,
body[data-dp-theme-effects~="glass"] .dp-panel-activity,
body[data-dp-theme-effects~="glass"] .dp-panel-changes,
body[data-dp-theme-effects~="glass"] .dp-panel-payments,
body[data-dp-theme-effects~="glass"] .dp-panel-shipments,
body[data-dp-theme-effects~="glass"] .dp-panel-attachments,
body[data-dp-theme-effects~="glass"] .dp-panel-messages,
body[data-dp-theme-effects~="glass"] .dp-panel-notes,
body[data-dp-theme-effects~="glass"] .dp-panel-modal,
body[data-dp-theme-effects~="glass"] .dp-panel-command{
	background:var(--dp-glass_surface_bg);
	border-color:var(--dp-glass_border);
	box-shadow:var(--dp-glass_shadow);
	backdrop-filter:var(--dp-glass_blur);
	-webkit-backdrop-filter:var(--dp-glass_blur);
}
body[data-dp-theme-effects~="glass"] .dp-panel-sidebar,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-nav,
body[data-dp-theme-effects~="glass"] .dp-panel-live-control,
body[data-dp-theme-effects~="glass"] .dp-panel-theme-toggle,
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta,
body[data-dp-theme-effects~="glass"] .dp-panel-pagination,
body[data-dp-theme-effects~="glass"] .dp-panel-bulk-bar{
	background:var(--dp-glass_surface_strong_bg);
	border-color:var(--dp-glass_border);
	box-shadow:var(--dp-glass_shadow_soft);
	backdrop-filter:var(--dp-glass_blur);
	-webkit-backdrop-filter:var(--dp-glass_blur);
}
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll,
body[data-dp-theme-effects~="glass"] .dp-panel-table,
body[data-dp-theme-effects~="glass"] .dp-panel-board-column,
body[data-dp-theme-effects~="glass"] .dp-panel-board-card,
body[data-dp-theme-effects~="glass"] .dp-panel-summary,
body[data-dp-theme-effects~="glass"] .dp-panel-insight,
body[data-dp-theme-effects~="glass"] .dp-panel-link,
body[data-dp-theme-effects~="glass"] .dp-panel-contact,
body[data-dp-theme-effects~="glass"] .dp-panel-location,
body[data-dp-theme-effects~="glass"] .dp-panel-item,
body[data-dp-theme-effects~="glass"] .dp-panel-total,
body[data-dp-theme-effects~="glass"] .dp-panel-task,
body[data-dp-theme-effects~="glass"] .dp-panel-payment,
body[data-dp-theme-effects~="glass"] .dp-panel-shipment,
body[data-dp-theme-effects~="glass"] .dp-panel-attachment,
body[data-dp-theme-effects~="glass"] .dp-panel-message,
body[data-dp-theme-effects~="glass"] .dp-panel-note,
body[data-dp-theme-effects~="glass"] .dp-panel-row-preview dl div,
body[data-dp-theme-effects~="glass"] .dp-panel-shortcut-group{
	background:var(--dp-glass_surface_muted_bg);
	border-color:var(--dp-glass_border);
	box-shadow:var(--dp-glass_shadow_soft);
	backdrop-filter:var(--dp-glass_blur);
	-webkit-backdrop-filter:var(--dp-glass_blur);
}
body[data-dp-theme-effects~="glass"] .dp-panel-table th,
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr,
body[data-dp-theme-effects~="glass"] .dp-panel-table td.dp-panel-actions,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll th.dp-panel-actions,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll td.dp-panel-actions{
	background:color-mix(in srgb,var(--dp-surface) 72%,transparent);
}
body[data-dp-theme-effects~="glass"] .dp-panel-table th.dp-panel-actions,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll th.dp-panel-actions{
	position:relative;
	right:auto;
	z-index:auto;
	min-width:0;
	background:color-mix(in srgb,var(--dp-surface) 72%,transparent);
	box-shadow:none;
	backdrop-filter:none;
	-webkit-backdrop-filter:none;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table th.dp-panel-actions:before,
body[data-dp-theme-effects~="glass"] .dp-panel-table th.dp-panel-actions:after,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll th.dp-panel-actions:before,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll th.dp-panel-actions:after{
	content:none;
	display:none;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table th.dp-panel-actions+th,
body[data-dp-theme-effects~="glass"] .dp-panel-table th:has(+ th.dp-panel-actions){
	box-shadow:none;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll tr:hover td.dp-panel-actions{
	background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 32%,var(--dp-surface));
}
body[data-dp-theme-effects~="glass"] .dp-panel-search input,
body[data-dp-theme-effects~="glass"] .dp-panel-global-search input,
body[data-dp-theme-effects~="glass"] .dp-panel-filter input,
body[data-dp-theme-effects~="glass"] .dp-panel-filter select,
body[data-dp-theme-effects~="glass"] .dp-panel-field input,
body[data-dp-theme-effects~="glass"] .dp-panel-field select,
body[data-dp-theme-effects~="glass"] .dp-panel-field textarea,
body[data-dp-theme-effects~="glass"] .dp-panel-sidebar-search input{
	background:var(--dp-control_bg);
	border-color:var(--dp-control_border);
	backdrop-filter:var(--dp-glass_blur);
	-webkit-backdrop-filter:var(--dp-glass_blur);
}
body[data-dp-theme-effects~="glass"] .dp-panel-button-secondary,
body[data-dp-theme-effects~="glass"] .dp-panel-action-neutral,
body[data-dp-theme-effects~="glass"] .dp-panel-row-link,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker summary,
body[data-dp-theme-effects~="glass"] .dp-panel-density,
body[data-dp-theme-effects~="glass"] .dp-panel-table-view,
body[data-dp-theme-effects~="glass"] .dp-panel-filter-trigger{
	background:var(--dp-glass_surface_muted_bg);
	border-color:var(--dp-glass_border);
	box-shadow:none;
}
body[data-dp-theme-effects~="glass"] .dp-panel-main-region>header,
body[data-dp-theme-effects~="glass"] .dp-panel-card,
body[data-dp-theme-effects~="glass"] .dp-panel-widget,
body[data-dp-theme-effects~="glass"] .dp-panel-page-table,
body[data-dp-theme-effects~="glass"] .dp-panel-table-shell,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar,
body[data-dp-theme-effects~="glass"] .dp-panel-form-section,
body[data-dp-theme-effects~="glass"] .dp-panel-show-field,
body[data-dp-theme-effects~="glass"] .dp-panel-modal,
body[data-dp-theme-effects~="glass"] .dp-panel-command,
body[data-dp-theme-effects~="glass"] .dp-panel-sidebar,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-nav{
	position:relative;
	overflow:hidden;
	box-shadow:var(--dp-glass_shadow),var(--dp-glass_edge);
}
body[data-dp-theme-effects~="glass"] .dp-panel-main-region>header:after,
body[data-dp-theme-effects~="glass"] .dp-panel-card:after,
body[data-dp-theme-effects~="glass"] .dp-panel-widget:after,
body[data-dp-theme-effects~="glass"] .dp-panel-page-table:after,
body[data-dp-theme-effects~="glass"] .dp-panel-table-shell:after,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar:after,
body[data-dp-theme-effects~="glass"] .dp-panel-form-section:after,
body[data-dp-theme-effects~="glass"] .dp-panel-show-field:after,
body[data-dp-theme-effects~="glass"] .dp-panel-modal:after,
body[data-dp-theme-effects~="glass"] .dp-panel-command:after,
body[data-dp-theme-effects~="glass"] .dp-panel-sidebar:after,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-nav:after{
	content:"";
	position:absolute;
	inset:0;
	z-index:0;
	pointer-events:none;
	background:var(--dp-glass_highlight);
	opacity:.82;
}
body[data-dp-theme-effects~="glass"] .dp-panel-main-region>header>*,

body[data-dp-theme-effects~="glass"] .dp-panel-widget>*,
body[data-dp-theme-effects~="glass"] .dp-panel-page-table>*,
body[data-dp-theme-effects~="glass"] .dp-panel-table-shell>*,
body[data-dp-theme-effects~="glass"] .dp-panel-commandbar>*,
body[data-dp-theme-effects~="glass"] .dp-panel-form-section>*,
body[data-dp-theme-effects~="glass"] .dp-panel-show-field>*,
body[data-dp-theme-effects~="glass"] .dp-panel-modal>*,
body[data-dp-theme-effects~="glass"] .dp-panel-command>*,
body[data-dp-theme-effects~="glass"] .dp-panel-sidebar>*,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-nav>*{
	position:relative;
	z-index:1;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-root,
body[data-dp-theme-effects~="glass"] .dp-panel-unsaved-root,
body[data-dp-theme-effects~="glass"] .dp-panel-command-root{
	background:var(--dp-glass_overlay_bg);
	backdrop-filter:blur(16px) saturate(1.08);
	-webkit-backdrop-filter:blur(16px) saturate(1.08);
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-header,
body[data-dp-theme-effects~="glass"] .dp-panel-modal-body,
body[data-dp-theme-effects~="glass"] .dp-panel-modal-body>.dp-panel-form>.dp-panel-toolbar:last-child,
body[data-dp-theme-effects~="glass"] .dp-panel-command-footer,
body[data-dp-theme-effects~="glass"] .dp-panel-command-list{
	background:transparent;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-header,
body[data-dp-theme-effects~="glass"] .dp-panel-command-footer,
body[data-dp-theme-effects~="glass"] .dp-panel-table th,
body[data-dp-theme-effects~="glass"] .dp-panel-table td,
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta,
body[data-dp-theme-effects~="glass"] .dp-panel-pagination{
	border-color:color-mix(in srgb,var(--dp-glass_border) 78%,transparent);
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker form,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu>div,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-menu,
body[data-dp-theme-effects~="glass"] .dp-panel-command-item,
body[data-dp-theme-effects~="glass"] .dp-panel-toast,
body[data-dp-theme-effects~="glass"] .dp-panel-unsaved-dialog{
	background:var(--dp-glass_menu_bg);
	border-color:var(--dp-glass_border);
	box-shadow:var(--dp-glass_shadow_lifted);
	backdrop-filter:var(--dp-glass_blur);
	-webkit-backdrop-filter:var(--dp-glass_blur);
}
body[data-dp-theme-effects~="glass"] .dp-panel-search input,
body[data-dp-theme-effects~="glass"] .dp-panel-global-search input,
body[data-dp-theme-effects~="glass"] .dp-panel-filter input,
body[data-dp-theme-effects~="glass"] .dp-panel-filter select,
body[data-dp-theme-effects~="glass"] .dp-panel-field input,
body[data-dp-theme-effects~="glass"] .dp-panel-field select,
body[data-dp-theme-effects~="glass"] .dp-panel-field textarea,
body[data-dp-theme-effects~="glass"] .dp-panel-per-page select,
body[data-dp-theme-effects~="glass"] .dp-panel-theme-select select,
body[data-dp-theme-effects~="glass"] .dp-panel-sidebar-search input{
	background:var(--dp-glass_control_bg);
	box-shadow:var(--dp-glass_edge);
}
body[data-dp-theme-effects~="glass"] .dp-panel-button-secondary,
body[data-dp-theme-effects~="glass"] .dp-panel-action-neutral,
body[data-dp-theme-effects~="glass"] .dp-panel-row-link,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker summary,
body[data-dp-theme-effects~="glass"] .dp-panel-density,
body[data-dp-theme-effects~="glass"] .dp-panel-table-view,
body[data-dp-theme-effects~="glass"] .dp-panel-filter-trigger,
body[data-dp-theme-effects~="glass"] .dp-panel-theme-select label,
body[data-dp-theme-effects~="glass"] .dp-panel-modal-header-actions>a,
body[data-dp-theme-effects~="glass"] .dp-panel-modal-header-actions>button{
	background:var(--dp-glass_control_bg);
	box-shadow:var(--dp-glass_edge);
	backdrop-filter:var(--dp-glass_blur);
	-webkit-backdrop-filter:var(--dp-glass_blur);
}
body[data-dp-theme-effects~="glass"] .dp-panel-button:not(.dp-panel-button-secondary),
body[data-dp-theme-effects~="glass"] .dp-panel-action-primary,
body[data-dp-theme-effects~="glass"] .dp-panel-action-success,
body[data-dp-theme-effects~="glass"] .dp-panel-action-warning,
body[data-dp-theme-effects~="glass"] .dp-panel-action-danger{
	box-shadow:0 14px 34px color-mix(in srgb,currentColor 18%,transparent),inset 0 1px 0 rgba(255,255,255,.24);
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-card:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-show-field:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-board-card:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:hover{
	box-shadow:var(--dp-glass_shadow_lifted),var(--dp-glass_edge);
}
body[data-dp-theme-effects~="glass"] .dp-panel-table{
	border-collapse:separate;
	border-spacing:0;
	overflow:hidden;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:nth-child(odd){
	background:color-mix(in srgb,var(--dp-glass_surface_muted_bg) 72%,transparent);
}
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:nth-child(even){
	background:color-mix(in srgb,var(--dp-glass_surface_bg) 42%,transparent);
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-link,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-link,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-group>summary{
	border-color:color-mix(in srgb,var(--dp-glass_border) 46%,transparent);
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-link:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-link:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-group>summary:hover{
	box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge);
}
body[data-dp-theme-effects~="glass"] .dp-panel-badge,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-badge,
body[data-dp-theme-effects~="glass"] .dp-panel-sidebar-badge,
body[data-dp-theme-effects~="glass"] .dp-panel-table-view small,
body[data-dp-theme-effects~="glass"] .dp-panel-live-control small{
	background:color-mix(in srgb,var(--dp-glass_control_bg) 84%,transparent);
	border:1px solid color-mix(in srgb,var(--dp-glass_border) 62%,transparent);
	box-shadow:var(--dp-glass_edge);
}
@supports not ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){
	body[data-dp-theme-effects~="glass"]{--dp-glass_surface_bg:var(--dp-surface);--dp-glass_surface_strong_bg:var(--dp-surface);--dp-glass_surface_muted_bg:var(--dp-surface_muted)}
}
.dp-panel-with-navigation{--dp-nav-mode-edge-radius:0 24px 24px 0;--dp-nav-mode-edge-shadow:0 18px 54px color-mix(in srgb,#0f172a 13%,transparent);--dp-nav-mode-top:12px}
.dp-panel-nav-mode-floating .dp-panel-sidebar-top{z-index:36}
.dp-panel-nav-mode-floating .dp-panel-sidebar-search{z-index:28}
.dp-panel-nav-mode-docked .dp-panel-sidebar,.dp-panel-nav-mode-docked .dp-panel-horizontal-nav{box-shadow:none}
.dp-panel-nav-mode-docked .dp-panel-sidebar{top:var(--dp-nav-mode-top);max-height:calc(100dvh - (var(--dp-nav-mode-top) * 2))}
.dp-panel-nav-mode-docked .dp-panel-sidebar-top{z-index:36}
.dp-panel-nav-mode-docked .dp-panel-sidebar-search{z-index:28}
.dp-panel-nav-mode-edge{--dp-nav-mode-top:0px}
.dp-panel-nav-mode-edge.dp-panel-nav-sidebar{padding-top:0;padding-left:0}
.dp-panel-nav-mode-edge.dp-panel-nav-sidebar .dp-panel-main-region{padding-top:18px;padding-right:clamp(14px,2vw,30px)}
.dp-panel-nav-mode-edge .dp-panel-sidebar{position:sticky;top:0;height:100dvh;max-height:100dvh;margin:0;border-left:0;border-radius:var(--dp-nav-mode-edge-radius);box-shadow:var(--dp-nav-mode-edge-shadow)}
.dp-panel-nav-mode-edge .dp-panel-sidebar-top{position:sticky;top:0;z-index:42;border-radius:0 24px 0 0}
.dp-panel-nav-mode-edge .dp-panel-sidebar-search{position:sticky;top:76px;z-index:34}
.dp-panel-nav-mode-edge .dp-panel-sidebar-context{position:relative;z-index:18}
.dp-panel-nav-mode-edge .dp-panel-sidebar-nav{position:relative;z-index:10}
.dp-panel-nav-mode-edge.dp-panel-nav-horizontal{padding-top:0}
.dp-panel-nav-mode-edge .dp-panel-horizontal-nav{order:-1;position:sticky;top:0;z-index:140;width:100vw;max-width:100vw;margin:0 calc(50% - 50vw) 12px;border-left:0;border-right:0;border-radius:0;box-shadow:0 12px 32px color-mix(in srgb,#0f172a 10%,transparent)}
.dp-panel-nav-mode-edge .dp-panel-horizontal-group[open]>div{max-width:calc(100vw - 20px)}
.dp-panel-nav-mode-overlay .dp-panel-sidebar{position:sticky;top:12px;z-index:120}
.dp-panel-nav-mode-overlay .dp-panel-horizontal-nav{z-index:150}
.dp-panel-header,.dp-panel-footer{position:relative;min-width:0}
.dp-panel-header{isolation:isolate}
.dp-panel-footer{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:14px;border:1px solid var(--dp-border);border-radius:20px;background:color-mix(in srgb,var(--dp-surface) 94%,transparent);box-shadow:var(--dp-ui-shadow-soft,0 14px 34px rgba(15,23,42,.065));padding:14px 18px;color:var(--dp-text)}
.dp-panel-footer-status{display:grid;gap:2px;min-width:0}.dp-panel-footer-status strong{font-size:13px;font-weight:950}.dp-panel-footer-status span{color:var(--dp-text_muted);font-size:12px;font-weight:760}.dp-panel-footer-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.dp-panel-header-mode-docked .dp-panel-main-region>.dp-panel-header,.dp-panel-footer-mode-docked .dp-panel-main-region>.dp-panel-footer{box-shadow:none}
.dp-panel-header-mode-docked .dp-panel-main-region>.dp-panel-header{margin-bottom:2px}
.dp-panel-footer-mode-docked .dp-panel-main-region>.dp-panel-footer{margin-top:2px;background:var(--dp-surface)}







body[data-dp-theme-effects~="glass"] .dp-panel-header,
body[data-dp-theme-effects~="glass"] .dp-panel-footer{background:var(--dp-glass_surface_strong_bg);border-color:var(--dp-glass_border);box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge);backdrop-filter:var(--dp-glass_blur);-webkit-backdrop-filter:var(--dp-glass_blur)}
body[data-dp-theme-effects~="brutalist"] .dp-panel-header,
body[data-dp-theme-effects~="brutalist"] .dp-panel-footer{border-radius:0;box-shadow:6px 6px 0 #111}
@media(max-width:1180px){.dp-panel-nav-mode-edge.dp-panel-nav-sidebar{padding:8px}.dp-panel-nav-mode-edge .dp-panel-sidebar{position:sticky;top:0;height:auto;max-height:none;border-left:1px solid color-mix(in srgb,var(--dp-border) 82%,transparent);border-radius:20px}.dp-panel-nav-mode-edge .dp-panel-sidebar-top{border-radius:18px 18px 0 0}.dp-panel-nav-mode-edge .dp-panel-sidebar-search{position:relative;top:auto;z-index:28}.dp-panel-nav-mode-edge.dp-panel-nav-sidebar .dp-panel-main-region{padding-top:0;padding-right:0}}
@media(max-width:820px){.dp-panel-footer{grid-template-columns:1fr}.dp-panel-footer-actions{justify-content:flex-start}.dp-panel-header-mode-edge:not(.dp-panel-nav-sidebar) .dp-panel-main-region>.dp-panel-header,.dp-panel-footer-mode-edge:not(.dp-panel-nav-sidebar) .dp-panel-main-region>.dp-panel-footer{margin-inline:calc(50% - 50vw)}}
@media(max-width:720px){.dp-panel-nav-mode-edge .dp-panel-horizontal-nav{margin-inline:calc(50% - 50vw);padding-inline:8px}.dp-panel-nav-mode-edge .dp-panel-horizontal-group[open]>div{left:10px;right:10px;width:auto}}
CSS;
	}

	/**
	 * Returns sticky header/footer and attached chrome stylesheet rules.
	 *
	 * The block coordinates renderer-owned chrome classes with navigation mode
	 * offsets, sticky stack ordering, horizontal menu overflow, and document
	 * clipping so headers, footers, and navigation surfaces can attach to viewport
	 * edges without hiding modal or menu affordances.
	 *
	 * @return string CSS emitted for header, footer, and sticky chrome attachment.
	 */
	private static function chromeAttachmentCss(): string {
		return <<<'CSS'
.dp-panel-header-mode-edge:not(.dp-panel-nav-sidebar) .dp-panel-main-region>.dp-panel-header{position:sticky;top:0;z-index:130;width:100vw;max-width:100vw;margin:0 calc(50% - 50vw) 14px;border-left:0;border-right:0;border-radius:0}
.dp-panel-header-mode-edge.dp-panel-nav-horizontal.dp-panel-nav-mode-edge .dp-panel-main-region>.dp-panel-header{top:var(--dp-panel-horizontal-nav-offset,68px)}
.dp-panel-header-mode-edge.dp-panel-nav-sidebar .dp-panel-main-region>.dp-panel-header{position:sticky;top:0;z-index:90;border-top-left-radius:0;border-top-right-radius:0}
.dp-panel-header-mode-docked .dp-panel-main-region>.dp-panel-header{position:relative;box-shadow:none}
.dp-panel-header-mode-overlay .dp-panel-main-region>.dp-panel-header{position:sticky;top:12px;z-index:180;box-shadow:0 24px 70px color-mix(in srgb,#0f172a 16%,transparent)}
.dp-panel-footer-mode-edge:not(.dp-panel-nav-sidebar) .dp-panel-main-region>.dp-panel-footer{position:sticky;bottom:0;z-index:110;width:100vw;max-width:100vw;margin:14px calc(50% - 50vw) 0;border-left:0;border-right:0;border-bottom:0;border-radius:20px 20px 0 0}
.dp-panel-footer-mode-edge.dp-panel-nav-sidebar .dp-panel-main-region>.dp-panel-footer{position:sticky;bottom:0;z-index:80;border-bottom-left-radius:0;border-bottom-right-radius:0}
.dp-panel-footer-mode-docked .dp-panel-main-region>.dp-panel-footer{position:relative;box-shadow:none}
.dp-panel-footer-mode-overlay .dp-panel-main-region>.dp-panel-footer{position:sticky;bottom:12px;z-index:170;box-shadow:0 24px 70px color-mix(in srgb,#0f172a 16%,transparent)}
html:has(body.dp-panel-has-sticky-chrome),body.dp-panel-has-sticky-chrome{overflow-x:clip;overflow-y:visible}
.dp-panel-horizontal-nav:has(.dp-panel-horizontal-group[open]),.dp-panel-horizontal-track:has(.dp-panel-horizontal-group[open]),.dp-panel-horizontal-nav-menu-open,.dp-panel-horizontal-track-menu-open{overflow:visible}
.dp-panel-horizontal-nav-menu-open{z-index:16000}
.dp-panel-nav-mode-edge .dp-panel-horizontal-nav{order:-1;position:sticky;top:0;z-index:140}
.dp-panel-nav-sticky .dp-panel-sidebar{position:sticky;top:var(--dp-panel-nav-sticky-top,var(--dp-nav-mode-top,12px));z-index:120;align-self:start}
.dp-panel-nav-sticky.dp-panel-nav-horizontal .dp-panel-horizontal-nav{position:sticky;top:var(--dp-panel-nav-sticky-top,0px);z-index:160;align-self:start}
.dp-panel-header-sticky .dp-panel-main-region>.dp-panel-header{position:sticky;top:var(--dp-panel-header-sticky-top,12px);z-index:130;align-self:start}
.dp-panel-nav-sticky.dp-panel-nav-horizontal.dp-panel-header-sticky:not(.dp-panel-nav-mode-edge) .dp-panel-horizontal-nav{top:var(--dp-panel-sticky-header-stack,256px)}
.dp-panel-nav-sticky.dp-panel-nav-horizontal.dp-panel-nav-mode-edge.dp-panel-header-sticky .dp-panel-main-region>.dp-panel-header{top:calc(var(--dp-panel-nav-sticky-top,0px) + var(--dp-panel-horizontal-nav-offset,68px))}
.dp-panel-footer-sticky .dp-panel-main-region>.dp-panel-footer{position:sticky;bottom:var(--dp-panel-footer-sticky-bottom,12px);z-index:120;align-self:end}
.dp-panel-nav-sticky.dp-panel-nav-sidebar.dp-panel-header-sticky .dp-panel-main-region>.dp-panel-header{top:var(--dp-panel-header-sticky-top,12px)}
.dp-panel-nav-sticky.dp-panel-nav-horizontal .dp-panel-horizontal-group[open]>div{z-index:161}
CSS;
	}

}
