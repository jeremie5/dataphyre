<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Mcp\Contracts;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Builds a route-free contract index from PHP tokens and declarative JSON.
 * Source is never required, evaluated, reflected, or bootstrapped.
 */
final class SourceContractIndex implements ContractSource {
	private ?array $cachedSnapshot=null;
	private string $root;
	/** @var list<string> */
	private array $modules;

	public function __construct(string $dataphyreRoot,array $modules=[],private int $maxFiles=30000) {
		$resolved=realpath($dataphyreRoot);
		if(!is_string($resolved) || !is_dir($resolved.'/runtime/modules')){
			throw new \InvalidArgumentException('Contract source root must contain runtime/modules.');
		}
		$this->root=str_replace('\\','/',$resolved);
		$this->modules=array_values(array_unique(array_filter(array_map(static function(mixed $module): string {
			$module=trim((string)$module);
			return preg_match('/^[A-Za-z0-9_.-]+$/',$module)===1?$module:'';
		},$modules))));
		sort($this->modules,SORT_STRING);
		$this->maxFiles=max(1,min($this->maxFiles,100000));
	}

	public function snapshot(): array {
		if($this->cachedSnapshot!==null){return $this->cachedSnapshot;}
		$contracts=[];
		$declarations=[];
		$testFiles=[];
		$diagnostics=['scope_modules'=>$this->modules,'files_scanned'=>0,'php_files'=>0,'tokenized_php_files'=>0,'skipped_php_files'=>0,'json_manifests'=>0,'missing_modules'=>[],'unreadable_files'=>[],'parse_failures'=>[],'truncated'=>false];
		foreach($this->sourceFiles($diagnostics) as $path){
			$diagnostics['files_scanned']++;
			$relative=$this->relative($path);
			$module=$this->moduleFromPath($relative);
			if(str_ends_with(strtolower($relative),'.php')){
				$diagnostics['php_files']++;
				$text=@file_get_contents($path);
				if(!is_string($text)){$diagnostics['unreadable_files'][]=$relative;continue;}
				if(!$this->canDeclareContract($text,$relative)){$diagnostics['skipped_php_files']++;continue;}
				$diagnostics['tokenized_php_files']++;
				$parsed=$this->parsePhp($text,$relative,$module);
				array_push($contracts,...$parsed['contracts']);
				array_push($declarations,...$parsed['declarations']);
				if($parsed['test_file']!==null){$testFiles[]=$parsed['test_file'];}
				continue;
			}
			if($this->isLegacyManifest($relative)){
				$diagnostics['json_manifests']++;
				$parsed=$this->parseLegacyManifest($path,$relative,$module);
				$contracts[]=$parsed['contract'];
				$testFiles[]=$parsed['test_file'];
			}
		}
		usort($contracts,self::recordOrder(...));
		usort($declarations,self::recordOrder(...));
		usort($testFiles,static fn(array $left,array $right): int=>strcmp((string)$left['path'],(string)$right['path']));
		$diagnostics['contract_records']=count($contracts);
		$diagnostics['declarations']=count($declarations);
		$diagnostics['test_files']=count($testFiles);
		return $this->cachedSnapshot=[
			'contracts'=>$contracts,
			'declarations'=>$declarations,
			'test_files'=>$testFiles,
			'diagnostics'=>$diagnostics,
		];
	}

	/** @return list<string> */
	private function sourceFiles(array &$diagnostics): array {
		$files=[];
		$modulesRoot=$this->root.'/runtime/modules';
		$roots=$this->modules===[]?[$modulesRoot]:array_map(static fn(string $module): string=>$modulesRoot.'/'.$module,$this->modules);
		foreach($roots as $root){
			if(!is_dir($root)){$diagnostics['missing_modules'][]=basename($root);continue;}
			$iterator=new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			/** @var SplFileInfo $file */
			foreach($iterator as $file){
				if(!$file->isFile()){continue;}
				$path=str_replace('\\','/',$file->getPathname());
				$extension=strtolower($file->getExtension());
				if($extension!=='php' && !($extension==='json' && $this->isLegacyManifest($this->relative($path)))){continue;}
				$files[]=$path;
				if(count($files)>=$this->maxFiles){$diagnostics['truncated']=true;break 2;}
			}
		}
		sort($files,SORT_STRING);
		return $files;
	}

