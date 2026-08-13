<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits the ajax navigation, refresh scheduling, and global lifecycle runtime.
 */
trait PanelRendererAssetsAjaxRuntimeScripts {
	/**
	 * Returns this controller module for the public Panel runtime bundle.
	 */
	private static function ajaxRuntimeScript(): string {
		return <<<'JS'
function dpPanelAjaxEnabled(){
	return !!(window.fetch&&window.DOMParser&&window.history&&history.pushState);
}
/**
 * Reads the persisted live-update pause state.
 *
 * @returns {boolean} Whether live updates are paused.
 */
function dpPanelLivePaused(){
	try{return localStorage.getItem("dataphyre_panel_live_paused")==="1";}catch(error){return false;}
}
/**
 * Persists and applies live-update pause state.
 *
 * Pausing cancels scheduled live and region refreshes; resuming immediately marks
 * the live control as syncing and schedules both refresh pipelines.
 *
 * @param {boolean} paused Desired pause state.
 * @returns {void}
 */
function dpPanelSetLivePaused(paused){
	try{localStorage.setItem("dataphyre_panel_live_paused",paused?"1":"0");}catch(error){}
	document.querySelectorAll("[data-dp-panel-live-control]").forEach(function(control){
		control.classList.toggle("dp-panel-live-paused",paused);
		var toggle=control.querySelector("[data-dp-panel-live-toggle]");
		var state=control.querySelector("[data-dp-panel-live-state]");
		if(toggle){
			toggle.setAttribute("aria-pressed",paused?"true":"false");
			toggle.title=paused?dpPanelText("client.resume_live_updates","Resume live updates"):dpPanelText("client.pause_live_updates","Pause live updates");
		}
		if(state){state.textContent=paused?dpPanelText("client.paused","Paused"):dpPanelText("client.live","Live");}
	});
	if(paused){
		clearTimeout(dpPanelAjaxLiveRefreshTimer);
		clearTimeout(dpPanelRegionRefreshTimer);
		dpPanelSetLiveMessage(dpPanelText("client.paused","Paused"),"neutral");
	}
	else{
		dpPanelSetLiveMessage(dpPanelText("client.resuming_updates","Resuming updates"),"syncing");
		dpPanelAjaxScheduleLiveRefresh();
		dpPanelScheduleRegionRefreshes();
	}
}
/**
 * Reconciles live-update controls with persisted pause state.
 *
 * @returns {void}
 */
function dpPanelRefreshLiveControls(){
	dpPanelSetLivePaused(dpPanelLivePaused());
}
/**
 * Formats a server refresh timestamp for live-update controls.
 *
 * @param {string} value ISO-ish timestamp.
 * @returns {string} Localized timestamp label.
 */
function dpPanelLiveTimeLabel(value){
	if(!value){return dpPanelText("client.updated_now","Updated now");}
	var date=new Date(value);
	if(isNaN(date.getTime())){return dpPanelText("client.updated_now","Updated now");}
	return dpPanelText("client.updated_at","Updated {time}",{time:date.toLocaleTimeString([], {hour:"2-digit",minute:"2-digit",second:"2-digit"})});
}
/**
 * Updates live-update control labels and tone metadata.
 *
 * @param {string} label Human-facing status label.
 * @param {string} tone Status tone.
 * @returns {void}
 */
function dpPanelSetLiveMessage(label,tone){
	document.querySelectorAll("[data-dp-panel-live-control]").forEach(function(control){
		control.dataset.dpPanelLiveTone=tone||"neutral";
		control.title=label;
	});
	document.querySelectorAll("[data-dp-panel-live-updated]").forEach(function(item){
		if(item.textContent!==label){item.textContent=label;}
		item.setAttribute("aria-label",dpPanelText("client.live_updates_label","Live updates: {label}",{label:label}));
	});
}
/**
 * Marks a successful live refresh status.
 *
 * @param {Object<string, *>|null} payload Applied refresh payload.
 * @param {boolean} unchanged Whether the payload matched the current signature.
 * @returns {void}
 */
function dpPanelSetLiveStatus(payload,unchanged){
	dpPanelAjaxLiveFailures=0;
	var label=unchanged ? dpPanelText("client.no_changes","No changes") : dpPanelLiveTimeLabel(payload&&payload.refreshed_at);
	dpPanelSetLiveMessage(label,unchanged ? "neutral" : "success");
}
/**
 * Calculates exponential backoff for live refresh retries.
 *
 * @param {number} interval Base interval in milliseconds.
 * @returns {number} Retry delay in milliseconds.
 */
function dpPanelLiveRetryDelay(interval){
	if(dpPanelAjaxLiveFailures<=0){return interval;}
	var factor=Math.pow(2,Math.min(dpPanelAjaxLiveFailures,5));
	return Math.min(300000,interval*factor);
}
/**
 * Formats a live-refresh retry delay.
 *
 * @param {number} delay Delay in milliseconds.
 * @returns {string} Localized retry label.
 */
function dpPanelLiveRetryLabel(delay){
	var seconds=Math.max(1,Math.round(delay/1000));
	if(seconds<60){return dpPanelText("client.retry_seconds","Retrying in {seconds}s",{seconds:seconds});}
	return dpPanelText("client.retry_minutes","Retrying in {minutes}m",{minutes:Math.round(seconds/60)});
}
/**
 * Displays toast notifications embedded in an ajax payload.
 *
 * @param {Object<string, *>|null} payload Server payload.
 * @returns {void}
 */
function dpPanelNotifyPayload(payload){
	if(!payload||!Array.isArray(payload.notifications)){return;}
	payload.notifications.forEach(function(notification){
		if(!notification){return;}
		dpPanelToast(notification,typeof notification==="string"?"info":notification.type||notification.tone||"info");
	});
}
/**
 * Emits boot-time notifications from the rendered Panel root.
 *
 * @returns {void}
 */
function dpPanelBootNotifications(){
	var main=document.querySelector("main.dp-panel[data-dp-panel-notifications]");
	if(!main){return;}
	var notifications=[];
	try{notifications=JSON.parse(main.dataset.dpPanelNotifications||"[]")||[];}catch(error){notifications=[];}
	main.removeAttribute("data-dp-panel-notifications");
	dpPanelNotifyPayload({notifications:notifications});
}
/**
 * Extracts a document title from HTML text without executing it.
 *
 * @param {string} html Response HTML.
 * @returns {string} Decoded title text.
 */
function dpPanelResponseTitle(html){
	if(!html){return "";}
	var match=String(html).match(/<title[^>]*>(.*?)<\/title>/i);
	if(!match){return "";}
	var area=document.createElement("textarea");
	area.innerHTML=match[1].replace(/<[^>]*>/g,"");
	return area.value.trim();
}
/**
 * Detects whether a decoded JSON value matches Panel fragment payload shape.
 *
 * @param {*} payload Decoded response candidate.
 * @returns {boolean} Whether the value looks like a Panel payload.
 */
function dpPanelLooksLikePayload(payload){
	if(!payload||typeof payload!=="object"||Array.isArray(payload)){return false;}
	if(typeof payload.redirect_to==="string"&&payload.redirect_to!==""){return true;}
	if(typeof payload.html==="string"){return true;}
	if(Array.isArray(payload.notifications)&&payload.status!==undefined){return true;}
	return false;
}
/**
 * Parses the HTML document embedded in a Panel payload.
 *
 * @param {Object<string, *>|null} payload Panel ajax payload.
 * @returns {Document|null} Parsed document or null when no HTML exists.
 */
function dpPanelPayloadDoc(payload){
	if(!payload||typeof payload.html!=="string"||payload.html===""){return null;}
	return new DOMParser().parseFromString(payload.html,"text/html");
}
/**
 * Normalizes a fetch response into the Panel fragment payload contract.
 *
 * JSON responses keep server-provided fields; HTML responses are wrapped with
 * title, signature, refreshed timestamp, status, and empty notifications so the
 * rest of the ajax pipeline can treat both formats uniformly.
 *
 * @param {Response} response Fetch response.
 * @returns {Promise<Object<string, *>>} Normalized Panel payload.
 */
function dpPanelFragmentPayload(response){
	var type=(response.headers&&response.headers.get("content-type")||"").toLowerCase();
	if(type.indexOf("application/json")!==-1){
		return response.json().then(function(payload){
			if(payload&&typeof payload==="object"&&!Array.isArray(payload)){
				if(payload.status===undefined){payload.status=response.status;}
				return payload;
			}
			return {status:response.status,data:payload};
		});
	}
	return response.text().then(function(text){
		var trimmed=text.trim();
		if(trimmed.charAt(0)==="{"||trimmed.charAt(0)==="["){
			try{
				var payload=JSON.parse(trimmed);
				if(dpPanelLooksLikePayload(payload)){
					if(payload.status===undefined){payload.status=response.status;}
					return payload;
				}
			}catch(error){}
		}
		return {
			html:text,
			title:dpPanelResponseTitle(text),
			signature:text ? String(response.status)+":"+text.length+":"+text.slice(0,64) : "",
			refreshed_at:new Date().toISOString(),
			status:response.status,
			notifications:[]
		};
	});
}
/**
 * Removes Panel-only partial parameters from a URL before history storage.
 *
 * @param {string|URL} url Source URL.
 * @returns {URL} Clean URL.
 */
function dpPanelAjaxCleanUrl(url){
	var clean=new URL(url,location.href);
	clean.searchParams.delete("__panel_partial");
	return clean;
}
/**
 * Adds the Panel fragment partial parameter to a URL.
 *
 * @param {string|URL} url Source URL.
 * @returns {URL} Fragment request URL.
 */
function dpPanelAjaxFragmentUrl(url){
	var next=new URL(url,location.href);
	next.searchParams.set("__panel_partial","fragment");
	return next;
}
/**
 * Adds transient ajax request parameters for deferred refresh targets.
 *
 * @param {string|URL} url Source URL.
 * @param {Object<string, *>} options Ajax options.
 * @returns {URL} URL with transient parameters.
 */
function dpPanelAjaxApplyTransientParams(url,options){
	var next=new URL(url,location.href);
	if(options&&Array.isArray(options.deferTargets)&&options.deferTargets.length){
		next.searchParams.set("__panel_defer",options.deferTargets.join(","));
	}
	return next;
}
/**
 * Finds lazy refresh targets among requested refresh targets.
 *
 * @param {string[]} targets Refresh targets requested by effects or callers.
 * @returns {string[]} Targets represented by lazy refresh regions in the DOM.
 */
function dpPanelLazyTargetsFromTargets(targets){
	if(!Array.isArray(targets)||!targets.length){return [];}
	var lazy=[];
	targets.forEach(function(target){
		target=String(target||"").toLowerCase();
		if(!target){return;}
		var selector='main.dp-panel [data-dp-panel-refresh-lazy="1"][data-dp-panel-refresh-lazy-target="'+dpPanelCssEscape(target)+'"]';
		if(document.querySelector(selector)&&lazy.indexOf(target)===-1){lazy.push(target);}
	});
	return lazy;
}
/**
 * Merges refresh target lists while preserving first-seen order.
 *
 * @param {string[]} first First target list.
 * @param {string[]} second Second target list.
 * @returns {string[]} Unique lowercase target list.
 */
function dpPanelMergeTargets(first,second){
	var merged=[];
	(first||[]).concat(second||[]).forEach(function(target){
		target=String(target||"").toLowerCase();
		if(target&&merged.indexOf(target)===-1){merged.push(target);}
	});
	return merged;
}
/**
 * Resolves the query parameter used for theme preset state.
 *
 * @returns {string} Theme preset parameter name.
 */
function dpPanelThemePresetParameter(){
	var selector=document.querySelector("[data-dp-panel-theme-select]");
	return selector&&selector.dataset.dpPanelThemeParameter ? selector.dataset.dpPanelThemeParameter : "panel_theme";
}
/**
 * Reads a theme preset value from a URL.
 *
 * @param {string|URL} url URL to inspect.
 * @returns {string} Lowercase theme preset value.
 */
function dpPanelUrlThemePreset(url){
	var parsed=new URL(url,location.href);
	var parameter=dpPanelThemePresetParameter();
	return (parsed.searchParams.get(parameter)||parsed.searchParams.get("preset")||"").toLowerCase();
}
/**
 * Checks whether ajax navigation would preserve the current theme preset.
 *
 * @param {string|URL} url Target URL.
 * @returns {boolean} Whether current and target theme preset values match.
 */
function dpPanelAjaxPreservesTheme(url){
	var current=dpPanelUrlThemePreset(location.href);
	var target=dpPanelUrlThemePreset(url);
	return current===target;
}
/**
 * Resolves the current theme preset from URL or local storage.
 *
 * @returns {string} Lowercase theme preset value.
 */
function dpPanelCurrentThemePreset(){
	var current=dpPanelUrlThemePreset(location.href);
	if(current){return current;}
	try{return (localStorage.getItem("dataphyre_panel_theme_preset")||"").toLowerCase();}catch(error){return "";}
}
/**
 * Removes theme preset parameters from a URL for navigation de-duplication.
 *
 * @param {string} href Source href.
 * @returns {string} Theme-neutral href.
 */
function dpPanelThemeNeutralHref(href){
	var url=new URL(href,location.href);
	var parameter=dpPanelThemePresetParameter();
	[parameter,"preset"].forEach(function(name){if(name){url.searchParams.delete(name);}});
	return url.pathname+(url.searchParams.toString() ? "?"+url.searchParams.toString() : "")+url.hash;
}
/**
 * Adds the current theme preset to a theme-neutral href.
 *
 * @param {string} href Source href.
 * @returns {string} Href carrying current theme state when present.
 */
function dpPanelHrefForCurrentTheme(href){
	var url=new URL(dpPanelThemeNeutralHref(href),location.href);
	var preset=dpPanelCurrentThemePreset();
	if(preset){url.searchParams.set(dpPanelThemePresetParameter(),preset);}
	return url.pathname+(url.searchParams.toString() ? "?"+url.searchParams.toString() : "")+url.hash;
}
/**
 * Enforces the client-side ajax navigation safety boundary.
 *
 * Ajax navigation is limited to same-origin, theme-preserving, non-export Panel
 * fragment URLs. Everything else falls back to browser navigation.
 *
 * @param {string|URL} url Candidate URL.
 * @returns {boolean} Whether the URL is safe for ajax loading.
 */
function dpPanelAjaxAllowedUrl(url){
	var next;
	try{next=new URL(url,location.href);}catch(error){return false;}
	if(next.origin!==location.origin){return false;}
	if(!dpPanelAjaxPreservesTheme(next)){return false;}
	var partial=(next.searchParams.get("__panel_partial")||"").toLowerCase();
	if(partial&&partial!=="fragment"){return false;}
	var operation=(next.searchParams.get("operation")||"").toLowerCase();
	if(["export","bulk_export","import_template"].indexOf(operation)!==-1){return false;}
	var pathOperations=next.pathname.split("/").filter(Boolean).map(function(segment){
		try{return decodeURIComponent(segment).toLowerCase().replace(/-/g,"_");}catch(error){return segment.toLowerCase().replace(/-/g,"_");}
	});
	if(pathOperations.some(function(segment){return ["export","bulk_export","import_template"].indexOf(segment)!==-1;})){return false;}
	return true;
}
/**
 * Extracts effect metadata from a Panel payload.
 *
 * @param {Object<string, *>|null} payload Server payload.
 * @returns {Object<string, *>} Effect map.
 */
function dpPanelPayloadEffects(payload){
	if(!payload){return {};}
	var effects=payload.effects&&typeof payload.effects==="object" ? payload.effects : null;
	if(!effects&&payload.data&&payload.data.effects&&typeof payload.data.effects==="object"){effects=payload.data.effects;}
	return effects||{};
}
/**
 * Extracts normalized refresh targets from effect metadata.
 *
 * @param {Object<string, *>|null} effects Effect map.
 * @returns {string[]} Refresh target names.
 */
function dpPanelEffectRefreshTargets(effects){
	var refresh=effects&&effects.refresh!==undefined ? effects.refresh : [];
	if(typeof refresh==="string"){refresh=refresh.split(/[,\s]+/);}
	if(!Array.isArray(refresh)){return [];}
	return refresh.map(function(target){return String(target||"").toLowerCase().trim();}).filter(Boolean);
}
/**
 * Determines whether effects should close an open modal.
 *
 * @param {Object<string, *>|null} effects Effect map.
 * @param {boolean} defaultValue Default close behavior.
 * @returns {boolean} Whether modal close should occur.
 */
function dpPanelEffectCloseModal(effects,defaultValue){
	if(effects&&Object.prototype.hasOwnProperty.call(effects,"close_modal")){return effects.close_modal!==false;}
	return defaultValue!==false;
}
/**
 * Resolves post-submit modal navigation from action effects.
 *
 * back restores a parent snapshot, stay preserves the current dialog, and
 * close exits the complete modal flow. Legacy close_modal remains supported.
 */
function dpPanelEffectModalNavigation(effects,defaultValue){
	var strategy=effects&&effects.modal_navigation!==undefined ? String(effects.modal_navigation||"").toLowerCase().replace(/[^a-z_]/g,"") : "";
	if(["back","close","stay"].indexOf(strategy)!==-1){return strategy;}
	return dpPanelEffectCloseModal(effects,defaultValue)!==false ? "close" : "stay";
}
/**
 * Checks whether refresh targets represent the whole Panel.
 *
 * @param {string[]} targets Refresh target names.
 * @returns {boolean} Whether targets include panel, all, or wildcard.
 */
function dpPanelRefreshTargetIncludesPanel(targets){
	return targets.indexOf("panel")!==-1||targets.indexOf("*")!==-1||targets.indexOf("all")!==-1;
}
/**
 * Converts refresh target names into DOM selectors.
 *
 * @param {string[]} targets Refresh target names.
 * @returns {string[]} Unique selectors for refresh regions.
 */
function dpPanelRefreshTargetSelectors(targets){
	var selectors=[];
	targets.forEach(function(target){
		var parts=String(target||"").split(":");
		var type=(parts.shift()||"").trim();
		var key=parts.join(":").trim();
		if(type==="table"||type==="tables"){
			selectors.push(key ? '[data-dp-panel-refresh-region="table"][data-dp-panel-refresh-key="'+dpPanelCssEscape(key)+'"]' : '[data-dp-panel-refresh-region="table"]');
		}
		else if(type==="widgets"||type==="widget"){
			selectors.push('[data-dp-panel-refresh-region="widgets"]');
		}
		else if(type==="navigation"||type==="nav"||type==="sidebar"){
			selectors.push('[data-dp-panel-refresh-region="navigation"]');
		}
		else if(type==="region"||type==="island"||type==="custom"){
			if(key){selectors.push('[data-dp-panel-refresh-key="'+dpPanelCssEscape(key)+'"]');}
		}
		else if(type){
			selectors.push('[data-dp-panel-refresh-key="'+dpPanelCssEscape(type)+'"]');
		}
	});
	return selectors.filter(function(selector,index,array){return selector&&array.indexOf(selector)===index;});
}
/**
 * Patches targeted refresh regions from a parsed replacement Panel.
 *
 * Targeted refresh avoids replacing the whole root when the server names a
 * specific table, widget, navigation, island, or custom region. It returns the
 * number of regions actually replaced.
 *
 * @param {HTMLElement} currentMain Current Panel root.
 * @param {HTMLElement} nextMain Parsed replacement Panel root.
 * @param {string[]} targets Refresh targets.
 * @returns {number} Number of patched regions.
 */
function dpPanelPatchRefreshTargets(currentMain,nextMain,targets){
	if(!currentMain||!nextMain||!targets.length||dpPanelRefreshTargetIncludesPanel(targets)){return 0;}
	var selectors=dpPanelRefreshTargetSelectors(targets);
	var patched=0;
	selectors.forEach(function(selector){
		var currentNodes=Array.from(currentMain.querySelectorAll(selector));
		currentNodes.forEach(function(currentNode,index){
			var key=currentNode.getAttribute("data-dp-panel-refresh-key")||"";
			var region=currentNode.getAttribute("data-dp-panel-refresh-region")||"";
			var matchSelector=selector;
			if(region){
				matchSelector='[data-dp-panel-refresh-region="'+dpPanelCssEscape(region)+'"]'+(key ? '[data-dp-panel-refresh-key="'+dpPanelCssEscape(key)+'"]' : "");
			}
			var nextNode=nextMain.querySelector(matchSelector);
			if(!nextNode){
				nextNode=nextMain.querySelectorAll(selector)[index]||null;
			}
			if(!nextNode){return;}
			currentNode.replaceWith(nextNode.cloneNode(true));
			patched++;
		});
	});
	return patched;
}
/**
 * Dispatches custom browser events declared by a Panel payload.
 *
 * @param {Object<string, *>|null} payload Server payload.
 * @param {string} source Effect source label.
 * @returns {Object<string, *>} Effect map.
 */
function dpPanelDispatchEffects(payload,source){
	var effects=dpPanelPayloadEffects(payload);
	(effects.events||[]).forEach(function(event){
		if(!event||!event.name){return;}
		document.dispatchEvent(new CustomEvent(String(event.name),{
			bubbles:true,
			detail:Object.assign({source:source||"panel",payload:payload},event.detail||{})
		}));
	});
	var targets=dpPanelEffectRefreshTargets(effects);
	if(targets.length){
		document.dispatchEvent(new CustomEvent("dataphyre:panel-action-refresh",{
			bubbles:true,
			detail:{targets:targets,payload:payload,source:source||"panel"}
		}));
	}
	return effects;
}
/**
 * Applies a normalized Panel ajax payload to the current page.
 *
 * The application path handles redirects, unchanged live signatures, targeted
 * refreshes, full root patching, history updates, focus/view-state restoration,
 * notification dispatch, lazy-region scheduling, and custom effect events.
 *
 * @param {Object<string, *>|null} payload Normalized Panel payload.
 * @param {string|URL} url Source URL for history and current-url state.
 * @param {Object<string, *>} options Ajax behavior options.
 * @returns {boolean} Whether the payload was applied.
 */
function dpPanelAjaxApply(payload,url,options){
	options=options||{};
	if(!payload){return false;}
	if(payload.redirect_to){
		dpPanelDispatchEffects(payload,"redirect");
		var redirectOptions={
			replace:options.replace===true,
			history:options.history,
			preserveScroll:options.preserveScroll,
			silent:options.silent,
			live:options.live,
			quiet:options.quiet,
			allowDuringMutation:true,
			targetedRefresh:false,
			refreshTargets:[],
			suppressEffects:true
		};
		setTimeout(function(){
			dpPanelAjaxLoad(payload.redirect_to,redirectOptions);
		},0);
		return true;
	}
	if(!payload.html){return false;}
	var previousY=window.scrollY||document.documentElement.scrollTop||0;
	var preserveMobileNavigation=!!(document.body&&document.body.classList.contains("dp-panel-mobile-nav-open"));
	var currentMain=document.querySelector("main.dp-panel");
	var preserveFocus=options.preserveFocus===true||options.live===true||options.silent===true||options.quiet===true;
	var focusState=preserveFocus ? dpPanelCapturePanelFocus(currentMain) : null;
	var viewState=options.preserveViewState===false ? null : dpPanelCapturePanelViewState(currentMain);
	var sidebarScrollState=options.navigation===true ? dpPanelCaptureSidebarScrollState(currentMain) : [];
	var rowState=options.live===true&&dpPanelUpdateFlashEnabled(currentMain) ? dpPanelCaptureRowState(currentMain) : null;
	var doc=dpPanelPayloadDoc(payload);
	var nextMain=doc.querySelector("main.dp-panel");
	if(!nextMain||!currentMain){return false;}
	var refreshTargets=Array.isArray(options.refreshTargets) ? options.refreshTargets : [];
	var currentSignature=currentMain.dataset.dpPanelSignature||"";
	if(options.live===true&&payload.signature&&currentSignature===payload.signature){
		dpPanelSetLiveStatus(payload,true);
		dpPanelAjaxScheduleLiveRefresh();
		dpPanelScheduleRegionRefreshes();
		return true;
	}
	if(payload.signature){nextMain.dataset.dpPanelSignature=payload.signature;}
	if(options.targetedRefresh===true&&refreshTargets.length&&!dpPanelRefreshTargetIncludesPanel(refreshTargets)){
		var patched=dpPanelPatchRefreshTargets(currentMain,nextMain,refreshTargets);
		if(patched>0){
			dpPanelRestoreTableLayouts(currentMain,viewState);
			dpPanelSyncPageStateScripts(doc);
			dpPanelCloseTransientChrome();
			if(preserveMobileNavigation){dpPanelSetMobileNavigationOpen(true,currentMain);}
			dpPanelInitTabs();
			dpPanelInitSteps();
			dpPanelInitRepeaters();
			dpPanelRefreshPanelUi();
			dpPanelNotifyPayload(payload);
			if(options.suppressEffects!==true){dpPanelDispatchEffects(payload,"fragment");}
			dpPanelAjaxScheduleLiveRefresh();
			dpPanelScheduleRegionRefreshes();
			dpPanelLoadLazyRefreshRegions();
			refreshTargets.forEach(function(target){dpPanelSetRefreshTargetStatus(target,dpPanelText("client.updated_now","Updated now"),"success");});
			if(options.preserveScroll!==true&&options.scrollTarget==="table"){dpPanelScrollTableIntoView(currentMain);}
			document.dispatchEvent(new CustomEvent("dataphyre:panel-refresh",{detail:payload}));
			document.dispatchEvent(new CustomEvent("dataphyre:panel-target-refresh",{detail:{targets:refreshTargets,payload:payload,patched:patched}}));
			return true;
		}
	}
	currentMain=dpPanelPatchMainElement(currentMain,nextMain);
	if(!currentMain){return false;}
	if(options.navigation===true){
		currentMain.classList.add("dp-panel-navigation-enter");
		requestAnimationFrame(function(){
			currentMain.classList.add("dp-panel-navigation-enter-active");
			setTimeout(function(){
				currentMain.classList.remove("dp-panel-navigation-enter","dp-panel-navigation-enter-active");
			},260);
		});
	}
	dpPanelRestoreTableLayouts(currentMain,viewState);
	dpPanelSyncPageStateScripts(doc);
	dpPanelCloseTransientChrome();
	if(preserveMobileNavigation){dpPanelSetMobileNavigationOpen(true,currentMain);}
	var nextTitle=payload.title||doc.querySelector("title")&&doc.querySelector("title").textContent||"";
	if(nextTitle){document.title=nextTitle;}
	var clean=dpPanelAjaxCleanUrl(url);
	currentMain.dataset.dpPanelCurrentUrl=clean.toString();
	if(options.history!==false){
		if(options.replace===true){history.replaceState({dataphyrePanel:true},document.title,clean);}
		else{history.pushState({dataphyrePanel:true},document.title,clean);}
	}
	document.body.classList.remove("dp-panel-has-unsaved-changes");
	dpPanelRememberVisit();
	dpPanelInitTabs();
	dpPanelInitSteps();
	dpPanelInitRepeaters();
	dpPanelRefreshPanelUi();
	dpPanelRestorePanelViewState(viewState);
	dpPanelRestoreSidebarScrollState(sidebarScrollState);
	if(sidebarScrollState.length){
		setTimeout(function(){dpPanelRestoreSidebarScrollState(sidebarScrollState);},160);
		setTimeout(function(){dpPanelRestoreSidebarScrollState(sidebarScrollState);},420);
	}
	dpPanelRestorePanelFocus(focusState);
	dpPanelSetLiveStatus(payload,false);
	dpPanelNotifyPayload(payload);
	if(options.suppressEffects!==true){dpPanelDispatchEffects(payload,"fragment");}
	if(options.live===true){
		var refreshed=currentMain||document.querySelector("main.dp-panel");
		if(refreshed&&dpPanelUpdateFlashEnabled(refreshed)){
			refreshed.classList.add("dp-panel-live-fresh");
			setTimeout(function(){refreshed.classList.remove("dp-panel-live-fresh");},1400);
		}
		dpPanelHighlightRowChanges(rowState);
	}
	dpPanelAjaxScheduleLiveRefresh();
	dpPanelScheduleRegionRefreshes();
	dpPanelLoadLazyRefreshRegions();
	if(options.preserveScroll===true){window.scrollTo(0,previousY);}
	else if(options.scrollTarget==="table"){dpPanelScrollTableIntoView(currentMain);}
	else{window.scrollTo({top:0,behavior:"smooth"});}
	document.dispatchEvent(new CustomEvent("dataphyre:panel-refresh",{detail:payload}));
	return true;
}
/**
 * Scrolls the primary table shell into comfortable viewport position.
 *
 * @param {Document|Element} scope Search scope.
 * @returns {void}
 */
function dpPanelScrollTableIntoView(scope){
	var root=scope&&scope.querySelector ? scope : document;
	var target=root.querySelector(".dp-panel-page-table,.dp-panel-table-shell");
	if(!target){return;}
	var rect=target.getBoundingClientRect();
	var viewportHeight=window.innerHeight||document.documentElement.clientHeight||0;
	var topPadding=Math.max(14,Math.min(84,viewportHeight*.08));
	var top=Math.max(0,(window.scrollY||document.documentElement.scrollTop||0)+rect.top-topPadding);
	window.scrollTo({top:top,behavior:"smooth"});
}
/**
 * Loads a Panel URL through the fragment ajax pipeline.
 *
 * The loader enforces the ajax URL safety boundary, serializes concurrent
 * mutations, aborts superseded requests, normalizes payloads, applies them, and
 * falls back to browser navigation when in-place refresh is not possible.
 *
 * @param {string|URL} url Target URL.
 * @param {Object<string, *>} options Ajax behavior options.
 * @returns {void}
 */
function dpPanelAjaxLoad(url,options){
	if(!dpPanelAjaxEnabled()||!dpPanelAjaxAllowedUrl(url)){location.href=url;return;}
	options=options||{};
	if(Array.isArray(options.refreshTargets)&&options.refreshTargets.length){
		options.deferTargets=dpPanelMergeTargets(options.deferTargets||[],dpPanelLazyTargetsFromTargets(options.refreshTargets));
	}
	var fragmentUrl=dpPanelAjaxFragmentUrl(dpPanelAjaxApplyTransientParams(url,options));
	var method=(options.method||"GET").toUpperCase();
	var mutation=method!=="GET"&&method!=="HEAD";
	if(dpPanelAjaxRequest&&dpPanelAjaxRequest.mutation&&options.allowDuringMutation!==true){
		if(options.live===true||options.silent===true){
			dpPanelAjaxScheduleLiveRefresh();
			return;
		}
		dpPanelToast(dpPanelText("client.action_in_progress","Let the current action finish first"),"warning");
		return;
	}
	if(dpPanelAjaxController){
		dpPanelAjaxController.abort();
	}
	var controller=new AbortController();
	var requestId=++dpPanelAjaxRequestId;
	dpPanelAjaxController=controller;
	dpPanelAjaxRequest={id:requestId,controller:controller,mutation:mutation,live:options.live===true,silent:options.silent===true};
	var main=document.querySelector("main.dp-panel");
	var visibleLoading=options.quiet!==true&&options.silent!==true&&(options.live!==true||dpPanelUpdateFlashEnabled(main));
	if(main&&visibleLoading){main.classList.add("dp-panel-ajax-loading");}
	if(options.live===true){
		dpPanelSetLiveMessage("Checking...", "syncing");
	}
	var fetchOptions={
		method:method,
		credentials:"same-origin",
		headers:Object.assign({
			"Accept":"application/json",
			"X-Requested-With":"DataphyrePanelFragment"
		},options.headers||{}),
		signal:controller.signal
	};
	if(method!=="GET"&&method!=="HEAD"&&options.body){
		fetchOptions.body=options.body;
	}
	fetch(fragmentUrl,{
		method:fetchOptions.method,
		credentials:fetchOptions.credentials,
		headers:fetchOptions.headers,
		body:fetchOptions.body,
		signal:fetchOptions.signal
	}).then(function(response){
		return dpPanelFragmentPayload(response);
	}).then(function(payload){
		if(!dpPanelAjaxRequest||dpPanelAjaxRequest.id!==requestId){return;}
		if(options.live===true&&payload&&payload.status>=400){
			throw new Error(dpPanelText("client.live_refresh_failed","Live refresh failed with status {status}",{status:payload.status}));
		}
		return dpPanelSyncPayloadAssets(payload,"style").then(function(){return payload;});
	}).then(function(payload){
		if(!payload||!dpPanelAjaxRequest||dpPanelAjaxRequest.id!==requestId){return;}
		if(!dpPanelAjaxApply(payload,url,options)){
			if(options.silent===true){return;}
			if(method==="GET"){location.href=url;}
			else{dpPanelToast(dpPanelText("client.action_refresh_failed","That action could not refresh in place"),"warning");}
			if(typeof options.onFailed==="function"){options.onFailed(payload);}
			return;
		}
		return dpPanelSyncPayloadAssets(payload,"script").then(function(){
			if(typeof options.onApplied==="function"){options.onApplied(payload);}
		});
	}).catch(function(error){
		if(!dpPanelAjaxRequest||dpPanelAjaxRequest.id!==requestId){return;}
		if(error&&error.name==="AbortError"){return;}
		if(options.live===true){
			dpPanelAjaxLiveFailures++;
			dpPanelSetLiveMessage("Update failed, retrying","error");
		}
		if(options.refreshTargets&&options.refreshTargets.length){
			options.refreshTargets.forEach(function(target){dpPanelSetRefreshTargetStatus(target,"Update failed","error");});
			dpPanelResetLazyRefreshTargets(options.refreshTargets);
		}
		if(options.silent===true){return;}
		if(method==="GET"){location.href=url;}
		else{dpPanelToast(dpPanelText("client.action_complete_failed","That action could not complete"),"warning");}
		if(typeof options.onFailed==="function"){options.onFailed(error);}
	}).finally(function(){
		var ownsRequest=dpPanelAjaxRequest&&dpPanelAjaxRequest.id===requestId&&dpPanelAjaxRequest.controller===controller;
		if(ownsRequest){
			var active=document.querySelector("main.dp-panel");
			if(active){active.classList.remove("dp-panel-ajax-loading");}
			if(options.form){dpPanelReleaseSubmitLoading(options.form);}
			dpPanelAjaxRequest=null;
			dpPanelAjaxController=null;
		}
		if(options.silent===true){
			dpPanelAjaxScheduleLiveRefresh();
			dpPanelScheduleRegionRefreshes();
		}
	});
}
/**
 * Checks whether live or regional refresh should be deferred.
 *
 * Refresh pauses while offline, hidden, manually paused, dirty, modal-bound,
 * selected, recently active, or focused in a typing target so server patches do
 * not disrupt active operator work.
 *
 * @returns {boolean} Whether refresh is currently blocked.
 */
function dpPanelAjaxLiveBlocked(){
	if(navigator.onLine===false){
		dpPanelSetLiveMessage(dpPanelText("client.offline","Offline"),"error");
		return true;
	}
	if(document.hidden){return true;}
	if(dpPanelLivePaused()){return true;}
	if(document.body.classList.contains("dp-panel-has-unsaved-changes")){return true;}
	if(document.body.classList.contains("dp-panel-modal-open")){return true;}
	if(document.querySelector(".dp-panel-select input[type='checkbox']:checked")){return true;}
	if(Date.now()-dpPanelAjaxLastActivity<1200){return true;}
	if(document.activeElement&&dpPanelIsTypingTarget(document.activeElement)){return true;}
	return false;
}
/**
 * Builds a refresh-target name from a refresh region node.
 *
 * @param {Element|null} node Refresh region element.
 * @returns {string} Normalized refresh target name.
 */
function dpPanelRefreshTargetForNode(node){
	if(!node){return "";}
	var region=(node.getAttribute("data-dp-panel-refresh-region")||"region").toLowerCase();
	var key=(node.getAttribute("data-dp-panel-refresh-key")||"").toLowerCase();
	if(region==="table"||region==="tables"){return key ? "table:"+key : "table";}
	if(region==="widgets"||region==="widget"){return "widgets";}
	if(region==="navigation"||region==="nav"||region==="sidebar"){return "navigation";}
	if(region==="island"){return key ? "island:"+key : "";}
	if(region==="custom"){return key ? "custom:"+key : "";}
	if(region==="region"){return key ? "region:"+key : "";}
	return key ? region+":"+key : region;
}
/**
 * Loads the persisted refresh pause map.
 *
 * @returns {Object<string, boolean>} Target pause map.
 */
function dpPanelRefreshPauseMap(){
	try{return JSON.parse(localStorage.getItem("dataphyre_panel_refresh_paused")||"{}")||{};}catch(error){return {};}
}
/**
 * Checks whether a refresh target is paused.
 *
 * @param {string} target Refresh target name.
 * @returns {boolean} Whether the target is paused.
 */
function dpPanelRefreshTargetPaused(target){
	target=String(target||"").toLowerCase();
	if(!target){return false;}
	var paused=dpPanelRefreshPauseMap();
	return paused[target]===true;
}
/**
 * Persists pause state for a refresh target and reschedules refresh loops.
 *
 * @param {string} target Refresh target name.
 * @param {boolean} paused Desired pause state.
 * @returns {void}
 */
function dpPanelSetRefreshTargetPaused(target,paused){
	target=String(target||"").toLowerCase();
	if(!target){return;}
	var map=dpPanelRefreshPauseMap();
	if(paused){map[target]=true;}
	else{delete map[target];}
	try{localStorage.setItem("dataphyre_panel_refresh_paused",JSON.stringify(map));}catch(error){}
	dpPanelRefreshRegionControls();
	dpPanelScheduleRegionRefreshes();
}
/**
 * Synchronizes region refresh controls with pause state.
 *
 * @returns {void}
 */
function dpPanelRefreshRegionControls(){
	document.querySelectorAll("[data-dp-panel-refresh-controls]").forEach(function(control){
		var target=(control.getAttribute("data-dp-panel-refresh-target")||"").toLowerCase();
		var paused=dpPanelRefreshTargetPaused(target);
		control.classList.toggle("dp-panel-refresh-controls-paused",paused);
		var toggle=control.querySelector("[data-dp-panel-refresh-toggle]");
		if(toggle){
			toggle.setAttribute("aria-pressed",paused?"true":"false");
			toggle.textContent=paused ? (control.getAttribute("data-dp-panel-refresh-resume-label")||dpPanelText("client.resume","Resume")) : (control.getAttribute("data-dp-panel-refresh-pause-label")||dpPanelText("client.pause","Pause"));
			toggle.title=paused ? dpPanelText("client.resume_region","Resume this region") : dpPanelText("client.pause_region","Pause this region");
		}
		var status=control.querySelector("[data-dp-panel-refresh-status]");
		if(status&&paused){status.textContent=dpPanelText("client.paused","Paused");}
		else if(status&&(status.textContent||"")===dpPanelText("client.paused","Paused")){status.textContent=control.getAttribute("data-dp-panel-refresh-status")||dpPanelText("client.auto_refresh","Auto refresh");}
	});
}
/**
 * Updates a refresh target control status label and tone.
 *
 * @param {string} target Refresh target name.
 * @param {string} label Status label.
 * @param {string} tone Status tone.
 * @returns {void}
 */
function dpPanelSetRefreshTargetStatus(target,label,tone){
	target=String(target||"").toLowerCase();
	document.querySelectorAll("[data-dp-panel-refresh-controls]").forEach(function(control){
		if((control.getAttribute("data-dp-panel-refresh-target")||"").toLowerCase()!==target){return;}
		if(tone){control.dataset.dpPanelRefreshTone=tone;}
		var status=control.querySelector("[data-dp-panel-refresh-status]");
		if(status){status.textContent=label;}
	});
}
/**
 * Checks whether a lazy refresh region is close enough to the viewport.
 *
 * @param {Element|null} node Lazy refresh region.
 * @returns {boolean} Whether the region may load now.
 */
function dpPanelLazyRefreshNearViewport(node){
	if(!node||node.getAttribute("data-dp-panel-refresh-visible")!=="1"){return true;}
	var rect=node.getBoundingClientRect();
	var margin=parseInt(node.getAttribute("data-dp-panel-refresh-visible-margin")||"360",10)||360;
	var height=window.innerHeight||document.documentElement.clientHeight||0;
	var width=window.innerWidth||document.documentElement.clientWidth||0;
	return rect.bottom>=-margin&&rect.right>=-margin&&rect.top<=height+margin&&rect.left<=width+margin;
}
/**
 * Schedules lazy refresh region discovery.
 *
 * @param {number} delay Optional delay in milliseconds.
 * @returns {void}
 */
function dpPanelScheduleLazyRefreshCheck(delay){
	clearTimeout(dpPanelLazyRefreshTimer);
	dpPanelLazyRefreshTimer=setTimeout(dpPanelLoadLazyRefreshRegions,delay===undefined ? 120 : Math.max(0,delay));
}
/**
 * Loads eligible lazy refresh regions through targeted ajax refresh.
 *
 * Optional force targets allow manual or prefetch triggers to load regions that
 * otherwise require explicit user intent.
 *
 * @returns {void}
 */
function dpPanelLoadLazyRefreshRegions(){
	var forceTargets=Array.isArray(arguments[0]) ? arguments[0].map(function(target){return String(target||"").toLowerCase();}) : [];
	var main=document.querySelector("main.dp-panel");
	if(!main||!dpPanelAjaxEnabled()){return;}
	if(dpPanelAjaxRequest){
		setTimeout(function(){dpPanelLoadLazyRefreshRegions(forceTargets);},240);
		return;
	}
	var targets=[];
	main.querySelectorAll("[data-dp-panel-refresh-lazy=\"1\"]").forEach(function(node){
		if(node.dataset.dpPanelRefreshLazyLoading==="1"){return;}
		var target=(node.getAttribute("data-dp-panel-refresh-lazy-target")||dpPanelRefreshTargetForNode(node)||"").toLowerCase();
		if(!target||dpPanelRefreshTargetPaused(target)){return;}
		if(node.getAttribute("data-dp-panel-refresh-manual")==="1"&&forceTargets.indexOf(target)===-1){return;}
		if(!dpPanelLazyRefreshNearViewport(node)){return;}
		node.dataset.dpPanelRefreshLazyLoading="1";
		node.setAttribute("aria-busy","true");
		dpPanelSetRefreshTargetStatus(target,"Loading...","syncing");
		if(targets.indexOf(target)===-1){targets.push(target);}
	});
	if(!targets.length){return;}
	dpPanelAjaxLoad(location.href,{
		replace:true,
		history:false,
		preserveScroll:true,
		quiet:true,
		silent:true,
		targetedRefresh:true,
		refreshTargets:targets,
		deferTargets:targets,
		suppressEffects:true
	});
}
/**
 * Clears loading state for lazy refresh regions after a failed refresh.
 *
 * @param {string[]} targets Refresh target names.
 * @returns {void}
 */
function dpPanelResetLazyRefreshTargets(targets){
	(targets||[]).forEach(function(target){
		target=String(target||"").toLowerCase();
		if(!target){return;}
		document.querySelectorAll('main.dp-panel [data-dp-panel-refresh-lazy="1"][data-dp-panel-refresh-lazy-target="'+dpPanelCssEscape(target)+'"]').forEach(function(node){
			node.dataset.dpPanelRefreshLazyLoading="";
			node.setAttribute("aria-busy","false");
		});
	});
}
/**
 * Resolves the refresh target associated with a lazy refresh trigger.
 *
 * @param {Element|null} trigger Trigger element.
 * @returns {string} Refresh target name.
 */
function dpPanelLazyRefreshTargetFromTrigger(trigger){
	if(!trigger){return "";}
	var direct=trigger.getAttribute&&((trigger.getAttribute("data-dp-panel-refresh-now")||trigger.getAttribute("data-dp-panel-refresh-lazy-target")||trigger.getAttribute("data-dp-panel-refresh-target")||"").toLowerCase());
	if(direct){return direct;}
	var lazy=trigger.closest&&trigger.closest("[data-dp-panel-refresh-lazy-target]");
	if(lazy){return (lazy.getAttribute("data-dp-panel-refresh-lazy-target")||"").toLowerCase();}
	return "";
}
/**
 * Schedules a small prefetch for a lazy refresh target.
 *
 * @param {Element|null} trigger Prefetch trigger.
 * @returns {void}
 */
function dpPanelPrefetchLazyRefresh(trigger){
	var target=dpPanelLazyRefreshTargetFromTrigger(trigger);
	if(!target||dpPanelRefreshTargetPaused(target)){return;}
	if(!dpPanelLazyTargetsFromTargets([target]).length){return;}
	var host=trigger.closest&&trigger.closest("[data-dp-panel-refresh-prefetch]");
	var delay=parseInt((host&&host.getAttribute("data-dp-panel-refresh-prefetch-delay"))||"140",10)||0;
	clearTimeout(dpPanelLazyPrefetchTimer);
	dpPanelLazyPrefetchTimer=setTimeout(function(){
		dpPanelSetRefreshTargetStatus(target,"Warming...","syncing");
		dpPanelLoadLazyRefreshRegions([target]);
	},Math.max(0,delay));
}
/**
 * Schedules interval-based targeted refreshes for named regions.
 *
 * Region timers respect per-target pause state and the same live-blocking rules
 * as full-panel live refreshes, then batch all due targets into one targeted
 * ajax request.
 *
 * @returns {void}
 */
function dpPanelScheduleRegionRefreshes(){
	clearTimeout(dpPanelRegionRefreshTimer);
	var main=document.querySelector("main.dp-panel");
	if(!main||!dpPanelAjaxEnabled()){return;}
	var nodes=Array.from(main.querySelectorAll("[data-dp-panel-refresh-interval]"));
	if(!nodes.length){return;}
	var now=Date.now();
	var soonest=0;
	nodes.forEach(function(node){
		var interval=parseInt(node.getAttribute("data-dp-panel-refresh-interval")||"0",10)||0;
		if(interval<1000){return;}
		var target=dpPanelRefreshTargetForNode(node);
		if(target&&dpPanelRefreshTargetPaused(target)){return;}
		var due=parseInt(node.getAttribute("data-dp-panel-refresh-due")||"0",10)||0;
		if(!due||due<now-interval){due=now+interval;}
		node.setAttribute("data-dp-panel-refresh-due",String(due));
		soonest=soonest ? Math.min(soonest,due) : due;
	});
	if(!soonest){return;}
	dpPanelRegionRefreshTimer=setTimeout(function(){
		if(dpPanelAjaxRequest||dpPanelAjaxLiveBlocked()){
			var retry=Date.now()+1200;
			document.querySelectorAll("main.dp-panel [data-dp-panel-refresh-interval]").forEach(function(node){
				var due=parseInt(node.getAttribute("data-dp-panel-refresh-due")||"0",10)||0;
				if(!due||due<retry){node.setAttribute("data-dp-panel-refresh-due",String(retry));}
			});
			dpPanelScheduleRegionRefreshes();
			return;
		}
		var tick=Date.now();
		var targets=[];
		Array.from(document.querySelectorAll("main.dp-panel [data-dp-panel-refresh-interval]")).forEach(function(node){
			var interval=parseInt(node.getAttribute("data-dp-panel-refresh-interval")||"0",10)||0;
			if(interval<1000){return;}
			var target=dpPanelRefreshTargetForNode(node);
			if(target&&dpPanelRefreshTargetPaused(target)){return;}
			var due=parseInt(node.getAttribute("data-dp-panel-refresh-due")||"0",10)||0;
			if(due>tick){return;}
			node.setAttribute("data-dp-panel-refresh-due",String(tick+interval));
			if(target&&targets.indexOf(target)===-1){targets.push(target);}
		});
		if(!targets.length){
			dpPanelScheduleRegionRefreshes();
			return;
		}
		dpPanelAjaxLoad(location.href,{
			replace:true,
			history:false,
			preserveScroll:true,
			quiet:true,
			silent:true,
			targetedRefresh:true,
			refreshTargets:targets,
			suppressEffects:true
		});
	},Math.max(250,soonest-now));
}
/**
 * Schedules the whole-panel live refresh loop.
 *
 * @returns {void}
 */
function dpPanelAjaxScheduleLiveRefresh(){
	clearTimeout(dpPanelAjaxLiveRefreshTimer);
	var main=document.querySelector("main.dp-panel[data-dp-panel-live-interval]");
	if(!main||!dpPanelAjaxEnabled()){return;}
	var interval=parseInt(main.dataset.dpPanelLiveInterval||"0",10)||0;
	if(interval<1000){return;}
	var delay=dpPanelLiveRetryDelay(interval);
	if(dpPanelAjaxLiveFailures>0){
		dpPanelSetLiveMessage(dpPanelLiveRetryLabel(delay),"error");
	}
	dpPanelAjaxLiveRefreshTimer=setTimeout(function(){
		if(dpPanelAjaxLiveBlocked()){
			dpPanelAjaxScheduleLiveRefresh();
			return;
	}
	dpPanelAjaxLoad(location.href,{replace:true,history:false,preserveScroll:true,silent:true,live:true});
	},delay);
}
/** Submits a modal form through the owned, fragment-aware request pipeline. */
function dpPanelAjaxSubmitModalForm(form,event){
	var root=form&&form.closest ? form.closest(".dp-panel-modal-root") : null;
	if(!root||typeof dpPanelHandleModalPayload!=="function"||typeof dpPanelFragmentPayload!=="function"||typeof dpPanelBeginModalRequest!=="function"||typeof dpPanelModalRequestIsCurrent!=="function"||typeof dpPanelFinishModalRequest!=="function"){return false;}
	var submitter=event.submitter||null;
	var method=((submitter&&submitter.formMethod)||form.method||"post").toUpperCase();
	if(["GET","POST"].indexOf(method)===-1){return false;}
	if((submitter&&submitter.formTarget)||form.target||form.dataset.dpPanelNoAjax==="1"){return false;}
	var action=(submitter&&submitter.getAttribute("formaction"))||form.getAttribute("action")||location.href;
	if(!dpPanelAjaxAllowedUrl(action)){return false;}
	event.preventDefault();
	if(root.classList.contains("dp-panel-modal-busy")){
		dpPanelToast(dpPanelText("client.already_working","Already working on that request"),"warning");
		return true;
	}
	if(form.dataset.dpPanelSubmitting==="1"){
		dpPanelToast(dpPanelText("client.already_working","Already working on that request"),"warning");
		return true;
	}
	var currentTrigger=typeof dpPanelModalState!=="undefined"&&dpPanelModalState ? dpPanelModalState.currentTrigger : null;
	var trigger=(currentTrigger&&document.contains(currentTrigger))
		? currentTrigger
		: (document.querySelector("[data-dp-panel-action-modal='1']")||root.querySelector(".dp-panel-modal-close"));
	var requestUrl=dpPanelAjaxFragmentUrl(action);
	var body=new FormData(form);
	var signedIntent=form.querySelector("[data-dp-panel-navigation-intent='1']");
	var signedReturn=body.get("return_to");
	if(!signedIntent||!signedIntent.name||!signedIntent.value||typeof signedReturn!=="string"||!signedReturn){
		body.delete("return_to");
		if(typeof dpPanelModalReturnUrl==="function"){body.append("return_to",dpPanelModalReturnUrl(trigger,action));}
	}
	if(submitter&&submitter.name){body.append(submitter.name,submitter.value||"");}
	if(method==="GET"){
		body.forEach(function(value,key){
			if(typeof value==="string"){requestUrl.searchParams.append(key,value);}
		});
		body=null;
	}
	else{
		body.append("__panel_partial","fragment");
	}
	dpPanelSetSubmitLoading(form,submitter||form.querySelector("button[type='submit'],input[type='submit']"));
	root.classList.add("dp-panel-modal-busy");
	if(typeof dpPanelModalStatus==="function"){dpPanelModalStatus(dpPanelText("modal.saving_changes","Saving changes..."),"working");}
	var request=dpPanelBeginModalRequest({method:method,kind:"ajax-form"});
	fetch(requestUrl,{
		method:method,
		body:method==="GET"?null:body,
		signal:request.signal,
		credentials:"same-origin",
		headers:{
			"Accept":"application/json",
			"X-Requested-With":"DataphyrePanelModal"
		}
	}).then(function(response){
		if(!dpPanelModalRequestIsCurrent(request)){return null;}
		return dpPanelFragmentPayload(response);
	}).then(function(payload){
		if(!dpPanelModalRequestIsCurrent(request)){return;}
		if(!dpPanelHandleModalPayload(trigger,payload,action,request)&&typeof dpPanelModalStatus==="function"){
			dpPanelModalStatus(dpPanelText("modal.unexpected_response_retry","The action returned an unexpected response. Please retry or open the full page."),"error");
		}
	}).catch(function(error){
		if(!dpPanelModalRequestIsCurrent(request)||(error&&error.name==="AbortError")){return;}
		if(typeof dpPanelModalStatus==="function"){dpPanelModalStatus(dpPanelText("modal.submit_failed_retry","The action could not complete in the dialog. Please retry or open the full page."),"error");}
	}).finally(function(){
		dpPanelReleaseSubmitLoading(form);
		if(dpPanelModalRequestIsCurrent(request)){
			root.classList.remove("dp-panel-modal-busy");
			if(!root.hidden&&root.dataset.dpPanelModalStatus==="working"&&typeof dpPanelClearModalStatus==="function"){dpPanelClearModalStatus();}
		}
		dpPanelFinishModalRequest(request);
	});
	return true;
}
/**
 * Submits an eligible Panel form through ajax.
 *
 * File uploads, explicit targets, non-GET/POST methods, and opt-out forms fall
 * back to native submission. GET forms become ajax navigation; POST forms send a
 * fragment payload and release loading state through the ajax loader.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @param {SubmitEvent} event Submit event.
 * @returns {boolean} Whether the form was handled.
 */
function dpPanelAjaxSubmitForm(form,event){
	if(!dpPanelAjaxEnabled()||!dpPanelFormBelongsToPanel(form)){return false;}
	if(form.closest&&form.closest(".dp-panel-modal-root")&&dpPanelAjaxSubmitModalForm(form,event)){return true;}
	var submitter=event.submitter||null;
	var method=((submitter&&submitter.formMethod)||form.method||"get").toLowerCase();
	if(["get","post"].indexOf(method)===-1){return false;}
	if((submitter&&submitter.formTarget)||form.target||form.dataset.dpPanelNoAjax==="1"){return false;}
	if((submitter&&submitter.dataset&&submitter.dataset.dpPanelNoAjax==="1")){return false;}
	var enctype=((submitter&&submitter.formEnctype)||form.enctype||"").toLowerCase();
	if(enctype==="multipart/form-data"){return false;}
	if(form.querySelector("input[type='file']")){return false;}
	var action=(submitter&&submitter.getAttribute("formaction"))||form.getAttribute("action")||location.href;
	if(!dpPanelAjaxAllowedUrl(action)){return false;}
	event.preventDefault();
	if(form.dataset.dpPanelSubmitting==="1"){
		dpPanelToast(dpPanelText("client.already_working","Already working on that request"),"warning");
		return true;
	}
	var url=new URL(action,location.href);
	var data=new FormData(form);
	if(submitter&&submitter.name){
		data.append(submitter.name,submitter.value||"");
	}
	if(method==="get"){
		url.search="";
		data.forEach(function(value,key){
			if(typeof value==="string"){url.searchParams.append(key,value);}
		});
		dpPanelAjaxLoad(url.toString(),{
			preserveFocus:true,
			preserveScroll:true,
			replace:true,
			quiet:true
		});
		return true;
	}
	data.append("__panel_partial","fragment");
	dpPanelSetSubmitLoading(form,submitter||form.querySelector("button[type='submit'],input[type='submit']"));
	dpPanelAjaxLoad(url.toString(),{
		method:"POST",
		body:data,
		replace:true,
		preserveScroll:true,
		form:form
	});
	return true;
}
/**
 * Debounces ajax GET form submission.
 *
 * @param {HTMLFormElement} form GET form.
 * @param {number} delay Debounce delay in milliseconds.
 * @returns {void}
 */
function dpPanelAjaxScheduleForm(form,delay){
	if(!form||!dpPanelAjaxEnabled()){return;}
	if((form.method||"get").toLowerCase()!=="get"||form.dataset.dpPanelNoAjax==="1"){return;}
	clearTimeout(dpPanelAjaxLiveTimer);
	dpPanelAjaxLiveTimer=setTimeout(function(){
		if(form.requestSubmit){form.requestSubmit();}
		else {
			var event=new Event("submit",{cancelable:true,bubbles:true});
			if(form.dispatchEvent(event)){form.submit();}
		}
	},delay);
}
dpPanelListen(document,"dataphyre:panel-action-refresh",function(event){
	var detail=event.detail||{};
	var payload=detail.payload||{};
	if(payload.redirect_to||payload.html){return;}
	var targets=Array.isArray(detail.targets) ? detail.targets : [];
	if(!targets.length||!dpPanelAjaxEnabled()){return;}
	dpPanelAjaxLoad(location.href,{
		replace:true,
		history:false,
		preserveScroll:true,
		quiet:true,
		targetedRefresh:!dpPanelRefreshTargetIncludesPanel(targets),
		refreshTargets:targets,
		suppressEffects:true
	});
});
var dpPanelUnsavedNavigation=null;
var dpPanelUnsavedLastFocus=null;
/**
 * Checks whether Panel or modal forms have unsaved changes.
 *
 * @returns {boolean} Whether unsaved changes are present.
 */
function dpPanelHasUnsavedChanges(){
	return document.body.classList.contains("dp-panel-has-unsaved-changes")||(typeof dpPanelModalHasDirtyForm==="function"&&dpPanelModalHasDirtyForm());
}
/**
 * Returns the singleton unsaved-changes confirmation dialog root.
 *
 * @returns {HTMLDivElement} Unsaved changes dialog root.
 */
function dpPanelUnsavedRoot(){
	var root=document.querySelector(".dp-panel-unsaved-root");
	if(root){return root;}
	root=document.createElement("div");
	root.className="dp-panel-unsaved-root";
	root.hidden=true;
	root.innerHTML="<section class=\"dp-panel-unsaved-dialog\" role=\"dialog\" aria-modal=\"true\" aria-labelledby=\"dp-panel-unsaved-title\" aria-describedby=\"dp-panel-unsaved-description\"><div class=\"dp-panel-unsaved-icon\" aria-hidden=\"true\">!</div><div class=\"dp-panel-unsaved-copy\"><h2 id=\"dp-panel-unsaved-title\">"+dpPanelEscape(dpPanelText("client.leave_unsaved_title","Leave with unsaved changes?"))+"</h2><p id=\"dp-panel-unsaved-description\">"+dpPanelEscape(dpPanelText("client.leave_unsaved_description","Your current changes have not been saved. Stay here to keep editing, or leave and discard them."))+"</p></div><div class=\"dp-panel-unsaved-actions\"><button class=\"dp-panel-button dp-panel-button-secondary\" type=\"button\" data-dp-panel-unsaved-stay>"+dpPanelEscape(dpPanelText("client.stay_here","Stay here"))+"</button><button class=\"dp-panel-button dp-panel-action dp-panel-action-danger\" type=\"button\" data-dp-panel-unsaved-leave>"+dpPanelEscape(dpPanelText("client.leave_anyway","Leave anyway"))+"</button></div></section>";
	document.body.appendChild(root);
	root.addEventListener("click",function(event){
		if(event.target===root||event.target.closest("[data-dp-panel-unsaved-stay]")){
			dpPanelCloseUnsavedDialog();
			return;
		}
		if(event.target.closest("[data-dp-panel-unsaved-leave]")){
			var next=dpPanelUnsavedNavigation;
			dpPanelCloseUnsavedDialog();
			document.body.classList.remove("dp-panel-has-unsaved-changes");
			document.querySelectorAll(".dp-panel form").forEach(function(form){
				form.dataset.dpPanelSubmitted="1";
			});
			if(typeof dpPanelCloseModal==="function"){dpPanelCloseModal();}
			if(typeof next==="function"){next();}
		}
	});
	return root;
}
/**
 * Closes the unsaved-changes confirmation dialog.
 *
 * @returns {void}
 */
function dpPanelCloseUnsavedDialog(){
	var root=document.querySelector(".dp-panel-unsaved-root");
	if(!root){return;}
	root.hidden=true;
	document.body.classList.remove("dp-panel-unsaved-open");
	dpPanelUnsavedNavigation=null;
	var focus=dpPanelUnsavedLastFocus&&document.contains(dpPanelUnsavedLastFocus)?dpPanelUnsavedLastFocus:null;
	if(focus&&!dpPanelCommandElementVisible(focus)){
		var rowMore=focus.closest(".dp-panel-row-more");
		focus=rowMore?rowMore.querySelector(":scope>summary"):null;
	}
	if(focus){try{focus.focus({preventScroll:true});}catch(error){focus.focus();}}
	dpPanelUnsavedLastFocus=null;
}
/**
 * Runs a navigation callback immediately or after unsaved-change confirmation.
 *
 * @param {Function} callback Navigation callback to run when confirmed.
 * @returns {boolean} Whether navigation ran immediately.
 */
function dpPanelConfirmUnsavedNavigation(callback){
	if(!dpPanelHasUnsavedChanges()){
		callback();
		return true;
	}
	dpPanelUnsavedNavigation=callback;
	var root=dpPanelUnsavedRoot();
	dpPanelUnsavedLastFocus=document.activeElement&&document.contains(document.activeElement)?document.activeElement:null;
	dpPanelCloseTransientPanels(null);
	dpPanelCloseCommandPalette();
	dpPanelCloseMobileNavigation(false);
	root.hidden=false;
	document.body.classList.add("dp-panel-unsaved-open");
	setTimeout(function(){
		var stay=root.querySelector("[data-dp-panel-unsaved-stay]");
		if(stay){stay.focus();}
	},0);
	return false;
}
dpPanelListen(document,"click",function(event){
	dpPanelAjaxLastActivity=Date.now();
	var transient=event.target.closest&&event.target.closest(".dp-panel-horizontal-group,.dp-panel-column-picker,.dp-panel-action-group,.dp-panel-row-more,.dp-panel-saved-view-menu");
	if(!transient){
		dpPanelCloseTransientChrome();
	}
	var copyEntry=event.target.closest&&event.target.closest("[data-dp-panel-copy-entry]");
	if(copyEntry){
		event.preventDefault();
		dpPanelCopyText(copyEntry.getAttribute("data-dp-panel-copy-entry")||"",copyEntry.getAttribute("data-dp-panel-copy-message")||dpPanelText("common.copied","Copied")).then(function(copied){
			if(!copied){return;}
			var previous=copyEntry.textContent;
			copyEntry.classList.add("dp-panel-entry-copy-copied");
			copyEntry.textContent=dpPanelText("common.copied","Copied");
			setTimeout(function(){
				copyEntry.classList.remove("dp-panel-entry-copy-copied");
				copyEntry.textContent=previous||dpPanelText("common.copy","Copy");
			},1300);
		});
		return;
	}
	var copyFocused=event.target.closest&&event.target.closest("[data-dp-panel-copy-focused-row]");
	if(copyFocused){
		event.preventDefault();
		dpPanelCopyFocusedRow(copyFocused.getAttribute("data-dp-panel-copy-focused-row")||"json");
		return;
	}
	var previewPrevious=event.target.closest&&event.target.closest("[data-dp-panel-preview-prev]");
	if(previewPrevious){
		event.preventDefault();
		dpPanelPreviewAdjacentRow(-1);
		return;
	}
	var previewNext=event.target.closest&&event.target.closest("[data-dp-panel-preview-next]");
	if(previewNext){
		event.preventDefault();
		dpPanelPreviewAdjacentRow(1);
		return;
	}
	var row=event.target.closest&&event.target.closest("[data-dp-panel-row]");
	var previewButton=event.target.closest&&event.target.closest("[data-dp-panel-preview-row]");
	if(previewButton&&previewButton.closest(".dp-panel")){
		event.preventDefault();
		event.stopImmediatePropagation();
		dpPanelPreviewTableRow(previewButton.closest("[data-dp-panel-row]"));
		return;
	}
	if(row&&row.closest(".dp-panel")&&!dpPanelRowActivationBlockedByEvent(event,row)){
		if(row.dataset.dpPanelRowUrl){
			event.preventDefault();
			event.stopImmediatePropagation();
			dpPanelFocusTableRow(row);
			dpPanelActivateTableRow(row);
			return;
		}
		dpPanelFocusTableRow(row);
	}
	var regionRefresh=event.target.closest&&event.target.closest("[data-dp-panel-refresh-now]");
	if(regionRefresh&&regionRefresh.closest(".dp-panel")){
		event.preventDefault();
		var refreshTarget=(regionRefresh.getAttribute("data-dp-panel-refresh-now")||"").toLowerCase();
		if(!refreshTarget){return;}
		dpPanelSetRefreshTargetStatus(refreshTarget,"Checking...","syncing");
		if(dpPanelLazyTargetsFromTargets([refreshTarget]).length){
			dpPanelLoadLazyRefreshRegions([refreshTarget]);
			return;
		}
		dpPanelAjaxLoad(location.href,{
			replace:true,
			history:false,
			preserveScroll:true,
			quiet:true,
			silent:true,
			targetedRefresh:true,
			refreshTargets:[refreshTarget],
			suppressEffects:true
		});
		return;
	}
	var regionToggle=event.target.closest&&event.target.closest("[data-dp-panel-refresh-toggle]");
	if(regionToggle&&regionToggle.closest(".dp-panel")){
		event.preventDefault();
		var toggleTarget=(regionToggle.getAttribute("data-dp-panel-refresh-toggle")||"").toLowerCase();
		if(!toggleTarget){return;}
		dpPanelSetRefreshTargetPaused(toggleTarget,!dpPanelRefreshTargetPaused(toggleTarget));
		return;
	}
	var liveRefresh=event.target.closest&&event.target.closest("[data-dp-panel-live-refresh]");
	if(liveRefresh&&liveRefresh.closest(".dp-panel")){
		event.preventDefault();
		dpPanelAjaxLiveFailures=0;
		dpPanelAjaxLoad(location.href,{replace:true,history:false,preserveScroll:true,live:true});
		return;
	}
	var liveToggle=event.target.closest&&event.target.closest("[data-dp-panel-live-toggle]");
	if(liveToggle&&liveToggle.closest(".dp-panel")){
		event.preventDefault();
		dpPanelSetLivePaused(!dpPanelLivePaused());
		return;
	}
	var mobileNavToggle=event.target.closest&&event.target.closest("[data-dp-panel-mobile-nav-toggle]");
	if(mobileNavToggle&&mobileNavToggle.closest(".dp-panel")){
		event.preventDefault();
		var mobilePanel=dpPanelMobileNavigationPanel(mobileNavToggle);
		dpPanelSetMobileNavigationOpen(!mobilePanel.classList.contains("dp-panel-mobile-nav-open"),mobilePanel);
		return;
	}
	var mobileNavBackdrop=event.target.closest&&event.target.closest("[data-dp-panel-mobile-nav-backdrop]");
	if(mobileNavBackdrop&&mobileNavBackdrop.closest(".dp-panel")){
		event.preventDefault();
		dpPanelSetMobileNavigationOpen(false,dpPanelMobileNavigationPanel(mobileNavBackdrop));
		return;
	}
	var openMobilePanel=document.querySelector('main.dp-panel-mobile-nav-open[data-dp-panel-mobile-navigation="drawer"]');
	if(openMobilePanel&&window.matchMedia&&window.matchMedia("(max-width: 1180px)").matches&&!event.target.closest("[data-dp-panel-sidebar]")&&!event.target.closest("[data-dp-panel-mobile-nav-toggle]")){
		dpPanelSetMobileNavigationOpen(false,openMobilePanel);
	}
	var link=event.target.closest&&event.target.closest("a[href]");
	if(!link||!link.closest(".dp-panel")){return;}
	if(event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey){return;}
	if(link.target||link.download||link.dataset.dpPanelNoAjax==="1"||link.dataset.dpPanelActionModal==="1"){return;}
	if(link.closest("[data-dp-panel-sidebar]")&&event.detail>0){
		dpPanelSuppressNextSidebarAutoScroll();
	}
	var href=link.getAttribute("href")||"";
	if(href===""||href.charAt(0)==="#"||href.indexOf("mailto:")===0||href.indexOf("tel:")===0){return;}
	if(!dpPanelAjaxAllowedUrl(href)){
		if(dpPanelHasUnsavedChanges()){
			event.preventDefault();
			dpPanelConfirmUnsavedNavigation(function(){location.href=href;});
		}
		return;
	}
	event.preventDefault();
	var tableNavigation=!!link.closest(".dp-panel-table-views,.dp-panel-table-groups,[data-dp-panel-scroll-table]");
	var sidebarNavigation=!!link.closest("[data-dp-panel-sidebar]");
	dpPanelConfirmUnsavedNavigation(function(){
		dpPanelCloseTransientChrome();
		if(sidebarNavigation){dpPanelCloseMobileNavigation();}
		if(sidebarNavigation){dpPanelSuppressNextSidebarAutoScroll();}
		dpPanelAjaxLoad(href, tableNavigation ? {scrollTarget:"table"} : (sidebarNavigation ? {preserveScroll:true,navigation:true} : {}));
	});
});
dpPanelListen(document,"keydown",function(event){
	var mobilePanel=document.querySelector('main.dp-panel-mobile-nav-open[data-dp-panel-mobile-navigation="drawer"]');
	if(mobilePanel&&event.key==="Tab"){
		var sidebar=mobilePanel.querySelector("[data-dp-panel-sidebar]");
		if(sidebar&&dpPanelTrapFocus(sidebar,event)){return;}
	}
	if(mobilePanel&&event.key==="Escape"){
		event.preventDefault();
		dpPanelSetMobileNavigationOpen(false,mobilePanel);
	}
});
dpPanelListen(document,"pointerenter",function(event){
	var trigger=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-refresh-prefetch],[data-dp-panel-refresh-now]") : null;
	if(trigger&&trigger.closest(".dp-panel")){dpPanelPrefetchLazyRefresh(trigger);}
},true);
dpPanelListen(document,"focusin",function(event){
	var trigger=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-refresh-prefetch],[data-dp-panel-refresh-now]") : null;
	if(trigger&&trigger.closest(".dp-panel")){dpPanelPrefetchLazyRefresh(trigger);}
});
dpPanelListen(document,"touchstart",function(event){
	var trigger=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-refresh-prefetch],[data-dp-panel-refresh-now]") : null;
	if(trigger&&trigger.closest(".dp-panel")){dpPanelPrefetchLazyRefresh(trigger);}
},{passive:true});
dpPanelListen(document,"dblclick",function(event){
	var row=event.target.closest&&event.target.closest("[data-dp-panel-row]");
	if(!row||!row.closest(".dp-panel")||dpPanelRowActivationBlockedByEvent(event,row)){return;}
	event.preventDefault();
	dpPanelFocusTableRow(row);
	dpPanelActivateTableRow(row);
});
dpPanelListen(document,"input",function(event){
	dpPanelAjaxLastActivity=Date.now();
	var target=event.target;
	if(!target||!target.closest){return;}
	if(target.closest("[data-dp-panel-sidebar-search]")){
		dpPanelRefreshSidebarSearch();
		return;
	}
	var form=target.closest(".dp-panel-search");
	if(form&&target.matches("input[type='search'],input[name='q']")){
		dpPanelAjaxScheduleForm(form,320);
	}
});
dpPanelListen(document,"change",function(event){
	dpPanelAjaxLastActivity=Date.now();
	var target=event.target;
	if(!target||!target.closest){return;}
	var form=target.closest(".dp-panel-filters,.dp-panel-per-page");
	if(form){
		dpPanelAjaxScheduleForm(form,80);
	}
});
dpPanelListen(window,"popstate",function(){
	if(typeof dpPanelHandleModalPopstate==="function"&&dpPanelHandleModalPopstate()){return;}
	var href=location.href;
	if(dpPanelHasUnsavedChanges()){
		var current=document.querySelector("main.dp-panel");
		var currentUrl=current&&current.dataset.dpPanelCurrentUrl ? current.dataset.dpPanelCurrentUrl : null;
		if(currentUrl){history.pushState({dataphyrePanel:true},document.title,currentUrl);}
		dpPanelConfirmUnsavedNavigation(function(){
			history.pushState({dataphyrePanel:true},document.title,href);
			dpPanelAjaxLoad(href,{replace:true});
		});
		return;
	}
	dpPanelAjaxLoad(href,{replace:true});
});
dpPanelListen(document,"visibilitychange",function(){
	if(!document.hidden){
		dpPanelAjaxScheduleLiveRefresh();
		dpPanelScheduleRegionRefreshes();
		dpPanelScheduleLazyRefreshCheck(40);
	}
});
dpPanelListen(window,"online",function(){
	dpPanelAjaxLiveFailures=0;
	dpPanelSetLiveMessage(dpPanelText("client.back_online","Back online"),"success");
	dpPanelAjaxScheduleLiveRefresh();
	dpPanelScheduleRegionRefreshes();
	dpPanelScheduleLazyRefreshCheck(40);
});
dpPanelListen(window,"offline",function(){
	dpPanelSetLiveMessage(dpPanelText("client.offline","Offline"),"error");
});
dpPanelListen(window,"focus",function(){
	dpPanelAjaxScheduleLiveRefresh();
	dpPanelScheduleRegionRefreshes();
	dpPanelScheduleLazyRefreshCheck(40);
});
dpPanelListen(window,"scroll",function(){dpPanelScheduleLazyRefreshCheck(140);},{passive:true});
dpPanelListen(window,"resize",function(){dpPanelScheduleLazyRefreshCheck(140);});
dpPanelListen(document,"input",dpPanelRefreshPanelUi);
dpPanelListen(document,"change",dpPanelRefreshPanelUi);
dpPanelListen(document,"submit",function(event){
	dpPanelAjaxLastActivity=Date.now();
	if(event.defaultPrevented){return;}
	var form=event.target;
	if(!dpPanelFormBelongsToPanel(form)){return;}
	var skipValidation=event.submitter&&event.submitter.formNoValidate===true;
	if(!skipValidation&&typeof form.checkValidity==="function"&&!dpPanelValidateForm(form)){
		event.preventDefault();
		return false;
	}
	if(dpPanelAjaxSubmitForm(form,event)){
		return false;
	}
	if(form.dataset.dpPanelSubmitting==="1"){
		event.preventDefault();
		dpPanelToast(dpPanelText("client.already_working","Already working on that request"),"warning");
		return false;
	}
	dpPanelSetSubmitLoading(form,event.submitter||form.querySelector("button[type='submit'],input[type='submit']"));
});
dpPanelListen(document,"invalid",function(event){
	if(event.target&&event.target.closest&&event.target.closest(".dp-panel")){
		dpPanelMarkInvalidControl(event.target);
	}
},true);
dpPanelListen(window,"beforeunload",function(event){
	if(!document.body.classList.contains("dp-panel-has-unsaved-changes")){return;}
	event.preventDefault();
	event.returnValue=dpPanelText("client.leave_unsaved_title","Leave with unsaved changes?");
});
dpPanelListen(window,"resize",dpPanelRefreshTableScroll);
/**
 * Normalizes a configured keyboard binding into canonical modifier order.
 *
 * @param {string} value Raw key binding such as `cmd+k` or `Ctrl+Shift+P`.
 * @returns {string} Canonical binding string.
 */
