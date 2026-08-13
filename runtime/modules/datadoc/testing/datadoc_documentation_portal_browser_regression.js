#!/usr/bin/env node
'use strict';

import fs from 'node:fs';
import path from 'node:path';
const pageUrl=process.argv[2]||'http://127.0.0.1:4177/index.html';
const debuggingEndpoint=process.argv[3]||'http://127.0.0.1:9223';
const screenshotDirectory=process.argv[4]?path.resolve(process.argv[4]):'';
const contentImagesRequired=!process.argv.includes('--allow-empty-content-assets');
for(const [label,value] of [['page URL',pageUrl],['debugging endpoint',debuggingEndpoint]]){
	let parsed;
	try{ parsed=new URL(value); }
	catch{ throw new Error(`Datadoc documentation portal ${label} is invalid.`); }
	if(parsed.protocol!=='http:'||!['127.0.0.1','localhost','[::1]'].includes(parsed.hostname)){
		throw new Error(`Datadoc documentation portal ${label} must use loopback HTTP.`);
	}
}

const searchIndexResponse=await fetch(new URL('search-index.json',pageUrl));
if(!searchIndexResponse.ok){ throw new Error(`Datadoc search index returned HTTP ${searchIndexResponse.status}.`); }
const searchIndex=await searchIndexResponse.json();
const deepEntry=(Array.isArray(searchIndex?.entries)?searchIndex.entries:[])
	.filter(entry=>typeof entry?.path==='string'&&entry.path!=='index.html'&&Array.isArray(entry.headings)&&entry.headings.length>0)
	.sort((left,right)=>right.path.split('/').length-left.path.split('/').length||left.path.localeCompare(right.path))[0];
if(!deepEntry){ throw new Error('Datadoc browser regression requires one nested page with a level-two or level-three heading.'); }
const deepPath=deepEntry.path;
const expectedDeepRoot='../'.repeat(Math.max(0,deepPath.split('/').length-1));

const delay=milliseconds=>new Promise(resolve=>setTimeout(resolve,milliseconds));

class CdpClient {
	constructor(url){
		this.url=url;
		this.sequence=0;
		this.pending=new Map();
		this.events=[];
		this.socket=null;
	}

	async connect(){
		this.socket=new WebSocket(this.url);
		await new Promise((resolve,reject)=>{
			const timeout=setTimeout(()=>reject(new Error('Timed out connecting to the local browser.')),5000);
			this.socket.addEventListener('open',()=>{ clearTimeout(timeout); resolve(); },{once:true});
			this.socket.addEventListener('error',()=>{ clearTimeout(timeout); reject(new Error('Unable to connect to the local browser.')); },{once:true});
		});
		this.socket.addEventListener('message',event=>{
			const payload=JSON.parse(String(event.data));
			if(payload.id&&this.pending.has(payload.id)){
				const pending=this.pending.get(payload.id);
				this.pending.delete(payload.id);
				if(payload.error){ pending.reject(new Error(payload.error.message)); }
				else{ pending.resolve(payload.result||{}); }
				return;
			}
			if(payload.method){ this.events.push(payload); }
		});
	}

	command(method,params={}){
		if(!this.socket){ return Promise.reject(new Error('Local browser connection is not open.')); }
		const id=++this.sequence;
		return new Promise((resolve,reject)=>{
			const timeout=setTimeout(()=>{
				this.pending.delete(id);
				reject(new Error(`Local browser command timed out: ${method}`));
			},8000);
			this.pending.set(id,{
				resolve:value=>{ clearTimeout(timeout); resolve(value); },
				reject:error=>{ clearTimeout(timeout); reject(error); },
			});
			this.socket.send(JSON.stringify({id,method,params}));
		});
	}

