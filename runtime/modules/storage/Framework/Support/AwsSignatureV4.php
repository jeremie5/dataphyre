<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Storage\Support;

/**
 * Signs S3-compatible storage requests with AWS Signature Version 4.
 *
 * The signer builds canonical requests, signed header lists, credential scopes,
 * HMAC signing keys, and presigned query parameters for storage disks that use
 * AWS S3 or S3-compatible APIs. Session tokens are included when configured,
 * credentials are never returned, and presigned URLs are constrained by the
 * signed method, host, path, query, headers, and expiry.
 */
final class AwsSignatureV4 {

	/**
	 * Builds authorization headers for one signed HTTP request.
	 *
	 * Extra headers are lowercased and included in the canonical header list.
	 * The request body is SHA-256 hashed and signed with the configured region,
	 * service, access key, and secret key.
	 *
	 * @param string $method HTTP method such as GET, PUT, POST, or DELETE.
	 * @param string $url Absolute request URL.
	 * @param array{region?:string,service?:string,access_key?:string,secret_key?:string,session_token?:string} $config
	 * Signing config containing region, service, access_key, secret_key, and optional session_token.
	 * @param string $payload Raw request body bytes.
	 * @param array<string,string> $extraHeaders Additional headers that must participate in the signature.
	 * @return array<int,string> HTTP header lines including Authorization.
	 * @throws \RuntimeException When access key or secret key is missing.
	 */
	public static function headers(string $method, string $url, array $config, string $payload='', array $extraHeaders=[]): array {
		$region=(string)($config['region'] ?? 'us-east-1');
		$service=(string)($config['service'] ?? 's3');
		$accessKey=(string)($config['access_key'] ?? '');
		$secretKey=(string)($config['secret_key'] ?? '');
		if($accessKey==='' || $secretKey===''){
			throw new \RuntimeException('S3-compatible storage disk is missing access_key or secret_key.');
		}
		$timestamp=time();
		$amzDate=gmdate('Ymd\THis\Z', $timestamp);
		$date=gmdate('Ymd', $timestamp);
		$parts=parse_url($url);
		$host=(string)($parts['host'] ?? '');
		$path=(string)($parts['path'] ?? '/');
		$query=(string)($parts['query'] ?? '');
		$payloadHash=hash('sha256', $payload);
		$headers=array_change_key_case($extraHeaders, CASE_LOWER);
		$headers['host']=$host;
		$headers['x-amz-content-sha256']=$payloadHash;
		$headers['x-amz-date']=$amzDate;
		if(!empty($config['session_token'])){
			$headers['x-amz-security-token']=(string)$config['session_token'];
		}
		ksort($headers);
		$canonicalHeaders='';
		foreach($headers as $name=>$value){
			$canonicalHeaders.=$name.':'.trim((string)preg_replace('/\s+/', ' ', (string)$value))."\n";
		}
		$signedHeaders=implode(';', array_keys($headers));
		$canonicalRequest=strtoupper($method)."\n".self::canonicalUri($path)."\n".self::canonicalQuery($query)."\n".$canonicalHeaders."\n".$signedHeaders."\n".$payloadHash;
		$scope=$date.'/'.$region.'/'.$service.'/aws4_request';
		$stringToSign="AWS4-HMAC-SHA256\n".$amzDate."\n".$scope."\n".hash('sha256', $canonicalRequest);
		$signature=hash_hmac('sha256', $stringToSign, self::signingKey($secretKey, $date, $region, $service));
		$headers['authorization']='AWS4-HMAC-SHA256 Credential='.$accessKey.'/'.$scope.', SignedHeaders='.$signedHeaders.', Signature='.$signature;
		$out=[];
		foreach($headers as $name=>$value){
			$out[]=self::canonicalHeaderName($name).': '.$value;
		}
		return $out;
	}

