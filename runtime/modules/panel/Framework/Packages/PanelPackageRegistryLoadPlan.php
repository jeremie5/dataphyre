<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Explicit transport-neutral loader for signed package registry indexes.
 *
 * A host supplies the transport and, for offline operation, previously persisted
 * signed bytes plus monotonic sequence/digest state. The plan never interprets
 * the opaque locator and neither construction nor serialization performs I/O.
 */
final class PanelPackageRegistryLoadPlan implements \JsonSerializable {

	private string $locator;
	private PanelPackageTransport $transport;
	private PanelPackageSignatureVerifier $verifier;
	private PanelPackageTrustPolicy $trustPolicy;
	private array $options;

	public function __construct(
		string $locator,
		PanelPackageTransport $transport,
		PanelPackageSignatureVerifier $verifier,
		PanelPackageTrustPolicy $trustPolicy,
		array $options=[]
	) {
		$this->locator=trim($locator);
		$this->transport=$transport;
		$this->verifier=clone $verifier;
		$this->trustPolicy=clone $trustPolicy;
		$this->options=$this->options($options);
	}

	public static function make(string $locator, PanelPackageTransport $transport, PanelPackageSignatureVerifier $verifier, PanelPackageTrustPolicy $trustPolicy, array $options=[]): self {
		return new self($locator, $transport, $verifier, $trustPolicy, $options);
	}

	/** Fetches or reads host-supplied cached bytes, then verifies before release. */
	public function load(array $options=[]): PanelPackageRegistryLoadResult {
		$options=array_replace($this->options, $this->options($options));
		$offline=(bool)($options['offline'] ?? false);
		$maxBytes=max(1024, min(16777216, (int)($options['max_index_bytes'] ?? 2097152)));
		$body='';$source=$offline ? 'cache' : 'transport';$errors=[];
		if($this->locator==='' || strlen($this->locator)>2048 || str_contains($this->locator, "\0")){$errors[]='Registry transport locator is invalid.';}
		if($offline){
			$cached=$options['cached_body'] ?? null;
			if(!is_string($cached) || $cached==='' || strlen($cached)>$maxBytes){$errors[]='Offline registry loading requires bounded host-supplied signed bytes.';}
			else{$body=$cached;}
		}
		elseif($errors===[]){
			try{$response=$this->transport->fetch($this->locator, [
				'content_type'=>PanelPackageRegistryIndex::CONTENT_TYPE,'max_bytes'=>$maxBytes,
				'minimum_sequence'=>max(0, (int)($options['minimum_sequence'] ?? 0)),
			]);}
			catch(\Throwable){$response=[];}
			$body=$this->responseBody($response ?? null, $maxBytes) ?? '';
			if($body===''){$errors[]='Registry transport response failed size, status, encoding, content-type, or digest validation.';}
		}
		$index=null;
		if($errors===[]){
			$index=PanelPackageRegistryIndex::make($body, $this->verifier, $this->trustPolicy, $this->indexOptions($options, $offline));
			if(!$index->ok()){$errors[]='Fetched registry index failed signature, trust, freshness, replay, schema, or transparency verification.';}
		}
		return PanelPackageRegistryLoadResult::make($index, $body, $source, $errors, is_array($options['meta'] ?? null) ? $options['meta'] : []);
	}

	/** @return array<string,mixed> No-I/O load manifest. */
	public function toArray(): array {
		return [
			'type'=>'panel_package_registry_load_plan','ready'=>$this->locator!=='',
			'locator_digest'=>$this->locator!=='' ? hash('sha256', $this->locator) : '',
			'offline'=>(bool)($this->options['offline'] ?? false),
			'cached_body_available'=>is_string($this->options['cached_body'] ?? null) && $this->options['cached_body']!=='',
			'transport_supplied'=>true,
			'meta'=>is_array($this->options['meta'] ?? null) ? $this->sanitize($this->options['meta']) : [],
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	private function responseBody(mixed $response, int $maxBytes): ?string {
		if(!is_array($response) || ($response['ok'] ?? false)!==true){return null;}
		if(isset($response['status']) && !is_int($response['status'])){return null;}
		if(!is_string($response['content_type'] ?? null) || (isset($response['content_encoding']) && !is_string($response['content_encoding'])) || (isset($response['bytes']) && !is_int($response['bytes'])) || (isset($response['sha256']) && !is_string($response['sha256']))){return null;}
		$status=$response['status'] ?? 200;
		$body=$response['body'] ?? null;
		$type=strtolower(trim(explode(';', (string)($response['content_type'] ?? ''))[0]));
		$encoding=strtolower(trim((string)($response['content_encoding'] ?? 'identity')));
		if($status<200 || $status>=300 || !is_string($body) || $body==='' || strlen($body)>$maxBytes || $type!==PanelPackageRegistryIndex::CONTENT_TYPE || !in_array($encoding, ['', 'identity'], true)){return null;}
		if(isset($response['bytes']) && $response['bytes']!==strlen($body)){return null;}
		if(isset($response['sha256'])){
			$declared=$this->digest((string)$response['sha256']);
			if($declared==='' || !hash_equals($declared, hash('sha256', $body))){return null;}
		}
		return $body;
	}

	private function indexOptions(array $options, bool $offline): array {
		$allowed=['now','clock_skew_seconds','max_age_seconds','minimum_sequence','previous_digest','max_index_bytes','max_packages','max_package_bytes','allowed_content_types','require_transparency','transparency_verifier','require_revocation_check','revocation_checker','require_publisher_trust','publisher_trust_resolver','allowed_publisher_statuses','meta'];
		$result=array_intersect_key($options, array_fill_keys($allowed, true));
		$result['offline']=$offline;
		$result['allow_stale_cache']=$offline && (bool)($options['allow_stale_cache'] ?? false);
		return $result;
	}

	private function options(array $options): array {
		$allowed=['offline','cached_body','allow_stale_cache','now','clock_skew_seconds','max_age_seconds','minimum_sequence','previous_digest','max_index_bytes','max_packages','max_package_bytes','allowed_content_types','require_transparency','transparency_verifier','require_revocation_check','revocation_checker','require_publisher_trust','publisher_trust_resolver','allowed_publisher_statuses','meta'];
		return array_intersect_key($options, array_fill_keys($allowed, true));
	}

	private function digest(string $digest): string {
		$digest=strtolower(trim($digest));if(str_starts_with($digest, 'sha256:')){$digest=substr($digest, 7);}
		return preg_match('/^[a-f0-9]{64}$/', $digest)===1 ? $digest : '';
	}

	private function sanitize(mixed $value, string $key=''): mixed {
		if($key!=='' && $this->sensitiveKey($key)){return '[REDACTED]';}
		if(!is_array($value)){return is_object($value) ? '[OBJECT]' : $value;}
		$safe=[];foreach($value as $itemKey=>$item){$safe[$itemKey]=$this->sanitize($item, is_string($itemKey) ? $itemKey : '');}return $safe;
	}

	private function sensitiveKey(string $key): bool {
		$key=preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key;
		return preg_match('/(?:^|[_\-.])(?:secret|password|passwd|token|private[_\-.]?key|secret[_\-.]?key|seed|credential|authorization|cookie|bearer|api[_\-.]?key|access[_\-.]?key)(?:$|[_\-.])/i', $key)===1;
	}
}
