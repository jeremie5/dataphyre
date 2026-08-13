<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Stable public failure; transport and upstream details never enter the message. */
final class PanelHttpDataSourceException extends \RuntimeException implements \JsonSerializable {
	public function __construct(
		private readonly string $publicCode,
		string $message,
		private readonly int $httpStatus,
		private readonly bool $retryable=false,
		private readonly bool $circuitFailure=false
	){
		PanelHttpDataSourceValue::identifier($publicCode, 'Remote data-source error code', 64);
		PanelHttpDataSourceValue::text($message, 'Remote data-source error message', 240);
		if($httpStatus<400 || $httpStatus>599){ throw new \InvalidArgumentException('Remote data-source error status must be between 400 and 599.'); }
		parent::__construct($message);
	}

	public static function accessDenied(): self { return new self('remote_scope_denied','The remote data scope is not authorized.',403); }
	public static function cursorInvalid(): self { return new self('remote_cursor_invalid','The remote data cursor is invalid or expired.',409); }
	public static function cancelled(): self { return new self('remote_request_cancelled','The remote data request was cancelled.',408,true); }
	public static function deadline(): self { return new self('remote_deadline_exceeded','The remote data deadline was exceeded.',504,true,true); }
	public static function transportUnavailable(): self { return new self('remote_transport_unavailable','The remote data service is unavailable.',503,true,true); }
	public static function circuitOpen(): self { return new self('remote_circuit_open','The remote data circuit is temporarily open.',503,true); }
	public static function protocolInvalid(): self { return new self('remote_protocol_invalid','The remote data service returned an invalid response.',502,false,true); }
	public static function capabilityMismatch(): self { return new self('remote_capability_mismatch','The remote data capability contract changed.',503,false,true); }
	public static function requestTooLarge(): self { return new self('remote_request_too_large','The remote data query exceeds the configured request limit.',422); }
	public static function runtimeUnavailable(): self { return new self('remote_runtime_unavailable','The remote data execution context is unavailable.',503,true); }
	public static function upstream(int $status): self {
		return match(true){
			$status===401 || $status===403=>new self('remote_access_denied','The remote data request was denied.',403),
			$status===408=>new self('remote_deadline_exceeded','The remote data deadline was exceeded.',504,true,true),
			$status===409=>new self('remote_query_conflict','The remote data query conflicted with current state.',409),
			$status===422=>new self('remote_query_rejected','The remote data query was rejected.',422),
			$status===429=>new self('remote_rate_limited','The remote data service is rate limited.',429,true,true),
			$status>=500=>new self('remote_upstream_unavailable','The remote data service is unavailable.',503,true,true),
			default=>new self('remote_upstream_error','The remote data request failed.',502),
		};
	}

	public function publicCode(): string { return $this->publicCode; }
	public function httpStatus(): int { return $this->httpStatus; }
	public function retryable(): bool { return $this->retryable; }
	public function countsTowardCircuit(): bool { return $this->circuitFailure; }
	/** @return array<string,mixed> */
	public function jsonSerialize(): array { return ['type'=>'panel_http_data_source_error','version'=>1,'code'=>$this->publicCode,'message'=>$this->getMessage(),'http_status'=>$this->httpStatus,'retryable'=>$this->retryable]; }
}
