#!/usr/bin/env node
'use strict';

const assert=require('assert/strict');
const crypto=require('crypto');
const fs=require('fs');
const os=require('os');
const path=require('path');
const {spawnSync}=require('child_process');

const SCRIPT_ROOT=__dirname;
const DEFAULT_ROOT=path.resolve(SCRIPT_ROOT,'../../../..');
const DEFAULT_CONTRACT=path.join(SCRIPT_ROOT,'panel_release_contract.json');
const CONTRACT_MAX_BYTES=1024*1024;
const REPORT_MAX_BYTES=128*1024*1024;
const AGGREGATE_RESULTS_MAX_BYTES=16*1024;

function invariant(condition,message){if(!condition){throw new Error(message);}}
function object(value){return value!==null&&typeof value==='object'&&!Array.isArray(value);}
function sorted(values){return [...values].map(String).sort();}
function same(left,right,message){assert.deepEqual(left,right,message);}
function sha256(value){return crypto.createHash('sha256').update(value).digest('hex');}

function parseArgs(argv){
	const result={
		assetAudit:'',assetSnapshot:'',browserEvidenceRoot:'',contract:DEFAULT_CONTRACT,
		help:false,jobResults:'',mode:'source',php:process.env.PHP_BINARY||'php',pixelReport:'',
		report:'',root:DEFAULT_ROOT,selfTest:false,
	};
	for(let index=0;index<argv.length;index++){
		const argument=String(argv[index]);
		const value=()=>{
			const inline=argument.indexOf('=');
			if(inline!==-1){return argument.slice(inline+1);}
			index++;
			invariant(index<argv.length,'Missing value for '+argument+'.');
			return String(argv[index]);
		};
		if(argument==='--help'||argument==='-h'){result.help=true;}
		else if(argument==='--self-test'){result.selfTest=true;}
		else if(argument==='--mode'||argument.startsWith('--mode=')){result.mode=value();}
		else if(argument==='--root'||argument.startsWith('--root=')){result.root=value();}
		else if(argument==='--contract'||argument.startsWith('--contract=')){result.contract=value();}
		else if(argument==='--php'||argument.startsWith('--php=')){result.php=value();}
		else if(argument==='--report'||argument.startsWith('--report=')){result.report=value();}
		else if(argument==='--asset-snapshot'||argument.startsWith('--asset-snapshot=')){result.assetSnapshot=value();}
		else if(argument==='--asset-audit'||argument.startsWith('--asset-audit=')){result.assetAudit=value();}
		else if(argument==='--browser-evidence-root'||argument.startsWith('--browser-evidence-root=')){result.browserEvidenceRoot=value();}
		else if(argument==='--pixel-report'||argument.startsWith('--pixel-report=')){result.pixelReport=value();}
		else if(argument==='--job-results'||argument.startsWith('--job-results=')){result.jobResults=value();}
		else {throw new Error('Unknown argument: '+argument+'.');}
	}
	result.mode=String(result.mode||'source').trim().toLowerCase();
	invariant(['source','package','asset','browser','pixel','aggregate'].includes(result.mode),'--mode must be source, package, asset, browser, pixel, or aggregate.');
	result.root=path.resolve(result.root||DEFAULT_ROOT);
	result.contract=path.resolve(result.contract||DEFAULT_CONTRACT);
	return result;
}

function usage(){
	return [
		'Dataphyre Panel release-contract validator',
		'',
		'Usage:',
		'  node panel_release_contract.js --mode=source --php=php',
		'  node panel_release_contract.js --mode=package --root=<prepared-export> --php=php',
		'  node panel_release_contract.js --mode=asset --asset-snapshot=<snapshot.json> --asset-audit=<audit.json>',
		'  node panel_release_contract.js --mode=browser --browser-evidence-root=<artifact-directory>',
		'  node panel_release_contract.js --mode=pixel --pixel-report=<report.json>',
		'  node panel_release_contract.js --mode=aggregate --job-results=<json>',
		'  node panel_release_contract.js --self-test',
	].join('\n');
}

