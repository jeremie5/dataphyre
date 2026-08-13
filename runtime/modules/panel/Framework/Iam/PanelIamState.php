<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Structural invariants for adapter-neutral, tenant-isolated IAM state. */
final class PanelIamState {
	/** @return array<string,mixed> */
	public static function initial():array{return['schema_version'=>1,'principals'=>[],'service_accounts'=>[],'memberships'=>[],'receipts'=>[],'receipt_order'=>[],'audit'=>['sequence'=>0,'anchor_hash'=>str_repeat('0',64),'events'=>[]]];}

	/** @param array<string,mixed> $state */
	public static function assertValid(array $state,string|int $tenantId):void {
		$tenant=PanelIamGuard::identifier($tenantId,'tenant id');
		$expected=['schema_version','principals','service_accounts','memberships','receipts','receipt_order','audit'];$keys=array_keys($state);sort($keys,SORT_STRING);$sorted=$expected;sort($sorted,SORT_STRING);
		if($keys!==$sorted||($state['schema_version']??null)!==1){throw new \UnexpectedValueException('Panel IAM tenant state schema is invalid.');}
		foreach(['principals','service_accounts','memberships','receipts']as$collection){if(!is_array($state[$collection])||($state[$collection]!==[]&&array_is_list($state[$collection]))){throw new \UnexpectedValueException('Panel IAM '.$collection.' state must be an object-like map.');}}
		if(!is_array($state['receipt_order'])||!array_is_list($state['receipt_order'])){throw new \UnexpectedValueException('Panel IAM receipt order must be a list.');}
		foreach($state['principals']as$id=>$payload){if(!is_array($payload)){throw new \UnexpectedValueException('Panel IAM principal state is invalid.');}$principal=PanelIamPrincipal::restore($payload);if(!is_string($id)||!hash_equals($id,$principal->id())){throw new \UnexpectedValueException('Panel IAM principal map key mismatch.');}}
		foreach($state['service_accounts']as$id=>$payload){if(!is_array($payload)){throw new \UnexpectedValueException('Panel IAM service account state is invalid.');}$account=PanelIamServiceAccount::restore($payload);if(!is_string($id)||!hash_equals($id,$account->id())){throw new \UnexpectedValueException('Panel IAM service account map key mismatch.');}}
		foreach($state['memberships']as$key=>$payload){if(!is_array($payload)){throw new \UnexpectedValueException('Panel IAM membership state is invalid.');}$membership=PanelIamMembership::restore($payload);if(!is_string($key)||!hash_equals($key,$membership->key())||!hash_equals($tenant,$membership->tenantId())){throw new \UnexpectedValueException('Panel IAM membership scope or map key mismatch.');}}
		$order=[];foreach($state['receipt_order']as$digest){if(!is_string($digest)||preg_match('/^[a-f0-9]{64}$/D',$digest)!==1||isset($order[$digest])||!isset($state['receipts'][$digest])){throw new \UnexpectedValueException('Panel IAM receipt order is invalid.');}$order[$digest]=true;}
		if(count($order)!==count($state['receipts'])){throw new \UnexpectedValueException('Panel IAM receipt order is incomplete.');}
		foreach($state['receipts']as$digest=>$payload){if(!is_string($digest)||!is_array($payload)){throw new \UnexpectedValueException('Panel IAM receipt state is invalid.');}$receipt=PanelIamReceipt::restore($payload);if(!hash_equals($digest,(string)($payload['idempotency_digest']??''))||!hash_equals($tenant,$receipt->tenantId())){throw new \UnexpectedValueException('Panel IAM receipt scope or digest mismatch.');}}
		self::assertAudit($state['audit'],$tenant);
	}

	/** @param array<string,mixed> $before @param array<string,mixed> $after */
	public static function assertTransition(array $before,array $after,string|int $tenantId):void {
		self::assertValid($before,$tenantId);self::assertValid($after,$tenantId);
		foreach($before['receipts']as$digest=>$receipt){if(isset($after['receipts'][$digest])&&$after['receipts'][$digest]!==$receipt){throw new \LogicException('Panel IAM receipts are append-only.');}}
		$remaining=array_values(array_filter($before['receipt_order'],static fn(string $digest):bool=>isset($after['receipts'][$digest])));
		$removed=count($before['receipt_order'])-count($remaining);
		if($remaining!==array_slice($before['receipt_order'],$removed)||$remaining!==array_slice($after['receipt_order'],0,count($remaining))){throw new \LogicException('Panel IAM receipt retention may remove only an oldest prefix.');}
		$beforeAudit=[];foreach($before['audit']['events']as$event){$beforeAudit[(string)$event['hash']]=$event;}foreach($after['audit']['events']as$event){$hash=(string)$event['hash'];if(isset($beforeAudit[$hash])&&$beforeAudit[$hash]!==$event){throw new \LogicException('Panel IAM audit events are immutable.');}}
		$beforeEvents=array_column($before['audit']['events'],'hash');$afterEvents=array_column($after['audit']['events'],'hash');$remainingEvents=array_values(array_filter($beforeEvents,static fn(string $hash):bool=>in_array($hash,$afterEvents,true)));
		$removedEvents=count($beforeEvents)-count($remainingEvents);
		if($remainingEvents!==array_slice($beforeEvents,$removedEvents)||$remainingEvents!==array_slice($afterEvents,0,count($remainingEvents))){throw new \LogicException('Panel IAM audit events are append-only.');}
		$expectedAnchor=$removedEvents>0?(string)$before['audit']['events'][$removedEvents-1]['hash']:(string)$before['audit']['anchor_hash'];if(!hash_equals($expectedAnchor,(string)$after['audit']['anchor_hash'])){throw new \LogicException('Panel IAM audit anchor does not match the retained suffix.');}
		$appended=count($afterEvents)-count($remainingEvents);if((int)$after['audit']['sequence']!==(int)$before['audit']['sequence']+$appended){throw new \LogicException('Panel IAM audit sequence must advance exactly once per appended event.');}
		foreach($after['receipts']as$digest=>$receipt){if(isset($before['receipts'][$digest])){continue;}$linked=false;foreach($after['audit']['events']as$event){if(($event['receipt_id']??null)===($receipt['id']??null)&&($event['hash']??null)===($receipt['audit_hash']??null)){$linked=true;break;}}if(!$linked){throw new \LogicException('New Panel IAM receipts require a matching audit event.');}}
	}

	/** @param mixed $audit */
	private static function assertAudit(mixed $audit,string $tenant):void {
		if(!is_array($audit)||array_keys($audit)!==['sequence','anchor_hash','events']||!is_int($audit['sequence'])||$audit['sequence']<0||!is_string($audit['anchor_hash'])||preg_match('/^[a-f0-9]{64}$/D',$audit['anchor_hash'])!==1||!is_array($audit['events'])||!array_is_list($audit['events'])){throw new \UnexpectedValueException('Panel IAM audit state is invalid.');}
		$previous=$audit['anchor_hash'];$sequence=null;
		foreach($audit['events']as$payload){if(!is_array($payload)){throw new \UnexpectedValueException('Panel IAM audit event state is invalid.');}$event=PanelIamAuditEvent::restore($payload);if(!hash_equals($tenant,$event->tenantId())||!hash_equals($previous,$event->previousHash())||($sequence!==null&&$event->sequence()!==$sequence+1)){throw new \UnexpectedValueException('Panel IAM audit chain structure is invalid.');}$previous=$event->hash();$sequence=$event->sequence();}
		if($sequence!==null&&$sequence!==$audit['sequence']){throw new \UnexpectedValueException('Panel IAM audit sequence does not match its event tail.');}
	}
}
