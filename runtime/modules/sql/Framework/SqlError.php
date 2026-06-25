<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Factory for stable SQL framework exceptions and diagnostic messages.
 *
 * SqlError keeps repository, table, hydrator, relation, aggregate, money,
 * temporal, transaction, and mutation failures in one consistent format with
 * titles, summaries, structured context, and remediation hints.
 */
final class SqlError {

	/**
	 * Creates an exception for a SQL cluster that is absent from datacenter configuration.
	 *
	 * @param string $cluster Requested SQL cluster name.
	 * @param list<string> $knownClusters Configured cluster names available in the current datacenter.
	 * @param ?string $datacenter Datacenter used while resolving SQL configuration.
	 * @return \RuntimeException RuntimeException containing a stable SQL failure title, context, and hint.
	 */
	public static function unknownCluster(string $cluster, array $knownClusters, ?string $datacenter=null): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL framework configuration error',
			"Unknown SQL cluster '{$cluster}'.",
			[
				'cluster'=>$cluster,
				'known_clusters'=>$knownClusters,
				'datacenter'=>$datacenter,
			],
			$knownClusters===[]
				? 'No SQL clusters are configured for the current datacenter.'
				: 'Use one of the configured cluster names, or call DB::clusters() to inspect them at runtime.'
		));
	}

	/**
	 * Creates an exception when a repository query target does not extend TableRepository.
	 *
	 * @param string $repositoryClass Repository class name being validated.
	 * @return \RuntimeException RuntimeException containing a stable SQL failure title, context, and hint.
	 */
	public static function invalidRepositoryClass(string $repositoryClass): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL repository error',
			"Repository query target '{$repositoryClass}' is not a valid TableRepository class.",
			[
				'repository_class'=>$repositoryClass,
				'expected_base_class'=>TableRepository::class,
			],
			'Instantiate RepositoryQuery with a concrete repository that extends Dataphyre\\Database\\TableRepository.'
		));
	}

	/**
	 * Creates an exception for record/table operations that require primary-key metadata.
	 *
	 * @param string $repositoryClass Repository class name being validated.
	 * @param string $operation Repository, record, mutation, or transaction operation that failed.
	 * @return \RuntimeException RuntimeException containing a stable SQL failure title, context, and hint.
	 */
	public static function missingPrimaryKeyForRepository(string $repositoryClass, string $operation): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL repository error',
			"{$repositoryClass} cannot {$operation} because it does not declare a primary key.",
			[
				'repository_class'=>$repositoryClass,
				'operation'=>$operation,
			],
			'Declare the primary key in the repository schema, for example new TableSchema(..., ..., ..., \'id\').'
		));
	}

	/**
	 * Creates an exception for record/table operations that require primary-key metadata.
	 *
	 * @param string $table Logical or physical table name involved in the failure.
	 * @param string $operation Repository, record, mutation, or transaction operation that failed.
	 * @return \RuntimeException RuntimeException containing a stable SQL failure title, context, and hint.
	 */
	public static function missingPrimaryKeyForTable(string $table, string $operation): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL table query error',
			"Table '{$table}' cannot {$operation} because no primary key is known.",
			[
				'table'=>$table,
				'operation'=>$operation,
			],
			'Pass the primary key into DB::table($table, $primaryKey), use TableQuery::usingPrimaryKey(...), or attach a TableSchema.'
		));
	}

	/**
	 * Creates an exception for projection lookup without a table schema.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param string $projectionName Named projection requested from a table schema.
	 * @param ?string $table Logical or physical table name involved in the failure.
	 * @return \RuntimeException RuntimeException containing a stable SQL failure title, context, and hint.
	 */
	public static function missingSchemaForProjection(string $owner, string $projectionName, ?string $table=null): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL projection error',
			"Cannot resolve projection '{$projectionName}' because no table schema is available.",
			[
				'owner'=>$owner,
				'table'=>$table,
				'projection'=>$projectionName,
			],
			'Declare a TableSchema with named projections, or select columns explicitly.'
		));
	}

	/**
	 * Creates an exception for invalid or missing record hydrator configuration.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param mixed $hydrator Hydrator value supplied by schema or repository configuration.
	 * @return \RuntimeException RuntimeException containing a stable SQL failure title, context, and hint.
	 */
	public static function invalidHydrator(string $owner, mixed $hydrator): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL hydration error',
			'The configured record hydrator is invalid.',
			[
				'owner'=>$owner,
				'hydrator_type'=>get_debug_type($hydrator),
				'hydrator_value'=>is_scalar($hydrator) ? $hydrator : get_debug_type($hydrator),
			],
			'Use a RecordHydrator instance, a callable, a hydrator class name, or a record class name.'
		));
	}

	/**
	 * Creates an exception for malformed relation metadata or relation factory methods.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param string $relation Relation name requested from repository or record metadata.
	 * @param string $detail Human-readable detail explaining the invalid configuration or operation.
	 * @return \RuntimeException RuntimeException containing a stable SQL failure title, context, and hint.
	 */
	public static function invalidRelation(string $owner, string $relation, string $detail): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL relation error',
			$detail,
			[
				'owner'=>$owner,
				'relation'=>$relation,
			],
			'Pass a Relation object, or define a public static repository method with no required parameters that returns a Relation.'
		));
	}

	/**
	 * Creates an exception for record helpers that cannot run with the current metadata.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param string $operation Repository, record, mutation, or transaction operation that failed.
	 * @param string $detail Human-readable detail explaining the invalid configuration or operation.
	 * @return \RuntimeException RuntimeException containing a stable SQL failure title, context, and hint.
	 */
	public static function recordOperationUnavailable(string $owner, string $operation, string $detail): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL record operation error',
			$detail,
			[
				'owner'=>$owner,
				'operation'=>$operation,
			],
			'Use a repository-backed record with primary-key metadata, or call the repository helper directly.'
		));
	}

	/**
	 * Creates an exception for invalid or missing record hydrator configuration.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param string $className Hydrator or record class name expected to exist.
	 * @return \RuntimeException RuntimeException containing a stable SQL failure title, context, and hint.
	 */
	public static function missingHydratorClass(string $owner, string $className): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL hydration error',
			"Hydrator or record class '{$className}' could not be loaded.",
			[
				'owner'=>$owner,
				'class'=>$className,
			],
			'Check the namespace, autoload path, and class name. If this is a repository record, convention-first lookup expects ...\\Record\\<Entity>Record.'
		));
	}

	/**
	 * Creates an exception for malformed mutation field maps or counter increments.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param string $detail Human-readable detail explaining the invalid configuration or operation.
	 * @return \InvalidArgumentException InvalidArgumentException containing a stable SQL validation title, context, and hint.
	 */
	public static function invalidFieldPayload(string $owner, string $detail): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL field validation error',
			$detail,
			['owner'=>$owner],
			'Pass a non-empty associative array of column => value pairs.'
		));
	}

	/**
	 * Creates an exception for malformed mutation field maps or counter increments.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param string $column Column name involved in field, aggregate, money, or unknown-column validation.
	 * @param int|float $amount Counter or temporal window amount supplied by caller.
	 * @return \InvalidArgumentException InvalidArgumentException containing a stable SQL validation title, context, and hint.
	 */
	public static function invalidCounterAmount(string $owner, string $column, int|float $amount): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL counter error',
			"Counter amount for '{$column}' must be a finite number greater than or equal to zero.",
			[
				'owner'=>$owner,
				'column'=>$column,
				'amount'=>$amount,
			],
			'Use increment($column, $amount) or decrement($column, $amount) with a positive integer or float.'
		));
	}

	/**
	 * Creates an exception that blocks update/delete operations without a scope.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param string $operation Repository, record, mutation, or transaction operation that failed.
	 * @return \RuntimeException RuntimeException containing a stable SQL failure title, context, and hint.
	 */
	public static function unscopedMutation(string $owner, string $operation): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL mutation scope error',
			"Refusing to {$operation} without a WHERE clause.",
			[
				'owner'=>$owner,
				'operation'=>$operation,
			],
			'Add a where_* filter, call allow_unscoped_write()/allowUnscopedWrite() for an intentional table-wide mutation, or disable the repository write-scope policy.'
		));
	}

	/**
	 * Creates an exception for unsafe table, column, alias, projection, or relation identifiers.
	 *
	 * @param string $scope Identifier scope such as table, column, alias, projection, or relation.
	 * @param string $identifier Identifier value rejected by SQL validation.
	 * @param ?string $table Logical or physical table name involved in the failure.
	 * @return \InvalidArgumentException InvalidArgumentException containing a stable SQL validation title, context, and hint.
	 */
	public static function invalidIdentifier(string $scope, string $identifier, ?string $table=null): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL identifier error',
			"Invalid {$scope} identifier '{$identifier}'.",
			[
				'scope'=>$scope,
				'table'=>$table,
				'identifier'=>$identifier,
			],
			'Identifiers must start with a letter or underscore and contain only letters, numbers, underscores, or dots.'
		));
	}

	/**
	 * Creates an exception for invalid aggregate function or aggregate column requests.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param string $function Aggregate function name requested by caller.
	 * @param list<string> $allowedFunctions Allowed aggregate functions for the query surface.
	 * @return \InvalidArgumentException InvalidArgumentException containing a stable SQL validation title, context, and hint.
	 */
	public static function invalidAggregateFunction(string $owner, string $function, array $allowedFunctions): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL aggregate error',
			"Unsupported aggregate function '{$function}'.",
			[
				'owner'=>$owner,
				'function'=>$function,
				'allowed_functions'=>$allowedFunctions,
			],
			'Use one of the supported aggregate helpers such as sum(...), avg(...), min(...), max(...), countColumn(...), or countDistinct(...).'
		));
	}

	/**
	 * Creates an exception for invalid aggregate function or aggregate column requests.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param string $function Aggregate function name requested by caller.
	 * @param string $column Column name involved in field, aggregate, money, or unknown-column validation.
	 * @param bool $allowStar Whether * is accepted as an aggregate column.
	 * @return \InvalidArgumentException InvalidArgumentException containing a stable SQL validation title, context, and hint.
	 */
	public static function invalidAggregateColumn(string $owner, string $function, string $column, bool $allowStar=false): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL aggregate error',
			"Aggregate {$function}(...) cannot target column '{$column}'.",
			[
				'owner'=>$owner,
				'function'=>$function,
				'column'=>$column,
				'allow_star'=>$allowStar,
			],
			$allowStar
				? 'Use a valid schema column name or * when the aggregate explicitly allows it.'
				: 'Use a valid schema column name. For row counts, use count() instead of passing * into a column aggregate.'
		));
	}

	/**
	 * Creates an exception when a database feature depends on an unloaded framework module.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param string $module Framework module required for the operation.
	 * @param string $operation Repository, record, mutation, or transaction operation that failed.
	 * @return \RuntimeException RuntimeException containing a stable SQL failure title, context, and hint.
	 */
	public static function missingFrameworkModule(string $owner, string $module, string $operation): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL framework dependency error',
			"{$operation} requires the Dataphyre {$module} framework module.",
			[
				'owner'=>$owner,
				'module'=>$module,
			],
			"Load it explicitly with \\dataphyre\\core::load_framework_module('{$module}') before using the SQL integration helpers."
		));
	}

	/**
	 * Creates an exception for invalid money metadata, comparison, or required money columns.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param string $detail Human-readable detail explaining the invalid configuration or operation.
	 * @return \InvalidArgumentException InvalidArgumentException containing a stable SQL validation title, context, and hint.
	 */
	public static function invalidMoneyDefinition(string $owner, string $detail): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL money integration error',
			$detail,
			[
				'owner'=>$owner,
			],
			'Use asMoney(...), asMoneyIn(...), asStoredMoney(...), and the whereMoney... helpers with explicit amount, currency, and stored-money column definitions.'
		));
	}

	/**
	 * Creates an exception for invalid money metadata, comparison, or required money columns.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param string $column Column name involved in field, aggregate, money, or unknown-column validation.
	 * @param string $role Money column role, such as amount, currency, or base amount.
	 * @param list<string> $availableColumns Known columns available for the money definition.
	 * @return \RuntimeException RuntimeException containing a stable SQL failure title, context, and hint.
	 */
	public static function missingMoneyColumn(string $owner, string $column, string $role, array $availableColumns): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL money integration error',
			"Money hydration could not find the {$role} column '{$column}' in the selected row.",
			[
				'owner'=>$owner,
				'column'=>$column,
				'role'=>$role,
				'available_columns'=>$availableColumns,
			],
			'Include the required money or stored-money columns in the query projection, or use asMoneyIn(..., $currency) when the stored currency is fixed.'
		));
	}

	/**
	 * Creates an exception for invalid money metadata, comparison, or required money columns.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param string $amountColumn Column name involved in field, aggregate, money, or unknown-column validation.
	 * @param string $detail Human-readable detail explaining the invalid configuration or operation.
	 * @param ?string $hint Optional remediation hint appended to the formatted message.
	 * @return \InvalidArgumentException InvalidArgumentException containing a stable SQL validation title, context, and hint.
	 */
	public static function invalidMoneyComparison(string $owner, string $amountColumn, string $detail, ?string $hint=null): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL money comparison error',
			$detail,
			[
				'owner'=>$owner,
				'amount_column'=>$amountColumn,
			],
			$hint
		));
	}

	/**
	 * Creates an exception for invalid temporal values or temporal window definitions.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param mixed $value Temporal or diagnostic value being formatted.
	 * @param ?string $hint Optional remediation hint appended to the formatted message.
	 * @return \InvalidArgumentException InvalidArgumentException containing a stable SQL validation title, context, and hint.
	 */
	public static function invalidTemporalValue(string $owner, mixed $value, ?string $hint=null): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL temporal filter error',
			'The temporal value could not be normalized into a comparable SQL value.',
			[
				'owner'=>$owner,
				'value_type'=>get_debug_type($value),
				'value'=>is_scalar($value) ? (string)$value : get_debug_type($value),
			],
			$hint ?? 'Use a non-empty datetime string, a unix timestamp integer, or a DateTimeInterface instance.'
		));
	}

	/**
	 * Creates an exception for invalid temporal values or temporal window definitions.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param string $unit Temporal window unit.
	 * @param int $amount Counter or temporal window amount supplied by caller.
	 * @return \InvalidArgumentException InvalidArgumentException containing a stable SQL validation title, context, and hint.
	 */
	public static function invalidTemporalWindow(string $owner, string $unit, int $amount): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL temporal filter error',
			"Relative {$unit} windows must be greater than zero.",
			[
				'owner'=>$owner,
				'unit'=>$unit,
				'amount'=>$amount,
			],
			'Pass a positive number of minutes, hours, or days when using inLastMinutes(...), inLastHours(...), or inLastDays(...).'
		));
	}

	/**
	 * Creates an exception when requested schema metadata is not known for a table.
	 *
	 * @param string $table Logical or physical table name involved in the failure.
	 * @param string $column Column name involved in field, aggregate, money, or unknown-column validation.
	 * @param list<string> $knownColumns Known columns for the table.
	 * @return \InvalidArgumentException InvalidArgumentException containing a stable SQL validation title, context, and hint.
	 */
	public static function unknownColumn(string $table, string $column, array $knownColumns): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL schema error',
			"Unknown column '{$column}' for table '{$table}'.",
			[
				'table'=>$table,
				'column'=>$column,
				'known_columns'=>$knownColumns,
			],
			'Use one of the declared schema columns, or update the TableSchema if the table definition changed.'
		));
	}

	/**
	 * Creates an exception when requested schema metadata is not known for a table.
	 *
	 * @param string $table Logical or physical table name involved in the failure.
	 * @param string $projectionName Named projection requested from a table schema.
	 * @param list<string> $knownProjections Known projections for the table.
	 * @return \InvalidArgumentException InvalidArgumentException containing a stable SQL validation title, context, and hint.
	 */
	public static function unknownProjection(string $table, string $projectionName, array $knownProjections): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL schema error',
			"Unknown projection '{$projectionName}' for table '{$table}'.",
			[
				'table'=>$table,
				'projection'=>$projectionName,
				'known_projections'=>$knownProjections,
			],
			'Check the projection name spelling or add the projection to the TableSchema definition.'
		));
	}

	/**
	 * Creates or classifies transaction exceptions, including transient retryable failures.
	 *
	 * @param string $summary Short transaction failure summary.
	 * @param ?string $cluster Requested SQL cluster name.
	 * @param ?string $hint Optional remediation hint appended to the formatted message.
	 * @param Throwable $previous Previous throwable chained into a transaction exception.
	 * @return TransactionException TransactionException carrying cluster, summary, hint, and previous throwable details.
	 */
	public static function transactionException(string $summary, ?string $cluster=null, ?string $hint=null, ?\Throwable $previous=null): TransactionException {
		return new TransactionException(
			self::format(
				'SQL transaction error',
				$summary,
				['cluster'=>$cluster],
				$hint
			),
			0,
			$previous
		);
	}

	/**
	 * Creates or classifies transaction exceptions, including transient retryable failures.
	 *
	 * @param Throwable $exception Throwable inspected for transient transaction semantics.
	 * @return bool True when the throwable represents a transient transaction failure that may be retried.
	 */
	public static function isTransientTransactionException(\Throwable $exception): bool {
		for($current=$exception; $current!==null; $current=$current->getPrevious()){
			$code=(string)$current->getCode();
			if(in_array($code, ['40001', '40P01', '1205', '1213', '5', '6'], true)){
				return true;
			}
			$message=strtolower($current->getMessage());
			foreach([
				'deadlock',
				'lock wait timeout',
				'could not serialize access',
				'serialization failure',
				'serialization_failure',
				'retry transaction',
				'database is locked',
				'database table is locked',
				'sqlite_busy',
				'sqlite_locked',
				'too much contention',
			] as $needle){
				if(str_contains($message, $needle)){
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Creates domain exceptions for not-found, too-many, or optimistic-lock mutation failures.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param array<string,mixed> $context Structured context embedded into the exception message.
	 * @param ?string $message Override message for domain-specific exception text.
	 * @param ?string $hint Optional remediation hint appended to the formatted message.
	 * @return RecordNotFoundException Domain mutation exception with structured operation context.
	 */
	public static function recordNotFound(string $owner, array $context=[], ?string $message=null, ?string $hint=null): RecordNotFoundException {
		$summary=$message!==null && trim($message)!==''
			? trim($message)
			: 'The requested record was not found.';
		return new RecordNotFoundException(
			self::format(
				'SQL record not found',
				$summary,
				array_merge(['owner'=>$owner], $context),
				$hint ?? 'Use the nullable first()/find() path when missing records are expected, or check the query filters and primary key.'
			),
			array_merge(['owner'=>$owner], $context)
		);
	}

	/**
	 * Creates domain exceptions for not-found, too-many, or optimistic-lock mutation failures.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param array<string,mixed> $context Structured context embedded into the exception message.
	 * @param ?string $message Override message for domain-specific exception text.
	 * @param ?string $hint Optional remediation hint appended to the formatted message.
	 * @return MultipleRecordsFoundException Domain mutation exception with structured operation context.
	 */
	public static function multipleRecordsFound(string $owner, array $context=[], ?string $message=null, ?string $hint=null): MultipleRecordsFoundException {
		$summary=$message!==null && trim($message)!==''
			? trim($message)
			: 'The query expected exactly one record but matched multiple records.';
		return new MultipleRecordsFoundException(
			self::format(
				'SQL multiple records found',
				$summary,
				array_merge(['owner'=>$owner], $context),
				$hint ?? 'Tighten the query filters, use a primary key, or fall back to get()/all() when multiple matches are valid.'
			),
			array_merge(['owner'=>$owner], $context)
		);
	}

	/**
	 * Creates domain exceptions for not-found, too-many, or optimistic-lock mutation failures.
	 *
	 * @param string $owner Repository, record, table query, or subsystem that owns the failing operation.
	 * @param array<string,mixed> $context Structured context embedded into the exception message.
	 * @param ?string $message Override message for domain-specific exception text.
	 * @param ?string $hint Optional remediation hint appended to the formatted message.
	 * @return OptimisticLockException Domain mutation exception with structured operation context.
	 */
	public static function optimisticLockConflict(string $owner, array $context=[], ?string $message=null, ?string $hint=null): OptimisticLockException {
		$summary=$message!==null && trim($message)!==''
			? trim($message)
			: 'The optimistic write did not match the expected row version.';
		return new OptimisticLockException(
			self::format(
				'SQL optimistic lock conflict',
				$summary,
				array_merge(['owner'=>$owner], $context),
				$hint ?? 'Reload the row, compare the current values, and retry the write with the new version if it is still valid.'
			),
			array_merge(['owner'=>$owner], $context)
		);
	}

	/**
	 * Formats a mutation failure message from operation, context, and hint.
	 *
	 * @param string $operation Repository, record, mutation, or transaction operation that failed.
	 * @param array<string,mixed> $context Structured context embedded into the exception message.
	 * @param ?string $hint Optional remediation hint appended to the formatted message.
	 * @return string Formatted diagnostic message or compact value representation.
	 */
	public static function mutationErrorMessage(string $operation, array $context=[], ?string $hint=null): string {
		return self::format(
			'SQL mutation failed',
			'The SQL mutation returned false or null.',
			array_merge(['operation'=>$operation], $context),
			$hint ?? 'Check the SQL query logs for the underlying engine error details.'
		);
	}

	/**
	 * Builds the canonical multi-line SQL diagnostic message.
	 *
	 * The first line is always a stable title and summary. Non-empty context values
	 * are rendered as bullet lines, and a non-blank hint is appended last so callers
	 * can expose actionable remediation without changing exception classes.
	 *
	 * @param string $title Diagnostic category shown before the summary.
	 * @param string $summary Human-readable failure summary.
	 * @param array<string,mixed> $context Structured context values to include when non-empty.
	 * @param ?string $hint Optional remediation text.
	 * @return string Stable exception message used by SQL framework errors.
	 */
	private static function format(string $title, string $summary, array $context=[], ?string $hint=null): string {
		$lines=["{$title}: {$summary}"];
		$contextLines=self::contextLines($context);
		if($contextLines!==[]){
			$lines[]='Context:';
			foreach($contextLines as $line){
				$lines[]='- '.$line;
			}
		}
		if($hint!==null && trim($hint)!==''){
			$lines[]='Hint: '.trim($hint);
		}
		return implode("\n", $lines);
	}

	/**
	 * Normalizes structured diagnostic context into displayable key/value lines.
	 *
	 * Nulls, empty arrays, empty strings, and blank keys are intentionally omitted
	 * so exception messages stay compact while preserving meaningful runtime
	 * details such as table names, owners, allowed values, and operation metadata.
	 *
	 * @param array<string,mixed> $context Structured context provided by a SQL error factory.
	 * @return array<int, string> Formatted context lines without bullet prefixes.
	 */
	private static function contextLines(array $context): array {
		$lines=[];
		foreach($context as $key=>$value){
			if($value===null || $value===[] || $value===''){
				continue;
			}
			$key=trim((string)$key);
			if($key===''){
				continue;
			}
			$lines[]=$key.': '.self::stringify($value);
		}
		return $lines;
	}

	/**
	 * Converts arbitrary context values into compact diagnostic fragments.
	 *
	 * Scalars and booleans keep their visible values, arrays are recursively
	 * flattened in insertion order, throwables expose class and message, and
	 * unsupported objects/resources collapse to their debug type to avoid unsafe
	 * string conversion side effects inside exception construction.
	 *
	 * @param mixed $value Context value being rendered.
	 * @return string Safe, compact representation for SQL diagnostic messages.
	 */
	private static function stringify(mixed $value): string {
		if(is_bool($value)){
			return $value ? 'true' : 'false';
		}
		if(is_scalar($value)){
			return (string)$value;
		}
		if(is_array($value)){
			if(array_is_list($value)){
				$scalarList=true;
				foreach($value as $item){
					if(!is_scalar($item) || is_bool($item)){
						$scalarList=false;
						break;
					}
				}
				if($scalarList){
					return implode(', ', $value);
				}
			}
			$flat=[];
			foreach($value as $item){
				$flat[]=self::stringify($item);
			}
			return implode(', ', $flat);
		}
		if($value instanceof \Throwable){
			return $value::class.': '.$value->getMessage();
		}
		return get_debug_type($value);
	}
}
