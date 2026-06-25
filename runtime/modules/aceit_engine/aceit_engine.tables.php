<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;

return [
	'experiments'=>static fn(string $table): TableDefinition => TableDefinition::for($table)
		->autoIncrement('id')
		->text('env_factor1')
		->text('env_factor2')
		->text('env_factor3')
		->text('env_factor4')
		->text('env_factor5')
		->string('experiment_name', 255)->notNull()
		->string('group', 255)->notNull()
		->string('segment_identifier', 32)
		->longText('events')
		->float('score')->notNull()->default(0)
		->boolean('is_aggregate')->notNull()->default(false)
		->datetime('experiment_date')->notNull()->defaultCurrent()
		->datetime('date_column')->notNull()->defaultCurrent()
		->index(['experiment_name', 'group'], 'idx_aceit_experiment_group')
		->index('segment_identifier', 'idx_aceit_segment_identifier')
		->index('experiment_date', 'idx_aceit_experiment_date'),
];
