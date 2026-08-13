<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

test('mvc route cache and clear entrypoints cover command success help and failures', static function(Context $t): void {
	$modules=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
	require $modules.'/mvc/kernel/cache_routes.php';
	require $modules.'/mvc/kernel/clear_cached_routes.php';
	$workspace=$t->workspace('mvc-route-cache');
	$cache=$workspace->path('routes.cache.php');
	$unexportableCache=$workspace->path('unexportable.cache.php');
	$successConfig=$workspace->file('success.php', '<?php return '.var_export([
		'manifest_cache'=>$cache,
		'routes'=>[
			['path'=>'/cached', 'handler'=>['CacheController', 'show'], 'name'=>'cached'],
		],
	], true).';');
	$missingConfig=$workspace->file('missing.php', "<?php return ['manifest_cache'=>false];\n");
	$unexportableConfig=$workspace->file(
		'unexportable.php',
		"<?php return ['manifest_cache'=>".var_export($unexportableCache, true).", 'routes'=>[['path'=>'/closure', 'handler'=>static fn(): string=>'closure']]];\n"
	);

	$out=[];
	$err=[];
	$writeOut=static function(string $message) use (&$out): void { $out[]=$message; };
	$writeErr=static function(string $message) use (&$err): void { $err[]=$message; };
	$t->same(2, dp_mvc_cache_routes_main(['cache-routes.php'], false, $writeOut, $writeErr));
	$t->same(0, dp_mvc_cache_routes_main(['cache-routes.php', '--help'], true, $writeOut, $writeErr));
	$t->same(1, dp_mvc_cache_routes_main(['cache-routes.php', '--config='.$missingConfig], true, $writeOut, $writeErr));
	$t->same(1, dp_mvc_cache_routes_main(['cache-routes.php', '--config='.$unexportableConfig], true, $writeOut, $writeErr));
	$t->same(0, dp_mvc_cache_routes_main(['cache-routes.php', '--app=cache', '--config='.$successConfig], true, $writeOut, $writeErr));
	$t->isTrue(is_file($cache));

	$t->same(2, dp_mvc_clear_cached_routes_main(['clear-routes.php'], false, null, $writeOut, $writeErr));
	$t->same(0, dp_mvc_clear_cached_routes_main(['clear-routes.php', '--help'], true, null, $writeOut, $writeErr));
	$t->same(1, dp_mvc_clear_cached_routes_main(
		['clear-routes.php', '--config='.$successConfig],
		true,
		static fn(string $file): bool=>false,
		$writeOut,
		$writeErr
	));
	$t->isTrue(is_file($cache));
	$t->same(0, dp_mvc_clear_cached_routes_main(['clear-routes.php', '--config='.$successConfig], true, null, $writeOut, $writeErr));
	$t->isFalse(is_file($cache));
	$t->same(0, dp_mvc_clear_cached_routes_main(['clear-routes.php', '--config='.$successConfig], true, null, $writeOut, $writeErr));
	$t->same(1, dp_mvc_clear_cached_routes_main(['clear-routes.php', '--config='.$missingConfig], true, null, $writeOut, $writeErr));
	$t->contains('MVC routes cached', implode('', $out));
	$t->contains('MVC route manifest is not exportable', implode('', $err));
	$t->contains('Unable to delete MVC route cache', implode('', $err));
})->tag('mvc', 'entrypoint', 'coverage')->group('framework-coverage');
