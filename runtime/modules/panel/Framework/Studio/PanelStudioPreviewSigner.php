<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Private-key configured, rotating HMAC preview capability signer. */
final class PanelStudioPreviewSigner implements \JsonSerializable {
	public const MAX_KEYS=8;
	public const MIN_KEY_BYTES=32;
	public const MAX_TTL_SECONDS=900;
	public const MAX_TOKEN_BYTES=8192;
	public const MAX_SEGMENT_BYTES=4096;
	/** @var array<string,string> */ private readonly array $keys;
	private readonly \Closure $clock;
	private readonly \Closure $nonceFactory;
	/** @param array<string,string> $keys */
	public function __construct(array $keys,private readonly string $currentKeyId,?callable $clock=null,?callable $nonceFactory=null){
		if($keys===[]||count($keys)>self::MAX_KEYS){throw new \InvalidArgumentException('Studio preview signing requires between one and eight private keys.');}
		$clean=[];foreach($keys as$id=>$key){if(!is_string($id)||preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/',$id)!==1){throw new \InvalidArgumentException('Studio preview key identifiers must be safe identifiers.');}if(!is_string($key)||strlen($key)<self::MIN_KEY_BYTES){throw new \InvalidArgumentException('Studio preview private keys must contain at least 32 bytes.');}$clean[$id]=$key;}
		if(!isset($clean[$currentKeyId])){throw new \InvalidArgumentException('Studio preview current key is not present in the keyring.');}
		$this->keys=$clean;$this->clock=$clock!==null?$clock(...):static fn():int=>time();$this->nonceFactory=$nonceFactory!==null?$nonceFactory(...):static fn():string=>bin2hex(random_bytes(16));
	}
	public function issue(string $tenantId,string $principalId,string $documentId,PanelStudioRevision $revision,int $ttlSeconds=300,string $audience='panel_studio_preview'):PanelStudioPreviewIntent{
		PanelStudioDocument::scope($tenantId,'tenant');PanelStudioDocument::scope($principalId,'principal');PanelStudioDocument::scope($documentId,'document');self::audience($audience);if($ttlSeconds<1||$ttlSeconds>self::MAX_TTL_SECONDS){throw new \InvalidArgumentException('Studio preview TTL must be between 1 and 900 seconds.');}
		$now=$this->now();$nonce=($this->nonceFactory)();if(!is_string($nonce)||preg_match('/^[a-zA-Z0-9_-]{16,128}$/',$nonce)!==1){throw new \UnexpectedValueException('Studio preview nonce factories must return safe high-entropy identifiers.');}
		$claims=['tenant_id'=>$tenantId,'principal_id'=>$principalId,'document_id'=>$documentId,'revision'=>$revision->number(),'content_hash'=>$revision->contentHash(),'audience'=>$audience,'issued_at'=>$now,'expires_at'=>$now+$ttlSeconds,'nonce'=>$nonce,'key_id'=>$this->currentKeyId];
		$header=['alg'=>'HS256','kid'=>$this->currentKeyId,'typ'=>'DPSTUDIO','version'=>1];$unsigned=self::encode($header).'.'.self::encode($claims);$token=$unsigned.'.'.self::base64Url(hash_hmac('sha256',$unsigned,$this->keys[$this->currentKeyId],true));return new PanelStudioPreviewIntent($token,$claims);
	}
	public function verify(string $token,string $tenantId,string $principalId,string $documentId,?int $revision=null,string $audience='panel_studio_preview'):PanelStudioPreviewIntent{
		PanelStudioDocument::scope($tenantId,'tenant');PanelStudioDocument::scope($principalId,'principal');PanelStudioDocument::scope($documentId,'document');self::audience($audience);if(strlen($token)>self::MAX_TOKEN_BYTES||strlen($token)<16){throw new \UnexpectedValueException('Studio preview token is invalid.');}$parts=explode('.',$token);if(count($parts)!==3){throw new \UnexpectedValueException('Studio preview token is invalid.');}foreach($parts as$index=>$part){if($part===''||strlen($part)>self::MAX_SEGMENT_BYTES||preg_match('/^[A-Za-z0-9_-]+$/D',$part)!==1||($index===2&&strlen($part)!==43)){throw new \UnexpectedValueException('Studio preview token is invalid.');}}
		[$headerPart,$claimsPart,$signature]=$parts;$header=self::decode($headerPart);$claims=self::decode($claimsPart);$keyId=$header['kid']??null;if(($header['alg']??null)!=='HS256'||($header['typ']??null)!=='DPSTUDIO'||($header['version']??null)!==1||!is_string($keyId)||!isset($this->keys[$keyId])){throw new \UnexpectedValueException('Studio preview token is invalid.');}
		$expected=self::base64Url(hash_hmac('sha256',$headerPart.'.'.$claimsPart,$this->keys[$keyId],true));if(!hash_equals($expected,$signature)){throw new \UnexpectedValueException('Studio preview token is invalid.');}
		$now=$this->now();$issued=is_int($claims['issued_at']??null)?$claims['issued_at']:0;$expires=is_int($claims['expires_at']??null)?$claims['expires_at']:0;if($issued<1||$issued>$now+30||$expires<=$now||$expires-$issued<1||$expires-$issued>self::MAX_TTL_SECONDS){throw new \UnexpectedValueException('Studio preview token is expired or has an invalid lifetime.');}
		$scopes=['tenant_id'=>$tenantId,'principal_id'=>$principalId,'document_id'=>$documentId,'audience'=>$audience];foreach($scopes as$key=>$expectedScope){$actual=$claims[$key]??null;if(!is_string($actual)||!hash_equals($expectedScope,$actual)){throw new \UnexpectedValueException('Studio preview token scope does not match.');}}
		if($revision!==null&&($claims['revision']??null)!==$revision){throw new \UnexpectedValueException('Studio preview token revision does not match.');}
		if(!is_int($claims['revision']??null)||$claims['revision']<1||!is_string($claims['content_hash']??null)||preg_match('/^[a-f0-9]{64}$/',$claims['content_hash'])!==1||!is_string($claims['nonce']??null)||preg_match('/^[a-zA-Z0-9_-]{16,128}$/',$claims['nonce'])!==1||($claims['key_id']??null)!==$keyId){throw new \UnexpectedValueException('Studio preview token claims are invalid.');}
		return new PanelStudioPreviewIntent($token,$claims);
	}
	public function manifest():array{$ids=array_keys($this->keys);sort($ids,SORT_STRING);return['type'=>'panel_studio_preview_signer_manifest','version'=>1,'algorithm'=>'HS256','current_key_id'=>$this->currentKeyId,'accepted_key_ids'=>$ids,'key_rotation'=>count($ids)>1,'max_ttl_seconds'=>self::MAX_TTL_SECONDS,'max_token_bytes'=>self::MAX_TOKEN_BYTES,'max_segment_bytes'=>self::MAX_SEGMENT_BYTES,'nonce_semantics'=>'bounded_reusable_until_expiry','replay_consumption_store'=>false,'private_keys_serialized'=>false,'fallback_key'=>false];}
	public function jsonSerialize():array{return$this->manifest();}
	private static function audience(string $audience):void{if(preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/',$audience)!==1){throw new \InvalidArgumentException('Studio preview audiences must be safe identifiers.');}}
	private static function encode(array $value):string{return self::base64Url(json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
	private static function decode(string $value):array{$padding=(4-strlen($value)%4)%4;$raw=base64_decode(strtr($value,'-_','+/').str_repeat('=',$padding),true);if(!is_string($raw)){throw new \UnexpectedValueException('Studio preview token is invalid.');}try{$decoded=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \UnexpectedValueException('Studio preview token is invalid.');}if(!is_array($decoded)||array_is_list($decoded)){throw new \UnexpectedValueException('Studio preview token is invalid.');}return$decoded;}
	private static function base64Url(string $value):string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
	private function now():int{$value=($this->clock)();if(!is_int($value)||$value<1){throw new \UnexpectedValueException('Studio preview clocks must return positive integer timestamps.');}return$value;}
}
