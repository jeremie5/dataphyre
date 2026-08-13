<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelDeveloperToolkit;
use Dataphyre\Panel\PanelManifestDiff;
use Dataphyre\Panel\PanelManifestInspector;
use Dataphyre\Panel\PanelQualityMatrix;
use Dataphyre\Panel\PanelSchemaBlueprint;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

test('manifest inspector fingerprints capabilities and reports duplicate identities and unsafe values', static function(Context $t): void {
	$manifest=[
		'type'=>'panel_manifest',
		'resources'=>[
			['name'=>'orders', 'capabilities'=>['table'=>true, 'bulk'=>['delete'=>true]]],
			['name'=>'orders'],
			['label'=>'Missing identity'],
		],
		'callback'=>static fn(): bool => true,
		'help_url'=>'javascript:alert(1)',
	];
	$inspection=PanelManifestInspector::inspect($manifest);
	$t->isFalse($inspection->passed());
	$t->same(1, count($inspection->issues('error'))>=1 ? 1 : 0);
	$t->isTrue(in_array('table', $inspection->jsonSerialize()['capabilities'], true));
	$t->isTrue(in_array('bulk.delete', $inspection->jsonSerialize()['capabilities'], true));
	$t->same(64, strlen($inspection->hash()));
	$t->same(1, $inspection->metrics()['callables']);
})->tag('panel', 'development', 'inspection')->maxMillis(1000);

test('manifest diff reports deterministic added removed and changed paths', static function(Context $t): void {
	$diff=PanelManifestDiff::between(
		['name'=>'orders', 'fields'=>['title'=>['required'=>false], 'old'=>true]],
		['name'=>'orders', 'fields'=>['title'=>['required'=>true], 'new'=>true]]
	);
	$t->isTrue($diff->changed());
	$t->same(['added'=>1, 'removed'=>1, 'changed'=>1, 'total'=>3], $diff->summary());
	$t->same('fields.old', $diff->changes('removed')[0]['path']);
	$t->same('fields.new', $diff->changes('added')[0]['path']);
	$t->same('fields.title.required', $diff->changes('changed')[0]['path']);
})->tag('panel', 'development', 'diff')->maxMillis(1000);

