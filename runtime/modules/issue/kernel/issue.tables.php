<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;

return [
	'issues'=>static fn(string $table): TableDefinition => TableDefinition::for($table)
		->autoIncrement('issueid')
		->string('md5', 32)->notNull()
		->string('type', 255)->notNull()
		->text('description')
		->longText('context')->notNull()
		->string('server_ip', 64)->notNull()
		->string('status', 32)->notNull()->default('pending')
		->datetime('date')->notNull()->defaultCurrent()
		->bigInt('execution_userid')
		->string('rqid', 64)
		->datetime('time')
		->string('event', 255)
		->index(['md5', 'status'], 'idx_issues_md5_status')
		->index('date', 'idx_issues_date'),
];
