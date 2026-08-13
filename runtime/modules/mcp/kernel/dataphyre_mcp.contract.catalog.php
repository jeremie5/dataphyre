<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Mcp\Contracts;

/**
 * Normalizes source records into a stable, queryable Dataphyre contract graph.
 */
final class ContractCatalog {
	public const MODEL_VERSION=1;
	public const KINDS=['test_contract','php_type_contract','serialized_contract','legacy_test_manifest'];

	private ?array $records=null;
	private ?array $snapshot=null;

	public function __construct(private ContractSource $source) {}

	/** @return array<string,mixed> */
	public function catalog(array $query=[]): array {
		$modules=$this->stringList($query['modules']??[]);
		$kinds=$this->stringList($query['kinds']??[]);
		$unknownKinds=array_values(array_diff($kinds,self::KINDS));
		if($unknownKinds!==[]){throw new \InvalidArgumentException('Unknown contract kinds: '.implode(', ',$unknownKinds).'.');}
		$needle=strtolower(trim((string)($query['query']??'')));
		$offset=max(0,(int)($query['offset']??0));
		$limit=max(1,min((int)($query['limit']??50)?:50,200));
		$includeEvidence=($query['include_evidence']??true)!==false;
		$records=array_values(array_filter($this->records(),function(array $record) use($modules,$kinds,$needle): bool {
			if($modules!==[] && array_intersect($modules,$record['modules']??[])===[]){return false;}
			if($kinds!==[] && !in_array((string)$record['kind'],$kinds,true)){return false;}
			if($needle===''){return true;}
			$haystack=strtolower(implode(' ',[
				(string)($record['id']??''),(string)($record['name']??''),(string)($record['kind']??''),
				implode(' ',array_map('strval',$record['modules']??[])),implode(' ',array_map('strval',$record['roles']??[])),
				implode(' ',array_map('strval',$record['producers']??[])),
			]));
			return str_contains($haystack,$needle);
		}));
		$kindSummary=[];$moduleSummary=[];
		foreach($records as $record){
			$kind=(string)$record['kind'];$kindSummary[$kind]=($kindSummary[$kind]??0)+1;
			foreach($record['modules']??[] as $module){$module=(string)$module;$moduleSummary[$module]=($moduleSummary[$module]??0)+1;}
		}
		ksort($kindSummary,SORT_STRING);ksort($moduleSummary,SORT_STRING);
		$page=array_slice($records,$offset,$limit);
		$page=array_map(fn(array $record): array=>$this->compactRecord($record,$includeEvidence),$page);
		$conflicts=$this->versionConflicts($records);
		return [
			'catalog_type'=>'dataphyre_contract_catalog','contract_model_version'=>self::MODEL_VERSION,
			'write_policy'=>'read_only','execution'=>'not_executed','source_strategy'=>'php_tokens_and_declarative_json',
			'filters'=>['modules'=>$modules,'kinds'=>$kinds,'query'=>$needle,'offset'=>$offset,'limit'=>$limit,'include_evidence'=>$includeEvidence],
			'counts'=>['matched'=>count($records),'returned'=>count($page),'total'=>count($this->records())],
			'kind_summary'=>$kindSummary,'module_summary'=>$moduleSummary,
			'version_health'=>[
				'unresolved_test_contract_count'=>count(array_filter($records,static fn(array $record): bool=>($record['kind']??null)==='test_contract'&&($record['version_resolved']??true)===false)),
				'serialized_without_literal_version_count'=>count(array_filter($records,static fn(array $record): bool=>($record['kind']??null)==='serialized_contract'&&($record['version']??null)===null)),
				'conflict_count'=>count($conflicts),'conflicts'=>$conflicts,
			],
			'pagination'=>['offset'=>$offset,'next_offset'=>$offset+count($page)<count($records)?$offset+count($page):null,'has_more'=>$offset+count($page)<count($records)],
			'records'=>$page,'inventory_fingerprint'=>$this->fingerprint(),'diagnostics'=>$this->snapshot()['diagnostics'],
		];
	}

