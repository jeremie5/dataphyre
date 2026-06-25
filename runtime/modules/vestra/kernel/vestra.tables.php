<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Dataphyre
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;

return [
	'objects'=>static fn(string $table): TableDefinition => TableDefinition::for($table)
		->bigInt('object_id')->notNull()->primary()
		->string('tenant', 128)
		->string('hash', 128)
		->string('mime_type', 128)
		->bigInt('filesize')
		->json('reference')
		->integer('use_count')->notNull()->default(0)
		->timestamp('created_at')
		->timestamp('updated_at')
		->index('tenant', 'idx_vestra_objects_tenant')
		->index('hash', 'idx_vestra_objects_hash'),
];
