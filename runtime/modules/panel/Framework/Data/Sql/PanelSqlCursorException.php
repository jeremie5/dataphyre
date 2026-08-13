<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Stable public cursor failure that does not distinguish signature, scope, key, shape, or expiry. */
final class PanelSqlCursorException extends \InvalidArgumentException implements \JsonSerializable {
	public function __construct(?\Throwable $previous=null) {
		parent::__construct('The Panel SQL cursor is invalid, expired, or belongs to another scope.', 0, $previous);
	}

	/** @return array{type:string,status:string,http_status:int,retryable:bool} */
	public function jsonSerialize(): array {
		return ['type'=>'panel_sql_cursor_error', 'status'=>'invalid_cursor', 'http_status'=>422, 'retryable'=>false];
	}
}
