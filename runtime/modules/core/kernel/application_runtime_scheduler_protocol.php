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
	public const CONTRACT='dataphyre.scheduler_request.v1';
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
			'cloud_application'=>$identity['cloud_application'] ?? '',
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
			'contract','kind','timestamp','nonce','cloud_application','framework_application','environment',
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
			'contract','kind','timestamp','nonce','cloud_application','framework_application','environment',
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
			'contract','kind','timestamp','nonce','cloud_application','framework_application','environment',
			'release_id','generation','counter','scheduler_name','definition_sha256','budget_milliseconds',
		] || ($candidate['contract'] ?? null)!==self::CONTRACT
			|| !in_array($candidate['kind'] ?? null,['registration','callback','noop'],true)
			|| !is_int($candidate['timestamp'] ?? null) || $candidate['timestamp']<1000000000
			|| !is_string($candidate['nonce'] ?? null) || preg_match('/^[a-f0-9]{32}$/D',$candidate['nonce'])!==1
			|| !is_string($candidate['cloud_application'] ?? null)
			|| !\Dataphyre\PublicApplicationIdentifier::valid($candidate['cloud_application'])
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
