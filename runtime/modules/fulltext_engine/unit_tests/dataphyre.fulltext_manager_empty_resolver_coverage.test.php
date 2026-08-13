<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\FulltextEngine\Contracts\DocumentResolver;
use Dataphyre\FulltextEngine\HydratedSearchResults;
use Dataphyre\FulltextEngine\IndexDefinition;
use Dataphyre\FulltextEngine\SearchManager;
use Dataphyre\FulltextEngine\SearchResults;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'fulltext_engine'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
if(!defined('DP_FULLTEXT_ENGINE_CFG')){
	define('DP_FULLTEXT_ENGINE_CFG', [
		'framework'=>[
			'indexes'=>[],
			'resolvers'=>[],
		],
	]);
}
$dp_fulltext_empty_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_fulltext_empty_modules_root.'/core/kernel/autoloader.php';
if(!function_exists('tracelog')){
	function tracelog(...$arguments): void {}
}
require_once __DIR__.'/fulltext_coverage_helpers.php';
\dataphyre\autoloader::register($dp_fulltext_empty_modules_root);
\dataphyre\autoloader::register_framework_modules(['fulltext_engine']);

final class DpFulltextDirectResolver implements DocumentResolver {
	public function resolve(array $ids, ?IndexDefinition $definition=null): array {
		return array_fill_keys(array_map('strval', $ids), ['resolved'=>true]);
	}
}

test('fulltext manager handles absent configuration direct resolvers and numeric definitions', static function(Context $t): void {
	SearchManager::flush();
	$manager=SearchManager::instance();
	$t->same(null, $manager->resolver('unconfigured'));

	$results=new SearchResults('unconfigured', [], 0, 0.0, 0.0);
	$t->throws(static fn()=>$manager->hydrate($results), RuntimeException::class, 'No fulltext document resolver');
	$t->instanceOf(HydratedSearchResults::class, $manager->hydrate($results, new DpFulltextDirectResolver()));

	$report=$manager->sync([
		['name'=>'named-definition', 'type'=>'json', 'primary_key'=>'id'],
		['type'=>'json', 'primary_key'=>'id'],
	]);
	$t->same(1, count($report->created()));
	$t->same(1, count($report->failed()));
	SearchManager::flush();
})->tag('fulltext', 'manager', 'deep-coverage')->group('framework-coverage');
