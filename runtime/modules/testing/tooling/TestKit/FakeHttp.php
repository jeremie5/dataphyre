<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\AssertionContext;

use Closure;

final class FakeHttp {

	/** @var array<string, FakeHttpResponse|array{status?:mixed,body?:mixed,headers?:mixed}|Closure> */
	private array $responses=[];
	/** @var array<string,list<FakeHttpResponse|array{status?:mixed,body?:mixed,headers?:mixed}|Closure>> */
	private array $response_queues=[];
	/** @var list<FakeHttpRequest> */
	private array $requests=[];

	/** @param array<string, string> $headers */
	public function respond(string $method, string $url, int $status=200, mixed $body=null, array $headers=[]): self {
		$this->responses[$this->key($method, $url)]=new FakeHttpResponse($status, $body, $headers);
		return $this;
	}

	/** @param array<string,string> $headers */
	public function respondJson(string $method, string $url, mixed $body, int $status=200, array $headers=[]): self {
		$this->responses[$this->key($method, $url)]=FakeHttpResponse::json($body, $status, $headers);
		return $this;
	}

	/** @param array<string,mixed> $body @param array<string,string> $headers */
	public function respondForm(string $method, string $url, array $body, int $status=200, array $headers=[]): self {
		$this->responses[$this->key($method, $url)]=FakeHttpResponse::form($body, $status, $headers);
		return $this;
	}

	/** @param array<string,string> $headers */
	public function respondText(string $method, string $url, string $body='', int $status=200, array $headers=[]): self {
		$this->responses[$this->key($method, $url)]=FakeHttpResponse::text($body, $status, $headers);
		return $this;
	}

	/** @param array<string,string> $headers */
	public function respondFailure(string $method, string $url, int $status=500, string $body='error', array $headers=[]): self {
		$this->responses[$this->key($method, $url)]=FakeHttpResponse::failure($status, $body, $headers);
		return $this;
	}

	public function respondUsing(string $method, string $url, callable $response): self {
		$this->responses[$this->key($method, $url)]=Closure::fromCallable($response);
		return $this;
	}

	public function respondNext(string $method, string $url, FakeHttpResponse|array|callable $response): self {
		if(!$response instanceof FakeHttpResponse && !is_array($response)){
			$response=Closure::fromCallable($response);
		}
		$this->response_queues[$this->key($method, $url)][]=$response;
		return $this;
	}

	/** @param array<string, string> $headers @return array{status:int, body:mixed, headers:array<string, string>} */
	public function request(string $method, string $url, mixed $payload=null, array $headers=[]): array {
		return $this->dispatch(new FakeHttpRequest($method, $url, $payload, $headers));
	}

	/** @return array{status:int,body:mixed,headers:array<string,string>} */
	private function dispatch(FakeHttpRequest $request): array {
		$this->requests[]=$request;
		$key=$this->key($request->method(), $request->url());
		$response=$this->response_queues[$key] ?? [];
		if($response!==[]){
			$selected=array_shift($this->response_queues[$key]);
		}else{
			$selected=$this->responses[$key] ?? new FakeHttpResponse(404);
		}
		if($selected instanceof Closure){
			$selected=$selected($request);
		}
		if($selected instanceof FakeHttpResponse){
			$selected=$selected->toArray();
		}
		if(!is_array($selected)){
			throw new \UnexpectedValueException('Fake HTTP response callback must return FakeHttpResponse or an array.');
		}
		return [
			'status'=>(int)($selected['status'] ?? 0),
			'body'=>$selected['body'] ?? null,
			'headers'=>is_array($selected['headers'] ?? null) ? $selected['headers'] : [],
		];
	}

	/**
	 * Returns a callback compatible with Dataphyre HTTP client configuration.
	 * An optional decoder can turn raw request bodies into domain-readable data.
	 */
	public function handler(?callable $payload_decoder=null): Closure {
		return function(string $method, string $url, mixed $payload=null, array $headers=[], mixed ...$ignored)use($payload_decoder): array {
			if($payload_decoder!==null){
				$payload=$payload_decoder($payload, $method, $url, $headers, $ignored);
			}
			return $this->dispatch(new FakeHttpRequest($method, $url, $payload, $headers, $ignored));
		};
	}

