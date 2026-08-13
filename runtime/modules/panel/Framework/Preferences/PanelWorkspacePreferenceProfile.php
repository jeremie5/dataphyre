<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable value representing one version of a user's Panel workspace. */
final class PanelWorkspacePreferenceProfile implements \JsonSerializable {

	private string $userId;
	private string $name;
	private int $revision;
	/** @var array<string,mixed> */
	private array $settings;
	/** @var array<string,array<string,mixed>> */
	private array $devices;
	private string $createdAt;
	private string $updatedAt;

	/** @param array<string,mixed> $settings @param array<string,array<string,mixed>> $devices */
	public function __construct(string $userId, string $name='default', array $settings=[], array $devices=[], int $revision=0, ?string $createdAt=null, ?string $updatedAt=null) {
		$this->userId=self::identifier($userId, 'user');
		$this->name=self::profileName($name);
		$this->revision=max(0, $revision);
		$this->settings=self::jsonArray($settings);
		$this->devices=[];
		foreach($devices as $device=>$overrides){
			$device=trim((string)$device);
			if($device!=='' && strlen($device)<=256 && is_array($overrides)){
				$this->devices[$device]=self::jsonArray($overrides);
			}
		}
		ksort($this->devices);
		$this->createdAt=$createdAt!==null && trim($createdAt)!=='' ? trim($createdAt) : gmdate('c');
		$this->updatedAt=$updatedAt!==null && trim($updatedAt)!=='' ? trim($updatedAt) : $this->createdAt;
	}

	/** @param array<string,mixed> $payload */
	public static function fromArray(array $payload): self {
		return new self(
			(string)($payload['user_id'] ?? ''),
			(string)($payload['profile'] ?? 'default'),
			is_array($payload['settings'] ?? null) ? $payload['settings'] : [],
			is_array($payload['devices'] ?? null) ? $payload['devices'] : [],
			(int)($payload['revision'] ?? 0),
			isset($payload['created_at']) ? (string)$payload['created_at'] : null,
			isset($payload['updated_at']) ? (string)$payload['updated_at'] : null
		);
	}

	public function userId(): string { return $this->userId; }
	public function name(): string { return $this->name; }
	public function revision(): int { return $this->revision; }
	/** @return array<string,mixed> */
	public function settings(): array { return $this->settings; }
	/** @return array<string,array<string,mixed>> */
	public function devices(): array { return $this->devices; }
	public function createdAt(): string { return $this->createdAt; }
	public function updatedAt(): string { return $this->updatedAt; }

	/** @return array<string,mixed> */
	public function resolved(?string $device=null): array {
		if($device===null || trim($device)==='' || !isset($this->devices[trim($device)])){
			return $this->settings;
		}
		return self::merge($this->settings, $this->devices[trim($device)]);
	}

	/** @param array<string,mixed> $settings @param array<string,array<string,mixed>>|null $devices */
	public function with(array $settings, ?array $devices=null): self {
		return new self($this->userId, $this->name, $settings, $devices ?? $this->devices, $this->revision, $this->createdAt, $this->updatedAt);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'id'=>substr(hash('sha256', $this->userId."\0".$this->name), 0, 32),
			'user_id'=>$this->userId,
			'profile'=>$this->name,
			'revision'=>$this->revision,
			'settings'=>$this->settings,
			'devices'=>$this->devices,
			'created_at'=>$this->createdAt,
			'updated_at'=>$this->updatedAt,
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array { return $this->toArray(); }

	private static function identifier(string $value, string $label): string {
		$value=trim($value);
		if($value==='' || strlen($value)>256 || preg_match('/[\x00-\x1F\x7F]/', $value)===1){
			throw new \InvalidArgumentException('Panel preference '.$label.' identifier is invalid.');
		}
		return $value;
	}

	private static function profileName(string $name): string {
		$name=strtolower(trim($name));
		$name=trim(preg_replace('/[^a-z0-9._-]+/', '-', $name) ?? '', '.-_');
		return $name!=='' ? substr($name, 0, 128) : 'default';
	}

	/** @param array<string,mixed> $value @return array<string,mixed> */
	private static function jsonArray(array $value): array {
		try {
			$decoded=json_decode(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
			return is_array($decoded) ? $decoded : [];
		}
		catch(\Throwable){ return []; }
	}

	/** @param array<string,mixed> $base @param array<string,mixed> $overlay @return array<string,mixed> */
	private static function merge(array $base, array $overlay): array {
		foreach($overlay as $key=>$value){
			$base[$key]=is_array($value) && is_array($base[$key] ?? null) && !array_is_list($value) && !array_is_list($base[$key])
				? self::merge($base[$key], $value)
				: $value;
		}
		return $base;
	}
}
