<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Cohesive MFA, challenge, trusted-device, and session orchestration service. */
final class PanelAuthenticationManager implements \JsonSerializable {
	private string $pepper;
	public function __construct(private readonly PanelAuthenticationStore $store,private readonly PanelAuthenticationCipher $cipher,private readonly PanelOneTimeChallengeAdapter $challengeAdapter,string $pepper){if(strlen($pepper)<16){throw new \InvalidArgumentException('Panel authentication pepper must contain at least 16 bytes.');}$this->pepper=hash('sha256',$pepper,true);}
	public static function memory(string $encryptionKey,string $pepper,?PanelOneTimeChallengeAdapter $adapter=null):self{return new self(new PanelMemoryAuthenticationStore(),new PanelAuthenticationCipher($encryptionKey),$adapter??new PanelLocalOneTimeChallengeAdapter($pepper),$pepper);}
	public static function filesystem(string $directory,string $encryptionKey,string $pepper,?PanelOneTimeChallengeAdapter $adapter=null):self{return new self(new PanelFilesystemAuthenticationStore($directory),new PanelAuthenticationCipher($encryptionKey),$adapter??new PanelLocalOneTimeChallengeAdapter($pepper),$pepper);}
	public function store():PanelAuthenticationStore{return $this->store;}
	public function challengeAdapter():PanelOneTimeChallengeAdapter{return$this->challengeAdapter;}
	public function scoped(PanelAuthenticationAccess $access):PanelScopedAuthenticationManager{return new PanelScopedAuthenticationManager($this,$access);}

	/** Server-side owner resolution. Never expose this result as a public existence oracle. */
	public function ownerOf(string $resource,string $id):?string{
		$id=trim($id);if($id===''){return null;}
		$record=$this->store->get($this->ownershipCollection($resource),$id);
		return $record?->ownerId();
	}
	public function ownedBy(string $resource,string $id,string|int $userId):bool{
		$id=trim($id);if($id===''){return false;}
		$record=$this->store->get($this->ownershipCollection($resource),$id);
		return $record!==null&&$record->ownedBy($this->user($userId));
	}

	/** @param array<string,mixed> $options */
	public function provisionTotp(string|int $userId,string $label='Authenticator',array $options=[]):PanelTotpEnrollment{
		$user=$this->user($userId);$now=(int)($options['now']??time());$factorId=$this->id((string)($options['id']??''),'totp');$secret=isset($options['secret'])?(string)$options['secret']:PanelTotp::generateSecret((int)($options['secret_bytes']??20));PanelTotp::base32Decode($secret);
		$issuer=trim((string)($options['issuer']??'Dataphyre Panel'));$account=trim((string)($options['account']??$user));$label=trim($label)?:'Authenticator';$algorithm=strtolower((string)($options['algorithm']??'sha1'));$digits=(int)($options['digits']??6);$period=(int)($options['period']??30);
		$uri=PanelTotp::provisioningUri($secret,$account,$issuer,['algorithm'=>$algorithm,'digits'=>$digits,'period'=>$period]);$recovery=$this->generateRecoveryCodes((int)($options['recovery_codes']??10));$hashes=array_map(fn(string $code):array=>['code_hash'=>$this->hash('recovery',$this->normalizeRecovery($code)),'used_at'=>null],$recovery);
		$record=PanelAuthenticationRecord::make('factors',$factorId,['user_id'=>$user,'kind'=>'totp','label'=>$label,'issuer'=>$issuer,'secret_ciphertext'=>$this->cipher->encrypt($secret,'totp:'.$factorId.':'.$user),'algorithm'=>$algorithm,'digits'=>$digits,'period'=>$period,'skew'=>max(0,min(3,(int)($options['skew']??1))),'enabled'=>false,'last_counter'=>-1,'recovery_code_hashes'=>$hashes,'disabled_at'=>null],$now);$this->store->create($record);
		return new PanelTotpEnrollment($factorId,$label,$issuer,$secret,$uri,$recovery,$digits,$period);
	}

