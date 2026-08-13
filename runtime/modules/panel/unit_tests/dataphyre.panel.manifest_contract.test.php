<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManifestContract;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\Schema;
use Dataphyre\Panel\SchemaManifest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

test('public panel resource and schema manifests share one additive version contract', static function(Context $t): void {
	$t->same(['schema_version'=>1,'api_version'=>1], PanelManifestContract::versions());

	$schema=SchemaManifest::from(Schema::make(), 'create')->toArray();
	$t->same('schema_manifest', $schema['type']);
	$t->same(PanelManifestContract::SCHEMA_VERSION, $schema['schema_version']);
	$t->same(PanelManifestContract::API_VERSION, $schema['api_version']);

	$resource=Resource::make('contract-orders')->fields([['name'=>'number']])->columns([['name'=>'number']]);
	$resourceManifest=$resource->resourceManifest();
	$t->same('resource_manifest', $resourceManifest['type']);
	$t->same(PanelManifestContract::SCHEMA_VERSION, $resourceManifest['schema_version']);
	$t->same(PanelManifestContract::API_VERSION, $resourceManifest['api_version']);
	$t->same(PanelManifestContract::SCHEMA_VERSION, $resourceManifest['forms']['create']['schema_version'] ?? null);
	$t->same(PanelManifestContract::API_VERSION, $resourceManifest['table']['api_version'] ?? null);
	$t->same(PanelManifestContract::SCHEMA_VERSION, $resourceManifest['permission']['schema_version'] ?? null);

	$panel=PanelInstance::make('manifest-contract');
	$panel->register($resource);
	$panelManifest=$panel->panelManifest();
	$t->same('panel_manifest', $panelManifest['type']);
	$t->same(PanelManifestContract::SCHEMA_VERSION, $panelManifest['schema_version']);
	$t->same(PanelManifestContract::API_VERSION, $panelManifest['api_version']);
	$t->same(PanelManifestContract::SCHEMA_VERSION, $panelManifest['resources']['contract-orders']['schema_version'] ?? null);
	$t->same(PanelManifestContract::API_VERSION, $panelManifest['search']['api_version'] ?? null);
	$t->same(PanelManifestContract::SCHEMA_VERSION, $panelManifest['platform']['schema_version'] ?? null);
})->tag('panel', 'manifest', 'contract', 'versioning', 'wave0-contracts')->maxMillis(2000);

test('manifest contract stamps nested manifests and effects but leaves ordinary maps untouched', static function(Context $t): void {
	$payload=PanelManifestContract::stamp([
		'type'=>'custom_manifest',
		'schema_version'=>999,
		'api_version'=>999,
		'child'=>['type'=>'child_manifest','value'=>1],
		'delta'=>['type'=>'panel_manifest_delta','value'=>2],
		'effect'=>['type'=>'navigation_effect','target'=>'orders'],
		'effects'=>['type'=>'navigation_effects','targets'=>['orders']],
		'ordinary'=>['type'=>'record','schema_version'=>7],
	]);
	$t->same(1, $payload['schema_version']);
	$t->same(1, $payload['api_version']);
	$t->same(1, $payload['child']['schema_version']);
	$t->same(1, $payload['delta']['api_version']);
	$t->same(1, $payload['effect']['api_version']);
	$t->same(1, $payload['effects']['schema_version']);
	$t->same(7, $payload['ordinary']['schema_version']);
	$t->isFalse(array_key_exists('api_version', $payload['ordinary']));
	$t->same(['schema_version'=>1,'api_version'=>1,'value'=>'untyped'], PanelManifestContract::stamp(['value'=>'untyped']));
})->tag('panel', 'manifest', 'contract', 'nested', 'wave0-contracts')->maxMillis(500);
