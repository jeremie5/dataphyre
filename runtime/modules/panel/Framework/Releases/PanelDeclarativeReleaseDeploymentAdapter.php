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
 * Signed declarative adapter for Kubernetes, Nomad, ECS, Compose, and
 * filesystem promotion transports. Credentials and network clients remain
 * process-local host inputs.
 */
final class PanelDeclarativeReleaseDeploymentAdapter implements PanelReleaseDeploymentAdapter {
	/** @var array<string,string> */ private array $keys=[];
	/** @param array<string,string> $keys */
	public function __construct(private readonly PanelReleaseDeploymentProfile $profile,private readonly PanelReleaseDeploymentTransport $transport,array $keys,private readonly string $currentKeyId){
		if($keys===[])throw new \InvalidArgumentException('Declarative release deployment requires an integrity keyring.');
		foreach($keys as$id=>$key){$id=PanelOperationsGuard::name((string)$id,'release deployment key id');if(!is_string($key)||strlen($key)<32)throw new \InvalidArgumentException('Release deployment integrity keys require at least 32 bytes.');$this->keys[$id]=$key;}
		PanelOperationsGuard::name($currentKeyId,'current release deployment key id');if(!isset($this->keys[$currentKeyId]))throw new \InvalidArgumentException('Current release deployment key is not trusted.');ksort($this->keys,SORT_STRING);
	}

	public function profile():PanelReleaseDeploymentProfile{return$this->profile;}public function transport():PanelReleaseDeploymentTransport{return$this->transport;}

	/** @param array<string,mixed> $context @return array<string,mixed> */
	public function preview(string $phase,array $context):array{
		$phase=$this->phase($phase);$artifact=$context['artifact']??null;if(!is_array($artifact))throw new \InvalidArgumentException('Release deployment context requires an artifact.');
		$id=PanelOperationsGuard::identifier((string)($artifact['id']??''),'release artifact id');$version=PanelOperationsGuard::label((string)($artifact['version']??''),'release artifact version',128);$digest=$this->digest((string)($artifact['digest']??''),'release artifact digest');
		$digests=PanelOperationsGuard::safeMetadata(is_array($artifact['digests']??null)?$artifact['digests']:[],64);foreach($digests as$key=>$value){if(!is_string($key)||!is_string($value)||preg_match('/^[a-f0-9]{64}$/D',$value)!==1)throw new \InvalidArgumentException('Release artifact component digests are invalid.');}
		$operation=(string)($context['operation_key']??'');if($operation===''||strlen($operation)>512||str_contains($operation,"\0"))throw new \InvalidArgumentException('Release deployment operation key is invalid.');
		$attempt=$context['attempt']??null;$fence=$context['fence']??null;if(!is_int($attempt)||$attempt<1||!is_int($fence)||$fence<1)throw new \InvalidArgumentException('Release deployment attempt or fence is invalid.');
		if(($context['phase']??null)!==$phase||($context['payload_redacted']??null)!==true)throw new \InvalidArgumentException('Release deployment context phase or redaction contract is invalid.');
		$ring=PanelOperationsGuard::name((string)($context['ring']??''),'release ring');$execution=PanelOperationsGuard::identifier((string)($context['execution_id']??''),'release execution id',190);$deployment=PanelOperationsGuard::identifier((string)($context['deployment_id']??''),'release deployment id',190);
		$tenant=$context['tenant_id']??null;if($tenant!==null)$tenant=PanelOperationsGuard::label((string)$tenant,'release tenant id',190);
		$operationHash=hash('sha256',$operation);$body=PanelManifestContract::stamp([
			'type'=>'panel_release_deployment_request','version'=>1,'profile_digest'=>$this->profile->digest(),
			'driver'=>$this->profile->driver(),'target'=>$this->profile->target(),'strategy'=>$this->profile->strategy(),
			'config'=>$this->profile->config(),'rollout'=>$this->profile->rollout(),
			'phase'=>$phase,'intent'=>['action'=>$this->profile->action($phase),'desired_artifact_digest'=>$digest],
			'artifact'=>['id'=>$id,'version'=>$version,'digest'=>$digest,'digests'=>$digests],
			'scope'=>['ring'=>$ring,'tenant_hash'=>$tenant!==null?hash('sha256',$tenant):null],
			'execution'=>['execution_hash'=>hash('sha256',$execution),'deployment_hash'=>hash('sha256',$deployment)],
			'idempotency'=>['operation_key_hash'=>$operationHash,'attempt'=>$attempt,'fence'=>$fence],
			'payload_redacted'=>true,'credentials_exposed'=>false,
		]);
		$body['request_id']='release_request_'.substr(PanelOperationsGuard::digest($body),0,40);$requestDigest=PanelOperationsGuard::digest($body);
		$body['request_digest']=$requestDigest;$body['integrity']=['key_id'=>$this->currentKeyId,'digest'=>$requestDigest,'signature'=>hash_hmac('sha256',$requestDigest,$this->keys[$this->currentKeyId])];return$body;
	}

	public function execute(string $phase,array $context):array{$request=$this->preview($phase,$context);$receipt=$this->transport->dispatch($request);return$this->receipt($request,$receipt);}

