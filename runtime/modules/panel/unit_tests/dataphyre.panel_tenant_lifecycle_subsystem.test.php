<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelSearchProvider;
use Dataphyre\Panel\PanelTenant;
use Dataphyre\Panel\PanelTenantContext;
use Dataphyre\Panel\PanelTenantMembership;
use Dataphyre\Panel\PanelTenantOnboardingResult;
use Dataphyre\Panel\PanelTenantSanitizer;
use Dataphyre\Panel\PanelTenantStorageScope;
use Dataphyre\Panel\PanelTenantSwitchResult;
use Dataphyre\Panel\PanelTrace;
use Dataphyre\Panel\TenantManifest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);
if(!function_exists('tracelog')){ function tracelog(mixed ...$arguments): void {} }

test('tenant contracts are immutable bounded redacted and deterministic',static function(Context $t): void {
	$wide=[];
	for($index=0;$index<110;$index++){ $wide['key-'.$index]=$index; }
	$safe=PanelTenantSanitizer::map([
		'api_token'=>'private',
		'float'=>INF,
		'resource'=>fopen('php://memory','rb'),
		'closure'=>static fn(): bool=>true,
		'date'=>new DateTimeImmutable('2026-07-13T12:00:00+00:00'),
		'json'=>new class implements JsonSerializable { public function jsonSerialize(): mixed { return ['safe'=>true]; } },
		'broken'=>new class implements JsonSerializable { public function jsonSerialize(): mixed { throw new RuntimeException('private detail'); } },
		'object'=>new stdClass(),
		'wide'=>$wide,
		'deep'=>['a'=>['b'=>['c'=>['d'=>['e'=>'limited']]]]],
		'invalid_utf8'=>"bad\xFF",
	]);
	$t->same('[redacted]',$safe['api_token']);
	$t->same(0.0,$safe['float']);
	$t->same('[resource]',$safe['resource']);
	$t->same('[closure]',$safe['closure']);
	$t->contains('2026-07-13',$safe['date']);
	$t->isTrue($safe['json']['safe']);
	$t->same('failed',$safe['broken']['serialization']);
	$t->same(stdClass::class,$safe['object']['type']);
	$t->isTrue($safe['wide']['__truncated__']);
	$t->same('[depth-limited]',$safe['deep']['a']['b']['c']['d']);
	$t->isTrue(json_encode($safe)!==false);
	$t->same([],PanelTenantSanitizer::map([]));
	$t->same('',PanelTenantSanitizer::text([]));
	$t->same('abc',PanelTenantSanitizer::text(' abc ',3));
	$t->same('',PanelTenantSanitizer::text('€',2));
	$t->same('stringable',PanelTenantSanitizer::text(new class implements Stringable { public function __toString(): string { return ' stringable '; } }));
	$throwingStringable=new class implements Stringable { public function __toString(): string { throw new RuntimeException('private conversion failure'); } };
	$t->same('',PanelTenantSanitizer::text($throwingStringable));
	$t->same(null,PanelTenantSanitizer::badge($throwingStringable));
	$t->same(null,PanelTenantSanitizer::tenantKey('../north'));
	$t->same('north_america',PanelTenantSanitizer::tenantKey('North_America'));
	$t->same('/tenant/north',PanelTenantSanitizer::url('/tenant/north'));
	$t->same(null,PanelTenantSanitizer::url('javascript:alert(1)'));
	$t->same(null,PanelTenantSanitizer::url('//evil.example/path'));
	$t->same(null,PanelTenantSanitizer::url('https://user:pass@example.com'));
	$t->same(null,PanelTenantSanitizer::url('https:\\evil.example'));
	$t->same(null,PanelTenantSanitizer::badge(INF));
	$t->same(null,PanelTenantSanitizer::badge([]));
	$t->same('tenant_error',PanelTenantSanitizer::diagnostic('','Safe message',new RuntimeException('private'))['code']);

	$membership=PanelTenantMembership::fromArray([
		'tenant_key'=>'north','roles'=>[' Owner ','Owner',new stdClass()],
		'permissions'=>['*','orders.view','orders.view'],'preferred'=>true,'expires_at'=>time()+300,
		'meta'=>['session_token'=>'private','safe'=>'yes'],
	]);
	$t->same('north',$membership->tenant());
	$t->same(['owner'],$membership->roles());
	$t->same(['*','orders.view'],$membership->permissions());
	$t->isTrue($membership->preferred());
	$t->isTrue(is_int($membership->expiresAt()));
	$t->isTrue($membership->isActive());
	$t->isTrue($membership->canSwitch());
	$t->isTrue($membership->allows('anything'));
	$t->same('[redacted]',$membership->meta()['session_token']);
	$t->same($membership->toArray(),$membership->jsonSerialize());
	$expired=PanelTenantMembership::make('north',active:true,canSwitch:true,expiresAt:time()-1);
	$t->isFalse($expired->isActive());
	$t->isFalse($expired->canSwitch());
	$t->isFalse($expired->allows('orders.view'));
	$t->throws(static fn()=>PanelTenantMembership::make('../bad'),InvalidArgumentException::class);
	$boundedMembership=PanelTenantMembership::make(
		'north',
		array_map(static fn(int $index): string=>'role-'.$index,range(0,39)),
		array_map(static fn(int $index): string=>'permission-'.$index,range(0,69)),
	);
	$t->same(32,count($boundedMembership->roles()));
	$t->same(64,count($boundedMembership->permissions()));
	$strictFlags=PanelTenantMembership::fromArray(['tenant'=>'north','active'=>1,'can_switch'=>1,'expires_at'=>0]);
	$t->isFalse($strictFlags->isActive());
	$t->same(null,$strictFlags->expiresAt());

	$request=PanelRequest::fromArray(['tenant'=>'stale','user'=>['id'=>'operator']]);
	$tenant=PanelTenant::make('north')->meta(['password'=>'private']);
	$active=PanelTenantContext::active($request,$tenant,$membership,'resolver',['status'=>'active'],['api_key'=>'private']);
	$t->same('north',$active->request()->tenantKey());
	$t->same($tenant,$active->tenant());
	$t->same($membership,$active->membership());
	$t->same('north',$active->tenantKey());
	$t->isTrue($active->isActive());
	$t->isTrue($active->isAuthorized());
	$t->same('active',$active->code());
	$t->same('resolver',$active->source());
	$t->same('active',$active->entitlement()['status']);
	$t->same('[redacted]',$active->meta()['api_key']);
	$t->same($active->toArray(),$active->jsonSerialize());
	$inactive=PanelTenantContext::inactive($request,'','', ['authorization'=>'private']);
	$t->same(null,$inactive->tenant());
	$t->same(null,$inactive->membership());
	$t->same(null,$inactive->tenantKey());
	$t->isFalse($inactive->isActive());
	$t->isFalse($inactive->isAuthorized());
	$t->same('tenant_unresolved',$inactive->code());
	$t->same('none',$inactive->source());
	$t->same('[redacted]',$inactive->meta()['authorization']);

	$success=PanelTenantSwitchResult::success($inactive,$active,['session_token'=>'private']);
	$t->isTrue($success->ok());
	$t->same('switched',$success->code());
	$t->same($inactive,$success->previous());
	$t->same($active,$success->current());
	$t->isTrue($success->persisted());
	$t->same('[redacted]',$success->meta()['session_token']);
	$t->same($success->toArray(),$success->jsonSerialize());
	$failure=PanelTenantSwitchResult::failure('', $active, diagnostics:[['code'=>'failed','password'=>'private'],'invalid']);
	$t->isFalse($failure->ok());
	$t->same('switch_failed',$failure->code());
	$t->same($active,$failure->current());
	$t->isFalse($failure->persisted());
	$t->same('[redacted]',$failure->diagnostics()[0]['password']);

	$onboarded=PanelTenantOnboardingResult::success($tenant,[' prepare ','prepare','activate'],['api_token'=>'private']);
	$t->isTrue($onboarded->ok());
	$t->same('onboarded',$onboarded->code());
	$t->same($tenant,$onboarded->tenant());
	$t->same(['prepare','activate'],$onboarded->completed());
	$t->same([],$onboarded->rolledBack());
	$t->isFalse($onboarded->replayed());
	$t->same('[redacted]',$onboarded->meta()['api_token']);
	$t->isTrue($onboarded->asReplay()->replayed());
	$t->same($onboarded->toArray(),$onboarded->jsonSerialize());
	$onboardingFailure=PanelTenantOnboardingResult::failure('',null,['one'],['one'],[['code'=>'failure','secret'=>'private'],'invalid']);
	$t->isFalse($onboardingFailure->ok());
	$t->same('onboarding_failed',$onboardingFailure->code());
	$t->same(null,$onboardingFailure->tenant());
	$t->same(['one'],$onboardingFailure->rolledBack());
	$t->same('[redacted]',$onboardingFailure->diagnostics()[0]['secret']);
})->tag('panel','tenant','contracts','security')->group('framework-coverage');

