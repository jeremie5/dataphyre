<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Instance-scoped IAM control plane with fail-closed policy, fencing, receipts, and audit. */
final class PanelIamManager implements \JsonSerializable {
	private readonly \Closure $clock;
	private readonly ?\Closure $authorizer;
	/** @var array<string,string> */ private array $auditKeys;
	private string $currentAuditKeyId;
	/** @var list<string> */ private array $highRiskPermissions;
	private int $auditRetention;
	private int $receiptRetention;
	private bool $requireApproval;

	/** @param array<string,mixed> $options */
	public function __construct(private readonly PanelIamStore $store,string|array $auditKeys,?callable $authorizer=null,array $options=[]){
		[$this->auditKeys,$this->currentAuditKeyId]=self::keyring($auditKeys,$options['current_audit_key_id']??null);
		$this->authorizer=$authorizer!==null?\Closure::fromCallable($authorizer):null;
		$this->clock=is_callable($options['clock']??null)?\Closure::fromCallable($options['clock']):static fn():string=>gmdate(DATE_ATOM);
		$this->auditRetention=max(8,min(10000,(int)($options['audit_retention']??1000)));
		$this->receiptRetention=max(16,min(50000,(int)($options['receipt_retention']??10000)));
		$this->requireApproval=($options['require_high_risk_approval']??true)!==false;
		$this->highRiskPermissions=PanelIamGuard::names(is_array($options['high_risk_permissions']??null)||is_string($options['high_risk_permissions']??null)?$options['high_risk_permissions']:['iam.*','security.*','tenant.owner'],'high-risk permission');
	}

	public function store():PanelIamStore{return$this->store;}
	public function authorizationConfigured():bool{return$this->authorizer!==null;}
	public function auditRetention():int{return$this->auditRetention;}
	public function receiptRetention():int{return$this->receiptRetention;}
	public function requiresHighRiskApproval():bool{return$this->requireApproval;}
	/** @return list<string> */ public function highRiskPermissions():array{return$this->highRiskPermissions;}
	public function currentAuditKeyId():string{return$this->currentAuditKeyId;}
	/** @return list<string> */ public function auditKeyIds():array{$ids=array_keys($this->auditKeys);sort($ids,SORT_STRING);return$ids;}
	public function scope(string|int $tenantId,string|int $actorId):PanelScopedIamManager{return new PanelScopedIamManager($this,PanelIamGuard::identifier($tenantId,'tenant id'),PanelIamGuard::identifier($actorId,'actor id'));}
	public function authorizeQuery(PanelIamQuery $query):void {$this->authorize($query->ability(),$query,null,null);}

	public function createPrincipal(PanelIamMutation $mutation,PanelIamPrincipal $principal):PanelIamReceipt {
		$mutation->assert('principal.create','principal',$principal->id());if($mutation->expectedRevision()!==null){throw new PanelIamConflict('IAM principal creation expects no existing revision.');}
		$digest=self::payloadDigest($principal->storagePayload());
		return$this->commit($mutation,['record_digest'=>$digest],function(array &$state,string $now)use($mutation,$principal):array{
			if(isset($state['principals'][$principal->id()])||isset($state['service_accounts'][$principal->id()])){throw new PanelIamConflict('IAM subject already exists in this tenant.');}
			$this->authorize('iam.principal.create',$mutation,null,$principal);
			$stored=$principal->withRevision(1,$now);$state['principals'][$stored->id()]=$stored->storagePayload();return[$stored->revision(),$stored->status(),['subject_type'=>'principal']];
		});
	}

