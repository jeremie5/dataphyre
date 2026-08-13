<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Datadoc;

/** Immutable, integrity-checked output of Datadoc's static portal engine. */
final class DocumentationPortalBuild implements \JsonSerializable, \Countable, \IteratorAggregate {

	/**
	 * @param array<string,string> $files
	 * @param array<string,mixed> $manifest
	 * @param list<array{path:string,bytes:int,sha256:string}> $fileManifest
	 */
	private function __construct(
		private readonly string $version,
		private readonly array $files,
		private readonly array $manifest,
		private readonly array $fileManifest,
	){}

	/**
	 * @param array<string,string> $files
	 * @param array<string,mixed> $manifest
	 */
	public static function make(string $version,array $files,array $manifest):self {
		$version=DocumentationPortal::canonicalVersion($version);
		if($files===[]||array_is_list($files)){ throw new \InvalidArgumentException('Datadoc portal builds require a relative-path file map.'); }
		if(($manifest['type']??null)!=='dataphyre_datadoc_static_portal'||($manifest['schema_version']??null)!==1||($manifest['documentation_version']??null)!==$version){
			throw new \InvalidArgumentException('Datadoc portal build manifest identity is inconsistent.');
		}
		$normalized=[];
		$fileManifest=[];
		$keys=[];
		foreach($files as $path=>$contents){
			if(!is_string($path)||!is_string($contents)){ throw new \InvalidArgumentException('Datadoc portal build files must map paths to strings.'); }
			$path=self::relativePath($path);
			$key=strtolower($path);
			if(isset($keys[$key])){ throw new \InvalidArgumentException('Datadoc portal build paths must be unique without case ambiguity.'); }
			$keys[$key]=true;
			$normalized[$path]=$contents;
			$fileManifest[]=['path'=>$path,'bytes'=>strlen($contents),'sha256'=>hash('sha256',$contents)];
		}
		ksort($normalized,SORT_STRING);
		usort($fileManifest,static fn(array $left,array $right):int=>$left['path']<=>$right['path']);
		foreach(['portal.json','search-index.json','versions.json','assets/portal.css','assets/portal.js','assets/favicon.svg','index.html','404.html'] as $required){
			if(!array_key_exists($required,$normalized)){ throw new \InvalidArgumentException('Datadoc portal build is missing a required artifact.'); }
		}
		try{
			$portal=json_decode($normalized['portal.json'],true,128,JSON_THROW_ON_ERROR);
			$search=json_decode($normalized['search-index.json'],true,128,JSON_THROW_ON_ERROR);
			$versions=json_decode($normalized['versions.json'],true,128,JSON_THROW_ON_ERROR);
		}
		catch(\JsonException $error){ throw new \InvalidArgumentException('Datadoc portal build metadata must be valid JSON.',0,$error); }
		if(!is_array($portal)||self::canonical($portal)!==self::canonical($manifest)){
			throw new \InvalidArgumentException('Datadoc portal artifact does not match its manifest.');
		}
		if(!is_array($search)||($search['type']??null)!=='dataphyre_datadoc_search_index'||($search['documentation_version']??null)!==$version){
			throw new \InvalidArgumentException('Datadoc portal search index identity is inconsistent.');
		}
		if(!is_array($versions)||($versions['type']??null)!=='dataphyre_datadoc_versions'||($versions['current']??null)!==$version){
			throw new \InvalidArgumentException('Datadoc portal version index identity is inconsistent.');
		}
		foreach(['css'=>'assets/portal.css','javascript'=>'assets/portal.js','favicon'=>'assets/favicon.svg'] as $name=>$path){
			$asset=$manifest['assets'][$name]??null;
			if(!is_array($asset)||($asset['path']??null)!==$path||($asset['bytes']??null)!==strlen($normalized[$path])||($asset['sha256']??null)!==hash('sha256',$normalized[$path])){
				throw new \InvalidArgumentException('Datadoc portal asset manifest is inconsistent.');
			}
		}
		$contentAssets=$manifest['content_assets']??null;
		if(!is_array($contentAssets)||!array_is_list($contentAssets)||count($contentAssets)>10000||($manifest['content_asset_count']??null)!==count($contentAssets)||!is_int($manifest['content_asset_bytes']??null)||$manifest['content_asset_bytes']<0||$manifest['content_asset_bytes']>134217728){
			throw new \InvalidArgumentException('Datadoc portal content asset manifest is inconsistent.');
		}
		$contentAssetKeys=[];
		$contentAssetBytes=0;
		$previousContentAssetPath=null;
		foreach($contentAssets as $asset){
			if(!is_array($asset)||!is_string($asset['path']??null)||!is_string($asset['media_type']??null)||!is_int($asset['bytes']??null)||!is_string($asset['sha256']??null)){
				throw new \InvalidArgumentException('Datadoc portal content asset manifest is inconsistent.');
			}
			$path=self::relativePath($asset['path']);
			$key=strtolower($path);
			if($previousContentAssetPath!==null&&strcmp($previousContentAssetPath,$path)>=0){ throw new \InvalidArgumentException('Datadoc portal content asset manifest must use canonical path order.'); }
			$previousContentAssetPath=$path;
			if(isset($contentAssetKeys[$key])||!array_key_exists($path,$normalized)){ throw new \InvalidArgumentException('Datadoc portal content asset manifest is inconsistent.'); }
			$contentAssetKeys[$key]=true;
			$contents=$normalized[$path];
			try { $mediaType=DocumentationPortal::contentAssetMime($path,$contents); }
			catch(\Throwable $error){ throw new \InvalidArgumentException('Datadoc portal content asset manifest is inconsistent.',0,$error); }
			if($asset['media_type']!==$mediaType||$asset['bytes']!==strlen($contents)||$asset['sha256']!==hash('sha256',$contents)){
				throw new \InvalidArgumentException('Datadoc portal content asset manifest is inconsistent.');
			}
			$contentAssetBytes+=strlen($contents);
		}
		if($contentAssetBytes!==$manifest['content_asset_bytes']){ throw new \InvalidArgumentException('Datadoc portal content asset manifest is inconsistent.'); }
		foreach($normalized as $path=>$contents){
			if(preg_match('/\.(?:png|jpe?g|gif|webp|avif|ico)$/i',$path)===1&&!isset($contentAssetKeys[strtolower($path)])){
				throw new \InvalidArgumentException('Datadoc portal build contains an undeclared content asset.');
			}
		}
		return new self($version,$normalized,self::canonical($manifest),$fileManifest);
	}

