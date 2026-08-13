<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace dataphyre;

/**
 * Deterministic in-process kernel seam for framework fulltext coverage.
 * Each code test runs in an isolated worker, so this never replaces a live engine.
 */
final class fulltext_engine {
	public static array $definitions=[
		'products'=>[
			'name'=>'products', 'type'=>'json',
			'primary_key_column_name'=>'id', 'language'=>'en',
		],
		'invalid'=>['name'=>''],
	];
	public static array $documents=[
		'products'=>[
			'1'=>['id'=>'1', 'name'=>'Red shoe'],
			'2'=>['id'=>'2', 'name'=>'Blue shoe'],
		],
	];
	public static array $createFailures=[];
	public static array $deleteFailures=[];

	public static function get_index_definitions(): array { return array_values(self::$definitions); }
	public static function get_index_definition(string $name): array|false { return self::$definitions[$name] ?? false; }
	public static function index_exists(string $name): bool { return isset(self::$definitions[$name]); }
	public static function create_index(string $name, string $primary, string $type='json', string $language='en'): bool {
		if(trim($name)==='' || trim($primary)==='' || in_array($name, self::$createFailures, true)){ return false; }
		self::$definitions[$name]=[
			'name'=>$name, 'type'=>$type,
			'primary_key_column_name'=>$primary, 'language'=>$language,
		];
		self::$documents[$name]=[];
		return true;
	}
	public static function delete_index(string $name): bool {
		if(!isset(self::$definitions[$name]) || in_array($name, self::$deleteFailures, true)){ return false; }
		unset(self::$definitions[$name], self::$documents[$name]);
		return true;
	}
	public static function add_to_index(string $name, array $values, string $language='en'): bool {
		$definition=self::$definitions[$name] ?? null;
		$key=is_array($definition) ? ($definition['primary_key_column_name'] ?? 'id') : 'id';
		if(!isset($values[$key])){ return false; }
		self::$documents[$name][(string)$values[$key]]=$values;
		return true;
	}
	public static function update_in_index(string $name, array $values, string $language='en'): bool {
		return self::add_to_index($name, $values, $language);
	}
	public static function remove_from_index(string $name, string $id): bool {
		$exists=isset(self::$documents[$name][$id]);
		unset(self::$documents[$name][$id]);
		return $exists;
	}
	public static function find_in_index(
		string $name, array $criteria, string $language='en', bool $boolean=true,
		int $limit=50, float $threshold=0.3, string $algorithms=''
	): array {
		$results=[];
		foreach(array_slice(self::$documents[$name] ?? [], 0, max(0, $limit), true) as $id=>$document){
			$results[]=[(string)$id=>0.9];
		}
		return ['results'=>$results, 'count'=>count($results), 'certainty'=>0.9, 'time'=>0.001];
	}
	public static function search(
		string $name, array $criteria, string $language='en', int $limit=50,
		bool $boolean=true, float $threshold=0.3, string $algorithms=''
	): array {
		return self::find_in_index($name, $criteria, $language, $boolean, $limit, $threshold, $algorithms);
	}
	public static function tokenize(string $text, string $language='en'): array {
		return array_values(array_filter(preg_split('/\s+/', strtolower(trim($text))) ?: []));
	}
	public static function remove_stopwords(string $query, string $language='en'): string {
		return trim(preg_replace('/\b(the|and)\b/i', '', $query) ?? $query);
	}
	public static function apply_stemming(string $query, string $language='en'): string {
		return preg_replace('/ing\b/i', '', $query) ?? $query;
	}
	public static function get_score(
		string $indexValue, string $searchValue, string $searchValueRaw, string $language='en',
		bool $boolean=true, string $algorithms=''
	): float {
		return $indexValue==='' ? 0.0 : 0.75;
	}
}
