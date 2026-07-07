[CmdletBinding()]
param(
	[string]$Root,
	[string]$ModuleName,
	[int]$Limit = 40,
	[switch]$AllRuntime,
	[switch]$CandidatesOnly,
	[ValidateSet('Text', 'Json')]
	[string]$Format = 'Text',
	[string]$Output
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($Root)) {
	$scriptDirectory = if ([string]::IsNullOrWhiteSpace($PSScriptRoot)) {
		Split-Path -Parent $MyInvocation.MyCommand.Path
	}
	else {
		$PSScriptRoot
	}
	$Root = (Resolve-Path (Join-Path $scriptDirectory '..\..\..')).Path
}
$Root = (Resolve-Path $Root).Path
$scanRoot = Join-Path $Root 'runtime/modules'

if (-not (Test-Path $scanRoot -PathType Container)) {
	throw "Dataphyre runtime module directory was not found: $scanRoot"
}

$php = 'php'
$localPhp = Join-Path (Split-Path -Parent $Root) '..\.local\shopiro\php\php.exe'
if (Test-Path $localPhp -PathType Leaf) {
	$php = (Resolve-Path $localPhp).Path
}

$reporter = @'
<?php
$root=$argv[1] ?? '';
$moduleFilter=$argv[2] ?? '';
$limit=max(0, (int)($argv[3] ?? 40));
$frameworkOnly=(($argv[4] ?? '1') === '1');
$candidatesOnly=(($argv[5] ?? '0') === '1');
$format=strtolower($argv[6] ?? 'text');
if($moduleFilter === '__ALL__'){
	$moduleFilter='';
}

if($root==='' || !is_dir($root)){
	fwrite(STDERR, "Runtime module directory was not found.\n");
	exit(1);
}

function normalize_path(string $path): string {
	return str_replace('\\', '/', $path);
}

function body_contains(array $tokens, int $start, int $end, string $needle): bool {
	return str_contains(body_text($tokens, $start, $end), $needle);
}

function body_text(array $tokens, int $start, int $end): string {
	$body='';
	for($i=$start; $i<=$end; $i++){
		$body.=is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
	}
	return $body;
}

function is_hot_path_excluded(string $class, string $method, string $path): bool {
	if(preg_match('~/Framework/(?:Forms/Field|Tables/Column|Rendering/|Schemas/(?:Schema|SchemaComponent)|Support/Panel(?:ActionState|LifecycleResult))\.php$~', $path)){
		return true;
	}
	if(preg_match('~/Framework/(?:Record|RepositoryQuery|TableQuery|QuerySpec|Relation|PageResult|Mutation(?:Batch)?Result|TransactionResult)\.php$~', $path)){
		return true;
	}
	if(preg_match('~/Framework/(?:Config|ConfigRepository|DialbackCatalog|DialbackEvent|PermissionRule|PermissionSet|PermissionNamer)\.php$~', $path)){
		return true;
	}
	if(preg_match('~/Framework/SubjectResolver\.php$~', $path) && preg_match('/^(permissions|roles|id|resolvePermissions|resolveRoles)$/i', $method)){
		return true;
	}
	if(preg_match('~/Framework/(?:ApiCallableBinding|Endpoint|ApiGroup)\.php$~', $path) && preg_match('/^(metadata|cacheIdentity|resolve|dispatchDefaults|beforeExecute|afterExecute|withBinding|withBindings)$/i', $method)){
		return true;
	}
	if(preg_match('~/Framework/(?:Guards/.+Guard|Auth|AuthManager)\.php$~', $path) && preg_match('/^(check|guest|id|user|context|claims|token|guard|provider|validate)$/i', $method)){
		return true;
	}
	if(preg_match('~/Framework/Components/ReactorComponent\.php$~', $path) && preg_match('/^(render|hydrate|dehydrate|clientBindings|validate|validateOnly|runLifecycle|applyModelLifecycle)$/i', $method)){
		return true;
	}
	if(preg_match('~/Framework/.+~', $path) && preg_match('/^(get|set|is|has|can|should|default|current|enabled|base|object|asset|token|id|name|email|html|changes|changed|headers|claims|claim|context|manager|instance|metadata|resolve|render|normalize|format|options|schema|fields|columns)$/i', $method)){
		return true;
	}
	return false;
}

