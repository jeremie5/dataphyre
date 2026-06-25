<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable panel form state for values, errors, metadata, and field diagnostics.
 *
 * PanelFormState carries current values, normalized validation errors, initial/raw
 * and dehydrated values, dirty markers, server-computed updates, and JSON payloads
 * used by panel forms and dynamic field state responses.
 */
final class PanelFormState implements \JsonSerializable {

	/**
	 * Stores immutable panel form values, validation errors, and metadata.
	 *
	 * Use the with* methods to derive adjusted state without mutating the original
	 * instance.
	 *
	 * @param array<string, mixed> $values Current field values.
	 * @param array<string, array<int, string>|string> $errors Field errors before normalization.
	 * @param array<string, mixed> $meta Form metadata such as mode, operation, initial values, and dirty fields.
	 */
	public function __construct(
		private readonly array $values=[],
		private readonly array $errors=[],
		private readonly array $meta=[]
	){}

	/**
	 * Creates a panel form state with normalized error arrays.
	 *
	 * @param array<string, mixed> $values Current field values.
	 * @param array<string, array<int, string>|string> $errors Field errors before normalization.
	 * @param array<string, mixed> $meta Form metadata.
	 * @return self Immutable form state value.
	 */
	public static function make(array $values=[], array $errors=[], array $meta=[]): self {
		return new self($values, self::normalizeErrors($errors), $meta);
	}

	/**
	 * Returns all current form values.
	 *
	 * @return array<string, mixed> Current field values keyed by field name.
	 */
	public function values(): array {
		return $this->values;
	}

	/**
	 * Returns one current field value.
	 *
	 * @param string $field Field name.
	 * @param mixed $default Value returned when the field is absent.
	 * @return mixed current submitted/server value keyed by exact field name, or the caller default when absent.
	 */
	public function value(string $field, mixed $default=null): mixed {
		return $this->values[$field] ?? $default;
	}

	/**
	 * Returns all normalized field errors.
	 *
	 * @return array<string, array<int, string>> Error messages keyed by field name.
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Returns normalized errors for one field.
	 *
	 * @param string $field Field name.
	 * @return array<int, string> Field error messages.
	 */
	public function fieldErrors(string $field): array {
		return $this->errors[$field] ?? [];
	}

	/**
	 * Reports whether the form state has no validation errors.
	 *
	 * @return bool True when no field errors are present.
	 */
	public function valid(): bool {
		return $this->errors===[];
	}

	/**
	 * Reports whether the form state contains validation errors.
	 *
	 * @return bool True when at least one field has errors.
	 */
	public function invalid(): bool {
		return !$this->valid();
	}

	/**
	 * Returns all form metadata.
	 *
	 * @return array<string, mixed> Metadata captured by the panel form pipeline.
	 */
	public function meta(): array {
		return $this->meta;
	}

	/**
	 * Returns the form mode metadata value.
	 *
	 * @return string|null Non-empty mode string, or null when absent.
	 */
	public function mode(): ?string {
		$mode=$this->meta['mode'] ?? null;
		return is_string($mode) && $mode!=='' ? $mode : null;
	}

	/**
	 * Returns the form operation metadata value.
	 *
	 * @return string|null Non-empty operation string, or null when absent.
	 */
	public function operation(): ?string {
		$operation=$this->meta['operation'] ?? null;
		return is_string($operation) && $operation!=='' ? $operation : null;
	}

	/**
	 * Returns initial field values used for dirty comparisons.
	 *
	 * @return array<string, mixed> Initial field values from metadata.
	 */
	public function initialValues(): array {
		return is_array($this->meta['initial_values'] ?? null) ? $this->meta['initial_values'] : [];
	}

	/**
	 * Returns one initial field value.
	 *
	 * @param string $field Field name.
	 * @param mixed $default Value returned when the field is absent.
	 * @return mixed initial comparison value from metadata, or the caller default when absent.
	 */
	public function initialValue(string $field, mixed $default=null): mixed {
		$values=$this->initialValues();
		return $values[$field] ?? $default;
	}

	/**
	 * Returns raw submitted field values before normalization/dehydration.
	 *
	 * @return array<string, mixed> Raw field values from metadata.
	 */
	public function rawValues(): array {
		return is_array($this->meta['raw_values'] ?? null) ? $this->meta['raw_values'] : [];
	}

