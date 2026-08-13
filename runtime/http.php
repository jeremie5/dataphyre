<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(!function_exists('dataphyre_http_redirect_and_terminate')){
	/** Emits an HTTP redirect and terminates the active request. */
	function dataphyre_http_redirect_and_terminate(string $url, int $status=302): never {
		header('Location: '.$url, true, $status);
		exit;
	}
}

if(!function_exists('dataphyre_output_and_terminate')){
	/** Emits process output and terminates the active request. */
	function dataphyre_output_and_terminate(string $output): never {
		if(ob_get_level()>0){
			@ob_clean();
		}
		echo $output;
		exit;
	}
}

if(!function_exists('dataphyre_process_error')){
	/** Writes one CLI diagnostic to the process error stream. */
	function dataphyre_process_error(string $message): void {
		fwrite(STDERR, $message);
	}
}

if(!function_exists('dataphyre_process_terminate')){
	/** Terminates a CLI process with an explicit status code. */
	function dataphyre_process_terminate(int $status): never {
		exit($status);
	}
}

if(!function_exists('dataphyre_define_if_missing')){
	/** Defines one immutable runtime value when its owner has not done so already. */
	function dataphyre_define_if_missing(string $name, mixed $value): void {
		if(!defined($name)){
			define($name, $value);
		}
	}
}

if(!function_exists('dataphyre_routing_response_and_terminate')){
	/** Emits one normalized legacy-routing response and terminates the request. */
	function dataphyre_routing_response_and_terminate(array $response): never {
		$status=(int)($response['status'] ?? 404);
		$location=trim((string)($response['location'] ?? ''));
		if($location!==''){
			header('Location: '.$location, true, $status);
			exit;
		}
		http_response_code($status);
		dataphyre_output_and_terminate((string)($response['body'] ?? ''));
	}
}

if(!function_exists('dataphyre_emit_http_response')){
	/** Emits a normalized status/header/body envelope without terminating the caller. */
	function dataphyre_emit_http_response(array $response): void {
		http_response_code((int)($response['status'] ?? 200));
		foreach(($response['headers'] ?? []) as $name=>$value){
			header((string)$name.': '.(string)$value);
		}
		if(($response['body'] ?? '')!==''){
			echo (string)$response['body'];
		}
	}
}

if(!function_exists('dataphyre_http_request')){
	/**
	 * Executes one normalized HTTP request without coupling modules to cURL state.
	 *
	 * @param array{url:string,method?:string,headers?:array,body?:string,file?:string,ca_bundle?:string,timeout?:int} $request
	 * @return array{status:int,headers:array<string,string>,body:string,error:string}
	 */
	function dataphyre_http_request(array $request): array {
		$url=trim((string)($request['url'] ?? ''));
		if($url==='' || !function_exists('curl_init')){
			return ['status'=>0,'headers'=>[],'body'=>'','error'=>$url==='' ? 'Missing request URL.' : 'cURL is unavailable.'];
		}
		$curl=curl_init();
		if($curl===false){
			return ['status'=>0,'headers'=>[],'body'=>'','error'=>'Unable to initialize cURL.'];
		}
		$headers=[];
		foreach(($request['headers'] ?? []) as $name=>$value){
			$headers[]=is_int($name) ? (string)$value : (string)$name.': '.(string)$value;
		}
		$responseHeaders=[];
		$fileHandle=null;
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper((string)($request['method'] ?? 'GET')));
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, max(1, (int)($request['timeout'] ?? 15)));
		curl_setopt($curl, CURLOPT_TIMEOUT, max(1, (int)($request['timeout'] ?? 60)));
		curl_setopt($curl, CURLOPT_HEADERFUNCTION, static function($curl, string $line) use (&$responseHeaders): int {
			$length=strlen($line);
			if(str_contains($line, ':')){
				[$name,$value]=explode(':', $line, 2);
				$responseHeaders[trim($name)]=trim($value);
			}
			return $length;
		});
		$caBundle=trim((string)($request['ca_bundle'] ?? ''));
		if($caBundle!=='' && is_file($caBundle)){
			curl_setopt($curl, CURLOPT_CAINFO, $caBundle);
		}
		$file=trim((string)($request['file'] ?? ''));
		if($file!==''){
			$fileHandle=@fopen($file, 'rb');
			if(!is_resource($fileHandle)){
				curl_close($curl);
				return ['status'=>0,'headers'=>[],'body'=>'','error'=>'Unable to open upload file.'];
			}
			curl_setopt($curl, CURLOPT_UPLOAD, true);
			curl_setopt($curl, CURLOPT_INFILE, $fileHandle);
			$size=@filesize($file);
			if(is_int($size)){
				curl_setopt($curl, CURLOPT_INFILESIZE, $size);
			}
		}elseif(array_key_exists('body', $request)){
			curl_setopt($curl, CURLOPT_POSTFIELDS, (string)$request['body']);
		}
		$body=curl_exec($curl);
		$status=(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		$error=$body===false ? curl_error($curl) : '';
		curl_close($curl);
		if(is_resource($fileHandle)){
			fclose($fileHandle);
		}
		return [
			'status'=>$status,
			'headers'=>$responseHeaders,
			'body'=>is_string($body) ? $body : '',
			'error'=>$error,
		];
	}
}

