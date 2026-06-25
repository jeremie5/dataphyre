<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

/**
 * Describes one fulltext index definition.
 *
 * IndexDefinition is the immutable value passed between framework helpers and
 * the fulltext kernel when creating, comparing, or serializing search indexes.
 * It separates required identity fields from backend-specific attributes so
 * engines can preserve analyzer, tokenizer, shard, or mapping options without
 * changing the public API.
 */
final class IndexDefinition implements \JsonSerializable {

	/**
	 * Stores the normalized index identity and engine attributes.
	 *
	 * The name, type, and primary key column form the minimum valid definition.
	 * Language is optional and usually informs analyzer selection. Attributes are
	 * intentionally open-ended and are appended to the kernel definition after the
	 * required keys.
	 *
	 * @param readonly string $name Fulltext index name.
	 * @param readonly string $type Backend index type, defaulting to json when built from empty input.
	 * @param readonly string $primaryKeyColumnName Source record primary key column used for index identity.
	 * @param readonly ?string $language Optional language/analyzer hint.
	 * @param readonly array $attributes Additional backend-specific index options.
	 */
	public function __construct(
		private readonly string $name,
		private readonly string $type,
		private readonly string $primaryKeyColumnName,
		private readonly ?string $language=null,
		private readonly array $attributes=[]
	){}

	/** @var array<string,mixed>|null */
	private ?array $kernelPayload=null;

	/**
	 * Rehydrates an index definition from array data.
	 *
	 * Accepts both primary_key_column_name and primary_key for compatibility with
	 * older configuration arrays. Known identity keys are removed from attributes
	 * so toKernelArray() can reassemble canonical kernel data without duplicate
	 * aliases.
	 *
	 * @param array<string,mixed> $definition Raw index definition data.
	 * @return self Index definition value object.
	 */
	public static function fromArray(array $definition): self {
		$name=trim((string)($definition['name'] ?? ''));
		$type=strtolower(trim((string)($definition['type'] ?? 'json')));
		$primaryKeyColumnName=trim((string)($definition['primary_key_column_name'] ?? $definition['primary_key'] ?? ''));
		$language=isset($definition['language']) ? trim((string)$definition['language']) : '';
		$attributes=$definition;
		unset($attributes['name'], $attributes['type'], $attributes['primary_key_column_name'], $attributes['primary_key'], $attributes['language']);
		return new self(
			$name,
			$type!=='' ? $type : 'json',
			$primaryKeyColumnName,
			$language!=='' ? $language : null,
			$attributes
		);
	}

	/**
	 * Returns the fulltext index name.
	 *
	 *
	 * @return string Index name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the backend index type.
	 *
	 *
	 * @return string Normalized index type.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Returns the source primary-key column used to identify indexed records.
	 *
	 *
	 * @return string Primary-key column name.
	 */
	public function primaryKeyColumnName(): string {
		return $this->primaryKeyColumnName;
	}

	/**
	 * Returns the optional language or analyzer hint for the index.
	 *
	 *
	 * @return ?string Language hint, or null when none was configured.
	 */
	public function language(): ?string {
		return $this->language;
	}

	/**
	 * Returns backend-specific attributes preserved from the definition data.
	 *
	 * Attributes may include analyzer, tokenizer, field mapping, weighting,
	 * storage, or engine-specific options. The class does not interpret these
	 * fields; it only carries them through serialization.
	 *
	 * @return array<string,mixed> Additional index attributes.
	 */
	public function attributes(): array {
		return $this->attributes;
	}

	/**
	 * Indicates whether the definition has the minimum identity needed by the kernel.
	 *
	 *
	 * @return bool True when name, type, and primary key column are all non-empty.
	 */
	public function isValid(): bool {
		return $this->name!==''
			&& $this->type!==''
			&& $this->primaryKeyColumnName!=='';
	}

	/**
	 * Compares two definitions by their stable index identity.
	 *
	 * Matching intentionally ignores language and backend attributes, because
	 * those can change analyzer behavior without changing the identity of the
	 * index slot being addressed.
	 *
	 * @param IndexDefinition $other Definition to compare.
	 * @return bool True when name, type, and primary key column match exactly.
	 */
	public function matches(IndexDefinition $other): bool {
		return $this->name===$other->name()
			&& $this->type===$other->type()
			&& $this->primaryKeyColumnName===$other->primaryKeyColumnName();
	}

	/**
	 * Serializes the definition into the canonical kernel array.
	 *
	 * Required keys are emitted first, then preserved backend attributes are
	 * appended. Attribute keys cannot override required keys because array union
	 * keeps the left-hand required values, protecting the canonical identity
	 * fields.
	 *
	 * @return array<string,mixed> Kernel-ready index definition data.
	 */
	public function toKernelArray(): array {
		if($this->kernelPayload!==null){
			return $this->kernelPayload;
		}
		return $this->kernelPayload=[
			'name'=>$this->name,
			'type'=>$this->type,
			'primary_key_column_name'=>$this->primaryKeyColumnName,
			'language'=>$this->language,
		]+$this->attributes;
	}

	/**
	 * Serializes the index definition for JSON diagnostics and examples.
	 *
	 * @return array<string,mixed> Kernel-equivalent definition data.
	 */
	public function jsonSerialize(): array {
		return $this->toKernelArray();
	}
}
