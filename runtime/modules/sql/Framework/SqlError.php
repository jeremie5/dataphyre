<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

final class SqlError {

	public static function unknownCluster(string $cluster, array $known_clusters, ?string $datacenter=null): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL framework configuration error',
			"Unknown SQL cluster '{$cluster}'.",
			[
				'cluster'=>$cluster,
				'known_clusters'=>$known_clusters,
				'datacenter'=>$datacenter,
			],
			$known_clusters===[] 
				? 'No SQL clusters are configured for the current datacenter.'
				: 'Use one of the configured cluster names, or call DB::clusters() to inspect them at runtime.'
		));
	}

	public static function invalidRepositoryClass(string $repository_class): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL repository error',
			"Repository query target '{$repository_class}' is not a valid TableRepository class.",
			[
				'repository_class'=>$repository_class,
				'expected_base_class'=>TableRepository::class,
			],
			'Instantiate RepositoryQuery with a concrete repository that extends Dataphyre\\Database\\TableRepository.'
		));
	}

	public static function missingPrimaryKeyForRepository(string $repository_class, string $operation): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL repository error',
			"{$repository_class} cannot {$operation} because it does not declare a primary key.",
			[
				'repository_class'=>$repository_class,
				'operation'=>$operation,
			],
			'Declare the primary key in the repository schema, for example new TableSchema(..., ..., ..., \'id\').'
		));
	}

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

	public static function missingSchemaForProjection(string $owner, string $projection_name, ?string $table=null): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL projection error',
			"Cannot resolve projection '{$projection_name}' because no table schema is available.",
			[
				'owner'=>$owner,
				'table'=>$table,
				'projection'=>$projection_name,
			],
			'Declare a TableSchema with named projections, or select columns explicitly.'
		));
	}

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

	public static function missingHydratorClass(string $owner, string $class_name): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL hydration error',
			"Hydrator or record class '{$class_name}' could not be loaded.",
			[
				'owner'=>$owner,
				'class'=>$class_name,
			],
			'Check the namespace, autoload path, and class name. If this is a repository record, convention-first lookup expects ...\\Record\\<Entity>Record.'
		));
	}

	public static function invalidFieldPayload(string $owner, string $detail): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL payload error',
			$detail,
			['owner'=>$owner],
			'Pass a non-empty associative array of column => value pairs.'
		));
	}

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

	public static function invalidAggregateFunction(string $owner, string $function, array $allowed_functions): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL aggregate error',
			"Unsupported aggregate function '{$function}'.",
			[
				'owner'=>$owner,
				'function'=>$function,
				'allowed_functions'=>$allowed_functions,
			],
			'Use one of the supported aggregate helpers such as sum(...), avg(...), min(...), max(...), countColumn(...), or countDistinct(...).'
		));
	}

	public static function invalidAggregateColumn(string $owner, string $function, string $column, bool $allow_star=false): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL aggregate error',
			"Aggregate {$function}(...) cannot target column '{$column}'.",
			[
				'owner'=>$owner,
				'function'=>$function,
				'column'=>$column,
				'allow_star'=>$allow_star,
			],
			$allow_star
				? 'Use a valid schema column name or * when the aggregate explicitly allows it.'
				: 'Use a valid schema column name. For row counts, use count() instead of passing * into a column aggregate.'
		));
	}

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

	public static function missingMoneyColumn(string $owner, string $column, string $role, array $available_columns): \RuntimeException {
		return new \RuntimeException(self::format(
			'SQL money integration error',
			"Money hydration could not find the {$role} column '{$column}' in the selected row.",
			[
				'owner'=>$owner,
				'column'=>$column,
				'role'=>$role,
				'available_columns'=>$available_columns,
			],
			'Include the required money or stored-money columns in the query projection, or use asMoneyIn(..., $currency) when the stored currency is fixed.'
		));
	}

	public static function invalidMoneyComparison(string $owner, string $amount_column, string $detail, ?string $hint=null): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL money comparison error',
			$detail,
			[
				'owner'=>$owner,
				'amount_column'=>$amount_column,
			],
			$hint
		));
	}

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

	public static function unknownColumn(string $table, string $column, array $known_columns): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL schema error',
			"Unknown column '{$column}' for table '{$table}'.",
			[
				'table'=>$table,
				'column'=>$column,
				'known_columns'=>$known_columns,
			],
			'Use one of the declared schema columns, or update the TableSchema if the table definition changed.'
		));
	}

	public static function unknownProjection(string $table, string $projection_name, array $known_projections): \InvalidArgumentException {
		return new \InvalidArgumentException(self::format(
			'SQL schema error',
			"Unknown projection '{$projection_name}' for table '{$table}'.",
			[
				'table'=>$table,
				'projection'=>$projection_name,
				'known_projections'=>$known_projections,
			],
			'Check the projection name spelling or add the projection to the TableSchema definition.'
		));
	}

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

	public static function mutationErrorMessage(string $operation, array $context=[], ?string $hint=null): string {
		return self::format(
			'SQL mutation failed',
			'The SQL mutation returned false or null.',
			array_merge(['operation'=>$operation], $context),
			$hint ?? 'Check the SQL query logs for the underlying engine error details.'
		);
	}

	private static function format(string $title, string $summary, array $context=[], ?string $hint=null): string {
		$lines=["{$title}: {$summary}"];
		$context_lines=self::contextLines($context);
		if($context_lines!==[]){
			$lines[]='Context:';
			foreach($context_lines as $line){
				$lines[]='- '.$line;
			}
		}
		if($hint!==null && trim($hint)!==''){
			$lines[]='Hint: '.trim($hint);
		}
		return implode("\n", $lines);
	}

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

	private static function stringify(mixed $value): string {
		if(is_bool($value)){
			return $value ? 'true' : 'false';
		}
		if(is_scalar($value)){
			return (string)$value;
		}
		if(is_array($value)){
			$flat=array_map(static fn(mixed $item): string => self::stringify($item), $value);
			return implode(', ', $flat);
		}
		if($value instanceof \Throwable){
			return $value::class.': '.$value->getMessage();
		}
		return get_debug_type($value);
	}
}
