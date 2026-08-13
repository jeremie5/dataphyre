<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelArrayDataSource;
use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceRunner;
use Dataphyre\Panel\PanelDataMutation;
use Dataphyre\Panel\PanelDataMutationAccessDenied;
use Dataphyre\Panel\PanelDataMutationBatch;
use Dataphyre\Panel\PanelDataMutationBatchResult;
use Dataphyre\Panel\PanelDataMutationCapabilities;
use Dataphyre\Panel\PanelDataMutationConflict;
use Dataphyre\Panel\PanelDataMutationException;
use Dataphyre\Panel\PanelDataMutationReceipt;
use Dataphyre\Panel\PanelDataMutationUnsupported;
use Dataphyre\Panel\PanelDataSourceRegistry;
use Dataphyre\Panel\PanelMutableDataSource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

/** @return array<string,mixed> */
function dp_panel_mutation_options(string $idempotency,int $expected=-1,?string $tenant='north',string $actor='operator'):array{
	$options=['idempotency_key'=>$idempotency,'actor_id'=>$actor,'authorization'=>['allow'=>true],'metadata'=>['surface'=>'orders'],'reason'=>'Operator edit.'];
	if($tenant!==null){$options['tenant']=$tenant;}
	if($expected>=0){$options['expected_revision']=$expected;}
	return$options;
}

