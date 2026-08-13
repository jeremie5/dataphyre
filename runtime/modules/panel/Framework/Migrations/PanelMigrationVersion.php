<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Strict semantic application version paired with an integer state schema. */
final class PanelMigrationVersion implements \JsonSerializable {
	private function __construct(private readonly string $semantic,private readonly int $schema){}
	public static function make(string $semantic,int $schema):self {
		$semantic=trim($semantic);
		if(preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D',$semantic)!==1){throw new \InvalidArgumentException('Panel migration versions must be strict semantic versions.');}
		if($schema<0||$schema>2147483647){throw new \InvalidArgumentException('Panel migration schema versions must be non-negative 32-bit integers.');}
		return new self($semantic,$schema);
	}
	/** @param array<string,mixed> $data */ public static function fromArray(array $data):self{return self::make((string)($data['semantic_version']??''),(int)($data['state_schema_version']??-1));}
	public function semantic():string{return$this->semantic;}
	public function schema():int{return$this->schema;}
	public function compare(self $other):int{$semantic=version_compare($this->semantic,$other->semantic);return$semantic!==0?$semantic:($this->schema<=>$other->schema);}
	public function equals(self $other):bool{return$this->semantic===$other->semantic&&$this->schema===$other->schema;}
	public function before(self $other):bool{return version_compare($this->semantic,$other->semantic)<=0&&$this->schema<=$other->schema&&!$this->equals($other);}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return['type'=>'panel_migration_version','manifest_version'=>1,'semantic_version'=>$this->semantic,'state_schema_version'=>$this->schema];}
	public function __toString():string{return$this->semantic.'@'.$this->schema;}
}
