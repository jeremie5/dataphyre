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
 * Versioned, signed, scope-bound Reactor component state.
 *
 * Schema v2 binds component identity, state, locked keys, a non-reversible host
 * scope fingerprint, snapshot instance id, monotonic CAS version, creation, and
 * expiry. Legacy v1 envelopes can only verify outside production under the
 * exact `allow_unscoped_debug_v1` deprecation policy.
 */
final class ReactorSnapshot implements \JsonSerializable {
	public const SCHEMA_VERSION=2;
	public const LEGACY_POLICY='allow_unscoped_debug_v1';
	private const DEFAULT_MAX_AGE_SECONDS=86400;
	private const MAX_CONFIGURED_AGE_SECONDS=2592000;
	private const MAX_CLOCK_SKEW_SECONDS=60;
	private const MAX_STATE_DEPTH=32;
	private const MAX_STATE_NODES=10000;
	private const MAX_STATE_KEY_BYTES=256;
	private const MAX_STATE_STRING_BYTES=1048576;

	/** @param array<string,mixed> $state @param list<string> $locked */
	private function __construct(
		private readonly int $schemaVersion,
		private readonly string $snapshotId,
		private readonly string $component,
		private readonly array $state,
		private readonly array $locked,
		private readonly string $scopeHash,
		private readonly int $version,
		private readonly int $createdAt,
		private readonly int $expiresAt,
		private readonly string $signature,
		private readonly bool $shapeValid=true
	){}

	/**
	 * Creates a new scoped v2 snapshot.
	 *
	 * @param ReactorSecurityContext|array<string,mixed> $securityContext Explicit trusted host scope.
	 * @param list<string> $locked
	 */
	public static function make(string $component, array $state=[], array $locked=[], ReactorSecurityContext|array $securityContext=[], int $version=0, ?string $snapshotId=null): self {
		$component=ReactorName::normalize($component);
		if($component===''){ throw new \InvalidArgumentException('A Reactor snapshot requires a component name.'); }
		$context=ReactorSecurityContext::fromTrusted($securityContext);
		if(!$context->isBound()){ throw new \InvalidArgumentException('A Reactor snapshot requires an explicit trusted host scope.'); }
		if($version<0){ throw new \InvalidArgumentException('A Reactor snapshot version cannot be negative.'); }
		$locked=self::normalizeLocked($locked);
		self::assertSerializableState($state);
		$snapshotId=$snapshotId ?? bin2hex(random_bytes(16));
		if(preg_match('/^[a-f0-9]{32}$/D', $snapshotId)!==1){ throw new \InvalidArgumentException('A Reactor snapshot id must be 32 lowercase hexadecimal characters.'); }
		$createdAt=time();
		$maxAge=self::maxAge();
		if($maxAge===false){ throw new \UnexpectedValueException('Reactor snapshot_max_age_seconds must be a positive bounded integer.'); }
		$payload=[
			'schema_version'=>self::SCHEMA_VERSION,
			'snapshot_id'=>$snapshotId,
			'component'=>$component,
			'state'=>$state,
			'locked'=>$locked,
			'scope_hash'=>$context->scopeHash(),
			'version'=>$version,
			'created_at'=>$createdAt,
			'expires_at'=>$createdAt+$maxAge,
		];
		return self::signed($payload);
	}

