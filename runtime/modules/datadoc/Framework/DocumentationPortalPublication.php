<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Datadoc;

/**
 * Immutable, directory-atomic publication of one Datadoc portal build.
 * Existing byte-identical releases are idempotent; changed versions must use
 * a new SemVer instead of replacing an already published directory.
 */
final class DocumentationPortalPublication implements \JsonSerializable, \Countable, \IteratorAggregate {
	/**
	 * @param array<string,string> $files
	 * @param array<string,mixed> $manifest
	 */
	private function __construct(
		private readonly string $version,
		private readonly string $releasePrefix,
		private readonly array $files,
		private readonly array $manifest,
	){}

	/** @param array<string,mixed> $meta */
	public static function fromBuild(DocumentationPortalBuild $build,string $basePath='',array $meta=[]):self {
		$basePath=self::basePath($basePath);
		$version=$build->version();
		$releasePrefix=($basePath===''?'':$basePath.'/').'versions/'.$version;
		try { $metaJson=json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
		catch(\JsonException $error){ throw new \InvalidArgumentException('Datadoc publication metadata must be JSON serializable.',0,$error); }
		if(strlen($metaJson)>65536){ throw new \LengthException('Datadoc publication metadata exceeds its byte bound.'); }
		/** @var array<mixed> $normalizedMeta */
		$normalizedMeta=json_decode($metaJson,true,512,JSON_THROW_ON_ERROR);
		$manifest=self::canonical([
			'type'=>'dataphyre_datadoc_static_publication',
			'schema_version'=>1,
			'documentation_version'=>$version,
			'title'=>$build->manifest()['title'],
			'release_prefix'=>$releasePrefix,
			'portal_sha256'=>hash('sha256',(string)$build->file('portal.json')),
			'build_fingerprint'=>hash('sha256',self::json($build->jsonSerialize())),
			'artifact_count'=>count($build)+1,
			'files'=>$build->fileManifest(),
			'meta'=>$normalizedMeta,
			'portal'=>$build->manifest(),
		]);
		$files=$build->files();
		if(isset($files['publication.json'])){ throw new \LogicException('Datadoc portal build unexpectedly owns the publication manifest path.'); }
		$files['publication.json']=self::json($manifest);
		ksort($files,SORT_STRING);
		return new self($version,$releasePrefix,$files,$manifest);
	}

	public function version():string { return $this->version; }
	public function releasePrefix():string { return $this->releasePrefix; }

	/** @return array<string,string> */
	public function files():array { return $this->files; }

	public function file(string $path):?string {
		$path=str_replace('\\','/',trim($path));
		return $this->files[$path]??null;
	}

	/** @return array<string,mixed> */
	public function manifest():array { return $this->manifest; }

	public function count():int { return count($this->files); }
	public function getIterator():\Traversable { return new \ArrayIterator($this->files); }
	/** @return array<string,mixed> */
	public function jsonSerialize():array { return $this->manifest; }

	public function apply(string $root,bool $dryRun=true):DocumentationPortalWriteResult {
		$root=self::root($root);
		$target=self::join($root,$this->releasePrefix);
		if($dryRun){
			$existing=$this->existingResult($root,$target,true);
			return $existing??new DocumentationPortalWriteResult($root,$target,true,true,count($this->files));
		}

		$lockPath=$root.DIRECTORY_SEPARATOR.'.dataphyre-datadoc.lock';
		if(is_link($lockPath)){ throw new \RuntimeException('Datadoc publication lock path is unsafe.'); }
		$lock=@fopen($lockPath,'c+b');
		if(!is_resource($lock)||is_link($lockPath)){ throw self::lockFailure($lock); }
		if(!flock($lock,LOCK_EX|LOCK_NB)){ fclose($lock); throw new \RuntimeException('Datadoc publication is busy.'); }
		$changed=false;
		try {
			$existing=$this->existingResult($root,$target,false);
			if($existing!==null){ return $existing; }
			$stage=$root.DIRECTORY_SEPARATOR.'.dataphyre-datadoc-stage-'.bin2hex(random_bytes(12));
			if(!@mkdir($stage,0700,false)){ throw new \RuntimeException('Unable to create the Datadoc publication staging directory.'); }
			try {
				$this->writeTree($stage);
				$this->verifyTree($stage);
				self::exposeTree($stage);
				$parent=dirname($target);
				self::ensureDirectory($root,$parent);
				if(file_exists($target)||is_link($target)||!@rename($stage,$target)){ throw new \RuntimeException('Unable to atomically publish the Datadoc release directory.'); }
				$changed=true;
			}
			finally { self::removeTree($stage); }
		}
		finally { flock($lock,LOCK_UN); fclose($lock); }
		return new DocumentationPortalWriteResult($root,$target,false,$changed,count($this->files));
	}

	private function existingResult(string $root,string $target,bool $dryRun):?DocumentationPortalWriteResult {
		if(!file_exists($target)&&!is_link($target)){ return null; }
		$this->verifyTree($target);
		$permissionsChanged=!$dryRun&&self::exposeTree($target);
		return new DocumentationPortalWriteResult($root,$target,$dryRun,$permissionsChanged,count($this->files));
	}

	/** @param resource|false $lock */
	private static function lockFailure(mixed $lock):\RuntimeException {
		if(is_resource($lock)){ fclose($lock); }
		return new \RuntimeException('Unable to open the Datadoc publication lock.');
	}

	private function writeTree(string $directory):void {
		foreach($this->files as $relative=>$contents){
			$target=self::join($directory,$relative);
			$parent=dirname($target);
			if(!is_dir($parent)&&!@mkdir($parent,0775,true)&&!is_dir($parent)){ throw new \RuntimeException('Unable to create a Datadoc artifact directory.'); }
			$handle=@fopen($target,'xb');
			if(!is_resource($handle)){ throw new \RuntimeException('Unable to create a Datadoc publication artifact.'); }
			try {
				$offset=0;
				$length=strlen($contents);
				while($offset<$length){
					$written=fwrite($handle,substr($contents,$offset));
					if(!is_int($written)||$written<1){ throw new \RuntimeException('Unable to write a complete Datadoc publication artifact.'); }
					$offset+=$written;
				}
			}
			finally { fclose($handle); }
		}
	}

	/** Makes only a fully verified static tree readable by a separate web user. */
	private static function exposeTree(string $directory):bool {
		$changed=false;
		$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
		foreach($iterator as $entry){
			if($entry->isLink()||(!$entry->isDir()&&!$entry->isFile())){ throw new \RuntimeException('Datadoc release contains an unsafe filesystem entry.'); }
			if(DIRECTORY_SEPARATOR!=='\\'){
				$desired=$entry->isDir()?0755:0644;
				$entryPath=$entry->getPathname();
				clearstatcache(true,$entryPath);
				$actual=fileperms($entryPath);
				if(!is_int($actual)){ throw new \RuntimeException('Unable to inspect a verified Datadoc publication artifact.'); }
				if(($actual&0777)!==$desired){ if(!@chmod($entryPath,$desired)){ throw new \RuntimeException('Unable to expose a verified Datadoc publication artifact.'); } clearstatcache(true,$entryPath); $changed=true; }
			}
		}
		if(DIRECTORY_SEPARATOR!=='\\'){
			clearstatcache(true,$directory);
			$actual=fileperms($directory);
			if(!is_int($actual)){ throw new \RuntimeException('Unable to inspect the verified Datadoc publication directory.'); }
			if(($actual&0777)!==0755){ if(!@chmod($directory,0755)){ throw new \RuntimeException('Unable to expose the verified Datadoc publication directory.'); } clearstatcache(true,$directory); $changed=true; }
		}
		return $changed;
	}

	private function verifyTree(string $directory):void {
		if(is_link($directory)||!is_dir($directory)){ throw new \RuntimeException('Datadoc release target is not a safe directory.'); }
		$expected=[];
		foreach($this->files as $path=>$contents){ $expected[$path]=['bytes'=>strlen($contents),'sha256'=>hash('sha256',$contents)]; }
		$seen=[];
		$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::LEAVES_ONLY);
		foreach($iterator as $entry){
			if($entry->isLink()||!$entry->isFile()){ throw new \RuntimeException('Datadoc release contains an unsafe filesystem entry.'); }
			$relative=str_replace('\\','/',substr($entry->getPathname(),strlen(rtrim($directory,'/\\'))+1));
			$contract=$expected[$relative]??null;
			$actualHash=hash_file('sha256',$entry->getPathname());
			if(!is_array($contract)||$entry->getSize()!==$contract['bytes']||!is_string($actualHash)||!hash_equals($contract['sha256'],$actualHash)){
				throw new \RuntimeException('Datadoc release tree does not match its immutable publication.');
			}
			$seen[$relative]=true;
		}
		if(count($seen)!==count($expected)){ throw new \RuntimeException('Datadoc release tree is incomplete.'); }
	}

