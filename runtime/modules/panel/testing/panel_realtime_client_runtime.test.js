/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
"use strict";

const assert=require("node:assert/strict");
const path=require("node:path");
const runtime=require(path.join(__dirname,"..","Framework","Realtime","PanelRealtimeClient.js"));
const encoder=new TextEncoder();

let cases=0;
let assertions=0;
const failures=[];
const tests=[];
function check(value,message){assert.ok(value,message);assertions++;}
function same(actual,expected,message){assert.deepEqual(actual,expected,message);assertions++;}
function test(name,run){cases++;tests.push({name,run});}

function sseFrame(event,data,id,extra){
	let output=extra||"";
	if(id){output+="id: "+id+"\n";}
	output+="event: "+event+"\n";
	output+="data: "+JSON.stringify(data)+"\n\n";
	return output;
}
function responseFrom(chunks,options){
	options=options||{};
	const body=new ReadableStream({start(controller){for(const chunk of chunks){controller.enqueue(typeof chunk==="string"?encoder.encode(chunk):chunk);}controller.close();}});
	const status=options.status||200;
	return {ok:status>=200&&status<300,status,redirected:options.redirected===true,headers:new Headers({"content-type":options.contentType||"text/event-stream; charset=utf-8"}),body};
}
function hangingResponse(){return {ok:true,status:200,redirected:false,headers:new Headers({"content-type":"text/event-stream"}),body:new ReadableStream({start(){}})};}
function capture(client,names){
	const events=[];
	for(const name of names){client.addEventListener(runtime.events[name],event=>events.push({name,detail:event.detail}));}
	return events;
}
function terminal(code){return sseFrame("panel.error",{schema_version:1,type:"panel.error",code:code||"done",message:"Terminal.",retryable:false},null);}

test("client validates explicit host transport configuration and keeps credentials out of URLs",async()=>{
	const base={url:"/realtime",subscriptionIntent:"signed-intent",fetch:async()=>responseFrom([terminal()]),baseUrl:"https://panel.example/app"};
	const client=runtime.create(base);
	same(client.state().method,"POST");
	check(!Object.prototype.hasOwnProperty.call(client,"options")&&!Object.prototype.hasOwnProperty.call(client,"lastEventId")&&!Object.prototype.hasOwnProperty.call(client,"target"),"client credentials and event target must remain in closure-private state");same(client.options,undefined);same(client.lastEventId,undefined);same(client.target,undefined);
	for(const helper of ["requestBody","requestHeaders","refreshSubscriptionIntent","ensureSubscriptionIntent"]){same(typeof client[helper],"undefined");}
	same(runtime.manifest().native_event_source,false);
	same(runtime.manifest().host_owned_csrf,true);
	same(runtime.manifest().query_requires_opt_in,true);same(runtime.manifest().credential_named_query_keys_rejected,true);
	assert.throws(()=>runtime.create({...base,url:"https://evil.example/realtime"}),/cross-origin/);assertions++;
	assert.throws(()=>runtime.create({...base,url:"https://evil.example/realtime",allowCrossOrigin:true}),/allowCrossOrigin/);assertions++;
	assert.throws(()=>runtime.create({...base,url:"http://stream.example/realtime",allowedOrigins:["http://stream.example"],mode:"cors"}),/downgrade/);assertions++;
	assert.throws(()=>runtime.create({...base,url:"https://stream.example/realtime",allowedOrigins:["https://stream.example/path"],mode:"cors"}),/allowed origin/);assertions++;
	const cross=runtime.create({...base,url:"https://stream.example/realtime",allowedOrigins:["https://stream.example"],mode:"cors"});same(cross.state().url_origin,"https://stream.example");
	assert.throws(()=>runtime.create({...base,url:"/realtime?intent=secret",allowQuery:true}),/credential/);assertions++;
	for(const key of ["api_key","accessKey","password","session-id","csrfToken","JWT","request.signature","cookie","auth","key","sig","ticketId"]){assert.throws(()=>runtime.create({...base,url:"/realtime?"+encodeURIComponent(key)+"=value",allowQuery:true}),/credential-named/);assertions++;}
	check(runtime.create({...base,url:"/realtime?view=one",allowQuery:true}) instanceof runtime.Client,"explicit non-credential query key should remain available");
	assert.throws(()=>runtime.create({...base,url:"/realtime?view=one"}),/allowQuery/);assertions++;
	assert.throws(()=>runtime.create({...base,method:"GET",body:{}}),/cannot configure/);assertions++;
	assert.throws(()=>runtime.create({...base,method:"PUT"}),/GET or POST/);assertions++;
	assert.throws(()=>runtime.create({...base,headers:{Origin:"https://panel.example"}}),/controlled/);assertions++;
	assert.throws(()=>runtime.create({...base,headers:[["X-Trace","one"]]}),/object or Headers/);assertions++;
	assert.throws(()=>runtime.create({...base,headers:new Map([["X-Trace","one"]])}),/object or Headers/);assertions++;
	assert.throws(()=>runtime.create({...base,subscriptionIntent:"bad\nintent"}),/invalid/);assertions++;
	assert.throws(()=>runtime.create({...base,mode:"no-cors"}),/same-origin or cors/);assertions++;
});

