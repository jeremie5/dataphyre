<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/** Immutable transaction result and rollback receipt. */
final class ReactorTransactionResult implements \JsonSerializable {
	public function __construct(
		private readonly string $status,
		private readonly string $transactionId,
		private readonly string $component,
		private readonly int $version,
		private readonly array $state=[],
		private readonly array $inversePatches=[],
		private readonly array $errors=[],
		private readonly array $metadata=[]
	){}

	public static function fromArray(array $data): self {
		return new self(
			(string)($data['status'] ?? 'failed'),
			(string)($data['transaction_id'] ?? ''),
			(string)($data['component'] ?? ''),
			(int)($data['version'] ?? 0),
			is_array($data['state'] ?? null) ? $data['state'] : [],
			is_array($data['inverse_patches'] ?? null) ? $data['inverse_patches'] : [],
			is_array($data['errors'] ?? null) ? $data['errors'] : [],
			is_array($data['metadata'] ?? null) ? $data['metadata'] : []
		);
	}

	public function ok(): bool { return in_array($this->status, ['committed', 'duplicate'], true); }
	public function status(): string { return $this->status; }
	public function transactionId(): string { return $this->transactionId; }
	public function version(): int { return $this->version; }
	public function state(): array { return $this->state; }
	public function inversePatches(): array { return $this->inversePatches; }
	public function errors(): array { return $this->errors; }
	public function metadata(): array { return $this->metadata; }

	public function rollbackTransaction(?string $idempotencyKey=null): ReactorStateTransaction {
		$transaction=ReactorStateTransaction::make($this->component, $this->version)
			->idempotencyKey($idempotencyKey ?? 'rollback:'.$this->transactionId)
			->conflictStrategy('reject')
			->metadata(['rollback_of'=>$this->transactionId]);
		foreach($this->inversePatches as $patch){
			$transaction=$transaction->patch($patch instanceof ReactorStatePatch ? $patch : ReactorStatePatch::fromArray((array)$patch));
		}
		return $transaction;
	}

	public function jsonSerialize(): array {
		return [
			'status'=>$this->status,
			'ok'=>$this->ok(),
			'transaction_id'=>$this->transactionId,
			'component'=>$this->component,
			'version'=>$this->version,
			'state'=>$this->state,
			'inverse_patches'=>$this->inversePatches,
			'errors'=>$this->errors,
			'metadata'=>$this->metadata,
		];
	}
}
