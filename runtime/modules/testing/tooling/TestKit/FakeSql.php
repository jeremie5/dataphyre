<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\AssertionContext;

final class FakeSql {

	/** @var array<int, array{sql:string, bindings:array<int|string, mixed>}> */
	private array $queries=[];
	private bool $reject_unbound_writes=false;

	public function rejectUnboundWrites(bool $reject=true): self {
		$this->reject_unbound_writes=$reject;
		return $this;
	}

	/** @param array<int|string, mixed> $bindings @return array<int, array<string, mixed>> */
	public function query(string $sql, array $bindings=[]): array {
		if($this->reject_unbound_writes===true && $bindings===[] && preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql)===1){
			throw new AssertionFailed('FakeSql rejected an unbound write query.', 'bound write query', $sql);
		}
		$this->queries[]=[
			'sql'=>$sql,
			'bindings'=>$bindings,
		];
		return [];
	}

	/** @return array<int, array{sql:string, bindings:array<int|string, mixed>}> */
	public function queries(): array {
		return $this->queries;
	}

	public function assertQueryCount(AssertionContext $t, int $expected): void {
		$t->same($expected, count($this->queries), 'Expected fake SQL query count to match.');
	}

	public function assertQueried(AssertionContext $t, string $pattern, ?array $bindings=null): void {
		$found=false;
		foreach($this->queries as $query){
			if(preg_match($pattern, $query['sql'])!==1){
				continue;
			}
			if($bindings!==null && $query['bindings']!==$bindings){
				continue;
			}
			$found=true;
			break;
		}
		if($found===false){
			$t->fail('Expected fake SQL to contain matching query.', ['pattern'=>$pattern, 'bindings'=>$bindings], $this->queries);
		}
		$t->isTrue(true, 'SQL query was recorded.');
	}

	public function assertNoUnboundWrites(AssertionContext $t): void {
		foreach($this->queries as $query){
			if($query['bindings']===[] && preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['sql'])===1){
				$t->fail('Expected write queries to use bindings.', 'bound write query', $query['sql']);
			}
		}
		$t->isTrue(true, 'SQL write bindings are present.');
	}
}
