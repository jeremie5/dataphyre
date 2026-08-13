<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Stable public failure raised while issuing a DataSurface window intent. */
final class PanelDataSurfaceException extends \RuntimeException {
	public function __construct(private readonly string $publicCode, private readonly int $httpStatus, string $message) {
		parent::__construct($message, $httpStatus);
		if(preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $publicCode)!==1 || $httpStatus<400 || $httpStatus>599){ throw new \InvalidArgumentException('Panel DataSurface exception metadata is invalid.'); }
	}
	public function publicCode(): string { return $this->publicCode; }
	public function httpStatus(): int { return $this->httpStatus; }
}
