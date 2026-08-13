<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Stable remote-mutation infrastructure failure with circuit classification. */
final class PanelHttpDataMutationException extends PanelDataMutationException {
	public function __construct(string $code,string $message,int $status,bool $retryable=false,private readonly bool $circuitFailure=false,?\Throwable $previous=null){parent::__construct($code,$message,$status,$retryable,$previous);}
	public static function cancelled():self{return new self('mutation_remote_cancelled','The remote mutation request was cancelled.',408,true);}
	public static function deadline():self{return new self('mutation_remote_deadline','The remote mutation deadline was exceeded.',504,true,true);}
	public static function transportUnavailable(?\Throwable $previous=null):self{return new self('mutation_remote_transport_unavailable','The remote mutation service is unavailable.',503,true,true,$previous);}
	public static function circuitOpen():self{return new self('mutation_remote_circuit_open','The remote mutation circuit is temporarily open.',503,true);}
	public static function protocolInvalid(?\Throwable $previous=null):self{return new self('mutation_remote_protocol_invalid','The remote mutation service returned an invalid response.',502,false,true,$previous);}
	public static function capabilityMismatch():self{return new self('mutation_remote_capability_mismatch','The remote mutation capability contract changed.',503,false,true);}
	public static function requestTooLarge():self{return new self('mutation_remote_request_too_large','The remote mutation exceeds the configured request limit.',422);}
	public static function runtimeUnavailable(?\Throwable $previous=null):self{return new self('mutation_remote_runtime_unavailable','The remote mutation execution context is unavailable.',503,true,false,$previous);}
	public function countsTowardCircuit():bool{return$this->circuitFailure;}
}
