<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Stable public failure envelope for Studio collaboration transport. */
final class PanelStudioCollaborationTransportException extends \RuntimeException {
	public function __construct(
		private readonly string $publicCode,
		private readonly int $httpStatus,
		string $message,
		private readonly bool $retryable=false,
		?\Throwable $previous=null,
	){
		if(preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $publicCode)!==1){
			throw new \InvalidArgumentException('Studio collaboration transport error code is invalid.');
		}
		if($httpStatus<400||$httpStatus>599){
			throw new \InvalidArgumentException('Studio collaboration transport HTTP status is invalid.');
		}
		parent::__construct($message, 0, $previous);
	}

	public function publicCode():string{return $this->publicCode;}
	public function httpStatus():int{return $this->httpStatus;}
	public function retryable():bool{return $this->retryable;}
}
