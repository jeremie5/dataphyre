<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Deterministic in-memory reference adapter for tests and single-process tools. */
final class PanelInMemoryStudioStore implements PanelStudioStore {
	private array $state;
	private int $cursor=0;
	/** @var list<array<string,mixed>> */ private array $changes=[];
	public function __construct(?array $state=null,private readonly int $retention=512){$this->state=$state??PanelStudioStateEngine::initialState();PanelStudioStateEngine::manifest($this->state);}
	public function head(string $tenantId,string $documentId):?PanelStudioRevision{return PanelStudioStateEngine::head($this->state,$tenantId,$documentId);}
	public function draft(string $tenantId,string $documentId):?PanelStudioDraft{return PanelStudioStateEngine::draft($this->state,$tenantId,$documentId);}
	public function published(string $tenantId,string $documentId):?PanelStudioRevision{return PanelStudioStateEngine::published($this->state,$tenantId,$documentId);}
	public function history(string $tenantId,string $documentId,int $limit=100):array{return PanelStudioStateEngine::history($this->state,$tenantId,$documentId,$limit);}
	public function save(PanelStudioDocument $document,PanelStudioDefinition $definition,int $expectedRevision,string $idempotencyKey,string $actor,string $createdAt,?PanelStudioArtifact $artifact=null):PanelStudioReceipt{$receipt=PanelStudioStateEngine::save($this->state,$document,$definition,$expectedRevision,$idempotencyKey,$actor,$createdAt,$artifact);$this->record($receipt,$document->tenantId(),$document->id());return$receipt;}
	public function approve(string $tenantId,string $documentId,int $expectedRevision,string $idempotencyKey,string $actor,string $createdAt):PanelStudioReceipt{$receipt=PanelStudioStateEngine::approve($this->state,$tenantId,$documentId,$expectedRevision,$idempotencyKey,$actor,$createdAt);$this->record($receipt,$tenantId,$documentId);return$receipt;}
	public function publish(string $tenantId,string $documentId,int $expectedRevision,string $idempotencyKey,string $actor,int $requiredApprovals,string $createdAt):PanelStudioReceipt{$receipt=PanelStudioStateEngine::publish($this->state,$tenantId,$documentId,$expectedRevision,$idempotencyKey,$actor,$requiredApprovals,$createdAt);$this->record($receipt,$tenantId,$documentId);return$receipt;}
	public function rollback(string $tenantId,string $documentId,int $targetRevision,int $expectedRevision,string $idempotencyKey,string $actor,string $createdAt):PanelStudioReceipt{$receipt=PanelStudioStateEngine::rollback($this->state,$tenantId,$documentId,$targetRevision,$expectedRevision,$idempotencyKey,$actor,$createdAt);$this->record($receipt,$tenantId,$documentId);return$receipt;}
	public function verify(string $tenantId,string $documentId):bool{return PanelStudioStateEngine::verify($this->state,$tenantId,$documentId);}
	public function cursor():int{return$this->cursor;}
	public function changesSince(int $cursor=0,int $limit=100):array{
		$cursor=max(0,$cursor);$limit=max(1,min(1000,$limit));$oldest=$this->changes!==[]?(int)$this->changes[0]['cursor']:0;$reset=$cursor>0&&$oldest>0&&$cursor<$oldest-1;$changes=[];if(!$reset){foreach($this->changes as$change){if($change['cursor']>$cursor){$changes[]=$change;}if(count($changes)>=$limit){break;}}}$next=$changes!==[]?(int)$changes[array_key_last($changes)]['cursor']:$this->cursor;
		return['cursor'=>$next,'oldest_cursor'=>$oldest,'reset_required'=>$reset,'changes'=>$changes,'snapshot'=>$reset?['schema'=>'dataphyre.panel.studio.v1','sequence'=>$this->cursor,'committed_at'=>null,'payload'=>$this->state,'event'=>$this->changes!==[]?$this->changes[array_key_last($this->changes)]:null]:null];
	}
	public function manifest():array{return['type'=>'panel_studio_store_manifest','version'=>1,'adapter'=>'memory','cursor'=>$this->cursor,'retention'=>max(8,$this->retention),'state'=>PanelStudioStateEngine::manifest($this->state),'capabilities'=>['atomic'=>'single_process','optimistic_revisions'=>true,'idempotent_commands'=>true,'hash_chained_history'=>true,'artifact_binding'=>true,'legacy_rebind_required'=>true,'cursor_feed'=>true,'stale_cursor_reset'=>true,'reset_snapshot'=>'studio_state_envelope_v1','rollback'=>true],'security'=>['reset_snapshot_requires_authorized_host_boundary'=>true]];}
	public function jsonSerialize():array{return$this->manifest();}
	private function record(PanelStudioReceipt $receipt,string $tenantId,string $documentId):void{if($receipt->replayed()){return;}$this->changes[]=['cursor'=>++$this->cursor,'type'=>'studio.'.$receipt->operation(),'tenant_id'=>$tenantId,'document_id'=>$documentId,'revision'=>$receipt->revision(),'revision_hash'=>$receipt->revisionHash()];$retain=max(8,$this->retention);if(count($this->changes)>$retain){$this->changes=array_slice($this->changes,-$retain);}}
}
