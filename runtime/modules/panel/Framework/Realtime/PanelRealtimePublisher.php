<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Optional append side implemented by brokers that accept local publication. */
interface PanelRealtimePublisher {
	/** @param array<string,mixed> $metadata */
	public function publish(PanelRealtimeContext $context, string $channel, string $topic, string $type, mixed $payload, array $metadata=[], ?string $occurredAt=null): PanelRealtimeEvent;
}