	private function canDeclareContract(string $source,string $path): bool {
		if($this->isCodeTest($path) || str_contains('/'.$path,'/Contracts/')){return true;}
		return preg_match('/\binterface\s+[A-Za-z_]|\babstract\s+class\s+[A-Za-z_]|\b(?:implements|extends)\s+[A-Za-z_\\\\]|\b(?:class|trait|enum)\s+[A-Za-z_][A-Za-z0-9_]*Contract\b|[\'\"]type[\'\"]\s*=>/',$source)===1;
	}

	/** @return array{contracts:list<array<string,mixed>>,declarations:list<array<string,mixed>>,test_file:?array<string,mixed>} */
	private function parsePhp(string $source,string $path,?string $module): array {
		$tokens=token_get_all($source);
		[$namespace,$aliases]=$this->namespaceAndAliases($tokens);
		$declarations=$this->declarations($tokens,$path,$module,$namespace,$aliases);
		$contracts=[];
		foreach($declarations as $declaration){
			if(($declaration['contract_roles'] ?? [])===[]){continue;}
			$contracts[]=[
				'id'=>'php:'.(string)$declaration['fqcn'],
				'kind'=>'php_type_contract',
				'name'=>(string)$declaration['fqcn'],
				'module'=>$module,
				'path'=>$path,
				'line'=>$declaration['line'],
				'symbol_kind'=>$declaration['kind'],
				'roles'=>$declaration['contract_roles'],
				'extends'=>$declaration['extends'],
				'implements'=>$declaration['implements'],
				'method_count'=>count($declaration['methods']),
				'methods'=>$declaration['methods'],
				'description'=>$declaration['description'],
			];
		}
		if($this->isProductionPhp($path)){
			array_push($contracts,...$this->serializedContracts($tokens,$path,$module,$declarations));
		}
		$testFile=null;
		if($this->isCodeTest($path)){
			$semantic=$this->semanticContracts($tokens,$path,$module);
			array_push($contracts,...$semantic['contracts']);
			$testFile=$semantic['test_file'];
		}
		return ['contracts'=>$contracts,'declarations'=>$declarations,'test_file'=>$testFile];
	}

	/** @return array{0:string,1:array<string,string>} */
	private function namespaceAndAliases(array $tokens): array {
		$namespace='';
		$aliases=[];
		$classSeen=false;
		for($index=0,$count=count($tokens);$index<$count;$index++){
			$token=$tokens[$index];
			if(!is_array($token)){continue;}
			if($token[0]===T_NAMESPACE){$namespace=$this->qualifiedNameAfter($tokens,$index+1);continue;}
			if(in_array($token[0],[T_CLASS,T_INTERFACE,T_TRAIT,T_ENUM],true)){$classSeen=true;continue;}
			if($token[0]!==T_USE || $classSeen){continue;}
			$statement='';
			for($cursor=$index+1;$cursor<$count;$cursor++){
				$part=$tokens[$cursor];
				if($part===';'){$index=$cursor;break;}
				$statement.=is_array($part)?$part[1]:$part;
			}
			foreach(explode(',',$statement) as $import){
				$import=trim($import);
				if($import==='' || str_contains($import,'{')){continue;}
				if(preg_match('/^(.+?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i',$import,$match)===1){
					$target=trim($match[1],' \\');$alias=$match[2];
				}else{$target=trim($import,' \\');$alias=substr($target,(int)strrpos('\\'.$target,'\\'));}
				if($target!=='' && $alias!==''){$aliases[$alias]=$target;}
			}
		}
		return [$namespace,$aliases];
	}

