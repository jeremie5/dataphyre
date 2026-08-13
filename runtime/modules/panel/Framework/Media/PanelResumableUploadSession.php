<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Durable, idempotent chunked upload session backed by a PanelMediaDisk. */
final class PanelResumableUploadSession implements \JsonSerializable {

	private const VERSION=1;
	private PanelMediaDisk $disk;
	/** @var array<string,mixed> */
	private array $state;

	/** @param array<string,mixed> $state */
	private function __construct(PanelMediaDisk $disk, array $state) {
		$this->disk=$disk;
		$this->state=$state;
	}

	/**
	 * @param array<string,mixed> $metadata
	 */
	public static function start(
		PanelMediaDisk $disk,
		string $targetPath,
		int $totalSize,
		int $chunkSize=5242880,
		?string $expectedChecksum=null,
		array $metadata=[],
		?string $id=null,
		int $ttlSeconds=86400
	): self {
		$targetPath=$disk->normalizePath($targetPath);
		if(str_starts_with($targetPath, '.panel_uploads/')){
			throw new \InvalidArgumentException('Upload target cannot use Panel internal storage.');
		}
		if($totalSize<1){
			throw new \InvalidArgumentException('Upload total size must be at least one byte.');
		}
		if($chunkSize<1024 || $chunkSize>67108864){
			throw new \InvalidArgumentException('Upload chunk size must be between 1 KiB and 64 MiB.');
		}
		if($expectedChecksum!==null){
			$expectedChecksum=strtolower(trim($expectedChecksum));
			if(preg_match('/^[a-f0-9]{64}$/', $expectedChecksum)!==1){
				throw new \InvalidArgumentException('Upload checksum must be a SHA-256 hex digest.');
			}
		}
		$id=$id!==null ? trim($id) : bin2hex(random_bytes(16));
		if(preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{7,127}$/', $id)!==1){
			throw new \InvalidArgumentException('Upload session id must be 8-128 safe identifier characters.');
		}
		$manifest=self::manifestPath($id);
		if($disk->exists($manifest)){
			throw new \RuntimeException('Upload session already exists: '.$id);
		}
		$now=gmdate('c');
		$state=[
			'version'=>self::VERSION,
			'id'=>$id,
			'disk'=>$disk->name(),
			'target_path'=>$targetPath,
			'total_size'=>$totalSize,
			'chunk_size'=>$chunkSize,
			'total_chunks'=>(int)ceil($totalSize / $chunkSize),
			'expected_checksum'=>$expectedChecksum,
			'received'=>[],
			'state'=>'open',
			'created_at'=>$now,
			'updated_at'=>$now,
			'expires_at'=>gmdate('c', time()+max(60, $ttlSeconds)),
			'completed_at'=>null,
			'result'=>null,
			'metadata'=>self::jsonSafe($metadata),
		];
		$session=new self($disk, $state);
		$session->save(false);
		return $session;
	}

	public static function resume(PanelMediaDisk $disk, string $id): self {
		$id=trim($id);
		if(preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{7,127}$/', $id)!==1){
			throw new \InvalidArgumentException('Invalid upload session id.');
		}
		$path=self::manifestPath($id);
		if(!$disk->exists($path)){
			throw new \RuntimeException('Upload session does not exist: '.$id);
		}
		try {
			$state=json_decode($disk->read($path, 1048576), true, 128, JSON_THROW_ON_ERROR);
		}
		catch(\Throwable $exception){
			throw new \RuntimeException('Upload session manifest is invalid.', 0, $exception);
		}
		if(!is_array($state) || (int)($state['version'] ?? 0)!==self::VERSION || ($state['id'] ?? null)!==$id || ($state['disk'] ?? null)!==$disk->name()){
			throw new \RuntimeException('Upload session manifest does not match this disk or runtime version.');
		}
		$session=new self($disk, $state);
		$session->refreshReceived();
		return $session;
	}

	public function id(): string {
		return (string)$this->state['id'];
	}

	public function targetPath(): string {
		return (string)$this->state['target_path'];
	}

	/** @return array<string,mixed> */
	public function receiveChunk(int $index, string $contents, ?string $checksum=null, ?int $offset=null): array {
		$stream=fopen('php://temp/maxmemory:2097152', 'w+b');
		if(!is_resource($stream)){
			throw new \RuntimeException('Unable to allocate upload chunk stream.');
		}
		try {
			fwrite($stream, $contents);
			rewind($stream);
			return $this->receiveChunkStream($index, $stream, strlen($contents), $checksum, $offset); } finally {
			fclose($stream);
		}
	}

