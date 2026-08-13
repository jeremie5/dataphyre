<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable compiled query plan; parameter values are deliberately absent from diagnostics. */
final class PanelSqlCompiledQuery implements \JsonSerializable {
	/**
	 * @param array<string,null|bool|int|float|string> $parameters
	 * @param array<string,null|bool|int|float|string> $baseParameters
	 * @param list<string> $projectedFields
	 * @param list<array{field:string,direction:string,nulls:string,alias:string}> $cursorSorts
	 * @param list<array{alias:string,function:string,field:?string}> $aggregateSpecs
	 */
	public function __construct(
		private readonly string $sql,
		private readonly array $parameters,
		private readonly string $countSql,
		private readonly array $baseParameters,
		private readonly ?string $aggregateSql,
		private readonly array $projectedFields,
		private readonly array $cursorSorts,
		private readonly array $aggregateSpecs,
		private readonly int $offset,
		private readonly int $limit
	){
		if(trim($sql)==='' || trim($countSql)===''){ throw new \InvalidArgumentException('Panel SQL compiled plans require data and count statements.'); }
		if($offset<0 || $limit<1){ throw new \InvalidArgumentException('Panel SQL compiled plan pagination is invalid.'); }
		if($projectedFields===[] || $cursorSorts===[]){ throw new \InvalidArgumentException('Panel SQL compiled plans require projection and deterministic sorts.'); }
	}

	public function sql(): string { return $this->sql; }
	/** @return array<string,null|bool|int|float|string> */ public function parameters(): array { return $this->parameters; }
	public function countSql(): string { return $this->countSql; }
	/** @return array<string,null|bool|int|float|string> */ public function baseParameters(): array { return $this->baseParameters; }
	public function aggregateSql(): ?string { return $this->aggregateSql; }
	/** @return list<string> */ public function projectedFields(): array { return $this->projectedFields; }
	/** @return list<array{field:string,direction:string,nulls:string,alias:string}> */ public function cursorSorts(): array { return $this->cursorSorts; }
	/** @return list<array{alias:string,function:string,field:?string}> */ public function aggregateSpecs(): array { return $this->aggregateSpecs; }
	public function offset(): int { return $this->offset; }
	public function limit(): int { return $this->limit; }

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_sql_compiled_query', 'version'=>1,
			'statement_hash'=>hash('sha256', $this->sql), 'count_statement_hash'=>hash('sha256', $this->countSql),
			'aggregate_statement_hash'=>$this->aggregateSql===null ? null : hash('sha256', $this->aggregateSql),
			'parameter_count'=>count($this->parameters), 'base_parameter_count'=>count($this->baseParameters),
			'parameters_serialized'=>false, 'projected_fields'=>$this->projectedFields,
			'cursor_sorts'=>array_map(static fn(array $sort): array=>array_diff_key($sort, ['alias'=>true]), $this->cursorSorts),
			'aggregates'=>$this->aggregateSpecs, 'offset'=>$this->offset, 'limit'=>$this->limit,
		];
	}
}
