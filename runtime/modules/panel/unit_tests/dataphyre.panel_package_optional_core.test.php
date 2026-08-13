<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\dataphyre_path;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('package apply remains standalone when the legacy core dialback surface is absent', static function(Context $t): void {
	$worker=$t->tempFile(<<<'PHP'
<?php
declare(strict_types=1);
function tracelog(mixed ...$arguments): void {}
$modules=(string)$argv[1];
define('ROOTPATH', ['common_dataphyre_runtime'=>dirname($modules)]);
define('DATAPHYRE_MODULE_POLICY', [
	'enabled'=>['core'=>true, 'panel'=>true],
	'disabled'=>[],
	'core_implicit'=>true,
]);
require_once $modules.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($modules);
\dataphyre\autoloader::register_framework_modules(['panel']);
$template=\Dataphyre\Panel\PanelPackageTemplate::make('standalone-package')
	->plugin(false)
	->provider(false)
	->docs(false)
	->tests(false)
	->with('marketplace', false)
	->file('src/Standalone.php', '<?php return true;');
$result=\Dataphyre\Panel\PanelPackageInstallPlan::make($template)->apply((string)$argv[2], ['dry_run'=>true]);
echo json_encode([
	'core_loaded'=>class_exists('dataphyre\\core', false),
	'ok'=>$result->ok(),
	'blocked'=>count($result->blocked()),
	'dry_run'=>$result->toArray()['meta']['dry_run'] ?? null,
], JSON_THROW_ON_ERROR);
PHP, 'panel-package-optional-core');
	$result=$t->phpProcess([$worker, dataphyre_path('runtime/modules'), $t->tempDirectory('panel-package-optional-core-install')]);
	$t->same(0, $result->exitCode(), trim($result->stderr()));
	$payload=$result->json();
	$t->same(false, $payload['core_loaded'] ?? null);
	$t->same(true, $payload['ok'] ?? null);
	$t->same(0, $payload['blocked'] ?? null);
	$t->same(true, $payload['dry_run'] ?? null);
})->tag('panel', 'packages', 'standalone', 'integration')->maxMillis(3000);
