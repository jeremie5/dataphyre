<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\TableDefinition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/vestra_runtime_test_helpers.php';

suite('Vestra deterministic storage contract')
	->contract('vestra.runtime', 1)
	->layer('integration')
	->risk('high')
	->watches('module:vestra')
	->through('configuration', 'references', 'tokens', 'transport', 'accounting', 'ingestion', 'propagation')
	->isolation('case')
	->tag('vestra', 'runtime', 'exact-coverage')
	->group('framework-coverage');

test('runtime configuration names endpoint tenant rate cache and safe-source defaults', static function(Context $t): void {
	$contract=DpVestraRuntimeScenario::open($t)->configurationContract();
	$t->hasPathValues([
		'has_config'=>true,
		'default_safe_delete'=>false,
		'configured'=>true,
		'fallback_base'=>'https://env-node.test/',
		'fallback_public'=>'https://env-public.test/',
		'fallback_tenant'=>'legacy-tenant',
		'fallback_rate'=>'legacy-rate',
	], $contract);
	$t->endsWith('/cache/', $contract['cache']);
});

test('reference normalization accepts nested ids handles links metadata and rejects negative envelopes', static function(Context $t): void {
	$t->hasPathValues([
		'nested_id'=>42,
		'nested_driver'=>'vestra',
		'link_only'=>true,
		'handle'=>'handle-only',
		'invalid'=>false,
		'response_id'=>73,
		'response_asset'=>'https://persisted.test/asset',
		'response_passkey'=>'pass-one',
		'response_hash'=>'hash-one',
		'response_owner'=>'shop',
		'negative_ok'=>false,
		'negative_status'=>false,
	], DpVestraRuntimeScenario::open($t)->referenceContract());
});

test('URL generation composes tenant aliases tokens expiries transforms passkeys and persisted fallbacks', static function(Context $t): void {
	$urls=DpVestraRuntimeScenario::open($t)->urlContract();
	$t->contains('/v/tenant-one/s.p/99/t/g1.runtime-grant/__tr/w640/h480/mcover/q100/fjpeg/folder/My%20Image.webp', $urls['transformed']);
	$t->contains('?passkey=secret&fit=crop', $urls['transformed']);
	$t->contains('/v/tenant-media/m.p/99/', $urls['alias']);
	$t->contains('/e/1700000500/t/explicit-token/safe/name', $urls['explicit']);
	$t->same('https://objects.vestra.test/v/tenant-one/s.p/99', $urls['unsigned']);
	$t->same('https://persisted.test/object?old=1&variant=large&passkey=p', $urls['fallback']);
	$t->same('https://persisted.test/file.png?download=1&v=2#view', $urls['asset']);
	$t->same('https://persisted.test/v/tenant-one/s.p/', $urls['template']);
});

test('token policy covers configured cached control-issued access read and node credentials', static function(Context $t): void {
	$tokens=DpVestraRuntimeScenario::open($t)->tokenContract();
	$t->same('write-fixed', $tokens['configured']);
	$t->same('write-issued', $tokens['issued']);
	$t->same('write-issued', $tokens['cached']);
	$t->same(1, $tokens['writeCalls']);
	$t->contains('/t/access-issued/', $tokens['access']);
	$t->contains('/t/access-after-retry/', $tokens['retriedAccess']);
	$t->same(2, $tokens['retryCalls']);
	$t->same([25000], $tokens['retryDelays']);
	$t->contains('/t/read-configured/', $tokens['read']);
	$t->contains('/t/node-issued/', $tokens['node']);
	$t->isFalse($tokens['outageFirst']);
	$t->isFalse($tokens['outageSecond']);
	$t->same(5, $tokens['outageCalls']);
	$t->same([25000,50000,100000,200000], $tokens['outageDelays']);
});

test('usage accounting updates positive counts and purges zero-count objects transactionally', static function(Context $t): void {
	$t->same([
		'invalid'=>false,
		'missing'=>false,
		'positive'=>5,
		'updateFailure'=>false,
		'purged'=>0,
		'deleted'=>1,
		'purgeFailure'=>false,
	], DpVestraRuntimeScenario::open($t)->usageContract());
});