	/** @return list<array<string,mixed>> */
	private function declarations(array $tokens,string $path,?string $module,string $namespace,array $aliases): array {
		$declarations=[];
		$classOpen=[];
		$classStack=[];
		$braceDepth=0;
		for($index=0,$count=count($tokens);$index<$count;$index++){
			$token=$tokens[$index];
			if($token==='{'){
				$braceDepth++;
				foreach($classOpen[$index] ?? [] as $declarationIndex){$classStack[]=['index'=>$declarationIndex,'depth'=>$braceDepth];}
				continue;
			}
			if($token==='}'){
				while($classStack!==[] && $classStack[count($classStack)-1]['depth']===$braceDepth){array_pop($classStack);}
				$braceDepth=max(0,$braceDepth-1);
				continue;
			}
			if(!is_array($token)){continue;}
			if(in_array($token[0],[T_CLASS,T_INTERFACE,T_TRAIT,T_ENUM],true)){
				$previous=$this->previousMeaningful($tokens,$index);
				if($previous!==null && is_array($tokens[$previous]) && in_array($tokens[$previous][0],[T_NEW,T_DOUBLE_COLON],true)){continue;}
				$nameIndex=$this->nextTokenId($tokens,$index+1,T_STRING);
				if($nameIndex===null){continue;}
				$name=(string)$tokens[$nameIndex][1];
				$header=$this->classHeader($tokens,$nameIndex+1,$namespace,$aliases);
				if($header['open_index']===null){continue;}
				$kind=strtolower(substr(token_name($token[0]),2));
				$abstract=$kind==='interface' || $this->hasModifier($tokens,$index,T_ABSTRACT);
				$fqcn=$namespace!==''?$namespace.'\\'.$name:$name;
				$roles=[];
				if($kind==='interface'){$roles[]='interface';}
				if($abstract && $kind==='class'){$roles[]='abstract_class';}
				if(str_contains('/'.$path,'/Contracts/')){$roles[]='contract_namespace';}
				if(str_ends_with($name,'Contract')){$roles[]='named_contract';}
				$declaration=[
					'fqcn'=>$fqcn,'name'=>$name,'kind'=>$kind,'module'=>$module,'path'=>$path,'line'=>$token[2]??null,
					'source_scope'=>$this->isProductionPhp($path)?'production':'test_support',
					'abstract'=>$abstract,'final'=>$this->hasModifier($tokens,$index,T_FINAL),'readonly'=>defined('T_READONLY')&&$this->hasModifier($tokens,$index,T_READONLY),
					'extends'=>$header['extends'],'implements'=>$header['implements'],'methods'=>[],
					'contract_roles'=>array_values(array_unique($roles)),'description'=>$this->previousDocSummary($tokens,$index),
					'collect_methods'=>$roles!==[],
				];
				$declarations[]=$declaration;
				$classOpen[$header['open_index']][]=count($declarations)-1;
				continue;
			}
			if($token[0]===T_FUNCTION && $classStack!==[]){
				$declarationIndex=$classStack[count($classStack)-1]['index'];
				if(($declarations[$declarationIndex]['collect_methods']??false)!==true){continue;}
				$nameIndex=$this->nextTokenId($tokens,$index+1,T_STRING);
				if($nameIndex===null){continue;}
				$declarations[$declarationIndex]['methods'][]=[
					'name'=>(string)$tokens[$nameIndex][1],
					'line'=>$token[2]??null,
					'visibility'=>$this->methodVisibility($tokens,$index),
					'static'=>$this->hasModifier($tokens,$index,T_STATIC),
					'signature'=>$this->methodSignature($tokens,$index),
				];
			}
		}
		foreach($declarations as &$declaration){unset($declaration['collect_methods']);}
		unset($declaration);
		return $declarations;
	}

	/** @return array{open_index:?int,extends:list<string>,implements:list<string>} */
	private function classHeader(array $tokens,int $start,string $namespace,array $aliases): array {
		$mode='';$name='';$extends=[];$implements=[];$open=null;
		$flush=function() use (&$name,&$mode,&$extends,&$implements,$namespace,$aliases): void {
			$name=trim(trim($name),'\\');
			if($name===''){return;}
			$resolved=$this->resolveName($name,$namespace,$aliases);
			if($mode==='extends'){$extends[]=$resolved;}elseif($mode==='implements'){$implements[]=$resolved;}
			$name='';
		};
		for($index=$start,$count=count($tokens);$index<$count;$index++){
			$token=$tokens[$index];
			if($token==='{'){$flush();$open=$index;break;}
			if($token===';'){break;}
			if($token===','){$flush();continue;}
			if(!is_array($token)){continue;}
			if($token[0]===T_EXTENDS){$flush();$mode='extends';continue;}
			if($token[0]===T_IMPLEMENTS){$flush();$mode='implements';continue;}
			if($mode!=='' && in_array($token[0],[T_STRING,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED,T_NAME_RELATIVE,T_NS_SEPARATOR],true)){$name.=$token[1];}
		}
		return ['open_index'=>$open,'extends'=>array_values(array_unique($extends)),'implements'=>array_values(array_unique($implements))];
	}

	private function resolveName(string $name,string $namespace,array $aliases): string {
		$name=trim($name);
		if(str_starts_with($name,'\\')){return ltrim($name,'\\');}
		if(str_starts_with(strtolower($name),'namespace\\')){return trim($namespace.'\\'.substr($name,10),'\\');}
		$parts=explode('\\',$name);
		$first=$parts[0]??'';
		if(isset($aliases[$first])){array_shift($parts);return trim($aliases[$first].($parts!==[]?'\\'.implode('\\',$parts):''),'\\');}
		if(in_array(strtolower($name),['jsonserializable','iterator','iteratoraggregate','arrayaccess','countable','stringable','throwable'],true)){return $name;}
		return trim(($namespace!==''?$namespace.'\\':'').$name,'\\');
	}

