<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Fluent SQL table definition used for schema metadata and table hydration.
 *
 * TableDefinition records portable column definitions, runtime casts, primary
 * keys, unique constraints, indexes, projections, defaults, and DBMS-specific SQL
 * needed by Dataphyre's SQL runtime.
 */
final class TableDefinition {

	private string $table;
	private array $columns=[];
	private array $primaryColumns=[];
	private array $uniqueConstraints=[];
	private array $indexes=[];
	private array $projections=[];
	private array $casts=[];
	private ?string $lastColumn=null;

	/**
	 * Starts a schema definition for one SQL table.
	 *
	 * Table names are validated immediately. Dotted identifiers are allowed so
	 * PostgreSQL schema-qualified tables can be represented by the same builder.
	 *
	 * @param string $table Table name or schema-qualified table name.
	 * @throws \InvalidArgumentException When the table identifier is invalid.
	 */
	public function __construct(string $table){
		$this->table=$this->assertIdentifier($table, true);
	}

	/**
	 * Creates a table definition for fluent schema construction.
	 *
	 * @param string $table Table name or schema-qualified table name.
	 * @return self New table definition builder.
	 */
	public static function for(string $table): self {
		return new self($table);
	}

	/**
	 * Returns the validated table identifier.
	 *
	 * @return string Table name or schema-qualified table name.
	 */
	public function table(): string {
		return $this->table;
	}

	/**
	 * Adds or replaces a column definition.
	 *
	 * The new column becomes the active column for chained modifiers such as cast(),
	 * nullable(), default(), defaultSql(), primary(), and onUpdateCurrent().
	 *
	 * @param string $name SQL column identifier.
	 * @param string|array<string, string> $type DBMS-specific type map or shared SQL type.
	 * @return self This table definition builder.
	 * @throws \InvalidArgumentException When the column identifier is invalid.
	 */
	public function column(string $name, string|array $type): self {
		$name=$this->assertIdentifier($name);
		$this->columns[$name]=[
			'name'=>$name,
			'type'=>$this->normalizeType($type),
			'nullable'=>true,
			'default'=>null,
			'default_sql'=>null,
			'on_update_current'=>false,
			'inline_primary'=>false,
			'check'=>null,
			'cast'=>null,
		];
		$this->lastColumn=$name;
		return $this;
	}

	/**
	 * Adds a string column.
	 *
	 * MySQL and PostgreSQL use VARCHAR(length); SQLite uses TEXT because SQLite does
	 * not enforce VARCHAR length in the same way.
	 *
	 * @param string $name SQL column identifier.
	 * @param int $length Maximum length for DBMSs that support VARCHAR sizing.
	 * @return self This table definition builder.
	 */
	public function string(string $name, int $length=255): self {
		$length=max(1, $length);
		return $this->column($name, [
			'mysql'=>"VARCHAR({$length})",
			'postgresql'=>"VARCHAR({$length})",
			'sqlite'=>'TEXT',
		]);
	}

	/**
	 * Adds a text column.
	 *
	 * @param string $name SQL column identifier.
	 * @return self This table definition builder.
	 */
	public function text(string $name): self {
		return $this->column($name, 'TEXT');
	}

	/**
	 * Adds a long text column.
	 *
	 * MySQL uses LONGTEXT while PostgreSQL and SQLite use TEXT.
	 *
	 * @param string $name SQL column identifier.
	 * @return self This table definition builder.
	 */
	public function longText(string $name): self {
		return $this->column($name, [
			'mysql'=>'LONGTEXT',
			'postgresql'=>'TEXT',
			'sqlite'=>'TEXT',
		]);
	}

	/**
	 * Adds a JSON column and registers a json cast.
	 *
	 * MySQL uses JSON, PostgreSQL uses JSONB, and SQLite stores JSON as TEXT.
	 *
	 * @param string $name SQL column identifier.
	 * @return self This table definition builder.
	 */
	public function json(string $name): self {
		return $this->column($name, [
			'mysql'=>'JSON',
			'postgresql'=>'JSONB',
			'sqlite'=>'TEXT',
		])->cast('json');
	}

	/**
	 * Adds an integer column and registers an int cast.
	 *
	 * @param string $name SQL column identifier.
	 * @return self This table definition builder.
	 */
	public function integer(string $name): self {
		return $this->column($name, [
			'mysql'=>'INT',
			'postgresql'=>'INTEGER',
			'sqlite'=>'INTEGER',
		])->cast('int');
	}

