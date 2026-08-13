<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Typed semantic roles and interaction policy for advanced DataSurface canvases. */
final class PanelDataCanvasSpec implements \JsonSerializable {
	private const ADVANCED=['spreadsheet','pivot','tree','graph','gantt','heatmap','map','canvas'];
	private const ROLES=['title','description','parent','source','target','row','column','value','group','start','end','progress','latitude','longitude','x','y','width','height','color','cross_filter'];
	private const AGGREGATES=['count','sum','average','minimum','maximum','first'];
	private const SELECTIONS=['none','single','multiple'];
	/** @var array<string,list<string>> */
	private const REQUIRED=[
		'pivot'=>['row','column','value'],'tree'=>['title','parent'],'graph'=>['source','target'],
		'gantt'=>['title','start','end'],'heatmap'=>['row','column','value'],
		'map'=>['title','latitude','longitude'],'canvas'=>['title','x','y'],
	];

	/** @param array<string,string> $roles */
	private function __construct(
		private readonly PanelDataSurfaceType $surface,
		private readonly array $roles,
		private readonly string $aggregate,
		private readonly string $selection,
		private readonly ?string $crossFilterGroup,
		private readonly ?string $crossFilterField,
		private readonly ?string $drillUrl,
		private readonly string $drillParameter,
		private readonly bool $editable,
		private readonly int $frozenFields,
		private readonly bool $showLabels,
		private readonly bool $showLegend,
		private readonly bool $snapToGrid,
		private readonly bool $zoom,
	){}

