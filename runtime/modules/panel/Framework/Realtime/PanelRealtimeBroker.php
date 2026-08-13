<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Transport-neutral pull side of a replayable realtime broker. */
interface PanelRealtimeBroker extends \JsonSerializable {
	public function read(PanelRealtimeSubscription $subscription, int $afterSequence, int $limit, ?PanelRealtimeCancellation $cancellation=null): PanelRealtimeReadResult;
}
