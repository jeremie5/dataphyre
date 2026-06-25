<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;

return [
	'captcha_blocks'=>static fn(string $table): TableDefinition => TableDefinition::for($table)
		->autoIncrement('id')
		->text('ip_address')->notNull()
		->datetime('expiry')->notNull()
		->text('reason')->notNull()
		->index('ip_address', 'idx_captcha_blocks_ip')
		->index('expiry', 'idx_captcha_blocks_expiry'),
];
