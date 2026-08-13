<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Stable rule identifiers used by architecture violations and exemptions. */
final class TestArchitectureRule {
	public const RUNTIME_EVALUATION='runtime-evaluation';
	public const EXECUTABLE_JSON='executable-json';
	public const UNMANAGED_TEMPORARY_FILE='unmanaged-temporary-file';
	public const UNMANAGED_SYSTEM_TEMPORARY_DIRECTORY='unmanaged-system-temporary-directory';
	public const RAW_GLOBAL_VARIABLE='raw-global-variable';
	public const GLOBAL_DECLARATION='global-declaration';
	public const RAW_SUPERGLOBAL='raw-superglobal';
	public const DIRECT_REFLECTION_ACCESS='direct-reflection-access';
	public const RAW_OUTPUT_BUFFER='raw-output-buffer';
	public const RAW_PROCESS_CONTROL='raw-process-control';
	public const DESTRUCTIVE_ROOTPATH_OPERATION='destructive-rootpath-operation';
	public const INVALID_EXEMPTION='invalid-exemption';

	/** @return list<string> */
	public static function all(): array {
		return [
			self::RUNTIME_EVALUATION,
			self::EXECUTABLE_JSON,
			self::UNMANAGED_TEMPORARY_FILE,
			self::UNMANAGED_SYSTEM_TEMPORARY_DIRECTORY,
			self::RAW_GLOBAL_VARIABLE,
			self::GLOBAL_DECLARATION,
			self::RAW_SUPERGLOBAL,
			self::DIRECT_REFLECTION_ACCESS,
			self::RAW_OUTPUT_BUFFER,
			self::RAW_PROCESS_CONTROL,
			self::DESTRUCTIVE_ROOTPATH_OPERATION,
			self::INVALID_EXEMPTION,
		];
	}

	/** @return list<string> */
	public static function exemptable(): array {
		return [
			self::UNMANAGED_TEMPORARY_FILE,
			self::UNMANAGED_SYSTEM_TEMPORARY_DIRECTORY,
			self::RAW_GLOBAL_VARIABLE,
			self::RAW_SUPERGLOBAL,
			self::RAW_OUTPUT_BUFFER,
			self::RAW_PROCESS_CONTROL,
		];
	}
}

/** A typed, deterministic architecture diagnostic. */
final class TestArchitectureViolation implements \JsonSerializable {
	public function __construct(
		private string $rule,
		private string $file,
		private int $line,
		private string $detail,
	) {}

	public function rule(): string { return $this->rule; }
	public function file(): string { return $this->file; }
	public function line(): int { return $this->line; }
	public function detail(): string { return $this->detail; }

	/** @return array{rule:string,file:string,line:int,detail:string} */
	public function toArray(): array {
		return ['rule'=>$this->rule,'file'=>$this->file,'line'=>$this->line,'detail'=>$this->detail];
	}

	/** @return array{rule:string,file:string,line:int,detail:string} */
	public function jsonSerialize(): array { return $this->toArray(); }

	public function __toString(): string {
		return '['.$this->rule.'] '.$this->file.':'.$this->line.' '.$this->detail;
	}
}

/**
 * Compact repository index shared by every rule in one worker.
 *
 * Each relevant source is read and parsed once. Only small rule facts survive
 * the per-file scan, so a whole-framework audit stays below normal worker
 * memory limits even when the test suite contains thousands of cases.
 */
final class TestArchitectureIndex {
	private const MARKER='dataphyre-test-architecture:';
	private const EXEMPTION_PATTERN='/dataphyre-test-architecture:\s*exempt\[([a-z][a-z0-9-]*)\]\s+reason="([^"\r\n]+)"\s*(?:\*\/)?\s*$/';
	private const UNIT_PATTERN='~(?<marker>dataphyre-test-architecture:)|(?<temporary>(?i:\b(?:tempnam|tmpfile)\s*\())|(?<system>(?i:\bsys_get_temp_dir\s*\())|(?<globals>\$GLOBALS\b)|(?<global>(?i:\bglobal)\s+(?<global_name>\$[A-Za-z_][A-Za-z0-9_]*))|(?<superglobal>\$_(?:GET|POST|COOKIE|FILES|SERVER|SESSION|ENV|REQUEST)\b)|(?<reflection>(?i:(?:\bnew\s+(?<reflection_class>[\\\\]?Reflection(?:Class|Method|Property))\b|(?:->|\?->)\s*(?<reflection_method>setAccessible|invokeArgs)\s*\()))|(?<buffer>(?i:\b(?<buffer_name>ob_start)\s*\())|(?<process>(?i:\b(?<process_name>proc_open)\s*\())~';

