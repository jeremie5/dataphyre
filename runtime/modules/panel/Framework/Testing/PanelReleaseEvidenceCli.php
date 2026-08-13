<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Preview-safe CLI boundary for issuing and independently verifying evidence packs. */
final class PanelReleaseEvidenceCli {
	private const MAX_JSON_BYTES=4194304;
	private const MAX_KEY_BYTES=16384;

	/** @return array{exit_code:int,payload:array<string,mixed>} */
	public static function execute(array $arguments,?string $cwd=null):array {
		try{
			$cwd=self::directory($cwd??getcwd()?:'.');$options=self::parse($arguments);
			if(($options['help']??false)===true){return ['exit_code'=>0,'payload'=>self::help()];}
			$mode=$options['mode']??'';if(!in_array($mode,['issue','verify'],true)){throw new \InvalidArgumentException('Choose issue or verify.');}
			$root=self::directory(self::path($cwd,(string)($options['root']??'')));
			$key=self::key(self::path($cwd,(string)($options['key_file']??'')));
			if($mode==='issue'){return self::issue($root,$key,$options,$cwd);}
			return self::verify($root,$key,$options,$cwd);
		}catch(\Throwable $error){return ['exit_code'=>2,'payload'=>['type'=>'panel_release_evidence_cli','version'=>1,'ok'=>false,'mode'=>'error','message'=>$error->getMessage()]];}
	}

	/** @param array<string,mixed> $options @return array{exit_code:int,payload:array<string,mixed>} */
	private static function issue(string $root,string $key,array $options,string $cwd):array {
		$spec=self::jsonFile(self::path($cwd,(string)($options['spec']??'')),'release evidence issue spec');
		self::keys($spec,['artifacts','context','claims','key_id','issued_at','ttl','run_id','strict_tree'],['artifacts','context','claims','key_id'],'issue spec');
		$strict=$spec['strict_tree']??true;if(!is_bool($strict)){throw new \InvalidArgumentException('Issue spec strict_tree must be boolean.');}
		$issued=$spec['issued_at']??time();$ttl=$spec['ttl']??3600;$runId=$spec['run_id']??null;
		if(!is_int($issued)||!is_int($ttl)||($runId!==null&&!is_string($runId))){throw new \InvalidArgumentException('Issue spec clock, ttl, or run id is invalid.');}
		$bundle=PanelReleaseEvidenceBundle::issue(
			$root,
			is_array($spec['artifacts'])?$spec['artifacts']:throw new \InvalidArgumentException('Issue spec artifacts must be a list.'),
			is_array($spec['context'])?$spec['context']:throw new \InvalidArgumentException('Issue spec context must be an object.'),
			is_array($spec['claims'])?$spec['claims']:throw new \InvalidArgumentException('Issue spec claims must be a list.'),
			(string)$spec['key_id'],$key,$issued,$ttl,$runId,$strict
		);
		$serialized=$bundle->jsonSerialize();$output=$options['output']??null;
		if(is_string($output)&&$output!==''){
			$output=self::path($cwd,$output);
			if($strict&&self::inside($root,$output)){throw new \InvalidArgumentException('Strict evidence bundles must be written outside the attested artifact root.');}
			self::writeJson($output,$serialized);
			return ['exit_code'=>0,'payload'=>['type'=>'panel_release_evidence_cli','version'=>1,'ok'=>true,'mode'=>'issue','run_id'=>$bundle->runId(),'bundle_digest'=>$bundle->digest(),'artifacts'=>count($serialized['artifacts']),'claims'=>count($serialized['claims']),'output'=>$output]];
		}
		return ['exit_code'=>0,'payload'=>['type'=>'panel_release_evidence_cli','version'=>1,'ok'=>true,'mode'=>'issue','run_id'=>$bundle->runId(),'bundle_digest'=>$bundle->digest(),'artifacts'=>count($serialized['artifacts']),'claims'=>count($serialized['claims']),'bundle'=>$serialized]];
	}

