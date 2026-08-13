<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

/**
 * Universal Datadoc static-portal publisher.
 *
 * Preview by default:
 *   php dev/tools/datadoc_docs.php --source docs --version 2.0.0
 *
 * Publish only with explicit authority:
 *   php dev/tools/datadoc_docs.php --source docs --version 2.0.0 --write
 */

use Dataphyre\Datadoc\DocumentationPortal;
use Dataphyre\Datadoc\DocumentationPortalPublication;
use Dataphyre\Datadoc\DocumentationCorpus;

$emit=static function(array $payload,int $code=0):never {
	$stream=$code===0?STDOUT:STDERR;
	fwrite($stream,json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
	exit($code);
};

set_error_handler(static function(int $severity,string $message,string $file,int $line):bool {
	if((error_reporting()&$severity)===0){ return false; }
	throw new ErrorException($message,0,$severity,$file,$line);
});

try {
	$project=dirname(__DIR__,2);
	$modules=$project.'/runtime/modules';
	require_once $modules.'/core/kernel/autoloader.php';
	\dataphyre\autoloader::register($modules);
	\dataphyre\autoloader::register_prefixes([
		'Dataphyre\\Datadoc\\'=>$modules.'/datadoc/Framework',
		'Dataphyre\\'=>$modules.'/core/Framework',
	]);

	$arguments=$argv;
	array_shift($arguments);
	$options=[];
	$allowed=['root','source','mount','output','version','title','portal_config','meta','workspace','write','help'];
	$boolean=['workspace','write','help'];
	$repeatable=['mount'];
	for($index=0;$index<count($arguments);$index++){
		$argument=(string)$arguments[$index];
		if(!str_starts_with($argument,'--')){ throw new InvalidArgumentException('Unexpected positional Datadoc argument.'); }
		$key=substr($argument,2);
		$value=true;
		if(str_contains($key,'=')){ [$key,$value]=explode('=',$key,2); }
		$key=str_replace('-','_',strtolower(trim($key)));
		if(!in_array($key,$allowed,true)){ throw new InvalidArgumentException('Unknown Datadoc documentation option.'); }
		if(array_key_exists($key,$options)&&!in_array($key,$repeatable,true)){ throw new InvalidArgumentException('Datadoc documentation option was provided more than once.'); }
		if(in_array($key,$boolean,true)){
			if($value!==true){ throw new InvalidArgumentException('Boolean Datadoc documentation flags do not accept values.'); }
		}
		elseif($value===true){
			if(!isset($arguments[$index+1])||str_starts_with((string)$arguments[$index+1],'--')){ throw new InvalidArgumentException('Datadoc documentation option requires a value.'); }
			$value=$arguments[++$index];
		}
		if(in_array($key,$repeatable,true)){
			$options[$key]??=[];
			if(count($options[$key])>=256){ throw new LengthException('Datadoc documentation source mount count exceeds its bound.'); }
			$options[$key][]=$value;
		}
		else{ $options[$key]=$value; }
	}

	$usage=[
		'ok'=>true,
		'usage'=>'datadoc_docs.php (--source DIR | --workspace) [--mount PREFIX=DIR ...] --version SEMVER [--root DIR] [--output DIR] [--title TEXT] [--portal-config JSON] [--meta JSON] [--write]',
		'ownership'=>[
			'engine'=>'datadoc',
			'publication'=>'datadoc',
			'content_producers'=>'module-neutral',
		],
		'limits'=>[
			'maximum_sources'=>256,
			'maximum_mounts'=>256,
			'maximum_pages'=>10000,
			'maximum_content_assets'=>10000,
		],
		'safety'=>[
			'preview_by_default'=>true,
			'source_not_executed'=>true,
			'project_confined'=>true,
			'symlinks_rejected'=>true,
			'inert_raster_assets_only'=>true,
			'deterministic_source_mounts'=>true,
			'deterministic_workspace_discovery'=>true,
			'workspace_module_code_not_loaded'=>true,
			'publication_output_excluded_from_corpus'=>true,
			'immutable_versions'=>true,
			'atomic_version_publish'=>true,
			'external_dependencies'=>false,
		],
	];
	if(($options['help']??false)===true){ $emit($usage); }
	if(!isset($options['source'])&&($options['workspace']??false)!==true){ throw new InvalidArgumentException('Datadoc documentation requires --source or --workspace.'); }
	if(!isset($options['version'])){ throw new InvalidArgumentException('Datadoc documentation --version is required.'); }

	$isAbsolute=static function(string $path):bool {
		$path=str_replace('\\','/',$path);
		return str_starts_with($path,'/')||(strlen($path)>=3&&ctype_alpha($path[0])&&$path[1]===':'&&$path[2]==='/');
	};
	$rootInput=(string)($options['root']??getcwd());
	if(!$isAbsolute($rootInput)){ $rootInput=getcwd().DIRECTORY_SEPARATOR.$rootInput; }
	$root=realpath($rootInput);
	if($root===false||!is_dir($root)||is_link($rootInput)){ throw new InvalidArgumentException('Datadoc documentation root must be an existing non-symlink directory.'); }
	$root=rtrim(str_replace('\\','/',$root),'/');
	if($root===''){ $root='/'; }
	$within=static function(string $path,bool $directory=false) use ($root,$isAbsolute):string {
		if(!$isAbsolute($path)){ $path=rtrim($root,'/').'/'.str_replace('\\','/',$path); }
		if(is_link($path)){ throw new InvalidArgumentException('Datadoc documentation input must not be a symlink.'); }
		$resolved=realpath($path);
		if($resolved===false||($directory?!is_dir($resolved):!is_file($resolved))){ throw new InvalidArgumentException('Datadoc documentation input does not exist.'); }
		$resolved=str_replace('\\','/',$resolved);
		$left=DIRECTORY_SEPARATOR==='\\'?strtolower($resolved):$resolved;
		$right=DIRECTORY_SEPARATOR==='\\'?strtolower($root):$root;
		$prefix=rtrim($right,'/').'/';
		if($left!==$right&&!str_starts_with($left,$prefix)){ throw new InvalidArgumentException('Datadoc documentation input escaped the project root.'); }
		return $resolved;
	};
	$readJson=static function(string $path,string $label) use ($within):array {
		$path=$within($path,false);
		$contents=file_get_contents($path,false,null,0,1048577);
		if(!is_string($contents)||strlen($contents)>1048576){ throw new LengthException($label.' JSON exceeds its byte bound.'); }
		try { $decoded=json_decode($contents,true,512,JSON_THROW_ON_ERROR); }
		catch(JsonException $error){ throw new InvalidArgumentException($label.' JSON is invalid.',0,$error); }
		if(!is_array($decoded)){ throw new InvalidArgumentException($label.' JSON must decode to an array or object.'); }
		return $decoded;
	};

	$mounts=[];
	$mountKeys=[];
	foreach((array)($options['mount']??[]) as $mount){
		if(!is_string($mount)||($separator=strpos($mount,'='))===false||$separator===0||$separator===strlen($mount)-1){ throw new InvalidArgumentException('Datadoc documentation mounts must use PREFIX=DIR.'); }
		$prefix=substr($mount,0,$separator);
		$key=strtolower($prefix);
		if(isset($mountKeys[$key])){ throw new InvalidArgumentException('Datadoc documentation mount prefixes must be unique without case ambiguity.'); }
		$mountKeys[$key]=true;
		$mounts[$prefix]=substr($mount,$separator+1);
	}
	$title=(string)($options['title']??'Dataphyre Documentation');
	$output=(string)($options['output']??'docs/generated/datadoc');
	$corpus=DocumentationCorpus::discover(
		$root,
		isset($options['source'])?(string)$options['source']:null,
		$mounts,
		($options['workspace']??false)===true,
		$title,
		[$output],
	);
	$documents=$corpus->documents();
	$contentAssets=$corpus->contentAssets();

	$portalOptions=isset($options['portal_config'])?$readJson((string)$options['portal_config'],'Portal configuration'):[];
	$meta=isset($options['meta'])?$readJson((string)$options['meta'],'Publication metadata'):[];
	$build=DocumentationPortal::make()->build(
		(string)$options['version'],
		$title,
		$documents,
		[],
		$portalOptions,
		$contentAssets,
	);
	$publication=DocumentationPortalPublication::fromBuild(
		$build,
		$output,
		$meta,
	);
	$write=($options['write']??false)===true;
	$result=$publication->apply($root,!$write);
	$emit([
		'ok'=>true,
		'mode'=>$write?'write':'preview',
		'corpus'=>$corpus->manifest(),
		'build'=>$build->jsonSerialize(),
		'publication'=>$publication->jsonSerialize(),
		'result'=>$result->jsonSerialize(),
	]);
}
catch(Throwable $exception){
	if($exception instanceof InvalidArgumentException||$exception instanceof LengthException){
		$code='invalid_argument';
		$public=$exception->getMessage();
	}
	elseif($exception instanceof RuntimeException){
		$code='operation_failed';
		$public='Datadoc documentation publication failed.';
	}
	else{
		$code='internal_error';
		$public='Datadoc documentation encountered an internal error.';
	}
	$emit(['ok'=>false,'error_code'=>$code,'message'=>$public,'exception'=>$exception::class],1);
}