	/** @param array<string,mixed> $options */
	public static function make(PanelDataSurfaceType|string $surface,PanelDataSurfaceProjection $projection,array $options=[]):self {
		$surface=PanelDataSurfaceType::normalize($surface);
		if($options!==[]&&array_is_list($options)){throw new \InvalidArgumentException('Panel DataCanvas options must be object-like.');}
		$allowed=['roles','aggregate','selection','cross_filter_group','cross_filter_field','drill_url','drill_parameter','editable','frozen_fields','show_labels','show_legend','snap_to_grid','zoom'];
		$unknown=array_values(array_diff(array_keys($options),$allowed));if($unknown!==[]){throw new \InvalidArgumentException('Panel DataCanvas options contain unsupported keys: '.implode(', ',array_map('strval',$unknown)).'.');}
		$roles=$projection->slots();$configured=$options['roles']??[];if(!is_array($configured)||($configured!==[]&&array_is_list($configured))){throw new \InvalidArgumentException('Panel DataCanvas roles must be object-like.');}
		foreach($configured as$role=>$field){$role=strtolower(trim((string)$role));if(!in_array($role,self::ROLES,true)){throw new \InvalidArgumentException("Unsupported Panel DataCanvas role '{$role}'.");}$field=PanelDataSurfaceGuard::field((string)$field);if(!in_array($field,$projection->fields(),true)){throw new \InvalidArgumentException("Panel DataCanvas role '{$role}' references an unprojected field.");}$roles[$role]=$field;}
		$roles=array_intersect_key($roles,array_fill_keys(self::ROLES,true));ksort($roles,SORT_STRING);
		foreach(self::REQUIRED[$surface->value]??[]as$role){if(!isset($roles[$role])){throw new \InvalidArgumentException("Panel DataCanvas {$surface->value} surfaces require the '{$role}' semantic role.");}}
		$aggregate=strtolower(trim((string)($options['aggregate']??'sum')));if(!in_array($aggregate,self::AGGREGATES,true)){throw new \InvalidArgumentException('Panel DataCanvas aggregate is invalid.');}
		$selection=strtolower(trim((string)($options['selection']??'none')));if(!in_array($selection,self::SELECTIONS,true)){throw new \InvalidArgumentException('Panel DataCanvas selection mode is invalid.');}
		$group=null;if(array_key_exists('cross_filter_group',$options)&&$options['cross_filter_group']!==null&&trim((string)$options['cross_filter_group'])!==''){$group=PanelDataSurfaceGuard::identifier((string)$options['cross_filter_group'],'cross-filter group',96);}
		$filterField=$options['cross_filter_field']??($group!==null?($roles['cross_filter']??null):null);if($filterField!==null&&trim((string)$filterField)!==''){$filterField=PanelDataSurfaceGuard::field((string)$filterField);if(!in_array($filterField,$projection->fields(),true)){throw new \InvalidArgumentException('Panel DataCanvas cross-filter field must be projected.');}}else{$filterField=null;}
		if(($group===null)!==($filterField===null)){throw new \InvalidArgumentException('Panel DataCanvas cross-filter group and field must be configured together.');}
		if($group!==null&&$selection==='none'){throw new \InvalidArgumentException('Panel DataCanvas cross-filtering requires single or multiple selection.');}
		$drillUrl=self::url($options['drill_url']??null);$drillParameter=strtolower(trim((string)($options['drill_parameter']??'record')));if(preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$drillParameter)!==1){throw new \InvalidArgumentException('Panel DataCanvas drill parameter is invalid.');}
		$editable=($options['editable']??false)===true;if($editable&&!in_array($surface->value,['spreadsheet','canvas'],true)){throw new \InvalidArgumentException('Panel DataCanvas editing is only available to spreadsheet and freeform canvas surfaces.');}
		$frozen=(int)($options['frozen_fields']??0);if($frozen<0||$frozen>min(16,count($projection->fields()))){throw new \InvalidArgumentException('Panel DataCanvas frozen field count is invalid.');}
		return new self($surface,$roles,$aggregate,$selection,$group,$filterField,$drillUrl,$drillParameter,$editable,$frozen,($options['show_labels']??true)!==false,($options['show_legend']??true)!==false,($options['snap_to_grid']??false)===true,($options['zoom']??in_array($surface->value,['graph','map','canvas'],true))===true);
	}

	public static function advanced(PanelDataSurfaceType|string $surface):bool{return in_array(PanelDataSurfaceType::normalize($surface)->value,self::ADVANCED,true);}
	public function surface():PanelDataSurfaceType{return$this->surface;}
	/** @return array<string,string> */public function roles():array{return$this->roles;}
	public function role(string $name):?string{return$this->roles[strtolower(trim($name))]??null;}
	public function aggregate():string{return$this->aggregate;}
	public function selection():string{return$this->selection;}
	public function crossFilterGroup():?string{return$this->crossFilterGroup;}
	public function crossFilterField():?string{return$this->crossFilterField;}
	public function drillUrl():?string{return$this->drillUrl;}
	public function drillParameter():string{return$this->drillParameter;}
	public function editable():bool{return$this->editable;}
	public function frozenFields():int{return$this->frozenFields;}
	public function showLabels():bool{return$this->showLabels;}
	public function showLegend():bool{return$this->showLegend;}
	public function snapToGrid():bool{return$this->snapToGrid;}
	public function zoom():bool{return$this->zoom;}
	public function crossFilterEnabled():bool{return$this->crossFilterGroup!==null&&$this->crossFilterField!==null;}

	/** @param list<mixed> $values */
	public function applyCrossFilter(PanelDataQuery $query,array $values):PanelDataQuery {
		if(!$this->crossFilterEnabled()){throw new \LogicException('Panel DataCanvas cross-filtering is not configured.');}
		$values=PanelDataSurfaceInteraction::normalizeValues($values);if($values===[]){return$query;}
		return count($values)===1?$query->where((string)$this->crossFilterField,$values[0]):$query->where((string)$this->crossFilterField,'in',$values);
	}

	public function fingerprint():string{return hash('sha256',PanelDataSurfaceGuard::canonicalJson($this->jsonSerialize()));}
	public function jsonSerialize():array{return[
		'type'=>'panel_data_canvas_spec','version'=>1,'surface'=>$this->surface->value,'roles'=>$this->roles,'aggregate'=>$this->aggregate,
		'interaction'=>['selection'=>$this->selection,'cross_filter_group'=>$this->crossFilterGroup,'cross_filter_field'=>$this->crossFilterField,'drill_url'=>$this->drillUrl,'drill_parameter'=>$this->drillParameter,'editable'=>$this->editable],
		'presentation'=>['frozen_fields'=>$this->frozenFields,'show_labels'=>$this->showLabels,'show_legend'=>$this->showLegend,'snap_to_grid'=>$this->snapToGrid,'zoom'=>$this->zoom],
		'capabilities'=>['accessible_ssr'=>true,'progressive_enhancement'=>true,'signed_server_cross_filter'=>$this->crossFilterEnabled(),'drill_through'=>$this->drillUrl!==null,'keyboard_selection'=>$this->selection!=='none','mutation_protocol_required'=>$this->editable],
	];}

	private static function url(mixed $value):?string {
		if($value===null||trim((string)$value)===''){return null;}$value=trim((string)$value);
		if(strlen($value)>2048||!str_starts_with($value,'/')||str_starts_with($value,'//')||str_contains($value,'\\')||preg_match('/[\x00-\x20\x7F]/',$value)===1){throw new \InvalidArgumentException('Panel DataCanvas drill URL must be a safe application-relative path.');}
		return$value;
	}
}
