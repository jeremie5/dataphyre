<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

use Dataphyre\Storage\FileMetadata;
use Dataphyre\Storage\StorageManager;

/**
 * Panel media disk backed by a named Dataphyre Storage disk.
 *
 * The adapter keeps Panel paths relative to a private prefix, routes every
 * backend operation through StorageManager so host guards and decorators remain
 * active, verifies bytes/checksums itself, and compensates failed replacements
 * where the storage contract permits. It deliberately does not claim atomic
 * create or rename semantics because the generic Storage contract has no CAS.
 */
final class PanelDataphyreStorageMediaDisk implements PanelMediaDisk, \JsonSerializable {
	private readonly string $disk;
	private readonly string $prefix;
	private readonly string $name;
	private readonly int $defaultMaxBytes;
	/** @var array<string,mixed> */
	private readonly array $readOptions;
	/** @var array<string,mixed> */
	private readonly array $writeOptions;
	/** @var array<string,mixed> */
	private readonly array $listOptions;

	/**
	 * @param array{
	 *     name?:string,
	 *     default_max_bytes?:int,
	 *     read_options?:array<string,mixed>,
	 *     write_options?:array<string,mixed>,
	 *     list_options?:array<string,mixed>
	 * } $options
	 */
	public function __construct(
		private readonly StorageManager $storage,
		string $disk,
		string $prefix='panel-media',
		array $options=[]
	) {
		$requestedDisk=trim($disk);
		$disk=Resource::normalizeName($requestedDisk);
		if($disk==='' || $disk!==$requestedDisk){
			throw new \InvalidArgumentException('Dataphyre Storage media disks require a canonical disk name.');
		}
		foreach(array_keys($options) as $key){
			if(!in_array($key,['name','default_max_bytes','read_options','write_options','list_options'],true)){
				throw new \InvalidArgumentException("Unknown Dataphyre Storage media option '{$key}'.");
			}
		}
		foreach(['read_options','write_options','list_options'] as $key){
			if(isset($options[$key]) && !is_array($options[$key])){
				throw new \InvalidArgumentException("Dataphyre Storage media {$key} must be a map.");
			}
		}
		$this->disk=$disk;
		$this->prefix=$this->normalize($prefix,true);
		$name=Resource::normalizeName((string)($options['name']??('dataphyre-storage-'.$disk)));
		$this->name=$name!==''?$name:'dataphyre-storage';
		$this->defaultMaxBytes=max(1,(int)($options['default_max_bytes']??1073741824));
		$this->readOptions=$options['read_options']??[];
		$this->writeOptions=$options['write_options']??[];
		$this->listOptions=$options['list_options']??[];
		$this->backend('connect',fn():object=>$this->storage->disk($this->disk),false);
	}

	public function name(): string {return $this->name;}
	public function storageDisk(): string {return $this->disk;}
	public function normalizePath(string $path): string {return $this->normalize($path,false);}

	/** @param array<string,mixed> $options @return array<string,mixed> */
	public function write(string $path,string $contents,array $options=[]):array {
		$stream=fopen('php://temp/maxmemory:2097152','w+b');
		if(!is_resource($stream)){throw new \RuntimeException('Unable to allocate media write stream.');}
		try{
			if(fwrite($stream,$contents)!==strlen($contents)){throw new \RuntimeException('Unable to buffer complete media contents.');}
			rewind($stream);
			return $this->writeStream($path,$stream,$options);
		}finally{fclose($stream);}
	}

	/** @param resource $stream @param array<string,mixed> $options @return array<string,mixed> */
	public function writeStream(string $path,mixed $stream,array $options=[]):array {
		if(!is_resource($stream)){throw new \InvalidArgumentException('Media source must be an open stream resource.');}
		$path=$this->normalizePath($path);
		$overwrite=($options['overwrite']??true)===true;
		$maxBytes=max(1,(int)($options['max_bytes']??$this->defaultMaxBytes));
		$expected=$this->expectedChecksum($options['checksum']??null);
		[$buffer,$bytes,$checksum]=$this->buffer($stream,$maxBytes);
		$targetExisted=false;$backup=null;
		try{
			if($expected!==null&&!hash_equals($expected,$checksum)){throw new \UnexpectedValueException('Media checksum mismatch.');}
			$targetExisted=$this->exists($path);
			if($targetExisted&&!$overwrite){throw new \RuntimeException('Media target already exists.');}
			if($targetExisted){$backup=$this->capture($path);}
			$storageOptions=$this->storageOptions($options,$maxBytes);
			$stored=$this->backend('write',fn():bool=>$this->storage->put($this->storagePath($path),$buffer,$this->disk,$storageOptions));
			if($stored!==true){throw new PanelMediaStorageException('write','backend_rejected',true);}
			$descriptor=$this->descriptor($path);
			if((int)$descriptor['size']!==$bytes||!hash_equals($checksum,(string)$descriptor['checksum'])){
				throw new PanelMediaStorageException('write','verification_failed',true);
			}
			return $descriptor;
		}catch(\Throwable $exception){
			if($targetExisted||$this->safeExists($path)){$this->restore($path,$targetExisted,$backup,$exception);}
			throw $exception;
		}finally{
			fclose($buffer);
			if(is_resource($backup)){fclose($backup);}
		}
	}

