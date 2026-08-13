<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** In-memory rotation keyring for application configuration and tests. */
final class PanelStaticNavigationKeyProvider implements PanelNavigationKeyProvider, \JsonSerializable {
	/** @var array<string,PanelNavigationSigningKey> */
	private array $keys=[];

	/**
	 * @param array<string,PanelNavigationSigningKey|string|array<string,mixed>> $keys
	 */
	public function __construct(array $keys, private readonly string $currentKeyId) {
		foreach($keys as $id=>$definition){
			$id=trim((string)$id);
			$key=$definition instanceof PanelNavigationSigningKey
				? $definition
				: new PanelNavigationSigningKey(
					$id,
					is_array($definition) ? (string)($definition['secret'] ?? '') : (string)$definition,
					is_array($definition) && isset($definition['not_before']) ? (int)$definition['not_before'] : null,
					is_array($definition) && isset($definition['expires_at']) ? (int)$definition['expires_at'] : null
				);
			if(isset($this->keys[$key->id()])){
				throw new \InvalidArgumentException('Navigation signing key ids must be unique.');
			}
			$this->keys[$key->id()]=$key;
		}
		if($this->keys!==[] && !isset($this->keys[$currentKeyId])){
			throw new \InvalidArgumentException('The current navigation signing key id is not present in the keyring.');
		}
	}

	public static function single(string $secret, string $keyId='current'): self {
		return new self([$keyId=>$secret], $keyId);
	}

	public function current(int $timestamp): ?PanelNavigationSigningKey {
		$key=$this->keys[$this->currentKeyId] ?? null;
		return $key instanceof PanelNavigationSigningKey && $key->canSignAt($timestamp) ? $key : null;
	}

	public function find(string $keyId): ?PanelNavigationSigningKey {
		return $this->keys[$keyId] ?? null;
	}

	public function manifest(): array {
		return [
			'type'=>'panel_navigation_key_provider',
			'provider'=>'static',
			'current_key_id'=>$this->currentKeyId,
			'key_count'=>count($this->keys),
			'keys'=>array_values(array_map(static fn(PanelNavigationSigningKey $key): array=>$key->jsonSerialize(), $this->keys)),
			'secrets_serialized'=>false,
		];
	}

	public function jsonSerialize(): array { return $this->manifest(); }
}
