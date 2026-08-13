<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Reference in-memory adapter implementing the complete PanelDataQuery contract.
 *
 * It is useful directly for small data sets and defines deterministic semantics that
 * database, Vestra, repository, and HTTP adapters can match.
 */
final class PanelArrayDataSource implements PanelSubscribableDataSource, PanelMutableDataSource {

	/** @var list<array<string, mixed>> */
	private array $rows=[];
	/** @var list<PanelDataChange> */
	private array $changes=[];
	private int $sequence=0;
	private string $name;
	private string $idField;
	private ?string $tenantField;
	/** @var list<string> */
	private array $defaultSearchFields;
	private ?\Closure $authorize;
	private ?\Closure $mutationAuthorize;
	private \Closure $clock;
	/** @var array<string,int> */
	private array $revisions=[];
	/** @var array<string,array{fingerprint:string,receipt:PanelDataMutationReceipt}> */
	private array $mutationReceipts=[];
	/** @var list<string> */
	private array $mutationReceiptOrder=[];
	private int $maximumMutationReceipts;

	/** @param iterable<mixed> $rows @param array<string, mixed> $options */
	public function __construct(iterable $rows=[], array $options=[]) {
		$this->name=$this->safeName((string)($options['name'] ?? 'array'));
		$this->idField=$this->field((string)($options['id_field'] ?? 'id'));
		$this->tenantField=array_key_exists('tenant_field', $options) && $options['tenant_field']===null ? null : $this->field((string)($options['tenant_field'] ?? 'tenant_id'));
		$this->defaultSearchFields=[];
		foreach(is_array($options['search_fields'] ?? null) ? $options['search_fields'] : [] as $field){ $this->defaultSearchFields[]=$this->field((string)$field); }
		$this->defaultSearchFields=array_values(array_unique($this->defaultSearchFields));
		$this->authorize=isset($options['authorize']) && is_callable($options['authorize']) ? \Closure::fromCallable($options['authorize']) : null;
		if(array_key_exists('mutation_authorize',$options)&&!is_callable($options['mutation_authorize'])){throw new \InvalidArgumentException('Panel array mutation_authorize must be callable.');}
		$this->mutationAuthorize=isset($options['mutation_authorize'])?\Closure::fromCallable($options['mutation_authorize']):null;
		if($this->mutationAuthorize!==null&&(str_contains($this->idField,'.')||($this->tenantField!==null&&str_contains($this->tenantField,'.')))){throw new \InvalidArgumentException('Mutable Panel array sources require top-level id and tenant fields.');}
		if(array_key_exists('clock',$options)&&!is_callable($options['clock'])){throw new \InvalidArgumentException('Panel array clock must be callable.');}
		$this->clock=isset($options['clock'])?\Closure::fromCallable($options['clock']):static fn():string=>gmdate(DATE_ATOM);
		$this->maximumMutationReceipts=(int)($options['mutation_receipts']??10000);
		if($this->maximumMutationReceipts<1||$this->maximumMutationReceipts>100000){throw new \InvalidArgumentException('Panel array mutation receipt retention must be between 1 and 100000.');}
		foreach($rows as $row){$row=$this->row($row);$this->rows[]=$row;$key=$this->rowKey($row);if($key!==null){$this->revisions[$this->keyDigest($key)]=1;}}
	}

	public function query(PanelDataQuery $query): PanelDataResult {
		PanelQueryCapabilities::fromArray($this->capabilities())->assertSupports($query);
		$rows=[];
		foreach($this->rows as $row){
			if(!$this->tenantMatches($row, $query)){ continue; }
			$row=$this->authorizeRow($row, $query);
			if($row===null || !$this->expressionMatches($row, $query->expression()) || !$this->searchMatches($row, $query)){ continue; }
			$rows[]=$row;
		}
		$aggregates=$this->aggregateRows($rows, $query->aggregateList());
		$rows=$this->sortRows($rows, $query->sortNodes());
		$total=count($rows);
		$offset=$query->cursorToken()===null ? $query->offsetValue() : PanelDataCursor::decode($query->cursorToken(), $query->fingerprint());
		$limit=$query->limitValue();
		$pageRows=array_slice($rows, $offset, $limit);
		$items=[];
		foreach($pageRows as $row){ $items[]=$this->project($row, $query->selectedFields(), $query->includes()); }
		$nextOffset=$offset+count($pageRows);
		$next=$nextOffset<$total ? PanelDataCursor::encode($nextOffset, $query->fingerprint()) : null;
		$previous=$offset>0 ? PanelDataCursor::encode(max(0, $offset-$limit), $query->fingerprint()) : null;
		$page=new PanelDataPage($offset, $limit, count($items), $total, $next, $previous);
		return new PanelDataResult(
			$items, $page, $this->name, $aggregates, $this->includedManifest($pageRows, $query->includes()),
			[
				'adapter'=>'array', 'tenant_applied'=>$query->tenantKey()!==null && $this->tenantField!==null,
				'authorization_applied'=>$this->authorize!==null,
			], $query
		);
	}

