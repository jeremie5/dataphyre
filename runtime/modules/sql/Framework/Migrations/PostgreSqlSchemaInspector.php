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
	public function __construct(private PostgreSqlMigrationProfile $profile) {}

	/**
	 * @param list<array{name:string,sql:string}> $entries
	 * @return array<string,array<string,mixed>>
	 */
	public function expectedSchema(array $entries): array {
		$expected=['tables'=>[], 'indexes'=>[], 'foreign_keys'=>[], 'checks'=>[]];
		$identifier='(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*)';
		$qualified='('.$identifier.')\\s*\\.\\s*('.$identifier.')';
		$scope=$this->profile->schema().'.';
		foreach($entries as $entry){
			$sql=(string)($entry['sql'] ?? '');
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
					$expected['tables'][$table] ??=['columns'=>[], 'primary_key'=>null];
					foreach(self::splitDefinitions($match[3]) as $definition){
						if(preg_match(
							'/^(?:CONSTRAINT\\s+'.$identifier.'\\s+)?PRIMARY\\s+KEY\\s*\\(([^)]*)\\)/i',
							$definition,
							$primaryMatch
						)===1){
							$expected['tables'][$table]['primary_key']=self::columnList($primaryMatch[1]);
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
						$inlinePrimary=preg_match('/\\bPRIMARY\\s+KEY\\b/i', $columnMatch[2])===1;
						$expected['tables'][$table]['columns'][$column]=[
							'type'=>$type,
							'nullable'=>!$inlinePrimary
								&& preg_match('/\\bNOT\\s+NULL\\b/i', $columnMatch[2])!==1,
						];
						if($inlinePrimary){
							$expected['tables'][$table]['primary_key']=[$column];
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
			if(preg_match_all(
				'/\\bALTER\\s+TABLE\\s+(?:ONLY\\s+)?'.$qualified.
				'\\s+ADD\\s+COLUMN\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?('.$identifier.
				')\\s+(.+?)(?:;|\\z)/is',
				$sql,
				$columnMatches,
				PREG_SET_ORDER
			)!==false){
				foreach($columnMatches as $match){
					$table=self::qualifiedName($match[1], $match[2]);
					if(!str_starts_with($table, $scope)){
						continue;
					}
					$column=self::identifier($match[3]);
					$type=self::columnType($match[4]);
					$expected['tables'][$table] ??=['columns'=>[], 'primary_key'=>null];
					$expected['tables'][$table]['columns'][$column]=[
						'type'=>$type,
						'nullable'=>preg_match('/\\bNOT\\s+NULL\\b/i', $match[4])!==1
							&& preg_match('/\\bPRIMARY\\s+KEY\\b/i', $match[4])!==1,
					];
					self::registerExpectedCheck($expected, $table, $match[4], $identifier);
				}
			}
			if(preg_match_all(
				'/\\bALTER\\s+TABLE\\s+(?:ONLY\\s+)?'.$qualified.
				'\\s+ADD\\s+(?:CONSTRAINT\\s+'.$identifier.
				'\\s+)?PRIMARY\\s+KEY\\s*\\(([^)]*)\\)\\s*(?:;|\\z)/is',
				$sql,
				$primaryMatches,
				PREG_SET_ORDER
			)!==false){
				foreach($primaryMatches as $match){
					$table=self::qualifiedName($match[1], $match[2]);
					if(!str_starts_with($table, $scope)){
						continue;
					}
					$expected['tables'][$table] ??=['columns'=>[], 'primary_key'=>null];
					$expected['tables'][$table]['primary_key']=self::columnList($match[3]);
					foreach($expected['tables'][$table]['primary_key'] as $column){
						if(isset($expected['tables'][$table]['columns'][$column])){
							$expected['tables'][$table]['columns'][$column]['nullable']=false;
						}
					}
				}
			}
			if(preg_match_all(
				'/\\bALTER\\s+TABLE\\s+(?:ONLY\\s+)?'.$qualified.
				'\\s+ALTER\\s+COLUMN\\s+('.$identifier.
				')\\s+(SET|DROP)\\s+NOT\\s+NULL\\s*(?:;|\\z)/is',
				$sql,
				$nullabilityMatches,
				PREG_SET_ORDER
			)!==false){
				foreach($nullabilityMatches as $match){
					$table=self::qualifiedName($match[1], $match[2]);
					$column=self::identifier($match[3]);
					if(isset($expected['tables'][$table]['columns'][$column])){
						$expected['tables'][$table]['columns'][$column]['nullable']=
							strcasecmp($match[4], 'DROP')===0;
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
					? self::normalizeSqlExpression((string)$row['predicate'])
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
			if($actual['keys']!==$definition['keys']){
				$issues[]=[
					'kind'=>'index_keys_mismatch',
					'object'=>$index,
					'expected'=>$definition['keys'],
					'actual'=>$actual['keys'],
				];
			}
			if($actual['predicate']!==$definition['predicate']){
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
						&& $candidate['expression']===$definition['expression']
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
			if($actual['expression']!==$definition['expression']){
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
	 * Proves a down migration changes structure, preserves rows when labelled
	 * lossless, and is exactly reconstructed by its paired up migration.
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
		$before=$this->structuralFingerprint($pdo);
		$beforeData=$entry['down']['safety']==='lossless'
			? $this->dataFingerprint($pdo)
			: null;
		self::executeSql(
			$pdo,
			$entry['down']['sql'],
			'Migration down SQL execution failed: '.$entry['id'].'.'
		);
		$down=$this->structuralFingerprint($pdo);
		if(hash_equals($before, $down)){
			throw new RuntimeException(
				'Migration down direction made no structural change: '.$entry['id'].'.'
			);
		}
		if(is_array($beforeData)){
			self::assertLosslessDownRows(
				$beforeData,
				$this->dataFingerprint($pdo),
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
		if(is_array($beforeData) && $beforeData!==$this->dataFingerprint($pdo)){
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
		if(!hash_equals($down, $final)){
			throw new RuntimeException(
				'Migration down direction is not repeatably paired with its up migration: '.
				$entry['id'].'.'
			);
		}
		if(is_array($beforeData)){
			self::assertLosslessDownRows(
				$beforeData,
				$this->dataFingerprint($pdo),
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
		if(preg_match_all(
			'/\\bALTER\\s+TABLE\\s+(?:ONLY\\s+)?'.$qualified.
			'\\s+DROP\\s+CONSTRAINT\\s+(?:IF\\s+EXISTS\\s+)?('.$identifier.')'.
			'(?:\\s+(?:CASCADE|RESTRICT))?\\s*(?:;|\\z)/is',
			$sql,
			$drops,
			PREG_SET_ORDER
		)!==false){
			foreach($drops as $match){
				$table=self::qualifiedName($match[1], $match[2]);
				unset($expected['checks'][$table.'.'.self::identifier($match[3])]);
			}
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

	/**
	 * @return array<string,array{table:string,unique:bool,keys:list<string>,predicate:?string}>
	 */
	private static function indexDefinitions(string $sql, string $identifier): array {
		$pattern='/\\bCREATE\\s+(?<unique>UNIQUE\\s+)?INDEX\\s+(?:CONCURRENTLY\\s+)?'.
			'(?:IF\\s+NOT\\s+EXISTS\\s+)?'.
			'(?:(?<index_schema>'.$identifier.')\\s*\\.\\s*)?(?<index_name>'.$identifier.')\\s+'.
			'ON\\s+(?:ONLY\\s+)?(?<table_schema>'.$identifier.')\\s*\\.\\s*'.
			'(?<table_name>'.$identifier.')(?=\\s|\\()/is';
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
			$predicate=$predicateSql===null ? null : self::normalizeSqlExpression($predicateSql);
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
		$normalized=self::normalizeSqlExpression($expression);
		$normalized=(string)preg_replace('/::(?:text|character varying)\\b/i', '', $normalized);
		$normalized=self::stripOuterParentheses(trim($normalized));
		if(preg_match('/^(.+)=any\\(array\\s*\\[(.*)\\]\\)$/is', $normalized, $match)===1){
			$normalized=trim($match[1]).' in('.trim($match[2]).')';
		}
		return self::stripOuterParentheses($normalized);
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

	private static function normalizeIndexKey(string $key): string {
		$key=self::normalizeSqlExpression($key);
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

	private static function matchingParenthesis(string $sql, int $opening): int {
		if(($sql[$opening] ?? null)!=='('){
			throw new RuntimeException('Cannot parse migration parenthesis boundary.');
		}
		$depth=0;
		$quote=null;
		$length=strlen($sql);
		for($index=$opening; $index<$length; $index++){
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
			}elseif($character==='('){
				$depth++;
			}elseif($character===')' && --$depth===0){
				return $index;
			}
		}
		throw new RuntimeException('Unclosed parenthesis in applied migration definition.');
	}

	private static function statementEnd(string $sql, int $start): int {
		$quote=null;
		$depth=0;
		$length=strlen($sql);
		for($index=$start; $index<$length; $index++){
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
			'PRIMARY\\s+KEY|REFERENCES|UNIQUE|CHECK)\\b|$)/is',
			trim($definition),
			$match
		);
		return self::normalizeType((string)($match[1] ?? ''));
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
		return strtolower($identifier);
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
