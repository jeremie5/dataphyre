<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\Migrations\PostgreSqlMigrationProfile;
use Dataphyre\Database\Migrations\PostgreSqlSchemaInspector;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

$dpPostgreSqlInspectorPg17Root=dirname(__DIR__);
require_once $dpPostgreSqlInspectorPg17Root.'/Framework/Migrations/PostgreSqlMigrationProfile.php';
require_once $dpPostgreSqlInspectorPg17Root.'/Framework/Migrations/PostgreSqlSchemaInspector.php';

function dp_postgresql_inspector_pg17_profile(): PostgreSqlMigrationProfile {
	return PostgreSqlMigrationProfile::fromArray([
		'application_id'=>'fixture',
		'schema'=>'fixture',
		'journal_table'=>'schema_migrations',
		'event_table'=>'schema_migration_events',
		'advisory_lock'=>'fixture.postgresql_migrations',
		'bootstrap_ids'=>['001_base'],
		'bootstrap_cutoff'=>'001_base',
		'manifest_public_path'=>'migrations/postgresql/manifest.json',
		'lock_timeout'=>'3s',
		'statement_timeout'=>'2min',
	]);
}

test('PostgreSQL 17 varchar CHECK rendering is catalog-equivalent without erasing source casts', static function(Context $t): void {
	$inspector=new PostgreSqlSchemaInspector(dp_postgresql_inspector_pg17_profile());
	$expected=$inspector->expectedSchema([[
		'name'=>'001_base',
		'sql'=><<<'SQL'
CREATE TABLE fixture.pg17_checks (
	id BIGINT PRIMARY KEY,
	applicability_status VARCHAR(24) NOT NULL,
	source_id VARCHAR(500) NOT NULL,
	chain_key VARCHAR(190) NOT NULL,
	CONSTRAINT pg17_status_check CHECK (
		applicability_status IN ('mandatory','conditional','future_effective')
	),
	CONSTRAINT pg17_status_exclusion_check CHECK (applicability_status <> 'retired'),
	CONSTRAINT pg17_source_check CHECK (length(btrim(source_id)) BETWEEN 1 AND 500),
	CONSTRAINT pg17_chain_check CHECK (chain_key ~ '^[a-z][a-z0-9._:-]{0,189}$')
);
SQL
	]]);
	$pdo=$t->scriptedPdo('pgsql')
		->queueRows([
			[
				'schema_name'=>'fixture', 'table_name'=>'pg17_checks',
				'column_name'=>'id', 'column_type'=>'bigint', 'is_not_null'=>'t',
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'pg17_checks',
				'column_name'=>'applicability_status',
				'column_type'=>'character varying(24)', 'is_not_null'=>'t',
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'pg17_checks',
				'column_name'=>'source_id',
				'column_type'=>'character varying(500)', 'is_not_null'=>'t',
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'pg17_checks',
				'column_name'=>'chain_key',
				'column_type'=>'character varying(190)', 'is_not_null'=>'t',
			],
		])
		->queueRows([[
			'schema_name'=>'fixture', 'table_name'=>'pg17_checks', 'column_name'=>'id',
		]])
		->queueRows([])
		->queueRows([
			[
				'schema_name'=>'fixture', 'table_name'=>'pg17_checks',
				'constraint_name'=>'pg17_status_check',
				'expression'=>"applicability_status::text = ANY (".
					"ARRAY['mandatory'::character varying, 'conditional'::character varying, ".
					"'future_effective'::character varying]::text[])",
				'is_valid'=>'t',
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'pg17_checks',
				'constraint_name'=>'pg17_status_exclusion_check',
				'expression'=>"applicability_status::text <> 'retired'::text",
				'is_valid'=>'t',
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'pg17_checks',
				'constraint_name'=>'pg17_source_check',
				'expression'=>'length(btrim(source_id::text)) >= 1 '.
					'AND length(btrim(source_id::text)) <= 500',
				'is_valid'=>'t',
			],
			[
				'schema_name'=>'fixture', 'table_name'=>'pg17_checks',
				'constraint_name'=>'pg17_chain_check',
				'expression'=>"chain_key::text ~ '^[a-z][a-z0-9._:-]{0,189}$'::text",
				'is_valid'=>'t',
			],
		])
		->queueRows([]);

	$t->same([], $inspector->schemaIssues($pdo, $expected));

	$access=$t->nonPublic($inspector);
	$t->isFalse($access->invoke(
		'expressionsEquivalent',
		PostgreSqlSchemaInspector::normalizeCheckExpression('btrim(source_id::text) <> \'\''),
		PostgreSqlSchemaInspector::normalizeCheckExpression('btrim(source_id) <> \'\'')
	));
	$t->isFalse($access->invoke(
		'expressionsEquivalent',
		PostgreSqlSchemaInspector::normalizeCheckExpression("status IN ('ready')"),
		PostgreSqlSchemaInspector::normalizeCheckExpression(
			"status = ANY (ARRAY['ready'::fixture.status_code]::fixture.status_code[])"
		)
	));
})->tag('sql', 'migration', 'postgresql', 'schema', 'postgresql-17', 'varchar')->group('framework-coverage');

