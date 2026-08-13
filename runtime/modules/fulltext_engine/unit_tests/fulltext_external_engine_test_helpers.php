<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre\fulltext_engine;

/** In-memory cURL handle used by isolated external-engine contracts. */
final class FulltextCurlHandle {
	/** @var array<int,mixed> */
	public array $options=[];
	/** @var array{body:mixed,code:int,error:string}|null */
	public ?array $response=null;

	public function __construct(public string $url='') {}
}

/**
 * Deterministic HTTP script for legacy adapters that call namespaced cURL.
 *
 * Tests enqueue outcomes and inspect structured requests; adapter scenarios do
 * not manipulate globals, hand-roll JSON between assertions, or touch a network.
 */
final class FulltextCurlTransport {
	/** @var list<array{body:mixed,code:int,error:string}> */
	private static array $responses=[];
	/** @var list<array{url:string,options:array<int,mixed>,body:mixed,code:int,error:string}> */
	private static array $requests=[];
	/** @var list<int> */
	private static array $sleepSeconds=[];

	public static function reset(): void {
		self::$responses=[];
		self::$requests=[];
		self::$sleepSeconds=[];
	}

	public static function respond(mixed $body='{}', int $code=200, string $error=''): void {
		self::$responses[]=['body'=>$body, 'code'=>$code, 'error'=>$error];
	}

	public static function fail(string $error='transport failure'): void {
		self::respond(false, 0, $error);
	}

	/** @return array{body:mixed,code:int,error:string} */
	public static function execute(FulltextCurlHandle $handle): array {
		$response=array_shift(self::$responses) ?? ['body'=>false, 'code'=>0, 'error'=>'No scripted response.'];
		$handle->response=$response;
		self::$requests[]=[
			'url'=>$handle->url,
			'options'=>$handle->options,
			'body'=>$response['body'],
			'code'=>$response['code'],
			'error'=>$response['error'],
		];
		return $response;
	}

	/** @return list<array{url:string,options:array<int,mixed>,body:mixed,code:int,error:string}> */
	public static function requests(): array {
		return self::$requests;
	}

	/** @return array{url:string,options:array<int,mixed>,body:mixed,code:int,error:string} */
	public static function lastRequest(): array {
		$request=end(self::$requests);
		return is_array($request) ? $request : ['url'=>'', 'options'=>[], 'body'=>false, 'code'=>0, 'error'=>''];
	}

	public static function recordSleep(int $seconds): void {
		self::$sleepSeconds[]=$seconds;
	}

	/** @return list<int> */
	public static function sleepSeconds(): array {
		return self::$sleepSeconds;
	}
}

function tracelog(mixed ...$arguments): void {}

function curl_init(?string $url=null): FulltextCurlHandle {
	return new FulltextCurlHandle($url ?? '');
}

function curl_setopt(FulltextCurlHandle $handle, int $option, mixed $value): bool {
	if($option===CURLOPT_URL){
		$handle->url=(string)$value;
	}
	$handle->options[$option]=$value;
	return true;
}

/** @param array<int,mixed> $options */
function curl_setopt_array(FulltextCurlHandle $handle, array $options): bool {
	foreach($options as $option=>$value){
		curl_setopt($handle, (int)$option, $value);
	}
	return true;
}

function curl_exec(FulltextCurlHandle $handle): mixed {
	return FulltextCurlTransport::execute($handle)['body'];
}

function curl_getinfo(FulltextCurlHandle $handle, ?int $option=null): mixed {
	if($option===CURLINFO_HTTP_CODE){
		return (int)($handle->response['code'] ?? 0);
	}
	return ['http_code'=>(int)($handle->response['code'] ?? 0)];
}

function curl_error(FulltextCurlHandle $handle): string {
	return (string)($handle->response['error'] ?? '');
}

function curl_close(FulltextCurlHandle $handle): void {}

function sleep(int $seconds): int {
	FulltextCurlTransport::recordSleep($seconds);
	return 0;
}

namespace dataphyre;

/** Scripted filesystem boundary used by Vespa deployment-package contracts. */
final class FulltextVespaCoreIo {
	/** @var list<bool> */
	private static array $writeResults=[];
	private static bool $materialize=true;

	/** @param list<bool> $writeResults */
	public static function reset(array $writeResults=[], bool $materialize=true): void {
		self::$writeResults=$writeResults;
		self::$materialize=$materialize;
	}

	public static function write(string $path, string $contents): int|false {
		$result=array_shift(self::$writeResults) ?? true;
		if($result!==true){
			return false;
		}
		if(!self::$materialize){
			return strlen($contents);
		}
		$directory=dirname($path);
		if(!is_dir($directory)){
			mkdir($directory, 0777, true);
		}
		return file_put_contents($path, $contents);
	}
}

if(!class_exists(core::class, false)){
	final class core {
		/** @var list<bool> */
		private static array $forcedWriteResults=[];
		/** @var list<bool> */
		private static array $forcedDirectoryResults=[];

		/** @param list<bool> $writeResults @param list<bool> $directoryResults */
		public static function scriptFilesystem(array $writeResults=[], array $directoryResults=[]): void {
			self::$forcedWriteResults=$writeResults;
			self::$forcedDirectoryResults=$directoryResults;
		}

		public static function file_put_contents_forced(string $path, string $contents): int|false {
			$result=array_shift(self::$forcedWriteResults);
			if($result===false){
				return false;
			}
			return FulltextVespaCoreIo::write($path, $contents);
		}

		public static function force_rmdir(string $path): bool {
			$result=array_shift(self::$forcedDirectoryResults);
			if($result===false){
				return false;
			}
			if(!file_exists($path)){
				return true;
			}
			$items=new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach($items as $item){
				$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
			}
			return rmdir($path);
		}

		public static function unavailable(mixed ...$arguments): never {
			$description='Fulltext kernel dependency unavailable.';
			foreach($arguments as $argument){
				if(is_string($argument) && str_starts_with($argument, 'DataphyreFulltextEngine:')){
					$description=$argument;
				}
			}
			throw new \RuntimeException($description);
		}
	}
}

/** Controllable ZipArchive-compatible implementation for deployment tests. */
final class FulltextVespaArchiveFake {
	public const CREATE=1;
	public const OVERWRITE=2;

	public static bool $openResult=true;
	public static bool $addFileResult=true;
	public static bool $closeResult=true;
	public static bool $materialize=true;
	public static bool $materializeAsDirectory=false;
	private string $path='';

	public static function reset(): void {
		self::$openResult=true;
		self::$addFileResult=true;
		self::$closeResult=true;
		self::$materialize=true;
		self::$materializeAsDirectory=false;
	}

	public function open(string $path, int $flags): bool {
		$this->path=$path;
		if(!self::$openResult){
			return false;
		}
		$this->materialize();
		return true;
	}

	public function addEmptyDir(string $path): bool {
		return true;
	}

	public function addFile(string $path, string $localName): bool {
		return self::$addFileResult;
	}

	public function close(): bool {
		$this->materialize();
		return self::$closeResult;
	}

	private function materialize(): void {
		if(!self::$materialize || $this->path===''){
			return;
		}
		$directory=dirname($this->path);
		if(!is_dir($directory)){
			mkdir($directory, 0777, true);
		}
		if(self::$materializeAsDirectory){
			if(!is_dir($this->path)){
				mkdir($this->path, 0777, true);
			}
			return;
		}
		file_put_contents($this->path, 'vespa deployment archive');
	}
}
