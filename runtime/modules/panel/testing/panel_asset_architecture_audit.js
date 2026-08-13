#!/usr/bin/env node
'use strict';

const fs=require('fs');
const path=require('path');
const {interactionRegistry}=require('./panel_browser_scenarios.js');

const DEFAULT_BUDGETS=Object.freeze(require('./panel_asset_budgets.json'));
const RELEASE_CONTRACT=Object.freeze(require('./panel_release_contract.json'));

const args=parseArgs(process.argv.slice(2));

function parseArgs(argv){
	const parsed={baseUrl:process.env.DP_PANEL_BASE_URL||'',cssFile:'',jsFile:'',capabilities:'',report:'',reportOnly:false,budgets:{...DEFAULT_BUDGETS}};
	for(let index=0;index<argv.length;index++){
		const argument=argv[index];
		const value=()=>{
			const inline=argument.indexOf('=');
			if(inline!==-1){return argument.slice(inline+1);}
			index++;
			return argv[index]||'';
		};
		if(argument==='--help'||argument==='-h'){parsed.help=true;}
		else if(argument==='--self-test'){parsed.selfTest=true;}
		else if(argument==='--report-only'){parsed.reportOnly=true;}
		else if(argument==='--base-url'||argument.startsWith('--base-url=')){parsed.baseUrl=value();}
		else if(argument==='--css-file'||argument.startsWith('--css-file=')){parsed.cssFile=value();}
		else if(argument==='--js-file'||argument.startsWith('--js-file=')){parsed.jsFile=value();}
		else if(argument==='--capabilities'||argument.startsWith('--capabilities=')){parsed.capabilities=value();}
		else if(argument==='--report'||argument.startsWith('--report=')){parsed.report=value();}
		else if(argument.startsWith('--max-')){
			const key=argument.slice(6,argument.includes('=')?argument.indexOf('='):undefined).replace(/-([a-z])/g,(_,letter)=>letter.toUpperCase());
			if(!Object.prototype.hasOwnProperty.call(parsed.budgets,key)){throw new Error('Unknown budget: '+key);}
			parsed.budgets[key]=Math.max(0,Number(value()));
		}
		else {throw new Error('Unknown argument: '+argument+'. Run with --help for usage.');}
	}
	return parsed;
}

function printHelp(){
	console.log([
		'Dataphyre Panel asset architecture audit',
		'',
		'Usage:',
		'  node panel_asset_architecture_audit.js --base-url http://127.0.0.1:8089/debug',
		'  node panel_asset_architecture_audit.js --css-file panel.css --js-file panel.js',
		'',
		'Options:',
		'  --base-url <url>            Fetch panel.css and panel.js from a mounted Panel.',
		'  --css-file <path>           Audit a local stylesheet.',
		'  --js-file <path>            Audit a local runtime bundle.',
		'  --capabilities <token>      Request a canonical capability variant from --base-url.',
		'  --report <path>             Write the complete JSON audit report.',
		'  --report-only               Report failures without a non-zero exit.',
		'  --self-test                 Verify selector specificity and CSS delimiter semantics.',
		'  --max-<budget> <number>     Override a kebab-case budget key.',
		'',
		'Budgets: '+Object.entries(DEFAULT_BUDGETS).map(([key,value])=>key+'='+value).join(', '),
	].join('\n'));
}

function assetUrl(baseUrl,asset,capabilities=''){
	const url=new URL(baseUrl);
	url.searchParams.set('dp_panel_asset',asset);
	if(capabilities){url.searchParams.set('dp_panel_caps',capabilities);}
	return url.toString();
}

async function loadAsset(file,baseUrl,asset,capabilities=''){
	if(file){return fs.readFileSync(path.resolve(file),'utf8');}
	if(!baseUrl){throw new Error('Provide --base-url or both --css-file and --js-file.');}
	const response=await fetch(assetUrl(baseUrl,asset,capabilities),{headers:{accept:asset.endsWith('.css')?'text/css':'application/javascript'}});
	if(!response.ok){throw new Error(asset+' returned HTTP '+response.status+'.');}
	return response.text();
}

