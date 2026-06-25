<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Builds rollback manifests for Panel package installation and apply results.
 *
 * Rollback plans are pure value objects: they inspect install or apply result
 * payloads, derive restore/delete/leave/blocked steps, summarize snapshot needs,
 * and serialize the result for Package UI, logs, and automation without touching
 * the filesystem.
 */
final class PanelPackageRollbackPlan implements \JsonSerializable {

	private array $installPlan;
	private array $meta=[];

	/**
	 * Captures the install or apply-result payload used to derive rollback steps.
	 *
	 * @param PanelPackageInstallPlan|PanelPackageApplyResult|array $installPlan Source payload from planning or applying a package.
	 * @param array<string,mixed> $meta Additional metadata merged into emitted manifests.
	 */
	public function __construct(PanelPackageInstallPlan|PanelPackageApplyResult|array $installPlan, array $meta=[]) {
		$this->installPlan=$installPlan instanceof PanelPackageInstallPlan
			? $installPlan->manifest()
			: ($installPlan instanceof PanelPackageApplyResult ? $installPlan->toArray() : $installPlan);
		$this->meta=$meta;
	}

	/**
	 * Creates a rollback plan from any supported source payload.
	 *
	 * @param PanelPackageInstallPlan|PanelPackageApplyResult|array $installPlan Source payload from planning or applying a package.
	 * @param array<string,mixed> $meta Additional metadata merged into emitted manifests.
	 * @return self Rollback plan value object.
	 */
	public static function make(PanelPackageInstallPlan|PanelPackageApplyResult|array $installPlan, array $meta=[]): self {
		return new self($installPlan, $meta);
	}

	/**
	 * Creates a rollback plan from an already-applied package result.
	 *
	 * Apply results include concrete written, skipped, backup, and blocked rows,
	 * so rollback output can reference actual backup paths instead of predicted
	 * snapshot keys.
	 *
	 * @param PanelPackageApplyResult $result Completed package apply result.
	 * @return self Rollback plan derived from actual apply state.
	 */
	public static function fromApplyResult(PanelPackageApplyResult $result): self {
		return new self($result);
	}

	/**
	 * Adds metadata that will be included in future manifests.
	 *
	 * Arrays merge over the current metadata map. String keys set one metadata
	 * value when the trimmed key is non-empty.
	 *
	 * @param array<string,mixed>|string $key Metadata map or single metadata key.
	 * @param mixed $value Value assigned for a string metadata key.
	 * @return self Same mutable rollback plan instance for fluent construction.
	 */
	public function meta(array|string $key, mixed $value=null): self {
		if(is_array($key)){
			$this->meta=array_replace($this->meta, $key);
			return $this;
		}
		$key=trim($key);
		if($key!==''){
			$this->meta[$key]=$value;
		}
		return $this;
	}

	/**
	 * Returns a rollback manifest derived from an install plan.
	 *
	 * Create steps roll back by deleting created targets, replace steps roll back
	 * by restoring snapshots, skips are left in place, and conflicts block plan
	 * readiness. Apply-result sources are delegated to manifestFromApplyResult().
	 *
	 * @param array<string,mixed> $meta Per-call metadata merged over constructor metadata.
	 * @return array{type:string,package:mixed,ready:bool,blocked:bool,install_ready:bool,summary:array{steps:int,snapshots:int,restores:int,deletes:int,leaves:int,blocked:int},steps:list<array{action:string,target:string,install_action:string,requires_snapshot:bool,snapshot_key:?string,reason:string}>,meta:array<string,mixed>} Rollback manifest.
	 */
	public function manifest(array $meta=[]): array {
		if(($this->installPlan['type'] ?? null)==='panel_package_apply_result'){
			return $this->manifestFromApplyResult($meta);
		}
		$steps=[];
		$snapshots=0;
		$restores=0;
		$deletes=0;
		$leaves=0;
		$blocked=0;
		foreach((array)($this->installPlan['steps'] ?? []) as $step){
			if(!is_array($step)){
				continue;
			}
			$action=(string)($step['action'] ?? '');
			$target=(string)($step['target'] ?? '');
			$rollbackAction='leave';
			$requiresSnapshot=false;
			$reason='No rollback work required.';
			if($action==='create'){
				$rollbackAction='delete';
				$deletes++;
				$reason='Remove file created by install.';
			}
			elseif($action==='replace'){
				$rollbackAction='restore';
				$requiresSnapshot=true;
				$snapshots++;
				$restores++;
				$reason='Restore file from backup snapshot.';
			}
			elseif($action==='skip'){
				$leaves++;
				$reason='Install skipped this existing file.';
			}
			elseif($action==='conflict'){
				$rollbackAction='blocked';
				$blocked++;
				$reason='Install plan has unresolved conflict.';
			}
			else{
				$leaves++;
			}
			$steps[]=[
				'action'=>$rollbackAction,
				'target'=>$target,
				'install_action'=>$action,
				'requires_snapshot'=>$requiresSnapshot,
				'snapshot_key'=>$requiresSnapshot ? hash('sha256', $target.'|'.($step['bytes'] ?? 0)) : null,
				'reason'=>$reason,
			];
		}
		return [
			'type'=>'panel_package_rollback_plan',
			'package'=>$this->installPlan['package']['id'] ?? null,
			'ready'=>$blocked===0,
			'blocked'=>$blocked>0,
			'install_ready'=>!empty($this->installPlan['ready']),
			'summary'=>[
				'steps'=>count($steps),
				'snapshots'=>$snapshots,
				'restores'=>$restores,
				'deletes'=>$deletes,
				'leaves'=>$leaves,
				'blocked'=>$blocked,
			],
			'steps'=>$steps,
			'meta'=>array_replace($this->meta, $meta),
		];
	}

