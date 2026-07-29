<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\DataEnvironment;
use Dataphyre\Database\SqlError;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['sql']);

test('data environment scopes SQL clusters and cache namespaces without leaking nested work', static function(Context $t): void {
	$t->same(['name'=>'live', 'cluster'=>null, 'cache_namespace'=>null], DataEnvironment::current());
	$t->isFalse(DataEnvironment::active());
	$t->isTrue(DataEnvironment::is('LIVE'));
	$t->same('orders', DataEnvironment::cacheKey('orders'));
	$t->same('orders/one', DataEnvironment::cachePath('orders/one'));

	$result=DataEnvironment::run('Sandbox', static function(array $environment) use ($t): string {
		$t->same(['name'=>'sandbox', 'cluster'=>null, 'cache_namespace'=>'serve-sandbox'], $environment);
		$t->isTrue(DataEnvironment::active());
		$t->isTrue(DataEnvironment::is('sandbox'));
		$t->same('sandbox', DataEnvironment::name());
		$t->same(null, DataEnvironment::clusterOverride());
		$t->same('serve-sandbox', DataEnvironment::cacheNamespace());
		$t->same('serve-sandbox::orders', DataEnvironment::cacheKey('orders'));
		$t->same('serve-sandbox/orders', DataEnvironment::cachePath('orders'));

		return DataEnvironment::run('preview', static function(): string {
			if(DataEnvironment::name()!=='preview' || DataEnvironment::cacheKey('orders')!=='preview::orders'){
				throw new RuntimeException('nested_data_environment_failed');
			}
			return 'nested-result';
		});
	}, ['cluster'=>null, 'cache_namespace'=>'serve-sandbox']);

	$t->same('nested-result', $result);
	$t->same('live', DataEnvironment::name());
	$t->isFalse(DataEnvironment::active());
})->tag('database', 'environment', 'isolation');

test('data environment restores context after failures and enforces stack ownership', static function(Context $t): void {
	$t->throws(static function(): void {
		DataEnvironment::run('sandbox', static function(): void {
			throw new LogicException('expected');
		});
	}, LogicException::class);
	$t->same('live', DataEnvironment::name());

	$token=DataEnvironment::push('sandbox');
	$t->throws(static fn()=>DataEnvironment::pop(str_repeat('0', 32)), RuntimeException::class);
	$t->same('sandbox', DataEnvironment::name());
	DataEnvironment::pop($token);
	$t->same('live', DataEnvironment::name());

	foreach(['', '../sandbox', 'space name', 'UPPER SPACE'] as $invalid){
		$t->throws(static fn()=>DataEnvironment::push($invalid), InvalidArgumentException::class);
	}
	$t->throws(
		static fn()=>DataEnvironment::push('sandbox', ['cache_namespace'=>'../unsafe']),
		InvalidArgumentException::class
	);
	$t->throws(
		static fn()=>DataEnvironment::push('sandbox', ['cluster'=>'missing cluster']),
		InvalidArgumentException::class
	);

	if(defined('DP_SQL_CFG') && is_array(DP_SQL_CFG)){
		$coreConfig=defined('DP_CORE_CFG') && is_array(DP_CORE_CFG) ? DP_CORE_CFG : [];
		$datacenter=trim((string)($coreConfig['datacenter'] ?? ''));
		$clusters=DP_SQL_CFG['datacenters'][$datacenter]['dbms_clusters'] ?? [];
		if(is_array($clusters) && $clusters!==[]){
			$t->throws(
				static fn()=>DataEnvironment::push('sandbox', ['cluster'=>'DefinitelyMissingCluster']),
				SqlError::class
			);
		}
	}
})->tag('database', 'environment', 'failure-safety');

test('data environment keeps Fiber execution contexts independent', static function(Context $t): void {
	$main=DataEnvironment::push('sandbox', ['cache_namespace'=>'main-sandbox']);
	$fiber=new Fiber(static function(): string {
		return DataEnvironment::run('preview', static function(): string {
			Fiber::suspend([
				'name'=>DataEnvironment::name(),
				'key'=>DataEnvironment::cacheKey('orders'),
			]);
			return DataEnvironment::name();
		}, ['cache_namespace'=>'fiber-preview']);
	});

	$t->same(['name'=>'preview', 'key'=>'fiber-preview::orders'], $fiber->start());
	$t->same('sandbox', DataEnvironment::name());
	$t->same(null, $fiber->resume());
	$t->isTrue($fiber->isTerminated());
	$t->same('preview', $fiber->getReturn());
	$t->same('sandbox', DataEnvironment::name());
	DataEnvironment::pop($main);
	$t->same('live', DataEnvironment::name());
})->tag('database', 'environment', 'fiber');
