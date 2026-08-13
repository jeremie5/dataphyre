<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Safe handler refusal whose code and message may be written to a command receipt. */
final class PanelCommandExecutionException extends \RuntimeException {
	public function __construct(private readonly string $errorCode,string $safeMessage,?\Throwable $previous=null){
		PanelOperationsGuard::name($errorCode,'command execution error code');
		PanelOperationsGuard::label($safeMessage,'command execution safe message',2048);
		parent::__construct($safeMessage,0,$previous);
	}

	public function errorCode():string{return $this->errorCode;}
}
