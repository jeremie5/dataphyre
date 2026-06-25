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

	/**
	 * Signs a Reactor payload.
	 *
	 * @param array<string, mixed> $payload Payload to canonicalize and sign.
	 * @return string Hex SHA-256 HMAC signature.
	 */
	public static function sign(array $payload): string {
		return hash_hmac('sha256', self::canonical($payload), self::secret());
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
		return hash_equals(self::sign($payload), $signature);
	}

	/**
	 * Resolves the secret used for Reactor HMAC signatures.
	 *
	 * @return string Configured Reactor/app secret, or local development fallback.
	 */
	private static function secret(): string {
		$secret=Reactor::config('secret');
		if(is_scalar($secret) && trim((string)$secret)!==''){
			return (string)$secret;
		}
		if(defined('CFG') && is_array(constant('CFG'))){
			$config=constant('CFG');
			foreach(['secret', 'app_secret', 'csrf_secret'] as $key){
				if(is_scalar($config[$key] ?? null) && trim((string)$config[$key])!==''){
					return (string)$config[$key];
				}
			}
		}
		return 'dataphyre-reactor-local-secret';
	}

	/**
	 * Reports whether unsigned payloads are allowed for local debug requests.
	 *
	 * @return bool `true` only when debug config allows unsigned payloads outside production.
	 */
	private static function unsignedAllowed(): bool {
		return Reactor::config('allow_unsigned_in_debug', true)===true
			&& (!defined('IS_PRODUCTION') || constant('IS_PRODUCTION')!==true);
	}

	/**
	 * Converts a payload into deterministic JSON for signing.
	 *
	 * @param array<string, mixed> $payload Payload to canonicalize.
	 * @return string Canonical JSON representation.
	 */
	private static function canonical(array $payload): string {
		self::sort($payload);
		return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
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
		}
	}
}
