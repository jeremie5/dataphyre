<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelAtomicSnapshotStore;
use Dataphyre\Panel\PanelFilesystemNotificationAdapter;
use Dataphyre\Panel\PanelInboxNotification;
use Dataphyre\Panel\PanelNotificationActivityStore;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

suite('Panel notification and activity persistence')
	->contract('panel.notification-activity', 1)
	->layer('integration')
	->risk('high')
	->watches('module:panel')
	->through('snapshot-store', 'notification-adapter', 'activity-store')
	->isolation('case')
	->tag('panel', 'notifications', 'activity')
	->group('framework-coverage');

test('atomic snapshots validate identity serialize manifests limit feeds and ignore malformed snapshots', static function(Context $t): void {
	$t->throws(static fn()=>new PanelAtomicSnapshotStore(' ','valid'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAtomicSnapshotStore($t->tempDirectory('snapshot-invalid-schema'),'not valid'),InvalidArgumentException::class);
	$directory=$t->tempDirectory('snapshot-contract');
	$store=new PanelAtomicSnapshotStore($directory,'panel.contract',['value'=>0]);
	$t->throws(static fn()=>$store->transaction(static function(array &$payload): void {},' '),InvalidArgumentException::class);
	foreach(range(1,3) as $value){
		$store->transaction(static function(array &$payload)use($value): void { $payload['value']=$value; },'value.changed',['value'=>$value]);
	}
	$t->same(1,count($store->changesSince(0,1)['changes']));
	$t->same($store->manifest(),$store->jsonSerialize());

	file_put_contents($directory.DIRECTORY_SEPARATOR.'00000000000000000004.json','');
	file_put_contents($directory.DIRECTORY_SEPARATOR.'00000000000000000005.json',json_encode(['schema'=>'other','sequence'=>5,'payload'=>[]],JSON_THROW_ON_ERROR));
	file_put_contents($directory.DIRECTORY_SEPARATOR.'00000000000000000006.json',json_encode(['schema'=>'panel.contract','sequence'=>99,'payload'=>[]],JSON_THROW_ON_ERROR));
	$t->same(3,(new PanelAtomicSnapshotStore($directory,'panel.contract'))->cursor());

	$blocked=$t->tempDirectory('snapshot-blocked').DIRECTORY_SEPARATOR.'file';
	file_put_contents($blocked,'not a directory');
	$t->throws(static fn()=>new PanelAtomicSnapshotStore($blocked.DIRECTORY_SEPARATOR.'child','panel.contract'),RuntimeException::class);
})->tag('panel','notifications','snapshots','coverage')->group('panel-lane-c');

test('atomic snapshots surface lock write flush collision and commit failures without corrupting state', static function(Context $t): void {
	$io=$t->state('panel.snapshot.io',['failure'=>'']);
	if(!function_exists('Dataphyre\\Panel\\dp_panel_snapshot_io_failure')){
		$t->defineSymbols(<<<'PHP'
namespace Dataphyre\Panel;
function dp_panel_snapshot_io_failure(): string {
	return (string)\Dataphyre\Test\TestState::channel('panel.snapshot.io')->get('failure','');
}
function is_link(string $filename): bool {
	if(dp_panel_snapshot_io_failure()==='symlink'){ return true; }
	return \is_link($filename);
}
function is_writable(string $filename): bool {
	if(dp_panel_snapshot_io_failure()==='not_writable'){ return false; }
	return \is_writable($filename);
}
function fopen(string $filename,string $mode,bool $use_include_path=false,mixed $context=null): mixed {
	$failure=dp_panel_snapshot_io_failure();
	if($failure==='lock_open' && str_ends_with($filename,'.lock')){ return false; }
	if($failure==='temp_open' && str_ends_with($filename,'.tmp')){ return false; }
	return $context===null ? \fopen($filename,$mode,$use_include_path) : \fopen($filename,$mode,$use_include_path,$context);
}
function flock(mixed $stream,int $operation,?int &$would_block=null): bool {
	if(dp_panel_snapshot_io_failure()==='lock'){ return false; }
	return \flock($stream,$operation,$would_block);
}
function fwrite(mixed $stream,string $data,?int $length=null): int|false {
	if(dp_panel_snapshot_io_failure()==='write'){ return 0; }
	return $length===null ? \fwrite($stream,$data) : \fwrite($stream,$data,$length);
}
function fflush(mixed $stream): bool {
	if(dp_panel_snapshot_io_failure()==='flush'){ return false; }
	return \fflush($stream);
}
function rename(string $from,string $to,mixed $context=null): bool {
	if(dp_panel_snapshot_io_failure()==='rename'){ return false; }
	return $context===null ? \rename($from,$to) : \rename($from,$to,$context);
}
PHP);
	}
	$directory=$t->tempDirectory('snapshot-faults');
	$io->put('failure','symlink');
	$t->throws(static fn()=>new PanelAtomicSnapshotStore($directory,'panel.faults'),RuntimeException::class);
	$io->put('failure','not_writable');
	$t->throws(static fn()=>new PanelAtomicSnapshotStore($directory,'panel.faults'),RuntimeException::class);
	$io->put('failure','');
	$store=new PanelAtomicSnapshotStore($directory,'panel.faults',['count'=>0]);
	foreach(['lock_open','lock'] as $failure){
		$io->put('failure',$failure);
		$t->throws(static fn()=>$store->payload(),RuntimeException::class);
	}
	foreach(['temp_open','write','flush','rename'] as $failure){
		$io->put('failure',$failure);
		$t->throws(static fn()=>$store->transaction(static function(array &$payload): void { $payload['count']++; },'count.changed'),RuntimeException::class);
	}
	$io->put('failure','');
	file_put_contents($directory.DIRECTORY_SEPARATOR.'00000000000000000001.json',json_encode([
		'schema'=>'panel.faults','sequence'=>1,'committed_at'=>gmdate('c'),'payload'=>['count'=>1],'event'=>[],
	],JSON_THROW_ON_ERROR));
	file_put_contents($directory.DIRECTORY_SEPARATOR.'00000000000000000002.json','reserved');
	$t->throws(static fn()=>$store->transaction(static function(array &$payload): void {},'collision'),RuntimeException::class);
})->tag('panel','notifications','snapshots','fault-injection','coverage')->group('panel-lane-c');

test('filesystem notifications seed inboxes transition unread state and describe every delivery outcome', static function(Context $t): void {
	$resource=fopen('php://temp','r+');
	try{
		$seed=new PanelInboxNotification(['id'=>'seed','message'=>'Seed','channels'=>[]]);
		$adapter=new PanelFilesystemNotificationAdapter($t->tempDirectory('notification-deliveries'),[$seed],['database','mail'],[
			'rejected'=>static fn(): bool=>false,
			'scalar'=>static fn(): string=>'provider-id',
			'failed'=>static function(): never { throw new RuntimeException('provider offline'); },
			'unsafe'=>static fn()=>['status'=>'delivered','data'=>$resource],
		]);
		$t->throws(static fn()=>$adapter->handler(' ',static fn()=>true),InvalidArgumentException::class);
		$t->same(null,$adapter->get(' '));
		$t->isTrue($adapter->markUnread('seed'));
		$t->isFalse($adapter->markUnread('missing'));
		$t->same([],$adapter->deliver('missing'));
		$t->same(['database','mail'],array_column($adapter->deliver('seed',[]),'channel'));
		$t->same('rejected',$adapter->deliver($seed,'rejected')[0]['status']);
		$t->same('provider-id',$adapter->deliver($seed,'scalar')[0]['data']['result']);
		$t->same('failed',$adapter->deliver($seed,'failed')[0]['status']);
		$t->same('resource',$adapter->deliver($seed,'unsafe')[0]['data']);
		$adapter->withoutHandler('scalar');
		$t->same('queued',$adapter->deliver($seed,'scalar')[0]['status']);
		$t->same($adapter->manifest(),$adapter->jsonSerialize());
	}finally{ fclose($resource); }
})->tag('panel','notifications','adapter','deliveries','coverage')->group('panel-lane-c');

test('filesystem notifications retain bounded receipts and tolerate malformed persisted collections', static function(Context $t): void {
	$directory=$t->tempDirectory('notification-retention');
	$adapter=new PanelFilesystemNotificationAdapter($directory,[new PanelInboxNotification(['id'=>'notice','message'=>'Notice'])],['database'],[],512,50);
	foreach(range(1,51) as $_){ $adapter->deliver('notice','database'); }
	$t->same(50,count($adapter->deliveryReceipts()));

	$raw=new PanelAtomicSnapshotStore($directory,'dataphyre.panel.notifications.v1');
	$raw->transaction(static function(array &$state): void { $state['deliveries']='invalid'; },'fixture.invalid-deliveries');
	$t->same([],$adapter->deliveryReceipts());
	$raw->transaction(static function(array &$state): void { $state['notifications']='invalid'; },'fixture.invalid-notifications');
	$t->same([],$adapter->all());
})->tag('panel','notifications','adapter','retention','coverage')->group('panel-lane-c');

test('activity collaboration validates commands and reports harmless missing mutations', static function(Context $t): void {
	$store=PanelNotificationActivityStore::make($t->tempDirectory('activity-validation'));
	$t->throws(static fn()=>$store->policy(' ',static fn()=>true),InvalidArgumentException::class);
	$t->isFalse($store->unsubscribe('operator','orders.*'));
	$t->isFalse($store->muteSubscription('operator','orders.*'));
	$t->isFalse($store->unwatch('order',1,'operator'));
	$t->isFalse($store->unassign('order',1,'operator'));
	$t->isFalse($store->acknowledge('missing','operator'));
	$t->throws(static fn()=>$store->recordActivity(' ','actor','order',1),InvalidArgumentException::class);
	$t->throws(static fn()=>$store->comment('order',1,'actor',' '),InvalidArgumentException::class);
	$t->throws(static fn()=>$store->comment('order',1,'actor',str_repeat('x',100001)),LengthException::class);
	$t->throws(static fn()=>$store->watch(' ',1,'operator'),InvalidArgumentException::class);
	$t->throws(static fn()=>$store->watch('order',1,"bad\nrecipient"),InvalidArgumentException::class);
	$t->throws(static fn()=>$store->subscribe('operator',' '),InvalidArgumentException::class);
	$comment=$store->comment('order',1,'actor','Hello', ['',"bad\nrecipient",'valid']);
	$t->same(['valid'],$comment['mentions']);
})->tag('panel','notifications','activity','validation','coverage')->group('panel-lane-c');

test('activity feeds filter visibility digests preferences subscriptions and fail-closed policies', static function(Context $t): void {
	$resource=fopen('php://temp','r+');
	try{
		$store=PanelNotificationActivityStore::make($t->tempDirectory('activity-visibility'));
		$store->setPreferences('disabled',['notifications_enabled'=>false,'digest_enabled'=>false,'quiet_hours'=>null]);
		$t->isFalse($store->shouldNotify('disabled','orders.created','database'));
		$t->same(0,$store->digest('disabled',null,'2030-01-01T00:00:00Z')['count']);
		$t->isFalse($store->shouldNotify('operator','orders.created','database'));

		$store->watch('order',1,'watcher');
		$store->subscribe('subscriber','orders.*',[],'nonsense');
		$activity=$store->recordActivity('order.created','actor','order',1,['unsafe'=>$resource],[
			'topic'=>'orders.created','created_at'=>'2026-01-02T00:00:00Z',
		]);
		$t->same([],$activity['data']);
		$t->same(1,count($store->activities(['recipient'=>'watcher'])));
		$t->same(1,count($store->activities(['recipient'=>'subscriber'])));
		$t->same(0,count($store->activities(['recipient'=>'stranger'])));
		$t->same(0,count($store->activities(['type'=>'other'])));
		$t->same(0,count($store->activities(['since'=>'2026-01-03T00:00:00Z'])));
		$t->same(0,count($store->activities(['until'=>'2026-01-01T00:00:00Z'])));
		$t->same([],$store->mentions('stranger'));
		$t->same([],$store->mentions('actor'));
		$t->isTrue($store->shouldNotify('subscriber','orders.created','database'));
		$t->isFalse($store->shouldNotify('subscriber','users.created','database'));
		$t->same('orders.created',$store->subscribe('exact','orders.created')['topic']);
		$t->isTrue($store->shouldNotify('exact','orders.created','database'));
		$t->isFalse($store->shouldNotify('exact','users.created','database'));
	}finally{ fclose($resource); }
})->tag('panel','notifications','activity','visibility','coverage')->group('panel-lane-c');

test('activity retention manifests malformed collections and policy exceptions remain explicit', static function(Context $t): void {
	$directory=$t->tempDirectory('activity-retention');
	$store=new PanelNotificationActivityStore($directory,512,100);
	foreach(range(1,101) as $index){
		$store->recordActivity('fixture.event','actor','item',$index,[],['created_at'=>sprintf('2026-01-01T00:%02d:00Z',$index%60)]);
	}
	$store->assign('item',101,'assignee','actor');
	$t->same(100,count($store->activities([],1000)));
	$store->policy('activity.record',static function(): never { throw new RuntimeException('policy unavailable'); });
	$t->throws(static fn()=>$store->recordActivity('fixture.blocked','actor','item',102),DomainException::class);

	$raw=new PanelAtomicSnapshotStore($directory,'dataphyre.panel.activity.v1');
	$raw->transaction(static function(array &$state): void {
		$state['subscriptions']='invalid';
		$state['watchers']['invalid']='not-a-list';
	},'fixture.invalid-state');
	$t->same([],$store->subscriptions());
	$t->same(0,$store->manifest()['counts']['watchers']);
	$t->same($store->manifest(),$store->jsonSerialize());
})->tag('panel','notifications','activity','retention','coverage')->group('panel-lane-c');
