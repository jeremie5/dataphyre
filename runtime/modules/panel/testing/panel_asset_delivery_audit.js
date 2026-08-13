#!/usr/bin/env node
'use strict';

const crypto=require('crypto');
const fs=require('fs');
const path=require('path');
const vm=require('vm');
const zlib=require('zlib');
const {performance}=require('perf_hooks');

const cwd=process.cwd();
const budgets=require('./panel_asset_delivery_budgets.json');
const options=parseArguments(process.argv.slice(2));

function parseArguments(argv){
	const parsed={
		baseUrl:process.env.DP_PANEL_BASE_URL||'',
		manifestUrl:'',
		profiles:[],
		report:'',
		reportOnly:false,
		browser:false,
		browserPath:process.env.CHROME_PATH||'',
		allowNoBrowser:false,
		selfTest:false,
	};
	for(let index=0;index<argv.length;index++){
		const argument=argv[index];
		const value=()=>{
			const separator=argument.indexOf('=');
			if(separator!==-1){return argument.slice(separator+1);}
			index++;
			return argv[index]||'';
		};
		if(argument==='--help'||argument==='-h'){parsed.help=true;}
		else if(argument==='--self-test'){parsed.selfTest=true;}
		else if(argument==='--report-only'){parsed.reportOnly=true;}
		else if(argument==='--browser'){parsed.browser=true;}
		else if(argument==='--allow-no-browser'){parsed.allowNoBrowser=true;}
		else if(argument==='--base-url'||argument.startsWith('--base-url=')){parsed.baseUrl=value();}
		else if(argument==='--manifest-url'||argument.startsWith('--manifest-url=')){parsed.manifestUrl=value();}
		else if(argument==='--profile'||argument.startsWith('--profile=')){parsed.profiles.push(value());}
		else if(argument==='--report'||argument.startsWith('--report=')){parsed.report=value();}
		else if(argument==='--browser-path'||argument.startsWith('--browser-path=')){parsed.browserPath=value();}
		else {throw new Error('Unknown argument: '+argument+'. Run with --help for usage.');}
	}
	return parsed;
}

function printHelp(){
	console.log([
		'Dataphyre Panel physical asset delivery audit',
		'',
		'Usage:',
		'  node panel_asset_delivery_audit.js --manifest-url http://127.0.0.1:8098/panel/assets/panel-assets.json --profile full --browser',
		'  node panel_asset_delivery_audit.js --base-url http://127.0.0.1:8098/panel --profile shell --profile table',
		'',
		'Options:',
		'  --manifest-url <url>  Exact Panel panel-assets.json route.',
		'  --base-url <url>      Query-style Panel entrypoint; dp_panel_asset is appended.',
		'  --profile <name>      Budget profile to audit; repeatable, defaults to every profile.',
		'  --browser             Execute full-profile scripts in Chromium and enforce bootstrap budgets.',
		'  --browser-path <path> Chromium/Chrome executable override.',
		'  --allow-no-browser    Permit --browser to report unavailable Chromium without failing.',
		'  --report <path>       Write the complete JSON evidence report.',
		'  --report-only         Report failures without returning a non-zero exit.',
		'  --self-test           Exercise manifest, compression, dependency, and budget checks offline.',
	].join('\n'));
}

function invariant(condition,message){
	if(!condition){throw new Error(message);}
}

function object(value){
	return value!==null&&typeof value==='object'&&!Array.isArray(value);
}

function sha256(content){
	return crypto.createHash('sha256').update(content).digest('hex');
}

function sri(content){
	return 'sha384-'+crypto.createHash('sha384').update(content).digest('base64');
}

function median(values){
	const sorted=[...values].sort((left,right)=>left-right);
	return sorted[Math.floor(sorted.length/2)]||0;
}

function compileMilliseconds(source,name){
	const samples=[];
	for(let index=0;index<5;index++){
		const start=performance.now();
		new vm.Script(source,{filename:name,displayErrors:true});
		samples.push(performance.now()-start);
	}
	return median(samples);
}

function manifestEndpoint(profile){
	const configured=options.manifestUrl||options.baseUrl;
	invariant(configured,'Provide --manifest-url or --base-url.');
	const endpoint=new URL(configured);
	if(!options.manifestUrl){endpoint.searchParams.set('dp_panel_asset','panel-assets.json');}
	const capability=budgets.profiles[profile].capabilities;
	if(capability){endpoint.searchParams.set('dp_panel_caps',capability);}
	else {endpoint.searchParams.delete('dp_panel_caps');}
	return endpoint;
}

