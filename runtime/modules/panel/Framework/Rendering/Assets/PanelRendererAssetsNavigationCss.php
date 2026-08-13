<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits enhanced navigation styling for sidebar and horizontal Panel layouts.
 *
 * The stylesheet binds renderer-generated navigation classes to desktop,
 * collapsed, submenu, horizontal menu, dark/system, and mobile fallback states.
 * It is a static asset returned to the renderer and depends on markup data
 * attributes plus CSS variables rather than PHP-side runtime interpolation.
 */
trait PanelRendererAssetsNavigationCss {
	/**
	 * Returns the navigation experience stylesheet.
	 *
	 * The block coordinates sidebar search, active items, collapsed behavior,
	 * hover submenus, horizontal menu positioning, responsive breakpoints, and
	 * dark/system color overrides for generated panel navigation.
	 *
	 * @return string CSS emitted for enhanced panel navigation experiences.
	 */
	private static function navigationExperienceCss(): string {
		return <<<'CSS'
.dp-panel-nav-sidebar{--dp-panel-sidebar-gap:20px;grid-template-columns:minmax(260px,300px) minmax(0,1fr);column-gap:var(--dp-panel-sidebar-gap)}
.dp-panel-nav-sidebar .dp-panel-sidebar{border-radius:24px;padding:14px;background:linear-gradient(180deg,color-mix(in srgb,var(--dp-surface) 94%,transparent),color-mix(in srgb,var(--dp-surface_muted) 28%,var(--dp-surface)));box-shadow:0 20px 54px color-mix(in srgb,#0f172a 9%,transparent);overflow:auto;scrollbar-width:thin}
.dp-panel-nav-sidebar .dp-panel-sidebar-top{position:sticky;top:0;z-index:2;margin:-14px -14px 0;padding:14px 14px 10px;background:linear-gradient(180deg,color-mix(in srgb,var(--dp-surface) 96%,transparent),color-mix(in srgb,var(--dp-surface) 84%,transparent));backdrop-filter:blur(16px);border-radius:24px 24px 0 0}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand{min-height:56px;border-radius:18px;border-color:color-mix(in srgb,var(--dp-border) 72%,transparent);background:linear-gradient(135deg,color-mix(in srgb,var(--dp-surface_muted) 72%,var(--dp-surface)),var(--dp-surface))}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand>span{width:42px;height:42px;border-radius:15px;box-shadow:0 12px 26px color-mix(in srgb,var(--dp-primary-600,#2563eb) 20%,transparent)}
.dp-panel-nav-sidebar .dp-panel-sidebar-search{position:sticky;top:72px;z-index:2;margin:0 -2px 8px;padding:2px;background:color-mix(in srgb,var(--dp-surface) 72%,transparent);backdrop-filter:blur(14px);border-radius:16px}
.dp-panel-nav-sidebar .dp-panel-sidebar-search input{height:42px;border-radius:15px;padding-left:36px;background:color-mix(in srgb,var(--dp-control_bg,var(--dp-surface)) 92%,var(--dp-surface_muted))}
.dp-panel-nav-sidebar .dp-panel-sidebar-search:before{content:"";position:absolute;left:14px;top:50%;width:13px;height:13px;border:2px solid var(--dp-text_muted);border-radius:999px;transform:translateY(-50%);opacity:.72}
.dp-panel-nav-sidebar .dp-panel-sidebar-search:after{content:"";position:absolute;left:26px;top:27px;width:7px;height:2px;border-radius:999px;background:var(--dp-text_muted);transform:rotate(45deg);opacity:.72}
.dp-panel-tenant-switcher-sidebar{position:relative;margin:3px 0 8px}
.dp-panel-tenant-switcher-sidebar>summary,.dp-panel-tenant-switcher-option{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:40px;border:1px solid var(--dp-border);border-radius:12px;background:var(--dp-surface);color:var(--dp-text);padding:6px 9px;text-decoration:none}
.dp-panel-tenant-switcher-sidebar>div{position:absolute;z-index:120;inset:calc(100% + 4px) 0 auto;display:grid;gap:4px;max-height:55vh;overflow:auto;border:1px solid var(--dp-border);border-radius:12px;background:var(--dp-surface);padding:5px}
.dp-panel-tenant-switcher-option:hover,.dp-panel-tenant-switcher-option[aria-current="true"]{background:var(--dp-surface_muted)}
.dp-panel-sidebar-context{display:grid;gap:2px;margin:2px 0 10px;padding:11px 12px;border:1px solid color-mix(in srgb,var(--dp-primary-600,#2563eb) 20%,var(--dp-border));border-radius:17px;background:linear-gradient(135deg,color-mix(in srgb,var(--dp-primary-50,#eff6ff) 70%,var(--dp-surface)),var(--dp-surface));box-shadow:inset 0 1px 0 color-mix(in srgb,#fff 65%,transparent)}
.dp-panel-sidebar-context span{font-size:9px;font-weight:950;letter-spacing:.1em;text-transform:uppercase;color:var(--dp-primary-700,#175cd3)}
.dp-panel-sidebar-context strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--dp-text);font-size:13px;font-weight:940}
.dp-panel-sidebar-context small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--dp-text_muted);font-size:11px;font-weight:760}
.dp-panel-nav-sidebar .dp-panel-sidebar-nav{gap:7px}
.dp-panel-nav-sidebar .dp-panel-sidebar-group{position:relative;margin:8px 0 0;padding:11px 0 0;border-top:1px solid color-mix(in srgb,var(--dp-border_soft) 86%,transparent)}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2{display:block;margin:0 0 5px;padding:0;color:var(--dp-text_muted)}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2>span{float:right;display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;border-radius:999px;background:var(--dp-surface_muted);color:var(--dp-text_muted);font-size:10px;font-weight:950}
.dp-panel-nav-sidebar .dp-panel-sidebar-group-link{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center;width:100%;min-height:30px;border-radius:12px;color:inherit;padding:0 8px;text-decoration:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-group-link:hover{background:color-mix(in srgb,var(--dp-surface_muted) 70%,transparent);color:var(--dp-text)}
.dp-panel-nav-sidebar .dp-panel-sidebar-group-link span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:10px;font-weight:950;letter-spacing:.095em;text-transform:uppercase}
.dp-panel-nav-sidebar .dp-panel-sidebar-group-link b{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;border-radius:999px;background:var(--dp-surface_muted);color:var(--dp-text_muted);font-size:10px;font-weight:950}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:8px;align-items:center;width:100%;height:30px;border:0;border-radius:12px;background:transparent;color:inherit;padding:0 8px;text-align:left;cursor:pointer}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button:hover{background:color-mix(in srgb,var(--dp-surface_muted) 70%,transparent);color:var(--dp-text)}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:10px;font-weight:950;letter-spacing:.095em;text-transform:uppercase}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button b{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;border-radius:999px;background:var(--dp-surface_muted);color:var(--dp-text_muted);font-size:10px;font-weight:950}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button i{width:8px;height:8px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg);opacity:.62;transition:transform .14s ease}
.dp-panel-nav-sidebar .dp-panel-sidebar-group-collapsed h2 button i{transform:rotate(-45deg)}
.dp-panel-nav-sidebar .dp-panel-sidebar-link{min-height:46px;border-radius:15px;padding:7px 9px;isolation:isolate}
.dp-panel-nav-sidebar .dp-panel-sidebar-link:before{content:"";position:absolute;inset:5px auto 5px 5px;width:3px;border-radius:999px;background:transparent;transition:background .14s ease}
.dp-panel-nav-sidebar .dp-panel-sidebar-link:hover{transform:translateX(2px);background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 30%,var(--dp-surface))}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active{box-shadow:0 12px 26px color-mix(in srgb,var(--dp-primary-600,#2563eb) 12%,transparent);background:linear-gradient(90deg,color-mix(in srgb,var(--dp-primary-600,#2563eb) 16%,var(--dp-surface)),color-mix(in srgb,var(--dp-primary-50,#eff6ff) 62%,var(--dp-surface)))}

.dp-panel-nav-sidebar .dp-panel-sidebar-icon{width:35px;height:35px;border-radius:13px}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy strong{font-size:13px;letter-spacing:0}
.dp-panel-nav-sidebar .dp-panel-sidebar-badge{height:24px;min-width:24px}
.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle]{position:relative;overflow:hidden;transition:background .16s ease,color .16s ease,box-shadow .16s ease,transform .16s ease}
.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle] span{width:13px;height:13px;border-left:2px solid currentColor;border-bottom:2px solid currentColor;border-radius:0;background:transparent;box-shadow:none;transform:translateX(2px) rotate(45deg);transition:transform .16s ease}
.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle] span:before,.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle] span:after{content:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle]:hover{transform:translateY(-1px)}
.dp-panel-sidebar-collapsed{grid-template-columns:88px minmax(0,1fr)}
.dp-panel-sidebar-collapsed .dp-panel-sidebar{overflow:visible;padding:10px;border-radius:22px}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-top{grid-template-columns:1fr;justify-items:center;margin:-10px -10px 0;padding:10px;border-radius:22px 22px 0 0}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand{grid-template-columns:42px;justify-content:center;justify-items:center;width:56px;min-height:56px;padding:7px}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand>span{width:42px;height:42px}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle]{width:56px;height:42px;border-radius:15px;background:color-mix(in srgb,var(--dp-surface_muted) 78%,var(--dp-surface));color:var(--dp-text_muted);box-shadow:none}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-top [data-dp-panel-sidebar-toggle] span{transform:translateX(-2px) rotate(225deg)}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand strong,.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand small,.dp-panel-sidebar-collapsed .dp-panel-sidebar-search,.dp-panel-sidebar-collapsed .dp-panel-sidebar-context,.dp-panel-sidebar-collapsed .dp-panel-sidebar-copy,.dp-panel-sidebar-collapsed .dp-panel-sidebar-badge,.dp-panel-sidebar-collapsed .dp-panel-sidebar-group h2,.dp-panel-sidebar-collapsed .dp-panel-sidebar-pin{display:none}
.dp-panel-sidebar-collapsed .dp-panel-tenant-switcher-sidebar{display:none}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-nav{display:grid;gap:7px;justify-items:center;overflow:visible}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-group{display:grid;gap:7px;width:100%;margin:0;padding:0;border:0}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-item{display:grid;grid-template-columns:1fr;width:100%;justify-items:center}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-link,.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu>summary{grid-template-columns:1fr;justify-items:center;width:56px;min-width:0;min-height:52px;margin:0 auto;padding:7px;border-radius:16px}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-link:hover,.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu>summary:hover{transform:none}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-icon{width:38px;height:38px;border-radius:14px}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-link.active,.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu.active>summary{background:linear-gradient(135deg,var(--dp-primary-600,#2563eb),color-mix(in srgb,var(--dp-primary-600,#2563eb) 70%,var(--dp-info-600,#0891b2)));color:#fff;box-shadow:0 12px 28px color-mix(in srgb,var(--dp-primary-600,#2563eb) 24%,transparent)}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-link.active .dp-panel-sidebar-icon,.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu.active>summary .dp-panel-sidebar-icon{background:rgba(255,255,255,.22);color:#fff}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu{position:relative;width:100%;justify-items:center}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu>summary{grid-template-columns:1fr}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu>summary>i{position:absolute;right:5px;bottom:7px;width:6px;height:6px}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu-items{display:none}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu:hover>.dp-panel-sidebar-submenu-items,.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu:focus-within>.dp-panel-sidebar-submenu-items{position:absolute;left:calc(100% + 12px);top:0;z-index:60;display:grid;width:min(280px,70vw);gap:5px;margin:0;padding:8px;border:1px solid var(--dp-border);border-radius:18px;background:var(--dp-surface);box-shadow:0 24px 70px color-mix(in srgb,#0f172a 20%,transparent)}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu:hover>.dp-panel-sidebar-submenu-items .dp-panel-sidebar-link,.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu:focus-within>.dp-panel-sidebar-submenu-items .dp-panel-sidebar-link,.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu:hover>.dp-panel-sidebar-submenu-items .dp-panel-sidebar-submenu>summary,.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu:focus-within>.dp-panel-sidebar-submenu-items .dp-panel-sidebar-submenu>summary{grid-template-columns:34px minmax(0,1fr) auto;justify-items:stretch;width:100%;min-height:42px;margin:0;padding:6px 8px}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu:hover>.dp-panel-sidebar-submenu-items .dp-panel-sidebar-copy,.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu:focus-within>.dp-panel-sidebar-submenu-items .dp-panel-sidebar-copy{display:grid}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu:hover>.dp-panel-sidebar-submenu-items .dp-panel-sidebar-badge,.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu:focus-within>.dp-panel-sidebar-submenu-items .dp-panel-sidebar-badge{display:inline-flex}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu:hover>.dp-panel-sidebar-submenu-items .dp-panel-sidebar-icon,.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu:focus-within>.dp-panel-sidebar-submenu-items .dp-panel-sidebar-icon{width:34px;height:34px}
.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu:hover>.dp-panel-sidebar-submenu-items .dp-panel-sidebar-submenu-items,.dp-panel-sidebar-collapsed .dp-panel-sidebar-submenu:focus-within>.dp-panel-sidebar-submenu-items .dp-panel-sidebar-submenu-items{position:static;display:grid;width:auto;margin-left:17px;padding:2px 0 4px 12px;border:0;border-left:1px solid var(--dp-border_soft);border-radius:0;background:transparent;box-shadow:none}
.dp-panel-nav-horizontal{display:block;width:var(--dp-panel-content-width,100%);max-width:var(--dp-panel-content-max,none);margin-inline:var(--dp-panel-content-margin,0)}
.dp-panel-nav-horizontal .dp-panel-main-region{gap:14px}
.dp-panel-horizontal-nav{position:sticky;top:10px;z-index:80;display:grid;grid-template-columns:auto minmax(0,1fr);gap:12px;align-items:center;width:100%;margin:0 0 2px;padding:10px;border:1px solid var(--dp-border);border-radius:22px;background:color-mix(in srgb,var(--dp-surface) 90%,transparent);box-shadow:0 16px 40px color-mix(in srgb,#0f172a 8%,transparent);backdrop-filter:blur(18px);overflow:visible}
.dp-panel-horizontal-brand{display:inline-grid;grid-template-columns:34px auto;gap:9px;align-items:center;min-height:42px;border:1px solid var(--dp-border_soft);border-radius:15px;background:color-mix(in srgb,var(--dp-surface_muted) 58%,var(--dp-surface));color:var(--dp-text);padding:5px 11px 5px 6px;text-decoration:none;white-space:nowrap}
.dp-panel-horizontal-brand span{display:grid;place-items:center;width:34px;height:34px;border-radius:12px;background:linear-gradient(135deg,var(--dp-primary-600,#2563eb),var(--dp-info-600,#0891b2));color:#fff;font-size:11px;font-weight:950}
.dp-panel-horizontal-brand strong{font-size:13px;font-weight:930}
.dp-panel-horizontal-track{display:flex;align-items:center;gap:8px;min-width:0;overflow-x:auto;overflow-y:hidden;scrollbar-width:none;padding:1px;overscroll-behavior-inline:contain}

.dp-panel-horizontal-link,.dp-panel-horizontal-group>summary{display:inline-flex;align-items:center;gap:8px;min-height:42px;border:1px solid transparent;border-radius:15px;color:var(--dp-text);background:transparent;padding:7px 10px;text-decoration:none;white-space:nowrap;cursor:pointer;list-style:none}
.dp-panel-horizontal-group>summary::-webkit-details-marker{display:none}
.dp-panel-horizontal-link:hover,.dp-panel-horizontal-group>summary:hover{border-color:var(--dp-border);background:var(--dp-surface_muted);text-decoration:none}
.dp-panel-horizontal-link.active,.dp-panel-horizontal-group.active>summary{border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 34%,var(--dp-border));background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 72%,var(--dp-surface));color:var(--dp-primary-800,#1849a9)}
.dp-panel-horizontal-link span{display:grid;place-items:center;width:28px;height:28px;border-radius:10px;background:var(--dp-neutral_bg,#eef2f7);font-size:10px;font-weight:950}
.dp-panel-horizontal-link.active span{background:var(--dp-primary-600,#2563eb);color:#fff}
.dp-panel-horizontal-link strong,.dp-panel-horizontal-group>summary span{font-size:13px;font-weight:880}
.dp-panel-horizontal-group{position:relative;flex:0 0 auto}
.dp-panel-horizontal-group>summary b{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:22px;border-radius:999px;background:var(--dp-surface_muted);color:var(--dp-text_muted);font-size:10px;font-weight:950}
.dp-panel-horizontal-group:not([open])>div{display:none}
.dp-panel-horizontal-group>div{position:fixed;left:var(--dp-horizontal-menu-left,auto);right:auto;top:var(--dp-horizontal-menu-top,72px);display:grid;gap:6px;width:var(--dp-horizontal-menu-width,min(360px,86vw));max-height:var(--dp-horizontal-menu-max-height,min(480px,70vh));overflow:auto;border:1px solid var(--dp-border);border-radius:18px;background:var(--dp-surface);box-shadow:0 24px 70px color-mix(in srgb,#0f172a 18%,transparent);padding:8px;z-index:16000}
.dp-panel-horizontal-item{position:relative;display:grid;grid-template-columns:34px minmax(0,1fr) auto;gap:9px;align-items:center;min-height:48px;border:1px solid transparent;border-radius:14px;color:var(--dp-text);padding:7px 9px;text-decoration:none}
.dp-panel-horizontal-item:hover{border-color:var(--dp-border);background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 34%,var(--dp-surface));text-decoration:none}
.dp-panel-horizontal-item.active{background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 68%,var(--dp-surface));border-color:color-mix(in srgb,var(--dp-primary-600,#2563eb) 28%,var(--dp-border))}
.dp-panel-horizontal-item>span{display:grid;place-items:center;width:34px;height:34px;border-radius:12px;background:var(--dp-neutral_bg,#eef2f7);color:var(--dp-neutral_text,#344054);font-size:10px;font-weight:950}
.dp-panel-horizontal-item strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;font-weight:880}
.dp-panel-horizontal-item small{grid-column:2/4;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:-4px;color:var(--dp-text_muted);font-size:11px;font-weight:650}
.dp-panel-horizontal-item em{justify-self:end;min-width:22px;border-radius:999px;background:var(--dp-surface_muted);color:var(--dp-text_muted);padding:3px 7px;font-style:normal;font-size:10px;font-weight:950}
body[data-dp-theme-mode="dark"] .dp-panel-sidebar-context{background:linear-gradient(135deg,#1d3354,#151f2e);border-color:#34537f}
body[data-dp-theme-mode="dark"] .dp-panel-horizontal-nav,body[data-dp-theme-mode="dark"] .dp-panel-horizontal-group>div{background:#151f2e;border-color:#2c3a4f}
body[data-dp-theme-mode="dark"] .dp-panel-horizontal-brand,body[data-dp-theme-mode="dark"] .dp-panel-horizontal-link:hover,body[data-dp-theme-mode="dark"] .dp-panel-horizontal-group>summary:hover{background:#182235;border-color:#34445d;color:#eef4ff}
body[data-dp-theme-mode="dark"] .dp-panel-horizontal-link.active,body[data-dp-theme-mode="dark"] .dp-panel-horizontal-group.active>summary,body[data-dp-theme-mode="dark"] .dp-panel-horizontal-item.active{background:#20375d;color:#eaf2ff;border-color:#3b64a4}
@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"] .dp-panel-sidebar-context{background:linear-gradient(135deg,#1d3354,#151f2e);border-color:#34537f}body[data-dp-theme-mode="system"] .dp-panel-horizontal-nav,body[data-dp-theme-mode="system"] .dp-panel-horizontal-group>div{background:#151f2e;border-color:#2c3a4f}body[data-dp-theme-mode="system"] .dp-panel-horizontal-brand,body[data-dp-theme-mode="system"] .dp-panel-horizontal-link:hover,body[data-dp-theme-mode="system"] .dp-panel-horizontal-group>summary:hover{background:#182235;border-color:#34445d;color:#eef4ff}body[data-dp-theme-mode="system"] .dp-panel-horizontal-link.active,body[data-dp-theme-mode="system"] .dp-panel-horizontal-group.active>summary,body[data-dp-theme-mode="system"] .dp-panel-horizontal-item.active{background:#20375d;color:#eaf2ff;border-color:#3b64a4}}
@media(max-width:1180px){.dp-panel-nav-sidebar{display:block}.dp-panel-nav-sidebar .dp-panel-sidebar{position:relative;top:auto;max-height:none;margin:0 0 14px}.dp-panel-nav-sidebar .dp-panel-sidebar-top,.dp-panel-nav-sidebar .dp-panel-sidebar-search{position:relative;top:auto}.dp-panel-nav-sidebar .dp-panel-sidebar-context{display:none}.dp-panel-horizontal-nav{position:relative;top:auto;grid-template-columns:1fr}.dp-panel-horizontal-brand{width:max-content}.dp-panel-horizontal-track{padding-bottom:1px}.dp-panel-horizontal-group>div{left:var(--dp-horizontal-menu-left,10px);right:auto}}
@media(max-width:720px){.dp-panel-horizontal-nav{border-radius:18px;padding:8px}.dp-panel-horizontal-brand{display:none}.dp-panel-horizontal-link,.dp-panel-horizontal-group>summary{min-height:38px;padding:6px 9px}.dp-panel-horizontal-link span{width:26px;height:26px}.dp-panel-horizontal-group>div{position:fixed;left:10px;right:10px;top:auto;width:auto;max-height:60vh}.dp-panel-sidebar-collapsed{grid-template-columns:1fr}.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand strong,.dp-panel-sidebar-collapsed .dp-panel-sidebar-brand small,.dp-panel-sidebar-collapsed .dp-panel-sidebar-search,.dp-panel-sidebar-collapsed .dp-panel-sidebar-context,.dp-panel-sidebar-collapsed .dp-panel-sidebar-copy,.dp-panel-sidebar-collapsed .dp-panel-sidebar-badge,.dp-panel-sidebar-collapsed .dp-panel-sidebar-group h2{display:grid}.dp-panel-sidebar-collapsed .dp-panel-sidebar-link{grid-template-columns:auto minmax(0,1fr) auto;justify-items:stretch}}
CSS;
	}

}
