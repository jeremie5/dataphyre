<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Stable, secret-free failure metadata for durable snapshot stores.
 *
 * Connection strings, credentials, table names, SQL, scope names, schema names,
 * and provider messages are intentionally absent from the serialized envelope.
 */
final class PanelSnapshotStorageException extends \RuntimeException implements \JsonSerializable {
	public function __construct(
		private readonly string $errorCode,
		string $message,
		private readonly bool $retryable=false,
		?\Throwable $previous=null,
	){
		if(preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $errorCode)!==1){
			throw new \InvalidArgumentException('Snapshot storage error code is invalid.');
		}
		parent::__construct($message, 0, $previous);
	}

	public function errorCode():string{return $this->errorCode;}
	public function retryable():bool{return $this->retryable;}

	/** @return array{type:string,code:string,retryable:bool,message:string,details_serialized:bool} */
	public function jsonSerialize():array {
		return [
			'type'=>'panel_snapshot_storage_error',
			'code'=>$this->errorCode,
			'retryable'=>$this->retryable,
			'message'=>$this->getMessage(),
			'details_serialized'=>false,
		];
	}
}
