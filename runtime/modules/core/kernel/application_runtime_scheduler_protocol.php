<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once dirname(__DIR__).'/Framework/ApplicationEnvironmentIdentifier.php';
require_once dirname(__DIR__).'/Framework/PublicApplicationIdentifier.php';

/** Domain-separated, one-time scheduler requests issued only by root PID 1. */
final class DataphyreApplicationRuntimeSchedulerProtocol
{
	public const CONTRACT='dataphyre.scheduler_request.v2';
	public const MAX_TRANSPORT_BYTES=524288;
	public const MAX_REQUEST_BYTES=4096;
	public const MAX_ENVIRONMENT_ENTRIES=576;

	/** @param array<string,string> $identity @return array<string,mixed> */
	public static function issue(
		string $kind,
		array $identity,
		string $generation,
		int $counter,
		string $secretKey,
		?string $schedulerName=null,
		?string $definitionSha256=null,
		?int $budgetMilliseconds=null,
		?int $timestamp=null,
		?string $nonce=null,
	): array {
		$candidate=[
			'contract'=>self::CONTRACT,
			'kind'=>$kind,
			'timestamp'=>$timestamp ?? time(),
			'nonce'=>$nonce ?? bin2hex(random_bytes(16)),
			'deployment_application'=>$identity['deployment_application'] ?? '',
			'framework_application'=>$identity['framework_application'] ?? '',
			'environment'=>$identity['environment'] ?? '',
			'release_id'=>$identity['release_id'] ?? '',
			'generation'=>$generation,
			'counter'=>$counter,
			'scheduler_name'=>$schedulerName,
			'definition_sha256'=>$definitionSha256,
			'budget_milliseconds'=>$budgetMilliseconds,
		];
		if(!self::validUnsigned($candidate) || strlen($secretKey)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES){
			throw new InvalidArgumentException('Scheduler request inputs are invalid.');
		}
		$candidate['signature']=sodium_bin2base64(
			sodium_crypto_sign_detached(self::canonical($candidate),$secretKey),
			SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
		);
		return $candidate;
	}

	/** @param array<string,mixed> $candidate */
	public static function verify(array $candidate,string $publicKey,?int $now=null): bool
	{
		if(array_keys($candidate)!==[
			'contract','kind','timestamp','nonce','deployment_application','framework_application','environment',
			'release_id','generation','counter','scheduler_name','definition_sha256','budget_milliseconds','signature',
		] || strlen($publicKey)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES){
			return false;
		}
		$unsigned=$candidate;
		$signatureEncoded=$unsigned['signature'] ?? null;
		unset($unsigned['signature']);
		if(!is_string($signatureEncoded) || !self::validUnsigned($unsigned)
			|| abs(($now ?? time())-$unsigned['timestamp'])>30){
			return false;
		}
		try{$signature=sodium_base642bin($signatureEncoded,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,'');}
		catch(Throwable){return false;}
		return strlen($signature)===SODIUM_CRYPTO_SIGN_BYTES
			&& sodium_crypto_sign_verify_detached($signature,self::canonical($unsigned),$publicKey);
	}

