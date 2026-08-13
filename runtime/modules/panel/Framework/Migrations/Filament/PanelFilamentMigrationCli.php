<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Preview-first, root-confined command adapter for Filament migration plans. */
final class PanelFilamentMigrationCli {
	/** @return array{exit_code:int,payload:array<string,mixed>} */
	public static function execute(array $argv,?string $cwd=null):array{
		try{
			$options=self::options($argv);$usage=[
				'ok'=>true,'mode'=>'help',
				'usage'=>'panel_filament_migrate.php [--root DIR] [--paths PATH,PATH] [--target-directory DIR] [--target-namespace auto|FQCN] [--policy error|skip|replace] [--write]',
				'exit_codes'=>['planned_or_written'=>0,'migration_blocked'=>1,'invalid_input'=>2],
				'safety'=>['preview_by_default'=>true,'explicit_write_flag'=>true,'source_not_executed'=>true,'root_confined'=>true,'transactional_write'=>true,'activation_not_implied'=>true],
			];
			if(($options['help']??false)===true)return['exit_code'=>0,'payload'=>$usage];
			$cwd=$cwd??getcwd();if(!is_string($cwd)||$cwd==='')throw new \InvalidArgumentException('Filament migration working directory is unavailable.');
			$root=self::root((string)($options['root']??$cwd),$cwd);$targetDirectory=(string)($options['target_directory']??'app/Panel/Resources');
			$targetNamespace=trim((string)($options['target_namespace']??'auto'));
			if($targetNamespace===''||strtolower($targetNamespace)==='auto')$targetNamespace=PanelScaffoldWriter::discoverNamespace($root,$targetDirectory)['namespace'];
			$paths=[];if(isset($options['paths'])){foreach(explode(',',(string)$options['paths'])as$path){$path=trim($path);if($path==='')throw new \InvalidArgumentException('Filament migration paths cannot contain empty entries.');$paths[]=$path;}}
			$inventory=PanelFilamentSourceAnalyzer::make($root)->analyze($paths);
			$plan=PanelFilamentMigrationPlan::make($inventory,['target_namespace'=>$targetNamespace,'target_directory'=>$targetDirectory]);
			$write=($options['write']??false)===true;$transaction=null;
			if($plan->readyToWrite()){$result=$plan->write($root,(string)($options['policy']??'error'),!$write);$transaction=self::transaction($result,$root);}
			elseif($write){return['exit_code'=>1,'payload'=>['ok'=>false,'mode'=>'blocked','plan'=>$plan->jsonSerialize(),'transaction'=>null]];}
			return['exit_code'=>$plan->readyToWrite()?0:1,'payload'=>[
				'ok'=>$plan->readyToWrite(),'mode'=>$write?'write':'preview','plan'=>$plan->jsonSerialize(),'transaction'=>$transaction,
			]];
		}catch(\Throwable $exception){return['exit_code'=>2,'payload'=>['ok'=>false,'mode'=>'invalid','message'=>self::safeMessage($exception->getMessage())]];}
	}

	/** @return array<string,string|bool> */
	private static function options(array $argv):array{
		array_shift($argv);$options=[];$allowed=['root','paths','target_directory','target_namespace','policy','write','help'];
		for($index=0;$index<count($argv);$index++){
			$argument=$argv[$index]??null;if(!is_string($argument)||!str_starts_with($argument,'--'))throw new \InvalidArgumentException('Unexpected positional Filament migration argument.');
			$key=substr($argument,2);$value=true;if(str_contains($key,'='))[$key,$value]=explode('=',$key,2);$key=str_replace('-','_',strtolower(trim($key)));
			if(!in_array($key,$allowed,true))throw new \InvalidArgumentException('Unknown Filament migration CLI option.');
			if(array_key_exists($key,$options))throw new \InvalidArgumentException('Filament migration CLI option was provided more than once.');
			if(in_array($key,['write','help'],true)){if($value!==true)throw new \InvalidArgumentException('Filament migration flag does not accept a value.');}
			elseif($value===true){$next=$argv[$index+1]??null;if(!is_string($next)||str_starts_with($next,'--'))throw new \InvalidArgumentException('Filament migration CLI option requires a value.');$value=$next;$index++;}
			if(is_string($value)&&($value===''||strlen($value)>8192||str_contains($value,"\0")))throw new \InvalidArgumentException('Filament migration CLI option value is invalid.');$options[$key]=$value;
		}
		return$options;
	}

	private static function root(string $input,string $cwd):string{
		if(!self::absolute($input))$input=rtrim($cwd,'/\\').DIRECTORY_SEPARATOR.$input;$real=realpath($input);
		if($real===false||!is_dir($real)||is_link($input))throw new \InvalidArgumentException('Filament migration root must be an existing non-symlink directory.');return rtrim($real,'/\\');
	}

	/** @return array<string,mixed> */
	private static function transaction(PanelScaffoldWriteResult $result,string $root):array{
		$entries=[];$prefix=rtrim(str_replace('\\','/',$root),'/').'/';
		foreach($result->entries()as$entry){$path=str_replace('\\','/',(string)($entry['path']??''));if(str_starts_with($path,$prefix))$path=substr($path,strlen($prefix));$entries[]=[
			'kind'=>$entry['kind']??null,'name'=>$entry['name']??null,'class'=>$entry['class']??null,'path'=>$path,
			'operation'=>$entry['operation']??null,'bytes'=>$entry['bytes']??null,'digest'=>$entry['digest']??null,
		];}
		return['status'=>$result->dryRun()?'planned':'applied','dry_run'=>$result->dryRun(),'changed'=>$result->changed(),'counts'=>['artifacts'=>count($entries),'created'=>count($result->created()),'replaced'=>count($result->replaced()),'skipped'=>count($result->skipped())],'entries'=>$entries,'root_serialized'=>false];
	}

	private static function absolute(string $path):bool{return str_starts_with($path,'/')||preg_match('/^[A-Za-z]:[\\\\\/]/D',$path)===1;}
	private static function safeMessage(string $message):string{$message=preg_replace('/[\x00-\x1F\x7F]+/',' ',$message)??'Filament migration input is invalid.';$message=preg_replace('/(?:[A-Za-z]:[\\\\\/]|\/)[^\s]+/','[PATH]',$message)??$message;return substr(trim($message),0,512)?:'Filament migration input is invalid.';}
}