	public function createServiceAccount(PanelIamMutation $mutation,PanelIamServiceAccount $account):PanelIamReceipt {
		$mutation->assert('service.create','service',$account->id());if($mutation->expectedRevision()!==null){throw new PanelIamConflict('IAM service-account creation expects no existing revision.');}
		$digest=self::payloadDigest($account->storagePayload());
		return$this->commit($mutation,['record_digest'=>$digest],function(array &$state,string $now)use($mutation,$account):array{
			if(isset($state['service_accounts'][$account->id()])||isset($state['principals'][$account->id()])){throw new PanelIamConflict('IAM subject already exists in this tenant.');}
			$this->authorize('iam.service.create',$mutation,null,$account);
			$stored=$account->withRevision(1,$now);$state['service_accounts'][$stored->id()]=$stored->storagePayload();return[$stored->revision(),$stored->status(),['subject_type'=>'service']];
		});
	}

	/** @param array|string $roles @param array|string $permissions @param array<string,mixed> $options */
	public function grant(PanelIamMutation $mutation,array|string $roles=[],array|string $permissions=[],array $options=[]):PanelIamReceipt {
		$mutation->assert('membership.grant',$mutation->subjectType(),$mutation->subjectId());$roles=PanelIamGuard::names($roles,'role');$permissions=PanelIamGuard::names($permissions,'permission');
		if($roles===[]&&$permissions===[]){throw new \InvalidArgumentException('Panel IAM grants require at least one role or permission.');}
		$expires=PanelIamGuard::instant(is_int($options['expires_at']??null)||is_string($options['expires_at']??null)?$options['expires_at']:null,'membership expires_at',true);$metadata=is_array($options['metadata']??null)?PanelIamGuard::metadata($options['metadata']):[];
		$payload=['roles'=>$roles,'permissions'=>$permissions,'expires_at'=>$expires,'metadata'=>$metadata];
		return$this->commit($mutation,$payload,function(array &$state,string $now)use($mutation,$roles,$permissions,$expires,$metadata):array{
			$this->assertSubjectExists($state,$mutation->subjectType(),$mutation->subjectId());$key=self::membershipKey($mutation->subjectType(),$mutation->subjectId());$current=isset($state['memberships'][$key])?PanelIamMembership::restore($state['memberships'][$key]):null;$this->expected($mutation,$current?->revision());
			$highRisk=$this->isHighRisk($permissions);if($highRisk){$this->assertApproval($mutation);}$ability=$highRisk?'iam.membership.grant.high_risk':'iam.membership.grant';
			$proposed=$current instanceof PanelIamMembership?$current->evolve(['roles'=>array_values(array_unique(array_merge($current->roles(),$roles))),'permissions'=>array_values(array_unique(array_merge($current->permissions(),$permissions))),'status'=>'active','expires_at'=>$expires??$current->expiresAt(),'metadata'=>array_replace($current->metadata(),$metadata)],$current->revision()+1,$now):PanelIamMembership::make($mutation->tenantId(),$mutation->subjectType(),$mutation->subjectId(),$roles,$permissions,['status'=>'active','expires_at'=>$expires,'revision'=>1,'metadata'=>$metadata,'now'=>$now]);
			$this->authorize($ability,$mutation,$current,$proposed);$state['memberships'][$key]=$proposed->storagePayload();return[$proposed->revision(),$proposed->status(),['high_risk'=>$highRisk,'role_count'=>count($proposed->roles()),'permission_count'=>count($proposed->permissions())]];
		});
	}

	public function revoke(PanelIamMutation $mutation):PanelIamReceipt{return$this->membershipStatus($mutation,'membership.revoke','revoked','iam.membership.revoke');}
	public function suspend(PanelIamMutation $mutation):PanelIamReceipt{return$this->membershipStatus($mutation,'membership.suspend','suspended','iam.membership.suspend');}
	public function restore(PanelIamMutation $mutation):PanelIamReceipt{return$this->membershipStatus($mutation,'membership.restore','active','iam.membership.restore');}