	/** @param array<string,mixed> $options @return array{exit_code:int,payload:array<string,mixed>} */
	private static function verify(string $root,string $key,array $options,string $cwd):array {
		$envelope=self::jsonFile(self::path($cwd,(string)($options['bundle']??'')),'release evidence bundle');
		$expectations=self::jsonFile(self::path($cwd,(string)($options['spec']??'')),'release evidence expectations');
		$bundle=PanelReleaseEvidenceBundle::fromArray($envelope);$integrity=$envelope['integrity']??[];$keyId=is_array($integrity)?(string)($integrity['key_id']??''):'';
		$now=$options['now']??time();$skew=$options['clock_skew']??0;if(!is_int($now)||!is_int($skew)){throw new \InvalidArgumentException('Verification clock values must be integers.');}
		$result=$bundle->verify($root,[$keyId=>$key],$expectations,$now,$skew);
		return ['exit_code'=>$result->passed()?0:1,'payload'=>['type'=>'panel_release_evidence_cli','version'=>1,'ok'=>$result->passed(),'mode'=>'verify','run_id'=>$bundle->runId(),'verification'=>$result->jsonSerialize()]];
	}

	/** @return array<string,mixed> */
	private static function parse(array $arguments):array {
		$options=['mode'=>'','help'=>false];$arguments=array_values($arguments);if($arguments!==[]&&!str_starts_with((string)$arguments[0],'-')&&!in_array((string)$arguments[0],['issue','verify'],true)){array_shift($arguments);}
		for($index=0;$index<count($arguments);$index++){
			$argument=(string)$arguments[$index];
			if(in_array($argument,['issue','verify'],true)){if($options['mode']!==''){throw new \InvalidArgumentException('Release evidence mode may be supplied once.');}$options['mode']=$argument;continue;}
			if($argument==='--help'||$argument==='-h'){$options['help']=true;continue;}
			if(!str_starts_with($argument,'--')){throw new \InvalidArgumentException('Unknown release evidence argument: '.$argument.'.');}
			$inline=strpos($argument,'=');$name=substr($argument,2,$inline===false?null:$inline-2);$value=$inline===false?null:substr($argument,$inline+1);
			$map=['root'=>'root','spec'=>'spec','key-file'=>'key_file','bundle'=>'bundle','output'=>'output','now'=>'now','clock-skew'=>'clock_skew'];if(!isset($map[$name])){throw new \InvalidArgumentException('Unknown release evidence option: --'.$name.'.');}
			if($value===null){$index++;$value=(string)($arguments[$index]??'');}
			if($value===''){throw new \InvalidArgumentException('Release evidence option --'.$name.' requires a value.');}
			$key=$map[$name];if(array_key_exists($key,$options)){throw new \InvalidArgumentException('Release evidence option --'.$name.' may be supplied once.');}
			$options[$key]=in_array($key,['now','clock_skew'],true)?self::integer($value,$name):$value;
		}
		if(($options['help']??false)===true){return $options;}
		foreach(['root','spec','key_file'] as $required){if(!isset($options[$required])){throw new \InvalidArgumentException('Release evidence option --'.str_replace('_','-',$required).' is required.');}}
		if(($options['mode']??'')==='verify'&&!isset($options['bundle'])){throw new \InvalidArgumentException('Release evidence option --bundle is required for verification.');}
		if(($options['mode']??'')==='issue'&&isset($options['bundle'])){throw new \InvalidArgumentException('--bundle is only valid for verification.');}
		if(($options['mode']??'')==='verify'&&isset($options['output'])){throw new \InvalidArgumentException('--output is only valid for issuance.');}
		return $options;
	}