	/** @return list<array<string,mixed>> */
	private function serializedContracts(array $tokens,string $path,?string $module,array $declarations): array {
		$records=[];
		for($index=0,$count=count($tokens);$index<$count;$index++){
			$token=$tokens[$index];
			if(!is_array($token) || $token[0]!==T_CONSTANT_ENCAPSED_STRING || $this->decodeString($token[1])!=='type'){continue;}
			$arrow=$this->nextMeaningful($tokens,$index+1);
			$valueIndex=$arrow===null?null:$this->nextMeaningful($tokens,$arrow+1);
			if($arrow===null || !is_array($tokens[$arrow]) || $tokens[$arrow][0]!==T_DOUBLE_ARROW || $valueIndex===null || !is_array($tokens[$valueIndex]) || $tokens[$valueIndex][0]!==T_CONSTANT_ENCAPSED_STRING){continue;}
			$type=$this->decodeString((string)$tokens[$valueIndex][1]);
			if(!$this->looksLikeSerializedContract($type)){continue;}
			$producer=$this->declarationAtLine($declarations,(int)($token[2]??0));
			$version=$this->nearbyArrayValue($tokens,$valueIndex,'version',100);
			$records[]=[
				'id'=>'serialized:'.$type,'kind'=>'serialized_contract','name'=>$type,'module'=>$module,'path'=>$path,'line'=>$token[2]??null,
				'version'=>$version['value']??null,'version_resolved'=>$version['resolved']??false,'version_expression'=>$version['expression']??null,
				'producer'=>$producer['fqcn']??null,'producer_kind'=>$producer['kind']??null,
				'confidence'=>$this->serializedConfidence($type,$producer),
			];
		}
		return $records;
	}

