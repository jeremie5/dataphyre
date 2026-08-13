<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Signs Reactor snapshots and request payloads with deterministic HMACs.
 *
 * Reactor signatures protect client-roundtripped component state from tampering. Payloads are
 * recursively key-sorted before JSON encoding so equivalent associative arrays produce the same
 * signature across requests.
 */
final class ReactorSigner {
	private const MIN_PRODUCTION_SECRET_BYTES=32;
	private const MAX_VERIFY_KEYS=8;
	private static ?string $ephemeralSecret=null;

	/**
	 * Signs a Reactor payload.
	 *
	 * @param array<string, mixed> $payload Payload to canonicalize and sign.
	 * @return string Hex SHA-256 HMAC signature.
	 */
	public static function sign(array $payload): string {
		$keyring=self::keyring();
		return hash_hmac('sha256', self::canonical($payload), $keyring['keys'][$keyring['current']]);
	}

	/**
	 * Verifies a Reactor payload signature.
	 *
	 * Empty signatures are accepted only when debug unsigned payloads are allowed and the runtime
	 * is not production.
	 *
	 * @param array<string, mixed> $payload Payload to canonicalize and verify.
	 * @param string $signature Hex HMAC signature supplied by the client.
	 * @return bool Whether the signature is valid or unsigned debug mode permits it.
	 */
	public static function verify(array $payload, string $signature): bool {
		if($signature===''){
			return self::unsignedAllowed();
		}
		if(strlen($signature)!==64 || preg_match('/^[a-f0-9]{64}$/D', $signature)!==1){
			return false;
		}
		try{
			$canonical=self::canonical($payload);
			$keyring=self::keyring();
		}
		catch(\Throwable){
			return false;
		}
		$valid=false;
		foreach($keyring['keys'] as $secret){
			$valid=hash_equals(hash_hmac('sha256', $canonical, $secret), $signature) || $valid;
		}
		return $valid;
	}

	/**
	 * Creates a keyed, domain-separated scope fingerprint.
	 *
	 * Host identifiers are frequently low entropy, so an ordinary SHA-256 digest
	 * would be dictionary-recoverable once serialized to a browser. Scope tags use
	 * the current Reactor signing key and a distinct domain prefix instead.
	 *
	 * @param array<string,string> $scopeClaims Canonical trusted host claims.
	 */
	public static function scopeFingerprint(array $scopeClaims): string {
		$keyring=self::keyring();
		return hash_hmac('sha256', "reactor-scope-v1\0".self::canonical($scopeClaims), $keyring['keys'][$keyring['current']]);
	}

	/** Verifies a scope fingerprint against current and retained rotation keys. */
	public static function verifyScopeFingerprint(array $scopeClaims, string $fingerprint): bool {
		if(strlen($fingerprint)!==64 || preg_match('/^[a-f0-9]{64}$/D', $fingerprint)!==1){ return false; }
		try{
			$canonical="reactor-scope-v1\0".self::canonical($scopeClaims);
			$keyring=self::keyring();
		}
		catch(\Throwable){ return false; }
		$valid=false;
		foreach($keyring['keys'] as $secret){
			$valid=hash_equals(hash_hmac('sha256', $canonical, $secret), $fingerprint) || $valid;
		}
		return $valid;
	}

	/**
	 * Returns a secret-free signing capability manifest for diagnostics.
	 *
	 * @return array<string,mixed>
	 */
	public static function manifest(): array {
		try{
			$keyring=self::keyring();
			return [
				'type'=>'reactor_signer',
				'schema_version'=>1,
				'algorithm'=>'hmac-sha256',
				'configured'=>$keyring['source']!=='process_ephemeral',
				'ready'=>true,
				'production'=>self::production(),
				'source'=>$keyring['source'],
				'current_key_id'=>$keyring['current'],
				'key_ids'=>array_keys($keyring['keys']),
				'key_count'=>count($keyring['keys']),
				'minimum_production_secret_bytes'=>self::MIN_PRODUCTION_SECRET_BYTES,
				'strong_secrets'=>$keyring['strong'],
				'ephemeral_process_local'=>$keyring['source']==='process_ephemeral',
				'unsigned_debug_payloads'=>self::unsignedAllowed(),
				'secrets_serialized'=>false,
			];
		}
		catch(\Throwable $error){
			return [
				'type'=>'reactor_signer',
				'schema_version'=>1,
				'algorithm'=>'hmac-sha256',
				'configured'=>false,
				'ready'=>false,
				'production'=>self::production(),
				'source'=>'unavailable',
				'current_key_id'=>null,
				'key_ids'=>[],
				'key_count'=>0,
				'minimum_production_secret_bytes'=>self::MIN_PRODUCTION_SECRET_BYTES,
				'strong_secrets'=>false,
				'ephemeral_process_local'=>false,
				'unsigned_debug_payloads'=>false,
				'secrets_serialized'=>false,
				'error'=>'signing_configuration_unavailable',
			];
		}
	}

	/**
	 * Reports whether unsigned payloads are allowed for local debug requests.
	 *
	 * @return bool `true` only when debug config allows unsigned payloads outside production.
	 */
	private static function unsignedAllowed(): bool {
		return Reactor::config('allow_unsigned_in_debug', false)===true && !self::production();
	}