	/**
	 * Builds a presigned URL using query-string Signature V4 parameters.
	 *
	 * Existing query parameters are preserved and included in canonical order.
	 * Expiration is clamped to AWS's one-week maximum and signed with an
	 * UNSIGNED-PAYLOAD marker for browser/download use. Header names included
	 * in `$headers` become part of the signature and must be sent by the caller
	 * using matching values.
	 *
	 * @param string $method HTTP method the URL will authorize.
	 * @param string $url Absolute object URL.
	 * @param array{region?:string,service?:string,access_key?:string,secret_key?:string,session_token?:string} $config
	 * Signing config containing region, service, access_key, secret_key, and optional session_token.
	 * @param int|\DateTimeInterface $expires Unix timestamp or DateTimeInterface expiry.
	 * @param array<string,string> $headers Headers that callers must send with the presigned request.
	 * @return string Presigned URL with canonical X-Amz-* query parameters.
	 * @throws \RuntimeException When access key or secret key is missing.
	 */
	public static function presignedUrl(string $method, string $url, array $config, int|\DateTimeInterface $expires, array $headers=[]): string {
		$region=(string)($config['region'] ?? 'us-east-1');
		$service=(string)($config['service'] ?? 's3');
		$accessKey=(string)($config['access_key'] ?? '');
		$secretKey=(string)($config['secret_key'] ?? '');
		if($accessKey==='' || $secretKey===''){
			throw new \RuntimeException('S3-compatible storage disk is missing access_key or secret_key.');
		}
		$expiresAt=$expires instanceof \DateTimeInterface ? $expires->getTimestamp() : (int)$expires;
		$ttl=max(1, min(604800, $expiresAt-time()));
		$timestamp=time();
		$amzDate=gmdate('Ymd\THis\Z', $timestamp);
		$date=gmdate('Ymd', $timestamp);
		$parts=parse_url($url);
		$host=(string)($parts['host'] ?? '');
		$path=(string)($parts['path'] ?? '/');
		$existingQuery=(string)($parts['query'] ?? '');
		$scope=$date.'/'.$region.'/'.$service.'/aws4_request';
		$signedHeaders=['host'=>$host];
		foreach(array_change_key_case($headers, CASE_LOWER) as $name=>$value){
			if(trim((string)$value)!==''){
				$signedHeaders[$name]=(string)$value;
			}
		}
		ksort($signedHeaders);
		$query=[
			'X-Amz-Algorithm'=>'AWS4-HMAC-SHA256',
			'X-Amz-Credential'=>$accessKey.'/'.$scope,
			'X-Amz-Date'=>$amzDate,
			'X-Amz-Expires'=>(string)$ttl,
			'X-Amz-SignedHeaders'=>implode(';', array_keys($signedHeaders)),
		];
		if(!empty($config['session_token'])){
			$query['X-Amz-Security-Token']=(string)$config['session_token'];
		}
		if($existingQuery!==''){
			parse_str($existingQuery, $existingParams);
			foreach($existingParams as $key=>$value){
				$query[(string)$key]=$value;
			}
		}
		$canonicalHeaders='';
		foreach($signedHeaders as $name=>$value){
			$canonicalHeaders.=$name.':'.trim((string)preg_replace('/\s+/', ' ', (string)$value))."\n";
		}
		$canonicalQuery=self::canonicalQuery(http_build_query($query));
		$canonicalRequest=strtoupper($method)."\n".self::canonicalUri($path)."\n".$canonicalQuery."\n".$canonicalHeaders."\n".implode(';', array_keys($signedHeaders))."\nUNSIGNED-PAYLOAD";
		$stringToSign="AWS4-HMAC-SHA256\n".$amzDate."\n".$scope."\n".hash('sha256', $canonicalRequest);
		$query['X-Amz-Signature']=hash_hmac('sha256', $stringToSign, self::signingKey($secretKey, $date, $region, $service));
		$base=(string)($parts['scheme'] ?? 'https').'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').$path;
		return $base.'?'.self::canonicalQuery(http_build_query($query));
	}

	/**
	 * Derives the binary Signature V4 signing key for a date, region, and service.
	 *
	 * The derived key is scoped to a single date, region, service, and
	 * `aws4_request` terminal step; callers should not persist it.
	 *
	 * @param string $secretKey AWS secret access key.
	 * @param string $date YYYYMMDD signing date.
	 * @param string $region AWS region.
	 * @param string $service AWS service name, normally s3.
	 * @return string Binary HMAC key for aws4_request.
	 */
	private static function signingKey(string $secretKey, string $date, string $region, string $service): string {
		$key=hash_hmac('sha256', $date, 'AWS4'.$secretKey, true);
		$key=hash_hmac('sha256', $region, $key, true);
		$key=hash_hmac('sha256', $service, $key, true);
		return hash_hmac('sha256', 'aws4_request', $key, true);
	}

	/**
	 * Canonicalizes a request path according to Signature V4 encoding rules.
	 *
	 * Each path segment is decoded once and rawurlencoded so already-escaped
	 * object keys sign consistently with S3-compatible canonical request rules.
	 *
	 * @param string $path URL path component.
	 * @return string Segment-encoded canonical URI.
	 */
	private static function canonicalUri(string $path): string {
		return implode('/', array_map(static fn(string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', $path)));
	}

	/**
	 * Sorts and encodes query parameters for Signature V4 canonical requests.
	 *
	 * Repeated keys are expanded, parameters are sorted after encoding, and empty
	 * query strings stay empty.
	 *
	 * @param string $query Raw query string.
	 * @return string Canonical query string.
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
	 * Converts lowercase canonical header names back to HTTP display form.
	 *
	 * Signing uses lowercase header names internally; this method only formats
	 * outbound header lines for transport.
	 *
	 * @param string $name Lowercase header name.
	 * @return string Hyphenated header name with capitalized segments.
	 */
	private static function canonicalHeaderName(string $name): string {
		return implode('-', array_map(static fn(string $part): string => ucfirst($part), explode('-', strtolower($name))));
	}
}