	private function looksLikeSerializedContract(string $type): bool {
		if(preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/',$type)!==1){return false;}
		if(in_array($type,['string','integer','number','boolean','array','object','null','text','date','datetime','json','select','textarea','checkbox','radio','email','password','url','uuid','decimal','bigint','money','image','file','hidden'],true)){return false;}
		return str_starts_with($type,'panel_') || str_starts_with($type,'dataphyre_') || preg_match('/(?:^|_)(?:manifest|contract|receipt|intent|envelope|checkpoint|decision|profile|plan|report|catalog|schema|event|request|response)$/',$type)===1;
	}

	private function serializedConfidence(string $type,?array $producer): string {
		if(preg_match('/(?:^|_)(?:manifest|contract|receipt|intent|envelope|checkpoint)$/',$type)===1){return 'explicit';}
		if($producer!==null && (in_array('JsonSerializable',$producer['implements']??[],true) || str_ends_with((string)($producer['name']??''),'Manifest'))){return 'producer_backed';}
		return 'conventional';
	}

	/** @return array{contracts:list<array<string,mixed>>,test_file:array<string,mixed>} */
	private function semanticContracts(array $tokens,string $path,?string $module): array {
		$suites=$this->rootCalls($tokens,'suite');
		$cases=$this->rootCalls($tokens,'test');
		$records=[];
		for($index=0,$count=count($tokens);$index<$count;$index++){
			$token=$tokens[$index];
			if(!is_array($token) || $token[0]!==T_OBJECT_OPERATOR){continue;}
			$nameIndex=$this->nextMeaningful($tokens,$index+1);
			if($nameIndex===null || !is_array($tokens[$nameIndex]) || strtolower((string)$tokens[$nameIndex][1])!=='contract'){continue;}
			$open=$this->nextMeaningful($tokens,$nameIndex+1);
			if($open===null || $tokens[$open]!=='('){continue;}
			[$arguments,$close]=$this->callArguments($tokens,$open);
			$nameValue=$this->literalValue($arguments[0]??[]);
			if(!is_string($nameValue['value']??null) || trim((string)$nameValue['value'])===''){continue;}
			$versionValue=isset($arguments[1])?$this->literalValue($arguments[1]):['value'=>'1','resolved'=>true,'expression'=>'1'];
			$version=(string)(($versionValue['value']??null) ?? ($versionValue['expression']??'unknown'));
			$scope=$this->nearestRootCall($tokens,$index);
			if($scope===null){continue;}
			$rangeStart=$scope['index']??$index;
			$rangeEnd=$this->chainEnd($tokens,$close);
			$metadata=$this->chainMetadata($tokens,$rangeStart,$rangeEnd);
			$suiteName=($scope['kind']??'')==='suite'
				?($scope['name']??null)
				:$this->suiteNameBefore($suites,(int)($scope['index']??$index));
			$records[]=[
				'id'=>'test:'.trim((string)$nameValue['value']).'@'.$version,
				'kind'=>'test_contract','name'=>trim((string)$nameValue['value']),'module'=>$module,'path'=>$path,'line'=>$token[2]??null,
				'version'=>$version,'version_resolved'=>(bool)($versionValue['resolved']??false),'version_expression'=>$versionValue['expression']??$version,
				'declaration_scope'=>$scope['kind']??'unknown','suite'=>$suiteName,
				'case'=>($scope['kind']??'')==='test'?($scope['name']??null):null,
				'declared_case_count'=>($scope['kind']??'')==='suite'?$this->caseCountForSuite($cases,$suites,(int)($scope['index']??$index)):1,
				'metadata'=>$metadata,
			];
		}
		$contractRefs=[];
		foreach($records as $record){$contractRefs[]=['id'=>$record['id'],'name'=>$record['name'],'version'=>$record['version'],'scope'=>$record['declaration_scope']];}
		return [
			'contracts'=>$records,
			'test_file'=>[
				'path'=>$path,'module'=>$module,'kind'=>'code','suite_names'=>array_values(array_unique(array_column($suites,'name'))),
				'declared_case_count'=>count($cases),'expanded_case_count'=>null,'contracts'=>$contractRefs,
				'cases'=>array_map(static fn(array $case): array=>['name'=>$case['name'],'line'=>$case['line']],$cases),
				'execution'=>'not_executed','runtime_metadata_command'=>'php bin/dataphyre-test list --owner='.($module??'<module>').' --cases --json',
			],
		];
	}

	/** @return list<array{index:int,name:string,line:?int,close_index:int,chain_end:int}> */
	private function rootCalls(array $tokens,string $name): array {
		$calls=[];
		for($index=0,$count=count($tokens);$index<$count;$index++){
			$token=$tokens[$index];
			if(!is_array($token) || !in_array($token[0],[T_STRING,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED],true)){continue;}
			$called=strtolower(trim((string)$token[1],'\\'));
			if($called!==$name && !str_ends_with($called,'\\'.$name)){continue;}
			$previous=$this->previousMeaningful($tokens,$index);
			if($previous!==null && is_array($tokens[$previous]) && in_array($tokens[$previous][0],[T_OBJECT_OPERATOR,T_DOUBLE_COLON],true)){continue;}
			$open=$this->nextMeaningful($tokens,$index+1);
			if($open===null || $tokens[$open]!=='('){continue;}
			[$arguments,$close]=$this->callArguments($tokens,$open);
			$value=$this->literalValue($arguments[0]??[]);
			if(is_string($value['value']??null)){$calls[]=[
				'index'=>$index,'name'=>(string)$value['value'],'line'=>$token[2]??null,
				'close_index'=>$close,'chain_end'=>$this->chainEnd($tokens,$close),
			];}
		}
		return $calls;
	}

	/** @return array{kind:string,name:string,index:int,close_index:int,chain_end:int}|null */
	private function nearestRootCall(array $tokens,int $before): ?array {
		for($index=$before-1;$index>=0;$index--){
			$token=$tokens[$index];
			if(!is_array($token) || !in_array($token[0],[T_STRING,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED],true)){continue;}
			$called=strtolower(trim((string)$token[1],'\\'));
			$kind=str_ends_with($called,'\\test')||$called==='test'?'test':(str_ends_with($called,'\\suite')||$called==='suite'?'suite':'');
			if($kind===''){continue;}
			$previous=$this->previousMeaningful($tokens,$index);
			if($previous!==null && is_array($tokens[$previous]) && in_array($tokens[$previous][0],[T_OBJECT_OPERATOR,T_DOUBLE_COLON],true)){continue;}
			$open=$this->nextMeaningful($tokens,$index+1);
			if($open===null || $tokens[$open]!=='('){continue;}
			[$arguments,$close]=$this->callArguments($tokens,$open);
			$chainEnd=$this->chainEnd($tokens,$close);
			if($before<=$close || $before>$chainEnd){continue;}
			$value=$this->literalValue($arguments[0]??[]);
			return [
				'kind'=>$kind,'name'=>is_string($value['value']??null)?(string)$value['value']:$kind,'index'=>$index,
				'close_index'=>$close,'chain_end'=>$chainEnd,
			];
		}
		return null;
	}

	/** @param list<array{index:int,name:string}> $suites */
	private function suiteNameBefore(array $suites,int $index): ?string {
		$name=null;
		foreach($suites as $suite){if($suite['index']>=$index){break;}$name=$suite['name'];}
		return $name;
	}

	/**
	 * @param list<array{index:int,name:string}> $cases
	 * @param list<array{index:int,name:string}> $suites
	 */
	private function caseCountForSuite(array $cases,array $suites,int $suiteIndex): int {
		$nextSuite=null;
		foreach($suites as $suite){if($suite['index']>$suiteIndex){$nextSuite=$suite['index'];break;}}
		return count(array_filter($cases,static fn(array $case): bool=>$case['index']>$suiteIndex&&($nextSuite===null||$case['index']<$nextSuite)));
	}

	/** @return array<string,mixed> */
	private function chainMetadata(array $tokens,int $start,int $end): array {
		$lists=['tag'=>'tags','group'=>'groups','watches'=>'watches','through'=>'through','dependsOn'=>'dependencies','sandboxesRootpath'=>'rootpath_sandboxes'];
		$scalars=['layer'=>'layer','risk'=>'risk','isolation'=>'isolation','maxMillis'=>'max_millis','memoryLimit'=>'memory_limit','coverageMemoryLimit'=>'coverage_memory_limit','repeat'=>'repeat','id'=>'stable_id'];
		$metadata=[];
		for($index=$start;$index<=$end && $index<count($tokens);$index++){
			$token=$tokens[$index];
			if(!is_array($token) || $token[0]!==T_OBJECT_OPERATOR){continue;}
			$nameIndex=$this->nextMeaningful($tokens,$index+1);
			if($nameIndex===null || !is_array($tokens[$nameIndex])){continue;}
			$method=(string)$tokens[$nameIndex][1];
			if(!isset($lists[$method]) && !isset($scalars[$method])){continue;}
			$open=$this->nextMeaningful($tokens,$nameIndex+1);
			if($open===null || $tokens[$open]!=='('){continue;}
			[$arguments,$close]=$this->callArguments($tokens,$open);
			$values=[];
			foreach($arguments as $argument){$literal=$this->literalValue($argument);if(($literal['resolved']??false)===true){$values[]=$literal['value'];}}
			if(isset($lists[$method])){$metadata[$lists[$method]]=array_values(array_unique(array_map('strval',$values)));}
			elseif($values!==[]){$metadata[$scalars[$method]]=$values[0];}
			$index=$close;
		}
		return $metadata;
	}

	/** @return array{0:list<list<mixed>>,1:int} */
	private function callArguments(array $tokens,int $open): array {
		$arguments=[];$current=[];$round=0;$square=0;$curly=0;$close=$open;
		for($index=$open+1,$count=count($tokens);$index<$count;$index++){
			$token=$tokens[$index];
			if($token==='('){$round++;$current[]=$token;continue;}
			if($token===')'){
				if($round===0 && $square===0 && $curly===0){if($current!==[]){$arguments[]=$current;}$close=$index;break;}
				$round=max(0,$round-1);$current[]=$token;continue;
			}
			if($token==='['){$square++;$current[]=$token;continue;}
			if($token===']'){$square=max(0,$square-1);$current[]=$token;continue;}
			if($token==='{'){$curly++;$current[]=$token;continue;}
			if($token==='}'){$curly=max(0,$curly-1);$current[]=$token;continue;}
			if($token===',' && $round===0 && $square===0 && $curly===0){$arguments[]=$current;$current=[];continue;}
			$current[]=$token;
		}
		return [$arguments,$close];
	}

	/** @return array{value:mixed,resolved:bool,expression:string} */
	private function literalValue(array $tokens): array {
		$meaningful=[];$expression='';
		foreach($tokens as $token){
			if(is_array($token) && in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)){continue;}
			$meaningful[]=$token;$expression.=is_array($token)?$token[1]:$token;
		}
		$expression=trim(preg_replace('/\s+/',' ',$expression)??'');
		if(count($meaningful)!==1){return ['value'=>null,'resolved'=>false,'expression'=>$expression];}
		$token=$meaningful[0];
		if(is_array($token) && $token[0]===T_CONSTANT_ENCAPSED_STRING){return ['value'=>$this->decodeString($token[1]),'resolved'=>true,'expression'=>$expression];}
		if(is_array($token) && $token[0]===T_LNUMBER){return ['value'=>(int)$token[1],'resolved'=>true,'expression'=>$expression];}
		if(is_array($token) && $token[0]===T_DNUMBER){return ['value'=>(float)$token[1],'resolved'=>true,'expression'=>$expression];}
		if(is_array($token) && $token[0]===T_STRING && in_array(strtolower($token[1]),['true','false','null'],true)){
			return ['value'=>match(strtolower($token[1])){'true'=>true,'false'=>false,default=>null},'resolved'=>true,'expression'=>$expression];
		}
		return ['value'=>null,'resolved'=>false,'expression'=>$expression];
	}

