<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Stable semantic planning or execution failure. */
class PanelSemanticException extends \RuntimeException implements \JsonSerializable {
	public function __construct(private readonly string $publicCode,string $message,private readonly int $httpStatus=422,private readonly bool $retryable=false,?\Throwable $previous=null){if(preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$publicCode)!==1||$httpStatus<400||$httpStatus>599){throw new \InvalidArgumentException('Panel semantic failure metadata is invalid.');}parent::__construct($message,0,$previous);}
	public function publicCode():string{return$this->publicCode;}public function httpStatus():int{return$this->httpStatus;}public function retryable():bool{return$this->retryable;}
	/** @return array<string,mixed> */public function jsonSerialize():array{return['type'=>'panel_semantic_error','version'=>1,'code'=>$this->publicCode,'message'=>$this->getMessage(),'status'=>$this->httpStatus,'retryable'=>$this->retryable];}
}
