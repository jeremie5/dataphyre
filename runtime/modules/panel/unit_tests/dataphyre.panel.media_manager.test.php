<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
use Dataphyre\Panel\PanelMediaManager;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
require_once __DIR__.'/panel_test_harness_helpers.php';dp_panel_unit_test_bootstrap();

test('media manager owns resumable upload assembly processing catalog delivery feeds and deletion',static function(Context $t):void{
	$root=$t->workspace('panel-media-manager')->root();$manager=PanelMediaManager::local($root,str_repeat('s',32),['cleanup_grace'=>0]);$contents=str_repeat('A',1024).str_repeat('B',1024);$checksum=hash('sha256',$contents);
	$upload=$manager->startUpload('uploads/report.bin',strlen($contents),['id'=>'upload_demo_1','chunk_size'=>1024,'checksum'=>$checksum,'metadata'=>['owner'=>'operator']]);$t->same('open',$upload['state']);
	$first=$manager->receiveChunk('upload_demo_1',0,substr($contents,0,1024),hash('sha256',substr($contents,0,1024)));$t->same(1,$first['session']['received_chunks']);
	$manager->receiveChunk('upload_demo_1',1,substr($contents,1024),hash('sha256',substr($contents,1024)));
	$completed=$manager->completeUpload('upload_demo_1',[],['name'=>'Report']);$t->isTrue($completed['ok']);$id=$completed['item']['id'];$t->same('Report',$manager->item($id)['name']);$t->same($checksum,$manager->item($id)['source']['checksum']);
	$delivery=$manager->issue($id,300,'attachment','operator');$t->contains('token=',$delivery['url']);$t->same($id,$delivery['claims']['media_id']??$delivery['payload']['media_id']??null);
	$t->isTrue($manager->changes(0)['cursor']>=4);$t->isTrue($manager->manifest()['capabilities']['resumable_uploads']);$t->isTrue($manager->delete($id));$t->same([], $manager->items());
})->tag('panel','media','manager','production')->maxMillis(5000);
