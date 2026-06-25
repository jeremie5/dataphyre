<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

use Dataphyre\FulltextEngine\Contracts\DocumentResolver;

/**
 * Fluent handle for one configured fulltext index.
 *
 * An Index does not own storage directly; it binds an index name to a SearchManager
 * and forwards every read, write, resolver, and lifecycle operation through that
 * manager. The handle is intentionally lightweight so callers can pass it around as
 * a stable capability while the manager keeps the SQL schema, search algorithms,
 * resolver registry, and hydration rules centralized.
 */
final class Index {

	/**
	 * Creates a named index handle backed by the shared search manager.
	 *
	 * The constructor performs no I/O and does not validate that the physical index
	 * exists. Existence checks and creation remain explicit so boot code can choose
	 * between lazy references, idempotent provisioning, and hard failure policies.
	 *
	 * @param readonly SearchManager $manager Manager that owns storage, resolver, and search execution.
	 * @param readonly string $name Logical index name used for every delegated operation.
	 */
	public function __construct(
		private readonly SearchManager $manager,
		private readonly string $name
	){}

	/**
	 * Returns the logical index name bound to this handle.
	 *
	 * @return string Name passed to the manager for schema, search, and mutation calls.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Checks whether the backing fulltext index is currently registered or present.
	 *
	 * @return bool True when the manager can resolve the index definition for this name.
	 */
	public function exists(): bool {
		return $this->manager->hasIndex($this->name);
	}

	/**
	 * Returns the resolved index definition, if the manager knows this index.
	 *
	 * The definition describes the primary key, language defaults, storage type, and
	 * searchable columns that downstream inspection tools need
	 * without executing a search.
	 *
	 * @return IndexDefinition|null Immutable definition metadata, or null when the index is unknown.
	 */
	public function definition(): ?IndexDefinition {
		return $this->manager->definition($this->name);
	}

	/**
	 * Returns the document resolver currently registered for this index.
	 *
	 * @return DocumentResolver|null Resolver used to hydrate result primary keys into domain records.
	 */
	public function resolver(): ?DocumentResolver {
		return $this->manager->resolver($this->name);
	}

	/**
	 * Registers or replaces the resolver used to hydrate this index's search results.
	 *
	 * Accepted resolver forms are interpreted by SearchManager and may include a
	 * DocumentResolver instance, a callable, or a structured resolver descriptor.
	 * The change affects subsequent hydration calls for this index name.
	 *
	 * @param mixed $resolver Resolver strategy or descriptor accepted by the manager.
	 * @return self Same handle for fluent configuration.
	 */
	public function extendResolver(mixed $resolver): self {
		$this->manager->extendResolver($this->name, $resolver);
		return $this;
	}

	/**
	 * Configures hydration from a SQL table keyed by the indexed primary key.
	 *
	 * The generated descriptor is stored in the manager resolver registry. Columns
	 * may be '*' for complete rows, a single column, or a list of columns. Caching
	 * policy is passed through unchanged so the resolver layer can decide whether
	 * to reuse hydrated rows across search calls.
	 *
	 * @param string $table Table name containing source documents.
	 * @param string $primaryKey Column that matches the fulltext index primary key.
	 * @param array|string $columns Columns selected during hydration.
	 * @param bool|array|string|null $caching Cache policy understood by the table resolver.
	 * @param mixed $mapper Optional row mapper applied after hydration.
	 * @return self Same handle for fluent configuration.
	 */
	public function useTableResolver(
		string $table,
		string $primaryKey='id',
		array|string $columns='*',
		bool|array|string|null $caching=false,
		mixed $mapper=null
	): self {
		$this->manager->extendResolver($this->name, [
			'driver'=>'table',
			'table'=>$table,
			'primary_key'=>$primaryKey,
			'columns'=>$columns,
			'caching'=>$caching,
			'mapper'=>$mapper,
		]);
		return $this;
	}

	/**
	 * Configures hydration through a repository abstraction.
	 *
	 * Repository descriptors are useful when result documents need domain-level
	 * loading rules, permission-aware scopes, or mapper logic that should not live
	 * in the index itself. The descriptor is deferred to SearchManager so framework
	 * integrations can resolve repository names at runtime.
	 *
	 * @param string $repository Repository service or class name used for hydration.
	 * @param string $primaryKey Field that matches the fulltext index primary key.
	 * @param array|string $columns Fields requested from the repository.
	 * @param bool|array|string|null $caching Cache policy passed to the repository resolver.
	 * @param mixed $mapper Optional mapper applied to each hydrated document.
	 * @return self Same handle for fluent configuration.
	 */
	public function useRepositoryResolver(
		string $repository,
		string $primaryKey='id',
		array|string $columns='*',
		bool|array|string|null $caching=false,
		mixed $mapper=null
	): self {
		$this->manager->extendResolver($this->name, [
			'driver'=>'repository',
			'repository'=>$repository,
			'primary_key'=>$primaryKey,
			'columns'=>$columns,
			'caching'=>$caching,
			'mapper'=>$mapper,
		]);
		return $this;
	}

	/**
	 * Starts a query builder scoped to this index.
	 *
	 * @return Query Builder that will execute against the same manager and index name.
	 */
	public function query(): Query {
		return new Query($this->manager, $this->name);
	}

