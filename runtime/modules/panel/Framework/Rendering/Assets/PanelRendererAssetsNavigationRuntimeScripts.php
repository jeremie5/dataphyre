<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits the sidebar, mobile navigation, and panel UI runtime.
 */
trait PanelRendererAssetsNavigationRuntimeScripts {
	/**
	 * Returns this controller module for the public Panel runtime bundle.
	 */
	private static function navigationRuntimeScript(): string {
		return <<<'JS'
function dpPanelSetMobileNavigationOpen(open,panel,restoreFocus){
	panel=panel||dpPanelMobileNavigationPanel();
	if(!panel){return;}
	open=!!open;
	var wasOpen=panel.classList.contains("dp-panel-mobile-nav-open");
	panel.classList.toggle("dp-panel-mobile-nav-open",open);
	if(document.body){document.body.classList.toggle("dp-panel-mobile-nav-open",open);}
	var button=panel.querySelector("[data-dp-panel-mobile-nav-toggle]");
	var region=panel.querySelector(".dp-panel-main-region");
	if(region){region.toggleAttribute("inert",open);if(open){region.setAttribute("aria-hidden","true");}else{region.removeAttribute("aria-hidden");}}
	if(button){
		button.setAttribute("aria-expanded",open?"true":"false");
		button.setAttribute("aria-label",open?dpPanelText("nav.close_navigation","Close navigation"):dpPanelText("nav.open_navigation","Open navigation"));
	}
	if(open){
		if(typeof dpPanelCloseTransientPanels==="function"){dpPanelCloseTransientPanels(null);}
		requestAnimationFrame(function(){var sidebar=panel.querySelector("[data-dp-panel-sidebar]");var focus=sidebar&&(sidebar.querySelector(".dp-panel-mobile-nav-dismiss")||dpPanelFocusable(sidebar)[0]);if(focus){focus.focus();}});
	}
	else if(wasOpen&&button&&restoreFocus!==false){requestAnimationFrame(function(){button.focus();});}
}
/**
 * Closes every open mobile navigation drawer.
 *
 * @returns {void}
 */
function dpPanelCloseMobileNavigation(restoreFocus){
	document.querySelectorAll('main.dp-panel-mobile-nav-open[data-dp-panel-mobile-navigation="drawer"]').forEach(function(panel){
		dpPanelSetMobileNavigationOpen(false,panel,restoreFocus);
	});
}
/**
 * Suppresses the next automatic active-sidebar scroll.
 *
 * @returns {void}
 */
function dpPanelSuppressNextSidebarAutoScroll(){
	try{sessionStorage.setItem("dataphyre_panel_sidebar_skip_next_auto_scroll",String(Date.now()+1200));}catch(error){}
}
/**
 * Scrolls the active sidebar item into view on compact layouts.
 *
 * @returns {void}
 */
function dpPanelScrollActiveSidebarIntoView(){
	if(!window.matchMedia||!window.matchMedia("(max-width: 1180px)").matches){return;}
	try{
		var skipUntil=parseInt(sessionStorage.getItem("dataphyre_panel_sidebar_skip_next_auto_scroll")||"0",10)||0;
		if(skipUntil>Date.now()){return;}
		if(skipUntil>0){
			sessionStorage.removeItem("dataphyre_panel_sidebar_skip_next_auto_scroll");
		}
	}catch(error){}
	var sidebar=document.querySelector("[data-dp-panel-sidebar]");
	if(!sidebar){return;}
	var active=sidebar.querySelector(".dp-panel-sidebar-link.active")||sidebar.querySelector(".dp-panel-sidebar-group.active .dp-panel-sidebar-link");
	if(!active||typeof active.scrollIntoView!=="function"){return;}
	setTimeout(function(){
		try{active.scrollIntoView({block:"nearest",inline:"center",behavior:"smooth"});}
		catch(error){active.scrollIntoView(false);}
	},80);
}
/**
 * Refreshes sidebar collapsed state, groups, search, and active-item scroll.
 *
 * @returns {void}
 */
function dpPanelRefreshSidebar(){
	dpPanelSetSidebarCollapsed(dpPanelSidebarCollapsed());
	dpPanelPrepareSidebarGroups();
	dpPanelRefreshSidebarSearch();
	dpPanelScrollActiveSidebarIntoView();
}
/**
 * Refreshes all client-side Panel UI affordances after render or patch.
 *
 * This is the central browser runtime reconciliation step for dependencies,
 * dirty state, saved views, selection, table controls, sidebar navigation,
 * horizontal navigation, refresh regions, and step controls.
 *
 * @returns {void}
 */
function dpPanelRefreshPanelUi(){
	var main=document.querySelector("main.dp-panel");
	if(main&&!main.dataset.dpPanelCurrentUrl){main.dataset.dpPanelCurrentUrl=location.href;}
	if(typeof dpPanelRefreshThemeModeControls==="function"){dpPanelRefreshThemeModeControls();}
	dpPanelRefreshDependencies();
	dpPanelInitFormState();
	dpPanelRefreshDirtyState();
	dpPanelRefreshSavedViewControls();
	dpPanelUpdateBulkSelection();
	dpPanelInitTableRows();
	dpPanelPrepareRowActionMenus();
	dpPanelInitColumnResizing();
	dpPanelRefreshTableScroll();
	dpPanelInitColumnPickers();
	dpPanelRefreshSidebar();
	dpPanelScrollActiveHorizontalNavigationIntoView();
	dpPanelRefreshPinnedNavigation();
	dpPanelRefreshRecentNavigation();
	dpPanelRefreshRegionControls();
	document.querySelectorAll("[data-dp-panel-steps]").forEach(dpPanelApplyStepControlState);
}
var dpPanelAjaxController=null;
var dpPanelAjaxRequest=null;
var dpPanelAjaxRequestId=0;
var dpPanelAjaxLiveTimer=null;
var dpPanelAjaxLiveRefreshTimer=null;
var dpPanelRegionRefreshTimer=null;
var dpPanelLazyRefreshTimer=null;
var dpPanelLazyPrefetchTimer=null;
var dpPanelAjaxLiveFailures=0;
var dpPanelAjaxLastActivity=Date.now();
/**
 * Checks whether the browser supports Panel ajax navigation.
 *
 * @returns {boolean} Whether fetch, DOMParser, and history APIs are available.
 */
JS;
	}

}
