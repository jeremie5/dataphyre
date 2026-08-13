<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Mcp\Panel;

use Dataphyre\Mcp\Contracts\ContractCatalog;
use Dataphyre\Mcp\Contracts\SourceContractIndex;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Builds a bounded Panel capability inventory from source text only.
 *
 * Panel classes are never required or reflected. The platform catalogue is
 * decoded from its deliberately small literal PHP-array grammar, while public
 * contracts continue to come from the shared source contract index.
 */
final class SourcePanelCapabilityIndex implements PanelCapabilitySource {
	private const PLATFORM_MANIFEST='runtime/modules/panel/Framework/Platform/PanelPlatformManifest.php';
	private const FRAMEWORK_ROOT='runtime/modules/panel/Framework';
	private const DOCUMENTATION_ROOT='runtime/modules/panel/documentation';
	private const TEST_ROOT='runtime/modules/panel/unit_tests';
	private const TEST_SUPPORT_ROOT='runtime/modules/panel/testing';

	private ?array $cachedSnapshot=null;
	private string $root;

	public function __construct(string $dataphyreRoot,private int $maxFiles=5000) {
		$resolved=realpath($dataphyreRoot);
		if(!is_string($resolved) || !is_dir($resolved.'/runtime/modules/panel')){
			throw new \InvalidArgumentException('Panel capability source root must contain runtime/modules/panel.');
		}
		$this->root=str_replace('\\','/',$resolved);
		$this->maxFiles=max(1,min($this->maxFiles,20000));
	}

