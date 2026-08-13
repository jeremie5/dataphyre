<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Query proxy returned by a data-source-bound Resource. */
final class PanelDataSourceResourceQuery {
	public function __construct(
		private readonly PanelDataSourceResourceBridge $bridge,
		private readonly ?PanelRequest $request,
		private readonly Resource $resource,
		private array $scopeFilters=[],
		private ?PanelQueryExpression $scopeExpression=null
	){}

	public function paginateRecords(int $page, int $perPage): PanelDataResult {
		return $this->bridge->query($this->request, ['page'=>$page, 'limit'=>$perPage, 'scope_filters'=>$this->scopeFilters, 'scope_expression'=>$this->scopeExpression], $this->resource);
	}

	public function getRecords(): PanelDataResult {
		return $this->bridge->query($this->request, ['page'=>1, 'limit'=>$this->bridge->collectionLimit(), 'cursor'=>null, 'scope_filters'=>$this->scopeFilters, 'scope_expression'=>$this->scopeExpression], $this->resource);
	}

	public function findRecord(string|int $id): mixed {
		return $this->bridge->find($id, $this->request, $this->resource, $this->scopeFilters, $this->scopeExpression);
	}

	public function where(string $field, mixed $operatorOrValue, mixed $value=null): self {
		$clone=clone $this;
		$clone->scopeFilters[]=func_num_args()===2
			? ['field'=>$field, 'operator'=>'eq', 'value'=>$operatorOrValue]
			: ['field'=>$field, 'operator'=>(string)$operatorOrValue, 'value'=>$value];
		return $clone;
	}

	public function whereExpression(PanelQueryExpression $expression, string $boolean='and'): self {
		$clone=clone $this;
		$clone->scopeExpression=$clone->scopeExpression===null ? $expression : PanelQueryGroup::make($boolean, [$clone->scopeExpression, $expression]);
		return $clone;
	}

	public function whereRelation(string $relation, PanelQueryExpression $expression, string $quantifier='any'): self {
		return $this->whereExpression(PanelQueryRelation::make($relation, $expression, $quantifier));
	}

	public function deny(): self {
		return $this->whereExpression(PanelQueryIn::make('__panel_tenant_denied', []));
	}
}
