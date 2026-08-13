<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\TableDefinition;

return [
	'users'=>TableDefinition::for('users')
		->autoIncrement('id')
		->string('name',120)->nullable(),
	'callable'=>static fn(string $location,?string $definitionId): TableDefinition=>TableDefinition::for($location)
		->autoIncrement('id')
		->string('label',80)->nullable(),
	'invalid'=>'not-a-definition',
];
