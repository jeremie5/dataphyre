<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;

return [
	'postal_codes_regex'=>static fn(string $table): TableDefinition => TableDefinition::for($table)
		->autoIncrement('id')
		->string('country', 2)->notNull()
		->string('subdivision', 16)->notNull()->default('*')
		->text('validation_regex')
		->text('reformatting_regex')
		->text('reformatting_rules')
		->unique(['country', 'subdivision'], 'uniq_postal_codes_regex_country_subdivision'),
	'postal_codes'=>static fn(string $table): TableDefinition => TableDefinition::for($table)
		->autoIncrement('id')
		->string('country', 2)->notNull()
		->string('postal_code', 32)->notNull()
		->string('subdivision', 16)
		->float('latitude')->notNull()
		->float('longitude')->notNull()
		->index(['country', 'postal_code'], 'idx_postal_codes_country_postal_code'),
];
