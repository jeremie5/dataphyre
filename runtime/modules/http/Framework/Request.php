<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Http;

final class Request {

	private string $method;
	private string $path;
	private array $query;
	private array $body;
	private array $cookies;
	private array $server;
	private array $headers;
	private array $route_parameters;
	private array $attributes=[];

	private function __construct(
		string $method,
		string $path,
		array $query,
		array $body,
		array $cookies,
		array $server,
		array $headers,
		array $route_parameters
	){
		$this->method=$method;
		$this->path=$path;
		$this->query=$query;
		$this->body=$body;
		$this->cookies=$cookies;
		$this->server=$server;
		$this->headers=$headers;
		$this->route_parameters=$route_parameters;
	}

	public static function capture(array $route_parameters=[]): self {
		return new self(
			strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
			self::detect_path(),
			$_GET,
			self::capture_body(),
			$_COOKIE,
			$_SERVER,
			self::capture_headers(),
			$route_parameters
		);
	}

	public static function create(
		string $method,
		string $path,
		array $query=[],
		array $body=[],
		array $cookies=[],
		array $server=[],
		array $headers=[],
		array $route_parameters=[],
		array $attributes=[]
	): self {
		$request=new self(
			strtoupper(trim($method)) ?: 'GET',
			self::normalize_path($path),
			$query,
			$body,
			$cookies,
			$server,
			self::normalize_headers($headers),
			$route_parameters
		);
		if($attributes!==[]){
			$request->mergeAttributes($attributes);
		}
		return $request;
	}

	public function method(): string {
		return $this->method;
	}

	public function path(): string {
		return $this->path;
	}

	public function route_parameters(): array {
		return $this->route_parameters;
	}

	public function query(?string $key=null, mixed $default=null): mixed {
		if($key===null){
			return $this->query;
		}
		return $this->query[$key] ?? $default;
	}

	public function input(?string $key=null, mixed $default=null): mixed {
		if($key===null){
			return $this->body;
		}
		return $this->body[$key] ?? $default;
	}

	public function cookie(?string $key=null, mixed $default=null): mixed {
		if($key===null){
			return $this->cookies;
		}
		return $this->cookies[$key] ?? $default;
	}

	public function header(string $name, mixed $default=null): mixed {
		$key=strtolower(str_replace('-', '_', trim($name)));
		return $this->headers[$key] ?? $default;
	}

	public function headers(): array {
		return $this->headers;
	}

	public function server(?string $key=null, mixed $default=null): mixed {
		if($key===null){
			return $this->server;
		}
		return $this->server[$key] ?? $default;
	}

	public function attributes(): array {
		return $this->attributes;
	}

	public function attribute(?string $key=null, mixed $default=null): mixed {
		if($key===null){
			return $this->attributes;
		}
		return $this->attributes[$key] ?? $default;
	}

	public function setAttribute(string $key, mixed $value): self {
		$key=trim($key);
		if($key!==''){
			$this->attributes[$key]=$value;
		}
		return $this;
	}

	public function mergeAttributes(array $attributes): self {
		foreach($attributes as $key=>$value){
			if(!is_string($key)){
				continue;
			}
			$this->setAttribute($key, $value);
		}
		return $this;
	}

	private static function detect_path(): string {
		$path=(string)($_GET['uri'] ?? '');
		if($path===''){
			$path=(string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
		}
		return self::normalize_path($path);
	}

	private static function capture_body(): array {
		if(is_array($_POST) && $_POST!==[]){
			return $_POST;
		}
		$raw=@file_get_contents('php://input');
		if(!is_string($raw) || trim($raw)===''){
			return [];
		}
		$decoded=json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}

	private static function capture_headers(): array {
		$headers=[];
		foreach($_SERVER as $key=>$value){
			if(str_starts_with($key, 'HTTP_')===false){
				continue;
			}
			$normalized=strtolower(substr($key, 5));
			$headers[$normalized]=$value;
		}
		if(isset($_SERVER['CONTENT_TYPE'])){
			$headers['content_type']=$_SERVER['CONTENT_TYPE'];
		}
		if(isset($_SERVER['CONTENT_LENGTH'])){
			$headers['content_length']=$_SERVER['CONTENT_LENGTH'];
		}
		if(isset($_SERVER['PHP_AUTH_USER'])){
			$headers['php_auth_user']=$_SERVER['PHP_AUTH_USER'];
		}
		if(isset($_SERVER['PHP_AUTH_PW'])){
			$headers['php_auth_pw']=$_SERVER['PHP_AUTH_PW'];
		}
		if(isset($headers['authorization'])===false){
			foreach(['REDIRECT_HTTP_AUTHORIZATION', 'Authorization', 'HTTP_AUTHORIZATION'] as $key){
				if(isset($_SERVER[$key]) && is_string($_SERVER[$key]) && trim($_SERVER[$key])!==''){
					$headers['authorization']=$_SERVER[$key];
					break;
				}
			}
		}
		return $headers;
	}

	private static function normalize_path(string $path): string {
		$path='/'.trim((string)$path, '/');
		return $path==='/' ? '/' : rtrim($path, '/');
	}

	private static function normalize_headers(array $headers): array {
		$normalized=[];
		foreach($headers as $name=>$value){
			if(!is_string($name)){
				continue;
			}
			$key=strtolower(str_replace('-', '_', trim($name)));
			if($key===''){
				continue;
			}
			$normalized[$key]=$value;
		}
		return $normalized;
	}
}
