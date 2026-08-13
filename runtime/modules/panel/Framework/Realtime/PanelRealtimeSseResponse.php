<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** HTTP-framework-neutral status, headers, and pullable SSE body. */
final class PanelRealtimeSseResponse implements \JsonSerializable {
	/** @var list<string> */ private array $staticChunks;
	/** @param array<string,string> $headers @param list<string> $staticChunks */
	private function __construct(private readonly int $status, private readonly array $headers, private readonly ?PanelRealtimeStreamSession $session, array $staticChunks=[]){
		if($status<100 || $status>599){ throw new \InvalidArgumentException('Panel realtime response status is invalid.'); }
		foreach($headers as $name=>$value){ if(preg_match('/^[A-Za-z0-9-]+$/D',(string)$name)!==1 || preg_match('/[\r\n]/',(string)$value)===1){ throw new \InvalidArgumentException('Panel realtime response header is invalid.'); } }
		$this->staticChunks=array_values($staticChunks);
	}

	public static function stream(PanelRealtimeStreamSession $session): self { return new self(200,self::baseHeaders(),$session); }
	public static function error(int $status, string $frame): self { return new self($status,self::baseHeaders(),null,[$frame]); }
	public function status(): int { return $this->status; }
	/** @return array<string,string> */ public function headers(): array { return $this->headers; }
	public function nextChunk(): ?string {
		if($this->staticChunks!==[]){ return array_shift($this->staticChunks); }
		return $this->session?->nextChunk();
	}
	/** Non-blocking finite pull helper. Empty idle chunks are omitted. */
	public function chunks(int $maximumPulls=100): \Generator {
		if($maximumPulls<1 || $maximumPulls>10000){ throw new \InvalidArgumentException('Panel realtime response pull bound is invalid.'); }
		for($pull=0;$pull<$maximumPulls;$pull++){
			$chunk=$this->nextChunk(); if($chunk===null || $chunk===''){ break; } yield $chunk;
		}
	}
	public function closed(): bool { return $this->session===null ? $this->staticChunks===[] : $this->session->closed(); }
	public function session(): ?PanelRealtimeStreamSession { return $this->session; }
	public function jsonSerialize(): array { return ['type'=>'panel_realtime_sse_response','version'=>1,'status'=>$this->status,'headers'=>$this->headers,'streaming'=>$this->session!==null,'closed'=>$this->closed(),'body_exposed_in_manifest'=>false]; }
	/** @return array<string,string> */
	private static function baseHeaders(): array { return ['Content-Type'=>'text/event-stream; charset=utf-8','Cache-Control'=>'no-store, private, no-transform','X-Accel-Buffering'=>'no','X-Content-Type-Options'=>'nosniff','X-Dataphyre-Realtime-Version'=>'1']; }
}
