<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\TestContext;

use Closure;
use Countable;
use Throwable;
use Traversable;

final class GeneratedCases implements \IteratorAggregate, Countable {

	/** @var Closure(): iterable<string, array<int, mixed>> */
	private Closure $factory;
	/** @var Closure(mixed): iterable<mixed>|null */
	private ?Closure $shrinker;

	public function __construct(private string $kind, private int $seed, private int $count, callable $factory, ?callable $shrinker=null, private string $fingerprint='') {
		$this->kind=trim($kind)!=='' ? trim($kind) : 'property';
		$this->count=max(0, $count);
		$this->factory=Closure::fromCallable($factory);
		$this->shrinker=$shrinker===null ? null : Closure::fromCallable($shrinker);
		$this->fingerprint=$fingerprint!=='' ? $fingerprint : substr(hash('sha256', 'dataphyre-generated:'.$this->kind), 0, 24);
	}

	public static function fromArbitrary(string $kind, int $seed, int $count, Arbitrary $arbitrary): self {
		$count=max(0, $count);
		return new self($kind, $seed, $count, static function()use($seed, $count, $arbitrary, $kind): iterable {
			$root=new DeterministicRandom($seed);
			for($index=0; $index<$count; $index++){
				yield $kind.'_'.$index=>[$arbitrary->sample($root->fork('case:'.$index), $index)];
			}
		}, static function(array $case)use($arbitrary): iterable {
			$value=$case[0] ?? null;
			foreach($arbitrary->shrink($value) as $candidate){
				yield [$candidate];
			}
		}, $arbitrary->fingerprint());
	}

	public function getIterator(): Traversable {
		yield from ($this->factory)();
	}

	/**
	 * Splits an expensive deterministic property into independently schedulable cases.
	 *
	 * Every yielded dataset row contains one GeneratedCases shard. Shards retain
	 * shrinking and replay support while bounding the lifecycle and memory retained
	 * by one worker.
	 *
	 * @return iterable<string,array{0:self}>
	 */
	public function shards(int $cases_per_shard): iterable {
		if($cases_per_shard<1){
			throw new \InvalidArgumentException('Generated-case shard size must be at least one.');
		}
		if($this->count===0){
			return;
		}
		$shard_count=(int)ceil($this->count/$cases_per_shard);
		for($shard_index=0; $shard_index<$shard_count; $shard_index++){
			$offset=$shard_index*$cases_per_shard;
			$length=min($cases_per_shard, $this->count-$offset);
			$parent=$this;
			$number=$shard_index+1;
			$kind=$this->kind.' shard '.$number.'/'.$shard_count;
			$fingerprint=$this->shardFingerprint($offset,$length);
			$shard=new self(
				$kind,
				$this->seed,
				$length,
				static function()use($parent, $offset, $length): iterable {
					$index=0;
					foreach($parent as $label=>$case){
						if($index>=$offset+$length){
							break;
						}
						if($index>=$offset){
							yield $label=>$case;
						}
						$index++;
					}
				},
				$this->shrinker,
				$fingerprint,
			);
			$first=$offset+1;
			$last=$offset+$length;
			yield $this->kind.' shard '.$number.'/'.$shard_count.' cases '.$first.'-'.$last=>[$shard];
		}
	}

	private function shardFingerprint(int $offset,int $length): string {
		return substr(hash('sha256',implode("\0",[$this->fingerprint,(string)$offset,(string)$length,(string)$this->count])),0,24);
	}

	public function kind(): string {
		return $this->kind;
	}

	public function seed(): int {
		return $this->seed;
	}

	public function count(): int {
		return $this->count;
	}

	public function fingerprint(): string {
		return $this->fingerprint;
	}

	public function replayToken(string|int $label, mixed $case): string {
		$payload=[
			'version'=>1,
			'kind'=>$this->kind,
			'fingerprint'=>$this->fingerprint,
			'seed'=>$this->seed,
			'label'=>(string)$label,
			'case'=>$case,
		];
		try{
			$json=json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
		}catch(\JsonException $failure){
			throw new \InvalidArgumentException('Generated case cannot be encoded into a replay token: '.$failure->getMessage(), 0, $failure);
		}
		$body=self::base64UrlEncode($json);
		$checksum=substr(hash('sha256', 'dataphyre-replay-v1.'.$body), 0, 24);
		return 'dpt1.'.$body.'.'.$checksum;
	}

