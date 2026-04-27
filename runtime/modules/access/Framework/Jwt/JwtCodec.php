<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Jwt;

use Dataphyre\Access\Exceptions\AuthenticationException;

final class JwtCodec {

	public static function encode(array $claims, array $config=[], array $headers=[]): string {
		$algorithm=strtoupper(trim((string)($headers['alg'] ?? $config['algorithm'] ?? 'HS256')));
		$headers=array_replace([
			'alg'=>$algorithm,
			'typ'=>'JWT',
		], $headers);
		self::assertAlgorithmAllowed($algorithm, $config);
		$header_segment=self::base64UrlEncode(self::encodeSegment($headers, 'JWT header'));
		$payload_segment=self::base64UrlEncode(self::encodeSegment($claims, 'JWT payload'));
		$signing_input=$header_segment.'.'.$payload_segment;
		$signature=self::sign($signing_input, $algorithm, $config, $headers, $claims);
		return $signing_input.'.'.self::base64UrlEncode($signature);
	}

	public static function decode(string $token, array $config=[]): JwtPayload {
		$token=trim($token);
		if($token===''){
			throw new AuthenticationException('JWT token is missing.');
		}
		$segments=explode('.', $token);
		if(count($segments)!==3){
			throw new AuthenticationException('JWT token must contain three segments.');
		}
		[$header_segment, $payload_segment, $signature_segment]=$segments;
		$headers=self::decodeSegment($header_segment, 'JWT header');
		$claims=self::decodeSegment($payload_segment, 'JWT payload');
		$algorithm=(string)($headers['alg'] ?? '');
		if($algorithm===''){
			throw new AuthenticationException('JWT algorithm header is missing.');
		}
		self::assertAlgorithmAllowed($algorithm, $config);
		self::verifySignature(
			$header_segment.'.'.$payload_segment,
			$signature_segment,
			$algorithm,
			$config,
			$headers,
			$claims
		);
		self::validateRegisteredClaims($claims, $config);
		return new JwtPayload($token, $headers, $claims);
	}

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

	private static function encodeSegment(array $payload, string $label): string {
		$encoded=json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if(!is_string($encoded) || $encoded===''){
			throw new AuthenticationException("Unable to encode {$label}.");
		}
		return $encoded;
	}

	private static function base64UrlEncode(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	private static function base64UrlDecode(string $value): ?string {
		$remainder=strlen($value) % 4;
		if($remainder!==0){
			$value.=str_repeat('=', 4 - $remainder);
		}
		$decoded=base64_decode(strtr($value, '-_', '+/'), true);
		return $decoded===false ? null : $decoded;
	}

	private static function assertAlgorithmAllowed(string $algorithm, array $config): void {
		$allowed=$config['algorithms'] ?? $config['algorithm'] ?? ['HS256'];
		$allowed=is_array($allowed) ? $allowed : [$allowed];
		$allowed=array_values(array_filter(array_map(
			static fn(mixed $value): string=>strtoupper(trim((string)$value)),
			$allowed
		), static fn(string $value): bool=>$value!==''));
		if($allowed===[]){
			$allowed=['HS256'];
		}
		if(!in_array(strtoupper($algorithm), $allowed, true)){
			throw new AuthenticationException("JWT algorithm '{$algorithm}' is not allowed.");
		}
	}

	private static function verifySignature(
		string $signing_input,
		string $signature_segment,
		string $algorithm,
		array $config,
		array $headers=[],
		array $claims=[]
	): void {
		$signature=self::base64UrlDecode($signature_segment);
		if($signature===null){
			throw new AuthenticationException('Unable to decode JWT signature.');
		}
		$algorithm=strtoupper($algorithm);
		if(str_starts_with($algorithm, 'HS')){
			$key=self::resolveKey($config, ['secret', 'key', 'signing_key'], $algorithm, $headers, $claims);
			$hash_algorithm=match($algorithm){
				'HS256'=>'sha256',
				'HS384'=>'sha384',
				'HS512'=>'sha512',
				default=>null,
			};
			if($hash_algorithm===null){
				throw new AuthenticationException("Unsupported JWT HMAC algorithm '{$algorithm}'.");
			}
			$expected=hash_hmac($hash_algorithm, $signing_input, $key, true);
			if(!hash_equals($expected, $signature)){
				throw new AuthenticationException('JWT signature is invalid.');
			}
			return;
		}
		if(str_starts_with($algorithm, 'RS')){
			$key=self::resolveKey($config, ['public_key', 'verification_key', 'key'], $algorithm, $headers, $claims);
			$openssl_algorithm=match($algorithm){
				'RS256'=>OPENSSL_ALGO_SHA256,
				'RS384'=>OPENSSL_ALGO_SHA384,
				'RS512'=>OPENSSL_ALGO_SHA512,
				default=>null,
			};
			if($openssl_algorithm===null){
				throw new AuthenticationException("Unsupported JWT RSA algorithm '{$algorithm}'.");
			}
			if(openssl_verify($signing_input, $signature, $key, $openssl_algorithm)!==1){
				throw new AuthenticationException('JWT signature is invalid.');
			}
			return;
		}
		throw new AuthenticationException("Unsupported JWT algorithm '{$algorithm}'.");
	}

	private static function sign(
		string $signing_input,
		string $algorithm,
		array $config,
		array $headers=[],
		array $claims=[]
	): string {
		$algorithm=strtoupper($algorithm);
		if(str_starts_with($algorithm, 'HS')){
			$key=self::resolveKey($config, ['secret', 'key', 'signing_key'], $algorithm, $headers, $claims);
			$hash_algorithm=match($algorithm){
				'HS256'=>'sha256',
				'HS384'=>'sha384',
				'HS512'=>'sha512',
				default=>null,
			};
			if($hash_algorithm===null){
				throw new AuthenticationException("Unsupported JWT HMAC algorithm '{$algorithm}'.");
			}
			return hash_hmac($hash_algorithm, $signing_input, $key, true);
		}
		throw new AuthenticationException("JWT encoding does not support algorithm '{$algorithm}'.");
	}

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
			$claim_audience=$claims['aud'] ?? null;
			$audiences=is_array($claim_audience) ? $claim_audience : [$claim_audience];
			if(!in_array($audience, $audiences, true)){
				throw new AuthenticationException('JWT audience is invalid.');
			}
		}
	}

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
