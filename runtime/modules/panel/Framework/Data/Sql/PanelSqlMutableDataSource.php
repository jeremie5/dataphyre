<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Durable SQL mutation adapter with fail-closed authorization, CAS writes, and transactional replay receipts. */
final class PanelSqlMutableDataSource implements PanelMutableDataSource, \JsonSerializable {
	private readonly PanelSqlDataSource $readSource;
	private readonly string $name;
	private readonly string $driver;
	private readonly ?\Closure $authorize;
	private readonly \Closure $clock;

	/** @param array<string,mixed> $options */
	public function __construct(private readonly PanelSqlMutationExecutor $executor,private readonly PanelSqlMutationSchema $mutationSchema,array $options=[]){
		$mutationKeys=['mutation_authorize','mutation_clock'];$readOptions=$options;
		foreach($mutationKeys as$key){unset($readOptions[$key]);}
		if(array_key_exists('mutation_authorize',$options)&&!is_callable($options['mutation_authorize'])){throw new \InvalidArgumentException('Panel SQL mutation_authorize must be callable.');}
		if(array_key_exists('mutation_clock',$options)&&!is_callable($options['mutation_clock'])){throw new \InvalidArgumentException('Panel SQL mutation_clock must be callable.');}
		$this->authorize=isset($options['mutation_authorize'])?\Closure::fromCallable($options['mutation_authorize']):null;
		$this->clock=isset($options['mutation_clock'])?\Closure::fromCallable($options['mutation_clock']):static fn():string=>gmdate(DATE_ATOM);
		$this->readSource=new PanelSqlDataSource($executor,$mutationSchema->schema(),$readOptions);
		$manifest=$this->readSource->manifest();$this->name=(string)$manifest['name'];$this->driver=$executor->driver();
	}

	/** @param array<string,mixed> $options */
	public static function usingPdo(\PDO $pdo,PanelSqlMutationSchema $schema,array $options=[]):self{return new self(new PanelPdoSqlExecutor($pdo),$schema,$options);}

	public function query(PanelDataQuery $query):PanelDataResult{return$this->readSource->query($query);}
	public function find(string|int $id,?PanelDataQuery $scope=null):mixed{return$this->readSource->find($id,$scope);}

	/** @return array<string,mixed> */
	public function capabilities():array{
		$writable=$this->authorize!==null;
		return array_replace($this->readSource->capabilities(),[
			'mutations'=>$writable,'mutation_operations'=>$writable?$this->mutationSchema->operations():[],
			'mutation_batch'=>$writable,'mutation_atomic_batch'=>$writable,'mutation_max_batch'=>$writable?$this->mutationSchema->maxBatch():1,
			'mutation_optimistic_concurrency'=>$writable,'mutation_idempotency'=>$writable,
			'mutation_idempotency_scope'=>$writable?'database':'none','mutation_tenant'=>$this->mutationSchema->schema()->tenantField()!==null,
			'mutation_authorization'=>$writable,'mutation_returning'=>$writable,'change_feed'=>false,
		]);
	}

	public function mutate(PanelDataMutation $mutation):PanelDataMutationReceipt{
		PanelDataMutationCapabilities::fromArray($this->capabilities())->assertSupports($mutation);
		return$this->transaction(fn():PanelDataMutationReceipt=>$this->mutateInsideTransaction($mutation));
	}

	public function mutateBatch(PanelDataMutationBatch $batch):PanelDataMutationBatchResult{
		PanelDataMutationCapabilities::fromArray($this->capabilities())->assertSupports($batch);
		if($batch->atomic()){
			$receipts=$this->transaction(function()use($batch):array{$receipts=[];foreach($batch->mutations()as$mutation){$receipts[]=$this->mutateInsideTransaction($mutation);}return$receipts;});
		}else{$receipts=[];foreach($batch->mutations()as$mutation){$receipts[]=$this->mutate($mutation);}}
		return new PanelDataMutationBatchResult($batch,$receipts,$this->name);
	}

	/** Explicit, idempotent receipt-table installation; never invoked by construction or mutation. @return array<string,mixed> */
	public function installMutationSchema():array{
		try{$statements=$this->mutationSchema->migrationStatements($this->driver);foreach($statements as$sql){$this->executor->execute($sql);} $this->inspectMutationSchema();}
		catch(\Throwable $error){throw new PanelDataMutationException('mutation_schema_migration_failed','The SQL mutation receipt schema could not be installed.',503,true,$error);}
		return['type'=>'panel_sql_mutation_schema_installation','version'=>1,'driver'=>$this->driver,'schema_version'=>PanelSqlMutationSchema::RECEIPT_SCHEMA_VERSION,'statements'=>count($statements),'idempotent'=>true,'destructive'=>false];
	}

