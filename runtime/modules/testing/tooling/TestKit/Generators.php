<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

final class Generators {

	public static function integers(int $min, int $max, int $count, ?int $seed=null): iterable {
		$random=new DeterministicRandom($seed ?? random_int(1, PHP_INT_MAX));
		for($index=0; $index<max(0, $count); $index++){
			yield 'int_'.$index=>[$random->fork('integer:'.$index)->int($min, $max)];
		}
	}

	public static function strings(int $count, int $min_length=0, int $max_length=16, ?int $seed=null): iterable {
		$random=new DeterministicRandom($seed ?? random_int(1, PHP_INT_MAX));
		for($index=0; $index<max(0, $count); $index++){
			yield 'str_'.$index=>[$random->fork('string:'.$index)->string($min_length, $max_length)];
		}
	}

	public static function oneOf(array $values, int $count, ?int $seed=null): iterable {
		$random=new DeterministicRandom($seed ?? random_int(1, PHP_INT_MAX));
		for($index=0; $index<max(0, $count); $index++){
			yield 'one_'.$index=>[$random->fork('one:'.$index)->pick($values)];
		}
	}

	public static function integer(int $min=PHP_INT_MIN, int $max=PHP_INT_MAX): Arbitrary {
		if($min>$max){
			throw new \InvalidArgumentException('Integer generator minimum cannot exceed its maximum.');
		}
		return new Arbitrary(
			static fn(DeterministicRandom $random): int=>$random->int($min, $max),
			static function(mixed $value)use($min, $max): iterable {
				$value=(int)$value;
				$target=max($min, min($max, 0));
				$current=$value;
				while($current!==$target){
					$next=$target+(int)(($current-$target)/2);
					if($next===$current){
						$next=$target;
					}
					yield $next;
					$current=$next;
				}
			},
			'integer('.$min.','.$max.')'
		);
	}

	public static function string(int $min_length=0, int $max_length=16, string $alphabet='abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'): Arbitrary {
		$min_length=max(0, $min_length);
		$max_length=max($min_length, $max_length);
		return new Arbitrary(
			static fn(DeterministicRandom $random): string=>$random->string($min_length, $max_length, $alphabet),
			static function(mixed $value)use($min_length): iterable {
				$value=(string)$value;
				while(strlen($value)>$min_length){
					$next_length=max($min_length, intdiv(strlen($value), 2));
					$value=substr($value, 0, $next_length);
					yield $value;
				}
			},
			'string('.$min_length.','.$max_length.','.hash('sha256', $alphabet).')'
		);
	}

	public static function element(array $values): Arbitrary {
		$values=array_values($values);
		return new Arbitrary(
			static fn(DeterministicRandom $random): mixed=>$random->pick($values),
			static function(mixed $value)use($values): iterable {
				if($values!==[] && $value!==$values[0]){
					yield $values[0];
				}
			},
			'element('.substr(hash('sha256', serialize($values)), 0, 16).')'
		);
	}

	public static function boolean(): Arbitrary {
		return new Arbitrary(static fn(DeterministicRandom $random): bool=>$random->bool(), static function(mixed $value): iterable {
			if($value===true){
				yield false;
			}
		}, 'boolean');
	}

	public static function nullable(Arbitrary $value, int $null_weight=1, int $value_weight=3): Arbitrary {
		$null_weight=max(0, $null_weight);
		$value_weight=max(0, $value_weight);
		if($null_weight+$value_weight===0){
			throw new \InvalidArgumentException('Nullable generator needs a positive weight.');
		}
		return new Arbitrary(static function(DeterministicRandom $random, int $size)use($value, $null_weight, $value_weight): mixed {
			return $random->int(1, $null_weight+$value_weight)<=$null_weight ? null : $value->sample($random->fork('value'), $size);
		}, static function(mixed $actual)use($value): iterable {
			if($actual!==null){
				yield null;
				yield from $value->shrink($actual);
			}
		}, 'nullable('.$value->fingerprint().','.$null_weight.','.$value_weight.')');
	}

	/** @param array<int|string,Arbitrary> $fields */
	public static function shape(array $fields): Arbitrary {
		foreach($fields as $field=>$generator){
			if(!$generator instanceof Arbitrary){
				throw new \InvalidArgumentException("Shape field '{$field}' must be an Arbitrary.");
			}
		}
		$description=[];
		foreach($fields as $field=>$generator){
			$description[]=(string)$field.':'.$generator->fingerprint();
		}
		return new Arbitrary(static function(DeterministicRandom $random, int $size)use($fields): array {
			$result=[];
			foreach($fields as $field=>$generator){
				$result[$field]=$generator->sample($random->fork('field:'.(string)$field), $size);
			}
			return $result;
		}, static function(mixed $actual)use($fields): iterable {
			if(!is_array($actual)){
				return;
			}
			foreach($fields as $field=>$generator){
				foreach($generator->shrink($actual[$field] ?? null) as $candidate){
					$copy=$actual;
					$copy[$field]=$candidate;
					yield $copy;
				}
			}
		}, 'shape('.implode(',', $description).')');
	}

	public static function tupleOf(Arbitrary ...$values): Arbitrary {
		return self::shape(array_values($values))->named('tuple('.implode(',', array_map(static fn(Arbitrary $value): string=>$value->fingerprint(), $values)).')');
	}

	public static function listOf(Arbitrary $value, int $min_count=0, int $max_count=10): Arbitrary {
		$min_count=max(0, $min_count);
		$max_count=max($min_count, $max_count);
		return new Arbitrary(static function(DeterministicRandom $random, int $size)use($value, $min_count, $max_count): array {
			$count=$random->int($min_count, $max_count);
			$result=[];
			for($index=0; $index<$count; $index++){
				$result[]=$value->sample($random->fork('item:'.$index), $size);
			}
			return $result;
		}, static function(mixed $actual)use($value, $min_count): iterable {
			if(!is_array($actual)){
				return;
			}
			if(count($actual)>$min_count){
				yield array_slice($actual, 0, max($min_count, intdiv(count($actual), 2)));
				yield array_slice($actual, 0, $min_count);
			}
			foreach($actual as $index=>$item){
				foreach($value->shrink($item) as $candidate){
					$copy=$actual;
					$copy[$index]=$candidate;
					yield $copy;
				}
			}
		}, 'list('.$value->fingerprint().','.$min_count.','.$max_count.')');
	}

	public static function cases(Arbitrary $arbitrary, int $count=100, ?int $seed=null, string $kind='property'): GeneratedCases {
		return GeneratedCases::fromArbitrary($kind, $seed ?? random_int(1, PHP_INT_MAX), $count, $arbitrary);
	}

	public static function fuzzIntegers(int $min, int $max, int $count, ?int $seed=null): GeneratedCases {
		return self::cases(self::integer($min, $max), $count, $seed, 'integers');
	}

	public static function fuzzStrings(int $count, int $min_length=0, int $max_length=16, ?int $seed=null): GeneratedCases {
		return self::cases(self::string($min_length, $max_length), $count, $seed, 'strings');
	}

	public static function tuples(iterable ...$generators): iterable {
		$rows=[];
		foreach($generators as $generator){
			$rows[]=array_values(iterator_to_array($generator, false));
		}
		$count=min(array_map('count', $rows) ?: [0]);
		for($index=0; $index<$count; $index++){
			$tuple=[];
			foreach($rows as $row){
				$value=$row[$index];
				$tuple[]=is_array($value) && count($value)===1 ? $value[0] : $value;
			}
			yield 'tuple_'.$index=>$tuple;
		}
	}
}
