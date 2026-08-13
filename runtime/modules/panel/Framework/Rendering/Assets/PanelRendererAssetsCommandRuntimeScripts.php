<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits the command palette, saved views, and workspace state runtime.
 */
trait PanelRendererAssetsCommandRuntimeScripts {
	/**
	 * Returns this controller module for the public Panel runtime bundle.
	 */
	private static function commandRuntimeScript(): string {
		return <<<'JS'
function dpPanelCommandPaletteRoot(){
	var root=document.querySelector(".dp-panel-command-root");
	if(root){return root;}
	root=document.createElement("div");
	root.className="dp-panel-command-root";
	root.hidden=true;
	root.innerHTML="<section class=\"dp-panel-command\" role=\"dialog\" aria-modal=\"true\" aria-label=\""+dpPanelEscape(dpPanelText("client.command_palette","Command palette"))+"\"><input type=\"search\" aria-label=\""+dpPanelEscape(dpPanelText("client.jump_to_panel_item","Jump to a panel item"))+"\" placeholder=\""+dpPanelEscape(dpPanelText("client.jump_to_placeholder","Jump to..."))+"\" role=\"combobox\" aria-expanded=\"true\" aria-controls=\"dp-panel-command-list\" data-dp-panel-command-input><div class=\"dp-panel-command-context\" data-dp-panel-command-context></div><div class=\"dp-panel-command-list\" id=\"dp-panel-command-list\" role=\"listbox\"></div><div class=\"dp-panel-command-footer\" data-dp-panel-command-footer></div></section>";
	document.body.appendChild(root);
	root.addEventListener("click",function(event){
		if(event.target===root){dpPanelCloseCommandPalette();}
	});
	return root;
}
/** Moves the palette into the active modal and temporarily inerts its parent dialog. */
function dpPanelScopeCommandPalette(root){
	var modal=document.querySelector(".dp-panel-modal-root:not([hidden])");
	if(!root||!modal){return false;}
	var dialog=modal.querySelector(":scope > .dp-panel-modal");
	root.__dpPanelCommandParentDialog=dialog||null;
	if(dialog){
		root.__dpPanelCommandParentInert=dialog.hasAttribute("inert");
		root.__dpPanelCommandParentAriaHidden=dialog.getAttribute("aria-hidden");
		dialog.setAttribute("inert","");
		dialog.setAttribute("aria-hidden","true");
	}
	modal.appendChild(root);
	root.dataset.dpPanelCommandModalScoped="1";
	return true;
}
/** Restores a modal-scoped palette's parent semantics and body ownership. */
function dpPanelRestoreCommandPaletteScope(root){
	if(!root||root.dataset.dpPanelCommandModalScoped!=="1"){return;}
	var dialog=root.__dpPanelCommandParentDialog;
	if(dialog){
		if(root.__dpPanelCommandParentInert){dialog.setAttribute("inert","");}else{dialog.removeAttribute("inert");}
		if(root.__dpPanelCommandParentAriaHidden===null){dialog.removeAttribute("aria-hidden");}
		else{dialog.setAttribute("aria-hidden",root.__dpPanelCommandParentAriaHidden);}
	}
	delete root.dataset.dpPanelCommandModalScoped;
	delete root.__dpPanelCommandParentDialog;
	delete root.__dpPanelCommandParentInert;
	delete root.__dpPanelCommandParentAriaHidden;
	document.body.appendChild(root);
}
/**
 * Classifies a DOM command source into a command palette category.
 *
 * Category inference is intentionally DOM-structural so server-rendered links and
 * buttons can become searchable commands without additional metadata.
 *
 * @param {Element} element Link, button, summary, or related control.
 * @returns {string} Localized command category label.
 */
function dpPanelCommandCategory(element){
	if(element.closest(".dp-panel-commandbar")){return dpPanelText("client.toolbar","Toolbar");}
	if(element.closest(".dp-panel-bulk-bar")){return dpPanelText("client.bulk_action","Bulk action");}
	if(element.closest(".dp-panel-filter-panel")){return dpPanelText("client.filter","Filter");}
	if(element.closest(".dp-panel-column-picker")){return dpPanelText("client.columns","Columns");}
	if(element.closest(".dp-panel-pagination")){return dpPanelText("client.pagination","Pagination");}
	if(element.closest(".dp-panel-action-group")){return dpPanelText("client.action_menu","Action menu");}
	if(element.closest(".dp-panel-row-more-menu,.dp-panel-row-more")){return dpPanelText("client.record_action","Record action");}
	if(element.closest(".dp-panel-nav-card")){return dpPanelText("client.navigation","Navigation");}
	if(element.closest(".dp-panel-table-views")){return dpPanelText("client.view","View");}
	if(element.closest(".dp-panel-search-results")){return dpPanelText("client.search","Search");}
	if(element.closest("tr,.dp-panel-board-card,.dp-panel-alert-card,.dp-panel-task,.dp-panel-approval,.dp-panel-item,.dp-panel-payment,.dp-panel-shipment,.dp-panel-message,.dp-panel-note")){return dpPanelText("client.record_action","Record action");}
	if(element.closest(".dp-panel-toolbar-actions")){return dpPanelText("client.action","Action");}
	if(element.closest(".dp-panel-breadcrumbs")){return dpPanelText("client.breadcrumb","Breadcrumb");}
	return dpPanelText("client.opens_link","Link");
}
/**
 * Extracts stable display text from an element while removing decorative chrome.
 *
 * @param {Element} element Source element.
 * @returns {string} Whitespace-normalized user-facing text.
 */
function dpPanelElementText(element){
	var clone=element.cloneNode(true);
	clone.querySelectorAll(".dp-panel-action-icon,.dp-panel-table-view-dot,.dp-panel-nav-badge,small").forEach(function(node){node.remove();});
	return (clone.textContent||"").replace(/\s+/g," ").trim();
}
/**
 * Derives contextual record text for a command source.
 *
 * Row, board, alert, task, payment, shipment, message, and note containers expose
 * nearby record labels so command results can distinguish repeated actions such
 * as `Edit` or `View`.
 *
 * @param {Element} element Command source element.
 * @returns {string} Context label or an empty string.
 */
function dpPanelCommandContext(element){
	var row=element.closest("tr");
	if(row){
		var cell=row.querySelector("td:not(.dp-panel-select):not(.dp-panel-actions)");
		var rowText=cell?dpPanelElementText(cell):"";
		if(rowText){return rowText;}
	}
	var boardCard=element.closest(".dp-panel-board-card");
	if(boardCard){
		var title=boardCard.querySelector(".dp-panel-board-title");
		var cardText=title?dpPanelElementText(title):"";
		if(cardText){return cardText;}
	}
	var alertCard=element.closest(".dp-panel-alert-card,.dp-panel-task,.dp-panel-approval,.dp-panel-item,.dp-panel-payment,.dp-panel-shipment,.dp-panel-message,.dp-panel-note");
	if(alertCard){
		var strong=alertCard.querySelector("strong,a");
		var alertText=strong?dpPanelElementText(strong):"";
		if(alertText){return alertText;}
	}
	return "";
}
/**
 * Builds the searchable display label for a DOM-discovered command.
 *
 * @param {Element} element Command source element.
 * @returns {string} Label capped for compact palette rendering.
 */
function dpPanelCommandLabel(element){
	var label=dpPanelElementText(element);
	var context=dpPanelCommandContext(element);
	if(label&&context&&label.toLowerCase().indexOf(context.toLowerCase())===-1){
		label=label+": "+context;
	}
	return label.length>110?label.slice(0,107)+"...":label;
}
/**
 * Resolves a keyboard hint for a command item.
 *
 * Explicit `key_hint` metadata wins; otherwise common built-in command labels map
 * to their documented shortcut keys.
 *
 * @param {Object<string, *>} item Command item.
 * @returns {string} Shortcut display text or an empty string.
 */
function dpPanelCommandHint(item){
	if(item&&item.key_hint){return item.key_hint;}
	var label=String((item&&item.label)||"").toLowerCase();
	if(label==="search current view"||label==="search all records"){return "/";}
	if(label==="search navigation"){return "N";}
	if(label==="toggle filters"||label==="open filters"){return "F";}
	if(label==="open column controls"){return "C";}
	if(label==="show keyboard shortcuts"){return "?";}
	if(label==="select visible rows"){return "A";}
	if(label==="invert visible row selection"){return "X";}
	if(label==="clear selected rows"){return "Esc";}
	if(label==="open focused row actions"){return "M";}
	if(label==="preview focused row"){return "P";}
	if(label==="refresh panel"){return "R";}
	return "";
}
/**
 * Checks whether an element is currently visible enough to be commanded.
 *
 * @param {Element|null} element Candidate command source.
 * @returns {boolean} Whether the element is visible and not hidden by Panel state.
 */
function dpPanelCommandElementVisible(element){
	if(!element||element.hidden){return false;}
	if(element.closest("[hidden],.dp-panel-field-hidden")){return false;}
	return !!(element.offsetWidth||element.offsetHeight||element.getClientRects().length);
}
/**
 * Determines whether a button or summary is eligible for command discovery.
 *
 * The palette skips controls inside modal/palette internals, theme toggles,
 * live toggles, transient filter contents, and empty bulk bars to avoid exposing
 * unsafe duplicate or contextless commands.
 *
 * @param {Element|null} control Candidate control.
 * @returns {boolean} Whether the control should appear as a command item.
 */
function dpPanelCommandControlAllowed(control){
	if(!control||control.disabled||control.getAttribute("aria-disabled")==="true"){return false;}
	if(control.closest(".dp-panel-command-root,.dp-panel-modal-root")){return false;}
	if(control.closest(".dp-panel-theme-toggle")){return false;}
	if(control.matches("[data-dp-panel-live-toggle],[data-dp-panel-clear-selection]")){return false;}
	if(control.closest(".dp-panel-filters")&&!control.matches(".dp-panel-filter-panel>summary")){return false;}
	if(control.closest(".dp-panel-commandbar-search")){return false;}
	if(control.closest(".dp-panel-column-picker")&&!control.matches(".dp-panel-column-picker>summary")){return false;}
	if(control.closest(".dp-panel-bulk-bar-empty")){return false;}
	return dpPanelCommandElementVisible(control);
}
/**
 * Assigns a sort priority to a command item category.
 *
 * @param {Object<string, *>} item Command item.
 * @returns {number} Lower values sort earlier.
 */
function dpPanelCommandPriority(item){
	var category=item.category||"";
	if(category==="Command"){return 10;}
	if(category==="Focused row"){return 14;}
	if(category==="Selection"){return 16;}
	if(category==="Toolbar"){return 20;}
	if(category==="Bulk action"){return 24;}
	if(category==="Action"){return 28;}
	if(category==="Action menu"){return 32;}
	if(category==="Record action"){return 36;}
	if(category==="View"){return 42;}
	if(category==="Filter"||category==="Columns"){return 48;}
	if(category==="Navigation"){return 54;}
	if(category==="Pagination"){return 60;}
	if(category==="Breadcrumb"){return 66;}
	if(category===dpPanelText("client.pinned_category","Pinned")){return 70;}
	if(category===dpPanelText("client.recent","Recent")){return 72;}
	if(category===dpPanelText("client.saved_view","Saved view")){return 74;}
	return 80;
}
/**
 * Sorts command items by category priority and label.
 *
 * @param {Array<Object<string, *>>} items Command items.
 * @returns {Array<Object<string, *>>} Sorted command items.
 */
function dpPanelCommandSort(items){
	return items.sort(function(left,right){
		var priority=dpPanelCommandPriority(left)-dpPanelCommandPriority(right);
		if(priority!==0){return priority;}
		return String(left.label||"").localeCompare(String(right.label||""));
	});
}
/**
 * Builds normalized searchable text for a command item.
 *
 * @param {Object<string, *>} item Command item.
 * @returns {string} Lowercase search haystack.
 */
function dpPanelCommandSearchText(item){
	return String((item.category||"")+" "+(item.label||"")+" "+(item.context||"")+" "+(item.search||"")).toLowerCase().replace(/\s+/g," ").trim();
}
/**
 * Highlights query token ranges inside command result text.
 *
 * Returned HTML is escaped except for generated `mark` tags, so palette results
 * can safely render labels sourced from the DOM or server command state.
 *
 * @param {string} value Raw label or context text.
 * @param {string} query Search query.
 * @returns {string} Escaped HTML with matching ranges wrapped in mark tags.
 */
function dpPanelCommandHighlight(value,query){
	var text=String(value||"");
	var tokens=String(query||"").toLowerCase().replace(/\s+/g," ").trim().split(" ").filter(function(token){
		return token.length>0;
	});
	if(!tokens.length){return dpPanelEscape(text);}
	var ranges=[];
	var lower=text.toLowerCase();
	tokens.forEach(function(token){
		var start=0;
		while(start<lower.length){
			var index=lower.indexOf(token,start);
			if(index===-1){break;}
			ranges.push([index,index+token.length]);
			start=index+Math.max(1,token.length);
		}
	});
	if(!ranges.length){return dpPanelEscape(text);}
	ranges.sort(function(left,right){return left[0]-right[0]||right[1]-left[1];});
	var merged=[];
	ranges.forEach(function(range){
		var last=merged[merged.length-1];
		if(last&&range[0]<=last[1]){
			last[1]=Math.max(last[1],range[1]);
			return;
		}
		merged.push(range.slice());
	});
	var html="";
	var cursor=0;
	merged.forEach(function(range){
		if(range[0]>cursor){html+=dpPanelEscape(text.slice(cursor,range[0]));}
		html+="<mark>"+dpPanelEscape(text.slice(range[0],range[1]))+"</mark>";
		cursor=range[1];
	});
	if(cursor<text.length){html+=dpPanelEscape(text.slice(cursor));}
	return html;
}
/**
 * Infers a command tone from action-oriented label text.
 *
 * @param {string} label Command label.
 * @returns {string} Tone name or an empty string.
 */
function dpPanelCommandToneFromText(label){
	var text=String(label||"").toLowerCase();
	if(/\b(delete|remove|destroy|cancel|reject|deactivate|disable|block|ban|void|purge|archive)\b/.test(text)){return "danger";}
	if(/\b(pause|hold|risk|review|warn|flag|escalate)\b/.test(text)){return "warning";}
	if(/\b(approve|verify|activate|enable|ship|complete|restore|save|create|confirm)\b/.test(text)){return "success";}
	if(/\b(export|copy|download|import|preview|open|view|inspect|search)\b/.test(text)){return "info";}
	return "";
}
/**
 * Resolves command tone from control classes or label semantics.
 *
 * @param {Element|null} element Source control when available.
 * @param {string} label Command label.
 * @returns {string} Tone name or an empty string.
 */
function dpPanelCommandTone(element,label){
	var className=element&&element.className!==undefined ? String(element.className) : "";
	if(/\b(dp-panel-(action|button|row-link)-danger|danger)\b/.test(className)){return "danger";}
	if(/\b(dp-panel-(action|button|row-link)-warning|warning)\b/.test(className)){return "warning";}
	if(/\b(dp-panel-(action|button|row-link)-success|success)\b/.test(className)){return "success";}
	if(/\b(dp-panel-(action|button|row-link)-info|info)\b/.test(className)){return "info";}
	if(/\b(dp-panel-(action|button|row-link)-primary|primary)\b/.test(className)){return "primary";}
	return dpPanelCommandToneFromText(label);
}
/**
 * Builds status chips that describe the current command palette scope.
 *
 * Scope chips summarize focused row, selection, active table view, and filter
 * state so commands are understandable before execution.
 *
 * @returns {Array<{tone: string, label: string, value: string}>} Scope chips.
 */
function dpPanelCommandScopeChips(){
	var modal=document.querySelector(".dp-panel-modal-root:not([hidden])");
	if(modal){
		var modalTitle=(modal.querySelector(".dp-panel-modal-title h2")||{}).textContent||dpPanelText("modal.action","Current dialog");
		return [{tone:"view",label:"Modal",value:String(modalTitle).replace(/\s+/g," ").trim()}];
	}
	var chips=[];
	var focused=dpPanelFocusedTableRow();
	if(focused){
		chips.push({tone:"focused",label:"Focused row",value:dpPanelFocusedRowLabel(focused)});
	}
	if(document.querySelector("input[name='selected[]']")){
		var selection=dpPanelSelectionSummary();
		if(selection.total>0){
			chips.push({tone:selection.count>0?"selection":"neutral",label:"Selection",value:selection.label});
		}
	}
	var activeView=document.querySelector(".dp-panel-table-view.active");
	if(activeView){
		var viewLabel=dpPanelElementText(activeView);
		if(viewLabel){chips.push({tone:"view",label:"View",value:viewLabel});}
	}
	var filters=document.querySelectorAll(".dp-panel-filter-chip").length;
	if(filters>0){
		chips.push({tone:"filter",label:"Filters",value:filters===1?"1 active filter":filters+" active filters"});
	}
	return chips;
}
/**
 * Renders command palette scope chips into the palette shell.
 *
 * @param {HTMLDivElement} root Command palette root.
 * @returns {void}
 */
function dpPanelRenderCommandScope(root){
	var scope=root.querySelector("[data-dp-panel-command-context]");
	if(!scope){return;}
	var chips=dpPanelCommandScopeChips();
	if(!chips.length){
		scope.hidden=true;
		scope.innerHTML="";
		return;
	}
	scope.hidden=false;
	scope.innerHTML=chips.slice(0,4).map(function(chip){
		return "<span class=\"dp-panel-command-scope dp-panel-command-scope-"+dpPanelEscape(String(chip.tone||"neutral").replace(/[^a-z0-9_-]/gi,"").toLowerCase())+"\"><em>"+dpPanelEscape(chip.label)+"</em><strong>"+dpPanelEscape(chip.value)+"</strong></span>";
	}).join("");
}
/**
 * Builds footer metadata for the active command item.
 *
 * @param {Object<string, *>|null} item Active command item.
 * @returns {string} Slash-separated command metadata.
 */
function dpPanelCommandFooterText(item){
	if(!item){return "";}
	var parts=[item.category||dpPanelText("client.panel","Panel")];
	if(item.context){parts.push(item.context);}
	if(item.href){parts.push(dpPanelText("client.opens_link","Opens link"));}
	else if(typeof item.run==="function"){parts.push(dpPanelText("client.runs_command","Runs command"));}
	return parts.join(" / ");
}
/**
 * Renders command palette footer count, metadata, and keyboard hints.
 *
 * @param {HTMLDivElement} root Command palette root.
 * @param {number} index Active result index.
 * @param {string} query Current search query.
 * @returns {void}
 */
function dpPanelRenderCommandFooter(root,index,query){
	var footer=root.querySelector("[data-dp-panel-command-footer]");
	if(!footer){return;}
	var items=root.__dpPanelCommandItems||[];
	var count=items.length;
	var item=items[Math.max(0,Math.min(count-1,index||0))];
	var queryLabel=String(query||"").trim();
	if(!item){
		var noResults=dpPanelEscape(dpPanelText("client.no_results","No results"));
		footer.innerHTML="<span>"+noResults+(queryLabel ? " <strong>"+dpPanelEscape(queryLabel)+"</strong>" : "")+"</span><em><kbd>"+dpPanelEscape(dpPanelText("client.esc","Esc"))+"</kbd> "+dpPanelEscape(dpPanelText("common.close","Close"))+"</em>";
		return;
	}
	var hint=dpPanelCommandHint(item);
	var meta=dpPanelCommandFooterText(item);
	footer.innerHTML="<span>"+dpPanelEscape((index+1)+" of "+count)+"<strong>"+dpPanelEscape(item.label||dpPanelText("client.command","Command"))+"</strong>"+(meta?"<small>"+dpPanelEscape(meta)+"</small>":"")+"</span><em><kbd>"+dpPanelEscape(dpPanelText("client.enter","Enter"))+"</kbd> "+dpPanelEscape(dpPanelText("client.run","Run"))+(hint?"<kbd>"+dpPanelEscape(hint)+"</kbd> "+dpPanelEscape(dpPanelText("client.shortcut","Shortcut")):"")+"<kbd>"+dpPanelEscape(dpPanelText("client.esc","Esc"))+"</kbd> "+dpPanelEscape(dpPanelText("common.close","Close"))+"</em>";
}
/**
 * Scores a command against the current query.
 *
 * A null score means the item does not match every query token. Matching items
 * retain category priority while rewarding label and category prefix matches.
 *
 * @param {Object<string, *>} item Command item.
 * @param {string} query Search query.
 * @returns {number|null} Match score or null when not matched.
 */
function dpPanelCommandMatchScore(item,query){
	var needle=String(query||"").toLowerCase().replace(/\s+/g," ").trim();
	if(!needle){return dpPanelCommandPriority(item);}
	var haystack=dpPanelCommandSearchText(item);
	var tokens=needle.split(" ").filter(Boolean);
	var score=dpPanelCommandPriority(item);
	for(var index=0;index<tokens.length;index++){
		var token=tokens[index];
		var position=haystack.indexOf(token);
		if(position===-1){return null;}
		score+=position;
		if(haystack.indexOf(" "+token)===-1&&haystack.indexOf(token)!==0){score+=12;}
	}
	if(String(item.label||"").toLowerCase().indexOf(needle)===0){score-=18;}
	if(String(item.category||"").toLowerCase().indexOf(needle)===0){score-=8;}
	return score;
}
/**
 * Executes a DOM-backed command control.
 *
 * Summary elements toggle their disclosure and participate in transient panel
 * exclusivity; other controls are activated through their native click handler.
 *
 * @param {Element} control Link, button, or summary control.
 * @returns {void}
 */
function dpPanelRunCommandControl(control){
	if(!document.contains(control)){return;}
	if(control.tagName==="SUMMARY"){
		var details=control.closest("details");
		if(details){
			details.open=!details.open;
			if(details.open){dpPanelCloseTransientPanels(details);}
			control.focus();
			return;
		}
	}
	control.click();
}
/**
 * Builds context text for a bulk-bar command.
 *
 * @param {Element|null} control Control within a bulk action bar.
 * @returns {string} Selection count label or an empty string.
 */
function dpPanelBulkCommandContext(control){
	var bar=control&&control.closest ? control.closest(".dp-panel-bulk-bar") : null;
	if(!bar){return "";}
	var count=parseInt(bar.dataset.dpPanelSelectedCount||"0",10)||0;
	var total=parseInt(bar.dataset.dpPanelSelectionTotal||"0",10)||0;
	var label=count===1 ? dpPanelText("client.selected_record","1 selected record") : dpPanelText("client.selected_records","{count} selected records",{count:count});
	if(total>0){label+=dpPanelText("client.visible_suffix"," of {total} visible",{total:total});}
	return label;
}
/**
 * Resolves a readable label for the currently focused row.
 *
 * @param {Element|null} row Focused table row.
 * @returns {string} Row label for command context.
 */
function dpPanelFocusedRowLabel(row){
	if(!row){return dpPanelText("client.focused_row","Focused row");}
	var label=(row.getAttribute("aria-label")||"").replace(/\s+/g," ").trim();
	if(label){return label;}
	var cell=row.querySelector("td:not(.dp-panel-select):not(.dp-panel-actions)");
	return cell ? dpPanelElementText(cell) : dpPanelText("client.focused_row","Focused row");
}
/**
 * Collects actionable controls from the focused row action cell.
 *
 * @param {Element|null} row Focused table row.
 * @returns {Element[]} Row-specific controls safe to expose as commands.
 */
function dpPanelFocusedRowActionControls(row){
	if(!row){return [];}
	var controls=Array.prototype.slice.call(row.querySelectorAll(".dp-panel-actions .dp-panel-row-link[href],.dp-panel-actions button:not([disabled]),.dp-panel-actions summary"));
	return controls.filter(function(control){
		if(control.closest(".dp-panel-command-root,.dp-panel-modal-root")){return false;}
		if(control.matches(".dp-panel-row-more>summary")){return false;}
		if(control.getAttribute("aria-disabled")==="true"){return false;}
		return true;
	});
}
/**
 * Executes a command against the focused row while preserving row focus state.
 *
 * @param {Element|null} row Focused table row.
 * @param {Element|null} control Row action control.
 * @returns {boolean} Whether the action was executed.
 */
function dpPanelRunFocusedRowActionControl(row,control){
	if(!row||!control||!document.contains(control)){return false;}
	dpPanelFocusTableRow(row);
	var rowMore=control.closest(".dp-panel-row-more");
	if(rowMore){
		rowMore.open=true;
		dpPanelPrepareRowActionMenu(rowMore);
		dpPanelCloseTransientPanels(rowMore);
		dpPanelPlaceRowActionMenu(rowMore);
	}
	if(control.tagName==="SUMMARY"){
		dpPanelRunCommandControl(control);
		return true;
	}
	control.click();
	return true;
}
/**
 * Returns the scoped recent-navigation storage key.
 *
 * @returns {string} LocalStorage key including workspace scope.
 */
function dpPanelRecentStorageKey(){
	return dpPanelLegacyRecentStorageKey()+"|"+dpPanelRecentStorageScope();
}
/**
 * Returns the legacy unscoped recent-navigation storage key.
 *
 * @returns {string} Legacy LocalStorage key.
 */
function dpPanelLegacyRecentStorageKey(){
	return "dataphyre_panel_recent_items";
}
/**
 * Resolves the current Panel workspace scope for recent navigation.
 *
 * @returns {string} First path segment scope, or `/`.
 */
function dpPanelRecentStorageScope(){
	var panel=document.querySelector(".dp-panel");
	var source=location.pathname||"/";
	if(panel&&panel.dataset&&panel.dataset.dpPanelCurrentUrl){
		try{source=(new URL(panel.dataset.dpPanelCurrentUrl,location.href)).pathname||source;}catch(error){}
	}
	var parts=String(source||"/").split("/").filter(Boolean);
	return parts.length ? "/"+parts[0] : "/";
}
/**
 * Reads feature flags for client-side navigation helpers.
 *
 * @param {string} name Feature flag name.
 * @returns {boolean} Whether the feature is enabled for the rendered Panel.
 */
function dpPanelNavigationFeatureEnabled(name){
	var panel=document.querySelector(".dp-panel");
	if(!panel||!panel.dataset){return true;}
	if(name==="search"){return panel.dataset.dpPanelNavigationSearch!=="0";}
	if(name==="recent"){return panel.dataset.dpPanelRecentNavigation!=="0";}
	if(name==="pinning"){return panel.dataset.dpPanelPinnedNavigation!=="0";}
	if(name==="collapse"){return panel.dataset.dpPanelNavigationCollapse!=="0";}
	if(name==="collapse_exclusive"){return panel.dataset.dpPanelNavigationCollapseExclusive==="1";}
	return true;
}
/**
 * Checks whether a recent navigation item belongs to the current workspace scope.
 *
 * @param {Object<string, *>} item Recent navigation item.
 * @returns {boolean} Whether the item is same-origin and scoped to this workspace.
 */
function dpPanelRecentItemInScope(item){
	if(!item||!item.href){return false;}
	try{
		var url=new URL(item.href,location.href);
		if(url.origin!==location.origin){return false;}
		var parts=url.pathname.split("/").filter(Boolean);
		var scope=parts.length ? "/"+parts[0] : "/";
		return scope===dpPanelRecentStorageScope();
	}catch(error){
		return false;
	}
}
/**
 * Builds the LocalStorage key for saved views on the current Panel page.
 *
 * @returns {string} Saved-view storage key scoped by path and heading.
 */
function dpPanelSavedViewsKey(){
	var heading=((document.querySelector(".dp-panel h1")||{}).textContent||document.title||location.pathname).replace(/\s+/g," ").trim();
	return "dataphyre_panel_saved_views|"+location.pathname+"|"+heading;
}
/**
 * Loads saved views for the current Panel page.
 *
 * @returns {Array<{label: string, category: string, href: string, saved_at: string}>} Saved view items.
 */
function dpPanelSavedViews(){
	try{
		var views=JSON.parse(localStorage.getItem(dpPanelSavedViewsKey())||"[]");
		if(!Array.isArray(views)){return [];}
		return views.filter(function(view){return view&&view.href&&view.label;}).slice(0,20);
	}catch(error){
		return [];
	}
}
/**
 * Checks whether the current URL is already saved as a view.
 *
 * @returns {boolean} Whether the current view exists in saved-view storage.
 */
function dpPanelCurrentViewSaved(){
	return dpPanelSavedViews().some(function(view){return view.href===location.href;});
}
/**
 * Synchronizes saved-view buttons, state labels, and saved-view lists.
 *
 * @returns {void}
 */
function dpPanelRefreshSavedViewControls(){
	var saved=dpPanelCurrentViewSaved();
	document.body.classList.toggle("dp-panel-saved-view-active",saved);
	document.querySelectorAll("[data-dp-panel-save-view]").forEach(function(button){
		button.hidden=saved;
		button.setAttribute("aria-hidden",saved?"true":"false");
	});
	document.querySelectorAll("[data-dp-panel-remove-saved-view]").forEach(function(button){
		button.hidden=!saved;
		button.setAttribute("aria-hidden",saved?"false":"true");
	});
	document.querySelectorAll("[data-dp-panel-saved-view-state]").forEach(function(state){
		state.hidden=!saved;
		state.setAttribute("aria-hidden",saved?"false":"true");
		if(saved){
			var current=dpPanelSavedViews().find(function(view){return view.href===location.href;});
			state.textContent=current&&current.label ? dpPanelText("client.saved_prefix","Saved: {label}",{label:current.label}) : dpPanelText("client.saved_view","Saved view");
		}
	});
	var views=dpPanelSavedViews();
	document.querySelectorAll("[data-dp-panel-saved-view-list]").forEach(function(list){
		if(!views.length){
			list.innerHTML="<p class=\"dp-panel-saved-view-empty\">"+dpPanelEscape(dpPanelText("client.no_saved_views","No saved views yet."))+"</p>";
			return;
		}
		list.innerHTML=views.map(function(view){
			var active=view.href===location.href;
			var date=view.saved_at ? new Date(view.saved_at) : null;
			var savedAt=date&&!isNaN(date.getTime()) ? date.toLocaleDateString(undefined,{month:"short",day:"numeric"}) : "";
			return "<a class=\""+(active?"active":"")+"\" href=\""+dpPanelEscape(view.href)+"\""+(active?" aria-current=\"page\"":"")+"><strong>"+dpPanelEscape(view.label)+"</strong><small>"+dpPanelEscape(active?dpPanelText("common.current_view","Current view"):((new URL(view.href,location.href)).search||dpPanelText("common.default_slice","Default slice")))+"</small>"+(savedAt?"<em>"+dpPanelEscape(savedAt)+"</em>":"")+"</a>";
		}).join("");
	});
}
/**
 * Starts the save-current-view flow using the modal or prompt fallback.
 *
 * @returns {void}
 */
function dpPanelSaveCurrentView(){
	try{
		var defaultLabel=((document.querySelector(".dp-panel-table-view.active span")||{}).textContent||dpPanelText("common.current_view","Current view")).replace(/\s+/g," ").trim();
		if(typeof dpPanelOpenSaveViewModal==="function"&&typeof dpPanelModalRoot==="function"){
			dpPanelOpenSaveViewModal(defaultLabel);
			return;
		}
		var label=prompt(dpPanelText("client.name_this_view","Name this view"),defaultLabel);
		if(label===null){return;}
		dpPanelPersistSavedView(label);
	}catch(error){
		dpPanelToast(dpPanelText("client.unable_save_view","Unable to save view"),"warning");
	}
}
/**
 * Persists the current URL as a named saved view in localStorage.
 *
 * Existing entries with the same URL or case-insensitive label are replaced,
 * and the list is capped so local UI storage stays bounded.
 *
 * @param {string} label User-provided saved-view label.
 * @returns {boolean} Whether the view was persisted.
 */
function dpPanelPersistSavedView(label){
	label=String(label||"").replace(/\s+/g," ").trim();
	if(!label){dpPanelToast(dpPanelText("client.saved_view_needs_name","Saved view needs a name"),"warning");return false;}
	var href=location.href;
	var views=dpPanelSavedViews().filter(function(view){return view.href!==href&&view.label.toLowerCase()!==label.toLowerCase();});
	views.unshift({label:label, category:dpPanelText("client.saved_view","Saved view"), href:href, saved_at:new Date().toISOString()});
	localStorage.setItem(dpPanelSavedViewsKey(),JSON.stringify(views.slice(0,20)));
	dpPanelToast(dpPanelText("client.saved_view_added","Saved view added"),"success");
	dpPanelRefreshSavedViewControls();
	return true;
}
/**
 * Opens the modal form used to name and save the current view.
 *
 * @param {string} defaultLabel Suggested saved-view label.
 * @returns {void}
 */
function dpPanelOpenSaveViewModal(defaultLabel){
	var activeRoot=document.querySelector(".dp-panel-modal-root");
	if(activeRoot&&activeRoot.classList.contains("dp-panel-modal-busy")){
		dpPanelToast(dpPanelText("client.action_in_progress","Let the current action finish first"),"warning");
		return;
	}
	var trigger=document.createElement("button");
	trigger.type="button";
	trigger.hidden=true;
	trigger.setAttribute("aria-hidden","true");
	trigger.dataset.dpPanelActionModal="1";
	trigger.dataset.dpPanelActionName="save_view";
	trigger.dataset.dpPanelActionWidth="sm";
	trigger.dataset.dpPanelActionStyle="dialog";
	trigger.dataset.dpPanelActionTone="primary";
	trigger.dataset.dpPanelActionHeading=dpPanelText("client.save_view","Save view");
	trigger.dataset.dpPanelActionDescription=dpPanelText("client.save_view_description","Name the current filters, columns, sorting, grouping, and density.");
	trigger.dataset.dpPanelModalStack=activeRoot&&!activeRoot.hidden ? "push" : "replace";
	trigger.dataset.dpPanelModalExit="auto";
	var wrap=document.createElement("div");
	wrap.className="dp-panel-save-view-surface";
	wrap.innerHTML='<form class="dp-panel-form dp-panel-save-view-form" data-dp-panel-save-view-form data-dp-panel-modal-local-form="1"><section class="dp-panel-form-section"><label class="dp-panel-field"><span>'+dpPanelEscape(dpPanelText("client.view_name","View name"))+'</span><input type="text" name="label" autocomplete="off" required><small class="dp-panel-help">'+dpPanelEscape(dpPanelText("client.saved_locally","Saved locally for this browser and workspace."))+'</small></label></section><div class="dp-panel-modal-form-actions"><button class="dp-panel-button dp-panel-button-secondary" type="button" data-dp-panel-modal-cancel>'+dpPanelEscape(dpPanelText("common.cancel","Cancel"))+'</button><button class="dp-panel-button" type="submit">'+dpPanelEscape(dpPanelText("client.save_view","Save view"))+'</button></div></form>';
	wrap.appendChild(trigger);
	var form=wrap.querySelector("form");
	var input=wrap.querySelector("input[name='label']");
	if(input){input.value=defaultLabel||dpPanelText("common.current_view","Current view");}
	form.addEventListener("submit",function(event){
		event.preventDefault();
		if(dpPanelPersistSavedView(input?input.value:"")){dpPanelExitModal(true);}
	});
	dpPanelOpenModal(trigger,wrap);
	setTimeout(function(){
		if(input&&document.contains(input)){input.focus();input.select();}
	},20);
}
/**
 * Removes the current URL from saved-view storage.
 *
 * @returns {void}
 */
function dpPanelForgetCurrentSavedView(){
	try{
		var href=location.href;
		var views=dpPanelSavedViews();
		var kept=views.filter(function(view){return view.href!==href;});
		if(kept.length===views.length){dpPanelToast(dpPanelText("client.this_view_not_saved","This view is not saved"),"warning");return;}
		localStorage.setItem(dpPanelSavedViewsKey(),JSON.stringify(kept));
		dpPanelToast(dpPanelText("client.saved_view_removed","Saved view removed"),"success");
		dpPanelRefreshSavedViewControls();
	}catch(error){
		dpPanelToast(dpPanelText("client.unable_remove_saved_view","Unable to remove saved view"),"warning");
	}
}
/**
 * Copies saved views as a versioned JSON payload.
 *
 * @returns {void}
 */
function dpPanelExportSavedViews(){
	var views=dpPanelSavedViews();
	if(!views.length){dpPanelToast(dpPanelText("client.no_saved_views_export","No saved views to export"),"warning");return;}
	dpPanelCopyText(JSON.stringify({version:1, views:views},null,2),dpPanelText("client.saved_views_copied","Saved views copied"));
}
/**
 * Imports saved views from a pasted JSON payload.
 *
 * @returns {void}
 */
function dpPanelImportSavedViews(){
	var raw=prompt(dpPanelText("client.paste_saved_views_json","Paste saved views JSON"));
	if(raw===null){return;}
	try{
		var payload=JSON.parse(raw);
		var incoming=Array.isArray(payload) ? payload : (Array.isArray(payload.views) ? payload.views : []);
		if(!incoming.length){dpPanelToast(dpPanelText("client.no_saved_views_found","No saved views found"),"warning");return;}
		var views=dpPanelSavedViews();
		incoming.forEach(function(view){
			if(!view||!view.href||!view.label){return;}
			views=views.filter(function(existing){return existing.href!==view.href&&existing.label.toLowerCase()!==String(view.label).toLowerCase();});
			views.unshift({label:String(view.label), category:dpPanelText("client.saved_view","Saved view"), href:String(view.href), saved_at:view.saved_at||new Date().toISOString()});
		});
		localStorage.setItem(dpPanelSavedViewsKey(),JSON.stringify(views.slice(0,20)));
		dpPanelRefreshSavedViewControls();
		dpPanelToast(dpPanelText("client.saved_views_imported","Saved views imported"),"success");
	}catch(error){
		dpPanelToast(dpPanelText("client.saved_views_json_invalid","Saved views JSON was not valid"),"warning");
	}
}
/**
 * Builds storage prefixes for workspace-level Panel preferences.
 *
 * @returns {{heading: string, columnWidths: string, savedViews: string}} Workspace storage prefixes.
 */
function dpPanelWorkspaceStoragePrefix(){
	var heading=((document.querySelector(".dp-panel h1")||{}).textContent||document.title||location.pathname).replace(/\s+/g," ").trim();
	return {
		heading:heading,
		columnWidths:"dataphyre_panel_column_widths|"+location.pathname+"|"+heading+"|",
		savedViews:dpPanelSavedViewsKey()
	};
}
/**
 * Collects exportable Panel workspace preferences from localStorage.
 *
 * Only Dataphyre Panel preference keys are included, keeping snapshots limited
 * to UI state such as saved views, column widths, theme, sidebar, pinned, and
 * recent navigation state.
 *
 * @returns {Object<string, string>} LocalStorage preference map.
 */
function dpPanelWorkspacePreferences(){
	var prefs={};
	var prefix=dpPanelWorkspaceStoragePrefix();
	try{
		for(var index=0;index<localStorage.length;index++){
			var key=localStorage.key(index);
			if(!key){continue;}
			if(key===prefix.savedViews||key.indexOf(prefix.columnWidths)===0){
				prefs[key]=localStorage.getItem(key);
			}
		}
		["dataphyre_panel_theme","dataphyre_panel_sidebar_collapsed","dataphyre_panel_live_paused",dpPanelPinnedNavStorageKey(),dpPanelRecentStorageKey(),dpPanelSidebarGroupStorageKey()].forEach(function(key){
			var value=localStorage.getItem(key);
			if(value!==null){prefs[key]=value;}
		});
	}catch(error){}
	return prefs;
}
/**
 * Copies a versioned workspace snapshot to the clipboard.
 *
 * @returns {void}
 */
function dpPanelExportWorkspaceSnapshot(){
	var snapshot={
		type:"dataphyre_panel_workspace_snapshot",
		version:1,
		exported_at:new Date().toISOString(),
		title:((document.querySelector(".dp-panel h1")||{}).textContent||document.title||"Panel").replace(/\s+/g," ").trim(),
		url:location.href,
		path:location.pathname,
		saved_views:dpPanelSavedViews(),
		preferences:dpPanelWorkspacePreferences()
	};
	dpPanelCopyText(JSON.stringify(snapshot,null,2),"Workspace snapshot copied");
}
/**
 * Restores workspace preferences and saved views from a pasted snapshot.
 *
 * The import only writes keys prefixed with `dataphyre_panel_`, then optionally
 * navigates to the snapshot URL when it is same-origin and the user confirms.
 *
 * @returns {void}
 */
function dpPanelImportWorkspaceSnapshot(){
	var raw=prompt(dpPanelText("client.workspace_snapshot_prompt","Paste workspace snapshot JSON"));
	if(raw===null){return;}
	try{
		var snapshot=JSON.parse(raw);
		if(!snapshot||typeof snapshot!=="object"){throw new Error("Invalid snapshot");}
		var preferences=snapshot.preferences&&typeof snapshot.preferences==="object"&&!Array.isArray(snapshot.preferences) ? snapshot.preferences : {};
		Object.keys(preferences).forEach(function(key){
			if(key.indexOf("dataphyre_panel_")!==0){return;}
			localStorage.setItem(key,String(preferences[key]));
		});
		if(Array.isArray(snapshot.saved_views)){
			var views=dpPanelSavedViews();
			snapshot.saved_views.forEach(function(view){
				if(!view||!view.href||!view.label){return;}
				views=views.filter(function(existing){return existing.href!==view.href&&existing.label.toLowerCase()!==String(view.label).toLowerCase();});
				views.unshift({label:String(view.label), category:dpPanelText("client.saved_view","Saved view"), href:String(view.href), saved_at:view.saved_at||new Date().toISOString()});
			});
			localStorage.setItem(dpPanelSavedViewsKey(),JSON.stringify(views.slice(0,20)));
		}
		dpPanelRefreshPanelUi();
		dpPanelToast(dpPanelText("client.workspace_snapshot_restored","Workspace snapshot restored"),"success");
		if(snapshot.url){
			var next=new URL(String(snapshot.url),location.href);
			if(next.origin===location.origin&&next.href!==location.href&&confirm("Open the snapshot view now?")){
				location.href=next.href;
			}
		}
	}catch(error){
		dpPanelToast(dpPanelText("client.workspace_snapshot_invalid","Workspace snapshot JSON was not valid"),"warning");
	}
}
/**
 * Loads recent navigation items for the current workspace scope.
 *
 * Legacy and scoped storage are merged, de-duplicated by theme-neutral URL and
 * label, filtered to same-origin scope, and capped to eight entries.
 *
 * @returns {Array<{label: string, category: string, href: string, scope: string}>} Recent navigation items.
 */
function dpPanelRecentItems(){
	if(!dpPanelNavigationFeatureEnabled("recent")){return [];}
	try{
		var keys=[dpPanelRecentStorageKey(),dpPanelLegacyRecentStorageKey()];
		var items=[];
		var seen={};
		keys.forEach(function(key){
			var stored=JSON.parse(localStorage.getItem(key)||"[]");
			if(!Array.isArray(stored)){return;}
			stored.forEach(function(item){
				if(!item||!item.href||!item.label||!dpPanelRecentItemInScope(item)){return;}
				var neutral=dpPanelThemeNeutralHref(item.href);
				var itemKey=neutral+"|"+String(item.label).toLowerCase();
				if(seen[itemKey]){return;}
				seen[itemKey]=true;
				items.push({
					label:String(item.label),
					category:String(item.category||"Recent"),
					href:neutral,
					scope:item.scope||dpPanelRecentStorageScope()
				});
			});
		});
		items=items.slice(0,8);
		if(items.length){
			localStorage.setItem(dpPanelRecentStorageKey(),JSON.stringify(items));
		}
		return items;
	}catch(error){
		return [];
	}
}
/**
 * Records the current Panel page as a recent navigation item.
 *
 * @returns {void}
 */
function dpPanelRememberVisit(){
	if(!dpPanelNavigationFeatureEnabled("recent")){return;}
	try{
		var title=(document.querySelector(".dp-panel h1")||{}).textContent||document.title||location.pathname;
		title=String(title).replace(/\s+/g," ").trim();
		if(!title){return;}
		var href=dpPanelThemeNeutralHref(location.href);
		var items=dpPanelRecentItems().filter(function(item){return dpPanelThemeNeutralHref(item.href)!==href;});
		items.unshift({label:title, category:"Recent", href:href, scope:dpPanelRecentStorageScope()});
		localStorage.setItem(dpPanelRecentStorageKey(),JSON.stringify(items.slice(0,8)));
		if(typeof dpPanelRefreshRecentNavigation==="function"){dpPanelRefreshRecentNavigation();}
	}catch(error){}
}
/**
 * Copies text to the clipboard with an input fallback.
 *
 * @param {string} value Text to copy.
 * @param {string} message Success toast message.
 * @returns {Promise<boolean>} Promise resolving to whether copy appeared to succeed.
 */
function dpPanelCopyText(value,message){
	if(navigator.clipboard&&navigator.clipboard.writeText){
		return navigator.clipboard.writeText(value).then(function(){dpPanelToast(message||dpPanelText("common.copied","Copied"),"success");return true;}).catch(function(){dpPanelToast(dpPanelText("copy.unable","Unable to copy"),"warning");return false;});
	}
	var input=document.createElement("input");
	input.value=value;
	input.style.position="fixed";
	input.style.opacity="0";
	document.body.appendChild(input);
	input.select();
	var copied=false;
	try{copied=document.execCommand("copy");dpPanelToast(message||dpPanelText("common.copied","Copied"),"success");}catch(error){dpPanelToast(dpPanelText("copy.unable","Unable to copy"),"warning");}
	input.remove();
	return Promise.resolve(copied);
}
/**
 * Opens the keyboard shortcuts reference in a Panel modal.
 *
 * @returns {void}
 */
function dpPanelOpenShortcuts(){
	var trigger=document.createElement("button");
	trigger.type="button";
	trigger.dataset.dpPanelActionHeading=dpPanelText("client.keyboard_shortcuts","Keyboard shortcuts");
	trigger.dataset.dpPanelActionDescription="Fast panel movement and table work.";
	trigger.dataset.dpPanelActionWidth="lg";
	trigger.textContent=dpPanelText("client.keyboard_shortcuts","Keyboard shortcuts");
	var groups=[
		["Panel", [["Ctrl / Cmd + K","Open command palette"], ["/","Focus search"], ["N","Focus navigation search"], ["F","Toggle filters"], ["C","Open column controls"], ["?","Show shortcuts"], ["Esc","Close open panels"]]],
		["Sidebar", [["Arrow up / down","Move between navigation items"], ["Home / End","Jump to first or last navigation item"], ["Enter in navigation search","Open first match"], ["Esc in navigation search","Clear navigation search"], ["Command palette","Expand or collapse navigation groups"]]],
		["Tables", [["Arrow up / down","Move focused row"], ["Home / End","Jump to first or last row"], ["Enter","Open focused row"], ["M","Open focused row actions"], ["P","Preview focused row"], ["Left / Right","Move preview"], ["Space","Select focused row"], ["A","Select visible rows"], ["X","Invert visible selection"], ["Shift + click","Select a row range"], ["Command palette","Copy rows as CSV or JSON"]]]
	];
	var html=groups.map(function(group){
		return "<section class=\"dp-panel-shortcut-group\"><h3>"+dpPanelEscape(group[0])+"</h3><dl>"+group[1].map(function(item){
			return "<div><dt>"+dpPanelEscape(item[0])+"</dt><dd>"+dpPanelEscape(item[1])+"</dd></div>";
		}).join("")+"</dl></section>";
	}).join("");
	dpPanelOpenModal(trigger,"<div class=\"dp-panel-shortcuts\">"+html+"</div>");
}
/**
 * Reads server-provided command palette items from embedded JSON state.
 *
 * Server commands may provide links or mapped client actions. Invalid payloads
 * are ignored so the client palette continues to function with DOM-discovered
 * commands.
 *
 * @returns {Array<Object<string, *>>} Normalized server command items.
 */
function dpPanelServerCommandItems(){
	var script=document.querySelector("script[data-dp-panel-command-state]");
	if(!script){return [];}
	try{
		var state=JSON.parse(script.textContent||"{}");
		var commands=Array.isArray(state.commands)?state.commands:[];
		return commands.map(function(command){
			if(!command||!command.label){return null;}
			var href=command.href||command.url||"";
			var item={
				label:String(command.label||""),
				category:String(command.category||command.group||"Command"),
				context:String(command.description||""),
				search:[command.description||"", command.source||"", Array.isArray(command.keywords)?command.keywords.join(" "):""].join(" "),
				tone:String(command.tone||""),
				clientAction:String(command.client_action||"")
			};
			if(!href&&!item.clientAction){return null;}
			if(href){item.href=href;}
			if(command.new_tab){item.newTab=true;}
			if(item.clientAction==="focus_global_search"){item.run=function(){dpPanelFocusSearch(true);};}
			if(item.clientAction==="focus_local_search"){item.run=function(){dpPanelFocusSearch(false);};}
			if(item.clientAction==="focus_navigation_search"){item.run=dpPanelFocusSidebarSearch;}
			if(item.clientAction==="shortcuts"){item.run=dpPanelOpenShortcuts;}
			return item;
		}).filter(Boolean);
	}catch(error){
		return [];
	}
}
/**
 * Builds the full command palette item list for the current Panel state.
 *
 * The list merges server commands, pinned/recent navigation, saved views, built
 * in commands, selection commands, focused-row actions, visible links, and
 * eligible buttons while de-duplicating by stable href/category/label keys.
 *
 * @returns {Array<Object<string, *>>} Sorted command item list.
 */
function dpPanelCommandItems(){
	if(document.querySelector(".dp-panel-modal-root:not([hidden])")){
		return dpPanelCommandSort([
			{label:"Save current view",category:"Modal command",run:dpPanelSaveCurrentView},
			{label:dpPanelText("copy.current_url","Copy current URL"),category:"Modal command",run:function(){dpPanelCopyText(location.href,dpPanelText("copy.current_url_done","Copied current URL"));}},
			{label:"Show keyboard shortcuts",category:"Modal command",run:dpPanelOpenShortcuts}
		]);
	}
	var seen={};
	var items=[];
	dpPanelServerCommandItems().forEach(function(item){
		var key="server|"+(item.href||"")+"|"+item.category+"|"+item.label;
		if(seen[key]){return;}
		seen[key]=true;
		if(item.href){seen[item.href+"|"+item.label]=true;}
		items.push(item);
	});
	dpPanelPinnedNavItems().forEach(function(item){
		var href=dpPanelHrefForCurrentTheme(item.href);
		if(dpPanelNavStableHref(item.href)!==dpPanelNavStableHref(location.href)){
			var key="pinned|"+dpPanelThemeNeutralHref(item.href)+"|"+item.label;
			if(!seen[key]){
				items.push({label:item.label, category:dpPanelText("client.pinned_category","Pinned"), href:href});
				seen[key]=true;
				seen[dpPanelThemeNeutralHref(item.href)+"|"+item.label]=true;
			}
		}
	});
	dpPanelRecentItems().forEach(function(item){
		var href=dpPanelHrefForCurrentTheme(item.href);
		var neutral=dpPanelThemeNeutralHref(item.href);
		if(dpPanelNavStableHref(item.href)!==dpPanelNavStableHref(location.href)){
			var key="recent|"+neutral+"|"+item.label;
			if(!seen[neutral+"|"+item.label]&&!seen[key]){
				items.push({label:item.label, category:"Recent", href:href});
				seen[key]=true;
				seen[neutral+"|"+item.label]=true;
			}
		}
	});
	dpPanelSavedViews().forEach(function(item){
		if(item.href!==location.href){
			var key="saved|"+item.href+"|"+item.label;
			if(!seen[key]){
				seen[key]=true;
				items.push({label:item.label, category:dpPanelText("client.saved_view","Saved view"), href:item.href});
			}
		}
	});
	var globalSearch=document.querySelector("[data-dp-panel-global-search-input]");
	var localSearch=document.querySelector("[data-dp-panel-search-input]");
	if(globalSearch){
		items.push({label:"Search all records", category:"Command", run:function(){dpPanelFocusSearch(true);}});
	}
	if(localSearch){
		items.push({label:"Search current view", category:"Command", run:function(){dpPanelFocusSearch(false);}});
	}
	if(document.querySelector("[data-dp-panel-filter-modal]")){
		items.push({label:"Open filters", category:"Command", run:dpPanelToggleFilters});
	}
	if(document.querySelector(".dp-panel-column-picker")){
		items.push({label:"Open column controls", category:"Command", run:dpPanelOpenColumnPicker});
	}
	if(document.querySelector("[data-dp-panel-sidebar-search]")){
		items.push({label:"Search navigation", category:"Command", run:dpPanelFocusSidebarSearch});
	}
	if(document.querySelector("input[name='selected[]']")){
		var selection=dpPanelSelectionSummary();
		items.push({label:"Select visible rows", category:"Selection", run:dpPanelSelectVisibleRows, context:selection.total+" visible records", search:selection.search});
		items.push({label:"Invert visible row selection", category:"Selection", run:dpPanelInvertVisibleRows, context:selection.label, search:selection.search});
		items.push({label:"Clear selected rows", category:"Selection", run:dpPanelClearSelectedRows, context:selection.label, search:selection.search});
		if(selection.count>0){
			items.push({label:"Copy selected rows as CSV", category:"Selection", run:function(){dpPanelCopySelectedRows("csv");}, context:selection.label, search:selection.search+" csv export copy"});
			items.push({label:"Copy selected rows as JSON", category:"Selection", run:function(){dpPanelCopySelectedRows("json");}, context:selection.label, search:selection.search+" json export copy"});
		}
	}
	if(document.querySelector("[data-dp-panel-row]")){
		var focusedRow=dpPanelFocusedTableRow();
		if(focusedRow){
			var focusedLabel=dpPanelFocusedRowLabel(focusedRow);
			dpPanelFocusedRowActionControls(focusedRow).slice(0,8).forEach(function(control){
				var label=dpPanelCommandLabel(control);
				if(!label){return;}
				var key="focused-row|"+label+"|"+(control.name||control.value||control.getAttribute("aria-label")||control.getAttribute("href")||"");
				if(seen[key]){return;}
				seen[key]=true;
				items.push({
					label:label,
					category:"Focused row",
					run:function(){dpPanelRunFocusedRowActionControl(focusedRow,control);},
					context:focusedLabel,
					search:(label+" "+focusedLabel+" focused row action"),
					tone:dpPanelCommandTone(control,label)
				});
			});
		}
		items.push({label:"Open focused row actions", category:"Command", run:function(){dpPanelOpenFocusedRowActions();}});
		items.push({label:"Preview focused row", category:"Command", run:function(){dpPanelPreviewTableRow();}});
		items.push({label:"Copy focused row as JSON", category:"Command", run:function(){dpPanelCopyFocusedRow("json");}});
		items.push({label:"Copy visible rows as CSV", category:"Command", run:function(){dpPanelCopyVisibleRows("csv");}});
	}
	items.push({label:"Save current view", category:"Command", run:dpPanelSaveCurrentView});
	items.push({label:"Remove current saved view", category:"Command", run:dpPanelForgetCurrentSavedView});
	items.push({label:"Export saved views", category:"Command", run:dpPanelExportSavedViews});
	items.push({label:"Import saved views", category:"Command", run:dpPanelImportSavedViews});
	items.push({label:"Copy workspace snapshot", category:"Command", run:dpPanelExportWorkspaceSnapshot});
	items.push({label:"Restore workspace snapshot", category:"Command", run:dpPanelImportWorkspaceSnapshot});
	if(document.querySelector(".dp-panel-sidebar-nav")){
		if(dpPanelNavigationFeatureEnabled("pinning")){
			items.push({label:"Pin current navigation", category:"Command", run:dpPanelPinActiveNavigation});
			items.push({label:"Unpin current navigation", category:"Command", run:dpPanelUnpinActiveNavigation});
			if(dpPanelPinnedNavItems().length){
				items.push({label:dpPanelText("nav.clear_pinned","Clear pinned navigation"), category:dpPanelText("client.command","Command"), run:dpPanelClearPinnedNavigation});
			}
		}
		items.push({label:"Expand navigation groups", category:"Command", run:dpPanelExpandAllSidebarGroups});
		items.push({label:"Collapse navigation groups", category:"Command", run:dpPanelCollapseAllSidebarGroups});
		if(dpPanelNavigationFeatureEnabled("recent")&&dpPanelRecentItems().length){
			items.push({label:"Clear recent navigation", category:"Command", run:dpPanelClearRecentNavigation});
		}
	}
	items.push({label:dpPanelText("copy.current_url","Copy current URL"), category:dpPanelText("client.command","Command"), run:function(){dpPanelCopyText(location.href,dpPanelText("copy.current_url_done","Copied current URL"));}});
	items.push({label:"Show keyboard shortcuts", category:"Command", run:dpPanelOpenShortcuts});
	items.push({label:"Refresh panel", category:"Command", run:function(){location.reload();}});
	document.querySelectorAll(".dp-panel a[href]").forEach(function(link){
		if(!dpPanelCommandElementVisible(link)){return;}
		var href=link.getAttribute("href")||"";
		if(!href||href==="#"||href.indexOf("javascript:")===0){return;}
		if(link.closest(".dp-panel-command-root,.dp-panel-modal-root")){return;}
		var label=dpPanelCommandLabel(link);
		if(!label){return;}
		var key="link|"+href+"|"+label;
		if(seen[key]){return;}
		seen[key]=true;
		var linkBindings=dpPanelControlKeyBindings(link);
		items.push({label:label, category:dpPanelCommandCategory(link), href:href, tone:dpPanelCommandTone(link,label), key_hint:linkBindings.length?dpPanelDisplayKeyBinding(linkBindings[0]):""});
	});
	document.querySelectorAll(".dp-panel button:not([disabled]),.dp-panel summary").forEach(function(control){
		if(!dpPanelCommandControlAllowed(control)){return;}
		var label=dpPanelCommandLabel(control);
		if(!label){return;}
		var category=dpPanelCommandCategory(control);
		var key="control|"+label+"|"+category+"|"+(control.name||control.value||control.getAttribute("aria-label")||"");
		if(seen[key]){return;}
		seen[key]=true;
		var bulkContext=category==="Bulk action" ? dpPanelBulkCommandContext(control) : "";
		var controlBindings=dpPanelControlKeyBindings(control);
		items.push({label:label, category:category, run:function(){dpPanelRunCommandControl(control);}, context:bulkContext, search:bulkContext, tone:dpPanelCommandTone(control,label), key_hint:controlBindings.length?dpPanelDisplayKeyBinding(controlBindings[0]):""});
	});
	return dpPanelCommandSort(items);
}
/**
 * Renders filtered command palette results for a query.
 *
 * Results are scored, capped, grouped by category, rendered with escaped
 * highlights, and stored on the root for keyboard and click execution.
 *
 * @param {HTMLDivElement} root Command palette root.
 * @param {Array<Object<string, *>>} items Full command item list.
 * @param {string} query Current search query.
 * @returns {void}
 */
function dpPanelRenderCommandPalette(root,items,query){
	var list=root.querySelector(".dp-panel-command-list");
	var input=root.querySelector("[data-dp-panel-command-input]");
	dpPanelRenderCommandScope(root);
	var matched=items.map(function(item){
		return {item:item, score:dpPanelCommandMatchScore(item,query)};
	}).filter(function(match){
		return match.score!==null;
	}).sort(function(left,right){
		if(left.score!==right.score){return left.score-right.score;}
		return String(left.item.label||"").localeCompare(String(right.item.label||""));
	}).slice(0,16).map(function(match){
		return match.item;
	});
	if(!matched.length){
		list.innerHTML="<p class=\"dp-panel-command-empty\">"+dpPanelEscape(dpPanelText("client.no_results","No results"))+"</p>";
		root.__dpPanelCommandItems=[];
		if(input){input.removeAttribute("aria-activedescendant");}
		dpPanelRenderCommandFooter(root,0,query);
		return;
	}
	var previousCategory="";
	list.innerHTML=matched.map(function(item,index){
		item.__dpPanelCommandIndex=index;
		var category=String(item.category||"Panel");
		var heading=category!==previousCategory ? "<div class=\"dp-panel-command-group\">"+dpPanelEscape(category)+"</div>" : "";
		previousCategory=category;
		var hint=dpPanelCommandHint(item);
		var focused=category==="Focused row";
		var selection=category==="Selection";
		var bulk=category==="Bulk action";
		var tone=String(item.tone||dpPanelCommandTone(null,item.label)||"").replace(/[^a-z0-9_-]/gi,"").toLowerCase();
		var toneClass=tone ? " dp-panel-command-item-tone-"+tone : "";
		var labelHtml=dpPanelCommandHighlight(item.label,query);
		var context=item.context ? "<small>"+dpPanelCommandHighlight(item.context,query)+"</small>" : "";
		return heading+"<button id=\"dp-panel-command-option-"+index+"\" class=\"dp-panel-command-item"+toneClass+(focused?" dp-panel-command-item-focused":"")+(selection?" dp-panel-command-item-selection":"")+(bulk?" dp-panel-command-item-bulk":"")+(index===0?" active":"")+"\" type=\"button\" role=\"option\" aria-selected=\""+(index===0?"true":"false")+"\" data-dp-panel-command-index=\""+index+"\"><span>"+dpPanelEscape(category)+"</span><strong>"+labelHtml+"</strong>"+context+(hint?"<kbd>"+dpPanelEscape(hint)+"</kbd>":"")+"</button>";
	}).join("");
	root.__dpPanelCommandItems=matched;
	if(input){input.setAttribute("aria-activedescendant","dp-panel-command-option-0");}
	dpPanelRenderCommandFooter(root,0,query);
	list.querySelectorAll("[data-dp-panel-command-index]").forEach(function(button){
		button.addEventListener("click",function(){
			dpPanelRunCommand(root,parseInt(button.dataset.dpPanelCommandIndex||"0",10)||0);
		});
		button.addEventListener("mousemove",function(){
			dpPanelSetCommandActive(root,parseInt(button.dataset.dpPanelCommandIndex||"0",10)||0);
		});
	});
}
/**
 * Marks one command palette result as active and updates footer state.
 *
 * @param {HTMLDivElement} root Command palette root.
 * @param {number} index Desired active result index.
 * @returns {void}
 */
function dpPanelSetCommandActive(root,index){
	var items=Array.prototype.slice.call(root.querySelectorAll(".dp-panel-command-item"));
	if(!items.length){return;}
	var activeIndex=(index+items.length)%items.length;
	var input=root.querySelector("[data-dp-panel-command-input]");
	items.forEach(function(item,itemIndex){
		var active=itemIndex===activeIndex;
		item.classList.toggle("active",active);
		item.setAttribute("aria-selected",active?"true":"false");
		if(active&&input){input.setAttribute("aria-activedescendant",item.id);}
	});
	dpPanelRenderCommandFooter(root,activeIndex,(input&&input.value)||"");
	items[activeIndex].scrollIntoView({block:"nearest"});
}
/**
 * Moves active command palette selection by a relative direction.
 *
 * @param {HTMLDivElement} root Command palette root.
 * @param {number} direction Relative movement amount.
 * @returns {void}
 */
function dpPanelCommandMove(root,direction){
	var items=Array.prototype.slice.call(root.querySelectorAll(".dp-panel-command-item"));
	if(!items.length){return;}
	var current=Math.max(0,items.findIndex(function(item){return item.classList.contains("active");}));
	dpPanelSetCommandActive(root,current+direction);
}
/**
 * Executes the active command palette item.
 *
 * Link commands navigate or open a new tab; function commands run their client
 * action after the palette has closed and focus restoration has been handled.
 *
 * @param {HTMLDivElement} root Command palette root.
 * @param {number} index Command item index.
 * @returns {void}
 */
function dpPanelRunCommand(root,index){
	var items=root.__dpPanelCommandItems||[];
	var item=items[index]||items[0];
	if(!item){return;}
	dpPanelCloseCommandPalette();
	if(item.href){
		if(item.newTab){window.open(item.href,"_blank","noopener,noreferrer");return;}
		window.location.href=item.href;
		return;
	}
	if(typeof item.run==="function"){
		item.run();
	}
}
/**
 * Opens the command palette and wires live filtering and keyboard navigation.
 *
 * @returns {void}
 */
function dpPanelOpenCommandPalette(){
	var root=dpPanelCommandPaletteRoot();
	var input=root.querySelector("[data-dp-panel-command-input]");
	var items=dpPanelCommandItems();
	dpPanelCommandLastFocus=document.activeElement&&document.contains(document.activeElement)?document.activeElement:null;
	if(typeof dpPanelCloseTransientPanels==="function"){dpPanelCloseTransientPanels(null);}
	if(typeof dpPanelCloseMobileNavigation==="function"){dpPanelCloseMobileNavigation(false);}
	dpPanelScopeCommandPalette(root);
	root.hidden=false;
	document.body.classList.add("dp-panel-command-open");
	dpPanelRenderCommandPalette(root,items,"");
	input.value="";
	input.focus();
	input.oninput=function(){dpPanelRenderCommandPalette(root,items,input.value);};
	input.onkeydown=function(event){
		if(event.key==="Escape"){event.preventDefault();event.stopPropagation();dpPanelCloseCommandPalette();return;}
		if(dpPanelTrapFocus(root,event)){return;}
		if(event.key==="ArrowDown"){event.preventDefault();dpPanelCommandMove(root,1);return;}
		if(event.key==="ArrowUp"){event.preventDefault();dpPanelCommandMove(root,-1);return;}
		if(event.key==="Home"){event.preventDefault();dpPanelSetCommandActive(root,0);return;}
		if(event.key==="End"){event.preventDefault();dpPanelSetCommandActive(root,9999);return;}
		if(event.key==="Enter"){
			var active=root.querySelector(".dp-panel-command-item.active")||root.querySelector(".dp-panel-command-item");
			if(active){
				event.preventDefault();
				dpPanelRunCommand(root,parseInt(active.dataset.dpPanelCommandIndex||"0",10)||0);
			}
		}
	};
}
/**
 * Closes the command palette and restores the previously focused element.
 *
 * @returns {void}
 */
function dpPanelCloseCommandPalette(){
	var root=document.querySelector(".dp-panel-command-root");
	if(root){root.hidden=true;}
	document.body.classList.remove("dp-panel-command-open");
	if(root){dpPanelRestoreCommandPaletteScope(root);}
	if(dpPanelCommandLastFocus&&!dpPanelCommandElementVisible(dpPanelCommandLastFocus)){
		var rowMore=dpPanelCommandLastFocus.closest(".dp-panel-row-more");
		var disclosure=rowMore||dpPanelCommandLastFocus.closest(".dp-panel-action-group,.dp-panel-column-picker,.dp-panel-saved-view-menu,.dp-panel-horizontal-group");
		if(disclosure){dpPanelCommandLastFocus=disclosure.querySelector(":scope>summary");}
	}
	if(dpPanelCommandLastFocus&&document.contains(dpPanelCommandLastFocus)){
		var scrollState=[];
		for(var owner=dpPanelCommandLastFocus.parentElement;owner;owner=owner.parentElement){
			if(owner.scrollHeight>owner.clientHeight||owner.scrollWidth>owner.clientWidth){scrollState.push([owner,owner.scrollTop,owner.scrollLeft]);}
		}
		var pageScroll=[window.scrollX||0,window.scrollY||0];
		try{dpPanelCommandLastFocus.focus({preventScroll:true});}catch(error){dpPanelCommandLastFocus.focus();}
		scrollState.forEach(function(state){state[0].scrollTop=state[1];state[0].scrollLeft=state[2];});
		window.scrollTo(pageScroll[0],pageScroll[1]);
	}
	dpPanelCommandLastFocus=null;
}
/**
 * Serializes a form into a compact dirty-state fingerprint.
 *
 * The string is not a submission payload; it preserves enough names, values, and
 * selected file metadata to detect user edits before live refresh replaces DOM.
 *
 * @param {HTMLFormElement} form Form to fingerprint.
 * @returns {string} Stable dirty-state fingerprint.
 */
JS;
	}

}
