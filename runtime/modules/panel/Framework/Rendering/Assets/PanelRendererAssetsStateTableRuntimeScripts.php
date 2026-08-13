<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits the form state, patch restoration, and table interaction runtime.
 */
trait PanelRendererAssetsStateTableRuntimeScripts {
	/**
	 * Returns this controller module for the public Panel runtime bundle.
	 */
	private static function stateTableRuntimeScript(): string {
		return <<<'JS'
function dpPanelFormSerialize(form){
	var values=[];
	form.querySelectorAll("input,select,textarea").forEach(function(control){
		if(!control.name||control.type==="submit"||control.type==="button"||control.type==="reset"){return;}
		if(control.type==="file"){
			values.push(control.name+"="+Array.prototype.map.call(control.files||[],function(file){return file.name+":"+file.size;}).join(","));
			return;
		}
		if((control.type==="checkbox"||control.type==="radio")&&!control.checked){
			values.push(control.name+"=");
			return;
		}
		values.push(control.name+"="+String(control.value||""));
	});
	return values.join("&");
}
/**
 * Returns Panel forms that participate in dirty-state and submit lifecycle UI.
 *
 * @returns {HTMLFormElement[]} Tracked POST forms in Panel or modal surfaces.
 */
function dpPanelTrackedForms(){
	return Array.prototype.slice.call(document.querySelectorAll(".dp-panel form[method='post'],.dp-panel form[method='POST'],.dp-panel-modal-root form[method='post'],.dp-panel-modal-root form[method='POST']"));
}
/**
 * Checks whether a form belongs to the Panel runtime.
 *
 * @param {HTMLFormElement|null} form Candidate form.
 * @returns {boolean} Whether the form is inside Panel or modal UI.
 */
function dpPanelFormBelongsToPanel(form){
	return !!(form&&form.closest&&(form.closest(".dp-panel")||form.closest(".dp-panel-modal-root")));
}
/**
 * Serializes a single field wrapper for per-field dirty-state classes.
 *
 * @param {Element} field Field wrapper.
 * @returns {string} Field-local dirty-state fingerprint.
 */
function dpPanelFieldSerialize(field){
	var values=[];
	field.querySelectorAll("input,select,textarea").forEach(function(control){
		if(!control.name||control.disabled||control.type==="hidden"){return;}
		if((control.type==="checkbox"||control.type==="radio")&&!control.checked){return;}
		if(control.type==="file"){return;}
		values.push(control.name+"="+String(control.value||""));
	});
	return values.join("&");
}
/**
 * Captures initial dirty-state fingerprints for tracked forms and fields.
 *
 * @returns {void}
 */
function dpPanelInitFormState(){
	dpPanelTrackedForms().forEach(function(form){
		if(form.dataset.dpPanelInitialState===undefined){
			form.dataset.dpPanelInitialState=dpPanelFormSerialize(form);
		}
		form.querySelectorAll("[data-dp-panel-field-name]").forEach(function(field){
			if(field.dataset.dpPanelInitialState===undefined){
				field.dataset.dpPanelInitialState=dpPanelFieldSerialize(field);
			}
		});
	});
}
/**
 * Refreshes form and field dirty-state classes and live-refresh behavior.
 *
 * Dirty forms pause live updates to protect in-progress edits. When the dirty
 * state clears, the live refresh loop is scheduled again so the Panel can catch
 * up with server state.
 *
 * @returns {void}
 */
function dpPanelRefreshDirtyState(){
	var dirty=false;
	dpPanelTrackedForms().forEach(function(form){
		if(form.dataset.dpPanelSubmitted==="1"){return;}
		var initial=form.dataset.dpPanelInitialState;
		if(initial===undefined){
			initial=dpPanelFormSerialize(form);
			form.dataset.dpPanelInitialState=initial;
		}
		var formDirty=dpPanelFormSerialize(form)!==initial;
		form.classList.toggle("dp-panel-form-dirty",formDirty);
		form.querySelectorAll("[data-dp-panel-field-name]").forEach(function(field){
			var fieldInitial=field.dataset.dpPanelInitialState;
			if(fieldInitial===undefined){
				fieldInitial=dpPanelFieldSerialize(field);
				field.dataset.dpPanelInitialState=fieldInitial;
			}
			field.classList.toggle("dp-panel-field-dirty",dpPanelFieldSerialize(field)!==fieldInitial);
		});
		if(formDirty){dirty=true;}
	});
	var wasDirty=document.body.classList.contains("dp-panel-has-unsaved-changes");
	document.body.classList.toggle("dp-panel-has-unsaved-changes",dirty);
	if(dirty){
		dpPanelSetLiveMessage(dpPanelText("client.editing_paused","Editing paused"),"neutral");
	}
	else if(wasDirty){
		dpPanelSetLiveMessage(dpPanelText("client.resuming_updates","Resuming updates"),"syncing");
		dpPanelAjaxScheduleLiveRefresh();
	}
}
/**
 * Escapes a value for CSS selector fragments used during state restoration.
 *
 * @param {string} value Raw selector value.
 * @returns {string} Escaped selector value.
 */
function dpPanelEscapeSelectorValue(value){
	value=String(value||"");
	if(window.CSS&&typeof CSS.escape==="function"){return CSS.escape(value);}
	return value.replace(/["\\\]\[]/g,"\\$&");
}
/**
 * Captures focused Panel control identity and text selection before DOM patching.
 *
 * @param {Element} root Current Panel root.
 * @returns {{id: string, name: string, tag: string, type: string, value: string, start: number|null, end: number|null}|null} Focus state.
 */
function dpPanelCapturePanelFocus(root){
	var active=document.activeElement;
	if(!root||!active||!root.contains(active)||!active.matches("input,select,textarea,button")){return null;}
	var state={
		id:active.id||"",
		name:active.getAttribute("name")||"",
		tag:active.tagName.toLowerCase(),
		type:(active.getAttribute("type")||"").toLowerCase(),
		value:active.value!==undefined ? String(active.value) : "",
		start:null,
		end:null
	};
	try{
		if(typeof active.selectionStart==="number"){
			state.start=active.selectionStart;
			state.end=active.selectionEnd;
		}
	}catch(error){}
	return state.id||state.name ? state : null;
}
/**
 * Restores focus and text selection after the Panel root has been patched.
 *
 * @param {Object<string, *>|null} state Focus state from `dpPanelCapturePanelFocus`.
 * @returns {void}
 */
function dpPanelRestorePanelFocus(state){
	if(!state){return;}
	var root=document.querySelector("main.dp-panel");
	if(!root){return;}
	var candidates=[];
	if(state.id){candidates.push("#"+dpPanelEscapeSelectorValue(state.id));}
	if(state.name){candidates.push("[name=\""+dpPanelEscapeSelectorValue(state.name)+"\"]");}
	var control=null;
	for(var index=0;index<candidates.length&&!control;index++){
		var found=Array.prototype.slice.call(root.querySelectorAll(candidates[index]));
		control=found.find(function(item){
			var tag=item.tagName.toLowerCase();
			var type=(item.getAttribute("type")||"").toLowerCase();
			return tag===state.tag&&(!state.type||type===state.type);
		})||found[0]||null;
	}
	if(!control||control.disabled){return;}
	control.focus({preventScroll:true});
	if(state.start!==null&&typeof control.setSelectionRange==="function"){
		try{
			var end=state.end===null ? state.start : state.end;
			control.setSelectionRange(Math.min(state.start,control.value.length),Math.min(end,control.value.length));
		}catch(error){}
	}
}
/**
 * Captures scroll, table layout, and open disclosure state before a live patch.
 *
 * @param {Element} root Current Panel root.
 * @returns {{tables: Array, tableLayouts: Array, details: Array}|null} View state snapshot.
 */
function dpPanelCapturePanelViewState(root){
	if(!root){return null;}
	var state={tables:[],tableLayouts:[],details:[]};
	root.querySelectorAll(".dp-panel-table-scroll").forEach(function(scroller,index){
		state.tables.push({index:index,left:scroller.scrollLeft||0,top:scroller.scrollTop||0});
	});
	root.querySelectorAll(".dp-panel-table").forEach(function(table,index){
		var layout=dpPanelCaptureTableLayout(table,index);
		if(layout){state.tableLayouts.push(layout);}
	});
	root.querySelectorAll("details").forEach(function(details,index){
		if(details.open){
			state.details.push({
				index:index,
				className:details.className||"",
				summary:(details.querySelector("summary")&&details.querySelector("summary").textContent||"").replace(/\s+/g," ").trim()
			});
		}
	});
	return state;
}
/**
 * Builds a selector capable of finding a scrollable sidebar element later.
 *
 * @param {Element|null} element Sidebar element or descendant.
 * @returns {string} Selector relative to the sidebar root.
 */
function dpPanelSidebarScrollSelector(element){
	if(!element||!element.classList){return "";}
	if(element.hasAttribute&&element.hasAttribute("data-dp-panel-sidebar")){return "[data-dp-panel-sidebar]";}
	var classes=Array.prototype.slice.call(element.classList).filter(function(name){
		return name.indexOf("dp-panel-sidebar")===0;
	});
	if(!classes.length){return "";}
	return "."+classes.map(dpPanelEscapeSelectorValue).join(".");
}
/**
 * Captures non-zero sidebar scroll positions before DOM replacement.
 *
 * @param {Document|Element} root Search root.
 * @returns {Array<{selector: string, top: number, left: number}>} Sidebar scroll state.
 */
function dpPanelCaptureSidebarScrollState(root){
	var sidebar=(root||document).querySelector&&((root||document).querySelector("[data-dp-panel-sidebar]"));
	if(!sidebar){return [];}
	return Array.prototype.slice.call(sidebar.querySelectorAll("*")).concat([sidebar]).map(function(element){
		if((element.scrollTop||0)===0&&(element.scrollLeft||0)===0){return null;}
		var selector=dpPanelSidebarScrollSelector(element);
		if(!selector){return null;}
		return {selector:selector,top:element.scrollTop||0,left:element.scrollLeft||0};
	}).filter(Boolean);
}
/**
 * Restores sidebar scroll positions after DOM replacement.
 *
 * @param {Array<{selector: string, top: number, left: number}>} state Sidebar scroll state.
 * @returns {void}
 */
function dpPanelRestoreSidebarScrollState(state){
	if(!state||!state.length){return;}
	state.forEach(function(item){
		var element=item.selector==="[data-dp-panel-sidebar]" ? document.querySelector("[data-dp-panel-sidebar]") : document.querySelector("[data-dp-panel-sidebar] "+item.selector);
		if(!element){return;}
		element.scrollTop=item.top||0;
		element.scrollLeft=item.left||0;
	});
}
/**
 * Computes a table layout identity from wrapper metadata and leaf headers.
 *
 * @param {HTMLTableElement} table Table element.
 * @param {number} index Table index in the current Panel.
 * @returns {string} Layout key used to match tables across live refreshes.
 */
function dpPanelTableLayoutKey(table,index){
	var wrapper=table.closest(".dp-panel-table-scroll,.dp-panel-page-table,.dp-panel-relation,.dp-panel-table-shell");
	var label=wrapper ? (wrapper.getAttribute("aria-label")||wrapper.getAttribute("data-dp-panel-refresh-key")||"") : "";
	var headers=dpPanelTableLeafHeaders(table).map(function(th){return (th.textContent||th.getAttribute("aria-label")||"").replace(/\s+/g," ").trim().toLowerCase();}).join("|");
	return (label||"table")+"#"+index+"#"+headers;
}
/**
 * Captures optimized table column layout metadata.
 *
 * @param {HTMLTableElement} table Table element.
 * @param {number} index Table index in the current Panel.
 * @returns {Object<string, *>|null} Table layout snapshot.
 */
function dpPanelCaptureTableLayout(table,index){
	if(!table){return null;}
	var colgroup=table.querySelector("colgroup[data-dp-panel-a11y-colgroup='1']");
	if(!colgroup||!colgroup.children||!colgroup.children.length){return null;}
	var columns=Array.prototype.slice.call(colgroup.children).map(function(col,colIndex){
		return {
			index:colIndex,
			width:parseInt(col.style.width,10)||0,
			kind:col.dataset.dpPanelA11yColumnKind||"",
			desired:parseInt(col.dataset.dpPanelA11yColumnDesired,10)||parseInt(col.style.width,10)||0
		};
	}).filter(function(column){return column.width>0;});
	if(!columns.length){return null;}
	return {
		index:index,
		key:dpPanelTableLayoutKey(table,index),
		columns:columns,
		applied:parseInt(table.dataset.dpPanelA11yTableAppliedWidth,10)||columns.reduce(function(total,column){return total+column.width;},0),
		desired:parseInt(table.dataset.dpPanelA11yTableDesiredWidth,10)||0,
		compact:parseInt(table.dataset.dpPanelA11yTableCompactColumns,10)||0,
		scroll:table.dataset.dpPanelA11yTableScrollPreserved==="1"
	};
}
/**
 * Applies a captured table column layout to a freshly patched table.
 *
 * @param {HTMLTableElement} table Table to mutate.
 * @param {Object<string, *>} layout Captured table layout.
 * @returns {boolean} Whether the layout was applied.
 */
function dpPanelApplyTableLayout(table,layout){
	if(!table||!layout||!Array.isArray(layout.columns)||!layout.columns.length){return false;}
	if(dpPanelA11yTableInCardMode(table)){return false;}
	Array.from(table.querySelectorAll("colgroup[data-dp-panel-a11y-colgroup='1']")).forEach(function(colgroup){colgroup.remove();});
	var colgroup=document.createElement("colgroup");
	colgroup.dataset.dpPanelA11yColgroup="1";
	var columns=dpPanelA11yColumnsWithLastStretch(layout.columns,table.closest(".dp-panel-table-scroll")?.clientWidth||0);
	columns.forEach(function(column){
		var node=document.createElement("col");
		node.style.width=Math.round(column.width)+"px";
		node.dataset.dpPanelA11yColumnKind=column.kind||"";
		node.dataset.dpPanelA11yColumnDesired=String(Math.round(column.desired||column.width));
		colgroup.appendChild(node);
	});
	table.insertBefore(colgroup,table.firstChild);
	var applied=columns.reduce(function(total,column){return total+column.width;},0);
	var available=table.closest(".dp-panel-table-scroll")?.clientWidth||0;
	var target=dpPanelA11yTableTargetWidth(applied,available);
	table.classList.add("dp-panel-a11y-table-optimized");
	table.classList.toggle("dp-panel-a11y-table-compressed",(layout.compact||0)>0);
	table.classList.toggle("dp-panel-a11y-table-scroll-preserved",target>available+2);
	table.style.setProperty("min-width",Math.round(target)+"px","important");
	table.style.setProperty("width","100%","important");
	table.dataset.dpPanelA11yTableOptimized="1";
	table.dataset.dpPanelA11yTableColumnCount=String(columns.length);
	table.dataset.dpPanelA11yTableAvailableWidth=String(Math.round(available));
	table.dataset.dpPanelA11yTableAppliedWidth=String(applied);
	table.dataset.dpPanelA11yTableDesiredWidth=String(layout.desired||applied);
	table.dataset.dpPanelA11yTableCompactColumns=String(layout.compact||0);
	table.dataset.dpPanelA11yTableScrollPreserved=layout.scroll ? "1" : "0";
	table.dataset.dpPanelA11yPreservedColumns="1";
	table.dataset.dpPanelA11yLastColumnStretched=layout.desired&&applied>layout.desired+2 ? "1" : "0";
	columns.forEach(function(column){
		dpPanelA11yApplyColumnClasses(table,column.index,column.kind||"text",column.width,false,dpPanelTableLeafHeaders(table)[column.index]||null);
	});
	return true;
}
/**
 * Restores captured table layouts into matching tables in a new Panel root.
 *
 * @param {Element} root New Panel root.
 * @param {Object<string, *>} state Captured Panel view state.
 * @returns {void}
 */
function dpPanelRestoreTableLayouts(root,state){
	if(!root||!state||!Array.isArray(state.tableLayouts)||!state.tableLayouts.length){return;}
	var layouts=state.tableLayouts.slice();
	root.querySelectorAll(".dp-panel-table").forEach(function(table,index){
		var key=dpPanelTableLayoutKey(table,index);
		var layout=layouts.find(function(item){return item.key===key;})||layouts.find(function(item){return item.index===index;});
		if(layout){dpPanelApplyTableLayout(table,layout);}
	});
}
/**
 * Restores scroll positions, disclosure state, and table layouts after patching.
 *
 * @param {Object<string, *>|null} state Captured Panel view state.
 * @returns {void}
 */
function dpPanelRestorePanelViewState(state){
	if(!state){return;}
	var root=document.querySelector("main.dp-panel");
	if(!root){return;}
	dpPanelRestoreTableLayouts(root,state);
	var scrollers=root.querySelectorAll(".dp-panel-table-scroll");
	(state.tables||[]).forEach(function(item){
		var scroller=scrollers[item.index];
		if(scroller){
			scroller.scrollLeft=item.left||0;
			scroller.scrollTop=item.top||0;
		}
	});
	var details=Array.prototype.slice.call(root.querySelectorAll("details"));
	(state.details||[]).forEach(function(item){
		var match=details.find(function(candidate,index){
			if(index===item.index){return true;}
			var summary=(candidate.querySelector("summary")&&candidate.querySelector("summary").textContent||"").replace(/\s+/g," ").trim();
			return summary!==""&&summary===item.summary&&candidate.className===item.className;
		});
		if(match){match.open=true;}
	});
	dpPanelRefreshTableScroll();
}
/**
 * Builds a stable row key for change highlighting.
 *
 * @param {HTMLTableRowElement|null} row Table row.
 * @param {number} index Row index fallback.
 * @returns {string} Stable row key.
 */
function dpPanelRowKey(row,index){
	if(!row){return "";}
	var selected=row.querySelector("input[name='selected[]']");
	if(selected&&selected.value){return "selected:"+selected.value;}
	var link=row.querySelector("a[href]");
	if(link&&link.getAttribute("href")){return "link:"+link.getAttribute("href");}
	return "row:"+index;
}
/**
 * Builds a text signature for a table row.
 *
 * @param {HTMLTableRowElement|null} row Table row.
 * @returns {string} Whitespace-normalized row text.
 */
function dpPanelRowSignature(row){
	return (row&&row.textContent||"").replace(/\s+/g," ").trim();
}
/**
 * Captures row signatures before a live update for flash highlighting.
 *
 * @param {Element} root Current Panel root.
 * @returns {Object<string, string>} Row signature map keyed by stable row key.
 */
function dpPanelCaptureRowState(root){
	var state={};
	if(!root){return state;}
	root.querySelectorAll(".dp-panel-table tbody tr").forEach(function(row,index){
		var key=dpPanelRowKey(row,index);
		if(key){state[key]=dpPanelRowSignature(row);}
	});
	return state;
}
/**
 * Parses a row-update flash flag from an element.
 *
 * @param {Element|null} element Candidate element.
 * @returns {boolean} Whether update flash is explicitly enabled.
 */
function dpPanelUpdateFlashAttributeEnabled(element){
	if(!element||!element.getAttribute){return false;}
	var value=String(element.getAttribute("data-dp-panel-update-flash")||"").toLowerCase();
	return ["1","true","yes","on"].indexOf(value)!==-1;
}
/**
 * Resolves row-update flash behavior from an element's ancestor chain.
 *
 * @param {Element|null} element Starting element.
 * @returns {boolean} Whether row update flash should run.
 */
function dpPanelUpdateFlashEnabled(element){
	var current=element||document.querySelector("main.dp-panel");
	while(current&&current!==document){
		if(current.hasAttribute&&current.hasAttribute("data-dp-panel-update-flash")){
			return dpPanelUpdateFlashAttributeEnabled(current);
		}
		current=current.parentElement;
	}
	return false;
}
/**
 * Replaces the current Panel main element contents and attributes in place.
 *
 * Keeping the same node preserves external references while allowing live
 * refresh to swap server-rendered children and metadata atomically.
 *
 * @param {HTMLElement} currentMain Existing `main.dp-panel` element.
 * @param {HTMLElement} nextMain Parsed replacement `main.dp-panel` element.
 * @returns {HTMLElement|null} Patched main element.
 */
function dpPanelPatchMainElement(currentMain,nextMain){
	if(!currentMain||!nextMain){return null;}
	Array.prototype.slice.call(currentMain.attributes).forEach(function(attribute){
		if(!nextMain.hasAttribute(attribute.name)){
			currentMain.removeAttribute(attribute.name);
		}
	});
	Array.prototype.slice.call(nextMain.attributes).forEach(function(attribute){
		if(currentMain.getAttribute(attribute.name)!==attribute.value){
			currentMain.setAttribute(attribute.name,attribute.value);
		}
	});
	while(currentMain.firstChild){
		currentMain.removeChild(currentMain.firstChild);
	}
	while(nextMain.firstChild){
		currentMain.appendChild(nextMain.firstChild);
	}
	return currentMain;
}
/**
 * Synchronizes JSON state scripts and body theme attributes after a live patch.
 *
 * @param {Document} doc Parsed response document.
 * @returns {void}
 */
function dpPanelSyncPageStateScripts(doc){
	if(!doc){return;}
	["data-dp-panel-command-state","data-dp-panel-surface-state"].forEach(function(attribute){
		var selector="script["+attribute+"]";
		var next=doc.querySelector(selector);
		var current=document.querySelector(selector);
		if(!next){
			if(current){current.remove();}
			return;
		}
		if(!current){
			current=document.createElement("script");
			current.type="application/json";
			current.setAttribute(attribute,"");
			var main=document.querySelector("main.dp-panel");
			if(main&&main.parentNode){main.parentNode.insertBefore(current,main);}
			else{document.body.insertBefore(current,document.body.firstChild);}
		}
		current.textContent=next.textContent||"{}";
	});
	if(doc.body&&document.body){
		["data-dp-theme","data-dp-theme-mode","data-dp-theme-dark-mode"].forEach(function(attribute){
			if(doc.body.hasAttribute(attribute)){document.body.setAttribute(attribute,doc.body.getAttribute(attribute)||"");}
		});
	}
}
/**
 * Closes transient chrome around a live update or explicit UI transition.
 *
 * @param {Element|null} except Element or subtree that should remain open.
 * @returns {void}
 */
function dpPanelCloseTransientChrome(except){
	document.querySelectorAll(".dp-panel-horizontal-group[open],.dp-panel-column-picker[open],.dp-panel-action-group[open],.dp-panel-row-more[open],.dp-panel-saved-view-menu[open]").forEach(function(details){
		if(except&&(details===except||details.contains(except)||except.contains&&except.contains(details))){return;}
		details.open=false;
	});
}
/**
 * Applies temporary entered/updated classes to changed table rows.
 *
 * @param {Object<string, string>|null} previous Row signatures captured before update.
 * @returns {void}
 */
function dpPanelHighlightRowChanges(previous){
	if(!previous){return;}
	var root=document.querySelector("main.dp-panel");
	if(!root){return;}
	if(!dpPanelUpdateFlashEnabled(root)){return;}
	root.querySelectorAll(".dp-panel-table tbody tr").forEach(function(row,index){
		var key=dpPanelRowKey(row,index);
		if(!key||row.querySelector(".dp-panel-empty")){return;}
		if(previous[key]===undefined){
			row.classList.add("dp-panel-row-entered");
		}
		else if(previous[key]!==dpPanelRowSignature(row)){
			row.classList.add("dp-panel-row-updated");
		}
		if(row.classList.contains("dp-panel-row-entered")||row.classList.contains("dp-panel-row-updated")){
			setTimeout(function(){row.classList.remove("dp-panel-row-entered","dp-panel-row-updated");},2200);
		}
	});
}
/**
 * Reads submit button text for later restoration.
 *
 * @param {HTMLButtonElement|HTMLInputElement|null} button Submitter control.
 * @returns {string} Button label text.
 */
function dpPanelButtonText(button){
	if(!button){return "";}
	if(button.matches("input")){
		return button.value||"Working";
	}
	return (button.textContent||"Working").replace(/\s+/g," ").trim();
}
/**
 * Marks a form and submitter as actively submitting.
 *
 * The submitter label is preserved before being replaced with a spinner, and
 * secondary submit buttons are temporarily disabled unless named submitters need
 * to preserve their value.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @param {HTMLButtonElement|HTMLInputElement|null} submitter Submit control that initiated the request.
 * @returns {void}
 */
function dpPanelSetSubmitLoading(form,submitter){
	form.dataset.dpPanelSubmitting="1";
	form.dataset.dpPanelSubmitted="1";
	form.classList.remove("dp-panel-form-dirty");
	document.body.classList.remove("dp-panel-has-unsaved-changes");
	if(submitter){form.dataset.dpPanelSubmitterName=submitter.name||"";}
	form.classList.add("dp-panel-form-submitting");
	if(submitter){
		submitter.classList.add("dp-panel-submitter-busy");
		submitter.setAttribute("aria-busy","true");
		if(submitter.matches("input")){
			submitter.dataset.dpPanelLabel=submitter.value||"";
			submitter.value="Working...";
		}
		else{
			submitter.dataset.dpPanelLabel=dpPanelButtonText(submitter);
			submitter.dataset.dpPanelHtml=submitter.innerHTML;
			submitter.innerHTML='<span class="dp-panel-loading-spinner" aria-hidden="true"></span><span>'+dpPanelEscape(dpPanelText("client.working","Working..."))+'</span>';
		}
	}
	setTimeout(function(){
		form.querySelectorAll("button[type='submit'],input[type='submit']").forEach(function(button){
			button.setAttribute("aria-disabled","true");
			if(!button.name&&!button.disabled){
				button.dataset.dpPanelAjaxDisabled="1";
				button.disabled=true;
			}
		});
	},0);
}
/**
 * Restores form submit UI after an ajax submission finishes or fails.
 *
 * @param {HTMLFormElement|null} form Submitted form.
 * @returns {void}
 */
function dpPanelReleaseSubmitLoading(form){
	if(!form){return;}
	delete form.dataset.dpPanelSubmitting;
	form.classList.remove("dp-panel-form-submitting");
	form.querySelectorAll(".dp-panel-submitter-busy").forEach(function(button){
		button.classList.remove("dp-panel-submitter-busy");
		button.removeAttribute("aria-busy");
		if(button.dataset.dpPanelLabel!==undefined){
			if(button.matches("input")){button.value=button.dataset.dpPanelLabel;}
			else if(button.dataset.dpPanelHtml!==undefined){button.innerHTML=button.dataset.dpPanelHtml;}
			else{button.textContent=button.dataset.dpPanelLabel;}
			delete button.dataset.dpPanelLabel;
			delete button.dataset.dpPanelHtml;
		}
	});
	form.querySelectorAll("button[type='submit'],input[type='submit']").forEach(function(button){
		button.removeAttribute("aria-disabled");
		if(button.dataset.dpPanelAjaxDisabled==="1"){
			button.disabled=false;
			delete button.dataset.dpPanelAjaxDisabled;
		}
	});
}
/**
 * Finds the field shell that should receive validation state for a control.
 *
 * @param {Element|null} control Form control.
 * @returns {Element|null} Field shell element.
 */
function dpPanelFieldShell(control){
	if(!control||!control.closest){return null;}
	return control.closest(".dp-panel-field,.dp-panel-repeater-row,.dp-panel-form-section,label");
}
/**
 * Clears generated invalid-state classes from a form.
 *
 * @param {HTMLFormElement} form Form to clear.
 * @returns {void}
 */
function dpPanelClearValidation(form){
	form.querySelectorAll(".dp-panel-field-invalid").forEach(function(field){
		field.classList.remove("dp-panel-field-invalid");
	});
}
/**
 * Marks the shell for an invalid control.
 *
 * @param {Element} control Invalid form control.
 * @returns {void}
 */
function dpPanelMarkInvalidControl(control){
	var shell=dpPanelFieldShell(control);
	if(shell){shell.classList.add("dp-panel-field-invalid");}
}
/**
 * Runs browser constraint validation with Panel-specific highlighting.
 *
 * @param {HTMLFormElement} form Form to validate.
 * @returns {boolean} Whether the form passed client-side validation.
 */
function dpPanelValidateForm(form){
	dpPanelClearValidation(form);
	var invalid=Array.prototype.slice.call(form.querySelectorAll("input,select,textarea")).filter(function(control){
		if(control.disabled||control.type==="hidden"){return false;}
		if(typeof control.checkValidity!=="function"){return false;}
		var valid=control.checkValidity();
		if(!valid){dpPanelMarkInvalidControl(control);}
		return !valid;
	});
	if(!invalid.length){return true;}
	var first=invalid[0];
	if(typeof first.reportValidity==="function"){first.reportValidity();}
	first.focus({preventScroll:true});
	var shell=dpPanelFieldShell(first)||first;
	if(shell&&typeof shell.scrollIntoView==="function"){
		shell.scrollIntoView({block:"center",behavior:"smooth"});
	}
	dpPanelToast(dpPanelText("client.review_highlighted_fields","Review the highlighted fields"),"warning");
	return false;
}
/**
 * Synchronizes row selection classes, bulk bars, and select-all toggles.
 *
 * @returns {void}
 */
function dpPanelUpdateBulkSelection(){
	document.querySelectorAll("input[name='selected[]']").forEach(function(input){
		var row=input.closest("tr");
		if(row){
			row.classList.toggle("dp-panel-row-selected",input.checked);
			row.setAttribute("aria-selected",input.checked?"true":"false");
			row.dataset.dpPanelSelected=input.checked?"1":"0";
		}
	});
	document.querySelectorAll(".dp-panel-bulk-bar").forEach(function(bar){
		var formId="";
		var action=bar.querySelector("[form]");
		if(action){formId=action.getAttribute("form")||"";}
		var all=formId?document.querySelectorAll("input[name='selected[]'][form='"+formId+"']"):document.querySelectorAll("input[name='selected[]']");
		var selected=formId?document.querySelectorAll("input[name='selected[]'][form='"+formId+"']:checked"):document.querySelectorAll("input[name='selected[]']:checked");
		var count=selected.length;
		bar.dataset.dpPanelSelectedCount=String(count);
		bar.dataset.dpPanelSelectedLabel=count===1?"1 selected":count+" selected";
		bar.dataset.dpPanelSelectionTotal=String(all.length);
		bar.classList.toggle("dp-panel-bulk-bar-empty",count===0);
		bar.hidden=count===0;
		bar.setAttribute("aria-hidden",count===0?"true":"false");
		bar.querySelectorAll("[data-dp-panel-selected-count]").forEach(function(item){
			item.textContent=String(count);
		});
		bar.querySelectorAll("[data-dp-panel-selected-label]").forEach(function(item){
			item.textContent=count===1 ? dpPanelText("client.record_selected","record selected") : dpPanelText("client.records_selected","records selected");
		});
		bar.querySelectorAll("[data-dp-panel-bulk-status] small").forEach(function(item){
			item.textContent=all.length===count ? dpPanelText("client.all_visible_records","All visible records") : dpPanelText("client.visible_records","Visible records");
		});
	});
	document.querySelectorAll("[data-dp-panel-select-all]").forEach(function(toggle){
		var table=toggle.closest("table");
		var checkboxes=(table?table:document).querySelectorAll("input[name='selected[]']");
		var checked=(table?table:document).querySelectorAll("input[name='selected[]']:checked");
		toggle.indeterminate=checked.length>0&&checked.length<checkboxes.length;
		toggle.checked=checkboxes.length>0&&checked.length===checkboxes.length;
	});
}
/**
 * Returns visible Panel table rows that participate in keyboard navigation.
 *
 * @param {Document|Element} scope Search scope, or document by default.
 * @returns {HTMLTableRowElement[]} Visible rows with Panel row metadata.
 */
function dpPanelTableRows(scope){
	return Array.prototype.slice.call((scope||document).querySelectorAll("[data-dp-panel-row]")).filter(function(row){
		return dpPanelCommandElementVisible(row);
	});
}
/**
 * Initializes roving tabindex state for every rendered Panel table body.
 *
 * @returns {void}
 */
function dpPanelInitTableRows(){
	document.querySelectorAll(".dp-panel-table tbody").forEach(function(body){
		var rows=dpPanelTableRows(body);
		var hasActive=rows.some(function(row){return row.getAttribute("tabindex")==="0";});
		rows.forEach(function(row,index){
			row.setAttribute("tabindex",(!hasActive&&index===0) || row.getAttribute("tabindex")==="0" ? "0" : "-1");
			row.setAttribute("data-dp-panel-row-ready","1");
		});
	});
}
/**
 * Moves keyboard focus to a table row and updates row focus classes.
 *
 * @param {HTMLTableRowElement|null} row Row to focus.
 * @returns {void}
 */
function dpPanelFocusTableRow(row){
	if(!row){return;}
	var body=row.closest("tbody");
	dpPanelTableRows(body||document).forEach(function(item){
		item.setAttribute("tabindex",item===row ? "0" : "-1");
		item.classList.toggle("dp-panel-row-focused",item===row);
	});
	row.focus({preventScroll:true});
	if(typeof row.scrollIntoView==="function"){
		row.scrollIntoView({block:"nearest"});
	}
}
/**
 * Moves focused row state by a relative direction within the same table body.
 *
 * @param {HTMLTableRowElement|null} row Current row.
 * @param {number} direction Relative movement amount.
 * @returns {boolean} Whether focus moved to a different row.
 */
function dpPanelMoveTableRow(row,direction){
	var rows=dpPanelTableRows(row?row.closest("tbody"):document);
	if(!rows.length){return false;}
	var index=Math.max(0,rows.indexOf(row));
	var next=rows[Math.max(0,Math.min(rows.length-1,index+direction))];
	if(next&&next!==row){
		dpPanelFocusTableRow(next);
		return true;
	}
	return false;
}
/**
 * Finds the primary action link for a row.
 *
 * @param {HTMLTableRowElement|null} row Row to inspect.
 * @returns {HTMLAnchorElement|null} Primary link or null when absent.
 */
function dpPanelRowPrimaryLink(row){
	if(!row){return null;}
	return row.querySelector(".dp-panel-actions .dp-panel-row-link[href],.dp-panel-cell-link[href],a[href]");
}
/**
 * Checks whether a row click should avoid activating the row itself.
 *
 * Interactive descendants and copy/preview/resizer controls are allowed to own
 * their event so row-level activation does not fight explicit cell actions.
 *
 * @param {Event|null} event User interaction event.
 * @param {HTMLTableRowElement|null} row Row that would otherwise activate.
 * @returns {boolean} Whether row activation should be skipped.
 */
function dpPanelRowActivationBlockedByEvent(event,row){
	if(!event||!event.target||!event.target.closest){return false;}
	var target=event.target;
	if(target===row){return false;}
	var blocker=target.closest("a,button,input,select,textarea,summary,label,details,[role='button'],[role='link'],[contenteditable='true'],[data-dp-panel-copy-entry],[data-dp-panel-preview-row],[data-dp-panel-field-button],[data-dp-panel-inline-edit],.dp-panel-actions,.dp-panel-select,.dp-panel-cell-copy,.dp-panel-cell-stack-copyable,.dp-panel-action-menu,.dp-panel-row-more-menu,.dp-panel-column-resizer");
	return !!(blocker&&(!row||blocker===row||row.contains(blocker)));
}
/**
 * Activates a row URL using modal, confirm, ajax, or full navigation semantics.
 *
 * @param {HTMLTableRowElement|null} row Row carrying `data-dp-panel-row-url`.
 * @returns {boolean} Whether an activation path was found.
 */
function dpPanelActivateRowUrl(row){
	if(!row){return false;}
	var url=row.dataset.dpPanelRowUrl||"";
	if(!url){return false;}
	if(row.dataset.dpPanelActionModal==="1"){
		if(row.dataset.dpPanelActionHasFields==="1"){
			dpPanelFetchAction(row);
			return true;
		}
		if(row.dataset.dpPanelActionHasContent==="1"&&row.dataset.dpPanelActionHasHandler!=="1"&&!row.dataset.confirm){
			dpPanelOpenModal(row,row.dataset.dpPanelActionContent||"");
			return true;
		}
		dpPanelConfirmAction(row);
		return true;
	}
	dpPanelConfirmUnsavedNavigation(function(){
		if(typeof dpPanelAjaxEnabled==="function"&&dpPanelAjaxEnabled()&&dpPanelAjaxAllowedUrl(url)){
			dpPanelAjaxLoad(url,{});
		}
		else {
			window.location.href=url;
		}
	});
	return true;
}
/**
 * Resolves the active row from DOM focus or roving tabindex state.
 *
 * @returns {HTMLTableRowElement|null} Focused row, fallback row, or null.
 */
function dpPanelFocusedTableRow(){
	var row=document.activeElement&&document.activeElement.closest ? document.activeElement.closest("[data-dp-panel-row]") : null;
	if(row){return row;}
	return document.querySelector(".dp-panel-row-focused")||document.querySelector("[data-dp-panel-row][tabindex='0']");
}
var dpPanelPreviewCurrentRow=null;
/**
 * Activates the current row's primary action.
 *
 * @param {HTMLTableRowElement|null} row Row to activate.
 * @returns {boolean} Whether an action was executed.
 */
function dpPanelActivateTableRow(row){
	if(dpPanelActivateRowUrl(row)){return true;}
	var link=dpPanelRowPrimaryLink(row);
	if(!link){dpPanelToast(dpPanelText("client.no_primary_action","No primary action on this row"),"warning");return false;}
	link.click();
	return true;
}
/**
 * Opens or focuses the focused row's action controls.
 *
 * @param {HTMLTableRowElement|null} row Optional row override.
 * @returns {boolean} Whether row actions were opened or focused.
 */
function dpPanelOpenFocusedRowActions(row){
	row=row||dpPanelFocusedTableRow();
	if(!row){dpPanelToast(dpPanelText("client.no_focused_row","No row is focused"),"warning");return false;}
	dpPanelFocusTableRow(row);
	var details=row.querySelector(".dp-panel-row-more");
	if(details){
		details.open=true;
		dpPanelPrepareRowActionMenu(details);
		dpPanelCloseTransientPanels(details);
		requestAnimationFrame(function(){
			dpPanelPlaceRowActionMenu(details);
			if(!dpPanelFocusRowActionMenuItem(details,0)){
				var summary=details.querySelector(":scope>summary");
				if(summary){summary.focus({preventScroll:true});}
			}
		});
		return true;
	}
	var action=row.querySelector(".dp-panel-actions .dp-panel-row-link[href],.dp-panel-actions .dp-panel-action, .dp-panel-actions button, .dp-panel-actions a[href]");
	if(action){
		action.focus({preventScroll:true});
		dpPanelToast(dpPanelText("client.focused_row_action","Focused the row action"),"info");
		return true;
	}
	dpPanelToast(dpPanelText("client.no_row_actions","No actions on this row"),"warning");
	return false;
}
/**
 * Extracts copy/export data from visible non-action row cells.
 *
 * @param {HTMLTableRowElement|null} row Row to read.
 * @returns {Object<string, string>} Map of cell label to normalized text value.
 */
function dpPanelRowData(row){
	var data={};
	if(!row){return data;}
	Array.prototype.slice.call(row.querySelectorAll("td")).forEach(function(cell){
		if(cell.classList.contains("dp-panel-select")||cell.classList.contains("dp-panel-actions")){return;}
		var label=(cell.getAttribute("data-label")||dpPanelText("client.field","Field")).replace(/\s+/g," ").trim();
		var copy=cell.cloneNode(true);
		Array.prototype.slice.call(copy.querySelectorAll(".dp-panel-cell-copy,.dp-panel-cell-icon,[data-dp-panel-copy-entry]")).forEach(function(control){
			control.remove();
		});
		var value=(copy.textContent||"").replace(/\s+/g," ").trim();
		if(label){data[label]=value;}
	});
	return data;
}
/**
 * Extracts non-empty data maps for multiple rows.
 *
 * @param {HTMLTableRowElement[]} rows Rows to read.
 * @returns {Array<Object<string, string>>} Exportable row data.
 */
function dpPanelRowsData(rows){
	return rows.map(dpPanelRowData).filter(function(item){return Object.keys(item).length>0;});
}
/**
 * Escapes one value for CSV output.
 *
 * @param {*} value Cell value.
 * @returns {string} CSV-safe cell value.
 */
function dpPanelCsvEscape(value){
	value=String(value==null?"":value);
	return /[",\n\r]/.test(value) ? "\""+value.replace(/"/g,"\"\"")+"\"" : value;
}
/**
 * Builds CSV text for exported table rows.
 *
 * @param {HTMLTableRowElement[]} rows Rows to export.
 * @returns {string} CSV document text.
 */
function dpPanelRowsCsv(rows){
	var data=dpPanelRowsData(rows);
	if(!data.length){return "";}
	var labels=[];
	data.forEach(function(item){
		Object.keys(item).forEach(function(label){
			if(labels.indexOf(label)===-1){labels.push(label);}
		});
	});
	return [labels.map(dpPanelCsvEscape).join(",")].concat(data.map(function(item){
		return labels.map(function(label){return dpPanelCsvEscape(item[label]||"");}).join(",");
	})).join("\n");
}
/**
 * Returns selected rows within a selection scope.
 *
 * @param {Document|Element} scope Selection scope.
 * @returns {HTMLTableRowElement[]} Selected row elements.
 */
function dpPanelSelectedRows(scope){
	return dpPanelSelectionInputs(scope||document).filter(function(input){return input.checked;}).map(function(input){return input.closest("[data-dp-panel-row]");}).filter(Boolean);
}
/**
 * Returns visible rows in the active table selection scope.
 *
 * @returns {HTMLTableRowElement[]} Visible row elements.
 */
function dpPanelVisibleRows(){
	return dpPanelTableRows(dpPanelSelectionScope());
}
/**
 * Copies row data as JSON or CSV.
 *
 * @param {HTMLTableRowElement[]} rows Rows to copy.
 * @param {string} format `json` or `csv`.
 * @param {string} label Success toast label.
 * @returns {boolean} Whether copy was attempted.
 */
function dpPanelCopyRows(rows,format,label){
	rows=(rows||[]).filter(Boolean);
	if(!rows.length){dpPanelToast(dpPanelText("copy.no_rows","No rows to copy"),"warning");return false;}
	var value=format==="json" ? JSON.stringify(dpPanelRowsData(rows),null,2) : dpPanelRowsCsv(rows);
	if(!value){dpPanelToast(dpPanelText("copy.no_row_data","No row data to copy"),"warning");return false;}
	dpPanelCopyText(value,label||dpPanelText("copy.rows","Copied {count} {row}",{count:rows.length,row:rows.length===1?"row":"rows"}));
	return true;
}
/**
 * Copies the focused row.
 *
 * @param {string} format `json` or `csv`.
 * @returns {boolean} Whether copy was attempted.
 */
function dpPanelCopyFocusedRow(format){
	var row=dpPanelFocusedTableRow();
	if(!row){dpPanelToast(dpPanelText("client.no_focused_row","No row is focused"),"warning");return false;}
	return dpPanelCopyRows([row],format||"json",dpPanelText("copy.focused_row","Copied focused row"));
}
/**
 * Copies selected rows.
 *
 * @param {string} format `json` or `csv`.
 * @returns {boolean} Whether copy was attempted.
 */
function dpPanelCopySelectedRows(format){
	return dpPanelCopyRows(dpPanelSelectedRows(document),format||"csv",dpPanelText("copy.selected_rows","Copied selected rows"));
}
/**
 * Copies visible rows in the active table scope.
 *
 * @param {string} format `json` or `csv`.
 * @returns {boolean} Whether copy was attempted.
 */
function dpPanelCopyVisibleRows(format){
	return dpPanelCopyRows(dpPanelVisibleRows(),format||"csv",dpPanelText("copy.visible_rows","Copied visible rows"));
}
/**
 * Returns rows available to the row preview modal.
 *
 * @param {HTMLTableRowElement|null} row Current row.
 * @returns {HTMLTableRowElement[]} Previewable rows.
 */
function dpPanelPreviewRows(row){
	return dpPanelTableRows(row?row.closest("tbody"):document);
}
/**
 * Opens a modal preview for a table row.
 *
 * The preview uses configured preview fields when present, otherwise visible
 * non-action cells become definition-list entries. Row action HTML is copied into
 * the preview to keep record actions reachable from the modal context.
 *
 * @param {HTMLTableRowElement|null} row Row to preview.
 * @returns {boolean} Whether a preview was opened.
 */
function dpPanelPreviewTableRow(row){
	row=row||dpPanelFocusedTableRow();
	if(!row){dpPanelToast(dpPanelText("client.no_focused_row","No row is focused"),"warning");return false;}
	dpPanelPreviewCurrentRow=row;
	dpPanelFocusTableRow(row);
	var title=(row.getAttribute("aria-label")||dpPanelText("client.record","Record")).replace(/\s+/g," ").trim();
	var rows=dpPanelPreviewRows(row);
	var index=Math.max(0,rows.indexOf(row));
	var configuredFields=null;
	if(row.dataset.dpPanelPreviewFields){
		try{configuredFields=JSON.parse(row.dataset.dpPanelPreviewFields)||null;}catch(error){configuredFields=null;}
	}
	var fields=Array.isArray(configuredFields)&&configuredFields.length ? configuredFields : Array.prototype.slice.call(row.querySelectorAll("td")).filter(function(cell){
		return !cell.classList.contains("dp-panel-select")&&!cell.classList.contains("dp-panel-actions");
	});
	var position=dpPanelText("client.preview_position","{index} of {total}",{index:index+1,total:rows.length});
	var html="<div class=\"dp-panel-row-preview\"><div class=\"dp-panel-row-preview-nav\"><button class=\"dp-panel-button dp-panel-button-secondary\" type=\"button\" data-dp-panel-preview-prev"+(index<=0?" disabled":"")+">"+dpPanelEscape(dpPanelText("common.previous","Previous"))+"</button><span>"+dpPanelEscape(position)+"</span><button class=\"dp-panel-button dp-panel-button-secondary\" type=\"button\" data-dp-panel-preview-next"+(index>=rows.length-1?" disabled":"")+">"+dpPanelEscape(dpPanelText("common.next","Next"))+"</button></div><div class=\"dp-panel-row-preview-tools\"><button class=\"dp-panel-button dp-panel-button-secondary\" type=\"button\" data-dp-panel-copy-focused-row=\"json\">"+dpPanelEscape(dpPanelText("client.copy_json","Copy JSON"))+"</button><button class=\"dp-panel-button dp-panel-button-secondary\" type=\"button\" data-dp-panel-copy-focused-row=\"csv\">"+dpPanelEscape(dpPanelText("client.copy_csv","Copy CSV"))+"</button></div><dl>";
	fields.forEach(function(field){
		var label=field&&field.nodeType===1 ? (field.getAttribute("data-label")||"Field") : String((field&&field.label)||"Field");
		var value=field&&field.nodeType===1 ? (field.textContent||"").replace(/\s+/g," ").trim() : String((field&&field.value)||"").replace(/\s+/g," ").trim();
		html+="<div><dt>"+dpPanelEscape(label)+"</dt><dd>"+dpPanelEscape(value||"None")+"</dd></div>";
	});
	html+="</dl></div>";
	var wrap=document.createElement("div");
	wrap.innerHTML=html;
	var actions=row.querySelector(".dp-panel-actions");
	if(actions){
		var actionWrap=document.createElement("div");
		actionWrap.className="dp-panel-row-preview-actions";
		actionWrap.innerHTML=actions.innerHTML;
		wrap.appendChild(actionWrap);
	}
	var trigger=document.createElement("button");
	trigger.type="button";
	trigger.textContent=dpPanelText("client.preview_row","Preview row");
	trigger.dataset.dpPanelActionHeading=title||"Record preview";
	trigger.dataset.dpPanelActionDescription="Visible fields and row actions.";
	trigger.dataset.dpPanelActionWidth="lg";
	dpPanelOpenModal(trigger,wrap);
	return true;
}
/**
 * Opens the previous or next row in the active preview sequence.
 *
 * @param {number} direction Relative movement direction.
 * @returns {boolean} Whether another preview was opened.
 */
function dpPanelPreviewAdjacentRow(direction){
	var row=dpPanelPreviewCurrentRow&&document.contains(dpPanelPreviewCurrentRow) ? dpPanelPreviewCurrentRow : dpPanelFocusedTableRow();
	var rows=dpPanelPreviewRows(row);
	if(!row||!rows.length){return false;}
	var index=rows.indexOf(row);
	var next=rows[Math.max(0,Math.min(rows.length-1,index+direction))];
	if(!next||next===row){return false;}
	return dpPanelPreviewTableRow(next);
}
/**
 * Toggles selection for a row's selection checkbox.
 *
 * @param {HTMLTableRowElement|null} row Row to toggle.
 * @returns {boolean} Whether selection changed.
 */
function dpPanelToggleTableRowSelection(row){
	var input=row?row.querySelector("input[name='selected[]']"):null;
	if(!input||input.disabled){return false;}
	input.checked=!input.checked;
	dpPanelUpdateBulkSelection();
	return true;
}
/**
 * Builds the localStorage key for a table's user column widths.
 *
 * @param {HTMLTableElement} table Table element.
 * @returns {string} Column-width storage key scoped to path, heading, and table label.
 */
function dpPanelTableColumnStorageKey(table){
	var label=(table.closest(".dp-panel-table-scroll")||{}).getAttribute ? table.closest(".dp-panel-table-scroll").getAttribute("aria-label")||"" : "";
	var heading=((document.querySelector(".dp-panel h1")||{}).textContent||"").replace(/\s+/g," ").trim();
	return "dataphyre_panel_column_widths|"+location.pathname+"|"+heading+"|"+label;
}
/**
 * Computes a persistent key for a table header column.
 *
 * @param {HTMLTableCellElement} th Header cell.
 * @param {number} index Leaf column index.
 * @returns {string} Stable column key.
 */
function dpPanelTableColumnKey(th,index){
	return (th.dataset.dpPanelColumnKey||(th.textContent||th.getAttribute("aria-label")||"column").replace(/\s+/g," ").trim().toLowerCase()+"|"+index);
}
/**
 * Reads persisted column widths for a table.
 *
 * @param {HTMLTableElement} table Table element.
 * @returns {Object<string, number>} Column width map keyed by header key.
 */
function dpPanelReadColumnWidths(table){
	try{
		var widths=JSON.parse(localStorage.getItem(dpPanelTableColumnStorageKey(table))||"{}");
		return widths&&typeof widths==="object"&&!Array.isArray(widths) ? widths : {};
	}catch(error){
		return {};
	}
}
/**
 * Checks whether a table has persisted user column widths.
 *
 * @param {HTMLTableElement} table Table element.
 * @returns {boolean} Whether any saved width exists.
 */
function dpPanelHasSavedColumnWidths(table){
	return Object.keys(dpPanelReadColumnWidths(table)).length>0;
}
/**
 * Marks a table as user-sized and updates accessibility optimization metadata.
 *
 * User column widths intentionally override automatic accessibility column
 * optimization until widths are cleared.
 *
 * @param {HTMLTableElement} table Table element.
 * @param {Object<string, number>|null} widths Optional width map.
 * @returns {void}
 */
function dpPanelMarkUserColumnWidths(table,widths){
	if(!table||!table.dataset){return;}
	var hasWidths=widths ? Object.keys(widths).length>0 : dpPanelHasSavedColumnWidths(table);
	if(hasWidths){
		table.classList.add("dp-panel-user-column-widths");
		table.classList.remove("dp-panel-a11y-table-optimized","dp-panel-a11y-table-compressed");
		table.dataset.dpPanelUserColumnWidths="1";
		table.dataset.dpPanelA11yTableSkipped="user_column_widths";
		delete table.dataset.dpPanelA11yTableOptimized;
		table.dataset.dpPanelA11yTableCompactColumns="0";
		var colgroup=table.querySelector("colgroup[data-dp-panel-a11y-colgroup='1']");
		var total=colgroup&&colgroup.children ? Array.prototype.slice.call(colgroup.children).reduce(function(sum,col){return sum+(parseInt(col.style.width,10)||0);},0) : Math.round(table.getBoundingClientRect().width||0);
		var scroller=table.closest(".dp-panel-table-scroll");
		var available=scroller ? Math.round(scroller.clientWidth||0) : Math.round(table.parentElement ? table.parentElement.clientWidth||0 : 0);
		if(total>0){table.dataset.dpPanelA11yTableAppliedWidth=String(total);}
		if(!table.dataset.dpPanelA11yTableDesiredWidth&&total>0){table.dataset.dpPanelA11yTableDesiredWidth=String(total);}
		if(available>0){table.dataset.dpPanelA11yTableAvailableWidth=String(available);}
		table.dataset.dpPanelA11yTableScrollPreserved=total>available+2 ? "1" : "0";
	}
	else {
		table.classList.remove("dp-panel-user-column-widths");
		delete table.dataset.dpPanelUserColumnWidths;
		if(table.dataset.dpPanelA11yTableSkipped==="user_column_widths"){
			delete table.dataset.dpPanelA11yTableSkipped;
		}
	}
}
/**
 * Refreshes accessibility policy summaries after table width changes.
 *
 * @param {HTMLTableElement} table Table element.
 * @returns {void}
 */
function dpPanelRefreshTableAccessibilitySummary(table){
	if(!table||typeof dpPanelRefreshAccessibilityPolicySummary!=="function"){return;}
	var panel=table.closest(".dp-panel");
	if(panel){dpPanelRefreshAccessibilityPolicySummary(panel);}
	var container=table.closest("[data-dp-panel-a11y-default='1'],form.dp-panel-form");
	if(container&&container!==panel){dpPanelRefreshAccessibilityPolicySummary(container);}
}
/**
 * Persists user column widths and refreshes dependent table metadata.
 *
 * @param {HTMLTableElement} table Table element.
 * @param {Object<string, number>} widths Width map keyed by header key.
 * @returns {void}
 */
function dpPanelSaveColumnWidths(table,widths){
	widths=widths&&typeof widths==="object" ? widths : {};
	try{localStorage.setItem(dpPanelTableColumnStorageKey(table),JSON.stringify(widths));}catch(error){}
	dpPanelMarkUserColumnWidths(table,widths);
	dpPanelRefreshTableAccessibilitySummary(table);
}
/**
 * Returns leaf header cells for a table, using the accessibility helper when present.
 *
 * @param {HTMLTableElement} table Table element.
 * @returns {HTMLTableCellElement[]} Leaf header cells.
 */
function dpPanelTableLeafHeaders(table){
	if(typeof dpPanelA11yTableHeaderCells==="function"){
		var headers=dpPanelA11yTableHeaderCells(table);
		if(headers.length){return headers;}
	}
	return Array.prototype.slice.call(table.querySelectorAll("thead th"));
}
/**
 * Ensures a resizable colgroup exists for a table.
 *
 * @param {HTMLTableElement} table Table element.
 * @returns {HTMLTableColElement|null} Colgroup element or null when headers are absent.
 */
function dpPanelEnsureTableResizeColgroup(table){
	var existing=table.querySelector("colgroup[data-dp-panel-a11y-colgroup='1']");
	if(existing){return existing;}
	var headers=dpPanelTableLeafHeaders(table);
	if(!headers.length){return null;}
	var colgroup=document.createElement("colgroup");
	colgroup.dataset.dpPanelA11yColgroup="1";
	headers.forEach(function(header,index){
		var rect=header.getBoundingClientRect();
		var width=Math.max(72,Math.round(rect.width||header.offsetWidth||120));
		var node=document.createElement("col");
		node.style.width=width+"px";
		node.dataset.dpPanelA11yColumnKind=header.classList.contains("dp-panel-actions") ? "actions" : (header.classList.contains("dp-panel-select") ? "select" : "text");
		node.dataset.dpPanelA11yColumnDesired=String(width);
		colgroup.appendChild(node);
		dpPanelSetTableColumnCellsWidth(table,index,width);
	});
	table.insertBefore(colgroup,table.firstChild);
	return colgroup;
}
/**
 * Updates a colgroup column width and reconciles stretched trailing columns.
 *
 * @param {HTMLTableElement} table Table element.
 * @param {number} index Leaf column index.
 * @param {number} width Desired column width in pixels.
 * @returns {void}
 */
function dpPanelRefreshTableColumnColgroupWidth(table,index,width){
	var colgroup=dpPanelEnsureTableResizeColgroup(table);
	var col=colgroup&&colgroup.children ? colgroup.children[index] : null;
	if(!col){return;}
	var previous=parseInt(col.style.width,10)||0;
	var delta=Math.round(width)-previous;
	col.style.width=Math.round(width)+"px";
	var lastIndex=colgroup.children.length-1;
	var scroller=table.closest(".dp-panel-table-scroll");
	var available=scroller ? Math.max(0,Math.round(scroller.clientWidth||0)) : 0;
	/**
	 * Measures the current total width of the resize colgroup.
	 *
	 * @returns {number} Sum of column widths in pixels.
	 */
	function colgroupTotal(){
		return Array.prototype.slice.call(colgroup.children).reduce(function(sum,node){
			return sum+(parseInt(node.style.width,10)||0);
		},0);
	}
	if(table.dataset.dpPanelA11yLastColumnStretched==="1"&&index<lastIndex&&delta>0){
		var last=colgroup.children[lastIndex];
		var lastWidth=parseInt(last.style.width,10)||0;
		var lastFloor=parseInt(last.dataset.dpPanelA11yColumnDesired,10)||132;
		var absorbed=Math.min(delta,Math.max(0,lastWidth-lastFloor));
		if(absorbed>0){
			last.style.width=Math.max(lastFloor,lastWidth-absorbed)+"px";
			dpPanelSetTableColumnCellsWidth(table,lastIndex,lastWidth-absorbed);
		}
	}
	else if(table.dataset.dpPanelA11yLastColumnStretched==="1"&&index<lastIndex&&delta<0){
		var growLast=colgroup.children[lastIndex];
		var growWidth=parseInt(growLast.style.width,10)||0;
		var currentTotal=colgroupTotal();
		var refill=available>0 ? Math.max(0,available-currentTotal) : Math.abs(delta);
		if(refill>0){
			growLast.style.width=Math.max(44,growWidth+refill)+"px";
			dpPanelSetTableColumnCellsWidth(table,lastIndex,growWidth+refill);
		}
	}
	var total=colgroupTotal();
	if(total>0){
		table.style.setProperty("min-width",total+"px","important");
		table.style.setProperty("width","100%","important");
		table.dataset.dpPanelA11yTableAppliedWidth=String(total);
	}
}
/**
 * Computes the maximum horizontal scroll offset for a table scroller.
 *
 * @param {Element|null} scroller Table scroll container.
 * @returns {number} Maximum scrollLeft value.
 */
function dpPanelTableColumnScrollMax(scroller){
	if(!scroller){return 0;}
	return Math.max(0,Math.round((scroller.scrollWidth||0)-(scroller.clientWidth||0)));
}
/**
 * Captures horizontal scroll anchoring state before column resize mutation.
 *
 * @param {HTMLTableElement} table Table element.
 * @returns {{scroller: Element, scrollLeft: number, max: number, anchoredRight: boolean}|null} Resize anchor state.
 */
function dpPanelCaptureTableColumnResizeAnchor(table){
	var scroller=table ? table.closest(".dp-panel-table-scroll") : null;
	if(!scroller){return null;}
	var max=dpPanelTableColumnScrollMax(scroller);
	return {
		scroller:scroller,
		scrollLeft:scroller.scrollLeft||0,
		max:max,
		anchoredRight:max<=1||scroller.scrollLeft>=max-2
	};
}
/**
 * Restores horizontal scroll anchoring after column resize mutation.
 *
 * @param {Object<string, *>|null} anchor Resize anchor state.
 * @returns {void}
 */
function dpPanelRestoreTableColumnResizeAnchor(anchor){
	if(!anchor||!anchor.scroller){return;}
	var scroller=anchor.scroller;
	var max=dpPanelTableColumnScrollMax(scroller);
	if(anchor.anchoredRight){
		scroller.scrollLeft=max;
	}
	else if(scroller.scrollLeft>max){
		scroller.scrollLeft=max;
	}
}
/**
 * Applies a pixel width to every single-span cell in a table column.
 *
 * @param {HTMLTableElement} table Table element.
 * @param {number} index Leaf column index.
 * @param {number} width Width in pixels.
 * @returns {void}
 */
function dpPanelSetTableColumnCellsWidth(table,index,width){
	var value=Math.max(44,Math.round(width));
	Array.prototype.slice.call(table.querySelectorAll("tr")).forEach(function(row){
		var cell=row.children[index];
		if(cell&&(cell.colSpan||1)===1){
			cell.style.width=value+"px";
			cell.style.minWidth=Math.min(value,420)+"px";
			cell.dataset.dpPanelA11yColumnWidth=String(value);
		}
	});
}
/**
 * Applies a user column width to cells and colgroup metadata.
 *
 * @param {HTMLTableElement} table Table element.
 * @param {number} index Leaf column index.
 * @param {number} width Width in pixels.
 * @returns {void}
 */
function dpPanelSetTableColumnWidth(table,index,width){
	var value=Math.max(72,Math.round(width));
	dpPanelSetTableColumnCellsWidth(table,index,value);
	dpPanelRefreshTableColumnColgroupWidth(table,index,value);
}
/**
 * Clears user and accessibility column sizing metadata from a table.
 *
 * @param {HTMLTableElement} table Table element.
 * @returns {void}
 */
function dpPanelClearTableColumnSizing(table){
	if(!table){return;}
	table.classList.remove("dp-panel-user-column-widths","dp-panel-a11y-table-optimized","dp-panel-a11y-table-compressed","dp-panel-a11y-table-scroll-preserved");
	table.style.removeProperty("min-width");
	table.style.removeProperty("width");
	Array.from(table.querySelectorAll("colgroup[data-dp-panel-a11y-colgroup='1']")).forEach(function(colgroup){colgroup.remove();});
	Array.from(table.querySelectorAll("[data-dp-panel-a11y-column-width],[data-dp-panel-a11y-column-optimized]")).forEach(function(cell){
		cell.style.removeProperty("width");
		cell.style.removeProperty("min-width");
		delete cell.dataset.dpPanelA11yColumnWidth;
		delete cell.dataset.dpPanelA11yColumnOptimized;
		delete cell.dataset.dpPanelA11yColumnKind;
		cell.classList.remove("dp-panel-a11y-column-compact","dp-panel-a11y-column-actions","dp-panel-a11y-column-select","dp-panel-a11y-column-numeric","dp-panel-a11y-column-status");
	});
	delete table.dataset.dpPanelA11yTableOptimized;
	delete table.dataset.dpPanelA11yTableColumnCount;
	delete table.dataset.dpPanelA11yTableAvailableWidth;
	delete table.dataset.dpPanelA11yTableDesiredWidth;
	delete table.dataset.dpPanelA11yTableAppliedWidth;
	delete table.dataset.dpPanelA11yTableCompactColumns;
	delete table.dataset.dpPanelA11yTableScrollPreserved;
	delete table.dataset.dpPanelA11yPreservedColumns;
	delete table.dataset.dpPanelA11yLastColumnStretched;
	if(table.dataset.dpPanelA11yTableSkipped==="user_column_widths"){
		delete table.dataset.dpPanelA11yTableSkipped;
	}
}
/**
 * Applies persisted user column widths to a table.
 *
 * @param {HTMLTableElement} table Table element.
 * @returns {void}
 */
function dpPanelApplyColumnWidths(table){
	var widths=dpPanelReadColumnWidths(table);
	dpPanelMarkUserColumnWidths(table,widths);
	dpPanelTableLeafHeaders(table).forEach(function(th,index){
		var key=dpPanelTableColumnKey(th,index);
		if(widths[key]){
			dpPanelSetTableColumnWidth(table,index,widths[key]);
		}
	});
}
/**
 * Resets one persisted column width and restores automatic sizing as needed.
 *
 * @param {HTMLTableElement} table Table element.
 * @param {number} index Leaf column index.
 * @returns {void}
 */
function dpPanelResetTableColumnWidth(table,index){
	var th=dpPanelTableLeafHeaders(table)[index];
	if(!th){return;}
	var widths=dpPanelReadColumnWidths(table);
	delete widths[dpPanelTableColumnKey(th,index)];
	dpPanelSaveColumnWidths(table,widths);
	dpPanelClearTableColumnSizing(table);
	if(Object.keys(widths).length){
		dpPanelApplyColumnWidths(table);
	}
	else if(typeof dpPanelA11yOptimizeTableColumns==="function"){
		dpPanelA11yOptimizeTableColumns(table.closest(".dp-panel")||document);
	}
	dpPanelRefreshTableScroll();
	dpPanelRefreshTableAccessibilitySummary(table);
	dpPanelToast(dpPanelText("client.column_width_reset","Column width reset"),"success");
}
/**
 * Initializes pointer-driven column resizing for all Panel tables.
 *
 * Resizers are skipped for selection, action, and stretched trailing columns.
 * User widths are persisted in localStorage and re-applied before resize handles
 * are attached.
 *
 * @returns {void}
 */
function dpPanelInitColumnResizing(){
	document.querySelectorAll(".dp-panel-table").forEach(function(table){
		dpPanelApplyColumnWidths(table);
		var leafHeaders=dpPanelTableLeafHeaders(table);
		leafHeaders.forEach(function(th,index){
			var skip=th.classList.contains("dp-panel-select")||th.classList.contains("dp-panel-actions")||(table.dataset.dpPanelA11yLastColumnStretched==="1"&&index>=leafHeaders.length-2);
			if(skip){
				var existing=th.querySelector(".dp-panel-column-resizer");
				if(existing){existing.remove();}
				delete th.dataset.dpPanelColumnResizeReady;
				return;
			}
			if(th.dataset.dpPanelColumnResizeReady==="1"){return;}
			th.dataset.dpPanelColumnResizeReady="1";
			var grip=document.createElement("span");
			grip.className="dp-panel-column-resizer";
			grip.setAttribute("role","separator");
			grip.setAttribute("aria-orientation","vertical");
			grip.setAttribute("title",dpPanelText("client.column_resize_hint","Drag to resize. Double-click to reset."));
			th.appendChild(grip);
			grip.addEventListener("dblclick",function(event){
				event.preventDefault();
				event.stopPropagation();
				dpPanelResetTableColumnWidth(table,index);
			});
			grip.addEventListener("pointerdown",function(event){
				if(event.button!==0){return;}
				event.preventDefault();
				event.stopPropagation();
				var widths=dpPanelReadColumnWidths(table);
				var key=dpPanelTableColumnKey(th,index);
				var startX=event.clientX;
				var startWidth=th.getBoundingClientRect().width;
				document.body.classList.add("dp-panel-column-resizing");
				/**
				 * Applies an in-progress pointer resize to the active column.
				 *
				 * @param {PointerEvent} moveEvent Pointer move event.
				 * @returns {void}
				 */
				function move(moveEvent){
					var next=Math.max(88,startWidth+(moveEvent.clientX-startX));
					var anchor=dpPanelCaptureTableColumnResizeAnchor(table);
					widths[key]=Math.round(next);
					dpPanelSetTableColumnWidth(table,index,next);
					dpPanelRestoreTableColumnResizeAnchor(anchor);
					dpPanelRefreshTableScroll();
				}
				/**
				 * Finalizes a pointer resize and persists the new column width map.
				 *
				 * @returns {void}
				 */
				function up(){
					document.removeEventListener("pointermove",move);
					document.removeEventListener("pointerup",up);
					document.body.classList.remove("dp-panel-column-resizing");
					dpPanelRestoreTableColumnResizeAnchor(dpPanelCaptureTableColumnResizeAnchor(table));
					dpPanelSaveColumnWidths(table,widths);
					dpPanelRefreshTableScroll();
				}
				dpPanelListen(document,"pointermove",move);
				dpPanelListen(document,"pointerup",up,{once:true});
			});
		});
	});
}
/**
 * Refreshes scroll affordance classes for table shells.
 *
 * @returns {void}
 */
function dpPanelRefreshTableScroll(){
	document.querySelectorAll("[data-dp-panel-table-shell]").forEach(function(shell){
		var scroller=shell.querySelector(".dp-panel-table-scroll");
		if(!scroller){return;}
		var maxScroll=Math.max(0,scroller.scrollWidth-scroller.clientWidth-1);
		shell.classList.toggle("dp-panel-table-can-scroll-left",scroller.scrollLeft>1);
		shell.classList.toggle("dp-panel-table-can-scroll-right",scroller.scrollLeft<maxScroll);
		if(scroller.dataset.dpPanelScrollReady!=="1"){
			scroller.dataset.dpPanelScrollReady="1";
			scroller.addEventListener("scroll",dpPanelRefreshTableScroll,{passive:true});
		}
	});
}
/**
 * Refreshes visible column-picker options and selected-count UI.
 *
 * @param {Element|null} picker Column picker root.
 * @returns {void}
 */
function dpPanelRefreshColumnPicker(picker){
	if(!picker){return;}
	var query=((picker.querySelector("[data-dp-panel-column-search]")||{}).value||"").toLowerCase().trim();
	var options=Array.prototype.slice.call(picker.querySelectorAll("[data-dp-panel-column-option]"));
	var visible=0;
	var checked=0;
	options.forEach(function(option){
		var text=(option.textContent||"").toLowerCase();
		var match=!query||text.indexOf(query)!==-1;
		option.hidden=!match;
		if(match){visible++;}
		var input=option.querySelector("input[type='checkbox']");
		if(input&&input.checked){checked++;}
	});
	var count=picker.querySelector("[data-dp-panel-column-count]");
	if(count){count.textContent=checked+"/"+options.length;}
	picker.classList.toggle("dp-panel-column-picker-empty",visible===0);
}
/**
 * Initializes column picker search and bulk select controls.
 *
 * @returns {void}
 */
function dpPanelInitColumnPickers(){
	document.querySelectorAll("[data-dp-panel-column-picker]").forEach(function(picker){
		dpPanelRefreshColumnPicker(picker);
		if(picker.dataset.dpPanelColumnPickerReady==="1"){return;}
		picker.dataset.dpPanelColumnPickerReady="1";
		picker.addEventListener("input",function(event){
			if(event.target.matches("[data-dp-panel-column-search],input[name='visible_columns[]']")){
				dpPanelRefreshColumnPicker(picker);
			}
		});
		picker.addEventListener("click",function(event){
			var action=event.target.closest("[data-dp-panel-columns-select]");
			if(!action){return;}
			var checked=action.dataset.dpPanelColumnsSelect==="all";
			picker.querySelectorAll("[data-dp-panel-column-option]:not([hidden]) input[name='visible_columns[]']").forEach(function(input){
				input.checked=checked;
			});
			dpPanelRefreshColumnPicker(picker);
		});
	});
}
/**
 * Builds the localStorage key for pinned navigation in the current workspace.
 *
 * @returns {string} Pinned navigation storage key.
 */
function dpPanelPinnedNavStorageKey(){
	return "dataphyre_panel_pinned_navigation|"+location.pathname.split("/").slice(0,2).join("/");
}
/**
 * Loads pinned navigation items when pinning is enabled.
 *
 * @returns {Array<{label: string, description: string, icon: string, href: string}>} Pinned navigation items.
 */
function dpPanelPinnedNavItems(){
	if(!dpPanelNavigationFeatureEnabled("pinning")){return [];}
	try{
		var items=JSON.parse(localStorage.getItem(dpPanelPinnedNavStorageKey())||"[]");
		if(!Array.isArray(items)){return [];}
		return items.filter(function(item){return item&&item.href&&item.label;}).slice(0,12);
	}catch(error){
		return [];
	}
}
/**
 * Normalizes a navigation URL by removing transient table and theme parameters.
 *
 * @param {string} href Navigation URL.
 * @returns {string} Stable path and query identity.
 */
function dpPanelNavStableHref(href){
	var url=new URL(href,location.href);
	["page","per_page","sort","dir","density","format",dpPanelThemePresetParameter(),"preset"].forEach(function(name){if(name){url.searchParams.delete(name);}});
	return url.pathname+(url.searchParams.toString() ? "?"+url.searchParams.toString() : "");
}
/**
 * Builds a de-duplication key for pinned or recent navigation items.
 *
 * @param {Object<string, *>} item Navigation item.
 * @returns {string} Stable item key.
 */
function dpPanelNavItemKey(item){
	return dpPanelNavStableHref(item.href)+"|"+String(item.label||"").toLowerCase();
}
/**
 * Extracts navigation item metadata from a sidebar link.
 *
 * @param {HTMLAnchorElement} link Sidebar link.
 * @returns {{label: string, description: string, icon: string, href: string}} Navigation item.
 */
function dpPanelNavItemFromLink(link){
	var label=((link.querySelector(".dp-panel-sidebar-copy strong")||{}).textContent||link.textContent||"").replace(/\s+/g," ").trim();
	var description=((link.querySelector(".dp-panel-sidebar-copy small")||{}).textContent||"").replace(/\s+/g," ").trim();
	var icon=((link.querySelector(".dp-panel-sidebar-icon")||{}).textContent||"").replace(/\s+/g," ").trim();
	return {label:label, description:description, icon:icon, href:dpPanelThemeNeutralHref(link.href)};
}
/**
 * Persists the pinned navigation list.
 *
 * @param {Array<Object<string, *>>} items Pinned navigation items.
 * @returns {void}
 */
function dpPanelSavePinnedNavItems(items){
	if(!dpPanelNavigationFeatureEnabled("pinning")){return;}
	try{localStorage.setItem(dpPanelPinnedNavStorageKey(),JSON.stringify(items.slice(0,12)));}catch(error){}
}
/**
 * Clears pinned navigation for the current workspace.
 *
 * @returns {void}
 */
function dpPanelClearPinnedNavigation(){
	if(!dpPanelNavigationFeatureEnabled("pinning")){return;}
	try{localStorage.removeItem(dpPanelPinnedNavStorageKey());}catch(error){}
	dpPanelRefreshPinnedNavigation();
	dpPanelToast(dpPanelText("nav.pinned_cleared","Pinned navigation cleared"),"success");
}
/**
 * Checks whether a navigation item is already pinned.
 *
 * @param {Object<string, *>} item Navigation item.
 * @returns {boolean} Whether an equivalent pinned item exists.
 */
function dpPanelPinnedNavHas(item){
	var key=dpPanelNavItemKey(item);
	return dpPanelPinnedNavItems().some(function(existing){return dpPanelNavItemKey(existing)===key;});
}
/**
 * Pins or unpins a sidebar navigation link.
 *
 * @param {HTMLAnchorElement} link Sidebar link.
 * @returns {void}
 */
function dpPanelTogglePinnedNav(link){
	if(!dpPanelNavigationFeatureEnabled("pinning")){return;}
	var item=dpPanelNavItemFromLink(link);
	if(!item.href||!item.label){return;}
	var key=dpPanelNavItemKey(item);
	var items=dpPanelPinnedNavItems();
	var next=items.filter(function(existing){return dpPanelNavItemKey(existing)!==key;});
	var pinned=next.length===items.length;
	if(pinned){next.unshift(item);}
	dpPanelSavePinnedNavItems(next);
	dpPanelRefreshPinnedNavigation();
	dpPanelToast(pinned ? dpPanelText("client.navigation_pinned","Navigation pinned") : dpPanelText("client.navigation_unpinned","Navigation unpinned"), "success");
}
/**
 * Returns the active sidebar link eligible for pinning commands.
 *
 * @returns {HTMLAnchorElement|null} Active or fallback sidebar link.
 */
function dpPanelActiveSidebarLink(){
	return document.querySelector(".dp-panel-sidebar-nav .dp-panel-sidebar-link.active:not(.dp-panel-sidebar-link-pinned)")||document.querySelector(".dp-panel-sidebar-nav .dp-panel-sidebar-link:not(.dp-panel-sidebar-link-pinned)");
}
/**
 * Pins the active sidebar navigation item.
 *
 * @returns {void}
 */
function dpPanelPinActiveNavigation(){
	if(!dpPanelNavigationFeatureEnabled("pinning")){return;}
	var link=dpPanelActiveSidebarLink();
	if(!link){dpPanelToast(dpPanelText("client.no_active_navigation","No navigation item is active"),"warning");return;}
	if(!dpPanelPinnedNavHas(dpPanelNavItemFromLink(link))){
		dpPanelTogglePinnedNav(link);
		return;
	}
	dpPanelToast(dpPanelText("client.navigation_already_pinned","Navigation is already pinned"),"info");
}
/**
 * Unpins the active sidebar navigation item.
 *
 * @returns {void}
 */
function dpPanelUnpinActiveNavigation(){
	if(!dpPanelNavigationFeatureEnabled("pinning")){return;}
	var link=dpPanelActiveSidebarLink();
	if(!link){dpPanelToast(dpPanelText("client.no_active_navigation","No navigation item is active"),"warning");return;}
	if(dpPanelPinnedNavHas(dpPanelNavItemFromLink(link))){
		dpPanelTogglePinnedNav(link);
		return;
	}
	dpPanelToast(dpPanelText("client.navigation_not_pinned","Navigation is not pinned"),"info");
}
/**
 * Renders a pinned navigation item as sidebar link HTML.
 *
 * @param {Object<string, *>} item Pinned navigation item.
 * @returns {string} Escaped sidebar link HTML.
 */
function dpPanelPinnedNavLinkHtml(item){
	var current=dpPanelNavStableHref(item.href)===dpPanelNavStableHref(location.href);
	return "<a class=\"dp-panel-sidebar-link dp-panel-sidebar-link-pinned"+(current?" active":"")+"\" href=\""+dpPanelEscape(dpPanelHrefForCurrentTheme(item.href))+"\""+(current?" aria-current=\"page\"":"")+"><span class=\"dp-panel-sidebar-icon\" aria-hidden=\"true\">"+dpPanelEscape(item.icon||"PI")+"</span><span class=\"dp-panel-sidebar-copy\"><strong>"+dpPanelEscape(item.label)+"</strong>"+(item.description?"<small>"+dpPanelEscape(item.description)+"</small>":"")+"</span></a>";
}
/**
 * Returns recent navigation items not already active or pinned.
 *
 * @returns {Array<Object<string, *>>} Recent sidebar items.
 */
function dpPanelRecentNavItems(){
	if(!dpPanelNavigationFeatureEnabled("recent")){return [];}
	var pinned=dpPanelPinnedNavItems().map(dpPanelNavItemKey);
	var current=dpPanelNavStableHref(location.href);
	return dpPanelRecentItems().filter(function(item){
		if(!item||!item.href||!item.label){return false;}
		if(dpPanelNavStableHref(item.href)===current){return false;}
		return pinned.indexOf(dpPanelNavItemKey(item))===-1;
	}).slice(0,5);
}
/**
 * Renders a recent navigation item as sidebar link HTML.
 *
 * @param {Object<string, *>} item Recent navigation item.
 * @returns {string} Escaped sidebar link HTML.
 */
function dpPanelRecentNavLinkHtml(item){
	return "<a class=\"dp-panel-sidebar-link dp-panel-sidebar-link-recent\" href=\""+dpPanelEscape(dpPanelHrefForCurrentTheme(item.href))+"\"><span class=\"dp-panel-sidebar-icon\" aria-hidden=\"true\">RE</span><span class=\"dp-panel-sidebar-copy\"><strong>"+dpPanelEscape(item.label)+"</strong><small>Recently opened</small></span></a>";
}
/**
 * Clears recent navigation storage and refreshes the sidebar.
 *
 * @returns {void}
 */
function dpPanelClearRecentNavigation(){
	if(!dpPanelNavigationFeatureEnabled("recent")){return;}
	try{
		localStorage.removeItem(dpPanelRecentStorageKey());
		localStorage.removeItem(dpPanelLegacyRecentStorageKey());
	}catch(error){}
	dpPanelRefreshRecentNavigation();
	dpPanelToast(dpPanelText("client.recent_navigation_cleared","Recent navigation cleared"),"success");
}
/**
 * Rebuilds the generated recent navigation sidebar section.
 *
 * @returns {void}
 */
function dpPanelRefreshRecentNavigation(){
	var nav=document.querySelector(".dp-panel-sidebar-nav");
	if(!nav){return;}
	nav.querySelectorAll("[data-dp-panel-recent-nav]").forEach(function(section){section.remove();});
	if(!dpPanelNavigationFeatureEnabled("recent")){
		dpPanelRefreshSidebarSearch();
		return;
	}
	var items=dpPanelRecentNavItems();
	if(!items.length){
		dpPanelRefreshSidebarSearch();
		return;
	}
	var section=document.createElement("section");
	section.className="dp-panel-sidebar-group dp-panel-sidebar-recent";
	section.dataset.dpPanelRecentNav="1";
	section.innerHTML="<h2>"+dpPanelEscape(dpPanelText("client.recent","Recent"))+"</h2>"+items.map(dpPanelRecentNavLinkHtml).join("");
	var pinned=nav.querySelector("[data-dp-panel-pinned-nav]");
	if(pinned&&pinned.nextSibling){nav.insertBefore(section,pinned.nextSibling);}
	else if(pinned){nav.appendChild(section);}
	else {
		var firstGroup=nav.querySelector(".dp-panel-sidebar-group");
		if(firstGroup){nav.insertBefore(section,firstGroup);}
		else {nav.appendChild(section);}
	}
	dpPanelPrepareSidebarGroups();
	dpPanelRefreshSidebarSearch();
}
/**
 * Applies sidebar search filtering and search result counts.
 *
 * @returns {void}
 */
function dpPanelRefreshSidebarSearch(){
	var sidebar=document.querySelector("[data-dp-panel-sidebar]");
	if(!sidebar){return;}
	var input=sidebar.querySelector("[data-dp-panel-sidebar-search]");
	var query=(input&&input.value||"").toLowerCase().replace(/\s+/g," ").trim();
	var total=0;
	var visible=0;
	sidebar.querySelectorAll(".dp-panel-sidebar-link").forEach(function(link){
		if(link.closest("[data-dp-panel-pinned-nav]")){return;}
		total++;
		var text=(link.textContent||"").toLowerCase().replace(/\s+/g," ").trim();
		var wrapper=link.closest(".dp-panel-sidebar-item");
		var match=!query||text.indexOf(query)!==-1;
		if(wrapper){wrapper.hidden=!match;}
		else {link.hidden=!match;}
		if(match){visible++;}
	});
	sidebar.querySelectorAll(".dp-panel-sidebar-submenu").forEach(function(menu){
		var label=(menu.querySelector(":scope > summary")||menu).textContent.toLowerCase().replace(/\s+/g," ").trim();
		var childMatch=Array.prototype.slice.call(menu.querySelectorAll(".dp-panel-sidebar-link,.dp-panel-sidebar-submenu")).some(function(item){
			return item!==menu && !item.hidden && !item.closest(".dp-panel-sidebar-item[hidden]");
		});
		var directMatch=query!==""&&label.indexOf(query)!==-1;
		menu.hidden=query!==""&&!childMatch&&!directMatch;
		if(query!==""&&(childMatch||directMatch)){
			menu.open=true;
		}
	});
	sidebar.querySelectorAll(".dp-panel-sidebar-group:not([data-dp-panel-pinned-nav])").forEach(function(group){
		var hasVisible=Array.prototype.slice.call(group.querySelectorAll(".dp-panel-sidebar-link,.dp-panel-sidebar-item")).some(function(item){return !item.hidden;});
		group.hidden=query!==""&&!hasVisible;
	});
	var count=sidebar.querySelector("[data-dp-panel-sidebar-search-count]");
	if(count){
		count.textContent=query==="" ? "" : visible+"/"+total;
		count.hidden=query==="";
	}
	sidebar.classList.toggle("dp-panel-sidebar-searching",query!=="");
	sidebar.classList.toggle("dp-panel-sidebar-search-empty",query!==""&&visible===0);
}
/**
 * Returns visible sidebar links in keyboard navigation order.
 *
 * @returns {HTMLAnchorElement[]} Visible sidebar links.
 */
function dpPanelVisibleSidebarLinks(){
	var sidebar=document.querySelector("[data-dp-panel-sidebar]");
	if(!sidebar){return [];}
	return Array.prototype.slice.call(sidebar.querySelectorAll(".dp-panel-sidebar-link")).filter(function(link){
		var wrapper=link.closest(".dp-panel-sidebar-item");
		var group=link.closest(".dp-panel-sidebar-group");
		return !link.hidden&&(!wrapper||!wrapper.hidden)&&(!group||!group.hidden)&&dpPanelCommandElementVisible(link);
	});
}
/**
 * Focuses a sidebar navigation link and scrolls it into view.
 *
 * @param {HTMLAnchorElement|null} link Sidebar link.
 * @returns {boolean} Whether focus moved.
 */
function dpPanelFocusSidebarLink(link){
	if(!link){return false;}
	link.focus();
	if(typeof link.scrollIntoView==="function"){link.scrollIntoView({block:"nearest",inline:"nearest"});}
	return true;
}
/**
 * Moves sidebar focus through visible links.
 *
 * @param {HTMLAnchorElement} current Current sidebar link.
 * @param {number} delta Relative movement amount.
 * @returns {boolean} Whether focus moved.
 */
function dpPanelMoveSidebarFocus(current,delta){
	var links=dpPanelVisibleSidebarLinks();
	if(!links.length){return false;}
	var index=links.indexOf(current);
	if(index===-1){index=delta>0?-1:0;}
	var next=links[(index+delta+links.length)%links.length];
	return dpPanelFocusSidebarLink(next);
}
/**
 * Activates the first visible sidebar search match.
 *
 * @returns {boolean} Whether a match was opened.
 */
function dpPanelSidebarOpenFirstMatch(){
	var first=dpPanelVisibleSidebarLinks()[0];
	if(!first){dpPanelToast(dpPanelText("client.no_navigation_matches","No navigation matches"),"warning");return false;}
	first.click();
	return true;
}
/**
 * Handles keyboard navigation for sidebar search and links.
 *
 * @param {KeyboardEvent} event Sidebar keydown event.
 * @returns {boolean} Whether the event was handled.
 */
function dpPanelHandleSidebarKeyboard(event){
	var target=event.target&&event.target.closest ? event.target : null;
	if(!target){return false;}
	var search=target.closest("[data-dp-panel-sidebar-search]");
	var link=target.closest(".dp-panel-sidebar-link");
	if(search){
		if(event.key==="Escape"){
			if(search.value!==""){
				event.preventDefault();
				search.value="";
				dpPanelRefreshSidebarSearch();
				return true;
			}
			return false;
		}
		if(event.key==="Enter"){
			event.preventDefault();
			return dpPanelSidebarOpenFirstMatch();
		}
		if(event.key==="ArrowDown"){
			event.preventDefault();
			return dpPanelFocusSidebarLink(dpPanelVisibleSidebarLinks()[0]);
		}
		if(event.key==="ArrowUp"){
			var links=dpPanelVisibleSidebarLinks();
			event.preventDefault();
			return dpPanelFocusSidebarLink(links[links.length-1]);
		}
		return false;
	}
	if(link&&link.closest("[data-dp-panel-sidebar]")){
		if(event.key==="ArrowDown"){
			event.preventDefault();
			return dpPanelMoveSidebarFocus(link,1);
		}
		if(event.key==="ArrowUp"){
			event.preventDefault();
			return dpPanelMoveSidebarFocus(link,-1);
		}
		if(event.key==="Home"){
			event.preventDefault();
			return dpPanelFocusSidebarLink(dpPanelVisibleSidebarLinks()[0]);
		}
		if(event.key==="End"){
			var visible=dpPanelVisibleSidebarLinks();
			event.preventDefault();
			return dpPanelFocusSidebarLink(visible[visible.length-1]);
		}
	}
	return false;
}
/**
 * Builds the localStorage key for collapsed sidebar groups.
 *
 * @returns {string} Sidebar group storage key.
 */
function dpPanelSidebarGroupStorageKey(){
	return "dataphyre_panel_sidebar_groups|"+location.pathname.split("/").slice(0,2).join("/");
}
var dpPanelSidebarActiveGroupManualCollapse={};
/**
 * Loads collapsed sidebar groups, defaulting inactive groups to collapsed.
 *
 * @returns {string[]} Collapsed group names.
 */
function dpPanelSidebarCollapsedGroups(){
	try{
		var stored=localStorage.getItem(dpPanelSidebarGroupStorageKey());
		if(stored!==null){
			var state=JSON.parse(stored||"{}");
			if(state&&Array.isArray(state.collapsed)){
				var collapsed=state.collapsed.map(String);
				var activeNames=Array.prototype.slice.call(document.querySelectorAll(".dp-panel-sidebar-group.active")).map(dpPanelSidebarGroupName).filter(Boolean);
				collapsed=collapsed.filter(function(name){return activeNames.indexOf(name)===-1||dpPanelSidebarActiveGroupManualCollapse[name]===true;});
				return collapsed;
			}
			if(state&&Array.isArray(state.expanded)){
				var expanded=state.expanded.map(String);
				return Array.prototype.slice.call(document.querySelectorAll(".dp-panel-sidebar-group:not(.active)")).map(dpPanelSidebarGroupName).filter(function(name){return name&&expanded.indexOf(name)===-1;});
			}
		}
		return Array.prototype.slice.call(document.querySelectorAll(".dp-panel-sidebar-group:not(.active)")).map(dpPanelSidebarGroupName).filter(Boolean);
	}catch(error){
		return Array.prototype.slice.call(document.querySelectorAll(".dp-panel-sidebar-group:not(.active)")).map(dpPanelSidebarGroupName).filter(Boolean);
	}
}
/**
 * Resolves the persistent name for a sidebar group.
 *
 * @param {Element} group Sidebar group element.
 * @returns {string} Group name.
 */
function dpPanelSidebarGroupName(group){
	return (group.dataset.dpPanelSidebarGroup||((group.querySelector("[data-dp-panel-group-label]")||{}).dataset||{}).dpPanelGroupLabel||((group.querySelector("h2 button span")||group.querySelector("h2")||{}).textContent||"")).replace(/\s+/g," ").trim();
}
/**
 * Persists the collapsed sidebar group set.
 *
 * @param {string[]} groups Collapsed group names.
 * @returns {void}
 */
function dpPanelSetSidebarCollapsedGroups(groups){
	var collapsed=Array.from(new Set(groups)).slice(0,80);
	try{localStorage.setItem(dpPanelSidebarGroupStorageKey(),JSON.stringify({collapsed:collapsed,path:location.pathname}));}catch(error){}
}
/**
 * Sets one sidebar group's collapsed state and reapplies group UI.
 *
 * @param {Element} group Sidebar group.
 * @param {boolean} collapsed Desired collapsed state.
 * @returns {void}
 */
function dpPanelSetSidebarGroupCollapsed(group,collapsed){
	var name=dpPanelSidebarGroupName(group);
	if(!name){return;}
	var groups=dpPanelSidebarCollapsedGroups().filter(function(item){return item!==name;});
	if(!collapsed&&dpPanelNavigationFeatureEnabled("collapse_exclusive")){
		groups=Array.prototype.slice.call(document.querySelectorAll(".dp-panel-sidebar-group")).filter(function(item){return item!==group;}).map(dpPanelSidebarGroupName).filter(Boolean);
	}
	if(group.classList.contains("active")){
		if(collapsed){
			dpPanelSidebarActiveGroupManualCollapse[name]=true;
		}else{
			delete dpPanelSidebarActiveGroupManualCollapse[name];
		}
	}
	if(collapsed){groups.push(name);}
	dpPanelSetSidebarCollapsedGroups(groups);
	dpPanelApplySidebarGroupState();
}
/**
 * Applies persisted sidebar group collapsed state to the DOM.
 *
 * @returns {void}
 */
function dpPanelApplySidebarGroupState(){
	var collapsed=dpPanelSidebarCollapsedGroups();
	document.querySelectorAll(".dp-panel-sidebar-group").forEach(function(group){
		var name=dpPanelSidebarGroupName(group);
		var isCollapsed=name!==""&&collapsed.indexOf(name)!==-1;
		group.classList.toggle("dp-panel-sidebar-group-collapsed",isCollapsed);
		var button=group.querySelector("[data-dp-panel-sidebar-group-toggle]");
		if(button){
			button.setAttribute("aria-expanded",isCollapsed?"false":"true");
			button.title=isCollapsed ? "Expand "+name : "Collapse "+name;
		}
	});
}
/**
 * Prepares sidebar group headings as collapsible controls when enabled.
 *
 * @returns {void}
 */
function dpPanelPrepareSidebarGroups(){
	if(!dpPanelNavigationFeatureEnabled("collapse")){
		document.querySelectorAll(".dp-panel-sidebar-group h2").forEach(function(heading){
			if(!heading||heading.dataset.dpPanelGroupReady==="1"){return;}
			heading.dataset.dpPanelGroupReady="1";
			if(heading.querySelector("[data-dp-panel-sidebar-group-link]")){return;}
			var label=heading.dataset.dpPanelGroupLabel||heading.textContent.replace(/\s+/g," ").trim();
			heading.textContent=label;
		});
		return;
	}
	document.querySelectorAll(".dp-panel-sidebar-group").forEach(function(group){
		var heading=group.querySelector("h2");
		if(!heading||heading.dataset.dpPanelGroupReady==="1"){return;}
		heading.dataset.dpPanelGroupReady="1";
		var label=heading.textContent.replace(/\s+/g," ").trim();
		label=heading.dataset.dpPanelGroupLabel||label;
		var count=((heading.querySelector(":scope > span")||{}).textContent||"").replace(/\s+/g," ").trim();
		var button=document.createElement("button");
		button.type="button";
		button.dataset.dpPanelSidebarGroupToggle="1";
		button.setAttribute("aria-label","Toggle "+label);
		button.innerHTML="<span>"+dpPanelEscape(label)+"</span>"+(count?"<b>"+dpPanelEscape(count)+"</b>":"")+"<i aria-hidden=\"true\"></i>";
		heading.textContent="";
		heading.appendChild(button);
		button.addEventListener("click",function(event){
			event.preventDefault();
			dpPanelSetSidebarGroupCollapsed(group,!group.classList.contains("dp-panel-sidebar-group-collapsed"));
		});
	});
	dpPanelApplySidebarGroupState();
}
/**
 * Expands all sidebar groups and persists that state.
 *
 * @returns {void}
 */
function dpPanelExpandAllSidebarGroups(){
	dpPanelSetSidebarCollapsedGroups([]);
	dpPanelApplySidebarGroupState();
	dpPanelToast(dpPanelText("client.navigation_groups_expanded","Navigation groups expanded"),"success");
}
/**
 * Collapses all sidebar groups and persists that state.
 *
 * @returns {void}
 */
function dpPanelCollapseAllSidebarGroups(){
	var names=Array.prototype.slice.call(document.querySelectorAll(".dp-panel-sidebar-group")).map(dpPanelSidebarGroupName).filter(Boolean);
	dpPanelSetSidebarCollapsedGroups(names);
	dpPanelApplySidebarGroupState();
	dpPanelToast(dpPanelText("client.navigation_groups_collapsed","Navigation groups collapsed"),"success");
}
/**
 * Adds pin buttons beside sidebar links when pinning is enabled.
 *
 * @returns {void}
 */
function dpPanelPrepareSidebarPinControls(){
	if(!dpPanelNavigationFeatureEnabled("pinning")){
		document.querySelectorAll("[data-dp-panel-pin-nav]").forEach(function(button){button.remove();});
		return;
	}
	document.querySelectorAll(".dp-panel-sidebar-nav > .dp-panel-sidebar-link:not(.dp-panel-sidebar-link-pinned):not(.dp-panel-sidebar-link-recent),.dp-panel-sidebar-group > .dp-panel-sidebar-link:not(.dp-panel-sidebar-link-pinned):not(.dp-panel-sidebar-link-recent),.dp-panel-sidebar-group > .dp-panel-sidebar-item > .dp-panel-sidebar-link:not(.dp-panel-sidebar-link-pinned):not(.dp-panel-sidebar-link-recent)").forEach(function(link){
		if(link.dataset.dpPanelPinReady==="1"){return;}
		link.dataset.dpPanelPinReady="1";
		var wrapper=document.createElement("div");
		wrapper.className="dp-panel-sidebar-item";
		link.parentNode.insertBefore(wrapper,link);
		wrapper.appendChild(link);
		var button=document.createElement("button");
		button.type="button";
		button.className="dp-panel-sidebar-pin";
		button.dataset.dpPanelPinNav="1";
		button.setAttribute("aria-label","Pin navigation item");
		button.title="Pin navigation item";
		button.textContent=dpPanelText("client.pin","Pin");
		wrapper.appendChild(button);
		button.addEventListener("click",function(event){
			event.preventDefault();
			event.stopPropagation();
			dpPanelTogglePinnedNav(link);
		});
	});
}
/**
 * Rebuilds pinned navigation and synchronizes pin button pressed state.
 *
 * @returns {void}
 */
function dpPanelRefreshPinnedNavigation(){
	var nav=document.querySelector(".dp-panel-sidebar-nav");
	if(!nav){return;}
	if(!dpPanelNavigationFeatureEnabled("pinning")){
		nav.querySelectorAll("[data-dp-panel-pinned-nav]").forEach(function(section){section.remove();});
		document.querySelectorAll("[data-dp-panel-pin-nav]").forEach(function(button){button.remove();});
		dpPanelRefreshRecentNavigation();
		dpPanelRefreshSidebarSearch();
		return;
	}
	dpPanelPrepareSidebarPinControls();
	nav.querySelectorAll("[data-dp-panel-pinned-nav]").forEach(function(section){section.remove();});
	nav.querySelectorAll("[data-dp-panel-recent-nav]").forEach(function(section){section.remove();});
	var items=dpPanelPinnedNavItems();
	document.querySelectorAll(".dp-panel-sidebar-item").forEach(function(wrapper){
		var link=wrapper.querySelector(".dp-panel-sidebar-link");
		var button=wrapper.querySelector("[data-dp-panel-pin-nav]");
		if(!link||!button){return;}
		var pinned=dpPanelPinnedNavHas(dpPanelNavItemFromLink(link));
		wrapper.classList.toggle("dp-panel-sidebar-item-pinned",pinned);
		button.setAttribute("aria-pressed",pinned?"true":"false");
		button.title=pinned ? "Unpin navigation item" : "Pin navigation item";
		button.textContent=pinned ? dpPanelText("client.pinned","Pinned") : dpPanelText("client.pin","Pin");
	});
	if(!items.length){
		dpPanelRefreshRecentNavigation();
		dpPanelRefreshSidebarSearch();
		return;
	}
	var section=document.createElement("section");
	section.className="dp-panel-sidebar-group dp-panel-sidebar-pinned";
	section.dataset.dpPanelPinnedNav="1";
	section.innerHTML="<h2>"+dpPanelEscape(dpPanelText("client.pinned_category","Pinned"))+"</h2>"+items.map(dpPanelPinnedNavLinkHtml).join("");
	var firstGroup=nav.querySelector(".dp-panel-sidebar-group");
	if(firstGroup){nav.insertBefore(section,firstGroup);}
	else {nav.appendChild(section);}
	dpPanelRefreshRecentNavigation();
	dpPanelRefreshSidebarSearch();
}
/**
 * Reads persisted sidebar collapsed state.
 *
 * @returns {boolean} Whether the sidebar is collapsed.
 */
function dpPanelSidebarCollapsed(){
	try{return localStorage.getItem("dataphyre_panel_sidebar_collapsed")==="1";}catch(error){return false;}
}
/**
 * Persists and applies desktop sidebar collapsed state.
 *
 * @param {boolean} collapsed Desired collapsed state.
 * @returns {void}
 */
function dpPanelSetSidebarCollapsed(collapsed){
	try{localStorage.setItem("dataphyre_panel_sidebar_collapsed",collapsed?"1":"0");}catch(error){}
	document.querySelectorAll("main.dp-panel-with-sidebar").forEach(function(panel){
		var collapsible=panel.dataset.dpPanelNavigationCollapse!=="0";
		var hasShellToggle=!!panel.querySelector("[data-dp-panel-sidebar-toggle]");
		var effectiveCollapsed=collapsible&&hasShellToggle&&collapsed;
		panel.classList.toggle("dp-panel-sidebar-collapsed",effectiveCollapsed);
	});
	dpPanelScrollActiveSidebarIntoView();
}
/**
 * Resolves the mobile navigation drawer Panel for a target.
 *
 * @param {Element|null} target Event target or descendant.
 * @returns {HTMLElement|null} Panel with mobile drawer navigation.
 */
function dpPanelMobileNavigationPanel(target){
	if(target&&target.closest){
		var local=target.closest('main.dp-panel[data-dp-panel-mobile-navigation="drawer"]');
		if(local){return local;}
	}
	return document.querySelector('main.dp-panel[data-dp-panel-mobile-navigation="drawer"]');
}
/**
 * Opens or closes the mobile navigation drawer.
 *
 * @param {boolean} open Desired open state.
 * @param {HTMLElement|null} panel Optional Panel override.
 * @returns {void}
 */
JS;
	}

}
