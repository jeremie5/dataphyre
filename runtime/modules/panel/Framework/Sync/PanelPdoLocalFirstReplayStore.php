<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Transactional PDO replay ledger with fenced leases and exact signed-response recovery. */
final class PanelPdoLocalFirstReplayStore implements PanelLocalFirstReplayStore,\JsonSerializable {
	private readonly string $driver;private readonly string $table;private int $savepoint=0;
	private bool $manualSqliteWriteTransaction=false;
	public function __construct(private readonly \PDO $pdo,string $table='dataphyre_panel_local_first_replay'){$this->driver=strtolower((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));if(!in_array($this->driver,['sqlite','pgsql','mysql'],true)){throw new \InvalidArgumentException('Local-first PDO replay stores support sqlite, pgsql, and mysql.');}$this->table=PanelLocalFirstReplaySchema::table($table);$pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);}
	public function migrate():self{try{PanelLocalFirstReplaySchema::migrate($this->pdo,$this->table);}catch(\Throwable $error){throw self::storage($error);}return$this;}

	public function claim(string $credentialId,int $sequence,string $requestDigest,string|int|\DateTimeInterface $now,int $leaseSeconds=30):PanelLocalFirstReplayClaim{$credentialId=self::id($credentialId);self::sequence($sequence);self::digest($requestDigest);if($leaseSeconds<1||$leaseSeconds>300){throw new \InvalidArgumentException('Local-first replay leases must be 1-300 seconds.');}$instant=PanelOperationsGuard::instant($now);$epoch=strtotime($instant);$expires=$epoch+$leaseSeconds;$token=bin2hex(random_bytes(32));
		try{return$this->transaction(function()use($credentialId,$sequence,$requestDigest,$instant,$epoch,$expires,$token):PanelLocalFirstReplayClaim{$row=$this->row($credentialId,$sequence,true);if($row!==null){if(!hash_equals($row['request_digest'],$requestDigest)){throw new \LogicException('Local-first request sequence was rebound.');}if($row['state']==='completed'){return PanelLocalFirstReplayClaim::replay($credentialId,$sequence,$requestDigest,$this->responseFromRow($row));}if($row['state']!=='pending'||!is_string($row['lease_token'])||preg_match('/^[a-f0-9]{64}$/D',$row['lease_token'])!==1){throw new \UnexpectedValueException('Stored local-first replay row is invalid.');}if((int)$row['lease_expires_at']>$epoch){throw new PanelLocalFirstReplayException('request_in_flight','The local-first request is already being processed.',true);}$statement=$this->pdo->prepare("UPDATE {$this->table} SET lease_token = :token, lease_expires_at = :expires, updated_at = :updated WHERE credential_id = :credential AND sequence = :sequence AND state = 'pending' AND request_digest = :digest");$statement->execute(['token'=>$token,'expires'=>$expires,'updated'=>$instant,'credential'=>$credentialId,'sequence'=>$sequence,'digest'=>$requestDigest]);if($statement->rowCount()!==1){throw new PanelLocalFirstReplayException('lease_lost','The local-first replay lease was lost.',true);}return PanelLocalFirstReplayClaim::acquired($credentialId,$sequence,$requestDigest,$token,PanelOperationsGuard::instant($expires));}
			$latest=$this->latestRow($credentialId,true);if($latest!==null){if($latest['state']!=='completed'){throw new PanelLocalFirstReplayException('prior_request_in_flight','A prior local-first request is still being processed.',true);}if($sequence!==(int)$latest['sequence']+1){throw new \LogicException('Local-first request sequence is not contiguous.');}}elseif($sequence!==1){throw new \LogicException('Local-first request sequence is not contiguous.');}$statement=$this->pdo->prepare("INSERT INTO {$this->table} (credential_id, sequence, request_digest, state, lease_token, lease_expires_at, response_json, created_at, updated_at) VALUES (:credential, :sequence, :digest, 'pending', :token, :expires, NULL, :created, :updated)");$statement->execute(['credential'=>$credentialId,'sequence'=>$sequence,'digest'=>$requestDigest,'token'=>$token,'expires'=>$expires,'created'=>$instant,'updated'=>$instant]);return PanelLocalFirstReplayClaim::acquired($credentialId,$sequence,$requestDigest,$token,PanelOperationsGuard::instant($expires));});}catch(PanelLocalFirstReplayException|\LogicException|\InvalidArgumentException $error){throw$error;}catch(\Throwable $error){throw self::storage($error);}}

	public function complete(PanelLocalFirstReplayClaim $claim,PanelLocalFirstResponse $response):void{if(!$claim->acquiredLease()||$response->sequence()!==$claim->sequence()||!hash_equals($response->requestDigest(),$claim->requestDigest())){throw new \InvalidArgumentException('Local-first replay completion does not match its claim.');}$json=json_encode($response->jsonSerialize(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);$now=PanelOperationsGuard::instant(time());try{$this->transaction(function()use($claim,$response,$json,$now):void{$statement=$this->pdo->prepare("UPDATE {$this->table} SET state = 'completed', lease_token = NULL, lease_expires_at = 0, response_json = :response, updated_at = :updated WHERE credential_id = :credential AND sequence = :sequence AND state = 'pending' AND request_digest = :digest AND lease_token = :token");$statement->execute(['response'=>$json,'updated'=>$now,'credential'=>$claim->credentialId(),'sequence'=>$claim->sequence(),'digest'=>$claim->requestDigest(),'token'=>$claim->leaseToken()]);if($statement->rowCount()===1){return;}$existing=$this->row($claim->credentialId(),$claim->sequence(),true);if($existing!==null&&$existing['state']==='completed'&&hash_equals($this->responseFromRow($existing)->digest(),$response->digest())){return;}throw new PanelLocalFirstReplayException('lease_lost','The local-first replay lease was lost.',true);});}catch(PanelLocalFirstReplayException $error){throw$error;}catch(\Throwable $error){throw self::storage($error);}}

	public function abandon(PanelLocalFirstReplayClaim $claim):void{if(!$claim->acquiredLease()){return;}try{$statement=$this->pdo->prepare("DELETE FROM {$this->table} WHERE credential_id = :credential AND sequence = :sequence AND state = 'pending' AND request_digest = :digest AND lease_token = :token");$statement->execute(['credential'=>$claim->credentialId(),'sequence'=>$claim->sequence(),'digest'=>$claim->requestDigest(),'token'=>$claim->leaseToken()]);}catch(\Throwable $error){throw self::storage($error);}}
	public function response(string $credentialId,int $sequence):?PanelLocalFirstResponse{$credentialId=self::id($credentialId);self::sequence($sequence);try{$row=$this->row($credentialId,$sequence,false);return$row!==null&&$row['state']==='completed'?$this->responseFromRow($row):null;}catch(PanelLocalFirstReplayException $error){throw$error;}catch(\Throwable $error){throw self::storage($error);}}
	public function latestSequence(string $credentialId):int{$credentialId=self::id($credentialId);try{$statement=$this->pdo->prepare("SELECT MAX(sequence) FROM {$this->table} WHERE credential_id = :credential AND state = 'completed'");$statement->execute(['credential'=>$credentialId]);$value=$statement->fetchColumn();return$value===false||$value===null?0:(int)$value;}catch(\Throwable $error){throw self::storage($error);}}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_local_first_replay_store_manifest','version'=>2,'driver'=>$this->driver,'table_hash'=>hash('sha256',$this->table),'connection_details_serialized'=>false,'schema_mutated_implicitly'=>false,'capabilities'=>['exact_response_replay'=>true,'contiguous_sequences'=>true,'fenced_leases'=>true,'expired_lease_takeover'=>true,'durable'=>true,'transactions'=>true,'host_transactions_preserved'=>true]]);}

	/** @return array<string,mixed>|null */private function row(string $credentialId,int $sequence,bool $lock):?array{$suffix=$lock&&$this->driver!=='sqlite'?' FOR UPDATE':'';$statement=$this->pdo->prepare("SELECT credential_id, sequence, request_digest, state, lease_token, lease_expires_at, response_json FROM {$this->table} WHERE credential_id = :credential AND sequence = :sequence{$suffix}");$statement->execute(['credential'=>$credentialId,'sequence'=>$sequence]);$row=$statement->fetch(\PDO::FETCH_ASSOC);return$row===false?null:$this->validateRow($row);}
	/** @return array<string,mixed>|null */private function latestRow(string $credentialId,bool $lock):?array{$suffix=$lock&&$this->driver!=='sqlite'?' FOR UPDATE':'';$statement=$this->pdo->prepare("SELECT credential_id, sequence, request_digest, state, lease_token, lease_expires_at, response_json FROM {$this->table} WHERE credential_id = :credential ORDER BY sequence DESC LIMIT 1{$suffix}");$statement->execute(['credential'=>$credentialId]);$row=$statement->fetch(\PDO::FETCH_ASSOC);return$row===false?null:$this->validateRow($row);}
	/** @param array<string,mixed> $row @return array<string,mixed> */private function validateRow(array $row):array{if(!is_string($row['credential_id']??null)||!is_numeric($row['sequence']??null)||!is_string($row['request_digest']??null)||!in_array($row['state']??null,['pending','completed'],true)||(!is_string($row['lease_token']??null)&&($row['lease_token']??null)!==null)||!is_numeric($row['lease_expires_at']??null)||(!is_string($row['response_json']??null)&&($row['response_json']??null)!==null)){throw new \UnexpectedValueException('Stored local-first replay row is invalid.');}$row['sequence']=(int)$row['sequence'];$row['lease_expires_at']=(int)$row['lease_expires_at'];self::id($row['credential_id']);self::sequence($row['sequence']);self::digest($row['request_digest']);if(($row['state']==='pending'&&($row['lease_token']===null||$row['response_json']!==null))||($row['state']==='completed'&&($row['lease_token']!==null||$row['response_json']===null))){throw new \UnexpectedValueException('Stored local-first replay row state is invalid.');}return$row;}
	/** @param array<string,mixed> $row */private function responseFromRow(array $row):PanelLocalFirstResponse{$json=$row['response_json']??null;if(!is_string($json)||strlen($json)>4194304){throw new \UnexpectedValueException('Stored local-first response is invalid.');}$payload=json_decode($json,true,64,JSON_THROW_ON_ERROR);if(!is_array($payload)){throw new \UnexpectedValueException('Stored local-first response is invalid.');}$response=PanelLocalFirstResponse::hydrate($payload);if($response->sequence()!==(int)$row['sequence']||!hash_equals($response->requestDigest(),(string)$row['request_digest'])){throw new \UnexpectedValueException('Stored local-first response binding is invalid.');}return$response;}
	private function transaction(callable $callback):mixed{
		$owned=!$this->activeTransaction();$savepoint='dp_lfr_'.(++$this->savepoint);
		try{
			if($owned){
				if($this->driver==='sqlite'){
					if($this->pdo->exec('BEGIN IMMEDIATE')===false){throw new \RuntimeException('PDO transaction begin failed.');}
					$this->manualSqliteWriteTransaction=true;
				}elseif(!$this->pdo->beginTransaction()){throw new \RuntimeException('PDO transaction begin failed.');}
			}elseif($this->pdo->exec("SAVEPOINT {$savepoint}")===false){throw new \RuntimeException('PDO savepoint begin failed.');}
			$result=$callback();
			if($owned){
				if($this->manualSqliteWriteTransaction){
					if($this->pdo->exec('COMMIT')===false){throw new \RuntimeException('PDO commit failed.');}
					$this->manualSqliteWriteTransaction=false;
				}elseif(!$this->pdo->commit()){throw new \RuntimeException('PDO commit failed.');}
			}elseif($this->pdo->exec("RELEASE SAVEPOINT {$savepoint}")===false){throw new \RuntimeException('PDO savepoint release failed.');}
			return$result;
		}catch(\Throwable $error){
			try{
				if($owned){
					if($this->manualSqliteWriteTransaction){$this->pdo->exec('ROLLBACK');}
					elseif($this->pdo->inTransaction()){$this->pdo->rollBack();}
				}elseif($this->pdo->inTransaction()){
					$this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
					$this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
				}
			}catch(\Throwable){}
			finally{if($owned){$this->manualSqliteWriteTransaction=false;}}
			throw$error;
		}
	}
	private function activeTransaction():bool{return$this->manualSqliteWriteTransaction||$this->pdo->inTransaction();}
	private static function storage(\Throwable $error):PanelLocalFirstReplayException{return new PanelLocalFirstReplayException('storage_unavailable','The local-first replay ledger is unavailable.',true);}
	private static function id(string $value):string{if(preg_match('/^lfc_[a-f0-9]{48}$/D',$value)!==1){throw new \InvalidArgumentException('Local-first credential id is invalid.');}return$value;}private static function sequence(int $value):void{if($value<1){throw new \InvalidArgumentException('Local-first replay sequence must be positive.');}}private static function digest(string $value):void{if(preg_match('/^[a-f0-9]{64}$/D',$value)!==1){throw new \InvalidArgumentException('Local-first request digest is invalid.');}}
}
