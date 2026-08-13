#!/usr/bin/env node
'use strict';

const crypto=require('crypto');
const fs=require('fs');
const path=require('path');

const cwd=process.cwd();
const payloadPath=String(process.argv[2]||'');
let payload={};

function absolutePath(value){
	const candidate=String(value||'').trim();
	return candidate==='' ? '' : (path.isAbsolute(candidate) ? candidate : path.resolve(cwd,candidate));
}

function ensureParent(file){
	fs.mkdirSync(path.dirname(file),{recursive:true});
}

function writeResult(result,exitCode){
	const serialized=JSON.stringify(result,null,2);
	const output=absolutePath(payload.output_path);
	if(output!==''){
		ensureParent(output);
		fs.writeFileSync(output,serialized);
	}
	else{
		process.stdout.write(serialized+'\n');
	}
	process.exitCode=exitCode;
}

function loadPackage(name){
	const roots=[
		process.env.DATAPHYRE_BROWSER_NODE_MODULES,
		'/opt/dataphyre-browser/node_modules',
		path.join(cwd,'.tmp','puppeteer-check','node_modules'),
		path.join(__dirname,'node_modules'),
	].filter(Boolean);
	for(const root of roots){
		const candidate=path.join(root,name);
		try{
			if(fs.existsSync(candidate)){
				return require(candidate);
			}
		}catch(error){
			continue;
		}
	}
	try{
		return require(name);
	}catch(error){
		return null;
	}
}

function browserExecutable(){
	const candidates=[
		payload.browser,
		process.env.CHROME_PATH,
		process.env.PUPPETEER_EXECUTABLE_PATH,
		'/usr/bin/chromium',
		'/usr/bin/chromium-browser',
		'/usr/bin/google-chrome-stable',
		'C:/Program Files/Google/Chrome/Application/chrome.exe',
		'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
		path.join(process.env.LOCALAPPDATA||'','Google/Chrome/Application/chrome.exe'),
		path.join(process.env.PROGRAMFILES||'','Microsoft/Edge/Application/msedge.exe'),
		path.join(process.env['PROGRAMFILES(X86)']||'','Microsoft/Edge/Application/msedge.exe'),
	].map(value=>String(value||'').trim()).filter(Boolean);
	return candidates.find(candidate=>fs.existsSync(candidate))||'';
}

function impactRank(impact){
	return {minor:1,moderate:2,serious:3,critical:4}[String(impact||'').toLowerCase()]||0;
}

function normalizedAxeFinding(finding){
	return {
		id:String(finding.id||''),
		impact:finding.impact||null,
		description:String(finding.description||''),
		help:String(finding.help||''),
		help_url:String(finding.helpUrl||''),
		nodes:Array.isArray(finding.nodes) ? finding.nodes.map(node=>({
			target:Array.isArray(node.target) ? node.target.map(String) : [],
			html:String(node.html||''),
			failure_summary:String(node.failureSummary||''),
		})) : [],
	};
}

async function builtInAccessibility(page){
	return page.evaluate(()=>{
		const issues=[];
		const text=value=>String(value||'').replace(/\s+/g,' ').trim();
		const accessibleName=element=>{
			const aria=text(element.getAttribute('aria-label'));
			if(aria!==''){return aria;}
			const labelledBy=text(element.getAttribute('aria-labelledby'));
			if(labelledBy!==''){
				const label=labelledBy.split(/\s+/).map(id=>document.getElementById(id)).filter(Boolean).map(node=>text(node.textContent)).join(' ');
				if(label!==''){return label;}
			}
			if(element.id){
				const explicit=document.querySelector('label[for="'+CSS.escape(element.id)+'"]');
				if(explicit&&text(explicit.textContent)!==''){return text(explicit.textContent);}
			}
			const wrapping=element.closest('label');
			if(wrapping&&text(wrapping.textContent)!==''){return text(wrapping.textContent);}
			return text(element.textContent||element.getAttribute('title')||element.getAttribute('alt'));
		};
		if(text(document.documentElement.getAttribute('lang'))===''){
			issues.push({rule:'html-lang',target:'html',message:'The document must declare a language.'});
		}
		for(const image of document.querySelectorAll('img')){
			if(!image.hasAttribute('alt')){
				issues.push({rule:'image-alt',target:image.id ? '#'+image.id : 'img',message:'Images must declare alt text, including an empty alt for decorative images.'});
			}
		}
		for(const control of document.querySelectorAll('button,[role="button"],input:not([type="hidden"]),select,textarea')){
			if(accessibleName(control)===''){
				issues.push({rule:'control-name',target:control.id ? '#'+control.id : control.tagName.toLowerCase(),message:'Interactive controls must have an accessible name.'});
			}
		}
		return issues;
	});
}