test("fallback event targets preserve typed dispatch without browser event constructors",async()=>{
	const response=responseFrom([terminal("fallback_event")]);
	const saved={EventTarget:globalThis.EventTarget,CustomEvent:globalThis.CustomEvent,Event:globalThis.Event};
	try{
		globalThis.EventTarget=undefined;globalThis.CustomEvent=undefined;
		const client=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent",window:null,document:null,navigator:null,maxAttempts:1,fetch:async()=>response});
		const details=[];const listener=event=>details.push(event.detail.code);const ignored="not-a-listener";
		client.addEventListener(runtime.events.error,ignored);client.addEventListener(runtime.events.error,listener);client.addEventListener(runtime.events.error,listener);
		client.addEventListener(runtime.events.error,()=>{throw new Error("host listener failure");});
		client.start();await client.settled();same(details,["fallback_event"]);
		client.removeEventListener(runtime.events.error,listener);client.removeEventListener(runtime.events.error,listener);
		globalThis.Event=undefined;let closeDetail=null;client.addEventListener(runtime.events.close,event=>{closeDetail=event.detail;});client.stop("fallback");same(closeDetail.reason,"fallback");
	}
	finally{globalThis.EventTarget=saved.EventTarget;globalThis.CustomEvent=saved.CustomEvent;globalThis.Event=saved.Event;}
});

test("fetch-streamed SSE dispatches typed events and persists only verified Last-Event-ID values",async()=>{
	const saved=[];const requests=[];
	const stream=sseFrame("orders.updated",{schema_version:1,sequence:1,channel:"orders",topic:"orders.updated",type:"orders.updated",occurred_at:"2026-07-14T12:00:00Z",payload:{id:1},metadata:{}},"resume-1")+
		": heartbeat 1000\n\n"+sseFrame("panel.cursor",{schema_version:1,type:"panel.cursor",cursor_advanced:true},"resume-2")+terminal("complete");
	const chunks=[stream.slice(0,31),stream.slice(31,97),stream.slice(97)];
	const client=runtime.create({url:"/realtime",baseUrl:"https://panel.example/app",subscriptionIntent:"signed-intent",body:{channel:"orders"},headers:{Authorization:"Bearer host-token",Accept:"bad","Last-Event-ID":"forged"},saveCursor:value=>saved.push(value),fetch:async(url,init)=>{requests.push({url,init});return responseFrom(chunks);}});
	const events=capture(client,["open","event","cursor","heartbeat","error","close"]);
	client.start();await client.settled();
	same(client.state().state,"terminal");same(client.state().has_resume_cursor,true);
	same(saved,["resume-1","resume-2"]);
	same(events.filter(event=>event.name==="event").length,1);
	same(events.find(event=>event.name==="event").detail.envelope.payload.id,1);
	check(Object.isFrozen(events.find(event=>event.name==="event").detail)&&Object.isFrozen(events.find(event=>event.name==="event").detail.envelope)&&Object.isFrozen(events.find(event=>event.name==="event").detail.envelope.payload),"public event details must be deeply frozen");
	same(events.filter(event=>event.name==="heartbeat").length,1);
	same(events.find(event=>event.name==="error").detail.code,"complete");
	same(requests.length,1);same(requests[0].init.method,"POST");
	same(requests[0].init.headers["X-Dataphyre-Realtime-Intent"],"signed-intent");
	same(requests[0].init.headers.Accept,"text/event-stream");
	check(!Object.keys(requests[0].init.headers).some(name=>name.toLowerCase()==="last-event-id"),"forged host cursor must be removed");
	same(JSON.parse(requests[0].init.body),{channel:"orders"});
	check(!requests[0].url.includes("signed-intent"),"intent must not enter URL");
	const publicProof=JSON.stringify({client,events,state:client.state()});check(!publicProof.includes("signed-intent")&&!publicProof.includes("resume-1")&&!publicProof.includes("resume-2")&&!publicProof.includes("host-token"),"public client state and events must not expose transport credentials");
});

