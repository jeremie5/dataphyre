<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Crash-safe local Reactor transaction store using locked atomic JSON files.
 */
final class ReactorFileTransactionStore implements ReactorTransactionStore {
	private string $root;

	public function __construct(string $root) {
		$root=rtrim(str_replace('\\', '/', trim($root)), '/');
		if($root===''){ throw new \InvalidArgumentException('A Reactor transaction store root is required.'); }
		if(!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root)){
			throw new \RuntimeException('Unable to create Reactor transaction store: '.$root);
		}
		$this->root=$root;
	}

	public function load(string $component): array {
		$document=$this->withDocument($component, static fn(array $document): array => [$document, $document]);
		return ['state'=>$document['state'], 'version'=>$document['version'], 'updated_at'=>$document['updated_at']];
	}

	public function commit(string $component, int $expectedVersion, array $state, string $idempotencyKey, array $receipt, array $events=[]): bool {
		return $this->withDocument($component, static function(array $document) use($expectedVersion, $state, $idempotencyKey, $receipt, $events): array {
			if($document['version']!==$expectedVersion){ return [$document, false]; }
			$document['state']=$state;
			$document['version']=$expectedVersion+1;
			$document['updated_at']=time();
			$document['receipts'][$idempotencyKey]=$receipt;
			if(count($document['receipts'])>1000){ $document['receipts']=array_slice($document['receipts'], -1000, null, true); }
			foreach($events as $event){
				$document['sequence']++;
				$document['events'][]=['sequence'=>$document['sequence'], 'timestamp'=>time()]+(is_array($event) ? $event : ['data'=>$event]);
			}
			if(count($document['events'])>5000){ $document['events']=array_slice($document['events'], -5000); }
			return [$document, true];
		});
	}

	public function receipt(string $component, string $idempotencyKey): ?array {
		return $this->withDocument($component, static fn(array $document): array => [$document, $document['receipts'][$idempotencyKey] ?? null]);
	}

	public function enqueue(ReactorStateTransaction $transaction): void {
		$this->withDocument($transaction->component(), static function(array $document) use($transaction): array {
			$document['queue'][$transaction->idValue()]=$transaction->jsonSerialize();
			return [$document, null];
		});
	}

	public function queued(string $component, int $limit=100): array {
		return $this->withDocument($component, static fn(array $document): array => [$document, array_slice(array_values($document['queue']), 0, max(0, $limit))]);
	}

	public function dequeue(string $component, string $transactionId): bool {
		return $this->withDocument($component, static function(array $document) use($transactionId): array {
			if(!isset($document['queue'][$transactionId])){ return [$document, false]; }
			unset($document['queue'][$transactionId]);
			return [$document, true];
		});
	}

	public function events(string $component, int $afterSequence=0, int $limit=100): array {
		return $this->withDocument($component, static function(array $document) use($afterSequence, $limit): array {
			$events=array_values(array_filter($document['events'], static fn(array $event): bool => (int)($event['sequence'] ?? 0)>$afterSequence));
			return [$document, array_slice($events, 0, max(0, $limit))];
		});
	}

	private function withDocument(string $component, callable $callback): mixed {
		$component=class_exists(ReactorName::class) ? ReactorName::normalize($component) : strtolower(trim($component));
		$key=hash('sha256', strtolower(trim($component)));
		$path=$this->root.'/'.$key.'.json';
		$lockPath=$this->root.'/'.$key.'.lock';
		$lock=@fopen($lockPath, 'c+b');
		if($lock===false || !flock($lock, LOCK_EX)){
			if(is_resource($lock)){ fclose($lock); }
			throw new \RuntimeException('Unable to lock Reactor transaction state.');
		}
		try {
			$document=$this->readDocument($path);
			[$updated, $result]=$callback($document);
			if($updated!==$document){ $this->writeDocument($path, $updated); }
			return $result;
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	private function readDocument(string $path): array {
		$decoded=is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
		$decoded=is_array($decoded) ? $decoded : [];
		return [
			'state'=>is_array($decoded['state'] ?? null) ? $decoded['state'] : [],
			'version'=>max(0, (int)($decoded['version'] ?? 0)),
			'updated_at'=>max(0, (int)($decoded['updated_at'] ?? 0)),
			'receipts'=>is_array($decoded['receipts'] ?? null) ? $decoded['receipts'] : [],
			'queue'=>is_array($decoded['queue'] ?? null) ? $decoded['queue'] : [],
			'events'=>is_array($decoded['events'] ?? null) ? array_values($decoded['events']) : [],
			'sequence'=>max(0, (int)($decoded['sequence'] ?? 0)),
		];
	}

	private function writeDocument(string $path, array $document): void {
		$temporary=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
		$json=json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		if(@file_put_contents($temporary, $json, LOCK_EX)===false){ throw new \RuntimeException('Unable to write Reactor transaction state.'); }
		if(!@rename($temporary, $path)){
			@unlink($temporary);
			throw new \RuntimeException('Unable to atomically publish Reactor transaction state.');
		}
	}
}
