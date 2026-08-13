<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Distributed command-fabric store with fenced subscriber ownership. */
interface PanelLeasedCommandFabricStore extends PanelCommandFabricStore {
	public function currentTime():string;
	public function acquireSubscriberLease(string $subscriber,string $worker='worker',int $ttlSeconds=60):?PanelCommandFabricSubscriberLease;
	public function inspectSubscriberLease(PanelCommandFabricSubscriberLease $lease):PanelCommandFabricSubscriberLease;
	public function renewSubscriberLease(PanelCommandFabricSubscriberLease $lease,int $ttlSeconds=60):PanelCommandFabricSubscriberLease;
	/** Atomically fences ownership and advances the durable subscriber cursor. */
	public function advanceSubscriberCursor(PanelCommandFabricSubscriberLease $lease,int $sequence):void;
	public function releaseSubscriberLease(PanelCommandFabricSubscriberLease $lease):void;
	/** @return list<array<string,mixed>> */public function activeSubscriberLeaseManifests():array;
}
