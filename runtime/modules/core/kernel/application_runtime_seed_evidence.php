<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Private root-owned boundary for the fixed managed-seed evidence stream. */

const DATAPHYRE_ONE_SHOT_SEED_MAXIMUM_CAPTURE_BYTES=32768;
const DATAPHYRE_ONE_SHOT_SEED_MAXIMUM_EVIDENCE_BYTES=16384;

/** Drains one captured seed stream without ever forwarding its bytes. */
function dataphyre_one_shot_drain_seed_stream(mixed $stream,string &$buffer,int &$total,bool &$overflow): void
{
	if(!is_resource($stream)) return;
	if(!@stream_set_blocking($stream,false)) throw new RuntimeException('Seed output stream is unavailable.');
	while(true){
		$chunk=@fread($stream,65536);
		if(!is_string($chunk) || $chunk==='') break;
		$total+=strlen($chunk);
		if($total<=DATAPHYRE_ONE_SHOT_SEED_MAXIMUM_CAPTURE_BYTES && !$overflow) $buffer.=$chunk;
		else $overflow=true;
	}
}

/** Returns whether both captured seed streams reached EOF. */
function dataphyre_one_shot_seed_streams_eof(array $pipes): bool
{
	foreach([1,2] as $index){
		$stream=$pipes[$index] ?? null;
		if(is_resource($stream) && !feof($stream)) return false;
	}
	return true;
}

/** Canonicalizes the one evidence object accepted across the root-owned pipe. */
function dataphyre_one_shot_canonicalize_seed_evidence(mixed $value): mixed
{
	if(!is_array($value)) return $value;
	if(array_is_list($value)) return array_map(dataphyre_one_shot_canonicalize_seed_evidence(...),$value);
	ksort($value,SORT_STRING);
	foreach($value as $key=>$item) $value[$key]=dataphyre_one_shot_canonicalize_seed_evidence($item);
	return $value;
}

/** Validates the fixed managed-seed result shape so application bytes cannot masquerade as evidence. */
function dataphyre_one_shot_valid_seed_result(mixed $value): bool
{
	if(!is_array($value)) return false;
	$keys=array_keys($value);$expected=[
		'active_definition_count','active_keyset_sha256','applied_count','applied_keyset_sha256',
		'batch','convergence','requested_profile_definition_count','skipped_count',
	];
	$sorted=$keys;$expectedSorted=$expected;sort($sorted,SORT_STRING);sort($expectedSorted,SORT_STRING);
	if($sorted!==$expectedSorted) return false;
	foreach(['requested_profile_definition_count','active_definition_count','applied_count','skipped_count'] as $key){
		if(!is_int($value[$key]) || $value[$key]<0 || $value[$key]>4096) return false;
	}
	if($value['active_definition_count']<1
		|| $value['requested_profile_definition_count']!==$value['active_definition_count']) return false;
	foreach(['active_keyset_sha256','applied_keyset_sha256'] as $key){
		if(!is_string($value[$key]) || preg_match('/^sha256:[a-f0-9]{64}$/D',$value[$key])!==1) return false;
	}
	if(($value['batch']===null)!==($value['applied_count']===0)
		|| ($value['batch']!==null && (!is_int($value['batch']) || $value['batch']<1))) return false;
	$convergence=$value['convergence'];
	if(!is_array($convergence)) return false;
	$convergenceKeys=array_keys($convergence);$convergenceExpected=[
		'active_applied_count','active_keyset_sha256','drift_count','inactive_applied_count','orphaned_count','pending_count',
	];
	$convergenceSorted=$convergenceKeys;$convergenceExpectedSorted=$convergenceExpected;
	sort($convergenceSorted,SORT_STRING);sort($convergenceExpectedSorted,SORT_STRING);
	if($convergenceSorted!==$convergenceExpectedSorted) return false;
	foreach(['active_applied_count','drift_count','inactive_applied_count','orphaned_count','pending_count'] as $key){
		if(!is_int($convergence[$key]) || $convergence[$key]<0 || $convergence[$key]>4096) return false;
	}
	if(!is_string($convergence['active_keyset_sha256'])
		|| preg_match('/^sha256:[a-f0-9]{64}$/D',$convergence['active_keyset_sha256'])!==1) return false;
	return $value['active_definition_count']===$convergence['active_applied_count']
		&& $value['active_keyset_sha256']===$convergence['active_keyset_sha256']
		&& $value['applied_count']+$value['skipped_count']===$value['active_definition_count']
		&& $convergence['pending_count']===0 && $convergence['drift_count']===0
		&& $convergence['orphaned_count']===0 && $convergence['inactive_applied_count']===0;
}

