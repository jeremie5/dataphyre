<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Request-aware policy layer around navigation intent signing and verification.
 */
final class PanelNavigationIntentManager implements \JsonSerializable {
	public const INPUT='navigation_intent';
	public const MIGRATION_SAME_PANEL='same_panel';
	public const MIGRATION_DISABLED='disabled';

	public function __construct(
		private readonly ?PanelNavigationIntentSigner $signer,
		private readonly string $panel='default',
		private readonly string $surface='default',
		private readonly string $audience='dataphyre.panel.navigation',
		private readonly string $migrationPolicy=self::MIGRATION_SAME_PANEL,
		private readonly int $ttl=900,
		private readonly string $inputName=self::INPUT,
		private readonly mixed $principalResolver=null,
		private readonly bool $enabled=true
	){
		if(!in_array($migrationPolicy, [self::MIGRATION_SAME_PANEL,self::MIGRATION_DISABLED], true)){
			throw new \InvalidArgumentException('Navigation intent migration policy must be same_panel or disabled.');
		}
		if($ttl<30 || $ttl>86400){ throw new \InvalidArgumentException('Navigation intent TTL must be between 30 seconds and one day.'); }
		if(Resource::normalizeName($inputName)===''){ throw new \InvalidArgumentException('Navigation intent input name cannot be blank.'); }
		if($principalResolver!==null && !is_callable($principalResolver)){ throw new \InvalidArgumentException('Navigation intent principal resolver must be callable.'); }
	}

	/** @param array<string,mixed> $options */
	public function issue(string $returnTarget, PanelRequest $request, array $options=[]): ?string {
		if(!$this->enabled || !$this->signer instanceof PanelNavigationIntentSigner){ return null; }
		$now=(int)($options['now'] ?? time());
		$ttl=max(30, min(86400, (int)($options['ttl'] ?? $this->ttl)));
		$parent=$this->parentContext($request, $now);
		$chain=is_array($options['chain'] ?? null) ? $options['chain'] : $parent['chain'];
		if(count($chain)>=PanelNavigationIntent::MAX_CHAIN_DEPTH){ $chain=array_slice($chain, -(PanelNavigationIntent::MAX_CHAIN_DEPTH-1)); }
		$intent=PanelNavigationIntent::make($returnTarget, [
			'audience'=>(string)($options['audience'] ?? $this->audience),
			'panel'=>(string)($options['panel'] ?? $this->panel),
			'surface'=>(string)($options['surface'] ?? $this->surface),
			'tenant_binding'=>$this->tenantBinding($request),
			'principal_binding'=>$this->principalBinding($request),
			'operation'=>(string)($options['operation'] ?? 'return'),
			'outcome'=>(string)($options['outcome'] ?? 'complete'),
			'issued_at'=>$now,
			'not_before'=>(int)($options['not_before'] ?? $now),
			'expires_at'=>(int)($options['expires_at'] ?? ($now+$ttl)),
			'nonce'=>$options['nonce'] ?? null,
			'parent'=>$options['parent'] ?? $parent['parent'],
			'chain'=>$chain,
		]);
		return $this->signer->issue($intent, $now);
	}

	/** @param array<string,mixed> $expected */
	public function verify(string $token, PanelRequest $request, array $expected=[]): PanelNavigationIntentVerification {
		if(!$this->enabled){ return PanelNavigationIntentVerification::disabled(); }
		if(!$this->signer instanceof PanelNavigationIntentSigner){ return PanelNavigationIntentVerification::rejected('missing_key'); }
		return $this->signer->verify($token, array_replace([
			'audience'=>$this->audience,
			'panel'=>$this->panel,
			'surface'=>$this->surface,
			'tenant_binding'=>$this->tenantBinding($request),
			'principal_binding'=>$this->principalBinding($request),
		], $expected));
	}

	/** @param array<string,mixed> $expected */
	public function resolve(PanelRequest $request, bool $privileged=false, bool $consume=false, array $expected=[]): PanelNavigationResolution {
		$candidate=$this->candidate($request);
		if($candidate===null){ return new PanelNavigationResolution(null, PanelNavigationIntentVerification::rejected('missing'), false); }
		$target=PanelNavigationTarget::normalize($candidate);
		if($target===null){ return new PanelNavigationResolution(null, PanelNavigationIntentVerification::rejected('target_mismatch'), $privileged && $this->configured()); }
		$token=$this->token($request);
		if(!$this->configured()){
			return new PanelNavigationResolution($target, PanelNavigationIntentVerification::disabled(), false);
		}
		if($token!==''){
			$verification=$this->verify($token, $request, array_replace($expected, ['return_target'=>$target,'consume'=>$consume]));
			return new PanelNavigationResolution($verification->valid() ? $target : null, $verification, $privileged && !$verification->valid());
		}
		if(!$privileged && $this->migrationPolicy===self::MIGRATION_SAME_PANEL && PanelNavigationTarget::samePanel($target)){
			return new PanelNavigationResolution($target, PanelNavigationIntentVerification::migration($target), false);
		}
		return new PanelNavigationResolution(null, PanelNavigationIntentVerification::rejected('missing'), $privileged);
	}

