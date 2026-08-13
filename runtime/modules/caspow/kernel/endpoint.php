<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3).'/http.php';

/** Composable HTTP boundary for CAS proof-of-work creation and verification. */
final class dataphyre_caspow_endpoint {
	/**
	 * @param array<string,mixed> $runtime
	 * @return array{status:int,payload:array<string,mixed>}|null
	 */
	public static function bootstrap(?bool $dispatch=null, array $runtime=[]): ?array {
		$dispatch ??= !defined('DATAPHYRE_CASPOW_ENDPOINT_NO_DISPATCH');
		if(!$dispatch){
			return null;
		}
		$bootstrap=is_callable($runtime['bootstrap'] ?? null) ? $runtime['bootstrap'] : '\\dataphyre_bootstrap_caspow_endpoint';
		$bootstrap();
		$method=(string)($runtime['method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		$action=$runtime['action'] ?? (class_exists('dataphyre\\routing', false) ? \dataphyre\routing::$bindings['action'] ?? null : null);
		$query=is_array($runtime['query'] ?? null) ? $runtime['query'] : ($_GET ?? []);
		$body=array_key_exists('body', $runtime) ? (string)$runtime['body'] : (string)file_get_contents('php://input');
		$response=self::dispatch($method, is_scalar($action) ? (string)$action : null, $query, $body, $runtime);
		$emitter=is_callable($runtime['emit'] ?? null) ? $runtime['emit'] : '\\dataphyre_json_response_and_terminate';
		$emitter($response['payload'], $response['status']);
		return $response;
	}

	/**
	 * @param array<string,mixed> $query
	 * @param array<string,mixed> $runtime
	 * @return array{status:int,payload:array<string,mixed>}
	 */
	public static function dispatch(string $method, ?string $action, array $query=[], string $raw_body='', array $runtime=[]): array {
		$method=strtoupper(trim($method));
		$request=self::readJsonRequest($raw_body);
		if($action==='create'){
			if(!in_array($method, ['GET','POST'], true)){
				return ['status'=>405, 'payload'=>['error'=>'Method not allowed']];
			}
			$scope=$request['scope'] ?? $query['scope'] ?? null;
			$capabilities=is_array($request['capabilities'] ?? null) ? $request['capabilities'] : [];
			$create=is_callable($runtime['create'] ?? null)
				? $runtime['create']
				: static fn(mixed $scope, array $capabilities): array=>\dataphyre\caspow::create_challenge($scope, $capabilities);
			return ['status'=>200, 'payload'=>$create($scope, $capabilities)];
		}
		if($action==='verify'){
			if($method!=='POST'){
				return ['status'=>405, 'payload'=>['error'=>'Method not allowed']];
			}
			$payload=$request['payload'] ?? $raw_body;
			$verify=is_callable($runtime['verify'] ?? null)
				? $runtime['verify']
				: static fn(mixed $payload): bool=>\dataphyre\caspow::verify_payload($payload);
			return ['status'=>200, 'payload'=>['valid'=>(bool)$verify($payload)]];
		}
		return ['status'=>404, 'payload'=>['error'=>'Endpoint not found']];
	}

	/** @return array<string,mixed> */
	public static function readJsonRequest(string $raw): array {
		if(trim($raw)===''){
			return [];
		}
		$decoded=json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}
}

dataphyre_caspow_endpoint::bootstrap();
