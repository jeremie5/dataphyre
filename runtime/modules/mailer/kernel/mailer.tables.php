<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;

/**
 * Defines SQL table shapes used by the mailer outbox and telemetry layer.
 *
 * The returned closures are consumed by sql_define_table() during module load.
 * Outbox rows store normalized message JSON plus the most recent SendResult,
 * event rows store manager telemetry, suppression rows store hashed recipient
 * state, and webhook rows store dedupe hashes for provider delivery events.
 */
return [
	'outbox'=>static function(string $table): TableDefinition {
		return TableDefinition::for($table)
			->string('id', 64)->notNull()->primary()
			->string('provider', 64)->notNull()
			->string('status', 32)->notNull()->default('queued')
			->integer('priority')->notNull()->default(0)
			->integer('attempts')->notNull()->default(0)
			->integer('max_attempts')->notNull()->default(3)
			->timestamp('not_before')->nullable()
			->longText('message_json')->notNull()
			->longText('result_json')->nullable()
			->text('last_error')->nullable()
			->timestamp('created_at')->notNull()->defaultCurrent()
			->timestamp('updated_at')->notNull()->defaultCurrent()->onUpdateCurrent()
			->index(['status', 'not_before', 'priority'], 'idx_mailer_outbox_status_due')
			->index(['provider', 'status'], 'idx_mailer_outbox_provider_status')
			->index('created_at', 'idx_mailer_outbox_created');
	},
	'events'=>static function(string $table): TableDefinition {
		return TableDefinition::for($table)
			->string('id', 64)->notNull()->primary()
			->string('message_id', 64)->nullable()
			->string('provider', 64)->notNull()
			->string('event', 64)->notNull()
			->string('severity', 24)->notNull()->default('info')
			->longText('payload_json')->nullable()
			->timestamp('created_at')->notNull()->defaultCurrent()
			->index(['message_id', 'event'], 'idx_mailer_events_message_event')
			->index(['provider', 'event'], 'idx_mailer_events_provider_event')
			->index('created_at', 'idx_mailer_events_created');
	},
	'suppressions'=>static function(string $table): TableDefinition {
		return TableDefinition::for($table)
			->string('id', 64)->notNull()->primary()
			->string('email_hash', 128)->notNull()
			->string('email', 320)->nullable()
			->string('reason', 64)->notNull()->default('manual')
			->string('source', 64)->notNull()->default('dataphyre')
			->longText('metadata_json')->nullable()
			->timestamp('expires_at')->nullable()
			->timestamp('created_at')->notNull()->defaultCurrent()
			->timestamp('updated_at')->notNull()->defaultCurrent()->onUpdateCurrent()
			->unique('email_hash', 'uniq_mailer_suppressions_email_hash')
			->index(['reason', 'expires_at'], 'idx_mailer_suppressions_reason_expiry')
			->index('created_at', 'idx_mailer_suppressions_created');
	},
	'webhook_events'=>static function(string $table): TableDefinition {
		return TableDefinition::for($table)
			->string('event_hash', 128)->notNull()->primary()
			->string('provider', 64)->notNull()
			->string('event', 64)->notNull()
			->string('message_id', 128)->nullable()
			->longText('payload_json')->nullable()
			->timestamp('created_at')->notNull()->defaultCurrent()
			->index(['provider', 'event'], 'idx_mailer_webhook_events_provider_event')
			->index('created_at', 'idx_mailer_webhook_events_created');
	},
];
