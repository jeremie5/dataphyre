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

/**
 * Generates table schema, repository, and record classes for an application table.
 *
 * The tool resolves the target application through Dataphyre application
 * discovery, validates SQL identifiers before code generation, and refuses to
	 * overwrite existing artifacts unless the caller explicitly enables force.
 */
final class ScaffoldTableArtifacts {

	/**
	 * Creates the framework artifacts for one database table.
	 *
	 * Generated files are placed under the application's Framework autoload root
	 * using Schema, Repository, and Record subdirectories. The return payload is
	 * designed for CLI output and generation reports to show exactly what changed.
	 *
	 * @param string $projectRoot Project root containing application roots.
	 * @param string $applicationName Application id or name to locate.
	 * @param string $entityName Human or code name converted to the generated class prefix.
	 * @param string $tableName SQL table identifier.
	 * @param string $primaryKey Primary key column identifier.
	 * @param array<int, string> $columns Column identifiers exposed by generated records.
	 * @param bool $force Whether existing artifact files may be overwritten.
	 * @return array{application: string, entity: string, table: string, primary_key: string, columns: array<int, string>, framework_namespace: string, framework_directory: string, generated: array<string, array{path: string, status: string}>}
	 * @throws \RuntimeException When the application, identifiers, directories, or file writes are invalid.
	 */
	public static function scaffold(
		string $projectRoot,
		string $applicationName,
		string $entityName,
		string $tableName,
		string $primaryKey,
		array $columns,
		bool $force=false
	): array {
		$projectRoot=rtrim($projectRoot, '/\\');
		$applicationName=trim($applicationName);
		$applicationDirectory=app_locator::locate($projectRoot, $applicationName);
		if($applicationDirectory===null){
			throw new \RuntimeException("Application {$applicationName} was not found in any configured application root.");
		}
		$definition=self::loadApplicationDefinition($applicationName, $applicationDirectory);
		[$frameworkNamespace, $frameworkDirectory]=self::frameworkLocation($definition);
		$entityClass=self::classify($entityName);
		if($entityClass===''){
			throw new \RuntimeException('Entity name must contain at least one alphanumeric character.');
		}
		$tableName=self::normalizeSqlIdentifier($tableName, 'table');
		$primaryKey=self::normalizeSqlIdentifier($primaryKey, 'primary key');
		$columns=self::normalizeColumns($columns, $primaryKey);

		$schemaDirectory=$frameworkDirectory.'/Schema';
		$repositoryDirectory=$frameworkDirectory.'/Repository';
		$recordDirectory=$frameworkDirectory.'/Record';

		self::ensureDirectory($schemaDirectory);
		self::ensureDirectory($repositoryDirectory);
		self::ensureDirectory($recordDirectory);

		$paths=[
			'schema'=>$schemaDirectory.'/'.$entityClass.'TableSchema.php',
			'repository'=>$repositoryDirectory.'/'.$entityClass.'Repository.php',
			'record'=>$recordDirectory.'/'.$entityClass.'Record.php',
		];

		$status=[
			'application'=>$definition->id,
			'entity'=>$entityClass,
			'table'=>$tableName,
			'primary_key'=>$primaryKey,
			'columns'=>$columns,
			'framework_namespace'=>$frameworkNamespace,
			'framework_directory'=>$frameworkDirectory,
			'generated'=>[],
		];

		$status['generated']['schema']=self::writeFile(
			$paths['schema'],
			self::schemaSource($frameworkNamespace, $entityClass, $tableName, $primaryKey, $columns),
			$force
		);
		$status['generated']['repository']=self::writeFile(
			$paths['repository'],
			self::repositorySource($frameworkNamespace, $entityClass),
			$force
		);
		$status['generated']['record']=self::writeFile(
			$paths['record'],
			self::recordSource($frameworkNamespace, $entityClass, $columns),
			$force
		);

		return $status;
	}

