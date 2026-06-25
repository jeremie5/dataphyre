<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes panel actions and action groups for clients and diagnostics.
 *
 * Action manifests expose presentation, availability, interaction, form,
 * effects, lifecycle, permission, and state metadata without executing the
 * action handler. They are safe to build for command palettes, manifests,
 * and runtime UI hydration.
 */
final class ActionManifest {

	/**
	 * Stores the action source and contextual state used during manifestation.
	 *
	 * @param Action|ActionGroup $action Action source to describe.
	 * @param mixed $record Current record for dynamic state resolution.
	 * @param ?PanelRequest $request Current request context.
	 * @param ?Resource $resource Owning resource for permission naming.
	 * @param string $mode Action mode.
	 * @param array<string,mixed> $meta Additional manifest metadata.
	 */
	private function __construct(
		private readonly Action|ActionGroup $action,
		private readonly mixed $record=null,
		private readonly ?PanelRequest $request=null,
		private readonly ?Resource $resource=null,
		private readonly string $mode='action',
		private readonly array $meta=[]
	){}

	/**
	 * Creates a manifest builder for a single action or grouped actions.
	 *
	 * @param Action|ActionGroup $action Action source to describe.
	 * @param mixed $record Current record used by dynamic labels, state, and authorization.
	 * @param ?PanelRequest $request Current panel request context.
	 * @param ?Resource $resource Owning resource for permission naming.
	 * @param string $mode Action mode such as action, bulk, row, or relation.
	 * @param array<string,mixed> $meta Additional manifest metadata.
	 * @return self New immutable manifest builder.
	 */
	public static function from(Action|ActionGroup $action, mixed $record=null, ?PanelRequest $request=null, ?Resource $resource=null, string $mode='action', array $meta=[]): self {
		$mode=Resource::normalizeName($mode) ?: 'action';
		return new self($action, $record, $request, $resource, $mode, $meta);
	}

	/**
	 * Materializes the action or action-group manifest.
	 *
	 * @return array{type:string,kind:string,name:string} Manifest root plus action or group metadata for Panel clients.
	 */
	public function toArray(): array {
		if($this->action instanceof ActionGroup){
			return $this->groupManifest($this->action);
		}
		return $this->singleActionManifest($this->action);
	}