	/** Fail-closed schema probe without writes. @return array<string,mixed> */
	public function inspectMutationSchema():array{
		$table=$this->mutationSchema->quoteReceiptTable($this->driver);
		try{$rows=$this->executor->rows("SELECT source_name, idempotency_digest, mutation_fingerprint, schema_version, receipt_json, created_at FROM {$table} WHERE 1 = 0");if($rows!==[]){throw new \UnexpectedValueException('Panel SQL mutation schema probe unexpectedly returned rows.');}}
		catch(\Throwable $error){throw new PanelDataMutationException('mutation_schema_required','The SQL mutation receipt schema is missing or incompatible.',503,false,$error);}
		return['type'=>'panel_sql_mutation_schema_inspection','version'=>1,'driver'=>$this->driver,'compatible'=>true,'schema_version'=>PanelSqlMutationSchema::RECEIPT_SCHEMA_VERSION,'writes_performed'=>false];
	}

	/** @return array<string,mixed> */
	public function manifest():array{return[
		'type'=>'panel_sql_mutable_data_source','version'=>1,'name'=>$this->name,'driver'=>$this->driver,
		'read_source'=>$this->readSource->manifest(),'mutation_schema'=>$this->mutationSchema->manifest(),'capabilities'=>$this->capabilities(),
		'authorization'=>['configured'=>$this->authorize!==null,'fail_closed'=>true,'decision'=>'strict_true','callback_serialized'=>false,'rechecked_on_replay'=>true],
		'consistency'=>['transactional_receipts'=>true,'persistent_idempotency'=>true,'optimistic_concurrency'=>true,'atomic_batch'=>true,'non_atomic_batch_explicit'=>true,'nested_transaction_savepoints'=>true],
		'security'=>['raw_sql_accepted'=>false,'identifiers_allowlisted'=>true,'values_parameterized'=>true,'identity_immutable'=>true,'tenant_immutable'=>true,'raw_idempotency_stored'=>false,'authorization_metadata_serialized'=>false,'connection_details_serialized'=>false,'automatic_schema_mutation'=>false],
	];}
	/** @return array<string,mixed> */public function jsonSerialize():array{return$this->manifest();}
	public function readSource():PanelSqlDataSource{return$this->readSource;}
	public function executor():PanelSqlMutationExecutor{return$this->executor;}
	public function mutationSchema():PanelSqlMutationSchema{return$this->mutationSchema;}

	private function mutateInsideTransaction(PanelDataMutation $mutation):PanelDataMutationReceipt{
		$values=$this->validatedValues($mutation);
		$existing=$this->storedReceipt($mutation);
		$before=$this->record($mutation->key(),$mutation->tenantKey(),true);
		$candidate=$this->candidate($mutation,$before,$values);
		$this->authorizeMutation($mutation,$before,$candidate);
		if($existing!==null){return$existing->asReplay();}
		$operation=$mutation->operation();$revision=$before===null?0:$this->revision($before);
		if($operation==='create'&&$before!==null){throw new PanelDataMutationConflict('record_exists','The record already exists.');}
		if(in_array($operation,['update','delete'],true)&&$before===null){throw new PanelDataMutationException('record_not_found','The record does not exist.',404,false);}
		if($operation==='upsert'&&$before!==null&&$mutation->expectedRevision()===null){throw new PanelDataMutationConflict('expected_revision_required','Updating an existing record through upsert requires its expected revision.');}
		if($before!==null&&$mutation->expectedRevision()!==null&&$mutation->expectedRevision()!==$revision){throw new PanelDataMutationConflict('revision_conflict','The record changed after the mutation was prepared.',true);}

		$occurredAt=$this->now();$outcome='unchanged';$changed=[];$after=$candidate;
		if($operation==='delete'){
			$this->deleteRecord($mutation,$revision);$revision++;$outcome='deleted';$after=null;
		}elseif($before===null){
			$this->insertRecord($mutation,$values);$revision=1;$outcome='created';$after=$this->requiredRecord($mutation->key(),$mutation->tenantKey());$changed=array_keys($after);
		}else{
			$changed=$this->changedFields($before,$values);
			if($changed!==[]){$this->updateRecord($mutation,$values,$revision);$revision++;$outcome='updated';$after=$this->requiredRecord($mutation->key(),$mutation->tenantKey());}
			else{$after=$before;}
		}
		$receipt=new PanelDataMutationReceipt($this->name,$operation,$mutation->key(),$outcome,$revision,$mutation->fingerprint(),$mutation->idempotencyDigest(),$occurredAt,$mutation->returnsRecord()&&$operation!=='delete'?$after:null,$changed,['actor_hash'=>hash('sha256',(string)$mutation->actorId()),'reason_present'=>$mutation->reason()!=='','persistence'=>'sql']);
		$this->storeReceipt($receipt);return$receipt;
	}

