<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Builds deterministic, signed, self-verifying Panel package registry indexes.
 *
 * Package and registry private keys remain behind caller-owned signer callbacks.
 * Every package is cryptographically verified and evaluated against trust policy
 * before its bytes can enter a publication.
 */
final class PanelPackageRegistryPublisher implements \JsonSerializable {
	private readonly string $registry;
	private readonly string $publisher;
	private readonly string $keyId;
	private readonly string $algorithm;
	private readonly \Closure $signer;
	private readonly \Closure $clock;
	private readonly PanelPackageSignatureVerifier $verifier;
	private readonly PanelPackageTrustPolicy $trustPolicy;
	private readonly int $ttlSeconds;
	private readonly int $maxPackages;
	private readonly int $maxBundleBytes;

	public function __construct(
		string $registry,
		string $publisher,
		string $keyId,
		string $algorithm,
		callable $signer,
		PanelPackageSignatureVerifier $verifier,
		PanelPackageTrustPolicy $trustPolicy,
		?callable $clock=null,
		array $options=[]
	) {
		$this->registry=$this->canonicalName($registry, 'registry');
		$this->publisher=$this->canonicalName($publisher, 'publisher');
		$this->keyId=PanelOperationsGuard::identifier($keyId, 'package registry key id', 256);
		$algorithm=strtolower(trim($algorithm));
		if(!in_array($algorithm, ['ed25519','rsa-sha256','ecdsa-sha256'], true)){
			throw new \InvalidArgumentException('Package registry signature algorithm is unsupported.');
		}
		$this->algorithm=$algorithm;
		$this->signer=\Closure::fromCallable($signer);
		$this->clock=$clock!==null ? \Closure::fromCallable($clock) : static fn(): int=>time();
		$this->verifier=clone $verifier;
		$this->trustPolicy=clone $trustPolicy;
		$this->ttlSeconds=max(60, min(31536000, (int)($options['ttl_seconds'] ?? 3600)));
		$this->maxPackages=max(1, min(10000, (int)($options['max_packages'] ?? 2000)));
		$this->maxBundleBytes=max(1024, min(1073741824, (int)($options['max_bundle_bytes'] ?? 67108864)));
	}

	public static function make(
		string $registry,
		string $publisher,
		string $keyId,
		string $algorithm,
		callable $signer,
		PanelPackageSignatureVerifier $verifier,
		PanelPackageTrustPolicy $trustPolicy,
		?callable $clock=null,
		array $options=[]
	): self {
		return new self($registry, $publisher, $keyId, $algorithm, $signer, $verifier, $trustPolicy, $clock, $options);
	}