	public function configured(): bool { return $this->enabled; }
	public function canIssue(): bool { return $this->enabled && $this->signer instanceof PanelNavigationIntentSigner; }
	public function inputName(): string { return $this->inputName; }
	public function migrationPolicy(): string { return $this->migrationPolicy; }
	public function panel(): string { return $this->panel; }
	public function surface(): string { return $this->surface; }

	public function manifest(): array {
		return [
			'type'=>'panel_navigation_intent_manifest',
			'version'=>1,
			'configured'=>$this->configured(),
			'can_issue'=>$this->canIssue(),
			'audience'=>$this->audience,
			'panel'=>$this->panel,
			'surface'=>$this->surface,
			'input_name'=>$this->inputName,
			'ttl_seconds'=>$this->ttl,
			'migration_policy'=>$this->migrationPolicy,
			'principal_bound'=>true,
			'tenant_bound'=>true,
			'parent_chain_limit'=>PanelNavigationIntent::MAX_CHAIN_DEPTH,
			'target_max_bytes'=>PanelNavigationTarget::MAX_BYTES,
			'signer'=>$this->signer,
			'secrets_serialized'=>false,
		];
	}

	public function jsonSerialize(): array { return $this->manifest(); }

	private function candidate(PanelRequest $request): ?string {
		$invalid=null;
		foreach([$request->input('return_to'),$request->query('return_to')] as $candidate){
			if(!is_string($candidate) || trim($candidate)===''){ continue; }
			if(PanelNavigationTarget::normalize($candidate)!==null){ return $candidate; }
			$invalid ??=$candidate;
		}
		return $invalid;
	}

	private function token(PanelRequest $request): string {
		foreach([$request->input($this->inputName),$request->query($this->inputName)] as $token){
			if(is_string($token) && trim($token)!==''){ return trim($token); }
		}
		return '';
	}

	private function tenantBinding(PanelRequest $request): string {
		return hash('sha256', 'tenant\0'.($request->tenantKey() ?? 'guest'));
	}

	private function principalBinding(PanelRequest $request): string {
		$user=$request->user();
		if(is_callable($this->principalResolver)){
			try{ $resolved=($this->principalResolver)($user, $request); }
			catch(\Throwable){ $resolved=null; }
			if(is_scalar($resolved) && trim((string)$resolved)!==''){ return hash('sha256', 'principal\0'.trim((string)$resolved)); }
		}
		$identity=self::principalIdentity($user);
		return hash('sha256', 'principal\0'.$identity);
	}

	private static function principalIdentity(mixed $user): string {
		if(is_string($user) || is_int($user)){ return trim((string)$user)!=='' ? trim((string)$user) : 'guest'; }
		if(is_array($user)){
			foreach(['id','user_id','uuid','key','email'] as $key){ if(is_scalar($user[$key] ?? null) && trim((string)$user[$key])!==''){ return trim((string)$user[$key]); } }
			return 'guest';
		}
		if(is_object($user)){
			foreach(['id','userId','getId','uuid','key'] as $method){
				if(method_exists($user, $method)){
					try{ $value=$user->{$method}(); }catch(\Throwable){ $value=null; }
					if(is_scalar($value) && trim((string)$value)!==''){ return trim((string)$value); }
				}
			}
			foreach(['id','user_id','uuid','key'] as $property){
				try{ $value=$user->{$property} ?? null; }catch(\Throwable){ $value=null; }
				if(is_scalar($value) && trim((string)$value)!==''){ return trim((string)$value); }
			}
		}
		return 'guest';
	}

	/** @return array{parent:?string,chain:list<array<string,string>>} */
	private function parentContext(PanelRequest $request, int $now): array {
		$token=$this->token($request);
		if($token==='' || !$this->signer instanceof PanelNavigationIntentSigner){ return ['parent'=>null,'chain'=>[]]; }
		$verification=$this->verify($token, $request, ['now'=>$now,'consume'=>false]);
		$parent=$verification->intent();
		if(!$verification->valid() || !$parent instanceof PanelNavigationIntent){ return ['parent'=>null,'chain'=>[]]; }
		$chain=$parent->chain();
		$chain[]=$parent->chainEntry();
		return ['parent'=>hash('sha256', $token),'chain'=>$chain];
	}
}
