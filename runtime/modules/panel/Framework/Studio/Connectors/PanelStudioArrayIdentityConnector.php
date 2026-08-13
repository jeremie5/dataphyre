<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Deterministic host/testing identity directory with no authentication material. */
final class PanelStudioArrayIdentityConnector implements PanelStudioIdentityConnector {
	/** @var array<string,PanelStudioIdentityProfile> */
	private array $profiles=[];
	private readonly ?string $tenant;

	/** @param array<int|string,PanelStudioIdentityProfile|array<string,mixed>> $profiles */
	public function __construct(array $profiles,?string $tenantId=null){
		if(count($profiles)>1000){throw new \LengthException('Studio identity directories support at most 1000 profiles.');}
		$this->tenant=$tenantId!==null?PanelStudioDocument::scope($tenantId,'tenant'):null;
		foreach($profiles as$value){
			if($value instanceof PanelStudioIdentityProfile){$profile=$value;}
			elseif(is_array($value)&&!array_is_list($value)){
				$extra=array_diff(array_keys($value),['id','display_name','status','source']);
				if($extra!==[]){throw new \InvalidArgumentException('Studio identity profile payload contains unsupported fields.');}
				$profile=new PanelStudioIdentityProfile(
					$value['id']??'',
					is_string($value['display_name']??null)?$value['display_name']:'',
					is_string($value['status']??null)?$value['status']:'active',
					is_string($value['source']??null)?$value['source']:'host'
				);
			}else{throw new \InvalidArgumentException('Studio identity directories require identity profiles.');}
			if(isset($this->profiles[$profile->id()])){throw new \LogicException('Studio identity directory ids must be unique.');}
			$this->profiles[$profile->id()]=$profile;
		}
	}

	public function tenantId():?string{return$this->tenant;}

	public function resolve(array $ids):array {
		if(count($ids)>200){throw new \LengthException('Studio identity resolution supports at most 200 ids.');}
		$result=[];
		foreach($ids as$id){
			$id=PanelIamGuard::identifier($id,'Studio identity id');
			if(isset($this->profiles[$id])){$result[$id]=$this->profiles[$id];}
		}
		return$result;
	}

	public function search(string $query='',int $limit=25):array {
		$query=self::query($query);$limit=max(1,min(200,$limit));$needle=self::fold($query);$matches=[];
		foreach($this->profiles as$profile){
			if($needle!==''&&!str_contains(self::fold($profile->displayName()."\0".$profile->id()),$needle)){continue;}
			$matches[]=$profile;
		}
		usort($matches,static fn(PanelStudioIdentityProfile $left,PanelStudioIdentityProfile $right):int=>[self::fold($left->displayName()),$left->id()]<=>[self::fold($right->displayName()),$right->id()]);
		return array_slice($matches,0,$limit);
	}

	public function manifest():array {
		return[
			'type'=>'panel_studio_identity_connector','version'=>1,'adapter'=>'array',
			'count'=>count($this->profiles),'tenant_bound'=>$this->tenant!==null,
			'capabilities'=>['resolve'=>true,'search'=>true,'status'=>true],
			'security'=>['email_serialized'=>false,'credentials_serialized'=>false],
		];
	}
	public function jsonSerialize():array{return$this->manifest();}

	private static function query(string $query):string {
		$query=trim($query);
		if(strlen($query)>190||($query!==''&&preg_match('//u',$query)!==1)){throw new \InvalidArgumentException('Studio identity search query is invalid.');}
		return$query;
	}
	private static function fold(string $value):string{return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);}
}
