<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database\Concerns;

trait TransformsRows {

	private array $row_transformers=[];

	protected function addRowTransformer(callable $transformer): void {
		$this->row_transformers[]=$transformer;
	}

	protected function hasRowTransformers(): bool {
		return $this->row_transformers!==[];
	}

	protected function transformRow(array $row): array {
		foreach($this->row_transformers as $transformer){
			$row=$transformer($row);
		}
		return $row;
	}

	protected function transformRows(array $rows): array {
		foreach($rows as $key=>$row){
			if(is_array($row)){
				$rows[$key]=$this->transformRow($row);
			}
		}
		return $rows;
	}

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
