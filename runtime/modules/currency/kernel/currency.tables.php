<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;

return [
	'exchange_rates'=>static fn(string $table): TableDefinition => TableDefinition::for($table)
		->autoIncrement('id')
		->longText('data')->notNull()
		->datetime('date')->notNull()->defaultCurrent()
		->text('source')->notNull()
		->index('date', 'idx_exchange_rates_date')
		->index('source', 'idx_exchange_rates_source'),
];
