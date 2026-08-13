<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Verifies signed Panel package templates against host-owned public keys.
 *
 * Signatures cover a domain-separated canonical JSON payload containing the
 * package manifest (excluding its signature field) and the sorted path, size,
 * and SHA-256 digest of every artifact. Keys are supplied by the host; embedded
 * package keys are rejected unless explicitly enabled. Ed25519 is preferred,
 * with RSA/ECDSA SHA-256 available through OpenSSL.
 */
final class PanelPackageSignatureVerifier implements \JsonSerializable {

	private const FORMAT='dataphyre.panel.package.signature.v1';
	private const MAX_ENCODED_SIGNATURE_BYTES=131072;
	private const MAX_PUBLIC_KEY_BYTES=65536;
	private array $keys=[];
	private bool $allowEmbeddedKeys=false;
	private int $maxArtifacts=10000;
	private int $maxBytes=268435456;
	private array $meta=[];

	/**
	 * @param array<string,mixed> $keys Key id => public-key string or key descriptor.
	 * @param array<string,mixed> $options Verifier limits and embedded-key policy.
	 */
	public function __construct(array $keys=[], array $options=[]) {
		foreach($keys as $id=>$key){
			if(is_int($id) && is_array($key)){
				$id=(string)($key['id'] ?? $key['key_id'] ?? '');
			}
			if(is_array($key)){
				$this->key((string)$id, (string)($key['public_key'] ?? $key['key'] ?? ''), (string)($key['algorithm'] ?? 'ed25519'), is_array($key['meta'] ?? null) ? $key['meta'] : []);
			}
			else{
				$this->key((string)$id, (string)$key);
			}
		}
		if(array_key_exists('allow_embedded_keys', $options)){
			$this->allowEmbeddedKeys((bool)$options['allow_embedded_keys']);
		}
		if(isset($options['max_artifacts'])){
			$this->maxArtifacts((int)$options['max_artifacts']);
		}
		if(isset($options['max_bytes'])){
			$this->maxBytes((int)$options['max_bytes']);
		}
		if(is_array($options['meta'] ?? null)){
			$this->meta($options['meta']);
		}
	}

	/** @return self Configured verifier. */
	public static function make(array $keys=[], array $options=[]): self {
		return new self($keys, $options);
	}

	/**
	 * Registers one host-trusted public key.
	 *
	 * Key material is intentionally omitted from serialized verifier manifests.
	 */
	public function key(string $id, string $publicKey, string $algorithm='ed25519', array $meta=[]): self {
		$id=trim($id);
		$publicKey=trim($publicKey);
		$algorithm=$this->normalizeAlgorithm($algorithm);
		if($id!=='' && $publicKey!==''){
			$this->keys[$id]=[
				'public_key'=>$publicKey,
				'algorithm'=>$algorithm,
				'meta'=>$meta,
			];
		}
		return $this;
	}

	/** @return self Verifier with the key id removed. */
	public function forgetKey(string $id): self {
		unset($this->keys[trim($id)]);
		return $this;
	}

	/** @return self Verifier with embedded-key behavior configured. */
	public function allowEmbeddedKeys(bool $allow=true): self {
		$this->allowEmbeddedKeys=$allow;
		return $this;
	}

	/** @return self Verifier with the artifact-count safety cap configured. */
	public function maxArtifacts(int $count): self {
		$this->maxArtifacts=max(1, $count);
		return $this;
	}

	/** @return self Verifier with the aggregate-byte safety cap configured. */
	public function maxBytes(int $bytes): self {
		$this->maxBytes=max(1, $bytes);
		return $this;
	}

	/** @return self Verifier with diagnostic metadata merged. */
	public function meta(array|string $key, mixed $value=null): self {
		if(is_array($key)){
			$this->meta=array_replace($this->meta, $key);
			return $this;
		}
		$key=trim($key);
		if($key!==''){
			$this->meta[$key]=$value;
		}
		return $this;
	}

