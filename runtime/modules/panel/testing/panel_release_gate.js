#!/usr/bin/env node
'use strict';

const fs=require('fs');
const os=require('os');
const path=require('path');
const crypto=require('crypto');
const {spawnSync}=require('child_process');

const cwd=process.cwd();
const scriptRoot=__dirname;
const args=parseArgs(process.argv.slice(2));

function parseArgs(argv){
	const parsed={
		artifactDir:'.tmp/panel-release-gate',
		baseUrl:process.env.DP_PANEL_BASE_URL||'',
		browser:process.env.CHROME_PATH||'',
		cssFile:'',
		coverage:'',
		coverageEngine:'exact',
		evidenceBundle:'',
		evidenceKeyFile:'',
		evidenceKeyId:'',
		evidenceReleaseDigest:'',
		evidenceRunId:'',
		evidenceSourceDigest:'',
		evidenceTtl:3600,
		help:false,
		jsFile:'',
		lanes:[],
		interactionScenarios:[],
		interactionTags:[],
		inclusiveBudgets:'',
		inclusiveCapabilities:'',
		inclusiveEvidence:'',
		inclusiveManifest:'',
		changedPaths:[],
		whySelected:false,
		minimumCoverage:100,
		php:process.env.PHP_BINARY||'php',
		report:'',
		reportOnly:false,
		requireCoverage:false,
		updateBaselines:false,
		visualAuditOnly:true,
		visualBaselineDir:'',
		visualContainerWidths:[],
		visualDirections:[],
		visualForcedColors:[],
		visualReducedMotions:[],
		visualScenarios:[],
		visualScrollSamples:[],
		visualTheme:'',
		visualThemeModes:[],
		visualViewports:[],
		visualZooms:[],
	};
	const append=(target,value)=>target.push(...String(value).split(',').map(item=>item.trim()).filter(Boolean));
	for(let index=0;index<argv.length;index++){
		const argument=String(argv[index]);
		const value=()=>{
			const inline=argument.indexOf('=');
			if(inline!==-1){return argument.slice(inline+1);}
			index++;
			return String(argv[index]||'');
		};
		if(argument==='--help'||argument==='-h'){parsed.help=true;}
		else if(argument==='--report-only'){parsed.reportOnly=true;}
		else if(argument==='--why-selected'){parsed.whySelected=true;}
		else if(argument==='--require-coverage'){parsed.requireCoverage=true;}
		else if(argument==='--visual-regression'){parsed.visualAuditOnly=false;}
		else if(argument==='--update-baselines'){parsed.updateBaselines=true;parsed.visualAuditOnly=false;}
		else if(argument==='--base-url'||argument.startsWith('--base-url=')){parsed.baseUrl=value();}
		else if(argument==='--artifact-dir'||argument.startsWith('--artifact-dir=')){parsed.artifactDir=value();}
		else if(argument==='--report'||argument.startsWith('--report=')){parsed.report=value();}
		else if(argument==='--browser'||argument.startsWith('--browser=')){parsed.browser=value();}
		else if(argument==='--css-file'||argument.startsWith('--css-file=')){parsed.cssFile=value();}
		else if(argument==='--js-file'||argument.startsWith('--js-file=')){parsed.jsFile=value();}
		else if(argument==='--php'||argument.startsWith('--php=')){parsed.php=value();}
		else if(argument==='--coverage'||argument.startsWith('--coverage=')){parsed.coverage=value();}
		else if(argument==='--coverage-engine'||argument.startsWith('--coverage-engine=')){parsed.coverageEngine=value();}
		else if(argument==='--evidence-bundle'||argument.startsWith('--evidence-bundle=')){parsed.evidenceBundle=value();}
		else if(argument==='--evidence-key-file'||argument.startsWith('--evidence-key-file=')){parsed.evidenceKeyFile=value();}
		else if(argument==='--evidence-key-id'||argument.startsWith('--evidence-key-id=')){parsed.evidenceKeyId=value();}
		else if(argument==='--evidence-release-digest'||argument.startsWith('--evidence-release-digest=')){parsed.evidenceReleaseDigest=value();}
		else if(argument==='--evidence-run-id'||argument.startsWith('--evidence-run-id=')){parsed.evidenceRunId=value();}
		else if(argument==='--evidence-source-digest'||argument.startsWith('--evidence-source-digest=')){parsed.evidenceSourceDigest=value();}
		else if(argument==='--evidence-ttl'||argument.startsWith('--evidence-ttl=')){parsed.evidenceTtl=Number(value());}
		else if(argument==='--minimum-coverage'||argument.startsWith('--minimum-coverage=')){parsed.minimumCoverage=Number(value());}
		else if(argument==='--lanes'||argument.startsWith('--lanes=')){append(parsed.lanes,value());}
		else if(argument==='--interaction-scenario'||argument.startsWith('--interaction-scenario=')){append(parsed.interactionScenarios,value());}
		else if(argument==='--interaction-tag'||argument.startsWith('--interaction-tag=')){append(parsed.interactionTags,value());}
		else if(argument==='--inclusive-manifest'||argument.startsWith('--inclusive-manifest=')){parsed.inclusiveManifest=value();}
		else if(argument==='--inclusive-capabilities'||argument.startsWith('--inclusive-capabilities=')){parsed.inclusiveCapabilities=value();}
		else if(argument==='--inclusive-evidence'||argument.startsWith('--inclusive-evidence=')){parsed.inclusiveEvidence=value();}
		else if(argument==='--inclusive-budgets'||argument.startsWith('--inclusive-budgets=')){parsed.inclusiveBudgets=value();}
		else if(argument==='--changed-path'||argument.startsWith('--changed-path=')){append(parsed.changedPaths,value());}
		else if(argument==='--visual-baseline-dir'||argument.startsWith('--visual-baseline-dir=')){parsed.visualBaselineDir=value();}
		else if(argument==='--visual-theme'||argument.startsWith('--visual-theme=')){parsed.visualTheme=value();}
		else if(argument==='--visual-scenario'||argument.startsWith('--visual-scenario=')){append(parsed.visualScenarios,value());}
		else if(argument==='--visual-viewport'||argument.startsWith('--visual-viewport=')){append(parsed.visualViewports,value());}
		else if(argument==='--visual-theme-mode'||argument.startsWith('--visual-theme-mode=')){append(parsed.visualThemeModes,value());}
		else if(argument==='--visual-direction'||argument.startsWith('--visual-direction=')){append(parsed.visualDirections,value());}
		else if(argument==='--visual-zoom'||argument.startsWith('--visual-zoom=')){append(parsed.visualZooms,value());}
		else if(argument==='--visual-reduced-motion'||argument.startsWith('--visual-reduced-motion=')){append(parsed.visualReducedMotions,value());}
		else if(argument==='--visual-forced-colors'||argument.startsWith('--visual-forced-colors=')){append(parsed.visualForcedColors,value());}
		else if(argument==='--visual-container-width'||argument.startsWith('--visual-container-width=')){append(parsed.visualContainerWidths,value());}
		else if(argument==='--visual-scroll-sample'||argument.startsWith('--visual-scroll-sample=')){append(parsed.visualScrollSamples,value());}
		else {throw new Error('Unknown argument: '+argument+'. Run with --help for usage.');}
	}
	parsed.minimumCoverage=Math.max(0,Math.min(100,Number.isFinite(parsed.minimumCoverage)?parsed.minimumCoverage:100));
	parsed.coverageEngine=String(parsed.coverageEngine||'exact').trim().toLowerCase();
	const allowedLanes=['asset','interaction','visual','coverage','inclusive'];
	parsed.lanes=parsed.lanes.length===0?allowedLanes:Array.from(new Set(parsed.lanes));
	const invalid=parsed.lanes.filter(lane=>!allowedLanes.includes(lane));
	if(invalid.length){throw new Error('Unknown release-gate lanes: '+invalid.join(', ')+'.');}
	return parsed;
}