	/** @return array<string,mixed> */
	public function snapshot(): array {
		if($this->cachedSnapshot!==null){return $this->cachedSnapshot;}
		$diagnostics=[
			'platform_manifest'=>self::PLATFORM_MANIFEST,
			'platform_manifest_present'=>false,
			'platform_catalogue_parsed'=>false,
			'platform_parse_failures'=>[],
			'framework_truncated'=>false,
			'test_inventory_truncated'=>false,
			'unreadable_files'=>[],
			'missing_feature_sources'=>[],
		];
		$catalogue=$this->platformCatalogue($diagnostics);
		$framework=$this->frameworkInventory($diagnostics);
		$documents=$this->documentationInventory($diagnostics);
		$contractSource=new SourceContractIndex($this->root,['panel'],$this->maxFiles);
		$contractSnapshot=$contractSource->snapshot();
		$contracts=$this->normalizedContracts(new ContractCatalog($contractSource));
		$tests=$this->testInventory($contractSnapshot['test_files']??[],$diagnostics);
		$domains=$this->domains($catalogue,$framework,$contracts,$diagnostics);
		$areas=$this->areas($framework,$domains);
		$diagnostics['domain_count']=count($domains);
		$diagnostics['framework_file_count']=count($framework['files']);
		$diagnostics['framework_area_count']=count($areas);
		$diagnostics['documentation_file_count']=count($documents);
		$diagnostics['test_file_count']=count($tests);
		$diagnostics['indexed_test_file_count']=count($contractSnapshot['test_files']??[]);
		$diagnostics['contract_count']=count($contracts);
		$diagnostics['contract_source']=$contractSnapshot['diagnostics']??[];
		$fingerprint=hash('sha256',json_encode([
			'domains'=>array_map(static fn(array $domain): array=>[
				'name'=>$domain['name'],'required'=>$domain['required_features'],'features'=>array_column($domain['features'],'class','name'),'services'=>array_column($domain['services'],'expected','name'),
			],$domains),
			'areas'=>array_map(static fn(array $area): array=>['name'=>$area['name'],'files'=>$area['file_count']],$areas),
			'documents'=>array_column($documents,'sha256','path'),
			'tests'=>array_column($tests,'path'),
			'contracts'=>array_column($contracts,'id'),
		],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
		return $this->cachedSnapshot=[
			'snapshot_type'=>'dataphyre_panel_capability_source',
			'source_model_version'=>1,
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'source_strategy'=>'literal_php_array_tokens_contract_tokens_and_bounded_markdown',
			'domains'=>$domains,
			'areas'=>$areas,
			'documents'=>$documents,
			'tests'=>$tests,
			'contracts'=>$contracts,
			'declarations'=>$this->compactDeclarations($contractSnapshot['declarations']??[]),
			'counts'=>[
				'domains'=>count($domains),'areas'=>count($areas),'framework_files'=>count($framework['files']),
				'documents'=>count($documents),'tests'=>count($tests),'indexed_tests'=>count($contractSnapshot['test_files']??[]),'contracts'=>count($contracts),
			],
			'inventory_fingerprint'=>$fingerprint,
			'diagnostics'=>$diagnostics,
		];
	}

	/** @return array<string,array<string,mixed>> */
	private function platformCatalogue(array &$diagnostics): array {
		$path=$this->root.'/'.self::PLATFORM_MANIFEST;
		$diagnostics['platform_manifest_present']=is_file($path);
		if(!is_file($path)){$diagnostics['platform_parse_failures'][]='platform_manifest_missing';return [];}
		$source=@file_get_contents($path);
		if(!is_string($source)){$diagnostics['unreadable_files'][]=self::PLATFORM_MANIFEST;return [];}
		try{$catalogue=$this->decodeCatalogueAssignments($source);}
		catch(\Throwable $failure){$diagnostics['platform_parse_failures'][]=$failure->getMessage();return [];}
		if($catalogue===[]){$diagnostics['platform_parse_failures'][]='platform_catalogue_empty';return [];}
		$domains=[];
		foreach($catalogue as $name=>$definition){
			if(!is_string($name)||!is_array($definition)){continue;}
			$prefix=is_string($definition['prefix']??null)?$definition['prefix']:$name;
			$required=$this->stringList($definition['required']??[]);
			$features=$this->stringMap($definition['features']??[]);
			$services=$this->stringMap($definition['services']??[]);
			$domains[$name]=['name'=>$name,'prefix'=>$prefix,'required'=>$required,'features'=>$features,'services'=>$services];
		}
		ksort($domains,SORT_STRING);
		$diagnostics['platform_catalogue_parsed']=$domains!==[];
		return $domains;
	}

	/** @return array<string,mixed> */
	private function decodeCatalogueAssignments(string $source): array {
		$tokens=token_get_all($source);
		$catalogue=[];
		for($index=0,$count=count($tokens);$index<$count;$index++){
			$token=$tokens[$index];
			if(!is_array($token)||$token[0]!==T_VARIABLE||$token[1]!=='$catalogue'){continue;}
			$cursor=$this->nextMeaningful($tokens,$index+1);
			$path=[];
			while($cursor!==null&&$tokens[$cursor]==='['){
				$cursor=$this->nextMeaningful($tokens,$cursor+1);
				if($cursor===null||!is_array($tokens[$cursor])||$tokens[$cursor][0]!==T_CONSTANT_ENCAPSED_STRING){break;}
				$path[]=$this->decodeStringLiteral($tokens[$cursor][1]);
				$cursor=$this->nextMeaningful($tokens,$cursor+1);
				if($cursor===null||$tokens[$cursor]!==']'){break;}
				$cursor=$this->nextMeaningful($tokens,$cursor+1);
			}
			if($cursor===null){continue;}
			$operator=$tokens[$cursor];
			$isAssign=$operator==='=';
			$isAdd=is_array($operator)&&$operator[0]===T_PLUS_EQUAL;
			if(!$isAssign&&!$isAdd){continue;}
			$cursor=$this->nextMeaningful($tokens,$cursor+1);
			if($cursor===null||$tokens[$cursor]!=='['){continue;}
			$value=$this->parseStaticValue($tokens,$cursor);
			if(!is_array($value)){throw new \UnexpectedValueException('Panel platform catalogue assignment must be a literal array.');}
			if($path===[]&&$isAssign){$catalogue=$value;}
			elseif($path!==[]){$this->mergeCataloguePath($catalogue,$path,$value,$isAdd);}
			$index=max($index,$cursor-1);
		}
		return $catalogue;
	}

	/** @param list<string> $path */
	private function mergeCataloguePath(array &$catalogue,array $path,array $value,bool $add): void {
		$cursor=&$catalogue;
		foreach($path as $segment){if(!isset($cursor[$segment])||!is_array($cursor[$segment])){$cursor[$segment]=[];}$cursor=&$cursor[$segment];}
		$cursor=$add ? $cursor+$value : $value;
	}

	private function parseStaticValue(array $tokens,int &$index): mixed {
		$index=$this->nextMeaningful($tokens,$index)??$index;
		$token=$tokens[$index]??null;
		if($token==='['){return $this->parseStaticArray($tokens,$index);}
		$value=$this->parseStaticAtom($tokens,$index);
		while(true){
			$cursor=$this->nextMeaningful($tokens,$index);
			if($cursor===null||$tokens[$cursor]!=='.'){break;}
			$index=$this->nextMeaningful($tokens,$cursor+1)??($cursor+1);
			$right=$this->parseStaticAtom($tokens,$index);
			if(!is_string($value)||!is_string($right)){throw new \UnexpectedValueException('Panel platform catalogue concatenation must resolve to strings.');}
			$value.=$right;
		}
		return $value;
	}

	private function parseStaticArray(array $tokens,int &$index): array {
		if(($tokens[$index]??null)!=='['){throw new \UnexpectedValueException('Expected literal array opener.');}
		$values=[];$index++;
		while(true){
			$cursor=$this->nextMeaningful($tokens,$index);
			if($cursor===null){throw new \UnexpectedValueException('Unclosed Panel platform catalogue array.');}
			if($tokens[$cursor]===']'){$index=$cursor+1;return $values;}
			$index=$cursor;
			$first=$this->parseStaticValue($tokens,$index);
			$cursor=$this->nextMeaningful($tokens,$index);
			if($cursor!==null&&is_array($tokens[$cursor])&&$tokens[$cursor][0]===T_DOUBLE_ARROW){
				if(!is_string($first)&&!is_int($first)){throw new \UnexpectedValueException('Panel platform catalogue keys must be literal strings or integers.');}
				$index=$this->nextMeaningful($tokens,$cursor+1)??($cursor+1);
				$values[$first]=$this->parseStaticValue($tokens,$index);
			}else{$values[]=$first;}
			$cursor=$this->nextMeaningful($tokens,$index);
			if($cursor===null){throw new \UnexpectedValueException('Unclosed Panel platform catalogue array.');}
			if($tokens[$cursor]===','){$index=$cursor+1;continue;}
			if($tokens[$cursor]===']'){$index=$cursor+1;return $values;}
			throw new \UnexpectedValueException('Unexpected token in Panel platform catalogue array.');
		}
	}

	private function parseStaticAtom(array $tokens,int &$index): mixed {
		$token=$tokens[$index]??null;
		if(is_array($token)&&$token[0]===T_CONSTANT_ENCAPSED_STRING){$index++;return $this->decodeStringLiteral($token[1]);}
		if($token==='-'){
			$index=$this->nextMeaningful($tokens,$index+1)??($index+1);
			$value=$this->parseStaticAtom($tokens,$index);
			if(is_int($value)||is_float($value)){return -$value;}
			throw new \UnexpectedValueException('Panel platform catalogue unary minus requires a number.');
		}
		if(is_array($token)&&in_array($token[0],[T_LNUMBER,T_DNUMBER],true)){$index++;return $token[0]===T_LNUMBER?(int)$token[1]:(float)$token[1];}
		if(is_array($token)&&in_array($token[0],[T_STRING,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED,T_NAME_RELATIVE],true)){
			$name=$token[1];$index++;
			$cursor=$this->nextMeaningful($tokens,$index);
			if($cursor!==null&&is_array($tokens[$cursor])&&$tokens[$cursor][0]===T_DOUBLE_COLON){
				$classIndex=$this->nextMeaningful($tokens,$cursor+1);
				if($classIndex!==null&&is_array($tokens[$classIndex])&&$tokens[$classIndex][0]===T_CLASS){$index=$classIndex+1;return $this->normalizePanelClass($name);}
			}
			$lower=strtolower($name);
			if($lower==='true'){return true;}if($lower==='false'){return false;}if($lower==='null'){return null;}
		}
		throw new \UnexpectedValueException('Unsupported expression in Panel platform catalogue.');
	}

	private function normalizePanelClass(string $class): string {
		$class=ltrim($class,'\\');
		if(strtolower($class)==='self'){return 'Dataphyre\\Panel\\PanelPlatformManifest';}
		return str_contains($class,'\\')?$class:'Dataphyre\\Panel\\'.$class;
	}

	private function decodeStringLiteral(string $literal): string {
		if(strlen($literal)<2){return $literal;}
		$quote=$literal[0];$body=substr($literal,1,-1);
		return $quote==="'"?str_replace(["\\\\","\\'"],["\\","'"],$body):stripcslashes($body);
	}

	private function nextMeaningful(array $tokens,int $index): ?int {
		for($count=count($tokens);$index<$count;$index++){
			$token=$tokens[$index];
			if(is_array($token)&&in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_OPEN_TAG,T_CLOSE_TAG],true)){continue;}
			return $index;
		}
		return null;
	}