	/**
	 * Creates the exact receipt envelope a remote deployment worker must return.
	 * The key remains a host-owned input and is never retained by the envelope.
	 *
	 * @param array<string,mixed> $request @param array<string,mixed> $details
	 * @return array<string,mixed>
	 */
	public static function sealReceipt(array $request,bool $ok,string $code,array $details,string $keyId,string $key):array{
		if(strlen($key)<32)throw new \InvalidArgumentException('Release deployment receipt keys require at least 32 bytes.');$keyId=PanelOperationsGuard::name($keyId,'release deployment receipt key id');$code=PanelOperationsGuard::name($code,'release deployment receipt code');
		foreach(['request_digest','driver','target','phase']as$field){if(!is_string($request[$field]??null)||$request[$field]==='')throw new \InvalidArgumentException('Release deployment request cannot be acknowledged safely.');}
		$idempotency=$request['idempotency']??null;if(!is_array($idempotency)||!is_string($idempotency['operation_key_hash']??null)||!is_int($idempotency['fence']??null))throw new \InvalidArgumentException('Release deployment request idempotency binding is invalid.');
		$payload=PanelManifestContract::stamp(['type'=>'panel_release_deployment_receipt','version'=>1,'ok'=>$ok,'code'=>$code,'request_digest'=>$request['request_digest'],'operation_key_hash'=>$idempotency['operation_key_hash'],'fence'=>$idempotency['fence'],'driver'=>$request['driver'],'target'=>$request['target'],'phase'=>$request['phase'],'details'=>PanelOperationsGuard::safeMetadata($details,128),'credentials_exposed'=>false]);
		$digest=PanelOperationsGuard::digest($payload);$payload['integrity']=['key_id'=>$keyId,'digest'=>$digest,'signature'=>hash_hmac('sha256',$digest,$key)];return$payload;
	}

	public function jsonSerialize():array{$ids=array_keys($this->keys);sort($ids,SORT_STRING);return PanelManifestContract::stamp(['type'=>'panel_declarative_release_deployment_adapter','version'=>1,'profile'=>$this->profile->jsonSerialize(),'transport'=>$this->transport->jsonSerialize(),'trusted_key_ids'=>$ids,'current_key_id'=>$this->currentKeyId,'phases'=>['prepare','activate','verify','rollback'],'drivers'=>['kubernetes','nomad','ecs','compose','filesystem'],'idempotency_required'=>true,'fenced_receipts'=>true,'request_signatures'=>true,'receipt_signatures'=>true,'credentials_exposed'=>false,'integrity_keys_exposed'=>false]);}

	/** @param array<string,mixed> $request @param array<string,mixed> $receipt @return array<string,mixed> */
	private function receipt(array $request,array $receipt):array{
		$integrity=$receipt['integrity']??null;if(!is_array($integrity)||!is_string($integrity['key_id']??null)||!is_string($integrity['digest']??null)||!is_string($integrity['signature']??null))throw new \UnexpectedValueException('Release deployment receipt integrity is missing.');
		$key=$this->keys[$integrity['key_id']]??null;$unsigned=$receipt;unset($unsigned['integrity']);$digest=PanelOperationsGuard::digest($unsigned);
		if(!is_string($key)||!hash_equals($digest,$integrity['digest'])||!hash_equals(hash_hmac('sha256',$digest,$key),$integrity['signature']))throw new \UnexpectedValueException('Release deployment receipt signature is untrusted.');
		$expected=['request_digest'=>$request['request_digest'],'operation_key_hash'=>$request['idempotency']['operation_key_hash'],'fence'=>$request['idempotency']['fence'],'driver'=>$request['driver'],'target'=>$request['target'],'phase'=>$request['phase']];
		foreach($expected as$field=>$value){if(!array_key_exists($field,$receipt)||(is_string($value)?!is_string($receipt[$field])||!hash_equals($value,$receipt[$field]):$receipt[$field]!==$value))throw new \UnexpectedValueException('Release deployment receipt is not bound to the active request.');}
		if(!is_bool($receipt['ok']??null))throw new \UnexpectedValueException('Release deployment receipt outcome is invalid.');$code=PanelOperationsGuard::name((string)($receipt['code']??''),'release deployment receipt code');
		return['ok'=>$receipt['ok'],'code'=>$code,'request_digest'=>$request['request_digest'],'receipt_digest'=>$digest,'driver'=>$request['driver'],'target'=>$request['target'],'phase'=>$request['phase'],'fence'=>$request['idempotency']['fence'],'result_redacted'=>true];
	}

	private function phase(string $phase):string{$phase=strtolower(trim($phase));if(!in_array($phase,['prepare','activate','verify','rollback'],true))throw new \InvalidArgumentException('Release deployment phase is invalid.');return$phase;}
	private function digest(string $digest,string $label):string{$digest=strtolower(trim($digest));if(preg_match('/^[a-f0-9]{64}$/D',$digest)!==1)throw new \InvalidArgumentException(ucfirst($label).' is invalid.');return$digest;}
}
