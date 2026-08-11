<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace {
	if(!function_exists('tracelog')){
		function tracelog(...$args): void {}
	}
	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant, array $config): void {
			if(!defined($constant)){
				define($constant, $config);
			}
		}
	}
	if(!function_exists('sql_define_table')){
		function sql_define_table(...$args): void {}
	}
	if(!function_exists('config')){
		function config(string $key): mixed {
			if($key==='vestra_write_token' && empty($GLOBALS['dataphyre_vestra_disable_legacy_write_token'])){
				return 'config-write-fallback';
			}
			return '';
		}
	}

	if(!defined('CFG')){
		define('CFG', [
			'vestra_tenant_read_token'=>'legacy-read-fallback',
		]);
	}
	if(!defined('DP_VESTRA_CFG')){
		define('DP_VESTRA_CFG', [
			'base_url'=>'https://vestra.example.test/',
			'object_url'=>'https://vestra.example.test/',
			'api_url'=>'https://control.example.test/api/',
			'api_token'=>'flat-api-fallback',
			'write_api_token'=>'',
			'tenant_read_token'=>'',
			'write_token'=>'',
			'node_token'=>'',
			'default_tenant'=>'empty-profile',
			'rate'=>'s',
			'tenants'=>[
				'empty-profile'=>[
					'tenant'=>'empty-canonical',
					'rate'=>'s',
					'api_token'=>'',
					'write_api_token'=>'',
					'tenant_read_token'=>'',
					'write_token'=>'',
					'node_token'=>'',
				],
				'null-profile'=>[
					'tenant'=>'null-canonical',
					'rate'=>'s',
					'api_token'=>null,
					'write_api_token'=>null,
					'tenant_read_token'=>null,
					'write_token'=>null,
					'node_token'=>null,
				],
				'omitted-profile'=>[
					'tenant'=>'omitted-canonical',
					'rate'=>'s',
				],
				'partial-profile'=>[
					'tenant'=>'partial-canonical',
					'rate'=>'s',
					'api_token'=>null,
				],
				'split-profile'=>[
					'tenant'=>'split-canonical',
					'rate'=>'s',
					'api_token'=>'access-control-token',
					'write_api_token'=>'write-control-token',
				],
				'write-only-profile'=>[
					'tenant'=>'write-only-canonical',
					'rate'=>'s',
					'api_token'=>null,
					'write_api_token'=>'write-only-control-token',
				],
				'write-api-denied-profile'=>[
					'tenant'=>'write-api-denied-canonical',
					'rate'=>'s',
					'api_token'=>'access-control-token',
					'write_api_token'=>null,
				],
				'write-token-denied-profile'=>[
					'tenant'=>'write-token-denied-canonical',
					'rate'=>'s',
					'api_token'=>null,
					'write_api_token'=>'write-control-token',
					'write_token'=>null,
				],
			],
		]);
	}

	putenv('VESTRA_API_TOKEN=environment-api-fallback');
	putenv('VESTRA_WRITE_API_TOKEN');
	putenv('VESTRA_TENANT_READ_TOKEN=environment-read-fallback');
	putenv('VESTRA_WRITE_TOKEN=environment-write-fallback');
	putenv('VESTRA_NODE_TOKEN=environment-node-fallback');
}

namespace dataphyre {
	function curl_init(?string $url=null): \CurlHandle|false {
		return \curl_init($url);
	}

	function curl_setopt(\CurlHandle $handle, int $option, mixed $value): bool {
		$id=spl_object_id($handle);
		$GLOBALS['dataphyre_vestra_curl_options'][$id][$option]=$value;
		return true;
	}

	function curl_exec(\CurlHandle $handle): string|bool {
		$id=spl_object_id($handle);
		$GLOBALS['dataphyre_vestra_curl_calls'][]=$GLOBALS['dataphyre_vestra_curl_options'][$id] ?? [];
		if(is_array($GLOBALS['dataphyre_vestra_curl_responses'] ?? null) && $GLOBALS['dataphyre_vestra_curl_responses']!==[]){
			return (string)array_shift($GLOBALS['dataphyre_vestra_curl_responses']);
		}
		return (string)($GLOBALS['dataphyre_vestra_curl_response'] ?? '{"ok":false}');
	}

