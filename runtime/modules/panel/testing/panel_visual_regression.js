#!/usr/bin/env node
'use strict';

const fs=require('fs');
const path=require('path');
const crypto=require('crypto');

const cwd=process.cwd();
const args=parseArgs(process.argv.slice(2));
const baseUrl=(args.baseUrl || process.env.DP_PANEL_BASE_URL || '').replace(/\/$/, '');
const artifactRoot=path.resolve(cwd, args.artifactDir || '.tmp/panel-visual-regression');
const baselineRoot=path.resolve(cwd, args.baselineDir || path.join(__dirname,'baselines'));
const reportPath=args.report ? path.resolve(cwd, args.report) : path.join(artifactRoot, 'report.json');

const scenarioCatalog=[
	{id:'command_center', path:'', title:'Command Center'},
	{id:'orders_index', path:'/orders?group=status&view=review&page=1', title:'Orders index'},
	{id:'orders_empty', path:'/orders?q=__dataphyre_visual_empty__', title:'Orders empty state'},
	{id:'orders_board', path:'/orders/board', title:'Orders board'},
	{id:'orders_create', path:'/orders/create', title:'Order create form'},
	{id:'orders_show', path:'/orders/4', title:'Order detail'},
	{id:'orders_edit', path:'/orders/4/edit', title:'Order edit form'},
	{id:'orders_import', path:'/orders/import', title:'Order import'},
	{id:'sellers_create', path:'/sellers/create', title:'Seller create form'},
	{id:'feature_showcase', path:'?resource=feature_showcase', title:'Feature showcase'},
	{id:'state_loading_error', path:'?resource=state_lab&fixture=loading_error', title:'Loading and error states'},
	{id:'state_validation_disabled', path:'?resource=state_lab&fixture=validation_disabled', title:'Validation and disabled states'},
	{id:'state_dense_long', path:'?resource=state_lab&fixture=dense_long', title:'Dense and long-content states'},
	{id:'state_relation_upload', path:'?resource=state_lab&fixture=relation_upload', title:'Relation and upload states'},
	{id:'state_modal', path:'?resource=state_lab&fixture=modal', title:'Modal state', scrollSamples:['top']},
	{id:'orders_validation', path:'/orders/create', title:'Order validation state', prepare:'invalid_form'},
	{id:'orders_filter_modal', path:'/orders?group=status&view=review&page=1', title:'Filter modal state', prepare:'filter_modal', scrollSamples:['top']},
	{id:'mobile_drawer', path:'/orders?group=status&view=review&page=1', title:'Mobile drawer state', prepare:'mobile_drawer', viewports:['mobile','320','375'], scrollSamples:['top']},
];

const viewportCatalog={
	desktop:{width:1440, height:1000},
	laptop:{width:1024, height:800},
	mobile:{width:390, height:844},
	'320':{width:320, height:568},
	'375':{width:375, height:667},
	'768':{width:768, height:1024},
	'2560':{width:2560, height:1440},
};
const defaultViewportNames=['desktop','laptop','mobile'];

function parseArgs(argv){
	const parsed={
		auditOnly:false,
		artifactDir:'',
		baseUrl:'',
		baselineDir:'',
		browser:'',
		containerWidths:[],
		directions:[],
		forcedColors:[],
		theme:'',
		themeModes:[],
		diffThreshold:0.0025,
		headed:false,
		help:false,
		reducedMotions:[],
		report:'',
		reportOnly:false,
		scenarios:[],
		scrollSamples:[],
		updateBaselines:false,
		viewports:[],
		zooms:[],
	};
	for(let index=0; index<argv.length; index++){
		const argument=argv[index];
		const value=()=>{
			const inline=argument.indexOf('=');
			if(inline!==-1){
				return argument.slice(inline+1);
			}
			index++;
			return argv[index] || '';
		};
		if(argument==='--help' || argument==='-h'){
			parsed.help=true;
		}
		else if(argument==='--audit-only'){
			parsed.auditOnly=true;
		}
		else if(argument==='--headed'){
			parsed.headed=true;
		}
		else if(argument==='--report-only'){
			parsed.reportOnly=true;
		}
		else if(argument==='--update-baselines'){
			parsed.updateBaselines=true;
		}
		else if(argument==='--base-url' || argument.startsWith('--base-url=')){
			parsed.baseUrl=value();
		}
		else if(argument==='--browser' || argument.startsWith('--browser=')){
			parsed.browser=value();
		}
		else if(argument==='--theme' || argument.startsWith('--theme=')){
			parsed.theme=value().trim();
		}
		else if(argument==='--theme-mode' || argument.startsWith('--theme-mode=')){
			parsed.themeModes.push(...splitValues(value()));
		}
		else if(argument==='--direction' || argument.startsWith('--direction=')){
			parsed.directions.push(...splitValues(value()));
		}
		else if(argument==='--zoom' || argument.startsWith('--zoom=')){
			parsed.zooms.push(...splitValues(value()));
		}
		else if(argument==='--reduced-motion' || argument.startsWith('--reduced-motion=')){
			parsed.reducedMotions.push(...splitValues(value()));
		}
		else if(argument==='--forced-colors' || argument.startsWith('--forced-colors=')){
			parsed.forcedColors.push(...splitValues(value()));
		}
		else if(argument==='--container-width' || argument.startsWith('--container-width=')){
			parsed.containerWidths.push(...splitValues(value()));
		}
		else if(argument==='--artifact-dir' || argument.startsWith('--artifact-dir=')){
			parsed.artifactDir=value();
		}
		else if(argument==='--baseline-dir' || argument.startsWith('--baseline-dir=')){
			parsed.baselineDir=value();
		}
		else if(argument==='--report' || argument.startsWith('--report=')){
			parsed.report=value();
		}
		else if(argument==='--scenario' || argument.startsWith('--scenario=')){
			parsed.scenarios.push(...value().split(',').map(item=>item.trim()).filter(Boolean));
		}
		else if(argument==='--scroll-sample' || argument.startsWith('--scroll-sample=') || argument==='--scroll-samples' || argument.startsWith('--scroll-samples=')){
			parsed.scrollSamples.push(...value().split(',').map(item=>item.trim()).filter(Boolean));
		}
		else if(argument==='--viewport' || argument.startsWith('--viewport=')){
			parsed.viewports.push(...value().split(',').map(item=>item.trim()).filter(Boolean));
		}
		else if(argument==='--diff-threshold' || argument.startsWith('--diff-threshold=')){
			parsed.diffThreshold=Math.max(0, Math.min(1, Number(value())));
		}
		else {
			throw new Error('Unknown argument: '+argument+'. Run with --help for usage.');
		}
	}
	if(parsed.auditOnly&&parsed.updateBaselines){throw new Error('--audit-only and --update-baselines cannot be combined.');}
	parsed.themeModes=choices(parsed.themeModes,['light','dark','system'],'theme modes');
	parsed.directions=choices(parsed.directions,['ltr','rtl'],'directions');
	parsed.reducedMotions=aliasedChoices(parsed.reducedMotions,{'reduce':'reduce','reduced':'reduce','on':'reduce','true':'reduce','1':'reduce','no-preference':'no-preference','none':'no-preference','off':'no-preference','false':'no-preference','0':'no-preference'},'reduced-motion values');
	parsed.forcedColors=aliasedChoices(parsed.forcedColors,{'active':'active','on':'active','true':'active','1':'active','none':'none','off':'none','false':'none','0':'none'},'forced-colors values');
	parsed.zooms=numbers(parsed.zooms,'zoom',raw=>raw.endsWith('%')?Number(raw.slice(0,-1))/100:Number(raw),0.25,5);
	parsed.containerWidths=Array.from(new Set(numbers(parsed.containerWidths,'container width',raw=>Number(raw.replace(/px$/i,'')),1,10000).map(value=>Math.round(value))));
	return parsed;
}