function is_semantic_candidate(string $class, string $method, string $path): bool {
	if(is_hot_path_excluded($class, $method, $path)){
		return false;
	}
	if(str_ends_with($class, 'Result') || str_ends_with($class, 'Payload') || str_ends_with($class, 'Context')){
		return false;
	}
	if(in_array($method, ['__construct', 'toArray', 'jsonSerialize'], true)){
		return false;
	}
	if(preg_match('/^(get|set|is|has|can|should|default|current|enabled|base|object|asset|token|id|name|email|html|changes|changed|headers|claims|claim|context|manager|instance)$/i', $method)){
		return false;
	}
	return (bool)preg_match(
		'/(dispatch|execute|handle|submit|resolve|authorize|validate|create|update|delete|remove|store|save|insert|upsert|transaction|commit|rollback|flush|extend|register|bind|boot|mount|render|hydrate|dehydrate|ingest|propagate|upload|copy|move|put|send|queue|import|export|login|logout|attempt|recover|consume)/i',
		$method.' '.$class.' '.$path
	);
}

function is_kernel_delegated(string $module, string $body): bool {
	if($module === ''){
		return false;
	}
	if(preg_match('/\\\\dataphyre\\\\'.preg_quote($module, '/').'::/i', $body)){
		return true;
	}
	return (bool)preg_match('/\\\\dataphyre\\\\[A-Za-z0-9_]+::/', $body);
}

function is_framework_extension_surface(string $class, string $method, string $path): bool {
	if(preg_match('/(?:Registry|Provider|Manager|Resolver|Bootstrap|Dispatcher|Router|Broker|Factory)$/i', $class)){
		return true;
	}
	return (bool)preg_match(
		'/(extend|register|bind|boot|mount|dispatch|execute|authorize|login|logout|attempt|recover|consume|send|queue|ingest|propagate|upload|import|export|transaction|commit|rollback|save|delete|remove|store|create|update)/i',
		$method.' '.$class.' '.$path
	);
}