	/**
	 * Converts a payload into deterministic JSON for signing.
	 *
	 * @param array<string, mixed> $payload Payload to canonicalize.
	 * @return string Canonical JSON representation.
	 */
	private static function canonical(array $payload): string {
		self::sort($payload);
		return json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}

	/**
	 * Recursively sorts associative payload keys before signing.
	 *
	 * @param array<string|int, mixed> $value Payload subtree mutated in place.
	 * @return void
	 */
	private static function sort(array &$value): void {
		ksort($value);
		foreach($value as &$item){
			if(is_array($item)){
				self::sort($item);
			}
			elseif(!is_string($item) && !is_int($item) && !is_float($item) && !is_bool($item) && $item!==null){
				throw new \InvalidArgumentException('Reactor signed payloads must contain only JSON scalar values and arrays.');
			}
		}
	}

	/** @return array{keys:array<string,string>,current:string,source:string,strong:bool} */
	private static function keyring(): array {
		$keys=[];
		$source='';
		$current='';
		$configured=Reactor::config('signing_keys');
		if($configured!==null){
			if(!is_array($configured) || $configured===[]){
				throw new \UnexpectedValueException('Reactor signing_keys must be a non-empty key map.');
			}
			foreach($configured as $id=>$secret){
				$keyId=is_string($id) ? trim($id) : 'key_'.count($keys);
				self::addKey($keys, $keyId, $secret);
			}
			$selected=Reactor::config('current_signing_key');
			if($selected===null){
				if(count($keys)!==1){ throw new \UnexpectedValueException('Reactor multi-key signing keyrings require an explicit current_signing_key.'); }
				$current=(string)array_key_first($keys);
			}
			else{
				if(!is_string($selected) || trim($selected)===''){ throw new \UnexpectedValueException('Reactor current_signing_key must be a non-empty string.'); }
				$current=trim($selected);
			}
			if(!isset($keys[$current])){ throw new \UnexpectedValueException('Reactor current_signing_key is not present in signing_keys.'); }
			$source='reactor_keyring';
		}
		else{
			$secret=Reactor::config('secret');
			if($secret!==null && (!is_scalar($secret) || trim((string)$secret)==='')){
				throw new \UnexpectedValueException('Reactor secret must be a non-empty scalar value.');
			}
			if(is_scalar($secret) && trim((string)$secret)!==''){
				self::addKey($keys, 'current', (string)$secret);
				$current='current';
				$source='reactor_secret';
			}
			elseif(defined('CFG') && is_array(constant('CFG'))){
				$config=constant('CFG');
				foreach(['secret','app_secret','csrf_secret'] as $name){
					if(is_scalar($config[$name] ?? null) && trim((string)$config[$name])!==''){
						self::addKey($keys, 'current', (string)$config[$name]);
						$current='current';
						$source='application_config';
						break;
					}
				}
			}
		}

		$previous=Reactor::config('previous_signing_secrets', []);
		if($previous!==null && !is_array($previous)){ throw new \UnexpectedValueException('Reactor previous_signing_secrets must be an array.'); }
		foreach((array)$previous as $id=>$secret){
			$keyId=is_string($id) ? trim($id) : 'previous_'.count($keys);
			self::addKey($keys, $keyId, $secret);
		}
		if($keys!==[] && $current===''){
			throw new \UnexpectedValueException('Reactor previous signing secrets require a current signing secret or keyring.');
		}
		if(count($keys)>self::MAX_VERIFY_KEYS){ throw new \LengthException('Reactor signing keyrings may contain at most eight verification keys.'); }

		if($keys===[]){
			if(self::production()){
				throw new \RuntimeException('Reactor signing is unavailable in production until a private secret or keyring is configured.');
			}
			self::$ephemeralSecret ??=random_bytes(self::MIN_PRODUCTION_SECRET_BYTES);
			$keys=['ephemeral'=>self::$ephemeralSecret];
			$current='ephemeral';
			$source='process_ephemeral';
		}

		$strong=true;
		foreach($keys as $secret){ if(strlen($secret)<self::MIN_PRODUCTION_SECRET_BYTES){ $strong=false; } }
		if(self::production() && !$strong){
			throw new \RuntimeException('Every Reactor production signing secret must contain at least 32 bytes.');
		}
		return ['keys'=>$keys,'current'=>$current,'source'=>$source,'strong'=>$strong];
	}

	/** @param array<string,string> $keys */
	private static function addKey(array &$keys, string $id, mixed $secret): void {
		if($id==='' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $id)!==1){
			throw new \InvalidArgumentException('Reactor signing key ids must contain 1 to 64 safe characters.');
		}
		if(!is_scalar($secret) || trim((string)$secret)==='' || str_contains((string)$secret, "\0")){
			throw new \InvalidArgumentException('Reactor signing secrets must be non-empty scalar values without null bytes.');
		}
		if(isset($keys[$id])){ throw new \InvalidArgumentException('Reactor signing key ids must be unique.'); }
		$keys[$id]=(string)$secret;
	}

	private static function production(): bool {
		if(defined('IS_PRODUCTION') && constant('IS_PRODUCTION')===true){ return true; }
		return Reactor::config('production', false)===true;
	}
}
