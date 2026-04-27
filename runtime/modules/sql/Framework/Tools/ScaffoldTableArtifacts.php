<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database\Tools;

use dataphyre\application_definition;
use dataphyre\app_locator;

final class ScaffoldTableArtifacts {

	public static function scaffold(
		string $project_root,
		string $application_name,
		string $entity_name,
		string $table_name,
		string $primary_key,
		array $columns,
		bool $force=false
	): array {
		$project_root=rtrim($project_root, '/\\');
		$application_name=trim($application_name);
		$application_directory=app_locator::locate($project_root, $application_name);
		if($application_directory===null){
			throw new \RuntimeException("Application {$application_name} was not found in any configured application root.");
		}
		$definition=self::load_application_definition($application_name, $application_directory);
		[$framework_namespace, $framework_directory]=self::framework_location($definition);
		$entity_class=self::classify($entity_name);
		if($entity_class===''){
			throw new \RuntimeException('Entity name must contain at least one alphanumeric character.');
		}
		$table_name=self::normalize_sql_identifier($table_name, 'table');
		$primary_key=self::normalize_sql_identifier($primary_key, 'primary key');
		$columns=self::normalize_columns($columns, $primary_key);

		$schema_directory=$framework_directory.'/Schema';
		$repository_directory=$framework_directory.'/Repository';
		$record_directory=$framework_directory.'/Record';

		self::ensure_directory($schema_directory);
		self::ensure_directory($repository_directory);
		self::ensure_directory($record_directory);

		$paths=[
			'schema'=>$schema_directory.'/'.$entity_class.'TableSchema.php',
			'repository'=>$repository_directory.'/'.$entity_class.'Repository.php',
			'record'=>$record_directory.'/'.$entity_class.'Record.php',
		];

		$status=[
			'application'=>$definition->id,
			'entity'=>$entity_class,
			'table'=>$table_name,
			'primary_key'=>$primary_key,
			'columns'=>$columns,
			'framework_namespace'=>$framework_namespace,
			'framework_directory'=>$framework_directory,
			'generated'=>[],
		];

		$status['generated']['schema']=self::write_file(
			$paths['schema'],
			self::schema_source($framework_namespace, $entity_class, $table_name, $primary_key, $columns),
			$force
		);
		$status['generated']['repository']=self::write_file(
			$paths['repository'],
			self::repository_source($framework_namespace, $entity_class),
			$force
		);
		$status['generated']['record']=self::write_file(
			$paths['record'],
			self::record_source($framework_namespace, $entity_class, $columns),
			$force
		);

		return $status;
	}

	private static function load_application_definition(string $application_name, string $application_directory): application_definition {
		$conventional_definition=application_definition::from_conventions($application_name, $application_directory);
		$definition_file=$application_directory.'/app.php';
		if(!is_file($definition_file)){
			return $conventional_definition;
		}
		$definition=require($definition_file);
		if($definition instanceof application_definition){
			return $definition;
		}
		if(is_array($definition)){
			return $conventional_definition->with_overrides($definition);
		}
		throw new \RuntimeException("Application definition must return an array or application_definition: {$definition_file}");
	}

	private static function framework_location(application_definition $definition): array {
		foreach($definition->autoload as $prefix=>$directory){
			$normalized_prefix=trim((string)$prefix, '\\');
			$normalized_directory=rtrim((string)$directory, '/\\');
			if($normalized_prefix==='' || $normalized_directory===''){
				continue;
			}
			if(str_ends_with(strtolower($normalized_prefix), '\\framework')){
				return [$normalized_prefix, $normalized_directory];
			}
		}
		return [
			$definition->id.'\\framework',
			$definition->root_directory.'/framework',
		];
	}

	private static function normalize_columns(array $columns, string $primary_key): array {
		$normalized=[];
		$seen=[];
		foreach($columns as $column){
			$column=self::normalize_sql_identifier((string)$column, 'column');
			if(isset($seen[$column])){
				continue;
			}
			$seen[$column]=true;
			$normalized[]=$column;
		}
		if(!isset($seen[$primary_key])){
			array_unshift($normalized, $primary_key);
		}
		return $normalized;
	}