	/**
	 * Returns the exact bytes package publishers must sign.
	 *
	 * @throws \InvalidArgumentException When the package bundle cannot be
	 * canonicalized safely.
	 */
	public function payload(PanelPackageTemplate|array $bundle): string {
		$normalized=$this->normalizeBundle($bundle);
		if($normalized['errors']!==[]){
			throw new \InvalidArgumentException(implode(' ', $normalized['errors']));
		}
		return $this->encodeCanonical([
			'format'=>self::FORMAT,
			'package'=>$normalized['package'],
			'artifacts'=>$normalized['artifacts'],
		]);
	}

	/** @return string Lowercase SHA-256 digest of the canonical payload. */
	public function digest(PanelPackageTemplate|array $bundle): string {
		return hash('sha256', $this->payload($bundle));
	}

	/**
	 * Verifies the package signature and its declared payload digest.
	 *
	 * Malformed or unsupported packages return a failed result instead of
	 * throwing, making the verifier safe at installation and registry boundaries.
	 */
	public function verify(PanelPackageTemplate|array $bundle, array $meta=[]): PanelPackageVerificationResult {
		try{
			$normalized=$this->normalizeBundle($bundle);
		}
		catch(\Throwable){
			return PanelPackageVerificationResult::make([
				'ok'=>false,
				'checks'=>[[
					'name'=>'bundle_valid',
					'ok'=>false,
					'expected'=>'canonically serializable package and artifact bundle',
					'actual'=>'invalid',
				]],
				'errors'=>['Package payload is not canonically serializable.'],
				'meta'=>array_replace($this->meta, $meta),
			]);
		}
		$package=$normalized['package'];
		$packageId=(string)($package['id'] ?? '');
		$signature=is_array($normalized['signature'] ?? null) ? $normalized['signature'] : [];
		$algorithm=$this->normalizeAlgorithm((string)($signature['algorithm'] ?? $signature['alg'] ?? ''));
		$keyId=trim((string)($signature['key_id'] ?? $signature['key'] ?? ''));
		$encodedSignature=trim((string)($signature['signature'] ?? $signature['value'] ?? ''));
		$declaredDigest=$this->normalizeDigest((string)($signature['digest'] ?? ''));
		$checks=[];
		$errors=$normalized['errors'];
		$payload='';
		$digest='';
		try{
			$payload=$this->encodeCanonical([
				'format'=>self::FORMAT,
				'package'=>$package,
				'artifacts'=>$normalized['artifacts'],
			]);
			$digest=hash('sha256', $payload);
		}
		catch(\Throwable){
			$errors[]='Package payload is not canonically serializable.';
		}
		$this->check($checks, $errors, 'bundle_valid', $normalized['errors']===[], 'valid package and artifact bundle', $normalized['errors']===[] ? 'valid' : 'invalid', 'Package artifact bundle is invalid.');
		$this->check($checks, $errors, 'signature_present', $encodedSignature!=='', 'detached signature', $encodedSignature!=='' ? 'present' : 'missing', 'Package signature is missing.');
		$this->check($checks, $errors, 'algorithm_supported', in_array($algorithm, ['ed25519', 'rsa-sha256', 'ecdsa-sha256'], true), 'ed25519, rsa-sha256, or ecdsa-sha256', $algorithm!=='' ? $algorithm : 'missing', 'Package signature algorithm is unsupported or missing.');
		$this->check($checks, $errors, 'key_identified', $keyId!=='' || ($this->allowEmbeddedKeys && trim((string)($signature['public_key'] ?? ''))!==''), 'trusted key id', $keyId!=='' ? $keyId : 'missing', 'Package signature key is not identified.');
		$this->check($checks, $errors, 'digest_matches', $declaredDigest!=='' && $digest!=='' && hash_equals($digest, $declaredDigest), $digest, $declaredDigest!=='' ? $declaredDigest : 'missing', 'Package payload digest does not match.');

		$key=$keyId!=='' ? ($this->keys[$keyId] ?? null) : null;
		$embeddedKey=false;
		if($keyId==='' && $key===null && $this->allowEmbeddedKeys && trim((string)($signature['public_key'] ?? ''))!==''){
			$key=[
				'public_key'=>(string)$signature['public_key'],
				'algorithm'=>$algorithm,
				'meta'=>['embedded'=>true],
			];
			$embeddedKey=true;
		}
		$this->check($checks, $errors, 'key_trusted', is_array($key), 'configured host key or explicitly allowed anonymous embedded key', is_array($key) ? ($embeddedKey ? 'embedded' : 'configured') : 'unknown', 'Package signing key is not trusted by this verifier.');
		$algorithmMatches=is_array($key) && $this->normalizeAlgorithm((string)($key['algorithm'] ?? ''))===$algorithm;
		$this->check($checks, $errors, 'key_algorithm_matches', $algorithmMatches, $algorithm, is_array($key) ? $this->normalizeAlgorithm((string)($key['algorithm'] ?? '')) : 'unknown', 'Signing key algorithm does not match package signature algorithm.');

		$signatureBytes=strlen($encodedSignature)<=self::MAX_ENCODED_SIGNATURE_BYTES ? $this->decodeBinary($encodedSignature) : null;
		$this->check($checks, $errors, 'signature_encoding_valid', $signatureBytes!==null, 'base64, base64url, or hexadecimal', $signatureBytes!==null ? 'valid' : 'invalid', 'Package signature encoding is invalid.');
		$cryptographic=false;
		if($errors===[] && is_array($key) && $signatureBytes!==null){
			$cryptographic=$this->verifyBytes($algorithm, $payload, $signatureBytes, (string)$key['public_key']);
		}
		$this->check($checks, $errors, 'signature_valid', $cryptographic, 'valid detached signature', $cryptographic ? 'valid' : 'invalid', 'Package cryptographic signature is invalid.');
		$errors=array_values(array_unique($errors));
		return PanelPackageVerificationResult::make([
			'ok'=>$errors===[],
			'package'=>$packageId,
			'algorithm'=>$algorithm,
			'key_id'=>$keyId,
			'digest'=>$digest,
			'artifact_count'=>count($normalized['artifacts']),
			'bytes'=>$normalized['bytes'],
			'checks'=>$checks,
			'errors'=>$errors,
			'meta'=>array_replace($this->meta, $meta),
		]);
	}

