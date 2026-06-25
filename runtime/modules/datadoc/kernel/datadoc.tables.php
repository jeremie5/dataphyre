<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;

return [
	'projects'=>static fn(string $table): TableDefinition => TableDefinition::for($table)
		->autoIncrement('id')
		->string('name', 255)->unique('name', 'uniq_datadoc_projects_name')
		->string('title', 255)
		->text('path'),
	'data'=>static fn(string $table): TableDefinition => TableDefinition::for($table)
		->autoIncrement('id')
		->bigInt('time')
		->string('checksum', 255)
		->string('type', 255)
		->text('content')
		->text('file')
		->string('project', 255)
		->string('function', 255)
		->string('namespace', 255)
		->string('class', 255)
		->integer('line')
		->text('phpdoc_description')
		->text('phpdoc_tags')
		->unique(['checksum', 'project'], 'uniq_datadoc_checksum_project')
		->index('project', 'idx_datadoc_data_project')
		->index(['class', 'function', 'namespace', 'project'], 'idx_datadoc_data_lookup'),
	'files'=>static fn(string $table): TableDefinition => TableDefinition::for($table)
		->autoIncrement('id')
		->string('filepath', 1024)
		->string('checksum', 255)
		->string('project', 255)
		->datetime('last_synced')
		->boolean('is_stale')->notNull()->default(true)
		->unique(['filepath(255)', 'project'], 'uniq_datadoc_file_project')
		->index('project', 'idx_datadoc_files_project')
		->index('is_stale', 'idx_datadoc_files_stale'),
];