	/** @param array<string, string> $headers @return array{status:int, body:mixed, headers:array<string, string>} */
	public function get(string $url, array $headers=[]): array {
		return $this->request('GET', $url, null, $headers);
	}

	/** @param array<string, string> $headers @return array{status:int, body:mixed, headers:array<string, string>} */
	public function post(string $url, mixed $payload=null, array $headers=[]): array {
		return $this->request('POST', $url, $payload, $headers);
	}

	/** @param array<string, string> $headers @return array{status:int, body:mixed, headers:array<string, string>} */
	public function put(string $url, mixed $payload=null, array $headers=[]): array {
		return $this->request('PUT', $url, $payload, $headers);
	}

	/** @param array<string, string> $headers @return array{status:int, body:mixed, headers:array<string, string>} */
	public function delete(string $url, mixed $payload=null, array $headers=[]): array {
		return $this->request('DELETE', $url, $payload, $headers);
	}

	/** @return array<int, array{method:string, url:string, payload:mixed, headers:array<string, string>}> */
	public function requests(): array {
		return array_map(static fn(FakeHttpRequest $request): array=>$request->toArray(), $this->requests);
	}

	/** @return list<FakeHttpRequest> */
	public function requestObjects(): array {
		return $this->requests;
	}

	public function lastRequest(): FakeHttpRequest {
		if($this->requests===[]){
			throw new \OutOfBoundsException('Fake HTTP client has no recorded requests.');
		}
		return $this->requests[array_key_last($this->requests)];
	}

	public function assertRequested(AssertionContext $t, string $method, string $url, ?array $payload_subset=null): void {
		$found=false;
		foreach($this->requests as $request){
			if($request->method()!==strtoupper($method) || $request->url()!==$url){
				continue;
			}
			if($payload_subset!==null){
				try{
					$t->subset($payload_subset, $request->body());
				}catch(AssertionFailed){
					continue;
				}
			}
			$found=true;
			break;
		}
		if($found===false){
			$t->fail('Expected fake HTTP client to contain request.', ['method'=>strtoupper($method), 'url'=>$url, 'payload_subset'=>$payload_subset], $this->requests());
		}
		$t->isTrue(true, 'HTTP request was recorded.');
	}

	public function assertRequestCount(AssertionContext $t, int $expected): void {
		$t->same($expected, count($this->requests), 'Expected fake HTTP request count to match.');
	}

	public function assertFormRequested(AssertionContext $t, string $method, string $url, array $values=[]): void {
		$this->assertStructuredRequest($t, $method, $url, $values, static fn(FakeHttpRequest $request): array=>$request->form(), 'form');
	}

	public function assertJsonRequested(AssertionContext $t, string $method, string $url, array $values=[]): void {
		$this->assertStructuredRequest($t, $method, $url, $values, static fn(FakeHttpRequest $request): array=>$request->json(), 'JSON');
	}

	public function assertHeaderSent(AssertionContext $t, string $method, string $url, string $header, string $value): void {
		$found=false;
		foreach($this->requests as $request){
			if($request->method()===strtoupper($method) && $request->url()===$url && $request->header($header)===$value){
				$found=true;
				break;
			}
		}
		if($found===false){
			$t->fail('Expected fake HTTP request header.', ['method'=>strtoupper($method), 'url'=>$url, 'header'=>$header, 'value'=>$value], $this->requests());
		}
		$t->isTrue(true, 'Expected fake HTTP request header was recorded.');
	}

	private function assertStructuredRequest(AssertionContext $t, string $method, string $url, array $values, callable $decoder, string $kind): void {
		$found=false;
		foreach($this->requests as $request){
			if($request->method()!==strtoupper($method) || $request->url()!==$url){
				continue;
			}
			try{
				$t->subset($values, $decoder($request));
				$found=true;
				break;
			}catch(AssertionFailed){
			}
		}
		if($found===false){
			$t->fail('Expected fake HTTP '.$kind.' request.', ['method'=>strtoupper($method), 'url'=>$url, 'values'=>$values], $this->requests());
		}
		$t->isTrue(true, 'Expected fake HTTP '.$kind.' request was recorded.');
	}

	private function key(string $method, string $url): string {
		return strtoupper($method).' '.$url;
	}
}
