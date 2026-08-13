<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Opaque, checksummed cursor codec bound to a query fingerprint. */
final class PanelDataCursor {

	public static function encode(int $offset, string $fingerprint): string {
		$payload=['v'=>1, 'o'=>max(0, $offset), 'q'=>$fingerprint];
		$json=json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$payload['c']=substr(hash('sha256', $json), 0, 24);
		return rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
	}

	public static function decode(string $cursor, string $fingerprint): int {
		$cursor=trim($cursor);
		if($cursor==='' || strlen($cursor)>4096 || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)!==1){ throw new \InvalidArgumentException('Invalid Panel data cursor encoding.'); }
		$padding=(4-(strlen($cursor)%4))%4;
		$decoded=base64_decode(strtr($cursor, '-_', '+/').str_repeat('=', $padding), true);
		if($decoded===false){ throw new \InvalidArgumentException('Invalid Panel data cursor encoding.'); }
		try{ $payload=json_decode($decoded, true, 32, JSON_THROW_ON_ERROR); }
		catch(\JsonException $error){ throw new \InvalidArgumentException('Invalid Panel data cursor payload.', 0, $error); }
		if(!is_array($payload) || ($payload['v'] ?? null)!==1 || !is_int($payload['o'] ?? null) || !is_string($payload['q'] ?? null) || !is_string($payload['c'] ?? null)){
			throw new \InvalidArgumentException('Invalid Panel data cursor payload.');
		}
		$checksum=$payload['c']; unset($payload['c']);
		$expected=substr(hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 24);
		if(!hash_equals($expected, $checksum) || !hash_equals($fingerprint, $payload['q'])){ throw new \InvalidArgumentException('Panel data cursor does not belong to this query.'); }
		return max(0, $payload['o']);
	}
}
