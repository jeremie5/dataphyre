"use strict";

const assert=require("node:assert/strict");
const fs=require("node:fs");
const path=require("node:path");
const vm=require("node:vm");

const panelRoot=path.resolve(__dirname,"..");
const scriptFile=path.join(panelRoot,"Framework","Rendering","Assets","PanelRendererAssetsWidgetRuntimeScripts.php");
const cssFile=path.join(panelRoot,"Framework","Rendering","Assets","PanelRendererAssetsWidgetRuntimeCss.php");
const php=fs.readFileSync(scriptFile,"utf8");
const match=php.match(/return <<<'JS'\r?\n([\s\S]*?)\r?\nJS;/);
assert.ok(match,"widget runtime nowdoc is extractable");
const source=match[1];

function createIsland(id,panel){
	const attributes={
		"data-dp-widget-endpoint":"/panel/widgets/runtime",
		"data-dp-widget-snapshot":"snapshot-"+id,
		"data-dp-widget-binding":"v1.binding-"+id,
		"data-dp-widget-version":"1",
		"data-dp-widget-retry-limit":"0",
		"data-dp-widget-refresh":"manual",
	};
	const status={textContent:"",hidden:true};
	return {
		nodeType:1,id,dataset:{},isConnected:true,panel,status,attributes,
		matches(selector){ return selector==="[data-dp-widget-island]"; },
		closest(selector){ return selector===".dp-panel"?this.panel:null; },
		getAttribute(name){ return Object.prototype.hasOwnProperty.call(this.attributes,name)?this.attributes[name]:null; },
		setAttribute(name,value){ this.attributes[name]=String(value); },
		querySelector(selector){ return selector.indexOf("dp-panel-widget-status")!==-1?status:null; },
		querySelectorAll(){ return []; },
	};
}

function createPanel(islands=[]){
	return {
		nodeType:1,dataset:{},isConnected:true,
		matches(selector){ return selector===".dp-panel"; },
		closest(selector){ return selector===".dp-panel"?this:null; },
		querySelectorAll(selector){
			if(selector==="[data-dp-widget-island]"){return islands;}
			if(selector===".dp-panel"){return [];}
			return [];
		},
	};
}

function createWrapper(islands=[],panels=[]){
	return {
		nodeType:1,
		matches(){ return false; },
		querySelectorAll(selector){
			if(selector==="[data-dp-widget-island]"){return islands;}
			if(selector===".dp-panel"){return panels;}
			return [];
		},
	};
}

function bootContext(initialRoots=[]){
	const observers=[];
	const microtasks=[];
	const windowListeners={};
	const documentListeners={};
	const fetches=[];
	let fetchHandler=null;
	class MutationObserver {
		constructor(callback){this.callback=callback;this.target=null;this.disconnected=false;observers.push(this);}
		observe(target){this.target=target;}
		disconnect(){this.disconnected=true;}
	}
	const documentElement={contains(node){return !!node.isConnected;}};
	const document={
		readyState:"loading",documentElement,hidden:false,
		querySelectorAll(selector){return selector===".dp-panel"?initialRoots:[];},
		addEventListener(type,listener){documentListeners[type]=listener;},
	};
	const window={
		MutationObserver,AbortController:globalThis.AbortController,
		crypto:{randomUUID(){return "00000000-0000-4000-8000-000000000001";}},
		navigator:{onLine:true},
		queueMicrotask(callback){microtasks.push(callback);},
		setTimeout,clearTimeout,setInterval,clearInterval,
		addEventListener(type,listener){windowListeners[type]=listener;},
	};
	const context={
		window,document,navigator:window.navigator,MutationObserver,
		dpPanelListen(target,type,listener,options){target.addEventListener(type,listener,options);return listener;},
		fetch(...args){fetches.push(args);return fetchHandler?fetchHandler(...args):Promise.resolve({status:200,headers:{get(){return "0";}},text(){return Promise.resolve("");}});},
		console,Promise,Set,WeakMap,Array,Object,Number,String,Boolean,Math,JSON,Error,Uint8Array,
	};
	window.window=window;
	vm.createContext(context);
	vm.runInContext(source,context,{filename:"panel-widget-runtime.js"});
	return {
		context,observers,microtasks,windowListeners,documentListeners,fetches,
		setFetchHandler(handler){fetchHandler=handler;},
		flushMicrotasks(){while(microtasks.length){microtasks.shift()();}},
	};
}

