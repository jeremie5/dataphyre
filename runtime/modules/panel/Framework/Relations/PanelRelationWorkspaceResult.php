<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Result, audit record, and undo receipt for a relation workspace command. */
final class PanelRelationWorkspaceResult implements \JsonSerializable {
	public function __construct(
		private readonly string $status,
		private readonly string $operation,
		private readonly int $version,
		private readonly array $records=[],
		private readonly array $snapshot=[],
		private readonly array $errors=[],
		private readonly array $metadata=[]
	){}
	public function ok(): bool { return in_array($this->status, ['committed', 'duplicate'], true); }
	public function status(): string { return $this->status; }
	public function version(): int { return $this->version; }
	public function records(): array { return $this->records; }
	public function snapshot(): array { return $this->snapshot; }
	public function errors(): array { return $this->errors; }
	public function jsonSerialize(): array { return ['status'=>$this->status, 'ok'=>$this->ok(), 'operation'=>$this->operation, 'version'=>$this->version, 'records'=>$this->records, 'snapshot'=>$this->snapshot, 'errors'=>$this->errors, 'metadata'=>$this->metadata]; }
}
