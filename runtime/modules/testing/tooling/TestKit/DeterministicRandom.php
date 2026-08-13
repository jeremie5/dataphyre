<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** A deterministic random stream which never mutates PHP's global MT state. */
final class DeterministicRandom {

	private int $counter=0;
	private string $buffer='';

	public function __construct(private int|string $seed) {}

	public function seed(): int|string {
		return $this->seed;
	}

	public function fork(string $scope): self {
		return new self(hash('sha256', $this->canonicalSeed()."\0".$scope));
	}

	public function bytes(int $length): string {
		if($length<0){
			throw new \InvalidArgumentException('Deterministic byte length cannot be negative.');
		}
		while(strlen($this->buffer)<$length){
			$this->buffer.=hash('sha256', $this->canonicalSeed()."\0".$this->counter++, true);
		}
		$result=substr($this->buffer, 0, $length);
		$this->buffer=substr($this->buffer, $length);
		return $result;
	}

	public function int(int $min, int $max): int {
		if($min>$max){
			throw new \InvalidArgumentException('Deterministic integer minimum cannot exceed its maximum.');
		}
		if($min===$max){
			return $min;
		}
		if($min===PHP_INT_MIN && $max===PHP_INT_MAX){
			return $this->signedInt64();
		}
		$distance=$max-$min;
		if(is_int($distance) && $distance>=0 && $distance<2147483647){
			$span=$distance + 1;
			$domain=2147483648;
			$limit=$domain-($domain%$span);
			do{
				$parts=unpack('Nvalue', $this->bytes(4));
				$value=((int)($parts['value'] ?? 0))&0x7fffffff;
			}while($value>=$limit);
			return $min+($value%$span);
		}
		$unsigned=$this->unsignedInt63();
		if(($min===0 && $max===PHP_INT_MAX) || (is_int($distance) && $distance===PHP_INT_MAX)){
			return $min+(int)$unsigned;
		}
		$ratio=$unsigned/(float)PHP_INT_MAX;
		$value=(float)$min+floor($ratio*(((float)$max-(float)$min)+1.0));
		return (int)max((float)$min, min((float)$max, $value));
	}

	public function bool(): bool {
		return $this->int(0, 1)===1;
	}

	public function pick(array $values): mixed {
		$values=array_values($values);
		return $values===[] ? null : $values[$this->int(0, count($values)-1)];
	}

	public function string(int $min_length=0, int $max_length=16, string $alphabet='abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'): string {
		$min_length=max(0, $min_length);
		$max_length=max($min_length, $max_length);
		if($alphabet==='' && $max_length>0){
			throw new \InvalidArgumentException('Deterministic string alphabet cannot be empty.');
		}
		$length=$this->int($min_length, $max_length);
		$value='';
		for($index=0; $index<$length; $index++){
			$value.=$alphabet[$this->int(0, strlen($alphabet)-1)];
		}
		return $value;
	}

	private function canonicalSeed(): string {
		return gettype($this->seed).':'.(string)$this->seed;
	}

	private function unsignedInt63(): int {
		$parts=unpack('Nhigh/Nlow', $this->bytes(8));
		return (((int)($parts['high'] ?? 0)&0x7fffffff)*4294967296)+(int)($parts['low'] ?? 0);
	}

	private function signedInt64(): int {
		$parts=unpack('Nhigh/Nlow', $this->bytes(8));
		$high=(int)($parts['high'] ?? 0);
		$magnitude=(($high&0x7fffffff)*4294967296)+(int)($parts['low'] ?? 0);
		return ($high&0x80000000)!==0 ? PHP_INT_MIN+(int)$magnitude : (int)$magnitude;
	}
}