function audited_decision(array $row): ?array {
	$path=$row['path'];
	$method=$row['name'];
	$decision=null;
	$traceOnly=[
		'~/modules/api/Framework/ApiManager\.php$~'=>['dispatch', 'dispatchBatch', 'dispatchChain', 'authorizeCompiledRoute', 'executeCompiledRoute'],
		'~/modules/localization/Framework/LocalizationManager\.php$~'=>['saveDefinition', 'saveDefinitions', 'deleteDefinition', 'deleteDefinitions', 'sync', 'rebuildSelection'],
		'~/modules/fulltext_engine/Framework/SearchManager\.php$~'=>['createIndex', 'deleteIndex', 'sync'],
		'~/modules/async/Framework/AsyncManager\.php$~'=>['dispatch', 'batch'],
		'~/modules/scheduling/Framework/ScheduledTask\.php$~'=>['register'],
	];
	$traceAndDialback=[
		'~/modules/api/Framework/ApiManager\.php$~'=>['runLifecycleHooks', 'invokeLifecycleHook', 'authorizeWithResolver'],
		'~/modules/mailer/Framework/MailerManager\.php$~'=>['send', 'sendBatch', 'sendAsync'],
		'~/modules/panel/Framework/Packages/PanelPackageInstallPlan\.php$~'=>['apply'],
	];
	$dialbackOnly=[
		'~/modules/storage/Framework/StorageManager\.php$~'=>['applyLifecycle', 'sync', 'purgeQuarantine'],
		'~/modules/access/Framework/Auth\.php$~'=>['login', 'loginUsingId', 'attempt', 'logout'],
		'~/modules/access/Framework/OAuthClient/Provider\.php$~'=>['resolveLocalUser', 'login'],
	];
	$alreadyCovered=[
		'~/modules/panel/Framework/Core/PanelManager\.php$~'=>['dispatch', 'handle', 'submit', 'save', 'delete', 'import', 'upload'],
		'~/modules/reactor/Framework/Core/ReactorManager\.php$~'=>['dispatch', 'mount'],
		'~/modules/permission/Framework/PermissionEngine\.php$~'=>['check', 'allows', 'compile', 'flush'],
		'~/modules/sql/Framework/DB\.php$~'=>['select', 'insert', 'update', 'delete', 'statement', 'transaction'],
		'~/modules/storage/Framework/StorageManager\.php$~'=>['put', 'putFile', 'putUploadedFile', 'delete', 'copy', 'move'],
		'~/modules/localization/Framework/LocalizationManager\.php$~'=>['rebuild'],
		'~/modules/fulltext_engine/Framework/SearchManager\.php$~'=>['syncConfigured'],
	];
	$deferred=[
		'~/modules/mvc/Framework/ProviderRegistry\.php$~'=>['register', 'boot'],
		'~/modules/routing/Framework/RouteCompiler\.php$~'=>['compile', 'build'],
		'~/modules/sanitation/Framework/Sanitation\.php$~'=>['registerPreset', 'extend'],
	];
	foreach($traceAndDialback as $pattern=>$methods){
		if(preg_match($pattern, $path) && in_array($method, $methods, true)){
			return [
				'classification'=>'must_trace_and_dialback',
				'needs_trace'=>!$row['tracelog'],
				'needs_dialback'=>!$row['dialback'],
				'proposed_action'=>'add_targeted_trace_and_scoped_framework_dialback',
				'rationale'=>'Audited coarse Framework lifecycle/effect boundary; add bounded trace and scoped Framework dialback if not already covered.',
			];
		}
	}
	foreach($traceOnly as $pattern=>$methods){
		if(preg_match($pattern, $path) && in_array($method, $methods, true)){
			return [
				'classification'=>'must_trace',
				'needs_trace'=>!$row['tracelog'],
				'needs_dialback'=>false,
				'proposed_action'=>'add_targeted_trace',
				'rationale'=>'Audited coarse Framework lifecycle, mutation, integration, or external-effect boundary.',
			];
		}
	}
	foreach($dialbackOnly as $pattern=>$methods){
		if(preg_match($pattern, $path) && in_array($method, $methods, true)){
			return [
				'classification'=>'must_dialback',
				'needs_trace'=>false,
				'needs_dialback'=>!$row['dialback'],
				'proposed_action'=>'add_scoped_framework_dialback',
				'rationale'=>'Audited extension/governance boundary where application policy code may need a scoped hook.',
			];
		}
	}
	foreach($alreadyCovered as $pattern=>$methods){
		if(preg_match($pattern, $path) && in_array($method, $methods, true)){
			return [
				'classification'=>'already_covered',
				'needs_trace'=>false,
				'needs_dialback'=>false,
				'proposed_action'=>'none_existing_trace_or_event_surface',
				'rationale'=>'Audited surface is already covered by a Framework trace helper, kernel trace context, or existing storage/event hook.',
			];
		}
	}
	foreach($deferred as $pattern=>$methods){
		if(preg_match($pattern, $path) && in_array($method, $methods, true)){
			return [
				'classification'=>'deferred_review',
				'needs_trace'=>false,
				'needs_dialback'=>false,
				'proposed_action'=>'defer_not_release_blocker',
				'rationale'=>'Audited as plausible future coverage, but not a current release blocker.',
			];
		}
	}
	return $decision;
}