	public function confirmTotp(string $factorId,string $code,int $timestamp,string|int|null $ownerId=null):PanelAuthenticationDecision{
		$owner=$ownerId===null?null:$this->user($ownerId);return $this->store->transaction(function(PanelAuthenticationTransaction $tx)use($factorId,$code,$timestamp,$owner):PanelAuthenticationDecision{
			$factor=$this->ownedRecord($tx,'factors',$factorId,$owner);if($factor===null||$factor->value('kind')!=='totp'){return new PanelAuthenticationDecision(false,'factor_not_found');}
			$counter=$this->totpCounter($factor,$code,$timestamp);if($counter===null||$counter<=(int)$factor->value('last_counter',-1)){return new PanelAuthenticationDecision(false,'invalid_or_replayed_totp');}
			$tx->save($factor->merge(['enabled'=>true,'last_counter'=>$counter,'disabled_at'=>null],$timestamp));return new PanelAuthenticationDecision(true,'totp_confirmed',null,2,['factor_id'=>$factorId]);
		});
	}

	public function verifyTotp(string|int $userId,string $code,int $timestamp):PanelAuthenticationDecision{
		$user=$this->user($userId);return $this->store->transaction(function(PanelAuthenticationTransaction $tx)use($user,$code,$timestamp):PanelAuthenticationDecision{
			$match=$this->consumeTotp($tx,$user,$code,$timestamp);return $match===null?new PanelAuthenticationDecision(false,'invalid_or_replayed_totp'):new PanelAuthenticationDecision(true,'totp_verified',null,2,['factor_id'=>$match]);
		});
	}

	public function useRecoveryCode(string|int $userId,string $code,?int $now=null):PanelAuthenticationDecision{
		$user=$this->user($userId);$now=$now??time();return $this->store->transaction(function(PanelAuthenticationTransaction $tx)use($user,$code,$now):PanelAuthenticationDecision{
			$match=$this->consumeRecovery($tx,$user,$code,$now);return $match===null?new PanelAuthenticationDecision(false,'invalid_or_used_recovery_code'):new PanelAuthenticationDecision(true,'recovery_code_verified',null,2,['factor_id'=>$match]);
		});
	}

	public function disableTotp(string $factorId,?int $now=null,string|int|null $ownerId=null):bool{
		$owner=$ownerId===null?null:$this->user($ownerId);return $this->store->transaction(function(PanelAuthenticationTransaction $tx)use($factorId,$now,$owner):bool{$factor=$this->ownedRecord($tx,'factors',$factorId,$owner);if($factor===null){return false;}$tx->save($factor->merge(['enabled'=>false,'disabled_at'=>$now??time()],$now));return true;});
	}

	/** @return list<array<string,mixed>> */
	public function factors(string|int $userId):array{
		$user=$this->user($userId);return array_map(static fn(PanelAuthenticationRecord $record):array=>['id'=>$record->id(),'kind'=>$record->value('kind'),'label'=>$record->value('label'),'issuer'=>$record->value('issuer'),'enabled'=>$record->value('enabled')===true,'created_at'=>$record->createdAt(),'updated_at'=>$record->updatedAt(),'recovery_codes_remaining'=>count(array_filter((array)$record->value('recovery_code_hashes',[]),static fn(array $code):bool=>($code['used_at']??null)===null))],$this->store->all('factors',['user_id'=>$user]));
	}

	/** @param array<string,mixed> $options */
	public function beginChallenge(string|int $userId,string $purpose,string $method='totp',array $options=[]):PanelStepUpChallenge{
		$user=$this->user($userId);$purpose=$this->bounded($purpose,200,'Step-up challenge purpose is required.');$method=strtolower(trim($method));if(!in_array($method,['totp','recovery','email'],true)){throw new \InvalidArgumentException('Unsupported step-up challenge method.');}
		$now=(int)($options['now']??time());$ttl=max(30,min(1800,(int)($options['ttl_seconds']??300)));$id=$this->id((string)($options['id']??''),'challenge');$codeHash=null;$recipientHint=null;
		$sessionId=isset($options['session_id'])?$this->optionalId($options['session_id']):null;if($sessionId!==null){$session=$this->store->get('sessions',$sessionId);if($session===null||$session->value('user_id')!==$user||$session->value('revoked_at')!==null||$now>=(int)$session->value('expires_at')){throw new \InvalidArgumentException('Step-up challenge session is unavailable for this user.');}}
		if($method==='email'){$recipient=trim((string)($options['recipient']??''));if($recipient===''){throw new \InvalidArgumentException('Email challenge recipient is required.');}$dispatch=$this->challengeAdapter->dispatch($id,$recipient,$purpose,$now+$ttl,['user_id'=>$user]);$codeHash=$this->hash('challenge:'.$id,$dispatch->code());$recipientHint=$this->recipientHint($recipient);}
		$record=$this->store->create(PanelAuthenticationRecord::make('challenges',$id,[
			'user_id'=>$user,'purpose'=>$purpose,'method'=>$method,'status'=>'pending','attempts'=>0,
			'max_attempts'=>max(1,min(10,(int)($options['max_attempts']??5))),'expires_at'=>$now+$ttl,
			'consumed_at'=>null,'code_hash'=>$codeHash,'recipient_hint'=>$recipientHint,
			'session_id'=>$sessionId,
			'required_level'=>max(1,min(3,(int)($options['required_level']??2))),
			'metadata'=>is_array($options['metadata']??null)?$options['metadata']:[],
		],$now));
		return PanelStepUpChallenge::fromRecord($record);
	}

