<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Api;

/**
 * Converts Dataphyre API endpoint metadata into an OpenAPI 3.1 document.
 *
 * The generator preserves endpoint-provided operations, parameters, request
 * bodies, responses, tags, servers, and security requirements while collecting
 * reusable security schemes into components.
 */
final class OpenApiGenerator {

	/**
	 * Generates an OpenAPI document from endpoint descriptors.
	 *
	 * Invalid endpoint rows and unsupported HTTP methods are skipped. Document
	 * servers come from explicit options first, then the first endpoint that
	 * declares servers.
	 *
	 * @param array<int,array<string,mixed>> $endpoints Endpoint descriptors collected by the API module.
	 * @param array{title?:string, version?:string, description?:string, termsOfService?:string, contact?:array<string,mixed>, license?:array<string,mixed>, servers?:array<int,string|array<string,mixed>>} $options Document-level OpenAPI metadata and server overrides.
	 * @return array<string,mixed> OpenAPI 3.1 document.
	 */
	public function generate(array $endpoints, array $options=[]): array {
		$paths=[];
		$securitySchemes=[];
		$defaultServers=$this->normalizeServers($options['servers'] ?? []);
		foreach($endpoints as $endpoint){
			if(!is_array($endpoint)){
				continue;
			}
			$path=(string)($endpoint['path'] ?? '/');
			foreach($this->normalizeMethods($endpoint['methods'] ?? []) as $method){
				$paths[$path][strtolower($method)]=$this->buildOperation($endpoint, $method);
			}
			foreach(($endpoint['security_schemes'] ?? []) as $name=>$scheme){
				if(isset($securitySchemes[$name])){
					continue;
				}
				$openapi=$scheme['openapi'] ?? null;
				if(is_array($openapi) && $openapi!==[]){
					$securitySchemes[$name]=$openapi;
				}
			}
			if($defaultServers===[] && !empty($endpoint['servers']) && is_array($endpoint['servers'])){
				$defaultServers=$this->normalizeServers($endpoint['servers']);
			}
		}

		$document=[
			'openapi'=>'3.1.0',
			'info'=>$this->buildInfo($options),
			'paths'=>$paths,
		];
		if($defaultServers!==[]){
			$document['servers']=$defaultServers;
		}
		if($securitySchemes!==[]){
			$document['components']=[
				'securitySchemes'=>$securitySchemes,
			];
		}
		return $document;
	}

	/**
	 * Builds the OpenAPI info object from generator options.
	 *
	 * Empty scalar values are omitted except title and version, which fall back to
	 * Dataphyre API and 1.0.0. Contact and license are trusted as caller-provided
	 * OpenAPI objects when non-empty.
	 *
	 * @param array<string, mixed> $options Document metadata options.
	 * @return array<string,mixed> OpenAPI info object.
	 */
	private function buildInfo(array $options): array {
		$info=[
			'title'=>trim((string)($options['title'] ?? 'Dataphyre API')),
			'version'=>trim((string)($options['version'] ?? '1.0.0')),
		];
		foreach(['description', 'termsOfService'] as $key){
			if(isset($options[$key]) && trim((string)$options[$key])!==''){
				$info[$key]=trim((string)$options[$key]);
			}
		}
		if(isset($options['contact']) && is_array($options['contact']) && $options['contact']!==[]){
			$info['contact']=$options['contact'];
		}
		if(isset($options['license']) && is_array($options['license']) && $options['license']!==[]){
			$info['license']=$options['license'];
		}
		return $info;
	}

	/**
	 * Builds an OpenAPI operation object for one endpoint method.
	 *
	 * Endpoint metadata is copied through only when it already has the OpenAPI shape
	 * expected by downstream consumers. The Dataphyre method extension preserves the
	 * original uppercase method used to build the lower-case path operation key.
	 *
	 * @param array<string, mixed> $endpoint Endpoint descriptor from API route metadata.
	 * @param string $method Normalized uppercase HTTP method.
	 * @return array<string,mixed> OpenAPI operation object.
	 */
	private function buildOperation(array $endpoint, string $method): array {
		$operation=[
			'responses'=>$this->normalizeResponses($endpoint['responses'] ?? []),
		];
		foreach(['summary', 'description', 'operation_id'] as $key){
			$value=$endpoint[$key] ?? null;
			if(!is_string($value) || trim($value)===''){
				continue;
			}
			$targetKey=$key==='operation_id' ? 'operationId' : $key;
			$operation[$targetKey]=trim($value);
		}
		if(!empty($endpoint['tags']) && is_array($endpoint['tags'])){
			$operation['tags']=array_values($endpoint['tags']);
		}
		if(($endpoint['deprecated'] ?? false)===true){
			$operation['deprecated']=true;
		}
		if(!empty($endpoint['parameters']) && is_array($endpoint['parameters'])){
			$operation['parameters']=array_values($endpoint['parameters']);
		}
		if(!empty($endpoint['request_body']) && is_array($endpoint['request_body'])){
			$operation['requestBody']=$endpoint['request_body'];
		}
		if(!empty($endpoint['security']) && is_array($endpoint['security'])){
			$operation['security']=array_values($endpoint['security']);
		}
		if(!empty($endpoint['servers']) && is_array($endpoint['servers'])){
			$operation['servers']=$this->normalizeServers($endpoint['servers']);
		}
		$operation['x-dataphyre-method']=$method;
		return $operation;
	}

	/**
	 * Normalizes response descriptors and supplies a default 200 response.
	 *
	 * String/scalar response values become description-only response objects. Array
	 * responses without a description receive a generic Response label.
	 *
	 * @param array<string|int, mixed> $responses Response map keyed by HTTP status or default.
	 * @return array<string,array<string,mixed>> OpenAPI responses object.
	 */
	private function normalizeResponses(array $responses): array {
		$normalized=[];
		foreach($responses as $status=>$response){
			$status=(string)$status;
			if(!is_array($response)){
				$response=['description'=>(string)$response];
			}
			if(empty($response['description'])){
				$response['description']='Response';
			}
			$normalized[$status]=$response;
		}
		return $normalized!==[] ? $normalized : ['200'=>['description'=>'OK']];
	}

	/**
	 * Filters endpoint methods to valid OpenAPI HTTP methods.
	 *
	 * Method names are uppercased, trimmed, de-duplicated, and limited to verbs that
	 * OpenAPI path items can represent.
	 *
	 * @param array<int, mixed> $methods Raw method list from endpoint metadata.
	 * @return array<int,string> Unique uppercase HTTP methods.
	 */
	private function normalizeMethods(array $methods): array {
		$normalized=[];
		foreach($methods as $method){
			$method=strtoupper(trim((string)$method));
			if(in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)===false){
				continue;
			}
			$normalized[$method]=$method;
		}
		return array_values($normalized);
	}

	/**
	 * Normalizes string or object server definitions.
	 *
	 * String entries become `{url: ...}` objects. Array entries are passed through
	 * only when they include a non-empty `url`, preserving optional OpenAPI server
	 * variables and descriptions.
	 *
	 * @param array<int, string|array<string,mixed>|mixed> $servers Server URL strings or OpenAPI server objects.
	 * @return array<int,array<string,mixed>> OpenAPI server objects.
	 */
	private function normalizeServers(array $servers): array {
		$normalized=[];
		foreach($servers as $server){
			if(is_string($server) && trim($server)!==''){
				$normalized[]=['url'=>trim($server)];
				continue;
			}
			if(!is_array($server) || empty($server['url'])){
				continue;
			}
			$normalized[]=$server;
		}
		return $normalized;
	}
}