function classify_surface(array $row, string $body): array {
	$audited=audited_decision($row);
	if($audited!==null){
		return $audited;
	}
	if(!$row['publicish']){
		return [
			'classification'=>'private_or_protected',
			'needs_trace'=>false,
			'needs_dialback'=>false,
			'proposed_action'=>'none_non_public',
			'rationale'=>'Non-public implementation detail; this inventory focuses on public-ish Framework surfaces.',
		];
	}
	if($row['hot_path']){
		return [
			'classification'=>'intentionally_uninstrumented_hot_noisy',
			'needs_trace'=>false,
			'needs_dialback'=>false,
			'proposed_action'=>'none_hot_path_without_benchmark',
			'rationale'=>'Likely high-frequency path; do not add tracelog or dialback without benchmark evidence.',
		];
	}
	if($row['tracelog'] && $row['dialback']){
		return [
			'classification'=>'locally_instrumented_trace_and_dialback',
			'needs_trace'=>false,
			'needs_dialback'=>false,
			'proposed_action'=>'none_already_local',
			'rationale'=>'Method already contains local trace and dialback calls.',
		];
	}
	if($row['tracelog']){
		return [
			'classification'=>'locally_traced',
			'needs_trace'=>false,
			'needs_dialback'=>false,
			'proposed_action'=>'review_only_if_extension_point',
			'rationale'=>'Method already contains local trace coverage; add scoped Framework dialback only if this is a user extension boundary.',
		];
	}
	if($row['dialback']){
		return [
			'classification'=>'locally_dialbacked',
			'needs_trace'=>false,
			'needs_dialback'=>false,
			'proposed_action'=>'none_already_local_dialback',
			'rationale'=>'Method already exposes a local dialback; do not infer a trace requirement without an audited coarse-boundary decision.',
		];
	}
	if($row['kernel_delegated']){
		return [
			'classification'=>'candidate_kernel_delegated_review',
			'needs_trace'=>false,
			'needs_dialback'=>false,
			'proposed_action'=>'verify_kernel_coverage_before_local_hook',
			'rationale'=>'Method appears to delegate to a kernel/static Dataphyre surface; avoid duplicate Framework instrumentation until kernel coverage is checked.',
		];
	}
	if($row['candidate'] && $row['extension_surface']){
		return [
			'classification'=>'framework_extension_boundary_candidate',
			'needs_trace'=>false,
			'needs_dialback'=>false,
			'proposed_action'=>'triage_against_coverage_inventory',
			'rationale'=>'Heuristic discovery candidate only; not mandatory until audited as lifecycle/mutation/security/integration/extension coverage.',
		];
	}
	if($row['candidate']){
		return [
			'classification'=>'trace_candidate',
			'needs_trace'=>false,
			'needs_dialback'=>false,
			'proposed_action'=>'triage_against_coverage_inventory',
			'rationale'=>'Heuristic discovery candidate only; not mandatory until audited as a meaningful coarse boundary.',
		];
	}
	return [
		'classification'=>'intentionally_uninstrumented_low_signal',
		'needs_trace'=>false,
		'needs_dialback'=>false,
		'proposed_action'=>'none_low_signal',
		'rationale'=>'Public-ish method did not match lifecycle/mutation/security/integration/extension signals.',
	];
}

