<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel {
	require_once __DIR__.'/panel_test_probes.php';

	if(!function_exists(__NAMESPACE__.'\\class_exists')){
		function class_exists(string $class, bool $autoload=true): bool {
			$class=ltrim($class, '\\');
			if(!\Dataphyre\Panel\TestFixtures\UploadFilesystemScenario::classAvailable($class)){
				return false;
			}
			return \class_exists($class, $autoload);
		}

		function is_file(string $filename): bool {
			if(\Dataphyre\Panel\TestFixtures\UploadFilesystemScenario::isUploadedFile($filename)){
				return true;
			}
			return \is_file($filename);
		}

		function filesize(string $filename): int|false {
			if(\Dataphyre\Panel\TestFixtures\UploadFilesystemScenario::filesizeShouldFail($filename)){
				return false;
			}
			return \filesize($filename);
		}

		function flock(mixed $stream, int $operation, mixed &$wouldBlock=null): bool {
			if(\Dataphyre\Panel\TestFixtures\UploadFilesystemScenario::lockShouldFail() && in_array($operation, [LOCK_EX, LOCK_EX | LOCK_NB], true)){
				return false;
			}
			return \flock($stream, $operation, $wouldBlock);
		}

		function file_put_contents(string $filename, mixed $data, int $flags=0, mixed $context=null): int|false {
			$suffix=\Dataphyre\Panel\TestFixtures\UploadFilesystemScenario::writeFailureSuffix();
			if($suffix!=='' && str_ends_with(str_replace('\\', '/', $filename), $suffix)){
				return false;
			}
			return $context===null
				? \file_put_contents($filename, $data, $flags)
				: \file_put_contents($filename, $data, $flags, $context);
		}

		function stream_copy_to_stream(mixed $from, mixed $to, ?int $length=null, int $offset=0): int|false {
			if(\Dataphyre\Panel\TestFixtures\UploadFilesystemScenario::streamCopyShouldFail()){
				return false;
			}
			return $length===null
				? \stream_copy_to_stream($from, $to)
				: \stream_copy_to_stream($from, $to, $length, $offset);
		}

		function sys_get_temp_dir(): string { // dataphyre-test-architecture: exempt[unmanaged-system-temporary-directory] reason="Upload namespace shim redirects production temporary paths into TestState."
			return \Dataphyre\Panel\TestFixtures\UploadFilesystemScenario::tempRoot();
		}
	}
}

namespace {
	use Dataphyre\Panel\PanelContext;
	use Dataphyre\Panel\PanelStorageUploadEndpoint;
	use Dataphyre\Storage\Contracts\StorageDriver;
	use Dataphyre\Storage\Drivers\MemoryDriver;
	use Dataphyre\Storage\FileMetadata;
	use Dataphyre\Storage\Storage;
	use Dataphyre\Panel\TestFixtures\UploadFilesystemScenario;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\before_each;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	if(!defined('DP_STORAGE_CFG')){
		define('DP_STORAGE_CFG', [
			'default_disk'=>'memory',
			'disks'=>[
				'memory'=>['driver'=>'memory'],
				'failing'=>['driver'=>'panel-upload-test', 'write'=>false, 'delete'=>false],
				'fallback'=>['driver'=>'panel-upload-test', 'write'=>true, 'metadata'=>false, 'temporary_url'=>false],
			],
		]);
	}

	framework(['panel', 'storage']);
	before_each(static function(Context $t): void {
		$workspace=$t->workspace('panel-storage-upload');
		UploadFilesystemScenario::reset($t,$workspace->root());
	});

	/** @return array{0:string,1:array{file:array{name:string,type:string,tmp_name:string,error:int,size:int}}} */
	function dp_panel_upload_fixture(Context $t,string $body='data',string $name='report.txt',string $type='text/plain'): array {
		$tmp=$t->workspace('panel-storage-upload-fixture')->file('upload.bin',$body);
		return [$tmp, ['file'=>[
			'name'=>$name,
			'type'=>$type,
			'tmp_name'=>$tmp,
			'error'=>UPLOAD_ERR_OK,
			'size'=>strlen($body),
		]]];
	}

	function dp_panel_upload_id(string $prefix='upload'): string {
		return $prefix.bin2hex(random_bytes(6));
	}

