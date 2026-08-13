<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Serializable optimistic state transaction shared by server and browser.
 */
final class ReactorStateTransaction implements \JsonSerializable {
	/** @var list<ReactorStatePatch> */
	private array $patches=[];
	private string $id;
	private string $idempotencyKey;
	private string $conflictStrategy='reject';
	private bool $optimistic=true;
	private bool $offlineCapable=false;
	private ?int $expiresAt=null;
	private array $metadata=[];
	private ReactorRetryPolicy $retryPolicy;
	private ?string $signature=null;
	private readonly string $component;
	private readonly int $baseVersion;

	private function __construct(string $component, int $baseVersion) {
		$name=ReactorName::normalize($component);
		if($name===''){ throw new \InvalidArgumentException('A Reactor transaction requires a component name.'); }
		if($baseVersion<0){ throw new \InvalidArgumentException('A Reactor transaction version cannot be negative.'); }
		$this->component=$name;
		$this->baseVersion=$baseVersion;
		$this->id=self::randomId();
		$this->idempotencyKey=$this->id;
		$this->retryPolicy=new ReactorRetryPolicy();
	}

	public static function make(string $component, int $baseVersion=0): self { return new self($component, $baseVersion); }

	public static function fromArray(array $data): self {
		$transaction=self::make((string)($data['component'] ?? ''), (int)($data['base_version'] ?? 0));
		$transaction->id=trim((string)($data['id'] ?? '')) ?: $transaction->id;
		$transaction->idempotencyKey=trim((string)($data['idempotency_key'] ?? '')) ?: $transaction->id;
		$transaction->conflictStrategy=self::normalizeConflictStrategy((string)($data['conflict_strategy'] ?? 'reject'));
		$transaction->optimistic=($data['optimistic'] ?? true)!==false;
		$transaction->offlineCapable=($data['offline_capable'] ?? false)===true;
		$transaction->expiresAt=isset($data['expires_at']) ? max(0, (int)$data['expires_at']) : null;
		$transaction->metadata=is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
		$transaction->retryPolicy=ReactorRetryPolicy::fromArray(is_array($data['retry'] ?? null) ? $data['retry'] : []);
		$transaction->signature=isset($data['signature']) ? (string)$data['signature'] : null;
		foreach(is_array($data['patches'] ?? null) ? $data['patches'] : [] as $patch){
			if(is_array($patch)){ $transaction->patches[]=ReactorStatePatch::fromArray($patch); }
		}
		return $transaction;
	}

	public function id(string $id): self { $clone=clone $this; $clone->id=trim($id) ?: $this->id; $clone->signature=null; return $clone; }
	public function idValue(): string { return $this->id; }
	public function component(): string { return $this->component; }
	public function baseVersion(): int { return $this->baseVersion; }
	public function patches(): array { return $this->patches; }
	public function retryPolicy(): ReactorRetryPolicy { return $this->retryPolicy; }
	public function idempotencyKeyValue(): string { return $this->idempotencyKey; }
	public function conflictStrategyValue(): string { return $this->conflictStrategy; }
	public function optimisticValue(): bool { return $this->optimistic; }
	public function offlineCapableValue(): bool { return $this->offlineCapable; }
	public function metadataValue(): array { return $this->metadata; }

	public function patch(ReactorStatePatch|string $operation, ?string $path=null, mixed $value=null): self {
		$clone=clone $this;
		$clone->patches[]=$operation instanceof ReactorStatePatch ? $operation : ReactorStatePatch::make($operation, (string)$path, $value);
		$clone->signature=null;
		return $clone;
	}
	public function set(string $path, mixed $value): self { return $this->patch('set', $path, $value); }
	public function remove(string $path): self { return $this->patch('remove', $path); }
	public function merge(string $path, array $value): self { return $this->patch('merge', $path, $value); }
	public function increment(string $path, int|float $by=1): self { return $this->patch('increment', $path, $by); }
	public function append(string $path, mixed $value): self { return $this->patch('append', $path, $value); }
	public function test(string $path, mixed $value): self { return $this->patch('test', $path, $value); }

	public function idempotencyKey(string $key): self { $clone=clone $this; $clone->idempotencyKey=trim($key) ?: $this->id; $clone->signature=null; return $clone; }
	public function conflictStrategy(string $strategy): self { $clone=clone $this; $clone->conflictStrategy=self::normalizeConflictStrategy($strategy); $clone->signature=null; return $clone; }
	public function optimistic(bool $enabled=true): self { $clone=clone $this; $clone->optimistic=$enabled; $clone->signature=null; return $clone; }
	public function offlineCapable(bool $enabled=true): self { $clone=clone $this; $clone->offlineCapable=$enabled; $clone->signature=null; return $clone; }
	public function expiresAt(?int $timestamp): self { $clone=clone $this; $clone->expiresAt=$timestamp===null ? null : max(0, $timestamp); $clone->signature=null; return $clone; }
	public function expiresIn(int $seconds): self { return $this->expiresAt(time()+max(0, $seconds)); }
	public function metadata(array $metadata): self { $clone=clone $this; $clone->metadata=array_replace($clone->metadata, $metadata); $clone->signature=null; return $clone; }
	public function retry(ReactorRetryPolicy|array $policy): self { $clone=clone $this; $clone->retryPolicy=is_array($policy) ? ReactorRetryPolicy::fromArray($policy) : $policy; $clone->signature=null; return $clone; }
	public function expired(?int $now=null): bool { return $this->expiresAt!==null && $this->expiresAt<=($now ?? time()); }

	public function seal(): self {
		$clone=clone $this;
		$clone->signature=ReactorSigner::sign($clone->signingPayload());
		return $clone;
	}

	public function verify(): bool {
		if($this->signature===null || $this->signature===''){ return false; }
		return ReactorSigner::verify($this->signingPayload(), $this->signature);
	}

	public function jsonSerialize(): array { return $this->signingPayload()+['signature'=>$this->signature]; }

	private function signingPayload(): array {
		return [
			'id'=>$this->id,
			'component'=>$this->component,
			'base_version'=>$this->baseVersion,
			'idempotency_key'=>$this->idempotencyKey,
			'conflict_strategy'=>$this->conflictStrategy,
			'optimistic'=>$this->optimistic,
			'offline_capable'=>$this->offlineCapable,
			'expires_at'=>$this->expiresAt,
			'patches'=>array_map(static fn(ReactorStatePatch $patch): array => $patch->jsonSerialize(), $this->patches),
			'retry'=>$this->retryPolicy->jsonSerialize(),
			'metadata'=>$this->metadata,
		];
	}

	private static function normalizeConflictStrategy(string $strategy): string {
		$strategy=strtolower(trim($strategy));
		return in_array($strategy, ['reject', 'rebase', 'server_wins'], true) ? $strategy : 'reject';
	}

	private static function randomId(): string {
		return 'rtx_'.bin2hex(random_bytes(12));
	}
}
