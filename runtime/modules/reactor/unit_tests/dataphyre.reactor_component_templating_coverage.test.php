<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Reactor\ReactorComponent;
use Dataphyre\Templating\Templating;
use Dataphyre\Templating\TemplatingManager;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>[
			'core'=>true,
			'mvc'=>true,
			'reactor'=>true,
			'templating'=>true,
		],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_reactor_templating_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_reactor_templating_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_reactor_templating_modules_root);
\dataphyre\autoloader::register_framework_modules(['core', 'mvc', 'reactor', 'templating']);
require_once $dp_reactor_templating_modules_root.'/templating/unit_tests/templating_render_test_helpers.php';

test('reactor component delegates string rendering to the available templating module', static function(Context $t): void {
	$cache=rtrim($t->workspace('reactor-templating')->directory('cache'), '/\\').DIRECTORY_SEPARATOR;
	\dataphyre\templating::init(false, $cache, false);
	TemplatingManager::flush();
	$t->isTrue(class_exists(Templating::class));
	$t->isTrue(class_exists('dataphyre\\templating', false));
	$html=ReactorComponent::make('templated-reactor')
		->render('<p>Hello {{ name }}</p>')
		->renderHtml(['name'=>'Ada']);
	$t->contains('Hello Ada', $html);
})->tag('reactor', 'coverage')->group('framework-coverage');
