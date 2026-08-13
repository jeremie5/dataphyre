<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/** Deterministic transaction store for tests and single-process applications. */
final class ReactorInMemoryTransactionStore implements ReactorTransactionStore, \JsonSerializable {
	private array $documents=[];

	public function seed(string $component, array $state, int $version=0): self {
		$component=self::component($component);
		$document=$this->document($component);
		$document['state']=$state;
		$document['version']=max(0, $version);
		$document['updated_at']=time();
		$this->documents[$component]=$document;
		return $this;
	}

	public function load(string $component): array {
		$component=self::component($component);
		$document=$this->document($component);
		return ['state'=>$document['state'], 'version'=>$document['version'], 'updated_at'=>$document['updated_at']];
	}

	public function commit(string $component, int $expectedVersion, array $state, string $idempotencyKey, array $receipt, array $events=[]): bool {
		$component=self::component($component);
		$document=$this->document($component);
		if($document['version']!==$expectedVersion){ return false; }
		$document['state']=$state;
		$document['version']=$expectedVersion+1;
		$document['updated_at']=time();
		$document['receipts'][$idempotencyKey]=$receipt;
		foreach($events as $event){
			$document['sequence']++;
			$document['events'][]=['sequence'=>$document['sequence'], 'timestamp'=>time()]+(is_array($event) ? $event : ['data'=>$event]);
		}
		$this->documents[$component]=$document;
		return true;
	}

	public function receipt(string $component, string $idempotencyKey): ?array {
		$component=self::component($component);
		return $this->document($component)['receipts'][$idempotencyKey] ?? null;
	}

	public function enqueue(ReactorStateTransaction $transaction): void {
		$document=$this->document($transaction->component());
		$document['queue'][$transaction->idValue()]=$transaction->jsonSerialize();
		$this->documents[$transaction->component()]=$document;
	}

	public function queued(string $component, int $limit=100): array {
		$component=self::component($component);
		return array_slice(array_values($this->document($component)['queue']), 0, max(0, $limit));
	}

	public function dequeue(string $component, string $transactionId): bool {
		$component=self::component($component);
		$document=$this->document($component);
		if(!isset($document['queue'][$transactionId])){ return false; }
		unset($document['queue'][$transactionId]);
		$this->documents[$component]=$document;
		return true;
	}

	public function events(string $component, int $afterSequence=0, int $limit=100): array {
		$component=self::component($component);
		$events=array_values(array_filter($this->document($component)['events'], static fn(array $event): bool => (int)$event['sequence']>$afterSequence));
		return array_slice($events, 0, max(0, $limit));
	}

	public function jsonSerialize(): array { return $this->documents; }

	private function document(string $component): array {
		return $this->documents[$component] ?? [
			'state'=>[], 'version'=>0, 'updated_at'=>0, 'receipts'=>[], 'queue'=>[], 'events'=>[], 'sequence'=>0,
		];
	}

	private static function component(string $component): string {
		return class_exists(ReactorName::class) ? ReactorName::normalize($component) : strtolower(trim($component));
	}
}
