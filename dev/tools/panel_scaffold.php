<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

/**
 * Root-confined Panel scaffold CLI.
 *
 * Preview one artifact:
 *   php dev/tools/panel_scaffold.php --kind resource --class OrderResource
 *
 * Apply a suite only with explicit write authority:
 *   php dev/tools/panel_scaffold.php --config panel-scaffold.json --write
 */

$projectRoot=dirname(__DIR__, 2);
$modules=$projectRoot.'/runtime/modules';
require_once $modules.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($modules);
\dataphyre\autoloader::register_prefixes([
	'Dataphyre\\Panel\\'=>$modules.'/panel/Framework',
	'Dataphyre\\'=>$modules.'/core/Framework',
]);

use Dataphyre\Panel\PanelScaffolder;
use Dataphyre\Panel\PanelScaffoldWriter;

$emit=static function(array $payload,int $code=0): never {
	fwrite($code===0 ? STDOUT : STDERR,json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
	exit($code);
};

try{
$arguments=$argv;
array_shift($arguments);
$options=[];
$allowed=['root','config','kind','class','options','base_path','namespace','policy','write','help'];
for($index=0;$index<count($arguments);$index++){
	$argument=(string)$arguments[$index];
	if(!str_starts_with($argument, '--')){
		throw new InvalidArgumentException('Unexpected positional argument: '.$argument);
	}
	$key=ltrim($argument, '-');
	$value=true;
	if(str_contains($key, '=')){
		[$key,$value]=explode('=', $key, 2);
	}
	elseif(isset($arguments[$index+1]) && !str_starts_with((string)$arguments[$index+1], '--')){
		$value=$arguments[++$index];
	}
	$key=str_replace('-', '_', strtolower(trim($key)));
	if(!in_array($key, $allowed, true)){
		throw new InvalidArgumentException('Unknown Panel scaffold option: --'.str_replace('_','-',$key));
	}
	if(array_key_exists($key, $options)){
		throw new InvalidArgumentException('Panel scaffold option was provided more than once: --'.str_replace('_','-',$key));
	}
	$options[$key]=$value;
}

$readJson=static function(string $path,string $label): array {
	if($path==='' || !is_file($path)){
		throw new InvalidArgumentException($label.' JSON file does not exist.');
	}
	try{
		$decoded=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
	}
	catch(JsonException $exception){
		throw new InvalidArgumentException($label.' JSON is invalid.',0,$exception);
	}
	if(!is_array($decoded)){
		throw new InvalidArgumentException($label.' JSON must decode to an object or array.');
	}
	return $decoded;
};
$isAbsolute=static fn(string $path): bool=>(bool)preg_match('/^[A-Za-z]:[\\\\\/]/',$path) || str_starts_with($path,'/');
$usage=[
	'ok'=>true,
	'usage'=>'panel_scaffold.php (--kind KIND --class CLASS | --config FILE) [--root DIR] [--namespace auto|BASE] [--base-path DIR] [--policy error|skip|replace] [--write]',
	'kinds'=>['resource','page','provider','plugin','theme','test'],
	'safety'=>['preview_by_default'=>true,'explicit_write_flag'=>true,'root_confined'=>true,'transactional'=>true],
];
if(($options['help'] ?? false)===true){ $emit($usage); }

	$config=[];
	$configPath='';
	if(isset($options['config'])){
		$configPath=(string)$options['config'];
		$config=$readJson($configPath,'Config');
	}
	if($config!==[] && (isset($options['kind']) || isset($options['class']) || isset($options['options']))){
		throw new InvalidArgumentException('--config cannot be combined with --kind, --class, or --options.');
	}

	$root=(string)($options['root'] ?? $config['root'] ?? getcwd());
	if(!$isAbsolute($root)){
		$base=$configPath!=='' ? dirname((string)realpath($configPath)) : (string)getcwd();
		$root=$base.DIRECTORY_SEPARATOR.$root;
	}
	$writer=PanelScaffoldWriter::make($root);
	$definitions=[];
	if($config!==[]){
		$definitions=is_array($config['artifacts'] ?? null) ? $config['artifacts'] : [];
		if($definitions===[]){
			throw new InvalidArgumentException('Panel scaffold config must contain a non-empty artifacts array.');
		}
	}
	else{
		$kind=trim((string)($options['kind'] ?? ''));
		$class=trim((string)($options['class'] ?? ''));
		if($kind==='' || $class===''){
			throw new InvalidArgumentException('Panel scaffold --kind and --class are required without --config.');
		}
		$artifactOptions=isset($options['options']) ? $readJson((string)$options['options'],'Options') : [];
		$definitions=[['kind'=>$kind,'class'=>$class,'options'=>$artifactOptions]];
	}

	$globalBase=trim((string)($options['base_path'] ?? $config['base_path'] ?? 'app/Panel'));
	$globalNamespace=trim((string)($options['namespace'] ?? $config['namespace'] ?? 'auto'));
	$prepared=[];
	foreach($definitions as $definition){
		if(!is_array($definition)){
			throw new InvalidArgumentException('Every Panel scaffold artifact definition must be an object.');
		}
		$kind=strtolower(trim((string)($definition['kind'] ?? $definition['type'] ?? '')));
		$class=trim((string)($definition['class'] ?? ''));
		if(!in_array($kind,$usage['kinds'],true) || $class===''){
			throw new InvalidArgumentException('Every Panel scaffold artifact requires a supported kind and non-empty class.');
		}
		$artifactOptions=is_array($definition['options'] ?? null) ? $definition['options'] : [];
		$isTest=$kind==='test';
		$basePath=trim((string)($artifactOptions['base_path'] ?? ($isTest ? ($config['test_base_path'] ?? 'tests/Panel') : $globalBase)));
		if($basePath===''){
			throw new InvalidArgumentException('Panel scaffold base paths cannot be empty.');
		}
		$artifactOptions['base_path']=$basePath;
		if(!isset($artifactOptions['namespace'])){
			$namespace=$isTest && isset($config['test_namespace']) ? trim((string)$config['test_namespace']) : $globalNamespace;
			if($namespace==='' || strtolower($namespace)==='auto'){
				$namespace=PanelScaffoldWriter::discoverNamespace($writer->root(),$basePath)['namespace'];
			}
			$suffix=match($kind){
				'resource'=>'Resources',
				'page'=>'Pages',
				'plugin'=>'Plugins',
				'theme'=>'Themes',
				default=>'',
			};
			$artifactOptions['namespace']=trim($namespace.($suffix!=='' ? '\\'.$suffix : ''),'\\');
		}
		$prepared[]=['kind'=>$kind,'class'=>$class,'options'=>$artifactOptions];
	}

	$artifacts=PanelScaffolder::make()->suite($prepared);
	if(count($artifacts)!==count($prepared)){
		throw new LogicException('Panel scaffolder did not produce every requested artifact.');
	}
	$policy=(string)($options['policy'] ?? $config['policy'] ?? 'error');
	$write=($options['write'] ?? false)===true;
	$result=$writer->apply($artifacts,$policy,!$write);
	$emit([
		'ok'=>true,
		'mode'=>$write ? 'write' : 'preview',
		'result'=>$result->jsonSerialize(),
		'artifacts'=>array_map(static fn($artifact): array=>$artifact->jsonSerialize(),$artifacts),
	]);
}
catch(Throwable $exception){
	$emit(['ok'=>false,'message'=>$exception->getMessage(),'exception'=>$exception::class],1);
}