	public function read(string $path,int $maxBytes=0):string {
		$path=$this->normalizePath($path);
		$limit=$maxBytes>0?$maxBytes:$this->defaultMaxBytes;
		$stream=$this->readStream($path);
		try{
			[$buffer]=$this->buffer($stream,$limit);
			try{
				$contents=stream_get_contents($buffer);
				if(!is_string($contents)){throw new PanelMediaStorageException('read','buffer_unavailable',true);}
				return $contents;
			}finally{fclose($buffer);}
		}finally{fclose($stream);}
	}

	/** @return resource */
	public function readStream(string $path):mixed {
		$path=$this->normalizePath($path);
		$stream=$this->backend('read',fn():mixed=>$this->storage->readStream($this->storagePath($path),$this->disk,$this->readOptions));
		if(!is_resource($stream)){throw new PanelMediaStorageException('read','not_found',false);}
		return $stream;
	}

	public function exists(string $path):bool {
		$path=$this->normalizePath($path);
		return $this->backend('exists',fn():bool=>$this->storage->exists($this->storagePath($path),$this->disk));
	}

	public function delete(string $path):bool {
		$path=$this->normalizePath($path);
		if(!$this->exists($path)){return false;}
		$deleted=$this->backend('delete',fn():bool=>$this->storage->delete($this->storagePath($path),$this->disk));
		if($deleted!==true||$this->safeExists($path)){throw new PanelMediaStorageException('delete','verification_failed',true);}
		return true;
	}

	/** @return array<string,mixed> */
	public function copy(string $from,string $to,bool $overwrite=false):array {
		$from=$this->normalizePath($from);$to=$this->normalizePath($to);
		if($from===$to){return $this->descriptor($from);}
		if(!$this->exists($from)){throw new PanelMediaStorageException('copy','source_missing',false);}
		$stream=$this->readStream($from);
		try{return $this->writeStream($to,$stream,['overwrite'=>$overwrite,'checksum'=>$this->checksum($from)]);}
		finally{fclose($stream);}
	}

	/** @return array<string,mixed> */
	public function move(string $from,string $to,bool $overwrite=false):array {
		$from=$this->normalizePath($from);$to=$this->normalizePath($to);
		if($from===$to){return $this->descriptor($from);}
		$targetExisted=$this->exists($to);
		$backup=$targetExisted?$this->capture($to):null;
		try{
			$descriptor=$this->copy($from,$to,$overwrite);
			if($this->exists($from)&&!$this->delete($from)){throw new PanelMediaStorageException('move','source_delete_failed',true);}
			return $descriptor;
		}catch(\Throwable $exception){
			if($targetExisted||$this->safeExists($to)){$this->restore($to,$targetExisted,$backup,$exception);}
			throw $exception;
		}finally{if(is_resource($backup)){fclose($backup);}}
	}

	public function size(string $path):int {
		return (int)$this->metadataDescriptor($this->normalizePath($path),false)['size'];
	}

	public function checksum(string $path,string $algorithm='sha256'):string {
		$path=$this->normalizePath($path);$algorithm=strtolower(trim($algorithm));
		if(!in_array($algorithm,hash_algos(),true)){throw new \InvalidArgumentException('Unsupported checksum algorithm: '.$algorithm);}
		$checksum=$this->backend('checksum',fn():string|false=>$this->storage->checksum($this->storagePath($path),$this->disk,$algorithm,$this->readOptions));
		if(!is_string($checksum)||$checksum===''){throw new PanelMediaStorageException('checksum','unavailable',true);}
		return strtolower($checksum);
	}

	public function modifiedAt(string $path):int {
		return (int)$this->metadataDescriptor($this->normalizePath($path),false)['modified_at'];
	}