	public function challenge(string $id,string|int|null $ownerId=null):?PanelStepUpChallenge{$owner=$ownerId===null?null:$this->user($ownerId);$record=$this->store->get('challenges',$id);if($record===null||($owner!==null&&!$record->ownedBy($owner))){return null;}return PanelStepUpChallenge::fromRecord($record);}

	public function verifyChallenge(string $challengeId,string $response,?int $now=null,string|int|null $ownerId=null):PanelAuthenticationDecision{
		$now=$now??time();$owner=$ownerId===null?null:$this->user($ownerId);return $this->store->transaction(function(PanelAuthenticationTransaction $tx)use($challengeId,$response,$now,$owner):PanelAuthenticationDecision{
			$record=$this->ownedRecord($tx,'challenges',$challengeId,$owner);if($record===null){return new PanelAuthenticationDecision(false,'challenge_not_found',$challengeId);}$status=(string)$record->value('status');
			if($status==='verified'||$record->value('consumed_at')!==null){return new PanelAuthenticationDecision(false,'challenge_replayed',$challengeId);}
			if($status!=='pending'){return new PanelAuthenticationDecision(false,'challenge_'.$status,$challengeId);}
			if($now>=(int)$record->value('expires_at')){$tx->save($record->merge(['status'=>'expired'],$now));return new PanelAuthenticationDecision(false,'challenge_expired',$challengeId);}
			$attempts=(int)$record->value('attempts')+1;$user=(string)$record->value('user_id');$method=(string)$record->value('method');$verified=false;
			if($method==='email'){$normalized=preg_replace('/[\s-]+/','',trim($response))??'';$actual=$this->hash('challenge:'.$challengeId,$normalized);$expected=(string)$record->value('code_hash','');$verified=$expected!==''&&hash_equals($expected,$actual);}
			elseif($method==='totp'){$verified=$this->consumeTotp($tx,$user,$response,$now)!==null;}
			elseif($method==='recovery'){$verified=$this->consumeRecovery($tx,$user,$response,$now)!==null;}
			if(!$verified){$locked=$attempts>=(int)$record->value('max_attempts');$tx->save($record->merge(['attempts'=>$attempts,'status'=>$locked?'locked':'pending'],$now));return new PanelAuthenticationDecision(false,$locked?'challenge_locked':'challenge_invalid',$challengeId);}
			$level=(int)$record->value('required_level',2);$tx->save($record->merge(['attempts'=>$attempts,'status'=>'verified','consumed_at'=>$now],$now));$sessionId=$record->value('session_id');
			if(is_string($sessionId)&&$sessionId!==''){$session=$tx->get('sessions',$sessionId);if($session!==null&&$session->value('user_id')===$user&&$session->value('revoked_at')===null&&$now<(int)$session->value('expires_at')){$tx->save($session->merge(['authentication_level'=>max($level,(int)$session->value('authentication_level',1)),'step_up_at'=>$now,'last_seen_at'=>$now],$now));}}
			return new PanelAuthenticationDecision(true,'challenge_verified',$challengeId,$level,['method'=>$method]);
		});
	}

	public function cancelChallenge(string $challengeId,?int $now=null,string|int|null $ownerId=null):bool{$owner=$ownerId===null?null:$this->user($ownerId);return $this->store->transaction(function(PanelAuthenticationTransaction $tx)use($challengeId,$now,$owner):bool{$record=$this->ownedRecord($tx,'challenges',$challengeId,$owner);if($record===null||$record->value('status')!=='pending'){return false;}$tx->save($record->merge(['status'=>'cancelled'],$now??time()));return true;});}
	public function expireChallenges(?int $now=null):int{$now=$now??time();return $this->store->transaction(static function(PanelAuthenticationTransaction $tx)use($now):int{$count=0;foreach($tx->all('challenges',['status'=>'pending'])as$record){if($now>=(int)$record->value('expires_at')){$tx->save($record->merge(['status'=>'expired'],$now));$count++;}}return$count;});}

