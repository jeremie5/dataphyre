<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Plans and applies a panel package template into a target filesystem root.
 *
 * Install plans combine a package template, runtime compatibility matrix, trust
 * policy, overwrite behavior, and caller metadata into a serializable manifest.
 * Applying a plan is the filesystem boundary for panel package installation:
 * it validates that artifact paths remain inside the target root, honors dry-run
 * requests, records skipped and blocked steps, and optionally backs up replaced
 * files before writing package artifacts.
 */
final class PanelPackageInstallPlan implements \JsonSerializable {

	private PanelPackageTemplate $template;
	private string $targetPath='';
	private array $runtime=[];
	private ?PanelPackageTrustPolicy $trustPolicy=null;
	private ?PanelPackageSignatureVerifier $signatureVerifier=null;
	private ?\Closure $activationGate=null;
	private string $overwritePolicy='fail';
	private array $meta=[];

	/**
	 * Creates an install plan for a package template.
	 *
	 * Supported options are `runtime` for compatibility evaluation,
	 * `trust_policy` for package trust checks, `signature_verifier` for real
	 * artifact-bundle signature verification, `activation_gate` for a process-local
	 * final marketplace or deployment policy check, `overwrite_policy` for conflict
	 * handling (`fail`, `skip`, or `replace`), and `meta` for diagnostics carried
	 * into manifests. The target path is normalized to a slash-separated package
	 * relative path; absolute filesystem resolution is deferred until apply().
	 *
	 * @param PanelPackageTemplate $template Template containing package metadata and artifacts.
	 * @param string $targetPath Optional package-relative target path used by manifest previews.
	 * @param array{runtime?: array<string, mixed>, trust_policy?: PanelPackageTrustPolicy, signature_verifier?: PanelPackageSignatureVerifier, activation_gate?: callable, overwrite_policy?: string, meta?: array<string, mixed>} $options Plan options.
	 */
	public function __construct(PanelPackageTemplate $template, string $targetPath='', array $options=[]) {
		$this->template=$template;
		$this->target($targetPath);
		$this->runtime=is_array($options['runtime'] ?? null) ? $options['runtime'] : PanelCompatibilityMatrix::defaultRuntime();
		if(($options['trust_policy'] ?? null) instanceof PanelPackageTrustPolicy){
			$this->trustPolicy=$options['trust_policy'];
		}
		if(($options['signature_verifier'] ?? null) instanceof PanelPackageSignatureVerifier){
			$this->signatureVerifier=$options['signature_verifier'];
		}
		if(is_callable($options['activation_gate'] ?? null)){
			$this->activationGate=\Closure::fromCallable($options['activation_gate']);
		}
		if(isset($options['overwrite_policy'])){
			$this->overwritePolicy((string)$options['overwrite_policy']);
		}
		if(isset($options['meta']) && is_array($options['meta'])){
			$this->meta($options['meta']);
		}
	}

	/**
	 * Creates a fluent install plan instance.
	 *
	 *
	 * @param string $targetPath Optional package-relative target path used by manifest previews.
	 * @param array<string, mixed> $options Constructor options.
	 * @return self Newly configured install plan.
	 */
	public static function make(PanelPackageTemplate $template, string $targetPath='', array $options=[]): self {
		return new self($template, $targetPath, $options);
	}

	/**
	 * Sets the manifest preview target path.
	 *
	 * The stored value is slash-normalized and trimmed. It is not treated as a
	 * trusted filesystem root; apply() resolves and validates the real root before
	 * any write or backup operation.
	 *
	 * @param string $path Package-relative target path for previews.
	 * @return self Fluent plan instance.
	 */
	public function target(string $path): self {
		$this->targetPath=rtrim(str_replace('\\', '/', trim($path)), '/');
		return $this;
	}

	/**
	 * Replaces the runtime matrix used for compatibility checks.
	 *
	 *
	 * @return self Fluent plan instance.
	 */
	public function runtime(array $runtime): self {
		$this->runtime=$runtime;
		return $this;
	}

	/**
	 * Sets or clears the trust policy used before installation.
	 *
	 * A null policy means the package is not trust-gated by this plan. When a
	 * policy is provided, buildManifest() and apply() both expose the policy
	 * decision and block installation unless the package is trusted.
	 *
	 * @param ?PanelPackageTrustPolicy $policy Trust policy evaluator, or null to disable trust gating.
	 * @return self Fluent plan instance.
	 */
	public function trustPolicy(?PanelPackageTrustPolicy $policy): self {
		$this->trustPolicy=$policy;
		return $this;
	}

	/**
	 * Sets or clears cryptographic verification for the complete artifact bundle.
	 *
	 * When configured, both manifest previews and apply() fail closed unless the
	 * detached package signature, declared digest, public-key id, and every
	 * artifact digest verify successfully.
	 */
	public function signatureVerifier(?PanelPackageSignatureVerifier $verifier): self {
		$this->signatureVerifier=$verifier;
		return $this;
	}

	/**
	 * Sets or clears the process-local activation gate.
	 *
	 * The callback is never serialized. It receives a redacted context containing
	 * only the phase and package identity, and may return a boolean or a decision
	 * array. Array decisions must explicitly be complete, current, unrevoked,
	 * unblocked, and allowed. Exceptions and malformed decisions fail closed.
	 */
	public function activationGate(?callable $gate): self {
		$this->activationGate=$gate!==null ? \Closure::fromCallable($gate) : null;
		return $this;
	}

	/**
	 * Sets the conflict policy for existing target files.
	 *
	 * Accepted values are normalized through Resource::normalizeName(). Unknown
	 * values fall back to `fail` so callers must opt into skipping or replacing
	 * existing files explicitly.
	 *
	 * @param string $policy One of `fail`, `skip`, or `replace`.
	 * @return self Fluent plan instance.
	 */
	public function overwritePolicy(string $policy): self {
		$policy=Resource::normalizeName($policy);
		$this->overwritePolicy=in_array($policy, ['fail', 'skip', 'replace'], true) ? $policy : 'fail';
		return $this;
	}

