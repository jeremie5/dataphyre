<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelSearchContext;
use Dataphyre\Panel\PanelSearchPage;
use Dataphyre\Panel\PanelSearchProvider;
use Dataphyre\Panel\PanelSearchResult;
use Dataphyre\Panel\PanelTrace;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\SearchManifest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);
if(!function_exists('tracelog')){ function tracelog(mixed ...$arguments): void {} }

test('global search value contracts are immutable bounded redacted and URL safe',static function(Context $t): void {
	$request=PanelRequest::fromArray(['tenant'=>'tenant-a','user'=>['id'=>'operator-1']]);
	$context=PanelSearchContext::make('  order  ',$request,0,99,['cursor'=>'A','api_token'=>'adapter-secret'],[
		'api_token'=>'drop-me',
		'callback'=>static fn(): bool=>true,
	]);
	$t->same('order',$context->query());
	$t->same($request,$context->request());
	$t->same(1,$context->providerLimit());
	$t->same(50,$context->globalLimit());
	$t->same('tenant-a',$context->tenant());
	$t->same(['id'=>'operator-1'],$context->user());
	$t->same('[redacted]',$context->option('api_token'));
	$t->same('[closure]',$context->option('callback'));
	$t->same('fallback',$context->option('missing','fallback'));
	$t->same($context->options(),$context->toArray()['options']);
	$t->same('adapter-secret',$context->cursor()['api_token']);
	$t->same('[redacted]',$context->toArray()['cursor']['api_token']);
	$t->same('order',$context->jsonSerialize()['query']);

	$result=PanelSearchResult::fromArray([
		'title'=>' Order A ',
		'subtitle'=>' Primary ',
		'record_key'=>' A-1 ',
		'url'=>'HTTPS://EXAMPLE.COM/Orders/A-1',
		'score'=>INF,
		'meta'=>['password'=>'drop-me','safe'=>'kept'],
	],'orders','Orders');
	$t->isTrue($result instanceof PanelSearchResult);
	$t->same('Order A',$result->title());
	$t->same('orders',$result->provider());
	$t->same('Orders',$result->providerLabel());
	$t->same('Orders',$result->resourceLabel());
	$t->same('Orders',$result->sourceLabel());
	$t->same('Primary',$result->subtitle());
	$t->same('',$result->icon());
	$t->same(0.0,$result->score());
	$t->same('url:https://example.com/Orders/A-1',$result->dedupeKey());
	$t->same('[redacted]',$result->meta()['password']);
	$t->same('kept',$result->meta()['safe']);
	$t->same('Order A',$result->jsonSerialize()['title']);
	$t->isTrue($result->forProvider('orders')->withScore(NAN)->score()===0.0);
	$t->same('',PanelSearchResult::fromArray(['title'=>'Unsafe','url'=>'java'."\n".'script:alert(1)'])?->url());
	$t->same('',PanelSearchResult::fromArray(['title'=>'Credentials','url'=>'https://user:pass@example.com/a'])?->url());
	$t->same('mailto:ops@example.com',PanelSearchResult::fromArray(['title'=>'Mail','url'=>'mailto:ops@example.com'])?->url());
	$t->same('',PanelSearchResult::fromArray(['title'=>'Network','url'=>'//evil.example/path'])?->url());
	$t->same('',PanelSearchResult::fromArray(['title'=>'Backslash','url'=>'https:\\evil.example\path'])?->url());
	$t->isFalse(PanelSearchResult::fromArray(['title'=>'   ']) instanceof PanelSearchResult);
	$t->throws(static fn()=>PanelSearchResult::make('   '),InvalidArgumentException::class);
	$caseSensitive=PanelSearchResult::fromArray(['title'=>'Case','url'=>'https://example.com/orders/a']);
	$t->isFalse($result->dedupeKey()===$caseSensitive?->dedupeKey());
	$upperRecord=PanelSearchResult::fromArray(['title'=>'Upper','record_key'=>'A'],'orders');
	$lowerRecord=PanelSearchResult::fromArray(['title'=>'Lower','record_key'=>'a'],'orders');
	$t->isFalse($upperRecord?->dedupeKey()===$lowerRecord?->dedupeKey());

	$consumed=0;
	$rows=(static function()use(&$consumed): Generator {
		for($index=0;$index<500;$index++){
			$consumed++;
			yield PanelSearchResult::make('Result '.$index,'bounded');
		}
	})();
	$diagnostics=[];
	for($index=0;$index<80;$index++){ $diagnostics[]=['code'=>'d'.$index,'secret'=>'drop']; }
	$wide=[];
	for($index=0;$index<110;$index++){ $wide['key-'.$index]=$index; }
	$page=PanelSearchPage::make($rows,['bounded'=>'cursor','continuation_token'=>'opaque'],false,true,$diagnostics,[
		'authorization'=>'drop',
		'nested'=>['one'=>['two'=>['three'=>['four'=>'limited']]]],
		'wide'=>$wide,
		'serializable'=>new class implements JsonSerializable { public function jsonSerialize(): mixed { return ['safe'=>'value']; } },
		'broken_serializable'=>new class implements JsonSerializable { public function jsonSerialize(): mixed { throw new RuntimeException('nope'); } },
		'object'=>new stdClass(),
		'long_utf8'=>str_repeat('€',700),
	]);
	$t->same(200,count($page));
	$t->isTrue($consumed<=201);
	$t->same(50,count($page->diagnostics()));
	$t->same('[redacted]',$page->diagnostics()[1]['secret']);
	$t->same('[redacted]',$page->meta()['authorization']);
	$t->isTrue($page->meta()['wide']['__truncated__']);
	$t->same('value',$page->meta()['serializable']['safe']);
	$t->same('failed',$page->meta()['broken_serializable']['serialization']);
	$t->same(stdClass::class,$page->meta()['object']['type']);
	$t->isTrue(strlen($page->meta()['long_utf8'])<=2003);
	$t->same(['bounded'=>'cursor','continuation_token'=>'opaque'],$page->nextCursor());
	$t->same('[redacted]',$page->toArray()['next_cursor']['continuation_token']);
	$t->isFalse($page->isComplete());
	$t->isTrue($page->isPartial());
	$t->same('page_result_budget_exhausted',$page->diagnostics()[0]['code']);
	$t->same(200,$page->jsonSerialize()['result_count']);
	$t->same(200,count(iterator_to_array($page)));
	$fromArray=PanelSearchPage::fromArray(['items'=>[['title'=>'Array row']],'cursor'=>new stdClass(),'diagnostics'=>'invalid','meta'=>'invalid']);
	$t->same(1,count($fromArray));
	$t->same(null,$fromArray->nextCursor());
	$t->same([],$fromArray->diagnostics());
	$jsonSafe=PanelSearchPage::make(meta:['nonfinite'=>INF,'invalid_utf8'=>"bad\xFF"]);
	$t->same(0.0,$jsonSafe->meta()['nonfinite']);
	$t->isTrue(json_encode($jsonSafe)!==false);
	$t->same(null,PanelSearchPage::make(nextCursor:'   ')->nextCursor());
	$t->same(null,PanelSearchPage::make(nextCursor:[])->nextCursor());
	$invalidConsumed=0;
	$invalidRows=(static function()use(&$invalidConsumed): Generator { while(true){ $invalidConsumed++; yield 'invalid'; } })();
	$t->same(0,count(PanelSearchPage::make($invalidRows)));
	$t->isTrue($invalidConsumed<=201);
	$throwingRows=(static function(): Generator { yield ['title'=>'Before failure']; throw new RuntimeException('page iterator failed'); })();
	$throwingPage=PanelSearchPage::make($throwingRows);
	$t->same(1,count($throwingPage));
	$t->isTrue($throwingPage->isPartial());
	$t->same('page_result_error',$throwingPage->diagnostics()[0]['code']);
})->tag('panel','global-search','contracts','security')->group('framework-coverage');

