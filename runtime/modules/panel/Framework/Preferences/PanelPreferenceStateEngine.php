<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Shared deterministic state transitions used by preference store adapters. */
final class PanelPreferenceStateEngine {

	/** @return array<string,mixed> */
	public static function initialState(): array {
		return ['profiles'=>[], 'versions'=>[], 'meta'=>[]];
	}

	public static function key(string $userId, string $profile='default'): string {
		$normalized=new PanelWorkspacePreferenceProfile($userId, $profile);
		return (string)$normalized->toArray()['id'];
	}

	/** @param array<string,mixed> $state */
	public static function load(array $state, string $userId, string $profile='default'): ?PanelWorkspacePreferenceProfile {
		$value=$state['profiles'][self::key($userId, $profile)] ?? null;
		return is_array($value) ? PanelWorkspacePreferenceProfile::fromArray($value) : null;
	}

	/** @param array<string,mixed> $state @return array<int,PanelWorkspacePreferenceProfile> */
	public static function profiles(array $state, string $userId): array {
		$profiles=[];
		foreach((array)($state['profiles'] ?? []) as $value){
			if(is_array($value) && ($value['user_id'] ?? null)===$userId){
				$profiles[]=PanelWorkspacePreferenceProfile::fromArray($value);
			}
		}
		usort($profiles, static fn(PanelWorkspacePreferenceProfile $left, PanelWorkspacePreferenceProfile $right): int => strcmp($left->name(), $right->name()));
		return $profiles;
	}

	/** @param array<string,mixed> $state @return array<int,PanelWorkspacePreferenceProfile> */
	public static function history(array $state, string $userId, string $profile='default', int $limit=100): array {
		$versions=$state['versions'][self::key($userId, $profile)] ?? [];
		$versions=is_array($versions) ? array_reverse($versions, true) : [];
		$result=[];
		foreach(array_slice($versions, 0, max(1, min(1000, $limit)), true) as $value){
			if(is_array($value)){ $result[]=PanelWorkspacePreferenceProfile::fromArray($value); }
		}
		return $result;
	}

	/** @param array<string,mixed> $state */
	public static function save(array &$state, PanelWorkspacePreferenceProfile $candidate, ?int $expectedRevision, string $strategy='reject', int $versionRetention=100): PanelWorkspacePreferenceProfile {
		$strategy=self::strategy($strategy);
		$key=self::key($candidate->userId(), $candidate->name());
		$current=is_array($state['profiles'][$key] ?? null) ? PanelWorkspacePreferenceProfile::fromArray($state['profiles'][$key]) : null;
		$currentRevision=$current?->revision() ?? 0;
		$incoming=['settings'=>self::sanitize($candidate->settings()), 'devices'=>self::sanitize($candidate->devices())];
		if($expectedRevision!==null && $expectedRevision!==$currentRevision){
			if($strategy==='reject'){
				throw new PanelPreferenceConflictException($candidate->userId(), $candidate->name(), $expectedRevision, $currentRevision, ['revision']);
			}
			if($strategy!=='overwrite'){
				$baseValue=$state['versions'][$key][(string)$expectedRevision] ?? null;
				if(!is_array($baseValue)){
					throw new PanelPreferenceConflictException($candidate->userId(), $candidate->name(), $expectedRevision, $currentRevision, ['revision_history'], 'The expected preference revision is no longer retained.');
				}
				$base=PanelWorkspacePreferenceProfile::fromArray($baseValue);
				$currentValue=['settings'=>$current?->settings() ?? [], 'devices'=>$current?->devices() ?? []];
				$conflicts=[];
				$incoming=self::mergeValue(
					['settings'=>$base->settings(), 'devices'=>$base->devices()],
					$currentValue,
					$incoming,
					'',
					$conflicts,
					$strategy
				);
				if($conflicts!==[] && $strategy==='merge'){
					throw new PanelPreferenceConflictException($candidate->userId(), $candidate->name(), $expectedRevision, $currentRevision, array_values(array_unique($conflicts)), 'Panel workspace fields changed concurrently.');
				}
			}
		}
		$now=gmdate('c');
		$saved=new PanelWorkspacePreferenceProfile(
			$candidate->userId(),
			$candidate->name(),
			is_array($incoming['settings'] ?? null) ? $incoming['settings'] : [],
			is_array($incoming['devices'] ?? null) ? $incoming['devices'] : [],
			$currentRevision+1,
			$current?->createdAt() ?? $candidate->createdAt(),
			$now
		);
		$state['profiles'][$key]=$saved->toArray();
		$state['versions'][$key]=is_array($state['versions'][$key] ?? null) ? $state['versions'][$key] : [];
		$state['versions'][$key][(string)$saved->revision()]=$saved->toArray();
		if(count($state['versions'][$key])>max(2, $versionRetention)){
			$state['versions'][$key]=array_slice($state['versions'][$key], -max(2, $versionRetention), null, true);
		}
		return $saved;
	}