	/**
	 * Adds a signed big integer column and registers an int cast.
	 *
	 * @param string $name SQL column identifier.
	 * @return self This table definition builder.
	 */
	public function bigInt(string $name): self {
		return $this->column($name, [
			'mysql'=>'BIGINT',
			'postgresql'=>'BIGINT',
			'sqlite'=>'INTEGER',
		])->cast('int');
	}

	/**
	 * Adds an unsigned-style big integer column and registers an int cast.
	 *
	 * PostgreSQL and SQLite do not have a direct unsigned BIGINT equivalent, so they
	 * use their normal big integer storage type.
	 *
	 * @param string $name SQL column identifier.
	 * @return self This table definition builder.
	 */
	public function unsignedBigInt(string $name): self {
		return $this->column($name, [
			'mysql'=>'BIGINT UNSIGNED',
			'postgresql'=>'BIGINT',
			'sqlite'=>'INTEGER',
		])->cast('int');
	}

	/**
	 * Adds a floating-point column and registers a float cast.
	 *
	 * @param string $name SQL column identifier.
	 * @return self This table definition builder.
	 */
	public function float(string $name): self {
		return $this->column($name, [
			'mysql'=>'DOUBLE',
			'postgresql'=>'DOUBLE PRECISION',
			'sqlite'=>'REAL',
		])->cast('float');
	}

	/**
	 * Adds a boolean column and registers a bool cast.
	 *
	 * SQLite stores booleans as INTEGER values.
	 *
	 * @param string $name SQL column identifier.
	 * @return self This table definition builder.
	 */
	public function boolean(string $name): self {
		return $this->column($name, [
			'mysql'=>'BOOLEAN',
			'postgresql'=>'BOOLEAN',
			'sqlite'=>'INTEGER',
		])->cast('bool');
	}

	/**
	 * Adds a timestamp column and registers a datetime cast.
	 *
	 * @param string $name SQL column identifier.
	 * @return self This table definition builder.
	 */
	public function timestamp(string $name): self {
		return $this->column($name, [
			'mysql'=>'TIMESTAMP',
			'postgresql'=>'TIMESTAMPTZ',
			'sqlite'=>'TEXT',
		])->cast('datetime');
	}

	/**
	 * Adds a datetime column and registers a datetime cast.
	 *
	 * @param string $name SQL column identifier.
	 * @return self This table definition builder.
	 */
	public function datetime(string $name): self {
		return $this->column($name, [
			'mysql'=>'DATETIME',
			'postgresql'=>'TIMESTAMP',
			'sqlite'=>'TEXT',
		])->cast('datetime');
	}

	/**
	 * Adds a UUID-compatible column.
	 *
	 * PostgreSQL uses UUID; MySQL and SQLite use text-compatible storage.
	 *
	 * @param string $name SQL column identifier.
	 * @return self This table definition builder.
	 */
	public function uuid(string $name): self {
		return $this->column($name, [
			'mysql'=>'VARCHAR(36)',
			'postgresql'=>'UUID',
			'sqlite'=>'TEXT',
		]);
	}

	/**
	 * Adds an enum-like column with optional check metadata.
	 *
	 * MySQL uses ENUM literals. PostgreSQL and SQLite use TEXT plus a generated CHECK
	 * constraint when non-empty values are supplied.
	 *
	 * @param string $name SQL column identifier.
	 * @param array<int, string|int|float> $values Allowed enum values.
	 * @return self This table definition builder.
	 */
	public function enum(string $name, array $values): self {
		$normalized=[];
		$quoted=[];
		foreach($values as $value){
			$value=trim((string)$value);
			if($value===''){
				continue;
			}
			$normalized[]=$value;
			$quoted[]="'".str_replace("'", "''", $value)."'";
		}
		$values=$normalized;
		$quotedValues=implode(',', $quoted);
		$this->column($name, [
			'mysql'=>"ENUM({$quotedValues})",
			'postgresql'=>'TEXT',
			'sqlite'=>'TEXT',
		]);
		if($values!==[]){
			$this->columns[$this->lastColumn]['check_values']=$values;
		}
		return $this;
	}

