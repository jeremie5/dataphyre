<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** HMAC signer with explicit key ids, retained-key verification, exact claims, and context binding. */
final class PanelDataSurfaceIntentSigner implements \JsonSerializable {
	private const AUDIENCE='dp-panel-data-surface';
	private const TYPE='DP-SURFACE';
	private const PAYLOAD_KEYS_V1=['aud','definition','exp','iat','nonce','panel','principal_tag','projection_fingerprint','query_fingerprint','query_state','range','resource','source','surface','tenant_tag','v'];
	private const PAYLOAD_KEYS_V2=['aud','definition','definition_fingerprint','exp','iat','nonce','panel','principal_tag','projection_fingerprint','query_fingerprint','query_state','range','resource','source','surface','tenant_tag','v'];
	/** @var array<string,string> */ private array $keys=[];
	private string $currentKeyId;
	private ?\Closure $clock;

	/** @param array<string,string> $keys */
	public function __construct(array $keys, string $currentKeyId, ?callable $clock=null, private readonly int $leeway=5) {
		if($leeway<0 || $leeway>60){ throw new \InvalidArgumentException('Panel DataSurface intent leeway must be between 0 and 60 seconds.'); }
		foreach($keys as $keyId=>$secret){
			$keyId=PanelDataSurfaceGuard::identifier((string)$keyId, 'key id', 64);
			if(!is_string($secret) || strlen($secret)<32){ throw new \InvalidArgumentException('Panel DataSurface signing keys must contain at least 32 bytes.'); }
			$this->keys[$keyId]=$secret;
		}
		$currentKeyId=PanelDataSurfaceGuard::identifier($currentKeyId, 'current key id', 64);
		if(!isset($this->keys[$currentKeyId])){ throw new \InvalidArgumentException('Panel DataSurface current signing key is not present in the keyring.'); }
		$this->currentKeyId=$currentKeyId;
		$this->clock=$clock===null ? null : \Closure::fromCallable($clock);
	}

	/** @param array<string,mixed> $safeState */
	public function issue(
		PanelDataSurfaceDefinition $definition,
		PanelDataQuery $query,
		array $safeState,
		PanelDataSurfaceRange $range,
		PanelDataSurfaceContext $context,
		int $ttl=300
	): PanelDataSurfaceWindowIntent {
		if($ttl<30 || $ttl>3600){ throw new \InvalidArgumentException('Panel DataSurface intent TTL must be between 30 and 3600 seconds.'); }
		PanelDataSurfaceGuard::assertJson($safeState, 131072);
		$now=$this->now(); $key=$this->keys[$this->currentKeyId];
		$payload=[
			'v'=>2,'aud'=>self::AUDIENCE,'panel'=>$context->panel(),'definition'=>$definition->id(),
				'resource'=>$definition->resource(),'source'=>$definition->source(),'surface'=>$definition->surface()->value,'definition_fingerprint'=>$definition->fingerprint(),
			'query_fingerprint'=>$query->fingerprint(),'projection_fingerprint'=>$definition->projection()->fingerprint(),
			'query_state'=>$safeState,'tenant_tag'=>$this->tag('tenant', $context->tenant(), $key),
			'principal_tag'=>$this->tag('principal', $context->principal(), $key),'range'=>$range->claims(),
			'iat'=>$now,'exp'=>$now+$ttl,'nonce'=>bin2hex(random_bytes(16)),
		];
		$header=['alg'=>'HS256','kid'=>$this->currentKeyId,'typ'=>self::TYPE,'v'=>2];
		$input=PanelDataSurfaceGuard::encode(PanelDataSurfaceGuard::canonicalJson($header)).'.'.PanelDataSurfaceGuard::encode(PanelDataSurfaceGuard::canonicalJson($payload));
		$token=$input.'.'.PanelDataSurfaceGuard::encode(hash_hmac('sha256', $input, $key, true));
		if(strlen($token)>16384){ throw new \LengthException('Panel DataSurface intent exceeds 16384 bytes.'); }
		return new PanelDataSurfaceWindowIntent($token, $now, $now+$ttl, $this->currentKeyId);
	}