	public function find(string|int $id, ?PanelDataQuery $scope=null): mixed {
		$scope=($scope ?? PanelDataQuery::make())->cursor(null)->offset(0)->limit(1)->where($this->idField, $id);
		return $this->query($scope)->items()[0] ?? null;
	}

	public function capabilities(): array {
		$writable=$this->mutationAuthorize!==null;
		return array_replace(PanelQueryCapabilities::full('array'), [
			'search'=>true, 'select'=>true,
			'include'=>true, 'cursor'=>true, 'offset'=>true, 'aggregates'=>true, 'tenant'=>$this->tenantField!==null,
			'authorization'=>$this->authorize!==null, 'change_feed'=>true, 'mutations'=>$writable,
			'mutation_operations'=>$writable?PanelDataMutation::OPERATIONS:[], 'mutation_batch'=>$writable,
			'mutation_atomic_batch'=>$writable, 'mutation_max_batch'=>$writable?100:1,
			'mutation_optimistic_concurrency'=>$writable, 'mutation_idempotency'=>$writable,
			'mutation_idempotency_scope'=>$writable?'process':'none', 'mutation_tenant'=>$this->tenantField!==null,
			'mutation_authorization'=>$writable, 'mutation_returning'=>$writable,
		]);
	}

	public function mutate(PanelDataMutation $mutation):PanelDataMutationReceipt {
		PanelDataMutationCapabilities::fromArray($this->capabilities())->assertSupports($mutation);
		$digest=$mutation->idempotencyDigest();$existing=$this->mutationReceipts[$digest]??null;
		if(is_array($existing)&&!hash_equals($existing['fingerprint'],$mutation->fingerprint())){throw new PanelDataMutationConflict('idempotency_conflict','The mutation idempotency key was already used for another payload.');}
		$indexes=$this->rowIndexes($mutation->key());if(count($indexes)>1){throw new PanelDataMutationConflict('duplicate_record_key','The data source contains a duplicated record key.');}
		$index=$indexes[0]??null;$before=$index===null?null:$this->rows[$index];$candidate=$this->mutationCandidate($mutation,$before);
		$this->authorizeMutation($mutation,$before,$candidate);
		if(is_array($existing)){return$existing['receipt']->asReplay();}
		$keyDigest=$this->keyDigest($mutation->key());$revision=$this->revisions[$keyDigest]??($before===null?0:1);$operation=$mutation->operation();
		if($operation==='create'&&$before!==null){throw new PanelDataMutationConflict('record_exists','The record already exists.');}
		if(in_array($operation,['update','delete'],true)&&$before===null){throw new PanelDataMutationException('record_not_found','The record does not exist.',404,false);}
		if($operation==='upsert'&&$before!==null&&$mutation->expectedRevision()===null){throw new PanelDataMutationConflict('expected_revision_required','Updating an existing record through upsert requires its expected revision.');}
		if($before!==null&&$mutation->expectedRevision()!==null&&$mutation->expectedRevision()!==$revision){throw new PanelDataMutationConflict('revision_conflict','The record changed after the mutation was prepared.',true);}
		$occurredAt=$this->now();$outcome='unchanged';$changed=[];$after=$candidate;$change=null;
		if($operation==='delete'){
			array_splice($this->rows,(int)$index,1);$revision++;$this->revisions[$keyDigest]=$revision;$outcome='deleted';
			$change=$this->recordChange('delete',$mutation->key(),$before,null,$this->mutationChangeMetadata($mutation,$revision),$occurredAt);
		}
		elseif($before===null){
			$this->rows[]=$candidate;$revision=1;$this->revisions[$keyDigest]=$revision;$outcome='created';$changed=array_keys($candidate);
			$change=$this->recordChange('insert',$mutation->key(),null,$candidate,$this->mutationChangeMetadata($mutation,$revision),$occurredAt);
		}
		else{
			$changed=$this->changedFields($before,$candidate);
			if($changed!==[]){$this->rows[(int)$index]=$candidate;$revision++;$this->revisions[$keyDigest]=$revision;$outcome='updated';$change=$this->recordChange('update',$mutation->key(),$before,$candidate,$this->mutationChangeMetadata($mutation,$revision),$occurredAt);}
		}
		$receipt=new PanelDataMutationReceipt($this->name,$operation,$mutation->key(),$outcome,$revision,$mutation->fingerprint(),$digest,$occurredAt,$mutation->returnsRecord()&&$operation!=='delete'?$after:null,$changed,['change_sequence'=>$change?->sequence(),'actor_hash'=>hash('sha256',(string)$mutation->actorId()),'reason_present'=>$mutation->reason()!=='']);
		$this->rememberMutation($digest,$mutation->fingerprint(),$receipt);return$receipt;
	}

