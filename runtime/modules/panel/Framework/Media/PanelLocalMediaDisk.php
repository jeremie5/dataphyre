<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Safe local filesystem implementation of PanelMediaDisk.
 *
 * All paths are relative, traversal and symlink hops are rejected, writes are
 * checksum-aware atomic replacements, and every operation participates in a
 * disk-wide cross-process lock.
 */
final class PanelLocalMediaDisk implements PanelMediaDisk, \JsonSerializable {

	private string $root;
	private string $name;
	private int $defaultMaxBytes;

	public function __construct(string $root, string $name='local', int $defaultMaxBytes=1073741824) {
		$root=rtrim(trim($root), "\\/");
		if($root==='' || str_contains($root, "\0")){
			throw new \InvalidArgumentException('Local media root must be a non-empty path.');
		}
		if(is_link($root)){
			throw new \RuntimeException('Local media root cannot be a symbolic link.');
		}
		if(!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root)){
			throw new \RuntimeException('Unable to create local media root: '.$root);
		}
		$real=realpath($root);
		if($real===false || !is_writable($real)){
			throw new \RuntimeException('Local media root is unavailable or not writable.');
		}
		$this->root=rtrim($real, "\\/");
		$this->name=Resource::normalizeName($name) ?: 'local';
		$this->defaultMaxBytes=max(1, $defaultMaxBytes);
		$this->withLock(LOCK_EX, function(): void {
			$this->recoverUnlocked();
		});
	}

	public static function make(string $root, string $name='local'): self {
		return new self($root, $name);
	}

	public function name(): string {
		return $this->name;
	}

	public function root(): string {
		return $this->root;
	}

	public function normalizePath(string $path): string {
		return $this->normalize($path, false);
	}

	/** @param array<string,mixed> $options @return array<string,mixed> */
	public function write(string $path, string $contents, array $options=[]): array {
		$stream=fopen('php://temp/maxmemory:2097152', 'w+b');
		if(!is_resource($stream)){
			throw new \RuntimeException('Unable to allocate media write stream.');
		}
		try {
			fwrite($stream, $contents);
			rewind($stream);
			return $this->writeStream($path, $stream, $options); } finally {
			fclose($stream);
		}
	}

	/** @param resource $stream @param array<string,mixed> $options @return array<string,mixed> */
	public function writeStream(string $path, mixed $stream, array $options=[]): array {
		if(!is_resource($stream)){
			throw new \InvalidArgumentException('Media source must be an open stream resource.');
		}
		$path=$this->normalizePath($path);
		$overwrite=($options['overwrite'] ?? true)===true;
		$maxBytes=max(1, (int)($options['max_bytes'] ?? $this->defaultMaxBytes));
		$expected=isset($options['checksum']) ? strtolower(trim((string)$options['checksum'])) : null;
		if($expected!==null && preg_match('/^[a-f0-9]{64}$/', $expected)!==1){
			throw new \InvalidArgumentException('Expected media checksum must be a SHA-256 hex digest.');
		}
		return $this->withLock(LOCK_EX, function() use ($path, $stream, $overwrite, $maxBytes, $expected): array {
			$target=$this->absolute($path, true);
			$this->ensureParent($target);
			if(is_link($target)){
				throw new \RuntimeException('Media target cannot be a symbolic link.');
			}
			if(file_exists($target) && !$overwrite){
				throw new \RuntimeException('Media target already exists: '.$path);
			}
			$temp=dirname($target).DIRECTORY_SEPARATOR.'.'.basename($target).'.'.bin2hex(random_bytes(8)).'.tmp';
			$output=@fopen($temp, 'xb');
			if(!is_resource($output)){
				throw new \RuntimeException('Unable to create temporary media file.');
			}
			$hash=hash_init('sha256');
			$bytes=0;
			try {
				while(!feof($stream)){
					$chunk=fread($stream, 1048576);
					if($chunk===false){
						throw new \RuntimeException('Unable to read media source stream.');
					}
					if($chunk===''){
						continue;
					}
					$bytes+=strlen($chunk);
					if($bytes>$maxBytes){
						throw new \LengthException('Media write exceeds the configured byte limit.');
					}
					hash_update($hash, $chunk);
					$offset=0;
					while($offset<strlen($chunk)){
						$written=fwrite($output, substr($chunk, $offset));
						if($written===false || $written===0){
							throw new \RuntimeException('Unable to write complete media stream.');
						}
						$offset+=$written;
					}
				}
				if(!fflush($output)){
					throw new \RuntimeException('Unable to flush media file.');
				}
				if(function_exists('fsync')){
					@fsync($output);
				}
			}
			catch(\Throwable $exception){
				@fclose($output);
				@unlink($temp);
				throw $exception;
			}
			@fclose($output);
			$checksum=hash_final($hash);
			if($expected!==null && !hash_equals($expected, $checksum)){
				@unlink($temp);
				throw new \UnexpectedValueException('Media checksum mismatch.');
			}
			$this->replace($temp, $target);
			$descriptor=$this->descriptorUnlocked($path, $target);
			$descriptor['checksum']=$checksum;
			return $descriptor;
		});
	}

	public function read(string $path, int $maxBytes=0): string {
		$path=$this->normalizePath($path);
		return $this->withLock(LOCK_SH, function() use ($path, $maxBytes): string {
			$file=$this->absolute($path);
			if(!is_file($file) || is_link($file)){
				throw new \RuntimeException('Media file does not exist: '.$path);
			}
			$size=(int)filesize($file);
			if($maxBytes>0 && $size>$maxBytes){
				throw new \LengthException('Media read exceeds the configured byte limit.');
			}
			$contents=file_get_contents($file);
			if($contents===false){
				throw new \RuntimeException('Unable to read media file: '.$path);
			}
			return $contents;
		});
	}

	/** @return resource */
	public function readStream(string $path): mixed {
		$path=$this->normalizePath($path);
		$file=$this->absolute($path);
		if(!is_file($file) || is_link($file)){
			throw new \RuntimeException('Media file does not exist: '.$path);
		}
		$stream=@fopen($file, 'rb');
		if(!is_resource($stream)){
			throw new \RuntimeException('Unable to open media read stream: '.$path);
		}
		return $stream;
	}

	public function exists(string $path): bool {
		try {
			$path=$this->normalizePath($path);
			return $this->withLock(LOCK_SH, fn(): bool => is_file($this->absolute($path)) && !is_link($this->absolute($path)));
		}
		catch(\Throwable){
			return false;
		}
	}

	public function delete(string $path): bool {
		$path=$this->normalizePath($path);
		return $this->withLock(LOCK_EX, function() use ($path): bool {
			$file=$this->absolute($path);
			if(!file_exists($file)){
				return false;
			}
			if(!is_file($file) || is_link($file)){
				throw new \RuntimeException('Media deletion only supports regular files.');
			}
			if(!@unlink($file)){
				throw new \RuntimeException('Unable to delete media file: '.$path);
			}
			$this->removeEmptyParents(dirname($file));
			return true;
		});
	}

	/** @return array<string,mixed> */
	public function move(string $from, string $to, bool $overwrite=false): array {
		$from=$this->normalizePath($from);
		$to=$this->normalizePath($to);
		if($from===$to){
			return $this->descriptor($from);
		}
		return $this->withLock(LOCK_EX, function() use ($from, $to, $overwrite): array {
			$source=$this->absolute($from);
			$target=$this->absolute($to, true);
			if(!is_file($source) || is_link($source)){
				throw new \RuntimeException('Media source does not exist: '.$from);
			}
			if(file_exists($target) && !$overwrite){
				throw new \RuntimeException('Media target already exists: '.$to);
			}
			$this->ensureParent($target);
			$backup=null;
			if(file_exists($target)){
				$backup=dirname($target).DIRECTORY_SEPARATOR.'.'.basename($target).'.'.bin2hex(random_bytes(8)).'.bak';
				if(!@rename($target, $backup)){
					throw new \RuntimeException('Unable to stage media target for move.');
				}
			}
			if(!@rename($source, $target)){
				if($backup!==null){ @rename($backup, $target); }
				throw new \RuntimeException('Unable to move media file.');
			}
			if($backup!==null){ @unlink($backup); }
			$this->removeEmptyParents(dirname($source));
			return $this->descriptorUnlocked($to, $target);
		});
	}

	/** @return array<string,mixed> */
	public function copy(string $from, string $to, bool $overwrite=false): array {
		$from=$this->normalizePath($from);
		$to=$this->normalizePath($to);
		return $this->withLock(LOCK_EX, function() use ($from, $to, $overwrite): array {
			$source=$this->absolute($from);
			$target=$this->absolute($to, true);
			if(!is_file($source) || is_link($source)){
				throw new \RuntimeException('Media source does not exist: '.$from);
			}
			if(file_exists($target) && !$overwrite){
				throw new \RuntimeException('Media target already exists: '.$to);
			}
			$this->ensureParent($target);
			$temp=dirname($target).DIRECTORY_SEPARATOR.'.'.basename($target).'.'.bin2hex(random_bytes(8)).'.tmp';
			if(!@copy($source, $temp)){
				throw new \RuntimeException('Unable to copy media file.');
			}
			$this->replace($temp, $target);
			return $this->descriptorUnlocked($to, $target);
		});
	}

	public function size(string $path): int {
		return (int)$this->descriptor($path)['size'];
	}

	public function checksum(string $path, string $algorithm='sha256'): string {
		$path=$this->normalizePath($path);
		$algorithm=strtolower(trim($algorithm));
		if(!in_array($algorithm, hash_algos(), true)){
			throw new \InvalidArgumentException('Unsupported checksum algorithm: '.$algorithm);
		}
		return $this->withLock(LOCK_SH, function() use ($path, $algorithm): string {
			$file=$this->absolute($path);
			if(!is_file($file) || is_link($file)){
				throw new \RuntimeException('Media file does not exist: '.$path);
			}
			$checksum=hash_file($algorithm, $file);
			if(!is_string($checksum)){
				throw new \RuntimeException('Unable to checksum media file.');
			}
			return $checksum;
		});
	}

	public function modifiedAt(string $path): int {
		return (int)$this->descriptor($path)['modified_at'];
	}

	/** @return array<int,array<string,mixed>> */
	public function list(string $prefix='', bool $recursive=true): array {
		$prefix=$this->normalize($prefix, true);
		return $this->withLock(LOCK_SH, function() use ($prefix, $recursive): array {
			$directory=$prefix==='' ? $this->root : $this->absolute($prefix);
			if(!is_dir($directory) || is_link($directory)){
				return [];
			}
			$files=[];
			$iterator=$recursive
				? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS))
				: new \IteratorIterator(new \DirectoryIterator($directory));
			foreach($iterator as $file){
				if(!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()){
					continue;
				}
				$absolute=$file->getPathname();
				$relative=str_replace('\\', '/', substr($absolute, strlen($this->root)+1));
				if($relative==='.panel-media.lock' || str_starts_with($relative, '.panel_uploads/') || str_starts_with($relative, '.panel-quarantine/')){
					continue;
				}
				$files[]=$this->descriptorUnlocked($relative, $absolute);
			}
			usort($files, static fn(array $left, array $right): int => strcmp((string)$left['path'], (string)$right['path']));
			return $files;
		});
	}

	/** @return array<string,mixed> */
	public function descriptor(string $path): array {
		$path=$this->normalizePath($path);
		return $this->withLock(LOCK_SH, function() use ($path): array {
			$file=$this->absolute($path);
			if(!is_file($file) || is_link($file)){
				throw new \RuntimeException('Media file does not exist: '.$path);
			}
			return $this->descriptorUnlocked($path, $file);
		});
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		return [
			'type'=>'panel_media_disk',
			'adapter'=>'local',
			'name'=>$this->name,
			'root'=>$this->root,
			'default_max_bytes'=>$this->defaultMaxBytes,
			'capabilities'=>[
				'atomic_write'=>true,
				'streams'=>true,
				'checksums'=>hash_algos(),
				'path_traversal_protection'=>true,
				'symlink_protection'=>true,
				'cross_process_locking'=>true,
			],
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return $this->manifest();
	}

	private function normalize(string $path, bool $allowEmpty): string {
		if(str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/', $path)===1){
			throw new \InvalidArgumentException('Media path contains control characters.');
		}
		$path=trim(str_replace('\\', '/', $path));
		if($path==='' && $allowEmpty){
			return '';
		}
		if($path==='' || str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path)===1 || str_contains($path, '://')){
			throw new \InvalidArgumentException('Media path must be relative.');
		}
		$segments=explode('/', $path);
		$clean=[];
		foreach($segments as $segment){
			if($segment==='' || $segment==='.'){
				continue;
			}
			if($segment==='..' || str_contains($segment, ':')){
				throw new \InvalidArgumentException('Media path traversal is not allowed.');
			}
			$clean[]=$segment;
		}
		if($clean===[] && !$allowEmpty){
			throw new \InvalidArgumentException('Media path cannot be empty.');
		}
		return implode('/', $clean);
	}

	private function absolute(string $path, bool $allowMissingLeaf=false): string {
		$relative=str_replace('/', DIRECTORY_SEPARATOR, $path);
		$absolute=$this->root.DIRECTORY_SEPARATOR.$relative;
		$current=$this->root;
		$segments=explode(DIRECTORY_SEPARATOR, $relative);
		foreach($segments as $index=>$segment){
			$current.=(str_ends_with($current, DIRECTORY_SEPARATOR) ? '' : DIRECTORY_SEPARATOR).$segment;
			if(file_exists($current) || is_link($current)){
				if(is_link($current)){
					throw new \RuntimeException('Media path crosses a symbolic link.');
				}
				if($index<count($segments)-1 && !is_dir($current)){
					throw new \RuntimeException('Media path parent is not a directory.');
				}
			}
			elseif(!$allowMissingLeaf && $index<count($segments)-1){
				break;
			}
		}
		return $absolute;
	}

	private function ensureParent(string $target): void {
		$parent=dirname($target);
		$relative=str_replace('\\', '/', substr($parent, strlen($this->root)+1));
		$current=$this->root;
		foreach(array_filter(explode('/', $relative), static fn(string $segment): bool => $segment!=='') as $segment){
			$current.=DIRECTORY_SEPARATOR.$segment;
			if(is_link($current)){
				throw new \RuntimeException('Media directory path crosses a symbolic link.');
			}
			if(file_exists($current)){
				if(!is_dir($current)){
					throw new \RuntimeException('Media path parent is not a directory.');
				}
				continue;
			}
			if(!@mkdir($current, 0770) && !is_dir($current)){
				throw new \RuntimeException('Unable to create media directory.');
			}
		}
	}

	private function replace(string $temp, string $target): void {
		$backup=null;
		if(file_exists($target)){
			if(!is_file($target) || is_link($target)){
				@unlink($temp);
				throw new \RuntimeException('Media target is not a replaceable regular file.');
			}
			$backup=dirname($target).DIRECTORY_SEPARATOR.'.'.basename($target).'.'.bin2hex(random_bytes(8)).'.bak';
			if(!@rename($target, $backup)){
				@unlink($temp);
				throw new \RuntimeException('Unable to stage existing media file for replacement.');
			}
		}
		if(!@rename($temp, $target)){
			if($backup!==null){
				@rename($backup, $target);
			}
			@unlink($temp);
			throw new \RuntimeException('Unable to atomically commit media file.');
		}
		if($backup!==null){
			@unlink($backup);
		}
	}

	/** @return array<string,mixed> */
	private function descriptorUnlocked(string $path, string $file): array {
		$mime='application/octet-stream';
		if(class_exists(\finfo::class)){
			$finfo=new \finfo(FILEINFO_MIME_TYPE);
			$detected=$finfo->file($file);
			if(is_string($detected) && $detected!==''){
				$mime=$detected;
			}
		}
		return [
			'disk'=>$this->name,
			'path'=>$path,
			'filename'=>basename($path),
			'size'=>(int)filesize($file),
			'mime'=>$mime,
			'checksum'=>(string)hash_file('sha256', $file),
			'modified_at'=>(int)filemtime($file),
		];
	}

	/** @template T @param callable():T $callback @return T */
	private function withLock(int $mode, callable $callback): mixed {
		$lock=@fopen($this->root.DIRECTORY_SEPARATOR.'.panel-media.lock', 'c+b');
		if(!is_resource($lock)){
			throw new \RuntimeException('Unable to open media disk lock.');
		}
		try {
			if(!flock($lock, $mode)){
				throw new \RuntimeException('Unable to acquire media disk lock.');
			}
			return $callback(); } finally {
			@flock($lock, LOCK_UN);
			@fclose($lock);
		}
	}

	private function removeEmptyParents(string $directory): void {
		while($directory!==$this->root && str_starts_with($directory, $this->root.DIRECTORY_SEPARATOR)){
			$entries=@scandir($directory);
			if($entries===false || count($entries)>2 || !@rmdir($directory)){
				break;
			}
			$directory=dirname($directory);
		}
	}

	private function recoverUnlocked(): void {
		$iterator=new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach($iterator as $file){
			if(!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()){
				continue;
			}
			$basename=$file->getBasename();
			$path=$file->getPathname();
			if(preg_match('/^\.(.+)\.[a-f0-9]{16}\.bak$/', $basename, $match)===1){
				$target=$file->getPath().DIRECTORY_SEPARATOR.$match[1];
				if(file_exists($target)){
					@unlink($path);
				}
				else {
					@rename($path, $target);
				}
			}
			elseif(preg_match('/^\..+\.[a-f0-9]{16}\.tmp$/', $basename)===1){
				@unlink($path);
			}
		}
	}
}
