<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Default process runtime. Hosts may inject a request-aware cancellable runtime. */
final class PanelSystemHttpDataSourceRuntime implements PanelHttpDataSourceRuntime {
	public function nowMilliseconds(): int { return (int)floor(microtime(true)*1000); }
	public function requestId(): string { return 'phr_'.bin2hex(random_bytes(16)); }
	public function cancellationRequested(): bool { return false; }
	public function cancellationReason(): ?string { return null; }
	public function waitMilliseconds(int $milliseconds, int $deadlineUnixMilliseconds): bool {
		if($milliseconds<0){ throw new \InvalidArgumentException('Remote retry waits cannot be negative.'); }
		if($milliseconds===0){ return $this->nowMilliseconds()<$deadlineUnixMilliseconds; }
		$remaining=$deadlineUnixMilliseconds-$this->nowMilliseconds();
		if($remaining<$milliseconds || $this->cancellationRequested()){ return false; }
		usleep($milliseconds*1000);
		return !$this->cancellationRequested() && $this->nowMilliseconds()<$deadlineUnixMilliseconds;
	}
}