	/** Rehydrates v2 or legacy v1 JSON without treating it as trusted. */
	public static function from(mixed $snapshot): ?self {
		if(is_string($snapshot)){
			try{ $max=self::payloadLimit(); }
			catch(\Throwable){ return null; }
			if(strlen($snapshot)>$max){ return null; }
			try{ $decoded=json_decode($snapshot, true, 64, JSON_THROW_ON_ERROR); }
			catch(\Throwable){ return null; }
			$snapshot=$decoded;
		}
		if(!is_array($snapshot)){ return null; }
		$schema=array_key_exists('schema_version', $snapshot) ? $snapshot['schema_version'] : 1;
		if($schema===1){ return self::fromLegacy($snapshot); }
		if($schema!==self::SCHEMA_VERSION){ return self::invalid((int)(is_int($schema) ? $schema : 0)); }
		$keys=array_keys($snapshot);
		sort($keys);
		$expected=['component','created_at','expires_at','locked','schema_version','scope_hash','signature','snapshot_id','state','version'];
		sort($expected);
		if($keys!==$expected){ return null; }
		$componentRaw=$snapshot['component'] ?? null;
		$state=$snapshot['state'] ?? null;
		$locked=$snapshot['locked'] ?? null;
		$valid=is_string($componentRaw)
			&& ReactorName::normalize($componentRaw)===$componentRaw && $componentRaw!==''
			&& is_array($state) && is_array($locked) && self::lockedShape($locked)
			&& is_string($snapshot['snapshot_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', (string)$snapshot['snapshot_id'])===1
			&& is_string($snapshot['scope_hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', (string)$snapshot['scope_hash'])===1
			&& is_int($snapshot['version'] ?? null) && (int)$snapshot['version']>=0
			&& is_int($snapshot['created_at'] ?? null) && is_int($snapshot['expires_at'] ?? null)
			&& is_string($snapshot['signature'] ?? null);
		if($valid){
			try{ self::assertSerializableState($state); }
			catch(\Throwable){ $valid=false; }
		}
		return new self(
			self::SCHEMA_VERSION,
			is_string($snapshot['snapshot_id'] ?? null) ? $snapshot['snapshot_id'] : '',
			is_string($componentRaw) ? ReactorName::normalize($componentRaw) : '',
			is_array($state) ? $state : [],
			is_array($locked) ? array_values($locked) : [],
			is_string($snapshot['scope_hash'] ?? null) ? $snapshot['scope_hash'] : '',
			is_int($snapshot['version'] ?? null) ? $snapshot['version'] : -1,
			is_int($snapshot['created_at'] ?? null) ? $snapshot['created_at'] : 0,
			is_int($snapshot['expires_at'] ?? null) ? $snapshot['expires_at'] : 0,
			is_string($snapshot['signature'] ?? null) ? $snapshot['signature'] : '',
			$valid
		);
	}

	public function component(): string { return $this->component; }
	public function state(): array { return $this->state; }
	public function locked(): array { return $this->locked; }
	public function schemaVersion(): int { return $this->schemaVersion; }
	public function snapshotId(): string { return $this->snapshotId; }
	public function scopeHash(): string { return $this->scopeHash; }
	public function version(): int { return $this->version; }
	public function createdAt(): int { return $this->createdAt; }
	public function expiresAt(): int { return $this->expiresAt; }
	public function isLegacy(): bool { return $this->schemaVersion===1; }

	/** Verifies integrity, time bounds, and exact trusted host scope. */
	public function verify(ReactorSecurityContext|array|null $securityContext=null): bool {
		if(!$this->shapeValid || $this->component==='' || $this->createdAt<1){ return false; }
		$now=time();
		if($this->createdAt>$now+self::MAX_CLOCK_SKEW_SECONDS){ return false; }
		$maxAge=self::maxAge();
		if($maxAge===false){ return false; }
		if($this->schemaVersion===1){
			if(!self::legacyAllowed() || $this->createdAt<$now-$maxAge){ return false; }
			return ReactorSigner::verify($this->legacyPayload(), $this->signature);
		}
		if(!$this->verifyAuthenticity($securityContext)){ return false; }
		return $this->expiresAt>$now;
	}

	/**
	 * Verifies a v2 snapshot's shape, signature, and exact trusted scope without
	 * classifying its current expiry. Security boundaries that expose a distinct
	 * expired outcome must call this first so forged or cross-scope envelopes
	 * cannot use expiry as an oracle.
	 */
	public function verifyAuthenticity(ReactorSecurityContext|array|null $securityContext=null): bool {
		if(!$this->shapeValid || $this->schemaVersion!==self::SCHEMA_VERSION || $this->component==='' || $this->createdAt<1){ return false; }
		$now=time();
		if($this->createdAt>$now+self::MAX_CLOCK_SKEW_SECONDS){ return false; }
		$maxAge=self::maxAge();
		if($maxAge===false || $this->expiresAt<=$this->createdAt || $this->expiresAt>$this->createdAt+$maxAge){ return false; }
		try{ $context=ReactorSecurityContext::fromTrusted($securityContext); }
		catch(\Throwable){ return false; }
		if(!$context->isBound() || !ReactorSigner::verifyScopeFingerprint($context->scopeClaims(), $this->scopeHash)){ return false; }
		return ReactorSigner::verify($this->v2Payload(), $this->signature);
	}

	/** Creates the signed successor after a successful pre-hydration CAS claim. */
	public function successor(array $state, array $locked=[]): self {
		if($this->schemaVersion!==self::SCHEMA_VERSION || !$this->shapeValid){ throw new \LogicException('Only a valid v2 Reactor snapshot can create a successor.'); }
		$locked=self::normalizeLocked($locked);
		self::assertSerializableState($state);
		$createdAt=time();
		$maxAge=self::maxAge();
		if($maxAge===false){ throw new \UnexpectedValueException('Reactor snapshot_max_age_seconds must be a positive bounded integer.'); }
		return self::signed([
			'schema_version'=>self::SCHEMA_VERSION,
			'snapshot_id'=>$this->snapshotId,
			'component'=>$this->component,
			'state'=>$state,
			'locked'=>$locked,
			'scope_hash'=>$this->scopeHash,
			'version'=>$this->version+1,
			'created_at'=>$createdAt,
			'expires_at'=>$createdAt+$maxAge,
		]);
	}

	/** Metadata safe for a pre-hydration transport policy; state and ids are excluded. */
	public function verificationMetadata(): array {
		return [
			'present'=>true,
			'verified'=>true,
			'schema_version'=>$this->schemaVersion,
			'scope_bound'=>$this->schemaVersion===self::SCHEMA_VERSION && $this->scopeHash!=='',
			'version'=>$this->version,
			'created_at'=>$this->createdAt,
			'expires_at'=>$this->expiresAt,
			'legacy'=>$this->isLegacy(),
		];
	}

	/** Expiry used when atomically reserving the next version before callbacks. */
	public static function freshExpiry(): int {
		$maxAge=self::maxAge();
		if($maxAge===false){ throw new \UnexpectedValueException('Reactor snapshot_max_age_seconds must be a positive bounded integer.'); }
		return time()+$maxAge;
	}

	public function jsonSerialize(): array {
		if($this->schemaVersion===1){ return $this->legacyPayload()+['signature'=>$this->signature]; }
		return $this->v2Payload()+['signature'=>$this->signature];
	}

	/** Whether the named v1 deprecation policy is active outside production. */
	public static function legacyAllowed(): bool {
		return Reactor::config('legacy_snapshot_policy')===self::LEGACY_POLICY
			&& (ReactorSigner::manifest()['production'] ?? false)!==true;
	}

	/** Secret-free snapshot security contract for manifests and migration checks. */
	public static function manifest(): array {
		return [
			'schema_version'=>self::SCHEMA_VERSION,
			'scope_bound'=>true,
			'claims'=>['component','keyed_scope_tag','locked','created_at','expires_at','version','snapshot_id'],
			'scope_tag'=>'hmac_sha256_domain_separated_with_retained_key_verification',
			'authenticity_before_expiry_classification'=>true,
			'legacy_unscoped_policy'=>self::legacyAllowed() ? self::LEGACY_POLICY : 'disabled',
			'legacy_unscoped_allowed_in_production'=>false,
			'default_ttl_seconds'=>self::DEFAULT_MAX_AGE_SECONDS,
			'maximum_ttl_seconds'=>self::MAX_CONFIGURED_AGE_SECONDS,
			'state_tree'=>[
				'values'=>'null_boolean_integer_finite_float_utf8_string_list_or_map_only',
				'objects_allowed'=>false,
				'maximum_depth'=>self::MAX_STATE_DEPTH,
				'maximum_nodes'=>self::MAX_STATE_NODES,
				'maximum_key_bytes'=>self::MAX_STATE_KEY_BYTES,
				'maximum_string_bytes'=>self::MAX_STATE_STRING_BYTES,
			],
		];
	}

	/** @param array<string,mixed> $payload */
	private static function signed(array $payload): self {
		return new self(
			self::SCHEMA_VERSION,
			$payload['snapshot_id'],
			$payload['component'],
			$payload['state'],
			$payload['locked'],
			$payload['scope_hash'],
			$payload['version'],
			$payload['created_at'],
			$payload['expires_at'],
			ReactorSigner::sign($payload)
		);
	}

	/** @param array<string,mixed> $snapshot */
	private static function fromLegacy(array $snapshot): ?self {
		$keys=array_keys($snapshot);
		sort($keys);
		$expected=['component','created_at','locked','signature','state'];
		sort($expected);
		if($keys!==$expected){ return null; }
		$componentRaw=$snapshot['component'] ?? null;
		$state=$snapshot['state'] ?? null;
		$locked=$snapshot['locked'] ?? null;
		$valid=is_string($componentRaw) && ReactorName::normalize($componentRaw)===$componentRaw && $componentRaw!==''
			&& is_array($state) && is_array($locked) && self::lockedShape($locked)
			&& is_int($snapshot['created_at'] ?? null) && is_string($snapshot['signature'] ?? null);
		if($valid){ try{ self::assertSerializableState($state); } catch(\Throwable){ $valid=false; } }
		return new self(1, '', is_string($componentRaw) ? ReactorName::normalize($componentRaw) : '', is_array($state) ? $state : [], is_array($locked) ? array_values($locked) : [], '', 0, is_int($snapshot['created_at'] ?? null) ? $snapshot['created_at'] : 0, 0, is_string($snapshot['signature'] ?? null) ? $snapshot['signature'] : '', $valid);
	}

	private static function invalid(int $schema): self {
		return new self($schema, '', '', [], [], '', -1, 0, 0, '', false);
	}

	/** @return array<string,mixed> */
	private function v2Payload(): array {
		return [
			'schema_version'=>$this->schemaVersion,
			'snapshot_id'=>$this->snapshotId,
			'component'=>$this->component,
			'state'=>$this->state,
			'locked'=>$this->locked,
			'scope_hash'=>$this->scopeHash,
			'version'=>$this->version,
			'created_at'=>$this->createdAt,
			'expires_at'=>$this->expiresAt,
		];
	}

	/** @return array<string,mixed> */
	private function legacyPayload(): array {
		return ['component'=>$this->component,'state'=>$this->state,'locked'=>$this->locked,'created_at'=>$this->createdAt];
	}

	/** @param array<int,mixed> $locked */
	private static function lockedShape(array $locked): bool {
		if(!array_is_list($locked)){ return false; }
		foreach($locked as $key){
			if(!is_string($key) || $key==='' || strlen($key)>256 || preg_match('//u', $key)!==1 || preg_match('/[\x00-\x1F\x7F]/', $key)===1){ return false; }
		}
		return count($locked)===count(array_unique($locked));
	}

	/** @param array<int,mixed> $locked @return list<string> */
	private static function normalizeLocked(array $locked): array {
		$normalized=[];
		$seen=[];
		foreach($locked as $key){
			if(!is_string($key) && !is_int($key)){ throw new \InvalidArgumentException('Reactor locked state keys must be strings or integers.'); }
			$key=trim((string)$key);
			if($key==='' || strlen($key)>256 || preg_match('//u', $key)!==1 || preg_match('/[\x00-\x1F\x7F]/', $key)===1){ throw new \InvalidArgumentException('Reactor locked state keys must be bounded valid UTF-8 strings without control characters.'); }
			$fingerprint='key:'.$key;
			if(!isset($seen[$fingerprint])){ $seen[$fingerprint]=true; $normalized[]=$key; }
		}
		return $normalized;
	}

	private static function assertSerializableState(array $state): void {
		$max=self::payloadLimit();
		$nodes=0;
		self::assertJsonValue($state, 0, $nodes, min($max, self::MAX_STATE_STRING_BYTES));
		$encoded=json_encode($state, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
		if(strlen($encoded)>$max){ throw new \LengthException('Reactor snapshot state exceeds the configured payload limit.'); }
	}

	private static function assertJsonValue(mixed $value, int $depth, int &$nodes, int $maxStringBytes): void {
		if($depth>self::MAX_STATE_DEPTH){ throw new \LengthException('Reactor snapshot state exceeds the maximum tree depth.'); }
		if(++$nodes>self::MAX_STATE_NODES){ throw new \LengthException('Reactor snapshot state exceeds the maximum node count.'); }
		if($value===null || is_bool($value) || is_int($value)){ return; }
		if(is_float($value)){
			if(!is_finite($value)){ throw new \InvalidArgumentException('Reactor snapshot state floats must be finite.'); }
			return;
		}
		if(is_string($value)){
			if(preg_match('//u', $value)!==1){ throw new \InvalidArgumentException('Reactor snapshot state strings must be valid UTF-8.'); }
			if(strlen($value)>$maxStringBytes){ throw new \LengthException('Reactor snapshot state string exceeds the configured bound.'); }
			return;
		}
		if(is_array($value)){
			foreach($value as $key=>$item){
				if(is_string($key) && (strlen($key)>self::MAX_STATE_KEY_BYTES || preg_match('//u', $key)!==1)){
					throw new \InvalidArgumentException('Reactor snapshot state keys must be bounded valid UTF-8 strings.');
				}
				self::assertJsonValue($item, $depth+1, $nodes, $maxStringBytes);
			}
			return;
		}
		throw new \InvalidArgumentException('Reactor snapshot state accepts JSON scalar, list, and map values only.');
	}

	private static function maxAge(): int|false {
		$configured=Reactor::config('snapshot_max_age_seconds');
		if($configured===null){ return self::DEFAULT_MAX_AGE_SECONDS; }
		if(!is_int($configured) || $configured<1){ return false; }
		return min($configured, self::MAX_CONFIGURED_AGE_SECONDS);
	}

	private static function payloadLimit(): int {
		$max=Reactor::config('max_payload_bytes', 262144);
		if(!is_int($max) || $max<1 || $max>16777216){ throw new \UnexpectedValueException('Reactor max_payload_bytes must be an integer from 1 to 16777216.'); }
		return $max;
	}
}
