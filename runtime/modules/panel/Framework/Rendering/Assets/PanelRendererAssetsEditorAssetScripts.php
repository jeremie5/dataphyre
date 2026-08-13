<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Emits the lifecycle-safe browser asset-library extension for Panel editors. */
trait PanelRendererAssetsEditorAssetScripts {
	private static function editorAssetScript(): string {
		return <<<'JS'
(function(window,document){
"use strict";
var api=window.DataphyrePanelEditors;
if(!api||api.version!==1){return;}
var bridges=new Map();
var bindings=new WeakMap();
var pickers=new WeakMap();
var trackedEditors=new Set();
var openEditors=new Set();
var observer=null;
var disposed=false;
var controller=typeof AbortController==="function"?new AbortController():null;
var cleanups=[];
function listen(target,name,handler,options){target.addEventListener(name,handler,options||false);cleanups.push(function(){target.removeEventListener(name,handler,options||false);});}
function cleanName(value,fallback){var name=String(value==null?"":value).trim().toLowerCase().replace(/[^a-z0-9_-]+/g,"_").replace(/^_+|_+$/g,"");return name||String(fallback||"");}
function readProfile(editor){if(!editor||!editor.dataset||!editor.dataset.dpPanelEditorProfile){return null;}try{var value=JSON.parse(editor.dataset.dpPanelEditorProfile);return value&&typeof value==="object"&&!Array.isArray(value)?value:null;}catch(error){return null;}}
function descriptor(editor){
	var profile=readProfile(editor),value=profile&&profile.asset_provider,browser=value&&value.browser;
	if(!value||typeof value!=="object"||Array.isArray(value)||value.schema_version!==1||value.enabled===false||value.ready!==true||!browser||browser.schema_version!==1){return null;}
	var driver=cleanName(browser.driver||value.name),endpoint=String(browser.endpoint||"").trim();if(!driver){return null;}
	var capabilities=value.capabilities&&typeof value.capabilities==="object"?value.capabilities:{};
	return {name:cleanName(value.name,"assets"),provider:cleanName(value.provider,"callback"),driver:driver,endpoint:endpoint,
		accepted:Array.isArray(value.accepted)?value.accepted.map(String).slice(0,64):[],maxBytes:Math.max(1,Math.min(1073741824,Number(value.max_bytes||10485760))),
		capabilities:{browse:capabilities.browse===true,read:capabilities.read===true,upload:capabilities.upload===true,delete:capabilities.delete===true,delivery:capabilities.delivery===true},
		verificationRequired:browser.request_verification_required!==false,verificationField:cleanName(browser.verification_field,"csrf"),verificationHeader:/^[A-Za-z][A-Za-z0-9-]{0,63}$/.test(String(browser.verification_header||""))?String(browser.verification_header):"X-CSRF-Token",profile:profile,raw:value};
}
function bridgeFor(editor){var value=descriptor(editor);return value?{descriptor:value,bridge:bridges.get(value.driver)||null}:null;}
function emit(editor,name,detail){editor.dispatchEvent(new CustomEvent(name,{bubbles:true,detail:Object.assign({editor:editor},detail||{})}));}
function verificationToken(editor,value){
	var form=editor.closest("form"),field=value.verificationField,control=form&&field?form.querySelector('[name="'+(window.CSS&&CSS.escape?CSS.escape(field):field.replace(/["\\]/g,"\\$&"))+'"]'):null;
	if(control&&String(control.value||"").trim()!==""){return String(control.value);}
	var meta=document.querySelector('meta[name="'+field.replace(/["\\]/g,"\\$&")+'"]');return meta?String(meta.content||""):"";
}
function safeEndpoint(value){try{if(!value||/^\/\//.test(value)||/[\\\x00-\x20\x7f]/.test(value)){return null;}var url=new URL(value,document.baseURI);if(url.origin!==location.origin||url.username||url.password||url.hash){return null;}return url;}catch(error){return null;}}
function decoded(value){value=String(value||"");for(var pass=0;pass<4;pass++){var next;try{next=decodeURIComponent(value);}catch(error){return null;}if(next===value){return value;}value=next;}try{return decodeURIComponent(value)===value?value:null;}catch(error){return null;}}
function safeAssetUrl(value){
	value=String(value||"").trim();if(!value||value.length>4096||/[\\\x00-\x20\x7f]/.test(value)||/^\/\//.test(value)){return null;}
	try{
		var url=new URL(value,document.baseURI),relative=value.charAt(0)==="/"&&value.charAt(1)!=="/";
		if(url.username||url.password||url.hash||(!relative&&url.protocol!=="https:")){return null;}
		if(relative&&url.origin!==location.origin){return null;}
		var path=decoded(url.pathname);if(path===null||/(?:^|\/)\.\.?(?:\/|$)/.test(path)){return null;}
		var unsafe=false;url.searchParams.forEach(function(unused,key){var clean=decoded(key);if(clean===null||/(?:secret|token|password|authorization|credential|api[_-]?key|access[_-]?key)/i.test(clean)){unsafe=true;}});
		return unsafe?null:value;
	}catch(error){return null;}
}
function normalizeEnvelope(value){if(!value||typeof value!=="object"||Array.isArray(value)||value.type!=="panel_editor_asset_result"||value.schema_version!==1||typeof value.ok!=="boolean"){throw new Error("invalid_response");}return value;}
function httpRequest(context,operation,payload,file){
	var endpoint=safeEndpoint(context.descriptor.endpoint);if(!endpoint){return Promise.reject(new Error("endpoint_invalid"));}
	var token=verificationToken(context.editor,context.descriptor);if(context.descriptor.verificationRequired&&!token){return Promise.reject(new Error("verification_missing"));}
	var options={method:"POST",credentials:"same-origin",cache:"no-store",redirect:"error",referrerPolicy:"same-origin",headers:{"Accept":"application/json"},signal:context.signal};
	if(token){options.headers[context.descriptor.verificationHeader]=token;}
	if(file){var form=new FormData();form.set("operation",operation);form.set("file",file,file.name);if(token&&context.descriptor.verificationField){form.set(context.descriptor.verificationField,token);}options.body=form;}
	else{options.headers["Content-Type"]="application/json";options.body=JSON.stringify(Object.assign({operation:operation},payload||{}));}
	return fetch(endpoint.href,options).then(function(response){var type=String(response.headers.get("content-type")||"").toLowerCase();if(!/^application\/json(?:\s*;|$)/.test(type)){throw new Error("content_type_invalid");}return response.text().then(function(text){if(text.length>2097152){throw new Error("response_too_large");}var body;try{body=JSON.parse(text);}catch(error){throw new Error("response_invalid");}body=normalizeEnvelope(body);if(!response.ok||!body.ok){var failure=new Error(String(body.code||"request_failed"));failure.result=body;throw failure;}return body;});});
}
function httpBridge(){return {browse:function(context,query){return httpRequest(context,"browse",{query:query||{}});},upload:function(context,file){return httpRequest(context,"upload",null,file);},delete:function(context,id){return httpRequest(context,"delete",{id:id});},delivery:function(context,id){return httpRequest(context,"delivery",{id:id});}};}
function contextFor(editor,state){return {editor:editor,profile:state.descriptor.profile,descriptor:state.descriptor,signal:state.controller?state.controller.signal:undefined,request:function(operation,payload,file){return httpRequest(this,operation,payload,file);}};}
function normalizeAsset(value){
	if(!value||typeof value!=="object"||Array.isArray(value)||value.type!=="panel_editor_asset"||value.schema_version!==1){return null;}
	var id=String(value.id||""),assetName=String(value.name||"").trim(),mime=String(value.mime||"application/octet-stream").toLowerCase(),kind=cleanName(value.kind,"file"),url=safeAssetUrl(value.url);
	if(!/^[A-Za-z0-9][A-Za-z0-9._:-]{0,191}$/.test(id)||!assetName||assetName.length>255||!url){return null;}
	return {id:id,name:assetName,url:url,mime:mime,bytes:Math.max(0,Number(value.bytes||0)),kind:["image","video","audio","document","file"].includes(kind)?kind:"file",width:Number(value.width||0),height:Number(value.height||0),alt:String(value.alt||"").slice(0,512),status:String(value.status||"ready")};
}
function pageFrom(result){var page=result&&result.page?result.page:result;if(!page||typeof page!=="object"){throw new Error("page_invalid");}var assets=Array.isArray(page.assets)?page.assets.map(normalizeAsset).filter(Boolean).slice(0,100):[];return {assets:assets,nextCursor:typeof page.next_cursor==="string"?page.next_cursor:"",hasMore:page.has_more===true,total:Number.isFinite(Number(page.total))?Math.max(0,Number(page.total)):null};}
function resultAsset(result){return normalizeAsset(result&&result.asset);}
function button(label,className){var element=document.createElement("button");element.type="button";element.className=className||"";element.textContent=label;return element;}
function setStatus(state,message,tone){state.status.textContent=String(message||"");state.status.dataset.dpPanelEditorAssetsTone=tone||"neutral";}
function setBusy(state,busy){
	busy=!!busy;if(busy&&!state.busy&&state.dialog.contains(document.activeElement)){state.busyFocus=document.activeElement;}
	state.busy=busy;state.dialog.setAttribute("aria-busy",busy?"true":"false");state.dialog.querySelectorAll("button,input").forEach(function(control){if(!control.matches("[data-dp-panel-editor-assets-close]")){control.disabled=busy;}});
	if(!busy&&state.busyFocus){var restore=state.busyFocus;state.busyFocus=null;if(restore.isConnected&&!restore.disabled&&typeof restore.focus==="function"){restore.focus({preventScroll:true});}}
}
function close(editor,reason){var state=pickers.get(editor);if(!state){return false;}state.generation++;if(state.controller){state.controller.abort();}state.controller=null;if(state.busy){setBusy(state,false);}if(state.dialog.open&&typeof state.dialog.close==="function"){state.dialog.close();}else{state.dialog.removeAttribute("open");state.dialog.hidden=true;}state.trigger.setAttribute("aria-expanded","false");openEditors.delete(editor);if(state.lastFocus&&state.lastFocus.isConnected&&typeof state.lastFocus.focus==="function"){state.lastFocus.focus();}emit(editor,"dp-panel-editor:assets-closed",{reason:String(reason||"close")});return true;}
function insertAsset(editor,state,asset){
	var mode=String(editor.dataset.dpPanelEditor||"plain").toLowerCase(),label=asset.alt||asset.name,text=asset.url,html="";
	if(asset.kind==="image"){
		if(mode==="markdown"){text="!["+label.replace(/[\]\\]/g,"\\$&")+"]("+asset.url+")";}
		else if(["html","rich_editor","rich_text"].includes(mode)){var image=document.createElement("img");image.setAttribute("src",asset.url);image.setAttribute("alt",label);image.setAttribute("loading","lazy");if(asset.width>0){image.width=Math.min(100000,asset.width);}if(asset.height>0){image.height=Math.min(100000,asset.height);}html=image.outerHTML;}
	}
	else if(mode==="markdown"){text="["+label.replace(/[\]\\]/g,"\\$&")+"]("+asset.url+")";}
	else if(["html","rich_editor","rich_text"].includes(mode)){var link=document.createElement("a");link.setAttribute("href",asset.url);link.textContent=label;html=link.outerHTML;}
	close(editor,"inserted");emit(editor,"dp-panel-editor:insert",{text:text,html:html,asset:asset});emit(editor,"dp-panel-editor:asset-inserted",{asset:asset});
}
function assetCard(editor,state,asset){
	var card=document.createElement("article");card.className="dp-panel-editor-asset";card.setAttribute("role","listitem");card.dataset.dpPanelEditorAssetId=asset.id;
	var choose=button("","dp-panel-editor-asset-choose");choose.setAttribute("aria-label","Insert "+asset.name);
	var visual=document.createElement("span");visual.className="dp-panel-editor-asset-visual";
	if(asset.kind==="image"){var image=document.createElement("img");image.referrerPolicy="no-referrer";image.loading="lazy";image.alt="";image.src=asset.url;visual.appendChild(image);}else{visual.textContent=asset.kind.slice(0,1).toUpperCase();visual.setAttribute("aria-hidden","true");}
	var copy=document.createElement("span");copy.className="dp-panel-editor-asset-copy";var title=document.createElement("strong");title.textContent=asset.name;var meta=document.createElement("small");meta.textContent=asset.mime+(asset.bytes?" · "+formatBytes(asset.bytes):"");copy.append(title,meta);choose.append(visual,copy);choose.addEventListener("click",function(){insertAsset(editor,state,asset);});card.appendChild(choose);
	if(state.descriptor.capabilities.delete&&typeof state.bridge.delete==="function"){
		var remove=button("Delete","dp-panel-editor-asset-delete");remove.setAttribute("aria-label","Delete "+asset.name);remove.addEventListener("click",function(){
			if(remove.dataset.confirm!=="1"){remove.dataset.confirm="1";remove.textContent="Confirm delete";setTimeout(function(){if(remove.isConnected){remove.dataset.confirm="";remove.textContent="Delete";}},5000);return;}
			mutate(state,"Deleting asset",function(context){return state.bridge.delete(context,asset.id);}).then(function(){card.remove();setStatus(state,"Asset deleted.","success");if(state.dialog.open){state.search.focus({preventScroll:true});}emit(editor,"dp-panel-editor:asset-deleted",{asset:asset});}).catch(function(){setStatus(state,"Asset could not be deleted.","danger");});
		});card.appendChild(remove);
	}
	return card;
}
function formatBytes(bytes){if(bytes<1024){return bytes+" B";}if(bytes<1048576){return (bytes/1024).toFixed(bytes<10240?1:0)+" KB";}return (bytes/1048576).toFixed(bytes<10485760?1:0)+" MB";}
function renderAssets(editor,state,page,append){if(!append){state.grid.textContent="";}page.assets.forEach(function(asset){state.grid.appendChild(assetCard(editor,state,asset));});state.nextCursor=page.nextCursor;state.more.hidden=!page.hasMore;state.empty.hidden=state.grid.children.length>0;setStatus(state,page.assets.length?"Assets ready.":"No assets found.","neutral");}
function mutate(state,label,callback){if(state.busy){return Promise.reject(new Error("busy"));}setBusy(state,true);setStatus(state,label+"…","neutral");var generation=++state.generation;if(state.controller){state.controller.abort();}state.controller=typeof AbortController==="function"?new AbortController():null;var context=contextFor(state.editor,state);var value;try{value=callback(context);}catch(error){value=Promise.reject(error);}return Promise.resolve(value).then(function(result){if(generation!==state.generation||!state.editor.isConnected){throw new Error("stale_request");}return normalizeEnvelope(result);}).finally(function(){if(generation===state.generation){setBusy(state,false);}});}
function browse(editor,state,append){var query={search:String(state.search.value||"").trim().slice(0,128),limit:24};if(append&&state.nextCursor){query.cursor=state.nextCursor;}return mutate(state,"Loading assets",function(context){return state.bridge.browse(context,query);}).then(function(result){renderAssets(editor,state,pageFrom(result),append);}).catch(function(error){if(String(error.message)!=="stale_request"){setStatus(state,"Assets could not be loaded.","danger");}});}
function upload(editor,state,file){if(!file||!state.descriptor.capabilities.upload||typeof state.bridge.upload!=="function"){return;}if(file.size>state.descriptor.maxBytes){setStatus(state,"File exceeds the upload size policy.","danger");return;}if(!accepted(file,state.descriptor.accepted)){setStatus(state,"File type is not accepted.","danger");return;}mutate(state,"Uploading asset",function(context){return state.bridge.upload(context,file);}).then(function(result){var asset=resultAsset(result);if(!asset){throw new Error("asset_invalid");}state.grid.prepend(assetCard(editor,state,asset));state.empty.hidden=true;setStatus(state,"Asset uploaded.","success");emit(editor,"dp-panel-editor:asset-uploaded",{asset:asset});}).catch(function(error){if(String(error.message)!=="stale_request"){setStatus(state,"Asset upload failed.","danger");}}).finally(function(){state.file.value="";});}
function accepted(file,types){if(!types.length){return true;}var mime=String(file.type||"").toLowerCase(),filename=String(file.name||"").toLowerCase();return types.some(function(type){type=String(type).toLowerCase();if(type.charAt(0)==="."){return filename.endsWith(type);}if(type.endsWith("/*")){return mime.startsWith(type.slice(0,-1));}return mime===type;});}
function createPicker(editor,state){
	var dialog=document.createElement("dialog");dialog.className="dp-panel-editor-assets-dialog";dialog.setAttribute("aria-modal","true");dialog.setAttribute("aria-labelledby","dp-panel-editor-assets-title-"+state.id);
	var shell=document.createElement("div");shell.className="dp-panel-editor-assets-shell";var header=document.createElement("header");var heading=document.createElement("h2");heading.id="dp-panel-editor-assets-title-"+state.id;heading.textContent="Media library";var closeButton=button("Close","dp-panel-editor-assets-close");closeButton.dataset.dpPanelEditorAssetsClose="1";closeButton.setAttribute("aria-label","Close media library");header.append(heading,closeButton);
	var search=document.createElement("form");search.className="dp-panel-editor-assets-search";search.setAttribute("role","search");var searchLabel=document.createElement("label");searchLabel.textContent="Search assets";var searchInput=document.createElement("input");searchInput.type="search";searchInput.autofocus=true;searchInput.placeholder="Search by filename";searchInput.autocomplete="off";searchLabel.appendChild(searchInput);var searchButton=button("Search","dp-panel-editor-assets-search-button");search.append(searchLabel,searchButton);
	var uploadRow=document.createElement("div");uploadRow.className="dp-panel-editor-assets-upload";var fileLabel=document.createElement("label");fileLabel.textContent="Upload asset";var file=document.createElement("input");file.type="file";file.accept=state.descriptor.accepted.join(",");fileLabel.appendChild(file);uploadRow.appendChild(fileLabel);
	var status=document.createElement("p");status.className="dp-panel-editor-assets-status";status.setAttribute("role","status");status.setAttribute("aria-live","polite");status.textContent="Choose or upload an asset.";
	var empty=document.createElement("p");empty.className="dp-panel-editor-assets-empty";empty.textContent="No assets found.";empty.hidden=true;var grid=document.createElement("div");grid.className="dp-panel-editor-assets-grid";grid.setAttribute("role","list");grid.setAttribute("aria-label","Available assets");var footer=document.createElement("footer");var more=button("Load more","dp-panel-editor-assets-more");more.hidden=true;var done=button("Done","dp-panel-editor-assets-done");done.dataset.dpPanelEditorAssetsClose="1";footer.append(more,done);
	shell.append(header,search);if(state.descriptor.capabilities.upload&&typeof state.bridge.upload==="function"){shell.appendChild(uploadRow);}shell.append(status,empty,grid,footer);dialog.appendChild(shell);(editor.querySelector("[data-dp-panel-editor-assets-host]")||editor).appendChild(dialog);
	Object.assign(state,{dialog:dialog,searchForm:search,search:searchInput,file:file,status:status,empty:empty,grid:grid,more:more});
	closeButton.addEventListener("click",function(){close(editor,"button");});done.addEventListener("click",function(){close(editor,"button");});dialog.addEventListener("keydown",function(event){if(event.key==="Escape"){event.preventDefault();event.stopPropagation();close(editor,"escape");}});dialog.addEventListener("cancel",function(event){event.preventDefault();close(editor,"escape");});dialog.addEventListener("close",function(){state.trigger.setAttribute("aria-expanded","false");openEditors.delete(editor);});
	search.addEventListener("submit",function(event){event.preventDefault();browse(editor,state,false);});file.addEventListener("change",function(){upload(editor,state,file.files&&file.files[0]);});more.addEventListener("click",function(){browse(editor,state,true);});
}
var sequence=0;
function open(editor){
	var resolved=bridgeFor(editor),binding=bindings.get(editor);if(!resolved||!resolved.bridge||typeof resolved.bridge.browse!=="function"||!binding){return Promise.resolve(false);}
	var state=pickers.get(editor);if(!state){state={id:++sequence,editor:editor,trigger:binding.trigger,descriptor:resolved.descriptor,bridge:resolved.bridge,controller:null,generation:0,busy:false,nextCursor:"",lastFocus:null};createPicker(editor,state);pickers.set(editor,state);}else{state.descriptor=resolved.descriptor;state.bridge=resolved.bridge;}
	state.lastFocus=document.activeElement;state.trigger.setAttribute("aria-expanded","true");state.dialog.hidden=false;if(typeof state.dialog.showModal==="function"&&!state.dialog.open){state.dialog.showModal();}else{state.dialog.setAttribute("open","");}openEditors.add(editor);state.search.focus();emit(editor,"dp-panel-editor:assets-opened",{provider:state.descriptor.name});return browse(editor,state,false).then(function(){if(state.dialog.open&&state.search.isConnected){state.search.focus({preventScroll:true});}return true;});
}
function refresh(editor){
	if(!editor||!editor.matches||!editor.matches("[data-dp-panel-editor]")){return;}
	var trigger=editor.querySelector("[data-dp-panel-editor-assets-trigger]"),resolved=bridgeFor(editor),existing=bindings.get(editor);if(!trigger){return;}
	if(existing&&existing.trigger!==trigger){existing.cleanup();existing=null;}
	if(!existing){var click=function(){open(editor);};trigger.addEventListener("click",click);var unmounted=function(){close(editor,"editor_unmounted");};editor.addEventListener("dp-panel-editor:adapter-unmounted",unmounted);existing={trigger:trigger,cleanup:function(){trigger.removeEventListener("click",click);editor.removeEventListener("dp-panel-editor:adapter-unmounted",unmounted);}};bindings.set(editor,existing);trackedEditors.add(editor);}
	var ready=!!(resolved&&resolved.bridge&&typeof resolved.bridge.browse==="function");trigger.hidden=!ready;trigger.disabled=!ready;editor.dataset.dpPanelEditorAssetsState=ready?"ready":"unavailable";if(!ready){close(editor,"provider_unavailable");}
}
function editors(root){var rows=[];if(root&&root.matches&&root.matches("[data-dp-panel-editor]")){rows.push(root);}if(root&&root.querySelectorAll){rows=rows.concat(Array.prototype.slice.call(root.querySelectorAll("[data-dp-panel-editor]")));}return rows;}
function refreshAll(root){editors(root||document).forEach(refresh);}
function release(editor,reason){close(editor,reason||"release");var state=pickers.get(editor);if(state){state.dialog.remove();pickers.delete(editor);}var binding=bindings.get(editor);if(binding){binding.trigger.hidden=true;binding.trigger.disabled=true;binding.trigger.setAttribute("aria-expanded","false");binding.cleanup();bindings.delete(editor);}trackedEditors.delete(editor);delete editor.dataset.dpPanelEditorAssetsState;}
function register(driver,bridge){driver=cleanName(driver);if(!driver||!bridge||typeof bridge.browse!=="function"){throw new TypeError("A Panel editor asset bridge requires a name and browse function.");}bridges.set(driver,bridge);refreshAll(document);return api;}
function unregister(driver){driver=cleanName(driver);bridges.delete(driver);Array.from(trackedEditors).forEach(refresh);return api;}
function stateFor(editor){var descriptorValue=descriptor(editor),state=pickers.get(editor);return descriptorValue&&bindings.has(editor)?{driver:descriptorValue.driver,ready:editor.dataset.dpPanelEditorAssetsState==="ready",open:!!(state&&state.dialog.open),busy:!!(state&&state.busy),asset_count:state?state.grid.children.length:0}:null;}
function disposeAssets(){if(disposed){return;}disposed=true;if(observer){observer.disconnect();observer=null;}if(controller){controller.abort();}cleanups.splice(0).forEach(function(cleanup){cleanup();});Array.from(trackedEditors).forEach(function(editor){release(editor,"runtime_dispose");});bridges.clear();}
bridges.set("http",httpBridge());
var previousDispose=api.dispose,previousList=api.list;
api.registerAssets=register;api.unregisterAssets=unregister;api.openAssets=open;api.closeAssets=close;api.assetState=stateFor;api.createHttpAssetBridge=httpBridge;
api.list=function(){var list=typeof previousList==="function"?previousList():{};list.assets=Array.from(bridges.keys()).sort();return list;};
api.dispose=function(){disposeAssets();if(typeof previousDispose==="function"){previousDispose();}};
function boot(){if(disposed){return;}refreshAll(document);listen(document,"dp-panel-editor:ready",function(event){var editor=event.target&&event.target.closest?event.target.closest("[data-dp-panel-editor]"):null;if(editor){refresh(editor);}},{signal:controller?controller.signal:undefined});if(typeof MutationObserver==="function"&&document.documentElement){observer=new MutationObserver(function(mutations){mutations.forEach(function(mutation){mutation.addedNodes.forEach(function(node){if(node.nodeType===1){refreshAll(node);}});mutation.removedNodes.forEach(function(node){if(node.nodeType!==1){return;}editors(node).forEach(function(editor){queueMicrotask(function(){if(!editor.isConnected){release(editor,"detached");}});});});});});observer.observe(document.documentElement,{childList:true,subtree:true});}}
if(document.readyState==="loading"){listen(document,"DOMContentLoaded",boot,{once:true,signal:controller?controller.signal:undefined});}else{Promise.resolve().then(boot);}
})(window,document);
JS;
	}
}
