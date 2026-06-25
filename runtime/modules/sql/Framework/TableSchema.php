<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Runtime schema view for a SQL-backed table definition.
 *
 * `TableSchema` centralizes the table name, known columns, named projections,
 * primary key, and scalar casts used by higher-level SQL helpers. It validates
 * identifiers before they can reach query builders, rejects unknown columns at
 * the schema boundary, and converts read/write values according to declared
 * casts without executing SQL itself except for explicit hydration requests.
 */
final class TableSchema {

	private const READ_DATETIME_CACHE_LIMIT=128;
	private const READ_JSON_CACHE_LIMIT=256;

	private string $table;
	private array $columns;
	private array $columnLookup;
	private array $projections;
	private ?string $primaryKey;
	private array $casts;
	private array $readCastsByType;
	private array $readDateTimeCache=[];
	private array $readJsonCache=[];
	private ?array $lastColumnsInput=null;
	private ?array $lastColumnsResult=null;
	private ?array $lastCastRowsInput=null;
	private ?array $lastCastRowsResult=null;

	/**
	 * Creates a validated table schema snapshot.
	 *
	 * Table and column identifiers must match Dataphyre's conservative SQL
	 * identifier grammar. Projection names are preserved as provided, while their
	 * column lists are normalized, de-duplicated, and checked against the known
	 * columns. Cast declarations are normalized through supported aliases before
	 * any row data is accepted.
	 *
	 * @param string $table SQL table identifier owned by this schema.
	 * @param array<int,string> $columns Column identifiers available for selection and mutation.
	 * @param array<string,array<int,string>> $projections Named column groups for read queries.
	 * @param ?string $primaryKey Known primary-key column, or null when callers provide key metadata elsewhere.
	 * @param array<string,string> $casts Column-to-cast map for read/write value conversion.
	 * @throws SqlError When identifiers, projections, primary key, or casts violate schema rules.
	 */
	public function __construct(string $table, array $columns, array $projections=[], ?string $primaryKey=null, array $casts=[]){
		$this->table=$this->assertIdentifier($table);
		$this->columns=$this->normalizeIdentifiers($columns);
		$this->columnLookup=array_fill_keys($this->columns, true);
		$this->projections=$this->normalizeProjections($projections);
		$this->primaryKey=$primaryKey!==null ? $this->assertKnownColumn($primaryKey) : null;
		$this->casts=$this->normalizeCasts($casts);
		$this->readCastsByType=$this->groupReadCasts($this->casts);
	}

	/**
	 * Returns the schema attached to a table definition.
	 *
	 * This factory lets callers accept a full `TableDefinition` while still
	 * working with the smaller validated schema view.
	 *
	 * @param TableDefinition $definition Table definition that owns the schema.
	 * @return self Schema snapshot produced by the definition.
	 */
	public static function fromDefinition(TableDefinition $definition): self {
		return $definition->schema();
	}

	/**
	 * Returns the validated table identifier.
	 *
	 *
	 * @return string SQL table identifier.
	 */
	public function table(): string {
		return $this->table;
	}

	/**
	 * Returns the schema primary-key column when one was declared.
	 *
	 *
	 * @return ?string Primary-key column name, or null for keyless schemas.
	 */
	public function primaryKey(): ?string {
		return $this->primaryKey;
	}

	/**
	 * Returns every column known to the schema.
	 *
	 *
	 * @return array<int,string> Ordered, de-duplicated column identifiers.
	 */
	public function columnNames(): array {
		return $this->columns;
	}

	/**
	 * Returns named read projections for this table.
	 *
	 *
	 * @return array<string,array<int,string>> Projection names mapped to validated column lists.
	 */
	public function projections(): array {
		return $this->projections;
	}

	/**
	 * Returns normalized read/write cast declarations.
	 *
	 *
	 * @return array<string,string> Column-to-cast map using canonical cast names.
	 */
	public function casts(): array {
		return $this->casts;
	}

