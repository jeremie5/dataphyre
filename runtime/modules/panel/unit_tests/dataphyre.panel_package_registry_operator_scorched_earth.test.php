<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelFilesystemPackageRegistry;
use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelPackageAcquisitionPlan;
use Dataphyre\Panel\PanelPackageArtifactCache;
use Dataphyre\Panel\PanelPackageManifest;
use Dataphyre\Panel\PanelPackageRegistryCatalog;
use Dataphyre\Panel\PanelPackageRegistryIndex;
use Dataphyre\Panel\PanelPackageRegistryLoadPlan;
use Dataphyre\Panel\PanelPackageRegistryPublication;
use Dataphyre\Panel\PanelPackageRegistryPublisher;
use Dataphyre\Panel\PanelPackageResolver;
use Dataphyre\Panel\PanelPackageSignatureVerifier;
use Dataphyre\Panel\PanelPackageTemplate;
use Dataphyre\Panel\PanelPackageTrustPolicy;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelPlatformAssets;
use Dataphyre\Panel\PanelPlatformController;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();
if(!function_exists('Dataphyre\\Panel\\is_writable')){
	require_once __DIR__.'/fixtures/panel_registry_filesystem_failure.php';
}

/** @return array<string,mixed> */
function dp_panel_registry_operator_authority(): array {
	$keypair=sodium_crypto_sign_keypair();
	$secret=sodium_crypto_sign_secretkey($keypair);
	$public=sodium_crypto_sign_publickey($keypair);
	$keyId='registry_release';
	$publisher='example_publisher';
	$verifier=PanelPackageSignatureVerifier::make([
		$keyId=>['algorithm'=>'ed25519','public_key'=>base64_encode($public)],
	]);
	$policy=PanelPackageTrustPolicy::make([
		'require_signature'=>true,'allow_unknown_publishers'=>false,
		'trusted_publishers'=>[$publisher],'trusted_keys'=>[$keyId],
		'allowed_statuses'=>['stable'],
	]);
	return compact('secret','public','keyId','publisher','verifier','policy');
}

function dp_panel_registry_operator_template(array $authority, string $id, string $version, string $marker): PanelPackageTemplate {
	$manifest=PanelPackageManifest::from([
		'id'=>$id,'label'=>ucwords(str_replace('_', ' ', $id)),'version'=>$version,
		'description'=>'Workflow package '.$marker,'type'=>'integration','status'=>'stable',
		'provides'=>['workflow','operator_actions'],
		'links'=>[['label'=>'Documentation','target'=>'https://docs.example.test/'.$id]],
		'support'=>['owner'=>$authority['publisher']],
		'meta'=>['publisher'=>$authority['publisher'],'license'=>'MIT'],
	]);
	$template=PanelPackageTemplate::make($manifest)
		->plugin(false)->provider(false)->theme(false)->docs(false)->tests(false)->with('marketplace', false)
		->file('src/'.$id.'.php', '<?php /* '.$marker.' */ return true;');
	$payload=$authority['verifier']->payload($template);
	$manifest->signature([
		'algorithm'=>'ed25519','key_id'=>$authority['keyId'],'publisher'=>$authority['publisher'],
		'digest'=>hash('sha256', $payload),
		'signature'=>base64_encode(sodium_crypto_sign_detached($payload, $authority['secret'])),
	]);
	return $template;
}

function dp_panel_registry_operator_publisher(array $authority, int $now): PanelPackageRegistryPublisher {
	return PanelPackageRegistryPublisher::make(
		'example_registry',$authority['publisher'],$authority['keyId'],'ed25519',
		static fn(string $payload): string=>base64_encode(sodium_crypto_sign_detached($payload, $authority['secret'])),
		$authority['verifier'],$authority['policy'],static fn(): int=>$now,
		['ttl_seconds'=>3600,'max_packages'=>100,'max_bundle_bytes'=>1048576]
	);
}

test('package catalog query inputs normalize booleans and clamp only canonical limits',static function(Context $t):void{
	$query=$t->nonPublic(PanelPlatformController::class);
	$t->isTrue($query->invoke('packageQueryBoolean','yes'));
	$t->throws(static fn()=>$query->invoke('packageQueryBoolean','sometimes'),InvalidArgumentException::class);
	$t->same(25,$query->invoke('packageQueryLimit','25'));
	$t->same(100,$query->invoke('packageQueryLimit','999'));
	$t->throws(static fn()=>$query->invoke('packageQueryLimit','1000'),InvalidArgumentException::class);
	$t->throws(static fn()=>$query->invoke('packageQueryLimit',[]),InvalidArgumentException::class);
})->tag('panel','packages','registry','query','validation')->isolation('case');