function printHelp(){
	console.log([
		'Dataphyre Panel release quality gate',
		'',
		'Usage:',
		'  node panel_release_gate.js --base-url http://127.0.0.1:8089/debug',
		'  node panel_release_gate.js --lanes asset --css-file panel.css --js-file panel.js',
		'  node panel_release_gate.js --base-url <url> --coverage <exact-report.json> --require-coverage',
		'',
		'Core options:',
		'  --lanes <csv>                 asset, interaction, visual, coverage, inclusive.',
		'  --base-url <url>              Mounted Panel showroom URL.',
		'  --artifact-dir <path>         Lane artifacts and reports.',
		'  --report <path>               Aggregate JSON report.',
		'  --browser <path>              Chrome or Edge executable.',
		'  --css-file <path>             Local panel.css for the asset lane.',
		'  --js-file <path>              Local panel.js for the asset lane.',
		'  --report-only                 Emit failures without a non-zero exit.',
		'  --why-selected                Include browser scenario selection reasons.',
		'  --changed-path <csv>           Select browser contracts watching changed paths.',
		'  --interaction-scenario <csv>   Interaction scenario names/contracts.',
		'  --interaction-tag <csv>        Required interaction scenario tags.',
		'  --inclusive-manifest <path>     Versioned inclusive-quality matrix JSON.',
		'  --inclusive-capabilities <path> Browser/adapter capability evidence JSON.',
		'  --inclusive-evidence <path>     Artifact-backed inclusive-quality evidence JSON.',
		'  --inclusive-budgets <path>      Optional release budget overrides JSON.',
		'',
		'Attested evidence options:',
		'  --evidence-key-file <path>      Host-held HMAC key file; enables signed evidence.',
		'  --evidence-key-id <id>          Trusted key identifier, never the key material.',
		'  --evidence-source-digest <sha>   Exact prepared-package tree digest.',
		'  --evidence-release-digest <sha>  Optional release-manifest digest.',
		'  --evidence-run-id <id>           Optional host run identifier.',
		'  --evidence-ttl <seconds>         Validity from 60 to 604800 seconds.',
		'  --evidence-bundle <path>         Signed bundle path outside the artifact root.',
		'',
		'Coverage options:',
		'  --coverage <path>             Aggregated exact-line coverage JSON.',
		'  --require-coverage            Fail when no coverage report was supplied.',
		'  --coverage-engine <engine>    exact, xdebug, or phpdbg.',
		'  --minimum-coverage <percent>  Exact Panel line threshold; default 100.',
		'  --php <path>                  PHP executable used by the coverage gate.',
		'',
		'Visual options:',
		'  --visual-regression           Compare screenshots instead of audit-only mode.',
		'  --update-baselines            Explicitly update selected visual baselines.',
		'  --visual-baseline-dir <path>  Override baseline directory.',
		'  --visual-theme <name>         Theme preset.',
		'  --visual-scenario <csv>       Scenario IDs.',
		'  --visual-viewport <csv>       desktop,laptop,mobile,320,375,768,2560.',
		'  --visual-theme-mode <csv>     light,dark,system.',
		'  --visual-direction <csv>      ltr,rtl.',
		'  --visual-zoom <csv>           Device scale values.',
		'  --visual-reduced-motion <csv> reduce,no-preference.',
		'  --visual-forced-colors <csv>  active,none.',
		'  --visual-container-width <csv> Container widths in pixels.',
		'  --visual-scroll-sample <csv>  top,middle,bottom.',
	].join('\n'));
}

