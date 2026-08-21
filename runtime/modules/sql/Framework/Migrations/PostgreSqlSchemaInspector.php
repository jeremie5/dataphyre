<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database\Migrations;

use PDO;
use RuntimeException;

/**
 * Derives and verifies the PostgreSQL schema contract represented by an applied
 * migration prefix, and certifies paired down/up migrations inside one caller
 * transaction.
 */
final class PostgreSqlSchemaInspector {
	private const POSTGRESQL_IDENTIFIER_MAX_BYTES=63;

	public function __construct(private PostgreSqlMigrationProfile $profile) {}

	/**
	 * @param list<array{name:string,sql:string}> $entries
	 * @return array<string,array<string,mixed>>
	 */
	public function expectedSchema(
		array $entries,
		string $databaseDialect='postgresql'
	): array {
		if(!in_array($databaseDialect, ['postgresql', 'yugabyte'], true)){
			throw new RuntimeException('PostgreSQL schema inspection dialect is invalid.');
		}
		$expected=['tables'=>[], 'indexes'=>[], 'foreign_keys'=>[], 'checks'=>[]];
		$identifier='(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*)';
		$qualified='('.$identifier.')\\s*\\.\\s*('.$identifier.')';
		$scope=$this->profile->schema().'.';
		foreach($entries as $entry){
			$migrationSql=self::migrationSchemaSql(
				(string)($entry['sql'] ?? ''),
				$databaseDialect
			);
			// Apply projected DDL one statement at a time. A migration commonly
			// drops and recreates one named constraint or index; processing every
			// addition before every drop reverses that contract and certifies the
			// wrong final schema.
			foreach(self::topLevelSqlStatements($migrationSql) as $sql){
			if(preg_match_all(
				'/\\bCREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?'.$qualified.
				'\\s*\\((.*?)\\)\\s*(?:;|\\z)/is',
				$sql,
				$tableMatches,
				PREG_SET_ORDER
			)!==false){
				foreach($tableMatches as $match){
					$table=self::qualifiedName($match[1], $match[2]);
					if(!str_starts_with($table, $scope)){
						continue;
					}
					$expected['tables'][$table] ??=[
						'columns'=>[],
						'primary_key'=>null,
						'primary_key_name'=>null,
					];
					foreach(self::splitDefinitions($match[3]) as $definition){
						if(preg_match(
							'/^(?:CONSTRAINT\\s+('.$identifier.')\\s+)?PRIMARY\\s+KEY\\s*\\(([^)]*)\\)/i',
							$definition,
							$primaryMatch
						)===1){
							$expected['tables'][$table]['primary_key']=self::columnList($primaryMatch[2]);
							$expected['tables'][$table]['primary_key_name']=
								isset($primaryMatch[1]) && trim((string)$primaryMatch[1])!==''
									? self::identifier($primaryMatch[1])
									: self::defaultPrimaryKeyName($table);
							self::registerExpectedCheck($expected, $table, $definition, $identifier);
							continue;
						}
						if(preg_match('/^(?:CONSTRAINT|FOREIGN|UNIQUE|CHECK|EXCLUDE)\\b/i', $definition)===1){
							self::registerExpectedCheck($expected, $table, $definition, $identifier);
							continue;
						}
						if(preg_match('/^('.$identifier.')\\s+(.+)$/is', $definition, $columnMatch)!==1){
							throw new RuntimeException(
								'Cannot parse migration column definition for '.$table.': '.$definition
							);
						}
						$column=self::identifier($columnMatch[1]);
						$type=self::columnType($columnMatch[2]);
						$serial=self::serialColumnBaseType($columnMatch[2])!==null;
						$inlinePrimary=preg_match('/\\bPRIMARY\\s+KEY\\b/i', $columnMatch[2])===1;
						$expected['tables'][$table]['columns'][$column]=[
							'type'=>$type,
							'nullable'=>!$inlinePrimary
								&& !$serial
								&& preg_match('/\\bNOT\\s+NULL\\b/i', $columnMatch[2])!==1,
						];
						if($inlinePrimary){
							$expected['tables'][$table]['primary_key']=[$column];
							$expected['tables'][$table]['primary_key_name']=self::defaultPrimaryKeyName($table);
						}
						self::registerExpectedCheck($expected, $table, $definition, $identifier);
					}
					foreach($expected['tables'][$table]['primary_key'] ?? [] as $column){
						if(isset($expected['tables'][$table]['columns'][$column])){
							$expected['tables'][$table]['columns'][$column]['nullable']=false;
						}
					}
				}
			}
			$this->applyAlterTableColumnActions(
				$expected,
				$sql,
				$identifier,
				$qualified
			);
			if(preg_match_all(
				'/\\bALTER\\s+TABLE\\s+(?:ONLY\\s+)?'.$qualified.
				'\\s+ADD\\s+(?:CONSTRAINT\\s+('.$identifier.
				')\\s+)?PRIMARY\\s+KEY\\s*\\(([^)]*)\\)\\s*(?:;|\\z)/is',
				$sql,
				$primaryMatches,
				PREG_SET_ORDER
			)!==false){
				foreach($primaryMatches as $match){
					$table=self::qualifiedName($match[1], $match[2]);
					if(!str_starts_with($table, $scope)){
						continue;
					}
					$expected['tables'][$table] ??=[
						'columns'=>[],
						'primary_key'=>null,
						'primary_key_name'=>null,
					];
					$expected['tables'][$table]['primary_key']=self::columnList($match[4]);
					$expected['tables'][$table]['primary_key_name']=
						isset($match[3]) && trim((string)$match[3])!==''
							? self::identifier($match[3])
							: self::defaultPrimaryKeyName($table);
					foreach($expected['tables'][$table]['primary_key'] as $column){
						if(isset($expected['tables'][$table]['columns'][$column])){
							$expected['tables'][$table]['columns'][$column]['nullable']=false;
						}
					}
				}
			}
			$this->registerAlterTableChecks($expected, $sql, $identifier, $qualified);
			if(preg_match_all(
				'/\\bALTER\\s+TABLE\\s+(?:ONLY\\s+)?'.$qualified.
				'\\s+ADD\\s+CONSTRAINT\\s+('.$identifier.')\\s+FOREIGN\\s+KEY\\s*\\(([^)]*)\\)'.
				'\\s+REFERENCES\\s+'.$qualified.'\\s*\\(([^)]*)\\)(.*?)(?:;|\\z)/is',
				$sql,
				$foreignKeyMatches,
				PREG_SET_ORDER
			)!==false){
				foreach($foreignKeyMatches as $match){
					$table=self::qualifiedName($match[1], $match[2]);
					if(!str_starts_with($table, $scope)){
						continue;
					}
					$constraint=self::identifier($match[3]);
					$onDelete='no_action';
					if(preg_match(
						'/\\bON\\s+DELETE\\s+(NO\\s+ACTION|RESTRICT|CASCADE|SET\\s+NULL|SET\\s+DEFAULT)\\b/i',
						(string)$match[8],
						$deleteMatch
						)===1){
							$onDelete=strtolower(str_replace(' ', '_', (string)preg_replace('/\\s+/', ' ', trim($deleteMatch[1]))));
					}
					$expected['foreign_keys'][$table.'.'.$constraint]=[
						'table'=>$table,
						'columns'=>self::columnList($match[4]),
						'referenced_table'=>self::qualifiedName($match[5], $match[6]),
						'referenced_columns'=>self::columnList($match[7]),
						'on_delete'=>$onDelete,
					];
				}
			}
			foreach(self::indexDefinitions($sql, $identifier) as $index=>$definition){
				if(str_starts_with($definition['table'], $scope)){
					$expected['indexes'][$index]=$definition;
				}
			}
			$this->applyDrops($expected, $sql, $identifier, $qualified);
			}
		}
		return $expected;
	}

