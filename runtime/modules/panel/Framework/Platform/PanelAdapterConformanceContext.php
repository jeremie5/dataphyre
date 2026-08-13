<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Assertion/evidence collector passed to adapter conformance probes. */
final class PanelAdapterConformanceContext {
	/** @var list<array<string,mixed>> */ private array $issues=[];
	/** @var array<string,mixed> */ private array $evidence=[];
	private int $assertions=0;
	private ?string $skipReason=null;

	/** @param array<string,mixed> $options @param array<string,mixed> $capabilities */
	public function __construct(private readonly array $options=[], private readonly array $capabilities=[]){ }

	public function option(string $key, mixed $default=null): mixed { return array_key_exists($key,$this->options) ? $this->options[$key] : $default; }
	/** @return array<string,mixed> */ public function capabilities(): array { return $this->capabilities; }
	public function assertions(): int { return $this->assertions; }
	/** @return list<array<string,mixed>> */ public function issues(): array { return $this->issues; }
	/** @return array<string,mixed> */ public function evidence(): array { return $this->evidence; }
	public function skipped(): bool { return $this->skipReason!==null; }
	public function skipReason(): ?string { return $this->skipReason; }

	public function skip(string $reason): void { $this->skipReason=self::message($reason, 'Probe skipped.'); }

	public function evidenceValue(string $key, mixed $value): self {
		$key=Resource::normalizeName($key);
		if($key===''){ throw new \InvalidArgumentException('Adapter conformance evidence requires a key.'); }
		if(count($this->evidence)>=50 && !array_key_exists($key, $this->evidence)){ throw new \LengthException('Adapter conformance evidence supports at most 50 entries.'); }
		$this->evidence[$key]=self::safe($value);
		return $this;
	}

	public function check(bool $condition, string $code, string $message, mixed $expected=true, mixed $actual=null): self {
		$this->assertions++;
		if(!$condition){
			if(count($this->issues)>=100){ return $this; }
			$this->issues[]=['code'=>self::code($code), 'message'=>self::message($message, 'Adapter contract failed.'), 'expected'=>self::safe($expected), 'actual'=>self::safe($actual)];
		}
		return $this;
	}

	public function same(mixed $expected, mixed $actual, string $code='not_same', string $message='Values differ.'): self { return $this->check($expected===$actual, $code, $message, $expected, $actual); }
	public function truthy(mixed $actual, string $code='not_truthy', string $message='Value is not true.'): self { return $this->check($actual===true, $code, $message, true, $actual); }
	public function instanceOf(string $class, mixed $actual, string $code='wrong_type', string $message='Value has the wrong type.'): self { return $this->check($actual instanceof $class, $code, $message, $class, is_object($actual)?$actual::class:get_debug_type($actual)); }

	/** @param class-string<\Throwable>|list<class-string<\Throwable>> $classes */
	public function throws(callable $callback, string|array $classes=\Throwable::class, string $code='exception_missing'): self {
		$classes=is_array($classes) ? array_values($classes) : [$classes];
		if($classes===[] || array_filter($classes,static fn(mixed $class):bool=>!is_string($class) || (!is_a($class,\Throwable::class,true) && $class!==\Throwable::class))!==[]){ throw new \InvalidArgumentException('Expected exception types must implement Throwable.'); }
		try{ $callback(); }
		catch(\Throwable $exception){
			$matches=false; foreach($classes as $class){ if(is_a($exception, $class)){ $matches=true; break; } }
			return $this->check($matches, 'exception_type', 'Probe threw an unexpected exception type.', $classes, $exception::class);
		}
		return $this->check(false, $code, 'Expected adapter operation to throw.', $classes, null);
	}

	/** Adds a sanitized exception issue without exposing a stack, payload, or credential. */
	public function exception(\Throwable $exception): void {
		if(count($this->issues)>=100){ return; }
		$this->issues[]=['code'=>'exception', 'message'=>'Adapter probe threw '.$exception::class.'.', 'exception'=>$exception::class, 'detail'=>self::safe($exception->getMessage())];
	}

	private static function code(string $value): string { return Resource::normalizeName($value) ?: 'contract_failed'; }
	private static function message(string $value, string $fallback): string {
		$value=trim($value); $value=$value!=='' ? $value : $fallback;
		return function_exists('mb_substr') ? mb_substr($value, 0, 500, 'UTF-8') : substr($value, 0, 500);
	}
	private static function safe(mixed $value): mixed { return PanelSensitiveDataSanitizer::sanitize($value, ['max_depth'=>8,'max_items'=>100,'max_string_bytes'=>500]); }
}
