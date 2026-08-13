<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Explicit, dry-run-first orphan cleanup policy for Panel media disks. */
final class PanelMediaCleanupPolicy implements \JsonSerializable {

	private int $graceSeconds;
	/** @var list<string> */
	private array $protectedPrefixes;
	private int $maxDeletes;
	private $policy=null;

	/** @param array<int,string> $protectedPrefixes */
	public function __construct(int $graceSeconds=604800, array $protectedPrefixes=[], int $maxDeletes=1000) {
		$this->graceSeconds=max(0, $graceSeconds);
		$this->protectedPrefixes=array_values(array_unique(array_filter(array_map(static fn(mixed $value): string => trim(str_replace('\\', '/', (string)$value), '/'), $protectedPrefixes))));
		$this->maxDeletes=max(1, $maxDeletes);
	}

	public function authorizeUsing(?callable $policy): self {
		$this->policy=$policy;
		return $this;
	}

	/** @param array<int,string> $referencedPaths @return array<string,mixed> */
	public function plan(PanelMediaDisk $disk, array $referencedPaths, string $prefix='', ?int $now=null): array {
		$now=$now ?? time();
		$referenced=[];
		foreach($referencedPaths as $path){
			try {
				$referenced[$disk->normalizePath((string)$path)]=true;
			}
			catch(\Throwable){
				continue;
			}
		}
		$candidates=[];
		$protected=[];
		foreach($disk->list($prefix, true) as $file){
			$path=(string)$file['path'];
			if(isset($referenced[$path])){
				continue;
			}
			$reason=$this->protectionReason($path, $file, $now);
			if($reason!==null){
				$protected[]=array_replace($file, ['reason'=>$reason]);
				continue;
			}
			$candidates[]=$file;
		}
		usort($candidates, static fn(array $left, array $right): int => ((int)$left['modified_at']<=> (int)$right['modified_at']) ?: strcmp((string)$left['path'], (string)$right['path']));
		return [
			'type'=>'panel_media_cleanup_plan',
			'disk'=>$disk->name(),
			'generated_at'=>gmdate('c', $now),
			'grace_seconds'=>$this->graceSeconds,
			'referenced_count'=>count($referenced),
			'candidate_count'=>count($candidates),
			'candidate_bytes'=>array_sum(array_column($candidates, 'size')),
			'candidates'=>$candidates,
			'protected'=>$protected,
		];
	}

	/** @param array<int,string> $referencedPaths @return array<string,mixed> */
	public function execute(PanelMediaDisk $disk, array $referencedPaths, string $prefix='', bool $dryRun=true, ?int $now=null): array {
		$plan=$this->plan($disk, $referencedPaths, $prefix, $now);
		$deleted=[];
		$denied=[];
		$failed=[];
		foreach(array_slice($plan['candidates'], 0, $this->maxDeletes) as $candidate){
			if($this->policy!==null){
				try {
					$allowed=($this->policy)($candidate, $disk, $plan);
				}
				catch(\Throwable $exception){
					$denied[]=array_replace($candidate, ['reason'=>'policy_error', 'error'=>$exception->getMessage()]);
					continue;
				}
				if($allowed!==true){
					$denied[]=array_replace($candidate, ['reason'=>'policy_denied']);
					continue;
				}
			}
			if($dryRun){
				continue;
			}
			try {
				if($disk->delete((string)$candidate['path'])){
					$deleted[]=$candidate;
				}
			}
			catch(\Throwable $exception){
				$failed[]=array_replace($candidate, ['error'=>$exception->getMessage()]);
			}
		}
		return [
			'type'=>'panel_media_cleanup_result',
			'dry_run'=>$dryRun,
			'plan'=>$plan,
			'deleted'=>$deleted,
			'deleted_count'=>count($deleted),
			'deleted_bytes'=>array_sum(array_column($deleted, 'size')),
			'denied'=>$denied,
			'failed'=>$failed,
			'truncated'=>count($plan['candidates'])>$this->maxDeletes,
		];
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		return [
			'type'=>'panel_media_cleanup_policy',
			'grace_seconds'=>$this->graceSeconds,
			'protected_prefixes'=>$this->protectedPrefixes,
			'max_deletes'=>$this->maxDeletes,
			'dry_run_default'=>true,
			'has_policy_hook'=>$this->policy!==null,
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return $this->manifest();
	}

	/** @param array<string,mixed> $file */
	private function protectionReason(string $path, array $file, int $now): ?string {
		foreach($this->protectedPrefixes as $prefix){
			if($path===$prefix || str_starts_with($path, $prefix.'/')){
				return 'protected_prefix';
			}
		}
		if((int)($file['modified_at'] ?? $now)>$now-$this->graceSeconds){
			return 'grace_period';
		}
		return null;
	}
}