async function fetchBounded(url,accept,maxBytes=4_000_000){
	const response=await fetch(url,{headers:{accept},redirect:'error',signal:AbortSignal.timeout(30_000)});
	const buffer=Buffer.from(await response.arrayBuffer());
	invariant(buffer.length<=maxBytes,'Response exceeded byte boundary: '+url+'.');
	return {response,buffer};
}

async function mapLimit(values,limit,callback){
	const results=new Array(values.length);
	let cursor=0;
	const workers=Array.from({length:Math.min(limit,values.length)},async()=>{
		while(cursor<values.length){
			const index=cursor++;
			results[index]=await callback(values[index],index);
		}
	});
	await Promise.all(workers);
	return results;
}

function firstPartyDescriptors(manifest){
	const collections=[
		...(Array.isArray(manifest.styles)?manifest.styles:[]),
		...(Array.isArray(manifest.scripts)?manifest.scripts:[]),
	];
	return collections.filter(asset=>object(asset)&&asset.external!==true);
}

function validateManifest(manifest,profile){
	invariant(object(manifest),'Asset manifest must be an object for '+profile+'.');
	invariant(manifest.schema_version===1,'Unsupported asset manifest schema for '+profile+'.');
	invariant(manifest.mode==='physical','Asset manifest is not in physical mode for '+profile+'.');
	invariant(manifest.delivery?.strategy==='physical'&&manifest.delivery?.physical===true,'Physical delivery contract is absent for '+profile+'.');
	invariant(manifest.delivery?.runtime_namespace==='window.DataphyrePanel.runtimeChunks','Runtime namespace contract drifted for '+profile+'.');
	invariant(Array.isArray(manifest.styles)&&Array.isArray(manifest.scripts),'Asset manifest collections are malformed for '+profile+'.');
	const descriptors=firstPartyDescriptors(manifest);
	const names=descriptors.map(asset=>String(asset.name||''));
	invariant(names.every(name=>name&&name.length<=128),'Asset manifest contains an unsafe name for '+profile+'.');
	invariant(new Set(names).size===names.length,'Asset manifest contains duplicate first-party names for '+profile+'.');
	return descriptors;
}

async function inspectAsset(descriptor,manifestUrl){
	invariant(typeof descriptor.url==='string'&&descriptor.url,'First-party asset URL is missing for '+descriptor.name+'.');
	const url=new URL(descriptor.url,manifestUrl);
	invariant(url.origin===manifestUrl.origin,'First-party asset crossed origin: '+descriptor.name+'.');
	const expectedType=descriptor.type==='style'?'text/css':'application/javascript';
	const {response,buffer}=await fetchBounded(url,expectedType);
	const source=buffer.toString('utf8');
	const contentType=(response.headers.get('content-type')||'').toLowerCase();
	const cacheControl=(response.headers.get('cache-control')||'').toLowerCase();
	invariant(response.status===200,descriptor.name+' returned HTTP '+response.status+'.');
	invariant(contentType.startsWith(expectedType),descriptor.name+' returned '+contentType+'.');
	invariant(cacheControl.includes('public')&&cacheControl.includes('immutable'),descriptor.name+' is not immutable.');
	invariant((response.headers.get('x-content-type-options')||'').toLowerCase()==='nosniff',descriptor.name+' is missing nosniff.');
	invariant(Number(descriptor.bytes)===buffer.length,descriptor.name+' byte metadata drifted.');
	invariant(descriptor.sha256===sha256(buffer),descriptor.name+' SHA-256 metadata drifted.');
	invariant(descriptor.integrity===sri(buffer),descriptor.name+' SRI metadata drifted.');
	if(descriptor.physical===true&&descriptor.type==='style'){
		invariant(source.includes('@layer dp-tokens,dp-panel,dp-accessibility;'),descriptor.name+' lost cascade layer order.');
	}
	if(descriptor.physical===true&&descriptor.type==='script'){
		invariant(source.includes('dp-panel-runtime-chunk:'),descriptor.name+' lost its physical chunk marker.');
		invariant(source.includes('registerRuntimeChunk('),descriptor.name+' lost runtime registration.');
	}
	const gzip=zlib.gzipSync(buffer,{level:9}).length;
	const brotli=zlib.brotliCompressSync(buffer,{params:{[zlib.constants.BROTLI_PARAM_QUALITY]:11}}).length;
	const parseMilliseconds=descriptor.type==='script'?compileMilliseconds(source,descriptor.name):0;
	return {
		name:descriptor.name,
		type:descriptor.type,
		url:url.toString(),
		physical:descriptor.physical===true,
		chunks:Array.isArray(descriptor.chunks)?descriptor.chunks:[],
		dependencies:Array.isArray(descriptor.dependencies)?descriptor.dependencies:[],
		raw_bytes:buffer.length,
		gzip_bytes:gzip,
		brotli_bytes:brotli,
		parse_milliseconds:Number(parseMilliseconds.toFixed(3)),
		source,
	};
}

