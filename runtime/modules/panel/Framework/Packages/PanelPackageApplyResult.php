<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Result envelope produced after applying a panel package to a target root.
 *
 * The object preserves the installer audit trail: package metadata, destination
 * root, written files, skipped files, backups, blocked writes, timing, and
 * caller-defined metadata. Array lists are normalized to array entries only so
 * JSON consumers receive predictable item collections.
 */
final class PanelPackageApplyResult implements \JsonSerializable {

	private bool $ok;
	private array $package;
	private string $targetRoot;
	private array $written;
	private array $skipped;
	private array $backups;
	private array $blocked;
	private string $startedAt;
	private string $finishedAt;
	private int $durationMs;
	private array $meta;

	/**
	 * Normalizes raw package-apply telemetry into a stable result object.
	*
	 * Unknown keys are ignored. File operation collections keep only array items
	 * because each entry is expected to describe a path operation, skip reason,
	 * backup location, or block reason.
	 *
	 * @param array<string, mixed> $data Raw installer result payload.
	 */
	public function __construct(array $data=[]) {
		$this->ok=(bool)($data['ok'] ?? false);
		$this->package=is_array($data['package'] ?? null) ? $data['package'] : [];
		$this->targetRoot=(string)($data['target_root'] ?? '');
		$this->written=array_values(array_filter((array)($data['written'] ?? []), 'is_array'));
		$this->skipped=array_values(array_filter((array)($data['skipped'] ?? []), 'is_array'));
		$this->backups=array_values(array_filter((array)($data['backups'] ?? []), 'is_array'));
		$this->blocked=array_values(array_filter((array)($data['blocked'] ?? []), 'is_array'));
		$this->startedAt=(string)($data['started_at'] ?? '');
		$this->finishedAt=(string)($data['finished_at'] ?? '');
		$this->durationMs=max(0, (int)($data['duration_ms'] ?? 0));
		$this->meta=is_array($data['meta'] ?? null) ? $data['meta'] : [];
	}

	/**
	 * Creates a package apply result from raw installer telemetry.
	*
	 * @param array<string, mixed> $data Raw installer result payload.
	 * @return self Normalized package apply result.
	 */
	public static function make(array $data=[]): self {
		return new self($data);
	}

	/**
	 * Reports whether the package apply operation completed successfully.
	 *
	 * @return bool `true` when the installer marked the apply as successful.
	 */
	public function ok(): bool {
		return $this->ok;
	}

	/**
	 * Returns package identity and manifest metadata captured during apply.
	 *
	 * @return array<string, mixed> Package descriptor supplied by the installer.
	 */
	public function package(): array {
		return $this->package;
	}

	/**
	 * Returns the filesystem root where the package was applied.
	 *
	 * @return string Target project or panel root path.
	 */
	public function targetRoot(): string {
		return $this->targetRoot;
	}

	/**
	 * Returns file entries written by the package apply operation.
	 *
	 * @return array<int, array<string, mixed>> Written file operation records.
	 */
	public function written(): array {
		return $this->written;
	}

	/**
	 * Returns file entries intentionally skipped by the installer.
	 *
	 * @return array<int, array<string, mixed>> Skipped file records, usually including a reason.
	 */
	public function skipped(): array {
		return $this->skipped;
	}

	/**
	 * Returns backup records created before overwriting target files.
	 *
	 * @return array<int, array<string, mixed>> Backup operation records.
	 */
	public function backups(): array {
		return $this->backups;
	}

	/**
	 * Returns writes blocked by policy, validation, or filesystem checks.
	 *
	 * @return array<int, array<string, mixed>> Blocked operation records.
	 */
	public function blocked(): array {
		return $this->blocked;
	}

	/**
	 * Exports the complete package-apply audit payload.
	 *
	 * @return array{type:string,ok:bool,package:array,target_root:string,written:array,skipped:array,backups:array,blocked:array,started_at:string,finished_at:string,duration_ms:int,meta:array}
	 */
	public function toArray(): array {
		return [
			'type'=>'panel_package_apply_result',
			'ok'=>$this->ok,
			'package'=>$this->package,
			'target_root'=>$this->targetRoot,
			'written'=>$this->written,
			'skipped'=>$this->skipped,
			'backups'=>$this->backups,
			'blocked'=>$this->blocked,
			'started_at'=>$this->startedAt,
			'finished_at'=>$this->finishedAt,
			'duration_ms'=>$this->durationMs,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Serializes the package apply result for API responses and panel diagnostics.
	 *
	 * @return array{type:string,ok:bool,package:array,target_root:string,written:array,skipped:array,backups:array,blocked:array,started_at:string,finished_at:string,duration_ms:int,meta:array}
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
