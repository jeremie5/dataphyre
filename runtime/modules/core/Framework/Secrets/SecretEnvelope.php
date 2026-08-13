<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Secrets;

/**
 * Purpose- and context-bound authenticated encryption for persisted secrets.
 *
 * The envelope is suitable for PostgreSQL text columns. It includes an opaque
 * key id so old rows can be opened during key rotation, while AES-256-GCM AAD
 * prevents moving ciphertext between tenants, scopes, providers, or purposes.
 */
final class SecretEnvelope {

	public const VERSION='v1';
	public const ALGORITHM='aes-256-gcm';
	private const PREFIX='dpsecret:v1:';
	private const IV_BYTES=12;
	private const TAG_BYTES=16;

	public function __construct(private SecretKeyRing $keys) {
		if(!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')){
			throw new SecretException('Authenticated secret encryption is unavailable.');
		}
	}

	/** @return array{ciphertext:string,fingerprint:string,key_id:string,version:string,algorithm:string} */
	public function sealString(#[\SensitiveParameter] string $plaintext, string $purpose, array $context=[]): array {
		return $this->seal(['kind'=>'string', 'value'=>$plaintext], $purpose, $context);
	}

	/** @return array{ciphertext:string,fingerprint:string,key_id:string,version:string,algorithm:string} */
	public function sealJson(#[\SensitiveParameter] array $payload, string $purpose, array $context=[]): array {
		return $this->seal(['kind'=>'json', 'value'=>$payload], $purpose, $context);
	}

	public function openString(#[\SensitiveParameter] string $envelope, string $purpose, array $context=[]): string {
		$decoded=$this->open($envelope, $purpose, $context);
		if(($decoded['kind'] ?? null)!=='string' || !is_string($decoded['value'] ?? null)){
			throw new SecretException('The secret envelope payload type is invalid.');
		}
		return $decoded['value'];
	}

	/** @return array<string|int,mixed> */
	public function openJson(#[\SensitiveParameter] string $envelope, string $purpose, array $context=[]): array {
		$decoded=$this->open($envelope, $purpose, $context);
		if(($decoded['kind'] ?? null)!=='json' || !is_array($decoded['value'] ?? null)){
			throw new SecretException('The secret envelope payload type is invalid.');
		}
		return $decoded['value'];
	}

	public function fingerprintString(#[\SensitiveParameter] string $plaintext, string $purpose, array $context=[]): string {
		return $this->fingerprint($this->canonical(['kind'=>'string', 'value'=>$plaintext]), $purpose, $context, $this->keys->primaryId());
	}

	public function matchesString(#[\SensitiveParameter] string $plaintext, string $fingerprint, #[\SensitiveParameter] string $envelope, string $purpose, array $context=[]): bool {
		if(preg_match('/^[a-f0-9]{64}$/', $fingerprint)!==1) return false;
		try{
			$key_id=$this->inspect($envelope)['key_id'];
			$expected=$this->fingerprint($this->canonical(['kind'=>'string', 'value'=>$plaintext]), $purpose, $context, $key_id);
			return hash_equals($fingerprint, $expected);
		}catch(\Throwable){
			return false;
		}
	}

	public function isEnvelope(string $value): bool {
		try{
			$this->inspect($value);
			return true;
		}catch(\Throwable){
			return false;
		}
	}

	public function needsRotation(string $envelope): bool {
		try{
			return !hash_equals($this->keys->primaryId(), $this->inspect($envelope)['key_id']);
		}catch(\Throwable){
			return true;
		}
	}

	/** @return array{version:string,algorithm:string,key_id:string} */
	public function inspect(string $envelope): array {
		[$key_id, $payload]=$this->parse($envelope);
		if(!$this->keys->has($key_id)){
			throw new SecretException('The secret envelope key version is unavailable.');
		}
		if(strlen($payload)<self::IV_BYTES+self::TAG_BYTES+1){
			throw new SecretException('The secret envelope is malformed.');
		}
		return ['version'=>self::VERSION, 'algorithm'=>self::ALGORITHM, 'key_id'=>$key_id];
	}

	/** @param array{kind:string,value:mixed} $payload */
	private function seal(#[\SensitiveParameter] array $payload, string $purpose, array $context): array {
		$plain=$this->canonical($payload);
		$key_id=$this->keys->primaryId();
		$aad=$this->aad($purpose, $context);
		$key=$this->derive($this->keys->key($key_id), 'encryption', $aad);
		$iv=random_bytes(self::IV_BYTES);
		$tag='';
		$ciphertext=openssl_encrypt($plain, self::ALGORITHM, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, self::TAG_BYTES);
		if(!is_string($ciphertext) || strlen($tag)!==self::TAG_BYTES){
			throw new SecretException('Secret encryption failed.');
		}
		return [
			'ciphertext'=>self::PREFIX.$key_id.':'.$this->base64UrlEncode($iv.$tag.$ciphertext),
			'fingerprint'=>$this->fingerprint($plain, $purpose, $context, $key_id),
			'key_id'=>$key_id,
			'version'=>self::VERSION,
			'algorithm'=>self::ALGORITHM,
		];
	}

