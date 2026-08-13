<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Secret-free identity projection suitable for Studio HTML and JSON surfaces. */
final class PanelStudioIdentityProfile implements \JsonSerializable {
	private const STATUSES=['active','suspended','revoked','unknown'];

	public function __construct(
		string|int $id,
		string $displayName,
		private readonly string $status='active',
		private readonly string $source='host'
	){
		$this->id=PanelIamGuard::identifier($id,'Studio identity id');
		$this->displayName=PanelIamGuard::text($displayName,'Studio identity display name',190,true);
		if(!in_array($status,self::STATUSES,true)){throw new \InvalidArgumentException('Studio identity status is invalid.');}
		if(preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$source)!==1){throw new \InvalidArgumentException('Studio identity source is invalid.');}
		$this->initials=self::initialsFor($displayName);
	}

	private readonly string $id;
	private readonly string $displayName;
	private readonly string $initials;

	public static function fromIam(PanelIamPrincipal $principal):self {
		return new self($principal->id(),$principal->displayName(),$principal->status(),'iam');
	}

	public static function unresolved(string|int $id):self {
		$id=PanelIamGuard::identifier($id,'Studio identity id');
		return new self($id,$id,'unknown','unresolved');
	}

	public function id():string{return$this->id;}
	public function displayName():string{return$this->displayName;}
	public function status():string{return$this->status;}
	public function source():string{return$this->source;}
	public function initials():string{return$this->initials;}
	public function assignable():bool{return$this->status==='active';}

	/** @return array<string,mixed> */
	public function jsonSerialize():array {
		return[
			'type'=>'panel_studio_identity_profile','version'=>1,'id'=>$this->id,
			'display_name'=>$this->displayName,'status'=>$this->status,'source'=>$this->source,
			'initials'=>$this->initials,'assignable'=>$this->assignable(),
		];
	}

	private static function initialsFor(string $name):string {
		$parts=preg_split('/[\s._-]+/u',trim($name),-1,PREG_SPLIT_NO_EMPTY);
		$parts=is_array($parts)&&$parts!==[]?$parts:[$name];
		$selected=count($parts)>1?[$parts[0],$parts[array_key_last($parts)]]:[$parts[0],$parts[0]];
		$initials='';
		foreach($selected as$index=>$part){
			preg_match_all('/\X/u',$part,$characters);
			$values=$characters[0]??[];
			$initials.=(string)($values[$index===1&&count($parts)===1?1:0]??$values[0]??'?');
		}
		return function_exists('mb_strtoupper')?mb_strtoupper($initials,'UTF-8'):strtoupper($initials);
	}
}
