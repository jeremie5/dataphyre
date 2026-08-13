<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Stable secret-free failure metadata for durable command-fabric adapters. */
final class PanelCommandFabricStorageException extends \RuntimeException {
	public function __construct(private readonly string $errorCode,string $message,private readonly bool $retryable=false,?\Throwable $previous=null){
		if(preg_match('/^[a-z][a-z0-9_]{1,63}$/D',$errorCode)!==1){throw new \InvalidArgumentException('Command fabric storage error code is invalid.');}
		parent::__construct($message,0,$previous);
	}
	public function errorCode():string{return$this->errorCode;}
	public function retryable():bool{return$this->retryable;}
}
