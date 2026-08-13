<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable onboarding pipeline outcome with compensation evidence. */
final class PanelTenantOnboardingResult implements \JsonSerializable {

	/**
	 * @param list<string> $completed
	 * @param list<string> $rolledBack
	 * @param list<array<string,mixed>> $diagnostics
	 * @param array<string,mixed> $meta
	 */
	private function __construct(
		private readonly bool $ok,
		private readonly string $code,
		private readonly ?PanelTenant $tenant,
		private readonly array $completed=[],
		private readonly array $rolledBack=[],
		private readonly bool $replayed=false,
		private readonly array $diagnostics=[],
		private readonly array $meta=[]
	){}

	/** @param list<string> $completed @param array<string,mixed> $meta */
	public static function success(PanelTenant $tenant, array $completed=[], array $meta=[]): self {
		return new self(true, 'onboarded', $tenant, self::names($completed), [], false, [], PanelTenantSanitizer::map($meta));
	}

	/** @param list<string> $completed @param list<string> $rolledBack @param list<array<string,mixed>> $diagnostics @param array<string,mixed> $meta */
	public static function failure(string $code, ?PanelTenant $tenant=null, array $completed=[], array $rolledBack=[], array $diagnostics=[], array $meta=[]): self {
		return new self(
			false,
			Resource::normalizeName($code) ?: 'onboarding_failed',
			$tenant,
			self::names($completed),
			self::names($rolledBack),
			false,
			self::normalizeDiagnostics($diagnostics),
			PanelTenantSanitizer::map($meta)
		);
	}

	public function asReplay(): self {
		return new self($this->ok, $this->code, $this->tenant, $this->completed, $this->rolledBack, true, $this->diagnostics, $this->meta);
	}

	public function ok(): bool { return $this->ok; }
	public function code(): string { return $this->code; }
	public function tenant(): ?PanelTenant { return $this->tenant; }
	/** @return list<string> */
	public function completed(): array { return $this->completed; }
	/** @return list<string> */
	public function rolledBack(): array { return $this->rolledBack; }
	public function replayed(): bool { return $this->replayed; }
	/** @return list<array<string,mixed>> */
	public function diagnostics(): array { return $this->diagnostics; }
	/** @return array<string,mixed> */
	public function meta(): array { return $this->meta; }

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'ok'=>$this->ok,
			'code'=>$this->code,
			'tenant'=>$this->tenant?->name(),
			'completed'=>$this->completed,
			'rolled_back'=>$this->rolledBack,
			'replayed'=>$this->replayed,
			'diagnostics'=>$this->diagnostics,
			'meta'=>$this->meta,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	/** @return list<string> */
	private static function names(array $values): array {
		$names=[];
		foreach($values as $value){
			if(count($names)>=100){ break; }
			$name=Resource::normalizeName((string)$value);
			if($name!=='' && !in_array($name, $names, true)){ $names[]=$name; }
		}
		return $names;
	}

	/** @return list<array<string,mixed>> */
	private static function normalizeDiagnostics(array $values): array {
		$diagnostics=[];
		foreach($values as $value){
			if(count($diagnostics)>=50){ break; }
			if(is_array($value)){ $diagnostics[]=PanelTenantSanitizer::map($value); }
		}
		return $diagnostics;
	}
}