	public function version():string { return $this->version; }

	/** @return array<string,string> */
	public function files():array { return $this->files; }

	public function file(string $path):?string {
		$path=str_replace('\\','/',trim($path));
		return $this->files[$path]??null;
	}

	/** @return array<string,mixed> */
	public function manifest():array { return $this->manifest; }

	/** @return list<array{path:string,bytes:int,sha256:string}> */
	public function fileManifest():array { return $this->fileManifest; }

	public function count():int { return count($this->files); }

	public function getIterator():\Traversable { return new \ArrayIterator($this->files); }

	/** @return array<string,mixed> */
	public function jsonSerialize():array {
		return [
			'type'=>'dataphyre_datadoc_portal_build',
			'schema_version'=>1,
			'documentation_version'=>$this->version,
			'file_count'=>count($this->files),
			'files'=>$this->fileManifest,
			'portal'=>$this->manifest,
		];
	}

	private static function relativePath(string $path):string {
		if($path===''||$path!==trim($path)||strlen($path)>4096||preg_match('//u',$path)!==1||preg_match('/[\x00-\x1f\x7f]/',$path)===1||str_contains($path,'\\')||str_contains($path,'%')||str_contains($path,'?')||str_contains($path,'#')||str_contains($path,':')||str_starts_with($path,'/')){
			throw new \InvalidArgumentException('Datadoc portal build paths must be safe relative UTF-8 paths.');
		}
		foreach(explode('/',$path) as $segment){
			if($segment===''||$segment==='.'||$segment==='..'||$segment!==trim($segment)||strlen($segment)>255){ throw new \InvalidArgumentException('Datadoc portal build paths must be safe relative UTF-8 paths.'); }
		}
		return $path;
	}

	/** @param array<mixed> $value @return array<mixed> */
	private static function canonical(array $value):array {
		foreach($value as $key=>$item){ if(is_array($item)){ $value[$key]=self::canonical($item); } }
		if(!array_is_list($value)){ ksort($value,SORT_STRING); }
		return $value;
	}
}
