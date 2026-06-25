<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Immutable catalog of Dataphyre applications discovered for a project.
 *
 * The catalog keeps application entries keyed by stable application id, sorted
 * by key, and exposed through countable, iterable, array, and JSON surfaces.
 * Invalid constructor entries are ignored rather than retained, so consumers
 * can trust `all()`, iteration, and serialization to contain only
 * `Application` objects.
 */
final class ApplicationCatalog implements Countable, IteratorAggregate, \JsonSerializable {

	/** @var array<string, Application> Applications keyed by normalized catalog key. */
	private readonly array $entries;

	/** @var array{project_root:?string, entries:array<int, array<string, mixed>>}|null */
	private ?array $arrayPayload=null;

	/**
	 * Normalizes project-root metadata and application entries.
	 *
	 * Entries that are not `Application` instances are skipped. Explicit array
	 * keys win when non-empty after trimming; otherwise the application's own
	 * id is used. The final map is sorted by key to make iteration and JSON
	 * output deterministic across discovery runs.
	 *
	 * @param ?string $projectRoot Filesystem root associated with the discovered applications, when known.
	 * @param array<int|string, mixed> $entries Candidate application entries keyed by id or discovery order.
	 */
	public function __construct(
		private readonly ?string $projectRoot=null,
		array $entries=[]
	){
		$normalized=[];
		foreach($entries as $key=>$entry){
			if(!$entry instanceof Application){
				continue;
			}
			$normalized[trim((string)($key ?: $entry->id)) ?: $entry->id]=$entry;
		}
		ksort($normalized);
		$this->entries=$normalized;
	}

	/**
	 * Returns the filesystem root used when the catalog was built.
	 *
	 * @return ?string Project root path, or `null` when the catalog is not tied to a specific root.
	 */
	public function projectRoot(): ?string {
		return $this->projectRoot;
	}

	/**
	 * Returns every application in catalog order.
	 *
	 * @return array<int, Application> Applications sorted by their normalized catalog keys.
	 */
	public function all(): array {
		return array_values($this->entries);
	}

	/**
	 * Returns the normalized application keys present in the catalog.
	 *
	 * @return array<int, string> Sorted application ids or explicit catalog keys.
	 */
	public function names(): array {
		return array_keys($this->entries);
	}

	/**
	 * Returns the first application in deterministic catalog order.
	 *
	 * @return ?Application First application, or `null` when the catalog is empty.
	 */
	public function first(): ?Application {
		foreach($this->entries as $entry){
			return $entry;
		}
		return null;
	}

	/**
	 * Looks up an application by normalized catalog key.
	 *
	 * Blank lookup ids are rejected before accessing the map, which avoids
	 * accidentally treating an empty string as a valid application key.
	 *
	 * @param string $applicationId Application id or explicit catalog key.
	 * @return ?Application Matching application, or `null` when the key is blank or unknown.
	 */
	public function get(string $applicationId): ?Application {
		$applicationId=trim($applicationId);
		return $applicationId!=='' ? ($this->entries[$applicationId] ?? null) : null;
	}

	/**
	 * Checks whether the catalog contains an application key.
	 *
	 *
	 * @param string $applicationId Application id or explicit catalog key.
	 * @return bool `true` when `get()` resolves an `Application`.
	 */
	public function has(string $applicationId): bool {
		return $this->get($applicationId) instanceof Application;
	}

	/**
	 * Counts valid applications in the catalog.
	 *
	 * @return int Number of retained `Application` entries.
	 */
	public function count(): int {
		return count($this->entries);
	}

	/**
	 * Returns an iterator over applications in catalog order.
	 *
	 *
	 * @return Traversable<int, Application> Iterator over `all()` so foreach sees application values, not internal keys.
	 */
	public function getIterator(): Traversable {
		return new ArrayIterator($this->all());
	}

	/**
	 * Serializes the catalog into project metadata and application payloads.
	 *
	 * @return array{project_root:?string, entries:array<int, array<string, mixed>>} Stable catalog payload for diagnostics and APIs.
	 */
	public function toArray(): array {
		if($this->arrayPayload!==null){
			return $this->arrayPayload;
		}
		$entries=[];
		foreach($this->entries as $application){
			$entries[]=$application->toArray();
		}
		return $this->arrayPayload=[
			'project_root'=>$this->projectRoot,
			'entries'=>$entries,
		];
	}

	/**
	 * Exposes the catalog payload to json_encode().
	 *
	 * @return array{project_root:?string, entries:array<int, array<string, mixed>>} Serializable catalog payload.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
