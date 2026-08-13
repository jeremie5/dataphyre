<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Reactor\ReactorSigner;
use Dataphyre\Reactor\ReactorSnapshot;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!class_exists('dataphyre\\reactor', false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class reactor {
	public static mixed $runtimeConfig=[];
	public static function config(string $key, mixed $default=null): mixed {
		return is_array(self::$runtimeConfig) && array_key_exists($key, self::$runtimeConfig)
			? self::$runtimeConfig[$key]
			: $default;
	}
}
PHP);
}

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true,'mvc'=>true,'reactor'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}

$dp_reactor_signer_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_reactor_signer_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_reactor_signer_modules_root);
\dataphyre\autoloader::register_framework_modules(['core','mvc','reactor']);

test('reactor signer keyrings rotate without serializing key material', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	$newSecret=str_repeat('n', 32);
	$oldSecret=str_repeat('o', 32);
	$payload=['state'=>['b'=>2,'a'=>1],'component'=>'orders'];
	$canonical='{"component":"orders","state":{"a":1,"b":2}}';
	\dataphyre\reactor::$runtimeConfig=[
		'signing_keys'=>['new'=>$newSecret,'old'=>$oldSecret],
		'current_signing_key'=>'new',
		'allow_unsigned_in_debug'=>true,
	];
	$current=ReactorSigner::sign($payload);
	$t->same(hash_hmac('sha256', $canonical, $newSecret), $current);
	$t->isTrue(ReactorSigner::verify($payload, $current));
	$t->isTrue(ReactorSigner::verify($payload, hash_hmac('sha256', $canonical, $oldSecret)));
	$t->isTrue(ReactorSigner::verify([], ''));
	$t->isFalse(ReactorSigner::verify($payload, strtoupper($current)));
	$manifest=ReactorSigner::manifest();
	$t->hasPathValues([
		'configured'=>true,
		'ready'=>true,
		'production'=>false,
		'source'=>'reactor_keyring',
		'current_key_id'=>'new',
		'key_count'=>2,
		'strong_secrets'=>true,
		'unsigned_debug_payloads'=>true,
		'secrets_serialized'=>false,
	], $manifest);
	$t->same(['new','old'], $manifest['key_ids']);
	$serialized=json_encode($manifest, JSON_THROW_ON_ERROR);
	$t->notContains($newSecret, $serialized);
	$t->notContains($oldSecret, $serialized);

	\dataphyre\reactor::$runtimeConfig=[
		'secret'=>$newSecret,
		'previous_signing_secrets'=>['retired'=>$oldSecret],
	];
	$t->same(hash_hmac('sha256', $canonical, $newSecret), ReactorSigner::sign($payload));
	$t->isTrue(ReactorSigner::verify($payload, hash_hmac('sha256', $canonical, $oldSecret)));
	$t->same('reactor_secret', ReactorSigner::manifest()['source']);

	\dataphyre\reactor::$runtimeConfig=['secret'=>'development-only'];
	$t->isFalse(ReactorSigner::manifest()['strong_secrets']);
	$t->isTrue(ReactorSigner::verify($payload, ReactorSigner::sign($payload)));
})->tag('reactor','security','signing','rotation')->maxMillis(1500);

test('reactor signer fails closed for missing or weak production keys', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	$payload=['component'=>'orders'];

	\dataphyre\reactor::$runtimeConfig=['production'=>true,'allow_unsigned_in_debug'=>true];
	$t->throws(static fn()=>ReactorSigner::sign($payload), RuntimeException::class);
	$t->isFalse(ReactorSigner::verify($payload, str_repeat('0', 64)));
	$t->isFalse(ReactorSigner::verify($payload, ''));
	$t->hasPathValues([
		'ready'=>false,
		'production'=>true,
		'source'=>'unavailable',
		'unsigned_debug_payloads'=>false,
		'error'=>'signing_configuration_unavailable',
	], ReactorSigner::manifest());

	\dataphyre\reactor::$runtimeConfig=['production'=>true,'secret'=>'too-short'];
	$t->throws(static fn()=>ReactorSigner::sign($payload), RuntimeException::class);
	$t->isFalse(ReactorSigner::manifest()['ready']);

	\dataphyre\reactor::$runtimeConfig=['production'=>true,'secret'=>str_repeat('p', 32),'allow_unsigned_in_debug'=>true];
	$signature=ReactorSigner::sign($payload);
	$t->isTrue(ReactorSigner::verify($payload, $signature));
	$t->isFalse(ReactorSigner::verify($payload, ''));
	$t->isTrue(ReactorSigner::manifest()['strong_secrets']);
})->tag('reactor','security','signing','production')->maxMillis(1500);

