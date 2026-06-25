<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Executes panel schema hydration, live state resolution, dehydration, and validation.
 *
 * SchemaLifecycle is the runtime bridge between Field definitions, PanelRequest input, records, and PanelFormState. It preserves field order, filters invalid field inputs, traces lifecycle events, resolves reactive field updates with bounded iterations, and records enough metadata for diagnostics to explain each form state transition.
 */
final class SchemaLifecycle {

	/**
	 * Stores normalized fields and schema metadata for lifecycle operations.
	 *
	 * @param array<string, Field> $fields Fields keyed by normalized field name.
	 * @param array<string,mixed> $meta Schema metadata copied into state payloads and traces.
	 */
	private function __construct(
		private readonly array $fields,
		private readonly array $meta=[]
	){}

	/**
	 * Builds a lifecycle from a list or map of Field objects.
	 *
	 * Non-field values are ignored so callers can pass mixed schema arrays without corrupting the lifecycle. Valid fields are keyed by Field::name(), ensuring later operations can address state consistently by field name.
	 *
	 * @param array<int|string,mixed> $fields Candidate Field objects.
	 * @param array<string,mixed> $meta Schema metadata copied into states and traces.
	 * @return self Lifecycle bound to normalized fields and metadata.
	 */
	public static function make(array $fields, array $meta=[]): self {
		$normalized=[];
		foreach($fields as $field){
			if($field instanceof Field){
				$normalized[$field->name()]=$field;
			}
		}
		return new self($normalized, $meta);
	}

	/**
	 * Creates a lifecycle from a ResourceForm definition.
	 *
	 * The form supplies both its field list and metadata, keeping runtime lifecycle behavior aligned with the resource form used to render the panel.
	 *
	 * @param ResourceForm $form Resource form whose fields should be executed.
	 * @return self Lifecycle derived from the form.
	 */
	public static function fromForm(ResourceForm $form): self {
		return self::make($form->fieldsList(), $form->metadata());
	}

	/**
	 * Creates a lifecycle from a Schema definition.
	 *
	 * Schema metadata is read from the schema array payload so generated schemas and runtime lifecycle state share the same descriptive context.
	 *
	 * @param Schema $schema Schema whose fields should be executed.
	 * @return self Lifecycle derived from the schema.
	 */
	public static function fromSchema(Schema $schema): self {
		return self::make($schema->fieldsList(), $schema->toArray()['meta'] ?? []);
	}

	/**
	 * Returns the normalized field map used by lifecycle operations.
	 *
	 *
	 * @return array<string, Field> Fields keyed by name.
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Provides schema metadata attached to lifecycle states and traces.
	 *
	 *
	 * @return array<string,mixed> Schema metadata.
	 */
	public function meta(): array {
		return $this->meta;
	}

	/**
	 * Hydrates display-state values from a record, request prefill, and request input.
	 *
	 * For each field, prefill query values win over record/default values, while request input can override the chosen default. The field then receives the value through hydrateValue(), allowing field-specific casts, formatting, or component state preparation before a PanelFormState is returned.
	 *
	 * @param mixed $record Existing record array/object used for initial values.
	 * @param ?PanelRequest $request Optional panel request supplying prefill and input values.
	 * @return PanelFormState Hydrated state with lifecycle metadata and no validation errors.
	 */
	public function hydrate(mixed $record=null, ?PanelRequest $request=null): PanelFormState {
		$values=[];
		$prefill=$request!==null ? self::prefillValues($request) : [];
		foreach($this->fields as $field){
			$meta=$field->toArray();
			$name=(string)$meta['name'];
			$default=array_key_exists($name, $prefill) ? $prefill[$name] : self::recordValue($record, $name, $meta['default'] ?? null);
			$value=$request?->input($name, $default) ?? self::recordValue($record, $name, $meta['default'] ?? null);
			$values[$name]=$field->hydrateValue($value, $record, $request);
		}
		$state=PanelFormState::make($values, [], $this->stateMeta('hydrate', [
			'operation'=>$request?->operation(),
			'field_count'=>count($this->fields),
		]));
		$this->trace('hydrated', [
			'field_count'=>count($this->fields),
			'state'=>$state,
		]);
		return $state;
	}

