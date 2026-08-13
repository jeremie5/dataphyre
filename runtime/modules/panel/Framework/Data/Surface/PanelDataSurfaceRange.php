<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Exact bounded visible range plus server-owned overscan and optional cursor. */
final class PanelDataSurfaceRange implements \JsonSerializable {
	public const MAX_START=1000000000;
	public const MAX_LENGTH=500;
	public const MAX_OVERSCAN=250;
	public const MAX_FETCH=1000;

	public function __construct(
		private readonly int $start,
		private readonly int $length,
		private readonly int $overscanBefore=20,
		private readonly int $overscanAfter=40,
		private readonly ?string $cursor=null
	){
		if($start<0 || $start>self::MAX_START){ throw new \InvalidArgumentException('Panel DataSurface range start must be between 0 and 1000000000.'); }
		if($length<1 || $length>self::MAX_LENGTH){ throw new \InvalidArgumentException('Panel DataSurface range length must be between 1 and 500.'); }
		if($overscanBefore<0 || $overscanBefore>self::MAX_OVERSCAN || $overscanAfter<0 || $overscanAfter>self::MAX_OVERSCAN){ throw new \InvalidArgumentException('Panel DataSurface overscan must be between 0 and 250.'); }
		if($this->fetchLimit()>self::MAX_FETCH){ throw new \LengthException('Panel DataSurface fetched windows may not exceed 1000 records.'); }
		if($cursor!==null){
			PanelDataSurfaceGuard::boundedString($cursor, 'cursor', 4096);
			if($overscanBefore!==0){ throw new \InvalidArgumentException('Cursor DataSurface ranges cannot overscan before the cursor.'); }
		}
	}

	public static function make(int $start=0, int $length=50, int $overscanBefore=20, int $overscanAfter=40, ?string $cursor=null): self {
		return new self($start, $length, $overscanBefore, $overscanAfter, $cursor);
	}

	/** @param array<string,mixed> $value */
	public static function fromArray(array $value): self {
		$allowed=['start','length','overscan_before','overscan_after','cursor'];
		foreach(array_keys($value) as $key){ if(!is_string($key) || !in_array($key, $allowed, true)){ throw new \InvalidArgumentException('Panel DataSurface range contains unsupported fields.'); } }
		foreach(['start','length','overscan_before','overscan_after'] as $key){ if(isset($value[$key]) && !is_int($value[$key])){ throw new \InvalidArgumentException("Panel DataSurface range {$key} must be an integer."); } }
		if(isset($value['cursor']) && !is_string($value['cursor'])){ throw new \InvalidArgumentException('Panel DataSurface range cursor must be a string or null.'); }
		return new self(
			$value['start'] ?? 0,
			$value['length'] ?? 50,
			$value['overscan_before'] ?? 20,
			$value['overscan_after'] ?? 40,
			isset($value['cursor']) && $value['cursor']!=='' ? $value['cursor'] : null
		);
	}

	public function start(): int { return $this->start; }
	public function length(): int { return $this->length; }
	public function overscanBefore(): int { return $this->overscanBefore; }
	public function overscanAfter(): int { return $this->overscanAfter; }
	public function cursor(): ?string { return $this->cursor; }
	public function effectiveOffset(): int { return $this->cursor===null ? max(0, $this->start-$this->overscanBefore) : $this->start; }
	public function appliedOverscanBefore(): int { return $this->start-$this->effectiveOffset(); }
	public function fetchLimit(): int { return $this->length+$this->appliedOverscanBefore()+$this->overscanAfter; }

	/** Claims included inside a signed intent. */
	public function claims(): array {
		return [
			'start'=>$this->start,
			'length'=>$this->length,
			'overscan_before'=>$this->overscanBefore,
			'overscan_after'=>$this->overscanAfter,
			'cursor'=>$this->cursor,
		];
	}

	/** Public range metadata never discloses an upstream cursor. */
	public function jsonSerialize(): array {
		return [
			'start'=>$this->start,
			'length'=>$this->length,
			'overscan_before'=>$this->overscanBefore,
			'overscan_after'=>$this->overscanAfter,
			'effective_offset'=>$this->effectiveOffset(),
			'fetch_limit'=>$this->fetchLimit(),
			'cursor_present'=>$this->cursor!==null,
		];
	}
}
