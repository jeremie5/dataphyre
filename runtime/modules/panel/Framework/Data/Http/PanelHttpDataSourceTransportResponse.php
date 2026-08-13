<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Bounded transport response; arbitrary headers are deliberately unavailable. */
final class PanelHttpDataSourceTransportResponse implements \JsonSerializable {
	public function __construct(
		private readonly int $status,
		private readonly string $contentType,
		private readonly string $body,
		private readonly float $elapsedMilliseconds=0.0,
		private readonly ?int $retryAfterMilliseconds=null
	){
		if($status<100 || $status>599){ throw new \InvalidArgumentException('Remote transport status is invalid.'); }
		PanelHttpDataSourceValue::text($contentType, 'Remote response content type', 120);
		if(strlen($body)>8388608){ throw new \LengthException('Remote transport response exceeds the absolute 8 MiB bound.'); }
		if(!is_finite($elapsedMilliseconds) || $elapsedMilliseconds<0){ throw new \InvalidArgumentException('Remote transport elapsed time is invalid.'); }
		if($retryAfterMilliseconds!==null && ($retryAfterMilliseconds<0 || $retryAfterMilliseconds>300000)){ throw new \InvalidArgumentException('Remote retry-after metadata is invalid.'); }
	}

	/** @param array<string,mixed> $body */
	public static function json(int $status, array $body, float $elapsedMilliseconds=0.0, ?int $retryAfterMilliseconds=null): self {
		return new self($status, 'application/json; charset=utf-8', PanelHttpDataSourceValue::encode($body), $elapsedMilliseconds, $retryAfterMilliseconds);
	}

	public function status(): int { return $this->status; }
	public function contentType(): string { return $this->contentType; }
	public function body(): string { return $this->body; }
	public function elapsedMilliseconds(): float { return $this->elapsedMilliseconds; }
	public function retryAfterMilliseconds(): ?int { return $this->retryAfterMilliseconds; }
	/** @return array<string,mixed> */
	public function jsonSerialize(): array { return ['type'=>'panel_http_data_transport_response','version'=>1,'status'=>$this->status,'content_type'=>$this->contentType,'body_bytes'=>strlen($this->body),'elapsed_ms'=>$this->elapsedMilliseconds,'retry_after_ms'=>$this->retryAfterMilliseconds,'body_serialized'=>false,'headers_serialized'=>false]; }
}
