<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\Action;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\PanelInMemoryNavigationReplayGuard;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelNavigationIntent;
use Dataphyre\Panel\PanelNavigationIntentManager;
use Dataphyre\Panel\PanelNavigationIntentRuntime;
use Dataphyre\Panel\PanelNavigationIntentSigner;
use Dataphyre\Panel\PanelNavigationSigningKey;
use Dataphyre\Panel\PanelNavigationTarget;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelStaticNavigationKeyProvider;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestIsolation;
use Dataphyre\Test\TestLayer;
use Dataphyre\Test\TestRisk;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel','mvc']);

suite('Panel signed navigation-intent trust boundary')
	->contract('panel.navigation-intent.security', 1)
	->layer(TestLayer::Contract)
	->risk(TestRisk::Critical)
	->watches('module:panel', 'symbol:PanelNavigationIntent', 'symbol:PanelNavigationIntentSigner', 'symbol:PanelNavigationIntentManager')
	->through('panel.navigation-target', 'panel.navigation-keyring', 'panel.navigation-signer', 'panel.navigation-policy', 'panel.navigation-replay')
	->isolation(TestIsolation::File)
	->tag('panel', 'navigation', 'intent', 'security')
	->group('framework-coverage');

/** @return array<string,string> */
function dp_panel_signed_navigation_fields(string $html): array {
	$fields=[];
	preg_match_all('/<input\b[^>]*type="hidden"[^>]*>/i', $html, $matches);
	foreach($matches[0] ?? [] as $input){
		if(preg_match('/\bname="([^"]+)"/i', $input, $name)!==1 || preg_match('/\bvalue="([^"]*)"/i', $input, $value)!==1){ continue; }
		$fields[html_entity_decode($name[1], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')]=html_entity_decode($value[1], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
	}
	return $fields;
}

function dp_panel_signed_navigation_request(string $method='GET', array $input=[], array $query=[], mixed $user='operator-a', string $tenant='tenant-a', string $operation='create'): PanelRequest {
	return PanelRequest::fromArray([
		'method'=>$method,
		'resource'=>'signed-orders',
		'operation'=>$operation,
		'input'=>$input,
		'query'=>$query,
		'user'=>is_string($user) || is_int($user) ? ['id'=>$user] : $user,
		'tenant'=>$tenant,
	]);
}

/** @return list<array{id:string,surface:string,operation:string,outcome:string,target:string}> */
function dp_panel_signed_navigation_chain(int $depth): array {
	$chain=[];
	for($index=0;$index<$depth;$index++){
		$chain[]=[
			'id'=>hash('sha256','parent-'.$index),
			'surface'=>'modal',
			'operation'=>'edit',
			'outcome'=>'saved',
			'target'=>'/panel/orders/'.$index,
		];
	}
	return $chain;
}

test('navigation intents use canonical bounded claims and strict internal targets',static function(Context $t):void{
	$key=new PanelNavigationSigningKey('2026-07',str_repeat('K',32),100,10000);
	$provider=new PanelStaticNavigationKeyProvider(['2026-07'=>$key],'2026-07');
	$signer=new PanelNavigationIntentSigner($provider,null,0);
	$intent=PanelNavigationIntent::make('/panel/orders?view=risk&filters%5Bstatus%5D=open',[
		'audience'=>'panel.return','panel'=>'ops','surface'=>'orders','tenant'=>'tenant-a','subject'=>'operator-a',
		'operation'=>'save','outcome'=>'success','issued_at'=>1000,'not_before'=>1000,'expires_at'=>1100,'nonce'=>str_repeat('N',24),
	]);
	$token=$signer->issue($intent,1000);
	$t->same(2,substr_count($token,'.'));
	$t->isTrue(strlen($token)<PanelNavigationIntentSigner::MAX_TOKEN_BYTES);
	$verified=$signer->verify($token,[
		'now'=>1001,'audience'=>'panel.return','panel'=>'ops','surface'=>'orders','tenant_binding'=>'tenant-a','principal_binding'=>'operator-a',
		'operation'=>'save','outcome'=>'success','return_target'=>'/panel/orders?filters%5Bstatus%5D=open&view=risk',
	]);
	$t->isTrue($verified->valid());
	$t->same('/panel/orders?filters%5Bstatus%5D=open&view=risk',$verified->intent()?->returnTarget());
	$t->same(false,$provider->manifest()['secrets_serialized']);
	$t->notContains(str_repeat('K',32),json_encode([$key,$provider,$signer],JSON_THROW_ON_ERROR));
	foreach(['https://evil.test/panel','//evil.test/panel','javascript:alert(1)','/panel/http://evil.test','/panel/%2e%2e/admin','/panel/%252e%252e/admin',"/panel/orders\r\nX-Test: bad",'/panel\\admin']as$unsafe){
		$t->same(null,PanelNavigationTarget::normalize($unsafe));
	}
	$t->throws(static fn()=>PanelNavigationIntent::make(str_repeat('/a',1100)),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelNavigationSigningKey('bad key','short'),InvalidArgumentException::class);
	$maximumChain=PanelNavigationIntent::make('/panel/orders',[
		'issued_at'=>1000,'not_before'=>1000,'expires_at'=>1100,'nonce'=>str_repeat('D',24),
		'chain'=>dp_panel_signed_navigation_chain(PanelNavigationIntent::MAX_CHAIN_DEPTH),
	]);
	$t->same(PanelNavigationIntent::MAX_CHAIN_DEPTH,count($maximumChain->chain()));
	$t->throws(static fn()=>PanelNavigationIntent::make('/panel/orders',[
		'issued_at'=>1000,'not_before'=>1000,'expires_at'=>1100,'nonce'=>str_repeat('E',24),
		'chain'=>dp_panel_signed_navigation_chain(PanelNavigationIntent::MAX_CHAIN_DEPTH+1),
	]),InvalidArgumentException::class);
})->tag('panel','navigation','intent','canonical','security')->maxMillis(1500);

test('navigation verification rejects tamper time and every cross-context binding',static function(Context $t):void{
	$signer=new PanelNavigationIntentSigner(PanelStaticNavigationKeyProvider::single(str_repeat('S',32),'active'),null,0);
	$intent=PanelNavigationIntent::make('/panel/orders?view=queue',[
		'audience'=>'panel.navigation','panel'=>'ops','surface'=>'orders','tenant'=>'tenant-a','subject'=>'operator-a',
		'operation'=>'update','outcome'=>'saved','issued_at'=>2000,'not_before'=>2010,'expires_at'=>2100,'nonce'=>str_repeat('Q',24),
	]);
	$token=$signer->issue($intent,2020);
	[$header,$payload,$signature]=explode('.',$token);
	$t->same('invalid_signature',$signer->verify($header.'.'.$payload.'.'.substr($signature,0,-1).($signature[-1]==='A'?'B':'A'),['now'=>2020])->code());
	$t->same('not_yet_valid',$signer->verify($token,['now'=>2005])->code());
	$t->same('expired',$signer->verify($token,['now'=>2100])->code());
	foreach([
		['audience'=>'other'],['panel'=>'other'],['surface'=>'other'],['tenant_binding'=>'tenant-b'],['principal_binding'=>'operator-b'],['operation'=>'delete'],['outcome'=>'cancelled'],
	] as $expected){
		$t->same('context_mismatch',$signer->verify($token,['now'=>2020]+$expected)->code());
	}
	$t->same('target_mismatch',$signer->verify($token,['now'=>2020,'return_target'=>'/panel/orders?view=other'])->code());
	$future=PanelNavigationIntent::make('/panel/orders',['issued_at'=>2200,'not_before'=>2000,'expires_at'=>2300,'nonce'=>str_repeat('F',24)]);
	$t->same('issued_in_future',$signer->verify($signer->issue($future,2200),['now'=>2100])->code());
	$t->same('malformed',$signer->verify('not-a-token')->code());
	$t->same('oversized',$signer->verify(str_repeat('a',PanelNavigationIntentSigner::MAX_TOKEN_BYTES+1))->code());
})->tag('panel','navigation','intent','tamper','expiry','binding')->maxMillis(1500);

test('navigation key rotation replay protection and missing keys fail closed',static function(Context $t):void{
	$old=new PanelNavigationSigningKey('old',str_repeat('O',32),2900,3050);
	$current=new PanelNavigationSigningKey('current',str_repeat('C',32));
	$oldSigner=new PanelNavigationIntentSigner(new PanelStaticNavigationKeyProvider(['old'=>$old],'old'),null,0);
	$rotated=new PanelNavigationIntentSigner(new PanelStaticNavigationKeyProvider(['old'=>$old,'current'=>$current],'current'),new PanelInMemoryNavigationReplayGuard(),0);
	$intent=PanelNavigationIntent::make('/panel/orders',['issued_at'=>3000,'not_before'=>3000,'expires_at'=>3100,'nonce'=>str_repeat('R',24)]);
	$oldToken=$oldSigner->issue($intent,3000);
	$t->isTrue($rotated->verify($oldToken,['now'=>3001])->valid());
	$currentToken=$rotated->issue(PanelNavigationIntent::make('/panel/orders',['issued_at'=>3000,'not_before'=>3000,'expires_at'=>3100,'nonce'=>str_repeat('U',24)]),3000);
	$t->isTrue($rotated->verify($currentToken,['now'=>3001,'consume'=>true])->valid());
	$t->same('replay',$rotated->verify($currentToken,['now'=>3002,'consume'=>true])->code());
	$unknown=new PanelNavigationIntentSigner(PanelStaticNavigationKeyProvider::single(str_repeat('Z',32),'other'),null,0);
	$t->same('missing_key',$unknown->verify($oldToken,['now'=>3001])->code());
	$outsideWindow=PanelNavigationIntent::make('/panel/orders',['issued_at'=>3060,'not_before'=>3060,'expires_at'=>3100,'nonce'=>str_repeat('W',24)]);
	$outsideWindowToken=$oldSigner->issue($outsideWindow,3000);
	$t->same('invalid_claims',$rotated->verify($outsideWindowToken,['now'=>3060])->code());
	$disabled=new PanelNavigationIntentManager(null,'ops','orders','dataphyre.panel.navigation',PanelNavigationIntentManager::MIGRATION_DISABLED,900,'navigation_intent',null,true);
	$request=dp_panel_signed_navigation_request('POST',['return_to'=>'/panel/orders']);
	$t->isTrue($disabled->resolve($request,true)->blocked());
	$t->same('missing',$disabled->resolve($request,true)->verification()->code());
})->tag('panel','navigation','intent','rotation','replay','missing-key','activation-window')->maxMillis(1500);

test('unsigned migration is explicit same-panel only and privileged requests require a signed pair',static function(Context $t):void{
	$signer=new PanelNavigationIntentSigner(PanelStaticNavigationKeyProvider::single(str_repeat('M',32)),null,0);
	$manager=new PanelNavigationIntentManager($signer,'ops','orders','dataphyre.panel.navigation',PanelNavigationIntentManager::MIGRATION_SAME_PANEL,900);
	$get=dp_panel_signed_navigation_request('GET',[],['return_to'=>'/panel/orders?view=queue']);
	$migration=$manager->resolve($get,false);
	$t->isTrue($migration->accepted());
	$t->isTrue($migration->verification()->migrated());
	$post=dp_panel_signed_navigation_request('POST',['return_to'=>'/panel/orders?view=queue'],[],operation:'store');
	$t->isTrue($manager->resolve($post,true)->blocked());
	$t->same(null,$manager->resolve(dp_panel_signed_navigation_request('GET',[],['return_to'=>'https://evil.test']),false)->target());
	$token=$manager->issue('/panel/orders?view=queue',$get);
	$signed=dp_panel_signed_navigation_request('POST',['return_to'=>'/panel/orders?view=queue','navigation_intent'=>$token],[],operation:'store');
	$t->isTrue($manager->resolve($signed,true)->accepted());
	$t->isTrue($manager->resolve(dp_panel_signed_navigation_request('POST',['return_to'=>'/panel/orders?view=other','navigation_intent'=>$token],[],operation:'store'),true)->blocked());
})->tag('panel','navigation','intent','migration','privileged')->maxMillis(1500);

test('surface integration signs modal forms preserves parents and blocks tampered save navigation before mutation',static function(Context $t):void{
	$saves=0;
	$assignments=0;
	$panel=PanelInstance::make('ops')
		->navigationIntents(str_repeat('I',32),'primary',['surface'=>'orders','unsigned_migration'=>'same_panel','ttl'=>1200]);
	$resource=Resource::make('signed-orders')
		->field(Field::make('name')->required())
		->action(Action::make('assign')
			->field(Field::make('owner')->required())
			->navigationIntent(true,'assign','saved')
			->navigationAudience('ops.navigation')
			->navigationReturnTarget('/panel?view=assigned')
			->handle(static function()use(&$assignments):array{$assignments++;return ['message'=>'Assigned'];}))
		->saveUsing(static function(array $data)use(&$saves):array{$saves++;return ['message'=>'Saved '.$data['name']];});
	$panel->register($resource);
	$return='/panel?slice=attention&view=queue&page=3';
	$get=dp_panel_signed_navigation_request('GET',[],['return_to'=>$return]);
	$form=$panel->dispatch($get)->content();
	$fields=dp_panel_signed_navigation_fields($form);
	$t->same('/panel?page=3&slice=attention&view=queue',$fields['return_to'] ?? null);
	$t->isTrue(isset($fields['navigation_intent']));
	$t->contains('data-dp-panel-navigation-intent="1"',$form);
	$t->contains('href="/panel?page=3&amp;slice=attention&amp;view=queue" data-dp-panel-modal-cancel="1"',$form);

	$postInput=['name'=>'Order 1','return_to'=>$fields['return_to'],'navigation_intent'=>$fields['navigation_intent']];
	$saved=$panel->dispatch(dp_panel_signed_navigation_request('POST',$postInput,[],operation:'store'));
	$t->same(303,$saved->status());
	$t->same($fields['return_to'],$saved->headers()['Location'] ?? null);
	$t->same(1,$saves);

	$tampered=$postInput;
	$tampered['return_to']='/panel?view=other';
	$blocked=$panel->dispatch(dp_panel_signed_navigation_request('POST',$tampered,[],operation:'store'));
	$t->same(422,$blocked->status());
	$t->same(1,$saves);
	$t->contains('navigation_intent_rejected',$blocked->content());

	$crossUser=$panel->dispatch(dp_panel_signed_navigation_request('POST',$postInput,[],user:'operator-b',operation:'store'));
	$t->same(422,$crossUser->status());
	$crossTenant=$panel->dispatch(dp_panel_signed_navigation_request('POST',$postInput,[],tenant:'tenant-b',operation:'store'));
	$t->same(422,$crossTenant->status());
	$t->same(1,$saves);

	$childGet=dp_panel_signed_navigation_request('GET',[],['return_to'=>$fields['return_to'],'navigation_intent'=>$fields['navigation_intent']]);
	$childFields=dp_panel_signed_navigation_fields($panel->dispatch($childGet)->content());
	$childVerification=$panel->verifyNavigationIntent($childFields['navigation_intent'],$childGet,['return_target'=>$childFields['return_to']]);
	$t->isTrue($childVerification->valid());
	$t->same(hash('sha256',$fields['navigation_intent']),$childVerification->intent()?->parent());
	$t->same(1,count($childVerification->intent()?->chain() ?? []));

	$actionGet=PanelRequest::fromArray([
		'method'=>'GET','resource'=>'signed-orders','operation'=>'action','action'=>'assign',
		'query'=>['return_to'=>$return],'user'=>['id'=>'operator-a'],'tenant'=>'tenant-a',
	]);
	$actionFields=dp_panel_signed_navigation_fields($panel->dispatch($actionGet)->content());
	$t->same('/panel?view=assigned',$actionFields['return_to'] ?? null);
	$actionVerification=$panel->verifyNavigationIntent($actionFields['navigation_intent'],$actionGet,[
		'audience'=>'ops.navigation','operation'=>'assign','outcome'=>'saved','return_target'=>'/panel?view=assigned',
	]);
	$t->isTrue($actionVerification->valid());
	$wrongContextToken=$panel->issueNavigationIntent('/panel?view=assigned',$actionGet,[
		'audience'=>'ops.navigation','operation'=>'return','outcome'=>'complete','now'=>time(),
	]);
	$wrongAction=$panel->dispatch(PanelRequest::fromArray([
		'method'=>'POST','resource'=>'signed-orders','operation'=>'action','action'=>'assign',
		'input'=>['__panel_action_submit'=>'1','owner'=>'Mina','return_to'=>'/panel?view=assigned','navigation_intent'=>$wrongContextToken],
		'user'=>['id'=>'operator-a'],'tenant'=>'tenant-a',
	]));
	$t->same(422,$wrongAction->status());
	$t->same(0,$assignments);
	$assigned=$panel->dispatch(PanelRequest::fromArray([
		'method'=>'POST','resource'=>'signed-orders','operation'=>'action','action'=>'assign',
		'input'=>['__panel_action_submit'=>'1','owner'=>'Mina','return_to'=>$actionFields['return_to'],'navigation_intent'=>$actionFields['navigation_intent']],
		'user'=>['id'=>'operator-a'],'tenant'=>'tenant-a',
	]));
	$t->same(303,$assigned->status());
	$t->same('/panel?view=assigned',$assigned->headers()['Location'] ?? null);
	$t->same(1,$assignments);
})->tag('panel','navigation','intent','modal','daughter','save','cancel','tamper')->maxMillis(4000);

test('fluent action and panel manifests expose capabilities without signing secrets',static function(Context $t):void{
	$action=Action::make('assign')
		->modalStack('push')
		->backOnModalExit()
		->navigationIntent(true,'assign','saved')
		->navigationAudience('ops.navigation')
		->navigationReturnTarget('/panel/orders?view=assigned');
	$definition=$action->toArray();
	$t->same('assign',$definition['navigation_intent']['operation']);
	$t->same('saved',$definition['navigation_intent']['outcome']);
	$t->same('ops.navigation',$definition['navigation_intent']['audience']);
	$t->same('/panel/orders?view=assigned',$definition['navigation_intent']['return_target']);
	$roundTrip=Action::fromArray($definition)->toArray();
	$t->same($definition['navigation_intent'],$roundTrip['navigation_intent']);
	$secret=str_repeat('V',32);
	$panel=PanelInstance::make('manifest')->navigationIntentKey($secret,'v2',['surface'=>'admin']);
	$manifest=$panel->panelManifest();
	$json=json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
	$t->same(true,$manifest['navigation_intents']['configured']);
	$t->same('admin',$manifest['navigation_intents']['surface']);
	$t->same(false,$manifest['navigation_intents']['secrets_serialized']);
	$t->notContains($secret,$json);
})->tag('panel','navigation','intent','fluent','manifest','secret-free')->maxMillis(3000);

test('navigation intent defensive branches stay bounded canonical and fail closed',static function(Context $t):void{
	$secret=str_repeat('D',32);
	$key=new PanelNavigationSigningKey('bounded',$secret,100,200);
	$provider=new PanelStaticNavigationKeyProvider(['bounded'=>$key],'bounded');
	$signer=new PanelNavigationIntentSigner($provider,null,0);
	$t->throws(static fn()=>PanelNavigationIntent::make('/panel',['issued_at'=>20,'not_before'=>20,'expires_at'=>20]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelNavigationIntent::make('/panel',['nonce'=>'short']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelNavigationIntent::make('/panel',['audience'=>'bad audience']),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelNavigationSigningKey('short','too-short'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelNavigationSigningKey('bounded',str_repeat('x',32),200,200),InvalidArgumentException::class);
	$t->same('/?a=1',PanelNavigationTarget::normalize('?a=1'));
	$t->same('/relative/path',PanelNavigationTarget::normalize('relative/path'));

	$early=PanelNavigationIntent::make('/panel',['issued_at'=>90,'not_before'=>90,'expires_at'=>180,'nonce'=>str_repeat('E',24)]);
	$t->same('invalid_claims',$signer->verify($signer->issue($early,150),['now'=>150])->code());
	$late=PanelNavigationIntent::make('/panel',['issued_at'=>200,'not_before'=>200,'expires_at'=>250,'nonce'=>str_repeat('L',24)]);
	$t->same('invalid_claims',$signer->verify($signer->issue($late,150),['now'=>210])->code());

	$encode=static fn(string $value):string=>rtrim(strtr(base64_encode($value),'+/','-_'),'=');
	$t->same('malformed',$signer->verify($encode('{').'.'.$encode('{}').'.'.$encode(str_repeat('x',32)))->code());
	$nonCanonicalHeader='{"typ":"DP-NAV","alg":"HS256","v":1,"kid":"bounded"}';
	$t->same('malformed',$signer->verify($encode($nonCanonicalHeader).'.'.$encode('{}').'.'.$encode(str_repeat('x',32)))->code());
	$invalidPayload=$early->payload();
	unset($invalidPayload['aud']);
	$encodedHeader=$encode(PanelNavigationIntentSigner::canonicalJson(['alg'=>'HS256','typ'=>'DP-NAV','v'=>1,'kid'=>'bounded']));
	$encodedPayload=$encode(PanelNavigationIntentSigner::canonicalJson($invalidPayload));
	$input=$encodedHeader.'.'.$encodedPayload;
	$invalidClaims=$input.'.'.$encode(hash_hmac('sha256',$input,$secret,true));
	$t->same('invalid_claims',$signer->verify($invalidClaims,['now'=>150])->code());
	$t->same($provider,$signer->keyProvider());
	$t->same(null,$signer->replayGuard());
	$t->throws(static fn()=>PanelNavigationIntentSigner::canonicalJson(['invalid'=>new stdClass()]),InvalidArgumentException::class);
	$smallSigner=new PanelNavigationIntentSigner(PanelStaticNavigationKeyProvider::single(str_repeat('B',32)),null,0,512);
	$largeIntent=PanelNavigationIntent::make('/panel?payload='.str_repeat('a',600),['issued_at'=>100,'not_before'=>100,'expires_at'=>200,'nonce'=>str_repeat('B',24)]);
	$t->throws(static fn()=>$smallSigner->issue($largeIntent,100),LengthException::class);
	$t->same($early->payload(),$early->jsonSerialize());

	$t->throws(static fn()=>new PanelStaticNavigationKeyProvider([
		'a'=>new PanelNavigationSigningKey('duplicate',str_repeat('a',32)),
		'b'=>new PanelNavigationSigningKey('duplicate',str_repeat('b',32)),
	],'duplicate'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStaticNavigationKeyProvider(['only'=>str_repeat('o',32)],'missing'),InvalidArgumentException::class);
	$replay=new PanelInMemoryNavigationReplayGuard();
	$t->isTrue($replay->accept(str_repeat('N',24),10,['now'=>1]));
	$t->same(1,$replay->jsonSerialize()['stored_nonces']);
	$t->isTrue($replay->accept(str_repeat('M',24),30,['now'=>20]));
})->tag('panel','navigation','intent','defensive','coverage')->maxMillis(2500);

test('navigation manager runtime covers disabled policy principals diagnostics and query pairs',static function(Context $t):void{
	$signer=new PanelNavigationIntentSigner(PanelStaticNavigationKeyProvider::single(str_repeat('P',32),'principal'),null,0);
	$t->throws(static fn()=>new PanelNavigationIntentManager($signer,migrationPolicy:'unknown'),InvalidArgumentException::class);
	$request=dp_panel_signed_navigation_request('GET',[],['return_to'=>'/panel?view=queue']);
	$disabled=new PanelNavigationIntentManager($signer,'ops','orders','panel.navigation',PanelNavigationIntentManager::MIGRATION_DISABLED,900,'navigation_intent',null,false);
	$t->same(null,$disabled->issue('/panel',$request));
	$t->same('disabled',$disabled->verify('ignored',$request)->code());
	$disabledResolution=$disabled->resolve($request);
	$t->isTrue($disabledResolution->accepted());
	$t->same('disabled',$disabledResolution->verification()->code());
	$t->same(PanelNavigationIntentManager::MIGRATION_DISABLED,$disabled->migrationPolicy());
	$t->same('ops',$disabled->panel());
	$t->same('orders',$disabled->surface());
	$t->same(false,$disabled->canIssue());
	$t->contains('panel_navigation_resolution',json_encode($disabledResolution,JSON_THROW_ON_ERROR));
	$t->contains('Navigation intents are not configured.',$disabledResolution->verification()->message());
	$t->same(null,$disabled->resolve(dp_panel_signed_navigation_request())->target());
	$t->contains('panel_navigation_intent_manifest',json_encode($disabled,JSON_THROW_ON_ERROR));

	$resolvedManager=new PanelNavigationIntentManager($signer,'ops','orders','panel.navigation',principalResolver:static fn():string=>'resolver-user');
	$t->isTrue(is_string($resolvedManager->issue('/panel',dp_panel_signed_navigation_request(user:[]),['now'=>100,'expires_at'=>200])));
	$throwingResolver=new PanelNavigationIntentManager($signer,'ops','orders','panel.navigation',principalResolver:static function():never{throw new RuntimeException('resolver failed');});
	$t->isTrue(is_string($throwingResolver->issue('/panel',dp_panel_signed_navigation_request(user:[]),['now'=>100,'expires_at'=>200])));
	$methodUser=new class { public function id():string{return 'method-user';} };
	$objectManager=new PanelNavigationIntentManager($signer,'ops','orders','panel.navigation');
	$t->isTrue(is_string($objectManager->issue('/panel',dp_panel_signed_navigation_request(user:$methodUser),['now'=>100,'expires_at'=>200])));
	$propertyUser=new class { public string $user_id='property-user'; public function id():never{throw new RuntimeException('method failed');} };
	$t->isTrue(is_string((new PanelNavigationIntentManager($signer,'ops','orders','panel.navigation'))->issue('/panel',dp_panel_signed_navigation_request(user:$propertyUser),['now'=>100,'expires_at'=>200])));
	$opaqueUser=new class { public function __get(string $name):mixed{throw new RuntimeException('property failed');} };
	$t->isTrue(is_string((new PanelNavigationIntentManager($signer,'ops','orders','panel.navigation'))->issue('/panel',dp_panel_signed_navigation_request(user:$opaqueUser),['now'=>100,'expires_at'=>200])));

	$runtimeRequest=dp_panel_signed_navigation_request();
	$runtime=PanelContext::run([
		'panel_name'=>'runtime',
		'navigation_intents'=>['enabled'=>true,'key'=>str_repeat('R',32),'key_id'=>'runtime','surface'=>'runtime','leeway'=>0],
	],static function()use($runtimeRequest):array{
		return [
			PanelNavigationIntentRuntime::query('/panel?view=runtime',$runtimeRequest,['now'=>100,'expires_at'=>200]),
			PanelNavigationIntentRuntime::query('https://evil.test',$runtimeRequest),
		];
	});
	$t->same('/panel?view=runtime',$runtime[0]['return_to']);
	$t->isTrue(isset($runtime[0]['navigation_intent']));
	$t->same([],$runtime[1]);
	$futureProvider=new PanelStaticNavigationKeyProvider(['future'=>['secret'=>str_repeat('F',32),'not_before'=>500]],'future');
	$failed=PanelContext::run([
		'panel_name'=>'runtime',
		'navigation_intents'=>['enabled'=>true,'key_provider'=>$futureProvider,'surface'=>'runtime','leeway'=>0],
	],static function()use($runtimeRequest):array{
		return [
			PanelNavigationIntentRuntime::hiddenInputs('/panel',$runtimeRequest,['now'=>100,'expires_at'=>200]),
			PanelNavigationIntentRuntime::query('/panel',$runtimeRequest,['now'=>100,'expires_at'=>200]),
		];
	});
	$t->contains('name="return_to"',$failed[0]);
	$t->notContains('navigation_intent',$failed[0]);
	$t->same(['return_to'=>'/panel'],$failed[1]);
	$verification=$signer->verify($signer->issue(PanelNavigationIntent::make('/panel',['issued_at'=>100,'not_before'=>100,'expires_at'=>200,'nonce'=>str_repeat('V',24)]),100),['now'=>101]);
	$t->same('principal',$verification->keyId());
	$t->contains('panel_navigation_intent_verification',json_encode($verification,JSON_THROW_ON_ERROR));
})->tag('panel','navigation','intent','manager','runtime','coverage')->maxMillis(3500);
