<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Versioned URL-safe query state with a backward-compatible legacy parser. */
final class PanelQueryUrlCodec {
	public const PARAMETER='dp_query';
	public const VERSION=2;
	public const MAX_ENCODED_BYTES=32768;
	public const LEGACY_FILTERS_DEPRECATED_SINCE='2.0';
	public const LEGACY_FILTERS_SUPPORTED_UNTIL='3.0';

	public static function encode(PanelDataQuery $query): string {
		$json=PanelQueryValue::stableJson($query->urlState());
		return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
	}

	public static function decode(string|array $payload): PanelDataQuery {
		if(is_array($payload)){ return self::fromDecoded($payload); }
		$payload=trim($payload);
		if($payload===''){ return PanelDataQuery::make(); }
		if(strlen($payload)>self::MAX_ENCODED_BYTES){ throw new \LengthException('Panel query URL state exceeds 32768 bytes.'); }
		$decoded=json_decode($payload, true);
		if(!is_array($decoded)){
			$padding=(4-(strlen($payload)%4))%4;
			$raw=base64_decode(strtr($payload, '-_', '+/').str_repeat('=', $padding), true);
			$decoded=is_string($raw) ? json_decode($raw, true) : null;
		}
		if(!is_array($decoded)){ throw new \InvalidArgumentException('Malformed Panel query URL state.'); }
		return self::fromDecoded($decoded);
	}

	/** @param array<string,mixed> $parameters */
	public static function fromQuery(array $parameters, string $parameter=self::PARAMETER): PanelDataQuery {
		if(array_key_exists($parameter, $parameters)){ return self::decode(is_string($parameters[$parameter]) || is_array($parameters[$parameter]) ? $parameters[$parameter] : ''); }
		return self::legacy($parameters);
	}

	/** @return array<string,string> */
	public static function toQuery(PanelDataQuery $query, string $parameter=self::PARAMETER): array { return [$parameter=>self::encode($query)]; }

	/** Parses historical filters/sorts/q query structures without accepting tenant or authorization metadata. @param array<string,mixed> $legacy */
	public static function legacy(array $legacy): PanelDataQuery {
		$data=[];
		if(isset($legacy['filters'])){ $data['filters']=$legacy['filters']; }
		elseif(self::looksLikeFilterMap($legacy)){ $data['filters']=$legacy; }
		foreach(['sorts','sort','direction','dir','search','q','select','include','limit','offset'] as $key){ if(array_key_exists($key, $legacy)){ $data[$key]=$legacy[$key]; } }
		if(isset($data['sort']) && !isset($data['sorts'])){ $data['sorts']=[['field'=>(string)$data['sort'], 'direction'=>(string)($data['direction'] ?? $data['dir'] ?? 'asc')]]; }
		if(isset($data['q']) && !isset($data['search'])){ $data['search']=(string)$data['q']; }
		return PanelDataQuery::fromArray($data);
	}

	/** @param array<string,mixed> $decoded */
	private static function fromDecoded(array $decoded): PanelDataQuery {
		if(($decoded['type'] ?? null)==='panel_query_url' || isset($decoded['version'])){
			$version=(int)($decoded['version'] ?? 1);
			if($version<1 || $version>self::VERSION){ throw new \InvalidArgumentException("Unsupported Panel query URL version '{$version}'."); }
			return PanelDataQuery::fromArray([
				'expression'=>$decoded['expression'] ?? null, 'sort_nodes'=>$decoded['sorts'] ?? [],
				'search'=>$decoded['search'] ?? null, 'select'=>$decoded['select'] ?? [], 'include'=>$decoded['include'] ?? [],
				'limit'=>$decoded['limit'] ?? 50, 'offset'=>$decoded['offset'] ?? 0,
			]);
		}
		return self::legacy($decoded);
	}

	/** @param array<string,mixed> $values */
	private static function looksLikeFilterMap(array $values): bool {
		if($values===[] || array_is_list($values)){ return false; }
		foreach(array_keys($values) as $key){ if(in_array($key, ['tenant','authorization','metadata','cursor'], true)){ return false; } }
		return true;
	}
}