	function curl_getinfo(\CurlHandle $handle, ?int $option=null): mixed {
		return $option===CURLINFO_RESPONSE_CODE ? 200 : [];
	}

	function curl_close(\CurlHandle $handle): void {
		unset($GLOBALS['dataphyre_vestra_curl_options'][spl_object_id($handle)]);
		\curl_close($handle);
	}

	function curl_error(\CurlHandle $handle): string {
		return '';
	}

	if(!class_exists(__NAMESPACE__.'\core', false)){
		final class core {
			public static function dialback(string $event_name, mixed ...$data): mixed {
				return null;
			}
		}
	}
}

namespace DataphyreUnitTests {
	use Dataphyre\Test\Context;
	use ReflectionClass;
	use function Dataphyre\Test\test;

	require_once __DIR__.'/../kernel/vestra.main.php';

	/** @param list<mixed> $arguments */
	function vestra_private_call(string $method, array $arguments=[]): mixed {
		$reflection=new ReflectionClass(\dataphyre\vestra::class);
		return $reflection->getMethod($method)->invokeArgs(null, $arguments);
	}

	/** @return array{api_token:string,write_api_token:string,tenant_read_token:string,write_token:string,node_token:string} */
	function vestra_resolved_credentials(string $profile): array {
		return [
			'api_token'=>(string)vestra_private_call('vestra_api_token', [$profile]),
			'write_api_token'=>(string)vestra_private_call('vestra_write_api_token', [$profile]),
			'tenant_read_token'=>(string)vestra_private_call('vestra_tenant_read_token', [$profile]),
			'write_token'=>(string)vestra_private_call('vestra_write_token', [$profile]),
			'node_token'=>(string)vestra_private_call('vestra_node_token', [$profile]),
		];
	}

	/** @param list<string> $responses */
	function vestra_reset_http_spy(array $responses=[]): void {
		$GLOBALS['dataphyre_vestra_curl_options']=[];
		$GLOBALS['dataphyre_vestra_curl_calls']=[];
		$GLOBALS['dataphyre_vestra_curl_responses']=$responses;
		$GLOBALS['dataphyre_vestra_curl_response']=json_encode([
			'ok'=>true,
			'data'=>[
				'write_token'=>[
					'token'=>'w1.minted-test',
					'expires_at'=>4102444800,
				],
			],
		], JSON_UNESCAPED_SLASHES);
	}

	/** @return list<array<int,mixed>> */
	function vestra_http_calls(): array {
		return is_array($GLOBALS['dataphyre_vestra_curl_calls'] ?? null) ? $GLOBALS['dataphyre_vestra_curl_calls'] : [];
	}

	function vestra_without_legacy_write_token(callable $callback): mixed {
		$had_flag=array_key_exists('dataphyre_vestra_disable_legacy_write_token', $GLOBALS);
		$previous_flag=$GLOBALS['dataphyre_vestra_disable_legacy_write_token'] ?? null;
		$previous_environment=getenv('VESTRA_WRITE_TOKEN');
		$GLOBALS['dataphyre_vestra_disable_legacy_write_token']=true;
		putenv('VESTRA_WRITE_TOKEN');
		try{
			return $callback();
		}
		finally{
			if($had_flag){
				$GLOBALS['dataphyre_vestra_disable_legacy_write_token']=$previous_flag;
			}
			else
			{
				unset($GLOBALS['dataphyre_vestra_disable_legacy_write_token']);
			}
			$previous_environment===false
				? putenv('VESTRA_WRITE_TOKEN')
				: putenv('VESTRA_WRITE_TOKEN='.$previous_environment);
		}
	}

