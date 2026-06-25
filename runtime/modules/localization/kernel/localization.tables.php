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
		->autoIncrement('id')
		->string('lang', 10)->notNull()
		->text('name')->notNull()
		->text('string')->notNull()
		->enum('type', ['global', 'theme', 'local'])->notNull()
		->text('theme')
		->text('path')
		->timestamp('edit_time')->notNull()->defaultCurrent()->onUpdateCurrent()
		->index('lang', 'idx_locales_lang')
		->index('type', 'idx_locales_type')
		->index('edit_time', 'idx_locales_edit_time');
};