test("host intent provider refreshes expired and reconnecting credentials through bounded private inputs",async()=>{
	const requests=[],providerInputs=[],delays=[];let call=0;
	const timer=(fn,ms)=>{if(ms<1000){delays.push(ms);queueMicrotask(fn);return {fast:true};}return setTimeout(fn,ms);};const clear=id=>{if(id&&id.fast){return;}clearTimeout(id);};
	const expired=sseFrame("panel.error",{schema_version:1,type:"panel.error",code:"intent_expired",message:"Expired.",retryable:false},null);
	const client=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent-one",subscriptionIntentProvider:async input=>{providerInputs.push(input);return "intent-two";},baseRetryMs:100,maxRetryMs:250,jitter:0,setTimeout:timer,clearTimeout:clear,fetch:async(url,init)=>{requests.push(init);return responseFrom([call++===0?expired:terminal("refreshed")],{status:call===1?401:200});}});
	const events=capture(client,["error"]);client.start();await client.settled();same(requests.length,2);same(requests[0].headers["X-Dataphyre-Realtime-Intent"],"intent-one");same(requests[1].headers["X-Dataphyre-Realtime-Intent"],"intent-two");same(providerInputs.map(input=>input.reason),["intent_expired"]);same(Object.keys(providerInputs[0]).sort(),["attempt","hasResumeCursor","reason"]);check(Object.isFrozen(providerInputs[0]),"provider input must be frozen and credential-free");check(events.some(event=>event.detail.code==="intent_expired"&&event.detail.retryable===true),"provider-backed expiry should enter bounded recovery");
	const reconnectInputs=[];let reconnectCalls=0;const reconnect=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"initial",subscriptionIntentProvider:input=>{reconnectInputs.push(input);return "renewed";},baseRetryMs:100,maxRetryMs:250,jitter:0,setTimeout:timer,clearTimeout:clear,fetch:async(url,init)=>{reconnectCalls++;return responseFrom([reconnectCalls===1?": heartbeat\n\n":terminal("renewed_done")]);}});reconnect.start();await reconnect.settled();same(reconnectCalls,2);same(reconnectInputs.map(input=>input.reason),["reconnect"]);
	const timeoutClient=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntentProvider:()=>new Promise(()=>{}),intentRefreshTimeoutMs:1000,maxAttempts:1,setTimeout:(fn,ms)=>{queueMicrotask(fn);return 1;},clearTimeout:()=>{},fetch:async()=>responseFrom([terminal()])});const timeoutEvents=capture(timeoutClient,["error"]);timeoutClient.start();await timeoutClient.settled();check(timeoutEvents.some(event=>event.detail.code==="intent_refresh_timeout"),"intent provider timeout must be bounded");
});

