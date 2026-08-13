<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Durable release execution state machine with adapter idempotency, fenced
 * leases, crash recovery, explicit activation commit, and automatic rollback.
 */
final class PanelReleaseExecutionEngine implements \JsonSerializable {
	private const FORWARD_PHASES=['prepare','activate','verify'];
	private const TERMINAL=['active','blocked','rolled_back','rollback_failed'];

	private readonly PanelAtomicSnapshotStore $store;
	private readonly \Closure $clock;
	/** @var array<string,string> */ private array $keys=[];

	/**
	 * @param array<string,string> $keys
	 */
	public function __construct(
		string $root,
		private readonly PanelReleaseControlPlane $controlPlane,
		private readonly PanelPolicyControlPlane $policy,
		private readonly ?PanelReleaseDeploymentAdapter $adapter,
		array $keys,
		private readonly string $currentKeyId,
		?callable $clock=null,
		private readonly int $leaseSeconds=120,
		private readonly int $maximumEntries=10000,
		int $snapshotRetention=2048,
	) {
		if($keys===[]){throw new \InvalidArgumentException('Release execution requires an integrity keyring.');}
		foreach($keys as$id=>$key){$id=PanelOperationsGuard::name((string)$id,'release execution key id');if(!is_string($key)||strlen($key)<32){throw new \InvalidArgumentException('Release execution keys require at least 32 bytes.');}$this->keys[$id]=$key;}
		PanelOperationsGuard::name($currentKeyId,'current release execution key id');if(!isset($this->keys[$currentKeyId])){throw new \InvalidArgumentException('Current release execution key is not trusted.');}
		if($leaseSeconds<30||$leaseSeconds>3600){throw new \InvalidArgumentException('Release execution lease must be between 30 and 3600 seconds.');}
		if($maximumEntries<1||$maximumEntries>1000000){throw new \InvalidArgumentException('Release execution retention bound is invalid.');}
		$this->clock=$clock!==null?\Closure::fromCallable($clock):static fn():string=>gmdate('c');
		$initial=$this->signedState(['schema'=>'panel_release_execution_engine','version'=>1,'revision'=>0,'executions'=>[],'idempotency'=>[]]);
		$this->store=new PanelAtomicSnapshotStore($root,'panel.release-execution.v1',$initial,max(8,$snapshotRetention));
		$this->assertState($this->store->payload());
	}

	public function configured():bool{return$this->adapter instanceof PanelReleaseDeploymentAdapter;}
	public function controlPlane():PanelReleaseControlPlane{return$this->controlPlane;}
	public function policy():PanelPolicyControlPlane{return$this->policy;}
	public function store():PanelAtomicSnapshotStore{return$this->store;}
	public function adapter():?PanelReleaseDeploymentAdapter{return$this->adapter;}

