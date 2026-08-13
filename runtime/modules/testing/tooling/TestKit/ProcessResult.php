<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Immutable, inspectable result from a shell-free child-process probe. */
final class ProcessResult {

	/** @param list<string> $command */
	public function __construct(
		private array $command,
		private int $exit_code,
		private string $stdout,
		private string $stderr,
		private bool $timed_out,
		private float $duration_seconds
	) {}

	/** @return list<string> */
	public function command(): array {
		return $this->command;
	}

	public function exitCode(): int {
		return $this->exit_code;
	}

	public function stdout(): string {
		return $this->stdout;
	}

	public function stderr(): string {
		return $this->stderr;
	}

	public function timedOut(): bool {
		return $this->timed_out;
	}

	public function durationSeconds(): float {
		return $this->duration_seconds;
	}

	public function succeeded(): bool {
		return !$this->timed_out && $this->exit_code===0;
	}

	public function json(bool $associative=true): mixed {
		return json_decode($this->stdout, $associative, 512, JSON_THROW_ON_ERROR);
	}

	/** Decodes a machine-readable error envelope from the standard-error stream. */
	public function stderrJson(bool $associative=true): mixed {
		return json_decode($this->stderr, $associative, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * Returns a copy-safe failure envelope without allowing an unbounded child
	 * response to inflate assertion traces, JUnit reports, or CI logs.
	 *
	 * @return array{exit_code:int,timed_out:bool,command:list<string>,stdout:string,stderr:string,duration_seconds:float}
	 */
	public function diagnostic(int $stream_limit=4096): array {
		if($stream_limit<32){
			throw new \InvalidArgumentException('Process diagnostic stream limit must be at least 32 bytes.');
		}
		return [
			'exit_code'=>$this->exit_code,
			'timed_out'=>$this->timed_out,
			'command'=>$this->command,
			'stdout'=>$this->boundedStream($this->stdout, $stream_limit),
			'stderr'=>$this->boundedStream($this->stderr, $stream_limit),
			'duration_seconds'=>$this->duration_seconds,
		];
	}

	private function boundedStream(string $stream, int $limit): string {
		$stream=trim($stream);
		$bytes=strlen($stream);
		if($bytes<=$limit){
			return $stream;
		}
		$head=intdiv($limit, 2);
		$tail=$limit-$head;
		return substr($stream, 0, $head)
			."\n...[truncated ".($bytes-$limit)." bytes]...\n"
			.substr($stream, -$tail);
	}
}
