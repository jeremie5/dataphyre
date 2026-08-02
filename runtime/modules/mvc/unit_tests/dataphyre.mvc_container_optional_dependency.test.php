<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Mvc\Container;
use Dataphyre\Mvc\ContainerException;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http', 'routing', 'mvc']);

final class DpMvcOptionalDependencyScalarLeaf {
	public function __construct(public array $keys) {}
}

final class DpMvcOptionalDependencyGraph {
	public function __construct(public DpMvcOptionalDependencyScalarLeaf $leaf) {}
}

final class DpMvcOptionalDependencyConsumer {
	public function __construct(public ?DpMvcOptionalDependencyGraph $dependency=null) {}
}

test('mvc container falls back to an optional default when reflection-only dependency construction fails', static function(Context $t): void {
	$consumer=(new Container())->make(DpMvcOptionalDependencyConsumer::class);
	$t->same(null, $consumer->dependency);
})->tag('mvc', 'container-optional-dependency')->group('framework-coverage');

test('mvc container preserves failures from explicitly registered optional dependencies', static function(Context $t): void {
	$container=new Container();
	$container->bind(DpMvcOptionalDependencyGraph::class);
	$t->throws(
		static fn()=>$container->make(DpMvcOptionalDependencyConsumer::class),
		ContainerException::class,
	);
})->tag('mvc', 'container-optional-dependency')->group('framework-coverage');
