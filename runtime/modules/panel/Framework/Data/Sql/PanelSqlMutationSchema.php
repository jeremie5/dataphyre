<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Explicit write allowlist, revision fence, and durable receipt schema for a SQL source. */
final class PanelSqlMutationSchema implements \JsonSerializable {
	public const RECEIPT_SCHEMA_VERSION=1;
	/** @var list<string> */private readonly array $writableFields;
	/** @var list<string> */private readonly array $operations;

	/** @param list<string> $writableFields @param list<string> $operations */
	private function __construct(
		private readonly PanelSqlSchema $schema,
		array $writableFields,
		private readonly string $revisionField,
		private readonly string $receiptTable,
		array $operations,
		private readonly int $maxBatch
	){$this->writableFields=$writableFields;$this->operations=$operations;}

	/** @param list<string> $writableFields @param array<string,mixed> $options */
	public static function make(PanelSqlSchema $schema,array $writableFields,string $revisionField,string $receiptTable,array $options=[]):self{
		$unknown=array_values(array_diff(array_keys($options),['operations','max_batch']));
		if($unknown!==[]){throw new \InvalidArgumentException('Unknown Panel SQL mutation-schema option: '.(string)$unknown[0]);}
		if(!array_is_list($writableFields)||$writableFields===[]){throw new \InvalidArgumentException('Panel SQL mutation schemas require a non-empty writable-field list.');}
		$writable=[];
		foreach($writableFields as$field){
			if(!is_string($field)||!$schema->hasField($field)){throw new \InvalidArgumentException('Panel SQL mutation writable fields must be allowlisted by the read schema.');}
			if($field===$schema->primaryKey()||$field===$schema->tenantField()){throw new \InvalidArgumentException('Panel SQL mutation identity and tenant fields cannot be writable.');}
			$writable[$field]=true;
		}
		$revisionField=self::field($revisionField);
		if(!$schema->hasField($revisionField)){throw new \InvalidArgumentException('Panel SQL mutation revision field must be allowlisted by the read schema.');}
		if($revisionField===$schema->primaryKey()||$revisionField===$schema->tenantField()||isset($writable[$revisionField])){throw new \InvalidArgumentException('Panel SQL mutation revision field must be dedicated and immutable.');}
		$receiptTable=self::identifierPath($receiptTable,'receipt table',3);
		$rawOperations=$options['operations']??PanelDataMutation::OPERATIONS;
		if(!is_array($rawOperations)||!array_is_list($rawOperations)||$rawOperations===[]){throw new \InvalidArgumentException('Panel SQL mutation operations must be a non-empty list.');}
		$operations=[];
		foreach($rawOperations as$operation){if(!is_string($operation)||!in_array($operation,PanelDataMutation::OPERATIONS,true)){throw new \InvalidArgumentException('Panel SQL mutation operation is invalid.');}$operations[$operation]=true;}
		$maxBatch=$options['max_batch']??100;
		if(!is_int($maxBatch)||$maxBatch<1||$maxBatch>100){throw new \InvalidArgumentException('Panel SQL mutation max_batch must be between 1 and 100.');}
		return new self($schema,array_keys($writable),$revisionField,$receiptTable,array_keys($operations),$maxBatch);
	}

	public function schema():PanelSqlSchema{return$this->schema;}
	/** @return list<string> */public function writableFields():array{return$this->writableFields;}
	public function writable(string $field):bool{return in_array($field,$this->writableFields,true);}
	public function revisionField():string{return$this->revisionField;}
	public function receiptTable():string{return$this->receiptTable;}
	/** @return list<string> */public function operations():array{return$this->operations;}
	public function supports(string $operation):bool{return in_array($operation,$this->operations,true);}
	public function maxBatch():int{return$this->maxBatch;}

