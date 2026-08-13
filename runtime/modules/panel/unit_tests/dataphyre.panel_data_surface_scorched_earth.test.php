<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelArrayDataSource;
use Dataphyre\Panel\PanelCallbackDataSource;
use Dataphyre\Panel\PanelDataPage;
use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelDataResult;
use Dataphyre\Panel\PanelDataSource;
use Dataphyre\Panel\PanelDataSourceRegistry;
use Dataphyre\Panel\PanelDataSurfaceContext;
use Dataphyre\Panel\PanelDataSurfaceDefinition;
use Dataphyre\Panel\PanelDataSurfaceEndpoint;
use Dataphyre\Panel\PanelDataSurfaceException;
use Dataphyre\Panel\PanelDataSurfaceGuard;
use Dataphyre\Panel\PanelDataSurfaceIntentSigner;
use Dataphyre\Panel\PanelDataSurfaceProjection;
use Dataphyre\Panel\PanelDataSurfaceRange;
use Dataphyre\Panel\PanelDataSurfaceRegistry;
use Dataphyre\Panel\PanelDataSurfaceRenderer;
use Dataphyre\Panel\PanelDataSurfaceType;
use Dataphyre\Panel\PanelDataSurfaceWindowRequest;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelSecurityContext;
use Dataphyre\Panel\ResourceTable;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return list<array<string,mixed>> */
function dp_panel_surface_rows(int $count=30): array {
	$rows=[];
	for($index=1;$index<=$count;$index++){
		$rows[]=[
			'id'=>$index,'tenant_id'=>'north','name'=>'Order '.$index,'description'=>'Description '.$index,
			'status'=>$index%2===0?'paid':'review','occurred_at'=>'2026-07-'.str_pad((string)(($index%28)+1),2,'0',STR_PAD_LEFT).'T12:00:00Z',
			'image'=>'/media/order-'.$index.'.jpg','alt'=>'Order '.$index.' image','parent_id'=>$index===1?null:(int)floor($index/2),
			'source'=>'node-'.max(1,$index-1),'target'=>'node-'.$index,'region'=>$index%3===0?'west':'east','amount'=>$index*10.5,
			'start_at'=>'2026-07-'.str_pad((string)(($index%20)+1),2,'0',STR_PAD_LEFT).'T09:00:00Z','end_at'=>'2026-07-'.str_pad((string)(($index%20)+2),2,'0',STR_PAD_LEFT).'T17:00:00Z','progress'=>($index*7)%101,
			'latitude'=>45.0+($index%10)/10,'longitude'=>-73.0-($index%10)/10,'x'=>$index%5,'y'=>(int)floor(($index-1)/5),'width'=>1,'height'=>1,
		];
	}
	return $rows;
}

/** @return array{registry:PanelDataSurfaceRegistry,definition:PanelDataSurfaceDefinition,context:PanelDataSurfaceContext,signer:PanelDataSurfaceIntentSigner,source:PanelDataSourceRegistry} */
function dp_panel_surface_fixture(PanelDataSurfaceType|string $type='table', array $options=[]): array {
	$sourceRegistry=(new PanelDataSourceRegistry())->register('orders',new PanelArrayDataSource(dp_panel_surface_rows((int)($options['count']??30)),['name'=>'orders']));
	$signer=new PanelDataSurfaceIntentSigner(['previous'=>str_repeat('p',32),'active'=>str_repeat('a',32)],'active',static fn(): int=>(int)($options['now']??1000));
	$projection=PanelDataSurfaceProjection::make(
		['id','name','description','status','occurred_at','image','alt','parent_id','source','target','region','amount','start_at','end_at','progress','latitude','longitude','x','y','width','height'],'id',
		['title'=>'name','description'=>'description','badge'=>'status','time'=>'occurred_at','image'=>'image','alt'=>'alt','parent'=>'parent_id','source'=>'source','target'=>'target','row'=>'region','column'=>'status','value'=>'amount','start'=>'start_at','end'=>'end_at','progress'=>'progress','latitude'=>'latitude','longitude'=>'longitude','x'=>'x','y'=>'y','width'=>'width','height'=>'height','cross_filter'=>'status'],
		['id'=>'ID','name'=>'Order','status'=>'Status']
	);
	$definitionOptions=['title'=>'Orders','description'=>'Signed bounded records','empty_message'=>'No orders.','endpoint'=>'/panel/data-surface','estimated_item_size'=>48];
	if(array_key_exists('canvas',$options)){$definitionOptions['canvas']=$options['canvas'];}
	$definition=PanelDataSurfaceDefinition::make(
		'orders_surface','orders','orders',$type,$projection,
		$options['range']??PanelDataSurfaceRange::make(0,5,0,2),$options['resolver']??null,
		$definitionOptions
	);
	$authorize=$options['authorize']??static fn(array $envelope,PanelDataSurfaceContext $context): bool=>$context->principal()==='operator-7';
	$registry=(new PanelDataSurfaceRegistry($sourceRegistry,$signer,$authorize))->register($definition);
	$context=PanelDataSurfaceContext::fromTrusted('operations',['tenant_id'=>'north','principal_id'=>'operator-7','correlation_id'=>'corr-1']);
	return ['registry'=>$registry,'definition'=>$definition,'context'=>$context,'signer'=>$signer,'source'=>$sourceRegistry];
}

/** @param callable(array<string,mixed>,array<string,mixed>):array{array<string,mixed>,array<string,mixed>} $mutate */
function dp_panel_surface_resign(string $token, string $secret, callable $mutate): string {
	[$head,$body]=array_slice(explode('.',$token),0,2);
	$header=json_decode((string)PanelDataSurfaceGuard::decode($head),true,8,JSON_THROW_ON_ERROR);
	$payload=json_decode((string)PanelDataSurfaceGuard::decode($body),true,32,JSON_THROW_ON_ERROR);
	[$header,$payload]=$mutate($header,$payload);
	$input=PanelDataSurfaceGuard::encode(PanelDataSurfaceGuard::canonicalJson($header)).'.'.PanelDataSurfaceGuard::encode(PanelDataSurfaceGuard::canonicalJson($payload));
	return $input.'.'.PanelDataSurfaceGuard::encode(hash_hmac('sha256',$input,$secret,true));
}

