<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable hash-chained receipt for one durable collaboration activity. */
final class PanelCollaborationReceipt implements \JsonSerializable {
	/** @param array<string,mixed> $payload */
	public function __construct(private readonly array $payload) {
		foreach(['id', 'sequence', 'action', 'occurred_at', 'payload_hash', 'previous_hash', 'hash'] as $key){
			if(!array_key_exists($key, $payload)){ throw new \InvalidArgumentException('Collaboration receipt is missing '.$key.'.'); }
		}
	}
	public function id(): string { return (string)$this->payload['id']; }
	public function sequence(): int { return (int)$this->payload['sequence']; }
	public function action(): string { return (string)$this->payload['action']; }
	public function actor(): ?string { return isset($this->payload['actor']) ? (string)$this->payload['actor'] : null; }
	public function hash(): string { return (string)$this->payload['hash']; }
	public function previousHash(): string { return (string)$this->payload['previous_hash']; }
	/** @return array<string,mixed> */
	public function toArray(): array { return $this->payload; }
	/** @return array<string,mixed> */
	public function jsonSerialize(): array { return $this->payload; }
}
