<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/**
 * Atomic migration adapter contract for filesystem, database, or broker hosts.
 * Implementations must execute each handler and checkpoint in one fenced transaction.
 */
interface PanelMigrationStore extends \JsonSerializable {
	public function state(string $scope,?string $tenant=null):PanelMigrationState;
	public function acquire(string $scope,?string $tenant,string $owner='migration-worker',int $ttlSeconds=60):?PanelMigrationLease;
	public function renew(PanelMigrationLease $lease,int $ttlSeconds=60):PanelMigrationLease;
	public function begin(PanelMigrationLease $lease,PanelMigrationPlan $plan,mixed $actor=null):PanelMigrationReport;
	public function applyBatch(PanelMigrationLease $lease,string $runId,PanelMigrationPlan $plan,PanelMigrationDefinition $definition,mixed $actor=null):PanelMigrationReport;
	public function beginRollback(PanelMigrationLease $lease,string $runId,PanelMigrationPlan $plan):PanelMigrationReport;
	public function applyCompensation(PanelMigrationLease $lease,string $runId,PanelMigrationPlan $plan,PanelMigrationDefinition $definition,mixed $actor=null):PanelMigrationReport;
	public function complete(PanelMigrationLease $lease,string $runId,PanelMigrationPlan $plan):PanelMigrationReport;
	public function completeRollback(PanelMigrationLease $lease,string $runId,PanelMigrationPlan $plan):PanelMigrationReport;
	public function fail(PanelMigrationLease $lease,string $runId,\Throwable $error):PanelMigrationReport;
	public function restoreSnapshot(PanelMigrationLease $lease,string $runId,PanelMigrationPlan $plan):PanelMigrationReport;
	public function release(PanelMigrationLease $lease):void;
	/** @return list<PanelMigrationReport> */ public function recoverExpired(int $limit=100):array;
	public function report(string $runId):?PanelMigrationReport;
	public function reportByPlan(string $planDigest):?PanelMigrationReport;
	public function snapshot(string $runId):?PanelMigrationSnapshot;
	/** @return array<string,mixed> */ public function changesSince(int $cursor=0,int $limit=100):array;
	/** @return array<string,mixed> */ public function manifest():array;
}
