<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Raised when a migration worker no longer owns the current fence. */
final class PanelMigrationLeaseLost extends PanelMigrationConflict {
	public function __construct(private readonly string $scopeKey,string $message='Panel migration execution lease was lost.'){parent::__construct($message);}
	public function scopeKey():string{return$this->scopeKey;}
}
