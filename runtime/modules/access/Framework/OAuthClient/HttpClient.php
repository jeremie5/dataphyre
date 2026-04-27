<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\OAuthClient;

use Dataphyre\Access\Exceptions\OAuthException;

final class HttpClient {

	private array $config;

	public function __construct(array $config=[]){
		$this->config=$config;
	}

	public function send(
		string $method,
		string $url,
		array|string|null $body=null,
		array $headers=[],
		array $query=[]
	): array {
		$method=strtoupper(trim($method));
		$url=$this->append_query($url, $query);
		$body_payload=$this->normalize_body($body, $headers);
		return function_exists('curl_init')
			? $this->send_with_curl($method, $url, $body_payload, $headers)
			: $this->send_with_stream($method, $url, $body_payload, $headers);
	}

	private function send_with_curl(string $method, string $url, ?string $body, array $headers): array {
		$ch=curl_init($url);
		if($ch===false){
			throw new OAuthException('Unable to initialize OAuth HTTP client.');
		}
		$response_headers=[];
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER=>true,
			CURLOPT_CUSTOMREQUEST=>$method,
			CURLOPT_HTTPHEADER=>$this->flatten_headers($headers),
			CURLOPT_FOLLOWLOCATION=>false,
			CURLOPT_TIMEOUT=>$this->timeout(),
			CURLOPT_CONNECTTIMEOUT=>$this->connect_timeout(),
			CURLOPT_USERAGENT=>$this->user_agent(),
			CURLOPT_HEADERFUNCTION=>static function($curl, string $header) use (&$response_headers): int {
				$length=strlen($header);
				$header=trim($header);
				if($header==='' || str_contains($header, ':')===false){
					return $length;
				}
				[$name, $value]=explode(':', $header, 2);
				$response_headers[strtolower(trim($name))]=trim($value);
				return $length;
			},
		]);
		if($body!==null && $body!==''){
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		$body_response=curl_exec($ch);
		$status=(int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if($body_response===false){
			$error=curl_error($ch);
			curl_close($ch);
			throw new OAuthException('OAuth HTTP request failed: '.$error);
		}
		curl_close($ch);
		return [
			'status'=>$status,
			'headers'=>$response_headers,
			'body'=>(string)$body_response,
		];
	}

	private function send_with_stream(string $method, string $url, ?string $body, array $headers): array {
		$headers=array_replace([
			'User-Agent'=>$this->user_agent(),
		], $headers);
		$context=stream_context_create([
			'http'=>[
				'method'=>$method,
				'header'=>implode("\r\n", $this->flatten_headers($headers)),
				'content'=>$body ?? '',
				'timeout'=>$this->timeout(),
				'ignore_errors'=>true,
				'follow_location'=>0,
			],
		]);
		$body_response=@file_get_contents($url, false, $context);
		if($body_response===false){
			throw new OAuthException('OAuth HTTP request failed for '.$url);
		}
		$raw_headers=$http_response_header ?? [];
		$status=0;
		$response_headers=[];
		foreach($raw_headers as $index=>$header){
			if($index===0 && preg_match('/\s(\d{3})\s/', $header, $matches)===1){
				$status=(int)$matches[1];
				continue;
			}
			if(str_contains($header, ':')===false){
				continue;
			}
			[$name, $value]=explode(':', $header, 2);
			$response_headers[strtolower(trim($name))]=trim($value);
		}
		return [
			'status'=>$status,
			'headers'=>$response_headers,
			'body'=>(string)$body_response,
		];
	}

	private function append_query(string $url, array $query): string {
		if($query===[]){
			return $url;
		}
		$query_string=http_build_query($query, '', '&', PHP_QUERY_RFC3986);
		if($query_string===''){
			return $url;
		}
		return str_contains($url, '?')
			? $url.'&'.$query_string
			: $url.'?'.$query_string;
	}

	private function normalize_body(array|string|null $body, array &$headers): ?string {
		if($body===null){
			return null;
		}
		if(is_string($body)){
			return $body;
		}
		$content_type=$this->header_value($headers, 'Content-Type');
		if($content_type===null || str_starts_with(strtolower($content_type), 'application/x-www-form-urlencoded')){
			$headers['Content-Type']='application/x-www-form-urlencoded';
			return http_build_query($body, '', '&', PHP_QUERY_RFC3986);
		}
		if(str_starts_with(strtolower($content_type), 'application/json')){
			return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
		}
		return http_build_query($body, '', '&', PHP_QUERY_RFC3986);
	}

	private function header_value(array $headers, string $name): ?string {
		$needle=strtolower(trim($name));
		foreach($headers as $header_name=>$value){
			if(strtolower(trim((string)$header_name))===$needle){
				return (string)$value;
			}
		}
		return null;
	}

	private function flatten_headers(array $headers): array {
		$headers=array_replace([
			'Accept'=>'application/json, application/x-www-form-urlencoded;q=0.9, */*;q=0.1',
			'User-Agent'=>$this->user_agent(),
		], $headers);
		$flattened=[];
		foreach($headers as $name=>$value){
			$flattened[]=$name.': '.$value;
		}
		return $flattened;
	}

	private function timeout(): int {
		return max(1, (int)($this->config['timeout'] ?? 10));
	}

	private function connect_timeout(): int {
		return max(1, (int)($this->config['connect_timeout'] ?? min(5, $this->timeout())));
	}

	private function user_agent(): string {
		$user_agent=trim((string)($this->config['user_agent'] ?? 'Dataphyre OAuth Client/1.0'));
		return $user_agent!=='' ? $user_agent : 'Dataphyre OAuth Client/1.0';
	}
}
