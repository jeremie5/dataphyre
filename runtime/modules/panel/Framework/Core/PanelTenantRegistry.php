<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Manager-owned tenant lifecycle, membership, switching, and onboarding registry.
 *
 * The registry never writes sessions, files, databases, or billing systems.
 * Persistence, entitlement, and provisioning effects are explicit host callbacks.
 */
final class PanelTenantRegistry {

	private const MAX_TENANTS=100;
	private const ACTIVE_ENTITLEMENT_STATUSES=['active','enabled','entitled','trialing','grace'];
	/** @var array<string,PanelTenant> */
	private array $tenants=[];
	private ?\Closure $membershipResolver=null;
	private ?\Closure $authorizationResolver=null;
	private ?\Closure $activeResolver=null;
	private ?\Closure $persistenceResolver=null;
	private ?\Closure $entitlementResolver=null;
	/** @var array<string,array{apply:\Closure,rollback:?\Closure}> */
	private array $onboardingSteps=[];
	/** @var array<string,array{fingerprint:string,result:PanelTenantOnboardingResult}> */
	private array $onboardingResults=[];

	public function __construct(private readonly PanelManager $manager){}

	public function register(PanelTenant|array $tenant): PanelTenant {
		if(is_array($tenant)){
			if(PanelTenantSanitizer::tenantKey($tenant['name'] ?? null)===null){ throw new \InvalidArgumentException('Panel tenants require a safe stable name.'); }
			$tenant=PanelTenant::fromArray($tenant);
		}
		if($tenant->name()==='' || PanelTenantSanitizer::tenantKey($tenant->name())!==$tenant->name()){
			throw new \InvalidArgumentException('Panel tenants require a safe stable name.');
		}
		if(!isset($this->tenants[$tenant->name()]) && count($this->tenants)>=self::MAX_TENANTS){
			throw new \OverflowException('Panel tenant registry limit reached.');
		}
		$this->tenants[$tenant->name()]=$tenant;
		PanelTrace::record('tenant.registered', self::traceIdentity($tenant->name()));
		return $tenant;
	}

	/** @param list<PanelTenant|array<string,mixed>> $tenants @return list<PanelTenant> */
	public function registerMany(array $tenants): array {
		$registered=[];
		foreach($tenants as $tenant){
			if($tenant instanceof PanelTenant || is_array($tenant)){ $registered[]=$this->register($tenant); }
		}
		return $registered;
	}

	public function tenant(string $name): ?PanelTenant {
		$name=PanelTenantSanitizer::tenantKey($name);
		return $name!==null ? ($this->tenants[$name] ?? null) : null;
	}

	public function has(string $name): bool { return $this->tenant($name) instanceof PanelTenant; }

	/** @return array<string,PanelTenant> */
	public function all(): array { return $this->tenants; }

	public function membershipsUsing(callable $resolver): self {
		$this->membershipResolver=\Closure::fromCallable($resolver);
		return $this;
	}

	public function authorizeUsing(callable $resolver): self {
		$this->authorizationResolver=\Closure::fromCallable($resolver);
		return $this;
	}

	public function activeUsing(callable $resolver): self {
		$this->activeResolver=\Closure::fromCallable($resolver);
		return $this;
	}

	public function persistUsing(callable $resolver): self {
		$this->persistenceResolver=\Closure::fromCallable($resolver);
		return $this;
	}

	/** Optional local status/entitlement lookup. Panel never performs billing I/O. */
	public function entitlementUsing(callable $resolver): self {
		$this->entitlementResolver=\Closure::fromCallable($resolver);
		return $this;
	}

