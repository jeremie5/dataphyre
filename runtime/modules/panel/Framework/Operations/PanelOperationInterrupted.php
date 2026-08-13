<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Internal cooperative-control signal used for pause and cancellation. */
final class PanelOperationInterrupted extends \RuntimeException {
	public function __construct(private readonly string $operationStatus) {
		parent::__construct('Panel operation execution interrupted with status '.$operationStatus.'.');
	}
	public function operationStatus(): string { return $this->operationStatus; }
}
