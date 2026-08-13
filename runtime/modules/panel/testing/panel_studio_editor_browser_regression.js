#!/usr/bin/env node
/*
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
"use strict";

const fs=require("node:fs");
const path=require("node:path");
const {spawnSync}=require("node:child_process");

const cwd=process.cwd();
const defaults={
	php:process.env.PHP_BINARY||"php",
	browser:process.env.CHROME_PATH||process.env.EDGE_PATH||"",
	artifacts:path.resolve(cwd,"cache","unit-tests","panel-studio-editor-evidence"),
	allowNoSandbox:false,
};

function parseArguments(argumentsList){
	const options={...defaults};
	for(let index=0;index<argumentsList.length;index++){
		const item=String(argumentsList[index]);
		const read=()=>{const equal=item.indexOf("=");if(equal>=0){return item.slice(equal+1);}index++;return String(argumentsList[index]||"");};
		if(item==="--php"||item.startsWith("--php=")){options.php=read();}
		else if(item==="--browser"||item.startsWith("--browser=")){options.browser=read();}
		else if(item==="--artifacts"||item.startsWith("--artifacts=")){options.artifacts=path.resolve(cwd,read());}
		else if(item==="--allow-no-sandbox"){options.allowNoSandbox=true;}
		else{throw new Error("Unknown argument: "+item);}
	}
	return options;
}
function assert(value,message){if(!value){throw new Error(message);}}
function findPuppeteer(){
	for(const candidate of [path.join(cwd,".tmp","puppeteer-check","node_modules","puppeteer-core"),path.join(cwd,".tmp","node_modules","puppeteer-core"),path.join(__dirname,"node_modules","puppeteer-core")]){if(fs.existsSync(candidate)){return require(candidate);}}
	throw new Error("puppeteer-core is unavailable");
}
function findBrowser(configured){
	for(const candidate of [configured,"C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe","C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe",path.join(process.env.LOCALAPPDATA||"","Google","Chrome","Application","chrome.exe"),"C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe","C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe"].filter(Boolean)){if(fs.existsSync(candidate)){return candidate;}}
	throw new Error("Chrome or Edge is unavailable");
}
function fixture(options,theme="dark",direction="ltr",zoom="100",reflow="desktop"){
	const file=path.join(__dirname,"fixtures","panel_studio_editor_showroom.php");
	const result=spawnSync(options.php,[file,"--theme="+theme,"--direction="+direction,"--zoom="+zoom,"--reflow="+reflow],{cwd,encoding:"utf8",maxBuffer:4*1024*1024,windowsHide:true,shell:false});
	assert(!result.error&&result.status===0,"Studio fixture failed: "+String(result.stderr||result.error||"").slice(-2000));
	assert(result.stdout.includes("data-dp-studio-editor"),"Studio fixture omitted the editor root");
	return result.stdout;
}
function parseColor(value){
	const match=String(value).match(/rgba?\((\d+(?:\.\d+)?)\s*,?\s*(\d+(?:\.\d+)?)\s*,?\s*(\d+(?:\.\d+)?)(?:\s*[,/]\s*(\d+(?:\.\d+)?))?\)/i);
	return match?{r:Number(match[1]),g:Number(match[2]),b:Number(match[3]),a:match[4]===undefined?1:Number(match[4])}:null;
}
function composite(foreground,background){const alpha=foreground.a+(background.a||1)*(1-foreground.a);return{r:(foreground.r*foreground.a+background.r*(background.a||1)*(1-foreground.a))/alpha,g:(foreground.g*foreground.a+background.g*(background.a||1)*(1-foreground.a))/alpha,b:(foreground.b*foreground.a+background.b*(background.a||1)*(1-foreground.a))/alpha,a:alpha};}
function luminance(color){const channel=value=>{value/=255;return value<=.04045?value/12.92:Math.pow((value+.055)/1.055,2.4);};return .2126*channel(color.r)+.7152*channel(color.g)+.0722*channel(color.b);}
function contrast(foreground,background,canvas){let fg=parseColor(foreground),bg=parseColor(background),base=parseColor(canvas)||{r:255,g:255,b:255,a:1};if(!fg||!bg){return 0;}if(bg.a<1){bg=composite(bg,base);}if(fg.a<1){fg=composite(fg,bg);}const high=Math.max(luminance(fg),luminance(bg)),low=Math.min(luminance(fg),luminance(bg));return(high+.05)/(low+.05);}
async function forcedColors(page){
	try{await page.emulateMediaFeatures([{name:"forced-colors",value:"active"}]);return"puppeteer";}catch(error){const session=await page.createCDPSession();await session.send("Emulation.setEmulatedMedia",{media:"",features:[{name:"forced-colors",value:"active"}]});return"cdp";}
}
async function openFixture(browser,html,viewport,{javascript=true,reducedMotion=false,forced=false}={}){
	const page=await browser.newPage();await page.setViewport({...viewport,deviceScaleFactor:1,isMobile:viewport.width<=720,hasTouch:viewport.width<=720});
	if(!javascript){await page.setJavaScriptEnabled(false);}if(reducedMotion){await page.emulateMediaFeatures([{name:"prefers-reduced-motion",value:"reduce"}]);}
	let forcedEngine=null;if(forced){forcedEngine=await forcedColors(page);}
	const faults=[];page.on("pageerror",error=>faults.push(String(error&&error.stack||error)));page.on("console",message=>{if(message.type()==="error"){faults.push(message.text());}});
	await page.setContent(html,{waitUntil:"domcontentloaded",timeout:30000});if(javascript){await page.waitForSelector('[data-dp-studio-editor][data-dp-studio-enhanced="true"]',{timeout:10000});}
	return{page,faults,forcedEngine};
}
async function inspect(page){
	return page.evaluate(()=>{
		const root=document.querySelector("[data-dp-studio-editor]");
		const visible=element=>{const style=getComputedStyle(element),box=element.getBoundingClientRect();return !element.hidden&&style.display!=="none"&&style.visibility!=="hidden"&&box.width>0&&box.height>0;};
		const targets=Array.from(root.querySelectorAll("button,select,textarea,input:not([type='hidden'])")).filter(visible).map(control=>{const hit=control.type==="checkbox"&&control.closest("label")||control,box=hit.getBoundingClientRect();return{tag:control.tagName,label:control.getAttribute("aria-label")||control.textContent.trim()||control.name,width:box.width,height:box.height};});
		const ids=Array.from(document.querySelectorAll("[id]")).map(element=>element.id),duplicates=ids.filter((id,index)=>ids.indexOf(id)!==index);
		const input=root.querySelector(".dp-studio-field input:not([type='checkbox'])"),select=root.querySelector(".dp-studio-field select")||root.querySelector(".dp-studio-canvas-controls select"),collaboration=root.querySelector("[data-dp-studio-collaboration]"),collaborationSelect=collaboration?.querySelector("select"),collaborationInput=collaboration?.querySelector("input"),bodyStyle=getComputedStyle(document.body);
		const sample=element=>element?{color:getComputedStyle(element).color,background:getComputedStyle(element).backgroundColor}:null;
		return{
			enhanced:root.dataset.dpStudioEnhanced==="true",direction:getComputedStyle(root).direction,dirty:root.dataset.dpStudioDirty,selected:root.dataset.dpStudioSelected,
			pageOverflow:document.documentElement.scrollWidth-document.documentElement.clientWidth,rootOverflow:root.scrollWidth-root.clientWidth,
			duplicates,targets,visiblePanels:Array.from(root.querySelectorAll("[data-dp-studio-panel]")).filter(visible).map(panel=>panel.dataset.dpStudioPanel),
			mobileNavigation:getComputedStyle(root.querySelector(".dp-studio-mobile-nav")).display,pressed:Array.from(root.querySelectorAll('[data-dp-studio-mobile-panel][aria-pressed="true"]')).map(control=>control.dataset.dpStudioMobilePanel),
			semantics:{tree:!!root.querySelector('[role="tree"]'),treeitems:root.querySelectorAll('[role="treeitem"]').length,roving:root.querySelectorAll('[role="treeitem"][tabindex="0"]').length===1,leafExpansion:Array.from(root.querySelectorAll('[role="treeitem"]')).every(item=>item.closest('li').querySelector(':scope > ol[role="group"]')?item.getAttribute('aria-expanded')==='true':!item.hasAttribute('aria-expanded')),live:!!root.querySelector('[aria-live="polite"]'),labelledPanes:Array.from(root.querySelectorAll("[data-dp-studio-panel]")).every(panel=>{const id=panel.getAttribute("aria-labelledby");return id&&document.getElementById(id);}),labels:Array.from(root.querySelectorAll(".dp-studio-field input:not([type='hidden']),.dp-studio-field select,.dp-studio-field textarea,.dp-studio-collaboration input,.dp-studio-collaboration select,.dp-studio-collaboration textarea")).every(control=>control.labels&&control.labels.length>0)},
			collaboration:{present:!!collaboration,threads:collaboration?.querySelectorAll(".dp-studio-thread").length||0,comments:collaboration?.querySelectorAll(".dp-studio-comment").length||0,owner:collaborationSelect?.value||"",watching:collaboration?.querySelector('[value="unwatch"]')!==null,receiptVerified:collaboration?.querySelector('[data-valid="true"]')!==null},
			colors:{input:sample(input),select:sample(select),collaborationSelect:sample(collaborationSelect),collaborationInput:sample(collaborationInput),body:bodyStyle.backgroundColor},
		};
	});
}
function assertCore(facts,direction){assert(facts.enhanced,"Studio did not progressively enhance");assert(facts.direction===direction,"Studio direction mismatch");assert(facts.pageOverflow<=1&&facts.rootOverflow<=1,"Studio introduced horizontal page overflow");assert(facts.duplicates.length===0,"Studio emitted duplicate ids: "+facts.duplicates.join(", "));assert(facts.semantics.tree&&facts.semantics.treeitems>=8&&facts.semantics.roving&&facts.semantics.leafExpansion&&facts.semantics.live&&facts.semantics.labelledPanes&&facts.semantics.labels,"Studio semantic contract is incomplete: "+JSON.stringify(facts.semantics));assert(facts.collaboration.present&&facts.collaboration.threads===1&&facts.collaboration.comments===1&&facts.collaboration.owner==="mina"&&facts.collaboration.watching&&facts.collaboration.receiptVerified,"Studio collaboration projection is incomplete: "+JSON.stringify(facts.collaboration));}
function definition(page){return page.evaluate(()=>JSON.parse(document.querySelector('[name="studio_definition_json"]').value));}
function childKeys(model,pathValue){let node=model;for(const key of pathValue.split("/").slice(1)){node=node.children.find(child=>child.key===key);}return node.children.map(child=>child.key);}
async function screenshot(page,artifactRoot,name){const file=path.join(artifactRoot,name+".png");await page.screenshot({path:file,fullPage:true});return path.relative(cwd,file).replace(/\\/g,"/");}

async function desktopCase(browser,options,report){
	const opened=await openFixture(browser,fixture(options,"dark","ltr"),{width:1440,height:1000});const page=opened.page;let facts=await inspect(page);assertCore(facts,"ltr");assert(facts.mobileNavigation==="none","Desktop unexpectedly shows mobile panel navigation");assert(facts.visiblePanels.length===4,"Desktop did not expose all four editor panes");
	assert(contrast(facts.colors.input.color,facts.colors.input.background,facts.colors.body)>=4.5,"Dark inspector input contrast is below 4.5:1");assert(contrast(facts.colors.select.color,facts.colors.select.background,facts.colors.body)>=4.5,"Dark select contrast is below 4.5:1");assert(contrast(facts.colors.collaborationSelect.color,facts.colors.collaborationSelect.background,facts.colors.body)>=4.5,"Dark collaboration select contrast is below 4.5:1");assert(contrast(facts.colors.collaborationInput.color,facts.colors.collaborationInput.background,facts.colors.body)>=4.5,"Dark collaboration input contrast is below 4.5:1");
	const collaborationSubmission=await page.evaluate(()=>new Promise(resolve=>{const root=document.querySelector("[data-dp-studio-editor]"),form=root.querySelector("[data-dp-studio-command-form]");form.addEventListener("submit",event=>{event.preventDefault();const data=new FormData(form,event.submitter);resolve({operation:data.get("studio_collaboration_operation"),csrfBound:String(data.get("_token")||"").length===32,definitionBytes:String(data.get("studio_definition_json")||"").length});},{once:true});root.querySelector('[name="studio_collaboration_operation"][value="unwatch"]').click();}));
	assert(collaborationSubmission.operation==="unwatch"&&collaborationSubmission.csrfBound&&collaborationSubmission.definitionBytes>100,"Studio collaboration submission is not bound to the editor form: "+JSON.stringify(collaborationSubmission));
	const renamed=await page.evaluate(()=>{const input=document.querySelector("[data-dp-studio-key]");input.focus();input.value="contact_email";input.dispatchEvent(new Event("change",{bubbles:true}));return{active:document.activeElement?.dataset.dpStudioFocus||"",value:document.querySelector("[data-dp-studio-key]")?.value,selection:document.querySelector('[name="studio_selected_path"]').value};});
	assert(renamed.active==="property:orders/order_form/identity/contact_email:key"&&renamed.value==="contact_email"&&renamed.selection.endsWith("/contact_email"),"Inspector rename did not preserve focus and selection");
	await page.click('[data-dp-studio-action="undo"]');assert((await page.$eval("[data-dp-studio-key]",element=>element.value))==="email","Undo did not restore the component key");await page.click('[data-dp-studio-action="redo"]');assert((await page.$eval("[data-dp-studio-key]",element=>element.value))==="contact_email","Redo did not restore the component key");
	const market='[data-dp-studio-treeitem][data-dp-studio-path="orders/order_form/identity/market"]';await page.focus(market);await page.keyboard.down("Alt");await page.keyboard.press("ArrowUp");await page.keyboard.up("Alt");let model=await definition(page);assert(childKeys(model,"orders/order_form/identity").join(",")==="name,market,contact_email","Alt+ArrowUp did not reorder siblings");
	const dragged=await page.evaluate(()=>{const source=document.querySelector('[data-dp-studio-treeitem][data-dp-studio-path="orders/order_form/fulfilment/status"]'),target=document.querySelector('[data-dp-studio-path="orders/order_form/fulfilment/channel"].dp-studio-tree-row');const transfer=new DataTransfer(),rect=target.getBoundingClientRect();source.dispatchEvent(new DragEvent("dragstart",{bubbles:true,cancelable:true,dataTransfer:transfer}));target.dispatchEvent(new DragEvent("dragover",{bubbles:true,cancelable:true,dataTransfer:transfer,clientY:rect.top+1}));target.dispatchEvent(new DragEvent("drop",{bubbles:true,cancelable:true,dataTransfer:transfer,clientY:rect.top+1}));return document.querySelector('[name="studio_definition_json"]').value;});model=JSON.parse(dragged);assert(childKeys(model,"orders/order_form/fulfilment").join(",")==="status,channel","Pointer drag did not reorder siblings");
	await page.click('[data-dp-studio-treeitem][data-dp-studio-path="orders/order_form/identity"]');const portable=await page.$eval('[data-dp-studio-add="board"]',element=>({disabled:element.disabled,text:element.textContent,title:element.title}));const truthfulPortable=portable.text.includes("Portable only")&&portable.title.includes("portable-only"),truthfulParent=portable.text.includes("Parent needed")&&portable.title.includes("compatible parent");assert(portable.disabled&&(truthfulPortable||truthfulParent),"Disabled palette affordance is not truthful: "+JSON.stringify(portable));await page.click('[data-dp-studio-add="field"]');model=await definition(page);assert(childKeys(model,"orders/order_form/identity").includes("field_1"),"Palette did not add a trusted field");
	await page.select("[data-dp-studio-zoom-control]","125");await page.select("[data-dp-studio-reflow-control]","mobile");await page.waitForFunction(()=>document.querySelector(".dp-studio-canvas-sheet").getBoundingClientRect().width<=391,{timeout:3000});const canvas=await page.evaluate(()=>{const root=document.querySelector("[data-dp-studio-editor]"),sheet=document.querySelector(".dp-studio-canvas-sheet");return{zoom:root.dataset.dpStudioZoom,reflow:root.dataset.dpStudioReflow,width:sheet.getBoundingClientRect().width,dirty:root.dataset.dpStudioDirty,previewDisabled:root.querySelector('[data-dp-studio-submit="preview"]').disabled,issues:window.DataphyrePanelStudioEditor.validate(root).length};});assert(canvas.zoom==="125"&&canvas.reflow==="mobile"&&canvas.width<=391,"Canvas zoom/reflow controls did not apply: "+JSON.stringify(canvas));assert(canvas.dirty==="true"&&canvas.previewDisabled&&canvas.issues===0,"Dirty state or signed-preview protection is incorrect: "+JSON.stringify(canvas));
	facts=await inspect(page);assertCore(facts,"ltr");const accessibility=await page.accessibility.snapshot({interestingOnly:false});assert(accessibility&&accessibility.role==="RootWebArea","Chromium accessibility tree is unavailable");assert(opened.faults.length===0,"Desktop browser faults: "+opened.faults.join(" | "));report.cases.push({id:"desktop-interactions",facts,renamed,canvas,collaborationSubmission,screenshot:await screenshot(page,options.artifacts,"desktop-dark")});await page.close();
}
async function mobileCase(browser,options,report,width){
	const opened=await openFixture(browser,fixture(options,"dark","ltr","100","mobile"),{width,height:844});const page=opened.page;let facts=await inspect(page);assertCore(facts,"ltr");assert(facts.mobileNavigation==="grid"&&facts.visiblePanels.join(",")==="canvas"&&facts.pressed.join(",")==="canvas","Mobile enhancement did not default to one canvas pane");assert(facts.targets.every(target=>target.height>=43.5),"Mobile visible control target is below 44px: "+JSON.stringify(facts.targets.filter(target=>target.height<43.5)));
	for(const panel of ["tree","palette","properties","canvas"]){await page.click('[data-dp-studio-mobile-panel="'+panel+'"]');facts=await inspect(page);assert(facts.visiblePanels.join(",")===panel&&facts.pressed.join(",")===panel,"Mobile panel switch failed for "+panel);assert(facts.pageOverflow<=1&&facts.rootOverflow<=1,"Mobile panel overflowed at "+width+"px in "+panel);assert(facts.targets.every(target=>target.height>=43.5),"Mobile target is below 44px in "+panel+": "+JSON.stringify(facts.targets.filter(target=>target.height<43.5)));}
	assert(opened.faults.length===0,"Mobile browser faults: "+opened.faults.join(" | "));report.cases.push({id:"mobile-"+width,facts,screenshot:await screenshot(page,options.artifacts,"mobile-"+width)});await page.close();
}
async function rtlCase(browser,options,report){
	const opened=await openFixture(browser,fixture(options,"dark","rtl"),{width:1280,height:900});const page=opened.page;let facts=await inspect(page);assertCore(facts,"rtl");const market='[data-dp-studio-treeitem][data-dp-studio-path="orders/order_form/identity/market"]';await page.focus(market);await page.keyboard.down("Alt");await page.keyboard.press("ArrowRight");await page.keyboard.up("Alt");const model=await definition(page);assert(childKeys(model,"orders/order_form").includes("market"),"RTL logical ArrowRight did not outdent the selected node");facts=await inspect(page);assertCore(facts,"rtl");assert(opened.faults.length===0,"RTL browser faults: "+opened.faults.join(" | "));report.cases.push({id:"rtl-logical-reorder",facts,screenshot:await screenshot(page,options.artifacts,"desktop-rtl")});await page.close();
}
async function themeCase(browser,options,report,theme){
	const opened=await openFixture(browser,fixture(options,theme,"ltr"),{width:1024,height:820});const page=opened.page;const facts=await inspect(page);assertCore(facts,"ltr");assert(contrast(facts.colors.input.color,facts.colors.input.background,facts.colors.body)>=4.5,theme+" input contrast is below 4.5:1");assert(contrast(facts.colors.select.color,facts.colors.select.background,facts.colors.body)>=4.5,theme+" select contrast is below 4.5:1");assert(opened.faults.length===0,theme+" browser faults: "+opened.faults.join(" | "));report.cases.push({id:"theme-"+theme,facts,contrast:{input:contrast(facts.colors.input.color,facts.colors.input.background,facts.colors.body),select:contrast(facts.colors.select.color,facts.colors.select.background,facts.colors.body)},screenshot:theme==="glass"?await screenshot(page,options.artifacts,"desktop-glass"):null});await page.close();
}
async function mediaCases(browser,options,report){
	let opened=await openFixture(browser,fixture(options,"dark","ltr"),{width:1024,height:820},{reducedMotion:true});let page=opened.page;const reduced=await page.$eval(".dp-studio-canvas-sheet",element=>({matches:matchMedia("(prefers-reduced-motion: reduce)").matches,transition:getComputedStyle(element).transitionDuration}));assert(reduced.matches&&reduced.transition.split(",").every(value=>parseFloat(value)<=.001),"Reduced motion did not suppress Studio transitions");assert(opened.faults.length===0,"Reduced-motion browser faults");report.cases.push({id:"reduced-motion",reduced});await page.close();
	opened=await openFixture(browser,fixture(options,"dark","ltr"),{width:1024,height:820},{forced:true});page=opened.page;const forced=await page.evaluate(()=>{const root=document.querySelector("[data-dp-studio-editor]"),button=root.querySelector(".dp-studio-button-primary");return{matches:matchMedia("(forced-colors: active)").matches,rootBackground:getComputedStyle(root).backgroundColor,buttonColor:getComputedStyle(button).color,buttonBackground:getComputedStyle(button).backgroundColor};});assert(forced.matches,"Forced-colors emulation did not activate");assert(opened.faults.length===0,"Forced-colors browser faults");report.cases.push({id:"forced-colors",engine:opened.forcedEngine,forced,screenshot:await screenshot(page,options.artifacts,"forced-colors")});await page.close();
}
async function noJsCase(browser,options,report){
	const opened=await openFixture(browser,fixture(options,"dark","ltr"),{width:390,height:844},{javascript:false});const page=opened.page;const facts=await page.evaluate(()=>{const root=document.querySelector("[data-dp-studio-editor]"),visible=element=>!element.hidden&&getComputedStyle(element).display!=="none";return{enhanced:root.hasAttribute("data-dp-studio-enhanced"),mobileNavigation:getComputedStyle(root.querySelector(".dp-studio-mobile-nav")).display,panes:Array.from(root.querySelectorAll("[data-dp-studio-panel]")).filter(visible).length,treeitems:root.querySelectorAll('[role="treeitem"]').length,definition:!!root.querySelector('[name="studio_definition_json"]'),collaboration:!!root.querySelector("[data-dp-studio-collaboration]"),formCount:root.querySelectorAll("form").length,pageOverflow:document.documentElement.scrollWidth-document.documentElement.clientWidth,rootOverflow:root.scrollWidth-root.clientWidth};});assert(!facts.enhanced&&facts.mobileNavigation==="none"&&facts.panes===4&&facts.treeitems>=8&&facts.definition&&facts.collaboration&&facts.formCount===1,"No-JS SSR surface is incomplete");assert(facts.pageOverflow<=1&&facts.rootOverflow<=1,"No-JS mobile SSR surface overflows horizontally");report.cases.push({id:"no-js-ssr",facts});await page.close();
}

async function main(){
	const options=parseArguments(process.argv.slice(2));fs.mkdirSync(options.artifacts,{recursive:true});const puppeteer=findPuppeteer(),executablePath=findBrowser(options.browser),browserArgs=["--disable-dev-shm-usage","--no-first-run","--no-default-browser-check"];if(options.allowNoSandbox){browserArgs.push("--no-sandbox");}const browser=await puppeteer.launch({executablePath,headless:true,args:browserArgs});const report={type:"panel_studio_editor_browser_regression",version:2,generated_at:new Date().toISOString(),browser:await browser.version(),security:{sandbox_disabled:options.allowNoSandbox},cases:[]};
	try{await desktopCase(browser,options,report);await mobileCase(browser,options,report,320);await mobileCase(browser,options,report,390);await rtlCase(browser,options,report);await themeCase(browser,options,report,"light");await themeCase(browser,options,report,"dark");await themeCase(browser,options,report,"glass");await mediaCases(browser,options,report);await noJsCase(browser,options,report);}finally{await browser.close();}
	report.summary={passed:true,case_count:report.cases.length,viewports:[320,390,1024,1280,1440],themes:["light","dark","glass"],directions:["ltr","rtl"],keyboard_reorder:true,pointer_reorder:true,focus_preserved:true,collaboration_workspace:true,collaboration_form_binding:true,no_js_ssr:true,forced_colors:true,reduced_motion:true,no_horizontal_overflow:report.cases.every(item=>!item.facts||item.facts.pageOverflow<=1&&item.facts.rootOverflow<=1)};fs.writeFileSync(path.join(options.artifacts,"report.json"),JSON.stringify(report,null,2)+"\n");console.log("panel Studio editor browser regression passed: "+report.cases.length+" cases");
}
main().catch(error=>{console.error(error&&error.stack||error);process.exitCode=1;});
