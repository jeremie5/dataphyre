<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelPackageAcquisitionPlan;
use Dataphyre\Panel\PanelPackageArtifactCache;
use Dataphyre\Panel\PanelPackageManifest;
use Dataphyre\Panel\PanelPackageRegistryIndex;
use Dataphyre\Panel\PanelPackageRegistryLoadPlan;
use Dataphyre\Panel\PanelPackageRegistryLoadResult;
use Dataphyre\Panel\PanelPackageResolutionPlan;
use Dataphyre\Panel\PanelPackageResolver;
use Dataphyre\Panel\PanelPackageSignatureVerifier;
use Dataphyre\Panel\PanelPackageTemplate;
use Dataphyre\Panel\PanelPackageTransport;
use Dataphyre\Panel\PanelPackageTrustPolicy;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();
if(!class_exists(\dataphyre\core::class, false)){
	require_once dirname(__DIR__, 2).'/core/kernel/core_functions.php';
}
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
if(!function_exists('Dataphyre\\Panel\\link')){
	require_once __DIR__.'/fixtures/panel_distribution_link_failure.php';
}

/** @return array{secret:string,public:string,key_id:string,publisher:string,verifier:PanelPackageSignatureVerifier,policy:PanelPackageTrustPolicy} */
function dp_panel_distribution_authority(string $keyId='release', string $publisher='shopiro'): array {
	$keypair=sodium_crypto_sign_keypair();
	$public=sodium_crypto_sign_publickey($keypair);
	$secret=sodium_crypto_sign_secretkey($keypair);
	$verifier=PanelPackageSignatureVerifier::make([
		$keyId=>['algorithm'=>'ed25519','public_key'=>base64_encode($public)],
	]);
	$policy=PanelPackageTrustPolicy::make([
		'require_signature'=>true,
		'allow_unknown_publishers'=>false,
		'trusted_publishers'=>[$publisher],
		'trusted_keys'=>[$keyId],
		'allowed_statuses'=>['stable'],
	]);
	return compact('secret','public','keyId','publisher','verifier','policy')+['key_id'=>$keyId];
}

