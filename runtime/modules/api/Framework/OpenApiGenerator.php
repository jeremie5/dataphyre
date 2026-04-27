<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Api;

final class OpenApiGenerator {

	public function generate(array $endpoints, array $options=[]): array {
		$paths=[];
		$security_schemes=[];
		$default_servers=$this->normalizeServers($options['servers'] ?? []);
		foreach($endpoints as $endpoint){
			if(!is_array($endpoint)){
				continue;
			}
			$path=(string)($endpoint['path'] ?? '/');
			foreach($this->normalizeMethods($endpoint['methods'] ?? []) as $method){
				$paths[$path][strtolower($method)]=$this->buildOperation($endpoint, $method);
			}
			foreach(($endpoint['security_schemes'] ?? []) as $name=>$scheme){
				if(isset($security_schemes[$name])){
					continue;
				}
				$openapi=$scheme['openapi'] ?? null;
				if(is_array($openapi) && $openapi!==[]){
					$security_schemes[$name]=$openapi;
				}
			}
			if($default_servers===[] && !empty($endpoint['servers']) && is_array($endpoint['servers'])){
				$default_servers=$this->normalizeServers($endpoint['servers']);
			}
		}

		$document=[
			'openapi'=>'3.1.0',
			'info'=>$this->buildInfo($options),
			'paths'=>$paths,
		];
		if($default_servers!==[]){
			$document['servers']=$default_servers;
		}
		if($security_schemes!==[]){
			$document['components']=[
				'securitySchemes'=>$security_schemes,
			];
		}
		return $document;
	}

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

	private function buildOperation(array $endpoint, string $method): array {
		$operation=[
			'responses'=>$this->normalizeResponses($endpoint['responses'] ?? []),
		];
		foreach(['summary', 'description', 'operation_id'] as $key){
			$value=$endpoint[$key] ?? null;
			if(!is_string($value) || trim($value)===''){
				continue;
			}
			$target_key=$key==='operation_id' ? 'operationId' : $key;
			$operation[$target_key]=trim($value);
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
