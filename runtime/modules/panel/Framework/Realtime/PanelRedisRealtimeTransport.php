<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * SDK-neutral execution boundary for the fixed Redis scripts owned by Panel.
 * Implementations must preserve binary-safe strings and nested RESP arrays.
 */
interface PanelRedisRealtimeTransport extends \JsonSerializable {
	/** @param list<string> $keys @param list<string> $arguments */
	public function evaluate(string $script, array $keys, array $arguments=[]): mixed;
}