function dpPanelNormalizeKeyBinding(value){
	var aliases={cmd:"mod",command:"mod",option:"alt",control:"ctrl",ctl:"ctrl",esc:"escape",return:"enter",del:"delete"};
	var parts=String(value||"").toLowerCase().replace(/\s+/g,"+").split("+").filter(Boolean).map(function(part){return aliases[part]||part;});
	var mods={};
	var key="";
	parts.forEach(function(part){
		if(part==="mod"||part==="ctrl"||part==="meta"||part==="alt"||part==="shift"){mods[part]=true;return;}
		key=part===" "?"space":part;
	});
	if(!key){return "";}
	var ordered=[];
	["mod","ctrl","meta","alt","shift"].forEach(function(mod){if(mods[mod]){ordered.push(mod);}});
	ordered.push(key);
	return ordered.join("+");
}
/**
 * Converts a keyboard event into possible canonical binding strings.
 *
 * @param {KeyboardEvent} event Keyboard event.
 * @returns {string[]} Candidate binding strings.
 */
function dpPanelKeyEventBindings(event){
	var key=String(event.key||"").toLowerCase();
	var aliases={" ":"space",escape:"escape",esc:"escape",arrowup:"arrowup",arrowdown:"arrowdown",arrowleft:"arrowleft",arrowright:"arrowright"};
	key=aliases[key]||key;
	if(!key||key==="control"||key==="shift"||key==="alt"||key==="meta"){return [];}
	var base=[];
	if(event.altKey){base.push("alt");}
	if(event.shiftKey){base.push("shift");}
	var variants=[];
	if(event.ctrlKey||event.metaKey){variants.push(["mod"].concat(base,key).join("+"));}
	if(event.ctrlKey){variants.push(["ctrl"].concat(base,key).join("+"));}
	if(event.metaKey){variants.push(["meta"].concat(base,key).join("+"));}
	if(!event.ctrlKey&&!event.metaKey){variants.push(base.concat(key).join("+"));}
	return variants.map(dpPanelNormalizeKeyBinding).filter(Boolean);
}
/**
 * Parses configured key bindings from a control dataset.
 *
 * @param {Element} control Control carrying `data-dp-panel-key-bindings`.
 * @returns {string[]} Canonical key bindings.
 */
