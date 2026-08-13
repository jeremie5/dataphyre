<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** HMAC-authenticated, expiring, query-bound keyset cursor with retained-key rotation. */
final class PanelSqlCursorCodec implements \JsonSerializable {
	public const MAX_TOKEN_BYTES=4096;
	public const MAX_VALUES=17;
	/** @var array<string,string> */
	private readonly array $keys;
	private readonly string $activeKey;
	private readonly \Closure $clock;

	/** @param array<string,string> $keys */
	public function __construct(array $keys, ?string $activeKey=null, ?callable $clock=null) {
		$normalized=[];
		foreach($keys as $id=>$secret){
			$id=trim((string)$id);
			if(preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $id)!==1){ throw new \InvalidArgumentException('Panel SQL cursor key identifiers are invalid.'); }
			if(!is_string($secret) || strlen($secret)<32 || strlen($secret)>4096){ throw new \InvalidArgumentException("Panel SQL cursor key '{$id}' must contain between 32 and 4096 bytes."); }
			$normalized[$id]=$secret;
		}
		if($normalized===[] || count($normalized)>16){ throw new \InvalidArgumentException('Panel SQL cursor codecs require between 1 and 16 retained keys.'); }
		$activeKey=$activeKey===null ? (string)array_key_first($normalized) : trim($activeKey);
		if(!isset($normalized[$activeKey])){ throw new \InvalidArgumentException('Panel SQL cursor active key is not retained.'); }
		$this->keys=$normalized; $this->activeKey=$activeKey;
		$this->clock=$clock===null ? static fn(): int=>time() : \Closure::fromCallable($clock);
	}

	/** @param list<null|bool|int|float|string> $values */
	public function encode(string $fingerprint, array $values, int $offset, int $ttl=900): string {
		self::fingerprint($fingerprint); self::values($values);
		if($offset<0 || $offset>1000000000){ throw new \InvalidArgumentException('Panel SQL cursor offset is invalid.'); }
		if($ttl<30 || $ttl>86400){ throw new \InvalidArgumentException('Panel SQL cursor TTL must be between 30 and 86400 seconds.'); }
		$now=$this->now();
		$payload=['v'=>1, 'k'=>$this->activeKey, 'q'=>$fingerprint, 'o'=>$offset, 's'=>$values, 'i'=>$now, 'e'=>$now+$ttl];
		$json=json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
		$token=self::base64($json).'.'.self::base64(hash_hmac('sha256', $json, $this->keys[$this->activeKey], true));
		if(strlen($token)>self::MAX_TOKEN_BYTES){ throw new \LengthException('Panel SQL cursor exceeds the public token budget.'); }
		return $token;
	}

	/** @return array{offset:int,values:list<null|bool|int|float|string>,issued_at:int,expires_at:int,key_id:string} */
	public function decode(string $token, string $fingerprint): array {
		self::fingerprint($fingerprint); $token=trim($token);
		if($token==='' || strlen($token)>self::MAX_TOKEN_BYTES || substr_count($token, '.')!==1){ throw new \InvalidArgumentException('Invalid Panel SQL cursor encoding.'); }
		[$encoded,$signature]=explode('.', $token, 2);
		$json=self::unbase64($encoded); $provided=self::unbase64($signature);
		try{ $payload=json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
		catch(\JsonException $error){ throw new \InvalidArgumentException('Invalid Panel SQL cursor payload.', 0, $error); }
		if(!is_array($payload) || array_is_list($payload)){ throw new \InvalidArgumentException('Invalid Panel SQL cursor payload.'); }
		$keys=array_keys($payload); sort($keys, SORT_STRING);
		if($keys!==['e','i','k','o','q','s','v'] || $payload['v']!==1 || !is_string($payload['k']) || !isset($this->keys[$payload['k']]) || !is_string($payload['q']) || !is_int($payload['o']) || !is_array($payload['s']) || !array_is_list($payload['s']) || !is_int($payload['i']) || !is_int($payload['e'])){
			throw new \InvalidArgumentException('Invalid Panel SQL cursor payload.');
		}
		$expected=hash_hmac('sha256', $json, $this->keys[$payload['k']], true);
		if(strlen($provided)!==32 || !hash_equals($expected, $provided)){ throw new \InvalidArgumentException('Panel SQL cursor signature is invalid.'); }
		if(!hash_equals($fingerprint, $payload['q'])){ throw new \InvalidArgumentException('Panel SQL cursor does not belong to this query and security scope.'); }
		self::values($payload['s']);
		if($payload['o']<0 || $payload['o']>1000000000 || $payload['e']<=$payload['i'] || ($payload['e']-$payload['i'])>86400){ throw new \InvalidArgumentException('Panel SQL cursor time or offset bounds are invalid.'); }
		$now=$this->now();
		if($payload['i']>$now+30){ throw new \InvalidArgumentException('Panel SQL cursor was issued in the future.'); }
		if($payload['e']<=$now){ throw new \InvalidArgumentException('Panel SQL cursor has expired.'); }
		return ['offset'=>$payload['o'], 'values'=>$payload['s'], 'issued_at'=>$payload['i'], 'expires_at'=>$payload['e'], 'key_id'=>$payload['k']];
	}

	public function activeKeyId(): string { return $this->activeKey; }
	public function retainedKeyCount(): int { return count($this->keys); }

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_sql_cursor_codec', 'version'=>1, 'algorithm'=>'hmac-sha256',
			'active_key_id'=>$this->activeKey, 'retained_key_count'=>count($this->keys),
			'max_token_bytes'=>self::MAX_TOKEN_BYTES, 'secrets_serialized'=>false,
		];
	}

	private function now(): int {
		$value=($this->clock)();
		if(!is_int($value) || $value<0){ throw new \UnexpectedValueException('Panel SQL cursor clock must return a non-negative integer timestamp.'); }
		return $value;
	}

	private static function fingerprint(string $fingerprint): void {
		if(preg_match('/^[a-f0-9]{64}$/D', $fingerprint)!==1){ throw new \InvalidArgumentException('Panel SQL cursor fingerprint must be a lowercase SHA-256 digest.'); }
	}

	/** @param array<mixed> $values */
	private static function values(array $values): void {
		if(!array_is_list($values) || count($values)>self::MAX_VALUES){ throw new \InvalidArgumentException('Panel SQL cursor sort values are invalid.'); }
		$bytes=0;
		foreach($values as $value){
			if(is_float($value) && !is_finite($value)){ throw new \InvalidArgumentException('Panel SQL cursor contains a non-finite number.'); }
			if($value!==null && !is_bool($value) && !is_int($value) && !is_float($value) && !is_string($value)){ throw new \InvalidArgumentException('Panel SQL cursor values must be JSON scalars.'); }
			if(is_string($value)){ $bytes+=strlen($value); }
		}
		if($bytes>16384){ throw new \LengthException('Panel SQL cursor scalar values exceed their byte budget.'); }
	}

	private static function base64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
	private static function unbase64(string $value): string {
		if($value==='' || preg_match('/^[A-Za-z0-9_-]+$/D', $value)!==1){ throw new \InvalidArgumentException('Invalid Panel SQL cursor encoding.'); }
		$padding=(4-(strlen($value)%4))%4;
		$decoded=base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);
		if($decoded===false){ throw new \InvalidArgumentException('Invalid Panel SQL cursor encoding.'); }
		return $decoded;
	}
}
