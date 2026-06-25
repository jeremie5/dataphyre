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
.dp-panel-modal-header{position:relative!important;padding-right:max(var(--dp-modal-pad,16px),68px)!important}
.dp-panel-modal-header-actions{position:static!important;padding-right:42px!important}
.dp-panel-modal-close{position:absolute!important;top:12px!important;right:12px!important;z-index:8!important}
@media(max-width:760px){.dp-panel-modal-header{padding-right:58px!important}.dp-panel-modal-header-actions{justify-content:flex-start!important;flex-wrap:nowrap!important;max-width:100%!important;overflow-x:auto!important;padding-right:0!important}.dp-panel-modal-close{top:10px!important;right:10px!important}}
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
	--dp-nav-section-active-rail:linear-gradient(180deg,var(--dp-primary-600,#2563eb),var(--dp-info-600,#0891b2));
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
.dp-panel-nav-sidebar{grid-template-columns:minmax(250px,var(--dp-nav-width)) minmax(0,1fr)!important;column-gap:var(--dp-nav-gap)!important}
.dp-panel-nav-sidebar .dp-panel-main-region{padding-right:var(--dp-panel-main-region-pad-right)!important}
.dp-panel-nav-sidebar .dp-panel-footer{margin-inline:calc(-1 * var(--dp-panel-pad-inline,0px))!important;margin-bottom:calc(-1 * var(--dp-panel-pad-bottom,0px))!important;width:calc(100% + var(--dp-panel-pad-inline,0px) + var(--dp-panel-pad-inline,0px))!important}
.dp-panel-nav-sidebar .dp-panel-sidebar{display:flex!important;flex-direction:column!important}
.dp-panel-nav-sidebar .dp-panel-sidebar{gap:var(--dp-nav-section-gap)!important;padding:var(--dp-nav-shell-padding)!important;border:1px solid var(--dp-nav-shell-border)!important;border-radius:var(--dp-nav-shell-radius)!important;background:var(--dp-nav-shell-bg)!important;box-shadow:var(--dp-nav-shell-shadow)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-top{margin:calc(var(--dp-nav-shell-padding) * -1) calc(var(--dp-nav-shell-padding) * -1) 0!important;padding:var(--dp-nav-shell-padding) var(--dp-nav-shell-padding) 8px!important;border-radius:var(--dp-nav-shell-radius) var(--dp-nav-shell-radius) 0 0!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand{border:1px solid var(--dp-nav-brand-border)!important;border-radius:18px!important;background:var(--dp-nav-brand-bg)!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-search{margin:0!important;padding:0!important;background:transparent!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-search input{height:42px!important;border:1px solid var(--dp-nav-brand-border)!important;border-radius:17px!important;background:var(--dp-nav-search-bg)!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-context{border:1px solid var(--dp-nav-current-border)!important;border-radius:18px!important;background:var(--dp-nav-current-bg)!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-context span{background:transparent!important;color:var(--dp-primary-700,#175cd3)!important;padding:0!important;min-height:0!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group{display:grid!important;gap:5px!important;margin:0!important;padding:10px 0 0!important;border:0!important;border-top:1px solid var(--dp-nav-section-border)!important;border-radius:0!important;background:transparent!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group.active:before{left:-8px!important;top:12px!important;bottom:6px!important;background:var(--dp-nav-section-active-rail)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2{padding:0 7px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button{height:28px!important;border-radius:11px!important;background:transparent!important;padding:0!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button:hover{background:transparent!important;color:var(--dp-text)!important}
.dp-panel-sidebar-submenu{background:transparent!important;border:0!important;box-shadow:none!important}
.dp-panel-sidebar-submenu>summary,.dp-panel-nav-sidebar .dp-panel-sidebar-link{min-height:var(--dp-nav-item-height)!important;border:1px solid var(--dp-nav-item-border)!important;border-radius:var(--dp-nav-item-radius)!important;background:var(--dp-nav-item-bg)!important;padding:var(--dp-nav-item-padding)!important;box-shadow:none!important}
.dp-panel-sidebar-submenu>summary:hover,.dp-panel-nav-sidebar .dp-panel-sidebar-link:hover{border-color:var(--dp-nav-item-border)!important;background:var(--dp-nav-item-hover-bg)!important;box-shadow:none!important;transform:none!important}
.dp-panel-sidebar-submenu.active>summary,.dp-panel-nav-sidebar .dp-panel-sidebar-link.active{background:var(--dp-nav-item-active-bg)!important;color:var(--dp-nav-item-active-color)!important;box-shadow:none!important}
.dp-panel-sidebar-submenu.active>summary:before,.dp-panel-nav-sidebar .dp-panel-sidebar-link.active:before{background:var(--dp-primary-600,#2563eb)!important}
.dp-panel-sidebar-icon{background:var(--dp-nav-icon-bg)!important;color:var(--dp-nav-icon-color)!important}
.dp-panel-sidebar-submenu.active>summary .dp-panel-sidebar-icon,.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-icon{background:var(--dp-nav-icon-active-bg)!important;color:var(--dp-nav-icon-active-color)!important}
.dp-panel-sidebar-submenu-items{margin-left:var(--dp-nav-submenu-indent)!important;border-left:1px solid var(--dp-nav-submenu-rail)!important;padding-left:10px!important}
.dp-panel-sidebar-badge{background:var(--dp-nav-badge-bg)!important;color:var(--dp-nav-badge-color)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy strong{font-weight:860!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy small{font-weight:640!important;opacity:.92!important}
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
.dp-panel-sidebar-submenu{display:grid!important;gap:4px!important;border:0!important;border-radius:0!important;background:transparent!important;padding:0!important;box-shadow:none!important}
.dp-panel-sidebar-submenu>summary{display:grid!important;grid-template-columns:34px minmax(0,1fr) auto 14px!important;gap:9px!important;align-items:center!important;min-height:44px!important;border:1px solid transparent!important;border-radius:13px!important;color:var(--dp-text)!important;padding:6px 8px!important;cursor:pointer!important;list-style:none!important}
.dp-panel-sidebar-submenu>summary::-webkit-details-marker{display:none}
.dp-panel-sidebar-submenu>summary>i{width:8px!important;height:8px!important;border-right:2px solid currentColor!important;border-bottom:2px solid currentColor!important;transform:rotate(45deg)!important;opacity:.64!important;transition:transform .14s ease!important}
.dp-panel-sidebar-submenu[open]>summary>i{transform:rotate(225deg)!important}
.dp-panel-sidebar-submenu>summary:hover{border-color:var(--dp-border_soft)!important;background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 28%,transparent)!important}
.dp-panel-sidebar-submenu.active>summary{border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 26%,var(--dp-border))!important;background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 48%,transparent)!important;color:var(--dp-primary-800,#1c4bb3)!important}
.dp-panel-sidebar-submenu-items{display:grid!important;gap:4px!important;margin-left:17px!important;padding:2px 0 4px 12px!important;border-left:1px solid color-mix(in srgb,var(--dp-border) 72%,transparent)!important}
.dp-panel-sidebar-submenu-depth-1 .dp-panel-sidebar-submenu-items{margin-left:14px!important;padding-left:10px!important}
.dp-panel-sidebar-link-parent{background:transparent!important;border-style:dashed!important}
.dp-panel-sidebar-submenu .dp-panel-sidebar-link{min-height:40px!important}
.dp-panel-sidebar-submenu .dp-panel-sidebar-submenu{margin-left:0!important}
.dp-panel-horizontal-submenu{position:relative;display:grid}
.dp-panel-horizontal-submenu>summary{display:grid;grid-template-columns:34px minmax(0,1fr) auto;gap:9px;align-items:center;min-height:48px;border:1px solid transparent;border-radius:14px;color:var(--dp-text);padding:7px 9px;cursor:pointer;list-style:none}
.dp-panel-horizontal-submenu>summary::-webkit-details-marker{display:none}
.dp-panel-horizontal-submenu>summary:hover,.dp-panel-horizontal-submenu.active>summary{border-color:var(--dp-border);background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 44%,var(--dp-surface))}
.dp-panel-horizontal-submenu>summary>span{display:grid;place-items:center;width:34px;height:34px;border-radius:12px;background:var(--dp-neutral_bg,#eef2f7);color:var(--dp-neutral_text,#344054);font-size:10px;font-weight:950}
.dp-panel-horizontal-submenu>summary strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;font-weight:880}
.dp-panel-horizontal-submenu>div{position:relative;display:grid;gap:6px;margin:4px 0 0 22px;padding-left:10px;border-left:1px solid var(--dp-border_soft)}
.dp-panel-horizontal-group[open]{z-index:16001}
.dp-panel-horizontal-group[open]>summary{position:relative;z-index:16002}
.dp-panel-horizontal-group[open]>div{position:fixed!important;left:var(--dp-horizontal-menu-left,10px)!important;right:auto!important;top:var(--dp-horizontal-menu-top,72px)!important;width:var(--dp-horizontal-menu-width,min(360px,86vw))!important;max-height:var(--dp-horizontal-menu-max-height,min(480px,70vh))!important;overflow:auto!important;z-index:16000!important}
@media(max-width:1180px){
.dp-panel-nav-sidebar .dp-panel-sidebar{position:sticky!important;top:8px!important;z-index:18!important;display:grid!important;gap:10px!important;margin:0 0 12px!important;padding:10px!important;border-radius:20px!important;background:color-mix(in srgb,var(--dp-surface) 88%,transparent)!important;border:1px solid color-mix(in srgb,var(--dp-border) 82%,transparent)!important;box-shadow:0 18px 42px rgba(15,23,42,.10)!important;backdrop-filter:blur(18px)!important;overflow:visible!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-top{display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;gap:10px!important;align-items:center!important;margin:0!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand{min-height:50px!important;border-radius:16px!important;padding:8px 10px!important;background:color-mix(in srgb,var(--dp-surface_muted) 64%,var(--dp-surface))!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand>span{width:36px!important;height:36px!important;border-radius:13px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand strong{font-size:14px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand small{display:block!important;font-size:11px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle]{width:46px!important;height:46px!important;border-radius:15px!important;background:var(--dp-primary-600,#2563eb)!important;color:#fff!important;box-shadow:0 12px 26px color-mix(in srgb,var(--dp-primary-600,#2563eb) 24%,transparent)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle] span{width:13px!important;height:13px!important;border-left:2px solid currentColor!important;border-bottom:2px solid currentColor!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;transform:translateX(2px) rotate(45deg)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle] span:before,.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle] span:after{content:none!important}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle]{background:var(--dp-surface_muted)!important;color:var(--dp-text)!important;box-shadow:none!important}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle] span{transform:translateX(-2px) rotate(225deg)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-context{display:grid!important;grid-template-columns:auto minmax(0,1fr) auto!important;align-items:center!important;gap:8px!important;margin:0!important;border:1px solid color-mix(in srgb,var(--dp-primary-600,#2563eb) 22%,var(--dp-border))!important;border-radius:16px!important;background:linear-gradient(135deg,color-mix(in srgb,var(--dp-primary-50,#eff6ff) 66%,var(--dp-surface)),var(--dp-surface))!important;padding:9px 11px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-context span{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-height:24px!important;border-radius:999px!important;background:var(--dp-primary-600,#2563eb)!important;color:#fff!important;padding:3px 9px!important;font-size:10px!important;font-weight:950!important;letter-spacing:.06em!important;text-transform:uppercase!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-context strong{min-width:0!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;color:var(--dp-text)!important;font-size:14px!important;font-weight:940!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-context small{justify-self:end!important;min-width:0!important;max-width:34vw!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;color:var(--dp-text_muted)!important;font-size:11px!important;font-weight:820!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-search{display:grid!important;position:relative!important;top:auto!important;margin:0!important;padding:0!important;background:transparent!important;border-radius:0!important;backdrop-filter:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-search input{height:44px!important;min-height:44px!important;border-radius:15px!important;padding:0 42px 0 38px!important;font-size:16px!important;background:var(--dp-control_bg)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-search span{right:8px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-nav{display:flex!important;align-items:flex-start!important;gap:10px!important;width:100%!important;max-width:100%!important;overflow-x:auto!important;overflow-y:hidden!important;padding:2px 2px 8px!important;scroll-snap-type:x mandatory!important;scroll-padding-inline:8px!important;scrollbar-width:thin!important;overscroll-behavior-inline:contain!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-nav>.dp-panel-sidebar-link{flex:0 0 min(210px,68vw)!important;scroll-snap-align:start!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;align-content:start!important;gap:8px!important;flex:0 0 min(430px,88vw)!important;min-width:0!important;margin:0!important;padding:10px!important;border:1px solid var(--dp-border_soft)!important;border-radius:18px!important;background:color-mix(in srgb,var(--dp-surface_muted) 48%,var(--dp-surface))!important;scroll-snap-align:start!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group.active{order:-20!important;border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 35%,var(--dp-border))!important;box-shadow:0 12px 30px color-mix(in srgb,var(--dp-primary-600,#2563eb) 10%,transparent)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-pinned{order:-30!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-recent{order:-10!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2{display:flex!important;grid-column:1/-1!important;margin:0!important;padding:0!important;color:var(--dp-text_muted)!important;font-size:10px!important;font-weight:950!important;letter-spacing:.08em!important;text-transform:uppercase!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button{min-height:30px!important;border-radius:999px!important;background:color-mix(in srgb,var(--dp-surface) 74%,transparent)!important;padding:4px 8px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button b{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-width:22px!important;height:20px!important;border-radius:999px!important;background:var(--dp-surface_muted)!important;color:var(--dp-text_muted)!important;font-size:10px!important;font-weight:950!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-item{display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;gap:6px!important;min-width:0!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2+.dp-panel-sidebar-item:last-child{grid-column:1/-1!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu{grid-column:1/-1!important;min-width:0!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu-items{padding-left:10px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link{display:grid!important;grid-template-columns:32px minmax(0,1fr) auto!important;gap:8px!important;width:100%!important;min-width:0!important;max-width:none!important;min-height:56px!important;border-radius:15px!important;padding:8px!important;background:color-mix(in srgb,var(--dp-surface) 84%,transparent)!important;border:1px solid var(--dp-border_soft)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active{background:linear-gradient(135deg,var(--dp-primary-600,#2563eb),color-mix(in srgb,var(--dp-primary-600,#2563eb) 70%,var(--dp-info-600,#0891b2)))!important;color:#fff!important;box-shadow:0 14px 32px color-mix(in srgb,var(--dp-primary-600,#2563eb) 24%,transparent)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-copy small,.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-copy strong{color:#fff!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-icon,.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-badge{background:rgba(255,255,255,.22)!important;color:#fff!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-icon{width:32px!important;height:32px!important;border-radius:12px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy{min-width:0!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy strong{display:block!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;font-size:13px!important;line-height:1.15!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy small{display:block!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;font-size:10px!important;line-height:1.2!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-pin{display:none!important}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-search{display:none!important}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-context{display:none!important}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-nav{display:none!important}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar{gap:0!important}
}
@media(max-width:620px){
.dp-panel-nav-sidebar .dp-panel-sidebar{top:6px!important;margin-bottom:10px!important;padding:8px!important;border-radius:18px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand{grid-template-columns:34px minmax(0,1fr)!important;grid-template-areas:"icon name"!important;min-height:44px!important;padding:6px 8px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand>span{width:34px!important;height:34px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand small{display:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle]{width:44px!important;height:44px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-context{grid-template-columns:minmax(0,1fr) auto!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-context span{display:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-context small{max-width:38vw!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group{flex-basis:92vw!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;padding:9px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2{grid-column:1/-1!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-nav>.dp-panel-sidebar-link{flex-basis:78vw!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link{min-height:52px!important}
}
body[data-dp-theme-mode="dark"] .dp-panel-nav-sidebar .dp-panel-sidebar{background:color-mix(in srgb,#121a28 88%,transparent)!important;border-color:#29384d!important}
body[data-dp-theme-mode="dark"] .dp-panel-nav-sidebar .dp-panel-sidebar-brand,body[data-dp-theme-mode="dark"] .dp-panel-nav-sidebar .dp-panel-sidebar-link,body[data-dp-theme-mode="dark"] .dp-panel-nav-sidebar .dp-panel-sidebar-group{background:#151f2e!important;border-color:#2f4058!important}
body[data-dp-theme-mode="dark"] .dp-panel-sidebar-submenu{background:transparent!important;border-color:transparent!important}
body[data-dp-theme-mode="dark"] .dp-panel-sidebar-submenu.active>summary{background:#1b2d49!important;border-color:#3b64a4!important;color:#dbeafe!important}
body[data-dp-theme-mode="dark"] .dp-panel-nav-sidebar .dp-panel-sidebar-context{background:linear-gradient(135deg,#1d3354,#151f2e)!important;border-color:#34537f!important}
@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"] .dp-panel-sidebar-submenu{background:transparent!important;border-color:transparent!important}body[data-dp-theme-mode="system"] .dp-panel-sidebar-submenu.active>summary{background:#1b2d49!important;border-color:#3b64a4!important;color:#dbeafe!important}}
@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"] .dp-panel-nav-sidebar .dp-panel-sidebar{background:color-mix(in srgb,#121a28 88%,transparent)!important;border-color:#29384d!important}body[data-dp-theme-mode="system"] .dp-panel-nav-sidebar .dp-panel-sidebar-brand,body[data-dp-theme-mode="system"] .dp-panel-nav-sidebar .dp-panel-sidebar-link,body[data-dp-theme-mode="system"] .dp-panel-nav-sidebar .dp-panel-sidebar-group{background:#151f2e!important;border-color:#2f4058!important}body[data-dp-theme-mode="system"] .dp-panel-nav-sidebar .dp-panel-sidebar-context{background:linear-gradient(135deg,#1d3354,#151f2e)!important;border-color:#34537f!important}}
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
	--dp-nav-section-active-rail:var(--dp-nav_section_active_rail,linear-gradient(180deg,var(--dp-primary-600,#2563eb),var(--dp-info-600,#0891b2)));
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
.dp-panel-nav-sidebar{grid-template-columns:minmax(260px,var(--dp-nav-width)) minmax(0,1fr)!important;column-gap:var(--dp-nav-gap)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar{position:sticky!important;top:16px!important;display:grid!important;align-content:start!important;gap:var(--dp-nav-section-gap)!important;max-height:calc(100vh - 32px)!important;overflow:auto!important;padding:var(--dp-nav-shell-padding)!important;border:1px solid var(--dp-nav-shell-border)!important;border-radius:var(--dp-nav-shell-radius)!important;background:var(--dp-nav-shell-bg)!important;box-shadow:var(--dp-nav-shell-shadow)!important;backdrop-filter:blur(18px)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-top{display:grid!important;grid-template-columns:minmax(0,1fr) 42px!important;gap:10px!important;align-items:center!important;margin:0!important;padding:0!important;border:0!important;background:transparent!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand{display:grid!important;grid-template-columns:42px minmax(0,1fr)!important;grid-template-areas:"icon name" "icon tag"!important;align-items:center!important;gap:0 10px!important;min-height:56px!important;padding:8px 10px!important;border:1px solid var(--dp-nav-brand-border)!important;border-radius:18px!important;background:var(--dp-nav-brand-bg)!important;box-shadow:none!important;text-decoration:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand>span{grid-area:icon!important;width:42px!important;height:42px!important;border-radius:15px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand strong{grid-area:name!important;min-width:0!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;font-size:14px!important;line-height:1.15!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand small{grid-area:tag!important;display:block!important;min-width:0!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;font-size:11px!important;line-height:1.25!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle]{width:42px!important;height:42px!important;min-height:42px!important;border-radius:16px!important;background:var(--dp-nav-brand-bg)!important;border:1px solid var(--dp-nav-brand-border)!important;color:var(--dp-text)!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-search{display:grid!important;position:relative!important;top:auto!important;margin:0!important;padding:0!important;background:transparent!important;border:0!important;border-radius:0!important;backdrop-filter:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-search:before{left:13px!important;top:50%!important;transform:translateY(-50%)!important;width:15px!important;height:15px!important;opacity:.72!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-search input{height:44px!important;min-height:44px!important;padding:0 42px 0 39px!important;border:1px solid var(--dp-nav-brand-border)!important;border-radius:17px!important;background:var(--dp-nav-search-bg)!important;box-shadow:none!important;color:var(--dp-text)!important;font-size:13px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-context{display:grid!important;grid-template-columns:minmax(0,1fr)!important;gap:3px!important;margin:0!important;padding:12px 13px!important;border:1px solid var(--dp-nav-current-border)!important;border-radius:19px!important;background:var(--dp-nav-current-bg)!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-context span{display:block!important;min-height:0!important;padding:0!important;background:transparent!important;color:var(--dp-primary-700,#175cd3)!important;font-size:10px!important;font-weight:950!important;letter-spacing:.08em!important;text-transform:uppercase!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-context strong{min-width:0!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;color:var(--dp-text)!important;font-size:14px!important;line-height:1.18!important;font-weight:900!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-context small{justify-self:start!important;min-width:0!important;max-width:100%!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;color:var(--dp-text_muted)!important;font-size:11px!important;line-height:1.2!important;font-weight:720!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-nav{display:grid!important;gap:8px!important;overflow:visible!important;padding:0!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group{position:relative!important;display:grid!important;grid-template-columns:1fr!important;gap:4px!important;margin:0!important;padding:12px 0 0!important;border:0!important;border-top:1px solid var(--dp-nav-section-border)!important;border-radius:0!important;background:transparent!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group.active:before{content:""!important;position:absolute!important;left:-9px!important;top:14px!important;bottom:6px!important;width:3px!important;border-radius:999px!important;background:var(--dp-nav-section-active-rail)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:8px!important;min-height:26px!important;margin:0!important;padding:0 7px!important;color:var(--dp-nav-section-label)!important;font-size:10px!important;font-weight:950!important;letter-spacing:.08em!important;text-transform:uppercase!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2>span,.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button>b{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-width:24px!important;height:20px!important;border-radius:999px!important;background:var(--dp-nav-badge-bg)!important;color:var(--dp-nav-badge-color)!important;padding:0 7px!important;font-size:10px!important;line-height:1!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button{display:flex!important;align-items:center!important;justify-content:space-between!important;width:100%!important;min-height:26px!important;border:0!important;border-radius:12px!important;background:transparent!important;color:inherit!important;padding:0!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button>span{display:block!important;min-width:0!important;height:auto!important;border-radius:0!important;background:transparent!important;color:inherit!important;padding:0!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;line-height:1.2!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button>i{flex:0 0 auto!important;margin-left:2px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button:hover{background:transparent!important;color:var(--dp-text)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-item{display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;gap:6px!important;min-width:0!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary{display:grid!important;grid-template-columns:34px minmax(0,1fr) auto!important;align-items:center!important;gap:9px!important;width:100%!important;min-width:0!important;min-height:var(--dp-nav-item-height)!important;border:1px solid var(--dp-nav-item-border)!important;border-radius:var(--dp-nav-item-radius)!important;background:var(--dp-nav-item-bg)!important;color:var(--dp-nav-item-color,var(--dp-text))!important;padding:var(--dp-nav-item-padding)!important;box-shadow:none!important;text-decoration:none!important;transform:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link:hover,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary:hover{border-color:transparent!important;background:var(--dp-nav-item-hover-bg)!important;box-shadow:none!important;transform:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu.active>summary{border-color:transparent!important;background:var(--dp-nav-item-active-bg)!important;color:var(--dp-nav-item-active-color)!important;box-shadow:0 14px 30px color-mix(in srgb,var(--dp-primary-600,#2563eb) 18%,transparent)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active:before,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu.active>summary:before{content:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-icon{width:34px!important;height:34px!important;border-radius:13px!important;background:var(--dp-nav-icon-bg)!important;color:var(--dp-nav-icon-color)!important;font-size:10px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-icon,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu.active>summary .dp-panel-sidebar-icon{background:var(--dp-nav-icon-active-bg)!important;color:var(--dp-nav-icon-active-color)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy{min-width:0!important;display:grid!important;gap:1px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy strong{display:block!important;min-width:0!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;color:inherit!important;font-size:13px!important;line-height:1.16!important;font-weight:870!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy small{display:block!important;min-width:0!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;color:inherit!important;font-size:10.5px!important;line-height:1.2!important;font-weight:650!important;opacity:.78!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-badge{justify-self:end!important;min-width:22px!important;height:21px!important;border:0!important;border-radius:999px!important;background:var(--dp-nav-badge-bg)!important;color:var(--dp-nav-badge-color)!important;padding:0 7px!important;font-size:10px!important;line-height:1!important;font-weight:950!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-badge,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu.active>summary .dp-panel-sidebar-badge{background:rgba(255,255,255,.22)!important;color:#fff!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu{display:grid!important;gap:3px!important;border:0!important;border-radius:0!important;background:transparent!important;padding:0!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary{grid-template-columns:34px minmax(0,1fr) auto 12px!important;list-style:none!important;cursor:pointer!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary>i{width:8px!important;height:8px!important;border-right:2px solid currentColor!important;border-bottom:2px solid currentColor!important;transform:rotate(45deg)!important;opacity:.56!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu[open]>summary>i{transform:rotate(225deg)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu-items{display:grid!important;gap:3px!important;margin:3px 0 3px var(--dp-nav-submenu-indent)!important;padding:2px 0 2px 11px!important;border-left:1px solid var(--dp-nav-submenu-rail)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-submenu-depth-1 .dp-panel-sidebar-submenu-items{margin-left:12px!important;padding-left:10px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link-parent{border-color:transparent!important;border-style:solid!important;background:transparent!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-pin{width:26px!important;height:26px!important;align-self:center!important;border-radius:10px!important;background:transparent!important;border-color:transparent!important;color:var(--dp-text_muted)!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-item:hover .dp-panel-sidebar-pin,.dp-panel-nav-sidebar .dp-panel-sidebar-pin:hover{background:var(--dp-nav-item-hover-bg)!important;border-color:transparent!important;color:var(--dp-text)!important}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed{grid-template-columns:88px minmax(0,1fr)!important}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar{padding:10px!important;border-radius:22px!important;overflow:visible!important}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-top{grid-template-columns:1fr!important}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand{grid-template-columns:42px!important;grid-template-areas:"icon"!important;justify-content:center!important;padding:8px!important}
.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand strong,.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand small,.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-search,.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-context,.dp-panel-nav-sidebar.dp-panel-sidebar-collapsed .dp-panel-sidebar-nav{display:none!important}
}
@media(max-width:1180px){
.dp-panel-nav-sidebar .dp-panel-sidebar{background:var(--dp-nav-shell-bg)!important;border-color:var(--dp-nav-shell-border)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand{background:var(--dp-nav-brand-bg)!important;border-color:var(--dp-nav-brand-border)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-context{background:var(--dp-nav-current-bg)!important;border-color:var(--dp-nav-current-border)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-nav{gap:8px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group{background:transparent!important;border-color:var(--dp-nav-section-border)!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary{background:var(--dp-nav-item-bg)!important;border-color:transparent!important;box-shadow:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link:hover,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary:hover{background:var(--dp-nav-item-hover-bg)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active,.dp-panel-nav-sidebar .dp-panel-sidebar-submenu.active>summary{background:var(--dp-nav-item-active-bg)!important;color:var(--dp-nav-item-active-color)!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group-collapsed>*:not(h2){display:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-searching .dp-panel-sidebar-group-collapsed>*:not(h2){display:grid!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group-collapsed h2 button>i{transform:rotate(-45deg)!important}
}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button>span{display:block!important;min-width:0!important;height:auto!important;border-radius:0!important;background:transparent!important;color:inherit!important;padding:0!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;line-height:1.2!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2>span,.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button>b{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-width:24px!important;height:20px!important;border-radius:999px!important;background:var(--dp-nav-badge-bg)!important;color:var(--dp-nav-badge-color)!important;padding:0 7px!important;font-size:10px!important;line-height:1!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button>i{flex:0 0 auto!important;margin-left:2px!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group-collapsed>*:not(h2){display:none!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-searching .dp-panel-sidebar-group-collapsed>*:not(h2){display:grid!important}
.dp-panel-nav-sidebar .dp-panel-sidebar-group-collapsed h2 button>i{transform:rotate(-45deg)!important}
body[data-dp-theme-effects~="glass"]{background:var(--dp-body_bg)!important;background-attachment:fixed!important}
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
	background:var(--dp-glass_surface_bg)!important;
	border-color:var(--dp-glass_border)!important;
	box-shadow:var(--dp-glass_shadow)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-sidebar,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-nav,
body[data-dp-theme-effects~="glass"] .dp-panel-live-control,
body[data-dp-theme-effects~="glass"] .dp-panel-theme-toggle,
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta,
body[data-dp-theme-effects~="glass"] .dp-panel-pagination,
body[data-dp-theme-effects~="glass"] .dp-panel-bulk-bar{
	background:var(--dp-glass_surface_strong_bg)!important;
	border-color:var(--dp-glass_border)!important;
	box-shadow:var(--dp-glass_shadow_soft)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
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
	background:var(--dp-glass_surface_muted_bg)!important;
	border-color:var(--dp-glass_border)!important;
	box-shadow:var(--dp-glass_shadow_soft)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table th,
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr,
body[data-dp-theme-effects~="glass"] .dp-panel-table td.dp-panel-actions,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll th.dp-panel-actions,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll td.dp-panel-actions{
	background:color-mix(in srgb,var(--dp-surface) 72%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table th.dp-panel-actions,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll th.dp-panel-actions{
	position:relative!important;
	right:auto!important;
	z-index:auto!important;
	min-width:0!important;
	background:color-mix(in srgb,var(--dp-surface) 72%,transparent)!important;
	box-shadow:none!important;
	backdrop-filter:none!important;
	-webkit-backdrop-filter:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table th.dp-panel-actions:before,
body[data-dp-theme-effects~="glass"] .dp-panel-table th.dp-panel-actions:after,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll th.dp-panel-actions:before,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll th.dp-panel-actions:after{
	content:none!important;
	display:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table th.dp-panel-actions+th,
body[data-dp-theme-effects~="glass"] .dp-panel-table th:has(+ th.dp-panel-actions){
	box-shadow:none!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-table-scroll tr:hover td.dp-panel-actions{
	background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 32%,var(--dp-surface))!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-search input,
body[data-dp-theme-effects~="glass"] .dp-panel-global-search input,
body[data-dp-theme-effects~="glass"] .dp-panel-filter input,
body[data-dp-theme-effects~="glass"] .dp-panel-filter select,
body[data-dp-theme-effects~="glass"] .dp-panel-field input,
body[data-dp-theme-effects~="glass"] .dp-panel-field select,
body[data-dp-theme-effects~="glass"] .dp-panel-field textarea,
body[data-dp-theme-effects~="glass"] .dp-panel-sidebar-search input{
	background:var(--dp-control_bg)!important;
	border-color:var(--dp-control_border)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-button-secondary,
body[data-dp-theme-effects~="glass"] .dp-panel-action-neutral,
body[data-dp-theme-effects~="glass"] .dp-panel-row-link,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker summary,
body[data-dp-theme-effects~="glass"] .dp-panel-density,
body[data-dp-theme-effects~="glass"] .dp-panel-table-view,
body[data-dp-theme-effects~="glass"] .dp-panel-filter-trigger{
	background:var(--dp-glass_surface_muted_bg)!important;
	border-color:var(--dp-glass_border)!important;
	box-shadow:none!important;
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
	position:relative!important;
	overflow:hidden;
	box-shadow:var(--dp-glass_shadow),var(--dp-glass_edge)!important;
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
body[data-dp-theme-effects~="glass"] .dp-panel-card>*,
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
	background:var(--dp-glass_overlay_bg)!important;
	backdrop-filter:blur(16px) saturate(1.08)!important;
	-webkit-backdrop-filter:blur(16px) saturate(1.08)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-header,
body[data-dp-theme-effects~="glass"] .dp-panel-modal-body,
body[data-dp-theme-effects~="glass"] .dp-panel-modal-body>.dp-panel-form>.dp-panel-toolbar:last-child,
body[data-dp-theme-effects~="glass"] .dp-panel-command-footer,
body[data-dp-theme-effects~="glass"] .dp-panel-command-list{
	background:transparent!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-modal-header,
body[data-dp-theme-effects~="glass"] .dp-panel-command-footer,
body[data-dp-theme-effects~="glass"] .dp-panel-table th,
body[data-dp-theme-effects~="glass"] .dp-panel-table td,
body[data-dp-theme-effects~="glass"] .dp-panel-table-meta,
body[data-dp-theme-effects~="glass"] .dp-panel-pagination{
	border-color:color-mix(in srgb,var(--dp-glass_border) 78%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-action-menu,
body[data-dp-theme-effects~="glass"] .dp-panel-row-more-menu,
body[data-dp-theme-effects~="glass"] .dp-panel-column-picker form,
body[data-dp-theme-effects~="glass"] .dp-panel-saved-view-menu>div,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-menu,
body[data-dp-theme-effects~="glass"] .dp-panel-command-item,
body[data-dp-theme-effects~="glass"] .dp-panel-toast,
body[data-dp-theme-effects~="glass"] .dp-panel-unsaved-dialog{
	background:var(--dp-glass_menu_bg)!important;
	border-color:var(--dp-glass_border)!important;
	box-shadow:var(--dp-glass_shadow_lifted)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
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
	background:var(--dp-glass_control_bg)!important;
	box-shadow:var(--dp-glass_edge)!important;
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
	background:var(--dp-glass_control_bg)!important;
	box-shadow:var(--dp-glass_edge)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-button:not(.dp-panel-button-secondary),
body[data-dp-theme-effects~="glass"] .dp-panel-action-primary,
body[data-dp-theme-effects~="glass"] .dp-panel-action-success,
body[data-dp-theme-effects~="glass"] .dp-panel-action-warning,
body[data-dp-theme-effects~="glass"] .dp-panel-action-danger{
	box-shadow:0 14px 34px color-mix(in srgb,currentColor 18%,transparent),inset 0 1px 0 rgba(255,255,255,.24)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-widget:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-card:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-show-field:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-board-card:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:hover{
	box-shadow:var(--dp-glass_shadow_lifted),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table{
	border-collapse:separate!important;
	border-spacing:0!important;
	overflow:hidden!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:nth-child(odd){
	background:color-mix(in srgb,var(--dp-glass_surface_muted_bg) 72%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-table tbody tr:nth-child(even){
	background:color-mix(in srgb,var(--dp-glass_surface_bg) 42%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-link,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-link,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-group>summary{
	border-color:color-mix(in srgb,var(--dp-glass_border) 46%,transparent)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-link:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-sidebar .dp-panel-sidebar-submenu>summary:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-link:hover,
body[data-dp-theme-effects~="glass"] .dp-panel-horizontal-group>summary:hover{
	box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;
}
body[data-dp-theme-effects~="glass"] .dp-panel-badge,
body[data-dp-theme-effects~="glass"] .dp-panel-nav-badge,
body[data-dp-theme-effects~="glass"] .dp-panel-sidebar-badge,
body[data-dp-theme-effects~="glass"] .dp-panel-table-view small,
body[data-dp-theme-effects~="glass"] .dp-panel-live-control small{
	background:color-mix(in srgb,var(--dp-glass_control_bg) 84%,transparent)!important;
	border:1px solid color-mix(in srgb,var(--dp-glass_border) 62%,transparent)!important;
	box-shadow:var(--dp-glass_edge)!important;
}
@supports not ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){
	body[data-dp-theme-effects~="glass"]{--dp-glass_surface_bg:var(--dp-surface);--dp-glass_surface_strong_bg:var(--dp-surface);--dp-glass_surface_muted_bg:var(--dp-surface_muted)}
}
.dp-panel-with-navigation{--dp-nav-mode-edge-radius:0 24px 24px 0;--dp-nav-mode-edge-shadow:0 18px 54px color-mix(in srgb,#0f172a 13%,transparent);--dp-nav-mode-top:12px}
.dp-panel-nav-mode-floating .dp-panel-sidebar-top{z-index:36!important}
.dp-panel-nav-mode-floating .dp-panel-sidebar-search{z-index:28!important}
.dp-panel-nav-mode-docked .dp-panel-sidebar,.dp-panel-nav-mode-docked .dp-panel-horizontal-nav{box-shadow:none!important}
.dp-panel-nav-mode-docked .dp-panel-sidebar{top:var(--dp-nav-mode-top)!important;max-height:calc(100dvh - (var(--dp-nav-mode-top) * 2))!important}
.dp-panel-nav-mode-docked .dp-panel-sidebar-top{z-index:36!important}
.dp-panel-nav-mode-docked .dp-panel-sidebar-search{z-index:28!important}
.dp-panel-nav-mode-edge{--dp-nav-mode-top:0px}
.dp-panel-nav-mode-edge.dp-panel-nav-sidebar{padding-top:0!important;padding-left:0!important}
.dp-panel-nav-mode-edge.dp-panel-nav-sidebar .dp-panel-main-region{padding-top:18px!important;padding-right:clamp(14px,2vw,30px)!important}
.dp-panel-nav-mode-edge .dp-panel-sidebar{position:sticky!important;top:0!important;height:100dvh!important;max-height:100dvh!important;margin:0!important;border-left:0!important;border-radius:var(--dp-nav-mode-edge-radius)!important;box-shadow:var(--dp-nav-mode-edge-shadow)!important}
.dp-panel-nav-mode-edge .dp-panel-sidebar-top{position:sticky!important;top:0!important;z-index:42!important;border-radius:0 24px 0 0!important}
.dp-panel-nav-mode-edge .dp-panel-sidebar-search{position:sticky!important;top:76px!important;z-index:34!important}
.dp-panel-nav-mode-edge .dp-panel-sidebar-context{position:relative!important;z-index:18!important}
.dp-panel-nav-mode-edge .dp-panel-sidebar-nav{position:relative!important;z-index:10!important}
.dp-panel-nav-mode-edge.dp-panel-nav-horizontal{padding-top:0!important}
.dp-panel-nav-mode-edge .dp-panel-horizontal-nav{order:-1!important;position:sticky!important;top:0!important;z-index:140!important;width:100vw!important;max-width:100vw!important;margin:0 calc(50% - 50vw) 12px!important;border-left:0!important;border-right:0!important;border-radius:0!important;box-shadow:0 12px 32px color-mix(in srgb,#0f172a 10%,transparent)!important}
.dp-panel-nav-mode-edge .dp-panel-horizontal-group[open]>div{max-width:calc(100vw - 20px)!important}
.dp-panel-nav-mode-overlay .dp-panel-sidebar{position:sticky!important;top:12px!important;z-index:120!important}
.dp-panel-nav-mode-overlay .dp-panel-horizontal-nav{z-index:150!important}
.dp-panel-header,.dp-panel-footer{position:relative!important;min-width:0!important}
.dp-panel-header{isolation:isolate}
.dp-panel-footer{display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;align-items:center!important;gap:14px!important;border:1px solid var(--dp-border)!important;border-radius:20px!important;background:color-mix(in srgb,var(--dp-surface) 94%,transparent)!important;box-shadow:var(--dp-ui-shadow-soft,0 14px 34px rgba(15,23,42,.065))!important;padding:14px 18px!important;color:var(--dp-text)!important}
.dp-panel-footer-status{display:grid!important;gap:2px!important;min-width:0!important}.dp-panel-footer-status strong{font-size:13px!important;font-weight:950!important}.dp-panel-footer-status span{color:var(--dp-text_muted)!important;font-size:12px!important;font-weight:760!important}.dp-panel-footer-actions{display:flex!important;align-items:center!important;justify-content:flex-end!important;gap:8px!important;flex-wrap:wrap!important}
.dp-panel-header-mode-docked .dp-panel-main-region>.dp-panel-header,.dp-panel-footer-mode-docked .dp-panel-main-region>.dp-panel-footer{box-shadow:none!important}
.dp-panel-header-mode-docked .dp-panel-main-region>.dp-panel-header{margin-bottom:2px!important}
.dp-panel-footer-mode-docked .dp-panel-main-region>.dp-panel-footer{margin-top:2px!important;background:var(--dp-surface)!important}
.dp-panel-header-mode-edge:not(.dp-panel-nav-sidebar) .dp-panel-main-region>.dp-panel-header{position:sticky!important;top:0!important;z-index:130!important;width:100vw!important;max-width:100vw!important;margin:0 calc(50% - 50vw) 14px!important;border-left:0!important;border-right:0!important;border-radius:0!important}
.dp-panel-header-mode-edge.dp-panel-nav-horizontal.dp-panel-nav-mode-edge .dp-panel-main-region>.dp-panel-header{top:var(--dp-panel-horizontal-nav-offset,68px)!important}
.dp-panel-header-mode-edge.dp-panel-nav-sidebar .dp-panel-main-region>.dp-panel-header{position:sticky!important;top:0!important;z-index:90!important;border-top-left-radius:0!important;border-top-right-radius:0!important}
.dp-panel-footer-mode-edge:not(.dp-panel-nav-sidebar) .dp-panel-main-region>.dp-panel-footer{position:sticky!important;bottom:0!important;z-index:110!important;width:100vw!important;max-width:100vw!important;margin:14px calc(50% - 50vw) 0!important;border-left:0!important;border-right:0!important;border-bottom:0!important;border-radius:20px 20px 0 0!important}
.dp-panel-footer-mode-edge.dp-panel-nav-sidebar .dp-panel-main-region>.dp-panel-footer{position:sticky!important;bottom:0!important;z-index:80!important;border-bottom-left-radius:0!important;border-bottom-right-radius:0!important}
.dp-panel-header-mode-overlay .dp-panel-main-region>.dp-panel-header{position:sticky!important;top:12px!important;z-index:180!important;box-shadow:0 24px 70px color-mix(in srgb,#0f172a 16%,transparent)!important}
.dp-panel-footer-mode-overlay .dp-panel-main-region>.dp-panel-footer{position:sticky!important;bottom:12px!important;z-index:170!important;box-shadow:0 24px 70px color-mix(in srgb,#0f172a 16%,transparent)!important}
body[data-dp-theme-effects~="glass"] .dp-panel-header,
body[data-dp-theme-effects~="glass"] .dp-panel-footer{background:var(--dp-glass_surface_strong_bg)!important;border-color:var(--dp-glass_border)!important;box-shadow:var(--dp-glass_shadow_soft),var(--dp-glass_edge)!important;backdrop-filter:var(--dp-glass_blur)!important;-webkit-backdrop-filter:var(--dp-glass_blur)!important}
body[data-dp-theme-effects~="brutalist"] .dp-panel-header,
body[data-dp-theme-effects~="brutalist"] .dp-panel-footer{border-radius:0!important;box-shadow:6px 6px 0 #111!important}
@media(max-width:1180px){.dp-panel-nav-mode-edge.dp-panel-nav-sidebar{padding:8px!important}.dp-panel-nav-mode-edge .dp-panel-sidebar{position:sticky!important;top:0!important;height:auto!important;max-height:none!important;border-left:1px solid color-mix(in srgb,var(--dp-border) 82%,transparent)!important;border-radius:20px!important}.dp-panel-nav-mode-edge .dp-panel-sidebar-top{border-radius:18px 18px 0 0!important}.dp-panel-nav-mode-edge .dp-panel-sidebar-search{position:relative!important;top:auto!important;z-index:28!important}.dp-panel-nav-mode-edge.dp-panel-nav-sidebar .dp-panel-main-region{padding-top:0!important;padding-right:0!important}}
@media(max-width:820px){.dp-panel-footer{grid-template-columns:1fr!important}.dp-panel-footer-actions{justify-content:flex-start!important}.dp-panel-header-mode-edge:not(.dp-panel-nav-sidebar) .dp-panel-main-region>.dp-panel-header,.dp-panel-footer-mode-edge:not(.dp-panel-nav-sidebar) .dp-panel-main-region>.dp-panel-footer{margin-inline:calc(50% - 50vw)!important}}
@media(max-width:720px){.dp-panel-nav-mode-edge .dp-panel-horizontal-nav{margin-inline:calc(50% - 50vw)!important;padding-inline:8px!important}.dp-panel-nav-mode-edge .dp-panel-horizontal-group[open]>div{left:10px!important;right:10px!important;width:auto!important}}
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
.dp-panel-header-mode-edge:not(.dp-panel-nav-sidebar) .dp-panel-main-region>.dp-panel-header{position:sticky!important;top:0!important;z-index:130!important;width:100vw!important;max-width:100vw!important;margin:0 calc(50% - 50vw) 14px!important;border-left:0!important;border-right:0!important;border-radius:0!important}
.dp-panel-header-mode-edge.dp-panel-nav-horizontal.dp-panel-nav-mode-edge .dp-panel-main-region>.dp-panel-header{top:var(--dp-panel-horizontal-nav-offset,68px)!important}
.dp-panel-header-mode-edge.dp-panel-nav-sidebar .dp-panel-main-region>.dp-panel-header{position:sticky!important;top:0!important;z-index:90!important;border-top-left-radius:0!important;border-top-right-radius:0!important}
.dp-panel-header-mode-docked .dp-panel-main-region>.dp-panel-header{position:relative!important;box-shadow:none!important}
.dp-panel-header-mode-overlay .dp-panel-main-region>.dp-panel-header{position:sticky!important;top:12px!important;z-index:180!important;box-shadow:0 24px 70px color-mix(in srgb,#0f172a 16%,transparent)!important}
.dp-panel-footer-mode-edge:not(.dp-panel-nav-sidebar) .dp-panel-main-region>.dp-panel-footer{position:sticky!important;bottom:0!important;z-index:110!important;width:100vw!important;max-width:100vw!important;margin:14px calc(50% - 50vw) 0!important;border-left:0!important;border-right:0!important;border-bottom:0!important;border-radius:20px 20px 0 0!important}
.dp-panel-footer-mode-edge.dp-panel-nav-sidebar .dp-panel-main-region>.dp-panel-footer{position:sticky!important;bottom:0!important;z-index:80!important;border-bottom-left-radius:0!important;border-bottom-right-radius:0!important}
.dp-panel-footer-mode-docked .dp-panel-main-region>.dp-panel-footer{position:relative!important;box-shadow:none!important}
.dp-panel-footer-mode-overlay .dp-panel-main-region>.dp-panel-footer{position:sticky!important;bottom:12px!important;z-index:170!important;box-shadow:0 24px 70px color-mix(in srgb,#0f172a 16%,transparent)!important}
html:has(body.dp-panel-has-sticky-chrome),body.dp-panel-has-sticky-chrome{overflow-x:clip!important;overflow-y:visible!important}
.dp-panel-horizontal-nav:has(.dp-panel-horizontal-group[open]),.dp-panel-horizontal-track:has(.dp-panel-horizontal-group[open]),.dp-panel-horizontal-nav-menu-open,.dp-panel-horizontal-track-menu-open{overflow:visible!important}
.dp-panel-horizontal-nav-menu-open{z-index:16000!important}
.dp-panel-nav-mode-edge .dp-panel-horizontal-nav{order:-1!important;position:sticky!important;top:0!important;z-index:140!important}
.dp-panel-nav-sticky .dp-panel-sidebar{position:sticky!important;top:var(--dp-panel-nav-sticky-top,var(--dp-nav-mode-top,12px))!important;z-index:120!important;align-self:start!important}
.dp-panel-nav-sticky.dp-panel-nav-horizontal .dp-panel-horizontal-nav{position:sticky!important;top:var(--dp-panel-nav-sticky-top,0px)!important;z-index:160!important;align-self:start!important}
.dp-panel-header-sticky .dp-panel-main-region>.dp-panel-header{position:sticky!important;top:var(--dp-panel-header-sticky-top,12px)!important;z-index:130!important;align-self:start!important}
.dp-panel-nav-sticky.dp-panel-nav-horizontal.dp-panel-header-sticky:not(.dp-panel-nav-mode-edge) .dp-panel-horizontal-nav{top:var(--dp-panel-sticky-header-stack,256px)!important}
.dp-panel-nav-sticky.dp-panel-nav-horizontal.dp-panel-nav-mode-edge.dp-panel-header-sticky .dp-panel-main-region>.dp-panel-header{top:calc(var(--dp-panel-nav-sticky-top,0px) + var(--dp-panel-horizontal-nav-offset,68px))!important}
.dp-panel-footer-sticky .dp-panel-main-region>.dp-panel-footer{position:sticky!important;bottom:var(--dp-panel-footer-sticky-bottom,12px)!important;z-index:120!important;align-self:end!important}
.dp-panel-nav-sticky.dp-panel-nav-sidebar.dp-panel-header-sticky .dp-panel-main-region>.dp-panel-header{top:var(--dp-panel-header-sticky-top,12px)!important}
.dp-panel-nav-sticky.dp-panel-nav-horizontal .dp-panel-horizontal-group[open]>div{z-index:161!important}
CSS;
	}

}