test('PostgreSQL 17 preserves explicit text operands while removing catalog-only literal casts', static function(Context $t): void {
	$inspector=new PostgreSqlSchemaInspector(dp_postgresql_inspector_pg17_profile());
	$access=$t->nonPublic($inspector);
	foreach([
		[
			"content_hash::text~'^[a-f0-9]{64}$'",
			"content_hash::text ~ '^[a-f0-9]{64}$'::text",
		],
		[
			"(method='typed_signature' AND signer_name IS NOT NULL ".
				"AND char_length(btrim(signer_name::text)) BETWEEN 1 AND 200) ".
				"OR (method='acknowledgement' AND signer_name IS NULL)",
			"(method = 'typed_signature'::text AND signer_name IS NOT NULL ".
				"AND char_length(btrim(signer_name::text)) >= 1 ".
				"AND char_length(btrim(signer_name::text)) <= 200) ".
				"OR (method = 'acknowledgement'::text AND signer_name IS NULL)",
		],
	] as [$migration, $catalog]){
		$t->isTrue($access->invoke(
			'expressionsEquivalent',
			PostgreSqlSchemaInspector::normalizeCheckExpression($migration),
			PostgreSqlSchemaInspector::normalizeCheckExpression($catalog)
		), $migration);
	}
	$t->isFalse($access->invoke(
		'expressionsEquivalent',
		PostgreSqlSchemaInspector::normalizeCheckExpression(
			"btrim(signer_name::text)='Employee'"
		),
		PostgreSqlSchemaInspector::normalizeCheckExpression(
			"btrim(signer_name)='Employee'::text"
		)
	));
})->tag('sql', 'migration', 'postgresql', 'schema', 'postgresql-17', 'literal-cast')->group('framework-coverage');

test('PostgreSQL 17 index rendering removes only proven catalog artifacts and preserves direction drift', static function(Context $t): void {
	$inspector=new PostgreSqlSchemaInspector(dp_postgresql_inspector_pg17_profile());
	$access=$t->nonPublic($inspector);
	foreach([
		['mod(id, 128)', 'mod(id, (128)::bigint)'],
		[
			"line_evidence->'correction'->>'replaces_sha256'",
			"(line_evidence->'correction'::text)->>'replaces_sha256'::text",
		],
		[
			"evidence #>> '{authority_evidence,commercial_selection,selection_sha256}'",
			"evidence #>> '{authority_evidence,commercial_selection,selection_sha256}'::text[]",
		],
	] as [$migration, $catalog]){
		$t->isTrue($access->invoke(
			'expressionsEquivalent',
			$access->invoke('normalizeIndexExpression', $migration),
			$access->invoke('normalizeIndexExpression', $catalog)
		), $migration);
	}
	$t->isTrue($access->invoke(
		'expressionsEquivalent',
		$access->invoke(
			'normalizeIndexPredicate',
			"deleted_at IS NULL AND status NOT IN ('completed','duplicate')"
		),
		$access->invoke(
			'normalizeIndexPredicate',
			"(deleted_at IS NULL) AND ((status)::text <> ALL ".
				"((ARRAY['completed'::character varying, ".
				"'duplicate'::character varying])::text[]))"
		)
	));

	$identifier='(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*)';
	$descending=$access->invoke(
		'indexDefinitions',
		'CREATE INDEX fixture.customer_history_idx ON fixture.history '.
			'(tenant_id, customer_id, created_at DESC, id DESC);',
		$identifier
	);
	$ascending=$access->invoke(
		'indexDefinitions',
		'CREATE INDEX customer_history_idx ON fixture.history USING btree '.
			'(tenant_id, customer_id, created_at, id);',
		$identifier
	);
	$t->same(
		['tenant_id', 'customer_id', 'created_at desc', 'id desc'],
		$descending['fixture.customer_history_idx']['keys']
	);
	$t->same(
		['tenant_id', 'customer_id', 'created_at', 'id'],
		$ascending['fixture.customer_history_idx']['keys']
	);
	$t->isFalse($access->invoke(
		'indexKeysEquivalent',
		$descending['fixture.customer_history_idx']['keys'],
		$ascending['fixture.customer_history_idx']['keys']
	));
})->tag('sql', 'migration', 'postgresql', 'schema', 'postgresql-17', 'index')->group('framework-coverage');