	private static function root(string $root):string {
		$root=trim($root);
		if($root===''||is_link($root)||!is_dir($root)){ throw new \InvalidArgumentException('Datadoc publication root must be an existing safe directory.'); }
		$real=realpath($root);
		if($real===false){ throw new \InvalidArgumentException('Datadoc publication root could not be resolved.'); }
		return dirname($real)===$real?$real:rtrim($real,'/\\');
	}

	private static function basePath(string $path):string {
		$path=str_replace('\\','/',$path);
		if(str_starts_with($path,'/')||(strlen($path)>=3&&ctype_alpha($path[0])&&$path[1]===':'&&$path[2]==='/')){ throw new \InvalidArgumentException('Datadoc publication base path must be relative.'); }
		$path=trim($path,'/');
		if($path===''){ return ''; }
		if(strlen($path)>2048||preg_match('//u',$path)!==1||preg_match('/[\x00-\x1f\x7f]/',$path)===1||str_contains($path,'%')||str_contains($path,'?')||str_contains($path,'#')||str_contains($path,':')){ throw new \InvalidArgumentException('Datadoc publication base path is unsafe.'); }
		foreach(explode('/',$path) as $segment){ if($segment===''||$segment==='.'||$segment==='..'||$segment!==trim($segment)||strlen($segment)>255){ throw new \InvalidArgumentException('Datadoc publication base path is unsafe.'); } }
		return $path;
	}

