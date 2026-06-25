<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Jwt;

use Dataphyre\Access\Exceptions\AuthenticationException;

/**
 * Encodes and validates JSON Web Tokens for Dataphyre access flows.
 *
 * The codec supports HMAC signing for token creation, HMAC/RSA verification for token
 * validation, algorithm allow-lists, key resolvers, keyed `kid` lookup, registered claim checks,
 * and strict base64url/JSON parsing. Authentication failures are reported through
 * `AuthenticationException` so callers can handle all JWT rejection paths consistently.
 */
final class JwtCodec {

	/** @var mixed Last raw algorithm allow-list input normalized by assertAlgorithmAllowed(). */
	private static mixed $lastAllowedAlgorithmsInput=null;

	/** @var ?array<int, string> Last normalized algorithm allow-list. */
	private static ?array $lastAllowedAlgorithmsOutput=null;

	/**
	 * Encodes claims into a signed JWT.
	 *
	 * Encoding currently signs HS-family algorithms and rejects unsupported algorithms instead of
	 * emitting unsigned or partially supported tokens.
	 *
	 * @param array<string, mixed> $claims JWT payload claims.
	 * @param array<string, mixed> $config Signing config containing secret/key/signing_key, algorithm, algorithms, keys, or key_resolver.
	 * @param array<string, mixed> $headers Additional or overriding JWT headers.
	 * @return string Compact JWT string.
	 */
	public static function encode(array $claims, array $config=[], array $headers=[]): string {
		$algorithm=strtoupper(trim((string)($headers['alg'] ?? $config['algorithm'] ?? 'HS256')));
		$headers=array_replace([
			'alg'=>$algorithm,
			'typ'=>'JWT',
		], $headers);
		self::assertAlgorithmAllowed($algorithm, $config);
		$headerSegment=self::base64UrlEncode(self::encodeSegment($headers, 'JWT header'));
		$payloadSegment=self::base64UrlEncode(self::encodeSegment($claims, 'JWT payload'));
		$signingInput=$headerSegment.'.'.$payloadSegment;
		$signature=self::sign($signingInput, $algorithm, $config, $headers, $claims);
		return $signingInput.'.'.self::base64UrlEncode($signature);
	}

	/**
	 * Decodes, verifies, and validates a compact JWT.
	 *
	 * The token must contain exactly three segments. Signature verification happens before
	 * registered-claim validation, and successful decoding returns a `JwtPayload` preserving the
	 * original token, headers, and claims.
	 *
	 * @param string $token Compact JWT string.
	 * @param array<string, mixed> $config Verification config containing keys, allowed algorithms, issuer, audience, leeway, and optional `now`.
	 * @return JwtPayload Verified token payload.
	 */
	public static function decode(string $token, array $config=[]): JwtPayload {
		$token=trim($token);
		if($token===''){
			throw new AuthenticationException('JWT token is missing.');
		}
		$segments=explode('.', $token);
		if(count($segments)!==3){
			throw new AuthenticationException('JWT token must contain three segments.');
		}
		[$headerSegment, $payloadSegment, $signatureSegment]=$segments;
		$headers=self::decodeSegment($headerSegment, 'JWT header');
		$claims=self::decodeSegment($payloadSegment, 'JWT payload');
		$algorithm=(string)($headers['alg'] ?? '');
		if($algorithm===''){
			throw new AuthenticationException('JWT algorithm header is missing.');
		}
		self::assertAlgorithmAllowed($algorithm, $config);
		self::verifySignature(
			$headerSegment.'.'.$payloadSegment,
			$signatureSegment,
			$algorithm,
			$config,
			$headers,
			$claims
		);
		self::validateRegisteredClaims($claims, $config);
		return new JwtPayload($token, $headers, $claims);
	}

	/**
	 * Decodes a base64url JSON segment into an associative array.
	 *
	 * @param string $segment Base64url JWT segment.
	 * @param string $label Human-readable segment label for exceptions.
	 * @return array<string, mixed> Decoded JSON object.
	 */
	private static function decodeSegment(string $segment, string $label): array {
		$decoded=self::base64UrlDecode($segment);
		if($decoded===null){
			throw new AuthenticationException("Unable to decode {$label}.");
		}
		$json=json_decode($decoded, true);
		if(!is_array($json)){
			throw new AuthenticationException("Invalid {$label} JSON.");
		}
		return $json;
	}

	/**
	 * Encodes a JWT header or payload segment as JSON.
	 *
	 * @param array<string, mixed> $payload Header or claims payload.
	 * @param string $label Human-readable segment label for exceptions.
	 * @return string JSON segment body before base64url encoding.
	 */
	private static function encodeSegment(array $payload, string $label): string {
		$encoded=json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if(!is_string($encoded) || $encoded===''){
			throw new AuthenticationException("Unable to encode {$label}.");
		}
		return $encoded;
	}

