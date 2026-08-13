<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Reactor\Reactor;
use Dataphyre\Reactor\ReactorSigner;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('CFG')){
	define('CFG', ['secret'=>'', 'app_secret'=>'reactor-cfg-secret']);
}
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'reactor'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}

$dp_reactor_kernel_config_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_reactor_kernel_config_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_reactor_kernel_config_root);
\dataphyre\autoloader::register_framework_modules(['reactor']);
require_once $dp_reactor_kernel_config_root.'/reactor/kernel/reactor.main.php';

test('reactor framework config delegates to kernel while signer falls back through CFG secrets', static function(Context $t): void {
	$t->same('fallback', Reactor::config('missing', 'fallback'));
	$signature=ReactorSigner::sign(['component'=>'coverage']);
	$t->same(hash_hmac('sha256', '{"component":"coverage"}', 'reactor-cfg-secret'), $signature);
	$t->isTrue(ReactorSigner::verify(['component'=>'coverage'], $signature));
})->tag('reactor', 'coverage')->group('framework-coverage');
