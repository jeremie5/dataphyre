<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Deterministic, registry-confined semantic-version and dependency resolver. */
final class PanelPackageResolver {
	private PanelPackageRegistryIndex $index;
	private array $runtime;

	public function __construct(PanelPackageRegistryIndex $index, array $runtime=[]) {
		$this->index=$index;
		$this->runtime=$runtime!==[] ? $runtime : PanelCompatibilityMatrix::defaultRuntime();
	}

	public static function make(PanelPackageRegistryIndex $index, array $runtime=[]): self { return new self($index, $runtime); }

	/**
	 * Resolves requested packages and every dependency exclusively inside the
	 * authenticated registry index. No capability aliases or external fallback
	 * names are considered, preventing dependency-confusion substitution.
	 *
	 * @param array<int|string,mixed> $requests Package ids or id => constraint map.
	 * @param array<string,mixed> $installed Installed id => version or descriptor map.
	 * @param array<string,mixed> $options Update, lock, prerelease, and graph limits.
	 */
	public function resolve(array $requests, array $installed=[], array $options=[]): PanelPackageResolutionPlan {
		$errors=[];
		if(!$this->index->ok()){$errors[]='Cannot resolve packages from an unverified registry index.';}
		$constraints=[];
		foreach($requests as $key=>$value){
			$id=is_int($key) ? Resource::normalizeName((string)$value) : Resource::normalizeName((string)$key);
			$rawId=is_int($key) ? (string)$value : (string)$key;
			$constraint=is_int($key) ? '*' : trim((string)$value);
			if($id==='' || $id!==$rawId || !$this->constraintValid($constraint) || strlen($constraint)>128){$errors[]='Package request contains a non-canonical id or invalid constraint.';continue;}
			$constraints[$id][]=$constraint;
		}
		$pinned=is_array($options['pinned'] ?? null) ? $options['pinned'] : [];
		foreach($pinned as $id=>$constraint){
			$rawId=(string)$id;
			$id=Resource::normalizeName($rawId);
			$constraint=trim((string)$constraint);
			if($id==='' || $id!==$rawId || !$this->constraintValid($constraint)){$errors[]='Pinned package policy is malformed.';continue;}
			$constraints[$id][]=$constraint;
		}
		if($constraints===[]){$errors[]='At least one package request is required.';}
		ksort($constraints, SORT_STRING);
		$installed=$this->normalizeState($installed, $errors, 'Installed');
		$locked=$this->normalizeState(is_array($options['lock'] ?? null) ? $options['lock'] : [], $errors, 'Lock');
		if(!empty($options['frozen'])){
			foreach($locked as $entry){if((string)($entry['sha256'] ?? '')===''){$errors[]='Frozen lock entries require artifact SHA-256 digests.';break;}}
		}
		$candidates=$this->candidateMap($installed, $locked, $options);
		$maxPackages=max(1, min(1000, (int)($options['max_packages'] ?? 256)));
		$maxDepth=max(1, min(128, (int)($options['max_depth'] ?? 32)));
		$maxAttempts=max(1, min(1000000, (int)($options['max_attempts'] ?? 100000)));
		$attempts=0;
		$selected=[];
		$graph=[];
		if($errors===[] && !$this->search($constraints, $selected, $graph, $candidates, $installed, $locked, $options, 0, $maxDepth, $maxPackages, $attempts, $maxAttempts)){
			$errors[]='Package dependency constraints cannot be resolved from the authenticated registry without violating update or lock policy.';
		}
		if($errors===[] && $this->hasCycle($graph)){$errors[]='Package dependency graph contains a cycle.';}
		$order=$errors===[] ? $this->topologicalOrder($graph, array_keys($selected)) : [];
		$steps=[];
		foreach($order as $id){
			$entry=$selected[$id];
			$installedEntry=$installed[$id] ?? [];
			$from=(string)($installedEntry['version'] ?? '');
			$to=(string)$entry['version'];
			$installedDigest=(string)($installedEntry['sha256'] ?? '');
			$targetDigest=(string)$entry['artifact']['sha256'];
			$action='install';
			if($from!==''){
				$comparison=PanelPackageManifest::compareVersions($to, $from);
				if($comparison>0){$action='update';}
				elseif($comparison<0){$action='downgrade';}
				elseif($installedDigest==='' || !hash_equals($installedDigest, $targetDigest)){$action='reinstall';}
				else{$action='keep';}
			}
			$steps[]=[
				'action'=>$action,'package'=>$id,'from_version'=>$from!=='' ? $from : null,'to_version'=>$to,
				'artifact_sha256'=>$targetDigest,'dependencies'=>array_keys((array)$entry['dependencies']),
			];
		}
		return PanelPackageResolutionPlan::make([
			'ok'=>$errors===[],
			'registry'=>$this->index->registry(),
			'sequence'=>$this->index->sequence(),
			'index_digest'=>$this->index->digest(),
			'selected'=>$selected,
			'steps'=>$steps,
			'errors'=>array_values(array_unique($errors)),
			'meta'=>is_array($options['meta'] ?? null) ? $options['meta'] : [],
		]);
	}

