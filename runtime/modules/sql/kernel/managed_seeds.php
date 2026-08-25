<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Fixed managed seed dispatcher. No executable, path, ledger, cluster, or seed id is caller-selected. */
const DATAPHYRE_MANAGED_SEED_MAXIMUM_OUTPUT_BYTES=16384;
const DATAPHYRE_MANAGED_SEED_MAXIMUM_DEFINITIONS=4096;
const DATAPHYRE_MANAGED_SEED_EVIDENCE_KEY_BYTES=32;
const DATAPHYRE_MANAGED_SEED_HARD_STOP_SIGNAL=9;

/** Fixed output handler: application output can never become host evidence. */
function dataphyre_managed_seed_swallow_output(string $chunk): string
{
	return '';
}

/**
 * Installs a process-lifetime output boundary before any application file is loaded.
 *
 * The buffer is deliberately non-removable. Application code may create and close
 * nested buffers, but it cannot flush or discard the boundary that protects the
 * one-shot's canonical evidence stream. The root one-shot owns STDERR capture, so
 * application diagnostics never share the evidence stream or get forwarded raw.
 */
function dataphyre_managed_seed_install_output_boundary(): int
{
	$guardLevel=ob_get_level()+1;
	$flags=PHP_OUTPUT_HANDLER_STDFLAGS & ~PHP_OUTPUT_HANDLER_REMOVABLE;
	if(!ob_start('dataphyre_managed_seed_swallow_output',0,$flags)){
		throw new RuntimeException('Managed seed output boundary could not be installed.');
	}
	$handlers=ob_list_handlers();
	if(($handlers[$guardLevel-1] ?? null)!=='dataphyre_managed_seed_swallow_output'){
		@ob_end_clean();
		throw new RuntimeException('Managed seed output boundary could not be verified.');
	}
	return $guardLevel;
}

/** Repairs nested application buffers while retaining the non-removable boundary. */
function dataphyre_managed_seed_preserve_output_boundary(int $guardLevel): bool
{
	try{
		while(ob_get_level()>$guardLevel){
			if(!@ob_end_clean()) break;
		}
		$handlers=ob_list_handlers();
		if(ob_get_level()===$guardLevel
			&& ($handlers[$guardLevel-1] ?? null)==='dataphyre_managed_seed_swallow_output'){
			return true;
		}
	}catch(Throwable){
		// A hostile application handler cannot change the terminal failure decision.
	}
	try{
		while(ob_get_level()>0) @ob_end_clean();
		ob_start('dataphyre_managed_seed_swallow_output',0,
			PHP_OUTPUT_HANDLER_STDFLAGS & ~PHP_OUTPUT_HANDLER_REMOVABLE);
	}catch(Throwable){
		return false;
	}
	return false;
}

/** Canonicalizes evidence recursively so the same result has one byte shape. */
function dataphyre_managed_seed_canonicalize(mixed $value): mixed
{
	if(!is_array($value)) return $value;
	if(array_is_list($value)) return array_map(dataphyre_managed_seed_canonicalize(...),$value);
	ksort($value,SORT_STRING);
	foreach($value as $key=>$item) $value[$key]=dataphyre_managed_seed_canonicalize($item);
	return $value;
}

/** Consumes the one-use root evidence key before any application file is loaded. */
function dataphyre_managed_seed_read_evidence_key(): string
{
	$encoded='';
	try{
		while(strlen($encoded)<65 && !feof(STDIN)){
			$chunk=@fread(STDIN,65-strlen($encoded));
			if(!is_string($chunk) || $chunk==='') break;
			$encoded.=$chunk;
		}
		$extra=@fread(STDIN,1);
	}finally{
		try{@fclose(STDIN);}catch(Throwable){}
	}
	if(strlen($encoded)!==65 || !is_string($extra) || $extra!==''
		|| preg_match('/^[a-f0-9]{64}\n$/D',$encoded)!==1){
		throw new RuntimeException('Managed seed evidence authority is unavailable.');
	}
	$key=hex2bin(substr($encoded,0,64));
	sodium_memzero($encoded);
	if(!is_string($key) || strlen($key)!==DATAPHYRE_MANAGED_SEED_EVIDENCE_KEY_BYTES){
		throw new RuntimeException('Managed seed evidence authority is invalid.');
	}
	return $key;
}