test('ingestion reuses known resources propagates new attributes and CSS URLs and respects limits', static function(Context $t): void {
	$ingestion=DpVestraRuntimeScenario::open($t)->ingestionContract();
	$t->contains('https://persisted.test/known.css', $ingestion['all_html']);
	$t->contains('/v/tenant-one/s.p/2/', $ingestion['all_html']);
	$t->contains('/v/tenant-one/s.p/3/', $ingestion['all_html']);
	$t->same(['https://cdn.test/new.png','https://cdn.test/bg.jpg'], $ingestion['all_changes']);
	$t->same(1, $ingestion['limited_changes']);
	$t->contains('https://cdn.test/missing.png', $ingestion['bad_html']);
	$t->isTrue($ingestion['logged']);
});

test('remote propagation honors dialbacks validates inputs records references and rejects negative fetches', static function(Context $t): void {
	$propagation=DpVestraRuntimeScenario::open($t)->remotePropagationContract();
	$t->same(10, $propagation['dialback']['object_id']);
	$t->isFalse($propagation['invalidDialback']);
	$t->isFalse($propagation['empty']);
	$t->same(11, $propagation['remote']['object_id']);
	$t->same(1, $propagation['inserted']);
	$t->isFalse($propagation['failed']);
});

test('local propagation stages safely preserves sources encrypts metadata and makes deletion opt-in', static function(Context $t): void {
	$t->hasPathValues([
		'missing'=>false,
		'plain_id'=>21,
		'source_preserved'=>true,
		'stage_cleaned'=>true,
		'encrypted'=>true,
		'original_size'=>12,
		'delete_opt_in'=>true,
	], DpVestraRuntimeScenario::open($t)->localPropagationContract());
});

test('direct upload follows reserve guidance streams once and materializes its reference', static function(Context $t): void {
	$t->hasPathValues([
		'id'=>31,
		'asset'=>'https://persisted.test/31',
		'purposes'=>['control','upload'],
		'source_preserved'=>true,
	], DpVestraRuntimeScenario::open($t)->directUploadContract());
});

test('transport failures distinguish invalid boundaries unavailable HTTP statuses and malformed JSON', static function(Context $t): void {
	$failures=DpVestraRuntimeScenario::open($t)->transportFailureContract();
	$t->throws($failures['invalid_boundary'], LogicException::class);
	$t->isFalse($failures['transport']);
	$t->isFalse($failures['status']);
	$t->isFalse($failures['json']);
	$t->isTrue($failures['logged']);
});

test('URL rejection and fallback policy covers malformed references persisted objects and context dialbacks', static function(Context $t): void {
	$edges=DpVestraRuntimeScenario::open($t)->URLFailureAndFallbackContract();
	$t->isFalse($edges['invalid_object']);
	$t->isFalse($edges['invalid_asset']);
	$t->isFalse($edges['missing_link']);
	$t->same('https://persisted.test/from-object.jpg?one=1#fragment', $edges['asset_from_object']);
	$t->isFalse($edges['unsigned_rejected']);
	$t->contains('/t/string-token/', $edges['string_token']);
	$t->contains('/array-rate/', $edges['array_context']);
	$t->contains('/string-rate/', $edges['string_context']);
	$t->isFalse($edges['missing_context']);
	$t->isFalse($edges['missing_base']);
	$t->same('https://files.test/a', $edges['empty_extension']);
	$t->same('http://', $edges['invalid_extension_url']);
	$t->same('http://', $edges['invalid_query_url']);
	$t->same('object-9.png', $edges['safe_filename']);
	$t->isFalse($edges['scalar_id']);
	$t->same('', $edges['scalar_handle']);
	$t->same('nested-handle', $edges['nested_handle']);
});

test('configuration and request fallbacks name aliases legacy arrays derived control URLs and missing credentials', static function(Context $t): void {
	$t->hasPathValues([
		'alias_tenant'=>'alias',
		'legacy_array'=>'https://legacy-array.test/',
		'derived_api'=>'https://node.vestra.test/control/api/',
		'control_missing'=>false,
		'json_control.ok'=>true,
		'base_missing'=>false,
		'node_missing'=>false,
		'write_missing'=>false,
	], DpVestraRuntimeScenario::open($t)->configurationAndRequestEdgeContract());
});