function splitValues(value){return String(value||'').split(',').map(item=>item.trim()).filter(Boolean);}
function choices(values,allowed,label){const normalized=Array.from(new Set(values.map(value=>String(value).trim().toLowerCase())));const invalid=normalized.filter(value=>!allowed.includes(value));if(invalid.length>0){throw new Error('Unknown '+label+': '+invalid.join(', '));}return normalized;}
function aliasedChoices(values,aliases,label){const normalized=values.map(value=>String(value).trim().toLowerCase());const invalid=normalized.filter(value=>!aliases[value]);if(invalid.length>0){throw new Error('Unknown '+label+': '+invalid.join(', '));}return Array.from(new Set(normalized.map(value=>aliases[value])));}
function numbers(values,label,convert,minimum,maximum){const normalized=values.map(value=>({raw:String(value).trim().toLowerCase(),number:convert(String(value).trim().toLowerCase())}));const invalid=normalized.filter(item=>!Number.isFinite(item.number)||item.number<minimum||item.number>maximum);if(invalid.length>0){throw new Error('Invalid '+label+' values: '+invalid.map(item=>item.raw).join(', '));}return Array.from(new Set(normalized.map(item=>item.number)));}

function printHelp(){
	console.log([
		'Dataphyre Panel visual regression runner',
		'',
		'Usage:',
		'  node runtime/modules/panel/testing/panel_visual_regression.js [options]',
		'',
		'Options:',
		'  --base-url <url>             Mounted Panel showroom URL.',
		'  --browser <path>             Chrome or Edge executable.',
		'  --theme <name>               Apply a Panel theme and suffix artifacts with its name.',
		'  --theme-mode <modes>         light, dark, system. CSV/repeat creates a matrix.',
		'  --direction <values>         ltr, rtl. CSV/repeat creates a matrix.',
		'  --zoom <values>              Reflow zoom ratios or percentages (1, 1.5, 200%).',
		'  --reduced-motion <values>    reduce, no-preference.',
		'  --forced-colors <values>     active, none; unsupported emulation is reported, not hidden.',
		'  --container-width <pixels>   Constrain the Panel shell inline size for container audits.',
		'  --scenario <ids>             Comma-separated scenario ids.',
		'  --viewport <names>           desktop, laptop, mobile, 320, 375, 768, 2560.',
		'  --scroll-sample <names>      top, middle, bottom. Defaults to top.',
		'  --artifact-dir <path>        Current screenshots and diff artifacts.',
		'  --baseline-dir <path>        Approved reference screenshots.',
		'  --update-baselines           Replace references with current screenshots.',
		'  --diff-threshold <ratio>     Maximum changed-pixel ratio. Default 0.0025.',
		'  --report <path>              JSON report output path.',
		'  --report-only                Record failures without a non-zero exit.',
		'  --audit-only                 Run structural, accessibility, target-size, and overflow gates without screenshots or baselines.',
		'  --headed                     Show the controlled browser.',
		'  --help                       Show this help.',
		'',
		'Default matrix: desktop, laptop, and mobile with no environment overrides.',
		'Explicit axis values form a Cartesian product and are encoded in every artifact id.',
		'',
		'Example audit matrix:',
		'  node runtime/modules/panel/testing/panel_visual_regression.js --scenario orders_index --viewport 320,768 --theme-mode light,dark --direction ltr,rtl --audit-only',
		'',
		'Scenarios: '+scenarioCatalog.map(item=>item.id).join(', '),
	].join('\n'));
}

function findPuppeteer(){
	const candidates=[
		path.join(cwd, '.tmp', 'puppeteer-check', 'node_modules', 'puppeteer-core'),
		path.join(cwd, '.tmp', 'node_modules', 'puppeteer-core'),
		path.join(__dirname, 'node_modules', 'puppeteer-core'),
	];
	for(const candidate of candidates){
		if(fs.existsSync(candidate)){
			return require(candidate);
		}
	}
	throw new Error('Unable to find puppeteer-core. Restore .tmp/puppeteer-check or install the browser test dependencies.');
}

function findBrowser(){
	const candidates=[
		args.browser,
		process.env.CHROME_PATH,
		'C:/Program Files/Google/Chrome/Application/chrome.exe',
		'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
		path.join(process.env.LOCALAPPDATA || '', 'Google/Chrome/Application/chrome.exe'),
		path.join(process.env.PROGRAMFILES || '', 'Microsoft/Edge/Application/msedge.exe'),
		path.join(process.env['PROGRAMFILES(X86)'] || '', 'Microsoft/Edge/Application/msedge.exe'),
	].filter(Boolean);
	const browser=candidates.find(candidate=>fs.existsSync(candidate));
	if(!browser){
		throw new Error('Unable to find Chrome or Edge. Set --browser or CHROME_PATH.');
	}
	return browser;
}

function selectedScenarios(){
	if(args.scenarios.length===0){
		return scenarioCatalog;
	}
	const selected=scenarioCatalog.filter(item=>args.scenarios.includes(item.id));
	const missing=args.scenarios.filter(id=>!scenarioCatalog.some(item=>item.id===id));
	if(missing.length>0){
		throw new Error('Unknown scenarios: '+missing.join(', '));
	}
	return selected;
}

function selectedViewports(){
	const names=args.viewports.length===0 ? defaultViewportNames : Array.from(new Set(args.viewports));
	const missing=names.filter(name=>!viewportCatalog[name]);
	if(missing.length>0){
		throw new Error('Unknown viewports: '+missing.join(', '));
	}
	return names.map(name=>({name, ...viewportCatalog[name]}));
}

function matrixValues(values){return values.length>0?values:[null];}
function selectedEnvironments(){
	let environments=selectedViewports().map(viewport=>({viewport}));
	for(const [name,values] of [
		['themeMode',matrixValues(args.themeModes)],
		['direction',matrixValues(args.directions)],
		['zoom',matrixValues(args.zooms)],
		['reducedMotion',matrixValues(args.reducedMotions)],
		['forcedColors',matrixValues(args.forcedColors)],
		['containerWidth',matrixValues(args.containerWidths)],
	]){
		environments=environments.flatMap(environment=>values.map(value=>({...environment,[name]:value})));
	}
	return environments;
}

