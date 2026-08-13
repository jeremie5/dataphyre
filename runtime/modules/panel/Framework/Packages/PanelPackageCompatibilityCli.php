<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Read-only, root-confined CLI adapter for package compatibility plans. */
final class PanelPackageCompatibilityCli {
	private const MAX_CONFIG_BYTES=2097152;
	private const MAX_BASELINE_BYTES=8388608;

	/** @return array{exit_code:int,payload:array<string,mixed>} */
	public static function execute(array $argv,?string $cwd=null): array {
		try{
			$options=self::options($argv);
			$usage=[
				'ok'=>true,'mode'=>'help','usage'=>'panel_package_compatibility.php --config FILE [--baseline FILE] [--root DIR]',
				'exit_codes'=>['compatible'=>0,'policy_failure'=>1,'invalid_input'=>2],
				'safety'=>['read_only'=>true,'transport_neutral'=>true,'root_confined_inputs'=>true,'no_install'=>true],
			];
			if(($options['help'] ?? false)===true){return ['exit_code'=>0,'payload'=>$usage];}
			if(!isset($options['config'])){throw new \InvalidArgumentException('Compatibility CLI --config is required.');}
			$cwd=$cwd ?? getcwd();
			if(!is_string($cwd) || $cwd===''){throw new \InvalidArgumentException('Compatibility CLI working directory is unavailable.');}
			$root=self::root((string)($options['root'] ?? $cwd),$cwd);
			$config=self::readJson(self::input((string)$options['config'],$root),self::MAX_CONFIG_BYTES,'Compatibility config');
			$baseline=isset($options['baseline']) ? self::readJson(self::input((string)$options['baseline'],$root),self::MAX_BASELINE_BYTES,'Compatibility baseline') : [];
			$plan=PanelPackageCompatibilityPlan::make($config);
			$report=$plan->report($baseline);
			return ['exit_code'=>$report->ok() ? 0 : 1,'payload'=>[
				'ok'=>$report->ok(),'mode'=>'preview','read_only'=>true,'plan'=>$plan->toArray(),'report'=>$report->toArray(),
			]];
		}
		catch(\Throwable $exception){
			return ['exit_code'=>2,'payload'=>['ok'=>false,'mode'=>'invalid','read_only'=>true,'message'=>self::safeMessage($exception->getMessage())]];
		}
	}

	/** @return array<string,string|bool> */
	private static function options(array $argv): array {
		array_shift($argv);$options=[];$allowed=['root','config','baseline','help'];
		for($index=0;$index<count($argv);$index++){
			$argument=$argv[$index] ?? null;
			if(!is_string($argument) || !str_starts_with($argument,'--')){throw new \InvalidArgumentException('Unexpected positional compatibility argument.');}
			$key=substr($argument,2);$value=true;
			if(str_contains($key,'=')){[$key,$value]=explode('=',$key,2);}
			$key=str_replace('-','_',strtolower(trim($key)));
			if(!in_array($key,$allowed,true)){throw new \InvalidArgumentException('Unknown compatibility CLI option.');}
			if(array_key_exists($key,$options)){throw new \InvalidArgumentException('Compatibility CLI option was provided more than once.');}
			if($key==='help'){
				if($value!==true){throw new \InvalidArgumentException('Compatibility CLI help flag does not accept a value.');}
			}
			elseif($value===true){
				$next=$argv[$index+1] ?? null;
				if(!is_string($next) || str_starts_with($next,'--')){throw new \InvalidArgumentException('Compatibility CLI option requires a value.');}
				$value=$next;$index++;
			}
			if(is_string($value) && ($value==='' || strlen($value)>4096 || str_contains($value,"\0"))){throw new \InvalidArgumentException('Compatibility CLI option value is invalid.');}
			$options[$key]=$value;
		}
		return $options;
	}

	private static function root(string $input,string $cwd): string {
		if(!self::absolute($input)){$input=rtrim($cwd,'/\\').DIRECTORY_SEPARATOR.$input;}
		$real=realpath($input);
		if($real===false || !is_dir($real) || is_link($input)){throw new \InvalidArgumentException('Compatibility CLI root must be an existing non-symlink directory.');}
		return rtrim(str_replace('\\','/',$real),'/');
	}

	private static function input(string $input,string $root): string {
		$path=self::absolute($input) ? $input : $root.'/'.str_replace('\\','/',$input);
		$real=realpath($path);
		if($real===false || !is_file($real) || is_link($path)){throw new \InvalidArgumentException('Compatibility CLI JSON input does not exist or is a symbolic link.');}
		$real=str_replace('\\','/',$real);$left=PHP_OS_FAMILY==='Windows'?strtolower($real):$real;$right=PHP_OS_FAMILY==='Windows'?strtolower($root):$root;
		if($left===$right || !str_starts_with($left,$right.'/')){throw new \InvalidArgumentException('Compatibility CLI JSON input escaped the configured root.');}
		return $real;
	}

	/** @return array<string,mixed> */
	private static function readJson(string $path,int $maxBytes,string $label): array {
		clearstatcache(true,$path);$before=@lstat($path);$handle=@fopen($path,'rb');
		if(!is_array($before) || !is_resource($handle)){if(is_resource($handle)){fclose($handle);}throw new \RuntimeException($label.' could not be opened safely.');}
		try{
			$opened=fstat($handle);clearstatcache(true,$path);$current=@lstat($path);
			if(!self::regular($before) || !is_array($opened) || !self::regular($opened) || !is_array($current) || !self::sameFile($before,$opened) || !self::sameFile($opened,$current) || is_link($path)){throw new \RuntimeException($label.' changed during secure open.');}
			$bytes=(int)($opened['size'] ?? -1);if($bytes<2 || $bytes>$maxBytes){throw new \LengthException($label.' exceeds its byte budget.');}
			$json=stream_get_contents($handle);$finished=fstat($handle);clearstatcache(true,$path);$after=@lstat($path);
			if(!is_string($json) || strlen($json)!==$bytes || !is_array($finished) || (int)($finished['size'] ?? -1)!==$bytes || !is_array($after) || !self::sameFile($opened,$finished) || !self::sameFile($opened,$after)){throw new \RuntimeException($label.' could not be read consistently.');}
		}finally{fclose($handle);}
		try{$decoded=json_decode($json,true,128,JSON_THROW_ON_ERROR);}catch(\JsonException $exception){throw new \InvalidArgumentException($label.' JSON is invalid.',0,$exception);}
		if(!is_array($decoded) || array_is_list($decoded)){throw new \InvalidArgumentException($label.' JSON must decode to an object.');}
		return $decoded;
	}
	private static function regular(array $stat): bool {return (((int)($stat['mode'] ?? 0)) & 0170000)===0100000;}
	private static function sameFile(array $left,array $right): bool {return (int)($left['dev'] ?? -1)===(int)($right['dev'] ?? -2) && (int)($left['ino'] ?? -1)===(int)($right['ino'] ?? -2);}

	private static function absolute(string $path): bool {return str_starts_with($path,'/') || preg_match('/^[A-Za-z]:[\\\\\/]/D',$path)===1;}
	private static function safeMessage(string $message): string {
		$message=preg_replace('/[\x00-\x1F\x7F]+/',' ',$message) ?? 'Compatibility input is invalid.';
		$message=preg_replace('/(?:[A-Za-z]:[\\\\\/]|\/)[^\s]+/','[PATH]',$message) ?? $message;
		return substr(trim($message),0,512) ?: 'Compatibility input is invalid.';
	}
}