	/**
	 * Returns dehydrated values prepared for persistence or response payloads.
	 *
	 * @return array<string, mixed> Dehydrated field values from metadata.
	 */
	public function dehydratedValues(): array {
		return is_array($this->meta['dehydrated_values'] ?? null) ? $this->meta['dehydrated_values'] : [];
	}

	/**
	 * Returns one dehydrated field value.
	 *
	 * @param string $field Field name.
	 * @param mixed $default Value returned when the field is absent.
	 * @return mixed dehydrated value prepared for persistence/response payloads, or the caller default when absent.
	 */
	public function dehydratedValue(string $field, mixed $default=null): mixed {
		$values=$this->dehydratedValues();
		return $values[$field] ?? $default;
	}

	/**
	 * Reports whether any field is marked dirty.
	 *
	 * @return bool True when dirtyFields() is not empty.
	 */
	public function dirty(): bool {
		return $this->dirtyFields()!==[];
	}

	/**
	 * Returns normalized dirty field names.
	 *
	 * @return array<int, string> Non-empty dirty field names.
	 */
	public function dirtyFields(): array {
		$fields=$this->meta['dirty_fields'] ?? [];
		if(!is_array($fields)){
			return [];
		}
		return array_values(array_filter(array_map(
			static fn(mixed $field): string => trim((string)$field),
			$fields
		), static fn(string $field): bool => $field!==''));
	}

	/**
	 * Checks dirty state globally or for one field.
	 *
	 * @param string|null $field Optional field name.
	 * @return bool True when any field is dirty, or when the requested field is dirty.
	 */
	public function isDirty(?string $field=null): bool {
		if($field===null){
			return $this->dirty();
		}
		return in_array(trim($field), $this->dirtyFields(), true);
	}

	/**
	 * Returns dynamic field state update payloads.
	 *
	 * Passing a field name returns only that field's update payload when present.
	 *
	 * @param string|null $field Optional field name.
	 * @return array<string, mixed> All updates or one field update payload.
	 */
	public function stateUpdates(?string $field=null): array {
		$updates=is_array($this->meta['state_updates'] ?? null) ? $this->meta['state_updates'] : [];
		if($field===null){
			return $updates;
		}
		return is_array($updates[$field] ?? null) ? $updates[$field] : [];
	}

	/**
	 * Returns server-computed field values from metadata.
	 *
	 * @return array<string, mixed> Server-side values keyed by field name.
	 */
	public function serverValues(): array {
		return is_array($this->meta['server_values'] ?? null) ? $this->meta['server_values'] : [];
	}

	/**
	 * Builds a complete diagnostic state payload for one field.
	 *
	 * @param string $field Field name.
	 * @return array{name:string, value:mixed, initial:mixed, raw:mixed, dehydrated:mixed, dirty:bool, errors:array<int, string>, updates:array<string, mixed>} Field state payload.
	 */
	public function fieldState(string $field): array {
		return [
			'name'=>$field,
			'value'=>$this->value($field),
			'initial'=>$this->initialValue($field),
			'raw'=>$this->rawValues()[$field] ?? null,
			'dehydrated'=>$this->dehydratedValue($field),
			'dirty'=>$this->isDirty($field),
			'errors'=>$this->fieldErrors($field),
			'updates'=>$this->stateUpdates($field),
		];
	}

	/**
	 * Returns a clone with one current field value changed.
	 *
	 * @param string $field Field name.
	 * @param mixed $value New value.
	 * @return self Cloned form state.
	 */
	public function withValue(string $field, mixed $value): self {
		$values=$this->values;
		$values[$field]=$value;
		return new self($values, $this->errors, $this->meta);
	}

	/**
	 * Returns a clone with multiple current field values changed.
	 *
	 * @param array<string, mixed> $values Field values to merge or replace.
	 * @param bool $merge True to merge with existing values; false to replace all values.
	 * @return self Cloned form state.
	 */
	public function withValues(array $values, bool $merge=true): self {
		$values=$merge ? array_replace($this->values, $values) : $values;
		return new self($values, $this->errors, $this->meta);
	}

