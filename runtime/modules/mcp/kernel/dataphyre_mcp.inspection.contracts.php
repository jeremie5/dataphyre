<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Mcp\Contracts\ContractCatalog;
use Dataphyre\Mcp\Contracts\SourceContractIndex;

/**
 * Public MCP surfaces for source-derived Dataphyre contracts.
 */
trait dataphyre_mcp_inspection_contract_surfaces {
	/** @var array<string,ContractCatalog> */
	private array $contract_catalog_cache=[];
	private string $contract_catalog_root='';

	/** @return array<string,mixed> */
	private function contract_catalog(array $args): array {
		$modules=is_array($args['modules']??null)?$args['modules']:[];
		$modules=array_values(array_filter(array_map(static fn(mixed $module): string=>trim((string)$module),$modules)));
		if($modules===[]){throw new InvalidArgumentException('modules must name at least one owning runtime module for bounded contract discovery.');}
		$catalog=$this->contract_catalog_engine($modules)->catalog($args);
		$catalog['contract_safety']=$this->contract_discovery_safety('contract_catalog');
		$catalog['next_actions']=[
			'Describe one stable record with dataphyre_contract_describe before implementing or adapting it.',
			'Use dataphyre_unit_tests_list with the same modules and kind=code to find source files declaring executable TestKit evidence.',
			'Use the focused TestKit list/run command in runtime_metadata_command when expanded datasets or runtime-resolved versions are required.',
		];
		return $catalog;
	}

	/** @return array<string,mixed> */
	private function contract_describe(array $args): array {
		$modules=is_array($args['modules']??null)?$args['modules']:[];
		if($modules===[]){$modules=$this->contract_identity_modules((string)($args['id']??''));}
		if($modules===[]){throw new InvalidArgumentException('modules must provide an owning runtime module when the contract id does not encode one.');}
		$descriptor=$this->contract_catalog_engine($modules)->describe(
			(string)($args['id']??''),
			max(1,min((int)($args['max_evidence']??40)?:40,200))
		);
		$descriptor['contract_safety']=$this->contract_discovery_safety('contract_describe');
		return $descriptor;
	}

	/** @return array<string,mixed> */
	private function contract_resource_snapshot(): array {
		$scope=['mcp'];
		$catalog=$this->contract_catalog_engine($scope)->catalog(['modules'=>$scope,'limit'=>20,'include_evidence'=>false]);
		$availableModules=$this->contract_available_modules();
		return [
			'resource_type'=>'dataphyre_contract_index','contract_model_version'=>$catalog['contract_model_version'],
			'resource_mode'=>'bounded_bootstrap_partial','scope_modules'=>$scope,'available_modules'=>$availableModules,
			'write_policy'=>'read_only','execution'=>'not_executed','counts'=>$catalog['counts'],
			'kind_summary'=>$catalog['kind_summary'],'module_summary'=>$catalog['module_summary'],
			'version_health'=>$catalog['version_health'],'inventory_fingerprint'=>$catalog['inventory_fingerprint'],
			'sample_records'=>$catalog['records'],'diagnostics'=>$catalog['diagnostics'],
			'contract_safety'=>$this->contract_discovery_safety('contract_resource'),
			'full_catalog_tool'=>'dataphyre_contract_catalog','descriptor_tool'=>'dataphyre_contract_describe',
			'enumeration_contract'=>[
				'strategy'=>'module_federation','complete_when'=>'Call dataphyre_contract_catalog once for every available_modules entry.',
				'reason'=>'The resource stays cheap for every MCP client while module-scoped calls expose the complete live source graph.',
			],
		];
	}

	/** @return list<string> */
	private function contract_available_modules(): array {
		$root=$this->normalize_path($this->common_root.'/dataphyre/runtime/modules');
		$modules=[];
		foreach(glob($root.'/*',GLOB_ONLYDIR)?:[] as $path){$modules[]=basename($path);}
		sort($modules,SORT_STRING);
		return $modules;
	}

