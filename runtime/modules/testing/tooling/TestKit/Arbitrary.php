<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Closure;
use ReflectionFunction;

/** A composable value generator with its corresponding shrink strategy. */
final class Arbitrary {

	/** @var Closure(DeterministicRandom,int): mixed */
	private Closure $sampler;
	/** @var Closure(mixed): iterable<mixed> */
	private Closure $shrinker;

	public function __construct(callable $sampler, ?callable $shrinker=null, private string $description='custom') {
		$this->sampler=Closure::fromCallable($sampler);
		$this->shrinker=$shrinker===null ? static fn(mixed $value): iterable=>[] : Closure::fromCallable($shrinker);
		if($this->description==='custom'){
			$this->description.='@'.self::callableFingerprint($this->sampler);
		}
	}

	public function sample(DeterministicRandom $random, int $size=0): mixed {
		return ($this->sampler)($random, max(0, $size));
	}

	public function shrink(mixed $value): iterable {
		yield from ($this->shrinker)($value);
	}

	public function map(callable $mapper, ?callable $shrinker=null, string $description=''): self {
		$source=$this;
		return new self(
			static fn(DeterministicRandom $random, int $size): mixed=>$mapper($source->sample($random, $size)),
			$shrinker,
			$description!=='' ? $description : $this->description.'.map@'.self::callableFingerprint($mapper)
		);
	}

	public function filter(callable $predicate, int $max_attempts=100, string $description=''): self {
		$source=$this;
		$max_attempts=max(1, $max_attempts);
		return new self(static function(DeterministicRandom $random, int $size)use($source, $predicate, $max_attempts): mixed {
			for($attempt=0; $attempt<$max_attempts; $attempt++){
				$value=$source->sample($random->fork('filter:'.$attempt), $size+$attempt);
				if($predicate($value)===true){
					return $value;
				}
			}
			throw new \RuntimeException("Property generator filter could not satisfy its predicate in {$max_attempts} attempts.");
		}, static function(mixed $value)use($source, $predicate): iterable {
			foreach($source->shrink($value) as $candidate){
				if($predicate($candidate)===true){
					yield $candidate;
				}
			}
		}, $description!=='' ? $description : $this->description.'.filter@'.self::callableFingerprint($predicate));
	}

	public function named(string $description): self {
		return new self($this->sampler, $this->shrinker, trim($description)!=='' ? trim($description) : $this->description);
	}

	public function description(): string {
		return $this->description;
	}

	public function fingerprint(): string {
		return substr(hash('sha256', 'dataphyre-arbitrary-v1:'.$this->description), 0, 24);
	}

	private static function callableFingerprint(callable $callable): string {
		$reflection=new ReflectionFunction(Closure::fromCallable($callable));
		$file=(string)($reflection->getFileName() ?: 'internal');
		$identity=$reflection->getName().':'.$reflection->getStartLine().'-'.$reflection->getEndLine();
		if(is_file($file)){
			$lines=file($file, FILE_IGNORE_NEW_LINES);
			if(is_array($lines)){
				$identity='source:'.implode("\n", array_slice($lines, max(0, $reflection->getStartLine()-1), max(1, $reflection->getEndLine()-$reflection->getStartLine()+1)));
			}
		}
		return substr(hash('sha256', $identity), 0, 16);
	}
}