	async evaluate(expression){
		const result=await this.command('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
		if(result.exceptionDetails){ throw new Error(result.exceptionDetails.text||'Browser evaluation failed.'); }
		return result.result?.value;
	}

	clearEvents(){ this.events=[]; }

	close(){
		for(const pending of this.pending.values()){ pending.reject(new Error('Local browser connection closed.')); }
		this.pending.clear();
		this.socket?.close();
	}
}

const targetList=await (await fetch(`${debuggingEndpoint}/json`)).json();
const targetOrigin=new URL(pageUrl).origin;
const target=targetList.find(item=>item.type==='page'&&String(item.url||'').startsWith(targetOrigin))
	||targetList.find(item=>item.type==='page');
if(!target?.webSocketDebuggerUrl){ throw new Error('No inspectable local browser page is available.'); }

const client=new CdpClient(target.webSocketDebuggerUrl);
await client.connect();
for(const domain of ['Page','Runtime','Network','Log']){ await client.command(`${domain}.enable`); }
await client.command('Network.clearBrowserCache');
if(screenshotDirectory){ fs.mkdirSync(screenshotDirectory,{recursive:true}); }
const screenshotPaths=[];
const capture=async name=>{
	if(!screenshotDirectory){ return; }
	await client.command('Page.bringToFront');
	await client.evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
	const viewportRect=await client.evaluate('({x:scrollX,y:scrollY,width:innerWidth,height:innerHeight})');
	const result=await client.command('Page.captureScreenshot',{format:'png',fromSurface:true,captureBeyondViewport:true,clip:{...viewportRect,scale:1}});
	const targetPath=path.join(screenshotDirectory,`${name}.png`);
	fs.writeFileSync(targetPath,Buffer.from(result.data,'base64'));
	screenshotPaths.push(targetPath);
};

const failures=[];
const observations={};
let checkCount=0;
const check=(condition,message,details={})=>{
	checkCount++;
	if(!condition){ failures.push({message,details}); }
};
const evaluate=expression=>client.evaluate(`(()=>{${expression}})()`);
const viewport=async(width,height,mobile=false)=>{
	await client.command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile});
	await client.command('Emulation.setTouchEmulationEnabled',{enabled:mobile,maxTouchPoints:mobile?5:1});
};
const media=async(colorScheme='light',reducedMotion='no-preference',forcedColors='none',mediaType='screen')=>{
	await client.command('Emulation.setEmulatedMedia',{
		media:mediaType,
		features:[
			{name:'prefers-color-scheme',value:colorScheme},
			{name:'prefers-reduced-motion',value:reducedMotion},
			{name:'forced-colors',value:forcedColors},
		],
	});
};
const navigate=async url=>{
	client.clearEvents();
	await client.command('Page.navigate',{url});
	for(let attempt=0;attempt<80;attempt++){
		const state=await client.evaluate('document.readyState');
		if(state==='complete'){ break; }
		await delay(50);
	}
	await delay(250);
};
const browserErrors=()=>client.events.flatMap(event=>{
	if(event.method==='Runtime.exceptionThrown'){
		return [{kind:'exception',message:event.params?.exceptionDetails?.text||'Runtime exception'}];
	}
	if(event.method==='Runtime.consoleAPICalled'&&event.params?.type==='error'){
		return [{kind:'console',message:(event.params.args||[]).map(argument=>argument.value||argument.description||'').join(' ')}];
	}
	if(event.method==='Log.entryAdded'&&['error','warning'].includes(event.params?.entry?.level)){
		return [{kind:'log',message:event.params.entry.text||'Browser log entry'}];
	}
	if(event.method==='Network.loadingFailed'&&!event.params?.canceled){
		return [{kind:'network',message:event.params?.errorText||'Network request failed'}];
	}
	if(event.method==='Network.responseReceived'&&Number(event.params?.response?.status)>=400&&!String(event.params?.response?.url||'').endsWith('/favicon.ico')){
		return [{kind:'http',message:`${event.params.response.status} ${event.params.response.url}`}];
	}
	return [];
});