function checkDependencies(rows){
	const ready=new Set();
	const failures=[];
	for(const row of rows){
		if(!row.physical||row.type!=='script'){continue;}
		for(const dependency of row.dependencies){
			if(!ready.has(dependency)){
				failures.push(row.name+' precedes dependency '+dependency+'.');
			}
		}
		for(const chunk of row.chunks){ready.add(chunk);}
	}
	return failures;
}

function maximum(rows,key){
	return rows.reduce((value,row)=>Math.max(value,Number(row[key])||0),0);
}

function total(rows,key){
	return rows.reduce((value,row)=>value+(Number(row[key])||0),0);
}

function budgetChecks(profile,rows){
	const budget=budgets.profiles[profile];
	const styles=rows.filter(row=>row.type==='style').length;
	const scripts=rows.filter(row=>row.type==='script').length;
	const dependencyFailures=checkDependencies(rows);
	const values={
		styleChunks:styles,
		scriptChunks:scripts,
		totalChunks:rows.length,
		rawBytes:total(rows,'raw_bytes'),
		gzipBytes:total(rows,'gzip_bytes'),
		brotliBytes:total(rows,'brotli_bytes'),
		chunkRawBytes:maximum(rows,'raw_bytes'),
		chunkGzipBytes:maximum(rows,'gzip_bytes'),
		chunkBrotliBytes:maximum(rows,'brotli_bytes'),
		parseMilliseconds:total(rows,'parse_milliseconds'),
		chunkParseMilliseconds:maximum(rows,'parse_milliseconds'),
	};
	const mappings={
		styleChunks:'maxStyleChunks',
		scriptChunks:'maxScriptChunks',
		totalChunks:'maxTotalChunks',
		rawBytes:'maxRawBytes',
		gzipBytes:'maxGzipBytes',
		brotliBytes:'maxBrotliBytes',
		chunkRawBytes:'maxChunkRawBytes',
		chunkGzipBytes:'maxChunkGzipBytes',
		chunkBrotliBytes:'maxChunkBrotliBytes',
	};
	const checks=Object.entries(mappings).map(([name,budgetName])=>({
		name,
		actual:values[name],
		maximum:budget[budgetName],
		ok:values[name]<=budget[budgetName],
	}));
	checks.push({
		name:'parseMilliseconds',
		actual:values.parseMilliseconds,
		maximum:budgets.runtime.maxParseMilliseconds,
		ok:values.parseMilliseconds<=budgets.runtime.maxParseMilliseconds,
	});
	checks.push({
		name:'chunkParseMilliseconds',
		actual:values.chunkParseMilliseconds,
		maximum:budgets.runtime.maxChunkParseMilliseconds,
		ok:values.chunkParseMilliseconds<=budgets.runtime.maxChunkParseMilliseconds,
	});
	checks.push({name:'runtimeDependencyOrder',actual:dependencyFailures,expected:[],ok:dependencyFailures.length===0});
	return {values,checks};
}