test('search providers support iterable and cursor pages with guarded tenant authorization',static function(Context $t): void {
	$manager=new PanelManager();
	$request=PanelRequest::fromArray(['tenant'=>'tenant-a','user'=>['id'=>'operator-1']]);
	$captured=[];
	$provider=PanelSearchProvider::fromArray([
		'name'=>'indexed-orders',
		'label'=>'Indexed orders',
		'tenant_scoped'=>true,
		'tenant_required'=>true,
		'visible'=>static fn(): bool=>true,
		'authorize'=>static fn(mixed $user,PanelRequest $resolved,PanelSearchProvider $source,PanelManager $resolvedManager,?string $tenant): bool=>
			($user['id'] ?? null)==='operator-1' && $tenant==='tenant-a' && $source->name()==='indexed-orders' && $resolvedManager===$manager,
		'score'=>static fn(PanelSearchResult $result): float=>$result->title()==='Second' ? 9.0 : 3.0,
		'dedupe'=>static fn(PanelSearchResult $result): string=>'provider:'.$result->recordKey(),
		'search'=>static function(string $query,PanelRequest $resolved,PanelSearchProvider $source,int $limit,?PanelManager $resolvedManager,PanelSearchContext $context)use(&$captured): Generator {
			$captured=[$query,$resolved,$source,$limit,$resolvedManager,$context];
			yield ['title'=>'First','record_key'=>'1','provider'=>'spoofed'];
			yield 'invalid';
			yield ['title'=>'Second','record_key'=>'2'];
			yield ['title'=>'Third','record_key'=>'3'];
		},
		'meta'=>['api_key'=>'drop','tenant_scoped'=>'not-authority'],
	]);
	$provider=$provider->icon(' database ');
	$manager->registerSearchProvider($provider);
	$page=$provider->searchAuthorizedPage(' orders ',$request,$manager,2,['after'=>'10'],7);
	$t->same(2,count($page));
	$t->same('orders',$captured[0]);
	$t->same($request,$captured[1]);
	$t->same($provider,$captured[2]);
	$t->same(2,$captured[3]);
	$t->same($manager,$captured[4]);
	$t->same(['after'=>'10'],$captured[5]->cursor());
	$t->same(7,$captured[5]->globalLimit());
	$t->same(9.0,$page->results()[1]->score());
	$t->same('indexed-orders',$page->results()[0]->provider());
	$t->same('provider:2',$page->results()[1]->dedupeKey());
	$t->same('[redacted]',$provider->toArray()['meta']['api_key']);
	$t->same('database',$provider->toArray()['icon']);
	$t->isTrue($provider->toArray()['tenant_scoped']);
	$t->isTrue($provider->toArray()['tenant_required']);

	$missingTenant=PanelRequest::fromArray(['user'=>['id'=>'operator-1']]);
	$t->same(0,count($provider->searchAuthorizedPage('orders',$missingTenant,$manager)));
	$t->same(0,count($provider->searchAuthorizedPage('orders',PanelRequest::fromArray(['tenant'=>'tenant-a','user'=>['id'=>'wrong']]),$manager)));

	$hiddenCalls=0;
	$hidden=PanelSearchProvider::make('hidden')->hide()->searchUsing(static function()use(&$hiddenCalls): array {
		$hiddenCalls++;
		return [['title'=>'Raw compatibility']];
	});
	$t->same(1,count($hidden->searchPage('x',$request,$manager)));
	$t->same(0,count($hidden->searchAuthorizedPage('x',$request,$manager)));
	$t->same(1,$hiddenCalls);

	$cursorPage=PanelSearchProvider::make('cursor')->pageUsing(static fn()=>PanelSearchPage::make(
		[['title'=>'Cursor result']],
		'next-2',
		false,
		true,
		[['code'=>'index_degraded','message'=>'Replica lag']],
		['adapter'=>'index']
	));
	$resolved=$cursorPage->searchPage('x',$request,$manager);
	$t->same('next-2',$resolved->nextCursor());
	$t->same('next-2',$resolved->toArray()['next_cursor']);
	$t->isFalse($resolved->isComplete());
	$t->isTrue($resolved->isPartial());
	$t->same('index_degraded',$resolved->diagnostics()[0]['code']);
	$t->same('index',$resolved->meta()['adapter']);
	$associative=PanelSearchProvider::make('associative')->searchUsing(static fn(): array=>[
		'items'=>[['title'=>'Associative page']],
		'next_cursor'=>'assoc-next',
		'complete'=>false,
	]);
	$t->same('assoc-next',$associative->searchPage('x',$request,$manager)->nextCursor());
	$t->same([],$associative->searchPage('   ',$request,$manager)->results());
	$t->same([],$associative->search('   ',$request,$manager));

	PanelTrace::flush();
	$brokenAuth=PanelSearchProvider::make('broken-auth')->authorizeUsing(static function(): never { throw new RuntimeException('auth failed'); });
	$t->isFalse($brokenAuth->isAuthorized($request,$manager));
	$t->isFalse(PanelSearchProvider::make('broken-visible')->visibleUsing(static function(): never { throw new RuntimeException('visible failed'); })->isVisible($request,$manager));
	$invalid=PanelSearchProvider::make('invalid')->searchUsing(static fn()=>new stdClass());
	$t->isTrue($invalid->searchPage('x',$request,$manager)->isPartial());
	$failed=PanelSearchProvider::make('failed')->searchUsing(static function(): never { throw new RuntimeException('adapter failed'); });
	$t->same('provider_error',$failed->searchPage('x',$request,$manager)->diagnostics()[0]['code']);
	$badRank=PanelSearchProvider::make('bad-rank')->rankUsing(static fn()=>INF)->deduplicateUsing(static fn()=>new stdClass())->searchUsing(static fn(): array=>[['title'=>'Still returned']]);
	$badPage=$badRank->searchPage('x',$request,$manager);
	$t->isTrue($badPage->isPartial());
	$t->same(['score_error','dedupe_error'],array_column($badPage->diagnostics(),'code'));
	$noisy=PanelSearchProvider::make('noisy')->searchUsing(static function(): Generator { while(true){ yield 'invalid'; } });
	$noisyPage=$noisy->searchPage('x',$request,$manager,1);
	$t->isTrue($noisyPage->isPartial());
	$t->same('input_budget_exhausted',$noisyPage->diagnostics()[0]['code']);
	$throwing=PanelSearchProvider::make('throwing')->searchUsing(static function(): Generator { yield ['title'=>'Before failure']; throw new RuntimeException('provider iterator failed'); });
	$throwingPage=$throwing->searchPage('x',$request,$manager);
	$t->same(1,count($throwingPage));
	$t->isTrue($throwingPage->isPartial());
	$t->same('provider_iteration_error',$throwingPage->diagnostics()[0]['code']);
	$t->same('throwing',$throwingPage->diagnostics()[0]['provider']);
	$spoofedDiagnostic=PanelSearchProvider::make('real-source')->pageUsing(static fn()=>PanelSearchPage::make([],diagnostics:[['code'=>'degraded','provider'=>'spoofed']]));
	$t->same('real-source',$spoofedDiagnostic->searchPage('x',$request,$manager)->diagnostics()[0]['provider']);
	$traceNames=array_column(PanelTrace::events(),'event');
	$t->contains('search_provider.authorization_error',$traceNames);
	$t->contains('search_provider.invalid_response',$traceNames);
	$t->contains('search_provider.error',$traceNames);
	$t->contains('search_provider.iteration_error',$traceNames);
	$traceJson=(string)json_encode(PanelTrace::events());
	$t->isFalse(str_contains($traceJson,'auth failed'));
	$t->isFalse(str_contains($traceJson,'adapter failed'));
	$t->isFalse(str_contains($traceJson,'provider iterator failed'));
})->tag('panel','global-search','provider','authorization')->group('framework-coverage');