	/** @var array<string,self> */
	private static array $cache=[];
	/** @var list<array{rule:string,file:string,line:int,detail:string}> */
	private array $facts=[];
	/** @var list<string> */
	private array $modules=[];
	private int $sourceReads=0;
	private int $phpFiles=0;
	private int $jsonFiles=0;

	private function __construct(private string $modulesRoot) {
		$modules=[];
		foreach(new \FilesystemIterator($modulesRoot, \FilesystemIterator::SKIP_DOTS) as $entry){
			if($entry->isDir()){
				$modules[$entry->getFilename()]=true;
			}
		}
		$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($modulesRoot, \FilesystemIterator::SKIP_DOTS));
		foreach($iterator as $file){
			$path=$file->getPathname();
			$relative=$this->relative($path);
			$type=strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
			$unitTest=str_contains('/'.$relative, '/unit_tests/');
			if($type!=='php' && !($type==='json' && $unitTest)){
				continue;
			}
			$source=file_get_contents($path);
			if(!is_string($source)){
				throw new \RuntimeException('Unable to read architecture source: '.$path);
			}
			$this->sourceReads++;
			if($type==='php'){
				if($this->scanPhp($relative,$unitTest,$source)){
					$this->phpFiles++;
				}
			}
			else
			{
				$this->jsonFiles++;
				$this->scanJson($relative,$source);
			}
		}
		usort($this->facts, static fn(array $left,array $right): int=>[$left['file'],$left['line'],$left['rule'],$left['detail']] <=> [$right['file'],$right['line'],$right['rule'],$right['detail']]);
		$this->modules=array_keys($modules);
		sort($this->modules);
	}

	public static function forModulesRoot(string $modulesRoot): self {
		$resolved=realpath($modulesRoot);
		if(!is_string($resolved) || !is_dir($resolved)){
			throw new \InvalidArgumentException('Architecture modules root is unavailable: '.$modulesRoot);
		}
		$key=strtolower(str_replace('\\', '/', $resolved));
		return self::$cache[$key] ??= new self($resolved);
	}

	public static function forget(?string $modulesRoot=null): void {
		if($modulesRoot===null){
			self::$cache=[];
			return;
		}
		$resolved=realpath($modulesRoot);
		$key=strtolower(str_replace('\\', '/', is_string($resolved) ? $resolved : $modulesRoot));
		unset(self::$cache[$key]);
	}

	/** @return list<array{rule:string,file:string,line:int,detail:string}> */
	public function facts(): array { return $this->facts; }

	/** @return list<string> */
	public function modules(): array { return $this->modules; }

	/** @return array{files:int,source_reads:int,php_tokenizations:int,json_decodes:int,index_identity:int} */
	public function statistics(): array {
		return [
			'files'=>$this->sourceReads,
			'source_reads'=>$this->sourceReads,
			'php_tokenizations'=>$this->phpFiles,
			'json_decodes'=>$this->jsonFiles,
			'index_identity'=>spl_object_id($this),
		];
	}

	private function scanPhp(string $file, bool $unitTest, string $source): bool {
		$tokenized=false;
		$tokens=null;
		$evaluationPattern='/\b'.'ev'.'al\s*\(/i';
		if(preg_match($evaluationPattern,$source)===1){
			$tokenized=true;
			$tokens=token_get_all($source);
			foreach($tokens as $token){
				if(is_array($token) && $token[0]===T_EVAL){
					$this->add(TestArchitectureRule::RUNTIME_EVALUATION,$file,$token[2],'runtime eval');
				}
			}
		}
		if(!$unitTest){
			return $tokenized;
		}
		if(str_contains($source, 'ROOTPATH') && preg_match('/\b(?:force_rmdir|rmdir|unlink)\s*\(/i', $source)===1){
			if($tokens===null){
				$tokens=token_get_all($source);
				$tokenized=true;
			}
			$this->scanDestructiveRootpathOperations($file, $tokens);
		}
		preg_match_all(self::UNIT_PATTERN,$source,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE|PREG_UNMATCHED_AS_NULL);
		if($matches===[]){
			return $tokenized;
		}
		$lines=preg_split('/\R/',$source) ?: [$source];
		$invalidExemptionLines=[];
		$needsLexicalValidation=false;
		$globalNamesByLine=[];
		$reflectionMethodsByLine=[];
		$bufferFunctionsByLine=[];
		$processFunctionsByLine=[];
		foreach($matches as $match){
			$line=$this->lineNumber($source,(int)$match[0][1]);
			$lineSource=(string)($lines[$line-1] ?? '');
			if($match['marker'][0]!==null){
				if(!isset($invalidExemptionLines[$line]) && $this->exemptionOnLine($lineSource)===null){
					$invalidExemptionLines[$line]=true;
					$this->add(TestArchitectureRule::INVALID_EXEMPTION,$file,$line,'Exemptions require an exemptable rule and a reason of at least 20 characters.');
				}
				continue;
			}
			if($match['temporary'][0]!==null && !$this->isExempt($lineSource,TestArchitectureRule::UNMANAGED_TEMPORARY_FILE)){
				$needsLexicalValidation=true;
			}
			if($match['system'][0]!==null && !$this->isExempt($lineSource,TestArchitectureRule::UNMANAGED_SYSTEM_TEMPORARY_DIRECTORY)){
				$needsLexicalValidation=true;
			}
			if($match['globals'][0]!==null && !$this->isExempt($lineSource,TestArchitectureRule::RAW_GLOBAL_VARIABLE)){
				$needsLexicalValidation=true;
			}
			if($match['global'][0]!==null){
				$needsLexicalValidation=true;
				$globalNamesByLine[$line]=(string)$match['global_name'][0];
			}
			if($match['superglobal'][0]!==null && !$this->isExempt($lineSource,TestArchitectureRule::RAW_SUPERGLOBAL)){
				$needsLexicalValidation=true;
			}
			if($match['reflection'][0]!==null){
				$needsLexicalValidation=true;
				if($match['reflection_method'][0]!==null){
					$reflectionMethodsByLine[$line]=true;
				}
			}
			if($match['buffer'][0]!==null && !$this->isExempt($lineSource,TestArchitectureRule::RAW_OUTPUT_BUFFER)){
				$needsLexicalValidation=true;
				$bufferFunctionsByLine[$line][strtolower((string)$match['buffer_name'][0])]=true;
			}
			if($match['process'][0]!==null && !$this->isExempt($lineSource,TestArchitectureRule::RAW_PROCESS_CONTROL)){
				$needsLexicalValidation=true;
				$processFunctionsByLine[$line][strtolower((string)$match['process_name'][0])]=true;
			}
		}
		if($needsLexicalValidation){
			if($tokens===null){
				$tokens=token_get_all($source);
				$tokenized=true;
			}
			$this->scanUnitTokens($file,$lines,$tokens,$globalNamesByLine,$reflectionMethodsByLine,$bufferFunctionsByLine,$processFunctionsByLine);
		}
		return $tokenized;
	}

	/**
	 * Finds destructive calls whose argument is ROOTPATH itself or a self/static
	 * helper method that directly returns ROOTPATH. This intentionally small
	 * dataflow pass catches repository-deleting test setup before execution.
	 *
	 * @param array<int,array|string> $tokens
	 */
	private function scanDestructiveRootpathOperations(string $file, array $tokens): void {
		$rootpath_methods=[];
		$count=count($tokens);
		for($index=0; $index<$count; $index++){
			$token=$tokens[$index];
			if(!is_array($token) || $token[0]!==T_FUNCTION){
				continue;
			}
			$name_index=$this->nextSignificantToken($tokens, $index+1);
			if($name_index===null || !is_array($tokens[$name_index]) || $tokens[$name_index][0]!==T_STRING){
				continue;
			}
			$name=strtolower($tokens[$name_index][1]);
			$body_index=$name_index+1;
			while($body_index<$count && $this->tokenText($tokens[$body_index])!=='{'){
				$body_index++;
			}
			if($body_index>=$count){
				continue;
			}
			$depth=1;
			for($cursor=$body_index+1; $cursor<$count && $depth>0; $cursor++){
				$text=$this->tokenText($tokens[$cursor]);
				if($text==='{'){$depth++;continue;}
				if($text==='}'){$depth--;continue;}
				if($depth!==1 || !is_array($tokens[$cursor]) || $tokens[$cursor][0]!==T_RETURN){
					continue;
				}
				$return_index=$this->nextSignificantToken($tokens, $cursor+1);
				if($return_index!==null && is_array($tokens[$return_index]) && strtoupper($tokens[$return_index][1])==='ROOTPATH'){
					$rootpath_methods[$name]=true;
					break;
				}
			}
		}

		foreach($tokens as $index=>$token){
			if(!is_array($token) || $token[0]!==T_STRING || !in_array(strtolower($token[1]), ['force_rmdir','rmdir','unlink'], true)){
				continue;
			}
			$open=$this->nextSignificantToken($tokens, $index+1);
			if($open===null || $this->tokenText($tokens[$open])!=='('){
				continue;
			}
			$argument=[];
			$depth=1;
			for($cursor=$open+1; $cursor<$count && $depth>0; $cursor++){
				$text=$this->tokenText($tokens[$cursor]);
				if($text==='('){$depth++;}
				elseif($text===')'){$depth--;if($depth===0){break;}}
				elseif($text===',' && $depth===1){break;}
				if($depth>0 && !$this->ignorableToken($tokens[$cursor])){
					$argument[]=strtolower($text);
				}
			}
			$direct=in_array('rootpath', $argument, true);
			$indirect=false;
			for($part=0; $part+2<count($argument); $part++){
				if(in_array($argument[$part], ['self','static'], true) && $argument[$part+1]==='::' && isset($rootpath_methods[$argument[$part+2]])){
					$indirect=true;
					break;
				}
			}
			if($direct || $indirect){
				$this->add(
					TestArchitectureRule::DESTRUCTIVE_ROOTPATH_OPERATION,
					$file,
					$token[2],
					strtolower($token[1]).'() targets '.($direct ? 'ROOTPATH directly' : 'a helper that returns ROOTPATH')
				);
			}
		}
	}

	/** @param array<int,array|string> $tokens */
	private function nextSignificantToken(array $tokens, int $index): ?int {
		for($count=count($tokens); $index<$count; $index++){
			if(!$this->ignorableToken($tokens[$index])){
				return $index;
			}
		}
		return null;
	}

	private function ignorableToken(array|string $token): bool {
		return is_array($token) && in_array($token[0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true);
	}

	private function tokenText(array|string $token): string {
		return is_array($token) ? $token[1] : $token;
	}

	/**
	 * @param list<string> $lines
	 * @param array<int,array|string> $tokens
	 * @param array<int,string> $globalNamesByLine
	 * @param array<int,true> $reflectionMethodsByLine
	 * @param array<int,array<string,true>> $bufferFunctionsByLine
	 * @param array<int,array<string,true>> $processFunctionsByLine
	 */
	private function scanUnitTokens(
		string $file,
		array $lines,
		array $tokens,
		array $globalNamesByLine,
		array $reflectionMethodsByLine,
		array $bufferFunctionsByLine,
		array $processFunctionsByLine
	): void {
		foreach($tokens as $index=>$token){
			if(!is_array($token)){
				continue;
			}
			$line=$token[2];
			$lineSource=(string)($lines[$line-1] ?? '');
			if($token[0]===T_STRING && in_array(strtolower($token[1]),['tempnam','tmpfile'],true) && !$this->isExempt($lineSource,TestArchitectureRule::UNMANAGED_TEMPORARY_FILE)){
				$this->add(TestArchitectureRule::UNMANAGED_TEMPORARY_FILE,$file,$line,strtolower($token[1]).'()');
			}
			if($token[0]===T_STRING && strtolower($token[1])==='sys_get_temp_dir' && !$this->isExempt($lineSource,TestArchitectureRule::UNMANAGED_SYSTEM_TEMPORARY_DIRECTORY)){
				$this->add(TestArchitectureRule::UNMANAGED_SYSTEM_TEMPORARY_DIRECTORY,$file,$line,'sys_get_temp_dir()');
			}
			if($token[0]===T_VARIABLE && $token[1]==='$GLOBALS' && !$this->isExempt($lineSource,TestArchitectureRule::RAW_GLOBAL_VARIABLE)){
				$this->add(TestArchitectureRule::RAW_GLOBAL_VARIABLE,$file,$line,'$GLOBALS');
			}
			if($token[0]===T_VARIABLE && in_array($token[1],['$_GET','$_POST','$_COOKIE','$_FILES','$_SERVER','$_SESSION','$_ENV','$_REQUEST'],true) && !$this->isExempt($lineSource,TestArchitectureRule::RAW_SUPERGLOBAL)){
				$this->add(TestArchitectureRule::RAW_SUPERGLOBAL,$file,$line,$token[1]);
			}
			if($token[0]===T_GLOBAL && isset($globalNamesByLine[$line])){
				$this->add(TestArchitectureRule::GLOBAL_DECLARATION,$file,$line,'global '.$globalNamesByLine[$line]);
			}
			if($token[0]===T_NEW){
				$name=$this->instantiatedClassName($tokens,$index+1);
				if(in_array(strtolower(ltrim($name,'\\')),['reflectionclass','reflectionmethod','reflectionproperty'],true)){
					$this->add(TestArchitectureRule::DIRECT_REFLECTION_ACCESS,$file,$line,'new '.$name);
				}
			}
			if($token[0]===T_STRING && isset($reflectionMethodsByLine[$line]) && in_array(strtolower($token[1]),['setaccessible','invokeargs'],true)){
				$this->add(TestArchitectureRule::DIRECT_REFLECTION_ACCESS,$file,$line,$token[1]);
			}
			$name=strtolower((string)$token[1]);
			if($token[0]===T_STRING && isset($bufferFunctionsByLine[$line][$name])){
				$this->add(TestArchitectureRule::RAW_OUTPUT_BUFFER,$file,$line,$name.'()');
			}
			if($token[0]===T_STRING && isset($processFunctionsByLine[$line][$name])){
				$this->add(TestArchitectureRule::RAW_PROCESS_CONTROL,$file,$line,$name.'()');
			}
		}
	}

	private function lineNumber(string $source,int $offset): int {
		return substr_count($source,"\n",0,$offset)+1;
	}

	private function scanJson(string $file, string $source): void {
		$decoded=json_decode($source,true);
		$fields=[];
		if(json_last_error()===JSON_ERROR_NONE && is_array($decoded)){
			$this->findExecutableJsonFields($decoded,'$',$fields);
		}
		else
		{
			preg_match_all('/[\"\'](custom_script|file_dynamic)[\"\']\s*:/i',$source,$matches,PREG_OFFSET_CAPTURE);
			foreach($matches[1] ?? [] as $match){
				$fields[]=['key'=>(string)$match[0],'path'=>'$.(undecodable).'.(string)$match[0],'offset'=>(int)$match[1]];
			}
		}
		$offsets=[];
		preg_match_all('/"(custom_script|file_dynamic)"\s*:/i',$source,$matches,PREG_OFFSET_CAPTURE);
		foreach($matches[1] ?? [] as $match){
			$offsets[strtolower((string)$match[0])][]= (int)$match[1];
		}
		foreach($fields as $field){
			$key=strtolower($field['key']);
			$offset=$field['offset'] ?? array_shift($offsets[$key]);
			$line=substr_count(substr($source,0,is_int($offset) ? $offset : 0),"\n")+1;
			$this->add(TestArchitectureRule::EXECUTABLE_JSON,$file,$line,$field['path']);
		}
	}

	/** @param array<mixed> $value @param list<array{key:string,path:string,offset?:int}> $fields */
	private function findExecutableJsonFields(array $value, string $path, array &$fields): void {
		foreach($value as $key=>$child){
			$childPath=$path.'.'.$key;
			if($key==='custom_script' || $key==='file_dynamic'){
				$fields[]=['key'=>$key,'path'=>$childPath];
			}
			if(is_array($child)){
				$this->findExecutableJsonFields($child,$childPath,$fields);
			}
		}
	}

	/** @return array{rule:string,reason:string}|null */
	private function exemptionOnLine(string $line): ?array {
		if(preg_match(self::EXEMPTION_PATTERN,$line,$match)!==1){
			return null;
		}
		$rule=(string)$match[1];
		$reason=trim((string)$match[2]);
		if(!in_array($rule,TestArchitectureRule::exemptable(),true) || strlen($reason)<20){
			return null;
		}
		return ['rule'=>$rule,'reason'=>$reason];
	}

	private function isExempt(string $line, string $rule): bool {
		$exemption=$this->exemptionOnLine($line);
		return $exemption!==null && $exemption['rule']===$rule;
	}

	/** @param array<int,array|string> $tokens */
	private function instantiatedClassName(array $tokens,int $index): string {
		$name='';
		for($count=count($tokens);$index<$count;$index++){
			$token=$tokens[$index];
			if(is_array($token) && in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)){
				continue;
			}
			if(is_array($token) && in_array($token[0],[T_STRING,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED,T_NS_SEPARATOR],true)){
				$name.=$token[1];
				continue;
			}
			break;
		}
		return $name;
	}

	private function add(string $rule, string $file, int $line, string $detail): void {
		$this->facts[]=['rule'=>$rule,'file'=>$file,'line'=>$line,'detail'=>$detail];
	}

	private function relative(string $path): string {
		return ltrim(str_replace('\\','/',substr($path,strlen($this->modulesRoot))),'/');
	}
}