	/**
	 * Hydrates the backing table definition through the legacy SQL kernel.
	 *
	 * This is the only method on the schema that intentionally crosses into SQL
	 * runtime state. It delegates to `dataphyre\sql::hydrate_table_definition()`
	 * using the already-validated table name.
	 *
	 * @return bool True when the SQL kernel reports successful table-definition hydration.
	 */
	public function hydrateTable(): bool {
		return \dataphyre\sql::hydrate_table_definition($this->table);
	}

	/**
	 * Normalizes a requested column selector against the known schema columns.
	 *
	 * The wildcard `*` passes through unchanged. A string selector returns one
	 * validated column name, while an array selector returns unique validated
	 * column names in request order.
	 *
	 * @param array<int,string>|string $columns Wildcard, single column, or requested column list.
	 * @return array<int,string>|string Validated selector safe for query construction.
	 * @throws SqlError When any requested column is unknown or has an invalid identifier.
	 */
	public function columns(array|string $columns='*'): array|string {
		if($columns==='*'){
			return '*';
		}
		if(is_string($columns)){
			return $this->assertKnownColumn($columns);
		}
		if($this->lastColumnsInput===$columns && $this->lastColumnsResult!==null){
			return $this->lastColumnsResult;
		}
		$normalized=[];
		foreach($columns as $column){
			$normalized[]=$this->assertKnownColumn((string)$column);
		}
		$this->lastColumnsInput=$columns;
		return $this->lastColumnsResult=array_values(array_unique($normalized));
	}

	/**
	 * Returns a named projection's validated column list.
	 *
	 * Projections are looked up exactly after trimming the requested name. Unknown
	 * projections raise a SQL error that includes the table and available names for
	 * diagnostics.
	 *
	 * @param string $name Projection name to resolve.
	 * @return array<int,string> Validated projection column list.
	 * @throws SqlError When the projection is not declared on this schema.
	 */
	public function projection(string $name): array {
		$name=trim($name);
		if(!isset($this->projections[$name])){
			throw SqlError::unknownProjection($this->table, $name, array_keys($this->projections));
		}
		return $this->projections[$name];
	}

	/**
	 * Validates and casts an associative field map for writes.
	 *
	 * The field map must be non-empty and associative. Each key is checked as a
	 * known schema column before the corresponding value is converted through the
	 * configured write cast. The returned array is safe for mutation builders that
	 * expect trusted column names.
	 *
	 * @param array<string,mixed> $fields Column-value map supplied by a write operation.
	 * @return array<string,mixed> Validated and write-cast field map.
	 * @throws SqlError When the field map is empty, list-like, has unknown columns, or cannot encode a cast value.
	 */
	public function fields(array $fields): array {
		if($fields===[]){
			throw SqlError::invalidFieldPayload("schema {$this->table}", 'Field map cannot be empty.');
		}
		$normalized=[];
		foreach($fields as $column=>$value){
			if(is_int($column)){
				throw SqlError::invalidFieldPayload("schema {$this->table}", 'Field map must be an associative array.');
			}
			$column=$this->assertKnownColumn((string)$column);
			$normalized[$column]=$this->castWriteValue($column, $value);
		}
		return $normalized;
	}

	/**
	 * Applies declared read casts to a single database row.
	 *
	 * Only keys present in the row are converted. Unknown row keys are preserved so
	 * query expressions, joins, and computed aliases can pass through unchanged.
	 *
	 * @param array<string,mixed> $row Database row before read conversion.
	 * @return array<string,mixed> Row with declared cast columns converted for PHP consumption.
	 */
	public function castRow(array $row): array {
		if($this->casts===[]){
			return $row;
		}
		foreach($this->casts as $column=>$cast){
			if(array_key_exists($column, $row)){
				$row[$column]=$this->castReadValue($cast, $row[$column]);
			}
		}
		return $row;
	}

