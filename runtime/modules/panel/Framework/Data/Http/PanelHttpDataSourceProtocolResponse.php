<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Exact-key decoder for the only accepted upstream success/error envelopes. */
final class PanelHttpDataSourceProtocolResponse implements \JsonSerializable {
	/** @param list<array<string,mixed>> $items @param list<string> $projection @param array<string,mixed> $aggregates @param array<string,list<array<string,mixed>>> $included */
	private function __construct(
		private readonly array $items,
		private readonly int $offset,
		private readonly int $limit,
		private readonly ?int $total,
		private readonly ?string $nextCursor,
		private readonly ?string $previousCursor,
		private readonly array $projection,
		private readonly array $aggregates,
		private readonly array $included
	){}

	public static function decode(PanelHttpDataSourceTransportResponse $response, PanelHttpDataSourceProtocolRequest $request, string $recordKeyField, int $maxBytes): self {
		if(strlen($response->body())>$maxBytes || $response->body()===''){ throw PanelHttpDataSourceException::protocolInvalid(); }
		if(preg_match('/\Aapplication\/json(?:\s*;\s*charset=utf-8)?\z/iD', trim($response->contentType()))!==1){ throw PanelHttpDataSourceException::protocolInvalid(); }
		try{ $body=json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); }
		catch(\Throwable){ throw PanelHttpDataSourceException::protocolInvalid(); }
		if(!is_array($body) || array_is_list($body)){ throw PanelHttpDataSourceException::protocolInvalid(); }
		try{
			if($response->status()!==200){ throw self::decodeError($body, $response->status(), $request); }
			PanelHttpDataSourceValue::exactKeys($body, ['type','version','operation','request_id','definition_fingerprint','capability','query_fingerprint','data'], 'Remote success response');
			if($body['type']!=='panel_http_data_response' || $body['version']!==1){ throw new \UnexpectedValueException('response identity'); }
			self::verifyBinding($body, $request);
			$data=$body['data']; if(!is_array($data)){ throw new \UnexpectedValueException('response data'); }
			PanelHttpDataSourceValue::exactKeys($data, ['items','page','projection','aggregates','included'], 'Remote response data');
			$items=$data['items']; if(!is_array($items) || !array_is_list($items)){ throw new \UnexpectedValueException('response items'); }
			$page=$data['page']; if(!is_array($page)){ throw new \UnexpectedValueException('response page'); }
			PanelHttpDataSourceValue::exactKeys($page, ['offset','limit','returned','total','next_cursor','previous_cursor'], 'Remote response page');
			$offset=$page['offset']; $limit=$page['limit']; $returned=$page['returned']; $total=$page['total'];
			$query=$request->queryPayload();
			if(!is_int($offset) || $offset<0 || !is_int($limit) || $limit!==$query['limit'] || !is_int($returned) || $returned!==count($items) || $returned>$limit){ throw new \UnexpectedValueException('response page counters'); }
			if($total!==null && (!is_int($total) || $total<($offset+$returned))){ throw new \UnexpectedValueException('response total'); }
			$countTotal=$request->capabilityPin()->capabilities()['count_total'];
			if(($countTotal===true && $total===null) || ($countTotal===false && $total!==null)){ throw new \UnexpectedValueException('response total capability'); }
			if($request->operation()==='find' && ($offset!==0 || $limit!==1 || count($items)>1)){ throw new \UnexpectedValueException('find page'); }
			$next=self::cursor($page['next_cursor']); $previous=self::cursor($page['previous_cursor']);
			if($next!==null && $request->capabilityPin()->capabilities()['cursor']!==true){ throw new \UnexpectedValueException('next cursor'); }
			if($previous!==null && $request->capabilityPin()->capabilities()['cursor_previous']!==true){ throw new \UnexpectedValueException('previous cursor'); }

			$projection=$data['projection']; if(!is_array($projection)){ throw new \UnexpectedValueException('projection'); }
			PanelHttpDataSourceValue::exactKeys($projection, ['fields','record_key'], 'Remote response projection');
			if($projection['record_key']!==$recordKeyField || !is_array($projection['fields']) || !array_is_list($projection['fields']) || count($projection['fields'])<1 || count($projection['fields'])>100){ throw new \UnexpectedValueException('projection metadata'); }
			$fields=[];
			foreach($projection['fields'] as $field){ if(!is_string($field)){ throw new \UnexpectedValueException('projection field'); } $fields[]=PanelQueryPath::make($field)->value(); }
			if(count(array_unique($fields))!==count($fields) || !in_array($recordKeyField, $fields, true)){ throw new \UnexpectedValueException('projection fields'); }
			$selected=$query['select'];
			if($selected!==[]){ $expected=array_values(array_unique(array_merge($selected, [$recordKeyField]))); $left=$fields; sort($left,SORT_STRING); sort($expected,SORT_STRING); if($left!==$expected){ throw new \UnexpectedValueException('projection mismatch'); } }
			$keys=[]; $keyType=null; $normalizedItems=[]; $fieldSet=$fields; sort($fieldSet, SORT_STRING);
			foreach($items as $item){
				if(!is_array($item) || array_is_list($item)){ throw new \UnexpectedValueException('record shape'); }
				$itemKeys=array_keys($item); sort($itemKeys, SORT_STRING); if($itemKeys!==$fieldSet){ throw new \UnexpectedValueException('record projection'); }
				$currentKeyType=is_int($item[$recordKeyField] ?? null) ? 'integer' : 'string'; $key=self::stableRecordKey($item[$recordKeyField] ?? null);
				if($keyType!==null && $keyType!==$currentKeyType){ throw new \UnexpectedValueException('mixed record key types'); } $keyType=$currentKeyType;
				if(isset($keys[$key])){ throw new \UnexpectedValueException('duplicate record key'); } $keys[$key]=true;
				$nodes=0; $item=PanelHttpDataSourceValue::json($item, 'Remote record', 0, $nodes); if(!is_array($item)){ throw new \UnexpectedValueException('record json'); } $normalizedItems[]=$item;
			}
			if($request->operation()==='find' && $normalizedItems!==[] && (string)($normalizedItems[0][$recordKeyField] ?? '')!==(string)$request->recordKey()){ throw new \UnexpectedValueException('find record key'); }

			$aggregates=self::validateAggregates($data['aggregates'], $query['aggregates']);
			$included=self::validateIncluded($data['included'], $query['include']);
			return new self($normalizedItems, $offset, $limit, $total, $next, $previous, $fields, $aggregates, $included);
		}
		catch(PanelHttpDataSourceException $error){ throw $error; }
		catch(\Throwable){ throw PanelHttpDataSourceException::protocolInvalid(); }
	}

	/** @return list<array<string,mixed>> */ public function items(): array { return $this->items; }
	public function offset(): int { return $this->offset; }
	public function limit(): int { return $this->limit; }
	public function total(): ?int { return $this->total; }
	public function nextCursor(): ?string { return $this->nextCursor; }
	public function previousCursor(): ?string { return $this->previousCursor; }
	/** @return list<string> */ public function projection(): array { return $this->projection; }
	/** @return array<string,mixed> */ public function aggregates(): array { return $this->aggregates; }
	/** @return array<string,list<array<string,mixed>>> */ public function included(): array { return $this->included; }
	/** @return array<string,mixed> */
	public function jsonSerialize(): array { return ['type'=>'panel_http_data_decoded_response','version'=>1,'items'=>$this->items,'page'=>['offset'=>$this->offset,'limit'=>$this->limit,'returned'=>count($this->items),'total'=>$this->total,'next_cursor'=>$this->nextCursor,'previous_cursor'=>$this->previousCursor],'projection'=>$this->projection,'aggregates'=>$this->aggregates,'included'=>$this->included]; }

	/** @param array<string,mixed> $body */
	private static function decodeError(array $body, int $status, PanelHttpDataSourceProtocolRequest $request): PanelHttpDataSourceException {
		PanelHttpDataSourceValue::exactKeys($body, ['type','version','operation','request_id','definition_fingerprint','capability','query_fingerprint','error'], 'Remote error response');
		if($body['type']!=='panel_http_data_error' || $body['version']!==1){ throw new \UnexpectedValueException('error identity'); }
		self::verifyBinding($body, $request);
		$error=$body['error']; if(!is_array($error)){ throw new \UnexpectedValueException('error payload'); }
		PanelHttpDataSourceValue::exactKeys($error, ['code','retryable'], 'Remote error payload');
		if(!is_string($error['code']) || !is_bool($error['retryable'])){ throw new \UnexpectedValueException('error types'); }
		PanelHttpDataSourceValue::identifier($error['code'], 'Remote upstream error code', 64);
		return PanelHttpDataSourceException::upstream($status);
	}

	/** @param array<string,mixed> $body */
	private static function verifyBinding(array $body, PanelHttpDataSourceProtocolRequest $request): void {
		if($body['operation']!==$request->operation() || $body['request_id']!==$request->requestId() || $body['definition_fingerprint']!==$request->definitionFingerprint() || $body['query_fingerprint']!==$request->queryFingerprint()){ throw new \UnexpectedValueException('response binding'); }
		$capability=$body['capability']; if(!is_array($capability)){ throw new \UnexpectedValueException('response capability'); }
		PanelHttpDataSourceValue::exactKeys($capability, ['version','fingerprint'], 'Remote response capability');
		if($capability['version']!==$request->capabilityPin()->version() || !is_string($capability['fingerprint']) || !hash_equals($request->capabilityPin()->fingerprint(), $capability['fingerprint'])){ throw PanelHttpDataSourceException::capabilityMismatch(); }
	}

	private static function cursor(mixed $value): ?string {
		if($value===null){ return null; }
		if(!is_string($value)){ throw new \UnexpectedValueException('cursor type'); }
		return PanelHttpDataSourceValue::text($value, 'Upstream cursor', 2048);
	}

	private static function stableRecordKey(mixed $value): string {
		if(is_int($value)){ return 'i:'.$value; }
		if(!is_string($value)){ throw new \UnexpectedValueException('record key type'); }
		return 's:'.PanelHttpDataSourceValue::text($value, 'Remote record key', 512);
	}

	/** @param mixed $value @param mixed $specifications @return array<string,mixed> */
	private static function validateAggregates(mixed $value, mixed $specifications): array {
		if(!is_array($value) || ($value!==[] && array_is_list($value)) || !is_array($specifications)){ throw new \UnexpectedValueException('aggregate shape'); }
		$expected=[]; foreach($specifications as $spec){ if(!is_array($spec)){ throw new \UnexpectedValueException('aggregate spec'); } $expected[(string)$spec['alias']]=$spec; }
		$actual=array_keys($value); $aliases=array_keys($expected); sort($actual,SORT_STRING); sort($aliases,SORT_STRING); if($actual!==$aliases){ throw new \UnexpectedValueException('aggregate aliases'); }
		$out=[];
		foreach($expected as $alias=>$spec){
			$candidate=$value[$alias]; $function=$spec['function'];
			if(in_array($function, ['count','distinct_count'], true)){ if(!is_int($candidate) || $candidate<0){ throw new \UnexpectedValueException('aggregate count'); } }
			elseif(in_array($function, ['sum','avg'], true)){ if($candidate!==null && !is_int($candidate) && !(is_float($candidate) && is_finite($candidate))){ throw new \UnexpectedValueException('aggregate number'); } }
			elseif($candidate!==null && !is_scalar($candidate)){ throw new \UnexpectedValueException('aggregate scalar'); }
			$out[$alias]=$candidate;
		}
		return $out;
	}

	/** @param mixed $value @param mixed $relations @return array<string,list<array<string,mixed>>> */
	private static function validateIncluded(mixed $value, mixed $relations): array {
		if(!is_array($value) || ($value!==[] && array_is_list($value)) || !is_array($relations)){ throw new \UnexpectedValueException('included shape'); }
		$actual=array_keys($value); $expected=$relations; sort($actual,SORT_STRING); sort($expected,SORT_STRING); if($actual!==$expected){ throw new \UnexpectedValueException('included relations'); }
		$out=[]; $total=0;
		foreach($relations as $relation){
			$records=$value[$relation]; if(!is_array($records) || !array_is_list($records) || count($records)>1000){ throw new \UnexpectedValueException('included records'); }
			$total+=count($records); if($total>5000){ throw new \UnexpectedValueException('included total'); }
			$out[$relation]=[];
			foreach($records as $record){ $nodes=0; $record=PanelHttpDataSourceValue::json($record, 'Remote included record', 0, $nodes); if(!is_array($record) || array_is_list($record)){ throw new \UnexpectedValueException('included record shape'); } $out[$relation][]=$record; }
		}
		return $out;
	}
}