function skipWhitespace(state){while(state.index<state.text.length&&/\s/.test(state.text[state.index])){state.index++;}}
function scanString(state){
	invariant(state.text[state.index]==='"','Expected a JSON string at byte '+state.index+'.');
	const start=state.index++;
	while(state.index<state.text.length){
		const character=state.text[state.index++];
		if(character==='"'){
			const raw=state.text.slice(start,state.index);
			return JSON.parse(raw);
		}
		invariant(character.charCodeAt(0)>=0x20,'JSON strings cannot contain control characters.');
		if(character==='\\'){
			invariant(state.index<state.text.length,'Unterminated JSON escape.');
			const escaped=state.text[state.index++];
			invariant('"\\/bfnrtu'.includes(escaped),'Invalid JSON escape sequence.');
			if(escaped==='u'){
				const unicode=state.text.slice(state.index,state.index+4);
				invariant(/^[0-9a-f]{4}$/i.test(unicode),'Invalid JSON Unicode escape.');
				state.index+=4;
			}
		}
	}
	throw new Error('Unterminated JSON string.');
}
function scanValue(state){
	skipWhitespace(state);
	invariant(state.index<state.text.length,'Unexpected end of JSON input.');
	const character=state.text[state.index];
	if(character==='"'){scanString(state);return;}
	if(character==='{'){scanObject(state);return;}
	if(character==='['){scanArray(state);return;}
	const start=state.index;
	while(state.index<state.text.length&&!/[\s,}\]]/.test(state.text[state.index])){state.index++;}
	const primitive=state.text.slice(start,state.index);
	invariant(primitive!==''&&['true','false','null'].includes(primitive)||/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/.test(primitive),'Invalid JSON primitive at byte '+start+'.');
	JSON.parse(primitive);
}
function scanObject(state){
	state.index++;
	const keys=new Set();
	skipWhitespace(state);
	if(state.text[state.index]==='}'){state.index++;return;}
	while(state.index<state.text.length){
		skipWhitespace(state);
		const key=scanString(state);
		invariant(!keys.has(key),'Duplicate JSON object key: '+key+'.');
		keys.add(key);
		skipWhitespace(state);
		invariant(state.text[state.index++]===':','Expected a colon after JSON object key '+key+'.');
		scanValue(state);
		skipWhitespace(state);
		const delimiter=state.text[state.index++];
		if(delimiter==='}'){return;}
		invariant(delimiter===',','Expected a comma or closing brace in JSON object.');
	}
	throw new Error('Unterminated JSON object.');
}
function scanArray(state){
	state.index++;
	skipWhitespace(state);
	if(state.text[state.index]===']'){state.index++;return;}
	while(state.index<state.text.length){
		scanValue(state);
		skipWhitespace(state);
		const delimiter=state.text[state.index++];
		if(delimiter===']'){return;}
		invariant(delimiter===',','Expected a comma or closing bracket in JSON array.');
	}
	throw new Error('Unterminated JSON array.');
}
function parseJsonStrict(text,label='JSON document'){
	text=String(text);
	const state={text,index:0};
	scanValue(state);
	skipWhitespace(state);
	invariant(state.index===text.length,label+' contains trailing data.');
	return JSON.parse(text);
}
function readJson(file,maxBytes=REPORT_MAX_BYTES){
	const stat=fs.statSync(file);
	invariant(stat.isFile(),'Expected a regular JSON file: '+file+'.');
	invariant(stat.size<=maxBytes,'JSON file exceeds its byte budget: '+file+'.');
	return parseJsonStrict(fs.readFileSync(file,'utf8'),file);
}
function writeJsonAtomic(file,payload){
	fs.mkdirSync(path.dirname(file),{recursive:true});
	const temporary=file+'.tmp-'+process.pid+'-'+Date.now();
	fs.writeFileSync(temporary,JSON.stringify(payload,null,2)+'\n');
	try{fs.renameSync(temporary,file);}
	catch(error){if(fs.existsSync(file)){fs.unlinkSync(file);}fs.renameSync(temporary,file);}
}

function exactKeys(value,keys,label){
	invariant(object(value),label+' must be an object.');
	const actual=sorted(Object.keys(value));
	const expected=sorted(keys);
	same(actual,expected,label+' has unknown or missing keys.');
}
function stringList(value,label,{min=0,max=256,pattern=null}={}){
	invariant(Array.isArray(value),label+' must be an array.');
	invariant(value.length>=min&&value.length<=max,label+' has an invalid item count.');
	const seen=new Set();
	for(const item of value){
		invariant(typeof item==='string'&&item!==''&&item===item.trim(),label+' must contain non-empty trimmed strings.');
		invariant(!seen.has(item),label+' contains duplicate value '+item+'.');
		if(pattern){invariant(pattern.test(item),label+' contains invalid value '+item+'.');}
		seen.add(item);
	}
	return value;
}
function safeRelative(value,label='path'){
	invariant(typeof value==='string'&&value!==''&&value===value.trim(),label+' must be a non-empty trimmed string.');
	invariant(value.length<=512&&!value.includes('\0')&&!value.includes('\\'),label+' must use a bounded portable relative path.');
	invariant(!path.posix.isAbsolute(value)&&!path.win32.isAbsolute(value),label+' must be relative.');
	const normalized=path.posix.normalize(value);
	invariant(normalized===value&&!normalized.startsWith('../')&&normalized!=='.',label+' must not traverse its root.');
	return value;
}
function inside(root,relative){
	const resolved=path.resolve(root,...safeRelative(relative).split('/'));
	const prefix=path.resolve(root)+path.sep;
	invariant(resolved.toLowerCase().startsWith(prefix.toLowerCase()),'Path escaped its root: '+relative+'.');
	return resolved;
}

const CI_V1_JOB_KEYS=['contract_job','asset_job','browser_job','visual_job'];
const CI_V2_JOB_KEYS=[
	'contract_job','unit_job','exact_coverage_job','asset_job','browser_job','visual_job',
	'documentation_job','documentation_browser_job',
];
function ciJobKeys(contract){return contract.schema_version===1?CI_V1_JOB_KEYS:CI_V2_JOB_KEYS;}
function ciJobNames(contract){return ciJobKeys(contract).map(key=>contract.ci[key]);}