test('search coordinator ranks deduplicates budgets cursors and isolates partial failures',static function(Context $t): void {
	$manager=new PanelManager();
	$request=PanelRequest::fromArray(['tenant'=>'tenant-a','user'=>['id'=>'operator']]);
	$sensitiveQuery='order customer@example.test';
	$budgets=[];
	$cursors=[];
	$deniedCalls=0;
	$manager->registerSearchProviders([
		PanelSearchProvider::make('zeta')->sort(30)->limit(50)->searchUsing(static function(string $query,PanelRequest $request,PanelSearchProvider $provider,int $limit)use(&$budgets): array {
			$budgets['zeta']=$limit;
			return [
				['title'=>'Duplicate low','url'=>'https://EXAMPLE.com/Orders/A','score'=>2],
				['title'=>'Zeta tie','record_key'=>'z','score'=>5],
			];
		}),
		PanelSearchProvider::make('alpha')->sort(20)->rankUsing(static fn(PanelSearchResult $result): float=>$result->title()==='Duplicate high' ? 10 : 5)->searchUsing(static function(string $query,PanelRequest $request,PanelSearchProvider $provider,int $limit,?PanelManager $manager,PanelSearchContext $context)use(&$budgets,&$cursors): PanelSearchPage {
			$budgets['alpha']=$limit;
			$cursors[]=$context->cursor();
			return PanelSearchPage::make([
				['title'=>'Duplicate high','url'=>'https://example.com/Orders/A'],
				['title'=>'Alpha tie','record_key'=>'a'],
			], 'alpha-next', false);
		}),
		PanelSearchProvider::make('beta')->sort(20)->searchUsing(static fn(): array=>[['title'=>'Beta tie','record_key'=>'b','score'=>5]]),
		PanelSearchProvider::make('denied')->authorizeUsing(static fn(): bool=>false)->searchUsing(static function()use(&$deniedCalls): array { $deniedCalls++; return [['title'=>'Never']]; }),
		PanelSearchProvider::make('broken')->searchUsing(static function(): never { throw new RuntimeException('index offline'); }),
	]);
	$manager->register(Resource::make('resource-orders')->label('Resource orders')->globalSearchUsing(static fn(): array=>[
		['title'=>'Resource winner','record_key'=>'r','score'=>20],
	]));
	$manager->register(Resource::make('broken-resource')->globalSearchUsing(static function(): never { throw new RuntimeException('resource offline'); }));
	PanelTrace::flush();
	$page=$manager->globalSearchPage($sensitiveQuery,$request,5,['alpha'=>'alpha-cursor']);
	$t->same(5,count($page));
	$t->same('Resource winner',$page->results()[0]->title());
	$t->same('Duplicate high',$page->results()[1]->title());
	$t->same('Alpha tie',$page->results()[2]->title());
	$t->same('Beta tie',$page->results()[3]->title());
	$t->same('Zeta tie',$page->results()[4]->title());
	$t->same(1,count(array_filter($page->results(),static fn(PanelSearchResult $result): bool=>str_contains($result->title(),'Duplicate'))));
	$t->same(['alpha-cursor'],$cursors);
	$t->same(['alpha'=>'alpha-next'],$page->nextCursor());
	$t->isFalse($page->isComplete());
	$t->isTrue($page->isPartial());
	$t->same(['provider_error','resource_error'],array_column($page->diagnostics(),'code'));
	$t->same(0,$deniedCalls);
	$t->isTrue(max($budgets)<=5);
	$t->same(5,count($manager->globalSearch($sensitiveQuery,$request,5)));
	$traceJson=(string)json_encode(PanelTrace::events());
	$t->isFalse(str_contains($traceJson,$sensitiveQuery));
	$t->isFalse(str_contains($traceJson,'index offline'));
	$t->isFalse(str_contains($traceJson,'resource offline'));

	$t->same('alpha',$manager->searchProvider(' Alpha ')?->name());
	$t->isTrue($manager->hasSearchProvider('alpha'));
	$t->isFalse($manager->hasSearchProvider('missing'));
	$t->same(5,count($manager->registeredSearchProviders()));
	$t->same(4,count($manager->searchProviders($request,true)));
	$t->same(5,$manager->describe()['search_provider_count']);
	$t->throws(static fn()=>$manager->registerSearchProvider(['name'=>'...']),InvalidArgumentException::class);
	$t->same(1,count($manager->registerSearchProviders(['skip',PanelSearchProvider::make('extra')])));
	$t->same(0,count($manager->globalSearchPage('   ',$request)));

	$denyingManager=new PanelManager();
	$denyingManager->registerSearchProvider(PanelSearchProvider::make('blocked')->searchUsing(static fn(): array=>[['title'=>'blocked']]));
	$denyingManager->authorize(static function(): never { throw new RuntimeException('policy unavailable'); });
	$t->same(0,count($denyingManager->globalSearchPage('x',$request)));

	$many=new PanelManager();
	$definitions=[];
	for($index=0;$index<101;$index++){
		$definitions[]=PanelSearchProvider::make('source-'.$index)->searchUsing(static fn(): array=>[]);
	}
	$many->registerSearchProviders($definitions);
	$manyPage=$many->globalSearchPage('x',$request,1);
	$t->isTrue($manyPage->isPartial());
	$t->same('source_budget_exhausted',$manyPage->diagnostics()[0]['code']);
})->tag('panel','global-search','coordinator','ranking')->group('framework-coverage');

