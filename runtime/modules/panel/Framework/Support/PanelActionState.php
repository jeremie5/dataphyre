<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable snapshot of a Panel action as it moves through form, execution, lifecycle, and response stages.
 *
 * Action state keeps the action definition, invocation mode, submitted data, optional form validation state, execution
 * result, lifecycle outcome, and UI metadata together as one JSON-safe value. Renderers and controllers can clone it with
 * updated pieces instead of mutating shared state while an action is being prepared, submitted, or reported.
 */
final class PanelActionState implements \JsonSerializable {

	/**
	 * Stores the action snapshot and execution context.
	 *
	 * @param array<string, mixed> $action Serialized Action definition.
	 * @param string $mode Normalized action mode, such as action or bulk.
	 * @param ?PanelFormState $formState Optional form validation state for actions with fields.
	 * @param array<string, mixed> $data Submitted or prepared action data.
	 * @param mixed $result Raw execution result, compacted only during serialization.
	 * @param ?PanelLifecycleResult $lifecycle Optional lifecycle outcome attached by action dispatch.
	 * @param array<string, mixed> $meta UI and dispatch metadata such as stage, record_key, or selected_count.
	 */
	public function __construct(
		private readonly array $action=[],
		private readonly string $mode='action',
		private readonly ?PanelFormState $formState=null,
		private readonly array $data=[],
		private readonly mixed $result=null,
		private readonly ?PanelLifecycleResult $lifecycle=null,
		private readonly array $meta=[]
	){}

	/**
	 * Creates an action state from a live Action object.
	 *
	 * The Action is captured through toArray() so later changes to the original builder do not change this state snapshot.
	 *
	 * @param Action $action Action definition to snapshot.
	 * @param string $mode Invocation mode, normalized through Resource::normalizeName().
	 * @param ?PanelFormState $formState Optional form state associated with the action.
	 * @param array<string, mixed> $data Submitted or prepared action data.
	 * @param mixed $result Optional execution result.
	 * @param ?PanelLifecycleResult $lifecycle Optional lifecycle result.
	 * @param array<string, mixed> $meta Additional dispatch or rendering metadata.
	 * @return self New immutable action state snapshot.
	 */
	public static function make(
		Action $action,
		string $mode='action',
		?PanelFormState $formState=null,
		array $data=[],
		mixed $result=null,
		?PanelLifecycleResult $lifecycle=null,
		array $meta=[]
	): self {
		return new self($action->toArray(), Resource::normalizeName($mode) ?: 'action', $formState, $data, $result, $lifecycle, $meta);
	}

	/**
	 * Returns the serialized action definition captured by this state.
	 *
	 * @return array<string, mixed> Action payload from Action::toArray().
	 */
	public function action(): array {
		return $this->action;
	}

	/**
	 * Returns the stable action identifier used by renderers and dispatchers.
	 *
	 * @return string Action name, or an empty string when absent from the snapshot.
	 */
	public function actionName(): string {
		return (string)($this->action['name'] ?? '');
	}

	/**
	 * Returns the operator-facing action label.
	 *
	 * @return string Action label, falling back to the action name when no label is present.
	 */
	public function actionLabel(): string {
		return (string)($this->action['label'] ?? $this->actionName());
	}

	/**
	 * Returns the normalized invocation mode.
	 *
	 * @return string Action mode such as action or bulk.
	 */
	public function mode(): string {
		return $this->mode;
	}

	/**
	 * Returns the current UI or dispatch stage.
	 *
	 * @return string Stage metadata, defaulting to ready.
	 */
	public function stage(): string {
		return (string)($this->meta['stage'] ?? 'ready');
	}

	/**
	 * Returns the optional form validation state for this action.
	 *
	 * @return ?PanelFormState Form state attached during preparation or submission.
	 */
	public function formState(): ?PanelFormState {
		return $this->formState;
	}

	/**
	 * Returns submitted or prepared action data.
	 *
	 * @return array<string, mixed> Action data payload.
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Returns the raw action execution result.
	 *
	 * @return mixed raw handler/lifecycle result retained until jsonSerialize() compacts it for transport.
	 */
	public function result(): mixed {
		return $this->result;
	}

	/**
	 * Returns the lifecycle outcome attached to the action.
	 *
	 * @return ?PanelLifecycleResult Lifecycle result from action dispatch.
	 */
	public function lifecycle(): ?PanelLifecycleResult {
		return $this->lifecycle;
	}

	/**
	 * Returns action metadata used by renderers and dispatchers.
	 *
	 * @return array<string, mixed> Metadata map.
	 */
	public function meta(): array {
		return $this->meta;
	}

	/**
	 * Reports whether the action has form state or declared action fields.
	 *
	 * @return bool True when the action requires form rendering or validation feedback.
	 */
	public function hasForm(): bool {
		return $this->formState instanceof PanelFormState || ($this->action['fields']['fields'] ?? [])!==[];
	}

	/**
	 * Reports whether attached form state is valid.
	 *
	 * Actions without form state are considered valid because no validation failure has been recorded.
	 *
	 * @return bool True when there is no form state or the form state is valid.
	 */
	public function valid(): bool {
		return !$this->formState instanceof PanelFormState || $this->formState->valid();
	}

	/**
	 * Reports whether this state represents a bulk action.
	 *
	 * @return bool True when the action snapshot is marked bulk or the mode contains bulk.
	 */
	public function bulk(): bool {
		return ($this->action['bulk'] ?? false)===true || str_contains($this->mode, 'bulk');
	}

