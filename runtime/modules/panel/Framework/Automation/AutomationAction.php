<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Agent-safe command descriptor with policy, planning, validation, and rollback hooks.
 */
final class AutomationAction implements \JsonSerializable {
	private string $label;
	private string $description='';
	private string $version='1';
	private string $risk='low';
	private string $confirmation='none';
	private ?string $confirmationPhrase=null;
	/** @var array<string,mixed> */
	private array $inputSchema=['type'=>'object', 'properties'=>[]];
	private bool $idempotencyRequired=true;
	private ?\Closure $handler=null;
	private ?\Closure $planner=null;
	private ?\Closure $policyResolver=null;
	private ?\Closure $validator=null;
	private ?\Closure $rollbackHandler=null;
	/** @var list<string> */
	private array $rollbackInstructions=[];
	/** @var array<string,mixed> */
	private array $metadata=[];

	private function __construct(private readonly string $name) {
		if(WorkflowState::normalize($name)===''){
			throw new \InvalidArgumentException('Automation actions require a non-empty name.');
		}
		$this->label=ucwords(str_replace('_', ' ', WorkflowState::normalize($name)));
	}

	public static function make(string $name): self { return new self(WorkflowState::normalize($name)); }

	/** Wraps an existing Panel Action without changing its runtime contract. @param array<string,mixed> $options */
	public static function fromPanelAction(Action $action, array $options=[]): self {
		$definition=$action->toArray();
		$label=is_string($definition['label'] ?? null) ? (string)$definition['label'] : ucwords(str_replace('_', ' ', $action->name()));
		$tone=(string)($definition['tone'] ?? 'neutral');
		$risk=in_array($tone, ['danger', 'destructive', 'critical'], true) ? 'high' : 'low';
		$automation=self::make((string)($options['name'] ?? $action->name()))
			->label((string)($options['label'] ?? $label))
			->description((string)($options['description'] ?? $definition['description'] ?? 'Panel action '.$action->name().'.'))
			->risk((string)($options['risk'] ?? $risk))
			->confirmation((string)($options['confirmation'] ?? (($definition['requires_confirmation'] ?? false) ? 'explicit' : 'none')), isset($options['confirmation_phrase']) ? (string)$options['confirmation_phrase'] : null)
			->inputSchema(is_array($options['input_schema'] ?? null) ? $options['input_schema'] : ['type'=>'object', 'properties'=>['data'=>['type'=>'object']]])
			->metadata(['source'=>'panel_action', 'panel_action'=>$action->name(), 'panel_manifest'=>$definition])
			->policy(function(array $input, WorkflowActor $actor, array $context) use($action): AutomationPolicyDecision {
				$record=$context['record'] ?? ($input['record'] ?? null);
				$resource=($context['resource'] ?? null) instanceof Resource ? $context['resource'] : null;
				$user=$context['user'] ?? $actor;
				$request=$context['request'] ?? null;
				if(!$action->can($record, $user, $resource)){
					return AutomationPolicyDecision::deny('The wrapped Panel action denied this actor.');
				}
				if($action->isDisabled($record, $user, $resource, $request)){
					return AutomationPolicyDecision::deny($action->disabledReasonFor($record, $user, $resource, $request) ?? 'The wrapped Panel action is disabled.');
				}
				return AutomationPolicyDecision::allow('The wrapped Panel action authorized this actor.');
			})
			->handle(function(array $input, array $context) use($action): mixed {
				$record=$context['record'] ?? ($input['record'] ?? null);
				$data=is_array($input['data'] ?? null) ? $input['data'] : $input;
				$resource=($context['resource'] ?? null) instanceof Resource ? $context['resource'] : null;
				return $action->execute($record, $data, $resource, ($context['run_lifecycle'] ?? true)!==false, $context['request'] ?? null);
			});
		if(isset($options['rollback']) && is_callable($options['rollback'])){
			$automation=$automation->rollbackUsing($options['rollback'], is_array($options['rollback_instructions'] ?? null) ? $options['rollback_instructions'] : []);
		}
		return $automation;
	}