	/**
	 * Returns the rollback manifest as an array.
	 *
	 * @return array<string,mixed> Rollback manifest emitted by manifest().
	 */
	public function toArray(): array {
		return $this->manifest();
	}

	/**
	 * Serializes the rollback plan manifest for preview or recovery execution.
	 *
	 * @return array<string,mixed> Rollback manifest emitted by toArray().
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Builds rollback steps from actual package apply output.
	 *
	 * Written files with backups are restored from those backup paths; written
	 * files without backups are deleted; skipped files are left untouched; blocked
	 * apply entries keep the rollback plan unready.
	 *
	 * @param array<string,mixed> $meta Per-call metadata merged over constructor metadata.
	 * @return array{type:string,source:string,package:mixed,ready:bool,blocked:bool,install_ready:bool,summary:array{steps:int,restores:int,deletes:int,leaves:int,blocked:int},steps:list<array<string,mixed>>,meta:array<string,mixed>} Rollback manifest.
	 */
	private function manifestFromApplyResult(array $meta=[]): array {
		$backupByTarget=[];
		foreach((array)($this->installPlan['backups'] ?? []) as $backup){
			if(!is_array($backup)){
				continue;
			}
			$target=(string)($backup['target'] ?? '');
			if($target!==''){
				$backupByTarget[$target]=$backup;
			}
		}
		$steps=[];
		$restores=0;
		$deletes=0;
		$leaves=0;
		$blocked=0;
		foreach((array)($this->installPlan['written'] ?? []) as $write){
			if(!is_array($write)){
				continue;
			}
			$target=(string)($write['target'] ?? '');
			$backup=$backupByTarget[$target] ?? null;
			if(is_array($backup) && (string)($backup['backup'] ?? '')!==''){
				$restores++;
				$steps[]=[
					'action'=>'restore',
					'target'=>$target,
					'backup'=>(string)$backup['backup'],
					'install_action'=>(string)($write['action'] ?? 'write'),
					'requires_snapshot'=>false,
					'snapshot_key'=>(string)$backup['backup'],
					'reason'=>'Restore file from apply backup.',
				];
				continue;
			}
			$deletes++;
			$steps[]=[
				'action'=>'delete',
				'target'=>$target,
				'install_action'=>(string)($write['action'] ?? 'write'),
				'requires_snapshot'=>false,
				'snapshot_key'=>null,
				'reason'=>'Remove file written by apply result.',
			];
		}
		foreach((array)($this->installPlan['skipped'] ?? []) as $skip){
			if(!is_array($skip)){
				continue;
			}
			$leaves++;
			$steps[]=[
				'action'=>'leave',
				'target'=>(string)($skip['target'] ?? ''),
				'install_action'=>'skip',
				'requires_snapshot'=>false,
				'snapshot_key'=>null,
				'reason'=>'Apply skipped this existing file.',
			];
		}
		foreach((array)($this->installPlan['blocked'] ?? []) as $block){
			if(!is_array($block)){
				continue;
			}
			$blocked++;
			$steps[]=[
				'action'=>'blocked',
				'target'=>(string)($block['target'] ?? ''),
				'install_action'=>(string)($block['action'] ?? 'blocked'),
				'requires_snapshot'=>false,
				'snapshot_key'=>null,
				'reason'=>(string)($block['reason'] ?? 'Apply result contains blocked work.'),
			];
		}
		return [
			'type'=>'panel_package_rollback_plan',
			'source'=>'apply_result',
			'package'=>$this->installPlan['package']['id'] ?? null,
			'ready'=>$blocked===0,
			'blocked'=>$blocked>0,
			'install_ready'=>!empty($this->installPlan['ok']),
			'summary'=>[
				'steps'=>count($steps),
				'snapshots'=>count($backupByTarget),
				'restores'=>$restores,
				'deletes'=>$deletes,
				'leaves'=>$leaves,
				'blocked'=>$blocked,
			],
			'steps'=>$steps,
			'meta'=>array_replace($this->meta, $meta),
		];
	}
}