	/** @return array<string,mixed> Verifier policy without public-key material. */
	public function toArray(): array {
		$keys=[];
		foreach($this->keys as $id=>$key){
			$keys[]=[
				'id'=>$id,
				'algorithm'=>(string)($key['algorithm'] ?? ''),
				'fingerprint'=>hash('sha256', (string)($key['public_key'] ?? '')),
				'meta'=>is_array($key['meta'] ?? null) ? $this->sanitize($key['meta']) : [],
			];
		}
		return [
			'type'=>'panel_package_signature_verifier',
			'format'=>self::FORMAT,
			'allow_embedded_keys'=>$this->allowEmbeddedKeys,
			'max_artifacts'=>$this->maxArtifacts,
			'max_bytes'=>$this->maxBytes,
			'keys'=>$keys,
			'meta'=>$this->sanitize($this->meta),
		];
	}

	/** @return array<string,mixed> Verifier policy without public-key material. */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/** @return array{package:array,signature:array,artifacts:array,bytes:int,errors:array<int,string>} */
	private function normalizeBundle(PanelPackageTemplate|array $bundle): array {
		$data=$bundle instanceof PanelPackageTemplate ? $bundle->manifest() : $bundle;
		$package=is_array($data['package'] ?? null) ? $data['package'] : [];
		if($package===[] && isset($data['id'])){
			$package=$data;
		}
		$signature=is_array($package['signature'] ?? null) ? $package['signature'] : [];
		unset($package['signature'], $package['compatibility']);
		$artifacts=[];
		$seen=[];
		$bytes=0;
		$errors=[];
		$rawArtifacts=is_array($data['artifacts'] ?? null) ? $data['artifacts'] : [];
		if(count($rawArtifacts)>$this->maxArtifacts){
			$errors[]='Package exceeds the verifier artifact limit.';
		}
		foreach($rawArtifacts as $artifact){
			if(!is_array($artifact)){
				$errors[]='Package contains an invalid artifact descriptor.';
				continue;
			}
			$path=$this->normalizeArtifactPath((string)($artifact['path'] ?? ''));
			$collisionKey=strtolower($path);
			if($path==='' || isset($seen[$collisionKey])){
				$errors[]=$path==='' ? 'Package contains an unsafe artifact path.' : 'Package contains a duplicate artifact path.';
				continue;
			}
			$seen[$collisionKey]=true;
			$contents=(string)($artifact['contents'] ?? '');
			$size=strlen($contents);
			$bytes+=$size;
			$signedContents=$contents;
			if($path==='dataphyre-panel-package.json'){
				try{
					$manifest=json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
					if(!is_array($manifest)){
						throw new \JsonException('Package manifest must be an object.');
					}
					unset($manifest['signature'], $manifest['compatibility']);
					$signedContents=$this->encodeCanonical($manifest);
				}
				catch(\Throwable){
					$errors[]='Package manifest artifact is not valid JSON.';
				}
			}
			$artifacts[]=[
				'path'=>$path,
				'bytes'=>$path==='dataphyre-panel-package.json' ? strlen($signedContents) : $size,
				'sha256'=>hash('sha256', $signedContents),
			];
		}
		if($bytes>$this->maxBytes){
			$errors[]='Package exceeds the verifier byte limit.';
		}
		if($this->containsSensitiveMaterial($signature)){
			$errors[]='Package signature metadata contains forbidden secret material.';
		}
		usort($artifacts, static fn(array $left, array $right): int => strcmp((string)$left['path'], (string)$right['path']));
		if(trim((string)($package['id'] ?? ''))===''){
			$errors[]='Package manifest is missing an identifier.';
		}
		return [
			'package'=>$this->canonicalize($package),
			'signature'=>$signature,
			'artifacts'=>$artifacts,
			'bytes'=>$bytes,
			'errors'=>array_values(array_unique($errors)),
		];
	}