	/**
	 * Adds an auto-incrementing integer primary key column.
	 *
	 * The column is marked not-null, inline primary, and cast as int.
	 *
	 * @param string $name SQL column identifier, defaulting to id.
	 * @return self This table definition builder.
	 */
	public function autoIncrement(string $name='id'): self {
		$this->column($name, [
			'mysql'=>'INT AUTO_INCREMENT',
			'postgresql'=>'SERIAL',
			'sqlite'=>'INTEGER',
		]);
		$this->columns[$name]['nullable']=false;
		$this->columns[$name]['inline_primary']=true;
		$this->columns[$name]['cast']='int';
		$this->casts[$name]='int';
		return $this;
	}

	/**
	 * Assigns a runtime cast to the most recently added column.
	 *
	 * Supported normalized casts are string, int, float, bool, json, and datetime.
	 *
	 * @param string $type Cast type or supported alias.
	 * @return self This table definition builder.
	 * @throws \LogicException When no column has been added yet.
	 * @throws \InvalidArgumentException When the cast type is unsupported.
	 */
	public function cast(string $type): self {
		$column=$this->requireLastColumn();
		$type=$this->normalizeCast($type);
		$this->columns[$column]['cast']=$type;
		$this->casts[$column]=$type;
		return $this;
	}

	/**
	 * Assigns runtime casts to existing columns.
	 *
	 * @param array<string, string> $casts Cast types keyed by column name.
	 * @return self This table definition builder.
	 * @throws \InvalidArgumentException When a column is unknown or cast type is unsupported.
	 */
	public function casts(array $casts): self {
		foreach($casts as $column=>$type){
			$column=$this->assertIdentifier((string)$column);
			if(isset($this->columns[$column])===false){
				throw new \InvalidArgumentException("Cannot cast unknown SQL column: {$column}");
			}
			$type=$this->normalizeCast((string)$type);
			$this->columns[$column]['cast']=$type;
			$this->casts[$column]=$type;
		}
		return $this;
	}

	/**
	 * Sets nullability on the most recently added column.
	 *
	 * @param bool $nullable True to allow NULL values.
	 * @return self This table definition builder.
	 * @throws \LogicException When no column has been added yet.
	 */
	public function nullable(bool $nullable=true): self {
		$column=$this->requireLastColumn();
		$this->columns[$column]['nullable']=$nullable;
		return $this;
	}

	/**
	 * Marks the most recently added column as NOT NULL.
	 *
	 * @return self This table definition builder.
	 * @throws \LogicException When no column has been added yet.
	 */
	public function notNull(): self {
		return $this->nullable(false);
	}

	/**
	 * Sets a literal default value on the most recently added column.
	 *
	 * Literal defaults are quoted for each DBMS during SQL generation. Calling this
	 * clears any previous SQL-expression default on the column.
	 *
	 * @param mixed $value Literal default value.
	 * @return self This table definition builder.
	 * @throws \LogicException When no column has been added yet.
	 */
	public function default(mixed $value): self {
		$column=$this->requireLastColumn();
		$this->columns[$column]['default']=$value;
		$this->columns[$column]['default_sql']=null;
		return $this;
	}

	/**
	 * Sets a SQL-expression default on the most recently added column.
	 *
	 * Use this for DBMS functions such as CURRENT_TIMESTAMP. Calling this clears any
	 * previous literal default on the column.
	 *
	 * @param string|array<string, string> $sql DBMS-specific SQL expression map or shared expression.
	 * @return self This table definition builder.
	 * @throws \LogicException When no column has been added yet.
	 */
	public function defaultSql(string|array $sql): self {
		$column=$this->requireLastColumn();
		$this->columns[$column]['default']=null;
		$this->columns[$column]['default_sql']=$this->normalizeType($sql);
		return $this;
	}

	/**
	 * Sets a current-timestamp SQL default on the most recently added column.
	 *
	 * @return self This table definition builder.
	 * @throws \LogicException When no column has been added yet.
	 */
	public function defaultCurrent(): self {
		return $this->defaultSql([
			'mysql'=>'CURRENT_TIMESTAMP',
			'postgresql'=>'CURRENT_TIMESTAMP',
			'sqlite'=>"(datetime('now'))",
		]);
	}

