<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Transactional in-memory authentication adapter for tests and single-process applications. */
final class PanelMemoryAuthenticationStore implements PanelAuthenticationStore {
	/** @var array<string,array<string,PanelAuthenticationRecord>> */ private array $records=[];
	private bool $inTransaction=false;

	public function transaction(callable $callback): mixed {
		if($this->inTransaction){ return $callback($this); }
		$snapshot=$this->records; $this->inTransaction=true;
		try{ return $callback($this); }
		catch(\Throwable $error){ $this->records=$snapshot; throw $error; }
		finally{ $this->inTransaction=false; }
	}
	public function get(string $collection, string $id): ?PanelAuthenticationRecord { return $this->records[$collection][$id] ?? null; }
	public function create(PanelAuthenticationRecord $record): PanelAuthenticationRecord {
		if(isset($this->records[$record->collection()][$record->id()])){ throw new PanelAuthenticationConflict('Authentication record already exists.'); }
		$stored=$record->withRevision(1); $this->records[$record->collection()][$record->id()]=$stored; return $stored;
	}
	public function save(PanelAuthenticationRecord $record, ?int $expectedRevision=null): PanelAuthenticationRecord {
		$current=$this->get($record->collection(), $record->id()) ?? throw new \OutOfBoundsException('Authentication record does not exist.');
		$expected=$expectedRevision ?? $record->revision();
		if($current->revision()!==$expected){ throw new PanelAuthenticationConflict('Authentication record revision conflict.'); }
		$stored=$record->withRevision($current->revision()+1); $this->records[$record->collection()][$record->id()]=$stored; return $stored;
	}
	public function all(string $collection, array $criteria=[]): array {
		$rows=array_values($this->records[$collection] ?? []);
		$rows=array_values(array_filter($rows, static function(PanelAuthenticationRecord $record)use($criteria): bool {
			foreach($criteria as $key=>$expected){ $actual=$key==='id' ? $record->id() : $record->value((string)$key); if(is_array($expected) ? !in_array($actual, $expected, true) : $actual!==$expected){ return false; } } return true;
		}));
		usort($rows, static fn(PanelAuthenticationRecord $a, PanelAuthenticationRecord $b): int=>[$a->createdAt(),$a->id()]<=>[$b->createdAt(),$b->id()]); return $rows;
	}
	public function delete(string $collection, string $id): bool { if(!isset($this->records[$collection][$id])){ return false; } unset($this->records[$collection][$id]); return true; }
}
