#!/usr/bin/env node
'use strict';

const assert=require('assert/strict');
const fs=require('fs');
const os=require('os');
const path=require('path');
const {authenticateBrowserCases,baseCapabilities,installMainFrameNavigationPolicy,navigationAllowed,parseArgs,readJson,selectCases,targetUrl,validateManifest}=require('./panel_inclusive_quality_regression.js');

const profile={id:'en'};
const contract={id:'keyboard',domain:'input',execution:'browser',automation:'fully_automated',does_not_prove:[]};
const browserCase={type:'panel_browser_regression_manifest',version:1,name:'suite.p2.en.c8.keyboard',url:'/panel',interactions:[],meta:{quality_case_id:'suite.p2.en.c8.keyboard',quality_matrix_digest:'a'.repeat(64),quality_profile:profile,quality_contract:contract}};
const matrix={type:'panel_inclusive_quality_matrix',version:1,name:'suite',url:'/panel',digest:'a'.repeat(64),case_count:1,profiles:[profile],contracts:[contract],browser_manifests:[browserCase]};

assert.equal(validateManifest(matrix),matrix);
assert.equal(selectCases(matrix).length,1);
assert.equal(selectCases(matrix,['keyboard'],['en']).length,1);
assert.equal(selectCases(matrix,['touch'],[]).length,0);
assert.equal(targetUrl(browserCase,'https://example.test/base'),'https://example.test/panel');
assert.equal(targetUrl({...browserCase,url:'https://panel.example.test/app'},'https://panel.example.test'),'https://panel.example.test/app');
assert.throws(()=>targetUrl({...browserCase,url:'//evil.test'},'https://example.test'),/ambiguous|unsafe/);
assert.throws(()=>targetUrl({...browserCase,url:'https://evil.test/panel'},'https://example.test'),/allowlist/);
assert.equal(targetUrl({...browserCase,url:'https://panel.example.test/app'},'https://example.test',['https://panel.example.test']),'https://panel.example.test/app');
assert.throws(()=>validateManifest({...matrix,version:2}),/version/);
assert.throws(()=>validateManifest({...matrix,digest:'bad'}),/digest/);
assert.throws(()=>validateManifest({...matrix,browser_manifests:[]}),/no browser cases/);
assert.throws(()=>validateManifest({...matrix,browser_manifests:[{...browserCase,name:'other'}]}),/identity mismatch/);
assert.throws(()=>validateManifest({...matrix,browser_manifests:[{...browserCase,meta:{...browserCase.meta,quality_matrix_digest:'b'.repeat(64)}}]}),/digest mismatch/);
assert.throws(()=>validateManifest({...matrix,browser_manifests:[browserCase,{...browserCase}]}),/case count|unique/);
assert.throws(()=>validateManifest({...matrix,url:'javascript:alert(1)'}),/HTTP/);
assert.throws(()=>validateManifest({...matrix,profiles:[profile,{...profile}]}),/unique/);
const validation={type:'panel_inclusive_quality_validation',version:1,valid:true,name:'suite',digest:'a'.repeat(64),case_count:1,browser_case_count:1,browser_cases:[{case_id:browserCase.name,profile_id:'en',contract_id:'keyboard',url:'/panel'}]};
assert.equal(authenticateBrowserCases(matrix,validation),matrix);
assert.throws(()=>authenticateBrowserCases({...matrix,browser_manifests:[{...browserCase,url:'/forged'}]},validation),/mapping/);
assert.throws(()=>authenticateBrowserCases({...matrix,browser_manifests:[{...browserCase,meta:{...browserCase.meta,quality_profile:{id:'other'}}}]},validation),/mapping/);
assert.throws(()=>authenticateBrowserCases({...matrix,browser_manifests:[{...browserCase,meta:{...browserCase.meta,quality_profile:{...profile,direction:'rtl'}}}]},validation),/mapping/);
assert.throws(()=>authenticateBrowserCases({...matrix,browser_manifests:[{...browserCase,meta:{...browserCase.meta,quality_contract:{...contract,settings:{zoom:400}}}}]},validation),/mapping/);
assert.throws(()=>authenticateBrowserCases(matrix,{...validation,browser_cases:[]}),/canonical browser case set/);
assert.throws(()=>authenticateBrowserCases(matrix,{...validation,browser_cases:[...validation.browser_cases,{...validation.browser_cases[0],case_id:'suite.extra'}]}),/canonical browser case set/);
const maximumCases=Array.from({length:2048},(_,index)=>{const name='suite.case_'+String(index).padStart(4,'0');return{...browserCase,name,meta:{...browserCase.meta,quality_case_id:name}};});
const maximumValidation={...validation,case_count:2048,browser_case_count:2048,browser_cases:maximumCases.map(item=>({case_id:item.name,profile_id:'en',contract_id:'keyboard',url:'/panel'}))};
const maximumMatrix={...matrix,case_count:2048,browser_manifests:maximumCases};assert.equal(authenticateBrowserCases(maximumMatrix,maximumValidation),maximumMatrix);
const args=parseArgs(['--manifest=matrix.json','--contract','keyboard,touch','--contract=keyboard','--profile=en','--allow-origin=https://panel.example.test','--allow-no-sandbox','--list','--report-only']);
assert.deepEqual(args.contracts,['keyboard','touch']);
assert.deepEqual(args.profiles,['en']);
assert.equal(args.list,true);
assert.equal(args.reportOnly,true);
assert.equal(args.allowNoSandbox,true);
assert.deepEqual(args.allowOrigins,['https://panel.example.test']);
assert.throws(()=>parseArgs(['--unknown']),/Unknown argument/);
const capabilities=baseCapabilities('Chrome Test');
assert.equal(capabilities['browser.dom'].status,'available');
assert.equal(capabilities['browser.forced_colors'].status,'declared');
assert.equal(capabilities['browser.zoom_reflow'].source,'css-viewport-reflow-proxy');
assert.equal(navigationAllowed('https://example.test/panel','https://example.test'),true);
assert.equal(navigationAllowed('https://evil.test/panel','https://example.test'),false);
assert.equal(navigationAllowed('https://panel.example.test/app','https://example.test',['https://panel.example.test']),true);
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'dp-inclusive-'));const oversized=path.join(temp,'oversized.json');const handle=fs.openSync(oversized,'w');fs.ftruncateSync(handle,16*1024*1024+1);fs.closeSync(handle);assert.throws(()=>readJson(oversized),/byte budget/);fs.rmSync(temp,{recursive:true,force:true});