async function inspectProfile(profile){
	const endpoint=manifestEndpoint(profile);
	const {response,buffer}=await fetchBounded(endpoint,'application/json');
	invariant(response.status===200,'Manifest returned HTTP '+response.status+' for '+profile+'.');
	invariant((response.headers.get('content-type')||'').toLowerCase().startsWith('application/json'),'Manifest content type is invalid for '+profile+'.');
	const manifest=JSON.parse(buffer.toString('utf8'));
	const descriptors=validateManifest(manifest,profile);
	const rows=await mapLimit(descriptors,6,descriptor=>inspectAsset(descriptor,endpoint));
	const {values,checks}=budgetChecks(profile,rows);
	return {
		profile,
		manifest_url:endpoint.toString(),
		capabilities:budgets.profiles[profile].capabilities,
		content_id:manifest.content_id,
		values,
		checks,
		assets:rows.map(({source,...row})=>row),
		sources:Object.fromEntries(rows.filter(row=>row.type==='script').map(row=>[row.name,row.source])),
	};
}

function findPuppeteer(){
	for(const candidate of [
		path.join(cwd,'.tmp','puppeteer-check','node_modules','puppeteer-core'),
		path.join(cwd,'.tmp','node_modules','puppeteer-core'),
		path.join(__dirname,'node_modules','puppeteer-core'),
	]){
		if(fs.existsSync(candidate)){return require(candidate);}
	}
	throw new Error('puppeteer-core is unavailable.');
}

function findBrowser(){
	const candidates=[
		options.browserPath,
		process.env.CHROME_PATH,
		path.join(process.env.HOME||'','.cache','ms-playwright','chromium-1228','chrome-linux64','chrome'),
		path.join(process.env.HOME||'','.cache','ms-playwright','chromium_headless_shell-1228','chrome-headless-shell-linux64','chrome-headless-shell'),
		'/usr/bin/google-chrome',
		'/usr/bin/chromium',
		'/usr/bin/chromium-browser',
	].filter(Boolean);
	const executable=candidates.find(candidate=>fs.existsSync(candidate));
	if(!executable){throw new Error('Chromium or Chrome is unavailable.');}
	return executable;
}

async function browserBootstrap(profile){
	const scripts=Object.entries(profile.sources).map(([name,source])=>({name,source}));
	const puppeteer=findPuppeteer();
	const browser=await puppeteer.launch({
		executablePath:findBrowser(),
		headless:true,
		args:['--disable-dev-shm-usage','--no-first-run','--no-default-browser-check','--no-sandbox'],
	});
	try{
		const page=await browser.newPage();
		const pageErrors=[];
		page.on('pageerror',error=>pageErrors.push(String(error?.message||error)));
		await page.goto('about:blank');
		const measurement=await page.evaluate(async entries=>{
			const longTasks=[];
			let observer=null;
			if(typeof PerformanceObserver==='function'&&PerformanceObserver.supportedEntryTypes?.includes('longtask')){
				observer=new PerformanceObserver(list=>longTasks.push(...list.getEntries().map(entry=>({duration:entry.duration,startTime:entry.startTime}))));
				observer.observe({entryTypes:['longtask']});
			}
			const chunks=[];
			const started=performance.now();
			for(const entry of entries){
				const blob=new Blob([entry.source],{type:'application/javascript'});
				const url=URL.createObjectURL(blob);
				const before=performance.now();
				try{
					await new Promise((resolve,reject)=>{
						const script=document.createElement('script');
						script.src=url;
						script.onload=resolve;
						script.onerror=()=>reject(new Error('Failed to bootstrap '+entry.name));
						document.head.appendChild(script);
					});
				}
				finally{URL.revokeObjectURL(url);}
				chunks.push({name:entry.name,milliseconds:performance.now()-before});
			}
			await new Promise(resolve=>setTimeout(resolve,0));
			if(observer){observer.takeRecords();observer.disconnect();}
			const registry=window.DataphyrePanel?.runtimeChunks||{};
			return {
				milliseconds:performance.now()-started,
				chunks,
				longTasks,
				registry:Object.keys(registry).filter(name=>registry[name]?.status==='ready'),
			};
		},scripts);
		const checks=[
			{
				name:'bootstrapMilliseconds',
				actual:Number(measurement.milliseconds.toFixed(3)),
				maximum:budgets.runtime.maxBootstrapMilliseconds,
				ok:measurement.milliseconds<=budgets.runtime.maxBootstrapMilliseconds,
			},
			{
				name:'chunkBootstrapMilliseconds',
				actual:Number(maximum(measurement.chunks,'milliseconds').toFixed(3)),
				maximum:budgets.runtime.maxChunkBootstrapMilliseconds,
				ok:maximum(measurement.chunks,'milliseconds')<=budgets.runtime.maxChunkBootstrapMilliseconds,
			},
			{
				name:'longTasks',
				actual:measurement.longTasks.length,
				maximum:budgets.runtime.maxLongTasks,
				ok:measurement.longTasks.length<=budgets.runtime.maxLongTasks,
			},
			{
				name:'pageErrors',
				actual:pageErrors,
				maximum:budgets.runtime.maxPageErrors,
				ok:pageErrors.length<=budgets.runtime.maxPageErrors,
			},
		];
		return {...measurement,pageErrors,checks,browser:await browser.version()};
	}
	finally{await browser.close();}
}

