<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Emits the isolated progressively-enhanced widget lifecycle controller. */
trait PanelRendererAssetsWidgetRuntimeScripts {
	private static function widgetRuntimeScript(): string {
		return <<<'JS'
var dpPanelWidgetPanel=window.DataphyrePanel=window.DataphyrePanel||{};
var dpPanelWidgetPreviousRuntime=dpPanelWidgetPanel.widgetRuntime;
if(dpPanelWidgetPreviousRuntime&&typeof dpPanelWidgetPreviousRuntime.dispose==="function"){
	dpPanelWidgetPreviousRuntime.dispose("replaced");
}
var dpPanelWidgetInstances=new WeakMap();
var dpPanelWidgetMountedIslands=new Set();
var dpPanelWidgetDocumentObserver=null;
var dpPanelWidgetLifecycleBound=false;
var dpPanelWidgetRuntime={
	version:"1",
	disposed:false,
	dispose:function(){
		if(dpPanelWidgetRuntime.disposed){return;}
		dpPanelWidgetRuntime.disposed=true;
		if(dpPanelWidgetDocumentObserver){dpPanelWidgetDocumentObserver.disconnect();dpPanelWidgetDocumentObserver=null;}
		Array.from(dpPanelWidgetMountedIslands).forEach(function(island){dpPanelWidgetDispose(island,false,false);});
	},
	islandCount:function(){return dpPanelWidgetMountedIslands.size;}
};
dpPanelWidgetPanel.widgetRuntime=dpPanelWidgetRuntime;

function dpPanelWidgetIdempotency(){
	if(window.crypto&&typeof window.crypto.randomUUID==="function"){return window.crypto.randomUUID();}
	var bytes=new Uint8Array(16);
	if(window.crypto&&typeof window.crypto.getRandomValues==="function"){window.crypto.getRandomValues(bytes);}
	else{for(var index=0;index<bytes.length;index++){bytes[index]=Math.floor(Math.random()*256);}}
	return Array.prototype.map.call(bytes,function(value){return value.toString(16).padStart(2,"0");}).join("");
}

function dpPanelWidgetReadState(island){
	var script=island.querySelector(":scope > script[data-dp-widget-state]");
	if(!script){return null;}
	try{
		var value=JSON.parse(script.textContent||"{}");
		return value&&typeof value==="object"&&!Array.isArray(value) ? value : null;
	}catch(error){return null;}
}

function dpPanelWidgetValue(data,path){
	var value=data;
	String(path||"").split(".").forEach(function(key){
		if(value&&typeof value==="object"&&Object.prototype.hasOwnProperty.call(value,key)){value=value[key];}
		else{value=undefined;}
	});
	return value;
}

function dpPanelWidgetApplyData(island,data){
	if(!data||typeof data!=="object"||Array.isArray(data)){return;}
	island.querySelectorAll("[data-dp-widget-bind]").forEach(function(node){
		var value=dpPanelWidgetValue(data,node.getAttribute("data-dp-widget-bind")||"");
		if(value===undefined||value===null||typeof value==="object"){return;}
		node.textContent=String(value);
	});
}

function dpPanelWidgetWriteState(island,state){
	var script=island.querySelector(":scope > script[data-dp-widget-state]");
	if(script){script.textContent=JSON.stringify(state);}
}

function dpPanelWidgetStatus(instance,message,kind){
	var node=instance.island.querySelector(":scope > .dp-panel-widget-status");
	if(!node){return;}
	message=String(message||"").trim();
	node.textContent=message;
	node.hidden=message==="";
	instance.island.setAttribute("data-dp-widget-status",kind||"ready");
}

function dpPanelWidgetBusy(instance,busy){
	instance.busy=!!busy;
	instance.island.setAttribute("aria-busy",busy?"true":"false");
	instance.island.querySelectorAll("[data-dp-widget-action],[data-dp-widget-refresh-action]").forEach(function(button){button.disabled=!!busy;});
}

function dpPanelWidgetValidEndpoint(value){
	return typeof value==="string"&&value.charAt(0)==="/"&&value.slice(0,2)!=="//"&&!/[\r\n\\]/.test(value)&&value.length<=2048;
}

function dpPanelWidgetRequestHeaders(instance){
	var headers={"Accept":"application/json","Content-Type":"application/json","X-Requested-With":"DataphyrePanelWidget"};
	var token=String(instance&&instance.island&&instance.island.getAttribute("data-dp-widget-csrf")||dpPanelWidgetPanel.csrfToken||"").trim();
	if(!token&&document&&typeof document.querySelector==="function"){
		var meta=document.querySelector('meta[name="csrf-token"]');
		if(meta){token=String(meta.getAttribute("content")||"").trim();}
	}
	if(token&&token.length<=2048&&!/[\r\n]/.test(token)){headers["X-CSRF-Token"]=token;}
	return headers;
}

function dpPanelWidgetExactKeys(value,expected){
	var keys=Object.keys(value).sort();
	var wanted=expected.slice().sort();
	return keys.length===wanted.length&&keys.every(function(key,index){return key===wanted[index];});
}

function dpPanelWidgetSafeJson(value,depth,budget){
	budget.nodes++;
	if(depth>8||budget.nodes>1024){return false;}
	if(value===null||typeof value==="boolean"||typeof value==="string"){return typeof value!=="string"||value.length<=8192;}
	if(typeof value==="number"){return Number.isFinite(value);}
	if(typeof value!=="object"){return false;}
	var keys=Object.keys(value);
	if(keys.length>128){return false;}
	return keys.every(function(key){
		if(!Array.isArray(value)&&(key.length===0||key.length>64||/(?:^|[_-])(?:pass(?:word)?|passwd|secret|credential|authorization|cookie|session|private[_-]?key|api[_-]?key|access[_-]?token|refresh[_-]?token|token)$/i.test(key))){return false;}
		return dpPanelWidgetSafeJson(value[key],depth+1,budget);
	});
}

function dpPanelWidgetValidateResult(instance,result,transportStatus){
	var resultKeys=["type","schema_version","adapter","island_id","state","endpoint","snapshot","binding_tag","replayed","retryable","http_status"];
	var stateKeys=["status","version","data","error_code","message"];
	if(!result||typeof result!=="object"||Array.isArray(result)||!dpPanelWidgetExactKeys(result,resultKeys)||result.type!=="panel_widget_interaction_result"||result.schema_version!==1){throw new Error("invalid_widget_response");}
	if(typeof result.adapter!=="string"||!/^[a-z][a-z0-9_.-]{0,63}$/.test(result.adapter)||result.island_id!==instance.island.id||!result.state||typeof result.state!=="object"||Array.isArray(result.state)||!dpPanelWidgetExactKeys(result.state,stateKeys)){throw new Error("invalid_widget_response");}
	var state=result.state;
	if(typeof state.version!=="number"||!Number.isInteger(state.version)||state.version<0||["ready","error","unmounted"].indexOf(state.status)===-1){throw new Error("invalid_widget_response");}
	if(Array.isArray(state.data)&&state.data.length===0){state.data={};}
	if(!state.data||typeof state.data!=="object"||Array.isArray(state.data)||!dpPanelWidgetSafeJson(state.data,0,{nodes:0})){throw new Error("invalid_widget_response");}
	if(state.error_code!==null&&(typeof state.error_code!=="string"||!/^[a-z][a-z0-9_.-]{0,63}$/.test(state.error_code))){throw new Error("invalid_widget_response");}
	if(state.message!==null&&(typeof state.message!=="string"||state.message.length<1||state.message.length>240)){throw new Error("invalid_widget_response");}
	if(typeof result.replayed!=="boolean"||typeof result.retryable!=="boolean"||!Number.isInteger(result.http_status)){throw new Error("invalid_widget_response");}
	if(transportStatus!==undefined&&(!Number.isInteger(transportStatus)||transportStatus!==result.http_status)){throw new Error("invalid_widget_response");}
	if(result.endpoint!==null&&!dpPanelWidgetValidEndpoint(result.endpoint)){throw new Error("invalid_widget_response");}
	if(result.snapshot!==null&&(typeof result.snapshot!=="string"||result.snapshot.length<1||result.snapshot.length>8192)){throw new Error("invalid_widget_response");}
	if(result.binding_tag!==null&&(typeof result.binding_tag!=="string"||result.binding_tag.length<1||result.binding_tag.length>160)){throw new Error("invalid_widget_response");}
	var ready=result.http_status===200&&state.status==="ready";
	var unmounted=result.http_status===200&&state.status==="unmounted";
	if(ready){
		if(!result.endpoint||!result.snapshot||!result.binding_tag||result.retryable||state.version<1||state.error_code!==null){throw new Error("invalid_widget_response");}
	}else if(unmounted){
		if(result.endpoint!==null||result.snapshot!==null||result.binding_tag!==null||result.retryable||state.version<1||state.error_code!==null||Object.keys(state.data).length!==0){throw new Error("invalid_widget_response");}
	}else{
		if(result.http_status<400||result.http_status>599||state.status!=="error"||state.version!==0||state.error_code===null||state.message===null||result.endpoint!==null||result.snapshot!==null||result.binding_tag!==null||result.replayed||Object.keys(state.data).length!==0){throw new Error("invalid_widget_response");}
	}
	return result;
}

function dpPanelWidgetRestoreFocus(instance,focusAction){
	if(!focusAction||instance.disposed){return;}
	var selector=focusAction==="refresh"?"[data-dp-widget-refresh-action]":"[data-dp-widget-action=\""+focusAction.replace(/["\\]/g,"\\$&")+"\"]";
	var focus=instance.island.querySelector(selector);
	if(focus&&typeof focus.focus==="function"){try{focus.focus({preventScroll:true});}catch(error){focus.focus();}}
}

function dpPanelWidgetApplyResult(instance,result,sequence){
	if(instance.disposed||sequence!==instance.sequence){return;}
	result=dpPanelWidgetValidateResult(instance,result);
	var message=String(result.state.message||"");
	var status=String(result.state.status||"error");
	var completed=result.http_status===200&&(status==="ready"||status==="unmounted");
	if(completed){
		if(status==="ready"){
			instance.endpoint=result.endpoint;instance.island.dataset.dpWidgetEndpoint=result.endpoint;
			instance.snapshot=result.snapshot;instance.island.dataset.dpWidgetSnapshot=result.snapshot;
			instance.binding=result.binding_tag;instance.island.dataset.dpWidgetBinding=result.binding_tag;
		}
		instance.version=result.state.version;
		instance.island.dataset.dpWidgetVersion=String(instance.version);
		dpPanelWidgetApplyData(instance.island,result.state.data||{});
		dpPanelWidgetWriteState(instance.island,result.state);
	}
	if(!completed||status==="error"||status==="unavailable"||status==="offline"){
		dpPanelWidgetStatus(instance,message||"The widget could not be updated.",status);
	}else{
		dpPanelWidgetStatus(instance,message,status);
	}
	if(status==="unmounted"){dpPanelWidgetDispose(instance.island,false);return;}
}

function dpPanelWidgetRequest(instance,operation,action,payload,idempotency,attempt,focusAction){
	if(instance.disposed){return Promise.resolve();}
	var sequence=++instance.sequence;
	var controller=typeof window.AbortController==="function"?new window.AbortController():{signal:undefined,abort:function(){}};
	instance.controller=controller;
	dpPanelWidgetBusy(instance,true);
	dpPanelWidgetStatus(instance,attempt>0?"Retrying widget update...":"Updating widget...","loading");
	var body={
		schema_version:1,
		operation:operation,
		island_id:instance.island.id,
		expected_version:instance.version,
		idempotency_key:idempotency,
		snapshot:instance.snapshot,
		binding_tag:instance.binding
	};
	if(operation==="action"){
		body.action=action;
		body.payload=payload&&typeof payload==="object"&&!Array.isArray(payload)?payload:{};
	}
	return fetch(instance.endpoint,{
		method:"POST",
		credentials:"same-origin",
		headers:dpPanelWidgetRequestHeaders(instance),
		body:JSON.stringify(body),
		signal:controller.signal
	}).then(function(response){
		var declared=Number(response.headers.get("content-length")||0);
		if(declared>131072){throw new Error("widget_response_too_large");}
		return response.text().then(function(text){
			if(text.length>131072){throw new Error("widget_response_too_large");}
			var result;
			try{result=JSON.parse(text);}catch(error){throw new Error("invalid_widget_response");}
			return {response:response,result:result};
		});
	}).then(function(packet){
		var result=dpPanelWidgetValidateResult(instance,packet.result,packet.response.status);
		if((result.retryable===true||packet.response.status>=500)&&attempt<instance.retryLimit){
			return new Promise(function(resolve){
				instance.retryTimer=window.setTimeout(resolve,Math.min(4000,250*Math.pow(2,attempt)));
			}).then(function(){return dpPanelWidgetRequest(instance,operation,action,payload,idempotency,attempt+1,focusAction);});
		}
		dpPanelWidgetApplyResult(instance,result,sequence);
	}).catch(function(error){
		if(instance.disposed||error&&error.name==="AbortError"){return;}
		if(window.navigator&&navigator.onLine===false){dpPanelWidgetStatus(instance,"Widget updates are paused while offline.","offline");}
		else{dpPanelWidgetStatus(instance,"The widget could not be updated.","error");}
	}).finally(function(){
		if(!instance.disposed&&sequence===instance.sequence){
			instance.controller=null;
			dpPanelWidgetBusy(instance,false);
			dpPanelWidgetRestoreFocus(instance,focusAction);
		}
	});
}

function dpPanelWidgetEnqueue(instance,operation,action,payload,focusAction){
	var idempotency=dpPanelWidgetIdempotency();
	instance.queue=instance.queue.then(function(){return dpPanelWidgetRequest(instance,operation,action,payload,idempotency,0,focusAction);});
	return instance.queue;
}

function dpPanelWidgetUnmount(instance){
	if(instance.unmountSent||!instance.endpoint||!instance.snapshot||!instance.binding){return;}
	instance.unmountSent=true;
	var body={schema_version:1,operation:"unmount",island_id:instance.island.id,expected_version:instance.version,idempotency_key:dpPanelWidgetIdempotency(),snapshot:instance.snapshot,binding_tag:instance.binding};
	try{
		fetch(instance.endpoint,{method:"POST",credentials:"same-origin",headers:dpPanelWidgetRequestHeaders(instance),body:JSON.stringify(body),keepalive:true}).catch(function(){});
	}catch(error){}
}

function dpPanelWidgetDispose(island,sendUnmount,terminal){
	var instance=dpPanelWidgetInstances.get(island);
	if(!instance||instance.disposed){return;}
	if(sendUnmount){dpPanelWidgetUnmount(instance);}
	instance.disposed=true;
	if(instance.controller){instance.controller.abort();}
	if(instance.retryTimer){window.clearTimeout(instance.retryTimer);}
	if(instance.intervalTimer){window.clearInterval(instance.intervalTimer);}
	instance.cleanups.forEach(function(cleanup){cleanup();});
	instance.cleanups=[];
	dpPanelWidgetInstances.delete(island);
	dpPanelWidgetMountedIslands.delete(island);
	if(terminal===false){
		delete island.dataset.dpWidgetEnded;
		delete island.dataset.dpWidgetEnhanced;
	}else{
		island.dataset.dpWidgetEnded="1";
	}
}

function dpPanelWidgetInitialize(island){
	if(dpPanelWidgetInstances.has(island)||island.dataset.dpWidgetEnded==="1"||!island.closest||!island.closest(".dp-panel")){return;}
	var endpoint=island.getAttribute("data-dp-widget-endpoint")||"";
	var snapshot=island.getAttribute("data-dp-widget-snapshot")||"";
	var binding=island.getAttribute("data-dp-widget-binding")||"";
	if(!dpPanelWidgetValidEndpoint(endpoint)||!snapshot||!binding){return;}
	var instance={
		island:island,endpoint:endpoint,snapshot:snapshot,binding:binding,
		version:Number(island.getAttribute("data-dp-widget-version")||0),
		retryLimit:Math.max(0,Math.min(5,Number(island.getAttribute("data-dp-widget-retry-limit")||0))),
		sequence:0,queue:Promise.resolve(),controller:null,retryTimer:null,intervalTimer:null,
		cleanups:[],disposed:false,busy:false,unmountSent:false
	};
	if(!Number.isInteger(instance.version)||instance.version<1){return;}
	dpPanelWidgetInstances.set(island,instance);
	dpPanelWidgetMountedIslands.add(island);
	island.dataset.dpWidgetEnhanced="1";
	island.setAttribute("aria-busy","false");
	var actions=island.querySelector(":scope > [data-dp-widget-actions]");
	if(actions){actions.hidden=false;}
	island.querySelectorAll("[data-dp-widget-action]").forEach(function(button){
		button.hidden=false;button.disabled=false;
		var listener=function(){dpPanelWidgetEnqueue(instance,"action",button.getAttribute("data-dp-widget-action")||"",{},button.getAttribute("data-dp-widget-action")||"");};
		button.addEventListener("click",listener);
		instance.cleanups.push(function(){button.removeEventListener("click",listener);});
	});
	var refresh=island.querySelector("[data-dp-widget-refresh-action]");
	if(refresh){
		refresh.hidden=false;refresh.disabled=false;
		var refreshListener=function(){dpPanelWidgetEnqueue(instance,"refresh",null,{},"refresh");};
		refresh.addEventListener("click",refreshListener);
		instance.cleanups.push(function(){refresh.removeEventListener("click",refreshListener);});
	}
	var initial=dpPanelWidgetReadState(island);
	if(initial&&initial.data){dpPanelWidgetApplyData(island,initial.data);}
	var mode=island.getAttribute("data-dp-widget-refresh")||"manual";
	var interval=Number(island.getAttribute("data-dp-widget-refresh-interval")||0);
	if(mode==="interval"&&Number.isInteger(interval)&&interval>=5000){
		instance.intervalTimer=window.setInterval(function(){if(!instance.disposed&&!instance.busy&&!document.hidden){dpPanelWidgetEnqueue(instance,"refresh",null,{},null);}},interval);
	}
}

function dpPanelWidgetScan(root){
	if(!root||root.nodeType!==1){return;}
	if(root.matches&&root.matches("[data-dp-widget-island]")){dpPanelWidgetInitialize(root);}
	if(root.querySelectorAll){root.querySelectorAll("[data-dp-widget-island]").forEach(dpPanelWidgetInitialize);}
}

function dpPanelWidgetStillMounted(island){
	var connected=typeof island.isConnected==="boolean"?island.isConnected:!!(document.documentElement&&document.documentElement.contains(island));
	return connected&&!!(island.closest&&island.closest(".dp-panel"));
}

function dpPanelWidgetScheduleRemoval(node){
	var islands=[];
	if(node&&node.nodeType===1&&node.matches&&node.matches("[data-dp-widget-island]")){islands.push(node);}
	if(node&&node.nodeType===1&&node.querySelectorAll){node.querySelectorAll("[data-dp-widget-island]").forEach(function(island){islands.push(island);});}
	if(!islands.length){return;}
	var cleanup=function(){islands.forEach(function(island){if(!dpPanelWidgetStillMounted(island)){dpPanelWidgetDispose(island,true);}});};
	if(typeof window.queueMicrotask==="function"){window.queueMicrotask(cleanup);}else{Promise.resolve().then(cleanup);}
}

function dpPanelWidgetObserveRoot(root){
	if(!root||!root.matches||!root.matches(".dp-panel")){return;}
	root.dataset.dpWidgetObserver="1";
	dpPanelWidgetScan(root);
}

function dpPanelWidgetScanAdded(node){
	if(!node||node.nodeType!==1){return;}
	if(node.matches&&node.matches(".dp-panel")){dpPanelWidgetObserveRoot(node);}
	if(node.querySelectorAll){node.querySelectorAll(".dp-panel").forEach(dpPanelWidgetObserveRoot);}
	dpPanelWidgetScan(node);
}

function dpPanelWidgetBoot(){
	document.querySelectorAll(".dp-panel").forEach(dpPanelWidgetObserveRoot);
	if(dpPanelWidgetDocumentObserver===null&&typeof window.MutationObserver==="function"&&document.documentElement){
		dpPanelWidgetDocumentObserver=new window.MutationObserver(function(records){
			records.forEach(function(record){
				record.removedNodes.forEach(dpPanelWidgetScheduleRemoval);
				record.addedNodes.forEach(dpPanelWidgetScanAdded);
			});
		});
		dpPanelWidgetDocumentObserver.observe(document.documentElement,{childList:true,subtree:true});
	}
	if(!dpPanelWidgetLifecycleBound){
		dpPanelWidgetLifecycleBound=true;
		dpPanelListen(window,"pagehide",function(event){
			if(event&&event.persisted){return;}
			Array.from(dpPanelWidgetMountedIslands).forEach(function(island){dpPanelWidgetDispose(island,true);});
		});
	}
}

if(document.readyState==="loading"){dpPanelListen(document,"DOMContentLoaded",dpPanelWidgetBoot,{once:true});}
else{dpPanelWidgetBoot();}
JS;
	}
}
