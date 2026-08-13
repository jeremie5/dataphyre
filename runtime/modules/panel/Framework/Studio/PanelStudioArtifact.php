<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Canonical non-executable artifact binding a definition to its trusted schema/materializer contract. */
final class PanelStudioArtifact implements \JsonSerializable {
	public const ARTIFACT_VERSION=1;
	public const MODES=['portable_blueprint','trusted_materialization'];
	private readonly string $fingerprint;
	private function __construct(
		private readonly string $mode,
		private readonly string $definitionHash,
		private readonly string $normalizedHash,
		private readonly ?string $registryVersion,
		private readonly ?string $registryFingerprint,
		private readonly int $compilerVersion,
		private readonly string $compilerFingerprint,
		private readonly int $materializerVersion,
		private readonly ?string $materializerFingerprint,
		private readonly ?string $builderContractHash,
		private readonly int $builderCount,
		?string $fingerprint=null
	){
		if(!in_array($mode,self::MODES,true)||preg_match('/^[a-f0-9]{64}$/',$definitionHash)!==1||preg_match('/^[a-f0-9]{64}$/',$normalizedHash)!==1||preg_match('/^[a-f0-9]{64}$/',$compilerFingerprint)!==1||$compilerVersion<1||$materializerVersion<0||$builderCount<0||$builderCount>PanelStudioDefinition::MAX_NODES){throw new \InvalidArgumentException('Studio artifact metadata is invalid.');}
		$trusted=$mode==='trusted_materialization';if($trusted!==($registryVersion!==null&&$registryFingerprint!==null&&$materializerFingerprint!==null&&$builderContractHash!==null&&$materializerVersion>0)){throw new \InvalidArgumentException('Studio artifact trusted contract metadata is incomplete.');}
		if($registryVersion!==null&&preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/',$registryVersion)!==1){throw new \InvalidArgumentException('Studio artifact registry version is invalid.');}
		foreach([$registryFingerprint,$materializerFingerprint,$builderContractHash]as$digest){if($digest!==null&&preg_match('/^[a-f0-9]{64}$/',$digest)!==1){throw new \InvalidArgumentException('Studio artifact contract digests are invalid.');}}
		if(!$trusted&&($builderCount!==0||$normalizedHash!==$definitionHash)){throw new \InvalidArgumentException('Portable Studio artifacts cannot claim trusted materialization output.');}
		$computed=self::digest($this->unsigned());if($fingerprint!==null&&!hash_equals($computed,$fingerprint)){throw new \UnexpectedValueException('Studio artifact fingerprint integrity check failed.');}$this->fingerprint=$computed;
	}
	public static function portable(PanelStudioDefinition $definition,?PanelStudioCompiler $compiler=null):self{$compiler??=new PanelStudioCompiler();return new self('portable_blueprint',$definition->hash(),$definition->hash(),null,null,PanelStudioCompiler::COMPILER_VERSION,$compiler->fingerprint(),0,null,null,0);}
	/** @param array<string,string> $builderSymbols */
	public static function trusted(PanelStudioDefinition $definition,PanelStudioDefinition $normalized,PanelStudioSchemaRegistry $registry,PanelStudioCompiler $compiler,PanelStudioMaterializer $materializer,array $builderSymbols):self{
		self::symbols($builderSymbols);return new self('trusted_materialization',$definition->hash(),$normalized->hash(),$registry->version(),$registry->fingerprint(),PanelStudioCompiler::COMPILER_VERSION,$compiler->fingerprint(),$materializer->version(),$materializer->fingerprint(),self::digest($builderSymbols),count($builderSymbols));
	}
	/** @param array<string,mixed> $payload */
	public static function hydrate(array $payload):self{
		$expected=['type','version','mode','definition_hash','normalized_hash','registry_version','registry_fingerprint','compiler_version','compiler_fingerprint','materializer_version','materializer_fingerprint','builder_contract_hash','builder_count','materializable','fingerprint'];$keys=array_keys($payload);sort($keys,SORT_STRING);sort($expected,SORT_STRING);$nullableString=static fn(string $key):bool=>is_string($payload[$key])||$payload[$key]===null;if($keys!==$expected||$payload['type']!=='panel_studio_artifact'||$payload['version']!==self::ARTIFACT_VERSION||!is_string($payload['mode'])||!is_string($payload['definition_hash'])||!is_string($payload['normalized_hash'])||!$nullableString('registry_version')||!$nullableString('registry_fingerprint')||!is_int($payload['compiler_version'])||!is_string($payload['compiler_fingerprint'])||!is_int($payload['materializer_version'])||!$nullableString('materializer_fingerprint')||!$nullableString('builder_contract_hash')||!is_int($payload['builder_count'])||!is_bool($payload['materializable'])||!is_string($payload['fingerprint'])||$payload['materializable']!==($payload['mode']==='trusted_materialization')){throw new \UnexpectedValueException('Stored Studio artifact shape is invalid.');}
		return new self($payload['mode'],$payload['definition_hash'],$payload['normalized_hash'],$payload['registry_version'],$payload['registry_fingerprint'],$payload['compiler_version'],$payload['compiler_fingerprint'],$payload['materializer_version'],$payload['materializer_fingerprint'],$payload['builder_contract_hash'],$payload['builder_count'],$payload['fingerprint']);
	}
	public function mode():string{return$this->mode;}
	public function materializable():bool{return$this->mode==='trusted_materialization';}
	public function definitionHash():string{return$this->definitionHash;}
	public function normalizedHash():string{return$this->normalizedHash;}
	public function registryVersion():?string{return$this->registryVersion;}
	public function registryFingerprint():?string{return$this->registryFingerprint;}
	public function compilerVersion():int{return$this->compilerVersion;}
	public function compilerFingerprint():string{return$this->compilerFingerprint;}
	public function materializerVersion():int{return$this->materializerVersion;}
	public function materializerFingerprint():?string{return$this->materializerFingerprint;}
	public function builderContractHash():?string{return$this->builderContractHash;}
	public function builderCount():int{return$this->builderCount;}
	public function fingerprint():string{return$this->fingerprint;}
	public function assertDefinition(PanelStudioDefinition $definition):void{if(!hash_equals($this->definitionHash,$definition->hash())){throw new \UnexpectedValueException('Studio artifact does not bind the supplied definition.');}}
	/** @param array<string,string> $builderSymbols */ public function matchesBuilderContract(array $builderSymbols):bool{self::symbols($builderSymbols);return$this->builderContractHash!==null&&$this->builderCount===count($builderSymbols)&&hash_equals($this->builderContractHash,self::digest($builderSymbols));}
	/** @return list<PanelStudioDiagnostic> */
	public function compatibilityDiagnostics(PanelStudioSchemaRegistry $registry,PanelStudioMaterializer $materializer,?PanelStudioCompiler $compiler=null):array{
		$compiler??=new PanelStudioCompiler();$diagnostics=[];if(!$this->materializable()){$diagnostics[]=new PanelStudioDiagnostic('artifact.mode','artifact_not_materializable','Studio artifact contains only a portable blueprint and has no trusted materialization contract.');return$diagnostics;}
		if($this->registryVersion!==$registry->version()){$diagnostics[]=new PanelStudioDiagnostic('artifact.registry_version','registry_version_mismatch','Studio artifact registry version does not match the active trusted registry; an explicit migration is required.');}
		if(!hash_equals((string)$this->registryFingerprint,$registry->fingerprint())){$diagnostics[]=new PanelStudioDiagnostic('artifact.registry_fingerprint','stale_registry','Studio artifact was approved against a different trusted registry fingerprint; an explicit migration is required.');}
		if($this->compilerVersion!==PanelStudioCompiler::COMPILER_VERSION){$diagnostics[]=new PanelStudioDiagnostic('artifact.compiler_version','compiler_version_mismatch','Studio artifact compiler version does not match the active compiler.');}
		if(!hash_equals($this->compilerFingerprint,$compiler->fingerprint())){$diagnostics[]=new PanelStudioDiagnostic('artifact.compiler_fingerprint','compiler_fingerprint_mismatch','Studio artifact compiler fingerprint does not match the active compiler.');}
		if($this->materializerVersion!==$materializer->version()){$diagnostics[]=new PanelStudioDiagnostic('artifact.materializer_version','materializer_version_mismatch','Studio artifact materializer version does not match the active materializer.');}
		if(!hash_equals((string)$this->materializerFingerprint,$materializer->fingerprint())){$diagnostics[]=new PanelStudioDiagnostic('artifact.materializer_fingerprint','materializer_fingerprint_mismatch','Studio artifact materializer fingerprint does not match the active audited materializer.');}
		return$diagnostics;
	}
	public function assertCompatible(PanelStudioSchemaRegistry $registry,PanelStudioMaterializer $materializer,?PanelStudioCompiler $compiler=null):void{$diagnostics=$this->compatibilityDiagnostics($registry,$materializer,$compiler);if($diagnostics!==[]){throw new PanelStudioSchemaException('Studio artifact is stale or requires an explicit schema migration.',$diagnostics);}}
	public function jsonSerialize():array{return$this->unsigned()+['fingerprint'=>$this->fingerprint];}
	private function unsigned():array{return['type'=>'panel_studio_artifact','version'=>self::ARTIFACT_VERSION,'mode'=>$this->mode,'definition_hash'=>$this->definitionHash,'normalized_hash'=>$this->normalizedHash,'registry_version'=>$this->registryVersion,'registry_fingerprint'=>$this->registryFingerprint,'compiler_version'=>$this->compilerVersion,'compiler_fingerprint'=>$this->compilerFingerprint,'materializer_version'=>$this->materializerVersion,'materializer_fingerprint'=>$this->materializerFingerprint,'builder_contract_hash'=>$this->builderContractHash,'builder_count'=>$this->builderCount,'materializable'=>$this->materializable()];}
	/** @param array<string,string> $symbols */ private static function symbols(array &$symbols):void{foreach($symbols as$path=>$symbol){if(!is_string($path)||preg_match('/^root(?:\.children\[\d+\])*$/',$path)!==1||!is_string($symbol)||preg_match('/^[a-z][a-z0-9_]{1,63}$/',$symbol)!==1){throw new \InvalidArgumentException('Studio builder contracts require deterministic paths and symbolic builder names.');}}ksort($symbols,SORT_STRING);}
	private static function digest(array $value):string{self::sort($value);return hash('sha256',json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));}
	private static function sort(array &$value):void{if(!array_is_list($value)){ksort($value,SORT_STRING);}foreach($value as&$item){if(is_array($item)){self::sort($item);}}}
}