function executionPlan(environments,scenarios){
	const runnable=[];
	const skipped=[];
	for(const environment of environments){
		for(const scenario of scenarios){
			if(Array.isArray(scenario.viewports)&&!scenario.viewports.includes(environment.viewport.name)){
				skipped.push({scenario:scenario.id,viewport:environment.viewport.name,reason:'viewport_not_supported'});
				continue;
			}
			runnable.push({environment,scenario});
		}
	}
	if(runnable.length===0){
		throw new Error('The selected scenarios do not support any selected viewport.');
	}
	return{runnable,skipped};
}

function artifactSlug(value){return String(value).replace(/[^a-z0-9_-]+/gi,'_').replace(/^_+|_+$/g,'').toLowerCase();}
function zoomArtifactSlug(zoom){return artifactSlug(String(zoom))+'x';}
function environmentAxes(environment){return{viewport:{name:environment.viewport.name,width:environment.viewport.width,height:environment.viewport.height},theme:args.theme||null,themeMode:environment.themeMode,direction:environment.direction,zoom:environment.zoom,reducedMotion:environment.reducedMotion,forcedColors:environment.forcedColors,containerWidth:environment.containerWidth};}
function artifactBaseId(scenario,environment){
	const themeSlug=artifactSlug(args.theme);
	const parts=[scenario.id,environment.viewport.name];
	if(themeSlug){parts.push(themeSlug);}
	if(environment.themeMode!==null){parts.push('mode-'+artifactSlug(environment.themeMode));}
	if(environment.direction!==null){parts.push('dir-'+artifactSlug(environment.direction));}
	if(environment.zoom!==null){parts.push('zoom-'+zoomArtifactSlug(environment.zoom));}
	if(environment.reducedMotion!==null){parts.push('motion-'+artifactSlug(environment.reducedMotion));}
	if(environment.forcedColors!==null){parts.push('colors-'+artifactSlug(environment.forcedColors));}
	if(environment.containerWidth!==null){parts.push('container-'+environment.containerWidth);}
	return parts.join('__');
}
function effectiveViewport(environment){const zoom=environment.zoom||1;return{width:Math.max(1,Math.round(environment.viewport.width/zoom)),height:Math.max(1,Math.round(environment.viewport.height/zoom)),deviceScaleFactor:zoom};}
function hasEnvironmentOverrides(environment){return environment.themeMode!==null||environment.direction!==null||environment.zoom!==null||environment.reducedMotion!==null||environment.forcedColors!==null||environment.containerWidth!==null;}

async function installEnvironment(page,environment){
	if(!hasEnvironmentOverrides(environment)){return;}
	if(environment.themeMode!==null){await page.setCookie({name:'dataphyre_panel_theme_mode',value:environment.themeMode,url:new URL(baseUrl).origin+'/',path:'/'});}
	await page.evaluateOnNewDocument(settings=>{
		const apply=()=>{
			try{if(settings.themeMode!==null){localStorage.setItem('dataphyre_panel_theme_mode',settings.themeMode);}}catch(error){}
			const root=document.documentElement;
			if(settings.themeMode!==null&&root){root.dataset.dpThemeMode=settings.themeMode;if(document.body){document.body.dataset.dpThemeMode=settings.themeMode;}}
			if(settings.direction!==null&&root){root.setAttribute('dir',settings.direction);if(document.body){document.body.setAttribute('dir',settings.direction);}}
			if(settings.containerWidth!==null&&document.head){let style=document.getElementById('dp-panel-visual-container-width');if(!style){style=document.createElement('style');style.id='dp-panel-visual-container-width';document.head.appendChild(style);}style.textContent='main.dp-panel{inline-size:min(100%,'+settings.containerWidth+'px)!important;max-inline-size:'+settings.containerWidth+'px!important;margin-inline:auto!important}';}
		};
		apply();document.addEventListener('DOMContentLoaded',apply,{once:true});
	},environmentAxes(environment));
}

async function emulateEnvironmentMedia(page,environment){
	const capabilities={
		reducedMotion:{requested:environment.reducedMotion,supported:null,applied:false,error:null},
		forcedColors:{requested:environment.forcedColors,supported:null,applied:false,error:null},
	};
	const reduced=environment.reducedMotion===null?null:{name:'prefers-reduced-motion',value:environment.reducedMotion};
	const forced=environment.forcedColors===null?null:{name:'forced-colors',value:environment.forcedColors};
	const requested=[reduced,forced].filter(Boolean);
	if(requested.length===0){return capabilities;}
	try{
		await page.emulateMediaFeatures(requested);
		if(reduced){capabilities.reducedMotion.supported=true;capabilities.reducedMotion.applied=true;}
		if(forced){capabilities.forcedColors.supported=true;capabilities.forcedColors.applied=true;}
	}
	catch(error){
		const puppeteerMessage=String(error&&error.message||error);
		try{
			// Puppeteer validates a narrower media-feature list than Chromium's
			// protocol. Use the public CDP session as the deterministic fallback
			// so forced-colors is actually exercised instead of silently warned.
			const session=page.__dpPanelMediaSession||await page.createCDPSession();
			page.__dpPanelMediaSession=session;
			await session.send('Emulation.setEmulatedMedia',{features:requested});
			if(reduced){capabilities.reducedMotion.supported=true;capabilities.reducedMotion.applied=true;}
			if(forced){capabilities.forcedColors.supported=true;capabilities.forcedColors.applied=true;}
		}
		catch(protocolError){
			const protocolMessage=String(protocolError&&protocolError.message||protocolError);
			if(forced){capabilities.forcedColors.supported=false;capabilities.forcedColors.error=puppeteerMessage+'; CDP fallback: '+protocolMessage;}
			if(reduced){
				try{await page.emulateMediaFeatures([reduced]);capabilities.reducedMotion.supported=true;capabilities.reducedMotion.applied=true;}
				catch(reducedError){capabilities.reducedMotion.supported=false;capabilities.reducedMotion.error=String(reducedError&&reducedError.message||reducedError);}
			}
		}
	}
	return capabilities;
}