	/**
	 * Compares the live PostgreSQL catalog with the schema derived from applied
	 * manifest entries.
	 *
	 * @param array<string,array<string,mixed>> $expected
	 * @return list<array<string,mixed>>
	 */
	public function schemaIssues(PDO $pdo, array $expected): array {
		if(
			($expected['tables'] ?? [])===[]
			&& ($expected['indexes'] ?? [])===[]
			&& ($expected['foreign_keys'] ?? [])===[]
			&& ($expected['checks'] ?? [])===[]
		){
			return [];
		}

		$schema=$this->profile->schema();
		$tables=[];
		$columnRows=self::queryRows(
			$pdo,
			"SELECT table_namespace.nspname AS schema_name, table_record.relname AS table_name, ".
			"column_record.attname AS column_name, ".
			"pg_catalog.format_type(column_record.atttypid, column_record.atttypmod) AS column_type, ".
			"column_record.attnotnull AS is_not_null ".
			"FROM pg_catalog.pg_class AS table_record ".
			"JOIN pg_catalog.pg_namespace AS table_namespace ON table_namespace.oid=table_record.relnamespace ".
			"JOIN pg_catalog.pg_attribute AS column_record ON column_record.attrelid=table_record.oid ".
			"WHERE table_namespace.nspname='".self::sqlLiteral($schema)."' ".
			"AND table_record.relkind IN ('r', 'p') ".
			"AND column_record.attnum>0 AND NOT column_record.attisdropped ".
			"ORDER BY table_record.relname, column_record.attnum"
		);
		foreach($columnRows as $row){
			$table=self::qualifiedName((string)$row['schema_name'], (string)$row['table_name']);
			$column=(string)$row['column_name'];
			$tables[$table] ??=['columns'=>[], 'primary_key'=>null];
			$tables[$table]['columns'][$column]=[
				'type'=>self::normalizeType((string)$row['column_type']),
				'nullable'=>!self::postgresqlBoolean($row['is_not_null'] ?? false),
			];
		}

		$primaryRows=self::queryRows(
			$pdo,
			"SELECT table_namespace.nspname AS schema_name, table_record.relname AS table_name, ".
			"column_record.attname AS column_name, key_column.ordinality AS key_position ".
			"FROM pg_catalog.pg_constraint AS constraint_record ".
			"JOIN pg_catalog.pg_class AS table_record ON table_record.oid=constraint_record.conrelid ".
			"JOIN pg_catalog.pg_namespace AS table_namespace ON table_namespace.oid=table_record.relnamespace ".
			"CROSS JOIN LATERAL unnest(constraint_record.conkey) WITH ORDINALITY AS key_column(attnum, ordinality) ".
			"JOIN pg_catalog.pg_attribute AS column_record ".
			"ON column_record.attrelid=table_record.oid AND column_record.attnum=key_column.attnum ".
			"WHERE constraint_record.contype='p' ".
			"AND table_namespace.nspname='".self::sqlLiteral($schema)."' ".
			"ORDER BY table_record.relname, key_column.ordinality"
		);
		foreach($primaryRows as $row){
			$table=self::qualifiedName((string)$row['schema_name'], (string)$row['table_name']);
			$tables[$table] ??=['columns'=>[], 'primary_key'=>null];
			$tables[$table]['primary_key'] ??=[];
			$tables[$table]['primary_key'][]=(string)$row['column_name'];
		}

		$foreignKeys=[];
		$foreignKeyRows=self::queryRows(
			$pdo,
			"SELECT source_namespace.nspname AS schema_name, source_table.relname AS table_name, ".
			"constraint_record.conname AS constraint_name, source_column.attname AS column_name, ".
			"target_namespace.nspname AS referenced_schema_name, target_table.relname AS referenced_table_name, ".
			"target_column.attname AS referenced_column_name, key_column.ordinality AS key_position, ".
			"CASE constraint_record.confdeltype ".
			"WHEN 'c' THEN 'cascade' WHEN 'r' THEN 'restrict' WHEN 'n' THEN 'set_null' ".
			"WHEN 'd' THEN 'set_default' ELSE 'no_action' END AS on_delete, ".
			"constraint_record.convalidated AS is_valid ".
			"FROM pg_catalog.pg_constraint AS constraint_record ".
			"JOIN pg_catalog.pg_class AS source_table ON source_table.oid=constraint_record.conrelid ".
			"JOIN pg_catalog.pg_namespace AS source_namespace ON source_namespace.oid=source_table.relnamespace ".
			"JOIN pg_catalog.pg_class AS target_table ON target_table.oid=constraint_record.confrelid ".
			"JOIN pg_catalog.pg_namespace AS target_namespace ON target_namespace.oid=target_table.relnamespace ".
			"CROSS JOIN LATERAL unnest(constraint_record.conkey, constraint_record.confkey) ".
			"WITH ORDINALITY AS key_column(source_attnum, target_attnum, ordinality) ".
			"JOIN pg_catalog.pg_attribute AS source_column ".
			"ON source_column.attrelid=source_table.oid AND source_column.attnum=key_column.source_attnum ".
			"JOIN pg_catalog.pg_attribute AS target_column ".
			"ON target_column.attrelid=target_table.oid AND target_column.attnum=key_column.target_attnum ".
			"WHERE constraint_record.contype='f' ".
			"AND source_namespace.nspname='".self::sqlLiteral($schema)."' ".
			"ORDER BY source_table.relname, constraint_record.conname, key_column.ordinality"
		);
		foreach($foreignKeyRows as $row){
			$table=self::qualifiedName((string)$row['schema_name'], (string)$row['table_name']);
			$key=$table.'.'.self::identifier((string)$row['constraint_name']);
			$foreignKeys[$key] ??=[
				'table'=>$table,
				'columns'=>[],
				'referenced_table'=>self::qualifiedName(
					(string)$row['referenced_schema_name'],
					(string)$row['referenced_table_name']
				),
				'referenced_columns'=>[],
				'on_delete'=>(string)$row['on_delete'],
				'valid'=>self::postgresqlBoolean($row['is_valid'] ?? false),
			];
			$foreignKeys[$key]['columns'][]=(string)$row['column_name'];
			$foreignKeys[$key]['referenced_columns'][]=(string)$row['referenced_column_name'];
		}

		$checks=[];
		$checkRows=self::queryRows(
			$pdo,
			"SELECT table_namespace.nspname AS schema_name, table_record.relname AS table_name, ".
			"constraint_record.conname AS constraint_name, ".
			"pg_catalog.pg_get_expr(constraint_record.conbin, constraint_record.conrelid, true) AS expression, ".
			"constraint_record.convalidated AS is_valid ".
			"FROM pg_catalog.pg_constraint AS constraint_record ".
			"JOIN pg_catalog.pg_class AS table_record ON table_record.oid=constraint_record.conrelid ".
			"JOIN pg_catalog.pg_namespace AS table_namespace ON table_namespace.oid=table_record.relnamespace ".
			"WHERE constraint_record.contype='c' ".
			"AND table_namespace.nspname='".self::sqlLiteral($schema)."' ".
			"ORDER BY table_record.relname, constraint_record.conname"
		);
		foreach($checkRows as $row){
			$table=self::qualifiedName((string)$row['schema_name'], (string)$row['table_name']);
			$name=self::identifier((string)$row['constraint_name']);
			$checks[$table.'.'.$name]=[
				'table'=>$table,
				'name'=>$name,
				'expression'=>self::normalizeCheckExpression((string)$row['expression']),
				'valid'=>self::postgresqlBoolean($row['is_valid'] ?? false),
			];
		}

		$indexes=[];
		$indexRows=self::queryRows(
			$pdo,
			"SELECT index_namespace.nspname AS index_schema, index_record.relname AS index_name, ".
			"table_namespace.nspname AS table_schema, table_record.relname AS table_name, ".
			"index_state.indisunique AS is_unique, index_state.indisvalid AS is_valid, ".
			"index_state.indisready AS is_ready, ".
			"pg_catalog.pg_get_indexdef(index_state.indexrelid) AS index_definition, ".
			"pg_catalog.pg_get_expr(index_state.indpred, index_state.indrelid, true) AS predicate ".
			"FROM pg_catalog.pg_index AS index_state ".
			"JOIN pg_catalog.pg_class AS index_record ON index_record.oid=index_state.indexrelid ".
			"JOIN pg_catalog.pg_namespace AS index_namespace ON index_namespace.oid=index_record.relnamespace ".
			"JOIN pg_catalog.pg_class AS table_record ON table_record.oid=index_state.indrelid ".
			"JOIN pg_catalog.pg_namespace AS table_namespace ON table_namespace.oid=table_record.relnamespace ".
			"WHERE index_namespace.nspname='".self::sqlLiteral($schema)."' ".
			"ORDER BY index_record.relname"
		);
		$identifier='(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*)';
		foreach($indexRows as $row){
			$index=self::qualifiedName((string)$row['index_schema'], (string)$row['index_name']);
			$parsed=self::indexDefinitions((string)$row['index_definition'], $identifier);
			$parsedDefinition=$parsed[$index] ?? null;
			if($parsedDefinition===null){
				throw new RuntimeException('Cannot normalize live PostgreSQL index definition for '.$index.'.');
			}
			$indexes[$index]=[
				'table'=>self::qualifiedName((string)$row['table_schema'], (string)$row['table_name']),
				'unique'=>self::postgresqlBoolean($row['is_unique'] ?? false),
				'keys'=>$parsedDefinition['keys'],
				'predicate'=>isset($row['predicate']) && trim((string)$row['predicate'])!==''
					? self::normalizeIndexPredicate((string)$row['predicate'])
					: null,
				'valid'=>self::postgresqlBoolean($row['is_valid'] ?? false)
					&& self::postgresqlBoolean($row['is_ready'] ?? false),
			];
		}

		$issues=[];
		foreach($expected['tables'] as $table=>$definition){
			$actual=$tables[$table] ?? null;
			if($actual===null){
				$issues[]=['kind'=>'missing_table', 'object'=>$table];
				continue;
			}
			foreach($definition['columns'] as $column=>$expectedColumn){
				$object=$table.'.'.$column;
				if(!array_key_exists($column, $actual['columns'])){
					$issues[]=['kind'=>'missing_column', 'object'=>$object, 'expected'=>$expectedColumn];
					continue;
				}
				$actualColumn=$actual['columns'][$column];
				if($actualColumn['type']!==$expectedColumn['type']){
					$issues[]=[
						'kind'=>'column_type_mismatch',
						'object'=>$object,
						'expected'=>$expectedColumn['type'],
						'actual'=>$actualColumn['type'],
					];
				}
				if($actualColumn['nullable']!==$expectedColumn['nullable']){
					$issues[]=[
						'kind'=>'column_nullability_mismatch',
						'object'=>$object,
						'expected'=>$expectedColumn['nullable'],
						'actual'=>$actualColumn['nullable'],
					];
				}
			}
			if($definition['primary_key']!==null && $actual['primary_key']!==$definition['primary_key']){
				$issues[]=[
					'kind'=>'primary_key_mismatch',
					'object'=>$table,
					'expected'=>$definition['primary_key'],
					'actual'=>$actual['primary_key'],
				];
			}
		}
		foreach($expected['indexes'] as $index=>$definition){
			if(!isset($tables[$definition['table']])){
				continue;
			}
			$actual=$indexes[$index] ?? null;
			if($actual===null){
				$issues[]=['kind'=>'missing_index', 'object'=>$index, 'table'=>$definition['table']];
				continue;
			}
			if($actual['table']!==$definition['table']){
				$issues[]=[
					'kind'=>'index_table_mismatch',
					'object'=>$index,
					'expected'=>$definition['table'],
					'actual'=>$actual['table'],
				];
				continue;
			}
			if($actual['valid']!==true){
				$issues[]=['kind'=>'invalid_index', 'object'=>$index, 'table'=>$definition['table']];
			}
			if($actual['unique']!==$definition['unique']){
				$issues[]=[
					'kind'=>'index_uniqueness_mismatch',
					'object'=>$index,
					'expected'=>$definition['unique'],
					'actual'=>$actual['unique'],
				];
			}
			if(!self::indexKeysEquivalent($definition['keys'], $actual['keys'])){
				$issues[]=[
					'kind'=>'index_keys_mismatch',
					'object'=>$index,
					'expected'=>$definition['keys'],
					'actual'=>$actual['keys'],
				];
			}
			if(!self::expressionValuesEquivalent($definition['predicate'], $actual['predicate'])){
				$issues[]=[
					'kind'=>'index_predicate_mismatch',
					'object'=>$index,
					'expected'=>$definition['predicate'],
					'actual'=>$actual['predicate'],
				];
			}
		}
		foreach($expected['foreign_keys'] as $constraint=>$definition){
			if(!isset($tables[$definition['table']])){
				continue;
			}
			$actual=$foreignKeys[$constraint] ?? null;
			if($actual===null){
				$issues[]=[
					'kind'=>'missing_foreign_key',
					'object'=>$constraint,
					'table'=>$definition['table'],
				];
				continue;
			}
			if($actual['valid']!==true){
				$issues[]=[
					'kind'=>'invalid_foreign_key',
					'object'=>$constraint,
					'table'=>$definition['table'],
				];
			}
			foreach(['table', 'columns', 'referenced_table', 'referenced_columns', 'on_delete'] as $field){
				if($actual[$field]!==$definition[$field]){
					$issues[]=[
						'kind'=>'foreign_key_'.$field.'_mismatch',
						'object'=>$constraint,
						'expected'=>$definition[$field],
						'actual'=>$actual[$field],
					];
				}
			}
		}
		foreach($expected['checks'] as $constraint=>$definition){
			if(!isset($tables[$definition['table']])){
				continue;
			}
			$actual=null;
			if($definition['name']!==null){
				$actual=$checks[$definition['table'].'.'.$definition['name']] ?? null;
			}else{
				foreach($checks as $candidate){
					if(
						$candidate['table']===$definition['table']
						&& self::expressionsEquivalent(
							$definition['expression'],
							$candidate['expression']
						)
					){
						$actual=$candidate;
						break;
					}
				}
			}
			if($actual===null){
				$issues[]=[
					'kind'=>'missing_check_constraint',
					'object'=>$constraint,
					'table'=>$definition['table'],
					'expected'=>$definition['expression'],
				];
				continue;
			}
			if($actual['valid']!==true){
				$issues[]=[
					'kind'=>'invalid_check_constraint',
					'object'=>$constraint,
					'table'=>$definition['table'],
				];
			}
			if(!self::expressionsEquivalent($definition['expression'], $actual['expression'])){
				$issues[]=[
					'kind'=>'check_constraint_expression_mismatch',
					'object'=>$constraint,
					'expected'=>$definition['expression'],
					'actual'=>$actual['expression'],
				];
			}
		}
		return $issues;
	}

	/**
	 * Returns a stable structural fingerprint for application-owned objects.
	 * Administrative migration journals are intentionally excluded.
	 */
	public function structuralFingerprint(PDO $pdo): string {
		$schema=self::sqlLiteral($this->profile->schema());
		$administrative=$this->administrativeTableSql();
		$queries=[
			"SELECT c.relkind, c.relname AS object_name, a.attname AS member_name, ".
				"pg_catalog.format_type(a.atttypid, a.atttypmod) AS definition, ".
				"a.attnotnull::text AS not_null, ".
				"COALESCE(pg_get_expr(d.adbin, d.adrelid), '') AS default_expression ".
				"FROM pg_catalog.pg_class c ".
				"JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace ".
				"JOIN pg_catalog.pg_attribute a ON a.attrelid=c.oid ".
				"AND a.attnum>0 AND NOT a.attisdropped ".
				"LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid=c.oid AND d.adnum=a.attnum ".
				"WHERE n.nspname='".$schema."' AND c.relkind IN ('r','p') ".
				"AND c.relname NOT IN (".$administrative.") ".
				"ORDER BY c.relkind, c.relname, a.attname",
			"SELECT c.relname AS object_name, con.conname AS member_name, ".
				"con.contype AS constraint_type, con.convalidated::text AS validated, ".
				"pg_get_constraintdef(con.oid, true) AS definition ".
				"FROM pg_catalog.pg_constraint con ".
				"JOIN pg_catalog.pg_class c ON c.oid=con.conrelid ".
				"JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace ".
				"WHERE n.nspname='".$schema."' ".
				"AND c.relname NOT IN (".$administrative.") ".
				"ORDER BY c.relname, con.conname",
			"SELECT table_record.relname AS object_name, index_record.relname AS member_name, ".
				"pg_get_indexdef(index_record.oid) AS definition ".
				"FROM pg_catalog.pg_index index_state ".
				"JOIN pg_catalog.pg_class index_record ON index_record.oid=index_state.indexrelid ".
				"JOIN pg_catalog.pg_class table_record ON table_record.oid=index_state.indrelid ".
				"JOIN pg_catalog.pg_namespace n ON n.oid=table_record.relnamespace ".
				"WHERE n.nspname='".$schema."' ".
				"AND table_record.relname NOT IN (".$administrative.") ".
				"ORDER BY table_record.relname, index_record.relname",
		];
		$projection=[];
		foreach($queries as $query){
			$projection[]=self::queryRows($pdo, $query);
		}
		return hash(
			'sha256',
			json_encode($projection, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)
		);
	}

	/**
	 * @return array<string,array{row_count:string,hash_sum_a:string,hash_sum_b:string}>
	 */
	public function dataFingerprint(PDO $pdo): array {
		$schema=self::sqlLiteral($this->profile->schema());
		$rows=self::queryRows(
			$pdo,
			"SELECT c.relname AS table_name ".
			"FROM pg_catalog.pg_class c ".
			"JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace ".
			"WHERE n.nspname='".$schema."' AND c.relkind IN ('r','p') ".
			"AND c.relname NOT IN (".$this->administrativeTableSql().") ".
			"ORDER BY c.relname"
		);
		$fingerprint=[];
		foreach($rows as $row){
			$table=(string)($row['table_name'] ?? '');
			if($table===''){
				throw new RuntimeException('Cannot fingerprint an unnamed application table.');
			}
			$projection=self::queryRow(
				$pdo,
				"SELECT COUNT(*)::text AS row_count, ".
				"COALESCE(SUM(hashtextextended(row_to_json(snapshot_row)::text, 0)::numeric), 0)::text ".
				"AS hash_sum_a, ".
				"COALESCE(SUM(hashtextextended(row_to_json(snapshot_row)::text, 2147483647)::numeric), 0)::text ".
				"AS hash_sum_b FROM ".$this->profile->qualified($table)." AS snapshot_row"
			);
			$fingerprint[$table]=[
				'row_count'=>(string)($projection['row_count'] ?? ''),
				'hash_sum_a'=>(string)($projection['hash_sum_a'] ?? ''),
				'hash_sum_b'=>(string)($projection['hash_sum_b'] ?? ''),
			];
		}
		return $fingerprint;
	}

	public static function assertLosslessDownRows(
		array $before,
		array $afterDown,
		string $migrationId
	): void {
		foreach($before as $table=>$evidence){
			$beforeCount=(string)($evidence['row_count'] ?? '');
			$downCount=array_key_exists($table, $afterDown)
				? (string)($afterDown[$table]['row_count'] ?? '')
				: null;
			if(
				($downCount===null && $beforeCount!=='0')
				|| ($downCount!==null && $downCount!==$beforeCount)
			){
				throw new RuntimeException(
					'Migration labelled lossless removes application rows in its down direction: '.
					$migrationId.' ('.$table.').'
				);
			}
		}
	}