	/**
	 * Marks the most recently added column for MySQL ON UPDATE CURRENT_TIMESTAMP.
	 *
	 * Other DBMSs ignore this flag during SQL generation.
	 *
	 * @return self This table definition builder.
	 * @throws \LogicException When no column has been added yet.
	 */
	public function onUpdateCurrent(): self {
		$column=$this->requireLastColumn();
		$this->columns[$column]['on_update_current']=true;
		return $this;
	}

	/**
	 * Defines the table primary key columns.
	 *
	 * Passing null uses the most recently added column. Inline auto-increment primary
	 * keys suppress the separate PRIMARY KEY clause during SQL generation.
	 *
	 * @param string|array<int, string>|null $columns Primary key column or columns.
	 * @return self This table definition builder.
	 */
	public function primary(string|array|null $columns=null): self {
		if($columns===null){
			$columns=[$this->requireLastColumn()];
		}
		elseif(is_string($columns)){
			$columns=[$columns];
		}
		$this->primaryColumns=$this->normalizeColumns($columns);
		return $this;
	}

	/**
	 * Adds a unique constraint.
	 *
	 * Index prefix notation such as column(191) is accepted when supported by the
	 * target DBMS.
	 *
	 * @param string|array<int, string> $columns Unique column or columns.
	 * @param string|null $name Optional constraint name.
	 * @return self This table definition builder.
	 */
	public function unique(string|array $columns, ?string $name=null): self {
		$this->uniqueConstraints[]=[
			'name'=>$name,
			'columns'=>$this->normalizeColumns(is_string($columns) ? [$columns] : $columns, true),
		];
		return $this;
	}

	/**
	 * Adds a non-unique index definition.
	 *
	 * Unnamed indexes receive deterministic names based on table and column names.
	 *
	 * @param string|array<int, string> $columns Indexed column or columns.
	 * @param string|null $name Optional index name.
	 * @return self This table definition builder.
	 */
	public function index(string|array $columns, ?string $name=null): self {
		$this->indexes[]=[
			'name'=>$name,
			'columns'=>$this->normalizeColumns(is_string($columns) ? [$columns] : $columns, true),
		];
		return $this;
	}

	/**
	 * Defines a named projection for model/table schema consumers.
	 *
	 * @param string $name Projection name.
	 * @param array<int, string> $columns Projection columns.
	 * @return self This table definition builder.
	 * @throws \InvalidArgumentException When the projection name is empty.
	 */
	public function projection(string $name, array $columns): self {
		$name=trim($name);
		if($name===''){
			throw new \InvalidArgumentException('Table definition projection name cannot be empty.');
		}
		$this->projections[$name]=$this->normalizeColumns($columns);
		return $this;
	}

	/**
	 * Builds the runtime TableSchema view for this definition.
	 *
	 * The schema exposes column names, projections, a single-column primary key when
	 * available, and the cast map used by record hydration.
	 *
	 * @return TableSchema Runtime schema value object.
	 */
	public function schema(): TableSchema {
		return new TableSchema(
			$this->table,
			array_keys($this->columns),
			$this->projections,
			count($this->primaryColumns)===1 ? $this->primaryColumns[0] : null,
			$this->casts
		);
	}

	/**
	 * Returns defined column names in declaration order.
	 *
	 * @return array<int, string> Column names.
	 */
	public function columns(): array {
		return array_keys($this->columns);
	}

	/**
	 * Returns primary key columns.
	 *
	 * @return array<int, string> Primary key columns in configured order.
	 */
	public function primaryColumns(): array {
		return $this->primaryColumns;
	}

	/**
	 * Returns named schema projections.
	 *
	 * @return array<string, array<int, string>> Projection columns keyed by projection name.
	 */
	public function projections(): array {
		return $this->projections;
	}

	/**
	 * Returns runtime casts keyed by column name.
	 *
	 * @return array<string, string> Normalized casts keyed by column name.
	 */
	public function castMap(): array {
		return $this->casts;
	}

