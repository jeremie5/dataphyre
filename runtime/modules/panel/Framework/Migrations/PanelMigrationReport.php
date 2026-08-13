<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Stable, recursively redacted lifecycle receipt. */
final class PanelMigrationReport implements \JsonSerializable {
	private const STATUSES=['preflight','blocked','running','paused','failed','completed','rolling_back','rolled_back'];
	/** @param array<string,mixed> $payload */ private function __construct(private readonly array $payload){}
	/** @param array<string,mixed> $payload */ public static function make(array $payload):self{$status=strtolower(trim((string)($payload['status']??'')));if(!in_array($status,self::STATUSES,true)){throw new \InvalidArgumentException('Invalid Panel migration report status.');}$runId=isset($payload['run_id'])?(string)$payload['run_id']:null;if($runId!==null&&$runId!==''){PanelMigrationIntegrity::identifier($runId,'run id');}$payload['type']='panel_migration_report';$payload['manifest_version']=1;$payload['status']=$status;return new self(PanelMigrationIntegrity::redact($payload));}
	public function status():string{return(string)$this->payload['status'];}public function runId():?string{$value=$this->payload['run_id']??null;return is_string($value)&&$value!==''?$value:null;}public function planDigest():?string{$value=$this->payload['plan_digest']??null;return is_string($value)?$value:null;}public function successful():bool{return in_array($this->status(),['completed','rolled_back'],true);}public function terminal():bool{return in_array($this->status(),['blocked','failed','completed','rolled_back'],true);}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return$this->payload;}
}
