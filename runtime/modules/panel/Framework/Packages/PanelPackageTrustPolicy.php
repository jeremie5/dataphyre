<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Evaluates panel package manifests against local trust and revocation rules.
 *
 * The policy class performs deterministic manifest checks for marketplace and
 * installer workflows: allowed release status, package revocation, required
 * signature metadata, revoked signature digests, publisher allowlists, key
 * allowlists, and unknown-publisher handling. It does not verify cryptographic
 * signatures itself; it validates the signature metadata already present in the
 * manifest against configured trust lists.
 */
final class PanelPackageTrustPolicy implements \JsonSerializable {

	private array $trustedPublishers=[];
	private array $trustedKeys=[];
	private array $allowedStatuses=['stable', 'preview'];
	private array $revokedPackages=[];
	private array $revokedSignatures=[];
	private bool $requireSignature=false;
	private bool $allowUnknownPublishers=true;
	private array $meta=[];

	/**
	 * Creates a trust policy and applies the provided configuration.
	*
	 * @param array<string, mixed> $policy Trust policy options and metadata.
	 */
	public function __construct(array $policy=[]) {
		$this->configure($policy);
	}

	/**
	 * Creates a configured package trust policy.
	*
	 * @param array<string, mixed> $policy Trust policy options and metadata.
	 * @return self Configured trust policy.
	 */
	public static function make(array $policy=[]): self {
		return new self($policy);
	}

	/**
	 * Applies a batch of trust policy settings.
	*
	 * Supported keys are `trusted_publishers`, `trusted_keys`,
	 * `allowed_statuses`, `revoked_packages`, `revoked_signatures`,
	 * `require_signature`, `allow_unknown_publishers`, and `meta`.
	*
	 * @param array<string, mixed> $policy Trust policy settings.
	 * @return self Current policy after applying settings.
	 */
	public function configure(array $policy): self {
		if(isset($policy['trusted_publishers'])){
			$this->trustedPublishers((array)$policy['trusted_publishers']);
		}
		if(isset($policy['trusted_keys'])){
			$this->trustedKeys((array)$policy['trusted_keys']);
		}
		if(isset($policy['allowed_statuses'])){
			$this->allowedStatuses((array)$policy['allowed_statuses']);
		}
		if(isset($policy['revoked_packages'])){
			$this->revokedPackages((array)$policy['revoked_packages']);
		}
		if(isset($policy['revoked_signatures'])){
			$this->revokedSignatures((array)$policy['revoked_signatures']);
		}
		if(array_key_exists('require_signature', $policy)){
			$this->requireSignature((bool)$policy['require_signature']);
		}
		if(array_key_exists('allow_unknown_publishers', $policy)){
			$this->allowUnknownPublishers((bool)$policy['allow_unknown_publishers']);
		}
		if(isset($policy['meta']) && is_array($policy['meta'])){
			$this->meta($policy['meta']);
		}
		return $this;
	}

	/**
	 * Adds publisher identifiers accepted by this policy.
	*
	 * Publisher names are normalized with `Resource::normalizeName()` and stored
	 * uniquely. An empty trusted-publisher list means any known publisher is
	 * accepted unless unknown publishers are disabled and the manifest has none.
	 *
	 * @param array<int, string>|string $publishers Publisher identifiers.
	 * @return self Current policy with publisher allowlist extended.
	 */
	public function trustedPublishers(array|string $publishers): self {
		foreach((array)$publishers as $publisher){
			$publisher=Resource::normalizeName((string)$publisher);
			if($publisher!=='' && !in_array($publisher, $this->trustedPublishers, true)){
				$this->trustedPublishers[]=$publisher;
			}
		}
		return $this;
	}

	/**
	 * Adds signature key identifiers accepted by this policy.
	*
	 * @param array<int, string>|string $keys Signature key or key-id values.
	 * @return self Current policy with key allowlist extended.
	 */
	public function trustedKeys(array|string $keys): self {
		foreach((array)$keys as $key){
			$key=trim((string)$key);
			if($key!=='' && !in_array($key, $this->trustedKeys, true)){
				$this->trustedKeys[]=$key;
			}
		}
		return $this;
	}