	/** @return array<string,array<int,array<string,mixed>>> */
	private function candidateMap(array $installed, array $locked, array $options): array {
		$map=[];
		$allowPrerelease=(bool)($options['allow_prerelease'] ?? false);
		$allowYanked=(bool)($options['allow_yanked'] ?? false);
		$allowedStatuses=array_map(static fn(mixed $status): string=>Resource::normalizeName((string)$status), (array)($options['allowed_statuses'] ?? ['stable','preview']));
		foreach($this->index->entries() as $entry){
			$id=(string)$entry['id'];
			$version=(string)$entry['version'];
			if(($entry['revoked']??false)===true||($entry['publisher_blocked']??false)===true){continue;}
			$pinnedVersion='';
			if(empty($options['update'])){
				$pinnedVersion=(string)($locked[$id]['version'] ?? $installed[$id]['version'] ?? '');
			}
			if(!empty($options['frozen']) && !isset($locked[$id])){continue;}
			if($pinnedVersion!=='' && $version!==$pinnedVersion){continue;}
			$versionParts=PanelPackageManifest::versionParts($version);
			if($versionParts===null || (!$allowPrerelease && $versionParts['prerelease']!==[])){continue;}
			if(!$allowYanked && !empty($entry['yanked'])){continue;}
			if(!in_array((string)$entry['status'], $allowedStatuses, true)){continue;}
			$manifest=PanelPackageManifest::from(['id'=>$id,'version'=>$version,'requirements'=>$entry['requirements'] ?? []]);
			if(($manifest->compatibility($this->runtime)['ok'] ?? false)!==true){continue;}
			if(!$this->updateAllowed($entry, $installed[$id] ?? [], $options)){continue;}
			$lock=$locked[$id] ?? [];
			if(!empty($options['frozen']) && $lock!==[] && (string)($lock['version'] ?? '')!==$version){
				continue;
			}
			if($lock!==[] && (string)($lock['version'] ?? '')===$version && (string)($lock['sha256'] ?? '')!=='' && !hash_equals((string)$lock['sha256'], (string)$entry['artifact']['sha256'])){
				continue;
			}
			$map[$id][]=$entry;
		}
		foreach($map as $id=>&$entries){
			usort($entries, static function(array $left, array $right): int {
				$version=PanelPackageManifest::compareVersions((string)$right['version'], (string)$left['version']);
				return $version!==0 ? $version : strcmp((string)$left['artifact']['sha256'], (string)$right['artifact']['sha256']);
			});
		}
		unset($entries);
		return $map;
	}

	private function updateAllowed(array $entry, array $installed, array $options): bool {
		$current=(string)($installed['version'] ?? '');
		if($current===''){return true;}
		$next=(string)$entry['version'];
		$comparison=PanelPackageManifest::compareVersions($next, $current);
		if($comparison<0 && empty($options['allow_downgrade'])){return false;}
		if($comparison<=0){return true;}
		$currentParts=PanelPackageManifest::versionParts($current);
		$nextParts=PanelPackageManifest::versionParts($next);
		if($currentParts===null || $nextParts===null){return false;}
		$majorComparison=PanelPackageManifest::compareVersions($nextParts['major'].'.0.0', $currentParts['major'].'.0.0');
		if($majorComparison>0 && empty($options['allow_major_updates'])){return false;}
		$minorComparison=PanelPackageManifest::compareVersions('0.'.$nextParts['minor'].'.0', '0.'.$currentParts['minor'].'.0');
		if($majorComparison===0 && $minorComparison>0 && array_key_exists('allow_minor_updates', $options) && !$options['allow_minor_updates']){return false;}
		return true;
	}