	/** @return array{label:string,case:mixed,kind:string,seed:int,fingerprint:string} */
	public function validateReplayToken(string $token): array {
		if(str_starts_with($token, 'dpt1.')){
			$parts=explode('.', $token);
			if(count($parts)!==3 || !hash_equals(substr(hash('sha256', 'dataphyre-replay-v1.'.($parts[1] ?? '')), 0, 24), (string)($parts[2] ?? ''))){
				throw new \InvalidArgumentException('Generated-case replay token checksum is invalid.');
			}
			$json=self::base64UrlDecode((string)$parts[1]);
			try{
				$decoded=json_decode($json, true, 512, JSON_THROW_ON_ERROR);
			}catch(\JsonException $failure){
				throw new \InvalidArgumentException('Generated-case replay token JSON is invalid.', 0, $failure);
			}
			if(!is_array($decoded) || ($decoded['version'] ?? null)!==1 || !array_key_exists('case', $decoded)){
				throw new \InvalidArgumentException('Generated-case replay token payload is incomplete.');
			}
			if((string)($decoded['kind'] ?? '')!==$this->kind || (string)($decoded['fingerprint'] ?? '')!==$this->fingerprint){
				throw new \InvalidArgumentException('Generated-case replay token belongs to a different generator contract.');
			}
			return [
				'label'=>(string)($decoded['label'] ?? 'replay'),
				'case'=>$decoded['case'],
				'kind'=>$this->kind,
				'seed'=>(int)($decoded['seed'] ?? 0),
				'fingerprint'=>$this->fingerprint,
			];
		}
		$decoded=json_decode((string)base64_decode($token, true), true);
		if(!is_array($decoded) || !array_key_exists('case', $decoded)){
			throw new \InvalidArgumentException('Generated-case replay token is invalid.');
		}
		if(isset($decoded['kind']) && (string)$decoded['kind']!==$this->kind){
			throw new \InvalidArgumentException('Legacy replay token belongs to a different generator kind.');
		}
		return [
			'label'=>(string)($decoded['label'] ?? 'replay'),
			'case'=>$decoded['case'],
			'kind'=>$this->kind,
			'seed'=>(int)($decoded['seed'] ?? 0),
			'fingerprint'=>$this->fingerprint,
		];
	}

	public function replay(string $token, bool $strict=false): iterable {
		try{
			$decoded=$this->validateReplayToken($token);
			yield $decoded['label']=>$decoded['case'];
			return;
		}catch(\InvalidArgumentException $failure){
			if($strict){
				throw $failure;
			}
		}
		yield from $this;
	}

	public function shrink(mixed $case, callable $assertion, TestContext $context): mixed {
		return $this->shrinkResult($case, $assertion, $context)->minimal;
	}

	public function shrinkResult(mixed $case, callable $assertion, TestContext $context, int $max_candidates=1000): ShrinkResult {
		if($this->shrinker===null){
			return new ShrinkResult($case, $case, 0, [], true);
		}
		$best=$case;
		$path=[];
		$seen=[self::valueFingerprint($case)=>true];
		$candidates=0;
		$fixed_point=false;
		while($candidates<$max_candidates){
			$advanced=false;
			foreach(($this->shrinker)($best) as $candidate){
				$fingerprint=self::valueFingerprint($candidate);
				if(isset($seen[$fingerprint])){
					continue;
				}
				$seen[$fingerprint]=true;
				$candidates++;
				try{
					$args=is_array($candidate) && self::isListValue($candidate) ? $candidate : [$candidate];
					$result=$assertion($context, ...$args);
					$failed=$result===false;
				}catch(Throwable){
					$failed=true;
				}
				if($failed){
					$best=$candidate;
					$path[]=$candidate;
					$advanced=true;
					break;
				}
				if($candidates>=$max_candidates){
					break 2;
				}
			}
			if($advanced===false){
				$fixed_point=true;
				break;
			}
		}
		return new ShrinkResult($case, $best, $candidates, $path, $fixed_point);
	}

	private static function base64UrlEncode(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	private static function base64UrlDecode(string $value): string {
		$decoded=base64_decode(strtr($value, '-_', '+/'), true);
		if($decoded===false){
			throw new \InvalidArgumentException('Generated-case replay token encoding is invalid.');
		}
		return $decoded;
	}

	private static function valueFingerprint(mixed $value): string {
		try{
			return hash('sha256', serialize($value));
		}catch(Throwable){
			return hash('sha256', get_debug_type($value).':'.spl_object_id((object)$value));
		}
	}

	private static function isListValue(array $value): bool {
		if(function_exists('array_is_list')){
			return array_is_list($value);
		}
		return array_keys($value)===range(0, count($value)-1);
	}
}