	/** @return array{files:list<array<string,mixed>>,class_paths:array<string,list<string>>} */
	private function frameworkInventory(array &$diagnostics): array {
		$files=[];$classPaths=[];
		foreach($this->filesBelow(self::FRAMEWORK_ROOT,$diagnostics['framework_truncated']) as $absolute){
			$relative=$this->relative($absolute);$extension=strtolower(pathinfo($relative,PATHINFO_EXTENSION));
			$area=preg_match('#^'.preg_quote(self::FRAMEWORK_ROOT,'#').'/([^/]+)/#',$relative,$match)===1?$match[1]:'root';
			$files[]=['path'=>$relative,'area'=>$area,'extension'=>$extension,'bytes'=>(int)(@filesize($absolute)?:0)];
			if($extension==='php'){$classPaths[pathinfo($relative,PATHINFO_FILENAME)][]=$relative;}
		}
		$supportTruncated=false;
		foreach($this->filesBelow(self::TEST_SUPPORT_ROOT,$supportTruncated) as $absolute){
			if(strtolower(pathinfo($absolute,PATHINFO_EXTENSION))!=='php'){continue;}
			$classPaths[pathinfo($absolute,PATHINFO_FILENAME)][]=$this->relative($absolute);
		}
		foreach($classPaths as &$paths){sort($paths,SORT_STRING);}unset($paths);
		return ['files'=>$files,'class_paths'=>$classPaths];
	}

