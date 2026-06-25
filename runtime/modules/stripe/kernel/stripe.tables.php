<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;

return [
	'payment_methods'=>static fn(string $table): TableDefinition => TableDefinition::for($table)
		->string('id', 255)->notNull()->primary()
		->string('brand', 64)
		->string('type', 64)->notNull()
		->bigInt('userid')->notNull()
		->boolean('is_attached')->notNull()->default(false)
		->boolean('is_main')->notNull()->default(false)
		->string('country', 8)
		->string('last_four_digits', 8)
		->string('postal_code', 32)
		->integer('expiration_month')
		->integer('expiration_year')
		->string('name_on_card', 255)
		->index('userid', 'idx_stripe_payment_methods_userid')
		->index(['userid', 'is_main'], 'idx_stripe_payment_methods_userid_main'),
];