function ensureDirectory(directory){fs.mkdirSync(directory,{recursive:true});}
function absolute(value){return path.resolve(cwd,value);}
function tail(value,limit=12000){value=String(value||'');return value.length<=limit?value:value.slice(-limit);}
function readJson(file){
	try{return JSON.parse(fs.readFileSync(file,'utf8'));}
	catch(error){return null;}
}
function writeJsonAtomic(file,payload){
	ensureDirectory(path.dirname(file));
	const temporary=file+'.tmp-'+process.pid+'-'+Date.now();
	fs.writeFileSync(temporary,JSON.stringify(payload,null,2)+'\n');
	try{fs.renameSync(temporary,file);}
	catch(error){if(fs.existsSync(file)){fs.unlinkSync(file);}fs.renameSync(temporary,file);}
}
function sha256(value){return crypto.createHash('sha256').update(value).digest('hex');}
function addCsvOption(command,name,values){if(values.length){command.push(name,Array.from(new Set(values)).join(','));}}

function evidenceInventory(root){
	const files=[];
	const visit=(directory,prefix='')=>{
		for(const entry of fs.readdirSync(directory,{withFileTypes:true}).sort((a,b)=>a.name.localeCompare(b.name))){
			const relative=prefix?prefix+'/'+entry.name:entry.name;
			const absolutePath=path.join(directory,entry.name);
			const stat=fs.lstatSync(absolutePath);
			if(stat.isSymbolicLink()){throw new Error('Release evidence artifacts cannot contain symbolic links: '+relative+'.');}
			if(stat.isDirectory()){visit(absolutePath,relative);}
			else if(stat.isFile()){files.push(relative.replace(/\\/g,'/'));}
			if(files.length>512){throw new Error('Release evidence artifact inventory exceeds 512 files.');}
		}
	};
	visit(root);
	return files.sort();
}