	/**
	 * @param list<PanelPackageTemplate|array{template:PanelPackageTemplate,dependencies?:array<string,string>,yanked?:bool,transparency?:array<string,mixed>,listing?:array<string,mixed>}> $packages
	 * @param callable(array<string,mixed>):string $locatorFactory
	 * @param array{generated_at?:string|int|\DateTimeInterface,expires_at?:string|int|\DateTimeInterface,transparency?:array<string,mixed>} $options
	 */
	public function publish(array $packages, int $sequence, callable $locatorFactory, array $options=[]): PanelPackageRegistryPublication {
		if($sequence<1){throw new \InvalidArgumentException('Registry publication sequence must be positive.');}
		if($packages===[] || !array_is_list($packages) || count($packages)>$this->maxPackages){
			throw new \LengthException('Registry publication requires a bounded non-empty package list.');
		}
		if(array_diff(array_keys($options), ['generated_at','expires_at','transparency'])!==[]){
			throw new \InvalidArgumentException('Registry publication options contain unsupported fields.');
		}
		$generated=$this->epoch($options['generated_at'] ?? ($this->clock)(), 'registry generation time');
		$expires=$this->epoch($options['expires_at'] ?? ($generated+$this->ttlSeconds), 'registry expiry time');
		if($expires<=$generated){throw new \InvalidArgumentException('Registry publication expiry must follow generation time.');}
		$rows=[];$seen=[];
		foreach($packages as $definition){
			$config=[];
			if($definition instanceof PanelPackageTemplate){$template=$definition;}
			elseif(is_array($definition)){
				if(array_diff(array_keys($definition), ['template','dependencies','yanked','transparency','listing'])!==[] || !(($definition['template'] ?? null) instanceof PanelPackageTemplate)){
					throw new \InvalidArgumentException('Registry package publication definition is malformed.');
				}
				$template=$definition['template'];$config=$definition;
			}
			else{throw new \InvalidArgumentException('Registry packages must be templates or template publication definitions.');}
			$manifest=$template->package()->toArray();
			$id=$this->canonicalName((string)($manifest['id'] ?? ''), 'package id');
			$version=(string)($manifest['version'] ?? '');
			if(!PanelPackageManifest::validVersion($version)){throw new \InvalidArgumentException('Registry package version must be strict semantic version metadata.');}
			$identity=$id.'@'.strtolower($version);
			if(isset($seen[$identity])){throw new \LogicException('Registry publication contains a duplicate package id and version.');}
			$seen[$identity]=true;
			$verification=$this->verifier->verify($template, ['boundary'=>'registry_publication']);
			$trust=$this->trustPolicy->evaluate($template->package());
			if(!$verification->ok() || ($trust['trusted'] ?? false)!==true){
				throw new \LogicException('Registry publication rejected an unverified or untrusted package: '.$id.'.');
			}
			$signature=is_array($manifest['signature'] ?? null) ? $manifest['signature'] : [];
			if(($signature['publisher'] ?? null)!==$this->publisher
				|| ($signature['key_id'] ?? null)!==$this->keyId
				|| ($signature['algorithm'] ?? null)!==$this->algorithm
				|| $verification->keyId()!==$this->keyId
				|| $verification->algorithm()!==$this->algorithm){
				throw new \LogicException('Registry package signature identity does not match the registry authority.');
			}
			$artifacts=$this->bundleArtifacts($template->artifacts(), $manifest);
			$bundle=self::canonicalJson([
				'format'=>PanelPackageAcquisitionPlan::FORMAT,
				'package'=>$manifest,
				'artifacts'=>$artifacts,
			]);
			$bytes=strlen($bundle);
			if($bytes>$this->maxBundleBytes){throw new \LengthException('Registry package bundle exceeds its configured byte limit.');}
			$digest=hash('sha256', $bundle);
			$locatorContext=[
				'registry'=>$this->registry,'publisher'=>$this->publisher,
				'package'=>$id,'version'=>$version,'sha256'=>$digest,'bytes'=>$bytes,
				'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE,
			];
			try{$locator=$locatorFactory($locatorContext);}
			catch(\Throwable $error){throw new \RuntimeException('Registry artifact locator factory failed.', 0, $error);}
			if(!is_string($locator) || $locator==='' || trim($locator)!==$locator || strlen($locator)>2048 || str_contains($locator, "\0")){
				throw new \InvalidArgumentException('Registry artifact locator factory returned an invalid locator.');
			}
			$dependencies=$config['dependencies'] ?? [];
			if(!is_array($dependencies)){throw new \InvalidArgumentException('Registry package dependencies must be a map.');}
			$transparency=$config['transparency'] ?? [];
			if(!is_array($transparency)){throw new \InvalidArgumentException('Registry package transparency evidence must be a map.');}
			$listing=$this->listing($manifest, $config['listing'] ?? []);
			$entry=[
				'id'=>$id,'version'=>$version,'status'=>(string)($manifest['status'] ?? 'stable'),
				'publisher'=>$this->publisher,'key_id'=>$this->keyId,
				'dependencies'=>$dependencies,
				'requirements'=>is_array($manifest['requirements'] ?? null) ? $manifest['requirements'] : [],
				'yanked'=>($config['yanked'] ?? false)===true,
				'artifact'=>[
					'locator'=>$locator,'sha256'=>$digest,'bytes'=>$bytes,
					'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE,
				],
				'transparency'=>$transparency,
				'listing'=>$listing,
			];
			$rows[]=['entry'=>$entry,'artifact'=>[
				'id'=>$id,'version'=>$version,'locator'=>$locator,'sha256'=>$digest,'bytes'=>$bytes,
				'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE,'body'=>$bundle,
			]];
		}
		usort($rows, static function(array $left, array $right): int {
			$id=strcmp((string)$left['entry']['id'], (string)$right['entry']['id']);
			if($id!==0){return $id;}
			return PanelPackageManifest::compareVersions((string)$right['entry']['version'], (string)$left['entry']['version']);
		});
		$entries=array_column($rows, 'entry');
		$artifacts=[];
		foreach($rows as $row){$artifacts[$row['artifact']['sha256']]=$row['artifact'];}
		ksort($artifacts, SORT_STRING);
		$index=[
			'format'=>PanelPackageRegistryIndex::FORMAT,
			'registry'=>$this->registry,
			'publisher'=>$this->publisher,
			'sequence'=>$sequence,
			'generated_at'=>gmdate('Y-m-d\TH:i:s\Z', $generated),
			'expires_at'=>gmdate('Y-m-d\TH:i:s\Z', $expires),
			'packages'=>$entries,
			'transparency'=>is_array($options['transparency'] ?? null) ? $options['transparency'] : [],
		];
		$payload=PanelPackageRegistryIndex::signaturePayload($index, $this->verifier);
		try{$signature=($this->signer)($payload, $this->keyId, $this->algorithm, ['registry'=>$this->registry,'publisher'=>$this->publisher,'sequence'=>$sequence]);}
		catch(\Throwable $error){throw new \RuntimeException('Registry index signer failed.', 0, $error);}
		if(!is_string($signature) || $signature==='' || trim($signature)!==$signature || strlen($signature)>131072){
			throw new \UnexpectedValueException('Registry index signer returned an invalid detached signature.');
		}
		$index['signature']=[
			'algorithm'=>$this->algorithm,'key_id'=>$this->keyId,'publisher'=>$this->publisher,
			'digest'=>hash('sha256', $payload),'signature'=>$signature,
		];
		$body=self::canonicalJson($index);
		$verified=PanelPackageRegistryIndex::make($body, $this->verifier, $this->trustPolicy, [
			'now'=>$generated,'max_age_seconds'=>max(60, $expires-$generated),
			'max_index_bytes'=>16777216,'max_packages'=>$this->maxPackages,'max_package_bytes'=>$this->maxBundleBytes,
		]);
		if(!$verified->ok()){throw new \LogicException('Generated registry index did not self-verify: '.implode(' ', $verified->errors()));}
		return new PanelPackageRegistryPublication($index, $body, $artifacts);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'type'=>'panel_package_registry_publisher',
			'format'=>PanelPackageRegistryIndex::FORMAT,
			'registry'=>$this->registry,'publisher'=>$this->publisher,
			'algorithm'=>$this->algorithm,'key_id'=>$this->keyId,
			'ttl_seconds'=>$this->ttlSeconds,'max_packages'=>$this->maxPackages,
			'max_bundle_bytes'=>$this->maxBundleBytes,
			'signer_supplied'=>true,'signer_serialized'=>false,
			'verifier'=>$this->verifier->toArray(),
			'trust_policy'=>$this->trustPolicy->toArray(),
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {return $this->toArray();}

	/** @param iterable<mixed> $templateArtifacts @param array<string,mixed> $manifest @return list<array<string,mixed>> */
	private function bundleArtifacts(iterable $templateArtifacts, array $manifest): array {
		$artifacts=[];$manifestFound=false;
		foreach($templateArtifacts as $artifact){
			if(!is_array($artifact) || !is_string($artifact['path'] ?? null) || !is_string($artifact['contents'] ?? null)){
				throw new \InvalidArgumentException('Registry package template contains a malformed artifact.');
			}
			$contents=$artifact['contents'];$path=$artifact['path'];
			if($path==='dataphyre-panel-package.json'){
				if($manifestFound){throw new \InvalidArgumentException('Registry package contains duplicate manifest artifacts.');}
				$manifestFound=true;
				try{$embedded=json_decode($contents, true, 128, JSON_THROW_ON_ERROR);}
				catch(\Throwable $error){throw new \InvalidArgumentException('Registry package manifest artifact is invalid JSON.', 0, $error);}
				if(!is_array($embedded) || self::canonicalJson($embedded)!==self::canonicalJson($manifest)){
					throw new \InvalidArgumentException('Registry package manifest artifact does not match the signed package manifest.');
				}
			}
			$artifacts[]=[
				'path'=>$path,'contents'=>$contents,'bytes'=>strlen($contents),
				'sha256'=>hash('sha256', $contents),'kind'=>(string)($artifact['kind'] ?? 'asset'),
			];
		}
		if(!$manifestFound){throw new \InvalidArgumentException('Registry package is missing its manifest artifact.');}
		return $artifacts;
	}

	/** @param array<string,mixed> $manifest @param mixed $overrides @return array<string,mixed> */
	private function listing(array $manifest, mixed $overrides): array {
		if(!is_array($overrides) || ($overrides!==[] && array_is_list($overrides))){
			throw new \InvalidArgumentException('Registry package listing overrides must be a map.');
		}
		$links=[];
		foreach((array)($manifest['links'] ?? []) as $link){
			if(!is_array($link) || !is_string($link['label'] ?? null) || !is_string($link['target'] ?? null)){continue;}
			$parts=parse_url($link['target']);
			if(is_array($parts) && strtolower((string)($parts['scheme'] ?? ''))==='https' && trim((string)($parts['host'] ?? ''))!==''
				&& !isset($parts['user']) && !isset($parts['pass'])){$links[]=['label'=>$link['label'],'target'=>$link['target']];}
		}
		$base=[
			'label'=>(string)($manifest['label'] ?? $manifest['id'] ?? ''),
			'description'=>(string)($manifest['description'] ?? 'No package description supplied.'),
			'type'=>(string)($manifest['type'] ?? 'plugin'),
			'provides'=>array_values((array)($manifest['provides'] ?? [])),
			'tags'=>[],'categories'=>[],'links'=>$links,
		];
		$license=$manifest['meta']['license'] ?? null;
		if(is_string($license) && trim($license)!==''){$base['license']=trim($license);}
		return array_replace($base, $overrides);
	}

	private function canonicalName(string $value, string $label): string {
		$normalized=Resource::normalizeName($value);
		if($normalized==='' || $normalized!==$value || strlen($value)>128){throw new \InvalidArgumentException(ucfirst($label).' must be canonical.');}
		return $normalized;
	}

	private function epoch(mixed $value, string $label): int {
		try{
			if(is_int($value)){return $value;}
			if($value instanceof \DateTimeInterface){return $value->getTimestamp();}
			if(!is_string($value) || trim($value)===''){throw new \InvalidArgumentException();}
			$date=new \DateTimeImmutable(trim($value));
			return $date->getTimestamp();
		}
		catch(\Throwable $error){throw new \InvalidArgumentException(ucfirst($label).' is invalid.', 0, $error);}
	}

	private static function canonicalJson(mixed $value): string {
		return json_encode(self::canonical($value), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}

	private static function canonical(mixed $value): mixed {
		if(is_array($value)){
			if(!array_is_list($value)){ksort($value, SORT_STRING);}
			foreach($value as $key=>$item){$value[$key]=self::canonical($item);}
			return $value;
		}
		if($value===null || is_bool($value) || is_int($value) || is_string($value)){return $value;}
		if(is_float($value) && is_finite($value)){return $value;}
		throw new \InvalidArgumentException('Registry publication values must be JSON-compatible.');
	}
}
