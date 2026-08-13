<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Rotating HS256 signer for tenant, document, and principal-bound collaboration intents. */
final class PanelStudioCollaborationIntentSigner implements \JsonSerializable {
	private const AUDIENCE='dp-panel-studio-collaboration';
	private const TYPE='DP-STUDIO-COLLAB';
	private const PAYLOAD_KEYS=['abilities','aud','document_tag','exp','iat','nonce','principal_tag','subject','tenant_tag','v'];
	/** @var array<string,string> */
	private readonly array $keys;
	private readonly \Closure $clock;
	private readonly \Closure $nonceFactory;

	/** @param array<string,string> $keys */
	public function __construct(
		array $keys,
		private readonly string $currentKeyId,
		?callable $clock=null,
		?callable $nonceFactory=null,
		private readonly int $leeway=5,
	){
		if($leeway<0||$leeway>60){
			throw new \InvalidArgumentException('Studio collaboration intent leeway must be between 0 and 60 seconds.');
		}
		if($keys===[]||array_is_list($keys)||count($keys)>8){
			throw new \InvalidArgumentException('Studio collaboration intent keyring requires an object-like map of one to eight keys.');
		}
		$clean=[];
		foreach($keys as $keyId=>$secret){
			if(!is_string($keyId)||preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D', $keyId)!==1){
				throw new \InvalidArgumentException('Studio collaboration intent key ids are invalid.');
			}
			if(!is_string($secret)||strlen($secret)<32){
				throw new \InvalidArgumentException('Studio collaboration signing keys must contain at least 32 bytes.');
			}
			$clean[$keyId]=$secret;
		}
		if(!isset($clean[$currentKeyId])){
			throw new \InvalidArgumentException('Studio collaboration current signing key is not retained.');
		}
		$this->keys=$clean;
		$this->clock=\Closure::fromCallable($clock??static fn():int=>time());
		$this->nonceFactory=\Closure::fromCallable($nonceFactory??static fn():string=>bin2hex(random_bytes(16)));
	}

	/** @param list<string> $abilities */
	public function issue(PanelStudioEditorSession $session,array $abilities=['delta','mutate','presence','typing'],int $ttlSeconds=300):PanelStudioCollaborationIntent {
		$abilities=$this->abilities($abilities);
		if($ttlSeconds<30||$ttlSeconds>900){
			throw new \InvalidArgumentException('Studio collaboration intent TTL must be between 30 and 900 seconds.');
		}
		$now=$this->now();
		$nonce=($this->nonceFactory)();
		if(!is_string($nonce)||preg_match('/^[a-f0-9]{32}$/D', $nonce)!==1){
			throw new \UnexpectedValueException('Studio collaboration nonce factories must return 32 lowercase hexadecimal characters.');
		}
		$key=$this->keys[$this->currentKeyId];
		$payload=[
			'abilities'=>$abilities,
			'aud'=>self::AUDIENCE,
			'document_tag'=>$this->tag('document',$session->document()->id(),$key),
			'exp'=>$now+$ttlSeconds,
			'iat'=>$now,
			'nonce'=>$nonce,
			'principal_tag'=>$this->tag('principal',$session->principalId(),$key),
			'subject'=>$this->subject($session),
			'tenant_tag'=>$this->tag('tenant',$session->document()->tenantId(),$key),
			'v'=>1,
		];
		$header=['alg'=>'HS256','kid'=>$this->currentKeyId,'typ'=>self::TYPE,'v'=>1];
		$input=self::encode(self::json($header)).'.'.self::encode(self::json($payload));
		$token=$input.'.'.self::encode(hash_hmac('sha256', $input, $key, true));
		if(strlen($token)>4096){throw new \LengthException('Studio collaboration intent exceeds 4096 bytes.');}
		return new PanelStudioCollaborationIntent($token,$abilities,$now,$now+$ttlSeconds,$this->currentKeyId);
	}

