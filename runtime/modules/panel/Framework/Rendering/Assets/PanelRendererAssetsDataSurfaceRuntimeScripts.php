<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Progressive, root-scoped, bounded DataSurface window controller. */
trait PanelRendererAssetsDataSurfaceRuntimeScripts {
	private static function dataSurfaceRuntimeScript(): string {
		return <<<'JS'
var dpPanelDataSurfacePanel=window.DataphyrePanel=window.DataphyrePanel||{};
var dpPanelDataSurfacePrevious=dpPanelDataSurfacePanel.dataSurfaceRuntime;
if(dpPanelDataSurfacePrevious&&typeof dpPanelDataSurfacePrevious.dispose==="function"){dpPanelDataSurfacePrevious.dispose();}
var dpPanelDataSurfaceStates=new WeakMap();
var dpPanelDataSurfaceMounted=new Set();
var dpPanelDataSurfaceObserver=null;
var dpPanelDataSurfaceRuntime={
	version:"1",disposed:false,
	dispose:function(){
		if(dpPanelDataSurfaceRuntime.disposed){return;}
		dpPanelDataSurfaceRuntime.disposed=true;
		if(dpPanelDataSurfaceObserver){dpPanelDataSurfaceObserver.disconnect();dpPanelDataSurfaceObserver=null;}
		Array.from(dpPanelDataSurfaceMounted).forEach(dpPanelDataSurfaceDispose);
	},
	scan:function(root){dpPanelDataSurfaceScan(root);},
	count:function(){return dpPanelDataSurfaceMounted.size;}
};
dpPanelDataSurfacePanel.dataSurfaceRuntime=dpPanelDataSurfaceRuntime;

function dpPanelDataSurfaceConfig(root){
	var node=root.querySelector(":scope > script[data-dp-data-surface-config]");
	if(!node){return null;}
	try{
		var value=JSON.parse(node.textContent||"{}");
		return value&&typeof value==="object"&&!Array.isArray(value)?value:null;
	}catch(error){return null;}
}

function dpPanelDataSurfaceEndpoint(value){
	if(typeof value!=="string"||!value||value.length>2048||/[\u0000-\u0020\u007f\\]/.test(value)||value.slice(0,2)==="//"){return "";}
	try{var url=new URL(value,window.location.href);return url.origin===window.location.origin?url.href:"";}catch(error){return "";}
}

function dpPanelDataSurfaceExact(value,keys){
	if(!value||typeof value!=="object"||Array.isArray(value)){return false;}
	var actual=Object.keys(value).sort(),wanted=keys.slice().sort();
	return actual.length===wanted.length&&actual.every(function(key,index){return key===wanted[index];});
}

function dpPanelDataSurfaceSafe(value,depth,budget){
	budget.nodes++;
	if(depth>16||budget.nodes>10000){return false;}
	if(value===null||typeof value==="boolean"){return true;}
	if(typeof value==="number"){return Number.isFinite(value);}
	if(typeof value==="string"){return value.length<=65536;}
	if(typeof value!=="object"){return false;}
	var keys=Object.keys(value);if(keys.length>1000){return false;}
	return keys.every(function(key){return key.length<=256&&dpPanelDataSurfaceSafe(value[key],depth+1,budget);});
}

function dpPanelDataSurfaceIntent(value){
	return value===null||(
		dpPanelDataSurfaceExact(value,["type","version","intent","issued_at","expires_at"])&&
		value.type==="panel_data_surface_intent"&&value.version===1&&typeof value.intent==="string"&&value.intent.length>0&&value.intent.length<=16384&&
		Number.isInteger(value.issued_at)&&Number.isInteger(value.expires_at)&&value.expires_at>value.issued_at
	);
}

function dpPanelDataCanvasSpec(value,surface){
	if(value===null){return null;}
	if(!dpPanelDataSurfaceExact(value,["type","version","surface","roles","aggregate","interaction","presentation","capabilities"])||value.type!=="panel_data_canvas_spec"||value.version!==1||value.surface!==surface){return null;}
	if(!value.roles||typeof value.roles!=="object"||Array.isArray(value.roles)||!dpPanelDataSurfaceExact(value.interaction,["selection","cross_filter_group","cross_filter_field","drill_url","drill_parameter","editable"])||["none","single","multiple"].indexOf(value.interaction.selection)===-1){return null;}
	if((value.interaction.cross_filter_group===null)!==(value.interaction.cross_filter_field===null)||value.interaction.cross_filter_group!==null&&value.interaction.selection==="none"){return null;}
	if(!dpPanelDataSurfaceExact(value.presentation,["frozen_fields","show_labels","show_legend","snap_to_grid","zoom"])||!dpPanelDataSurfaceSafe(value,0,{nodes:0})){return null;}
	return value;
}

function dpPanelDataCanvasModel(value,surface,recordCount){
	if(!dpPanelDataSurfaceExact(value,["type","version","surface","record_count","model","diagnostics"])||value.type!=="panel_data_canvas_model"||value.version!==1||value.surface!==surface||value.record_count!==recordCount||!value.model||typeof value.model!=="object"||Array.isArray(value.model)||!Array.isArray(value.diagnostics)||value.diagnostics.length>32){throw new Error("invalid_surface_response");}
	value.diagnostics.forEach(function(entry){if(!dpPanelDataSurfaceExact(entry,["code","count"])||typeof entry.code!=="string"||!/^[a-z][a-z0-9_]{1,63}$/.test(entry.code)||!Number.isInteger(entry.count)||entry.count<1||entry.count>recordCount){throw new Error("invalid_surface_response");}});
	if(!dpPanelDataSurfaceSafe(value,0,{nodes:0})){throw new Error("invalid_surface_response");}
	return value;
}

function dpPanelDataSurfaceValidate(state,value){
	var advanced=["spreadsheet","pivot","tree","graph","gantt","heatmap","map","canvas"].indexOf(state.config.surface)!==-1;
	var keys=["type","version","definition","resource","surface","projection","records","window","returned","visible","total","total_known","has_before","has_after","previous_intent","next_intent"];
	if(advanced){keys.push("canvas");}
	if(!dpPanelDataSurfaceExact(value,keys)||value.type!=="panel_data_surface_window"||value.version!==(advanced?2:1)||value.definition!==state.config.definition||value.surface!==state.config.surface){throw new Error("invalid_surface_response");}
	if(!dpPanelDataSurfaceExact(value.projection,["fields","stable_key","slots","labels"])||!Array.isArray(value.projection.fields)||value.projection.fields.length<1||value.projection.fields.length>64){throw new Error("invalid_surface_response");}
	if(!dpPanelDataSurfaceExact(value.window,["start","length","overscan_before","overscan_after","effective_offset","fetch_limit","cursor_present"])){throw new Error("invalid_surface_response");}
	if(!Array.isArray(value.records)||value.records.length>1000||value.returned!==value.records.length||!Number.isInteger(value.visible)||value.visible<0||value.visible>value.returned){throw new Error("invalid_surface_response");}
	if(value.total!==null&&(!Number.isInteger(value.total)||value.total<0)){throw new Error("invalid_surface_response");}
	if(typeof value.total_known!=="boolean"||value.total_known!==(value.total!==null)||typeof value.has_before!=="boolean"||(value.has_after!==null&&typeof value.has_after!=="boolean")||!dpPanelDataSurfaceIntent(value.previous_intent)||!dpPanelDataSurfaceIntent(value.next_intent)){throw new Error("invalid_surface_response");}
	var seen=new Set(),visible=0;
	value.records.forEach(function(record){
		if(!dpPanelDataSurfaceExact(record,["key","position","visible","data"])||typeof record.key!=="string"||!record.key||record.key.length>256||seen.has(record.key)||!Number.isInteger(record.position)||record.position<0||typeof record.visible!=="boolean"||!record.data||typeof record.data!=="object"||Array.isArray(record.data)){throw new Error("invalid_surface_response");}
		seen.add(record.key);if(record.visible){visible++;}
	});
	if(advanced){dpPanelDataCanvasModel(value.canvas,value.surface,value.records.length);}
	if(visible!==value.visible||!dpPanelDataSurfaceSafe(value,0,{nodes:0})){throw new Error("invalid_surface_response");}
	return value;
}

function dpPanelDataSurfaceHeaders(){
	var headers={"Accept":"application/json","Content-Type":"application/json","X-Requested-With":"DataphyrePanelDataSurface"};
	var meta=document.querySelector('meta[name="csrf-token"]'),token=meta?String(meta.getAttribute("content")||"").trim():"";
	if(token&&token.length<=2048&&!/[\r\n]/.test(token)){headers["X-CSRF-Token"]=token;}
	return headers;
}

function dpPanelDataSurfaceStatus(state,message){
	var status=state.root.querySelector(":scope > .dp-data-surface__header [data-dp-data-surface-status]");
	if(status){status.textContent=String(message||"");}
}

function dpPanelDataSurfaceBusy(state,busy){
	state.busy=!!busy;state.root.setAttribute("aria-busy",busy?"true":"false");
	state.root.querySelectorAll("[data-dp-data-surface-intent]").forEach(function(button){button.disabled=!!busy||!button.getAttribute("data-intent");button.setAttribute("aria-disabled",button.disabled?"true":"false");});
}

function dpPanelDataSurfaceText(value){
	if(value===null||value===undefined){return "Not set";}
	if(typeof value==="boolean"){return value?"Yes":"No";}
	if(typeof value==="object"){try{return JSON.stringify(value);}catch(error){return "Not available";}}
	return String(value);
}

function dpPanelDataSurfaceMedia(value){
	if(typeof value!=="string"||!value||value.length>2048||/[\u0000-\u0020\u007f\\]/.test(value)||value.slice(0,2)==="//"){return "";}
	try{var url=new URL(value,window.location.href);return ["http:","https:"].indexOf(url.protocol)!==-1?url.href:"";}catch(error){return "";}
}

function dpPanelDataSurfaceElement(name,className,text){
	var node=document.createElement(name);if(className){node.className=className;}if(text!==undefined){node.textContent=String(text);}return node;
}

function dpPanelDataSurfaceRoving(node,index,record){
	node.setAttribute("data-dp-data-surface-item","");node.setAttribute("data-key",record.key);node.setAttribute("data-position",String(record.position));node.setAttribute("data-visible",record.visible?"1":"0");node.tabIndex=index===0?0:-1;
}

function dpPanelDataSurfaceTable(state,result){
	var shell=dpPanelDataSurfaceElement("div","dp-data-surface__table-shell"),table=dpPanelDataSurfaceElement("table","dp-data-surface__table");
	table.setAttribute("data-dp-data-surface-items","");
	var caption=dpPanelDataSurfaceElement("caption","dp-panel-visually-hidden",state.title);table.appendChild(caption);
	var thead=document.createElement("thead"),headRow=document.createElement("tr");
	result.projection.fields.forEach(function(field){var th=dpPanelDataSurfaceElement("th","",result.projection.labels[field]||field.split(".").pop().replace(/_/g," "));th.scope="col";headRow.appendChild(th);});
	thead.appendChild(headRow);table.appendChild(thead);
	var tbody=document.createElement("tbody");
	result.records.forEach(function(record,index){
		var row=document.createElement("tr");dpPanelDataSurfaceRoving(row,index,record);
		result.projection.fields.forEach(function(field){var label=result.projection.labels[field]||field.split(".").pop().replace(/_/g," "),cell=dpPanelDataSurfaceElement("td","",dpPanelDataSurfaceText(record.data[field]));cell.setAttribute("data-label",label);row.appendChild(cell);});
		tbody.appendChild(row);
	});
	if(!result.records.length){var row=dpPanelDataSurfaceElement("tr","dp-data-surface__empty"),cell=dpPanelDataSurfaceElement("td","",state.config.messages.empty);cell.colSpan=Math.max(1,result.projection.fields.length);row.appendChild(cell);tbody.appendChild(row);}
	table.appendChild(tbody);shell.appendChild(table);return shell;
}

function dpPanelDataSurfaceSlot(result,record,name){var field=result.projection.slots[name];return field?record.data[field]:null;}

function dpPanelDataSurfaceCollection(state,result){
	var list=document.createElement(result.surface==="timeline"?"ol":"ul");list.className="dp-data-surface__items";list.setAttribute("data-dp-data-surface-items","");list.setAttribute("role","list");list.setAttribute("aria-label",state.title);
	result.records.forEach(function(record,index){
		var item=dpPanelDataSurfaceElement("li","dp-data-surface__item");dpPanelDataSurfaceRoving(item,index,record);
		var article=document.createElement("article"),image=dpPanelDataSurfaceMedia(dpPanelDataSurfaceSlot(result,record,"image"));
		if(image){var img=document.createElement("img");img.className="dp-data-surface__image";img.src=image;img.alt=dpPanelDataSurfaceText(dpPanelDataSurfaceSlot(result,record,"alt")||"");img.loading="lazy";img.decoding="async";article.appendChild(img);}
		var body=dpPanelDataSurfaceElement("div","dp-data-surface__item-body"),title=dpPanelDataSurfaceSlot(result,record,"title");body.appendChild(dpPanelDataSurfaceElement("h3","",title===null||title===undefined?record.key:dpPanelDataSurfaceText(title)));
		var time=dpPanelDataSurfaceSlot(result,record,"time");if(time===null||time===undefined){time=dpPanelDataSurfaceSlot(result,record,"start");}if(time!==null&&time!==undefined){body.appendChild(dpPanelDataSurfaceElement("time","",dpPanelDataSurfaceText(time)));}
		var description=dpPanelDataSurfaceSlot(result,record,"description");if(description!==null&&description!==undefined){body.appendChild(dpPanelDataSurfaceElement("p","",dpPanelDataSurfaceText(description)));}
		var meta=dpPanelDataSurfaceSlot(result,record,"meta"),badge=dpPanelDataSurfaceSlot(result,record,"badge");if((meta!==null&&meta!==undefined)||(badge!==null&&badge!==undefined)){var footer=document.createElement("footer");if(meta!==null&&meta!==undefined){footer.appendChild(dpPanelDataSurfaceElement("span","",dpPanelDataSurfaceText(meta)));}if(badge!==null&&badge!==undefined){footer.appendChild(dpPanelDataSurfaceElement("span","dp-data-surface__badge",dpPanelDataSurfaceText(badge)));}body.appendChild(footer);}
		article.appendChild(body);item.appendChild(article);list.appendChild(item);
	});
	if(!result.records.length){list.appendChild(dpPanelDataSurfaceElement("li","dp-data-surface__empty",state.config.messages.empty));}
	return list;
}

function dpPanelDataCanvasClamp(value,minimum,maximum){value=Number(value);return Number.isFinite(value)?Math.max(minimum,Math.min(maximum,value)):minimum;}

function dpPanelDataCanvasValues(values){
	if(!Array.isArray(values)||values.length>100){return [];}
	var output=[],seen=new Set();values.forEach(function(value){
		if(value!==null&&typeof value!=="string"&&typeof value!=="number"&&typeof value!=="boolean"){return;}
		if(typeof value==="number"&&!Number.isFinite(value)||typeof value==="string"&&value.length>1024){return;}
		var key=JSON.stringify(value);if(!seen.has(key)){seen.add(key);output.push(value);}
	});return output;
}

function dpPanelDataCanvasItem(state,node,index,key,position,visible,values,role){
	dpPanelDataSurfaceRoving(node,index,{key:String(key),position:Number.isInteger(position)&&position>=0?position:index,visible:visible!==false});
	if(role){node.setAttribute("role",role);}
	if(state.config.canvas&&state.config.canvas.interaction.selection!=="none"){
		values=dpPanelDataCanvasValues(values);node.setAttribute("data-dp-data-canvas-select","");node.setAttribute("data-dp-data-canvas-values",JSON.stringify(values));node.setAttribute("aria-selected",state.selected.has(String(key))?"true":"false");
	}
	return node;
}

function dpPanelDataCanvasDrill(state,key){
	var interaction=state.config.canvas&&state.config.canvas.interaction;if(!interaction||!interaction.drill_url||(typeof key!=="string"&&typeof key!=="number")){return null;}
	try{var url=new URL(interaction.drill_url,window.location.href);if(url.origin!==window.location.origin){return null;}url.searchParams.set(interaction.drill_parameter,String(key));var link=dpPanelDataSurfaceElement("a","dp-data-canvas__drill","Open details");link.href=url.href;return link;}catch(error){return null;}
}

function dpPanelDataCanvasAppendDrill(state,node,key){var link=dpPanelDataCanvasDrill(state,key);if(link){node.appendChild(link);}}

function dpPanelDataCanvasSpreadsheet(state,result,model){
	var shell=dpPanelDataSurfaceElement("div","dp-data-canvas__table-shell dp-data-canvas__spreadsheet-shell"),table=dpPanelDataSurfaceElement("table","dp-data-canvas__table dp-data-canvas__spreadsheet");shell.setAttribute("role","region");shell.setAttribute("aria-label",state.title+" spreadsheet");table.setAttribute("data-dp-data-surface-items","");table.appendChild(dpPanelDataSurfaceElement("caption","dp-panel-visually-hidden",state.title));
	var fields=Array.isArray(model.fields)?model.fields.filter(function(field){return field&&typeof field.name==="string"&&typeof field.label==="string";}):[],thead=document.createElement("thead"),head=document.createElement("tr"),number=dpPanelDataSurfaceElement("th","dp-data-canvas__row-number","#");number.scope="col";head.appendChild(number);fields.forEach(function(field){var th=dpPanelDataSurfaceElement("th","",field.label);th.scope="col";th.setAttribute("data-field",field.name);if(field.frozen===true){th.setAttribute("data-frozen","true");}head.appendChild(th);});thead.appendChild(head);table.appendChild(thead);
	var byKey=new Map();result.records.forEach(function(record){byKey.set(record.key,record);});var tbody=document.createElement("tbody"),rows=Array.isArray(model.rows)?model.rows:[];
	rows.forEach(function(row,index){if(!row||!byKey.has(row.key)){return;}var record=byKey.get(row.key),tr=dpPanelDataSurfaceElement("tr","dp-data-canvas__record");dpPanelDataCanvasItem(state,tr,index,record.key,record.position,record.visible,row.filter_values);var rowNumber=dpPanelDataSurfaceElement("th","dp-data-canvas__row-number",record.position+1);rowNumber.scope="row";tr.appendChild(rowNumber);fields.forEach(function(field){var td=dpPanelDataSurfaceElement("td","",dpPanelDataSurfaceText(record.data[field.name]));td.setAttribute("data-label",field.label);if(field.frozen===true){td.setAttribute("data-frozen","true");}tr.appendChild(td);});tbody.appendChild(tr);});
	if(!tbody.childNodes.length){var emptyRow=dpPanelDataSurfaceElement("tr","dp-data-surface__empty"),emptyCell=dpPanelDataSurfaceElement("td","",state.config.messages.empty);emptyCell.colSpan=Math.max(1,fields.length+1);emptyRow.appendChild(emptyCell);tbody.appendChild(emptyRow);}table.appendChild(tbody);shell.appendChild(table);return shell;
}

function dpPanelDataCanvasMatrix(state,result,model){
	var shell=dpPanelDataSurfaceElement("div","dp-data-canvas__table-shell dp-data-canvas__matrix-shell"),table=dpPanelDataSurfaceElement("table","dp-data-canvas__table dp-data-canvas__matrix");shell.setAttribute("role","region");shell.setAttribute("aria-label",state.title+" "+result.surface);table.setAttribute("role","grid");table.setAttribute("data-dp-data-surface-items","");table.appendChild(dpPanelDataSurfaceElement("caption","dp-panel-visually-hidden",state.title));
	var rows=Array.isArray(model.rows)?model.rows:[],columns=Array.isArray(model.columns)?model.columns:[],cells=new Map();(Array.isArray(model.cells)?model.cells:[]).forEach(function(cell){if(cell){cells.set(String(cell.row_key)+"\u0000"+String(cell.column_key),cell);}});var thead=document.createElement("thead"),head=document.createElement("tr"),corner=dpPanelDataSurfaceElement("th","",String(model.aggregate||"Value"));corner.scope="col";head.appendChild(corner);columns.forEach(function(column){if(!column){return;}var th=dpPanelDataSurfaceElement("th","",dpPanelDataSurfaceText(column.label));th.scope="col";head.appendChild(th);});thead.appendChild(head);table.appendChild(thead);var tbody=document.createElement("tbody"),position=0;
	rows.forEach(function(row){if(!row){return;}var tr=document.createElement("tr"),rowHead=dpPanelDataSurfaceElement("th","",dpPanelDataSurfaceText(row.label));rowHead.scope="row";tr.appendChild(rowHead);columns.forEach(function(column){if(!column){return;}var td=dpPanelDataSurfaceElement("td","dp-data-canvas__matrix-cell"),label=dpPanelDataSurfaceText(column.label);td.setAttribute("data-label",label);var cell=cells.get(String(row.key)+"\u0000"+String(column.key));if(!cell){td.appendChild(dpPanelDataSurfaceElement("span","","Not set"));tr.appendChild(td);return;}td.style.setProperty("--dp-data-canvas-intensity",String(dpPanelDataCanvasClamp(cell.intensity,0,1)));var current=position++,box=dpPanelDataSurfaceElement("div","dp-data-canvas__matrix-value");dpPanelDataCanvasItem(state,box,current,cell.record_key||String(row.key)+"-"+String(column.key),current,true,cell.filter_values,"gridcell");box.appendChild(dpPanelDataSurfaceElement("strong","",dpPanelDataSurfaceText(cell.value)));box.appendChild(dpPanelDataSurfaceElement("span","",String(Number.isInteger(cell.count)?cell.count:0)+" record"+(cell.count===1?"":"s")));dpPanelDataCanvasAppendDrill(state,box,cell.record_key);td.appendChild(box);tr.appendChild(td);});tbody.appendChild(tr);});
	if(!rows.length){var emptyRow=dpPanelDataSurfaceElement("tr","dp-data-surface__empty"),emptyCell=dpPanelDataSurfaceElement("td","",state.config.messages.empty);emptyCell.colSpan=Math.max(1,columns.length+1);emptyRow.appendChild(emptyCell);tbody.appendChild(emptyRow);}table.appendChild(tbody);shell.appendChild(table);return shell;
}

function dpPanelDataCanvasTree(state,model){
	var list=dpPanelDataSurfaceElement("ol","dp-data-canvas__tree");list.setAttribute("data-dp-data-surface-items","");list.setAttribute("role","tree");list.setAttribute("aria-label",state.title);var nodes=Array.isArray(model.nodes)?model.nodes:[];
	nodes.forEach(function(node,index){if(!node){return;}var depth=Math.trunc(dpPanelDataCanvasClamp(node.depth,0,64)),item=dpPanelDataSurfaceElement("li","dp-data-canvas__tree-node");item.setAttribute("aria-level",String(depth+1));item.style.setProperty("--dp-data-tree-depth",String(depth));dpPanelDataCanvasItem(state,item,index,node.key,Number.isInteger(node.position)?node.position:index,node.visible,node.filter_values,"treeitem");var branch=dpPanelDataSurfaceElement("span","dp-data-canvas__tree-branch");branch.setAttribute("aria-hidden","true");item.appendChild(branch);var body=document.createElement("div");body.appendChild(dpPanelDataSurfaceElement("strong","",dpPanelDataSurfaceText(node.title)));if(node.description){body.appendChild(dpPanelDataSurfaceElement("span","",dpPanelDataSurfaceText(node.description)));}var states=[];if(node.orphan===true){states.push("Missing parent");}if(node.cycle===true){states.push("Circular relationship");}if(states.length){body.appendChild(dpPanelDataSurfaceElement("small","",states.join(" / ")));}dpPanelDataCanvasAppendDrill(state,body,node.key);item.appendChild(body);list.appendChild(item);});if(!nodes.length){list.appendChild(dpPanelDataSurfaceElement("li","dp-data-surface__empty",state.config.messages.empty));}return list;
}

function dpPanelDataCanvasGraph(state,model){
	var graph=dpPanelDataSurfaceElement("div","dp-data-canvas__graph"),stage=dpPanelDataSurfaceElement("div","dp-data-canvas__graph-stage");stage.setAttribute("aria-hidden","true");var namespace="http://www.w3.org/2000/svg",svg=document.createElementNS(namespace,"svg");svg.setAttribute("viewBox","0 0 100 100");svg.setAttribute("preserveAspectRatio","xMidYMid meet");svg.setAttribute("focusable","false");var nodes=Array.isArray(model.nodes)?model.nodes:[],edges=Array.isArray(model.edges)?model.edges:[],byKey=new Map();nodes.forEach(function(node){if(node){byKey.set(String(node.key),node);}});edges.forEach(function(edge){if(!edge){return;}var source=byKey.get(String(edge.source)),target=byKey.get(String(edge.target));if(!source||!target){return;}var line=document.createElementNS(namespace,"line");line.setAttribute("x1",String(dpPanelDataCanvasClamp(source.x,0,100)));line.setAttribute("y1",String(dpPanelDataCanvasClamp(source.y,0,100)));line.setAttribute("x2",String(dpPanelDataCanvasClamp(target.x,0,100)));line.setAttribute("y2",String(dpPanelDataCanvasClamp(target.y,0,100)));line.setAttribute("vector-effect","non-scaling-stroke");svg.appendChild(line);});stage.appendChild(svg);nodes.forEach(function(node){if(!node){return;}var label=dpPanelDataSurfaceElement("span","dp-data-canvas__graph-node");label.style.setProperty("--dp-canvas-x",dpPanelDataCanvasClamp(node.x,0,100)+"%");label.style.setProperty("--dp-canvas-y",dpPanelDataCanvasClamp(node.y,0,100)+"%");label.appendChild(dpPanelDataSurfaceElement("strong","",dpPanelDataSurfaceText(node.label)));label.appendChild(dpPanelDataSurfaceElement("small","",String((Number(node.in_degree)||0)+(Number(node.out_degree)||0))+" links"));stage.appendChild(label);});graph.appendChild(stage);
	var list=dpPanelDataSurfaceElement("ol","dp-data-canvas__graph-edges");list.setAttribute("data-dp-data-surface-items","");list.setAttribute("role",state.config.canvas.interaction.selection==="none"?"list":"listbox");list.setAttribute("aria-label",state.title+" relationships");edges.forEach(function(edge,index){if(!edge){return;}var item=dpPanelDataSurfaceElement("li","dp-data-canvas__graph-edge");dpPanelDataCanvasItem(state,item,index,edge.key,index,true,edge.filter_values,state.config.canvas.interaction.selection==="none"?"listitem":"option");var mark=dpPanelDataSurfaceElement("span","","↗");mark.setAttribute("aria-hidden","true");item.appendChild(mark);item.appendChild(dpPanelDataSurfaceElement("strong","",dpPanelDataSurfaceText(edge.label)));dpPanelDataCanvasAppendDrill(state,item,edge.key);list.appendChild(item);});graph.appendChild(list);if(!edges.length){graph.appendChild(dpPanelDataSurfaceElement("p","dp-data-surface__empty",state.config.messages.empty));}return graph;
}

function dpPanelDataCanvasGantt(state,model){
	var list=dpPanelDataSurfaceElement("ol","dp-data-canvas__gantt"),tasks=Array.isArray(model.tasks)?model.tasks:[];list.setAttribute("data-dp-data-surface-items","");list.setAttribute("role",state.config.canvas.interaction.selection==="none"?"list":"listbox");list.setAttribute("aria-label",state.title+" schedule");tasks.forEach(function(task,index){if(!task){return;}var item=dpPanelDataSurfaceElement("li","dp-data-canvas__gantt-task");dpPanelDataCanvasItem(state,item,index,task.key,index,true,task.filter_values,state.config.canvas.interaction.selection==="none"?"listitem":"option");var label=dpPanelDataSurfaceElement("div","dp-data-canvas__gantt-label");label.appendChild(dpPanelDataSurfaceElement("strong","",dpPanelDataSurfaceText(task.title)));label.appendChild(dpPanelDataSurfaceElement("span","",dpPanelDataSurfaceText(task.start)+" to "+dpPanelDataSurfaceText(task.end)));item.appendChild(label);var track=dpPanelDataSurfaceElement("div","dp-data-canvas__gantt-track"),bar=document.createElement("span"),left=dpPanelDataCanvasClamp(task.left,0,100),width=dpPanelDataCanvasClamp(task.width,0,100-left),progress=dpPanelDataCanvasClamp(task.progress,0,100);track.setAttribute("aria-label",progress+"% complete");bar.style.setProperty("--dp-gantt-left",left+"%");bar.style.setProperty("--dp-gantt-width",width+"%");bar.style.setProperty("--dp-gantt-progress",progress+"%");track.appendChild(bar);item.appendChild(track);dpPanelDataCanvasAppendDrill(state,item,task.key);list.appendChild(item);});if(!tasks.length){list.appendChild(dpPanelDataSurfaceElement("li","dp-data-surface__empty",state.config.messages.empty));}return list;
}

function dpPanelDataCanvasMap(state,model){
	var root=dpPanelDataSurfaceElement("div","dp-data-canvas__map"),points=Array.isArray(model.points)?model.points:[];root.setAttribute("data-dp-data-surface-items","");root.setAttribute("role",state.config.canvas.interaction.selection==="none"?"list":"listbox");root.setAttribute("aria-label",state.title+" locations");points.forEach(function(point,index){if(!point){return;}var item=dpPanelDataSurfaceElement("article","dp-data-canvas__map-point");item.style.setProperty("--dp-canvas-x",dpPanelDataCanvasClamp(point.x,0,100)+"%");item.style.setProperty("--dp-canvas-y",dpPanelDataCanvasClamp(point.y,0,100)+"%");dpPanelDataCanvasItem(state,item,index,point.key,index,true,point.filter_values,state.config.canvas.interaction.selection==="none"?"listitem":"option");var pin=dpPanelDataSurfaceElement("span","dp-data-canvas__map-pin");pin.setAttribute("aria-hidden","true");item.appendChild(pin);item.appendChild(dpPanelDataSurfaceElement("strong","",dpPanelDataSurfaceText(point.title)));item.appendChild(dpPanelDataSurfaceElement("small","",dpPanelDataSurfaceText(point.latitude)+", "+dpPanelDataSurfaceText(point.longitude)));dpPanelDataCanvasAppendDrill(state,item,point.key);root.appendChild(item);});if(!points.length){root.appendChild(dpPanelDataSurfaceElement("p","dp-data-surface__empty",state.config.messages.empty));}return root;
}

function dpPanelDataCanvasFreeform(state,model){
	var root=dpPanelDataSurfaceElement("div","dp-data-canvas__freeform"),items=Array.isArray(model.items)?model.items:[];root.setAttribute("data-dp-data-surface-items","");root.setAttribute("role",state.config.canvas.interaction.selection==="none"?"list":"listbox");root.setAttribute("aria-label",state.title+" canvas");items.forEach(function(value,index){if(!value){return;}var item=dpPanelDataSurfaceElement("article","dp-data-canvas__freeform-item"),x=dpPanelDataCanvasClamp(value.x,0,100),y=dpPanelDataCanvasClamp(value.y,0,100);item.style.setProperty("--dp-canvas-x",x+"%");item.style.setProperty("--dp-canvas-y",y+"%");item.style.setProperty("--dp-canvas-width",dpPanelDataCanvasClamp(value.width,.5,100-x)+"%");item.style.setProperty("--dp-canvas-height",dpPanelDataCanvasClamp(value.height,.5,100-y)+"%");dpPanelDataCanvasItem(state,item,index,value.key,index,true,value.filter_values,state.config.canvas.interaction.selection==="none"?"listitem":"option");item.appendChild(dpPanelDataSurfaceElement("strong","",dpPanelDataSurfaceText(value.title)));if(value.description){item.appendChild(dpPanelDataSurfaceElement("span","",dpPanelDataSurfaceText(value.description)));}dpPanelDataCanvasAppendDrill(state,item,value.key);root.appendChild(item);});if(!items.length){root.appendChild(dpPanelDataSurfaceElement("p","dp-data-surface__empty",state.config.messages.empty));}return root;
}

function dpPanelDataCanvasBuild(state,result){
	var model=result.canvas.model;switch(result.surface){
		case "spreadsheet":return dpPanelDataCanvasSpreadsheet(state,result,model);
		case "pivot":case "heatmap":return dpPanelDataCanvasMatrix(state,result,model);
		case "tree":return dpPanelDataCanvasTree(state,model);
		case "graph":return dpPanelDataCanvasGraph(state,model);
		case "gantt":return dpPanelDataCanvasGantt(state,model);
		case "map":return dpPanelDataCanvasMap(state,model);
		case "canvas":return dpPanelDataCanvasFreeform(state,model);
		default:throw new Error("invalid_surface_response");
	}
}

function dpPanelDataSurfaceUpdateControls(state,result){
	var values={previous:result.previous_intent&&result.previous_intent.intent||"",next:result.next_intent&&result.next_intent.intent||"",refresh:state.config.refresh_intent||""};
	state.root.querySelectorAll("[data-dp-data-surface-intent]").forEach(function(button){var token=values[button.getAttribute("data-dp-data-surface-intent")]||"";if(token){button.setAttribute("data-intent",token);}else{button.removeAttribute("data-intent");}button.disabled=!token;button.setAttribute("aria-disabled",token?"false":"true");});
}

function dpPanelDataSurfaceSpacers(state,result){
	if(result.canvas){state.before.style.blockSize="0px";state.after.style.blockSize="0px";return;}
	var first=result.records.length?result.records[0].position:result.window.effective_offset,last=result.records.length?result.records[result.records.length-1].position:first-1,size=state.config.estimated_item_size;
	var compactKnownWindow=result.total_known&&result.total<=Math.max(100,result.records.length*3);
	var before=compactKnownWindow?0:Math.min(20000000,Math.max(0,first*size)),after=!compactKnownWindow&&result.total_known?Math.min(20000000,Math.max(0,(result.total-last-1)*size)):0;
	state.before.style.blockSize=before+"px";state.after.style.blockSize=after+"px";
}

function dpPanelDataSurfaceApply(state,result,focusKey){
	result=dpPanelDataSurfaceValidate(state,result);state.current=result;
	while(state.viewport.firstChild){state.viewport.removeChild(state.viewport.firstChild);}
	state.viewport.appendChild(result.canvas?dpPanelDataCanvasBuild(state,result):(result.surface==="table"?dpPanelDataSurfaceTable(state,result):dpPanelDataSurfaceCollection(state,result)));
	dpPanelDataSurfaceSpacers(state,result);dpPanelDataSurfaceUpdateControls(state,result);
	dpPanelDataSurfaceStatus(state,result.total_known?result.visible+" of "+result.total+" records shown.":result.visible+" record"+(result.visible===1?"":"s")+" shown; total unknown.");
	var focus=focusKey?Array.from(state.root.querySelectorAll("[data-dp-data-surface-item]")).find(function(node){return node.getAttribute("data-key")===focusKey;}):null;
	if(focus){focus.tabIndex=0;try{focus.focus({preventScroll:true});}catch(error){focus.focus();}}
}

function dpPanelDataSurfaceLoad(state,intent,focusKey,interaction){
	if(state.busy||!intent||intent.length>16384){return Promise.resolve(false);}
	if(state.controller){state.controller.abort();}state.controller=typeof AbortController==="function"?new AbortController():null;
	dpPanelDataSurfaceBusy(state,true);dpPanelDataSurfaceStatus(state,state.config.messages.loading);
	var payload={intent:intent};if(interaction){payload.interaction=interaction;}
	return window.fetch(state.endpoint,{method:"POST",credentials:"same-origin",headers:dpPanelDataSurfaceHeaders(),body:JSON.stringify(payload),signal:state.controller?state.controller.signal:undefined})
		.then(function(response){if(!response.ok){throw new Error("surface_transport_"+response.status);}return response.json();})
		.then(function(value){dpPanelDataSurfaceApply(state,value,focusKey);state.config.next_intent=value.next_intent&&value.next_intent.intent||null;state.config.previous_intent=value.previous_intent&&value.previous_intent.intent||null;return true;})
		.catch(function(error){if(!state.controller||!state.controller.signal.aborted){dpPanelDataSurfaceStatus(state,state.config.messages.failed);}return false;})
		.then(function(done){dpPanelDataSurfaceBusy(state,false);return done;});
}

function dpPanelDataCanvasSelectionValues(state){
	var values=[],seen=new Set(),overflow=false;state.selected.forEach(function(entry){dpPanelDataCanvasValues(entry).forEach(function(value){var key=JSON.stringify(value);if(seen.has(key)){return;}if(values.length===100){overflow=true;return;}seen.add(key);values.push(value);});});return overflow?null:values;
}

function dpPanelDataCanvasRefreshSelection(state){
	state.root.querySelectorAll("[data-dp-data-canvas-select]").forEach(function(node){node.setAttribute("aria-selected",state.selected.has(node.getAttribute("data-key")||"")?"true":"false");});
}

function dpPanelDataCanvasQueueFilter(state,values){
	state.pendingCanvasValues=values.slice();if(state.busy){return;}
	var next=state.pendingCanvasValues;state.pendingCanvasValues=null;var intent=state.config.refresh_intent;
	if(!intent){return;}
	dpPanelDataSurfaceLoad(state,intent,null,{type:"cross_filter",values:next}).then(function(){if(state.pendingCanvasValues){dpPanelDataCanvasQueueFilter(state,state.pendingCanvasValues);}});
}

function dpPanelDataCanvasBroadcast(source,values){
	var interaction=source.config.canvas&&source.config.canvas.interaction,group=interaction&&interaction.cross_filter_group;if(!group){dpPanelDataSurfaceStatus(source,source.selected.size+" selected.");return;}
	Array.from(dpPanelDataSurfaceMounted).forEach(function(root){var target=dpPanelDataSurfaceStates.get(root),targetInteraction=target&&target.config.canvas&&target.config.canvas.interaction;if(target&&targetInteraction&&targetInteraction.cross_filter_group===group){dpPanelDataCanvasQueueFilter(target,values);}});
	if(typeof window.CustomEvent==="function"&&typeof window.dispatchEvent==="function"){window.dispatchEvent(new window.CustomEvent("dataphyre:panel-canvas-filter",{detail:{group:group,count:values.length,source:source.config.definition}}));}
}

function dpPanelDataCanvasToggle(state,item){
	if(!state.config.canvas||state.config.canvas.interaction.selection==="none"){return;}
	var key=item.getAttribute("data-key")||"",values;try{values=dpPanelDataCanvasValues(JSON.parse(item.getAttribute("data-dp-data-canvas-values")||"[]"));}catch(error){values=[];}
	var before=new Map(state.selected),selected=state.selected.has(key);if(state.config.canvas.interaction.selection==="single"){state.selected.clear();if(!selected){state.selected.set(key,values);}}else if(selected){state.selected.delete(key);}else{state.selected.set(key,values);}
	var combined=dpPanelDataCanvasSelectionValues(state);if(combined===null){state.selected=before;dpPanelDataSurfaceStatus(state,"Select at most 100 distinct filter values.");dpPanelDataCanvasRefreshSelection(state);return;}
	dpPanelDataCanvasRefreshSelection(state);dpPanelDataCanvasBroadcast(state,combined);
}

function dpPanelDataSurfaceMove(state,item,delta,edge){
	var items=Array.from(state.root.querySelectorAll("[data-dp-data-surface-item]"));if(!items.length){return;}
	var index=items.indexOf(item);if(index<0){index=0;}var next=edge==="start"?0:edge==="end"?items.length-1:Math.max(0,Math.min(items.length-1,index+delta));
	items.forEach(function(node){node.tabIndex=-1;});items[next].tabIndex=0;items[next].focus();items[next].scrollIntoView({block:"nearest",inline:"nearest",behavior:"auto"});
}

function dpPanelDataSurfaceKeydown(state,event){
	var item=event.target&&event.target.closest?event.target.closest("[data-dp-data-surface-item]"):null;if(!item||!state.root.contains(item)){return;}
	if((event.key==="Enter"||event.key===" ")&&item.hasAttribute("data-dp-data-canvas-select")){
		var control=event.target&&event.target.closest?event.target.closest("a,button,input,select,textarea"):null;if(control&&control!==item){return;}event.preventDefault();dpPanelDataCanvasToggle(state,item);return;
	}
	var rtl=window.getComputedStyle(state.root).direction==="rtl",key=event.key,delta=0,edge="";
	if(key==="ArrowDown"){delta=1;}else if(key==="ArrowUp"){delta=-1;}else if(key==="ArrowRight"){delta=rtl?-1:1;}else if(key==="ArrowLeft"){delta=rtl?1:-1;}else if(key==="PageDown"){delta=10;}else if(key==="PageUp"){delta=-10;}else if(key==="Home"){edge="start";}else if(key==="End"){edge="end";}else{return;}
	event.preventDefault();dpPanelDataSurfaceMove(state,item,delta,edge);
}

function dpPanelDataSurfaceDispose(root){
	var state=dpPanelDataSurfaceStates.get(root);if(!state){return;}if(state.controller){state.controller.abort();}state.cleanups.forEach(function(cleanup){cleanup();});dpPanelDataSurfaceStates.delete(root);dpPanelDataSurfaceMounted.delete(root);delete root.dataset.dpDataSurfaceEnhanced;
}

function dpPanelDataSurfaceInitialize(root){
	if(dpPanelDataSurfaceStates.has(root)||!root.closest||!root.closest(".dp-panel")){return;}
	var config=dpPanelDataSurfaceConfig(root),endpoint=config?dpPanelDataSurfaceEndpoint(config.endpoint):"";
	var advanced=config&&["spreadsheet","pivot","tree","graph","gantt","heatmap","map","canvas"].indexOf(config.surface)!==-1,canvas=config?dpPanelDataCanvasSpec(config.canvas,config.surface):null;
	if(!config||config.version!==1||["table","list","cards","timeline","calendar","gallery","spreadsheet","pivot","tree","graph","gantt","heatmap","map","canvas"].indexOf(config.surface)===-1||advanced&&!canvas||!advanced&&config.canvas!==null||!endpoint||!config.projection||!Array.isArray(config.projection.fields)){return;}
	var viewport=root.querySelector(":scope > [data-dp-data-surface-viewport]"),before=root.querySelector(":scope > [data-dp-data-surface-spacer="+JSON.stringify("before")+"]"),after=root.querySelector(":scope > [data-dp-data-surface-spacer="+JSON.stringify("after")+"]");if(!viewport||!before||!after){return;}
	var titleNode=root.querySelector(":scope > .dp-data-surface__header h2"),state={root:root,config:config,endpoint:endpoint,viewport:viewport,before:before,after:after,title:titleNode?titleNode.textContent:"Records",busy:false,controller:null,current:null,cleanups:[],userNavigated:false,selected:new Map(),pendingCanvasValues:null};
	dpPanelDataSurfaceStates.set(root,state);dpPanelDataSurfaceMounted.add(root);root.dataset.dpDataSurfaceEnhanced="1";root.setAttribute("aria-busy","false");
	var controls=root.querySelector(":scope > [data-dp-data-surface-controls]");if(controls){controls.hidden=false;}
	var click=function(event){var selected=event.target&&event.target.closest?event.target.closest("[data-dp-data-canvas-select]"):null;if(selected&&root.contains(selected)&&!(event.target&&event.target.closest&&event.target.closest("a,button,input,select,textarea"))){dpPanelDataCanvasToggle(state,selected);return;}var button=event.target&&event.target.closest?event.target.closest("[data-dp-data-surface-intent]"):null;if(!button||!root.contains(button)||button.disabled){return;}dpPanelDataSurfaceLoad(state,button.getAttribute("data-intent")||"",null);};
	var keydown=function(event){dpPanelDataSurfaceKeydown(state,event);},navigate=function(){state.userNavigated=true;};
	root.addEventListener("click",click);root.addEventListener("keydown",keydown);root.addEventListener("wheel",navigate,{passive:true});root.addEventListener("touchstart",navigate,{passive:true});
	state.cleanups.push(function(){root.removeEventListener("click",click);root.removeEventListener("keydown",keydown);root.removeEventListener("wheel",navigate);root.removeEventListener("touchstart",navigate);});
	if(typeof IntersectionObserver==="function"&&config.virtualize===true){
		var nextSentinel=dpPanelDataSurfaceElement("span","dp-data-surface__sentinel");nextSentinel.setAttribute("aria-hidden","true");viewport.insertAdjacentElement("afterend",nextSentinel);
		var observer=new IntersectionObserver(function(entries){entries.forEach(function(entry){if(entry.isIntersecting&&state.userNavigated&&!state.busy){var button=root.querySelector('[data-dp-data-surface-intent="next"]');if(button&&!button.disabled){state.userNavigated=false;dpPanelDataSurfaceLoad(state,button.getAttribute("data-intent")||"",null);}}});},{root:null,rootMargin:"320px 0px",threshold:0});observer.observe(nextSentinel);state.cleanups.push(function(){observer.disconnect();nextSentinel.remove();});
	}
}

function dpPanelDataSurfaceScan(root){
	if(!root){return;}
	if(root.nodeType===1&&root.matches&&root.matches("[data-dp-data-surface]")){dpPanelDataSurfaceInitialize(root);}
	if(root.querySelectorAll){root.querySelectorAll("[data-dp-data-surface]").forEach(dpPanelDataSurfaceInitialize);}
}

function dpPanelDataSurfaceScheduleRemoval(node){
	var roots=[];
	if(node&&node.nodeType===1&&node.matches&&node.matches("[data-dp-data-surface]")){roots.push(node);}
	if(node&&node.nodeType===1&&node.querySelectorAll){node.querySelectorAll("[data-dp-data-surface]").forEach(function(root){roots.push(root);});}
	if(!roots.length){return;}
	var cleanup=function(){roots.forEach(function(root){if(!root.isConnected||!root.closest||!root.closest(".dp-panel")){dpPanelDataSurfaceDispose(root);}});};
	if(typeof window.queueMicrotask==="function"){window.queueMicrotask(cleanup);}else{Promise.resolve().then(cleanup);}
}

function dpPanelDataSurfaceBoot(){
	if(dpPanelDataSurfaceRuntime.disposed){return;}
	dpPanelDataSurfaceScan(document);
	if(typeof MutationObserver==="function"&&!dpPanelDataSurfaceObserver){
		dpPanelDataSurfaceObserver=new MutationObserver(function(records){records.forEach(function(record){record.removedNodes.forEach(dpPanelDataSurfaceScheduleRemoval);record.addedNodes.forEach(dpPanelDataSurfaceScan);});});
		dpPanelDataSurfaceObserver.observe(document.documentElement||document,{childList:true,subtree:true});
	}
}
dpPanelDataSurfaceBoot();
dpPanelListen(window,"pagehide",function(event){if(!event.persisted){dpPanelDataSurfaceRuntime.dispose();}});
JS;
	}
}
