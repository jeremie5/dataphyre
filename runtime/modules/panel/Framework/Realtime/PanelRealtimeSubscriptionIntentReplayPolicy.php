<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Atomic host seam for consuming an initial subscription-intent nonce exactly once. */
interface PanelRealtimeSubscriptionIntentReplayPolicy extends \JsonSerializable {
	public function consume(PanelRealtimeIntentVerification $intent, PanelRealtimeSubscription $subscription, PanelRealtimeContext $context): bool;
}
