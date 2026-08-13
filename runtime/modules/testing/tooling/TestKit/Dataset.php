<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

final class Dataset {

	public static function cases(iterable $rows): iterable {
		yield from $rows;
	}

	/**
	 * Materializes a one-shot iterable into rows safe for repeated discovery.
	 *
	 * Numeric keys remain positional dataset labels. Named labels must be unique
	 * because they participate in case names and stable IDs.
	 *
	 * @return array<int|string,mixed>
	 */
	public static function repeatable(iterable $rows): array {
		if(is_array($rows)){
			return $rows;
		}
		$repeatable=[];
		foreach($rows as $label=>$row){
			if(is_string($label)){
				if(array_key_exists($label, $repeatable)){
					throw new \InvalidArgumentException("Dataset label '{$label}' is duplicated.");
				}
				$repeatable[$label]=$row;
				continue;
			}
			$repeatable[]=$row;
		}
		return $repeatable;
	}

	public static function range(int $start, int $end, int $step=1): iterable {
		$step=$step===0 ? 1 : abs($step);
		if($start<=$end){
			for($i=$start; $i<=$end; $i+=$step){
				yield (string)$i=>[$i];
			}
			return;
		}
		for($i=$start; $i>=$end; $i-=$step){
			yield (string)$i=>[$i];
		}
	}

	public static function matrix(array $dimensions): iterable {
		$rows=[['label'=>'', 'values'=>[]]];
		foreach($dimensions as $name=>$values){
			$next=[];
			foreach($rows as $row){
				foreach($values as $label=>$value){
					$next[]=[
						'label'=>trim($row['label'].' '.(is_string($label) ? $name.'='.$label : $name.'='.$value)),
						'values'=>array_merge($row['values'], [$value]),
					];
				}
			}
			$rows=$next;
		}
		foreach($rows as $row){
			yield trim($row['label'])=>$row['values'];
		}
	}

	public static function map(iterable $rows, callable $mapper): iterable {
		foreach($rows as $label=>$row){
			yield $label=>$mapper($row, $label);
		}
	}

	public static function take(iterable $rows, int $limit): iterable {
		$count=0;
		foreach($rows as $label=>$row){
			if($count++>=$limit){
				break;
			}
			yield $label=>$row;
		}
	}
}