	function dp_panel_upload_directory(Context $t,string $uploadId): string {
		return (string)$t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('chunkDirectory',$uploadId);
	}

	function dp_panel_upload_cleanup(Context $t,string $directory): void {
		$t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('cleanup',$directory);
	}

	function dp_panel_upload_reset_storage(): void {
		Storage::flushManager();
		MemoryDriver::flush();
		Storage::extend('panel-upload-test', static function(array $config): StorageDriver {
			return new class($config) implements StorageDriver {
				/** @var array<string,string> */
				private array $items=[];

				public function __construct(private array $config) {}

				public function exists(string $path): bool {
					return array_key_exists($path, $this->items);
				}

				public function read(string $path, array $options=[]): string|false {
					return $this->items[$path] ?? false;
				}

				public function readStream(string $path, array $options=[]): mixed {
					$body=$this->read($path, $options);
					if(!is_string($body)){
						return false;
					}
					$stream=fopen('php://temp', 'w+b');
					fwrite($stream, $body);
					rewind($stream);
					return $stream;
				}

				public function write(string $path, mixed $contents, array $options=[]): bool {
					if(($this->config['write'] ?? true)!==true){
						return false;
					}
					if(is_resource($contents)){
						rewind($contents);
						$contents=stream_get_contents($contents);
					}
					$this->items[$path]=(string)$contents;
					return true;
				}

				public function delete(string $path): bool {
					if(($this->config['delete'] ?? true)!==true){
						return false;
					}
					unset($this->items[$path]);
					return true;
				}

				public function metadata(string $path): FileMetadata|false {
					if(($this->config['metadata'] ?? true)!==true || !array_key_exists($path, $this->items)){
						return false;
					}
					return new FileMetadata($path, strlen($this->items[$path]), time(), 'application/octet-stream');
				}

				public function list(string $prefix='', array $options=[]): array {
					return [];
				}

				public function temporaryUrl(string $path, int|DateTimeInterface $expires, array $options=[]): string|false {
					return ($this->config['temporary_url'] ?? true)===true && $this->exists($path)
						? 'test://'.rawurlencode($path)
						: false;
				}
			};
		});
	}

	test('panel storage upload endpoint covers deletion policy and scalar helper boundaries', static function(Context $t): void {
		dp_panel_upload_reset_storage();
		$upload=UploadFilesystemScenario::active();
		$t->same('Stored upload path is missing.', PanelStorageUploadEndpoint::delete([])['error'] ?? null);
		$t->same('Stored upload path is invalid.', PanelStorageUploadEndpoint::delete(['path'=>'panel_uploads/../secret.txt'])['error'] ?? null);
		$t->same('Stored upload path is outside the allowed upload prefixes.', PanelStorageUploadEndpoint::delete(['path'=>'private/secret.txt'])['error'] ?? null);

		$upload->withoutClass('Dataphyre\\Storage\\Storage');
		$t->same('Dataphyre Storage is unavailable.', PanelStorageUploadEndpoint::delete(['path'=>'panel_uploads/report.txt'])['error'] ?? null);
		$upload->restoreClass('Dataphyre\\Storage\\Storage');

		$t->isTrue(PanelStorageUploadEndpoint::delete(['path'=>'panel_uploads/report.txt', 'disk'=>' .memory- '])['deleted'] ?? false);
		$t->same('Dataphyre Storage could not delete the upload.', PanelStorageUploadEndpoint::delete([
			'path'=>'panel_uploads/report.txt', 'disk'=>'failing',
		])['error'] ?? null);
		$t->isTrue(PanelContext::run(['upload_delete_prefixes'=>'*'], static fn(): bool =>
			(bool)(PanelStorageUploadEndpoint::delete(['path'=>'custom/report.txt'])['deleted'] ?? false)
		));
		$t->same('Stored upload path is outside the allowed upload prefixes.', PanelContext::run([
			'upload_delete_prefixes'=>['', '../bad', 'custom'],
		], static fn(): ?string => PanelStorageUploadEndpoint::delete(['path'=>'elsewhere/report.txt'])['error'] ?? null));

		$t->same('', $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('storagePath','///', 'report', 'id1', 'field', 'collection'));
		$path=(string)$t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('storagePath','panel_uploads/{date}/{field}/{collection}/{original}/{name}/{ext}/{hash}/{id}/{filename}',
			'Report Final.PDF', 'upload-1', 'Invoice Field', 'Private Set',);
		$t->contains('panel_uploads/', $path);
		$t->contains('/Invoice_Field/Private_Set/', $path);
		$t->same(false, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('pathSegmentsSafe',''));
		$t->same(false, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('pathSegmentsSafe','panel_uploads/./file'));
		$t->same(true, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('pathSegmentsSafe','panel_uploads/file'));
		$t->same('', $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('token','..'));
		$t->same('upload_1.ok', $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('token',' upload_1.ok '));
		$t->same(7, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('integer',7));
		$t->same(-7, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('integer',' -7 '));
		$t->same(null, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('integer','7x'));
		$t->same(null, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('integer',str_repeat('9', 80)));
		$t->same(0, PanelContext::run(['upload_max_bytes'=>'0'], static fn(): int =>
			$t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('configuredByteLimit','upload_max_bytes', 10)
		));
		$t->same(10, PanelContext::run(['upload_max_bytes'=>'invalid'], static fn(): int =>
			$t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('configuredByteLimit','upload_max_bytes', 10)
		));
		$t->same('text/plain', $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('mimeType','Text/Plain; charset=UTF-8'));
		$t->same('application/octet-stream', $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('mimeType',"text/plain\r\nX: y"));
		$t->same('disk_name', $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('storageName',' .disk name- '));
		$t->same('file', $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('cleanFilename',"\0/\\..."));

		$missing=dp_panel_upload_directory($t,dp_panel_upload_id('missing'));
		$t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('cleanup',$missing);
		$t->same(false, is_dir($missing));
	})->tag('panel', 'storage', 'upload', 'coverage')->group('framework-coverage');

