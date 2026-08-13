<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable result emitted by cryptographic Panel package verification.
 *
 * Verification deliberately reports every boundary decision instead of only a
 * boolean. Package installers, marketplace tooling, and audit logs can therefore
 * distinguish an unknown key, malformed signature, digest mismatch, unsupported
 * algorithm, and a genuine cryptographic failure without exposing key material.
 */
final class PanelPackageVerificationResult implements \JsonSerializable {

	private bool $ok;
	private string $package;
	private string $algorithm;
	private string $keyId;
	private string $digest;
	private int $artifactCount;
	private int $bytes;
	private array $checks;
	private array $errors;
	private array $meta;

	/**
	 * @param array<string,mixed> $data Raw verifier output.
	 */
	public function __construct(array $data=[]) {
		$this->package=(string)($data['package'] ?? '');
		$this->algorithm=(string)($data['algorithm'] ?? '');
		$this->keyId=(string)($data['key_id'] ?? '');
		$this->digest=(string)($data['digest'] ?? '');
		$this->artifactCount=max(0, (int)($data['artifact_count'] ?? 0));
		$this->bytes=max(0, (int)($data['bytes'] ?? 0));
		$this->checks=array_values(array_map(fn(array $check): array => $this->sanitize($check), array_filter((array)($data['checks'] ?? []), 'is_array')));
		$this->errors=array_values(array_filter(array_map(static fn(mixed $error): string => trim((string)$error), (array)($data['errors'] ?? [])), static fn(string $error): bool => $error!==''));
		$this->meta=is_array($data['meta'] ?? null) ? $this->sanitize($data['meta']) : [];
		$checksPass=$this->checks!==[] && array_reduce($this->checks, static fn(bool $ok, array $check): bool => $ok && (($check['ok'] ?? false)===true), true);
		$this->ok=(bool)($data['ok'] ?? false) && $this->errors===[] && $checksPass;
	}

	/** @return self Normalized verification result. */
	public static function make(array $data=[]): self {
		return new self($data);
	}

	/** @return bool Whether all structural, digest, key, and signature checks passed. */
	public function ok(): bool {
		return $this->ok;
	}

	/** @return string Verified package identifier, when available. */
	public function package(): string {
		return $this->package;
	}

	/** @return string Normalized signature algorithm. */
	public function algorithm(): string {
		return $this->algorithm;
	}

	/** @return string Configured public-key identifier. */
	public function keyId(): string {
		return $this->keyId;
	}

	/** @return string SHA-256 digest of the canonical signed payload. */
	public function digest(): string {
		return $this->digest;
	}

	/** @return array<int,array<string,mixed>> Individual verification decisions. */
	public function checks(): array {
		return $this->checks;
	}

	/** @return array<int,string> Safe human-readable verification failures. */
	public function errors(): array {
		return $this->errors;
	}

	/** @return array<string,mixed> Stable verification audit payload. */
	public function toArray(): array {
		return [
			'type'=>'panel_package_verification_result',
			'ok'=>$this->ok,
			'package'=>$this->package,
			'algorithm'=>$this->algorithm,
			'key_id'=>$this->keyId,
			'digest'=>$this->digest,
			'artifact_count'=>$this->artifactCount,
			'bytes'=>$this->bytes,
			'checks'=>$this->checks,
			'errors'=>$this->errors,
			'meta'=>$this->meta,
		];
	}

	/** @return array<string,mixed> Stable verification audit payload. */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	private function sanitize(mixed $value, string $key=''): mixed {
		if($key!=='' && preg_match('/(?:^|_)(?:secret|password|passwd|token|private_key|secret_key|credential|authorization|cookie)(?:$|_)/i', $key)===1){return '[REDACTED]';}
		if(!is_array($value)){return $value;}
		$sanitized=[];
		foreach($value as $itemKey=>$item){$sanitized[$itemKey]=$this->sanitize($item, is_string($itemKey) ? $itemKey : '');}
		return $sanitized;
	}
}
