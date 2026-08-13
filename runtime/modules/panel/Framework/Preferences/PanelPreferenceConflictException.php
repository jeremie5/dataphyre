<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Optimistic revision or three-way merge conflict for a workspace profile. */
final class PanelPreferenceConflictException extends \RuntimeException implements \JsonSerializable {
	/** @param list<string> $conflicts */
	public function __construct(
		private readonly string $userId,
		private readonly string $profileName,
		private readonly ?int $expectedRevision,
		private readonly int $currentRevision,
		private readonly array $conflicts=[],
		string $message=''
	) {
		parent::__construct($message!=='' ? $message : 'Panel workspace preference revision conflict.');
	}

	public function expectedRevision(): ?int { return $this->expectedRevision; }
	public function currentRevision(): int { return $this->currentRevision; }
	/** @return list<string> */
	public function conflicts(): array { return $this->conflicts; }
	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_preference_conflict',
			'user_id'=>$this->userId,
			'profile'=>$this->profileName,
			'expected_revision'=>$this->expectedRevision,
			'current_revision'=>$this->currentRevision,
			'conflicts'=>$this->conflicts,
			'message'=>$this->getMessage(),
		];
	}
}