	public function verify(string $token,PanelStudioEditorSession $session,string $expectedAbility):PanelStudioCollaborationIntentVerification {
		if(!in_array($expectedAbility, ['delta','mutate','presence','typing'], true)){
			throw $this->invalid();
		}
		if($token===''||strlen($token)>4096||substr_count($token, '.')!==2){
			throw $this->invalid();
		}
		[$encodedHeader,$encodedPayload,$encodedSignature]=explode('.', $token, 3);
		$header=$this->decodeObject($encodedHeader, 512);
		if(array_keys($header)!==['alg','kid','typ','v']||$header['alg']!=='HS256'||$header['typ']!==self::TYPE||$header['v']!==1||!is_string($header['kid'])){
			throw $this->invalid();
		}
		$keyId=$header['kid'];
		$key=$this->keys[$keyId]??null;
		if($key===null){throw $this->invalid();}
		$signature=self::decode($encodedSignature);
		$input=$encodedHeader.'.'.$encodedPayload;
		if($signature===null||!hash_equals(hash_hmac('sha256', $input, $key, true), $signature)){
			throw $this->invalid();
		}
		$payload=$this->decodeObject($encodedPayload, 8192);
		$keys=array_keys($payload);
		sort($keys, SORT_STRING);
		$expected=self::PAYLOAD_KEYS;
		sort($expected, SORT_STRING);
		if($keys!==$expected){throw $this->invalid();}
		try{
			if($payload['v']!==1||$payload['aud']!==self::AUDIENCE||$payload['subject']!==$this->subject($session)){
				throw $this->invalid();
			}
			if(!is_int($payload['iat'])||!is_int($payload['exp'])||$payload['iat']<1||$payload['exp']<=$payload['iat']||$payload['exp']-$payload['iat']>900){
				throw $this->invalid();
			}
			if(!is_string($payload['tenant_tag'])||!is_string($payload['document_tag'])||!is_string($payload['principal_tag'])){
				throw $this->invalid();
			}
			if(
				!hash_equals($this->tag('tenant',$session->document()->tenantId(),$key),$payload['tenant_tag'])
				||!hash_equals($this->tag('document',$session->document()->id(),$key),$payload['document_tag'])
				||!hash_equals($this->tag('principal',$session->principalId(),$key),$payload['principal_tag'])
			){
				throw $this->invalid();
			}
			$abilities=$this->abilities($payload['abilities']);
			if(!in_array($expectedAbility, $abilities, true)){throw $this->invalid();}
			if(!is_string($payload['nonce'])||preg_match('/^[a-f0-9]{32}$/D', $payload['nonce'])!==1){
				throw $this->invalid();
			}
			$now=$this->now();
			if($payload['iat']>$now+$this->leeway){throw $this->invalid();}
			if($payload['exp']<$now-$this->leeway){
				throw new PanelStudioCollaborationTransportException('intent_expired',401,'Studio collaboration intent has expired.');
			}
		}catch(PanelStudioCollaborationTransportException $error){
			throw $error;
		}catch(\Throwable){
			throw $this->invalid();
		}
		return new PanelStudioCollaborationIntentVerification($abilities,$payload['iat'],$payload['exp'],$keyId,$payload['nonce']);
	}

	/** @return array<string,mixed> */
	public function jsonSerialize():array {
		$keyIds=array_keys($this->keys);
		sort($keyIds, SORT_STRING);
		return [
			'type'=>'panel_studio_collaboration_intent_signer',
			'version'=>1,
			'algorithm'=>'HS256',
			'current_key_id'=>$this->currentKeyId,
			'accepted_key_ids'=>$keyIds,
			'key_rotation'=>count($keyIds)>1,
			'maximum_ttl_seconds'=>900,
			'leeway_seconds'=>$this->leeway,
			'tenant_bound'=>true,
			'document_bound'=>true,
			'principal_bound'=>true,
			'private_keys_serialized'=>false,
		];
	}

	/** @param mixed $abilities @return list<string> */
	private function abilities(mixed $abilities):array {
		if(!is_array($abilities)||!array_is_list($abilities)||$abilities===[]||count($abilities)>4){
			throw $this->invalid();
		}
		$allowed=['delta','mutate','presence','typing'];
		$result=[];
		foreach($abilities as $ability){
			if(!is_string($ability)||!in_array($ability, $allowed, true)){throw $this->invalid();}
			$result[$ability]=true;
		}
		$values=array_keys($result);
		sort($values, SORT_STRING);
		return $values;
	}

	private function subject(PanelStudioEditorSession $session):string {
		return hash('sha256', $session->document()->tenantId()."\0".$session->document()->id());
	}

	private function tag(string $scope,string $value,string $key):string {
		return self::encode(hash_hmac('sha256', "panel-studio-collaboration-{$scope}-v1\0".$value, $key, true));
	}

	/** @return array<string,mixed> */
	private function decodeObject(string $encoded,int $maximum):array {
		$raw=self::decode($encoded);
		if($raw===null||strlen($raw)>$maximum){throw $this->invalid();}
		try{$value=json_decode($raw, true, 32, JSON_THROW_ON_ERROR);}
		catch(\Throwable){throw $this->invalid();}
		if(!is_array($value)||array_is_list($value)){throw $this->invalid();}
		return $value;
	}

	private function now():int {
		$value=($this->clock)();
		if(!is_int($value)||$value<1){
			throw new \UnexpectedValueException('Studio collaboration signer clocks must return positive integer timestamps.');
		}
		return $value;
	}

	private function invalid():PanelStudioCollaborationTransportException {
		return new PanelStudioCollaborationTransportException('intent_invalid',401,'Studio collaboration intent is invalid.');
	}

	private static function json(array $value):string {
		return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
	}

	private static function encode(string $value):string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	private static function decode(string $value):?string {
		if($value===''||strlen($value)>8192||preg_match('/^[A-Za-z0-9_-]+$/D', $value)!==1){return null;}
		$padding=(4-strlen($value)%4)%4;
		$decoded=base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);
		return is_string($decoded)?$decoded:null;
	}
}
