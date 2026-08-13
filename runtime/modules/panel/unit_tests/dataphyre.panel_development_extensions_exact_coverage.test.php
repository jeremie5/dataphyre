<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelExtensionDescriptor;
use Dataphyre\Panel\PanelExtensionRegistry;
use Dataphyre\Panel\PanelExtensionRuntime;
use Dataphyre\Panel\PanelManifestInspector;
use Dataphyre\Panel\PanelSchemaBlueprint;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('manifest inspector accepts both public serialization protocols and rejects opaque values', static function(Context $t): void {
	$jsonManifest=new class implements JsonSerializable {
		public function jsonSerialize(): array { return ['type'=>'json-manifest', 'capabilities'=>['inspectable'=>true]]; }
	};
	$t->isTrue(PanelManifestInspector::inspect($jsonManifest)->passed());

	$scalarJson=new class implements JsonSerializable {
		public function jsonSerialize(): string { return 'serialized-value'; }
	};
	$t->same('serialized-value', PanelManifestInspector::inspect($scalarJson)->jsonSerialize()['manifest']['value']);

	$arrayManifest=new class {
		public function toArray(): array { return ['type'=>'array-manifest']; }
	};
	$t->isTrue(PanelManifestInspector::inspect($arrayManifest)->passed());

	$scalarArray=new class {
		public function toArray(): string { return 'array-protocol-value'; }
	};
	$t->same('array-protocol-value', PanelManifestInspector::inspect($scalarArray)->jsonSerialize()['manifest']['value']);
	$t->throws(static fn()=> PanelManifestInspector::inspect(new stdClass()), InvalidArgumentException::class);
})->tag('panel', 'development', 'manifest-inspector', 'exact-coverage')->maxMillis(1000);

test('schema and extension public contracts expose registered values through JSON without hidden lookup rules', static function(Context $t): void {
	$blueprint=PanelSchemaBlueprint::make('orders', ['id'=>['type'=>'integer']]);
	$t->same($blueprint->manifest(), $blueprint->jsonSerialize());

	$descriptor=PanelExtensionDescriptor::make('audit', '1.0.0');
	$registry=(new PanelExtensionRegistry())->register($descriptor);
	$t->same($descriptor, $registry->get(' AUDIT '));
	$t->same(null, $registry->get('missing'));
	$t->same($registry->manifest(), $registry->jsonSerialize());

	$runtime=(new PanelExtensionRuntime())->on('audit.recorded', static fn(mixed $payload): mixed=>$payload, 10, 'admin');
	$t->same($runtime->manifest(), $runtime->jsonSerialize());
})->tag('panel', 'development', 'extensions', 'json-contracts', 'exact-coverage')->maxMillis(1000);