test('search manifests report custom provider security ranking paging and empty samples truthfully',static function(Context $t): void {
	$manager=new PanelManager();
	$request=PanelRequest::fromArray(['tenant'=>'tenant-a','user'=>['id'=>'operator']]);
	$sensitiveQuery='zero customer@example.test';
	$manager->registerSearchProvider(
		PanelSearchProvider::make('index')
			->description('External search index')
			->tenantScoped(false)
			->visibleUsing(static fn(): bool=>true)
			->authorizeUsing(static fn(): bool=>true)
			->rankUsing(static fn(PanelSearchResult $result): float=>$result->score())
			->deduplicateUsing(static fn(PanelSearchResult $result): string=>$result->dedupeKey())
			->meta(['api_token'=>'drop','adapter'=>'elastic-compatible'])
			->searchUsing(static fn(): PanelSearchPage=>PanelSearchPage::make([],['api_token'=>'manifest-secret'],false))
	);
	$manager->register(Resource::make('orders')->label('Orders')->globalSearchUsing(static fn(): array=>[]));
	$manager->register(Resource::make('not-searchable'));
	$manager->registerSearchProvider(PanelSearchProvider::make('orders')->searchUsing(static fn(): array=>[]));
	PanelTrace::flush();
	$manifest=SearchManifest::from($manager,$request,$sensitiveQuery,7,['secret'=>'drop','surface'=>'test'])->toArray();
	$t->same('search_manifest',$manifest['type']);
	$t->same(3,$manifest['provider_count']);
	$t->same('custom',$manifest['providers']['index']['kind']);
	$t->same('resource',$manifest['providers']['orders']['kind']);
	$t->same('custom',$manifest['providers']['custom:orders']['kind']);
	$t->isFalse($manifest['providers']['index']['tenant_scoped']);
	$t->isTrue($manifest['providers']['index']['authorizes']);
	$t->isTrue($manifest['providers']['index']['score_lazy']);
	$t->isTrue($manifest['providers']['index']['dedupe_lazy']);
	$t->same('[redacted]',$manifest['providers']['index']['meta']['api_token']);
	$t->isTrue($manifest['query']['sampled']);
	$t->same(0,$manifest['query']['result_count']);
	$t->same('[redacted]',$manifest['query']['next_cursor']['index']['api_token']);
	$t->isTrue($manifest['capabilities']['results']['sampled']);
	$t->same('[redacted]',$manifest['meta']['secret']);
	$t->same(2,$manifest['capabilities']['providers']['custom']);
	$t->same(1,$manifest['capabilities']['providers']['resource']);
	$t->isTrue($manifest['capabilities']['contracts']['deterministic_ranking']);
	$t->isFalse($manifest['capabilities']['contracts']['fake_async_blocking']);
	$t->isFalse(str_contains((string)json_encode(PanelTrace::events()),$sensitiveQuery));

	$arrayManifest=SearchManifest::from(['resources'=>[],'search_providers'=>[
		'raw'=>['name'=>'raw','label'=>'Raw','meta'=>['password'=>'drop'],'tenant_scoped'=>false],
		'invalid'=>'skip',
		'...'=>['name'=>'...'],
		'hidden'=>['name'=>'hidden','hidden'=>true],
	]])->toArray();
	$t->same(1,$arrayManifest['provider_count']);
	$t->same('[redacted]',$arrayManifest['providers']['raw']['meta']['password']);
	$arrayPanelManifest=\Dataphyre\Panel\PanelManifest::from(['resources'=>[],'search_providers'=>[
		['name'=>'panel-raw','label'=>'Panel raw','search_lazy'=>true],
	]])->toArray();
	$t->same(1,$arrayPanelManifest['search']['provider_count']);
	$directManifest=SearchManifest::from(['orders'=>[
		'name'=>'orders','search'=>['global_searchable'=>true,'columns'=>['title']],
		'tenant'=>['scoped'=>false],'data'=>['queryable'=>true],'policies'=>['authorizes'=>false],
	]])->toArray();
	$t->same('resource',$directManifest['providers']['orders']['kind']);
	$t->same('search_manifest',SearchManifest::from(null)->toArray()['type']);

	$instance=Panel::make('manifest-search');
	$instance->registerSearchProvider(PanelSearchProvider::make('instance-index')->searchUsing(static fn(): array=>[]));
	$t->same(1,SearchManifest::from($instance,$request,'x')->toArray()['provider_count']);
	$failedSample=$t->nonPublic(SearchManifest::from([]))->invoke('safeSample',static function(): never { throw new RuntimeException('coordinator failed'); });
	$t->isTrue($failedSample->isPartial());
	$t->same('sample_error',$failedSample->diagnostics()[0]['code']);
	$t->isFalse(str_contains((string)json_encode(PanelTrace::events()),'coordinator failed'));

	$panelManifest=\Dataphyre\Panel\PanelManifest::from($manager,$request)->toArray();
	$t->same(3,$panelManifest['search']['provider_count']);
	$t->same('custom',$panelManifest['search']['providers']['index']['kind']);
})->tag('panel','global-search','manifest','truthfulness')->group('framework-coverage');