function evidenceAssertions(report,fallback=1){
	for(const value of [report?.summary?.total,report?.summary?.passed,report?.assertions,Array.isArray(report?.checks)?report.checks.length:null]){
		if(Number.isInteger(value)&&value>0){return Math.min(1000000,value);}
	}
	return fallback;
}

function issueReleaseEvidence(artifactRoot,reportPath,report,lanes){
	if(!args.evidenceKeyFile){return null;}
	const contractFile=path.join(scriptRoot,'panel_release_contract.json');
	const contractDigest=sha256(fs.readFileSync(contractFile));
	const matrixDigests={};
	if(args.inclusiveManifest){
		const matrix=readJson(absolute(args.inclusiveManifest));
		if(matrix&&typeof matrix.digest==='string'&&/^[a-f0-9]{64}$/.test(matrix.digest)){matrixDigests.inclusive=matrix.digest;}
	}
	const claimFor=(id,status,execution,assertions,reportFile,capabilities)=>({
		id,status,execution,assertions,report_path:reportFile,capabilities,notes:null,
	});
	const claims=[];
	for(const lane of lanes){
		if(!lane.report_path){continue;}
		const relative=path.relative(artifactRoot,lane.report_path).replace(/\\/g,'/');
		if(relative.startsWith('../')||path.isAbsolute(relative)){throw new Error('Release evidence lane report escaped the artifact root.');}
		const execution=['interaction','visual','inclusive'].includes(lane.name)?'browser':'php';
		claims.push(claimFor(lane.name,lane.ok?'passed':'failed',execution,evidenceAssertions(lane.report,lane.ok?1:0),relative,[execution+'.'+lane.name]));
	}
	const gateRelative=path.relative(artifactRoot,reportPath).replace(/\\/g,'/');
	if(gateRelative.startsWith('../')||path.isAbsolute(gateRelative)){throw new Error('Release evidence gate report escaped the artifact root.');}
	claims.push(claimFor('release_gate',report.ok?'passed':'failed','php',evidenceAssertions(report,report.ok?1:0),gateRelative,['php.release_gate']));
	const issuedAt=Math.floor(Date.now()/1000);
	const runId=args.evidenceRunId||('run-'+issuedAt+'-'+crypto.randomBytes(12).toString('hex'));
	const context={
		source_digest:args.evidenceSourceDigest,
		contract_digest:contractDigest,
		release_digest:args.evidenceReleaseDigest||null,
		matrix_digests:matrixDigests,
		runner:{id:'panel-release-gate',version:'1',channel:process.env.CI?'ci':'local',browser:args.browser?path.basename(args.browser):null},
		environment:{node:process.version,platform:process.platform,arch:process.arch},
		capabilities:Array.from(new Set(claims.flatMap(claim=>claim.capabilities))).sort(),
	};
	const specification={artifacts:evidenceInventory(artifactRoot),context,claims,key_id:args.evidenceKeyId,issued_at:issuedAt,ttl:args.evidenceTtl,run_id:runId,strict_tree:true};
	const bundlePath=absolute(args.evidenceBundle||path.join(path.dirname(args.artifactDir),path.basename(args.artifactDir)+'-evidence.json'));
	const relativeBundle=path.relative(artifactRoot,bundlePath);
	if(relativeBundle===''||(!relativeBundle.startsWith('..'+path.sep)&&!path.isAbsolute(relativeBundle))){throw new Error('The signed evidence bundle must be outside the attested artifact root.');}
	ensureDirectory(path.dirname(bundlePath));
	const temporaryRoot=fs.mkdtempSync(path.join(os.tmpdir(),'dataphyre-panel-evidence-'));
	const issueSpec=path.join(temporaryRoot,'issue.json');
	const expectedSpec=path.join(temporaryRoot,'expected.json');
	try{
		fs.writeFileSync(issueSpec,JSON.stringify(specification));
		fs.writeFileSync(expectedSpec,JSON.stringify({source_digest:context.source_digest,contract_digest:context.contract_digest,...(context.release_digest?{release_digest:context.release_digest}:{}),matrix_digests:context.matrix_digests,run_id:runId}));
		const cli=path.join(cwd,'dev','tools','panel_release_evidence.php');
		const issued=spawnSync(args.php,[cli,'issue','--root',artifactRoot,'--spec',issueSpec,'--key-file',absolute(args.evidenceKeyFile),'--output',bundlePath],{cwd,encoding:'utf8',maxBuffer:8*1024*1024,shell:false,timeout:2*60*1000,windowsHide:true});
		if(issued.error||issued.status!==0){throw new Error('Release evidence issuance failed: '+tail(issued.stderr||issued.stdout||issued.error,4000));}
		const issueResult=JSON.parse(String(issued.stdout||'').trim());
		const verified=spawnSync(args.php,[cli,'verify','--root',artifactRoot,'--spec',expectedSpec,'--key-file',absolute(args.evidenceKeyFile),'--bundle',bundlePath,'--now',String(issuedAt)],{cwd,encoding:'utf8',maxBuffer:8*1024*1024,shell:false,timeout:2*60*1000,windowsHide:true});
		if(verified.error||verified.status!==0){throw new Error('Release evidence verification failed: '+tail(verified.stderr||verified.stdout||verified.error,4000));}
		const verifyResult=JSON.parse(String(verified.stdout||'').trim());
		if(verifyResult?.verification?.passed!==true){throw new Error('Release evidence verification did not produce a passing result.');}
		return {path:bundlePath,run_id:runId,bundle_digest:issueResult.bundle_digest,replay_key:verifyResult.verification.replay_key,artifacts:issueResult.artifacts,claims:issueResult.claims};
	}finally{fs.rmSync(temporaryRoot,{recursive:true,force:true});}
}

