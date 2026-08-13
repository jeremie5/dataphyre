<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Allowlisted correlated relation used to compile nested query expressions as EXISTS predicates. */
final class PanelSqlRelation implements \JsonSerializable {
	private function __construct(
		private readonly string $name,
		private readonly PanelSqlSchema $schema,
		private readonly string $localField,
		private readonly string $foreignField
	){}

	public static function make(string $name, PanelSqlSchema $schema, string $localField, string $foreignField): self {
		$name=self::singlePath($name, 'relation');
		$localField=self::singlePath($localField, 'local field');
		$foreignField=self::singlePath($foreignField, 'foreign field');
		if(!$schema->hasField($foreignField)){
			throw new \InvalidArgumentException("Panel SQL relation '{$name}' references unknown foreign field '{$foreignField}'.");
		}
		return new self($name, $schema, $localField, $foreignField);
	}

	public function name(): string { return $this->name; }
	public function schema(): PanelSqlSchema { return $this->schema; }
	public function localField(): string { return $this->localField; }
	public function foreignField(): string { return $this->foreignField; }

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_sql_relation', 'name'=>$this->name,
			'local_field'=>$this->localField, 'foreign_field'=>$this->foreignField,
			'schema'=>$this->schema->manifest(),
		];
	}

	private static function singlePath(string $value, string $label): string {
		$path=PanelQueryPath::make($value);
		if(count($path->segments())!==1){ throw new \InvalidArgumentException("Panel SQL {$label} must be a single allowlisted name."); }
		return $path->value();
	}
}
