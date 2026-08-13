<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable audit envelope emitted by an executable package rollback.
 */
final class PanelPackageRollbackResult implements \JsonSerializable {

	private bool $ok;
	private mixed $package;
	private string $targetRoot;
	private array $restored;
	private array $deleted;
	private array $skipped;
	private array $blocked;
	private array $reverted;
	private string $startedAt;
	private string $finishedAt;
	private int $durationMs;
	private array $meta;

	/** @param array<string,mixed> $data Raw rollback telemetry. */
	public function __construct(array $data=[]) {
		$collectionsValid=true;
		foreach(['restored','deleted','skipped','blocked','reverted'] as $section){
			if(array_key_exists($section, $data) && (!is_array($data[$section]) || !array_is_list($data[$section]))){$collectionsValid=false;}
		}
		$package=$data['package'] ?? null;
		$this->package=is_array($package) ? $this->sanitize($package) : (is_scalar($package) || $package===null ? $package : null);
		$this->targetRoot=(string)($data['target_root'] ?? '');
		$this->restored=$this->rows($data['restored'] ?? []);
		$this->deleted=$this->rows($data['deleted'] ?? []);
		$this->skipped=$this->rows($data['skipped'] ?? []);
		$this->blocked=$this->rows($data['blocked'] ?? []);
		$this->reverted=$this->rows($data['reverted'] ?? []);
		$this->startedAt=(string)($data['started_at'] ?? '');
		$this->finishedAt=(string)($data['finished_at'] ?? '');
		$this->durationMs=max(0, (int)($data['duration_ms'] ?? 0));
		$this->meta=is_array($data['meta'] ?? null) ? $this->sanitize($data['meta']) : [];
		$this->ok=(bool)($data['ok'] ?? false) && $collectionsValid && $this->blocked===[] && $this->reverted===[];
	}

	/** @return self Normalized rollback result. */
	public static function make(array $data=[]): self {
		return new self($data);
	}

	public function ok(): bool { return $this->ok; }
	public function package(): mixed { return $this->package; }
	public function targetRoot(): string { return $this->targetRoot; }
	/** @return array<int,array<string,mixed>> */
	public function restored(): array { return $this->restored; }
	/** @return array<int,array<string,mixed>> */
	public function deleted(): array { return $this->deleted; }
	/** @return array<int,array<string,mixed>> */
	public function skipped(): array { return $this->skipped; }
	/** @return array<int,array<string,mixed>> */
	public function blocked(): array { return $this->blocked; }
	/** @return array<int,array<string,mixed>> */
	public function reverted(): array { return $this->reverted; }

	/** @return array<string,mixed> Complete rollback audit payload. */
	public function toArray(): array {
		return [
			'type'=>'panel_package_rollback_result',
			'ok'=>$this->ok,
			'package'=>$this->package,
			'target_root'=>$this->targetRoot,
			'restored'=>$this->restored,
			'deleted'=>$this->deleted,
			'skipped'=>$this->skipped,
			'blocked'=>$this->blocked,
			'reverted'=>$this->reverted,
			'started_at'=>$this->startedAt,
			'finished_at'=>$this->finishedAt,
			'duration_ms'=>$this->durationMs,
			'meta'=>$this->meta,
		];
	}

	/** @return array<string,mixed> Complete rollback audit payload. */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/** @return array<int,array<string,mixed>> */
	private function rows(mixed $rows): array {
		return array_values(array_map(fn(array $row): array => $this->sanitize($row), array_filter((array)$rows, 'is_array')));
	}

	private function sanitize(mixed $value, string $key=''): mixed {
		if($key!=='' && preg_match('/(?:^|_)(?:secret|password|passwd|token|private_key|secret_key|credential|authorization|cookie)(?:$|_)/i', $key)===1){return '[REDACTED]';}
		if(!is_array($value)){return $value;}
		$sanitized=[];
		foreach($value as $itemKey=>$item){$sanitized[$itemKey]=$this->sanitize($item, is_string($itemKey) ? $itemKey : '');}
		return $sanitized;
	}
}
