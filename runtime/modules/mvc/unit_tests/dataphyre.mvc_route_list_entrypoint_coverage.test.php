<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Mvc\Mvc;
use Dataphyre\Mvc\MvcApplication;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

test('mvc route list entrypoint covers helpers standalone config fallback and command modes', static function(Context $t): void {
	$modules=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
	$entry=$modules.'/mvc/kernel/route_list.php';
	require $entry;
	$workspace=$t->workspace('mvc-route-list');
	$tmp=$workspace->root();
	$t->throws(static fn()=>dp_mvc_route_list_app('missing', null, $tmp), RuntimeException::class);
	dp_mvc_route_list_require_framework($modules);

	$t->same(['help'=>true], dp_mvc_route_list_options(['route-list.php', '--help']));
	$t->same(
		['app'=>'named', 'config'=>'config.php', 'format'=>'json'],
		dp_mvc_route_list_options(['route-list.php', '--app=named', '--config=config.php', '--format=JSON'])
	);
	$t->same(['app'=>'positional', 'format'=>'json'], dp_mvc_route_list_options(['route-list.php', 'positional', '--json', 'ignored']));

	$usage='';
	dp_mvc_route_list_usage(static function(string $message) use (&$usage): void { $usage.=$message; });
	$t->contains('Usage:', $usage);
	$table=dp_mvc_route_list_table([
		['methods'=>['GET', 'HEAD'], 'path'=>'/items', 'name'=>'items', 'action'=>'ItemController@show', 'middleware'=>['web', 'auth']],
	]);
	$t->contains('GET|HEAD', $table);
	$t->contains('ItemController@show', $table);

	$config=$workspace->file('routes.php', <<<'PHP'
<?php
return [
	'routes'=>[
		['path'=>'/one', 'handler'=>['EntryController', 'show'], 'name'=>'one'],
	],
	'apps'=>[
		'child'=>['signed_url_secret'=>'child-secret'],
	],
];
PHP);
	$invalid=$workspace->file('invalid.php', "<?php return 'invalid';\n");
	$app=dp_mvc_route_list_app('child', $config, $modules);
	$t->instanceOf(MvcApplication::class, $app);
	$t->same('child-secret', $app->config('signed_url_secret'));
	$t->throws(static fn()=>dp_mvc_route_list_app('bad', $invalid, $modules), RuntimeException::class);

	$out=[];
	$err=[];
	$writeOut=static function(string $message) use (&$out): void { $out[]=$message; };
	$writeErr=static function(string $message) use (&$err): void { $err[]=$message; };
	$t->same(2, dp_mvc_route_list_main(['route-list.php'], false, $writeOut, $writeErr));
	$t->same(0, dp_mvc_route_list_main(['route-list.php', '--help'], true, $writeOut, $writeErr));
	$t->same(0, dp_mvc_route_list_main(['route-list.php', '--app=child', '--config='.$config, '--json'], true, $writeOut, $writeErr));
	$t->same(0, dp_mvc_route_list_main(['route-list.php', 'child', '--config='.$config, '--format=table'], true, $writeOut, $writeErr));
	$t->same(1, dp_mvc_route_list_main(['route-list.php', '--config='.$invalid], true, $writeOut, $writeErr));
	$t->contains('only available from CLI', implode('', $out));
	$t->contains('MVC route list config file must return an array', implode('', $err));

	$fakeModules=$workspace->directory('modules');
	$workspace->file('modules/core/kernel/bootstrap.php', "<?php\n");
	$workspace->file('modules/core/kernel/core_functions.php', <<<'PHP'
<?php
namespace dataphyre;
if(!class_exists(autoloader::class, false)){
	class autoloader { public static function register(string $root): void {} }
}
if(!class_exists(core::class, false)){
	class core { public static function load_framework_modules(array $modules): array { return $modules; } }
}
PHP);
	$fallback=new MvcApplication('fallback-entrypoint');
	Mvc::register('fallback-entrypoint', $fallback);
	$t->isTrue($fallback===dp_mvc_route_list_app('fallback-entrypoint', null, $fakeModules));
})->tag('mvc', 'entrypoint', 'coverage')->group('framework-coverage');