	/**
	 * Converts request input into persistable field values.
	 *
	 * File fields preserve the record value when the upload input is blank. Live state is resolved before dehydration, readonly fields are skipped unless explicitly dehydrated, hidden fields are skipped, and each included field controls final persistence conversion through dehydrateValue().
	 *
	 * @param PanelRequest $request Request supplying input and uploaded files.
	 * @param mixed $record Existing record array/object used as fallback state.
	 * @param ?string $operation Panel operation such as create, edit, or form.
	 * @return PanelFormState Dehydrated values with raw/dehydrated metadata.
	 */
	public function dehydrate(PanelRequest $request, mixed $record=null, ?string $operation=null): PanelFormState {
		$values=[];
		$rawValues=[];
		$operation ??=$request->operation();
		foreach($this->fields as $field){
			$meta=$field->toArray();
			$name=(string)$meta['name'];
			$value=$field->isFileUpload() ? $request->file($name) : $request->input($name, $meta['default'] ?? null);
			if($field->isFileUpload() && self::fileInputBlank($value)){
				$value=self::recordValue($record, $name, $meta['default'] ?? null);
			}
			$rawValues[$name]=$value;
		}
		[$resolvedValues]=$this->resolveLiveState($rawValues, $record, $request, $operation);
		foreach($this->fields as $field){
			$meta=$field->toArray();
			$name=(string)$meta['name'];
			if((($meta['readonly'] ?? false)===true && ($meta['meta']['dehydrated'] ?? null)!==true) || ($meta['meta']['dehydrated'] ?? true)===false || $field->isVisible($operation, $record, $request)===false){
				continue;
			}
			$value=$resolvedValues[$name] ?? $rawValues[$name] ?? ($meta['default'] ?? null);
			$values[$name]=$field->dehydrateValue($value, $record, $request);
		}
		$state=PanelFormState::make($values, [], $this->stateMeta('dehydrate', [
			'operation'=>$operation,
			'field_count'=>count($this->fields),
			'raw_values'=>$rawValues,
			'dehydrated_values'=>$values,
		]));
		$this->trace('dehydrated', [
			'field_count'=>count($this->fields),
			'operation'=>$operation,
			'state'=>$state,
		]);
		return $state;
	}

	/**
	 * Validates writable visible fields against resolved live state.
	 *
	 * Incoming values are first passed through live state resolution so dependent server-side field changes are validated consistently. Readonly and hidden fields are skipped, and field errors are collected by name without throwing, producing a PanelFormState suitable for UI feedback.
	 *
	 * @param array<string,mixed> $values Candidate field values.
	 * @param mixed $record Existing record array/object used by field validators.
	 * @param ?PanelRequest $request Optional request context for operation and input-aware validators.
	 * @param ?string $operation Panel operation override.
	 * @return PanelFormState Resolved values plus field error arrays.
	 */
	public function validate(array $values, mixed $record=null, ?PanelRequest $request=null, ?string $operation=null): PanelFormState {
		$errors=[];
		$operation ??=$request?->operation() ?? 'form';
		[$values]=$this->resolveLiveState($values, $record, $request, $operation);
		foreach($this->fields as $field){
			$meta=$field->toArray();
			$name=(string)$meta['name'];
			if(($meta['readonly'] ?? false)===true || $field->isVisible($operation, $record, $request)===false){
				continue;
			}
			$fieldErrors=$field->validateValue($values[$name] ?? null, $values, $record, $request, $operation);
			if($fieldErrors!==[]){
				$errors[$name]=$fieldErrors;
			}
		}
		$state=PanelFormState::make($values, $errors, $this->stateMeta('validate', [
			'operation'=>$operation,
			'field_count'=>count($this->fields),
			'validated'=>true,
		]));
		$this->trace('validated', [
			'field_count'=>count($this->fields),
			'operation'=>$operation,
			'state'=>$state,
		]);
		return $state;
	}

