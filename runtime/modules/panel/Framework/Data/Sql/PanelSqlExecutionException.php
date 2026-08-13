<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Publicly stable SQL adapter failure that never includes SQL, parameters, or driver diagnostics. */
final class PanelSqlExecutionException extends \RuntimeException implements \JsonSerializable {
	public function __construct(private readonly string $operation='query', ?\Throwable $previous=null) {
		parent::__construct('Panel SQL data source execution failed.', 0, $previous);
	}

	public function operation(): string { return $this->operation; }

	/** @return array{type:string,status:string,operation:string,http_status:int,retryable:bool} */
	public function jsonSerialize(): array {
		return ['type'=>'panel_sql_execution_error', 'status'=>'unavailable', 'operation'=>$this->operation, 'http_status'=>503, 'retryable'=>true];
	}
}