	private function decodeString(string $literal): string {
		if(strlen($literal)<2){return $literal;}
		$quote=$literal[0];$body=substr($literal,1,-1);
		return $quote==="'"?str_replace(["\\\\","\\'"],["\\","'"],$body):stripcslashes($body);
	}

	/** @return array{value:mixed,resolved:bool,expression:string}|null */
	private function nearbyArrayValue(array $tokens,int $start,string $key,int $limit): ?array {
		$end=min(count($tokens),$start+$limit);
		for($index=$start+1;$index<$end;$index++){
			$token=$tokens[$index];
			if($token===';'){break;}
			if(!is_array($token) || $token[0]!==T_CONSTANT_ENCAPSED_STRING || $this->decodeString($token[1])!==$key){continue;}
			$arrow=$this->nextMeaningful($tokens,$index+1);$value=$arrow===null?null:$this->nextMeaningful($tokens,$arrow+1);
			if($arrow===null || !is_array($tokens[$arrow]) || $tokens[$arrow][0]!==T_DOUBLE_ARROW || $value===null){continue;}
			$slice=[];
			for($cursor=$value;$cursor<$end;$cursor++){
				$current=$tokens[$cursor];
				if($cursor>$value && ($current===',' || $current===']' || $current===';')){break;}
				$slice[]=$current;
			}
			return $this->literalValue($slice);
		}
		return null;
	}

