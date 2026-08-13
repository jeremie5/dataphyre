<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Studio identity directory backed exclusively by an actor/tenant-scoped IAM facade. */
final class PanelStudioIamIdentityConnector implements PanelStudioIdentityConnector {
	public function __construct(private readonly PanelScopedIamManager $iam){}
	public function tenantId():?string{return$this->iam->tenantId();}

	public function resolve(array $ids):array {
		if(count($ids)>200){throw new \LengthException('Studio IAM identity resolution supports at most 200 ids.');}
		$result=[];
		foreach($ids as$id){
			$id=PanelIamGuard::identifier($id,'Studio identity id');$principal=$this->iam->principal($id);
			if($principal instanceof PanelIamPrincipal){$result[$id]=PanelStudioIdentityProfile::fromIam($principal);}
		}
		return$result;
	}

	public function search(string $query='',int $limit=25):array {
		$query=trim($query);
		if(strlen($query)>190||($query!==''&&preg_match('//u',$query)!==1)){throw new \InvalidArgumentException('Studio identity search query is invalid.');}
		$limit=max(1,min(200,$limit));$needle=self::fold($query);$profiles=[];
		foreach($this->iam->principals(1000)as$principal){
			if($needle!==''&&!str_contains(self::fold($principal->displayName()."\0".$principal->id()),$needle)){continue;}
			$profiles[]=PanelStudioIdentityProfile::fromIam($principal);
			if(count($profiles)>=$limit){break;}
		}
		return$profiles;
	}

	public function manifest():array {
		return[
			'type'=>'panel_studio_identity_connector','version'=>1,'adapter'=>'scoped_iam',
			'tenant_bound'=>true,'actor_bound'=>true,
			'capabilities'=>['resolve'=>true,'search'=>true,'status'=>true],
			'security'=>[
				'scoped_iam_reads'=>true,'unscoped_manager_reads'=>false,
				'email_serialized'=>false,'credentials_serialized'=>false,
			],
		];
	}
	public function jsonSerialize():array{return$this->manifest();}
	private static function fold(string $value):string{return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);}
}
