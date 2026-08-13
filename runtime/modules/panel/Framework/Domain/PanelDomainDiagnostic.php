<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** One stable, machine-readable domain compiler diagnostic. */
final class PanelDomainDiagnostic implements \JsonSerializable {
	public const SEVERITIES=['info','warning','error'];

	public function __construct(
		private readonly string $path,
		private readonly string $code,
		private readonly string $message,
		private readonly string $severity='error',
	){
		if($path===''||preg_match('/^[a-zA-Z0-9_.\[\]-]+$/D',$path)!==1){throw new \InvalidArgumentException('Domain diagnostic path is invalid.');}
		PanelOperationsGuard::name($code,'diagnostic code');
		PanelOperationsGuard::label($message,'diagnostic message',1024);
		if(!in_array($severity,self::SEVERITIES,true)){throw new \InvalidArgumentException('Domain diagnostic severity is invalid.');}
	}

	public function path():string{return$this->path;}
	public function code():string{return$this->code;}
	public function message():string{return$this->message;}
	public function severity():string{return$this->severity;}
	public function error():bool{return$this->severity==='error';}
	public function jsonSerialize():array{return['path'=>$this->path,'code'=>$this->code,'message'=>$this->message,'severity'=>$this->severity];}
}