	/** @param array<string,mixed> $options */
	public function trustDevice(string|int $userId,string $label,string $fingerprint,array $options=[]):PanelTrustedDeviceCredential{
		$user=$this->user($userId);$label=$this->bounded($label,200,'Trusted device');if(trim($fingerprint)===''){throw new \InvalidArgumentException('Trusted device fingerprint is required.');}$now=(int)($options['now']??time());$ttl=max(300,min(31536000,(int)($options['ttl_seconds']??2592000)));$id=$this->id((string)($options['id']??''),'device');$token=$this->token();
		$record=$this->store->create(PanelAuthenticationRecord::make('devices',$id,['user_id'=>$user,'label'=>$label,'token_hash'=>$this->hash('device-token:'.$id,$token),'fingerprint_hash'=>$this->hash('device-fingerprint:'.$id,$fingerprint),'last_seen_at'=>$now,'expires_at'=>$now+$ttl,'revoked_at'=>null,'metadata'=>is_array($options['metadata']??null)?$options['metadata']:[]],$now));return new PanelTrustedDeviceCredential(PanelTrustedDevice::fromRecord($record),$token);
	}

	public function verifyTrustedDevice(string $deviceId,string $token,string $fingerprint,?int $now=null,string|int|null $ownerId=null):?PanelTrustedDevice{
		$now=$now??time();$owner=$ownerId===null?null:$this->user($ownerId);return $this->store->transaction(function(PanelAuthenticationTransaction $tx)use($deviceId,$token,$fingerprint,$now,$owner):?PanelTrustedDevice{$record=$this->ownedRecord($tx,'devices',$deviceId,$owner);if($record===null){return null;}$tokenValid=hash_equals((string)$record->value('token_hash',''),$this->hash('device-token:'.$deviceId,$token));$fingerprintValid=hash_equals((string)$record->value('fingerprint_hash',''),$this->hash('device-fingerprint:'.$deviceId,$fingerprint));if(!$tokenValid||!$fingerprintValid||$record->value('revoked_at')!==null||$now>=(int)$record->value('expires_at')){return null;}$record=$tx->save($record->merge(['last_seen_at'=>$now],$now));return PanelTrustedDevice::fromRecord($record);});
	}

	public function revokeDevice(string $deviceId,?int $now=null,string|int|null $ownerId=null):bool{$owner=$ownerId===null?null:$this->user($ownerId);return $this->store->transaction(function(PanelAuthenticationTransaction $tx)use($deviceId,$now,$owner):bool{$record=$this->ownedRecord($tx,'devices',$deviceId,$owner);if($record===null){return false;}$tx->save($record->merge(['revoked_at'=>$now??time()],$now));foreach($tx->all('sessions',['device_id'=>$deviceId,'user_id'=>$record->ownerId()])as$session){if($session->value('revoked_at')===null){$tx->save($session->merge(['revoked_at'=>$now??time()],$now));}}return true;});}
	/** @return list<PanelTrustedDevice> */ public function devices(string|int $userId):array{return array_map(PanelTrustedDevice::fromRecord(...),$this->store->all('devices',['user_id'=>$this->user($userId)]));}
	public function revokeAllDevices(string|int $userId,?int $now=null):int{
		$user=$this->user($userId);$now=$now??time();return $this->store->transaction(static function(PanelAuthenticationTransaction $tx)use($user,$now):int{$count=0;$deviceIds=[];foreach($tx->all('devices',['user_id'=>$user])as$device){if($device->value('revoked_at')===null){$tx->save($device->merge(['revoked_at'=>$now],$now));$deviceIds[]=$device->id();$count++;}}foreach($tx->all('sessions',['user_id'=>$user])as$session){if(in_array($session->value('device_id'),$deviceIds,true)&&$session->value('revoked_at')===null){$tx->save($session->merge(['revoked_at'=>$now],$now));}}return$count;});
	}

