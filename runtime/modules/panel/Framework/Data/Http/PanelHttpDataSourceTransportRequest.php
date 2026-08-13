<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Exact POST/JSON request passed only to the injected transport. */
final class PanelHttpDataSourceTransportRequest implements \JsonSerializable {
	public function __construct(
		private readonly string $endpoint,
		private readonly string $body,
		private readonly int $deadlineUnixMilliseconds,
		private readonly int $timeoutMilliseconds,
		private readonly int $attempt,
		private readonly PanelHttpDataSourceRuntime $runtime
	){
		if($endpoint==='' || strlen($endpoint)>2048){ throw new \InvalidArgumentException('Remote transport endpoint is invalid.'); }
		if($body==='' || strlen($body)>4194304){ throw new \InvalidArgumentException('Remote transport body is invalid.'); }
		if($deadlineUnixMilliseconds<0 || $timeoutMilliseconds<1 || $attempt<1 || $attempt>3){ throw new \InvalidArgumentException('Remote transport execution metadata is invalid.'); }
	}

	public function method(): string { return 'POST'; }
	public function endpoint(): string { return $this->endpoint; }
	public function contentType(): string { return 'application/json; charset=utf-8'; }
	public function accept(): string { return 'application/json'; }
	public function body(): string { return $this->body; }
	public function deadlineUnixMilliseconds(): int { return $this->deadlineUnixMilliseconds; }
	public function timeoutMilliseconds(): int { return $this->timeoutMilliseconds; }
	public function attempt(): int { return $this->attempt; }
	public function cancellationRequested(): bool { return $this->runtime->cancellationRequested(); }
	public function cancellationReason(): ?string { return $this->runtime->cancellationReason(); }
	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return ['type'=>'panel_http_data_transport_request','version'=>1,'method'=>'POST','content_type'=>$this->contentType(),'accept'=>$this->accept(),'body_bytes'=>strlen($this->body),'deadline_unix_ms'=>$this->deadlineUnixMilliseconds,'timeout_ms'=>$this->timeoutMilliseconds,'attempt'=>$this->attempt,'cancellation_supported'=>true,'endpoint_serialized'=>false,'body_serialized'=>false,'headers_serialized'=>false];
	}
}
