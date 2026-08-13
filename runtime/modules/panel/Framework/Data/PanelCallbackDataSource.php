<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Adapts application callbacks to the universal Panel data-source contract. */
class PanelCallbackDataSource implements PanelDataSource {

	private \Closure $queryCallback;
	private ?\Closure $findCallback;
	/** @var array<string, bool|int|string|list<string>> */
	private array $capabilityMap;

	/** @param array<string, bool|int|string|list<string>> $capabilities */
	public function __construct(callable $query, ?callable $find=null, private readonly string $name='callback', array $capabilities=[]) {
		if(trim($name)===''){ throw new \InvalidArgumentException('Panel callback data source name cannot be blank.'); }
		$this->queryCallback=\Closure::fromCallable($query);
		$this->findCallback=$find===null ? null : \Closure::fromCallable($find);
		$this->capabilityMap=array_replace(PanelQueryCapabilities::legacy('callback'), [
			'search'=>true, 'select'=>true,
			'include'=>true, 'cursor'=>true, 'offset'=>true, 'aggregates'=>true, 'tenant'=>true,
			'authorization'=>true, 'change_feed'=>false,
		], $capabilities);
	}

	public function query(PanelDataQuery $query): PanelDataResult {
		PanelQueryCapabilities::fromArray($this->capabilityMap)->assertSupports($query);
		$value=($this->queryCallback)($query, $query->jsonSerialize());
		if($value instanceof PanelDataResult){ return $value; }
		if(!is_array($value) && !$value instanceof \Traversable){ throw new \UnexpectedValueException('Panel data query callback must return PanelDataResult, array, or Traversable.'); }
		return PanelDataResult::normalize($value, $query, $this->name);
	}

	public function find(string|int $id, ?PanelDataQuery $scope=null): mixed {
		$scope=$scope ?? PanelDataQuery::make();
		PanelQueryCapabilities::fromArray($this->capabilityMap)->assertSupports($scope);
		if($this->findCallback!==null){ return ($this->findCallback)($id, $scope, $scope->jsonSerialize()); }
		return $this->query($scope->cursor(null)->offset(0)->limit(1)->where('id', $id))->items()[0] ?? null;
	}

	public function capabilities(): array { return $this->capabilityMap; }
}