function dpPanelControlKeyBindings(control){
	try{
		var parsed=JSON.parse(control.dataset.dpPanelKeyBindings||"[]");
		if(!Array.isArray(parsed)){return [];}
		return parsed.map(dpPanelNormalizeKeyBinding).filter(Boolean);
	}
	catch(error){
		return [];
	}
}
/** Formats a canonical key binding for display. */
function dpPanelDisplayKeyBinding(binding){
	return String(binding||"").split("+").filter(Boolean).map(function(part){
		if(part==="mod"){return "Ctrl/Cmd";}
		if(part==="ctrl"){return "Ctrl";}
		if(part==="meta"){return "Cmd";}
		if(part==="alt"){return "Alt";}
		if(part==="shift"){return "Shift";}
		if(part==="escape"){return "Esc";}
		if(part==="enter"){return "Enter";}
		if(part==="space"){return "Space";}
		return part.length===1 ? part.toUpperCase() : part.charAt(0).toUpperCase()+part.slice(1);
	}).join("+");
}
/** Executes the first visible control matching a contextual key binding. */
function dpPanelHandleActionKeyBinding(event){
	var eventBindings=dpPanelKeyEventBindings(event);
	if(!eventBindings.length){return false;}
	var root=document;
	var modal=document.querySelector(".dp-panel-modal-root:not([hidden])");
	if(modal){root=modal;}
	var controls=Array.from(root.querySelectorAll("[data-dp-panel-key-bindings]")).filter(function(control){
		if(control.disabled||control.getAttribute("aria-disabled")==="true"){return false;}
		if(control.closest("[hidden],.dp-panel-bulk-bar-empty")){return false;}
		return !!(control.offsetWidth||control.offsetHeight||control.getClientRects().length);
	});
	var activeRow=document.activeElement&&document.activeElement.closest ? document.activeElement.closest("[data-dp-panel-row]") : null;
	if(activeRow){
		var rowControls=controls.filter(function(control){return activeRow.contains(control);});
		if(rowControls.length){controls=rowControls;}
	}
	for(var index=0;index<controls.length;index++){
		var bindings=dpPanelControlKeyBindings(controls[index]);
		for(var item=0;item<eventBindings.length;item++){
			if(bindings.indexOf(eventBindings[item])!==-1){
				event.preventDefault();
				dpPanelRunCommandControl(controls[index]);
				return true;
			}
		}
	}
	return false;
}
dpPanelListen(document,"keydown",function(event){
	var unsavedRoot=document.querySelector(".dp-panel-unsaved-root");
	if(unsavedRoot&&!unsavedRoot.hidden&&dpPanelTrapFocus(unsavedRoot,event)){return;}
	if(unsavedRoot&&!unsavedRoot.hidden&&event.key==="Escape"){
		event.preventDefault();
		dpPanelCloseUnsavedDialog();
		return;
	}
	var commandRoot=document.querySelector(".dp-panel-command-root");
	if(commandRoot&&!commandRoot.hidden&&event.key==="Escape"){
		event.preventDefault();
		event.stopImmediatePropagation();
		dpPanelCloseCommandPalette();
		return;
	}
	if(commandRoot&&!commandRoot.hidden&&dpPanelTrapFocus(commandRoot,event)){return;}
	var modalRoot=document.querySelector(".dp-panel-modal-root");
	if(modalRoot&&!modalRoot.hidden&&modalRoot.querySelector(".dp-panel-row-preview")){
		if(event.key==="ArrowLeft"){
			event.preventDefault();
			dpPanelPreviewAdjacentRow(-1);
			return;
		}
		if(event.key==="ArrowRight"){
			event.preventDefault();
			dpPanelPreviewAdjacentRow(1);
			return;
		}
	}
	if(event.key==="Escape"&&!event.defaultPrevented){
		dpPanelCloseCommandPalette();
		dpPanelCloseTransientPanels(null);
	}
	if(modalRoot&&!modalRoot.hidden){
		if(!dpPanelIsTypingTarget(event.target)&&dpPanelHandleActionKeyBinding(event)){return;}
		if((event.ctrlKey||event.metaKey)&&String(event.key).toLowerCase()==="k"){
			event.preventDefault();
			dpPanelOpenCommandPalette();
		}
		return;
	}
	if(dpPanelHandleSidebarKeyboard(event)){
		return;
	}
	var activeRow=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-row]") : null;
	if(activeRow&&dpPanelRowActivationBlockedByEvent(event,activeRow)){
		activeRow=null;
	}
	if(activeRow){
		if(event.key==="ArrowDown"){
			event.preventDefault();
			dpPanelMoveTableRow(activeRow,1);
			return;
		}
		if(event.key==="ArrowUp"){
			event.preventDefault();
			dpPanelMoveTableRow(activeRow,-1);
			return;
		}
		if(event.key==="Home"){
			var first=dpPanelTableRows(activeRow.closest("tbody"))[0];
			if(first){event.preventDefault();dpPanelFocusTableRow(first);}
			return;
		}
		if(event.key==="End"){
			var rows=dpPanelTableRows(activeRow.closest("tbody"));
			var last=rows[rows.length-1];
			if(last){event.preventDefault();dpPanelFocusTableRow(last);}
			return;
		}
		if(event.key==="Enter"){
			event.preventDefault();
			dpPanelActivateTableRow(activeRow);
			return;
		}
		if(String(event.key).toLowerCase()==="p"){
			event.preventDefault();
			dpPanelPreviewTableRow(activeRow);
			return;
		}
		if(String(event.key).toLowerCase()==="m"){
			event.preventDefault();
			dpPanelOpenFocusedRowActions(activeRow);
			return;
		}
		if(event.key===" "){
			if(dpPanelToggleTableRowSelection(activeRow)){event.preventDefault();}
			return;
		}
	}
	if(dpPanelIsTypingTarget(event.target)){return;}
	if(dpPanelHandleActionKeyBinding(event)){return;}
	if((event.ctrlKey||event.metaKey)&&String(event.key).toLowerCase()==="k"){
		event.preventDefault();
		dpPanelOpenCommandPalette();
		return;
	}
	if(event.key==="/"){
		if(dpPanelFocusSearch(false)){event.preventDefault();}
		return;
	}
	if(String(event.key).toLowerCase()==="f"){
		if(document.querySelector(".dp-panel-filter-panel")){
			event.preventDefault();
			dpPanelToggleFilters();
		}
		return;
	}
	if(String(event.key).toLowerCase()==="c"){
		if(document.querySelector(".dp-panel-column-picker")){
			event.preventDefault();
			dpPanelOpenColumnPicker();
		}
		return;
	}
	if(String(event.key).toLowerCase()==="n"){
		if(dpPanelFocusSidebarSearch()){
			event.preventDefault();
		}
		return;
	}
	if(String(event.key).toLowerCase()==="a"){
		if(document.querySelector("input[name='selected[]']")){
			event.preventDefault();
			dpPanelSelectVisibleRows();
		}
		return;
	}
	if(String(event.key).toLowerCase()==="x"){
		if(document.querySelector("input[name='selected[]']")){
			event.preventDefault();
			dpPanelInvertVisibleRows();
		}
		return;
	}
	if(event.key==="?"){
		event.preventDefault();
		dpPanelOpenShortcuts();
	}
});
/**
 * Initializes ARIA tablists, roving tabindex, and layout settling behavior.
 *
 * @returns {void}
 */