async function applyEnvironment(page,environment,phase){
	await page.setViewport(effectiveViewport(environment));
	const capabilities=await emulateEnvironmentMedia(page,environment);
	let resolved=null;
	if(hasEnvironmentOverrides(environment)){
		resolved=await page.evaluate(settings=>{
			try{if(settings.themeMode!==null){localStorage.setItem('dataphyre_panel_theme_mode',settings.themeMode);}}catch(error){}
			if(settings.themeMode!==null){document.documentElement.dataset.dpThemeMode=settings.themeMode;if(document.body){document.body.dataset.dpThemeMode=settings.themeMode;}}
			if(settings.direction!==null){document.documentElement.setAttribute('dir',settings.direction);if(document.body){document.body.setAttribute('dir',settings.direction);}}
			if(settings.containerWidth!==null&&document.head){let style=document.getElementById('dp-panel-visual-container-width');if(!style){style=document.createElement('style');style.id='dp-panel-visual-container-width';document.head.appendChild(style);}style.textContent='main.dp-panel{inline-size:min(100%,'+settings.containerWidth+'px)!important;max-inline-size:'+settings.containerWidth+'px!important;margin-inline:auto!important}';}
			const panel=document.querySelector('main.dp-panel');return{themeMode:document.body?.dataset.dpThemeMode||document.documentElement.dataset.dpThemeMode||null,direction:getComputedStyle(document.documentElement).direction,reducedMotion:matchMedia('(prefers-reduced-motion: reduce)').matches,forcedColors:matchMedia('(forced-colors: active)').matches,viewport:{width:document.documentElement.clientWidth,height:document.documentElement.clientHeight,deviceScaleFactor:devicePixelRatio},containerWidth:panel?Math.round(panel.getBoundingClientRect().width):null};
		},environmentAxes(environment));
	}
	return{phase,requested:environmentAxes(environment),effectiveViewport:effectiveViewport(environment),capabilities,resolved};
}

async function settlePage(page,quietMilliseconds=420,timeoutMilliseconds=5000){
	await page.evaluate(async(quiet,timeout)=>{
		const panel=document.querySelector('main.dp-panel');
		if(!panel){return;}
		let lastMutation=performance.now();
		const observer=new MutationObserver(()=>{lastMutation=performance.now();});
		observer.observe(panel,{attributes:true,childList:true,subtree:true,characterData:true});
		const started=performance.now();
		for(;;){
			const pending=(typeof dpPanelA11yResizeFrame!=='undefined'&&dpPanelA11yResizeFrame)||(typeof dpPanelFieldMutationFrame!=='undefined'&&dpPanelFieldMutationFrame)||(typeof dpPanelA11yInputRefreshTimer!=='undefined'&&dpPanelA11yInputRefreshTimer);
			if(!pending&&performance.now()-lastMutation>=quiet){break;}
			if(performance.now()-started>=timeout){break;}
			await new Promise(resolve=>setTimeout(resolve,50));
		}
		observer.disconnect();
		await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
	},quietMilliseconds,timeoutMilliseconds);
}

async function prepareScenario(page,scenario){
	if(!scenario.prepare){return;}
	if(scenario.prepare==='invalid_form'){
		await page.evaluate(()=>{
			const form=document.querySelector('form.dp-panel-form');
			if(!form){return;}
			const required=Array.from(form.querySelectorAll('[required]'));
			required.forEach(control=>{if(control.matches('input:not([type="checkbox"]):not([type="radio"]),textarea')){control.value='';}});
			form.checkValidity();
		});
		await page.waitForFunction(()=>document.querySelectorAll('.dp-panel-field-invalid,[aria-invalid="true"]').length>0,{timeout:5000});
		return;
	}
	if(scenario.prepare==='filter_modal'){
		await page.waitForFunction(()=>{
			const trigger=document.querySelector('.dp-panel-filter-trigger[data-dp-panel-filter-modal]');
			const templateId=trigger?.dataset.dpPanelFilterTemplate||'';
			const template=templateId?document.getElementById(templateId):null;
			return document.readyState==='complete'
				&&trigger instanceof HTMLButtonElement
				&&!trigger.disabled
				&&template?.tagName==='TEMPLATE';
		},{timeout:10000});
		await page.click('.dp-panel-filter-trigger');
		try{
			await page.waitForSelector('.dp-panel-modal-root:not([hidden]) .dp-panel-modal',{visible:true,timeout:10000});
		}
		catch(error){
			const diagnostics=await page.evaluate(()=>{
				const triggers=Array.from(document.querySelectorAll('.dp-panel-filter-trigger[data-dp-panel-filter-modal]'));
				const trigger=triggers[0]||null;
				const templateId=trigger?.dataset.dpPanelFilterTemplate||'';
				const template=templateId?document.getElementById(templateId):null;
				const modalRoot=document.querySelector('.dp-panel-modal-root');
				const rect=trigger?.getBoundingClientRect();
				const hit=rect?document.elementFromPoint(rect.left+(rect.width/2),rect.top+(rect.height/2)):null;
				return{
					documentReadyState:document.readyState,
					triggerCount:triggers.length,
					triggerDisabled:trigger?.disabled??null,
					triggerConnected:trigger?.isConnected??false,
					triggerPointerEvents:trigger?getComputedStyle(trigger).pointerEvents:null,
					triggerRect:rect?{left:Math.round(rect.left),top:Math.round(rect.top),width:Math.round(rect.width),height:Math.round(rect.height)}:null,
					triggerHitTarget:hit?{tag:hit.tagName,className:String(hit.className||''),insideTrigger:trigger?.contains(hit)??false}:null,
					templateId,
					templatePresent:template?.tagName==='TEMPLATE',
					modalRootPresent:modalRoot!==null,
					modalRootHidden:modalRoot?.hidden??null,
					modalRootBusy:modalRoot?.classList.contains('dp-panel-modal-busy')??false,
				};
			});
			throw new Error('Filter modal did not open after its runtime became ready. Diagnostics: '+JSON.stringify(diagnostics),{cause:error});
		}
		return;
	}
	if(scenario.prepare==='mobile_drawer'){
		await page.click('.dp-panel-mobile-nav-toggle');
		await page.waitForFunction(()=>document.querySelector('main.dp-panel')?.classList.contains('dp-panel-mobile-nav-open'),{timeout:5000});
	}
}

async function scenarioScrollSamples(page,scenario){
	const samples=await resolvedScrollSamples(page);
	if(!Array.isArray(scenario.scrollSamples)){return samples;}
	return samples.filter(sample=>scenario.scrollSamples.includes(sample.name));
}

function selectedScrollSamples(){
	const names=args.scrollSamples.length===0 ? ['top'] : args.scrollSamples;
	const allowed=['top','middle','bottom'];
	const missing=names.filter(name=>!allowed.includes(name));
	if(missing.length>0){
		throw new Error('Unknown scroll samples: '+missing.join(', '));
	}
	return Array.from(new Set(names));
}

async function resolvedScrollSamples(page){
	const maximum=await page.evaluate(()=>Math.max(0,document.documentElement.scrollHeight-window.innerHeight));
	const offsets={top:0,middle:Math.round(maximum/2),bottom:maximum};
	const seen=new Set();
	return selectedScrollSamples().map(name=>({name,offset:offsets[name]})).filter(sample=>{
		if(seen.has(sample.offset)){
			return false;
		}
		seen.add(sample.offset);
		return true;
	});
}

