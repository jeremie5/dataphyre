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
	private bool $serializedSource=false;

	/**
	 * Captures the install or apply-result payload used to derive rollback steps.
	 *
	 * @param PanelPackageInstallPlan|PanelPackageApplyResult|array $installPlan Source payload from planning or applying a package.
	 * @param array<string,mixed> $meta Additional metadata merged into emitted manifests.
	 */
	public function __construct(PanelPackageInstallPlan|PanelPackageApplyResult|array $installPlan, array $meta=[]) {
		$this->serializedSource=is_array($installPlan);
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
	 * Executes a rollback derived from a concrete package apply result.
	 *
	 * The executor is fail-closed and mutation-atomic: it validates every target,
	 * stale installed digest, backup root, and backup digest before changing any
	 * file; locks the package root; revalidates under the lock; snapshots current
	 * targets; and restores those snapshots if any runtime mutation fails.
	 * Preview-only rollback plans cannot be executed because they contain no
	 * concrete backups or installed artifact digests.
	 *
	 * @param array{dry_run?:bool,force?:bool,target_root?:string,target_roots?:array<int,string>,backup_root?:string,backup_roots?:array<int,string>,lock_timeout_ms?:int,meta?:array<string,mixed>} $options Rollback safety and audit options.
	 */
	public function apply(array $options=[]): PanelPackageRollbackResult {
		$started=microtime(true);
		$startedAt=$this->timestamp($started);
		$dryRun=(bool)($options['dry_run'] ?? false);
		$force=(bool)($options['force'] ?? false);
		$manifest=$this->manifest();
		$root=$this->resolveExistingRoot((string)($this->installPlan['target_root'] ?? ''));
		$restored=[];
		$deleted=[];
		$skipped=[];
		$blocked=[];
		$reverted=[];
		$planned=[];
		if(($this->installPlan['type'] ?? null)!=='panel_package_apply_result'){
			$blocked[]=['action'=>'blocked','target'=>'','reason'=>'Only a concrete package apply result can be executed as a rollback.'];
		}
		else{
			foreach($this->sourceValidationErrors($force) as $reason){
				$blocked[]=['action'=>'blocked','target'=>$root,'reason'=>$reason];
			}
		}
		if($root===''){
			$blocked[]=['action'=>'blocked','target'=>(string)($this->installPlan['target_root'] ?? ''),'reason'=>'Package target root does not exist or cannot be resolved.'];
		}
		$hasExplicitTargetRoot=trim((string)($options['target_root'] ?? ''))!=='' || (is_array($options['target_roots'] ?? null) && $options['target_roots']!==[]);
		$targetRoots=$this->allowedTargetRoots($options);
		if($this->serializedSource && !$hasExplicitTargetRoot){
			$blocked[]=['action'=>'blocked','target'=>$root,'reason'=>'Serialized rollback sources require an explicit trusted target_root or target_roots option.'];
		}
		elseif($hasExplicitTargetRoot && ($root==='' || !$this->pathWithinAnyDirectoryRoot($root, $targetRoots))){
			$blocked[]=['action'=>'blocked','target'=>$root,'reason'=>'Package target root is outside the explicitly trusted rollback target roots.'];
		}
		$sourceMeta=is_array($this->installPlan['meta'] ?? null) ? $this->installPlan['meta'] : [];
		if((bool)($sourceMeta['dry_run'] ?? false)){
			$blocked[]=['action'=>'blocked','target'=>$root,'reason'=>'A dry-run package apply result has no filesystem changes to roll back.'];
		}
		$backupRoots=$this->allowedBackupRoots($options);
		foreach((array)($manifest['steps'] ?? []) as $step){
			$action=(string)($step['action'] ?? '');
			$target=(string)($step['target'] ?? '');
			if($action==='leave' || $action==='blocked'){
				$skipped[]=['action'=>$action,'target'=>$target,'reason'=>(string)($step['reason'] ?? 'No rollback mutation required.')];
				continue;
			}
			if($root==='' || !$this->pathWithinRoot($target, $root) || $this->pathContainsSymlink($target, $root)){
				$blocked[]=['action'=>$action,'target'=>$target,'reason'=>'Rollback target resolves outside the package target root.'];
				continue;
			}
			$exists=is_file($target);
			if($action==='delete' && !$exists){
				$skipped[]=['action'=>'delete','target'=>$target,'reason'=>'Created package file is already absent.'];
				continue;
			}
			$expectedInstalled=$this->normalizeDigest((string)($step['installed_sha256'] ?? ''));
			$actualInstalled=$exists ? (hash_file('sha256', $target) ?: '') : '';
			if($exists && !$force && ($expectedInstalled==='' || !hash_equals($expectedInstalled, $actualInstalled))){
				$blocked[]=[
					'action'=>$action,
					'target'=>$target,
					'reason'=>$expectedInstalled==='' ? 'Rollback lacks the installed artifact digest required for stale-file protection.' : 'Target changed after package installation; rollback refused to overwrite it.',
					'expected_sha256'=>$expectedInstalled,
					'actual_sha256'=>$actualInstalled,
				];
				continue;
			}
			$backup='';
			$expectedBackup='';
			$actualBackup='';
			if($action==='restore'){
				$backup=(string)($step['backup'] ?? '');
				$expectedBackup=$this->normalizeDigest((string)($step['backup_sha256'] ?? ''));
				$allowed=$backup!=='' && is_file($backup) && $this->pathWithinAnyRoot($backup, $backupRoots);
				if(!$allowed){
					$blocked[]=['action'=>'restore','target'=>$target,'backup'=>$backup,'reason'=>'Rollback backup is missing or outside the allowed backup roots.'];
					continue;
				}
				$actualBackup=hash_file('sha256', $backup) ?: '';
				if($expectedBackup==='' || !hash_equals($expectedBackup, $actualBackup)){
					$blocked[]=[
						'action'=>'restore','target'=>$target,'backup'=>$backup,
						'reason'=>$expectedBackup==='' ? 'Rollback lacks the backup digest required for integrity verification.' : 'Rollback backup digest does not match the apply audit record.',
						'expected_sha256'=>$expectedBackup,'actual_sha256'=>$actualBackup,
					];
					continue;
				}
			}
			$planned[]=[
				'action'=>$action,'target'=>$target,'backup'=>$backup,
				'installed_sha256'=>$expectedInstalled,'observed_sha256'=>$actualInstalled,
				'backup_sha256'=>$expectedBackup,'observed_backup_sha256'=>$actualBackup,
			];
		}
		if($blocked!==[]){
			return $this->result(false, $root, $restored, $deleted, $skipped, $blocked, $reverted, $started, $startedAt, $dryRun, $force, $options);
		}
		if($dryRun){
			foreach($planned as $step){
				$row=$step+['dry_run'=>true];
				if($step['action']==='restore'){$restored[]=$row;}else{$deleted[]=$row;}
			}
			return $this->result(true, $root, $restored, $deleted, $skipped, [], [], $started, $startedAt, true, $force, $options);
		}
		$lock=$this->acquireLock($root, max(0, min(10000, (int)($options['lock_timeout_ms'] ?? 2500))));
		if(!is_resource($lock)){
			$blocked[]=['action'=>'blocked','target'=>$root,'reason'=>'Package rollback lock could not be acquired.'];
			return $this->result(false, $root, [], [], $skipped, $blocked, [], $started, $startedAt, false, $force, $options);
		}
		$transactionRoot='';
		$snapshots=[];
		$mutationsStarted=false;
		$transactionRecovered=false;
		$preserveTransaction=false;
		try{
			foreach($planned as $step){
				$target=(string)$step['target'];
				if(!$this->pathWithinRoot($target, $root) || $this->pathContainsSymlink($target, $root)){
					throw new \RuntimeException('Rollback target became unsafe while the package lock was being acquired: '.$target);
				}
				$current=is_file($target) ? (hash_file('sha256', $target) ?: '') : '';
				if($current!==(string)$step['observed_sha256']){
					throw new \RuntimeException('Target changed while the rollback lock was being acquired: '.$target);
				}
				if($step['action']==='restore'){
					if(!$this->pathWithinAnyRoot((string)$step['backup'], $backupRoots)){
						throw new \RuntimeException('Rollback backup became unsafe while the package lock was being acquired: '.$target);
					}
					$currentBackup=is_file((string)$step['backup']) && !is_link((string)$step['backup']) ? (hash_file('sha256', (string)$step['backup']) ?: '') : '';
					if($currentBackup!==(string)$step['observed_backup_sha256']){
						throw new \RuntimeException('Backup changed while the rollback lock was being acquired: '.$target);
					}
				}
			}
			$transactionRoot=$this->transactionDirectory();
			foreach($planned as $index=>$step){
				$target=(string)$step['target'];
				$snapshot=[
					'target'=>$target,
					'existed'=>is_file($target),
					'snapshot'=>'',
					'mode'=>is_file($target) ? (fileperms($target) & 0777) : null,
				];
				if($snapshot['existed']){
					$snapshot['snapshot']=$transactionRoot.DIRECTORY_SEPARATOR.(string)$index.'.snapshot';
					if(!@copy($target, $snapshot['snapshot'])){
						throw new \RuntimeException('Current target could not be snapshotted: '.$target);
					}
					$snapshotDigest=hash_file('sha256', (string)$snapshot['snapshot']) ?: '';
					$currentDigest=hash_file('sha256', $target) ?: '';
					if($snapshotDigest==='' || $currentDigest==='' || !hash_equals($currentDigest, $snapshotDigest)){
						throw new \RuntimeException('Current target snapshot failed digest verification: '.$target);
					}
				}
				$snapshots[]=$snapshot;
			}
			$mutationsStarted=true;
			foreach($planned as $step){
				$target=(string)$step['target'];
				if(!$this->pathWithinRoot($target, $root) || $this->pathContainsSymlink($target, $root)){
					throw new \RuntimeException('Rollback target became unsafe immediately before mutation: '.$target);
				}
				$current=is_file($target) && !is_link($target) ? (hash_file('sha256', $target) ?: '') : '';
				if($current!==(string)$step['observed_sha256']){
					throw new \RuntimeException('Rollback target changed immediately before mutation: '.$target);
				}
				if($step['action']==='restore'){
					if(!$this->pathWithinAnyRoot((string)$step['backup'], $backupRoots) || (hash_file('sha256', (string)$step['backup']) ?: '')!==(string)$step['observed_backup_sha256']){
						throw new \RuntimeException('Rollback backup changed immediately before mutation: '.$target);
					}
					$this->replaceFromFile((string)$step['backup'], $target, (string)$step['observed_backup_sha256']);
					$restored[]=$step+['dry_run'=>false];
				}
				else{
					if((is_link($target) || (is_file($target) && !@unlink($target)))){
						throw new \RuntimeException('Installed package file could not be deleted: '.$target);
					}
					$deleted[]=$step+['dry_run'=>false];
				}
			}
		}
		catch(\Throwable $exception){
			$blocked[]=['action'=>'blocked','target'=>$root,'reason'=>$exception->getMessage()];
			$transactionRecovered=$mutationsStarted;
			foreach($mutationsStarted ? array_reverse($snapshots) : [] as $snapshot){
				$target=(string)$snapshot['target'];
				try{
					if(!empty($snapshot['existed'])){
						$this->replaceFromFile((string)$snapshot['snapshot'], $target, hash_file('sha256', (string)$snapshot['snapshot']) ?: '');
						if(is_int($snapshot['mode'])){@chmod($target, $snapshot['mode']);}
						$reverted[]=['action'=>'restore_transaction_snapshot','target'=>$target,'ok'=>true];
					}
					elseif(file_exists($target) || is_link($target)){
						$ok=!is_dir($target) && @unlink($target);
						$reverted[]=['action'=>'remove_transaction_write','target'=>$target,'ok'=>$ok];
						if(!$ok){$transactionRecovered=false;$blocked[]=['action'=>'blocked','target'=>$target,'reason'=>'Rollback transaction could not remove a partial write.'];}
					}
				}
				catch(\Throwable $revertException){
					$transactionRecovered=false;
					$reverted[]=['action'=>'restore_transaction_snapshot','target'=>$target,'ok'=>false,'reason'=>$revertException->getMessage()];
					$blocked[]=['action'=>'blocked','target'=>$target,'reason'=>'Rollback transaction recovery failed: '.$revertException->getMessage()];
				}
			}
			$restored=[];
			$deleted=[];
			$preserveTransaction=$mutationsStarted && !$transactionRecovered && $transactionRoot!=='';
		}
		finally{
			if($transactionRoot!=='' && !$preserveTransaction){$this->removeTree($transactionRoot);}
			@flock($lock, LOCK_UN);
			@fclose($lock);
		}
		return $this->result($blocked===[], $root, $restored, $deleted, $skipped, $blocked, $reverted, $started, $startedAt, false, $force, $options, $transactionRecovered, $preserveTransaction ? $transactionRoot : '');
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
				$backupByTarget[$this->portablePathKey($target)]=$backup;
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
			$backup=$backupByTarget[$this->portablePathKey($target)] ?? null;
			if(is_array($backup) && (string)($backup['backup'] ?? '')!==''){
				$restores++;
				$steps[]=[
					'action'=>'restore',
					'target'=>$target,
					'backup'=>(string)$backup['backup'],
					'installed_sha256'=>(string)($write['sha256'] ?? ''),
					'backup_sha256'=>(string)($backup['sha256'] ?? ''),
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
				'installed_sha256'=>(string)($write['sha256'] ?? ''),
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
			'target_root'=>(string)($this->installPlan['target_root'] ?? ''),
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

	/** @return string ISO-8601 timestamp for an epoch float. */
	private function timestamp(float $time): string {
		return (new \DateTimeImmutable('@'.(string)(int)$time))
			->setTimezone(new \DateTimeZone(date_default_timezone_get()))
			->format(DATE_ATOM);
	}

	/** @return PanelPackageRollbackResult Normalized rollback audit result. */
	private function result(
		bool $ok,
		string $root,
		array $restored,
		array $deleted,
		array $skipped,
		array $blocked,
		array $reverted,
		float $started,
		string $startedAt,
		bool $dryRun,
		bool $force,
		array $options,
		bool $transactionRecovered=false,
		string $transactionSnapshot=''
	): PanelPackageRollbackResult {
		$finished=microtime(true);
		$meta=[
			'atomic'=>true,
			'dry_run'=>$dryRun,
			'force'=>$force,
			'transaction_reverted'=>$transactionRecovered,
			'transaction_snapshot'=>$transactionSnapshot,
			'source_digest'=>hash('sha256', json_encode($this->installPlan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
		];
		if(is_array($options['meta'] ?? null)){
			$meta=array_replace($meta, $options['meta']);
		}
		return PanelPackageRollbackResult::make([
			'ok'=>$ok,
			'package'=>$this->installPlan['package']['id'] ?? null,
			'target_root'=>$root,
			'restored'=>$restored,
			'deleted'=>$deleted,
			'skipped'=>$skipped,
			'blocked'=>$blocked,
			'reverted'=>$reverted,
			'started_at'=>$startedAt,
			'finished_at'=>$this->timestamp($finished),
			'duration_ms'=>(int)round(($finished-$started)*1000),
			'meta'=>$meta,
		]);
	}

	/** @return string Canonical existing directory, or an empty string. */
	private function resolveExistingRoot(string $root): string {
		$root=trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root));
		if($root==='' || !is_dir($root)){
			return '';
		}
		$real=realpath($root);
		return $real===false ? '' : rtrim($real, DIRECTORY_SEPARATOR);
	}

	/** @return array<int,string> Fail-closed structural errors in a concrete apply-result source. */
	private function sourceValidationErrors(bool $force): array {
		$errors=[];
		$package=$this->installPlan['package'] ?? null;
		if(!is_array($package) || trim((string)($package['id'] ?? ''))===''){
			$errors[]='Rollback apply result does not identify a package.';
		}
		if(($this->installPlan['ok'] ?? false)!==true){
			$errors[]='Rollback apply result is not a successful completed installation.';
		}
		if(!is_array($this->installPlan['meta'] ?? [])){
			$errors[]='Rollback apply result has a malformed metadata section.';
		}
		$skipped=$this->installPlan['skipped'] ?? [];
		if(!is_array($skipped) || !array_is_list($skipped) || array_filter($skipped, static fn(mixed $row): bool => !is_array($row))!==[]){
			$errors[]='Rollback apply result has a malformed skipped section.';
		}
		foreach(['blocked','attempted','reverted'] as $section){
			if(!is_array($this->installPlan[$section] ?? [])){
				$errors[]='Rollback apply result has a malformed '.$section.' section.';
			}
			elseif(($this->installPlan[$section] ?? [])!==[]){
				$errors[]='Rollback apply result contains unresolved '.$section.' work.';
			}
		}
		$written=$this->installPlan['written'] ?? [];
		$backups=$this->installPlan['backups'] ?? [];
		if(!is_array($written) || !array_is_list($written)){
			return array_values(array_unique([...$errors, 'Rollback apply result has a malformed written section.']));
		}
		if(!is_array($backups) || !array_is_list($backups)){
			return array_values(array_unique([...$errors, 'Rollback apply result has a malformed backups section.']));
		}
		$writesByTarget=[];
		foreach($written as $write){
			if(!is_array($write)){
				$errors[]='Rollback apply result contains a malformed written row.';
				continue;
			}
			$target=trim((string)($write['target'] ?? ''));
			$key=$this->portablePathKey($target);
			$action=strtolower(trim((string)($write['action'] ?? '')));
			if($target==='' || isset($writesByTarget[$key])){
				$errors[]=$target==='' ? 'Rollback apply result contains a written row without a target.' : 'Rollback apply result contains duplicate written targets.';
				continue;
			}
			if(!in_array($action, ['create','replace'], true)){
				$errors[]='Rollback apply result contains an unsupported written action.';
			}
			$digestRaw=trim((string)($write['sha256'] ?? ''));
			if($this->normalizeDigest($digestRaw)==='' && !($force && $action==='create' && $digestRaw==='')){
				$errors[]='Rollback apply result contains a written row without a valid installed digest.';
			}
			$writesByTarget[$key]=['action'=>$action,'target'=>$target];
		}
		$backupsByTarget=[];
		foreach($backups as $backup){
			if(!is_array($backup)){
				$errors[]='Rollback apply result contains a malformed backup row.';
				continue;
			}
			$target=trim((string)($backup['target'] ?? ''));
			$key=$this->portablePathKey($target);
			if($target==='' || isset($backupsByTarget[$key])){
				$errors[]=$target==='' ? 'Rollback apply result contains a backup row without a target.' : 'Rollback apply result contains duplicate backup targets.';
				continue;
			}
			if(trim((string)($backup['backup'] ?? ''))==='' || $this->normalizeDigest((string)($backup['sha256'] ?? ''))===''){
				$errors[]='Rollback apply result contains a backup without a path and valid digest.';
			}
			if(!isset($writesByTarget[$key]) || ($writesByTarget[$key]['action'] ?? '')!=='replace'){
				$errors[]='Rollback apply result contains a backup that does not match a replacement write.';
			}
			$backupsByTarget[$key]=true;
		}
		foreach($writesByTarget as $key=>$write){
			if(($write['action'] ?? '')==='replace' && !isset($backupsByTarget[$key])){
				$errors[]='Rollback cannot restore a replacement write because its verified backup is missing.';
			}
		}
		return array_values(array_unique($errors));
	}

	/** @return string Case-folded target identity for cross-platform collision checks. */
	private function portablePathKey(string $path): string {
		return strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path)));
	}

	/** @return string Lowercase SHA-256 digest, or an empty string. */
	private function normalizeDigest(string $digest): string {
		$digest=strtolower(trim($digest));
		if(str_starts_with($digest, 'sha256:')){$digest=substr($digest, 7);}
		return preg_match('/^[a-f0-9]{64}$/', $digest)===1 ? $digest : '';
	}

	/** @return array<int,string> Canonical target roots explicitly trusted by the caller. */
	private function allowedTargetRoots(array $options): array {
		$roots=[];
		$candidates=is_array($options['target_roots'] ?? null) ? $options['target_roots'] : [];
		if(trim((string)($options['target_root'] ?? ''))!==''){$candidates[]=(string)$options['target_root'];}
		foreach($candidates as $candidate){
			$root=$this->resolveExistingRoot((string)$candidate);
			if($root!=='' && !in_array($root, $roots, true)){$roots[]=$root;}
		}
		return $roots;
	}

	/** @return bool Whether a directory is equal to or beneath an explicitly allowed directory root. */
	private function pathWithinAnyDirectoryRoot(string $path, array $roots): bool {
		$real=realpath($path);
		if($real===false || !is_dir($real)){return false;}
		foreach($roots as $root){if($this->pathPrefixMatches($real, (string)$root)){return true;}}
		return false;
	}

	/** @return array<int,string> Canonical backup roots explicitly allowed for restores. */
	private function allowedBackupRoots(array $options): array {
		$roots=[];
		$candidates=(array)($options['backup_roots'] ?? []);
		if(trim((string)($options['backup_root'] ?? ''))!==''){
			$candidates[]=(string)$options['backup_root'];
		}
		$sourceMeta=is_array($this->installPlan['meta'] ?? null) ? $this->installPlan['meta'] : [];
		if(!$this->serializedSource && $candidates===[] && trim((string)($sourceMeta['backup_root'] ?? ''))!==''){
			$candidates[]=(string)$sourceMeta['backup_root'];
		}
		foreach($candidates as $candidate){
			$root=$this->resolveExistingRoot((string)$candidate);
			if($root!=='' && !in_array($root, $roots, true)){
				$roots[]=$root;
			}
		}
		return $roots;
	}

	/** @return bool Whether a target remains beneath an existing root, including through symlink ancestors. */
	private function pathWithinRoot(string $path, string $root): bool {
		$path=$this->normalizeFilesystemPath($path);
		$root=$this->normalizeFilesystemPath($root);
		if($path==='' || $root==='' || !$this->pathPrefixMatches($path, $root)){
			return false;
		}
		$realRoot=realpath($root);
		if($realRoot===false){
			return false;
		}
		$ancestor=$path;
		while(!file_exists($ancestor) && !is_link($ancestor)){
			$parent=dirname($ancestor);
			if($parent===$ancestor){return false;}
			$ancestor=$parent;
		}
		$realAncestor=realpath($ancestor);
		return $realAncestor!==false && $this->pathPrefixMatches($realAncestor, $realRoot);
	}

	/** @return bool Whether a file resides beneath one of the canonical allowed roots. */
	private function pathWithinAnyRoot(string $path, array $roots): bool {
		if($roots===[] || !is_file($path) || is_link($path)){
			return false;
		}
		$real=realpath($path);
		if($real===false){return false;}
		foreach($roots as $root){
			if($this->pathPrefixMatches($real, (string)$root) && !$this->pathContainsSymlink($path, (string)$root)){
				return true;
			}
		}
		return false;
	}

	/** @return bool Whether the candidate or an ancestor beneath root is a symbolic link. */
	private function pathContainsSymlink(string $path, string $root): bool {
		$path=$this->normalizeFilesystemPath($path);
		$root=$this->normalizeFilesystemPath($root);
		if($path==='' || $root==='' || !$this->pathPrefixMatches($path, $root)){return true;}
		if(is_link($root)){return true;}
		$relative=ltrim(substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR);
		$current=rtrim($root, DIRECTORY_SEPARATOR);
		foreach($relative==='' ? [] : explode(DIRECTORY_SEPARATOR, $relative) as $segment){
			$current.=DIRECTORY_SEPARATOR.$segment;
			if(is_link($current)){return true;}
		}
		return false;
	}

	private function pathPrefixMatches(string $path, string $root): bool {
		return PanelFilesystemPath::prefixMatches($path, $root);
	}

	private function normalizeFilesystemPath(string $path): string {
		return PanelFilesystemPath::normalize($path);
	}

	/** @return resource|null Exclusive package-root lock, or null on timeout. */
	private function acquireLock(string $root, int $timeoutMs) {
		$directory=rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'dataphyre-panel-package-locks';
		if(is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))){
			return null;
		}
		@chmod($directory, 0700);
		$lockPath=$directory.DIRECTORY_SEPARATOR.hash('sha256', $root).'.lock';
		if(is_link($lockPath)){return null;}
		$handle=@fopen($lockPath, 'c+');
		if(!is_resource($handle)){
			return null;
		}
		$deadline=microtime(true)+($timeoutMs/1000);
		do{
			if(@flock($handle, LOCK_EX | LOCK_NB)){
				return $handle;
			}
			if($timeoutMs===0){break;}
			usleep(10000);
		}while(microtime(true)<$deadline);
		@fclose($handle);
		return null;
	}

	/** @return string Fresh private transaction directory. */
	private function transactionDirectory(): string {
		$base=rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'dataphyre-panel-package-transactions';
		if(is_link($base) || (!is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base))){
			throw new \RuntimeException('Package rollback transaction directory could not be created.');
		}
		@chmod($base, 0700);
		try{$suffix=bin2hex(random_bytes(12));}catch(\Throwable){$suffix=str_replace('.', '', uniqid('', true));}
		$directory=$base.DIRECTORY_SEPARATOR.$suffix;
		if(!@mkdir($directory, 0700, false)){
			throw new \RuntimeException('Package rollback transaction snapshot could not be created.');
		}
		return $directory;
	}

	/** Atomically replaces one target with verified bytes copied from a source file. */
	private function replaceFromFile(string $source, string $target, string $expectedSha256): void {
		if(!is_file($source) || is_link($source) || is_link($target)){
			throw new \RuntimeException('Rollback source file no longer exists: '.$target);
		}
		$directory=dirname($target);
		if(!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)){
			throw new \RuntimeException('Rollback target directory could not be created: '.$target);
		}
		try{$suffix=bin2hex(random_bytes(8));}catch(\Throwable){$suffix=str_replace('.', '', uniqid('', true));}
		$temp=$directory.DIRECTORY_SEPARATOR.'.'.basename($target).'.dp-rollback-'.$suffix.'.tmp';
		$displaced=$directory.DIRECTORY_SEPARATOR.'.'.basename($target).'.dp-rollback-displaced-'.$suffix.'.tmp';
		$moved=false;
		try{
			if(!@copy($source, $temp)){
				throw new \RuntimeException('Rollback source could not be staged: '.$target);
			}
			$actual=hash_file('sha256', $temp) ?: '';
			if($expectedSha256==='' || !hash_equals($expectedSha256, $actual)){
				throw new \RuntimeException('Staged rollback bytes failed digest verification: '.$target);
			}
			if(is_file($target)){
				$moved=@rename($target, $displaced);
				if(!$moved){throw new \RuntimeException('Rollback target could not be displaced: '.$target);}
			}
			if(!@link($temp, $target)){
				if($moved && !@rename($displaced, $target)){
					throw new \RuntimeException('Staged rollback bytes could not be published and the original target could not be restored: '.$target);
				}
				throw new \RuntimeException('Staged rollback bytes could not be published: '.$target);
			}
			@unlink($temp);
			$published=hash_file('sha256', $target) ?: '';
			if($published==='' || !hash_equals($expectedSha256, $published)){
				@unlink($target);
				if($moved){@rename($displaced, $target);}
				throw new \RuntimeException('Published rollback bytes failed digest verification: '.$target);
			}
			if($moved && is_file($displaced)){@unlink($displaced);}
		}
		finally{
			if(is_file($temp) || is_link($temp)){@unlink($temp);}
		}
	}

	/** Removes only an internally created transaction tree. */
	private function removeTree(string $directory): void {
		$base=realpath(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'dataphyre-panel-package-transactions');
		$real=realpath($directory);
		if($base===false || $real===false || !$this->pathPrefixMatches($real, $base) || $real===$base){
			return;
		}
		$iterator=new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach($iterator as $item){
			if($item->isDir() && !$item->isLink()){@rmdir($item->getPathname());}
			else{@unlink($item->getPathname());}
		}
		@rmdir($real);
	}
}
