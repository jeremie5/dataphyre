<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable report for a root-confined scaffold write transaction.
 */
final class PanelScaffoldWriteResult implements \JsonSerializable {

	/**
	 * @param array<int,array<string,mixed>> $entries
	 */
	public function __construct(
		private readonly string $root,
		private readonly string $policy,
		private readonly bool $dryRun,
		private readonly array $entries
	){}

	public function root(): string {
		return $this->root;
	}

	public function policy(): string {
		return $this->policy;
	}

	public function dryRun(): bool {
		return $this->dryRun;
	}

	/** @return array<int,array<string,mixed>> */
	public function entries(): array {
		return $this->entries;
	}

	/** @return array<int,array<string,mixed>> */
	public function created(): array {
		return $this->forOperation('create');
	}

	/** @return array<int,array<string,mixed>> */
	public function replaced(): array {
		return $this->forOperation('replace');
	}

	/** @return array<int,array<string,mixed>> */
	public function skipped(): array {
		return array_values(array_filter(
			$this->entries,
			static fn(array $entry): bool=>in_array($entry['operation'] ?? '', ['identical', 'skip'], true)
		));
	}

	public function changed(): bool {
		return $this->created()!==[] || $this->replaced()!==[];
	}

	/** @return array<int,array<string,mixed>> */
	private function forOperation(string $operation): array {
		return array_values(array_filter(
			$this->entries,
			static fn(array $entry): bool=>($entry['operation'] ?? '')===$operation
		));
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_scaffold_write_result',
			'ok'=>true,
			'status'=>$this->dryRun ? 'planned' : 'applied',
			'root'=>$this->root,
			'policy'=>$this->policy,
			'dry_run'=>$this->dryRun,
			'changed'=>$this->changed(),
			'counts'=>[
				'artifacts'=>count($this->entries),
				'created'=>count($this->created()),
				'replaced'=>count($this->replaced()),
				'skipped'=>count($this->skipped()),
			],
			'entries'=>$this->entries,
		];
	}
}
