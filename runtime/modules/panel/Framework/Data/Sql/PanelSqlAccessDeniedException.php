<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Stable fail-closed denial raised before an SQL executor is touched. */
final class PanelSqlAccessDeniedException extends \RuntimeException implements \JsonSerializable {
	public function __construct(private readonly string $reason='denied') {
		parent::__construct('Panel SQL data access was denied.');
	}

	public function reason(): string { return $this->reason; }

	/** @return array{type:string,status:string,reason:string,http_status:int,retryable:bool} */
	public function jsonSerialize(): array {
		return ['type'=>'panel_sql_access_denied', 'status'=>'denied', 'reason'=>$this->reason, 'http_status'=>403, 'retryable'=>false];
	}
}