	/**
	 * Executes a normalized fulltext search and wraps the ranked matches.
	 *
	 * Criteria shape is manager-defined and may include column text, filter terms,
	 * or algorithm-specific options. The returned object preserves raw match rows,
	 * scoring metadata, and the index context needed for later hydration.
	 *
	 * @param array<string,string|int|float|bool|list<string>|null> $criteria Search terms and filters understood by SearchManager.
	 * @param string|null $language Language override for stemming/tokenization.
	 * @param int|null $maxResults Maximum result count, or manager default when null.
	 * @param bool|null $booleanMode Boolean-mode override for compatible algorithms.
	 * @param float|null $threshold Minimum score threshold, or manager default when null.
	 * @param string|null $forcedAlgorithms Comma-separated or named algorithm override.
	 * @return SearchResults Ranked results plus metadata for this index.
	 */
	public function search(
		array $criteria,
		?string $language=null,
		?int $maxResults=null,
		?bool $booleanMode=null,
		?float $threshold=null,
		?string $forcedAlgorithms=null
	): SearchResults {
		return $this->manager->search($this->name, $criteria, $language, $maxResults, $booleanMode, $threshold, $forcedAlgorithms);
	}

	/**
	 * Hydrates search result primary keys into application documents.
	 *
	 * When no resolver override is supplied, the manager uses the resolver currently
	 * registered for the index. Supplying an override is useful for one-off exports
	 * or tests that need a different document loading policy without replacing the
	 * shared resolver.
	 *
	 * @param SearchResults $results Results returned by this module.
	 * @param mixed $resolver Optional resolver override accepted by SearchManager.
	 * @return HydratedSearchResults Results paired with hydrated documents.
	 */
	public function hydrate(SearchResults $results, mixed $resolver=null): HydratedSearchResults {
		return $this->manager->hydrate($results, $resolver);
	}

	/**
	 * Executes a search and returns the manager's raw transport response.
	 *
	 * This method is for diagnostics and integrations that need the exact array
	 * shape produced by SearchManager instead of the SearchResults wrapper. A false
	 * return indicates the manager could not execute the search.
	 *
	 * @param array<string,string|int|float|bool|list<string>|null> $criteria Search terms and filters understood by SearchManager.
	 * @param string|null $language Language override for stemming/tokenization.
	 * @param int|null $maxResults Maximum result count, or manager default when null.
	 * @param bool|null $booleanMode Boolean-mode override for compatible algorithms.
	 * @param float|null $threshold Minimum score threshold, or manager default when null.
	 * @param string|null $forcedAlgorithms Comma-separated or named algorithm override.
	 * @return array<int|string,mixed>|false Raw match data, or false when execution fails.
	 */
	public function rawSearch(
		array $criteria,
		?string $language=null,
		?int $maxResults=null,
		?bool $booleanMode=null,
		?float $threshold=null,
		?string $forcedAlgorithms=null
	): bool|array {
		return $this->manager->rawSearch($this->name, $criteria, $language, $maxResults, $booleanMode, $threshold, $forcedAlgorithms);
	}

	/**
	 * Creates the physical index storage and definition for this name.
	 *
	 * Creation is a write-side lifecycle operation and may touch SQL schema,
	 * metadata rows, or engine-specific index structures depending on the manager
	 * configuration.
	 *
	 * @param string $primaryKeyColumnName Source column used as the document identifier.
	 * @param string|null $type Optional engine/storage type override.
	 * @param string|null $language Default language used when indexing documents.
	 * @return bool True when the manager creates the index successfully.
	 */
	public function create(string $primaryKeyColumnName, ?string $type=null, ?string $language=null): bool {
		return $this->manager->createIndex($this->name, $primaryKeyColumnName, $type, $language);
	}

	/**
	 * Ensures this index exists without requiring callers to preflight existence.
	 *
	 * The manager decides whether an existing compatible index is acceptable or
	 * whether provisioning work is required. This is the preferred boot-time call
	 * for modules that can safely provision their own search tables.
	 *
	 * @param string $primaryKeyColumnName Source column used as the document identifier.
	 * @param string|null $type Optional engine/storage type override.
	 * @param string|null $language Default language used when indexing documents.
	 * @return bool True when the index already exists or is created successfully.
	 */
	public function ensure(string $primaryKeyColumnName, ?string $type=null, ?string $language=null): bool {
		return $this->manager->ensureIndex($this->name, $primaryKeyColumnName, $type, $language);
	}

	/**
	 * Removes this index and its manager-owned metadata.
	 *
	 * @return bool True when the manager deletes or drops the index successfully.
	 */
	public function delete(): bool {
		return $this->manager->deleteIndex($this->name);
	}

	/**
	 * Adds one document to this fulltext index.
	 *
	 * Values must include the primary key expected by the index definition plus the
	 * searchable fields managed by the configured engine. The manager owns token
	 * generation, language handling, and storage writes.
	 *
	 * @param array<string,scalar|null> $values Document fields to index, including the configured primary key.
	 * @param string|null $language Language override for this document.
	 * @return bool True when the document is written to the index.
	 */
	public function add(array $values, ?string $language=null): bool {
		return $this->manager->add($this->name, $values, $language);
	}

	/**
	 * Replaces the indexed representation for one document.
	 *
	 * The values array must identify the document using the configured primary key.
	 * Manager implementations may perform an upsert, delete-and-add, or engine-native
	 * update depending on the selected backend.
	 *
	 * @param array<string,scalar|null> $values Document fields to re-index, including the configured primary key.
	 * @param string|null $language Language override for this document.
	 * @return bool True when the indexed document is updated.
	 */
	public function update(array $values, ?string $language=null): bool {
		return $this->manager->update($this->name, $values, $language);
	}

	/**
	 * Removes one document from this index by primary key.
	 *
	 * @param string $primaryKeyValue Document identifier stored in the fulltext index.
	 * @return bool True when the document is removed from the index.
	 */
	public function remove(string $primaryKeyValue): bool {
		return $this->manager->remove($this->name, $primaryKeyValue);
	}
}