function dpPanelInitTabs(){
	document.querySelectorAll("[data-dp-panel-tabs]").forEach(function(tabs){
		/**
		 * Returns tab buttons owned by the current tab widget.
		 *
		 * @returns {HTMLElement[]} Tab buttons.
		 */
		function tabButtons(){
			return Array.from(tabs.querySelectorAll("[role='tab']"));
		}
		/**
		 * Returns tab panels owned by the current tab widget.
		 *
		 * @returns {HTMLElement[]} Tab panels.
		 */
		function tabPanels(){
			return Array.from(tabs.querySelectorAll("[role='tabpanel']"));
		}
		/**
		 * Resolves the panel controlled by a tab button.
		 *
		 * @param {HTMLElement|null} button Tab button.
		 * @returns {HTMLElement|null} Controlled panel.
		 */
		function activePanelFor(button){
			if(!button){return null;}
			var id=button.getAttribute("aria-controls")||"";
			return id ? tabs.querySelector("#"+dpPanelEscapeSelectorValue(id)) : null;
		}
		/**
		 * Restores viewport position after a requested tab activation.
		 *
		 * @param {number|null} stripTop Previous tab strip top position.
		 * @param {number|null} panelTop Previous active panel top position.
		 * @param {boolean} requested Whether activation was user-requested.
		 * @returns {void}
		 */
		function restoreTabViewport(stripTop,panelTop,requested){
			if(requested!==true||stripTop===null){return;}
			requestAnimationFrame(function(){
				var list=tabs.querySelector(".dp-panel-tab-list,[role='tablist']");
				if(!list){return;}
				var nextTop=list.getBoundingClientRect().top;
				var delta=nextTop-stripTop;
				if(Math.abs(delta)>1){
					window.scrollBy(0,delta);
				}
				var panel=activePanelFor(tabButtons().find(function(item){return item.getAttribute("aria-selected")==="true";}));
				if(!panel){return;}
				var panelRect=panel.getBoundingClientRect();
				var viewportHeight=window.innerHeight||document.documentElement.clientHeight||0;
				var lowerSafe=Math.max(220,viewportHeight*.72);
				if(panelTop!==null&&panelRect.top>lowerSafe){
					var target=window.scrollY+panelRect.top-Math.max(84,viewportHeight*.18);
					window.scrollTo({top:Math.max(0,target),behavior:"smooth"});
				}
			});
		}
		/**
		 * Activates a tab, updates ARIA state, and refreshes accessibility layout.
		 *
		 * @param {HTMLElement|null} button Tab button to activate.
		 * @param {boolean} requested Whether activation came from user interaction.
		 * @returns {void}
		 */
		function activate(button,requested){
			if(!button){return;}
			var buttons=tabButtons();
			var panels=tabPanels();
			if(buttons.indexOf(button)===-1){return;}
			if(requested===true){dpPanelBeginA11yLayoutTransition(tabs);}
			var list=tabs.querySelector(".dp-panel-tab-list,[role='tablist']");
			var stripTop=requested===true&&list ? list.getBoundingClientRect().top : null;
			var currentPanel=buttons.find(function(item){return item.getAttribute("aria-selected")==="true";});
			currentPanel=activePanelFor(currentPanel);
			var panelTop=requested===true&&currentPanel ? currentPanel.getBoundingClientRect().top : null;
			buttons.forEach(function(item){item.setAttribute("tabindex", item===button ? "0" : "-1");});
			buttons.forEach(function(item){item.setAttribute("aria-selected", item===button ? "true" : "false");});
			panels.forEach(function(panel){panel.hidden=panel.id!==button.getAttribute("aria-controls");});
			if(requested===true){dpPanelScheduleA11yLayoutAfterSelector(tabs);}
			restoreTabViewport(stripTop,panelTop,requested);
		}
		tabButtons().forEach(function(button){
			if(!button.hasAttribute("tabindex")){button.setAttribute("tabindex",button.getAttribute("aria-selected")==="true"?"0":"-1");}
		});
		if(tabs.dataset.dpPanelTabsReady!=="1"){
			tabs.dataset.dpPanelTabsReady="1";
			tabs.addEventListener("click",function(event){
				var button=event.target.closest("[role='tab']");
				if(!button||!tabs.contains(button)){return;}
				event.preventDefault();
				activate(button,true);
			});
			tabs.addEventListener("keydown",function(event){
				var button=event.target.closest("[role='tab']");
				if(!button||!tabs.contains(button)){return;}
				var buttons=tabButtons();
				var current=buttons.indexOf(button);
				if(current<0){return;}
				var next=current;
				if(event.key==="ArrowRight"){next=current+1;}
				else if(event.key==="ArrowLeft"){next=current-1;}
				else if(event.key==="Home"){next=0;}
				else if(event.key==="End"){next=buttons.length-1;}
				else{return;}
				event.preventDefault();
				next=(next+buttons.length)%buttons.length;
				if(buttons[next].focus){buttons[next].focus({preventScroll:true});}
				activate(buttons[next],true);
			});
		}
		var buttons=tabButtons();
		var selected=buttons.find(function(button){return button.getAttribute("aria-selected")==="true";})||buttons[0];
		if(selected){
			activate(selected);
		}
	});
}
/**
 * Returns controls whose disabled state should follow step visibility.
 *
 * @param {Element} panel Step panel.
 * @returns {NodeListOf<Element>} Step panel controls.
 */
