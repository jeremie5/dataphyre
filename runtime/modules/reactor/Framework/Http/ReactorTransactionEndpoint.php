<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * HTTP-neutral adapter for transaction payloads and finite SSE event batches.
 *
 * This class deliberately does not infer authentication, trusted origins, or
 * CSRF policy from PHP globals. Request-facing callers must install a transport
 * authorizer and pass verified host context into dispatch/stream, optionally
 * composing dedicated origin and CSRF validators. The default is fail closed.
 */
final class ReactorTransactionEndpoint {
	private $transportAuthorizer=null;
	private $originValidator=null;
	private $csrfValidator=null;
	private bool $allowInsecureLegacyTransport=false;

	public function __construct(private readonly ReactorTransactionCoordinator $coordinator) {}

	/**
	 * @param callable(string,array<string,mixed>,array<string,mixed>):(true|false|string|array<string,mixed>) $authorizer
	 */
	public function authorizeTransport(?callable $authorizer): self {
		$clone=clone $this;
		$clone->transportAuthorizer=$authorizer;
		return $clone;
	}

	/** @param callable(array<string,mixed>,string):(true|false|string|array<string,mixed>) $validator */
	public function validateOrigin(?callable $validator): self {
		$clone=clone $this;
		$clone->originValidator=$validator;
		return $clone;
	}

	/** @param callable(array<string,mixed>,array<string,mixed>):(true|false|string|array<string,mixed>) $validator */
	public function validateCsrf(?callable $validator): self {
		$clone=clone $this;
		$clone->csrfValidator=$validator;
		return $clone;
	}

	/**
	 * Explicit compatibility escape hatch for already-protected internal calls.
	 * Request-facing routes must use authorizeTransport() instead.
	 */
	public function allowInsecureLegacyTransport(bool $allow=true): self {
		$clone=clone $this;
		$clone->allowInsecureLegacyTransport=$allow;
		return $clone;
	}

	/** @return array<string,mixed> */
	public function dispatch(array|string $payload, bool $online=true, array $securityContext=[]): array {
		if(($guard=$this->guard('dispatch', $securityContext))!==null){ return $guard; }
		if(is_string($payload)){
			try { $decoded=json_decode($payload, true, 512, JSON_THROW_ON_ERROR); }
			catch(\Throwable){ return $this->error('invalid', 'invalid_json', 'The transaction payload is not valid JSON.', $securityContext); }
			$payload=is_array($decoded) ? $decoded : [];
		}
		$definition=$payload['reactor_transaction'] ?? $payload['transaction'] ?? $payload;
		if(!is_array($definition)){
			return $this->error('invalid', 'transaction_required', 'A transaction object is required.', $securityContext);
		}
		try {
			$result=$this->coordinator->dispatch(ReactorStateTransaction::fromArray($definition), $online, $securityContext);
			return $this->resultEnvelope($result, $securityContext);
		} catch(\InvalidArgumentException|\DomainException){
			return $this->error('invalid', 'transaction_invalid', 'The transaction definition is invalid.', $securityContext);
		} catch(\Throwable){
			return $this->error('failed', 'transaction_dispatch_failed', 'The transaction could not be dispatched.', $securityContext);
		}
	}

	/** @return array<string,mixed> */
	public function stream(string $component, int $afterSequence=0, int $limit=100, array $securityContext=[]): array {
		if(($guard=$this->guard('stream', $securityContext, ['component'=>$component]))!==null){ return $guard+['cursor'=>max(0, $afterSequence), 'events'=>[]]; }
		try {
			$events=$this->coordinator->stream($component, max(0, $afterSequence), max(1, min(1000, $limit)), $securityContext);
		} catch(\RuntimeException $exception){
			$code=$exception->getCode()===403 ? 'stream_authorization_denied' : 'stream_unavailable';
			return $this->error('denied', $code, $code==='stream_authorization_denied' ? 'The event stream is not authorized.' : 'The event stream is unavailable.', $securityContext)+['cursor'=>max(0, $afterSequence), 'events'=>[]];
		} catch(\Throwable){
			return $this->error('failed', 'stream_unavailable', 'The event stream is unavailable.', $securityContext)+['cursor'=>max(0, $afterSequence), 'events'=>[]];
		}
		$cursor=max(0, $afterSequence);
		foreach($events as $event){ $cursor=max($cursor, (int)($event['sequence'] ?? 0)); }
		return ['schema_version'=>1, 'status'=>'ok', 'ok'=>true, 'cursor'=>$cursor, 'events'=>$events];
	}