function selectedProfiles(){
	const known=Object.keys(budgets.profiles);
	const selected=options.profiles.length?options.profiles:known;
	for(const profile of selected){invariant(known.includes(profile),'Unknown delivery profile: '+profile+'.');}
	return [...new Set(selected)];
}

function offlineSelfTest(){
	invariant(budgets.schema==='dataphyre.panel.asset-delivery-budgets.v1','Budget schema drifted.');
	const source='window.DataphyrePanel={runtimeChunks:{}};';
	const descriptor={
		name:'panel-runtime-kernel.js',
		type:'script',
		physical:true,
		chunks:['kernel'],
		dependencies:[],
	};
	const row={
		...descriptor,
		raw_bytes:Buffer.byteLength(source),
		gzip_bytes:zlib.gzipSync(source,{level:9}).length,
		brotli_bytes:zlib.brotliCompressSync(source).length,
		parse_milliseconds:compileMilliseconds(source,descriptor.name),
	};
	invariant(checkDependencies([row]).length===0,'Self-test dependency order failed.');
	const broken={...row,name:'panel-runtime-form.js',chunks:['form'],dependencies:['transport']};
	invariant(checkDependencies([broken]).length===1,'Self-test missing dependency was accepted.');
	invariant(sha256(Buffer.from('panel')).length===64,'Self-test SHA-256 failed.');
	invariant(sri(Buffer.from('panel')).startsWith('sha384-'),'Self-test SRI failed.');
	console.log(JSON.stringify({ok:true,schema:budgets.schema,profiles:Object.keys(budgets.profiles),checks:5},null,2));
}

async function main(){
	if(options.help){printHelp();return;}
	if(options.selfTest){offlineSelfTest();return;}
	const reports=[];
	for(const profile of selectedProfiles()){reports.push(await inspectProfile(profile));}
	let browser=null;
	if(options.browser){
		const full=reports.find(report=>report.profile==='full')||reports[reports.length-1];
		try{browser=await browserBootstrap(full);}
		catch(error){
			if(!options.allowNoBrowser){throw error;}
			browser={unavailable:true,error:String(error?.message||error),checks:[]};
		}
	}
	const failures=[];
	for(const profile of reports){
		for(const check of profile.checks){if(!check.ok){failures.push({profile:profile.profile,...check});}}
	}
	for(const check of browser?.checks||[]){if(!check.ok){failures.push({profile:'browser',...check});}}
	const report={
		type:'dataphyre_panel_asset_delivery_audit',
		version:1,
		generated_at:new Date().toISOString(),
		ok:failures.length===0,
		budget_schema:budgets.schema,
		profiles:reports.map(({sources,...profile})=>profile),
		browser,
		failures,
	};
	if(options.report){
		const reportPath=path.resolve(options.report);
		fs.mkdirSync(path.dirname(reportPath),{recursive:true});
		fs.writeFileSync(reportPath,JSON.stringify(report,null,2)+'\n');
	}
	console.log(JSON.stringify({
		ok:report.ok,
		profiles:report.profiles.map(profile=>({profile:profile.profile,...profile.values})),
		browser:browser?{
			milliseconds:browser.milliseconds,
			chunk_maximum:browser.chunks?maximum(browser.chunks,'milliseconds'):null,
			long_tasks:browser.longTasks?.length,
			page_errors:browser.pageErrors,
			unavailable:browser.unavailable||false,
		}:null,
		failures,
	},null,2));
	if(failures.length&&!options.reportOnly){process.exitCode=1;}
}

main().catch(error=>{
	console.error(error&&error.stack?error.stack:String(error));
	process.exitCode=1;
});