	/**
	 * Replaces the package release statuses accepted by this policy.
	*
	 * Status values are normalized and de-duplicated. The default policy accepts
	 * `stable` and `preview`.
	 *
	 * @param array<int, string> $statuses Release status names.
	 * @return self Current policy with replaced status allowlist.
	 */
	public function allowedStatuses(array $statuses): self {
		$this->allowedStatuses=[];
		foreach($statuses as $status){
			$status=Resource::normalizeName((string)$status);
			if($status!=='' && !in_array($status, $this->allowedStatuses, true)){
				$this->allowedStatuses[]=$status;
			}
		}
		return $this;
	}

	/**
	 * Adds package identifiers that must be blocked regardless of other checks.
	*
	 * @param array<int, string>|string $packages Package IDs to revoke.
	 * @return self Current policy with package revocation list extended.
	 */
	public function revokedPackages(array|string $packages): self {
		foreach((array)$packages as $package){
			$package=Resource::normalizeName((string)$package);
			if($package!=='' && !in_array($package, $this->revokedPackages, true)){
				$this->revokedPackages[]=$package;
			}
		}
		return $this;
	}

	/**
	 * Adds signature digests that must be blocked regardless of publisher or key.
	*
	 * @param array<int, string>|string $signatures Signature digests to revoke.
	 * @return self Current policy with signature revocation list extended.
	 */
	public function revokedSignatures(array|string $signatures): self {
		foreach((array)$signatures as $signature){
			$signature=trim((string)$signature);
			if($signature!=='' && !in_array($signature, $this->revokedSignatures, true)){
				$this->revokedSignatures[]=$signature;
			}
		}
		return $this;
	}

	/**
	 * Sets whether packages must include signature metadata to be trusted.
	*
	 * @param bool $require Whether signature digest, key, or publisher metadata is required.
	 * @return self Current policy with signature requirement updated.
	 */
	public function requireSignature(bool $require=true): self {
		$this->requireSignature=$require;
		return $this;
	}

	/**
	 * Sets whether unsigned or publisher-less packages may pass publisher checks.
	*
	 * @param bool $allow Whether manifests without publisher metadata are allowed.
	 * @return self Current policy with unknown-publisher behavior updated.
	 */
	public function allowUnknownPublishers(bool $allow=true): self {
		$this->allowUnknownPublishers=$allow;
		return $this;
	}

	/**
	 * Adds policy metadata for reports and diagnostics.
	*
	 * @param array<string, mixed>|string $key Metadata map or metadata key.
	 * @param mixed $value Metadata value when `$key` is a string.
	 * @return self Current policy with metadata merged.
	 */
	public function meta(array|string $key, mixed $value=null): self {
		if(is_array($key)){
			$this->meta=array_replace($this->meta, $key);
			return $this;
		}
		$key=trim($key);
		if($key!==''){
			$this->meta[$key]=$value;
		}
		return $this;
	}