/** Signs deliberately malformed JSON segments for parser-boundary tests. */
function dp_panel_surface_sign_raw(string $headerJson, string $payloadJson, string $secret): string {
	$head=PanelDataSurfaceGuard::encode($headerJson);
	$body=PanelDataSurfaceGuard::encode($payloadJson);
	$input=$head.'.'.$body;
	return $input.'.'.PanelDataSurfaceGuard::encode(hash_hmac('sha256',$input,$secret,true));
}

test('data surface types ranges guards and projections are closed bounded contracts',static function(Context $t): void {
	$t->same(['table','list','cards','timeline','calendar','gallery','spreadsheet','pivot','tree','graph','gantt','heatmap','map','canvas'],PanelDataSurfaceType::values());
	foreach(PanelDataSurfaceType::values() as $value){$t->same($value,PanelDataSurfaceType::normalize(strtoupper($value))->value);}
	$t->throws(static fn()=>PanelDataSurfaceType::normalize('matrix'),InvalidArgumentException::class);
	$t->same('order_items',PanelDataSurfaceGuard::identifier(' Order items '));
	$t->throws(static fn()=>PanelDataSurfaceGuard::identifier('***'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataSurfaceGuard::boundedString([], 'value'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDataSurfaceGuard::boundedString('', 'value'),InvalidArgumentException::class);
	$t->same('',PanelDataSurfaceGuard::boundedString('', 'value',2,true));
	$t->throws(static fn()=>PanelDataSurfaceGuard::digest('bad'),InvalidArgumentException::class);
	$t->same('{"a":2,"z":1}',PanelDataSurfaceGuard::canonicalJson(['z'=>1,'a'=>2]));
	PanelDataSurfaceGuard::assertJson(1.5);$t->isTrue(true);
	$encoded=PanelDataSurfaceGuard::encode("binary\0value");$t->same("binary\0value",PanelDataSurfaceGuard::decode($encoded));
	$t->isNull(PanelDataSurfaceGuard::decode('bad='));
	foreach([INF,new stdClass(),str_repeat('x',PanelDataSurfaceGuard::MAX_STRING_BYTES+1)] as $bad){$t->throws(static fn()=>PanelDataSurfaceGuard::assertJson($bad),Throwable::class);}

	$range=PanelDataSurfaceRange::make(30,10,5,7);
	$t->same(25,$range->effectiveOffset());$t->same(22,$range->fetchLimit());$t->same(5,$range->appliedOverscanBefore());
	$t->same(['start'=>30,'length'=>10,'overscan_before'=>5,'overscan_after'=>7,'cursor'=>null],$range->claims());
	$t->same(false,$range->jsonSerialize()['cursor_present']);
	$cursor=PanelDataSurfaceRange::fromArray(['start'=>40,'length'=>8,'overscan_before'=>0,'overscan_after'=>3,'cursor'=>'opaque']);
	$t->same('opaque',$cursor->cursor());$t->same(11,$cursor->fetchLimit());
	foreach([
		static fn()=>PanelDataSurfaceRange::make(-1),static fn()=>PanelDataSurfaceRange::make(0,0),
		static fn()=>PanelDataSurfaceRange::make(0,500,250,251),static fn()=>PanelDataSurfaceRange::make(1,1,1,1,'cursor'),
		static fn()=>PanelDataSurfaceRange::fromArray(['unknown'=>1]),static fn()=>PanelDataSurfaceRange::fromArray(['start'=>'1']),
	] as $failure){$t->throws($failure,Throwable::class);}

	$projection=PanelDataSurfaceProjection::make(['name','profile.email'],'profile.id',['title'=>'name'],['name'=>'Customer']);
	$t->same(['profile.id','name','profile.email'],$projection->fields());$t->same('profile.id',$projection->stableKey());$t->same(['title'=>'name'],$projection->slots());$t->same(['name'=>'Customer'],$projection->labels());$t->same('Customer',$projection->label('name'));$t->same('Email',$projection->label('profile.email'));
	$projected=$projection->project(['name'=>'Ada','profile'=>['id'=>9,'email'=>'ada@example.test']]);
	$t->same('9',$projected['key']);$t->same('ada@example.test',$projected['data']['profile.email']);$t->same(64,strlen($projection->fingerprint()));
	$t->same($projection->jsonSerialize(),PanelDataSurfaceProjection::fromArray($projection->jsonSerialize())->jsonSerialize());
	foreach([
		static fn()=>PanelDataSurfaceProjection::make([],'id',['wrong'=>'id']),
		static fn()=>PanelDataSurfaceProjection::make(['id'],'id',['title'=>'missing']),
		static fn()=>PanelDataSurfaceProjection::make(['id'],'id',[],['missing'=>'No']),
		static fn()=>$projection->project(['name'=>'Missing key']),
		static fn()=>$projection->project(['profile'=>['id'=>[]]]),
	] as $failure){$t->throws($failure,Throwable::class);}
})->tag('panel','data-surface','contracts','security')->group('framework-coverage');

test('data surface context and definitions preserve trusted server ownership',static function(Context $t): void {
	$security=PanelSecurityContext::make('actor-9',['tenant_id'=>'tenant-2','attributes'=>['correlation_id'=>'bad corr<>','region'=>'ca']]);
	$context=PanelDataSurfaceContext::fromTrusted('Admin Panel',$security);
	$t->same('admin_panel',$context->panel());$t->same('tenant-2',$context->tenant());$t->same('actor-9',$context->principal());$t->same('badcorr',$context->correlationId());$t->same('ca',$context->get('region'));$t->same($security,$context->securityContext());
	$t->same(['bound'=>true,'panel'=>'admin_panel','has_tenant'=>true,'has_principal'=>true,'correlation_id'=>'badcorr'],$context->publicMetadata());
	$arrayContext=PanelDataSurfaceContext::fromTrusted('admin',['tenant_id'=>3,'actor_id'=>4]);$t->same('3',$arrayContext->tenant());$t->same('4',$arrayContext->principal());$t->isNull($arrayContext->securityContext());
	$t->throws(static fn()=>PanelDataSurfaceContext::fromTrusted('admin',['principal_id'=>'x']),InvalidArgumentException::class);
	$projection=PanelDataSurfaceProjection::make(['id','name']);
	$definition=PanelDataSurfaceDefinition::make('Orders Grid','Sales Orders','Main DB','cards',$projection,null,static fn(PanelDataSurfaceContext $ctx,array $state)=>PanelDataQuery::fromArray($state)->authorization(['actor'=>$ctx->principal()]),['title'=>'Orders','virtualize'=>false,'estimated_item_size'=>80]);
	$t->same('orders_grid',$definition->id());$t->same('sales_orders',$definition->resource());$t->same('main_db',$definition->source());$t->same('cards',$definition->surface()->value);$t->same(80,$definition->option('estimated_item_size'));$t->isFalse($definition->option('virtualize'));
	$query=$definition->resolveQuery($arrayContext,['search'=>'Ada']);$t->same('3',$query->tenantKey());$t->same('4',$query->authorizationMetadata()['actor']);
	$safe=$definition->safeState(PanelDataQuery::make()->search('Ada')->tenant('private')->authorization(['secret'=>'x'])->metadata(['secret'=>'y'])->cursor('cursor'));
	$t->notContains('tenant',array_keys($safe));$t->notContains('authorization',array_keys($safe));$t->notContains('cursor',array_keys($safe));
	foreach([
		static fn()=>$definition->safeState(['authorization'=>[]]),static fn()=>$definition->safeState([1,2]),
		static fn()=>PanelDataSurfaceDefinition::make('x','r','s','table',$projection,null,null,['estimated_item_size'=>1]),
		static fn()=>PanelDataSurfaceDefinition::make('x','r','s','table',$projection,null,static fn()=>new stdClass())->resolveQuery($arrayContext),
	] as $failure){$t->throws($failure,Throwable::class);}
})->tag('panel','data-surface','context','definition')->group('framework-coverage');

test('data surface signer binds every security domain and rotates retained keys',static function(Context $t): void {
	$now=1000;$clock=static function()use(&$now): int{return $now;};
	$oldSecret=str_repeat('o',32);$newSecret=str_repeat('n',32);
	$projection=PanelDataSurfaceProjection::make(['id','name']);$definition=PanelDataSurfaceDefinition::make('orders','orders','orders','list',$projection);
	$context=PanelDataSurfaceContext::fromTrusted('admin',['tenant_id'=>'tenant','principal_id'=>'actor']);$query=PanelDataQuery::make()->tenant('tenant');$range=PanelDataSurfaceRange::make(0,5,0,1);
	$old=new PanelDataSurfaceIntentSigner(['old'=>$oldSecret],'old',$clock);$intent=$old->issue($definition,$query,[],$range,$context,60);
	$rotated=new PanelDataSurfaceIntentSigner(['old'=>$oldSecret,'current'=>$newSecret],'current',$clock);$verified=$rotated->verify($intent->token(),$context);
	$t->same('old',$verified->keyId());$t->same(32,strlen($verified->nonce()));$t->same('admin',$verified->panel());$t->same('orders',$verified->definition());$t->same('orders',$verified->resource());$t->same('orders',$verified->source());$t->same('list',$verified->surface()->value);$t->same($definition->fingerprint(),$verified->definitionFingerprint());$t->same($query->fingerprint(),$verified->queryFingerprint());$t->same($projection->fingerprint(),$verified->projectionFingerprint());$t->same([],$verified->safeState());$t->same(5,$verified->range()->length());$t->same(1000,$verified->issuedAt());$t->same(1060,$verified->expiresAt());$t->same('window',$verified->authorizationEnvelope()['operation']);
	$t->same('panel_data_surface_intent',$intent->jsonSerialize()['type']);$t->same(1,$intent->jsonSerialize()['version']);$t->same('old',$intent->keyId());
	$t->same(1000,$intent->issuedAt());$t->same(1060,$intent->expiresAt());
	$manifest=$rotated->jsonSerialize();$t->same(1,$manifest['retained_key_count']);$t->isFalse($manifest['secrets_exposed']);$t->notContains($oldSecret,json_encode($manifest));
	$t->throws(static fn()=>$rotated->verify(substr($intent->token(),0,-1).'x',$context),PanelDataSurfaceException::class);
	$t->throws(static fn()=>$rotated->verify($intent->token(),PanelDataSurfaceContext::fromTrusted('admin',['tenant_id'=>'other','principal_id'=>'actor'])),PanelDataSurfaceException::class);
	$t->throws(static fn()=>$rotated->verify($intent->token(),PanelDataSurfaceContext::fromTrusted('admin',['tenant_id'=>'tenant','principal_id'=>'other'])),PanelDataSurfaceException::class);
	$now=1066;$expired=$t->throws(static fn()=>$rotated->verify($intent->token(),$context),PanelDataSurfaceException::class);$t->same('intent_expired',$expired->publicCode());$now=1000;
	$future=dp_panel_surface_resign($intent->token(),$oldSecret,static function(array $header,array $payload): array{$payload['iat']=2000;$payload['exp']=2060;return [$header,$payload];});
	$t->throws(static fn()=>$rotated->verify($future,$context),PanelDataSurfaceException::class);
	$wrongAudience=dp_panel_surface_resign($intent->token(),$oldSecret,static function(array $header,array $payload): array{$payload['aud']='other';return [$header,$payload];});
	$t->throws(static fn()=>$rotated->verify($wrongAudience,$context),PanelDataSurfaceException::class);
	foreach([
		static fn()=>new PanelDataSurfaceIntentSigner([], 'none'),static fn()=>new PanelDataSurfaceIntentSigner(['x'=>'short'],'x'),
		static fn()=>new PanelDataSurfaceIntentSigner(['x'=>str_repeat('x',32)],'missing'),static fn()=>new PanelDataSurfaceIntentSigner(['x'=>str_repeat('x',32)],'x',null,61),
		static fn()=>$rotated->issue($definition,$query,[],$range,$context,29),static fn()=>$rotated->issue($definition,$query,[],$range,$context,3601),
	] as $failure){$t->throws($failure,Throwable::class);}
})->tag('panel','data-surface','signing','rotation','adversarial')->group('framework-coverage');

test('data surface authorization always precedes source and query materialization',static function(Context $t): void {
	$capabilities=0;$queries=0;$resolvers=0;$authorizations=0;
	$source=new class($capabilities,$queries) implements PanelDataSource {
		public int $capabilitiesCalls=0;public int $queryCalls=0;
		public function __construct(int $a,int $b){}
		public function query(PanelDataQuery $query): PanelDataResult{$this->queryCalls++;return new PanelDataResult([],new PanelDataPage(0,1,0,0),'spy',[],[],[],$query);}
		public function find(string|int $id,?PanelDataQuery $scope=null): mixed{return null;}
		public function capabilities(): array{$this->capabilitiesCalls++;return ['adapter'=>'spy','offset'=>true,'cursor'=>true,'select'=>true,'filters'=>true,'sorts'=>true];}
	};
	$sources=(new PanelDataSourceRegistry())->register('spy',$source);$source->capabilitiesCalls=0;$signer=new PanelDataSurfaceIntentSigner(['key'=>str_repeat('k',32)],'key',static fn()=>1000);
	$definition=PanelDataSurfaceDefinition::make('spy','resource','spy','table',PanelDataSurfaceProjection::make(['id']),PanelDataSurfaceRange::make(0,1,0,0),static function()use(&$resolvers): PanelDataQuery{$resolvers++;return PanelDataQuery::make();});
	$registry=(new PanelDataSurfaceRegistry($sources,$signer,static function()use(&$authorizations): bool{$authorizations++;return false;}))->register($definition);$context=PanelDataSurfaceContext::fromTrusted('panel',['tenant_id'=>'tenant','principal_id'=>'actor']);
	$denied=$t->throws(static fn()=>$registry->issue('spy',$context),PanelDataSurfaceException::class);
	$t->same('transport_denied',$denied->publicCode());$t->same(1,$authorizations);$t->same(0,$source->capabilitiesCalls);$t->same(0,$source->queryCalls);$t->same(0,$resolvers);
	$unavailable=(new PanelDataSurfaceRegistry($sources,$signer,static function(): never{throw new RuntimeException('auth down');}))->register($definition);
	$error=$t->throws(static fn()=>$unavailable->issue('spy',$context),PanelDataSurfaceException::class);$t->same('transport_authorization_unavailable',$error->publicCode());$t->same(0,$source->capabilitiesCalls);
	$missing=(new PanelDataSurfaceRegistry(new PanelDataSourceRegistry(),$signer,static fn()=>true))->register($definition);
	$error=$t->throws(static fn()=>$missing->issue('spy',$context),PanelDataSurfaceException::class);$t->same('source_unavailable',$error->publicCode());
})->tag('panel','data-surface','authorization','ordering','critical')->group('framework-coverage');

test('data surface offset windows are bounded stable and never disclose source cursors',static function(Context $t): void {
	$fixture=dp_panel_surface_fixture();$registry=$fixture['registry'];$context=$fixture['context'];
	$intent=$registry->issue('orders_surface',$context,PanelDataQuery::make()->where('status','review')->sort('id'));
	$window=$registry->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),$context);$payload=$window->jsonSerialize();
	$t->same('panel_data_surface_window',$payload['type']);$t->same(7,$payload['returned']);$t->same(5,$payload['visible']);$t->same(15,$payload['total']);$t->isTrue($payload['total_known']);$t->isFalse($payload['has_before']);$t->isTrue($payload['has_after']);$t->isNull($payload['previous_intent']);$t->notNull($payload['next_intent']);
	$t->same('1',$payload['records'][0]['key']);$t->same(0,$payload['records'][0]['position']);$t->isTrue($payload['records'][0]['visible']);$t->isFalse($payload['records'][6]['visible']);
	$t->notContains('next_cursor',json_encode($payload));$t->notContains('previous_cursor',json_encode($payload));
	$next=$registry->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$window->nextIntent()->token()]),$context);$t->isTrue($next->hasBefore());$t->notNull($next->previousIntent());$t->same(5,$next->range()->effectiveOffset());
	$t->same(count($window),iterator_count($window->getIterator()));$t->same('orders_surface',$window->definition());$t->same('orders',$window->resource());$t->same('table',$window->surface()->value);
	$t->same($fixture['definition']->projection(),$window->projection());$t->isTrue($window->hasAfter());
})->tag('panel','data-surface','window','offset','stable-key')->group('framework-coverage');

