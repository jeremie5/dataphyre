<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Process-local atomic preference store for tests, previews, and short-lived workers. */
final class PanelInMemoryPreferenceStore implements PanelPreferenceStore, \JsonSerializable {

	/** @var array<string,mixed> */
	private array $state;
	/** @var array<int,array<string,mixed>> */
	private array $events=[];
	private int $cursor=0;
	private int $eventRetention;
	private int $versionRetention;

	public function __construct(int $eventRetention=512, int $versionRetention=100) {
		$this->state=PanelPreferenceStateEngine::initialState();
		$this->eventRetention=max(8, $eventRetention);
		$this->versionRetention=max(2, $versionRetention);
	}

	public function load(string $userId, string $profile='default'): ?PanelWorkspacePreferenceProfile {
		return PanelPreferenceStateEngine::load($this->state, $userId, $profile);
	}

	public function save(PanelWorkspacePreferenceProfile $profile, ?int $expectedRevision=null, string $strategy='reject'): PanelWorkspacePreferenceProfile {
		$saved=PanelPreferenceStateEngine::save($this->state, $profile, $expectedRevision, $strategy, $this->versionRetention);
		$this->event('profile.saved', ['user_id'=>$saved->userId(), 'profile'=>$saved->name(), 'revision'=>$saved->revision()]);
		return $saved;
	}

	public function delete(string $userId, string $profile='default', ?int $expectedRevision=null): bool {
		$deleted=PanelPreferenceStateEngine::delete($this->state, $userId, $profile, $expectedRevision);
		if($deleted){ $this->event('profile.deleted', ['user_id'=>$userId, 'profile'=>$profile]); }
		return $deleted;
	}

	public function profiles(string $userId): array {
		return PanelPreferenceStateEngine::profiles($this->state, $userId);
	}

	public function history(string $userId, string $profile='default', int $limit=100): array {
		return PanelPreferenceStateEngine::history($this->state, $userId, $profile, $limit);
	}

	public function export(string $userId, ?string $profile=null): array {
		return PanelPreferenceStateEngine::export($this->state, $userId, $profile);
	}

	public function import(array $payload, string $strategy='merge'): array {
		$results=PanelPreferenceStateEngine::import($this->state, $payload, $strategy, $this->versionRetention);
		$this->event('profiles.imported', ['count'=>count($results), 'profiles'=>array_map(static fn(PanelWorkspacePreferenceProfile $profile): string => $profile->name(), $results)]);
		return $results;
	}

	public function cursor(): int { return $this->cursor; }

	public function changesSince(int $cursor=0, int $limit=100): array {
		$cursor=max(0, $cursor);
		$limit=max(1, min(1000, $limit));
		$oldest=$this->events!==[] ? (int)$this->events[0]['cursor'] : 0;
		$reset=$cursor>0 && $oldest>0 && $cursor<$oldest-1;
		$changes=$reset ? [] : array_slice(array_values(array_filter($this->events, static fn(array $event): bool => (int)$event['cursor']>$cursor)), 0, $limit);
		$next=$changes!==[] ? (int)$changes[array_key_last($changes)]['cursor'] : $this->cursor;
		return [
			'cursor'=>$next,
			'oldest_cursor'=>$oldest,
			'reset_required'=>$reset,
			'changes'=>$changes,
			'snapshot'=>$reset ? [
				'type'=>'panel_workspace_preferences_snapshot',
				'profiles'=>array_values((array)($this->state['profiles'] ?? [])),
			] : null,
		];
	}

	public function manifest(array $meta=[]): array {
		return [
			'type'=>'panel_preference_store',
			'adapter'=>'memory',
			'cursor'=>$this->cursor,
			'profiles'=>count((array)$this->state['profiles']),
			'version_retention'=>$this->versionRetention,
			'event_retention'=>$this->eventRetention,
			'capabilities'=>[
				'atomic'=>'process_local',
				'optimistic_revisions'=>true,
				'three_way_merge'=>true,
				'history'=>true,
				'cursor_feed'=>true,
				'import_export'=>true,
			],
			'meta'=>PanelPreferenceStateEngine::sanitize($meta),
		];
	}

	public function jsonSerialize(): array { return $this->manifest(); }

	/** @param array<string,mixed> $payload */
	private function event(string $type, array $payload): void {
		$this->cursor++;
		$this->events[]=array_replace($payload, ['cursor'=>$this->cursor, 'type'=>$type, 'occurred_at'=>gmdate('c')]);
		if(count($this->events)>$this->eventRetention){
			$this->events=array_slice($this->events, -$this->eventRetention);
		}
	}
}