test('Panel facade and isolated PanelInstance expose full search registration and page APIs',static function(Context $t): void {
	PanelManager::flush();
	$request=PanelRequest::fromArray(['tenant'=>'tenant-a']);
	$registered=Panel::registerSearchProviders([
		['name'=>'facade','search'=>static fn(): array=>[['title'=>'Facade result','record_key'=>'1']]],
	]);
	Panel::registerSearchProvider(PanelSearchProvider::make('facade-second')->searchUsing(static fn(): array=>[]));
	$t->same(1,count($registered));
	$t->same('facade',Panel::searchProvider('facade')?->name());
	$t->isTrue(Panel::hasSearchProvider('facade'));
	$t->same(2,count(Panel::searchProviders()));
	$t->same('Facade result',Panel::globalSearch('face',$request,3)[0]['title']);
	$t->same(1,count(Panel::globalSearchPage('face',$request,3)));

	$instance=Panel::make('isolated-search');
	$provider=$instance->registerSearchProvider(PanelSearchProvider::make('instance')->searchUsing(static fn(): array=>[['title'=>'Instance result']]));
	$t->same('instance',$provider->name());
	$t->same(1,count($instance->registerSearchProviders([['name'=>'second','search'=>static fn(): array=>[]]])));
	$t->same('instance',$instance->searchProvider('instance')?->name());
	$t->isTrue($instance->hasSearchProvider('second'));
	$t->same(2,count($instance->searchProviders()));
	$t->same(1,count($instance->globalSearchPage('instance',$request,5)));
	$t->same(2,Panel::manager()->describe()['search_provider_count']);
	$t->same(2,$instance->manager()->describe()['search_provider_count']);
})->tag('panel','global-search','facade','instance')->group('framework-coverage');