test('universal mutation envelopes are scope bound bounded and safe to inspect',static function(Context $t):void{
	$create=PanelDataMutation::create('order-1',['name'=>'Ada','password'=>'legitimate-domain-value'],dp_panel_mutation_options('create-order-1'));
	$t->same('create',$create->operation());$t->same('order-1',$create->key());$t->same('Ada',$create->values()['name']);$t->same('operator',$create->actorId());$t->same('north',$create->tenantKey());$t->same(['allow'=>true],$create->authorizationMetadata());$t->same(64,strlen($create->fingerprint()));$t->same(64,strlen($create->idempotencyDigest()));$t->isTrue($create->returnsRecord());
	$public=json_encode($create,JSON_THROW_ON_ERROR);$trusted=$create->trustedEnvelope();
	$t->isFalse(str_contains($public,'create-order-1'));$t->isFalse(str_contains($public,'legitimate-domain-value'));$t->same('create-order-1',$trusted['idempotency_key']);$t->same('legitimate-domain-value',$trusted['values']['password']);$t->same(['name','password'],$create->jsonSerialize()['value_fields']);
	$update=PanelDataMutation::update(7,['name'=>'Grace'],dp_panel_mutation_options('update-order-7',3));$t->same(3,$update->expectedRevision());
	$delete=PanelDataMutation::delete(7,dp_panel_mutation_options('delete-order-7',4));$t->same([],$delete->values());
	$upsert=PanelDataMutation::upsert(8,['name'=>'Lin'],dp_panel_mutation_options('upsert-order-8'));$t->same('upsert',$upsert->operation());

	$t->throws(static fn()=>PanelDataMutation::make('invalid','x',['name'=>'x'],dp_panel_mutation_options('invalid-operation')),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataMutation::make('delete','x',['name'=>'x'],dp_panel_mutation_options('delete-values')),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataMutation::create('x',[],dp_panel_mutation_options('empty-create')),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataMutation::create('x',['name'=>'x'],['actor_id'=>'actor','idempotency_key'=>'short']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataMutation::create('x',['name'=>'x'],['idempotency_key'=>'missing-actor']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataMutation::create('x',['bad field'=>'x'],dp_panel_mutation_options('bad-value-field')),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataMutation::create('x',['name'=>'x'],array_replace(dp_panel_mutation_options('secret-metadata'),['metadata'=>['api_token'=>'no']])),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataMutation::update('x',['name'=>'x'],dp_panel_mutation_options('missing-revision')),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataMutation::delete('x',dp_panel_mutation_options('delete-missing-revision')),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataMutation::create('x',['name'=>'x'],dp_panel_mutation_options('create-with-revision',1)),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataMutation::create('x',['name'=>'x'],dp_panel_mutation_options('unknown-option')+['unknown'=>true]),InvalidArgumentException::class);
})->tag('panel','data','mutation','envelope','security')->maxMillis(3000);

test('array adapter performs tenant-safe optimistic idempotent mutation lifecycles',static function(Context $t):void{
	$source=new PanelArrayDataSource([],['name'=>'orders','mutation_authorize'=>static fn(PanelDataMutation $mutation):bool=>($mutation->authorizationMetadata()['allow']??false)===true,'clock'=>static fn():string=>'2026-07-16T12:00:00Z']);
	$t->instanceOf(PanelMutableDataSource::class,$source);$caps=PanelDataMutationCapabilities::fromArray($source->capabilities());$t->isTrue($caps->enabled());$t->same(PanelDataMutation::OPERATIONS,$caps->operations());
	$create=PanelDataMutation::create('order-1',['name'=>'Ada','state'=>'draft'],dp_panel_mutation_options('create-order-one'));
	$created=$source->mutate($create);$t->same('created',$created->outcome());$t->same(1,$created->revision());$t->same('Ada',$created->record()['name']);$t->same(1,$source->version('order-1'));$t->same(1,$source->sequence());$t->isFalse($created->replayed());
	$replay=$source->mutate($create);$t->isTrue($replay->replayed());$t->same($created->receiptId(),$replay->receiptId());$t->same(1,$source->sequence());
	$t->throws(static fn()=>$source->mutate(PanelDataMutation::create('order-1',['name'=>'Different'],dp_panel_mutation_options('create-order-one'))),PanelDataMutationConflict::class);

	$update=PanelDataMutation::update('order-1',['state'=>'review'],dp_panel_mutation_options('update-order-one',1));$updated=$source->mutate($update);
	$t->same('updated',$updated->outcome());$t->same(2,$updated->revision());$t->same(['state'],$updated->changedFields());$t->same('review',$updated->record()['state']);
	$unchanged=$source->mutate(PanelDataMutation::update('order-1',['state'=>'review'],dp_panel_mutation_options('unchanged-order-one',2)));
	$t->same('unchanged',$unchanged->outcome());$t->same(2,$unchanged->revision());$t->same(2,$source->sequence());
	$t->throws(static fn()=>$source->mutate(PanelDataMutation::update('order-1',['state'=>'paid'],dp_panel_mutation_options('stale-order-one',1))),PanelDataMutationConflict::class);
	$t->throws(static fn()=>$source->mutate(PanelDataMutation::update('order-1',['tenant_id'=>'south'],dp_panel_mutation_options('tenant-value-mismatch',2))),PanelDataMutationAccessDenied::class);
	$t->throws(static fn()=>$source->mutate(PanelDataMutation::update('order-1',['state'=>'paid'],dp_panel_mutation_options('tenant-scope-mismatch',2,'south'))),PanelDataMutationAccessDenied::class);
	$t->throws(static fn()=>$source->mutate(PanelDataMutation::update('missing',['state'=>'paid'],dp_panel_mutation_options('missing-order-row',1))),PanelDataMutationException::class);

	$upsert=$source->mutate(PanelDataMutation::upsert('order-1',['state'=>'paid'],dp_panel_mutation_options('upsert-order-one',2)));$t->same('updated',$upsert->outcome());$t->same(3,$upsert->revision());
	$t->throws(static fn()=>$source->mutate(PanelDataMutation::upsert('order-1',['state'=>'closed'],dp_panel_mutation_options('blind-upsert-existing'))),PanelDataMutationConflict::class);
	$new=$source->mutate(PanelDataMutation::upsert('order-2',['name'=>'Grace'],dp_panel_mutation_options('upsert-order-two')+['return_record'=>false]));$t->same('created',$new->outcome());$t->same(null,$new->record());
	$deleted=$source->mutate(PanelDataMutation::delete('order-1',dp_panel_mutation_options('delete-order-one',3)));$t->same('deleted',$deleted->outcome());$t->same(4,$deleted->revision());$t->same(null,$source->version('order-1'));$t->same(5,$source->sequence());
	$t->same(['insert','update','update','insert','delete'],array_map(static fn($change):string=>$change->operation(),$source->changes()));
	$t->isFalse(str_contains(json_encode($deleted,JSON_THROW_ON_ERROR),'delete-order-one'));
})->tag('panel','data','mutation','array','lifecycle','idempotency')->maxMillis(3000);

test('mutation batches roll back atomically and expose explicit non-atomic behavior',static function(Context $t):void{
	$source=new PanelArrayDataSource([['id'=>'existing','name'=>'Existing']],['tenant_field'=>null,'mutation_authorize'=>static fn():bool=>true,'clock'=>static fn():string=>'2026-07-16T12:00:00Z']);
	$first=PanelDataMutation::create('first',['name'=>'First'],dp_panel_mutation_options('batch-create-first',-1,null,'operator'));
	$conflict=PanelDataMutation::create('existing',['name'=>'Conflict'],dp_panel_mutation_options('batch-create-conflict',-1,null,'operator'));
	$atomic=new PanelDataMutationBatch([$first,$conflict]);$t->isTrue($atomic->atomic());$t->same(2,$atomic->count());$t->same(null,$atomic->tenantKey());$t->same('operator',$atomic->actorId());$t->same(64,strlen($atomic->fingerprint()));$t->same('panel_data_mutation_batch_manifest',$atomic->jsonSerialize()['type']);
	$t->throws(static fn()=>$source->mutateBatch($atomic),PanelDataMutationConflict::class);$t->same(null,$source->find('first'));$t->same(0,$source->sequence());
	$nonAtomic=new PanelDataMutationBatch([$first,$conflict],false);$t->throws(static fn()=>$source->mutateBatch($nonAtomic),PanelDataMutationConflict::class);$t->same('First',$source->find('first')['name']);$t->same(1,$source->sequence());
	$second=PanelDataMutation::create('second',['name'=>'Second'],dp_panel_mutation_options('batch-create-second',-1,null));
	$third=PanelDataMutation::create('third',['name'=>'Third'],dp_panel_mutation_options('batch-create-third',-1,null));
	$batch=new PanelDataMutationBatch([$second,$third]);$result=$source->mutateBatch($batch);$t->instanceOf(PanelDataMutationBatchResult::class,$result);$t->same($batch,$result->batch());$t->same(2,count($result->receipts()));$t->same('array',$result->source());$t->same(2,$result->count());$t->isFalse($result->replayed());$t->isTrue($source->mutateBatch($batch)->replayed());
	$t->throws(static fn()=>new PanelDataMutationBatch([]),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelDataMutationBatch([$second,$second]),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelDataMutationBatch([$second,PanelDataMutation::create('fourth',['name'=>'Fourth'],dp_panel_mutation_options('batch-create-fourth',-1,null,'other'))]),InvalidArgumentException::class);
})->tag('panel','data','mutation','batch','atomicity')->maxMillis(3000);

test('registry dispatch and authorization remain fail closed',static function(Context $t):void{
	$readOnly=new PanelArrayDataSource([],['tenant_field'=>null]);$registry=(new PanelDataSourceRegistry())->register('read_only',$readOnly);
	$mutation=PanelDataMutation::create('one',['name'=>'One'],dp_panel_mutation_options('registry-create-one',-1,null));
	$t->throws(static fn()=>$registry->mutable('read_only'),PanelDataMutationUnsupported::class);$t->throws(static fn()=>$registry->mutate('read_only',$mutation),PanelDataMutationUnsupported::class);
	$denied=new PanelArrayDataSource([],['tenant_field'=>null,'mutation_authorize'=>static fn():bool=>false]);$registry->register('denied',$denied);
	$t->throws(static fn()=>$registry->mutate('denied',$mutation),PanelDataMutationAccessDenied::class);
	$broken=new PanelArrayDataSource([],['tenant_field'=>null,'mutation_authorize'=>static function():bool{throw new RuntimeException('private authorization detail');}]);$registry->register('broken',$broken);
	try{$registry->mutate('broken',$mutation);$t->fail('Broken authorizer was accepted.');}catch(PanelDataMutationAccessDenied $error){$t->same('mutation_authorization_failed',$error->publicCode());$t->isFalse(str_contains($error->getMessage(),'private authorization detail'));}
	$writable=new PanelArrayDataSource([],['tenant_field'=>null,'mutation_authorize'=>static fn():bool=>true,'clock'=>static fn():string=>'2026-07-16T12:00:00Z']);$registry->register('writable',$writable);
	$t->same('created',$registry->mutate('writable',$mutation)->outcome());$t->same($writable,$registry->mutable('writable'));$t->isTrue($registry->manifest()['capabilities']['typed_mutation_dispatch']);
})->tag('panel','data','mutation','registry','authorization')->maxMillis(3000);

test('mutation value objects reject malformed capabilities receipts and failures',static function(Context $t):void{
	$mutation=PanelDataMutation::create('one',['name'=>'One'],dp_panel_mutation_options('value-object-one'));
	$receipt=new PanelDataMutationReceipt('orders','create','one','created',1,$mutation->fingerprint(),$mutation->idempotencyDigest(),'2026-07-16T12:00:00Z',['id'=>'one'],['name'],['api_token'=>'redacted']);
	$t->same('orders',$receipt->source());$t->same('one',$receipt->key());$t->same('corr_',substr($receipt->correlationId(),0,5));$t->same('[REDACTED]',$receipt->metadata()['api_token']);$replay=$receipt->asReplay();$t->isTrue($replay->replayed());$t->same($replay,$replay->asReplay());
	$t->throws(static fn()=>new PanelDataMutationReceipt('Bad source','create','one','created',1,$mutation->fingerprint(),$mutation->idempotencyDigest(),'now'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelDataMutationReceipt('orders','invalid','one','created',1,$mutation->fingerprint(),$mutation->idempotencyDigest(),'now'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelDataMutationReceipt('orders','create','one','created',0,$mutation->fingerprint(),$mutation->idempotencyDigest(),'now'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelDataMutationReceipt('orders','create','one','created',1,'bad',$mutation->idempotencyDigest(),'now'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelDataMutationReceipt('orders','create','one','created',1,$mutation->fingerprint(),$mutation->idempotencyDigest(),'not-an-instant'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataMutationReceipt::fromArray([]),UnexpectedValueException::class);$invalidStored=$receipt->jsonSerialize();$invalidStored['occurred_at']='not-an-instant';$t->throws(static fn()=>PanelDataMutationReceipt::fromArray($invalidStored),UnexpectedValueException::class);
	$t->throws(static fn()=>new PanelDataMutationBatchResult(new PanelDataMutationBatch([$mutation]),[],'orders'),InvalidArgumentException::class);

	$unsupported=new PanelDataMutationUnsupported(['mutation_batch']);$t->same(['mutation_batch'],$unsupported->features());$t->same(['mutation_batch'],$unsupported->jsonSerialize()['features']);
	$error=new PanelDataMutationException('mutation_failed','Mutation failed.',503,true);$t->same(503,$error->httpStatus());$t->isTrue($error->retryable());$t->same('mutation_failed',$error->jsonSerialize()['code']);
	$t->throws(static fn()=>new PanelDataMutationException('Bad','bad'),InvalidArgumentException::class);$t->throws(static fn()=>new PanelDataMutationException('mutation_bad','bad',200),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataMutationCapabilities::fromArray(['adapter'=>'array','mutations'=>true,'mutation_operations'=>[],'mutation_idempotency'=>true,'mutation_idempotency_scope'=>'process']),UnexpectedValueException::class);
	$t->throws(static fn()=>PanelDataMutationCapabilities::fromArray(['adapter'=>'array','mutations'=>false,'mutation_operations'=>[],'mutation_batch'=>false,'mutation_atomic_batch'=>true]),UnexpectedValueException::class);
})->tag('panel','data','mutation','values','adversarial')->maxMillis(3000);

test('mutable adapter conformance certifies negotiation replay and atomic batches',static function(Context $t):void{
	$source=new PanelArrayDataSource([],['tenant_field'=>null,'mutation_authorize'=>static fn():bool=>true,'clock'=>static fn():string=>'2026-07-16T12:00:00Z']);
	$mutation=PanelDataMutation::create('conformance-one',['name'=>'One'],dp_panel_mutation_options('conformance-create-one',-1,null));
	$batch=new PanelDataMutationBatch([
		PanelDataMutation::create('conformance-two',['name'=>'Two'],dp_panel_mutation_options('conformance-create-two',-1,null)),
		PanelDataMutation::create('conformance-three',['name'=>'Three'],dp_panel_mutation_options('conformance-create-three',-1,null)),
	]);
	$report=(new PanelAdapterConformanceRunner())->run(PanelAdapterConformanceCatalog::mutableDataSource(),$source,['allow_destructive'=>true,'mutation'=>$mutation,'batch'=>$batch]);
	$t->isTrue($report->passed());$t->same(['total'=>3,'passed'=>3,'failed'=>0,'skipped'=>0,'assertions'=>10],array_diff_key($report->summary(),['duration_ms'=>true]));$t->notContains('conformance-create-one',json_encode($report,JSON_THROW_ON_ERROR));
})->tag('panel','data','mutation','conformance')->maxMillis(5000);
