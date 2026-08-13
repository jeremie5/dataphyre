<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Unified deny-by-default policy runtime with signed bundles and kill switches. */
final class PanelPolicyControlPlane implements \JsonSerializable {
	/** @var array<string,string> */ private array $keys;
	/** @var array<string,PanelPolicyBundle> */ private array $bundles=[];
	/** @var list<string> */ private array $killSwitches=[];
	private int $revision=0;

	/** @param array<string,string> $trustedKeys */
	public function __construct(array $trustedKeys=[],private readonly bool $requireSigned=true,private readonly bool $defaultAllow=false){$keys=[];foreach($trustedKeys as$id=>$key){$id=PanelOperationsGuard::name((string)$id,'policy key id');if(!is_string($key)||strlen($key)<32){throw new \InvalidArgumentException('Policy trust keys require at least 32 bytes.');}$keys[$id]=$key;}$this->keys=$keys;if($defaultAllow){throw new \InvalidArgumentException('The unified Panel policy control plane cannot default allow.');}}

	public function register(PanelPolicyBundle $bundle,bool $replace=false):self {if($this->requireSigned&&!$bundle->verify($this->keys)){throw new \LogicException('Policy bundle signature is not trusted.');}if(!$replace&&isset($this->bundles[$bundle->id()])){throw new \LogicException('Policy bundle is already registered.');}$this->bundles[$bundle->id()]=$bundle;ksort($this->bundles,SORT_STRING);$this->revision++;return$this;}
	public function remove(string $bundleId):self {$bundleId=PanelOperationsGuard::name($bundleId,'policy bundle id');if(isset($this->bundles[$bundleId])){unset($this->bundles[$bundleId]);$this->revision++;}return$this;}
	public function engage(string $abilityPattern):self {$abilityPattern=strtolower(trim($abilityPattern));if($abilityPattern!=='*'&&preg_match('/^[a-z][a-z0-9_.:-]*(?:\.\*)?$/D',$abilityPattern)!==1){throw new \InvalidArgumentException('Policy kill-switch pattern is invalid.');}if(!in_array($abilityPattern,$this->killSwitches,true)){$this->killSwitches[]=$abilityPattern;sort($this->killSwitches,SORT_STRING);$this->revision++;}return$this;}
	public function release(string $abilityPattern):self {$index=array_search(strtolower(trim($abilityPattern)),$this->killSwitches,true);if($index!==false){array_splice($this->killSwitches,$index,1);$this->revision++;}return$this;}
	public function revision():int{return$this->revision;}

	public function evaluate(PanelPolicyRequest|array $request):PanelPolicyDecision {
		$request=$request instanceof PanelPolicyRequest?$request:PanelPolicyRequest::from($request);$trace=[];$matched=[];$reasons=[];$obligations=[];$allowed=false;$denied=false;
		foreach($this->killSwitches as$pattern){if(PanelOperationsGuard::abilityMatches($pattern,$request->ability())){$trace[]=['source'=>'kill_switch','id'=>$pattern,'effect'=>'deny','matched'=>true];return new PanelPolicyDecision(false,$request->fingerprint(),['kill_switch:'.$pattern],['The operation is disabled by an active kill switch.'],[],array_values($trace),$this->revision);}}
		$rules=[];foreach($this->bundles as$bundle){foreach($bundle->rules()as$rule){$rules[]=['bundle'=>$bundle->id(),'rule'=>$rule];}}usort($rules,static fn(array $a,array $b):int=>[$b['rule']->priority(),$a['bundle'],$a['rule']->id()]<=>[$a['rule']->priority(),$b['bundle'],$b['rule']->id()]);
		foreach($rules as$entry){/** @var PanelPolicyRule $rule */$rule=$entry['rule'];$isMatch=$rule->matches($request);$trace[]=['source'=>'bundle','bundle'=>$entry['bundle'],'id'=>$rule->id(),'effect'=>$rule->effect(),'priority'=>$rule->priority(),'matched'=>$isMatch];if(!$isMatch){continue;}$id=$entry['bundle'].':'.$rule->id();$matched[]=$id;$reasons[]=$rule->reason();$obligations=$this->mergeObligations($obligations,$rule->obligations());if($rule->effect()==='deny'){$denied=true;}else{$allowed=true;}}
		if($matched===[]){$reasons[]='No policy rule allowed the operation.';}if($denied){$reasons[]='A matching deny rule overrides allow rules.';}$result=$allowed&&!$denied;return new PanelPolicyDecision($result,$request->fingerprint(),array_values(array_unique($matched)),array_values(array_unique($reasons)),PanelOperationsGuard::canonical($obligations),$trace,$this->revision);
	}