function executeLane(name,executable,command,options={}){
	const started=Date.now();
	const result=spawnSync(executable,command,{
		cwd,
		encoding:'utf8',
		env:{...process.env,DP_PANEL_BASE_URL:args.baseUrl},
		maxBuffer:32*1024*1024,
		shell:false,
		timeout:options.timeoutMs||30*60*1000,
		windowsHide:true,
	});
	const report=options.reportPath&&fs.existsSync(options.reportPath)?readJson(options.reportPath):null;
	const error=result.error?String(result.error.stack||result.error):null;
	const lane={
		name,
		ok:result.status===0&&!error,
		status:result.status,
		signal:result.signal||null,
		duration_ms:Date.now()-started,
		executable,
		arguments:command,
		report_path:options.reportPath||null,
		report,
		stdout_tail:tail(result.stdout),
		stderr_tail:tail(result.stderr),
		error,
	};
	if(options.captureStdout){lane._stdout=String(result.stdout||'');}
	return lane;
}

function assetLane(artifactRoot){
	const reportPath=path.join(artifactRoot,'asset-architecture.json');
	const command=[
		path.join(scriptRoot,'panel_asset_architecture_audit.js'),
		'--report',reportPath,
	];
	if(args.cssFile&&args.jsFile){
		command.push('--css-file',absolute(args.cssFile),'--js-file',absolute(args.jsFile));
	}else{
		command.push('--base-url',args.baseUrl);
	}
	return executeLane('asset',process.execPath,command,{reportPath,timeoutMs:2*60*1000});
}

function interactionLane(artifactRoot){
	const reportPath=path.join(artifactRoot,'interaction.json');
	const command=[
		path.join(scriptRoot,'panel_interaction_regression.js'),
		'--base-url',args.baseUrl,
		'--report',reportPath,
	];
	if(args.browser){command.push('--browser',args.browser);}
	for(const scenario of args.interactionScenarios){command.push('--scenario',scenario);}
	for(const tag of args.interactionTags){command.push('--tag',tag);}
	for(const changedPath of args.changedPaths){command.push('--changed-path',changedPath);}
	if(args.whySelected){command.push('--why-selected');}
	return executeLane('interaction',process.execPath,command,{reportPath,timeoutMs:30*60*1000});
}