test('PostgreSQL 17 canonical numeric JSON and nested membership rendering stays schema-equivalent', static function(Context $t): void {
	$inspector=new PostgreSqlSchemaInspector(dp_postgresql_inspector_pg17_profile());
	$access=$t->nonPublic($inspector);
	foreach([
		[
			"(status IN ('applied','voided'))=(applied_at IS NOT NULL)",
			"(status = ANY (ARRAY['applied'::text,'voided'::text]))=".
				"(applied_at IS NOT NULL)",
		],
		[
			'amount_minor>=0 AND amount_minor<=100000000000',
			"amount_minor >= 0 AND amount_minor <= '100000000000'::bigint",
		],
		[
			"status='completed' AND position_seconds>=duration_seconds*0.90",
			"status='completed'::text AND position_seconds >= (duration_seconds * 0.90)",
		],
		[
			"(reminder_policy->>'first_after_minutes')::integer>=5",
			"((reminder_policy->>'first_after_minutes'::text)::integer)>=5",
		],
		[
			"(reminder_policy->>'first_after_minutes')::integer>=5",
			"((reminder_policy->>'first_after_minutes'::text))::integer>=5",
		],
		[
			"jsonb_typeof(reminder_policy->'enabled')='boolean'",
			"jsonb_typeof((reminder_policy->'enabled'::text))='boolean'::text",
		],
		[
			"reminder_count<=(reminder_policy->>'max_reminders')::integer",
			"reminder_count<=((reminder_policy->>'max_reminders'::text)::integer)",
		],
		[
			"email_normalized LIKE 'retired-%@invalid.local'",
			"email_normalized ~~ 'retired-%@invalid.local'::text",
		],
		[
			"reminder_policy->'channels'<@'[\"email\",\"sms\",\"rcs\",\"app\"]'::jsonb",
			"(reminder_policy->'channels'::text)<@".
				"'[\"email\", \"sms\", \"rcs\", \"app\"]'::jsonb",
		],
	] as [$migration, $catalog]){
		$t->isTrue($access->invoke(
			'expressionsEquivalent',
			PostgreSqlSchemaInspector::normalizeCheckExpression($migration),
			PostgreSqlSchemaInspector::normalizeCheckExpression($catalog)
		), $migration);
	}

	$t->isTrue($access->invoke(
		'expressionsEquivalent',
		$access->invoke('normalizeIndexExpression', 'coalesce(brand_id,0)'),
		$access->invoke('normalizeIndexExpression', 'coalesce(brand_id, (0)::bigint)')
	));
	$t->isFalse($access->invoke(
		'expressionsEquivalent',
		PostgreSqlSchemaInspector::normalizeCheckExpression('amount_minor<=100::bigint'),
		PostgreSqlSchemaInspector::normalizeCheckExpression('amount_minor<=100')
	));
	$t->isFalse($access->invoke(
		'expressionsEquivalent',
		$access->invoke('normalizeIndexExpression', 'coalesce(brand_id,0::bigint)'),
		$access->invoke('normalizeIndexExpression', 'coalesce(brand_id,0)')
	));
	$t->isFalse($access->invoke(
		'expressionsEquivalent',
		PostgreSqlSchemaInspector::normalizeCheckExpression("email LIKE 'a%'"),
		PostgreSqlSchemaInspector::normalizeCheckExpression("email ~~* 'a%'::text")
	));
})->tag('sql', 'migration', 'postgresql', 'schema', 'postgresql-17', 'catalog-canonical')->group('framework-coverage');

test('PostgreSQL schema projection keeps adjacent fixed EXECUTE indexes statement-bounded', static function(Context $t): void {
	$inspector=new PostgreSqlSchemaInspector(dp_postgresql_inspector_pg17_profile());
	$expected=$inspector->expectedSchema([[
		'name'=>'001_fixed_indexes',
		'sql'=><<<'SQL'
DO $migration$
BEGIN
	IF position('YugabyteDB' IN version())>0 THEN
		EXECUTE '
			CREATE INDEX IF NOT EXISTS course_access_native
			ON fixture.lines (course_id HASH, line_number ASC, id ASC)
			WHERE course_id IS NOT NULL AND deleted_at IS NULL
		';
		EXECUTE '
			CREATE INDEX IF NOT EXISTS ticket_access_native
			ON fixture.print_jobs (ticket_id HASH, created_at ASC, id ASC)
			WHERE ticket_id IS NOT NULL AND deleted_at IS NULL
		';
	ELSE
		EXECUTE '
			CREATE INDEX IF NOT EXISTS course_access_native
			ON fixture.lines (course_id, line_number ASC, id ASC)
			WHERE course_id IS NOT NULL AND deleted_at IS NULL
		';
		EXECUTE '
			CREATE INDEX IF NOT EXISTS ticket_access_native
			ON fixture.print_jobs (ticket_id, created_at ASC, id ASC)
			WHERE ticket_id IS NOT NULL AND deleted_at IS NULL
		';
	END IF;
END
$migration$;
SQL
	]]);

	$t->same(
		'course_id is not null and deleted_at is null',
		$expected['indexes']['fixture.course_access_native']['predicate']
	);
	$t->same(
		'ticket_id is not null and deleted_at is null',
		$expected['indexes']['fixture.ticket_access_native']['predicate']
	);
	$t->same(['course_id', 'line_number', 'id'], $expected['indexes']['fixture.course_access_native']['keys']);
	$t->same(['ticket_id', 'created_at', 'id'], $expected['indexes']['fixture.ticket_access_native']['keys']);
})->tag('sql', 'migration', 'postgresql', 'schema', 'postgresql-17', 'projection')->group('framework-coverage');