	/** Rejects whitespace, duplicate JSON keys, alternate escaping, and reordered fields. */
	public static function matchesCanonicalJson(array $candidate,string $raw): bool
	{
		if($raw==='' || strlen($raw)>self::MAX_REQUEST_BYTES || array_keys($candidate)!==[
			'contract','kind','timestamp','nonce','deployment_application','framework_application','environment',
			'release_id','generation','counter','scheduler_name','definition_sha256','budget_milliseconds','signature',
		]) return false;
		try{$canonical=json_encode($candidate,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
		catch(Throwable){return false;}
		return hash_equals($canonical,$raw);
	}

	/** @param array<string,array<string,mixed>> $pending @param array<string,mixed> $candidate */
	public static function consume(array &$pending,array $candidate,string $publicKey,?int $now=null): bool
	{
		if(!self::verify($candidate,$publicKey,$now)) return false;
		$key=$candidate['kind'].':'.$candidate['counter'];
		$issued=$pending[$key] ?? null;
		if(!is_array($issued)) return false;
		$issuedJson=json_encode($issued,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
		$candidateJson=json_encode($candidate,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
		if(!hash_equals($issuedJson,$candidateJson)) return false;
		unset($pending[$key]);
		return true;
	}

	/** @param array<string,mixed> $candidate */
	private static function validUnsigned(array $candidate): bool
	{
		if(array_keys($candidate)!==[
			'contract','kind','timestamp','nonce','deployment_application','framework_application','environment',
			'release_id','generation','counter','scheduler_name','definition_sha256','budget_milliseconds',
		] || ($candidate['contract'] ?? null)!==self::CONTRACT
			|| !in_array($candidate['kind'] ?? null,['registration','callback','noop'],true)
			|| !is_int($candidate['timestamp'] ?? null) || $candidate['timestamp']<1000000000
			|| !is_string($candidate['nonce'] ?? null) || preg_match('/^[a-f0-9]{32}$/D',$candidate['nonce'])!==1
			|| !is_string($candidate['deployment_application'] ?? null)
			|| !\Dataphyre\PublicApplicationIdentifier::valid($candidate['deployment_application'])
			|| !is_string($candidate['framework_application'] ?? null)
			|| preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D',$candidate['framework_application'])!==1
			|| !is_string($candidate['environment'] ?? null)
			|| !\Dataphyre\ApplicationEnvironmentIdentifier::valid($candidate['environment'])
			|| !is_string($candidate['release_id'] ?? null)
			|| preg_match('/^dep_[a-f0-9]{40}$/D',$candidate['release_id'])!==1
			|| !is_string($candidate['generation'] ?? null)
			|| preg_match('/^gen_[a-f0-9]{32}$/D',$candidate['generation'])!==1
			|| !is_int($candidate['counter'] ?? null) || $candidate['counter']<1 || $candidate['counter']>PHP_INT_MAX){
			return false;
		}
		if($candidate['kind']==='callback'){
			return is_string($candidate['scheduler_name'] ?? null)
				&& preg_match('/^[A-Za-z0-9._-]{1,128}$/D',$candidate['scheduler_name'])===1
				&& is_string($candidate['definition_sha256'] ?? null)
				&& preg_match('/^sha256:[a-f0-9]{64}$/D',$candidate['definition_sha256'])===1
				&& is_int($candidate['budget_milliseconds'] ?? null)
				&& $candidate['budget_milliseconds']>=1 && $candidate['budget_milliseconds']<=300000;
		}
		return $candidate['scheduler_name']===null
			&& $candidate['definition_sha256']===null
			&& $candidate['budget_milliseconds']===null;
	}

	/** @param array<string,mixed> $candidate */
	private static function canonical(array $candidate): string
	{
		return json_encode($candidate,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
	}
}

/** Bounded private diagnostic transport from one scheduler CGI to its root gateway. */
final class DataphyreApplicationRuntimeSchedulerFailureDiagnostic
{
	public const LOG_CONTRACT='dataphyre.internal_scheduler_failure.v1';
	private const CHILD_CONTRACT='dataphyre.internal_scheduler_callback_failure.v1';
	private const CHILD_PREFIX="DATAPHYRE_INTERNAL_SCHEDULER_FAILURE\t";
	private const LOG_PREFIX='Dataphyre managed scheduler failure ';
	private const MAX_TRANSPORT_BYTES=1024;
	private const CHILD_PHASES=[
		'callback_boundary','application_registration','definition_verification','task_execution','task_cleanup',
	];
	private const GATEWAY_PHASES=[
		'gateway_spawn','gateway_wait','gateway_timeout','gateway_cleanup','router_exit','gateway_transport',
	];

	/** @return array{failure_phase:string,exception_class:string} */
	public static function fromThrowable(string $phase,Throwable $failure): array
	{
		return [
			'failure_phase'=>self::childPhase($phase),
			'exception_class'=>self::exceptionClass($failure),
		];
	}

	/** @return array{failure_phase:string,exception_class:string} */
	public static function childFallback(string $phase='callback_boundary'): array
	{
		return [
			'failure_phase'=>self::childPhase($phase),
			'exception_class'=>'Throwable',
		];
	}

	/** @param array{failure_phase:string,exception_class:string} $diagnostic */
	public static function encodeChild(array $diagnostic): string
	{
		$normalized=self::normalizeChild($diagnostic) ?? self::childFallback();
		$payload=[
			'contract'=>self::CHILD_CONTRACT,
			'failure_phase'=>$normalized['failure_phase'],
			'exception_class'=>$normalized['exception_class'],
		];
		$encoded=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
		if(strlen($encoded)>self::MAX_TRANSPORT_BYTES-strlen(self::CHILD_PREFIX)-1){
			throw new RuntimeException('Scheduler failure diagnostic exceeded its fixed bound.');
		}
		return self::CHILD_PREFIX.$encoded."\n";
	}

	/** @return null|array{failure_phase:string,exception_class:string} */
	public static function decodeChild(string $stderr): ?array
	{
		if($stderr==='' || strlen($stderr)>self::MAX_TRANSPORT_BYTES
			|| !str_ends_with($stderr,"\n") || str_contains(substr($stderr,0,-1),"\n")
			|| !str_starts_with($stderr,self::CHILD_PREFIX)) return null;
		$encoded=substr($stderr,strlen(self::CHILD_PREFIX),-1);
		if($encoded==='' || strlen($encoded)>self::MAX_TRANSPORT_BYTES) return null;
		try{$payload=json_decode($encoded,true,8,JSON_THROW_ON_ERROR);}catch(Throwable){return null;}
		if(!is_array($payload) || array_keys($payload)!==[
			'contract','failure_phase','exception_class',
		] || ($payload['contract'] ?? null)!==self::CHILD_CONTRACT
			|| self::CHILD_PREFIX.json_encode(
				$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR,
			)."\n"!==$stderr) return null;
		return self::normalizeChild([
			'failure_phase'=>$payload['failure_phase'],
			'exception_class'=>$payload['exception_class'],
		]);
	}

	/**
	 * Child fields are application-reported hints. Root gateway fields remain authoritative.
	 * @param null|array{failure_phase:string,exception_class:string} $child
	 * @return array{contract:string,task_name:string,failure_phase:string,failure_kind:string,exit_code:?int,gateway_exception_class:string,application_reported_phase:?string,application_reported_exception_class:?string,message:string}
	 */
	public static function logRecord(
		string $taskName,string $phase,string $kind,?int $exitCode,?Throwable $failure=null,?array $child=null,
	): array {
		if(preg_match('/^[A-Za-z0-9._-]{1,128}$/D',$taskName)!==1 || in_array($taskName,['.','..'],true)){
			$taskName='unknown';
		}
		$normalizedChild=self::normalizeChild($child);
		$phase=in_array($phase,self::GATEWAY_PHASES,true) ? $phase : 'gateway_transport';
		$kind=in_array($kind,['exception','exit','timeout','transport'],true) ? $kind : 'transport';
		$exceptionClass=$failure instanceof Throwable ? self::exceptionClass($failure) : 'Throwable';
		$message=match($kind){
			'timeout'=>'Managed scheduler callback exceeded its fixed wall-clock budget.',
			'exit'=>'Managed scheduler callback process exited unsuccessfully.',
			'transport'=>'Managed scheduler callback transport failed.',
			default=>'Managed scheduler internal boundary failed.',
		};
		$exitCode=is_int($exitCode) && $exitCode>=0 && $exitCode<=255 ? $exitCode : null;
		return [
			'contract'=>self::LOG_CONTRACT,
			'task_name'=>$taskName,
			'failure_phase'=>$phase,
			'failure_kind'=>$kind,
			'exit_code'=>$exitCode,
			'gateway_exception_class'=>$exceptionClass,
			'application_reported_phase'=>$normalizedChild['failure_phase'] ?? null,
			'application_reported_exception_class'=>$normalizedChild['exception_class'] ?? null,
			'message'=>$message,
		];
	}

	/** @param array{contract:string,task_name:string,failure_phase:string,failure_kind:string,exit_code:?int,gateway_exception_class:string,application_reported_phase:?string,application_reported_exception_class:?string,message:string} $record */
	public static function encodeLog(array $record): string
	{
		$encoded=json_encode($record,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
		if(strlen($encoded)>self::MAX_TRANSPORT_BYTES-strlen(self::LOG_PREFIX)-1){
			throw new RuntimeException('Scheduler failure log exceeded its fixed bound.');
		}
		return self::LOG_PREFIX.$encoded."\n";
	}

	/** @param mixed $candidate @return null|array{failure_phase:string,exception_class:string} */
	private static function normalizeChild(mixed $candidate): ?array
	{
		if(!is_array($candidate) || array_keys($candidate)!==[
			'failure_phase','exception_class',
		] || !is_string($candidate['failure_phase'] ?? null)
			|| !in_array($candidate['failure_phase'],self::CHILD_PHASES,true)
			|| !is_string($candidate['exception_class'] ?? null)
			|| preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D',$candidate['exception_class'])!==1){
			return null;
		}
		return $candidate;
	}

	private static function childPhase(string $phase): string
	{
		return in_array($phase,self::CHILD_PHASES,true) ? $phase : 'callback_boundary';
	}

	private static function exceptionClass(Throwable $failure): string
	{
		$class=get_class($failure);
		if(str_contains($class,"\0") || str_contains($class,'@anonymous')) return 'Throwable';
		$separator=strrpos($class,'\\');
		$short=$separator===false ? $class : substr($class,$separator+1);
		return preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D',$short)===1 ? $short : 'Throwable';
	}
}