function ensureDirectory(directory){
	fs.mkdirSync(directory, {recursive:true});
}

function sha256(buffer){
	return crypto.createHash('sha256').update(buffer).digest('hex');
}

function writeJson(file, value){
	ensureDirectory(path.dirname(file));
	fs.writeFileSync(file, JSON.stringify(value, null, 2)+'\n');
}

async function comparePngs(page, baseline, current){
	const result=await page.evaluate(async payload=>{
		const load=source=>new Promise((resolve,reject)=>{
			const image=new Image();
			image.onload=()=>resolve(image);
			image.onerror=()=>reject(new Error('Unable to decode screenshot.'));
			image.src=source;
		});
		const [before,after]=await Promise.all([load(payload.before),load(payload.after)]);
		if(before.width!==after.width || before.height!==after.height){
			return {changedPixels:before.width*before.height, ratio:1, dimensionsMatch:false, width:after.width, height:after.height, baselineWidth:before.width, baselineHeight:before.height};
		}
		const canvas=document.createElement('canvas');
		canvas.width=after.width;
		canvas.height=after.height;
		const context=canvas.getContext('2d', {willReadFrequently:true});
		context.drawImage(before,0,0);
		const beforePixels=context.getImageData(0,0,canvas.width,canvas.height).data;
		context.clearRect(0,0,canvas.width,canvas.height);
		context.drawImage(after,0,0);
		const afterPixels=context.getImageData(0,0,canvas.width,canvas.height).data;
		let changed=0;
		for(let index=0; index<beforePixels.length; index+=4){
			const delta=Math.max(
				Math.abs(beforePixels[index]-afterPixels[index]),
				Math.abs(beforePixels[index+1]-afterPixels[index+1]),
				Math.abs(beforePixels[index+2]-afterPixels[index+2]),
				Math.abs(beforePixels[index+3]-afterPixels[index+3])
			);
			if(delta>12){
				changed++;
			}
		}
		return {changedPixels:changed, ratio:changed/(canvas.width*canvas.height), dimensionsMatch:true, width:canvas.width, height:canvas.height, baselineWidth:before.width, baselineHeight:before.height};
	},{
		before:'data:image/png;base64,'+baseline.toString('base64'),
		after:'data:image/png;base64,'+current.toString('base64'),
	});
	return result;
}

