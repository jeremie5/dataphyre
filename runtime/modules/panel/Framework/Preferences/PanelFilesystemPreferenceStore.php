<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Cross-process atomic filesystem preference store backed by immutable JSON snapshots. */
final class PanelFilesystemPreferenceStore implements PanelPreferenceStore, \JsonSerializable {

	private PanelAtomicSnapshotStore $store;
	private int $versionRetention;

	public function __construct(string $directory, int $snapshotRetention=512, int $versionRetention=100) {
		$this->versionRetention=max(2, $versionRetention);
		$this->store=new PanelAtomicSnapshotStore($directory, 'dataphyre.panel.preferences.v1', PanelPreferenceStateEngine::initialState(), $snapshotRetention);
	}

	public function load(string $userId, string $profile='default'): ?PanelWorkspacePreferenceProfile {
		return PanelPreferenceStateEngine::load($this->store->payload(), $userId, $profile);
	}

	public function save(PanelWorkspacePreferenceProfile $profile, ?int $expectedRevision=null, string $strategy='reject'): PanelWorkspacePreferenceProfile {
		$result=$this->store->transaction(function(array &$state) use ($profile, $expectedRevision, $strategy): PanelWorkspacePreferenceProfile {
			return PanelPreferenceStateEngine::save($state, $profile, $expectedRevision, $strategy, $this->versionRetention);
		}, 'preference.profile.saved', ['user_id'=>$profile->userId(), 'profile'=>$profile->name(), 'expected_revision'=>$expectedRevision, 'strategy'=>PanelPreferenceStateEngine::strategy($strategy)]);
		return $result['result'];
	}

	public function delete(string $userId, string $profile='default', ?int $expectedRevision=null): bool {
		$result=$this->store->transaction(function(array &$state) use ($userId, $profile, $expectedRevision): bool {
			return PanelPreferenceStateEngine::delete($state, $userId, $profile, $expectedRevision);
		}, 'preference.profile.deleted', ['user_id'=>$userId, 'profile'=>$profile, 'expected_revision'=>$expectedRevision]);
		return $result['result']===true;
	}

	public function profiles(string $userId): array {
		return PanelPreferenceStateEngine::profiles($this->store->payload(), $userId);
	}

	public function history(string $userId, string $profile='default', int $limit=100): array {
		return PanelPreferenceStateEngine::history($this->store->payload(), $userId, $profile, $limit);
	}

	public function export(string $userId, ?string $profile=null): array {
		return PanelPreferenceStateEngine::export($this->store->payload(), $userId, $profile);
	}

	public function import(array $payload, string $strategy='merge'): array {
		$result=$this->store->transaction(function(array &$state) use ($payload, $strategy): array {
			return PanelPreferenceStateEngine::import($state, $payload, $strategy, $this->versionRetention);
		}, 'preference.profiles.imported', ['count'=>is_array($payload['profiles'] ?? null) ? count($payload['profiles']) : 0, 'strategy'=>$strategy]);
		return $result['result'];
	}

	public function cursor(): int { return $this->store->cursor(); }
	public function changesSince(int $cursor=0, int $limit=100): array { return $this->store->changesSince($cursor, $limit); }

	public function manifest(array $meta=[]): array {
		$state=$this->store->payload();
		$versionCount=0;
		foreach((array)($state['versions'] ?? []) as $versions){ $versionCount+=is_array($versions) ? count($versions) : 0; }
		return [
			'type'=>'panel_preference_store',
			'adapter'=>'filesystem_atomic_json',
			'cursor'=>$this->cursor(),
			'profiles'=>count((array)($state['profiles'] ?? [])),
			'versions'=>$versionCount,
			'version_retention'=>$this->versionRetention,
			'capabilities'=>[
				'atomic'=>'cross_process',
				'optimistic_revisions'=>true,
				'three_way_merge'=>true,
				'history'=>true,
				'cursor_feed'=>true,
				'stale_cursor_reset'=>true,
				'import_export'=>true,
			],
			'store'=>$this->store->manifest(),
			'meta'=>PanelPreferenceStateEngine::sanitize($meta),
		];
	}

	public function jsonSerialize(): array { return $this->manifest(); }
}
