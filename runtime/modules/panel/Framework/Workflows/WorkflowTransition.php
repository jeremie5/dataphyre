<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Declarative, executable edge between workflow states.
 */
final class WorkflowTransition implements \JsonSerializable {
	/** @var list<string> */
	private array $from;
	/** @var list<string> */
	private array $roles=[];
	/** @var list<string> */
	private array $permissions=[];
	private ?\Closure $guard=null;
	private ?WorkflowApprovalPolicy $approval=null;
	private ?\Closure $assignmentResolver=null;
	private ?string $assignTo=null;
	/** @var list<string> */
	private array $assignmentRoles=[];
	private ?int $slaSeconds=null;
	private bool $reversible=false;
	private ?\Closure $compensator=null;
	/** @var array<string,mixed> */
	private array $metadata=[];

	/** @param list<string>|string $from */
	private function __construct(private readonly string $name, array|string $from, private readonly string $to) {
		$name=WorkflowState::normalize($name);
		$to=WorkflowState::normalize($to);
		$from=is_array($from) ? $from : [$from];
		$this->from=array_values(array_unique(array_filter(array_map(WorkflowState::normalize(...), $from))));
		if($name==='' || $to==='' || $this->from===[]){
			throw new \InvalidArgumentException('Workflow transitions require a name, source state, and target state.');
		}
	}

	public static function make(string $name, string $from, string $to): self {
		return new self(WorkflowState::normalize($name), $from, WorkflowState::normalize($to));
	}

	/** @param list<string> $from */
	public static function fromStates(string $name, array $from, string $to): self {
		return new self(WorkflowState::normalize($name), $from, WorkflowState::normalize($to));
	}

	public function name(): string { return WorkflowState::normalize($this->name); }
	/** @return list<string> */
	public function from(): array { return $this->from; }
	public function to(): string { return WorkflowState::normalize($this->to); }
	public function accepts(string $state): bool { return in_array(WorkflowState::normalize($state), $this->from, true); }

	/** @param list<string>|string $roles */
	public function roles(array|string $roles): self {
		$clone=clone $this;
		$clone->roles=self::normalizeList(is_array($roles) ? $roles : [$roles]);
		return $clone;
	}

	/** @param list<string>|string $permissions */
	public function permissions(array|string $permissions): self {
		$clone=clone $this;
		$clone->permissions=self::normalizeList(is_array($permissions) ? $permissions : [$permissions], false);
		return $clone;
	}

	public function guard(callable $guard): self {
		$clone=clone $this;
		$clone->guard=\Closure::fromCallable($guard);
		return $clone;
	}

	public function approval(WorkflowApprovalPolicy|array|null $policy): self {
		$clone=clone $this;
		$clone->approval=is_array($policy) ? WorkflowApprovalPolicy::from($policy) : $policy;
		return $clone;
	}

	/** @param list<string>|string $roles */
	public function assign(?string $actorId=null, array|string $roles=[]): self {
		$clone=clone $this;
		$clone->assignTo=$actorId===null ? null : (trim($actorId) ?: null);
		$clone->assignmentRoles=self::normalizeList(is_array($roles) ? $roles : [$roles]);
		return $clone;
	}

	public function assignUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->assignmentResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	public function sla(?int $seconds): self {
		$clone=clone $this;
		$clone->slaSeconds=$seconds===null ? null : max(1, $seconds);
		return $clone;
	}

	public function reversible(bool $enabled=true, ?callable $compensator=null): self {
		$clone=clone $this;
		$clone->reversible=$enabled;
		$clone->compensator=$compensator===null ? null : \Closure::fromCallable($compensator);
		return $clone;
	}

	public function compensateUsing(callable $compensator): self {
		return $this->reversible(true, $compensator);
	}

	/** @param array<string,mixed> $metadata */
	public function metadata(array $metadata): self {
		$clone=clone $this;
		$clone->metadata=array_replace($clone->metadata, $metadata);
		return $clone;
	}

	/** @return list<string> */
	public function requiredRoles(): array { return $this->roles; }
	/** @return list<string> */
	public function requiredPermissions(): array { return $this->permissions; }
	public function guardResolver(): ?\Closure { return $this->guard; }
	public function approvalPolicy(): ?WorkflowApprovalPolicy { return $this->approval; }
	public function assignmentResolver(): ?\Closure { return $this->assignmentResolver; }
	public function assignedActor(): ?string { return $this->assignTo; }
	/** @return list<string> */
	public function assignmentRoles(): array { return $this->assignmentRoles; }
	public function slaSeconds(): ?int { return $this->slaSeconds; }
	public function isReversible(): bool { return $this->reversible; }
	public function compensator(): ?\Closure { return $this->compensator; }
	/** @return array<string,mixed> */
	public function metadataValues(): array { return $this->metadata; }

	public function jsonSerialize(): array {
		return [
			'name'=>$this->name(), 'from'=>$this->from, 'to'=>$this->to(),
			'roles'=>$this->roles, 'permissions'=>$this->permissions,
			'guarded'=>$this->guard!==null, 'approval'=>$this->approval,
			'assignment'=>[
				'actor'=>$this->assignTo, 'roles'=>$this->assignmentRoles,
				'dynamic'=>$this->assignmentResolver!==null,
			],
			'sla_seconds'=>$this->slaSeconds,
			'reversible'=>$this->reversible,
			'has_compensation'=>$this->compensator!==null,
			'metadata'=>$this->metadata,
		];
	}

	/** @param array<int,mixed> $values @return list<string> */
	private static function normalizeList(array $values, bool $token=true): array {
		$result=[];
		foreach($values as $value){
			$value=$token ? WorkflowState::normalize((string)$value) : strtolower(trim((string)$value));
			if($value!=='' && !in_array($value, $result, true)){
				$result[]=$value;
			}
		}
		return $result;
	}
}
