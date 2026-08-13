<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Fail-closed resolver for ordinary PanelRequest-backed domain actions. */
final class PanelRequestDomainCommandContextResolver implements PanelDomainCommandContextResolver,\JsonSerializable {
	/** @param array<string,mixed> $data */
	public function resolve(PanelDomainCommandDefinition $command,mixed $record,array $data,mixed $request,?Resource $resource):PanelDomainCommandInvocation {
		if(!$request instanceof PanelRequest){throw new \LogicException('Domain command actions require a trusted PanelRequest.');}
		$tenant=$request->tenantKey();if($tenant===null){throw new \LogicException('Domain command actions require an explicit tenant context.');}
		$actor=$this->actor($request->user());
		$key=trim((string)$request->header('idempotency-key',$data['_idempotency_key']??''));
		if($key===''){throw new \LogicException('Domain command actions require an idempotency key.');}
		$recordId=$this->recordId($record,$request);
		unset($data['_idempotency_key'],$data['_confirmed']);
		return new PanelDomainCommandInvocation($command,$tenant,$actor,$key,$data,$recordId,false,($request->input('_confirmed',false)===true),[
			'panel_resource'=>$resource?->name(),'panel_operation'=>$request->operation(),'http_method'=>$request->method(),
		]);
	}

	public function jsonSerialize():array{return['type'=>'panel_request_domain_command_context_resolver','version'=>1,'requires'=>['panel_request','tenant','authenticated_actor','idempotency_key'],'secrets_serialized'=>false];}

	private function actor(mixed $user):string {
		$value=match(true){
			$user instanceof PanelSecurityContext=>$user->actorId(),
			$user instanceof WorkflowActor=>$user->id(),
			is_string($user)||is_int($user)=>(string)$user,
			is_array($user)=>(string)($user['actor_id']??$user['id']??''),
			is_object($user)&&method_exists($user,'getAuthIdentifier')=>(string)$user->getAuthIdentifier(),
			is_object($user)&&method_exists($user,'id')=>(string)$user->id(),
			default=>'',
		};
		if(trim($value)===''){throw new \LogicException('Domain command actions require an authenticated actor.');}
		return PanelOperationsGuard::identifier($value,'domain command actor id');
	}

	private function recordId(mixed $record,PanelRequest $request):?string {
		$value=$request->recordKey();
		if($value===null&&is_array($record)){$value=isset($record['id'])?(string)$record['id']:null;}
		if($value===null&&(is_string($record)||is_int($record))){$value=(string)$record;}
		return$value!==null&&trim($value)!==''?PanelOperationsGuard::identifier($value,'domain command record id'):null;
	}
}
