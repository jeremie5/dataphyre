<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

final class IndexDefinition implements \JsonSerializable {

	public function __construct(
		private readonly string $name,
		private readonly string $type,
		private readonly string $primary_key_column_name,
		private readonly ?string $language=null,
		private readonly array $attributes=[]
	){}

	public static function fromArray(array $definition): self {
		$name=trim((string)($definition['name'] ?? ''));
		$type=strtolower(trim((string)($definition['type'] ?? 'json')));
		$primary_key_column_name=trim((string)($definition['primary_key_column_name'] ?? $definition['primary_key'] ?? ''));
		$language=isset($definition['language']) ? trim((string)$definition['language']) : '';
		$attributes=$definition;
		unset($attributes['name'], $attributes['type'], $attributes['primary_key_column_name'], $attributes['primary_key'], $attributes['language']);
		return new self(
			$name,
			$type!=='' ? $type : 'json',
			$primary_key_column_name,
			$language!=='' ? $language : null,
			$attributes
		);
	}

	public function name(): string {
		return $this->name;
	}

	public function type(): string {
		return $this->type;
	}

	public function primaryKeyColumnName(): string {
		return $this->primary_key_column_name;
	}

	public function language(): ?string {
		return $this->language;
	}

	public function attributes(): array {
		return $this->attributes;
	}

	public function isValid(): bool {
		return $this->name!==''
			&& $this->type!==''
			&& $this->primary_key_column_name!=='';
	}

	public function matches(IndexDefinition $other): bool {
		return $this->name===$other->name()
			&& $this->type===$other->type()
			&& $this->primary_key_column_name===$other->primaryKeyColumnName();
	}

	public function toKernelArray(): array {
		return [
			'name'=>$this->name,
			'type'=>$this->type,
			'primary_key_column_name'=>$this->primary_key_column_name,
			'language'=>$this->language,
		]+$this->attributes;
	}

	public function jsonSerialize(): array {
		return $this->toKernelArray();
	}
}
