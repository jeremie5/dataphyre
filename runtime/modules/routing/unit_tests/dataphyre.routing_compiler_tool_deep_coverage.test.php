<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Routing\RouteCompiler;
use Dataphyre\Routing\Tools\CompileApplicationRoutes;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'routing'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_routing_compiler_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_routing_compiler_modules_root.'/core/kernel/autoloader.php';
require_once $dp_routing_compiler_modules_root.'/core/kernel/app_locator.php';
require_once $dp_routing_compiler_modules_root.'/core/kernel/application_definition.php';
\dataphyre\autoloader::register($dp_routing_compiler_modules_root);
\dataphyre\autoloader::register_framework_modules(['routing']);

final class DpRouteExportableValue {
	public function __construct(public string $value='ready') {}

	public static function __set_state(array $properties): self {
		return new self((string)($properties['value'] ?? ''));
	}
}

test('routing compiler covers source discovery signatures compilation validation and persistence', static function(Context $t): void {
	$workspace=$t->workspace('routing-compiler');
	$tmp=$workspace->root();
	$routesDirectory=$workspace->directory('routes');
	$a=$workspace->file('routes/a.php', '<?php return [["name"=>"a","exact_path"=>"/a"]];');
	$b=$workspace->file('routes/b.php', '<?php return [["name"=>"b","exact_path"=>"/b"]];');
	$workspace->file('routes/ignored.txt', 'ignored');
	$invalidRoutes=$workspace->file('invalid-routes.php', '<?php return "invalid";');

		$t->same([], RouteCompiler::routeFiles('  '));
		$t->same(
			array_map(static fn(string $path): string=>str_replace('\\', '/', $path), [$a, $b]),
			array_map(static fn(string $path): string=>str_replace('\\', '/', $path), RouteCompiler::routeFiles($routesDirectory))
		);
		$t->same([$a], RouteCompiler::routeFiles($a));
		$t->throws(static fn()=>RouteCompiler::routeFiles($tmp.DIRECTORY_SEPARATOR.'missing.php'), RuntimeException::class);

		$mtimes=RouteCompiler::sourceMtimes($routesDirectory);
		$t->same(
			array_map(static fn(string $path): string=>str_replace('\\', '/', $path), [$a, $b]),
			array_map(static fn(string $path): string=>str_replace('\\', '/', $path), array_keys($mtimes))
		);
		$t->isTrue(is_int($mtimes[array_key_first($mtimes)]));
		$signature=RouteCompiler::manifestSignature([
			'sources'=>[$a=>0, $tmp.DIRECTORY_SEPARATOR.'missing.php'=>17],
			'application'=>'test',
		]);
		$t->same(64, strlen($signature));

		$manifest=RouteCompiler::compileFile($a, ['application'=>'test']);
		$t->same('test', $manifest['metadata']['application'] ?? null);
		$t->same($a, $manifest['metadata']['source_file'] ?? null);
		$t->throws(static fn()=>RouteCompiler::compileFile($invalidRoutes), RuntimeException::class);

		$missingManifest=$tmp.DIRECTORY_SEPARATOR.'missing-manifest.php';
		$t->throws(static fn()=>RouteCompiler::readManifestFile($missingManifest), RuntimeException::class);
		$scalarManifest=$workspace->file('scalar-manifest.php', '<?php return "invalid";');
		$t->throws(static fn()=>RouteCompiler::readManifestFile($scalarManifest), RuntimeException::class);
		$invalidManifest=$workspace->file('invalid-manifest.php', '<?php return ["routes"=>"invalid"];');
		$t->throws(static fn()=>RouteCompiler::readManifestFile($invalidManifest), RuntimeException::class);

		$t->isFalse(RouteCompiler::manifestExportable(static fn(): string=>'no'));
		$t->isTrue(RouteCompiler::manifestExportable(new DpRouteExportableValue()));
		$t->isFalse(RouteCompiler::manifestExportable(new stdClass()));
		$t->isFalse(RouteCompiler::manifestExportable(['nested'=>[static fn(): string=>'no']]));

		$target=$tmp.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'manifest.php';
		$t->isFalse(RouteCompiler::tryWriteManifestFile($target, ['routes'=>[static fn(): string=>'no']]));
		$t->isTrue(RouteCompiler::tryWriteManifestFile($target, $manifest));
		$t->same($manifest, RouteCompiler::readManifestFile($target));
		RouteCompiler::writeManifestFile($target, $manifest);
		$t->throws(
			static fn()=>RouteCompiler::writeManifestFile($target, ['routes'=>[static fn(): string=>'no']]),
			RuntimeException::class
		);

		$blockingFile=$workspace->file('not-a-directory', 'blocking');
		$t->throws(
			static fn()=>@RouteCompiler::writeManifestFile($blockingFile.DIRECTORY_SEPARATOR.'manifest.php', ['routes'=>[]]),
			RuntimeException::class
		);
})->tag('routing', 'route-compiler', 'deep-coverage')->maxMillis(5000);

