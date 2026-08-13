<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Trusted host security context for Reactor transport and snapshot binding.
 *
 * This value is never populated from Reactor request headers or body fields.
 * A controller, middleware boundary, or configured host resolver must create it
 * from already-authenticated server state. Only canonical scope claims are used
 * for snapshot binding; the remaining attributes stay server-side and are made
 * available to transport policy callbacks.
 */
final class ReactorSecurityContext {
	private const MAX_SCOPE_VALUE_BYTES=256;
	private const MAX_CORRELATION_BYTES=128;

	/**
	 * @param array<string,mixed> $attributes Trusted host attributes.
	 * @param array<string,string> $scopeClaims Canonical non-secret scope claims.
	 */
	private function __construct(
		private readonly array $attributes,
		private readonly array $scopeClaims,
		private readonly string $correlationId
	){}

	/**
	 * Creates a trusted context from an explicit host-owned array.
	 *
	 * A scope is usable when it supplies an explicit `scope_id` or `audience`, or
	 * the complete tenant/principal/session tuple. Any supplied canonical claim is
	 * included in the scope hash, so adding a principal or session always narrows
	 * rather than broadens a snapshot's replay boundary.
	 *
	 * @param self|array<string,mixed>|null $context Host context, existing value, or empty context.
	 */
	public static function fromTrusted(self|array|null $context=null): self {
		if($context instanceof self){ return $context; }
		$attributes=$context ?? [];
		$claims=[];
		foreach(['scope_id','audience','tenant_id','principal_id','session_id'] as $name){
			if(!array_key_exists($name, $attributes) || $attributes[$name]===null || $attributes[$name]===''){ continue; }
			$claims[$name]=self::scopeValue($attributes[$name], $name);
		}
		$correlation=self::correlationValue($attributes['correlation_id'] ?? '');
		return new self($attributes, $claims, $correlation);
	}

	/** Creates a narrow host-defined audience for trusted internal/test dispatch. */
	public static function forAudience(string $audience, array $attributes=[]): self {
		$attributes['audience']=$audience;
		return self::fromTrusted($attributes);
	}

	/** Whether the context has enough host identity to bind a snapshot. */
	public function isBound(): bool {
		if(isset($this->scopeClaims['scope_id']) || isset($this->scopeClaims['audience'])){ return true; }
		return isset($this->scopeClaims['tenant_id'], $this->scopeClaims['principal_id'], $this->scopeClaims['session_id']);
	}

	/**
	 * Returns the deterministic keyed snapshot scope fingerprint.
	 *
	 * @throws \LogicException when the host context is not scope-bound.
	 */
	public function scopeHash(): string {
		if(!$this->isBound()){ throw new \LogicException('Reactor host security context is not scope-bound.'); }
		return ReactorSigner::scopeFingerprint($this->scopeClaims);
	}

	/** Canonical claims used only for keyed scope-tag verification. */
	public function scopeClaims(): array {
		return $this->scopeClaims;
	}

	/** Returns one trusted server-side attribute without serializing the context. */
	public function get(string $name, mixed $default=null): mixed {
		return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
	}

	/** Returns trusted server-side attributes for a host transport policy. */
	public function attributes(): array {
		return $this->attributes;
	}

	/** Correlation id safe for public denial envelopes and logs. */
	public function correlationId(): string {
		return $this->correlationId;
	}

	/** Secret-free capability facts suitable for manifests and traces. */
	public function publicMetadata(): array {
		return [
			'bound'=>$this->isBound(),
			'has_scope_id'=>isset($this->scopeClaims['scope_id']),
			'has_audience'=>isset($this->scopeClaims['audience']),
			'has_tenant'=>isset($this->scopeClaims['tenant_id']),
			'has_principal'=>isset($this->scopeClaims['principal_id']),
			'has_session'=>isset($this->scopeClaims['session_id']),
		];
	}

	private static function scopeValue(mixed $value, string $name): string {
		if(!is_string($value) && !is_int($value)){ throw new \InvalidArgumentException('Reactor '.$name.' must be a string or integer.'); }
		$value=trim((string)$value);
		if($value==='' || strlen($value)>self::MAX_SCOPE_VALUE_BYTES || str_contains($value, "\0") || preg_match('//u', $value)!==1 || preg_match('/[\x00-\x1F\x7F]/', $value)===1){
			throw new \InvalidArgumentException('Reactor '.$name.' is not a valid bounded scope value.');
		}
		return $value;
	}

	private static function correlationValue(mixed $value): string {
		if(!is_string($value) && !is_int($value)){ return ''; }
		$value=preg_replace('/[^A-Za-z0-9_.:-]/', '', (string)$value) ?? '';
		return substr($value, 0, self::MAX_CORRELATION_BYTES);
	}
}
