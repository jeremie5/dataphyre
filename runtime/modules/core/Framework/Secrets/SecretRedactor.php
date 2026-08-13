<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Secrets;

/** Recursively removes credential material before logs, diagnostics, or APIs. */
final class SecretRedactor {

	private const REDACTED='[REDACTED]';

	/** @param array<int,string> $additional_keys */
	public static function redact(mixed $value, array $additional_keys=[]): mixed {
		$additional=array_fill_keys(array_map(static fn(string $key): string=>self::normalizeKey($key), $additional_keys), true);
		return self::walk($value, $additional);
	}

	public static function sensitiveKey(string $key): bool {
		$key=self::normalizeKey($key);
		return preg_match('/(?:^|_)(?:api_key|authorization(?:_code)?|bearer|ciphertext|client_secret|cookie|credential(?:s)?|fingerprint|passphrase|password|private_key|refresh_token|secret|session_key|signing_key|token)(?:_|$)/', $key)===1;
	}

	/** @param array<string,bool> $additional */
	private static function walk(mixed $value, array $additional): mixed {
		if(!is_array($value)) return $value;
		$result=[];
		foreach($value as $key=>$item){
			$normalized=self::normalizeKey((string)$key);
			$result[$key]=(isset($additional[$normalized]) || self::sensitiveKey($normalized))
				? self::REDACTED
				: self::walk($item, $additional);
		}
		return $result;
	}

	private static function normalizeKey(string $key): string {
		$key=preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', trim($key)) ?? $key;
		return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $key) ?? $key);
	}
}