	private static function normalize_sql_identifier(string $value, string $label): string {
		$value=trim($value);
		if($value==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)!==1){
			throw new \RuntimeException("Invalid {$label} identifier: {$value}");
		}
		return $value;
	}

	private static function classify(string $value): string {
		$value=trim($value);
		if($value===''){
			return '';
		}
		$value=preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $value) ?? $value;
		$parts=preg_split('/[^A-Za-z0-9]+/', $value) ?: [];
		$parts=array_values(array_filter(array_map(
			static fn(string $part): string => trim($part),
			$parts
		), static fn(string $part): bool => $part!==''));
		if($parts===[]){
			return '';
		}
		return implode('', array_map(
			static fn(string $part): string => ucfirst(strtolower($part)),
			$parts
		));
	}

	private static function camelize(string $value): string {
		$class=self::classify($value);
		if($class===''){
			return '';
		}
		return lcfirst($class);
	}

	private static function ensure_directory(string $directory): void {
		if(is_dir($directory)){
			return;
		}
		if(@mkdir($directory, 0777, true)!==true && !is_dir($directory)){
			throw new \RuntimeException("Unable to create directory: {$directory}");
		}
	}

	private static function write_file(string $path, string $contents, bool $force): array {
		$exists=is_file($path);
		if($exists && $force===false){
			throw new \RuntimeException("Refusing to overwrite existing file without --force: {$path}");
		}
		$result=@file_put_contents($path, $contents, LOCK_EX);
		if($result===false){
			throw new \RuntimeException("Unable to write file: {$path}");
		}
		return [
			'path'=>$path,
			'status'=>$exists ? 'overwritten' : 'created',
		];
	}

	private static function schema_source(
		string $framework_namespace,
		string $entity_class,
		string $table_name,
		string $primary_key,
		array $columns
	): string {
		$columns_source=self::export_string_list($columns, 2);
		return <<<PHP
<?php

namespace {$framework_namespace}\Schema;

use Dataphyre\Database\TableSchema;

final class {$entity_class}TableSchema {

\tprivate const COLUMNS = [
{$columns_source}
\t];

\tprivate static ?TableSchema \$schema=null;

\tpublic static function schema(): TableSchema {
\t\treturn self::\$schema ??= new TableSchema('{$table_name}', self::COLUMNS, [], '{$primary_key}');
\t}
}
PHP;
	}

	private static function repository_source(string $framework_namespace, string $entity_class): string {
		return <<<PHP
<?php

namespace {$framework_namespace}\Repository;

use Dataphyre\Database\TableSchema;
use Dataphyre\Database\TableRepository;
use {$framework_namespace}\Record\\{$entity_class}Record;
use {$framework_namespace}\Schema\\{$entity_class}TableSchema;

final class {$entity_class}Repository extends TableRepository {

\tprotected static function table(): string {
\t\treturn static::schema()->table();
\t}

\tprotected static function schema(): ?TableSchema {
\t\treturn {$entity_class}TableSchema::schema();
\t}

\tprotected static function recordClass(): ?string {
\t\treturn {$entity_class}Record::class;
\t}
}
PHP;
	}

	private static function record_source(string $framework_namespace, string $entity_class, array $columns): string {
		$methods=[];
		$generated=[];
		foreach($columns as $column){
			$method_name=self::camelize($column);
			if($method_name==='' || isset($generated[$method_name]) || $method_name==='id'){
				continue;
			}
			$generated[$method_name]=true;
			$methods[]=
"\tpublic function {$method_name}(): mixed {\n".
"\t\treturn \$this->get('{$column}');\n".
"\t}";
		}
		$methods_source=$methods===[] ? "\n" : "\n\n".implode("\n\n", $methods)."\n";
		return <<<PHP
<?php

namespace {$framework_namespace}\Record;

use Dataphyre\Database\Record;

final class {$entity_class}Record extends Record {
{$methods_source}}
PHP;
	}

	private static function export_string_list(array $values, int $indent_level): string {
		$indent=str_repeat("\t", max(0, $indent_level));
		$lines=[];
		foreach($values as $value){
			$lines[]=$indent.var_export((string)$value, true).',';
		}
		return implode("\n", $lines).($lines===[] ? '' : "\n");
	}
}