	/** @return array<int,array<string,mixed>> */
	public function list(string $prefix='',bool $recursive=true):array {
		$prefix=$this->normalize($prefix,true);
		$options=array_replace($this->listOptions,['recursive'=>$recursive]);
		$items=$this->backend('list',fn():array=>$this->storage->list($this->storagePath($prefix,true),$this->disk,$options));
		$files=[];
		foreach($items as $item){
			if(!$item instanceof FileMetadata){throw new PanelMediaStorageException('list','invalid_metadata',false);}
			$path=$this->logicalPath($item->path());
			if($path===null||$path===''||($prefix!==''&&$path!==$prefix&&!str_starts_with($path,$prefix.'/'))){continue;}
			if(!$recursive&&str_contains(substr($path,strlen($prefix)+($prefix===''?0:1)),'/')){continue;}
			if($this->internal($path)){continue;}
			$files[]=$this->descriptorFromMetadata($path,$item,false);
		}
		usort($files,static fn(array $left,array $right):int=>strcmp((string)$left['path'],(string)$right['path']));
		return $files;
	}

	/** @return array<string,mixed> */
	public function descriptor(string $path):array {
		$path=$this->normalizePath($path);
		return $this->metadataDescriptor($path,true);
	}

	/** @return array<string,mixed> */
	public function manifest():array {
		return [
			'type'=>'panel_media_disk',
			'adapter'=>'dataphyre_storage',
			'name'=>$this->name,
			'storage_disk'=>$this->disk,
			'default_max_bytes'=>$this->defaultMaxBytes,
			'prefix_serialized'=>false,
			'storage_options_serialized'=>false,
			'credentials_serialized'=>false,
			'capabilities'=>[
				'atomic_write'=>false,
				'conditional_create'=>false,
				'atomic_move'=>false,
				'best_effort_compensation'=>true,
				'manager_write_guards'=>true,
				'streams'=>true,
				'checksums'=>hash_algos(),
				'path_traversal_protection'=>true,
				'provider_managed_durability'=>true,
			],
		];
	}

	public function jsonSerialize():array {return $this->manifest();}

	private function normalize(string $path,bool $allowEmpty):string {
		if(str_contains($path,"\0")||preg_match('/[\x00-\x1F\x7F]/',$path)===1){throw new \InvalidArgumentException('Media path contains control characters.');}
		$path=trim(str_replace('\\','/',$path));
		if($path===''&&$allowEmpty){return '';}
		if($path===''||str_starts_with($path,'/')||preg_match('/^[a-zA-Z]:/',$path)===1||str_contains($path,'://')){throw new \InvalidArgumentException('Media path must be relative.');}
		$clean=[];
		foreach(explode('/',$path) as $segment){
			if($segment===''||$segment==='.'){continue;}
			if($segment==='..'||str_contains($segment,':')){throw new \InvalidArgumentException('Media path traversal is not allowed.');}
			$clean[]=$segment;
		}
		if($clean===[]&&!$allowEmpty){throw new \InvalidArgumentException('Media path cannot be empty.');}
		return implode('/',$clean);
	}

	private function storagePath(string $path,bool $allowRoot=false):string {
		if($path===''&&$allowRoot){return $this->prefix;}
		return $this->prefix===''?$path:$this->prefix.'/'.$path;
	}

	private function logicalPath(string $path):?string {
		$path=trim(str_replace('\\','/',$path),'/');
		if($this->prefix===''){return $this->normalize($path,true);}
		if($path===$this->prefix){return '';}
		if(!str_starts_with($path,$this->prefix.'/')){return null;}
		return $this->normalize(substr($path,strlen($this->prefix)+1),true);
	}

	private function expectedChecksum(mixed $checksum):?string {
		if($checksum===null){return null;}
		$checksum=strtolower(trim((string)$checksum));
		if(preg_match('/^[a-f0-9]{64}$/D',$checksum)!==1){throw new \InvalidArgumentException('Expected media checksum must be a SHA-256 hex digest.');}
		return $checksum;
	}

	/** @param resource $stream @return array{0:resource,1:int,2:string} */
	private function buffer(mixed $stream,int $maxBytes):array {
		$buffer=fopen('php://temp/maxmemory:8388608','w+b');
		if(!is_resource($buffer)){throw new \RuntimeException('Unable to allocate verified media buffer.');}
		$hash=hash_init('sha256');$bytes=0;$emptyReads=0;
		try{
			while(!feof($stream)){
				$chunk=fread($stream,1048576);
				if($chunk===false){throw new PanelMediaStorageException('read','stream_failed',true);}
				if($chunk===''){
					if(++$emptyReads>3){throw new PanelMediaStorageException('read','stream_stalled',true);}
					continue;
				}
				$emptyReads=0;$bytes+=strlen($chunk);
				if($bytes>$maxBytes){throw new \LengthException('Media transfer exceeds the configured byte limit.');}
				hash_update($hash,$chunk);
				if(fwrite($buffer,$chunk)!==strlen($chunk)){throw new PanelMediaStorageException('write','buffer_failed',true);}
			}
			rewind($buffer);
			return [$buffer,$bytes,hash_final($hash)];
		}catch(\Throwable $exception){fclose($buffer);throw $exception;}
	}

