<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Typed realtime failure metadata. Endpoints never serialize the raw exception message. */
final class PanelRealtimeException extends \RuntimeException {
	public function __construct(
		private readonly string $publicCode,
		private readonly int $httpStatus,
		string $message,
		private readonly bool $retryable=false
	){
		parent::__construct($message);
		if(preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $publicCode)!==1){ throw new \InvalidArgumentException('Panel realtime error code is invalid.'); }
		if($httpStatus<400 || $httpStatus>599){ throw new \InvalidArgumentException('Panel realtime HTTP status is invalid.'); }
	}

	public function publicCode(): string { return $this->publicCode; }
	public function httpStatus(): int { return $this->httpStatus; }
	public function retryable(): bool { return $this->retryable; }
}
