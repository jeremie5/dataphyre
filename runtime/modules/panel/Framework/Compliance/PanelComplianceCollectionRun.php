<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Signed, immutable outcome of one fingerprint-pinned collection plan. */
final class PanelComplianceCollectionRun implements \JsonSerializable {
	private readonly string $digest;
	/** @param array<string,mixed> $payload */ private function __construct(private readonly array $payload,private readonly string $keyId,private readonly string $signature){
		PanelOperationsGuard::name($keyId,'compliance key id');if(preg_match('/^[a-f0-9]{64}$/D',$signature)!==1){throw new \InvalidArgumentException('Compliance collection run signature is invalid.');}
		self::validate($payload);$this->digest=PanelOperationsGuard::digest($payload);
	}

	/** @param array<string,mixed> $payload */ public static function sign(array $payload,string $keyId,string $key):self {
		if(strlen($key)<32){throw new \InvalidArgumentException('Compliance collection run signing keys require at least 32 bytes.');}$payload=PanelOperationsGuard::canonical($payload);self::validate($payload);$digest=PanelOperationsGuard::digest($payload);return new self($payload,PanelOperationsGuard::name($keyId,'compliance key id'),hash_hmac('sha256',$digest,$key));
	}
	/** @param array<string,mixed> $manifest */ public static function hydrate(array $manifest):self {
		$payload=$manifest['payload']??null;if(!is_array($payload)||!is_string($manifest['key_id']??null)||!is_string($manifest['signature']??null)){throw new \InvalidArgumentException('Compliance collection run manifest is invalid.');}$run=new self(PanelOperationsGuard::canonical($payload),$manifest['key_id'],$manifest['signature']);if(isset($manifest['digest'])&&(!is_string($manifest['digest'])||!hash_equals($run->digest,$manifest['digest']))){throw new \UnexpectedValueException('Compliance collection run digest does not verify.');}return$run;
	}

	/** @param array<string,string> $keys */ public function verify(array $keys):bool {$key=$keys[$this->keyId]??null;return is_string($key)&&strlen($key)>=32&&hash_equals($this->signature,hash_hmac('sha256',$this->digest,$key));}
	public function digest():string{return$this->digest;}
	public function runId():string{return(string)$this->payload['run_id'];}
	public function planFingerprint():string{return(string)$this->payload['plan_fingerprint'];}
	/** @return array<string,mixed> */ public function payload():array{return$this->payload;}
	/** @return array<string,mixed> */ public function summary():array{return$this->payload['summary'];}
	/** @return list<array<string,mixed>> */ public function results():array{return$this->payload['results'];}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_compliance_collection_run_manifest','version'=>1,'payload'=>$this->payload,'digest'=>$this->digest,'key_id'=>$this->keyId,'signature'=>$this->signature]);}

	/** @param array<string,mixed> $payload */ private static function validate(array $payload):void {
		if(($payload['type']??null)!=='panel_compliance_collection_run'||($payload['version']??null)!==1){throw new \InvalidArgumentException('Compliance collection run schema is invalid.');}
		PanelOperationsGuard::name((string)($payload['run_id']??''),'compliance collection run id');
		if(preg_match('/^[a-f0-9]{64}$/D',(string)($payload['plan_fingerprint']??''))!==1){throw new \InvalidArgumentException('Compliance collection run plan fingerprint is invalid.');}
		$started=PanelOperationsGuard::instant($payload['started_at']??'','compliance collection run start');$completed=PanelOperationsGuard::instant($payload['completed_at']??'','compliance collection run completion');if(strcmp($completed,$started)<0){throw new \InvalidArgumentException('Compliance collection run completion precedes its start.');}
		if(!is_array($payload['results']??null)||!array_is_list($payload['results'])||count($payload['results'])>2000||!is_array($payload['summary']??null)){throw new \InvalidArgumentException('Compliance collection run results are invalid.');}
		foreach($payload['results']as$result){if(!is_array($result)||!is_string($result['entry_id']??null)||!in_array($result['status']??null,[...PanelComplianceObservation::STATUSES,'missing'],true)||!is_array($result['observations']??null)||!array_is_list($result['observations'])){throw new \InvalidArgumentException('Compliance collection run contains an invalid result.');}}
	}
}