	/** @return list<array<string,mixed>> */
	private function documentationInventory(array &$diagnostics): array {
		$unused=false;$documents=[];
		foreach($this->filesBelow(self::DOCUMENTATION_ROOT,$unused) as $absolute){
			if(strtolower(pathinfo($absolute,PATHINFO_EXTENSION))!=='md'){continue;}
			$text=@file_get_contents($absolute,false,null,0,262144);
			if(!is_string($text)){$diagnostics['unreadable_files'][]=$this->relative($absolute);continue;}
			$title=pathinfo($absolute,PATHINFO_FILENAME);$headings=[];
			foreach(preg_split('/\R/',$text)?:[] as $line){
				if(preg_match('/^(#{1,3})\s+(.+)$/',trim($line),$match)!==1){continue;}
				$headings[]=['level'=>strlen($match[1]),'title'=>trim($match[2])];
				if(count($headings)>=80){break;}
			}
			if(isset($headings[0]['title'])){$title=(string)$headings[0]['title'];}
			$documents[]=['path'=>$this->relative($absolute),'name'=>basename($absolute),'title'=>$title,'bytes'=>strlen($text),'sha256'=>hash('sha256',$text),'headings'=>$headings];
		}
		usort($documents,static fn(array $left,array $right): int=>strcmp($left['path'],$right['path']));
		return $documents;
	}

