<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable deterministic outcome of a tenant switch attempt. */
final class PanelTenantSwitchResult implements \JsonSerializable {

	/** @param list<array<string,mixed>> $diagnostics @param array<string,mixed> $meta */
	private function __construct(
		private readonly bool $ok,
		private readonly string $code,
		private readonly PanelTenantContext $previous,
		private readonly PanelTenantContext $current,
		private readonly bool $persisted=false,
		private readonly array $diagnostics=[],
		private readonly array $meta=[]
	){}

	/** @param array<string,mixed> $meta */
	public static function success(PanelTenantContext $previous, PanelTenantContext $current, array $meta=[]): self {
		return new self(true, 'switched', $previous, $current, true, [], PanelTenantSanitizer::map($meta));
	}

	/** @param list<array<string,mixed>> $diagnostics @param array<string,mixed> $meta */
	public static function failure(string $code, PanelTenantContext $previous, ?PanelTenantContext $current=null, array $diagnostics=[], array $meta=[]): self {
		return new self(
			false,
			Resource::normalizeName($code) ?: 'switch_failed',
			$previous,
			$current ?? $previous,
			false,
			self::normalizeDiagnostics($diagnostics),
			PanelTenantSanitizer::map($meta)
		);
	}

	public function ok(): bool { return $this->ok; }
	public function code(): string { return $this->code; }
	public function previous(): PanelTenantContext { return $this->previous; }
	public function current(): PanelTenantContext { return $this->current; }
	public function persisted(): bool { return $this->persisted; }
	/** @return list<array<string,mixed>> */
	public function diagnostics(): array { return $this->diagnostics; }
	/** @return array<string,mixed> */
	public function meta(): array { return $this->meta; }

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'ok'=>$this->ok,
			'code'=>$this->code,
			'persisted'=>$this->persisted,
			'previous'=>$this->previous->toArray(),
			'current'=>$this->current->toArray(),
			'diagnostics'=>$this->diagnostics,
			'meta'=>$this->meta,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	/** @return list<array<string,mixed>> */
	private static function normalizeDiagnostics(array $diagnostics): array {
		$normalized=[];
		foreach($diagnostics as $diagnostic){
			if(count($normalized)>=50){ break; }
			if(is_array($diagnostic)){ $normalized[]=PanelTenantSanitizer::map($diagnostic); }
		}
		return $normalized;
	}
}
