<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Cohesive per-user API for Panel appearance, views, history, pins, and notification preferences. */
final class PanelWorkspacePreferences implements \JsonSerializable {

	public function __construct(
		private readonly PanelPreferenceStore $store,
		private readonly string $userId,
		private readonly string $profile='default',
		private readonly ?string $device=null
	) {
		new PanelWorkspacePreferenceProfile($userId, $profile);
	}

	public function profile(): PanelWorkspacePreferenceProfile {
		return $this->store->load($this->userId, $this->profile) ?? new PanelWorkspacePreferenceProfile($this->userId, $this->profile);
	}

	/** @return array<string,mixed> */
	public function resolved(): array { return $this->profile()->resolved($this->device); }

	/** @param callable(array<string,mixed>&,array<string,array<string,mixed>>&):void $mutation */
	public function update(callable $mutation, ?int $expectedRevision=null, string $strategy='reject'): PanelWorkspacePreferenceProfile {
		if(strtolower(trim($strategy))==='retry'){
			$last=null;
			for($attempt=0; $attempt<5; $attempt++){
				$current=$this->profile();
				$settings=$current->settings(); $devices=$current->devices();
				$mutation($settings, $devices);
				try {
					return $this->store->save(new PanelWorkspacePreferenceProfile($this->userId, $this->profile, $settings, $devices, $current->revision(), $current->createdAt(), $current->updatedAt()), $current->revision(), 'reject');
				}
				catch(PanelPreferenceConflictException $exception){ $last=$exception; }
			}
			throw $last ?? new \RuntimeException('Unable to update Panel workspace after retrying optimistic conflicts.');
		}
		$current=$this->profile();
		$settings=$current->settings();
		$devices=$current->devices();
		$mutation($settings, $devices);
		$candidate=new PanelWorkspacePreferenceProfile($this->userId, $this->profile, $settings, $devices, $current->revision(), $current->createdAt(), $current->updatedAt());
		return $this->store->save($candidate, $expectedRevision ?? $current->revision(), $strategy);
	}

	public function appearance(string $theme, string $density='normal', string $locale='en', string $direction='auto', ?int $expectedRevision=null, string $strategy='reject'): PanelWorkspacePreferenceProfile {
		$theme=$this->name($theme, 'default');
		$density=$this->name($density, 'normal');
		$locale=PanelLocaleMetadata::normalize($locale) ?: 'en';
		$direction=in_array(strtolower(trim($direction)), ['ltr', 'rtl', 'auto'], true) ? strtolower(trim($direction)) : 'auto';
		return $this->update(static function(array &$settings) use ($theme, $density, $locale, $direction): void {
			$settings['appearance']=['theme'=>$theme, 'density'=>$density, 'locale'=>$locale, 'direction'=>$direction];
		}, $expectedRevision, $strategy);
	}

	/** @param array<string,mixed> $configuration */
	public function saveTableView(string $resource, string $name, array $configuration, ?int $expectedRevision=null, string $strategy='reject'): PanelWorkspacePreferenceProfile {
		$resource=$this->name($resource, 'resource');
		$name=$this->name($name, 'default');
		$view=PanelPreferenceStateEngine::sanitize(array_replace([
			'filters'=>[], 'sorts'=>[], 'groups'=>[], 'columns'=>[], 'search'=>'', 'page_size'=>null, 'presentation'=>null,
		], $configuration));
		$view['name']=$name;
		$view['resource']=$resource;
		$view['updated_at']=gmdate('c');
		return $this->update(static function(array &$settings) use ($resource, $name, $view): void {
			$settings['table_views'][$resource][$name]=$view;
		}, $expectedRevision, $strategy);
	}

	public function deleteTableView(string $resource, string $name, ?int $expectedRevision=null, string $strategy='reject'): PanelWorkspacePreferenceProfile {
		$resource=$this->name($resource, 'resource');
		$name=$this->name($name, 'default');
		return $this->update(static function(array &$settings) use ($resource, $name): void {
			unset($settings['table_views'][$resource][$name]);
			if(($settings['table_views'][$resource] ?? [])===[]){ unset($settings['table_views'][$resource]); }
		}, $expectedRevision, $strategy);
	}

	/** @return array<string,mixed>|null */
	public function tableView(string $resource, string $name='default'): ?array {
		$value=$this->resolved()['table_views'][$this->name($resource, 'resource')][$this->name($name, 'default')] ?? null;
		return is_array($value) ? $value : null;
	}