test('tenant registry resolves only explicit authorized memberships and switches through host persistence',static function(Context $t): void {
	PanelTrace::flush();
	$manager=new PanelManager();
	$manager->registerTenants([
		PanelTenant::make('north')->label('North')->url('/panel/north')->sort(20)->badge(2),
		PanelTenant::make('south')->label('South')->url('/panel/south')->sort(10),
		PanelTenant::make('hidden')->hide()->url('/panel/hidden'),
		PanelTenant::make('blocked')->url('/panel/blocked'),
	]);
	$request=PanelRequest::fromArray(['user'=>['id'=>'operator']]);
	$manager->tenantMembershipsUsing(static fn(): array=>[
		'north'=>['roles'=>['operator'],'preferred'=>true],
		'south'=>PanelTenantMembership::make('south'),
		'hidden'=>'hidden',
		'blocked'=>'blocked',
		'unknown'=>'unknown',
		'invalid'=>new stdClass(),
	]);
	$abilities=[];
	$manager->tenantAuthorizationUsing(static function(mixed $user,PanelRequest $resolved,PanelTenant $tenant,PanelTenantMembership $membership,string $ability)use(&$abilities): bool {
		$abilities[]=[$resolved->tenantKey(),$tenant->name(),$membership->tenant(),$ability];
		return $tenant->name()!=='blocked';
	});
	$manager->tenantEntitlementUsing(static fn(PanelTenant $tenant): string=>$tenant->name()==='south' ? 'active' : 'trialing');
	$context=$manager->tenantContext($request);
	$t->isTrue($context->isAuthorized());
	$t->same('north',$context->tenantKey());
	$t->same('membership',$context->source());
	$t->same('trialing',$context->entitlement()['status']);
	$t->same('access',$abilities[0][3]);
	$t->isTrue($manager->hasTenantRegistry());
	$t->isTrue($manager->hasTenant('north'));
	$t->same(null,$manager->tenant('../north'));
	$t->same(4,count($manager->tenants()));
	$isolatedManager=new PanelManager();
	$t->isFalse($isolatedManager->hasTenantRegistry());
	$t->same(null,$isolatedManager->tenant('north'));
	$t->same([],$isolatedManager->tenants());
	$t->isFalse($isolatedManager->tenantRegistry()===$manager->tenantRegistry());

	$switcher=$manager->tenantSwitcher($context->request());
	$t->same(['South','North'],array_column($switcher,'label'));
	$t->same([false,true],array_column($switcher,'current'));
	$t->same([true,true],array_column($switcher,'authorized'));
	$t->isFalse(in_array('Blocked',array_column($switcher,'label'),true));
	$t->isFalse(in_array('Hidden',array_column($switcher,'label'),true));
	$t->contains('switch',array_column($abilities,3));

	$unconfigured=$manager->switchTenant('south',$context->request());
	$t->same('persistence_unconfigured',$unconfigured->code());
	$t->same('south',$unconfigured->current()->tenantKey());
	$t->same('tenant_invalid',$manager->switchTenant('../south',$context->request())->code());
	$t->same('tenant_unknown',$manager->switchTenant('absent',$context->request())->code());
	$t->same('tenant_denied',$manager->switchTenant('blocked',$context->request())->code());
	$t->same('tenant_hidden',$manager->switchTenant('hidden',$context->request())->code());

	$persisted=[];
	$manager->tenantPersistenceUsing(static function(PanelTenantContext $next,PanelTenantContext $previous,PanelRequest $original)use(&$persisted): array {
		$persisted=[$next->tenantKey(),$previous->tenantKey(),$original->tenantKey()];
		return ['ok'=>true,'meta'=>['storage_token'=>'private','revision'=>2]];
	});
	$switched=$manager->switchTenant('south',$context->request());
	$t->isTrue($switched->ok());
	$t->same(['south','north','north'],$persisted);
	$t->same(2,$switched->meta()['revision']);
	$t->same('[redacted]',$switched->meta()['storage_token']);

	$manager->tenantPersistenceUsing(static fn(): bool=>false);
	$t->same('persistence_rejected',$manager->switchTenant('south',$context->request())->code());
	$manager->tenantPersistenceUsing(static function(): never { throw new RuntimeException('private persistence failure'); });
	$t->same('persistence_failed',$manager->switchTenant('south',$context->request())->code());
	$events=PanelTrace::events();
	$json=json_encode($events,JSON_THROW_ON_ERROR);
	$t->isFalse(str_contains($json,'private persistence failure'));
	$t->isFalse(str_contains($json,'north'));
	$t->contains('tenant.switch_error',array_column($events,'event'));

	$manager->tenantActiveUsing(static fn(): string=>'south');
	$t->same('resolver',$manager->tenantContext($request)->source());
	$manager->tenantActiveUsing(static fn(): string=>'../south');
	$t->same('active_resolution_invalid',$manager->tenantContext($request)->code());
	$manager->tenantActiveUsing(static function(): never { throw new RuntimeException('private active failure'); });
	$t->same('active_resolution_failed',$manager->tenantContext($request)->code());
})->tag('panel','tenant','registry','security')->group('framework-coverage');