test('invalid environment and ingestion boundaries fail loudly at their abstraction seams', static function(Context $t): void {
	$scenario=DpVestraRuntimeScenario::open($t);
	$t->throws(static fn()=>$scenario->invalidEnvironmentBoundary(), LogicException::class);
	$t->throws(static fn()=>$scenario->invalidIngestionBoundary(), LogicException::class);
});

test('token failures reject empty write and access envelopes while object expiry scopes issued access', static function(Context $t): void {
	$tokens=DpVestraRuntimeScenario::open($t)->tokenFailureContract();
	$t->same('', $tokens['missingWrite']);
	$t->isFalse($tokens['rejectedAccess']);
	$t->isFalse($tokens['missingAccessToken']);
	$t->contains('/e/1700000400/t/expiring-token/', $tokens['objectExpiry']);
});

test('filesystem failure vocabulary covers hash cache encryption write copy and deduplication policies', static function(Context $t): void {
	$scenario=DpVestraRuntimeScenario::open($t);
	$t->same([
		'hash'=>false,
		'cache'=>false,
		'encrypt_unavailable'=>false,
		'encrypt_empty'=>false,
		'write'=>false,
		'copy'=>false,
		'dedupe_id'=>88,
	], $scenario->propagationFilesystemFailureContract());
	$t->throws(static fn()=>$scenario->invalidFilesystemBoundary(), LogicException::class);
});

test('legacy platform configuration remains compatible without leaking globals into test scenarios', static function(Context $t): void {
	$contract=DpVestraRuntimeScenario::open($t)->legacyPlatformConfigurationContract();
	$t->isTrue($contract['configured']);
	$t->contains('https://legacy-node.test/v/legacy-tenant/legacy-rate/17/', $contract['object_url']);
});

test('cache delivery consumes router-owned filename bindings through its bootstrap contract', static function(Context $t): void {
	$t->hasPathValues([
		'status'=>200,
		'body'=>'body{color:green}',
		'content_type'=>'text/css',
		'emitted'=>true,
	], DpVestraRuntimeScenario::open($t)->routerOwnedCacheDeliveryContract());
});

test('response upload and record edge policy covers handles link containers guidance failures updates and IPv6 origins', static function(Context $t): void {
	$t->hasPathValues([
		'missing_identity'=>false,
		'handle'=>'handle-response',
		'handle_link'=>'https://objects.test/handle',
		'container_link'=>'https://objects.test/91',
		'guidance_url'=>'https://uploads.test/fallback',
		'missing_guidance'=>false,
		'reserve_missing'=>false,
		'reserve_no_guidance'=>false,
		'relative_no_base'=>false,
		'upload_failure'=>false,
		'updated'=>1,
		'origin'=>'http://[2001:db8::1]:8080/dataphyre/vestra/asset%20one.png',
	], DpVestraRuntimeScenario::open($t)->responseUploadAndRecordEdgeContract());
});

test('module bootstrap framework marker and table schema expose their owned integration contracts', static function(Context $t): void {
	$t->same(['initialized'=>false,'table_registered'=>false,'cache_ready'=>false], \dataphyre\vestra_bootstrap(false));
	$cache=$t->workspace('vestra-bootstrap')->directory('cache');
	$trace=$t->spy()->willReturn(null);
	$config=$t->spy()->willReturn(null);
	$table=$t->spy()->willReturn(null);
	$result=\dataphyre\vestra_bootstrap(true, [
		'trace'=>$trace,
		'define_config'=>$config,
		'define_table'=>$table,
		'vestra_runtime'=>['cache_directory'=>$cache],
		'mkdir'=>static fn(): bool=>true,
		'is_writable'=>static fn(): bool=>true,
	]);
	$t->same(['initialized'=>true,'table_registered'=>true,'cache_ready'=>true], $result);
	$trace->assertCalledTimes($t, 1);
	$config->assertCalledTimes($t, 1);
	$table->assertCalledTimes($t, 1);
	$t->isTrue(\Dataphyre\Vestra\Bootstrap::boot());

	$manifest=require dirname(__DIR__).'/kernel/vestra.tables.php';
	$definition=$manifest['objects']('dataphyre.vestra_objects');
	$t->instanceOf(TableDefinition::class, $definition);
	$t->same(['object_id'], $definition->primaryColumns());
	$t->same(['object_id','tenant','hash','mime_type','filesize','reference','use_count','created_at','updated_at'], $definition->columns());
});