test('data surface cursor and unknown total semantics remain explicit and opaque',static function(Context $t): void {
	$source=new PanelCallbackDataSource(static function(PanelDataQuery $query): array {
		return ['items'=>[['id'=>'a','name'=>'A'],['id'=>'b','name'=>'B']],'page'=>['offset'=>20,'limit'=>$query->limitValue(),'next_cursor'=>'upstream-next','previous_cursor'=>'upstream-prev','total'=>null]];
	},null,'cursor-only',['offset'=>false,'cursor'=>true,'select'=>true]);
	$sources=(new PanelDataSourceRegistry())->register('events',$source);$signer=new PanelDataSurfaceIntentSigner(['key'=>str_repeat('k',32)],'key',static fn()=>1000);
	$definition=PanelDataSurfaceDefinition::make('events','events','events','timeline',PanelDataSurfaceProjection::make(['id','name'],'id',['title'=>'name']),PanelDataSurfaceRange::make(20,2,0,0,'upstream-current'));
	$registry=(new PanelDataSurfaceRegistry($sources,$signer,static fn()=>true))->register($definition);$context=PanelDataSurfaceContext::fromTrusted('panel',['tenant_id'=>'tenant','principal_id'=>'actor']);
	$intent=$registry->issue('events',$context);$window=$registry->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),$context);$payload=$window->jsonSerialize();
	$t->isNull($payload['total']);$t->isFalse($payload['total_known']);$t->isTrue($payload['has_before']);$t->isTrue($payload['has_after']);$t->notNull($payload['previous_intent']);$t->notNull($payload['next_intent']);
	$t->notContains('upstream-next',json_encode($payload));$t->notContains('upstream-prev',json_encode($payload));$t->notContains('upstream-current',json_encode($payload));
	$offsetError=$t->throws(static fn()=>$registry->issue('events',$context,null,PanelDataSurfaceRange::make(0,2,0,0)),PanelDataSurfaceException::class);$t->same('offset_unsupported',$offsetError->publicCode());
})->tag('panel','data-surface','cursor','unknown-total')->group('framework-coverage');