	/**
	 * Applies declared read casts to every array row in a result set.
	 *
	 * Non-array entries are left untouched, allowing callers to pass mixed result
	 * envelopes without losing metadata.
	 *
	 * @param array<int|string,mixed> $rows Result rows or a mixed result envelope.
	 * @return array<int|string,mixed> Result data with array rows cast in place.
	 */
	public function castRows(array $rows): array {
		if($this->casts===[]){
			return $rows;
		}
		$cacheable=$this->isCacheableTree($rows);
		if($cacheable && $this->lastCastRowsInput===$rows && $this->lastCastRowsResult!==null){
			return $this->lastCastRowsResult;
		}
		$inputRows=$cacheable ? $rows : null;
		$readCastsByType=$this->readCastsByType;
		foreach($rows as $key=>$row){
			if(!is_array($row)){
				continue;
			}
			foreach($readCastsByType['string'] as $column){
				if(array_key_exists($column, $row)){
					$row[$column]=$row[$column] === null ? null : (string)$row[$column];
				}
			}
			foreach($readCastsByType['int'] as $column){
				if(array_key_exists($column, $row)){
					$value=$row[$column];
					$row[$column]=$value === null ? null : (is_numeric($value) ? (int)$value : $value);
				}
			}
			foreach($readCastsByType['float'] as $column){
				if(array_key_exists($column, $row)){
					$value=$row[$column];
					$row[$column]=$value === null ? null : (is_numeric($value) ? (float)$value : $value);
				}
			}
			foreach($readCastsByType['bool'] as $column){
				if(array_key_exists($column, $row)){
					$row[$column]=$row[$column] === null ? null : $this->readBooleanValue($row[$column]);
				}
			}
			foreach($readCastsByType['json'] as $column){
				if(array_key_exists($column, $row)){
					$row[$column]=$row[$column] === null ? null : $this->readJsonValue($row[$column]);
				}
			}
			foreach($readCastsByType['datetime'] as $column){
				if(array_key_exists($column, $row)){
					$row[$column]=$row[$column] === null ? null : $this->readDateTimeValue($row[$column]);
				}
			}
			$rows[$key]=$row;
		}
		if($cacheable){
			$this->lastCastRowsInput=$inputRows;
			$this->lastCastRowsResult=$rows;
		}
		return $rows;
	}

	/**
	 * Normalizes projection definitions into known column lists.
	 *
	 * Non-array projection definitions are ignored, which lets higher-level config
	 * loaders pass partially merged data without failing the whole schema. Array
	 * definitions are validated column-by-column.
	 *
	 * @param array<string,mixed> $projections Raw projection map.
	 * @return array<string,array<int,string>> Validated projection map.
	 * @throws SqlError When a projection references an invalid or unknown column.
	 */
	private function normalizeProjections(array $projections): array {
		$normalized=[];
		foreach($projections as $name=>$columns){
			if(!is_array($columns)){
				continue;
			}
			$normalized[(string)$name]=array_values(array_unique(array_map(
				fn(string $column): string => $this->assertKnownColumn($column),
				$this->normalizeIdentifiers($columns)
			)));
		}
		return $normalized;
	}

	/**
	 * Normalizes and de-duplicates SQL identifiers.
	 *
	 * Identifier order is preserved after de-duplication so projections and column
	 * selectors remain deterministic for generated SQL and documentation.
	 *
	 * @param array<int|string,mixed> $identifiers Raw identifier list.
	 * @return array<int,string> Validated unique identifiers.
	 * @throws SqlError When any identifier violates the schema identifier grammar.
	 */
	private function normalizeIdentifiers(array $identifiers): array {
		$normalized=[];
		foreach($identifiers as $identifier){
			$identifier=$this->assertIdentifier((string)$identifier);
			$normalized[]=$identifier;
		}
		return array_values(array_unique($normalized));
	}