	/** @return array<string,null|bool|int|float|string> */
	private function validatedValues(PanelDataMutation $mutation):array{
		$schema=$this->mutationSchema->schema();$values=[];
		foreach($mutation->values()as$field=>$value){
			if($field===$schema->primaryKey()){
				if(!$this->sameIdentity($value,$mutation->key())){throw new PanelDataMutationConflict('record_key_conflict','Mutation values cannot replace the record key.');}
				continue;
			}
			if($field===$schema->tenantField()){
				if(!$this->sameIdentity($value,$mutation->tenantKey())){throw new PanelDataMutationAccessDenied('tenant_mismatch','Mutation values cannot replace the tenant.');}
				continue;
			}
			if(!$this->mutationSchema->writable($field)){throw new PanelDataMutationUnsupported(['field:'.$field],'The SQL data source does not allow this field to be mutated.');}
			if(!($value===null||is_bool($value)||is_int($value)||is_string($value)||(is_float($value)&&is_finite($value)))){throw new PanelDataMutationUnsupported(['scalar_value:'.$field],'The SQL adapter accepts only finite scalar field values.');}
			$values[$field]=$value;
		}
		if($schema->tenantField()!==null&&$mutation->tenantKey()===null){throw new PanelDataMutationAccessDenied('tenant_required','Tenant-scoped SQL mutations require an explicit tenant.');}
		return$values;
	}

	/** @param array<string,mixed>|null $before @param array<string,null|bool|int|float|string> $values @return array<string,mixed>|null */
	private function candidate(PanelDataMutation $mutation,?array $before,array $values):?array{
		if($mutation->operation()==='delete'){return null;}$schema=$this->mutationSchema->schema();
		$candidate=$before===null?$values:array_replace($before,$values);$candidate[$schema->primaryKey()]=$mutation->key();
		if($schema->tenantField()!==null){$candidate[$schema->tenantField()]=$mutation->tenantKey();}
		$candidate[$this->mutationSchema->revisionField()]=$before===null?1:$this->revision($before);
		return$candidate;
	}

	/** @param array<string,mixed>|null $before @param array<string,mixed>|null $after */
	private function authorizeMutation(PanelDataMutation $mutation,?array $before,?array $after):void{
		if($this->authorize===null){throw new PanelDataMutationAccessDenied('mutation_authorizer_missing','The SQL data source has no trusted mutation authorizer.');}
		try{$decision=($this->authorize)($mutation,$before,$after,$this);}
		catch(PanelDataMutationAccessDenied $error){throw$error;}
		catch(\Throwable $error){throw new PanelDataMutationAccessDenied('mutation_authorization_failed','The mutation authorization decision failed.',$error);}
		if($decision!==true){throw new PanelDataMutationAccessDenied();}
	}

	private function insertRecord(PanelDataMutation $mutation,array $values):void{
		$schema=$this->mutationSchema->schema();$fields=[$schema->primaryKey()];$data=[$schema->primaryKey()=>$mutation->key()];
		if($schema->tenantField()!==null){$fields[]=$schema->tenantField();$data[$schema->tenantField()]=$mutation->tenantKey();}
		foreach($values as$field=>$value){$fields[]=$field;$data[$field]=$value;}
		$fields[]=$this->mutationSchema->revisionField();$data[$this->mutationSchema->revisionField()]=1;
		$params=[];$columns=[];$placeholders=[];foreach($fields as$field){$columns[]=$this->column($field);$placeholders[]=$this->bind($params,$data[$field]);}
		$sql='INSERT INTO '.$this->table().' ('.implode(', ',$columns).') VALUES ('.implode(', ',$placeholders).')';
		try{$count=$this->executor->execute($sql,$params);}catch(PanelSqlExecutionException $error){throw new PanelDataMutationConflict('record_exists','The record could not be created because its identity is already in use.',false,$error);}
		if($count!==1){throw$this->corrupt();}
	}