test('cache endpoint serves immutable GET HEAD and conditional responses without process exits', static function(Context $t): void {
	$scenario=DpVestraRuntimeScenario::open($t);
	$file=$scenario->file('delivery/asset.css', 'body{color:red}');
	$cache=dirname($file);
	$missing=dataphyre_vestra_cache_endpoint::dispatch(['cache_directory'=>$cache,'filename'=>'missing.css']);
	$t->same(404, $missing['status']);
	$get=dataphyre_vestra_cache_endpoint::dispatch([
		'cache_directory'=>$cache,'filename'=>'asset.css','server'=>['REQUEST_METHOD'=>'GET'],
		'mtime'=>static fn(): int=>1700000000,
	]);
	$t->same(200, $get['status']);
	$t->same('text/css', $get['headers']['Content-Type']);
	$t->same('Accept-Encoding', $get['headers']['Vary']);
	$t->same('body{color:red}', $get['body']);
	$head=dataphyre_vestra_cache_endpoint::dispatch([
		'cache_directory'=>$cache,'filename'=>'asset.css','server'=>['REQUEST_METHOD'=>'HEAD'],
		'mtime'=>static fn(): int=>1700000000,
	]);
	$t->same('', $head['body']);
	$etag=dataphyre_vestra_cache_endpoint::dispatch([
		'cache_directory'=>$cache,'filename'=>'asset.css',
		'server'=>['HTTP_IF_NONE_MATCH'=>$get['headers']['ETag']],
		'mtime'=>static fn(): int=>1700000000,
	]);
	$t->same(304, $etag['status']);
	$t->same(null, dataphyre_vestra_cache_endpoint::bootstrap(false));
	$emit=$t->spy()->willReturn(null);
	$response=dataphyre_vestra_cache_endpoint::bootstrap(true, [
		'cache_directory'=>$cache,'bindings'=>['filename'=>'asset.css'],'server'=>[],'emit'=>$emit,
	]);
	$t->same(200, $response['status']);
	$emit->assertCalledTimes($t, 1);
});

test('cache endpoint explains root fallback read failures unknown MIME and invalid boundaries', static function(Context $t): void {
	$scenario=DpVestraRuntimeScenario::open($t);
	$t->same([
		'root_fallback'=>404,
		'read_failure'=>404,
		'unknown_type'=>'application/octet-stream',
		'modified_status'=>304,
	], $scenario->cacheEndpointEdgeContract());
	$t->throws(static fn()=>$scenario->invalidCacheEndpointBoundary(), LogicException::class);
	$t->throws(static fn()=>$scenario->invalidCacheEmitter(), LogicException::class);
});

test('bootstrap rejects invalid cache seams and reports cache readiness failures through tracing', static function(Context $t): void {
	$t->throws(static fn()=>\dataphyre\vestra_bootstrap(true, [
		'vestra_runtime'=>['cache_directory'=>'/unavailable'],
		'mkdir'=>'invalid','is_writable'=>static fn(): bool=>false,
	]), LogicException::class);
	$trace=$t->spy()->willReturn(null);
	$result=\dataphyre\vestra_bootstrap(true, [
		'trace'=>$trace,
		'vestra_runtime'=>['cache_directory'=>'/unavailable'],
		'mkdir'=>static fn(): bool=>false,
		'is_writable'=>static fn(): bool=>false,
	]);
	$t->same(['initialized'=>true,'table_registered'=>false,'cache_ready'=>false], $result);
	$trace->assertCalledTimes($t, 2);
});