	/**
	 * @param array<string,int|float> $health
	 * @return array<string,mixed>
	 */
	public function execute(string $artifactId,string $ring,array $health,PanelPolicyRequest|array $request,string $idempotencyKey,string $worker='inline'):array {
		$request=$this->request($request);$this->authorize($request,'release.execute');$this->requireAdapter();
		$artifactId=PanelOperationsGuard::name($artifactId,'release artifact id');$ring=PanelOperationsGuard::name($ring,'release ring');$worker=PanelOperationsGuard::identifier($worker,'release execution worker');
		$idempotencyHash=$this->idempotency($request,$idempotencyKey);$adapterFingerprint=$this->adapterFingerprint();
		$fingerprint=PanelOperationsGuard::digest(['artifact_id'=>$artifactId,'ring'=>$ring,'health'=>PanelOperationsGuard::canonical($health),'request'=>$request->fingerprint(),'adapter_fingerprint'=>$adapterFingerprint]);
		$state=$this->state();$known=$state['idempotency'][$idempotencyHash]??null;
		if(is_array($known)){
			if(!hash_equals((string)($known['fingerprint']??''),$fingerprint)){throw new \LogicException('Release execution idempotency key conflict.');}
			$projection=$this->execution((string)$known['id']);if(in_array($projection['status'],self::TERMINAL,true)){return array_replace($projection,['replayed'=>true]);}
			return$this->resume((string)$known['id'],$request,$worker);
		}

		$deployment=$this->controlPlane->schedule($artifactId,$ring,$health,$request,'execution-schedule-'.$idempotencyHash);
		$executionId='release_exec_'.substr(hash('sha256',(string)$deployment['id'].'|'.$fingerprint),0,40);$now=$this->now();$status=(string)$deployment['status'];
		$record=[
			'id'=>$executionId,'deployment_id'=>(string)$deployment['id'],'artifact_id'=>$artifactId,'ring'=>$ring,
			'tenant_id'=>$request->tenantId(),'actor_hash'=>hash('sha256',$request->actorId()),'adapter_fingerprint'=>$adapterFingerprint,
			'status'=>$status==='blocked'?'blocked':'approved','mode'=>$status==='blocked'?'terminal':'forward','phase'=>null,
			'steps'=>$this->initialSteps(),'failure'=>null,'lease'=>null,'created_at'=>$now,'updated_at'=>$now,'completed_at'=>$status==='blocked'?$now:null,
		];
		$this->mutate(function(array &$state)use($record,$idempotencyHash,$fingerprint):void{
			$known=$state['idempotency'][$idempotencyHash]??null;if(is_array($known)){if(!hash_equals((string)$known['fingerprint'],$fingerprint)){throw new \LogicException('Release execution idempotency key conflict.');}return;}
			if(count($state['executions'])>=$this->maximumEntries){throw new \LengthException('Release execution retention limit is reached.');}
			$state['executions'][$record['id']]=$record;$state['idempotency'][$idempotencyHash]=['id'=>$record['id'],'fingerprint'=>$fingerprint];ksort($state['executions'],SORT_STRING);ksort($state['idempotency'],SORT_STRING);
		},'release.execution.created',['execution_id'=>$executionId,'deployment_id'=>$deployment['id'],'status'=>$record['status']]);
		if($record['status']==='blocked'){return$this->execution($executionId);}
		return$this->resume($executionId,$request,$worker);
	}

	/** @return array<string,mixed> */
	public function resume(string $executionId,PanelPolicyRequest|array $request,string $worker='recovery',int $staleAfterSeconds=0):array {
		$request=$this->request($request);$this->authorize($request,'release.execute.resume');$this->requireAdapter();
		$executionId=PanelOperationsGuard::identifier($executionId,'release execution id',190);$worker=PanelOperationsGuard::identifier($worker,'release execution worker');
		if($staleAfterSeconds<0||$staleAfterSeconds>604800){throw new \InvalidArgumentException('Release execution stale threshold is invalid.');}
		$record=$this->record($executionId);$this->assertTenant($request,$record);if(in_array($record['status'],self::TERMINAL,true)){return array_replace($this->projection($record),['replayed'=>true]);}
		if(!hash_equals((string)$record['adapter_fingerprint'],$this->adapterFingerprint())){throw new \LogicException('Release execution adapter changed before the execution completed.');}
		$lease=$this->claim($executionId,$worker,$staleAfterSeconds);
		$record=$this->record($executionId);
		if($record['mode']==='rollback'){
			return$this->runRollback($executionId,$request,$lease);
		}
		return$this->runForward($executionId,$request,$lease);
	}

	/** @return array{resumed:list<array<string,mixed>>,errors:array<string,string>} */
	public function recoverStale(PanelPolicyRequest|array $request,string $worker='release-recovery',int $staleAfterSeconds=0,int $limit=25):array {
		$request=$this->request($request);$this->authorize($request,'release.execute.recover');$worker=PanelOperationsGuard::identifier($worker,'release recovery worker');$limit=max(1,min(1000,$limit));
		if($staleAfterSeconds<0||$staleAfterSeconds>604800){throw new \InvalidArgumentException('Release execution stale threshold is invalid.');}
		$candidates=[];foreach($this->state()['executions']as$id=>$record){if(!is_array($record)||$record['status']!=='running'||!$this->leaseStale($record,$staleAfterSeconds)){continue;}if($record['tenant_id']!==null&&$request->tenantId()!==$record['tenant_id']){continue;}$candidates[$id]=$record;}
		uasort($candidates,static fn(array $left,array $right):int=>[$left['updated_at'],$left['id']]<=>[$right['updated_at'],$right['id']]);$resumed=[];$errors=[];
		foreach(array_slice($candidates,0,$limit,true)as$id=>$record){try{$resumed[]=$this->resume((string)$id,$request,$worker,$staleAfterSeconds);}catch(\Throwable){$errors[(string)$id]='release_execution_recovery_failed';}}
		return['resumed'=>$resumed,'errors'=>$errors];
	}