function visualLane(artifactRoot){
	const visualRoot=path.join(artifactRoot,'visual');
	const reportPath=path.join(visualRoot,'report.json');
	const command=[
		path.join(scriptRoot,'panel_visual_regression.js'),
		'--base-url',args.baseUrl,
		'--artifact-dir',visualRoot,
		'--report',reportPath,
	];
	if(args.visualAuditOnly){command.push('--audit-only');}
	if(args.updateBaselines){command.push('--update-baselines');}
	if(args.browser){command.push('--browser',args.browser);}
	if(args.visualBaselineDir){command.push('--baseline-dir',absolute(args.visualBaselineDir));}
	if(args.visualTheme){command.push('--theme',args.visualTheme);}
	addCsvOption(command,'--scenario',args.visualScenarios);
	addCsvOption(command,'--viewport',args.visualViewports);
	addCsvOption(command,'--theme-mode',args.visualThemeModes);
	addCsvOption(command,'--direction',args.visualDirections);
	addCsvOption(command,'--zoom',args.visualZooms);
	addCsvOption(command,'--reduced-motion',args.visualReducedMotions);
	addCsvOption(command,'--forced-colors',args.visualForcedColors);
	addCsvOption(command,'--container-width',args.visualContainerWidths);
	addCsvOption(command,'--scroll-sample',args.visualScrollSamples);
	return executeLane('visual',process.execPath,command,{reportPath,timeoutMs:60*60*1000});
}

function coverageLane(artifactRoot){
	const reportPath=path.join(artifactRoot,'php-coverage.json');
	const command=[
		path.join(scriptRoot,'panel_php_coverage_gate.php'),
		'--coverage='+absolute(args.coverage),
		'--require-engine='+args.coverageEngine,
		'--minimum-percent='+args.minimumCoverage,
		'--json',
	];
	const lane=executeLane('coverage',args.php,command,{timeoutMs:2*60*1000,captureStdout:true});
	try{
		lane.report=JSON.parse(String(lane._stdout||'').trim());
		writeJsonAtomic(reportPath,lane.report);
		lane.report_path=reportPath;
	}catch(error){
		lane.ok=false;
		lane.error=lane.error||'Coverage gate did not return valid JSON: '+error.message;
	}
	delete lane._stdout;
	return lane;
}

function inclusiveLane(artifactRoot){
	const reportPath=path.join(artifactRoot,'inclusive-quality.json');
	const command=[
		path.join(cwd,'dev','tools','panel_developer.php'),
		'inclusive-quality-gate',
		'--matrix='+absolute(args.inclusiveManifest),
		'--capabilities='+absolute(args.inclusiveCapabilities),
		'--evidence='+absolute(args.inclusiveEvidence),
	];
	if(args.inclusiveBudgets){command.push('--budgets='+absolute(args.inclusiveBudgets));}
	const lane=executeLane('inclusive',args.php,command,{timeoutMs:2*60*1000,captureStdout:true});
	try{
		lane.report=JSON.parse(String(lane._stdout||'').trim());
		writeJsonAtomic(reportPath,lane.report);
		lane.report_path=reportPath;
	}catch(error){
		lane.ok=false;
		lane.error=lane.error||'Inclusive quality gate did not return valid JSON: '+error.message;
	}
	delete lane._stdout;
	return lane;
}

function skippedLane(name,reason){return{name,ok:true,skipped:true,reason};}