test('tenant registry fails closed for malformed resolver authorization and entitlement responses',static function(Context $t): void {
	$request=PanelRequest::fromArray(['tenant'=>'alpha','user'=>['id'=>'operator']]);
	$manager=new PanelManager();
	$t->isFalse($manager->hasTenantRegistry());
	$t->same([],$manager->tenantSwitcher($request));
	$manager->registerTenant(['name'=>'alpha','label'=>'Alpha']);
	$t->throws(static fn()=>$manager->registerTenant(['name'=>'../bad']),InvalidArgumentException::class);
	$t->same([], $manager->tenantMemberships($request));
	$t->same('membership_denied',$manager->tenantContext($request)->code());

	$manager->tenantMembershipsUsing(static fn()=>new stdClass());
	$t->same([], $manager->tenantMemberships($request));
	$manager->tenantMembershipsUsing(static function(): never { throw new RuntimeException('private membership failure'); });
	$t->same([], $manager->tenantMemberships($request));
	$manager->tenantMembershipsUsing(static fn(): array=>[['tenant'=>'../bad'],['tenant'=>'alpha'],['tenant'=>'alpha']]);
	$t->same(1,count($manager->tenantMemberships($request)));

	$manager->tenantAuthorizationUsing(static function(): never { throw new RuntimeException('private authorization failure'); });
	$t->same('tenant_denied',$manager->tenantContext($request)->code());
	$manager->tenantAuthorizationUsing(static fn(): bool=>true);
	$manager->tenantEntitlementUsing(static fn()=>false);
	$t->same('entitlement_denied',$manager->tenantContext($request)->code());
	$manager->tenantEntitlementUsing(static fn()=>'typo-active');
	$t->same('entitlement_denied',$manager->tenantContext($request)->code());
	$manager->tenantEntitlementUsing(static fn()=>['status'=>'blocked','allowed'=>true,'api_key'=>'private']);
	$t->isTrue($manager->tenantContext($request)->isAuthorized());
	$t->same('[redacted]',$manager->tenantContext($request)->entitlement()['api_key']);
	$manager->tenantEntitlementUsing(static fn()=>new stdClass());
	$t->same('entitlement_denied',$manager->tenantContext($request)->code());
	$manager->tenantEntitlementUsing(static function(): never { throw new RuntimeException('private entitlement failure'); });
	$t->same('entitlement_denied',$manager->tenantContext($request)->code());

})->tag('panel','tenant','fail-closed')->group('framework-coverage');