	test('panel storage upload endpoint rejects malformed requests and filesystem failures', static function(Context $t): void {
		dp_panel_upload_reset_storage();
		$upload=UploadFilesystemScenario::active();
		$t->same('Upload chunk is missing or invalid.', PanelStorageUploadEndpoint::handle([], [])['error'] ?? null);
		$t->same('Upload chunk is missing or invalid.', PanelStorageUploadEndpoint::handle([], ['file'=>['error'=>UPLOAD_ERR_PARTIAL]])['error'] ?? null);
		[$tmp, $files]=dp_panel_upload_fixture($t,'data');
		try{
			$t->same('Upload identity is missing.', PanelStorageUploadEndpoint::handle(['upload_id'=>'..'], $files)['error'] ?? null);
			$t->same('Upload chunk count is invalid.', PanelStorageUploadEndpoint::handle(['upload_id'=>'id1', 'chunks'=>'2x'], $files)['error'] ?? null);
			$t->same('Upload chunk count is invalid.', PanelStorageUploadEndpoint::handle(['upload_id'=>'id1', 'chunks'=>10001], $files)['error'] ?? null);
			$t->same('Upload chunk index is invalid.', PanelStorageUploadEndpoint::handle(['upload_id'=>'id1', 'chunks'=>2, 'chunk_index'=>'x'], $files)['error'] ?? null);
			$t->same('Upload chunk index is invalid.', PanelStorageUploadEndpoint::handle(['upload_id'=>'id1', 'chunks'=>2, 'chunk_index'=>2], $files)['error'] ?? null);
			$t->same('Upload size is invalid.', PanelStorageUploadEndpoint::handle(['upload_id'=>'id1', 'size'=>'x'], $files)['error'] ?? null);
			$t->same('Upload size is invalid.', PanelStorageUploadEndpoint::handle(['upload_id'=>'id1', 'size'=>-1], $files)['error'] ?? null);
			$t->same('Upload size is invalid.', PanelStorageUploadEndpoint::handle(['upload_id'=>'id1', 'chunks'=>2, 'size'=>0], $files)['error'] ?? null);
			$t->same('Upload exceeds the configured size limit.', PanelContext::run(['upload_max_bytes'=>3], static fn(): ?string =>
				PanelStorageUploadEndpoint::handle(['upload_id'=>'id1', 'size'=>4], $files)['error'] ?? null
			));
			$t->same('Upload storage path is invalid.', PanelStorageUploadEndpoint::handle([
				'upload_id'=>'id1', 'size'=>4, 'storage_path'=>'../{filename}',
			], $files)['error'] ?? null);
			$missingFiles=$files;
			$missingFiles['file']['tmp_name']=$tmp.'.missing';
			$t->same('Temporary upload chunk is unavailable.', PanelStorageUploadEndpoint::handle(['upload_id'=>'id1', 'size'=>4], $missingFiles)['error'] ?? null);

			$upload->uploadedFile($tmp)->failFilesizeFor($tmp);
			$t->same('Upload chunk size could not be verified.', PanelStorageUploadEndpoint::handle(['upload_id'=>'id1', 'size'=>4], $files)['error'] ?? null);
			$upload->clearUploadedFiles()->allowFilesizes();
			$t->same('Upload chunk exceeds the configured size limit.', PanelContext::run(['upload_max_chunk_bytes'=>3], static fn(): ?string =>
				PanelStorageUploadEndpoint::handle(['upload_id'=>'id1', 'size'=>4], $files)['error'] ?? null
			));
			$t->same('Upload chunk exceeds the declared upload size.', PanelStorageUploadEndpoint::handle(['upload_id'=>'id1', 'size'=>3], $files)['error'] ?? null);
		}
		finally {
			$upload->clearUploadedFiles()->allowFilesizes();
		}

		[$zeroTmp, $zeroFiles]=dp_panel_upload_fixture($t,'zero');
		$blockedId=dp_panel_upload_id('blocked');
		$blockedDirectory=dp_panel_upload_directory($t,$blockedId);
		@mkdir(dirname($blockedDirectory), 0775, true);
		file_put_contents($blockedDirectory, 'not a directory');
		$t->same('Could not prepare upload workspace.', PanelStorageUploadEndpoint::handle([
			'upload_id'=>$blockedId, 'size'=>0,
		], $zeroFiles)['error'] ?? null);

		[$lockTmp, $lockFiles]=dp_panel_upload_fixture($t,'lock');
		$lockId=dp_panel_upload_id('lock');
		$lockDirectory=dp_panel_upload_directory($t,$lockId);
		$upload->failLocks();
		try{
			$t->same('Could not lock upload workspace.', PanelStorageUploadEndpoint::handle([
				'upload_id'=>$lockId, 'size'=>4,
			], $lockFiles)['error'] ?? null);
		}
		finally {
			$upload->failLocks(false);
			dp_panel_upload_cleanup($t,$lockDirectory);
		}

		[$partTmp, $partFiles]=dp_panel_upload_fixture($t,'part');
		$partId=dp_panel_upload_id('part');
		$partDirectory=dp_panel_upload_directory($t,$partId);
		@mkdir($partDirectory, 0775, true);
		@mkdir($partDirectory.'/part-000000');
		$t->same('Could not persist upload chunk.', PanelStorageUploadEndpoint::handle([
			'upload_id'=>$partId, 'size'=>4,
		], $partFiles)['error'] ?? null);

		[$manifestTmp, $manifestFiles]=dp_panel_upload_fixture($t,'meta');
		$manifestId=dp_panel_upload_id('manifest');
		$manifestDirectory=dp_panel_upload_directory($t,$manifestId);
		$upload->failWritesEnding('/manifest.json');
		try{
			$t->same('Could not persist upload metadata.', PanelStorageUploadEndpoint::handle([
				'upload_id'=>$manifestId, 'size'=>4,
			], $manifestFiles)['error'] ?? null);
		}
		finally {
			$upload->allowWrites();
			dp_panel_upload_cleanup($t,$manifestDirectory);
		}
	})->tag('panel', 'storage', 'upload', 'coverage')->group('framework-coverage');