	/**
	 * Executes schema creation queries for this table definition.
	 *
	 * Required create-schema/create-table queries must succeed; optional index queries
	 * are attempted after table creation. The table cluster override is read from
	 * DP_SQL_CFG.
	 *
	 * @return bool True when required hydration queries succeed.
	 */
	public function hydrate(): bool {
		$queries=$this->createQueries();
		if($queries===[]){
			return false;
		}
		$dbmsCluster=\DP_SQL_CFG['tables'][$this->table]['cluster'] ?? \DP_SQL_CFG['default_cluster'];
		foreach($queries as $index=>$query){
			$required=(bool)($query['_required'] ?? $index===0);
			$query['dbms_cluster_override']=$dbmsCluster;
			\dataphyre\sql::clear_last_query_error();
			$result=\dataphyre\sql::query($query, null, false, false, false);
			if($required===true && $result===false){
				return false;
			}
		}
		return true;
	}

	/**
	 * Attempts to add one defined column to an existing table.
	 *
	 * Inline primary columns are skipped. Duplicate-column errors are treated as
	 * success so the operation is safe to retry during deployments.
	 *
	 * @param string $column Column name to add.
	 * @return bool True when the column exists or is added successfully.
	 */
	public function hydrateColumn(string $column): bool {
		$column=$this->assertIdentifier($column);
		if(!isset($this->columns[$column]) || $this->columns[$column]['inline_primary']===true){
			return false;
		}
		$dbmsCluster=\DP_SQL_CFG['tables'][$this->table]['cluster'] ?? \DP_SQL_CFG['default_cluster'];
		$query=[
			'mysql'=>'ALTER TABLE '.$this->quoteTable('mysql').' ADD COLUMN '.$this->columnSql($this->columns[$column], 'mysql'),
			'postgresql'=>'ALTER TABLE '.$this->quoteTable('postgresql').' ADD COLUMN IF NOT EXISTS '.$this->columnSql($this->columns[$column], 'postgresql'),
			'sqlite'=>'ALTER TABLE '.$this->quoteTable('sqlite').' ADD COLUMN '.$this->columnSql($this->columns[$column], 'sqlite'),
			'dbms_cluster_override'=>$dbmsCluster,
		];
		\dataphyre\sql::clear_last_query_error();
		$result=\dataphyre\sql::query($query, null, false, false, false);
		if($result!==false || \dataphyre\sql::last_query_error()===null){
			return true;
		}
		$message=strtolower((string)(\dataphyre\sql::last_query_error()['message'] ?? ''));
		if(str_contains($message, 'duplicate column') || str_contains($message, 'already exists')){
			\dataphyre\sql::clear_last_query_error();
			return true;
		}
		return false;
	}

	/**
	 * Builds DBMS-specific query arrays needed to create the schema/table/indexes.
	 *
	 * Empty table definitions produce no queries. PostgreSQL schema creation is added
	 * before table creation when the table is schema-qualified outside public.
	 *
	 * @return array<int, array<string, mixed>> Query arrays for Dataphyre SQL execution.
	 */
	public function createQueries(): array {
		if($this->columns===[]){
			return [];
		}
		$mysqlTable=$this->quoteTable('mysql');
		$postgresqlTable=$this->quoteTable('postgresql');
		$sqliteTable=$this->quoteTable('sqlite');
		$queries=[];
		if(null!==$schemaQuery=$this->createSchemaSql('postgresql')){
			$queries[]=[
				'mysql'=>'SELECT 1',
				'postgresql'=>$schemaQuery,
				'sqlite'=>'SELECT 1',
				'_required'=>true,
			];
		}
		$queries[]=[
			'mysql'=>$this->createTableSql('mysql', $mysqlTable),
			'postgresql'=>$this->createTableSql('postgresql', $postgresqlTable),
			'sqlite'=>$this->createTableSql('sqlite', $sqliteTable),
			'_required'=>true,
		];
		foreach($this->indexes as $index){
			$queries[]=[
				'mysql'=>$this->createIndexSql($index, 'mysql', $mysqlTable),
				'postgresql'=>$this->createIndexSql($index, 'postgresql', $postgresqlTable),
				'sqlite'=>$this->createIndexSql($index, 'sqlite', $sqliteTable),
			];
		}
		return $queries;
	}