async function auditPage(page){
	return page.evaluate(()=>{
		const root=document.documentElement;
		const viewportWidth=root.clientWidth;
		const viewportHeight=root.clientHeight;
		const viewportRect={top:0,right:viewportWidth,bottom:viewportHeight,left:0,width:viewportWidth,height:viewportHeight};
		const portalSelector='.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-toast-root,.dp-panel-unsaved-root';
		const visible=element=>{
			if(element.closest('[hidden],[aria-hidden="true"]')){return false;}
			const closedDetails=element.closest('details:not([open])');
			if(closedDetails&&!closedDetails.querySelector(':scope>summary')?.contains(element)){return false;}
			const style=getComputedStyle(element);
			const rect=element.getBoundingClientRect();
			return style.display!=='none' && style.visibility!=='hidden' && Number(style.opacity)!==0 && rect.width>0 && rect.height>0 && rect.bottom>0 && rect.top<viewportHeight && rect.right>0 && rect.left<viewportWidth;
		};
		const accessibleName=element=>{
			const labelledBy=(element.getAttribute('aria-labelledby') || '').trim().split(/\s+/).filter(Boolean).map(id=>document.getElementById(id)?.textContent || '').join(' ').trim();
			return (element.getAttribute('aria-label') || labelledBy || element.getAttribute('title') || element.textContent || '').trim();
		};
		const ids=new Map();
		document.querySelectorAll('[id]').forEach(element=>{
			const id=element.id.trim();
			if(id){
				ids.set(id,(ids.get(id) || 0)+1);
			}
		});
		const duplicateIds=Array.from(ids.entries()).filter(([,count])=>count>1).map(([id,count])=>({id,count}));
		const unnamedButtons=Array.from(document.querySelectorAll('button,[role="button"]')).filter(element=>visible(element) && accessibleName(element)==='').map(element=>({tag:element.tagName,class:String(element.className).slice(0,140)}));
		const imagesMissingAlt=Array.from(document.querySelectorAll('img')).filter(element=>visible(element) && !element.hasAttribute('alt') && element.getAttribute('aria-hidden')!=='true').map(element=>({src:element.getAttribute('src') || ''}));
		const controls=Array.from(document.querySelectorAll('input:not([type="hidden"]),select,textarea')).filter(visible);
		const unlabeledControls=controls.filter(element=>{
			if(element.getAttribute('aria-label') || element.getAttribute('aria-labelledby') || element.getAttribute('title') || element.getAttribute('placeholder')){
				return false;
			}
			if(element.id && document.querySelector('label[for="'+CSS.escape(element.id)+'"]')){
				return false;
			}
			return !element.closest('label');
		}).map(element=>({tag:element.tagName,type:element.getAttribute('type') || '',name:element.getAttribute('name') || ''}));
		const touchSelector='button,[role="button"],summary,input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]),select,textarea,.dp-panel-button';
		const undersizedTargets=Array.from(document.querySelectorAll(touchSelector)).filter(visible).map(element=>{
			const rect=element.getBoundingClientRect();
			return {tag:element.tagName,label:accessibleName(element).slice(0,80),width:Math.round(rect.width),height:Math.round(rect.height)};
		}).filter(target=>target.width<44 || target.height<44);
		const positiveTabindex=Array.from(document.querySelectorAll('[tabindex]')).filter(element=>visible(element) && Number(element.getAttribute('tabindex'))>0).map(element=>({tag:element.tagName,tabindex:Number(element.getAttribute('tabindex')),label:accessibleName(element).slice(0,80)}));
		const fixedPortalRoot=element=>{
			const portal=element.closest(portalSelector);
			return portal&&getComputedStyle(portal).position==='fixed' ? portal : null;
		};
		const visiblyEscapes=(candidate,region,regionPortal,regionRect)=>{
			if(candidate.matches('.dp-panel-sr-only,[hidden],[aria-hidden="true"]')){return 0;}
			const candidatePortal=fixedPortalRoot(candidate);
			if(candidatePortal&&candidatePortal!==regionPortal){return 0;}
			const style=getComputedStyle(candidate);
			if(style.position==='fixed'&&!candidatePortal){return 0;}
			const rect=candidate.getBoundingClientRect();
			const escape=Math.max(0,rect.right-regionRect.right,regionRect.left-rect.left);
			if(escape<=2){return 0;}
			for(let parent=candidate.parentElement;parent&&parent!==region;parent=parent.parentElement){
				const overflow=getComputedStyle(parent).overflowX;
				if(overflow==='hidden'||overflow==='clip'||overflow==='auto'||overflow==='scroll'){return 0;}
			}
			return escape;
		};
		const regionSelector='main.dp-panel,.dp-panel-main-region,.dp-panel-table-wrap,.dp-panel-form,.dp-panel-modal,'+portalSelector;
		const regions=Array.from(document.querySelectorAll(regionSelector)).filter(visible).map(element=>{
			const portal=fixedPortalRoot(element);
			const boundary=portal===element ? 'viewport' : 'region';
			const regionRect=boundary==='viewport' ? viewportRect : element.getBoundingClientRect();
			const escapeSources=Array.from(element.querySelectorAll('*')).filter(visible).map(candidate=>({candidate,escape:visiblyEscapes(candidate,element,portal,regionRect)})).filter(item=>item.escape>1).sort((left,right)=>right.escape-left.escape).slice(0,10);
			const overflow=Math.max(0,...escapeSources.map(item=>item.escape));
			return {class:String(element.className).slice(0,160),boundary,clientWidth:element.clientWidth,scrollWidth:element.scrollWidth,rawOverflow:Math.max(0,element.scrollWidth-element.clientWidth),overflow:Math.round(overflow),escapeSources:escapeSources.map(item=>({tag:item.candidate.tagName,class:String(item.candidate.className).slice(0,140),label:accessibleName(item.candidate).slice(0,80),escape:Math.round(item.escape)}))};
		}).filter(region=>region.overflow>1);
		const overflowElements=Array.from(new Set(document.querySelectorAll('.dp-panel *,'+portalSelector+','+portalSelector.split(',').map(selector=>selector+' *').join(','))));
		const explicitScrollSelector='[data-dp-panel-overflow-policy="scroll-x"]';
		const positionedOverlaySelector='.dp-panel-action-menu,.dp-panel-row-more-menu,.dp-panel-column-picker>form,.dp-panel-saved-view-menu>div,.dp-panel-horizontal-group>div';
		const overflowSources=overflowElements.filter(visible).map(element=>{
			const style=getComputedStyle(element);
			const rect=element.getBoundingClientRect();
			const portal=fixedPortalRoot(element);
			const boundary=portal===element ? 'viewport' : 'parent';
			const parentRect=boundary==='viewport' ? viewportRect : element.parentElement?.getBoundingClientRect();
			const internal=Math.max(0,element.scrollWidth-element.clientWidth);
			const external=parentRect ? Math.max(0,rect.right-parentRect.right,parentRect.left-rect.left) : 0;
			const viewportExternal=Math.max(0,rect.right-viewportWidth,-rect.left);
			const explicitScrollOwner=element.closest(explicitScrollSelector);
			const explicitScrollStyle=explicitScrollOwner ? getComputedStyle(explicitScrollOwner) : null;
			const explicitScrollReady=explicitScrollStyle!==null && (explicitScrollStyle.overflowX==='auto'||explicitScrollStyle.overflowX==='scroll');
			const textEllipsis=(style.overflowX==='hidden'||style.overflowX==='clip')&&style.textOverflow==='ellipsis'&&style.whiteSpace==='nowrap';
			const positionedChild=(style.position==='absolute'||style.position==='fixed')&&viewportExternal<=1;
			const positionedOverlay=positionedChild&&element.matches(positionedOverlaySelector);
			const nativeValueScroller=element.matches('input:not([type="hidden"]),select,textarea')&&external<=1&&viewportExternal<=1;
			const containedByScroll=explicitScrollReady&&explicitScrollOwner!==element;
			const ownsScroll=explicitScrollReady&&explicitScrollOwner===element;
			const policies=[];
			const reason=explicitScrollOwner?.getAttribute('data-dp-panel-overflow-reason')||null;
			if(ownsScroll){policies.push('explicit-scroll-x'+(reason ? ':'+reason : ''));}
			if(containedByScroll){policies.push('contained-by-scroll-x'+(reason ? ':'+reason : ''));}
			if(textEllipsis){policies.push('text-ellipsis');}
			if(positionedOverlay){policies.push('positioned-overlay');}
			else if(positionedChild){policies.push('positioned-child');}
			if(nativeValueScroller){policies.push('native-value-scroll');}
			const blockingInternal=internal>1&&!ownsScroll&&!textEllipsis&&!nativeValueScroller ? internal : 0;
			const blockingExternal=external>1&&!containedByScroll&&!positionedChild ? external : 0;
			const blockingViewportExternal=viewportExternal>1&&!containedByScroll ? viewportExternal : 0;
			const blocking=Math.max(blockingInternal,blockingExternal,blockingViewportExternal);
			return {tag:element.tagName,class:String(element.className).slice(0,140),label:accessibleName(element).slice(0,80),display:style.display,boundary,internal:Math.round(internal),external:Math.round(external),viewportExternal:Math.round(viewportExternal),blocking:Math.round(blocking),allowed:blocking<=1,policies:policies.length>0 ? policies : ['none'],left:Math.round(rect.left),right:Math.round(rect.right),width:Math.round(rect.width),parentWidth:Math.round(parentRect?.width || 0)};
		}).filter(item=>item.internal>1 || item.external>1 || item.viewportExternal>1).sort((left,right)=>Math.max(right.blocking,right.internal,right.external,right.viewportExternal)-Math.max(left.blocking,left.internal,left.external,left.viewportExternal));
		const blockingOverflowSources=overflowSources.filter(item=>item.blocking>1);
		const allowedOverflowSources=overflowSources.filter(item=>item.blocking<=1);
		return {
			title:document.title,
			viewport:{width:viewportWidth,height:viewportHeight},
			preferences:{themeMode:document.body?.dataset.dpThemeMode||document.documentElement.dataset.dpThemeMode||null,direction:getComputedStyle(document.documentElement).direction,reducedMotion:matchMedia('(prefers-reduced-motion: reduce)').matches,forcedColors:matchMedia('(forced-colors: active)').matches,deviceScaleFactor:devicePixelRatio,containerWidth:Math.round(document.querySelector('main.dp-panel')?.getBoundingClientRect().width||0)},
			document:{clientWidth:root.clientWidth,scrollWidth:root.scrollWidth,scrollHeight:root.scrollHeight,scrollY:Math.round(window.scrollY),overflow:Math.max(0,root.scrollWidth-root.clientWidth)},
			regions,
			overflowSources:overflowSources.slice(0,40),
			blockingOverflowSources:blockingOverflowSources.slice(0,40),
			allowedOverflowSources:allowedOverflowSources.slice(0,40),
			overflowSummary:{visible:overflowSources.length,blocking:blockingOverflowSources.length,allowed:allowedOverflowSources.length},
			accessibility:{duplicateIds,unnamedButtons,imagesMissingAlt,unlabeledControls,positiveTabindex},
			undersizedTargets,
			counts:{buttons:document.querySelectorAll('button').length,links:document.querySelectorAll('a[href]').length,inputs:controls.length,headings:document.querySelectorAll('h1,h2,h3').length},
		};
	});
}