test('data surface source failures duplicates and stale definitions fail closed',static function(Context $t): void {
	$context=PanelDataSurfaceContext::fromTrusted('panel',['tenant_id'=>'tenant','principal_id'=>'actor']);$signer=new PanelDataSurfaceIntentSigner(['key'=>str_repeat('k',32)],'key',static fn()=>1000);
	$duplicate=new PanelCallbackDataSource(static fn(PanelDataQuery $query): array=>['items'=>[['id'=>1],['id'=>1]],'page'=>['offset'=>0,'limit'=>$query->limitValue(),'total'=>2]],null,'duplicate',['offset'=>true,'cursor'=>true,'select'=>true]);
	$definition=PanelDataSurfaceDefinition::make('duplicate','orders','duplicate','table',PanelDataSurfaceProjection::make(['id']),PanelDataSurfaceRange::make(0,2,0,0));
	$registry=(new PanelDataSurfaceRegistry((new PanelDataSourceRegistry())->register('duplicate',$duplicate),$signer,static fn()=>true))->register($definition);$intent=$registry->issue('duplicate',$context);
	$error=$t->throws(static fn()=>$registry->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),$context),PanelDataSurfaceException::class);$t->same('result_duplicate_key',$error->publicCode());
	$failing=new PanelCallbackDataSource(static function(): never{throw new RuntimeException('database secret');},null,'failing',['offset'=>true]);
	$failingDefinition=PanelDataSurfaceDefinition::make('failing','orders','failing','table',PanelDataSurfaceProjection::make(['id']),PanelDataSurfaceRange::make(0,1,0,0));
	$failingRegistry=(new PanelDataSurfaceRegistry((new PanelDataSourceRegistry())->register('failing',$failing),$signer,static fn()=>true))->register($failingDefinition);$failingIntent=$failingRegistry->issue('failing',$context);
	$error=$t->throws(static fn()=>$failingRegistry->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$failingIntent->token()]),$context),PanelDataSurfaceException::class);$t->same('query_failed',$error->publicCode());$t->notContains('secret',$error->getMessage());
	$changed=PanelDataSurfaceDefinition::make('failing','changed','failing','table',PanelDataSurfaceProjection::make(['id']),PanelDataSurfaceRange::make(0,1,0,0));$failingRegistry->register($changed,true);
	$error=$t->throws(static fn()=>$failingRegistry->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$failingIntent->token()]),$context),PanelDataSurfaceException::class);$t->same('intent_stale',$error->publicCode());
})->tag('panel','data-surface','failure','adversarial')->group('framework-coverage');