	/** @param array<string,mixed> $options */
	public function createSession(string|int $userId,array $options=[]):PanelAuthenticationSessionCredential{
		$user=$this->user($userId);$now=(int)($options['now']??time());$ttl=max(60,min(31536000,(int)($options['ttl_seconds']??28800)));$id=$this->id((string)($options['id']??''),'session');$token=$this->token();$device=isset($options['device_id'])?$this->optionalId($options['device_id']):null;if($device!==null){$trusted=$this->store->get('devices',$device);if($trusted===null||$trusted->value('user_id')!==$user||$trusted->value('revoked_at')!==null||$now>=(int)$trusted->value('expires_at')){throw new \InvalidArgumentException('Session trusted device is unavailable for this user.');}}
		$record=$this->store->create(PanelAuthenticationRecord::make('sessions',$id,['user_id'=>$user,'device_id'=>$device,'token_hash'=>$this->hash('session-token:'.$id,$token),'last_seen_at'=>$now,'expires_at'=>$now+$ttl,'authentication_level'=>max(1,min(3,(int)($options['authentication_level']??1))),'step_up_at'=>null,'revoked_at'=>null,'ip_hash'=>isset($options['ip'])?$this->hash('session-ip',(string)$options['ip']):null,'user_agent_hash'=>isset($options['user_agent'])?$this->hash('session-agent',(string)$options['user_agent']):null,'metadata'=>is_array($options['metadata']??null)?$options['metadata']:[]],$now));return new PanelAuthenticationSessionCredential(PanelAuthenticationSession::fromRecord($record),$token);
	}

	public function authenticateSession(string $sessionId,string $token,?int $now=null,string|int|null $ownerId=null):?PanelAuthenticationSession{
		$now=$now??time();$owner=$ownerId===null?null:$this->user($ownerId);return $this->store->transaction(function(PanelAuthenticationTransaction $tx)use($sessionId,$token,$now,$owner):?PanelAuthenticationSession{$record=$this->ownedRecord($tx,'sessions',$sessionId,$owner);if($record===null){return null;}$valid=hash_equals((string)$record->value('token_hash',''),$this->hash('session-token:'.$sessionId,$token));if(!$valid||$record->value('revoked_at')!==null||$now>=(int)$record->value('expires_at')){return null;}$deviceId=$record->value('device_id');if(is_string($deviceId)&&$deviceId!==''){$device=$this->ownedRecord($tx,'devices',$deviceId,(string)$record->value('user_id'));if($device===null||$device->value('revoked_at')!==null||$now>=(int)$device->value('expires_at')){return null;}}$record=$tx->save($record->merge(['last_seen_at'=>$now],$now));return PanelAuthenticationSession::fromRecord($record);});
	}

	public function revokeSession(string $sessionId,?int $now=null,string|int|null $ownerId=null):bool{$owner=$ownerId===null?null:$this->user($ownerId);return $this->store->transaction(function(PanelAuthenticationTransaction $tx)use($sessionId,$now,$owner):bool{$record=$this->ownedRecord($tx,'sessions',$sessionId,$owner);if($record===null){return false;}$tx->save($record->merge(['revoked_at'=>$now??time()],$now));return true;});}
	/** @return list<PanelAuthenticationSession> */ public function sessions(string|int $userId):array{return array_map(PanelAuthenticationSession::fromRecord(...),$this->store->all('sessions',['user_id'=>$this->user($userId)]));}
	public function revokeAllSessions(string|int $userId,?string $exceptSessionId=null,?int $now=null):int{
		$user=$this->user($userId);$now=$now??time();return $this->store->transaction(static function(PanelAuthenticationTransaction $tx)use($user,$exceptSessionId,$now):int{$count=0;foreach($tx->all('sessions',['user_id'=>$user])as$session){if($session->id()!==$exceptSessionId&&$session->value('revoked_at')===null){$tx->save($session->merge(['revoked_at'=>$now],$now));$count++;}}return$count;});
	}

	public function jsonSerialize():array{return ['type'=>'panel_authentication_manager','capabilities'=>['totp'=>true,'recovery_codes'=>true,'one_time_challenges'=>true,'trusted_devices'=>true,'sessions'=>true,'atomic_transactions'=>true,'owner_scopes'=>true,'cross_user_ability'=>PanelAuthenticationAccess::CROSS_USER_ABILITY],'store'=>$this->store::class,'challenge_adapter'=>$this->challengeAdapter::class];}

