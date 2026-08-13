#!/usr/bin/env node
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
"use strict";

const fs=require("node:fs");
const path=require("node:path");
const cwd=process.cwd();
const artifactRoot=path.resolve(cwd,process.argv[2]||"cache/unit-tests/panel-realtime-client-evidence");
function assert(value,message){if(!value){throw new Error(message);}}
function findPuppeteer(){for(const candidate of [path.join(cwd,".tmp","puppeteer-check","node_modules","puppeteer-core"),path.join(cwd,".tmp","node_modules","puppeteer-core"),path.join(__dirname,"node_modules","puppeteer-core")]){if(fs.existsSync(candidate)){return require(candidate);}}throw new Error("puppeteer-core is unavailable");}
function findBrowser(){for(const candidate of [process.env.CHROME_PATH,process.env.EDGE_PATH,"C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe","C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe","C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe","C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe"].filter(Boolean)){if(fs.existsSync(candidate)){return candidate;}}throw new Error("Chrome or Edge is unavailable");}

async function main(){
	fs.mkdirSync(artifactRoot,{recursive:true});
	const runtimePath=path.join(__dirname,"..","Framework","Realtime","PanelRealtimeClient.js");
	const fixturePath=path.join(__dirname,"fixtures","panel_realtime_client_browser.html");
	const runtime=fs.readFileSync(runtimePath,"utf8").replace(/<\/script/gi,"<\\/script");
	const html=fs.readFileSync(fixturePath,"utf8").replace("/*DP_RUNTIME*/",runtime);
	const puppeteer=findPuppeteer();const browser=await puppeteer.launch({executablePath:findBrowser(),headless:true,args:["--disable-dev-shm-usage","--no-first-run","--no-default-browser-check"]});
	const page=await browser.newPage();const faults=[];page.on("pageerror",error=>faults.push(String(error&&error.stack||error)));page.on("console",message=>{if(message.type()==="error"){faults.push(message.text());}});
	let proof;
	try{
		await page.setViewport({width:1024,height:768,deviceScaleFactor:1});await page.setContent(html,{waitUntil:"domcontentloaded",timeout:30000});await page.waitForFunction(()=>window.__done===true,{timeout:15000});
		proof=await page.evaluate(()=>({proof:window.__proof||null,failure:window.__failure||null,status:document.getElementById("status").textContent}));
		assert(!proof.failure,"Browser fixture failed: "+proof.failure);assert(proof.status==="Passed"&&proof.proof,"Browser fixture did not complete");
		const facts=proof.proof;assert(facts.runtime.transport==="fetch_streamed_sse"&&!facts.runtime.native_event_source&&facts.runtime.public_credentials_exposed===false,"Browser runtime transport manifest is false");assert(facts.state.state==="terminal"&&facts.state.has_resume_cursor,"Browser stream did not retain its signed resume id");
		assert(facts.requests.length===1&&facts.requests[0].method==="POST"&&facts.requests[0].signal,"Browser request did not use fetch POST plus AbortController");assert(!facts.requests[0].url.includes("browser-intent"),"Browser put a subscription intent in its URL");assert(facts.requests[0].headers["X-Dataphyre-Realtime-Intent"]==="browser-intent","Browser omitted the intent header");
		assert(facts.events.some(event=>event.name==="event"&&event.custom&&event.detail.envelope.payload.id===7),"Typed CustomEvent envelope was not delivered");assert(facts.events.some(event=>event.name==="saved"&&event.detail==="resume-browser-1"),"Browser did not persist Last-Event-ID");assert(facts.events.some(event=>event.name==="heartbeat"&&event.custom),"Browser did not parse heartbeat comments");
		assert(!facts.privacy.hasOptions&&!facts.privacy.hasLastEventId&&!facts.privacy.hasTarget&&facts.privacy.credentialHelpers.length===0&&facts.privacy.deepFrozen,"Browser client credential state is not private and deeply frozen");assert(!facts.privacy.publicSerialized.includes("browser-intent")&&!facts.privacy.publicSerialized.includes("resume-browser-1")&&!facts.privacy.publicSerialized.includes("browser-secret"),"Browser public state or events exposed a transport credential");
		assert(facts.provider.requests.join(",")==="provider-one,provider-two"&&facts.provider.calls.length===1&&facts.provider.calls[0].reason==="intent_expired","Browser subscription intent provider did not refresh an expired connection");
		assert(facts.paused.fetchesWhilePaused===0&&facts.paused.totalFetches===1,"Manual pause did not suppress fetch until resume");assert(facts.stopped.aborted&&facts.stopped.state.state==="stopped","Host stop did not abort the browser fetch");assert(Object.values(facts.primitives).every(value=>value==="function"),"Required real-browser primitives are unavailable");assert(faults.length===0,"Browser faults: "+faults.join(" | "));
		await page.screenshot({path:path.join(artifactRoot,"panel-realtime-client.png"),fullPage:true});
	}finally{await page.close();await browser.close();}
	const report={type:"panel_realtime_client_browser_evidence",version:1,generated_at:new Date().toISOString(),browser_engine:"chromium",fixture:path.relative(cwd,fixturePath).replace(/\\/g,"/"),runtime:path.relative(cwd,runtimePath).replace(/\\/g,"/"),faults,proof:proof.proof,summary:{passed:true,fetch_streamed:true,native_event_source:false,signed_resume:true,typed_custom_events:true,private_credentials:true,intent_refresh_provider:true,pause_resume:true,abort_teardown:true}};
	fs.writeFileSync(path.join(artifactRoot,"report.json"),JSON.stringify(report,null,2)+"\n");process.stdout.write(JSON.stringify({type:report.type,passed:true,artifact:path.relative(cwd,path.join(artifactRoot,"report.json")).replace(/\\/g,"/"),summary:report.summary},null,2)+"\n");
}
main().catch(error=>{process.stderr.write(String(error&&error.stack||error)+"\n");process.exit(1);});