test('data surface registry lifecycle and manifest stay sorted public and secret-free',static function(Context $t): void {
	$fixture=dp_panel_surface_fixture();
	$registry=$fixture['registry'];
	$t->isTrue($registry->has('orders_surface'));
	$t->same(['orders_surface'],$registry->names());
	$t->same($fixture['definition'],$registry->get('orders_surface'));
	$manifest=$registry->manifest();
	$t->same('panel_data_surface_registry',$manifest['type']);
	$t->same(1,$manifest['count']);
	$t->isTrue($manifest['definitions']['orders_surface']['source_registered']);
	$t->same('array',$manifest['definitions']['orders_surface']['source_capabilities']['adapter']);
	$t->isFalse($manifest['secrets_exposed']);
	$t->same($manifest,$registry->jsonSerialize());

	$intent=$registry->issue('orders_surface',$fixture['context']);
	$registry->forget('Orders Surface');
	$t->isFalse($registry->has('orders_surface'));
	$t->same([],$registry->names());
	$t->throws(static fn()=>$registry->get('orders_surface'),OutOfBoundsException::class);
	$missingIssue=$t->throws(static fn()=>$registry->issue('orders_surface',$fixture['context']),PanelDataSurfaceException::class);
	$t->same('surface_not_found',$missingIssue->publicCode());
	$missingExecute=$t->throws(static fn()=>$registry->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),$fixture['context']),PanelDataSurfaceException::class);
	$t->same('surface_not_found',$missingExecute->publicCode());
	$registry->register($fixture['definition']);
	$t->isTrue($registry->has('orders_surface'));
})->tag('panel','data-surface','registry','manifest','lifecycle')->group('framework-coverage');

test('data surface malformed signed envelopes and unexpected endpoint faults remain non-diagnostic',static function(Context $t): void {
	$fixture=dp_panel_surface_fixture();
	$secret=str_repeat('a',32);
	$validHeader=PanelDataSurfaceGuard::canonicalJson(['alg'=>'HS256','kid'=>'active','typ'=>'DP-SURFACE','v'=>1]);
	$badHeader=dp_panel_surface_sign_raw('{','{}',$secret);
	$t->throws(static fn()=>$fixture['signer']->verify($badHeader,$fixture['context']),PanelDataSurfaceException::class);
	$badPayload=dp_panel_surface_sign_raw($validHeader,'{',$secret);
	$t->throws(static fn()=>$fixture['signer']->verify($badPayload,$fixture['context']),PanelDataSurfaceException::class);
	$intent=$fixture['registry']->issue('orders_surface',$fixture['context']);
	$badRange=dp_panel_surface_resign($intent->token(),$secret,static function(array $header,array $payload): array {
		$payload['range']=['start'=>'not-an-integer'];
		return [$header,$payload];
	});
	$t->throws(static fn()=>$fixture['signer']->verify($badRange,$fixture['context']),PanelDataSurfaceException::class);

	$clockCalls=0;
	$clock=static function()use(&$clockCalls): mixed { return ++$clockCalls<=2 ? 1000 : 'clock-failure'; };
	$sourceRegistry=(new PanelDataSourceRegistry())->register('internal',new PanelArrayDataSource(dp_panel_surface_rows(3),['name'=>'internal']));
	$signer=new PanelDataSurfaceIntentSigner(['key'=>str_repeat('k',32)],'key',$clock);
	$definition=PanelDataSurfaceDefinition::make('internal','orders','internal','table',PanelDataSurfaceProjection::make(['id']),PanelDataSurfaceRange::make(0,1,0,0));
	$registry=(new PanelDataSurfaceRegistry($sourceRegistry,$signer,static fn(): bool=>true))->register($definition);
	$context=PanelDataSurfaceContext::fromTrusted('panel',['tenant_id'=>'north','principal_id'=>'actor','correlation_id'=>'corr-internal']);
	$continuing=$registry->issue('internal',$context);
	$response=(new PanelDataSurfaceEndpoint($registry))->handle(['intent'=>$continuing->token()],'panel',['tenant_id'=>'north','principal_id'=>'actor','correlation_id'=>'corr-internal']);
	$t->same(500,$response['status']);
	$t->same('internal_error',$response['body']['code']);
	$t->same('Panel DataSurface request failed.',$response['body']['message']);
	$t->same('corr-internal',$response['body']['correlation_id']);
})->tag('panel','data-surface','signing','endpoint','fault-boundary')->group('framework-coverage');