/** Emits one authenticated, bounded, application-message-free evidence object. */
function dataphyre_managed_seed_emit(array $payload,string $evidenceKey): bool
{
	if(strlen($evidenceKey)!==DATAPHYRE_MANAGED_SEED_EVIDENCE_KEY_BYTES) return false;
	try{
		$unsigned=json_encode(
			dataphyre_managed_seed_canonicalize($payload),
			JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE,
		);
	}catch(Throwable){
		return false;
	}
	if(!is_string($unsigned) || $unsigned==='') return false;
	$payload['evidence_mac']='sha256:'.hash_hmac('sha256',$unsigned,$evidenceKey);
	try{
		$signed=json_encode(
			dataphyre_managed_seed_canonicalize($payload),
			JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE,
		);
	}catch(Throwable){return false;}
	if(!is_string($signed) || $signed==='' || strlen($signed)>DATAPHYRE_MANAGED_SEED_MAXIMUM_OUTPUT_BYTES) return false;
	$line=$signed."\n";
	try{$written=@fwrite(STDOUT,$line);}catch(Throwable){return false;}
	return is_int($written) && $written===strlen($line);
}

/** @param list<string> $keys */
function dataphyre_managed_seed_keyset_sha256(array $keys): string
{
	$normalized=[];
	foreach($keys as $key){
		if(!is_string($key) || preg_match('/^[a-z][a-z0-9._:-]{0,190}@[1-9][0-9]*$/D',$key)!==1){
			throw new RuntimeException('Managed seed inventory is invalid.');
		}
		$normalized[$key]=$key;
	}
	if(count($normalized)>DATAPHYRE_MANAGED_SEED_MAXIMUM_DEFINITIONS){
		throw new RuntimeException('Managed seed inventory exceeded its bound.');
	}
	ksort($normalized,SORT_NATURAL);
	return 'sha256:'.hash('sha256',implode("\n",array_values($normalized)));
}

/** @return list<string> */
function dataphyre_managed_seed_definition_keys(array $definitions): array
{
	$keys=[];
	foreach($definitions as $definition){
		if(!is_array($definition) || !is_string($definition['key'] ?? null)){
			throw new RuntimeException('Managed seed definition inventory is invalid.');
		}
		$key=$definition['key'];
		if(isset($keys[$key])) throw new RuntimeException('Managed seed definition inventory is duplicated.');
		$keys[$key]=$key;
	}
	if(count($keys)>DATAPHYRE_MANAGED_SEED_MAXIMUM_DEFINITIONS){
		throw new RuntimeException('Managed seed definition inventory exceeded its bound.');
	}
	ksort($keys,SORT_NATURAL);
	return array_values($keys);
}

/** @return array{active_applied_count:int,pending_count:int,drift_count:int,orphaned_count:int,inactive_applied_count:int,active_keyset_sha256:string} */
function dataphyre_managed_seed_convergence(array $status): array
{
	$active=[];$activeApplied=0;$pending=0;$drift=0;$orphaned=0;$inactiveApplied=0;
	foreach($status as $row){
		if(!is_array($row) || !is_string($row['key'] ?? null) || !is_string($row['status'] ?? null)){
			throw new RuntimeException('Managed seed status inventory is invalid.');
		}
		$key=$row['key'];$state=$row['status'];$isActive=($row['active'] ?? false)===true;
		if($isActive){
			$active[]=$key;
			if($state==='applied') $activeApplied++;
			elseif($state==='pending') $pending++;
			elseif($state==='drift') $drift++;
			else throw new RuntimeException('Managed seed active status is invalid.');
		}elseif($state==='orphaned') $orphaned++;
		elseif($state==='drift') $drift++;
		elseif($state==='applied') $inactiveApplied++;
		elseif($state!=='pending') throw new RuntimeException('Managed seed inactive status is invalid.');
	}
	return [
		'active_applied_count'=>$activeApplied,
		'pending_count'=>$pending,
		'drift_count'=>$drift,
		'orphaned_count'=>$orphaned,
		'inactive_applied_count'=>$inactiveApplied,
		'active_keyset_sha256'=>dataphyre_managed_seed_keyset_sha256($active),
	];
}