	/**
	 * Adds diagnostic metadata to future manifests and apply results.
	 *
	 * Metadata is copied into manifest previews and apply results without
	 * influencing compatibility, trust, or path validation.
	 *
	 * @param array<string, mixed>|string $key Metadata map to merge, or a single metadata key.
	 * @param mixed $value Value used when `$key` is a string.
	 * @return self Fluent plan instance.
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
	 * Builds a dry manifest without touching the filesystem.
	 *
	 * The returned payload describes compatibility, trust, overwrite policy,
	 * artifact steps, byte counts, and whether the plan is ready. Because no real
	 * target root is supplied here, conflict checks use the preview target path
	 * currently stored on the plan.
	 *
	 * @param array<string, mixed> $meta Metadata merged over plan metadata for this manifest only.
	 * @return array<string, mixed> Serializable install manifest with `type=panel_package_install_plan`.
	 */
	public function manifest(array $meta=[]): array {
		return $this->buildManifest($meta, $this->overwritePolicy);
	}

	/**
	 * Applies the install plan to a filesystem root.
	 *
	 * Apply first resolves the target root, rebuilds the manifest against that
	 * root, and then executes each step. Writes are blocked when compatibility,
	 * trust, path containment, conflict handling, backups, or directory creation
	 * fail. With `dry_run=true`, no directories, backups, or files are written,
	 * but the result still reports the actions that would have occurred.
	 *
	 * @param string $targetRoot Filesystem root that package artifacts must remain inside.
	 * @param array{dry_run?: bool, atomic?: bool, overwrite?: bool, overwrite_policy?: string, backup_root?: string, lock_timeout_ms?: int, meta?: array<string, mixed>} $options Apply-time behavior overrides.
	 * @return PanelPackageApplyResult Structured result containing written, skipped, blocked, backup, timing, and metadata sections.
	 */
	public function apply(string $targetRoot, array $options=[]): PanelPackageApplyResult {
		$started=microtime(true);
		$startedAt=(new \DateTimeImmutable('@'.(string)(int)$started))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format(DATE_ATOM);
		$dryRun=(bool)($options['dry_run'] ?? false);
		$atomic=!array_key_exists('atomic', $options) || (bool)$options['atomic'];
		$overwritePolicy=$this->effectiveOverwritePolicy($options);
		$root=$this->resolveRoot($targetRoot, !$dryRun);
		$manifest=$this->buildManifest(is_array($options['meta'] ?? null) ? $options['meta'] : [], $overwritePolicy, $root);
		$packageId=(string)($manifest['package']['id'] ?? 'panel_package');
		$dialbackPayload=[
			'package_id'=>$packageId,
			'target_root'=>$root,
			'dry_run'=>$dryRun,
			'overwrite_policy'=>$overwritePolicy,
			'plan_blocked'=>!empty($manifest['blocked']),
			'step_count'=>is_countable($manifest['steps'] ?? null) ? count($manifest['steps']) : 0,
		];
		$dialback=is_callable(['\\dataphyre\\core', 'dialback'])
			? \dataphyre\core::dialback('CALL_PANEL_FRAMEWORK_PACKAGE_BEFORE_APPLY', $dialbackPayload)
			: null;
		if($dialback instanceof PanelPackageApplyResult){
			return $dialback;
		}
		if(is_array($dialback)){
			return PanelPackageApplyResult::make($dialback);
		}
		if($this->signatureVerifier instanceof PanelPackageSignatureVerifier){
			$postVerification=$this->signatureVerifier->verify($this->template)->toArray();
			$plannedDigest=(string)($manifest['verification']['digest'] ?? '');
			$currentDigest=(string)($postVerification['digest'] ?? '');
			if(($postVerification['ok'] ?? false)!==true || $plannedDigest==='' || $currentDigest==='' || !hash_equals($plannedDigest, $currentDigest)){
				$manifest['ready']=false;
				$manifest['blocked']=true;
				$manifest['verification']=$postVerification;
				$manifest['meta']['template_changed_after_planning']=true;
			}
		}
		$artifacts=[];
		foreach($this->template->artifacts() as $artifact){
			$path=$this->normalizeArtifactPath((string)($artifact['path'] ?? ''));
			$key=strtolower($path);
			if($path!=='' && !isset($artifacts[$key])){
				$artifacts[$key]=$artifact;
			}
		}
		$written=[];
		$skipped=[];
		$backups=[];
		$blocked=[];
		$attempted=[];
		$reverted=[];
		$transactionWrites=[];
		$transactionRoot='';
		$backupNamespace=$this->backupNamespace();
		$lock=null;
		$planBlocked=!empty($manifest['blocked']);
		if($root===''){
			$blocked[]=[
				'action'=>'blocked',
				'path'=>'',
				'target'=>$targetRoot,
				'reason'=>'Target root could not be resolved.',
			];
		}
		if($planBlocked && empty($manifest['summary']['conflicts'])){
			$blocked[]=[
				'action'=>'blocked',
				'path'=>'',
				'target'=>$root,
				'reason'=>'Install plan is blocked by compatibility or trust policy, activation policy, signature verification, or artifact validation.',
			];
		}
		$requiresLock=$atomic || $this->activationGate instanceof \Closure;
		if(!$dryRun && $requiresLock && $root!=='' && !$planBlocked){
			$lock=$this->acquirePackageLock($root, max(0, min(10000, (int)($options['lock_timeout_ms'] ?? 2500))));
			if(!is_resource($lock)){
				$blocked[]=[
					'action'=>'blocked',
					'path'=>'',
					'target'=>$root,
					'reason'=>'Package install lock could not be acquired.',
				];
				$planBlocked=true;
			}
		}
		if(!$dryRun && !$planBlocked && $this->activationGate instanceof \Closure){
			$activation=$this->evaluateActivationGate('activation');
			$manifest['activation_gate']=$activation;
			if(($activation['allowed'] ?? false)!==true){
				$manifest['ready']=false;
				$manifest['blocked']=true;
				$planBlocked=true;
				$blocked[]=[
					'action'=>'blocked',
					'path'=>'',
					'target'=>$root,
					'reason'=>'Package activation policy changed after planning; no artifact was published.',
				];
			}
		}
		foreach((array)($manifest['steps'] ?? []) as $step){
			$path=(string)($step['path'] ?? '');
			$action=(string)($step['action'] ?? '');
			$target=$this->joinPath($root, $path);
			if($path==='' || $root==='' || !$this->pathWithinRoot($target, $root)){
				$blocked[]=[
					'action'=>'blocked',
					'path'=>$path,
					'target'=>$target,
					'reason'=>'Artifact target resolves outside the target root.',
				];
				continue;
			}
			if($action==='skip'){
				$skipped[]=[
					'action'=>'skip',
					'path'=>$path,
					'target'=>$target,
					'reason'=>'Existing file skipped by overwrite policy.',
				];
				continue;
			}
			if($planBlocked){
				$blocked[]=[
					'action'=>$action==='conflict' ? 'conflict' : 'blocked',
					'path'=>$path,
					'target'=>$target,
					'reason'=>$action==='conflict' ? 'Existing file conflicts with overwrite policy.' : 'Install plan is not ready.',
				];
				continue;
			}
			$artifactKey=strtolower($path);
			if(!isset($artifacts[$artifactKey])){
				$blocked[]=[
					'action'=>'blocked',
					'path'=>$path,
					'target'=>$target,
					'reason'=>'Artifact contents are unavailable.',
				];
				continue;
			}
			$contents=(string)($artifacts[$artifactKey]['contents'] ?? '');
			$expectedExisting=(string)($step['existing_sha256'] ?? '');
			if(!$dryRun){
				if($this->pathContainsSymlink($target, $root)){
					$blocked[]=[
						'action'=>'blocked','path'=>$path,'target'=>$target,
						'reason'=>'Artifact target or one of its ancestors is a symbolic link.',
					];
					continue;
				}
				$targetPresent=file_exists($target) || is_link($target);
				if($action==='create' && $targetPresent){
					$blocked[]=[
						'action'=>'blocked','path'=>$path,'target'=>$target,
						'reason'=>'Artifact target appeared after package planning and could not be written safely; install refused the race.',
					];
					continue;
				}
				if($action==='replace'){
					$current=is_file($target) && !is_link($target) ? (hash_file('sha256', $target) ?: '') : '';
					if($expectedExisting==='' || $current==='' || !hash_equals($expectedExisting, $current)){
						$blocked[]=[
							'action'=>'blocked','path'=>$path,'target'=>$target,
							'reason'=>$current==='' ? 'Replacement target disappeared or is not a regular file.' : 'Replacement target changed after package planning; install refused stale bytes.',
							'expected_sha256'=>$expectedExisting,'actual_sha256'=>$current,
						];
						continue;
					}
				}
			}
			$backup=null;
			if($action==='replace' && is_file($target) && !is_link($target)){
				$backup=$this->backupTarget($target, $path, $packageId, (string)($options['backup_root'] ?? ''), $dryRun, $backupNamespace, $expectedExisting);
				if(($options['backup_root'] ?? '')!=='' && $backup===null){
					$blocked[]=[
						'action'=>'blocked',
						'path'=>$path,
						'target'=>$target,
						'reason'=>'Existing file could not be backed up.',
					];
					continue;
				}
				if($backup!==null){
					$backups[]=$backup;
				}
			}
			if(!$dryRun){
				$directory=dirname($target);
				if(!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)){
					$blocked[]=[
						'action'=>'blocked',
						'path'=>$path,
						'target'=>$target,
						'reason'=>'Target directory could not be created.',
					];
					continue;
				}
				if(!$this->pathWithinRoot($target, $root) || $this->pathContainsSymlink($target, $root)){
					$blocked[]=[
						'action'=>'blocked',
						'path'=>$path,
						'target'=>$target,
						'reason'=>'Artifact target changed to a location outside the target root or through a symbolic-link ancestor.',
					];
					continue;
				}
				$snapshot='';
				$existed=is_file($target) && !is_link($target);
				if($atomic){
					if($action==='create' && $existed){
						$blocked[]=[
							'action'=>'blocked','path'=>$path,'target'=>$target,
							'reason'=>'Artifact target appeared after package planning; atomic install refused the race.',
						];
						continue;
					}
					if($action==='replace' && !$existed){
						$blocked[]=[
							'action'=>'blocked','path'=>$path,'target'=>$target,
							'reason'=>'Replacement target disappeared after package planning; atomic install refused the race.',
						];
						continue;
					}
					if($transactionRoot===''){
						$transactionRoot=$this->transactionDirectory();
						if($transactionRoot===''){
							$blocked[]=[
								'action'=>'blocked','path'=>$path,'target'=>$target,
								'reason'=>'Atomic package transaction snapshot could not be created.',
							];
							continue;
						}
					}
					if($existed){
						$snapshot=$transactionRoot.DIRECTORY_SEPARATOR.(string)count($transactionWrites).'.snapshot';
						if(!@copy($target, $snapshot)){
							$blocked[]=[
								'action'=>'blocked','path'=>$path,'target'=>$target,
								'reason'=>'Existing target could not be snapshotted for atomic install.',
							];
							continue;
						}
						$snapshotDigest=hash_file('sha256', $snapshot) ?: '';
						$currentDigest=hash_file('sha256', $target) ?: '';
						if($expectedExisting==='' || $snapshotDigest==='' || $currentDigest==='' || !hash_equals($expectedExisting, $snapshotDigest) || !hash_equals($expectedExisting, $currentDigest)){
							@unlink($snapshot);
							$blocked[]=[
								'action'=>'blocked','path'=>$path,'target'=>$target,
								'reason'=>'Atomic install snapshot did not match the planned replacement digest.',
							];
							continue;
						}
					}
				}
				$mode=$existed ? (fileperms($target) & 0777) : null;
				$published=$this->publishArtifact($target, $contents, $action, $expectedExisting, $root, $mode);
				if(!empty($published['touched']) && $atomic){
					$transactionWrites[]=[
						'action'=>$action,'path'=>$path,'target'=>$target,'snapshot'=>$snapshot,
						'existed'=>$existed,'mode'=>$mode,'sha256'=>hash('sha256', $contents),
					];
				}
				if(empty($published['ok'])){
					$blocked[]=[
						'action'=>'blocked',
						'path'=>$path,
						'target'=>$target,
						'reason'=>(string)($published['reason'] ?? 'File could not be published atomically.'),
					];
					continue;
				}
			}
			$written[]=[
				'action'=>$action,
				'path'=>$path,
				'target'=>$target,
				'bytes'=>strlen($contents),
				'sha256'=>hash('sha256', $contents),
				'dry_run'=>$dryRun,
				'backup'=>$backup['backup'] ?? null,
			];
		}
		$transactionRecovered=false;
		if(!$dryRun && $atomic && $blocked!==[] && $transactionWrites!==[]){
			$attempted=$transactionWrites;
			$transactionRecovered=true;
			foreach(array_reverse($transactionWrites) as $write){
				$target=(string)($write['target'] ?? '');
				$snapshot=(string)($write['snapshot'] ?? '');
				if(!empty($write['existed'])){
					$ok=$snapshot!=='' && is_file($snapshot) && $this->replaceFromSnapshot($snapshot, $target);
					if($ok && is_int($write['mode'] ?? null)){@chmod($target, (int)$write['mode']);}
					$reverted[]=['action'=>'restore_transaction_snapshot','path'=>(string)($write['path'] ?? ''),'target'=>$target,'ok'=>$ok];
				}
				else{
					$ok=(!file_exists($target) && !is_link($target)) || @unlink($target);
					$reverted[]=['action'=>'remove_transaction_write','path'=>(string)($write['path'] ?? ''),'target'=>$target,'ok'=>$ok];
				}
				if(!$ok){
					$transactionRecovered=false;
					$blocked[]=[
						'action'=>'blocked','path'=>(string)($write['path'] ?? ''),'target'=>$target,
						'reason'=>'Atomic package transaction recovery failed.',
					];
				}
			}
			if($transactionRecovered){
				$written=[];
			}
		}
		$preserveTransaction=$transactionRoot!=='' && $blocked!==[] && $transactionWrites!==[] && !$transactionRecovered;
		if($transactionRoot!=='' && !$preserveTransaction){
			$this->removeTransactionTree($transactionRoot);
		}
		if(is_resource($lock)){
			@flock($lock, LOCK_UN);
			@fclose($lock);
		}
		$finished=microtime(true);
		$finishedAt=(new \DateTimeImmutable('@'.(string)(int)$finished))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format(DATE_ATOM);
		$resultMeta=[
			'dry_run'=>$dryRun,
			'atomic'=>$atomic,
			'transaction_reverted'=>$transactionRecovered,
			'transaction_snapshot'=>$preserveTransaction ? $transactionRoot : '',
			'overwrite_policy'=>$overwritePolicy,
			'backup_root'=>(string)($options['backup_root'] ?? ''),
			'activation_gate_configured'=>(bool)($manifest['activation_gate']['configured'] ?? false),
			'activation_gate_passed'=>(bool)($manifest['activation_gate']['allowed'] ?? true),
			'activation_gate_phase'=>(string)($manifest['activation_gate']['phase'] ?? ''),
		];
		if(is_array($manifest['verification'] ?? null)){
			$resultMeta['signature_verified']=(bool)($manifest['verification']['ok'] ?? false);
			$resultMeta['verification_digest']=(string)($manifest['verification']['digest'] ?? '');
		}
		$result=PanelPackageApplyResult::make([
			'ok'=>$blocked===[],
			'package'=>$manifest['package'] ?? [],
			'target_root'=>$root,
			'written'=>$written,
			'skipped'=>$skipped,
			'backups'=>$backups,
			'blocked'=>$blocked,
			'attempted'=>$attempted,
			'reverted'=>$reverted,
			'started_at'=>$startedAt,
			'finished_at'=>$finishedAt,
			'duration_ms'=>(int)round(($finished - $started) * 1000),
			'meta'=>$resultMeta,
		]);
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Panel package apply '.($result->ok() ? 'succeeded' : 'blocked').'; package='.$packageId.'; dry_run='.($dryRun ? 'yes' : 'no').'; written='.count($result->written()).'; skipped='.count($result->skipped()).'; blocked='.count($result->blocked()).'; backups='.count($result->backups()), $S=$result->ok() ? 'info' : 'warning');
		$dialback=is_callable(['\\dataphyre\\core', 'dialback']) ? \dataphyre\core::dialback('CALL_PANEL_FRAMEWORK_PACKAGE_AFTER_APPLY', $dialbackPayload+[
			'ok'=>$result->ok(),
			'counts'=>[
				'written'=>count($result->written()),
				'skipped'=>count($result->skipped()),
				'blocked'=>count($result->blocked()),
				'backups'=>count($result->backups()),
			],
			'duration_ms'=>$result->toArray()['duration_ms'] ?? 0,
		]) : null;
		if($dialback instanceof PanelPackageApplyResult){
			return $dialback;
		}
		return is_array($dialback) ? PanelPackageApplyResult::make($dialback) : $result;
	}

	/**
	 * Builds the internal install manifest used by previews and apply().
	 *
	 * Compatibility and trust are evaluated once per manifest. Each artifact is
	 * normalized to a package-relative path and classified as `create`, `replace`,
	 * `skip`, or `conflict` according to the effective overwrite policy and the
	 * existence of the target file. A blocked manifest prevents apply() from
	 * writing any artifact except for skip accounting.
	 *
	 * @param array<string, mixed> $meta Metadata merged over plan metadata.
	 * @param string $overwritePolicy Effective conflict policy for this build.
	 * @param ?string $targetRoot Resolved filesystem root for apply, or null for preview manifests.
	 * @return array<string, mixed> Serializable install manifest.
	 */
	private function buildManifest(array $meta=[], string $overwritePolicy='fail', ?string $targetRoot=null): array {
		$package=$this->template->package();
		$compatibility=$package->compatibility($this->runtime);
		$trust=$this->trustPolicy instanceof PanelPackageTrustPolicy ? $this->trustPolicy->evaluate($package) : null;
		$verification=$this->signatureVerifier instanceof PanelPackageSignatureVerifier
			? $this->signatureVerifier->verify($this->template)->toArray()
			: null;
		$activation=$this->evaluateActivationGate('preflight');
		$steps=[];
		$bytes=0;
		$creates=0;
		$replaces=0;
		$skips=0;
		$conflicts=0;
		$invalid=0;
		$seenPaths=[];
		foreach($this->template->artifacts() as $artifact){
			$path=$this->normalizeArtifactPath((string)($artifact['path'] ?? ''));
			$collisionKey=strtolower($path);
			if($path==='' || isset($seenPaths[$collisionKey])){
				$invalid++;
				continue;
			}
			$seenPaths[$collisionKey]=true;
			$targetBase=$targetRoot ?? $this->targetPath;
			$target=$targetBase!=='' ? $targetBase.'/'.$path : $path;
			$exists=$targetBase!=='' && is_file(str_replace('/', DIRECTORY_SEPARATOR, $target));
			$action='create';
			$blocked=false;
			if($exists){
				if($overwritePolicy==='replace'){
					$action='replace';
					$replaces++;
				}
				elseif($overwritePolicy==='skip'){
					$action='skip';
					$skips++;
				}
				else{
					$action='conflict';
					$blocked=true;
					$conflicts++;
				}
			}
			else{
				$creates++;
			}
			$bytes+=(int)($artifact['bytes'] ?? 0);
			$steps[]=[
				'action'=>$action,
				'blocked'=>$blocked,
				'path'=>$path,
				'target'=>$target,
				'exists'=>$exists,
				'existing_sha256'=>$exists ? (hash_file('sha256', str_replace('/', DIRECTORY_SEPARATOR, $target)) ?: '') : '',
				'kind'=>(string)($artifact['kind'] ?? 'asset'),
				'bytes'=>(int)($artifact['bytes'] ?? 0),
			];
		}
		$blocked=!($compatibility['ok'] ?? false)
			|| ($trust!==null && ($trust['trusted'] ?? false)!==true)
			|| ($verification!==null && ($verification['ok'] ?? false)!==true)
			|| (($activation['configured'] ?? false)===true && ($activation['allowed'] ?? false)!==true)
			|| $invalid>0
			|| $conflicts>0;
		$manifest=[
			'type'=>'panel_package_install_plan',
			'package'=>$package->toArray($this->runtime),
			'target'=>$targetRoot ?? $this->targetPath,
			'ready'=>!$blocked,
			'blocked'=>$blocked,
			'overwrite_policy'=>$overwritePolicy,
			'compatibility'=>$compatibility,
			'trust'=>$trust,
			'activation_gate'=>$activation,
			'summary'=>[
				'steps'=>count($steps),
				'creates'=>$creates,
				'replaces'=>$replaces,
				'skips'=>$skips,
				'conflicts'=>$conflicts,
				'invalid'=>$invalid,
				'bytes'=>$bytes,
			],
			'steps'=>$steps,
			'meta'=>array_replace($this->meta, $meta),
		];
		if($verification!==null){
			$manifest['verification']=$verification;
		}
		return $manifest;
	}

	/** @return array<string,mixed> Redacted activation decision. */
	private function evaluateActivationGate(string $phase): array {
		$phase=$phase==='activation' ? 'activation' : 'preflight';
		if(!$this->activationGate instanceof \Closure){
			return [
				'configured'=>false,'checked'=>false,'allowed'=>true,'complete'=>true,
				'stale'=>false,'revoked'=>false,'blocked'=>false,'phase'=>$phase,
				'reason_codes'=>[],'callback_serialized'=>false,
			];
		}
		$package=$this->template->package();
		try{
			$raw=($this->activationGate)([
				'phase'=>$phase,
				'package'=>['id'=>$package->id(),'version'=>(string)$package->version()],
			]);
		}
		catch(\Throwable){
			return [
				'configured'=>true,'checked'=>true,'allowed'=>false,'complete'=>false,
				'stale'=>true,'revoked'=>false,'blocked'=>true,'phase'=>$phase,
				'reason_codes'=>['activation_gate_unavailable'],'callback_serialized'=>false,
			];
		}
		if(is_bool($raw)){
			return [
				'configured'=>true,'checked'=>true,'allowed'=>$raw,'complete'=>true,
				'stale'=>false,'revoked'=>false,'blocked'=>!$raw,'phase'=>$phase,
				'reason_codes'=>$raw ? [] : ['activation_gate_denied'],'callback_serialized'=>false,
			];
		}
		if(!is_array($raw)){
			return [
				'configured'=>true,'checked'=>true,'allowed'=>false,'complete'=>false,
				'stale'=>true,'revoked'=>false,'blocked'=>true,'phase'=>$phase,
				'reason_codes'=>['activation_gate_invalid'],'callback_serialized'=>false,
			];
		}
		$complete=($raw['complete'] ?? false)===true;
		$stale=($raw['stale'] ?? true)===true;
		$revoked=($raw['revoked'] ?? false)===true;
		$blocked=($raw['blocked'] ?? false)===true;
		$requested=($raw['allowed'] ?? false)===true;
		$allowed=$requested && $complete && !$stale && !$revoked && !$blocked;
		$reasonCodes=[];
		foreach(is_array($raw['reason_codes'] ?? null) ? $raw['reason_codes'] : [] as $reason){
			$reason=Resource::normalizeName((string)$reason);
			if($reason!=='' && strlen($reason)<=128){$reasonCodes[$reason]=true;}
		}
		if(!$complete){$reasonCodes['activation_gate_incomplete']=true;}
		if($stale){$reasonCodes['activation_gate_stale']=true;}
		if($revoked){$reasonCodes['package_revoked']=true;}
		if($blocked){$reasonCodes['activation_gate_blocked']=true;}
		if(!$requested){$reasonCodes['activation_gate_denied']=true;}
		$reasonCodes=array_keys($reasonCodes);
		sort($reasonCodes,SORT_STRING);
		return [
			'configured'=>true,'checked'=>true,'allowed'=>$allowed,'complete'=>$complete,
			'stale'=>$stale,'revoked'=>$revoked,'blocked'=>$blocked,'phase'=>$phase,
			'reason_codes'=>$reasonCodes,'callback_serialized'=>false,
		];
	}

	/**
	 * Returns the preview manifest for JSON-style consumers.
	 *
	 * @return array<string, mixed> Serializable install manifest.
	 */
	public function toArray(): array {
		return $this->manifest();
	}

	/**
	 * Serializes the plan as its preview manifest.
	 *
	 * @return array<string, mixed> Serializable install manifest.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Normalizes a template artifact path to a relative package path.
	 *
	 * Empty, current-directory, and parent-directory segments are collapsed so
	 * later path containment checks receive a stable relative path. This helper
	 * does not grant write permission; apply() still validates the joined target
	 * against the resolved root before writing.
	 *
	 * @param string $path Artifact path from the package template.
	 * @return string Slash-separated relative artifact path, or an empty string.
	 */
	private function normalizeArtifactPath(string $path): string {
		$path=trim(str_replace('\\', '/', $path), '/');
		$segments=[];
		foreach(explode('/', $path) as $segment){
			if($segment==='' || $segment==='.'){
				continue;
			}
			if($segment==='..'){
				if($segments===[]){
					return '';
				}
				array_pop($segments);
				continue;
			}
			if(
				preg_match('/[\x00-\x1F\x7F:]/', $segment)===1
				|| rtrim($segment, ". ")!==$segment
				|| preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?\z/i', $segment)===1
			){
				return '';
			}
			$segments[]=$segment;
		}
		return implode('/', $segments);
	}

	/**
	 * Resolves apply-time overwrite behavior.
	 *
	 * The legacy boolean `overwrite` option is supported as an apply-time
	 * override for callers that predate named overwrite policies.
	 *
	 * @param array<string, mixed> $options Apply options.
	 * @return string Effective policy: `replace` when overwrite is truthy, `fail` when false, otherwise the plan policy.
	 */
	private function effectiveOverwritePolicy(array $options): string {
		if(array_key_exists('overwrite', $options)){
			return !empty($options['overwrite']) ? 'replace' : 'fail';
		}
		if(array_key_exists('overwrite_policy', $options)){
			$policy=Resource::normalizeName((string)$options['overwrite_policy']);
			return in_array($policy, ['fail', 'skip', 'replace'], true) ? $policy : 'fail';
		}
		return $this->overwritePolicy;
	}

	/**
	 * Resolves a target or backup root to a canonical filesystem path.
	 *
	 * Existing directories are realpathed. Missing roots may be created when
	 * `$create` is true; otherwise the method returns a best-effort path beneath
	 * an existing parent so dry-run previews can still report stable targets.
	 *
	 * @param string $targetRoot User-supplied root path.
	 * @param bool $create Whether missing root directories may be created.
	 * @return string Canonical root path without a trailing separator, or an empty string when it cannot be resolved.
	 */
	private function resolveRoot(string $targetRoot, bool $create): string {
		$targetRoot=trim($targetRoot);
		if($targetRoot===''){
			return '';
		}
		$targetRoot=str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetRoot);
		if(is_dir($targetRoot)){
			$real=realpath($targetRoot);
			return $real!==false ? rtrim($real, DIRECTORY_SEPARATOR) : '';
		}
		if($create && !@mkdir($targetRoot, 0775, true) && !is_dir($targetRoot)){
			return '';
		}
		if(is_dir($targetRoot)){
			$real=realpath($targetRoot);
			return $real!==false ? rtrim($real, DIRECTORY_SEPARATOR) : '';
		}
		$parent=dirname($targetRoot);
		$realParent=is_dir($parent) ? realpath($parent) : false;
		return $realParent!==false ? rtrim($realParent, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($targetRoot) : '';
	}

	/**
	 * Joins a resolved root with a normalized package-relative artifact path.
	 *
	 * @param string $root Canonical filesystem root.
	 * @param string $path Slash-separated artifact path.
	 * @return string Platform-specific target path, or an empty string when the root is empty.
	 */
	private function joinPath(string $root, string $path): string {
		if($root===''){
			return '';
		}
		return rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
	}

	/**
	 * Verifies that a candidate filesystem path remains inside a root.
	 *
	 * Comparison is case-insensitive to match Windows path behavior while still
	 * preserving the normalized paths returned by helper methods.
	 *
	 * @param string $path Candidate file or directory path.
	 * @param string $root Canonical root that must contain the candidate.
	 * @return bool True when the candidate is the root itself or a descendant.
	 */
	private function pathWithinRoot(string $path, string $root): bool {
		$path=$this->normalizeFilesystemPath($path);
		$root=$this->normalizeFilesystemPath($root);
		if($path==='' || $root===''){
			return false;
		}
		if(!$this->pathPrefixMatches($path, $root)){
			return false;
		}
		$realRoot=realpath($root);
		if($realRoot===false){
			return false;
		}
		$ancestor=$path;
		while(!file_exists($ancestor) && !is_link($ancestor)){
			$parent=dirname($ancestor);
			if($parent===$ancestor){
				return false;
			}
			$ancestor=$parent;
		}
		$realAncestor=realpath($ancestor);
		return $realAncestor!==false && $this->pathPrefixMatches($realAncestor, $realRoot);
	}

	/**
	 * Compares a candidate path with a root using platform path semantics.
	 *
	 * Windows filesystems are treated case-insensitively; other platforms retain
	 * case so similarly named sibling roots cannot be mistaken for descendants.
	 *
	 * @param string $path Candidate path.
	 * @param string $root Root path.
	 * @return bool Whether the candidate is the root or a descendant.
	 */
	private function pathPrefixMatches(string $path, string $root): bool {
		return PanelFilesystemPath::prefixMatches($path, $root);
	}

	/**
	 * Collapses a filesystem path for containment comparison.
	 *
	 * Drive prefixes, absolute prefixes, dot segments, and parent references are
	 * normalized without checking whether the path exists, which keeps dry-run
	 * and not-yet-created targets comparable.
	 *
	 * @param string $path Filesystem path to normalize.
	 * @return string Normalized platform-specific path.
	 */
	private function normalizeFilesystemPath(string $path): string {
		return PanelFilesystemPath::normalize($path);
	}

	/**
	 * Copies an existing target file into the package backup tree.
	 *
	 * Backup paths are rooted at `backupRoot/<package-id>/<timestamp>/<artifact>`.
	 * The resolved backup path must remain inside the backup root. During dry-run
	 * no directories or files are created, but the method still returns the backup
	 * path that would be used when the root can be resolved.
	 *
	 * @param string $target Existing target file being replaced.
	 * @param string $path Package-relative artifact path.
	 * @param string $packageId Package identifier used to namespace backups.
	 * @param string $backupRoot Optional filesystem root for backups.
	 * @param bool $dryRun Whether to skip filesystem writes.
	 * @return array{path: string, target: string, backup: string, bytes: int, sha256: string, dry_run: bool}|null Backup descriptor, or null when backup is disabled or cannot be completed.
	 */
	private function backupTarget(string $target, string $path, string $packageId, string $backupRoot, bool $dryRun, string $namespace='', string $expectedSha256=''): ?array {
		$backupRoot=trim($backupRoot);
		if($backupRoot==='' || !is_file($target) || is_link($target)){
			return null;
		}
		$sourceDigest=hash_file('sha256', $target) ?: '';
		if($sourceDigest==='' || ($expectedSha256!=='' && !hash_equals($expectedSha256, $sourceDigest))){
			return null;
		}
		$backupBase=$this->resolveRoot($backupRoot, !$dryRun);
		if($backupBase===''){
			return null;
		}
		$packageNamespace=Resource::normalizeName($packageId);
		if($packageNamespace===''){$packageNamespace='panel_package';}
		$namespace=$namespace!=='' ? Resource::normalizeName($namespace) : $this->backupNamespace();
		if($namespace===''){$namespace=$this->backupNamespace();}
		$backup=$this->joinPath($backupBase, $packageNamespace.'/'.$namespace.'/'.$path);
		if(!$this->pathWithinRoot($backup, $backupBase) || $this->pathContainsSymlink($backup, $backupBase)){
			return null;
		}
		if(!$dryRun){
			$directory=dirname($backup);
			if(!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)){
				return null;
			}
			@chmod($directory, 0700);
			if(!$this->pathWithinRoot($backup, $backupBase) || $this->pathContainsSymlink($backup, $backupBase)){
				return null;
			}
			if(!@copy($target, $backup)){
				return null;
			}
			$backupDigest=hash_file('sha256', $backup) ?: '';
			$currentDigest=hash_file('sha256', $target) ?: '';
			if($backupDigest==='' || $currentDigest==='' || !hash_equals($sourceDigest, $backupDigest) || !hash_equals($sourceDigest, $currentDigest)){
				@unlink($backup);
				return null;
			}
			$sourceDigest=$backupDigest;
		}
		return [
			'path'=>$path,
			'target'=>$target,
			'backup'=>$backup,
			'bytes'=>$dryRun ? (int)filesize($target) : (int)filesize($backup),
			'sha256'=>$sourceDigest,
			'dry_run'=>$dryRun,
		];
	}

	/** @return string Collision-resistant namespace shared by every backup in one apply. */
	private function backupNamespace(): string {
		try{$suffix=bin2hex(random_bytes(12));}catch(\Throwable){$suffix=str_replace('.', '', uniqid('', true));}
		return date('Ymd-His').'-'.$suffix;
	}

	/** @return bool Whether the target or an ancestor beneath root is a symbolic link. */
	private function pathContainsSymlink(string $path, string $root): bool {
		$path=$this->normalizeFilesystemPath($path);
		$root=$this->normalizeFilesystemPath($root);
		if($path==='' || $root==='' || !$this->pathPrefixMatches($path, $root)){
			return true;
		}
		if(is_link($root)){
			return true;
		}
		$relative=ltrim(substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR);
		$current=rtrim($root, DIRECTORY_SEPARATOR);
		foreach($relative==='' ? [] : explode(DIRECTORY_SEPARATOR, $relative) as $segment){
			$current.=DIRECTORY_SEPARATOR.$segment;
			if(is_link($current)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Stages and publishes one artifact without exposing partial target bytes.
	 *
	 * @return array{ok:bool,touched:bool,reason?:string}
	 */
	private function publishArtifact(string $target, string $contents, string $action, string $expectedExisting, string $root, ?int $mode): array {
		$directory=dirname($target);
		if(!is_dir($directory) || $this->pathContainsSymlink($target, $root)){
			return ['ok'=>false,'touched'=>false,'reason'=>'Artifact target directory is unsafe.'];
		}
		try{$suffix=bin2hex(random_bytes(12));}catch(\Throwable){$suffix=str_replace('.', '', uniqid('', true));}
		$staged=$directory.DIRECTORY_SEPARATOR.'.'.basename($target).'.dp-install-'.$suffix.'.tmp';
		$displaced=$directory.DIRECTORY_SEPARATOR.'.'.basename($target).'.dp-install-displaced-'.$suffix.'.tmp';
		$touched=false;
		try{
			$bytes=@file_put_contents($staged, $contents, LOCK_EX);
			if($bytes===false || $bytes!==strlen($contents)){
				return ['ok'=>false,'touched'=>false,'reason'=>'Artifact could not be written completely during staging.'];
			}
			$expected=hash('sha256', $contents);
			$stagedDigest=hash_file('sha256', $staged) ?: '';
			if($stagedDigest==='' || !hash_equals($expected, $stagedDigest)){
				return ['ok'=>false,'touched'=>false,'reason'=>'Staged artifact failed digest verification.'];
			}
			if($this->pathContainsSymlink($target, $root)){
				return ['ok'=>false,'touched'=>false,'reason'=>'Artifact target became a symbolic link before publication.'];
			}
			if($action==='create'){
				if(file_exists($target) || is_link($target)){
					return ['ok'=>false,'touched'=>false,'reason'=>'Artifact target appeared before publication.'];
				}
				if(!@link($staged, $target)){
					return ['ok'=>false,'touched'=>false,'reason'=>'Staged artifact could not be published.'];
				}
				@unlink($staged);
				$touched=true;
			}
			elseif($action==='replace'){
				$current=is_file($target) && !is_link($target) ? (hash_file('sha256', $target) ?: '') : '';
				if($expectedExisting==='' || $current==='' || !hash_equals($expectedExisting, $current)){
					return ['ok'=>false,'touched'=>false,'reason'=>'Replacement target changed before publication.'];
				}
				if(!@rename($target, $displaced)){
					return ['ok'=>false,'touched'=>false,'reason'=>'Replacement target could not be displaced atomically.'];
				}
				$touched=true;
				if(!@link($staged, $target)){
					$restored=@rename($displaced, $target);
					return ['ok'=>false,'touched'=>!$restored,'reason'=>'Staged replacement could not be published.'];
				}
				@unlink($staged);
			}
			else{
				return ['ok'=>false,'touched'=>false,'reason'=>'Unsupported artifact publication action.'];
			}
			$actual=hash_file('sha256', $target) ?: '';
			if($actual==='' || !hash_equals($expected, $actual)){
				if($action==='replace' && is_file($displaced)){
					@unlink($target);
					$restored=@rename($displaced, $target);
					return ['ok'=>false,'touched'=>!$restored,'reason'=>'Published artifact failed digest verification.'];
				}
				if($action==='create'){@unlink($target);}
				return ['ok'=>false,'touched'=>false,'reason'=>'Published artifact failed digest verification.'];
			}
			if(is_int($mode)){@chmod($target, $mode);}
			if(is_file($displaced)){@unlink($displaced);}
			return ['ok'=>true,'touched'=>true];
		} finally { if(is_file($staged) || is_link($staged)){@unlink($staged);} }
	}

	/** @return resource|null Exclusive lock shared by package install and rollback. */
	private function acquirePackageLock(string $root, int $timeoutMs) {
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

	/** @return string Fresh private transaction directory, or an empty string. */
	private function transactionDirectory(): string {
		$base=rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'dataphyre-panel-package-transactions';
		if(is_link($base) || (!is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base))){
			return '';
		}
		@chmod($base, 0700);
		try{$suffix=bin2hex(random_bytes(12));}catch(\Throwable){$suffix=str_replace('.', '', uniqid('', true));}
		$directory=$base.DIRECTORY_SEPARATOR.$suffix;
		return @mkdir($directory, 0700, false) ? $directory : '';
	}

	/** Restores a transaction snapshot without exposing a half-copied target. */
	private function replaceFromSnapshot(string $snapshot, string $target): bool {
		if(!is_file($snapshot) || is_link($snapshot) || is_link($target)){
			return false;
		}
		$directory=dirname($target);
		if(!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)){
			return false;
		}
		try{$suffix=bin2hex(random_bytes(8));}catch(\Throwable){$suffix=str_replace('.', '', uniqid('', true));}
		$staged=$directory.DIRECTORY_SEPARATOR.'.'.basename($target).'.dp-install-restore-'.$suffix.'.tmp';
		$displaced=$directory.DIRECTORY_SEPARATOR.'.'.basename($target).'.dp-install-displaced-'.$suffix.'.tmp';
		if(!@copy($snapshot, $staged)){
			return false;
		}
		$expected=hash_file('sha256', $snapshot) ?: '';
		$actual=hash_file('sha256', $staged) ?: '';
		if($expected==='' || !hash_equals($expected, $actual)){
			@unlink($staged);
			return false;
		}
		$moved=false;
		if(is_file($target)){
			$moved=@rename($target, $displaced);
			if(!$moved){@unlink($staged);return false;}
		}
		if(!@rename($staged, $target)){
			if($moved){@rename($displaced, $target);}
			@unlink($staged);
			return false;
		}
		if($moved && is_file($displaced)){@unlink($displaced);}
		return true;
	}

	/** Removes only a transaction directory created beneath the private temp root. */
	private function removeTransactionTree(string $directory): void {
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
