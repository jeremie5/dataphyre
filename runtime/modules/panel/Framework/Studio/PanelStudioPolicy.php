<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Explicit Studio authorization policy; failures and exceptions always deny. */
final class PanelStudioPolicy implements PanelStudioAuthorization {
	public const ACTIONS=['read','save','approve','publish','rollback','preview'];
	private readonly \Closure $decision;
	/** @param callable(string,string,string,string):bool $decision */
	private function __construct(callable $decision,private readonly string $name){if(preg_match('/^[a-z][a-z0-9_.-]{0,63}$/',$name)!==1){throw new \InvalidArgumentException('Studio policy names must be safe identifiers.');}$this->decision=$decision(...);}
	public static function denyAll():self{return new self(static fn():bool=>false,'deny_all');}
	/** @param callable(string,string,string,string):bool $decision */ public static function permit(callable $decision,string $name='host_policy'):self{return new self($decision,$name);}
	/** Explicit bypass for tightly scoped repair tooling, never enabled implicitly. @param list<string> $principals */
	public static function trustedMaintenance(array $principals):self{
		$trusted=[];foreach($principals as$principal){$trusted[PanelStudioDocument::scope($principal,'maintenance principal')]=true;}if($trusted===[]){throw new \InvalidArgumentException('Trusted Studio maintenance requires at least one principal.');}
		return new self(static fn(string $action,string $tenant,string $principal,string $document):bool=>isset($trusted[$principal]),'trusted_maintenance');
	}
	public function allows(string $action,string $tenantId,string $principalId,string $documentId):bool{
		if(!in_array($action,self::ACTIONS,true)){return false;}
		try{PanelStudioDocument::scope($tenantId,'tenant');PanelStudioDocument::scope($principalId,'principal');PanelStudioDocument::scope($documentId,'document');return($this->decision)($action,$tenantId,$principalId,$documentId)===true;}catch(\Throwable){return false;}
	}
	public function manifest():array{return['type'=>'panel_studio_authorization_manifest','version'=>1,'policy'=>$this->name,'actions'=>self::ACTIONS,'fail_closed'=>true,'decision_serialized'=>false,'trusted_maintenance'=>$this->name==='trusted_maintenance'];}
	public function jsonSerialize():array{return$this->manifest();}
}