function dpPanelStepControls(panel){
	return panel.querySelectorAll("input,select,textarea,button:not([data-dp-panel-editor-view])");
}
/**
 * Disables controls in inactive step panels while preserving navigation buttons.
 *
 * @param {Element} steps Step widget root.
 * @returns {void}
 */
function dpPanelApplyStepControlState(steps){
	var index=parseInt(steps.dataset.dpPanelStepIndex||"0",10)||0;
	var panels=steps.querySelectorAll("[data-dp-panel-step-panel]");
	panels.forEach(function(panel,panelIndex){
		var active=panelIndex===index;
		dpPanelStepControls(panel).forEach(function(control){
			if(control.hasAttribute("data-dp-panel-step-prev")||control.hasAttribute("data-dp-panel-step-next")){return;}
			if(active&&control.dataset.dpPanelStepDisabled==="1"){
				control.disabled=false;
				delete control.dataset.dpPanelStepDisabled;
			}
			if(!active&&!control.disabled){
				control.disabled=true;
				control.dataset.dpPanelStepDisabled="1";
			}
		});
	});
}
/**
 * Finds the container that should receive layout-settling state for selectors.
 *
 * @param {Element|null} node Source element.
 * @returns {Element|null} Transition target.
 */
function dpPanelSelectorTransitionTarget(node){
	var target=node&&node.closest ? (node.closest("[data-dp-panel-tabs],[data-dp-panel-steps]")||node.closest("form.dp-panel-form")||node.closest(".dp-panel-modal-body")||node.closest(".dp-panel")) : null;
	return target;
}
/**
 * Ends accessibility layout settling state after selector content stabilizes.
 *
 * @param {Element|null} target Transition target.
 * @returns {void}
 */
