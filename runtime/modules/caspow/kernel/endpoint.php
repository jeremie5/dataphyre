<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
require_once(ROOTPATH['common_dataphyre_runtime']."modules/core/kernel/core.main.php");

header('Content-Type: application/json');

$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
$action=dataphyre\routing::$bindings['action'] ?? null;

switch($action){
	case 'create':
		if($method==='GET' || $method==='POST'){
			create_challenge();
			exit();
		}
		http_response_code(405);
		echo json_encode(['error'=>'Method not allowed']);
		exit();
	case 'verify':
		if($method==='POST'){
			verify_payload();
			exit();
		}
		http_response_code(405);
		echo json_encode(['error'=>'Method not allowed']);
		exit();
	default:
		http_response_code(404);
		echo json_encode(['error'=>'Endpoint not found']);
		exit();
}

function create_challenge(): void {
	$request=read_json_request();
	$scope=$request['scope'] ?? ($_GET['scope'] ?? null);
	$capabilities=is_array($request['capabilities'] ?? null) ? $request['capabilities'] : [];
	$response=dataphyre\caspow::create_challenge($scope, $capabilities);
	echo json_encode($response);
}

function verify_payload(): void {
	$request=read_json_request();
	$payload=$request['payload'] ?? file_get_contents('php://input');
	$is_valid=dataphyre\caspow::verify_payload($payload);
	echo json_encode(['valid'=>$is_valid]);
}

function read_json_request(): array {
	$raw=file_get_contents('php://input');
	if(!is_string($raw) || trim($raw)===''){
		return [];
	}
	$decoded=json_decode($raw, true);
	return is_array($decoded) ? $decoded : [];
}
