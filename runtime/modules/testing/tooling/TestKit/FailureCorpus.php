<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Countable;
use Traversable;

/** File-backed, deduplicated replay cases which survive across test runs. */
final class FailureCorpus implements \IteratorAggregate, Countable {

	/** @var array<string,array<string,mixed>> */
	private array $entries=[];

	public function __construct(private ?string $path=null) {
		if($path!==null && is_file($path)){
			$this->load((string)file_get_contents($path));
		}
	}

	public static function open(string $path): self {
		return new self($path);
	}

	/** @param array<string,mixed> $metadata */
	public function record(string $contract, GeneratedCases $cases, string|int $label, mixed $case, array $metadata=[]): string {
		return $this->recordReplay($contract, $cases->replayToken($label, $case), $metadata);
	}

	/** @param array<string,mixed> $metadata */
	public function recordReplay(string $contract, string $replay, array $metadata=[]): string {
		$contract=trim($contract);
		if($contract===''){
			throw new \InvalidArgumentException('Failure corpus contract cannot be blank.');
		}
		$id=substr(hash('sha256', $contract."\0".$replay), 0, 24);
		$existing=$this->entries[$id] ?? [];
		$this->entries[$id]=[
			'id'=>$id,
			'contract'=>$contract,
			'replay'=>$replay,
			'occurrences'=>(int)($existing['occurrences'] ?? 0)+1,
			'metadata'=>$metadata!==[] ? $metadata : (array)($existing['metadata'] ?? []),
		];
		$this->persist();
		return $id;
	}

	public function replay(string $contract, GeneratedCases $cases): iterable {
		foreach($this->entries as $id=>$entry){
			if((string)($entry['contract'] ?? '')!==$contract){
				continue;
			}
			foreach($cases->replay((string)$entry['replay'], true) as $label=>$case){
				yield $id.':'.$label=>$case;
			}
		}
	}

	public function remove(string $id): bool {
		if(!isset($this->entries[$id])){
			return false;
		}
		unset($this->entries[$id]);
		$this->persist();
		return true;
	}

	/** @return list<array<string,mixed>> */
	public function entries(?string $contract=null): array {
		$entries=array_values($this->entries);
		if($contract!==null){
			$entries=array_values(array_filter($entries, static fn(array $entry): bool=>($entry['contract'] ?? null)===$contract));
		}
		usort($entries, static fn(array $left, array $right): int=>(string)$left['id']<=>(string)$right['id']);
		return $entries;
	}

	public function count(): int {
		return count($this->entries);
	}

	public function getIterator(): Traversable {
		yield from $this->entries();
	}

	/** @return array{version:int,entries:list<array<string,mixed>>} */
	public function toArray(): array {
		return ['version'=>1, 'entries'=>$this->entries()];
	}

	private function load(string $json): void {
		if(trim($json)===''){
			return;
		}
		try{
			$payload=json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		}catch(\JsonException $failure){
			throw new \InvalidArgumentException('Failure corpus JSON is invalid.', 0, $failure);
		}
		if(!is_array($payload) || ($payload['version'] ?? null)!==1 || !is_array($payload['entries'] ?? null)){
			throw new \InvalidArgumentException('Failure corpus schema is unsupported.');
		}
		foreach($payload['entries'] as $entry){
			if(is_array($entry) && trim((string)($entry['id'] ?? ''))!=='' && trim((string)($entry['replay'] ?? ''))!==''){
				$this->entries[(string)$entry['id']]=$entry;
			}
		}
	}

	private function persist(): void {
		if($this->path===null){
			return;
		}
		$directory=dirname($this->path);
		if(!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)){
			throw new \RuntimeException('Failure corpus directory could not be created: '.$directory);
		}
		$json=json_encode($this->toArray(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
		if(file_put_contents($this->path, $json, LOCK_EX)===false){
			throw new \RuntimeException('Failure corpus could not be written: '.$this->path);
		}
	}
}
