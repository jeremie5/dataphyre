<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Raised when the state or registry has changed since planning. */
final class PanelMigrationStalePlan extends PanelMigrationConflict {
	public function __construct(private readonly string $planDigest,string $message='Panel migration plan is stale.'){parent::__construct($message);}
	public function planDigest():string{return$this->planDigest;}
}