	/** @return array<string,mixed> */public function execution(string $executionId):array{return$this->projection($this->record(PanelOperationsGuard::identifier($executionId,'release execution id',190)));}
	/** @return list<array<string,mixed>> */public function executions(?string $tenantId=null,int $limit=100):array {$limit=max(1,min(1000,$limit));$items=[];foreach($this->state()['executions']as$record){if(!is_array($record)||($tenantId!==null&&$record['tenant_id']!==$tenantId)){continue;}$items[]=$this->projection($record);}usort($items,static fn(array $left,array $right):int=>[$right['updated_at'],$right['id']]<=>[$left['updated_at'],$left['id']]);return array_slice($items,0,$limit);}

	/** @return array<string,mixed> */
	private function runForward(string $executionId,PanelPolicyRequest $request,array $lease):array {
		$control=$this->controlPlane->deployment((string)$this->record($executionId)['deployment_id']);
		if($control['status']==='active'){$this->finalize($executionId,'active',$lease);return$this->execution($executionId);}
		if($control['status']==='approved'){$this->controlPlane->transition((string)$control['id'],'executing',['code'=>'execution_claimed'],$request,$this->transitionKey($executionId,'executing'),'approved');}
		elseif($control['status']!=='executing'){throw new \LogicException('Release control-plane deployment is not executable.');}

		foreach(self::FORWARD_PHASES as$phase){$record=$this->record($executionId);if(($record['steps'][$phase]['status']??null)==='completed'){continue;}$step=$this->beginStep($executionId,$phase,$lease);$context=$this->adapterContext($this->record($executionId),$phase,$step,$lease);
			try{$result=$this->adapter->execute($phase,$context);$receipt=$this->adapterReceipt($result);}
			catch(PanelReleaseExecutionInterrupted $interrupted){throw$interrupted;}
			catch(\Throwable$exception){$failure=$this->exceptionReceipt($exception);$this->failForwardStep($executionId,$phase,$failure,$lease);return$this->runRollback($executionId,$request,$lease);}
			if(!$receipt['ok']){$this->failForwardStep($executionId,$phase,$receipt,$lease);return$this->runRollback($executionId,$request,$lease);}
			$this->completeStep($executionId,$phase,$receipt,$lease);
		}
		$record=$this->record($executionId);$this->controlPlane->transition((string)$record['deployment_id'],'active',['code'=>'adapter_verified','execution_digest'=>PanelOperationsGuard::digest($record['steps'])],$request,$this->transitionKey($executionId,'active'),'executing');
		$this->finalize($executionId,'active',$lease);return$this->execution($executionId);
	}

	/** @return array<string,mixed> */
	private function runRollback(string $executionId,PanelPolicyRequest $request,array $lease):array {
		$record=$this->record($executionId);$control=$this->controlPlane->deployment((string)$record['deployment_id']);$failure=is_array($record['failure'])?$record['failure']:['code'=>'execution_failed','result_digest'=>PanelOperationsGuard::digest([])];
		if($control['status']==='executing'){$control=$this->controlPlane->transition((string)$control['id'],'failed',$failure,$request,$this->transitionKey($executionId,'failed'),'executing');}
		if($control['status']==='failed'){$control=$this->controlPlane->transition((string)$control['id'],'rolling_back',['code'=>'automatic_rollback','failure_digest'=>$failure['result_digest']??null],$request,$this->transitionKey($executionId,'rolling_back'),'failed');}
		if($control['status']==='rolled_back'||$control['status']==='rollback_failed'){$this->finalize($executionId,(string)$control['status'],$lease);return$this->execution($executionId);}
		if($control['status']!=='rolling_back'){throw new \LogicException('Release control-plane deployment is not rollback recoverable.');}
		$record=$this->record($executionId);if(($record['steps']['rollback']['status']??null)!=='completed'){$step=$this->beginStep($executionId,'rollback',$lease);$context=$this->adapterContext($this->record($executionId),'rollback',$step,$lease);
			try{$result=$this->adapter->execute('rollback',$context);$receipt=$this->adapterReceipt($result);}
			catch(PanelReleaseExecutionInterrupted $interrupted){throw$interrupted;}
			catch(\Throwable$exception){$receipt=$this->exceptionReceipt($exception);}
			$this->completeStep($executionId,'rollback',$receipt,$lease);
		}else{$receipt=$record['steps']['rollback']['receipt'];}
		$status=($receipt['ok']??false)===true?'rolled_back':'rollback_failed';$this->controlPlane->transition((string)$record['deployment_id'],$status,['code'=>(string)($receipt['code']??$status),'result_digest'=>$receipt['result_digest']??null],$request,$this->transitionKey($executionId,$status),'rolling_back');
		$this->finalize($executionId,$status,$lease);return$this->execution($executionId);
	}

