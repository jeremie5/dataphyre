<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Content-addressed, digest-verifying local cache for signed package bundles. */
final class PanelPackageArtifactCache implements \JsonSerializable {

	private string $root='';
	private array $errors=[];
	private array $meta=[];

	public function __construct(string $root, array $meta=[]) {
		$root=trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root));
		if($root==='' || is_link($root) || (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root))){
			$this->errors[]='Package artifact cache root could not be created safely.';
			return;
		}
		$real=realpath($root);
		if($real===false || is_link($root)){$this->errors[]='Package artifact cache root could not be resolved safely.';return;}
		$this->root=rtrim($real, DIRECTORY_SEPARATOR);
		@chmod($this->root, 0700);
		$this->meta=$this->sanitize($meta);
	}

	public static function make(string $root, array $meta=[]): self { return new self($root, $meta); }
	public function ready(): bool { return $this->root!=='' && $this->errors===[]; }
	/** @return array<int,string> */
	public function errors(): array { return $this->errors; }

	/**
	 * Reads one cache entry after digest, size, content-type, symlink, and trusted
	 * host-clock freshness checks.
	 *
	 * @return array{body:string,sha256:string,bytes:int,content_type:string,stored_at:string,stale:bool,meta:array}|null
	 */
	public function read(string $digest, array $options=[]): ?array {
		$digest=$this->normalizeDigest($digest);
		if(!$this->ready() || $digest===''){return null;}
		[$bodyPath,$metaPath]=$this->paths($digest);
		if(!is_file($bodyPath) || is_link($bodyPath) || $this->containsSymlink($bodyPath)){return null;}
		$maxBytes=max(1, min(1073741824, (int)($options['max_bytes'] ?? 67108864)));
		$size=(int)@filesize($bodyPath);
		if($size<1 || $size>$maxBytes){return null;}
		$body=@file_get_contents($bodyPath);
		if(!is_string($body) || strlen($body)!==$size || !hash_equals($digest, hash('sha256', $body))){return null;}
		$metadata=[];
		$metadataSize=is_file($metaPath) && !is_link($metaPath) ? (int)@filesize($metaPath) : -1;
		if($metadataSize<2 || $metadataSize>65536){return null;}
		if(is_file($metaPath) && !is_link($metaPath)){
			$decoded=json_decode((string)@file_get_contents($metaPath), true);
			if(is_array($decoded)){$metadata=$decoded;}
		}
		if(($metadata['format'] ?? null)!=='dataphyre.panel.package.cache.v1'
			|| !is_string($metadata['sha256'] ?? null)
			|| !hash_equals($digest, $this->normalizeDigest($metadata['sha256']))
			|| !is_int($metadata['bytes'] ?? null) || $metadata['bytes']!==$size
			|| !is_string($metadata['content_type'] ?? null)
			|| !is_string($metadata['stored_at'] ?? null)){return null;}
		$contentType=strtolower(trim($metadata['content_type']));
		$expectedType=strtolower(trim((string)($options['content_type'] ?? '')));
		if($expectedType!=='' && $contentType!==$expectedType){return null;}
		$storedAt=$metadata['stored_at'];
		$stored=$this->parseTime($storedAt);
		if($stored===null){return null;}
		$now=(int)($options['now'] ?? time());
		$maxAge=max(60, min(31536000, (int)($options['max_age_seconds'] ?? 2592000)));
		$stale=$stored>$now+300 || ($now-$stored)>$maxAge;
		if($stale && empty($options['allow_stale'])){return null;}
		return [
			'body'=>$body,'sha256'=>$digest,'bytes'=>$size,'content_type'=>$contentType,
			'stored_at'=>$storedAt,'stale'=>$stale,
			'meta'=>is_array($metadata['meta'] ?? null) ? $this->sanitize($metadata['meta']) : [],
		];
	}

	/** Stores verified bytes atomically under their digest-derived path. */
	public function write(string $digest, string $body, string $contentType, array $meta=[], array $options=[]): bool {
		$digest=$this->normalizeDigest($digest);
		$contentType=strtolower(trim(explode(';', $contentType)[0]));
		if(!$this->ready() || $digest==='' || $body==='' || $contentType==='' || !hash_equals($digest, hash('sha256', $body))){return false;}
		[$bodyPath,$metaPath]=$this->paths($digest);
		$directory=dirname($bodyPath);
		if(is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) || $this->containsSymlink($bodyPath)){return false;}
		@chmod($directory, 0700);
		$lock=$this->lock($digest);
		if(!is_resource($lock)){return false;}
		try{
			if(is_file($bodyPath) && !is_link($bodyPath)){
				$existing=hash_file('sha256', $bodyPath) ?: '';
				if($existing==='' || !hash_equals($digest, $existing)){return false;}
			}
			else{
				try{$suffix=bin2hex(random_bytes(10));}catch(\Throwable){$suffix=str_replace('.', '', uniqid('', true));}
				$staged=$directory.DIRECTORY_SEPARATOR.'.'.$digest.'.'.$suffix.'.tmp';
				try{
					$bytes=@file_put_contents($staged, $body, LOCK_EX);
					if($bytes===false || $bytes!==strlen($body) || !hash_equals($digest, hash_file('sha256', $staged) ?: '')){return false;}
					if(!@link($staged, $bodyPath)){
						if(!is_file($bodyPath) || is_link($bodyPath) || !hash_equals($digest, hash_file('sha256', $bodyPath) ?: '')){return false;}
					}
				}
				finally{if(isset($staged) && (is_file($staged) || is_link($staged))){@unlink($staged);}}
			}
			$metadata=[
				'format'=>'dataphyre.panel.package.cache.v1','sha256'=>$digest,'bytes'=>strlen($body),
				'content_type'=>$contentType,'stored_at'=>date(DATE_ATOM, max(0, (int)($options['now'] ?? time()))),'meta'=>$this->sanitize($meta),
			];
			try{$suffix=bin2hex(random_bytes(8));}catch(\Throwable){$suffix=str_replace('.', '', uniqid('', true));}
			$stagedMeta=$directory.DIRECTORY_SEPARATOR.'.'.$digest.'.'.$suffix.'.json.tmp';
			$json=json_encode($metadata, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
			if(strlen($json)>65536){return false;}
			$written=@file_put_contents($stagedMeta, $json, LOCK_EX);
			if($written===false || $written!==strlen($json)){@unlink($stagedMeta);return false;}
			if(is_link($metaPath)){@unlink($stagedMeta);return false;}
			if(is_file($metaPath) && !@unlink($metaPath)){@unlink($stagedMeta);return false;}
			if(!@rename($stagedMeta, $metaPath)){@unlink($stagedMeta);return false;}
			return true;
		}
		finally{@flock($lock, LOCK_UN);@fclose($lock);}
	}

	/** @return array<string,mixed> Cache capability manifest without local paths. */
	public function toArray(): array {
		return [
			'type'=>'panel_package_artifact_cache','ready'=>$this->ready(),
			'root_digest'=>$this->root!=='' ? hash('sha256', $this->root) : '',
			'errors'=>$this->errors,'meta'=>$this->meta,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	/** @return array{0:string,1:string} */
	private function paths(string $digest): array {
		$directory=$this->root.DIRECTORY_SEPARATOR.substr($digest, 0, 2);
		return [$directory.DIRECTORY_SEPARATOR.$digest.'.package.json', $directory.DIRECTORY_SEPARATOR.$digest.'.meta.json'];
	}

	/** @return resource|null */
	private function lock(string $digest) {
		$directory=$this->root.DIRECTORY_SEPARATOR.'.locks';
		if(is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))){return null;}
		@chmod($directory, 0700);
		$path=$directory.DIRECTORY_SEPARATOR.$digest.'.lock';
		if($this->containsSymlink($path)){return null;}
		$handle=@fopen($path, 'c+');
		if(!is_resource($handle) || $this->containsSymlink($path) || !@flock($handle, LOCK_EX)){if(is_resource($handle)){@fclose($handle);}return null;}
		return $handle;
	}

	private function containsSymlink(string $path): bool {
		if($this->root==='' || !$this->insideRoot($path)){return true;}
		$relative=ltrim(substr($path, strlen($this->root)), DIRECTORY_SEPARATOR);
		$current=$this->root;
		foreach(explode(DIRECTORY_SEPARATOR, $relative) as $segment){
			if($segment==='' || $segment==='.' || $segment==='..'){return true;}
			$current.=DIRECTORY_SEPARATOR.$segment;
			if(is_link($current)){return true;}
			if(file_exists($current) || is_dir($current)){
				$real=realpath($current);
				if($real===false || !$this->insideRoot($real)){return true;}
			}
		}
		return false;
	}

	private function insideRoot(string $path): bool {
		$root=$this->root;
		if(PHP_OS_FAMILY==='Windows'){$root=strtolower($root);$path=strtolower($path);}
		return $path===$root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
	}

	private function normalizeDigest(string $digest): string {
		$digest=strtolower(trim($digest));if(str_starts_with($digest,'sha256:')){$digest=substr($digest,7);}
		return preg_match('/^[a-f0-9]{64}$/',$digest)===1 ? $digest : '';
	}

	private function parseTime(string $value): ?int {
		if(preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D', $value)!==1){return null;}
		$canonical=str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
		$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $canonical);
		$errors=\DateTimeImmutable::getLastErrors();
		if($parsed===false || (is_array($errors) && ($errors['warning_count']!==0 || $errors['error_count']!==0)) || $parsed->format('Y-m-d\TH:i:sP')!==$canonical){return null;}
		return $parsed->getTimestamp();
	}

	private function sanitize(mixed $value, string $key=''): mixed {
		if($key!=='' && $this->sensitiveKey($key)){return '[REDACTED]';}
		if(!is_array($value)){return is_object($value) ? '[OBJECT]' : $value;}$safe=[];
		foreach($value as $itemKey=>$item){$safe[$itemKey]=$this->sanitize($item,is_string($itemKey)?$itemKey:'');}return $safe;
	}

	private function sensitiveKey(string $key): bool {
		$key=preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key;
		return preg_match('/(?:^|[_\-.])(?:secret|password|passwd|token|private[_\-.]?key|secret[_\-.]?key|seed|credential|authorization|cookie|bearer|api[_\-.]?key|access[_\-.]?key)(?:$|[_\-.])/i', $key)===1;
	}
}