$rii=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$rows=[];
foreach($rii as $file){
	if($file->getExtension() !== 'php'){
		continue;
	}
	$path=normalize_path($file->getPathname());
	if(
		str_contains($path, '/unit_tests/') ||
		str_contains($path, '/third_party/') ||
		str_contains($path, '/src/lib/')
	){
		continue;
	}
	if($frameworkOnly && !str_contains($path, '/Framework/')){
		continue;
	}
	if($moduleFilter !== '' && !preg_match('~/modules/'.preg_quote($moduleFilter, '~').'/~i', $path)){
		continue;
	}

	$code=file_get_contents($file->getPathname());
	if($code === false){
		continue;
	}
	$tokens=token_get_all($code);
	$count=count($tokens);
	$class='';
	for($i=0; $i<$count; $i++){
		$token=$tokens[$i];
		if(is_array($token) && in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE], true)){
			$j=$i+1;
			while($j<$count && is_array($tokens[$j]) && $tokens[$j][0]===T_WHITESPACE){
				$j++;
			}
			if(is_array($tokens[$j] ?? null)){
				$class=$tokens[$j][1];
			}
			continue;
		}
		if(!is_array($token) || $token[0] !== T_FUNCTION){
			continue;
		}
		$j=$i+1;
		while(
			$j<$count &&
			is_array($tokens[$j]) &&
			in_array($tokens[$j][0], [T_WHITESPACE, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)
		){
			$j++;
		}
		if($j<$count && $tokens[$j] === '('){
			continue;
		}
		$name=is_array($tokens[$j] ?? null) ? $tokens[$j][1] : 'anonymous';
		$brace=null;
		for($k=$j; $k<$count; $k++){
			if($tokens[$k] === '{'){
				$brace=$k;
				break;
			}
			if($tokens[$k] === ';'){
				break;
			}
		}
		if($brace === null){
			continue;
		}
		$depth=0;
		$end=$brace;
		for($k=$brace; $k<$count; $k++){
			if($tokens[$k] === '{'){
				$depth++;
			}
			elseif($tokens[$k] === '}'){
				$depth--;
				if($depth === 0){
					$end=$k;
					break;
				}
			}
		}
		$visibility='';
		for($k=max(0, $i-12); $k<$i; $k++){
			if(is_array($tokens[$k]) && in_array($tokens[$k][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)){
				$visibility=token_name($tokens[$k][0]);
			}
		}
		$module='';
		if(preg_match('~/modules/([^/]+)/~', $path, $match)){
			$module=$match[1];
		}
		$publicish=($visibility==='' || $visibility==='T_PUBLIC');
		$body=body_text($tokens, $brace, $end);
		$row=[
			'path'=>$path,
			'line'=>$token[2],
			'module'=>$module,
			'class'=>$class,
			'name'=>$name,
			'visibility'=>$visibility,
			'publicish'=>$publicish,
			'tracelog'=>str_contains($body, 'tracelog('),
			'dialback'=>str_contains($body, 'dialback('),
			'hot_path'=>is_hot_path_excluded($class, $name, $path),
			'candidate'=>is_semantic_candidate($class, $name, $path),
			'kernel_delegated'=>is_kernel_delegated($module, $body),
			'extension_surface'=>is_framework_extension_surface($class, $name, $path),
		];
		$row+=classify_surface($row, $body);
		$rows[]=$row;
	}
}

$summary=[
	'functions'=>count($rows),
	'publicish'=>0,
	'publicish_missing_tracelog'=>0,
	'publicish_missing_dialback'=>0,
	'candidate_publicish'=>0,
	'candidate_missing_tracelog'=>0,
	'candidate_missing_dialback'=>0,
	'hot_path_publicish'=>0,
	'audited_internal'=>0,
	'needs_trace_review'=>0,
	'needs_dialback_review'=>0,
];
$byModule=[];
$byClassification=[];
$missing=[];
$candidateMissing=[];
$auditedActionRows=[];
foreach($rows as $row){
	$classification=$row['classification'];
	$byClassification[$classification]=($byClassification[$classification] ?? 0)+1;
	$auditedSurface=in_array($classification, ['must_trace', 'must_dialback', 'must_trace_and_dialback', 'already_covered', 'deferred_review'], true);
	if(!$row['publicish'] && !$auditedSurface){
		continue;
	}
	if(!$row['publicish'] && $auditedSurface){
		$summary['audited_internal']++;
	}
	if($row['hot_path']){
		$summary['hot_path_publicish']++;
		$byModule[$row['module']]['hot_path_publicish']=($byModule[$row['module']]['hot_path_publicish'] ?? 0)+1;
	}
	if($candidatesOnly && !$row['candidate'] && !$auditedSurface){
		continue;
	}
	$module=$row['module'];
	if($row['needs_trace']){
		$summary['needs_trace_review']++;
		$byModule[$module]['needs_trace_review']=($byModule[$module]['needs_trace_review'] ?? 0)+1;
	}
	if($row['needs_dialback']){
		$summary['needs_dialback_review']++;
		$byModule[$module]['needs_dialback_review']=($byModule[$module]['needs_dialback_review'] ?? 0)+1;
	}
	if($row['needs_trace'] || $row['needs_dialback']){
		$auditedActionRows[]=$row;
	}
	if($row['publicish']){
		$summary['publicish']++;
		$byModule[$module]['publicish']=($byModule[$module]['publicish'] ?? 0)+1;
		if($row['candidate']){
			$summary['candidate_publicish']++;
			$byModule[$module]['candidate_publicish']=($byModule[$module]['candidate_publicish'] ?? 0)+1;
		}
		if(!$row['tracelog']){
			$summary['publicish_missing_tracelog']++;
			$byModule[$module]['missing_tracelog']=($byModule[$module]['missing_tracelog'] ?? 0)+1;
			$missing[]=$row;
			if($row['candidate']){
				$summary['candidate_missing_tracelog']++;
				$byModule[$module]['candidate_missing_tracelog']=($byModule[$module]['candidate_missing_tracelog'] ?? 0)+1;
				$candidateMissing[]=$row;
			}
		}
		if(!$row['dialback']){
			$summary['publicish_missing_dialback']++;
			$byModule[$module]['missing_dialback']=($byModule[$module]['missing_dialback'] ?? 0)+1;
			if($row['candidate']){
				$summary['candidate_missing_dialback']++;
				$byModule[$module]['candidate_missing_dialback']=($byModule[$module]['candidate_missing_dialback'] ?? 0)+1;
			}
		}
	}
}
uasort($byModule, static function(array $a, array $b): int {
	return ($b['missing_tracelog'] ?? 0) <=> ($a['missing_tracelog'] ?? 0);
});

