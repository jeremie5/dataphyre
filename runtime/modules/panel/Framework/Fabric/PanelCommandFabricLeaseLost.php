<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Raised when another worker owns or has superseded a subscriber fence. */
final class PanelCommandFabricLeaseLost extends \RuntimeException {
	public function __construct(private readonly string $subscriber,string $message='Command fabric subscriber lease is no longer owned.'){
		PanelOperationsGuard::name($subscriber,'command fabric subscriber',128);parent::__construct($message);
	}
	public function subscriber():string{return$this->subscriber;}
}
