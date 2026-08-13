<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** One root-confined, content-addressed release-evidence artifact. */
final class PanelReleaseEvidenceArtifact implements \JsonSerializable {
	public const MAX_BYTES=67108864;
	public const MAX_PATH_BYTES=1024;

	private function __construct(
		private readonly string $path,
		private readonly int $bytes,
		private readonly string $sha256,
		private readonly string $mediaType
	) {}

	public static function capture(string $root,string $path):self {
		$path=self::normalizePath($path);
		$file=self::resolveFile($root,$path);
		$bytes=filesize($file);
		if(!is_int($bytes)||$bytes<0||$bytes>self::MAX_BYTES){throw new \LengthException('Release evidence artifact exceeds its byte budget.');}
		$sha256=hash_file('sha256',$file);
		if(!is_string($sha256)){throw new \RuntimeException('Release evidence artifact could not be hashed.');}
		return new self($path,$bytes,$sha256,self::mediaType($path));
	}

	/** @param array<string,mixed> $payload */
	public static function fromArray(array $payload):self {
		self::exactKeys($payload,['type','version','path','bytes','sha256','media_type'],'artifact');
		if(($payload['type']??null)!=='panel_release_evidence_artifact'||($payload['version']??null)!==1){throw new \UnexpectedValueException('Release evidence artifact envelope is unsupported.');}
		$path=self::normalizePath((string)($payload['path']??''));
		$bytes=$payload['bytes']??null;
		$sha256=strtolower(trim((string)($payload['sha256']??'')));
		$mediaType=trim((string)($payload['media_type']??''));
		if(!is_int($bytes)||$bytes<0||$bytes>self::MAX_BYTES){throw new \InvalidArgumentException('Release evidence artifact byte count is invalid.');}
		if(preg_match('/^[a-f0-9]{64}$/D',$sha256)!==1){throw new \InvalidArgumentException('Release evidence artifact digest is invalid.');}
		if($mediaType===''||strlen($mediaType)>128||preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#D',$mediaType)!==1){throw new \InvalidArgumentException('Release evidence artifact media type is invalid.');}
		return new self($path,$bytes,$sha256,$mediaType);
	}

	public function path():string {return $this->path;}
	public function bytes():int {return $this->bytes;}
	public function sha256():string {return $this->sha256;}

	public function assertMatches(string $root):void {
		$current=self::capture($root,$this->path);
		if($current->bytes!==$this->bytes||!hash_equals($this->sha256,$current->sha256)){throw new \UnexpectedValueException('Release evidence artifact content no longer matches its attested digest.');}
	}

	/** @return array<string,mixed> */
	public function jsonSerialize():array {
		return ['type'=>'panel_release_evidence_artifact','version'=>1,'path'=>$this->path,'bytes'=>$this->bytes,'sha256'=>$this->sha256,'media_type'=>$this->mediaType];
	}

	/** @return list<string> */
	public static function inventory(string $root,int $maximum=512):array {
		$root=self::resolveRoot($root);
		$files=[];
		$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::LEAVES_ONLY);
		foreach($iterator as $entry){
			if(!$entry instanceof \SplFileInfo){continue;}
			if($entry->isLink()){throw new \UnexpectedValueException('Release evidence roots cannot contain symbolic links.');}
			if(!$entry->isFile()){continue;}
			$absolute=$entry->getPathname();
			$relative=str_replace(DIRECTORY_SEPARATOR,'/',substr($absolute,strlen($root)+1));
			$files[]=self::normalizePath($relative);
			if(count($files)>$maximum){throw new \LengthException('Release evidence artifact inventory exceeds its file budget.');}
		}
		sort($files,SORT_STRING);
		return $files;
	}

	public static function normalizePath(string $path):string {
		if($path!==trim($path)||$path===''||strlen($path)>self::MAX_PATH_BYTES||preg_match('//u',$path)!==1||preg_match('/[\x00-\x1F\x7F]/',$path)===1){throw new \InvalidArgumentException('Release evidence artifact path is invalid.');}
		$path=str_replace('\\','/',$path);
		if(str_starts_with($path,'/')||preg_match('/^[A-Za-z]:\//',$path)===1||str_contains($path,'//')){throw new \InvalidArgumentException('Release evidence artifact path must be relative and normalized.');}
		$segments=explode('/',$path);
		foreach($segments as $segment){
			if($segment===''||$segment==='.'||$segment==='..'||strlen($segment)>255){throw new \InvalidArgumentException('Release evidence artifact path contains an unsafe segment.');}
		}
		return implode('/',$segments);
	}

	private static function resolveRoot(string $root):string {
		$real=realpath($root);
		if(!is_string($real)||!is_dir($real)||is_link($root)){throw new \InvalidArgumentException('Release evidence root must be an existing non-link directory.');}
		return rtrim($real,DIRECTORY_SEPARATOR);
	}

	private static function resolveFile(string $root,string $path):string {
		$root=self::resolveRoot($root);
		$cursor=$root;
		foreach(explode('/',$path) as $segment){
			$cursor.=DIRECTORY_SEPARATOR.$segment;
			if(is_link($cursor)){throw new \UnexpectedValueException('Release evidence artifacts cannot traverse symbolic links.');}
		}
		$real=realpath($cursor);
		if(!is_string($real)||!is_file($real)){throw new \UnexpectedValueException('Release evidence artifact is missing.');}
		$prefix=$root.DIRECTORY_SEPARATOR;
		$inside=DIRECTORY_SEPARATOR==='\\'?str_starts_with(strtolower($real),strtolower($prefix)):str_starts_with($real,$prefix);
		if(!$inside){throw new \UnexpectedValueException('Release evidence artifact escaped its root.');}
		return $real;
	}

	private static function mediaType(string $path):string {
		return match(strtolower((string)pathinfo($path,PATHINFO_EXTENSION))){
			'json'=>'application/json','png'=>'image/png','jpg','jpeg'=>'image/jpeg','webp'=>'image/webp','svg'=>'image/svg+xml','html','htm'=>'text/html','css'=>'text/css','js','mjs'=>'text/javascript','xml'=>'application/xml','txt','log'=>'text/plain','zip'=>'application/zip',default=>'application/octet-stream',
		};
	}

	/** @param array<string,mixed> $payload @param list<string> $keys */
	private static function exactKeys(array $payload,array $keys,string $label):void {
		$actual=array_keys($payload);sort($actual,SORT_STRING);sort($keys,SORT_STRING);
		if($actual!==$keys){throw new \InvalidArgumentException('Release evidence '.$label.' contains unknown or missing fields.');}
	}
}
