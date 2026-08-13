<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Atomic operation persistence plus worker leases and fencing. */
interface PanelLeasedOperationStore extends PanelOperationStore {
	public function currentTime():string;
	public function acquireLease(string $id,string $worker='worker',int $ttlSeconds=60):?PanelOperationReservation;
	public function reserveLease(?string $queue=null,string $worker='worker',int $ttlSeconds=60):?PanelOperationReservation;
	public function inspectLease(PanelOperationLease $lease):PanelOperationReservation;
	/** @param callable(PanelOperationRecord):PanelOperationRecord $mutator */
	public function mutateLease(PanelOperationLease $lease,callable $mutator,?int $renewSeconds=null):PanelOperationReservation;
	public function renewLease(PanelOperationLease $lease,int $ttlSeconds=60):PanelOperationReservation;
	/** @param callable(PanelOperationRecord):PanelOperationRecord $mutator */
	public function finishLease(PanelOperationLease $lease,callable $mutator):PanelOperationRecord;
	public function releaseLease(PanelOperationLease $lease,?int $delaySeconds=null):PanelOperationRecord;
	/** @return list<PanelOperationRecord> */ public function recoverExpiredLeases(int $limit=100):array;
	/** @return list<array<string,mixed>> */ public function activeLeaseManifests():array;
}