async function testNavigationPolicy(){
	const mainFrame={};let handler=null;const interception=[];
	const page={setRequestInterception:async value=>interception.push(value),on:(event,value)=>{assert.equal(event,'request');handler=value;},off:(event,value)=>{assert.equal(event,'request');assert.equal(value,handler);handler=null;},mainFrame:()=>mainFrame};
	const policy=await installMainFrameNavigationPolicy(page,'https://example.test');
	const request=(url,navigation,frame)=>{const state={aborted:0,continued:0};return{state,url:()=>url,isNavigationRequest:()=>navigation,frame:()=>frame,abort:async reason=>{assert.equal(reason,'blockedbyclient');state.aborted++;},continue:async()=>{state.continued++;}};};
	const blocked=request('https://evil.test/redirect',true,mainFrame);handler(blocked);assert.equal(blocked.state.aborted,1);assert.equal(blocked.state.continued,0);
	const allowed=request('https://example.test/next',true,mainFrame);handler(allowed);assert.equal(allowed.state.aborted,0);assert.equal(allowed.state.continued,1);
	const subresource=request('https://evil.test/image.png',false,{});handler(subresource);assert.equal(subresource.state.aborted,0);assert.equal(subresource.state.continued,1);
	assert.deepEqual(policy.blocked,['https://evil.test/redirect']);await policy.dispose();assert.equal(handler,null);assert.deepEqual(interception,[true,false]);
}

testNavigationPolicy().then(()=>console.log(JSON.stringify({ok:true,assertions:52}))).catch(error=>{console.error(error?.stack||String(error));process.exitCode=1;});