	/**
	 * Runs the standard submit lifecycle: dehydrate request data, then validate it.
	 *
	 * The operation defaults from the request and is shared across both phases so visibility, dehydration, live updates, and validation rules evaluate against the same form intent.
	 *
	 * @param PanelRequest $request Submitted panel request.
	 * @param mixed $record Existing record array/object for edit-like operations.
	 * @param ?string $operation Panel operation override.
	 * @return PanelFormState Validated submit state.
	 */
	public function submit(PanelRequest $request, mixed $record=null, ?string $operation=null): PanelFormState {
		$operation ??=$request->operation();
		$state=$this->dehydrate($request, $record, $operation);
		return $this->validate($state->values(), $record, $request, $operation);
	}

	/**
	 * Builds a full diagnostic form state snapshot.
	 *
	 * State snapshots include initial hydrated values, raw request/input values, current values after live state resolution, dehydrated values, dirty field names, server-provided updates, and optional validation errors. This method is the broadest lifecycle view and is intended for reactive UI updates, diagnostics, and state contract inspection.
	 *
	 * @param mixed $record Existing record array/object used for initial values.
	 * @param ?PanelRequest $request Optional request providing operation, input, files, and prefill values.
	 * @param ?string $operation Panel operation override.
	 * @param array<string,mixed> $input Explicit input values that override request reads.
	 * @param bool $validate True to validate the dehydrated snapshot before returning.
	 * @return PanelFormState Current state with diagnostic lifecycle metadata.
	 */
	public function state(
		mixed $record=null,
		?PanelRequest $request=null,
		?string $operation=null,
		array $input=[],
		bool $validate=false
	): PanelFormState {
		$operation ??=$request?->operation() ?? 'form';
		$prefill=$request!==null ? self::prefillValues($request) : [];
		$initialValues=[];
		$rawValues=[];
		$currentValues=[];
		foreach($this->fields as $field){
			$meta=$field->toArray();
			$name=(string)($meta['name'] ?? '');
			if($name===''){
				continue;
			}
			$initialRaw=array_key_exists($name, $prefill)
				? $prefill[$name]
				: self::recordValue($record, $name, $meta['default'] ?? null);
			$initialValues[$name]=$field->hydrateValue($initialRaw, $record, $request);
			if(array_key_exists($name, $input)){
				$raw=$input[$name];
			}
			elseif($field->isFileUpload() && $request!==null){
				$raw=$request->file($name);
				if(self::fileInputBlank($raw)){
					$raw=$initialRaw;
				}
			}
			else {
				$raw=$request?->input($name, $initialRaw) ?? $initialRaw;
			}
			$rawValues[$name]=$raw;
			$currentValues[$name]=$field->hydrateValue($raw, $record, $request);
		}
		[$currentValues, $stateUpdates, $serverValues]=$this->resolveLiveState($currentValues, $record, $request, $operation);
		$dehydratedValues=[];
		foreach($this->fields as $field){
			$meta=$field->toArray();
			$name=(string)($meta['name'] ?? '');
			if($name==='' || (($meta['readonly'] ?? false)===true && ($meta['meta']['dehydrated'] ?? null)!==true) || ($meta['meta']['dehydrated'] ?? true)===false || $field->isVisible($operation, $record, $request)===false){
				continue;
			}
			$dehydratedValues[$name]=$field->dehydrateValue($currentValues[$name] ?? null, $record, $request);
		}
		$errors=[];
		if($validate){
			$validated=$this->validate($dehydratedValues, $record, $request, $operation);
			$errors=$validated->errors();
		}
		$dirtyFields=[];
		foreach($currentValues as $name=>$value){
			if(!array_key_exists($name, $initialValues) || !self::valuesMatch($initialValues[$name], $value)){
				$dirtyFields[]=$name;
			}
		}
		$state=PanelFormState::make($currentValues, $errors, $this->stateMeta('state', [
			'operation'=>$operation,
			'field_count'=>count($this->fields),
			'initial_values'=>$initialValues,
			'raw_values'=>$rawValues,
			'dehydrated_values'=>$dehydratedValues,
			'dirty_fields'=>$dirtyFields,
			'state_updates'=>$stateUpdates,
			'server_values'=>$serverValues,
			'validated'=>$validate,
		]));
		$this->trace('state', [
			'field_count'=>count($this->fields),
			'operation'=>$operation,
			'dirty_fields'=>$dirtyFields,
			'validated'=>$validate,
			'state'=>$state,
		]);
		return $state;
	}

