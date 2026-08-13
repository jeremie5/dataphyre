<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Declarative quorum, eligibility, actor-separation, and rejection policy.
 */
final class WorkflowApprovalPolicy implements \JsonSerializable {
	/** @var list<string> */
	private array $roles;
	/** @var list<string> */
	private array $permissions;

	/**
	 * @param list<string> $roles
	 * @param list<string> $permissions
	 */
	public function __construct(
		private readonly int $quorum=1,
		array $roles=[],
		array $permissions=[],
		private readonly bool $distinctActors=true,
		private readonly bool $allowRequester=false,
		private readonly int $rejectionThreshold=1,
		private readonly ?int $expiresAfterSeconds=null
	){
		if($quorum<1){
			throw new \InvalidArgumentException('Approval quorum must be at least one.');
		}
		if($rejectionThreshold<1){
			throw new \InvalidArgumentException('Approval rejection threshold must be at least one.');
		}
		$this->roles=self::normalize($roles);
		$this->permissions=self::normalize($permissions, false);
	}

	/** @param array<string,mixed> $policy */
	public static function from(array $policy): self {
		return new self(
			max(1, (int)($policy['quorum'] ?? 1)),
			is_array($policy['roles'] ?? null) ? $policy['roles'] : [],
			is_array($policy['permissions'] ?? null) ? $policy['permissions'] : [],
			($policy['distinct_actors'] ?? true)!==false,
			($policy['allow_requester'] ?? false)===true,
			max(1, (int)($policy['rejection_threshold'] ?? 1)),
			isset($policy['expires_after_seconds']) ? max(1, (int)$policy['expires_after_seconds']) : null
		);
	}

	public function quorum(): int { return $this->quorum; }
	/** @return list<string> */
	public function roles(): array { return $this->roles; }
	/** @return list<string> */
	public function permissions(): array { return $this->permissions; }
	public function distinctActors(): bool { return $this->distinctActors; }
	public function allowRequester(): bool { return $this->allowRequester; }
	public function rejectionThreshold(): int { return $this->rejectionThreshold; }
	public function expiresAfterSeconds(): ?int { return $this->expiresAfterSeconds; }

	public function eligible(WorkflowActor $actor): bool {
		return $actor->hasAnyRole($this->roles) && $actor->hasAllPermissions($this->permissions);
	}

	public function jsonSerialize(): array {
		return [
			'type'=>'workflow_approval_policy',
			'quorum'=>$this->quorum,
			'roles'=>$this->roles,
			'permissions'=>$this->permissions,
			'distinct_actors'=>$this->distinctActors,
			'allow_requester'=>$this->allowRequester,
			'rejection_threshold'=>$this->rejectionThreshold,
			'expires_after_seconds'=>$this->expiresAfterSeconds,
		];
	}

	/** @param array<int,mixed> $values @return list<string> */
	private static function normalize(array $values, bool $token=true): array {
		$result=[];
		foreach($values as $value){
			$value=strtolower(trim((string)$value));
			if($token){
				$value=trim((string)preg_replace('/[^a-z0-9_*.-]+/', '_', $value), '_');
			}
			if($value!=='' && !in_array($value, $result, true)){
				$result[]=$value;
			}
		}
		return $result;
	}
}