	/** @return array<string,mixed> */ public function checkpoint():array{return['type'=>'panel_policy_control_plane_checkpoint','version'=>1,'revision'=>$this->revision,'bundles'=>array_map(static fn(PanelPolicyBundle $bundle):array=>$bundle->jsonSerialize(),$this->bundles),'kill_switches'=>$this->killSwitches,'require_signed'=>$this->requireSigned];}
	/** @param array<string,mixed> $checkpoint */ public function restore(array $checkpoint):self {if(($checkpoint['type']??null)!=='panel_policy_control_plane_checkpoint'||($checkpoint['version']??null)!==1||!is_int($checkpoint['revision']??null)||$checkpoint['revision']<0||!is_array($checkpoint['bundles']??null)||!is_array($checkpoint['kill_switches']??null)||($checkpoint['require_signed']??null)!==$this->requireSigned){throw new \UnexpectedValueException('Policy control-plane checkpoint is invalid.');}$bundles=[];foreach($checkpoint['bundles']as$id=>$payload){if(!is_string($id)||!is_array($payload)){throw new \UnexpectedValueException('Policy checkpoint bundle is invalid.');}$bundle=PanelPolicyBundle::hydrate($payload);if($bundle->id()!==$id||($this->requireSigned&&!$bundle->verify($this->keys))){throw new \UnexpectedValueException('Policy checkpoint bundle is untrusted.');}$bundles[$id]=$bundle;}$switches=PanelOperationsGuard::abilityPatterns($checkpoint['kill_switches'],'policy kill switch');$this->bundles=$bundles;$this->killSwitches=$switches;$this->revision=$checkpoint['revision'];return$this;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_policy_control_plane_manifest','version'=>1,'revision'=>$this->revision,'bundle_count'=>count($this->bundles),'bundle_digests'=>array_map(static fn(PanelPolicyBundle $bundle):string=>$bundle->digest(),$this->bundles),'kill_switches'=>$this->killSwitches,'trusted_key_ids'=>array_keys($this->keys),'require_signed'=>$this->requireSigned,'default_deny'=>true,'deny_overrides'=>true,'portable_conditions'=>true,'explainable'=>true]);}

	/** @param array<string,mixed> $current @param array<string,mixed> $incoming @return array<string,mixed> */
	private function mergeObligations(array $current,array $incoming):array {foreach($incoming as$key=>$value){if(in_array($key,['approval_count','mfa_level'],true)){$current[$key]=max((int)($current[$key]??0),(int)$value);continue;}if($key==='max_cost_micros'){$incomingLimit=max(0,(int)$value);$currentLimit=max(0,(int)($current[$key]??0));$current[$key]=$currentLimit===0?$incomingLimit:($incomingLimit===0?$currentLimit:min($currentLimit,$incomingLimit));continue;}if(in_array($key,['confirmation','separation_of_duties','dry_run'],true)){$current[$key]=($current[$key]??false)===true||$value===true;continue;}if(is_array($value)){$existing=is_array($current[$key]??null)?$current[$key]:[];$current[$key]=array_values(array_unique(array_merge($existing,$value),SORT_REGULAR));continue;}if(!array_key_exists($key,$current)){$current[$key]=$value;}elseif($current[$key]!==$value){$current[$key]=array_values(array_unique([$current[$key],$value],SORT_REGULAR));}}return$current;}
}