function validateContract(contract){
	exactKeys(contract,['type','schema_version','php_tested','assets','browser','packaging','ci'],'Panel release contract');
	invariant(contract.type==='dataphyre_panel_release_contract','Unsupported Panel release-contract type.');
	invariant(contract.schema_version===1||contract.schema_version===2,'Unsupported Panel release-contract schema version.');
	stringList(contract.php_tested,'php_tested',{min:2,max:8,pattern:/^8\.\d+$/});
	invariant(contract.php_tested.includes('8.2')&&contract.php_tested.includes('8.4'),'The release contract must require PHP 8.2 and 8.4 evidence.');

	exactKeys(contract.assets,['required_assets','capabilities','external_probe'],'assets');
	stringList(contract.assets.required_assets,'assets.required_assets',{min:2,max:32,pattern:/^panel-[a-z0-9-]+\.(?:css|js)$|^panel\.(?:css|js)$/});
	invariant(Array.isArray(contract.assets.capabilities)&&contract.assets.capabilities.length>0&&contract.assets.capabilities.length<=64,'assets.capabilities must be a bounded non-empty array.');
	const names=[];
	for(const [index,capability] of contract.assets.capabilities.entries()){
		const label='assets.capabilities['+index+']';
		exactKeys(capability,['name','capabilities','bundle_capabilities','delivery','separate_assets','host_asset'],label);
		invariant(typeof capability.name==='string'&&/^[a-z][a-z0-9-]{0,63}$/.test(capability.name),label+'.name is invalid.');
		names.push(capability.name);
		stringList(capability.capabilities,label+'.capabilities',{min:1,max:64,pattern:/^[a-z][a-z0-9-]{0,63}$/});
		stringList(capability.bundle_capabilities,label+'.bundle_capabilities',{min:1,max:64,pattern:/^[a-z][a-z0-9-]{0,63}$/});
		invariant(capability.capabilities.includes(capability.name),label+' must retain its declared capability.');
		for(const bundled of capability.bundle_capabilities){invariant(capability.capabilities.includes(bundled),label+' bundles undeclared capability '+bundled+'.');}
		invariant(['aggregate','aggregate_and_separate','aggregate_and_external','dependency_alias','base_runtime','separate'].includes(capability.delivery),label+'.delivery is invalid.');
		stringList(capability.separate_assets,label+'.separate_assets',{max:8,pattern:/^[a-z][a-z0-9.-]{0,127}$/});
		invariant(['forbidden','required'].includes(capability.host_asset),label+'.host_asset is invalid.');
	}
	stringList(names,'capability names',{min:1,max:64});
	invariant(names[0]==='shell'&&names.includes('data-surface')&&names.includes('widget-runtime')&&names.includes('studio-editor'),'The capability contract is missing required built-in roots.');
	exactKeys(contract.assets.external_probe,['name','asset'],'assets.external_probe');
	invariant(/^[a-z][a-z0-9-]{0,63}$/.test(contract.assets.external_probe.name),'External probe name is invalid.');
	invariant(/^[a-z][a-z0-9.-]{0,127}$/.test(contract.assets.external_probe.asset),'External probe asset is invalid.');
	invariant(!names.includes(contract.assets.external_probe.name),'External probe must not collide with a built-in capability.');

	exactKeys(contract.browser,['reports','required_media_capabilities','inclusive','committed_visual'],'browser');
	invariant(Array.isArray(contract.browser.reports)&&contract.browser.reports.length>0&&contract.browser.reports.length<=32,'browser.reports must be bounded and non-empty.');
	const reportIds=[];
	for(const [index,report] of contract.browser.reports.entries()){
		exactKeys(report,['id','path','total'],'browser.reports['+index+']');
		invariant(/^[a-z][a-z0-9_]{0,63}$/.test(report.id),'Browser report id is invalid.');
		reportIds.push(report.id);
		safeRelative(report.path,'browser report path');
		invariant(Number.isInteger(report.total)&&report.total>0&&report.total<=10000,'Browser report total is invalid.');
	}
	stringList(reportIds,'browser report ids',{min:1,max:32});
	stringList(contract.browser.required_media_capabilities,'browser.required_media_capabilities',{min:1,max:16,pattern:/^[A-Za-z][A-Za-z0-9]*$/});
	exactKeys(contract.browser.inclusive,['browser_report','release_report','automated_total','declared_manual_total'],'browser.inclusive');
	safeRelative(contract.browser.inclusive.browser_report,'inclusive browser report');
	safeRelative(contract.browser.inclusive.release_report,'inclusive release report');
	for(const key of ['automated_total','declared_manual_total']){invariant(Number.isInteger(contract.browser.inclusive[key])&&contract.browser.inclusive[key]>0,'browser.inclusive.'+key+' is invalid.');}
	exactKeys(contract.browser.committed_visual,['total'],'browser.committed_visual');
	invariant(Number.isInteger(contract.browser.committed_visual.total)&&contract.browser.committed_visual.total>0,'Committed visual total is invalid.');

	exactKeys(contract.packaging,['release_manifest','required_paths','forbidden_paths'],'packaging');
	safeRelative(contract.packaging.release_manifest,'packaging.release_manifest');
	stringList(contract.packaging.required_paths,'packaging.required_paths',{min:1,max:256});
	stringList(contract.packaging.forbidden_paths,'packaging.forbidden_paths',{min:1,max:128});
	for(const item of [...contract.packaging.required_paths,...contract.packaging.forbidden_paths]){safeRelative(item,'packaging path');}

	const ciKeys=contract.schema_version===1
		? ['workflow',...CI_V1_JOB_KEYS]
		: ['workflow',...CI_V2_JOB_KEYS,'aggregate_job','aggregate_required_jobs'];
	exactKeys(contract.ci,ciKeys,'ci');
	safeRelative(contract.ci.workflow,'ci.workflow');
	for(const key of ciJobKeys(contract)){invariant(/^[a-z][a-z0-9-]{0,63}$/.test(contract.ci[key]),'ci.'+key+' is invalid.');}
	stringList(ciJobNames(contract),'ci job names',{min:4,max:16,pattern:/^[a-z][a-z0-9-]{0,63}$/});
	if(contract.schema_version===2){
		invariant(/^[a-z][a-z0-9-]{0,63}$/.test(contract.ci.aggregate_job),'ci.aggregate_job is invalid.');
		invariant(!ciJobNames(contract).includes(contract.ci.aggregate_job),'The aggregate CI job must not depend on itself.');
		stringList(contract.ci.aggregate_required_jobs,'ci.aggregate_required_jobs',{min:8,max:16,pattern:/^[a-z][a-z0-9-]{0,63}$/});
		same(sorted(contract.ci.aggregate_required_jobs),sorted(ciJobNames(contract)),'ci.aggregate_required_jobs must exactly cover every declared evidence job.');
	}
	return contract;
}