	test('panel storage upload endpoint covers manifests assembly and storage outcomes', static function(Context $t): void {
		dp_panel_upload_reset_storage();
		$upload=UploadFilesystemScenario::active();
		$invalidId=dp_panel_upload_id('invalidmanifest');
		$invalidDirectory=dp_panel_upload_directory($t,$invalidId);
		[$firstTmp, $firstFiles]=dp_panel_upload_fixture($t,'one');
		try{
			$pending=PanelStorageUploadEndpoint::handle([
				'upload_id'=>$invalidId, 'filename'=>'report.txt', 'size'=>6, 'chunks'=>2, 'chunk_index'=>0,
			], $firstFiles);
			$t->isTrue($pending['pending'] ?? false);
			file_put_contents($invalidDirectory.'/manifest.json', '{invalid');
			[$secondTmp, $secondFiles]=dp_panel_upload_fixture($t,'two');
			$t->same('Upload metadata changed between chunks.', PanelStorageUploadEndpoint::handle([
				'upload_id'=>$invalidId, 'filename'=>'report.txt', 'size'=>6, 'chunks'=>2, 'chunk_index'=>1,
			], $secondFiles)['error'] ?? null);
		}
		finally {
			dp_panel_upload_cleanup($t,$invalidDirectory);
		}

		$missingStorageId=dp_panel_upload_id('missingstorage');
		$missingStorageDirectory=dp_panel_upload_directory($t,$missingStorageId);
		[$chunk0Tmp, $chunk0Files]=dp_panel_upload_fixture($t,'ab');
		PanelStorageUploadEndpoint::handle([
			'upload_id'=>$missingStorageId, 'filename'=>'joined.txt', 'size'=>4, 'chunks'=>2, 'chunk_index'=>0,
			'storage_visibility'=>'private',
		], $chunk0Files);
		[$chunk1Tmp, $chunk1Files]=dp_panel_upload_fixture($t,'cd');
		$upload->withoutClass('Dataphyre\\Storage\\Storage');
		try{
			$t->same('Dataphyre Storage is unavailable.', PanelStorageUploadEndpoint::handle([
				'upload_id'=>$missingStorageId, 'filename'=>'joined.txt', 'size'=>4, 'chunks'=>2, 'chunk_index'=>1,
				'storage_visibility'=>'private',
			], $chunk1Files)['error'] ?? null);
		}
		finally {
			$upload->restoreClass('Dataphyre\\Storage\\Storage');
			dp_panel_upload_cleanup($t,$missingStorageDirectory);
		}

		$assemblyId=dp_panel_upload_id('assembly');
		$assemblyDirectory=dp_panel_upload_directory($t,$assemblyId);
		@mkdir($assemblyDirectory, 0775, true);
		@mkdir($assemblyDirectory.'/assembled.bin');
		[$assemblyTmp, $assemblyFiles]=dp_panel_upload_fixture($t,'data');
		$t->same('Could not assemble upload chunks.', PanelStorageUploadEndpoint::handle([
			'upload_id'=>$assemblyId, 'size'=>4,
		], $assemblyFiles)['error'] ?? null);

		$copyDirectory=dp_panel_upload_directory($t,dp_panel_upload_id('copyfailure'));
		@mkdir($copyDirectory, 0775, true);
		file_put_contents($copyDirectory.'/part-000000', 'data');
		$upload->failStreamCopies();
		try{
			$t->same(false, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('assemble',$copyDirectory, $copyDirectory.'/target.bin', 1));
			$t->same(false, is_file($copyDirectory.'/target.bin'));
		}
		finally {
			$upload->failStreamCopies(false);
			dp_panel_upload_cleanup($t,$copyDirectory);
		}

		$missingPartDirectory=dp_panel_upload_directory($t,dp_panel_upload_id('missingpart'));
		@mkdir($missingPartDirectory, 0775, true);
		$t->same(false, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('assemble',$missingPartDirectory, $missingPartDirectory.'/target.bin', 1));
		dp_panel_upload_cleanup($t,$missingPartDirectory);

		$mismatchId=dp_panel_upload_id('mismatch');
		$mismatchDirectory=dp_panel_upload_directory($t,$mismatchId);
		[$mismatchTmp, $mismatchFiles]=dp_panel_upload_fixture($t,'four');
		$t->same('Assembled upload size does not match the declared size.', PanelStorageUploadEndpoint::handle([
			'upload_id'=>$mismatchId, 'size'=>5,
		], $mismatchFiles)['error'] ?? null);
		$t->same(false, is_dir($mismatchDirectory));

		$storageFailureId=dp_panel_upload_id('storagefailure');
		$storageFailureDirectory=dp_panel_upload_directory($t,$storageFailureId);
		[$storageFailureTmp, $storageFailureFiles]=dp_panel_upload_fixture($t,'fail');
		$t->same('Dataphyre Storage could not persist the upload.', PanelStorageUploadEndpoint::handle([
			'upload_id'=>$storageFailureId, 'size'=>4, 'storage_disk'=>'failing',
		], $storageFailureFiles)['error'] ?? null);
		dp_panel_upload_cleanup($t,$storageFailureDirectory);
	})->tag('panel', 'storage', 'upload', 'coverage')->group('framework-coverage');