function validateInputs(){
	if(Boolean(args.cssFile)!==Boolean(args.jsFile)){throw new Error('--css-file and --js-file must be supplied together.');}
	if(args.cssFile&&!fs.existsSync(absolute(args.cssFile))){throw new Error('Panel CSS asset does not exist: '+absolute(args.cssFile));}
	if(args.jsFile&&!fs.existsSync(absolute(args.jsFile))){throw new Error('Panel JavaScript asset does not exist: '+absolute(args.jsFile));}
	if(args.lanes.includes('asset')&&!args.baseUrl&&!(args.cssFile&&args.jsFile)){
		throw new Error('The asset lane requires --base-url or both --css-file and --js-file.');
	}
	if(args.lanes.some(lane=>['interaction','visual'].includes(lane))&&!args.baseUrl){
		throw new Error('--base-url is required for interaction or visual lanes.');
	}
	if(args.requireCoverage&&!args.coverage){throw new Error('--require-coverage was set without --coverage.');}
	if(args.requireCoverage&&!args.lanes.includes('coverage')){throw new Error('--require-coverage requires the coverage lane.');}
	if(args.coverage&&!fs.existsSync(absolute(args.coverage))){throw new Error('Coverage report does not exist: '+absolute(args.coverage));}
	if(args.lanes.includes('inclusive')){
		for(const [name,value] of [['--inclusive-manifest',args.inclusiveManifest],['--inclusive-capabilities',args.inclusiveCapabilities],['--inclusive-evidence',args.inclusiveEvidence]]){
			if(!value){throw new Error(name+' is required for the inclusive lane.');}
			if(!fs.existsSync(absolute(value))){throw new Error(name+' file does not exist: '+absolute(value));}
		}
		if(args.inclusiveBudgets&&!fs.existsSync(absolute(args.inclusiveBudgets))){throw new Error('--inclusive-budgets file does not exist: '+absolute(args.inclusiveBudgets));}
	}
	if(args.updateBaselines&&args.visualAuditOnly){throw new Error('Baseline updates cannot run in audit-only mode.');}
	const evidenceValues=[args.evidenceBundle,args.evidenceKeyFile,args.evidenceKeyId,args.evidenceReleaseDigest,args.evidenceRunId,args.evidenceSourceDigest].filter(Boolean);
	if(evidenceValues.length>0&&!args.evidenceKeyFile){throw new Error('--evidence-key-file is required when release evidence options are supplied.');}
	if(args.evidenceKeyFile){
		if(!fs.existsSync(absolute(args.evidenceKeyFile))){throw new Error('Release evidence key file does not exist: '+absolute(args.evidenceKeyFile));}
		if(!/^[a-z][a-z0-9_.-]{0,95}$/.test(args.evidenceKeyId)){throw new Error('--evidence-key-id must be a normalized identifier.');}
		if(!/^[a-f0-9]{64}$/.test(args.evidenceSourceDigest)){throw new Error('--evidence-source-digest must be a lowercase SHA-256 digest.');}
		if(args.evidenceReleaseDigest&&!/^[a-f0-9]{64}$/.test(args.evidenceReleaseDigest)){throw new Error('--evidence-release-digest must be a lowercase SHA-256 digest.');}
		if(args.evidenceRunId&&!/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,127}$/.test(args.evidenceRunId)){throw new Error('--evidence-run-id is invalid.');}
		if(!Number.isInteger(args.evidenceTtl)||args.evidenceTtl<60||args.evidenceTtl>604800){throw new Error('--evidence-ttl must be an integer from 60 through 604800.');}
	}
}

function main(){
	if(args.help){printHelp();return 0;}
	validateInputs();
	const artifactRoot=absolute(args.artifactDir);
	const reportPath=args.report?absolute(args.report):path.join(artifactRoot,'report.json');
	ensureDirectory(artifactRoot);
	const lanes=[];
	for(const name of args.lanes){
		if(name==='coverage'&&!args.coverage){lanes.push(skippedLane(name,'coverage_not_supplied'));continue;}
		if(name==='asset'){lanes.push(assetLane(artifactRoot));}
		else if(name==='interaction'){lanes.push(interactionLane(artifactRoot));}
		else if(name==='visual'){lanes.push(visualLane(artifactRoot));}
		else if(name==='coverage'){lanes.push(coverageLane(artifactRoot));}
		else if(name==='inclusive'){lanes.push(inclusiveLane(artifactRoot));}
	}
	const executed=lanes.filter(lane=>!lane.skipped);
	const failed=executed.filter(lane=>!lane.ok);
	const report={
		type:'dataphyre_panel_release_gate',
		generated_at:new Date().toISOString(),
		base_url:args.baseUrl||null,
		ok:executed.length>0&&failed.length===0,
		report_only:args.reportOnly,
		configuration:{
			lanes:args.lanes,
			asset_source:args.cssFile&&args.jsFile?'files':(args.baseUrl?'url':null),
			changed_paths:args.changedPaths,
			interaction_scenarios:args.interactionScenarios,
			interaction_tags:args.interactionTags,
			inclusive_manifest:args.inclusiveManifest||null,
			inclusive_capabilities:args.inclusiveCapabilities||null,
			inclusive_evidence:args.inclusiveEvidence||null,
			coverage_required:args.requireCoverage,
			coverage_engine:args.coverageEngine,
			minimum_coverage:args.minimumCoverage,
			visual_mode:args.visualAuditOnly?'audit_only':(args.updateBaselines?'update_baselines':'visual_regression'),
		},
		summary:{total:lanes.length,executed:executed.length,passed:executed.length-failed.length,failed:failed.length,skipped:lanes.length-executed.length},
		lanes,
	};
	writeJsonAtomic(reportPath,report);
	const evidence=issueReleaseEvidence(artifactRoot,reportPath,report,lanes);
	console.log(JSON.stringify({report:reportPath,evidence,ok:report.ok,...report.summary}));
	return report.ok||args.reportOnly?0:1;
}

try{process.exitCode=main();}
catch(error){console.error(error&&error.stack?error.stack:String(error));process.exitCode=2;}