async function runAxe(page,axeCore){
	await page.evaluate(axeCore.source);
	const tags=Array.isArray(payload.axe_tags) ? payload.axe_tags.map(String).filter(Boolean) : [];
	const options=tags.length>0 ? {runOnly:{type:'tag',values:tags}} : {};
	const raw=await page.evaluate(async runOptions=>window.axe.run(document,runOptions),options);
	const minimum=impactRank(payload.axe_min_impact||'minor');
	const violations=(raw.violations||[]).filter(item=>impactRank(item.impact)>=minimum).map(normalizedAxeFinding);
	return {
		skipped:false,
		minimum_impact:String(payload.axe_min_impact||'minor'),
		tags,
		summary:{
			passes:(raw.passes||[]).length,
			violations:violations.length,
			incomplete:(raw.incomplete||[]).length,
			inapplicable:(raw.inapplicable||[]).length,
		},
		violations,
	};
}

function diffPathFor(actual){
	const extension=path.extname(actual);
	return extension==='' ? actual+'.diff.png' : actual.slice(0,-extension.length)+'.diff'+extension;
}

function comparePng(actualPath,baselinePath,diffPath,PNG,options){
	const actual=PNG.sync.read(fs.readFileSync(actualPath));
	const baseline=PNG.sync.read(fs.readFileSync(baselinePath));
	const width=Math.max(actual.width,baseline.width);
	const height=Math.max(actual.height,baseline.height);
	const diff=new PNG({width,height});
	const threshold=Math.max(0,Math.min(255,Number(options.visual_pixel_threshold||0)));
	let diffPixels=0;
	for(let y=0;y<height;y++){
		for(let x=0;x<width;x++){
			const outputOffset=(y*width+x)*4;
			const actualInside=x<actual.width&&y<actual.height;
			const baselineInside=x<baseline.width&&y<baseline.height;
			let changed=!actualInside||!baselineInside;
			let red=0,green=0,blue=0,alpha=0;
			if(actualInside){
				const actualOffset=(y*actual.width+x)*4;
				red=actual.data[actualOffset];green=actual.data[actualOffset+1];blue=actual.data[actualOffset+2];alpha=actual.data[actualOffset+3];
				if(baselineInside){
					const baselineOffset=(y*baseline.width+x)*4;
					changed=Math.max(
						Math.abs(red-baseline.data[baselineOffset]),
						Math.abs(green-baseline.data[baselineOffset+1]),
						Math.abs(blue-baseline.data[baselineOffset+2]),
						Math.abs(alpha-baseline.data[baselineOffset+3])
					)>threshold;
				}
			}
			if(changed){
				diffPixels++;
				diff.data[outputOffset]=255;diff.data[outputOffset+1]=0;diff.data[outputOffset+2]=0;diff.data[outputOffset+3]=255;
			}else{
				diff.data[outputOffset]=red;diff.data[outputOffset+1]=green;diff.data[outputOffset+2]=blue;diff.data[outputOffset+3]=Math.min(alpha,64);
			}
		}
	}
	ensureParent(diffPath);
	fs.writeFileSync(diffPath,PNG.sync.write(diff));
	const totalPixels=Math.max(1,width*height);
	const ratio=diffPixels/totalPixels;
	const maxPixels=Math.max(0,Number(options.visual_max_diff_pixels||0));
	const maxRatio=Math.max(0,Number(options.visual_max_diff_ratio||0));
	return {
		matches:diffPixels<=maxPixels&&ratio<=maxRatio,
		diff_pixels:diffPixels,
		total_pixels:totalPixels,
		diff_ratio:ratio,
		pixel_threshold:threshold,
		max_diff_pixels:maxPixels,
		max_diff_ratio:maxRatio,
		dimensions_match:actual.width===baseline.width&&actual.height===baseline.height,
		diff_path:diffPath,
	};
}