/** @return array{body:string,manifest:array,requirements:array} */
function dp_panel_distribution_bundle(array $authority, string $id, string $version='1.0.0', array $files=[], array $manifestOverrides=[]): array {
	$manifest=PanelPackageManifest::from(array_replace_recursive([
		'id'=>$id,'label'=>ucwords(str_replace('_', ' ', $id)),'version'=>$version,'status'=>'stable',
		'support'=>['owner'=>$authority['publisher']],'meta'=>['publisher'=>$authority['publisher']],
	], $manifestOverrides));
	$template=PanelPackageTemplate::make($manifest)
		->plugin(false)->provider(false)->theme(false)->docs(false)->tests(false)->with('marketplace', false);
	foreach($files!==[] ? $files : ['src/Feature.php'=>'<?php return true;'] as $path=>$contents){$template->file((string)$path, (string)$contents);}
	$payload=$authority['verifier']->payload($template);
	$manifest->signature([
		'algorithm'=>'ed25519','key_id'=>$authority['key_id'],'publisher'=>$authority['publisher'],
		'digest'=>hash('sha256', $payload),'signature'=>base64_encode(sodium_crypto_sign_detached($payload, $authority['secret'])),
	]);
	$manifestData=$manifest->toArray();
	$body=json_encode([
		'format'=>PanelPackageAcquisitionPlan::FORMAT,
		'package'=>$manifestData,
		'artifacts'=>$template->artifacts(),
	], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
	return ['body'=>$body,'manifest'=>$manifestData,'requirements'=>$manifestData['requirements']];
}

/** @return array<string,mixed> */
function dp_panel_distribution_entry(array $authority, string $id, string $version, string $body, array $dependencies=[], array $overrides=[]): array {
	$base=[
		'id'=>$id,'version'=>$version,'status'=>'stable','publisher'=>$authority['publisher'],'key_id'=>$authority['key_id'],
		'dependencies'=>$dependencies,'requirements'=>[],
		'artifact'=>[
			'locator'=>'registry://'.$id.'/'.$version.'?access_token=opaque',
			'sha256'=>hash('sha256', $body),'bytes'=>strlen($body),
			'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE,
		],
		'transparency'=>['log'=>'fixture','leaf'=>hash('sha256', $id.'@'.$version)],
	];
	return array_replace_recursive($base, $overrides);
}

/** @param array<int,array<string,mixed>> $entries @return array<string,mixed> */
function dp_panel_distribution_signed_index(array $authority, array $entries, int $sequence=7, int $now=1800000000, array $overrides=[]): array {
	$index=array_replace([
		'format'=>PanelPackageRegistryIndex::FORMAT,
		'registry'=>'shopiro_packages','publisher'=>$authority['publisher'],'sequence'=>$sequence,
		'generated_at'=>gmdate(DATE_ATOM, $now-60),'expires_at'=>gmdate(DATE_ATOM, $now+3600),
		'packages'=>$entries,'transparency'=>['log'=>'fixture','checkpoint'=>$sequence],
	], $overrides);
	unset($index['signature']);
	$payload=PanelPackageRegistryIndex::signaturePayload($index, $authority['verifier']);
	$index['signature']=[
		'algorithm'=>'ed25519','key_id'=>$authority['key_id'],'publisher'=>$authority['publisher'],
		'digest'=>hash('sha256', $payload),'signature'=>base64_encode(sodium_crypto_sign_detached($payload, $authority['secret'])),
	];
	return $index;
}

/** @return PanelPackageRegistryIndex */
function dp_panel_distribution_index(array $authority, array $entries, int $now=1800000000, array $indexOverrides=[], array $options=[]): PanelPackageRegistryIndex {
	return PanelPackageRegistryIndex::make(
		dp_panel_distribution_signed_index($authority, $entries, (int)($indexOverrides['sequence'] ?? 7), $now, $indexOverrides),
		$authority['verifier'], $authority['policy'],
		array_replace(['now'=>$now], $options)
	);
}

final class DpPanelDistributionTransport implements PanelPackageTransport {
	public int $calls=0;
	/** @param array<string,array<string,mixed>> $responses */
	public function __construct(public array $responses) {}
	public function fetch(string $locator, array $request=[]): array {
		$this->calls++;
		return $this->responses[$locator] ?? ['ok'=>false,'status'=>404];
	}
}

test('registry load plans are explicit, transport-neutral, offline-capable, and replay-safe', static function(Context $t): void {
	$authority=dp_panel_distribution_authority();
	$bundle=dp_panel_distribution_bundle($authority, 'registry_pack');
	$entry=dp_panel_distribution_entry($authority, 'registry_pack', '1.0.0', $bundle['body']);
	$payload=dp_panel_distribution_signed_index($authority, [$entry]);
	$body=json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	$locator='registry://signed-index?token=opaque';
	$transport=new DpPanelDistributionTransport([$locator=>[
		'ok'=>true,'status'=>200,'body'=>$body,'bytes'=>strlen($body),'sha256'=>hash('sha256', $body),
		'content_type'=>PanelPackageRegistryIndex::CONTENT_TYPE.'; charset=utf-8','content_encoding'=>'identity',
	]]);
	$plan=PanelPackageRegistryLoadPlan::make($locator, $transport, $authority['verifier'], $authority['policy'], [
		'now'=>1800000000,'meta'=>['access_token'=>'hidden'],
	]);
	$t->same(0, $transport->calls);
	$safePlan=json_encode($plan, JSON_THROW_ON_ERROR);
	$t->same(0, $transport->calls);
	$t->notContains($locator, $safePlan);
	$t->notContains('hidden', $safePlan);
	$loaded=$plan->load();
	$t->isTrue($loaded->ok());
	$t->same(1, $transport->calls);
	$t->same($body, $loaded->body());
	$t->notNull($loaded->index());
	$safe=json_encode($loaded, JSON_THROW_ON_ERROR);
	$t->notContains($locator, $safe);
	$t->notContains($body, $safe);

	$offline=PanelPackageRegistryLoadPlan::make($locator, $transport, $authority['verifier'], $authority['policy'], [
		'offline'=>true,'cached_body'=>$body,'now'=>1800000000,
	])->load();
	$t->isTrue($offline->ok());
	$t->same('cache', $offline->toArray()['source']);
	$t->same(1, $transport->calls);
	$miss=PanelPackageRegistryLoadPlan::make($locator, $transport, $authority['verifier'], $authority['policy'], ['offline'=>true,'now'=>1800000000])->load();
	$t->isFalse($miss->ok());
	$t->same(1, $transport->calls);

	$replay=PanelPackageRegistryLoadPlan::make($locator, $transport, $authority['verifier'], $authority['policy'], [
		'offline'=>true,'cached_body'=>$body,'now'=>1800000000,'minimum_sequence'=>8,'previous_digest'=>$loaded->index()->digest(),
	])->load();
	$t->isFalse($replay->ok());
	$wrongType=new DpPanelDistributionTransport([$locator=>['ok'=>true,'status'=>200,'body'=>$body,'content_type'=>'text/plain']]);
	$t->isFalse(PanelPackageRegistryLoadPlan::make($locator, $wrongType, $authority['verifier'], $authority['policy'], ['now'=>1800000000])->load()->ok());
});

test('signed registry indexes bind publisher metadata, trusted time, replay state, and safe CI output', static function(Context $t): void {
	$authority=dp_panel_distribution_authority();
	$bundle=dp_panel_distribution_bundle($authority, 'orders_pack');
	$entry=dp_panel_distribution_entry($authority, 'orders_pack', '1.0.0', $bundle['body']);
	$now=1800000000;
	$index=dp_panel_distribution_index($authority, [$entry], $now, [], [
		'require_transparency'=>true,
		'transparency_verifier'=>static fn(string $kind, array $subject, array $proof): bool=>in_array($kind, ['index','package'], true) && $subject!==[] && $proof!==[],
		'meta'=>['authorization'=>'Bearer secret'],
	]);
	$t->isTrue($index->ok());
	$t->same(7, $index->sequence());
	$safe=json_encode($index, JSON_THROW_ON_ERROR);
	$t->notContains('registry://', $safe);
	$t->notContains('Bearer secret', $safe);
	$t->contains('[REDACTED]', $safe);

	$replayed=PanelPackageRegistryIndex::make(dp_panel_distribution_signed_index($authority, [$entry], 6, $now), $authority['verifier'], $authority['policy'], [
		'now'=>$now,'minimum_sequence'=>7,'previous_digest'=>$index->digest(),
	]);
	$t->isFalse($replayed->ok());
	$t->contains('older than trusted host state', implode(' ', $replayed->errors()));

	$changedEntry=$entry;$changedEntry['artifact']['locator']='registry://orders_pack/alternate';
	$sequenceReuse=PanelPackageRegistryIndex::make(dp_panel_distribution_signed_index($authority, [$changedEntry], 7, $now), $authority['verifier'], $authority['policy'], [
		'now'=>$now,'minimum_sequence'=>7,'previous_digest'=>$index->digest(),
	]);
	$t->isFalse($sequenceReuse->ok());
	$t->contains('reuses a trusted sequence', implode(' ', $sequenceReuse->errors()));

	$stalePayload=dp_panel_distribution_signed_index($authority, [$entry], 8, $now, [
		'generated_at'=>gmdate(DATE_ATOM, $now-10000),'expires_at'=>gmdate(DATE_ATOM, $now-5000),
	]);
	$stale=PanelPackageRegistryIndex::make($stalePayload, $authority['verifier'], $authority['policy'], ['now'=>$now,'max_age_seconds'=>60]);
	$t->isFalse($stale->ok());
	$offline=PanelPackageRegistryIndex::make($stalePayload, $authority['verifier'], $authority['policy'], ['now'=>$now,'max_age_seconds'=>60,'offline'=>true,'allow_stale_cache'=>true]);
	$t->isTrue($offline->ok());
	$t->isTrue((bool)$offline->toArray()['stale']);
});

test('registry indexes fail closed on tampering, key confusion, archives, and missing transparency verification', static function(Context $t): void {
	$authority=dp_panel_distribution_authority();
	$bundle=dp_panel_distribution_bundle($authority, 'risk_pack');
	$entry=dp_panel_distribution_entry($authority, 'risk_pack', '1.0.0', $bundle['body']);
	$now=1800000000;
	$tampered=dp_panel_distribution_signed_index($authority, [$entry], 7, $now);
	$tampered['packages'][0]['artifact']['sha256']=str_repeat('a', 64);
	$tamperedIndex=PanelPackageRegistryIndex::make($tampered, $authority['verifier'], $authority['policy'], ['now'=>$now]);
	$t->isFalse($tamperedIndex->ok());
	$t->contains('cryptographic verification failed', implode(' ', $tamperedIndex->errors()));

	$keyMismatch=$entry;$keyMismatch['key_id']='attacker';
	$t->isFalse(dp_panel_distribution_index($authority, [$keyMismatch], $now)->ok());
	$archive=$entry;$archive['artifact']['archive']=true;
	$t->isFalse(dp_panel_distribution_index($authority, [$archive], $now)->ok());
	$invalidConstraint=$entry;$invalidConstraint['dependencies']=['unknown_pack'=>'latest'];
	$t->isFalse(dp_panel_distribution_index($authority, [$invalidConstraint], $now)->ok());
	$missingHook=dp_panel_distribution_index($authority, [$entry], $now, [], ['require_transparency'=>true]);
	$t->isFalse($missingHook->ok());
	$badHook=dp_panel_distribution_index($authority, [$entry], $now, [], ['require_transparency'=>true,'transparency_verifier'=>static fn(): bool=>false]);
	$t->isFalse($badHook->ok());
	$invalidVersion=$entry;$invalidVersion['version']='1.0.0-01';
	$t->isFalse(dp_panel_distribution_index($authority, [$invalidVersion], $now)->ok());
	foreach([
		'2030-01-01T00:00:00',
		'2030-02-30T00:00:00+00:00',
		'2030-01-01t00:00:00z',
		' 2030-01-01T00:00:00+00:00',
		'next Thursday',
	] as $invalidTimestamp){
		$invalidTime=dp_panel_distribution_signed_index($authority, [$entry], 7, $now, ['generated_at'=>$invalidTimestamp]);
		$t->isFalse(PanelPackageRegistryIndex::make($invalidTime, $authority['verifier'], $authority['policy'], ['now'=>$now])->ok());
	}
});

test('strict semantic-version precedence and zero-major caret ranges are deterministic', static function(Context $t): void {
	$t->isFalse(PanelPackageManifest::validVersion('1.0.0-01'));
	$t->isFalse(PanelPackageManifest::validVersion('01.0.0'));
	$t->isFalse(PanelPackageManifest::validVersion(' 1.0.0'));
	$t->isTrue(PanelPackageManifest::validVersion('1.0.0-alpha.1+build.01'));
	$ordered=['1.0.0-alpha','1.0.0-alpha.1','1.0.0-alpha.beta','1.0.0-beta','1.0.0-beta.2','1.0.0-beta.11','1.0.0-rc.1','1.0.0'];
	for($index=1;$index<count($ordered);$index++){$t->same(-1, PanelPackageManifest::compareVersions($ordered[$index-1], $ordered[$index]));}
	$t->same(0, PanelPackageManifest::compareVersions('1.0.0+build.1', '1.0.0+build.2'));
	$t->same(1, PanelPackageManifest::compareVersions('999999999999999999999.0.0', '2.0.0'));
	$t->isTrue(PanelPackageManifest::matchesConstraint('0.2.9', '^0.2.3'));
	$t->isFalse(PanelPackageManifest::matchesConstraint('0.3.0', '^0.2.3'));
	$t->isTrue(PanelPackageManifest::matchesConstraint('0.0.3', '^0.0.3'));
	$t->isFalse(PanelPackageManifest::matchesConstraint('0.0.4', '^0.0.3'));
	$t->isTrue(PanelPackageManifest::matchesConstraint('2.1.9', '^2.1'));
	$t->throws(static fn()=>PanelPackageManifest::compareVersions('latest', '1.0.0'), InvalidArgumentException::class);
});

test('resolver is deterministic, dependency-confined, lock-aware, and downgrade-safe', static function(Context $t): void {
	$authority=dp_panel_distribution_authority();
	$app1=dp_panel_distribution_bundle($authority, 'app_pack', '1.0.0');
	$app2=dp_panel_distribution_bundle($authority, 'app_pack', '2.0.0');
	$dep1=dp_panel_distribution_bundle($authority, 'dep_pack', '1.2.0');
	$dep2=dp_panel_distribution_bundle($authority, 'dep_pack', '2.3.0');
	$entries=[
		dp_panel_distribution_entry($authority, 'app_pack', '1.0.0', $app1['body'], ['dep_pack'=>'^1.0.0']),
		dp_panel_distribution_entry($authority, 'app_pack', '2.0.0', $app2['body'], ['dep_pack'=>'^2.0.0']),
		dp_panel_distribution_entry($authority, 'dep_pack', '1.2.0', $dep1['body']),
		dp_panel_distribution_entry($authority, 'dep_pack', '2.3.0', $dep2['body']),
	];
	$resolver=PanelPackageResolver::make(dp_panel_distribution_index($authority, $entries));
	$first=$resolver->resolve(['app_pack'=>'*']);
	$second=$resolver->resolve(['app_pack'=>'*']);
	$t->isTrue($first->ok());
	$t->same('2.0.0', $first->selected()['app_pack']['version']);
	$t->same('2.3.0', $first->selected()['dep_pack']['version']);
	$t->same($first->checksum(), $second->checksum());
	$t->same(['dep_pack','app_pack'], array_column($first->steps(), 'package'));

	$downgradeBlocked=$resolver->resolve(['app_pack'=>'=1.0.0'], ['app_pack'=>['version'=>'2.0.0','sha256'=>hash('sha256', $app2['body'])]]);
	$t->isFalse($downgradeBlocked->ok());
	$downgrade=$resolver->resolve(['app_pack'=>'=1.0.0'], ['app_pack'=>['version'=>'2.0.0','sha256'=>hash('sha256', $app2['body'])]], ['update'=>true,'allow_downgrade'=>true]);
	$t->isTrue($downgrade->ok());
	$t->same('downgrade', array_values(array_filter($downgrade->steps(), static fn(array $step): bool=>$step['package']==='app_pack'))[0]['action']);

	$app1Digest=hash('sha256', $app1['body']);
	$dep1Digest=hash('sha256', $dep1['body']);
	$frozenLock=[
		'app_pack'=>['version'=>'1.0.0','sha256'=>$app1Digest],
		'dep_pack'=>['version'=>'1.2.0','sha256'=>$dep1Digest],
	];
	$frozen=$resolver->resolve(['app_pack'=>'*'], [], ['update'=>true,'frozen'=>true,'lock'=>$frozenLock]);
	$t->isTrue($frozen->ok());
	$t->same('1.0.0', $frozen->selected()['app_pack']['version']);
	$frozenLock['app_pack']['sha256']=str_repeat('f', 64);
	$republished=$resolver->resolve(['app_pack'=>'*'], [], ['frozen'=>true,'lock'=>$frozenLock]);
	$t->isFalse($republished->ok());

	$confused=dp_panel_distribution_entry($authority, 'confused_pack', '1.0.0', dp_panel_distribution_bundle($authority, 'confused_pack')['body'], ['private_dep'=>'^1.0.0']);
	$confusionResolver=PanelPackageResolver::make(dp_panel_distribution_index($authority, [$confused]));
	$t->isFalse($confusionResolver->resolve(['confused_pack'=>'*'])->ok());
	$t->isFalse($resolver->resolve(['app_pack'=>'latest'])->ok());
	$t->isFalse($resolver->resolve(['app_pack'=>'*'], ['Bad Id'=>'1.0.0'])->ok());
});

test('resolver rejects authenticated dependency cycles', static function(Context $t): void {
	$authority=dp_panel_distribution_authority();
	$left=dp_panel_distribution_bundle($authority, 'left_pack');
	$right=dp_panel_distribution_bundle($authority, 'right_pack');
	$entries=[
		dp_panel_distribution_entry($authority, 'left_pack', '1.0.0', $left['body'], ['right_pack'=>'^1.0.0']),
		dp_panel_distribution_entry($authority, 'right_pack', '1.0.0', $right['body'], ['left_pack'=>'^1.0.0']),
	];
	$plan=PanelPackageResolver::make(dp_panel_distribution_index($authority, $entries))->resolve(['left_pack'=>'*']);
	$t->isFalse($plan->ok());
	$t->contains('cycle', implode(' ', $plan->errors()));
});

test('artifact cache is content-addressed, freshness-bound, idempotent, and redacted', static function(Context $t): void {
	$root=$t->workspace('panel-package-cache')->directory('cache');
	$cache=PanelPackageArtifactCache::make($root, ['api_token'=>'cache-secret']);
	$body='{"signed":true}';$digest=hash('sha256', $body);$now=1800000000;
	$t->isTrue($cache->ready());
	$t->isTrue($cache->write($digest, $body, PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE, ['authorization'=>'hidden'], ['now'=>$now]));
	$t->isTrue($cache->write($digest, $body, PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE, [], ['now'=>$now]));
	$read=$cache->read($digest, ['content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE,'now'=>$now]);
	$t->same($body, $read['body'] ?? null);
	$t->isFalse((bool)($read['stale'] ?? true));
	$t->isNull($cache->read($digest, ['content_type'=>'application/zip','now'=>$now]));
	$t->isNull($cache->read($digest, ['content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE,'now'=>$now+1000,'max_age_seconds'=>60]));
	$t->notNull($cache->read($digest, ['content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE,'now'=>$now+1000,'max_age_seconds'=>60,'allow_stale'=>true]));
	$t->isFalse($cache->write(str_repeat('a', 64), $body, PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE));
	$safe=json_encode($cache, JSON_THROW_ON_ERROR);
	$t->notContains($root, $safe);
	$t->notContains('cache-secret', $safe);

	$bodyPath=$root.DIRECTORY_SEPARATOR.substr($digest, 0, 2).DIRECTORY_SEPARATOR.$digest.'.package.json';
	$metaPath=$root.DIRECTORY_SEPARATOR.substr($digest, 0, 2).DIRECTORY_SEPARATOR.$digest.'.meta.json';
	$metadata=json_decode((string)file_get_contents($metaPath), true, 512, JSON_THROW_ON_ERROR);
	$metadata['stored_at']='tomorrow';
	file_put_contents($metaPath, json_encode($metadata, JSON_THROW_ON_ERROR));
	$t->isNull($cache->read($digest, ['content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE,'now'=>$now,'allow_stale'=>true]));
	file_put_contents($bodyPath, 'tampered');
	$t->isNull($cache->read($digest, ['content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE,'now'=>$now]));

	$outside=$t->workspace('panel-package-cache-junction')->directory('outside');
	$linkRoot=$t->workspace('panel-package-cache-junction-root')->directory('cache');
	$linkCache=PanelPackageArtifactCache::make($linkRoot);
	$linkBody='junction-escape';$linkDigest=hash('sha256', $linkBody);
	$linkPrefix=$linkRoot.DIRECTORY_SEPARATOR.substr($linkDigest, 0, 2);
	if(@symlink($outside, $linkPrefix)){
		$t->isFalse($linkCache->write($linkDigest, $linkBody, PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE, [], ['now'=>$now]));
		$t->same([], array_values(array_diff(scandir($outside) ?: [], ['.','..'])));
	}
});

test('explicit acquisition verifies transport bytes, caches safely, and hands off install and rollback', static function(Context $t): void {
	$authority=dp_panel_distribution_authority();
	$bundle=dp_panel_distribution_bundle($authority, 'install_pack');
	$entry=dp_panel_distribution_entry($authority, 'install_pack', '1.0.0', $bundle['body']);
	$index=dp_panel_distribution_index($authority, [$entry], 1800000000, [], [
		'require_transparency'=>true,'transparency_verifier'=>static fn(): bool=>true,
	]);
	$resolution=PanelPackageResolver::make($index)->resolve(['install_pack'=>'*']);
	$locator=$index->entries()[0]['artifact']['locator'];
	$transport=new DpPanelDistributionTransport([$locator=>[
		'ok'=>true,'status'=>200,'body'=>$bundle['body'],'bytes'=>strlen($bundle['body']),
		'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE.'; charset=utf-8','content_encoding'=>'identity',
		'headers'=>['authorization'=>'must-not-leak'],
	]]);
	$cache=PanelPackageArtifactCache::make($t->workspace('panel-package-acquire')->directory('cache'));
	$plan=PanelPackageAcquisitionPlan::make($resolution, $transport, $cache, $authority['verifier'], $authority['policy'], [
		'now'=>1800000000,'require_transparency'=>true,'transparency_verifier'=>static fn(): bool=>true,
		'meta'=>['password'=>'redacted'],
	]);
	$t->same(0, $transport->calls);
	$t->isTrue((bool)$plan->toArray()['ready']);
	$t->same(0, $transport->calls);
	$authority['verifier']->forgetKey('release');
	$result=$plan->acquire();
	$t->isTrue($result->ok());
	$t->same(1, $transport->calls);
	$t->instanceOf(PanelPackageTemplate::class, $result->template('install_pack'));
	$safe=json_encode($result, JSON_THROW_ON_ERROR);
	$t->notContains('registry://', $safe);
	$t->notContains('must-not-leak', $safe);
	$t->notContains('redacted', strtolower(str_replace('[REDACTED]', '', $safe)));

	$install=$result->installPlan('install_pack', '', ['overwrite_policy'=>'replace','signature_verifier'=>PanelPackageSignatureVerifier::make()]);
	$t->notNull($install);
	$t->isTrue((bool)$install->manifest()['ready']);
	$workspace=$t->workspace('panel-package-install-handoff');
	$target=$workspace->directory('target');$backups=$workspace->directory('backups');
	$applied=$install->apply($target, ['backup_root'=>$backups]);
	$t->isTrue($applied->ok());
	$rollback=$result->rollbackPlan($applied, ['source'=>'distribution']);
	$t->isTrue((bool)$rollback->manifest()['ready']);
	$rolledBack=$rollback->apply(['backup_root'=>$backups]);
	$t->isTrue($rolledBack->ok());

});

test('offline acquisition re-verifies cached signatures without calling transport', static function(Context $t): void {
	$authority=dp_panel_distribution_authority();
	$bundle=dp_panel_distribution_bundle($authority, 'offline_pack');
	$entry=dp_panel_distribution_entry($authority, 'offline_pack', '1.0.0', $bundle['body']);
	$index=dp_panel_distribution_index($authority, [$entry]);
	$resolution=PanelPackageResolver::make($index)->resolve(['offline_pack'=>'*']);
	$locator=$index->entries()[0]['artifact']['locator'];
	$transport=new DpPanelDistributionTransport([$locator=>[
		'ok'=>true,'status'=>200,'body'=>$bundle['body'],'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE,
	]]);
	$cache=PanelPackageArtifactCache::make($t->workspace('panel-package-offline')->directory('cache'));
	$online=PanelPackageAcquisitionPlan::make($resolution, $transport, $cache, $authority['verifier'], $authority['policy'], ['now'=>1800000000])->acquire();
	$t->isTrue($online->ok());
	$t->same(1, $transport->calls);
	$offline=PanelPackageAcquisitionPlan::make($resolution, $transport, $cache, $authority['verifier'], $authority['policy'], ['offline'=>true,'now'=>1800000000])->acquire();
	$t->isTrue($offline->ok());
	$t->same('cache', $offline->packages()[0]['source'] ?? null);
	$t->same(1, $transport->calls);
	$emptyCache=PanelPackageArtifactCache::make($t->workspace('panel-package-offline-empty')->directory('cache'));
	$miss=PanelPackageAcquisitionPlan::make($resolution, $transport, $emptyCache, $authority['verifier'], $authority['policy'], ['offline'=>true,'now'=>1800000000])->acquire();
	$t->isFalse($miss->ok());
	$t->same(1, $transport->calls);
});

test('first-party transparency revocation and publisher evidence gate registry resolution and acquisition without proof circularity',static function(Context $t):void{
	$authority=dp_panel_distribution_authority();$now=1800000000;$clockInstant=gmdate('Y-m-d\TH:i:s.000000\Z',$now);$clock=static function()use(&$clockInstant):string{return$clockInstant;};
	$logSigner=static fn(string $payload,string $keyId,string $role):string=>base64_encode(sodium_crypto_sign_detached($payload,$authority['secret']));
	$logVerifier=static function(string $payload,string $keyId,string $signature,string $role)use($authority):bool{$decoded=base64_decode($signature,true);return$keyId===$authority['key_id']&&is_string($decoded)&&sodium_crypto_sign_verify_detached($decoded,$payload,$authority['public']);};
	$root=$t->workspace('panel-package-first-party-marketplace')->directory('state');$log=new \Dataphyre\Panel\PanelPackageTransparencyLog($root.'/log','shopiro_public_log',$authority['key_id'],$logSigner,$clock);
	$networkVerifier=new \Dataphyre\Panel\PanelPackageTransparencyVerifier($logVerifier,['shopiro_public_log'],[],['allow_trust_on_first_use'=>true,'clock'=>$clock]);$network=new \Dataphyre\Panel\PanelPackageMarketplaceTrustNetwork($root.'/network',$networkVerifier,$clock,86400);
	$attestation=['attestation_id'=>'shopiro_identity_release','publisher'=>'shopiro','issuer'=>'independent_lab','category'=>'identity','signal'=>'verified','evidence_hash'=>str_repeat('a',64),'issued_at'=>$clockInstant,'valid_until'=>gmdate('Y-m-d\TH:i:s.000000\Z',$now+86400)];
	$network->ingest($log->append('publisher_attestation',$attestation));
	$bundle=dp_panel_distribution_bundle($authority,'transparent_pack');$entry=dp_panel_distribution_entry($authority,'transparent_pack','1.0.0',$bundle['body'],[],['transparency'=>[]]);
	$log->append('package_release',PanelPackageRegistryIndex::packageTransparencySubject('shopiro_packages',7,$entry),['consistency_from_size'=>1]);
	$unsigned=dp_panel_distribution_signed_index($authority,[$entry],7,$now,['transparency'=>[]]);unset($unsigned['signature']);
	$log->append('registry_index',PanelPackageRegistryIndex::indexTransparencySubject($unsigned),['consistency_from_size'=>2]);
	$entry['transparency']=$log->receipt(1,1)->jsonSerialize();$unsigned['packages']=[$entry];$unsigned['transparency']=$log->receipt(2,1)->jsonSerialize();
	$payload=PanelPackageRegistryIndex::signaturePayload($unsigned,$authority['verifier']);$unsigned['signature']=['algorithm'=>'ed25519','key_id'=>$authority['key_id'],'publisher'=>$authority['publisher'],'digest'=>hash('sha256',$payload),'signature'=>base64_encode(sodium_crypto_sign_detached($payload,$authority['secret']))];
	$network->ingest($entry['transparency']);$network->ingest($unsigned['transparency']);$t->isTrue($network->health()['complete']);
	$distributionVerifier=new \Dataphyre\Panel\PanelPackageTransparencyVerifier($logVerifier,['shopiro_public_log'],[],['allow_trust_on_first_use'=>true,'clock'=>$clock]);$proofCalls=[];$transparencyHook=static function(string $kind,array $subject,array $proof)use($distributionVerifier,&$proofCalls):bool{$ok=$distributionVerifier($kind,$subject,$proof);$proofCalls[]=['kind'=>$kind,'subject'=>$subject,'ok'=>$ok];return$ok;};
	$security=['now'=>$now,'require_transparency'=>true,'transparency_verifier'=>$transparencyHook,'require_revocation_check'=>true,'revocation_checker'=>$network->revocations(),'require_publisher_trust'=>true,'publisher_trust_resolver'=>$network->publishers()];
	$index=PanelPackageRegistryIndex::make($unsigned,$authority['verifier'],$authority['policy'],$security);$t->isTrue($index->ok(),implode('; ',$index->errors()).' '.json_encode($proofCalls));$t->isFalse($index->entries()[0]['revoked']);$t->same('observed',$index->entries()[0]['publisher_trust']['status']);
	$resolution=PanelPackageResolver::make($index)->resolve(['transparent_pack'=>'*']);$t->isTrue($resolution->ok());$locator=$index->entries()[0]['artifact']['locator'];$transport=new DpPanelDistributionTransport([$locator=>['ok'=>true,'status'=>200,'body'=>$bundle['body'],'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE]]);$cache=PanelPackageArtifactCache::make($t->workspace('panel-package-first-party-cache')->directory('cache'));
		$acquired=PanelPackageAcquisitionPlan::make($resolution,$transport,$cache,$authority['verifier'],$authority['policy'],$security)->acquire();$t->isTrue($acquired->ok());
		$t->same(1,$acquired->toArray()['activation_gate_count']);$t->isFalse($acquired->toArray()['activation_gates_serialized']);
		$install=$acquired->installPlan('transparent_pack');$t->notNull($install);$t->isTrue($install->manifest()['activation_gate']['allowed']);
		$clockInstant=gmdate('Y-m-d\TH:i:s.000000\Z',$now+60);$revocation=['revocation_id'=>'transparent_pack_incident','scope'=>'artifact','publisher'=>'shopiro','artifact_sha256'=>hash('sha256',$bundle['body']),'reason'=>'supply_chain_incident','effective_at'=>$clockInstant];$network->ingest($log->append('revocation',$revocation,['consistency_from_size'=>3]));$security['now']=$now+60;
		$activationRoot=$t->workspace('panel-package-revoked-after-acquisition')->directory('target');$activationBlocked=$install->apply($activationRoot);$t->isFalse($activationBlocked->ok());$t->same([],$activationBlocked->written());$t->isFalse(is_file($activationRoot.'/src/Feature.php'));$t->isFalse($activationBlocked->toArray()['meta']['activation_gate_passed']);
		$blocked=PanelPackageAcquisitionPlan::make($resolution,$transport,$cache,$authority['verifier'],$authority['policy'],$security)->acquire();$t->isFalse($blocked->ok());$t->contains('revoked',implode(' ',$blocked->errors()));
});

test('acquisition rejects transport tampering, MIME confusion, publisher mismatch, unsafe paths, and proof failure', static function(Context $t): void {
	$authority=dp_panel_distribution_authority();
	$bundle=dp_panel_distribution_bundle($authority, 'guarded_pack');
	$entry=dp_panel_distribution_entry($authority, 'guarded_pack', '1.0.0', $bundle['body']);
	$index=dp_panel_distribution_index($authority, [$entry]);
	$resolution=PanelPackageResolver::make($index)->resolve(['guarded_pack'=>'*']);
	$locator=$index->entries()[0]['artifact']['locator'];
	$workspace=$t->workspace('panel-package-acquisition-adversarial');

	foreach([
		['body'=>$bundle['body'].'x','content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE],
		['body'=>$bundle['body'],'content_type'=>'application/zip'],
		['body'=>$bundle['body'],'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE,'content_encoding'=>'gzip'],
	] as $case=>$response){
		$transport=new DpPanelDistributionTransport([$locator=>['ok'=>true,'status'=>200]+$response]);
		$cache=PanelPackageArtifactCache::make($workspace->directory('cache-'.$case));
		$result=PanelPackageAcquisitionPlan::make($resolution, $transport, $cache, $authority['verifier'], $authority['policy'], ['now'=>1800000000])->acquire();
		$t->isFalse($result->ok());
	}

	$unsafe=json_decode($bundle['body'], true, 128, JSON_THROW_ON_ERROR);
	$unsafe['artifacts'][]=['path'=>'../escape.php','contents'=>'bad','bytes'=>3];
	$unsafeBody=json_encode($unsafe, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	$unsafeEntry=dp_panel_distribution_entry($authority, 'guarded_pack', '1.0.0', $unsafeBody);
	$unsafeIndex=dp_panel_distribution_index($authority, [$unsafeEntry]);
	$unsafeResolution=PanelPackageResolver::make($unsafeIndex)->resolve(['guarded_pack'=>'*']);
	$unsafeLocator=$unsafeIndex->entries()[0]['artifact']['locator'];
	$unsafeTransport=new DpPanelDistributionTransport([$unsafeLocator=>['ok'=>true,'status'=>200,'body'=>$unsafeBody,'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE]]);
	$unsafeResult=PanelPackageAcquisitionPlan::make($unsafeResolution, $unsafeTransport, PanelPackageArtifactCache::make($workspace->directory('unsafe-cache')), $authority['verifier'], $authority['policy'], ['now'=>1800000000])->acquire();
	$t->isFalse($unsafeResult->ok());
	$t->isFalse(is_file(dirname($workspace->directory('unsafe-target')).DIRECTORY_SEPARATOR.'escape.php'));

	$other=dp_panel_distribution_authority('release', 'attacker');
	// Sign with the trusted release bytes but claim another publisher.
	$other['secret']=$authority['secret'];$other['public']=$authority['public'];$other['verifier']=$authority['verifier'];
	$mismatchBundle=dp_panel_distribution_bundle($other, 'guarded_pack');
	$mismatchEntry=dp_panel_distribution_entry($authority, 'guarded_pack', '1.0.0', $mismatchBundle['body']);
	$mismatchIndex=dp_panel_distribution_index($authority, [$mismatchEntry]);
	$mismatchResolution=PanelPackageResolver::make($mismatchIndex)->resolve(['guarded_pack'=>'*']);
	$mismatchLocator=$mismatchIndex->entries()[0]['artifact']['locator'];
	$mismatchTransport=new DpPanelDistributionTransport([$mismatchLocator=>['ok'=>true,'status'=>200,'body'=>$mismatchBundle['body'],'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE]]);
	$mismatch=PanelPackageAcquisitionPlan::make($mismatchResolution, $mismatchTransport, PanelPackageArtifactCache::make($workspace->directory('mismatch-cache')), $authority['verifier'], $authority['policy'], ['now'=>1800000000])->acquire();
	$t->isFalse($mismatch->ok());

	$proofTransport=new DpPanelDistributionTransport([$locator=>['ok'=>true,'status'=>200,'body'=>$bundle['body'],'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE]]);
	$proof=PanelPackageAcquisitionPlan::make($resolution, $proofTransport, PanelPackageArtifactCache::make($workspace->directory('proof-cache')), $authority['verifier'], $authority['policy'], [
		'now'=>1800000000,'require_transparency'=>true,'transparency_verifier'=>static fn(): bool=>false,
	])->acquire();
	$t->isFalse($proof->ok());
});

test('zz distribution guarded branches stay fail closed under malformed adapters and metadata', static function(Context $t): void {
	$authority=dp_panel_distribution_authority();$now=1800000000;
	$bundle=dp_panel_distribution_bundle($authority, 'coverage_pack');
	$entry=dp_panel_distribution_entry($authority, 'coverage_pack', '1.0.0', $bundle['body']);
	$index=dp_panel_distribution_index($authority, [$entry], $now);
	$t->same('shopiro', $index->publisher());

	$invalidJson=PanelPackageRegistryIndex::make('{', $authority['verifier'], $authority['policy'], ['now'=>$now]);
	$t->isFalse($invalidJson->ok());
	$unserializable=PanelPackageRegistryIndex::make(['bad'=>new stdClass()], $authority['verifier'], $authority['policy'], ['now'=>$now]);
	$t->isFalse($unserializable->ok());
	$floatIndex=dp_panel_distribution_index($authority, [$entry], $now, ['diagnostic_ratio'=>1.5]);
	$t->isTrue($floatIndex->ok());
	$publisherMismatch=dp_panel_distribution_signed_index($authority, [$entry], 7, $now);
	$publisherMismatch['signature']['publisher']='attacker';
	$t->isFalse(PanelPackageRegistryIndex::make($publisherMismatch, $authority['verifier'], $authority['policy'], ['now'=>$now])->ok());
	$empty=PanelPackageRegistryIndex::make(dp_panel_distribution_signed_index($authority, [], 7, $now), $authority['verifier'], $authority['policy'], ['now'=>$now]);
	$t->isFalse($empty->ok());
	$badTime=dp_panel_distribution_signed_index($authority, [$entry], 7, $now, ['generated_at'=>'not-a-time']);
	$t->isFalse(PanelPackageRegistryIndex::make($badTime, $authority['verifier'], $authority['policy'], ['now'=>$now])->ok());
	$throwingProof=dp_panel_distribution_index($authority, [$entry], $now, [], [
		'require_transparency'=>true,'transparency_verifier'=>static function(): bool { throw new RuntimeException('proof unavailable'); },
	]);
	$t->isFalse($throwingProof->ok());
	$throwingTrust=dp_panel_distribution_index($authority,[$entry],$now,[],[
		'require_revocation_check'=>true,'revocation_checker'=>static fn():never=>throw new RuntimeException('revocations unavailable'),
		'require_publisher_trust'=>true,'publisher_trust_resolver'=>static fn():never=>throw new RuntimeException('publisher evidence unavailable'),
	]);
	$t->isFalse($throwingTrust->ok());

	$complexEntry=$entry;
	$complexEntry['requirements']=[
		'php'=>'>=8.1.0','panel'=>null,'reactor'=>null,
		'modules'=>['core'=>'^1.0.0'],'themes'=>['default'],
	];
	$t->isTrue(dp_panel_distribution_index($authority, [$complexEntry], $now)->ok());

	$throwingTransport=new class implements PanelPackageTransport {
		public function fetch(string $locator, array $request=[]): array { throw new RuntimeException('transport unavailable'); }
	};
	$loadFailure=PanelPackageRegistryLoadPlan::make('registry://throw', $throwingTransport, $authority['verifier'], $authority['policy'], ['now'=>$now])->load();
	$t->isFalse($loadFailure->ok());
	$t->notSame([], $loadFailure->errors());
	$t->isFalse(PanelPackageRegistryLoadResult::make($index, '{', 'cache')->ok());
	$t->isFalse(PanelPackageRegistryLoadResult::make($index, '{"overflow":1e400}', 'cache')->ok());

	$version1=dp_panel_distribution_bundle($authority, 'version_pack', '1.0.0');
	$version11=dp_panel_distribution_bundle($authority, 'version_pack', '1.1.0');
	$version2=dp_panel_distribution_bundle($authority, 'version_pack', '2.0.0');
	$versionEntries=[
		dp_panel_distribution_entry($authority, 'version_pack', '1.0.0', $version1['body']),
		dp_panel_distribution_entry($authority, 'version_pack', '1.1.0', $version11['body']),
		dp_panel_distribution_entry($authority, 'version_pack', '2.0.0', $version2['body']),
	];
	$versionResolver=PanelPackageResolver::make(dp_panel_distribution_index($authority, $versionEntries, $now));
	$pinned=$versionResolver->resolve(['version_pack'=>'*'], [], ['pinned'=>['version_pack'=>'=1.0.0']]);
	$t->isTrue($pinned->ok());
	$t->same('1.0.0', $pinned->selected()['version_pack']['version']);
	$reinstall=$versionResolver->resolve(['version_pack'=>'=1.0.0'], ['version_pack'=>'1.0.0']);
	$t->same('reinstall', $reinstall->steps()[0]['action'] ?? null);
	$keep=$versionResolver->resolve(['version_pack'=>'=1.0.0'], ['version_pack'=>['version'=>'1.0.0','sha256'=>hash('sha256', $version1['body'])]]);
	$t->same('keep', $keep->steps()[0]['action'] ?? null);
	$defaultPinned=$versionResolver->resolve(['version_pack'=>'*'], ['version_pack'=>'1.0.0'], ['allow_major_updates'=>true]);
	$t->isTrue($defaultPinned->ok());
	$t->same('1.0.0', $defaultPinned->selected()['version_pack']['version'] ?? null);
	$lockedPinned=$versionResolver->resolve(['version_pack'=>'*'], [], ['lock'=>['version_pack'=>'1.1.0']]);
	$t->isTrue($lockedPinned->ok());
	$t->same('1.1.0', $lockedPinned->selected()['version_pack']['version'] ?? null);
	$explicitUpdate=$versionResolver->resolve(['version_pack'=>'*'], ['version_pack'=>'1.0.0'], ['update'=>true,'allow_major_updates'=>true]);
	$t->isTrue($explicitUpdate->ok());
	$t->same('2.0.0', $explicitUpdate->selected()['version_pack']['version'] ?? null);
	$t->isFalse($versionResolver->resolve(['version_pack'=>'=2.0.0'], ['version_pack'=>'1.0.0'])->ok());
	$t->isTrue($versionResolver->resolve(['version_pack'=>'=2.0.0'], ['version_pack'=>'1.0.0'], ['update'=>true,'allow_major_updates'=>true])->ok());
	$t->isFalse($versionResolver->resolve(['version_pack'=>'=1.1.0'], ['version_pack'=>'1.0.0'], ['update'=>true,'allow_minor_updates'=>false])->ok());
	$t->isTrue($versionResolver->resolve(['version_pack'=>'=1.1.0'], ['version_pack'=>'1.0.0'], ['update'=>true,'allow_minor_updates'=>true])->ok());
	$t->isFalse($versionResolver->resolve(['version_pack'=>'=1.0.0'], [], ['max_attempts'=>1])->ok());
	$t->isTrue($versionResolver->resolve(['version_pack'=>'=1.0.0'], [], ['max_attempts'=>3])->ok());
	$t->isFalse($versionResolver->resolve(['version_pack'=>'*'], [], ['frozen'=>true,'lock'=>['version_pack'=>['version'=>'1.0.0']]])->ok());
	json_encode($pinned, JSON_THROW_ON_ERROR);

	$workspace=$t->workspace('panel-package-guarded-branches');
	$invalidRootCache=PanelPackageArtifactCache::make('');
	$t->isFalse($invalidRootCache->ready());
	$t->notSame([], $invalidRootCache->errors());
	$linkCache=PanelPackageArtifactCache::make($workspace->directory('link-cache'));
	$linkBody='link-failure-body';$linkDigest=hash('sha256', $linkBody);
	$t->state('panel.package-distribution.link',['fail'=>true]);
	$t->isFalse($linkCache->write($linkDigest, $linkBody, PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE, [], ['now'=>$now]));
	$largeMetaBody='large-metadata-body';
	$t->isFalse($linkCache->write(hash('sha256', $largeMetaBody), $largeMetaBody, PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE, ['note'=>str_repeat('x', 70000)], ['now'=>$now]));

	$resolution=PanelPackageResolver::make($index)->resolve(['coverage_pack'=>'*']);
	$t->same($index->digest(),$resolution->indexDigest());
	$locator=$index->entries()[0]['artifact']['locator'];
	$cache=PanelPackageArtifactCache::make($workspace->directory('acquisition-cache'));
	$validTransport=new DpPanelDistributionTransport([$locator=>['ok'=>true,'status'=>200,'body'=>$bundle['body'],'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE]]);
	$validAcquisition=PanelPackageAcquisitionPlan::make($resolution, $validTransport, $cache, $authority['verifier'], $authority['policy'], ['now'=>$now]);
	json_encode($validAcquisition, JSON_THROW_ON_ERROR);
	$t->throws(static fn()=>$t->nonPublic($validAcquisition)->invoke('canonicalize', new stdClass()), InvalidArgumentException::class);
	$gateEntry=$resolution->selected()['coverage_pack'];$gate=$t->nonPublic(PanelPackageAcquisitionPlan::class);
	$missingRevocations=$gate->invoke('marketplaceDecision',$resolution->registry(),$gateEntry,['require_revocation_check'=>true]);$t->contains('revocation_checker_unavailable',$missingRevocations['reason_codes']);
	$failedRevocations=$gate->invoke('marketplaceDecision',$resolution->registry(),$gateEntry,['revocation_checker'=>static fn():never=>throw new RuntimeException('revocations unavailable')]);$t->contains('revocation_checker_unavailable',$failedRevocations['reason_codes']);
	$missingPublisher=$gate->invoke('marketplaceDecision',$resolution->registry(),$gateEntry,['require_publisher_trust'=>true]);$t->contains('publisher_evidence_unavailable',$missingPublisher['reason_codes']);
	$failedPublisher=$gate->invoke('marketplaceDecision',$resolution->registry(),$gateEntry,['publisher_trust_resolver'=>static fn():never=>throw new RuntimeException('publisher evidence unavailable')]);$t->contains('publisher_evidence_unavailable',$failedPublisher['reason_codes']);

	$invalidResolution=PanelPackageResolutionPlan::make([
		'ok'=>true,'registry'=>'fixture','sequence'=>1,'index_digest'=>str_repeat('a',64),
		'selected'=>['broken_pack'=>['id'=>'broken_pack','version'=>'1.0.0','artifact'=>[]]],'steps'=>[],'errors'=>[],
	]);
	$invalidAcquisition=PanelPackageAcquisitionPlan::make($invalidResolution, $validTransport, $cache, $authority['verifier'], $authority['policy'], ['now'=>$now])->acquire();
	$t->isFalse($invalidAcquisition->ok());
	$t->notSame([], $invalidAcquisition->errors());

	$throwPlan=PanelPackageAcquisitionPlan::make($resolution, $throwingTransport, PanelPackageArtifactCache::make($workspace->directory('throw-cache')), $authority['verifier'], $authority['policy'], ['now'=>$now]);
	$t->isFalse($throwPlan->acquire()->ok());

	$badJsonBody='{';
	$badJsonEntry=dp_panel_distribution_entry($authority, 'bad_json_pack', '1.0.0', $badJsonBody);
	$badJsonIndex=dp_panel_distribution_index($authority, [$badJsonEntry], $now);
	$badJsonResolution=PanelPackageResolver::make($badJsonIndex)->resolve(['bad_json_pack'=>'*']);
	$badJsonLocator=$badJsonIndex->entries()[0]['artifact']['locator'];
	$badJsonTransport=new DpPanelDistributionTransport([$badJsonLocator=>['ok'=>true,'status'=>200,'body'=>$badJsonBody,'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE]]);
	$t->isFalse(PanelPackageAcquisitionPlan::make($badJsonResolution, $badJsonTransport, PanelPackageArtifactCache::make($workspace->directory('bad-json-cache')), $authority['verifier'], $authority['policy'], ['now'=>$now])->acquire()->ok());

	$badManifest=json_decode($bundle['body'], true, 128, JSON_THROW_ON_ERROR);
	foreach($badManifest['artifacts'] as &$artifact){if(($artifact['path'] ?? '')==='dataphyre-panel-package.json'){$artifact['contents']='{';$artifact['bytes']=1;}}
	unset($artifact);
	$badManifestBody=json_encode($badManifest, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	$badManifestEntry=dp_panel_distribution_entry($authority, 'coverage_pack', '1.0.0', $badManifestBody);
	$badManifestIndex=dp_panel_distribution_index($authority, [$badManifestEntry], $now);
	$badManifestResolution=PanelPackageResolver::make($badManifestIndex)->resolve(['coverage_pack'=>'*']);
	$badManifestLocator=$badManifestIndex->entries()[0]['artifact']['locator'];
	$badManifestTransport=new DpPanelDistributionTransport([$badManifestLocator=>['ok'=>true,'status'=>200,'body'=>$badManifestBody,'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE]]);
	$t->isFalse(PanelPackageAcquisitionPlan::make($badManifestResolution, $badManifestTransport, PanelPackageArtifactCache::make($workspace->directory('bad-manifest-cache')), $authority['verifier'], $authority['policy'], ['now'=>$now])->acquire()->ok());

	$mismatchBundle=dp_panel_distribution_bundle($authority, 'requirements_pack', '1.0.0', [], [
		'requirements'=>['php'=>'>=8.1.0'],'meta'=>['publisher'=>'shopiro','diagnostic_ratio'=>1.5],
	]);
	$mismatchEntry=dp_panel_distribution_entry($authority, 'requirements_pack', '1.0.0', $mismatchBundle['body']);
	$mismatchIndex=dp_panel_distribution_index($authority, [$mismatchEntry], $now);
	$mismatchResolution=PanelPackageResolver::make($mismatchIndex)->resolve(['requirements_pack'=>'*']);
	$mismatchLocator=$mismatchIndex->entries()[0]['artifact']['locator'];
	$mismatchTransport=new DpPanelDistributionTransport([$mismatchLocator=>['ok'=>true,'status'=>200,'body'=>$mismatchBundle['body'],'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE]]);
	$t->isFalse(PanelPackageAcquisitionPlan::make($mismatchResolution, $mismatchTransport, PanelPackageArtifactCache::make($workspace->directory('requirements-cache')), $authority['verifier'], $authority['policy'], ['now'=>$now])->acquire()->ok());

	$proofTransport=new DpPanelDistributionTransport([$locator=>['ok'=>true,'status'=>200,'body'=>$bundle['body'],'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE]]);
	$proofResult=PanelPackageAcquisitionPlan::make($resolution, $proofTransport, PanelPackageArtifactCache::make($workspace->directory('throw-proof-cache')), $authority['verifier'], $authority['policy'], [
		'now'=>$now,'require_transparency'=>true,'transparency_verifier'=>static function(): bool { throw new RuntimeException('proof unavailable'); },
	])->acquire();
	$t->isFalse($proofResult->ok());
});
