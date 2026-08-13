<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Verified package acquisition output and installer handoff.
 *
 * Templates and host trust objects remain process-local. Serialization exposes
 * only a redacted audit manifest, so artifact bytes, transport locators, public
 * key material, credentials, and filesystem paths cannot leak through CI logs.
 */
final class PanelPackageAcquisitionResult implements \JsonSerializable {

	private bool $ok;
	private array $packages;
	private array $errors;
	private array $meta;
	/** @var array<string,PanelPackageTemplate> */
	private array $templates;
	/** @var array<string,\Closure> Process-local final activation checks. */
	private array $activationGates;
	private PanelPackageSignatureVerifier $verifier;
	private PanelPackageTrustPolicy $trustPolicy;

	/** @param array<string,mixed> $data Verified acquisition telemetry. */
	public function __construct(
		array $data,
		PanelPackageSignatureVerifier $verifier,
		PanelPackageTrustPolicy $trustPolicy,
		array $templates=[],
		array $activationGates=[]
	) {
		$this->verifier=clone $verifier;
		$this->trustPolicy=clone $trustPolicy;
		$this->packages=array_values(array_filter((array)($data['packages'] ?? []), 'is_array'));
		$this->errors=array_values(array_filter(array_map(static fn(mixed $error): string=>trim((string)$error), (array)($data['errors'] ?? [])), static fn(string $error): bool=>$error!==''));
		$this->meta=is_array($data['meta'] ?? null) ? $this->sanitize($data['meta']) : [];
		$this->templates=[];
		foreach($templates as $id=>$template){
			$id=Resource::normalizeName((string)$id);
			if($id!=='' && $template instanceof PanelPackageTemplate && $template->package()->id()===$id){$this->templates[$id]=$template;}
		}
		ksort($this->templates, SORT_STRING);
		$this->activationGates=[];
		foreach($activationGates as $id=>$gate){
			$id=Resource::normalizeName((string)$id);
			if($id!=='' && isset($this->templates[$id]) && is_callable($gate)){
				$this->activationGates[$id]=\Closure::fromCallable($gate);
			}
		}
		ksort($this->activationGates, SORT_STRING);
		$rows=[];
		foreach($this->packages as $row){$id=Resource::normalizeName((string)($row['package'] ?? ''));if($id!==''){$rows[$id]=true;}}
		$this->ok=(bool)($data['ok'] ?? false)
			&& $this->errors===[]
			&& $this->packages!==[]
			&& count($rows)===count($this->packages)
			&& count($this->templates)===count($this->packages)
			&& array_diff_key($rows, $this->templates)===[];
	}

	public static function make(array $data, PanelPackageSignatureVerifier $verifier, PanelPackageTrustPolicy $trustPolicy, array $templates=[], array $activationGates=[]): self {
		return new self($data, $verifier, $trustPolicy, $templates, $activationGates);
	}

	public function ok(): bool { return $this->ok; }
	/** @return array<int,string> */
	public function errors(): array { return $this->errors; }
	/** @return array<int,array<string,mixed>> */
	public function packages(): array { return $this->packages; }

	/** Returns one verified in-memory template. It is never serialized. */
	public function template(string $package): ?PanelPackageTemplate {
		return $this->ok ? ($this->templates[Resource::normalizeName($package)] ?? null) : null;
	}

	/**
	 * Creates an installer plan pinned to the same verifier and trust policy that
	 * accepted acquisition. Caller options cannot weaken those two boundaries.
	 */
	public function installPlan(string $package, string $targetPath='', array $options=[]): ?PanelPackageInstallPlan {
		$template=$this->template($package);
		if(!$template instanceof PanelPackageTemplate){return null;}
		$options['signature_verifier']=$this->verifier;
		$options['trust_policy']=$this->trustPolicy;
		if(isset($this->activationGates[$template->package()->id()])){
			$options['activation_gate']=$this->activationGates[$template->package()->id()];
		}
		return PanelPackageInstallPlan::make($template, $targetPath, $options);
	}

	/** Explicit rollback handoff for a concrete installer result. */
	public function rollbackPlan(PanelPackageApplyResult $result, array $meta=[]): PanelPackageRollbackPlan {
		return PanelPackageRollbackPlan::make($result, $meta);
	}

	/** @return array<string,mixed> Redacted CI acquisition manifest. */
	public function toArray(): array {
		return [
			'type'=>'panel_package_acquisition_result',
			'ok'=>$this->ok,
			'package_count'=>count($this->packages),
			'activation_gate_count'=>count($this->activationGates),
			'activation_gates_serialized'=>false,
			'packages'=>$this->sanitize($this->packages),
			'errors'=>$this->errors,
			'meta'=>$this->meta,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	private function sanitize(mixed $value, string $key=''): mixed {
		if($key!=='' && $this->sensitiveKey($key)){return '[REDACTED]';}
		if(!is_array($value)){return is_object($value) ? '[OBJECT]' : $value;}
		$safe=[];
		foreach($value as $itemKey=>$item){$safe[$itemKey]=$this->sanitize($item, is_string($itemKey) ? $itemKey : '');}
		return $safe;
	}

	private function sensitiveKey(string $key): bool {
		$key=preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key;
		return preg_match('/(?:^|[_\-.])(?:secret|password|passwd|token|private[_\-.]?key|secret[_\-.]?key|seed|credential|authorization|cookie|bearer|api[_\-.]?key|access[_\-.]?key)(?:$|[_\-.])/i', $key)===1;
	}
}
