<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

class QuerySpec {

	private array $where=[];
	private array $vars=[];
	private array $order_by=[];
	private ?int $limit=null;
	private ?int $offset=null;

	public static function columns(array|string $columns='*'): array|string {
		if(is_string($columns)){
			$columns=trim($columns);
			return $columns==='' ? '*' : $columns;
		}
		$normalized=[];
		foreach($columns as $column){
			$column=trim((string)$column);
			if($column===''){
				continue;
			}
			$normalized[]=$column;
		}
		return $normalized==[] ? '*' : array_values(array_unique($normalized));
	}

	public function where_eq(string $column, mixed $value): self {
		return $this->where_compare($column, '=', $value);
	}

	public function where_not_eq(string $column, mixed $value): self {
		return $this->where_compare($column, '<>', $value);
	}

	public function where_gt(string $column, mixed $value): self {
		return $this->where_compare($column, '>', $value);
	}

	public function where_gte(string $column, mixed $value): self {
		return $this->where_compare($column, '>=', $value);
	}

	public function where_lt(string $column, mixed $value): self {
		return $this->where_compare($column, '<', $value);
	}

	public function where_lte(string $column, mixed $value): self {
		return $this->where_compare($column, '<=', $value);
	}

	public function where_in(string $column, array $values): self {
		$column=$this->assert_identifier($column);
		$values=array_values($values);
		if($values===[]){
			$this->where[]='1 = 0';
			return $this;
		}
		$placeholders=implode(', ', array_fill(0, count($values), '?'));
		$this->where[]=$column.' IN ('.$placeholders.')';
		foreach($values as $value){
			$this->vars[]=$value;
		}
		return $this;
	}

	public function where_like(string $column, string $value): self {
		return $this->where_compare($column, 'LIKE', $value);
	}

	public function where_not_like(string $column, string $value): self {
		return $this->where_compare($column, 'NOT LIKE', $value);
	}

	public function where_between(string $column, mixed $from, mixed $to): self {
		$column=$this->assert_identifier($column);
		$this->where[]=$column.' BETWEEN ? AND ?';
		$this->vars[]=$from;
		$this->vars[]=$to;
		return $this;
	}

	public function where_since(string $column, mixed $value): self {
		return $this->where_gte($column, $this->normalize_temporal_value($value));
	}

	public function where_until(string $column, mixed $value): self {
		return $this->where_lte($column, $this->normalize_temporal_value($value));
	}

	public function where_after(string $column, mixed $value): self {
		return $this->where_gt($column, $this->normalize_temporal_value($value));
	}

	public function where_before(string $column, mixed $value): self {
		return $this->where_lt($column, $this->normalize_temporal_value($value));
	}

	public function where_within(string $column, mixed $from, mixed $to): self {
		return $this->where_between(
			$column,
			$this->normalize_temporal_value($from),
			$this->normalize_temporal_value($to)
		);
	}

	public function in_last_minutes(string $column, int $minutes): self {
		return $this->where_since($column, $this->relative_temporal_value('minutes', $minutes));
	}

	public function in_last_hours(string $column, int $hours): self {
		return $this->where_since($column, $this->relative_temporal_value('hours', $hours));
	}

	public function in_last_days(string $column, int $days): self {
		return $this->where_since($column, $this->relative_temporal_value('days', $days));
	}

	public function where_null(string $column): self {
		$this->where[]=$this->assert_identifier($column).' IS NULL';
		return $this;
	}

	public function where_not_null(string $column): self {
		$this->where[]=$this->assert_identifier($column).' IS NOT NULL';
		return $this;
	}

	public function where_raw(string $fragment, array $vars=[]): self {
		$fragment=trim($fragment);
		if($fragment!==''){
			$this->where[]=$fragment;
			foreach($vars as $value){
				$this->vars[]=$value;
			}
		}
		return $this;
	}

	public function where_all(callable $callback): self {
		return $this->where_group('AND', $callback);
	}

	public function where_any(callable $callback): self {
		return $this->where_group('OR', $callback);
	}

	public function when(mixed $condition, callable $callback, ?callable $default=null): self {
		if($condition){
			$result=$callback($this, $condition);
			return $result instanceof self ? $result : $this;
		}
		if($default!==null){
			$result=$default($this, $condition);
			return $result instanceof self ? $result : $this;
		}
		return $this;
	}

	public function unless(mixed $condition, callable $callback, ?callable $default=null): self {
		return $this->when(!$condition, $callback, $default);
	}

	public function when_not_null(mixed $value, callable $callback, ?callable $default=null): self {
		if($value!==null){
			$result=$callback($this, $value);
			return $result instanceof self ? $result : $this;
		}
		if($default!==null){
			$result=$default($this, $value);
			return $result instanceof self ? $result : $this;
		}
		return $this;
	}

	public function when_filled(mixed $value, callable $callback, ?callable $default=null): self {
		$is_filled=!($value===null || (is_string($value) && trim($value)==='') || (is_array($value) && $value===[]));
		if($is_filled){
			$result=$callback($this, $value);
			return $result instanceof self ? $result : $this;
		}
		if($default!==null){
			$result=$default($this, $value);
			return $result instanceof self ? $result : $this;
		}
		return $this;
	}

