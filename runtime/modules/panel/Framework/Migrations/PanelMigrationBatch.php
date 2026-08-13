<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable handler result for one atomic, bounded migration batch. */
final class PanelMigrationBatch implements \JsonSerializable {
	/** @param array<string,mixed> $data @param array<string,mixed> $checkpoint @param array<string,mixed> $compensation */
	private function __construct(private readonly array $data,private readonly bool $done,private readonly string|int|null $nextCursor,private readonly int $processed,private readonly array $checkpoint,private readonly array $compensation){}
	/** @param array<string,mixed> $data @param array<string,mixed> $checkpoint @param array<string,mixed> $compensation */ public static function complete(array $data,int $processed=0,array $checkpoint=[],array $compensation=[]):self{return new self($data,true,null,self::validateProcessed($processed),$checkpoint,$compensation);}
	/** @param array<string,mixed> $data @param array<string,mixed> $checkpoint @param array<string,mixed> $compensation */ public static function more(array $data,string|int $nextCursor,int $processed,array $checkpoint=[],array $compensation=[]):self{if(is_string($nextCursor)&&trim($nextCursor)===''){throw new \InvalidArgumentException('Panel migration continuation cursors cannot be empty.');}return new self($data,false,$nextCursor,self::validateProcessed($processed),$checkpoint,$compensation);}
	/** @return array<string,mixed> */ public function data():array{return$this->data;} public function done():bool{return$this->done;} public function nextCursor():string|int|null{return$this->nextCursor;} public function processed():int{return$this->processed;}
	/** @return array<string,mixed> */ public function checkpoint():array{return$this->checkpoint;} /** @return array<string,mixed> */ public function compensation():array{return$this->compensation;}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return['type'=>'panel_migration_batch','manifest_version'=>1,'done'=>$this->done,'next_cursor'=>$this->nextCursor,'processed'=>$this->processed,'state_digest'=>PanelMigrationIntegrity::digest($this->data),'checkpoint'=>PanelMigrationIntegrity::redact($this->checkpoint),'compensation'=>PanelMigrationIntegrity::redact($this->compensation),'state_serialized'=>false];}
	private static function validateProcessed(int $processed):int{if($processed<0||$processed>10000000){throw new \InvalidArgumentException('Panel migration processed counts must be between 0 and 10000000.');}return$processed;}
}