	/** @return array<string,mixed> */
	public function describe(string $identity,int $maxEvidence=40): array {
		$identity=trim($identity);
		if($identity===''){throw new \InvalidArgumentException('Contract id or name is required.');}
		$matches=array_values(array_filter($this->records(),static function(array $record) use($identity): bool {
			return ($record['id']??null)===$identity || ($record['name']??null)===$identity || (($record['name']??'').'@'.($record['version']??''))===$identity;
		}));
		if($matches===[]){
			$folded=strtolower($identity);
			$matches=array_values(array_filter($this->records(),static fn(array $record): bool=>strtolower((string)($record['id']??''))===$folded||strtolower((string)($record['name']??''))===$folded));
		}
		if($matches===[]){throw new \OutOfBoundsException('Dataphyre contract was not found: '.$identity);}
		if(count($matches)>1){
			return [
				'descriptor_type'=>'dataphyre_contract_descriptor','contract_model_version'=>self::MODEL_VERSION,
				'status'=>'ambiguous','query'=>$identity,
				'candidates'=>array_map(fn(array $record): array=>$this->compactRecord($record,false),array_slice($matches,0,50)),
			];
		}
		$record=$matches[0];
		$record['evidence']=array_slice($record['evidence']??[],0,max(1,min($maxEvidence,200)));
		return [
			'descriptor_type'=>'dataphyre_contract_descriptor','contract_model_version'=>self::MODEL_VERSION,
			'status'=>'found','write_policy'=>'read_only','execution'=>'not_executed','contract'=>$record,
			'relationship_contract'=>[
				'implementation_map'=>'contract.implemented_by contains direct source-declared implementers and extenders',
				'executable_evidence'=>'contract.executable_evidence contains explicit TestKit watches(type:...) links only',
				'no_runtime_inference'=>true,
			],
		];
	}

	/** @return list<array<string,mixed>> */
	public function testFiles(array $modules=[],string $kind='all',string $contract=''): array {
		$modules=$this->stringList($modules);
		$kind=strtolower(trim($kind));
		if(!in_array($kind,['all','code','json'],true)){throw new \InvalidArgumentException('Test manifest kind must be all, code, or json.');}
		$contract=trim($contract);
		return array_values(array_filter($this->snapshot()['test_files'],static function(array $file) use($modules,$kind,$contract): bool {
			if($modules!==[] && !in_array((string)($file['module']??''),$modules,true)){return false;}
			if($kind!=='all' && ($file['kind']??null)!==$kind){return false;}
			if($contract===''){return true;}
			foreach($file['contracts']??[] as $candidate){if(($candidate['id']??null)===$contract||($candidate['name']??null)===$contract){return true;}}
			return false;
		}));
	}

	/** @return array<string,mixed> */
	public function testFile(string $path): array {
		$path=ltrim(str_replace('\\','/',$path),'/');
		foreach($this->snapshot()['test_files'] as $file){if(($file['path']??null)===$path){return $file;}}
		throw new \OutOfBoundsException('Dataphyre test manifest was not found in the contract index: '.$path);
	}

	/** @return list<array<string,mixed>> */
	private function records(): array {
		if($this->records!==null){return $this->records;}
		$groups=[];
		foreach($this->snapshot()['contracts'] as $record){
			$id=(string)($record['id']??'');if($id===''){continue;}
			if(!isset($groups[$id])){$groups[$id]=$this->baseRecord($record);}
			$groups[$id]['evidence'][]=$this->evidence($record);
			if(($record['module']??null)!==null){$groups[$id]['modules'][]=(string)$record['module'];}
			if(($record['producer']??null)!==null){$groups[$id]['producers'][]=(string)$record['producer'];}
			if(isset($record['metadata'])&&is_array($record['metadata'])){$groups[$id]['metadata_variants'][]=$record['metadata'];}
		}
		$declarations=$this->snapshot()['declarations'];
		foreach($groups as &$record){
			$record['modules']=$this->uniqueStrings($record['modules']);
			$record['producers']=$this->uniqueStrings($record['producers']);
			$record['metadata_variants']=$this->uniqueArrays($record['metadata_variants']);
			$record['evidence']=$this->uniqueArrays($record['evidence']);
			$record['evidence_count']=count($record['evidence']);
			if($record['kind']==='php_type_contract'){
				$record['implemented_by']=$this->implementers((string)$record['name'],$declarations);
				$record['implementation_count']=count(array_filter($record['implemented_by'],static fn(array $item): bool=>($item['source_scope']??null)==='production'));
				$record['test_implementation_count']=count($record['implemented_by'])-$record['implementation_count'];
				$record['executable_evidence']=$this->watchedEvidence((string)$record['name'],$groups);
			}
		}
		unset($record);
		$records=array_values($groups);
		usort($records,static fn(array $left,array $right): int=>strcmp((string)$left['kind'],(string)$right['kind'])?:strcmp((string)$left['name'],(string)$right['name'])?:strcmp((string)$left['id'],(string)$right['id']));
		return $this->records=$records;
	}

	/** @return array<string,mixed> */
	private function baseRecord(array $record): array {
		$base=[
			'id'=>(string)$record['id'],'kind'=>(string)$record['kind'],'name'=>(string)($record['name']??$record['id']),
			'modules'=>[],'producers'=>[],'evidence'=>[],'metadata_variants'=>[],
		];
		foreach(['version','version_resolved','version_expression','symbol_kind','roles','extends','implements','method_count','methods','description','confidence','valid_json','case_count'] as $field){
			if(array_key_exists($field,$record)){$base[$field]=$record[$field];}
		}
		return $base;
	}

