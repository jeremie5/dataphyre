<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Convention-configurable adapter for existing repository and gateway objects. */
final class PanelRepositoryDataSource implements PanelDataSource {

	private string $queryMethod;
	private ?string $findMethod;
	private bool $arrayQuery;

	/** @param array<string, mixed> $options */
	public function __construct(private readonly object $repository, array $options=[]) {
		$this->queryMethod=(string)($options['query_method'] ?? $this->discover(['query', 'fetch', 'search', 'paginate']));
		$findMethod=array_key_exists('find_method', $options) && $options['find_method']===null ? '' : (string)($options['find_method'] ?? $this->discover(['find', 'findById', 'get'], false));
		$this->findMethod=$findMethod==='' ? null : $findMethod;
		$this->arrayQuery=(bool)($options['array_query'] ?? false);
		if($this->queryMethod==='' || !is_callable([$repository, $this->queryMethod])){ throw new \InvalidArgumentException('Panel repository does not expose a callable query method.'); }
		if($this->findMethod!==null && !is_callable([$repository, $this->findMethod])){ throw new \InvalidArgumentException('Configured Panel repository find method is not callable.'); }
	}

	public function query(PanelDataQuery $query): PanelDataResult {
		PanelQueryCapabilities::fromArray($this->capabilities())->assertSupports($query);
		$value=$this->repository->{$this->queryMethod}($this->arrayQuery ? $query->jsonSerialize() : $query);
		if($value instanceof PanelDataResult){ return $value; }
		if(!is_array($value) && !$value instanceof \Traversable){ throw new \UnexpectedValueException('Panel repository query must return PanelDataResult, array, or Traversable.'); }
		return PanelDataResult::normalize($value, $query, $this->repository::class);
	}

	public function find(string|int $id, ?PanelDataQuery $scope=null): mixed {
		$scope=$scope ?? PanelDataQuery::make();
		PanelQueryCapabilities::fromArray($this->capabilities())->assertSupports($scope);
		if($this->findMethod!==null){ return $this->repository->{$this->findMethod}($id, $this->arrayQuery ? $scope->jsonSerialize() : $scope); }
		return $this->query($scope->cursor(null)->offset(0)->limit(1)->where('id', $id))->items()[0] ?? null;
	}

	public function capabilities(): array {
		$defaults=array_replace(PanelQueryCapabilities::legacy('repository'), [
			'search'=>true, 'select'=>true, 'include'=>true, 'cursor'=>true, 'offset'=>true,
			'aggregates'=>true, 'tenant'=>true, 'authorization'=>true, 'change_feed'=>false,
		]);
		if(is_callable([$this->repository, 'panelDataCapabilities'])){
			$capabilities=$this->repository->panelDataCapabilities();
			if(is_array($capabilities)){ return array_replace($defaults, $capabilities); }
		}
		return $defaults;
	}

	/** @param list<string> $candidates */
	private function discover(array $candidates, bool $required=true): string {
		foreach($candidates as $method){ if(is_callable([$this->repository, $method])){ return $method; } }
		if($required){ throw new \InvalidArgumentException('Panel repository has no recognized query method.'); }
		return '';
	}
}