	/** @return array<string,mixed> */
	private function code_test_manifest_summary(string $relative,int $maxCases): array {
		$contractPath=$this->contract_relative_path($relative);
		$module=preg_match('#^runtime/modules/([^/]+)/#',$contractPath,$match)===1?$match[1]:'';
		if($module===''){throw new InvalidArgumentException('Code test paths must belong to runtime/modules/<module>/unit_tests.');}
		$file=$this->contract_catalog_engine([$module])->testFile($contractPath);
		$cases=is_array($file['cases']??null)?array_slice($file['cases'],0,$maxCases):[];
		return [
			'path'=>'dataphyre/'.(string)$file['path'],'module'=>$file['module'],'kind'=>'code',
			'valid_php'=>true,'suite_names'=>$file['suite_names'],'contract_count'=>count($file['contracts']),
			'contracts'=>$file['contracts'],'case_count'=>$file['declared_case_count'],'declared_case_count'=>$file['declared_case_count'],
			'expanded_case_count'=>null,'returned_cases'=>count($cases),'truncated'=>count($file['cases'])>$maxCases,'cases'=>$cases,
			'write_policy'=>'read_only','execution'=>'not_executed','runtime_metadata_command'=>$file['runtime_metadata_command'],
			'version_policy'=>'Literal versions are resolved statically; class-constant or computed versions retain version_expression until TestKit list resolves them.',
			'verification_safety'=>$this->verification_safety_contract('unit_test_manifest'),
		];
	}

	private function contract_catalog_engine(array $modules=[]): ContractCatalog {
		$root=$this->normalize_path($this->common_root.'/dataphyre');
		if($this->contract_catalog_root!==$root){
			$this->contract_catalog_cache=[];$this->contract_catalog_root=$root;
		}
		$modules=array_values(array_unique(array_filter(array_map(static fn(mixed $module): string=>trim((string)$module),$modules))));
		sort($modules,SORT_STRING);$key=implode(',',$modules);
		return $this->contract_catalog_cache[$key]??=new ContractCatalog(new SourceContractIndex($root,$modules));
	}

	/** @return list<string> */
	private function contract_identity_modules(string $identity): array {
		$identity=ltrim(trim($identity),'\\');
		if(preg_match('#^legacy:runtime/modules/([^/]+)/#',$identity,$match)===1){return [$match[1]];}
		if(preg_match('/^(?:test:)?([a-z][a-z0-9_-]*)\./i',$identity,$match)===1){return [strtolower($match[1])];}
		if(preg_match('/^serialized:([a-z][a-z0-9]*)_/i',$identity,$match)===1){return [strtolower($match[1])];}
		if(preg_match('/^php:Dataphyre\\\\([A-Za-z0-9_]+)/',$identity,$match)===1){return [strtolower($match[1])];}
		return [];
	}

	private function contract_relative_path(string $path): string {
		$path=ltrim(str_replace('\\','/',$path),'/');
		return str_starts_with($path,'dataphyre/')?substr($path,10):$path;
	}

	/** @return array<string,mixed> */
	private function contract_discovery_safety(string $surface): array {
		return [
			'surface'=>$surface,'classification'=>'read_only_source_contract_metadata','execution'=>'not_executed',
			'source_methods'=>['token_get_all','declarative JSON decode'],'source_required'=>false,'reflection_used'=>false,'eval_used'=>false,
			'not_performed'=>['test discovery execution','test helper execution','runtime bootstrap','route dispatch','SQL queries','storage access','network requests','source writes'],
			'version_boundary'=>'Computed PHP expressions remain explicit unresolved expressions; use TestKit list --cases --json for authoritative expanded runtime metadata.',
			'ordinary_app_use'=>'Inspect the exact interface, payload, and executable test contracts touched by an app-owned adapter before editing.',
			'framework_use'=>'Use inventory fingerprints and version conflicts as planning evidence, then prove behavior with focused module tests.',
		];
	}
}