	/** @return array<string,mixed> */
	private static function help():array {return ['type'=>'panel_release_evidence_cli','version'=>1,'ok'=>true,'mode'=>'help','usage'=>['panel_release_evidence.php issue --root ARTIFACTS --spec issue.json --key-file key --output evidence.json','panel_release_evidence.php verify --root ARTIFACTS --spec expected.json --key-file key --bundle evidence.json']];}
	private static function integer(string $value,string $label):int {if(preg_match('/^-?\d+$/D',$value)!==1){throw new \InvalidArgumentException('Release evidence '.$label.' must be an integer.');}$integer=filter_var($value,FILTER_VALIDATE_INT);if(!is_int($integer)){throw new \InvalidArgumentException('Release evidence '.$label.' is outside the integer range.');}return $integer;}
	private static function path(string $cwd,string $path):string {if($path===''||strlen($path)>4096||str_contains($path,"\0")){throw new \InvalidArgumentException('Release evidence filesystem path is invalid.');}if($path[0]==='/'||preg_match('/^[A-Za-z]:[\\\/]/',$path)===1){return $path;}return $cwd.DIRECTORY_SEPARATOR.$path;}
	private static function directory(string $path):string {$real=realpath($path);if(!is_string($real)||!is_dir($real)||is_link($path)){throw new \InvalidArgumentException('Release evidence directory does not exist or is unsafe.');}return rtrim($real,DIRECTORY_SEPARATOR);}
	private static function key(string $path):string {$real=realpath($path);if(!is_string($real)||!is_file($real)||is_link($path)){throw new \InvalidArgumentException('Release evidence key file does not exist or is unsafe.');}$bytes=filesize($real);if(!is_int($bytes)||$bytes<32||$bytes>self::MAX_KEY_BYTES){throw new \LengthException('Release evidence key file is outside its byte budget.');}$key=file_get_contents($real);if(!is_string($key)){throw new \RuntimeException('Release evidence key file could not be read.');}return rtrim($key,"\r\n");}
	/** @return array<string,mixed> */ private static function jsonFile(string $path,string $label):array {$real=realpath($path);if(!is_string($real)||!is_file($real)||is_link($path)){throw new \InvalidArgumentException(ucfirst($label).' does not exist or is unsafe.');}$bytes=filesize($real);if(!is_int($bytes)||$bytes<2||$bytes>self::MAX_JSON_BYTES){throw new \LengthException(ucfirst($label).' is outside its byte budget.');}$json=file_get_contents($real);if(!is_string($json)){throw new \RuntimeException(ucfirst($label).' could not be read.');}$payload=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(!is_array($payload)||($payload!==[]&&array_is_list($payload))){throw new \InvalidArgumentException(ucfirst($label).' must be a JSON object.');}return $payload;}
	/** @param array<string,mixed> $payload @param list<string> $allowed @param list<string> $required */ private static function keys(array $payload,array $allowed,array $required,string $label):void {if(array_diff(array_keys($payload),$allowed)!==[]||array_diff($required,array_keys($payload))!==[]||($payload!==[]&&array_is_list($payload))){throw new \InvalidArgumentException('Release evidence '.$label.' contains unknown or missing fields.');}}
	/** @param array<string,mixed> $payload */ private static function writeJson(string $path,array $payload):void {$directory=dirname($path);if(!is_dir($directory)||is_link($directory)){throw new \InvalidArgumentException('Release evidence output directory does not exist or is unsafe.');}if(is_link($path)||is_dir($path)){throw new \InvalidArgumentException('Release evidence output target is unsafe.');}$json=json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";$temporary=$path.'.tmp-'.bin2hex(random_bytes(8));try{if(file_put_contents($temporary,$json,LOCK_EX)!==strlen($json)){throw new \RuntimeException('Release evidence output could not be written.');}@chmod($temporary,0644);if(!rename($temporary,$path)){throw new \RuntimeException('Release evidence output could not be committed.');}}finally{if(is_file($temporary)){@unlink($temporary);}}}
	private static function inside(string $root,string $path):bool {$parent=realpath(dirname($path));if(!is_string($parent)){return false;}$candidate=rtrim($parent,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($path);$prefix=rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;return DIRECTORY_SEPARATOR==='\\'?str_starts_with(strtolower($candidate),strtolower($prefix)):str_starts_with($candidate,$prefix);}
}