/** Applies one validated active profile and proves its complete ledger convergence. */
function dataphyre_managed_seed_apply_profile(
	\Dataphyre\Database\Seeds\SeedManager $manager,
	string $profile,
	string &$stage,
): array {
	$stage='profile';$catalog=$manager->catalog();$requested=[];$active=[];
	foreach($catalog as $definition){
		if(!is_array($definition) || !is_array($definition['profiles'] ?? null)
			|| !is_string($definition['key'] ?? null)){
			throw new RuntimeException('Managed seed catalog is invalid.');
		}
		if(in_array($profile,$definition['profiles'],true)) $requested[]=$definition['key'];
		if(($definition['active'] ?? false)===true) $active[]=$definition['key'];
	}
	if($requested===[]) throw new RuntimeException('Managed seed profile is unavailable.');
	$activeHash=dataphyre_managed_seed_keyset_sha256($active);
	$stage='precondition';$before=dataphyre_managed_seed_convergence($manager->status());
	if($before['active_applied_count']+$before['pending_count']!==count($active)
		|| $before['drift_count']!==0 || $before['orphaned_count']!==0
		|| $before['inactive_applied_count']!==0
		|| !hash_equals($activeHash,$before['active_keyset_sha256'])){
		throw new RuntimeException('Managed seed precondition failed.');
	}
	$stage='apply';$applied=$manager->apply();
	if(!is_array($applied) || !array_key_exists('batch',$applied)
		|| !is_array($applied['applied'] ?? null) || !is_int($applied['skipped'] ?? null)){
		throw new RuntimeException('Managed seed apply result is invalid.');
	}
	$appliedKeys=dataphyre_managed_seed_definition_keys($applied['applied']);$batch=$applied['batch'];
	if(($batch!==null && (!is_int($batch) || $batch<1)) || $applied['skipped']<0
		|| count($appliedKeys)+$applied['skipped']!==count($active)){
		throw new RuntimeException('Managed seed apply accounting is invalid.');
	}
	$stage='convergence';$convergence=dataphyre_managed_seed_convergence($manager->status());
	if($convergence['active_applied_count']!==count($active)
		|| $convergence['pending_count']!==0 || $convergence['drift_count']!==0
		|| $convergence['orphaned_count']!==0 || $convergence['inactive_applied_count']!==0
		|| !hash_equals($activeHash,$convergence['active_keyset_sha256'])){
		throw new RuntimeException('Managed seed convergence failed.');
	}
	return [
		'requested_profile_definition_count'=>count($requested),
		'active_definition_count'=>count($active),'active_keyset_sha256'=>$activeHash,
		'batch'=>$batch,'applied_count'=>count($appliedKeys),
		'applied_keyset_sha256'=>dataphyre_managed_seed_keyset_sha256($appliedKeys),
		'skipped_count'=>$applied['skipped'],'convergence'=>$convergence,
	];
}

/** Returns only one stable fixed-enum failure code; application context is never serialized. */
function dataphyre_managed_seed_error(string $stage): array
{
	return ['code'=>match($stage){
		'initialization'=>'seed_initialization_failed','profile'=>'seed_profile_unavailable',
		'precondition'=>'seed_precondition_failed',
		'apply'=>'seed_apply_failed','convergence'=>'seed_convergence_failed',
		default=>'seed_operation_failed',
	}];
}

/** Registers the terminal guard that turns application exit/fatal paths into evidence. */
function dataphyre_managed_seed_register_shutdown_guard(
	int $guardLevel,array $values,bool &$terminalEvidencePending,string &$evidenceKey,
): void
{
	register_shutdown_function(static function() use (&$terminalEvidencePending,$guardLevel,$values,&$evidenceKey): void {
		if(!$terminalEvidencePending) return;
		dataphyre_managed_seed_terminate($guardLevel,[
			'contract'=>'dataphyre.managed_seed_apply.v1','ok'=>false,
			'application'=>$values['application'],'environment'=>$values['environment'],
			'data_environment'=>$values['data-environment'],'profile'=>$values['profile'],
			'error'=>['code'=>'seed_process_terminated'],
		],$terminalEvidencePending,$evidenceKey);
	});
}

/** Emits terminal evidence, then waits for PID 1 to hard-stop the entire process group. */
function dataphyre_managed_seed_terminate(
	int $guardLevel,array $payload,bool &$terminalEvidencePending,string &$evidenceKey,
): never {
	dataphyre_managed_seed_preserve_output_boundary($guardLevel);
	if(!dataphyre_managed_seed_emit($payload,$evidenceKey)){
		@posix_kill(getmypid(),DATAPHYRE_MANAGED_SEED_HARD_STOP_SIGNAL);
		exit(70);
	}
	while(true) usleep(1000000);
}

