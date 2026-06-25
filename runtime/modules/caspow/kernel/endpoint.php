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

/**
 * Emits a CAS proof-of-work challenge response.
 *
 * The endpoint accepts JSON POST bodies or GET query parameters for challenge
 * scope. Optional capabilities must be supplied as an array in the JSON body and
 * are passed through to the CASPoW challenge generator.
 *
 * @return void
 */
function create_challenge(): void {
	$request=read_json_request();
	$scope=$request['scope'] ?? ($_GET['scope'] ?? null);
	$capabilities=is_array($request['capabilities'] ?? null) ? $request['capabilities'] : [];
	$response=dataphyre\caspow::create_challenge($scope, $capabilities);
	echo json_encode($response);
}

/**
 * Verifies a submitted CAS proof-of-work payload.
 *
 * JSON requests may submit the proof under `payload`; otherwise the raw request
 * body is verified directly. The response is a JSON object with a boolean
 * `valid` field.
 *
 * @return void
 */
function verify_payload(): void {
	$request=read_json_request();
	$payload=$request['payload'] ?? file_get_contents('php://input');
	$is_valid=dataphyre\caspow::verify_payload($payload);
	echo json_encode(['valid'=>$is_valid]);
}

/**
 * Reads the request body as a JSON object.
 *
 * Empty bodies, invalid JSON, and non-object JSON values are treated as an empty
 * request payload so endpoint handlers can safely fall back to query parameters
 * or raw body handling.
 *
 * @return array<string, mixed> Decoded JSON object payload, or an empty array.
 */
function read_json_request(): array {
	$raw=file_get_contents('php://input');
	if(!is_string($raw) || trim($raw)===''){
		return [];
	}
	$decoded=json_decode($raw, true);
	return is_array($decoded) ? $decoded : [];
}
