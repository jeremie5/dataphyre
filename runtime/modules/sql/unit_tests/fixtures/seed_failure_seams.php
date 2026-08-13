<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database\Seeds;

use Dataphyre\Test\TestState;
use Dataphyre\Test\TempWorkspace;

/** Builds the smallest bootable Dataphyre runtime used by SQL seed CLI tests. */
final class SeedRuntimeFixture {
	public static function withoutSql(TempWorkspace $workspace): string {
		return self::create($workspace, false);
	}

	public static function withSql(TempWorkspace $workspace): string {
		return self::create($workspace, true);
	}

	public static function withSqlAndCache(TempWorkspace $workspace): string {
		$runtime=self::create($workspace, true);
		$workspace->file('modules/cache/kernel/cache.main.php', <<<'PHP'
<?php
namespace dataphyre;
final class cache {}
PHP);
		return $runtime;
	}

	private static function create(TempWorkspace $workspace, bool $withSql): string {
		$workspace->file('bootstrap_config.php', <<<'PHP'
<?php
namespace dataphyre;
final class bootstrap_config {
	public static function resolve(string $runtime): array {
		return [
			'bootstrap'=>['source'=>'unit-test'],
			'modules'=>['enabled'=>['core'=>true], 'disabled'=>[], 'core_implicit'=>true],
		];
	}
}
PHP);
		$workspace->file('modules/core/kernel/bootstrap.php', <<<'PHP'
<?php
namespace dataphyre;
final class autoloader {
	public static function register(string $modules): void {}
}
final class core {
	public static function load_framework_module(string $module): void {}
}
PHP);
		$workspace->file('modules/core/kernel/core_functions.php', <<<'PHP'
<?php
function dp_define_core_config(): void {
	if(!defined('DP_CORE_CFG')) define('DP_CORE_CFG', ['datacenter'=>'coverage']);
}
PHP);
		$workspace->file('modules/core/kernel/helper_functions.php', <<<'PHP'
<?php
function dp_module_present(string $module): array|bool {
	$entry=dirname(__DIR__, 2).'/'.$module.'/kernel/'.$module.'.main.php';
	return is_file($entry) ? [$entry, '1.0'] : false;
}
PHP);
		$workspace->file('modules/sql/kernel/sql.main.php', $withSql ? <<<'PHP'
<?php
namespace dataphyre;
final class sql {}
PHP : '<?php');
		return str_replace('\\', '/', $workspace->root());
	}
}

/**
 * Portable failure seam for filesystem outcomes that the host cannot produce
 * deterministically (for example hash_file() failing for a readable file).
 */
function hash_file(string $algorithm, string $filename, bool $binary=false): string|false {
	$normalized=str_replace('\\', '/', $filename);
	$failures=TestState::channelIfActive('sql.seed-failures')?->get('hash_file', []) ?? [];
	if(in_array($normalized, is_array($failures) ? $failures : [], true)){
		return false;
	}
	return \hash_file($algorithm, $filename, $binary);
}
