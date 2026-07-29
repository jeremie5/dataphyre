<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Recovery;

use InvalidArgumentException;

/** Process-local catalog of typed problems, aliases, patterns, and corrective actions. */
final class RecoveryRegistry {
	/** @var array<string,ProblemDefinition> */
	private array $problems=[];
	/** @var array<string,string> */
	private array $aliases=[];
	/** @var array<int,array{pattern:string,problem:string}> */
	private array $patterns=[];
	/** @var array<string,RecoveryActionDefinition> */
	private array $actions=[];
	private ?string $fallbackId=null;

	public function registerProblem(ProblemDefinition $definition, array $aliases=[]): self {
		$this->problems[$definition->id()]=$definition;
		foreach($aliases as $alias) $this->alias((string)$alias, $definition->id());
		if($this->fallbackId===null) $this->fallbackId=$definition->id();
		return $this;
	}

	public function registerAction(RecoveryActionDefinition $definition): self {
		$this->actions[$definition->id()]=$definition;
		return $this;
	}

	public function alias(string $alias, string $problemId): self {
		$alias=$this->normalizeCode($alias);
		$problemId=$this->normalizeCode($problemId);
		if($alias==='' || $problemId==='') throw new InvalidArgumentException('Recovery aliases require stable values.');
		$this->aliases[$alias]=$problemId;
		return $this;
	}

	public function pattern(string $pattern, string $problemId): self {
		if(@preg_match($pattern, '')===false) throw new InvalidArgumentException('Recovery pattern must be a valid regular expression.');
		$problemId=$this->normalizeCode($problemId);
		if($problemId==='') throw new InvalidArgumentException('Recovery pattern requires a stable problem id.');
		$this->patterns[]=['pattern'=>$pattern, 'problem'=>$problemId];
		return $this;
	}

	public function fallback(string $problemId): self {
		$problemId=$this->normalizeCode($problemId);
		if(!isset($this->problems[$problemId])) throw new InvalidArgumentException('Recovery fallback must reference a registered problem.');
		$this->fallbackId=$problemId;
		return $this;
	}

	public function problem(string $code): ?ProblemDefinition {
		$code=$this->normalizeCode($code);
		$id=$this->aliases[$code] ?? $code;
		if(isset($this->problems[$id])) return $this->problems[$id];
		foreach($this->patterns as $pattern){
			if(preg_match($pattern['pattern'], $code)===1){
				return $this->problems[$pattern['problem']] ?? null;
			}
		}
		return $this->fallbackId!==null ? ($this->problems[$this->fallbackId] ?? null) : null;
	}

	public function action(string $id): ?RecoveryActionDefinition {
		return $this->actions[$this->normalizeCode($id)] ?? null;
	}

	/** @return array<int,ProblemDefinition> */
	public function problems(): array {
		return array_values($this->problems);
	}

	/** @return array<int,RecoveryActionDefinition> */
	public function actions(): array {
		return array_values($this->actions);
	}

	private function normalizeCode(string $code): string {
		return strtolower(trim(preg_replace('/[^a-zA-Z0-9._-]+/', '_', $code) ?? $code, " \t\n\r\0\x0B_"));
	}
}