test("resume loading and bounded injected jitter drive retry without native EventSource",async()=>{
	const requests=[];const delays=[];let call=0;
	const timer=(fn,ms)=>{if(ms<5000){delays.push(ms);queueMicrotask(fn);return {fast:true};}return setTimeout(fn,ms);};
	const clear=id=>{if(id&&id.fast){return;}clearTimeout(id);};
	const retryable="retry: 1200\n\n"+sseFrame("panel.error",{schema_version:1,type:"panel.error",code:"upstream_busy",message:"Busy.",retryable:true},null);
	const client=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent",loadCursor:()=>"resume-old",baseRetryMs:200,maxRetryMs:1500,jitter:0.5,random:()=>1,setTimeout:timer,clearTimeout:clear,fetch:async(url,init)=>{requests.push(init);call++;return responseFrom([call===1?retryable:terminal("denied")]);}});
	const events=capture(client,["retry","error"]);client.start();await client.settled();
	same(requests.length,2);same(requests[0].headers["Last-Event-ID"],"resume-old");same(requests[1].headers["Last-Event-ID"],"resume-old");
	same(delays,[1500]);same(events.find(event=>event.name==="retry").detail.delay_ms,1500);
	same(events.filter(event=>event.name==="error").map(event=>event.detail.code),["upstream_busy","denied"]);
});

test("offline and hidden lifecycle reasons pause independently and teardown listeners",async()=>{
	const windowTarget=new EventTarget();const documentTarget=new EventTarget();documentTarget.hidden=true;const navigatorValue={onLine:false};let fetches=0;
	const client=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent",window:windowTarget,document:documentTarget,navigator:navigatorValue,fetch:async()=>{fetches++;return responseFrom([terminal("done")]);}});
	const events=capture(client,["pause","resume","close"]);client.start();await Promise.resolve();await Promise.resolve();same(fetches,0);
	navigatorValue.onLine=true;windowTarget.dispatchEvent(new Event("online"));await Promise.resolve();same(fetches,0);
	documentTarget.hidden=false;documentTarget.dispatchEvent(new Event("visibilitychange"));await client.settled();same(fetches,1);
	check(events.filter(event=>event.name==="pause").length>=2,"offline and hidden pauses expected");
	same(events.filter(event=>event.name==="resume").length,1);
	client.stop("done");
});

test("heartbeat timeout aborts a stalled reader and reconnects through bounded backoff",async()=>{
	let call=0;const delays=[];
	const timer=(fn,ms)=>{delays.push(ms);if(ms<5000){queueMicrotask(fn);return {fast:true};}if(ms===5000){return setTimeout(fn,0);}return setTimeout(fn,ms);};const clear=id=>{if(id&&id.fast){return;}clearTimeout(id);};
	const client=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent",heartbeatTimeoutMs:5000,baseRetryMs:100,maxRetryMs:250,jitter:0,maxAttempts:3,setTimeout:timer,clearTimeout:clear,fetch:async()=>{call++;return call===1?hangingResponse():responseFrom([terminal("after_timeout")]);}});
	const events=capture(client,["error","retry"]);client.start();await client.settled();
	same(call,2);check(events.some(event=>event.name==="error"&&event.detail.code==="heartbeat_timeout"),"heartbeat timeout error expected");
	check(events.some(event=>event.name==="retry"&&event.detail.delay_ms===100),"bounded retry expected");
	check(delays.includes(5000),"heartbeat deadline timer expected");
});

test("connect timeout aborts a fetch that never produces response headers",async()=>{
	let aborted=false;const client=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent",connectTimeoutMs:1000,maxAttempts:1,setTimeout:(fn,ms)=>setTimeout(fn,ms===1000?0:ms),clearTimeout:clearTimeout,fetch:(url,init)=>new Promise(()=>{init.signal.addEventListener("abort",()=>{aborted=true;},{once:true});})});
	const events=capture(client,["error"]);client.start();await client.settled();same(aborted,true);check(events.some(event=>event.detail.code==="connect_timeout"),"response-header wait must be time bounded");
});