function skipTrivia(source,index,end){
	while(index<end){
		if(/\s/.test(source[index])){index++;continue;}
		if(source[index]==='/'&&source[index+1]==='*'){
			const close=source.indexOf('*/',index+2);
			return close===-1?end:skipTrivia(source,close+2,end);
		}
		break;
	}
	return index;
}

function delimiter(source,start,end){
	let quote='',round=0,square=0;
	for(let index=start;index<end;index++){
		const char=source[index];
		if(quote){
			if(char==='\\'){index++;continue;}
			if(char===quote){quote='';}
			continue;
		}
		if(char==='"'||char==="'"){quote=char;continue;}
		if(char==='/'&&source[index+1]==='*'){
			const close=source.indexOf('*/',index+2);
			if(close===-1){return {index:end,char:''};}
			index=close+1;
			continue;
		}
		if(char==='('){round++;continue;}
		if(char===')'){round=Math.max(0,round-1);continue;}
		if(char==='['){square++;continue;}
		if(char===']'){square=Math.max(0,square-1);continue;}
		if(round===0&&square===0&&(char==='{'||char===';')){return {index,char};}
	}
	return {index:end,char:''};
}

function matchingBrace(source,open,end){
	let depth=1,quote='';
	for(let index=open+1;index<end;index++){
		const char=source[index];
		if(quote){
			if(char==='\\'){index++;continue;}
			if(char===quote){quote='';}
			continue;
		}
		if(char==='"'||char==="'"){quote=char;continue;}
		if(char==='/'&&source[index+1]==='*'){
			const close=source.indexOf('*/',index+2);
			if(close===-1){return end-1;}
			index=close+1;
			continue;
		}
		if(char==='{'){depth++;}
		else if(char==='}'&&--depth===0){return index;}
	}
	return end-1;
}

function splitSelectors(selectorText){
	const selectors=[];
	let start=0,quote='',round=0,square=0;
	for(let index=0;index<selectorText.length;index++){
		const char=selectorText[index];
		if(quote){
			if(char==='\\'){index++;continue;}
			if(char===quote){quote='';}
			continue;
		}
		if(char==='"'||char==="'"){quote=char;continue;}
		if(char==='/'&&selectorText[index+1]==='*'){
			const close=selectorText.indexOf('*/',index+2);
			if(close===-1){break;}
			index=close+1;
			continue;
		}
		if(char==='('){round++;continue;}
		if(char===')'){round=Math.max(0,round-1);continue;}
		if(char==='['){square++;continue;}
		if(char===']'){square=Math.max(0,square-1);continue;}
		if(char===','&&round===0&&square===0){selectors.push(selectorText.slice(start,index));start=index+1;}
	}
	selectors.push(selectorText.slice(start));
	return selectors.map(normalizeSelector).filter(Boolean);
}