if($format === 'json'){
	$filteredRows=[];
	foreach($rows as $row){
		$auditedSurface=in_array($row['classification'], ['must_trace', 'must_dialback', 'must_trace_and_dialback', 'already_covered', 'deferred_review'], true);
		if($candidatesOnly && !$row['candidate'] && !$row['hot_path'] && !$auditedSurface){
			continue;
		}
		$filteredRows[]=$row;
	}
	echo json_encode([
		'generated_at'=>gmdate('c'),
		'scope'=>$frameworkOnly ? 'framework' : 'runtime',
		'module_filter'=>$moduleFilter !== '' ? $moduleFilter : null,
		'candidates_only'=>$candidatesOnly,
		'policy'=>[
			'blanket_coverage_required'=>false,
			'hot_path_rule'=>'Do not add tracelog or dialback to functions that can run 1000+ times per request without benchmark evidence.',
			'framework_dialback_scope'=>'New Framework-owned dialbacks use CALL_<MODULE>_FRAMEWORK_<SURFACE_OR_CONCEPT>_<ACTION>; kernel bridges may keep existing CALL_<MODULE>_<ACTION> names.',
		],
		'summary'=>$summary,
		'by_module'=>$byModule,
		'by_classification'=>$byClassification,
		'audited_action_rows'=>$auditedActionRows,
		'rows'=>$filteredRows,
	], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
	exit(0);
}

echo "Dataphyre trace/dialback candidate visibility report\n";
echo "Scope: ".($frameworkOnly ? 'Framework public API' : 'runtime public API').($moduleFilter !== '' ? " / module $moduleFilter" : '').($candidatesOnly ? ' / semantic candidates' : '')."\n";
echo "Note: this is a discovery report, not a requirement for blanket tracelog or dialback coverage.\n";
echo "Hot/noisy methods are intentionally excluded; functions that can run 1000+ times per request should stay untraced unless benchmark-proven.\n";
echo "Functions scanned: ".$summary['functions']."\n";
echo "Public-ish methods: ".$summary['publicish']."\n";
echo "Without local tracelog call: ".$summary['publicish_missing_tracelog']."\n";
echo "Without local dialback call (not inherently required): ".$summary['publicish_missing_dialback']."\n\n";
echo "Semantic candidates: ".$summary['candidate_publicish']."\n";
echo "Semantic candidates without local tracelog call: ".$summary['candidate_missing_tracelog']."\n";
echo "Semantic candidates without local dialback call (not inherently required): ".$summary['candidate_missing_dialback']."\n\n";
echo "Hot-path public-ish methods excluded from candidate coverage: ".$summary['hot_path_publicish']."\n\n";
echo "Audited internal/private surfaces included: ".$summary['audited_internal']."\n";
echo "Needs targeted trace review: ".$summary['needs_trace_review']."\n";
echo "Needs scoped Framework dialback review: ".$summary['needs_dialback_review']."\n\n";

echo "Classification inventory\n";
ksort($byClassification);
foreach($byClassification as $classification=>$count){
	echo "- ".$classification.": ".$count."\n";
}
echo "\n";

if($auditedActionRows!==[]){
	echo "Audited must-cover review queue\n";
	foreach($auditedActionRows as $row){
		$needs=[];
		if($row['needs_trace']){
			$needs[]='trace';
		}
		if($row['needs_dialback']){
			$needs[]='dialback';
		}
		echo "- ".$row['path'].":".$row['line']." ".$row['class']."::".$row['name']." [".implode('+', $needs)."] ".$row['classification']." - ".$row['proposed_action']."\n";
	}
	echo "\n";
}

echo "Top modules by candidate visibility gaps\n";
$shown=0;
foreach($byModule as $module=>$stats){
	if($shown >= 20){
		break;
	}
	echo "- ".$module.": ".($stats['missing_tracelog'] ?? 0)."/".$stats['publicish']." without local tracelog, ".($stats['missing_dialback'] ?? 0)." without local dialback";
	if(($stats['candidate_publicish'] ?? 0) > 0){
		echo ", candidates ".($stats['candidate_missing_tracelog'] ?? 0)."/".$stats['candidate_publicish']." without local tracelog";
	}
	if(($stats['hot_path_publicish'] ?? 0) > 0){
		echo ", hot excluded ".$stats['hot_path_publicish'];
	}
	if(($stats['needs_trace_review'] ?? 0) > 0 || ($stats['needs_dialback_review'] ?? 0) > 0){
		echo ", review trace ".($stats['needs_trace_review'] ?? 0).", dialback ".($stats['needs_dialback_review'] ?? 0);
	}
	echo "\n";
	$shown++;
}

if($limit > 0){
	echo "\nFirst candidate methods without local tracelog (limited to $limit)\n";
	foreach(array_slice($candidatesOnly ? $candidateMissing : $missing, 0, $limit) as $row){
		$tag=$row['candidate'] ? ' candidate' : ($row['hot_path'] ? ' hot-excluded' : '');
		echo "- ".$row['path'].":".$row['line']." ".$row['class']."::".$row['name'].$tag." [".$row['classification']."] ".$row['proposed_action']."\n";
	}
}
'@

$tempScript = Join-Path $env:TEMP ("dataphyre_trace_dialback_coverage_{0}_{1}.php" -f $PID, [Guid]::NewGuid().ToString('N'))
try {
	[System.IO.File]::WriteAllText($tempScript, $reporter, [System.Text.UTF8Encoding]::new($false))
	$frameworkOnly = if ($AllRuntime) { '0' } else { '1' }
	$moduleArgument = if ([string]::IsNullOrEmpty($ModuleName)) { '__ALL__' } else { $ModuleName }
	$candidatesArgument = if ($CandidatesOnly) { '1' } else { '0' }
	$arguments = @($tempScript, $scanRoot, $moduleArgument, $Limit, $frameworkOnly, $candidatesArgument, $Format.ToLowerInvariant())
	if ([string]::IsNullOrWhiteSpace($Output)) {
		& $php @arguments
	}
	else {
		& $php @arguments | Set-Content -LiteralPath $Output -Encoding UTF8
	}
	if ($LASTEXITCODE -ne 0) {
		exit $LASTEXITCODE
	}
}
finally {
	if (Test-Path $tempScript -PathType Leaf) {
		Remove-Item -LiteralPath $tempScript -Force
	}
}