	public function mutateBatch(PanelDataMutationBatch $batch):PanelDataMutationBatchResult {
		PanelDataMutationCapabilities::fromArray($this->capabilities())->assertSupports($batch);$snapshot=null;
		if($batch->atomic()){$snapshot=[$this->rows,$this->changes,$this->sequence,$this->revisions,$this->mutationReceipts,$this->mutationReceiptOrder];}
		try{$receipts=[];foreach($batch->mutations()as$mutation){$receipts[]=$this->mutate($mutation);}return new PanelDataMutationBatchResult($batch,$receipts,$this->name);}
		catch(\Throwable$error){if(is_array($snapshot)){[$this->rows,$this->changes,$this->sequence,$this->revisions,$this->mutationReceipts,$this->mutationReceiptOrder]=$snapshot;}throw$error;}
	}

	public function version(string|int $key):?int{$indexes=$this->rowIndexes($key);return count($indexes)===1?($this->revisions[$this->keyDigest($key)]??1):null;}

	/** @param iterable<mixed> $rows */
	public function replace(iterable $rows, array $metadata=[]): self {
		$before=$this->rows;
		$next=[]; foreach($rows as $row){ $next[]=$this->row($row); }
		$this->rows=$next;$revisions=[];foreach($next as$row){$key=$this->rowKey($row);if($key!==null){$digest=$this->keyDigest($key);$revisions[$digest]=($this->revisions[$digest]??0)+1;}}$this->revisions=$revisions;
		$this->recordChange('replace', '*', $before, $next, $metadata);
		return $this;
	}

	/** @param array<string, mixed>|object $row */
	public function upsert(array|object $row, array $metadata=[]): self {
		$row=$this->row($row);
		[$exists, $key]=$this->value($row, $this->idField);
		if(!$exists || (!is_string($key) && !is_int($key))){ throw new \InvalidArgumentException("Panel array row must contain scalar id field '{$this->idField}'."); }
		foreach($this->rows as $index=>$current){
			[, $currentKey]=$this->value($current, $this->idField);
			if($this->equal($currentKey, $key)){
				$this->rows[$index]=$row;$digest=$this->keyDigest($key);$this->revisions[$digest]=($this->revisions[$digest]??1)+1;
				$this->recordChange('update', $key, $current, $row, $metadata);
				return $this;
			}
		}
		$this->rows[]=$row;$this->revisions[$this->keyDigest($key)]=1;
		$this->recordChange('insert', $key, null, $row, $metadata);
		return $this;
	}

	public function remove(string|int $id, array $metadata=[]): bool {
		foreach($this->rows as $index=>$row){
			[, $key]=$this->value($row, $this->idField);
			if(!$this->equal($key, $id)){ continue; }
			array_splice($this->rows, $index, 1);$digest=$this->keyDigest($id);$this->revisions[$digest]=($this->revisions[$digest]??1)+1;
			$this->recordChange('delete', $id, $row, null, $metadata);
			return true;
		}
		return false;
	}

	public function changes(int $afterSequence=0, int $limit=100): array {
		if($afterSequence<0){ throw new \InvalidArgumentException('Panel data change cursor cannot be negative.'); }
		$limit=max(1, min(10000, $limit));
		return array_slice(array_values(array_filter($this->changes, static fn(PanelDataChange $change): bool=>$change->sequence()>$afterSequence)), 0, $limit);
	}

