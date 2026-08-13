<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Cross-process atomic Studio adapter backed by immutable snapshots. */
final class PanelFilesystemStudioStore implements PanelStudioStore {
	private PanelAtomicSnapshotStore $store;
	public function __construct(string $directory,int $retention=512){$this->store=new PanelAtomicSnapshotStore($directory,'dataphyre.panel.studio.v1',PanelStudioStateEngine::initialState(),$retention);}
	public function head(string $tenantId,string $documentId):?PanelStudioRevision{return PanelStudioStateEngine::head($this->store->payload(),$tenantId,$documentId);}
	public function draft(string $tenantId,string $documentId):?PanelStudioDraft{return PanelStudioStateEngine::draft($this->store->payload(),$tenantId,$documentId);}
	public function published(string $tenantId,string $documentId):?PanelStudioRevision{return PanelStudioStateEngine::published($this->store->payload(),$tenantId,$documentId);}
	public function history(string $tenantId,string $documentId,int $limit=100):array{return PanelStudioStateEngine::history($this->store->payload(),$tenantId,$documentId,$limit);}
	public function save(PanelStudioDocument $document,PanelStudioDefinition $definition,int $expectedRevision,string $idempotencyKey,string $actor,string $createdAt,?PanelStudioArtifact $artifact=null):PanelStudioReceipt{return$this->commit('save',$document->tenantId(),$document->id(),static fn(array &$state):PanelStudioReceipt=>PanelStudioStateEngine::save($state,$document,$definition,$expectedRevision,$idempotencyKey,$actor,$createdAt,$artifact));}
	public function approve(string $tenantId,string $documentId,int $expectedRevision,string $idempotencyKey,string $actor,string $createdAt):PanelStudioReceipt{return$this->commit('approve',$tenantId,$documentId,static fn(array &$state):PanelStudioReceipt=>PanelStudioStateEngine::approve($state,$tenantId,$documentId,$expectedRevision,$idempotencyKey,$actor,$createdAt));}
	public function publish(string $tenantId,string $documentId,int $expectedRevision,string $idempotencyKey,string $actor,int $requiredApprovals,string $createdAt):PanelStudioReceipt{return$this->commit('publish',$tenantId,$documentId,static fn(array &$state):PanelStudioReceipt=>PanelStudioStateEngine::publish($state,$tenantId,$documentId,$expectedRevision,$idempotencyKey,$actor,$requiredApprovals,$createdAt));}
	public function rollback(string $tenantId,string $documentId,int $targetRevision,int $expectedRevision,string $idempotencyKey,string $actor,string $createdAt):PanelStudioReceipt{return$this->commit('rollback',$tenantId,$documentId,static fn(array &$state):PanelStudioReceipt=>PanelStudioStateEngine::rollback($state,$tenantId,$documentId,$targetRevision,$expectedRevision,$idempotencyKey,$actor,$createdAt));}
	public function verify(string $tenantId,string $documentId):bool{return PanelStudioStateEngine::verify($this->store->payload(),$tenantId,$documentId);}
	public function cursor():int{return$this->store->cursor();}
	public function changesSince(int $cursor=0,int $limit=100):array{$changes=$this->store->changesSince($cursor,$limit);if(($changes['reset_required']??false)===true&&is_array($changes['snapshot']['payload']??null)){PanelStudioStateEngine::manifest($changes['snapshot']['payload']);}return$changes;}
	public function manifest():array{$store=$this->store->manifest();unset($store['directory']);return['type'=>'panel_studio_store_manifest','version'=>1,'adapter'=>'filesystem_atomic_json','cursor'=>$this->cursor(),'state'=>PanelStudioStateEngine::manifest($this->store->payload()),'capabilities'=>['atomic'=>'cross_process','crash_recovery'=>true,'optimistic_revisions'=>true,'idempotent_commands'=>true,'hash_chained_history'=>true,'artifact_binding'=>true,'legacy_rebind_required'=>true,'cursor_feed'=>true,'stale_cursor_reset'=>true,'reset_snapshot'=>'studio_state_envelope_v1','rollback'=>true],'security'=>['reset_snapshot_requires_authorized_host_boundary'=>true],'store'=>$store];}
	public function jsonSerialize():array{return$this->manifest();}
	/** @param callable(array<string,mixed>&):PanelStudioReceipt $mutation */
	private function commit(string $operation,string $tenantId,string $documentId,callable $mutation):PanelStudioReceipt{$result=$this->store->transaction($mutation,'studio.'.$operation,['tenant_id'=>$tenantId,'document_id'=>$documentId]);if(!$result['result']instanceof PanelStudioReceipt){throw new \UnexpectedValueException('Studio store transition did not return a receipt.');}return$result['result'];}
}
