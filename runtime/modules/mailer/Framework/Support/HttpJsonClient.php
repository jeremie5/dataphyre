<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mailer\Support;

/**
 * Performs small JSON-oriented HTTP requests for mailer integrations.
 *
 * The client prefers cURL when available and falls back to PHP streams so queue
 * workers and diagnostics can call provider APIs in minimal runtimes while still
 * receiving a consistent response array. It does not enforce provider allow-lists
 * or retry policies; callers choose trusted URLs and decide how failures are
 * surfaced or retried.
 */
final class HttpJsonClient {

	/**
	 * Sends an HTTP request and returns a normalized response array.
	 *
	 * Array payloads are JSON-encoded and receive a default Content-Type header
	 * unless the caller supplied one. Bodies are omitted for GET and HEAD even
	 * when a payload is present.
	 *
	 * Transport failures return status 0, an empty body, null JSON, and the best
	 * available error string instead of throwing. Non-2xx HTTP responses are
	 * returned with ok=false while preserving response headers and body text.
	 *
	 * @param string $method HTTP method.
	 * @param string $url Absolute provider endpoint URL.
	 * @param array<string, mixed>|string|null $payload JSON payload array, raw body string, or null.
	 * @param array<string|int, string> $headers Header map or preformatted header lines.
	 * @param int $timeout Request timeout in seconds.
	 * @return array{ok: bool, status: int, headers: array<int, string>, body: string, json: ?array, error: string}
	 */
	public static function request(string $method, string $url, array|string|null $payload=null, array $headers=[], int $timeout=15): array {
		$method=strtoupper(trim($method));
		$body=is_array($payload) ? (json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') : (string)($payload ?? '');
		$headerLines=self::headers($headers, $body!=='' && !self::hasHeader($headers, 'content-type') ? ['Content-Type'=>'application/json'] : []);
		if(function_exists('curl_init')){
			$ch=curl_init($url);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HEADER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
			if($body!=='' && $method!=='GET' && $method!=='HEAD'){
				curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
			}
			$response=curl_exec($ch);
			$error=curl_error($ch);
			$status=(int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$headerSize=(int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
			curl_close($ch);
			if($response===false){
				return ['ok'=>false, 'status'=>0, 'headers'=>[], 'body'=>'', 'json'=>null, 'error'=>$error];
			}
			$responseHeaders=substr((string)$response, 0, $headerSize);
			$responseBody=substr((string)$response, $headerSize);
			return self::response($status, $responseBody, $error, self::parseHeaders($responseHeaders));
		}
		$context=stream_context_create([
			'http'=>[
				'method'=>$method,
				'header'=>implode("\r\n", $headerLines)."\r\n",
				'content'=>($body!=='' && $method!=='GET' && $method!=='HEAD') ? $body : '',
				'timeout'=>$timeout,
				'ignore_errors'=>true,
			],
		]);
		$response=@file_get_contents($url, false, $context);
		$status=0;
		foreach($httpResponseHeader ?? [] as $line){
			if(preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $match)===1){
				$status=(int)$match[1];
			}
		}
		return self::response($status, is_string($response) ? $response : '', is_string($response) ? '' : 'HTTP request failed', $httpResponseHeader ?? []);
	}

	/**
	 * Builds the normalized response array returned by request().
	 *
	 * JSON decoding is opportunistic: invalid JSON does not make the response an
	 * error by itself, and callers can inspect the raw body when json is null.
	 *
	 * @param int $status HTTP status code, or 0 when the transport failed before a response.
	 * @param string $body Raw response body.
	 * @param string $error Transport error message when one was reported.
	 * @param array<int, string> $headers Response header lines.
	 * @return array{ok: bool, status: int, headers: array<int, string>, body: string, json: ?array, error: string}
	 */
	private static function response(int $status, string $body, string $error='', array $headers=[]): array {
		$json=json_decode($body, true);
		return [
			'ok'=>$status>=200 && $status<300,
			'status'=>$status,
			'headers'=>$headers,
			'body'=>$body,
			'json'=>is_array($json) ? $json : null,
			'error'=>$error,
		];
	}

	/**
	 * Extracts response header lines from a cURL header block.
	 *
	 * @param string $headers Raw header block returned before the response body.
	 * @return array<int, string> Non-empty header lines excluding HTTP status lines.
	 */
	private static function parseHeaders(string $headers): array {
		$lines=preg_split('/\r\n|\r|\n/', trim($headers)) ?: [];
		return array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line!=='' && stripos($line, 'HTTP/')!==0));
	}

	/**
	 * Merges default and caller headers into transport-ready header lines.
	 *
	 * @param array<string|int, string> $headers Caller header map or preformatted lines.
	 * @param array<string, string> $defaults Default headers applied before caller values.
	 * @return array<int, string> Header lines accepted by cURL and stream contexts.
	 */
	private static function headers(array $headers, array $defaults=[]): array {
		$merged=array_replace($defaults, $headers);
		$lines=[];
		foreach($merged as $key=>$value){
			if(is_int($key)){
				$line=trim((string)$value);
				if($line!==''){
					$lines[]=$line;
				}
				continue;
			}
			$key=trim((string)$key);
			if($key!==''){
				$lines[]=$key.': '.(string)$value;
			}
		}
		return $lines;
	}

	/**
	 * Checks whether a header set already contains a named header.
	 *
	 * @param array<string|int, string> $headers Header map or preformatted lines.
	 * @param string $name Case-insensitive header name to locate.
	 * @return bool True when the header is present.
	 */
	private static function hasHeader(array $headers, string $name): bool {
		$name=strtolower($name);
		foreach($headers as $key=>$value){
			$header=is_int($key) ? (string)$value : (string)$key;
			if(strtolower(trim(strtok($header, ':') ?: $header))===$name){
				return true;
			}
		}
		return false;
	}
}
