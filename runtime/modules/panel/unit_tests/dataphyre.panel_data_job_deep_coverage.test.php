<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelDataJob;
use Dataphyre\Panel\PanelDataJobResult;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel data job normalizes fluent plans aliases queue intent and serialization',static function(Context $t): void {
	$job=PanelDataJob::make(' ',' ');
	$generated=$job->id();
	$t->contains('operation_',$generated);
	$t->same($job,$job->id(' '));
	$t->same($generated,$job->id());
	$t->same($job,$job->id('Daily Sync'));
	$t->same('daily_sync',$job->id());
	$resource=Resource::make('orders');
	$job->resource($resource)->rows(['first'=>['id'=>1],'second'=>['id'=>2]])->chunkSize(0)->queue(true)
		->handle(static fn(mixed $item): mixed=>$item)->metadata(['source'=>'deep'])->artifact(' ','abc','text/custom',['one'=>1]);
	$plan=$job->plan();
	$t->same('operation',$plan['type']);
	$t->same('operation',$plan['name']);
	$t->same('orders',$plan['resource']);
	$t->same(2,$plan['total']);
	$t->same(1,$plan['chunk_size']);
	$t->same(2,$plan['chunks']);
	$t->same('default',$plan['queue']);
	$t->isTrue($plan['handler']);
	$t->isFalse($plan['mapper']);
	$t->same($plan,$job->jsonSerialize());
	$job->records([1,2,3])->chunkSize(20000)->queue(false);
	$t->same(10000,$job->plan()['chunk_size']);
	$t->isFalse($job->plan()['queueable']);
	$job->queue(null);
	$t->same(null,$job->plan()['queue']);
	$job->queue(' ');
	$t->same('default',$job->plan()['queue']);
	$t->instanceOf(PanelDataJob::class,PanelDataJob::import());
	$t->instanceOf(PanelDataJob::class,PanelDataJob::export());
})->tag('panel','data-job','coverage')->group('framework-coverage');

test('panel data job runs mapped chunks progress artifacts and partial failures',static function(Context $t): void {
	$progress=[];
	$large=array_combine(range(0,12),range(0,12));
	$job=PanelDataJob::export('customer export')->resource(Resource::make('customers'))->items([
		['id'=>1,'name'=>'Ada'],
		$large,
		new stdClass(),
	])->chunkSize(2)->queue('critical')->progress(static function(array $event,PanelDataJob $job)use(&$progress): void {
		$progress[]=$event['data']['processed'];
	})->map(static function(mixed $item,int $offset): array {
		if($offset>0){
			throw new RuntimeException('Cannot map item '.$offset);
		}
		return ['id'=>$item['id'],'name'=>strtoupper($item['name'])];
	})->artifact('seed.txt','seed')->metadata(['batch'=>'nightly']);
	$result=$job->run();
	$t->instanceOf(PanelDataJobResult::class,$result);
	$t->same('completed_with_failures',$result->status());
	$t->same(3,$result->processed());
	$t->same(1,$result->succeeded());
	$t->same(2,$result->failed());
	$t->same([2,3],$progress);
	$t->same(2,count($result->failures()));
	$t->same('array',$result->failures()[0]['item']['type']);
	$t->same('object',$result->failures()[1]['item']['type']);
	$t->same(stdClass::class,$result->failures()[1]['item']['class']);
	$t->same(3,count($result->artifacts()));
	$t->same('application/json',$result->artifacts()[1]['mime']);
	$t->same('text/csv',$result->artifacts()[2]['mime']);
	$t->same(4,count($result->events()));
	$t->same('critical',$result->metadata()['queue']);
	$t->isTrue($result->ok());
})->tag('panel','data-job','coverage')->group('framework-coverage');

test('panel data job covers handlers identity empty and fully failed runs plus private normalizers',static function(Context $t): void {
	$handled=[];
	$success=PanelDataJob::import('handler')->items(['a','b'])->handle(static function(string $item,int $offset,?Resource $resource,PanelDataJob $job)use(&$handled): string {
		$handled[]=$item.$offset;
		return 'ignored';
	})->run();
	$t->same('completed',$success->status());
	$t->same(['a0','b1'],$handled);
	$t->isTrue($success->ok());
	$identity=PanelDataJob::make('identity')->items([1])->run();
	$t->same(1,$identity->succeeded());
	$empty=PanelDataJob::make('empty')->run();
	$t->same('completed',$empty->status());
	$t->same([],$empty->events());
	$failed=PanelDataJob::import('failed')->items([1,2])->handle(static function(): void { throw new RuntimeException('Nope'); })->run();
	$t->same('failed',$failed->status());
	$t->same(0,$failed->succeeded());

	$t->same('event',$t->nonPublic(PanelDataJob::class)->invoke('event',' ',['one'=>1])['event']);
	$t->same(['offset'=>4,'message'=>'Bad','item'=>'value'],$t->nonPublic(PanelDataJob::class)->invoke('failure',4,'value','Bad'));
	$csv=$t->nonPublic(PanelDataJob::class)->invoke('failureCsv',[['offset'=>1,'message'=>'Bad','item'=>['id'=>1]]]);
	$t->contains('offset,message,item',$csv);
	$t->contains('Bad',$csv);
	$summaries=$t->nonPublic(PanelDataJob::class)->invoke('artifactSummaries',[
		['name'=>'one','mime'=>'text/plain','contents'=>'abc','metadata'=>'invalid'],
		[],
	]);
	$t->same(3,$summaries[0]['bytes']);
	$t->same([],$summaries[0]['metadata']);
	$t->same('artifact',$summaries[1]['name']);
	$t->same(['small'=>1],$t->nonPublic(PanelDataJob::class)->invoke('compactValue',['small'=>1]));
	$t->same(5,$t->nonPublic(PanelDataJob::class)->invoke('compactValue',5));
})->tag('panel','data-job','coverage')->group('framework-coverage');

test('panel data job returns an empty failure csv when the php stream wrapper is unavailable',static function(Context $t): void {
	set_error_handler(static fn(): bool=>true);
	stream_wrapper_unregister('php');
	try{
		$t->same('',$t->nonPublic(PanelDataJob::class)->invoke('failureCsv',[['offset'=>1]]));
	}finally{
		stream_wrapper_restore('php');
		restore_error_handler();
	}
})->tag('panel','data-job','coverage')->group('framework-coverage');
