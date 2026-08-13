<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Durable authentication store capable of atomic cross-record updates. */
interface PanelAuthenticationStore extends PanelAuthenticationTransaction {
	/** @param callable(PanelAuthenticationTransaction):mixed $callback */
	public function transaction(callable $callback): mixed;
}