	test('explicit empty tenant credentials suppress every fallback source', static function(Context $t): void {
		$expected=[
			'api_token'=>'',
			'write_api_token'=>'',
			'tenant_read_token'=>'',
			'write_token'=>'',
			'node_token'=>'',
		];
		$t->same($expected, vestra_resolved_credentials('empty-profile'));
		$t->same($expected, vestra_resolved_credentials(''));
	})->tag('vestra', 'credentials', 'tenant-isolation');

	test('explicit null tenant credentials fail closed and survive profile aliases', static function(Context $t): void {
		$expected=[
			'api_token'=>'',
			'write_api_token'=>'',
			'tenant_read_token'=>'',
			'write_token'=>'',
			'node_token'=>'',
		];
		$t->same($expected, vestra_resolved_credentials('null-profile'));

		$context=vestra_private_call('tenant_context', [['tenant'=>'null-profile'], []]);
		$t->same('null-canonical', $context['tenant'] ?? null);
		foreach(array_keys($expected) as $key){
			$t->isTrue(array_key_exists($key, $context));
			$t->same('', $context[$key]);
		}
	})->tag('vestra', 'credentials', 'tenant-isolation');

	test('omitted tenant credentials retain backwards-compatible inheritance', static function(Context $t): void {
		$expected=[
			'api_token'=>'flat-api-fallback',
			'write_api_token'=>'flat-api-fallback',
			'tenant_read_token'=>'legacy-read-fallback',
			'write_token'=>'config-write-fallback',
			'node_token'=>'environment-node-fallback',
		];
		$t->same($expected, vestra_resolved_credentials('omitted-profile'));

		$context=vestra_private_call('tenant_context', [['tenant'=>'omitted-profile'], []]);
		$t->same('omitted-canonical', $context['tenant'] ?? null);
		foreach(array_keys($expected) as $key){
			$t->isFalse(array_key_exists($key, $context));
		}
	})->tag('vestra', 'credentials', 'compatibility');

	test('credential inheritance is decided independently per profile key', static function(Context $t): void {
		$t->same([
			'api_token'=>'',
			'write_api_token'=>'',
			'tenant_read_token'=>'legacy-read-fallback',
			'write_token'=>'config-write-fallback',
			'node_token'=>'environment-node-fallback',
		], vestra_resolved_credentials('partial-profile'));
	})->tag('vestra', 'credentials', 'compatibility');