	private function search(array $constraints, array &$selected, array &$graph, array $candidates, array $installed, array $locked, array $options, int $depth, int $maxDepth, int $maxPackages, int &$attempts, int $maxAttempts): bool {
		if($depth>$maxDepth || count($constraints)>$maxPackages){return false;}
		$unresolved=array_values(array_diff(array_keys($constraints), array_keys($selected)));
		sort($unresolved, SORT_STRING);
		if($unresolved===[]){
			foreach($selected as $id=>$entry){foreach((array)($constraints[$id] ?? []) as $constraint){if(!PanelPackageManifest::matchesConstraint((string)$entry['version'], (string)$constraint)){return false;}}}
			return true;
		}
		if($attempts>=$maxAttempts){return false;}
		$id=$unresolved[0];
		foreach($candidates[$id] ?? [] as $entry){
			$attempts++;
			if($attempts>$maxAttempts){return false;}
			$matches=true;
			foreach((array)($constraints[$id] ?? []) as $constraint){if(!PanelPackageManifest::matchesConstraint((string)$entry['version'], (string)$constraint)){$matches=false;break;}}
			if(!$matches){continue;}
			$nextSelected=$selected;
			$nextGraph=$graph;
			$nextConstraints=$constraints;
			$nextSelected[$id]=$entry;
			$nextGraph[$id]=array_keys((array)$entry['dependencies']);
			$valid=true;
			foreach((array)$entry['dependencies'] as $dependency=>$constraint){
				$nextConstraints[$dependency][]=$constraint;
				if(isset($nextSelected[$dependency]) && !PanelPackageManifest::matchesConstraint((string)$nextSelected[$dependency]['version'], (string)$constraint)){$valid=false;break;}
			}
			if(!$valid){continue;}
			if($this->search($nextConstraints, $nextSelected, $nextGraph, $candidates, $installed, $locked, $options, $depth+1, $maxDepth, $maxPackages, $attempts, $maxAttempts)){
				$selected=$nextSelected;$graph=$nextGraph;return true;
			}
		}
		return false;
	}

	private function hasCycle(array $graph): bool {
		$visiting=[];$visited=[];
		$visit=function(string $id)use(&$visit,&$visiting,&$visited,$graph): bool {
			if(isset($visiting[$id])){return true;}
			if(isset($visited[$id])){return false;}
			$visiting[$id]=true;
			foreach((array)($graph[$id] ?? []) as $dependency){if($visit((string)$dependency)){return true;}}
			unset($visiting[$id]);$visited[$id]=true;return false;
		};
		foreach(array_keys($graph) as $id){if($visit((string)$id)){return true;}}
		return false;
	}

	/** @return array<int,string> Dependencies first, then dependants. */
	private function topologicalOrder(array $graph, array $ids): array {
		$visited=[];$order=[];
		$visit=function(string $id)use(&$visit,&$visited,&$order,$graph): void {
			if(isset($visited[$id])){return;}$visited[$id]=true;
			$dependencies=(array)($graph[$id] ?? []);sort($dependencies, SORT_STRING);
			foreach($dependencies as $dependency){$visit((string)$dependency);}
			$order[]=$id;
		};
		sort($ids, SORT_STRING);foreach($ids as $id){$visit((string)$id);}return $order;
	}

	/** @return array<string,array{version:string,sha256:string}> */
	private function normalizeState(array $state, array &$errors, string $label): array {
		$normalized=[];
		foreach($state as $id=>$value){
			$rawId=(string)$id;$id=Resource::normalizeName($rawId);
			$version=is_array($value) ? trim((string)($value['version'] ?? '')) : trim((string)$value);
			$sha=is_array($value) ? strtolower(trim((string)($value['sha256'] ?? $value['artifact_sha256'] ?? ''))) : '';
			if(str_starts_with($sha,'sha256:')){$sha=substr($sha,7);}
			if($rawId==='' || $id!==$rawId || !$this->semanticVersion($version) || ($sha!=='' && preg_match('/^[a-f0-9]{64}$/',$sha)!==1)){$errors[]=$label.' package state is malformed.';continue;}
			$normalized[$id]=['version'=>$version,'sha256'=>$sha];
		}
		ksort($normalized,SORT_STRING);return $normalized;
	}

	private function semanticVersion(string $version): bool {
		return PanelPackageManifest::validVersion($version);
	}

	private function constraintValid(string $constraint): bool {
		$constraint=trim($constraint);
		if($constraint==='' || $constraint==='*'){return $constraint==='*';}
		foreach(preg_split('/\s*,\s*/', $constraint) ?: [] as $part){
			$part=trim($part);
			if($part==='*'){continue;}
			if(preg_match('/^(?:\^|>=|<=|>|<|==|=)?\s*(\S+)$/D', $part, $matches)!==1 || !PanelPackageManifest::validVersion($matches[1])){return false;}
		}
		return true;
	}
}