test("heartbeat deadline ignores byte drips and short heartbeat EOF loops exhaust retries",async()=>{
	let now=0,reads=0,cancelled=false;
	const dripReader={read(){reads++;now+=1000;return Promise.resolve({done:false,value:encoder.encode("x")});},cancel(){cancelled=true;return Promise.resolve();},releaseLock(){}};
	const dripResponse={ok:true,status:200,redirected:false,headers:new Headers({"content-type":"text/event-stream"}),body:{getReader(){return dripReader;}}};
	const dripClient=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent",heartbeatTimeoutMs:5000,maxAttempts:1,now:()=>now,fetch:async()=>dripResponse});
	const dripEvents=capture(dripClient,["error"]);dripClient.start();await dripClient.settled();same(reads,5);same(cancelled,true);check(dripEvents.some(event=>event.detail.code==="heartbeat_timeout"),"incomplete byte drips must hit the frame deadline");
	let calls=0;const delays=[];const timer=(fn,ms)=>{if(ms<5000){delays.push(ms);queueMicrotask(fn);return {fast:true};}return setTimeout(fn,ms);};const clear=id=>{if(id&&id.fast){return;}clearTimeout(id);};
	const eofClient=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent",maxAttempts:3,baseRetryMs:100,maxRetryMs:250,jitter:0,setTimeout:timer,clearTimeout:clear,fetch:async()=>{calls++;return responseFrom([": heartbeat\n\n"]);}});
	const eofEvents=capture(eofClient,["error"]);eofClient.start();await eofClient.settled();same(calls,3);same(delays,[100,200]);check(eofEvents.some(event=>event.detail.code==="retry_exhausted"),"short heartbeat EOF loops must not reset retry attempts");
});