	public function name(): string { return WorkflowState::normalize($this->name); }
	public function label(string $label): self { $clone=clone $this; $clone->label=trim($label) ?: $clone->label; return $clone; }
	public function description(string $description): self { $clone=clone $this; $clone->description=trim($description); return $clone; }
	public function version(string|int $version): self { $clone=clone $this; $clone->version=trim((string)$version) ?: '1'; return $clone; }

	public function risk(string $risk): self {
		$risk=WorkflowState::normalize($risk);
		$clone=clone $this;
		$clone->risk=in_array($risk, ['low','medium','high','critical'], true) ? $risk : 'low';
		return $clone;
	}

	public function confirmation(string $level, ?string $phrase=null): self {
		$level=WorkflowState::normalize($level);
		$clone=clone $this;
		$clone->confirmation=in_array($level, ['none','acknowledge','explicit','phrase','critical'], true) ? $level : 'none';
		$clone->confirmationPhrase=$phrase===null ? null : (trim($phrase) ?: null);
		return $clone;
	}

	/** @param array<string,mixed> $schema */
	public function inputSchema(array $schema): self { $clone=clone $this; $clone->inputSchema=WorkflowRecord::jsonSafe($schema); return $clone; }
	public function requiresIdempotency(bool $required=true): self { $clone=clone $this; $clone->idempotencyRequired=$required; return $clone; }
	public function handle(callable $handler): self { $clone=clone $this; $clone->handler=\Closure::fromCallable($handler); return $clone; }
	public function planUsing(callable $planner): self { $clone=clone $this; $clone->planner=\Closure::fromCallable($planner); return $clone; }
	public function policy(callable $policy): self { $clone=clone $this; $clone->policyResolver=\Closure::fromCallable($policy); return $clone; }
	public function validateUsing(callable $validator): self { $clone=clone $this; $clone->validator=\Closure::fromCallable($validator); return $clone; }

	/** @param list<string> $instructions */
	public function rollbackUsing(callable $handler, array $instructions=[]): self {
		$clone=clone $this;
		$clone->rollbackHandler=\Closure::fromCallable($handler);
		$clone->rollbackInstructions=array_values(array_filter(array_map(static fn(mixed $value): string=>trim((string)$value), $instructions)));
		return $clone;
	}

	/** @param array<string,mixed> $metadata */
	public function metadata(array $metadata): self { $clone=clone $this; $clone->metadata=array_replace($clone->metadata, WorkflowRecord::jsonSafe($metadata)); return $clone; }

	public function labelValue(): string { return $this->label; }
	public function descriptionValue(): string { return $this->description; }
	public function versionValue(): string { return $this->version; }
	public function riskLevel(): string { return $this->risk; }
	public function confirmationLevel(): string { return $this->confirmation; }
	public function confirmationPhrase(): ?string { return $this->confirmationPhrase; }
	/** @return array<string,mixed> */
	public function schema(): array { return $this->inputSchema; }
	public function idempotencyRequired(): bool { return $this->idempotencyRequired; }
	public function handler(): ?\Closure { return $this->handler; }
	public function planner(): ?\Closure { return $this->planner; }
	public function policyResolver(): ?\Closure { return $this->policyResolver; }
	public function validator(): ?\Closure { return $this->validator; }
	public function rollbackHandler(): ?\Closure { return $this->rollbackHandler; }
	/** @return list<string> */
	public function rollbackInstructions(): array { return $this->rollbackInstructions; }
	/** @return array<string,mixed> */
	public function metadataValues(): array { return $this->metadata; }

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_automation_action', 'name'=>$this->name(), 'label'=>$this->label,
			'description'=>$this->description, 'version'=>$this->version, 'risk'=>$this->risk,
			'confirmation'=>['level'=>$this->confirmation, 'phrase'=>$this->confirmationPhrase],
			'input_schema'=>$this->inputSchema, 'idempotency'=>['required'=>$this->idempotencyRequired],
			'capabilities'=>[
				'executable'=>$this->handler!==null, 'dry_run'=>true, 'custom_plan'=>$this->planner!==null,
				'policy'=>$this->policyResolver!==null, 'validation'=>$this->validator!==null || $this->inputSchema!==[],
				'rollback'=>$this->rollbackHandler!==null, 'human_approval'=>true,
			],
			'rollback_instructions'=>$this->rollbackInstructions, 'metadata'=>$this->metadata,
		];
	}
}
