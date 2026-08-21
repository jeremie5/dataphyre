<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database\Seeds;

use RuntimeException;

/**
 * Orders, applies, audits, and explicitly rolls back versioned SQL seeds.
 *
 * Apply batches are atomic through the configured ledger transaction. Re-running
 * a seed with the same checksum is a no-op; changing an already-applied seed is
 * reported as checksum drift instead of silently executing mutable history.
 */
final class SeedManager {
	/** @var array<string,SeedDefinition> */
	private array $definitions=[];
	/** @var array<string,list<string>> */
	private array $dependencies=[];
	/** @var list<string> */
	private array $ordered_keys=[];
	/** @var array<string,true> */
	private array $active_profiles=[];

	/** @param iterable<SeedDefinition|array<string,mixed>> $definitions @param list<string> $profiles */
	public function __construct(iterable $definitions, private SeedLedger $ledger, ?SeedContext $context=null, array $profiles=['default']) {
		$this->context=$context ?? new SeedContext();
		$this->active_profiles=$this->normalizeProfiles($profiles);
		foreach($definitions as $definition){
			if(is_array($definition)){
				$definition=SeedDefinition::fromArray($definition);
			}
			if(!$definition instanceof SeedDefinition){
				throw new RuntimeException('SeedManager accepts only SeedDefinition objects or definition arrays.');
			}
			if($this->ledger instanceof SqlSeedLedger && !$definition->hasContentFingerprint()){
				throw new RuntimeException('Persistent SQL seeds require a file/content fingerprint or an explicit content checksum: '.$definition->key());
			}
			$key=$definition->key();
			if(isset($this->definitions[$key])){
				throw new RuntimeException('Duplicate seed definition: '.$key);
			}
			$this->definitions[$key]=$definition;
		}
		$this->indexGraph();
	}

	private SeedContext $context;

	/** @return list<array<string,mixed>> */
	public function catalog(): array {
		return array_map(
			fn(string $key): array=>$this->definitions[$key]->jsonSerialize()+['active'=>$this->definitions[$key]->supportsProfiles($this->active_profiles)],
			$this->ordered_keys,
		);
	}

	/** @return list<array<string,mixed>> */
	public function status(): array {
		$this->ledger->ensureSchema();
		$records=$this->ledger->all();
		$rows=[];
		foreach($this->ordered_keys as $key){
			$definition=$this->definitions[$key];
			$record=$records[$key] ?? null;
			$status=$record===null
				? 'pending'
				: ($definition->acceptsChecksum((string)($record['checksum'] ?? '')) ? 'applied' : 'drift');
			$rows[]=$definition->jsonSerialize()+[
				'active'=>$definition->supportsProfiles($this->active_profiles),
				'status'=>$status,
				'batch'=>$record['batch'] ?? null,
				'applied_at'=>$record['applied_at'] ?? null,
			];
			unset($records[$key]);
		}
		foreach($records as $key=>$record){
			$rows[]=[
				'id'=>(string)($record['id'] ?? ''),
				'version'=>(int)($record['version'] ?? 0),
				'key'=>$key,
				'description'=>'',
				'dependencies'=>[],
				'checksum'=>(string)($record['checksum'] ?? ''),
				'rollback_available'=>false,
				'source'=>null,
				'profiles'=>[],
				'active'=>false,
				'preflight_available'=>false,
				'content_sources'=>[],
				'status'=>'orphaned',
				'batch'=>$record['batch'] ?? null,
				'applied_at'=>$record['applied_at'] ?? null,
			];
		}
		return $rows;
	}

	/** @param list<string> $selectors @return list<array<string,mixed>> */
	public function planApply(array $selectors=[]): array {
		$this->ledger->ensureSchema();
		[$pending]=$this->pendingDefinitions($selectors, $this->ledger->all());
		return array_map(static fn(SeedDefinition $definition): array=>$definition->jsonSerialize(), $pending);
	}

	/**
	 * Applies selected seeds and their dependencies as one atomic batch.
	 *
	 * @param list<string> $selectors Seed ids or exact `id@version` keys; empty applies all pending seeds.
	 * @return array{batch:int|null,applied:list<array<string,mixed>>,skipped:int}
	 */
	public function apply(array $selectors=[]): array {
		$this->ledger->ensureSchema();
		return $this->ledger->transaction(function() use ($selectors): array {
			[$pending, $selected_count]=$this->pendingDefinitions($selectors, $this->ledger->all());
			if($pending===[]){
				return ['batch'=>null, 'applied'=>[], 'skipped'=>$selected_count];
			}
			$batch=$this->ledger->nextBatch();
			$applied=[];
			foreach($pending as $definition){
				$this->runDefinitionStep($definition, 'preflight', fn(): mixed=>$definition->preflight($this->context));
				$this->runDefinitionStep($definition, 'apply', fn(): mixed=>$definition->apply($this->context));
				$this->runDefinitionStep($definition, 'ledger', function() use ($definition, $batch): void {
					$this->ledger->recordApplied([
						'id'=>$definition->id(),
						'version'=>$definition->version(),
						'checksum'=>$definition->checksum(),
						'batch'=>$batch,
						'applied_at'=>gmdate('c'),
					]);
				});
				$applied[]=$definition->jsonSerialize();
			}
			return [
				'batch'=>$batch,
				'applied'=>$applied,
				'skipped'=>$selected_count-count($pending),
			];
		});
	}