	/** @return array{token:string,fence:int,worker:string} */
	private function claim(string $executionId,string $worker,int $staleAfterSeconds):array {
		$token=bin2hex(random_bytes(32));$now=$this->now();$result=$this->mutate(function(array &$state)use($executionId,$worker,$staleAfterSeconds,$token,$now):array{
			$record=&$this->recordReference($state,$executionId);if(in_array($record['status'],self::TERMINAL,true)){throw new \LogicException('Terminal release executions cannot be leased.');}
			if($record['status']==='running'&&!$this->leaseStale($record,$staleAfterSeconds)){throw new \LogicException('Release execution is already leased.');}
			$fence=$state['revision']+1;$record['status']='running';$record['lease']=['worker_hash'=>hash('sha256',$worker),'token_hash'=>hash('sha256',$token),'fence'=>$fence,'expires_at'=>$this->after($now,$this->leaseSeconds)];$record['updated_at']=$now;
			return['token'=>$token,'fence'=>$fence,'worker'=>$worker];
		},'release.execution.claimed',['execution_id'=>$executionId,'worker_hash'=>hash('sha256',$worker)]);return$result;
	}

	/** @return array<string,mixed> */
	private function beginStep(string $executionId,string $phase,array $lease):array {
		if(!in_array($phase,[...self::FORWARD_PHASES,'rollback'],true)){throw new \InvalidArgumentException('Release execution phase is invalid.');}
		return$this->mutate(function(array &$state)use($executionId,$phase,$lease):array{
			$record=&$this->recordReference($state,$executionId);$this->assertLease($record,$lease);$step=$record['steps'][$phase];if($step['status']==='completed'){return$step;}
			$now=$this->now();$step['status']='running';$step['attempts']++;$step['operation_key']??=hash('sha256',$executionId.'|'.$phase);$step['started_at']??=$now;$step['completed_at']=null;$step['receipt']=null;$record['steps'][$phase]=$step;$record['phase']=$phase;$record['updated_at']=$now;$record['lease']['expires_at']=$this->after($now,$this->leaseSeconds);return$step;
		},'release.execution.step_started',['execution_id'=>$executionId,'phase'=>$phase]);
	}

	/** @param array<string,mixed> $receipt */
	private function completeStep(string $executionId,string $phase,array $receipt,array $lease):void {
		$this->mutate(function(array &$state)use($executionId,$phase,$receipt,$lease):void{$record=&$this->recordReference($state,$executionId);$this->assertLease($record,$lease);$step=$record['steps'][$phase];if($step['status']!=='running'){throw new \LogicException('Release execution step is not running.');}$now=$this->now();$step['status']='completed';$step['completed_at']=$now;$step['receipt']=$receipt;$record['steps'][$phase]=$step;$record['phase']=$phase;$record['updated_at']=$now;$record['lease']['expires_at']=$this->after($now,$this->leaseSeconds);},'release.execution.step_completed',['execution_id'=>$executionId,'phase'=>$phase,'ok'=>$receipt['ok']]);
	}

	/** @param array<string,mixed> $failure */
	private function failForwardStep(string $executionId,string $phase,array $failure,array $lease):void {
		$this->mutate(function(array &$state)use($executionId,$phase,$failure,$lease):void{$record=&$this->recordReference($state,$executionId);$this->assertLease($record,$lease);$step=$record['steps'][$phase];if($step['status']!=='running'){throw new \LogicException('Release execution step is not running.');}$now=$this->now();$step['status']='failed';$step['completed_at']=$now;$step['receipt']=$failure;$record['steps'][$phase]=$step;$record['mode']='rollback';$record['failure']=$failure;$record['phase']=$phase;$record['updated_at']=$now;$record['lease']['expires_at']=$this->after($now,$this->leaseSeconds);},'release.execution.step_failed',['execution_id'=>$executionId,'phase'=>$phase,'code'=>$failure['code']??'failed']);
	}