	/** @param array<string,mixed> $meta */
	public function touchRecent(string $type, string $id, array $meta=[], int $limit=30): PanelWorkspacePreferenceProfile {
		$type=$this->name($type, 'resource');
		$id=trim($id);
		if($id===''){ throw new \InvalidArgumentException('Recent workspace item id cannot be empty.'); }
		$entry=['type'=>$type, 'id'=>$id, 'visited_at'=>gmdate('c'), 'meta'=>PanelPreferenceStateEngine::sanitize($meta)];
		return $this->update(static function(array &$settings) use ($entry, $type, $id, $limit): void {
			$recent=array_values(array_filter((array)($settings['recent'] ?? []), static fn(mixed $item): bool => is_array($item) && !(($item['type'] ?? null)===$type && ($item['id'] ?? null)===$id)));
			array_unshift($recent, $entry);
			$settings['recent']=array_slice($recent, 0, max(1, min(100, $limit)));
		}, null, 'retry');
	}

	/** @param array<string,mixed> $meta */
	public function pin(string $type, string $id, array $meta=[]): PanelWorkspacePreferenceProfile {
		$type=$this->name($type, 'resource');
		$id=trim($id);
		if($id===''){ throw new \InvalidArgumentException('Pinned workspace item id cannot be empty.'); }
		$entry=['type'=>$type, 'id'=>$id, 'pinned_at'=>gmdate('c'), 'meta'=>PanelPreferenceStateEngine::sanitize($meta)];
		return $this->update(static function(array &$settings) use ($entry, $type, $id): void {
			$pinned=array_values(array_filter((array)($settings['pinned'] ?? []), static fn(mixed $item): bool => is_array($item) && !(($item['type'] ?? null)===$type && ($item['id'] ?? null)===$id)));
			$pinned[]=$entry;
			$settings['pinned']=$pinned;
		}, null, 'retry');
	}

	public function unpin(string $type, string $id): PanelWorkspacePreferenceProfile {
		$type=$this->name($type, 'resource');
		return $this->update(static function(array &$settings) use ($type, $id): void {
			$settings['pinned']=array_values(array_filter((array)($settings['pinned'] ?? []), static fn(mixed $item): bool => is_array($item) && !(($item['type'] ?? null)===$type && ($item['id'] ?? null)===$id)));
		}, null, 'retry');
	}

	/** @param array<string,mixed> $preferences */
	public function notifications(array $preferences, ?int $expectedRevision=null, string $strategy='reject'): PanelWorkspacePreferenceProfile {
		$preferences=PanelPreferenceStateEngine::sanitize($preferences);
		return $this->update(static function(array &$settings) use ($preferences): void {
			$settings['notifications']=array_replace(is_array($settings['notifications'] ?? null) ? $settings['notifications'] : [], $preferences);
		}, $expectedRevision, $strategy);
	}

	/** @param array<string,mixed> $overrides */
	public function deviceOverrides(string $device, array $overrides, ?int $expectedRevision=null, string $strategy='reject'): PanelWorkspacePreferenceProfile {
		$device=trim($device);
		if($device==='' || strlen($device)>256){ throw new \InvalidArgumentException('Panel device identifier is invalid.'); }
		$overrides=PanelPreferenceStateEngine::sanitize($overrides);
		return $this->update(static function(array &$settings, array &$devices) use ($device, $overrides): void {
			$devices[$device]=array_replace_recursive(is_array($devices[$device] ?? null) ? $devices[$device] : [], $overrides);
		}, $expectedRevision, $strategy);
	}

	/** @return array<string,mixed> */
	public function export(): array { return $this->store->export($this->userId, $this->profile); }
	/** @param array<string,mixed> $payload @return array<int,PanelWorkspacePreferenceProfile> */
	public function import(array $payload, string $strategy='merge'): array {
		foreach((array)($payload['profiles'] ?? []) as $profile){
			if(!is_array($profile) || !hash_equals($this->userId, (string)($profile['user_id'] ?? ''))){
				throw new \InvalidArgumentException('Workspace imports cannot cross user boundaries.');
			}
		}
		return $this->store->import($payload, $strategy);
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		$current=$this->profile();
		return [
			'type'=>'panel_workspace_preferences',
			'user_id'=>$this->userId,
			'profile'=>$current->name(),
			'device'=>$this->device,
			'revision'=>$current->revision(),
			'resolved'=>$current->resolved($this->device),
			'capabilities'=>[
				'appearance'=>true,
				'saved_table_views'=>true,
				'recent_items'=>true,
				'pinned_items'=>true,
				'notification_preferences'=>true,
				'device_overrides'=>true,
				'import_export'=>true,
				'optimistic_merge'=>true,
			],
			'store'=>$this->store->manifest(),
		];
	}

	public function jsonSerialize(): array { return $this->manifest(); }

	private function name(string $value, string $fallback): string {
		$value=strtolower(trim($value));
		$value=trim(preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '', '.-_');
		return $value!=='' ? substr($value, 0, 128) : $fallback;
	}
}
