<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\OAuthClient;

use Dataphyre\Access\Exceptions\OAuthException;

/**
 * Resolves OAuth/OpenID Connect JWKS entries into PEM public keys.
 *
 * The resolver supports inline JWKS arrays and remote jwks_url documents, keeps
 * a process-local remote cache, refreshes once when a token kid is missing, and
 * converts x5c certificates or RSA modulus/exponent keys into PEM material.
 */
final class JwksResolver {

	private static array $remoteCache=[];

	/**
	 * Resolves the verification key for a token header and algorithm.
	 *
	 * Keys are filtered by kid when present and by alg when the JWK declares one.
	 * When a kid miss occurs against a remote JWKS URL, the cache is invalidated
	 * and fetched again before failing.
	 *
	 * @param string $algorithm JOSE algorithm expected for the token.
	 * @param array{kid?:string,alg?:string,typ?:string} $headers Decoded token header containing optional kid.
	 * @param array{jwks?:array{keys?:list<array<string,mixed>>}|list<array<string,mixed>>,jwks_url?:string,http?:array<string,mixed>} $config OAuth client configuration with inline jwks or remote jwks_url.
	 * @return string PEM certificate or public key.
	 * @throws OAuthException When no usable JWKS key can be resolved.
	 */
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
			return self::publicKeyFromJwk($jwk);
		}
		if($kid!=='' && isset($config['jwks_url'])){
			unset(self::$remoteCache[(string)$config['jwks_url']]);
			$keys=self::keys($config);
			foreach($keys as $jwk){
				if(is_array($jwk) && trim((string)($jwk['kid'] ?? ''))===$kid){
					return self::publicKeyFromJwk($jwk);
				}
			}
		}
		throw new OAuthException('Unable to resolve a JWKS public key for the received token.');
	}

	/**
	 * Loads JWKS key entries from inline config or a remote URL.
	 *
	 * Remote responses must be 2xx JSON. The decoded keys array is cached by URL
	 * for the current process to avoid repeated identity-provider fetches.
	 *
	 * @param array{jwks?:array{keys?:list<array<string,mixed>>}|list<array<string,mixed>>,jwks_url?:string,http?:array<string,mixed>} $config OAuth client configuration with jwks, jwks_url, and optional http settings.
	 * @return list<array<string,mixed>> JWK entries.
	 * @throws OAuthException When a remote JWKS request fails or returns invalid JSON.
	 */
	public static function keys(array $config): array {
		if(is_array($config['jwks'] ?? null)){
			$jwks=$config['jwks'];
			return is_array($jwks['keys'] ?? null) ? $jwks['keys'] : $jwks;
		}
		$jwksUrl=trim((string)($config['jwks_url'] ?? ''));
		if($jwksUrl===''){
			return [];
		}
		if(isset(self::$remoteCache[$jwksUrl])){
			return self::$remoteCache[$jwksUrl];
		}
		$http=new HttpClient(is_array($config['http'] ?? null) ? $config['http'] : []);
		$response=$http->send('GET', $jwksUrl);
		$status=(int)($response['status'] ?? 0);
		if($status<200 || $status>=300){
			throw new OAuthException('Failed to fetch JWKS from '.$jwksUrl);
		}
		$decoded=json_decode((string)($response['body'] ?? ''), true);
		if(!is_array($decoded)){
			throw new OAuthException('JWKS response is invalid JSON.');
		}
		$keys=is_array($decoded['keys'] ?? null) ? $decoded['keys'] : $decoded;
		self::$remoteCache[$jwksUrl]=$keys;
		return $keys;
	}

	/**
	 * Converts a supported JWK entry into PEM material.
	 *
	 * @param array{kty?:string,kid?:string,alg?:string,use?:string,x5c?:list<string>,n?:string,e?:string} $jwk JWK entry containing x5c certificate data or RSA n/e values.
	 * @return string PEM certificate or public key.
	 * @throws OAuthException When the key type or required RSA fields are unsupported.
	 */
	private static function publicKeyFromJwk(array $jwk): string {
		if(isset($jwk['x5c'][0]) && is_string($jwk['x5c'][0]) && trim($jwk['x5c'][0])!==''){
			return self::pemCertificate((string)$jwk['x5c'][0]);
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
		return self::rsaPublicKeyFromModulusExponent($n, $e);
	}

	/**
	 * Wraps an x5c certificate value in PEM certificate markers.
	 *
	 * @param string $certificate Base64 DER certificate body.
	 * @return string PEM certificate.
	 */
	private static function pemCertificate(string $certificate): string {
		$certificate=preg_replace('/\s+/', '', trim($certificate));
		return "-----BEGIN CERTIFICATE-----\n"
			.chunk_split($certificate, 64, "\n")
			."-----END CERTIFICATE-----\n";
	}

	/**
	 * Builds an RSA SubjectPublicKeyInfo PEM from base64url modulus and exponent.
	 *
	 * @param string $n Base64url-encoded RSA modulus.
	 * @param string $e Base64url-encoded RSA exponent.
	 * @return string PEM public key.
	 * @throws OAuthException When modulus or exponent cannot be decoded.
	 */
	private static function rsaPublicKeyFromModulusExponent(string $n, string $e): string {
		$modulus=self::base64urlDecode($n);
		$exponent=self::base64urlDecode($e);
		if($modulus===null || $exponent===null){
			throw new OAuthException('Unable to decode JWKS RSA modulus or exponent.');
		}
		$rsaKey=self::asn1Sequence(
			self::asn1Integer($modulus)
			.self::asn1Integer($exponent)
		);
		$publicKeyInfo=self::asn1Sequence(
			self::asn1Sequence(
				"\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01"
				."\x05\x00"
			)
			.self::asn1BitString($rsaKey)
		);
		return "-----BEGIN PUBLIC KEY-----\n"
			.chunk_split(base64_encode($publicKeyInfo), 64, "\n")
			."-----END PUBLIC KEY-----\n";
	}

	/**
	 * Decodes unpadded base64url JOSE values.
	 *
	 * @param string $value Base64url text.
	 * @return ?string Decoded bytes, or null on failure.
	 */
	private static function base64urlDecode(string $value): ?string {
		$remainder=strlen($value) % 4;
		if($remainder!==0){
			$value.=str_repeat('=', 4 - $remainder);
		}
		$decoded=base64_decode(strtr($value, '-_', '+/'), true);
		return $decoded===false ? null : $decoded;
	}

	/**
	 * Encodes a DER ASN.1 INTEGER value.
	 *
	 * @param string $value Unsigned integer bytes.
	 * @return string DER INTEGER bytes.
	 */
	private static function asn1Integer(string $value): string {
		if($value==='' || (ord($value[0]) & 0x80)!==0){
			$value="\x00".$value;
		}
		return "\x02".self::asn1Length(strlen($value)).$value;
	}

	/**
	 * Encodes a DER ASN.1 SEQUENCE value.
	 *
	 * @param string $value Encoded child bytes.
	 * @return string DER SEQUENCE bytes.
	 */
	private static function asn1Sequence(string $value): string {
		return "\x30".self::asn1Length(strlen($value)).$value;
	}

	/**
	 * Encodes a DER ASN.1 BIT STRING with zero unused bits.
	 *
	 * @param string $value Encoded bit-string payload.
	 * @return string DER BIT STRING bytes.
	 */
	private static function asn1BitString(string $value): string {
		return "\x03".self::asn1Length(strlen($value)+1)."\x00".$value;
	}

	/**
	 * Encodes a DER length field.
	 *
	 * @param int $length Payload length in bytes.
	 * @return string DER short or long-form length bytes.
	 */
	private static function asn1Length(int $length): string {
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
