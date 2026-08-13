<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\AssertionContext;

/**
 * Loads a PHP bootstrap and describes the process-local contract it publishes.
 *
 * Bootstrap tests should state their observable lifecycle promises instead of
 * repeating require calls and raw defined()/class_exists() transforms.
 */
final class PhpBootstrapProbe {

	private int $load_count=0;

	public function __construct(private AssertionContext $context, private string $path) {
		$this->path=trim($this->path);
		if($this->path===''||!is_file($this->path)){
			throw new \InvalidArgumentException('PHP bootstrap file is unavailable: '.$this->path);
		}
		$resolved=realpath($this->path);
		$this->path=is_string($resolved)&&$resolved!=='' ? $resolved : $this->path;
		$this->load();
	}

	/** Loads the bootstrap again so an idempotency contract can be expressed. */
	public function reloadsSafely(): self {
		$this->load();
		$this->context->isTrue($this->load_count>=2, 'PHP bootstrap should support an explicit second load.');
		return $this;
	}

	/** Asserts that the bootstrap publishes one constant with its expected value. */
	public function defines(string $constant, mixed $expected=true): self {
		$constant=trim($constant);
		$this->context->isTrue($constant!==''&&defined($constant), "Bootstrap should define {$constant}.");
		$this->context->same($expected, constant($constant), "Bootstrap constant {$constant} should match its contract value.");
		return $this;
	}

	/** Asserts that every type was loaded by the bootstrap without autoloading it here. */
	public function providesTypes(string ...$types): self {
		foreach($types as $type){
			$type=ltrim(trim($type), '\\');
			$available=$type!==''&&(
				class_exists($type, false)
				||interface_exists($type, false)
				||trait_exists($type, false)
				||(function_exists('enum_exists')&&enum_exists($type, false))
			);
			$this->context->isTrue($available, "Bootstrap should provide type {$type} without deferred autoloading.");
		}
		return $this;
	}

	public function path(): string { return $this->path; }
	public function loadCount(): int { return $this->load_count; }

	private function load(): void {
		$path=$this->path;
		(static function()use($path): void { require $path; })();
		$this->load_count++;
	}
}
