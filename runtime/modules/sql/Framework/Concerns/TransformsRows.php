<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database\Concerns;

/**
 * Adds a post-query row transformation pipeline to SQL builders.
 *
 * Consuming query objects register callbacks that adapt raw database rows into
 * framework-facing arrays after SQL execution but before callers receive
 * queued or fetched results. The pipeline is intentionally in-memory and does
 * not mutate database state.
 */
trait TransformsRows {

	/** @var array<int, callable> Ordered callbacks that accept and return one row array. */
	private array $rowTransformers=[];

	/**
	 * Appends a row transformer to the pipeline.
	 *
	 * Transformers run in registration order and receive the row output from the
	 * previous transformer. Callbacks should return an array to preserve the row
	 * contract used by transformRow().
	 *
	 * @param callable $transformer Callback that transforms one associative row array.
	 * @return void
	 */
	protected function addRowTransformer(callable $transformer): void {
		$this->rowTransformers[]=$transformer;
	}

	/**
	 * Reports whether the row pipeline has any registered callbacks.
	 *
	 * @return bool True when transformQueuedResult() should inspect result shape.
	 */
	protected function hasRowTransformers(): bool {
		return isset($this->rowTransformers[0]);
	}

	/**
	 * Applies the transformer pipeline to one row.
	 *
	 * Each transformer receives the accumulated row and may add, remove,
	 * normalize, or cast fields before the next transformer runs.
	 *
	 * @param array<string, mixed> $row Database row to transform.
	 * @return array<string, mixed> Transformed row.
	 */
	protected function transformRow(array $row): array {
		$transformers=$this->rowTransformers;
		if(isset($transformers[0]) && !isset($transformers[1])){
			return $transformers[0]($row);
		}
		if(isset($transformers[1]) && !isset($transformers[2])){
			return $transformers[1]($transformers[0]($row));
		}
		foreach($transformers as $transformer){
			$row=$transformer($row);
		}
		return $row;
	}

	/**
	 * Applies row transformations to every array row in a result list.
	 *
	 * Non-array entries are preserved as-is, which keeps mixed driver payloads
	 * or metadata slots from being coerced accidentally.
	 *
	 * @param array<int|string, mixed> $rows Result list or keyed row collection.
	 * @return array<int|string, mixed> Result collection with array rows transformed.
	 */
	protected function transformRows(array $rows): array {
		$transformers=$this->rowTransformers;
		if(isset($transformers[0]) && !isset($transformers[1])){
			$transformer=$transformers[0];
			foreach($rows as $key=>$row){
				if(is_array($row)){
					$rows[$key]=$transformer($row);
				}
			}
			return $rows;
		}
		if(isset($transformers[1]) && !isset($transformers[2])){
			$first=$transformers[0];
			$second=$transformers[1];
			foreach($rows as $key=>$row){
				if(is_array($row)){
					$rows[$key]=$second($first($row));
				}
			}
			return $rows;
		}
		foreach($rows as $key=>$row){
			if(is_array($row)){
				foreach($transformers as $transformer){
					$row=$transformer($row);
				}
				$rows[$key]=$row;
			}
		}
		return $rows;
	}

	/**
	 * Transforms queued query results according to their array shape.
	 *
	 * Empty, non-array, or transformer-free results are returned unchanged. List
	 * arrays containing row arrays are transformed as multi-row results; other
	 * arrays are treated as a single associative row.
	 *
	 * @param mixed $result Raw queued result from a SQL driver.
	 * @return mixed original driver result, transformed row list, or transformed associative row.
	 */
	protected function transformQueuedResult(mixed $result): mixed {
		if(!$this->hasRowTransformers() || !is_array($result)){
			return $result;
		}
		if($result===[]){
			return [];
		}
		if(array_is_list($result) && isset($result[0]) && is_array($result[0])){
			return $this->transformRows($result);
		}
		return $this->transformRow($result);
	}
}