	private function chainEnd(array $tokens,int $start): int {
		$round=0;$square=0;$curly=0;
		for($index=$start+1,$count=count($tokens);$index<$count;$index++){
			$token=$tokens[$index];
			if($token==='('){$round++;}elseif($token===')'){$round=max(0,$round-1);}elseif($token==='['){$square++;}elseif($token===']'){$square=max(0,$square-1);}elseif($token==='{'){$curly++;}elseif($token==='}'){$curly=max(0,$curly-1);}
			if($token===';' && $round===0 && $square===0 && $curly===0){return $index;}
		}
		return min(count($tokens)-1,$start+300);
	}

	/** @return array{contract:array<string,mixed>,test_file:array<string,mixed>} */
	private function parseLegacyManifest(string $absolute,string $path,?string $module): array {
		$text=@file_get_contents($absolute);
		$data=is_string($text)?json_decode($text,true):null;
		$valid=is_array($data);
		$cases=$valid?(array_is_list($data)?$data:[$data]):[];
		$caseRows=[];
		foreach($cases as $case){if(is_array($case)){$caseRows[]=['name'=>(string)($case['name']??''),'function'=>(string)($case['function']??''),'expected_shape'=>get_debug_type($case['expected']??null)];}}
		$id='legacy:'.$path;
		$contract=['id'=>$id,'kind'=>'legacy_test_manifest','name'=>basename($path),'module'=>$module,'path'=>$path,'line'=>1,'valid_json'=>$valid,'case_count'=>count($cases)];
		return ['contract'=>$contract,'test_file'=>[
			'path'=>$path,'module'=>$module,'kind'=>'json','suite_names'=>[],'declared_case_count'=>count($cases),'expanded_case_count'=>count($cases),
			'contracts'=>[['id'=>$id,'name'=>basename($path),'version'=>null,'scope'=>'manifest']],'cases'=>$caseRows,'valid_json'=>$valid,
			'json_error'=>$valid?null:json_last_error_msg(),'execution'=>'not_executed',
		]];
	}

	/** @return array<string,mixed>|null */
	private function declarationAtLine(array $declarations,int $line): ?array {
		$selected=null;
		foreach($declarations as $declaration){if((int)($declaration['line']??0)<=$line){$selected=$declaration;}else{break;}}
		return $selected;
	}