	/** @return list<array<string,mixed>> */
	private function normalizedContracts(ContractCatalog $catalog): array {
		$records=[];$offset=0;
		do{
			$page=$catalog->catalog(['modules'=>['panel'],'offset'=>$offset,'limit'=>200,'include_evidence'=>false]);
			array_push($records,...$page['records']);$offset=$page['pagination']['next_offset'];
		}while($offset!==null);
		return $records;
	}

	/** @return list<array<string,mixed>> */
	private function testInventory(array $indexed,array &$diagnostics): array {
		$byPath=[];
		foreach($indexed as $test){if(is_array($test)&&isset($test['path'])){$byPath[(string)$test['path']]=$test;}}
		$tests=[];
		foreach($this->filesBelow(self::TEST_ROOT,$diagnostics['test_inventory_truncated']) as $absolute){
			$extension=strtolower(pathinfo($absolute,PATHINFO_EXTENSION));if(!in_array($extension,['php','json'],true)){continue;}
			$path=$this->relative($absolute);$indexedTest=$byPath[$path]??[];
			$tests[]=[
				'path'=>$path,'kind'=>$extension==='json'?'json':'code','indexed'=>$indexedTest!==[],
				'contract_ids'=>array_values(array_filter(array_map(static fn(array $contract): string=>(string)($contract['id']??''),$indexedTest['contracts']??[]))),
				'contract_names'=>array_values(array_filter(array_map(static fn(array $contract): string=>(string)($contract['name']??''),$indexedTest['contracts']??[]))),
				'declared_case_count'=>(int)($indexedTest['declared_case_count']??0),
			];
		}
		usort($tests,static fn(array $left,array $right): int=>strcmp($left['path'],$right['path']));
		return $tests;
	}

	/** @return list<array<string,mixed>> */
	private function domains(array $catalogue,array $framework,array $contracts,array &$diagnostics): array {
		$contractNames=array_fill_keys(array_map(static fn(array $record): string=>(string)($record['name']??''),$contracts),true);
		$domains=[];
		foreach($catalogue as $name=>$definition){
			$features=[];$areas=[];
			foreach($definition['features'] as $feature=>$class){
				$classReference=$this->classReference($class);$short=$this->shortClass($classReference);$paths=$short!==''?($framework['class_paths'][$short]??[]):[];
				if($classReference!==''&&!$this->literalServiceType($classReference)&&$paths===[]){$diagnostics['missing_feature_sources'][]=$name.':'.$feature.':'.$classReference;}
				foreach($paths as $path){if(preg_match('#/Framework/([^/]+)/#',$path,$match)===1){$areas[]=$match[1];}}
				$features[]=[
					'name'=>$feature,'class'=>$classReference,'required'=>in_array($feature,$definition['required'],true),
					'source_paths'=>$paths,'source_present'=>$paths!==[]||$this->literalServiceType($classReference),
					'contract_id'=>$classReference!==''&&isset($contractNames[$classReference])?'php:'.$classReference:null,
				];
			}
			$services=[];
			foreach($definition['services'] as $service=>$expected){
				$classReference=$this->classReference($expected);$short=$this->shortClass($classReference);
				$services[]=['name'=>$service,'expected'=>$classReference,'source_paths'=>$short!==''?($framework['class_paths'][$short]??[]):[]];
			}
			usort($features,static fn(array $left,array $right): int=>strcmp($left['name'],$right['name']));
			usort($services,static fn(array $left,array $right): int=>strcmp($left['name'],$right['name']));
			$areas=array_values(array_unique($areas));sort($areas,SORT_STRING);
			$domains[]=[
				'id'=>'panel:domain:'.$name,'kind'=>'platform_domain','name'=>$name,'prefix'=>$definition['prefix'],
				'required_features'=>$definition['required'],'feature_count'=>count($features),'required_feature_count'=>count($definition['required']),
				'service_count'=>count($services),'features'=>$features,'services'=>$services,'framework_areas'=>$areas,
			];
		}
		usort($domains,static fn(array $left,array $right): int=>strcmp($left['name'],$right['name']));
		$diagnostics['missing_feature_sources']=array_values(array_unique($diagnostics['missing_feature_sources']));
		return $domains;
	}