function normalizeSelector(selector){
	return selector.replace(/\/\*[\s\S]*?\*\//g,' ').replace(/\s+/g,' ').replace(/\s*([>+~])\s*/g,'$1').trim();
}

function specificityParts(ids=0,classes=0,types=0){
	return {ids,classes,types};
}

function addSpecificity(target,addition){
	target.ids+=addition.ids;
	target.classes+=addition.classes;
	target.types+=addition.types;
	return target;
}

function compareSpecificity(left,right){
	return left.ids-right.ids||left.classes-right.classes||left.types-right.types;
}

function maximumSpecificity(selectorList){
	let maximum=specificityParts();
	for(const selector of splitSelectors(selectorList)){
		const candidate=specificity(selector);
		if(compareSpecificity(candidate,maximum)>0){maximum=specificityParts(candidate.ids,candidate.classes,candidate.types);}
	}
	return maximum;
}

function isIdentifierCharacter(char){
	return Boolean(char)&&/[\w\u0080-\uFFFF-]/u.test(char);
}

function consumeEscape(source,index,end){
	index++;
	if(index>=end){return index;}
	if(/[0-9a-f]/i.test(source[index])){
		let digits=0;
		while(index<end&&digits<6&&/[0-9a-f]/i.test(source[index])){index++;digits++;}
		if(index<end&&/\s/.test(source[index])){index++;}
		return index;
	}
	return index+1;
}

function consumeIdentifier(source,index,end){
	while(index<end){
		if(source[index]==='\\'){index=consumeEscape(source,index,end);continue;}
		if(!isIdentifierCharacter(source[index])){break;}
		index++;
	}
	return index;
}

function balancedClose(source,open,end,opening,closing){
	let depth=1,quote='';
	for(let index=open+1;index<end;index++){
		const char=source[index];
		if(quote){
			if(char==='\\'){index=consumeEscape(source,index,end)-1;continue;}
			if(char===quote){quote='';}
			continue;
		}
		if(char==='"'||char==="'"){quote=char;continue;}
		if(char==='/'&&source[index+1]==='*'){
			const close=source.indexOf('*/',index+2);
			if(close===-1){return end;}
			index=close+1;
			continue;
		}
		if(char===opening){depth++;}
		else if(char===closing&&--depth===0){return index;}
	}
	return end;
}

function nthOfSelectorList(argument){
	let quote='',round=0,square=0;
	for(let index=0;index<argument.length;index++){
		const char=argument[index];
		if(quote){
			if(char==='\\'){index=consumeEscape(argument,index,argument.length)-1;continue;}
			if(char===quote){quote='';}
			continue;
		}
		if(char==='"'||char==="'"){quote=char;continue;}
		if(char==='/'&&argument[index+1]==='*'){
			const close=argument.indexOf('*/',index+2);
			if(close===-1){return '';}
			index=close+1;
			continue;
		}
		if(char==='('){round++;continue;}
		if(char===')'){round=Math.max(0,round-1);continue;}
		if(char==='['){square++;continue;}
		if(char===']'){square=Math.max(0,square-1);continue;}
		if(round||square||!isIdentifierCharacter(char)){continue;}
		const close=consumeIdentifier(argument,index,argument.length);
		if(argument.slice(index,close).toLowerCase()==='of'){
			return argument.slice(close).trim();
		}
		index=close-1;
	}
	return '';
}

function specificity(selector){
	const result=specificityParts();
	const end=selector.length;
	const legacyPseudoElements=new Set(['after','before','first-letter','first-line']);
	for(let index=0;index<end;){
		const char=selector[index];
		if(char==='/'&&selector[index+1]==='*'){
			const close=selector.indexOf('*/',index+2);
			index=close===-1?end:close+2;
			continue;
		}
		if(char==='\\'){
			const close=consumeIdentifier(selector,index,end);
			if(close>index){result.types++;index=close;continue;}
		}
		if(char==='#'||char==='.'){
			if(char==='#'){result.ids++;}else{result.classes++;}
			index=consumeIdentifier(selector,index+1,end);
			continue;
		}
		if(char==='['){
			result.classes++;
			const close=balancedClose(selector,index,end,'[',']');
			index=close<end?close+1:end;
			continue;
		}
		if(char===':'){
			const pseudoElement=selector[index+1]===':';
			const nameStart=index+(pseudoElement?2:1);
			const nameEnd=consumeIdentifier(selector,nameStart,end);
			const name=selector.slice(nameStart,nameEnd).toLowerCase();
			const functional=selector[nameEnd]==='(';
			const close=functional?balancedClose(selector,nameEnd,end,'(',')'):nameEnd;
			const argument=functional?selector.slice(nameEnd+1,close):'';
			if(pseudoElement||legacyPseudoElements.has(name)){
				result.types++;
				if(name==='slotted'&&functional){addSpecificity(result,maximumSpecificity(argument));}
			}
			else if(name==='where'){
				// :where() and every selector nested inside it deliberately contribute zero.
			}
			else if(name==='is'||name==='not'||name==='has'){
				addSpecificity(result,maximumSpecificity(argument));
			}
			else if(name==='nth-child'||name==='nth-last-child'){
				result.classes++;
				const selectorList=nthOfSelectorList(argument);
				if(selectorList){addSpecificity(result,maximumSpecificity(selectorList));}
			}
			else{
				result.classes++;
			}
			index=functional?(close<end?close+1:end):nameEnd;
			continue;
		}
		if(char==='*'){
			index+=selector[index+1]==='|'&&selector[index+2]!=='|'?2:1;
			continue;
		}
		if(/[a-zA-Z_\u0080-\uFFFF-]/u.test(char)){
			const close=consumeIdentifier(selector,index,end);
			if(selector[close]==='|'&&selector[close+1]!=='|'){
				index=close+1;
				continue;
			}
			result.types++;
			index=close;
			continue;
		}
		index++;
	}
	return {...result,score:result.ids*100+(result.classes*10)+result.types};
}

function runSpecificitySelfTest(){
	const cases=[
		['ordinary compound','#app.panel[data-state]:hover::before',[1,3,1]],
		['is takes one most-specific argument','.shell:is(.item,#winner,article.ready)',[1,1,0]],
		['is does not add every alternative','.dp-panel :is(.one,.two,.three,.four)',[0,2,0]],
		['panel commandbar regression','.dp-panel :is(.dp-panel-commandbar,.dp-panel-commandbar-top,.dp-panel-commandbar-bottom,.dp-panel-commandbar-search,.dp-panel-commandbar-primary,.dp-panel-commandbar-view,.dp-panel-commandbar-utility,.dp-panel-commandbar-actions,.dp-panel-commandbar-groups,.dp-panel-toolbar,.dp-panel-table-toolbar,.dp-panel-form-toolbar,.dp-panel-page-table>header,.dp-panel-record-heading,.dp-panel-relation-header)',[0,2,1]],
		['nested selector-list pseudos',':not(:where(#ignored),:is(.item,article#kept))',[1,0,1]],
		['where is always zero','.root:where(#ignored,.many.classes):hover',[0,2,0]],
		['has uses its most-specific relative selector','section:has(> .notice,#dialog .action)',[1,1,1]],
		['nth-child adds pseudo-class and of-list',':nth-child(2n + 1 of :is(.row,#chosen),li.active)',[1,1,0]],
		['nth-last-child honors where in of-list','ul:nth-last-child(odd of :where(#ignored),li.active)',[0,2,2]],
		['nth without of-list',':nth-child(2n + 1)',[0,1,0]],
		['lexicographic maximum beats many classes',':is(.a.b.c.d.e.f.g.h.i.j.k,#id)',[1,0,0]],
		['functional pseudo-element','::slotted(.item,#selected)',[1,0,1]],
		['namespace and escaped identifier','svg|a.foo\\:bar',[0,1,1]],
	];
	const results=cases.map(([name,selector,expected])=>{
		const actual=specificity(selector);
		const tuple=[actual.ids,actual.classes,actual.types];
		return {name,selector,expected,actual:tuple,score:actual.score,ok:JSON.stringify(tuple)===JSON.stringify(expected)};
	});
	const delimiterCases=[
		['balanced braces cannot hide an unterminated nested var()', '@layer test{.item{--basis:var(--item,var(--fallback,auto);color:red}}', diagnostics=>diagnostics.curly===0&&diagnostics.round===1&&diagnostics.declarationFunctionImbalances===1],
		['quoted and commented delimiters do not affect structure', '@layer test{.item{content:"}]);(";/* }]);( */--basis:var(--item,calc((100% - 2px) / 2));color:red}}', diagnostics=>Object.entries(diagnostics).filter(([key])=>key!=='declarationSamples').every(([,value])=>value===0)],
	];
	for(const [name,source,accept] of delimiterCases){
		const diagnostics=cssDelimiterIntegrity(source);
		results.push({name,source,diagnostics,ok:accept(diagnostics)});
	}
	const failures=results.filter(result=>!result.ok);
	console.log(JSON.stringify({type:'dataphyre_panel_asset_architecture_self_test',ok:failures.length===0,cases:results.length,failures},null,2));
	if(failures.length){process.exitCode=1;}
}

function normalizeBody(body){
	return body.replace(/\/\*[\s\S]*?\*\//g,'').replace(/\s+/g,' ').replace(/\s*([:;,])\s*/g,'$1').trim();
}

/**
 * Verifies the generated stylesheet as a token stream, not merely as text.
 * A missing function closer can keep raw braces balanced while making the
 * browser consume following rules as part of a declaration value.
 */
function cssDelimiterIntegrity(source){
	let quote='';
	let comment=false;
	let escaped=false;
	let round=0;
	let square=0;
	let curly=0;
	let unexpectedClosers=0;
	let unterminatedComments=0;
	let unterminatedStrings=0;
	let masked='';
	for(let index=0;index<source.length;index++){
		const char=source[index];
		const next=source[index+1]||'';
		if(comment){
			masked+=' ';
			if(char==='*'&&next==='/'){masked+=' ';index++;comment=false;}
			continue;
		}
		if(quote){
			masked+=' ';
			if(escaped){escaped=false;continue;}
			if(char==='\\'){escaped=true;continue;}
			if(char===quote){quote='';}
			continue;
		}
		if(char==='/'&&next==='*'){masked+='  ';index++;comment=true;continue;}
		if(char==='"'||char==="'"){masked+=' ';quote=char;continue;}
		masked+=char;
		if(char==='('){round++;}
		else if(char===')'){if(round===0){unexpectedClosers++;}else{round--;}}
		else if(char==='['){square++;}
		else if(char===']'){if(square===0){unexpectedClosers++;}else{square--;}}
		else if(char==='{'){curly++;}
		else if(char==='}'){if(curly===0){unexpectedClosers++;}else{curly--;}}
	}
	if(comment){unterminatedComments=1;}
	if(quote){unterminatedStrings=1;}
	const declarationViolations=[];
	for(const raw of masked.split(/[;{}]/)){
		const declaration=raw.trim();
		if(!/^(?:--[a-z0-9_-]+|-[a-z][a-z0-9_-]*|[a-z][a-z0-9_-]*)\s*:/i.test(declaration)){continue;}
		const opens=(declaration.match(/\(/g)||[]).length;
		const closes=(declaration.match(/\)/g)||[]).length;
		const squareOpens=(declaration.match(/\[/g)||[]).length;
		const squareCloses=(declaration.match(/\]/g)||[]).length;
		if(opens!==closes||squareOpens!==squareCloses){
			declarationViolations.push({declaration:declaration.slice(0,240),round:opens-closes,square:squareOpens-squareCloses});
		}
	}
	return {
		round,
		square,
		curly,
		unexpectedClosers,
		unterminatedComments,
		unterminatedStrings,
		declarationFunctionImbalances:declarationViolations.length,
		declarationSamples:declarationViolations.slice(0,12),
	};
}

function analyzeCss(css){
	const delimiterIntegrity=cssDelimiterIntegrity(css);
	const rules=[];
	const layers=new Set();
	const declaredLayerOrder=((css.match(/^\s*@layer\s+([^;{]+);/)||[])[1]||'').split(',').map(layer=>layer.trim()).filter(Boolean);
	const ownerMarkers=[...css.matchAll(/\/\* dp-owner:([a-z0-9-]+) \*\//g)].map(match=>({index:match.index,name:match[1]}));
	const ownerAt=index=>{
		let owner='tokens';
		for(const marker of ownerMarkers){if(marker.index>index){break;}owner=marker.name;}
		return owner;
	};
	function walk(start,end,contexts){
		let cursor=start;
		while(cursor<end){
			cursor=skipTrivia(css,cursor,end);
			if(cursor>=end){break;}
			const found=delimiter(css,cursor,end);
			const prelude=css.slice(cursor,found.index).replace(/\/\*[\s\S]*?\*\//g,' ').replace(/\s+/g,' ').trim();
			if(!found.char){break;}
			if(found.char===';'){cursor=found.index+1;continue;}
			const close=matchingBrace(css,found.index,end);
			const bodyStart=found.index+1;
			if(prelude.startsWith('@')){
				const name=(prelude.match(/^@([\w-]+)/)||[])[1]||'';
				if(name==='layer'){
					const layerName=prelude.slice(6).trim();
					if(layerName){layers.add(layerName);}
				}
				if(['media','supports','container','layer','scope','document'].includes(name)){
					walk(bodyStart,close,contexts.concat(prelude));
				}
			}
			else if(prelude){
				const body=css.slice(bodyStart,close);
				for(const selector of splitSelectors(prelude)){
					rules.push({selector,selectorText:normalizeSelector(prelude),owner:ownerAt(cursor),context:contexts.join(' > '),body:normalizeBody(body),specificity:specificity(selector)});
				}
			}
			cursor=close+1;
		}
	}
	walk(0,css.length,[]);
	const selectorCounts=new Map();
	const selectorRuleGroups=new Map();
	const exactCounts=new Map();
	const exactRuleGroups=new Map();
	for(const rule of rules){
		const selectorKey=rule.context+'\n'+rule.selector;
		selectorCounts.set(selectorKey,(selectorCounts.get(selectorKey)||0)+1);
		if(!selectorRuleGroups.has(selectorKey)){selectorRuleGroups.set(selectorKey,[]);}
		selectorRuleGroups.get(selectorKey).push(rule);
		const exactKey=selectorKey+'\n'+rule.body;
		exactCounts.set(exactKey,(exactCounts.get(exactKey)||0)+1);
		if(!exactRuleGroups.has(exactKey)){exactRuleGroups.set(exactKey,[]);}
		exactRuleGroups.get(exactKey).push(rule);
	}
	const duplicates=[...selectorCounts.entries()].filter(([,count])=>count>1).sort((a,b)=>b[1]-a[1]);
	const exactDuplicates=[...exactCounts.entries()].filter(([,count])=>count>1).sort((a,b)=>b[1]-a[1]);
	const specificityLeaders=[...rules].sort((a,b)=>b.specificity.score-a.specificity.score).slice(0,20).map(rule=>({selector:rule.selector,context:rule.context,...rule.specificity}));
	return {
		bytes:Buffer.byteLength(css),
		importantDeclarations:(css.match(/!important\b/gi)||[]).length,
		delimiterIntegrity,
		malformedSelectorListTails:(css.match(/,\s*(?=\}|@(?:media|container|supports|layer)\b)/gi)||[]).length,
		emptyConditionalBlocks:(css.match(/@(media|container|supports)[^{]*\{\s*\}/gi)||[]).length,
		styleRules:rules.length,
		uniqueSelectorContexts:selectorCounts.size,
		duplicateSelectorKeys:duplicates.length,
		duplicateSelectorOccurrences:duplicates.reduce((sum,[,count])=>sum+count-1,0),
		exactDuplicateRuleKeys:exactDuplicates.length,
		exactDuplicateRules:exactDuplicates.reduce((sum,[,count])=>sum+count-1,0),
		maxSpecificity:specificityLeaders[0]?.score||0,
		chainedPanelRoots:(css.match(/\.dp-panel\.dp-panel/g)||[]).length,
		layers:[...layers],
		declaredLayerOrder,
		sourceOwners:ownerMarkers.map(marker=>marker.name),
		topDuplicateSelectors:duplicates.slice(0,50).map(([key,count])=>{
			const parts=key.split('\n');
			const occurrences=selectorRuleGroups.get(key)||[];
			const bodies=[...new Set(occurrences.map(rule=>rule.body))];
			return {selector:parts.pop(),context:parts.join('\n'),count,owners:occurrences.map(rule=>rule.owner),conflictingBodies:bodies.length,bodies};
		}),
		topExactDuplicateRules:exactDuplicates.map(([key,count])=>{
			const parts=key.split('\n');
			const occurrences=exactRuleGroups.get(key)||[];
			return {body:parts.pop(),selector:parts.pop(),context:parts.join('\n'),count,owners:occurrences.map(rule=>rule.owner),selectorTexts:[...new Set(occurrences.map(rule=>rule.selectorText))]};
		}),
		specificityLeaders,
	};
}

function analyzeJavascript(javascript){
	const functionDeclarations=(javascript.match(/^function\s+[A-Za-z_$][\w$]*\s*\(/gm)||[]).length;
	const moduleScoped=/^\/\* dp-panel-modal-submit-fallback-v2 \*\/\n\(function\(window,document\)\{\n/.test(javascript);
	const listenerPattern=/dpPanelListen\(\s*(document|window)\s*,\s*["']([^"']+)/g;
	const unmanagedPattern=/(document|window)\.addEventListener\(\s*["']([^"']+)/g;
	const listeners={};
	for(const match of javascript.matchAll(listenerPattern)){
		const key=match[1]+':'+match[2];
		listeners[key]=(listeners[key]||0)+1;
	}
	const unmanagedListeners={};
	for(const match of javascript.matchAll(unmanagedPattern)){
		const key=match[1]+':'+match[2];
		unmanagedListeners[key]=(unmanagedListeners[key]||0)+1;
	}
	return {
		bytes:Buffer.byteLength(javascript),
		documentListeners:Object.entries(listeners).filter(([key])=>key.startsWith('document:')).reduce((sum,[,count])=>sum+count,0),
		windowListeners:Object.entries(listeners).filter(([key])=>key.startsWith('window:')).reduce((sum,[,count])=>sum+count,0),
		listenerTypes:listeners,
		managedGlobalListeners:Object.values(listeners).reduce((sum,count)=>sum+count,0),
		unmanagedGlobalListeners:Object.values(unmanagedListeners).reduce((sum,count)=>sum+count,0),
		unmanagedListenerTypes:unmanagedListeners,
		functionDeclarations,
		globalFunctionDeclarations:moduleScoped ? 0 : functionDeclarations,
		moduleScoped,
		immediatelyInvokedClosures:(javascript.match(/\(function\s*\(/g)||[]).length,
	};
}

function enforce(css,javascript,budgets){
	const checks=[
		['cssBytes',css.bytes,budgets.cssBytes],
		['jsBytes',javascript.bytes,budgets.jsBytes],
		['importantDeclarations',css.importantDeclarations,budgets.importantDeclarations],
		['duplicateSelectorOccurrences',css.duplicateSelectorOccurrences,budgets.duplicateSelectorOccurrences],
		['exactDuplicateRules',css.exactDuplicateRules,budgets.exactDuplicateRules],
		['maxSpecificity',css.maxSpecificity,budgets.maxSpecificity],
		['chainedPanelRoots',css.chainedPanelRoots,budgets.chainedPanelRoots],
		['managedGlobalListeners',javascript.managedGlobalListeners,budgets.managedGlobalListeners],
		['unmanagedGlobalListeners',javascript.unmanagedGlobalListeners,budgets.unmanagedGlobalListeners],
		['globalFunctionDeclarations',javascript.globalFunctionDeclarations,budgets.globalFunctionDeclarations],
	].map(([name,actual,maximum])=>({name,actual,maximum,ok:actual<=maximum}));
	const expectedLayers=['dp-tokens','dp-panel','dp-accessibility'];
	for(const name of ['round','square','curly','unexpectedClosers','unterminatedComments','unterminatedStrings','declarationFunctionImbalances']){
		checks.push({name:'cssDelimiter.'+name,actual:css.delimiterIntegrity[name],maximum:0,ok:css.delimiterIntegrity[name]===0});
	}
	checks.push({name:'malformedSelectorListTails',actual:css.malformedSelectorListTails,maximum:0,ok:css.malformedSelectorListTails===0});
	checks.push({name:'emptyConditionalBlocks',actual:css.emptyConditionalBlocks,maximum:0,ok:css.emptyConditionalBlocks===0});
	checks.push({name:'cascadeLayers',actual:css.layers,expected:expectedLayers,ok:expectedLayers.every(layer=>css.layers.includes(layer))});
	checks.push({name:'cascadeLayerOrder',actual:css.declaredLayerOrder,expected:expectedLayers,ok:JSON.stringify(css.declaredLayerOrder)===JSON.stringify(expectedLayers)});
	const expectedOwners=['foundation','components','layout','presentation','navigation','responsive','themes','system','visual-system','brick-v2'];
	checks.push({name:'sourceOwners',actual:css.sourceOwners,expected:expectedOwners,ok:JSON.stringify(css.sourceOwners)===JSON.stringify(expectedOwners)});
	return checks;
}

function browserScenarioChecks(){
	const catalog=interactionRegistry.list();
	const interactionContract=RELEASE_CONTRACT.browser.reports.find(report=>report.id==='interaction');
	const expectedScenarioCount=Number(interactionContract?.total);
	const interactionSource=fs.readFileSync(path.join(__dirname,'panel_interaction_regression.js'),'utf8');
	const probeNames=Array.from(interactionSource.matchAll(/await probe\('([^']+)'/g),match=>match[1]);
	const catalogNames=catalog.map(entry=>entry.name);
	const sorted=value=>[...value].sort((left,right)=>left.localeCompare(right));
	const changedSelection=interactionRegistry.select({changedPaths:['runtime/modules/panel/Framework/Http/PanelRequest.php']});
	const unknownSelection=interactionRegistry.select({changedPaths:['runtime/modules/panel/Framework/Unknown/NewSurface.php']});
	return [
		{name:'browserScenarioCount',actual:catalog.length,expected:expectedScenarioCount,ok:Number.isInteger(expectedScenarioCount)&&catalog.length===expectedScenarioCount},
		{name:'browserScenarioProbeOwnership',actual:sorted(probeNames),expected:sorted(catalogNames),ok:JSON.stringify(sorted(probeNames))===JSON.stringify(sorted(catalogNames))},
		{name:'browserScenarioStableIdentity',actual:catalog.map(entry=>entry.id),ok:new Set(catalog.map(entry=>entry.id)).size===catalog.length&&catalog.every(entry=>entry.id&&entry.contract)},
		{name:'browserScenarioWatchOwnership',actual:catalog.map(entry=>({id:entry.id,watches:entry.watches.length})),ok:catalog.every(entry=>entry.watches.length>0)},
		{name:'browserScenarioHttpImpact',actual:changedSelection.selected.map(entry=>entry.contract),ok:changedSelection.selected.some(entry=>entry.contract==='panel.bulk.transport-contracts')&&!changedSelection.conservativeFallback},
		{name:'browserScenarioConservativeFallback',actual:{selected:unknownSelection.selected.length,available:catalog.length,unknown:unknownSelection.unknownChanged},ok:unknownSelection.conservativeFallback&&unknownSelection.selected.length===catalog.length},
	];
}

async function main(){
	if(args.help){printHelp();return;}
	if(args.selfTest){runSpecificitySelfTest();return;}
	const [cssSource,javascriptSource]=await Promise.all([
		loadAsset(args.cssFile,args.baseUrl,'panel.css',args.capabilities),
		loadAsset(args.jsFile,args.baseUrl,'panel.js',args.capabilities),
	]);
	const css=analyzeCss(cssSource);
	const javascript=analyzeJavascript(javascriptSource);
	const checks=[...enforce(css,javascript,args.budgets),...browserScenarioChecks()];
	const failures=checks.filter(check=>!check.ok);
	const report={type:'dataphyre_panel_asset_architecture_audit',generatedAt:new Date().toISOString(),ok:failures.length===0,capabilities:args.capabilities||null,budgets:args.budgets,checks,css,javascript,browserScenarios:interactionRegistry.list()};
	if(args.report){
		const reportPath=path.resolve(args.report);
		fs.mkdirSync(path.dirname(reportPath),{recursive:true});
		fs.writeFileSync(reportPath,JSON.stringify(report,null,2)+'\n');
	}
	console.log(JSON.stringify({ok:report.ok,css:{bytes:css.bytes,styleRules:css.styleRules,duplicateSelectorOccurrences:css.duplicateSelectorOccurrences,exactDuplicateRules:css.exactDuplicateRules,maxSpecificity:css.maxSpecificity,chainedPanelRoots:css.chainedPanelRoots,layers:css.layers},javascript:{bytes:javascript.bytes,functionDeclarations:javascript.functionDeclarations,globalFunctionDeclarations:javascript.globalFunctionDeclarations,moduleScoped:javascript.moduleScoped,documentListeners:javascript.documentListeners,windowListeners:javascript.windowListeners,managedGlobalListeners:javascript.managedGlobalListeners,unmanagedGlobalListeners:javascript.unmanagedGlobalListeners},failures},null,2));
	if(failures.length&&!args.reportOnly){process.exitCode=1;}
}

main().catch(error=>{console.error(error&&error.stack?error.stack:String(error));process.exitCode=1;});
