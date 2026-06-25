<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
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
	if(!defined('ROOTPATH')){
		define('ROOTPATH', [
			'root'=>dirname(__DIR__, 6),
			'common_dataphyre'=>dirname(__DIR__, 3).'/',
		]);
	}
	if(!defined('DP_VESTRA_CFG')){
		define('DP_VESTRA_CFG', [
			'base_url'=>'https://vestra.example.com/',
			'object_url'=>'https://vestra.example.com/',
			'default_tenant'=>'example-store-content',
			'tenants'=>[
				'example-store-content'=>[
					'tenant'=>'example-store-content',
					'rate'=>'s.p',
					'object_url'=>'https://vestra.example.com/',
				],
				'media'=>[
					'tenant'=>'example-store-content',
					'rate'=>'s.p',
					'object_url'=>'https://vestra.example.com/',
				],
			],
		]);
	}
}

namespace dataphyre {
	if(!class_exists(__NAMESPACE__.'\core', false)){
		class core {
			public static function url_updated_querystring(string $url, ?array $add=null, array|bool|null $remove=null): string {
				$parts=parse_url($url);
				$query=[];
				if(isset($parts['query'])){
					parse_str($parts['query'], $query);
				}
				if($remove===true){
					$query=[];
				}elseif(is_array($remove)){
					foreach($remove as $key){
						unset($query[$key]);
					}
				}
				if(is_array($add)){
					$query=array_replace($query, $add);
				}
				$base='';
				if(isset($parts['scheme'])){
					$base.=$parts['scheme'].'://';
				}
				if(isset($parts['host'])){
					$base.=$parts['host'];
				}
				if(isset($parts['path'])){
					$base.=$parts['path'];
				}
				$query_string=http_build_query($query);
				return $base.($query_string!=='' ? '?'.$query_string : '');
			}

			public static function encrypt_data(string $data, array $salt=[]): string {
				return 'enc:'.base64_encode(json_encode([$salt, $data], JSON_UNESCAPED_SLASHES));
			}

			public static function decrypt_data(string $data, array $salt=[], string $mode=''): string {
				return $data;
			}

			public static function dialback(string $event_name, mixed ...$data): mixed {
				if($event_name==='CALL_Vestra_ISSUE_TENANT_TOKEN'){
					return [
						'token'=>'g1.testgrant',
						'expires_at'=>time()+300,
						'tenant_grant'=>true,
					];
				}
				return null;
			}
		}
	}
}

namespace DataphyreUnitTests {
	require_once __DIR__.'/../Framework/IngestionResult.php';
	require_once __DIR__.'/../kernel/vestra.main.php';
	require_once __DIR__.'/../Framework/VestraManager.php';
	require_once __DIR__.'/../Framework/Client.php';

	function vestra_ingestion_result_from_array_json(array $payload): string {
		return json_encode(\Dataphyre\Vestra\IngestionResult::fromArray($payload)->toArray(), JSON_UNESCAPED_SLASHES);
	}

	function vestra_ingestion_result_status_json(string $html, array $changes): string {
		$result=new \Dataphyre\Vestra\IngestionResult($html, $changes);
		return json_encode([
			'html'=>$result->html(),
			'changes'=>$result->changes(),
			'changed'=>$result->changed(),
		], JSON_UNESCAPED_SLASHES);
	}

	function vestra_asset_url_json(array $reference, string $extension, array $parameters): string {
		return json_encode([
			'asset'=>\dataphyre\vestra::asset_url($reference, $extension, $parameters),
			'object'=>\dataphyre\vestra::object_url($reference, $parameters),
		], JSON_UNESCAPED_SLASHES);
	}

	function vestra_client_facade_json(array $reference, string $extension): string {
		\Dataphyre\Vestra\VestraManager::flush();
		if(!defined('Dataphyre\\Vestra\\DP_VESTRA_CFG')){
			define('Dataphyre\\Vestra\\DP_VESTRA_CFG', defined('DP_VESTRA_CFG') ? DP_VESTRA_CFG : []);
		}
		return json_encode([
			'configured'=>\Dataphyre\Vestra\Client::configured(),
			'base_url'=>\Dataphyre\Vestra\Client::baseUrl(),
			'object_url'=>\Dataphyre\Vestra\Client::objectUrl(),
			'asset'=>\Dataphyre\Vestra\Client::asset_url($reference, $extension, ['quality'=>80]),
		], JSON_UNESCAPED_SLASHES);
	}
}
