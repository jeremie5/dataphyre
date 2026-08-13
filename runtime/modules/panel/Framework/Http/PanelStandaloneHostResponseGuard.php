<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Normalizes untrusted downstream responses at the standalone-host boundary.
 *
 * Hop-by-hop and connection-nominated headers are removed, malformed headers
 * fail closed, redirects remain inside the mount unless explicitly approved,
 * and HEAD/no-content semantics are applied without materializing streams.
 */
final class PanelStandaloneHostResponseGuard {
	private const HOP_BY_HOP=[
		'connection'=>true,
		'keep-alive'=>true,
		'proxy-authenticate'=>true,
		'proxy-authorization'=>true,
		'te'=>true,
		'trailer'=>true,
		'transfer-encoding'=>true,
		'upgrade'=>true,
	];

	/**
	 * @param array<string,string|array<int,string>> $securityHeaders
	 */
	public function __construct(
		private readonly string $prefix,
		private readonly array $securityHeaders=[],
		private readonly mixed $redirectValidator=null,
	){}

	public function normalize(
		mixed $value,
		\Dataphyre\Http\Request $request,
		PanelStandaloneHostContext $context,
		bool $asset=false,
	): \Dataphyre\Http\Response {
		$response=$this->response($value);
		if($response->status<200 || $response->status>599){
			throw new PanelStandaloneHostException('invalid_response_status', 500, 'The Panel handler returned an invalid HTTP status.');
		}
		$headers=$this->headers($response->headers);
		$headers=$this->applySecurityHeaders($headers, $context->requestId(), $asset);
		$this->validateRedirect($response->status, $headers, $context);
		$body=$response->body;
		$stream=$response->stream;
		$method=$context->method();
		if($method==='HEAD'){
			if(!$this->hasHeader($headers, 'content-length') && !is_resource($stream)){
				$headers['Content-Length']=(string)strlen($body);
			}
			$body='';
			$stream=null;
		}
		if($response->status===204 || $response->status===304){
			$body='';
			$stream=null;
			$headers=$this->withoutHeaders($headers, ['content-type','content-length','content-disposition']);
		}
		$normalized=new \Dataphyre\Http\Response($body, $response->status, $headers);
		$normalized->stream=$stream;
		return $normalized;
	}

	private function response(mixed $value): \Dataphyre\Http\Response {
		if($value instanceof \Dataphyre\Http\Response){
			$copy=new \Dataphyre\Http\Response($value->body, $value->status, $value->headers);
			$copy->stream=$value->stream;
			return $copy;
		}
		if($value instanceof PanelPageResult){
			return new \Dataphyre\Http\Response($value->content(), $value->status(), $value->headers());
		}
		return \Dataphyre\Http\Response::normalize($value, 'html');
	}

