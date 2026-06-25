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
 * Sends OAuth provider HTTP requests with cURL or stream fallback.
 *
 * The client normalizes query parameters, body encoding, default headers,
 * timeouts, and user-agent handling so token, discovery, and userinfo calls
 * receive the same response shape across PHP runtimes.
 */
final class HttpClient {

	private array $config;

	/**
	 * Stores transport options for OAuth provider requests.
	 *
	 * @param array<string, mixed> $config Optional timeout, connect_timeout, and user_agent values.
	 */
	public function __construct(array $config=[]){
		$this->config=$config;
	}

	/**
	 * Sends an OAuth HTTP request.
	 *
	 * Array bodies default to application/x-www-form-urlencoded unless a JSON
	 * Content-Type is already present. Redirect following is disabled to avoid
	 * hiding provider misconfiguration from login flows.
	 *
	 * @param string $method HTTP method.
	 * @param string $url Absolute OAuth endpoint URL.
	 * @param array<string, mixed>|string|null $body Form data, JSON data, raw body, or null.
	 * @param array<string, string> $headers Request headers.
	 * @param array<string, mixed> $query Query parameters appended with RFC3986 encoding.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 * @throws OAuthException When the transport cannot initialize or complete the request.
	 */
	public function send(
		string $method,
		string $url,
		array|string|null $body=null,
		array $headers=[],
		array $query=[]
	): array {
		$method=strtoupper(trim($method));
		$url=$this->appendQuery($url, $query);
		$bodyPayload=$this->normalizeBody($body, $headers);
		return function_exists('curl_init')
			? $this->sendWithCurl($method, $url, $bodyPayload, $headers)
			: $this->sendWithStream($method, $url, $bodyPayload, $headers);
	}