	/** @return array<string,PanelTenantMembership> */
	public function memberships(PanelRequest $request): array {
		if($this->membershipResolver===null){ return []; }
		try{
			$response=($this->membershipResolver)($request->user(), $request, $this, $this->manager);
			if($response instanceof PanelTenantMembership){ $response=[$response]; }
			elseif(is_array($response) && (isset($response['tenant']) || isset($response['tenant_key']))){ $response=[$response]; }
			if(!is_iterable($response)){
				PanelTrace::record('tenant.membership_invalid', ['response_type'=>get_debug_type($response)]);
				return [];
			}
			$memberships=[];
			$inspected=0;
			foreach($response as $key=>$value){
				if($inspected++>=self::MAX_TENANTS){
					PanelTrace::record('tenant.membership_budget_exhausted', ['limit'=>self::MAX_TENANTS]);
					break;
				}
				try{
					if($value instanceof PanelTenantMembership){ $membership=$value; }
					elseif(is_array($value)){ $membership=PanelTenantMembership::fromArray($value, is_string($key) ? $key : null); }
					elseif(is_string($value)){ $membership=PanelTenantMembership::make($value); }
					else { continue; }
				}
				catch(\Throwable $exception){
					PanelTrace::record('tenant.membership_row_invalid', ['exception'=>$exception::class]);
					continue;
				}
				if(isset($this->tenants[$membership->tenant()]) && !isset($memberships[$membership->tenant()])){
					$memberships[$membership->tenant()]=$membership;
				}
			}
			return $memberships;
		}
		catch(\Throwable $exception){
			PanelTrace::record('tenant.membership_error', ['exception'=>$exception::class]);
			return [];
		}
	}

	public function context(PanelRequest $request): PanelTenantContext {
		return $this->resolveContext($request, $this->memberships($request));
	}

	public function switch(string $tenant, PanelRequest $request): PanelTenantSwitchResult {
		$memberships=$this->memberships($request);
		$previous=$this->resolveContext($request, $memberships);
		$key=PanelTenantSanitizer::tenantKey($tenant);
		if($key===null){
			return PanelTenantSwitchResult::failure('tenant_invalid', $previous, diagnostics:[PanelTenantSanitizer::diagnostic('tenant_invalid', 'Tenant key is invalid.')]);
		}
		$next=$this->contextForKey($key, $request, $memberships, 'switch');
		if(!$next->isAuthorized()){
			PanelTrace::record('tenant.switch_denied', self::traceIdentity($key)+['code'=>$next->code()]);
			return PanelTenantSwitchResult::failure($next->code(), $previous, $next);
		}
		if($this->persistenceResolver===null){
			return PanelTenantSwitchResult::failure('persistence_unconfigured', $previous, $next, [PanelTenantSanitizer::diagnostic('persistence_unconfigured', 'Tenant persistence callback is not configured.')]);
		}
		try{
			$response=($this->persistenceResolver)($next, $previous, $request, $this, $this->manager);
			$ok=$response===true || (is_array($response) && ($response['ok'] ?? false)===true);
			$meta=is_array($response) && is_array($response['meta'] ?? null) ? $response['meta'] : [];
			if(!$ok){
				PanelTrace::record('tenant.switch_rejected', self::traceIdentity($key));
				return PanelTenantSwitchResult::failure('persistence_rejected', $previous, $next, [PanelTenantSanitizer::diagnostic('persistence_rejected', 'Tenant persistence callback rejected the switch.')], $meta);
			}
			PanelTrace::record('tenant.switched', self::traceIdentity($key)+['source'=>$next->source()]);
			return PanelTenantSwitchResult::success($previous, $next, $meta);
		}
		catch(\Throwable $exception){
			PanelTrace::record('tenant.switch_error', self::traceIdentity($key)+['exception'=>$exception::class]);
			return PanelTenantSwitchResult::failure('persistence_failed', $previous, $next, [PanelTenantSanitizer::diagnostic('persistence_failed', 'Tenant persistence callback failed.', $exception)]);
		}
	}

