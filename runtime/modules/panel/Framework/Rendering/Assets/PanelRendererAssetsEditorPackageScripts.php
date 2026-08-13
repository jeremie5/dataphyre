<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Emits the separately cacheable editor-package extension runtime. */
trait PanelRendererAssetsEditorPackageScripts {
	private static function editorPackageScript(): string {
		return <<<'JS'
(function(window,document){
"use strict";
var previous=window.DataphyrePanelEditors;
if(previous&&typeof previous.dispose==="function"){try{previous.dispose();}catch(error){}}
var surfaceBridges=new Map();
var syntaxBridges=new Map();
var states=new WeakMap();
var ownedEditors=new Set();
var observer=null;
var disposed=false;
var lifecycleController=typeof AbortController==="function"?new AbortController():null;
function name(value,fallback){
	var normalized=String(value==null?"":value).trim().toLowerCase().replace(/[^a-z0-9_-]+/g,"_").replace(/^_+|_+$/g,"");
	return normalized||String(fallback||"");
}
function listenGlobal(target,eventName,listener,options){target.addEventListener(eventName,listener,options);}
function profile(editor){
	if(!editor||!editor.dataset||!editor.dataset.dpPanelEditorProfile){return null;}
	try{var value=JSON.parse(editor.dataset.dpPanelEditorProfile);return value&&typeof value==="object"&&!Array.isArray(value)?value:null;}catch(error){return null;}
}
function descriptor(editor,key,kind){
	var value=profile(editor);value=value&&value[key];
	if(!value||typeof value!=="object"||Array.isArray(value)||name(value.kind)!==kind||value.enabled===false||value.configured===false){return null;}
	var driver=name(value.driver||value.name);
	if(!driver){return null;}
	return {
		schemaVersion:Number(value.schema_version||1),kind:kind,name:name(value.name,driver),driver:driver,
		strategy:name(value.strategy,"registry"),fallback:["native","source","error"].includes(name(value.fallback))?name(value.fallback):kind==="syntax"?"source":"native",
		modes:Array.isArray(value.modes)?value.modes.map(function(item){return name(item);}).filter(Boolean):[],
		languages:Array.isArray(value.languages)?value.languages.map(function(item){return String(item)==="*"?"*":name(item);}).filter(Boolean):[],
		capabilities:Array.isArray(value.capabilities)?value.capabilities.map(function(item){return name(item);}).filter(Boolean):[],
		requiredGlobals:Array.isArray(value.required_globals)?value.required_globals.map(String).filter(function(path){return /^[A-Za-z_$][A-Za-z0-9_$]*(\.[A-Za-z_$][A-Za-z0-9_$]*)*$/.test(path);}):[],
		options:safeOptions(value.options),raw:value
	};
}
function safeOptions(value,depth){
	depth=depth||0;
	if(value===undefined){return {};}
	if(value===null){return null;}
	if(depth>6){return null;}
	if(typeof value==="string"){return value.slice(0,4096);}
	if(typeof value==="number"||typeof value==="boolean"){return value;}
	if(Array.isArray(value)){return value.slice(0,128).map(function(item){return safeOptions(item,depth+1);}).filter(function(item){return item!==null;});}
	if(typeof value!=="object"){return null;}
	var clean={};var count=0;
	Object.keys(value).some(function(key){
		if(count++>=128){return true;}
		var normalized=key.replace(/([a-z0-9])([A-Z])/g,"$1_$2").toLowerCase().replace(/[.-]/g,"_");
		if(/(^|_)(secret|token|password|passwd|authorization|cookie|credential|private_key|signing_key|api_key|access_key|csrf|callback|callable|closure|handler|resolver|factory)($|_)/.test(normalized)){return false;}
		var item=safeOptions(value[key],depth+1);if(item!==null){clean[key]=item;}return false;
	});
	return clean;
}
function resolveGlobal(path){
	var current=window;
	for(var part of String(path||"").split(".")){if(!part||current==null||!(part in Object(current))){return null;}current=current[part];}
	return current;
}
function globalsAvailable(adapter){return adapter.requiredGlobals.every(function(path){return resolveGlobal(path)!=null;});}
function supports(adapter,value,key){
	var declared=adapter[key]||[];if(!declared.length||declared.includes("*")){return true;}
	return declared.includes(name(value));
}
function dispatch(editor,eventName,detail,cancelable){
	return editor.dispatchEvent(new CustomEvent(eventName,{bubbles:true,cancelable:!!cancelable,detail:Object.assign({editor:editor},detail||{})}));
}
function sourceFor(editor){return editor&&editor.querySelector("[data-dp-panel-editor-source]");}
function sourceShellFor(source){return source?(source.closest("[data-dp-panel-input-shell],.dp-panel-input-shell")||source):null;}
function hostFor(editor){
	var host=editor.querySelector("[data-dp-panel-editor-external-host]");
	if(host){return {element:host,created:false};}
	host=document.createElement("div");host.className="dp-panel-editor-external-host";host.hidden=true;host.setAttribute("aria-hidden","true");host.dataset.dpPanelEditorExternalHost="1";
	var visual=editor.querySelector("[data-dp-panel-editor-visual]");
	if(visual){visual.insertAdjacentElement("afterend",host);}else{editor.appendChild(host);}
	return {element:host,created:true};
}
function captureNative(editor,source,host){
	var visual=editor.querySelector("[data-dp-panel-editor-visual]");var sourceShell=sourceShellFor(source);var modeSwitch=editor.querySelector(".dp-panel-editor-mode-switch");
	return {visual:visual,visualHidden:visual?visual.hidden:null,visualAria:visual?visual.getAttribute("aria-hidden"):null,sourceShell:sourceShell,sourceHidden:sourceShell?sourceShell.hidden:null,sourceAria:sourceShell?sourceShell.getAttribute("aria-hidden"):null,modeSwitch:modeSwitch,modeSwitchHidden:modeSwitch?modeSwitch.hidden:null,mode:editor.dataset.dpPanelEditorMode||"source",host:host};
}
function attribute(element,key,value){if(!element){return;}if(value===null){element.removeAttribute(key);}else{element.setAttribute(key,value);}}
function setState(editor,stateName,detail){
	editor.dataset.dpPanelEditorAdapterState=stateName;
	if(stateName==="mounting"){editor.setAttribute("aria-busy","true");}else{editor.removeAttribute("aria-busy");}
	dispatch(editor,"dp-panel-editor:adapter-"+stateName,detail||{});
}
function restoreNative(editor,state,fallback){
	var nativeState=state.native;var visual=nativeState.visual;var sourceShell=nativeState.sourceShell;var modeSwitch=nativeState.modeSwitch;var host=nativeState.host;
	if(host){host.hidden=true;host.setAttribute("aria-hidden","true");}
	if(modeSwitch){modeSwitch.hidden=nativeState.modeSwitchHidden===true;}
	if(fallback==="source"||fallback==="error"){
		if(visual){visual.hidden=true;visual.setAttribute("aria-hidden","true");}
		if(sourceShell){sourceShell.hidden=false;sourceShell.removeAttribute("aria-hidden");}
		editor.dataset.dpPanelEditorMode="source";
	}
	else{
		if(visual){visual.hidden=nativeState.visualHidden===true;attribute(visual,"aria-hidden",nativeState.visualAria);}
		if(sourceShell){sourceShell.hidden=nativeState.sourceHidden===true;attribute(sourceShell,"aria-hidden",nativeState.sourceAria);}
		editor.dataset.dpPanelEditorMode=nativeState.mode;
	}
	if(state.source){if(fallback==="error"){state.source.setAttribute("aria-invalid","true");}else{state.source.removeAttribute("aria-invalid");}}
	editor.classList.remove("dp-panel-editor-external");
}
function activateExternal(editor,state){
	var nativeState=state.native;var host=nativeState.host;
	if(nativeState.visual){nativeState.visual.hidden=true;nativeState.visual.setAttribute("aria-hidden","true");}
	if(nativeState.sourceShell){nativeState.sourceShell.hidden=true;nativeState.sourceShell.setAttribute("aria-hidden","true");}
	if(nativeState.modeSwitch){nativeState.modeSwitch.hidden=true;}
	state.source.removeAttribute("aria-invalid");host.hidden=false;host.setAttribute("aria-hidden","false");host.dataset.dpPanelEditorAdapter=state.adapter.driver;
	editor.classList.add("dp-panel-editor-external");editor.dataset.dpPanelEditorAdapter=state.adapter.driver;
}
function invokeCleanup(state){
	while(state.cleanup.length){try{state.cleanup.pop()();}catch(error){}}
}
function destroyInstance(state){
	var result=null;
	try{
		if(state.bridge&&typeof state.bridge.unmount==="function"){result=state.bridge.unmount(state.instance,state.context);}
		else if(state.instance&&typeof state.instance.destroy==="function"){result=state.instance.destroy();}
		else if(state.instance&&typeof state.instance.dispose==="function"){result=state.instance.dispose();}
	}catch(error){result=null;}
	if(result&&typeof result.catch==="function"){result.catch(function(){});}
	return result;
}
function unmount(editor,options){
	options=options||{};var state=states.get(editor);if(!state){return false;}
	state.token++;if(state.controller){state.controller.abort();}invokeCleanup(state);var destruction=destroyInstance(state);
	states.delete(editor);ownedEditors.delete(editor);
	if(options.restore!==false&&editor.isConnected){restoreNative(editor,state,state.adapter.fallback);}
	if(state.native.host){
		var retiredHost=state.native.host;
		var cleanHost=function(){retiredHost.textContent="";if((state.createdHost||destruction&&typeof destruction.then==="function")&&options.removeHost!==false){retiredHost.remove();}};
		if(destruction&&typeof destruction.then==="function"){
			retiredHost.removeAttribute("data-dp-panel-editor-external-host");retiredHost.removeAttribute("data-dp-panel-editor-adapter");
			if(editor.isConnected){var replacement=hostFor(editor).element;replacement.hidden=true;replacement.setAttribute("aria-hidden","true");}
			Promise.resolve(destruction).then(cleanHost,cleanHost);
		}else{cleanHost();}
	}
	delete editor.dataset.dpPanelEditorAdapter;delete editor.dataset.dpPanelEditorAdapterState;editor.removeAttribute("aria-busy");
	dispatch(editor,"dp-panel-editor:adapter-unmounted",{adapter:state.adapter,reason:String(options.reason||"unmount")});return true;
}
function emitSource(state,value){
	if(!state.source||states.get(state.editor)!==state){return false;}
	value=String(value==null?"":value);state.syncing=true;
	try{state.source.value=value;state.source.dispatchEvent(new Event("input",{bubbles:true}));}finally{state.syncing=false;}
	return true;
}
function contextFor(state){
	return {
		editor:state.editor,source:state.source,host:state.native.host,profile:profile(state.editor),descriptor:state.adapter,
		options:state.adapter.options,signal:state.controller?state.controller.signal:null,
		mode:name(state.editor.dataset.dpPanelEditor||"plain"),language:name(state.editor.dataset.dpPanelCodeLanguage||"plain","plain"),
		read:function(){return String(state.source.value||"");},
		change:function(value){return emitSource(state,value);},
		dispatch:function(eventName,detail){return dispatch(state.editor,eventName,detail||{});}
	};
}
function failMount(editor,state,phase,reason,error){
	if(states.get(editor)!==state){return false;}
	state.phase=phase;restoreNative(editor,state,state.adapter.fallback);setState(editor,phase,{adapter:state.adapter,reason:reason,error:error?String(error.message||error):""});return false;
}
function normalizeBridge(bridge){return typeof bridge==="function"?{mount:bridge}:bridge;}
function mount(editor){
	if(disposed||!editor){return Promise.resolve(false);}
	var adapter=descriptor(editor,"browser_adapter","surface");if(!adapter){return Promise.resolve(false);}
	var mode=name(editor.dataset.dpPanelEditor||"plain");
	var existing=states.get(editor);
	if(existing&&(existing.phase==="mounting"||existing.phase==="ready")&&existing.adapter.driver===adapter.driver){return Promise.resolve(existing.phase==="ready");}
	if(existing){unmount(editor,{reason:"remount"});}
	var source=sourceFor(editor);if(!source){return Promise.resolve(false);}
	var ownedHost=hostFor(editor);var bridge=normalizeBridge(surfaceBridges.get(adapter.driver));
	var state={editor:editor,source:source,adapter:adapter,bridge:bridge,instance:null,context:null,native:captureNative(editor,source,ownedHost.element),createdHost:ownedHost.created,cleanup:[],syncing:false,phase:"mounting",token:1,controller:typeof AbortController==="function"?new AbortController():null};
	state.context=contextFor(state);states.set(editor,state);ownedEditors.add(editor);setState(editor,"mounting",{adapter:adapter});
	if(adapter.schemaVersion!==1){return Promise.resolve(failMount(editor,state,"unavailable","unsupported_schema"));}
	if(!supports(adapter,mode,"modes")){return Promise.resolve(failMount(editor,state,"unavailable","unsupported_mode"));}
	if(!bridge||typeof bridge.mount!=="function"){return Promise.resolve(failMount(editor,state,"unavailable","missing_bridge"));}
	if(!globalsAvailable(adapter)){return Promise.resolve(failMount(editor,state,"unavailable","missing_global"));}
	var token=state.token;var mounted;
	try{mounted=bridge.mount(state.context);}catch(error){return Promise.resolve(failMount(editor,state,"error","mount_exception",error));}
	return Promise.resolve(mounted).then(function(instance){
		if(states.get(editor)!==state||state.token!==token||state.controller&&state.controller.signal.aborted||!editor.isConnected){
			state.instance=instance||null;
			if(states.get(editor)===state){unmount(editor,{restore:false,reason:"stale_mount"});}else{destroyInstance(state);}
			return false;
		}
		if(!instance||typeof instance!=="object"){return failMount(editor,state,"error","invalid_instance");}
		state.instance=instance;state.phase="ready";
		var sourceInput=function(){if(state.syncing||states.get(editor)!==state||typeof instance.setValue!=="function"){return;}try{instance.setValue(String(source.value||""));}catch(error){}};
		source.addEventListener("input",sourceInput);state.cleanup.push(function(){source.removeEventListener("input",sourceInput);});
		var commandListener=function(event){if(!event.detail||states.get(editor)!==state){return;}if(command(editor,event.detail.command,event.detail)){event.preventDefault();}};
		editor.addEventListener("dp-panel-editor:command",commandListener);state.cleanup.push(function(){editor.removeEventListener("dp-panel-editor:command",commandListener);});
		var form=source.form||editor.closest("form");
		if(form){
			var commit=function(){sync(editor);};var formData=function(event){sync(editor);if(event.formData&&source.name){event.formData.set(source.name,source.value);}};
			form.addEventListener("submit",commit,true);form.addEventListener("formdata",formData);
			state.cleanup.push(function(){form.removeEventListener("submit",commit,true);form.removeEventListener("formdata",formData);});
		}
		activateExternal(editor,state);setState(editor,"ready",{adapter:adapter});return true;
	},function(error){return failMount(editor,state,"error","mount_rejected",error);});
}
function sync(editor){
	var state=states.get(editor);if(!state||state.phase!=="ready"||!state.instance||typeof state.instance.getValue!=="function"){return false;}
	try{var value=state.instance.getValue();if(value&&typeof value.then==="function"){return false;}return emitSource(state,value);}catch(error){setState(editor,"error",{adapter:state.adapter,reason:"read_failed",error:String(error.message||error)});return false;}
}
function command(editor,commandName,detail){
	var state=states.get(editor);if(!state||state.phase!=="ready"||!state.instance||typeof state.instance.command!=="function"){return false;}
	try{var handled=state.instance.command(name(commandName),detail||{});if(handled&&typeof handled.then==="function"){handled.then(function(){sync(editor);},function(){});return true;}if(handled){sync(editor);return true;}return false;}catch(error){return false;}
}
function insertExternal(editor,detail){
	var state=states.get(editor);if(!state||state.phase!=="ready"||!state.instance){return false;}
	try{
		var handled=typeof state.instance.insert==="function"?state.instance.insert(detail||{}):false;
		if(handled&&typeof handled.then==="function"){handled.then(function(){sync(editor);},function(){});return true;}
		if(handled){sync(editor);return true;}return false;
	}catch(error){return false;}
}
function getState(editor){
	var state=states.get(editor);return state?{driver:state.adapter.driver,phase:state.phase,fallback:state.adapter.fallback,mounted:state.phase==="ready"}:null;
}
function editorsBelow(root){
	var found=[];if(root&&root.matches&&root.matches("[data-dp-panel-editor]")){found.push(root);}
	if(root&&root.querySelectorAll){found=found.concat(Array.prototype.slice.call(root.querySelectorAll("[data-dp-panel-editor]")));}
	return found.filter(function(editor,index,array){return array.indexOf(editor)===index;});
}
function containsEditors(root){return !!(root&&((root.matches&&root.matches("[data-dp-panel-editor]"))||(root.querySelector&&root.querySelector("[data-dp-panel-editor]"))));}
function mountAll(root){return Promise.all(editorsBelow(root||document).map(mount));}
function unmountAll(root,options){editorsBelow(root||document).forEach(function(editor){unmount(editor,options);});}
function retryDriver(driver){editorsBelow(document).forEach(function(editor){var adapter=descriptor(editor,"browser_adapter","surface");if(adapter&&adapter.driver===driver){mount(editor);}});}
function registerSurface(driver,bridge){driver=name(driver);bridge=normalizeBridge(bridge);if(!driver||!bridge||typeof bridge.mount!=="function"){throw new TypeError("A Panel editor surface bridge requires a name and mount function.");}surfaceBridges.set(driver,bridge);retryDriver(driver);return api;}
function unregisterSurface(driver){driver=name(driver);surfaceBridges.delete(driver);Array.from(ownedEditors).forEach(function(editor){var state=states.get(editor);if(state&&state.adapter.driver===driver){unmount(editor,{reason:"bridge_unregistered"});mount(editor);}});return api;}
function registerSyntax(driver,bridge){driver=name(driver);bridge=normalizeBridge(bridge);if(!driver||!bridge||typeof bridge.tokens!=="function"){throw new TypeError("A Panel syntax bridge requires a name and tokens function.");}syntaxBridges.set(driver,bridge);return api;}
function unregisterSyntax(driver){syntaxBridges.delete(name(driver));return api;}
function syntaxTokens(editor,code,language){
	var adapter=descriptor(editor,"browser_syntax","syntax");if(!adapter||adapter.schemaVersion!==1||!supports(adapter,language,"languages")||!globalsAvailable(adapter)){return null;}
	var bridge=syntaxBridges.get(adapter.driver);if(!bridge||typeof bridge.tokens!=="function"){return null;}
	try{var result=bridge.tokens({editor:editor,profile:profile(editor),descriptor:adapter,options:adapter.options,code:String(code),language:name(language,"plain")});return Array.isArray(result)?result:null;}catch(error){return null;}
}
function list(){return {version:1,surfaces:Array.from(surfaceBridges.keys()).sort(),syntax:Array.from(syntaxBridges.keys()).sort()};}
function genericModuleBridge(factory){
	if(typeof factory!=="function"){throw new TypeError("A module editor bridge requires a factory function.");}
	return {mount:function(context){return factory(context);}};
}
function tinyMceBridge(){
	return {mount:function(context){
		var engine=resolveGlobal(context.descriptor.requiredGlobals[0]||"tinymce");if(!engine||typeof engine.init!=="function"){throw new Error("TinyMCE is unavailable.");}
		var editorInstance=null;var options=Object.assign({},context.options,{target:context.host});delete options.selector;delete options.setup;delete options.init_instance_callback;
		options.setup=function(instance){editorInstance=instance;instance.on("init",function(){instance.setContent(context.read());});instance.on("change input undo redo",function(){context.change(instance.getContent());});};
		return Promise.resolve(engine.init(options)).then(function(instances){
			var instance=editorInstance||(Array.isArray(instances)?instances[0]:instances);if(!instance){throw new Error("TinyMCE did not return an editor instance.");}
			var commands={bold:"Bold",italic:"Italic",underline:"Underline",strike:"Strikethrough",undo:"Undo",redo:"Redo",unordered_list:"InsertUnorderedList",ordered_list:"InsertOrderedList",unlink:"Unlink",clear_format:"RemoveFormat",link:"mceLink"};
			return {getValue:function(){return instance.getContent();},setValue:function(value){if(instance.getContent()!==value){instance.setContent(value);}},focus:function(){instance.focus();},insert:function(detail){instance.insertContent(detail.html||detail.text||"");return true;},command:function(commandName){if(/^heading_[1-6]$/.test(commandName)){instance.execCommand("FormatBlock",false,"h"+commandName.slice(-1));return true;}if(commandName==="paragraph"){instance.execCommand("FormatBlock",false,"p");return true;}var mapped=commands[commandName];if(!mapped){return false;}instance.execCommand(mapped);return true;},destroy:function(){if(typeof instance.remove==="function"){return instance.remove();}}};
		});
	}};
}
function ckEditor5Bridge(){
	return {mount:function(context){
		var Constructor=resolveGlobal(context.descriptor.requiredGlobals[0]||"ClassicEditor");if(!Constructor||typeof Constructor.create!=="function"){throw new Error("CKEditor 5 is unavailable.");}
		var options=Object.assign({},context.options);delete options.initialData;
		return Promise.resolve(Constructor.create(context.host,Object.assign({initialData:context.read()},options))).then(function(instance){
			if(instance.model&&instance.model.document&&typeof instance.model.document.on==="function"){instance.model.document.on("change:data",function(){context.change(instance.getData());});}
			var commands={bold:"bold",italic:"italic",underline:"underline",strike:"strikethrough",undo:"undo",redo:"redo",unordered_list:"bulletedList",ordered_list:"numberedList",link:"link",unlink:"unlink"};
			return {getValue:function(){return instance.getData();},setValue:function(value){if(instance.getData()!==value){instance.setData(value);}},focus:function(){if(instance.editing&&instance.editing.view){instance.editing.view.focus();}},command:function(commandName,detail){var mapped=commands[commandName]||commandName;if(!instance.commands||!instance.commands.get(mapped)){return false;}instance.execute(mapped,detail&&detail.value!==undefined?detail.value:undefined);return true;},destroy:function(){return instance.destroy();}};
		});
	}};
}
function monacoBridge(){
	return {mount:function(context){
		var engine=resolveGlobal(context.descriptor.requiredGlobals[0]||"monaco.editor");if(!engine||typeof engine.create!=="function"){throw new Error("Monaco is unavailable.");}
		var options=Object.assign({},context.options,{value:context.read(),language:context.language});delete options.model;
		var instance=engine.create(context.host,options);var subscription=instance.onDidChangeModelContent(function(){context.change(instance.getValue());});
		return {getValue:function(){return instance.getValue();},setValue:function(value){if(instance.getValue()!==value){instance.setValue(value);}},focus:function(){instance.focus();},insert:function(detail){var selection=instance.getSelection();instance.executeEdits("dataphyre-panel",[{range:selection,text:String(detail.text||detail.html||""),forceMoveMarkers:true}]);return true;},command:function(commandName){var aliases={undo:"undo",redo:"redo",find:"actions.find",replace:"editor.action.startFindReplaceAction",format:"editor.action.formatDocument"};var action=instance.getAction(aliases[commandName]||commandName);if(!action){return false;}action.run();return true;},destroy:function(){if(subscription&&typeof subscription.dispose==="function"){subscription.dispose();}instance.dispose();}};
	}};
}
function tokenType(value,fallback){return name(String(value||""),fallback||"plain");}
function prismBridge(){
	return {tokens:function(context){
		var Prism=resolveGlobal(context.descriptor.requiredGlobals[0]||"Prism");var grammar=Prism&&Prism.languages&&Prism.languages[context.language];if(!Prism||typeof Prism.tokenize!=="function"||!grammar){return null;}
		var output=[];function flatten(node,parent){if(typeof node==="string"){output.push({type:parent||"plain",text:node});return;}if(Array.isArray(node)){node.forEach(function(child){flatten(child,parent);});return;}if(node&&typeof node==="object"){flatten(node.content,tokenType(node.type,parent||"plain"));}}
		flatten(Prism.tokenize(context.code,grammar),"plain");return output;
	}};
}
function highlightJsBridge(){
	return {tokens:function(context){
		var hljs=resolveGlobal(context.descriptor.requiredGlobals[0]||"hljs");if(!hljs||typeof hljs.highlight!=="function"){return null;}
		var result;try{result=hljs.highlight(context.code,{language:context.language,ignoreIllegals:true});}catch(error){return null;}
		var output=[];var root=result&&result._emitter&&result._emitter.rootNode;
		function flatten(node,parent){if(typeof node==="string"){output.push({type:parent||"plain",text:node});return;}if(!node){return;}var type=tokenType(node.scope,parent||"plain");if(Array.isArray(node.children)){node.children.forEach(function(child){flatten(child,type);});}}
		if(root){flatten(root,"plain");return output;}
		var template=document.createElement("template");template.innerHTML=String(result&&result.value||"");
		function walk(node,parent){if(node.nodeType===3){output.push({type:parent||"plain",text:node.nodeValue||""});return;}if(node.nodeType!==1&&node.nodeType!==11){return;}var type=parent||"plain";if(node.nodeType===1){var match=Array.from(node.classList||[]).find(function(item){return item.indexOf("hljs-")===0;});if(match){type=tokenType(match.slice(5),type);}}Array.from(node.childNodes||[]).forEach(function(child){walk(child,type);});}
		walk(template.content,"plain");return output;
	}};
}
surfaceBridges.set("tinymce",tinyMceBridge());
surfaceBridges.set("ckeditor5",ckEditor5Bridge());
surfaceBridges.set("monaco",monacoBridge());
syntaxBridges.set("prism",prismBridge());
syntaxBridges.set("highlightjs",highlightJsBridge());
var api={
	version:1,registerSurface:registerSurface,unregisterSurface:unregisterSurface,registerSyntax:registerSyntax,unregisterSyntax:unregisterSyntax,
	mount:mount,mountAll:mountAll,unmount:unmount,unmountAll:unmountAll,sync:sync,command:command,insert:insertExternal,syntaxTokens:syntaxTokens,state:getState,list:list,
	createTiptapBridge:genericModuleBridge,createCodeMirror6Bridge:genericModuleBridge,
	dispose:function(){if(disposed){return;}disposed=true;if(observer){observer.disconnect();observer=null;}if(lifecycleController){lifecycleController.abort();}Array.from(ownedEditors).forEach(function(editor){unmount(editor,{reason:"runtime_dispose"});});}
};
window.DataphyrePanelEditors=api;
function insert(editor,detail){
	if(!editor||!detail||typeof detail!=="object"){return;}
	var core=window.DataphyrePanel&&window.DataphyrePanel.editorRuntime&&window.DataphyrePanel.editorRuntime.version===1?window.DataphyrePanel.editorRuntime:null;
	var mode=String(editor.dataset.dpPanelEditor||"plain").toLowerCase();
	var visual=editor.querySelector("[data-dp-panel-editor-visual]");
	var source=editor.querySelector("[data-dp-panel-editor-source]");
	var text=detail.text==null?"":String(detail.text);
	if(detail.asset&&typeof detail.asset==="object"&&detail.asset.kind==="image"&&core&&typeof core.allowMediaReference==="function"){core.allowMediaReference(editor,detail.asset.url||"");}
	var html=detail.html==null?"":(core&&typeof core.sanitizeRichHtml==="function"?core.sanitizeRichHtml(editor,String(detail.html)):"");
	if(api.insert(editor,{text:text,html:html})){return;}
	if(visual&&editor.dataset.dpPanelEditorMode==="write"){
		if(!core){return;}core.restoreSelection(editor);document.execCommand(html!==""?"insertHTML":"insertText",false,html||text);core.syncFromVisual(editor);return;
	}
	if(!source){return;}
	if(html!==""&&!["html","rich_editor","rich_text"].includes(mode)){var template=document.createElement("template");template.innerHTML=html;text=template.content.textContent||"";html="";}
	if(core){core.replaceSelection(source,html||text);core.renderPreview(editor);}
}
window.dpPanelEditorProfile=profile;
window.dpPanelEditorDispatchCommand=function(editor,button,commandName){
	var builtins=["undo","redo","paragraph","heading","heading_1","heading_2","heading_3","bold","italic","underline","strike","link","unlink","unordered_list","ordered_list","quote","code","code_block","hr","clear_format"];
	var detail={editor:editor,source:editor&&editor.querySelector("[data-dp-panel-editor-source]"),visual:editor&&editor.querySelector("[data-dp-panel-editor-visual]"),command:String(commandName||""),plugin:button&&button.dataset?String(button.dataset.dpPanelEditorPlugin||""):"",profile:profile(editor)};
	return editor.dispatchEvent(new CustomEvent("dp-panel-editor:command",{bubbles:true,cancelable:true,detail:detail}))&&detail.plugin===""&&builtins.includes(detail.command);
};
window.dpPanelEditorRenderSyntaxTokens=function(editor,preview,value){
	var detail={editor:editor,profile:profile(editor),language:String(editor.dataset.dpPanelCodeLanguage||"plain"),code:value,tokens:null};
	editor.dispatchEvent(new CustomEvent("dp-panel-editor:highlight",{bubbles:true,detail:detail}));
	if(!Array.isArray(detail.tokens)){detail.tokens=api.syntaxTokens(editor,value,detail.language);}
	if(!Array.isArray(detail.tokens)){return false;}
	var tokens=[],joined="";
	detail.tokens.slice(0,100000).forEach(function(token){
		if(!token||typeof token!=="object"){return;}
		var text=String(token.text==null?"":token.text);
		var type=String(token.type||"plain").toLowerCase().replace(/[^a-z0-9_-]+/g,"_").replace(/^_+|_+$/g,"")||"plain";
		tokens.push({type:type,text:text});joined+=text;
	});
	if(!tokens.length||joined!==value){return false;}
	preview.textContent="";var code=document.createElement("code");
	tokens.forEach(function(token){var span=document.createElement("span");span.className="dp-panel-token dp-panel-token-"+token.type;span.textContent=token.text;code.appendChild(span);});
	preview.appendChild(code);return true;
};
window.dpPanelEditorInitProfile=function(editor){
	if(!editor){return;}
	if(editor.dataset.dpPanelEditorProfileReady!=="1"){
		editor.dataset.dpPanelEditorProfileReady="1";
		editor.addEventListener("dp-panel-editor:insert",function(event){insert(editor,event.detail||{});});
		editor.dispatchEvent(new CustomEvent("dp-panel-editor:ready",{bubbles:true,detail:{editor:editor,profile:profile(editor)}}));
	}
	api.mount(editor);
};
function enhance(root){
	var core=window.DataphyrePanel&&window.DataphyrePanel.editorRuntime;
	if(core&&core.version===1&&typeof core.init==="function"){core.init(root||document);return;}
	editorsBelow(root||document).forEach(window.dpPanelEditorInitProfile);
}
function boot(){
	if(disposed){return;}enhance(document);
	if(typeof MutationObserver==="function"&&document.documentElement){
		observer=new MutationObserver(function(mutations){mutations.forEach(function(mutation){
			mutation.addedNodes.forEach(function(node){if(node.nodeType===1&&node.isConnected&&containsEditors(node)){enhance(node);}});
			mutation.removedNodes.forEach(function(node){if(node.nodeType!==1||!containsEditors(node)){return;}editorsBelow(node).forEach(function(editor){if(!editor.isConnected){unmount(editor,{restore:false,reason:"detached"});}});});
		});});
		observer.observe(document.documentElement,{childList:true,subtree:true});
	}
}
if(document.readyState==="loading"){listenGlobal(document,"DOMContentLoaded",boot,lifecycleController?{once:true,signal:lifecycleController.signal}:{once:true});}
else{Promise.resolve().then(boot);}
listenGlobal(window,"pagehide",function(event){
	if(event.persisted){Array.from(ownedEditors).forEach(function(editor){unmount(editor,{reason:"page_cache"});});return;}
	api.dispose();
},lifecycleController?{signal:lifecycleController.signal}:false);
listenGlobal(window,"pageshow",function(event){if(event.persisted&&!disposed){enhance(document);}},lifecycleController?{signal:lifecycleController.signal}:false);
})(window,document);
JS;
	}
}
