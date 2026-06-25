<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;

return [
	'sessions'=>static function(string $table): TableDefinition {
		return TableDefinition::for($table)
			->string('id', 64)->notNull()->primary()
			->unsignedBigInt('userid')->notNull()
			->text('useragent')->notNull()
			->text('ipaddress')->notNull()
			->boolean('keepalive')->notNull()->default(false)
			->boolean('active')->notNull()->default(true)
			->timestamp('date')->notNull()->defaultCurrent()
			->index(['userid', 'active'], 'idx_access_sessions_userid_active')
			->index(['id', 'userid', 'useragent(255)', 'ipaddress(255)', 'active'], 'idx_access_sessions_full_lookup')
			->index('date', 'idx_access_sessions_date');
	},
	'tokens'=>static function(string $table): TableDefinition {
		return TableDefinition::for($table)
			->string('id', 64)->notNull()->primary()
			->string('type', 64)->notNull()
			->string('token_hash', 128)->notNull()
			->unsignedBigInt('user_id')->nullable()
			->string('email', 255)->nullable()
			->longText('metadata_json')->nullable()
			->timestamp('expires_at')->notNull()
			->timestamp('used_at')->nullable()
			->timestamp('created_at')->notNull()->defaultCurrent()
			->unique('token_hash', 'uniq_access_tokens_token_hash')
			->index(['type', 'email'], 'idx_access_tokens_type_email')
			->index(['type', 'user_id'], 'idx_access_tokens_type_user')
			->index(['expires_at', 'used_at'], 'idx_access_tokens_expiry');
	},
];
