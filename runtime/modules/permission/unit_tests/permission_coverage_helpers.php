<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Permission;

use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;

final class PermissionStorageProbe {
	private const CHANNEL='permission.storage';

	private function __construct(private TestState $state) {}

	public static function reset(Context $context): self {
		return new self($context->state(self::CHANNEL,[
			'rows'=>[
				'dataphyre.permission_assignments'=>[],
				'dataphyre.permission_roles'=>[],
				'dataphyre.permission_role_permissions'=>[],
			],
			'failures'=>[],
		]));
	}

	public static function active(): self {
		return new self(TestState::channel(self::CHANNEL));
	}

	public function rows(string $table): ?array {
		$tables=$this->state->get('rows',[]);
		return isset($tables[$table]) && is_array($tables[$table]) ? $tables[$table] : null;
	}

	public function replaceRows(string $table,array $rows): self {
		$tables=$this->state->get('rows',[]);
		$tables[$table]=$rows;
		$this->state->put('rows',$tables);
		return $this;
	}

	public function appendRow(string $table,mixed $row): self {
		$rows=$this->rows($table);
		if($rows===null){
			throw new \InvalidArgumentException('Unknown Permission storage table: '.$table);
		}
		$rows[]=$row;
		return $this->replaceRows($table,$rows);
	}

	public function lastRow(string $table): mixed {
		$rows=$this->rows($table) ?? [];
		return $rows===[] ? null : $rows[array_key_last($rows)];
	}

	public function fail(string $operation,string $table): self {
		$failures=$this->state->get('failures',[]);
		$failures[$operation][$table]=true;
		$this->state->put('failures',$failures);
		return $this;
	}

	public function allow(string $operation,?string $table=null): self {
		$failures=$this->state->get('failures',[]);
		if($table===null){
			unset($failures[$operation]);
		}else{
			unset($failures[$operation][$table]);
			if(($failures[$operation] ?? [])===[]){ unset($failures[$operation]); }
		}
		$this->state->put('failures',$failures);
		return $this;
	}

	public function shouldFail(string $operation,string $table): bool {
		return ($this->state->get('failures',[])[$operation][$table] ?? false)===true;
	}

	public function select(string $table,?string $where,?array $parameters): array|false {
		if($this->shouldFail('select',$table)){ return false; }
		$rows=$this->rows($table);
		if($rows===null){ return false; }
		return array_values(array_filter(
			$rows,
			static fn(mixed $row): bool=>is_array($row)
				? dp_permission_sql_matches($row,$where,$parameters)
				: ($where===null || trim($where)==='')
		));
	}

	public function insert(string $table,mixed $fields): string|false {
		if($this->shouldFail('insert',$table)){ return false; }
		$rows=$this->rows($table);
		if($rows===null || !is_array($fields)){ return false; }
		if($table==='dataphyre.permission_roles'){
			foreach($rows as $row){
				if(is_array($row) && ($row['name'] ?? null)===($fields['name'] ?? null)){ return false; }
			}
		}
		$this->appendRow($table,$fields);
		return (string)($fields['id'] ?? $fields['name'] ?? '1');
	}

	public function update(string $table,mixed $fields,?string $where,?array $parameters): int|false {
		if($this->shouldFail('update',$table)){ return false; }
		$rows=$this->rows($table);
		if($rows===null || !is_array($fields)){ return false; }
		$count=0;
		foreach($rows as $index=>$row){
			if(is_array($row) && dp_permission_sql_matches($row,$where,$parameters)){
				$rows[$index]=array_replace($row,$fields);
				$count++;
			}
		}
		$this->replaceRows($table,$rows);
		return $count;
	}

	public function delete(string $table,?string $where,?array $parameters): int|false {
		if($this->shouldFail('delete',$table)){ return false; }
		$rows=$this->rows($table);
		if($rows===null){ return false; }
		$remaining=array_values(array_filter(
			$rows,
			static fn(mixed $row): bool=>!is_array($row) || !dp_permission_sql_matches($row,$where,$parameters)
		));
		$this->replaceRows($table,$remaining);
		return count($rows)-count($remaining);
	}
}

function dp_permission_sql_reset(Context $context): PermissionStorageProbe {
	return PermissionStorageProbe::reset($context);
}

function dp_permission_sql_probe(): PermissionStorageProbe {
	return PermissionStorageProbe::active();
}

function dp_permission_sql_matches(array $row,?string $where,?array $parameters): bool {
	if($where===null || trim($where)===''){
		return true;
	}
	$parameters ??=[];
	return match(trim($where)){
		'WHERE id=?'=>(string)($row['id'] ?? '')===(string)($parameters[0] ?? ''),
		'WHERE name=?'=>(string)($row['name'] ?? '')===(string)($parameters[0] ?? ''),
		'WHERE role=?'=>(string)($row['role'] ?? '')===(string)($parameters[0] ?? ''),
		'WHERE scope=?'=>(string)($row['scope'] ?? '')===(string)($parameters[0] ?? ''),
		'WHERE subject_type=? AND subject_id=? AND scope=? AND kind=? AND value=?'=>(string)($row['subject_type'] ?? '')===(string)($parameters[0] ?? '')
			&& (string)($row['subject_id'] ?? '')===(string)($parameters[1] ?? '')
			&& (string)($row['scope'] ?? '')===(string)($parameters[2] ?? '')
			&& (string)($row['kind'] ?? '')===(string)($parameters[3] ?? '')
			&& (string)($row['value'] ?? '')===(string)($parameters[4] ?? ''),
		'WHERE subject_type=? AND subject_id=? AND scope IN (?, ?)'=>(string)($row['subject_type'] ?? '')===(string)($parameters[0] ?? '')
			&& (string)($row['subject_id'] ?? '')===(string)($parameters[1] ?? '')
			&& in_array((string)($row['scope'] ?? ''),[(string)($parameters[2] ?? ''),(string)($parameters[3] ?? '')],true),
		default=>false,
	};
}

if(!function_exists(__NAMESPACE__.'\\sql_select')){
	function sql_select($fields=null,$table=null,$where=null,$parameters=null,...$unused): array|false {
		return dp_permission_sql_probe()->select(
			(string)$table,
			is_string($where) ? $where : null,
			is_array($parameters) ? $parameters : null,
		);
	}
}

if(!function_exists(__NAMESPACE__.'\\sql_insert')){
	function sql_insert($table=null,$fields=null,...$unused): string|false {
		return dp_permission_sql_probe()->insert((string)$table,$fields);
	}
}

if(!function_exists(__NAMESPACE__.'\\sql_update')){
	function sql_update($table=null,$fields=null,$where=null,$parameters=null,...$unused): int|false {
		return dp_permission_sql_probe()->update(
			(string)$table,
			$fields,
			is_string($where) ? $where : null,
			is_array($parameters) ? $parameters : null,
		);
	}
}

if(!function_exists(__NAMESPACE__.'\\sql_delete')){
	function sql_delete($table=null,$where=null,$parameters=null,...$unused): int|false {
		return dp_permission_sql_probe()->delete(
			(string)$table,
			is_string($where) ? $where : null,
			is_array($parameters) ? $parameters : null,
		);
	}
}