	private function finalize(string $executionId,string $status,array $lease):void {
		if(!in_array($status,self::TERMINAL,true)){throw new \InvalidArgumentException('Release execution terminal status is invalid.');}
		$this->mutate(function(array &$state)use($executionId,$status,$lease):void{$record=&$this->recordReference($state,$executionId);$this->assertLease($record,$lease);$now=$this->now();$record['status']=$status;$record['mode']='terminal';$record['phase']=null;$record['lease']=null;$record['updated_at']=$now;$record['completed_at']=$now;},'release.execution.finalized',['execution_id'=>$executionId,'status'=>$status]);
	}

	/** @param array<string,mixed> $record @param array<string,mixed> $step @return array<string,mixed> */
	private function adapterContext(array $record,string $phase,array $step,array $lease):array {
		$artifact=$this->controlPlane->artifact((string)$record['artifact_id']);
		return['execution_id'=>$record['id'],'deployment_id'=>$record['deployment_id'],'artifact'=>['id'=>$artifact->id(),'version'=>$artifact->version(),'digest'=>$artifact->digest(),'digests'=>$artifact->digests()],'ring'=>$record['ring'],'tenant_id'=>$record['tenant_id'],'phase'=>$phase,'operation_key'=>$step['operation_key'],'attempt'=>$step['attempts'],'fence'=>$lease['fence'],'payload_redacted'=>true];
	}

	/** @param array<string,mixed> $result @return array{ok:bool,code:string,result_digest:string,result_redacted:true} */
	private function adapterReceipt(array $result):array {
		if(!is_bool($result['ok']??null)){throw new \UnexpectedValueException('Release deployment adapter result is invalid.');}$safe=PanelOperationsGuard::safeMetadata($result,512);$code=isset($safe['code'])&&is_scalar($safe['code'])?strtolower(trim((string)$safe['code'])):($safe['ok']?'completed':'adapter_rejected');
		try{$code=PanelOperationsGuard::name($code,'release adapter result code');}catch(\Throwable){$code=$safe['ok']?'completed':'adapter_rejected';}
		return['ok'=>$safe['ok'],'code'=>$code,'result_digest'=>PanelOperationsGuard::digest($safe),'result_redacted'=>true];
	}

	/** @return array{ok:false,code:string,result_digest:string,result_redacted:true} */
	private function exceptionReceipt(\Throwable $exception):array {return['ok'=>false,'code'=>'adapter_exception','result_digest'=>PanelOperationsGuard::digest(['class'=>get_class($exception),'message'=>$exception->getMessage()]),'result_redacted'=>true];}

	/** @return array<string,array<string,mixed>> */
	private function initialSteps():array {$steps=[];foreach([...self::FORWARD_PHASES,'rollback']as$phase){$steps[$phase]=['phase'=>$phase,'status'=>'pending','attempts'=>0,'operation_key'=>null,'started_at'=>null,'completed_at'=>null,'receipt'=>null];}return$steps;}

	/** @param array<string,mixed> $record @return array<string,mixed> */
	private function projection(array $record):array {
		$steps=[];foreach($record['steps']as$phase=>$step){$steps[$phase]=['phase'=>$phase,'status'=>$step['status'],'attempts'=>$step['attempts'],'operation_key_hash'=>is_string($step['operation_key'])?hash('sha256',$step['operation_key']):null,'started_at'=>$step['started_at'],'completed_at'=>$step['completed_at'],'receipt'=>$step['receipt']];}
		return PanelManifestContract::stamp(['type'=>'panel_release_execution','version'=>1,'id'=>$record['id'],'deployment_id'=>$record['deployment_id'],'artifact_id'=>$record['artifact_id'],'ring'=>$record['ring'],'tenant_id'=>$record['tenant_id'],'actor_hash'=>$record['actor_hash'],'status'=>$record['status'],'mode'=>$record['mode'],'phase'=>$record['phase'],'steps'=>$steps,'failure'=>$record['failure'],'leased'=>is_array($record['lease']),'lease_fence'=>is_array($record['lease'])?(int)$record['lease']['fence']:null,'lease_credentials_exposed'=>false,'adapter_fingerprint'=>$record['adapter_fingerprint'],'created_at'=>$record['created_at'],'updated_at'=>$record['updated_at'],'completed_at'=>$record['completed_at'],'replayed'=>false]);
	}