	/**
	 * Resolves reactive field state updates produced by Field::stateFor().
	 *
	 * The resolver performs up to three passes so field updates can cascade without looping indefinitely. Updates may target the field itself through value or other fields through set/fields maps; each server-set value records propagation flags for the caller.
	 *
	 * @param array<string,mixed> $values Current field values.
	 * @param mixed $record Existing record array/object used by field state callbacks.
	 * @param ?PanelRequest $request Optional request context.
	 * @param ?string $operation Panel operation override.
	 * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,array<string,bool>>} Resolved values, last update payloads, and server value flags.
	 */
	public function resolveLiveState(array $values, mixed $record=null, ?PanelRequest $request=null, ?string $operation=null): array {
		$operation ??=$request?->operation() ?? 'form';
		$stateValues=$values;
		$updates=[];
		$serverValues=[];
		for($iteration=0; $iteration<3; $iteration++){
			$changed=false;
			$updates=[];
			foreach($this->fields as $field){
				$meta=$field->toArray();
				$name=(string)($meta['name'] ?? '');
				if($name===''){
					continue;
				}
				$value=$stateValues[$name] ?? $request?->input($name, self::recordValue($record, $name, $meta['default'] ?? null));
				$update=$field->stateFor($stateValues, $value, $record, $request, $operation);
				$updates[$name]=$update;
				if(array_key_exists('value', $update)){
					$changed=self::applyResolvedStateValue($stateValues, $serverValues, $name, $update['value'], $update) || $changed;
				}
				foreach(['set', 'fields'] as $setKey){
					if(!isset($update[$setKey]) || !is_array($update[$setKey])){
						continue;
					}
					foreach($update[$setKey] as $target=>$targetValue){
						$target=Resource::normalizeName((string)$target);
						if($target===''){
							continue;
						}
						$flags=[];
						if(is_array($targetValue) && array_key_exists('value', $targetValue)){
							$flags=$targetValue;
							$targetValue=$targetValue['value'];
						}
						$changed=self::applyResolvedStateValue($stateValues, $serverValues, $target, $targetValue, $flags) || $changed;
					}
				}
			}
			if(!$changed){
				break;
			}
		}
		return [$stateValues, $updates, $serverValues];
	}