	/**
	 * Returns the number of selected records for bulk action display.
	 *
	 * @return ?int Non-negative selected record count, or null when not supplied.
	 */
	public function selectedCount(): ?int {
		return isset($this->meta['selected_count']) ? max(0, (int)$this->meta['selected_count']) : null;
	}

	/**
	 * Returns the single-record key associated with the action.
	 *
	 * @return ?string Non-empty scalar record key, or null for bulk/global actions.
	 */
	public function recordKey(): ?string {
		$key=$this->meta['record_key'] ?? null;
		return is_scalar($key) && trim((string)$key)!=='' ? (string)$key : null;
	}

	/**
	 * Returns a copy with a different form state.
	 *
	 * @param ?PanelFormState $formState Replacement form state.
	 * @return self Cloned action state with updated form state.
	 */
	public function withFormState(?PanelFormState $formState): self {
		return new self($this->action, $this->mode, $formState, $this->data, $this->result, $this->lifecycle, $this->meta);
	}

	/**
	 * Returns a copy with replacement action data.
	 *
	 * @param array<string, mixed> $data Replacement action data.
	 * @return self Cloned action state with updated data.
	 */
	public function withData(array $data): self {
		return new self($this->action, $this->mode, $this->formState, $data, $this->result, $this->lifecycle, $this->meta);
	}

	/**
	 * Returns a copy with a different execution result.
	 *
	 * @param mixed $result Replacement execution result.
	 * @return self Cloned action state with updated result.
	 */
	public function withResult(mixed $result): self {
		return new self($this->action, $this->mode, $this->formState, $this->data, $result, $this->lifecycle, $this->meta);
	}

	/**
	 * Returns a copy with a different lifecycle result.
	 *
	 * @param ?PanelLifecycleResult $lifecycle Replacement lifecycle result.
	 * @return self Cloned action state with updated lifecycle result.
	 */
	public function withLifecycle(?PanelLifecycleResult $lifecycle): self {
		return new self($this->action, $this->mode, $this->formState, $this->data, $this->result, $lifecycle, $this->meta);
	}

	/**
	 * Returns a copy with normalized stage metadata.
	 *
	 * @param string $stage Stage name to store under meta.stage.
	 * @return self Cloned action state with updated stage metadata.
	 */
	public function withStage(string $stage): self {
		$stage=Resource::normalizeName($stage) ?: 'ready';
		return $this->withMeta(['stage'=>$stage]);
	}

	/**
	 * Returns a copy with merged or replaced metadata.
	 *
	 * @param array<string, mixed> $meta Metadata to merge or replace.
	 * @param bool $merge True to merge over existing metadata, false to replace it.
	 * @return self Cloned action state with updated metadata.
	 */
	public function withMeta(array $meta, bool $merge=true): self {
		return new self($this->action, $this->mode, $this->formState, $this->data, $this->result, $this->lifecycle, $merge ? array_replace($this->meta, $meta) : $meta);
	}

	/**
	 * Exports a bounded JSON payload for action debugging and client responses.
	 *
	 * Large arrays, deep structures, long strings, and objects are compacted to keep Flightdeck and API responses stable
	 * even when an action result contains large records or service objects.
	 *
	 * @return array<string, mixed> Serialized action state.
	 */
	public function jsonSerialize(): array {
		return [
			'action'=>$this->action,
			'mode'=>$this->mode,
			'stage'=>$this->stage(),
			'bulk'=>$this->bulk(),
			'selected_count'=>$this->selectedCount(),
			'record_key'=>$this->recordKey(),
			'has_form'=>$this->hasForm(),
			'valid'=>$this->valid(),
			'data_keys'=>array_keys($this->data),
			'data'=>self::compactValue($this->data),
			'form_state'=>$this->formState?->jsonSerialize(),
			'lifecycle'=>$this->lifecycle?->jsonSerialize(),
			'result'=>self::compactValue($this->result),
			'meta'=>$this->meta,
		];
	}

	/**
	 * Compacts arbitrary action data or result values into a JSON-safe diagnostic shape.
	 *
	 * Known Panel value objects are serialized directly. Generic objects are represented by class name, arrays are depth
	 * and size limited, and long strings are truncated to protect debug payloads from unbounded output.
	 *
	 * @param mixed $value Value to compact.
	 * @param int $depth Current recursion depth.
	 * @return mixed scalar, bounded array/object preview, truncation marker, or debug-type label safe for JSON output.
	 */
	private static function compactValue(mixed $value, int $depth=0): mixed {
		if($depth>3){
			return is_array($value) ? ['type'=>'array', 'count'=>count($value)] : get_debug_type($value);
		}
		if($value instanceof PanelLifecycleResult || $value instanceof PanelFormState || $value instanceof PanelNotification){
			return $value->jsonSerialize();
		}
		if(is_array($value)){
			if(count($value)>30){
				return [
					'type'=>'array',
					'count'=>count($value),
					'keys'=>array_slice(array_keys($value), 0, 30),
				];
			}
			$clean=[];
			foreach($value as $key=>$item){
				$clean[$key]=self::compactValue($item, $depth+1);
			}
			return $clean;
		}
		if(is_object($value)){
			return [
				'type'=>'object',
				'class'=>$value::class,
			];
		}
		if(is_string($value) && strlen($value)>800){
			return substr($value, 0, 800).'...';
		}
		return $value;
	}
}