	/**
	 * @param array<string,mixed> $headers
	 * @return array<string,string|array<int,string>>
	 */
	private function headers(array $headers): array {
		$nominated=[];
		foreach($headers as $name=>$value){
			if(is_string($name) && strtolower(trim($name))==='connection'){
				foreach($this->lines($value) as $line){
					foreach(explode(',', $line) as $candidate){
						$candidate=strtolower(trim($candidate));
						if($candidate!==''){
							$nominated[$candidate]=true;
						}
					}
				}
			}
		}
		$normalized=[];
		$names=[];
		foreach($headers as $name=>$value){
			if(!is_string($name) || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name)!==1){
				throw new PanelStandaloneHostException('invalid_response_header', 500, 'The Panel handler returned an invalid response header.');
			}
			$lower=strtolower($name);
			if(isset(self::HOP_BY_HOP[$lower]) || isset($nominated[$lower])){
				continue;
			}
			$lines=$this->lines($value);
			if($lines===[]){
				continue;
			}
			foreach($lines as $line){
				if(strlen($line)>16384 || preg_match('/[\r\n\x00]/', $line)===1){
					throw new PanelStandaloneHostException('invalid_response_header', 500, 'The Panel handler returned an invalid response header.');
				}
			}
			$canonical=$names[$lower] ??= $this->canonicalName($name);
			if($lower==='set-cookie'){
				$current=$normalized[$canonical] ?? [];
				$current=is_array($current) ? $current : [$current];
				$normalized[$canonical]=[...$current,...$lines];
				continue;
			}
			$normalized[$canonical]=is_array($value) ? $lines : $lines[0];
		}
		return $normalized;
	}

	/** @return list<string> */
	private function lines(mixed $value): array {
		$values=is_array($value) ? $value : [$value];
		$lines=[];
		foreach($values as $item){
			if(!is_string($item) && !is_numeric($item)){
				throw new PanelStandaloneHostException('invalid_response_header', 500, 'The Panel handler returned an invalid response header.');
			}
			$lines[]=(string)$item;
		}
		return $lines;
	}

	/**
	 * @param array<string,string|array<int,string>> $headers
	 * @return array<string,string|array<int,string>>
	 */
	private function applySecurityHeaders(array $headers, string $requestId, bool $asset): array {
		$defaults=[
			'X-Content-Type-Options'=>'nosniff',
			'Referrer-Policy'=>'same-origin',
			'X-Frame-Options'=>'SAMEORIGIN',
		];
		foreach($defaults as $name=>$value){
			if(!$this->hasHeader($headers, strtolower($name))){
				$headers[$name]=$value;
			}
		}
		foreach($this->headers($this->securityHeaders) as $name=>$value){
			$headers=$this->setHeader($headers, $name, $value);
		}
		$headers=$this->setHeader($headers, 'X-Correlation-ID', $requestId);
		$headers=$this->setHeader($headers, 'X-Dataphyre-Panel-Host', 'standalone');
		if(!$asset){
			$headers=$this->setHeader($headers, 'Cache-Control', 'no-store');
		}
		return $headers;
	}

	/**
	 * @param array<string,string|array<int,string>> $headers
	 */
	private function validateRedirect(int $status, array $headers, PanelStandaloneHostContext $context): void {
		if($status<300 || $status>399){
			return;
		}
		$location=$this->header($headers, 'location');
		if($location===null){
			return;
		}
		if(is_array($location) || trim($location)==='' || preg_match('/[\r\n\x00-\x1F\x7F]/', $location)===1 || str_contains($location, '\\') || str_starts_with($location, '//')){
			throw new PanelStandaloneHostException('invalid_redirect', 500, 'The Panel handler returned an unsafe redirect.');
		}
		$parts=parse_url($location);
		if($parts===false || isset($parts['user']) || isset($parts['pass'])){
			throw new PanelStandaloneHostException('invalid_redirect', 500, 'The Panel handler returned an unsafe redirect.');
		}
		$scheme=strtolower((string)($parts['scheme'] ?? ''));
		if($scheme!=='' && (!in_array($scheme, ['http','https'], true) || !isset($parts['host']))){
			throw new PanelStandaloneHostException('invalid_redirect', 500, 'The Panel handler returned an unsafe redirect.');
		}
		if($scheme==='' && PanelNavigationTarget::samePanel($location, $this->prefix)){
			return;
		}
		if(!is_callable($this->redirectValidator)){
			throw new PanelStandaloneHostException('redirect_outside_panel', 500, 'The Panel handler returned a redirect outside its mount.');
		}
		try{
			$allowed=PanelUtilityResolver::evaluate($this->redirectValidator, [
				'target'=>$location,
				'context'=>$context,
				'request'=>$context->request(),
				'host_prefix'=>$this->prefix,
			], ['target','context','request']);
		}
		catch(\Throwable $exception){
			throw new PanelStandaloneHostException('redirect_policy_unavailable', 503, 'The redirect policy is unavailable.', [], $exception);
		}
		if($allowed!==true){
			throw new PanelStandaloneHostException('redirect_outside_panel', 500, 'The Panel handler returned a redirect outside its mount.');
		}
	}

	private function canonicalName(string $name): string {
		$lower=strtolower($name);
		return match($lower){
			'content-md5'=>'Content-MD5',
			'etag'=>'ETag',
			'te'=>'TE',
			'www-authenticate'=>'WWW-Authenticate',
			default=>implode('-', array_map(static fn(string $part): string=>ucfirst($part), explode('-', $lower))),
		};
	}

	/** @param array<string,string|array<int,string>> $headers */
	private function hasHeader(array $headers, string $lower): bool {
		foreach(array_keys($headers) as $name){
			if(strtolower($name)===$lower){
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,string|array<int,string>> $headers
	 * @return string|array<int,string>|null
	 */
	private function header(array $headers, string $lower): string|array|null {
		foreach($headers as $name=>$value){
			if(strtolower($name)===$lower){
				return $value;
			}
		}
		return null;
	}

	/**
	 * @param array<string,string|array<int,string>> $headers
	 * @param string|array<int,string> $value
	 * @return array<string,string|array<int,string>>
	 */
	private function setHeader(array $headers, string $name, string|array $value): array {
		$headers=$this->withoutHeaders($headers, [strtolower($name)]);
		$headers[$name]=$value;
		return $headers;
	}

	/**
	 * @param array<string,string|array<int,string>> $headers
	 * @param list<string> $remove
	 * @return array<string,string|array<int,string>>
	 */
	private function withoutHeaders(array $headers, array $remove): array {
		$remove=array_fill_keys($remove, true);
		foreach(array_keys($headers) as $name){
			if(isset($remove[strtolower($name)])){
				unset($headers[$name]);
			}
		}
		return $headers;
	}
}