await viewport(1440,900,false);
await media('light');
await navigate(pageUrl);
observations.desktop=await evaluate(`
	const visible=element=>!!element&&getComputedStyle(element).display!=='none'&&element.getBoundingClientRect().width>0;
	const layout=document.querySelector('.dp-doc-layout');
	const header=document.querySelector('.dp-doc-header');
	const article=document.querySelector('.dp-doc-article');
	const articleRect=article.getBoundingClientRect();
	const resourceNames=performance.getEntriesByType('resource').map(entry=>new URL(entry.name).pathname);
	return {
		title:document.title,
		viewport:innerWidth,
		documentWidth:document.documentElement.scrollWidth,
		bodyWidth:document.body.scrollWidth,
		grid:getComputedStyle(layout).gridTemplateColumns,
		headerHeight:Math.round(header.getBoundingClientRect().height),
		articleWidth:Math.round(article.getBoundingClientRect().width),
		sidebarVisible:visible(document.querySelector('.dp-doc-sidebar')),
		tocVisible:visible(document.querySelector('.dp-doc-toc')),
		menuVisible:visible(document.querySelector('[data-nav-toggle]')),
		h1:document.querySelector('h1')?.textContent.trim()||'',
		mainCount:document.querySelectorAll('main').length,
		h1Count:document.querySelectorAll('h1').length,
		duplicateIds:[...document.querySelectorAll('[id]')].map(node=>node.id).filter((id,index,all)=>all.indexOf(id)!==index),
		cssLoaded:resourceNames.some(path=>path.endsWith('/assets/portal.css')),
		javascriptLoaded:resourceNames.some(path=>path.endsWith('/assets/portal.js')),
		faviconHref:document.querySelector('link[rel~="icon"]')?.href||'',
		images:[...document.querySelectorAll('.dp-doc-image')].map(image=>{const rect=image.getBoundingClientRect();return{src:image.getAttribute('src'),alt:image.getAttribute('alt'),complete:image.complete,naturalWidth:image.naturalWidth,width:Math.round(rect.width),right:Math.round(rect.right),articleRight:Math.round(articleRect.right)};}),
	};
`);
check(observations.desktop.documentWidth<=1440,'Desktop document overflows horizontally.',observations.desktop);
check(observations.desktop.bodyWidth<=1440,'Desktop body overflows horizontally.',observations.desktop);
check(observations.desktop.sidebarVisible,'Desktop navigation is not visible.',observations.desktop);
check(!observations.desktop.tocVisible,'Root page renders an empty table of contents.',observations.desktop);
check(!observations.desktop.menuVisible,'Desktop mobile-menu control is visible.',observations.desktop);
check(observations.desktop.headerHeight>=42&&observations.desktop.headerHeight<=90,'Desktop header height is outside its normalized range.',observations.desktop);
check(observations.desktop.articleWidth>400&&observations.desktop.articleWidth<=900,'Desktop article width is outside its readable range.',observations.desktop);
check(observations.desktop.mainCount===1&&observations.desktop.h1Count===1,'Desktop landmarks or primary heading are ambiguous.',observations.desktop);
check(observations.desktop.duplicateIds.length===0,'Desktop page contains duplicate IDs.',observations.desktop);
check(observations.desktop.cssLoaded&&observations.desktop.javascriptLoaded,'Portal CSS or JavaScript did not load on desktop.',observations.desktop);
check((!contentImagesRequired&&observations.desktop.images.length===0)||(observations.desktop.images.length>0&&observations.desktop.images.every(image=>image.complete&&image.naturalWidth>0)&&observations.desktop.images.some(image=>image.naturalWidth>=1000)),'Local documentation images did not decode a wide responsive fixture.',observations.desktop);
check(observations.desktop.images.every(image=>image.width>0&&image.right<=image.articleRight+1),'Local documentation images exceed the article surface.',observations.desktop);
observations.favicon=await evaluate(`
	const link=document.querySelector('link[rel~="icon"]');
	if(!link)return {href:'',ok:false,status:0};
	return fetch(link.href,{credentials:'same-origin',cache:'no-store'}).then(response=>({href:link.href,ok:response.ok,status:response.status}));
`);
check(observations.favicon.ok&&observations.favicon.href.endsWith('/assets/favicon.svg'),'Portal favicon did not load.',observations.favicon);
check(browserErrors().length===0,'Desktop browser emitted errors.',{errors:browserErrors()});
await capture('desktop');

