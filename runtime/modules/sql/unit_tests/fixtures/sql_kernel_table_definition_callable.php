<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\TableDefinition;

return static fn(string $location,?string $definitionId): TableDefinition=>TableDefinition::for($location)
	->autoIncrement('id')
	->string('value',80)->nullable();