	/** @param resource $stream @return array<string,mixed> */
	public function receiveChunkStream(int $index, mixed $stream, int $size, ?string $checksum=null, ?int $offset=null): array {
		$this->assertOpen();
		if(!is_resource($stream)){
			throw new \InvalidArgumentException('Upload chunk must be an open stream resource.');
		}
		$totalChunks=(int)$this->state['total_chunks'];
		if($index<0 || $index>=$totalChunks){
			throw new \OutOfRangeException('Upload chunk index is outside the session range.');
		}
		$expectedOffset=$index*(int)$this->state['chunk_size'];
		if($offset!==null && $offset!==$expectedOffset){
			throw new \UnexpectedValueException('Upload chunk offset does not match its index.');
		}
		$expectedSize=$index===$totalChunks-1
			? (int)$this->state['total_size']-$expectedOffset
			: (int)$this->state['chunk_size'];
		if($size!==$expectedSize){
			throw new \UnexpectedValueException('Upload chunk size does not match the session contract.');
		}
		if($checksum!==null){
			$checksum=strtolower(trim($checksum));
			if(preg_match('/^[a-f0-9]{64}$/', $checksum)!==1){
				throw new \InvalidArgumentException('Chunk checksum must be a SHA-256 hex digest.');
			}
		}
		[$buffer, $actualChecksum]=$this->bufferChunk($stream, $expectedSize);
		try {
			if($checksum!==null && !hash_equals($checksum, $actualChecksum)){
				throw new \UnexpectedValueException('Upload chunk checksum mismatch.');
			}
			$path=$this->chunkPath($index);
			if($this->disk->exists($path)){
				$existing=$this->disk->descriptor($path);
				if((int)$existing['size']!==$size || !hash_equals($actualChecksum, (string)$existing['checksum'])){
					throw new \UnexpectedValueException('A different payload already exists for this upload chunk.');
				}
				$this->refreshReceived();
				return array_replace($existing, ['index'=>$index, 'offset'=>$expectedOffset, 'idempotent'=>true]);
			}
			$descriptor=$this->disk->writeStream($path, $buffer, [
				'overwrite'=>false,
				'max_bytes'=>$expectedSize,
				'checksum'=>$actualChecksum,
			]);
			$this->refreshReceived();
			$this->save();
			return array_replace($descriptor, ['index'=>$index, 'offset'=>$expectedOffset, 'idempotent'=>false]); } finally {
			fclose($buffer);
		}
	}

	/** @return array<string,mixed> */
	public function status(): array {
		if(($this->state['state'] ?? null)==='open'){
			$this->refreshReceived();
		}
		$received=is_array($this->state['received'] ?? null) ? $this->state['received'] : [];
		$receivedBytes=array_sum(array_map(static fn(mixed $chunk): int => is_array($chunk) ? (int)($chunk['size'] ?? 0) : 0, $received));
		$missing=[];
		for($index=0; $index<(int)$this->state['total_chunks']; $index++){
			if(!isset($received[(string)$index])){
				$missing[]=$index;
			}
		}
		return array_replace($this->state, [
			'received'=>$received,
			'received_chunks'=>count($received),
			'received_bytes'=>$receivedBytes,
			'missing_chunks'=>$missing,
			'progress'=>(int)floor(($receivedBytes/(int)$this->state['total_size'])*100),
			'ready'=>$missing===[] && $receivedBytes===(int)$this->state['total_size'],
		]);
	}

	/** @return array<string,mixed> */
	public function assemble(bool $overwrite=false): array {
		$this->assertOpen();
		$status=$this->status();
		if(($status['ready'] ?? false)!==true){
			throw new \RuntimeException('Upload cannot be assembled while chunks are missing.');
		}
		$assembled=fopen('php://temp/maxmemory:8388608', 'w+b');
		if(!is_resource($assembled)){
			throw new \RuntimeException('Unable to allocate upload assembly stream.');
		}
		try {
			for($index=0; $index<(int)$this->state['total_chunks']; $index++){
				$chunk=$this->disk->readStream($this->chunkPath($index));
				try {
					if(stream_copy_to_stream($chunk, $assembled)!==(int)$this->state['received'][(string)$index]['size']){
						throw new \RuntimeException('Unable to assemble complete upload chunk.');
					}
				}
				finally {
					fclose($chunk);
				}
			}
			rewind($assembled);
			$hash=hash_init('sha256');
			hash_update_stream($hash, $assembled);
			$actualChecksum=hash_final($hash);
			$expected=$this->state['expected_checksum'];
			if(is_string($expected) && $expected!=='' && !hash_equals($expected, $actualChecksum)){
				throw new \UnexpectedValueException('Assembled upload checksum mismatch.');
			}
			rewind($assembled);
			if($this->disk->exists($this->targetPath())){
				$existing=$this->disk->descriptor($this->targetPath());
				if((int)$existing['size']===(int)$this->state['total_size'] && hash_equals($actualChecksum, (string)$existing['checksum'])){
					$result=array_replace($existing, ['idempotent'=>true]);
				}
				elseif(!$overwrite){
					throw new \RuntimeException('Upload target already exists with different content.');
				}
			}
			if(!isset($result)){
				$result=$this->disk->writeStream($this->targetPath(), $assembled, [
					'overwrite'=>$overwrite,
					'max_bytes'=>(int)$this->state['total_size'],
					'checksum'=>$actualChecksum,
				]);
				$result['idempotent']=false;
			}
		}
		finally {
			fclose($assembled);
		}
		if((int)$result['size']!==(int)$this->state['total_size']){
			$this->disk->delete($this->targetPath());
			throw new \UnexpectedValueException('Assembled upload size mismatch.');
		}
		$this->state['state']='completed';
		$this->state['completed_at']=gmdate('c');
		$this->state['result']=$result;
		$this->state['updated_at']=$this->state['completed_at'];
		$this->save();
		$this->deleteChunks();
		return $result;
	}

