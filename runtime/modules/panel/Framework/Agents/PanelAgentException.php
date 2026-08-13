<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Stable, non-secret failure returned by the Panel agent-safe boundary. */
final class PanelAgentException extends \RuntimeException {
	public function __construct(
		private readonly string $errorCode,
		string $message,
		private readonly int $httpStatus=422,
		?\Throwable $previous=null
	){
		parent::__construct($message, 0, $previous);
		if(preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $errorCode)!==1){
			throw new \InvalidArgumentException('Panel agent error codes must be stable lowercase identifiers.');
		}
		if($httpStatus<400 || $httpStatus>599){
			throw new \InvalidArgumentException('Panel agent HTTP statuses must be between 400 and 599.');
		}
	}

	public function errorCode(): string { return $this->errorCode; }
	public function httpStatus(): int { return $this->httpStatus; }
}