	/**
	 * Describes a single executable action without invoking its handler.
	 *
	 * Dynamic presentation and availability are resolved against the current
	 * record/request/resource context, while form and effect metadata are kept as
	 * declarative payloads for the client.
	 *
	 * @param Action $action Action to describe.
	 * @return array<string,mixed> Single-action manifest with presentation, availability, interaction, effects, lifecycle, form, permission, capability, state, and meta sections.
	 */
	private function singleActionManifest(Action $action): array {
		$definition=$action->toArray();
		$resolved=$action->resolvedMeta($this->record, $this->request, $this->resource);
		$state=$action->state($this->record, $this->request, $this->resource, $this->mode);
		$formManifest=$action->hasFields()
			? $action->form()->manifest($this->mode, [
				'surface'=>'action',
				'action'=>$action->name(),
				'mode'=>$this->mode,
			])
			: null;
		$effects=is_array($definition['effects'] ?? null) ? $definition['effects'] : [];
		$manifest=[
			'type'=>'action_manifest',
			'kind'=>'action',
			'name'=>$action->name(),
			'mode'=>$this->mode,
			'presentation'=>[
				'label'=>(string)($resolved['label'] ?? $definition['label'] ?? $action->name()),
				'icon'=>$resolved['icon'] ?? null,
				'tone'=>(string)($resolved['tone'] ?? $definition['tone'] ?? 'neutral'),
				'badge'=>$resolved['badge'] ?? null,
				'badge_tone'=>(string)($resolved['badge_tone'] ?? $definition['badge_tone'] ?? 'neutral'),
				'tooltip'=>$resolved['tooltip'] ?? null,
				'dynamic'=>[
					'label'=>($definition['label_dynamic'] ?? false)===true,
					'icon'=>($definition['icon_dynamic'] ?? false)===true,
					'tone'=>($definition['tone_dynamic'] ?? false)===true,
					'badge'=>($definition['badge_dynamic'] ?? false)===true,
					'tooltip'=>($definition['tooltip_dynamic'] ?? false)===true,
					'attributes'=>($definition['extra_attributes_dynamic'] ?? false)===true,
				],
			],
			'availability'=>[
				'authorized'=>(bool)($state->meta()['authorized'] ?? true),
				'visible'=>(bool)($state->meta()['visible'] ?? true),
				'disabled'=>(bool)($state->meta()['disabled'] ?? false),
				'disabled_reason'=>$state->meta()['disabled_reason'] ?? null,
			],
			'interaction'=>[
				'requires_confirmation'=>($definition['requires_confirmation'] ?? false)===true,
				'has_form'=>$action->hasFields(),
				'has_modal_content'=>($definition['has_modal_content'] ?? false)===true,
				'modal'=>($definition['modal'] ?? false)===true,
				'modal_heading'=>$resolved['modal_heading'] ?? $definition['modal_heading'] ?? null,
				'modal_description'=>$definition['modal_description'] ?? null,
				'modal_submit_label'=>$resolved['modal_submit_label'] ?? $definition['modal_submit_label'] ?? null,
				'modal_cancel_label'=>$definition['modal_cancel_label'] ?? null,
				'modal_width'=>(string)($definition['modal_width'] ?? 'md'),
				'modal_style'=>(string)($definition['meta']['modal_style'] ?? 'dialog'),
				'modal_stack'=>(string)($definition['modal_stack'] ?? 'replace'),
				'bulk'=>($definition['bulk'] ?? false)===true,
				'allow_empty_selection'=>($definition['allow_empty_selection'] ?? false)===true,
				'key_bindings'=>is_array($definition['key_bindings'] ?? null) ? $definition['key_bindings'] : [],
			],
			'effects'=>[
				'refresh'=>is_array($effects['refresh'] ?? null) ? array_values($effects['refresh']) : [],
				'refresh_count'=>is_array($effects['refresh'] ?? null) ? count($effects['refresh']) : 0,
				'events'=>is_array($effects['events'] ?? null) ? array_values($effects['events']) : [],
				'event_count'=>is_array($effects['events'] ?? null) ? count($effects['events']) : 0,
				'close_modal'=>array_key_exists('close_modal', $effects) ? (bool)$effects['close_modal'] : null,
				'success_message'=>$definition['success_message'] ?? null,
				'redirect_to'=>$definition['redirect_to'] ?? null,
			],
			'lifecycle'=>[
				'has_handler'=>($definition['has_handler'] ?? false)===true,
				'authorizes'=>($definition['authorizes'] ?? false)===true,
				'has_visibility'=>($definition['has_visibility'] ?? false)===true,
				'disables'=>($definition['disables'] ?? false)===true,
				'mutates_data'=>($definition['mutates_data'] ?? false)===true,
				'before_validate'=>($definition['lifecycle']['before_validate'] ?? false)===true,
				'after_validate'=>($definition['lifecycle']['after_validate'] ?? false)===true,
				'before_action'=>(int)($definition['lifecycle']['before_action'] ?? 0),
				'after_action'=>(int)($definition['lifecycle']['after_action'] ?? 0),
				'failure_hooks'=>(int)($definition['failure_hooks'] ?? 0),
			],
			'form'=>$formManifest,
			'permission'=>$this->permissionManifest('action', $action->name()),
			'capabilities'=>self::capabilities($definition, $formManifest, $effects),
			'state'=>$state->jsonSerialize(),
			'meta'=>array_replace(is_array($definition['meta'] ?? null) ? $definition['meta'] : [], $this->meta),
		];
		PanelTrace::record('action.manifest.described', [
			'name'=>$manifest['name'],
			'mode'=>$manifest['mode'],
			'has_form'=>$manifest['interaction']['has_form'],
			'modal'=>$manifest['interaction']['modal'],
			'effect_count'=>$manifest['effects']['refresh_count'] + $manifest['effects']['event_count'],
		]);
		return $manifest;
	}

	/**
	 * Describes a group of actions and aggregates group-level capabilities.
	 *
	 * @param ActionGroup $group Group to describe.
	 * @return array<string,mixed> Action-group manifest containing child action manifests and aggregate capability counts.
	 */
	private function groupManifest(ActionGroup $group): array {
		$definition=$group->toArray();
		$actions=[];
		foreach($group->actionsList() as $action){
			$actions[]=$this->singleActionManifest($action);
		}
		$manifest=[
			'type'=>'action_manifest',
			'kind'=>'action_group',
			'name'=>(string)($definition['name'] ?? ''),
			'label'=>(string)($definition['label'] ?? 'Actions'),
			'icon'=>$definition['icon'] ?? null,
			'tone'=>(string)($definition['tone'] ?? 'neutral'),
			'action_count'=>count($actions),
			'actions'=>$actions,
			'permission'=>$this->permissionManifest('action_group', (string)($definition['name'] ?? '')),
			'capabilities'=>[
				'forms'=>count(array_filter($actions, static fn(array $item): bool => (bool)($item['interaction']['has_form'] ?? false))),
				'modals'=>count(array_filter($actions, static fn(array $item): bool => (bool)($item['interaction']['modal'] ?? false))),
				'confirmations'=>count(array_filter($actions, static fn(array $item): bool => (bool)($item['interaction']['requires_confirmation'] ?? false))),
				'effects'=>array_sum(array_map(static fn(array $item): int => (int)($item['effects']['refresh_count'] ?? 0) + (int)($item['effects']['event_count'] ?? 0), $actions)),
				'bulk_actions'=>count(array_filter($actions, static fn(array $item): bool => (bool)($item['interaction']['bulk'] ?? false))),
				'permission'=>[
					'total'=>count(array_filter($actions, static fn(array $item): bool => is_string($item['permission']['permission'] ?? null) && trim((string)$item['permission']['permission'])!=='')),
				],
			],
			'meta'=>array_replace(is_array($definition['meta'] ?? null) ? $definition['meta'] : [], $this->meta),
		];
		PanelTrace::record('action_group.manifest.described', [
			'name'=>$manifest['name'],
			'action_count'=>$manifest['action_count'],
			'capabilities'=>$manifest['capabilities'],
		]);
		return $manifest;
	}

