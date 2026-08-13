<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Atomic new-execution lease or completed idempotent replay. */
final class PanelAgentStoreReservation {
	private function __construct(private readonly string $status, private readonly ?string $id, private readonly int $revision, private readonly ?int $expiresAt, private readonly ?PanelAgentExecutionResult $result){}
	public static function acquired(string $id, int $revision, int $expiresAt): self { PanelAgentGuard::identifier($id, 'reservation id', 128); if($revision<1 || $expiresAt<1){ throw new \InvalidArgumentException('Panel agent reservation lease is invalid.'); } return new self('acquired',$id,$revision,$expiresAt,null); }
	public static function replay(PanelAgentExecutionResult $result, int $revision): self { return new self('replay',null,$revision,null,$result); }
	public function acquiredNew(): bool { return $this->status==='acquired'; }
	public function id(): ?string { return $this->id; }
	public function revision(): int { return $this->revision; }
	public function expiresAt(): ?int { return $this->expiresAt; }
	public function result(): ?PanelAgentExecutionResult { return $this->result; }
}