	/** @return resource */
	private function capture(string $path):mixed {
		$source=$this->readStream($path);
		try{[$buffer]=$this->buffer($source,$this->defaultMaxBytes);return $buffer;}
		finally{fclose($source);}
	}

	private function restore(string $path,bool $existed,mixed $backup,\Throwable $original):void {
		try{
			if($existed){
				if(!is_resource($backup)){throw new PanelMediaStorageException('compensate','backup_unavailable',false,$original);}
				rewind($backup);
				$hash=hash_init('sha256');
				$bytes=hash_update_stream($hash,$backup);
				$checksum=hash_final($hash);
				if(!is_int($bytes)||$bytes<0){throw new PanelMediaStorageException('compensate','backup_unavailable',false,$original);}
				rewind($backup);
				$restored=$this->backend('compensate',fn():bool=>$this->storage->put($this->storagePath($path),$backup,$this->disk,array_replace($this->writeOptions,['max_bytes'=>$this->defaultMaxBytes])));
				if($restored!==true){throw new PanelMediaStorageException('compensate','restore_rejected',true,$original);}
				$descriptor=$this->descriptor($path);
				if((int)$descriptor['size']!==$bytes||!hash_equals($checksum,(string)$descriptor['checksum'])){
					throw new PanelMediaStorageException('compensate','restore_verification_failed',true,$original);
				}
				return;
			}
			$deleted=$this->backend('compensate',fn():bool=>$this->storage->delete($this->storagePath($path),$this->disk));
			if($deleted!==true||$this->safeExists($path)){
				throw new PanelMediaStorageException('compensate','delete_verification_failed',true,$original);
			}
		}catch(\Throwable $exception){
			if($exception instanceof PanelMediaStorageException&&$exception->operation()==='compensate'){throw $exception;}
			throw new PanelMediaStorageException('compensate','backend_failure',true,$original);
		}
	}

	private function safeExists(string $path):bool {
		try{return $this->exists($path);}catch(\Throwable){return true;}
	}

	/** @param array<string,mixed> $options @return array<string,mixed> */
	private function storageOptions(array $options,int $maxBytes):array {
		unset($options['overwrite'],$options['checksum']);
		$options['max_bytes']=$maxBytes;
		return array_replace($this->writeOptions,$options);
	}

	/** @return array<string,mixed> */
	private function metadataDescriptor(string $path,bool $includeChecksum):array {
		$metadata=$this->backend('metadata',fn():FileMetadata|false=>$this->storage->metadata($this->storagePath($path),$this->disk));
		if(!$metadata instanceof FileMetadata){throw new PanelMediaStorageException('metadata','not_found',false);}
		return $this->descriptorFromMetadata($path,$metadata,$includeChecksum);
	}

	/** @return array<string,mixed> */
	private function descriptorFromMetadata(string $path,FileMetadata $metadata,bool $includeChecksum):array {
		$size=$metadata->size();$modified=$metadata->modifiedAt();
		if($size===null||$size<0||$modified===null||$modified<0){throw new PanelMediaStorageException('metadata','incomplete',false);}
		$descriptor=[
			'disk'=>$this->name,
			'path'=>$path,
			'filename'=>basename($path),
			'size'=>$size,
			'mime'=>$metadata->mimeType()?:'application/octet-stream',
			'modified_at'=>$modified,
		];
		if($includeChecksum){$descriptor['checksum']=$this->checksum($path);}
		return $descriptor;
	}

	private function internal(string $path):bool {
		return $path==='.panel_uploads'||str_starts_with($path,'.panel_uploads/')
			||$path==='.panel-quarantine'||str_starts_with($path,'.panel-quarantine/');
	}

	private function backend(string $operation,callable $callback,bool $retryable=true):mixed {
		try{return $callback();}
		catch(PanelMediaStorageException $exception){throw $exception;}
		catch(\Throwable $exception){throw new PanelMediaStorageException($operation,'backend_failure',$retryable,$exception);}
	}
}