	/**
	 * Returns a clone with one field error appended.
	 *
	 * Empty field names and empty messages are ignored.
	 *
	 * @param string $field Field name.
	 * @param string $message Error message.
	 * @return self Cloned form state.
	 */
	public function withError(string $field, string $message): self {
		$errors=$this->errors;
		$field=trim($field);
		$message=trim($message);
		if($field!=='' && $message!==''){
			$errors[$field][]= $message;
		}
		return new self($this->values, self::normalizeErrors($errors), $this->meta);
	}

	/**
	 * Returns a clone with multiple field errors changed.
	 *
	 * @param array<string, array<int, string>|string> $errors Errors to merge or replace.
	 * @param bool $merge True to merge with existing errors; false to replace all errors.
	 * @return self Cloned form state.
	 */
	public function withErrors(array $errors, bool $merge=true): self {
		$errors=$merge ? array_merge_recursive($this->errors, $errors) : $errors;
		return new self($this->values, self::normalizeErrors($errors), $this->meta);
	}

	/**
	 * Returns a clone with errors removed.
	 *
	 * Passing null clears every error bag; otherwise only the named field is removed.
	 *
	 * @param string|null $field Optional field name to clear.
	 * @return self Cloned form state.
	 */
	public function withoutError(?string $field=null): self {
		if($field===null){
			return new self($this->values, [], $this->meta);
		}
		$errors=$this->errors;
		unset($errors[trim($field)]);
		return new self($this->values, $errors, $this->meta);
	}

	/**
	 * Returns a clone with metadata changed.
	 *
	 * @param array<string, mixed> $meta Metadata to merge or replace.
	 * @param bool $merge True to merge with existing metadata; false to replace metadata.
	 * @return self Cloned form state.
	 */
	public function withMeta(array $meta, bool $merge=true): self {
		$meta=$merge ? array_replace($this->meta, $meta) : $meta;
		return new self($this->values, $this->errors, $meta);
	}

	/**
	 * Returns a clone limited to selected fields and their errors.
	 *
	 * Metadata is preserved unchanged.
	 *
	 * @param array<int, string|int|float> $fields Field names to keep.
	 * @return self Cloned form state with filtered values and errors.
	 */
	public function only(array $fields): self {
		$allowed=array_fill_keys(array_map(static fn(mixed $field): string => trim((string)$field), $fields), true);
		$allowed=array_filter($allowed, static fn(mixed $value, string $field): bool => $field!=='', ARRAY_FILTER_USE_BOTH);
		return new self(array_intersect_key($this->values, $allowed), array_intersect_key($this->errors, $allowed), $this->meta);
	}

	/**
	 * Returns a clone excluding selected fields and their errors.
	 *
	 * Metadata is preserved unchanged.
	 *
	 * @param array<int, string|int|float> $fields Field names to remove.
	 * @return self Cloned form state with filtered values and errors.
	 */
	public function except(array $fields): self {
		$blocked=array_fill_keys(array_map(static fn(mixed $field): string => trim((string)$field), $fields), true);
		$blocked=array_filter($blocked, static fn(mixed $value, string $field): bool => $field!=='', ARRAY_FILTER_USE_BOTH);
		return new self(array_diff_key($this->values, $blocked), array_diff_key($this->errors, $blocked), $this->meta);
	}

	/**
	 * Serializes the form state for JSON responses and diagnostics.
	 *
	 * @return array{valid:bool, values:array<string, mixed>, errors:array<string, array<int, string>>, meta:array<string, mixed>} Form state payload.
	 */
	public function jsonSerialize(): array {
		return [
			'valid'=>$this->valid(),
			'values'=>$this->values,
			'errors'=>$this->errors,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Normalizes error input into non-empty message arrays keyed by field.
	 *
	 * @param array<string, array<int, string>|string|mixed> $errors Raw error payload.
	 * @return array<string, array<int, string>> Normalized error messages.
	 */
	private static function normalizeErrors(array $errors): array {
		$normalized=[];
		foreach($errors as $field=>$messages){
			if(!is_string($field) || trim($field)===''){
				continue;
			}
			$messages=is_array($messages) ? $messages : [$messages];
			foreach($messages as $message){
				$message=trim((string)$message);
				if($message!==''){
					$normalized[$field][]=$message;
				}
			}
		}
		return $normalized;
	}
}