	/** @return array<string,mixed> */private function record(string $executionId):array {$record=$this->state()['executions'][$executionId]??null;if(!is_array($record)){throw new \OutOfBoundsException('Release execution does not exist.');}return$record;}
	/** @param array<string,mixed> $state @return array<string,mixed> */private function &recordReference(array &$state,string $executionId):array {if(!isset($state['executions'][$executionId])||!is_array($state['executions'][$executionId])){throw new \OutOfBoundsException('Release execution does not exist.');}return$state['executions'][$executionId];}

	/** @param array<string,mixed> $record @param array<string,mixed> $lease */
	private function assertLease(array $record,array $lease):void {if($record['status']!=='running'||!is_array($record['lease'])||!is_string($lease['token']??null)||!is_int($lease['fence']??null)||!hash_equals((string)$record['lease']['token_hash'],hash('sha256',$lease['token']))||(int)$record['lease']['fence']!==$lease['fence']){throw new \LogicException('Release execution lease was lost.');}}

	/** @param array<string,mixed> $record */
	private function leaseStale(array $record,int $staleAfterSeconds):bool {if(!is_array($record['lease']??null)){return true;}$expires=$this->epoch((string)($record['lease']['expires_at']??''));return$expires<=$this->epoch($this->now())-$staleAfterSeconds;}

	/** @param array<string,mixed> $record */
	private function assertTenant(PanelPolicyRequest $request,array $record):void {if($record['tenant_id']!==null&&($request->tenantId()===null||!hash_equals((string)$record['tenant_id'],$request->tenantId()))){throw new \LogicException('Release execution tenant does not match the trusted policy request.');}}

	private function requireAdapter():void {if(!$this->adapter instanceof PanelReleaseDeploymentAdapter){throw new \LogicException('Release execution requires a configured deployment adapter.');}}
	private function adapterFingerprint():string {return PanelOperationsGuard::digest($this->adapter?->jsonSerialize()??['type'=>'unconfigured_release_adapter']);}
	private function transitionKey(string $executionId,string $status):string{return'release-transition-'.substr(hash('sha256',$executionId.'|'.$status),0,48);}
	private function idempotency(PanelPolicyRequest $request,string $key):string {$key=trim($key);if($key===''||strlen($key)>512||str_contains($key,"\0")){throw new \InvalidArgumentException('Release execution idempotency key is invalid.');}return hash('sha256',($request->tenantId()??'global')."\0".$request->actorId()."\0".$key);}

	private function request(PanelPolicyRequest|array $request):PanelPolicyRequest{return$request instanceof PanelPolicyRequest?$request:PanelPolicyRequest::from($request);}
	private function authorize(PanelPolicyRequest $request,string $ability):void {$attributes=$request->jsonSerialize();$attributes['ability']=$ability;$this->policy->evaluate(PanelPolicyRequest::from($attributes))->assertAllowed();}

	/** @return array<string,mixed> */private function state():array {$state=$this->store->payload();$this->assertState($state);return$state;}
	/** @param callable(array<string,mixed>&):mixed $mutation */private function mutate(callable $mutation,string $type,array $event=[]):mixed {$transaction=$this->store->transaction(function(array &$state)use($mutation){$this->assertState($state);$result=$mutation($state);$state['revision']++;$state=$this->signedState($state);return$result;},$type,PanelOperationsGuard::safeMetadata($event,64));return$transaction['result'];}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function signedState(array $state):array {unset($state['integrity']);$digest=PanelOperationsGuard::digest($state);$state['integrity']=['key_id'=>$this->currentKeyId,'digest'=>$digest,'signature'=>hash_hmac('sha256',$digest,$this->keys[$this->currentKeyId])];return$state;}

