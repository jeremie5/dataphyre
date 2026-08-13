#!/usr/bin/env node
'use strict';

const fs=require('fs');
const path=require('path');
const {interactionRegistry}=require('./panel_browser_scenarios.js');

const cwd=process.cwd();
const args=parseArgs(process.argv.slice(2));
const baseUrl=(args.baseUrl || process.env.DP_PANEL_BASE_URL || '').replace(/\/$/, '');
const reportPath=path.resolve(cwd,args.report || '.tmp/panel-interaction-regression/report.json');

function parseArgs(argv){
	const parsed={baseUrl:'',browser:'',headed:false,help:false,report:'',list:false,whySelected:false,scenarios:[],tags:[],changedPaths:[]};
	for(let index=0;index<argv.length;index++){
		const argument=argv[index];
		const value=()=>{
			const inline=argument.indexOf('=');
			if(inline!==-1){return argument.slice(inline+1);}
			index++;
			return argv[index] || '';
		};
		if(argument==='--help' || argument==='-h'){parsed.help=true;}
		else if(argument==='--headed'){parsed.headed=true;}
		else if(argument==='--list'){parsed.list=true;}
		else if(argument==='--why-selected'){parsed.whySelected=true;}
		else if(argument==='--base-url' || argument.startsWith('--base-url=')){parsed.baseUrl=value();}
		else if(argument==='--browser' || argument.startsWith('--browser=')){parsed.browser=value();}
		else if(argument==='--report' || argument.startsWith('--report=')){parsed.report=value();}
		else if(argument==='--scenario' || argument.startsWith('--scenario=')){parsed.scenarios.push(value());}
		else if(argument==='--tag' || argument.startsWith('--tag=')){parsed.tags.push(value());}
		else if(argument==='--changed-path' || argument.startsWith('--changed-path=')){parsed.changedPaths.push(value());}
		else {throw new Error('Unknown argument: '+argument);}
	}
	return parsed;
}

function printHelp(){
	console.log([
		'Dataphyre Panel interaction regression runner',
		'',
		'Usage: node panel_interaction_regression.js [options]',
		'',
		'  --base-url <url>   Mounted Panel showroom URL.',
		'  --browser <path>   Chrome or Edge executable.',
		'  --report <path>    JSON report output path.',
		'  --headed           Show the controlled browser.',
		'  --list             List registered scenarios without launching a browser.',
		'  --scenario <text>  Select a scenario name/contract; repeatable, regex accepted.',
		'  --tag <tag>         Require a registered scenario tag; repeatable.',
		'  --changed-path <p> Select scenarios watching a changed source path; repeatable.',
		'  --why-selected      Print causal selection reasons before execution.',
	].join('\n'));
}

function findPuppeteer(){
	for(const candidate of [
		path.join(cwd,'.tmp','puppeteer-check','node_modules','puppeteer-core'),
		path.join(cwd,'.tmp','node_modules','puppeteer-core'),
		path.join(__dirname,'node_modules','puppeteer-core'),
	]){
		if(fs.existsSync(candidate)){return require(candidate);}
	}
	throw new Error('Unable to find puppeteer-core.');
}

function findBrowser(){
	const candidates=[
		args.browser,
		process.env.CHROME_PATH,
		'C:/Program Files/Google/Chrome/Application/chrome.exe',
		'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
		path.join(process.env.LOCALAPPDATA || '','Google/Chrome/Application/chrome.exe'),
		path.join(process.env.PROGRAMFILES || '','Microsoft/Edge/Application/msedge.exe'),
	].filter(Boolean);
	const browser=candidates.find(candidate=>fs.existsSync(candidate));
	if(!browser){throw new Error('Unable to find Chrome or Edge.');}
	return browser;
}

function ensureDirectory(directory){fs.mkdirSync(directory,{recursive:true});}
function assert(condition,message){if(!condition){throw new Error(message);}}
function delay(milliseconds){return new Promise(resolve=>setTimeout(resolve,milliseconds));}
async function pressTab(page,reverse=false){
	if(!reverse){await page.keyboard.press('Tab');return;}
	await page.keyboard.down('Shift');
	try{await page.keyboard.press('Tab');}finally{await page.keyboard.up('Shift');}
}

function escapeFixtureHtml(value){
	return String(value===undefined||value===null?'':value).replace(/[&<>"']/g,character=>({
		'&':'&amp;',
		'<':'&lt;',
		'>':'&gt;',
		'"':'&quot;',
		"'":'&#039;',
	})[character]);
}

function modalFixtureUrl(name){
	return new URL('/__dataphyre_panel_modal_regression__/'+String(name||'fixture').replace(/[^a-z0-9_-]/gi,'-'),baseUrl).toString();
}

function modalFixtureDocument(content,title='Modal regression fixture'){
	return '<!doctype html><html><head><meta charset="utf-8"><title>'+escapeFixtureHtml(title)+'</title></head><body>'+content+'</body></html>';
}

function modalFixtureTrigger(id,route,heading,options={}){
	const stack=options.stack||'push';
	const exit=options.exit||'auto';
	const tag=options.tag==='button'?'button':'a';
	const destination=modalFixtureUrl(route);
	const target=tag==='a'?' href="'+escapeFixtureHtml(destination)+'"':' data-dp-panel-row-url="'+escapeFixtureHtml(destination)+'"';
	const method=options.method&&String(options.method).toUpperCase()!=='GET'?' data-dp-panel-action-method="'+escapeFixtureHtml(String(options.method).toUpperCase())+'"':'';
	const confirmation=options.confirm?' data-confirm="'+escapeFixtureHtml(options.confirm)+'"':'';
	const fields=options.fields===false?'':' data-dp-panel-action-has-fields="1"';
	return '<'+tag+' id="'+escapeFixtureHtml(id)+'"'+target+' data-dp-panel-action-modal="1"'+fields+method+confirmation+' data-dp-panel-action-heading="'+escapeFixtureHtml(heading)+'" data-dp-panel-modal-stack="'+escapeFixtureHtml(stack)+'" data-dp-panel-modal-stack-explicit="1" data-dp-panel-modal-exit="'+escapeFixtureHtml(exit)+'"'+(tag==='button'?' type="button"':'')+'>'+escapeFixtureHtml(heading)+'</'+tag+'>';
}

async function appendModalFixtureTrigger(page,definition){
	await page.evaluate(fixture=>{
		const trigger=document.createElement('a');
		trigger.id=fixture.id;
		trigger.href=fixture.href;
		trigger.textContent=fixture.heading;
		trigger.dataset.dpPanelActionModal='1';
		trigger.dataset.dpPanelActionHasFields='1';
		trigger.dataset.dpPanelActionHeading=fixture.heading;
		trigger.dataset.dpPanelModalStack=fixture.stack||'replace';
		trigger.dataset.dpPanelModalStackExplicit='1';
		trigger.dataset.dpPanelModalExit=fixture.exit||'auto';
		document.querySelector('main.dp-panel').appendChild(trigger);
	},definition);
}

async function waitForModalContent(page,selector,timeout=10000){
	await page.waitForFunction(target=>{
		const root=document.querySelector('.dp-panel-modal-root');
		return !!(root&&!root.hidden&&!root.classList.contains('dp-panel-modal-busy')&&root.querySelector(target));
	},{timeout},selector);
}

async function installRequestFixtures(page,resolver){
	const errors=[];
	await page.setRequestInterception(true);
	const listener=request=>{
		let fixture=null;
		try{fixture=resolver(request);}
		catch(error){errors.push(String(error&&error.stack||error));}
		if(!fixture){
			request.continue().catch(error=>errors.push(String(error&&error.message||error)));
			return;
		}
		const response={...fixture};
		const delayMs=Number(response.delayMs||0);
		const waitFor=response.waitFor;
		const continueRequest=response.continueRequest===true;
		delete response.delayMs;
		delete response.waitFor;
		delete response.continueRequest;
		const respond=()=>{
			const operation=continueRequest ? request.continue() : request.respond(response);
			operation.catch(error=>errors.push(String(error&&error.message||error)));
		};
		if(waitFor&&typeof waitFor.then==='function'){
			Promise.resolve(waitFor).then(respond,error=>errors.push(String(error&&error.message||error)));
		}
		else if(delayMs>0){setTimeout(respond,delayMs);}
		else{respond();}
	};
	page.on('request',listener);
	return {
		errors,
		listener,
		async dispose(){
			page.off('request',listener);
			await page.setRequestInterception(false);
		},
	};
}

async function applyTheme(page,preset='glass',mode='dark'){
	const cookieUrl=new URL(baseUrl).origin+'/';
	await page.setCookie(
		{name:'dataphyre_panel_theme_preset',value:preset,url:cookieUrl,path:'/'},
		{name:'dataphyre_panel_theme_mode',value:mode,url:cookieUrl,path:'/'},
	);
	await page.evaluateOnNewDocument((themePreset,themeMode)=>{
		try{
			localStorage.setItem('dataphyre_panel_theme_preset',themePreset);
			localStorage.setItem('dataphyre_panel_theme_mode',themeMode);
		}
		catch(error){}
	},preset,mode);
}

function colorChannels(value){
	const source=String(value||'');
	const channels=source.match(/[\d.]+/g);
	if(!channels||channels.length<3){return null;}
	const parsed=channels.slice(0,3).map(Number);
	return source.startsWith('color(srgb ') ? parsed.map(channel=>channel*255) : parsed;
}

function relativeLuminance(value){
	const channels=colorChannels(value);
	if(!channels){return null;}
	const linear=channels.map(channel=>{
		const normalized=Math.max(0,Math.min(255,channel))/255;
		return normalized<=0.04045 ? normalized/12.92 : Math.pow((normalized+0.055)/1.055,2.4);
	});
	return 0.2126*linear[0]+0.7152*linear[1]+0.0722*linear[2];
}

function contrastRatio(foreground,background){
	const foregroundLuminance=relativeLuminance(foreground);
	const backgroundLuminance=relativeLuminance(background);
	if(foregroundLuminance===null||backgroundLuminance===null){return 0;}
	return (Math.max(foregroundLuminance,backgroundLuminance)+0.05)/(Math.min(foregroundLuminance,backgroundLuminance)+0.05);
}

async function open(page,url,viewport){
	await page.setViewport({...viewport,deviceScaleFactor:1});
	const response=await page.goto(url,{waitUntil:'domcontentloaded',timeout:30000});
	assert(response && response.status()<400,'HTTP status '+(response ? response.status() : 0)+' for '+url+'.');
	await page.waitForSelector('main.dp-panel',{visible:true,timeout:15000});
	await page.waitForFunction(()=>{
		const runtime=window.DataphyrePanel?.runtimeController;
		const controllers=runtime?.controllers||{};
		const capabilities=String(document.querySelector('main.dp-panel')?.dataset.dpPanelAssets||'').split(/\s+/).filter(Boolean);
		const required=['foundation','command','state_table','navigation','ajax','validation_upload','accessibility','theme'];
		if(capabilities.includes('form')){required.push('fields');}
		if(capabilities.includes('editor')){required.push('editor');}
		if(capabilities.includes('modal')){required.push('modal');}
		if(capabilities.includes('board')){required.push('board');}
		return document.readyState==='complete'&&runtime?.disposed===false&&required.every(name=>!!controllers[name]);
	},{timeout:15000});
	await page.evaluate(async()=>{
		if(document.fonts && document.fonts.ready){
			await Promise.race([document.fonts.ready,new Promise(resolve=>setTimeout(resolve,1500))]);
		}
		await new Promise(resolve=>setTimeout(resolve,120));
	});
}

async function run(){
	if(args.help){printHelp();return 0;}
	if(args.list){
		console.log(JSON.stringify({type:'dataphyre_panel_browser_scenarios',scenarios:interactionRegistry.list()},null,2));
		return 0;
	}
	if(!baseUrl){throw new Error('--base-url or DP_PANEL_BASE_URL is required.');}
	const selection=interactionRegistry.select({names:args.scenarios,tags:args.tags,changedPaths:args.changedPaths});
	if(selection.selected.length===0){
		throw new Error('No registered Panel browser scenarios matched the supplied selectors.');
	}
	if(args.whySelected){
		console.log(JSON.stringify({type:'dataphyre_panel_browser_selection',selected:selection.selected.map(entry=>({id:entry.id,name:entry.name,reasons:entry.reasons})),unknownChanged:selection.unknownChanged},null,2));
	}
	const selectedByName=new Map(selection.selected.map(entry=>[entry.name,entry]));
	const puppeteer=findPuppeteer();
	const browser=await puppeteer.launch({
		executablePath:findBrowser(),
		headless:!args.headed,
		args:['--no-sandbox','--disable-gpu','--font-render-hinting=none'],
	});
	const results=[];
	const encountered=new Set();
	const probe=async(name,callback,options={})=>{
		const scenario=interactionRegistry.entry(name);
		encountered.add(name);
		if(!selectedByName.has(name)){return;}
		const started=Date.now();
		const context=await browser.createBrowserContext();
		const page=await context.newPage();
		const activeConsoleErrors=[];
		const network=[];
		const onConsole=message=>{if(message.type()==='error'){const location=message.location();activeConsoleErrors.push(message.text()+(location.url ? ' @ '+location.url : ''));}};
		const onPageError=error=>activeConsoleErrors.push(String(error && error.message || error));
		const onResponse=response=>{
			if(network.length>=250){network.shift();}
			network.push({type:'response',method:response.request().method(),status:response.status(),resourceType:response.request().resourceType(),url:response.url()});
		};
		const onRequestFailed=request=>{
			if(network.length>=250){network.shift();}
			network.push({type:'request_failed',method:request.method(),resourceType:request.resourceType(),url:request.url(),failure:request.failure()?.errorText||'unknown'});
		};
		page.on('console',onConsole);
		page.on('pageerror',onPageError);
		page.on('response',onResponse);
		page.on('requestfailed',onRequestFailed);
		try{
			const detail=await callback(page);
			const allowed=Array.isArray(options.allowConsoleErrors)?options.allowConsoleErrors:[];
			const unexpectedConsoleErrors=activeConsoleErrors.filter(error=>!allowed.some(fragment=>error.includes(fragment)));
			assert(unexpectedConsoleErrors.length===0,unexpectedConsoleErrors.length+' browser console errors.');
			results.push({id:scenario.id,name,contract:scenario.contract,tags:[...scenario.tags],watches:[...scenario.watches],selectionReasons:[...selectedByName.get(name).reasons],passed:true,durationMs:Date.now()-started,detail,consoleErrors:[],ignoredConsoleErrors:activeConsoleErrors.filter(error=>!unexpectedConsoleErrors.includes(error))});
		}
		catch(error){
			const artifactDirectory=path.join(path.dirname(reportPath),'interaction-artifacts',scenario.id);
			ensureDirectory(artifactDirectory);
			const screenshotPath=path.join(artifactDirectory,'failure.png');
			const domPath=path.join(artifactDirectory,'failure.html');
			const networkPath=path.join(artifactDirectory,'network.json');
			const artifactErrors=[];
			try{await page.screenshot({path:screenshotPath,fullPage:true});}catch(artifactError){artifactErrors.push('screenshot: '+String(artifactError&&artifactError.message||artifactError));}
			try{fs.writeFileSync(domPath,await page.content());}catch(artifactError){artifactErrors.push('dom: '+String(artifactError&&artifactError.message||artifactError));}
			try{fs.writeFileSync(networkPath,JSON.stringify(network,null,2)+'\n');}catch(artifactError){artifactErrors.push('network: '+String(artifactError&&artifactError.message||artifactError));}
			results.push({
				id:scenario.id,name,contract:scenario.contract,tags:[...scenario.tags],watches:[...scenario.watches],selectionReasons:[...selectedByName.get(name).reasons],
				passed:false,durationMs:Date.now()-started,error:String(error && error.stack || error),consoleErrors:[...activeConsoleErrors],
				replay:['node',path.relative(cwd,__filename),'--base-url',baseUrl,'--scenario',scenario.contract,'--report',path.relative(cwd,reportPath)].join(' '),
				artifacts:{screenshot:screenshotPath,dom:domPath,network:networkPath,errors:artifactErrors},
			});
		}
		finally{
			page.off('console',onConsole);
			page.off('pageerror',onPageError);
			page.off('response',onResponse);
			page.off('requestfailed',onRequestFailed);
			await context.close();
		}
	};
	try{
		await probe('mobile drawer opens, traps shell state, and closes with Escape',async page=>{
			await applyTheme(page,'glass','dark');
			await open(page,baseUrl+'?panel_theme=glass',{width:390,height:844});
			const toggle=await page.$('.dp-panel-mobile-nav-toggle');
			assert(toggle,'Mobile navigation toggle is missing.');
			assert(await toggle.evaluate(element=>element.getAttribute('aria-expanded')==='false'),'Drawer toggle does not start collapsed.');
			await toggle.click();
			await page.waitForFunction(()=>document.querySelector('main.dp-panel')?.classList.contains('dp-panel-mobile-nav-open'));
			await page.waitForFunction(()=>document.activeElement?.classList.contains('dp-panel-mobile-nav-dismiss'));
			await page.waitForFunction(()=>{
				const sidebar=document.querySelector('.dp-panel-sidebar');
				if(!sidebar){return false;}
				const rect=sidebar.getBoundingClientRect();
				return rect.width>0&&rect.right>0&&rect.left<window.innerWidth;
			});
			const opened=await page.evaluate(()=>{
				const panel=document.querySelector('main.dp-panel');
				const sidebar=document.querySelector('.dp-panel-sidebar');
				const region=document.querySelector('.dp-panel-main-region');
				const rect=sidebar.getBoundingClientRect();
				return {expanded:document.querySelector('.dp-panel-mobile-nav-toggle')?.getAttribute('aria-expanded'),open:panel.classList.contains('dp-panel-mobile-nav-open'),left:Math.round(rect.left),right:Math.round(rect.right),focusInside:sidebar.contains(document.activeElement),regionInert:region.hasAttribute('inert'),regionHidden:region.getAttribute('aria-hidden')};
			});
			assert(opened.expanded==='true' && opened.open && opened.right>0,'Drawer did not become visibly open.');
			assert(opened.focusInside&&opened.regionInert&&opened.regionHidden==='true','Drawer did not move focus inside and inert the underlying page.');
			for(let index=0;index<24;index++){
				await pressTab(page,index%3===0);
				assert(await page.evaluate(()=>document.querySelector('.dp-panel-sidebar')?.contains(document.activeElement)),'Keyboard focus escaped the open mobile drawer.');
			}
			const dismiss=await page.$('.dp-panel-mobile-nav-dismiss');
			assert(dismiss,'Drawer close affordance is missing.');
			const composition=await page.evaluate(()=>{
				const top=document.querySelector('.dp-panel-sidebar-top');
				const group=document.querySelector('.dp-panel-sidebar-group');
				const close=document.querySelector('.dp-panel-mobile-nav-dismiss');
				const sidebar=document.querySelector('.dp-panel-sidebar');
				const nav=document.querySelector('.dp-panel-sidebar-nav');
				const sidebarRect=sidebar.getBoundingClientRect();
				const groups=Array.from(document.querySelectorAll('.dp-panel-sidebar-group'));
				const outOfBounds=Array.from(document.querySelectorAll('.dp-panel-sidebar-link,.dp-panel-sidebar-submenu>summary')).filter(element=>{
					const rect=element.getBoundingClientRect();
					return rect.width>0&&rect.height>0&&(rect.left<sidebarRect.left-1||rect.right>sidebarRect.right+1);
				}).length;
				return {
					topHeight:Math.round(top.getBoundingClientRect().height),
					groupBackground:getComputedStyle(group).backgroundColor,
					closeDisplay:getComputedStyle(close).display,
					documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
					navOverflow:nav ? Math.max(0,nav.scrollWidth-nav.clientWidth) : -1,
					outOfBounds,
					groupDecorations:groups.map(element=>({
						after:getComputedStyle(element,'::after').content,
						before:getComputedStyle(element,'::before').content,
						background:getComputedStyle(element).backgroundColor,
					})),
				};
			});
			assert(composition.topHeight<=96,'Drawer header stretches instead of sizing to its content.');
			assert(composition.groupBackground==='rgba(0, 0, 0, 0)','Drawer sections render as competing cards.');
			assert(composition.closeDisplay!=='none','Drawer close affordance is not visible.');
			assert(composition.documentOverflow===0,'Glass mobile drawer overflows the viewport by '+composition.documentOverflow+'px.');
			assert(composition.navOverflow===0,'Glass mobile navigation has an internal horizontal overflow of '+composition.navOverflow+'px.');
			assert(composition.outOfBounds===0,composition.outOfBounds+' mobile navigation links escape the drawer.');
			assert(composition.groupDecorations.every(item=>item.after==='none'&&item.before==='none'),'Mobile navigation groups retain decorative separator pseudo-elements.');
			assert(composition.groupDecorations.every(item=>item.background==='rgba(0, 0, 0, 0)'),'Mobile navigation groups are not visually continuous.');
			await dismiss.click();
			await page.waitForFunction(()=>!document.querySelector('main.dp-panel')?.classList.contains('dp-panel-mobile-nav-open'));
			await page.waitForFunction(()=>document.activeElement?.classList.contains('dp-panel-mobile-nav-toggle')&&!document.querySelector('.dp-panel-main-region')?.hasAttribute('inert'));
			await page.waitForFunction(()=>{
				const sidebar=document.querySelector('.dp-panel-sidebar');
				if(!sidebar){return false;}
				const rect=sidebar.getBoundingClientRect();
				return rect.right<=1||rect.left>=window.innerWidth-1;
			});
			await toggle.click();
			await page.waitForFunction(()=>document.querySelector('main.dp-panel')?.classList.contains('dp-panel-mobile-nav-open'));
			await page.keyboard.press('Escape');
			await page.waitForFunction(()=>!document.querySelector('main.dp-panel')?.classList.contains('dp-panel-mobile-nav-open'));
			await page.waitForFunction(()=>document.activeElement?.classList.contains('dp-panel-mobile-nav-toggle'));
			return {...opened,...composition};
		});

		await probe('fixed-fit brick collections collapse to one safe mobile track',async page=>{
			await applyTheme(page,'glass','dark');
			await open(page,baseUrl+'?panel_theme=glass',{width:390,height:844});
			const brick=await page.evaluate(()=>{
				const panel=document.querySelector('main.dp-panel');
				const host=document.createElement('div');
				host.className='dp-panel-table-views';
				host.dataset.dpDisplay='brick';
				host.dataset.dpFit='fixed';
				host.style.setProperty('--dp-collection-columns','4');
				for(let index=0;index<4;index++){
					const button=document.createElement('button');
					button.type='button';
					button.textContent='Responsive brick option '+(index+1);
					host.appendChild(button);
				}
				panel.querySelector('.dp-panel-main-region').appendChild(host);
				const style=getComputedStyle(host);
				const children=Array.from(host.children).map(element=>element.getBoundingClientRect());
				return {columns:style.gridTemplateColumns,childWidths:children.map(rect=>rect.width),childHeights:children.map(rect=>rect.height),regionOverflow:Math.max(0,host.scrollWidth-host.clientWidth),documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth)};
			});
			assert(brick.columns.trim().split(/\s+/).length===1,'Fixed-fit brick retained multiple mobile columns: '+brick.columns+'.');
			assert(brick.childWidths.every(width=>width>=300)&&brick.childHeights.every(height=>height>=44),'Mobile brick controls are squeezed below usable geometry.');
			assert(brick.regionOverflow===0&&brick.documentOverflow===0,'Fixed-fit brick creates mobile overflow: '+JSON.stringify(brick)+'.');
			const embeddedNavigation=[];
			for(const direction of ['ltr','rtl']){
				await open(page,baseUrl+'?panel_theme=glass',{width:1440,height:1000});
				await page.evaluate(currentDirection=>{
					document.documentElement.dir=currentDirection;
					const style=document.createElement('style');
					style.textContent='main.dp-panel{inline-size:min(100%,220px)!important;max-inline-size:220px!important;margin-inline:auto!important}';
					document.head.appendChild(style);
				},direction);
				await delay(120);
				const navigation=await page.evaluate(()=>{
					const region=document.querySelector('.dp-panel-main-region');
					const groups=Array.from(document.querySelectorAll('.dp-panel-nav-group')).map(group=>{
						const grid=group.querySelector('.dp-panel-grid');
						const header=group.querySelector(':scope>header');
						const cards=Array.from(group.querySelectorAll('.dp-panel-nav-card'));
						const groupRect=group.getBoundingClientRect();
						const gridRect=grid.getBoundingClientRect();
						const headerRect=header.getBoundingClientRect();
						return {
							tracks:getComputedStyle(grid).gridTemplateColumns.trim().split(/\s+/).filter(Boolean),
							groupOverflow:Math.max(0,group.scrollWidth-group.clientWidth),
							gridOverflow:Math.max(0,grid.scrollWidth-grid.clientWidth),
							headerBounded:headerRect.left>=groupRect.left-1&&headerRect.right<=groupRect.right+1,
							cards:cards.map(card=>{const rect=card.getBoundingClientRect();return {width:rect.width,hostWidth:gridRect.width,overflow:Math.max(0,card.scrollWidth-card.clientWidth),bounded:rect.left>=gridRect.left-1&&rect.right<=gridRect.right+1};}),
						};
					});
					return {panelWidth:Math.round(document.querySelector('main.dp-panel')?.getBoundingClientRect().width||0),regionOverflow:region ? Math.max(0,region.scrollWidth-region.clientWidth) : -1,documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),groups};
				});
				assert(navigation.panelWidth===220&&navigation.groups.length>=2,'Embedded dashboard navigation fixture is incomplete: '+JSON.stringify(navigation)+'.');
				assert(navigation.regionOverflow===0&&navigation.documentOverflow===0,'Embedded dashboard navigation overflows in '+direction+': '+JSON.stringify(navigation)+'.');
				assert(navigation.groups.every(group=>group.tracks.length===1&&group.groupOverflow===0&&group.gridOverflow===0&&group.headerBounded),'Embedded dashboard navigation does not collapse to one bounded track in '+direction+': '+JSON.stringify(navigation.groups)+'.');
				assert(navigation.groups.every(group=>group.cards.every(card=>card.bounded&&card.overflow===0&&Math.abs(card.width-card.hostWidth)<=1)),'Embedded dashboard navigation cards do not fill their safe track in '+direction+': '+JSON.stringify(navigation.groups)+'.');
				embeddedNavigation.push({direction,...navigation});
			}
			const embeddedNarrow=[];
			const narrowFixtures=[
				{name:'index',path:'/orders?group=status&view=review&page=1&panel_theme=glass',required:['.dp-panel-commandbar-primary','.dp-panel-per-page label','.dp-panel-table-group-row button','.dp-panel-summary-value']},
				{name:'board',path:'/orders?view=board&panel_theme=glass',required:['.dp-panel-commandbar-primary','.dp-panel-commandbar-actions','.dp-panel-table-group-row button']},
				{name:'show',path:'/orders/4?panel_theme=glass',required:['.dp-panel-show-field-copyable','.dp-panel-show-field-copyable>strong']},
				{name:'import',path:'/orders/import?panel_theme=glass',required:['.dp-panel-field:has(>input[type="checkbox"])>span']},
				{name:'state',path:'?resource=state_lab&fixture=loading_error&panel_theme=glass',required:['.dp-panel-custom-page','.dp-panel-custom-page>*']},
			];
			for(const direction of ['ltr','rtl']){
				for(const fixture of narrowFixtures){
					await open(page,baseUrl+fixture.path,{width:1440,height:1000});
					const wideSummaries=fixture.name==='index' ? await page.evaluate(()=>Array.from(document.querySelectorAll('.dp-panel-summary')).map(element=>{
						const style=getComputedStyle(element);
						const children=Array.from(element.children).map(child=>child.getBoundingClientRect());
						return {display:style.display,autoFlow:style.gridAutoFlow,vertical:children.every((rect,index)=>index===0||rect.top>=children[index-1].bottom-1)};
					})) : [];
					await page.evaluate(currentDirection=>{
						document.documentElement.dir=currentDirection;
						const style=document.createElement('style');
						style.textContent='main.dp-panel{inline-size:min(100%,160px)!important;max-inline-size:160px!important;margin-inline:auto!important}';
						document.head.appendChild(style);
					},direction);
					await delay(100);
						const audit=await page.evaluate(required=>{
						const selectors=[
							'.dp-panel-commandbar-top',
							'.dp-panel-commandbar-bottom',
							'.dp-panel-commandbar-primary',
							'.dp-panel-commandbar-view',
							'.dp-panel-commandbar-actions',
							'.dp-panel-per-page',
							'.dp-panel-per-page label',
							'.dp-panel-table-group-row button',
							'.dp-panel-summary-value',
							'.dp-panel-show-field-copyable',
							'.dp-panel-show-field-copyable>strong',
							'.dp-panel-field:has(>input[type="checkbox"])>span',
							'.dp-panel-custom-page',
							'.dp-panel-custom-page>*',
						];
						const geometry=selectors.flatMap(selector=>Array.from(document.querySelectorAll(selector)).map(element=>{
							const rect=element.getBoundingClientRect();
							const parentRect=element.parentElement?.getBoundingClientRect()||rect;
							return {
								selector,
								internal:Math.max(0,element.scrollWidth-element.clientWidth),
								external:Math.max(0,parentRect.left-rect.left,rect.right-parentRect.right),
								tracks:getComputedStyle(element).display==='grid' ? getComputedStyle(element).gridTemplateColumns.trim().split(/\s+/).filter(Boolean).length : null,
							};
						}));
						const present=Object.fromEntries(required.map(selector=>[selector,document.querySelectorAll(selector).length]));
						const summaries=Array.from(document.querySelectorAll('.dp-panel-summary')).map(element=>{
							const style=getComputedStyle(element);
							const children=Array.from(element.children).map(child=>child.getBoundingClientRect());
							return {
								display:style.display,
								autoFlow:style.gridAutoFlow,
								vertical:children.every((rect,index)=>index===0||rect.top>=children[index-1].bottom-1),
							};
						});
						return {
							panelWidth:Math.round(document.querySelector('main.dp-panel')?.getBoundingClientRect().width||0),
							documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
							regionOverflow:Math.max(0,(document.querySelector('.dp-panel-main-region')?.scrollWidth||0)-(document.querySelector('.dp-panel-main-region')?.clientWidth||0)),
							present,
							geometry,
							summaries,
						};
					},fixture.required);
					assert(audit.panelWidth===160,'Ultra-narrow embedded '+fixture.name+' fixture is not constrained to 160px: '+JSON.stringify(audit)+'.');
					assert(Object.values(audit.present).every(count=>count>0),'Ultra-narrow embedded '+fixture.name+' fixture is incomplete: '+JSON.stringify(audit.present)+'.');
					assert(audit.documentOverflow===0&&audit.regionOverflow===0,'Ultra-narrow embedded '+fixture.name+' overflows in '+direction+': '+JSON.stringify(audit)+'.');
					assert(audit.geometry.every(item=>item.internal<=1&&item.external<=1),'Ultra-narrow embedded '+fixture.name+' retains fixed geometry in '+direction+': '+JSON.stringify(audit.geometry)+'.');
					assert(wideSummaries.every(item=>item.display==='grid'&&item.autoFlow==='row'&&item.vertical),'Summary card hierarchy collapsed in wide '+fixture.name+' '+direction+': '+JSON.stringify(wideSummaries)+'.');
					assert(audit.summaries.every(item=>item.display==='grid'&&item.autoFlow==='row'&&item.vertical),'Summary card hierarchy collapsed in ultra-narrow embedded '+fixture.name+' '+direction+': '+JSON.stringify(audit.summaries)+'.');
					assert(audit.geometry.filter(item=>item.selector==='.dp-panel-commandbar-primary'||item.selector==='.dp-panel-commandbar-actions'||item.selector==='.dp-panel-custom-page'||item.selector==='.dp-panel-show-field-copyable').every(item=>item.tracks===1),'Ultra-narrow embedded '+fixture.name+' does not collapse structural grids in '+direction+': '+JSON.stringify(audit.geometry)+'.');
					embeddedNarrow.push({direction,fixture:fixture.name,wideSummaries,...audit});
				}
			}
			return {brick,embeddedNavigation,embeddedNarrow};
		});

		await probe('row masonry fills incomplete rows without reordering or overflow',async page=>{
			const measure=async(width,height,containerWidth=null)=>{
				await open(page,baseUrl,{width,height});
				return page.evaluate(({currentWidth,containerWidth})=>{
					const panel=document.querySelector('main.dp-panel');
					const region=panel.querySelector('.dp-panel-main-region');
					if(containerWidth!==null){
						region.style.width=containerWidth+'px';
						region.style.maxWidth=containerWidth+'px';
					}
					const host=document.createElement('div');
					host.dataset.dpDisplay='masonry';
					host.dataset.dpMasonry='rows';
					host.dataset.dpFit='fill';
					host.dataset.dpColumns='configured';
					host.style.setProperty('--dp-collection-min','140px');
					host.style.setProperty('--dp-collection-columns','2');
					host.style.setProperty('--dp-collection-basis','calc(50% - 6px)');
					for(let index=0;index<3;index++){
						const button=document.createElement('button');
						button.type='button';
						button.dataset.order=String(index+1);
						button.textContent='Masonry option '+(index+1);
						host.appendChild(button);
					}
					region.appendChild(host);
					const hostRect=host.getBoundingClientRect();
					const style=getComputedStyle(host);
					const gap=Number(style.gap.replace('px',''))||0;
					const children=Array.from(host.children).map(element=>{const rect=element.getBoundingClientRect();return {order:element.dataset.order,left:rect.left,top:rect.top,width:rect.width,right:rect.right};});
					const rows={};children.forEach(child=>{const key=String(Math.round(child.top));(rows[key]??=[]).push(child);});
					return {width:currentWidth,containerWidth,activeColumns:style.getPropertyValue('--dp-collection-columns-active').trim(),activeBasis:style.getPropertyValue('--dp-collection-basis-active').trim(),hostWidth:hostRect.width,gap,children,rows:Object.values(rows),overflow:Math.max(0,host.scrollWidth-host.clientWidth),documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth)};
				},{currentWidth:width,containerWidth});
			};
			const desktop=await measure(900,720);
			assert(desktop.rows.length===2&&desktop.rows[0].length===2&&desktop.rows[1].length===1,'Desktop row masonry did not form a 2+1 layout: '+JSON.stringify(desktop.rows)+'.');
			assert(Math.abs(desktop.rows[1][0].width-desktop.hostWidth)<=1,'Incomplete desktop row did not stretch to the host width.');
			assert(desktop.children.map(item=>item.order).join(',')==='1,2,3','Row masonry changed DOM order.');
			assert(desktop.overflow===0&&desktop.documentOverflow===0,'Desktop row masonry overflowed: '+JSON.stringify(desktop)+'.');
			const mobile=await measure(390,720);
			assert(mobile.rows.length===3&&mobile.rows.every(row=>row.length===1),'Mobile row masonry did not collapse to one item per row.');
			assert(mobile.rows.every(row=>Math.abs(row[0].width-mobile.hostWidth)<=1),'Mobile row masonry did not fill each row.');
			assert(mobile.overflow===0&&mobile.documentOverflow===0,'Mobile row masonry overflowed: '+JSON.stringify(mobile)+'.');
			const constrained=await measure(900,720,390);
			assert(constrained.activeColumns==='1'&&constrained.activeBasis==='100%','Desktop viewport did not honor the narrow Panel container breakpoint: '+JSON.stringify(constrained)+'.');
			assert(constrained.rows.length===3&&constrained.rows.every(row=>row.length===1),'Narrow Panel container did not collapse row masonry independently of viewport width.');
			assert(constrained.rows.every(row=>Math.abs(row[0].width-constrained.hostWidth)<=1)&&constrained.overflow===0&&constrained.documentOverflow===0,'Narrow Panel container masonry does not fill safely: '+JSON.stringify(constrained)+'.');
			const measureItemControls=async containerWidth=>{
				await open(page,baseUrl+'?resource=feature_showcase',{width:1800,height:900});
				return page.evaluate(width=>{
					const region=document.querySelector('main.dp-panel .dp-panel-main-region');
					if(width!==null){region.style.width=width+'px';region.style.maxWidth=width+'px';}
					const itemStyle=element=>{
						element.dataset.dpItemResponsive='1';
						element.dataset.dpItemResponsiveTiers='sm md lg xl 2xl';
						element.style.setProperty('--dp-item-grow','1');
						for(const breakpoint of ['md','lg','xl','2xl']){element.style.setProperty('--dp-item-grow-'+breakpoint,'4');}
						element.style.setProperty('--dp-item-shrink','1');
						for(const breakpoint of ['md','lg','xl','2xl']){element.style.setProperty('--dp-item-shrink-'+breakpoint,'0');}
						element.style.setProperty('--dp-item-order','3');
						for(const breakpoint of ['md','lg','xl','2xl']){element.style.setProperty('--dp-item-order-'+breakpoint,'-2');}
						element.style.setProperty('--dp-item-fill-grid','span var(--dp-item-span-active)');
						element.style.setProperty('--dp-item-fill-grow','var(--dp-item-grow-active)');
						for(const breakpoint of ['md','lg','xl','2xl']){
							element.style.setProperty('--dp-item-fill-grid-'+breakpoint,'1/-1');
							element.style.setProperty('--dp-item-fill-grow-'+breakpoint,'999');
						}
					};
					const masonry=document.createElement('div');
					masonry.dataset.dpDisplay='masonry';
					masonry.dataset.dpMasonry='rows';
					masonry.dataset.dpFit='fill';
					masonry.dataset.dpColumns='configured';
					masonry.style.setProperty('--dp-collection-min','140px');
					masonry.style.setProperty('--dp-collection-columns','2');
					masonry.style.setProperty('--dp-collection-basis','calc(50% - 6px)');
					const sentinel=document.createElement('span');
					sentinel.dataset.dpItemBreak='responsive';
					sentinel.dataset.dpItemLayout='1';
					sentinel.dataset.dpItemResponsive='1';
					sentinel.dataset.dpItemResponsiveTiers='sm md lg xl 2xl';
					sentinel.setAttribute('aria-hidden','true');
					sentinel.style.setProperty('--dp-item-grow','0');
					sentinel.style.setProperty('--dp-item-shrink','0');
					sentinel.style.setProperty('--dp-item-basis','100%');
					sentinel.style.setProperty('--dp-item-break-display','none');
					for(const breakpoint of ['md','lg','xl','2xl']){sentinel.style.setProperty('--dp-item-break-display-'+breakpoint,'block');}
					const responsive=document.createElement('button');
					responsive.type='button';
					responsive.dataset.dpItemLayout='1';
					responsive.dataset.dpItemFillRemainder='responsive';
					responsive.dataset.domOrder='responsive';
					responsive.textContent='Responsive item';
					itemStyle(responsive);
					const plain=document.createElement('button');
					plain.type='button';
					plain.dataset.domOrder='plain';
					plain.textContent='Plain item';
					masonry.append(sentinel,responsive,plain);
					const grid=document.createElement('div');
					grid.dataset.dpDisplay='grid';
					grid.dataset.dpFit='fixed';
					grid.dataset.dpColumns='configured';
					grid.style.setProperty('--dp-collection-columns','2');
					const gridItem=document.createElement('button');
					gridItem.type='button';
					gridItem.dataset.dpItemLayout='1';
					gridItem.dataset.dpItemFillRemainder='responsive';
					gridItem.textContent='Responsive grid item';
					itemStyle(gridItem);
					const gridPlain=document.createElement('button');
					gridPlain.type='button';
					gridPlain.textContent='Plain grid item';
					grid.append(gridItem,gridPlain);
					region.append(masonry,grid);
					const itemComputed=getComputedStyle(responsive);
					const gridComputed=getComputedStyle(gridItem);
					const itemRect=responsive.getBoundingClientRect();
					return {
						containerWidth:width,
						grow:itemComputed.flexGrow,
						shrink:itemComputed.flexShrink,
						order:itemComputed.order,
						breakDisplay:getComputedStyle(sentinel).display,
						gridColumn:gridComputed.gridColumn,
						domOrder:Array.from(masonry.querySelectorAll('[data-dom-order]')).map(element=>element.dataset.domOrder),
						itemWidth:itemRect.width,
						itemHeight:itemRect.height,
						hostWidth:masonry.getBoundingClientRect().width,
						overflow:Math.max(0,masonry.scrollWidth-masonry.clientWidth,grid.scrollWidth-grid.clientWidth),
						documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
					};
				},containerWidth);
			};
			const responsiveWide=await measureItemControls(null);
			assert(responsiveWide.grow==='999'&&responsiveWide.shrink==='0'&&responsiveWide.order==='-2','Wide responsive item controls did not activate: '+JSON.stringify(responsiveWide)+'.');
			assert(responsiveWide.breakDisplay==='block'&&responsiveWide.gridColumn==='1 / -1','Wide responsive break/fill policies did not activate: '+JSON.stringify(responsiveWide)+'.');
			assert(responsiveWide.domOrder.join(',')==='responsive,plain','Responsive CSS order mutated DOM order.');
			const responsiveConstrained=await measureItemControls(390);
			assert(responsiveConstrained.grow==='1'&&responsiveConstrained.shrink==='1'&&responsiveConstrained.order==='3','Narrow container did not restore base item controls: '+JSON.stringify(responsiveConstrained)+'.');
			assert(responsiveConstrained.breakDisplay==='none'&&responsiveConstrained.gridColumn!=='1 / -1','Narrow container retained wide break/fill policies: '+JSON.stringify(responsiveConstrained)+'.');
			assert(responsiveConstrained.domOrder.join(',')==='responsive,plain'&&responsiveConstrained.itemHeight>=44&&Math.abs(responsiveConstrained.itemWidth-responsiveConstrained.hostWidth)<=2,'Narrow responsive item geometry or semantics are unsafe: '+JSON.stringify(responsiveConstrained)+'.');
			assert(responsiveWide.overflow===0&&responsiveWide.documentOverflow===0&&responsiveConstrained.overflow===0&&responsiveConstrained.documentOverflow===0,'Responsive item controls create horizontal overflow.');
			return {desktop,mobile,constrained,responsiveWide,responsiveConstrained};
		});

		await probe('flat minima widget seams are owned by the collection boundary',async page=>{
			await applyTheme(page,'flat_minima','dark');
			await open(page,baseUrl+'?resource=feature_showcase&panel_theme=flat_minima',{width:1440,height:1000});
			const seams=await page.evaluate(()=>{
				const host=document.querySelector('.dp-panel-main-region>.dp-panel-widgets[data-dp-display]');
				if(!host){return null;}
				const hostRect=host.getBoundingClientRect();
				const hostStyle=getComputedStyle(host);
				const widgets=Array.from(host.children).map(element=>{
					const rect=element.getBoundingClientRect();
					const style=getComputedStyle(element);
					return {top:rect.top,left:rect.left,right:rect.right,bottom:rect.bottom,borders:[style.borderTopWidth,style.borderRightWidth,style.borderBottomWidth,style.borderLeftWidth],margins:[style.marginTop,style.marginRight,style.marginBottom,style.marginLeft]};
				});
				return {gap:hostStyle.gap,border:hostStyle.borderTopWidth,radius:parseFloat(hostStyle.borderTopLeftRadius),overflowStyle:hostStyle.overflow,background:hostStyle.backgroundColor,host:{top:hostRect.top,left:hostRect.left,right:hostRect.right,bottom:hostRect.bottom},widgets,overflow:Math.max(0,host.scrollWidth-host.clientWidth),documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth)};
			});
			assert(seams&&seams.widgets.length>=6,'Flat Minima widget fixture is incomplete.');
			assert(seams.gap==='1px'&&seams.border==='1px','Flat Minima collection does not own a single-pixel seam: '+JSON.stringify(seams)+'.');
			assert(seams.radius>0&&seams.overflowStyle==='hidden','Flat Minima collection does not clip square widget surfaces to its rounded perimeter: '+JSON.stringify(seams)+'.');
			assert(seams.widgets.every(item=>item.borders.every(value=>value==='0px')&&item.margins.every(value=>value==='0px')),'Flat Minima widgets still own overlapping borders or negative margins.');
			assert(seams.widgets.every(item=>item.top>=seams.host.top+1&&item.left>=seams.host.left+1&&item.right<=seams.host.right-1&&item.bottom<=seams.host.bottom-1),'A Flat Minima widget overlaps the collection perimeter.');
			assert(seams.overflow===0&&seams.documentOverflow===0,'Flat Minima seams create horizontal overflow.');
			await open(page,baseUrl,{width:1440,height:1000});
			const runtimeIsland=await page.evaluate(()=>{
				const island=document.querySelector('.dp-panel-main-region>.dp-panel-widgets>.dp-panel-widget-island');
				const surface=island?.querySelector(':scope>.dp-panel-widget-fallback>.dp-panel-widget');
				const fallback=island?.querySelector(':scope>.dp-panel-widget-fallback');
				const actions=island?.querySelector(':scope>.dp-panel-widget-actions');
				const buttons=Array.from(actions?.querySelectorAll('.dp-panel-widget-action')||[]);
				if(!island||!surface||!fallback||!actions){return null;}
				const islandRect=island.getBoundingClientRect();
				const surfaceRect=surface.getBoundingClientRect();
				const fallbackRect=fallback.getBoundingClientRect();
				const actionsRect=actions.getBoundingClientRect();
				const style=getComputedStyle(surface);
				const actionStyle=getComputedStyle(actions);
				return {enhanced:island.getAttribute('data-dp-widget-enhanced'),radius:style.borderRadius,borders:[style.borderTopWidth,style.borderRightWidth,style.borderBottomWidth,style.borderLeftWidth],shadow:style.boxShadow,island:{left:islandRect.left,right:islandRect.right},surface:{left:surfaceRect.left,right:surfaceRect.right,height:surfaceRect.height,background:style.backgroundColor},actions:{top:actionsRect.top,bottom:actionsRect.bottom,left:actionsRect.left,right:actionsRect.right,background:actionStyle.backgroundColor,borderTop:actionStyle.borderTopWidth,paddingLeft:parseFloat(actionStyle.paddingLeft),buttonHeights:buttons.map(button=>button.getBoundingClientRect().height)},fallbackBottom:fallbackRect.bottom,overflow:Math.max(0,island.scrollWidth-island.clientWidth),documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth)};
			});
			assert(runtimeIsland&&runtimeIsland.enhanced==='1','Interactive widget island did not hydrate for the Flat Minima boundary probe.');
			assert(runtimeIsland.radius==='0px'&&runtimeIsland.borders.every(value=>value==='0px')&&runtimeIsland.shadow==='none','Interactive widget retained a nested card surface inside the flush collection: '+JSON.stringify(runtimeIsland)+'.');
			assert(Math.abs(runtimeIsland.surface.left-runtimeIsland.island.left)<=1&&Math.abs(runtimeIsland.surface.right-runtimeIsland.island.right)<=1&&runtimeIsland.surface.height>=104&&runtimeIsland.overflow===0&&runtimeIsland.documentOverflow===0,'Interactive widget does not fill its masonry cell safely: '+JSON.stringify(runtimeIsland)+'.');
			assert(Math.abs(runtimeIsland.actions.top-runtimeIsland.fallbackBottom)<=1&&runtimeIsland.actions.background===runtimeIsland.surface.background&&runtimeIsland.actions.borderTop==='1px','Interactive widget actions are not attached to the widget surface: '+JSON.stringify(runtimeIsland)+'.');
			assert(runtimeIsland.actions.paddingLeft===16&&Math.abs(runtimeIsland.actions.left-runtimeIsland.island.left)<=1&&Math.abs(runtimeIsland.actions.right-runtimeIsland.island.right)<=1&&runtimeIsland.actions.buttonHeights.length===2&&runtimeIsland.actions.buttonHeights.every(height=>height>=44&&height<=48),'Interactive widget action rail is misaligned or stretches its controls: '+JSON.stringify(runtimeIsland)+'.');
			return {seams,runtimeIsland};
		});

		await probe('masonry toolbar stretches mixed links and form-wrapped controls',async page=>{
			await applyTheme(page,'flat_minima','dark');
			const measure=async(width,height)=>{
				await open(page,baseUrl+'?resource=feature_showcase&panel_theme=flat_minima',{width,height});
				return page.evaluate(()=>{
					const host=document.querySelector('[data-dp-panel-collection="toolbar"][data-dp-masonry="rows"]');
					if(!host){return null;}
					const cells=Array.from(host.children).map((wrapper,index)=>{
						const surface=wrapper.matches('a,button')?wrapper:wrapper.querySelector('a,button');
						const wrapperRect=wrapper.getBoundingClientRect();
						const surfaceRect=surface?.getBoundingClientRect();
						return {index,tag:wrapper.tagName,surface:surface?.tagName||'',top:Math.round(wrapperRect.top),wrapperWidth:wrapperRect.width,wrapperHeight:wrapperRect.height,surfaceWidth:surfaceRect?.width||0,surfaceHeight:surfaceRect?.height||0};
					});
					const rows={};cells.forEach(cell=>(rows[cell.top]??=[]).push(cell));
					const style=getComputedStyle(host);
					return {activeColumns:style.getPropertyValue('--dp-collection-columns-active').trim(),activeBasis:style.getPropertyValue('--dp-collection-basis-active').trim(),hostWidth:host.getBoundingClientRect().width,cells,rows:Object.values(rows),overflow:Math.max(0,host.scrollWidth-host.clientWidth),documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth)};
				});
			};
			const wide=await measure(1800,1000);
			assert(wide&&wide.activeColumns==='5'&&wide.rows.length===2&&wide.rows[0].length===5&&wide.rows[1].length===2,'Wide toolbar did not form its configured 5+2 masonry rows: '+JSON.stringify(wide)+'.');
			assert(wide.cells.every(item=>Math.abs(item.wrapperWidth-item.surfaceWidth)<=2&&Math.abs(item.wrapperHeight-item.surfaceHeight)<=2),'A wide toolbar surface does not fill its masonry cell: '+JSON.stringify(wide.cells)+'.');
			assert(wide.overflow===0&&wide.documentOverflow===0,'Wide masonry toolbar overflows.');
			const desktop=await measure(1440,1000);
			assert(desktop&&desktop.cells.length===7&&desktop.cells.some(item=>item.tag==='FORM')&&desktop.cells.some(item=>item.tag==='A'),'Mixed toolbar fixture is incomplete: '+JSON.stringify(desktop)+'.');
			assert(desktop.activeColumns==='3'&&desktop.rows.length===3&&desktop.rows[0].length===3&&desktop.rows[1].length===3&&desktop.rows[2].length===1,'Container-aware desktop toolbar did not form its configured 3+3+1 masonry rows: '+JSON.stringify(desktop)+'.');
			assert(Math.abs(desktop.rows[2][0].wrapperWidth-desktop.hostWidth)<=2,'Incomplete desktop toolbar row did not stretch to the host width.');
			assert(desktop.cells.every(item=>Math.abs(item.wrapperWidth-item.surfaceWidth)<=2&&Math.abs(item.wrapperHeight-item.surfaceHeight)<=2),'A toolbar surface does not fill its masonry cell: '+JSON.stringify(desktop.cells)+'.');
			assert(desktop.overflow===0&&desktop.documentOverflow===0,'Desktop masonry toolbar overflows.');
			const mobile=await measure(390,844);
			assert(mobile&&mobile.rows.length===mobile.cells.length&&mobile.rows.every(row=>row.length===1),'Mobile toolbar did not collapse to one control per row.');
			assert(mobile.cells.every(item=>Math.abs(item.wrapperWidth-item.surfaceWidth)<=2&&Math.abs(item.wrapperHeight-item.surfaceHeight)<=2),'A mobile toolbar surface does not fill its cell.');
			assert(mobile.overflow===0&&mobile.documentOverflow===0,'Mobile masonry toolbar overflows.');
			return {wide,desktop,mobile};
		});

		await probe('edit modal aligns nested sections and contains required dirty markers',async page=>{
			await applyTheme(page,'glass','dark');
			await open(page,baseUrl+'/orders?group=status&view=active&page=1&panel_theme=glass',{width:1024,height:800});
			const editSelector='tr[data-order-status] [data-dp-panel-action-name="edit"][data-dp-panel-action-modal="1"]';
			assert(await page.$(editSelector),'Expected a visible order edit trigger.');
			await page.click(editSelector);
			await page.waitForSelector('.dp-panel-modal-root:not([hidden]) input[name="email"]',{visible:true,timeout:15000});
			await page.$eval('.dp-panel-modal-root:not([hidden]) input[name="email"]',input=>{
				input.value='changed16@example.test';
				input.dispatchEvent(new Event('input',{bubbles:true}));
				input.dispatchEvent(new Event('change',{bubbles:true}));
			});
			const geometry=await page.evaluate(()=>{
				const root=document.querySelector('.dp-panel-modal-root:not([hidden])');
				const byLabel=name=>Array.from(root.querySelectorAll('.dp-panel-field')).find(field=>field.querySelector('.dp-panel-field-label-text')?.textContent.trim()===name);
				const rect=element=>{const box=element.getBoundingClientRect();return {left:box.left,right:box.right,width:box.width,top:box.top};};
				const panel=root.querySelector('.dp-panel-tab-panel:not([hidden])');
				const section=panel.querySelector(':scope>.dp-panel-form-section');
				const email=byLabel('Email');
				const label=email.querySelector('.dp-panel-field-label');
				const labelText=label.querySelector('.dp-panel-field-label-text');
				const shell=email.querySelector('.dp-panel-input-shell');
				const lastButton=shell.querySelector('.dp-panel-input-adornments:last-child .dp-panel-input-button:last-child');
				const market=byLabel('Market').querySelector('.dp-panel-choice-list');
				const marketItems=Array.from(market.children).map(rect);
				const marketInputs=Array.from(market.querySelectorAll('input[type="radio"]'));
				marketInputs[0].checked=true;
				const checkedStyle=getComputedStyle(marketInputs[0].closest('.dp-panel-choice'));
				const uncheckedStyle=getComputedStyle(marketInputs[1].closest('.dp-panel-choice'));
				marketInputs[0].focus();
				const focusedStyle=getComputedStyle(marketInputs[0].closest('.dp-panel-choice'));
				marketInputs[2].disabled=true;
				const disabledStyle=getComputedStyle(marketInputs[2].closest('.dp-panel-choice'));
				const marketControls=marketInputs.map(input=>{
					const box=input.getBoundingClientRect();
					const style=getComputedStyle(input);
					const target=input.closest('.dp-panel-choice').getBoundingClientRect();
					return {width:box.width,height:box.height,minWidth:style.minWidth,minHeight:style.minHeight,margin:style.margin,flex:style.flex,targetHeight:target.height};
				});
				const choiceStates={checkedBorder:checkedStyle.borderColor,uncheckedBorder:uncheckedStyle.borderColor,checkedBackground:checkedStyle.backgroundColor,uncheckedBackground:uncheckedStyle.backgroundColor,focusOutline:[focusedStyle.outlineStyle,focusedStyle.outlineWidth,focusedStyle.outlineColor],disabledOpacity:disabledStyle.opacity,disabledCursor:disabledStyle.cursor};
				return {
					alignment:{left:Math.abs(rect(panel).left-rect(section).left),right:Math.abs(rect(panel).right-rect(section).right)},
					email:{dirty:email.classList.contains('dp-panel-field-dirty'),shellBefore:getComputedStyle(shell,'::before').content,shellAfter:getComputedStyle(shell,'::after').content,labelBefore:getComputedStyle(label,'::before').content,labelAfter:getComputedStyle(labelText,'::after').content,rightGap:rect(shell).right-rect(lastButton).right},
					market:{display:market.dataset.dpDisplay,masonry:market.dataset.dpMasonry,activeColumns:getComputedStyle(market).getPropertyValue('--dp-collection-columns-active').trim(),rows:new Set(marketItems.map(item=>Math.round(item.top))).size,lastWidth:marketItems.at(-1).width,hostWidth:rect(market).width,overflow:Math.max(0,market.scrollWidth-market.clientWidth),controls:marketControls,states:choiceStates},
					documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
				};
			});
			assert(geometry.alignment.left<=1&&geometry.alignment.right<=1,'Nested modal section gutters do not align: '+JSON.stringify(geometry.alignment)+'.');
			assert(geometry.email.dirty&&geometry.email.labelBefore==='""'&&geometry.email.labelAfter.includes('*'),'Dirty/required state is not owned by the semantic field label: '+JSON.stringify(geometry.email)+'.');
			assert(geometry.email.shellBefore==='none'&&geometry.email.shellAfter==='none','Input shell still renders clipped field markers.');
			assert(geometry.email.rightGap<=1.5,'Final input addon leaves a false right gap of '+geometry.email.rightGap+'px.');
			assert(geometry.market.display==='masonry'&&geometry.market.masonry==='rows'&&geometry.market.activeColumns==='2'&&geometry.market.rows===2,'Market options are not a 2+1 row-masonry layout.');
			assert(Math.abs(geometry.market.lastWidth-geometry.market.hostWidth)<=1&&geometry.market.overflow===0,'Final market option does not fill its incomplete row.');
			assert(geometry.market.controls.length===3&&geometry.market.controls.every(control=>control.width===20&&control.height===20&&control.minWidth==='20px'&&control.minHeight==='20px'&&control.margin==='0px'&&control.flex.startsWith('0 0 20px')&&control.targetHeight>=44),'Radio controls or their label targets escaped normalized geometry: '+JSON.stringify(geometry.market.controls)+'.');
			assert(geometry.market.states.checkedBorder!==geometry.market.states.uncheckedBorder&&geometry.market.states.checkedBackground!==geometry.market.states.uncheckedBackground,'Checked choice does not expose a visible card state: '+JSON.stringify(geometry.market.states)+'.');
			assert(geometry.market.states.focusOutline[0]==='solid'&&geometry.market.states.focusOutline[1]==='2px','Choice focus is not promoted to the full label target: '+JSON.stringify(geometry.market.states.focusOutline)+'.');
			assert(Number(geometry.market.states.disabledOpacity)<0.8&&geometry.market.states.disabledCursor==='not-allowed','Disabled choice is not visually and behaviorally distinct: '+JSON.stringify(geometry.market.states)+'.');
			assert(geometry.documentOverflow===0,'Edit modal presentation overflows the document.');
			return geometry;
		});

		await probe('adaptive form spans and semantic switches prevent intrinsic control clipping',async page=>{
			await applyTheme(page,'glass','dark');
			const measure=async(width,height)=>{
				await open(page,baseUrl+'/orders/create?panel_theme=glass',{width,height});
				const fulfillmentTab=await page.evaluate(()=>{
					const tab=Array.from(document.querySelectorAll('button[role="tab"]')).find(button=>button.textContent.trim().includes('Fulfillment'));
					tab?.click();
					return !!tab;
				});
				assert(fulfillmentTab,'Fulfillment tab is missing from the create fixture.');
				await page.waitForSelector('.dp-panel-tab-panel:not([hidden]) [name="priority_handling"][type="checkbox"]',{timeout:5000});
				return page.evaluate(()=>{
					const panel=document.querySelector('.dp-panel-tab-panel:not([hidden])');
					const fields=['sla_minutes','priority_handling','receipt'].map(name=>panel.querySelector('[data-dp-panel-field-name="'+name+'"]'));
					const switchField=fields[1];
					const switchControl=switchField.querySelector('.dp-panel-switch');
					const switchInput=switchControl.querySelector('input[type="checkbox"]');
					const track=switchControl.querySelector('.dp-panel-switch-track');
					const knob=track.firstElementChild;
					const explicit=panel.querySelector('[data-dp-panel-field-name="internal_note"]');
					const section=panel.querySelector(':scope>.dp-panel-form-section');
					const sectionStyle=getComputedStyle(section);
					const railStyle=getComputedStyle(section,'::before');
					const rect=element=>{const box=element.getBoundingClientRect();return {width:box.width,height:box.height,top:Math.round(box.top)};};
					return {
						fields:fields.map(field=>{
							const control=field.querySelector('input:not([type="hidden"]),.dp-panel-switch');
							return {name:field.dataset.dpPanelFieldName,automatic:field.classList.contains('dp-panel-grid-item-auto'),gridColumn:getComputedStyle(field).gridColumn,...rect(field),overflow:Math.max(0,field.scrollWidth-field.clientWidth,control.scrollWidth-control.clientWidth)};
						}),
						switch:{outerTag:switchField.tagName,controlTag:switchControl.tagName,nestedLabels:switchField.querySelectorAll('label label').length,track:rect(track),knob:rect(knob),inputPosition:getComputedStyle(switchInput).position,inputClip:getComputedStyle(switchInput).clipPath,controlHeight:rect(switchControl).height},
						rail:{content:railStyle.content,top:parseFloat(railStyle.top),bottom:parseFloat(railStyle.bottom),left:parseFloat(railStyle.left),width:parseFloat(railStyle.width),radius:parseFloat(railStyle.borderTopLeftRadius),sectionRadius:parseFloat(sectionStyle.borderTopLeftRadius),sectionOverflow:sectionStyle.overflow},
						explicit:{automatic:explicit.classList.contains('dp-panel-grid-item-auto'),gridColumn:getComputedStyle(explicit).gridColumn},
						documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
					};
				});
			};
			const wide=await measure(1440,920);
			const compact=await measure(1024,800);
			const mobile=await measure(390,844);
			await measure(1440,920);
			await page.click('[data-dp-panel-field-name="priority_handling"] .dp-panel-switch');
			await page.waitForFunction(()=>{
				const knob=document.querySelector('[data-dp-panel-field-name="priority_handling"] .dp-panel-switch-track>span');
				return knob&&new DOMMatrix(getComputedStyle(knob).transform).m41>=17.5;
			},{timeout:1000});
			const checked=await page.evaluate(()=>{
				const control=document.querySelector('[data-dp-panel-field-name="priority_handling"] .dp-panel-switch');
				const input=control.querySelector('input[type="checkbox"]');
				const track=control.querySelector('.dp-panel-switch-track');
				const knob=track.firstElementChild;
				return {checked:input.checked,label:control.querySelector('[data-dp-panel-switch-label]').textContent.trim(),trackBackground:getComputedStyle(track).backgroundColor,knobTransform:getComputedStyle(knob).transform,knobOffset:new DOMMatrix(getComputedStyle(knob).transform).m41,cardBackground:getComputedStyle(control).backgroundColor};
			});
			await page.focus('[data-dp-panel-field-name="priority_handling"] input[type="checkbox"]');
			const focus=await page.$eval('[data-dp-panel-field-name="priority_handling"] .dp-panel-switch',control=>{const style=getComputedStyle(control);return {style:style.outlineStyle,width:style.outlineWidth};});
			assert(wide.fields.every(field=>field.automatic&&field.width>=220&&field.overflow===0&&field.gridColumn==='auto / span 3'),'Wide automatic field spans are clipped or incorrectly placed: '+JSON.stringify(wide.fields)+'.');
			assert(new Set(wide.fields.map(field=>field.top)).size===1,'Wide automatic fields do not share their adaptive row: '+JSON.stringify(wide.fields)+'.');
			assert(wide.switch.outerTag==='DIV'&&wide.switch.controlTag==='LABEL'&&wide.switch.nestedLabels===0,'Boolean field markup contains nested or misplaced labels: '+JSON.stringify(wide.switch)+'.');
			assert(wide.switch.track.width===42&&wide.switch.track.height===24&&wide.switch.knob.width===18&&wide.switch.knob.height===18&&wide.switch.controlHeight>=52,'Switch geometry escaped its normalized contract: '+JSON.stringify(wide.switch)+'.');
			assert(wide.switch.inputPosition==='absolute'&&wide.switch.inputClip==='inset(50%)','Native switch input is not accessibly visually hidden: '+JSON.stringify(wide.switch)+'.');
			for(const result of [wide,compact,mobile]){
				assert(result.rail.content==='""'&&result.rail.top>=10&&result.rail.bottom>=10&&result.rail.left===0&&result.rail.width===4&&result.rail.radius>=result.rail.width/2,'A form-section accent rail escapes its rounded corner tangent: '+JSON.stringify(result.rail)+'.');
				assert(result.rail.sectionRadius>0&&result.rail.sectionOverflow==='visible','The form-section corner fix clips interactive descendants: '+JSON.stringify(result.rail)+'.');
			}
			assert(!wide.explicit.automatic&&wide.explicit.gridColumn==='1 / -1','Explicit full-width placement was overwritten: '+JSON.stringify(wide.explicit)+'.');
			assert(checked.checked&&checked.label==='Enabled'&&checked.knobOffset>=17.5&&focus.style==='solid'&&focus.width==='2px','Switch checked/focus state is not visible or synchronized: '+JSON.stringify({checked,focus})+'.');
			assert(compact.fields.every(field=>field.width>=220&&field.overflow===0)&&compact.documentOverflow===0,'Compact adaptive form clips an intrinsic control: '+JSON.stringify(compact)+'.');
			assert(mobile.fields.every(field=>field.gridColumn==='1 / -1'&&field.overflow===0)&&mobile.documentOverflow===0,'Mobile adaptive form does not collapse cleanly: '+JSON.stringify(mobile)+'.');
			return {wide,checked,focus,compact,mobile};
		});

		await probe('form and infolist grids retain real tracks at intermediate container widths',async page=>{
			const measure=async(pathname,width,expectedTracks,options={})=>{
				await open(page,baseUrl+pathname,{width,height:900});
				const result=await page.evaluate(({pathname,containerWidth,direction})=>{
					if(direction){document.documentElement.dir=direction;}
					if(containerWidth){
						const style=document.createElement('style');
						style.textContent='main.dp-panel{inline-size:'+containerWidth+'px!important;max-inline-size:'+containerWidth+'px!important;margin-inline:auto!important}';
						document.head.append(style);
					}
					const selector=pathname==='/orders/4' ? '.dp-panel-show .dp-panel-form-grid' : (pathname==='/orders/create' ? '.dp-panel-tab-panel:not([hidden]) .dp-panel-form-grid' : '.dp-panel-form-grid');
					const grid=document.querySelector(selector);
					if(!grid){return {missing:true};}
					const style=getComputedStyle(grid);
					const tracks=style.gridTemplateColumns.trim().split(/\s+/).filter(Boolean).map(value=>Number.parseFloat(value));
					const items=Array.from(grid.children).map(element=>{const rect=element.getBoundingClientRect();return {width:rect.width,top:Math.round(rect.top)};});
					const market=document.querySelector('[data-dp-panel-field-name="market"] .dp-panel-choice-list');
					const marketItems=market ? Array.from(market.children).map(element=>{const rect=element.getBoundingClientRect();return {width:rect.width,top:Math.round(rect.top)};}) : [];
					return {
						missing:false,
						tracks,
						rows:new Set(items.map(item=>item.top)).size,
						itemWidths:items.map(item=>item.width),
						market:market ? {rows:new Set(marketItems.map(item=>item.top)).size,lastWidth:marketItems.at(-1)?.width||0,hostWidth:market.getBoundingClientRect().width,overflow:Math.max(0,market.scrollWidth-market.clientWidth)} : null,
						direction:getComputedStyle(document.documentElement).direction,
						documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
					};
				},{pathname,containerWidth:options.containerWidth||0,direction:options.direction||'ltr'});
				assert(!result.missing,'Responsive grid fixture is missing for '+pathname+' at '+width+'px.');
				assert(result.tracks.length===expectedTracks,'Responsive grid exposed '+result.tracks.length+' tracks instead of '+expectedTracks+' for '+pathname+' at '+width+'px: '+JSON.stringify(result)+'.');
				assert(result.tracks.every(track=>Number.isFinite(track)&&track>0)&&result.itemWidths.every(itemWidth=>itemWidth>0),'Responsive grid retained a collapsed or zero-width track for '+pathname+' at '+width+'px: '+JSON.stringify(result)+'.');
				assert(result.documentOverflow===0,'Responsive grid overflows the document for '+pathname+' at '+width+'px.');
				return {pathname,width,expectedTracks,containerWidth:options.containerWidth||null,...result};
			};
			const cases=[];
			cases.push(await measure('/orders/create',1024,6));
			cases.push(await measure('/orders/create',900,6));
			cases.push(await measure('/orders/create',1440,6,{containerWidth:900,direction:'rtl'}));
			cases.push(await measure('/orders/4',1024,3));
			cases.push(await measure('/orders/4',900,3));
			cases.push(await measure('/sellers/create',1024,3));
			cases.push(await measure('/orders/create',768,1));
			cases.push(await measure('/orders/4',390,1));
			for(const item of cases.filter(item=>item.pathname==='/orders/create'&&item.expectedTracks===6)){
				assert(item.market&&item.market.rows===2&&Math.abs(item.market.lastWidth-item.market.hostWidth)<=1&&item.market.overflow===0,'Market row masonry does not fill its incomplete row at '+item.width+'px: '+JSON.stringify(item.market)+'.');
			}
			assert(cases[2].direction==='rtl','Isolated form container did not preserve RTL direction.');
			assert(cases[3].rows===2&&cases[4].rows===2,'Intermediate infolist layout did not retain its compact two-row presentation.');
			assert(cases[5].rows===2,'Intermediate seller form did not retain its compact two-row section.');
			return cases;
		});

		await probe('dark native selects keep a dark popup color contract',async page=>{
			await applyTheme(page,'glass','dark');
			await open(page,baseUrl+'/orders/4?panel_theme=glass',{width:390,height:844});
			await page.click('[data-dp-panel-action-name="assign"][data-dp-panel-action-modal="1"]');
			await page.waitForSelector('.dp-panel-modal-root:not([hidden]) select',{visible:true,timeout:15000});
			const contrast=await page.evaluate(()=>{
				const root=document.querySelector('.dp-panel-modal-root:not([hidden])');
				const select=root&&root.querySelector('select');
				const option=select&&select.options.length ? select.options[0] : null;
				if(!root||!select||!option){return null;}
				const checkboxLabel=document.createElement('label');
				checkboxLabel.className='dp-panel-checkbox';
				checkboxLabel.innerHTML='<span>Modal checkbox</span><input type="checkbox" checked>';
				root.querySelector('.dp-panel-modal-body').appendChild(checkboxLabel);
				const checkbox=checkboxLabel.querySelector('input');
				const backgroundFor=element=>{
					let current=element;
					while(current){
						const background=getComputedStyle(current).backgroundColor;
						if(background&&background!=='transparent'&&background!=='rgba(0, 0, 0, 0)'){return background;}
						current=current.parentElement;
					}
					return 'rgba(0, 0, 0, 0)';
				};
				const selectStyle=getComputedStyle(select);
				const optionStyle=getComputedStyle(option);
				const checkboxStyle=getComputedStyle(checkbox);
				return {
					themeMode:document.body.dataset.dpThemeMode||'',
					themeEffects:document.body.dataset.dpThemeEffects||'',
					selectScheme:selectStyle.colorScheme,
					selectBackground:backgroundFor(select),
					selectOwnBackground:selectStyle.backgroundColor,
					selectColor:selectStyle.color,
					optionBackground:optionStyle.backgroundColor,
					optionColor:optionStyle.color,
					checkboxAppearance:checkboxStyle.appearance||checkboxStyle.webkitAppearance,
					checkboxMark:checkboxStyle.backgroundImage,
				};
			});
			assert(contrast,'Native select fixture is missing.');
			assert(contrast.themeMode==='dark'&&contrast.themeEffects.split(/\s+/).includes('glass'),'Glass dark theme was not applied deterministically.');
			assert(contrast.selectScheme.includes('dark'),'Native select does not advertise a dark color scheme.');
			assert(contrast.selectBackground!=='rgb(255, 255, 255)'&&contrast.selectBackground!=='rgba(0, 0, 0, 0)','Modal select uses a white or transparent background in dark mode: '+contrast.selectBackground+'.');
			assert(contrast.optionBackground!=='rgba(0, 0, 0, 0)','Native option background remains transparent in dark mode.');
			assert(contrastRatio(contrast.selectColor,contrast.selectBackground)>=4.5,'Modal select text contrast is below 4.5:1.');
			assert(contrastRatio(contrast.optionColor,contrast.optionBackground)>=4.5,'Native option text contrast is below 4.5:1.');
			assert(contrast.checkboxAppearance==='none'&&contrast.checkboxMark!=='none','Generated modal checkbox escaped the canonical custom-control contract.');
			return contrast;
		});

		await probe('system-dark native controls preserve popup and selected-option contrast',async page=>{
			await page.emulateMediaFeatures([{name:'prefers-color-scheme',value:'dark'}]);
			await applyTheme(page,'glass','system');
			await open(page,baseUrl+'/orders/import?panel_theme=glass',{width:390,height:844});
			const controls=await page.evaluate(()=>{
				const select=document.querySelector('select');
				const option=select&&select.options.length ? select.options[0] : null;
				if(!select||!option){return null;}
				const selectStyle=getComputedStyle(select);
				const optionStyle=getComputedStyle(option);
				return {mode:document.body.dataset.dpThemeMode||'',scheme:selectStyle.colorScheme,selectColor:selectStyle.color,selectBackground:selectStyle.backgroundColor,optionColor:optionStyle.color,optionBackground:optionStyle.backgroundColor};
			});
			assert(controls&&controls.mode==='system'&&controls.scheme.includes('dark'),'System-dark native control fixture is incomplete.');
			assert(controls.selectBackground!=='rgb(255, 255, 255)'&&contrastRatio(controls.selectColor,controls.selectBackground)>=4.5,'System-dark select is unreadable.');
			assert(controls.optionBackground!=='rgb(255, 255, 255)'&&contrastRatio(controls.optionColor,controls.optionBackground)>=4.5,'System-dark selected option is unreadable.');
			await applyTheme(page,'flat_minima','system');
			await open(page,baseUrl+'/sellers?panel_theme=flat_minima',{width:1440,height:900});
			const selectedRow=await page.evaluate(()=>{
				const input=document.querySelector('.dp-panel-select input[name="selected[]"]');
				if(!input){return null;}
				if(!input.checked){input.click();}
				const row=input.closest('tr');
				const marker=row?.querySelector('td:first-child');
				const cells=Array.from(row?.querySelectorAll('td')||[]);
				if(!row||cells.length===0||!marker){return null;}
				const markerStyle=getComputedStyle(marker,'::after');
				return {
					mode:document.body.dataset.dpThemeMode||'',
					selected:row.classList.contains('dp-panel-row-selected')&&row.getAttribute('aria-selected')==='true',
					rowBackground:getComputedStyle(row).backgroundColor,
					cells:cells.map(cell=>{const style=getComputedStyle(cell);return {className:cell.className,background:style.backgroundColor,color:style.color};}),
					marker:markerStyle.backgroundColor,
				};
			});
			assert(selectedRow&&selectedRow.mode==='system'&&selectedRow.selected,'System-dark selected-row fixture did not activate.');
			assert(relativeLuminance(selectedRow.rowBackground)<0.18,'Selected row wrapper becomes a bright seam in system-dark mode: '+selectedRow.rowBackground+'.');
			assert(selectedRow.cells.length>=2&&selectedRow.cells.every(cell=>relativeLuminance(cell.background)<0.18),'A selected-row cell becomes a bright slab in system-dark mode: '+JSON.stringify(selectedRow.cells)+'.');
			assert(selectedRow.cells.every(cell=>contrastRatio(cell.color,cell.background)>=4.5),'Selected row text contrast is below 4.5:1 in system-dark mode: '+JSON.stringify(selectedRow.cells)+'.');
			assert(selectedRow.marker!=='rgba(0, 0, 0, 0)'&&selectedRow.marker!=='transparent','Selected row lost its accent marker in system-dark mode.');
			await open(page,baseUrl+'?panel_theme=flat_minima',{width:1440,height:900});
			const globalSearch=await page.evaluate(()=>{
				const form=document.querySelector('.dp-panel-global-search');
				const input=form?.querySelector('input[type="search"]');
				if(!form||!input){return null;}
				const measure=direction=>{
					document.documentElement.dir=direction;
					const formRect=form.getBoundingClientRect();
					const inputRect=input.getBoundingClientRect();
					const inputStyle=getComputedStyle(input);
					const before=getComputedStyle(form,'::before');
					const after=getComputedStyle(form,'::after');
					const iconInlineStart=direction==='rtl'
						? inputRect.right-(formRect.right-parseFloat(before.right))
						: formRect.left+parseFloat(before.left)-inputRect.left;
					const iconWidth=parseFloat(before.width)+parseFloat(before.borderInlineStartWidth)+parseFloat(before.borderInlineEndWidth);
					return {
						direction,
						paddingInlineStart:parseFloat(inputStyle.paddingInlineStart),
						iconInlineStart,
						iconWidth,
						textGap:parseFloat(inputStyle.paddingInlineStart)-iconInlineStart-iconWidth,
						before:{content:before.content,zIndex:before.zIndex,color:before.borderTopColor,pointerEvents:before.pointerEvents},
						after:{content:after.content,zIndex:after.zIndex,color:after.backgroundColor,pointerEvents:after.pointerEvents},
						inputBackground:inputStyle.backgroundColor,
						overflow:Math.max(0,form.scrollWidth-form.clientWidth),
					};
				};
				return {mode:document.body.dataset.dpThemeMode||'',effects:document.body.dataset.dpThemeEffects||'',placeholder:input.placeholder,directions:['ltr','rtl'].map(measure)};
			});
			assert(globalSearch&&globalSearch.mode==='system'&&globalSearch.effects.split(/\s+/).includes('flat_minima'),'System-dark workspace search fixture is incomplete.');
			assert(globalSearch.placeholder==='Search across this workspace','Workspace search lost its descriptive placeholder.');
			assert(globalSearch.directions.every(item=>item.paddingInlineStart>=44&&item.paddingInlineStart<=46),'Workspace search text retains excessive leading space: '+JSON.stringify(globalSearch.directions)+'.');
			assert(globalSearch.directions.every(item=>item.iconInlineStart>=14&&item.iconInlineStart<=18&&item.textGap>=8&&item.textGap<=14),'Workspace search icon and label are not optically aligned in both directions: '+JSON.stringify(globalSearch.directions)+'.');
			assert(globalSearch.directions.every(item=>item.before.content==='""'&&item.after.content==='""'&&Number(item.before.zIndex)>=1&&Number(item.after.zIndex)>=1&&item.before.pointerEvents==='none'&&item.after.pointerEvents==='none'),'Workspace search decoration is hidden below the opaque control or captures input.');
			assert(globalSearch.directions.every(item=>contrastRatio(item.before.color,item.inputBackground)>=3&&contrastRatio(item.after.color,item.inputBackground)>=3),'Workspace search icon lacks system-dark non-text contrast: '+JSON.stringify(globalSearch.directions)+'.');
			assert(globalSearch.directions.every(item=>item.overflow===0),'Workspace search decoration creates internal overflow.');
			return {...controls,selectedRow,globalSearch};
		});

		await probe('blocking modal and command surfaces dismiss transient chrome',async page=>{
			await applyTheme(page,'glass','dark');
			await open(page,baseUrl+'/orders/board?panel_theme=glass',{width:1024,height:800});
			await page.click('.dp-panel-board-card .dp-panel-row-more>summary');
			await page.waitForSelector('.dp-panel-board-card .dp-panel-row-more[open]>.dp-panel-row-more-menu',{visible:true,timeout:10000});
			await page.click('.dp-panel-row-more[open] .dp-panel-action-group>summary');
			const messageActionSelector='.dp-panel-row-more[open] .dp-panel-action-group[open] [data-dp-panel-action-name="message_customer"]';
			const messageAction=await page.waitForSelector(messageActionSelector,{visible:true,timeout:10000});
			await page.waitForFunction(selector=>{
				const target=document.querySelector(selector);
				if(!target){return false;}
				const rect=target.getBoundingClientRect();
				if(rect.width<=0||rect.height<=0){return false;}
				const hit=document.elementFromPoint(rect.left+rect.width/2,rect.top+rect.height/2);
				return !!(hit&&(hit===target||target.contains(hit)));
			},{timeout:10000},messageActionSelector);
			await messageAction.click();
			await page.waitForSelector('.dp-panel-modal-root:not([hidden])',{visible:true,timeout:15000});
			await page.waitForFunction(()=>{
				const root=document.querySelector('.dp-panel-modal-root:not([hidden])');
				return !!root&&!root.classList.contains('dp-panel-modal-busy')&&!root.querySelector('.dp-panel-modal-loading');
			},{timeout:15000});
			const modal=await page.evaluate(()=>{
				const root=document.querySelector('.dp-panel-modal-root:not([hidden])');
				const dialog=root&&root.querySelector('.dp-panel-modal');
				const rect=dialog&&dialog.getBoundingClientRect();
				const hit=rect&&document.elementFromPoint(rect.left+rect.width/2,rect.top+Math.min(60,rect.height/2));
				return {transients:document.querySelectorAll('.dp-panel-row-more[open],.dp-panel-action-group[open],.dp-panel-column-picker[open],.dp-panel-saved-view-menu[open]').length,focused:root&&root.contains(document.activeElement),hit:!!(hit&&root.contains(hit))};
			});
			assert(modal.transients===0&&modal.focused&&modal.hit,'Modal opened beneath stale transient chrome: '+JSON.stringify(modal)+'.');
			await page.click('.dp-panel-modal-close');
			await page.waitForFunction(()=>document.querySelector('.dp-panel-modal-root')?.hidden===true,{timeout:10000});
			assert(await page.evaluate(()=>document.activeElement?.matches('.dp-panel-row-more>summary')),'Closing a row action modal did not restore focus to the visible More trigger.');
			await page.click('.dp-panel-board-card .dp-panel-row-more>summary');
			await page.keyboard.down('Control');
			try{await page.keyboard.press('k');}finally{await page.keyboard.up('Control');}
			await page.waitForSelector('.dp-panel-command-root:not([hidden])',{visible:true,timeout:10000});
			const command=await page.evaluate(()=>{
				const root=document.querySelector('.dp-panel-command-root:not([hidden])');
				return {transients:document.querySelectorAll('.dp-panel-row-more[open],.dp-panel-action-group[open],.dp-panel-column-picker[open],.dp-panel-saved-view-menu[open]').length,focused:document.activeElement?.matches('[data-dp-panel-command-input]'),activeInside:root&&root.contains(document.activeElement)};
			});
			assert(command.transients===0&&command.focused&&command.activeInside,'Command palette opened beneath stale transient chrome: '+JSON.stringify(command)+'.');
			return {modal,command};
		});

		await probe('unsaved confirmation traps focus and restores its opener',async page=>{
			await applyTheme(page,'glass','dark');
			await open(page,baseUrl+'/orders/create?panel_theme=glass',{width:1024,height:800});
			const input=await page.$('.dp-panel-form input[type="text"],.dp-panel-form textarea');
			assert(input,'Dirty-form input fixture is missing.');
			await input.type('Unsaved focus trap regression');
			const link=await page.$('.dp-panel-breadcrumbs a');
			assert(link,'Unsaved-navigation link fixture is missing.');
			await link.click();
			await page.waitForSelector('.dp-panel-unsaved-root:not([hidden])',{visible:true,timeout:10000});
			await page.waitForFunction(()=>document.activeElement?.matches('[data-dp-panel-unsaved-stay]'),{timeout:10000});
			for(let index=0;index<12;index++){
				await pressTab(page,index%4===0);
				assert(await page.evaluate(()=>document.querySelector('.dp-panel-unsaved-root:not([hidden])')?.contains(document.activeElement)),'Focus escaped the unsaved confirmation dialog.');
			}
			await page.keyboard.press('Escape');
			await page.waitForSelector('.dp-panel-unsaved-root',{hidden:true,timeout:10000});
			assert(await link.evaluate(element=>document.activeElement===element),'Unsaved dialog did not restore focus to the initiating navigation control.');
			return {trapped:true,restored:true};
		});

		await probe('commandbar controls share normalized geometry',async page=>{
			await applyTheme(page,'glass','dark');
			await open(page,baseUrl+'/orders?group=status&view=review&page=1&panel_theme=glass',{width:1440,height:1000});
			const geometry=await page.evaluate(()=>{
				const controls=[
					['search',document.querySelector('.dp-panel-commandbar-search>.dp-panel-search')],
					['filters',document.querySelector('.dp-panel-commandbar .dp-panel-filter-trigger')],
					['assign',document.querySelector('.dp-panel-commandbar-primary>form [data-dp-panel-action-name="assign"]')],
					['ops',document.querySelector('.dp-panel-commandbar-primary>.dp-panel-action-group>summary')],
					['create',document.querySelector('.dp-panel-commandbar-create')],
				].filter(([,element])=>element).map(([name,element])=>{
					const rect=element.getBoundingClientRect();
					return {name,height:rect.height,top:rect.top,bottom:rect.bottom};
				});
				return {
					controls,
					documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
				};
			});
			await applyTheme(page,'flat_minima','dark');
			const measureAccent=async(width,height)=>{
				await open(page,baseUrl+'/orders?group=status&view=review&page=1&panel_theme=flat_minima',{width,height});
				return page.evaluate(()=>{
					const surface=document.querySelector('.dp-panel-commandbar');
					if(!surface){return null;}
					const rect=surface.getBoundingClientRect();
					const style=getComputedStyle(surface);
					const accent=getComputedStyle(surface,'::before');
					const px=value=>Number.parseFloat(value)||0;
					return {
						surfaceWidth:rect.width,
						surfaceRadius:px(style.borderTopLeftRadius),
						surfaceOverflow:style.overflow,
						left:px(accent.left),
						right:px(accent.right),
						top:px(accent.top),
						width:px(accent.width),
						height:px(accent.height),
						radius:px(accent.borderTopLeftRadius),
						clipPath:accent.clipPath,
						documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
					};
				});
			};
			const accents=[await measureAccent(1440,1000),await measureAccent(390,844)];
			assert(geometry.controls.length===5,'Commandbar normalization fixture is incomplete.');
			const heights=geometry.controls.map(item=>item.height);
			const tops=geometry.controls.map(item=>item.top);
			assert(Math.max(...heights)-Math.min(...heights)<=1,'Commandbar control heights are inconsistent: '+JSON.stringify(geometry.controls));
			assert(heights.every(height=>height>=47&&height<=49),'Commandbar controls do not resolve to the 48px contract.');
			assert(Math.max(...tops)-Math.min(...tops)<=1,'Commandbar controls do not align on one row.');
			assert(geometry.documentOverflow===0,'Normalized commandbar overflows the viewport.');
			for(const accent of accents){
				assert(accent&&accent.surfaceRadius>=16&&accent.surfaceOverflow==='visible','Commandbar accent fixture is missing or clips interactive descendants: '+JSON.stringify(accent)+'.');
				assert(accent.left>=10&&accent.right>=10&&accent.top===0&&accent.height===3&&accent.radius>=accent.height/2,'Commandbar accent escapes its rounded corner tangent: '+JSON.stringify(accent)+'.');
				assert(Math.abs(accent.width+accent.left+accent.right-(accent.surfaceWidth-2))<=2&&accent.clipPath==='none','Commandbar accent relies on an invalid clipping workaround: '+JSON.stringify(accent)+'.');
				assert(accent.documentOverflow===0,'Inset commandbar accent overflows the viewport.');
			}
			return {geometry,accents};
		});

		await probe('commandbar Ops and Columns disclosures hit-test above sibling controls',async page=>{
			await applyTheme(page,'glass','dark');
			await open(page,baseUrl+'/orders?group=status&view=review&page=1&panel_theme=glass',{width:1024,height:800});
			const measure=async(selector,menuSelector)=>{
				await page.click(selector);
				await page.waitForSelector(menuSelector,{visible:true,timeout:10000});
				return page.evaluate(target=>{
					const menu=document.querySelector(target);
					const surface=menu&&Array.from(menu.querySelectorAll('a,button,input,label')).find(candidate=>{
						const rect=candidate.getBoundingClientRect();
						const style=getComputedStyle(candidate);
						return rect.width>0&&rect.height>0&&style.display!=='none'&&style.visibility!=='hidden'&&style.pointerEvents!=='none';
					})||menu;
					if(!menu||!surface){return null;}
					const rect=surface.getBoundingClientRect();
					const x=Math.max(0,Math.min(innerWidth-1,rect.left+Math.min(rect.width/2,40)));
					const y=Math.max(0,Math.min(innerHeight-1,rect.top+Math.min(rect.height/2,20)));
					const hit=document.elementFromPoint(x,y);
					return {hit:!!(hit&&menu.contains(hit)),surface:surface.tagName,point:{x,y},menuZ:getComputedStyle(menu).zIndex,overflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth)};
				},menuSelector);
			};
			const ops=await measure('.dp-panel-commandbar-primary>.dp-panel-action-group>summary','.dp-panel-commandbar-primary>.dp-panel-action-group[open]>.dp-panel-action-menu');
			await page.keyboard.press('Escape');
			const columns=await measure('.dp-panel-commandbar-actions>.dp-panel-column-picker>summary','.dp-panel-commandbar-actions>.dp-panel-column-picker[open]>form');
			assert(ops&&ops.hit&&ops.overflow===0,'Commandbar Ops opened beneath sibling controls or overflowed: '+JSON.stringify(ops)+'.');
			assert(columns&&columns.hit&&columns.overflow===0,'Columns opened beneath sibling controls or overflowed: '+JSON.stringify(columns)+'.');
			return {ops,columns};
		});

		await probe('ajax navigation activates capability-scoped assets before revealing a surface',async page=>{
			await applyTheme(page,'flat_minima','dark');
			await open(page,baseUrl+'?panel_theme=flat_minima',{width:1440,height:900});
			const shell=await page.evaluate(()=>{
				window.__dpPanelCapabilityHandoff='preserved';
				const styles=Array.from(document.querySelectorAll('link[href*="dp_panel_asset=panel-style-"],link[href*="dp_panel_asset=panel.css"]'));
				const scripts=Array.from(document.querySelectorAll('script[src*="dp_panel_asset=panel-runtime-"],script[src*="dp_panel_asset=panel.js"]'));
				return {
					assets:String(document.querySelector('main.dp-panel')?.dataset.dpPanelAssets||'').split(/\s+/).filter(Boolean),
					mode:scripts.some(node=>new URL(node.src,location.href).searchParams.get('dp_panel_asset')?.startsWith('panel-runtime-'))?'physical':'aggregate',
					styles:styles.map(node=>new URL(node.href,location.href).searchParams.get('dp_panel_asset')),
					scripts:scripts.map(node=>new URL(node.src,location.href).searchParams.get('dp_panel_asset')),
				};
			});
			assert(!shell.assets.includes('table')&&(
				shell.mode==='physical'
					? shell.styles.length===6&&new Set(shell.styles).size===6&&shell.scripts.length===5&&shell.scripts.includes('panel-runtime-widget-runtime.js')
					: shell.styles.length===1&&shell.scripts.length===1
			),'Dashboard capability fixture is not scoped: '+JSON.stringify(shell)+'.');
			await page.$eval('.dp-panel-sidebar-link[href*="/orders"]',link=>link.click());
			await page.waitForFunction(()=>location.pathname.endsWith('/orders')&&document.querySelector('main.dp-panel')?.dataset.dpPanelAssets?.split(/\s+/).includes('table'));
			await page.waitForFunction(()=>{
				const controllers=window.DataphyrePanel?.runtimeController?.controllers||{};
				return controllers.fields&&controllers.editor&&controllers.modal&&document.querySelector('.dp-panel-table-group.active');
			});
			const upgraded=await page.evaluate(()=>{
				const host=document.querySelector('.dp-panel-table-groups');
				const active=host?.querySelector('.dp-panel-table-group.active');
				const hostStyle=host&&getComputedStyle(host);
				const activeStyle=active&&getComputedStyle(active);
				const activeNavigationGroup=document.querySelector('.dp-panel-sidebar-group.active');
				const activeNavigationSurface=activeNavigationGroup?.querySelector('.dp-panel-sidebar-link.active,.dp-panel-sidebar-submenu.active>summary');
				const activeNavigationSurfaceStyle=activeNavigationSurface&&getComputedStyle(activeNavigationSurface);
				const resourceTable=document.querySelector('.dp-panel-table');
				const resourceTableWrapper=resourceTable?.closest('.dp-panel-table-scroll');
				const styles=Array.from(document.querySelectorAll('link[href*="dp_panel_asset=panel-style-"],link[href*="dp_panel_asset=panel.css"]'));
				const scripts=Array.from(document.querySelectorAll('script[src*="dp_panel_asset=panel-runtime-"],script[src*="dp_panel_asset=panel.js"]'));
				window.__dpPanelCapabilityRuntime=window.DataphyrePanel?.runtimeController;
				return {
					sentinel:window.__dpPanelCapabilityHandoff,
					assets:String(document.querySelector('main.dp-panel')?.dataset.dpPanelAssets||'').split(/\s+/).filter(Boolean),
					styles:styles.map(node=>node.href),
					scripts:scripts.map(node=>node.src),
					styleNames:styles.map(node=>new URL(node.href,location.href).searchParams.get('dp_panel_asset')),
					scriptNames:scripts.map(node=>new URL(node.src,location.href).searchParams.get('dp_panel_asset')),
					host:host&&{display:hostStyle.display,border:hostStyle.borderTopWidth,height:host.getBoundingClientRect().height},
					active:active&&{display:activeStyle.display,decoration:activeStyle.textDecorationLine,background:activeStyle.backgroundColor,height:active.getBoundingClientRect().height},
					navigation:activeNavigationGroup&&activeNavigationSurface&&{
						groupDecoration:getComputedStyle(activeNavigationGroup,'::before').content,
						surfaceBackground:activeNavigationSurfaceStyle.backgroundColor,
						surfaceBorder:activeNavigationSurfaceStyle.borderColor,
					},
					table:resourceTable&&resourceTableWrapper&&{
						width:Math.round(resourceTable.getBoundingClientRect().width),
						wrapperWidth:resourceTableWrapper.clientWidth,
						inlineWidth:resourceTable.style.getPropertyValue('width'),
					},
					overflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
				};
			});
			assert(upgraded.sentinel==='preserved','Capability navigation fell back to a full reload instead of preserving the live shell.');
			assert(upgraded.assets.includes('table')&&upgraded.assets.includes('form')&&(
				shell.mode==='physical'
					? upgraded.styles.length===6&&new Set(upgraded.styleNames).size===6&&upgraded.scripts.length===8&&new Set(upgraded.scriptNames).size===8
					: upgraded.styles.length===1&&upgraded.scripts.length===1
			),'Destination capability assets were not activated exactly once: '+JSON.stringify(upgraded)+'.');
			assert(
				upgraded.styles.every(url=>url.includes('dp_panel_caps=')&&url.includes('table'))
					&&(shell.mode==='physical'
						? ['panel-runtime-kernel.js','panel-runtime-interaction.js','panel-runtime-transport.js','panel-runtime-quality.js','panel-runtime-widget-runtime.js','panel-runtime-form.js','panel-runtime-editor.js','panel-runtime-modal.js'].every(name=>upgraded.scriptNames.includes(name))
						: upgraded.scripts[0].includes('dp_panel_caps=')&&upgraded.scripts[0].includes('table')),
				'Active asset variants do not declare or implement the destination table capability: '+JSON.stringify(upgraded)+'.'
			);
			assert(upgraded.host?.display==='flex'&&upgraded.host.border==='1px'&&upgraded.host.height>=44&&upgraded.active?.display==='flex'&&upgraded.active.decoration==='none'&&upgraded.active.background!=='rgba(0, 0, 0, 0)'&&upgraded.active.height>=34,'Table grouping controls were revealed before their segmented styling loaded: '+JSON.stringify(upgraded)+'.');
			assert(upgraded.navigation?.groupDecoration==='none'&&upgraded.navigation.surfaceBackground!=='rgba(0, 0, 0, 0)'&&upgraded.navigation.surfaceBorder!=='rgba(0, 0, 0, 0)','Active sidebar hierarchy duplicates its selected surface with a detached group decoration: '+JSON.stringify(upgraded.navigation)+'.');
			assert(upgraded.table?.inlineWidth==='100%'&&upgraded.table.width>=upgraded.table.wrapperWidth-2,'Resource table is narrower than its available wrapper: '+JSON.stringify(upgraded.table)+'.');
			assert(upgraded.overflow===0,'Capability asset handoff creates horizontal overflow.');
			await page.$eval('.dp-panel-table-group[href*="group=risk"]',link=>link.click());
			await page.waitForFunction(()=>new URL(location.href).searchParams.get('group')==='risk'&&document.querySelector('.dp-panel-table-group.active span')?.textContent==='Risk');
			const sameCapability=await page.evaluate(()=>{
				const styles=Array.from(document.querySelectorAll('link[href*="dp_panel_asset=panel-style-"],link[href*="dp_panel_asset=panel.css"]'));
				const scripts=Array.from(document.querySelectorAll('script[src*="dp_panel_asset=panel-runtime-"],script[src*="dp_panel_asset=panel.js"]'));
				return {
					sentinel:window.__dpPanelCapabilityHandoff,
					runtimeStable:window.__dpPanelCapabilityRuntime===window.DataphyrePanel?.runtimeController,
					styles:styles.map(node=>new URL(node.href,location.href).searchParams.get('dp_panel_asset')),
					scripts:scripts.map(node=>new URL(node.src,location.href).searchParams.get('dp_panel_asset')),
					decoration:getComputedStyle(document.querySelector('.dp-panel-table-group.active')).textDecorationLine,
				};
			});
			assert(sameCapability.sentinel==='preserved'&&sameCapability.runtimeStable&&sameCapability.styles.length===upgraded.styles.length&&sameCapability.scripts.length===upgraded.scripts.length&&new Set(sameCapability.styles).size===sameCapability.styles.length&&new Set(sameCapability.scripts).size===sameCapability.scripts.length&&sameCapability.decoration==='none','Same-capability navigation reloaded assets or lost segmented styling: '+JSON.stringify(sameCapability)+'.');
			await page.evaluate(basePath=>{
				const dashboard=Array.from(document.querySelectorAll('.dp-panel-sidebar-link[href]')).find(link=>new URL(link.href).pathname===basePath);
				if(dashboard){dashboard.click();}
			},new URL(baseUrl).pathname);
			await page.waitForFunction(basePath=>location.pathname===basePath&&document.querySelector('[data-dp-widget-island][data-dp-widget-enhanced="1"]'),{},new URL(baseUrl).pathname);
			const dashboard=await page.evaluate(()=>({
				sentinel:window.__dpPanelCapabilityHandoff,
				assets:String(document.querySelector('main.dp-panel')?.dataset.dpPanelAssets||'').split(/\s+/).filter(Boolean),
				widgetRuntime:!!window.DataphyrePanel?.runtimeController?.controllers?.widget_runtime,
				styles:Array.from(document.querySelectorAll('link[href*="dp_panel_asset=panel-style-"],link[href*="dp_panel_asset=panel.css"]')).map(node=>node.href),
				scripts:Array.from(document.querySelectorAll('script[src*="dp_panel_asset=panel-runtime-"],script[src*="dp_panel_asset=panel.js"]')).map(node=>node.src),
			}));
			assert(dashboard.sentinel==='preserved'&&dashboard.assets.includes('widget-runtime')&&!dashboard.assets.includes('table')&&dashboard.widgetRuntime&&(
				shell.mode==='physical'
					? dashboard.styles.length===6&&dashboard.scripts.length===upgraded.scripts.length&&new Set(dashboard.scripts).size===dashboard.scripts.length&&dashboard.scripts.some(url=>url.includes('panel-runtime-widget-runtime.js'))
					: dashboard.styles.length===1&&dashboard.scripts.length===1&&dashboard.scripts[0].includes('widget-runtime')
			),'Capability downshift did not restore the interactive dashboard bundle cleanly: '+JSON.stringify(dashboard)+'.');
			await page.$eval('.dp-panel-sidebar-link[href*="/orders"]',link=>link.click());
			await page.waitForFunction(()=>{
				const controllers=window.DataphyrePanel?.runtimeController?.controllers||{};
				return location.pathname.endsWith('/orders')&&controllers.fields&&controllers.editor&&controllers.modal&&document.querySelector('.dp-panel-table-group.active');
			});
			const revisit=await page.evaluate(()=>{
				const active=document.querySelector('.dp-panel-table-group.active');
				const style=active&&getComputedStyle(active);
				return {
					sentinel:window.__dpPanelCapabilityHandoff,
					styles:document.querySelectorAll('link[href*="dp_panel_asset=panel-style-"],link[href*="dp_panel_asset=panel.css"]').length,
					scripts:document.querySelectorAll('script[src*="dp_panel_asset=panel-runtime-"],script[src*="dp_panel_asset=panel.js"]').length,
					active:active&&{display:style.display,decoration:style.textDecorationLine,background:style.backgroundColor},
				};
			});
			assert(revisit.sentinel==='preserved'&&revisit.styles===upgraded.styles.length&&revisit.scripts===upgraded.scripts.length&&revisit.active?.display==='flex'&&revisit.active.decoration==='none'&&revisit.active.background!=='rgba(0, 0, 0, 0)','Capability upgrade failed after revisiting a previously loaded surface: '+JSON.stringify(revisit)+'.');
			return {shell,upgraded,sameCapability,dashboard,revisit};
		});

		await probe('density views and groups wrap without scroll containers',async page=>{
			await applyTheme(page,'glass','dark');
			await open(page,baseUrl+'/orders?group=status&view=review&page=1&panel_theme=glass',{width:810,height:740});
			const segments=await page.evaluate(()=>{
				const rows=Array.from(document.querySelectorAll('.dp-panel-density,.dp-panel-table-views,.dp-panel-table-groups')).map(element=>{
					const style=getComputedStyle(element);
					const rect=element.getBoundingClientRect();
					const outOfBounds=Array.from(element.children).filter(child=>{
						const childRect=child.getBoundingClientRect();
						return childRect.width>0&&childRect.height>0&&(childRect.left<rect.left-1||childRect.right>rect.right+1);
					}).length;
					return {
						className:element.className,
						clientWidth:element.clientWidth,
						scrollWidth:element.scrollWidth,
						overflowX:style.overflowX,
						flexWrap:style.flexWrap,
						outOfBounds,
					};
				});
				return {rows,documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth)};
			});
			assert(segments.rows.length===3,'Expected density, views, and groups responsive fixtures.');
			segments.rows.forEach(row=>{
				assert(row.flexWrap==='wrap',row.className+' does not wrap.');
				assert(row.overflowX!=='auto'&&row.overflowX!=='scroll',row.className+' remains an overflow scroll container.');
				assert(row.scrollWidth<=row.clientWidth+1,row.className+' overflows internally by '+(row.scrollWidth-row.clientWidth)+'px.');
				assert(row.outOfBounds===0,row.className+' has '+row.outOfBounds+' controls outside its bounds.');
			});
			assert(segments.documentOverflow===0,'Responsive table controls overflow the document.');
			return segments;
		});

		await probe('relation search joins controls and empty state spans the table',async page=>{
			await applyTheme(page,'glass','dark');
			await open(page,baseUrl+'?resource=orders&operation=relation&record=4&relation=items&r_items_q=__missing__&panel_theme=glass',{width:1024,height:800});
			await page.waitForSelector('.dp-panel-relation .dp-panel-empty-row',{visible:true,timeout:10000});
			const relation=await page.evaluate(()=>{
				const form=document.querySelector('.dp-panel-relation>.dp-panel-toolbar>.dp-panel-search');
				const input=form&&form.querySelector('input[type="search"]');
				const submit=form&&form.querySelector('button[type="submit"]');
				const clear=form&&form.querySelector('a.dp-panel-button');
				const cell=document.querySelector('.dp-panel-relation .dp-panel-empty-row>td');
				const empty=cell&&cell.querySelector('.dp-panel-empty-state');
				const headers=document.querySelectorAll('.dp-panel-relation .dp-panel-table thead th');
				if(!form||!input||!submit||!clear||!cell||!empty){return null;}
				const inputRect=input.getBoundingClientRect();
				const submitRect=submit.getBoundingClientRect();
				const clearRect=clear.getBoundingClientRect();
				const cellRect=cell.getBoundingClientRect();
				const emptyRect=empty.getBoundingClientRect();
				return {
					columnGap:getComputedStyle(form).columnGap,
					inputToSubmit:Math.abs(inputRect.right-submitRect.left),
					submitToClear:Math.abs(submitRect.right-clearRect.left),
					controlHeights:[inputRect.height,submitRect.height,clearRect.height],
					headerCount:headers.length,
					colSpan:cell.colSpan,
					emptyWidthRatio:cellRect.width>0 ? emptyRect.width/cellRect.width : 0,
					documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
				};
			});
			assert(relation,'Empty relation search fixture is incomplete.');
			assert(relation.columnGap==='0px'||relation.columnGap==='normal','Relation search declares an unexpected grid gap.');
			assert(relation.inputToSubmit<=1&&relation.submitToClear<=1,'Relation search controls are visibly separated.');
			assert(Math.max(...relation.controlHeights)-Math.min(...relation.controlHeights)<=1,'Relation search controls have inconsistent heights.');
			assert(relation.headerCount>0&&relation.colSpan===relation.headerCount,'Empty relation cell does not span every table column.');
			assert(relation.emptyWidthRatio>=0.9,'Empty relation state only fills '+Math.round(relation.emptyWidthRatio*100)+'% of its table cell.');
			assert(relation.documentOverflow===0,'Empty relation page overflows the viewport.');
			return relation;
		});

		await probe('dark file checkbox and select controls remain readable',async page=>{
			await applyTheme(page,'glass','dark');
			await open(page,baseUrl+'/orders/import?panel_theme=glass',{width:390,height:844});
			const controls=await page.evaluate(()=>{
				const file=document.querySelector('input[type="file"]');
				const checkbox=document.querySelector('input[type="checkbox"]');
				const select=document.querySelector('select');
				const option=select&&select.options.length ? select.options[0] : null;
				if(!file||!checkbox||!select||!option){return null;}
				const fileStyle=getComputedStyle(file);
				const fileButton=getComputedStyle(file,'::file-selector-button');
				const checkboxStyle=getComputedStyle(checkbox);
				const checkboxBefore=getComputedStyle(checkbox,'::before');
				const label=checkbox.closest('label');
				const labelStyle=getComputedStyle(label);
				const labelBefore=getComputedStyle(label,'::before');
				const selectStyle=getComputedStyle(select);
				const optionStyle=getComputedStyle(option);
				const fileRect=file.getBoundingClientRect();
				const checkboxRect=checkbox.getBoundingClientRect();
				return {
					themeMode:document.body.dataset.dpThemeMode||'',
					themeEffects:document.body.dataset.dpThemeEffects||'',
					file:{height:fileRect.height,scheme:fileStyle.colorScheme,color:fileStyle.color,background:fileStyle.backgroundColor,buttonColor:fileButton.color,buttonBackground:fileButton.backgroundColor},
					select:{color:selectStyle.color,background:selectStyle.backgroundColor,optionColor:optionStyle.color,optionBackground:optionStyle.backgroundColor},
					checkbox:{width:checkboxRect.width,height:checkboxRect.height,appearance:checkboxStyle.appearance||checkboxStyle.webkitAppearance,backgroundImage:checkboxStyle.backgroundImage,before:checkboxBefore.content,labelBefore:labelBefore.content,labelShadow:labelStyle.boxShadow},
				};
			});
			assert(controls,'Dark import control fixtures are missing.');
			assert(controls.themeMode==='dark'&&controls.themeEffects.split(/\s+/).includes('glass'),'Dark glass import theme was not applied.');
			assert(controls.file.height>=44&&controls.file.scheme.includes('dark'),'File input does not satisfy dark 44px control geometry.');
			assert(controls.file.background!=='rgb(255, 255, 255)'&&contrastRatio(controls.file.color,controls.file.background)>=4.5,'File input is unreadable in dark mode.');
			assert(controls.file.buttonBackground!=='rgb(255, 255, 255)'&&controls.file.buttonBackground!=='rgba(0, 0, 0, 0)','File selector button keeps a white or transparent native background.');
			assert(contrastRatio(controls.file.buttonColor,controls.file.buttonBackground)>=3,'File selector button contrast is below 3:1.');
			assert(contrastRatio(controls.select.color,controls.select.background)>=4.5,'Import select contrast is below 4.5:1.');
			assert(contrastRatio(controls.select.optionColor,controls.select.optionBackground)>=4.5,'Import option contrast is below 4.5:1.');
			assert(controls.checkbox.appearance==='none','Checkbox retains native appearance.');
			assert(controls.checkbox.width>=20&&controls.checkbox.height>=20,'Checkbox target is visually undersized.');
			assert(controls.checkbox.backgroundImage!=='none','Checked checkbox has no deterministic check mark.');
			assert(controls.checkbox.before==='none'&&controls.checkbox.labelBefore==='none','Checkbox renders an unexpected horizontal pseudo-element.');
			assert(controls.checkbox.labelShadow==='none','Checkbox field retains an unintended shadow line.');
			return controls;
		});

		await probe('glass separators metrics and board menus stay normalized',async page=>{
			await applyTheme(page,'glass','dark');
			await open(page,baseUrl+'/orders?group=status&view=review&page=1&panel_theme=glass',{width:1440,height:1000});
			const chrome=await page.evaluate(()=>{
				const top=document.querySelector('.dp-panel-commandbar-top');
				const bottom=document.querySelector('.dp-panel-commandbar-bottom');
				const meta=document.querySelector('.dp-panel-table-meta');
				if(!top||!bottom||!meta){return null;}
				const topStyle=getComputedStyle(top);
				const bottomStyle=getComputedStyle(bottom);
				const metaStyle=getComputedStyle(meta);
				return {
					topBorderBottom:parseFloat(topStyle.borderBottomWidth)||0,
					bottomBorderTop:parseFloat(bottomStyle.borderTopWidth)||0,
					directRules:document.querySelectorAll('.dp-panel-commandbar>hr').length,
					metaBorders:[metaStyle.borderTopWidth,metaStyle.borderRightWidth,metaStyle.borderBottomWidth,metaStyle.borderLeftWidth],
					metaRadii:[metaStyle.borderTopLeftRadius,metaStyle.borderTopRightRadius,metaStyle.borderBottomRightRadius,metaStyle.borderBottomLeftRadius],
					metaBackground:metaStyle.backgroundColor,
				};
			});
			assert(chrome,'Glass commandbar or table metrics fixture is missing.');
			assert(chrome.topBorderBottom===1&&chrome.bottomBorderTop===0&&chrome.directRules===0,'Glass commandbar renders more than one row separator.');
			assert(chrome.metaBorders.every(value=>parseFloat(value)===0),'Glass table metrics retain a border.');
			assert(chrome.metaRadii.every(value=>parseFloat(value)===0),'Glass table metrics retain a rounded container.');
			assert(chrome.metaBackground==='rgba(0, 0, 0, 0)','Glass table metrics retain a filled container.');

			await applyTheme(page,'flat_minima','dark');
			await open(page,baseUrl+'/orders?group=status&view=review&page=1&panel_theme=flat_minima',{width:1024,height:800});
			await page.click('.dp-panel-table tbody td.dp-panel-actions .dp-panel-row-more>summary');
			await page.waitForSelector('.dp-panel-table tbody td.dp-panel-actions .dp-panel-row-more[open]>.dp-panel-row-more-menu',{visible:true,timeout:10000});
			await page.waitForFunction(()=>document.querySelector('.dp-panel-table tbody td.dp-panel-actions .dp-panel-row-more[open]')?.classList.contains('dp-panel-row-more-floating'),{timeout:10000});
			const tableMenu=await page.evaluate(()=>{
				const details=document.querySelector('.dp-panel-table tbody td.dp-panel-actions .dp-panel-row-more[open]');
				const menu=details&&details.querySelector(':scope>.dp-panel-row-more-menu');
				const header=menu&&menu.querySelector(':scope>header');
				const section=menu&&menu.querySelector(':scope>section');
				const trigger=details&&details.querySelector(':scope>summary');
				const row=details&&details.closest('tr');
				if(!details||!menu||!header||!section||!trigger||!row){return null;}
				const menuRect=menu.getBoundingClientRect();
				const triggerRect=trigger.getBoundingClientRect();
				const headerRows=Array.from(header.children).map(element=>element.getBoundingClientRect().top);
				const actionRects=Array.from(section.children).map(element=>{
					const rect=element.getBoundingClientRect();
					return {left:rect.left,right:rect.right,width:rect.width,top:rect.top};
				});
				const sectionStyle=getComputedStyle(section);
				return {
					headerDisplay:getComputedStyle(header).display,
					headerRows,
					sectionDisplay:sectionStyle.display,
					sectionFlexWrap:sectionStyle.flexWrap,
					rowShadow:getComputedStyle(row).boxShadow,
					actionRects,
					menu:{position:getComputedStyle(menu).position,left:menuRect.left,top:menuRect.top,right:menuRect.right,bottom:menuRect.bottom,width:menuRect.width,overflow:Math.max(0,menu.scrollWidth-menu.clientWidth),overlapsTrigger:!(menuRect.right<=triggerRect.left||menuRect.left>=triggerRect.right||menuRect.bottom<=triggerRect.top||menuRect.top>=triggerRect.bottom)},
					sectionOverflow:Math.max(0,section.scrollWidth-section.clientWidth),
					documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
					viewportWidth:innerWidth,
				};
			});
			assert(tableMenu&&tableMenu.actionRects.length>=6,'Open table row menu fixture is incomplete.');
			assert(tableMenu.headerDisplay==='grid'&&tableMenu.headerRows.length>=2&&tableMenu.headerRows[1]>tableMenu.headerRows[0],'Row action menu heading collapsed onto one line.');
			assert(tableMenu.sectionDisplay==='flex'&&tableMenu.sectionFlexWrap==='wrap','Row action menu does not expose its wrapping brick contract.');
			assert(tableMenu.rowShadow==='none','Opening a row action menu elevates the entire table row with a detached shadow.');
			assert(tableMenu.actionRects.every(rect=>rect.width>0&&rect.left>=tableMenu.menu.left-1&&rect.right<=tableMenu.menu.right+1),'A row menu action escapes the menu surface: '+JSON.stringify(tableMenu)+'.');
			assert(tableMenu.menu.position==='fixed'&&!tableMenu.menu.overlapsTrigger&&tableMenu.menu.left>=0&&tableMenu.menu.right<=tableMenu.viewportWidth&&tableMenu.menu.overflow===0&&tableMenu.sectionOverflow===0&&tableMenu.documentOverflow===0,'Open table row menu escapes the viewport or covers its trigger: '+JSON.stringify(tableMenu)+'.');

			await open(page,baseUrl+'/orders?group=status&view=review&page=1&panel_theme=flat_minima',{width:320,height:800});
			await page.click('.dp-panel-table tbody td.dp-panel-actions .dp-panel-row-more>summary');
			await page.waitForSelector('.dp-panel-table tbody td.dp-panel-actions .dp-panel-row-more[open]>.dp-panel-row-more-menu',{visible:true,timeout:10000});
			await page.waitForFunction(()=>document.querySelector('.dp-panel-table tbody td.dp-panel-actions .dp-panel-row-more[open]')?.classList.contains('dp-panel-row-more-floating'),{timeout:10000});
			const mobileTableMenu=await page.evaluate(()=>{
				const details=document.querySelector('.dp-panel-table tbody td.dp-panel-actions .dp-panel-row-more[open]');
				const trigger=details&&details.querySelector(':scope>summary');
				const menu=details&&details.querySelector(':scope>.dp-panel-row-more-menu');
				const section=menu&&menu.querySelector(':scope>section');
				if(!details||!trigger||!menu||!section){return null;}
				const triggerRect=trigger.getBoundingClientRect();
				const menuRect=menu.getBoundingClientRect();
				const controls=Array.from(section.children).map(child=>{
					const control=child.querySelector('button,a,summary');
					return {child:child.getBoundingClientRect().width,control:control?control.getBoundingClientRect().width:0};
				});
				return {
					detailsDisplay:getComputedStyle(details).display,
					menuPosition:getComputedStyle(menu).position,
					controls,
					overlapsTrigger:!(menuRect.right<=triggerRect.left||menuRect.left>=triggerRect.right||menuRect.bottom<=triggerRect.top||menuRect.top>=triggerRect.bottom),
					left:menuRect.left,
					right:menuRect.right,
					viewportWidth:innerWidth,
					menuOverflow:Math.max(0,menu.scrollWidth-menu.clientWidth),
					documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
				};
			});
			assert(mobileTableMenu&&mobileTableMenu.controls.length>=6,'Open mobile table row menu fixture is incomplete.');
			assert(mobileTableMenu.detailsDisplay==='grid'&&mobileTableMenu.menuPosition==='static'&&!mobileTableMenu.overlapsTrigger,'Mobile row action menu does not stack beneath its trigger: '+JSON.stringify(mobileTableMenu)+'.');
			assert(mobileTableMenu.controls.every(control=>control.control>0&&Math.abs(control.child-control.control)<=2),'Mobile row menu controls do not fill their wrapped rows: '+JSON.stringify(mobileTableMenu.controls)+'.');
			assert(mobileTableMenu.left>=0&&mobileTableMenu.right<=mobileTableMenu.viewportWidth&&mobileTableMenu.menuOverflow===0&&mobileTableMenu.documentOverflow===0,'Mobile row action menu creates horizontal overflow: '+JSON.stringify(mobileTableMenu)+'.');

			await applyTheme(page,'glass','dark');
			await open(page,baseUrl+'/orders/board?panel_theme=glass',{width:557,height:740});
			await page.click('.dp-panel-board-card .dp-panel-row-more>summary');
			await page.waitForSelector('.dp-panel-board-card .dp-panel-row-more[open]>.dp-panel-row-more-menu',{visible:true,timeout:10000});
			const board=await page.evaluate(()=>{
				const details=document.querySelector('.dp-panel-board-card .dp-panel-row-more[open]');
				const menu=details&&details.querySelector(':scope>.dp-panel-row-more-menu');
				const card=details&&details.closest('.dp-panel-board-card');
				const column=card&&card.closest('.dp-panel-board-column');
				const header=column&&column.querySelector(':scope>header');
				if(!menu||!column||!header){return null;}
				const backgroundFor=element=>{
					let current=element;
					while(current){
						const background=getComputedStyle(current).backgroundColor;
						if(background&&background!=='transparent'&&background!=='rgba(0, 0, 0, 0)'){return background;}
						current=current.parentElement;
					}
					return 'rgb(0, 0, 0)';
				};
				const actions=Array.from(menu.querySelectorAll('.dp-panel-action,.dp-panel-row-link')).filter(element=>{
					const rect=element.getBoundingClientRect();
					return !element.closest('.dp-panel-action-menu')&&rect.width>0&&rect.height>0;
				}).map(element=>{
					const rect=element.getBoundingClientRect();
					const label=element.querySelector('.dp-panel-action-label,.dp-panel-action-copy')||element;
					return {
						label:(label.textContent||'').trim().replace(/\s+/g,' '),
						width:rect.width,
						height:rect.height,
						color:getComputedStyle(label).color,
						background:backgroundFor(element),
					};
				});
				const cardLabels=Array.from(card.querySelectorAll(':scope>.dp-panel-actions>.dp-panel-row-link,:scope>.dp-panel-actions>.dp-panel-row-more>summary')).map(element=>{
					const label=element.querySelector('.dp-panel-action-label')||element;
					return {label:(label.textContent||'').trim(),labelColor:getComputedStyle(label).color,controlColor:getComputedStyle(element).color};
				});
				const headerStyle=getComputedStyle(header);
				return {
					actions,
					cardLabels,
					menuOverflow:Math.max(0,menu.scrollWidth-menu.clientWidth),
					columnBefore:getComputedStyle(column,'::before').content,
					headerRadii:[headerStyle.borderTopLeftRadius,headerStyle.borderTopRightRadius,headerStyle.borderBottomRightRadius,headerStyle.borderBottomLeftRadius],
					documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
				};
			});
			assert(board&&board.actions.length>=6,'Open board menu fixture is incomplete.');
			const widths=board.actions.map(item=>item.width);
			assert(Math.max(...widths)-Math.min(...widths)<=2,'Board menu actions do not share a full-width geometry: '+JSON.stringify(board.actions.map(item=>({label:item.label,width:item.width})))+'.');
			assert(board.actions.every(item=>item.height>=43&&item.height<=45),'Board menu action heights are not normalized to the 44px touch target.');
			assert(board.actions.some(item=>item.label.startsWith('Ops')),'Nested Ops action is missing from the board menu geometry audit.');
			assert(board.actions.every(item=>contrastRatio(item.color,item.background)>=3),'One or more board menu labels are below 3:1 contrast.');
			assert(board.cardLabels.length===3&&board.cardLabels.every(item=>item.labelColor===item.controlColor),'Closed board action labels do not inherit their normalized Glass control color: '+JSON.stringify(board.cardLabels)+'.');
			assert(board.menuOverflow===0&&board.documentOverflow===0,'Open board menu creates horizontal overflow.');
			assert(board.columnBefore==='none','Opening More exposes a decorative board-column stripe.');
			assert(board.headerRadii.every(value=>parseFloat(value)===0),'Glass board header retains rounded corners.');
			await page.click('.dp-panel-board-card .dp-panel-row-more[open] .dp-panel-action-group>summary');
			await page.waitForSelector('.dp-panel-board-card .dp-panel-row-more[open] .dp-panel-action-group[open]>.dp-panel-action-menu',{visible:true,timeout:10000});
			await new Promise(resolve=>setTimeout(resolve,100));
			const nested=await page.evaluate(()=>{
				const outer=document.querySelector('.dp-panel-board-card .dp-panel-row-more[open]');
				const inner=outer&&outer.querySelector('.dp-panel-action-group[open]');
				const outerMenu=outer&&outer.querySelector(':scope>.dp-panel-row-more-menu');
				const innerMenu=inner&&inner.querySelector(':scope>.dp-panel-action-menu');
				if(!outer||!inner||!outerMenu||!innerMenu){return null;}
				const box=element=>{const rect=element.getBoundingClientRect();return {left:rect.left,top:rect.top,right:rect.right,bottom:rect.bottom,width:rect.width,height:rect.height,overflowX:Math.max(0,element.scrollWidth-element.clientWidth)};};
				return {outerOpen:outer.open,innerOpen:inner.open,outer:box(outerMenu),inner:box(innerMenu),viewportHeight:innerHeight,documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth)};
			});
			assert(nested&&nested.outerOpen&&nested.innerOpen,'Opening nested Ops collapsed its parent More disclosure.');
			assert(nested.outer.top>=8&&nested.outer.bottom<=nested.viewportHeight-8,'Nested Ops growth moved the parent menu outside the viewport: '+JSON.stringify(nested.outer)+'.');
			assert(nested.inner.width<=nested.outer.width&&nested.outer.overflowX===0&&nested.documentOverflow===0,'Nested Ops creates horizontal overflow: '+JSON.stringify(nested)+'.');
			await page.focus('.dp-panel-board-card .dp-panel-row-more[open] .dp-panel-action-group>summary');
			await page.keyboard.press('ArrowDown');
			await page.waitForFunction(()=>document.querySelector('.dp-panel-row-more[open] .dp-panel-action-group[open]>.dp-panel-action-menu')?.contains(document.activeElement),{timeout:10000});
			await page.keyboard.press('Escape');
			assert(await page.evaluate(()=>{
				const outer=document.querySelector('.dp-panel-row-more[open]');
				const summary=outer&&outer.querySelector('.dp-panel-action-group>summary');
				return !!outer&&!outer.querySelector('.dp-panel-action-group[open]')&&document.activeElement===summary;
			}),'First Escape did not close only Ops and restore its summary focus.');
			await page.keyboard.press('Escape');
			assert(await page.evaluate(()=>!document.querySelector('.dp-panel-row-more[open]')&&document.activeElement?.matches('.dp-panel-row-more>summary')),'Second Escape did not close More and restore its summary focus.');
			await page.click('.dp-panel-board-card .dp-panel-row-more>summary');
			await page.click('.dp-panel-board-card .dp-panel-row-more[open] .dp-panel-action-group>summary');
			const otherTriggers=await page.$$('.dp-panel-board-card .dp-panel-row-more>summary');
			assert(otherTriggers.length>1,'A second board More trigger is required for disclosure exclusivity coverage.');
			await otherTriggers[1].click();
			await page.waitForFunction(()=>document.querySelectorAll('.dp-panel-board-card .dp-panel-row-more[open]').length===1);
			assert(await page.evaluate(()=>document.querySelectorAll('.dp-panel-board-card .dp-panel-row-more[open] .dp-panel-action-group[open]').length===0),'Opening an unrelated More retained the previous nested disclosure chain.');
			return {chrome,tableMenu,mobileTableMenu,board,nested};
		});

		await probe('board action labels inherit readable control colors across shipped themes',async page=>{
			const results=[];
			for(const theme of ['flat_minima','brutalist']){
				await applyTheme(page,theme,'dark');
				await open(page,baseUrl+'/orders/board?panel_theme='+encodeURIComponent(theme),{width:1024,height:800});
				const labels=await page.evaluate(()=>Array.from(document.querySelector('.dp-panel-board-card')?.querySelectorAll(':scope>.dp-panel-actions>.dp-panel-row-link,:scope>.dp-panel-actions>.dp-panel-row-more>summary')||[]).map(control=>{
					const label=control.querySelector('.dp-panel-action-label')||control;
					return {text:(label.textContent||'').trim(),labelColor:getComputedStyle(label).color,controlColor:getComputedStyle(control).color};
				}));
				assert(labels.length===3&&labels.every(item=>item.labelColor===item.controlColor),theme+' board labels do not inherit their control color: '+JSON.stringify(labels)+'.');
				results.push({theme,labels});
			}
			return results;
		});

		await probe('light glass row and nested menu actions keep WCAG text contrast',async page=>{
			await applyTheme(page,'glass','light');
			await open(page,baseUrl+'/orders/board?panel_theme=glass',{width:1024,height:800});
			await page.click('.dp-panel-board-card .dp-panel-row-more>summary');
			await page.click('.dp-panel-board-card .dp-panel-row-more[open] .dp-panel-action-group>summary');
			await page.waitForSelector('.dp-panel-row-more[open] .dp-panel-action-group[open]>.dp-panel-action-menu',{visible:true,timeout:10000});
			const actions=await page.evaluate(()=>{
				const parseBackground=value=>{
					const numbers=String(value||'').match(/[\d.]+/g)?.map(Number)||[];
					if(value.startsWith('color(srgb')&&numbers.length>=3){return {rgb:numbers.slice(0,3).map(channel=>channel*255),alpha:numbers[3]??1};}
					if(value.startsWith('rgb')&&numbers.length>=3){return {rgb:numbers.slice(0,3),alpha:numbers[3]??1};}
					return null;
				};
				const backgroundFor=element=>{
					const layers=[];
					for(let current=element;current;current=current.parentElement){
						const layer=parseBackground(getComputedStyle(current).backgroundColor);
						if(layer&&layer.alpha>0){layers.push(layer);if(layer.alpha>=1){break;}}
					}
					let result=[255,255,255];
					for(const layer of layers.reverse()){result=result.map((channel,index)=>layer.rgb[index]*layer.alpha+channel*(1-layer.alpha));}
					return 'rgb('+result.map(Math.round).join(', ')+')';
				};
				return Array.from(document.querySelectorAll('.dp-panel-row-more[open] :is(.dp-panel-action,.dp-panel-row-link)')).filter(element=>{
					const rect=element.getBoundingClientRect();
					return rect.width>0&&rect.height>0&&!element.matches('[aria-disabled="true"],.dp-panel-action-disabled,:disabled');
				}).map(element=>{
					const label=element.querySelector('.dp-panel-action-label')||element;
					return {label:(label.textContent||'').trim().replace(/\s+/g,' '),color:getComputedStyle(label).color,background:backgroundFor(element)};
				});
			});
			assert(actions.length>=9,'Light glass action contrast fixture is incomplete.');
			assert(actions.every(action=>contrastRatio(action.color,action.background)>=4.5),'Light glass menu action contrast falls below 4.5:1: '+JSON.stringify(actions.map(action=>({...action,contrast:contrastRatio(action.color,action.background)})))+'.');
			return actions;
		});

		await probe('in-place actions preserve the viewport around their result',async page=>{
			await open(page,baseUrl+'/orders/create',{width:1024,height:800});
			const responseHtml=await page.content();
			await page.setRequestInterception(true);
			const intercept=request=>{
				if(request.method()==='POST'){
					request.respond({status:200,contentType:'application/json',body:JSON.stringify({html:responseHtml,title:'Create Order'})});
					return;
				}
				request.continue();
			};
			page.on('request',intercept);
			const viewport=await page.evaluate(()=>new Promise((resolve,reject)=>{
				const panel=document.querySelector('main.dp-panel');
				const form=document.createElement('form');
				form.method='post';
				form.action=location.href;
				const button=document.createElement('button');
				button.type='submit';
				button.textContent='Synthetic action';
				form.appendChild(button);
				panel.appendChild(form);
				window.scrollTo(0,Math.min(2600,document.documentElement.scrollHeight-innerHeight-40));
				const before=Math.round(window.scrollY);
				const timeout=setTimeout(()=>reject(new Error('Synthetic action refresh timed out.')),8000);
				document.addEventListener('dataphyre:panel-refresh',()=>{
					setTimeout(()=>{clearTimeout(timeout);resolve({before,after:Math.round(window.scrollY)});},80);
				},{once:true});
				form.dispatchEvent(new SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:button}));
			}));
			page.off('request',intercept);
			await page.setRequestInterception(false);
			assert(viewport.before>0,'Viewport fixture did not scroll away from the page origin.');
			assert(Math.abs(viewport.after-viewport.before)<=2,'In-place action moved the viewport from '+viewport.before+' to '+viewport.after+'.');
			return viewport;
		});

		await probe('runtime controllers replace global listeners without duplication',async page=>{
			await open(page,baseUrl,{width:390,height:844});
			const lifecycle=await page.evaluate(async()=>{
				const before=window.DataphyrePanel?.runtimeController;
				const physical=Array.from(document.querySelectorAll('script[src*="dp_panel_asset=panel-runtime-"]'));
				const scripts=physical.length>0?physical:Array.from(document.querySelectorAll('script[src*="dp_panel_asset=panel.js"]'));
				if(!before||scripts.length===0){return {error:'Runtime controller or script assets are missing.'};}
				const beforeListenerCount=Object.values(before.controllers||{}).reduce((sum,controller)=>sum+(controller.listenerCount||0),0);
				const capabilities=String(document.querySelector('main.dp-panel')?.dataset.dpPanelAssets||'').split(/\s+/).filter(Boolean);
				const sources=[];
				for(const script of scripts){
					const url=new URL(script.src,location.href);
					url.searchParams.set('dp_runtime_reload',String(Date.now())+'-'+sources.length);
					const response=await fetch(url);
					if(!response.ok){return {error:'Runtime asset reload failed with HTTP '+response.status+'.'};}
					sources.push(await response.text());
				}
				for(const source of sources){(0,eval)(source);}
				await new Promise(resolve=>setTimeout(resolve,80));
				const after=window.DataphyrePanel?.runtimeController;
				const controllers=Object.keys(after?.controllers||{}).sort();
				const listenerCount=Object.values(after?.controllers||{}).reduce((sum,controller)=>sum+(controller.listenerCount||0),0);
				const globalsHidden=typeof window.dpPanelText==='undefined'&&typeof window.dpPanelOpenModal==='undefined'&&typeof window.dpPanelAjaxLoad==='undefined';
				const chunks=Object.fromEntries(Object.entries(window.DataphyrePanel?.runtimeChunks||{}).map(([name,entry])=>[name,{status:entry?.status,dependencies:entry?.dependencies,exports:entry?.exports}]));
				return {replaced:before!==after,previousDisposed:before.disposed===true,previousSignalAborted:before.signal?.aborted===true,controllers,listenerCount,beforeListenerCount,capabilities,globalsHidden,delivery:physical.length>0?'physical':'aggregate',runtimeAssets:scripts.length,chunks};
			});
			assert(!lifecycle.error,lifecycle.error);
			assert(lifecycle.replaced && lifecycle.previousDisposed && lifecycle.previousSignalAborted,'Previous runtime listeners were not disposed.');
			assert(lifecycle.listenerCount>0&&lifecycle.listenerCount===lifecycle.beforeListenerCount,'Managed listener ownership changed across replacement: '+lifecycle.beforeListenerCount+' to '+lifecycle.listenerCount+'.');
			assert(lifecycle.globalsHidden,'Private controller functions leaked onto window.');
			if(lifecycle.delivery==='physical'){
				assert(lifecycle.runtimeAssets>=4&&Object.values(lifecycle.chunks).every(chunk=>chunk.status==='ready'&&Array.isArray(chunk.dependencies)&&Array.isArray(chunk.exports)),'Physical runtime registry did not remain dependency-complete after replacement: '+JSON.stringify(lifecycle.chunks)+'.');
			}
			const required=['accessibility','ajax','command','foundation','navigation','state_table','theme','validation_upload'];
			if(lifecycle.capabilities.includes('form')){required.push('fields');}
			if(lifecycle.capabilities.includes('editor')){required.push('editor');}
			if(lifecycle.capabilities.includes('modal')){required.push('modal');}
			if(lifecycle.capabilities.includes('board')){required.push('board');}
			for(const controller of required){
				assert(lifecycle.controllers.includes(controller),'Missing '+controller+' runtime controller.');
			}
			await page.click('.dp-panel-mobile-nav-toggle');
			await page.waitForFunction(()=>document.querySelector('main.dp-panel')?.classList.contains('dp-panel-mobile-nav-open'));
			await page.keyboard.press('Escape');
			await page.waitForFunction(()=>!document.querySelector('main.dp-panel')?.classList.contains('dp-panel-mobile-nav-open'));
			return lifecycle;
		});

		await probe('signed Data Surface windows page exactly once, preserve roving focus, and release removed roots',async page=>{
			await open(page,baseUrl,{width:1180,height:820});
			const unrelated=await page.evaluate(()=>({
				assets:String(document.querySelector('main.dp-panel')?.dataset.dpPanelAssets||'').split(/\s+/).filter(Boolean),
				runtime:typeof window.DataphyrePanel?.dataSurfaceRuntime,
				surfaces:document.querySelectorAll('[data-dp-data-surface]').length,
			}));
			assert(!unrelated.assets.includes('data-surface')&&unrelated.runtime==='undefined'&&unrelated.surfaces===0,'An unrelated shell loaded the optional Data Surface capability.');

			await open(page,baseUrl+'?resource=data_surface_lab',{width:1180,height:820});
			const surfaceSelector='[data-dp-data-surface]';
			await page.waitForSelector(surfaceSelector+'[data-dp-data-surface-enhanced="1"]',{visible:true,timeout:10000});
			const readSurface=()=>page.$eval(surfaceSelector,root=>({
				assets:String(document.querySelector('main.dp-panel')?.dataset.dpPanelAssets||'').split(/\s+/).filter(Boolean),
				enhanced:root.dataset.dpDataSurfaceEnhanced==='1',
				busy:root.getAttribute('aria-busy'),
				status:root.querySelector('[data-dp-data-surface-status]')?.textContent||'',
				rows:Array.from(root.querySelectorAll('[data-dp-data-surface-item]')).map(item=>({key:item.getAttribute('data-key'),position:Number(item.getAttribute('data-position')),visible:item.getAttribute('data-visible')==='1',tabIndex:item.tabIndex})),
				nextHidden:root.querySelector('[data-dp-data-surface-intent="next"]')?.hidden,
				nextDisabled:root.querySelector('[data-dp-data-surface-intent="next"]')?.disabled,
				runtimeCount:window.DataphyrePanel?.dataSurfaceRuntime?.count?.()||0,
				listenerCount:window.DataphyrePanel?.runtimeController?.controllers?.data_surface?.listenerCount||0,
			}));
			const initial=await readSurface();
			assert(initial.assets.includes('data-surface')&&initial.enhanced&&initial.runtimeCount===1,'The Data Surface page did not select and own its optional runtime.');
			assert(initial.rows.length===6&&initial.rows.filter(row=>row.visible).length===5&&initial.rows[0].position===0,'The initial signed window lost its five visible records plus bounded overscan.');
			assert(initial.status==='5 of 16 records shown.'&&initial.busy==='false'&&!initial.nextHidden&&!initial.nextDisabled,'The initial Data Surface controls or status are not ready.');
			assert(initial.listenerCount===1,'The Data Surface runtime owns '+initial.listenerCount+' global listeners instead of its single teardown listener.');

			let windowRequests=0;
			const countWindow=request=>{
				if(request.method()==='POST'&&request.url().includes('dp_panel_data_surface=1')){windowRequests++;}
			};
			page.on('request',countWindow);
			const nextResponsePromise=page.waitForResponse(response=>response.request().method()==='POST'&&response.url().includes('dp_panel_data_surface=1'),{timeout:10000});
			await page.click(surfaceSelector+' [data-dp-data-surface-intent="next"]');
			const nextResponse=await nextResponsePromise;
			const nextBody=await nextResponse.json();
			await page.waitForFunction((selector,position)=>Number(document.querySelector(selector+' [data-dp-data-surface-item]')?.getAttribute('data-position'))===position,{timeout:10000},surfaceSelector,nextBody.records?.[0]?.position);
			await new Promise(resolve=>setTimeout(resolve,250));
			page.off('request',countWindow);
			const afterNext=await readSurface();
			assert(nextResponse.status()===200&&nextBody.type==='panel_data_surface_window'&&nextBody.version===1&&nextBody.definition==='browser_orders_surface','The signed Data Surface response broke its public window envelope.');
			assert(windowRequests===1&&afterNext.rows[0].position===nextBody.records[0].position&&afterNext.rows[0].position>initial.rows[0].position,'Next dispatched more than once or failed to commit the returned window.');

			await page.focus(surfaceSelector+' [data-dp-data-surface-item]');
			await page.keyboard.press('End');
			const roving=await page.evaluate(selector=>{
				const root=document.querySelector(selector),items=Array.from(root.querySelectorAll('[data-dp-data-surface-item]'));
				return {activeKey:document.activeElement?.getAttribute('data-key'),lastKey:items.at(-1)?.getAttribute('data-key'),tabStops:items.filter(item=>item.tabIndex===0).length};
			},surfaceSelector);
			assert(roving.activeKey===roving.lastKey&&roving.tabStops===1,'End did not preserve one roving Data Surface tab stop.');

			const reload=await page.evaluate(async selector=>{
				const beforeRuntime=window.DataphyrePanel?.dataSurfaceRuntime;
				const beforeController=window.DataphyrePanel?.runtimeController;
				const physical=Array.from(document.querySelectorAll('script[src*="dp_panel_asset=panel-runtime-"]'));
				const scripts=physical.length>0?physical:Array.from(document.querySelectorAll('script[src*="dp_panel_asset=panel.js"]'));
				const root=document.querySelector(selector);
				if(!beforeRuntime||!beforeController||scripts.length===0||!root){throw new Error('Data Surface hot-reload fixture is incomplete.');}
				const beforePosition=Number(root.querySelector('[data-dp-data-surface-item]')?.getAttribute('data-position'));
				const sources=[];
				for(const script of scripts){
					const url=new URL(script.src,location.href);
					url.searchParams.set('dp_surface_reload',String(Date.now())+'-'+sources.length);
					const response=await fetch(url);
					if(!response.ok){throw new Error('Data Surface runtime asset could not be reloaded: HTTP '+response.status+'.');}
					sources.push(await response.text());
				}
				for(const source of sources){(0,eval)(source);}
				await new Promise(resolve=>setTimeout(resolve,100));
				const afterRuntime=window.DataphyrePanel?.dataSurfaceRuntime;
				return {
					replaced:beforeRuntime!==afterRuntime,
					previousDisposed:beforeRuntime.disposed===true,
					previousControllerDisposed:beforeController.disposed===true,
					previousSignalAborted:beforeController.signal?.aborted===true,
					beforePosition,
					afterPosition:Number(root.querySelector('[data-dp-data-surface-item]')?.getAttribute('data-position')),
					enhanced:root.dataset.dpDataSurfaceEnhanced==='1',
					count:afterRuntime?.count?.()||0,
					listeners:window.DataphyrePanel?.runtimeController?.controllers?.data_surface?.listenerCount||0,
					delivery:physical.length>0?'physical':'aggregate',
					runtimeAssets:scripts.length,
				};
			},surfaceSelector);
			assert(reload.replaced&&reload.previousDisposed&&reload.previousControllerDisposed&&reload.previousSignalAborted,'Data Surface hot reload did not dispose both managed runtime generations.');
			assert(reload.enhanced&&reload.count===1&&reload.listeners===1&&reload.afterPosition===reload.beforePosition,'Data Surface hot reload lost the live window or duplicated lifecycle ownership.');

			await page.evaluate(selector=>{const root=document.querySelector(selector);root.parentElement.appendChild(root);},surfaceSelector);
			await new Promise(resolve=>setTimeout(resolve,100));
			const afterMove=await readSurface();
			assert(afterMove.enhanced&&afterMove.runtimeCount===1,'A same-document Data Surface move disposed live state.');
			await page.evaluate(selector=>{window.__dpRemovedDataSurface=document.querySelector(selector);window.__dpRemovedDataSurface.remove();},surfaceSelector);
			await page.waitForFunction(()=>window.DataphyrePanel?.dataSurfaceRuntime?.count?.()===0,{timeout:10000});
			const teardown=await page.evaluate(()=>({connected:window.__dpRemovedDataSurface?.isConnected===true,enhanced:window.__dpRemovedDataSurface?.dataset.dpDataSurfaceEnhanced==='1'}));
			assert(!teardown.connected&&!teardown.enhanced,'Terminal Data Surface removal retained enhanced state.');
			return {unrelated,initial,afterNext,windowRequests,roving,reload,afterMove,teardown};
		});

		await probe('Studio Editor keyboard edits preserve focus, submit same-origin commands, and dispose remounted roots',async page=>{
			await page.emulateMediaFeatures([{name:'prefers-reduced-motion',value:'reduce'}]);
			const commandRequests=[];
			const interception=await installRequestFixtures(page,request=>{
				const url=new URL(request.url());
				if(request.method()!=='POST'||url.pathname!=='/studio/edit'){return null;}
				commandRequests.push({url:request.url(),method:request.method(),headers:request.headers(),body:request.postData()||''});
				return {status:200,contentType:'text/html; charset=utf-8',body:'<!doctype html><html><body><p data-dp-studio-command-received>Studio command received.</p></body></html>'};
			});
			const studioUrl=baseUrl+'?dp_panel_studio_editor=1';
			const openStudio=async()=>{
				await page.setViewport({width:1440,height:1000,deviceScaleFactor:1});
				const response=await page.goto(studioUrl,{waitUntil:'domcontentloaded',timeout:30000});
				assert(response&&response.status()===200,'Studio showroom did not return HTTP 200.');
				await page.waitForSelector('[data-dp-studio-editor][data-dp-studio-enhanced="true"]',{visible:true,timeout:10000});
			};
			try{
				await openStudio();
				const initial=await page.evaluate(()=>{
					const root=document.querySelector('[data-dp-studio-editor]');
					const seconds=value=>String(value||'').split(',').map(part=>part.trim().endsWith('ms')?parseFloat(part)/1000:parseFloat(part)||0);
					const retained=[];
					for(const element of document.querySelectorAll('.dp-studio,.dp-studio *')){
						for(const pseudo of [null,'::before','::after']){
							const style=getComputedStyle(element,pseudo),animation=Math.max(0,...seconds(style.animationDuration)),transition=Math.max(0,...seconds(style.transitionDuration));
							if(animation>.01||transition>.01){retained.push({tag:element.tagName,pseudo,animation,transition});}
						}
					}
					return {
						enhanced:root?.dataset.dpStudioEnhanced==='true',runtimeCount:window.DataphyrePanelStudioEditor?.count?.()||0,
						treeitems:root?.querySelectorAll('[role="treeitem"]').length||0,roving:root?.querySelectorAll('[role="treeitem"][tabindex="0"]').length||0,
						labelledPanes:Array.from(root?.querySelectorAll('[data-dp-studio-panel]')||[]).every(panel=>{const id=panel.getAttribute('aria-labelledby');return !!(id&&document.getElementById(id));}),
						labelledFields:Array.from(root?.querySelectorAll('.dp-studio-field input:not([type="hidden"]),.dp-studio-field select,.dp-studio-field textarea,.dp-studio-collaboration input,.dp-studio-collaboration select,.dp-studio-collaboration textarea')||[]).every(control=>control.labels&&control.labels.length>0),
						live:!!root?.querySelector('[aria-live="polite"]'),reducedMotion:matchMedia('(prefers-reduced-motion: reduce)').matches,retained:retained.slice(0,8),retainedCount:retained.length,
						previewDisabled:root?.querySelector('[data-dp-studio-submit="preview"]')?.disabled,action:new URL(root?.querySelector('[data-dp-studio-command-form]')?.action||'',location.href).href,
						collaboration:{present:!!root?.querySelector('[data-dp-studio-collaboration]'),threads:root?.querySelectorAll('.dp-studio-thread').length||0,comments:root?.querySelectorAll('.dp-studio-comment').length||0,owner:root?.querySelector('[name="studio_collaboration_assignee"]')?.value||'',watching:!!root?.querySelector('[name="studio_collaboration_operation"][value="unwatch"]'),verified:!!root?.querySelector('.dp-studio-integrity[data-valid="true"]')},
					};
				});
				assert(initial.enhanced&&initial.runtimeCount===1,'Studio did not mount exactly one runtime instance.');
				assert(initial.treeitems>=8&&initial.roving===1&&initial.labelledPanes&&initial.labelledFields&&initial.live,'Studio accessibility semantics are incomplete: '+JSON.stringify(initial));
				assert(initial.reducedMotion&&initial.retainedCount===0,'Studio reduced-motion mode retained long motion: '+JSON.stringify(initial.retained));
				assert(initial.previewDisabled===false&&new URL(initial.action).origin===new URL(baseUrl).origin,'Studio command form is not a clean same-origin preview-capable surface.');
				assert(initial.collaboration.present&&initial.collaboration.threads===1&&initial.collaboration.comments===1&&initial.collaboration.owner==='mina'&&initial.collaboration.watching&&initial.collaboration.verified,'Studio collaboration workspace is incomplete: '+JSON.stringify(initial.collaboration));
				const collaborationSubmission=await page.evaluate(()=>new Promise(resolve=>{
					const root=document.querySelector('[data-dp-studio-editor]'),form=root.querySelector('[data-dp-studio-command-form]');
					form.addEventListener('submit',event=>{const data=new FormData(form);resolve({operation:event.submitter?.value||null,csrfBound:data.get('_token')==='C'.repeat(32),definitionBytes:String(data.get('studio_definition_json')||'').length,nativePrevented:event.defaultPrevented,submitterDisabled:event.submitter?.disabled===true});},{once:true});
					root.querySelector('[name="studio_collaboration_operation"][value="unwatch"]').click();
				}));
				assert(collaborationSubmission.operation==='unwatch'&&collaborationSubmission.csrfBound&&collaborationSubmission.definitionBytes>100&&collaborationSubmission.nativePrevented&&collaborationSubmission.submitterDisabled,'Studio collaboration operation was not bound to the trusted managed editor form: '+JSON.stringify(collaborationSubmission));

				const renamed=await page.evaluate(()=>{
					const input=document.querySelector('[data-dp-studio-key]');input.focus();input.value='contact_email';input.dispatchEvent(new Event('change',{bubbles:true}));
					return {active:document.activeElement?.dataset.dpStudioFocus||'',selection:document.querySelector('[name="studio_selected_path"]')?.value||'',value:document.querySelector('[data-dp-studio-key]')?.value||''};
				});
				assert(renamed.active==='property:orders/order_form/identity/contact_email:key'&&renamed.selection.endsWith('/contact_email')&&renamed.value==='contact_email','Inspector rename did not preserve focus and selection.');

				const market='[data-dp-studio-treeitem][data-dp-studio-path="orders/order_form/identity/market"]';
				await page.focus(market);await page.keyboard.down('Alt');await page.keyboard.press('ArrowUp');await page.keyboard.up('Alt');
				const childKeys=()=>page.$eval('[name="studio_definition_json"]',field=>{const model=JSON.parse(field.value),identity=model.children[0].children.find(child=>child.key==='identity');return identity.children.map(child=>child.key);});
				const afterMove=await childKeys();
				assert(afterMove.join(',')==='name,market,contact_email','Alt+ArrowUp did not deterministically reorder Studio siblings.');
				await page.keyboard.down('Control');await page.keyboard.press('z');await page.keyboard.up('Control');
				const afterUndo=await childKeys();
				assert(afterUndo.join(',')==='name,contact_email,market','Ctrl+Z did not undo the keyboard reorder while retaining the earlier inspector edit.');

				await page.focus('[data-dp-studio-submit="save"]');
				await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded',timeout:10000}),page.keyboard.press('Enter')]);
				assert(commandRequests.length===1,'Studio save emitted '+commandRequests.length+' requests.');
				const save=commandRequests[0],savePayload=new URLSearchParams(save.body),savedDefinition=JSON.parse(savePayload.get('studio_definition_json')||'null');
				assert(save.method==='POST'&&new URL(save.url).origin===new URL(baseUrl).origin&&new URL(save.url).pathname==='/studio/edit','Studio save was not a same-origin POST.');
				assert(String(save.headers['content-type']||'').startsWith('application/x-www-form-urlencoded'),'Studio save did not use a deterministic form request.');
				assert(savePayload.get('studio_action')==='save'&&savePayload.get('_token')==='C'.repeat(32)&&savePayload.get('studio_selected_path')?.endsWith('/contact_email'),'Studio save omitted its action, CSRF, or selected-path contract.');
				assert(savedDefinition?.children?.[0]?.children?.[0]?.children?.some(child=>child.key==='contact_email'),'Studio save did not serialize the live trusted definition.');

				await openStudio();
				await page.focus('[data-dp-studio-submit="preview"]');
				await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded',timeout:10000}),page.keyboard.press('Enter')]);
				assert(commandRequests.length===2,'Studio preview emitted '+(commandRequests.length-1)+' requests instead of one.');
				const preview=commandRequests[1],previewPayload=new URLSearchParams(preview.body);
				assert(preview.method==='POST'&&new URL(preview.url).origin===new URL(baseUrl).origin&&new URL(preview.url).pathname==='/studio/edit'&&previewPayload.get('studio_action')==='preview','Studio preview was not a same-origin POST command.');
				assert(previewPayload.get('_token')==='C'.repeat(32)&&Number(previewPayload.get('studio_base_revision'))>=1&&String(previewPayload.get('studio_base_hash')||'').length===64,'Studio preview omitted its CSRF or revision binding.');

				await openStudio();
				const lifecycle=await page.evaluate(async()=>{
					const waitFor=async predicate=>{for(let attempt=0;attempt<100;attempt++){if(predicate()){return true;}await new Promise(resolve=>setTimeout(resolve,20));}return false;};
					const api=window.DataphyrePanelStudioEditor,root=document.querySelector('[data-dp-studio-editor]'),host=root.parentElement,replacement=root.cloneNode(true);
					replacement.removeAttribute('data-dp-studio-enhanced');root.remove();
					const disposed=await waitFor(()=>api.count()===0&&!root.hasAttribute('data-dp-studio-enhanced'));
					host.appendChild(replacement);const remounted=await waitFor(()=>api.count()===1&&replacement.dataset.dpStudioEnhanced==='true');
					host.appendChild(replacement);await new Promise(resolve=>setTimeout(resolve,60));const moved=api.count()===1&&replacement.dataset.dpStudioEnhanced==='true';
					const dormant=replacement.cloneNode(true);dormant.removeAttribute('data-dp-studio-enhanced');api.stop();const stopped=api.count()===0&&!replacement.hasAttribute('data-dp-studio-enhanced');replacement.replaceWith(dormant);await new Promise(resolve=>setTimeout(resolve,80));const stayedDormant=api.count()===0&&!dormant.hasAttribute('data-dp-studio-enhanced');api.start();const restarted=await waitFor(()=>api.count()===1&&dormant.dataset.dpStudioEnhanced==='true');
					return {disposed,remounted,moved,stopped,stayedDormant,restarted,count:api.count()};
				});
				assert(lifecycle.disposed&&lifecycle.remounted&&lifecycle.moved,'Studio dynamic removal, remount, or same-turn move lifecycle failed: '+JSON.stringify(lifecycle));
				assert(lifecycle.stopped&&lifecycle.stayedDormant&&lifecycle.restarted&&lifecycle.count===1,'Studio stop/start did not dispose and restart observation exactly once: '+JSON.stringify(lifecycle));
				return {initial,collaborationSubmission,renamed,afterMove,afterUndo,requests:{count:commandRequests.length,saveAction:savePayload.get('studio_action'),previewAction:previewPayload.get('studio_action')},lifecycle};
			}
			finally{await interception.dispose();}
		});

		await probe('Studio live collaboration converges across tabs without losing drafts or replaying mutations',async page=>{
			const studioUrl=baseUrl+'?dp_panel_studio_editor=1';
			const collaborationEndpoint=request=>{
				const url=new URL(request.url());
				return request.method()==='POST'&&url.searchParams.get('dp_panel_studio_collaboration')==='1';
			};
			const collaborationAction=request=>{
				const body=request.postData()||'';
				const match=body.match(/name="studio_collaboration_transport_action"\r?\n\r?\n([a-z_]+)/);
				return match?match[1]:'';
			};
			const primaryRequests=[];
			const peerRequests=[];
			const peerConsoleErrors=[];
			const onPrimaryRequest=request=>{if(collaborationEndpoint(request)){primaryRequests.push({action:collaborationAction(request),body:request.postData()||'',url:request.url()});}};
			const context=page.browserContext();
			const peer=await context.newPage();
			const onPeerRequest=request=>{if(collaborationEndpoint(request)){peerRequests.push({action:collaborationAction(request),body:request.postData()||'',url:request.url()});}};
			const onPeerConsole=message=>{if(message.type()==='error'){const location=message.location();peerConsoleErrors.push(message.text()+(location.url?' @ '+location.url:''));}};
			const onPeerError=error=>peerConsoleErrors.push(String(error&&error.message||error));
			page.on('request',onPrimaryRequest);
			peer.on('request',onPeerRequest);
			peer.on('console',onPeerConsole);
			peer.on('pageerror',onPeerError);
			try{
				await page.setViewport({width:1440,height:1000,deviceScaleFactor:1});
				await peer.setViewport({width:1180,height:900,deviceScaleFactor:1});
				const primaryResponse=await page.goto(studioUrl,{waitUntil:'domcontentloaded',timeout:30000});
				assert(primaryResponse&&primaryResponse.status()===200,'Primary Studio collaboration tab did not return HTTP 200.');
				await page.waitForSelector('[data-dp-studio-editor][data-dp-studio-enhanced="true"]',{visible:true,timeout:10000});
				const peerResponse=await peer.goto(studioUrl,{waitUntil:'domcontentloaded',timeout:30000});
				assert(peerResponse&&peerResponse.status()===200,'Peer Studio collaboration tab did not return HTTP 200.');
				await peer.waitForSelector('[data-dp-studio-editor][data-dp-studio-enhanced="true"]',{visible:true,timeout:10000});
				await peer.bringToFront();
				await peer.waitForFunction(()=>document.visibilityState==='visible'&&document.querySelector('[data-dp-studio-editor]')?.dataset.dpStudioCollaborationState==='live',{timeout:10000});

				const draft='Unsaved draft must survive a remote delta.';
				const peerBefore=await peer.$eval('[data-dp-studio-collaboration]',root=>{
					const field=root.querySelector('textarea[name^="studio_collaboration_comments["]');
					return{cursor:Number(root.dataset.dpStudioCollaborationCursor||0),threads:root.querySelectorAll('.dp-studio-thread').length,commentName:field?.name||''};
				});
				assert(peerBefore.commentName.startsWith('studio_collaboration_comments['),'The seeded collaboration thread has no stable comment control.');
				const commentSelector='textarea[name="'+peerBefore.commentName.replace(/\\/g,'\\\\').replace(/"/g,'\\"')+'"]';
				await peer.focus(commentSelector);
				await peer.keyboard.type(draft);
				await peer.waitForFunction((selector,value,cursor)=>{
					const field=document.querySelector(selector),root=document.querySelector('[data-dp-studio-collaboration]');
					return !!(field&&root&&field.value===value&&document.activeElement===field&&Number(root.dataset.dpStudioCollaborationCursor||0)>cursor);
				},{timeout:10000},commentSelector,draft,peerBefore.cursor);
				await delay(850);
				const peerDraft=await peer.evaluate(selector=>{
					const field=document.querySelector(selector),root=document.querySelector('[data-dp-studio-collaboration]'),status=root?.querySelector('[data-dp-studio-collaboration-live-status]');
					return{value:field?.value||'',focused:document.activeElement===field,cursor:Number(root?.dataset.dpStudioCollaborationCursor||0),state:document.querySelector('[data-dp-studio-editor]')?.dataset.dpStudioCollaborationState||'',status:status?.textContent||''};
				},commentSelector);
				assert(peerDraft.value===draft&&peerDraft.focused&&peerDraft.cursor>peerBefore.cursor&&peerDraft.state==='live','A typing refresh replaced the peer draft or focus: '+JSON.stringify(peerDraft));

				await page.bringToFront();
				await page.waitForFunction(()=>document.visibilityState==='visible'&&document.querySelector('[data-dp-studio-editor]')?.dataset.dpStudioCollaborationState==='live',{timeout:10000});
				const title='Browser live convergence proof '+Date.now();
				const titleSelector='input[name="studio_collaboration_title"]';
				const mutationCountBefore=primaryRequests.filter(request=>request.action==='mutate').length;
				const mutationResponsePromise=page.waitForResponse(response=>collaborationEndpoint(response.request())&&collaborationAction(response.request())==='mutate',{timeout:10000});
				const submitted=await page.evaluate((fieldSelector,value)=>{
					const field=document.querySelector(fieldSelector),button=document.querySelector('button[name="studio_collaboration_operation"][value="create_thread"]');
					if(!field||!button){return{ready:false,value:'',focused:false};}
					field.focus();field.value=value;field.dispatchEvent(new Event('input',{bubbles:true}));button.click();
					return{ready:true,value:field.value,focused:document.activeElement===field};
				},titleSelector,title);
				assert(submitted.ready&&submitted.value===title,'The live thread draft could not be submitted from the active collaboration fragment: '+JSON.stringify(submitted));
				const mutationResponse=await mutationResponsePromise;
				const mutationPayload=await mutationResponse.json();
				const observedMutation=primaryRequests.find(request=>request.action==='mutate');
				const submittedNames=Array.from((observedMutation?.body||'').matchAll(/name="([^"]+)"/g),match=>match[1]);
				const submittedTitle=(observedMutation?.body||'').match(/name="studio_collaboration_title"\r?\n\r?\n([\s\S]*?)\r?\n--/)?.[1]||'';
				assert(mutationResponse.status()===200&&mutationPayload?.ok===true,'The live collaboration mutation was rejected: HTTP '+mutationResponse.status()+' '+JSON.stringify({payload:mutationPayload,submittedTitle,submittedNames}));
				await page.waitForFunction(expected=>Array.from(document.querySelectorAll('.dp-studio-thread h4')).some(heading=>heading.textContent===expected),{timeout:10000},title);
				await page.waitForFunction(()=>document.querySelector('[data-dp-studio-collaboration]')?.getAttribute('aria-busy')===null,{timeout:10000});
				await delay(1200);
				const primaryMutationRequests=primaryRequests.filter(request=>request.action==='mutate').slice(mutationCountBefore);
				assert(primaryMutationRequests.length===1,'A single collaboration command emitted '+primaryMutationRequests.length+' mutation requests.');
				assert(primaryMutationRequests[0].body.includes('name="studio_collaboration_operation"')&&primaryMutationRequests[0].body.includes('create_thread'),'The live mutation omitted its bounded operation.');

				await peer.bringToFront();
				await peer.waitForFunction(()=>document.visibilityState==='visible',{timeout:5000});
				await peer.evaluate(()=>window.DataphyrePanelStudioEditor?.syncCollaboration(document.querySelector('[data-dp-studio-editor]')));
				await peer.waitForFunction(expected=>Array.from(document.querySelectorAll('.dp-studio-thread h4')).some(heading=>heading.textContent===expected),{timeout:12000},title);
				const converged=await peer.evaluate((selector,value,expected)=>{
					const field=document.querySelector(selector),root=document.querySelector('[data-dp-studio-collaboration]'),status=root?.querySelector('[data-dp-studio-collaboration-live-status]');
					return{
						draft:field?.value||'',cursor:Number(root?.dataset.dpStudioCollaborationCursor||0),threadCount:root?.querySelectorAll('.dp-studio-thread').length||0,
						hasRemote:Array.from(root?.querySelectorAll('.dp-studio-thread h4')||[]).some(heading=>heading.textContent===expected),
						state:document.querySelector('[data-dp-studio-editor]')?.dataset.dpStudioCollaborationState||'',status:status?.textContent||'',
					};
				},commentSelector,draft,title);
				assert(converged.hasRemote&&converged.threadCount===peerBefore.threads+1,'The peer did not converge on the remote thread exactly once: '+JSON.stringify(converged));
				assert(converged.draft===draft&&converged.cursor>peerDraft.cursor&&converged.state==='live','Remote convergence lost the peer draft or live cursor: '+JSON.stringify(converged));
				assert(peerConsoleErrors.length===0,peerConsoleErrors.length+' peer browser console errors: '+peerConsoleErrors.join(' | '));
				return{
					peerBefore,peerDraft,converged,
					requests:{
						primary:primaryRequests.reduce((counts,request)=>{counts[request.action]=(counts[request.action]||0)+1;return counts;},{}),
						peer:peerRequests.reduce((counts,request)=>{counts[request.action]=(counts[request.action]||0)+1;return counts;},{}),
						mutationAttempts:primaryMutationRequests.length,
					},
				};
			}
			finally{
				page.off('request',onPrimaryRequest);
				peer.off('request',onPeerRequest);
				peer.off('console',onPeerConsole);
				peer.off('pageerror',onPeerError);
				if(!peer.isClosed()){await peer.close();}
			}
		});

		await probe('Studio visual runtime keeps actual Panel surfaces sandboxed themed accessible and overflow-free',async page=>{
			await page.emulateMediaFeatures([{name:'prefers-reduced-motion',value:'reduce'}]);
			const inspect=async(preset,mode,width,height)=>{
				await page.setViewport({width,height,deviceScaleFactor:1,isMobile:width<=720,hasTouch:width<=720});
				const url=baseUrl+'?dp_panel_studio_visual=1&panel_theme='+encodeURIComponent(preset)+'&dp_panel_studio_visual_mode='+encodeURIComponent(mode);
				const response=await page.goto(url,{waitUntil:'domcontentloaded',timeout:30000});
				assert(response&&response.status()===200,'Studio visual showroom did not return HTTP 200.');
				await page.waitForSelector('.dp-studio-visual-grid iframe[sandbox=""]',{visible:true,timeout:15000});
				await page.waitForFunction(()=>document.querySelectorAll('.dp-studio-visual-grid iframe').length>=7,{timeout:15000});
				await delay(250);
				const outer=await page.evaluate(()=>{
					const root=document.documentElement,main=document.querySelector('#preview-surfaces'),frames=Array.from(document.querySelectorAll('.dp-studio-visual-grid iframe')),surfaces=Array.from(document.querySelectorAll('.dp-studio-visual-surface'));
					const manifest=JSON.parse(document.querySelector('#dp-studio-visual-manifest')?.textContent||'null');
					const bounds=surfaces.map(surface=>{const box=surface.getBoundingClientRect();return{left:box.left,right:box.right,width:box.width};});
					const motion=[];for(const element of document.querySelectorAll('body,body *')){const style=getComputedStyle(element);for(const value of String(style.transitionDuration||'').split(',')){if(parseFloat(value)>0.01){motion.push(value);}}for(const value of String(style.animationDuration||'').split(',')){if(parseFloat(value)>0.01){motion.push(value);}}}
					return{
						overflow:root.scrollWidth-root.clientWidth,surfaceCount:surfaces.length,frameCount:frames.length,selected:surfaces.filter(surface=>surface.dataset.selected==='true').length,
						failed:surfaces.filter(surface=>surface.dataset.status!=='ready').length,inert:main?.hasAttribute('inert')===true,sandboxes:frames.every(frame=>frame.getAttribute('sandbox')===''&&frame.sandbox.length===0),
						referrers:frames.every(frame=>frame.referrerPolicy==='no-referrer'),titles:frames.every(frame=>String(frame.title||'').endsWith(' visual preview')),bounds,motion,
						manifest:{type:manifest?.type,source:manifest?.source,surfaceCount:manifest?.surface_count,contentSerialized:manifest?.content_serialized,datasetValues:manifest?.dataset?.values_serialized,statuses:manifest?.surfaces?.map(surface=>surface.status)||[]},
						secretLeak:document.documentElement.innerHTML.includes('browser-secret')||document.documentElement.innerHTML.includes('browser-token'),
					};
				});
				const frames=page.frames().filter(frame=>frame!==page.mainFrame());
				assert(frames.length===outer.frameCount,'Studio visual iframe/CDP frame counts diverged.');
				const rendered=[];
				for(const frame of frames){
					await frame.waitForSelector('body',{timeout:15000});
					rendered.push(await frame.evaluate(()=>{
						const body=document.body,root=document.documentElement,visible=element=>{const style=getComputedStyle(element),box=element.getBoundingClientRect();return style.display!=='none'&&style.visibility!=='hidden'&&box.width>0&&box.height>0;};
						const rgba=value=>{const channels=String(value||'').match(/[\d.]+/g);if(!channels||channels.length<3){return[0,0,0,0];}return[Number(channels[0]),Number(channels[1]),Number(channels[2]),channels.length>3?Number(channels[3]):1];};
						const blend=(layer,base)=>{const alpha=Math.max(0,Math.min(1,layer[3]));return[Math.round(layer[0]*alpha+base[0]*(1-alpha)),Math.round(layer[1]*alpha+base[1]*(1-alpha)),Math.round(layer[2]*alpha+base[2]*(1-alpha)),1];};
						const imageLayers=value=>{const layers=[];let depth=0,start=0;for(let index=0;index<String(value).length;index++){const character=value[index];if(character==='('){depth++;}else if(character===')'){depth--;}else if(character===','&&depth===0){layers.push(value.slice(start,index));start=index+1;}}layers.push(String(value).slice(start));return layers.filter(layer=>layer.trim()&&layer.trim()!=='none');};
						const effectiveBackgrounds=element=>{const ancestry=[];for(let current=element;current;current=current.parentElement){ancestry.unshift(current);}let candidates=[body.dataset.dpThemeMode==='dark'?[0,0,0,1]:[255,255,255,1]];for(const current of ancestry){const style=getComputedStyle(current);candidates=candidates.map(base=>blend(rgba(style.backgroundColor),base));const layers=imageLayers(style.backgroundImage);for(let index=layers.length-1;index>=0;index--){const colors=(layers[index].match(/rgba?\([^)]*\)/g)||[]).map(rgba);if(colors.length===0){continue;}const painted=[];for(const base of candidates){for(const color of colors){painted.push(blend(color,base));}}const unique=new Map(painted.map(color=>[color.slice(0,3).join(','),color]));candidates=Array.from(unique.values()).slice(0,64);}}return candidates.map(color=>'rgb('+color.slice(0,3).join(', ')+')');};
						const controls=Array.from(document.querySelectorAll('input:not([type="hidden"]),select,textarea')).filter(visible).map(control=>{const style=getComputedStyle(control);return{tag:control.tagName,color:style.color,backgrounds:effectiveBackgrounds(control),labelled:Boolean(control.labels&&control.labels.length||control.getAttribute('aria-label')||control.getAttribute('aria-labelledby'))};});
						return{title:document.title,theme:body.dataset.dpTheme||'',mode:body.dataset.dpThemeMode||'',overflow:root.scrollWidth-root.clientWidth,main:Boolean(document.querySelector('main.dp-panel')),controls,forms:document.querySelectorAll('form').length,scripts:document.querySelectorAll('script').length};
					}));
				}
				return{url,status:response.status(),headers:response.headers(),outer,rendered};
			};

			const dark=await inspect('glass','dark',1440,1000);
			assert(dark.headers['content-security-policy']?.includes("default-src 'none'")&&/^"[a-f0-9]{64}"$/.test(dark.headers.etag||''),'Studio visual response omitted its CSP or content-bound ETag.');
			assert(dark.outer.surfaceCount>=7&&dark.outer.frameCount===dark.outer.surfaceCount&&dark.outer.selected===1&&dark.outer.failed===0,'Studio visual surface inventory is incomplete: '+JSON.stringify(dark.outer));
			assert(dark.outer.inert&&dark.outer.sandboxes&&dark.outer.referrers&&dark.outer.titles&&!dark.outer.secretLeak,'Studio visual sandbox or privacy boundary failed: '+JSON.stringify(dark.outer));
			assert(dark.outer.manifest.type==='panel_studio_visual_preview'&&dark.outer.manifest.source==='session'&&dark.outer.manifest.surfaceCount===dark.outer.surfaceCount&&dark.outer.manifest.contentSerialized===false&&dark.outer.manifest.datasetValues===false&&dark.outer.manifest.statuses.every(status=>status==='ready'),'Studio visual public manifest drifted: '+JSON.stringify(dark.outer.manifest));
			assert(dark.outer.overflow<=1&&dark.outer.motion.length===0&&dark.outer.bounds.every(box=>box.left>=-1&&box.right<=1441),'Desktop Studio visual layout overflowed or retained reduced motion.');
			assert(dark.rendered.every(frame=>frame.main&&frame.theme==='browser_glass'&&frame.mode==='dark'&&frame.overflow<=1),'Studio visual frames escaped the owning dark glass Panel instance: '+JSON.stringify(dark.rendered.map(frame=>({title:frame.title,theme:frame.theme,mode:frame.mode,overflow:frame.overflow}))));
			const darkControls=dark.rendered.flatMap(frame=>frame.controls);
			assert(darkControls.length>=3&&darkControls.every(control=>control.labelled&&control.backgrounds.length>0&&control.backgrounds.every(background=>contrastRatio(control.color,background)>=4.5)),'Studio visual dark controls are unlabelled or below 4.5:1: '+JSON.stringify(darkControls));

			const light=await inspect('flat_minima','light',1024,820);
			assert(light.outer.overflow<=1&&light.outer.failed===0&&light.rendered.every(frame=>frame.theme==='browser_flat_minima'&&frame.mode==='light'&&frame.overflow<=1),'Studio visual light theme did not remain instance-scoped and bounded.');
			const lightControls=light.rendered.flatMap(frame=>frame.controls);
			assert(lightControls.every(control=>control.labelled&&control.backgrounds.length>0&&control.backgrounds.every(background=>contrastRatio(control.color,background)>=4.5)),'Studio visual light controls are unlabelled or below 4.5:1: '+JSON.stringify(lightControls));

			const mobile=await inspect('glass','dark',320,844);
			assert(mobile.outer.overflow<=1&&mobile.outer.failed===0&&mobile.outer.bounds.every(box=>box.left>=-1&&box.right<=321&&box.width<=320),'Studio visual runtime overflowed its 320px outer viewport: '+JSON.stringify(mobile.outer.bounds));
			assert(mobile.rendered.every(frame=>frame.overflow<=1&&frame.theme==='browser_glass'&&frame.mode==='dark'),'Studio visual frame overflowed or lost theme ownership on mobile.');
			return{dark:{outer:dark.outer,frames:dark.rendered.length,controls:darkControls.length},light:{outer:light.outer,frames:light.rendered.length,controls:lightControls.length},mobile:{outer:mobile.outer,frames:mobile.rendered.length}};
		},{allowConsoleErrors:['Blocked script execution in']});

		await probe('browser editor adapters mount asynchronously, synchronize canonical values, fall back, and release detached roots',async page=>{
			await open(page,baseUrl+'?resource=feature_showcase',{width:320,height:844});
			await page.waitForFunction(()=>window.DataphyrePanelEditors?.version===1,{timeout:10000});
			const setup=await page.evaluate(()=>{
				const api=window.DataphyrePanelEditors;
				window.__dpEditorAdapterLab={mounts:0,destroys:0,changes:0,commands:0,inserts:0};
				const factory=context=>{
					window.__dpEditorAdapterLab.mounts++;
					let value=context.read();
					const surface=document.createElement('div');
					surface.dataset.fixtureEditorSurface='1';surface.contentEditable='true';surface.setAttribute('role','textbox');surface.setAttribute('aria-multiline','true');surface.setAttribute('aria-label','Adapter article body');
					const render=()=>{surface.textContent=value;};render();context.host.appendChild(surface);
					const onInput=()=>{value=surface.textContent||'';window.__dpEditorAdapterLab.changes++;context.change(value);};surface.addEventListener('input',onInput);
					return new Promise(resolve=>setTimeout(()=>resolve({
						getValue:()=>value,
						setValue:next=>{value=String(next);render();},
						focus:()=>surface.focus(),
						command:command=>{if(command!=='bold'){return false;}window.__dpEditorAdapterLab.commands++;value+='|bold';render();context.change(value);return true;},
						insert:detail=>{window.__dpEditorAdapterLab.inserts++;value+=String(detail.html||detail.text||'');render();context.change(value);return true;},
						destroy:()=>{window.__dpEditorAdapterLab.destroys++;surface.removeEventListener('input',onInput);surface.remove();},
					}),35));
				};
				window.__dpEditorAdapterFactory=factory;
				api.registerSurface('browser_fixture',api.createTiptapBridge(factory));
				api.registerSyntax('browser_tokens',{tokens:context=>{
					const split=context.code.indexOf(' ');
					return split<0?[{type:'plain',text:context.code}]:[{type:'keyword',text:context.code.slice(0,split)},{type:'plain',text:context.code.slice(split)}];
				}});
				const definition=(driver,fallback='native',settings={})=>({
					name:'browser_lab',mode:settings.mode||'rich_text',
					browser_adapter:{schema_version:1,kind:'surface',name:driver,driver,strategy:settings.strategy||'registry',modes:[settings.mode||'rich_text'],languages:[],capabilities:['source_sync','lifecycle'],required_globals:settings.requiredGlobals||[],fallback,enabled:true,configured:true,options:settings.options||{}},
					browser_syntax:{schema_version:1,kind:'syntax',name:settings.syntaxDriver||'browser_tokens',driver:settings.syntaxDriver||'browser_tokens',strategy:settings.syntaxStrategy||'registry',modes:[],languages:[settings.language||'javascript'],capabilities:['text_tokens'],required_globals:settings.syntaxGlobals||[],fallback:'source',enabled:true,configured:true,options:{}},
				});
				const makeEditor=(id,driver,fallback,value,settings={})=>{
					const form=document.createElement('form');form.id=id+'-form';form.style.width='min(100%,280px)';form.style.minWidth='0';
					const editor=document.createElement('div');editor.id=id;editor.className='dp-panel-editor';editor.dataset.dpPanelEditor=settings.mode||'rich_text';editor.dataset.dpPanelEditorMode='write';editor.dataset.dpPanelCodeLanguage=settings.language||'javascript';editor.dataset.dpPanelEditorProfile=JSON.stringify(definition(driver,fallback,settings));
					const toolbar=document.createElement('div');toolbar.className='dp-panel-editor-toolbar';toolbar.innerHTML='<span>Article</span><div class="dp-panel-editor-tools" role="toolbar" aria-label="Article tools"><button type="button" data-dp-panel-editor-command="bold">Bold</button></div><small data-dp-panel-editor-status>Write</small>';
					const visual=document.createElement('div');visual.className='dp-panel-editor-visual';visual.contentEditable='true';visual.setAttribute('role','textbox');visual.setAttribute('aria-multiline','true');visual.dataset.dpPanelEditorVisual='1';
					const host=document.createElement('div');host.className='dp-panel-editor-external-host';host.hidden=true;host.setAttribute('aria-hidden','true');host.dataset.dpPanelEditorExternalHost='1';
					const shell=document.createElement('div');shell.className='dp-panel-input-shell';shell.hidden=true;shell.dataset.dpPanelEditorSourceShell='1';
					const textarea=document.createElement('textarea');textarea.name=id==='adapter-editor'?'body':id;textarea.value=value;textarea.dataset.dpPanelEditorSource='1';shell.appendChild(textarea);
					const preview=document.createElement('pre');preview.className='dp-panel-editor-preview dp-panel-editor-preview-code';
					editor.append(toolbar,visual,host,shell,preview);form.appendChild(editor);document.querySelector('main.dp-panel').appendChild(form);
					if(typeof window.dpPanelInitRichEditors==='function'){window.dpPanelInitRichEditors(form);}else{window.dpPanelEditorInitProfile(editor);}
					return editor;
				};
				window.__dpMakeEditor=makeEditor;
				makeEditor('adapter-editor','browser_fixture','native','Initial article');
				makeEditor('late-editor','late_fixture','source','<p>Late fallback value</p>');
				return {version:api.version,registered:api.list()};
			});
			assert(setup.version===1&&setup.registered.surfaces.includes('browser_fixture')&&setup.registered.syntax.includes('browser_tokens'),'Editor adapter registry did not publish the registered bridges: '+JSON.stringify(setup)+'.');
			await page.waitForFunction(()=>document.querySelector('#adapter-editor')?.dataset.dpPanelEditorAdapterState==='ready'&&document.querySelector('#late-editor')?.dataset.dpPanelEditorAdapterState==='unavailable',{timeout:10000});
			const interaction=await page.evaluate(async()=>{
				const wait=milliseconds=>new Promise(resolve=>setTimeout(resolve,milliseconds));
				const api=window.DataphyrePanelEditors,editor=document.querySelector('#adapter-editor'),source=editor.querySelector('textarea'),surface=editor.querySelector('[data-fixture-editor-surface]'),host=editor.querySelector('[data-dp-panel-editor-external-host]');
				surface.textContent='Changed article';surface.dispatchEvent(new Event('input',{bubbles:true}));await wait(20);
				editor.querySelector('[data-dp-panel-editor-command="bold"]').click();await wait(20);
				editor.dispatchEvent(new CustomEvent('dp-panel-editor:insert',{bubbles:true,detail:{text:'|inserted'}}));await wait(20);
				const submitted=new FormData(editor.closest('form')).get('body');
				const preview=editor.querySelector('.dp-panel-editor-preview');const syntax=window.dpPanelEditorRenderSyntaxTokens(editor,preview,'const value = 1;');
				const late=document.querySelector('#late-editor'),lateVisual=late.querySelector('[data-dp-panel-editor-visual]'),lateSource=late.querySelector('[data-dp-panel-editor-source-shell]');
				const rect=editor.getBoundingClientRect();
				return {
					canonical:source.value,submitted,syntax,syntaxText:preview.textContent,tokenClasses:Array.from(preview.querySelectorAll('.dp-panel-token')).map(token=>token.className),
					state:api.state(editor),hostHidden:host.hidden,hostAria:host.getAttribute('aria-hidden'),surfaceRole:surface.getAttribute('role'),surfaceLabel:surface.getAttribute('aria-label'),sourceHidden:source.closest('.dp-panel-input-shell').hidden,
					late:{state:api.state(late),visualHidden:lateVisual.hidden,sourceHidden:lateSource.hidden,value:late.querySelector('textarea').value},
					overflow:Math.max(0,editor.scrollWidth-editor.clientWidth),width:Math.round(rect.width),documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),stats:{...window.__dpEditorAdapterLab},
				};
			});
			assert(interaction.canonical==='Changed article|bold|inserted'&&interaction.submitted===interaction.canonical,'External editor did not synchronize its canonical submitted value: '+JSON.stringify(interaction)+'.');
			assert(interaction.syntax&&interaction.syntaxText==='const value = 1;'&&interaction.tokenClasses.some(value=>value.includes('dp-panel-token-keyword')),'Registered syntax adapter did not produce an exact inert token stream.');
			assert(interaction.state?.mounted&&interaction.hostHidden===false&&interaction.hostAria==='false'&&interaction.sourceHidden,'Enhanced editor did not expose exactly one active editing surface: '+JSON.stringify(interaction)+'.');
			assert(interaction.surfaceRole==='textbox'&&interaction.surfaceLabel==='Adapter article body','Enhanced editor surface lost its accessible textbox identity.');
			assert(interaction.late.state?.phase==='unavailable'&&interaction.late.visualHidden&&interaction.late.sourceHidden===false&&interaction.late.value==='<p>Late fallback value</p>','Missing adapter did not preserve the declared source fallback: '+JSON.stringify(interaction.late)+'.');
			assert(interaction.overflow===0&&interaction.documentOverflow===0&&interaction.width<=280,'Editor adapter overflowed its 280px host: '+JSON.stringify(interaction)+'.');

			const lifecycle=await page.evaluate(async()=>{
				const api=window.DataphyrePanelEditors,wait=milliseconds=>new Promise(resolve=>setTimeout(resolve,milliseconds));
				const until=async predicate=>{for(let attempt=0;attempt<100;attempt++){if(predicate()){return true;}await wait(20);}return false;};
				const late=document.querySelector('#late-editor');api.registerSurface('late_fixture',api.createCodeMirror6Bridge(window.__dpEditorAdapterFactory));
				const lateMounted=await until(()=>api.state(late)?.phase==='ready');
				api.unregisterSurface('late_fixture');const lateFellBack=await until(()=>api.state(late)?.phase==='unavailable'&&!late.querySelector('[data-dp-panel-editor-source-shell]').hidden);
				const editor=document.querySelector('#adapter-editor'),form=editor.closest('form');api.unmount(editor,{reason:'explicit_test'});
				const nativeRestored=api.state(editor)===null&&!editor.querySelector('[data-dp-panel-editor-visual]').hidden;
				await api.mount(editor);const remounted=api.state(editor)?.phase==='ready';
				const moveHost=document.createElement('div');document.querySelector('main.dp-panel').appendChild(moveHost);moveHost.appendChild(form);await wait(80);const movedWithoutRemount=api.state(editor)?.phase==='ready';
				form.remove();const detached=await until(()=>api.state(editor)===null);
				api.registerSurface('stale_fixture',api.createTiptapBridge(window.__dpEditorAdapterFactory));const stale=window.__dpMakeEditor('stale-editor','stale_fixture','native','Stale');stale.closest('form').remove();
				const staleReleased=await until(()=>api.state(stale)===null&&window.__dpEditorAdapterLab.destroys>=4);
				return {lateMounted,lateFellBack,nativeRestored,remounted,movedWithoutRemount,detached,staleReleased,stats:{...window.__dpEditorAdapterLab},lateState:api.state(late)};
			});
			assert(lifecycle.lateMounted&&lifecycle.lateFellBack,'Late adapter registration/unregistration did not transition through ready and source fallback: '+JSON.stringify(lifecycle)+'.');
			assert(lifecycle.nativeRestored&&lifecycle.remounted&&lifecycle.movedWithoutRemount&&lifecycle.detached,'Editor unmount, remount, same-turn move, or detached-root cleanup failed: '+JSON.stringify(lifecycle)+'.');
			assert(lifecycle.staleReleased&&lifecycle.stats.mounts>=4&&lifecycle.stats.destroys>=4,'Aborted asynchronous editor mount leaked its eventual instance: '+JSON.stringify(lifecycle)+'.');
			const vendors=await page.evaluate(async()=>{
				const api=window.DataphyrePanelEditors,wait=milliseconds=>new Promise(resolve=>setTimeout(resolve,milliseconds));
				const until=async predicate=>{for(let attempt=0;attempt<100;attempt++){if(predicate()){return true;}await wait(20);}return false;};
				window.__dpVendorEditors={tiny:null,ck:null,monaco:null,tinyRemoved:0,ckDestroyed:0,monacoDisposed:0};
				window.tinymce={init:options=>{
					const callbacks={};const surface=document.createElement('div');surface.dataset.vendorSurface='tinymce';surface.setAttribute('role','textbox');surface.setAttribute('aria-label','TinyMCE article');options.target.appendChild(surface);
					const instance={content:'',commands:[],on:(events,listener)=>events.split(/\s+/).forEach(event=>{(callbacks[event]||(callbacks[event]=[])).push(listener);}),emit:event=>(callbacks[event]||[]).forEach(listener=>listener()),setContent:value=>{instance.content=String(value);surface.textContent=instance.content;},getContent:()=>instance.content,insertContent:value=>{instance.content+=String(value);surface.textContent=instance.content;},execCommand:(command,_ui,value)=>{instance.commands.push([command,value]);},focus:()=>surface.focus(),remove:()=>{window.__dpVendorEditors.tinyRemoved++;surface.remove();}};
					options.setup(instance);instance.emit('init');window.__dpVendorEditors.tiny=instance;return Promise.resolve([instance]);
				}};
				window.ClassicEditor={create:(host,options)=>{
					const surface=document.createElement('div');surface.dataset.vendorSurface='ckeditor5';surface.setAttribute('role','textbox');surface.setAttribute('aria-label','CKEditor article');host.appendChild(surface);let change=null;
					const instance={data:String(options.initialData||''),commands:{get:()=>({})},model:{document:{on:(_event,listener)=>{change=listener;}}},editing:{view:{focus:()=>surface.focus()}},getData:()=>instance.data,setData:value=>{instance.data=String(value);surface.textContent=instance.data;},execute:command=>{instance.data+='|'+command;surface.textContent=instance.data;if(change){change();}},change:value=>{instance.data=String(value);surface.textContent=instance.data;if(change){change();}},destroy:()=>{window.__dpVendorEditors.ckDestroyed++;surface.remove();return Promise.resolve();}};
					instance.setData(instance.data);window.__dpVendorEditors.ck=instance;return Promise.resolve(instance);
				}};
				window.monaco={editor:{create:(host,options)=>{
					const surface=document.createElement('div');surface.dataset.vendorSurface='monaco';surface.setAttribute('role','textbox');surface.setAttribute('aria-label','Monaco source');host.appendChild(surface);let change=null;
					const instance={value:String(options.value||''),getValue:()=>instance.value,setValue:value=>{instance.value=String(value);surface.textContent=instance.value;},onDidChangeModelContent:listener=>{change=listener;return{dispose:()=>{change=null;}};},getSelection:()=>({startLineNumber:1,startColumn:1,endLineNumber:1,endColumn:1}),executeEdits:(_source,edits)=>{instance.value+=String(edits[0]?.text||'');surface.textContent=instance.value;if(change){change();}},getAction:action=>({run:()=>{instance.value+='|'+action;surface.textContent=instance.value;if(change){change();}}}),focus:()=>surface.focus(),change:value=>{instance.value=String(value);surface.textContent=instance.value;if(change){change();}},dispose:()=>{window.__dpVendorEditors.monacoDisposed++;surface.remove();}};
					instance.setValue(instance.value);window.__dpVendorEditors.monaco=instance;return instance;
				}}};
				window.Prism={languages:{javascript:{}},tokenize:code=>[{type:'keyword',content:code.slice(0,5)},code.slice(5)]};
				window.hljs={highlight:code=>({_emitter:{rootNode:{children:[{scope:'keyword',children:[code.slice(0,3)]},code.slice(3)]}}})};
				const tiny=window.__dpMakeEditor('tiny-editor','tinymce','native','<p>Tiny initial</p>',{strategy:'global',requiredGlobals:['tinymce'],syntaxDriver:'prism',syntaxStrategy:'global',syntaxGlobals:['Prism'],options:{selector:'ignored',setup:'ignored'}});
				const ck=window.__dpMakeEditor('ck-editor','ckeditor5','native','<p>CK initial</p>',{strategy:'global',requiredGlobals:['ClassicEditor'],syntaxDriver:'highlightjs',syntaxStrategy:'global',syntaxGlobals:['hljs'],options:{initialData:'ignored'}});
				const monaco=window.__dpMakeEditor('monaco-editor','monaco','source','const initial = 1;',{mode:'code',strategy:'global',requiredGlobals:['monaco.editor'],syntaxDriver:'prism',syntaxStrategy:'global',syntaxGlobals:['Prism'],options:{model:'ignored'}});
				const mounted=await until(()=>[tiny,ck,monaco].every(editor=>api.state(editor)?.phase==='ready'));
				window.__dpVendorEditors.tiny.content='<p>Tiny changed</p>';window.__dpVendorEditors.tiny.emit('change');
				window.__dpVendorEditors.ck.change('<p>CK changed</p>');
				window.__dpVendorEditors.monaco.change('const changed = true;');await wait(20);
				const tinyCommand=api.command(tiny,'bold');const tinyInsert=api.insert(tiny,{text:'|insert'});
				const ckCommand=api.command(ck,'bold');const monacoCommand=api.command(monaco,'find');const monacoInsert=api.insert(monaco,{text:'|insert'});await wait(20);
				const tinyPreview=tiny.querySelector('.dp-panel-editor-preview'),ckPreview=ck.querySelector('.dp-panel-editor-preview');
				const prism=window.dpPanelEditorRenderSyntaxTokens(tiny,tinyPreview,'const tiny = 1;');const highlight=window.dpPanelEditorRenderSyntaxTokens(ck,ckPreview,'let ck = 2;');
				const values={tiny:tiny.querySelector('textarea').value,ck:ck.querySelector('textarea').value,monaco:monaco.querySelector('textarea').value};
				const tokens={prism,prismText:tinyPreview.textContent,prismClass:tinyPreview.querySelector('.dp-panel-token')?.className||'',highlight,highlightText:ckPreview.textContent,highlightClass:ckPreview.querySelector('.dp-panel-token')?.className||''};
				const surfaces=Array.from(document.querySelectorAll('[data-vendor-surface]')).map(surface=>({vendor:surface.dataset.vendorSurface,role:surface.getAttribute('role'),label:surface.getAttribute('aria-label')}));
				api.unmount(tiny,{reason:'vendor_test'});api.unmount(ck,{reason:'vendor_test'});api.unmount(monaco,{reason:'vendor_test'});await wait(30);
				return {mounted,tinyCommand,tinyInsert,ckCommand,monacoCommand,monacoInsert,values,tokens,surfaces,teardown:{tiny:window.__dpVendorEditors.tinyRemoved,ck:window.__dpVendorEditors.ckDestroyed,monaco:window.__dpVendorEditors.monacoDisposed,states:[api.state(tiny),api.state(ck),api.state(monaco)]}};
			});
			assert(vendors.mounted&&vendors.tinyCommand&&vendors.tinyInsert&&vendors.ckCommand&&vendors.monacoCommand&&vendors.monacoInsert,'A first-party vendor bridge did not mount or route commands: '+JSON.stringify(vendors)+'.');
			assert(vendors.values.tiny==='<p>Tiny changed</p>|insert'&&vendors.values.ck==='<p>CK changed</p>|bold'&&vendors.values.monaco==='const changed = true;|actions.find|insert','Vendor bridges did not preserve canonical values: '+JSON.stringify(vendors.values)+'.');
			assert(vendors.tokens.prism&&vendors.tokens.prismText==='const tiny = 1;'&&vendors.tokens.prismClass.includes('keyword')&&vendors.tokens.highlight&&vendors.tokens.highlightText==='let ck = 2;'&&vendors.tokens.highlightClass.includes('keyword'),'Prism or highlight.js did not flatten to exact inert tokens: '+JSON.stringify(vendors.tokens)+'.');
			assert(vendors.surfaces.length===3&&vendors.surfaces.every(surface=>surface.role==='textbox'&&surface.label),'Vendor surface accessibility contract failed: '+JSON.stringify(vendors.surfaces)+'.');
			assert(vendors.teardown.tiny===1&&vendors.teardown.ck===1&&vendors.teardown.monaco===1&&vendors.teardown.states.every(state=>state===null),'A built-in vendor bridge did not release its instance: '+JSON.stringify(vendors.teardown)+'.');
			return {setup,interaction,lifecycle,vendors};
		});

		await probe('editor asset providers browse, upload, insert, delete, verify requests, and release accessible pickers',async page=>{
			const httpRequests=[];
			const interception=await installRequestFixtures(page,request=>{
				const url=new URL(request.url());
				if(url.pathname.startsWith('/panel/media/')){return {status:200,contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"><rect width="8" height="8" fill="#2563eb"/></svg>'};}
				if(request.method()!=='POST'||url.pathname!=='/editor-assets-fixture'){return null;}
				httpRequests.push({method:request.method(),url:request.url(),headers:request.headers(),body:request.postData()||''});
				return {status:200,contentType:'application/json; charset=utf-8',body:JSON.stringify({
					type:'panel_editor_asset_result',schema_version:1,ok:true,code:'assets_ready',message:'Assets ready.',status:200,asset:null,
					page:{type:'panel_editor_asset_page',schema_version:1,assets:[{type:'panel_editor_asset',schema_version:1,id:'http_asset',name:'http.png',url:'/panel/media/http.png',mime:'image/png',bytes:8,kind:'image',width:8,height:8,alt:'HTTP asset',status:'ready',metadata:{}}],next_cursor:null,has_more:false,total:1,meta:{}},warnings:[],meta:{},
				})};
			});
			try{
				await open(page,baseUrl+'?resource=feature_showcase',{width:280,height:760});
				if(!await page.evaluate(()=>window.DataphyrePanel?.editorRuntime?.version===1)){
					await page.addScriptTag({url:baseUrl+'?dp_panel_asset=panel.js&dp_panel_editor_assets_probe=1'});
				}
				await page.waitForFunction(()=>window.DataphyrePanel?.editorRuntime?.version===1&&typeof window.DataphyrePanel.editorRuntime.init==='function',{timeout:10000});
				if(!await page.evaluate(()=>typeof window.DataphyrePanelEditors?.registerAssets==='function')){
					await page.addScriptTag({url:baseUrl+'?dp_panel_asset=panel-editor-assets.js&dp_panel_editor_assets_probe=1'});
				}
				await page.waitForFunction(()=>window.DataphyrePanelEditors?.version===1&&typeof window.DataphyrePanelEditors.registerAssets==='function',{timeout:10000});
				const setup=await page.evaluate(()=>{
					const api=window.DataphyrePanelEditors;
					window.__dpAssetLab={browse:[],uploads:0,deletes:[],externalInserts:0};
					const asset=(id,name,url,kind='image',mime='image/png')=>({type:'panel_editor_asset',schema_version:1,id,name,url,mime,bytes:64,kind,width:kind==='image'?64:null,height:kind==='image'?48:null,alt:name.replace(/\.[^.]+$/,''),status:'ready',metadata:{}});
					const envelope=(assets,nextCursor=null,hasMore=false,oneAsset=null)=>({type:'panel_editor_asset_result',schema_version:1,ok:true,code:oneAsset?'uploaded':'assets_ready',message:'Assets ready.',status:200,asset:oneAsset,page:oneAsset?null:{type:'panel_editor_asset_page',schema_version:1,assets,next_cursor:nextCursor,has_more:hasMore,total:assets.length,meta:{}},warnings:[],meta:{}});
					const first=[asset('hero','hero.png','/panel/media/hero.png'),asset('guide','guide.pdf','https://cdn.example.test/guide.pdf','document','application/pdf'),asset('unsafe','unsafe.png','http://insecure.example.test/unsafe.png')];
					const bridge={
						browse:(_context,query={})=>{window.__dpAssetLab.browse.push({...query});const search=String(query.search||'').toLowerCase();let result=query.cursor?envelope([asset('more','more.png','/panel/media/more.png')]):envelope(first.filter(item=>!search||item.name.includes(search)),search?null:'next_page',!search);return search==='slow'?new Promise(resolve=>setTimeout(()=>resolve(result),180)):result;},
						upload:(_context,file)=>{window.__dpAssetLab.uploads++;const uploaded=asset('uploaded',file.name,'/panel/media/uploaded.png');return envelope([],null,false,uploaded);},
						delete:(_context,id)=>{window.__dpAssetLab.deletes.push(id);return {type:'panel_editor_asset_result',schema_version:1,ok:true,code:'deleted',message:'Deleted.',status:200,asset:null,page:null,warnings:[],meta:{}};},
					};
					const profile=(driver,endpoint='',external=false)=>({
						name:'asset_lab',mode:'rich_text',policy:{name:'strict_html',elements:{p:[],br:[],strong:[],a:['href','target','rel'],img:['src','alt','width','height','loading']},schemes:['http','https'],relative_urls:true},
						asset_provider:{type:'panel_editor_asset_provider',schema_version:1,name:'asset_lab',provider:'callback',accepted:['image/png'],max_bytes:1024,enabled:true,ready:true,capabilities:{browse:true,read:true,upload:true,delete:true,delivery:true,canonical_references:true},browser:{schema_version:1,driver,endpoint,request_verification_required:true,verification_field:'csrf_token',verification_header:'X-Panel-Verify',credentials:'same-origin'}},
						browser_adapter:external?{schema_version:1,kind:'surface',name:'asset_surface',driver:'asset_surface',strategy:'registry',modes:['rich_text'],languages:[],capabilities:['source_sync','lifecycle'],required_globals:[],fallback:'native',enabled:true,configured:true,options:{}}:null,
					});
					const makeEditor=(id,driver,endpoint='',token=null,external=false)=>{
						const form=document.createElement('form');form.id=id+'-form';form.style.cssText='width:min(100%,260px);min-width:0';
						if(token!==null){const verification=document.createElement('input');verification.type='hidden';verification.name='csrf_token';verification.value=token;form.appendChild(verification);}
						const editor=document.createElement('div');editor.id=id;editor.className='dp-panel-editor';editor.dataset.dpPanelEditor='rich_text';editor.dataset.dpPanelEditorMode='write';editor.dataset.dpPanelEditorProfile=JSON.stringify(profile(driver,endpoint,external));editor.dataset.dpPanelEditorAssets=driver;
						const toolbar=document.createElement('div');toolbar.className='dp-panel-editor-toolbar';const label=document.createElement('span');label.textContent='Article';const tools=document.createElement('div');tools.className='dp-panel-editor-tools dp-panel-editor-asset-tools';tools.setAttribute('role','toolbar');tools.setAttribute('aria-label','Editor assets');const trigger=document.createElement('button');trigger.type='button';trigger.hidden=true;trigger.disabled=true;trigger.dataset.dpPanelEditorAssetsTrigger='1';trigger.setAttribute('aria-haspopup','dialog');trigger.setAttribute('aria-expanded','false');trigger.textContent='Media';tools.appendChild(trigger);toolbar.append(label,tools);
						const visual=document.createElement('div');visual.className='dp-panel-editor-visual';visual.contentEditable='true';visual.setAttribute('role','textbox');visual.setAttribute('aria-multiline','true');visual.dataset.dpPanelEditorVisual='1';
						const externalHost=document.createElement('div');externalHost.className='dp-panel-editor-external-host';externalHost.hidden=true;externalHost.setAttribute('aria-hidden','true');externalHost.dataset.dpPanelEditorExternalHost='1';
						const sourceShell=document.createElement('span');sourceShell.className='dp-panel-input-shell';const source=document.createElement('textarea');source.name=id;source.dataset.dpPanelEditorSource='1';sourceShell.appendChild(source);
						const preview=document.createElement('div');preview.className='dp-panel-editor-preview';const assetHost=document.createElement('div');assetHost.dataset.dpPanelEditorAssetsHost='1';
						editor.append(toolbar,visual);if(external){editor.appendChild(externalHost);}editor.append(sourceShell,preview,assetHost);form.appendChild(editor);document.querySelector('main.dp-panel').appendChild(form);window.DataphyrePanel.editorRuntime.init(form);return editor;
					};
					window.__dpMakeAssetEditor=makeEditor;
					const editor=makeEditor('asset-native','asset_fixture');const hiddenBefore=editor.querySelector('[data-dp-panel-editor-assets-trigger]').hidden;
					api.registerSurface('asset_surface',api.createTiptapBridge(context=>{
						let value=context.read();const surface=document.createElement('div');surface.dataset.assetExternalSurface='1';surface.setAttribute('role','textbox');surface.setAttribute('aria-label','External asset editor');context.host.appendChild(surface);
						return {getValue:()=>value,setValue:next=>{value=String(next);surface.textContent=value;},focus:()=>surface.focus(),command:()=>false,insert:detail=>{window.__dpAssetLab.externalInserts++;value+=String(detail.html||detail.text||'');surface.textContent=value;context.change(value);return true;},destroy:()=>surface.remove()};
					}));
					const external=makeEditor('asset-external','asset_fixture','',null,true);api.registerAssets('asset_fixture',bridge);
					return {hiddenBefore,hiddenAfter:editor.querySelector('[data-dp-panel-editor-assets-trigger]').hidden,assets:api.list().assets,state:api.assetState(editor),externalState:api.state(external)};
				});
				assert(setup.hiddenBefore&&setup.hiddenAfter===false&&setup.assets.includes('asset_fixture')&&setup.assets.includes('http')&&setup.state?.ready,'Asset bridge registration did not progressively reveal a ready picker: '+JSON.stringify(setup)+'.');
				await page.waitForFunction(()=>document.querySelector('#asset-external')?.dataset.dpPanelEditorAdapterState==='ready',{timeout:10000});

				await page.click('#asset-native [data-dp-panel-editor-assets-trigger]');
				await page.waitForSelector('#asset-native .dp-panel-editor-assets-dialog[open]',{visible:true,timeout:10000});
				await page.waitForFunction(()=>document.querySelectorAll('#asset-native [data-dp-panel-editor-asset-id]').length===2);
				const initial=await page.$eval('#asset-native',editor=>{
					const dialog=editor.querySelector('.dp-panel-editor-assets-dialog'),trigger=editor.querySelector('[data-dp-panel-editor-assets-trigger]'),status=dialog.querySelector('[role="status"]'),labelled=document.getElementById(dialog.getAttribute('aria-labelledby')||'');
					return {modal:dialog.getAttribute('aria-modal'),labelled:!!labelled,heading:labelled?.textContent||'',focusSearch:document.activeElement===dialog.querySelector('input[type="search"]'),focusInside:dialog.contains(document.activeElement),active:{tag:document.activeElement?.tagName||'',type:document.activeElement?.getAttribute('type')||'',className:document.activeElement?.className||''},expanded:trigger.getAttribute('aria-expanded'),count:dialog.querySelectorAll('[data-dp-panel-editor-asset-id]').length,unsafe:!!dialog.querySelector('[data-dp-panel-editor-asset-id="unsafe"]'),statusRole:status?.getAttribute('role'),live:status?.getAttribute('aria-live'),overflow:Math.max(0,dialog.scrollWidth-dialog.clientWidth),controlMin:Math.min(...Array.from(dialog.querySelectorAll('button,input')).filter(item=>!item.hidden).map(item=>item.getBoundingClientRect().height))};
				});
				assert(initial.modal==='true'&&initial.labelled&&initial.heading==='Media library'&&initial.focusSearch&&initial.expanded==='true'&&initial.count===2&&!initial.unsafe&&initial.statusRole==='status'&&initial.live==='polite','Asset picker accessibility or unsafe-result filtering failed: '+JSON.stringify(initial)+'.');
				assert(initial.overflow===0&&initial.controlMin>=31,'Asset picker controls overflowed or lost normalized geometry: '+JSON.stringify(initial)+'.');

				await page.click('#asset-native .dp-panel-editor-assets-more');
				await page.waitForSelector('#asset-native [data-dp-panel-editor-asset-id="more"]');
				await page.$eval('#asset-native .dp-panel-editor-assets-search input',input=>{input.value='hero';input.closest('form').requestSubmit();});
				await page.waitForFunction(()=>document.querySelectorAll('#asset-native [data-dp-panel-editor-asset-id]').length===1&&document.querySelector('#asset-native [data-dp-panel-editor-asset-id="hero"]'));
				await page.$eval('#asset-native input[type="file"]',input=>{const transfer=new DataTransfer();transfer.items.add(new File([new Uint8Array([1,2,3])],'uploaded.png',{type:'image/png'}));input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));});
				await page.waitForSelector('#asset-native [data-dp-panel-editor-asset-id="uploaded"]');
				await page.click('#asset-native [data-dp-panel-editor-asset-id="uploaded"] .dp-panel-editor-asset-delete');
				const deleteConfirmation=await page.$eval('#asset-native [data-dp-panel-editor-asset-id="uploaded"] .dp-panel-editor-asset-delete',button=>button.textContent);
				assert(deleteConfirmation==='Confirm delete','Asset deletion did not require an explicit second action.');
				await page.$eval('#asset-native [data-dp-panel-editor-asset-id="uploaded"] .dp-panel-editor-asset-delete',button=>button.click());
				await page.waitForFunction(()=>!document.querySelector('#asset-native [data-dp-panel-editor-asset-id="uploaded"]'));
				await page.click('#asset-native [data-dp-panel-editor-asset-id="hero"] .dp-panel-editor-asset-choose');
				await page.waitForFunction(()=>!document.querySelector('#asset-native .dp-panel-editor-assets-dialog')?.open);
				const insertion=await page.$eval('#asset-native',editor=>{
					const source=editor.querySelector('[data-dp-panel-editor-source]'),visual=editor.querySelector('[data-dp-panel-editor-visual]'),trigger=editor.querySelector('[data-dp-panel-editor-assets-trigger]');
					visual.insertAdjacentHTML('beforeend','<img src="/panel/media/evil.png" alt="Tracker">');visual.dispatchEvent(new Event('input',{bubbles:true}));
					return {value:source.value,visual:visual.innerHTML,preview:editor.querySelector('.dp-panel-editor-preview')?.innerHTML||'',focusRestored:document.activeElement===trigger||document.activeElement===visual,markdown:window.DataphyrePanel.editorRuntime.htmlToMarkdown('<p><img src="/panel/media/hero.png" alt="Hero"></p>',editor)};
				});
				assert(insertion.value.includes('<img')&&insertion.value.includes('src="/panel/media/hero.png"')&&!insertion.value.includes('evil.png')&&!insertion.visual.includes('evil.png')&&!insertion.preview.includes('evil.png'),'Provider image insertion or paste isolation failed: '+JSON.stringify(insertion)+'.');
				assert(insertion.focusRestored&&insertion.markdown.includes('![Hero](/panel/media/hero.png)'),'Picker focus restore or Markdown media round-trip failed: '+JSON.stringify(insertion)+'.');

				const reopened=await page.evaluate(async()=>{const editor=document.querySelector('#asset-native'),trigger=editor.querySelector('[data-dp-panel-editor-assets-trigger]'),before=window.DataphyrePanelEditors.assetState(editor),opened=await window.DataphyrePanelEditors.openAssets(editor);return {opened,before,after:window.DataphyrePanelEditors.assetState(editor),hidden:trigger.hidden,disabled:trigger.disabled};});
				assert(reopened.opened&&reopened.after?.open&&!reopened.hidden&&!reopened.disabled,'Asset picker public reopen contract failed: '+JSON.stringify(reopened)+'.');
				await page.setViewport({width:160,height:640,deviceScaleFactor:1});await new Promise(resolve=>setTimeout(resolve,80));
				const narrow=await page.$eval('#asset-native .dp-panel-editor-assets-dialog',dialog=>{const rect=dialog.getBoundingClientRect();const escaped=Array.from(dialog.querySelectorAll('button,input')).filter(item=>!item.hidden).some(item=>{const box=item.getBoundingClientRect();return box.left<-1||box.right>innerWidth+1;});return {left:Math.round(rect.left),right:Math.round(rect.right),width:Math.round(rect.width),viewport:innerWidth,overflow:Math.max(0,dialog.scrollWidth-dialog.clientWidth),escaped};});
				assert(narrow.left>=-1&&narrow.right<=narrow.viewport+1&&narrow.width<=narrow.viewport&&narrow.overflow===0&&!narrow.escaped,'Asset picker overflowed its 160px viewport: '+JSON.stringify(narrow)+'.');
				await page.keyboard.press('Escape');await new Promise(resolve=>setTimeout(resolve,100));const escapeState=await page.$eval('#asset-native',editor=>{const dialog=editor.querySelector('.dp-panel-editor-assets-dialog');return {open:dialog.open,focusInsideEditor:editor.contains(document.activeElement),focusInsideDialog:dialog.contains(document.activeElement),active:document.activeElement?.className||document.activeElement?.tagName||''};});
				assert(!escapeState.open&&escapeState.focusInsideEditor&&!escapeState.focusInsideDialog,'Asset picker Escape dismissal did not restore its opener context: '+JSON.stringify(escapeState)+'.');

				await page.setViewport({width:280,height:760,deviceScaleFactor:1});await page.$eval('#asset-native [data-dp-panel-editor-assets-trigger]',trigger=>trigger.click());await page.waitForSelector('#asset-native .dp-panel-editor-assets-dialog[open]',{visible:true});
				await page.$eval('#asset-native .dp-panel-editor-assets-search input',input=>{input.value='slow';input.closest('form').requestSubmit();});await page.waitForFunction(()=>window.DataphyrePanelEditors.assetState(document.querySelector('#asset-native'))?.busy===true);
				await page.click('#asset-native [data-dp-panel-editor-assets-close]');await page.waitForFunction(()=>!document.querySelector('#asset-native .dp-panel-editor-assets-dialog')?.open);
				await page.$eval('#asset-native .dp-panel-editor-assets-search input',input=>{input.value='';});await page.$eval('#asset-native [data-dp-panel-editor-assets-trigger]',trigger=>trigger.click());
				await page.waitForFunction(()=>{const editor=document.querySelector('#asset-native'),state=window.DataphyrePanelEditors.assetState(editor);return state?.open&&state.busy===false&&state.asset_count===2;},{timeout:10000});
				await page.keyboard.press('Escape');

				await page.$eval('#asset-external [data-dp-panel-editor-assets-trigger]',trigger=>trigger.click());await page.waitForSelector('#asset-external [data-dp-panel-editor-asset-id="hero"]');await page.click('#asset-external [data-dp-panel-editor-asset-id="hero"] .dp-panel-editor-asset-choose');
				await page.waitForFunction(()=>document.querySelector('#asset-external [data-dp-panel-editor-source]')?.value.includes('/panel/media/hero.png'));
				const external=await page.$eval('#asset-external',editor=>({value:editor.querySelector('[data-dp-panel-editor-source]').value,insertions:window.__dpAssetLab.externalInserts,state:window.DataphyrePanelEditors.state(editor)}));
				assert(external.insertions===1&&external.value.includes('<img')&&external.value.includes('src="/panel/media/hero.png"')&&external.state?.mounted,'External editor did not receive the sanitized provider asset: '+JSON.stringify(external)+'.');

				await page.evaluate(()=>window.__dpMakeAssetEditor('asset-http','http','/editor-assets-fixture','verified-token'));
				await page.waitForFunction(()=>document.querySelector('#asset-http [data-dp-panel-editor-assets-trigger]')?.hidden===false);await page.$eval('#asset-http [data-dp-panel-editor-assets-trigger]',trigger=>trigger.click());
				await page.waitForSelector('#asset-http [data-dp-panel-editor-asset-id="http_asset"]',{timeout:10000});
				assert(httpRequests.length===1,'HTTP asset bridge emitted '+httpRequests.length+' browse requests instead of one.');
				const httpRequest=httpRequests[0],httpBody=JSON.parse(httpRequest.body||'{}');
				assert(httpRequest.method==='POST'&&new URL(httpRequest.url).origin===new URL(baseUrl).origin&&httpRequest.headers['x-panel-verify']==='verified-token'&&httpBody.operation==='browse','HTTP asset bridge omitted its same-origin verification contract: '+JSON.stringify(httpRequest)+'.');
				await page.keyboard.press('Escape');
				await page.evaluate(()=>window.__dpMakeAssetEditor('asset-missing-token','http','/editor-assets-fixture',null));
				await page.waitForFunction(()=>document.querySelector('#asset-missing-token [data-dp-panel-editor-assets-trigger]')?.hidden===false);await page.$eval('#asset-missing-token [data-dp-panel-editor-assets-trigger]',trigger=>trigger.click());
				await page.waitForFunction(()=>document.querySelector('#asset-missing-token .dp-panel-editor-assets-status')?.textContent==='Assets could not be loaded.');
				assert(httpRequests.length===1,'A missing verification token reached the asset endpoint.');
				await page.keyboard.press('Escape');

				const lifecycle=await page.evaluate(async()=>{
					const api=window.DataphyrePanelEditors,wait=milliseconds=>new Promise(resolve=>setTimeout(resolve,milliseconds)),editor=document.querySelector('#asset-native'),form=editor.closest('form'),trigger=editor.querySelector('[data-dp-panel-editor-assets-trigger]');
					const moveHost=document.createElement('div');document.querySelector('main.dp-panel').appendChild(moveHost);moveHost.appendChild(form);await wait(80);const moved=api.assetState(editor)?.ready===true&&!trigger.hidden;
					form.remove();for(let attempt=0;attempt<100&&api.assetState(editor)!==null;attempt++){await wait(20);}const detached=api.assetState(editor)===null&&trigger.hidden&&trigger.disabled&&!editor.querySelector('.dp-panel-editor-assets-dialog');
					const external=document.querySelector('#asset-external');external.closest('form').remove();for(let attempt=0;attempt<100&&api.assetState(external)!==null;attempt++){await wait(20);}api.unregisterAssets('asset_fixture');
					return {moved,detached,externalDetached:api.assetState(external)===null,assets:api.list().assets,lab:{...window.__dpAssetLab}};
				});
				assert(lifecycle.moved&&lifecycle.detached&&lifecycle.externalDetached&&!lifecycle.assets.includes('asset_fixture'),'Asset picker same-turn move, detached teardown, or bridge unregister failed: '+JSON.stringify(lifecycle)+'.');
				assert(lifecycle.lab.uploads===1&&lifecycle.lab.deletes.join(',')==='uploaded'&&lifecycle.lab.browse.some(query=>query.cursor==='next_page')&&lifecycle.lab.browse.some(query=>query.search==='hero'),'Asset UI did not route browse, pagination, search, upload, and delete exactly once: '+JSON.stringify(lifecycle.lab)+'.');
				assert(interception.errors.length===0,'Asset request interception failed: '+interception.errors.join('; '));
				return {setup,initial,insertion,narrow,external,http:{count:httpRequests.length,operation:httpBody.operation,verified:httpRequest.headers['x-panel-verify']==='verified-token'},lifecycle};
			}
			finally{await interception.dispose();}
		});

		await probe('interactive widget actions refresh once, restore focus, and unmount cleanly',async page=>{
			await open(page,baseUrl,{width:1024,height:800});
			const islandSelector='[data-dp-widget-island][data-dp-widget-component="counter"]';
			await page.waitForSelector(islandSelector+'[data-dp-widget-enhanced="1"]',{visible:true,timeout:10000});
			const readWidget=()=>page.$eval(islandSelector,island=>({
				assets:String(document.querySelector('main.dp-panel')?.dataset.dpPanelAssets||'').split(/\s+/).filter(Boolean),
				value:Number(island.querySelector('[data-dp-widget-bind="value"]')?.textContent||NaN),
				version:Number(island.dataset.dpWidgetVersion||0),
				status:island.dataset.dpWidgetStatus||'',
				enhanced:island.dataset.dpWidgetEnhanced==='1',
				busy:island.getAttribute('aria-busy'),
				actionHidden:island.querySelector('[data-dp-widget-action="increment"]')?.hidden,
				actionDisabled:island.querySelector('[data-dp-widget-action="increment"]')?.disabled,
				refreshHidden:island.querySelector('[data-dp-widget-refresh-action]')?.hidden,
				refreshDisabled:island.querySelector('[data-dp-widget-refresh-action]')?.disabled,
				widgetListenerCount:window.DataphyrePanel?.runtimeController?.controllers?.widget_runtime?.listenerCount||0,
			}));
			const widgetResponse=operation=>page.waitForResponse(response=>{
				if(response.request().method()!=='POST'||!response.url().includes('dp_panel_widget_runtime=1')){return false;}
				try{return JSON.parse(response.request().postData()||'{}').operation===operation;}catch{return false;}
			},{timeout:10000});
			const initial=await readWidget();
			assert(initial.assets.includes('widget-runtime'),'Interactive island did not select the widget-runtime asset capability.');
			assert(initial.enhanced&&initial.version>=1&&Number.isFinite(initial.value),'Interactive island did not expose a valid enhanced initial state.');
			assert(initial.actionHidden===false&&!initial.actionDisabled&&initial.refreshHidden===false&&!initial.refreshDisabled,'Widget actions did not progressively enhance into enabled controls.');

			const actionResponsePromise=widgetResponse('action');
			await page.click(islandSelector+' [data-dp-widget-action="increment"]');
			const actionResponse=await actionResponsePromise;
			const actionBody=await actionResponse.json();
			await page.waitForFunction((selector,version,value)=>{
				const island=document.querySelector(selector);
				return Number(island?.dataset.dpWidgetVersion)===version+1&&Number(island?.querySelector('[data-dp-widget-bind="value"]')?.textContent)===value+1&&island?.getAttribute('aria-busy')==='false';
			},{timeout:10000},islandSelector,initial.version,initial.value);
			const afterAction=await readWidget();
			const actionFocus=await page.evaluate(selector=>document.activeElement===document.querySelector(selector+' [data-dp-widget-action="increment"]'),islandSelector);
			assert(actionResponse.status()===200&&actionBody.type==='panel_widget_interaction_result'&&actionBody.state?.status==='ready','Widget action response broke the public result envelope.');
			assert(afterAction.value===initial.value+1&&afterAction.version===initial.version+1&&actionFocus,'Widget action did not update exactly once and restore focus.');

			const refreshResponsePromise=widgetResponse('refresh');
			await page.click(islandSelector+' [data-dp-widget-refresh-action]');
			const refreshResponse=await refreshResponsePromise;
			const refreshBody=await refreshResponse.json();
			await page.waitForFunction((selector,version,value)=>{
				const island=document.querySelector(selector);
				return Number(island?.dataset.dpWidgetVersion)===version+1&&Number(island?.querySelector('[data-dp-widget-bind="value"]')?.textContent)===value+10&&island?.getAttribute('aria-busy')==='false';
			},{timeout:10000},islandSelector,afterAction.version,afterAction.value);
			const afterRefresh=await readWidget();
			const refreshFocus=await page.evaluate(selector=>document.activeElement===document.querySelector(selector+' [data-dp-widget-refresh-action]'),islandSelector);
			assert(refreshResponse.status()===200&&refreshBody.state?.status==='ready','Widget refresh response broke the public result envelope.');
			assert(afterRefresh.value===afterAction.value+10&&afterRefresh.version===afterAction.version+1&&refreshFocus,'Widget refresh did not update exactly once and restore focus.');

			const reload=await page.evaluate(async selector=>{
				const before=window.DataphyrePanel?.runtimeController;
				const physical=Array.from(document.querySelectorAll('script[src*="dp_panel_asset=panel-runtime-"]'));
				const scripts=physical.length>0?physical:Array.from(document.querySelectorAll('script[src*="dp_panel_asset=panel.js"]'));
				const island=document.querySelector(selector);
				if(!before||scripts.length===0||!island){throw new Error('Widget hot-reload fixture is incomplete.');}
				const beforeListeners=before.controllers?.widget_runtime?.listenerCount||0;
				const beforeValue=Number(island.querySelector('[data-dp-widget-bind="value"]')?.textContent||NaN);
				const beforeVersion=Number(island.dataset.dpWidgetVersion||0);
				const sources=[];
				for(const script of scripts){
					const url=new URL(script.src,location.href);
					url.searchParams.set('dp_widget_reload',String(Date.now())+'-'+sources.length);
					const response=await fetch(url);
					if(!response.ok){throw new Error('Widget runtime asset could not be reloaded: HTTP '+response.status+'.');}
					sources.push(await response.text());
				}
				for(const source of sources){(0,eval)(source);}
				await new Promise(resolve=>setTimeout(resolve,100));
				const after=window.DataphyrePanel?.runtimeController;
				return {
					replaced:before!==after,
					previousDisposed:before.disposed===true,
					previousSignalAborted:before.signal?.aborted===true,
					beforeListeners,
					afterListeners:after?.controllers?.widget_runtime?.listenerCount||0,
					beforeValue,
					afterValue:Number(island.querySelector('[data-dp-widget-bind="value"]')?.textContent||NaN),
					beforeVersion,
					afterVersion:Number(island.dataset.dpWidgetVersion||0),
					enhanced:island.dataset.dpWidgetEnhanced==='1',
					delivery:physical.length>0?'physical':'aggregate',
					runtimeAssets:scripts.length,
				};
			},islandSelector);
			assert(reload.replaced&&reload.previousDisposed&&reload.previousSignalAborted,'Widget runtime reload did not dispose the previous managed controller.');
			assert(reload.enhanced&&reload.afterValue===reload.beforeValue&&reload.afterVersion===reload.beforeVersion,'Widget runtime reload lost the live island state.');
			assert(reload.beforeListeners>0&&reload.afterListeners===reload.beforeListeners,'Widget runtime managed-listener ownership changed across reload.');

			let reloadActionRequests=0;
			const countReloadAction=request=>{
				if(request.method()!=='POST'||!request.url().includes('dp_panel_widget_runtime=1')){return;}
				try{if(JSON.parse(request.postData()||'{}').operation==='action'){reloadActionRequests++;}}catch{}
			};
			page.on('request',countReloadAction);
			const reloadActionResponsePromise=widgetResponse('action');
			await page.click(islandSelector+' [data-dp-widget-action="increment"]');
			await reloadActionResponsePromise;
			await page.waitForFunction((selector,version,value)=>{
				const island=document.querySelector(selector);
				return Number(island?.dataset.dpWidgetVersion)===version+1&&Number(island?.querySelector('[data-dp-widget-bind="value"]')?.textContent)===value+1;
			},{timeout:10000},islandSelector,afterRefresh.version,afterRefresh.value);
			await new Promise(resolve=>setTimeout(resolve,350));
			page.off('request',countReloadAction);
			const afterReloadAction=await readWidget();
			assert(reloadActionRequests===1,'One post-reload Widget click emitted '+reloadActionRequests+' action requests.');
			assert(afterReloadAction.status==='ready'&&afterReloadAction.version===afterRefresh.version+1&&afterReloadAction.value===afterRefresh.value+1,'Post-reload Widget action did not settle exactly once.');

			let unmountRequests=0;
			const countUnmount=request=>{
				if(request.method()!=='POST'||!request.url().includes('dp_panel_widget_runtime=1')){return;}
				try{if(JSON.parse(request.postData()||'{}').operation==='unmount'){unmountRequests++;}}catch{}
			};
			page.on('request',countUnmount);
			const unmountResponsePromise=widgetResponse('unmount');
			await page.evaluate(selector=>{
				window.__dpRemovedWidget=document.querySelector(selector);
				window.__dpRemovedWidget.remove();
			},islandSelector);
			const unmountResponse=await unmountResponsePromise;
			await new Promise(resolve=>setTimeout(resolve,350));
			page.off('request',countUnmount);
			const teardown=await page.evaluate(()=>({ended:window.__dpRemovedWidget?.dataset.dpWidgetEnded==='1',connected:window.__dpRemovedWidget?.isConnected===true}));
			assert(unmountResponse.status()===200&&String(unmountResponse.headers()['content-type']||'').includes('application/json'),'Widget removal did not complete the public JSON unmount transport contract.');
			assert(unmountResponse.headers()['x-dataphyre-panel-widget-terminal']==='sealed','Widget unmount response retained credentials or violated the terminal-state envelope.');
			assert(unmountRequests===1&&teardown.ended&&!teardown.connected,'Widget teardown did not emit exactly one unmount and release the detached island.');
			return {initial,afterAction,afterRefresh,reload,afterReloadAction,unmountRequests,terminalCredentialsStripped:true,teardown};
		});

		await probe('modal runtime hot reload preserves nested history and releases orphaned busy UI',async page=>{
			await open(page,baseUrl+'/orders?group=status&view=review&page=1',{width:1024,height:760});
			const parentUrl=modalFixtureUrl('hot-reload-parent');
			const childUrl=modalFixtureUrl('hot-reload-child');
			const parentPath=new URL(parentUrl).pathname;
			const childPath=new URL(childUrl).pathname;
			const parentHtml=modalFixtureDocument(
				'<form id="dp-modal-hot-reload-parent" class="dp-panel-form">'
				+'<label class="dp-panel-field"><span>Parent value</span><input name="parent_value" value="parent"></label>'
				+modalFixtureTrigger('dp-modal-hot-reload-child-trigger','hot-reload-child','Open hot reload child',{stack:'push',exit:'auto'})
				+'</form>'
			);
			const childHtml=modalFixtureDocument(
				'<form id="dp-modal-hot-reload-child" class="dp-panel-form">'
				+'<label class="dp-panel-field"><span>Child value</span><input name="child_value" value="child"></label>'
				+'<button id="dp-modal-hot-reload-submit" type="submit">Save child</button>'
				+'</form>'
			);
			const interception=await installRequestFixtures(page,request=>{
				const pathname=new URL(request.url()).pathname;
				if(pathname===parentPath){return {status:200,contentType:'text/html',body:parentHtml};}
				if(pathname===childPath){return {status:200,contentType:'text/html',body:childHtml};}
				return null;
			});
			await appendModalFixtureTrigger(page,{id:'dp-modal-hot-reload-parent-trigger',href:parentUrl,heading:'Open hot reload parent',stack:'replace',exit:'auto'});
			await page.click('#dp-modal-hot-reload-parent-trigger');
			await waitForModalContent(page,'#dp-modal-hot-reload-parent');
			await page.evaluate(()=>document.querySelector('#dp-modal-hot-reload-child-trigger').click());
			await waitForModalContent(page,'#dp-modal-hot-reload-child');
			const lifecycle=await page.evaluate(async()=>{
				const before=window.DataphyrePanel?.runtimeController;
				const state=window.DataphyrePanel?.modalState;
				const root=document.querySelector('.dp-panel-modal-root');
				const form=root?.querySelector('#dp-modal-hot-reload-child');
				const submit=root?.querySelector('#dp-modal-hot-reload-submit');
				const physical=Array.from(document.querySelectorAll('script[src*="dp_panel_asset=panel-runtime-"]'));
				const scripts=physical.length>0?physical:Array.from(document.querySelectorAll('script[src*="dp_panel_asset=panel.js"]'));
				if(!before||!state||!root||!form||!submit||scripts.length===0){return {error:'Hot reload modal fixture is incomplete.'};}
				const orphanController=new AbortController();
				state.requestSequence+=1;
				state.activeRequest={id:state.requestSequence,controller:orphanController,signal:orphanController.signal};
				root.classList.add('dp-panel-modal-busy');
				root.dataset.dpPanelModalStatus='working';
				form.classList.add('dp-panel-form-loading');
				submit.dataset.dpPanelModalPreviousDisabled='0';
				submit.disabled=true;
				const sources=[];
				for(const script of scripts){
					const url=new URL(script.src,location.href);
					url.searchParams.set('dp_modal_reload',String(Date.now())+'-'+sources.length);
					const response=await fetch(url);
					if(!response.ok){return {error:'Modal runtime asset reload failed with HTTP '+response.status+'.'};}
					sources.push(await response.text());
				}
				for(const source of sources){(0,eval)(source);}
				await new Promise(resolve=>setTimeout(resolve,100));
				const after=window.DataphyrePanel?.runtimeController;
				const nextState=window.DataphyrePanel?.modalState;
				return {
					replaced:before!==after,
					previousDisposed:before.disposed===true,
					orphanAborted:orphanController.signal.aborted,
					statePreserved:state===nextState,
					stackDepth:Array.isArray(nextState?.stack)?nextState.stack.length:-1,
					activeRequest:nextState?.activeRequest||null,
					busy:root.classList.contains('dp-panel-modal-busy'),
					loading:form.classList.contains('dp-panel-form-loading'),
					submitDisabled:submit.disabled,
					status:root.dataset.dpPanelModalStatus||'',
					childVisible:!!root.querySelector('#dp-modal-hot-reload-child'),
					backVisible:!root.querySelector('.dp-panel-modal-back')?.hidden,
					delegateBound:typeof root.dpPanelModalClickHandler==='function',
					delivery:physical.length>0?'physical':'aggregate',
					runtimeAssets:scripts.length,
				};
			});
			assert(!lifecycle.error,lifecycle.error);
			assert(lifecycle.replaced&&lifecycle.previousDisposed&&lifecycle.orphanAborted,'Hot reload did not dispose both runtime and modal-owned request state.');
			assert(lifecycle.statePreserved&&lifecycle.stackDepth===1&&lifecycle.activeRequest===null,'Hot reload lost or corrupted nested modal history.');
			assert(!lifecycle.busy&&!lifecycle.loading&&!lifecycle.submitDisabled&&lifecycle.status==='','Hot reload left orphaned busy UI behind.');
			assert(lifecycle.childVisible&&lifecycle.backVisible&&lifecycle.delegateBound,'Hot reload damaged the active daughter modal or its controls.');
			await page.click('.dp-panel-modal-back');
			await waitForModalContent(page,'#dp-modal-hot-reload-parent');
			await page.click('.dp-panel-modal-close');
			await page.waitForFunction(()=>document.querySelector('.dp-panel-modal-root')?.hidden===true);
			assert(interception.errors.length===0,'Hot reload modal interception failed: '+interception.errors.join('; '));
			return lifecycle;
		});

		await probe('filter modal has dialog semantics and closes with Escape',async page=>{
			await open(page,baseUrl+'/orders?group=status&view=review&page=1',{width:1440,height:1000});
			await page.click('.dp-panel-filter-trigger');
			await page.waitForSelector('.dp-panel-modal-root:not([hidden]) .dp-panel-modal',{visible:true,timeout:10000});
			const modal=await page.$eval('.dp-panel-modal-root:not([hidden])',element=>{
				const dialog=element.querySelector('.dp-panel-modal');
				const rect=dialog.getBoundingClientRect();
				return {role:element.getAttribute('role') || dialog.getAttribute('role'),label:element.getAttribute('aria-label') || element.getAttribute('aria-labelledby') || dialog.getAttribute('aria-label') || dialog.getAttribute('aria-labelledby'),width:Math.round(rect.width),height:Math.round(rect.height)};
			});
			assert(modal.role==='dialog','Filter overlay does not expose dialog semantics.');
			assert(Boolean(modal.label),'Filter dialog has no accessible name.');
			await page.keyboard.press('Escape');
			await page.waitForFunction(()=>!document.querySelector('.dp-panel-modal-root:not([hidden])'));
			return modal;
		});

		await probe('create slide-over stays inside the viewport and restores focus',async page=>{
			await open(page,baseUrl+'/orders?group=status&view=review&page=1',{width:1024,height:800});
			const workspaceUrl=page.url();
			await page.focus('.dp-panel-commandbar-create');
			await page.click('.dp-panel-commandbar-create');
			await page.waitForSelector('.dp-panel-modal-root:not([hidden]) .dp-panel-modal',{visible:true,timeout:10000});
			const showedBusyState=await page.$eval('.dp-panel-modal-root:not([hidden])',element=>element.classList.contains('dp-panel-modal-busy'));
			assert(showedBusyState,'Create modal skipped its loading state.');
			await page.waitForFunction(()=>!document.querySelector('.dp-panel-modal-root:not([hidden])')?.classList.contains('dp-panel-modal-busy'),{timeout:15000});
			const modal=await page.$eval('.dp-panel-modal-root:not([hidden]) .dp-panel-modal',element=>{const rect=element.getBoundingClientRect();return {left:Math.round(rect.left),right:Math.round(rect.right),top:Math.round(rect.top),bottom:Math.round(rect.bottom),width:Math.round(rect.width)};});
			assert(modal.left>=0 && modal.right<=1024 && modal.top>=0 && modal.bottom<=800,'Create modal escapes the viewport.');
			const signedPair=await page.$eval('.dp-panel-modal-root:not([hidden]) form',form=>{
				const target=form.querySelector('input[name="return_to"]');
				const intent=form.querySelector('[data-dp-panel-navigation-intent="1"]');
				return {target:target?.value||'',intent:intent?.value||'',intentName:intent?.name||''};
			});
			const signedTarget=new URL(signedPair.target,workspaceUrl);
			const workspaceTarget=new URL(workspaceUrl);
			assert(signedPair.intentName==='navigation_intent'&&signedPair.intent.split('.').length===3,'Create modal did not receive a signed navigation intent pair.');
			assert(signedTarget.pathname===workspaceTarget.pathname&&signedTarget.searchParams.get('group')==='status'&&signedTarget.searchParams.get('view')==='review'&&signedTarget.searchParams.get('page')==='1','Signed return lost the filtered/page workspace: '+signedPair.target+'.');

			const tamper=await page.$eval('.dp-panel-modal-root:not([hidden]) form',async form=>{
				const body=new FormData(form);
				const changedTarget=new URL(String(body.get('return_to')||''),location.href);
				changedTarget.searchParams.set('page','9');
				body.set('return_to',changedTarget.pathname+changedTarget.search+changedTarget.hash);
				const action=new URL(form.action,location.href);
				action.searchParams.set('__panel_partial','modal');
				const response=await fetch(action.toString(),{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'DataphyrePanelModal'},body});
				return {status:response.status,text:await response.text(),url:location.href,formStillVisible:!!document.querySelector('.dp-panel-modal-root:not([hidden]) form')};
			});
			assert(tamper.status===422&&tamper.text.includes('navigation_intent_rejected'),'Tampered return pairing did not fail closed: '+JSON.stringify(tamper)+'.');
			assert(tamper.url===workspaceUrl&&tamper.formStillVisible,'Rejected return pairing navigated or dismissed the active modal.');

			await page.$eval('.dp-panel-modal-root:not([hidden]) form',form=>{
				const set=(name,value)=>{
					const control=form.elements.namedItem(name);
					if(!control){return;}
					control.value=value;
					control.dispatchEvent(new Event('input',{bubbles:true}));
					control.dispatchEvent(new Event('change',{bubbles:true}));
				};
				set('customer','Signed navigation browser order');
				set('email','signed-navigation@example.test');
				const market=form.querySelector('input[name="market"][value="CA"]');
				if(market){market.checked=true;market.dispatchEvent(new Event('change',{bubbles:true}));}
				set('channel','marketplace');
				set('status','review');
				set('risk','low');
				set('total','42.50');
				set('owner','Mina');
				set('sla_minutes','30');
				form.requestSubmit();
			});
			await page.waitForFunction(()=>document.querySelector('.dp-panel-modal-root')?.hidden===true,{timeout:15000});
			await page.waitForFunction(()=>document.querySelector('main.dp-panel')?.getAttribute('aria-busy')!=='true',{timeout:15000});
			const returned=new URL(page.url());
			assert(returned.pathname===workspaceTarget.pathname&&returned.searchParams.get('group')==='status'&&returned.searchParams.get('view')==='review'&&returned.searchParams.get('page')==='1','Successful modal save did not preserve the filtered/page workspace: '+page.url()+'.');
			const restored=await page.evaluate(()=>document.activeElement?.classList.contains('dp-panel-commandbar-create'));
			assert(restored,'Focus was not restored to the create trigger after signed save.');
			return {...modal,showedBusyState,signedReturn:signedPair.target,tamperStatus:tamper.status};
		},{allowConsoleErrors:['/orders/store?__panel_partial=modal']});

		await probe('selected order edit transition and CSV export keep their distinct request contracts',async page=>{
			await open(page,baseUrl+'/orders?view=active',{width:1280,height:900});
			const selectReviewOrder=async()=>page.evaluate(()=>{
				const input=document.querySelector('tr[data-order-status="review"] input[name="selected[]"]');
				if(!input){return '';}
				if(!input.checked){input.click();}
				return input.value;
			});
			const selectedKey=await selectReviewOrder();
			assert(selectedKey!=='','Orders fixture has no review record for the bulk transition flow.');
			await page.waitForFunction(()=>{
				const bar=document.querySelector('[data-dp-panel-bulk-bar]');
				return !!(bar&&!bar.hidden&&Number(bar.querySelector('[data-dp-panel-selected-count]')?.textContent||0)===1);
			});

			const editRequestPromise=page.waitForRequest(request=>{
				const target=new URL(request.url());
				return target.pathname.endsWith('/bulk_update')&&request.method()==='POST';
			},{timeout:15000});
			await page.click('[data-dp-panel-action-name="bulk_update"]');
			const editRequest=await editRequestPromise;
			await waitForModalContent(page,'.dp-panel-form');
			const editResponse=editRequest.response();
			assert(editResponse&&editResponse.status()<400,'Edit selected returned HTTP '+(editResponse?editResponse.status():0)+'.');
			const editModal=await page.evaluate(()=>{
				const root=document.querySelector('.dp-panel-modal-root:not([hidden])');
				return {
					status:root?.dataset.dpPanelModalStatus||'',
					text:root?.innerText||'',
					selected:Array.from(root?.querySelectorAll('.dp-panel-form input[name="selected[]"]')||[]).map(input=>input.value),
				};
			});
			assert(editModal.status!=='error'&&!editModal.text.includes('could not load in the dialog'),'Edit selected opened a modal error instead of the returned form.');
			assert(editModal.selected.includes(selectedKey),'Edit selected did not preserve the selected record in its returned form.');
			await page.click('.dp-panel-modal-close');
			await page.waitForFunction(()=>document.querySelector('.dp-panel-modal-root')?.hidden===true);

			assert(await selectReviewOrder()===selectedKey,'The review record selection was lost before confirmation.');
			await page.click('[data-dp-panel-action-name="bulk_approve"]');
			await waitForModalContent(page,'.dp-panel-modal-confirmation');
			const confirmation=await page.$eval('.dp-panel-modal-root:not([hidden])',root=>({
				status:root.dataset.dpPanelModalStatus||'',
				text:root.innerText||'',
			}));
			assert(confirmation.status!=='error'&&confirmation.text.includes('Approve selected records'),'Approve selected did not open its confirmation dialog.');
			const transitionResponsePromise=page.waitForResponse(response=>{
				const target=new URL(response.url());
				return target.pathname.endsWith('/bulk_transition')&&response.request().method()==='POST';
			},{timeout:15000});
			const transitionRefreshPromise=page.waitForResponse(response=>{
				const target=new URL(response.url());
				const headers=response.request().headers();
				return response.request().method()==='GET'
					&&target.searchParams.get('__panel_partial')==='fragment'
					&&headers['x-requested-with']==='DataphyrePanelFragment';
			},{timeout:15000});
			let releaseTransitionRefresh;
			const transitionRefreshGate=new Promise(resolve=>{releaseTransitionRefresh=resolve;});
			let observeTransitionRefresh;
			const transitionRefreshObserved=new Promise(resolve=>{observeTransitionRefresh=resolve;});
			const refreshInterception=await installRequestFixtures(page,request=>{
				const target=new URL(request.url());
				const headers=request.headers();
				if(request.method()!=='GET'||target.searchParams.get('__panel_partial')!=='fragment'||headers['x-requested-with']!=='DataphyrePanelFragment'){
					return null;
				}
				observeTransitionRefresh();
				return {continueRequest:true,waitFor:transitionRefreshGate};
			});
			await page.click('[data-dp-panel-modal-submit]');
			const transitionResponse=await transitionResponsePromise;
			assert(transitionResponse.status()<400,'Approve selected returned HTTP '+transitionResponse.status()+'.');
			let transitionPending=null;
			let transitionRefresh=null;
			try{
				await Promise.race([
					transitionRefreshObserved,
					delay(15000).then(()=>{throw new Error('Approve selected did not begin its redirected workspace refresh.');}),
				]);
				transitionPending=await page.evaluate(()=>{
					const root=document.querySelector('.dp-panel-modal-root:not([hidden])');
					const submit=root?.querySelector('[data-dp-panel-modal-submit]');
					return {
						visible:!!root,
						busy:!!root?.classList.contains('dp-panel-modal-busy'),
						disabled:!!submit?.disabled,
						loading:!!submit?.classList.contains('dp-panel-action-loading'),
						status:root?.dataset.dpPanelModalStatus||'',
					};
				});
				releaseTransitionRefresh();
				transitionRefresh=await transitionRefreshPromise;
			}
			finally{
				releaseTransitionRefresh();
				await refreshInterception.dispose();
			}
			assert(transitionPending&&transitionPending.visible&&transitionPending.busy&&transitionPending.disabled&&transitionPending.loading,'Approve selected released its modal controls before the redirected workspace refresh settled: '+JSON.stringify(transitionPending)+'.');
			assert(transitionRefresh.status()<400,'Approve selected workspace refresh returned HTTP '+transitionRefresh.status()+'.');
			assert(refreshInterception.errors.length===0,'Approve selected refresh interception failed: '+refreshInterception.errors.join('; '));
			await page.waitForFunction(key=>{
				const root=document.querySelector('.dp-panel-modal-root');
				const row=document.querySelector('tr[data-dp-panel-record-key="'+key+'"]');
				return (!root||root.hidden)&&row?.dataset.orderStatus==='paid';
			},{timeout:15000},selectedKey);
			const transitionModal=await page.evaluate(()=>{
				const root=document.querySelector('.dp-panel-modal-root');
				return {hidden:!root||root.hidden,status:root?.dataset.dpPanelModalStatus||'',text:root?.innerText||''};
			});
			assert(transitionModal.hidden,'Approve selected left its confirmation dialog open: '+JSON.stringify(transitionModal)+'.');
			const approvedStatus=await page.$eval('tr[data-dp-panel-record-key="'+selectedKey+'"]',row=>row.dataset.orderStatus||'');

			const exportSelection=await page.evaluate(()=>{
				const input=document.querySelector('input[name="selected[]"]');
				if(!input){return '';}
				if(!input.checked){input.click();}
				return input.value;
			});
			assert(exportSelection!=='','Orders fixture has no record available for selected CSV export.');
			let exportTransport=null;
			const interception=await installRequestFixtures(page,request=>{
				const target=new URL(request.url());
				if(!target.pathname.endsWith('/bulk_export')){return null;}
				const headers=request.headers();
				exportTransport={
					method:request.method(),
					url:request.url(),
					postData:request.postData()||'',
					resourceType:request.resourceType(),
					navigation:request.isNavigationRequest(),
					requestedWith:headers['x-requested-with']||'',
				};
				return {
					status:200,
					contentType:'text/csv',
					headers:{'Content-Disposition':'attachment; filename="selected-orders.csv"','Cache-Control':'no-store'},
					body:'id\n'+exportSelection+'\n',
				};
			});
			const exportRequestPromise=page.waitForRequest(request=>new URL(request.url()).pathname.endsWith('/bulk_export'),{timeout:10000});
			await page.evaluate(()=>{
				const button=Array.from(document.querySelectorAll('.dp-panel-bulk-action')).find(candidate=>(candidate.textContent||'').includes('Export CSV'));
				if(button){button.click();}
			});
			await exportRequestPromise;
			await delay(160);
			assert(exportTransport!==null,'Export CSV did not submit to the bulk export endpoint.');
			const exportUrl=new URL(exportTransport.url);
			assert(exportTransport.method==='POST'&&exportTransport.navigation&&exportTransport.resourceType==='document','Export CSV was not submitted as native document navigation: '+JSON.stringify(exportTransport)+'.');
			assert(exportTransport.requestedWith===''&&!exportUrl.searchParams.has('__panel_partial')&&!exportTransport.postData.includes('__panel_partial'),'Export CSV was intercepted by the Ajax fragment pipeline: '+JSON.stringify(exportTransport)+'.');
			const exportFormData=new URLSearchParams(exportTransport.postData);
			assert(exportFormData.getAll('selected[]').includes(exportSelection),'Export CSV did not submit the selected record: '+JSON.stringify(exportTransport)+'.');
			const modalAfterExport=await page.evaluate(()=>{
				const root=document.querySelector('.dp-panel-modal-root');
				return {
					hidden:!root||root.hidden,
					status:root?.dataset.dpPanelModalStatus||'',
					confirmation:!!root?.querySelector('.dp-panel-modal-confirmation'),
					text:root?.innerText||'',
				};
			});
			// Hidden modal DOM is retained to preserve live parent history; only exposed state can be stale here.
			assert(modalAfterExport.hidden&&modalAfterExport.status!=='error'&&!modalAfterExport.confirmation,'Export CSV exposed stale approval modal state: '+JSON.stringify(modalAfterExport)+'.');
			assert(interception.errors.length===0,'Bulk export interception failed: '+interception.errors.join('; '));
			return {
				selectedKey,
				editStatus:editResponse.status(),
				transitionStatus:transitionResponse.status(),
				transitionPending,
				approvedStatus,
				exportSelection,
				exportTransport,
			};
		});

		await probe('nested modal exits restore live parent value, listener, focus, and scroll',async page=>{
			await open(page,baseUrl+'/orders?group=status&view=review&page=1',{width:1024,height:720});
			const parentUrl=modalFixtureUrl('live-parent');
			const childUrl=modalFixtureUrl('live-child');
			const parentPath=new URL(parentUrl).pathname;
			const childPath=new URL(childUrl).pathname;
			const parentHtml=modalFixtureDocument(
				'<form id="dp-modal-live-parent" class="dp-panel-form" method="post" action="'+escapeFixtureHtml(modalFixtureUrl('live-parent-submit'))+'">'
				+'<label class="dp-panel-field"><span>Parent value</span><input id="dp-modal-live-parent-value" name="parent_value" value="server value"></label>'
				+'<button id="dp-modal-live-parent-listener" type="button">Parent listener</button>'
				+modalFixtureTrigger('dp-modal-live-child-trigger','live-child','Open child',{stack:'push',exit:'auto'})
				+'<div aria-hidden="true" style="height:1500px"></div>'
				+'</form>'
			);
			const childHtml=modalFixtureDocument(
				'<form id="dp-modal-live-child" class="dp-panel-form" method="post" action="'+escapeFixtureHtml(modalFixtureUrl('live-child-submit'))+'">'
				+'<label class="dp-panel-field"><span>Child value</span><input id="dp-modal-live-child-value" name="child_value" value="child"></label>'
				+'<button id="dp-modal-live-child-cancel" type="button" data-dp-panel-modal-cancel>Cancel</button>'
				+'</form>'
			);
			const interception=await installRequestFixtures(page,request=>{
				const pathname=new URL(request.url()).pathname;
				if(pathname===parentPath){return {status:200,contentType:'text/html',body:parentHtml};}
				if(pathname===childPath){return {status:200,contentType:'text/html',body:childHtml};}
				return null;
			});
			await appendModalFixtureTrigger(page,{id:'dp-modal-live-parent-trigger',href:parentUrl,heading:'Open live parent',stack:'replace',exit:'auto'});
			await page.click('#dp-modal-live-parent-trigger');
			await waitForModalContent(page,'#dp-modal-live-parent');
			const baseline=await page.evaluate(()=>{
				const root=document.querySelector('.dp-panel-modal-root');
				const body=root.querySelector('.dp-panel-modal-body');
				const input=body.querySelector('#dp-modal-live-parent-value');
				const listener=body.querySelector('#dp-modal-live-parent-listener');
				window.__dpModalLiveParent={input,listener,clicks:0};
				listener.addEventListener('click',()=>{window.__dpModalLiveParent.clicks+=1;});
				input.value='edited parent value';
				body.scrollTop=Math.min(240,Math.max(0,body.scrollHeight-body.clientHeight));
				input.focus();
				return {scrollTop:Math.round(body.scrollTop),maxScroll:Math.round(body.scrollHeight-body.clientHeight)};
			});
			assert(baseline.maxScroll>0&&baseline.scrollTop>0,'Parent modal fixture did not create a scrollable body.');
			const exitCases=[
				{name:'X',run:()=>page.click('.dp-panel-modal-close')},
				{name:'backdrop',run:()=>page.$eval('.dp-panel-modal-root',root=>root.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true})))},
				{name:'Escape',run:()=>page.keyboard.press('Escape')},
				{name:'Cancel',run:()=>page.click('#dp-modal-live-child-cancel')},
				{name:'Back',run:()=>page.click('.dp-panel-modal-back')},
			];
			const exits=[];
			for(let index=0;index<exitCases.length;index++){
				const exitCase=exitCases[index];
				await page.evaluate(()=>{
					const input=document.querySelector('#dp-modal-live-parent-value');
					input.focus();
					document.querySelector('#dp-modal-live-child-trigger').click();
				});
				await waitForModalContent(page,'#dp-modal-live-child');
				const backVisible=await page.$eval('.dp-panel-modal-back',button=>!button.hidden);
				assert(backVisible,'Child modal did not expose a parent Back affordance for '+exitCase.name+'.');
				await exitCase.run();
				await waitForModalContent(page,'#dp-modal-live-parent');
				await page.waitForFunction(()=>document.activeElement?.id==='dp-modal-live-parent-value',{timeout:4000});
				const restored=await page.evaluate(()=>{
					const root=document.querySelector('.dp-panel-modal-root');
					const body=root.querySelector('.dp-panel-modal-body');
					const state=window.__dpModalLiveParent;
					const input=body.querySelector('#dp-modal-live-parent-value');
					const listener=body.querySelector('#dp-modal-live-parent-listener');
					listener.click();
					return {
						sameInput:input===state.input,
						sameListener:listener===state.listener,
						value:input.value,
						clicks:state.clicks,
						focus:document.activeElement?.id||'',
						scrollTop:Math.round(body.scrollTop),
					};
				});
				assert(restored.sameInput&&restored.sameListener,exitCase.name+' cloned or replaced the live parent DOM.');
				assert(restored.value==='edited parent value',exitCase.name+' lost the live parent input value.');
				assert(restored.clicks===index+1,exitCase.name+' lost or duplicated the parent event listener.');
				assert(restored.focus==='dp-modal-live-parent-value',exitCase.name+' did not restore parent focus.');
				assert(Math.abs(restored.scrollTop-baseline.scrollTop)<=2,exitCase.name+' changed parent modal scroll from '+baseline.scrollTop+' to '+restored.scrollTop+'.');
				exits.push({name:exitCase.name,...restored});
			}
			assert(interception.errors.length===0,'Modal fixture interception failed: '+interception.errors.join('; '));
			return {baseline,exits};
		});

		await probe('browser Back traverses daughter modals before leaving the page',async page=>{
			await open(page,baseUrl+'/orders?group=status&view=review&page=1',{width:1024,height:760});
			const initialUrl=page.url();
			const parentUrl=modalFixtureUrl('browser-back-parent');
			const childUrl=modalFixtureUrl('browser-back-child');
			const routes={
				[new URL(parentUrl).pathname]:modalFixtureDocument('<form id="dp-modal-browser-parent" class="dp-panel-form"><label><span>Draft</span><input id="dp-modal-browser-draft" value="initial"></label>'+modalFixtureTrigger('dp-modal-browser-child-trigger','browser-back-child','Open browser child',{stack:'push',exit:'auto'})+'</form>'),
				[new URL(childUrl).pathname]:modalFixtureDocument('<form id="dp-modal-browser-child" class="dp-panel-form"><input value="daughter"></form>'),
			};
			const interception=await installRequestFixtures(page,request=>{
				const body=routes[new URL(request.url()).pathname];
				return body?{status:200,contentType:'text/html',body}:null;
			});
			await appendModalFixtureTrigger(page,{id:'dp-modal-browser-parent-trigger',href:parentUrl,heading:'Open browser parent',stack:'replace',exit:'auto'});
			const openParent=async()=>{
				await page.click('#dp-modal-browser-parent-trigger');
				await waitForModalContent(page,'#dp-modal-browser-parent');
			};
			await openParent();
			await page.click('#dp-modal-browser-child-trigger');
			await waitForModalContent(page,'#dp-modal-browser-child');
			const daughter=await page.evaluate(()=>({depth:window.DataphyrePanel.modalState.historyDepth,stack:window.DataphyrePanel.modalState.stack.length,state:history.state}));
			assert(daughter.depth===2&&daughter.stack===1&&daughter.state?.dataphyrePanelModal===true,'Daughter modal did not own a browser-history level: '+JSON.stringify(daughter)+'.');
			await page.evaluate(()=>history.back());
			await waitForModalContent(page,'#dp-modal-browser-parent');
			await page.waitForFunction(()=>window.DataphyrePanel.modalState.historyDepth===1&&window.DataphyrePanel.modalState.stack.length===0);
			const parent=await page.evaluate(()=>({depth:window.DataphyrePanel.modalState.historyDepth,stack:window.DataphyrePanel.modalState.stack.length,url:location.href}));
			assert(parent.url===initialUrl,'Browser Back changed the workspace URL while restoring the parent modal.');
			await page.evaluate(()=>history.back());
			await page.waitForFunction(()=>document.querySelector('.dp-panel-modal-root')?.hidden===true&&window.DataphyrePanel.modalState.historyDepth===0);
			assert(page.url()===initialUrl,'Closing the top modal via browser Back left the workspace.');

			await openParent();
			await page.$eval('#dp-modal-browser-draft',input=>{input.value='unsaved';input.dispatchEvent(new Event('input',{bubbles:true}));});
			await page.evaluate(()=>history.back());
			await page.waitForFunction(()=>window.DataphyrePanel.modalState.historyDepth===1&&document.querySelector('#dp-modal-browser-parent'));
			const guarded=await page.evaluate(()=>({depth:window.DataphyrePanel.modalState.historyDepth,dirty:document.querySelector('#dp-modal-browser-draft')?.value,armed:window.DataphyrePanel.modalState.discardArmedUntil>Date.now(),url:location.href}));
			assert(guarded.dirty==='unsaved'&&guarded.armed&&guarded.url===initialUrl,'Browser Back bypassed the dirty parent guard: '+JSON.stringify(guarded)+'.');
			await page.evaluate(()=>history.back());
			await page.waitForFunction(()=>document.querySelector('.dp-panel-modal-root')?.hidden===true&&window.DataphyrePanel.modalState.historyDepth===0);
			assert(page.url()===initialUrl,'Confirmed browser Back left the workspace instead of closing the modal.');
			assert(interception.errors.length===0,'Browser Back fixture interception failed: '+interception.errors.join('; '));
			return {daughter,parent,guarded};
		});

		await probe('nested modal stack honors explicit replace and clear plus stay, back, and close exits',async page=>{
			await open(page,baseUrl+'/orders?group=status&view=review&page=1',{width:1024,height:760});
			const routes={
				parent:modalFixtureDocument(
					'<form id="dp-modal-stack-parent" class="dp-panel-form">'
					+modalFixtureTrigger('dp-modal-stack-child-trigger','stack-child','Stack child',{stack:'push',exit:'auto'})
					+modalFixtureTrigger('dp-modal-exit-stay-trigger','exit-stay','Stay child',{stack:'push',exit:'stay'})
					+modalFixtureTrigger('dp-modal-exit-back-trigger','exit-back','Back child',{stack:'push',exit:'back'})
					+modalFixtureTrigger('dp-modal-exit-close-trigger','exit-close','Close child',{stack:'push',exit:'close'})
					+'</form>'
				),
				child:modalFixtureDocument(
					'<form id="dp-modal-stack-child" class="dp-panel-form">'
					+modalFixtureTrigger('dp-modal-explicit-replace-trigger','explicit-replace','Explicit replacement',{stack:'replace',exit:'auto'})
					+modalFixtureTrigger('dp-modal-explicit-clear-trigger','explicit-clear','Explicit clear',{stack:'clear',exit:'auto'})
					+'</form>'
				),
				replace:modalFixtureDocument('<form id="dp-modal-explicit-replace" class="dp-panel-form"><p>Replacement level</p></form>'),
				clear:modalFixtureDocument('<form id="dp-modal-explicit-clear" class="dp-panel-form"><p>Cleared flow</p></form>'),
				stay:modalFixtureDocument('<form id="dp-modal-exit-stay" class="dp-panel-form"><button type="button" data-dp-panel-modal-cancel>Cancel</button></form>'),
				back:modalFixtureDocument('<form id="dp-modal-exit-back" class="dp-panel-form"><button type="button" data-dp-panel-modal-cancel>Cancel</button></form>'),
				close:modalFixtureDocument('<form id="dp-modal-exit-close" class="dp-panel-form"><button type="button" data-dp-panel-modal-cancel>Cancel</button></form>'),
			};
			const routeByPath={
				[new URL(modalFixtureUrl('stack-parent')).pathname]:routes.parent,
				[new URL(modalFixtureUrl('stack-child')).pathname]:routes.child,
				[new URL(modalFixtureUrl('explicit-replace')).pathname]:routes.replace,
				[new URL(modalFixtureUrl('explicit-clear')).pathname]:routes.clear,
				[new URL(modalFixtureUrl('exit-stay')).pathname]:routes.stay,
				[new URL(modalFixtureUrl('exit-back')).pathname]:routes.back,
				[new URL(modalFixtureUrl('exit-close')).pathname]:routes.close,
			};
			const interception=await installRequestFixtures(page,request=>{
				const body=routeByPath[new URL(request.url()).pathname];
				return body?{status:200,contentType:'text/html',body}:null;
			});
			await appendModalFixtureTrigger(page,{id:'dp-modal-stack-parent-trigger',href:modalFixtureUrl('stack-parent'),heading:'Open stack parent',stack:'replace',exit:'auto'});
			const openParent=async()=>{
				await page.click('#dp-modal-stack-parent-trigger');
				await waitForModalContent(page,'#dp-modal-stack-parent');
			};
			await openParent();
			await page.evaluate(()=>document.querySelector('#dp-modal-stack-child-trigger').click());
			await waitForModalContent(page,'#dp-modal-stack-child');
			await page.evaluate(()=>document.querySelector('#dp-modal-explicit-replace-trigger').click());
			await waitForModalContent(page,'#dp-modal-explicit-replace');
			const replaceState=await page.$eval('.dp-panel-modal-root',root=>({strategy:root.dataset.dpPanelModalStack,backHidden:root.querySelector('.dp-panel-modal-back').hidden}));
			assert(replaceState.strategy==='replace'&&!replaceState.backHidden,'Explicit replace did not preserve only the pre-child parent level.');
			await page.click('.dp-panel-modal-back');
			await waitForModalContent(page,'#dp-modal-stack-parent');

			await page.evaluate(()=>document.querySelector('#dp-modal-stack-child-trigger').click());
			await waitForModalContent(page,'#dp-modal-stack-child');
			await page.evaluate(()=>document.querySelector('#dp-modal-explicit-clear-trigger').click());
			await waitForModalContent(page,'#dp-modal-explicit-clear');
			const clearState=await page.$eval('.dp-panel-modal-root',root=>({strategy:root.dataset.dpPanelModalStack,backHidden:root.querySelector('.dp-panel-modal-back').hidden}));
			assert(clearState.strategy==='clear'&&clearState.backHidden,'Explicit clear left modal history available.');
			await page.click('.dp-panel-modal-close');
			await page.waitForFunction(()=>document.querySelector('.dp-panel-modal-root')?.hidden===true);
			assert(!await page.$('#dp-modal-stack-parent'),'Clearing and closing unexpectedly restored the parent modal.');

			await openParent();
			await page.evaluate(()=>document.querySelector('#dp-modal-exit-stay-trigger').click());
			await waitForModalContent(page,'#dp-modal-exit-stay');
			await page.click('.dp-panel-modal-close');
			await delay(80);
			assert(await page.$('#dp-modal-exit-stay'),'The stay exit strategy allowed X to leave the child.');
			await page.click('#dp-modal-exit-stay [data-dp-panel-modal-cancel]');
			await delay(80);
			assert(await page.$('#dp-modal-exit-stay'),'The stay exit strategy allowed Cancel to leave the child.');
			await page.click('.dp-panel-modal-back');
			await waitForModalContent(page,'#dp-modal-stack-parent');

			await page.evaluate(()=>document.querySelector('#dp-modal-exit-back-trigger').click());
			await waitForModalContent(page,'#dp-modal-exit-back');
			await page.keyboard.press('Escape');
			await waitForModalContent(page,'#dp-modal-stack-parent');

			await page.evaluate(()=>document.querySelector('#dp-modal-exit-close-trigger').click());
			await waitForModalContent(page,'#dp-modal-exit-close');
			await page.$eval('.dp-panel-modal-root',root=>root.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true})));
			await page.waitForFunction(()=>document.querySelector('.dp-panel-modal-root')?.hidden===true);
			assert(!await page.$('#dp-modal-stack-parent'),'The close exit strategy restored a parent instead of closing the flow.');
			assert(interception.errors.length===0,'Modal strategy interception failed: '+interception.errors.join('; '));
			return {replaceState,clearState,exitStrategies:['stay','back','close']};
		});

		await probe('422 and 500 modal failures never report success or navigate',async page=>{
			await open(page,baseUrl+'/orders?group=status&view=review&page=1',{width:1024,height:760});
			const initialUrl=page.url();
			const workspaceHtml=await page.content();
			const successMarker='DP_MODAL_FAILURE_MUST_NOT_SUCCEED';
			const forbidden422=modalFixtureUrl('forbidden-422');
			const forbidden500=modalFixtureUrl('forbidden-500');
			const navigationAttempts=[];
			const parentHtml=modalFixtureDocument(
				'<form id="dp-modal-failure-parent" class="dp-panel-form">'
				+modalFixtureTrigger('dp-modal-failure-422-trigger','failure-422-form','Open 422 form',{stack:'push',exit:'auto'})
				+modalFixtureTrigger('dp-modal-failure-500-trigger','failure-500-submit','Open 500 confirmation',{stack:'push',exit:'auto',fields:false,method:'POST',confirm:'Confirm the failing action',tag:'button'})
				+'</form>'
			);
			const invalidFormHtml=modalFixtureDocument(
				'<form id="dp-modal-failure-422-retry" class="dp-panel-form" method="post" action="'+escapeFixtureHtml(modalFixtureUrl('failure-422-submit'))+'">'
				+'<label class="dp-panel-field"><span>Required value</span><input name="required_value" aria-invalid="true" value=""></label>'
				+'<button type="submit">Retry</button>'
				+'</form>'
			);
			const form422Html=modalFixtureDocument(
				'<form id="dp-modal-failure-422-form" class="dp-panel-form" method="post" action="'+escapeFixtureHtml(modalFixtureUrl('failure-422-submit'))+'">'
				+'<label class="dp-panel-field"><span>Value</span><input name="value" value="invalid"></label>'
				+'<button id="dp-modal-failure-422-submit" type="submit">Submit 422</button>'
				+'</form>'
			);
			const paths={
				parent:new URL(modalFixtureUrl('failure-parent')).pathname,
				form422:new URL(modalFixtureUrl('failure-422-form')).pathname,
				submit422:new URL(modalFixtureUrl('failure-422-submit')).pathname,
				submit500:new URL(modalFixtureUrl('failure-500-submit')).pathname,
				forbidden422:new URL(forbidden422).pathname,
				forbidden500:new URL(forbidden500).pathname,
			};
			const interception=await installRequestFixtures(page,request=>{
				const pathname=new URL(request.url()).pathname;
				if(pathname===paths.parent){return {status:200,contentType:'text/html',body:parentHtml};}
				if(pathname===paths.form422){return {status:200,contentType:'text/html',body:form422Html};}
				if(pathname===paths.submit422&&request.method()==='POST'){
					return {status:422,contentType:'application/json',body:JSON.stringify({
						status:422,
						html:invalidFormHtml,
						redirect_to:forbidden422,
						effects:{modal_navigation:'close',close_modal:true},
						notifications:[{type:'success',message:successMarker+'-422'}],
					})};
				}
				if(pathname===paths.submit500&&request.method()==='POST'){
					return {status:500,contentType:'application/json',body:JSON.stringify({
						status:500,
						redirect_to:forbidden500,
						effects:{modal_navigation:'close',close_modal:true},
						notifications:[{type:'success',message:successMarker+'-500'}],
					})};
				}
				if(pathname===paths.forbidden422||pathname===paths.forbidden500){
					navigationAttempts.push(pathname);
					return {status:200,contentType:'text/html',body:workspaceHtml};
				}
				return null;
			});
			await page.evaluate(()=>{
				window.__dpModalFailureRefreshes=0;
				document.addEventListener('dataphyre:panel-refresh',()=>{window.__dpModalFailureRefreshes+=1;});
			});
			await appendModalFixtureTrigger(page,{id:'dp-modal-failure-parent-trigger',href:modalFixtureUrl('failure-parent'),heading:'Open failure parent',stack:'replace',exit:'auto'});
			await page.click('#dp-modal-failure-parent-trigger');
			await waitForModalContent(page,'#dp-modal-failure-parent');

			await page.evaluate(()=>document.querySelector('#dp-modal-failure-422-trigger').click());
			await waitForModalContent(page,'#dp-modal-failure-422-form');
			await page.waitForFunction(()=>document.querySelector('#dp-modal-failure-422-form')?.dataset.dpPanelModalPrepared==='1');
			await page.$eval('#dp-modal-failure-422-form',form=>form.requestSubmit());
			await page.waitForFunction(()=>{
				const root=document.querySelector('.dp-panel-modal-root');
				return !!(root&&!root.hidden&&!root.classList.contains('dp-panel-modal-busy')&&root.dataset.dpPanelModalStatus==='warning'&&root.querySelector('#dp-modal-failure-422-retry'));
			},{timeout:10000});
			const failure422=await page.evaluate(marker=>{
				const root=document.querySelector('.dp-panel-modal-root');
				return {hidden:root.hidden,status:root.dataset.dpPanelModalStatus||'',url:location.href,successVisible:document.body.innerText.includes(marker),refreshes:window.__dpModalFailureRefreshes};
			},successMarker);
			assert(!failure422.hidden&&failure422.status==='warning','422 did not stay open in a warning state.');
			assert(failure422.url===initialUrl&&failure422.refreshes===0,'422 refreshed or navigated the workspace.');
			assert(!failure422.successVisible&&navigationAttempts.length===0,'422 surfaced a success path or followed redirect_to.');
			await page.click('.dp-panel-modal-back');
			await delay(100);
			const validationDiscard=await page.evaluate(()=>{
				const root=document.querySelector('.dp-panel-modal-root');
				return {
					retryVisible:!!root?.querySelector('#dp-modal-failure-422-retry'),
					validationDirty:root?.dataset.dpPanelModalValidationDirty||'',
					discardArmed:(window.DataphyrePanel?.modalState?.discardArmedUntil||0)>Date.now(),
				};
			});
			assert(validationDiscard.retryVisible&&validationDiscard.validationDirty==='1'&&validationDiscard.discardArmed,'The first Back discarded a validation-error form instead of arming confirmation.');
			await page.click('.dp-panel-modal-back');
			await waitForModalContent(page,'#dp-modal-failure-parent');

			await page.evaluate(()=>document.querySelector('#dp-modal-failure-500-trigger').click());
			await waitForModalContent(page,'.dp-panel-modal-confirmation');
			await page.click('[data-dp-panel-modal-submit]');
			await delay(450);
			const failure500=await page.evaluate(marker=>{
				const root=document.querySelector('.dp-panel-modal-root');
				if(!root){
					return {missing:true,hidden:true,status:'',confirmation:false,url:location.href,successVisible:document.body.innerText.includes(marker),refreshes:window.__dpModalFailureRefreshes};
				}
				return {
					missing:false,
					hidden:root.hidden,
					status:root.dataset.dpPanelModalStatus||'',
					confirmation:!!root.querySelector('.dp-panel-modal-confirmation'),
					url:location.href,
					successVisible:document.body.innerText.includes(marker),
					refreshes:window.__dpModalFailureRefreshes,
				};
			},successMarker);
			assert(!failure500.hidden&&failure500.confirmation&&failure500.status==='error','500 left the confirmation flow or reported a non-error state.');
			assert(failure500.url===initialUrl&&failure500.refreshes===0,'500 refreshed or navigated the workspace.');
			assert(!failure500.successVisible&&navigationAttempts.length===0,'500 surfaced success or followed redirect_to.');
			assert(interception.errors.length===0,'Failure interception failed: '+interception.errors.join('; '));
			return {failure422,validationDiscard,failure500,navigationAttempts};
		},{allowConsoleErrors:['/__dataphyre_panel_modal_regression__/failure-422-submit','/__dataphyre_panel_modal_regression__/failure-500-submit']});

		await probe('successful stay-in-modal save advances the dirty baseline',async page=>{
			await open(page,baseUrl+'/orders?group=status&view=review&page=1',{width:1024,height:760});
			const workspaceHtml=await page.content();
			const formUrl=modalFixtureUrl('stay-baseline-form');
			const submitUrl=modalFixtureUrl('stay-baseline-submit');
			const formPath=new URL(formUrl).pathname;
			const submitPath=new URL(submitUrl).pathname;
			const formHtml=modalFixtureDocument(
				'<form id="dp-modal-stay-baseline-form" class="dp-panel-form" method="post" action="'+escapeFixtureHtml(submitUrl)+'">'
				+'<label class="dp-panel-field"><span>Saved value</span><input id="dp-modal-stay-baseline-value" name="saved_value" value="server value"></label>'
				+'<button id="dp-modal-stay-baseline-submit" type="submit">Save and stay</button>'
				+'</form>'
			);
			let submissions=0;
			const interception=await installRequestFixtures(page,request=>{
				const pathname=new URL(request.url()).pathname;
				if(pathname===formPath){return {status:200,contentType:'text/html',body:formHtml};}
				if(pathname===submitPath&&request.method()==='POST'){
					submissions+=1;
					return {status:200,contentType:'application/json',body:JSON.stringify({
						status:200,
						html:workspaceHtml,
						effects:{modal_navigation:'stay',close_modal:false},
					})};
				}
				return null;
			});
			await appendModalFixtureTrigger(page,{id:'dp-modal-stay-baseline-trigger',href:formUrl,heading:'Open stay baseline form',stack:'replace',exit:'auto'});
			await page.click('#dp-modal-stay-baseline-trigger');
			await waitForModalContent(page,'#dp-modal-stay-baseline-form');
			await page.waitForFunction(()=>document.querySelector('#dp-modal-stay-baseline-form')?.dataset.dpPanelModalPrepared==='1');
			await page.$eval('#dp-modal-stay-baseline-value',input=>{
				input.value='persisted value';
				input.dispatchEvent(new Event('input',{bubbles:true}));
			});
			await page.$eval('#dp-modal-stay-baseline-form',form=>form.requestSubmit());
			await page.waitForFunction(()=>{
				const root=document.querySelector('.dp-panel-modal-root');
				return !!(root&&!root.hidden&&!root.classList.contains('dp-panel-modal-busy')&&root.querySelector('#dp-modal-stay-baseline-form'));
			},{timeout:10000});
			await delay(260);
			const baseline=await page.evaluate(()=>{
				const root=document.querySelector('.dp-panel-modal-root');
				const input=root?.querySelector('#dp-modal-stay-baseline-value');
				return {
					value:input?.value||'',
					initial:input?.dataset.dpPanelModalInitial,
					validationDirty:root?.dataset.dpPanelModalValidationDirty||'',
					structuralDirty:root?.dataset.dpPanelModalStructuralDirty||'',
				};
			});
			assert(submissions===1,'Stay baseline fixture submitted '+submissions+' times.');
			assert(baseline.value==='persisted value'&&baseline.initial===baseline.value,'Successful stay did not advance the modal form baseline.');
			assert(!baseline.validationDirty&&!baseline.structuralDirty,'Successful stay left explicit dirty markers set.');
			await page.click('.dp-panel-modal-close');
			await page.waitForFunction(()=>document.querySelector('.dp-panel-modal-root')?.hidden===true);
			assert(interception.errors.length===0,'Stay baseline interception failed: '+interception.errors.join('; '));
			return {submissions,baseline,closedOnFirstAttempt:true};
		});

		await probe('discard confirmation never carries between parent and daughter modals',async page=>{
			await open(page,baseUrl+'/orders?group=status&view=review&page=1',{width:1024,height:760});
			const parentUrl=modalFixtureUrl('discard-scope-parent');
			const childUrl=modalFixtureUrl('discard-scope-child');
			const parentPath=new URL(parentUrl).pathname;
			const childPath=new URL(childUrl).pathname;
			const parentHtml=modalFixtureDocument(
				'<form id="dp-modal-discard-parent" class="dp-panel-form">'
				+'<label class="dp-panel-field"><span>Parent draft</span><input id="dp-modal-discard-parent-value" name="parent_value" value="parent"></label>'
				+modalFixtureTrigger('dp-modal-discard-child-trigger','discard-scope-child','Open discard child',{stack:'push',exit:'auto'})
				+'</form>'
			);
			const childHtml=modalFixtureDocument(
				'<form id="dp-modal-discard-child" class="dp-panel-form">'
				+'<label class="dp-panel-field"><span>Child draft</span><input id="dp-modal-discard-child-value" name="child_value" value="child"></label>'
				+'</form>'
			);
			const interception=await installRequestFixtures(page,request=>{
				const pathname=new URL(request.url()).pathname;
				if(pathname===parentPath){return {status:200,contentType:'text/html',body:parentHtml};}
				if(pathname===childPath){return {status:200,contentType:'text/html',body:childHtml};}
				return null;
			});
			await appendModalFixtureTrigger(page,{id:'dp-modal-discard-parent-trigger',href:parentUrl,heading:'Open discard parent',stack:'replace',exit:'auto'});
			await page.click('#dp-modal-discard-parent-trigger');
			await waitForModalContent(page,'#dp-modal-discard-parent');
			await page.$eval('#dp-modal-discard-parent-value',input=>{input.value='dirty parent';input.dispatchEvent(new Event('input',{bubbles:true}));});
			await page.click('.dp-panel-modal-close');
			await delay(80);
			const parentWarning=await page.evaluate(()=>({
				parentVisible:!!document.querySelector('#dp-modal-discard-parent'),
				armed:(window.DataphyrePanel?.modalState?.discardArmedUntil||0)>Date.now(),
			}));
			assert(parentWarning.parentVisible&&parentWarning.armed,'Parent dirty form did not arm its first discard confirmation.');

			await page.evaluate(()=>document.querySelector('#dp-modal-discard-child-trigger').click());
			await waitForModalContent(page,'#dp-modal-discard-child');
			const childEntry=await page.evaluate(()=>({discardArmedUntil:window.DataphyrePanel?.modalState?.discardArmedUntil||0}));
			assert(childEntry.discardArmedUntil===0,'Parent discard authorization leaked into the daughter modal.');
			await page.$eval('#dp-modal-discard-child-value',input=>{input.value='dirty child';input.dispatchEvent(new Event('input',{bubbles:true}));});
			await page.click('.dp-panel-modal-close');
			await delay(80);
			const childWarning=await page.evaluate(()=>({
				childVisible:!!document.querySelector('#dp-modal-discard-child'),
				parentVisible:!!document.querySelector('#dp-modal-discard-parent'),
				armed:(window.DataphyrePanel?.modalState?.discardArmedUntil||0)>Date.now(),
			}));
			assert(childWarning.childVisible&&!childWarning.parentVisible&&childWarning.armed,'Daughter form reused the parent discard authorization.');
			await page.click('.dp-panel-modal-close');
			await waitForModalContent(page,'#dp-modal-discard-parent');
			const parentReturn=await page.evaluate(()=>({discardArmedUntil:window.DataphyrePanel?.modalState?.discardArmedUntil||0,value:document.querySelector('#dp-modal-discard-parent-value')?.value||''}));
			assert(parentReturn.discardArmedUntil===0&&parentReturn.value==='dirty parent','Returning from daughter retained its discard authorization or lost the parent draft.');
			await page.click('.dp-panel-modal-close');
			await delay(80);
			assert(await page.$('#dp-modal-discard-parent'),'Daughter discard authorization silently closed the dirty parent.');
			assert(interception.errors.length===0,'Discard scope interception failed: '+interception.errors.join('; '));
			return {parentWarning,childEntry,childWarning,parentReturn};
		});

		await probe('multi-select, dynamic-control, and repeater edits all require discard confirmation',async page=>{
			await open(page,baseUrl+'/orders?group=status&view=review&page=1',{width:1024,height:760});
			const parentUrl=modalFixtureUrl('dirty-control-parent');
			const parentPath=new URL(parentUrl).pathname;
			const dirtyUrls={
				multiple:modalFixtureUrl('dirty-control-multiple'),
				dynamic:modalFixtureUrl('dirty-control-dynamic'),
				repeater:modalFixtureUrl('dirty-control-repeater'),
			};
			const dirtyPaths=Object.values(dirtyUrls).map(url=>new URL(url).pathname);
			const parentHtml=modalFixtureDocument(
				'<form id="dp-modal-dirty-parent" class="dp-panel-form">'
				+modalFixtureTrigger('dp-modal-dirty-multiple-trigger','dirty-control-multiple','Open multi-select dirty controls',{stack:'push',exit:'auto'})
				+modalFixtureTrigger('dp-modal-dirty-dynamic-trigger','dirty-control-dynamic','Open dynamic dirty controls',{stack:'push',exit:'auto'})
				+modalFixtureTrigger('dp-modal-dirty-repeater-trigger','dirty-control-repeater','Open repeater dirty controls',{stack:'push',exit:'auto'})
				+'</form>'
			);
			const dirtyHtml=modalFixtureDocument(
				'<form id="dp-modal-dirty-kinds" class="dp-panel-form">'
				+'<label class="dp-panel-field"><span>Choices</span><select id="dp-modal-dirty-multiple" name="choices[]" multiple>'
				+'<option value="alpha" selected>Alpha</option><option value="beta" selected>Beta</option><option value="gamma">Gamma</option>'
				+'</select></label>'
				+'<div id="dp-modal-dynamic-host"></div>'
				+'<div id="dp-modal-dirty-repeater" data-dp-panel-repeater data-dp-panel-repeater-next="0">'
				+'<div data-dp-panel-repeater-items></div>'
				+'<template data-dp-panel-repeater-template><div data-dp-panel-repeater-row><input name="rows[__INDEX__][value]" value=""></div></template>'
				+'<button id="dp-modal-dirty-repeater-add" type="button" data-dp-panel-repeater-add>Add row</button>'
				+'</div>'
				+'</form>'
			);
			const interception=await installRequestFixtures(page,request=>{
				const pathname=new URL(request.url()).pathname;
				if(pathname===parentPath){return {status:200,contentType:'text/html',body:parentHtml};}
				if(dirtyPaths.includes(pathname)){return {status:200,contentType:'text/html',body:dirtyHtml};}
				return null;
			});
			await appendModalFixtureTrigger(page,{id:'dp-modal-dirty-parent-trigger',href:parentUrl,heading:'Open dirty control parent',stack:'replace',exit:'auto'});
			await page.click('#dp-modal-dirty-parent-trigger');
			await waitForModalContent(page,'#dp-modal-dirty-parent');
			const openDirtyModal=async name=>{
				await page.evaluate(selector=>document.querySelector(selector).click(),'#dp-modal-dirty-'+name+'-trigger');
				await waitForModalContent(page,'#dp-modal-dirty-kinds');
				await page.waitForFunction(()=>{
					const form=document.querySelector('#dp-modal-dirty-kinds');
					const repeater=document.querySelector('#dp-modal-dirty-repeater');
					return form?.dataset.dpPanelModalPrepared==='1'&&repeater?.dataset.dpPanelRepeaterReady==='1';
				});
			};
			const expectDoubleClose=async label=>{
				await page.click('.dp-panel-modal-close');
				await delay(80);
				const firstAttemptStayed=!!(await page.$('#dp-modal-dirty-kinds'));
				assert(firstAttemptStayed,label+' edit closed without discard confirmation.');
				await page.click('.dp-panel-modal-close');
				await waitForModalContent(page,'#dp-modal-dirty-parent');
				return firstAttemptStayed;
			};

			await openDirtyModal('multiple');
			const multiple=await page.$eval('#dp-modal-dirty-multiple',select=>{
				select.options[1].selected=false;
				select.options[2].selected=true;
				select.dispatchEvent(new Event('change',{bubbles:true}));
				return {value:select.value,selected:Array.from(select.selectedOptions).map(option=>option.value)};
			});
			assert(multiple.value==='alpha'&&multiple.selected.join(',')==='alpha,gamma','Multi-select fixture did not preserve its first selected value while changing later selections.');
			const multipleDirty=await expectDoubleClose('Multi-select');

			await openDirtyModal('dynamic');
			const dynamic=await page.$eval('#dp-modal-dynamic-host',host=>{
				const input=document.createElement('input');
				input.id='dp-modal-dynamic-value';
				input.name='dynamic_value';
				input.defaultValue='';
				input.value='typed after baseline';
				host.appendChild(input);
				return {value:input.value,defaultValue:input.defaultValue,hasBaseline:input.dataset.dpPanelModalInitial!==undefined};
			});
			assert(dynamic.value!==dynamic.defaultValue&&!dynamic.hasBaseline,'Dynamic control fixture unexpectedly had a captured baseline.');
			const dynamicDirty=await expectDoubleClose('Dynamic control');

			const repeaterTrace={};
			repeaterTrace.beforeOpen=await page.evaluate(()=>({
				rootStructuralDirty:document.querySelector('.dp-panel-modal-root')?.dataset.dpPanelModalStructuralDirty||'',
				stackDepth:window.DataphyrePanel?.modalState?.stack?.length||0,
				stackStructuralDirty:window.DataphyrePanel?.modalState?.stack?.at(-1)?.structuralDirty||'',
			}));
			await openDirtyModal('repeater');
			repeaterTrace.afterOpen=await page.evaluate(()=>({
				rootStructuralDirty:document.querySelector('.dp-panel-modal-root')?.dataset.dpPanelModalStructuralDirty||'',
				stackDepth:window.DataphyrePanel?.modalState?.stack?.length||0,
				stackStructuralDirty:window.DataphyrePanel?.modalState?.stack?.at(-1)?.structuralDirty||'',
			}));
			await page.$eval('#dp-modal-dirty-repeater-add',button=>button.click());
			const repeater=await page.evaluate(()=>({
				rows:document.querySelectorAll('#dp-modal-dirty-repeater [data-dp-panel-repeater-row]').length,
				structuralDirty:document.querySelector('.dp-panel-modal-root')?.dataset.dpPanelModalStructuralDirty||'',
			}));
			assert(repeater.rows===1&&repeater.structuralDirty==='1','Repeater fixture did not create and mark a structural edit: '+JSON.stringify(repeater));
			await page.click('.dp-panel-modal-close');
			await delay(80);
			repeaterTrace.afterFirstClose=await page.evaluate(()=>({
				rootStructuralDirty:document.querySelector('.dp-panel-modal-root')?.dataset.dpPanelModalStructuralDirty||'',
				stackDepth:window.DataphyrePanel?.modalState?.stack?.length||0,
				stackStructuralDirty:window.DataphyrePanel?.modalState?.stack?.at(-1)?.structuralDirty||'',
				childVisible:!!document.querySelector('#dp-modal-dirty-kinds'),
			}));
			assert(repeaterTrace.afterFirstClose.childVisible,'Repeater edit closed without discard confirmation.');
			await page.click('.dp-panel-modal-close');
			await waitForModalContent(page,'#dp-modal-dirty-parent');
			repeaterTrace.afterSecondClose=await page.evaluate(()=>({
				rootStructuralDirty:document.querySelector('.dp-panel-modal-root')?.dataset.dpPanelModalStructuralDirty||'',
				stackDepth:window.DataphyrePanel?.modalState?.stack?.length||0,
				stackStructuralDirty:window.DataphyrePanel?.modalState?.stack?.at(-1)?.structuralDirty||'',
				parentVisible:!!document.querySelector('#dp-modal-dirty-parent'),
			}));
			const repeaterDirty=repeaterTrace.afterFirstClose.childVisible&&repeaterTrace.afterSecondClose.parentVisible;
			const parentStructuralDirty=await page.$eval('.dp-panel-modal-root',root=>root.dataset.dpPanelModalStructuralDirty||'');
			assert(parentStructuralDirty==='','Daughter repeater structural dirty state leaked into the restored parent modal: '+JSON.stringify(repeaterTrace));
			assert(interception.errors.length===0,'Dirty control interception failed: '+interception.errors.join('; '));
			return {multiple,multipleDirty,dynamic,dynamicDirty,repeater,repeaterDirty,parentStructuralDirty,repeaterTrace};
		});

		await probe('workspace shortcuts are gated while a modal owns focus',async page=>{
			const modalUrl=modalFixtureUrl('shortcut-gate');
			const modalPath=new URL(modalUrl).pathname;
			const modalHtml=modalFixtureDocument(
				'<form id="dp-modal-shortcut-gate" class="dp-panel-form">'
				+'<button id="dp-modal-shortcut-sentinel" type="button">Modal focus sentinel</button>'
				+'</form>'
			);
			const interception=await installRequestFixtures(page,request=>new URL(request.url()).pathname===modalPath?{status:200,contentType:'text/html',body:modalHtml}:null);
			const shortcuts=[
				{name:'command_palette',key:'k',code:'KeyK',ctrlKey:true},
				{name:'table_search',key:'/',code:'Slash'},
				{name:'filters',key:'f',code:'KeyF'},
				{name:'columns',key:'c',code:'KeyC'},
				{name:'sidebar_search',key:'n',code:'KeyN'},
			];
			const states=[];
			for(const shortcut of shortcuts){
				await open(page,baseUrl+'/orders?group=status&view=review&page=1',{width:1024,height:760});
				await appendModalFixtureTrigger(page,{id:'dp-modal-shortcut-gate-trigger',href:modalUrl,heading:'Open shortcut gate',stack:'replace',exit:'auto'});
				await page.click('#dp-modal-shortcut-gate-trigger');
				await waitForModalContent(page,'#dp-modal-shortcut-gate');
				const state=await page.evaluate(definition=>{
					const root=document.querySelector('.dp-panel-modal-root');
					const sentinel=document.querySelector('#dp-modal-shortcut-sentinel');
					sentinel.focus();
					const event=new KeyboardEvent('keydown',{
						key:definition.key,
						code:definition.code,
						ctrlKey:definition.ctrlKey===true,
						bubbles:true,
						cancelable:true,
					});
					sentinel.dispatchEvent(event);
					const commandRoot=document.querySelector('.dp-panel-command-root');
					const parentDialog=root.querySelector(':scope > .dp-panel-modal');
					return {
						name:definition.name,
						defaultPrevented:event.defaultPrevented,
						commandOpen:!!(commandRoot&&!commandRoot.hidden),
						commandInsideModal:!!(commandRoot&&root.contains(commandRoot)),
						commandScoped:commandRoot?.dataset.dpPanelCommandModalScoped||'',
						commandLabels:commandRoot&&!commandRoot.hidden?Array.from(commandRoot.querySelectorAll('.dp-panel-command-item strong')).map(element=>element.textContent.trim()):[],
						parentDialogInert:parentDialog?.inert===true,
						parentDialogAriaHidden:parentDialog?.getAttribute('aria-hidden')||'',
						modalIntact:!!root.querySelector('#dp-modal-shortcut-gate'),
						activeInsideModal:root.contains(document.activeElement),
						activeId:document.activeElement?.id||'',
						columnPickerOpen:document.querySelector('.dp-panel-column-picker')?.open===true,
					};
				},shortcut);
				states.push(state);
			}
			const allowedModalCommands=['Save current view','Copy current URL','Show keyboard shortcuts'];
			const failedStates=states.filter(state=>{
				if(!state.modalIntact||!state.activeInsideModal||state.columnPickerOpen){return true;}
				if(state.name!=='command_palette'){return state.commandOpen;}
				return !state.commandOpen||!state.commandInsideModal||state.commandScoped!=='1'||!state.parentDialogInert||state.parentDialogAriaHidden!=='true'||state.commandLabels.length!==allowedModalCommands.length||state.commandLabels.some(label=>!allowedModalCommands.includes(label));
			});
			assert(failedStates.length===0,'Workspace shortcuts escaped the modal: '+JSON.stringify(failedStates));
			assert(interception.errors.length===0,'Shortcut gate interception failed: '+interception.errors.join('; '));
			return states;
		});

		await probe('stale out-of-order modal fetch cannot replace the newer flow',async page=>{
			await open(page,baseUrl+'/orders?group=status&view=review&page=1',{width:1024,height:760});
			const slowUrl=modalFixtureUrl('race-slow');
			const fastUrl=modalFixtureUrl('race-fast');
			const slowPath=new URL(slowUrl).pathname;
			const fastPath=new URL(fastUrl).pathname;
			const responseOrder=[];
			const interception=await installRequestFixtures(page,request=>{
				const pathname=new URL(request.url()).pathname;
				if(pathname===slowPath){
					setTimeout(()=>responseOrder.push('slow'),260);
					return {status:200,contentType:'text/html',body:modalFixtureDocument('<form id="dp-modal-race-slow" class="dp-panel-form"><input value="slow"></form>'),delayMs:260};
				}
				if(pathname===fastPath){
					setTimeout(()=>responseOrder.push('fast'),20);
					return {status:200,contentType:'text/html',body:modalFixtureDocument('<form id="dp-modal-race-fast" class="dp-panel-form"><input value="fast"></form>'),delayMs:20};
				}
				return null;
			});
			await page.evaluate(()=>{
				window.__dpModalNativeAbortController=window.AbortController;
				window.__dpModalSuppressedAborts=0;
				window.AbortController=class NonAbortingModalController{
					constructor(){this.controller=new window.__dpModalNativeAbortController();this.signal=this.controller.signal;}
					abort(){window.__dpModalSuppressedAborts+=1;}
				};
			});
			await appendModalFixtureTrigger(page,{id:'dp-modal-race-slow-trigger',href:slowUrl,heading:'Open slow modal',stack:'replace',exit:'auto'});
			await appendModalFixtureTrigger(page,{id:'dp-modal-race-fast-trigger',href:fastUrl,heading:'Open fast modal',stack:'replace',exit:'auto'});
			const slowRequest=page.waitForRequest(request=>new URL(request.url()).pathname===slowPath,{timeout:5000});
			await page.click('#dp-modal-race-slow-trigger');
			await slowRequest;
			const fastRequest=page.waitForRequest(request=>new URL(request.url()).pathname===fastPath,{timeout:5000});
			await page.evaluate(()=>document.querySelector('#dp-modal-race-fast-trigger').click());
			await fastRequest;
			await waitForModalContent(page,'#dp-modal-race-fast');
			await delay(360);
			const race=await page.evaluate(()=>{
				const root=document.querySelector('.dp-panel-modal-root');
				return {
					hidden:root.hidden,
					title:root.querySelector('.dp-panel-modal-title h2')?.textContent||'',
					fast:!!root.querySelector('#dp-modal-race-fast'),
					slow:!!root.querySelector('#dp-modal-race-slow'),
					busy:root.classList.contains('dp-panel-modal-busy'),
					suppressedAborts:window.__dpModalSuppressedAborts,
				};
			});
			assert(responseOrder.join(',')==='fast,slow','Fixture responses did not complete out of order: '+responseOrder.join(',')+'.');
			assert(!race.hidden&&race.fast&&!race.slow&&!race.busy&&race.title==='Open fast modal','The stale slow response replaced or disturbed the newer modal.');
			assert(race.suppressedAborts>0,'Race fixture did not exercise cancellation plus stale-response ownership.');
			assert(interception.errors.length===0,'Race interception failed: '+interception.errors.join('; '));
			return {...race,responseOrder};
		});

		await probe('Save current view returns to its live parent modal on cancel, Back, and save',async page=>{
			await open(page,baseUrl+'/orders?group=status&view=review&page=1',{width:1024,height:720});
			const parentUrl=modalFixtureUrl('save-view-parent');
			const parentPath=new URL(parentUrl).pathname;
			const parentHtml=modalFixtureDocument(
				'<form id="dp-modal-save-view-parent" class="dp-panel-form">'
				+'<label class="dp-panel-field"><span>Parent draft</span><input id="dp-modal-save-view-parent-value" name="parent_draft" value="server draft"></label>'
				+'<button id="dp-modal-save-view-parent-listener" type="button">Parent listener</button>'
				+'<div aria-hidden="true" style="height:1400px"></div>'
				+'</form>'
			);
			const interception=await installRequestFixtures(page,request=>new URL(request.url()).pathname===parentPath?{status:200,contentType:'text/html',body:parentHtml}:null);
			await appendModalFixtureTrigger(page,{id:'dp-modal-save-view-parent-trigger',href:parentUrl,heading:'Open Save View parent',stack:'replace',exit:'auto'});
			await page.click('#dp-modal-save-view-parent-trigger');
			await waitForModalContent(page,'#dp-modal-save-view-parent');
			const baseline=await page.evaluate(()=>{
				const body=document.querySelector('.dp-panel-modal-body');
				const input=document.querySelector('#dp-modal-save-view-parent-value');
				const listener=document.querySelector('#dp-modal-save-view-parent-listener');
				window.__dpSaveViewParent={input,listener,clicks:0};
				listener.addEventListener('click',()=>{window.__dpSaveViewParent.clicks+=1;});
				input.value='edited parent draft';
				body.scrollTop=Math.min(220,Math.max(0,body.scrollHeight-body.clientHeight));
				input.focus();
				return {scrollTop:Math.round(body.scrollTop),maxScroll:Math.round(body.scrollHeight-body.clientHeight)};
			});
			assert(baseline.maxScroll>0&&baseline.scrollTop>0,'Save View parent fixture did not scroll.');
			const openSaveView=async()=>{
				await page.evaluate(()=>{
					document.querySelector('#dp-modal-save-view-parent-value').focus();
					document.querySelector('#dp-modal-save-view-parent-listener').dispatchEvent(new KeyboardEvent('keydown',{key:'k',code:'KeyK',ctrlKey:true,bubbles:true,cancelable:true}));
				});
				await page.waitForSelector('.dp-panel-command-root:not([hidden]) [data-dp-panel-command-input]',{visible:true,timeout:5000});
				await page.type('.dp-panel-command-root:not([hidden]) [data-dp-panel-command-input]','Save current view');
				await page.waitForFunction(()=>document.querySelector('.dp-panel-command-root:not([hidden]) .dp-panel-command-item.active strong')?.textContent.trim()==='Save current view',{timeout:5000});
				await page.keyboard.press('Enter');
				await waitForModalContent(page,'.dp-panel-save-view-form');
				const backVisible=await page.$eval('.dp-panel-modal-back',button=>!button.hidden);
				assert(backVisible,'Nested Save View modal did not expose Back.');
			};
			const assertParent=async expectedClicks=>{
				await waitForModalContent(page,'#dp-modal-save-view-parent');
				await page.waitForFunction(()=>document.activeElement?.id==='dp-modal-save-view-parent-value',{timeout:4000});
				const state=await page.evaluate(()=>{
					const body=document.querySelector('.dp-panel-modal-body');
					const input=document.querySelector('#dp-modal-save-view-parent-value');
					const listener=document.querySelector('#dp-modal-save-view-parent-listener');
					const saved=window.__dpSaveViewParent;
					listener.click();
					return {sameInput:input===saved.input,sameListener:listener===saved.listener,value:input.value,clicks:saved.clicks,focus:document.activeElement?.id||'',scrollTop:Math.round(body.scrollTop)};
				});
				assert(state.sameInput&&state.sameListener,'Save View replaced the live parent DOM.');
				assert(state.value==='edited parent draft','Save View lost the parent draft value.');
				assert(state.clicks===expectedClicks,'Save View lost or duplicated the parent listener.');
				assert(state.focus==='dp-modal-save-view-parent-value','Save View did not restore parent focus.');
				assert(Math.abs(state.scrollTop-baseline.scrollTop)<=2,'Save View changed parent scroll from '+baseline.scrollTop+' to '+state.scrollTop+'.');
				return state;
			};

			await openSaveView();
			await page.click('.dp-panel-save-view-form [data-dp-panel-modal-cancel]');
			const afterCancel=await assertParent(1);

			await openSaveView();
			await page.click('.dp-panel-modal-back');
			const afterBack=await assertParent(2);

			const label='Nested modal regression '+Date.now();
			await openSaveView();
			await page.$eval('.dp-panel-save-view-form input[name="label"]',(input,value)=>{input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}));},label);
			await page.$eval('.dp-panel-save-view-form',form=>form.requestSubmit());
			const afterSave=await assertParent(3);
			const saved=await page.evaluate(label=>{
				let found=false;
				const touched=[];
				for(let index=0;index<localStorage.length;index++){
					const key=localStorage.key(index);
					if(!key||key.indexOf('dataphyre_panel_')!==0){continue;}
					try{
						const value=JSON.parse(localStorage.getItem(key)||'null');
						if(!Array.isArray(value)){continue;}
						if(value.some(item=>item&&item.label===label)){found=true;touched.push(key);}
					}catch(error){}
				}
				touched.forEach(key=>{
					try{
						const value=JSON.parse(localStorage.getItem(key)||'[]');
						localStorage.setItem(key,JSON.stringify(value.filter(item=>!item||item.label!==label)));
					}catch(error){}
				});
				return {found,keys:touched};
			},label);
			assert(saved.found,'Save View did not persist the named view before returning to its parent.');
			assert(interception.errors.length===0,'Save View interception failed: '+interception.errors.join('; '));
			return {baseline,afterCancel,afterBack,afterSave,savedKeys:saved.keys.length};
		});

		await probe('keyboard traversal exposes visible focus treatment',async page=>{
			await open(page,baseUrl+'/orders/create',{width:1440,height:1000});
			await page.evaluate(()=>{document.activeElement?.blur();document.body.focus();});
			const focused=[];
			for(let index=0;index<14;index++){
				await page.keyboard.press('Tab');
				focused.push(await page.evaluate(()=>{
					const element=document.activeElement;
					const style=getComputedStyle(element);
					const rect=element.getBoundingClientRect();
					return {tag:element.tagName,class:String(element.className).slice(0,100),visible:rect.width>0&&rect.height>0,focusVisible:style.outlineStyle!=='none'||style.boxShadow!=='none'};
				}));
			}
			assert(focused.filter(item=>item.visible).length>=8,'Keyboard traversal reached too few visible controls.');
			assert(focused.filter(item=>item.visible).every(item=>item.focusVisible),'A keyboard-focused control has no visible focus treatment.');
			return {visited:focused.length,visible:focused.filter(item=>item.visible).length};
		});

		await probe('reduced-motion preference collapses every Panel animation and transition duration',async page=>{
			await page.emulateMediaFeatures([{name:'prefers-reduced-motion',value:'reduce'}]);
			await open(page,baseUrl+'/orders/create',{width:1440,height:1000});
			const result=await page.evaluate(()=>{
				const seconds=value=>String(value||'').split(',').map(part=>{
					const token=part.trim();
					if(token.endsWith('ms')){return parseFloat(token)/1000;}
					return parseFloat(token)||0;
				});
				const retained=[];
				let computedSurfaces=0;
				const roots=':is(.dp-panel,.dp-panel-modal-root,.dp-panel-command-root,.dp-panel-unsaved-root)';
				for(const element of document.querySelectorAll(roots+','+roots+' *')){
					for(const pseudo of [null,'::before','::after']){
						const style=getComputedStyle(element,pseudo);
						const animation=Math.max(0,...seconds(style.animationDuration));
						const transition=Math.max(0,...seconds(style.transitionDuration));
						computedSurfaces++;
						if(animation>0.01||transition>0.01){
							retained.push({selector:element.tagName.toLowerCase()+'.'+Array.from(element.classList).join('.'),pseudo,animation,transition});
						}
					}
				}
				return {active:matchMedia('(prefers-reduced-motion: reduce)').matches,computedSurfaces,retained:retained.slice(0,12),retainedCount:retained.length};
			});
			assert(result.active,'Chromium did not activate the reduced-motion preference.');
			assert(result.computedSurfaces>=30,'Reduced-motion coverage inspected too few rendered surfaces.');
			assert(result.retainedCount===0,'Reduced-motion mode retained long motion: '+JSON.stringify(result.retained));
			return result;
		});

		await probe('Operations OS console stays redacted accessible touch-safe and overflow-free',async page=>{
			const measurements=[];
			for(const viewport of [
				{name:'desktop-dark',width:1440,height:1000,mode:'dark'},
				{name:'desktop-light',width:1280,height:900,mode:'light'},
				{name:'tablet-dark',width:768,height:900,mode:'dark'},
				{name:'mobile-dark',width:390,height:844,mode:'dark'},
				{name:'narrow-dark',width:320,height:700,mode:'dark'},
			]){
				await page.setViewport({width:viewport.width,height:viewport.height,deviceScaleFactor:1});
				const url=new URL(baseUrl);
				url.searchParams.set('dp_panel_operations_console','1');
				url.searchParams.set('mode',viewport.mode);
				const response=await page.goto(url.toString(),{waitUntil:'domcontentloaded',timeout:30000});
				assert(response&&response.status()<400,'Operations OS showroom returned HTTP '+(response?response.status():0)+' at '+viewport.name+'.');
				await page.waitForSelector('main[data-dp-panel-kind="operations_os_console"]',{visible:true,timeout:15000});
				const state=await page.evaluate(()=>{
					const root=document.querySelector('main[data-dp-panel-kind="operations_os_console"]');
					const visible=element=>{const box=element.getBoundingClientRect();return box.width>0&&box.height>0;};
					const interactives=Array.from(root.querySelectorAll('.dp-ops-anchor-nav a,.dp-ops-attention-list a,.dp-panel-button')).filter(visible);
					const targets=interactives.map(element=>{const box=element.getBoundingClientRect();return {tag:element.tagName,label:(element.textContent||'').trim(),width:box.width,height:box.height};});
					const ids=Array.from(root.querySelectorAll('[id]')).map(element=>element.id);
					const duplicateIds=ids.filter((id,index)=>ids.indexOf(id)!==index);
					const tables=Array.from(root.querySelectorAll('.dp-panel-table-shell')).map(shell=>({clientWidth:shell.clientWidth,scrollWidth:shell.scrollWidth,overflow:Math.max(0,shell.scrollWidth-shell.clientWidth)}));
					const cells=Array.from(root.querySelectorAll('.dp-panel-table td'));
					const forms=Array.from(root.querySelectorAll('form'));
					const dispatchForms=forms.filter(form=>form.querySelector('input[name="operation"][value="dispatch"]'));
					const button=root.querySelector('.dp-panel-button');
					const navLink=root.querySelector('.dp-ops-anchor-nav a');
					const buttonStyle=button?getComputedStyle(button):null;
					const navStyle=navLink?getComputedStyle(navLink):null;
					const rootStyle=getComputedStyle(document.documentElement);
					return {
						documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
						rootOverflow:Math.max(0,root.scrollWidth-root.clientWidth),
						sections:root.querySelectorAll(':scope>.dp-panel-page-content>section').length,
						headings:root.querySelectorAll('h1').length,
						nestedCards:root.querySelectorAll('.dp-panel-card').length,
						duplicateIds,
						tables,
						unlabelledCells:cells.filter(cell=>!(cell.dataset.label||'').trim()).length,
						stackedCells:cells.length>0&&cells.every(cell=>getComputedStyle(cell).display==='grid'),
						targets,
						forms:{count:forms.length,nonPost:forms.filter(form=>form.method.toLowerCase()!=='post').length,missingCsrf:forms.filter(form=>!form.querySelector('input[name="_token"][value="CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC"]')).length,dispatch:dispatchForms.length,dispatchMissingKey:dispatchForms.filter(form=>!form.querySelector('input[name="idempotency_key"]')).length},
						contrast:{buttonForeground:buttonStyle?.color||'',buttonBackground:buttonStyle?.backgroundColor||'',navForeground:navStyle?.color||'',pageBackground:rootStyle.backgroundColor||''},
					};
				});
				assert(state.headings===1&&state.sections===8,'Operations OS semantic region count changed at '+viewport.name+': '+JSON.stringify({headings:state.headings,sections:state.sections}));
				assert(state.nestedCards===0,'Operations OS regressed into nested card presentation at '+viewport.name+'.');
				assert(state.duplicateIds.length===0,'Operations OS emitted duplicate IDs at '+viewport.name+': '+state.duplicateIds.join(', '));
				assert(state.documentOverflow<=1&&state.rootOverflow<=1,'Operations OS overflowed the page at '+viewport.name+': '+JSON.stringify(state));
				assert(state.tables.every(table=>table.overflow<=1),'Operations OS retained a horizontal table viewport at '+viewport.name+': '+JSON.stringify(state.tables));
				assert(state.unlabelledCells===0,'Operations OS has table cells without responsive labels at '+viewport.name+'.');
				assert(viewport.width>768||state.stackedCells,'Operations OS tables did not reflow at '+viewport.name+'.');
				assert(state.targets.length>=12&&state.targets.every(target=>target.height>=43.5),'Operations OS has undersized controls at '+viewport.name+': '+JSON.stringify(state.targets.filter(target=>target.height<43.5)));
				assert(state.forms.count>=7&&state.forms.nonPost===0&&state.forms.missingCsrf===0,'Operations OS control forms are not POST/CSRF complete at '+viewport.name+': '+JSON.stringify(state.forms));
				assert(state.forms.dispatch>=4&&state.forms.dispatchMissingKey===0,'Operations OS command controls lack idempotency at '+viewport.name+': '+JSON.stringify(state.forms));
				assert(contrastRatio(state.contrast.buttonForeground,state.contrast.buttonBackground)>=4.5,'Operations OS button contrast failed at '+viewport.name+': '+JSON.stringify(state.contrast));
				assert(contrastRatio(state.contrast.navForeground,state.contrast.pageBackground)>=4.5,'Operations OS navigation contrast failed at '+viewport.name+': '+JSON.stringify(state.contrast));
				measurements.push({viewport:viewport.name,...state});
			}
			return measurements;
		});

		await probe('required form validation exposes invalid controls without navigation',async page=>{
			await open(page,baseUrl+'/orders/create',{width:1440,height:1000});
			const validation=await page.evaluate(()=>{
				const form=document.querySelector('form.dp-panel-form');
				const before=location.href;
				const invalidEvents=[];
				form.querySelectorAll('input,select,textarea').forEach(control=>control.addEventListener('invalid',()=>invalidEvents.push(control.name || control.id || control.tagName),{once:true}));
				const valid=form.checkValidity();
				const invalid=Array.from(form.elements).filter(control=>control.validity && !control.validity.valid).map(control=>control.name || control.id || control.tagName);
				return {valid,invalid,invalidEvents,urlUnchanged:location.href===before};
			});
			assert(validation.valid===false,'Empty required form unexpectedly passed validation.');
			assert(validation.invalid.length>0 && validation.invalidEvents.length>0,'Validation did not expose invalid controls.');
			assert(validation.urlUnchanged,'Validation navigated away from the form.');
			return {invalidCount:validation.invalid.length,eventCount:validation.invalidEvents.length};
		});

		await probe('record actions keep a bounded primary set and keyboard-operable overflow',async page=>{
			const measurements=[];
			for(const viewport of [{name:'desktop',width:1440,height:1000},{name:'mobile',width:390,height:844}]){
				await open(page,baseUrl+'/orders/4',{width:viewport.width,height:viewport.height});
				const before=await page.evaluate(()=>{
					const toolbar=document.querySelector('.dp-panel-record-actions');
					const direct=toolbar ? Array.from(toolbar.children).filter(element=>{const rect=element.getBoundingClientRect();return rect.width>0&&rect.height>0;}) : [];
					const overflow=toolbar?.querySelector(':scope>.dp-panel-record-action-overflow');
					return {direct:direct.length,overflow:!!overflow,count:Number(overflow?.querySelector('.dp-panel-record-action-count')?.textContent||0),documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth)};
				});
				assert(before.overflow,'Record action overflow disclosure is missing at '+viewport.name+'.');
				assert(before.direct<=3,'Record heading exposes '+before.direct+' primary controls at '+viewport.name+'.');
				assert(before.count>=5,'Record action overflow reports too few secondary controls at '+viewport.name+'.');
				assert(before.documentOverflow===0,'Closed record actions overflow the document at '+viewport.name+'.');
				await page.focus('.dp-panel-record-action-overflow>summary');
				await page.keyboard.press('ArrowDown');
				await page.waitForFunction(()=>{
					const details=document.querySelector('.dp-panel-record-action-overflow');
					const menu=details?.querySelector(':scope>.dp-panel-record-action-menu');
					return !!(details?.open&&menu?.contains(document.activeElement));
				});
				const openState=await page.evaluate(()=>{
					const details=document.querySelector('.dp-panel-record-action-overflow');
					const summary=details?.querySelector(':scope>summary');
					const menu=details?.querySelector(':scope>.dp-panel-record-action-menu');
					const rect=menu?.getBoundingClientRect();
					const items=menu ? Array.from(menu.querySelectorAll('[role="menuitem"]')).filter(element=>{const itemRect=element.getBoundingClientRect();return itemRect.width>0&&itemRect.height>0;}) : [];
					return {
						open:!!details?.open,
						expanded:summary?.getAttribute('aria-expanded'),
						role:menu?.getAttribute('role'),
						activeInside:!!menu?.contains(document.activeElement),
						items:items.length,
						small:items.filter(element=>{const itemRect=element.getBoundingClientRect();return itemRect.width<44||itemRect.height<44;}).length,
						rect:rect ? {left:Math.round(rect.left),top:Math.round(rect.top),right:Math.round(rect.right),bottom:Math.round(rect.bottom),width:Math.round(rect.width),height:Math.round(rect.height),viewportWidth:innerWidth,viewportHeight:innerHeight} : null,
						bounded:!!rect&&rect.left>=0&&rect.top>=0&&rect.right<=innerWidth+1&&rect.bottom<=innerHeight+1,
						documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
					};
				});
				assert(openState.open&&openState.expanded==='true','Record action disclosure did not expose expanded state at '+viewport.name+'.');
				assert(openState.role==='menu'&&openState.activeInside,'Record action menu did not own semantic keyboard focus at '+viewport.name+'.');
				assert(openState.items>=5,'Record action menu exposes too few keyboard items at '+viewport.name+'.');
				assert(openState.small===0,'Record action menu has '+openState.small+' undersized items at '+viewport.name+'.');
				assert(openState.bounded&&openState.documentOverflow===0,'Open record action menu escapes the viewport at '+viewport.name+': '+JSON.stringify(openState));
				await page.keyboard.press('Escape');
				const closed=await page.evaluate(()=>{
					const details=document.querySelector('.dp-panel-record-action-overflow');
					return {open:!!details?.open,focus:document.activeElement===details?.querySelector(':scope>summary'),expanded:details?.querySelector(':scope>summary')?.getAttribute('aria-expanded')};
				});
				assert(!closed.open&&closed.focus&&closed.expanded==='false','Escape did not close record overflow and restore focus at '+viewport.name+'.');
				measurements.push({viewport:viewport.name,before,open:openState,closed});
			}
			return measurements;
		});

		await probe('copyable show entries reserve a collision-free logical action column',async page=>{
			const measurements=[];
			for(const viewport of [{name:'desktop',width:1440,height:1000},{name:'mobile',width:390,height:844}]){
				for(const direction of ['ltr','rtl']){
					await open(page,baseUrl+'/orders/4',{width:viewport.width,height:viewport.height});
					await page.evaluate(value=>{
						document.documentElement.dir=value;
						window.__dpCopiedEntryValue=null;
						try{
							Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText(text){window.__dpCopiedEntryValue=text;return Promise.resolve();}}});
						}
						catch(error){window.__dpCopiedEntryValue='clipboard-override-failed:'+String(error);}
					},direction);
					const layout=await page.evaluate(direction=>{
						const field=document.querySelector('.dp-panel-show-field-copyable');
						const button=field?.querySelector(':scope>.dp-panel-entry-copy');
						const header=field?.querySelector(':scope>header');
						const value=field ? Array.from(field.children).find(element=>element!==header&&element!==button&&!element.matches('.dp-panel-entry-description')) : null;
						const siblings=field ? Array.from(field.parentElement.querySelectorAll(':scope>.dp-panel-show-field:not(.dp-panel-show-field-copyable)')) : [];
						const bounds=element=>{const rect=element.getBoundingClientRect();return {left:rect.left,top:rect.top,right:rect.right,bottom:rect.bottom,width:rect.width,height:rect.height};};
						if(!field||!button||!value){return {missing:true};}
						const fieldRect=bounds(field);
						const buttonRect=bounds(button);
						const valueRect=bounds(value);
						const intersection=Math.max(0,Math.min(buttonRect.right,valueRect.right)-Math.max(buttonRect.left,valueRect.left))*Math.max(0,Math.min(buttonRect.bottom,valueRect.bottom)-Math.max(buttonRect.top,valueRect.top));
						const logicalEnd=direction==='rtl' ? Math.abs(buttonRect.left-fieldRect.left)<=20 : Math.abs(fieldRect.right-buttonRect.right)<=20;
						const siblingHeights=siblings.map(element=>element.getBoundingClientRect().height);
						const heightDelta=siblingHeights.length===0 ? 0 : Math.max(...siblingHeights.map(height=>Math.abs(fieldRect.height-height)));
						value.textContent='SO-'+('260505-RESPONSIVE-COPY-VALUE-'.repeat(12));
						const longFieldRect=bounds(field);
						const longButtonRect=bounds(button);
						const longValueRect=bounds(value);
						const longIntersection=Math.max(0,Math.min(longButtonRect.right,longValueRect.right)-Math.max(longButtonRect.left,longValueRect.left))*Math.max(0,Math.min(longButtonRect.bottom,longValueRect.bottom)-Math.max(longButtonRect.top,longValueRect.top));
						return {
							missing:false,
							direct:button.parentElement===field,
							after:getComputedStyle(field,'::after').content,
							field:fieldRect,
							button:buttonRect,
							value:valueRect,
							intersection,
							logicalEnd,
							heightDelta,
							longIntersection,
							longFieldOverflow:Math.max(0,field.scrollWidth-field.clientWidth),
							longDocumentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
							longHeight:longFieldRect.height,
						};
					},direction);
					assert(!layout.missing,'Copyable show entry is missing its field, direct action, or value at '+viewport.name+' '+direction+'.');
					assert(layout.direct,'Copy action is not a direct grid item at '+viewport.name+' '+direction+'.');
					assert(layout.after==='none','Copyable show entry retained a card-wide decorative pseudo-element at '+viewport.name+' '+direction+'.');
					assert(layout.button.width>=44&&layout.button.height>=44,'Copy action is below the 44px target at '+viewport.name+' '+direction+'.');
					assert(layout.intersection===0&&layout.longIntersection===0,'Copy action collides with short or wrapped values at '+viewport.name+' '+direction+': '+JSON.stringify(layout)+'.');
					assert(layout.logicalEnd,'Copy action does not follow the logical inline end at '+viewport.name+' '+direction+'.');
					assert(layout.heightDelta<=1,'Copyable field is taller than sibling value cards by '+layout.heightDelta+'px at '+viewport.name+' '+direction+'.');
					assert(layout.longFieldOverflow===0&&layout.longDocumentOverflow===0,'Long copyable value overflows at '+viewport.name+' '+direction+': '+JSON.stringify(layout)+'.');
					await page.$eval('.dp-panel-show-field-copyable>.dp-panel-entry-copy',button=>button.click());
					await delay(120);
					const copied=await page.evaluate(()=>{
						const button=document.querySelector('.dp-panel-show-field-copyable>.dp-panel-entry-copy');
						return {expected:button?.dataset.dpPanelCopyEntry||'',written:window.__dpCopiedEntryValue,text:button?.textContent||'',copied:!!button?.classList.contains('dp-panel-entry-copy-copied')};
					});
					assert(copied.written===copied.expected,'Copy action did not write its declared value at '+viewport.name+' '+direction+': '+JSON.stringify(copied)+'.');
					measurements.push({viewport:viewport.name,direction,layout,copied});
				}
			}
			return measurements;
		});

		await probe('mobile relation and order actions stay bounded and touch-safe',async page=>{
			const profiles=[
				{name:'flat-320-ltr',theme:'flat_minima',direction:'ltr',width:320},
				{name:'glass-390-rtl',theme:'glass',direction:'rtl',width:390},
				{name:'brutalist-390-ltr',theme:'brutalist',direction:'ltr',width:390},
			];
			const results=[];
			for(const profile of profiles){
				await open(page,baseUrl+'/orders?group=status&view=review&page=1&panel_theme='+encodeURIComponent(profile.theme),{width:profile.width,height:844});
				await page.evaluate(direction=>{
					document.documentElement.dir=direction;
					document.body.dataset.dpThemeMode='dark';
				},profile.direction);
				await page.waitForFunction(()=>Array.from(document.querySelectorAll('.dp-panel-table tbody tr')).some(row=>row.querySelector(':scope>td.dp-panel-select input')&&row.querySelector(':scope>td.dp-panel-actions')));
				const layout=await page.evaluate(profile=>{
					const visible=element=>{const rect=element.getBoundingClientRect();return rect.width>0&&rect.height>0;};
					const box=element=>{const rect=element.getBoundingClientRect();return {left:rect.left,right:rect.right,top:rect.top,bottom:rect.bottom,width:rect.width,height:rect.height};};
					const intersection=(first,second)=>Math.max(0,Math.min(first.right,second.right)-Math.max(first.left,second.left))*Math.max(0,Math.min(first.bottom,second.bottom)-Math.max(first.top,second.top));
					const row=Array.from(document.querySelectorAll('.dp-panel-table tbody tr')).find(candidate=>candidate.querySelector(':scope>td.dp-panel-select input')&&candidate.querySelector(':scope>td.dp-panel-actions'));
					if(!row){return {missing:true};}
					const select=row.querySelector(':scope>td.dp-panel-select');
					const firstCell=select?.nextElementSibling;
					const actions=row.querySelector(':scope>td.dp-panel-actions');
					if(!select||!firstCell||!actions){return {missing:true};}
					const rowBox=box(row);
					const selectBox=box(select);
					const firstSurface=Array.from(firstCell.children).find(visible)||null;
					const firstSurfaceBox=firstSurface ? box(firstSurface) : null;
					const firstSurfaceStyle=firstSurface ? getComputedStyle(firstSurface) : null;
					const firstStyle=getComputedStyle(firstCell);
					const actionsStyle=getComputedStyle(actions);
					const wrappers=Array.from(actions.children).filter(element=>element instanceof HTMLElement&&visible(element));
					const controls=wrappers.map(wrapper=>{
						let surface=wrapper.matches('a,button,summary') ? wrapper : null;
						if(!surface&&wrapper.matches('details')){surface=wrapper.querySelector(':scope>summary');}
						if(!surface){surface=wrapper.querySelector(':scope>a,:scope>button,:scope>summary,:scope>form>a,:scope>form>button');}
						if(!surface||!visible(surface)){return null;}
						return {...box(surface),label:(surface.textContent||'').replace(/\s+/g,' ').trim(),more:wrapper.classList.contains('dp-panel-row-more')};
					}).filter(Boolean).sort((left,right)=>Math.abs(left.top-right.top)>2 ? left.top-right.top : left.left-right.left);
					const rows=[];
					for(const control of controls){
						let group=rows.find(candidate=>Math.abs(candidate.top-control.top)<=2);
						if(!group){group={top:control.top,controls:[]};rows.push(group);}
						group.controls.push(control);
					}
					let overlaps=0;
					for(let first=0;first<controls.length;first++){
						for(let second=first+1;second<controls.length;second++){
							if(intersection(controls[first],controls[second])>1){overlaps++;}
						}
					}
					const actionsBox=box(actions);
					const contentWidth=actionsBox.width-parseFloat(actionsStyle.paddingLeft||'0')-parseFloat(actionsStyle.paddingRight||'0');
					const logicalEndGap=profile.direction==='rtl' ? selectBox.left-rowBox.left : rowBox.right-selectBox.right;
					const otherWidths=controls.filter(control=>!control.more).map(control=>control.width);
					const more=controls.find(control=>control.more)||null;
					return {
						missing:false,
						profile,
						row:rowBox,
						select:selectBox,
						selectCssWidth:getComputedStyle(select).width,
						logicalEndGap,
						firstReserveEnd:parseFloat(firstStyle.paddingInlineEnd||'0')+parseFloat(firstSurfaceStyle?.marginInlineEnd||'0'),
						selectionContentOverlap:firstSurfaceBox ? intersection(selectBox,firstSurfaceBox) : 0,
						actions:actionsBox,
						contentWidth,
						controls,
						controlRows:rows.map(group=>group.controls.map(control=>({width:control.width,height:control.height,label:control.label,more:control.more}))),
						multiRowsEqual:rows.filter(group=>group.controls.length>1).every(group=>Math.max(...group.controls.map(control=>control.width))-Math.min(...group.controls.map(control=>control.width))<=2),
						singleRowsFill:rows.filter(group=>group.controls.length===1).every(group=>Math.abs(group.controls[0].width-contentWidth)<=2),
						moreBalancesPeers:!!more&&otherWidths.length>=2&&more.width>=Math.min(...otherWidths)-2,
						overlaps,
						rowOverflow:Math.max(0,row.scrollWidth-row.clientWidth),
						actionsOverflow:Math.max(0,actions.scrollWidth-actions.clientWidth),
						documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
					};
				},profile);
				assert(!layout.missing,'Mobile table fixture is missing at '+profile.name+'.');
				assert(layout.select.width>0&&layout.select.width<=44,'Selection cell is not compact at '+profile.name+': '+JSON.stringify(layout.select)+'.');
				assert(layout.logicalEndGap>=8&&layout.logicalEndGap<=12,'Selection cell is not pinned to logical row end at '+profile.name+': '+layout.logicalEndGap+'px.');
				assert(layout.select.left>=layout.row.left&&layout.select.right<=layout.row.right,'Selection cell escapes its card at '+profile.name+'.');
				assert(layout.firstReserveEnd>=layout.select.width+6&&layout.selectionContentOverlap===0,'First data cell does not reserve the selection target at '+profile.name+': '+JSON.stringify(layout)+'.');
				assert(layout.controls.length>=3,'Mobile row actions are incomplete at '+profile.name+'.');
				assert(layout.controls.every(control=>control.width>=82&&control.height>=44),'A mobile row action is below its masonry/touch contract at '+profile.name+': '+JSON.stringify(layout.controls)+'.');
				assert(layout.multiRowsEqual&&layout.singleRowsFill&&layout.moreBalancesPeers,'Mobile row actions do not distribute and fill rows at '+profile.name+': '+JSON.stringify(layout.controlRows)+'.');
				assert(layout.overlaps===0&&layout.rowOverflow===0&&layout.actionsOverflow===0&&layout.documentOverflow===0,'Mobile table card overflows or overlaps at '+profile.name+': '+JSON.stringify(layout)+'.');

				let opened=null;
				if(profile.name==='glass-390-rtl'){
					await page.$eval('.dp-panel-table tbody tr:has(>td.dp-panel-select) td.dp-panel-actions>.dp-panel-row-more>summary',summary=>summary.click());
					await page.waitForFunction(()=>document.querySelector('.dp-panel-table tbody tr:has(>td.dp-panel-select) td.dp-panel-actions>.dp-panel-row-more')?.open===true);
					opened=await page.evaluate(()=>{
						const details=document.querySelector('.dp-panel-table tbody tr:has(>td.dp-panel-select) td.dp-panel-actions>.dp-panel-row-more');
						const actions=details?.closest('td.dp-panel-actions');
						const summary=details?.querySelector(':scope>summary');
						const menu=details?.querySelector(':scope>.dp-panel-row-more-menu');
						if(!details||!actions||!summary||!menu){return {missing:true};}
						const actionsStyle=getComputedStyle(actions);
						const actionsRect=actions.getBoundingClientRect();
						const detailsRect=details.getBoundingClientRect();
						const summaryRect=summary.getBoundingClientRect();
						const menuRect=menu.getBoundingClientRect();
						const contentWidth=actionsRect.width-parseFloat(actionsStyle.paddingLeft||'0')-parseFloat(actionsStyle.paddingRight||'0');
						return {missing:false,contentWidth,detailsWidth:detailsRect.width,summaryWidth:summaryRect.width,menuWidth:menuRect.width,menuPosition:getComputedStyle(menu).position,left:menuRect.left,right:menuRect.right,viewportWidth:document.documentElement.clientWidth,menuOverflow:Math.max(0,menu.scrollWidth-menu.clientWidth),documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth)};
					});
					assert(!opened.missing&&['static','fixed'].includes(opened.menuPosition),'Open More menu lost its bounded mobile placement contract: '+JSON.stringify(opened)+'.');
					assert(opened.detailsWidth>=opened.contentWidth-2&&Math.abs(opened.summaryWidth-opened.detailsWidth)<=2,'Open More disclosure does not fill its masonry row: '+JSON.stringify(opened)+'.');
					assert(opened.menuPosition==='fixed'||opened.menuWidth<=opened.detailsWidth+2,'Static More menu is wider than its masonry row: '+JSON.stringify(opened)+'.');
					assert(opened.left>=0&&opened.right<=opened.viewportWidth&&opened.menuOverflow===0&&opened.documentOverflow===0,'Open More menu escapes the RTL glass card: '+JSON.stringify(opened)+'.');
				}
				results.push({profile,layout,opened});
			}
			const tableCards=results;
			await open(page,baseUrl+'/orders/4',{width:390,height:844});
			const mobile=await page.evaluate(()=>{
				const relation=document.querySelector('.dp-panel-relation');
				const actions=Array.from(document.querySelectorAll('.dp-panel-record-actions button,.dp-panel-record-actions summary')).filter(element=>{const rect=element.getBoundingClientRect();return rect.width>0&&rect.height>0;}).map(element=>{const rect=element.getBoundingClientRect();return {width:Math.round(rect.width),height:Math.round(rect.height)};});
				return {relationOverflow:relation ? Math.max(0,relation.scrollWidth-relation.clientWidth) : -1,smallActions:actions.filter(item=>item.width<44||item.height<44),documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth)};
			});
			assert(mobile.relationOverflow===0,'Relation overflows by '+mobile.relationOverflow+'px.');
			assert(mobile.documentOverflow===0,'Document overflows by '+mobile.documentOverflow+'px.');
			assert(mobile.smallActions.length===0,mobile.smallActions.length+' order actions are below 44px.');

			await open(page,baseUrl+'/orders/4?panel_theme=brutalist',{width:1440,height:1000});
			await page.waitForFunction(()=>document.querySelector('.dp-panel-relation .dp-panel-table')?.dataset.dpPanelA11yTableOptimized==='1');
			const desktopAvailable=await page.$eval('.dp-panel-relation .dp-panel-table',table=>Number(table.dataset.dpPanelA11yTableAvailableWidth||0));
			await page.evaluate(()=>{
				document.documentElement.dir='rtl';
				document.body.dataset.dpThemeMode='dark';
				const style=document.createElement('style');
				style.id='dp-panel-relation-container-contract';
				style.textContent='main.dp-panel{inline-size:390px!important;max-inline-size:390px!important;margin-inline:auto!important}';
				document.head.appendChild(style);
			});
			await page.waitForFunction(()=>{
				const table=document.querySelector('.dp-panel-relation .dp-panel-table');
				return table&&table.dataset.dpPanelA11yTableSkipped==='card_mode'&&table.style.getPropertyValue('width')===''&&table.style.getPropertyValue('min-width')==='';
			});
			const embedded=await page.evaluate(()=>{
				const panel=document.querySelector('main.dp-panel');
				const region=document.querySelector('.dp-panel-main-region');
				const relation=document.querySelector('.dp-panel-relation');
				const aside=relation?.querySelector('.dp-panel-relation-aside');
				const wrapper=relation?.querySelector('.dp-panel-table-scroll');
				const table=relation?.querySelector('.dp-panel-table');
				return {
					panelWidth:Math.round(panel?.getBoundingClientRect().width||0),
					regionOverflow:region ? Math.max(0,region.scrollWidth-region.clientWidth) : -1,
					relationOverflow:relation ? Math.max(0,relation.scrollWidth-relation.clientWidth) : -1,
					wrapperOverflowPolicy:wrapper?.dataset.dpPanelOverflowPolicy||'',
					tableDisplay:table ? getComputedStyle(table).display : '',
					tableSkipped:table?.dataset.dpPanelA11yTableSkipped||'',
					tableInlineWidth:table?.style.getPropertyValue('width')||'',
					tableInlineMinWidth:table?.style.getPropertyValue('min-width')||'',
					asideMinWidth:aside ? getComputedStyle(aside).minWidth : '',
					documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),
				};
			});
			assert(desktopAvailable>390,'Desktop relation table was not optimized before the container transition.');
			assert(embedded.panelWidth===390,'Embedded Panel width is '+embedded.panelWidth+'px instead of 390px.');
			assert(embedded.regionOverflow===0&&embedded.relationOverflow===0,'Embedded relation retained internal overflow: '+JSON.stringify(embedded)+'.');
			assert(embedded.documentOverflow===0,'Embedded relation overflows the document by '+embedded.documentOverflow+'px.');
			assert(embedded.asideMinWidth==='0px','Embedded relation aside retained '+embedded.asideMinWidth+' minimum width.');
			assert(embedded.tableDisplay==='grid'&&embedded.tableSkipped==='card_mode','Embedded relation table did not enter card mode: '+JSON.stringify(embedded)+'.');
			assert(embedded.tableInlineWidth===''&&embedded.tableInlineMinWidth==='','Embedded relation table retained stale desktop inline sizing.');
			assert(embedded.wrapperOverflowPolicy==='scroll-x','Relation table wrapper lost its explicit overflow ownership.');

			await page.$eval('#dp-panel-relation-container-contract',style=>{style.textContent='main.dp-panel{inline-size:900px!important;max-inline-size:900px!important;margin-inline:auto!important}';});
			await page.waitForFunction(()=>{
				const table=document.querySelector('.dp-panel-relation .dp-panel-table');
				const wrapper=table?.closest('.dp-panel-table-scroll');
				return table&&wrapper&&table.dataset.dpPanelA11yTableOptimized==='1'&&Math.abs(Number(table.dataset.dpPanelA11yTableAvailableWidth||0)-wrapper.clientWidth)<=2;
			});
			const restored=await page.evaluate(()=>{
				const panel=document.querySelector('main.dp-panel');
				const region=document.querySelector('.dp-panel-main-region');
				const relation=document.querySelector('.dp-panel-relation');
				const table=relation?.querySelector('.dp-panel-table');
				const wrapper=table?.closest('.dp-panel-table-scroll');
				return {panelWidth:Math.round(panel?.getBoundingClientRect().width||0),regionOverflow:region ? Math.max(0,region.scrollWidth-region.clientWidth) : -1,relationOverflow:relation ? Math.max(0,relation.scrollWidth-relation.clientWidth) : -1,available:Number(table?.dataset.dpPanelA11yTableAvailableWidth||0),wrapperWidth:wrapper?.clientWidth||0,tableWidth:Math.round(table?.getBoundingClientRect().width||0),inlineWidth:table?.style.getPropertyValue('width')||'',optimized:table?.dataset.dpPanelA11yTableOptimized||'',documentOverflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth)};
			});
			assert(restored.panelWidth===900&&restored.optimized==='1','Relation table did not restore desktop optimization at 900px: '+JSON.stringify(restored)+'.');
			assert(Math.abs(restored.available-restored.wrapperWidth)<=2,'Restored relation table used a stale container width: '+JSON.stringify(restored)+'.');
			assert(restored.inlineWidth==='100%'&&restored.tableWidth>=restored.wrapperWidth-2,'Optimized relation table is narrower than its available wrapper: '+JSON.stringify(restored)+'.');
			assert(restored.regionOverflow===0&&restored.relationOverflow===0&&restored.documentOverflow===0,'Restored relation layout overflows: '+JSON.stringify(restored)+'.');
			return {tableCards,mobile,desktopAvailable,embedded,restored};
		});

		await probe('feature showcase renders and remains bounded at the page tail',async page=>{
			await open(page,baseUrl+'?resource=feature_showcase',{width:390,height:844});
			await page.evaluate(()=>window.scrollTo({top:document.documentElement.scrollHeight,left:0,behavior:'instant'}));
			await delay(120);
			const result=await page.evaluate(()=>({title:document.title,scrollY:Math.round(window.scrollY),scrollHeight:document.documentElement.scrollHeight,overflow:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth)}));
			assert(result.title==='Feature Showcase','Feature showcase did not render.');
			assert(result.overflow===0,'Feature showcase overflows by '+result.overflow+'px.');
			return result;
		});
	}
	finally{
		await browser.close();
	}
	const missingRegistrations=selection.selected.filter(entry=>!encountered.has(entry.name));
	for(const scenario of missingRegistrations){
		results.push({id:scenario.id,name:scenario.name,contract:scenario.contract,tags:[...scenario.tags],watches:[...scenario.watches],selectionReasons:[...scenario.reasons],passed:false,durationMs:0,error:'Registered scenario has no executable probe body.',consoleErrors:[]});
	}
	const failed=results.filter(result=>!result.passed);
	const report={
		type:'dataphyre_panel_interaction_regression',
		generatedAt:new Date().toISOString(),
		baseUrl,
		selection:{available:interactionRegistry.list().length,selected:selection.selected.length,unknownChanged:selection.unknownChanged,conservativeFallback:selection.conservativeFallback},
		summary:{total:results.length,passed:results.length-failed.length,failed:failed.length},
		results,
	};
	ensureDirectory(path.dirname(reportPath));
	fs.writeFileSync(reportPath,JSON.stringify(report,null,2)+'\n');
	console.log(JSON.stringify({report:reportPath,...report.summary}));
	return failed.length===0 ? 0 : 1;
}

run().then(code=>{process.exitCode=code;}).catch(error=>{console.error(error && error.stack || error);process.exitCode=1;});