	/**
	 * Normalizes cast declarations to the supported canonical cast names.
	 *
	 * Aliases such as `integer`, `boolean`, `array`, and `timestamp` are accepted
	 * for ergonomics, but the stored map only contains canonical names consumed by
	 * the read/write cast functions.
	 *
	 * @param array<string,mixed> $casts Raw column-to-cast declaration map.
	 * @return array<string,string> Validated cast declarations keyed by known column.
	 * @throws SqlError When a cast targets an unknown column or names an unsupported conversion.
	 */
	private function normalizeCasts(array $casts): array {
		$normalized=[];
		foreach($casts as $column=>$cast){
			$column=$this->assertKnownColumn((string)$column);
			$cast=strtolower(trim((string)$cast));
			$aliases=[
				'integer'=>'int',
				'double'=>'float',
				'real'=>'float',
				'boolean'=>'bool',
				'array'=>'json',
				'object'=>'json',
				'json_array'=>'json',
				'timestamp'=>'datetime',
				'date_time'=>'datetime',
				'datetimeimmutable'=>'datetime',
			];
			$cast=$aliases[$cast] ?? $cast;
			if(!in_array($cast, ['string', 'int', 'float', 'bool', 'json', 'datetime'], true)){
				throw SqlError::invalidFieldPayload("schema {$this->table}", "Unsupported cast '{$cast}' for column '{$column}'.");
			}
			$normalized[$column]=$cast;
		}
		return $normalized;
	}

	/**
	 * Groups read casts by conversion type for bulk row casting.
	 *
	 * @param array<string,string> $casts Normalized column-to-cast map.
	 * @return array<string, array<int,string>> Cast columns keyed by canonical cast type.
	 */
	private function groupReadCasts(array $casts): array {
		$groups=[
			'string'=>[],
			'int'=>[],
			'float'=>[],
			'bool'=>[],
			'json'=>[],
			'datetime'=>[],
		];
		foreach($casts as $column=>$cast){
			$groups[$cast][]=$column;
		}
		return $groups;
	}

