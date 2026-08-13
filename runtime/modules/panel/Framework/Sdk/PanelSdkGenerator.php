<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Deterministic multi-target SDK compiler. No filesystem or network effects occur during generation. */
final class PanelSdkGenerator implements \JsonSerializable {
	public const VERSION=1;

	/**
	 * @param array{targets?:list<string>,php_namespace?:string,php_class?:string,composer_package?:string,typescript_package?:string} $options
	 */
	public function generate(PanelSdkContract $contract,array $options=[]):PanelSdkPackage {
		$contract->assertReady();$unknown=array_diff(array_keys($options),['targets','php_namespace','php_class','composer_package','typescript_package']);if($unknown!==[]){throw new \InvalidArgumentException('Panel SDK generator options contain unsupported keys.');}
		$targets=PanelSdkGuard::names((array)($options['targets']??['php','typescript']),'SDK target',4);foreach($targets as$target){if(!in_array($target,['php','typescript'],true)){throw new \InvalidArgumentException("Panel SDK target '{$target}' is unsupported.");}}
		$segment=self::packageSegment($contract->id());$phpNamespace=PanelSdkGuard::phpNamespace((string)($options['php_namespace']??('DataphyreSdk\\'.self::studly($segment))));$phpClass=PanelSdkGuard::phpClass((string)($options['php_class']??'PanelClient'));
		$composer=PanelSdkGuard::packageId((string)($options['composer_package']??('dataphyre-sdk/'.$segment)));$typescript=PanelSdkGuard::packageId((string)($options['typescript_package']??('@dataphyre-sdk/'.$segment)));
		$files=['contract.json'=>PanelSdkGuard::canonicalJson($contract->jsonSerialize())];
		if(in_array('php',$targets,true)){foreach((new PanelSdkPhpGenerator())->files($contract,$phpNamespace,$phpClass,$composer)as$path=>$contents){$files['php/'.$path]=$contents;}}
		if(in_array('typescript',$targets,true)){foreach((new PanelSdkTypeScriptGenerator())->files($contract,$typescript)as$path=>$contents){$files['typescript/'.$path]=$contents;}}
		$manifest=[];foreach($files as$path=>$contents){$manifest[$path]=['bytes'=>strlen($contents),'sha256'=>hash('sha256',$contents)];}
		$files['sdk-manifest.json']=PanelSdkGuard::canonicalJson(['type'=>'panel_generated_sdk_manifest','version'=>self::VERSION,'contract'=>['id'=>$contract->id(),'version'=>$contract->version(),'fingerprint'=>$contract->fingerprint()],'generator'=>$this->jsonSerialize(),'targets'=>$targets,'files'=>$manifest,'credentials_embedded'=>false]);
		return PanelSdkPackage::make($contract,$files,$targets);
	}

	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_sdk_generator_manifest','version'=>self::VERSION,'targets'=>['php','typescript'],'contract_format'=>PanelSdkContract::FORMAT,'capabilities'=>['deterministic'=>true,'closed_schema_grammar'=>true,'request_validation'=>true,'response_validation'=>true,'typed_operations'=>true,'typed_events'=>true,'typed_studio_artifacts'=>true,'compatibility_reports'=>PanelSdkCompatibilityReport::class],'effects'=>['filesystem'=>false,'network'=>false],'security'=>['credentials_embedded'=>false,'host_transport_injected'=>true,'same_origin_routes_only'=>true]]);}

	private static function packageSegment(string $id):string {$segment=str_contains($id,'/')?substr($id,strrpos($id,'/')+1):$id;$segment=str_replace('_','-',$segment);return trim($segment,'-');}
	private static function studly(string $value):string {$parts=preg_split('/[^A-Za-z0-9]+/',$value,-1,PREG_SPLIT_NO_EMPTY)?:[];$value=implode('',array_map(static fn(string $part):string=>ucfirst($part),$parts));return$value!==''?$value:'Panel';}
}