	private static function ensureDirectory(string $root,string $directory):void {
		$relative=ltrim(substr($directory,strlen($root)),'/\\');
		$current=$root;
		foreach($relative===''?[]:preg_split('~[/\\\\]+~',$relative) as $segment){
			$current.=DIRECTORY_SEPARATOR.$segment;
			if(is_link($current)){ throw new \RuntimeException('Datadoc publication parent path is unsafe.'); }
			if(!is_dir($current)){
				$created=@mkdir($current,0775,false);
				if(!$created&&!is_dir($current)){ throw new \RuntimeException('Unable to create the Datadoc publication parent directory.'); }
				if($created&&DIRECTORY_SEPARATOR!=='\\'){ if(!@chmod($current,0755)){ throw new \RuntimeException('Unable to expose a Datadoc publication parent directory.'); } clearstatcache(true,$current); }
			}
		}
		$real=realpath($directory);
		$rootPrefix=rtrim($root,'/\\').DIRECTORY_SEPARATOR;
		if($real===false||($real!==$root&&!str_starts_with($real,$rootPrefix))){ throw new \RuntimeException('Datadoc publication parent escaped its root.'); }
	}

	private static function join(string $root,string $relative):string {
		return rtrim($root,'/\\').DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
	}

	private static function removeTree(string $path):void {
		if($path===''||(!file_exists($path)&&!is_link($path))){ return; }
		if(is_link($path)||is_file($path)){ @unlink($path); return; }
		$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
		foreach($iterator as $entry){
			if($entry->isLink()||$entry->isFile()){ @unlink($entry->getPathname()); }
			else{ @rmdir($entry->getPathname()); }
		}
		@rmdir($path);
	}

	private static function json(array $value):string {
		try { return json_encode(self::canonical($value),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)."\n"; }
		catch(\JsonException $error){ throw new \RuntimeException('Datadoc publication data could not be encoded.',0,$error); }
	}

	/** @param array<mixed> $value @return array<mixed> */
	private static function canonical(array $value):array {
		foreach($value as $key=>$item){ if(is_array($item)){ $value[$key]=self::canonical($item); } }
		if(!array_is_list($value)){ ksort($value,SORT_STRING); }
		return $value;
	}
}