async function main(){
	if(payloadPath===''||!fs.existsSync(payloadPath)){
		writeResult({passed:false,reason:'Browser payload is missing or unreadable.'},1);
		return;
	}
	try{
		payload=JSON.parse(fs.readFileSync(payloadPath,'utf8'));
	}catch(error){
		writeResult({passed:false,reason:'Browser payload is invalid JSON.',error:String(error.message||error)},1);
		return;
	}
	const puppeteer=loadPackage('puppeteer-core');
	if(!puppeteer){
		writeResult({passed:true,skipped:true,reason:'puppeteer-core is not installed for the Dataphyre browser worker.'},0);
		return;
	}
	const executable=browserExecutable();
	if(executable===''){
		writeResult({passed:true,skipped:true,reason:'No Chromium, Chrome, or Edge executable is available to the Dataphyre browser worker.'},0);
		return;
	}
	const visualRequested=String(payload.visual_baseline_path||'').trim()!=='';
	const PNG=visualRequested ? loadPackage('pngjs')?.PNG : null;
	if(visualRequested&&!PNG){
		writeResult({passed:true,skipped:true,reason:'pngjs is not installed for visual browser comparisons.'},0);
		return;
	}
	const axeCore=payload.assert_axe===true ? loadPackage('axe-core') : null;
	if(payload.assert_axe===true&&!axeCore){
		writeResult({passed:true,skipped:true,reason:'axe-core is not installed for accessibility browser assertions.'},0);
		return;
	}

	let browser;
	try{
		browser=await puppeteer.launch({
			executablePath:executable,
			headless:true,
			args:['--disable-dev-shm-usage','--disable-gpu','--no-sandbox','--no-zygote'],
		});
		const page=await browser.newPage();
		const viewport=payload.viewport&&typeof payload.viewport==='object' ? payload.viewport : {};
		await page.setViewport({
			width:Math.max(1,Number(viewport.width||1280)),
			height:Math.max(1,Number(viewport.height||720)),
			deviceScaleFactor:Math.max(1,Number(viewport.device_scale_factor||1)),
		});
		if(String(payload.url||'').trim()!==''){
			await page.goto(String(payload.url),{waitUntil:'networkidle0',timeout:Math.max(1000,Number(payload.navigation_timeout_millis||15000))});
		}else{
			await page.setContent(String(payload.html||''),{waitUntil:'networkidle0',timeout:Math.max(1000,Number(payload.navigation_timeout_millis||15000))});
		}
		await page.addStyleTag({content:'*,*::before,*::after{animation-duration:0s!important;animation-delay:0s!important;transition-duration:0s!important;caret-color:transparent!important}'});
		const failures=[];
		for(const selector of Array.isArray(payload.expect_selectors)?payload.expect_selectors:[]){
			try{
				if(!await page.$(String(selector))){failures.push({type:'missing_selector',selector:String(selector)});}
			}catch(error){
				failures.push({type:'invalid_selector',selector:String(selector),message:String(error.message||error)});
			}
		}
		const bodyText=await page.evaluate(()=>document.body?String(document.body.innerText||''):String(document.documentElement.innerText||''));
		for(const expected of Array.isArray(payload.expect_text)?payload.expect_text:[]){
			if(!bodyText.includes(String(expected))){failures.push({type:'missing_text',text:String(expected)});}
		}
		const a11yIssues=(payload.assert_a11y===true||payload.assert_axe===true) ? await builtInAccessibility(page) : [];
		const axe=payload.assert_axe===true
			? await runAxe(page,axeCore)
			: {skipped:true,reason:'axe was not requested',summary:{passes:0,violations:0,incomplete:0,inapplicable:0},violations:[]};
		if(a11yIssues.length>0){failures.push({type:'accessibility',issues:a11yIssues});}
		if(axe.violations.length>0){failures.push({type:'axe',violations:axe.violations});}

		let screenshot=null;
		const requestedScreenshot=String(payload.screenshot_path||'').trim();
		if(requestedScreenshot!==''||visualRequested){
			const screenshotPath=absolutePath(requestedScreenshot!==''?requestedScreenshot:'cache/unit-test-browser/browser.actual.png');
			ensureParent(screenshotPath);
			await page.screenshot({path:screenshotPath,fullPage:payload.full_page!==false,type:'png'});
			const bytes=fs.readFileSync(screenshotPath);
			screenshot={path:screenshotPath,sha256:crypto.createHash('sha256').update(bytes).digest('hex'),bytes:bytes.length};
			if(visualRequested){
				const baselinePath=absolutePath(payload.visual_baseline_path);
				const update=payload.update_visual_baseline===true;
				if(update){
					ensureParent(baselinePath);
					fs.copyFileSync(screenshotPath,baselinePath);
					screenshot.matches_baseline=true;
					screenshot.baseline_path=baselinePath;
					screenshot.baseline_updated=true;
					screenshot.visual_diff={diff_pixels:0,total_pixels:0,diff_ratio:0,diff_path:diffPathFor(screenshotPath),dimensions_match:true};
				}else if(!fs.existsSync(baselinePath)){
					screenshot.matches_baseline=false;
					screenshot.baseline_path=baselinePath;
					failures.push({type:'missing_visual_baseline',baseline_path:baselinePath});
				}else{
					const comparison=comparePng(screenshotPath,baselinePath,diffPathFor(screenshotPath),PNG,payload);
					screenshot.matches_baseline=comparison.matches;
					screenshot.baseline_path=baselinePath;
					screenshot.baseline_updated=false;
					screenshot.visual_diff=comparison;
					if(!comparison.matches){failures.push({type:'visual_difference',comparison});}
				}
			}
		}

		const result={
			passed:failures.length===0,
			skipped:false,
			browser:{engine:'chromium',executable},
			title:await page.title(),
			url:page.url(),
			viewport:page.viewport(),
			a11y_issues:a11yIssues,
			axe,
			screenshot,
			failures,
		};
		writeResult(result,result.passed?0:1);
	}catch(error){
		writeResult({
			passed:false,
			skipped:false,
			reason:'Browser worker execution failed.',
			error:{name:String(error.name||'Error'),message:String(error.message||error),stack:String(error.stack||'')},
			browser:{engine:'chromium',executable},
		},1);
	}finally{
		if(browser){
			try{await browser.close();}catch(error){}
		}
	}
}

main();
