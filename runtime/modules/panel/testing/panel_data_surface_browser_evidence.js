#!/usr/bin/env node
"use strict";

const fs=require("node:fs");
const path=require("node:path");

const cwd=process.cwd();
const baseUrl=String(process.argv[2]||process.env.DP_PANEL_BASE_URL||"http://127.0.0.1:8098/panel");
const artifactRoot=path.resolve(cwd,process.argv[3]||".tmp/panel-data-surface-evidence");

function assert(value,message){if(!value){throw new Error(message);}}
function findPuppeteer(){
	for(const candidate of [path.join(cwd,".tmp","puppeteer-check","node_modules","puppeteer-core"),path.join(cwd,".tmp","node_modules","puppeteer-core"),path.join(__dirname,"node_modules","puppeteer-core")]){if(fs.existsSync(candidate)){return require(candidate);}}
	throw new Error("puppeteer-core is unavailable");
}
function findBrowser(){
	for(const candidate of [process.env.CHROME_PATH,process.env.EDGE_PATH,"C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe","C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe","C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe"].filter(Boolean)){if(fs.existsSync(candidate)){return candidate;}}
	throw new Error("Chrome or Edge is unavailable");
}
function target(direction,theme){
	const url=new URL(baseUrl);url.searchParams.set("resource","data_surface_lab");url.searchParams.set("panel_theme",theme);url.searchParams.set("dp_evidence_dir",direction);return url.toString();
}
async function forcedColors(page,value){
	try{await page.emulateMediaFeatures([{name:"forced-colors",value}]);return "puppeteer";}catch(error){
		const session=await page.createCDPSession();await session.send("Emulation.setEmulatedMedia",{features:[{name:"forced-colors",value}]});return "cdp";
	}
}
async function inspect(page,direction){
	return page.evaluate(dir=>{
		const channels=value=>{const match=String(value).match(/[\d.]+/g)||[];return match.slice(0,3).map(Number);};
		const luminance=value=>{const rgb=channels(value).map(component=>{const normalized=component/255;return normalized<=.04045?normalized/12.92:Math.pow((normalized+.055)/1.055,2.4);});return .2126*rgb[0]+.7152*rgb[1]+.0722*rgb[2];};
		const contrast=(foreground,background)=>{const a=luminance(foreground),b=luminance(background);return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);};
		document.documentElement.dir=dir;
		const root=document.querySelector("[data-dp-data-surface]");
		const table=root&&root.querySelector("table");
		const firstCell=root&&root.querySelector("tbody td");
		const caption=root&&root.querySelector("table caption");
		const firstItem=root&&root.querySelector("[data-dp-data-surface-item]");
		const controls=root&&root.querySelector("[data-dp-data-surface-controls]");
		const buttons=controls?Array.from(controls.querySelectorAll("button")):[];
		const bodyOverflow=document.documentElement.scrollWidth-document.documentElement.clientWidth;
		const rootOverflow=root?root.scrollWidth-root.clientWidth:0;
		const rootStyle=root?getComputedStyle(root):null,cellStyle=firstCell?getComputedStyle(firstCell):null,header=root?.querySelector("thead th"),headerStyle=header?getComputedStyle(header):null;
		const colors={surface:rootStyle?.backgroundColor||"",value:cellStyle?.color||"",header:headerStyle?.color||""};
		return {
			direction:getComputedStyle(root).direction,
			reducedMotion:matchMedia("(prefers-reduced-motion: reduce)").matches,
			enhanced:root?.dataset.dpDataSurfaceEnhanced==="1",
			busy:root?.getAttribute("aria-busy"),
			bodyOverflow,rootOverflow,
			rootWidth:root?.getBoundingClientRect().width||0,
			tableDisplay:table?getComputedStyle(table).display:"",
			cellDisplay:firstCell?getComputedStyle(firstCell).display:"",
			controlsVisible:controls?!controls.hidden:false,
			buttonHeights:buttons.map(button=>button.getBoundingClientRect().height),
			colors,contrast:{value:contrast(colors.value,colors.surface),header:contrast(colors.header,colors.surface)},
			semantic:{table:!!table,headers:root?root.querySelectorAll('th[scope="col"]').length:0,live:!!root?.querySelector('[role="status"][aria-live="polite"]'),labelled:!!root?.getAttribute("aria-labelledby")},
			captionHidden:caption?caption.getBoundingClientRect().width<=1&&caption.getBoundingClientRect().height<=1:false,
			firstPosition:firstItem?.getAttribute("data-position")||null,
			focusable:root?root.querySelectorAll('[data-dp-data-surface-item][tabindex="0"]').length:0,
		};
	},direction);
}
async function main(){
	fs.mkdirSync(artifactRoot,{recursive:true});
	const puppeteer=findPuppeteer();
	const browser=await puppeteer.launch({executablePath:findBrowser(),headless:true,args:["--disable-dev-shm-usage","--no-first-run","--no-default-browser-check"]});
	const report={type:"panel_data_surface_browser_evidence",version:1,generated_at:new Date().toISOString(),base_url:baseUrl,cases:[],transport:[],forced_colors_engine:null};
	try{
		for(const viewport of [{name:"mobile-320",width:320,height:640},{name:"mobile-390",width:390,height:844},{name:"tablet",width:768,height:900},{name:"desktop",width:1440,height:1000}]){
			for(const direction of ["ltr","rtl"]){
				const theme=direction==="rtl"?"glass":"flat_minima";
				const page=await browser.newPage();await page.setViewport({width:viewport.width,height:viewport.height,deviceScaleFactor:1});
				await page.emulateMediaFeatures([{name:"prefers-reduced-motion",value:"reduce"}]);
				const requests=[];page.on("request",request=>{if(request.method()==="POST"&&request.url().includes("dp_panel_data_surface")){requests.push({url:request.url(),postData:request.postData()||""});}});
				await page.goto(target(direction,theme),{waitUntil:"networkidle0",timeout:30000});
				await page.waitForSelector('[data-dp-data-surface][data-dp-data-surface-enhanced="1"]',{timeout:10000});
				let facts=await inspect(page,direction);
				assert(facts.enhanced,"DataSurface did not progressively enhance");assert(facts.busy==="false","DataSurface remained busy");assert(facts.bodyOverflow<=1&&facts.rootOverflow<=1,"DataSurface caused horizontal overflow");assert(facts.controlsVisible,"DataSurface controls remained hidden");assert(facts.buttonHeights.every(height=>height>=41),"DataSurface control target is too short");assert(facts.semantic.table&&facts.semantic.headers>=2&&facts.semantic.live&&facts.semantic.labelled,"SSR table semantics are incomplete");assert(facts.captionHidden,"the accessible table caption leaked into the visual layout");assert(facts.focusable===1,"DataSurface does not expose one roving tab stop");assert(facts.direction===direction,"DataSurface direction does not follow the document");assert(facts.reducedMotion,"reduced-motion emulation did not reach the DataSurface page");assert(facts.contrast.value>=4.5&&facts.contrast.header>=4.5,"DataSurface text contrast is below WCAG AA");
				if(viewport.width<=390){assert(facts.tableDisplay==="block"&&facts.cellDisplay==="grid","mobile table did not reflow to stacked cells");}
				const keyboard=await page.evaluate(()=>{const root=document.querySelector("[data-dp-data-surface]"),first=root.querySelector('[data-dp-data-surface-item][tabindex="0"]');first.focus();first.dispatchEvent(new KeyboardEvent("keydown",{key:"ArrowDown",bubbles:true,cancelable:true}));return {before:first.getAttribute("data-position"),after:document.activeElement?.getAttribute("data-position")||null};});
				assert(keyboard.before!==keyboard.after,"Arrow-key navigation did not move the roving focus");
				const before=facts.firstPosition;
				await page.click('[data-dp-data-surface-intent="next"]');
				await page.waitForFunction(previous=>document.querySelector('[data-dp-data-surface-item]')?.getAttribute("data-position")!==previous,{timeout:10000},before);
				facts=await inspect(page,direction);assert(facts.bodyOverflow<=1&&facts.rootOverflow<=1,"loaded window introduced horizontal overflow");
				assert(requests.length===1,"next-window interaction did not issue exactly one POST");
				const body=JSON.parse(requests[0].postData);assert(Object.keys(body).length===1&&typeof body.intent==="string","browser sent mutable query/context fields with the signed intent");assert(!/(tenant|principal|range|cursor|authorization)/i.test(requests[0].postData),"browser transport disclosed server-owned context");
				const snapshot=await page.accessibility.snapshot({interestingOnly:false});assert(snapshot&&snapshot.role==="RootWebArea","accessibility tree snapshot is unavailable");
				const screenshot=path.join(artifactRoot,viewport.name+"-"+direction+".png");await page.screenshot({path:screenshot,fullPage:true});
				report.cases.push({viewport,direction,theme,facts,keyboard,request_count:requests.length,screenshot:path.relative(cwd,screenshot).replace(/\\/g,"/")});
				report.transport.push(...requests.map(request=>({url:new URL(request.url).pathname,keys:Object.keys(JSON.parse(request.postData)).sort()})));
				await page.close();
			}
		}
		const page=await browser.newPage();await page.setViewport({width:390,height:844});report.forced_colors_engine=await forcedColors(page,"active");await page.goto(target("ltr","flat_minima"),{waitUntil:"networkidle0",timeout:30000});
		const forced=await page.evaluate(()=>({active:matchMedia("(forced-colors: active)").matches,border:getComputedStyle(document.querySelector("[data-dp-data-surface]")).borderColor}));assert(forced.active,"forced-colors emulation did not activate");report.forced_colors=forced;await page.close();
	}finally{await browser.close();}
	report.summary={passed:true,case_count:report.cases.length,no_horizontal_overflow:report.cases.every(item=>item.facts.bodyOverflow<=1&&item.facts.rootOverflow<=1),directions:Array.from(new Set(report.cases.map(item=>item.direction))),viewports:Array.from(new Set(report.cases.map(item=>item.viewport.name)))};
	fs.writeFileSync(path.join(artifactRoot,"report.json"),JSON.stringify(report,null,2)+"\n");
	console.log("panel DataSurface browser evidence passed: "+report.cases.length+" responsive/RTL cases");
}

main().catch(error=>{console.error(error&&error.stack?error.stack:error);process.exitCode=1;});