test('reactor signer rejects malformed keyrings and non-json signed state', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	$valid=str_repeat('v', 32);
	$invalid=[
		['signing_keys'=>[]],
		['signing_keys'=>['bad id'=>$valid]],
		['signing_keys'=>['one'=>$valid,'two'=>$valid]],
		['signing_keys'=>['one'=>$valid],'current_signing_key'=>''],
		['signing_keys'=>['one'=>$valid],'current_signing_key'=>[]],
		['signing_keys'=>['one'=>$valid],'current_signing_key'=>'missing'],
		['secret'=>[]],
		['previous_signing_secrets'=>['old'=>$valid]],
		['secret'=>$valid,'previous_signing_secrets'=>'not-an-array'],
		['secret'=>$valid,'previous_signing_secrets'=>['bad id'=>$valid]],
		['secret'=>"bad\0secret"],
		['signing_keys'=>array_fill_keys(array_map(static fn(int $i): string=>'key_'.$i, range(1, 9)), $valid)],
	];
	foreach($invalid as $configuration){
		\dataphyre\reactor::$runtimeConfig=$configuration;
		$t->throws(static fn()=>ReactorSigner::sign(['safe'=>true]), Throwable::class);
		$t->isFalse(ReactorSigner::manifest()['ready']);
	}

	\dataphyre\reactor::$runtimeConfig=['signing_keys'=>[$valid]];
	$t->same('key_0', ReactorSigner::manifest()['current_key_id']);
	$t->throws(static fn()=>ReactorSigner::sign(['object'=>new stdClass()]), InvalidArgumentException::class);
	$stream=fopen('php://memory', 'rb');
	$t->isTrue(is_resource($stream));
	$t->defer(static function()use($stream): void { if(is_resource($stream)){ fclose($stream); } });
	$t->throws(static fn()=>ReactorSigner::sign(['stream'=>$stream]), InvalidArgumentException::class);
	$t->throws(static fn()=>ReactorSigner::sign(['number'=>INF]), JsonException::class);
	$t->isFalse(ReactorSigner::verify(['object'=>new stdClass()], str_repeat('0', 64)));
})->tag('reactor','security','signing','validation')->maxMillis(1500);

test('reactor snapshots enforce component identity clock bounds and production ttl', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	$secret=str_repeat('s', 32);
	$scope=['audience'=>'reactor:snapshot-security'];
	$t->throws(static fn()=>ReactorSnapshot::make('!!!', [], [], $scope), InvalidArgumentException::class);

	$makePayload=static function(int $createdAt, ?int $expiresAt=null, string $component='orders')use($secret,$scope): array {
		\dataphyre\reactor::$runtimeConfig=['secret'=>$secret];
		$payload=[
			'schema_version'=>2,
			'snapshot_id'=>str_repeat('a', 32),
			'component'=>$component,
			'state'=>['id'=>7],
			'locked'=>['id'],
			'scope_hash'=>ReactorSigner::scopeFingerprint($scope),
			'version'=>0,
			'created_at'=>$createdAt,
			'expires_at'=>$expiresAt ?? $createdAt+86400,
		];
		return $payload+['signature'=>ReactorSigner::sign($payload)];
	};
	$now=time();
	$t->isTrue(ReactorSnapshot::from($makePayload($now-1))?->verify($scope)===true);

	\dataphyre\reactor::$runtimeConfig=['secret'=>$secret,'snapshot_max_age_seconds'=>10];
	$expired=$makePayload($now-11, $now-1);
	\dataphyre\reactor::$runtimeConfig=['secret'=>$secret,'snapshot_max_age_seconds'=>10];
	$t->isFalse(ReactorSnapshot::from($expired)?->verify($scope) ?? true);
	$future=$makePayload($now+61, $now+70);
	\dataphyre\reactor::$runtimeConfig=['secret'=>$secret,'snapshot_max_age_seconds'=>10];
	$t->isFalse(ReactorSnapshot::from($future)?->verify($scope) ?? true);
	$missingTime=$makePayload(0, 10);
	\dataphyre\reactor::$runtimeConfig=['secret'=>$secret,'snapshot_max_age_seconds'=>10];
	$t->isFalse(ReactorSnapshot::from($missingTime)?->verify($scope) ?? true);

	$fresh=$makePayload($now, $now+10);
	\dataphyre\reactor::$runtimeConfig=['secret'=>$secret,'snapshot_max_age_seconds'=>'invalid'];
	$t->isFalse(ReactorSnapshot::from($fresh)?->verify($scope) ?? true);
	\dataphyre\reactor::$runtimeConfig=['secret'=>$secret,'production'=>true];
	$productionExpired=$makePayload($now-86401, $now-1);
	\dataphyre\reactor::$runtimeConfig=['secret'=>$secret,'production'=>true];
	$t->isFalse(ReactorSnapshot::from($productionExpired)?->verify($scope) ?? true);
	$bounded=$makePayload($now-2592001, $now+1);
	\dataphyre\reactor::$runtimeConfig=['secret'=>$secret,'production'=>true,'snapshot_max_age_seconds'=>PHP_INT_MAX];
	$t->isFalse(ReactorSnapshot::from($bounded)?->verify($scope) ?? true);

	$legacy=['component'=>'orders','state'=>['id'=>7],'locked'=>['id'],'created_at'=>$now];
	\dataphyre\reactor::$runtimeConfig=['secret'=>$secret,'legacy_snapshot_policy'=>ReactorSnapshot::LEGACY_POLICY];
	$legacy['signature']=ReactorSigner::sign($legacy);
	$t->isTrue(ReactorSnapshot::from($legacy)?->verify($scope)===true);
	\dataphyre\reactor::$runtimeConfig=['secret'=>$secret,'legacy_snapshot_policy'=>ReactorSnapshot::LEGACY_POLICY,'production'=>true];
	$t->isFalse(ReactorSnapshot::from($legacy)?->verify($scope) ?? true);
})->tag('reactor','security','signing','snapshot','production')->maxMillis(1500);