function successResult(island,status="ready"){
	const terminal=status==="unmounted";
	return {
		type:"panel_widget_interaction_result",schema_version:1,adapter:"memory",island_id:island.id,
		state:{status,version:2,data:status==="ready"?{value:2}:{},error_code:null,message:status==="ready"?"Updated.":"Ended."},
		endpoint:terminal?null:"/panel/widgets/runtime",snapshot:terminal?null:"next-snapshot",binding_tag:terminal?null:"v1.next-binding",replayed:false,retryable:false,http_status:200,
	};
}

function failureResult(island){
	return {
		type:"panel_widget_interaction_result",schema_version:1,adapter:"memory",island_id:island.id,
		state:{status:"error",version:0,data:[],error_code:"widget_conflict",message:"Try again."},
		endpoint:null,snapshot:null,binding_tag:null,replayed:false,retryable:true,http_status:409,
	};
}

async function main(){
	assert.equal(source.includes("�"),false,"runtime source has no replacement-character mojibake");
	assert.equal(source.includes("â€¦"),false,"runtime source has no encoded ellipsis mojibake");
	assert.equal(/document\.addEventListener\(["']click/.test(source),false,"runtime has no document-wide click delegation");
	assert.equal(fs.readFileSync(cssFile,"utf8").includes("!important"),false,"widget runtime CSS has zero priority overrides");

	const firstPanel=createPanel();
	const secondPanel=createPanel();
	const firstIsland=createIsland("dpwi-first",firstPanel);
	const secondIsland=createIsland("dpwi-second",secondPanel);
	firstPanel.querySelectorAll=selector=>selector==="[data-dp-widget-island]"?[firstIsland]:[];
	secondPanel.querySelectorAll=selector=>selector==="[data-dp-widget-island]"?[secondIsland]:[];
	const harness=bootContext([firstPanel,secondPanel]);
	const runtime=harness.context;
	const baseHeaders=runtime.dpPanelWidgetRequestHeaders({island:firstIsland});
	assert.equal(baseHeaders["X-Requested-With"],"DataphyrePanelWidget","widget transport header stays exact");
	assert.equal(Object.prototype.hasOwnProperty.call(baseHeaders,"X-CSRF-Token"),false,"blank CSRF material is never synthesized");
	runtime.dpPanelWidgetPanel.csrfToken="panel-csrf";
	assert.equal(runtime.dpPanelWidgetRequestHeaders({island:firstIsland})["X-CSRF-Token"],"panel-csrf","host-provided Panel CSRF token is forwarded");
	firstIsland.attributes["data-dp-widget-csrf"]="island-csrf";
	assert.equal(runtime.dpPanelWidgetRequestHeaders({island:firstIsland})["X-CSRF-Token"],"island-csrf","island-scoped CSRF material wins over a global token");
	firstIsland.attributes["data-dp-widget-csrf"]="bad\r\ntoken";
	assert.equal(Object.prototype.hasOwnProperty.call(runtime.dpPanelWidgetRequestHeaders({island:firstIsland}),"X-CSRF-Token"),false,"header-breaking CSRF material is dropped");
	delete firstIsland.attributes["data-dp-widget-csrf"];
	delete runtime.dpPanelWidgetPanel.csrfToken;
	harness.context.document.querySelector=selector=>selector==='meta[name="csrf-token"]'?{getAttribute(){return "meta-csrf";}}:null;
	assert.equal(runtime.dpPanelWidgetRequestHeaders({island:firstIsland})["X-CSRF-Token"],"meta-csrf","standard CSRF meta tokens are supported without embedding credentials in manifests");
	runtime.dpPanelWidgetBoot();
	assert.equal(firstPanel.dataset.dpWidgetObserver,"1","first Panel root is scanned");
	assert.equal(secondPanel.dataset.dpWidgetObserver,"1","second Panel root is scanned");
	assert.equal(runtime.dpPanelWidgetInstances.has(firstIsland),true,"first root island is enhanced");
	assert.equal(runtime.dpPanelWidgetInstances.has(secondIsland),true,"second root island is enhanced");
	assert.equal(harness.observers.length,1,"one document-level observer owns lifecycle discovery");
	assert.equal(harness.observers[0].target,harness.context.document.documentElement,"observer covers ancestor wrapper changes");
	assert.equal(typeof harness.windowListeners.pagehide,"function","pagehide cleanup is installed");

	const dynamicPanel=createPanel();
	const dynamicIsland=createIsland("dpwi-dynamic",dynamicPanel);
	dynamicPanel.querySelectorAll=selector=>selector==="[data-dp-widget-island]"?[dynamicIsland]:[];
	harness.observers[0].callback([{removedNodes:[],addedNodes:[dynamicPanel]}]);
	assert.equal(runtime.dpPanelWidgetInstances.has(dynamicIsland),true,"dynamically inserted Panel roots are enhanced");

	const outsideIsland=createIsland("dpwi-outside",null);
	harness.observers[0].callback([{removedNodes:[],addedNodes:[outsideIsland]}]);
	assert.equal(runtime.dpPanelWidgetInstances.has(outsideIsland),false,"credential-bearing islands outside Panel roots stay inert");

	const movedWrapper=createWrapper([dynamicIsland]);
	dynamicIsland.panel=secondPanel;
	dynamicIsland.isConnected=true;
	harness.observers[0].callback([{removedNodes:[movedWrapper],addedNodes:[movedWrapper]}]);
	harness.flushMicrotasks();
	assert.equal(runtime.dpPanelWidgetInstances.has(dynamicIsland),true,"same-turn DOM moves do not dispose live islands");
	assert.equal(harness.fetches.length,0,"same-turn DOM moves do not send unmount");

	dynamicIsland.panel=null;
	dynamicIsland.isConnected=false;
	const ancestor=createWrapper([dynamicIsland],[dynamicPanel]);
	harness.observers[0].callback([{removedNodes:[ancestor],addedNodes:[]}]);
	harness.flushMicrotasks();
	assert.equal(runtime.dpPanelWidgetInstances.has(dynamicIsland),false,"ancestor removal disposes descendant islands");
	assert.equal(dynamicIsland.dataset.dpWidgetEnded,"1","disposed islands cannot silently resume stale credentials");
	assert.equal(harness.fetches.length,1,"ancestor removal sends one best-effort unmount");
	dynamicIsland.panel=dynamicPanel;
	dynamicIsland.isConnected=true;
	harness.observers[0].callback([{removedNodes:[],addedNodes:[dynamicPanel]}]);
	assert.equal(runtime.dpPanelWidgetInstances.has(dynamicIsland),false,"removed then reinserted roots do not reuse ended sessions");

	const validationIsland=createIsland("dpwi-validation",firstPanel);
	const instance={island:validationIsland,endpoint:"/old",snapshot:"old-snapshot",binding:"old-binding",version:7,sequence:1,disposed:false,busy:false,cleanups:[],controller:null,retryTimer:null,intervalTimer:null};
	assert.equal(runtime.dpPanelWidgetValidateResult(instance,successResult(validationIsland),200).state.version,2,"exact successful responses validate");
	assert.equal(runtime.dpPanelWidgetValidateResult(instance,successResult(validationIsland,"unmounted"),200).state.status,"unmounted","credential-free terminal responses validate");
	const normalizedFailure=runtime.dpPanelWidgetValidateResult(instance,failureResult(validationIsland),409);
	assert.equal(Array.isArray(normalizedFailure.state.data),false,"empty PHP maps normalize safely");
	assert.deepEqual(Object.keys(normalizedFailure.state.data),[],"normalized failure data stays empty");
	for(const mutate of [
		result=>{result.extra=true;},
		result=>{result.retryable="false";},
		result=>{result.replayed=0;},
		result=>{result.http_status=200.5;},
		result=>{result.adapter={};},
		result=>{result.state.extra=true;},
		result=>{result.state.status="loading";},
		result=>{result.state.data={password:"leak"};},
	]){
		const malformed=successResult(validationIsland);
		mutate(malformed);
		assert.throws(()=>runtime.dpPanelWidgetValidateResult(instance,malformed,200),/invalid_widget_response/);
	}
	assert.throws(()=>runtime.dpPanelWidgetValidateResult(instance,successResult(validationIsland),500),/invalid_widget_response/,"transport/body status mismatch is rejected");
	for(const mutate of [
		result=>{result.endpoint="/must-not-rotate";},
		result=>{result.snapshot="must-not-rotate";},
		result=>{result.binding_tag="must-not-rotate";},
		result=>{result.retryable=true;},
		result=>{result.state.error_code="terminal_error";},
		result=>{result.state.data={leaked:true};},
	]){
		const malformed=successResult(validationIsland,"unmounted");
		mutate(malformed);
		assert.throws(()=>runtime.dpPanelWidgetValidateResult(instance,malformed,200),/invalid_widget_response/,"terminal responses cannot retain credentials, retry, errors, or data");
	}
	for(const credential of ["endpoint","snapshot","binding_tag"]){
		const malformed=successResult(validationIsland);
		malformed[credential]=null;
		assert.throws(()=>runtime.dpPanelWidgetValidateResult(instance,malformed,200),/invalid_widget_response/,"ready responses require every rotated credential");
	}

	runtime.dpPanelWidgetApplyResult(instance,failureResult(validationIsland),1,null);
	assert.equal(instance.version,7,"failure version zero does not overwrite valid CAS version");
	assert.equal(instance.snapshot,"old-snapshot","failure does not erase a valid snapshot");
	assert.equal(instance.binding,"old-binding","failure does not erase a valid binding tag");

	let aborted=0;
	let cleaned=0;
	const endedIsland=createIsland("dpwi-ended",firstPanel);
	const endedInstance={island:endedIsland,endpoint:"/old",snapshot:"old",binding:"old",version:1,sequence:1,disposed:false,busy:false,cleanups:[()=>{cleaned++;}],controller:{abort(){aborted++;}},retryTimer:null,intervalTimer:null};
	runtime.dpPanelWidgetInstances.set(endedIsland,endedInstance);
	runtime.dpPanelWidgetMountedIslands.add(endedIsland);
	runtime.dpPanelWidgetApplyResult(endedInstance,successResult(endedIsland,"unmounted"),1,null);
	assert.equal(runtime.dpPanelWidgetInstances.has(endedIsland),false,"completed unmount tears down the instance without a second request");
	assert.equal(aborted,1,"completed unmount aborts retained transport handles");
	assert.equal(cleaned,1,"completed unmount removes local listeners");
	assert.equal(endedInstance.endpoint,"/old","completed unmount does not rotate the retained endpoint");
	assert.equal(endedInstance.snapshot,"old","completed unmount does not rotate the retained snapshot");
	assert.equal(endedInstance.binding,"old","completed unmount does not rotate the retained binding tag");

	const mismatchIsland=createIsland("dpwi-mismatch",firstPanel);
	const mismatchInstance={island:mismatchIsland,endpoint:"/old",snapshot:"old-snapshot",binding:"old-binding",version:3,sequence:0,queue:Promise.resolve(),controller:null,retryTimer:null,intervalTimer:null,retryLimit:0,disposed:false,busy:false,cleanups:[],unmountSent:false};
	harness.setFetchHandler(()=>Promise.resolve({status:500,headers:{get(){return "0";}},text(){return Promise.resolve(JSON.stringify(successResult(mismatchIsland)));}}));
	await runtime.dpPanelWidgetRequest(mismatchInstance,"refresh",null,{},"mismatch",0,null);
	assert.equal(mismatchInstance.version,3,"mismatched HTTP/body success cannot commit state");
	assert.equal(mismatchInstance.snapshot,"old-snapshot","mismatched HTTP/body success cannot rotate credentials");

	console.log("panel widget runtime adversarial audit passed");
}

main().catch(error=>{
	console.error(error&&error.stack?error.stack:error);
	process.exitCode=1;
});
