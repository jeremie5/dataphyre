<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable pagination manifest shared by cursor and offset based sources. */
final class PanelDataPage implements \JsonSerializable {

	public function __construct(
		private readonly int $offset,
		private readonly int $limit,
		private readonly int $returned,
		private readonly ?int $total,
		private readonly ?string $nextCursor=null,
		private readonly ?string $previousCursor=null
	){
		if($offset<0 || $limit<1 || $returned<0 || ($total!==null && $total<0)){
			throw new \InvalidArgumentException('Panel data page counters are invalid.');
		}
		if($returned>$limit){ throw new \InvalidArgumentException('Panel data page returned count exceeds limit.'); }
	}

	public function offset(): int { return $this->offset; }
	public function limit(): int { return $this->limit; }
	public function returned(): int { return $this->returned; }
	public function total(): ?int { return $this->total; }
	public function nextCursor(): ?string { return $this->nextCursor; }
	public function previousCursor(): ?string { return $this->previousCursor; }
	public function hasMore(): bool { return $this->nextCursor!==null; }
	public function pageNumber(): int { return intdiv($this->offset, $this->limit)+1; }
	public function pageCount(): ?int { return $this->total===null ? null : max(1, (int)ceil($this->total/$this->limit)); }

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_data_page', 'offset'=>$this->offset, 'limit'=>$this->limit,
			'returned'=>$this->returned, 'total'=>$this->total, 'has_more'=>$this->hasMore(),
			'next_cursor'=>$this->nextCursor, 'previous_cursor'=>$this->previousCursor,
			'page'=>$this->pageNumber(), 'pages'=>$this->pageCount(),
		];
	}
}