function phpProbeCode(){
	return String.raw`
$root=(string)($argv[1] ?? '');
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',['enabled'=>['core'=>true,'http'=>true,'mvc'=>true,'panel'=>true,'permission'=>true,'routing'=>true],'disabled'=>[],'core_implicit'=>true]);
}
require_once $root.'/runtime/modules/core/kernel/autoloader.php';
\dataphyre\autoloader::register($root.'/runtime/modules');
\dataphyre\autoloader::register_framework_modules(['panel','permission']);
$class='\\Dataphyre\\Panel\\PanelAssetCapabilityManifest';
$renderer='\\Dataphyre\\Panel\\PanelRenderer';
$known=$class::knownCapabilities();
$rows=[];
$assetSummary=static function(array $manifest):array{
	$names=[];$host=[];
	foreach(['styles','scripts'] as $collection){
		foreach(($manifest[$collection] ?? []) as $asset){
			$names[]=(string)($asset['name'] ?? '');
			if(isset($asset['capability'])){$host[]=(string)$asset['capability'];}
		}
	}
	return ['asset_names'=>$names,'host_capabilities'=>$host,'missing_capabilities'=>array_values($manifest['missing_capabilities'] ?? [])];
};
foreach($known as $capability){
	$graph=$class::make([$capability]);
	$bundle=$graph->bundleCapabilities();
	$without=array_values(array_filter($bundle,static fn(string $name):bool=>$name!==$capability));
	$css=$renderer::assetContent('panel.css',$bundle);$js=$renderer::assetContent('panel.js',$bundle);
	$cssWithout=$renderer::assetContent('panel.css',$without);$jsWithout=$renderer::assetContent('panel.js',$without);
	$options=['capability_urls'=>[
		'reactor'=>['url'=>'/host/reactor.js','type'=>'script'],
		$capability=>['url'=>'/host/'.$capability.'.js','type'=>'script'],
	]];
	$manifest=$renderer::assetManifest([$capability],'capability',$options);
	$summary=$assetSummary($manifest);
	$rows[$capability]=[
		'capabilities'=>$graph->capabilities(),
		'bundle_capabilities'=>$bundle,
		'token'=>$graph->token(),
		'style_chunks'=>$graph->styleChunks(),
		'runtime_chunks'=>$graph->runtimeChunks(),
		'changes_aggregate'=>hash('sha256',(string)$css['content'])!==hash('sha256',(string)$cssWithout['content'])||hash('sha256',(string)$js['content'])!==hash('sha256',(string)$jsWithout['content']),
		'css'=>['bytes'=>strlen((string)$css['content']),'sha256'=>hash('sha256',(string)$css['content'])],
		'js'=>['bytes'=>strlen((string)$js['content']),'sha256'=>hash('sha256',(string)$js['content'])],
	]+$summary;
}
$externalName='vendor-map';
$external=$renderer::assetManifest([$externalName],'capability',['capability_urls'=>[$externalName=>['url'=>'/host/vendor-map.js','type'=>'script']]]);
echo json_encode(['type'=>'dataphyre_panel_asset_capability_probe','schema_version'=>1,'php_version'=>PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,'known_capabilities'=>$known,'capabilities'=>$rows,'external_probe'=>$assetSummary($external)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
`;
}

function runPhpProbe(root,php){
	const result=spawnSync(php,['-d','display_errors=1','-r',phpProbeCode(),root],{
		cwd:root,encoding:'utf8',maxBuffer:64*1024*1024,shell:false,timeout:120000,windowsHide:true,
	});
	invariant(!result.error,'PHP asset-capability probe could not start: '+String(result.error||''));
	invariant(result.status===0,'PHP asset-capability probe failed ('+result.status+'): '+String(result.stderr||result.stdout).slice(-12000));
	return parseJsonStrict(String(result.stdout||''),'PHP asset-capability probe');
}

function expectedAssetNames(capability){return sorted(['panel.css','panel.js',...capability.separate_assets]);}
function validateProbe(contract,probe){
	exactKeys(probe,['type','schema_version','php_version','known_capabilities','capabilities','external_probe'],'asset capability probe');
	invariant(probe.type==='dataphyre_panel_asset_capability_probe'&&probe.schema_version===1,'Unsupported asset capability probe envelope.');
	invariant(contract.php_tested.includes(probe.php_version),'PHP '+probe.php_version+' is not one of the release-contract gate versions.');
	const expectedNames=contract.assets.capabilities.map(item=>item.name);
	same(probe.known_capabilities,expectedNames,'Runtime capability order drifted from the release contract.');
	exactKeys(probe.capabilities,expectedNames,'probe.capabilities');
	for(const expected of contract.assets.capabilities){
		const row=probe.capabilities[expected.name];
		exactKeys(row,['capabilities','bundle_capabilities','token','style_chunks','runtime_chunks','changes_aggregate','css','js','asset_names','host_capabilities','missing_capabilities'],'probe capability '+expected.name);
		same(row.capabilities,expected.capabilities,'Dependency closure drifted for '+expected.name+'.');
		same(row.bundle_capabilities,expected.bundle_capabilities,'Bundle capability closure drifted for '+expected.name+'.');
		invariant(row.token===expected.bundle_capabilities.join('.'),'Non-canonical asset token for '+expected.name+'.');
		stringList(row.style_chunks,'style chunks for '+expected.name,{min:1,max:64});
		stringList(row.runtime_chunks,'runtime chunks for '+expected.name,{min:1,max:64});
		invariant(object(row.css)&&Number.isInteger(row.css.bytes)&&row.css.bytes>0&&/^[a-f0-9]{64}$/.test(row.css.sha256),'Invalid CSS evidence for '+expected.name+'.');
		invariant(object(row.js)&&Number.isInteger(row.js.bytes)&&row.js.bytes>0&&/^[a-f0-9]{64}$/.test(row.js.sha256),'Invalid JavaScript evidence for '+expected.name+'.');
		same(sorted(row.asset_names),expectedAssetNames(expected),'Asset delivery drifted for '+expected.name+'.');
		same(row.missing_capabilities,[],'Capability '+expected.name+' unexpectedly has missing browser assets.');
		const declaredDimension=expected.bundle_capabilities.includes(expected.name)&&expected.name!=='shell';
		if(declaredDimension){invariant(row.changes_aggregate===true,'Bundle capability '+expected.name+' is a no-op cache-key dimension.');}
		else {invariant(row.changes_aggregate===false,'Non-bundle capability '+expected.name+' unexpectedly changes aggregate bytes.');}
		const ownHostCount=row.host_capabilities.filter(name=>name===expected.name).length;
		if(expected.host_asset==='forbidden'){invariant(ownHostCount===0,'Built-in capability '+expected.name+' accepted a duplicate host asset.');}
		else {invariant(ownHostCount===1,'External capability '+expected.name+' did not resolve exactly one host asset.');}
	}
	exactKeys(probe.external_probe,['asset_names','host_capabilities','missing_capabilities'],'external probe');
	const external=contract.assets.external_probe;
	invariant(probe.external_probe.host_capabilities.filter(name=>name===external.name).length===1,'Truly external capability did not resolve exactly once.');
	invariant(probe.external_probe.asset_names.includes(external.asset),'External capability asset name drifted.');
	same(probe.external_probe.missing_capabilities,[],'External capability unexpectedly failed resolution.');
	return probe;
}