	/** @param array<string,mixed> $state */
	private function assertState(array $state):void {
		if(($state['schema']??null)!=='panel_release_execution_engine'||($state['version']??null)!==1||!is_int($state['revision']??null)||$state['revision']<0||!is_array($state['executions']??null)||!is_array($state['idempotency']??null)||!is_array($state['integrity']??null)||count($state['executions'])>$this->maximumEntries){throw new \UnexpectedValueException('Release execution state shape is invalid.');}
		$integrity=$state['integrity'];$unsigned=$state;unset($unsigned['integrity']);$digest=PanelOperationsGuard::digest($unsigned);$key=is_string($integrity['key_id']??null)?($this->keys[$integrity['key_id']]??null):null;if(!is_string($key)||!is_string($integrity['digest']??null)||!is_string($integrity['signature']??null)||!hash_equals($digest,$integrity['digest'])||!hash_equals($integrity['signature'],hash_hmac('sha256',$digest,$key))){throw new \UnexpectedValueException('Release execution state signature is untrusted.');}
		foreach($state['executions']as$id=>$record){if(!is_string($id)||!is_array($record)||($record['id']??null)!==$id||!is_string($record['deployment_id']??null)||!is_string($record['artifact_id']??null)||!is_string($record['ring']??null)||(!is_string($record['tenant_id']??null)&&($record['tenant_id']??null)!==null)||!is_string($record['actor_hash']??null)||preg_match('/^[a-f0-9]{64}$/D',$record['actor_hash'])!==1||!is_string($record['adapter_fingerprint']??null)||preg_match('/^[a-f0-9]{64}$/D',$record['adapter_fingerprint'])!==1||!in_array($record['status']??null,['approved','running',...self::TERMINAL],true)||!in_array($record['mode']??null,['forward','rollback','terminal'],true)||!is_array($record['steps']??null)||array_keys($record['steps'])!==[...self::FORWARD_PHASES,'rollback']||(!is_array($record['lease']??null)&&($record['lease']??null)!==null)||!is_string($record['created_at']??null)||!is_string($record['updated_at']??null)){throw new \UnexpectedValueException('Stored release execution is invalid.');}
			foreach($record['steps']as$phase=>$step){if(!is_array($step)||($step['phase']??null)!==$phase||!in_array($step['status']??null,['pending','running','completed','failed'],true)||!is_int($step['attempts']??null)||$step['attempts']<0){throw new \UnexpectedValueException('Stored release execution step is invalid.');}}
		}
		foreach($state['idempotency']as$hash=>$entry){if(!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1||!is_array($entry)||!is_string($entry['id']??null)||!isset($state['executions'][$entry['id']])||!is_string($entry['fingerprint']??null)||preg_match('/^[a-f0-9]{64}$/D',$entry['fingerprint'])!==1){throw new \UnexpectedValueException('Release execution idempotency index is invalid.');}}
	}

	public function verifyIntegrity():array {try{$state=$this->state();return['ok'=>true,'revision'=>$state['revision'],'execution_count'=>count($state['executions'])];}catch(\Throwable){return['ok'=>false,'revision'=>null,'execution_count'=>null];}}

	public function jsonSerialize():array {
		$state=$this->state();$statuses=[];foreach($state['executions']as$record){$status=(string)$record['status'];$statuses[$status]=($statuses[$status]??0)+1;}ksort($statuses,SORT_STRING);
		return PanelManifestContract::stamp(['type'=>'panel_release_execution_engine_manifest','version'=>1,'revision'=>$state['revision'],'configured'=>$this->configured(),'adapter'=>$this->adapter?->jsonSerialize(),'execution_count'=>count($state['executions']),'statuses'=>$statuses,'recent_executions'=>$this->executions(null,25),'integrity'=>$this->verifyIntegrity(),'lease_seconds'=>$this->leaseSeconds,'maximum_entries'=>$this->maximumEntries,'security'=>['signed_state'=>true,'lease_credentials_exposed'=>false,'adapter_results_redacted'=>true,'actor_identifiers_hashed'=>true],'capabilities'=>['durable_execution'=>true,'prepare_activate_verify'=>true,'automatic_rollback'=>true,'adapter_idempotency'=>true,'fenced_leases'=>true,'crash_recovery'=>true,'split_journal_recovery'=>true,'explicit_activation_commit'=>true,'health_gate_blocking'=>true,'tenant_scoped_recovery'=>true,'bounded_retention'=>true]]);
	}

	private function now():string {$value=($this->clock)();if(!$value instanceof \DateTimeInterface&&!is_string($value)&&!is_int($value)){throw new \UnexpectedValueException('Release execution clock must return an instant.');}return PanelOperationsGuard::instant($value);}
	private function after(string $instant,int $seconds):string{return PanelOperationsGuard::instant((new \DateTimeImmutable($instant))->modify('+'.$seconds.' seconds'));}
	private function epoch(string $instant):int {try{return(new \DateTimeImmutable(PanelOperationsGuard::instant($instant)))->getTimestamp();}catch(\Throwable){throw new \UnexpectedValueException('Release execution lease instant is invalid.');}}
}