/** Cached, all-violations facade used by the repository sentinel and tools. */
final class TestArchitectureAudit {
	/** @var list<TestArchitectureViolation>|null */
	private ?array $violations=null;

	private function __construct(private TestArchitectureIndex $index) {}

	public static function forModulesRoot(string $modulesRoot): self {
		return new self(TestArchitectureIndex::forModulesRoot($modulesRoot));
	}

	/** @return list<TestArchitectureViolation> */
	public function violations(): array {
		if($this->violations!==null){
			return $this->violations;
		}
		return $this->violations=array_map(
			static fn(array $fact): TestArchitectureViolation=>new TestArchitectureViolation($fact['rule'],$fact['file'],$fact['line'],$fact['detail']),
			$this->index->facts()
		);
	}

	/** @return list<TestArchitectureViolation> */
	public function violationsFor(string $rule): array {
		if(!in_array($rule,TestArchitectureRule::all(),true)){
			throw new \InvalidArgumentException('Unknown architecture rule: '.$rule);
		}
		return array_values(array_filter($this->violations(),static fn(TestArchitectureViolation $violation): bool=>$violation->rule()===$rule));
	}

	/** @return list<array{rule:string,file:string,line:int,detail:string}> */
	public function violationData(): array {
		return array_map(static fn(TestArchitectureViolation $violation): array=>$violation->toArray(),$this->violations());
	}

