<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable in-memory SDK artifact bundle with deterministic integrity metadata. */
final class PanelSdkPackage implements \JsonSerializable {
	/** @var array<string,string> */private readonly array $files;
	/** @var array<string,array{bytes:int,sha256:string,content_type:string}> */private readonly array $fileManifest;
	private readonly string $fingerprint;

	/** @param array<string,string> $files @param list<string> $targets */
	private function __construct(private readonly PanelSdkContract $contract,array $files,private readonly array $targets){
		if($files===[]||count($files)>128){throw new \InvalidArgumentException('Panel SDK package must contain between one and 128 files.');}$normalized=[];$caseFold=[];$bytes=0;
		foreach($files as$path=>$contents){if(!is_string($path)||!is_string($contents)){throw new \InvalidArgumentException('Panel SDK package files must map paths to string contents.');}$path=PanelSdkGuard::filePath($path);$fold=strtolower($path);if(isset($caseFold[$fold])){throw new \InvalidArgumentException('Panel SDK package paths must be case-insensitively unique.');}$caseFold[$fold]=true;$bytes+=strlen($contents);if($bytes>16777216){throw new \LengthException('Panel SDK package exceeds 16 MiB.');}$normalized[$path]=$contents;}ksort($normalized,SORT_STRING);$this->files=$normalized;
		$manifest=[];foreach($normalized as$path=>$contents){$manifest[$path]=['bytes'=>strlen($contents),'sha256'=>hash('sha256',$contents),'content_type'=>self::contentType($path)];}$this->fileManifest=$manifest;
		$this->fingerprint=PanelSdkGuard::fingerprint(['contract'=>$contract->fingerprint(),'targets'=>$targets,'files'=>$manifest]);
	}

	/** @param array<string,string> $files @param list<string> $targets */public static function make(PanelSdkContract $contract,array $files,array $targets):self {$targets=PanelSdkGuard::names($targets,'SDK target',8);if($targets===[]){throw new \InvalidArgumentException('Panel SDK package requires at least one target.');}return new self($contract,$files,$targets);}
	public function contract():PanelSdkContract{return$this->contract;}/** @return list<string> */public function targets():array{return$this->targets;}/** @return array<string,string> */public function files():array{return$this->files;}public function file(string $path):?string{return$this->files[PanelSdkGuard::filePath($path)]??null;}/** @return array<string,array{bytes:int,sha256:string,content_type:string}> */public function fileManifest():array{return$this->fileManifest;}public function fingerprint():string{return$this->fingerprint;}
	public function verify():bool {foreach($this->fileManifest as$path=>$expected){$body=$this->files[$path]??null;if(!is_string($body)||strlen($body)!==$expected['bytes']||!hash_equals($expected['sha256'],hash('sha256',$body))){return false;}}return hash_equals($this->fingerprint,PanelSdkGuard::fingerprint(['contract'=>$this->contract->fingerprint(),'targets'=>$this->targets,'files'=>$this->fileManifest]));}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_sdk_package_manifest','version'=>1,'contract'=>['id'=>$this->contract->id(),'version'=>$this->contract->version(),'fingerprint'=>$this->contract->fingerprint()],'targets'=>$this->targets,'files'=>$this->fileManifest,'bytes'=>array_sum(array_column($this->fileManifest,'bytes')),'fingerprint'=>$this->fingerprint,'verified'=>$this->verify(),'contents_exposed'=>false,'capabilities'=>['deterministic'=>true,'filesystem_writes'=>false,'case_collision_protection'=>true,'per_file_sha256'=>true]]);}

	private static function contentType(string $path):string {return match(strtolower(pathinfo($path,PATHINFO_EXTENSION))){'php'=>'application/x-httpd-php; charset=utf-8','ts'=>'text/typescript; charset=utf-8','json'=>'application/json; charset=utf-8','md'=>'text/markdown; charset=utf-8',default=>'text/plain; charset=utf-8'};}
}