test('schema blueprint generates resource forms tables relationships and complete PHP source', static function(Context $t): void {
	$blueprint=PanelSchemaBlueprint::make('orders', [
		'id'=>['type'=>'bigint', 'nullable'=>false, 'generated'=>true],
		'customer_id'=>['type'=>'bigint', 'nullable'=>false],
		'title'=>['type'=>'varchar', 'length'=>180, 'nullable'=>false],
		'status'=>['type'=>'varchar', 'enum'=>['draft','paid'], 'default'=>'draft'],
		'total'=>['type'=>'decimal', 'nullable'=>false, 'default'=>0],
		'metadata'=>['type'=>'json', 'nullable'=>true],
		'created_at'=>['type'=>'datetime'],
	], ['foreign_keys'=>['customer'=>['column'=>'customer_id', 'table'=>'customers']]]);
	$manifest=$blueprint->manifest();
	$t->same($manifest, $blueprint->jsonSerialize());
	$t->same('Order', $manifest['resource']);
	$t->same(5, count($manifest['fields']));
	$t->same('select', $manifest['fields'][2]['type']);
	$t->same('Customer', $manifest['relations'][0]['related_resource']);
	$php=$blueprint->php('App\\Admin');
	$t->contains('namespace App\\Admin;', $php);
	$t->contains('final class OrderResource', $php);
	$t->contains("'panel_schema_blueprint'", $php);
	$t->same('OrderItem', PanelSchemaBlueprint::make('public.order_items', [])->resourceName());
	$t->contains('namespace App\\Admin;', $blueprint->php('\\App\\Admin\\'));
	$t->throws(static fn()=>$blueprint->php('App; system("id")'), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSchemaBlueprint::make('123', [])->php(), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSchemaBlueprint::make("orders\0unsafe", []), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSchemaBlueprint::make("orders\xB1", []), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSchemaBlueprint::make(str_repeat('t',256), []), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSchemaBlueprint::make('orders', array_fill(0,513,['name'=>'field'])), LengthException::class);
	$t->throws(static fn()=>PanelSchemaBlueprint::make('orders', ['unsafe'=>new stdClass()]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSchemaBlueprint::make('orders', ['unsafe'=>['default'=>new stdClass()]]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSchemaBlueprint::make('orders', ['unsafe'=>['default'=>INF]]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSchemaBlueprint::make('orders', [], ['foreign_keys'=>'invalid']), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSchemaBlueprint::make('orders', [], ['foreign_keys'=>array_fill(0,513,[])]), LengthException::class);
	$t->throws(static fn()=>PanelSchemaBlueprint::make('orders', [], ['foreign_keys'=>['unsafe'=>new stdClass()]]), InvalidArgumentException::class);
	$deep=true; for($depth=0;$depth<10;$depth++){ $deep=[$deep]; }
	$t->throws(static fn()=>PanelSchemaBlueprint::make('orders', ['deep'=>['default'=>$deep]]), LengthException::class);
	$t->throws(static fn()=>PanelSchemaBlueprint::make('orders', ['wide'=>['default'=>array_fill(0,257,true)]]), LengthException::class);
	$t->throws(static fn()=>PanelSchemaBlueprint::make('orders', ['long'=>['default'=>str_repeat('x',4097)]]), LengthException::class);
	$t->throws(static fn()=>PanelSchemaBlueprint::make('orders', ['utf8'=>['default'=>"\xB1"]]), InvalidArgumentException::class);
})->tag('panel', 'development', 'schema', 'generation')->maxMillis(1000);

test('quality matrix generates exhaustive browser manifests across configured axes', static function(Context $t): void {
	$matrix=PanelQualityMatrix::make('orders', '/panel/orders', [
		'viewport'=>[['width'=>1280,'height'=>720],['width'=>390,'height'=>844,'mobile'=>true]],
		'theme'=>['default','glass'], 'locale'=>['en','fr'], 'direction'=>['ltr','rtl'],
		'zoom'=>[100,200], 'motion'=>['normal','reduced'], 'network'=>['online','offline'],
	]);
	$payload=$matrix->jsonSerialize();
	$t->same(256, $payload['cases']);
	$t->same('panel_browser_regression_manifest', $payload['manifests'][0]['type']);
	$t->isTrue($payload['manifests'][1]['accessibility']['enabled']);
	$t->same('/panel/orders', $payload['manifests'][0]['url']);
	$singleViewport=PanelQualityMatrix::make('single viewport', '/single', ['viewport'=>['width'=>800,'height'=>600]]);
	$t->same(48, $singleViewport->jsonSerialize()['cases']);
	$t->pathEquals('manifests.0.viewport.width', 800, $singleViewport->jsonSerialize());
	$t->throws(static fn()=>PanelQualityMatrix::make('empty', '/one', ['theme'=>[]]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityMatrix::make('unsafe', '/one', ['!!!'=>['one']]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityMatrix::make('duplicate', '/one', ['foo bar'=>['one'],'foo_bar'=>['two']]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityMatrix::make('object', '/one', ['custom'=>[new stdClass()]]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityMatrix::make('wide', '/one', ['custom'=>range(1,65)]), LengthException::class);
	$t->throws(static fn()=>PanelQualityMatrix::make('many', '/one', array_fill(0,17,['one'])), LengthException::class);
	$t->throws(static fn()=>PanelQualityMatrix::make('items', '/one', ['custom'=>[array_fill(0,129,true)]]), LengthException::class);
	$deep=true; for($depth=0;$depth<10;$depth++){ $deep=[$deep]; }
	$t->throws(static fn()=>PanelQualityMatrix::make('deep', '/one', ['custom'=>[$deep]]), LengthException::class);
	$t->throws(static fn()=>PanelQualityMatrix::make('string', '/one', ['custom'=>[str_repeat('x',4097)]]), LengthException::class);
	$t->throws(static fn()=>PanelQualityMatrix::make('utf8', '/one', ['custom'=>["\xB1"]]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityMatrix::make('blank', ' ', []), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityMatrix::make('url', "\xB1", []), InvalidArgumentException::class);
	$t->throws(static fn()=>$matrix->maximumCases(10)->manifests(), OverflowException::class);
})->tag('panel', 'development', 'quality-matrix')->maxMillis(2000);

test('developer toolkit exposes one cohesive inspection generation diff and accessibility API', static function(Context $t): void {
	$t->isTrue(PanelDeveloperToolkit::inspect(['type'=>'panel'])->passed());
	$t->isFalse(PanelDeveloperToolkit::diff(['a'=>1], ['a'=>2])->jsonSerialize()['changed']===false);
	$t->same('Seller', PanelDeveloperToolkit::blueprint('sellers', ['id'=>['type'=>'integer']])->resourceName());
	$t->same(48, PanelDeveloperToolkit::qualityMatrix('one', '/one', ['viewport'=>[['width'=>800,'height'=>600]]])->jsonSerialize()['cases']);
	$t->isTrue(PanelDeveloperToolkit::accessibility('<main><h1>Ready</h1></main>')->passed());
})->tag('panel', 'development', 'toolkit')->maxMillis(1000);
