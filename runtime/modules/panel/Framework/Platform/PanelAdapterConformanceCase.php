<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** One bounded, serializable production-adapter behavior contract. */
final class PanelAdapterConformanceCase implements \JsonSerializable {
	private \Closure $probe;

	/** @param list<string> $capabilities @param list<string> $tags */
	private function __construct(
		private readonly string $id,
		private readonly string $label,
		callable $probe,
		private readonly array $capabilities,
		private readonly array $tags,
		private readonly bool $destructive,
		private readonly bool $optional,
		private readonly int $maxMillis
	){ $this->probe=\Closure::fromCallable($probe); }

	/** @param list<string> $capabilities @param list<string> $tags */
	public static function make(string $id, callable $probe, string $label='', array $capabilities=[], array $tags=[], bool $destructive=false, bool $optional=false, int $maxMillis=5000): self {
		$id=self::token($id);
		if($id===''){ throw new \InvalidArgumentException('Adapter conformance cases require an id.'); }
		$label=trim($label)!=='' ? trim($label) : str_replace('_', ' ', $id);
		return new self($id, $label, $probe, self::tokens($capabilities), self::tokens($tags), $destructive, $optional, max(1, min(300000, $maxMillis)));
	}

	public function id(): string { return $this->id; }
	public function label(): string { return $this->label; }
	/** @return list<string> */ public function capabilities(): array { return $this->capabilities; }
	/** @return list<string> */ public function tags(): array { return $this->tags; }
	public function destructive(): bool { return $this->destructive; }
	public function optional(): bool { return $this->optional; }
	public function maxMillis(): int { return $this->maxMillis; }
	public function run(object $adapter, PanelAdapterConformanceContext $context): void { ($this->probe)($adapter, $context); }

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return ['id'=>$this->id, 'label'=>$this->label, 'capabilities'=>$this->capabilities, 'tags'=>$this->tags, 'destructive'=>$this->destructive, 'optional'=>$this->optional, 'max_millis'=>$this->maxMillis];
	}

	/** @param array<mixed> $values @return list<string> */
	private static function tokens(array $values): array {
		$out=[];
		foreach($values as $value){ $token=self::token((string)$value); if($token!==''){ $out[]=$token; } }
		$out=array_values(array_unique($out)); sort($out, SORT_STRING); return $out;
	}

	private static function token(string $value): string {
		$value=strtolower(trim(str_replace(['.', '-'], '_', $value)));
		$value=preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
		return trim($value, '_');
	}
}