	/**
	 * Loads application metadata from conventions plus optional app.php overrides.
	 *
	 * @param string $applicationName Application id used for conventional defaults.
	 * @param string $applicationDirectory Located application root.
	 * @return application_definition Normalized application definition.
	 * @throws \RuntimeException When app.php returns an unsupported value.
	 */
	private static function loadApplicationDefinition(string $applicationName, string $applicationDirectory): application_definition {
		$conventionalDefinition=application_definition::from_conventions($applicationName, $applicationDirectory);
		$definitionFile=$applicationDirectory.'/app.php';
		if(!is_file($definitionFile)){
			return $conventionalDefinition;
		}
		$definition=require($definitionFile);
		if($definition instanceof application_definition){
			return $definition;
		}
		if(is_array($definition)){
			return $conventionalDefinition->withOverrides($definition);
		}
		throw new \RuntimeException("Application definition must return an array or application_definition: {$definitionFile}");
	}

	/**
	 * Resolves the Framework namespace prefix and directory for generated files.
	 *
	 * @param application_definition $definition Application definition with autoload roots.
	 * @return array{0: string, 1: string} Framework namespace and filesystem directory.
	 */
	private static function frameworkLocation(application_definition $definition): array {
		foreach($definition->autoload as $prefix=>$directory){
			$normalizedPrefix=trim((string)$prefix, '\\');
			$normalizedDirectory=rtrim((string)$directory, '/\\');
			if($normalizedPrefix==='' || $normalizedDirectory===''){
				continue;
			}
			if(str_ends_with(strtolower($normalizedPrefix), '\\framework')){
				return [$normalizedPrefix, $normalizedDirectory];
			}
		}
		return [
			$definition->id.'\\framework',
			$definition->rootDirectory.'/framework',
		];
	}

	/**
	 * Validates, de-duplicates, and primary-key-prefixes generated column names.
	 *
	 * @param array<int, string> $columns Candidate column identifiers.
	 * @param string $primaryKey Normalized primary key identifier.
	 * @return array<int, string> Unique column identifiers with the primary key included.
	 */
	private static function normalizeColumns(array $columns, string $primaryKey): array {
		$normalized=[];
		$seen=[];
		foreach($columns as $column){
			$column=self::normalizeSqlIdentifier((string)$column, 'column');
			if(isset($seen[$column])){
				continue;
			}
			$seen[$column]=true;
			$normalized[]=$column;
		}
		if(!isset($seen[$primaryKey])){
			array_unshift($normalized, $primaryKey);
		}
		return $normalized;
	}