	/** @return list<string> */
	public function migrationStatements(string $driver):array{
		$driver=self::driver($driver);$table=$this->quotePath($this->receiptTable,$driver);
		return match($driver){
			'mysql'=>["CREATE TABLE IF NOT EXISTS {$table} (source_name VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, idempotency_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, mutation_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, schema_version SMALLINT UNSIGNED NOT NULL, receipt_json LONGTEXT NOT NULL, created_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (source_name, idempotency_digest)) ENGINE=InnoDB"],
			'pgsql'=>["CREATE TABLE IF NOT EXISTS {$table} (source_name VARCHAR(128) NOT NULL, idempotency_digest CHAR(64) NOT NULL, mutation_fingerprint CHAR(64) NOT NULL, schema_version SMALLINT NOT NULL CHECK (schema_version = 1), receipt_json TEXT NOT NULL, created_at VARCHAR(64) NOT NULL, PRIMARY KEY (source_name, idempotency_digest))"],
			default=>["CREATE TABLE IF NOT EXISTS {$table} (source_name TEXT NOT NULL CHECK (length(source_name) BETWEEN 1 AND 128), idempotency_digest TEXT NOT NULL CHECK (length(idempotency_digest) = 64), mutation_fingerprint TEXT NOT NULL CHECK (length(mutation_fingerprint) = 64), schema_version INTEGER NOT NULL CHECK (schema_version = 1), receipt_json TEXT NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY (source_name, idempotency_digest))"],
		};
	}

	public function quoteTable(string $driver):string{return$this->quotePath($this->schema->table(),self::driver($driver));}
	public function quoteReceiptTable(string $driver):string{return$this->quotePath($this->receiptTable,self::driver($driver));}
	public function quoteColumn(string $field,string $driver):string{return$this->quoteIdentifier($this->schema->column($field),self::driver($driver));}
	public function quoteAlias(string $field,string $driver):string{return$this->quoteIdentifier(self::field($field),self::driver($driver));}

	/** @return array<string,mixed> */
	public function manifest():array{return[
		'type'=>'panel_sql_mutation_schema','version'=>1,'read_schema_name'=>$this->schema->name(),'read_schema_fingerprint'=>$this->schema->fingerprint(),
		'writable_fields'=>$this->writableFields,'revision_field'=>$this->revisionField,'receipt_table'=>$this->receiptTable,
		'receipt_schema_version'=>self::RECEIPT_SCHEMA_VERSION,'operations'=>$this->operations,'max_batch'=>$this->maxBatch,
		'hard_delete'=>in_array('delete',$this->operations,true),'identity_writable'=>false,'tenant_writable'=>false,
		'automatic_schema_mutation'=>false,'migration'=>'explicit_idempotent','destructive_migration'=>false,
	];}
	/** @return array<string,mixed> */public function jsonSerialize():array{return$this->manifest();}

	private function quotePath(string $path,string $driver):string{return implode('.',array_map(fn(string $part):string=>$this->quoteIdentifier($part,$driver),explode('.',$path)));}
	private function quoteIdentifier(string $identifier,string $driver):string{return$driver==='mysql'?'`'.str_replace('`','``',$identifier).'`':'"'.str_replace('"','""',$identifier).'"';}
	private static function driver(string $driver):string{$driver=strtolower(trim($driver));if(!in_array($driver,['mysql','pgsql','sqlite'],true)){throw new \InvalidArgumentException('Panel SQL mutation schemas support mysql, pgsql, and sqlite only.');}return$driver;}
	private static function field(string $field):string{$path=PanelQueryPath::make($field);if(count($path->segments())!==1){throw new \InvalidArgumentException('Panel SQL mutation fields must be single allowlisted names.');}return$path->value();}
	private static function identifierPath(string $identifier,string $label,int $maxParts):string{$identifier=trim($identifier);$parts=explode('.',$identifier);if(count($parts)>$maxParts){throw new \InvalidArgumentException("Panel SQL {$label} contains too many identifier parts.");}return implode('.',array_map(static fn(string $part):string=>self::identifier($part,$label),$parts));}
	private static function identifier(string $identifier,string $label):string{$identifier=trim($identifier);if($identifier===''||strlen($identifier)>63||preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$identifier)!==1){throw new \InvalidArgumentException("Invalid Panel SQL {$label} identifier '{$identifier}'.");}return$identifier;}
}