function extractWorkflowJob(workflow,name){
	const escaped=name.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
	const match=new RegExp('^  '+escaped+':\\r?\\n([\\s\\S]*?)(?=^  [a-zA-Z0-9_-]+:\\r?\\n|(?![\\s\\S]))','m').exec(workflow);
	invariant(match,'CI job is missing: '+name+'.');
	return match[1];
}
function validateWorkflow(contract,root){
	const workflowPath=inside(root,contract.ci.workflow);
	invariant(fs.existsSync(workflowPath),'CI workflow is missing: '+contract.ci.workflow+'.');
	const workflow=fs.readFileSync(workflowPath,'utf8');
	const contractJob=extractWorkflowJob(workflow,contract.ci.contract_job);
	for(const version of contract.php_tested){invariant(contractJob.includes('"'+version+'"'),'Panel release-contract CI matrix is missing PHP '+version+'.');}
	for(const marker of ['panel_release_contract.js','--mode=source','--mode=package','--self-test']){invariant(contractJob.includes(marker),'Panel release-contract CI job is missing '+marker+'.');}
	const assetJob=extractWorkflowJob(workflow,contract.ci.asset_job);
	for(const marker of ['panel_release_contract.js','--mode=asset','--asset-snapshot','--asset-audit']){invariant(assetJob.includes(marker),'Panel asset CI job is missing '+marker+'.');}
	const browserJob=extractWorkflowJob(workflow,contract.ci.browser_job);
	for(const marker of ['panel_release_contract.js','--mode=browser','--browser-evidence-root']){invariant(browserJob.includes(marker),'Panel browser CI job is missing '+marker+'.');}
	const visualJob=extractWorkflowJob(workflow,contract.ci.visual_job);
	for(const marker of ['panel_release_contract.js','--mode=pixel','--pixel-report']){invariant(visualJob.includes(marker),'Panel visual CI job is missing '+marker+'.');}
	if(contract.schema_version===1){return {workflow:contract.ci.workflow,jobs:ciJobNames(contract)};}

	const unitJob=extractWorkflowJob(workflow,contract.ci.unit_job);
	for(const marker of ['bin/dataphyre-test list','bin/dataphyre-test run','--owner=panel','--fail-skipped','--fail-todo']){invariant(unitJob.includes(marker),'Panel unit CI job is missing '+marker+'.');}
	const exactJob=extractWorkflowJob(workflow,contract.ci.exact_coverage_job);
	for(const marker of ['--owner=panel','--coverage-source=runtime/modules/panel/Framework,runtime/modules/panel/kernel','--coverage-closed-world','--coverage-min-percent=100','panel_php_coverage_gate.php','--minimum-percent=100']){invariant(exactJob.includes(marker),'Panel exact-coverage CI job is missing '+marker+'.');}
	const documentationJob=extractWorkflowJob(workflow,contract.ci.documentation_job);
	for(const marker of ['datadoc_documentation_portal_phpdbg_coverage.php','panel_documentation_portal_test_runner.php']){invariant(documentationJob.includes(marker),'Panel documentation CI job is missing '+marker+'.');}
	const documentationBrowserJob=extractWorkflowJob(workflow,contract.ci.documentation_browser_job);
	for(const marker of ['datadoc_docs.php','--workspace','panel_docs.php','--portal','datadoc_documentation_portal_browser_regression.js','panel_documentation_portal_browser_regression.js']){invariant(documentationBrowserJob.includes(marker),'Panel documentation browser CI job is missing '+marker+'.');}
	const aggregateJob=extractWorkflowJob(workflow,contract.ci.aggregate_job);
	for(const marker of ['if: always()','panel_release_contract.js','--mode=aggregate','--job-results']){invariant(aggregateJob.includes(marker),'Panel aggregate CI job is missing '+marker+'.');}
	for(const job of contract.ci.aggregate_required_jobs){invariant(aggregateJob.includes(job),'Panel aggregate CI job does not consume '+job+'.');}
	return {workflow:contract.ci.workflow,jobs:[...ciJobNames(contract),contract.ci.aggregate_job],aggregate_required_jobs:[...contract.ci.aggregate_required_jobs]};
}

function validateAggregateEvidence(contract,jobResults){
	invariant(contract.schema_version>=2,'Aggregate evidence requires Panel release-contract schema version 2 or newer.');
	invariant(typeof jobResults==='string'&&jobResults!==''&&jobResults===jobResults.trim(),'--job-results must be non-empty JSON.');
	invariant(Buffer.byteLength(jobResults,'utf8')<=AGGREGATE_RESULTS_MAX_BYTES,'Aggregate job results exceed their byte budget.');
	const results=parseJsonStrict(jobResults,'aggregate job results');
	exactKeys(results,contract.ci.aggregate_required_jobs,'aggregate job results');
	const rows=[];
	for(const job of contract.ci.aggregate_required_jobs){
		const status=results[job];
		invariant(typeof status==='string'&&['success','failure','cancelled','skipped'].includes(status),'Aggregate result for '+job+' is invalid.');
		invariant(status==='success','Required Panel release job '+job+' finished with '+status+'.');
		rows.push({job,status});
	}
	return {jobs:rows.length,results:rows};
}

