<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Pull-based subscription that can be bridged to Reactor, SSE, or WebSockets. */
interface PanelDataSubscription {
	/** @return list<PanelDataChange> */
	public function poll(int $limit=100): array;
	public function cursor(): int;
	public function closed(): bool;
	public function close(): void;
}
