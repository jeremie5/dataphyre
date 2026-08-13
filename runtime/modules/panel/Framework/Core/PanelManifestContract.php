<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Canonical version contract for public Panel manifests and effect payloads.
 *
 * Versions are deliberately independent from the Dataphyre package version.
 * `schema_version` changes when serialized field meaning or shape requires a
 * consumer migration; `api_version` changes when the public Panel client/API
 * contract becomes incompatible. Additive fields retain the current versions.
 */
final class PanelManifestContract {

	public const SCHEMA_VERSION=1;
	public const API_VERSION=1;

	private function __construct(){}

	/** @return array{schema_version:int,api_version:int} */
	public static function versions(): array {
		return [
			'schema_version'=>self::SCHEMA_VERSION,
			'api_version'=>self::API_VERSION,
		];
	}

	/**
	 * Stamps one public payload and every nested manifest/effect payload.
	 *
	 * The top-level value is always stamped. Nested ordinary data maps are left
	 * alone, while maps whose `type` identifies a manifest or effect receive the
	 * same central contract. This versions child table/schema/action/permission
	 * manifests without duplicating constants throughout their builders.
	 *
	 * @param array<string|int,mixed> $payload
	 * @return array<string|int,mixed>
	 */
	public static function stamp(array $payload): array {
		return self::stampValue($payload, true);
	}

	/** @param array<string|int,mixed> $payload @return array<string|int,mixed> */
	private static function stampValue(array $payload, bool $topLevel=false): array {
		foreach($payload as $key=>$value){
			if(is_array($value)){
				$payload[$key]=self::stampValue($value, false);
			}
		}
		$type=is_string($payload['type'] ?? null) ? strtolower(trim((string)$payload['type'])) : '';
		if(!$topLevel && !self::isContractType($type)){
			return $payload;
		}

		$versioned=[];
		if(array_key_exists('type', $payload)){
			$versioned['type']=$payload['type'];
			unset($payload['type']);
		}
		$versioned['schema_version']=self::SCHEMA_VERSION;
		$versioned['api_version']=self::API_VERSION;
		unset($payload['schema_version'], $payload['api_version']);
		return $versioned+$payload;
	}

	private static function isContractType(string $type): bool {
		return $type!=='' && (
			str_ends_with($type, '_manifest')
			|| str_contains($type, '_manifest_')
			|| str_ends_with($type, '_effect')
			|| str_ends_with($type, '_effects')
		);
	}
}