function dpPanelEndA11yLayoutTransition(target){
	if(!target){return;}
	if(target._dpPanelA11yLayoutTimer){clearTimeout(target._dpPanelA11yLayoutTimer);}
	target._dpPanelA11yLayoutTimer=setTimeout(function(){
		target.classList.remove("dp-panel-a11y-layout-settling");
		target._dpPanelA11yLayoutTimer=0;
	},70);
}
/**
 * Begins accessibility layout settling state for tabs or steps.
 *
 * @param {Element|null} node Source selector widget or descendant.
 * @returns {Element|null} Transition target.
 */
function dpPanelBeginA11yLayoutTransition(node){
	var target=dpPanelSelectorTransitionTarget(node);
	if(!target){return null;}
	target.classList.add("dp-panel-a11y-layout-settling");
	if(target._dpPanelA11yLayoutTimer){clearTimeout(target._dpPanelA11yLayoutTimer);}
	target._dpPanelA11yLayoutTimer=setTimeout(function(){
		target.classList.remove("dp-panel-a11y-layout-settling");
		target._dpPanelA11yLayoutTimer=0;
	},900);
	return target;
}
/**
 * Resolves the active content root for accessibility policy refreshes.
 *
 * @param {Element|Document|null} node Source node.
 * @returns {Element|Document} Refresh root.
 */
