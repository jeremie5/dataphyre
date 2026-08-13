<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Authenticated local envelope around an otherwise opaque upstream cursor. */
final class PanelHttpDataSourceCursorCodec implements \JsonSerializable {
	/** @var array<string,string> */
	private readonly array $keys;
	private readonly string $activeKey;

	/** @param array<string,string> $keys */
	public function __construct(array $keys, ?string $activeKey=null){
		if($keys===[] || array_is_list($keys) || count($keys)>8){ throw new \InvalidArgumentException('Remote cursor keys require an object-like map of 1-8 retained keys.'); }
		$normalized=[];
		foreach($keys as $id=>$key){
			$id=PanelHttpDataSourceValue::identifier((string)$id, 'Remote cursor key id', 32);
			if(!is_string($key) || strlen($key)<32){ throw new \InvalidArgumentException('Remote cursor signing keys must contain at least 32 bytes.'); }
			$normalized[$id]=$key;
		}
		$this->keys=$normalized;
		$this->activeKey=$activeKey===null ? (string)array_key_first($normalized) : PanelHttpDataSourceValue::identifier($activeKey, 'Remote active cursor key id', 32);
		if(!isset($this->keys[$this->activeKey])){ throw new \InvalidArgumentException('Remote active cursor key is not retained.'); }
	}

	public function bindingFingerprint(string $value): string { return hash_hmac('sha256', $value, $this->keys[$this->activeKey]); }

	public function encode(string $upstreamCursor, string $queryFingerprint, string $definitionFingerprint, int $nowMilliseconds, int $ttlSeconds): string {
		$upstreamCursor=PanelHttpDataSourceValue::text($upstreamCursor, 'Upstream cursor', 2048);
		self::fingerprint($queryFingerprint, 'Remote cursor query fingerprint');
		self::fingerprint($definitionFingerprint, 'Remote cursor definition fingerprint');
		if($nowMilliseconds<0 || $ttlSeconds<30 || $ttlSeconds>86400){ throw new \InvalidArgumentException('Remote cursor timing values are invalid.'); }
		$payload=['v'=>1,'k'=>$this->activeKey,'iat'=>$nowMilliseconds,'exp'=>$nowMilliseconds+($ttlSeconds*1000),'q'=>$queryFingerprint,'d'=>$definitionFingerprint,'u'=>$upstreamCursor];
		$json=PanelHttpDataSourceValue::encode($payload);
		$token=self::base64($json).'.'.self::base64(hash_hmac('sha256', $json, $this->keys[$this->activeKey], true));
		if(strlen($token)>4096){ throw new \LengthException('Remote cursor envelope exceeds 4096 bytes.'); }
		return $token;
	}

	public function decode(string $cursor, string $queryFingerprint, string $definitionFingerprint, int $nowMilliseconds): string {
		self::fingerprint($queryFingerprint, 'Remote cursor query fingerprint'); self::fingerprint($definitionFingerprint, 'Remote cursor definition fingerprint');
		$cursor=trim($cursor);
		if($cursor==='' || strlen($cursor)>4096 || substr_count($cursor, '.')!==1){ throw new \InvalidArgumentException('Remote cursor envelope is invalid.'); }
		[$payloadPart,$signaturePart]=explode('.', $cursor, 2);
		$json=self::unbase64($payloadPart); $signature=self::unbase64($signaturePart);
		try{ $payload=json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
		catch(\Throwable){ throw new \InvalidArgumentException('Remote cursor envelope is invalid.'); }
		if(!is_array($payload)){ throw new \InvalidArgumentException('Remote cursor envelope is invalid.'); }
		PanelHttpDataSourceValue::exactKeys($payload, ['v','k','iat','exp','q','d','u'], 'Remote cursor envelope');
		$key=is_string($payload['k']) ? ($this->keys[$payload['k']] ?? null) : null;
		if(!is_string($key) || strlen($signature)!==32 || !hash_equals(hash_hmac('sha256', $json, $key, true), $signature)){ throw new \InvalidArgumentException('Remote cursor envelope is invalid.'); }
		if($payload['v']!==1 || !is_int($payload['iat']) || !is_int($payload['exp']) || !is_string($payload['q']) || !is_string($payload['d']) || !is_string($payload['u'])){ throw new \InvalidArgumentException('Remote cursor envelope is invalid.'); }
		if(!hash_equals($queryFingerprint, $payload['q']) || !hash_equals($definitionFingerprint, $payload['d'])){ throw new \InvalidArgumentException('Remote cursor does not belong to this query.'); }
		if($payload['iat']<0 || $payload['exp']<=$payload['iat'] || $payload['exp']<=$nowMilliseconds){ throw new \InvalidArgumentException('Remote cursor has expired.'); }
		return PanelHttpDataSourceValue::text($payload['u'], 'Upstream cursor', 2048);
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return ['type'=>'panel_http_data_cursor','version'=>1,'algorithm'=>'hmac-sha256','active_key'=>$this->activeKey,'retained_keys'=>count($this->keys),'max_envelope_bytes'=>4096,'max_upstream_bytes'=>2048,'secrets_serialized'=>false];
	}

	private static function fingerprint(string $value, string $label): void { if(preg_match('/^[a-f0-9]{64}$/D', $value)!==1){ throw new \InvalidArgumentException($label.' is invalid.'); } }
	private static function base64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
	private static function unbase64(string $value): string {
		if($value==='' || preg_match('/^[A-Za-z0-9_-]+$/D', $value)!==1){ throw new \InvalidArgumentException('Remote cursor envelope is invalid.'); }
		$decoded=base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4-(strlen($value)%4))%4), true);
		if($decoded===false){ throw new \InvalidArgumentException('Remote cursor envelope is invalid.'); }
		return $decoded;
	}
}