	/**
	 * Builds the CREATE TABLE statement for one DBMS.
	 *
	 * Column SQL, separate primary keys, and unique constraints are assembled into
	 * one idempotent table creation statement. Inline primary columns suppress a
	 * duplicate table-level primary-key clause.
	 *
	 * @param string $dbms Target DBMS key: mysql, postgresql, or sqlite.
	 * @return string CREATE TABLE SQL.
	 */
	private function createTableSql(string $dbms, ?string $quotedTable=null): string {
		$parts=[];
		$hasInlinePrimary=false;
		foreach($this->columns as $column){
			$parts[]=$this->columnSql($column, $dbms);
			$hasInlinePrimary=$hasInlinePrimary || $column['inline_primary']===true;
		}
		if($this->primaryColumns!==[] && $hasInlinePrimary===false){
			$parts[]='PRIMARY KEY ('.$this->quotedColumns($this->primaryColumns, $dbms).')';
		}
		foreach($this->uniqueConstraints as $constraint){
			$name=$constraint['name'] ?? null;
			$prefix=$name!==null && trim((string)$name)!==''
				? 'CONSTRAINT '.$this->quoteIdentifier((string)$name, $dbms).' '
				: '';
			$parts[]=$prefix.'UNIQUE ('.$this->quotedColumns($constraint['columns'], $dbms).')';
		}
		return 'CREATE TABLE IF NOT EXISTS '.($quotedTable ?? $this->quoteTable($dbms))." (\n\t".implode(",\n\t", $parts)."\n)";
	}

	/**
	 * Builds the PostgreSQL schema creation statement when needed.
	 *
	 * Only schema-qualified non-public table names require a separate schema
	 * statement. MySQL and SQLite do not receive schema setup from this builder.
	 *
	 * @param string $dbms Target DBMS key.
	 * @return ?string CREATE SCHEMA SQL, or null when not needed.
	 */
	private function createSchemaSql(string $dbms): ?string {
		if($dbms!=='postgresql'){
			return null;
		}
		$parts=explode('.', $this->table);
		if(count($parts)!==2 || $parts[0]==='public'){
			return null;
		}
		return 'CREATE SCHEMA IF NOT EXISTS '.$this->quoteIdentifier($parts[0], $dbms);
	}

	/**
	 * Builds one column definition for CREATE TABLE.
	 *
	 * The generated fragment includes type, inline primary key behavior, nullable
	 * state, enum check constraints, literal or SQL-expression defaults, and MySQL
	 * ON UPDATE CURRENT_TIMESTAMP metadata.
	 *
	 * @param array<string,mixed> $column Normalized column definition.
	 * @param string $dbms Target DBMS key.
	 * @return string Column SQL fragment.
	 */
	private function columnSql(array $column, string $dbms): string {
		$sql=$this->quoteIdentifier($column['name'], $dbms).' '.$this->typeFor($column['type'], $dbms);
		if($column['inline_primary']===true){
			$sql.=' PRIMARY KEY';
			if($dbms==='sqlite'){
				$sql.=' AUTOINCREMENT';
			}
			return $sql;
		}
		if($column['nullable']===false){
			$sql.=' NOT NULL';
		}
		if(array_key_exists('check_values', $column) && is_array($column['check_values'])){
			$values=implode(', ', array_map(
				static fn(string $value): string => "'".str_replace("'", "''", $value)."'",
				$column['check_values']
			));
			if($values!==''){
				$sql.=' CHECK ('.$this->quoteIdentifier($column['name'], $dbms).' IN ('.$values.'))';
			}
		}
		if($column['default_sql']!==null){
			$sql.=' DEFAULT '.$this->typeFor($column['default_sql'], $dbms);
		}
		elseif($column['default']!==null){
			$sql.=' DEFAULT '.$this->literal($column['default'], $dbms);
		}
		if($column['on_update_current']===true && $dbms==='mysql'){
			$sql.=' ON UPDATE CURRENT_TIMESTAMP';
		}
		return $sql;
	}

	/**
	 * Builds an index creation statement for one DBMS.
	 *
	 * MySQL does not support IF NOT EXISTS for this statement in the same portable
	 * form, while PostgreSQL and SQLite use idempotent index creation.
	 *
	 * @param array{name?:?string,columns:array<int,string>} $index Normalized index definition.
	 * @param string $dbms Target DBMS key.
	 * @return string CREATE INDEX SQL.
	 */
	private function createIndexSql(array $index, string $dbms, ?string $quotedTable=null): string {
		$name=$index['name'] ?: $this->defaultIndexName($index['columns']);
		$prefix=$dbms==='mysql' ? 'CREATE INDEX ' : 'CREATE INDEX IF NOT EXISTS ';
		return $prefix.$this->quoteIdentifier($name, $dbms)
			.' ON '.($quotedTable ?? $this->quoteTable($dbms)).' ('.$this->quotedColumns($index['columns'], $dbms).')';
	}