	/**
	 * Runs one definition lifecycle step while retaining its exact definition key.
	 *
	 * The ledger transaction still owns rollback; this wrapper only adds stable
	 * context and chains the original throwable without replacing its message.
	 */
	private function runDefinitionStep(SeedDefinition $definition, string $phase, callable $callback): mixed {
		try{
			return $callback();
		}catch(\Throwable $throwable){
			throw new SeedExecutionException($definition->key(), $phase, $throwable);
		}
	}

	/** @return array<string,mixed> */
	public function planRollback(string $selector): array {
		$this->ledger->ensureSchema();
		return $this->rollbackPlan($selector, $this->ledger->all());
	}

	/**
	 * Rolls back exactly one applied seed after an explicit confirmation flag.
	 *
	 * Applied dependents, checksum drift, missing down callbacks, and ambiguous or
	 * unknown selectors fail closed. There is intentionally no reset-all primitive.
	 *
	 * @return array<string,mixed>
	 */
	public function rollback(string $selector, bool $confirmed=false): array {
		if(!$confirmed){
			throw new RuntimeException('Seed rollback requires explicit confirmation.');
		}
		$this->ledger->ensureSchema();
		return $this->ledger->transaction(function() use ($selector): array {
			$plan=$this->rollbackPlan($selector, $this->ledger->all());
			/** @var SeedDefinition $definition */
			$definition=$this->definitions[$plan['key']];
			$definition->rollback($this->context);
			$this->ledger->remove($definition->id(), $definition->version());
			return array_replace($plan, ['rolled_back'=>true]);
		});
	}

	/** @param list<string> $selectors @param array<string,array<string,mixed>> $records @return array{0:list<SeedDefinition>,1:int} */
	private function pendingDefinitions(array $selectors, array $records): array {
		$this->assertLedgerIntegrity($records);
		$selected=$this->selectedKeys($selectors);
		$pending=[];
		foreach($this->ordered_keys as $key){
			if(!isset($selected[$key])){
				continue;
			}
			$definition=$this->definitions[$key];
			$record=$records[$key] ?? null;
			if($record===null){
				$pending[]=$definition;
				continue;
			}
		}
		return [$pending, count($selected)];
	}

	/** @param list<string> $selectors @return array<string,true> */
	private function selectedKeys(array $selectors): array {
		if($selectors===[]){
			$selected=[];
			foreach($this->ordered_keys as $key){
				if($this->definitions[$key]->supportsProfiles($this->active_profiles)){
					$selected[$key]=true;
				}
			}
			return $selected;
		}
		$selected=[];
		foreach($selectors as $selector){
			$selector=strtolower(trim((string)$selector));
			$matched=[];
			foreach($this->definitions as $key=>$definition){
				if($definition->matches($selector)){
					$matched[]=$key;
				}
			}
			if($matched===[]){
				throw new RuntimeException('Unknown seed selector: '.$selector);
			}
			foreach($matched as $key){
				if(!$this->definitions[$key]->supportsProfiles($this->active_profiles)){
					throw new RuntimeException('Seed '.$key.' is outside the active execution profiles: '.implode(', ', array_keys($this->active_profiles)));
				}
				$this->selectWithDependencies($key, $selected);
			}
		}
		return $selected;
	}

	/** @param array<string,true> $selected */
	private function selectWithDependencies(string $key, array &$selected): void {
		if(isset($selected[$key])){
			return;
		}
		foreach($this->dependencies[$key] as $dependency){
			if(!$this->definitions[$dependency]->supportsProfiles($this->active_profiles)){
				throw new RuntimeException('Seed '.$key.' depends on '.$dependency.', which is outside the active execution profiles.');
			}
			$this->selectWithDependencies($dependency, $selected);
		}
		$selected[$key]=true;
	}

	/** @param array<string,array<string,mixed>> $records @return array<string,mixed> */
	private function rollbackPlan(string $selector, array $records): array {
		$this->assertLedgerIntegrity($records);
		$selector=strtolower(trim($selector));
		$key=null;
		if(isset($this->definitions[$selector])){
			$key=$selector;
		}else{
			foreach(array_reverse($this->ordered_keys) as $candidate){
				$definition=$this->definitions[$candidate];
				if($definition->id()===$selector && isset($records[$candidate])){
					$key=$candidate;
					break;
				}
			}
		}
		if($key===null || !isset($this->definitions[$key])){
			throw new RuntimeException('Unknown or unapplied seed rollback selector: '.$selector);
		}
		$record=$records[$key] ?? null;
		if($record===null){
			throw new RuntimeException('Seed is not applied: '.$key);
		}
		$definition=$this->definitions[$key];
		if(!$definition->hasRollback()){
			throw new RuntimeException('Seed '.$key.' has no rollback callback.');
		}
		$blockers=[];
		foreach(array_keys($records) as $candidate){
			if($candidate!==$key && isset($this->definitions[$candidate]) && $this->dependsOn($candidate, $key)){
				$blockers[]=$candidate;
			}
		}
		if($blockers!==[]){
			sort($blockers, SORT_NATURAL);
			throw new RuntimeException('Cannot roll back '.$key.' while applied dependents exist: '.implode(', ', $blockers));
		}
		return $definition->jsonSerialize()+[
			'status'=>'applied',
			'batch'=>$record['batch'] ?? null,
			'applied_at'=>$record['applied_at'] ?? null,
			'rolled_back'=>false,
		];
	}