test('registry publisher and filesystem operator close the signed publish discover resolve acquire loop', static function(Context $t): void {
	$authority=dp_panel_registry_operator_authority();$now=1800000000;
	$root=$t->tempDirectory('panel-package-registry-operator');
	$store=PanelFilesystemPackageRegistry::make($root, 'example_registry', $authority['publisher']);
	$publisher=dp_panel_registry_operator_publisher($authority, $now);
	$orders100=dp_panel_registry_operator_template($authority, 'orders_pack', '1.0.0', 'orders-v1-marker');
	$orders110=dp_panel_registry_operator_template($authority, 'orders_pack', '1.1.0', 'orders-v11-marker');
	$risk=dp_panel_registry_operator_template($authority, 'risk_pack', '1.0.0', 'risk-marker');
	$publication=$publisher->publish([
		['template'=>$orders100,'listing'=>['tags'=>['commerce'],'categories'=>['operations']]],
		['template'=>$orders110,'listing'=>['tags'=>['commerce','recommended'],'categories'=>['operations']]],
		['template'=>$risk,'yanked'=>true,'listing'=>['tags'=>['risk'],'categories'=>['governance']]],
	], 1, $store->locatorFactory());
	$t->same('example_registry', $publication->registry());
	$t->same(1, $publication->sequence());
	$t->same(3, $publication->toArray()['package_count']);
	$t->notContains('<?php /* orders-v11-marker */', json_encode($publication, JSON_THROW_ON_ERROR));
	$t->isFalse($publication->toArray()['index_body_serialized']);

	$receipt=$store->commit($publication);
	$t->isTrue($receipt['ok']);$t->isFalse($receipt['replayed']);$t->same(3, $receipt['package_count']);
	$replay=$store->commit($publication);
	$t->isTrue($replay['replayed']);
	$t->notContains($root, json_encode($store, JSON_THROW_ON_ERROR));
	$t->same(1, $store->jsonSerialize()['sequence']);

	$loaded=PanelPackageRegistryLoadPlan::make(
		$store->indexLocator(),$store,$authority['verifier'],$authority['policy'],['now'=>$now]
	)->load();
	$t->isTrue($loaded->ok(), implode('; ', $loaded->errors()));
	$index=$loaded->index();
	$t->notNull($index);
	$t->same('Orders Pack', $index->entries()[0]['listing']['label']);
	$t->same(['operator_actions','workflow'], $index->entries()[0]['listing']['provides']);

	$catalog=$store->catalog();
	$t->instanceOf(PanelPackageRegistryCatalog::class, $catalog);
	$discovery=$catalog->search('workflow', ['tag'=>'recommended']);
	$t->same(1, $discovery['total']);$t->same('1.1.0', $discovery['packages'][0]['version']);
	$t->same(1, $discovery['facets']['tags']['recommended']);
	$t->same('1.1.0', $catalog->latest('orders_pack')['version']);
	$t->same(2, count($catalog->versions('orders_pack')));
	$t->same(0, count($catalog->versions('risk_pack')));
	$t->same(1, count($catalog->versions('risk_pack', true)));
	$page=$catalog->search('', ['all_versions'=>true], null, 1);
	$t->same(2, $page['total']);$t->notNull($page['next_cursor']);
	$next=$catalog->search('', ['all_versions'=>true], $page['next_cursor'], 1);
	$t->same(1, $next['count']);

	$resolution=PanelPackageResolver::make($index)->resolve(['orders_pack'=>'*']);
	$t->isTrue($resolution->ok());
	$cache=PanelPackageArtifactCache::make($t->tempDirectory('panel-package-registry-cache'));
	$acquired=PanelPackageAcquisitionPlan::make(
		$resolution,$store,$cache,$authority['verifier'],$authority['policy'],['now'=>$now]
	)->acquire();
	$t->isTrue($acquired->ok(), implode('; ', $acquired->errors()));
	$t->same('1.1.0', $acquired->toArray()['packages'][0]['version']);

	$changes=$store->changesSince(0, 10);
	$t->same(2, count($changes['changes']));
	$t->same('package_registry.published', $changes['changes'][0]['type']);
})->tag('panel','packages','registry','marketplace','distribution','scorched-earth')->isolation('case')->maxMillis(8000);