	/** @return list<array<string,mixed>> */
	public function switcher(PanelRequest $request): array {
		$memberships=$this->memberships($request);
		$current=$this->resolveContext($request, $memberships);
		$visible=[];
		foreach($this->tenants as $tenant){
			$context=$this->contextForKey($tenant->name(), $request, $memberships, 'switcher');
			if(!$context->isAuthorized()){ continue; }
			$data=$tenant->toArray($context->request(), $this->manager);
			$data['current']=$current->tenantKey()===$tenant->name();
			$data['authorized']=true;
			$data['entitlement']=$context->entitlement();
			$visible[]=$data;
		}
		usort($visible, static fn(array $left,array $right): int=>[(int)($left['sort'] ?? 100),(string)($left['label'] ?? '')] <=> [(int)($right['sort'] ?? 100),(string)($right['label'] ?? '')]);
		return $visible;
	}

	public function onboardingStep(string $name, callable $apply, ?callable $rollback=null): self {
		$name=Resource::normalizeName($name);
		if($name===''){ throw new \InvalidArgumentException('Tenant onboarding steps require a stable name.'); }
		$this->onboardingSteps[$name]=[
			'apply'=>\Closure::fromCallable($apply),
			'rollback'=>$rollback!==null ? \Closure::fromCallable($rollback) : null,
		];
		return $this;
	}

	public function onboard(PanelTenant|array $tenant, PanelRequest $request, string $idempotencyKey): PanelTenantOnboardingResult {
		$tenant=$tenant instanceof PanelTenant ? $tenant : PanelTenant::fromArray($tenant);
		if($tenant->name()==='' || PanelTenantSanitizer::tenantKey($tenant->name())!==$tenant->name()){
			return PanelTenantOnboardingResult::failure('tenant_invalid', $tenant);
		}
		$idempotencyKey=PanelTenantSanitizer::text($idempotencyKey, 300);
		if($idempotencyKey===''){ return PanelTenantOnboardingResult::failure('idempotency_key_required', $tenant); }
		$keyHash=hash('sha256', $idempotencyKey);
		$fingerprint=hash('sha256', json_encode([$tenant->name(), array_keys($this->onboardingSteps)], JSON_THROW_ON_ERROR));
		$cached=$this->onboardingResults[$keyHash] ?? null;
		if(is_array($cached)){
			if(!hash_equals((string)$cached['fingerprint'], $fingerprint)){
				return PanelTenantOnboardingResult::failure('idempotency_conflict', $tenant);
			}
			return $cached['result']->asReplay();
		}
		if($this->has($tenant->name())){
			$result=PanelTenantOnboardingResult::failure('tenant_exists', $tenant);
			$this->onboardingResults[$keyHash]=compact('fingerprint','result');
			return $result;
		}
		if(count($this->tenants)>=self::MAX_TENANTS){
			$result=PanelTenantOnboardingResult::failure('registry_full', $tenant);
			$this->onboardingResults[$keyHash]=compact('fingerprint','result');
			return $result;
		}
		$completed=[];
		$rolledBack=[];
		$diagnostics=[];
		$stepMeta=[];
		$failedStep=null;
		foreach($this->onboardingSteps as $name=>$step){
			try{
				$response=($step['apply'])($tenant, $request, $this, $this->manager, $idempotencyKey);
				if($response===false || (is_array($response) && ($response['ok'] ?? true)!==true)){
					$failedStep=$name;
					$diagnostics[]=PanelTenantSanitizer::diagnostic('step_rejected', 'Tenant onboarding step was rejected.', null, ['step'=>$name]);
					break;
				}
				if(is_array($response)){ $stepMeta[$name]=PanelTenantSanitizer::map($response); }
				$completed[]=$name;
			}
			catch(\Throwable $exception){
				$failedStep=$name;
				$diagnostics[]=PanelTenantSanitizer::diagnostic('step_failed', 'Tenant onboarding step failed.', $exception, ['step'=>$name]);
				break;
			}
		}
		if($failedStep!==null){
			foreach(array_reverse($completed) as $name){
				$rollback=$this->onboardingSteps[$name]['rollback'];
				if(!$rollback instanceof \Closure){
					$diagnostics[]=PanelTenantSanitizer::diagnostic('rollback_unavailable', 'Tenant onboarding compensation is not configured.', null, ['step'=>$name]);
					continue;
				}
				try{
					$response=$rollback($tenant, $request, $this, $this->manager, $stepMeta[$name] ?? [], $idempotencyKey);
					if($response===false){
						$diagnostics[]=PanelTenantSanitizer::diagnostic('rollback_rejected', 'Tenant onboarding compensation was rejected.', null, ['step'=>$name]);
						continue;
					}
					$rolledBack[]=$name;
				}
				catch(\Throwable $exception){
					$diagnostics[]=PanelTenantSanitizer::diagnostic('rollback_failed', 'Tenant onboarding compensation failed.', $exception, ['step'=>$name]);
				}
			}
			$result=PanelTenantOnboardingResult::failure('onboarding_failed', $tenant, $completed, $rolledBack, $diagnostics, ['failed_step'=>$failedStep]);
			$this->onboardingResults[$keyHash]=compact('fingerprint','result');
			PanelTrace::record('tenant.onboarding_failed', self::traceIdentity($tenant->name())+['failed_step'=>$failedStep]);
			return $result;
		}
		$this->register($tenant);
		$result=PanelTenantOnboardingResult::success($tenant, $completed, ['steps'=>$stepMeta]);
		$this->onboardingResults[$keyHash]=compact('fingerprint','result');
		PanelTrace::record('tenant.onboarded', self::traceIdentity($tenant->name())+['step_count'=>count($completed)]);
		return $result;
	}