test("parser limits reject invalid JSON oversized events unsupported schemas and incomplete frames",async()=>{
	async function terminalCode(chunks,overrides){
		let calls=0;const client=runtime.create({...{url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent",fetch:async()=>responseFrom(calls++===0?chunks:[terminal("after_parser_retry")])},...(overrides||{})});const events=capture(client,["error"]);client.start();await client.settled();return events.map(event=>event.detail.code);
	}
	let codes=await terminalCode(["event: orders.updated\ndata: {bad}\n\n"]);check(codes.includes("event_json_invalid"),"invalid JSON must be terminal");
	codes=await terminalCode([sseFrame("orders.updated",{schema_version:2,payload:{}},null)]);check(codes.includes("schema_version_unsupported"),"schema mismatch must be terminal");
	codes=await terminalCode([sseFrame("orders.updated",{schema_version:1,payload:{}},null)]);check(codes.includes("event_id_required"),"data events must carry signed ids");
	codes=await terminalCode([sseFrame("orders.updated",{schema_version:1,payload:"x".repeat(1500)},null)],{maxEventBytes:1024,maxFrameBytes:4096});check(codes.includes("event_too_large"),"event byte limit expected");
	codes=await terminalCode(["event: orders.updated\ndata: {\"schema_version\":1}"]);check(codes.includes("incomplete_frame"),"incomplete frame must retry");
	const mimeClient=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent",maxAttempts:1,fetch:async()=>responseFrom([terminal()],{contentType:"text/event-streaming"})});const mimeEvents=capture(mimeClient,["error"]);mimeClient.start();await mimeClient.settled();check(mimeEvents.some(event=>event.detail.code==="content_type_invalid"),"SSE media type must match exactly");
});

test("request bounds, cursor callback failures, reset terminality and reconfiguration remain explicit",async()=>{
	let clearCalls=0;let call=0;
	const reset=sseFrame("panel.reset",{schema_version:1,type:"panel.reset",reason:"retention_gap",action:"rehydrate"},"resume-head");
	const client=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent-one",saveCursor:()=>{throw new Error("store down");},clearCursor:()=>{clearCalls++;},fetch:async()=>{call++;return responseFrom([call===1?reset:terminal("reconfigured")]);}});
	const events=capture(client,["reset","error"]);client.start();await client.settled();same(client.state().state,"terminal");
	check(events.some(event=>event.name==="reset"&&event.detail.reason==="retention_gap"),"reset event expected");
	check(events.some(event=>event.name==="error"&&event.detail.code==="cursor_persistence_failed"),"cursor persistence failure expected");
	await client.reconfigure("intent-two",true);await client.settled();same(clearCalls,1);same(call,2);
	async function requestFailure(overrides){const bounded=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent",maxAttempts:1,fetch:async()=>responseFrom([terminal()]),...overrides});const failures=capture(bounded,["error"]);bounded.start();await bounded.settled();return failures.map(entry=>entry.detail.code);}
	check((await requestFailure({maxRequestBytes:4,body:{large:true}})).includes("request_body_too_large"),"request body byte bound expected");
	check((await requestFailure({bodyFactory:()=>Promise.resolve({})})).includes("request_body_invalid"),"async request body factory must be rejected");
});

test("host stop aborts an in-flight fetch and closes without retry",async()=>{
	let signal=null;let aborted=false;
	const fetchFn=(url,init)=>{signal=init.signal;return new Promise((resolve,reject)=>{init.signal.addEventListener("abort",()=>{aborted=true;reject(new DOMException("Aborted","AbortError"));},{once:true});});};
	const client=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent",fetch:fetchFn});client.start();for(let spin=0;spin<10&&signal===null;spin++){await Promise.resolve();}check(signal!==null,"fetch signal expected");client.stop("host");await client.settled();
	same(aborted,true);same(client.state().state,"stopped");same(client.state().running,false);
});

test("consecutive connection failures stop at the configured retry ceiling",async()=>{
	let calls=0;const delays=[];const timer=(fn,ms)=>{if(ms<5000){delays.push(ms);queueMicrotask(fn);return {fast:true};}return setTimeout(fn,ms);};const clear=id=>{if(id&&id.fast){return;}clearTimeout(id);};const client=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent",maxAttempts:2,baseRetryMs:100,maxRetryMs:250,jitter:0,setTimeout:timer,clearTimeout:clear,fetch:async()=>{calls++;throw new TypeError("network internals");}});
	const events=capture(client,["error"]);client.start();await client.settled();same(calls,2);same(delays,[100]);same(client.state().state,"terminal");check(events.some(event=>event.detail.code==="retry_exhausted"),"retry exhaustion expected");
});

test("clean EOF and non-OK streamed responses retain explicit retry semantics",async()=>{
	const retryTimer=(delays)=>(fn,ms)=>{if(ms<5000){delays.push(ms);queueMicrotask(fn);return {fast:true};}return setTimeout(fn,ms);};
	const retryClear=id=>{if(id&&id.fast){return;}clearTimeout(id);};
	const cleanDelays=[];let cleanCalls=0;
	const cleanClient=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent",baseRetryMs:100,maxRetryMs:250,jitter:0,setTimeout:retryTimer(cleanDelays),clearTimeout:retryClear,fetch:async()=>responseFrom([cleanCalls++===0?": heartbeat\n\n":terminal("after_eof")])});
	const cleanEvents=capture(cleanClient,["error","retry"]);cleanClient.start();await cleanClient.settled();same(cleanCalls,2);same(cleanDelays,[100]);check(cleanEvents.some(event=>event.detail.code==="stream_ended"),"clean EOF must be retryable");
	const statusDelays=[];let statusCalls=0;
	const statusClient=runtime.create({url:"/realtime",baseUrl:"https://panel.example",subscriptionIntent:"intent",baseRetryMs:100,maxRetryMs:250,jitter:0,setTimeout:retryTimer(statusDelays),clearTimeout:retryClear,fetch:async()=>statusCalls++===0?responseFrom([": heartbeat\n\n"],{status:503}):responseFrom([terminal("after_503")])});
	const statusEvents=capture(statusClient,["open","error","retry"]);statusClient.start();await statusClient.settled();same(statusCalls,2);same(statusDelays,[100]);check(statusEvents.some(event=>event.detail.code==="http_503"),"non-OK stream status must be surfaced");same(statusEvents.filter(event=>event.name==="open").length,1);
});

(async()=>{
	for(const entry of tests){try{await entry.run();}catch(error){failures.push({name:entry.name,message:String(error&&error.stack||error)});}}
	const report={type:"panel_realtime_client_node_proof",node_version:process.version,cases,assertions,failures};
	process.stdout.write(JSON.stringify(report,null,2)+"\n");
	if(failures.length){process.exitCode=1;}
})();