	/** @param array<string,mixed> $state */
	public static function delete(array &$state, string $userId, string $profile='default', ?int $expectedRevision=null): bool {
		$key=self::key($userId, $profile);
		$current=is_array($state['profiles'][$key] ?? null) ? PanelWorkspacePreferenceProfile::fromArray($state['profiles'][$key]) : null;
		if($current===null){ return false; }
		if($expectedRevision!==null && $expectedRevision!==$current->revision()){
			throw new PanelPreferenceConflictException($userId, $profile, $expectedRevision, $current->revision(), ['revision']);
		}
		unset($state['profiles'][$key]);
		return true;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	public static function export(array $state, string $userId, ?string $profile=null): array {
		$profiles=[];
		foreach(self::profiles($state, $userId) as $item){
			if($profile===null || $item->name()===(new PanelWorkspacePreferenceProfile($userId, $profile))->name()){
				$profiles[]=$item->toArray();
			}
		}
		return [
			'type'=>'panel_workspace_preferences_export',
			'version'=>1,
			'user_id'=>$userId,
			'exported_at'=>gmdate('c'),
			'profiles'=>$profiles,
		];
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $payload @return array<int,PanelWorkspacePreferenceProfile> */
	public static function import(array &$state, array $payload, string $strategy='merge', int $versionRetention=100): array {
		if(($payload['type'] ?? null)!=='panel_workspace_preferences_export' || (int)($payload['version'] ?? 0)!==1 || !is_array($payload['profiles'] ?? null)){
			throw new \InvalidArgumentException('Panel preference import payload is invalid.');
		}
		$payloadUser=isset($payload['user_id']) ? trim((string)$payload['user_id']) : '';
		$results=[];
		foreach($payload['profiles'] as $value){
			if(!is_array($value)){ continue; }
			$candidate=PanelWorkspacePreferenceProfile::fromArray($value);
			if($payloadUser==='' || !hash_equals($payloadUser, $candidate->userId())){
				throw new \InvalidArgumentException('Panel preference import contains a profile outside its declared user scope.');
			}
			$current=self::load($state, $candidate->userId(), $candidate->name());
			if($current!==null && self::strategy($strategy)==='reject'){
				throw new PanelPreferenceConflictException($candidate->userId(), $candidate->name(), 0, $current->revision(), ['profile']);
			}
			if($current!==null && str_starts_with(self::strategy($strategy), 'merge')){
				$candidate=new PanelWorkspacePreferenceProfile(
					$candidate->userId(), $candidate->name(),
					self::overlay($current->settings(), $candidate->settings()),
					self::overlay($current->devices(), $candidate->devices()),
					$current->revision(), $current->createdAt(), $current->updatedAt()
				);
			}
			$results[]=self::save($state, $candidate, $current?->revision(), 'overwrite', $versionRetention);
		}
		return $results;
	}

	public static function strategy(string $strategy): string {
		$strategy=strtolower(trim(str_replace('-', '_', $strategy)));
		return in_array($strategy, ['reject', 'overwrite', 'merge', 'merge_prefer_incoming', 'merge_prefer_current'], true) ? $strategy : 'reject';
	}

	public static function sanitize(mixed $value, int $depth=0): mixed {
		if($depth>32){ return null; }
		if(is_array($value)){
			$result=[];
			foreach($value as $key=>$item){
				if(is_string($key) && preg_match('/(?:password|passwd|secret|token|cookie|authorization|private[_-]?key|credential|session[_-]?id)/i', $key)===1){
					continue;
				}
				$result[$key]=self::sanitize($item, $depth+1);
			}
			return $result;
		}
		if(is_object($value)){
			if($value instanceof \JsonSerializable){ return self::sanitize($value->jsonSerialize(), $depth+1); }
			if($value instanceof \Stringable){ return (string)$value; }
			return null;
		}
		return is_scalar($value) || $value===null ? $value : null;
	}

	private static function mergeValue(mixed $base, mixed $current, mixed $incoming, string $path, array &$conflicts, string $strategy): mixed {
		if($incoming===$base){ return $current; }
		if($current===$base || $incoming===$current){ return $incoming; }
		if($base instanceof \stdClass && self::isMap($current) && self::isMap($incoming)){ $base=[]; }
		if(self::isMap($base) && self::isMap($current) && self::isMap($incoming)){
			$result=[];
			$keys=array_values(array_unique(array_merge(array_keys($base), array_keys($current), array_keys($incoming))));
			$missing=new \stdClass();
			foreach($keys as $key){
				$childPath=$path==='' ? (string)$key : $path.'.'.$key;
				$value=self::mergeValue($base[$key] ?? $missing, $current[$key] ?? $missing, $incoming[$key] ?? $missing, $childPath, $conflicts, $strategy);
				if($value!==$missing){ $result[$key]=$value; }
			}
			return $result;
		}
		$conflicts[]=$path!=='' ? $path : '$';
		return $strategy==='merge_prefer_current' ? $current : $incoming;
	}

	private static function isMap(mixed $value): bool {
		return is_array($value) && ($value===[] || !array_is_list($value));
	}

	/** @param array<string,mixed> $base @param array<string,mixed> $overlay @return array<string,mixed> */
	private static function overlay(array $base, array $overlay): array {
		foreach($overlay as $key=>$value){
			$base[$key]=self::isMap($value) && self::isMap($base[$key] ?? null) ? self::overlay($base[$key], $value) : $value;
		}
		return $base;
	}
}