	test('split Control credentials route access and write authority independently', static function(Context $t): void {
		$t->same(1, \dataphyre\vestra::SEPARATE_CONTROL_CREDENTIALS_VERSION);
		$write_path=vestra_private_call('tenant_control_path', ['split-profile', 'tokens/write']);
		$reserve_path=vestra_private_call('tenant_control_path', ['split-profile', 'objects/reserve']);
		$t->same('/tenants/split-canonical/tokens/write', $write_path);
		$t->same('/tenants/split-canonical/objects/reserve', $reserve_path);
		$t->same('access-control-token', vestra_private_call('control_api_token', [
			'/tenants/split-canonical/tokens/access',
			'split-profile',
		]));
		$t->same('write-control-token', vestra_private_call('control_api_token', [
			$write_path,
			'split-profile',
		]));
		$t->same('write-control-token', vestra_private_call('control_api_token', [
			$reserve_path,
			'split-profile',
		]));
		$t->same('/v/split-canonical/s/*', vestra_private_call('write_token_path', [
			'/v/{tenant}/{rate}/*',
			'split-profile',
			's',
		]));
		$reference=vestra_private_call('reference_from_response', [[
			'ok'=>true,
			'tenant'=>'split-profile',
			'data'=>['object_id'=>123456789],
		]]);
		$t->same('split-canonical', $reference['tenant'] ?? null);
		$t->same('split-profile', $reference['tenant_profile'] ?? null);
		$t->same('split-profile', vestra_private_call('reference_tenant_profile', [$reference]));

		vestra_reset_http_spy([
			json_encode([
				'ok'=>true,
				'data'=>[
					'access_token'=>[
						'token'=>'g1.access-test',
						'expires_at'=>4102444800,
					],
				],
			], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
		]);
		$t->same(
			'https://vestra.example.test/v/split-canonical/s/123456789/t/g1.access-test/object-123456789.jpg',
			\dataphyre\vestra::asset_url($reference, 'jpg')
		);
		$calls=vestra_http_calls();
		$t->count(1, $calls);
		$t->same('https://control.example.test/api/tenants/split-canonical/tokens/access', $calls[0][CURLOPT_URL] ?? null);
		$headers=is_array($calls[0][CURLOPT_HTTPHEADER] ?? null) ? $calls[0][CURLOPT_HTTPHEADER] : [];
		$t->isTrue(in_array('X-Vestra-Control-Key: access-control-token', $headers, true));

		vestra_reset_http_spy();
		$t->type('array', vestra_private_call('control_request', [
			'POST',
			$reserve_path,
			[],
			'split-profile',
			'',
			'form',
		]));
		$calls=vestra_http_calls();
		$t->count(1, $calls);
		$t->same('https://control.example.test/api/tenants/split-canonical/objects/reserve', $calls[0][CURLOPT_URL] ?? null);
		$headers=is_array($calls[0][CURLOPT_HTTPHEADER] ?? null) ? $calls[0][CURLOPT_HTTPHEADER] : [];
		$t->isTrue(in_array('X-Vestra-Control-Key: write-control-token', $headers, true));

		vestra_reset_http_spy();
		$t->isFalse(vestra_private_call('control_request', [
			'POST',
			$reserve_path,
			[],
			'split-profile',
			'',
			'form',
			['write_api_token'=>null],
		]));
		$t->count(0, vestra_http_calls());
	})->tag('vestra', 'credentials', 'tenant-isolation');

	test('reserve uploads send canonical tenant identity while resolving the alias credential', static function(Context $t): void {
		$file=tempnam(sys_get_temp_dir(), 'dataphyre-vestra-alias-');
		if(!is_string($file)){
			throw new \RuntimeException('Unable to create the Vestra reserve fixture.');
		}
		$contents='canonical alias upload';
		file_put_contents($file, $contents);
		try{
			vestra_reset_http_spy([
				json_encode([
					'ok'=>true,
					'data'=>[
						'object_id'=>123456789,
						'tenant'=>'split-canonical',
						'upload'=>[
							'url'=>'https://upload.example.test/object',
							'method'=>'PUT',
						],
					],
				], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
				json_encode(['ok'=>true], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
			]);
			$reference=vestra_private_call('fabric_reserve_upload', [
				$file,
				str_repeat('a', 64),
				'split-profile',
				strlen($contents),
				'text/plain',
			]);
			$t->type('array', $reference);
			$t->same('split-canonical', $reference['tenant'] ?? null);
			$t->same('split-profile', $reference['tenant_profile'] ?? null);

			$calls=vestra_http_calls();
			$t->count(2, $calls);
			$t->same('https://control.example.test/api/tenants/split-canonical/objects/reserve', $calls[0][CURLOPT_URL] ?? null);
			$t->same('https://upload.example.test/object', $calls[1][CURLOPT_URL] ?? null);
			$headers=is_array($calls[0][CURLOPT_HTTPHEADER] ?? null) ? $calls[0][CURLOPT_HTTPHEADER] : [];
			$t->isTrue(in_array('X-Vestra-Control-Key: write-control-token', $headers, true));
			$t->isTrue(count(array_filter($headers, static fn(mixed $header): bool=>is_string($header) && str_starts_with($header, 'Idempotency-Key: dataphyre_split-canonical_')))===1);
		}
		finally{
			@unlink($file);
		}
	})->tag('vestra', 'credentials', 'tenant-isolation');

	test('write-only Control credentials can mint when access authority is explicitly denied', static function(Context $t): void {
		vestra_without_legacy_write_token(static function() use ($t): void {
			vestra_reset_http_spy();
			$t->same('', vestra_private_call('vestra_api_token', ['write-only-profile']));
			$t->same('write-only-control-token', vestra_private_call('vestra_write_api_token', ['write-only-profile']));
			$t->same('w1.minted-test', vestra_private_call('vestra_write_token', [
				'write-only-profile',
				'PUT',
				'/objects/fetch',
				['rate'=>'s', 'max_bytes'=>64],
			]));

			$calls=vestra_http_calls();
			$t->count(1, $calls);
			$t->same('https://control.example.test/api/tenants/write-only-canonical/tokens/write', $calls[0][CURLOPT_URL] ?? null);
			$headers=is_array($calls[0][CURLOPT_HTTPHEADER] ?? null) ? $calls[0][CURLOPT_HTTPHEADER] : [];
			$t->isTrue(in_array('x-vestra-control-key: write-only-control-token', $headers, true));
			$t->isFalse(in_array('x-vestra-control-key: flat-api-fallback', $headers, true));
		});
	})->tag('vestra', 'credentials', 'tenant-isolation');

	test('explicit write Control denial cannot fall back to access authority', static function(Context $t): void {
		$previous=getenv('VESTRA_WRITE_API_TOKEN');
		putenv('VESTRA_WRITE_API_TOKEN=environment-write-control-fallback');
		try{
			$t->same('', vestra_private_call('vestra_write_api_token', ['write-api-denied-profile']));
			$t->same('', vestra_private_call('control_api_token', [
				'/tenants/write-api-denied-canonical/objects/reserve',
				'write-api-denied-profile',
			]));
			$t->same('', vestra_private_call('control_api_token', [
				'/tenants/split-canonical/objects/reserve',
				'split-profile',
				['write_api_token'=>null],
			]));

			vestra_without_legacy_write_token(static function() use ($t): void {
				vestra_reset_http_spy();
				$t->same('', vestra_private_call('vestra_write_token', [
					'write-api-denied-profile',
					'PUT',
					'/objects/fetch',
					['rate'=>'s', 'max_bytes'=>64],
				]));
				$t->count(0, vestra_http_calls());
			});
		}
		finally{
			$previous===false
				? putenv('VESTRA_WRITE_API_TOKEN')
				: putenv('VESTRA_WRITE_API_TOKEN='.$previous);
		}
	})->tag('vestra', 'credentials', 'tenant-isolation');

	test('explicit static write-token denial still prevents Control minting', static function(Context $t): void {
		vestra_reset_http_spy();
		$t->same('write-control-token', vestra_private_call('vestra_write_api_token', ['write-token-denied-profile']));
		$t->same('', vestra_private_call('vestra_write_token', [
			'write-token-denied-profile',
			'PUT',
			'/objects/fetch',
			['rate'=>'s', 'max_bytes'=>64],
		]));
		$t->count(0, vestra_http_calls());
	})->tag('vestra', 'credentials', 'tenant-isolation');

	test('omitted write Control credentials retain dedicated and legacy fallback order', static function(Context $t): void {
		$t->same('flat-api-fallback', vestra_private_call('vestra_write_api_token', ['omitted-profile']));
		$t->same('flat-api-fallback', vestra_private_call('control_api_token', [
			'/tenants/omitted-canonical/objects/reserve',
			'omitted-profile',
		]));

		$previous=getenv('VESTRA_WRITE_API_TOKEN');
		putenv('VESTRA_WRITE_API_TOKEN=environment-write-control-fallback');
		try{
			$t->same('environment-write-control-fallback', vestra_private_call('vestra_write_api_token', ['omitted-profile']));
		}
		finally{
			$previous===false
				? putenv('VESTRA_WRITE_API_TOKEN')
				: putenv('VESTRA_WRITE_API_TOKEN='.$previous);
		}
	})->tag('vestra', 'credentials', 'compatibility');
}
