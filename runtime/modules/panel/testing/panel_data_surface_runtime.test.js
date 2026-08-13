"use strict";

const assert=require("node:assert/strict");
const fs=require("node:fs");
const path=require("node:path");
const vm=require("node:vm");

const panelRoot=path.resolve(__dirname,"..");
const scriptFile=path.join(panelRoot,"Framework","Rendering","Assets","PanelRendererAssetsDataSurfaceRuntimeScripts.php");
const cssFile=path.join(panelRoot,"Framework","Rendering","Assets","PanelRendererAssetsDataSurfaceCss.php");
const php=fs.readFileSync(scriptFile,"utf8");
const match=php.match(/return <<<'JS'\r?\n([\s\S]*?)\r?\nJS;/);
assert.ok(match,"DataSurface runtime nowdoc is extractable");
const source=match[1];
const css=fs.readFileSync(cssFile,"utf8");

function boot(){
	const documentListeners={};
	const windowListeners={};
	let csrf="";
	const document={
		readyState:"complete",
		querySelectorAll(selector){return selector===".dp-panel"?[]:[];},
		querySelector(selector){return selector==='meta[name="csrf-token"]'&&csrf?{getAttribute(){return csrf;}}:null;},
		addEventListener(type,listener){documentListeners[type]=listener;},
	};
	const window={
		location:{href:"https://panel.test/orders",origin:"https://panel.test"},
		addEventListener(type,listener){windowListeners[type]=listener;},
		fetch(){return Promise.reject(new Error("unexpected fetch"));},
		getComputedStyle(){return {direction:"ltr"};},
		DataphyrePanel:{},
	};
	window.window=window;
	const context={
		window,document,URL,AbortController,console,Promise,Set,Map,WeakMap,Array,Object,Number,String,Boolean,Math,JSON,Error,
		dpPanelListen(target,type,listener,options){target.addEventListener(type,listener,options);return listener;},
	};
	vm.createContext(context);
	vm.runInContext(source,context,{filename:"panel-data-surface-runtime.js"});
	return {context,documentListeners,windowListeners,setCsrf(value){csrf=value;}};
}

function intent(token="signed.token.value"){
	return {type:"panel_data_surface_intent",version:1,intent:token,issued_at:1000,expires_at:1300};
}

function result(){
	return {
		type:"panel_data_surface_window",version:1,definition:"orders",resource:"orders",surface:"table",
		projection:{fields:["id","name"],stable_key:"id",slots:{title:"name"},labels:{id:"ID",name:"Name"}},
		records:[{key:"1",position:0,visible:true,data:{id:1,name:"Ada"}},{key:"2",position:1,visible:false,data:{id:2,name:"Grace"}}],
		window:{start:0,length:1,overscan_before:0,overscan_after:1,effective_offset:0,fetch_limit:2,cursor_present:false},
		returned:2,visible:1,total:20,total_known:true,has_before:false,has_after:true,previous_intent:null,next_intent:intent(),
	};
}

function clone(value){return JSON.parse(JSON.stringify(value));}

function canvasSpec(surface="pivot"){
	return {
		type:"panel_data_canvas_spec",version:1,surface,roles:{row:"region",column:"status",value:"amount"},aggregate:"sum",
		interaction:{selection:"multiple",cross_filter_group:"orders",cross_filter_field:"status",drill_url:"/orders",drill_parameter:"record",editable:false},
		presentation:{frozen_fields:0,show_labels:true,show_legend:true,snap_to_grid:false,zoom:false},
		capabilities:{accessible_ssr:true,progressive_enhancement:true,signed_server_cross_filter:true,drill_through:true,keyboard_selection:true,mutation_protocol_required:false},
	};
}

function canvasResult(){
	const value=result();value.version=2;value.surface="pivot";value.canvas={type:"panel_data_canvas_model",version:1,surface:"pivot",record_count:2,model:{rows:[],columns:[],cells:[],aggregate:"sum",minimum:null,maximum:null},diagnostics:[]};return value;
}