	public function report(): string {
		$violations=$this->violations();
		if($violations===[]){
			return 'No test-architecture violations.';
		}
		return "Test-architecture violations (".count($violations)."):\n".implode("\n",array_map(static fn(TestArchitectureViolation $violation): string=>(string)$violation,$violations));
	}

	/** @return array{files:int,source_reads:int,php_tokenizations:int,json_decodes:int,index_identity:int} */
	public function statistics(): array { return $this->index->statistics(); }

	/** @return list<string> */
	public function moduleNames(): array { return $this->index->modules(); }

	/**
	 * Resolves the modules declared by changed-run sentinel metadata.
	 *
	 * `framework('*')` is distribution-aware: it expands to the modules present
	 * in the supplied framework package, so the canonical architecture guard can
	 * protect both full and intentionally reduced Dataphyre distributions.
	 *
	 * @param list<string> $available_modules
	 * @return list<string>
	 */
	public static function changedRunSentinelModules(string $source, array $available_modules=[]): array {
		if(preg_match('/@dataphyre-changed-run-sentinel\s+framework\(\s*[\'\"]\*[\'\"]\s*\);/s',$source)===1){
			$result=[];
			foreach($available_modules as $module){
				$module=trim((string)$module);
				if(preg_match('/^[a-z][a-z0-9_-]*$/',$module)===1){
					$result[$module]=true;
				}
			}
			$result=array_keys($result);
			sort($result);
			return $result;
		}
		if(preg_match('/@dataphyre-changed-run-sentinel\s+framework\(\[([^\]]*)\]\);/s',$source,$match)!==1){
			return [];
		}
		preg_match_all('/[\'\"]([a-z][a-z0-9_-]*)[\'\"]/',(string)$match[1],$modules);
		$result=array_values(array_unique($modules[1] ?? []));
		sort($result);
		return $result;
	}
}
