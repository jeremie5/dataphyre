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
 * Stable public failure raised by the interactive-widget boundary.
 *
 * Internal exceptions are deliberately not copied into this object. Adapters
 * may log private diagnostics separately, while browser and API callers receive
 * only a bounded code, status, retry hint, and operator-safe message.
 */
final class PanelWidgetInteractionException extends \RuntimeException {
	public function __construct(
		private readonly string $publicCode,
		string $publicMessage,
		private readonly int $httpStatus=422,
		private readonly bool $retryable=false,
		?\Throwable $previous=null
	){
		if(preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $publicCode)!==1){
			throw new \InvalidArgumentException('Widget interaction error codes must be safe identifiers.');
		}
		$publicMessage=trim($publicMessage);
		if($publicMessage==='' || strlen($publicMessage)>240){
			throw new \InvalidArgumentException('Widget interaction public messages must contain 1-240 bytes.');
		}
		if($httpStatus<400 || $httpStatus>599){
			throw new \InvalidArgumentException('Widget interaction HTTP statuses must be errors.');
		}
		parent::__construct($publicMessage, 0, $previous);
	}

	public function publicCode(): string { return $this->publicCode; }
	public function publicMessage(): string { return $this->getMessage(); }
	public function httpStatus(): int { return $this->httpStatus; }
	public function retryable(): bool { return $this->retryable; }
}