	private function qualifiedNameAfter(array $tokens,int $start): string {
		$name='';
		for($index=$start,$count=count($tokens);$index<$count;$index++){
			$token=$tokens[$index];
			if($token===';' || $token==='{'){break;}
			if(is_array($token) && in_array($token[0],[T_STRING,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED,T_NS_SEPARATOR],true)){$name.=$token[1];}
		}
		return trim($name,'\\');
	}

	private function previousDocSummary(array $tokens,int $index): string {
		$cursor=$index-1;
		while($cursor>=0 && is_array($tokens[$cursor]??null) && ($tokens[$cursor][0]??null)===T_WHITESPACE){$cursor--;}
		$token=$tokens[$cursor]??null;
		if(!is_array($token) || $token[0]!==T_DOC_COMMENT){return '';}
		$text=preg_replace('#^/\*\*|\*/$#','',$token[1])??'';
		$text=preg_replace('/^\s*\*\s?/m','',$text)??'';
		$text=trim(preg_replace('/\s+/',' ',$text)??'');
		$at=strpos($text,' @');if($at!==false){$text=substr($text,0,$at);}
		return strlen($text)>240?substr($text,0,237).'...':$text;
	}

	private function methodSignature(array $tokens,int $start): string {
		$text='';$round=0;
		for($index=$start,$count=count($tokens);$index<$count;$index++){
			$token=$tokens[$index];
			if($token==='('){$round++;}elseif($token===')'){$round=max(0,$round-1);}
			if(($token==='{' || $token===';') && $round===0){break;}
			$text.=is_array($token)?$token[1]:$token;
		}
		return trim(preg_replace('/\s+/',' ',$text)??'');
	}

	private function methodVisibility(array $tokens,int $index): string {
		for($cursor=$index-1;$cursor>=0 && $cursor>=$index-12;$cursor--){
			$token=$tokens[$cursor];
			if(!is_array($token)){if(in_array($token,[';','{','}'],true)){break;}continue;}
			if($token[0]===T_PRIVATE){return 'private';}if($token[0]===T_PROTECTED){return 'protected';}if($token[0]===T_PUBLIC){return 'public';}
		}
		return 'public';
	}

	private function hasModifier(array $tokens,int $index,int $modifier): bool {
		for($cursor=$index-1;$cursor>=0 && $cursor>=$index-16;$cursor--){
			$token=$tokens[$cursor];
			if(!is_array($token)){if(in_array($token,[';','{','}'],true)){break;}continue;}
			if($token[0]===$modifier){return true;}
			if(!in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_FINAL,T_ABSTRACT,T_READONLY],true)){break;}
		}
		return false;
	}

	private function nextTokenId(array $tokens,int $start,int $id): ?int {
		for($index=$start,$count=count($tokens);$index<$count;$index++){
			$token=$tokens[$index];if(is_array($token)&&$token[0]===$id){return $index;}if(in_array($token,['(',';','{'],true)){return null;}
		}
		return null;
	}

	private function nextMeaningful(array $tokens,int $start): ?int {
		for($index=$start,$count=count($tokens);$index<$count;$index++){
			$token=$tokens[$index];if(is_array($token)&&in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)){continue;}return $index;
		}
		return null;
	}

	private function previousMeaningful(array $tokens,int $index): int {
		$cursor=$index-1;
		while($cursor>0 && is_array($tokens[$cursor]??null) && in_array($tokens[$cursor][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)){$cursor--;}
		return max(0,$cursor);
	}

	private function relative(string $path): string {return ltrim(substr(str_replace('\\','/',$path),strlen($this->root)),'/');}
	private function moduleFromPath(string $path): ?string {return preg_match('#^runtime/modules/([^/]+)/#',$path,$match)===1?$match[1]:null;}
	private function isCodeTest(string $path): bool {return str_contains('/'.$path,'/unit_tests/')&&str_ends_with(strtolower($path),'.test.php');}
	private function isLegacyManifest(string $path): bool {return str_contains('/'.str_replace('\\','/',$path),'/unit_tests/')&&str_ends_with(strtolower($path),'.json');}
	private function isProductionPhp(string $path): bool {return !str_contains('/'.$path,'/unit_tests/')&&!str_contains('/'.$path,'/testing/')&&!str_contains('/'.$path,'/documentation/');}

	private static function recordOrder(array $left,array $right): int {
		return strcmp((string)($left['id']??$left['path']??''),(string)($right['id']??$right['path']??'')) ?: strcmp((string)($left['path']??''),(string)($right['path']??''));
	}
}
