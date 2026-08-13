<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Server-signed exact response to a local-first request. */
final class PanelLocalFirstResponse implements \JsonSerializable {
	public const STATUSES=['ok','partial','conflict','rejected','error'];private readonly string $digest;/** @var array<string,mixed> */private readonly array $body;
	/** @param array<string,mixed> $body */
	private function __construct(private readonly string $requestDigest,private readonly int $sequence,private readonly string $status,array $body,private readonly string $issuedAt,private readonly string $keyId,private readonly string $signature){
		if(preg_match('/^[a-f0-9]{64}$/D',$requestDigest)!==1||$sequence<1||!in_array($status,self::STATUSES,true)||PanelOperationsGuard::instant($issuedAt)!==$issuedAt||preg_match('/^[a-f0-9]{64}$/D',$signature)!==1){throw new \InvalidArgumentException('Local-first response identity is invalid.');}PanelOperationsGuard::name($keyId,'local-first response key id');if($body!==[]&&array_is_list($body)){throw new \InvalidArgumentException('Local-first response body must be object-like.');}$body=PanelOperationsGuard::canonical($body);PanelLocalFirstCanonical::value($body);if(strlen(PanelOperationsGuard::json($body))>4194304){throw new \LengthException('Local-first response body exceeds 4 MiB.');}$this->body=$body;$this->digest=PanelLocalFirstCanonical::digest($this->unsigned());
	}
	/** @param array<string,mixed> $body */public static function sign(string $requestDigest,int $sequence,string $status,array $body,string|int|\DateTimeInterface $issuedAt,string $keyId,string $key):self{if(strlen($key)<32){throw new \InvalidArgumentException('Local-first response signing keys require at least 32 bytes.');}$issuedAt=PanelOperationsGuard::instant($issuedAt);$unsigned=['request_digest'=>$requestDigest,'sequence'=>$sequence,'status'=>$status,'body'=>$body,'issued_at'=>$issuedAt,'key_id'=>$keyId];$signature=hash_hmac('sha256',PanelLocalFirstCanonical::digest($unsigned),$key);return new self($requestDigest,$sequence,$status,$body,$issuedAt,$keyId,$signature);}
	/** @param array<string,mixed> $payload */public static function hydrate(array $payload):self{$expected=['type','schema_version','api_version','version','request_digest','sequence','status','body','issued_at','key_id','digest','signature'];$keys=array_keys($payload);sort($keys,SORT_STRING);sort($expected,SORT_STRING);if($keys!==$expected||($payload['type']??null)!=='panel_local_first_response'||($payload['schema_version']??null)!==1||($payload['api_version']??null)!==1||($payload['version']??null)!==1||!is_string($payload['request_digest']??null)||!is_int($payload['sequence']??null)||!is_string($payload['status']??null)||!is_array($payload['body']??null)||!is_string($payload['issued_at']??null)||!is_string($payload['key_id']??null)||!is_string($payload['digest']??null)||!is_string($payload['signature']??null)){throw new \UnexpectedValueException('Stored local-first response shape is invalid.');}try{$response=new self($payload['request_digest'],$payload['sequence'],$payload['status'],$payload['body'],$payload['issued_at'],$payload['key_id'],$payload['signature']);}catch(\Throwable $error){throw new \UnexpectedValueException('Stored local-first response is invalid.',0,$error);}if(!hash_equals($response->digest(),$payload['digest'])){throw new \UnexpectedValueException('Stored local-first response digest is invalid.');}return$response;}
	/** @param array<string,string> $keys */public function verify(array $keys):bool{$key=$keys[$this->keyId]??null;return is_string($key)&&strlen($key)>=32&&hash_equals($this->signature,hash_hmac('sha256',$this->digest,$key));}
	public function requestDigest():string{return$this->requestDigest;}public function sequence():int{return$this->sequence;}public function status():string{return$this->status;}public function digest():string{return$this->digest;}/** @return array<string,mixed> */public function body():array{return$this->body;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_local_first_response','version'=>1]+$this->unsigned()+['digest'=>$this->digest,'signature'=>$this->signature]);}
	/** @return array<string,mixed> */private function unsigned():array{return['request_digest'=>$this->requestDigest,'sequence'=>$this->sequence,'status'=>$this->status,'body'=>$this->body,'issued_at'=>$this->issuedAt,'key_id'=>$this->keyId];}
}