	public function cancel(): bool {
		if(in_array($this->state['state'] ?? null, ['completed', 'cancelled'], true)){
			return false;
		}
		$this->state['state']='cancelled';
		$this->state['updated_at']=gmdate('c');
		$this->save();
		$this->deleteChunks();
		return true;
	}

	public function expired(?int $at=null): bool {
		$expires=strtotime((string)($this->state['expires_at'] ?? ''));
		return $expires!==false && $expires<=($at ?? time());
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		$status=$this->status();
		$status['type']='panel_resumable_upload_session';
		$status['capabilities']=[
			'idempotent_chunks'=>true,
			'chunk_checksums'=>true,
			'whole_file_checksum'=>true,
			'resume'=>true,
			'cancel'=>true,
			'expiration'=>true,
		];
		return $status;
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return $this->manifest();
	}

	private function refreshReceived(): void {
		$received=[];
		for($index=0; $index<(int)$this->state['total_chunks']; $index++){
			$path=$this->chunkPath($index);
			if($this->disk->exists($path)){
				$descriptor=$this->disk->descriptor($path);
				$received[(string)$index]=[
					'index'=>$index,
					'offset'=>$index*(int)$this->state['chunk_size'],
					'size'=>(int)$descriptor['size'],
					'checksum'=>(string)$descriptor['checksum'],
					'path'=>$path,
				];
			}
		}
		$this->state['received']=$received;
	}

	private function save(bool $overwrite=true): void {
		$this->state['updated_at']=gmdate('c');
		$json=json_encode($this->state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$this->disk->write(self::manifestPath($this->id()), $json, ['overwrite'=>$overwrite, 'max_bytes'=>1048576]);
	}

	private function deleteChunks(): void {
		for($index=0; $index<(int)$this->state['total_chunks']; $index++){
			$this->disk->delete($this->chunkPath($index));
		}
	}

	private function assertOpen(): void {
		if(($this->state['state'] ?? null)!=='open'){
			throw new \LogicException('Upload session is not open.');
		}
		if($this->expired()){
			throw new \RuntimeException('Upload session has expired.');
		}
	}

	/** @param resource $stream @return array{0:resource,1:string} */
	private function bufferChunk(mixed $stream, int $expectedSize): array {
		$buffer=fopen('php://temp/maxmemory:8388608', 'w+b');
		if(!is_resource($buffer)){
			throw new \RuntimeException('Unable to allocate verified chunk buffer.');
		}
		$hash=hash_init('sha256');
		$bytes=0;
		try {
			while($bytes<$expectedSize && !feof($stream)){
				$chunk=fread($stream, min(1048576, $expectedSize-$bytes));
				if($chunk===false){
					throw new \RuntimeException('Unable to read upload chunk stream.');
				}
				if($chunk===''){
					continue;
				}
				$bytes+=strlen($chunk);
				hash_update($hash, $chunk);
				if(fwrite($buffer, $chunk)!==strlen($chunk)){
					throw new \RuntimeException('Unable to buffer complete upload chunk.');
				}
			}
			if($bytes!==$expectedSize){
				throw new \UnexpectedValueException('Upload chunk stream ended before its declared size.');
			}
			$extra=fread($stream, 1);
			if($extra!==false && $extra!==''){
				throw new \UnexpectedValueException('Upload chunk stream exceeds its declared size.');
			}
			rewind($buffer);
			return [$buffer, hash_final($hash)];
		}
		catch(\Throwable $exception){
			fclose($buffer);
			throw $exception;
		}
	}

	private function chunkPath(int $index): string {
		return '.panel_uploads/'.$this->id().'/chunks/'.sprintf('%08d.part', $index);
	}

	private static function manifestPath(string $id): string {
		return '.panel_uploads/'.$id.'/manifest.json';
	}

	private static function jsonSafe(mixed $value): mixed {
		return json_decode(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
	}
}
