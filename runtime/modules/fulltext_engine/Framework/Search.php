<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

use Dataphyre\FulltextEngine\Contracts\DocumentResolver;

final class Search {

	public static function manager(): SearchManager {
		return SearchManager::instance();
	}

	public static function flush(): void {
		SearchManager::flush();
	}

	public static function index(string $name): Index {
		return self::manager()->index($name);
	}

	public static function query(string $index_name): Query {
		return self::manager()->query($index_name);
	}

	public static function definitions(): array {
		return self::manager()->definitions();
	}

	public static function definition(string $index_name): ?IndexDefinition {
		return self::manager()->definition($index_name);
	}

	public static function hasIndex(string $index_name): bool {
		return self::manager()->hasIndex($index_name);
	}

	public static function resolver(string $index_name): ?DocumentResolver {
		return self::manager()->resolver($index_name);
	}

	public static function extendResolver(string $index_name, mixed $resolver): void {
		self::manager()->extendResolver($index_name, $resolver);
	}

	public static function useTableResolver(
		string $index_name,
		string $table,
		string $primary_key='id',
		array|string $columns='*',
		bool|array|string|null $caching=false,
		mixed $mapper=null
	): void {
		self::extendResolver($index_name, [
			'driver'=>'table',
			'table'=>$table,
			'primary_key'=>$primary_key,
			'columns'=>$columns,
			'caching'=>$caching,
			'mapper'=>$mapper,
		]);
	}

	public static function useRepositoryResolver(
		string $index_name,
		string $repository,
		string $primary_key='id',
		array|string $columns='*',
		bool|array|string|null $caching=false,
		mixed $mapper=null
	): void {
		self::extendResolver($index_name, [
			'driver'=>'repository',
			'repository'=>$repository,
			'primary_key'=>$primary_key,
			'columns'=>$columns,
			'caching'=>$caching,
			'mapper'=>$mapper,
		]);
	}

	public static function search(
		string $index_name,
		array $criteria,
		?string $language=null,
		?int $max_results=null,
		?bool $boolean_mode=null,
		?float $threshold=null,
		?string $forced_algorithms=null
	): SearchResults {
		return self::manager()->search($index_name, $criteria, $language, $max_results, $boolean_mode, $threshold, $forced_algorithms);
	}

	public static function rawSearch(
		string $index_name,
		array $criteria,
		?string $language=null,
		?int $max_results=null,
		?bool $boolean_mode=null,
		?float $threshold=null,
		?string $forced_algorithms=null
	): bool|array {
		return self::manager()->rawSearch($index_name, $criteria, $language, $max_results, $boolean_mode, $threshold, $forced_algorithms);
	}

	public static function hydrate(SearchResults $results, mixed $resolver=null): HydratedSearchResults {
		return self::manager()->hydrate($results, $resolver);
	}

	public static function createIndex(
		string $index_name,
		string $primary_key_column_name,
		?string $type=null,
		?string $language=null
	): bool {
		return self::manager()->createIndex($index_name, $primary_key_column_name, $type, $language);
	}

	public static function deleteIndex(string $index_name): bool {
		return self::manager()->deleteIndex($index_name);
	}

	public static function ensureIndex(
		string $index_name,
		string $primary_key_column_name,
		?string $type=null,
		?string $language=null
	): bool {
		return self::manager()->ensureIndex($index_name, $primary_key_column_name, $type, $language);
	}

	public static function sync(array $definitions, bool $prune_missing=false): IndexSyncReport {
		return self::manager()->sync($definitions, $prune_missing);
	}

	public static function syncConfigured(bool $prune_missing=false): IndexSyncReport {
		return self::manager()->syncConfigured($prune_missing);
	}

	public static function add(string $index_name, array $values, ?string $language=null): bool {
		return self::manager()->add($index_name, $values, $language);
	}

	public static function update(string $index_name, array $values, ?string $language=null): bool {
		return self::manager()->update($index_name, $values, $language);
	}

	public static function remove(string $index_name, string $primary_key_value): bool {
		return self::manager()->remove($index_name, $primary_key_value);
	}

	public static function tokenize(string $text, ?string $language=null): array {
		return self::manager()->tokenize($text, $language);
	}

	public static function removeStopwords(string $query, ?string $language=null): string {
		return self::manager()->removeStopwords($query, $language);
	}

	public static function applyStemming(string $query, ?string $language=null): string {
		return self::manager()->applyStemming($query, $language);
	}

	public static function score(
		string $index_value,
		string $search_value,
		?string $search_value_raw=null,
		?string $language=null,
		?bool $boolean_mode=null,
		?string $forced_algorithms=null
	): float {
		return self::manager()->score($index_value, $search_value, $search_value_raw, $language, $boolean_mode, $forced_algorithms);
	}
}