/** Runs the only managed-runtime seed action: one atomic, idempotent whole-profile apply. */
function dataphyre_managed_seed_main(array $argv): int
{
	if(PHP_SAPI!=='cli' || count($argv)!==7) return 64;
	$values=[];
	foreach(array_slice($argv,1) as $argument){
		if(!is_string($argument) || preg_match('/^--([a-z-]+)=(.*)$/D',$argument,$match)!==1
			|| array_key_exists($match[1],$values)){
			return 64;
		}
		$values[$match[1]]=$match[2];
	}
	if(array_keys($values)!==[
		'project-root','application','environment','profile','data-environment','allow-demo',
	] || $values['project-root']!=='/app'
		|| preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D',$values['application'])!==1
		|| preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D',$values['environment'])!==1
		|| preg_match('/^[a-z][a-z0-9._:-]{0,63}$/D',$values['profile'])!==1
		|| preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$values['data-environment'])!==1
		|| !in_array($values['allow-demo'],['0','1'],true)){
		return 64;
	}
	if(!hash_equals($values['application'],(string)(getenv('DATAPHYRE_FRAMEWORK_APPLICATION') ?: ''))
		|| !hash_equals($values['environment'],(string)(getenv('DATAPHYRE_ENVIRONMENT') ?: ''))){
		return 78;
	}

	$projectRoot='/app';$seedRoot=$projectRoot.'/database/seeds';$bootstrap=$seedRoot.'/bootstrap.php';
	foreach([$projectRoot,$seedRoot] as $directory){
		$resolved=realpath($directory);
		if(!is_string($resolved) || !hash_equals($directory,$resolved) || is_link($directory) || !is_dir($directory)) return 66;
	}
	$resolvedBootstrap=realpath($bootstrap);
	if(!is_string($resolvedBootstrap) || !hash_equals($bootstrap,$resolvedBootstrap)
		|| is_link($bootstrap) || !is_file($bootstrap) || !is_readable($bootstrap)){
		return 66;
	}
	$seedKernel=__DIR__.'/seeds.php';$resolvedKernel=realpath($seedKernel);
	if(!is_string($resolvedKernel) || !hash_equals($seedKernel,$resolvedKernel)
		|| is_link($seedKernel) || !is_file($seedKernel) || !is_readable($seedKernel)){
		return 70;
	}
	$stage='initialization';
	$terminalEvidencePending=true;
	$guardLevel=null;
	$evidenceKey=null;
	try{
		if(!function_exists('posix_kill') || !function_exists('sodium_memzero')) return 77;
		foreach(['exec','passthru','shell_exec','system','proc_open','popen','pcntl_exec','pcntl_fork'] as $processFunction){
			if(function_exists($processFunction)) return 77;
		}
		$evidenceKey=dataphyre_managed_seed_read_evidence_key();
		$guardLevel=dataphyre_managed_seed_install_output_boundary();
		dataphyre_managed_seed_register_shutdown_guard($guardLevel,$values,$terminalEvidencePending,$evidenceKey);
		if($values['profile']==='demo' && $values['allow-demo']==='0'){
			$stage='profile';
			throw new RuntimeException('The demo seed profile requires explicit acknowledgement.');
		}
		$arguments=[
			$resolvedKernel,'apply','--app='.$values['application'],'--project-root='.$projectRoot,
			'--path='.$seedRoot,'--bootstrap='.$resolvedBootstrap,'--profile='.$values['profile'],
			'--data-environment='.$values['data-environment'],'--json',
		];
		if($values['allow-demo']==='1') $arguments[]='--allow-demo';
		require_once $resolvedKernel;
		$options=dp_sql_seed_options($arguments);
		$stage='bootstrap';
		$environment=dp_sql_seed_prepare_runtime_environment($options);
		if(strtolower((string)\Dataphyre\Database\DB::clusterDbms($environment['cluster']))!=='postgresql'){
			$stage='bootstrap';
			throw new RuntimeException('Managed seed apply requires the configured PostgreSQL cluster.');
		}
		$stage='transaction';
		$outcome=\Dataphyre\Database\DB::transaction(
			static function() use ($environment,$options,$values,&$stage): array {
				return dp_sql_seed_in_resolved_environment(
					$environment,
					static function() use ($options,$values,&$stage): array {
						return \dataphyre\sql::without_deferred_queries(
							static function() use ($options,$values,&$stage): array {
								$manager=dp_sql_seed_manager($options);
								return dataphyre_managed_seed_apply_profile($manager,$values['profile'],$stage);
							},
						);
					},
				);
			},
			$environment['cluster'],
		);
	}catch(Throwable $failure){
		if(is_int($guardLevel)) dataphyre_managed_seed_preserve_output_boundary($guardLevel);
		if(!is_int($guardLevel) || !is_string($evidenceKey)) return 70;
		dataphyre_managed_seed_terminate($guardLevel,[
			'contract'=>'dataphyre.managed_seed_apply.v1','ok'=>false,
			'application'=>$values['application'],'environment'=>$values['environment'],
			'data_environment'=>$values['data-environment'],'profile'=>$values['profile'],
			'error'=>dataphyre_managed_seed_error($stage),
		],$terminalEvidencePending,$evidenceKey);
	}

	if(!is_int($guardLevel) || !is_string($evidenceKey)) return 70;
	dataphyre_managed_seed_terminate($guardLevel,[
		'contract'=>'dataphyre.managed_seed_apply.v1','ok'=>true,
		'application'=>$values['application'],'environment'=>$values['environment'],
		'data_environment'=>$values['data-environment'],'profile'=>$values['profile'],
		'demo_acknowledged'=>$values['allow-demo']==='1','result'=>$outcome,
	],$terminalEvidencePending,$evidenceKey);
}

(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__)
	&& exit(dataphyre_managed_seed_main(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []));