	private function consumeTotp(PanelAuthenticationTransaction $tx,string $user,string $code,int $timestamp):?string{
		$matched=null;$matchedCounter=null;$matchedRecord=null;foreach($tx->all('factors',['user_id'=>$user,'kind'=>'totp','enabled'=>true])as$factor){$counter=$this->totpCounter($factor,$code,$timestamp);$fresh=$counter!==null&&$counter>(int)$factor->value('last_counter',-1);if($fresh&&$matched===null){$matched=$factor->id();$matchedCounter=$counter;$matchedRecord=$factor;}}
		if($matchedRecord!==null){$tx->save($matchedRecord->merge(['last_counter'=>$matchedCounter],$timestamp));}return$matched;
	}
	private function totpCounter(PanelAuthenticationRecord $factor,string $code,int $timestamp):?int{$user=(string)$factor->value('user_id');$secret=$this->cipher->decrypt((string)$factor->value('secret_ciphertext'),'totp:'.$factor->id().':'.$user);return PanelTotp::matchingCounter($secret,$code,$timestamp,['algorithm'=>$factor->value('algorithm','sha1'),'digits'=>(int)$factor->value('digits',6),'period'=>(int)$factor->value('period',30),'skew'=>max(0,min(3,(int)$factor->value('skew',1)))]);}
	private function consumeRecovery(PanelAuthenticationTransaction $tx,string $user,string $code,int $now):?string{
		$actual=$this->hash('recovery',$this->normalizeRecovery($code));$matchedFactor=null;$matchedIndex=null;$matchedCodes=[];
		foreach($tx->all('factors',['user_id'=>$user,'kind'=>'totp','enabled'=>true])as$factor){$codes=(array)$factor->value('recovery_code_hashes',[]);foreach($codes as$index=>$entry){$expected=is_array($entry)?(string)($entry['code_hash']??''):'';$unused=is_array($entry)&&($entry['used_at']??null)===null;$valid=$expected!==''&&hash_equals($expected,$actual);if($valid&&$unused&&$matchedFactor===null){$matchedFactor=$factor;$matchedIndex=$index;$matchedCodes=$codes;}}}
		if($matchedFactor!==null&&$matchedIndex!==null){$matchedCodes[$matchedIndex]['used_at']=$now;$tx->save($matchedFactor->merge(['recovery_code_hashes'=>$matchedCodes],$now));return$matchedFactor->id();}return null;
	}
	private function ownedRecord(PanelAuthenticationTransaction $tx,string $collection,string $id,?string $owner):?PanelAuthenticationRecord{
		$record=$tx->get($collection,$id);if($record===null){return null;}return $owner===null||$record->ownedBy($owner)?$record:null;
	}
	private function ownershipCollection(string $resource):string{
		return match(strtolower(trim($resource))){
			'factor','factors','totp'=>'factors',
			'challenge','challenges','step_up'=>'challenges',
			'device','devices','trusted_device','trusted_devices'=>'devices',
			'session','sessions'=>'sessions',
			default=>throw new \InvalidArgumentException('Unsupported authentication ownership resource.'),
		};
	}
	/** @return list<string> */ private function generateRecoveryCodes(int $count):array{$count=max(4,min(20,$count));$codes=[];for($i=0;$i<$count;$i++){$raw=PanelTotp::base32Encode(random_bytes(10));$codes[]=implode('-',str_split(substr($raw,0,16),4));}return$codes;}
	private function normalizeRecovery(string $code):string{return strtoupper(preg_replace('/[^A-Za-z0-9]/','',trim($code))??'');}
	private function hash(string $purpose,string $value):string{return hash_hmac('sha256',$purpose."\0".$value,$this->pepper);}
	private function token():string{return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');}
	private function id(string $provided,string $prefix):string{$provided=trim($provided);if($provided!==''){if(strlen($provided)>190||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D',$provided)!==1){throw new \InvalidArgumentException('Invalid authentication identifier.');}return$provided;}return$prefix.'_'.bin2hex(random_bytes(12));}
	private function optionalId(mixed $value):?string{$value=trim((string)$value);return$value===''?null:$this->id($value,'id');}
	private function user(string|int $user):string{$user=trim((string)$user);if($user===''||strlen($user)>190||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D',$user)!==1){throw new \InvalidArgumentException('Authentication user id is required.');}return$user;}
	private function bounded(string $value,int $max,string $fallback):string{$value=trim($value);if($value===''){$value=$fallback;}if(strlen($value)>$max){throw new \LengthException('Authentication label exceeds its maximum length.');}return$value;}
	private function recipientHint(string $recipient):string{if(str_contains($recipient,'@')){[$local,$domain]=explode('@',$recipient,2);return substr($local,0,1).'***@'.$domain;}return'***'.substr($recipient,-2);}
}