	public function tap(callable $callback): self {
		$result=$callback($this);
		return $result instanceof self ? $result : $this;
	}

	public function order_by(string $column, string $direction='ASC'): self {
		$direction=strtoupper(trim($direction));
		$direction=$direction==='DESC' ? 'DESC' : 'ASC';
		$this->order_by[]=$this->assert_identifier($column).' '.$direction;
		return $this;
	}

	public function order_by_asc(string $column): self {
		return $this->order_by($column, 'ASC');
	}

	public function order_by_desc(string $column): self {
		return $this->order_by($column, 'DESC');
	}

	public function latest(string $column='created_at'): self {
		return $this->order_by_desc($column);
	}

	public function oldest(string $column='created_at'): self {
		return $this->order_by_asc($column);
	}

	public function limit(int $limit): self {
		$this->limit=max(0, $limit);
		return $this;
	}

	public function offset(int $offset): self {
		$this->offset=max(0, $offset);
		return $this;
	}

	public function for_page(int $page, int $per_page): self {
		$page=max(1, $page);
		$per_page=max(1, $per_page);
		return $this->limit($per_page)->offset(($page - 1) * $per_page);
	}

	public function clear_ordering(): self {
		$this->order_by=[];
		return $this;
	}

	public function clear_limit(): self {
		$this->limit=null;
		return $this;
	}

	public function clear_offset(): self {
		$this->offset=null;
		return $this;
	}

	public function clear_paging(): self {
		$this->limit=null;
		$this->offset=null;
		return $this;
	}

	public function without_ordering(): self {
		$clone=clone $this;
		return $clone->clear_ordering();
	}

	public function without_paging(): self {
		$clone=clone $this;
		return $clone->clear_paging();
	}

	public function compile(): array {
		$params=[];
		if($this->where!==[]){
			$params[]='WHERE '.implode(' AND ', $this->where);
		}
		if($this->order_by!==[]){
			$params[]='ORDER BY '.implode(', ', $this->order_by);
		}
		if($this->limit!==null){
			$params[]='LIMIT '.$this->limit;
		}
		if($this->offset!==null && $this->offset>0){
			$params[]='OFFSET '.$this->offset;
		}
		return [
			'params'=>$params===[] ? '' : "\n\t\t\t".implode("\n\t\t\t", $params)."\n\t\t",
			'vars'=>$this->vars,
		];
	}

	public function debugContext(): array {
		$context=[
			'where_fragments'=>$this->where,
			'vars_count'=>count($this->vars),
			'order_by'=>$this->order_by,
		];
		if($this->limit!==null){
			$context['limit']=$this->limit;
		}
		if($this->offset!==null){
			$context['offset']=$this->offset;
		}
		return $context;
	}

	protected function builderState(): array {
		return [
			'where'=>$this->where,
			'vars'=>$this->vars,
			'order_by'=>$this->order_by,
			'limit'=>$this->limit,
			'offset'=>$this->offset,
		];
	}

	protected function applyBuilderState(array $state): void {
		$this->where=is_array($state['where'] ?? null) ? array_values($state['where']) : [];
		$this->vars=is_array($state['vars'] ?? null) ? array_values($state['vars']) : [];
		$this->order_by=is_array($state['order_by'] ?? null) ? array_values($state['order_by']) : [];
		$this->limit=is_int($state['limit'] ?? null) ? $state['limit'] : null;
		$this->offset=is_int($state['offset'] ?? null) ? $state['offset'] : null;
	}

	private function assert_identifier(string $identifier): string {
		$identifier=trim($identifier);
		if($identifier==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $identifier)!==1){
			throw SqlError::invalidIdentifier('query', $identifier);
		}
		return $identifier;
	}

	private function where_compare(string $column, string $operator, mixed $value): self {
		$this->where[]=$this->assert_identifier($column).' '.$operator.' ?';
		$this->vars[]=$value;
		return $this;
	}

	private function where_group(string $glue, callable $callback): self {
		$group=new self();
		$callback($group);
		if($group->where===[]){
			return $this;
		}
		$this->where[]='('.implode(' '.$glue.' ', $group->where).')';
		foreach($group->vars as $value){
			$this->vars[]=$value;
		}
		return $this;
	}

	private function normalize_temporal_value(mixed $value): string {
		if($value instanceof \DateTimeInterface){
			return $value->format('Y-m-d H:i:s');
		}
		if(is_int($value)){
			return gmdate('Y-m-d H:i:s', $value);
		}
		if(is_string($value)){
			$value=trim($value);
			if($value!==''){
				return $value;
			}
		}
		throw SqlError::invalidTemporalValue('query', $value);
	}

	private function relative_temporal_value(string $unit, int $amount): string {
		if($amount<=0){
			throw SqlError::invalidTemporalWindow('query', $unit, $amount);
		}
		return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
			->modify('-'.$amount.' '.$unit)
			->format('Y-m-d H:i:s');
	}
}