test('registry operator rejects rollback equivocation foreign locators malformed listings and tampered objects', static function(Context $t): void {
	$authority=dp_panel_registry_operator_authority();$now=1800000000;
	$root=$t->tempDirectory('panel-package-registry-adversarial');
	$store=PanelFilesystemPackageRegistry::make($root, 'example_registry', $authority['publisher']);
	$publisher=dp_panel_registry_operator_publisher($authority, $now);
	$template=dp_panel_registry_operator_template($authority, 'secure_pack', '1.0.0', 'secure-marker');
	$first=$publisher->publish([['template'=>$template,'listing'=>['tags'=>['security']]]], 4, $store->locatorFactory());
	$store->commit($first);

	$t->throws(
		static fn()=>$publisher->publish([['template'=>$template,'listing'=>['links'=>[['label'=>'Unsafe','target'=>'http://example.test']]]]], 5, $store->locatorFactory()),
		LogicException::class
	);
	$foreign=$publisher->publish([$template], 5, static fn(array $artifact): string=>'https://packages.example.test/'.$artifact['sha256']);
	$t->throws(static fn()=>$store->commit($foreign), LogicException::class);
	$conflict=$publisher->publish([['template'=>$template,'yanked'=>true]], 4, $store->locatorFactory());
	$t->throws(static fn()=>$store->commit($conflict), LogicException::class);

	$second=$publisher->publish([$template], 5, $store->locatorFactory());
	$store->commit($second);
	$t->throws(static fn()=>$store->commit($first), LogicException::class);
	$oldCursor=$store->catalog()->search('', ['all_versions'=>true], null, 1)['next_cursor'];
	$t->same(null, $oldCursor);
	$unsafeEntry=$second->index()['packages'][0];
	$unsafeEntry['artifact']['body']='artifact-body-must-never-enter-catalog';
	$t->throws(
		static fn()=>new PanelPackageRegistryCatalog('example_registry',5,$second->digest(),[$unsafeEntry]),
		InvalidArgumentException::class
	);
	unset($unsafeEntry['artifact']['body']);
	$unsafeEntry['listing']['body']='listing-body-must-never-enter-catalog';
	$unsafeEntry['requirements']['body']='requirement-body-must-never-enter-catalog';
	$safeCatalog=new PanelPackageRegistryCatalog('example_registry',5,$second->digest(),[$unsafeEntry]);
	$t->notContains('must-never-enter-catalog',json_encode($safeCatalog->search(),JSON_THROW_ON_ERROR));
	$unsafeEntry['yanked']='true';
	$t->throws(
		static fn()=>new PanelPackageRegistryCatalog('example_registry',5,$second->digest(),[$unsafeEntry]),
		InvalidArgumentException::class
	);

	$manifest=PanelPackageManifest::from([
		'id'=>'mismatch_pack','label'=>'Mismatch','version'=>'1.0.0','status'=>'stable',
		'support'=>['owner'=>$authority['publisher']],'meta'=>['publisher'=>$authority['publisher']],
	]);
	$mismatch=PanelPackageTemplate::make($manifest)
		->plugin(false)->provider(false)->theme(false)->docs(false)->tests(false)->with('marketplace', false)
		->file('dataphyre-panel-package.json', '{}')->file('src/Feature.php', '<?php return true;');
	$payload=$authority['verifier']->payload($mismatch);
	$manifest->signature([
		'algorithm'=>'ed25519','key_id'=>$authority['keyId'],'publisher'=>$authority['publisher'],
		'digest'=>hash('sha256', $payload),'signature'=>base64_encode(sodium_crypto_sign_detached($payload, $authority['secret'])),
	]);
	$t->throws(static fn()=>$publisher->publish([$mismatch], 6, $store->locatorFactory()), InvalidArgumentException::class);

	$artifact=array_values($second->artifacts())[0];
	$object=$root.'/objects/sha256/'.substr($artifact['sha256'], 0, 2).'/'.$artifact['sha256'].'.object';
	$t->isTrue(is_file($object));
	$t->isTrue(file_put_contents($object, 'tampered')!==false);
	$response=$store->fetch($artifact['locator'], ['sha256'=>$artifact['sha256'],'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE]);
	$t->isFalse($response['ok']);$t->same(503, $response['status']);
	$t->isFalse($store->fetch('panel-registry://example_registry/objects/sha256/'.str_repeat('a', 64))['ok']);
	$t->isFalse($store->fetch($store->indexLocator(), ['content_type'=>'text/plain'])['ok']);
})->tag('panel','packages','registry','security','adversarial','scorched-earth')->isolation('case')->maxMillis(8000);

test('registry distribution is exposed through Panel surfaces and the production platform domain', static function(Context $t): void {
	$authority=dp_panel_registry_operator_authority();$now=1800000000;
	$signer=static fn(string $payload): string=>base64_encode(sodium_crypto_sign_detached($payload, $authority['secret']));
	$staticStore=Panel::filesystemPackageRegistry($t->tempDirectory('panel-registry-static-facade'), 'facade_packages', $authority['publisher']);
	$t->instanceOf(PanelFilesystemPackageRegistry::class, $staticStore);
	$staticPublisher=Panel::packageRegistryPublisher(
		'facade_packages',$authority['publisher'],$authority['keyId'],'ed25519',$signer,
		$authority['verifier'],$authority['policy'],static fn(): int=>$now
	);
	$t->instanceOf(PanelPackageRegistryPublisher::class, $staticPublisher);

	$surface=Panel::make('registry_surface');
	$instanceStore=$surface->filesystemPackageRegistry($t->tempDirectory('panel-registry-instance-facade'), 'instance_packages', $authority['publisher']);
	$t->instanceOf(PanelFilesystemPackageRegistry::class, $instanceStore);
	$t->instanceOf(PanelPackageRegistryPublisher::class, $surface->packageRegistryPublisher(
		'instance_packages',$authority['publisher'],$authority['keyId'],'ed25519',$signer,
		$authority['verifier'],$authority['policy'],static fn(): int=>$now
	));

	$platformRoot=$t->tempDirectory('panel-registry-platform');
	$config=['state_root'=>$platformRoot,'packages'=>['registry_id'=>'platform_packages','publisher'=>$authority['publisher']]];
	foreach(['operations','data','workflows','automation','authentication','notifications','media','localization','preferences','collaboration','relations','development','extensions'] as $domain){$config[$domain]=false;}
	$platform=PanelPlatform::defaults($config);
	$t->same($platform->packageRegistry(), $platform->packageTransport());
	$t->isTrue($platform->manifest()->available('packages'));
	$t->isTrue($platform->manifest()->configured('packages'));
	$t->isTrue($platform->manifest()->ready('packages'));
	$t->isTrue($platform->manifest()->domain('packages')['features']['publisher']);
	$readUser=['id'=>'registry-operator','permissions'=>['packages.view']];
	$emptyCatalog=$platform->packageCatalog();
	$t->isFalse($emptyCatalog->toArray()['published']);
	$t->same(null, $emptyCatalog->toArray()['index_digest']);
	$emptyPage=$platform->packagesPage(['base_url'=>'/platform/packages'],['method'=>'GET','user'=>$readUser]);
	$t->same(200,$emptyPage->status());
	$t->contains('No registry publication yet.',$emptyPage->content());
	$template=dp_panel_registry_operator_template($authority, 'platform_pack', '1.0.0', 'platform-marker');
	$publisher=Panel::packageRegistryPublisher(
		'platform_packages',$authority['publisher'],$authority['keyId'],'ed25519',$signer,
		$authority['verifier'],$authority['policy'],static fn(): int=>$now
	);
	$platform->packageRegistry()->commit($publisher->publish([$template], 1, $platform->packageRegistry()->locatorFactory()));
	$t->same('platform_pack', $platform->packageCatalog()->latest('platform_pack')['id']);
	$t->isTrue(in_array('packages', $platform->jsonSerialize()['metadata']['enabled_domains'], true));
	$page=$platform->packagesPage(['base_url'=>'/platform/packages'],['method'=>'GET','query'=>['q'=>'workflow','capability'=>'workflow'],'user'=>$readUser]);
	$t->same(200,$page->status());
	$t->contains('Package registry',$page->content());
	$t->contains('platform_pack@1.0.0',$page->content());
	$t->contains('Signed index verified',$page->content());
	$t->contains('role="search"',$page->content());
	$t->contains('data-dp-display="brick"',$page->content());
	$t->contains('aria-label="Package availability"',$page->content());
	$t->same(1,$page->data()['discovery']['total']);
	$t->notContains('panel-registry://',json_encode($page->data(),JSON_THROW_ON_ERROR));
	$t->notContains($platformRoot,json_encode($page,JSON_THROW_ON_ERROR));
	$css=PanelPlatformAssets::stylesheet();
	$t->contains('.dp-panel-commandbar.dp-package-catalog-filter{display:grid',$css);
	$t->contains('.dp-package-catalog-results{--dp-platform-min:21rem',$css);
	$t->contains('@container (max-width:26rem)',$css);
	$t->same(401,$platform->packagesPage([],['method'=>'GET'])->status());
	$t->same(403,$platform->packagesPage([],['method'=>'GET','user'=>['id'=>'reader','permissions'=>[]]])->status());
	$t->same(422,$platform->packagesPage([],['method'=>'GET','query'=>['cursor'=>'not-a-catalog-cursor'],'user'=>$readUser])->status());
	$xss=$platform->packagesPage([],['method'=>'GET','query'=>['q'=>'"><script>alert(1)</script>'],'user'=>$readUser]);
	$t->same(200,$xss->status());
	$t->notContains('<script>alert(1)</script>',$xss->content());

	$panel=Panel::make('package_registry_operator')->usePlatform($platform);
	$t->same($panel,$panel->mountPlatformPages(['domains'=>['packages'],'packages'=>['base_url'=>'/platform/packages']]));
	$t->same(1,count($panel->platformPages(['domains'=>['packages']])));
	$mounted=$panel->dispatch(['resource'=>'platform_packages','method'=>'GET','query'=>['q'=>'platform'],'user'=>$readUser]);
	$t->same(200,$mounted->status());
	$t->contains('platform_pack@1.0.0',$mounted->content());
	$t->same(405,$panel->dispatch(['resource'=>'platform_packages','method'=>'POST','user'=>$readUser])->status());
})->tag('panel','packages','registry','platform','facade','scorched-earth')->isolation('case')->maxMillis(8000);

test('registry publication value rejects every malformed byte and descriptor boundary', static function(Context $t): void {
	$authority=dp_panel_registry_operator_authority();$now=1800000000;
	$store=PanelFilesystemPackageRegistry::make($t->tempDirectory('panel-registry-publication-value'),'example_registry',$authority['publisher']);
	$template=dp_panel_registry_operator_template($authority,'publication_pack','1.0.0','publication-marker');
	$publication=dp_panel_registry_operator_publisher($authority,$now)->publish([$template],1,$store->locatorFactory());
	$index=$publication->index();$body=$publication->body();$artifacts=$publication->artifacts();$digest=array_key_first($artifacts);
	$canonical=$t->nonPublic(PanelPackageRegistryPublication::class);

	$t->throws(static fn()=>new PanelPackageRegistryPublication($index,'',$artifacts),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelPackageRegistryPublication($index,'{',$artifacts),InvalidArgumentException::class);
	$mismatch=$index;$mismatch['sequence']=2;
	$t->throws(static fn()=>new PanelPackageRegistryPublication($mismatch,$body,$artifacts),InvalidArgumentException::class);
	$incomplete=$index;unset($incomplete['packages']);$incompleteBody=$canonical->invoke('canonicalJson',$incomplete);
	$t->throws(static fn()=>new PanelPackageRegistryPublication($incomplete,$incompleteBody,$artifacts),InvalidArgumentException::class);
	$badArtifact=$artifacts;$badArtifact[$digest]['bytes']++;
	$t->throws(static fn()=>new PanelPackageRegistryPublication($index,$body,$badArtifact),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelPackageRegistryPublication($index,$body,[]),InvalidArgumentException::class);
	$badPackage=$index;$badPackage['packages'][0]['artifact']['locator']='panel-registry://example_registry/objects/sha256/'.str_repeat('f',64);
	$badPackageBody=$canonical->invoke('canonicalJson',$badPackage);
	$t->throws(static fn()=>new PanelPackageRegistryPublication($badPackage,$badPackageBody,$artifacts),InvalidArgumentException::class);

	$t->same($artifacts[$digest],$publication->artifact(' '.strtoupper($digest).' '));
	$t->same(null,$publication->artifact(str_repeat('0',64)));
	$t->same(1.25,$canonical->invoke('canonical',1.25));
	$t->throws(static fn()=>$canonical->invoke('canonical',NAN),InvalidArgumentException::class);
	$t->same($publication->toArray(),$publication->jsonSerialize());
})->tag('panel','packages','registry','publication','coverage','adversarial')->isolation('case')->maxMillis(8000);

test('registry publisher fails closed across signer package locator artifact and time boundaries', static function(Context $t): void {
	$authority=dp_panel_registry_operator_authority();$now=1800000000;
	$template=dp_panel_registry_operator_template($authority,'publisher_pack','1.0.0','publisher-marker');
	$locator=static fn(array $artifact):string=>'https://packages.example.test/'.$artifact['sha256'];
	$signer=static fn(string $payload):string=>base64_encode(sodium_crypto_sign_detached($payload,$authority['secret']));
	$t->throws(static fn()=>PanelPackageRegistryPublisher::make('example_registry',$authority['publisher'],$authority['keyId'],'unsupported',$signer,$authority['verifier'],$authority['policy']),InvalidArgumentException::class);
	$publisher=dp_panel_registry_operator_publisher($authority,$now);
	$t->throws(static fn()=>$publisher->publish([],1,$locator),LengthException::class);
	$t->throws(static fn()=>$publisher->publish([$template],1,$locator,['unknown'=>true]),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->publish([['template'=>$template,'unknown'=>true]],1,$locator),InvalidArgumentException::class);
	$t->throws(static fn()=>$publisher->publish([new stdClass()],1,$locator),InvalidArgumentException::class);

	$unsigned=PanelPackageTemplate::make(PanelPackageManifest::from([
		'id'=>'unsigned_pack','label'=>'Unsigned pack','version'=>'1.0.0','status'=>'stable',
		'support'=>['owner'=>$authority['publisher']],'meta'=>['publisher'=>$authority['publisher']],
	]));
	$t->throws(static fn()=>$publisher->publish([$unsigned],1,$locator),LogicException::class);
	$wrongAuthority=PanelPackageRegistryPublisher::make(
		'example_registry',$authority['publisher'],'another_registry_key','ed25519',$signer,
		$authority['verifier'],$authority['policy'],static fn():int=>$now
	);
	$t->throws(static fn()=>$wrongAuthority->publish([$template],1,$locator),LogicException::class);
	$t->throws(static fn()=>$publisher->publish([$template],1,static function():string{throw new RuntimeException('locator unavailable');}),RuntimeException::class);
	$t->throws(static fn()=>$publisher->publish([$template],1,static fn():string=>''),InvalidArgumentException::class);

	$throwingSigner=PanelPackageRegistryPublisher::make(
		'example_registry',$authority['publisher'],$authority['keyId'],'ed25519',
		static function():string{throw new RuntimeException('signer unavailable');},
		$authority['verifier'],$authority['policy'],static fn():int=>$now
	);
	$t->throws(static fn()=>$throwingSigner->publish([$template],1,$locator),RuntimeException::class);
	$emptySigner=PanelPackageRegistryPublisher::make(
		'example_registry',$authority['publisher'],$authority['keyId'],'ed25519',static fn():string=>'',
		$authority['verifier'],$authority['policy'],static fn():int=>$now
	);
	$t->throws(static fn()=>$emptySigner->publish([$template],1,$locator),UnexpectedValueException::class);

	$private=$t->nonPublic($publisher);$manifest=$template->package()->toArray();
	$t->throws(static fn()=>$private->invoke('bundleArtifacts',[['path'=>'missing-contents']],$manifest),InvalidArgumentException::class);
	$t->throws(static fn()=>$private->invoke('bundleArtifacts',[['path'=>'dataphyre-panel-package.json','contents'=>'{']],$manifest),InvalidArgumentException::class);
	$embedded=json_encode($manifest,JSON_THROW_ON_ERROR);
	$t->throws(static fn()=>$private->invoke('bundleArtifacts',[
		['path'=>'dataphyre-panel-package.json','contents'=>$embedded],
		['path'=>'dataphyre-panel-package.json','contents'=>$embedded],
	],$manifest),InvalidArgumentException::class);
	$t->throws(static fn()=>$private->invoke('listing',$manifest,['not-a-map']),InvalidArgumentException::class);
	$date=new DateTimeImmutable('@'.$now);
	$t->same($now,$private->invoke('epoch',$date,'generation time'));
	$t->same($now,$private->invoke('epoch','2027-01-15T08:00:00+00:00','generation time'));
	$t->throws(static fn()=>$private->invoke('epoch','','generation time'),InvalidArgumentException::class);
	$t->same(2.5,$private->invoke('canonical',2.5));
	$t->throws(static fn()=>$private->invoke('canonical',NAN),InvalidArgumentException::class);
	$t->same($publisher->toArray(),$publisher->jsonSerialize());
	$t->notContains('private',json_encode($publisher,JSON_THROW_ON_ERROR));
})->tag('panel','packages','registry','publisher','coverage','adversarial')->isolation('case')->maxMillis(8000);

test('registry catalog validates construction metadata factories cursors and safe serialization', static function(Context $t): void {
	$authority=dp_panel_registry_operator_authority();$now=1800000000;
	$store=PanelFilesystemPackageRegistry::make($t->tempDirectory('panel-registry-catalog-contracts'),'example_registry',$authority['publisher']);
	$template=dp_panel_registry_operator_template($authority,'catalog_pack','1.0.0','catalog-marker');
	$publication=dp_panel_registry_operator_publisher($authority,$now)->publish([$template],1,$store->locatorFactory());
	$entry=$publication->index()['packages'][0];$digest=$publication->digest();

	$t->throws(static fn()=>new PanelPackageRegistryCatalog('Bad Registry',1,$digest,[$entry]),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelPackageRegistryCatalog('example_registry',1,$digest,[$entry,$entry]),InvalidArgumentException::class);
	$fromPublication=PanelPackageRegistryCatalog::fromPublication($publication);
	$t->same('catalog_pack',$fromPublication->latest('catalog_pack')['id']);
	$verified=PanelPackageRegistryIndex::make($publication->body(),$authority['verifier'],$authority['policy'],['now'=>$now]);
	$t->isTrue($verified->ok());
	$t->same('catalog_pack',PanelPackageRegistryCatalog::fromIndex($verified)->latest('catalog_pack')['id']);
	$invalidIndex=PanelPackageRegistryIndex::make('{}',$authority['verifier'],$authority['policy'],['now'=>$now]);
	$t->throws(static fn()=>PanelPackageRegistryCatalog::fromIndex($invalidIndex),InvalidArgumentException::class);
	$t->same($fromPublication->toArray(),$fromPublication->jsonSerialize());
	$t->throws(static fn()=>$fromPublication->search('',['unknown_filter'=>'x']),InvalidArgumentException::class);

	$invalid=$entry;$invalid['dependencies']=[['invalid']];
	$t->throws(static fn()=>new PanelPackageRegistryCatalog('example_registry',1,$digest,[$invalid]),InvalidArgumentException::class);
	$invalid=$entry;$invalid['dependencies']=['Bad Name'=>'*'];
	$t->throws(static fn()=>new PanelPackageRegistryCatalog('example_registry',1,$digest,[$invalid]),InvalidArgumentException::class);
	$invalid=$entry;$invalid['dependencies']=['base_pack'=>''];
	$t->throws(static fn()=>new PanelPackageRegistryCatalog('example_registry',1,$digest,[$invalid]),InvalidArgumentException::class);
	$valid=$entry;$valid['dependencies']=['base_pack'=>'^1.0.0'];$valid['requirements']=['php'=>'>=8.4','panel'=>null,'modules'=>['core'=>'*'],'themes'=>[]];
	$metadataCatalog=new PanelPackageRegistryCatalog('example_registry',1,$digest,[$valid]);
	$t->same(['base_pack'=>'^1.0.0'],$metadataCatalog->latest('catalog_pack')['dependencies']);
	$t->same('>=8.4',$metadataCatalog->latest('catalog_pack')['requirements']['php']);
	$invalid=$entry;$invalid['requirements']=['not-a-map'];
	$t->throws(static fn()=>new PanelPackageRegistryCatalog('example_registry',1,$digest,[$invalid]),InvalidArgumentException::class);
	$invalid=$entry;$invalid['requirements']=['php'=>[]];
	$t->throws(static fn()=>new PanelPackageRegistryCatalog('example_registry',1,$digest,[$invalid]),InvalidArgumentException::class);
	$invalid=$entry;$invalid['listing']['label']=[];
	$t->throws(static fn()=>new PanelPackageRegistryCatalog('example_registry',1,$digest,[$invalid]),InvalidArgumentException::class);
	$invalid=$entry;$invalid['listing']['links']=[['label'=>'Unsafe','target'=>'http://example.test']];
	$t->throws(static fn()=>new PanelPackageRegistryCatalog('example_registry',1,$digest,[$invalid]),InvalidArgumentException::class);

	$second=$entry;$second['id']='second_pack';$second['listing']['label']='Second pack';
	$two=new PanelPackageRegistryCatalog('example_registry',1,$digest,[$entry,$second]);
	$firstPage=$two->search('',[],null,1);
	$t->notNull($firstPage['next_cursor']);
	$t->throws(static fn()=>$two->search('different-query',[],$firstPage['next_cursor'],1),LogicException::class);
	$private=$t->nonPublic($fromPublication);
	$t->same(3.5,$private->invoke('canonical',3.5));
	$t->throws(static fn()=>$private->invoke('canonical',NAN),InvalidArgumentException::class);
})->tag('panel','packages','registry','catalog','coverage','adversarial')->isolation('case')->maxMillis(8000);

test('filesystem registry fails closed across unsafe roots objects locks and persisted states', static function(Context $t): void {
	$authority=dp_panel_registry_operator_authority();$now=1800000000;
	$t->throws(static fn()=>new PanelFilesystemPackageRegistry('','example_registry',$authority['publisher']),InvalidArgumentException::class);
	$blockedParent=$t->tempDirectory('panel-registry-blocked-parent').'/file';file_put_contents($blockedParent,'not-a-directory');
	$t->throws(static fn()=>new PanelFilesystemPackageRegistry($blockedParent.'/registry','example_registry',$authority['publisher']),RuntimeException::class);
	$filesystem=$t->state('panel.package-registry.filesystem');
	$unwritable=$t->tempDirectory('panel-registry-unwritable');
	try{
		$filesystem->put('deny_writable_path',$unwritable);
		$t->throws(static fn()=>new PanelFilesystemPackageRegistry($unwritable,'example_registry',$authority['publisher']),RuntimeException::class);
	}
	finally{$filesystem->forget('deny_writable_path');}
	$unsafeRoot=$t->tempDirectory('panel-registry-unsafe-subdir');$linkTarget=$t->tempDirectory('panel-registry-link-target');symlink($linkTarget,$unsafeRoot.'/objects');
	$t->throws(static fn()=>new PanelFilesystemPackageRegistry($unsafeRoot,'example_registry',$authority['publisher']),RuntimeException::class);

	$template=dp_panel_registry_operator_template($authority,'filesystem_pack','1.0.0','filesystem-marker');
	$foreignStore=PanelFilesystemPackageRegistry::make($t->tempDirectory('panel-registry-foreign'),'foreign_registry',$authority['publisher']);
	$foreignPublication=dp_panel_registry_operator_publisher($authority,$now)->publish([$template],1,static fn(array $artifact):string=>'panel-registry://example_registry/objects/sha256/'.$artifact['sha256']);
	$t->throws(static fn()=>$foreignStore->commit($foreignPublication),LogicException::class);

	$bodyRoot=$t->tempDirectory('panel-registry-index-body');$bodyStore=PanelFilesystemPackageRegistry::make($bodyRoot,'example_registry',$authority['publisher']);
	$t->same(null,$bodyStore->indexBody());
	$bodyPublication=dp_panel_registry_operator_publisher($authority,$now)->publish([$template],1,$bodyStore->locatorFactory());
	$bodyStore->commit($bodyPublication);
	$t->same($bodyPublication->body(),$bodyStore->indexBody());

	$identityRoot=$t->tempDirectory('panel-registry-root-identity');$identityStore=PanelFilesystemPackageRegistry::make($identityRoot,'example_registry',$authority['publisher']);
	$identityPublication=dp_panel_registry_operator_publisher($authority,$now)->publish([$template],1,$identityStore->locatorFactory());
	$moved=$identityRoot.'-moved';rename($identityRoot,$moved);
	try{
		$t->same(503,$identityStore->fetch($identityStore->indexLocator())['status']);
		$t->throws(static fn()=>$identityStore->commit($identityPublication),RuntimeException::class);
	}
	finally{rename($moved,$identityRoot);}

	$directoryRoot=$t->tempDirectory('panel-registry-object-directory');$directoryStore=PanelFilesystemPackageRegistry::make($directoryRoot,'example_registry',$authority['publisher']);
	$directoryPublication=dp_panel_registry_operator_publisher($authority,$now)->publish([$template],1,$directoryStore->locatorFactory());
	$artifact=array_values($directoryPublication->artifacts())[0];$prefix=substr($artifact['sha256'],0,2);$outside=$t->tempDirectory('panel-registry-object-outside');
	symlink($outside,$directoryRoot.'/objects/sha256/'.$prefix);
	$t->throws(static fn()=>$directoryStore->commit($directoryPublication),RuntimeException::class);

	$renameRoot=$t->tempDirectory('panel-registry-object-rename');$renameStore=PanelFilesystemPackageRegistry::make($renameRoot,'example_registry',$authority['publisher']);
	$renamePublication=dp_panel_registry_operator_publisher($authority,$now)->publish([$template],1,$renameStore->locatorFactory());
	$artifact=array_values($renamePublication->artifacts())[0];$prefix=substr($artifact['sha256'],0,2);$objectDirectory=$renameRoot.'/objects/sha256/'.$prefix;mkdir($objectDirectory);
	mkdir($objectDirectory.'/'.$artifact['sha256'].'.object');
	$t->throws(static fn()=>$renameStore->commit($renamePublication),RuntimeException::class);

	$lockRoot=$t->tempDirectory('panel-registry-object-lock');$lockStore=PanelFilesystemPackageRegistry::make($lockRoot,'example_registry',$authority['publisher']);
	$lockPublication=dp_panel_registry_operator_publisher($authority,$now)->publish([$template],1,$lockStore->locatorFactory());
	mkdir($lockRoot.'/.objects.lock');
	$t->throws(static fn()=>$lockStore->commit($lockPublication),RuntimeException::class);

	$stateStore=PanelFilesystemPackageRegistry::make($t->tempDirectory('panel-registry-state-validation'),'example_registry',$authority['publisher']);
	$statePrivate=$t->nonPublic($stateStore);
	$t->throws(static fn()=>$statePrivate->invoke('assertState',['schema'=>'bad']),UnexpectedValueException::class);
	$invalidPublished=['schema'=>'panel_filesystem_package_registry','version'=>1,'registry'=>'example_registry','publisher'=>$authority['publisher'],'sequence'=>1,'publication_count'=>1,'index'=>null,'packages'=>[]];
	$t->throws(static fn()=>$statePrivate->invoke('assertState',$invalidPublished),UnexpectedValueException::class);
})->tag('panel','packages','registry','filesystem','coverage','adversarial')->isolation('case')->maxMillis(12000);