	/**
	 * Validates an identifier used inside generated SQL-aware PHP artifacts.
	 *
	 * @param string $value Candidate identifier.
	 * @param string $label Human label included in exception messages.
	 * @return string Trimmed SQL identifier.
	 * @throws \RuntimeException When the identifier is empty or contains unsafe characters.
	 */
	private static function normalizeSqlIdentifier(string $value, string $label): string {
		$value=trim($value);
		if($value==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)!==1){
			throw new \RuntimeException("Invalid {$label} identifier: {$value}");
		}
		return $value;
	}

	/**
	 * Converts an arbitrary entity or column name to PascalCase.
	 *
	 * @param string $value Human or machine name to classify.
	 * @return string PascalCase identifier, or an empty string when no alphanumeric parts exist.
	 */
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

	/**
	 * Converts an arbitrary name to camelCase for generated record accessors.
	 *
	 * @param string $value Human or machine name to camelize.
	 * @return string camelCase method name, or an empty string when classification fails.
	 */
	private static function camelize(string $value): string {
		$class=self::classify($value);
		if($class===''){
			return '';
		}
		return lcfirst($class);
	}

	/**
	 * Ensures a generated artifact directory exists.
	 *
	 * @param string $directory Directory path to create recursively.
	 * @return void Directory exists on success.
	 * @throws \RuntimeException When the directory cannot be created.
	 */
	private static function ensureDirectory(string $directory): void {
		if(is_dir($directory)){
			return;
		}
		if(@mkdir($directory, 0777, true)!==true && !is_dir($directory)){
			throw new \RuntimeException("Unable to create directory: {$directory}");
		}
	}

	/**
	 * Writes one generated file with overwrite protection.
	 *
	 * @param string $path Destination file path.
	 * @param string $contents Complete PHP source to write.
	 * @param bool $force Whether an existing file may be overwritten.
	 * @return array{path: string, status: string} File path and creation/overwrite status.
	 * @throws \RuntimeException When overwrite is disallowed or writing fails.
	 */
	private static function writeFile(string $path, string $contents, bool $force): array {
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

	/**
	 * Builds the generated TableSchema class source.
	 *
	 * @param string $frameworkNamespace Target Framework namespace.
	 * @param string $entityClass PascalCase entity class prefix.
	 * @param string $tableName SQL table identifier.
	 * @param string $primaryKey Primary key column identifier.
	 * @param array<int, string> $columns Column identifiers included in the schema.
	 * @return string Complete PHP source for the generated schema class.
	 */
	private static function schemaSource(
		string $frameworkNamespace,
		string $entityClass,
		string $tableName,
		string $primaryKey,
		array $columns
	): string {
		$columnsSource=self::exportStringList($columns, 2);
		return <<<PHP
<?php

namespace {$frameworkNamespace}\Schema;

use Dataphyre\Database\TableSchema;

final class {$entityClass}TableSchema {

\tprivate const COLUMNS = [
{$columnsSource}
\t];

\tprivate static ?TableSchema \$schema=null;

\tpublic static function schema(): TableSchema {
\t\treturn self::\$schema ??= new TableSchema('{$tableName}', self::COLUMNS, [], '{$primaryKey}');
\t}
}
PHP;
	}

	/**
	 * Builds the generated repository class source.
	 *
	 * @param string $frameworkNamespace Target Framework namespace.
	 * @param string $entityClass PascalCase entity class prefix.
	 * @return string Complete PHP source for the generated repository class.
	 */
	private static function repositorySource(string $frameworkNamespace, string $entityClass): string {
		return <<<PHP
<?php

namespace {$frameworkNamespace}\Repository;

use Dataphyre\Database\TableSchema;
use Dataphyre\Database\TableRepository;
use {$frameworkNamespace}\Record\\{$entityClass}Record;
use {$frameworkNamespace}\Schema\\{$entityClass}TableSchema;

final class {$entityClass}Repository extends TableRepository {

\tprotected static function table(): string {
\t\treturn static::schema()->table();
\t}

\tprotected static function schema(): ?TableSchema {
\t\treturn {$entityClass}TableSchema::schema();
\t}

\tprotected static function recordClass(): ?string {
\t\treturn {$entityClass}Record::class;
\t}
}
PHP;
	}

	/**
	 * Builds the generated record class source with column accessor methods.
	 *
	 * @param string $frameworkNamespace Target Framework namespace.
	 * @param string $entityClass PascalCase entity class prefix.
	 * @param array<int, string> $columns Column identifiers exposed as accessors.
	 * @return string Complete PHP source for the generated record class.
	 */
	private static function recordSource(string $frameworkNamespace, string $entityClass, array $columns): string {
		$methods=[];
		$generated=[];
		foreach($columns as $column){
			$methodName=self::camelize($column);
			if($methodName==='' || isset($generated[$methodName]) || $methodName==='id'){
				continue;
			}
			$generated[$methodName]=true;
			$methods[]=
"\tpublic function {$methodName}(): mixed {\n".
"\t\treturn \$this->get('{$column}');\n".
"\t}";
		}
		$methodsSource=$methods===[] ? "\n" : "\n\n".implode("\n\n", $methods)."\n";
		return <<<PHP
<?php

namespace {$frameworkNamespace}\Record;

use Dataphyre\Database\Record;

final class {$entityClass}Record extends Record {
{$methodsSource}}
PHP;
	}

	/**
	 * Exports a list of strings as an indented PHP array body.
	 *
	 * @param array<int, string> $values Values to export with var_export escaping.
	 * @param int $indentLevel Number of tab indents to prepend to each item.
	 * @return string PHP source lines for the array body.
	 */
	private static function exportStringList(array $values, int $indentLevel): string {
		$indent=str_repeat("\t", max(0, $indentLevel));
		$lines=[];
		foreach($values as $value){
			$lines[]=$indent.var_export((string)$value, true).',';
		}
		return implode("\n", $lines).($lines===[] ? '' : "\n");
	}
}