function dpPanelA11ySelectorRefreshRoot(node){
	if(!node||!node.querySelector){return node||document;}
	if(node.matches&&node.matches("[data-dp-panel-tabs]")){
		return node.querySelector("[role='tabpanel']:not([hidden])")||node;
	}
	if(node.matches&&node.matches("[data-dp-panel-steps]")){
		return node.querySelector("[data-dp-panel-step-panel]:not([hidden])")||node;
	}
	var tabs=node.closest&&node.closest("[data-dp-panel-tabs]");
	if(tabs){return tabs.querySelector("[role='tabpanel']:not([hidden])")||tabs;}
	var steps=node.closest&&node.closest("[data-dp-panel-steps]");
	if(steps){return steps.querySelector("[data-dp-panel-step-panel]:not([hidden])")||steps;}
	return node;
}
/**
 * Schedules accessibility policy refresh after a selector transition settles.
 *
 * @param {Element|null} node Source selector widget or descendant.
 * @returns {void}
 */
function dpPanelScheduleA11yLayoutAfterSelector(node){
	var transitionTarget=dpPanelSelectorTransitionTarget(node);
	requestAnimationFrame(function(){
		var root=dpPanelA11ySelectorRefreshRoot(node);
		dpPanelApplyAccessibilityPolicies(root,{preserveAdaptive:false});
		requestAnimationFrame(function(){
			requestAnimationFrame(function(){
				dpPanelEndA11yLayoutTransition(transitionTarget);
			});
		});
	});
}
/**
 * Activates a step in a stepper widget.
 *
 * @param {Element} steps Step widget root.
 * @param {number} index Desired step index.
 * @returns {void}
 */
function dpPanelSetStep(steps,index){
	var buttons=Array.from(steps.querySelectorAll("[data-dp-panel-step-button]"));
	var panels=Array.from(steps.querySelectorAll("[data-dp-panel-step-panel]"));
	if(!panels.length){return;}
	index=Math.max(0,Math.min(index,panels.length-1));
	var previous=parseInt(steps.dataset.dpPanelStepIndex||"0",10)||0;
	var requested=previous!==index;
	if(requested){dpPanelBeginA11yLayoutTransition(steps);}
	buttons.forEach(function(button,buttonIndex){
		button.setAttribute("aria-current",buttonIndex===index ? "step" : "false");
		button.setAttribute("tabindex",buttonIndex===index ? "0" : "-1");
	});
	panels.forEach(function(panel,panelIndex){
		var active=panelIndex===index;
		panel.hidden=!active;
		dpPanelStepControls(panel).forEach(function(control){
			if(active&&control.dataset.dpPanelStepDisabled==="1"){
				control.disabled=false;
				delete control.dataset.dpPanelStepDisabled;
			}
		});
	});
	steps.dataset.dpPanelStepIndex=String(index);
	dpPanelRefreshDependencies();
	dpPanelApplyStepControlState(steps);
	if(requested){dpPanelScheduleA11yLayoutAfterSelector(steps);}
}
/**
 * Initializes stepper widgets and restores disabled controls before submit.
 *
 * @returns {void}
 */
function dpPanelInitSteps(){
	document.querySelectorAll("[data-dp-panel-steps]").forEach(function(steps){
		if(steps.dataset.dpPanelStepsReady==="1"){return;}
		steps.dataset.dpPanelStepsReady="1";
		steps.addEventListener("click",function(event){
			var stepButton=event.target.closest("[data-dp-panel-step-button]");
			if(stepButton&&steps.contains(stepButton)){
				var buttons=Array.from(steps.querySelectorAll("[data-dp-panel-step-button]"));
				var index=buttons.indexOf(stepButton);
				if(index>=0){
					event.preventDefault();
					dpPanelSetStep(steps,index);
				}
				return;
			}
			var current=parseInt(steps.dataset.dpPanelStepIndex||"0",10)||0;
			if(event.target.closest("[data-dp-panel-step-prev]")){
				event.preventDefault();
				dpPanelSetStep(steps,current-1);
			}
			if(event.target.closest("[data-dp-panel-step-next]")){
				event.preventDefault();
				var form=steps.closest("form");
				if(form&&typeof form.reportValidity==="function"&&!form.reportValidity()){return;}
				dpPanelSetStep(steps,current+1);
			}
		});
		steps.addEventListener("keydown",function(event){
			var button=event.target.closest("[data-dp-panel-step-button]");
			if(!button||!steps.contains(button)){return;}
			var buttons=Array.from(steps.querySelectorAll("[data-dp-panel-step-button]"));
			var current=buttons.indexOf(button);
			if(current<0){return;}
			var next=current;
			if(event.key==="ArrowRight"){next=current+1;}
			else if(event.key==="ArrowLeft"){next=current-1;}
			else if(event.key==="Home"){next=0;}
			else if(event.key==="End"){next=buttons.length-1;}
			else{return;}
			event.preventDefault();
			next=(next+buttons.length)%buttons.length;
			if(buttons[next].focus){buttons[next].focus({preventScroll:true});}
			dpPanelSetStep(steps,next);
		});
		dpPanelSetStep(steps,0);
	});
	document.querySelectorAll("form").forEach(function(form){
		if(form.dataset.dpPanelStepsSubmitReady==="1"){return;}
		form.dataset.dpPanelStepsSubmitReady="1";
		form.addEventListener("submit",function(){
			form.querySelectorAll("[data-dp-panel-step-disabled='1']").forEach(function(control){
				control.disabled=false;
				delete control.dataset.dpPanelStepDisabled;
			});
		});
	});
}
/**
 * Initializes repeater and builder add/remove controls.
 *
 * @returns {void}
 */
