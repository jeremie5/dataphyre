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
 * Immutable bytes and descriptors produced by a registry publication.
 *
 * Raw index and artifact bodies are available to an explicit registry store,
 * but JsonSerializable deliberately exposes only public metadata and digests.
 */
final class PanelPackageRegistryPublication implements \JsonSerializable {
	private readonly string $digest;
	private readonly int $bytes;

	/**
	 * @param array<string,mixed> $index
	 * @param array<string,array<string,mixed>> $artifacts Artifact descriptors keyed by SHA-256.
	 */
	public function __construct(
		private readonly array $index,
		private readonly string $body,
		private readonly array $artifacts
	) {
		if($body==='' || strlen($body)>16777216){
			throw new \InvalidArgumentException('Registry publication body is empty or exceeds its byte limit.');
		}
		try{$decoded=json_decode($body, true, 128, JSON_THROW_ON_ERROR);}
		catch(\Throwable $error){throw new \InvalidArgumentException('Registry publication body is not valid JSON.', 0, $error);}
		if(!is_array($decoded) || self::canonicalJson($decoded)!==$body || self::canonicalJson($index)!==$body){
			throw new \InvalidArgumentException('Registry publication body is not the canonical form of its index.');
		}
		if(($index['format'] ?? null)!==PanelPackageRegistryIndex::FORMAT
			|| !is_string($index['registry'] ?? null)
			|| !is_string($index['publisher'] ?? null)
			|| !is_int($index['sequence'] ?? null) || $index['sequence']<1
			|| !is_array($index['packages'] ?? null) || !array_is_list($index['packages']) || $index['packages']===[]){
			throw new \InvalidArgumentException('Registry publication index is incomplete.');
		}
		$seen=[];
		foreach($artifacts as $digest=>$artifact){
			if(!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest)!==1 || isset($seen[$digest])
				|| !is_array($artifact) || !is_string($artifact['body'] ?? null)
				|| !is_int($artifact['bytes'] ?? null) || $artifact['bytes']!==strlen($artifact['body'])
				|| !hash_equals($digest, hash('sha256', $artifact['body']))
				|| ($artifact['sha256'] ?? null)!==$digest
				|| ($artifact['content_type'] ?? null)!==PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE
				|| !is_string($artifact['id'] ?? null) || !is_string($artifact['version'] ?? null)
				|| !is_string($artifact['locator'] ?? null)){
				throw new \InvalidArgumentException('Registry publication contains an invalid artifact descriptor.');
			}
			$seen[$digest]=true;
		}
		if(count($seen)!==count($index['packages'])){
			throw new \InvalidArgumentException('Registry publication artifact and package counts do not match.');
		}
		foreach($index['packages'] as $package){
			$digest=is_array($package) && is_array($package['artifact'] ?? null) ? (string)($package['artifact']['sha256'] ?? '') : '';
			if(!isset($seen[$digest]) || !hash_equals((string)$artifacts[$digest]['locator'], (string)($package['artifact']['locator'] ?? ''))){
				throw new \InvalidArgumentException('Registry publication package does not match its artifact descriptor.');
			}
		}
		$this->digest=hash('sha256', $body);
		$this->bytes=strlen($body);
	}

	public function registry(): string {return (string)$this->index['registry'];}
	public function publisher(): string {return (string)$this->index['publisher'];}
	public function sequence(): int {return (int)$this->index['sequence'];}
	public function digest(): string {return $this->digest;}
	public function bytes(): int {return $this->bytes;}
	public function body(): string {return $this->body;}
	/** @return array<string,mixed> */
	public function index(): array {return $this->index;}
	/** @return array<string,array<string,mixed>> */
	public function artifacts(): array {return $this->artifacts;}

	/** @return array<string,mixed>|null */
	public function artifact(string $digest): ?array {
		$digest=strtolower(trim($digest));
		return isset($this->artifacts[$digest]) ? $this->artifacts[$digest] : null;
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		$packages=[];
		foreach($this->index['packages'] as $package){
			$safe=$package;
			if(is_array($safe['artifact'] ?? null)){unset($safe['artifact']['locator']);}
			$packages[]=$safe;
		}
		$signature=is_array($this->index['signature'] ?? null) ? $this->index['signature'] : [];
		unset($signature['signature']);
		return [
			'type'=>'panel_package_registry_publication',
			'format'=>PanelPackageRegistryIndex::FORMAT,
			'registry'=>$this->registry(),
			'publisher'=>$this->publisher(),
			'sequence'=>$this->sequence(),
			'generated_at'=>(string)($this->index['generated_at'] ?? ''),
			'expires_at'=>(string)($this->index['expires_at'] ?? ''),
			'digest'=>$this->digest,
			'bytes'=>$this->bytes,
			'package_count'=>count($packages),
			'artifact_bytes'=>array_sum(array_map(static fn(array $artifact): int=>(int)$artifact['bytes'], $this->artifacts)),
			'packages'=>$packages,
			'signature'=>$signature,
			'index_body_serialized'=>false,
			'artifact_bodies_serialized'=>false,
			'artifact_locators_serialized'=>false,
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {return $this->toArray();}

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