	/** @param array<string,mixed> $metadata */
	public function rotateServiceCredential(PanelIamMutation $mutation,array $metadata):PanelIamReceipt {
		$mutation->assert('service.rotate_credential','service',$mutation->subjectId());$metadata=PanelIamGuard::credentialMetadata($metadata,true);$digest=self::payloadDigest($metadata);
		return$this->commit($mutation,['rotation_digest'=>$digest],function(array &$state,string $now)use($mutation,$metadata):array{
			$payload=$state['service_accounts'][$mutation->subjectId()]??null;if(!is_array($payload)){throw new \OutOfBoundsException('IAM service account does not exist in this tenant.');}$current=PanelIamServiceAccount::restore($payload);$this->expected($mutation,$current->revision());
			$proposed=$current->rotateCredential($metadata,$now)->withRevision($current->revision()+1,$now);$this->authorize('iam.service.rotate_credential',$mutation,$current,$proposed);$state['service_accounts'][$proposed->id()]=$proposed->storagePayload();return[$proposed->revision(),$proposed->status(),['key_id'=>$metadata['key_id'],'version'=>$metadata['version'],'material_persisted'=>false]];
		});
	}

	/** Trusted-internal unscoped read. Request-facing callers should use scope(). */
	public function principal(string|int $tenantId,string|int $principalId):?PanelIamPrincipal {
		$tenant=PanelIamGuard::identifier($tenantId,'tenant id');$id=PanelIamGuard::identifier($principalId,'principal id');$payload=$this->store->read($tenant)['principals'][$id]??null;return is_array($payload)?PanelIamPrincipal::restore($payload):null;
	}
	/** Trusted-internal unscoped read. Request-facing callers should use scope(). */
	public function serviceAccount(string|int $tenantId,string|int $accountId):?PanelIamServiceAccount {
		$tenant=PanelIamGuard::identifier($tenantId,'tenant id');$id=PanelIamGuard::identifier($accountId,'service account id');$payload=$this->store->read($tenant)['service_accounts'][$id]??null;return is_array($payload)?PanelIamServiceAccount::restore($payload):null;
	}
	/** Trusted-internal unscoped read. Request-facing callers should use scope(). */
	public function membership(string|int $tenantId,string $subjectType,string|int $subjectId):?PanelIamMembership {
		$tenant=PanelIamGuard::identifier($tenantId,'tenant id');$type=PanelIamGuard::subjectType($subjectType);$id=PanelIamGuard::identifier($subjectId,'subject id');$payload=$this->store->read($tenant)['memberships'][self::membershipKey($type,$id)]??null;return is_array($payload)?PanelIamMembership::restore($payload):null;
	}

	/** Trusted-internal unscoped read. Request-facing callers should use scope(). @return list<PanelIamPrincipal> */
	public function principals(string|int $tenantId,int $limit=100):array {$state=$this->store->read(PanelIamGuard::identifier($tenantId,'tenant id'));$items=array_map(static fn(array $payload):PanelIamPrincipal=>PanelIamPrincipal::restore($payload),array_values($state['principals']));usort($items,static fn(PanelIamPrincipal $a,PanelIamPrincipal $b):int=>[$a->displayName(),$a->id()]<=>[$b->displayName(),$b->id()]);return array_slice($items,0,self::limit($limit));}
	/** Trusted-internal unscoped read. Request-facing callers should use scope(). @return list<PanelIamServiceAccount> */
	public function serviceAccounts(string|int $tenantId,int $limit=100):array {$state=$this->store->read(PanelIamGuard::identifier($tenantId,'tenant id'));$items=array_map(static fn(array $payload):PanelIamServiceAccount=>PanelIamServiceAccount::restore($payload),array_values($state['service_accounts']));usort($items,static fn(PanelIamServiceAccount $a,PanelIamServiceAccount $b):int=>[$a->displayName(),$a->id()]<=>[$b->displayName(),$b->id()]);return array_slice($items,0,self::limit($limit));}