/**
 * Accepts exactly one canonical managed-seed evidence line after the child exits.
 * Any application byte on either stream, including warnings, is a failed contract.
 */
function dataphyre_one_shot_validate_seed_evidence(
	string $stdout,string $stderr,bool $overflow,string $evidenceKey,
	string $application,string $environment,string $dataEnvironment,string $profile,string $allowDemo,
): ?array {
	if($overflow || $stderr!=='' || $stdout==='' || strlen($stdout)>DATAPHYRE_ONE_SHOT_SEED_MAXIMUM_EVIDENCE_BYTES
		|| substr_count($stdout,"\n")!==1 || !str_ends_with($stdout,"\n") || strlen($evidenceKey)!==32) return null;
	try{$payload=json_decode(substr($stdout,0,-1),true,32,JSON_THROW_ON_ERROR);}
	catch(Throwable){return null;}
	if(!is_array($payload) || !is_string($payload['contract'] ?? null)
		|| $payload['contract']!=='dataphyre.managed_seed_apply.v1' || !is_bool($payload['ok'] ?? null)
		|| ($payload['application'] ?? null)!==$application || ($payload['environment'] ?? null)!==$environment
		|| ($payload['data_environment'] ?? null)!==$dataEnvironment || ($payload['profile'] ?? null)!==$profile){
		return null;
	}
	$ok=$payload['ok'];
	$expected=$ok
		? ['application','contract','data_environment','demo_acknowledged','environment','evidence_mac','ok','profile','result']
		: ['application','contract','data_environment','environment','error','evidence_mac','ok','profile'];
	$actual=array_keys($payload);$sorted=$actual;sort($sorted,SORT_STRING);$expectedSorted=$expected;sort($expectedSorted,SORT_STRING);
	if($sorted!==$expectedSorted || !is_string($payload['evidence_mac'])
		|| preg_match('/^sha256:[a-f0-9]{64}$/D',$payload['evidence_mac'])!==1) return null;
	$claimedMac=$payload['evidence_mac'];unset($payload['evidence_mac']);
	try{
		$unsigned=json_encode(dataphyre_one_shot_canonicalize_seed_evidence($payload),
			JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
	}catch(Throwable){return null;}
	if(!is_string($unsigned)
		|| !hash_equals($claimedMac,'sha256:'.hash_hmac('sha256',$unsigned,$evidenceKey))) return null;
	if($ok){
		if(!is_bool($payload['demo_acknowledged']) || $payload['demo_acknowledged']!==($allowDemo==='1')
			|| !dataphyre_one_shot_valid_seed_result($payload['result'])) return null;
	}else{
		$error=$payload['error'];
		if(!is_array($error) || !is_string($error['code'] ?? null)
			|| !in_array($error['code'],[
				'seed_initialization_failed','seed_profile_unavailable','seed_precondition_failed',
				'seed_apply_failed','seed_convergence_failed','seed_operation_failed','seed_process_terminated',
			],true) || array_keys($error)!==['code']) return null;
	}
	return ['line'=>$unsigned."\n",'ok'=>$ok];
}

/** Emits a root-owned generic managed-seed failure; application output is never reused. */
function dataphyre_one_shot_emit_seed_failure(string $code): void
{
	$line=json_encode([
		'contract'=>'dataphyre.managed_seed_apply.v1','error'=>['code'=>$code],'ok'=>false,
	],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
	if(strlen($line)>DATAPHYRE_ONE_SHOT_SEED_MAXIMUM_EVIDENCE_BYTES
		|| @fwrite(STDOUT,$line)!==strlen($line)) throw new RuntimeException('Managed seed root evidence write failed.');
}
