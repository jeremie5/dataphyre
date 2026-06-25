<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access;

/**
 * Issues and consumes one-time access tokens.
 *
 * AccessTokenBroker stores only HMAC token hashes in the configured identity
 * token table. Raw tokens are returned once from create(), then later verified by
 * hashing caller-supplied tokens during find() or consume(). Tokens are scoped by
 * normalized type, optional user id, optional email, metadata, expiry time, and
 * single-use `used_at` state.
 */
final class AccessTokenBroker {

	private static ?self $instance=null;

	/**
	 * Returns the process-local broker singleton.
	 *
	 * @return self Shared broker instance.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Creates and stores a new one-time token.
	 *
	 * The returned payload includes the raw token because this is the only time it
	 * is available. The database row receives the HMAC hash, normalized type,
	 * lowercased email, JSON metadata, expiry timestamp, and null used_at marker.
	 *
	 * @param string $type Token purpose such as password reset, invitation, or email verification.
	 * @param int|string|null $userId Optional user identifier associated with the token.
	 * @param ?string $email Optional email associated with the token.
	 * @param array<string, mixed> $metadata Additional token metadata to JSON encode.
	 * @param int $ttl Lifetime in seconds, clamped to at least 60 seconds.
	 * @return ?array{id: string, type: string, token: string, token_hash: string, user_id: int|string|null, email: ?string, expires_at: string, metadata: array<string, mixed>} Created token payload, or null when storage is unavailable or insert fails.
	 */
	public function create(string $type, int|string|null $userId=null, ?string $email=null, array $metadata=[], int $ttl=3600): ?array {
		if(function_exists('sql_insert')===false){
			return null;
		}
		$type=$this->normalizeType($type);
		if($type===''){
			return null;
		}
		$raw=rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$hash=$this->hash($raw);
		$id='atok_'.bin2hex(random_bytes(16));
		$expiresAt=date('Y-m-d H:i:s', time()+max(60, $ttl));
		$ok=sql_insert($this->table(), [
			'id'=>$id,
			'type'=>$type,
			'token_hash'=>$hash,
			'user_id'=>is_numeric($userId) ? (int)$userId : null,
			'email'=>$email!==null ? strtolower(trim($email)) : null,
			'metadata_json'=>json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
			'expires_at'=>$expiresAt,
			'used_at'=>null,
		], null, true)!==false;
		return $ok ? [
			'id'=>$id,
			'type'=>$type,
			'token'=>$raw,
			'token_hash'=>$hash,
			'user_id'=>$userId,
			'email'=>$email,
			'expires_at'=>$expiresAt,
			'metadata'=>$metadata,
		] : null;
	}

	/**
	 * Consumes a valid one-time token.
	 *
	 * A token can be consumed only if find() locates an unexpired unused row and
	 * the storage layer can mark it used. The returned row is the pre-update token
	 * payload so callers can continue using its metadata after consumption.
	 *
	 * @param string $type Token purpose to match.
	 * @param string $token Raw token presented by a caller.
	 * @return ?array<string, mixed> Token row with decoded metadata, or null when invalid, expired, used, or update fails.
	 */
	public function consume(string $type, string $token): ?array {
		$row=$this->find($type, $token);
		if($row===null || function_exists('sql_update')===false){
			return null;
		}
		if(sql_update($this->table(), ['used_at'=>date('Y-m-d H:i:s')], 'WHERE id=?', [(string)$row['id']], true)===false){
			return null;
		}
		return $row;
	}

	/**
	 * Finds an unexpired unused token row without consuming it.
	 *
	 * The lookup hashes the raw token, matches the normalized type, requires
	 * `used_at IS NULL`, rejects expired rows, and decodes `metadata_json` into a
	 * `metadata` array on the returned row.
	 *
	 * @param string $type Token purpose to match.
	 * @param string $token Raw token presented by a caller.
	 * @return ?array<string, mixed> Token row with decoded metadata, or null when unavailable or invalid.
	 */
	public function find(string $type, string $token): ?array {
		if(function_exists('sql_select')===false){
			return null;
		}
		$type=$this->normalizeType($type);
		$token=trim($token);
		if($type==='' || $token===''){
			return null;
		}
		$row=sql_select('*', $this->table(), 'WHERE token_hash=? AND type=? AND used_at IS NULL', [$this->hash($token), $type], false, false);
		if(!is_array($row)){
			return null;
		}
		if(strtotime((string)($row['expires_at'] ?? ''))<time()){
			return null;
		}
		$metadata=json_decode((string)($row['metadata_json'] ?? '{}'), true);
		$row['metadata']=is_array($metadata) ? $metadata : [];
		return $row;
	}

	/**
	 * Hashes a raw token with the project-local signing key.
	 *
	 * @param string $token Raw token.
	 * @return string Hex-encoded HMAC token hash.
	 */
	private function hash(string $token): string {
		$key=function_exists('\dataphyre\dpvk') ? \dataphyre\dpvk() : 'dataphyre-access-token';
		return hash_hmac('sha256', $token, (string)$key);
	}

	/**
	 * Returns the configured access token table.
	 *
	 * @return string SQL table name used for token storage.
	 */
	private function table(): string {
		return (string)(DP_ACCESS_CFG['identity']['tokens_table'] ?? 'dataphyre.access_tokens');
	}

	/**
	 * Normalizes token type names for storage and lookup.
	 *
	 * @param string $type Raw token type.
	 * @return string Lowercase underscore-delimited type, or an empty string.
	 */
	private function normalizeType(string $type): string {
		return strtolower(trim((string)preg_replace('/[^A-Za-z0-9_]+/', '_', $type), '_'));
	}
}
