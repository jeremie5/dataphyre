/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
(function(global){
"use strict";

var VERSION=1;
var PREFIX="dataphyre:panel-realtime:";
var STATES=["idle","connecting","open","paused","backoff","stopped","terminal"];
var PRIVATE=new WeakMap();

function RuntimeSignal(code,message,retryable,reported){
	this.name="PanelRealtimeClientError";
	this.code=code;
	this.message=message;
	this.retryable=retryable===true;
	this.reported=reported===true;
	if(Error.captureStackTrace){Error.captureStackTrace(this,RuntimeSignal);}
}
RuntimeSignal.prototype=Object.create(Error.prototype);
RuntimeSignal.prototype.constructor=RuntimeSignal;

function fail(code,message,retryable){throw new RuntimeSignal(code,message,retryable,false);}
function integer(value,fallback,minimum,maximum,label){
	value=value===undefined||value===null?fallback:Number(value);
	if(!Number.isInteger(value)||value<minimum||value>maximum){throw new TypeError(label+" is outside its safe bound.");}
	return value;
}
function number(value,fallback,minimum,maximum,label){
	value=value===undefined||value===null?fallback:Number(value);
	if(!Number.isFinite(value)||value<minimum||value>maximum){throw new TypeError(label+" is outside its safe bound.");}
	return value;
}
function token(value,label,maximum){
	if(typeof value!=="string"||value.length===0||value.length>maximum||/[\u0000-\u001f\u007f]/.test(value)){throw new TypeError(label+" is invalid.");}
	return value;
}
function headerName(value,label){
	if(typeof value!=="string"||!/^[A-Za-z0-9-]{1,96}$/.test(value)){throw new TypeError(label+" is invalid.");}
	return value;
}
function credentialQueryName(value){var separated=String(value).replace(/([a-z0-9])([A-Z])/g,"$1_$2").toLowerCase(),parts=separated.split(/[^a-z0-9]+/).filter(Boolean),compact=parts.join("");return parts.some(function(part){return ["auth","key","sig","ticket"].indexOf(part)>=0;})||/token|intent|authorization|credential|secret|apikey|accesskey|password|passwd|sessionid|sessionkey|csrf|xsrf|jwt|signature|cookie|bearer|privatekey|clientsecret|refreshtoken/.test(compact);}
function utf8Bytes(value,TextEncoderClass){return new TextEncoderClass().encode(value).byteLength;}
function safeReason(reason){return typeof reason==="string"&&/^[a-z][a-z0-9_.:-]{0,63}$/.test(reason)?reason:"host";}
function safeError(error){
	if(error instanceof RuntimeSignal){return error;}
	if(error&&error.name==="AbortError"){return new RuntimeSignal("aborted","Panel realtime request was aborted.",true,false);}
	return new RuntimeSignal("network_error","Panel realtime connection failed.",true,false);
}
function frozen(value){if(value===null||typeof value!=="object"){return value;}var stack=[value],seen=new WeakSet();while(stack.length){var current=stack.pop();if(seen.has(current)){continue;}seen.add(current);Object.keys(current).forEach(function(key){var child=current[key];if(child!==null&&typeof child==="object"){stack.push(child);}});Object.freeze(current);}return value;}
function privateState(client){var state=PRIVATE.get(client);if(!state){throw new TypeError("Panel realtime client state is unavailable.");}return state;}
function optionsOf(client){return privateState(client).options;}
function cursorOf(client){return privateState(client).lastEventId;}
function setCursor(client,value){privateState(client).lastEventId=value;}

function MiniTarget(){this.listeners=Object.create(null);}
MiniTarget.prototype.addEventListener=function(type,listener){if(typeof listener!=="function"){return;}var list=this.listeners[type]||(this.listeners[type]=[]);if(list.indexOf(listener)<0){list.push(listener);}};
MiniTarget.prototype.removeEventListener=function(type,listener){var list=this.listeners[type]||[];var index=list.indexOf(listener);if(index>=0){list.splice(index,1);}};
MiniTarget.prototype.dispatchEvent=function(event){var list=(this.listeners[event.type]||[]).slice();list.forEach(function(listener){try{listener.call(null,event);}catch(error){}});return true;};

function createTarget(){return typeof global.EventTarget==="function"?new global.EventTarget():new MiniTarget();}
function createEvent(type,detail){
	if(typeof global.CustomEvent==="function"){return new global.CustomEvent(type,{detail:detail});}
	if(typeof global.Event==="function"){
		var event=new global.Event(type);
		Object.defineProperty(event,"detail",{value:detail,enumerable:true});
		return event;
	}
	return {type:type,detail:detail};
}

function normalizeHeaders(input){
	var output=Object.create(null);
	if(input===undefined||input===null){return output;}
	var entries=[];
	if(Array.isArray(input)){throw new TypeError("Panel realtime headers must be an object or Headers value.");}
	if(typeof global.Headers==="function"&&input instanceof global.Headers){input.forEach(function(value,name){entries.push([name,value]);});}
	else if(typeof input==="object"&&(Object.getPrototypeOf(input)===Object.prototype||Object.getPrototypeOf(input)===null)){Object.keys(input).forEach(function(name){entries.push([name,input[name]]);});}
	else{throw new TypeError("Panel realtime headers must be an object or Headers value.");}
	entries.forEach(function(entry){
		var name=headerName(String(entry[0]),"Panel realtime header name");
		var lower=name.toLowerCase();
		if(["cookie","host","content-length","connection","origin"].indexOf(lower)>=0){throw new TypeError("Panel realtime header is controlled by the browser or transport.");}
		var value=token(String(entry[1]),"Panel realtime header value",8192);
		output[name]=value;
	});
	return output;
}

function normalizeAllowedOrigins(input,baseUrl){
	if(input===undefined||input===null){return [];}
	if(!Array.isArray(input)||input.length>16){throw new TypeError("Panel realtime allowed origins must be an array of at most 16 origins.");}
	var output=[];input.forEach(function(value){if(typeof value!=="string"){throw new TypeError("Panel realtime allowed origin is invalid.");}var url;try{url=new URL(value);}catch(error){throw new TypeError("Panel realtime allowed origin is invalid.");}if((url.protocol!=="http:"&&url.protocol!=="https:")||url.username||url.password||url.search||url.hash||url.pathname!=="/"){throw new TypeError("Panel realtime allowed origin is invalid.");}if(baseUrl.protocol==="https:"&&url.protocol!=="https:"){throw new TypeError("Panel realtime allowed origins cannot downgrade HTTPS.");}if(output.indexOf(url.origin)<0){output.push(url.origin);}});return output;
}

function normalizeUrl(raw,base,allowedOrigins,allowQuery){
	if(typeof raw!=="string"||raw.trim()===""){throw new TypeError("Panel realtime URL is required.");}
	var url;
	try{url=new URL(raw,base);}catch(error){throw new TypeError("Panel realtime URL is invalid.");}
	if(url.protocol!=="http:"&&url.protocol!=="https:"){throw new TypeError("Panel realtime URL must use HTTP or HTTPS.");}
	if(url.username||url.password||url.hash){throw new TypeError("Panel realtime URL cannot contain credentials or a fragment.");}
	if(url.search&&!allowQuery){throw new TypeError("Panel realtime URL query parameters require explicit allowQuery.");}
	url.searchParams.forEach(function(value,name){if(credentialQueryName(name)){throw new TypeError("Panel realtime credential-named query keys are not accepted.");}});
	var baseUrl=new URL(base);if(baseUrl.protocol!=="http:"&&baseUrl.protocol!=="https:"){throw new TypeError("Panel realtime base URL must use HTTP or HTTPS.");}
	if(baseUrl.protocol==="https:"&&url.protocol!=="https:"){throw new TypeError("Panel realtime transport cannot downgrade HTTPS.");}
	if(url.origin!==baseUrl.origin&&allowedOrigins.indexOf(url.origin)<0){throw new TypeError("Panel realtime cross-origin transport requires an exact allowed origin.");}
	return url.href;
}

function normalizeOptions(options){
	options=options||{};
	var locationValue=options.baseUrl||(global.location&&global.location.href)||"http://localhost/";
	if(options.allowCrossOrigin!==undefined){throw new TypeError("Panel realtime allowCrossOrigin is unsupported; configure allowedOrigins.");}
	var baseLocation=new URL(locationValue);var allowedOrigins=normalizeAllowedOrigins(options.allowedOrigins,baseLocation);var normalizedUrl=normalizeUrl(options.url,locationValue,allowedOrigins,options.allowQuery===true);
	var method=String(options.method||"POST").toUpperCase();
	if(method!=="GET"&&method!=="POST"){throw new TypeError("Panel realtime method must be GET or POST.");}
	if(method==="GET"&&(options.body!==undefined||typeof options.bodyFactory==="function")){throw new TypeError("Panel realtime GET transport cannot configure a request body.");}
	var fetchFn=options.fetch||global.fetch;
	if(typeof fetchFn!=="function"){throw new TypeError("Panel realtime fetch implementation is required.");}
	var AbortControllerClass=options.AbortController||global.AbortController;
	var TextDecoderClass=options.TextDecoder||global.TextDecoder;
	var TextEncoderClass=options.TextEncoder||global.TextEncoder;
	if(typeof AbortControllerClass!=="function"||typeof TextDecoderClass!=="function"||typeof TextEncoderClass!=="function"){throw new TypeError("Panel realtime requires AbortController, TextDecoder, and TextEncoder.");}
	var credentials=options.credentials||"same-origin";
	if(["omit","same-origin","include"].indexOf(credentials)<0){throw new TypeError("Panel realtime credentials mode is invalid.");}
	var crossOrigin=(new URL(normalizedUrl)).origin!==baseLocation.origin;var mode=options.mode||(crossOrigin?"cors":"same-origin");
	if(["same-origin","cors"].indexOf(mode)<0){throw new TypeError("Panel realtime fetch mode must be same-origin or cors.");}
	if(crossOrigin&&mode!=="cors"){throw new TypeError("Panel realtime cross-origin transport requires CORS mode.");}
	var initialIntent=options.subscriptionIntent===undefined||options.subscriptionIntent===null?null:token(options.subscriptionIntent,"Panel realtime subscription intent",4096);var intentProvider=typeof options.subscriptionIntentProvider==="function"?options.subscriptionIntentProvider:null;
	if(initialIntent===null&&intentProvider===null){throw new TypeError("Panel realtime subscription intent or provider is required.");}
	return {
		url:normalizedUrl,
		method:method,
		subscriptionIntent:initialIntent,subscriptionIntentProvider:intentProvider,intentRefreshTimeoutMs:integer(options.intentRefreshTimeoutMs,10000,1000,60000,"Panel realtime intent refresh timeout"),
		connectTimeoutMs:integer(options.connectTimeoutMs,15000,1000,120000,"Panel realtime connect timeout"),
		intentHeader:headerName(options.intentHeader||"X-Dataphyre-Realtime-Intent","Panel realtime intent header"),
		lastEventIdHeader:headerName(options.lastEventIdHeader||"Last-Event-ID","Panel realtime resume header"),
		headers:normalizeHeaders(options.headers),body:options.body,bodyFactory:options.bodyFactory,
		fetch:fetchFn,AbortController:AbortControllerClass,TextDecoder:TextDecoderClass,TextEncoder:TextEncoderClass,
		credentials:credentials,mode:mode,eventTarget:options.eventTarget||createTarget(),
		window:options.window===undefined?global:options.window,document:options.document===undefined?global.document:options.document,navigator:options.navigator===undefined?global.navigator:options.navigator,
		pauseWhenHidden:options.pauseWhenHidden!==false,
		setTimeout:options.setTimeout||global.setTimeout.bind(global),clearTimeout:options.clearTimeout||global.clearTimeout.bind(global),random:options.random||Math.random,now:typeof options.now==="function"?options.now:Date.now,
		baseRetryMs:integer(options.baseRetryMs,500,100,60000,"Panel realtime base retry"),maxRetryMs:integer(options.maxRetryMs,30000,250,120000,"Panel realtime maximum retry"),
		jitter:number(options.jitter,0.2,0,1,"Panel realtime retry jitter"),maxAttempts:integer(options.maxAttempts,20,1,100,"Panel realtime retry attempts"),
		heartbeatTimeoutMs:integer(options.heartbeatTimeoutMs,45000,5000,300000,"Panel realtime heartbeat timeout"),
		stableConnectionMs:integer(options.stableConnectionMs,30000,1000,300000,"Panel realtime stable connection window"),
		maxFrameBytes:integer(options.maxFrameBytes,262144,1024,2097152,"Panel realtime frame bytes"),maxEventBytes:integer(options.maxEventBytes,196608,1024,1048576,"Panel realtime event bytes"),
		maxBufferBytes:integer(options.maxBufferBytes,524288,2048,4194304,"Panel realtime parser buffer"),maxEventsPerRead:integer(options.maxEventsPerRead,1000,1,10000,"Panel realtime events per read"),
		maxRequestBytes:integer(options.maxRequestBytes,65536,0,1048576,"Panel realtime request bytes"),
		loadCursor:typeof options.loadCursor==="function"?options.loadCursor:null,saveCursor:typeof options.saveCursor==="function"?options.saveCursor:null,clearCursor:typeof options.clearCursor==="function"?options.clearCursor:null
	};
}

function Client(options){
	var normalized=normalizeOptions(options);PRIVATE.set(this,{options:normalized,lastEventId:""});
	this.stateValue="idle";
	this.running=false;
	this.terminal=false;
	this.attempt=0;
	this.serverRetryMs=null;
	this.pauseReasons=new Set();
	this.controller=null;
	this.waitTimer=null;
	this.waitResolve=null;
	this.resumeResolvers=[];
	this.loopPromise=null;
	this.cursorLoaded=false;
	this.listenersAttached=false;
	this.connectionsStarted=0;
	this.intentRefreshReason=null;
	this.refreshTimer=null;
	this.refreshReject=null;
	this.boundOffline=this.pause.bind(this,"offline");
	this.boundOnline=this.resume.bind(this,"offline");
	this.boundVisibility=this.onVisibility.bind(this);
}

Client.prototype.addEventListener=function(type,listener,options){optionsOf(this).eventTarget.addEventListener(type,listener,options);return this;};
Client.prototype.removeEventListener=function(type,listener,options){optionsOf(this).eventTarget.removeEventListener(type,listener,options);return this;};
Client.prototype.dispatch=function(name,detail){optionsOf(this).eventTarget.dispatchEvent(createEvent(PREFIX+name,frozen(detail)));};
Client.prototype.setState=function(state,detail){
	if(STATES.indexOf(state)<0){throw new TypeError("Panel realtime state is invalid.");}
	if(this.stateValue===state){return;}
	this.stateValue=state;
	this.dispatch("state",{state:state,detail:detail||null});
};
Client.prototype.state=function(){var options=optionsOf(this);return frozen({type:"panel_realtime_client",version:VERSION,state:this.stateValue,running:this.running,terminal:this.terminal,paused:Array.from(this.pauseReasons).sort(),has_resume_cursor:cursorOf(this)!=="",attempt:this.attempt,url_origin:(new URL(options.url)).origin,method:options.method,subscription_intent_exposed:false,last_event_id_exposed:false,headers_exposed:false,body_exposed:false});};

Client.prototype.start=function(){
	if(this.running){return this;}
	this.running=true;this.terminal=false;this.attachLifecycle();
	this.loopPromise=this.run();
	return this;
};
Client.prototype.settled=function(){return this.loopPromise||Promise.resolve();};
Client.prototype.stop=function(reason){
	reason=safeReason(reason||"host");
	if(!this.running&&this.stateValue==="stopped"){return this;}
	this.running=false;this.terminal=false;this.abortActive();this.interruptWait();this.interruptRefresh();this.wakeResume();this.detachLifecycle();this.setState("stopped",{reason:reason});this.dispatch("close",{reason:reason});
	return this;
};
Client.prototype.pause=function(reason){
	reason=safeReason(reason||"manual");var added=!this.pauseReasons.has(reason);this.pauseReasons.add(reason);
	this.abortActive();this.interruptWait();this.interruptRefresh();if(added){this.setState("paused",{reason:reason});this.dispatch("pause",{reason:reason,reasons:Array.from(this.pauseReasons).sort()});}
	return this;
};
Client.prototype.resume=function(reason){
	reason=safeReason(reason||"manual");var removed=this.pauseReasons.delete(reason);
	if(removed&&this.pauseReasons.size===0){this.dispatch("resume",{reason:reason});this.wakeResume();}
	return this;
};
Client.prototype.clearResumeCursor=async function(){
	var options=optionsOf(this);setCursor(this,"");
	if(options.clearCursor){try{await Promise.resolve(options.clearCursor());}catch(error){this.dispatch("error",{code:"cursor_clear_failed",message:"Panel realtime resume cursor could not be cleared.",retryable:false,phase:"cursor"});}}
	return this;
};
Client.prototype.reconfigure=async function(subscriptionIntent,clearCursor){
	var previous=this.loopPromise;this.stop("reconfigure");if(previous){await previous;}optionsOf(this).subscriptionIntent=token(subscriptionIntent,"Panel realtime subscription intent",4096);
	this.pauseReasons.clear();this.attempt=0;this.serverRetryMs=null;this.connectionsStarted=0;this.intentRefreshReason=null;if(clearCursor!==false){await this.clearResumeCursor();}
	return this.start();
};

Client.prototype.attachLifecycle=function(){
	if(this.listenersAttached){return;}
	var options=optionsOf(this),windowValue=options.window,documentValue=options.document,navigatorValue=options.navigator;
	this.pauseReasons.delete("offline");this.pauseReasons.delete("hidden");
	if(windowValue&&typeof windowValue.addEventListener==="function"){windowValue.addEventListener("offline",this.boundOffline);windowValue.addEventListener("online",this.boundOnline);}
	if(documentValue&&typeof documentValue.addEventListener==="function"&&options.pauseWhenHidden){documentValue.addEventListener("visibilitychange",this.boundVisibility);}
	this.listenersAttached=true;
	if(navigatorValue&&navigatorValue.onLine===false){this.pause("offline");}
	if(documentValue&&documentValue.hidden===true&&options.pauseWhenHidden){this.pause("hidden");}
};
Client.prototype.detachLifecycle=function(){
	if(!this.listenersAttached){return;}
	var options=optionsOf(this),windowValue=options.window,documentValue=options.document;
	if(windowValue&&typeof windowValue.removeEventListener==="function"){windowValue.removeEventListener("offline",this.boundOffline);windowValue.removeEventListener("online",this.boundOnline);}
	if(documentValue&&typeof documentValue.removeEventListener==="function"){documentValue.removeEventListener("visibilitychange",this.boundVisibility);}
	this.listenersAttached=false;
};
Client.prototype.onVisibility=function(){var documentValue=optionsOf(this).document;if(!documentValue){return;}if(documentValue.hidden){this.pause("hidden");}else{this.resume("hidden");}};
Client.prototype.abortActive=function(){if(this.controller){this.controller.abort();this.controller=null;}};
Client.prototype.interruptWait=function(){if(this.waitTimer!==null){optionsOf(this).clearTimeout(this.waitTimer);this.waitTimer=null;}if(this.waitResolve){var resolve=this.waitResolve;this.waitResolve=null;resolve();}};
Client.prototype.interruptRefresh=function(){if(this.refreshTimer!==null){optionsOf(this).clearTimeout(this.refreshTimer);this.refreshTimer=null;}if(this.refreshReject){var reject=this.refreshReject;this.refreshReject=null;reject(new RuntimeSignal("intent_refresh_interrupted","Panel realtime intent refresh was interrupted.",true,false));}};
Client.prototype.wakeResume=function(){var resolvers=this.resumeResolvers.splice(0);resolvers.forEach(function(resolve){resolve();});};
Client.prototype.waitUntilResumed=function(){var self=this;if(this.pauseReasons.size===0||!this.running){return Promise.resolve();}return new Promise(function(resolve){self.resumeResolvers.push(resolve);});};
Client.prototype.wait=function(milliseconds){
	var self=this,options=optionsOf(this);if(milliseconds<=0||!this.running){return Promise.resolve();}
	return new Promise(function(resolve){self.waitResolve=resolve;self.waitTimer=options.setTimeout(function(){self.waitTimer=null;self.waitResolve=null;resolve();},milliseconds);});
};

Client.prototype.run=async function(){
	await this.loadCursor();
	while(this.running&&!this.terminal){
		if(this.pauseReasons.size>0){this.setState("paused",{reasons:Array.from(this.pauseReasons).sort()});await this.waitUntilResumed();continue;}
		this.attempt++;
		try{await this.connectOnce();if(this.running){fail("stream_ended","Panel realtime stream ended.",true);}}
		catch(error){
			if(!this.running){break;}
			if(this.pauseReasons.size>0){continue;}
			var failure=safeError(error);
			if(!failure.reported){this.dispatch("error",{code:failure.code,message:failure.message,retryable:failure.retryable,phase:"connection"});}
			if(!failure.retryable){this.finishTerminal(failure.code);break;}
			if(this.attempt>=optionsOf(this).maxAttempts){this.dispatch("error",{code:"retry_exhausted",message:"Panel realtime retry limit was reached.",retryable:false,phase:"retry"});this.finishTerminal("retry_exhausted");break;}
			var retryAttempt=Math.max(1,this.attempt);var delay=this.retryDelay(retryAttempt);this.setState("backoff",{attempt:retryAttempt,delay_ms:delay});this.dispatch("retry",{attempt:retryAttempt,delay_ms:delay,reason:failure.code});await this.wait(delay);
		}
	}
	return this.state();
};
Client.prototype.loadCursor=async function(){
	var options=optionsOf(this);if(this.cursorLoaded||!options.loadCursor){this.cursorLoaded=true;return;}this.cursorLoaded=true;
	try{var value=await Promise.resolve(options.loadCursor());if(value!==null&&value!==undefined&&value!==""){setCursor(this,token(String(value),"Panel realtime resume cursor",4096));}}
	catch(error){setCursor(this,"");this.dispatch("error",{code:"cursor_load_failed",message:"Panel realtime resume cursor could not be loaded.",retryable:false,phase:"cursor"});}
};
Client.prototype.retryDelay=function(attempt){
	var options=optionsOf(this),exponent=Math.min(20,Math.max(0,attempt-1));var base=this.serverRetryMs===null?Math.min(options.maxRetryMs,options.baseRetryMs*Math.pow(2,exponent)):Math.min(options.maxRetryMs,this.serverRetryMs);
	var random;try{random=Number(options.random());}catch(error){random=0.5;}if(!Number.isFinite(random)||random<0||random>1){random=0.5;}
	var span=base*options.jitter;return Math.max(0,Math.min(options.maxRetryMs,Math.round(base-span+(span*2*random))));
};
Client.prototype.now=function(){var value;try{value=Number(optionsOf(this).now());}catch(error){value=NaN;}return Number.isFinite(value)?value:Date.now();};
Client.prototype.markConnectionStable=function(openedAt){if(this.now()-openedAt>=optionsOf(this).stableConnectionMs){this.attempt=0;}};

function requestBody(client){
	var options=optionsOf(client),value=typeof options.bodyFactory==="function"?options.bodyFactory(frozen({attempt:client.attempt,hasResumeCursor:cursorOf(client)!==""})):options.body;
	if(value===undefined||value===null){return null;}
	if(value&&typeof value.then==="function"){throw new RuntimeSignal("request_body_invalid","Panel realtime bodyFactory must return synchronously.",false,false);}
	if(typeof value!=="string"){try{value=JSON.stringify(value);}catch(error){throw new RuntimeSignal("request_body_invalid","Panel realtime request body is not valid JSON.",false,false);}}
	if(utf8Bytes(value,options.TextEncoder)>options.maxRequestBytes){throw new RuntimeSignal("request_body_too_large","Panel realtime request body exceeds its byte bound.",false,false);}
	return value;
}
function requestHeaders(client,body){
	var options=optionsOf(client),headers=Object.assign({},options.headers);
	var reserved=["accept","cache-control",options.intentHeader.toLowerCase(),options.lastEventIdHeader.toLowerCase()];Object.keys(headers).forEach(function(name){if(reserved.indexOf(name.toLowerCase())>=0){delete headers[name];}});
	headers.Accept="text/event-stream";headers["Cache-Control"]="no-cache";headers[options.intentHeader]=options.subscriptionIntent;
	if(cursorOf(client)){headers[options.lastEventIdHeader]=cursorOf(client);}
	if(body!==null&&!Object.keys(headers).some(function(name){return name.toLowerCase()==="content-type";})){headers["Content-Type"]="application/json; charset=utf-8";}
	return headers;
}
function refreshSubscriptionIntent(client,reason){
	var self=client,options=optionsOf(client),provider=options.subscriptionIntentProvider;if(!provider){return Promise.reject(new RuntimeSignal("intent_refresh_unavailable","Panel realtime subscription intent refresh is unavailable.",false,false));}
	return new Promise(function(resolve,reject){
		var settled=false;self.refreshReject=function(error){if(settled){return;}settled=true;self.refreshReject=null;reject(error);};self.refreshTimer=options.setTimeout(function(){if(settled){return;}settled=true;self.refreshTimer=null;self.refreshReject=null;reject(new RuntimeSignal("intent_refresh_timeout","Panel realtime subscription intent refresh timed out.",true,false));},options.intentRefreshTimeoutMs);
		Promise.resolve().then(function(){return provider(frozen({reason:reason,attempt:self.attempt,hasResumeCursor:cursorOf(self)!==""}));}).then(function(value){if(settled){return;}settled=true;options.clearTimeout(self.refreshTimer);self.refreshTimer=null;self.refreshReject=null;try{options.subscriptionIntent=token(value,"Panel realtime subscription intent",4096);}catch(error){reject(new RuntimeSignal("intent_refresh_invalid","Panel realtime subscription intent provider returned an invalid value.",false,false));return;}resolve();},function(){if(settled){return;}settled=true;options.clearTimeout(self.refreshTimer);self.refreshTimer=null;self.refreshReject=null;reject(new RuntimeSignal("intent_refresh_failed","Panel realtime subscription intent refresh failed.",true,false));});
	});
}
async function ensureSubscriptionIntent(client){
	var options=optionsOf(client),reason=client.intentRefreshReason;if(options.subscriptionIntent===null){reason=reason||"initial";}else if(client.connectionsStarted>0&&options.subscriptionIntentProvider){reason=reason||"reconnect";}
	if(reason!==null){await refreshSubscriptionIntent(client,reason);client.intentRefreshReason=null;}
	if(options.subscriptionIntent===null){throw new RuntimeSignal("subscription_intent_missing","Panel realtime subscription intent is unavailable.",false,false);}
}
function fetchResponse(options,init,controller){
	return new Promise(function(resolve,reject){
		var settled=false,timedOut=false,timer=null;function settle(callback,value){if(settled){return;}settled=true;if(timer!==null){options.clearTimeout(timer);timer=null;}controller.signal.removeEventListener("abort",aborted);callback(value);}function aborted(){settle(reject,new RuntimeSignal(timedOut?"connect_timeout":"aborted",timedOut?"Panel realtime connection timed out.":"Panel realtime request was aborted.",true,false));}
		controller.signal.addEventListener("abort",aborted,{once:true});timer=options.setTimeout(function(){if(settled){return;}timedOut=true;controller.abort();},options.connectTimeoutMs);Promise.resolve().then(function(){return options.fetch(options.url,init);}).then(function(response){settle(resolve,response);},function(error){settle(reject,error);});
	});
}
Client.prototype.connectOnce=async function(){
	this.setState("connecting",{attempt:this.attempt});
	await ensureSubscriptionIntent(this);if(!this.running){throw new RuntimeSignal("aborted","Panel realtime request was aborted.",true,false);}var options=optionsOf(this),body=options.method==="POST"?requestBody(this):null;var headers=requestHeaders(this,body);var controller=new options.AbortController();this.controller=controller;this.connectionsStarted++;
	var response;
	try{response=await fetchResponse(options,{method:options.method,headers:headers,body:body===null?undefined:body,credentials:options.credentials,mode:options.mode,cache:"no-store",redirect:"error",signal:controller.signal},controller);}
	catch(error){if(this.controller===controller){this.controller=null;}throw safeError(error);}
	try{
		if(response.redirected){throw new RuntimeSignal("redirect_rejected","Panel realtime redirects are not accepted.",false,false);}
		var contentType=response.headers&&typeof response.headers.get==="function"?String(response.headers.get("content-type")||"").toLowerCase():"";
		if(!/^text\/event-stream(?:\s*;|$)/.test(contentType)){throw new RuntimeSignal("content_type_invalid","Panel realtime response must be text/event-stream.",false,false);}
		if(!response.body||typeof response.body.getReader!=="function"){throw new RuntimeSignal("stream_body_missing","Panel realtime response body is not stream-readable.",false,false);}
		if(response.ok){this.setState("open",{status:response.status});this.dispatch("open",{status:response.status});}
		await this.consume(response.body,controller);
		if(!response.ok){throw new RuntimeSignal("http_"+response.status,"Panel realtime endpoint returned HTTP "+response.status+".",response.status===408||response.status===425||response.status===429||response.status>=500,false);}
	}
	finally{if(this.controller===controller){this.controller=null;}if(!controller.signal.aborted){controller.abort();}}
};

Client.prototype.readWithTimeout=function(reader,controller,deadlineAt){
	var self=this,options=optionsOf(this),timer=null;
	return new Promise(function(resolve,reject){
		var settled=false,remaining=Math.max(0,deadlineAt-self.now());if(remaining===0){controller.abort();reject(new RuntimeSignal("heartbeat_timeout","Panel realtime heartbeat timed out.",true,false));return;}timer=options.setTimeout(function(){if(settled){return;}settled=true;controller.abort();reject(new RuntimeSignal("heartbeat_timeout","Panel realtime heartbeat timed out.",true,false));},Math.ceil(remaining));
		reader.read().then(function(result){if(settled){return;}settled=true;options.clearTimeout(timer);resolve(result);},function(error){if(settled){return;}settled=true;options.clearTimeout(timer);reject(error);});
	});
};
Client.prototype.consume=async function(body,controller){
	var options=optionsOf(this),reader=body.getReader();var decoder=new options.TextDecoder("utf-8",{fatal:true});var buffer="",openedAt=this.now(),frameDeadlineAt=openedAt+options.heartbeatTimeoutMs;
	try{
		while(this.running&&this.pauseReasons.size===0){
			var result=await this.readWithTimeout(reader,controller,frameDeadlineAt);if(result.done){break;}
			if(!(result.value instanceof Uint8Array)){throw new RuntimeSignal("stream_chunk_invalid","Panel realtime stream yielded an invalid chunk.",false,false);}
			try{buffer+=decoder.decode(result.value,{stream:true});}catch(error){throw new RuntimeSignal("utf8_invalid","Panel realtime stream is not valid UTF-8.",false,false);}
			if(utf8Bytes(buffer,options.TextEncoder)>options.maxBufferBytes){throw new RuntimeSignal("parser_buffer_exceeded","Panel realtime parser buffer exceeded its byte bound.",false,false);}
			var processed=0,match;
			while((match=/\r\n\r\n|\n\n|\r\r/.exec(buffer))!==null){
				var frame=buffer.slice(0,match.index);buffer=buffer.slice(match.index+match[0].length);if(frame===""){continue;}
				processed++;if(processed>options.maxEventsPerRead){throw new RuntimeSignal("event_rate_exceeded","Panel realtime read exceeded its event count bound.",false,false);}
				await this.parseFrame(frame);frameDeadlineAt=this.now()+options.heartbeatTimeoutMs;this.markConnectionStable(openedAt);
			}
		}
		try{buffer+=decoder.decode();}catch(error){throw new RuntimeSignal("utf8_invalid","Panel realtime stream is not valid UTF-8.",false,false);}
		if(buffer.trim()!==""&&this.running&&this.pauseReasons.size===0){throw new RuntimeSignal("incomplete_frame","Panel realtime stream ended with an incomplete frame.",true,false);}
	}
	catch(error){throw error;}
	finally{try{await reader.cancel();}catch(error){}if(typeof reader.releaseLock==="function"){try{reader.releaseLock();}catch(error){}}}
};
Client.prototype.parseFrame=async function(frame){
	var options=optionsOf(this);if(utf8Bytes(frame,options.TextEncoder)>options.maxFrameBytes){throw new RuntimeSignal("frame_too_large","Panel realtime SSE frame exceeds its byte bound.",false,false);}
	var lines=frame.replace(/\r\n/g,"\n").replace(/\r/g,"\n").split("\n");var eventType="message",id=null,data=[],comment=false;
	for(var index=0;index<lines.length;index++){
		var line=lines[index];if(line.charAt(0)===":"){comment=true;continue;}var separator=line.indexOf(":");var field=separator<0?line:line.slice(0,separator);var value=separator<0?"":line.slice(separator+1);if(value.charAt(0)===" "){value=value.slice(1);}
		if(field==="event"){eventType=value||"message";}else if(field==="data"){data.push(value);}else if(field==="id"){id=value;}else if(field==="retry"&&/^\d{1,8}$/.test(value)){this.serverRetryMs=Math.max(250,Math.min(options.maxRetryMs,Number(value)));}
	}
	if(data.length===0){if(comment){this.dispatch("heartbeat",{at:this.now()});}return;}
	if(!/^[a-z][a-z0-9_.:-]{0,95}$/.test(eventType)){throw new RuntimeSignal("event_type_invalid","Panel realtime event type is invalid.",false,false);}
	if(id!==null){id=token(id,"Panel realtime Last-Event-ID",4096);}
	var json=data.join("\n");if(utf8Bytes(json,options.TextEncoder)>options.maxEventBytes){throw new RuntimeSignal("event_too_large","Panel realtime event data exceeds its byte bound.",false,false);}
	var envelope;try{envelope=JSON.parse(json);}catch(error){throw new RuntimeSignal("event_json_invalid","Panel realtime event data is not valid JSON.",false,false);}
	if(!envelope||typeof envelope!=="object"||Array.isArray(envelope)){throw new RuntimeSignal("event_envelope_invalid","Panel realtime event envelope must be an object.",false,false);}
	await this.handleEnvelope(eventType,envelope,id);
};
Client.prototype.persistCursor=async function(id){
	var options=optionsOf(this);if(id===null){return;}setCursor(this,id);
	if(options.saveCursor){try{await Promise.resolve(options.saveCursor(id));}catch(error){this.dispatch("error",{code:"cursor_persistence_failed",message:"Panel realtime resume cursor could not be persisted.",retryable:false,phase:"cursor"});}}
};
Client.prototype.handleEnvelope=async function(eventType,envelope,id){
	if(envelope.schema_version!==1){throw new RuntimeSignal("schema_version_unsupported","Panel realtime event schema version is unsupported.",false,false);}
	if(eventType!=="panel.error"&&id===null){throw new RuntimeSignal("event_id_required","Panel realtime data events require a signed Last-Event-ID.",false,false);}
	if(eventType==="panel.error"){
		await this.persistCursor(id);var retryable=envelope.retryable===true;var code=typeof envelope.code==="string"?envelope.code:"stream_error";var message=typeof envelope.message==="string"?envelope.message:"Panel realtime stream failed.";if(code==="intent_expired"&&optionsOf(this).subscriptionIntentProvider){retryable=true;this.intentRefreshReason="intent_expired";}
		this.dispatch("error",{code:code,message:message,retryable:retryable,phase:"stream"});throw new RuntimeSignal(code,message,retryable,true);
	}
	if(eventType==="panel.reset"){
		await this.persistCursor(id);this.dispatch("reset",{reason:typeof envelope.reason==="string"?envelope.reason:"unknown",action:"rehydrate",hasResumeCursor:cursorOf(this)!==""});throw new RuntimeSignal("reset_required","Panel realtime state must be rehydrated.",false,true);
	}
	if(eventType==="panel.cursor"){
		await this.persistCursor(id);this.dispatch("cursor",{hasResumeCursor:cursorOf(this)!==""});return;
	}
	this.dispatch("event",{eventType:eventType,envelope:envelope,hasResumeCursor:id!==null||cursorOf(this)!==""});await this.persistCursor(id);
};
Client.prototype.finishTerminal=function(reason){this.running=false;this.terminal=true;this.abortActive();this.interruptWait();this.interruptRefresh();this.wakeResume();this.detachLifecycle();this.setState("terminal",{reason:reason});this.dispatch("close",{reason:reason});};

function create(options){return new Client(options);}
function manifest(){return frozen({type:"panel_realtime_client_runtime",version:VERSION,transport:"fetch_streamed_sse",native_event_source:false,abort_controller:true,signed_last_event_id:true,offline_pause:true,visibility_pause:true,connect_timeout:true,heartbeat_timeout:true,heartbeat_deadline_resets_on_complete_frame:true,stable_connection_retry_reset:true,bounded_parser:true,bounded_backoff:true,host_owned_url:true,query_requires_opt_in:true,credential_named_query_keys_rejected:true,cross_origin_requires_exact_allowlist:true,https_downgrade_rejected:true,host_owned_headers:true,host_owned_authentication:true,host_owned_csrf:true,host_owned_origin_policy:true,host_owned_route:true,host_owned_subscription_intent_provider:true,subscription_intent_provider_bounded:true,public_credentials_exposed:false,html_evaluation:false});}

var api=frozen({version:VERSION,create:create,Client:Client,events:frozen({state:PREFIX+"state",open:PREFIX+"open",event:PREFIX+"event",cursor:PREFIX+"cursor",heartbeat:PREFIX+"heartbeat",retry:PREFIX+"retry",pause:PREFIX+"pause",resume:PREFIX+"resume",reset:PREFIX+"reset",error:PREFIX+"error",close:PREFIX+"close"}),manifest:manifest});
if(typeof module!=="undefined"&&module.exports){module.exports=api;}
global.DataphyrePanelRealtime=api;
})(typeof globalThis!=="undefined"?globalThis:this);
