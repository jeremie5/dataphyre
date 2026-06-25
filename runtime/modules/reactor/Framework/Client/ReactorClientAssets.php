<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Serves the browser-side Reactor runtime as versioned, cacheable framework assets.
 *
 * The asset facade exposes one script bundle, computes a content hash for cache-busting, and
 * returns a MIME-typed payload for the Reactor asset route. The generated JavaScript powers
 * component dispatch, model binding, optimistic busy states, uploads, polling, lazy loading,
 * batched requests, DOM morphing, and server-sent effects.
 */
final class ReactorClientAssets {

	/**
	 * Renders the script tag needed to boot Reactor components on a page.
	 *
	 * @param ?string $endpoint Optional default endpoint assigned to `data-dp-reactor-endpoint`.
	 * @return string Deferred script tag referencing the versioned Reactor asset.
	 */
	public static function script(?string $endpoint=null): string {
		$endpoint=(string)($endpoint ?? '');
		$attribute=$endpoint!=='' ? ' data-dp-reactor-endpoint="'.htmlspecialchars($endpoint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"' : '';
		return '<script src="'.htmlspecialchars(self::assetUrl('reactor.js'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" defer'.$attribute.'></script>';
	}

	/**
	 * Builds the public URL for a Reactor client asset.
	 *
	 * @param string $asset Requested asset name, sanitized before it is reflected into the URL.
	 * @return string Asset route URL with a content-derived version query parameter.
	 */
	public static function assetUrl(string $asset): string {
		$name=self::assetName($asset);
		return '/dataphyre/reactor/assets/'.$name.'?v='.self::assetVersion($name);
	}

	/**
	 * Calculates a short cache-busting version for an asset body.
	 *
	 * @param string $asset Requested asset name.
	 * @return string First 16 SHA-1 characters for known assets, or `missing` for unknown names.
	 */
	public static function assetVersion(string $asset): string {
		$content=self::assetContent($asset);
		return $content!==null ? substr(sha1((string)$content['body']), 0, 16) : 'missing';
	}

	/**
	 * Resolves a Reactor asset into an HTTP-ready content payload.
	 *
	 * @param string $asset Requested asset name.
	 * @return array{content_type:string, body:string}|null MIME type and body for known assets, otherwise `null`.
	 */
	public static function assetContent(string $asset): ?array {
		return self::assetName($asset)==='reactor.js'
			? ['content_type'=>'application/javascript; charset=UTF-8', 'body'=>self::javascript()]
			: null;
	}

	/**
	 * Sanitizes a requested asset path down to a supported basename.
	 *
	 * @param string $asset Raw route segment or asset path.
	 * @return string Safe basename containing only alphanumerics, dot, underscore, and dash; empty when invalid.
	 */
	private static function assetName(string $asset): string {
		$name=basename(str_replace('\\', '/', trim($asset)));
		return preg_match('/^[a-z0-9._-]+$/i', $name)===1 ? $name : '';
	}

	/**
	 * Returns the embedded Reactor browser runtime.
	 *
	 * The script is intentionally generated from PHP so the asset route can share the same
	 * versioning and deployment path as the framework code that depends on it.
	 *
	 * @return string JavaScript bundle executed by Reactor-enabled pages.
	 */
	private static function javascript(): string {
		$script= <<<'JS'
(function(){
if(window.DataphyreReactor){return;}
var currentScript=document.currentScript;
var defaultEndpoint=(currentScript&&currentScript.getAttribute("data-dp-reactor-endpoint"))||"";
var timers=new WeakMap();
var requests=new WeakMap();
var bundles={};
var pollers=new WeakMap();
var listenerCleanups=new WeakMap();
var lazyStarted=new WeakSet();
var bindingsStarted=new WeakSet();
/**
 * Finds the nearest Reactor component root for an event target.
 */
function rootFrom(target){return target&&target.closest ? target.closest("[data-dp-reactor-component]") : null;}
/**
 * Parses JSON attribute data and falls back when markup contains invalid JSON.
 */
function parseJson(value,fallback){try{return JSON.parse(value||"")||fallback;}catch(error){return fallback;}}
/**
 * Reports whether a loading or disabling marker applies to an action name.
 */
function matchesTarget(node,action){
	var target=node.getAttribute("data-dp-reactor-target")||node.getAttribute("data-dp-reactor-loading")||"";
	return !target||!action||target.split(",").map(function(item){return item.trim();}).indexOf(action)>=0;
}
/**
 * Collects request target names from action, trigger, and component metadata.
 */
function requestTargets(root,action,trigger){
	var targets=[];
	if(action){targets.push(action);}
	if(trigger){
		(trigger.getAttribute("data-dp-reactor-target")||trigger.getAttribute("data-dp-reactor-loading")||"").split(",").forEach(function(item){
			item=item.trim();if(item){targets.push(item);}
		});
	}
	if(root){
		(root.getAttribute("data-dp-reactor-targets")||"").split(",").forEach(function(item){
			item=item.trim();if(item){targets.push(item);}
		});
	}
	return targets.filter(function(value,index){return value&&targets.indexOf(value)===index;});
}
/**
 * Reads target names assigned directly to a loading or disabling node.
 */
function nodeTargets(node){
	return (node.getAttribute("data-dp-reactor-target")||node.getAttribute("data-dp-reactor-loading")||"").split(",").map(function(item){return item.trim();}).filter(Boolean);
}
/**
 * Checks whether a node should react to one of the active request targets.
 */
function targetMatches(node,targets){
	var own=nodeTargets(node);
	if(!own.length){return !targets.length;}
	return (targets||[]).some(function(target){return own.indexOf(target)>=0;});
}
/**
 * Applies busy, loading, hiding, and disabled state while a request is in flight.
 */
function setBusy(root,busy,action,targets){
	if(!root){return;}
	targets=targets||[];
	root.toggleAttribute("data-dp-reactor-busy",!!busy);
	root.setAttribute("aria-busy",busy?"true":"false");
	root.querySelectorAll("[data-dp-reactor-loading]").forEach(function(node){
		if(targetMatches(node,targets)||matchesTarget(node,action)){node.hidden=!busy;}
	});
	root.querySelectorAll("[data-dp-reactor-loading-remove]").forEach(function(node){
		if(targetMatches(node,targets)||matchesTarget(node,action)){node.hidden=!!busy;}
	});
	root.querySelectorAll("[data-dp-reactor-disable]").forEach(function(node){
		if((targetMatches(node,targets)||matchesTarget(node,action))&&("disabled" in node)){node.disabled=!!busy;}
	});
	root.querySelectorAll("[data-dp-reactor-busy-class]").forEach(function(node){
		if(targetMatches(node,targets)||matchesTarget(node,action)){
			(node.getAttribute("data-dp-reactor-busy-class")||"is-busy").split(" ").filter(Boolean).forEach(function(name){node.classList.toggle(name,!!busy);});
		}
	});
}
/**
 * Resolves the endpoint used for a Reactor component request.
 */
function endpoint(root){return root.getAttribute("data-dp-reactor-endpoint")||defaultEndpoint||location.href;}
/**
 * Extracts a non-negative integer attribute with a default fallback.
 */
function numberAttr(node,name,fallback){
	var value=parseInt(node&&node.getAttribute(name)||"",10);
	return Number.isFinite(value)&&value>=0?value:fallback;
}
/**
 * Writes a dotted path into an object, creating intermediate containers.
 */
function setPath(target,path,value){
	var parts=(path||"").split(".").filter(Boolean), cursor=target;
	for(var i=0;i<parts.length;i++){
		if(i===parts.length-1){cursor[parts[i]]=value;break;}
		if(!cursor[parts[i]]||typeof cursor[parts[i]]!=="object"){cursor[parts[i]]={};}
		cursor=cursor[parts[i]];
	}
}
/**
 * Normalizes an input element into the value shape used by Reactor state.
 */
function fieldValue(field){
	if(field.type==="checkbox"){return field.checked;}
	if(field.type==="radio"){return field.checked ? field.value : undefined;}
	return field.value;
}
/**
 * Assigns an incoming state value to a form field and reports whether it changed.
 */
function setFieldValue(field,value){
	if(!field){return false;}
	var old=fieldValue(field);
	if(field.type==="checkbox"){field.checked=value===true||value==="true"||value==="1"||value===1;}
	else if(field.type==="radio"){field.checked=String(field.value)===String(value);}
	else{field.value=value==null?"":String(value);}
	return old!==fieldValue(field);
}
/**
 * Reads a dotted path from an object graph.
 */
function pathValue(target,path){
	var parts=(path||"").split(".").filter(Boolean), cursor=target;
	for(var i=0;i<parts.length;i++){
		if(!cursor||typeof cursor!=="object"||!(parts[i] in cursor)){return undefined;}
		cursor=cursor[parts[i]];
	}
	return cursor;
}
/**
 * Locates the field bound to a state path inside a component.
 */
function fieldFor(root,path){
	if(!path){return null;}
	var escaped=window.CSS&&CSS.escape?CSS.escape(path):path.replace(/"/g,'\\"');
	return root.querySelector('[data-dp-reactor-model="'+escaped+'"],[name="'+escaped+'"]');
}
/**
 * Collects current component model fields into a nested state payload.
 */
function collectState(root){
	var state={};
	root.querySelectorAll("[data-dp-reactor-model]").forEach(function(field){
		if(field.type==="file"){return;}
		var value=fieldValue(field);
		if(value===undefined){return;}
		setPath(state,field.getAttribute("data-dp-reactor-model")||field.name,value);
	});
	return state;
}
/**
 * Collects action parameters from trigger metadata and submitted form fields.
 */
function collectParams(trigger){
	var params=parseJson(trigger&&trigger.getAttribute("data-dp-reactor-params"),{});
	if(trigger&&trigger.tagName==="FORM"){
		new FormData(trigger).forEach(function(value,key){
			if(typeof File!=="undefined"&&value instanceof File){return;}
			setPath(params,key,value);
		});
	}
	return params;
}
/**
 * Reports whether the current request scope contains file inputs.
 */
function hasFiles(root,trigger){
	var scope=trigger&&trigger.tagName==="FORM"?trigger:root;
	return !!(scope&&scope.querySelector('input[type="file"]'));
}
/**
 * Adds selected upload files to a FormData request body.
 */
function appendFiles(formData,root,trigger){
	var scope=trigger&&trigger.tagName==="FORM"?trigger:root;
	if(!scope){return;}
	scope.querySelectorAll('input[type="file"]').forEach(function(input){
		var name=input.name||input.getAttribute("data-dp-reactor-model")||"upload";
		Array.prototype.slice.call(input.files||[]).forEach(function(file,index){
			formData.append(input.multiple?name+"[]":name,file,file.name||("upload-"+index));
		});
	});
}
/**
 * Builds either a JSON or multipart request body for a Reactor dispatch.
 */
function requestBody(root,action,params,trigger){
	var payload={
		component:root.getAttribute("data-dp-reactor-component")||"",
		action:action,
		state:collectState(root),
		params:params||{},
		snapshot:parseJson(root.getAttribute("data-dp-reactor-snapshot"),{})
	};
	if(!hasFiles(root,trigger)){return {payload:payload,body:JSON.stringify(payload),headers:{"Content-Type":"application/json"}};}
	var formData=new FormData();
	formData.append("component",payload.component);
	formData.append("action",payload.action);
	formData.append("state",JSON.stringify(payload.state));
	formData.append("params",JSON.stringify(payload.params));
	formData.append("snapshot",JSON.stringify(payload.snapshot));
	appendFiles(formData,root,trigger);
	return {payload:payload,body:formData,headers:{}};
}
/**
 * Debounces work keyed by DOM nodes or component roots.
 */
function schedule(key,wait,callback){
	clearTimeout(timers.get(key));
	timers.set(key,setTimeout(callback,Math.max(0,wait)));
}
/**
 * Resolves the action that should run after a model event.
 */
function modelAction(field){
	return field.getAttribute("data-dp-reactor-model-action")||field.getAttribute("data-dp-reactor-action")||"";
}
/**
 * Resolves whether a model binding updates live, on blur, or on change.
 */
function modelMode(field){
	if(field.hasAttribute("data-dp-reactor-live")){return "live";}
	if(field.hasAttribute("data-dp-reactor-blur")){return "blur";}
	if(field.hasAttribute("data-dp-reactor-change")){return "change";}
	return "";
}
/**
 * Marks a model field and component as dirty compared to the latest snapshot.
 */
function markDirty(field){
	var root=rootFrom(field);
	if(!root){return;}
	var snapshot=parseJson(root.getAttribute("data-dp-reactor-snapshot"),{});
	var path=field.getAttribute("data-dp-reactor-model")||field.name;
	var dirty=fieldValue(field)!==pathValue(snapshot.state||{},path);
	field.toggleAttribute("data-dp-reactor-dirty",dirty);
	root.toggleAttribute("data-dp-reactor-dirty",root.querySelector("[data-dp-reactor-dirty]")!==null);
}
/**
 * Clears dirty markers after state has synchronized with the server.
 */
function clearDirty(root){
	root.querySelectorAll("[data-dp-reactor-dirty]").forEach(function(node){node.removeAttribute("data-dp-reactor-dirty");});
	root.removeAttribute("data-dp-reactor-dirty");
}
/**
 * Selects a browser storage driver for persisted state bindings.
 */
function storageFor(driver){
	try{return driver==="session"?window.sessionStorage:window.localStorage;}catch(error){return null;}
}
/**
 * Converts state values into stable URL parameter strings.
 */
function bindingValue(value){
	if(value===undefined||value===null){return "";}
	if(typeof value==="object"){return JSON.stringify(value);}
	return String(value);
}
/**
 * Mirrors configured state paths into the browser URL query string.
 */
function applyUrlBindings(root,state){
	var bindings=parseJson(root.getAttribute("data-dp-reactor-url"),{});
	var keys=Object.keys(bindings||{});
	if(!keys.length||!history.replaceState){return;}
	var url=new URL(location.href), changed=false, mode="replace";
	keys.forEach(function(path){
		var config=bindings[path]||{}, name=config.as||path, value=bindingValue(pathValue(state,path));
		mode=config.history==="push"?"push":mode;
		if((config.except_empty!==false)&&value===""){
			if(url.searchParams.has(name)){url.searchParams.delete(name);changed=true;}
			return;
		}
		if(url.searchParams.get(name)!==value){url.searchParams.set(name,value);changed=true;}
	});
	if(changed){history[mode==="push"?"pushState":"replaceState"](history.state,"",url.toString());}
}
/**
 * Mirrors configured state paths into localStorage or sessionStorage.
 */
function applyPersistBindings(root,state){
	var bindings=parseJson(root.getAttribute("data-dp-reactor-persist"),{});
	Object.keys(bindings||{}).forEach(function(path){
		var config=bindings[path]||{}, storage=storageFor(config.driver||"local");
		if(!storage){return;}
		var key=config.key||((root.getAttribute("data-dp-reactor-component")||"component")+"."+path);
		try{storage.setItem(key,JSON.stringify(pathValue(state,path)));}catch(error){}
	});
}
/**
 * Applies all outbound state bindings after a successful response.
 */
function applyOutboundBindings(root,state){
	applyUrlBindings(root,state||{});
	applyPersistBindings(root,state||{});
}
/**
 * Primes model fields from URL and persisted bindings before first dispatch.
 */
function initStateBindings(root){
	if(!root||bindingsStarted.has(root)){return;}
	bindingsStarted.add(root);
	var changed=false;
	var urlBindings=parseJson(root.getAttribute("data-dp-reactor-url"),{});
	var params=new URL(location.href).searchParams;
	Object.keys(urlBindings||{}).forEach(function(path){
		var config=urlBindings[path]||{}, name=config.as||path;
		if(!params.has(name)){return;}
		var field=fieldFor(root,path);
		if(setFieldValue(field,params.get(name))){changed=true;markDirty(field);}
	});
	var persistBindings=parseJson(root.getAttribute("data-dp-reactor-persist"),{});
	Object.keys(persistBindings||{}).forEach(function(path){
		var config=persistBindings[path]||{}, storage=storageFor(config.driver||"local");
		if(!storage){return;}
		var key=config.key||((root.getAttribute("data-dp-reactor-component")||"component")+"."+path);
		try{
			var raw=storage.getItem(key);
			if(raw===null){return;}
			var value=JSON.parse(raw);
			var field=fieldFor(root,path);
			if(setFieldValue(field,value)){changed=true;markDirty(field);}
		}catch(error){}
	});
	if(changed){dispatch(root,"",{_reactor:{source:"binding-init"}});}
}
/**
 * Derives a stable morphing key for keyed DOM reconciliation.
 */
function nodeKey(node){
	if(!node||node.nodeType!==1){return "";}
	return node.getAttribute("data-dp-reactor-key")||node.id||(node.getAttribute("name")?node.tagName+":"+node.getAttribute("name"):"");
}
/**
 * Dispatches a bubbling Reactor lifecycle event from a component root.
 */
function reactorEvent(root,name,detail){
	if(root){root.dispatchEvent(new CustomEvent(name,{bubbles:true,cancelable:true,detail:detail||{}}));}
}
/**
 * Runs a selector against a component root or the whole document with invalid-selector safety.
 */
function queryWithin(root,selector,scope){
	try{return (scope==="document"?document:root).querySelector(selector);}catch(error){return null;}
}
/**
 * Synchronizes element attributes during DOM morphing.
 */
function syncAttributes(from,to){
	Array.prototype.slice.call(from.attributes).forEach(function(attr){
		if(!to.hasAttribute(attr.name)){from.removeAttribute(attr.name);}
	});
	Array.prototype.slice.call(to.attributes).forEach(function(attr){
		if(from.getAttribute(attr.name)!==attr.value){from.setAttribute(attr.name,attr.value);}
	});
}
/**
 * Morphs one existing DOM node to match a server-rendered replacement node.
 */
function morphNode(from,to){
	if(!from||!to){return;}
	if(from.nodeType===1&&from.hasAttribute("data-dp-reactor-ignore")){return;}
	if(from.nodeType!==to.nodeType||from.nodeName!==to.nodeName){
		from.replaceWith(to.cloneNode(true));
		return;
	}
	if(from.nodeType===3||from.nodeType===8){
		if(from.nodeValue!==to.nodeValue){from.nodeValue=to.nodeValue;}
		return;
	}
	if(from.nodeType!==1){return;}
	var active=document.activeElement;
	var preservesValue=from===active&&/^(INPUT|TEXTAREA|SELECT)$/.test(from.tagName);
	if(!from.hasAttribute("data-dp-reactor-ignore-self")){syncAttributes(from,to);}
	if(!preservesValue&&/^(INPUT|TEXTAREA|SELECT)$/.test(from.tagName)){
		from.value=to.value;
		if(from.type==="checkbox"||from.type==="radio"){from.checked=to.checked;}
	}
	morphChildren(from,to);
}
/**
 * Reconciles child nodes while preserving keyed existing nodes where possible.
 */
function morphChildren(from,to){
	var keyed={};
	Array.prototype.slice.call(from.childNodes).forEach(function(child){
		var key=nodeKey(child);
		if(key){keyed[key]=child;}
	});
	Array.prototype.slice.call(to.childNodes).forEach(function(newChild,index){
		var current=from.childNodes[index], key=nodeKey(newChild), match=key&&keyed[key]?keyed[key]:current;
		if(!match){
			from.appendChild(newChild.cloneNode(true));
			return;
		}
		if(match!==current){from.insertBefore(match,current||null);}
		morphNode(match,newChild);
	});
	while(from.childNodes.length>to.childNodes.length){
		from.removeChild(from.lastChild);
	}
}
/**
 * Applies a full component HTML response using either inner replacement or morphing.
 */
function applyHtml(root,html){
	if(root.getAttribute("data-dp-reactor-replace")==="inner"){
		reactorEvent(root,"dataphyre:reactor-before-morph",{html:html,mode:"inner"});
		root.innerHTML=html;
		reactorEvent(root,"dataphyre:reactor-after-morph",{mode:"inner"});
		return;
	}
	var template=document.createElement("template");
	template.innerHTML=html;
	reactorEvent(root,"dataphyre:reactor-before-morph",{html:html,mode:"morph"});
	morphChildren(root,template.content);
	reactorEvent(root,"dataphyre:reactor-after-morph",{mode:"morph"});
}
/**
 * Applies a named server-rendered fragment to a target inside the component or document.
 */
function applyFragment(root,fragment){
	if(!fragment||!fragment.name){return;}
	var selector='[data-dp-reactor-fragment="'+String(fragment.name).replace(/"/g,'\\"')+'"]';
	var target=queryWithin(root,selector,fragment.scope);
	if(!target){return;}
	var mode=fragment.mode||"morph", html=String(fragment.html||"");
	reactorEvent(root,"dataphyre:reactor-before-fragment",{fragment:fragment,target:target});
	if(mode==="outer"){
		var template=document.createElement("template");
		template.innerHTML=html;
		var replacement=template.content.firstElementChild;
		if(replacement){target.replaceWith(replacement);}
	}
	else if(mode==="inner"){target.innerHTML=html;}
	else{
		var content=document.createElement("template");
		content.innerHTML=html;
		morphChildren(target,content.content);
	}
	reactorEvent(root,"dataphyre:reactor-after-fragment",{fragment:fragment});
}
/**
 * Applies focus and scroll effects to server-selected DOM targets.
 */
function applyTargetEffect(root,effect,kind){
	if(!effect||!effect.selector){return;}
	var target=queryWithin(root,effect.selector,effect.scope);
	if(!target){return;}
	if(kind==="focus"&&target.focus){target.focus({preventScroll:effect.prevent_scroll===true});}
	if(kind==="scroll"&&target.scrollIntoView){target.scrollIntoView({behavior:"smooth",block:effect.block||"nearest",inline:effect.inline||"nearest"});}
}
/**
 * Starts a browser download effect returned by the server.
 */
function applyDownload(effect){
	if(!effect||!effect.url){return;}
	var link=document.createElement("a");
	link.href=effect.url;
	if(effect.filename){link.download=effect.filename;}
	link.rel="noopener";
	link.style.display="none";
	document.body.appendChild(link);
	link.click();
	link.remove();
}
/**
 * Finds the field associated with a validation-error path.
 */
function findField(root,path){
	if(!path){return null;}
	var escaped=window.CSS&&CSS.escape?CSS.escape(path):path.replace(/"/g,'\\"');
	return root.querySelector('[data-dp-reactor-model="'+escaped+'"],[name="'+escaped+'"]');
}
/**
 * Renders validation errors into fields and error slots.
 */
function applyErrors(root,errors){
	root.querySelectorAll("[data-dp-reactor-error-for]").forEach(function(node){node.textContent="";node.hidden=true;});
	root.querySelectorAll("[data-dp-reactor-invalid]").forEach(function(field){
		field.removeAttribute("data-dp-reactor-invalid");
		field.removeAttribute("aria-invalid");
		field.removeAttribute("data-dp-reactor-error");
	});
	var hasErrors=false;
	Object.keys(errors||{}).forEach(function(path){
		var messages=Array.isArray(errors[path])?errors[path]:[errors[path]];
		var message=messages.filter(Boolean).join(" ");
		if(!message){return;}
		hasErrors=true;
		var field=findField(root,path);
		if(field){
			field.setAttribute("data-dp-reactor-invalid","1");
			field.setAttribute("aria-invalid","true");
			field.setAttribute("data-dp-reactor-error",message);
		}
		var slot=root.querySelector('[data-dp-reactor-error-for="'+path.replace(/"/g,'\\"')+'"]');
		if(slot){slot.textContent=message;slot.hidden=false;}
	});
	root.toggleAttribute("data-dp-reactor-has-errors",hasErrors);
}
/**
 * Applies all non-HTML side effects returned by a Reactor response.
 */
function applyEffects(root,json){
	var effects=json&&json.effects?json.effects:{};
	applyErrors(root,effects.errors||{});
	(effects.fragments||[]).forEach(function(fragment){applyFragment(root,fragment);});
	applyTargetEffect(root,effects.focus,"focus");
	applyTargetEffect(root,effects.scroll,"scroll");
	if(effects.title){document.title=effects.title;}
	if(effects.copy&&navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(String(effects.copy));}
	if(effects.open&&effects.open.url){window.open(effects.open.url,effects.open.target||"_blank","noopener");}
	applyDownload(effects.download);
	(effects.events||[]).forEach(function(event){
		if(event&&event.name){root.dispatchEvent(new CustomEvent(event.name,{bubbles:true,detail:event.detail||{}}));}
	});
	(effects.toasts||[]).forEach(function(toast){
		root.dispatchEvent(new CustomEvent("dataphyre:reactor-toast",{bubbles:true,detail:toast||{}}));
	});
	if(effects.redirect&&effects.redirect.url){
		if(effects.redirect.replace){location.replace(effects.redirect.url);}
		else{location.href=effects.redirect.url;}
	}
}
/**
 * Creates a new request identity and aborts any previous in-flight request for the component.
 */
function nextRequest(root){
	var previous=requests.get(root);
	if(previous&&previous.controller){previous.controller.abort();}
	var current={
		id:(previous&&previous.id?previous.id:0)+1,
		controller:typeof AbortController==="function"?new AbortController():null,
		targets:[]
	};
	requests.set(root,current);
	return current;
}
/**
 * Checks whether an async response still belongs to the latest request.
 */
function isCurrentRequest(root,current){
	var active=requests.get(root);
	return active&&active.id===current.id;
}
/**
 * Reports whether a request should be batched with nearby component actions.
 */
function shouldBundle(root,trigger){
	if(!root||!root.hasAttribute("data-dp-reactor-bundle")){return false;}
	return !hasFiles(root,trigger||null);
}
/**
 * Resolves the debounce window for request bundling.
 */
function bundleDelay(root){
	return numberAttr(root,"data-dp-reactor-bundle",12);
}
/**
 * Applies a successful JSON response to component HTML, effects, state bindings, and lifecycle events.
 */
function processResponse(root,current,json,action){
	if(!isCurrentRequest(root,current)){return json;}
	if(!json||json.ok===false){throw new Error(json&&json.message?json.message:"Reactor request failed");}
	var skipRender=json.effects&&json.effects.skip_render===true;
	if(json.effects&&json.effects.replace==="inner"){root.setAttribute("data-dp-reactor-replace","inner");}
	if(!skipRender&&typeof json.html==="string"){applyHtml(root,json.html);}
	if(json.effects&&json.effects.snapshot){root.setAttribute("data-dp-reactor-snapshot",JSON.stringify(json.effects.snapshot));}
	applyEffects(root,json);
	applyOutboundBindings(root,json.state||{});
	clearDirty(root);
	init(root);
	reactorEvent(root,"dataphyre:reactor-updated",json);
	return json;
}
/**
 * Queues one component request into a pending batch for the same endpoint.
 */
function enqueueBundle(root,action,params,trigger,current,request){
	var url=endpoint(root), bucket=bundles[url];
	if(!bucket){
		bucket=bundles[url]={items:[],timer:null};
	}
	var index=bucket.items.length;
	bucket.items.push({root:root,action:action,current:current,payload:request.payload});
	clearTimeout(bucket.timer);
	bucket.timer=setTimeout(function(){flushBundle(url);},bundleDelay(root));
	return new Promise(function(resolve,reject){
		bucket.items[index].resolve=resolve;
		bucket.items[index].reject=reject;
	});
}
/**
 * Sends a pending endpoint batch and resolves each queued component request independently.
 */
function flushBundle(url){
	var bucket=bundles[url];
	if(!bucket){return;}
	delete bundles[url];
	var items=bucket.items.filter(function(item){return item&&item.root&&item.payload;});
	if(!items.length){return;}
	fetch(url,{
		method:"POST",
		credentials:"same-origin",
		headers:{
			"Accept":"application/json",
			"Content-Type":"application/json",
			"X-Dataphyre-Reactor":"DataphyreReactor",
			"X-Dataphyre-Reactor-Batch":"1"
		},
		body:JSON.stringify({batch:items.map(function(item){return item.payload;})})
	}).then(function(response){
		return response.text().then(function(text){
			var json;
			try{json=JSON.parse(text);}catch(error){throw new Error("Reactor endpoint returned a non-JSON response.");}
			json.http_status=response.status;
			return json;
		});
	}).then(function(json){
		var responses=Array.isArray(json&&json.batch)?json.batch:[];
		items.forEach(function(item,index){
			try{
				var response=responses[index]||{ok:false,message:"Missing Reactor batch response.",status:500};
				item.resolve(processResponse(item.root,item.current,response,item.action));
			}catch(error){
				reactorEvent(item.root,"dataphyre:reactor-error",{message:error.message,error:error});
				item.reject(error);
			}finally{
				if(isCurrentRequest(item.root,item.current)){setBusy(item.root,false,item.action,item.current.targets||[]);}
			}
		});
	}).catch(function(error){
		items.forEach(function(item){
			reactorEvent(item.root,"dataphyre:reactor-error",{message:error.message,error:error});
			if(isCurrentRequest(item.root,item.current)){setBusy(item.root,false,item.action,item.current.targets||[]);}
			item.reject(error);
		});
	});
}
/**
 * Dispatches one Reactor action from a component root to its server endpoint.
 */
function dispatch(root,action,params,trigger){
	if(!root){return Promise.resolve(null);}
	action=action||"";
	if(trigger&&trigger.hasAttribute("data-dp-reactor-confirm")){
		var message=trigger.getAttribute("data-dp-reactor-confirm")||"Are you sure?";
		if(!window.confirm(message)){return Promise.resolve(null);}
	}
	var before=new CustomEvent("dataphyre:reactor-before-request",{bubbles:true,cancelable:true,detail:{action:action,params:params||{},trigger:trigger||null}});
	if(!root.dispatchEvent(before)){return Promise.resolve(null);}
	var current=nextRequest(root);
	var request=requestBody(root,action,params,trigger||null);
	current.targets=requestTargets(root,action,trigger||null);
	setBusy(root,true,action,current.targets);
	if(shouldBundle(root,trigger||null)){
		return enqueueBundle(root,action,params,trigger||null,current,request);
	}
	var headers=Object.assign({
		"Accept":"application/json",
		"X-Dataphyre-Reactor":"DataphyreReactor"
	},request.headers);
	return fetch(endpoint(root),{
		method:"POST",
		credentials:"same-origin",
		signal:current.controller?current.controller.signal:undefined,
		headers:headers,
		body:request.body
	}).then(function(response){
		return response.text().then(function(text){
			var json;
			try{json=JSON.parse(text);}catch(error){throw new Error("Reactor endpoint returned a non-JSON response.");}
			json.http_status=response.status;
			return json;
		});
	}).then(function(json){
		return processResponse(root,current,json,action);
	}).catch(function(error){
		if(error&&error.name==="AbortError"){return null;}
		reactorEvent(root,"dataphyre:reactor-error",{message:error.message,error:error});
		throw error;
	}).finally(function(){if(isCurrentRequest(root,current)){setBusy(root,false,action,current.targets||[]);}});
}
/**
 * Re-renders a component without a named action.
 */
function refresh(root,params){return dispatch(root,"",params||{});}
/**
 * Schedules a server update after a model input, change, or blur event.
 */
function handleModelEvent(field,eventName){
	var mode=modelMode(field);
	if(!mode){return;}
	if(mode==="blur"&&eventName!=="blur"){return;}
	if(mode==="change"&&eventName!=="change"){return;}
	if(mode==="live"&&eventName!=="input"&&eventName!=="change"){return;}
	var root=rootFrom(field);
	if(!root){return;}
	var wait=numberAttr(field,"data-dp-reactor-debounce",mode==="live"?250:0);
	var path=field.getAttribute("data-dp-reactor-model")||field.name||"";
	markDirty(field);
	schedule(field,wait,function(){dispatch(root,modelAction(field),{_reactor:{model:path,event:eventName}});});
}
/**
 * Starts a lazy component when it enters the viewport or when observers are unavailable.
 */
function initLazy(root){
	if(!root||lazyStarted.has(root)||!root.hasAttribute("data-dp-reactor-lazy")){return;}
	var action=root.getAttribute("data-dp-reactor-lazy-action")||"";
	var run=function(){if(lazyStarted.has(root)){return;}lazyStarted.add(root);dispatch(root,action,{});};
	if(!("IntersectionObserver" in window)){run();return;}
	var observer=new IntersectionObserver(function(entries){
		entries.forEach(function(entry){
			if(entry.isIntersecting){observer.disconnect();run();}
		});
	},{rootMargin:root.getAttribute("data-dp-reactor-lazy-margin")||"120px"});
	observer.observe(root);
}
/**
 * Starts interval polling for components that opt into server refreshes.
 */
function initPolling(root){
	if(!root||pollers.has(root)||!root.hasAttribute("data-dp-reactor-poll")){return;}
	var interval=numberAttr(root,"data-dp-reactor-poll",0);
	if(interval<250){return;}
	var action=root.getAttribute("data-dp-reactor-poll-action")||"";
	var timer=setInterval(function(){
		if(!document.documentElement.contains(root)){clearInterval(timer);pollers.delete(root);return;}
		if(document.hidden&&root.getAttribute("data-dp-reactor-poll-hidden")!=="1"){return;}
		dispatch(root,action,{});
	},interval);
	pollers.set(root,timer);
}
/**
 * Subscribes a component to named browser events that should trigger Reactor actions.
 */
function initListeners(root){
	if(!root||listenerCleanups.has(root)||!root.hasAttribute("data-dp-reactor-listeners")){return;}
	var listeners=parseJson(root.getAttribute("data-dp-reactor-listeners"),{});
	var componentName=root.getAttribute("data-dp-reactor-component")||"";
	var cleanups=[];
	Object.keys(listeners||{}).forEach(function(eventName){
		var action=listeners[eventName];
		if(!eventName||!action){return;}
		var handler=function(event){
			if(!document.documentElement.contains(root)){
				document.removeEventListener(eventName,handler);
				return;
			}
			var detail=event.detail||{};
			if(detail._reactor_to&&detail._reactor_to!==componentName){return;}
			if(detail._reactor_self===true&&event.target!==root){return;}
			dispatch(root,action,{_reactor:{event:eventName,detail:event.detail||{}},event:event.detail||{}});
		};
		document.addEventListener(eventName,handler);
		cleanups.push(function(){document.removeEventListener(eventName,handler);});
	});
	listenerCleanups.set(root,cleanups);
}
/**
 * Reflects browser online/offline state into every Reactor component.
 */
function applyOfflineState(){
	document.querySelectorAll("[data-dp-reactor-component]").forEach(function(root){
		root.toggleAttribute("data-dp-reactor-offline",navigator.onLine===false);
		root.querySelectorAll("[data-dp-reactor-offline-indicator]").forEach(function(node){node.hidden=navigator.onLine!==false;});
		root.querySelectorAll("[data-dp-reactor-online-indicator]").forEach(function(node){node.hidden=navigator.onLine===false;});
	});
}
/**
 * Initializes one Reactor component root and its optional behaviors.
 */
function init(root){setBusy(root,false,"",[]);initStateBindings(root);initLazy(root);initPolling(root);initListeners(root);applyOfflineState();}
/**
 * Initializes every Reactor component currently present in the document.
 */
function initAll(){document.querySelectorAll("[data-dp-reactor-component]").forEach(init);}
document.addEventListener("click",function(event){
	var trigger=event.target.closest&&event.target.closest("[data-dp-reactor-action]");
	if(!trigger||trigger.tagName==="FORM"){return;}
	var root=rootFrom(trigger);
	if(!root){return;}
	event.preventDefault();
	dispatch(root,trigger.getAttribute("data-dp-reactor-action")||"",collectParams(trigger),trigger);
});
document.addEventListener("input",function(event){
	var field=event.target.closest&&event.target.closest("[data-dp-reactor-model]");
	if(field){markDirty(field);handleModelEvent(field,"input");}
});
document.addEventListener("change",function(event){
	var field=event.target.closest&&event.target.closest("[data-dp-reactor-model]");
	if(field){markDirty(field);handleModelEvent(field,"change");}
});
document.addEventListener("blur",function(event){
	var field=event.target.closest&&event.target.closest("[data-dp-reactor-model]");
	if(field){handleModelEvent(field,"blur");}
},true);
document.addEventListener("submit",function(event){
	var trigger=event.target.closest&&event.target.closest("form[data-dp-reactor-action]");
	if(!trigger){return;}
	var root=rootFrom(trigger);
	if(!root){return;}
	event.preventDefault();
	dispatch(root,trigger.getAttribute("data-dp-reactor-action")||"",collectParams(trigger),trigger);
});
initAll();
document.addEventListener("dataphyre:panel-refresh",initAll);
window.addEventListener("online",applyOfflineState);
window.addEventListener("offline",applyOfflineState);
window.DataphyreReactor={dispatch:dispatch,refresh:refresh,init:init,initAll:initAll};
})();
JS
		;
		return $script;
	}
}