	/**
	 * Summarizes UI, form, effect, and lifecycle capabilities for one action.
	 *
	 * @param array<string,mixed> $definition Action definition array.
	 * @param ?array<string,mixed> $formManifest Optional form manifest generated by the action form.
	 * @param array<string,mixed> $effects Declarative action effects.
	 * @return array{ui:array<string,bool>,form:array<string,int|bool>,effects:array<string,int|bool>,lifecycle:array<string,bool>} Capability summary payload.
	 */
	private static function capabilities(array $definition, ?array $formManifest, array $effects): array {
		$formCapabilities=is_array($formManifest['capabilities'] ?? null) ? $formManifest['capabilities'] : [];
		return [
			'ui'=>[
				'modal'=>($definition['modal'] ?? false)===true,
				'slide_over'=>(string)($definition['meta']['modal_style'] ?? '')==='slide_over',
				'stacked_modal'=>(string)($definition['modal_stack'] ?? '')==='push',
				'keyboard_shortcut'=>(is_array($definition['key_bindings'] ?? null) && $definition['key_bindings']!==[]),
				'dynamic_presentation'=>($definition['label_dynamic'] ?? false)===true || ($definition['icon_dynamic'] ?? false)===true || ($definition['tone_dynamic'] ?? false)===true || ($definition['badge_dynamic'] ?? false)===true,
			],
			'form'=>[
				'field_count'=>(int)($formManifest['field_count'] ?? 0),
				'component_count'=>(int)($formManifest['component_count'] ?? 0),
				'has_live_state'=>(bool)($formCapabilities['behavior']['has_live_state'] ?? false),
				'has_conditionals'=>(bool)($formCapabilities['behavior']['has_conditionals'] ?? false),
				'has_custom_hydration'=>(bool)($formCapabilities['behavior']['has_custom_hydration'] ?? false),
				'has_validation'=>(bool)($formCapabilities['behavior']['has_validation'] ?? false),
			],
			'effects'=>[
				'refresh_targets'=>is_array($effects['refresh'] ?? null) ? count($effects['refresh']) : 0,
				'browser_events'=>is_array($effects['events'] ?? null) ? count($effects['events']) : 0,
				'modal_control'=>array_key_exists('close_modal', $effects),
				'redirect'=>isset($definition['redirect_to']),
				'notification'=>isset($definition['success_message']),
			],
			'lifecycle'=>[
				'authorizes'=>($definition['authorizes'] ?? false)===true,
				'guards_visibility'=>($definition['has_visibility'] ?? false)===true,
				'guards_disabled_state'=>($definition['disables'] ?? false)===true,
				'mutates_data'=>($definition['mutates_data'] ?? false)===true,
				'has_hooks'=>(int)($definition['before_hooks'] ?? 0) + (int)($definition['after_hooks'] ?? 0) + (int)($definition['failure_hooks'] ?? 0) > 0,
			],
		];
	}

	/**
	 * Builds the permission metadata associated with an action surface.
	 *
	 * @param string $kind action or action_group.
	 * @param string $name Action or group machine name.
	 * @return array{type:string,kind:string,resource:string,action:string,permission:?string,super_permission:mixed} Permission names derived from the owning resource and action surface.
	 */
	private function permissionManifest(string $kind, string $name): array {
		$resource=$this->resource?->name() ?: (string)($this->meta['resource'] ?? '');
		$resource=PanelPermissionBridge::resourceName($resource);
		$name=Resource::normalizeName($name);
		$permission=$resource!=='' && $name!=='' && $kind==='action'
			? PanelPermissionBridge::actionName($resource, $name)
			: null;
		$options=PanelPermissionBridge::options();
		return [
			'type'=>'action_permission_manifest',
			'kind'=>$kind,
			'resource'=>$resource,
			'action'=>$name,
			'permission'=>$permission,
			'super_permission'=>$options['super_permission'] ?? 'panel.*',
		];
	}
}
