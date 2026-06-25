<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Stores signed Reactor component state for client round trips.
 *
 * A snapshot contains the normalized component name, mutable state, locked field
 * names, creation timestamp, and signature. Reactor can reconstruct a snapshot
 * from JSON payloads and verify that the component, state, locked list, and
 * timestamp have not been tampered with.
 */
final class ReactorSnapshot implements \JsonSerializable {

	/**
	 * Stores a signed Reactor state payload.
	 *
	 * @param string $component Normalized component name.
	 * @param array<string, mixed> $state Component state payload.
	 * @param array<int, string> $locked State keys locked against client mutation.
	 * @param int $createdAt Unix timestamp when the snapshot was created.
	 * @param string $signature Signature over component, state, locked keys, and timestamp.
	 */
	private function __construct(
		private readonly string $component,
		private readonly array $state,
		private readonly array $locked,
		private readonly int $createdAt,
		private readonly string $signature
	){}

	/**
	 * Creates and signs a new Reactor snapshot.
	 *
	 * Locked keys are normalized to unique strings before signing so verification
	 * uses the same canonical payload that is serialized to the client.
	 *
	 * @param string $component Component name.
	 * @param array<string, mixed> $state Component state payload.
	 * @param array<int, string> $locked State keys locked against client mutation.
	 * @return self Signed snapshot.
	 */
	public static function make(string $component, array $state=[], array $locked=[]): self {
		$component=ReactorName::normalize($component);
		$createdAt=time();
		$locked=array_values(array_unique(array_map('strval', $locked)));
		$payload=[
			'component'=>$component,
			'state'=>$state,
			'locked'=>$locked,
			'created_at'=>$createdAt,
		];
		return new self($component, $state, $locked, $createdAt, ReactorSigner::sign($payload));
	}

	/**
	 * Rehydrates a snapshot from JSON or an array payload.
	 *
	 * Invalid JSON and non-array payloads return null. Rehydration does not imply
	 * trust; callers must call verify() before accepting the state.
	 *
	 * @param mixed $snapshot Serialized snapshot JSON or decoded snapshot array.
	 * @return ?self Snapshot instance, or null when the payload cannot be decoded.
	 */
	public static function from(mixed $snapshot): ?self {
		if(is_string($snapshot)){
			$decoded=json_decode($snapshot, true);
			$snapshot=is_array($decoded) ? $decoded : null;
		}
		if(!is_array($snapshot)){
			return null;
		}
		return new self(
			ReactorName::normalize((string)($snapshot['component'] ?? '')),
			is_array($snapshot['state'] ?? null) ? $snapshot['state'] : [],
			is_array($snapshot['locked'] ?? null) ? array_values(array_map('strval', $snapshot['locked'])) : [],
			(int)($snapshot['created_at'] ?? 0),
			(string)($snapshot['signature'] ?? '')
		);
	}

	/**
	 * Returns the normalized component name.
	 *
	 *
	 * @return string Component name.
	 */
	public function component(): string {
		return $this->component;
	}

	/**
	 * Returns component state captured in the signed snapshot.
	 *
	 * @return array<string, mixed> Snapshot state.
	 */
	public function state(): array {
		return $this->state;
	}

	/**
	 * Verifies the snapshot signature.
	 *
	 * Verification covers the component name, state payload, locked keys, and
	 * creation timestamp. A true result means the serialized payload matches the
	 * server signature, not that the component is authorized to run.
	 *
	 * @return bool true when component state, locked keys, timestamp, and signature still match.
	 */
	public function verify(): bool {
		return ReactorSigner::verify([
			'component'=>$this->component,
			'state'=>$this->state,
			'locked'=>$this->locked,
			'created_at'=>$this->createdAt,
		], $this->signature);
	}

	/**
	 * Serializes the signed snapshot payload.
	 *
	 * @return array{component: string, state: array<string, mixed>, locked: array<int, string>, created_at: int, signature: string}
	 */
	public function jsonSerialize(): array {
		return [
			'component'=>$this->component,
			'state'=>$this->state,
			'locked'=>$this->locked,
			'created_at'=>$this->createdAt,
			'signature'=>$this->signature,
		];
	}
}