await client.evaluate(`document.querySelector('[data-search-open]').click()`);
await delay(50);
observations.searchOpen=await evaluate(`
	const dialog=document.querySelector('[data-search-dialog]');
	const input=document.querySelector('[data-search-input]');
	return {open:dialog.open,focused:document.activeElement===input,width:Math.round(dialog.getBoundingClientRect().width),viewport:innerWidth};
`);
check(observations.searchOpen.open&&observations.searchOpen.focused,'Search dialog did not open with focus in its input.',observations.searchOpen);
await client.evaluate(`(()=>{const input=document.querySelector('[data-search-input]');input.value='documentation';input.dispatchEvent(new Event('input',{bubbles:true}));})()`);
await delay(450);
observations.searchResults=await evaluate(`
	return {
		count:document.querySelectorAll('[data-search-results] a').length,
		status:document.querySelector('[data-search-status]').textContent,
		unsafe:[...document.querySelectorAll('[data-search-results] a')].some(anchor=>!anchor.href.startsWith(location.origin)),
	};
`);
check(observations.searchResults.count>0,'Local search returned no results.',observations.searchResults);
check(!observations.searchResults.unsafe,'Local search produced an external result URL.',observations.searchResults);
check(browserErrors().length===0,'Search interaction emitted browser errors.',{errors:browserErrors()});
await client.evaluate(`document.querySelector('[data-search-dialog]').close()`);

const deepUrl=new URL(deepPath,pageUrl).href;
await navigate(deepUrl);
observations.deepPage=await evaluate(`
	const resources=performance.getEntriesByType('resource').map(entry=>new URL(entry.name).pathname);
	return {
		title:document.title,
		h1:document.querySelector('h1')?.textContent.trim()||'',
		documentWidth:document.documentElement.scrollWidth,
		viewport:innerWidth,
		cssLoaded:resources.some(path=>path.endsWith('/assets/portal.css')),
		javascriptLoaded:resources.some(path=>path.endsWith('/assets/portal.js')),
		root:document.body.dataset.root,
		tocVisible:!!document.querySelector('.dp-doc-toc')&&getComputedStyle(document.querySelector('.dp-doc-toc')).display!=='none',
		scrollX,
		visualOffset:visualViewport?.offsetLeft||0,
		background:getComputedStyle(document.documentElement).backgroundColor,
		layout:[...Object.values(document.querySelector('.dp-doc-layout').getBoundingClientRect().toJSON())].slice(0,4),
	};
`);
check(observations.deepPage.documentWidth<=observations.deepPage.viewport,'Nested documentation page overflows horizontally.',observations.deepPage);
check(observations.deepPage.cssLoaded&&observations.deepPage.javascriptLoaded,'Nested documentation page asset roots are broken.',observations.deepPage);
check(observations.deepPage.root===expectedDeepRoot,'Nested documentation page root calculation is incorrect.',{...observations.deepPage,expectedDeepRoot,deepPath});
check(observations.deepPage.tocVisible,'Nested documentation page table of contents is not visible.',observations.deepPage);
check(browserErrors().length===0,'Nested documentation page emitted browser errors.',{errors:browserErrors()});
await capture('deep-page');