	private function normalizeArtifactPath(string $path): string {
		$path=trim(str_replace('\\', '/', $path), '/');
		if($path==='' || str_contains($path, "\0") || preg_match('/^[A-Za-z]:/', $path)===1){
			return '';
		}
		$segments=[];
		foreach(explode('/', $path) as $segment){
			if($segment==='' || $segment==='.' || $segment==='..' || preg_match('/[\x00-\x1F\x7F:]/', $segment)===1 || rtrim($segment, ". ")!==$segment || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?\z/i', $segment)===1){
				return '';
			}
			$segments[]=$segment;
		}
		return implode('/', $segments);
	}

	private function normalizeAlgorithm(string $algorithm): string {
		$algorithm=strtolower(trim($algorithm));
		return match($algorithm){
			'eddsa', 'ed25519-sha512'=>'ed25519',
			'rsa', 'rs256', 'rsa_sha256'=>'rsa-sha256',
			'ecdsa', 'es256', 'ecdsa_sha256'=>'ecdsa-sha256',
			default=>$algorithm,
		};
	}

	private function normalizeDigest(string $digest): string {
		$digest=strtolower(trim($digest));
		if(str_starts_with($digest, 'sha256:')){
			$digest=substr($digest, 7);
		}
		return preg_match('/^[a-f0-9]{64}$/', $digest)===1 ? $digest : '';
	}