function validateRequiredFiles(contract,root){
	const rows=[];
	for(const relative of contract.packaging.required_paths){
		const file=inside(root,relative);
		invariant(fs.existsSync(file)&&fs.statSync(file).isFile(),'Required Panel package file is missing: '+relative+'.');
		rows.push({path:relative,bytes:fs.statSync(file).size});
	}
	return rows;
}
function allFiles(root){
	const rows=[];
	const walk=(directory,prefix='')=>{
		for(const entry of fs.readdirSync(directory,{withFileTypes:true})){
			const relative=prefix?prefix+'/'+entry.name:entry.name;
			const full=path.join(directory,entry.name);
			invariant(!entry.isSymbolicLink(),'Prepared package contains a symbolic link: '+relative+'.');
			if(entry.isDirectory()){walk(full,relative);}
			else if(entry.isFile()){rows.push(relative);}
		}
	};
	walk(root);
	return sorted(rows);
}
function validatePackage(contract,root){
	validateRequiredFiles(contract,root);
	for(const relative of contract.packaging.forbidden_paths){invariant(!fs.existsSync(inside(root,relative)),'Prepared package contains forbidden path: '+relative+'.');}
	const manifestPath=inside(root,contract.packaging.release_manifest);
	invariant(fs.existsSync(manifestPath),'Prepared package release manifest is missing.');
	const manifest=readJson(manifestPath,REPORT_MAX_BYTES);
	invariant(manifest.schema==='dataphyre.public_export_manifest.v1'&&Array.isArray(manifest.files),'Prepared package release manifest has an unsupported schema.');
	const entries=new Map();
	for(const [index,entry] of manifest.files.entries()){
		invariant(object(entry),'Release manifest file entry '+index+' must be an object.');
		const relative=safeRelative(entry.path,'release manifest file path');
		invariant(!entries.has(relative),'Release manifest contains duplicate file path: '+relative+'.');
		invariant(Number.isInteger(entry.bytes)&&entry.bytes>=0,'Release manifest has invalid byte count for '+relative+'.');
		invariant(typeof entry.sha256==='string'&&/^[a-f0-9]{64}$/.test(entry.sha256),'Release manifest has invalid digest for '+relative+'.');
		entries.set(relative,entry);
	}
	const actualFiles=allFiles(root).filter(relative=>relative!==contract.packaging.release_manifest);
	same(sorted([...entries.keys()]),actualFiles,'Release manifest inventory does not exactly match the prepared package.');
	const tree=[];
	for(const relative of actualFiles){
		const bytes=fs.readFileSync(inside(root,relative));
		const entry=entries.get(relative);
		invariant(entry.bytes===bytes.length,'Release manifest byte count drifted for '+relative+'.');
		invariant(entry.sha256===sha256(bytes),'Release manifest digest drifted for '+relative+'.');
		tree.push(relative+'\t'+entry.bytes+'\t'+entry.sha256+'\n');
	}
	invariant(manifest.export_file_count===actualFiles.length+1,'Release manifest export_file_count is not exact.');
	invariant(manifest.export_tree_sha256===sha256(tree.join('')),'Release manifest tree digest is not exact.');
	return {files:actualFiles.length,tree_sha256:manifest.export_tree_sha256};
}

function validateAssetEvidence(contract,snapshotFile,auditFile){
	invariant(snapshotFile!=='','--asset-snapshot is required for asset mode.');
	const snapshot=readJson(path.resolve(snapshotFile));
	invariant(snapshot.type==='dataphyre_panel_asset_snapshot'&&snapshot.asset_mode==='full','Unsupported Panel asset snapshot envelope.');
	invariant(object(snapshot.assets),'Panel asset snapshot assets must be an object.');
	same(sorted(Object.keys(snapshot.assets)),sorted(contract.assets.required_assets),'Panel asset snapshot inventory drifted.');
	const directory=path.dirname(path.resolve(snapshotFile));
	for(const name of contract.assets.required_assets){
		const entry=snapshot.assets[name];
		invariant(object(entry)&&Number.isInteger(entry.bytes)&&entry.bytes>0&&/^[a-f0-9]{64}$/.test(entry.sha256),'Invalid snapshot entry for '+name+'.');
		const file=path.join(directory,name);
		invariant(fs.existsSync(file),'Snapshot asset is missing: '+name+'.');
		const bytes=fs.readFileSync(file);
		invariant(bytes.length===entry.bytes&&sha256(bytes)===entry.sha256,'Snapshot asset evidence drifted for '+name+'.');
	}
	let audit=null;
	if(auditFile){
		audit=readJson(path.resolve(auditFile));
		invariant(audit.type==='dataphyre_panel_asset_architecture_audit'&&audit.ok===true,'Panel asset architecture audit did not pass.');
		invariant(audit.css?.bytes===snapshot.assets['panel.css'].bytes,'Asset audit CSS bytes do not match the snapshot.');
		invariant(audit.javascript?.bytes===snapshot.assets['panel.js'].bytes,'Asset audit JavaScript bytes do not match the snapshot.');
		invariant(Array.isArray(audit.checks)&&audit.checks.every(check=>check?.ok!==false),'Asset architecture audit contains a failed check.');
	}
	return {assets:Object.keys(snapshot.assets).length,css_bytes:snapshot.assets['panel.css'].bytes,js_bytes:snapshot.assets['panel.js'].bytes,audit:Boolean(audit)};
}