	/**
	 * Sends the request through cURL.
	 *
	 * @param string $method HTTP method.
	 * @param string $url Fully resolved endpoint URL.
	 * @param ?string $body Encoded request body.
	 * @param array<string, string> $headers Request headers.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 * @throws OAuthException When cURL initialization or execution fails.
	 */
	private function sendWithCurl(string $method, string $url, ?string $body, array $headers): array {
		$ch=curl_init($url);
		if($ch===false){
			throw new OAuthException('Unable to initialize OAuth HTTP client.');
		}
		$responseHeaders=[];
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER=>true,
			CURLOPT_CUSTOMREQUEST=>$method,
			CURLOPT_HTTPHEADER=>$this->flattenHeaders($headers),
			CURLOPT_FOLLOWLOCATION=>false,
			CURLOPT_TIMEOUT=>$this->timeout(),
			CURLOPT_CONNECTTIMEOUT=>$this->connectTimeout(),
			CURLOPT_USERAGENT=>$this->userAgent(),
			CURLOPT_HEADERFUNCTION=>static function($curl, string $header) use (&$responseHeaders): int {
				$length=strlen($header);
				$header=trim($header);
				if($header==='' || str_contains($header, ':')===false){
					return $length;
				}
				[$name, $value]=explode(':', $header, 2);
				$responseHeaders[strtolower(trim($name))]=trim($value);
				return $length;
			},
		]);
		if($body!==null && $body!==''){
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		$bodyResponse=curl_exec($ch);
		$status=(int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if($bodyResponse===false){
			$error=curl_error($ch);
			curl_close($ch);
			throw new OAuthException('OAuth HTTP request failed: '.$error);
		}
		curl_close($ch);
		return [
			'status'=>$status,
			'headers'=>$responseHeaders,
			'body'=>(string)$bodyResponse,
		];
	}

	/**
	 * Sends the request through PHP stream contexts when cURL is unavailable.
	 *
	 * @param string $method HTTP method.
	 * @param string $url Fully resolved endpoint URL.
	 * @param ?string $body Encoded request body.
	 * @param array<string, string> $headers Request headers.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 * @throws OAuthException When file_get_contents cannot complete the request.
	 */
	private function sendWithStream(string $method, string $url, ?string $body, array $headers): array {
		$headers=array_replace([
			'User-Agent'=>$this->userAgent(),
		], $headers);
		$context=stream_context_create([
			'http'=>[
				'method'=>$method,
				'header'=>implode("\r\n", $this->flattenHeaders($headers)),
				'content'=>$body ?? '',
				'timeout'=>$this->timeout(),
				'ignore_errors'=>true,
				'follow_location'=>0,
			],
		]);
		$bodyResponse=@file_get_contents($url, false, $context);
		if($bodyResponse===false){
			throw new OAuthException('OAuth HTTP request failed for '.$url);
		}
		$rawHeaders=$httpResponseHeader ?? [];
		$status=0;
		$responseHeaders=[];
		foreach($rawHeaders as $index=>$header){
			if($index===0 && preg_match('/\s(\d{3})\s/', $header, $matches)===1){
				$status=(int)$matches[1];
				continue;
			}
			if(str_contains($header, ':')===false){
				continue;
			}
			[$name, $value]=explode(':', $header, 2);
			$responseHeaders[strtolower(trim($name))]=trim($value);
		}
		return [
			'status'=>$status,
			'headers'=>$responseHeaders,
			'body'=>(string)$bodyResponse,
		];
	}

	/**
	 * Appends query parameters to an endpoint URL.
	 *
	 * @param string $url Base endpoint URL.
	 * @param array<string, mixed> $query Query parameters.
	 * @return string URL with encoded query string appended.
	 */
	private function appendQuery(string $url, array $query): string {
		if($query===[]){
			return $url;
		}
		$queryString=http_build_query($query, '', '&', PHP_QUERY_RFC3986);
		if($queryString===''){
			return $url;
		}
		return str_contains($url, '?')
			? $url.'&'.$queryString
			: $url.'?'.$queryString;
	}

	/**
	 * Encodes a request body and updates Content-Type when needed.
	 *
	 * @param array<string, mixed>|string|null $body Caller body value.
	 * @param array<string, string> $headers Mutable request headers.
	 * @return ?string Encoded body string, or null when no body should be sent.
	 */
	private function normalizeBody(array|string|null $body, array &$headers): ?string {
		if($body===null){
			return null;
		}
		if(is_string($body)){
			return $body;
		}
		$contentType=$this->headerValue($headers, 'Content-Type');
		if($contentType===null || str_starts_with(strtolower($contentType), 'application/x-www-form-urlencoded')){
			$headers['Content-Type']='application/x-www-form-urlencoded';
			return http_build_query($body, '', '&', PHP_QUERY_RFC3986);
		}
		if(str_starts_with(strtolower($contentType), 'application/json')){
			return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
		}
		return http_build_query($body, '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * Reads a header value case-insensitively from a header map.
	 *
	 * @param array<string, string> $headers Header map.
	 * @param string $name Header name to read.
	 * @return ?string Header value when present.
	 */
	private function headerValue(array $headers, string $name): ?string {
		$needle=strtolower(trim($name));
		foreach($headers as $headerName=>$value){
			if(strtolower(trim((string)$headerName))===$needle){
				return (string)$value;
			}
		}
		return null;
	}

	/**
	 * Converts header maps into transport header lines with OAuth defaults.
	 *
	 * @param array<string, string> $headers Caller headers.
	 * @return array<int, string> Header lines for cURL or stream context use.
	 */
	private function flattenHeaders(array $headers): array {
		$headers=array_replace([
			'Accept'=>'application/json, application/x-www-form-urlencoded;q=0.9, */*;q=0.1',
			'User-Agent'=>$this->userAgent(),
		], $headers);
		$flattened=[];
		foreach($headers as $name=>$value){
			$flattened[]=$name.': '.$value;
		}
		return $flattened;
	}

	/**
	 * Returns the request timeout in seconds.
	 *
	 * @return int Timeout clamped to at least one second.
	 */
	private function timeout(): int {
		return max(1, (int)($this->config['timeout'] ?? 10));
	}

	/**
	 * Returns the connection timeout in seconds.
	 *
	 * @return int Connect timeout clamped to at least one second.
	 */
	private function connectTimeout(): int {
		return max(1, (int)($this->config['connect_timeout'] ?? min(5, $this->timeout())));
	}

	/**
	 * Returns the configured OAuth client user agent.
	 *
	 * @return string Non-empty user-agent string.
	 */
	private function userAgent(): string {
		$userAgent=trim((string)($this->config['user_agent'] ?? 'Dataphyre OAuth Client/1.0'));
		return $userAgent!=='' ? $userAgent : 'Dataphyre OAuth Client/1.0';
	}
}