	/** Encodes a finite event batch using Server-Sent Events framing. */
	public function eventStream(string $component, int $afterSequence=0, int $limit=100, array $securityContext=[]): string {
		$batch=$this->stream($component, $afterSequence, $limit, $securityContext);
		$output="retry: 1500\n";
		if(($batch['ok'] ?? false)!==true){
			$output.="event: reactor.error\n";
			$output.='data: '.json_encode($batch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n\n";
			return $output;
		}
		foreach($batch['events'] as $event){
			$type=(string)preg_replace('/[^a-z0-9_.-]/i', '', (string)($event['type'] ?? 'message'));
			if($type===''){ $type='message'; }
			$output.='id: '.(int)($event['sequence'] ?? 0)."\n";
			$output.='event: '.$type."\n";
			$output.='data: '.json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n\n";
		}
		if($batch['events']===[]){ $output.=": heartbeat\n\n"; }
		return $output;
	}

	/** @return array<string,mixed>|null */
	private function guard(string $operation, array $securityContext, array $resource=[]): ?array {
		if($this->allowInsecureLegacyTransport){ return null; }
		if($this->transportAuthorizer===null){
			return $this->error('denied', 'transport_security_required', 'Transaction transport security is not configured.', $securityContext);
		}
		if($this->originValidator!==null){
			try { $decision=($this->originValidator)($securityContext, $operation); }
			catch(\Throwable){ return $this->error('denied', 'origin_validation_unavailable', 'Origin validation is unavailable.', $securityContext); }
			if(($error=$this->decisionError($decision, 'origin_denied', 'The request origin is not allowed.', $securityContext))!==null){ return $error; }
		}
		if($operation==='dispatch' && $this->csrfValidator!==null){
			try { $decision=($this->csrfValidator)($securityContext, $resource); }
			catch(\Throwable){ return $this->error('denied', 'csrf_validation_unavailable', 'CSRF validation is unavailable.', $securityContext); }
			if(($error=$this->decisionError($decision, 'csrf_denied', 'CSRF validation failed.', $securityContext))!==null){ return $error; }
		}
		try { $decision=($this->transportAuthorizer)($operation, $securityContext, $resource); }
		catch(\Throwable){ return $this->error('denied', 'transport_authorization_unavailable', 'Transaction transport authorization is unavailable.', $securityContext); }
		return $this->decisionError($decision, 'transport_denied', 'The transaction transport request is not authorized.', $securityContext);
	}

	/** @return array<string,mixed>|null */
	private function decisionError(mixed $decision, string $defaultCode, string $defaultMessage, array $securityContext): ?array {
		if($decision===true){ return null; }
		$status='denied';
		$code=$defaultCode;
		$message=$defaultMessage;
		if(is_string($decision) && trim($decision)!==''){ $message=trim($decision); }
		if(is_array($decision)){
			$status=trim((string)($decision['status'] ?? $status)) ?: $status;
			$code=trim((string)($decision['code'] ?? $code)) ?: $code;
			$message=trim((string)($decision['message'] ?? $message)) ?: $message;
		}
		return $this->error($status, $code, $message, $securityContext);
	}

	/** @return array<string,mixed> */
	private function resultEnvelope(ReactorTransactionResult $result, array $securityContext): array {
		$payload=['schema_version'=>1]+$result->jsonSerialize();
		if($result->ok() || $result->status()==='queued' || $result->status()==='server_wins'){ return $payload; }
		$code=trim((string)($result->metadata()['error_code'] ?? $result->status())) ?: 'transaction_failed';
		$message=trim((string)($result->errors()[0] ?? 'The transaction was not accepted.')) ?: 'The transaction was not accepted.';
		$payload['error']=['code'=>$code, 'message'=>$message, 'correlation_id'=>$this->correlationId($securityContext)];
		return $payload;
	}

	/** @return array<string,mixed> */
	private function error(string $status, string $code, string $message, array $securityContext): array {
		return [
			'schema_version'=>1,
			'status'=>$status,
			'ok'=>false,
			'error'=>['code'=>$code, 'message'=>$message, 'correlation_id'=>$this->correlationId($securityContext)],
			'errors'=>[$message],
		];
	}

	private function correlationId(array $securityContext): string {
		$id=preg_replace('/[^a-zA-Z0-9_.:-]/', '', (string)($securityContext['correlation_id'] ?? ''));
		if(is_string($id) && $id!==''){ return substr($id, 0, 128); }
		try { return 'rtxerr_'.bin2hex(random_bytes(8)); }
		catch(\Throwable){ return 'rtxerr_'.str_replace('.', '', uniqid('', true)); }
	}
}
