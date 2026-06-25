<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use Dataphyre\Http\Request;

/**
 * Creates and verifies HMAC-signed MVC URLs.
 *
 * Signed URLs protect route links from tampering by signing the canonical path
 * and sorted query parameters. The `signature` query parameter is excluded from
 * the signed payload, and optional `expires` timestamps are enforced during
 * validation.
 */
final class SignedUrl {

	/**
	 * Adds a signature and optional expiry timestamp to a URL.
	 *
	 * Existing signatures are removed before signing so links can be refreshed
	 * safely. Query parameters are sorted before HMAC calculation and before the
	 * final URL is rebuilt.
	 *
	 * @param string $url Path or URL containing the path and query to sign.
	 * @param string $secret Non-empty signing secret.
	 * @param ?int $expiresAt Optional Unix timestamp after which validation fails.
	 * @return string URL with `signature` and optional `expires` query parameters.
	 */
	public static function sign(string $url, string $secret, ?int $expiresAt=null): string {
		$secret=self::secret($secret);
		[$path, $query]=self::splitUrl($url);
		if($expiresAt!==null){
			$query['expires']=(string)$expiresAt;
		}
		unset($query['signature']);
		$query['signature']=self::signature($path, $query, $secret);
		return self::buildUrl($path, $query);
	}

	/**
	 * Validates the signed URL represented by an HTTP request.
	 *
	 * @param Request $request Request carrying the path and query parameters.
	 * @param string $secret Non-empty signing secret.
	 * @return bool True when the signature matches and the link has not expired.
	 */
	public static function valid(Request $request, string $secret): bool {
		return self::validUrl($request->path(), $request->query(), $secret);
	}

	/**
	 * Validates a signed path and query pair.
	 *
	 * The method rejects missing signatures and expired numeric `expires` values
	 * before using hash_equals() for timing-safe signature comparison.
	 *
	 * @param string $path Request path.
	 * @param array<string, mixed> $query Query parameters including `signature`.
	 * @param string $secret Non-empty signing secret.
	 * @return bool True when the signature matches the canonical path and query.
	 */
	public static function validUrl(string $path, array $query, string $secret): bool {
		$secret=self::secret($secret);
		$signature=$query['signature'] ?? null;
		if(!is_string($signature) || $signature===''){
			return false;
		}
		if(isset($query['expires']) && is_numeric($query['expires']) && (int)$query['expires']<time()){
			return false;
		}
		unset($query['signature']);
		return hash_equals($signature, self::signature($path, $query, $secret));
	}

	/**
	 * Calculates the canonical HMAC signature.
	 *
	 * @param string $path URL path.
	 * @param array<string, mixed> $query Query parameters to sign.
	 * @param string $secret Signing secret.
	 * @return string Hex-encoded HMAC SHA-256 signature.
	 */
	private static function signature(string $path, array $query, string $secret): string {
		unset($query['signature']);
		ksort($query);
		return hash_hmac('sha256', self::buildUrl($path, $query), $secret);
	}

	/**
	 * Splits a URL into path and parsed query parameters.
	 *
	 * @param string $url Path or URL to sign.
	 * @return array{0: string, 1: array<string, mixed>} Path and query map.
	 */
	private static function splitUrl(string $url): array {
		$parts=parse_url($url);
		$path=(string)($parts['path'] ?? '/');
		$query=[];
		if(isset($parts['query'])){
			parse_str((string)$parts['query'], $query);
		}
		return [$path, $query];
	}

	/**
	 * Rebuilds a URL from a path and sorted query parameters.
	 *
	 * @param string $path URL path.
	 * @param array<string, mixed> $query Query map.
	 * @return string Canonical URL path with query string when present.
	 */
	private static function buildUrl(string $path, array $query): string {
		ksort($query);
		$queryString=http_build_query($query);
		return $queryString==='' ? $path : $path.'?'.$queryString;
	}

	/**
	 * Validates and normalizes the signing secret.
	 *
	 * @param string $secret Raw signing secret.
	 * @return string Trimmed non-empty secret.
	 *
	 * @throws \RuntimeException When the secret is empty.
	 */
	private static function secret(string $secret): string {
		$secret=trim($secret);
		if($secret===''){
			throw new \RuntimeException('MVC signed URLs require a non-empty secret.');
		}
		return $secret;
	}
}
