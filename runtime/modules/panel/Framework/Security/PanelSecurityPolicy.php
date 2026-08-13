<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Composable, explainable Panel authorization and step-up authentication policy. */
final class PanelSecurityPolicy implements \JsonSerializable {
	private array $roles=[];
	private array $permissions=[];
	private int $mfaLevel=0;
	private bool $trustedSession=false;
	private bool $forbidImpersonation=false;
	private ?string $tenantId=null;
	private bool $tenantRequired=false;
	private array $attributeRules=[];
	private $resolver=null;

	private function __construct(private readonly string $ability) {
		if(trim($ability)===''){ throw new \InvalidArgumentException('A security policy ability is required.'); }
	}
	public static function make(string $ability): self { return new self(trim($ability)); }
	public function roles(array|string $roles): self { $clone=clone $this; $clone->roles=self::names($roles); return $clone; }
	public function permissions(array|string $permissions): self { $clone=clone $this; $clone->permissions=self::names($permissions); return $clone; }
	public function mfa(int $level=1): self { $clone=clone $this; $clone->mfaLevel=max(0, min(3, $level)); return $clone; }
	public function trustedSession(bool $required=true): self { $clone=clone $this; $clone->trustedSession=$required; return $clone; }
	public function forbidImpersonation(bool $forbid=true): self { $clone=clone $this; $clone->forbidImpersonation=$forbid; return $clone; }
	public function tenant(?string $tenantId=null, bool $required=true): self { $clone=clone $this; $clone->tenantId=$tenantId!==null ? trim($tenantId) ?: null : null; $clone->tenantRequired=$required; return $clone; }
	public function attribute(string $name, mixed $expected=true): self { $clone=clone $this; $name=trim($name); if($name!==''){ $clone->attributeRules[$name]=$expected; } return $clone; }
	public function resolve(?callable $resolver): self { $clone=clone $this; $clone->resolver=$resolver; return $clone; }

	public function evaluate(PanelSecurityContext $context, mixed $subject=null): PanelSecurityDecision {
		$reasons=[]; $requirements=[];
		if($this->roles!==[] && !array_filter($this->roles, $context->hasRole(...))){ $reasons[]='Required role is missing.'; $requirements['roles_any']=$this->roles; }
		$missing=array_values(array_filter($this->permissions, static fn(string $permission): bool => !$context->can($permission)));
		if($missing!==[]){ $reasons[]='Required permission is missing.'; $requirements['permissions']=$missing; }
		if($context->mfaLevel()<$this->mfaLevel){ $reasons[]='Step-up authentication is required.'; $requirements['mfa_level']=$this->mfaLevel; }
		if($this->trustedSession && !$context->trustedSession()){ $reasons[]='A trusted session is required.'; $requirements['trusted_session']=true; }
		if($this->forbidImpersonation && $context->impersonating()){ $reasons[]='This operation is unavailable while impersonating.'; $requirements['direct_session']=true; }
		if($this->tenantRequired && $context->tenantId()===null){ $reasons[]='A tenant context is required.'; $requirements['tenant_required']=true; }
		if($this->tenantId!==null && $context->tenantId()!==$this->tenantId){ $reasons[]='Tenant boundary does not match.'; $requirements['tenant_id']=$this->tenantId; }
		foreach($this->attributeRules as $name=>$expected){
			$actual=$context->attribute($name);
			$valid=is_callable($expected) ? $expected($actual, $context, $subject)===true : $actual===$expected;
			if(!$valid){ $reasons[]='Security attribute requirement failed: '.$name.'.'; $requirements['attributes'][$name]=is_scalar($expected) || $expected===null ? $expected : 'custom'; }
		}
		if($this->resolver!==null){
			$result=($this->resolver)($context, $subject, $this->ability);
			if($result!==true){ foreach(is_array($result) ? $result : [(string)($result ?: 'Custom policy denied the operation.')] as $reason){ $reasons[]=(string)$reason; } }
		}
		return new PanelSecurityDecision($reasons===[], $this->ability, array_values(array_unique($reasons)), $requirements, [
			'actor_id'=>$context->actorId(), 'tenant_id'=>$context->tenantId(), 'impersonating'=>$context->impersonating(), 'mfa_level'=>$context->mfaLevel(),
		]);
	}

	public function jsonSerialize(): array { return ['type'=>'security_policy', 'ability'=>$this->ability, 'roles_any'=>$this->roles, 'permissions'=>$this->permissions, 'mfa_level'=>$this->mfaLevel, 'trusted_session'=>$this->trustedSession, 'forbid_impersonation'=>$this->forbidImpersonation, 'tenant_id'=>$this->tenantId, 'tenant_required'=>$this->tenantRequired, 'attribute_rules'=>array_map(static fn(mixed $value): mixed => is_callable($value) ? 'custom' : $value, $this->attributeRules), 'custom_resolver'=>$this->resolver!==null]; }

	private static function names(array|string $names): array { return array_values(array_unique(array_filter(array_map(static fn(mixed $name): string => strtolower(trim((string)$name)), is_array($names) ? $names : [$names])))); }
}