	/** Trusted-internal unscoped read. Request-facing callers should use scope(). @param array<string,mixed> $criteria @return list<PanelIamMembership> */
	public function memberships(string|int $tenantId,array $criteria=[],int $limit=100):array {
		$tenant=PanelIamGuard::identifier($tenantId,'tenant id');$criteria=PanelIamGuard::metadata($criteria);$items=[];
		foreach($this->store->read($tenant)['memberships']as$payload){$membership=PanelIamMembership::restore($payload);if(isset($criteria['subject_type'])&&$membership->subjectType()!==PanelIamGuard::subjectType((string)$criteria['subject_type'])){continue;}if(isset($criteria['status'])&&$membership->status()!==PanelIamGuard::status((string)$criteria['status'])){continue;}if(isset($criteria['role'])&&!in_array(strtolower((string)$criteria['role']),$membership->roles(),true)){continue;}if(isset($criteria['permission'])&&!in_array(strtolower((string)$criteria['permission']),$membership->permissions(),true)){continue;}if(array_key_exists('active',$criteria)&&$membership->activeAt($criteria['at']??null)!==($criteria['active']===true)){continue;}$items[]=$membership;}
		usort($items,static fn(PanelIamMembership $a,PanelIamMembership $b):int=>[$a->subjectType(),$a->subjectId()]<=>[$b->subjectType(),$b->subjectId()]);return array_slice($items,0,self::limit($limit));
	}

	/** Trusted-internal unscoped read. Request-facing callers should use scope(). @return list<PanelIamAuditEvent> */
	public function audit(string|int $tenantId,int $afterSequence=0,int $limit=100):array {
		$tenant=PanelIamGuard::identifier($tenantId,'tenant id');$state=$this->store->read($tenant);$this->assertAudit($state,$tenant);$events=[];foreach($state['audit']['events']as$payload){$event=PanelIamAuditEvent::restore($payload);if($event->sequence()>max(0,$afterSequence)){$events[]=$event;}if(count($events)>=self::limit($limit)){break;}}return$events;
	}
	public function verifyAudit(string|int $tenantId):bool {$tenant=PanelIamGuard::identifier($tenantId,'tenant id');try{$this->assertAudit($this->store->read($tenant),$tenant);return true;}catch(\Throwable){return false;}}