if(!function_exists('dataphyre_access_denial_and_terminate')){
	/** Emits the browser response selected by the Access policy boundary. */
	function dataphyre_access_denial_and_terminate(string $message, int $status=403, ?string $redirect=null): never {
		if(is_string($redirect) && trim($redirect)!==''){
			dataphyre_http_redirect_and_terminate($redirect);
		}
		http_response_code($status);
		header('Content-Type:text/html; charset=UTF-8');
		header('Server: Dataphyre');
		$font=function_exists('minified_font') ? minified_font() : '';
		$html='<!DOCTYPE html><html><head>'
			.'<link rel="preconnect" href="https://fonts.googleapis.com">'
			.'<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
			.'<link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">'
			.'<style>@import url("https://fonts.googleapis.com/css2?family=Roboto&display=swap");</style>'
			.'<style>'.$font.'</style>'
			.'<style>h1,h2,h3,h4,h5.h6{font-family:"Roboto", sans-serif;}</style>'
			.'</head><body><h1 style="font-size:60px" class="phyro-bold"><i><b>DATAPHYRE</b></i></h1>'
			.'<h3>'.htmlspecialchars($message, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</h3>';
		dataphyre_output_and_terminate($html);
	}
}

if(!function_exists('dataphyre_access_denial')){
	/** Selects a non-terminating diagnostic result or the live browser emitter. */
	function dataphyre_access_denial(string $message, int $status=403, ?string $redirect=null): bool {
		if(defined('RUN_MODE') && RUN_MODE==='diagnostic'){
			return false;
		}
		dataphyre_access_denial_and_terminate($message, $status, $redirect);
	}
}

if(!function_exists('dataphyre_execute_php_file')){
	/** Executes a generated PHP entrypoint and returns its captured output. */
	function dataphyre_execute_php_file(string $path): string {
		$stdout=fopen('php://temp','w+b');
		$stderr=fopen('php://temp','w+b');
		if($stdout===false||$stderr===false){
			if(is_resource($stdout)){fclose($stdout);}
			if(is_resource($stderr)){fclose($stderr);}
			return '';
		}
		$process=@proc_open([PHP_BINARY,$path],[1=>$stdout,2=>$stderr],$pipes,null,null,['bypass_shell'=>true]);
		if(!is_resource($process)){
			fclose($stdout);
			fclose($stderr);
			return '';
		}
		proc_close($process);
		rewind($stdout);
		$output=stream_get_contents($stdout);
		fclose($stdout);
		fclose($stderr);
		return is_string($output)?$output:'';
	}
}

if(!function_exists('dataphyre_json_response_and_terminate')){
	/** Emits one JSON response and terminates the active request. */
	function dataphyre_json_response_and_terminate(array $payload, int $status=200): never {
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		dataphyre_output_and_terminate((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
	}
}

if(!function_exists('dataphyre_bootstrap_caspow_endpoint')){
	/** Loads the normal runtime before a directly-invoked CASPoW endpoint request. */
	function dataphyre_bootstrap_caspow_endpoint(): void {
		if(class_exists('dataphyre\\caspow', false)){
			return;
		}
		$runtime=defined('ROOTPATH') && is_array(ROOTPATH) ? (string)(ROOTPATH['common_dataphyre_runtime'] ?? '') : '';
		if($runtime!==''){
			require_once rtrim($runtime, '/\\').'/modules/core/kernel/core.main.php';
		}
	}
}
