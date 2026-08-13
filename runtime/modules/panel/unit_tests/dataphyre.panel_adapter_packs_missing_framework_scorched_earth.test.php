<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelDataphyreAccessPlugin;
use Dataphyre\Panel\PanelDataphyreAdapterPack;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

suite('Panel adapter-pack missing-framework boundaries')
	->contract('panel.adapter-packs.optional-frameworks',1)
	->layer('integration')
	->risk('high')
	->watches('module:panel','module:access')
	->through('preflight','plugin-register','secret-free-manifest')
	->tag('panel','adapter-pack','scorched-earth','optional-framework')
	->group('panel-platform-contract');

test('Access bridge fails closed when its optional framework is unavailable',static function(Context $t):void {
	$t->isFalse(class_exists(\Dataphyre\Access\PanelAuth::class));
	$panel=Panel::make('adapter_pack_missing_access')->usePlatform(PanelPlatform::make());
	$plugin=new PanelDataphyreAccessPlugin([
		'client_secret'=>'access-bridge-secret',
		'realm'=>'operations',
	]);

	$t->same('dataphyre_access',$plugin->id());
	$t->same('1.0.0',$plugin->version());
	$t->same('Dataphyre Access',$plugin->label());
	$t->contains('authentication',$plugin->description());
	$manifest=json_encode($plugin,JSON_THROW_ON_ERROR);
	$t->contains('"option_count":2',$manifest);
	$t->contains('"configuration_serialized":false',$manifest);
	$t->notContains('access-bridge-secret',$manifest);
	$plugin->boot($panel);
	$t->throws(static fn()=>$plugin->register($panel),RuntimeException::class);

	$plan=PanelDataphyreAdapterPack::make()->plan($panel,[
		'adapters'=>['access'=>true],
	]);
	$t->isFalse($plan->ready());
	$t->contains('requires unavailable class',implode(' ',$plan->errors()));
	$t->contains(\Dataphyre\Access\PanelAuth::class,implode(' ',$plan->errors()));
})->tag('panel','adapter-pack','access','missing-dependency','coverage')->isolation('case')->maxMillis(3000);

test('Storage media bridge fails during preflight when Dataphyre Storage is unavailable',static function(Context $t):void {
	$t->isFalse(class_exists(\Dataphyre\Storage\StorageManager::class));
	$panel=Panel::make('adapter_pack_missing_storage')->usePlatform(PanelPlatform::make());
	$plan=PanelDataphyreAdapterPack::make()->plan($panel,[
		'adapters'=>[
			'storage_media'=>[
				'disk'=>'private',
				'catalog_directory'=>'/not-reached',
			],
		],
	]);
	$t->isFalse($plan->ready());
	$t->contains('requires unavailable class',implode(' ',$plan->errors()));
	$t->contains(\Dataphyre\Storage\StorageManager::class,implode(' ',$plan->errors()));
})->tag('panel','adapter-pack','storage','media','missing-dependency','coverage')->isolation('case')->maxMillis(3000);