	/** @param string|list<string> $namespace */
	public function storageScope(string $tenant, string|array $namespace=[], ?PanelRequest $request=null): PanelTenantStorageScope {
		$key=PanelTenantSanitizer::tenantKey($tenant);
		if($key===null || !$this->has($key)){ throw new \InvalidArgumentException('Tenant storage scope requires a registered tenant.'); }
		if($request!==null && !$this->contextForKey($key, $request, $this->memberships($request), 'storage')->isAuthorized()){
			throw new \DomainException('Tenant storage scope is not authorized for this request.');
		}
		return PanelTenantStorageScope::make($key, $namespace);
	}

	/** @return array<string,mixed> */
	public function describe(?PanelRequest $request=null): array {
		$visible=$request!==null ? $this->switcher($request) : [];
		return [
			'tenant_count'=>count($this->tenants),
			'tenants'=>$visible,
			'visible_tenants'=>$visible,
			'membership_resolver'=>$this->membershipResolver!==null,
			'authorization_resolver'=>$this->authorizationResolver!==null,
			'active_resolver'=>$this->activeResolver!==null,
			'persistence_callback'=>$this->persistenceResolver!==null,
			'entitlement_hook'=>$this->entitlementResolver!==null,
			'onboarding_steps'=>array_values(array_keys($this->onboardingSteps)),
			'implicit_io'=>false,
		];
	}

	/** @param array<string,PanelTenantMembership> $memberships */
	private function resolveContext(PanelRequest $request, array $memberships): PanelTenantContext {
		$key=null;
		$source='none';
		if($this->activeResolver!==null){
			try{
				$value=($this->activeResolver)($request, $memberships, $this, $this->manager);
				$key=$value instanceof PanelTenant ? $value->name() : PanelTenantSanitizer::tenantKey($value);
				$source='resolver';
				if($key===null && $value!==null && (!is_string($value) || trim($value)!=='')){
					return PanelTenantContext::inactive($request, 'active_resolution_invalid', $source);
				}
			}
			catch(\Throwable $exception){
				PanelTrace::record('tenant.active_resolution_error', ['exception'=>$exception::class]);
				return PanelTenantContext::inactive($request, 'active_resolution_failed', 'resolver');
			}
		}
		if($key===null && $request->tenantKey()!==null){
			$key=PanelTenantSanitizer::tenantKey($request->tenantKey());
			$source='request';
			if($key===null){ return PanelTenantContext::inactive($request, 'tenant_invalid', $source); }
		}
		if($key===null){
			foreach($memberships as $membership){
				if($membership->preferred() && $membership->canSwitch()){
					$key=$membership->tenant();
					$source='membership';
					break;
				}
			}
		}
		return $key!==null
			? $this->contextForKey($key, $request, $memberships, $source)
			: PanelTenantContext::inactive($request, 'tenant_unresolved', $source);
	}