	private function updateRecord(PanelDataMutation $mutation,array $values,int $revision):void{
		$params=[];$sets=[];foreach($values as$field=>$value){$sets[]=$this->column($field).' = '.$this->bind($params,$value);}
		$sets[]=$this->column($this->mutationSchema->revisionField()).' = '.$this->bind($params,$revision+1);
		$where=$this->identityWhere($params,$mutation->key(),$mutation->tenantKey());$where[]=$this->column($this->mutationSchema->revisionField()).' = '.$this->bind($params,$revision);
		$count=$this->executor->execute('UPDATE '.$this->table().' SET '.implode(', ',$sets).' WHERE '.implode(' AND ',$where),$params);
		if($count!==1){throw new PanelDataMutationConflict('revision_conflict','The record changed while the mutation was being committed.',true);}
	}

	private function deleteRecord(PanelDataMutation $mutation,int $revision):void{
		$params=[];$where=$this->identityWhere($params,$mutation->key(),$mutation->tenantKey());$where[]=$this->column($this->mutationSchema->revisionField()).' = '.$this->bind($params,$revision);
		$count=$this->executor->execute('DELETE FROM '.$this->table().' WHERE '.implode(' AND ',$where),$params);
		if($count!==1){throw new PanelDataMutationConflict('revision_conflict','The record changed while the mutation was being committed.',true);}
	}

	/** @return array<string,mixed>|null */
	private function record(string|int $key,string|int|null $tenant,bool $lock):?array{
		$params=[];$projection=[];foreach($this->mutationSchema->schema()->fieldNames()as$field){$projection[]=$this->column($field).' AS '.$this->mutationSchema->quoteAlias($field,$this->driver);}
		$where=$this->identityWhere($params,$key,$tenant);$lockSql=$lock&&$this->driver!=='sqlite'?' FOR UPDATE':'';
		$rows=$this->executor->rows('SELECT '.implode(', ',$projection).' FROM '.$this->table().' WHERE '.implode(' AND ',$where).' LIMIT 2'.$lockSql,$params);
		if(count($rows)>1){throw new PanelDataMutationConflict('duplicate_record_key','The SQL data source contains a duplicated scoped record key.');}
		if($rows===[]){return null;}$row=$rows[0];if(!is_array($row)||array_is_list($row)){throw$this->corrupt();}
		foreach($this->mutationSchema->schema()->fieldNames()as$field){if(!array_key_exists($field,$row)){throw$this->corrupt();}}
		$this->revision($row);return$row;
	}

	/** @return array<string,mixed> */
	private function requiredRecord(string|int $key,string|int|null $tenant):array{return$this->record($key,$tenant,false)??throw$this->corrupt();}

	private function storedReceipt(PanelDataMutation $mutation):?PanelDataMutationReceipt{
		$params=[];$source=$this->bind($params,$this->name);$digest=$this->bind($params,$mutation->idempotencyDigest());$lock=$this->driver==='sqlite'?'':' FOR UPDATE';
		$rows=$this->executor->rows('SELECT source_name, idempotency_digest, mutation_fingerprint, schema_version, receipt_json, created_at FROM '.$this->mutationSchema->quoteReceiptTable($this->driver).' WHERE source_name = '.$source.' AND idempotency_digest = '.$digest.' LIMIT 2'.$lock,$params);
		if(count($rows)>1){throw$this->corrupt();}if($rows===[]){return null;}$row=$rows[0];
		if(!is_array($row)||array_is_list($row)||($row['source_name']??null)!==$this->name||($row['idempotency_digest']??null)!==$mutation->idempotencyDigest()||!is_string($row['mutation_fingerprint']??null)||$this->integer($row['schema_version']??null)!==PanelSqlMutationSchema::RECEIPT_SCHEMA_VERSION||!is_string($row['receipt_json']??null)||!is_string($row['created_at']??null)){throw$this->corrupt();}
		if(!hash_equals($row['mutation_fingerprint'],$mutation->fingerprint())){throw new PanelDataMutationConflict('idempotency_conflict','The mutation idempotency key was already used for another payload.');}
		try{$payload=json_decode($row['receipt_json'],true,64,JSON_THROW_ON_ERROR);if(!is_array($payload)||array_is_list($payload)){throw new \UnexpectedValueException();}$receipt=PanelDataMutationReceipt::fromArray($payload);}
		catch(\Throwable $error){throw$this->corrupt($error);}
		if($receipt->replayed()||$receipt->source()!==$this->name||!hash_equals($receipt->idempotencyDigest(),$mutation->idempotencyDigest())||!hash_equals($receipt->mutationFingerprint(),$mutation->fingerprint())||$receipt->occurredAt()!==$row['created_at']){throw$this->corrupt();}
		return$receipt;
	}

