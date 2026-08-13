<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelDataResult;
use Dataphyre\Panel\PanelDataSource;
use Dataphyre\Panel\PanelDataSourceRegistry;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

final class DpPanelRegistrySource implements PanelDataSource {
	/** @param array<string,mixed> $capabilities */
	public function __construct(private readonly string $id,private readonly array $capabilities=['adapter'=>'probe'],private readonly bool $throw=false){}
	public function query(PanelDataQuery $query): PanelDataResult{return PanelDataResult::normalize([['id'=>$this->id]],$query,$this->id);}
	public function find(string|int $id,?PanelDataQuery $scope=null): mixed{return (string)$id===$this->id?['id'=>$this->id]:null;}
	public function capabilities(): array{if($this->throw){throw new RuntimeException('private capability failure');}return$this->capabilities;}
}

test('data-source registry preserves replacement lookup manifests and exact rollback',static function(Context $t):void{
	$first=new DpPanelRegistrySource('first',['adapter'=>'probe','search'=>true,'max_limit'=>50]);
	$second=new DpPanelRegistrySource('second',['adapter'=>'probe','search'=>false,'operators'=>['eq','contains']]);
	$registry=new PanelDataSourceRegistry();
	$t->same('reject',$registry->conflictPolicy());$t->same(0,$registry->revision());
	$t->same('panel_data_source_registry',$registry->checkpointType());
	$registry->register('Primary Source',$first);
	$t->isTrue($registry->has('primary source'));$t->same($first,$registry->get('primary_source'));
	$t->same(['primary_source'],$registry->names());$t->throws(static fn()=>$registry->register('primary_source',$second),LogicException::class);
	$registry->register('primary_source',$second,true);
	$t->same($second,$registry->get('primary source'));$t->same(2,$registry->revision());
	$manifest=$registry->manifest();$t->same('panel_data_source_registry',$manifest['type']);$t->same(1,$manifest['contract_version']);
	$t->same(1,$manifest['count']);$t->same(false,$manifest['sources']['primary_source']['capabilities']['search']);
	$t->same(DpPanelRegistrySource::class,$manifest['sources']['primary_source']['class']);
	$t->same($manifest,$registry->jsonSerialize());$t->same(64,strlen($registry->fingerprint()));
	$t->same([['name'=>'primary_source','owner'=>'application','active'=>true,'revision'=>2,'meta'=>[]]],$registry->provenance());
	$checkpoint=$registry->checkpoint();$registry->forget('primary_source');$t->isFalse($registry->has('primary source'));
	$t->throws(static fn()=>$registry->get('primary source'),OutOfBoundsException::class);
	$revision=$registry->revision();$registry->forget('missing');$t->same($revision,$registry->revision());
	$registry->restore($checkpoint);$t->same($second,$registry->get('primary_source'));$t->same(2,$registry->revision());
	$t->same('keep_first',$registry->conflictPolicyUsing('KEEP_FIRST')->conflictPolicy());$t->same(3,$registry->revision());
	$registry->conflictPolicyUsing('keep_first');$t->same(3,$registry->revision());
})->tag('panel','data-source','registry','rollback','manifest')->maxMillis(3000);

test('contributor layers implement reject keep-first replacement and reveal-on-removal',static function(Context $t):void{
	$base=new DpPanelRegistrySource('base');$a=new DpPanelRegistrySource('a');$b=new DpPanelRegistrySource('b');
	$registry=(new PanelDataSourceRegistry('replace'));
	$t->isTrue($registry->contribute('orders',$base,'plugin.base',['password'=>'hidden','channel'=>'stable']));
	$t->isTrue($registry->contribute('orders',$a,'plugin.a',['release'=>'one']));
	$t->same($a,$registry->get('orders'));$t->same(2,count($registry->provenance()));
	$t->same('[REDACTED]',$registry->provenance()[0]['meta']['password']);
	$t->isTrue($registry->contribute('orders',$b,'plugin.a',['release'=>'two']));
	$t->same($b,$registry->get('orders'));$t->same(2,count($registry->provenance()));
	$t->same(2,$registry->manifest()['sources']['orders']['layers']);
	$registry->unregisterContributor('plugin.a');$t->same($base,$registry->get('orders'));
	$revision=$registry->revision();$registry->unregisterContributor('missing');$t->same($revision,$registry->revision());
	$keep=new PanelDataSourceRegistry('keep_first');$keep->contribute('orders',$base,'base');
	$t->isFalse($keep->contribute('orders',$a,'other'));$t->same($base,$keep->get('orders'));
	$reject=new PanelDataSourceRegistry();$reject->contribute('orders',$base,'base');
	$t->throws(static fn()=>$reject->contribute('orders',$a,'other'),LogicException::class);
	$t->isTrue($reject->contribute('orders',$a,'other',[], 'replace'));$t->same($a,$reject->get('orders'));
	$reject->unregisterContributor('base');$t->same($a,$reject->get('orders'));
	$reject->unregisterContributor('other');$t->same([],$reject->names());
})->tag('panel','data-source','registry','plugins','conflicts')->maxMillis(3000);