	public function manifest():PanelIamManifest{return PanelIamManifest::inspect($this);}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return$this->manifest()->jsonSerialize();}

	private function membershipStatus(PanelIamMutation $mutation,string $operation,string $status,string $ability):PanelIamReceipt {
		$mutation->assert($operation,$mutation->subjectType(),$mutation->subjectId());
		return$this->commit($mutation,['status'=>$status],function(array &$state,string $now)use($mutation,$status,$ability):array{
			$key=self::membershipKey($mutation->subjectType(),$mutation->subjectId());$payload=$state['memberships'][$key]??null;if(!is_array($payload)){throw new \OutOfBoundsException('IAM membership does not exist in this tenant.');}$current=PanelIamMembership::restore($payload);$this->expected($mutation,$current->revision());if($current->status()===$status){throw new PanelIamConflict('IAM membership already has the requested status.');}
			if($status==='active'){$this->assertSubjectExists($state,$mutation->subjectType(),$mutation->subjectId());}$proposed=$current->evolve(['status'=>$status],$current->revision()+1,$now);$this->authorize($ability,$mutation,$current,$proposed);$state['memberships'][$key]=$proposed->storagePayload();return[$proposed->revision(),$proposed->status(),['previous_status'=>$current->status()]];
		});
	}

	/** @param array<string,mixed> $fingerprintPayload @param callable(array<string,mixed>&,string):array{int,string,array<string,mixed>} $apply */
	private function commit(PanelIamMutation $mutation,array $fingerprintPayload,callable $apply):PanelIamReceipt {
		$fingerprint=$mutation->fingerprint($fingerprintPayload);$digest=$mutation->idempotencyDigest();
		return$this->store->transaction($mutation->tenantId(),function(array &$state)use($mutation,$fingerprint,$digest,$apply):PanelIamReceipt{
			$this->assertAudit($state,$mutation->tenantId());$existing=$state['receipts'][$digest]??null;
			if(is_array($existing)){if(!hash_equals((string)($existing['fingerprint']??''),$fingerprint)){throw new PanelIamConflict('IAM idempotency key was reused for a different mutation.');}$receipt=PanelIamReceipt::restore($existing);$this->authorize($this->replayAbility($mutation,$receipt),$mutation,$receipt,$receipt);return$receipt->asReplay();}
			$now=$this->now();[$revision,$status,$metadata]=$apply($state,$now);$metadata=PanelIamGuard::metadata($metadata);$receiptId=hash('sha256',$digest.$fingerprint);$previous=$state['audit']['events']!==[]?(string)$state['audit']['events'][array_key_last($state['audit']['events'])]['hash']:(string)$state['audit']['anchor_hash'];$sequence=(int)$state['audit']['sequence']+1;
			$audit=PanelIamAuditEvent::make($sequence,$mutation,$receiptId,$previous,$metadata,$this->currentAuditKeyId,$this->auditKeys[$this->currentAuditKeyId],$now);$state['audit']['sequence']=$sequence;$state['audit']['events'][]=$audit->storagePayload();$this->pruneAudit($state);
			$receipt=PanelIamReceipt::restore(['id'=>$receiptId,'operation'=>$mutation->operation(),'tenant_id'=>$mutation->tenantId(),'subject_type'=>$mutation->subjectType(),'subject_id'=>$mutation->subjectId(),'actor_id'=>$mutation->actorId(),'requester_id'=>$mutation->requesterId(),'approver_id'=>$mutation->approverId(),'reason'=>$mutation->reason(),'idempotency_digest'=>$digest,'fingerprint'=>$fingerprint,'revision'=>$revision,'status'=>$status,'occurred_at'=>$now,'audit_hash'=>$audit->hash(),'metadata'=>$metadata]);
			$state['receipts'][$digest]=$receipt->storagePayload();$state['receipt_order'][]=$digest;$this->pruneReceipts($state);return$receipt;
		},'iam.'.$mutation->operation(),['actor_hash'=>hash('sha256',$mutation->actorId()),'subject_type'=>$mutation->subjectType()]);
	}

	private function authorize(string $ability,PanelIamMutation|PanelIamQuery $command,mixed $current,mixed $proposed):void {
		if($this->authorizer===null){throw new PanelIamAuthorizationException('Panel IAM authorization is not configured.');}
		try{$decision=($this->authorizer)($ability,$command,$current,$proposed,$this);$allowed=$decision===true||($decision instanceof PanelSecurityDecision&&$decision->allowed())||(is_array($decision)&&($decision['allowed']??false)===true);}
		catch(PanelIamAuthorizationException $exception){throw$exception;}catch(\Throwable $exception){throw new PanelIamAuthorizationException('Panel IAM mutation authorization failed closed.',0,$exception);}
		if(!$allowed){throw new PanelIamAuthorizationException('Panel IAM operation is not authorized.');}
	}

	/** @param array<string,mixed> $state */
	private function assertSubjectExists(array $state,string $type,string $id):void {
		$payload=$type==='principal'?($state['principals'][$id]??null):($state['service_accounts'][$id]??null);if(!is_array($payload)){throw new \OutOfBoundsException('IAM subject does not exist in this tenant.');}$status=$type==='principal'?PanelIamPrincipal::restore($payload)->status():PanelIamServiceAccount::restore($payload)->status();if($status!=='active'){throw new PanelIamConflict('IAM subject is not active.');}
	}

	private function expected(PanelIamMutation $mutation,?int $current):void {
		$expected=$mutation->expectedRevision();if($current===null){if($expected!==null&&$expected!==0){throw new PanelIamConflict('IAM expected revision does not match absent state.');}return;}if($expected===null||$expected!==$current){throw new PanelIamConflict('IAM state revision conflict.');}
	}

	/** @param list<string> $permissions */
	private function isHighRisk(array $permissions):bool {foreach($permissions as$permission){foreach($this->highRiskPermissions as$pattern){if($pattern==='*'||hash_equals($pattern,$permission)||(str_ends_with($pattern,'.*')&&str_starts_with($permission,substr($pattern,0,-1)))){return true;}}}return false;}
	private function replayAbility(PanelIamMutation $mutation,PanelIamReceipt $receipt):string {return match($mutation->operation()){'membership.grant'=>(($receipt->storagePayload()['metadata']['high_risk']??false)===true?'iam.membership.grant.high_risk':'iam.membership.grant'),default=>'iam.'.$mutation->operation()};}
	private function assertApproval(PanelIamMutation $mutation):void {if(!$this->requireApproval){return;}$approver=$mutation->approverId();if($approver===null||hash_equals($approver,$mutation->requesterId())||!hash_equals($approver,$mutation->actorId())){throw new PanelIamAuthorizationException('High-risk IAM grants require a distinct requester and acting approver.');}}

	/** @param array<string,mixed> $state */
	private function assertAudit(array $state,string $tenant):void {
		PanelIamState::assertValid($state,$tenant);$previous=(string)$state['audit']['anchor_hash'];foreach($state['audit']['events']as$payload){$event=PanelIamAuditEvent::restore($payload);$key=$this->auditKeys[$event->keyId()]??null;if(!is_string($key)||!hash_equals($previous,$event->previousHash())||!$event->verify($key)){throw new \RuntimeException('Panel IAM audit chain integrity check failed.');}$previous=$event->hash();}
	}

	/** @param array<string,mixed> $state */
	private function pruneAudit(array &$state):void {$excess=count($state['audit']['events'])-$this->auditRetention;if($excess<=0){return;}$removed=array_splice($state['audit']['events'],0,$excess);$tail=$removed[array_key_last($removed)]??null;if(is_array($tail)){$state['audit']['anchor_hash']=(string)$tail['hash'];}}
	/** @param array<string,mixed> $state */
	private function pruneReceipts(array &$state):void {$excess=count($state['receipt_order'])-$this->receiptRetention;if($excess<=0){return;}$removed=array_splice($state['receipt_order'],0,$excess);foreach($removed as$digest){unset($state['receipts'][$digest]);}}
	private function now():string {$value=($this->clock)();if(!is_string($value)&&!is_int($value)){throw new \UnexpectedValueException('Panel IAM clock must return an instant string or Unix timestamp.');}return( string)PanelIamGuard::instant($value,'clock instant',false);}
	private static function membershipKey(string $type,string $id):string{return PanelIamGuard::subjectType($type).':'.PanelIamGuard::identifier($id,'subject id');}
	private static function limit(int $limit):int{return max(1,min(1000,$limit));}
	/** @param array<string,mixed> $payload */ private static function payloadDigest(array $payload):string{return hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));}
	/** @return array{array<string,string>,string} */
	private static function keyring(string|array $keys,mixed $current):array {
		if(is_string($keys)){$keys=['legacy'=>$keys];$current=$current??'legacy';}
		if($keys===[]||array_is_list($keys)||count($keys)>8){throw new \InvalidArgumentException('Panel IAM audit keyrings require 1 to 8 named keys.');}
		$normalized=[];foreach($keys as$id=>$key){if(!is_string($id)||!is_string($key)||strlen($key)<32){throw new \InvalidArgumentException('Panel IAM audit keyring entries require a safe id and at least 32 key bytes.');}$normalizedId=PanelIamGuard::identifier($id,'audit key id');if(isset($normalized[$normalizedId])){throw new \InvalidArgumentException('Panel IAM audit key ids must be unique after normalization.');}$normalized[$normalizedId]=$key;}
		if($current===null&&count($normalized)===1){$current=array_key_first($normalized);}$current=is_scalar($current)?PanelIamGuard::identifier((string)$current,'current audit key id'):'';if($current===''||!array_key_exists($current,$normalized)){throw new \InvalidArgumentException('Panel IAM current audit key id must select a configured key.');}
		return[$normalized,$current];
	}
}