	private function storeReceipt(PanelDataMutationReceipt $receipt):void{
		$params=[];$values=[$this->name,$receipt->idempotencyDigest(),$receipt->mutationFingerprint(),PanelSqlMutationSchema::RECEIPT_SCHEMA_VERSION,json_encode($receipt->jsonSerialize(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR),$receipt->occurredAt()];$placeholders=[];foreach($values as$value){$placeholders[]=$this->bind($params,$value);}
		$count=$this->executor->execute('INSERT INTO '.$this->mutationSchema->quoteReceiptTable($this->driver).' (source_name, idempotency_digest, mutation_fingerprint, schema_version, receipt_json, created_at) VALUES ('.implode(', ',$placeholders).')',$params);
		if($count!==1){throw$this->corrupt();}
	}

	/** @param array<string,null|bool|int|float|string> $parameters @return list<string> */
	private function identityWhere(array &$parameters,string|int $key,string|int|null $tenant):array{
		$schema=$this->mutationSchema->schema();$where=[$this->column($schema->primaryKey()).' = '.$this->bind($parameters,$key)];
		if($schema->tenantField()!==null){if($tenant===null){throw new PanelDataMutationAccessDenied('tenant_required','Tenant-scoped SQL mutations require an explicit tenant.');}$where[]=$this->column($schema->tenantField()).' = '.$this->bind($parameters,$tenant);}
		return$where;
	}

	/** @param array<string,mixed> $before @param array<string,null|bool|int|float|string> $values @return list<string> */
	private function changedFields(array $before,array $values):array{$changed=[];foreach($values as$field=>$value){if(!array_key_exists($field,$before)||PanelQueryValue::stableJson($before[$field])!==PanelQueryValue::stableJson($value)){$changed[]=$field;}}sort($changed,SORT_STRING);return$changed;}
	private function revision(array $record):int{$field=$this->mutationSchema->revisionField();if(!array_key_exists($field,$record)){throw$this->corrupt();}return$this->integer($record[$field]);}
	private function integer(mixed $value):int{if(is_int($value)){$number=$value;}elseif(is_string($value)&&preg_match('/^[1-9][0-9]*$/D',$value)===1&&strlen($value)<=strlen((string)PHP_INT_MAX)){$number=(int)$value;if((string)$number!==$value){throw$this->corrupt();}}else{throw$this->corrupt();}if($number<1){throw$this->corrupt();}return$number;}
	private function sameIdentity(mixed $left,mixed $right):bool{return(is_string($left)||is_int($left))&&(is_string($right)||is_int($right))&&(string)$left===(string)$right;}
	private function table():string{return$this->mutationSchema->quoteTable($this->driver);}
	private function column(string $field):string{return$this->mutationSchema->quoteColumn($field,$this->driver);}
	/** @param array<string,null|bool|int|float|string> $parameters */private function bind(array &$parameters,null|bool|int|float|string $value):string{$name='p'.(count($parameters)+1);$parameters[$name]=$value;return':'.$name;}
	private function now():string{try{$value=($this->clock)();}catch(\Throwable $error){throw new PanelDataMutationException('mutation_clock_failed','The mutation clock failed.',500,true,$error);}if(!is_string($value)){throw new PanelDataMutationException('mutation_clock_failed','The mutation clock returned an invalid instant.',500,true);}try{new \DateTimeImmutable($value);}catch(\Throwable $error){throw new PanelDataMutationException('mutation_clock_failed','The mutation clock returned an invalid instant.',500,true,$error);}return$value;}
	private function transaction(callable $callback):mixed{try{return$this->executor->transaction($callback);}catch(PanelDataMutationException $error){throw$error;}catch(\Throwable $error){throw new PanelDataMutationException('mutation_storage_unavailable','The SQL mutation store is unavailable.',503,true,$error);}}
	private function corrupt(?\Throwable $previous=null):PanelDataMutationException{return new PanelDataMutationException('mutation_storage_corrupt','The SQL mutation store failed integrity validation.',503,false,$previous);}
}