test('data surface adapter incompatibility invalid records and unknown cursor continuation fail closed',static function(Context $t): void {
	$context=PanelDataSurfaceContext::fromTrusted('panel',['tenant_id'=>'north','principal_id'=>'actor']);
	$signer=new PanelDataSurfaceIntentSigner(['key'=>str_repeat('k',32)],'key',static fn(): int=>1000);

	$throwingResolver=PanelDataSurfaceDefinition::make(
		'bad_query','orders','bad_query','table',PanelDataSurfaceProjection::make(['id']),null,
		static function(): never { throw new RuntimeException('private resolver failure'); }
	);
	$queryRegistry=(new PanelDataSurfaceRegistry(
		(new PanelDataSourceRegistry())->register('bad_query',new PanelArrayDataSource([['id'=>1]])),
		$signer,static fn(): bool=>true
	))->register($throwingResolver);
	$queryError=$t->throws(static fn()=>$queryRegistry->issue('bad_query',$context),PanelDataSurfaceException::class);
	$t->same('query_invalid',$queryError->publicCode());

	$limited=new PanelCallbackDataSource(static fn(): array=>['items'=>[]],null,'limited',['offset'=>true,'cursor'=>true,'select'=>true,'search'=>false]);
	$searchDefinition=PanelDataSurfaceDefinition::make(
		'limited','orders','limited','table',PanelDataSurfaceProjection::make(['id']),null,
		static fn(): PanelDataQuery=>PanelDataQuery::make()->search('private')
	);
	$limitedRegistry=(new PanelDataSurfaceRegistry((new PanelDataSourceRegistry())->register('limited',$limited),$signer,static fn(): bool=>true))->register($searchDefinition);
	$capabilityError=$t->throws(static fn()=>$limitedRegistry->issue('limited',$context),PanelDataSurfaceException::class);
	$t->same('adapter_incompatible',$capabilityError->publicCode());

	$invalidRecords=new PanelCallbackDataSource(static fn(PanelDataQuery $query): array=>[
		'items'=>[['name'=>'missing stable key']],
		'page'=>['offset'=>0,'limit'=>$query->limitValue(),'total'=>1],
	],null,'invalid-records',['offset'=>true,'cursor'=>true,'select'=>true]);
	$invalidDefinition=PanelDataSurfaceDefinition::make('invalid_records','orders','invalid_records','table',PanelDataSurfaceProjection::make(['id']),PanelDataSurfaceRange::make(0,1,0,0));
	$invalidRegistry=(new PanelDataSurfaceRegistry((new PanelDataSourceRegistry())->register('invalid_records',$invalidRecords),$signer,static fn(): bool=>true))->register($invalidDefinition);
	$invalidIntent=$invalidRegistry->issue('invalid_records',$context);
	$recordError=$t->throws(static fn()=>$invalidRegistry->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$invalidIntent->token()]),$context),PanelDataSurfaceException::class);
	$t->same('result_invalid',$recordError->publicCode());

	$cursorSource=new PanelCallbackDataSource(static function(PanelDataQuery $query): array {
		return ['items'=>[['id'=>'a'],['id'=>'b']],'page'=>['offset'=>20,'limit'=>$query->limitValue(),'total'=>null,'next_cursor'=>null,'previous_cursor'=>null]];
	},null,'cursor-terminal',['offset'=>false,'cursor'=>true,'select'=>true]);
	$cursorDefinition=PanelDataSurfaceDefinition::make('cursor_terminal','events','cursor_terminal','list',PanelDataSurfaceProjection::make(['id']),PanelDataSurfaceRange::make(20,2,0,0,'opaque-current'));
	$cursorRegistry=(new PanelDataSurfaceRegistry((new PanelDataSourceRegistry())->register('cursor_terminal',$cursorSource),$signer,static fn(): bool=>true))->register($cursorDefinition);
	$cursorIntent=$cursorRegistry->issue('cursor_terminal',$context);
	$terminal=$cursorRegistry->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$cursorIntent->token()]),$context);
	$t->isNull($terminal->hasAfter());
	$t->isNull($terminal->nextIntent());
})->tag('panel','data-surface','adapter','projection','cursor','fault-boundary')->group('framework-coverage');

test('data surface requests and endpoint expose stable no-store transport responses',static function(Context $t): void {
	$fixture=dp_panel_surface_fixture();$intent=$fixture['registry']->issue('orders_surface',$fixture['context']);
	$request=PanelDataSurfaceWindowRequest::fromJson(json_encode(['intent'=>$intent->token()],JSON_THROW_ON_ERROR));$t->same($intent->token(),$request->intent());$t->same(['intent'=>$intent->token()],$request->jsonSerialize());
	foreach(['',str_repeat('x',20001),'{','[]','{"intent":1}','{"intent":"x","tenant":"north"}'] as $json){$t->throws(static fn()=>PanelDataSurfaceWindowRequest::fromJson($json),PanelDataSurfaceException::class);}
	$t->throws(static fn()=>PanelDataSurfaceWindowRequest::fromArray(['intent'=>str_repeat('x',16385)]),PanelDataSurfaceException::class);
	$t->throws(static fn()=>PanelDataSurfaceWindowRequest::fromArray(['intent'=>'x','range'=>[]]),PanelDataSurfaceException::class);
	$endpoint=new PanelDataSurfaceEndpoint($fixture['registry']);$ok=$endpoint->handle(['intent'=>$intent->token()],'operations',['tenant_id'=>'north','principal_id'=>'operator-7','correlation_id'=>'c-1']);
	$t->same(200,$ok['status']);$t->same('no-store, private',$ok['headers']['Cache-Control']);$t->same('nosniff',$ok['headers']['X-Content-Type-Options']);$t->same('panel_data_surface_window',$ok['body']['type']);
	$denied=$endpoint->handle(['intent'=>$intent->token()],'operations',['tenant_id'=>'north','principal_id'=>'other','correlation_id'=>'c-2']);$t->same(401,$denied['status']);$t->same('intent_invalid',$denied['body']['code']);$t->same('c-2',$denied['body']['correlation_id']);
	$bad=$endpoint->handle(['intent'=>'bad'],'operations',['tenant_id'=>'north','principal_id'=>'operator-7']);$t->same(401,$bad['status']);$t->same('intent_invalid',$bad['body']['code']);
})->tag('panel','data-surface','endpoint','transport')->group('framework-coverage');