	public function verify(string $token, PanelDataSurfaceContext $context): PanelDataSurfaceIntentVerification {
		if($token==='' || strlen($token)>16384 || substr_count($token, '.')!==2){ throw $this->invalid(); }
		[$encodedHeader,$encodedPayload,$encodedSignature]=explode('.', $token, 3);
		$headerJson=PanelDataSurfaceGuard::decode($encodedHeader);
		if($headerJson===null || strlen($headerJson)>512){ throw $this->invalid(); }
		try{ $header=json_decode($headerJson, true, 8, JSON_THROW_ON_ERROR); }
		catch(\JsonException){ throw $this->invalid(); }
		if(!is_array($header) || array_keys($header)!==['alg','kid','typ','v'] || $header['alg']!=='HS256' || $header['typ']!==self::TYPE || !in_array($header['v'],[1,2],true) || !is_string($header['kid'])){ throw $this->invalid(); }
		$keyId=$header['kid']; $key=$this->keys[$keyId] ?? null;
		if($key===null){ throw $this->invalid(); }
		$signature=PanelDataSurfaceGuard::decode($encodedSignature);
		$input=$encodedHeader.'.'.$encodedPayload;
		if($signature===null || !hash_equals(hash_hmac('sha256', $input, $key, true), $signature)){ throw $this->invalid(); }
		$payloadJson=PanelDataSurfaceGuard::decode($encodedPayload);
		if($payloadJson===null || strlen($payloadJson)>131072){ throw $this->invalid(); }
		try{ $payload=json_decode($payloadJson, true, PanelDataSurfaceGuard::MAX_JSON_DEPTH+1, JSON_THROW_ON_ERROR); }
		catch(\JsonException){ throw $this->invalid(); }
		if(!is_array($payload) || array_is_list($payload)){ throw $this->invalid(); }
		$keys=array_keys($payload); sort($keys, SORT_STRING); $expected=$header['v']===2?self::PAYLOAD_KEYS_V2:self::PAYLOAD_KEYS_V1; sort($expected, SORT_STRING);
		if($keys!==$expected){ throw $this->invalid(); }
		try{
			if($payload['v']!==$header['v'] || $payload['aud']!==self::AUDIENCE){ throw $this->invalid(); }
			$panel=PanelDataSurfaceGuard::identifier((string)$payload['panel'], 'panel', 96);
				$definition=PanelDataSurfaceGuard::identifier((string)$payload['definition'], 'definition', 100);
				$definitionFingerprint=$header['v']===2?PanelDataSurfaceGuard::digest((string)$payload['definition_fingerprint'],'definition fingerprint'):null;
			$resource=PanelDataSurfaceGuard::identifier((string)$payload['resource'], 'resource', 100);
			$source=PanelDataSurfaceGuard::identifier((string)$payload['source'], 'source', 100);
			$surface=PanelDataSurfaceType::normalize((string)$payload['surface']);
			$queryFingerprint=PanelDataSurfaceGuard::digest((string)$payload['query_fingerprint'], 'query fingerprint');
			$projectionFingerprint=PanelDataSurfaceGuard::digest((string)$payload['projection_fingerprint'], 'projection fingerprint');
			$nonce=PanelDataSurfaceGuard::boundedString($payload['nonce'], 'nonce', 64);
			if(preg_match('/^[a-f0-9]{32}$/D', $nonce)!==1){ throw $this->invalid(); }
			if(!is_int($payload['iat']) || !is_int($payload['exp']) || $payload['iat']<0 || $payload['exp']<=$payload['iat'] || $payload['exp']-$payload['iat']>3600){ throw $this->invalid(); }
			if(!is_string($payload['tenant_tag']) || !is_string($payload['principal_tag'])){ throw $this->invalid(); }
			if(!hash_equals($this->tag('tenant', $context->tenant(), $key), $payload['tenant_tag']) || !hash_equals($this->tag('principal', $context->principal(), $key), $payload['principal_tag']) || !hash_equals($context->panel(), $panel)){ throw $this->invalid(); }
			$now=$this->now();
			if($payload['iat']>$now+$this->leeway){ throw $this->invalid(); }
			if($payload['exp']<$now-$this->leeway){ throw new PanelDataSurfaceException('intent_expired', 401, 'Panel DataSurface intent has expired.'); }
			if(!is_array($payload['query_state']) || ($payload['query_state']!==[] && array_is_list($payload['query_state']))){ throw $this->invalid(); }
			PanelDataSurfaceGuard::assertJson($payload['query_state'], 131072);
			if(!is_array($payload['range']) || array_is_list($payload['range'])){ throw $this->invalid(); }
			$range=PanelDataSurfaceRange::fromArray($payload['range']);
		}
		catch(PanelDataSurfaceException $exception){ throw $exception; }
		catch(\Throwable){ throw $this->invalid(); }
		return new PanelDataSurfaceIntentVerification($keyId,$nonce,$panel,$definition,$definitionFingerprint,$resource,$source,$surface,$queryFingerprint,$projectionFingerprint,$payload['query_state'],$range,$payload['iat'],$payload['exp']);
	}

	/** Secret-free signer posture. */
	public function jsonSerialize(): array {
		$keyIds=array_keys($this->keys); sort($keyIds, SORT_STRING);
		return ['type'=>'panel_data_surface_intent_signer','version'=>2,'algorithm'=>'HS256','issued_token_schema'=>2,'verified_token_schemas'=>[1,2],'current_key_id'=>$this->currentKeyId,'verification_key_ids'=>$keyIds,'retained_key_count'=>max(0,count($keyIds)-1),'maximum_ttl'=>3600,'secrets_exposed'=>false];
	}

	private function now(): int {
		$value=$this->clock===null ? time() : ($this->clock)();
		if(!is_int($value) || $value<0){ throw new \UnexpectedValueException('Panel DataSurface clock must return a non-negative integer timestamp.'); }
		return $value;
	}
	private function tag(string $domain, string $value, string $key): string { return PanelDataSurfaceGuard::encode(hash_hmac('sha256', "panel-data-surface-{$domain}-v1\0".$value, $key, true)); }
	private function invalid(): PanelDataSurfaceException { return new PanelDataSurfaceException('intent_invalid', 401, 'Panel DataSurface intent is invalid.'); }
}