test('tenant onboarding is idempotent and compensates completed effects in reverse order',static function(Context $t): void {
	$request=PanelRequest::fromArray(['user'=>['id'=>'operator']]);
	$events=[];
	$manager=new PanelManager();
	$manager
		->tenantOnboardingStep('prepare',static function(PanelTenant $tenant,PanelRequest $request,mixed $registry,mixed $resolvedManager,string $idempotencyKey)use(&$events): array { $events[]='apply:'.$tenant->name().':'.$idempotencyKey; return ['ok'=>true,'secret'=>'private']; },static function(PanelTenant $tenant,PanelRequest $request,mixed $registry,mixed $resolvedManager,array $meta,string $idempotencyKey)use(&$events): bool { $events[]='rollback:prepare:'.$idempotencyKey; return ($meta['ok'] ?? false)===true; })
		->tenantOnboardingStep('activate',static function()use(&$events): bool { $events[]='apply:activate'; return true; },static function()use(&$events): bool { $events[]='rollback:activate'; return false; })
		->tenantOnboardingStep('publish',static function()use(&$events): bool { $events[]='apply:publish'; return false; });
	$failed=$manager->onboardTenant(['name'=>'acme','label'=>'Acme'],$request,'request-1');
	$t->isFalse($failed->ok());
	$t->same('onboarding_failed',$failed->code());
	$t->same(['prepare','activate'],$failed->completed());
	$t->same(['prepare'],$failed->rolledBack());
	$t->same(['apply:acme:request-1','apply:activate','apply:publish','rollback:activate','rollback:prepare:request-1'],$events);
	$t->same(['rollback_rejected'],array_values(array_filter(array_column($failed->diagnostics(),'code'),static fn(string $code): bool=>$code==='rollback_rejected')));
	$t->same('publish',$failed->meta()['failed_step']);
	$t->isFalse($manager->hasTenant('acme'));
	$replay=$manager->onboardTenant(['name'=>'acme'],$request,'request-1');
	$t->isTrue($replay->replayed());
	$t->same($events,['apply:acme:request-1','apply:activate','apply:publish','rollback:activate','rollback:prepare:request-1']);
	$t->same('idempotency_conflict',$manager->onboardTenant(['name'=>'different'],$request,'request-1')->code());

	$successManager=new PanelManager();
	$successManager->tenantOnboardingStep('provision',static fn(): array=>['ok'=>true,'api_token'=>'private']);
	$success=$successManager->onboardTenant(PanelTenant::make('ready'),$request,'ready-key');
	$t->isTrue($success->ok());
	$t->isTrue($successManager->hasTenant('ready'));
	$t->same('[redacted]',$success->meta()['steps']['provision']['api_token']);
	$t->isTrue($successManager->onboardTenant(PanelTenant::make('ready'),$request,'ready-key')->replayed());
	$t->same('tenant_exists',$successManager->onboardTenant(PanelTenant::make('ready'),$request,'another-key')->code());
	$t->same('idempotency_key_required',$successManager->onboardTenant(PanelTenant::make('other'),$request,' ')->code());
	$t->same('tenant_invalid',$successManager->onboardTenant(['name'=>'../bad'],$request,'bad')->code());

	$rollbackManager=new PanelManager();
	$rollbackManager
		->tenantOnboardingStep('uncompensated',static fn(): bool=>true)
		->tenantOnboardingStep('throwing-rollback',static fn(): bool=>true,static function(): never { throw new RuntimeException('private rollback failure'); })
		->tenantOnboardingStep('throwing-apply',static function(): never { throw new RuntimeException('private apply failure'); });
	$rollbackFailure=$rollbackManager->onboardTenant(PanelTenant::make('rollback'),$request,'rollback-key');
	$t->same(['step_failed','rollback_failed','rollback_unavailable'],array_column($rollbackFailure->diagnostics(),'code'));
	$t->isFalse(str_contains(json_encode($rollbackFailure,JSON_THROW_ON_ERROR),'private'));

	$t->throws(static fn()=>(new PanelManager())->tenantOnboardingStep('...',static fn()=>true),InvalidArgumentException::class);

	$full=new PanelManager();
	$definitions=[];
	for($index=0;$index<100;$index++){ $definitions[]=['name'=>'tenant-'.$index]; }
	$t->same(100,count($full->registerTenants($definitions)));
	$t->throws(static fn()=>$full->registerTenant(PanelTenant::make('tenant-overflow')),OverflowException::class);
	$t->throws(static fn()=>$full->registerTenant(PanelTenant::make('../invalid')),InvalidArgumentException::class);
	$t->same('registry_full',$full->onboardTenant(PanelTenant::make('tenant-overflow'),$request,'full-registry')->code());
	$full->tenantMembershipsUsing(static function(): Generator { for($index=0;$index<101;$index++){ yield 'tenant-'.$index; } });
	$t->same(100,count($full->tenantMemberships($request)));
	$t->same(100,count($full->tenantRegistry()->describe($request)['visible_tenants']));
})->tag('panel','tenant','onboarding','rollback')->group('framework-coverage');