	/** @return list<array<string,mixed>> */
	private function areas(array $framework,array $domains): array {
		$rows=[];
		foreach($framework['files'] as $file){
			$name=(string)$file['area'];
			$rows[$name]??=['id'=>'panel:area:'.$this->slug($name),'kind'=>'framework_area','name'=>$name,'path'=>self::FRAMEWORK_ROOT.($name==='root'?'':'/'.$name),'file_count'=>0,'php_file_count'=>0,'extensions'=>[],'sample_files'=>[],'related_domains'=>[]];
			$rows[$name]['file_count']++;if($file['extension']==='php'){$rows[$name]['php_file_count']++;}
			$rows[$name]['extensions'][$file['extension']]=($rows[$name]['extensions'][$file['extension']]??0)+1;
			if(count($rows[$name]['sample_files'])<20){$rows[$name]['sample_files'][]=$file['path'];}
		}
		foreach($domains as $domain){foreach($domain['framework_areas'] as $area){if(isset($rows[$area])){$rows[$area]['related_domains'][]=$domain['name'];}}}
		foreach($rows as &$row){ksort($row['extensions'],SORT_STRING);$row['related_domains']=array_values(array_unique($row['related_domains']));sort($row['related_domains'],SORT_STRING);}unset($row);
		$areas=array_values($rows);usort($areas,static fn(array $left,array $right): int=>strcmp($left['name'],$right['name']));return $areas;
	}

	/** @return list<array<string,mixed>> */
	private function compactDeclarations(array $declarations): array {
		$rows=[];
		foreach($declarations as $declaration){
			if(!is_array($declaration)){continue;}
			$rows[]=[
				'fqcn'=>$declaration['fqcn']??'','kind'=>$declaration['kind']??'','path'=>$declaration['path']??'','source_scope'=>$declaration['source_scope']??'',
				'extends'=>$declaration['extends']??[],'implements'=>$declaration['implements']??[],'contract_roles'=>$declaration['contract_roles']??[],
			];
		}
		return $rows;
	}

	/** @return list<string> */
	private function filesBelow(string $relativeRoot,bool &$truncated): array {
		$root=$this->root.'/'.$relativeRoot;if(!is_dir($root)){return [];}$files=[];
		$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY,RecursiveIteratorIterator::CATCH_GET_CHILD);
		/** @var SplFileInfo $file */
		foreach($iterator as $file){if(!$file->isFile()){continue;}$files[]=str_replace('\\','/',$file->getPathname());if(count($files)>=$this->maxFiles){$truncated=true;break;}}
		sort($files,SORT_STRING);return $files;
	}

	private function relative(string $path): string {return ltrim(substr(str_replace('\\','/',$path),strlen($this->root)),'/');}
	private function classReference(string $value): string {return str_starts_with($value,'class-string:')?substr($value,13):$value;}
	private function literalServiceType(string $value): bool {return $value===''||in_array($value,['callable','class-string'],true)||!str_contains($value,'\\');}
	private function shortClass(string $class): string {$class=$this->classReference($class);return str_contains($class,'\\')?substr($class,(int)strrpos($class,'\\')+1):'';}
	private function slug(string $value): string {return trim(preg_replace('/[^a-z0-9]+/','_',strtolower($value))??'','_');}
	/** @return list<string> */ private function stringList(mixed $value): array {$values=is_array($value)?$value:[$value];$values=array_values(array_unique(array_filter(array_map('strval',$values),static fn(string $item): bool=>$item!=='')));sort($values,SORT_STRING);return $values;}
	/** @return array<string,string> */ private function stringMap(mixed $value): array {$map=[];if(is_array($value)){foreach($value as $key=>$item){if(is_string($key)&&is_string($item)){$map[$key]=$item;}}}ksort($map,SORT_STRING);return $map;}
}