	/**
	 * Builds a deterministic fallback index name.
	 *
	 * The table name and indexed columns are folded into an identifier-friendly
	 * token so generated migrations remain stable across runs.
	 *
	 * @param array<int,string> $columns Indexed columns.
	 * @return string Generated index name.
	 */
	private function defaultIndexName(array $columns): string {
		$parts=[];
		foreach($columns as $column){
			$parts[]=preg_replace('/[^A-Za-z0-9_]+/', '_', $column) ?? $column;
		}
		return 'idx_'.str_replace('.', '_', $this->table).'_'.implode('_', $parts);
	}

	/**
	 * Normalizes shared or DBMS-specific type declarations.
	 *
	 * String declarations apply to every DBMS. Array declarations may provide
	 * mysql, postgresql/pgsql, sqlite, or default entries, with TEXT as the final
	 * fallback.
	 *
	 * @param string|array<string,string> $type Type declaration.
	 * @return array{mysql:string,postgresql:string,sqlite:string} DBMS type map.
	 */
	private function normalizeType(string|array $type): array {
		if(is_string($type)){
			return [
				'mysql'=>$type,
				'postgresql'=>$type,
				'sqlite'=>$type,
			];
		}
		return [
			'mysql'=>(string)($type['mysql'] ?? $type['default'] ?? 'TEXT'),
			'postgresql'=>(string)($type['postgresql'] ?? $type['pgsql'] ?? $type['default'] ?? 'TEXT'),
			'sqlite'=>(string)($type['sqlite'] ?? $type['default'] ?? 'TEXT'),
		];
	}

	/**
	 * Normalizes runtime cast aliases to supported TableSchema casts.
	 *
	 * Unsupported casts are rejected during definition time so hydration cannot
	 * receive unknown cast instructions later.
	 *
	 * @param string $type Raw cast name.
	 * @return string Supported cast token.
	 */
	private function normalizeCast(string $type): string {
		$type=strtolower(trim($type));
		$aliases=[
			'integer'=>'int',
			'double'=>'float',
			'real'=>'float',
			'boolean'=>'bool',
			'json_array'=>'json',
			'array'=>'json',
			'object'=>'json',
			'timestamp'=>'datetime',
			'date_time'=>'datetime',
			'datetimeimmutable'=>'datetime',
		];
		$type=$aliases[$type] ?? $type;
		if(!in_array($type, ['string', 'int', 'float', 'bool', 'json', 'datetime'], true)){
			throw new \InvalidArgumentException("Unsupported SQL column cast: {$type}");
		}
		return $type;
	}

	/**
	 * Selects the SQL type expression for a DBMS.
	 *
	 * Missing DBMS entries fall back to mysql and then TEXT, allowing partial maps
	 * to remain usable.
	 *
	 * @param array<string,string> $type Normalized type map.
	 * @param string $dbms Target DBMS key.
	 * @return string SQL type expression.
	 */
	private function typeFor(array $type, string $dbms): string {
		return $type[$dbms] ?? $type['mysql'] ?? 'TEXT';
	}

