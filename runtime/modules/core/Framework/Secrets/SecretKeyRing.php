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
 * Versioned in-process key ring for field and credential encryption.
 *
 * Applications remain responsible for obtaining root keys from deployment
 * secret management or a KMS. Key material is never serialized by this class.
 */
final class SecretKeyRing {

	/** @var array<string,string> Binary 256-bit roots keyed by opaque key id. */
	private array $keys=[];
	private string $primary_key_id;

	/**
	 * @param array<string,string> $keys Binary 32-byte key roots.
	 */
	private function __construct(array $keys, string $primary_key_id) {
		if(!isset($keys[$primary_key_id]) || strlen($keys[$primary_key_id])!==32){
			throw new SecretException('The primary secret key is unavailable.');
		}
		$this->keys=$keys;
		$this->primary_key_id=$primary_key_id;
	}

	/**
	 * Builds a rotation-aware ring from high-entropy deployment secrets.
	 *
	 * Previous secrets decrypt existing envelopes but are never used for new
	 * writes. A secret must contain at least 32 bytes after optional base64:
	 * decoding.
	 *
	 * @param array<int,string> $previous_secrets
	 */
	public static function fromSecrets(#[\SensitiveParameter] string $primary_secret, #[\SensitiveParameter] array $previous_secrets=[]): self {
		$keys=[];
		$primary=self::material($primary_secret);
		$primary_id=self::identifier($primary);
		$keys[$primary_id]=hash('sha256', "dataphyre-secret-root-v1\0".$primary, true);
		foreach($previous_secrets as $previous_secret){
			$previous=self::material((string)$previous_secret);
			$id=self::identifier($previous);
			$keys[$id]=hash('sha256', "dataphyre-secret-root-v1\0".$previous, true);
		}
		return new self($keys, $primary_id);
	}

	public function primaryId(): string {
		return $this->primary_key_id;
	}

	/** @return array<int,string> Safe opaque identifiers, never key material. */
	public function keyIds(): array {
		return array_keys($this->keys);
	}

	public function has(string $key_id): bool {
		return isset($this->keys[$key_id]);
	}

	/** @internal SecretEnvelope is the intended consumer of raw roots. */
	public function key(string $key_id): string {
		if(!isset($this->keys[$key_id])){
			throw new SecretException('The requested secret key version is unavailable.');
		}
		return $this->keys[$key_id];
	}

	private static function material(#[\SensitiveParameter] string $secret): string {
		$secret=trim($secret);
		if(str_starts_with($secret, 'base64:')){
			$decoded=base64_decode(substr($secret, 7), true);
			if(!is_string($decoded)){
				throw new SecretException('Secret key material is not valid base64.');
			}
			$secret=$decoded;
		}
		if(strlen($secret)<32){
			throw new SecretException('Secret key material must contain at least 32 bytes.');
		}
		return $secret;
	}

	private static function identifier(#[\SensitiveParameter] string $secret): string {
		return substr(hash_hmac('sha256', 'key-id', "dataphyre-secret-key-id-v1\0".$secret), 0, 16);
	}
}
