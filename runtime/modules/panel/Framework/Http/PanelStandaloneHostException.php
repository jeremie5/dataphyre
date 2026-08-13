<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Safe HTTP failure raised inside the standalone Panel host boundary.
 *
 * The exception deliberately separates a stable public error code and HTTP
 * status from its diagnostic exception code. Host callers can therefore map
 * malformed requests and failed security gates without exposing internals.
 */
final class PanelStandaloneHostException extends \RuntimeException {

	/** @param array<string,string|array<int,string>> $headers */
	public function __construct(
		private readonly string $errorCode,
		private readonly int $httpStatus,
		string $message,
		private readonly array $headers=[],
		?\Throwable $previous=null
	){
		parent::__construct($message, 0, $previous);
	}

	/** Stable machine-readable error code used by the public envelope. */
	public function errorCode(): string {
		return $this->errorCode;
	}

	/** HTTP status selected by the boundary that rejected the request. */
	public function httpStatus(): int {
		return $this->httpStatus;
	}

	/** @return array<string,string|array<int,string>> */
	public function headers(): array {
		return $this->headers;
	}
}
