<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Immutable normalized authorization input shared by every Panel subsystem. */
final class PanelPolicyRequest implements \JsonSerializable {
	private readonly string $actorId;
	private readonly string $ability;
	private readonly ?string $tenantId;
	private readonly ?string $resourceType;
	private readonly ?string $resourceId;
	private readonly string $risk;
	/** @var list<string> */ private readonly array $roles;
	/** @var list<string> */ private readonly array $permissions;
	/** @var array<string,mixed> */ private readonly array $context;
	private readonly ?string $occurredAt;

	/** @param array<string,mixed> $context @param list<string> $roles @param list<string> $permissions */
	public function __construct(
		string $actorId,
		string $ability,
		?string $tenantId=null,
		?string $resourceType=null,
		?string $resourceId=null,
		string $risk='medium',
		array $roles=[],
		array $permissions=[],
		array $context=[],
		?string $occurredAt=null,
	){
		$this->actorId=PanelOperationsGuard::identifier($actorId,'policy actor id');$ability=strtolower(trim($ability));if($ability===''||strlen($ability)>160||preg_match('/^[a-z][a-z0-9_.:-]*$/D',$ability)!==1){throw new \InvalidArgumentException('Policy ability is invalid.');}$this->ability=$ability;$this->tenantId=$tenantId!==null?PanelOperationsGuard::identifier($tenantId,'policy tenant id'):null;if(($resourceType===null)!==($resourceId===null)){throw new \InvalidArgumentException('Policy resource identity is incomplete.');}$this->resourceType=$resourceType!==null?PanelOperationsGuard::name($resourceType,'policy resource type'):null;$this->resourceId=$resourceId!==null?PanelOperationsGuard::identifier($resourceId,'policy resource id'):null;$risk=strtolower(trim($risk));if(!in_array($risk,['low','medium','high','critical'],true)){throw new \InvalidArgumentException('Policy risk is invalid.');}$this->risk=$risk;$this->roles=PanelOperationsGuard::roles($roles,'policy role');$this->permissions=PanelOperationsGuard::abilityPatterns($permissions,'policy permission');$this->context=PanelOperationsGuard::safeMetadata($context,1024);$this->occurredAt=$occurredAt!==null?PanelOperationsGuard::instant($occurredAt):null;
	}

	/** @param array<string,mixed> $request */
	public static function from(array $request):self {$resource=is_array($request['resource']??null)?$request['resource']:[];return new self(PanelOperationsGuard::identifier((string)($request['actor_id']??''),'policy actor id'),strtolower(trim((string)($request['ability']??''))),isset($request['tenant_id'])?PanelOperationsGuard::identifier((string)$request['tenant_id'],'policy tenant id'):null,isset($resource['type'])?PanelOperationsGuard::name((string)$resource['type'],'policy resource type'):null,isset($resource['id'])?PanelOperationsGuard::identifier((string)$resource['id'],'policy resource id'):null,strtolower((string)($request['risk']??'medium')),PanelOperationsGuard::roles(is_array($request['roles']??null)?$request['roles']:[],'policy role'),PanelOperationsGuard::abilityPatterns(is_array($request['permissions']??null)?$request['permissions']:[],'policy permission'),PanelOperationsGuard::safeMetadata(is_array($request['context']??null)?$request['context']:[],1024),isset($request['occurred_at'])?PanelOperationsGuard::instant(is_int($request['occurred_at'])?$request['occurred_at']:(string)$request['occurred_at']):null);}

	public function actorId():string{return$this->actorId;}public function ability():string{return$this->ability;}public function tenantId():?string{return$this->tenantId;}public function resourceType():?string{return$this->resourceType;}public function resourceId():?string{return$this->resourceId;}public function risk():string{return$this->risk;}/** @return list<string> */public function roles():array{return$this->roles;}/** @return list<string> */public function permissions():array{return$this->permissions;}/** @return array<string,mixed> */public function context():array{return$this->context;}
	public function can(string $permission):bool {foreach($this->permissions as$granted){if(PanelOperationsGuard::abilityMatches($granted,$permission)){return true;}}return false;}
	public function hasRole(string $role):bool{return in_array(strtolower(trim($role)),$this->roles,true)||in_array('*',$this->roles,true);}
	/** @return array<string,mixed> */ public function attributes():array{return['actor'=>['id'=>$this->actorId,'roles'=>$this->roles,'permissions'=>$this->permissions],'ability'=>$this->ability,'tenant'=>['id'=>$this->tenantId],'resource'=>['type'=>$this->resourceType,'id'=>$this->resourceId],'risk'=>$this->risk,'context'=>$this->context,'occurred_at'=>$this->occurredAt];}
	public function fingerprint():string{return PanelOperationsGuard::digest($this->attributes());}
	public function jsonSerialize():array{return['type'=>'panel_policy_request','actor_id'=>$this->actorId,'ability'=>$this->ability,'tenant_id'=>$this->tenantId,'resource'=>['type'=>$this->resourceType,'id'=>$this->resourceId],'risk'=>$this->risk,'roles'=>$this->roles,'permissions'=>$this->permissions,'context'=>$this->context,'occurred_at'=>$this->occurredAt,'fingerprint'=>$this->fingerprint()];}
}