	/**
	 * Proves a schema down migration changes structure, or that an explicitly
	 * data-only pair leaves structure untouched. The paired up/down directions
	 * must reconstruct the selected structural and data evidence exactly, and
	 * lossless directions must preserve application row counts.
	 *
	 * The caller owns the surrounding transaction and final commit/rollback.
	 *
	 * @param array<string,mixed> $entry
	 */
	public function certifyDown(PDO $pdo, array $entry): void {
		if(!is_array($entry['down'] ?? null)){
			throw new RuntimeException(
				'Migration '.$entry['id'].' is irreversible: '.
				($entry['irreversible_reason'] ?? 'no down migration')
			);
		}
		$changeKind=$entry['change_kind'] ?? 'schema';
		if(!in_array($changeKind, PostgreSqlMigrationProfile::CHANGE_KINDS, true)){
			throw new RuntimeException(
				'Migration change kind is invalid during rollback certification: '.$entry['id'].'.'
			);
		}
		$dataOnly=$changeKind==='data_only';
		$before=$this->structuralFingerprint($pdo);
		$beforeData=$dataOnly || $entry['down']['safety']==='lossless'
			? $this->dataFingerprint($pdo)
			: null;
		self::executeSql(
			$pdo,
			$entry['down']['sql'],
			'Migration down SQL execution failed: '.$entry['id'].'.'
		);
		$down=$this->structuralFingerprint($pdo);
		if($dataOnly && !hash_equals($before, $down)){
			throw new RuntimeException(
				'Data-only migration down direction changed application structure: '.$entry['id'].'.'
			);
		}
		if(!$dataOnly && hash_equals($before, $down)){
			throw new RuntimeException(
				'Migration down direction made no structural change: '.$entry['id'].'.'
			);
		}
		$downData=$dataOnly || is_array($beforeData)
			? $this->dataFingerprint($pdo)
			: null;
		if(is_array($beforeData) && $entry['down']['safety']==='lossless'){
			self::assertLosslessDownRows(
				$beforeData,
				$downData,
				(string)$entry['id']
			);
		}
		self::executeSql(
			$pdo,
			$entry['up']['sql'],
			'Migration up SQL execution failed during rollback certification: '.$entry['id'].'.'
		);
		$restored=$this->structuralFingerprint($pdo);
		if(!hash_equals($before, $restored)){
			throw new RuntimeException(
				'Migration up direction did not reconstruct the pre-rollback schema: '.
				$entry['id'].'.'
			);
		}
		$restoredData=$dataOnly || is_array($beforeData)
			? $this->dataFingerprint($pdo)
			: null;
		if($dataOnly && $beforeData!==$restoredData){
			throw new RuntimeException(
				'Data-only migration up direction did not reconstruct pre-rollback data: '.
				$entry['id'].'.'
			);
		}
		if(!$dataOnly && is_array($beforeData) && $beforeData!==$restoredData){
			throw new RuntimeException(
				'Migration labelled lossless did not preserve all application rows through '.
				'down/up certification: '.$entry['id'].'.'
			);
		}
		self::executeSql(
			$pdo,
			$entry['down']['sql'],
			'Migration final down SQL execution failed during rollback certification: '.$entry['id'].'.'
		);
		$final=$this->structuralFingerprint($pdo);
		if($dataOnly && !hash_equals($before, $final)){
			throw new RuntimeException(
				'Data-only migration final down direction changed application structure: '.
				$entry['id'].'.'
			);
		}
		if(!hash_equals($down, $final)){
			throw new RuntimeException(
				'Migration down direction is not repeatably paired with its up migration: '.
				$entry['id'].'.'
			);
		}
		$finalData=$dataOnly || is_array($beforeData)
			? $this->dataFingerprint($pdo)
			: null;
		if($dataOnly && $downData!==$finalData){
			throw new RuntimeException(
				'Data-only migration down direction is not repeatably paired with its up '.
				'direction: '.$entry['id'].'.'
			);
		}
		if(is_array($beforeData) && $entry['down']['safety']==='lossless'){
			self::assertLosslessDownRows(
				$beforeData,
				$finalData,
				(string)$entry['id']
			);
		}
	}

	/** @return list<array<string,mixed>> */
	private static function queryRows(PDO $pdo, string $sql): array {
		$statement=$pdo->query($sql);
		if(!$statement instanceof \PDOStatement){
			throw new RuntimeException('PostgreSQL schema inspection query failed.');
		}
		$rows=$statement->fetchAll(PDO::FETCH_ASSOC);
		return $rows;
	}

	/** @return array<string,mixed> */
	private static function queryRow(PDO $pdo, string $sql): array {
		$statement=$pdo->query($sql);
		if(!$statement instanceof \PDOStatement){
			throw new RuntimeException('PostgreSQL schema inspection query failed.');
		}
		$row=$statement->fetch(PDO::FETCH_ASSOC);
		if(!is_array($row)){
			throw new RuntimeException('PostgreSQL schema inspection returned an invalid row.');
		}
		return $row;
	}

	private static function executeSql(PDO $pdo, string $sql, string $error): void {
		if($pdo->exec($sql)===false){
			throw new RuntimeException($error);
		}
	}

	private function administrativeTableSql(): string {
		return "'".self::sqlLiteral($this->profile->journalTable())."', '".
			self::sqlLiteral($this->profile->eventTable())."'";
	}

	private static function sqlLiteral(string $value): string {
		return str_replace("'", "''", $value);
	}

	/** @param array<string,array<string,mixed>> $expected */
	private function applyDrops(array &$expected, string $sql, string $identifier, string $qualified): void {
		if(preg_match_all(
			'/\bALTER\s+TABLE\s+(?:ONLY\s+)?'.$qualified.
			'\s+DROP\s+COLUMN\s+(?:IF\s+EXISTS\s+)?('.$identifier.
			')(?:\s+(?:CASCADE|RESTRICT))?\s*(?:;|\z)/is',
			$sql,
			$droppedColumns,
			PREG_SET_ORDER
		)!==false){
			foreach($droppedColumns as $match){
				$table=self::qualifiedName($match[1], $match[2]);
				$column=self::identifier($match[3]);
				unset($expected['tables'][$table]['columns'][$column]);
				if(in_array(
					$column,
					$expected['tables'][$table]['primary_key'] ?? [],
					true
				)){
					$expected['tables'][$table]['primary_key']=null;
					$expected['tables'][$table]['primary_key_name']=null;
				}
				foreach($expected['indexes'] as $index=>$definition){
					if(
						$definition['table']===$table
						&& self::indexUsesColumn($definition['keys'], $column)
					){
						unset($expected['indexes'][$index]);
					}
				}
				foreach($expected['foreign_keys'] as $constraint=>$definition){
					if(
						($definition['table']===$table && in_array($column, $definition['columns'], true))
						|| (
							$definition['referenced_table']===$table
							&& in_array($column, $definition['referenced_columns'], true)
						)
					){
						unset($expected['foreign_keys'][$constraint]);
					}
				}
				foreach($expected['checks'] as $constraint=>$definition){
					if(
						$definition['table']===$table
						&& preg_match(
							'/(?:^|[^A-Za-z0-9_$])'.preg_quote($column, '/').
							'(?:$|[^A-Za-z0-9_$])/i',
							$definition['expression']
						)===1
					){
						unset($expected['checks'][$constraint]);
					}
				}
			}
		}
		if(preg_match_all(
			'/\bALTER\s+TABLE\s+(?:ONLY\s+)?'.$qualified.
			'\s+DROP\s+CONSTRAINT\s+(?:IF\s+EXISTS\s+)?('.$identifier.
			')(?:\s+(?:CASCADE|RESTRICT))?\s*(?:;|\z)/is',
			$sql,
			$droppedConstraints,
			PREG_SET_ORDER
		)!==false){
			foreach($droppedConstraints as $match){
				$table=self::qualifiedName($match[1], $match[2]);
				$constraint=self::identifier($match[3]);
				$key=$table.'.'.$constraint;
				unset($expected['checks'][$key], $expected['foreign_keys'][$key]);
				if(
					($expected['tables'][$table]['primary_key_name'] ?? null)
					===$constraint
				){
					$expected['tables'][$table]['primary_key']=null;
					$expected['tables'][$table]['primary_key_name']=null;
				}
			}
		}
		if(preg_match_all(
			'/\bDROP\s+INDEX\s+(?:CONCURRENTLY\s+)?(?:IF\s+EXISTS\s+)?'.
			'(?:(?<schema>'.$identifier.')\s*\.\s*)?(?<index>'.$identifier.')'.
			'(?:\s+(?:CASCADE|RESTRICT))?\s*(?:;|\z)/is',
			$sql,
			$droppedIndexes,
			PREG_SET_ORDER
		)!==false){
			foreach($droppedIndexes as $match){
				$schema=isset($match['schema']) && trim((string)$match['schema'])!==''
					? self::identifier($match['schema'])
					: $this->profile->schema();
				unset($expected['indexes'][$schema.'.'.self::identifier($match['index'])]);
			}
		}
		if(preg_match_all(
			'/\bDROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?'.$qualified.
			'(?:\s+(?:CASCADE|RESTRICT))?\s*(?:;|\z)/is',
			$sql,
			$droppedTables,
			PREG_SET_ORDER
		)!==false){
			foreach($droppedTables as $match){
				$table=self::qualifiedName($match[1], $match[2]);
				unset($expected['tables'][$table]);
				foreach($expected['indexes'] as $index=>$definition){
					if($definition['table']===$table){
						unset($expected['indexes'][$index]);
					}
				}
				foreach($expected['foreign_keys'] as $constraint=>$definition){
					if($definition['table']===$table || $definition['referenced_table']===$table){
						unset($expected['foreign_keys'][$constraint]);
					}
				}
				foreach($expected['checks'] as $constraint=>$definition){
					if($definition['table']===$table){
						unset($expected['checks'][$constraint]);
					}
				}
			}
		}
	}

	/** @param array<string,array<string,mixed>> $expected */
	private static function registerExpectedCheck(
		array &$expected,
		string $table,
		string $definition,
		string $identifier
	): void {
		$tail=self::topLevelKeywordTail($definition, 'check');
		if($tail===null){
			return;
		}
		$expression=self::normalizeCheckExpression($tail);
		if($expression===''){
			throw new RuntimeException('Cannot parse migration CHECK constraint for '.$table.'.');
		}
		$name=null;
		if(preg_match('/\\bCONSTRAINT\\s+('.$identifier.')\\s+CHECK\\b/i', $definition, $match)===1){
			$name=self::identifier($match[1]);
		}
		$key=$table.'.'.($name ?? 'expression_'.hash('sha256', $expression));
		$expected['checks'][$key]=[
			'table'=>$table,
			'name'=>$name,
			'expression'=>$expression,
		];
	}

	/** @param array<string,array<string,mixed>> $expected */
	private function registerAlterTableChecks(
		array &$expected,
		string $sql,
		string $identifier,
		string $qualified
	): void {
		$pattern='/\\bALTER\\s+TABLE\\s+(?:ONLY\\s+)?'.$qualified.
			'\\s+ADD\\s+(?:CONSTRAINT\\s+('.$identifier.')\\s+)?CHECK\\s*\\(/is';
		$result=preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
		$scope=$this->profile->schema().'.';
		foreach($matches as $match){
			$table=self::qualifiedName($match[1][0], $match[2][0]);
			if(!str_starts_with($table, $scope)){
				continue;
			}
			$opening=$match[0][1]+strrpos($match[0][0], '(');
			$closing=self::matchingParenthesis($sql, $opening);
			$expression=self::normalizeCheckExpression(
				substr($sql, $opening+1, $closing-$opening-1)
			);
			$name=isset($match[3][0]) && trim((string)$match[3][0])!==''
				? self::identifier($match[3][0])
				: null;
			$key=$table.'.'.($name ?? 'expression_'.hash('sha256', $expression));
			$expected['checks'][$key]=[
				'table'=>$table,
				'name'=>$name,
				'expression'=>$expression,
			];
		}
	}

	/** @param list<string> $keys */
	private static function indexUsesColumn(array $keys, string $column): bool {
		$column=strtolower(trim($column));
		foreach($keys as $key){
			$base=(string)preg_replace('/\s+(?:asc|desc)(?:\s+nulls\s+(?:first|last))?$/i', '', trim($key));
			if(strtolower(trim($base, '"'))===$column){
				return true;
			}
		}
		return false;
	}

