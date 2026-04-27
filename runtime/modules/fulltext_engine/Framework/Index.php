<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

use Dataphyre\FulltextEngine\Contracts\DocumentResolver;

final class Index {

	public function __construct(
		private readonly SearchManager $manager,
		private readonly string $name
	){}

	public function name(): string {
		return $this->name;
	}

	public function exists(): bool {
		return $this->manager->hasIndex($this->name);
	}

	public function definition(): ?IndexDefinition {
		return $this->manager->definition($this->name);
	}

	public function resolver(): ?DocumentResolver {
		return $this->manager->resolver($this->name);
	}

	public function extendResolver(mixed $resolver): self {
		$this->manager->extendResolver($this->name, $resolver);
		return $this;
	}

	public function useTableResolver(
		string $table,
		string $primary_key='id',
		array|string $columns='*',
		bool|array|string|null $caching=false,
		mixed $mapper=null
	): self {
		$this->manager->extendResolver($this->name, [
			'driver'=>'table',
			'table'=>$table,
			'primary_key'=>$primary_key,
			'columns'=>$columns,
			'caching'=>$caching,
			'mapper'=>$mapper,
		]);
		return $this;
	}

	public function useRepositoryResolver(
		string $repository,
		string $primary_key='id',
		array|string $columns='*',
		bool|array|string|null $caching=false,
		mixed $mapper=null
	): self {
		$this->manager->extendResolver($this->name, [
			'driver'=>'repository',
			'repository'=>$repository,
			'primary_key'=>$primary_key,
			'columns'=>$columns,
			'caching'=>$caching,
			'mapper'=>$mapper,
		]);
		return $this;
	}

	public function query(): Query {
		return new Query($this->manager, $this->name);
	}

	public function search(
		array $criteria,
		?string $language=null,
		?int $max_results=null,
		?bool $boolean_mode=null,
		?float $threshold=null,
		?string $forced_algorithms=null
	): SearchResults {
		return $this->manager->search($this->name, $criteria, $language, $max_results, $boolean_mode, $threshold, $forced_algorithms);
	}

	public function hydrate(SearchResults $results, mixed $resolver=null): HydratedSearchResults {
		return $this->manager->hydrate($results, $resolver);
	}

	public function rawSearch(
		array $criteria,
		?string $language=null,
		?int $max_results=null,
		?bool $boolean_mode=null,
		?float $threshold=null,
		?string $forced_algorithms=null
	): bool|array {
		return $this->manager->rawSearch($this->name, $criteria, $language, $max_results, $boolean_mode, $threshold, $forced_algorithms);
	}

	public function create(string $primary_key_column_name, ?string $type=null, ?string $language=null): bool {
		return $this->manager->createIndex($this->name, $primary_key_column_name, $type, $language);
	}

	public function ensure(string $primary_key_column_name, ?string $type=null, ?string $language=null): bool {
		return $this->manager->ensureIndex($this->name, $primary_key_column_name, $type, $language);
	}

	public function delete(): bool {
		return $this->manager->deleteIndex($this->name);
	}

	public function add(array $values, ?string $language=null): bool {
		return $this->manager->add($this->name, $values, $language);
	}

	public function update(array $values, ?string $language=null): bool {
		return $this->manager->update($this->name, $values, $language);
	}

	public function remove(string $primary_key_value): bool {
		return $this->manager->remove($this->name, $primary_key_value);
	}
}