	/** @param array<string,array<string,mixed>> $records */
	private function assertLedgerIntegrity(array $records): void {
		$orphans=[];
		$drift=[];
		foreach($records as $key=>$record){
			$definition=$this->definitions[$key] ?? null;
			if(!$definition instanceof SeedDefinition){
				$orphans[]=$key;
				continue;
			}
			if(!$definition->acceptsChecksum((string)($record['checksum'] ?? ''))){
				$drift[]=$key;
			}
		}
		if($orphans!==[]){
			sort($orphans, SORT_NATURAL);
			throw new RuntimeException('Seed ledger contains orphaned applied definitions; restore their definitions before mutating seed state: '.implode(', ', $orphans));
		}
		if($drift!==[]){
			sort($drift, SORT_NATURAL);
			throw new RuntimeException('Applied seed checksum drift detected; create new versions before mutating seed state: '.implode(', ', $drift));
		}
	}

	/** @param list<string> $profiles @return array<string,true> */
	private function normalizeProfiles(array $profiles): array {
		$normalized=[];
		foreach($profiles as $profile){
			$profile=strtolower(trim((string)$profile));
			if(preg_match('/^[a-z][a-z0-9._:-]{0,63}$/', $profile)!==1){
				throw new RuntimeException('Invalid active seed profile: '.$profile);
			}
			$normalized[$profile]=true;
		}
		if($normalized===[]){
			throw new RuntimeException('At least one active seed profile is required.');
		}
		return $normalized;
	}

	private function dependsOn(string $candidate, string $target, array &$visited=[]): bool {
		if(isset($visited[$candidate])){
			return false;
		}
		$visited[$candidate]=true;
		foreach($this->dependencies[$candidate] ?? [] as $dependency){
			if($dependency===$target || $this->dependsOn($dependency, $target, $visited)){
				return true;
			}
		}
		return false;
	}

	private function indexGraph(): void {
		uasort($this->definitions, static function(SeedDefinition $left, SeedDefinition $right): int {
			return [$left->id(), $left->version()] <=> [$right->id(), $right->version()];
		});
		$versions=[];
		foreach($this->definitions as $key=>$definition){
			$versions[$definition->id()][$definition->version()]=$key;
		}
		foreach($versions as &$by_version){
			ksort($by_version, SORT_NUMERIC);
		}
		unset($by_version);
		foreach($this->definitions as $key=>$definition){
			$resolved=[];
			$by_version=$versions[$definition->id()];
			$previous=null;
			foreach($by_version as $version=>$version_key){
				if($version>=$definition->version()){
					break;
				}
				$previous=$version_key;
			}
			if($previous!==null){
				$resolved[$previous]=$previous;
			}
			foreach($definition->dependencies() as $dependency){
				$dependency_key=$this->resolveDependency($dependency, $versions);
				if($dependency_key===$key){
					throw new RuntimeException('Seed '.$key.' cannot depend on itself.');
				}
				$resolved[$dependency_key]=$dependency_key;
			}
			$this->dependencies[$key]=array_values($resolved);
			sort($this->dependencies[$key], SORT_NATURAL);
		}
		$states=[];
		$stack=[];
		foreach(array_keys($this->definitions) as $key){
			$this->visit($key, $states, $stack);
		}
	}

	/** @param array<string,array<int,string>> $versions */
	private function resolveDependency(string $dependency, array $versions): string {
		if(str_contains($dependency, '@')){
			if(!isset($this->definitions[$dependency])){
				throw new RuntimeException('Missing exact seed dependency: '.$dependency);
			}
			return $dependency;
		}
		if(!isset($versions[$dependency]) || $versions[$dependency]===[]){
			throw new RuntimeException('Missing seed dependency: '.$dependency);
		}
		$dependency_versions=$versions[$dependency];
		return (string)end($dependency_versions);
	}

	/** @param array<string,int> $states @param list<string> $stack */
	private function visit(string $key, array &$states, array &$stack): void {
		$state=$states[$key] ?? 0;
		if($state===2){
			return;
		}
		if($state===1){
			$stack[]=$key;
			throw new RuntimeException('Seed dependency cycle: '.implode(' -> ', $stack));
		}
		$states[$key]=1;
		$stack[]=$key;
		foreach($this->dependencies[$key] as $dependency){
			$this->visit($dependency, $states, $stack);
		}
		array_pop($stack);
		$states[$key]=2;
		$this->ordered_keys[]=$key;
	}
}
