<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Deterministic process-local workflow store for tests and ephemeral panels.
 */
final class InMemoryWorkflowStore implements WorkflowStore, \JsonSerializable {
	/** @var array<string,array<string,mixed>> */
	private array $records=[];

	public function create(WorkflowRecord $record): bool {
		$key=self::key($record->definition(), $record->id());
		if(isset($this->records[$key])){
			return false;
		}
		$this->records[$key]=$record->jsonSerialize();
		return true;
	}

	public function load(string $definition, string $id): ?WorkflowRecord {
		$record=$this->records[self::key($definition, $id)] ?? null;
		return is_array($record) ? WorkflowRecord::fromArray($record) : null;
	}

	public function compareAndSwap(WorkflowRecord $record, int $expectedVersion): bool {
		$key=self::key($record->definition(), $record->id());
		$current=$this->records[$key] ?? null;
		if(!is_array($current) || (int)($current['version'] ?? -1)!==$expectedVersion){
			return false;
		}
		$this->records[$key]=$record->jsonSerialize();
		return true;
	}

	public function all(?string $definition=null): array {
		$definition=$definition===null ? null : WorkflowState::normalize($definition);
		$result=[];
		foreach($this->records as $record){
			if($definition===null || ($record['definition'] ?? null)===$definition){
				$result[]=WorkflowRecord::fromArray($record);
			}
		}
		usort($result, static fn(WorkflowRecord $left, WorkflowRecord $right): int=>strcmp($left->id(), $right->id()));
		return $result;
	}

	public function jsonSerialize(): array {
		return ['type'=>'in_memory_workflow_store', 'count'=>count($this->records)];
	}

	private static function key(string $definition, string $id): string {
		return WorkflowState::normalize($definition).'\0'.trim($id);
	}
}