	public function subscribe(int $afterSequence=0): PanelDataSubscription { return new PanelArrayDataSubscription($this, $afterSequence); }
	public function sequence(): int { return $this->sequence; }
	/** @return list<array<string, mixed>> */ public function rows(): array { return $this->rows; }

	/** @param array<string, mixed> $filter */
	private function filterMatches(array $row, array $filter): bool {
		[$exists, $actual]=$this->value($row, (string)$filter['field']);
		$operator=(string)$filter['operator']; $expected=$filter['value'] ?? null;
		return match($operator){
			'eq'=>$exists && $this->equal($actual, $expected),
			'neq'=>!$exists || !$this->equal($actual, $expected),
			'gt'=>$exists && $this->compare($actual, $expected)>0,
			'gte'=>$exists && $this->compare($actual, $expected)>=0,
			'lt'=>$exists && $this->compare($actual, $expected)<0,
			'lte'=>$exists && $this->compare($actual, $expected)<=0,
			'in'=>$exists && $this->in($actual, $expected),
			'not_in'=>!$exists || !$this->in($actual, $expected),
			'contains'=>$exists && $this->contains($actual, $expected),
			'not_contains'=>!$exists || !$this->contains($actual, $expected),
			'starts_with'=>$exists && str_starts_with($this->lower($actual), $this->lower($expected)),
			'ends_with'=>$exists && str_ends_with($this->lower($actual), $this->lower($expected)),
			'between'=>$exists && $this->compare($actual, $expected[0] ?? null)>=0 && $this->compare($actual, $expected[1] ?? null)<=0,
			'not_between'=>!$exists || !($this->compare($actual, $expected[0] ?? null)>=0 && $this->compare($actual, $expected[1] ?? null)<=0),
			'is_null'=>!$exists || $actual===null,
			'not_null'=>$exists && $actual!==null,
			default=>false,
		};
	}

	private function expressionMatches(array $row, ?PanelQueryExpression $expression): bool {
		if($expression===null){ return true; }
		if($expression instanceof PanelQueryGroup){
			$matches=array_map(fn(PanelQueryExpression $child): bool=>$this->expressionMatches($row, $child), $expression->children());
			return $expression->boolean()==='and' ? !in_array(false, $matches, true) : in_array(true, $matches, true);
		}
		if($expression instanceof PanelQueryRelation){
			$items=$this->relationItems($row, $expression->relation());
			if($expression->scope()!==null){ $items=array_values(array_filter($items, fn(array $item): bool=>$this->expressionMatches($item, $expression->scope()))); }
			$matches=array_map(fn(array $item): bool=>$this->expressionMatches($item, $expression->expression()), $items);
			return match($expression->quantifier()){
				'any'=>in_array(true, $matches, true),
				'none'=>!in_array(true, $matches, true),
				'all'=>!in_array(false, $matches, true),
			};
		}
		$legacy=PanelQueryExpressionCodec::legacyFilters($expression);
		if($legacy===null || count($legacy)!==1){ throw new \LogicException('Panel array adapter received an unknown expression node.'); }
		return $this->filterMatches($row, $legacy[0]);
	}