await viewport(390,844,true);
await navigate(pageUrl);
observations.mobileClosed=await evaluate(`
	const visible=element=>!!element&&getComputedStyle(element).display!=='none'&&element.getBoundingClientRect().width>0;
	const sidebar=document.querySelector('.dp-doc-sidebar');
	const header=document.querySelector('.dp-doc-header');
	const article=document.querySelector('.dp-doc-article');
	const articleRight=article.getBoundingClientRect().right;
	return {
		viewport:innerWidth,
		documentWidth:document.documentElement.scrollWidth,
		bodyWidth:document.body.scrollWidth,
		headerWidth:Math.round(header.getBoundingClientRect().width),
		menuVisible:visible(document.querySelector('[data-nav-toggle]')),
		tocVisible:visible(document.querySelector('.dp-doc-toc')),
		sidebarOpen:sidebar.classList.contains('is-open'),
		sidebarRight:Math.round(sidebar.getBoundingClientRect().right),
		searchTarget:Math.round(document.querySelector('[data-search-open]').getBoundingClientRect().height),
		menuTarget:Math.round(document.querySelector('[data-nav-toggle]').getBoundingClientRect().height),
		images:[...document.querySelectorAll('.dp-doc-image')].map(image=>{const rect=image.getBoundingClientRect();return{complete:image.complete,naturalWidth:image.naturalWidth,width:Math.round(rect.width),right:Math.round(rect.right),articleRight:Math.round(articleRight)};}),
	};
`);
check(observations.mobileClosed.documentWidth<=390&&observations.mobileClosed.bodyWidth<=390,'Closed mobile portal overflows horizontally.',observations.mobileClosed);
check(observations.mobileClosed.headerWidth<=390,'Mobile header exceeds its viewport.',observations.mobileClosed);
check(observations.mobileClosed.menuVisible&&!observations.mobileClosed.tocVisible,'Mobile responsive visibility contract failed.',observations.mobileClosed);
check(!observations.mobileClosed.sidebarOpen&&observations.mobileClosed.sidebarRight<=1,'Closed mobile navigation remains on canvas.',observations.mobileClosed);
check(observations.mobileClosed.searchTarget>=42&&observations.mobileClosed.menuTarget>=42,'Mobile header controls are below the touch-target floor.',observations.mobileClosed);
check((!contentImagesRequired&&observations.mobileClosed.images.length===0)||(observations.mobileClosed.images.length>0&&observations.mobileClosed.images.every(image=>image.complete&&image.naturalWidth>=1000&&image.right<=image.articleRight+1)),'Wide local documentation images are not responsive on mobile.',observations.mobileClosed);
await capture('mobile-closed');

await client.evaluate(`document.querySelector('[data-nav-toggle]').click()`);
await delay(250);
observations.mobileOpen=await evaluate(`
	const sidebar=document.querySelector('.dp-doc-sidebar');
	const backdrop=document.querySelector('[data-nav-backdrop]');
	const rect=sidebar.getBoundingClientRect();
	return {
		open:sidebar.classList.contains('is-open'),
		expanded:document.querySelector('[data-nav-toggle]').getAttribute('aria-expanded'),
		backdropHidden:backdrop.hidden,
		left:Math.round(rect.left),
		right:Math.round(rect.right),
		width:Math.round(rect.width),
		documentWidth:document.documentElement.scrollWidth,
		viewport:innerWidth,
		focusedLink:document.activeElement?.tagName==='A'&&sidebar.contains(document.activeElement),
	};
`);
check(observations.mobileOpen.open&&observations.mobileOpen.expanded==='true','Mobile navigation did not expose its open state.',observations.mobileOpen);
check(!observations.mobileOpen.backdropHidden&&observations.mobileOpen.focusedLink,'Mobile navigation did not expose its backdrop or move focus.',observations.mobileOpen);
check(observations.mobileOpen.left>=0&&observations.mobileOpen.right<=390&&observations.mobileOpen.width<=344,'Open mobile navigation exceeds the viewport.',observations.mobileOpen);
check(observations.mobileOpen.documentWidth<=observations.mobileOpen.viewport,'Open mobile navigation causes document overflow.',observations.mobileOpen);
await capture('mobile-navigation');
await client.evaluate(`document.querySelector('[data-nav-backdrop]').click()`);
await delay(250);
observations.mobileRestored=await evaluate(`
	const sidebar=document.querySelector('.dp-doc-sidebar');
	return {
		focusedToggle:document.activeElement===document.querySelector('[data-nav-toggle]'),
		sidebarOpen:sidebar.classList.contains('is-open'),
		sidebarRight:Math.round(sidebar.getBoundingClientRect().right),
		scrollX,
		visualOffset:visualViewport?.offsetLeft||0,
	};
`);
check(observations.mobileRestored.focusedToggle&&!observations.mobileRestored.sidebarOpen&&observations.mobileRestored.sidebarRight<=1,'Closing mobile navigation did not restore a stable menu focus state.',observations.mobileRestored);
check(observations.mobileRestored.scrollX===0&&observations.mobileRestored.visualOffset===0,'Closing mobile navigation shifted the visual viewport.',observations.mobileRestored);