	/** @return array{kind:string,value:mixed} */
	private function open(#[\SensitiveParameter] string $envelope, string $purpose, array $context): array {
		[$key_id, $payload]=$this->parse($envelope);
		$aad=$this->aad($purpose, $context);
		$key=$this->derive($this->keys->key($key_id), 'encryption', $aad);
		$minimum=self::IV_BYTES+self::TAG_BYTES+1;
		if(strlen($payload)<$minimum){
			throw new SecretException('The secret envelope is malformed.');
		}
		$plain=openssl_decrypt(
			substr($payload, self::IV_BYTES+self::TAG_BYTES),
			self::ALGORITHM,
			$key,
			OPENSSL_RAW_DATA,
			substr($payload, 0, self::IV_BYTES),
			substr($payload, self::IV_BYTES, self::TAG_BYTES),
			$aad,
		);
		if(!is_string($plain)){
			throw new SecretException('Secret authentication failed.');
		}
		$decoded=json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
		if(!is_array($decoded) || !isset($decoded['kind']) || !array_key_exists('value', $decoded)){
			throw new SecretException('The secret envelope payload is malformed.');
		}
		return $decoded;
	}

	private function fingerprint(#[\SensitiveParameter] string $plain, string $purpose, array $context, string $key_id): string {
		$aad=$this->aad($purpose, $context);
		$key=$this->derive($this->keys->key($key_id), 'fingerprint', $aad);
		return hash_hmac('sha256', $plain, $key);
	}

	/** @return array{0:string,1:string} */
	private function parse(string $envelope): array {
		if(!str_starts_with($envelope, self::PREFIX)){
			throw new SecretException('The secret envelope version is unsupported.');
		}
		$remainder=substr($envelope, strlen(self::PREFIX));
		$separator=strpos($remainder, ':');
		if($separator===false){
			throw new SecretException('The secret envelope is malformed.');
		}
		$key_id=substr($remainder, 0, $separator);
		if(preg_match('/^[a-f0-9]{16}$/', $key_id)!==1){
			throw new SecretException('The secret envelope key id is malformed.');
		}
		$payload=$this->base64UrlDecode(substr($remainder, $separator+1));
		return [$key_id, $payload];
	}

	private function aad(string $purpose, array $context): string {
		$purpose=strtolower(trim($purpose));
		if($purpose==='' || preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,190}$/', $purpose)!==1){
			throw new SecretException('The secret purpose is invalid.');
		}
		return $this->canonical([
			'context'=>$context,
			'format'=>'dataphyre.secret-envelope.v1',
			'purpose'=>$purpose,
		]);
	}

	private function derive(#[\SensitiveParameter] string $root, string $use, string $aad): string {
		$key=hash_hkdf('sha256', $root, 32, 'dataphyre-secret-'.$use.'-v1', hash('sha256', $aad, true));
		if(strlen($key)!==32){
			throw new SecretException('Secret key derivation failed.');
		}
		return $key;
	}

	private function canonical(mixed $value): string {
		try{
			$normalized=$this->normalizeCanonical($value);
			return json_encode($normalized, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
		}catch(\JsonException|\InvalidArgumentException){
			throw new SecretException('Secret context or payload is not canonically serializable.');
		}
	}

	private function normalizeCanonical(mixed $value): mixed {
		if(is_array($value)){
			if(array_is_list($value)){
				return array_map(fn(mixed $item): mixed=>$this->normalizeCanonical($item), $value);
			}
			$normalized=[];
			$keys=array_map('strval', array_keys($value));
			sort($keys, SORT_STRING);
			foreach($keys as $key){
				$normalized[$key]=$this->normalizeCanonical($value[$key]);
			}
			return $normalized;
		}
		if(is_float($value) && !is_finite($value)){
			throw new \InvalidArgumentException('Non-finite numbers are unsupported.');
		}
		if(is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value===null){
			return $value;
		}
		throw new \InvalidArgumentException('Unsupported canonical value.');
	}

	private function base64UrlEncode(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	private function base64UrlDecode(string $value): string {
		if($value==='' || preg_match('/^[A-Za-z0-9_-]+$/', $value)!==1){
			throw new SecretException('The secret envelope encoding is malformed.');
		}
		$padding=(4-(strlen($value)%4))%4;
		$decoded=base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);
		if(!is_string($decoded)){
			throw new SecretException('The secret envelope encoding is malformed.');
		}
		return $decoded;
	}
}