	/** @param array<string,PanelTenantMembership> $memberships */
	private function contextForKey(string $key, PanelRequest $request, array $memberships, string $source): PanelTenantContext {
		$tenant=$this->tenants[$key] ?? null;
		if(!$tenant instanceof PanelTenant){ return PanelTenantContext::inactive($request, 'tenant_unknown', $source); }
		$membership=$memberships[$key] ?? null;
		if(!$membership instanceof PanelTenantMembership || !$membership->canSwitch()){
			return PanelTenantContext::inactive($request, 'membership_denied', $source);
		}
		if(!$tenant->isVisible($request->withTenant($key), $this->manager)){
			return PanelTenantContext::inactive($request, 'tenant_hidden', $source);
		}
		$ability=match($source){
			'switch', 'switcher'=>'switch',
			'storage'=>'storage',
			default=>'access',
		};
		if(!$this->authorized($tenant, $membership, $request, $ability)){
			return PanelTenantContext::inactive($request, 'tenant_denied', $source);
		}
		$entitlement=$this->entitlement($tenant, $membership, $request);
		if(($entitlement['allowed'] ?? false)!==true){
			return PanelTenantContext::inactive($request, 'entitlement_denied', $source, ['entitlement'=>$entitlement]);
		}
		return PanelTenantContext::active($request, $tenant, $membership, $source, $entitlement);
	}

	private function authorized(PanelTenant $tenant, PanelTenantMembership $membership, PanelRequest $request, string $ability): bool {
		if($this->authorizationResolver===null){ return true; }
		try{
			return (bool)($this->authorizationResolver)($request->user(), $request->withTenant($tenant->name()), $tenant, $membership, $ability, $this, $this->manager);
		}
		catch(\Throwable $exception){
			PanelTrace::record('tenant.authorization_error', self::traceIdentity($tenant->name())+['exception'=>$exception::class]);
			return false;
		}
	}

	/** @return array<string,mixed> */
	private function entitlement(PanelTenant $tenant, PanelTenantMembership $membership, PanelRequest $request): array {
		if($this->entitlementResolver===null){ return ['configured'=>false,'allowed'=>true,'status'=>'unconfigured']; }
		try{
			$response=($this->entitlementResolver)($tenant, $membership, $request->withTenant($tenant->name()), $this, $this->manager);
			if(is_bool($response)){ return ['configured'=>true,'allowed'=>$response,'status'=>$response ? 'active' : 'blocked']; }
			if(is_string($response)){
				$status=Resource::normalizeName($response) ?: 'unknown';
				return ['configured'=>true,'allowed'=>in_array($status, self::ACTIVE_ENTITLEMENT_STATUSES, true),'status'=>$status];
			}
			if(is_array($response)){
				$status=Resource::normalizeName((string)($response['status'] ?? 'unknown')) ?: 'unknown';
				$allowed=isset($response['allowed']) ? ($response['allowed']===true) : in_array($status, self::ACTIVE_ENTITLEMENT_STATUSES, true);
				return PanelTenantSanitizer::map(array_replace($response, ['configured'=>true,'allowed'=>$allowed,'status'=>$status]));
			}
			return ['configured'=>true,'allowed'=>false,'status'=>'invalid'];
		}
		catch(\Throwable $exception){
			PanelTrace::record('tenant.entitlement_error', self::traceIdentity($tenant->name())+['exception'=>$exception::class]);
			return ['configured'=>true,'allowed'=>false,'status'=>'error','exception'=>$exception::class];
		}
	}

	/** @return array{tenant_hash:string,tenant_length:int} */
	private static function traceIdentity(string $tenant): array {
		return ['tenant_hash'=>hash('sha256', $tenant), 'tenant_length'=>strlen($tenant)];
	}
}