test('tenant storage scopes reject traversal reserved segments and cross tenant aliases',static function(Context $t): void {
	$scope=PanelTenantStorageScope::make('North',['uploads','invoices']);
	$t->same('north',$scope->tenant());
	$t->same(['uploads','invoices'],$scope->namespaceSegments());
	$t->same('tenants/north/uploads/invoices',$scope->relativeRoot());
	$t->same('north:uploads:invoices',$scope->namespaceKey());
	$t->same($scope->toArray(),$scope->jsonSerialize());
	$t->throws(static fn()=>PanelTenantStorageScope::make('../north'),InvalidArgumentException::class);
	foreach(['../private','%2e%2e/private',' con ','CON','.git','trailing.','bad:name',"bad\0name",str_repeat('a',101)] as $invalid){
		$t->throws(static fn()=>PanelTenantStorageScope::make('north',$invalid),InvalidArgumentException::class);
	}
	$t->throws(static fn()=>PanelTenantStorageScope::make('north',[new stdClass()]),InvalidArgumentException::class);

	$base=str_replace('/',DIRECTORY_SEPARATOR,$t->workspace('dataphyre-tenant')->root());
	$north=$base.DIRECTORY_SEPARATOR.'tenants'.DIRECTORY_SEPARATOR.'north';
	$south=$base.DIRECTORY_SEPARATOR.'tenants'.DIRECTORY_SEPARATOR.'south';
	$invoice=$north.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'invoices';
	$other=$south.DIRECTORY_SEPARATOR.'private';
	mkdir($invoice,0777,true);
	mkdir($other,0777,true);
	file_put_contents($invoice.DIRECTORY_SEPARATOR.'one.txt','north');
	file_put_contents($other.DIRECTORY_SEPARATOR.'south.txt','south');
	try{
		$t->same(realpath($invoice),$scope->filesystemRoot($base));
		$t->same($invoice.DIRECTORY_SEPARATOR.'new.txt',$scope->resolvePath($base,'new.txt'));
		$t->isTrue($scope->containsPath($base,$invoice.DIRECTORY_SEPARATOR.'one.txt'));
		$t->isFalse($scope->containsPath($base,$north));
		$t->isFalse($scope->containsPath($base,$other.DIRECTORY_SEPARATOR.'south.txt'));
		$t->isFalse($scope->containsPath($base,$base.DIRECTORY_SEPARATOR.'missing.txt'));
		$t->isFalse($scope->containsPath($base.DIRECTORY_SEPARATOR.'missing',$invoice));
		$t->throws(static fn()=>$scope->filesystemRoot($base.DIRECTORY_SEPARATOR.'missing'),InvalidArgumentException::class);
		$t->throws(static fn()=>PanelTenantStorageScope::make('missing')->filesystemRoot($base),RuntimeException::class);

		$link=$north.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'escape';
		if(@symlink($south,$link)){
			$t->throws(static fn()=>PanelTenantStorageScope::make('north',['uploads','escape'])->filesystemRoot($base),RuntimeException::class);
			@unlink($link);
		}
		$alias=$base.DIRECTORY_SEPARATOR.'tenants'.DIRECTORY_SEPARATOR.'alias';
		$aliasCreated=@symlink($south,$alias);
		if(!$aliasCreated && PHP_OS_FAMILY==='Windows'){
			exec('cmd /d /c mklink /J '.escapeshellarg($alias).' '.escapeshellarg($south),$junctionOutput,$junctionCode);
			$aliasCreated=$junctionCode===0 && is_dir($alias);
		}
		if($aliasCreated){
			$t->throws(static fn()=>PanelTenantStorageScope::make('alias')->filesystemRoot($base),RuntimeException::class);
			@unlink($alias);
			@rmdir($alias);
		}
	}
	finally{
		$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
		foreach($iterator as $entry){ $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname()); }
		@rmdir($base);
	}

	$manager=new PanelManager();
	$manager->registerTenant(PanelTenant::make('north'));
	$manager->tenantMembershipsUsing(static fn()=>['north']);
	$request=PanelRequest::fromArray(['tenant'=>'north']);
	$t->same('north:uploads',$manager->tenantStorageScope('north','uploads',$request)->namespaceKey());
	$t->throws(static fn()=>$manager->tenantStorageScope('unknown','uploads',$request),InvalidArgumentException::class);
	$manager->tenantAuthorizationUsing(static fn()=>false);
	$t->throws(static fn()=>$manager->tenantStorageScope('north','uploads',$request),DomainException::class);
})->tag('panel','tenant','storage','security')->group('framework-coverage');

