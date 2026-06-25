<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * HTTP controller for Panel's storage-backed upload endpoint.
 *
 * The controller accepts JSON/form POST requests from Panel uploader controls, normalizes
 * uploaded files into PHP's legacy upload-array shape, delegates create/delete work to
 * `PanelStorageUploadEndpoint`, returns JSON with no-store security headers, and records a
 * Panel trace event for diagnostics.
 */
final class PanelUploadController {

	/**
	 * Handles a Panel upload or delete request.
	 *
	 * Non-POST requests return `405`, missing upload handlers return `503`, and endpoint failures
	 * return `422`. Successful chunk, complete, or delete responses return `200`.
	 *
	 * @param \Dataphyre\Http\Request $request Current HTTP request.
	 * @param array<string, mixed> $route Route metadata, currently unused but accepted for route dispatcher compatibility.
	 * @return \Dataphyre\Http\Response|PanelPageResult JSON response with upload outcome, status code, and no-store headers.
	 */
	public static function handle(\Dataphyre\Http\Request $request, array $route=[]): mixed {
		self::bootstrapStorage();
		$headers=[
			'Content-Type'=>'application/json; charset=UTF-8',
			'Cache-Control'=>'no-store',
			'X-Content-Type-Options'=>'nosniff',
		];
		if(strtoupper($request->method())!=='POST'){
			$response=self::json(['ok'=>false, 'error'=>'Upload endpoint requires POST.'], 405, $headers);
			self::trace($request, $response, ['ok'=>false, 'reason'=>'method']);
			return $response;
		}
		if(!class_exists(PanelStorageUploadEndpoint::class)){
			$response=self::json(['ok'=>false, 'error'=>'Panel upload handler is unavailable.'], 503, $headers);
			self::trace($request, $response, ['ok'=>false, 'reason'=>'handler_unavailable']);
			return $response;
		}
		$post=$request->input();
		$files=self::filesArray($request->files());
		$result=isset($post['dp_panel_upload_delete'])
			? PanelStorageUploadEndpoint::delete($post)
			: PanelStorageUploadEndpoint::handle($post, $files);
		$response=self::json($result, (($result['ok'] ?? false)===true) ? 200 : 422, $headers);
		self::trace($request, $response, [
			'ok'=>($result['ok'] ?? false)===true,
			'delete'=>isset($post['dp_panel_upload_delete']),
			'pending'=>($result['pending'] ?? false)===true,
			'complete'=>($result['complete'] ?? false)===true,
			'file_count'=>count($files),
		]);
		return $response;
	}

	/**
	 * Invokable-controller entrypoint.
	 *
	 * @param \Dataphyre\Http\Request $request Current HTTP request.
	 * @return \Dataphyre\Http\Response|PanelPageResult JSON response produced by handle().
	 */
	public function __invoke(\Dataphyre\Http\Request $request): mixed {
		return self::handle($request);
	}

	/**
	 * Converts framework uploaded-file objects into legacy upload arrays.
	 *
	 * @param array<string, mixed> $files Request file bag.
	 * @return array<string, mixed> Normalized files keyed by top-level field name.
	 */
	private static function filesArray(array $files): array {
		$normalized=[];
		foreach($files as $key=>$file){
			$key=is_string($key) ? explode('.', $key, 2)[0] : (string)$key;
			if($file instanceof \Dataphyre\Http\UploadedFile){
				$normalized[$key]=[
					'name'=>$file->clientOriginalName(),
					'type'=>$file->mimeType(),
					'tmp_name'=>$file->path(),
					'error'=>$file->error(),
					'size'=>$file->size(),
				];
				continue;
			}
			if(is_array($file)){
				$normalized[$key]=$file;
			}
		}
		return $normalized;
	}

	/**
	 * Loads storage and Panel upload endpoint dependencies for route-only execution.
	 *
	 * @return void
	 */
	private static function bootstrapStorage(): void {
		if(class_exists(PanelStorageUploadEndpoint::class)){
			return;
		}
		if(class_exists('\dataphyre\autoloader', false)){
			\dataphyre\autoloader::register_framework_modules(['panel', 'storage']);
		}
		$storageBootstrap=dirname(__DIR__, 2).'/../storage/Framework/Bootstrap.php';
		if(is_file($storageBootstrap)){
			require_once $storageBootstrap;
		}
		$storageKernel=dirname(__DIR__, 2).'/../storage/kernel/storage.main.php';
		if(is_file($storageKernel)){
			require_once $storageKernel;
		}
		foreach([
			'/storage/Framework/Contracts/StorageDriver.php',
			'/storage/Framework/FileMetadata.php',
			'/storage/Framework/Support/Path.php',
			'/storage/Framework/Support/Stream.php',
			'/storage/Framework/Support/Encryption.php',
			'/storage/Framework/Drivers/LocalDriver.php',
			'/storage/Framework/StorageManager.php',
			'/storage/Framework/Storage.php',
			'/panel/Framework/Uploads/PanelStorageUploadEndpoint.php',
		] as $file){
			$path=dirname(__DIR__, 2).'/..'.$file;
			if(is_file($path)){
				require_once $path;
			}
		}
	}

	/**
	 * Creates a JSON response through HTTP Response or Panel fallback result.
	 *
	 * @param array<string, mixed> $payload Response payload.
	 * @param int $status HTTP status code.
	 * @param array<string, string> $headers Response headers.
	 * @return \Dataphyre\Http\Response|PanelPageResult Framework JSON response when HTTP is loaded, otherwise the Panel fallback result.
	 */
	private static function json(array $payload, int $status, array $headers): mixed {
		if(class_exists('\Dataphyre\Http\Response')){
			return \Dataphyre\Http\Response::json($payload, $status, $headers);
		}
		return PanelPageResult::json($payload, $status, $headers);
	}

	/**
	 * Records upload endpoint diagnostics in the Panel trace.
	 *
	 * @param \Dataphyre\Http\Request $request Current HTTP request.
	 * @param mixed $response Response returned to the client.
	 * @param array<string, mixed> $context Additional upload outcome context.
	 * @return void
	 */
	private static function trace(\Dataphyre\Http\Request $request, mixed $response, array $context=[]): void {
		PanelTrace::record('route.upload', $context+[
			'path'=>$request->path(),
			'method'=>$request->method(),
			'status'=>self::responseStatus($response),
		]);
	}

	/**
	 * Extracts an HTTP status from supported response types.
	 *
	 * @param mixed $response Response object or fallback result.
	 * @return int HTTP status code.
	 */
	private static function responseStatus(mixed $response): int {
		if(is_object($response) && isset($response->status) && is_numeric($response->status)){
			return (int)$response->status;
		}
		if($response instanceof PanelPageResult){
			return $response->status();
		}
		return 200;
	}
}