	/**
	 * Checks whether a result tree can be cached by exact value.
	 *
	 * @param array<int|string,mixed> $values Candidate row/result tree.
	 * @return bool True when the tree contains only scalar, null, and array values.
	 */
	private function isCacheableTree(array $values): bool {
		foreach($values as $value){
			if(is_array($value)){
				if(!$this->isCacheableTree($value)){
					return false;
				}
				continue;
			}
			if($value!==null && !is_scalar($value)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Converts a write value according to a column's configured cast.
	 *
	 * Null values and columns without casts pass through unchanged. Numeric casts
	 * only convert numeric input; non-numeric values are preserved so validation
	 * layers above the schema can decide whether to reject them.
	 *
	 * @param string $column Known schema column being written.
	 * @param mixed $value Application value before SQL binding.
	 * @return mixed storage value after configured string, numeric, boolean, JSON, or datetime cast rules are applied.
	 * @throws SqlError When JSON encoding fails for a JSON-cast column.
	 */
	private function castWriteValue(string $column, mixed $value): mixed {
		if($value===null || !isset($this->casts[$column])){
			return $value;
		}
		return match($this->casts[$column]){
			'string'=>(string)$value,
			'int'=>is_numeric($value) ? (int)$value : $value,
			'float'=>is_numeric($value) ? (float)$value : $value,
			'bool'=>$this->writeBooleanValue($value),
			'json'=>$this->writeJsonValue($column, $value),
			'datetime'=>$this->writeDateTimeValue($value),
			default=>$value,
		};
	}

	/**
	 * Converts a read value according to a canonical cast name.
	 *
	 * Read conversion favors lossless behavior: unsupported numeric input remains
	 * unchanged, invalid JSON remains the original string, and invalid datetime
	 * strings remain the original value.
	 *
	 * @param string $cast Canonical cast name from `normalizeCasts()`.
	 * @param mixed $value Database value before PHP conversion.
	 * @return mixed application value after configured string, numeric, boolean, JSON, or datetime read conversion.
	 */
	private function castReadValue(string $cast, mixed $value): mixed {
		if($value===null){
			return null;
		}
		return match($cast){
			'string'=>(string)$value,
			'int'=>is_numeric($value) ? (int)$value : $value,
			'float'=>is_numeric($value) ? (float)$value : $value,
			'bool'=>$this->readBooleanValue($value),
			'json'=>$this->readJsonValue($value),
			'datetime'=>$this->readDateTimeValue($value),
			default=>$value,
		};
	}

	/**
	 * Converts a database boolean representation to a PHP boolean.
	 *
	 * Numeric zero is false, non-zero numeric values are true, and common textual
	 * true tokens are recognized case-insensitively.
	 *
	 * @param mixed $value Database value from a bool-cast column.
	 * @return bool PHP boolean representation.
	 */
	private function readBooleanValue(mixed $value): bool {
		if(is_bool($value)){
			return $value;
		}
		if(is_numeric($value)){
			return (int)$value!==0;
		}
		return in_array(strtolower(trim((string)$value)), ['1', 'true', 't', 'yes', 'y', 'on'], true);
	}

	/**
	 * Converts an application boolean-like value for SQL storage.
	 *
	 * Common textual false and true tokens are recognized before falling back to
	 * PHP truthiness, keeping submitted form values predictable for bool-cast columns.
	 *
	 * @param mixed $value Application value before storage.
	 * @return bool Boolean value to bind for storage.
	 */
	private function writeBooleanValue(mixed $value): bool {
		if(is_bool($value)){
			return $value;
		}
		if(is_numeric($value)){
			return (int)$value!==0;
		}
		if(is_string($value)){
			$normalized=strtolower(trim($value));
			if(in_array($normalized, ['0', 'false', 'f', 'no', 'n', 'off'], true)){
				return false;
			}
			if(in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'on'], true)){
				return true;
			}
		}
		return (bool)$value;
	}

	/**
	 * Decodes a database JSON value for application reads.
	 *
	 * Non-string values pass through unchanged, blank strings become null, and
	 * invalid JSON remains the original string to avoid destructive read casts.
	 *
	 * @param mixed $value Database value from a JSON-cast column.
	 * @return mixed Decoded JSON value, null for blanks, or original value on decode failure.
	 */
	private function readJsonValue(mixed $value): mixed {
		if(!is_string($value)){
			return $value;
		}
		if(array_key_exists($value, $this->readJsonCache)){
			return $this->readJsonCache[$value];
		}
		$trimmed=trim($value);
		if($trimmed===''){
			return null;
		}
		$decoded=json_decode($trimmed, true);
		$decoded=json_last_error()===JSON_ERROR_NONE ? $decoded : $value;
		if(count($this->readJsonCache)>=self::READ_JSON_CACHE_LIMIT){
			$this->readJsonCache=[];
		}
		$this->readJsonCache[$value]=$decoded;
		return $decoded;
	}

	/**
	 * Encodes an application JSON value for SQL storage.
	 *
	 * Existing strings are assumed to be caller-supplied JSON and pass through.
	 * Arrays, objects, and scalars are encoded without escaping Unicode or slashes
	 * so stored values remain human-readable.
	 *
	 * @param string $column Column being encoded, used for error context.
	 * @param mixed $value Application value before JSON storage.
	 * @return string JSON string ready for storage.
	 * @throws SqlError When PHP cannot encode the value as JSON.
	 */
	private function writeJsonValue(string $column, mixed $value): string {
		if(is_string($value)){
			return $value;
		}
		$encoded=json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if($encoded===false){
			throw SqlError::invalidFieldPayload("schema {$this->table}", "JSON cast failed for column '{$column}': ".json_last_error_msg());
		}
		return $encoded;
	}

	/**
	 * Converts a database datetime representation for application reads.
	 *
	 * Existing DateTime objects pass through, numeric values become timestamped
	 * `DateTimeImmutable` instances, parseable strings become immutable datetimes,
	 * blanks become null, and unparsable values remain unchanged.
	 *
	 * @param mixed $value Database value from a datetime-cast column.
	 * @return mixed DateTimeInterface, null, or original value when conversion is unsafe.
	 */
	private function readDateTimeValue(mixed $value): mixed {
		if($value instanceof \DateTimeInterface){
			return $value;
		}
		if(is_int($value) || is_float($value) || is_string($value)){
			$cacheKey=get_debug_type($value).':'.$value;
			if(isset($this->readDateTimeCache[$cacheKey])){
				return $this->readDateTimeCache[$cacheKey];
			}
		}
		try{
			if(is_numeric($value)){
				return $this->rememberReadDateTime($cacheKey ?? null, (new \DateTimeImmutable())->setTimestamp((int)$value));
			}
			$value=trim((string)$value);
			return $value!=='' ? $this->rememberReadDateTime($cacheKey ?? null, new \DateTimeImmutable($value)) : null;
		}catch(\Throwable){
			return $value;
		}
	}

	/**
	 * Stores a parsed read datetime in the bounded per-schema cache.
	 *
	 * @param string|null $key Exact scalar input cache key, or null for uncached input.
	 * @param \DateTimeImmutable $value Parsed immutable datetime value.
	 * @return \DateTimeImmutable Parsed value.
	 */
	private function rememberReadDateTime(?string $key, \DateTimeImmutable $value): \DateTimeImmutable {
		if($key===null){
			return $value;
		}
		if(count($this->readDateTimeCache)>=self::READ_DATETIME_CACHE_LIMIT){
			$this->readDateTimeCache=[];
		}
		$this->readDateTimeCache[$key]=$value;
		return $value;
	}

	/**
	 * Converts an application datetime representation for SQL storage.
	 *
	 * DateTime values are formatted as `Y-m-d H:i:s`, numeric timestamps are
	 * formatted through PHP's default timezone, and other values pass through for
	 * caller-side validation or database handling.
	 *
	 * @param mixed $value Application value before datetime storage.
	 * @return mixed SQL datetime string for DateTimeInterface/numeric input, or the original value for caller/database validation.
	 */
	private function writeDateTimeValue(mixed $value): mixed {
		if($value instanceof \DateTimeInterface){
			return $value->format('Y-m-d H:i:s');
		}
		if(is_numeric($value)){
			return date('Y-m-d H:i:s', (int)$value);
		}
		return $value;
	}

	/**
	 * Validates that a column exists in this schema.
	 *
	 * @param string $column Column identifier to validate.
	 * @return string Validated known column identifier.
	 * @throws SqlError When the identifier is invalid or not declared on this schema.
	 */
	private function assertKnownColumn(string $column): string {
		$column=$this->assertIdentifier($column);
		if(!isset($this->columnLookup[$column])){
			throw SqlError::unknownColumn($this->table, $column, $this->columns);
		}
		return $column;
	}

	/**
	 * Validates a SQL identifier used by schema-level helpers.
	 *
	 * Identifiers may contain letters, digits, underscores, and dots, and must
	 * start with a letter or underscore. This method does not quote identifiers;
	 * it only enforces the safe identifier grammar used before query construction.
	 *
	 * @param string $identifier Raw identifier candidate.
	 * @return string Trimmed validated identifier.
	 * @throws SqlError When the identifier is blank or outside the allowed grammar.
	 */
	private function assertIdentifier(string $identifier): string {
		$identifier=trim($identifier);
		if($identifier==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $identifier)!==1){
			throw SqlError::invalidIdentifier('schema', $identifier, $this->table);
		}
		return $identifier;
	}
}
