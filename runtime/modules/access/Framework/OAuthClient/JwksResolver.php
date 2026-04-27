<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\OAuthClient;

use Dataphyre\Access\Exceptions\OAuthException;

final class JwksResolver {

	private static array $remote_cache=[];

	public static function resolve(string $algorithm, array $headers, array $config): string {
		$keys=self::keys($config);
		if($keys===[]){
			throw new OAuthException('OAuth/OpenID Connect JWKS configuration is missing.');
		}
		$kid=trim((string)($headers['kid'] ?? ''));
		$algorithm=strtoupper(trim($algorithm));
		foreach($keys as $jwk){
			if(!is_array($jwk)){
				continue;
			}
			if($kid!=='' && trim((string)($jwk['kid'] ?? ''))!==$kid){
				continue;
			}
			if($algorithm!=='' && isset($jwk['alg']) && strtoupper(trim((string)$jwk['alg']))!==$algorithm){
				continue;
			}
			return self::public_key_from_jwk($jwk);
		}
		if($kid!=='' && isset($config['jwks_url'])){
			unset(self::$remote_cache[(string)$config['jwks_url']]);
			$keys=self::keys($config);
			foreach($keys as $jwk){
				if(is_array($jwk) && trim((string)($jwk['kid'] ?? ''))===$kid){
					return self::public_key_from_jwk($jwk);
				}
			}
		}
		throw new OAuthException('Unable to resolve a JWKS public key for the received token.');
	}

	public static function keys(array $config): array {
		if(is_array($config['jwks'] ?? null)){
			$jwks=$config['jwks'];
			return is_array($jwks['keys'] ?? null) ? $jwks['keys'] : $jwks;
		}
		$jwks_url=trim((string)($config['jwks_url'] ?? ''));
		if($jwks_url===''){
			return [];
		}
		if(isset(self::$remote_cache[$jwks_url])){
			return self::$remote_cache[$jwks_url];
		}
		$http=new HttpClient(is_array($config['http'] ?? null) ? $config['http'] : []);
		$response=$http->send('GET', $jwks_url);
		$status=(int)($response['status'] ?? 0);
		if($status<200 || $status>=300){
			throw new OAuthException('Failed to fetch JWKS from '.$jwks_url);
		}
		$decoded=json_decode((string)($response['body'] ?? ''), true);
		if(!is_array($decoded)){
			throw new OAuthException('JWKS response is invalid JSON.');
		}
		$keys=is_array($decoded['keys'] ?? null) ? $decoded['keys'] : $decoded;
		self::$remote_cache[$jwks_url]=$keys;
		return $keys;
	}

	private static function public_key_from_jwk(array $jwk): string {
		if(isset($jwk['x5c'][0]) && is_string($jwk['x5c'][0]) && trim($jwk['x5c'][0])!==''){
			return self::pem_certificate((string)$jwk['x5c'][0]);
		}
		$kty=strtoupper(trim((string)($jwk['kty'] ?? '')));
		if($kty!=='RSA'){
			throw new OAuthException("Unsupported JWKS key type '{$kty}'.");
		}
		$n=(string)($jwk['n'] ?? '');
		$e=(string)($jwk['e'] ?? '');
		if($n==='' || $e===''){
			throw new OAuthException('JWKS RSA key is missing modulus or exponent.');
		}
		return self::rsa_public_key_from_modulus_exponent($n, $e);
	}

	private static function pem_certificate(string $certificate): string {
		$certificate=preg_replace('/\s+/', '', trim($certificate));
		return "-----BEGIN CERTIFICATE-----\n"
			.chunk_split($certificate, 64, "\n")
			."-----END CERTIFICATE-----\n";
	}

	private static function rsa_public_key_from_modulus_exponent(string $n, string $e): string {
		$modulus=self::base64url_decode($n);
		$exponent=self::base64url_decode($e);
		if($modulus===null || $exponent===null){
			throw new OAuthException('Unable to decode JWKS RSA modulus or exponent.');
		}
		$rsa_key=self::asn1_sequence(
			self::asn1_integer($modulus)
			.self::asn1_integer($exponent)
		);
		$public_key_info=self::asn1_sequence(
			self::asn1_sequence(
				"\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01"
				."\x05\x00"
			)
			.self::asn1_bit_string($rsa_key)
		);
		return "-----BEGIN PUBLIC KEY-----\n"
			.chunk_split(base64_encode($public_key_info), 64, "\n")
			."-----END PUBLIC KEY-----\n";
	}

	private static function base64url_decode(string $value): ?string {
		$remainder=strlen($value) % 4;
		if($remainder!==0){
			$value.=str_repeat('=', 4 - $remainder);
		}
		$decoded=base64_decode(strtr($value, '-_', '+/'), true);
		return $decoded===false ? null : $decoded;
	}

	private static function asn1_integer(string $value): string {
		if($value==='' || (ord($value[0]) & 0x80)!==0){
			$value="\x00".$value;
		}
		return "\x02".self::asn1_length(strlen($value)).$value;
	}

	private static function asn1_sequence(string $value): string {
		return "\x30".self::asn1_length(strlen($value)).$value;
	}

	private static function asn1_bit_string(string $value): string {
		return "\x03".self::asn1_length(strlen($value)+1)."\x00".$value;
	}

	private static function asn1_length(int $length): string {
		if($length<128){
			return chr($length);
		}
		$encoded='';
		while($length>0){
			$encoded=chr($length & 0xFF).$encoded;
			$length>>=8;
		}
		return chr(0x80 | strlen($encoded)).$encoded;
	}
}