	/**
	 * Describes fields and lifecycle capabilities without executing state transitions.
	 *
	 * The payload includes per-field type, label, visibility flags, dehydration behavior, live/reactive capabilities, validation rules, dependencies, and metadata. It is safe for client boot payloads, manifests, and diagnostics.
	 *
	 * @param ?string $operation Optional operation label included in each field description.
	 * @return array<string,mixed> Serializable schema lifecycle description.
	 */
	public function describe(?string $operation=null): array {
		$fields=[];
		foreach($this->fields as $field){
			$meta=$field->toArray();
			$name=(string)($meta['name'] ?? '');
			if($name===''){
				continue;
			}
			$fields[$name]=[
				'name'=>$name,
				'type'=>(string)($meta['type'] ?? 'text'),
				'label'=>(string)($meta['label'] ?? $name),
				'required'=>($meta['required'] ?? false)===true,
				'readonly'=>($meta['readonly'] ?? false)===true,
				'visible'=>array_key_exists('visible', $meta) ? (bool)$meta['visible'] : true,
				'operation'=>$operation,
				'live'=>($meta['live'] ?? false)===true,
				'reactive'=>($meta['reactive'] ?? false)===true,
				'conditional'=>($meta['conditional'] ?? false)===true,
				'dynamic_options'=>($meta['dynamic_options'] ?? false)===true,
				'dehydrated'=>(($meta['meta']['dehydrated'] ?? null)===false || (($meta['readonly'] ?? false)===true && ($meta['meta']['dehydrated'] ?? null)!==true)) ? false : true,
				'state_updates'=>($meta['state_updates'] ?? false)===true,
				'hydrates'=>($meta['hydrates'] ?? false)===true,
				'dehydrates'=>($meta['dehydrates'] ?? false)===true,
				'validates'=>($meta['validates'] ?? false)===true,
				'rules'=>is_array($meta['rules'] ?? null) ? $meta['rules'] : [],
				'depends_on'=>is_array($meta['depends_on'] ?? null) ? $meta['depends_on'] : [],
				'meta'=>is_array($meta['meta'] ?? null) ? $meta['meta'] : [],
			];
		}
		return [
			'type'=>'schema_lifecycle',
			'field_count'=>count($fields),
			'operation'=>$operation,
			'fields'=>$fields,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Builds the shared metadata envelope for PanelFormState results.
	 *
	 * @param string $mode Lifecycle mode that produced the state.
	 * @param array<string,mixed> $meta Mode-specific metadata.
	 * @return array<string,mixed> State metadata with lifecycle and schema context.
	 */
	private function stateMeta(string $mode, array $meta=[]): array {
		return array_replace([
			'mode'=>$mode,
			'lifecycle'=>'schema',
			'schema_meta'=>$this->meta,
		], $meta);
	}

	/**
	 * Records a lifecycle trace event using the configured schema trace prefix.
	 *
	 * Blank prefixes fall back to schema.lifecycle. Payloads may contain PanelFormState objects for local diagnostics; trace storage decides how to serialize or display them.
	 *
	 * @param string $event Event suffix such as hydrated, dehydrated, validated, or state.
	 * @param array<string,mixed> $payload Trace payload.
	 * @return void Trace is recorded through PanelTrace.
	 */
	private function trace(string $event, array $payload=[]): void {
		$prefix=trim((string)($this->meta['lifecycle_trace_prefix'] ?? 'schema.lifecycle'));
		if($prefix===''){
			$prefix='schema.lifecycle';
		}
		PanelTrace::record($prefix.'.'.$event, $payload);
	}

	/**
	 * Reads a field value from an array or object record.
	 *
	 * Arrays use direct key lookup. Objects first check public properties, then a getter built from the field name, and finally return the supplied default.
	 *
	 * @param mixed $record Record array/object or null.
	 * @param string $key Field name to read.
	 * @param mixed $default Value returned when the record does not expose the field.
	 * @return mixed array value, public property, getter result, or the caller default when unavailable.
	 */
	private static function recordValue(mixed $record, string $key, mixed $default=null): mixed {
		if(is_array($record)){
			return $record[$key] ?? $default;
		}
		if(is_object($record)){
			if(isset($record->{$key})){
				return $record->{$key};
			}
			$method='get'.str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $key)));
			if(method_exists($record, $method)){
				return $record->{$method}();
			}
		}
		return $default;
	}

	/**
	 * Applies one resolved live-state value and records its server update flags.
	 *
	 * @param array<string,mixed> $stateValues Current mutable state values.
	 * @param array<string,array<string,bool>> $serverValues Server-applied value flags.
	 * @param string $name Target field name.
	 * @param mixed $value New value supplied by a state callback.
	 * @param array<string,mixed> $flags Optional force_value and propagate flags.
	 * @return bool True when the state value changed.
	 */
	private static function applyResolvedStateValue(array &$stateValues, array &$serverValues, string $name, mixed $value, array $flags=[]): bool {
		$changed=!array_key_exists($name, $stateValues) || $stateValues[$name]!==$value;
		$stateValues[$name]=$value;
		$serverValues[$name]=[
			'force_value'=>($flags['force_value'] ?? false)===true,
			'propagate'=>($flags['propagate'] ?? false)===true,
		];
		return $changed;
	}

	/**
	 * Extracts scalar prefill values from the request query.
	 *
	 * Prefill keys are normalized as field names and non-scalar values are ignored so query payloads cannot inject nested state structures into the form lifecycle.
	 *
	 * @param PanelRequest $request Request containing an optional prefill query array.
	 * @return array<string,scalar|null> Normalized prefill values keyed by field name.
	 */
	private static function prefillValues(PanelRequest $request): array {
		$prefill=$request->query('prefill', []);
		if(!is_array($prefill)){
			return [];
		}
		$values=[];
		foreach($prefill as $field=>$value){
			$field=Resource::normalizeName((string)$field);
			if($field!=='' && (is_scalar($value) || $value===null)){
				$values[$field]=$value;
			}
		}
		return $values;
	}

	/**
	 * Determines whether an upload input contains no selected file.
	 *
	 * Both single-file and multi-file PHP upload shapes are supported. Non-array values are treated as blank because they cannot represent a valid uploaded file payload.
	 *
	 * @param mixed $value Upload input payload.
	 * @return bool True when no file was submitted.
	 */
	private static function fileInputBlank(mixed $value): bool {
		if(!is_array($value)){
			return true;
		}
		if(isset($value['error']) && !is_array($value['error'])){
			return (int)$value['error']===UPLOAD_ERR_NO_FILE || trim((string)($value['name'] ?? ''))==='';
		}
		if(isset($value['name']) && is_array($value['name'])){
			foreach($value['name'] as $index=>$name){
				$error=is_array($value['error'] ?? null) ? (int)($value['error'][$index] ?? UPLOAD_ERR_OK) : UPLOAD_ERR_OK;
				if($error!==UPLOAD_ERR_NO_FILE && trim((string)$name)!==''){
					return false;
				}
			}
			return true;
		}
		return $value===[];
	}

	/**
	 * Compares initial and current field values for dirty-state detection.
	 *
	 * Booleans compare by boolean value, scalar/null pairs compare by string form to match request normalization, and complex values compare through a stable normalized JSON representation.
	 *
	 * @param mixed $left Initial value.
	 * @param mixed $right Current value.
	 * @return bool True when the values should be treated as equivalent.
	 */
	private static function valuesMatch(mixed $left, mixed $right): bool {
		if(is_bool($left) || is_bool($right)){
			return (bool)$left===(bool)$right;
		}
		if((is_scalar($left) || $left===null) && (is_scalar($right) || $right===null)){
			return (string)$left===(string)$right;
		}
		return self::stableValue($left)===self::stableValue($right);
	}

	/**
	 * Produces a deterministic string representation for complex dirty comparisons.
	 *
	 * @param mixed $value Value to normalize and encode.
	 * @return string JSON representation, or an empty string when encoding fails.
	 */
	private static function stableValue(mixed $value): string {
		return json_encode(self::normalizeComparableValue($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
	}

	/**
	 * Normalizes arrays and objects recursively for stable comparison.
	 *
	 * Objects are converted to public property arrays, associative arrays are key-sorted, and list order is preserved.
	 *
	 * @param mixed $value Value being normalized.
	 * @return mixed scalar value, list with preserved order, or key-sorted associative/object array.
	 */
	private static function normalizeComparableValue(mixed $value): mixed {
		if(is_object($value)){
			$value=get_object_vars($value);
		}
		if(!is_array($value)){
			return $value;
		}
		if(!array_is_list($value)){
			ksort($value);
		}
		foreach($value as $key=>$child){
			$value[$key]=self::normalizeComparableValue($child);
		}
		return $value;
	}
}
