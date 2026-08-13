<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Runtime-only actual-builder index plus its immutable secret-free publication artifact. */
final class PanelStudioMaterialization implements \JsonSerializable {
	/** @var array<string,object|array> */ private readonly array $builders;
	/** @var array<string,string> */ private readonly array $symbols;
	/** @var array<string,list<string>> */ private readonly array $identityIndex;
	public function __construct(private readonly PanelStudioArtifact $artifact,array $builders,array $symbols,array $identityIndex){
		if(!$artifact->materializable()||!isset($builders['root'])||array_keys($builders)!==array_keys($symbols)||!$artifact->matchesBuilderContract($symbols)){throw new \InvalidArgumentException('Studio materialization builder contract does not match its artifact.');}
		foreach($builders as$path=>$builder){if(!self::builderValue($builder)){throw new \InvalidArgumentException("Studio materialization contains an invalid builder at {$path}.");}}
		foreach($identityIndex as$identity=>$paths){if(!is_string($identity)||preg_match('/^[a-z][a-z0-9_]*:[a-z][a-z0-9_.-]{0,127}$/',$identity)!==1||!is_array($paths)||!array_is_list($paths)){throw new \InvalidArgumentException('Studio materialization identity index is invalid.');}foreach($paths as$path){if(!is_string($path)||!isset($builders[$path])){throw new \InvalidArgumentException('Studio materialization identity paths are invalid.');}}sort($paths,SORT_STRING);$identityIndex[$identity]=$paths;}
		ksort($builders,SORT_STRING);ksort($symbols,SORT_STRING);ksort($identityIndex,SORT_STRING);$this->builders=$builders;$this->symbols=$symbols;$this->identityIndex=$identityIndex;
	}
	public function artifact():PanelStudioArtifact{return$this->artifact;}
	public function root():object|array{return$this->builders['root'];}
	public function builder(string $path):object|array|null{return$this->builders[$path]??null;}
	public function builders():array{return$this->builders;}
	/** @return list<string> */ public function paths(string $kind,string $key):array{return$this->identityIndex[$kind.':'.$key]??[];}
	public function manifest():array{$counts=[];$presentations=[];foreach($this->symbols as$path=>$symbol){$counts[$symbol]=($counts[$symbol]??0)+1;$builder=$this->builders[$path];if($builder instanceof PanelStudioBuilderCollection){$presentations[$path]=$builder->jsonSerialize();}}ksort($counts,SORT_STRING);ksort($presentations,SORT_STRING);return['type'=>'panel_studio_materialization_manifest','version'=>1,'artifact'=>$this->artifact->jsonSerialize(),'root_symbol'=>$this->symbols['root'],'builder_contract'=>$this->symbols,'builder_counts'=>$counts,'identity_index'=>$this->identityIndex,'collection_bindings'=>$presentations,'runtime'=>['actual_panel_builders'=>true,'objects_serialized'=>false,'callbacks'=>false,'user_classes'=>false,'raw_html'=>false]];}
	public function jsonSerialize():array{return$this->manifest();}
	private static function builderValue(mixed $value):bool{if(is_object($value)){return!$value instanceof \Closure;}if(!is_array($value)||!array_is_list($value)){return false;}foreach($value as$item){if(!is_object($item)||$item instanceof \Closure){return false;}}return true;}
}