	/**
	 * Encodes bytes using unpadded base64url.
	 *
	 * @param string $value Raw bytes.
	 * @return string Base64url-encoded text without padding.
	 */
	private static function base64UrlEncode(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	/**
	 * Decodes unpadded base64url text.
	 *
	 * @param string $value Base64url-encoded text.
	 * @return string|null Raw bytes, or null when decoding fails.
	 */
	private static function base64UrlDecode(string $value): ?string {
		$remainder=strlen($value) % 4;
		if($remainder!==0){
			$value.=str_repeat('=', 4 - $remainder);
		}
		$decoded=base64_decode(strtr($value, '-_', '+/'), true);
		return $decoded===false ? null : $decoded;
	}

	/**
	 * Ensures a JWT algorithm is present in the configured allow-list.
	 *
	 * @param string $algorithm Algorithm from headers or config.
	 * @param array<string, mixed> $config JWT config.
	 * @return void
	 */
	private static function assertAlgorithmAllowed(string $algorithm, array $config): void {
		$allowed=$config['algorithms'] ?? $config['algorithm'] ?? ['HS256'];
		$allowed=self::normalizeAllowedAlgorithms($allowed);
		if(!in_array(strtoupper($algorithm), $allowed, true)){
			throw new AuthenticationException("JWT algorithm '{$algorithm}' is not allowed.");
		}
	}

	private static function normalizeAllowedAlgorithms(mixed $allowed): array {
		if(self::$lastAllowedAlgorithmsInput===$allowed && self::$lastAllowedAlgorithmsOutput!==null){
			return self::$lastAllowedAlgorithmsOutput;
		}
		$input=$allowed;
		$allowed=is_array($allowed) ? $allowed : [$allowed];
		$allowed=array_values(array_filter(array_map(
			static fn(mixed $value): string=>strtoupper(trim((string)$value)),
			$allowed
		), static fn(string $value): bool=>$value!==''));
		if($allowed===[]){
			$allowed=['HS256'];
		}
		self::$lastAllowedAlgorithmsInput=$input;
		return self::$lastAllowedAlgorithmsOutput=$allowed;
	}

	/**
	 * Verifies a JWT signature for supported HMAC and RSA algorithms.
	 *
	 * @param string $signingInput Header and payload segments joined with a dot.
	 * @param string $signatureSegment Base64url signature segment.
	 * @param string $algorithm JWT algorithm.
	 * @param array<string, mixed> $config Verification config.
	 * @param array<string, mixed> $headers Decoded JWT headers.
	 * @param array<string, mixed> $claims Decoded JWT claims.
	 * @return void
	 */
	private static function verifySignature(
		string $signingInput,
		string $signatureSegment,
		string $algorithm,
		array $config,
		array $headers=[],
		array $claims=[]
	): void {
		$signature=self::base64UrlDecode($signatureSegment);
		if($signature===null){
			throw new AuthenticationException('Unable to decode JWT signature.');
		}
		$algorithm=strtoupper($algorithm);
		if(str_starts_with($algorithm, 'HS')){
			$key=self::resolveKey($config, ['secret', 'key', 'signing_key'], $algorithm, $headers, $claims);
			$hashAlgorithm=match($algorithm){
				'HS256'=>'sha256',
				'HS384'=>'sha384',
				'HS512'=>'sha512',
				default=>null,
			};
			if($hashAlgorithm===null){
				throw new AuthenticationException("Unsupported JWT HMAC algorithm '{$algorithm}'.");
			}
			$expected=hash_hmac($hashAlgorithm, $signingInput, $key, true);
			if(!hash_equals($expected, $signature)){
				throw new AuthenticationException('JWT signature is invalid.');
			}
			return;
		}
		if(str_starts_with($algorithm, 'RS')){
			$key=self::resolveKey($config, ['public_key', 'verification_key', 'key'], $algorithm, $headers, $claims);
			$opensslAlgorithm=match($algorithm){
				'RS256'=>OPENSSL_ALGO_SHA256,
				'RS384'=>OPENSSL_ALGO_SHA384,
				'RS512'=>OPENSSL_ALGO_SHA512,
				default=>null,
			};
			if($opensslAlgorithm===null){
				throw new AuthenticationException("Unsupported JWT RSA algorithm '{$algorithm}'.");
			}
			if(openssl_verify($signingInput, $signature, $key, $opensslAlgorithm)!==1){
				throw new AuthenticationException('JWT signature is invalid.');
			}
			return;
		}
		throw new AuthenticationException("Unsupported JWT algorithm '{$algorithm}'.");
	}

	/**
	 * Signs JWT input for supported encoding algorithms.
	 *
	 * @param string $signingInput Header and payload segments joined with a dot.
	 * @param string $algorithm JWT algorithm.
	 * @param array<string, mixed> $config Signing config.
	 * @param array<string, mixed> $headers JWT headers.
	 * @param array<string, mixed> $claims JWT claims.
	 * @return string Raw signature bytes.
	 */
	private static function sign(
		string $signingInput,
		string $algorithm,
		array $config,
		array $headers=[],
		array $claims=[]
	): string {
		$algorithm=strtoupper($algorithm);
		if(str_starts_with($algorithm, 'HS')){
			$key=self::resolveKey($config, ['secret', 'key', 'signing_key'], $algorithm, $headers, $claims);
			$hashAlgorithm=match($algorithm){
				'HS256'=>'sha256',
				'HS384'=>'sha384',
				'HS512'=>'sha512',
				default=>null,
			};
			if($hashAlgorithm===null){
				throw new AuthenticationException("Unsupported JWT HMAC algorithm '{$algorithm}'.");
			}
			return hash_hmac($hashAlgorithm, $signingInput, $key, true);
		}
		throw new AuthenticationException("JWT encoding does not support algorithm '{$algorithm}'.");
	}

	/**
	 * Validates registered JWT claims against runtime config.
	 *
	 * @param array<string, mixed> $claims Decoded claims.
	 * @param array<string, mixed> $config Validation config with optional now, leeway, issuer, and audience.
	 * @return void
	 */
	private static function validateRegisteredClaims(array $claims, array $config): void {
		$now=(int)($config['now'] ?? time());
		$leeway=max(0, (int)($config['leeway'] ?? 0));
		if(isset($claims['nbf']) && is_numeric($claims['nbf']) && ((int)$claims['nbf']) > ($now + $leeway)){
			throw new AuthenticationException('JWT token is not valid yet.');
		}
		if(isset($claims['iat']) && is_numeric($claims['iat']) && ((int)$claims['iat']) > ($now + $leeway)){
			throw new AuthenticationException('JWT issued-at timestamp is invalid.');
		}
		if(isset($claims['exp']) && is_numeric($claims['exp']) && ((int)$claims['exp']) < ($now - $leeway)){
			throw new AuthenticationException('JWT token has expired.');
		}
		$issuer=$config['issuer'] ?? null;
		if($issuer!==null && $issuer!==''){
			if(($claims['iss'] ?? null)!==$issuer){
				throw new AuthenticationException('JWT issuer is invalid.');
			}
		}
		$audience=$config['audience'] ?? null;
		if($audience!==null && $audience!==''){
			$claimAudience=$claims['aud'] ?? null;
			$audiences=is_array($claimAudience) ? $claimAudience : [$claimAudience];
			if(!in_array($audience, $audiences, true)){
				throw new AuthenticationException('JWT audience is invalid.');
			}
		}
	}

	/**
	 * Resolves the signing or verification key for a token.
	 *
	 * Lookup order is `key_resolver`, `keys[kid]`, then named config candidates. Callables may
	 * provide keys dynamically for rotation and tenant-aware verification.
	 *
	 * @param array<string, mixed> $config JWT config.
	 * @param array<int, string> $candidates Config key names to try.
	 * @param string $algorithm JWT algorithm.
	 * @param array<string, mixed> $headers Decoded JWT headers.
	 * @param array<string, mixed> $claims Decoded JWT claims.
	 * @return string Signing or verification key.
	 */
	private static function resolveKey(
		array $config,
		array $candidates,
		string $algorithm='',
		array $headers=[],
		array $claims=[]
	): string {
		if(isset($config['key_resolver']) && is_callable($config['key_resolver'])){
			$key=($config['key_resolver'])($algorithm, $headers, $claims, $config);
			if(is_string($key) && trim($key)!==''){
				return trim($key);
			}
		}
		$kid=trim((string)($headers['kid'] ?? ''));
		if($kid!=='' && is_array($config['keys'] ?? null) && isset($config['keys'][$kid])){
			$key=$config['keys'][$kid];
			if(is_callable($key)){
				$key=$key($algorithm, $headers, $claims, $config);
			}
			if(is_string($key) && trim($key)!==''){
				return trim($key);
			}
		}
		foreach($candidates as $candidate){
			if(!array_key_exists($candidate, $config)){
				continue;
			}
			$key=$config[$candidate];
			if(is_callable($key)){
				$key=$key();
			}
			if(is_string($key) && $key!==''){
				return $key;
			}
		}
		throw new AuthenticationException('JWT verification key is missing.');
	}
}