	/**
	 * Validates and de-duplicates column lists.
	 *
	 * Prefix notation such as name(191) is allowed for index definitions when
	 * requested, and normal column lists require plain SQL identifiers.
	 *
	 * @param array<int,mixed> $columns Raw column declarations.
	 * @param bool $allowPrefix Whether MySQL index prefix notation is accepted.
	 * @return array<int,string> Normalized unique columns.
	 */
	private function normalizeColumns(array $columns, bool $allowPrefix=false): array {
		$normalized=[];
		foreach($columns as $column){
			$column=trim((string)$column);
			if($allowPrefix && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\([0-9]+\))?$/', $column)===1){
				$normalized[]=$column;
				continue;
			}
			$normalized[]=$this->assertIdentifier($column);
		}
		return array_values(array_unique($normalized));
	}

	/**
	 * Quotes a column list for primary, unique, and index SQL.
	 *
	 * @param array<int,string> $columns Normalized column list.
	 * @param string $dbms Target DBMS key.
	 * @return string Comma-separated quoted columns.
	 */
	private function quotedColumns(array $columns, string $dbms): string {
		$quoted=[];
		foreach($columns as $column){
			$quoted[]=$this->quoteIndexColumn($column, $dbms);
		}
		return implode(', ', $quoted);
	}

	/**
	 * Quotes an index column and handles optional prefix length notation.
	 *
	 * Prefix lengths are emitted only for MySQL; PostgreSQL and SQLite receive the
	 * base quoted column name.
	 *
	 * @param string $column Normalized index column.
	 * @param string $dbms Target DBMS key.
	 * @return string Quoted index column SQL.
	 */
	private function quoteIndexColumn(string $column, string $dbms): string {
		if(preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\(([0-9]+)\)$/', $column, $matches)===1){
			return $this->quoteIdentifier($matches[1], $dbms).($dbms==='mysql' ? '('.$matches[2].')' : '');
		}
		return $this->quoteIdentifier($column, $dbms);
	}

	/**
	 * Quotes the table identifier for a target DBMS.
	 *
	 * SQLite quotes the full table string, while MySQL/PostgreSQL quote each dotted
	 * identifier segment separately for schema-qualified names.
	 *
	 * @param string $dbms Target DBMS key.
	 * @return string Quoted table identifier.
	 */
	private function quoteTable(string $dbms): string {
		if($dbms==='sqlite'){
			return '"'.str_replace('"', '""', $this->table).'"';
		}
		$quoted=[];
		foreach(explode('.', $this->table) as $part){
			$quoted[]=$this->quoteIdentifier($part, $dbms);
		}
		return implode('.', $quoted);
	}

	/**
	 * Quotes one SQL identifier segment.
	 *
	 * MySQL uses backticks and PostgreSQL/SQLite use double quotes, with embedded
	 * quote characters escaped by doubling.
	 *
	 * @param string $identifier Validated identifier segment.
	 * @param string $dbms Target DBMS key.
	 * @return string Quoted identifier.
	 */
	private function quoteIdentifier(string $identifier, string $dbms): string {
		$quote=$dbms==='mysql' ? '`' : '"';
		$escaped=$dbms==='mysql' ? '``' : '""';
		return $quote.str_replace($quote, $escaped, $identifier).$quote;
	}

	/**
	 * Converts a literal default value into SQL.
	 *
	 * Booleans use DBMS-appropriate forms, numeric values are emitted directly, and
	 * other values are single-quoted with SQL quote escaping.
	 *
	 * @param mixed $value Literal default value.
	 * @param string $dbms Target DBMS key.
	 * @return string SQL literal.
	 */
	private function literal(mixed $value, string $dbms): string {
		if(is_bool($value)){
			if($dbms==='postgresql'){
				return $value ? 'TRUE' : 'FALSE';
			}
			return $value ? '1' : '0';
		}
		if(is_int($value) || is_float($value)){
			return (string)$value;
		}
		return "'".str_replace("'", "''", (string)$value)."'";
	}

	/**
	 * Returns the active column for chained column modifiers.
	 *
	 * Modifiers such as nullable(), default(), primary(), and cast() require a
	 * preceding column definition and fail fast when the chain is invalid.
	 *
	 * @return string Active column name.
	 */
	private function requireLastColumn(): string {
		if($this->lastColumn===null || !isset($this->columns[$this->lastColumn])){
			throw new \LogicException('No column is available for the requested table definition modifier.');
		}
		return $this->lastColumn;
	}

	/**
	 * Validates SQL identifiers accepted by the table definition builder.
	 *
	 * Plain identifiers are used for columns, constraints, and indexes; dotted
	 * identifiers are accepted only for schema-qualified table names.
	 *
	 * @param string $identifier Raw identifier.
	 * @param bool $allowDot Whether dotted identifiers are allowed.
	 * @return string Validated identifier.
	 */
	private function assertIdentifier(string $identifier, bool $allowDot=false): string {
		$identifier=trim($identifier);
		$pattern=$allowDot ? '/^[A-Za-z_][A-Za-z0-9_\.]*$/' : '/^[A-Za-z_][A-Za-z0-9_]*$/';
		if($identifier==='' || preg_match($pattern, $identifier)!==1){
			throw new \InvalidArgumentException("Invalid SQL identifier: {$identifier}");
		}
		return $identifier;
	}
}