test('registry rejects hostile capabilities provenance configuration and checkpoints',static function(Context $t):void{
	$valid=new DpPanelRegistrySource('valid');
	$t->throws(static fn()=>new PanelDataSourceRegistry('unknown'),InvalidArgumentException::class);
	$t->throws(static fn()=>(new PanelDataSourceRegistry())->conflictPolicyUsing('unknown'),InvalidArgumentException::class);
	$t->throws(static fn()=>(new PanelDataSourceRegistry())->register('   ',$valid),InvalidArgumentException::class);
	$t->throws(static fn()=>(new PanelDataSourceRegistry())->contribute('x',$valid,'   '),InvalidArgumentException::class);
	$t->throws(static fn()=>(new PanelDataSourceRegistry())->contribute('x',$valid,str_repeat('a',101)),InvalidArgumentException::class);
	$t->throws(static fn()=>(new PanelDataSourceRegistry())->contribute('x',$valid,'owner',['not','a','map']),InvalidArgumentException::class);
	$t->throws(static fn()=>(new PanelDataSourceRegistry())->contribute('x',$valid,'owner',['callback'=>static fn()=>true]),InvalidArgumentException::class);
	$t->throws(static fn()=>(new PanelDataSourceRegistry())->contribute('x',$valid,'owner',['large'=>str_repeat('x',9000)]),LengthException::class);

	$cases=[
		new DpPanelRegistrySource('throw',[],true),
		new DpPanelRegistrySource('list',['value']),
		new DpPanelRegistrySource('name',['Bad Name'=>true]),
		new DpPanelRegistrySource('assoc-list',['operators'=>['one'=>'eq']]),
		new DpPanelRegistrySource('large-list',['operators'=>array_fill(0,129,'eq')]),
		new DpPanelRegistrySource('bad-list',['operators'=>['eq',7]]),
		new DpPanelRegistrySource('long-list',['operators'=>[str_repeat('x',257)]]),
		new DpPanelRegistrySource('bad-value',['value'=>new stdClass()]),
		new DpPanelRegistrySource('large-int',['max_limit'=>1000000001]),
		new DpPanelRegistrySource('long-string',['adapter'=>str_repeat('x',513)]),
		new DpPanelRegistrySource('control',['adapter'=>"bad\nvalue"]),
		new DpPanelRegistrySource('secret',['api_token'=>'do-not-publish']),
	];
	foreach($cases as$index=>$source){$t->throws(static fn()=>(new PanelDataSourceRegistry())->register('source_'.$index,$source),UnexpectedValueException::class);}
	$tooMany=[];for($i=0;$i<129;$i++){$tooMany['cap_'.$i]=true;}
	$t->throws(static fn()=>(new PanelDataSourceRegistry())->register('too_many',new DpPanelRegistrySource('many',$tooMany)),UnexpectedValueException::class);
	$tooLarge=[];for($i=0;$i<100;$i++){$tooLarge['cap_'.$i]=str_repeat('x',400);}
	$t->throws(static fn()=>(new PanelDataSourceRegistry())->register('too_large',new DpPanelRegistrySource('large',$tooLarge)),UnexpectedValueException::class);
	$booleanSecret=new PanelDataSourceRegistry();$booleanSecret->register('safe',new DpPanelRegistrySource('safe',['api_token'=>false]));
	$t->same(false,$booleanSecret->manifest()['sources']['safe']['capabilities']['api_token']);
	$network=new PanelDataSourceRegistry();$network->contribute('remote',new DpPanelRegistrySource('remote',['endpoint_url'=>'https://private.example.test','cancellable_transport'=>true]),'plugin.remote',['headers'=>['X-Private'=>'no'],'transport'=>'PrivateTransport'],'replace');
	$networkManifest=$network->manifest();$t->same('[REDACTED]',$networkManifest['sources']['remote']['capabilities']['endpoint_url']);$t->isTrue($networkManifest['sources']['remote']['capabilities']['cancellable_transport']);$t->same('[REDACTED]',$networkManifest['sources']['remote']['meta']['headers']);$t->same('[REDACTED]',$networkManifest['sources']['remote']['meta']['transport']);$t->same('[REDACTED]',$network->provenance()[0]['meta']['transport']);
	$otherNetwork=new PanelDataSourceRegistry();$otherNetwork->contribute('remote',new DpPanelRegistrySource('remote',['endpoint_url'=>'https://different.example.test','cancellable_transport'=>true]),'plugin.remote',['headers'=>['X-Private'=>'different'],'transport'=>'OtherTransport'],'replace');$t->same($network->fingerprint(),$otherNetwork->fingerprint());

	$registry=(new PanelDataSourceRegistry())->register('valid',$valid);$checkpoint=$registry->checkpoint();
	$t->throws(static fn()=>$registry->restore([]),InvalidArgumentException::class);
	$t->throws(static fn()=>$registry->restore(['layers'=>[],'revision'=>-1,'conflict_policy'=>'reject']),InvalidArgumentException::class);
	$t->throws(static fn()=>$registry->restore(['layers'=>[],'revision'=>0,'conflict_policy'=>'bad']),InvalidArgumentException::class);
	$t->throws(static fn()=>$registry->restore(['layers'=>['Bad Name'=>[]],'revision'=>0,'conflict_policy'=>'reject']),InvalidArgumentException::class);
	$bad=$checkpoint;$bad['layers']['valid']=[['source'=>new stdClass(),'owner'=>'x','capabilities'=>[],'meta'=>[],'revision'=>1]];
	$t->throws(static fn()=>$registry->restore($bad),InvalidArgumentException::class);
	$untrusted=$checkpoint;$untrusted['layers']['valid'][0]['source']=new DpPanelRegistrySource('untrusted');
	$t->throws(static fn()=>$registry->restore($untrusted),InvalidArgumentException::class);
	$badOwner=$checkpoint;$badOwner['layers']['valid'][0]['owner']='Bad Owner';
	$t->throws(static fn()=>$registry->restore($badOwner),InvalidArgumentException::class);
	$duplicateOwner=$checkpoint;$duplicateOwner['layers']['valid'][]=$duplicateOwner['layers']['valid'][0];
	$t->throws(static fn()=>$registry->restore($duplicateOwner),InvalidArgumentException::class);
	$badCapabilities=$checkpoint;$badCapabilities['layers']['valid'][0]['capabilities']=['api_token'=>'private'];
	$t->throws(static fn()=>$registry->restore($badCapabilities),InvalidArgumentException::class);
	$badMeta=$checkpoint;$badMeta['layers']['valid'][0]['meta']=['password'=>'private'];
	$t->throws(static fn()=>$registry->restore($badMeta),InvalidArgumentException::class);
	$badRevision=$checkpoint;$badRevision['layers']['valid'][0]['revision']=2;
	$t->throws(static fn()=>$registry->restore($badRevision),InvalidArgumentException::class);
	$badRecordOrder=$checkpoint;$badRecordOrder['layers']['valid'][0]=array_reverse($badRecordOrder['layers']['valid'][0],true);
	$t->throws(static fn()=>$registry->restore($badRecordOrder),InvalidArgumentException::class);
	$tooDeep=$checkpoint;$tooDeep['revision']=100;$tooDeep['layers']['valid']=array_fill(0,65,$checkpoint['layers']['valid'][0]);
	$t->throws(static fn()=>$registry->restore($tooDeep),InvalidArgumentException::class);
	$oversized=[];for($i=0;$i<513;$i++){$oversized['s_'.$i]=$checkpoint['layers']['valid'];}
	$t->throws(static fn()=>$registry->restore(['layers'=>$oversized,'revision'=>1,'conflict_policy'=>'reject']),InvalidArgumentException::class);
})->tag('panel','data-source','registry','security','adversarial')->maxMillis(5000);

test('registry enforces its source budget without leaking adapter execution into manifests',static function(Context $t):void{
	$source=new DpPanelRegistrySource('budget',['adapter'=>'probe']);$registry=new PanelDataSourceRegistry();
	for($i=0;$i<512;$i++){$registry->register('source_'.$i,$source);}
	$t->same(512,count($registry->names()));$t->throws(static fn()=>$registry->register('overflow',$source),LengthException::class);
	$t->throws(static fn()=>$registry->contribute('overflow',$source,'plugin'),LengthException::class);
	$layers=new PanelDataSourceRegistry('replace');
	for($i=0;$i<64;$i++){$layers->contribute('layered',$source,'plugin_'.$i);}
	$t->throws(static fn()=>$layers->contribute('layered',$source,'plugin_overflow'),LengthException::class);
	$manifest=$registry->manifest();$t->same(512,$manifest['count']);
	$t->isFalse($manifest['capabilities']['live_adapter_code_run_by_manifest']);
})->tag('panel','data-source','registry','budgets')->maxMillis(5000);
