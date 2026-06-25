<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;

return [
	'user_changes'=>static fn(string $table): TableDefinition => TableDefinition::for($table)
		->uuid('changeid')->notNull()->primary()
		->string('type', 64)->notNull()
		->string('rollback_type', 64)->notNull()
		->boolean('can_rollback')->notNull()->default(false)
		->bigInt('userid')->notNull()
		->longText('data')->notNull()
		->longText('executor')->notNull()
		->datetime('time')->notNull()->defaultCurrent()
		->boolean('rollback')->notNull()->default(false)
		->bigInt('rollback_by')
		->datetime('rollback_time')
		->index('userid', 'idx_user_changes_userid')
		->index('time', 'idx_user_changes_time')
		->index('rollback', 'idx_user_changes_rollback'),
];