await client.evaluate(`document.querySelector('[data-search-open]').click()`);
await delay(50);
observations.mobileSearch=await evaluate(`
	const rect=document.querySelector('[data-search-dialog]').getBoundingClientRect();
	return {open:document.querySelector('[data-search-dialog]').open,width:Math.round(rect.width),height:Math.round(rect.height),viewportWidth:innerWidth,viewportHeight:innerHeight};
`);
check(observations.mobileSearch.open&&observations.mobileSearch.width<=390&&observations.mobileSearch.height<=844,'Mobile search dialog exceeds the viewport.',observations.mobileSearch);
await capture('mobile-search');
await client.evaluate(`document.querySelector('[data-search-dialog]').close()`);
check(browserErrors().length===0,'Mobile interactions emitted browser errors.',{errors:browserErrors()});

await media('dark');
await client.evaluate(`localStorage.removeItem('dataphyre-datadoc-theme')`);
await navigate(pageUrl);
observations.dark=await evaluate(`
	const parse=color=>color.match(/[\\d.]+/g)?.slice(0,3).map(Number)||[0,0,0];
	const luminance=color=>{const channels=parse(color).map(value=>{value/=255;return value<=.03928?value/12.92:Math.pow((value+.055)/1.055,2.4);});return .2126*channels[0]+.7152*channels[1]+.0722*channels[2];};
	const background=getComputedStyle(document.body).backgroundColor;
	const color=getComputedStyle(document.body).color;
	const lighter=Math.max(luminance(background),luminance(color));
	const darker=Math.min(luminance(background),luminance(color));
	return {matches:matchMedia('(prefers-color-scheme: dark)').matches,theme:document.documentElement.dataset.theme,background,color,contrast:Number(((lighter+.05)/(darker+.05)).toFixed(2))};
`);
check(observations.dark.matches&&observations.dark.theme==='system','Dark system theme did not activate.',observations.dark);
check(observations.dark.contrast>=7,'Dark portal body contrast is below the enhanced-text target.',observations.dark);
await capture('mobile-dark');

await media('dark','reduce','active');
observations.preferences=await evaluate(`
	return {
		reduced:matchMedia('(prefers-reduced-motion: reduce)').matches,
		forced:matchMedia('(forced-colors: active)').matches,
		transition:getComputedStyle(document.querySelector('.dp-doc-sidebar')).transitionDuration,
	};
`);
check(observations.preferences.reduced&&observations.preferences.forced,'Forced-colors or reduced-motion emulation did not reach the page.',observations.preferences);
check(observations.preferences.transition==='0s','Reduced-motion mode leaves a navigation transition active.',observations.preferences);

await media('light','no-preference','none','print');
observations.print=await evaluate(`
	return {
		header:getComputedStyle(document.querySelector('.dp-doc-header')).display,
		sidebar:getComputedStyle(document.querySelector('.dp-doc-sidebar')).display,
		layout:getComputedStyle(document.querySelector('.dp-doc-layout')).display,
	};
`);
check(observations.print.header==='none'&&observations.print.sidebar==='none'&&observations.print.layout==='block','Print layout does not remove interactive chrome.',observations.print);

await client.command('Emulation.clearDeviceMetricsOverride');
await client.command('Emulation.setEmulatedMedia',{media:'screen',features:[]});
client.close();

const report={
	type:'datadoc_documentation_portal_browser_regression',
	browser:target.browser||'Chromium DevTools Protocol',
	page_url:pageUrl,
	content_images_required:contentImagesRequired,
	checks:checkCount,
	failures,
	screenshots:screenshotPaths,
	observations,
};
process.stdout.write(`${JSON.stringify(report,null,2)}\n`);
process.exitCode=failures.length===0?0:1;