	test('panel storage upload endpoint completes uploads cleans locks and covers MIME fallbacks', static function(Context $t): void {
		dp_panel_upload_reset_storage();
		$upload=UploadFilesystemScenario::active();
		$successId=dp_panel_upload_id('success');
		$successDirectory=dp_panel_upload_directory($t,$successId);
		[$successTmp, $successFiles]=dp_panel_upload_fixture($t,'hello', 'Greeting.TXT', 'Text/Plain; charset=UTF-8');
		$result=PanelStorageUploadEndpoint::handle([
			'upload_id'=>$successId,
			'filename'=>'Greeting.TXT',
			'size'=>5,
			'storage_disk'=>'memory',
			'storage_path'=>'panel_uploads/{field}/{collection}/{filename}',
			'field'=>'Attachment',
			'storage_collection'=>'Documents',
			'storage_visibility'=>'private',
		], $successFiles);
		$t->isTrue($result['complete'] ?? false);
		$t->same(5, $result['file']['size'] ?? null);
		$t->same('Greeting.TXT', $result['file']['original_name'] ?? null);
		$t->contains('memory://', (string)($result['file']['url'] ?? ''));
		$t->same(false, is_dir($successDirectory));
		$t->same(false, is_file($successDirectory.'/.upload.lock'));

		$fallbackId=dp_panel_upload_id('fallback');
		$fallbackDirectory=dp_panel_upload_directory($t,$fallbackId);
		[$fallbackTmp, $fallbackFiles]=dp_panel_upload_fixture($t,'raw!');
		$fallback=PanelStorageUploadEndpoint::handle([
			'upload_id'=>$fallbackId, 'size'=>4, 'storage_disk'=>'fallback',
		], $fallbackFiles);
		$t->isTrue($fallback['complete'] ?? false);
		$t->same(4, $fallback['file']['size'] ?? null);
		$t->same(null, $fallback['file']['url'] ?? null);
		$t->same(false, is_dir($fallbackDirectory));

		$mimeTmp=$t->tempFile('plain text','dp-panel-upload-mime');
		try{
			$upload->withoutClass('finfo');
			$t->same('application/octet-stream', $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('detectedMimeType',$mimeTmp));
			$upload->restoreClass('finfo');
			$t->same('text/plain', $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('detectedMimeType',$mimeTmp));
		}
		finally {
			$upload->restoreClass('finfo');
		}

		$completeDirectory=dp_panel_upload_directory($t,dp_panel_upload_id('completehelper'));
		@mkdir($completeDirectory, 0775, true);
		file_put_contents($completeDirectory.'/part-000000', 'a');
		$t->same(false, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('chunksComplete',$completeDirectory, 2));
		file_put_contents($completeDirectory.'/part-000001', 'b');
		$t->same(true, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('chunksComplete',$completeDirectory, 2));
		dp_panel_upload_cleanup($t,$completeDirectory);

		$manifest=['upload_id'=>'id', 'filename'=>'file', 'size'=>1, 'mime'=>'text/plain', 'chunks'=>1, 'disk'=>'memory', 'path'=>'panel_uploads/file', 'visibility'=>''];
		$t->same(true, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('manifestMatches',$manifest, $manifest));
		$missingKey=$manifest;
		unset($missingKey['path']);
		$t->same(false, $t->nonPublic(PanelStorageUploadEndpoint::class)->invoke('manifestMatches',$missingKey, $manifest));
	})->tag('panel', 'storage', 'upload', 'coverage')->group('framework-coverage');
}