	/** @return array<string,mixed> */
	private function evidence(array $record): array {
		$fields=['module','path','line','declaration_scope','suite','case','declared_case_count','metadata','producer','producer_kind','confidence','valid_json','case_count'];
		$evidence=[];
		foreach($fields as $field){if(array_key_exists($field,$record)){$evidence[$field]=$record[$field];}}
		return $evidence;
	}

	/** @return list<array<string,mixed>> */
	private function implementers(string $contract,array $declarations): array {
		$rows=[];
		foreach($declarations as $declaration){
			$relations=array_merge($declaration['extends']??[],$declaration['implements']??[]);
			if(!in_array($contract,$relations,true)){continue;}
			$rows[]=[
				'fqcn'=>$declaration['fqcn'],'kind'=>$declaration['kind'],'module'=>$declaration['module'],'path'=>$declaration['path'],'line'=>$declaration['line'],'source_scope'=>$declaration['source_scope'],
				'relation'=>in_array($contract,$declaration['implements']??[],true)?'implements':'extends',
			];
		}
		usort($rows,static fn(array $left,array $right): int=>strcmp((string)$left['fqcn'],(string)$right['fqcn']));
		return $rows;
	}

	/** @return list<array<string,mixed>> */
	private function watchedEvidence(string $contract,array $groups): array {
		$short=substr($contract,(int)strrpos('\\'.$contract,'\\'));
		$targets=['type:'.$contract,'type:'.$short,$contract,$short];
		$evidence=[];
		foreach($groups as $record){
			if(($record['kind']??null)!=='test_contract'){continue;}
			foreach($record['metadata_variants']??[] as $metadata){
				if(array_intersect($targets,array_map('strval',$metadata['watches']??[]))===[]){continue;}
				$evidence[]=['id'=>$record['id'],'name'=>$record['name'],'version'=>$record['version']??null];break;
			}
		}
		return $this->uniqueArrays($evidence);
	}

	/** @return array<string,mixed> */
	private function compactRecord(array $record,bool $includeEvidence): array {
		$compact=$record;
		unset($compact['methods'],$compact['metadata_variants'],$compact['implemented_by'],$compact['executable_evidence']);
		if(!$includeEvidence){unset($compact['evidence']);}
		elseif(isset($compact['evidence'])){$compact['evidence']=array_slice($compact['evidence'],0,5);}
		if(($record['kind']??null)==='php_type_contract'){
			$compact['detail_summary']=[
				'method_count'=>$record['method_count']??0,'implementation_count'=>$record['implementation_count']??0,'test_implementation_count'=>$record['test_implementation_count']??0,
				'executable_evidence_count'=>count($record['executable_evidence']??[]),'describe_with'=>'dataphyre_contract_describe',
			];
		}
		return $compact;
	}

	/** @return list<array{name:string,versions:list<string>}> */
	private function versionConflicts(array $records): array {
		$versions=[];
		foreach($records as $record){if(($record['kind']??null)!=='test_contract'){continue;}$versions[(string)$record['name']][]=(string)($record['version']??'');}
		$conflicts=[];
		foreach($versions as $name=>$items){$items=$this->uniqueStrings($items);if(count($items)>1){$conflicts[]=['name'=>$name,'versions'=>$items];}}
		usort($conflicts,static fn(array $left,array $right): int=>strcmp($left['name'],$right['name']));
		return $conflicts;
	}

	private function fingerprint(): string {
		$rows=array_map(static fn(array $record): array=>[
			'id'=>$record['id'],'kind'=>$record['kind'],'modules'=>$record['modules'],'evidence_count'=>$record['evidence_count'],
		],$this->records());
		return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
	}

	private function snapshot(): array {return $this->snapshot??=$this->source->snapshot();}

	/** @return list<string> */
	private function stringList(mixed $value): array {
		if(!is_array($value)){$value=[$value];}
		return $this->uniqueStrings(array_values(array_filter(array_map(static fn(mixed $item): string=>trim((string)$item),$value),static fn(string $item): bool=>$item!=='')));
	}

	/** @return list<string> */
	private function uniqueStrings(array $values): array {$values=array_values(array_unique(array_map('strval',$values)));sort($values,SORT_STRING);return $values;}

	/** @return list<array<string,mixed>> */
	private function uniqueArrays(array $values): array {
		$unique=[];
		foreach($values as $value){if(!is_array($value)){continue;}$key=hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));$unique[$key]=$value;}
		return array_values($unique);
	}
}