function dpPanelInitRepeaters(){
	document.querySelectorAll("[data-dp-panel-repeater]").forEach(function(repeater){
		if(repeater.dataset.dpPanelRepeaterReady==="1"){return;}
		repeater.dataset.dpPanelRepeaterReady="1";
		dpPanelRefreshRepeater(repeater);
		repeater.addEventListener("click",function(event){
			var remove=event.target.closest("[data-dp-panel-repeater-remove]");
			if(remove){
				var row=remove.closest("[data-dp-panel-repeater-row]");
				if(row){row.remove();}
				dpPanelRefreshRepeater(repeater);
				return;
			}
			var add=event.target.closest("[data-dp-panel-repeater-add]");
			if(!add){return;}
			if(add.disabled||add.getAttribute("aria-disabled")==="true"){return;}
			var block=add.getAttribute("data-dp-panel-builder-add")||"";
			var template=block ? repeater.querySelector("[data-dp-panel-repeater-template][data-dp-panel-builder-template='"+block+"']") : repeater.querySelector("[data-dp-panel-repeater-template]");
			var items=repeater.querySelector("[data-dp-panel-repeater-items]");
			if(!template||!items){return;}
			var index=parseInt(repeater.dataset.dpPanelRepeaterNext||"0",10)||0;
			repeater.dataset.dpPanelRepeaterNext=String(index+1);
			var html=template.innerHTML.replace(/__INDEX__/g,String(index));
			var wrap=document.createElement("div");
			wrap.innerHTML=html;
			var row=wrap.firstElementChild;
			if(!row){return;}
			row.querySelectorAll("input,select,textarea,button").forEach(function(control){
				control.disabled=false;
			});
			items.appendChild(row);
			dpPanelInitFieldEnhancements(row);
			dpPanelRefreshRepeater(repeater);
			dpPanelRefreshPanelUi();
			var focus=row.querySelector("input,select,textarea");
			if(focus){focus.focus();}
		});
	});
}
/**
 * Returns visible children participating in packed board layout.
 *
 * @param {Element} grid Packed grid element.
 * @returns {Element[]} Visible packed grid items.
 */
function dpPanelPackedGridItems(grid){
	return Array.from(grid.children||[]).filter(function(item){
		if(item.hidden){return false;}
		var style=window.getComputedStyle ? window.getComputedStyle(item) : null;
		return !style||style.display!=="none";
	});
}
/**
 * Clears packed grid positioning from a board grid.
 *
 * @param {Element|null} grid Packed grid element.
 * @returns {void}
 */
function dpPanelResetPackedGrid(grid){
	if(!grid){return;}
	grid.dataset.dpPanelPackedGridReady="";
	grid.style.removeProperty("display");
	grid.style.removeProperty("position");
	grid.style.removeProperty("height");
	grid.style.removeProperty("--dp-packed-grid-columns");
	grid.style.removeProperty("--dp-packed-grid-gap");
	dpPanelPackedGridItems(grid).forEach(function(item){
		item.style.removeProperty("position");
		item.style.removeProperty("left");
		item.style.removeProperty("top");
		item.style.removeProperty("width");
		item.style.removeProperty("margin");
	});
}
/**
 * Applies masonry-style packed positioning to a board grid.
 *
 * The layout is disabled while dragging and falls back to normal flow when the
 * board is not in a compatible Panel board context or only one column fits.
 *
 * @param {Element|null} grid Packed grid element.
 * @returns {void}
 */
function dpPanelApplyPackedGrid(grid){
	if(!grid||!grid.isConnected){return;}
	if(!grid.matches||!grid.matches(".dp-panel-board[data-dp-panel-packed-grid]")||!grid.closest(".dp-panel[data-dp-panel-kind='board']")){
		dpPanelResetPackedGrid(grid);
		return;
	}
	if(grid.dataset.dpPanelBoardDragging==="1"||grid.querySelector(".dp-panel-board-dragging")){
		return;
	}
	dpPanelResetPackedGrid(grid);
	var items=dpPanelPackedGridItems(grid);
	if(items.length<2){return;}
	var gridStyle=window.getComputedStyle ? window.getComputedStyle(grid) : null;
	var rect=grid.getBoundingClientRect();
	var width=Math.floor(rect.width);
	if(width<=0){return;}
	var gap=gridStyle ? parseFloat(gridStyle.columnGap||gridStyle.gap||"0") : 0;
	if(!isFinite(gap)){gap=0;}
	var minWidth=parseFloat(grid.dataset.dpPanelPackedGridMin||"0");
	if(!isFinite(minWidth)||minWidth<=0){
		var firstRect=items[0].getBoundingClientRect();
		minWidth=Math.max(1,firstRect.width||width);
	}
	var columns=Math.max(1,Math.floor((width+gap)/(minWidth+gap)));
	columns=Math.min(columns,items.length);
	if(columns<=1){
		dpPanelResetPackedGrid(grid);
		return;
	}
	var itemWidth=Math.floor((width-(gap*(columns-1)))/columns);
	if(itemWidth<1){return;}
	grid.style.setProperty("display","block","important");
	grid.style.position="relative";
	grid.style.setProperty("--dp-packed-grid-columns",String(columns));
	grid.style.setProperty("--dp-packed-grid-gap",gap+"px");
	var heights=[];
	for(var i=0;i<columns;i++){heights.push(0);}
	items.forEach(function(item){
		var column=0;
		for(var index=1;index<heights.length;index++){
			if(heights[index]<heights[column]){column=index;}
		}
		var left=Math.round(column*(itemWidth+gap));
		var top=Math.round(heights[column]);
		item.style.setProperty("position","absolute","important");
		item.style.left=left+"px";
		item.style.top=top+"px";
		item.style.width=itemWidth+"px";
		item.style.margin="0";
		var itemHeight=item.getBoundingClientRect().height;
		heights[column]=top+itemHeight+gap;
	});
	var height=Math.max.apply(Math,heights)-gap;
	grid.style.height=Math.max(0,Math.ceil(height))+"px";
	grid.dataset.dpPanelPackedGridReady="1";
}
/**
 * Schedules packed grid layout on the next animation frame.
 *
 * @param {Element|null} grid Packed grid element.
 * @returns {void}
 */
function dpPanelSchedulePackedGrid(grid){
	if(!grid||grid._dpPanelPackedGridFrame){return;}
	grid._dpPanelPackedGridFrame=requestAnimationFrame(function(){
		grid._dpPanelPackedGridFrame=null;
		dpPanelApplyPackedGrid(grid);
	});
}
/**
 * Initializes packed board grids and their resize/mutation observers.
 *
 * @param {Element|Document|null} root Optional initialization scope.
 * @returns {void}
 */
function dpPanelInitPackedGrids(root){
	var scope=root||document;
	var selector=".dp-panel[data-dp-panel-kind='board'] .dp-panel-board[data-dp-panel-packed-grid]";
	var grids=Array.from(scope.querySelectorAll ? scope.querySelectorAll(selector) : []);
	if(scope.matches&&scope.matches(".dp-panel-board[data-dp-panel-packed-grid]")&&scope.closest(".dp-panel[data-dp-panel-kind='board']")){grids.unshift(scope);}
	if(scope.matches&&scope.matches(".dp-panel[data-dp-panel-kind='board']")){
		var board=scope.querySelector(".dp-panel-board[data-dp-panel-packed-grid]");
		if(board){grids.unshift(board);}
	}
	grids=grids.filter(function(grid,index,array){return grid&&array.indexOf(grid)===index;});
	grids.forEach(function(grid){
		if(grid.dataset.dpPanelPackedGridObserver!=="1"){
			grid.dataset.dpPanelPackedGridObserver="1";
			if(window.ResizeObserver){
				var resizeObserver=new ResizeObserver(function(){dpPanelSchedulePackedGrid(grid);});
				resizeObserver.observe(grid);
				Array.from(grid.children||[]).forEach(function(item){resizeObserver.observe(item);});
				grid._dpPanelPackedGridResizeObserver=resizeObserver;
			}
			if(window.MutationObserver){
				var mutationObserver=new MutationObserver(function(){
					if(grid._dpPanelPackedGridResizeObserver){
						Array.from(grid.children||[]).forEach(function(item){grid._dpPanelPackedGridResizeObserver.observe(item);});
					}
					dpPanelSchedulePackedGrid(grid);
				});
				mutationObserver.observe(grid,{childList:true,subtree:true,attributes:true,attributeFilter:["hidden","open","class"]});
				grid._dpPanelPackedGridMutationObserver=mutationObserver;
			}
		}
		dpPanelSchedulePackedGrid(grid);
	});
}
/**
 * Refreshes repeater add/remove availability against min and max constraints.
 *
 * @param {Element} repeater Repeater root.
 * @returns {void}
 */
function dpPanelRefreshRepeater(repeater){
	var items=repeater.querySelector("[data-dp-panel-repeater-items]");
	var rows=items ? Array.from(items.querySelectorAll("[data-dp-panel-repeater-row]")) : [];
	var min=parseInt(repeater.dataset.dpPanelRepeaterMin||"0",10)||0;
	var max=parseInt(repeater.dataset.dpPanelRepeaterMax||"0",10)||0;
	var add=repeater.querySelector("[data-dp-panel-repeater-add]");
	if(add){
		var full=max>0&&rows.length>=max;
		add.disabled=full;
		add.setAttribute("aria-disabled",full ? "true" : "false");
	}
	rows.forEach(function(row){
		var remove=row.querySelector("[data-dp-panel-repeater-remove]");
		if(remove){
			var locked=rows.length<=min;
			remove.disabled=locked;
			remove.setAttribute("aria-disabled",locked ? "true" : "false");
		}
	});
}
/**
 * Reads the submitted value from an inline edit form.
 *
 * @param {HTMLFormElement} form Inline edit form.
 * @returns {string} Current inline edit value.
 */
function dpPanelInlineEditValue(form){
	var checked=form.querySelector("input[type='checkbox'][name='value']");
	if(checked){return checked.checked ? (checked.value||"1") : "0";}
	var field=form.querySelector("[name='value']:not([type='hidden'])")||form.querySelector("[name='value']");
	return field ? (field.value||"") : "";
}
/**
 * Updates inline edit status text and tone metadata.
 *
 * @param {HTMLFormElement} form Inline edit form.
 * @param {string} text Status text.
 * @param {string} tone Status tone.
 * @returns {void}
 */
function dpPanelInlineEditSetStatus(form,text,tone){
	var status=form.querySelector(".dp-panel-inline-edit-status");
	form.dataset.dpPanelInlineTone=tone||"";
	if(status){status.textContent=text||"";}
}
/**
 * Submits an inline edit form through its JSON endpoint.
 *
 * @param {HTMLFormElement} form Inline edit form.
 * @returns {void}
 */
function dpPanelInlineEditSubmit(form){
	if(!form||form.dataset.dpPanelInlineBusy==="1"){return;}
	var value=dpPanelInlineEditValue(form);
	if(value===(form.dataset.dpPanelOriginal||"")){return;}
	form.dataset.dpPanelInlineBusy="1";
	dpPanelInlineEditSetStatus(form,"Saving","working");
	var data=new FormData(form);
	data.set("value",value);
	fetch(form.action,{
		method:"POST",
		body:data,
		headers:{"X-Requested-With":"DataphyrePanelInline","Accept":"application/json"},
		credentials:"same-origin"
	}).then(function(response){
		return response.json().then(function(payload){payload.__status=response.status;return payload;});
	}).then(function(payload){
		if(!payload||payload.ok===false){
			throw new Error((payload&&payload.message)||"Inline save failed");
		}
		form.dataset.dpPanelOriginal=String(payload.value==null ? value : payload.value);
		dpPanelInlineEditSetStatus(form,"Saved","success");
		if(payload.message){dpPanelToast(payload.message,"success");}
		setTimeout(function(){
			if(form.dataset.dpPanelInlineTone==="success"){dpPanelInlineEditSetStatus(form,"","");}
		},1400);
	}).catch(function(error){
		dpPanelInlineEditSetStatus(form,"Not saved","error");
		dpPanelToast(error&&error.message ? error.message : dpPanelText("client.inline_save_failed","Inline save failed"),"warning");
	}).finally(function(){
		form.dataset.dpPanelInlineBusy="0";
	});
}
dpPanelListen(document,"submit",function(event){
	var form=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-inline-edit]") : null;
	if(!form){return;}
	event.preventDefault();
	dpPanelInlineEditSubmit(form);
});
dpPanelListen(document,"change",function(event){
	var control=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-inline-autosave]") : null;
	if(!control){return;}
	var form=control.closest("[data-dp-panel-inline-edit]");
	if(form){dpPanelInlineEditSubmit(form);}
});
dpPanelListen(document,"keydown",function(event){
	var control=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-inline-autosave]") : null;
	if(!control||event.key!=="Enter"){return;}
	var form=control.closest("[data-dp-panel-inline-edit]");
	if(form){
		event.preventDefault();
		dpPanelInlineEditSubmit(form);
	}
});
dpPanelListen(document,"focusout",function(event){
	var control=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-inline-autosave]") : null;
	if(!control||control.tagName==="SELECT"||control.type==="checkbox"){return;}
	var form=control.closest("[data-dp-panel-inline-edit]");
	if(form){dpPanelInlineEditSubmit(form);}
});
/**
 * Returns the global Dataphyre Panel client runtime registry.
 *
 * The registry is the public browser extension point for field formatters and
 * field button handlers. It preserves existing registrations across repeated
 * ajax patches and returns itself from registration calls for fluent setup.
 *
 * @returns {Object<string, *>} Dataphyre Panel browser runtime.
 */
JS;
	}

}