function validateSummary(report,total,label){
	invariant(object(report)&&object(report.summary),label+' report is missing its summary.');
	invariant(report.summary.total===total,label+' expected '+total+' cases, received '+String(report.summary.total)+'.');
	invariant(report.summary.failed===0,label+' contains failed cases.');
	if(Object.hasOwn(report.summary,'blocked')){invariant(report.summary.blocked===0,label+' contains blocked cases.');}
}
function validateBrowserEvidence(contract,evidenceRoot){
	invariant(evidenceRoot!=='','--browser-evidence-root is required for browser mode.');
	const root=path.resolve(evidenceRoot);
	const reports={};
	for(const declaration of contract.browser.reports){
		const report=readJson(inside(root,declaration.path));
		validateSummary(report,declaration.total,declaration.id);
		reports[declaration.id]=declaration.total;
	}
	const media=readJson(inside(root,contract.browser.reports.find(item=>item.id==='reflow_media').path));
	for(const capability of contract.browser.required_media_capabilities){invariant(media.capabilities?.[capability]?.status==='supported','Browser media capability did not execute: '+capability+'.');}
	const inclusive=readJson(inside(root,contract.browser.inclusive.browser_report));
	validateSummary(inclusive,contract.browser.inclusive.automated_total,'inclusive browser');
	invariant(inclusive.summary.passed===contract.browser.inclusive.automated_total,'Inclusive browser evidence is incomplete.');
	invariant(inclusive.security?.sandbox_disabled===false&&inclusive.security?.main_frame_navigation_policy==='pre_request_same_origin_allowlist','Inclusive browser security evidence is not fail-closed.');
	invariant(Array.isArray(inclusive.evidence)&&inclusive.evidence.length===contract.browser.inclusive.automated_total,'Inclusive browser evidence row count drifted.');
	invariant(inclusive.evidence.every(row=>row.matrix_digest===inclusive.matrix?.digest),'Inclusive browser evidence is not bound to its exact matrix digest.');
	const release=readJson(inside(root,contract.browser.inclusive.release_report));
	invariant(release.passed===true,'Inclusive quality release report did not pass.');
	invariant(release.automated?.total===contract.browser.inclusive.automated_total&&release.automated?.passed===contract.browser.inclusive.automated_total&&release.automated?.missing===0,'Inclusive automated release evidence is incomplete.');
	invariant(release.declared_manual?.total===contract.browser.inclusive.declared_manual_total,'Inclusive manual declarations drifted from the contract.');
	return {reports,inclusive:{automated:contract.browser.inclusive.automated_total,declared_manual:contract.browser.inclusive.declared_manual_total}};
}
function validatePixelEvidence(contract,pixelFile){
	invariant(pixelFile!=='','--pixel-report is required for pixel mode.');
	const report=readJson(path.resolve(pixelFile));
	validateSummary(report,contract.browser.committed_visual.total,'committed visual');
	return {total:contract.browser.committed_visual.total};
}

function clone(value){return JSON.parse(JSON.stringify(value));}
function fakeProbe(contract){
	const capabilities={};
	for(const item of contract.assets.capabilities){
		const dependencyReactor=item.capabilities.includes('reactor');
		capabilities[item.name]={
			capabilities:[...item.capabilities],bundle_capabilities:[...item.bundle_capabilities],token:item.bundle_capabilities.join('.'),
			style_chunks:['core'],runtime_chunks:['kernel'],
			changes_aggregate:item.bundle_capabilities.includes(item.name)&&item.name!=='shell',
			css:{bytes:1,sha256:'a'.repeat(64)},js:{bytes:1,sha256:'b'.repeat(64)},
			asset_names:['panel.css','panel.js',...item.separate_assets],
			host_capabilities:item.host_asset==='required'?[item.name]:(dependencyReactor?['reactor']:[]),missing_capabilities:[],
		};
	}
	return {type:'dataphyre_panel_asset_capability_probe',schema_version:1,php_version:'8.4',known_capabilities:contract.assets.capabilities.map(item=>item.name),capabilities,external_probe:{asset_names:['panel.css','panel.js',contract.assets.external_probe.asset],host_capabilities:[contract.assets.external_probe.name],missing_capabilities:[]}};
}
function expectFailure(callback,pattern){assert.throws(callback,pattern);}
function selfTest(contract){
	let assertions=0;
	validateContract(contract);assertions++;
	const legacy=clone(contract);
	legacy.schema_version=1;
	legacy.ci={workflow:contract.ci.workflow,contract_job:contract.ci.contract_job,asset_job:contract.ci.asset_job,browser_job:contract.ci.browser_job,visual_job:contract.ci.visual_job};
	validateContract(legacy);assertions++;
	validateProbe(contract,fakeProbe(contract));assertions++;
	let mutated=clone(contract);mutated.extra=true;expectFailure(()=>validateContract(mutated),/unknown or missing keys/);assertions++;
	mutated=clone(contract);mutated.schema_version=3;expectFailure(()=>validateContract(mutated),/schema version/);assertions++;
	mutated=clone(contract);mutated.php_tested.push('8.4');expectFailure(()=>validateContract(mutated),/duplicate/);assertions++;
	mutated=clone(contract);mutated.assets.capabilities.push(clone(mutated.assets.capabilities[0]));expectFailure(()=>validateContract(mutated),/duplicate/);assertions++;
	mutated=clone(contract);mutated.packaging.required_paths[0]='../escape';expectFailure(()=>validateContract(mutated),/traverse|portable/);assertions++;
	mutated=clone(contract);mutated.assets.capabilities[1].bundle_capabilities.push('undeclared');expectFailure(()=>validateContract(mutated),/undeclared/);assertions++;
	mutated=clone(contract);mutated.browser.reports[0].path='/absolute.json';expectFailure(()=>validateContract(mutated),/relative/);assertions++;
	mutated=clone(contract);mutated.ci.aggregate_required_jobs.pop();expectFailure(()=>validateContract(mutated),/invalid item count|exactly cover/);assertions++;
	mutated=clone(contract);mutated.ci.aggregate_required_jobs.push(mutated.ci.aggregate_required_jobs[0]);expectFailure(()=>validateContract(mutated),/duplicate/);assertions++;
	mutated=clone(contract);mutated.ci.aggregate_job=mutated.ci.unit_job;expectFailure(()=>validateContract(mutated),/must not depend on itself/);assertions++;
	let probe=fakeProbe(contract);probe.capabilities.navigation.changes_aggregate=false;expectFailure(()=>validateProbe(contract,probe),/no-op cache-key/);assertions++;
	probe=fakeProbe(contract);probe.capabilities['data-surface'].host_capabilities=['data-surface'];expectFailure(()=>validateProbe(contract,probe),/duplicate host asset/);assertions++;
	probe=fakeProbe(contract);probe.external_probe.host_capabilities=[];expectFailure(()=>validateProbe(contract,probe),/external capability/);assertions++;
	probe=fakeProbe(contract);probe.php_version='8.3';expectFailure(()=>validateProbe(contract,probe),/not one of/);assertions++;
	expectFailure(()=>parseJsonStrict('{"a":1,"a":2}'),/Duplicate JSON object key/);assertions++;
	const passedResults=Object.fromEntries(contract.ci.aggregate_required_jobs.map(job=>[job,'success']));
	validateAggregateEvidence(contract,JSON.stringify(passedResults));assertions++;
	const failedResults=clone(passedResults);failedResults[contract.ci.unit_job]='failure';expectFailure(()=>validateAggregateEvidence(contract,JSON.stringify(failedResults)),/finished with failure/);assertions++;
	const skippedResults=clone(passedResults);skippedResults[contract.ci.browser_job]='skipped';expectFailure(()=>validateAggregateEvidence(contract,JSON.stringify(skippedResults)),/finished with skipped/);assertions++;
	const missingResults=clone(passedResults);delete missingResults[contract.ci.asset_job];expectFailure(()=>validateAggregateEvidence(contract,JSON.stringify(missingResults)),/unknown or missing keys/);assertions++;
	const extraResults={...passedResults,unexpected:'success'};expectFailure(()=>validateAggregateEvidence(contract,JSON.stringify(extraResults)),/unknown or missing keys/);assertions++;
	expectFailure(()=>validateAggregateEvidence(contract,'{"panel-unit":"success","panel-unit":"failure"}'),/Duplicate JSON object key/);assertions++;
	expectFailure(()=>validateAggregateEvidence(legacy,JSON.stringify(passedResults)),/schema version 2/);assertions++;
	expectFailure(()=>validateAggregateEvidence(contract,'x'.repeat(AGGREGATE_RESULTS_MAX_BYTES+1)),/byte budget/);assertions++;
	const temporary=fs.mkdtempSync(path.join(os.tmpdir(),'dp-panel-contract-'));
	try{
		const oversized=path.join(temporary,'oversized.json');
		const handle=fs.openSync(oversized,'w');fs.ftruncateSync(handle,CONTRACT_MAX_BYTES+1);fs.closeSync(handle);
		expectFailure(()=>readJson(oversized,CONTRACT_MAX_BYTES),/byte budget/);assertions++;
	}finally{fs.rmSync(temporary,{recursive:true,force:true});}
	return {assertions};
}