function auditFailures(audit){
	const failures=[];
	if(audit.document.overflow>1){
		failures.push('Document overflows viewport by '+audit.document.overflow+'px.');
	}
	if(audit.regions.length>0){
		failures.push(audit.regions.length+' visible regions overflow horizontally.');
	}
	if((audit.overflowSummary?.blocking||0)>0){
		failures.push(audit.overflowSummary.blocking+' visible internal overflow sources require an explicit policy.');
	}
	for(const [name,issues] of Object.entries(audit.accessibility)){
		if(issues.length>0){
			failures.push(issues.length+' accessibility findings for '+name+'.');
		}
	}
	if(audit.undersizedTargets.length>0){
		failures.push(audit.undersizedTargets.length+' visible controls are smaller than 44px.');
	}
	return failures;
}

function latestCapability(applications,name){
	for(let index=applications.length-1;index>=0;index--){
		const capability=applications[index].capabilities?.[name];
		if(capability&&capability.requested!==null){return capability;}
	}
	return null;
}

function environmentFailures(audit,environment,applications){
	const failures=[];
	const preferences=audit.preferences||{};
	if(environment.themeMode!==null&&preferences.themeMode!==environment.themeMode){failures.push('Theme mode resolved to '+String(preferences.themeMode)+' instead of '+environment.themeMode+'.');}
	if(environment.direction!==null&&preferences.direction!==environment.direction){failures.push('Direction resolved to '+String(preferences.direction)+' instead of '+environment.direction+'.');}
	if(environment.zoom!==null&&Math.abs(Number(preferences.deviceScaleFactor)-environment.zoom)>0.01){failures.push('Zoom device scale resolved to '+String(preferences.deviceScaleFactor)+' instead of '+environment.zoom+'.');}
	const reducedCapability=latestCapability(applications,'reducedMotion');
	if(reducedCapability?.supported===true){
		const expected=environment.reducedMotion==='reduce';
		if(Boolean(preferences.reducedMotion)!==expected){failures.push('Reduced motion did not resolve to '+environment.reducedMotion+'.');}
	}
	const forcedCapability=latestCapability(applications,'forcedColors');
	if(forcedCapability?.supported===true){
		const expected=environment.forcedColors==='active';
		if(Boolean(preferences.forcedColors)!==expected){failures.push('Forced colors did not resolve to '+environment.forcedColors+'.');}
	}
	if(environment.containerWidth!==null){
		const maximum=Math.min(Number(audit.viewport?.width)||environment.viewport.width,environment.containerWidth);
		const actual=Number(preferences.containerWidth)||0;
		if(actual<1||actual>maximum+1){failures.push('Container width resolved to '+actual+'px instead of at most '+maximum+'px.');}
	}
	return failures;
}

function capabilityWarnings(applications){
	const warnings=[];
	for(const application of applications){
		for(const [name,capability] of Object.entries(application.capabilities||{})){
			if(capability.requested!==null&&capability.supported===false){warnings.push(name+'='+capability.requested+' could not be emulated by this browser'+(capability.error?' ('+capability.error+')':'.'));}
		}
	}
	return Array.from(new Set(warnings));
}

function summarizeCapability(results,name){
	const entries=results.flatMap(result=>(result.environment?.applications||[]).map(application=>application.capabilities?.[name]).filter(capability=>capability&&capability.requested!==null));
	const statuses=entries.map(entry=>entry.supported);
	const status=entries.length===0?'not_requested':statuses.every(value=>value===true)?'supported':statuses.every(value=>value===false)?'unsupported':'partial';
	return{status,attempts:entries.length,requested:Array.from(new Set(entries.map(entry=>entry.requested))),errors:Array.from(new Set(entries.map(entry=>entry.error).filter(Boolean)))};
}

function matrixReport(environments){return{environmentCount:environments.length,defaultsPreserved:args.viewports.length===0&&args.themeModes.length===0&&args.directions.length===0&&args.zooms.length===0&&args.reducedMotions.length===0&&args.forcedColors.length===0&&args.containerWidths.length===0,requested:{viewports:args.viewports.length>0?Array.from(new Set(args.viewports)):defaultViewportNames,themeModes:args.themeModes,directions:args.directions,zooms:args.zooms,reducedMotions:args.reducedMotions,forcedColors:args.forcedColors,containerWidths:args.containerWidths},environments:environments.map(environmentAxes)};}

