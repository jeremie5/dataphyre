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
	private string $overwritePolicy='fail';
	private array $meta=[];

	/**
	 * Creates an install plan for a package template.
	 *
	 * Supported options are `runtime` for compatibility evaluation,
	 * `trust_policy` for package trust checks, `overwrite_policy` for conflict
	 * handling (`fail`, `skip`, or `replace`), and `meta` for diagnostics carried
	 * into manifests. The target path is normalized to a slash-separated package
	 * relative path; absolute filesystem resolution is deferred until apply().
	 *
	 * @param PanelPackageTemplate $template Template containing package metadata and artifacts.
	 * @param string $targetPath Optional package-relative target path used by manifest previews.
	 * @param array{runtime?: array<string, mixed>, trust_policy?: PanelPackageTrustPolicy, overwrite_policy?: string, meta?: array<string, mixed>} $options Plan options.
	 */
	public function __construct(PanelPackageTemplate $template, string $targetPath='', array $options=[]) {
		$this->template=$template;
		$this->target($targetPath);
		$this->runtime=is_array($options['runtime'] ?? null) ? $options['runtime'] : PanelCompatibilityMatrix::defaultRuntime();
		if(($options['trust_policy'] ?? null) instanceof PanelPackageTrustPolicy){
			$this->trustPolicy=$options['trust_policy'];
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
	 * @param array{dry_run?: bool, overwrite?: bool, overwrite_policy?: string, backup_root?: string, meta?: array<string, mixed>} $options Apply-time behavior overrides.
	 * @return PanelPackageApplyResult Structured result containing written, skipped, blocked, backup, timing, and metadata sections.
	 */
	public function apply(string $targetRoot, array $options=[]): PanelPackageApplyResult {
		$started=microtime(true);
		$startedAt=(new \DateTimeImmutable('@'.(string)(int)$started))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format(DATE_ATOM);
		$dryRun=(bool)($options['dry_run'] ?? false);
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
		$dialback=\dataphyre\core::dialback('CALL_PANEL_FRAMEWORK_PACKAGE_BEFORE_APPLY', $dialbackPayload);
		if($dialback instanceof PanelPackageApplyResult){
			return $dialback;
		}
		if(is_array($dialback)){
			return PanelPackageApplyResult::make($dialback);
		}
		$artifacts=[];
		foreach($this->template->artifacts() as $artifact){
			$path=$this->normalizeArtifactPath((string)($artifact['path'] ?? ''));
			if($path!==''){
				$artifacts[$path]=$artifact;
			}
		}
		$written=[];
		$skipped=[];
		$backups=[];
		$blocked=[];
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
				'reason'=>'Install plan is blocked by compatibility or trust policy.',
			];
		}
		foreach((array)($manifest['steps'] ?? []) as $step){
			if(!is_array($step)){
				continue;
			}
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
			if($action==='conflict' || !empty($step['blocked'])){
				$blocked[]=[
					'action'=>'conflict',
					'path'=>$path,
					'target'=>$target,
					'reason'=>'Existing file conflicts with overwrite policy.',
				];
				continue;
			}
			if(!isset($artifacts[$path])){
				$blocked[]=[
					'action'=>'blocked',
					'path'=>$path,
					'target'=>$target,
					'reason'=>'Artifact contents are unavailable.',
				];
				continue;
			}
			$contents=(string)($artifacts[$path]['contents'] ?? '');
			$backup=null;
			if($action==='replace' && is_file($target)){
				$backup=$this->backupTarget($target, $path, $packageId, (string)($options['backup_root'] ?? ''), $dryRun);
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
				if(!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)){
					$blocked[]=[
						'action'=>'blocked',
						'path'=>$path,
						'target'=>$target,
						'reason'=>'Target directory could not be created.',
					];
					continue;
				}
				if(@file_put_contents($target, $contents)===false){
					$blocked[]=[
						'action'=>'blocked',
						'path'=>$path,
						'target'=>$target,
						'reason'=>'File could not be written.',
					];
					continue;
				}
			}
			$written[]=[
				'action'=>$action,
				'path'=>$path,
				'target'=>$target,
				'bytes'=>strlen($contents),
				'dry_run'=>$dryRun,
				'backup'=>$backup['backup'] ?? null,
			];
		}
		$finished=microtime(true);
		$finishedAt=(new \DateTimeImmutable('@'.(string)(int)$finished))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format(DATE_ATOM);
		$result=PanelPackageApplyResult::make([
			'ok'=>$blocked===[],
			'package'=>$manifest['package'] ?? [],
			'target_root'=>$root,
			'written'=>$written,
			'skipped'=>$skipped,
			'backups'=>$backups,
			'blocked'=>$blocked,
			'started_at'=>$startedAt,
			'finished_at'=>$finishedAt,
			'duration_ms'=>(int)round(($finished - $started) * 1000),
			'meta'=>[
				'dry_run'=>$dryRun,
				'overwrite_policy'=>$overwritePolicy,
				'backup_root'=>(string)($options['backup_root'] ?? ''),
			],
		]);
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Panel package apply '.($result->ok() ? 'succeeded' : 'blocked').'; package='.$packageId.'; dry_run='.($dryRun ? 'yes' : 'no').'; written='.count($result->written()).'; skipped='.count($result->skipped()).'; blocked='.count($result->blocked()).'; backups='.count($result->backups()), $S=$result->ok() ? 'info' : 'warning');
		$dialback=\dataphyre\core::dialback('CALL_PANEL_FRAMEWORK_PACKAGE_AFTER_APPLY', $dialbackPayload+[
			'ok'=>$result->ok(),
			'counts'=>[
				'written'=>count($result->written()),
				'skipped'=>count($result->skipped()),
				'blocked'=>count($result->blocked()),
				'backups'=>count($result->backups()),
			],
			'duration_ms'=>$result->toArray()['duration_ms'] ?? 0,
		]);
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
		$steps=[];
		$bytes=0;
		$creates=0;
		$replaces=0;
		$skips=0;
		$conflicts=0;
		foreach($this->template->artifacts() as $artifact){
			$path=$this->normalizeArtifactPath((string)($artifact['path'] ?? ''));
			if($path===''){
				continue;
			}
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
				'kind'=>(string)($artifact['kind'] ?? 'asset'),
				'bytes'=>(int)($artifact['bytes'] ?? 0),
			];
		}
		$blocked=!($compatibility['ok'] ?? false) || ($trust!==null && ($trust['trusted'] ?? false)!==true) || $conflicts>0;
		return [
			'type'=>'panel_package_install_plan',
			'package'=>$package->toArray($this->runtime),
			'target'=>$targetRoot ?? $this->targetPath,
			'ready'=>!$blocked,
			'blocked'=>$blocked,
			'overwrite_policy'=>$overwritePolicy,
			'compatibility'=>$compatibility,
			'trust'=>$trust,
			'summary'=>[
				'steps'=>count($steps),
				'creates'=>$creates,
				'replaces'=>$replaces,
				'skips'=>$skips,
				'conflicts'=>$conflicts,
				'bytes'=>$bytes,
			],
			'steps'=>$steps,
			'meta'=>array_replace($this->meta, $meta),
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
				array_pop($segments);
				continue;
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
		$comparisonPath=strtolower($path);
		$comparisonRoot=strtolower(rtrim($root, DIRECTORY_SEPARATOR));
		return $comparisonPath===$comparisonRoot || str_starts_with($comparisonPath, $comparisonRoot.DIRECTORY_SEPARATOR);
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
		$path=str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
		$prefix='';
		if(preg_match('/^[A-Za-z]:\\\\/', $path)===1){
			$prefix=substr($path, 0, 2);
			$path=substr($path, 2);
		}
		$isAbsolute=str_starts_with($path, DIRECTORY_SEPARATOR);
		$segments=[];
		foreach(explode(DIRECTORY_SEPARATOR, $path) as $segment){
			if($segment==='' || $segment==='.'){
				continue;
			}
			if($segment==='..'){
				array_pop($segments);
				continue;
			}
			$segments[]=$segment;
		}
		$normalized=implode(DIRECTORY_SEPARATOR, $segments);
		if($prefix!==''){
			return $prefix.DIRECTORY_SEPARATOR.$normalized;
		}
		return ($isAbsolute ? DIRECTORY_SEPARATOR : '').$normalized;
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
	 * @return array{path: string, target: string, backup: string, bytes: int, dry_run: bool}|null Backup descriptor, or null when backup is disabled or cannot be completed.
	 */
	private function backupTarget(string $target, string $path, string $packageId, string $backupRoot, bool $dryRun): ?array {
		$backupRoot=trim($backupRoot);
		if($backupRoot===''){
			return null;
		}
		$backupBase=$this->resolveRoot($backupRoot, !$dryRun);
		if($backupBase===''){
			return null;
		}
		$backup=$this->joinPath($backupBase, Resource::normalizeName($packageId).'/'.date('Ymd-His').'/'.$path);
		if(!$this->pathWithinRoot($backup, $backupBase)){
			return null;
		}
		if(!$dryRun){
			$directory=dirname($backup);
			if(!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)){
				return null;
			}
			if(!@copy($target, $backup)){
				return null;
			}
		}
		return [
			'path'=>$path,
			'target'=>$target,
			'backup'=>$backup,
			'bytes'=>is_file($target) ? (int)filesize($target) : 0,
			'dry_run'=>$dryRun,
		];
	}
}
