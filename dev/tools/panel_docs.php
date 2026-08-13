<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

/**
 * Deterministic Panel API/cookbook publication CLI.
 *
 * Preview by default:
 *   php dev/tools/panel_docs.php --version 2.0.0
 *
 * Publish only with explicit authority:
 *   php dev/tools/panel_docs.php --version 2.0.0 --write
 */

use Dataphyre\Panel\PanelCompatibilityMatrix;
use Dataphyre\Panel\PanelDocumentationCatalog;
use Dataphyre\Panel\PanelDocumentationPortal;
use Dataphyre\Panel\PanelDocumentationPublisher;

$emit=static function(array $payload,int $code=0): never {
	$stream=$code===0 ? STDOUT : STDERR;
	fwrite($stream,json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
	exit($code);
};

set_error_handler(static function(int $severity,string $message,string $file,int $line): bool {
	if((error_reporting() & $severity)===0){ return false; }
	throw new ErrorException($message,0,$severity,$file,$line);
});

try{
	$project=dirname(__DIR__,2);
	$modules=$project.'/runtime/modules';
	require_once $modules.'/core/kernel/autoloader.php';
	\dataphyre\autoloader::register($modules);
	\dataphyre\autoloader::register_prefixes([
		'Dataphyre\\Datadoc\\'=>$modules.'/datadoc/Framework',
		'Dataphyre\\Panel\\'=>$modules.'/panel/Framework',
		'Dataphyre\\'=>$modules.'/core/Framework',
	]);
	$arguments=$argv;
	array_shift($arguments);
	$options=[];
	$allowed=['root','source','output','version','title','catalog','packages','runtime','paths','meta','portal','portal_config','write','help'];
	$boolean=['portal','write','help'];
	for($index=0;$index<count($arguments);$index++){
		$argument=(string)$arguments[$index];
		if(!str_starts_with($argument,'--')){ throw new InvalidArgumentException('Unexpected positional documentation argument.'); }
		$key=substr($argument,2);
		$value=true;
		if(str_contains($key,'=')){ [$key,$value]=explode('=',$key,2); }
		$key=str_replace('-','_',strtolower(trim($key)));
		if(!in_array($key,$allowed,true)){ throw new InvalidArgumentException('Unknown Panel documentation option.'); }
		if(array_key_exists($key,$options)){ throw new InvalidArgumentException('Panel documentation option was provided more than once.'); }
		if(in_array($key,$boolean,true)){
			if($value!==true){ throw new InvalidArgumentException('Boolean Panel documentation flags do not accept values.'); }
		}
		elseif($value===true){
			if(!isset($arguments[$index+1]) || str_starts_with((string)$arguments[$index+1],'--')){ throw new InvalidArgumentException('Panel documentation option requires a value.'); }
			$value=$arguments[++$index];
		}
		$options[$key]=$value;
	}

	$usage=[
		'ok'=>true,
		'usage'=>'panel_docs.php --version SEMVER [--root DIR] [--source DIR] [--output DIR] [--catalog JSON] [--packages JSON] [--runtime JSON] [--paths JSON] [--meta JSON] [--portal] [--portal-config JSON] [--write]',
		'safety'=>['preview_by_default'=>true,'source_not_executed'=>true,'project_confined'=>true,'immutable_versions'=>true,'atomic_version_publish'=>true,'portal_opt_in'=>true,'portal_external_dependencies'=>false],
	];
	if(($options['help'] ?? false)===true){ $emit($usage); }
	if(!isset($options['version'])){ throw new InvalidArgumentException('Panel documentation --version is required.'); }
	if(isset($options['portal_config']) && ($options['portal'] ?? false)!==true){ throw new InvalidArgumentException('Panel documentation --portal-config requires --portal.'); }

	$isAbsolute=static function(string $path): bool {
		$path=str_replace('\\','/',$path);
		return str_starts_with($path,'/') || (strlen($path)>=3 && ctype_alpha($path[0]) && $path[1]===':' && $path[2]==='/');
	};
	$rootInput=(string)($options['root'] ?? getcwd());
	if(!$isAbsolute($rootInput)){ $rootInput=getcwd().DIRECTORY_SEPARATOR.$rootInput; }
	$root=realpath($rootInput);
	if($root===false || !is_dir($root) || is_link($rootInput)){ throw new InvalidArgumentException('Panel documentation root must be an existing non-symlink directory.'); }
	$root=rtrim(str_replace('\\','/',$root),'/');
	$within=static function(string $path,bool $directory=false) use ($root,$isAbsolute): string {
		if(!$isAbsolute($path)){ $path=$root.'/'.str_replace('\\','/',$path); }
		$resolved=realpath($path);
		if($resolved===false || ($directory ? !is_dir($resolved) : !is_file($resolved))){ throw new InvalidArgumentException('Panel documentation input does not exist.'); }
		$resolved=str_replace('\\','/',$resolved);
		$left=DIRECTORY_SEPARATOR==='\\' ? strtolower($resolved) : $resolved;
		$right=DIRECTORY_SEPARATOR==='\\' ? strtolower($root) : $root;
		if($left!==$right && !str_starts_with($left,$right.'/')){ throw new InvalidArgumentException('Panel documentation input escaped the project root.'); }
		return $resolved;
	};
	$readJson=static function(string $path,string $label) use ($within): array {
		$path=$within($path,false);
		try { $decoded=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR); }
		catch(JsonException $exception){ throw new InvalidArgumentException($label.' JSON is invalid.',0,$exception); }
		if(!is_array($decoded)){ throw new InvalidArgumentException($label.' JSON must decode to an array or object.'); }
		return $decoded;
	};

	$source=$within((string)($options['source'] ?? 'runtime/modules/panel/Framework'),true);
	$output=(string)($options['output'] ?? 'docs/generated/panel');
	$catalogData=isset($options['catalog']) ? $readJson((string)$options['catalog'],'Catalog') : [];
	$catalogEntries=is_array($catalogData['entries'] ?? null) ? $catalogData['entries'] : $catalogData;
	$catalog=PanelDocumentationCatalog::make($catalogEntries);
	if(is_array($catalogData['meta'] ?? null)){ $catalog->meta($catalogData['meta']); }
	$packageData=isset($options['packages']) ? $readJson((string)$options['packages'],'Packages') : [];
	$packages=is_array($packageData['packages'] ?? null) ? $packageData['packages'] : $packageData;
	$runtime=isset($options['runtime']) ? $readJson((string)$options['runtime'],'Runtime') : (is_array($packageData['runtime'] ?? null) ? $packageData['runtime'] : []);
	$matrix=PanelCompatibilityMatrix::make($packages,$runtime);
	$paths=isset($options['paths']) ? $readJson((string)$options['paths'],'Paths') : null;
	if(is_array($paths) && isset($paths['paths'])){ $paths=$paths['paths']; }
	$meta=isset($options['meta']) ? $readJson((string)$options['meta'],'Meta') : [];
	$portalOptions=isset($options['portal_config']) ? $readJson((string)$options['portal_config'],'Portal configuration') : [];
	$publication=PanelDocumentationPublisher::make($source)->build(
		(string)$options['version'],
		$catalog,
		$matrix,
		[
			'base_path'=>$output,
			'title'=>(string)($options['title'] ?? 'Dataphyre Panel'),
			'source_paths'=>$paths,
			'meta'=>$meta,
		]
	);
	if(($options['portal'] ?? false)===true){ $publication=PanelDocumentationPortal::make()->decorate($publication,$portalOptions); }
	$write=($options['write'] ?? false)===true;
	$result=$publication->apply($root,'error',!$write);
	$emit([
		'ok'=>true,
		'mode'=>$write ? 'write' : 'preview',
		'publication'=>$publication->jsonSerialize(),
		'result'=>$result->jsonSerialize(),
	]);
}
catch(Throwable $exception){
	if($exception instanceof InvalidArgumentException){
		$code='invalid_argument';
		$public=$exception->getMessage();
	}
	elseif($exception instanceof RuntimeException){
		$code='operation_failed';
		$public='Panel documentation publication failed.';
	}
	else{
		$code='internal_error';
		$public='Panel documentation encountered an internal error.';
	}
	$emit(['ok'=>false,'error_code'=>$code,'message'=>$public,'exception'=>$exception::class],1);
}