test('tenant isolation propagates through search manifest facade instance and switcher rendering',static function(Context $t): void {
	$manager=new PanelManager();
	$instance=new PanelInstance('tenant-surface',$manager);
	$instance->registerTenants([
		PanelTenant::make('north')->label('North <script>')->url('/north')->badge('<b>2</b>'),
		PanelTenant::make('south')->label('South')->url('/south'),
	]);
	$instance
		->tenantMembershipsUsing(static fn()=>['north'])
		->tenantAuthorizationUsing(static fn(): bool=>true)
		->tenantActiveUsing(static fn(PanelRequest $resolved): ?string=>$resolved->tenantKey())
		->tenantPersistenceUsing(static fn(): bool=>true)
		->tenantEntitlementUsing(static fn(): bool=>true);
	$request=PanelRequest::fromArray(['tenant'=>'north','user'=>['id'=>'operator']]);
	$t->same($manager->tenantRegistry(),$instance->tenantRegistry());
	$t->same(1,count($instance->tenantMemberships($request)));
	$t->same('north',$instance->tenantContext($request)->tenantKey());
	$t->same('north',$instance->tenantDefinition('north')?->name());
	$t->isTrue($instance->hasTenant('north'));
	$t->same(2,count($instance->tenants()));
	$t->same('switched',$instance->switchTenant('north',$request)->code());
	$t->same(1,count($instance->tenantSwitcher($request)));
	$t->same('north:files',$instance->tenantStorageScope('north','files',$request)->namespaceKey());
	$instance->tenantOnboardingStep('instance-provision',static fn(): bool=>true);
	$t->isTrue($instance->onboardTenant(PanelTenant::make('west'),$request,'instance-west')->ok());
	$t->isTrue($instance->hasTenant('west'));

	$seen=[];
	$manager->registerSearchProvider(PanelSearchProvider::make('tenant-records')->tenantScoped()->searchUsing(static function(string $query,PanelRequest $resolved)use(&$seen): array {
		$seen[]=$resolved->tenantKey();
		return [['title'=>'North record','url'=>'/north/record']];
	}));
	PanelTrace::flush();
	$t->same(1,count($manager->globalSearchPage('record',$request)));
	$t->same(['north'],$seen);
	$t->same(0,count($manager->globalSearchPage('record',$request->withTenant('south'))));
	$t->same(['north'],$seen);
	$searchTrace=json_encode(PanelTrace::events(),JSON_THROW_ON_ERROR);
	$t->isFalse(str_contains($searchTrace,'"tenant":"north"'));
	$t->contains(hash('sha256','north'),$searchTrace);

	$manifest=TenantManifest::from($instance,$request,['api_key'=>'private'])->toArray();
	$t->same('north',$manifest['current']);
	$t->isTrue($manifest['active']);
	$t->isTrue($manifest['context']['authorized']);
	$t->same(1,count($manifest['tenants']));
	$t->same('north',$manifest['tenants'][0]['name']);
	$t->same('[redacted]',$manifest['meta']['api_key']);
	$t->isTrue($manifest['capabilities']['lifecycle']['manager_owned']);
	$t->isFalse($manifest['capabilities']['lifecycle']['implicit_io']);
	$t->isFalse($manifest['capabilities']['billing']['network_calls']);

	$html=$t->nonPublic(PanelRenderer::class)->invoke('tenantSwitcherHtml',[
		['authorized'=>false,'label'=>'Leak','url'=>'/leak'],
		['authorized'=>true,'label'=>'North <script>','url'=>'/north','current'=>true,'badge'=>'<b>2</b>'],
		['authorized'=>true,'label'=>'No target','url'=>'javascript:alert(1)'],
	],'horizontal');
	$t->contains('data-dp-panel-tenant-switcher',$html);
	$t->contains('dp-panel-tenant-switcher-horizontal',$html);
	$t->contains('dp-panel-horizontal-group',$html);
	$t->contains('dp-panel-horizontal-item',$html);
	$t->contains('North &lt;script&gt;',$html);
	$t->contains('&lt;b&gt;2&lt;/b&gt;',$html);
	$t->isFalse(str_contains($html,'Leak'));
	$t->isFalse(str_contains($html,'javascript'));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('tenantSwitcherHtml',[], 'invalid'));

	$facadePrefix='facade-tenant-'.bin2hex(random_bytes(4));
	$facadeNorth=$facadePrefix.'-north';
	$facadeSouth=$facadePrefix.'-south';
	$facadeReady=$facadePrefix.'-ready';
	$t->same(2,count(Panel::registerTenants([
		PanelTenant::make($facadeNorth)->url('/'.$facadeNorth),
		PanelTenant::make($facadeSouth)->url('/'.$facadeSouth),
	])));
	Panel::tenantMembershipsUsing(static fn()=>[$facadeNorth,$facadeSouth]);
	Panel::tenantAuthorizationUsing(static fn(): bool=>true);
	Panel::tenantActiveUsing(static fn(PanelRequest $resolved): ?string=>$resolved->tenantKey());
	Panel::tenantPersistenceUsing(static fn(): bool=>true);
	Panel::tenantEntitlementUsing(static fn(): string=>'active');
	Panel::tenantOnboardingStep('facade-provision',static fn(): bool=>true);
	$facadeRequest=PanelRequest::fromArray(['tenant'=>$facadeNorth,'user'=>['id'=>'facade-operator']]);
	$t->isTrue(Panel::tenantRegistry()->has($facadeNorth));
	$t->same(2,count(Panel::tenantMemberships($facadeRequest)));
	$t->same($facadeNorth,Panel::tenantContext($facadeRequest)->tenantKey());
	$t->same(2,count(Panel::tenantSwitcher($facadeRequest)));
	$t->isTrue(Panel::switchTenant($facadeSouth,$facadeRequest)->ok());
	$t->same($facadeNorth.':files',Panel::tenantStorageScope($facadeNorth,'files',$facadeRequest)->namespaceKey());
	$t->isTrue(Panel::onboardTenant(PanelTenant::make($facadeReady),$facadeRequest,'facade-ready')->ok());
	$t->same($facadeNorth,Panel::tenantDefinition($facadeNorth)?->name());
	$t->isTrue(Panel::hasTenant($facadeReady));
	$t->isTrue(count(Panel::tenants())>=3);
})->tag('panel','tenant','integration','search','rendering')->group('framework-coverage');