test('data surface SSR covers every semantic fallback with escaped bounded content',static function(Context $t): void {
	foreach(PanelDataSurfaceType::cases() as $type){
		$fixture=dp_panel_surface_fixture($type);$intent=$fixture['registry']->issue('orders_surface',$fixture['context']);$window=$fixture['registry']->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),$fixture['context']);
		$html=PanelDataSurfaceRenderer::render($fixture['definition'],$window,$intent);
		$t->contains('data-dp-data-surface',$html);$t->contains('aria-live="polite"',$html);$t->contains('data-dp-data-surface-config',$html);$t->contains('data-dp-data-surface-controls',$html);$t->contains('data-position="0"',$html);
		if($type===PanelDataSurfaceType::TABLE){$t->contains('<table',$html);$t->contains('scope="col"',$html);$t->contains('data-label="Order"',$html);}
		elseif($type->advanced()){$t->contains('dp-data-canvas__',$html);$t->same(2,$window->jsonSerialize()['version']);$t->same($type->value,$window->canvas()?->surface()->value);}
		else{$t->contains('role="list"',$html);$t->contains('<article>',$html);}
	}
	$source=new PanelArrayDataSource([['id'=>'<script>alert(1)</script>','tenant_id'=>'north','name'=>'<img src=x onerror=1>','image'=>'javascript:alert(1)','alt'=>'"><svg>']],['name'=>'xss']);$sources=(new PanelDataSourceRegistry())->register('xss',$source);$signer=new PanelDataSurfaceIntentSigner(['key'=>str_repeat('k',32)],'key',static fn()=>1000);$definition=PanelDataSurfaceDefinition::make('xss','xss','xss','gallery',PanelDataSurfaceProjection::make(['id','name','image','alt'],'id',['title'=>'name','image'=>'image','alt'=>'alt']),PanelDataSurfaceRange::make(0,1,0,0),null,['endpoint'=>'javascript:bad']);$registry=(new PanelDataSurfaceRegistry($sources,$signer,static fn()=>true))->register($definition);$context=PanelDataSurfaceContext::fromTrusted('panel',['tenant_id'=>'north','principal_id'=>'actor']);$intent=$registry->issue('xss',$context);$window=$registry->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),$context);$html=PanelRenderer::dataSurface($definition,$window,$intent);
	$t->contains('&lt;img src=x onerror=1&gt;',$html);$t->notContains('<img src=x onerror=1>',$html);$t->notContains('javascript:alert',$html);$t->contains('disabled aria-disabled="true"',$html);
	$other=dp_panel_surface_fixture('table');$t->throws(static fn()=>PanelDataSurfaceRenderer::render($other['definition'],$window),InvalidArgumentException::class);
})->tag('panel','data-surface','ssr','accessibility','xss')->group('framework-coverage');

test('data surface ResourceTable and capability assets remain explicit and scoped',static function(Context $t): void {
	$fixture=dp_panel_surface_fixture();$table=ResourceTable::make()->dataSurface($fixture['definition']);
	$t->same('orders_surface',$table->dataSurfaceDefinition()?->id());$t->same('orders_surface',$table->toArray()['data_surface']['id']);$t->isNull($table->withoutDataSurface()->dataSurfaceDefinition());
	$graph=PanelRenderer::assetCapabilityManifest(['virtualization']);$t->isTrue($graph->has('data-surface'));$t->isTrue($graph->has('shell'));$t->contains('data-surface',$graph->bundleCapabilities());$t->contains('data-surface',$graph->styleChunks());$t->contains('data-surface',$graph->runtimeChunks());
	$t->same($graph->bundleCapabilities(),\Dataphyre\Panel\PanelAssetCapabilityManifest::decodeToken($graph->token()));
	$js=(string)(PanelRenderer::assetContent('panel.js',['data-surface'])['content']??'');$css=(string)(PanelRenderer::assetContent('panel.css',['data-surface'])['content']??'');$surfaceCss=(string)$t->nonPublic(PanelRenderer::class)->invoke('dataSurfaceCss');
	$t->contains('dpPanelDataSurfaceInitialize',$js);$t->contains('new WeakMap()',$js);$t->contains('closest(".dp-panel")',$js);$t->contains('prefers-reduced-motion:reduce',$css);$t->contains('forced-colors:active',$css);$t->contains('@container dp-data-surface',$css);$t->contains('[dir="rtl"]',$css);$t->notContains('overflow-x:auto',$surfaceCss);$t->contains('table-layout:fixed',$surfaceCss);
})->tag('panel','data-surface','assets','resource-table','responsive')->group('framework-coverage');

test('data surface residual value paths and malformed envelopes fail closed',static function(Context $t): void {
	$fixture=dp_panel_surface_fixture();$registry=$fixture['registry'];$context=$fixture['context'];
	$intent=$registry->issue('orders_surface',$context);$verified=$fixture['signer']->verify($intent->token(),$context);
	$t->same($intent->issuedAt(),$verified->issuedAt());$t->same($intent->expiresAt(),$verified->expiresAt());
	$t->notSame('',$verified->nonce());$t->same('operations',$verified->panel());
	$t->same('active',$intent->keyId());$t->same(1000,$intent->issuedAt());$t->same(1300,$intent->expiresAt());
	$projection=$fixture['definition']->projection();$t->same('id',$projection->stableKey());$t->same('name',$projection->slots()['title']);$t->same('Order',$projection->labels()['name']);
	PanelDataSurfaceGuard::assertJson(1.25);

	$malformedHeader=PanelDataSurfaceGuard::encode('{').'.'.PanelDataSurfaceGuard::encode('{}').'.'.PanelDataSurfaceGuard::encode('x');
	$t->throws(static fn()=>$fixture['signer']->verify($malformedHeader,$context),PanelDataSurfaceException::class);
	[$head]=explode('.',$intent->token(),3);$input=$head.'.'.PanelDataSurfaceGuard::encode('{');
	$malformedPayload=$input.'.'.PanelDataSurfaceGuard::encode(hash_hmac('sha256',$input,str_repeat('a',32),true));
	$t->throws(static fn()=>$fixture['signer']->verify($malformedPayload,$context),PanelDataSurfaceException::class);
	$badDigest=dp_panel_surface_resign($intent->token(),str_repeat('a',32),static function(array $header,array $payload): array{$payload['query_fingerprint']='bad';return [$header,$payload];});
	$t->throws(static fn()=>$fixture['signer']->verify($badDigest,$context),PanelDataSurfaceException::class);

	$t->throws(static fn()=>PanelDataSurfaceWindowRequest::fromArray(['intent'=>" \0 "]),PanelDataSurfaceException::class);
	$t->throws(static fn()=>PanelDataSurfaceWindowRequest::fromJson('{'),PanelDataSurfaceException::class);
	$endpoint=(new PanelDataSurfaceEndpoint($registry))->handle(['intent'=>$intent->token()],'***',['tenant_id'=>'north','principal_id'=>'operator-7']);
	$t->same(500,$endpoint['status']);$t->same('internal_error',$endpoint['body']['code']);
	$t->throws(static fn()=>$fixture['definition']->safeState(['filters'=>[['value'=>['access-token'=>'must-not-leak']]]]),InvalidArgumentException::class);

	$render=$t->nonPublic(PanelDataSurfaceRenderer::class);
	$slotProjection=PanelDataSurfaceProjection::make(['id','meta'],'id',['meta'=>'meta']);
	$t->same('{"x":1}',$render->invoke('slot',$slotProjection,['meta'=>['x'=>1]],'meta'));
	$t->same('{&quot;x&quot;:1}',$render->invoke('value',['x'=>1]));
	$t->contains('Unavailable value',$render->invoke('value',[INF]));
})->tag('panel','data-surface','coverage','malformed','security')->group('framework-coverage');

