<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mailer\Support;

/**
 * Creates AWS Signature Version 4 headers for mailer HTTP integrations.
 *
 * The signer canonicalizes URI, query parameters, headers, and payload hash so
 * SES-style requests can be authenticated without pulling in the full AWS SDK.
 * It only produces headers; callers still own credential sourcing, endpoint
 * selection, request retries, and provider-specific response handling.
 */
final class AwsSignatureV4 {

	/**
	 * Builds signed headers for one AWS request.
	 *
	 * The returned header names are title-cased for transport clients, while the
	 * signature itself is calculated from lowercase canonical header names as
	 * required by SigV4.
	 *
	 * The URL is parsed for host, path, and query components but is not validated
	 * as an AWS endpoint. Empty credentials or region/service strings are signed
	 * as supplied, allowing upstream configuration validation to decide whether a
	 * request should be attempted.
	 *
	 * @param string $method HTTP method.
	 * @param string $url Absolute AWS endpoint URL.
	 * @param string $region AWS region scope.
	 * @param string $service AWS service scope, such as ses.
	 * @param string $accessKey AWS access key id.
	 * @param string $secretKey AWS secret access key.
	 * @param string $payload Raw request body used for the SHA-256 payload hash.
	 * @param ?string $sessionToken Optional STS session token.
	 * @param ?int $timestamp Optional Unix timestamp for deterministic tests.
	 * @return array<string, string> HTTP headers including Authorization and x-amz-* fields.
	 */
	public static function headers(
		string $method,
		string $url,
		string $region,
		string $service,
		string $accessKey,
		string $secretKey,
		string $payload,
		?string $sessionToken=null,
		?int $timestamp=null
	): array {
		$timestamp=$timestamp ?? time();
		$amzDate=gmdate('Ymd\THis\Z', $timestamp);
		$date=gmdate('Ymd', $timestamp);
		$parts=parse_url($url);
		$host=(string)($parts['host'] ?? '');
		$path=(string)($parts['path'] ?? '/');
		if($path===''){
			$path='/';
		}
		$query=(string)($parts['query'] ?? '');
		$payloadHash=hash('sha256', $payload);
		$headers=[
			'content-type'=>'application/json',
			'host'=>$host,
			'x-amz-content-sha256'=>$payloadHash,
			'x-amz-date'=>$amzDate,
		];
		if($sessionToken!==null && trim($sessionToken)!==''){
			$headers['x-amz-security-token']=$sessionToken;
		}
		ksort($headers);
		$canonicalHeaders='';
		foreach($headers as $name=>$value){
			$canonicalHeaders.=$name.':'.self::normalizeHeaderValue((string)$value)."\n";
		}
		$signedHeaders=implode(';', array_keys($headers));
		$canonicalRequest=strtoupper($method)."\n"
			.self::canonicalUri($path)."\n"
			.self::canonicalQuery($query)."\n"
			.$canonicalHeaders."\n"
			.$signedHeaders."\n"
			.$payloadHash;
		$scope=$date.'/'.$region.'/'.$service.'/aws4_request';
		$stringToSign="AWS4-HMAC-SHA256\n".$amzDate."\n".$scope."\n".hash('sha256', $canonicalRequest);
		$signingKey=self::signingKey($secretKey, $date, $region, $service);
		$signature=hash_hmac('sha256', $stringToSign, $signingKey);
		$headers['authorization']='AWS4-HMAC-SHA256 Credential='.$accessKey.'/'.$scope.', SignedHeaders='.$signedHeaders.', Signature='.$signature;
		$out=[];
		foreach($headers as $name=>$value){
			$out[self::canonicalHeaderName($name)]=$value;
		}
		return $out;
	}

	/**
	 * Derives the binary AWS SigV4 signing key.
	 *
	 * @param string $secretKey AWS secret access key.
	 * @param string $date Scope date in YYYYMMDD format.
	 * @param string $region AWS region.
	 * @param string $service AWS service name.
	 * @return string Binary signing key for aws4_request.
	 */
	private static function signingKey(string $secretKey, string $date, string $region, string $service): string {
		$key=hash_hmac('sha256', $date, 'AWS4'.$secretKey, true);
		$key=hash_hmac('sha256', $region, $key, true);
		$key=hash_hmac('sha256', $service, $key, true);
		return hash_hmac('sha256', 'aws4_request', $key, true);
	}

	/**
	 * Normalizes a request path for the canonical request.
	 *
	 * @param string $path Parsed URL path.
	 * @return string Slash-prefixed URI with each segment rawurlencoded once.
	 */
	private static function canonicalUri(string $path): string {
		$segments=explode('/', $path);
		$encoded=array_map(static fn(string $segment): string => rawurlencode(rawurldecode($segment)), $segments);
		$uri=implode('/', $encoded);
		return str_starts_with($uri, '/') ? $uri : '/'.$uri;
	}

	/**
	 * Sorts and encodes query parameters for the canonical request.
	 *
	 * Repeated query keys are preserved as multiple sorted pairs after PHP query
	 * parsing. Provider-specific canonicalization quirks should be handled before
	 * signing by constructing the URL exactly as it will be sent.
	 *
	 * @param string $query Raw URL query string.
	 * @return string RFC3986-encoded sorted query string.
	 */
	private static function canonicalQuery(string $query): string {
		if($query===''){
			return '';
		}
		parse_str($query, $params);
		ksort($params);
		$pairs=[];
		foreach($params as $key=>$value){
			foreach((array)$value as $entry){
				$pairs[]=rawurlencode((string)$key).'='.rawurlencode((string)$entry);
			}
		}
		sort($pairs);
		return implode('&', $pairs);
	}

	/**
	 * Collapses header whitespace according to SigV4 canonicalization rules.
	 *
	 * @param string $value Header value before signing.
	 * @return string Trimmed value with sequential whitespace collapsed.
	 */
	private static function normalizeHeaderValue(string $value): string {
		return trim((string)preg_replace('/\s+/', ' ', $value));
	}

	/**
	 * Converts lowercase canonical header names to transport-friendly casing.
	 *
	 * @param string $name Lowercase header name.
	 * @return string Header name with each hyphen-separated part capitalized.
	 */
	private static function canonicalHeaderName(string $name): string {
		return implode('-', array_map(
			static fn(string $part): string => ucfirst($part),
			explode('-', strtolower($name))
		));
	}
}
