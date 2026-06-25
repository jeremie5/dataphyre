<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable summary of a Panel background data operation.
 *
 * PanelDataJobResult is the progress and outcome shape for imports, exports,
 * bulk jobs, and other long-running Panel operations. It normalizes job identity,
 * counters, status, failure details, generated artifacts, event history, and
 * metadata into a JSON-safe result that can be returned to operators or persisted
 * by job runners.
 */
final class PanelDataJobResult implements \JsonSerializable {

	/**
	 * Stores the normalized job counters, state, diagnostics, and outputs.
	 *
	 * The constructor trusts the caller to provide already-normalized values.
	 * Use make() when ingesting loose runner data from queues, files, or command
	 * handlers.
	 *
	 * @param string $id Stable job identifier.
	 * @param string $type Normalized operation type such as import, export, or operation.
	 * @param string $name Normalized job name used by Panel surfaces.
	 * @param string $status Normalized lifecycle status such as pending, running, completed, failed, or completed_with_failures.
	 * @param int $total Total work units expected.
	 * @param int $processed Work units attempted so far.
	 * @param int $succeeded Work units completed successfully.
	 * @param int $failed Work units that failed.
	 * @param list<array<string, mixed>> $failures Failure records suitable for operator diagnostics.
	 * @param list<array<string, mixed>> $artifacts Generated files, links, or result references.
	 * @param list<array<string, mixed>> $events Timeline events emitted by the job.
	 * @param array<string, mixed> $metadata Additional runner or resource metadata.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $type,
		private readonly string $name,
		private readonly string $status,
		private readonly int $total,
		private readonly int $processed,
		private readonly int $succeeded,
		private readonly int $failed,
		private readonly array $failures=[],
		private readonly array $artifacts=[],
		private readonly array $events=[],
		private readonly array $metadata=[]
	){}

	/**
	 * Builds a normalized job result from loose runner data.
	 *
	 * Names and statuses are normalized for stable UI comparisons, counters are
	 * clamped to non-negative integers, and collection fields fall back to empty
	 * arrays when missing or malformed. Failure, artifact, event, and metadata
	 * entries are not recursively sanitized here; runners are responsible for
	 * omitting secrets and oversized diagnostic values before constructing results.
	 *
	 * @param array<string, mixed> $data Raw job result data from a runner or persisted job record.
	 * @return self Normalized Panel data job result.
	 */
	public static function make(array $data): self {
		return new self(
			(string)($data['id'] ?? ''),
			Resource::normalizeName((string)($data['type'] ?? 'operation')) ?: 'operation',
			Resource::normalizeName((string)($data['name'] ?? 'job')) ?: 'job',
			Resource::normalizeName((string)($data['status'] ?? 'pending')) ?: 'pending',
			max(0, (int)($data['total'] ?? 0)),
			max(0, (int)($data['processed'] ?? 0)),
			max(0, (int)($data['succeeded'] ?? 0)),
			max(0, (int)($data['failed'] ?? 0)),
			is_array($data['failures'] ?? null) ? $data['failures'] : [],
			is_array($data['artifacts'] ?? null) ? $data['artifacts'] : [],
			is_array($data['events'] ?? null) ? $data['events'] : [],
			is_array($data['metadata'] ?? null) ? $data['metadata'] : []
		);
	}

	/**
	 * Returns the job identifier.
	 *
	 * @return string Stable job id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Returns the normalized operation type.
	 *
	 * @return string Job type.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Returns the normalized job name.
	 *
	 * @return string Job name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the normalized lifecycle status.
	 *
	 * @return string Job status.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Returns total work units expected by the job.
	 *
	 * @return int Non-negative total count.
	 */
	public function total(): int {
		return $this->total;
	}

	/**
	 * Returns work units processed so far.
	 *
	 * @return int Non-negative processed count.
	 */
	public function processed(): int {
		return $this->processed;
	}

	/**
	 * Returns work units completed successfully.
	 *
	 * @return int Non-negative success count.
	 */
	public function succeeded(): int {
		return $this->succeeded;
	}

	/**
	 * Returns work units that failed.
	 *
	 * @return int Non-negative failure count.
	 */
	public function failed(): int {
		return $this->failed;
	}

	/**
	 * Returns failure records captured during the job.
	 *
	 * Records are intentionally left in runner-defined shape so import/export
	 * handlers can attach row numbers, identifiers, validation errors, or exception
	 * summaries without a second translation layer.
	 *
	 * @return list<array<string, mixed>> Failure diagnostics for operator review.
	 */
	public function failures(): array {
		return $this->failures;
	}

	/**
	 * Returns generated job artifacts.
	 *
	 * Artifact descriptors may represent local files, signed URLs, export IDs, or
	 * other references. The object does not check whether those references still
	 * exist.
	 *
	 * @return list<array<string, mixed>> Artifact descriptors such as files, URLs, or export references.
	 */
	public function artifacts(): array {
		return $this->artifacts;
	}

	/**
	 * Returns timeline events emitted by the job.
	 *
	 * Event ordering is preserved from the runner. This value object does not
	 * assign timestamps or collapse duplicate events.
	 *
	 * @return list<array<string, mixed>> Event records in runner order.
	 */
	public function events(): array {
		return $this->events;
	}

	/**
	 * Returns runner and resource metadata attached to the job result.
	 *
	 * Metadata is an extension map for resource name, tenant, filters, storage keys,
	 * or queue identifiers. Consumers should treat it as diagnostic context rather
	 * than authorization input.
	 *
	 * @return array<string, mixed> Metadata map.
	 */
	public function metadata(): array {
		return $this->metadata;
	}

	/**
	 * Returns integer completion percentage for progress indicators.
	 *
	 * Jobs with no known total are treated as complete because there is no
	 * denominator for progress math. Processed values above total are clamped to
	 * 100% for stable renderer output.
	 *
	 * @return int Completion percentage clamped from 0 to 100.
	 */
	public function percent(): int {
		if($this->total<=0){
			return 100;
		}
		return min(100, max(0, (int)floor(($this->processed / $this->total) * 100)));
	}

	/**
	 * Reports whether the job reached an operator-success terminal status.
	 *
	 * completed_with_failures still counts as ok because the job finished and
	 * surfaced per-row failures separately. Non-terminal statuses and hard failures
	 * return false.
	 *
	 * @return bool True for completed or completed_with_failures statuses.
	 */
	public function ok(): bool {
		return in_array($this->status, ['completed', 'completed_with_failures'], true);
	}

	/**
	 * Exports the canonical Panel data job result shape.
	 *
	 * The serialized array includes the derived percent value but no runner object,
	 * queue handle, or persistence connection. It is safe for JSON responses and
	 * snapshot storage once runner-supplied nested diagnostics have been scrubbed.
	 *
	 * @return array{id: string, type: string, name: string, status: string, total: int, processed: int, succeeded: int, failed: int, percent: int, failures: list<array<string, mixed>>, artifacts: list<array<string, mixed>>, events: list<array<string, mixed>>, metadata: array<string, mixed>}
	 */
	public function jsonSerialize(): array {
		return [
			'id'=>$this->id,
			'type'=>$this->type,
			'name'=>$this->name,
			'status'=>$this->status,
			'total'=>$this->total,
			'processed'=>$this->processed,
			'succeeded'=>$this->succeeded,
			'failed'=>$this->failed,
			'percent'=>$this->percent(),
			'failures'=>$this->failures,
			'artifacts'=>$this->artifacts,
			'events'=>$this->events,
			'metadata'=>$this->metadata,
		];
	}
}
