<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;

return static function(string $table): TableDefinition {
	return TableDefinition::for($table)
		->string('rqid', 64)->notNull()->primary()
		->longText('log')->notNull()
		->string('server', 64)
		->string('app', 64)
		->datetime('date')->notNull()->defaultCurrent()
		->index('date', 'idx_tracelog_date');
};
