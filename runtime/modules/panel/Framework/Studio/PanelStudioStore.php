<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Atomic persistence boundary for tenant-scoped Studio revisions. */
interface PanelStudioStore extends \JsonSerializable {
	public function head(string $tenantId,string $documentId):?PanelStudioRevision;
	public function draft(string $tenantId,string $documentId):?PanelStudioDraft;
	public function published(string $tenantId,string $documentId):?PanelStudioRevision;
	/** @return list<PanelStudioRevision> */ public function history(string $tenantId,string $documentId,int $limit=100):array;
	public function save(PanelStudioDocument $document,PanelStudioDefinition $definition,int $expectedRevision,string $idempotencyKey,string $actor,string $createdAt,?PanelStudioArtifact $artifact=null):PanelStudioReceipt;
	public function approve(string $tenantId,string $documentId,int $expectedRevision,string $idempotencyKey,string $actor,string $createdAt):PanelStudioReceipt;
	public function publish(string $tenantId,string $documentId,int $expectedRevision,string $idempotencyKey,string $actor,int $requiredApprovals,string $createdAt):PanelStudioReceipt;
	public function rollback(string $tenantId,string $documentId,int $targetRevision,int $expectedRevision,string $idempotencyKey,string $actor,string $createdAt):PanelStudioReceipt;
	public function verify(string $tenantId,string $documentId):bool;
	public function cursor():int;
	/**
	 * A stale cursor returns reset_required=true and a full snapshot envelope with
	 * schema, sequence, committed_at, payload, and event keys. The payload is the
	 * trusted JSON-only Studio state and must only cross an authorized host edge.
	 * @return array<string,mixed>
	 */
	public function changesSince(int $cursor=0,int $limit=100):array;
	/** @return array<string,mixed> */ public function manifest():array;
}