test('routing application compiler covers discovery conventional and explicit definitions', static function(Context $t): void {
	$workspace=$t->workspace('routing-application-compiler');
	$tmp=$workspace->root();
	$applications=$workspace->directory('applications');
	$routePayload='<?php return [["name"=>"home","exact_path"=>"/"]];';

		$t->throws(static fn()=>CompileApplicationRoutes::compile($tmp, 'missing'), RuntimeException::class, 'was not found');

		$empty=$workspace->directory('applications/empty');
		$t->throws(static fn()=>CompileApplicationRoutes::compile($tmp, 'empty'), RuntimeException::class, 'no framework routes file');

		$withoutOutput=$workspace->directory('applications/without-output');
		$workspace->file('applications/without-output/routes.php', $routePayload);
		$t->throws(static fn()=>CompileApplicationRoutes::compile($tmp, 'without-output'), RuntimeException::class, 'no compiled routes output path');

		$conventional=$applications.DIRECTORY_SEPARATOR.'conventional';
		$conventionalOutput=$conventional.DIRECTORY_SEPARATOR.'backend'.DIRECTORY_SEPARATOR.'dataphyre'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'routes.compiled.php';
		$workspace->file('applications/conventional/routes.php', $routePayload);
		$workspace->file('applications/conventional/backend/dataphyre/cache/routes.compiled.php', '<?php return [];');
		$t->same(
			str_replace('\\', '/', $conventionalOutput),
			str_replace('\\', '/', CompileApplicationRoutes::compile($tmp, 'conventional'))
		);
		$t->same('conventional', RouteCompiler::readManifestFile($conventionalOutput)['metadata']['application'] ?? null);

		$arrayApp=$workspace->directory('applications/array-app');
		$workspace->file('applications/array-app/routes.php', $routePayload);
		$workspace->file('applications/array-app/app.php', '<?php return ["id"=>"array-id","compiled_routes_file"=>__DIR__."/array.compiled.php"];');
		$t->same(
			str_replace('\\', '/', $arrayApp.DIRECTORY_SEPARATOR.'array.compiled.php'),
			str_replace('\\', '/', CompileApplicationRoutes::compile($tmp, 'array-app'))
		);

		$objectApp=$workspace->directory('applications/object-app');
		$workspace->file('applications/object-app/routes.php', $routePayload);
		$workspace->file(
			'applications/object-app/app.php',
			'<?php return new \\dataphyre\\application_definition("object-id", __DIR__, null, __DIR__."/routes.php", __DIR__."/object.compiled.php");'
		);
		$t->same(
			str_replace('\\', '/', $objectApp.DIRECTORY_SEPARATOR.'object.compiled.php'),
			str_replace('\\', '/', CompileApplicationRoutes::compile($tmp, 'object-app'))
		);

		$invalidApp=$workspace->directory('applications/invalid-app');
		$workspace->file('applications/invalid-app/app.php', '<?php return "invalid";');
		$t->throws(static fn()=>CompileApplicationRoutes::compile($tmp, 'invalid-app'), RuntimeException::class, 'must return an array');
})->tag('routing', 'compile-application-routes', 'deep-coverage')->maxMillis(5000);