	private function decodeBinary(string $encoded): ?string {
		$encoded=trim($encoded);
		if($encoded===''){
			return null;
		}
		if(preg_match('/^(?:[a-fA-F0-9]{2})+$/', $encoded)===1){
			$decoded=hex2bin($encoded);
			return $decoded===false ? null : $decoded;
		}
		$base64=strtr($encoded, '-_', '+/');
		$remainder=strlen($base64)%4;
		if($remainder!==0){
			$base64.=str_repeat('=', 4-$remainder);
		}
		$decoded=base64_decode($base64, true);
		return $decoded===false ? null : $decoded;
	}

	private function decodePublicKey(string $key): ?string {
		$key=trim($key);
		if($key===''){
			return null;
		}
		if(str_contains($key, 'BEGIN')){
			return $key;
		}
		return $this->decodeBinary($key);
	}

	private function verifyBytes(string $algorithm, string $payload, string $signature, string $publicKey): bool {
		try{
			if(strlen($publicKey)>self::MAX_PUBLIC_KEY_BYTES){
				return false;
			}
			if($algorithm==='ed25519'){
				$key=$this->decodePublicKey($publicKey);
				return $key!==null
					&& strlen($key)===SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
					&& strlen($signature)===SODIUM_CRYPTO_SIGN_BYTES
					&& function_exists('sodium_crypto_sign_verify_detached')
					&& sodium_crypto_sign_verify_detached($signature, $payload, $key);
			}
			if(in_array($algorithm, ['rsa-sha256', 'ecdsa-sha256'], true) && function_exists('openssl_verify') && function_exists('openssl_pkey_get_public')){
				$key=openssl_pkey_get_public($publicKey);
				if($key===false){return false;}
				$details=openssl_pkey_get_details($key);
				$expectedType=$algorithm==='rsa-sha256' ? OPENSSL_KEYTYPE_RSA : OPENSSL_KEYTYPE_EC;
				if(!is_array($details) || ($details['type'] ?? null)!==$expectedType){return false;}
				return openssl_verify($payload, $signature, $key, OPENSSL_ALGO_SHA256)===1;
			}
		}
		catch(\Throwable){
			return false;
		}
		return false;
	}

	private function encodeCanonical(array $payload): string {
		return json_encode($this->canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
	}

	private function canonicalize(mixed $value): mixed {
		if(is_array($value)){
			if(!array_is_list($value)){
				ksort($value, SORT_STRING);
			}
			foreach($value as $key=>$item){
				$value[$key]=$this->canonicalize($item);
			}
			return $value;
		}
		if($value===null || is_bool($value) || is_int($value) || is_string($value)){
			return $value;
		}
		if(is_float($value) && is_finite($value)){
			return $value;
		}
		throw new \InvalidArgumentException('Unsupported value in canonical package manifest.');
	}

	private function containsSensitiveMaterial(array $value): bool {
		foreach($value as $key=>$item){
			if(is_string($key) && preg_match('/(?:^|_)(?:secret|password|passwd|token|private_key|secret_key|seed|credential)(?:$|_)/i', $key)===1){return true;}
			if(is_array($item) && $this->containsSensitiveMaterial($item)){return true;}
		}
		return false;
	}

	private function sanitize(mixed $value, string $key=''): mixed {
		if($key!=='' && preg_match('/(?:^|_)(?:secret|password|passwd|token|private_key|secret_key|seed|credential|authorization|cookie)(?:$|_)/i', $key)===1){return '[REDACTED]';}
		if(!is_array($value)){return $value;}
		$sanitized=[];
		foreach($value as $itemKey=>$item){$sanitized[$itemKey]=$this->sanitize($item, is_string($itemKey) ? $itemKey : '');}
		return $sanitized;
	}

	private function check(array &$checks, array &$errors, string $name, bool $ok, string $expected, string $actual, string $error): void {
		$checks[]=[
			'name'=>$name,
			'ok'=>$ok,
			'expected'=>$expected,
			'actual'=>$actual,
		];
		if(!$ok){
			$errors[]=$error;
		}
	}
}
