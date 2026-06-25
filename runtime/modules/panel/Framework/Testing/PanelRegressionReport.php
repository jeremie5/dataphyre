<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Represents the immutable result summary produced by a panel regression suite run.
 *
 * Reports keep the normalized suite name, ordered check rows, non-negative duration, and caller metadata together for diagnostics. The object does not re-run checks or inspect the panel; it only derives counters, health flags, text summaries, and array serialization from the captured result rows.
 */
final class PanelRegressionReport implements \JsonSerializable {

	private string $name;
	private array $results;
	private float $durationMs;
	private array $meta;

	/**
	 * Captures a completed suite run as a normalized report value.
	 *
	 * The report normalizes the suite name with Resource rules, reindexes result rows to preserve deterministic JSON ordering, clamps negative durations to zero, and stores metadata without interpretation. Each result row is expected to use the suite runner shape: name, status, message, duration_ms, index, details, and meta.
	 *
	 * @param string $name Suite name associated with the run.
	 * @param list<array{name?:string,status?:string,message?:string,duration_ms?:int|float,index?:int,details?:array<string,mixed>,meta?:array<string,mixed>}> $results Ordered result rows emitted by PanelRegressionSuite::run().
	 * @param float $durationMs Total suite runtime in milliseconds.
	 * @param array<string,mixed> $meta Report metadata such as environment, release, or caller-supplied tags.
	 */
	public function __construct(string $name, array $results, float $durationMs=0.0, array $meta=[]) {
		$this->name=Resource::normalizeName($name) ?: 'regression_suite';
		$this->results=array_values($results);
		$this->durationMs=max(0.0, $durationMs);
		$this->meta=$meta;
	}

	/**
	 * Reads the normalized suite name associated with this report.
	 *
	 * @return string Stable report identifier derived from the suite name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Reads the ordered check result rows captured for this run.
	 *
	 * Rows are returned as stored so diagnostics can inspect runner-specific details without losing shape. The constructor reindexes the outer list, but it does not validate or rewrite row data.
	 *
	 * @return array<int,array<string,mixed>> Ordered regression result rows.
	 */
	public function results(): array {
		return $this->results;
	}

	/**
	 * Counts every captured check row regardless of status.
	 *
	 *
	 * @return int Number of result rows in this report.
	 */
	public function total(): int {
		return count($this->results);
	}

	/**
	 * Counts result rows whose status is passed.
	 *
	 * Unknown statuses are ignored rather than treated as failures, preserving the exact runner output while keeping this counter focused on successful checks.
	 *
	 * @return int Number of result rows marked passed.
	 */
	public function passed(): int {
		return count(array_filter($this->results, static fn(array $result): bool => ($result['status'] ?? '')==='passed'));
	}

	/**
	 * Counts result rows whose status is failed.
	 *
	 * Failed rows usually contain exception diagnostics or assertion messages captured by the suite runner. Unknown statuses are ignored by this counter and remain visible through results().
	 *
	 * @return int Number of result rows marked failed.
	 */
	public function failed(): int {
		return count(array_filter($this->results, static fn(array $result): bool => ($result['status'] ?? '')==='failed'));
	}

	/**
	 * Counts result rows whose status is skipped.
	 *
	 * Skipped rows represent declared scenarios that were intentionally not executed. They do not make the report unhealthy, but they are exposed for release gates that require full coverage.
	 *
	 * @return int Number of result rows marked skipped.
	 */
	public function skipped(): int {
		return count(array_filter($this->results, static fn(array $result): bool => ($result['status'] ?? '')==='skipped'));
	}

	/**
	 * Indicates whether the report contains no failed checks.
	 *
	 * A report with only passed and skipped rows is considered ok. Callers that require zero skipped checks should combine ok() with hasSkipped() or inspect skipped().
	 *
	 * @return bool True when failed() is zero.
	 */
	public function ok(): bool {
		return $this->failed()===0;
	}

	/**
	 * Indicates whether any checks were recorded as skipped.
	 *
	 *
	 * @return bool True when skipped() is greater than zero.
	 */
	public function hasSkipped(): bool {
		return $this->skipped()>0;
	}

	/**
	 * Reads the total captured suite runtime in milliseconds.
	 *
	 * The value is clamped to zero at construction and remains unrounded here so callers can decide their preferred display precision.
	 *
	 * @return float Non-negative runtime in milliseconds.
	 */
	public function durationMs(): float {
		return $this->durationMs;
	}

	/**
	 * Returns a compact human-readable suite summary.
	 *
	 * @return string Summary string containing total, passed, failed, skipped, and duration values.
	 */
	public function summary(): string {
		return $this->total().' checks, '.$this->passed().' passed, '.$this->failed().' failed, '.$this->skipped().' skipped in '.round($this->durationMs, 3).'ms';
	}

	/**
	 * Returns the captured report summary for diagnostics.
	 *
	 * @return array{type:string,name:string,ok:bool,total:int,passed:int,failed:int,skipped:int,duration_ms:float,results:list<array<string,mixed>>,meta:array<string,mixed>} Regression report summary.
	 */
	public function toArray(): array {
		return [
			'type'=>'panel_regression_report',
			'name'=>$this->name,
			'ok'=>$this->ok(),
			'total'=>$this->total(),
			'passed'=>$this->passed(),
			'failed'=>$this->failed(),
			'skipped'=>$this->skipped(),
			'duration_ms'=>round($this->durationMs, 3),
			'results'=>$this->results,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Serializes the regression report for JSON diagnostics.
	 *
	 * @return array{type:string,name:string,ok:bool,total:int,passed:int,failed:int,skipped:int,duration_ms:float,results:list<array<string,mixed>>,meta:array<string,mixed>} Regression report summary.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