	/** @param array<string,array<string,mixed>> $expected */
	private function applyAlterTableColumnActions(
		array &$expected,
		string $sql,
		string $identifier,
		string $qualified
	): void {
		$pattern='/\\bALTER\\s+TABLE\\s+(?:ONLY\\s+)?'.$qualified.'\\s+/is';
		if(preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER|PREG_OFFSET_CAPTURE)===false){
			return;
		}
		$scope=$this->profile->schema().'.';
		foreach($matches as $match){
			$table=self::qualifiedName($match[1][0], $match[2][0]);
			if(!str_starts_with($table, $scope)){
				continue;
			}
			$bodyStart=$match[0][1]+strlen($match[0][0]);
			$body=substr(
				$sql,
				$bodyStart,
				self::statementEnd($sql, $bodyStart)-$bodyStart
			);
			foreach(self::splitDefinitions($body) as $action){
				$action=trim($action);
				if(preg_match(
					'/^ADD\\s+(?!(?:CONSTRAINT|PRIMARY|FOREIGN|UNIQUE|CHECK|EXCLUDE)\\b)'.
						'(?:COLUMN\\s+)?(?:IF\\s+NOT\\s+EXISTS\\s+)?('.
						$identifier.')\\s+(.+)$/is',
					$action,
					$addMatch
				)===1){
					$column=self::identifier($addMatch[1]);
					$definition=$addMatch[2];
					$serial=self::serialColumnBaseType($definition)!==null;
					$inlinePrimary=preg_match('/\\bPRIMARY\\s+KEY\\b/i', $definition)===1;
					$expected['tables'][$table] ??=[
						'columns'=>[],
						'primary_key'=>null,
						'primary_key_name'=>null,
					];
					$expected['tables'][$table]['columns'][$column]=[
						'type'=>self::columnType($definition),
						'nullable'=>!$serial
							&& !$inlinePrimary
							&& preg_match('/\\bNOT\\s+NULL\\b/i', $definition)!==1,
					];
					if($inlinePrimary){
						$expected['tables'][$table]['primary_key']=[$column];
						$expected['tables'][$table]['primary_key_name']=self::defaultPrimaryKeyName($table);
					}
					self::registerExpectedCheck($expected, $table, $action, $identifier);
					continue;
				}
				if(preg_match(
					'/^RENAME\\s+(?:COLUMN\\s+)?('.$identifier.')\\s+TO\\s+('.
						$identifier.')$/is',
					$action,
					$renameMatch
				)===1){
					self::renameExpectedColumn(
						$expected,
						$table,
						self::identifier($renameMatch[1]),
						self::identifier($renameMatch[2])
					);
					continue;
				}
				if(preg_match(
					'/^ALTER\\s+(?:COLUMN\\s+)?('.$identifier.
						')\\s+(?:SET\\s+DATA\\s+)?TYPE\\s+(.+)$/is',
					$action,
					$typeMatch
				)===1){
					$column=self::identifier($typeMatch[1]);
					if(isset($expected['tables'][$table]['columns'][$column])){
						$expected['tables'][$table]['columns'][$column]['type']=
							self::columnType($typeMatch[2]);
					}
					continue;
				}
				if(preg_match(
					'/^ALTER\\s+(?:COLUMN\\s+)?('.$identifier.
						')\\s+(SET|DROP)\\s+NOT\\s+NULL$/is',
					$action,
					$nullabilityMatch
				)!==1){
					continue;
				}
				$column=self::identifier($nullabilityMatch[1]);
				if(isset($expected['tables'][$table]['columns'][$column])){
					$expected['tables'][$table]['columns'][$column]['nullable']=
						strcasecmp($nullabilityMatch[2], 'DROP')===0;
				}
			}
		}
	}

	/** @param array<string,array<string,mixed>> $expected */
	private static function renameExpectedColumn(
		array &$expected,
		string $table,
		string $from,
		string $to
	): void {
		$columns=$expected['tables'][$table]['columns'] ?? null;
		if(!is_array($columns) || !array_key_exists($from, $columns)){
			return;
		}
		$renamed=[];
		foreach($columns as $column=>$definition){
			$renamed[$column===$from ? $to : $column]=$definition;
		}
		$expected['tables'][$table]['columns']=$renamed;
		if(is_array($expected['tables'][$table]['primary_key'] ?? null)){
			$expected['tables'][$table]['primary_key']=array_map(
				static fn(string $column): string=>$column===$from ? $to : $column,
				$expected['tables'][$table]['primary_key']
			);
		}
		foreach($expected['indexes'] as &$definition){
			if(($definition['table'] ?? null)!==$table){
				continue;
			}
			$definition['keys']=array_map(
				static fn(string $key): string=>self::replaceIdentifierToken($key, $from, $to),
				$definition['keys']
			);
			if(is_string($definition['predicate'] ?? null)){
				$definition['predicate']=self::replaceIdentifierToken(
					$definition['predicate'],
					$from,
					$to
				);
			}
		}
		unset($definition);
		foreach($expected['foreign_keys'] as &$definition){
			if(($definition['table'] ?? null)===$table){
				$definition['columns']=array_map(
					static fn(string $column): string=>$column===$from ? $to : $column,
					$definition['columns']
				);
			}
			if(($definition['referenced_table'] ?? null)===$table){
				$definition['referenced_columns']=array_map(
					static fn(string $column): string=>$column===$from ? $to : $column,
					$definition['referenced_columns']
				);
			}
		}
		unset($definition);
		foreach($expected['checks'] as &$definition){
			if(($definition['table'] ?? null)===$table){
				$definition['expression']=self::replaceIdentifierToken(
					$definition['expression'],
					$from,
					$to
				);
			}
		}
		unset($definition);
	}

	private static function replaceIdentifierToken(string $sql, string $from, string $to): string {
		return (string)preg_replace_callback(
			"~\"(?:[^\"]|\"\")*\"|'(?:''|[^'])*'|[A-Za-z_][A-Za-z0-9_\\x24]*~",
			static function(array $match) use ($from, $to): string {
				$token=$match[0];
				if($token[0]==="'"){
					return $token;
				}
				if($token[0]==='"'){
					$value=str_replace('""', '"', substr($token, 1, -1));
					return $value===$from ? '"'.str_replace('"', '""', $to).'"' : $token;
				}
				return strtolower($token)===$from ? $to : $token;
			},
			$sql
		);
	}

	/**
	 * @return array<string,array{table:string,unique:bool,keys:list<string>,predicate:?string}>
	 */
	private static function indexDefinitions(string $sql, string $identifier): array {
		$sql=self::migrationSchemaSql($sql, 'postgresql');
		$pattern='/\\bCREATE\\s+(?<unique>UNIQUE\\s+)?INDEX\\s+(?:CONCURRENTLY\\s+)?'.
			'(?:IF\\s+NOT\\s+EXISTS\\s+)?'.
			'(?:(?<index_schema>'.$identifier.')\\s*\\.\\s*)?(?<index_name>'.$identifier.')\\s+'.
			'ON\\s+(?:ONLY\\s+)?(?<table_schema>'.$identifier.')\\s*\\.\\s*'.
			'(?<table_name>'.$identifier.')(?=\\s|\\(|;|\\z)/is';
		$result=preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
		$indexes=[];
		foreach($matches as $match){
			$prefixEnd=$match[0][1]+strlen($match[0][0]);
			$remainder=substr($sql, $prefixEnd);
			if(preg_match('/^\\s*(?:USING\\s+'.$identifier.'\\s*)?\\(/i', $remainder, $openingMatch)!==1){
				throw new RuntimeException('Cannot parse index key definition for '.$match['index_name'][0].'.');
			}
			$opening=$prefixEnd+strrpos($openingMatch[0], '(');
			$closing=self::matchingParenthesis($sql, $opening);
			$statementEnd=self::statementEnd($sql, $closing+1);
			$keySql=substr($sql, $opening+1, $closing-$opening-1);
			$keys=array_values(array_map(
				static fn(string $key): string=>self::normalizeIndexKey($key),
				self::splitDefinitions($keySql)
			));
			if($keys===[] || in_array('', $keys, true)){
				throw new RuntimeException('Applied index '.$match['index_name'][0].' has an empty key definition.');
			}
			$tail=substr($sql, $closing+1, $statementEnd-$closing-1);
			$predicateSql=self::topLevelKeywordTail($tail, 'where');
			$predicate=$predicateSql===null ? null : self::normalizeIndexPredicate($predicateSql);
			$tableSchema=self::identifier($match['table_schema'][0]);
			$table=self::qualifiedName($match['table_schema'][0], $match['table_name'][0]);
			$indexSchema=isset($match['index_schema'][0]) && $match['index_schema'][0]!==''
				? self::identifier($match['index_schema'][0])
				: $tableSchema;
			$index=$indexSchema.'.'.self::identifier($match['index_name'][0]);
			$indexes[$index]=[
				'table'=>$table,
				'unique'=>isset($match['unique'][0]) && trim($match['unique'][0])!=='',
				'keys'=>$keys,
				'predicate'=>$predicate,
			];
		}
		return $indexes;
	}

	public static function normalizeCheckExpression(string $expression): string {
		$normalized=self::normalizeCatalogExpressionArtifacts(
			self::normalizeSqlExpression($expression)
		);
		$normalized=self::normalizeEmbeddedTextMembershipExpressions($normalized);
		$normalized=self::normalizeRedundantConcatenationParentheses($normalized);
		return self::normalizeBooleanCheckExpression($normalized);
	}

	private static function normalizeRedundantConcatenationParentheses(string $expression): string {
		$literal="'(?:''|[^'])*'";
		$quotedIdentifier='"(?:[^"]|"")*"';
		$identifier='[A-Za-z_][A-Za-z0-9_$]*(?:\\.[A-Za-z_][A-Za-z0-9_$]*)*';
		$type=$identifier.'(?:\\[\\])*';
		$atom='(?:'.$literal.'|'.$quotedIdentifier.'|'.$identifier.'|[0-9]+)'.
			'(?:::(?:'.$type.'))?';
		return (string)preg_replace(
			'/\\(('.$atom.'(?:\\|\\|'.$atom.')+)\\)/i',
			'$1',
			$expression
		);
	}

	/**
	 * PostgreSQL's catalog renderer adds grouping around JSON operator chains.
	 * Only exact chains are collapsed here. Literal casts are source-sensitive:
	 * they are retained in the canonical value and considered separately during
	 * expected-versus-catalog comparison so an explicit source cast cannot be
	 * mistaken for a renderer artifact.
	 */
	private static function normalizeCatalogExpressionArtifacts(string $expression): string {
		$identifier='(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*)'.
			'(?:\\.(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*))*';
		$literal="'(?:''|[^'])*'";
		$jsonValue=$identifier.'(?:(?:->|#>)'.$literal.')+';
		$expression=(string)preg_replace('/\\b(and|or|not)\\(/i', '$1 (', $expression);
		do{
			$before=$expression;
			$expression=(string)preg_replace(
				'/\\(\\(([^()]*)\\)\\)(::[A-Za-z_][A-Za-z0-9_ ]*)/i',
				'($1)$2',
				$expression
			);
			$expression=(string)preg_replace(
				'/\\b(jsonb?_typeof|jsonb?_array_length)\\(\\(('.$jsonValue.')\\)\\)/i',
				'$1($2)',
				$expression
			);
		}while($expression!==$before);
		do{
			$before=$expression;
			$expression=(string)preg_replace(
				'/\\(('.$jsonValue.')\\)(?=(?:->>|#>>|->|#>))/',
				'$1',
				$expression
			);
		}while($expression!==$before);
		$expression=(string)preg_replace(
			'/(?<![A-Za-z0-9_$])\\(('.$identifier.'(?:(?:->|#>)'.$literal.')*(?:->>|#>>|->|#>)'.$literal.')\\)'.
			'(?=\\s*(?:=|<>|!=|<=|>=|<|>|@>|<@|~~|!~~|~|!~|'.
			'is(?:\\s+not)?\\s+(?:null|true|false)\\b))/i',
			'$1',
			$expression
		);
		return self::normalizeSqlExpression($expression);
	}

	/** @param list<string> $expected @param list<string> $actual */
	private static function indexKeysEquivalent(array $expected, array $actual): bool {
		if(count($expected)!==count($actual)){
			return false;
		}
		foreach($expected as $index=>$expression){
			if(!isset($actual[$index]) || !self::expressionsEquivalent($expression, $actual[$index])){
				return false;
			}
		}
		return true;
	}

	private static function expressionValuesEquivalent(?string $expected, ?string $actual): bool {
		if($expected===null || $actual===null){
			return $expected===$actual;
		}
		return self::expressionsEquivalent($expected, $actual);
	}

	/**
	 * Raw canonical equality wins. The fallback removes only catalog casts whose
	 * type is already fixed by an exact operator context. It is deliberately
	 * applied to the catalog side only; an explicit source cast remains visible.
	 */
	private static function expressionsEquivalent(string $expected, string $actual): bool {
		if($expected===$actual){
			return true;
		}
		if(!self::expectedTextCastsPresent($expected, $actual)){
			return false;
		}
		$expected=self::stripCatalogTextCasts($expected);
		$expected=self::normalizeEquivalentExpression($expected);
		foreach([false, true] as $stripOperands){
			$catalog=self::stripContextBoundCatalogCasts($actual, $stripOperands);
			$catalog=self::normalizeEquivalentExpression($catalog);
			if($expected===$catalog){
				return true;
			}
		}
		return false;
	}

	private static function expectedTextCastsPresent(string $expected, string $actual): bool {
		$required=self::textCastCounts($expected);
		if($required===[]){
			return true;
		}
		$available=self::textCastCounts($actual);
		foreach($required as $cast=>$count){
			if(($available[$cast] ?? 0)<$count){
				return false;
			}
		}
		return true;
	}

	/** @return array<string,int> */
	private static function textCastCounts(string $expression): array {
		$literal="'(?:''|[^'])*'";
		preg_match_all(
			'/('.$literal.')::(text|character varying)\\b(\\s*\\[\\])?/i',
			$expression,
			$matches,
			PREG_SET_ORDER
		);
		$counts=[];
		foreach($matches as $match){
			$key=$match[1].'::'.strtolower($match[2]).
				(isset($match[3]) && trim($match[3])!=='' ? '[]' : '');
			$counts[$key]=($counts[$key] ?? 0)+1;
		}
		return $counts;
	}

	private static function stripCatalogTextCasts(string $expression): string {
		$literal="'(?:''|[^'])*'";
		return self::normalizeSqlExpression((string)preg_replace(
			'/('.$literal.')::(?:text|character varying)\\b(?:\\s*\\[\\])?/i',
			'$1',
			$expression
		));
	}

	private static function stripContextBoundCatalogCasts(
		string $expression,
		bool $stripOperands=true
	): string {
		$identifier='(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*)'.
			'(?:\\.(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*))*';
		$literal="'(?:''|[^'])*'";
		$type='(?:text|character varying)';
		$value=$identifier.'(?:(?:->|->>|#>|#>>)'.$literal.')*';
		$simpleValue='(?:'.$value.'|\\(\\s*'.$value.'\\s*\\))';
		$numericLiteral="(?:'?-?[0-9]+(?:\\.[0-9]+)?'?)";
		$numericType='(?:smallint|integer|bigint|numeric(?:\\([0-9]+(?:,[0-9]+)?\\))?|real|double precision)';

		// mod(column, literal) resolves the literal to the exact built-in column
		// overload. A casted first operand is intentionally outside this rule.
		$expression=(string)preg_replace(
			'/\\bmod\\(('.$identifier.'),\\((-?[0-9]+)\\)::'.
			'(?:smallint|integer|bigint)\\)/i',
			'mod($1, $2)',
			$expression
		);

		// JSON operators fix their literal operand type (text or text[]).
		$expression=(string)preg_replace(
			'/((?:->|->>)'.$literal.')::'.$type.'\\b/i',
			'$1',
			$expression
		);
		$expression=(string)preg_replace(
			'/((?:#>|#>>)'.$literal.')::'.$type.'\\s*\\[\\](?![A-Za-z0-9_$])/i',
			'$1',
			$expression
		);
		// Unknown string literals are rendered with their resolved text cast.
		// Strip that annotation only from the catalog side; explicit migration
		// casts therefore remain visible and contract-significant.
		$expression=(string)preg_replace(
			'/('.$literal.')::'.$type.'\\b(?!\\s*\\[)/i',
			'$1',
			$expression
		);

		if($stripOperands){
			// PostgreSQL resolves varchar-only text functions through their text
			// signatures and records the implicit column cast in pg_get_expr().
			// Keep this bounded to exact built-in one-argument contexts.
			$expression=(string)preg_replace(
				'/\\b(btrim|ltrim|rtrim|lower|upper)'.
				'\\(('.$identifier.')::'.$type.'\\)/i',
				'$1($2)',
				$expression
			);

			// IN is rendered as ANY/ALL, and varchar membership adds an implicit
			// cast to the left operand. Array/member annotations are handled by
			// the membership normalizer below.
			$expression=(string)preg_replace_callback(
				'/('.$simpleValue.')::'.$type.'\\b(\\s+(?:not\\s+)?in)\\(/i',
				static fn(array $match): string=>
					self::stripOuterParentheses(trim($match[1])).$match[2].'(',
				$expression
			);
		}

		// Membership leaves have already been isolated by the Boolean parser.
		$expression=(string)preg_replace_callback(
			'/\\b((?:not\\s+)?in)\\(([^()]*)\\)/i',
			static function(array $match) use ($literal, $type): string {
				$members=(string)preg_replace(
					'/('.$literal.')::'.$type.'\\b/i',
					'$1',
					$match[2]
				);
				return $match[1].'('.$members.')';
			},
			$expression
		);

		// A simple column/JSON value fixes the comparison literal type. Function
		// arguments are excluded so overload-selecting casts remain significant.
		$comparison='(?:=|<>|!=|<=|>=|<|>)';
		// A comparison with a concrete column fixes a numeric constant's type.
		// PostgreSQL 17 may render integral constants as quoted bigint literals
		// and numeric constants with an explicit numeric cast. Strip only that
		// catalog annotation; casts in the migration-side expression stay intact.
		$expression=(string)preg_replace_callback(
			'/('.$value.$comparison.')\\(?('.$numericLiteral.')\\)?::'.$numericType.'\\b/i',
			static function(array $match): string {
				$number=$match[2];
				if(str_starts_with($number, "'") && str_ends_with($number, "'")){
					$number=substr($number, 1, -1);
				}
				return $match[1].$number;
			},
			$expression
		);
		// COALESCE(column, numeric literal) resolves the fallback to the exact
		// column type, so the catalog's literal cast is renderer-only as well.
		$expression=(string)preg_replace_callback(
			'/\\bcoalesce\\(('.$identifier.'),\\s*\\(?('.$numericLiteral.')\\)?::'.$numericType.'\\)/i',
			static function(array $match): string {
				$number=$match[2];
				if(str_starts_with($number, "'") && str_ends_with($number, "'")){
					$number=substr($number, 1, -1);
				}
				return 'coalesce('.$match[1].','.$number.')';
			},
			$expression
		);
		if($stripOperands){
			$expression=(string)preg_replace_callback(
				'/('.$simpleValue.')::'.$type.'('.$comparison.')/i',
				static fn(array $match): string=>
					self::stripOuterParentheses(trim($match[1])).$match[2],
				$expression
			);
			$patternOperator='(?:!~~\\*?|~~\\*?|!~\\*?|~\\*?)';
			$expression=(string)preg_replace_callback(
				'/('.$simpleValue.')::'.$type.'('.$patternOperator.')/i',
				static fn(array $match): string=>
					self::stripOuterParentheses(trim($match[1])).$match[2],
				$expression
			);
		}
		$expression=(string)preg_replace(
			'/('.$value.$comparison.')('.$literal.')::'.$type.'\\b/i',
			'$1$2',
			$expression
		);
		$expression=(string)preg_replace(
			'/('.$literal.')::'.$type.'('.$comparison.$value.')\\b/i',
			'$1$2',
			$expression
		);
		return self::normalizeSqlExpression($expression);
	}

	/**
	 * Canonicalizes only renderer artifacts that remain after the context-bound
	 * cast pass. This helper is shared by CHECK and expression-index comparison;
	 * it does not reorder operands or erase migration-authored casts.
	 */
	private static function normalizeEquivalentExpression(string $expression): string {
		$expression=self::normalizeCatalogExpressionArtifacts($expression);
		$expression=self::normalizeEmbeddedTextMembershipExpressions($expression);
		$expression=self::normalizePatternOperatorAliases($expression);
		$expression=self::normalizeJsonLiteralCasts($expression);
		$expression=self::normalizeRedundantCatalogOperandParentheses($expression);
		return self::normalizeSqlExpression($expression);
	}

	/** PostgreSQL renders the SQL LIKE family with its exact internal operators. */
	private static function normalizePatternOperatorAliases(string $expression): string {
		return self::normalizeSqlExpression(str_replace(
			['!~~*', '~~*', '!~~', '~~'],
			[' not ilike ', ' ilike ', ' not like ', ' like '],
			$expression
		));
	}

	/**
	 * PostgreSQL can render IN/NOT IN inside a larger comparison as ANY/ALL.
	 * The Boolean leaf normalizer cannot see through an enclosing equality, so
	 * normalize the bounded simple-column/text-array form before parsing leaves.
	 */
	private static function normalizeEmbeddedTextMembershipExpressions(string $expression): string {
		$identifier='(?:(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*)'.
			'(?:\\.(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*))*)';
		$left=$identifier.'(?:::(?:text|character varying))?';
		$array='array\\s*\\[(?:[^\\[\\]]|\\[(?:[^\\[\\]]*)\\])*\\]'.
			'(?:::(?:text|character\\s+varying|varchar)\\s*\\[\\])?';
		do{
			$before=$expression;
			$expression=(string)preg_replace_callback(
				'/('.$left.')(=any|<>all)\\(('.$array.')\\)/i',
				static function(array $match): string {
					$members=self::textMembershipArrayMembers($match[3]);
					if($members===null || $members===''){
						return $match[0];
					}
					return $match[1].($match[2]==='=any' ? ' in(' : ' not in(').$members.')';
				},
				$expression
			);
		}while($expression!==$before);
		return $expression;
	}

	/** PostgreSQL json/jsonb values ignore insignificant literal whitespace. */
	private static function normalizeJsonLiteralCasts(string $expression): string {
		$literal="'(?:''|[^'])*'";
		return (string)preg_replace_callback(
			'/('.$literal.')::(jsonb?)\\b/i',
			static function(array $match): string {
				$source=str_replace("''", "'", substr($match[1], 1, -1));
				try{
					$value=json_decode($source, true, 512, JSON_THROW_ON_ERROR);
					$canonical=json_encode(
						$value,
						JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES |
						JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
					);
				}catch(\JsonException){
					return $match[0];
				}
				return "'".str_replace("'", "''", $canonical)."'::".strtolower($match[2]);
			},
			$expression
		);
	}

	/**
	 * Removes only catalog grouping around one scalar comparison operand. The
	 * contents must be a cast or arithmetic expression and may not contain a
	 * Boolean/comparison operator, so meaningful predicate grouping survives.
	 */
	private static function normalizeRedundantCatalogOperandParentheses(string $expression): string {
		$comparison='(?:=|<>|!=|<=|>=|<|>|!~~\\*?|~~\\*?|!~\\*?|~\\*?)';
		do{
			$before=$expression;
			$expression=(string)preg_replace(
				'/\\(\\(([^()]*)\\)::([A-Za-z_][A-Za-z0-9_ ]*)\\)(?='.$comparison.')/i',
				'($1)::$2',
				$expression
			);
			$expression=(string)preg_replace(
				'/('.$comparison.')\\(\\(([^()]*)\\)::([A-Za-z_][A-Za-z0-9_ ]*)\\)/i',
				'$1($2)::$3',
				$expression
			);
			$expression=(string)preg_replace_callback(
				'/('.$comparison.')\\(([^()]*)\\)/i',
				static function(array $match): string {
					$operand=trim($match[2]);
					if(
						preg_match('/\\b(?:and|or)\\b|(?:=|<>|!=|<=|>=|<|>)/i', $operand)===1
						|| preg_match('/(?:\\*|\\/|::|->|#>)/', $operand)!==1
					){
						return $match[0];
					}
					return $match[1].$operand;
				},
				$expression
			);
		}while($expression!==$before);
		return $expression;
	}

	public static function normalizeSqlExpression(string $expression): string {
		$tokens=[];
		$length=strlen($expression);
		for($index=0; $index<$length;){
			$character=$expression[$index];
			if(ctype_space($character)){
				$index++;
				continue;
			}
			if($character==="'" || $character==='"'){
				$quote=$character;
				$token=$character;
				$index++;
				while($index<$length){
					$token.=$expression[$index];
					if($expression[$index]===$quote){
						if($index+1<$length && $expression[$index+1]===$quote){
							$token.=$expression[++$index];
							$index++;
							continue;
						}
						$index++;
						break;
					}
					$index++;
				}
				$tokens[]=['value'=>$token, 'kind'=>$quote==="'" ? 'literal' : 'identifier'];
				continue;
			}
			if(ctype_alpha($character) || $character==='_'){
				$start=$index++;
				while(
					$index<$length
					&& (
						ctype_alnum($expression[$index])
						|| $expression[$index]==='_'
						|| $expression[$index]==='$'
					)
				){
					$index++;
				}
				$tokens[]=['value'=>strtolower(substr($expression, $start, $index-$start)), 'kind'=>'word'];
				continue;
			}
			if(ctype_digit($character)){
				$start=$index++;
				while($index<$length && preg_match('/[0-9.eE+-]/', $expression[$index])===1){
					$index++;
				}
				$tokens[]=['value'=>strtolower(substr($expression, $start, $index-$start)), 'kind'=>'number'];
				continue;
			}
			$operator=null;
			foreach(['->>', '#>>', '::', '>=', '<=', '<>', '!=', '||', '&&', '->', '#>', '@>', '<@'] as $candidate){
				if(substr($expression, $index, strlen($candidate))===$candidate){
					$operator=$candidate;
					break;
				}
			}
			if($operator!==null){
				$tokens[]=['value'=>$operator, 'kind'=>'operator'];
				$index+=strlen($operator);
				continue;
			}
			$tokens[]=[
				'value'=>$character,
				'kind'=>str_contains('=<>+-*/%^~!', $character) ? 'operator' : 'punctuation',
			];
			$index++;
		}
		$normalized='';
		$previous=null;
		foreach($tokens as $token){
			$value=$token['value'];
			if($token['kind']==='identifier'){
				$identifier=str_replace('""', '"', substr($value, 1, -1));
				if(
					preg_match('/^[a-z_][a-z0-9_$]*$/', $identifier)===1
					&& !in_array($identifier, [
						'true', 'false', 'null', 'current_user', 'current_role',
						'current_date', 'current_time', 'current_timestamp',
						'localtime', 'localtimestamp', 'session_user', 'system_user',
						'user', 'current_catalog', 'current_schema',
					], true)
				){
					$value=$identifier;
				}
			}
			$space=$previous!==null;
			if(
				in_array($value, [')', ']', ',', '.', '::'], true)
				|| ($previous!==null && in_array($previous['value'], ['(', '[', '.', '::'], true))
				|| $value==='('
				|| $token['kind']==='operator'
				|| ($previous['kind'] ?? null)==='operator'
			){
				$space=false;
			}
			if($space){
				$normalized.=' ';
			}
			$normalized.=$value;
			$previous=$token;
		}
		return self::stripOuterParentheses(trim($normalized));
	}

	public static function normalizeType(string $type): string {
		$type=strtolower(trim((string)preg_replace('/\\s+/', ' ', $type)));
		$type=(string)preg_replace('/\\(\\s*/', '(', $type);
		$type=(string)preg_replace('/\\s*\\)/', ')', $type);
		$type=(string)preg_replace('/\\s*,\\s*/', ',', $type);
		$aliases=[
			'int'=>'integer',
			'int4'=>'integer',
			'int8'=>'bigint',
			'float8'=>'double precision',
			'bool'=>'boolean',
			'timestamp'=>'timestamp without time zone',
			'timestamptz'=>'timestamp with time zone',
			'time'=>'time without time zone',
			'timetz'=>'time with time zone',
		];
		if(isset($aliases[$type])){
			return $aliases[$type];
		}
		$type=(string)preg_replace('/^varchar\\s*(?=\\(|$)/', 'character varying', $type);
		$type=(string)preg_replace('/^char\\s*(?=\\(|$)/', 'character', $type);
		return (string)preg_replace('/^decimal\\s*(?=\\(|$)/', 'numeric', $type);
	}

	/**
	 * PostgreSQL stores CHECK expressions as parsed trees and prints a canonical
	 * form that removes redundant Boolean grouping and expands BETWEEN. Migration
	 * SQL must be compared in the same dialect so equivalent catalog output does
	 * not become false schema drift. The renderer keeps the SQL precedence of OR
	 * below AND; it never reorders operands or applies algebraic simplification.
	 */
	private static function normalizeBooleanCheckExpression(
		string $expression,
		int $parentPrecedence=0
	): string {
		$expression=self::stripOuterParentheses(trim($expression));
		$orParts=self::splitTopLevelBoolean($expression, 'or');
		if(count($orParts)>1){
			$normalized=implode(' or ', array_map(
				static fn(string $part): string=>self::normalizeBooleanCheckExpression($part, 1),
				$orParts
			));
			return $parentPrecedence>1 ? '('.$normalized.')' : $normalized;
		}
		$andParts=self::splitTopLevelBoolean($expression, 'and');
		if(count($andParts)>1){
			$normalized=implode(' and ', array_map(
				static fn(string $part): string=>self::normalizeBooleanCheckExpression($part, 2),
				$andParts
			));
			return $parentPrecedence>2 ? '('.$normalized.')' : $normalized;
		}
		$between=self::topLevelBetweenParts($expression);
		if($between!==null){
			[$value, $minimum, $maximum]=$between;
			return self::normalizeBooleanCheckExpression(
				$value.'>='.$minimum.' and '.$value.'<='.$maximum,
				$parentPrecedence
			);
		}
		return self::normalizeMembershipCheckExpression($expression);
	}

	/**
	 * PostgreSQL renders an IN list as = ANY (ARRAY[...]) and a NOT IN list as
	 * <> ALL (ARRAY[...]). The Boolean renderer has isolated one complete leaf
	 * before this method runs, so a compound left operand cannot consume a
	 * neighbouring AND/OR branch or erase its grouping.
	 */
	private static function normalizeMembershipCheckExpression(string $expression): string {
		foreach([
			'=any'=>' in',
			'<>all'=>' not in',
		] as $operator=>$replacement){
			if(preg_match(
				'/^(.+?)'.preg_quote($operator, '/').'\\((.*)\\)$/is',
				$expression,
				$match
			)!==1){
				continue;
			}
			$left=trim($match[1]);
			$members=self::textMembershipArrayMembers($match[2]);
			if($left!=='' && $members!==null && $members!==''){
				return $left.$replacement.'('.$members.')';
			}
		}
		return $expression;
	}

	/**
	 * Extract members from PostgreSQL's canonical text-array form used by
	 * varchar/text IN and NOT IN checks. Other array casts stay opaque so a
	 * domain/operator change cannot be accepted as renderer-only drift.
	 */
	private static function textMembershipArrayMembers(string $expression): ?string {
		$expression=self::stripOuterParentheses(trim($expression));
		$outerTail='';
		if(str_starts_with($expression, '(')){
			$closing=self::matchingParenthesis($expression, 0);
			if($closing<strlen($expression)-1){
				$outerTail=trim(substr($expression, $closing+1));
				$expression=self::stripOuterParentheses(
					trim(substr($expression, 1, $closing-1))
				);
			}
		}
		if(preg_match('/^array\\s*\\[/i', $expression, $openingMatch)!==1){
			return null;
		}
		$opening=strpos($openingMatch[0], '[');
		if($opening===false){
			return null;
		}
		$closing=self::matchingSquareBracket($expression, $opening);
		$tail=trim(substr($expression, $closing+1)).$outerTail;
		if(
			$tail!==''
			&& preg_match('/^::(?:text|character\\s+varying|varchar)\\s*\\[\\]$/i', $tail)!==1
		){
			return null;
		}
		$members=trim(substr($expression, $opening+1, $closing-$opening-1));
		return $members==='' ? null : $members;
	}

	/** @return list<string> */
	private static function splitTopLevelBoolean(string $expression, string $keyword): array {
		$parts=[];
		$start=0;
		$depth=0;
		$bracketDepth=0;
		$quote=null;
		$betweenPending=false;
		$length=strlen($expression);
		for($index=0; $index<$length; $index++){
			$character=$expression[$index];
			if($quote!==null){
				if($character===$quote){
					if($index+1<$length && $expression[$index+1]===$quote){
						$index++;
						continue;
					}
					$quote=null;
				}
				continue;
			}
			if($character==="'" || $character==='"'){
				$quote=$character;
				continue;
			}
			if($character==='('){
				$depth++;
				continue;
			}
			if($character===')'){
				$depth=max(0, $depth-1);
				continue;
			}
			if($character==='['){
				$bracketDepth++;
				continue;
			}
			if($character===']'){
				$bracketDepth=max(0, $bracketDepth-1);
				continue;
			}
			if($depth!==0 || $bracketDepth!==0 || !(ctype_alpha($character) || $character==='_')){
				continue;
			}
			$end=$index+1;
			while($end<$length && (ctype_alnum($expression[$end]) || $expression[$end]==='_')){
				$end++;
			}
			$word=strtolower(substr($expression, $index, $end-$index));
			if($keyword==='and' && $word==='between'){
				$betweenPending=true;
			}elseif($word===$keyword){
				if($keyword==='and' && $betweenPending){
					$betweenPending=false;
				}else{
					$part=trim(substr($expression, $start, $index-$start));
					if($part!==''){
						$parts[]=$part;
					}
					$start=$end;
				}
			}
			$index=$end-1;
		}
		$part=trim(substr($expression, $start));
		if($part!==''){
			$parts[]=$part;
		}
		return $parts;
	}

	/** @return ?array{0:string,1:string,2:string} */
	private static function topLevelBetweenParts(string $expression): ?array {
		$betweenStart=null;
		$betweenEnd=null;
		$depth=0;
		$bracketDepth=0;
		$quote=null;
		$length=strlen($expression);
		for($index=0; $index<$length; $index++){
			$character=$expression[$index];
			if($quote!==null){
				if($character===$quote){
					if($index+1<$length && $expression[$index+1]===$quote){
						$index++;
						continue;
					}
					$quote=null;
				}
				continue;
			}
			if($character==="'" || $character==='"'){
				$quote=$character;
				continue;
			}
			if($character==='('){
				$depth++;
				continue;
			}
			if($character===')'){
				$depth=max(0, $depth-1);
				continue;
			}
			if($character==='['){
				$bracketDepth++;
				continue;
			}
			if($character===']'){
				$bracketDepth=max(0, $bracketDepth-1);
				continue;
			}
			if($depth!==0 || $bracketDepth!==0 || !(ctype_alpha($character) || $character==='_')){
				continue;
			}
			$end=$index+1;
			while($end<$length && (ctype_alnum($expression[$end]) || $expression[$end]==='_')){
				$end++;
			}
			$word=strtolower(substr($expression, $index, $end-$index));
			if($betweenStart===null && $word==='between'){
				$betweenStart=$index;
				$betweenEnd=$end;
			}elseif($betweenStart!==null && $word==='and'){
				$value=trim(substr($expression, 0, $betweenStart));
				$minimum=trim(substr($expression, $betweenEnd, $index-$betweenEnd));
				$maximum=trim(substr($expression, $end));
				if(
					preg_match('/\\bnot$/i', $value)===1
					|| preg_match('/^symmetric\\b/i', $minimum)===1
				){
					return null;
				}
				if($value!=='' && $minimum!=='' && $maximum!==''){
					return [$value, $minimum, $maximum];
				}
				return null;
			}
			$index=$end-1;
		}
		return null;
	}

	private static function normalizeIndexKey(string $key): string {
		$key=self::normalizeIndexExpression($key);
		$nulls=null;
		if(preg_match('/\\s+nulls\\s+(first|last)$/', $key, $nullsMatch)===1){
			$nulls=$nullsMatch[1];
			$key=trim(substr($key, 0, -strlen($nullsMatch[0])));
		}
		$direction='asc';
		if(preg_match('/\\s+(asc|desc)$/', $key, $directionMatch)===1){
			$direction=$directionMatch[1];
			$key=trim(substr($key, 0, -strlen($directionMatch[0])));
		}
		$key=self::stripOuterParentheses(trim($key));
		if($direction==='desc'){
			$key.=' desc';
		}
		$defaultNulls=$direction==='desc' ? 'first' : 'last';
		if($nulls!==null && $nulls!==$defaultNulls){
			$key.=' nulls '.$nulls;
		}
		return $key;
	}

	private static function normalizeIndexExpression(string $expression): string {
		$normalized=self::normalizeCatalogExpressionArtifacts(
			self::normalizeSqlExpression($expression)
		);
		$normalized=self::normalizeEmbeddedTextMembershipExpressions($normalized);
		$normalized=(string)preg_replace_callback(
			'/\\b([A-Za-z_][A-Za-z0-9_$.\"]*)=any\\(array\\s*\\[([^\\[\\]]*)\\]\\)/i',
			static fn(array $match): string=>$match[1].' in('.trim($match[2]).')',
			$normalized
		);
		$normalized=(string)preg_replace(
			'/\\b(and|or)\\(([A-Za-z_][A-Za-z0-9_$.\"]*\\s+in\\([^()]*\\))\\)/i',
			'$1 $2',
			$normalized
		);
		while(preg_match('/^\\(\\(([^()]*)\\)\\)(::.+)$/s', $normalized, $match)===1){
			$normalized='('.$match[1].')'.$match[2];
		}
		return self::stripOuterParentheses(trim($normalized));
	}

	private static function normalizeIndexPredicate(string $expression): string {
		return self::normalizeBooleanCheckExpression(
			self::normalizeIndexExpression($expression)
		);
	}

	/**
	 * Project a migration into DDL that executes while the migration is applied.
	 * Stored routine bodies and inert literals are never schema declarations.
	 * The one supported conditional is the framework's PostgreSQL/YugabyteDB
	 * product split; every other conditional index expression fails closed.
	 */
	private static function migrationSchemaSql(
		string $sql,
		string $databaseDialect
	): string {
		$projected=[];
		foreach(self::topLevelSqlStatements($sql) as $statement){
			$shape=ltrim(self::maskSqlLiteralsAndComments($statement));
			if(preg_match('/^DO\b/i', $shape)===1){
				$body=self::doBody($statement);
				if($body!==null){
					$ddl=self::projectDoSchemaSql($body, $databaseDialect);
					if(trim($ddl)!==''){
						$projected[]=$ddl;
					}
				}
				continue;
			}
			if(preg_match(
				'/^(?:CREATE\s+(?:UNIQUE\s+)?INDEX|CREATE\s+TABLE|'.
					'ALTER\s+TABLE|DROP\s+(?:INDEX|TABLE))\b/i',
				$shape
			)===1){
				$projected[]=rtrim(self::stripSqlComments($statement), "; \t\r\n").';';
			}
		}
		return implode("\n", $projected);
	}

	/** @return list<string> */
	private static function topLevelSqlStatements(string $sql): array {
		$statements=[];
		$start=0;
		$lineComment=false;
		$blockDepth=0;
		$quote=null;
		$dollar=null;
		$escape=false;
		$length=strlen($sql);
		for($index=0; $index<$length; $index++){
			$character=$sql[$index];
			if($lineComment){
				if($character==="\n" || $character==="\r"){
					$lineComment=false;
				}
				continue;
			}
			if($blockDepth>0){
				if($character==='/' && ($sql[$index+1] ?? null)==='*'){
					$blockDepth++;
					$index++;
				}elseif($character==='*' && ($sql[$index+1] ?? null)==='/'){
					$blockDepth--;
					$index++;
				}
				continue;
			}
			if($quote!==null){
				if($escape && $character==='\\'){
					$index++;
					continue;
				}
				if($character===$quote){
					if(($sql[$index+1] ?? null)===$quote){
						$index++;
					}else{
						$quote=null;
						$escape=false;
					}
				}
				continue;
			}
			if($dollar!==null){
				if(substr($sql, $index, strlen($dollar))===$dollar){
					$index+=strlen($dollar)-1;
					$dollar=null;
				}
				continue;
			}
			if($character==='-' && ($sql[$index+1] ?? null)==='-'){
				$lineComment=true;
				$index++;
				continue;
			}
			if($character==='/' && ($sql[$index+1] ?? null)==='*'){
				$blockDepth=1;
				$index++;
				continue;
			}
			if($character==="'" || $character==='"'){
				$quote=$character;
				$escape=$character==="'"
					&& $index>0
					&& in_array($sql[$index-1], ['e', 'E'], true)
					&& ($index<2 || preg_match('/[A-Za-z0-9_$]/', $sql[$index-2])!==1);
				continue;
			}
			$delimiter=self::dollarQuoteDelimiterAt($sql, $index);
			if($delimiter!==null){
				$dollar=$delimiter;
				$index+=strlen($delimiter)-1;
				continue;
			}
			if($character===';'){
				$statement=trim(substr($sql, $start, $index-$start));
				if($statement!==''){
					$statements[]=$statement;
				}
				$start=$index+1;
			}
		}
		$statement=trim(substr($sql, $start));
		if($statement!==''){
			$statements[]=$statement;
		}
		return $statements;
	}

	private static function maskSqlLiteralsAndComments(string $sql): string {
		$masked=$sql;
		$lineComment=false;
		$blockDepth=0;
		$quote=null;
		$dollar=null;
		$escape=false;
		$length=strlen($sql);
		for($index=0; $index<$length; $index++){
			$character=$sql[$index];
			if($lineComment){
				if($character==="\n" || $character==="\r"){
					$lineComment=false;
				}else{
					$masked[$index]=' ';
				}
				continue;
			}
			if($blockDepth>0){
				$masked[$index]=ctype_space($character) ? $character : ' ';
				if($character==='/' && ($sql[$index+1] ?? null)==='*'){
					$masked[++$index]=' ';
					$blockDepth++;
				}elseif($character==='*' && ($sql[$index+1] ?? null)==='/'){
					$masked[++$index]=' ';
					$blockDepth--;
				}
				continue;
			}
			if($quote!==null){
				$masked[$index]=ctype_space($character) ? $character : ' ';
				if($escape && $character==='\\'){
					if($index+1<$length){
						$masked[++$index]=' ';
					}
					continue;
				}
				if($character===$quote){
					if(($sql[$index+1] ?? null)===$quote){
						$masked[++$index]=' ';
					}else{
						$quote=null;
						$escape=false;
					}
				}
				continue;
			}
			if($dollar!==null){
				$masked[$index]=ctype_space($character) ? $character : ' ';
				if(substr($sql, $index, strlen($dollar))===$dollar){
					for($offset=1; $offset<strlen($dollar); $offset++){
						$masked[$index+$offset]=' ';
					}
					$index+=strlen($dollar)-1;
					$dollar=null;
				}
				continue;
			}
			if($character==='-' && ($sql[$index+1] ?? null)==='-'){
				$masked[$index]=' ';
				$index++;
				$masked[$index]=' ';
				$lineComment=true;
				continue;
			}
			if($character==='/' && ($sql[$index+1] ?? null)==='*'){
				$masked[$index]=' ';
				$index++;
				$masked[$index]=' ';
				$blockDepth=1;
				continue;
			}
			if($character==="'" || $character==='"'){
				$masked[$index]=' ';
				$quote=$character;
				$escape=$character==="'"
					&& $index>0
					&& in_array($sql[$index-1], ['e', 'E'], true)
					&& ($index<2 || preg_match('/[A-Za-z0-9_$]/', $sql[$index-2])!==1);
				continue;
			}
			$delimiter=self::dollarQuoteDelimiterAt($sql, $index);
			if($delimiter!==null){
				for($offset=0; $offset<strlen($delimiter); $offset++){
					$masked[$index+$offset]=' ';
				}
				$dollar=$delimiter;
				$index+=strlen($delimiter)-1;
			}
		}
		return $masked;
	}

	private static function doBody(string $statement): ?string {
		$shape=self::maskSqlLiteralsAndComments($statement);
		if(preg_match('/^\s*DO\b/i', $shape, $do, PREG_OFFSET_CAPTURE)!==1){
			return null;
		}
		$searchOffset=$do[0][1]+strlen($do[0][0]);
		$lineComment=false;
		$blockDepth=0;
		$quote=null;
		$escape=false;
		$length=strlen($statement);
		for($index=$searchOffset; $index<$length; $index++){
			$character=$statement[$index];
			if($lineComment){
				if($character==="\n" || $character==="\r"){
					$lineComment=false;
				}
				continue;
			}
			if($blockDepth>0){
				if($character==='/' && ($statement[$index+1] ?? null)==='*'){
					$blockDepth++;
					$index++;
				}elseif($character==='*' && ($statement[$index+1] ?? null)==='/'){
					$blockDepth--;
					$index++;
				}
				continue;
			}
			if($quote!==null){
				if($escape && $character==='\\'){
					$index++;
					continue;
				}
				if($character===$quote){
					if(($statement[$index+1] ?? null)===$quote){
						$index++;
					}else{
						$quote=null;
						$escape=false;
					}
				}
				continue;
			}
			if($character==='-' && ($statement[$index+1] ?? null)==='-'){
				$lineComment=true;
				$index++;
				continue;
			}
			if($character==='/' && ($statement[$index+1] ?? null)==='*'){
				$blockDepth=1;
				$index++;
				continue;
			}
			if($character==="'" || $character==='"'){
				$quote=$character;
				$escape=$character==="'"
					&& $index>0
					&& in_array($statement[$index-1], ['e', 'E'], true)
					&& ($index<2 || preg_match('/[A-Za-z0-9_$]/', $statement[$index-2])!==1);
				continue;
			}
			$delimiter=self::dollarQuoteDelimiterAt($statement, $index);
			if($delimiter===null){
				continue;
			}
			$end=strpos($statement, $delimiter, $index+strlen($delimiter));
			if($end===false){
				throw new RuntimeException('Unclosed DO body in applied migration definition.');
			}
			return substr(
				$statement,
				$index+strlen($delimiter),
				$end-$index-strlen($delimiter)
			);
		}
		throw new RuntimeException('Cannot inspect a DO statement without one fixed dollar-quoted body.');
	}

	private static function projectDoSchemaSql(
		string $body,
		string $databaseDialect
	): string {
		$productPrefix='/\A\s*BEGIN\s+IF\s+position\s*\(\s*'.
			"'YugabyteDB'\\s+IN\\s+version\\s*\\(\\s*\\)\\s*\\)".
			'\s*>\s*0\s+THEN\b/is';
		if(preg_match($productPrefix, $body, $prefix, PREG_OFFSET_CAPTURE)===1){
			$thenStart=$prefix[0][1]+strlen($prefix[0][0]);
			$mask=self::maskSqlLiteralsAndComments($body);
			if(
				preg_match('/\bELSE\b/i', $mask, $else, PREG_OFFSET_CAPTURE, $thenStart)!==1
				|| preg_match(
					'/\bEND\s+IF\s*;/i',
					$mask,
					$endIf,
					PREG_OFFSET_CAPTURE,
					$else[0][1]+strlen($else[0][0])
				)!==1
			){
				throw new RuntimeException('Cannot inspect the database-product migration branch.');
			}
			$tail=substr($mask, $endIf[0][1]+strlen($endIf[0][0]));
			if(preg_match('/^\s*END\s*;?\s*$/i', $tail)!==1){
				throw new RuntimeException('Database-product migration branch has unsupported control flow.');
			}
			$selected=$databaseDialect==='yugabyte'
				? substr($body, $thenStart, $else[0][1]-$thenStart)
				: substr(
					$body,
					$else[0][1]+strlen($else[0][0]),
					$endIf[0][1]-$else[0][1]-strlen($else[0][0])
				);
			return self::projectPlpgsqlSchemaSql($selected, $databaseDialect, true);
		}

		$mask=self::maskSqlLiteralsAndComments($body);
		if(
			preg_match('/\bBEGIN\b/i', $mask, $begin, PREG_OFFSET_CAPTURE)!==1
			|| preg_match('/\bEND\s*;?\s*$/i', $mask, $end, PREG_OFFSET_CAPTURE)!==1
		){
			throw new RuntimeException('Cannot inspect the applied migration DO body.');
		}
		$content=substr(
			$body,
			$begin[0][1]+strlen($begin[0][0]),
			$end[0][1]-$begin[0][1]-strlen($begin[0][0])
		);
		return self::projectPlpgsqlSchemaSql($content, $databaseDialect, false);
	}

	private static function projectPlpgsqlSchemaSql(
		string $sql,
		string $databaseDialect,
		bool $controlResolved
	): string {
		if(
			!$controlResolved
			&& self::hasUnsupportedPlpgsqlControl($sql)
			&& self::containsIndexDdlEvidence($sql)
		){
			throw new RuntimeException(
				'Cannot certify index DDL behind unsupported migration control flow.'
			);
		}
		$projected=[];
		foreach(self::topLevelSqlStatements($sql) as $statement){
			$statementShape=ltrim(self::maskSqlLiteralsAndComments($statement));
			if(preg_match('/^EXECUTE\b(?!\s+(?:FUNCTION|PROCEDURE)\b)/i', $statementShape)===1){
				$fixed=self::fixedExecutedSql($statement);
				if($fixed===null){
					if(self::containsIndexDdlEvidence($statement)){
						throw new RuntimeException(
							'Cannot certify index DDL from a non-fixed EXECUTE expression.'
						);
					}
					continue;
				}
				if($fixed['escape'] && str_contains($fixed['sql'], '\\')){
					throw new RuntimeException(
						'Cannot certify a PostgreSQL escape-string EXECUTE containing backslash escapes.'
					);
				}
				$ddl=self::migrationSchemaSql($fixed['sql'], $databaseDialect);
				if(trim($ddl)!==''){
					$projected[]=$ddl;
				}
				continue;
			}
			if(preg_match(
				'/^(?:CREATE\s+(?:UNIQUE\s+)?INDEX|CREATE\s+TABLE|'.
					'ALTER\s+TABLE|DROP\s+(?:INDEX|TABLE))\b/i',
				$statementShape
			)===1){
				$projected[]=rtrim(self::stripSqlComments($statement), "; \t\r\n").';';
			}
		}
		return implode("\n", $projected);
	}

	private static function hasUnsupportedPlpgsqlControl(string $sql): bool {
		foreach(self::topLevelSqlStatements($sql) as $statement){
			$shape=ltrim(self::maskSqlLiteralsAndComments($statement));
			if(preg_match(
				'/^(?:<<[A-Za-z_][A-Za-z0-9_$]*>>\s*)?'.
					'(?:BEGIN|IF|CASE|LOOP|WHILE|FOR|FOREACH|EXCEPTION)\b/i',
				$shape
			)===1){
				return true;
			}
		}
		return false;
	}

	/** @return ?array{sql:string,escape:bool} */
	private static function fixedExecutedSql(string $statement): ?array {
		$shape=self::maskSqlLiteralsAndComments($statement);
		if(preg_match('/^\s*EXECUTE\b/i', $shape, $execute, PREG_OFFSET_CAPTURE)!==1){
			return null;
		}
		$offset=self::skipSqlTrivia(
			$statement,
			$execute[0][1]+strlen($execute[0][0])
		);
		$escape=false;
		if(
			in_array($statement[$offset] ?? null, ['e', 'E'], true)
			&& ($statement[$offset+1] ?? null)==="'"
		){
			$escape=true;
			$offset++;
		}
		$delimiter=null;
		if(($statement[$offset] ?? null)==="'"){
			$delimiter="'";
		}elseif(($statement[$offset] ?? null)==='$'){
			$delimiter=self::dollarQuoteDelimiterAt($statement, $offset);
		}
		if($delimiter===null){
			return null;
		}
		$context=[
			'type'=>$delimiter==="'" ? 'single' : 'dollar',
			'start'=>$offset,
			'content_start'=>$offset+strlen($delimiter),
			'parent_content_start'=>0,
			'delimiter'=>$delimiter,
			'escape'=>$escape,
		];
		$end=self::sqlLiteralEnd($statement, $context);
		$closingEnd=$end+strlen($delimiter);
		if(trim(self::stripSqlComments(substr($statement, $closingEnd)))!==''){
			return null;
		}
		$sql=substr($statement, $context['content_start'], $end-$context['content_start']);
		if($delimiter==="'"){
			$sql=str_replace("''", "'", $sql);
		}
		return ['sql'=>$sql, 'escape'=>$escape];
	}

	private static function skipSqlTrivia(string $sql, int $offset): int {
		$length=strlen($sql);
		while($offset<$length){
			if(ctype_space($sql[$offset])){
				$offset++;
				continue;
			}
			if(substr($sql, $offset, 2)==='--'){
				$newline=strcspn($sql, "\r\n", $offset+2)+$offset+2;
				$offset=min($length, $newline);
				continue;
			}
			if(substr($sql, $offset, 2)==='/*'){
				$depth=1;
				$offset+=2;
				while($offset<$length && $depth>0){
					if(substr($sql, $offset, 2)==='/*'){
						$depth++;
						$offset+=2;
					}elseif(substr($sql, $offset, 2)==='*/'){
						$depth--;
						$offset+=2;
					}else{
						$offset++;
					}
				}
				continue;
			}
			break;
		}
		return $offset;
	}

	private static function containsIndexDdlEvidence(string $sql): bool {
		$evidence=self::maskSqlLiteralsAndComments($sql);
		foreach(self::sqlLiteralFragments($sql) as $fragment){
			$evidence.=' '.$fragment;
		}
		if(preg_match('/\bCREATE\b.{0,8192}\bINDEX\b/is', $evidence)===1){
			return true;
		}
		return preg_match('/\bCREATE\b/i', $evidence)===1
			&& preg_match('/\\\\(?:u|U|x|[0-7])/i', $evidence)===1;
	}

	/** @return list<string> */
	private static function sqlLiteralFragments(string $sql): array {
		$fragments=[];
		$lineComment=false;
		$blockDepth=0;
		$doubleQuoted=false;
		$length=strlen($sql);
		for($index=0; $index<$length; $index++){
			$character=$sql[$index];
			if($lineComment){
				if($character==="\n" || $character==="\r"){
					$lineComment=false;
				}
				continue;
			}
			if($blockDepth>0){
				if($character==='/' && ($sql[$index+1] ?? null)==='*'){
					$blockDepth++;
					$index++;
				}elseif($character==='*' && ($sql[$index+1] ?? null)==='/'){
					$blockDepth--;
					$index++;
				}
				continue;
			}
			if($doubleQuoted){
				if($character==='"'){
					if(($sql[$index+1] ?? null)==='"'){
						$index++;
					}else{
						$doubleQuoted=false;
					}
				}
				continue;
			}
			if($character==='-' && ($sql[$index+1] ?? null)==='-'){
				$lineComment=true;
				$index++;
				continue;
			}
			if($character==='/' && ($sql[$index+1] ?? null)==='*'){
				$blockDepth=1;
				$index++;
				continue;
			}
			if($character==='"'){
				$doubleQuoted=true;
				continue;
			}
			if($character==="'"){
				$escape=$index>0
					&& in_array($sql[$index-1], ['e', 'E'], true)
					&& ($index<2 || preg_match('/[A-Za-z0-9_$]/', $sql[$index-2])!==1);
				$context=[
					'type'=>'single',
					'start'=>$index,
					'content_start'=>$index+1,
					'parent_content_start'=>0,
					'delimiter'=>"'",
					'escape'=>$escape,
				];
				$end=self::sqlLiteralEnd($sql, $context);
				$fragments[]=str_replace("''", "'", substr($sql, $index+1, $end-$index-1));
				$index=$end;
				continue;
			}
			$delimiter=self::dollarQuoteDelimiterAt($sql, $index);
			if($delimiter!==null){
				$end=strpos($sql, $delimiter, $index+strlen($delimiter));
				if($end===false){
					throw new RuntimeException('Unclosed dollar-quoted SQL literal in applied migration definition.');
				}
				$fragments[]=substr(
					$sql,
					$index+strlen($delimiter),
					$end-$index-strlen($delimiter)
				);
				$index=$end+strlen($delimiter)-1;
			}
		}
		return $fragments;
	}

	private static function dollarQuoteDelimiterAt(string $sql, int $offset): ?string {
		if(($sql[$offset] ?? null)!=='$'){
			return null;
		}
		return preg_match('/\\A\\$(?:[A-Za-z_][A-Za-z0-9_]*)?\\$/', substr($sql, $offset), $match)===1
			? $match[0]
			: null;
	}

	/** @param array{type:string,start:int,content_start:int,parent_content_start:int,delimiter:string,escape:bool} $context */
	private static function sqlLiteralEnd(string $sql, array $context): int {
		$length=strlen($sql);
		if($context['type']==='dollar'){
			$end=strpos($sql, $context['delimiter'], $context['content_start']);
			if($end===false){
				throw new RuntimeException('Unclosed dollar-quoted SQL literal in applied migration definition.');
			}
			return $end;
		}
		for($index=$context['content_start']; $index<$length; $index++){
			if($context['escape'] && $sql[$index]==='\\'){
				$index++;
				continue;
			}
			if($sql[$index]!=="'"){
				continue;
			}
			if(($sql[$index+1] ?? null)==="'"){
				$index++;
				continue;
			}
			return $index;
		}
		throw new RuntimeException('Unclosed fixed SQL literal in applied migration definition.');
	}

	private static function stripSqlComments(string $sql): string {
		$result='';
		$lineComment=false;
		$blockDepth=0;
		$quote=null;
		$dollar=null;
		$escape=false;
		$length=strlen($sql);
		for($index=0; $index<$length; $index++){
			$character=$sql[$index];
			if($lineComment){
				if($character==="\n" || $character==="\r"){
					$lineComment=false;
					$result.=$character;
				}else{
					$result.=' ';
				}
				continue;
			}
			if($blockDepth>0){
				if($character==='/' && ($sql[$index+1] ?? null)==='*'){
					$blockDepth++;
					$result.='  ';
					$index++;
				}elseif($character==='*' && ($sql[$index+1] ?? null)==='/'){
					$blockDepth--;
					$result.='  ';
					$index++;
				}else{
					$result.=' ';
				}
				continue;
			}
			if($quote!==null){
				$result.=$character;
				if($escape && $character==='\\'){
					if($index+1<$length){
						$result.=$sql[++$index];
					}
					continue;
				}
				if($character===$quote){
					if(($sql[$index+1] ?? null)===$quote){
						$result.=$sql[++$index];
					}else{
						$quote=null;
						$escape=false;
					}
				}
				continue;
			}
			if($dollar!==null){
				if(substr($sql, $index, strlen($dollar))===$dollar){
					$result.=$dollar;
					$index+=strlen($dollar)-1;
					$dollar=null;
				}else{
					$result.=$character;
				}
				continue;
			}
			if($character==='-' && ($sql[$index+1] ?? null)==='-'){
				$lineComment=true;
				$result.='  ';
				$index++;
				continue;
			}
			if($character==='/' && ($sql[$index+1] ?? null)==='*'){
				$blockDepth=1;
				$result.='  ';
				$index++;
				continue;
			}
			if($character==="'" || $character==='"'){
				$quote=$character;
				$escape=$character==="'"
					&& $index>0
					&& in_array($sql[$index-1], ['e', 'E'], true)
					&& ($index<2 || preg_match('/[A-Za-z0-9_$]/', $sql[$index-2])!==1);
				$result.=$character;
				continue;
			}
			$delimiter=self::dollarQuoteDelimiterAt($sql, $index);
			if($delimiter!==null){
				$dollar=$delimiter;
				$result.=$delimiter;
				$index+=strlen($delimiter)-1;
				continue;
			}
			$result.=$character;
		}
		return $result;
	}

	private static function matchingParenthesis(string $sql, int $opening): int {
		if(($sql[$opening] ?? null)!=='('){
			throw new RuntimeException('Cannot parse migration parenthesis boundary.');
		}
		$depth=0;
		$lineComment=false;
		$blockDepth=0;
		$quote=null;
		$dollar=null;
		$escape=false;
		$length=strlen($sql);
		for($index=$opening; $index<$length; $index++){
			$character=$sql[$index];
			if($lineComment){
				if($character==="\n" || $character==="\r"){
					$lineComment=false;
				}
				continue;
			}
			if($blockDepth>0){
				if($character==='/' && ($sql[$index+1] ?? null)==='*'){
					$blockDepth++;
					$index++;
				}elseif($character==='*' && ($sql[$index+1] ?? null)==='/'){
					$blockDepth--;
					$index++;
				}
				continue;
			}
			if($quote!==null){
				if($escape && $character==='\\'){
					$index++;
					continue;
				}
				if($character===$quote){
					if($index+1<$length && $sql[$index+1]===$quote){
						$index++;
						continue;
					}
					$quote=null;
					$escape=false;
				}
				continue;
			}
			if($dollar!==null){
				if(substr($sql, $index, strlen($dollar))===$dollar){
					$index+=strlen($dollar)-1;
					$dollar=null;
				}
				continue;
			}
			if($character==='-' && ($sql[$index+1] ?? null)==='-'){
				$lineComment=true;
				$index++;
				continue;
			}
			if($character==='/' && ($sql[$index+1] ?? null)==='*'){
				$blockDepth=1;
				$index++;
				continue;
			}
			if($character==="'" || $character==='"'){
				$quote=$character;
				$escape=$character==="'"
					&& $index>0
					&& in_array($sql[$index-1], ['e', 'E'], true)
					&& ($index<2 || preg_match('/[A-Za-z0-9_$]/', $sql[$index-2])!==1);
			}elseif(($delimiter=self::dollarQuoteDelimiterAt($sql, $index))!==null){
				$dollar=$delimiter;
				$index+=strlen($delimiter)-1;
			}elseif($character==='('){
				$depth++;
			}elseif($character===')' && --$depth===0){
				return $index;
			}
		}
		throw new RuntimeException('Unclosed parenthesis in applied migration definition.');
	}

	private static function matchingSquareBracket(string $sql, int $opening): int {
		if(($sql[$opening] ?? null)!=='['){
			throw new RuntimeException('Cannot parse migration array boundary.');
		}
		$depth=0;
		$quote=null;
		$length=strlen($sql);
		for($index=$opening; $index<$length; $index++){
			$character=$sql[$index];
			if($quote!==null){
				if($character===$quote){
					if(($sql[$index+1] ?? null)===$quote){
						$index++;
					}else{
						$quote=null;
					}
				}
				continue;
			}
			if($character==="'" || $character==='"'){
				$quote=$character;
			}elseif($character==='['){
				$depth++;
			}elseif($character===']' && --$depth===0){
				return $index;
			}
		}
		throw new RuntimeException('Unclosed array in applied migration definition.');
	}

	private static function statementEnd(string $sql, int $start): int {
		$quote=null;
		$dollar=null;
		$escape=false;
		$lineComment=false;
		$blockDepth=0;
		$depth=0;
		$length=strlen($sql);
		for($index=$start; $index<$length; $index++){
			$character=$sql[$index];
			if($lineComment){
				if($character==="\n" || $character==="\r"){
					$lineComment=false;
				}
				continue;
			}
			if($blockDepth>0){
				if($character==='/' && ($sql[$index+1] ?? null)==='*'){
					$blockDepth++;
					$index++;
				}elseif($character==='*' && ($sql[$index+1] ?? null)==='/'){
					$blockDepth--;
					$index++;
				}
				continue;
			}
			if($quote!==null){
				if($escape && $character==='\\'){
					$index++;
					continue;
				}
				if($character===$quote){
					if($index+1<$length && $sql[$index+1]===$quote){
						$index++;
						continue;
					}
					$quote=null;
					$escape=false;
				}
				continue;
			}
			if($dollar!==null){
				if(substr($sql, $index, strlen($dollar))===$dollar){
					$index+=strlen($dollar)-1;
					$dollar=null;
				}
				continue;
			}
			if($character==='-' && ($sql[$index+1] ?? null)==='-'){
				$lineComment=true;
				$index++;
				continue;
			}
			if($character==='/' && ($sql[$index+1] ?? null)==='*'){
				$blockDepth=1;
				$index++;
				continue;
			}
			if($character==="'" || $character==='"'){
				$quote=$character;
				$escape=$character==="'"
					&& $index>0
					&& in_array($sql[$index-1], ['e', 'E'], true)
					&& ($index<2 || preg_match('/[A-Za-z0-9_$]/', $sql[$index-2])!==1);
			}elseif(($delimiter=self::dollarQuoteDelimiterAt($sql, $index))!==null){
				$dollar=$delimiter;
				$index+=strlen($delimiter)-1;
			}elseif($character==='('){
				$depth++;
			}elseif($character===')'){
				$depth=max(0, $depth-1);
			}elseif($character===';' && $depth===0){
				return $index;
			}
		}
		return $length;
	}

	private static function topLevelKeywordTail(string $sql, string $keyword): ?string {
		$quote=null;
		$depth=0;
		$length=strlen($sql);
		$keyword=strtolower($keyword);
		for($index=0; $index<$length; $index++){
			$character=$sql[$index];
			if($quote!==null){
				if($character===$quote){
					if($index+1<$length && $sql[$index+1]===$quote){
						$index++;
						continue;
					}
					$quote=null;
				}
				continue;
			}
			if($character==="'" || $character==='"'){
				$quote=$character;
				continue;
			}
			if($character==='('){
				$depth++;
				continue;
			}
			if($character===')'){
				$depth=max(0, $depth-1);
				continue;
			}
			if($depth!==0 || !ctype_alpha($character)){
				continue;
			}
			$end=$index+1;
			while($end<$length && (ctype_alnum($sql[$end]) || $sql[$end]==='_')){
				$end++;
			}
			if(strtolower(substr($sql, $index, $end-$index))===$keyword){
				return trim(substr($sql, $end));
			}
			$index=$end-1;
		}
		return null;
	}

	private static function stripOuterParentheses(string $expression): string {
		while(strlen($expression)>=2 && $expression[0]==='('){
			try{
				$closing=self::matchingParenthesis($expression, 0);
			}catch(RuntimeException){
				break;
			}
			if($closing!==strlen($expression)-1){
				break;
			}
			$expression=trim(substr($expression, 1, -1));
		}
		return $expression;
	}

	/** @return list<string> */
	private static function splitDefinitions(string $body): array {
		$definitions=[];
		$start=0;
		$depth=0;
		$length=strlen($body);
		$quote=null;
		for($index=0; $index<$length; $index++){
			$character=$body[$index];
			if($quote!==null){
				if($character===$quote){
					if($index+1<$length && $body[$index+1]===$quote){
						$index++;
						continue;
					}
					$quote=null;
				}
				continue;
			}
			if($character==="'" || $character==='"'){
				$quote=$character;
			}elseif($character==='('){
				$depth++;
			}elseif($character===')'){
				$depth=max(0, $depth-1);
			}elseif($character===',' && $depth===0){
				$definition=trim(substr($body, $start, $index-$start));
				if($definition!==''){
					$definitions[]=$definition;
				}
				$start=$index+1;
			}
		}
		$definition=trim(substr($body, $start));
		if($definition!==''){
			$definitions[]=$definition;
		}
		return $definitions;
	}

	private static function columnType(string $definition): string {
		preg_match(
			'/^(.*?)(?=\\s+(?:COLLATE|CONSTRAINT|DEFAULT|GENERATED|NOT\\s+NULL|NULL|'.
				'PRIMARY\\s+KEY|REFERENCES|UNIQUE|CHECK|USING)\\b|$)/is',
			trim($definition),
			$match
		);
		$type=(string)($match[1] ?? '');
		return self::serialColumnBaseType($type) ?? self::normalizeType($type);
	}

	/**
	 * SERIAL names are migration grammar shorthands, not catalog types. Keep the
	 * expansion on the expected-schema side: mapping them in normalizeType()
	 * could falsely equate a real user-defined catalog domain named "serial".
	 */
	private static function serialColumnBaseType(string $definition): ?string {
		if(preg_match(
			'/^(smallserial|serial2|serial4|serial|bigserial|serial8)(?=\\s|$)/i',
			trim($definition),
			$match
		)!==1){
			return null;
		}
		return match(strtolower($match[1])){
			'smallserial', 'serial2'=>'smallint',
			'serial', 'serial4'=>'integer',
			'bigserial', 'serial8'=>'bigint',
		};
	}

	private static function identifier(string $identifier): string {
		$identifier=trim($identifier);
		if(
			strlen($identifier)>=2
			&& $identifier[0]==='"'
			&& $identifier[strlen($identifier)-1]==='"'
		){
			return str_replace('""', '"', substr($identifier, 1, -1));
		}
		return strtolower(substr($identifier, 0, self::POSTGRESQL_IDENTIFIER_MAX_BYTES));
	}

	private static function defaultPrimaryKeyName(string $qualifiedTable): string {
		$separator=strrpos($qualifiedTable, '.');
		$table=$separator===false
			? $qualifiedTable
			: substr($qualifiedTable, $separator+1);
		return substr($table.'_pkey', 0, self::POSTGRESQL_IDENTIFIER_MAX_BYTES);
	}

	private static function qualifiedName(string $schema, string $object): string {
		return self::identifier($schema).'.'.self::identifier($object);
	}

	/** @return list<string> */
	private static function columnList(string $columns): array {
		return array_values(array_map(
			static fn(string $column): string=>self::identifier($column),
			array_filter(
				array_map('trim', explode(',', $columns)),
				static fn(string $column): bool=>$column!==''
			)
		));
	}

	private static function postgresqlBoolean(mixed $value): bool {
		return $value===true || $value===1 || $value==='1' || $value==='t' || $value==='true';
	}
}