async function run(){
	if(args.help){
		printHelp();
		return 0;
	}
	if(!baseUrl){throw new Error('--base-url or DP_PANEL_BASE_URL is required.');}
	ensureDirectory(artifactRoot);
	if(!args.auditOnly){ensureDirectory(baselineRoot);}
	const environments=selectedEnvironments();
	const scenarios=selectedScenarios();
	const plan=executionPlan(environments,scenarios);
	const puppeteer=findPuppeteer();
	const browser=await puppeteer.launch({
		executablePath:findBrowser(),
		headless:!args.headed,
		args:['--no-sandbox','--disable-gpu','--font-render-hinting=none'],
	});
	const comparePage=args.auditOnly?null:await browser.newPage();
	const results=[];
	try{
		for(const {environment,scenario} of plan.runnable){
			const viewport=environment.viewport;
			let context=null;
			let page=null;
			const environmentApplications=[];
			let applicationResultOffset=0;
			const consoleErrors=[];
			let consoleResultOffset=0;
			const navigationFailures=[];
			let navigationFailureOffset=0;
			const baseId=artifactBaseId(scenario,environment);
			let url=baseUrl+scenario.path;
			let status=0;
			const started=Date.now();
			const onConsole=message=>{
				if(message.type()==='error'){
					const location=message.location();
					consoleErrors.push(message.text()+(location.url ? ' @ '+location.url : ''));
				}
			};
			const onPageError=error=>consoleErrors.push(String(error && error.message || error));
			let listenersAttached=false;
			try{
				const targetUrl=new URL(baseUrl+scenario.path);
				if(args.theme){targetUrl.searchParams.set('panel_theme',args.theme);}
				url=targetUrl.toString();
				context=await browser.createBrowserContext();
				page=await context.newPage();
				page.on('console',onConsole);
				page.on('pageerror',onPageError);
				listenersAttached=true;
				await page.evaluateOnNewDocument(()=>{try{localStorage.clear();sessionStorage.clear();}catch(error){}});
				await page.setViewport(effectiveViewport(environment));
				await installEnvironment(page,environment);
				if(hasEnvironmentOverrides(environment)){environmentApplications.push(await applyEnvironment(page,environment,'before_navigation'));}
				const navigate=async()=>{
					const response=await page.goto(url,{waitUntil:'domcontentloaded',timeout:30000});
					if(hasEnvironmentOverrides(environment)){environmentApplications.push(await applyEnvironment(page,environment,'after_navigation'));}
					status=response ? response.status() : 0;
					if(status>=400 || status===0){
						navigationFailures.push('HTTP status '+status+'.');
					}
				};
				await navigate();
				await page.waitForSelector('main.dp-panel',{visible:true,timeout:15000});
				await page.waitForFunction(()=>document.querySelector('main.dp-panel')?.hasAttribute('data-dp-panel-a11y-status'),{timeout:10000});
				await page.evaluate(async()=>{
					if(document.fonts && document.fonts.ready){
						await Promise.race([document.fonts.ready,new Promise(resolve=>setTimeout(resolve,1500))]);
					}
				});
				await settlePage(page);
				await prepareScenario(page,scenario);
				if(hasEnvironmentOverrides(environment)){environmentApplications.push(await applyEnvironment(page,environment,'after_prepare'));}
				await settlePage(page);
				const samples=await scenarioScrollSamples(page,scenario);
				if(samples.length===0){throw new Error('Scenario '+scenario.id+' has no compatible scroll samples for this request.');}
				let sampleIndex=0;
				for(const sample of samples){
					if(sampleIndex>0){
						await navigate();
						await page.waitForSelector('main.dp-panel',{visible:true,timeout:15000});
						await page.waitForFunction(()=>document.querySelector('main.dp-panel')?.hasAttribute('data-dp-panel-a11y-status'),{timeout:10000});
						await settlePage(page);
						await prepareScenario(page,scenario);
						if(hasEnvironmentOverrides(environment)){environmentApplications.push(await applyEnvironment(page,environment,'after_prepare'));}
						await settlePage(page);
					}
					sampleIndex++;
					const id=baseId+(sample.name==='top' ? '' : '__'+sample.name);
					await page.evaluate(offset=>window.scrollTo({top:offset,left:0,behavior:'instant'}),sample.offset);
					if(hasEnvironmentOverrides(environment)){environmentApplications.push(await applyEnvironment(page,environment,'sample_'+sample.name));}
					await settlePage(page,360,3500);
					const audit=await auditPage(page);
					const resultApplications=environmentApplications.slice(applicationResultOffset);
					applicationResultOffset=environmentApplications.length;
					const resultConsoleErrors=consoleErrors.slice(consoleResultOffset);
					consoleResultOffset=consoleErrors.length;
					const failures=navigationFailures.slice(navigationFailureOffset);
					navigationFailureOffset=navigationFailures.length;
					failures.push(...auditFailures(audit));
					failures.push(...environmentFailures(audit,environment,resultApplications));
					let comparison={skipped:args.auditOnly,reason:args.auditOnly?'audit_only':null};
					let screenshotRecord=null;
					if(!args.auditOnly){
						const screenshot=await page.screenshot({type:'png',fullPage:false});
						const artifactPath=path.join(artifactRoot,id+'.png');
						const baselinePath=path.join(baselineRoot,id+'.png');
						fs.writeFileSync(artifactPath,screenshot);
						if(args.updateBaselines){
							fs.writeFileSync(baselinePath,screenshot);
							comparison={updated:true,ratio:0,changedPixels:0,dimensionsMatch:true};
						}
						else if(fs.existsSync(baselinePath)){
							comparison=await comparePngs(comparePage,fs.readFileSync(baselinePath),screenshot);
							if(!comparison.dimensionsMatch){
								failures.push('Screenshot dimensions differ from baseline.');
							}
							else if(comparison.ratio>args.diffThreshold){
								failures.push('Screenshot changed by '+(comparison.ratio*100).toFixed(3)+'%.');
							}
						}
						else {
							comparison={missing:true,ratio:null,changedPixels:null,dimensionsMatch:null};
							failures.push('Screenshot baseline is missing.');
						}
						screenshotRecord={artifact:artifactPath,baseline:baselinePath,sha256:sha256(screenshot)};
					}
					if(resultConsoleErrors.length>0){
						failures.push(resultConsoleErrors.length+' browser console errors.');
					}
					const environmentRecord={axes:environmentAxes(environment),applications:resultApplications};environmentRecord.warnings=capabilityWarnings(environmentRecord.applications);
					results.push({id,scenario:scenario.id,viewport:viewport.name,scrollSample:sample.name,url,status,ok:failures.length===0,durationMs:Date.now()-started,failures,warnings:environmentRecord.warnings,consoleErrors:resultConsoleErrors,audit,environment:environmentRecord,comparison,screenshot:screenshotRecord});
				}
			}
			catch(runError){
				const error=String(runError && runError.stack || runError);
				const resultApplications=environmentApplications.slice(applicationResultOffset);
				const resultConsoleErrors=consoleErrors.slice(consoleResultOffset);
				const failures=[...navigationFailures.slice(navigationFailureOffset),error];
				const environmentRecord={axes:environmentAxes(environment),applications:resultApplications};environmentRecord.warnings=capabilityWarnings(environmentRecord.applications);
				results.push({id:baseId,scenario:scenario.id,viewport:viewport.name,scrollSample:'top',url,status,ok:false,durationMs:Date.now()-started,failures,warnings:environmentRecord.warnings,consoleErrors:resultConsoleErrors,error,environment:environmentRecord});
			}
			finally {
				if(page!==null){
					if(listenersAttached){
						page.off('console',onConsole);
						page.off('pageerror',onPageError);
					}
				}
				if(context!==null){
					await context.close();
				}
				else if(page!==null){
					await page.close();
				}
			}
		}
	}
	finally {
		await browser.close();
	}
	const failed=results.filter(result=>!result.ok);
	const warningCount=results.reduce((count,result)=>count+(result.warnings?.length||0),0);
	const report={
		type:'dataphyre_panel_visual_regression',
		generatedAt:new Date().toISOString(),
		baseUrl,
		mode:args.auditOnly?'audit_only':'visual_regression',
		auditOnly:args.auditOnly,
		theme:args.theme,
		updateBaselines:args.updateBaselines,
		diffThreshold:args.diffThreshold,
		isolation:{scope:'browser_context_per_scenario_environment',cookies:'isolated',storage:'isolated'},
		matrix:{...matrixReport(environments),scenarios:scenarios.map(scenario=>scenario.id),runnableCombinations:plan.runnable.length,skippedCombinations:plan.skipped},
		capabilities:{reducedMotion:summarizeCapability(results,'reducedMotion'),forcedColors:summarizeCapability(results,'forcedColors')},
		summary:{total:results.length,passed:results.length-failed.length,failed:failed.length,warnings:warningCount},
		results,
	};
	writeJson(reportPath,report);
	console.log(JSON.stringify({report:reportPath,...report.summary}));
	return failed.length===0 || args.reportOnly ? 0 : 1;
}

run().then(code=>{
	process.exitCode=code;
}).catch(error=>{
	const payload={type:'dataphyre_panel_visual_regression',generatedAt:new Date().toISOString(),baseUrl,ok:false,error:String(error && error.stack || error)};
	writeJson(reportPath,payload);
	console.error(JSON.stringify(payload));
	process.exitCode=2;
});