	private function searchMatches(array $row, PanelDataQuery $query): bool {
		$term=$query->searchTerm(); if($term===null){ return true; }
		$fields=$query->searchFields()!==[] ? $query->searchFields() : $this->defaultSearchFields;
		$values=[];
		if($fields===[]){ $this->flattenScalars($row, $values); }
		else{ foreach($fields as $field){ [$exists, $value]=$this->value($row, $field); if($exists){ $this->flattenScalars($value, $values); } } }
		$haystack=$this->lower(implode(' ', array_map(static fn(mixed $value): string=>(string)$value, $values)));
		$tokens=preg_split('/\s+/u', trim($this->lower($term)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		foreach($tokens as $token){ if(!str_contains($haystack, $token)){ return false; } }
		return true;
	}

	/** @param list<PanelQuerySort> $sorts @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
	private function sortRows(array $rows, array $sorts): array {
		if($sorts===[]){ return $rows; }
		$decorated=[]; foreach($rows as $index=>$row){ $decorated[]=['index'=>$index, 'row'=>$row]; }
		usort($decorated, function(array $left, array $right)use($sorts): int {
			foreach($sorts as $sort){
				[, $a]=$this->value($left['row'], $sort->field()->value()); [, $b]=$this->value($right['row'], $sort->field()->value());
				$explicitNull=false;
				if($a===null || $b===null){
					if($a===$b){ $comparison=0; }
					elseif($sort->nulls()==='first'){ $comparison=$a===null ? -1 : 1; $explicitNull=true; }
					elseif($sort->nulls()==='last'){ $comparison=$a===null ? 1 : -1; $explicitNull=true; }
					else{ $comparison=$this->compare($a, $b); }
				}
				else{ $comparison=$this->compare($a, $b); }
				if($comparison!==0){ return !$explicitNull && $sort->direction()==='desc' ? -$comparison : $comparison; }
			}
			return $left['index'] <=> $right['index'];
		});
		return array_values(array_map(static fn(array $entry): array=>$entry['row'], $decorated));
	}

	/** @return list<array<string,mixed>> */
	private function relationItems(array $row, string $relation): array {
		if(!array_key_exists($relation, $row) || $row[$relation]===null){ return []; }
		$value=$row[$relation];
		if($value instanceof \JsonSerializable){ $value=$value->jsonSerialize(); }
		elseif(is_object($value)){ $value=get_object_vars($value); }
		if(!is_array($value)){ return []; }
		$values=array_is_list($value) ? $value : [$value];
		$items=[];
		foreach($values as $item){
			if($item instanceof \JsonSerializable){ $item=$item->jsonSerialize(); }
			elseif(is_object($item)){ $item=get_object_vars($item); }
			if(is_array($item)){ $items[]=$item; }
		}
		return $items;
	}

	/** @param list<array<string, mixed>> $rows @param list<array{alias:string,function:string,field:?string}> $specs @return array<string, mixed> */
	private function aggregateRows(array $rows, array $specs): array {
		$out=[];
		foreach($specs as $spec){
			$values=[];
			if($spec['field']!==null){ foreach($rows as $row){ [$exists, $value]=$this->value($row, $spec['field']); if($exists && $value!==null){ $values[]=$value; } } }
			$out[$spec['alias']]=match($spec['function']){
				'count'=>$spec['field']===null ? count($rows) : count($values),
				'distinct_count'=>count(array_unique(array_map(fn(mixed $value): string=>$this->stable($value), $values))),
				'sum'=>array_sum(array_map([$this, 'number'], $values)),
				'avg'=>$values===[] ? null : array_sum(array_map([$this, 'number'], $values))/count($values),
				'min'=>$values===[] ? null : $this->extreme($values, false),
				'max'=>$values===[] ? null : $this->extreme($values, true),
			};
		}
		return $out;
	}

	/** @param list<string> $select @param list<string> $includes @return array<string, mixed> */
	private function project(array $row, array $select, array $includes): array {
		if($select===[]){ return $row; }
		$out=[];
		foreach(array_values(array_unique(array_merge($select, $includes))) as $field){
			[$exists, $value]=$this->value($row, $field); if($exists){ $this->set($out, $field, $value); }
		}
		return $out;
	}

	/** @param list<array<string, mixed>> $rows @param list<string> $includes @return array<string, mixed> */
	private function includedManifest(array $rows, array $includes): array {
		$out=[];
		foreach($includes as $include){
			$values=[]; foreach($rows as $row){ [$exists, $value]=$this->value($row, $include); if($exists){ $values[]=$value; } }
			$out[$include]=$values;
		}
		return $out;
	}

	private function tenantMatches(array $row, PanelDataQuery $query): bool {
		if($query->tenantKey()===null || $this->tenantField===null){ return true; }
		[$exists, $tenant]=$this->value($row, $this->tenantField);
		return $exists && $this->equal($tenant, $query->tenantKey());
	}

	/** @return array<string, mixed>|null */
	private function authorizeRow(array $row, PanelDataQuery $query): ?array {
		if($this->authorize===null){ return $row; }
		$decision=($this->authorize)($row, $query->authorizationMetadata(), $query);
		if($decision===false || $decision===null){ return null; }
		if($decision===true){ return $row; }
		if(is_array($decision) && !array_is_list($decision)){ return $decision; }
		throw new \UnexpectedValueException('Panel array authorization callback must return bool, null, or a row array.');
	}

	/** @return array{bool,mixed} */
	private function value(array $row, string $path): array {
		$current=$row;
		foreach(explode('.', $path) as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){ return [false, null]; }
			$current=$current[$segment];
		}
		return [true, $current];
	}

	/** @param array<string, mixed> $target */
	private function set(array &$target, string $path, mixed $value): void {
		$segments=explode('.', $path); $cursor=&$target;
		foreach($segments as $index=>$segment){
			if($index===array_key_last($segments)){ $cursor[$segment]=$value; break; }
			if(!isset($cursor[$segment]) || !is_array($cursor[$segment])){ $cursor[$segment]=[]; }
			$cursor=&$cursor[$segment];
		}
	}

	private function equal(mixed $left, mixed $right): bool {
		if(is_numeric($left) && is_numeric($right)){ return (float)$left===(float)$right; }
		return $left===$right;
	}

	private function compare(mixed $left, mixed $right): int {
		if($left===null && $right===null){ return 0; } if($left===null){ return -1; } if($right===null){ return 1; }
		if(is_numeric($left) && is_numeric($right)){ return (float)$left <=> (float)$right; }
		if($left instanceof \DateTimeInterface){ $left=$left->format(DATE_ATOM); } if($right instanceof \DateTimeInterface){ $right=$right->format(DATE_ATOM); }
		return strnatcasecmp($this->stable($left), $this->stable($right));
	}

	private function in(mixed $actual, mixed $expected): bool {
		if(!is_array($expected)){ return false; }
		foreach($expected as $candidate){ if($this->equal($actual, $candidate)){ return true; } }
		return false;
	}

	private function contains(mixed $actual, mixed $expected): bool {
		if(is_array($actual)){ foreach($actual as $item){ if($this->equal($item, $expected)){ return true; } } return false; }
		return str_contains($this->lower($actual), $this->lower($expected));
	}

	private function number(mixed $value): float|int {
		if(!is_numeric($value)){ throw new \UnexpectedValueException('Panel numeric aggregate encountered a non-numeric value.'); }
		return str_contains((string)$value, '.') ? (float)$value : (int)$value;
	}

	private function extreme(array $values, bool $maximum): mixed {
		$chosen=array_shift($values); foreach($values as $value){ $comparison=$this->compare($value, $chosen); if(($maximum && $comparison>0) || (!$maximum && $comparison<0)){ $chosen=$value; } } return $chosen;
	}

	/** @param list<scalar> $values */
	private function flattenScalars(mixed $value, array &$values): void {
		if(is_scalar($value)){ $values[]=$value; return; }
		if(is_array($value)){ foreach($value as $child){ $this->flattenScalars($child, $values); } }
	}

	private function stable(mixed $value): string {
		if(is_scalar($value) || $value===null){ return (string)$value; }
		return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
	}

	private function lower(mixed $value): string {
		$value=$this->stable($value);
		return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
	}

	/** @return array<string, mixed> */
	private function row(mixed $row): array {
		if($row instanceof \JsonSerializable){ $row=$row->jsonSerialize(); }
		elseif(is_object($row)){ $row=get_object_vars($row); }
		if(!is_array($row) || array_is_list($row)){ throw new \InvalidArgumentException('Panel array data rows must be object-like arrays or objects.'); }
		json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
		return $row;
	}

	private function mutationCandidate(PanelDataMutation $mutation,?array $before):?array {
		if($this->tenantField!==null){
			if($mutation->tenantKey()===null){throw new PanelDataMutationAccessDenied('tenant_required','Tenant-scoped data mutations require an explicit tenant.');}
			if($before!==null){[$exists,$tenant]=$this->value($before,$this->tenantField);if(!$exists||!$this->equal($tenant,$mutation->tenantKey())){throw new PanelDataMutationAccessDenied('tenant_mismatch','The record is outside the mutation tenant.');}}
		}
		if($mutation->operation()==='delete'){return null;}
		$values=$mutation->values();
		if(array_key_exists($this->idField,$values)&&!$this->equal($values[$this->idField],$mutation->key())){throw new PanelDataMutationConflict('record_key_conflict','Mutation values cannot replace the record key.');}
		$values[$this->idField]=$mutation->key();
		if($this->tenantField!==null){
			if(array_key_exists($this->tenantField,$values)&&!$this->equal($values[$this->tenantField],$mutation->tenantKey())){throw new PanelDataMutationAccessDenied('tenant_mismatch','Mutation values cannot replace the tenant.');}
			$values[$this->tenantField]=$mutation->tenantKey();
		}
		return$before===null?$this->row($values):$this->row(array_replace($before,$values));
	}

	private function authorizeMutation(PanelDataMutation $mutation,?array $before,?array $after):void {
		if($this->mutationAuthorize===null){throw new PanelDataMutationAccessDenied('mutation_authorizer_missing','The data source has no trusted mutation authorizer.');}
		try{$decision=($this->mutationAuthorize)($mutation,$before,$after,$this);}
		catch(PanelDataMutationAccessDenied$error){throw$error;}
		catch(\Throwable$error){throw new PanelDataMutationAccessDenied('mutation_authorization_failed','The mutation authorization decision failed.',$error);}
		if($decision!==true){throw new PanelDataMutationAccessDenied();}
	}

	/** @return list<int> */
	private function rowIndexes(string|int $key):array {$indexes=[];foreach($this->rows as$index=>$row){$current=$this->rowKey($row);if($current!==null&&$this->equal($current,$key)){$indexes[]=$index;}}return$indexes;}
	private function rowKey(array $row):string|int|null {[$exists,$key]=$this->value($row,$this->idField);return$exists&&(is_string($key)||is_int($key))?$key:null;}
	private function keyDigest(string|int $key):string{return hash('sha256','panel-array-key-v1|'.(string)$key);}
	/** @return list<string> */
	private function changedFields(array $before,array $after):array {$fields=[];foreach(array_unique(array_merge(array_keys($before),array_keys($after)))as$field){if(!array_key_exists($field,$before)||!array_key_exists($field,$after)||PanelQueryValue::stableJson($before[$field])!==PanelQueryValue::stableJson($after[$field])){$fields[]=(string)$field;}}sort($fields,SORT_STRING);return$fields;}
	/** @return array<string,mixed> */
	private function mutationChangeMetadata(PanelDataMutation $mutation,int $revision):array{return array_replace($mutation->metadata(),['mutation_fingerprint'=>$mutation->fingerprint(),'actor_hash'=>hash('sha256',(string)$mutation->actorId()),'revision'=>$revision]);}
	private function rememberMutation(string $digest,string $fingerprint,PanelDataMutationReceipt $receipt):void {$this->mutationReceipts[$digest]=compact('fingerprint','receipt');$this->mutationReceiptOrder[]=$digest;while(count($this->mutationReceiptOrder)>$this->maximumMutationReceipts){$old=array_shift($this->mutationReceiptOrder);if(is_string($old)){unset($this->mutationReceipts[$old]);}}}
	private function now():string {try{$value=($this->clock)();}catch(\Throwable$error){throw new PanelDataMutationException('mutation_clock_failed','The mutation clock failed.',500,true,$error);}if(!is_string($value)){throw new PanelDataMutationException('mutation_clock_failed','The mutation clock returned an invalid instant.',500,true);}try{new \DateTimeImmutable($value);}catch(\Throwable$error){throw new PanelDataMutationException('mutation_clock_failed','The mutation clock returned an invalid instant.',500,true,$error);}return$value;}

	/** @param array<string, mixed> $metadata */
	private function recordChange(string $operation, string|int $key, mixed $before, mixed $after, array $metadata,?string $occurredAt=null): PanelDataChange {
		if($metadata!==[] && array_is_list($metadata)){ throw new \InvalidArgumentException('Panel data change metadata must be an object-like array.'); }
		$change=new PanelDataChange(++$this->sequence, $operation, $key, $before, $after, $occurredAt??gmdate(DATE_ATOM), $metadata);$this->changes[]=$change;
		if(count($this->changes)>10000){ $this->changes=array_slice($this->changes, -10000); }
		return$change;
	}

	private function field(string $field): string {
		$field=trim($field); if($field==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $field)!==1){ throw new \InvalidArgumentException("Invalid Panel array field '{$field}'."); } return $field;
	}
	private function safeName(string $name): string {
		$name=strtolower(trim($name));$name=preg_replace('/[^a-z0-9]+/','_',$name)??'';$name=trim($name,'_')?:'array';if(strlen($name)>64){$name=substr($name,0,47).'_'.substr(hash('sha256',$name),0,16);}return$name;
	}
}
