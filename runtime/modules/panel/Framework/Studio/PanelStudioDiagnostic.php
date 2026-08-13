<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable, deterministic Studio validation diagnostic. */
final class PanelStudioDiagnostic implements \JsonSerializable {
	public function __construct(
		private readonly string $path,
		private readonly string $code,
		private readonly string $message,
		private readonly string $severity='error'
	){
		if($this->path===''||preg_match('/^[a-z0-9_.\[\]-]+$/i',$this->path)!==1){throw new \InvalidArgumentException('Studio diagnostic paths must be deterministic paths.');}
		if(preg_match('/^[a-z][a-z0-9_]{1,63}$/',$this->code)!==1){throw new \InvalidArgumentException('Studio diagnostic codes must be safe identifiers.');}
		if(trim($this->message)===''){throw new \InvalidArgumentException('Studio diagnostic messages cannot be empty.');}
		if(!in_array($this->severity,['error','warning','info'],true)){throw new \InvalidArgumentException('Studio diagnostic severity is invalid.');}
	}
	public function path():string{return$this->path;}
	public function code():string{return$this->code;}
	public function message():string{return$this->message;}
	public function severity():string{return$this->severity;}
	public function jsonSerialize():array{return['path'=>$this->path,'code'=>$this->code,'message'=>$this->message,'severity'=>$this->severity];}
}