	/**
	 * Evaluates one package manifest and returns check-level trust diagnostics.
	*
	 * The returned payload records the package id, final trusted/blocked status,
	 * publisher, whether signature metadata was present, and every individual
	 * check with expected and actual values.
	*
	 * @param PanelPackageManifest|array<string, mixed>|string $package Manifest object, manifest array, or package id.
	 * @return array{package:string,trusted:bool,status:string,publisher:?string,signed:bool,checks:array<int,array{name:string,ok:bool,expected:string,actual:string}>}
	 */
	public function evaluate(PanelPackageManifest|array|string $package): array {
		$package=$package instanceof PanelPackageManifest ? $package : PanelPackageManifest::from($package);
		$data=$package->toArray();
		$signature=is_array($data['signature'] ?? null) ? $data['signature'] : [];
		$support=is_array($data['support'] ?? null) ? $data['support'] : [];
		$publisher=Resource::normalizeName((string)($signature['publisher'] ?? $support['owner'] ?? $data['meta']['publisher'] ?? ''));
		$key=(string)($signature['key'] ?? $signature['key_id'] ?? '');
		$digest=(string)($signature['digest'] ?? '');
		$status=Resource::normalizeName((string)($data['status'] ?? 'stable'));
		$checks=[];
		$checks[]=[
			'name'=>'status_allowed',
			'ok'=>in_array($status, $this->allowedStatuses, true),
			'expected'=>implode(',', $this->allowedStatuses),
			'actual'=>$status,
		];
		$checks[]=[
			'name'=>'package_not_revoked',
			'ok'=>!in_array((string)$data['id'], $this->revokedPackages, true),
			'expected'=>'not_revoked',
			'actual'=>(string)$data['id'],
		];
		$checks[]=[
			'name'=>'signature_present',
			'ok'=>!$this->requireSignature || $digest!=='' || $key!=='' || $publisher!=='',
			'expected'=>$this->requireSignature ? 'required' : 'optional',
			'actual'=>($digest!=='' || $key!=='' || $publisher!=='') ? 'present' : 'missing',
		];
		$checks[]=[
			'name'=>'signature_not_revoked',
			'ok'=>$digest==='' || !in_array($digest, $this->revokedSignatures, true),
			'expected'=>'not_revoked',
			'actual'=>$digest!=='' ? $digest : 'unsigned',
		];
		$checks[]=[
			'name'=>'publisher_allowed',
			'ok'=>$publisher==='' ? $this->allowUnknownPublishers : ($this->trustedPublishers===[] || in_array($publisher, $this->trustedPublishers, true)),
			'expected'=>$this->trustedPublishers===[] ? 'any' : implode(',', $this->trustedPublishers),
			'actual'=>$publisher!=='' ? $publisher : 'unknown',
		];
		$checks[]=[
			'name'=>'key_allowed',
			'ok'=>$key==='' || $this->trustedKeys===[] || in_array($key, $this->trustedKeys, true),
			'expected'=>$this->trustedKeys===[] ? 'any' : implode(',', $this->trustedKeys),
			'actual'=>$key!=='' ? $key : 'none',
		];
		$ok=count(array_filter($checks, static fn(array $check): bool => ($check['ok'] ?? false)!==true))===0;
		return [
			'package'=>(string)$data['id'],
			'trusted'=>$ok,
			'status'=>$ok ? 'trusted' : 'blocked',
			'publisher'=>$publisher!=='' ? $publisher : null,
			'signed'=>($digest!=='' || $key!=='' || $publisher!==''),
			'checks'=>$checks,
		];
	}

	/**
	 * Evaluates a repository or package list and returns an aggregate trust report.
	*
	 * @param PanelPackageRepository|array<int, PanelPackageManifest|array|string> $packages Packages to evaluate.
	 * @param array<string, mixed> $meta Report metadata merged over policy metadata.
	 * @return PanelPackageTrustReport Report with results, summary counts, policy snapshot, and metadata.
	 */
	public function report(PanelPackageRepository|array $packages, array $meta=[]): PanelPackageTrustReport {
		$list=$packages instanceof PanelPackageRepository ? $packages->packages() : $packages;
		$results=[];
		foreach($list as $package){
			$results[]=$this->evaluate($package);
		}
		$summary=[
			'total'=>count($results),
			'trusted'=>count(array_filter($results, static fn(array $result): bool => ($result['trusted'] ?? false)===true)),
			'blocked'=>count(array_filter($results, static fn(array $result): bool => ($result['trusted'] ?? false)!==true)),
			'signed'=>count(array_filter($results, static fn(array $result): bool => ($result['signed'] ?? false)===true)),
		];
		return new PanelPackageTrustReport($results, $summary, $this->toArray(), array_replace($this->meta, $meta));
	}

	/**
	 * Exports the current trust policy configuration.
	 *
	 * @return array{type:string,require_signature:bool,allow_unknown_publishers:bool,trusted_publishers:array,trusted_keys:array,allowed_statuses:array,revoked_packages:array,revoked_signatures:array,meta:array}
	 */
	public function toArray(): array {
		return [
			'type'=>'panel_package_trust_policy',
			'require_signature'=>$this->requireSignature,
			'allow_unknown_publishers'=>$this->allowUnknownPublishers,
			'trusted_publishers'=>$this->trustedPublishers,
			'trusted_keys'=>$this->trustedKeys,
			'allowed_statuses'=>$this->allowedStatuses,
			'revoked_packages'=>$this->revokedPackages,
			'revoked_signatures'=>$this->revokedSignatures,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Serializes the trust policy for JSON responses and shape discovery.
	 *
	 * @return array{type:string,require_signature:bool,allow_unknown_publishers:bool,trusted_publishers:array,trusted_keys:array,allowed_statuses:array,revoked_packages:array,revoked_signatures:array,meta:array}
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