test('data surface registry residual branches preserve authorization and bounded semantics',static function(Context $t): void {
	$fixture=dp_panel_surface_fixture();$registry=$fixture['registry'];$context=$fixture['context'];
	$manifest=$registry->manifest();$t->same(1,$manifest['count']);$t->isTrue($manifest['definitions']['orders_surface']['source_registered']);$t->same($manifest,$registry->jsonSerialize());
	$t->isTrue($registry->has('orders_surface'));$t->same(['orders_surface'],$registry->names());
	$t->throws(static fn()=>$registry->issue('missing',$context),PanelDataSurfaceException::class);
	$intent=$registry->issue('orders_surface',$context);$registry->forget('orders_surface');$t->isFalse($registry->has('orders_surface'));
	$missing=$t->throws(static fn()=>$registry->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),$context),PanelDataSurfaceException::class);$t->same('surface_not_found',$missing->publicCode());

	$throwing=PanelDataSurfaceDefinition::make('throwing','orders','orders','table',PanelDataSurfaceProjection::make(['id']),null,static function(): never{throw new RuntimeException('resolver secret');});
	$throwingRegistry=(new PanelDataSurfaceRegistry($fixture['source'],$fixture['signer'],static fn()=>true))->register($throwing);
	$invalid=$t->throws(static fn()=>$throwingRegistry->issue('throwing',$context),PanelDataSurfaceException::class);$t->same('query_invalid',$invalid->publicCode());

	$searchSource=new PanelCallbackDataSource(static fn(PanelDataQuery $query): array=>['items'=>[],'page'=>['offset'=>0,'limit'=>$query->limitValue(),'total'=>0]],null,'search-disabled',['search'=>false,'offset'=>true]);
	$searchDefinition=PanelDataSurfaceDefinition::make('search_disabled','orders','search_disabled','table',PanelDataSurfaceProjection::make(['id']),PanelDataSurfaceRange::make(0,1,0,0));
	$searchRegistry=(new PanelDataSurfaceRegistry((new PanelDataSourceRegistry())->register('search_disabled',$searchSource),$fixture['signer'],static fn()=>true))->register($searchDefinition);
	$unsupported=$t->throws(static fn()=>$searchRegistry->issue('search_disabled',$context,PanelDataQuery::make()->search('needle')),PanelDataSurfaceException::class);$t->same('adapter_incompatible',$unsupported->publicCode());

	$invalidSource=new PanelCallbackDataSource(static fn(PanelDataQuery $query): array=>['items'=>[['name'=>'missing id']],'page'=>['offset'=>0,'limit'=>$query->limitValue(),'total'=>1]],null,'invalid-record',['offset'=>true]);
	$invalidDefinition=PanelDataSurfaceDefinition::make('invalid_record','orders','invalid_record','table',PanelDataSurfaceProjection::make(['id','name']),PanelDataSurfaceRange::make(0,1,0,0));
	$invalidRegistry=(new PanelDataSurfaceRegistry((new PanelDataSourceRegistry())->register('invalid_record',$invalidSource),$fixture['signer'],static fn()=>true))->register($invalidDefinition);
	$invalidIntent=$invalidRegistry->issue('invalid_record',$context);$invalidRecord=$t->throws(static fn()=>$invalidRegistry->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$invalidIntent->token()]),$context),PanelDataSurfaceException::class);$t->same('result_invalid',$invalidRecord->publicCode());

	$cursorSource=new PanelCallbackDataSource(static fn(PanelDataQuery $query): array=>['items'=>[['id'=>'only','name'=>'Only']],'page'=>['offset'=>0,'limit'=>$query->limitValue(),'total'=>null,'next_cursor'=>null,'previous_cursor'=>null]],null,'cursor-terminal',['offset'=>false,'cursor'=>true]);
	$cursorDefinition=PanelDataSurfaceDefinition::make('cursor_terminal','orders','cursor_terminal','list',PanelDataSurfaceProjection::make(['id','name'],'id',['title'=>'name']),PanelDataSurfaceRange::make(0,1,0,0,'current'));
	$cursorRegistry=(new PanelDataSurfaceRegistry((new PanelDataSourceRegistry())->register('cursor_terminal',$cursorSource),$fixture['signer'],static fn()=>true))->register($cursorDefinition);
	$cursorIntent=$cursorRegistry->issue('cursor_terminal',$context);$window=$cursorRegistry->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$cursorIntent->token()]),$context);
	$t->isNull($window->hasAfter());$t->isNull($window->nextIntent());$t->same($cursorDefinition->projection(),$window->projection());
	$t->contains('1 record shown; total unknown.',PanelDataSurfaceRenderer::render($cursorDefinition,$window,$cursorIntent));
})->tag('panel','data-surface','coverage','registry','bounded')->group('framework-coverage');