function execute(config){
	const contract=validateContract(readJson(config.contract,CONTRACT_MAX_BYTES));
	const checks=[];
	let evidence={};
	if(config.mode==='source'){
		checks.push('source_files','asset_capability_probe','ci_wiring');
		evidence.files=validateRequiredFiles(contract,config.root);
		evidence.probe=validateProbe(contract,runPhpProbe(config.root,config.php));
		evidence.ci=validateWorkflow(contract,config.root);
	}
	else if(config.mode==='package'){
		checks.push('package_inventory','release_manifest','asset_capability_probe');
		evidence.package=validatePackage(contract,config.root);
		evidence.probe=validateProbe(contract,runPhpProbe(config.root,config.php));
	}
	else if(config.mode==='asset'){
		checks.push('asset_snapshot','asset_architecture');
		evidence.asset=validateAssetEvidence(contract,config.assetSnapshot,config.assetAudit);
	}
	else if(config.mode==='browser'){
		checks.push('responsive_matrix','accessibility_matrix','inclusive_evidence');
		evidence.browser=validateBrowserEvidence(contract,config.browserEvidenceRoot);
	}
	else if(config.mode==='pixel'){
		checks.push('committed_visual_regression');
		evidence.pixel=validatePixelEvidence(contract,config.pixelReport);
	}
	else if(config.mode==='aggregate'){
		checks.push('required_job_results');
		evidence.aggregate=validateAggregateEvidence(contract,config.jobResults);
	}
	return {type:'dataphyre_panel_release_contract_validation',schema_version:1,generated_at:new Date().toISOString(),mode:config.mode,ok:true,checks,evidence};
}

function main(){
	const config=parseArgs(process.argv.slice(2));
	if(config.help){console.log(usage());return;}
	const contract=validateContract(readJson(config.contract,CONTRACT_MAX_BYTES));
	if(config.selfTest){
		const result={type:'dataphyre_panel_release_contract_self_test',ok:true,...selfTest(contract)};
		console.log(JSON.stringify(result));
		return;
	}
	let result;
	try{result=execute(config);}
	catch(error){
		result={type:'dataphyre_panel_release_contract_validation',schema_version:1,generated_at:new Date().toISOString(),mode:config.mode,ok:false,error:String(error?.message||error)};
		if(config.report){writeJsonAtomic(path.resolve(config.report),result);}
		throw error;
	}
	if(config.report){writeJsonAtomic(path.resolve(config.report),result);}
	console.log(JSON.stringify({ok:true,mode:config.mode,checks:result.checks}));
}

module.exports={
	execute,extractWorkflowJob,fakeProbe,parseArgs,parseJsonStrict,readJson,runPhpProbe,
	selfTest,validateAggregateEvidence,validateAssetEvidence,validateBrowserEvidence,validateContract,validatePackage,
	validatePixelEvidence,validateProbe,validateWorkflow,
};

if(require.main===module){
	try{main();}
	catch(error){console.error(error?.stack||String(error));process.exitCode=1;}
}
