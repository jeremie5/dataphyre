<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Typed runtime binding for builder collections whose presentation is part of the trusted definition. */
final class PanelStudioBuilderCollection implements \JsonSerializable {
	/** @var list<object> */ private readonly array $items;
	/** @var array<string,mixed> */ private readonly array $presentation;
	public function __construct(private readonly string $kind,private readonly string $key,array $items,array|string $presentation='stack',?int $columns=null){
		if(!in_array($kind,['filters','table_views','widget_grid','navigation'],true)||preg_match('/^[a-z][a-z0-9_.-]{0,127}$/',$key)!==1){throw new \InvalidArgumentException('Studio builder collection identity is invalid.');}if(count($items)>PanelStudioDefinition::MAX_NODES){throw new \LengthException('Studio builder collection exceeds its item budget.');}foreach($items as$item){if(!is_object($item)||$item instanceof \Closure){throw new \InvalidArgumentException('Studio builder collections can contain only actual non-closure Panel builders.');}}$this->items=array_values($items);$definition=is_string($presentation)?['display'=>$presentation]:$presentation;if($columns!==null){$definition['columns']=$columns;}$this->presentation=PanelCollectionPresentation::normalize($definition,self::defaultDisplay($kind));
	}
	public function kind():string{return$this->kind;}
	public function key():string{return$this->key;}
	public function items():array{return$this->items;}
	public function presentation():array{return$this->presentation;}
	public function display():string{return(string)$this->presentation['display'];}
	public function jsonSerialize():array{return['type'=>'panel_studio_builder_collection','version'=>1,'kind'=>$this->kind,'key'=>$this->key,'item_count'=>count($this->items),'presentation'=>$this->presentation,'runtime'=>['actual_panel_builders'=>true,'objects_serialized'=>false]];}
	private static function defaultDisplay(string $kind):string{return match($kind){'filters','widget_grid'=>'grid','table_views'=>'segmented','navigation'=>'stack'};}
}
