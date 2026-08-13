<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Action;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\ResourceManifest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

/** @return mixed */
test('resource manifest normalizes malformed child definitions and skips invalid serialized children',static function(Context $t): void {
	$request=PanelRequest::fromArray(['resource'=>'broken','operation'=>'index']);
	$manifest=ResourceManifest::from(['name'=>'broken'],$request);

	$table=$t->nonPublic($manifest)->invoke('tableManifest',[
		'name'=>'broken','table_schema'=>['default_sort'=>'invalid-scalar'],
	]);
	$t->same('table_manifest',$table['type']);
	$t->same(null,$table['error'] ?? null);
	$t->same(0,count($table['columns'] ?? []));

	$schema=$t->nonPublic($manifest)->invoke('schemaManifest','create',[
		'schema'=>new stdClass(),
	]);
	$t->same('schema_manifest',$schema['type']);
	$t->same(null,$schema['error'] ?? null);

	$infolist=$t->nonPublic($manifest)->invoke('infolistManifest',[
		'name'=>'broken','form'=>new stdClass(),
	]);
	$t->same('schema_manifest',$infolist['type']);
	$t->notEmpty($infolist['error']);

	$fallback=ResourceManifest::from([
		'name'=>'fallback_resource','label'=>'Fallback',
		'actions'=>['invalid-action',['name'=>'kept_action','label'=>'Kept action']],
		'relations'=>['invalid-relation'],
	],$request)->toArray();
	$t->same(['kept_action'],array_keys($fallback['actions']));
	$t->same([],$fallback['relations']);

	$t->same('Resource',$t->nonPublic($manifest)->invoke('humanize',' '));
	$t->same('Order Items',$t->nonPublic($manifest)->invoke('humanize','order_items'));
})->tag('panel','resource-manifest','coverage')->group('framework-coverage');

test('resource manifest catches live action manifestation failures and ignores invalid action entries',static function(Context $t): void {
	$resource=Resource::make('action_resource');
	$action=Action::make('explode')->label(static fn()=>throw new RuntimeException('dynamic label failed'));
	$t->nonPublic($resource)->writeProperty('actions',[
		'invalid'=>new stdClass(),
		'explode'=>$action,
	]);
	$manifest=ResourceManifest::from($resource,PanelRequest::fromArray(['resource'=>'action_resource']));
	$result=$t->nonPublic($manifest)->invoke('actionManifests',['name'=>'action_resource']);
	$t->same(['explode'],array_keys($result));
	$t->same('action_manifest',$result['explode']['type']);
	$t->same('dynamic label failed',$result['explode']['error']);
})->tag('panel','resource-manifest','coverage')->group('framework-coverage');