function main(){
	assert.equal(source.includes("ï¿½"),false,"runtime has no replacement-character mojibake");
	assert.equal(source.includes("innerHTML"),false,"runtime never parses server data as HTML");
	assert.equal(source.includes("insertAdjacentHTML"),false,"runtime never inserts string-built markup");
	assert.equal(/document\.addEventListener\(["']click/.test(source),false,"runtime has no document-wide click delegation");
	assert.equal(source.includes('observe(document.documentElement||document,{childList:true,subtree:true})'),true,"one document observer discovers and releases dynamically mounted surfaces");
	assert.equal(source.includes("dpPanelDataSurfacePanels"),false,"runtime does not allocate one observer per Panel root");
	assert.equal(source.includes('"DOMContentLoaded"'),false,"deferred capability scripts do not retain a redundant DOM-ready listener");
	assert.equal(source.includes('"dp-panel:rendered"'),false,"document observation replaces the redundant Panel-rendered listener");
	assert.equal((source.match(/dpPanelListen\(/g)||[]).length,1,"runtime owns only its pagehide teardown listener");
	assert.equal(source.includes('credentials:"same-origin"'),true,"window transport stays same-origin credential scoped");
	assert.equal(source.includes("textContent"),true,"record content is assigned through textContent");
	assert.equal(css.includes("overflow-x:auto"),false,"DataSurface CSS never falls back to horizontal scrolling");
	assert.equal(css.includes("@container dp-data-surface"),true,"mobile reflow is container-owned");
	assert.equal(css.includes("forced-colors:active"),true,"forced colors are first-class");
	assert.equal(/(?:transition|animation|scroll-behavior)\s*:/.test(css),false,"the capability adds no motion that needs a local reduced-motion override");
	assert.equal(css.includes('[dir="rtl"]'),true,"RTL has an explicit logical override");

	const harness=boot();
	const runtime=harness.context;
	assert.equal(runtime.dpPanelDataSurfaceEndpoint("/panel/windows"),"https://panel.test/panel/windows");
	assert.equal(runtime.dpPanelDataSurfaceEndpoint("https://panel.test/windows"),"https://panel.test/windows");
	for(const value of ["https://evil.test/window","//evil.test/window","javascript:alert(1)","/bad\\path","/bad\npath",""]){assert.equal(runtime.dpPanelDataSurfaceEndpoint(value),"");}
	assert.equal(runtime.dpPanelDataSurfaceMedia("javascript:alert(1)"),"");
	assert.equal(runtime.dpPanelDataSurfaceMedia("/safe.jpg"),"https://panel.test/safe.jpg");
	assert.equal(runtime.dpPanelDataSurfaceText(null),"Not set");
	assert.equal(runtime.dpPanelDataSurfaceText(true),"Yes");
	assert.equal(runtime.dpPanelDataSurfaceText({safe:"value"}),'{"safe":"value"}');

	const state={config:{definition:"orders",surface:"table"}};
	const valid=result();
	assert.equal(runtime.dpPanelDataSurfaceValidate(state,valid),valid,"exact bounded response validates");
	assert.equal(runtime.dpPanelDataSurfaceIntent(valid.next_intent),true);
	assert.equal(runtime.dpPanelDataSurfaceIntent(null),true);
	for(const mutate of [
		value=>{value.extra=true;},
		value=>{value.type="other";},
		value=>{value.definition="customers";},
		value=>{value.surface="list";},
		value=>{value.projection.extra=true;},
		value=>{value.window.extra=true;},
		value=>{value.returned=1;},
		value=>{value.visible=2;},
		value=>{value.total_known=false;},
		value=>{value.has_after="yes";},
		value=>{value.records[1].key="1";},
		value=>{value.records[0].data={value:Infinity};},
		value=>{value.next_intent.intent="x".repeat(16385);},
	]){
		const malformed=clone(valid);mutate(malformed);
		assert.throws(()=>runtime.dpPanelDataSurfaceValidate(state,malformed),/invalid_surface_response/);
	}
	assert.equal(runtime.dpPanelDataSurfaceSafe({nested:{safe:true}},0,{nodes:0}),true);
	assert.equal(runtime.dpPanelDataSurfaceSafe({value:"x".repeat(65537)},0,{nodes:0}),false);
	let deep=true;for(let index=0;index<18;index++){deep={next:deep};}
	assert.equal(runtime.dpPanelDataSurfaceSafe(deep,0,{nodes:0}),false);
	const spec=canvasSpec();
	assert.equal(runtime.dpPanelDataCanvasSpec(spec,"pivot"),spec,"advanced canvas config validates exactly");
	for(const mutate of [value=>{value.extra=true;},value=>{value.surface="map";},value=>{value.interaction.selection="none";},value=>{delete value.presentation.zoom;}]){const malformed=clone(spec);mutate(malformed);assert.equal(runtime.dpPanelDataCanvasSpec(malformed,"pivot"),null);}
	const advanced=canvasResult(),advancedState={config:{definition:"orders",surface:"pivot"}};
	assert.equal(runtime.dpPanelDataSurfaceValidate(advancedState,advanced),advanced,"v2 canvas window validates");
	for(const mutate of [value=>{value.version=1;},value=>{delete value.canvas;},value=>{value.canvas.record_count=3;},value=>{value.canvas.surface="map";},value=>{value.canvas.diagnostics=[{code:"bad",count:3}];}]){const malformed=clone(advanced);mutate(malformed);assert.throws(()=>runtime.dpPanelDataSurfaceValidate(advancedState,malformed),/invalid_surface_response/);}
	assert.deepEqual(Array.from(runtime.dpPanelDataCanvasValues(["paid","paid",1,true,null])),["paid",1,true,null]);
	assert.deepEqual(Array.from(runtime.dpPanelDataCanvasValues([Infinity,{},"safe"])),["safe"]);
	assert.equal(runtime.dpPanelDataCanvasClamp(120,0,100),100);assert.equal(runtime.dpPanelDataCanvasClamp("bad",0,100),0);
	const selectionState={selected:new Map([["a",["paid"]],["b",["paid","review"]]])};assert.deepEqual(Array.from(runtime.dpPanelDataCanvasSelectionValues(selectionState)),["paid","review"]);
	const compactState={config:{estimated_item_size:52},before:{style:{}},after:{style:{}}};
	runtime.dpPanelDataSurfaceSpacers(compactState,valid);
	assert.equal(compactState.before.style.blockSize,"0px","small known collections do not render empty virtual gutters");
	assert.equal(compactState.after.style.blockSize,"0px","small known collections stay visually compact");
	const large=clone(valid);large.total=1000000000;large.records[0].position=50;large.records[1].position=51;
	runtime.dpPanelDataSurfaceSpacers(compactState,large);
	assert.equal(compactState.before.style.blockSize,"2600px","large collections retain a bounded leading virtual spacer");
	assert.equal(compactState.after.style.blockSize,"20000000px","large trailing virtual spacers remain hard capped");
	const canvasSpacers={before:{style:{}},after:{style:{}}};runtime.dpPanelDataSurfaceSpacers(canvasSpacers,advanced);assert.equal(canvasSpacers.before.style.blockSize,"0px");assert.equal(canvasSpacers.after.style.blockSize,"0px");

	let headers=runtime.dpPanelDataSurfaceHeaders();
	assert.equal(headers["X-Requested-With"],"DataphyrePanelDataSurface");
	assert.equal(Object.prototype.hasOwnProperty.call(headers,"X-CSRF-Token"),false);
	harness.setCsrf("csrf-value");headers=runtime.dpPanelDataSurfaceHeaders();assert.equal(headers["X-CSRF-Token"],"csrf-value");
	harness.setCsrf("bad\r\ntoken");headers=runtime.dpPanelDataSurfaceHeaders();assert.equal(Object.prototype.hasOwnProperty.call(headers,"X-CSRF-Token"),false);

	assert.equal(runtime.dpPanelDataSurfaceRuntime.count(),0,"no roots means no mounted surfaces");
	assert.equal(Object.keys(harness.documentListeners).length,0,"runtime retains no document-level listeners");
	assert.equal(typeof harness.windowListeners.pagehide,"function","pagehide cleanup is installed");
	runtime.dpPanelDataSurfaceRuntime.dispose();runtime.dpPanelDataSurfaceRuntime.dispose();
	assert.equal(runtime.dpPanelDataSurfaceRuntime.disposed,true,"runtime disposal is idempotent");
	console.log("panel DataSurface runtime adversarial audit passed");
}

main();
