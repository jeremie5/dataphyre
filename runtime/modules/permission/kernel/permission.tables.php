<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;

return [
	'assignments'=>static function(string $table): TableDefinition {
		return TableDefinition::for($table)
			->string('id', 64)->notNull()->primary()
			->string('subject_type', 64)->notNull()->default('user')
			->string('subject_id', 128)->notNull()
			->string('scope', 128)->notNull()->default('global')
			->string('kind', 16)->notNull()->default('permission')
			->string('value', 255)->notNull()
			->boolean('negative')->notNull()->default(false)
			->string('created_by', 128)->nullable()
			->timestamp('created_at')->notNull()->defaultCurrent()
			->unique(['subject_type', 'subject_id', 'scope', 'kind', 'value', 'negative'], 'uniq_permission_assignments_subject_value')
			->index(['subject_type', 'subject_id', 'scope'], 'idx_permission_assignments_subject')
			->index(['kind', 'value'], 'idx_permission_assignments_kind_value');
	},
	'roles'=>static function(string $table): TableDefinition {
		return TableDefinition::for($table)
			->string('name', 128)->notNull()->primary()
			->string('label', 255)->nullable()
			->text('description')->nullable()
			->longText('metadata_json')->nullable()
			->boolean('system')->notNull()->default(false)
			->timestamp('created_at')->notNull()->defaultCurrent()
			->timestamp('updated_at')->notNull()->defaultCurrent()->onUpdateCurrent()
			->index(['system', 'name'], 'idx_permission_roles_system_name');
	},
	'role_permissions'=>static function(string $table): TableDefinition {
		return TableDefinition::for($table)
			->string('id', 64)->notNull()->primary()
			->string('role', 128)->notNull()
			->string('permission', 255)->notNull()
			->boolean('negative')->notNull()->default(false)
			->timestamp('created_at')->notNull()->defaultCurrent()
			->unique(['role', 'permission', 'negative'], 'uniq_permission_role_permissions')
			->index('role', 'idx_permission_role_permissions_role')
			->index('permission', 'idx_permission_role_permissions_permission');
	},
];
