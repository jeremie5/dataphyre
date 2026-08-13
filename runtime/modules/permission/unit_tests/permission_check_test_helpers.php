<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\CapturedExecution;
use Dataphyre\Test\Context;
use Dataphyre\Test\TempWorkspace;

if(!defined('DATAPHYRE_PERMISSION_CHECK_NO_DISPATCH')){
	define('DATAPHYRE_PERMISSION_CHECK_NO_DISPATCH', true);
}
require_once dirname(__DIR__).'/kernel/permission_check.php';
dp_permission_check_bootstrap();

/**
 * Intent-level fixture vocabulary for the standalone permission checker.
 *
 * Test cases describe manifests, invocations, and emitted reports while this
 * helper owns temporary paths, JSON serialization, output capture, and injected
 * process boundaries.
 */
final class DpPermissionCheckScenario {
	private TempWorkspace $workspace;
	/** @var list<string> */
	private array $errors=[];
	/** @var list<int> */
	private array $terminations=[];

	private function __construct(private Context $context) {
		$this->workspace=$context->workspace('permission-check');
	}

	public static function open(Context $context): self {
		return new self($context);
	}

	/** @param array<string|int,mixed> $document */
	public function jsonDocument(string $name, array $document): string {
		return $this->workspace->file(
			$name.'.json',
			json_encode($document, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL
		);
	}

	public function invalidJsonDocument(string $name='invalid'): string {
		return $this->workspace->file($name.'.json', '{not-json');
	}

	public function reportPath(string $name='report'): string {
		return $this->workspace->path('reports/'.$name.'.json');
	}

	public function path(string $relative): string {
		return $this->workspace->path($relative);
	}

	/** @return array<string,mixed> */
	public function report(string $name='report'): array {
		$source=file_get_contents($this->reportPath($name));
		if(!is_string($source)){
			throw new RuntimeException('Permission checker did not write the expected report.');
		}
		$decoded=json_decode($source, true, 512, JSON_THROW_ON_ERROR);
		if(!is_array($decoded)){
			throw new RuntimeException('Permission checker report must decode to an array.');
		}
		return $decoded;
	}

	/** @param list<string> $arguments @param array<string,mixed> $runtime */
	public function run(array $arguments, array $runtime=[]): CapturedExecution {
		$runtime['error'] ??= function(string $message): void {
			$this->errors[]=$message;
		};
		return $this->context->captureOutput(
			fn(): int=>dp_permission_check_run(['permission_check.php', ...$arguments], $runtime)
		);
	}

	/** @param list<string> $arguments */
	public function dispatch(array $arguments): CapturedExecution {
		return $this->context->captureOutput(function() use ($arguments): ?int {
			return dp_permission_check_entrypoint(['permission_check.php', ...$arguments], true, [
				'error'=>function(string $message): void {
					$this->errors[]=$message;
				},
				'terminate'=>function(int $status): void {
					$this->terminations[]=$status;
				},
			]);
		});
	}

	/** @return list<string> */
	public function errors(): array {
		return $this->errors;
	}

	/** @return list<int> */
	public function terminations(): array {
		return $this->terminations;
	}
}
