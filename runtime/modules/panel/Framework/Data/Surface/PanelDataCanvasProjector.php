<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Deterministic, side-effect-free semantic projection for every advanced DataCanvas. */
final class PanelDataCanvasProjector {
	/** @param list<array{key:string,position:int,visible:bool,data:array<string,mixed>}> $records */
	public function project(PanelDataSurfaceDefinition $definition,array $records):PanelDataCanvasModel {
		$spec=$definition->canvas();
		if(!$spec instanceof PanelDataCanvasSpec||!$definition->surface()->advanced()||$spec->surface()!==$definition->surface()){throw new \InvalidArgumentException('Panel DataCanvas projection requires a matching advanced surface definition.');}
		if(!array_is_list($records)||count($records)>PanelDataSurfaceRange::MAX_FETCH){throw new \InvalidArgumentException('Panel DataCanvas records are invalid.');}
		foreach($records as$record){if(!is_array($record)||array_keys($record)!==['key','position','visible','data']||!is_string($record['key'])||!is_int($record['position'])||!is_bool($record['visible'])||!is_array($record['data'])||array_is_list($record['data'])){throw new \InvalidArgumentException('Panel DataCanvas record is malformed.');}}
		$diagnostics=[];
		$model=match($definition->surface()){
			PanelDataSurfaceType::SPREADSHEET=>$this->spreadsheet($definition,$records),
			PanelDataSurfaceType::PIVOT,PanelDataSurfaceType::HEATMAP=>$this->matrix($spec,$records,$diagnostics),
			PanelDataSurfaceType::TREE=>$this->tree($spec,$records,$diagnostics),
			PanelDataSurfaceType::GRAPH=>$this->graph($spec,$records,$diagnostics),
			PanelDataSurfaceType::GANTT=>$this->gantt($spec,$records,$diagnostics),
			PanelDataSurfaceType::MAP=>$this->map($spec,$records,$diagnostics),
			PanelDataSurfaceType::CANVAS=>$this->canvas($spec,$records,$diagnostics),
		};
		ksort($diagnostics,SORT_STRING);$entries=[];foreach($diagnostics as$code=>$count){if($count>0){$entries[]=['code'=>$code,'count'=>$count];}}
		return new PanelDataCanvasModel($definition->surface(),count($records),$model,$entries);
	}

	/** @param list<array{key:string,position:int,visible:bool,data:array<string,mixed>}> $records @return array<string,mixed> */
	private function spreadsheet(PanelDataSurfaceDefinition $definition,array $records):array {
		$projection=$definition->projection();$spec=$definition->canvas();$fields=[];
		foreach($projection->fields()as$index=>$field){$fields[]=['name'=>$field,'label'=>$projection->label($field),'frozen'=>$index<($spec?->frozenFields()??0)];}
		$rows=[];foreach($records as$record){$rows[]=['key'=>$record['key'],'position'=>$record['position'],'visible'=>$record['visible'],'filter_values'=>$this->filterValues($spec,[$record])];}
		return['fields'=>$fields,'rows'=>$rows,'editable'=>$spec?->editable()??false,'frozen_fields'=>$spec?->frozenFields()??0];
	}

	/** @param list<array{key:string,position:int,visible:bool,data:array<string,mixed>}> $records @param array<string,int> $diagnostics @return array<string,mixed> */
	private function matrix(PanelDataCanvasSpec $spec,array $records,array &$diagnostics):array {
		$rowField=(string)$spec->role('row');$columnField=(string)$spec->role('column');$valueField=(string)$spec->role('value');$groups=[];$rows=[];$columns=[];
		foreach($records as$record){
			$row=$this->category($record['data'][$rowField]??null);$column=$this->category($record['data'][$columnField]??null);
			if($row===null||$column===null){$this->diagnostic($diagnostics,'invalid_category');continue;}
			$rows[$row['key']]=$row;$columns[$column['key']]=$column;$cellKey=$row['key']."\0".$column['key'];$groups[$cellKey]??=['row_key'=>$row['key'],'column_key'=>$column['key'],'values'=>[],'first'=>null,'first_set'=>false,'count'=>0,'invalid'=>0,'record_keys'=>[],'filter_values'=>[]];$cell=&$groups[$cellKey];$cell['count']++;$cell['record_keys'][]=$record['key'];
			$value=$record['data'][$valueField]??null;if(!$cell['first_set']&&$this->scalar($value)){$cell['first']=$value;$cell['first_set']=true;}$number=$this->number($value);if($number===null){$cell['invalid']++;}else{$cell['values'][]=$number;}
			$cell['filter_values']=$this->mergeValues($cell['filter_values'],$this->filterValues($spec,[$record]),$diagnostics);unset($cell);
		}
		$rows=array_values($rows);$columns=array_values($columns);$sort=static fn(array$a,array$b):int=>[$a['label'],$a['key']]<=>[$b['label'],$b['key']];usort($rows,$sort);usort($columns,$sort);
		$cells=[];$numeric=[];foreach($groups as$cell){$aggregate=$this->aggregate($spec->aggregate(),$cell);if(is_int($aggregate)||is_float($aggregate)){$numeric[]=(float)$aggregate;}$cells[]=['row_key'=>$cell['row_key'],'column_key'=>$cell['column_key'],'value'=>$aggregate,'count'=>$cell['count'],'invalid_values'=>$cell['invalid'],'record_key'=>$cell['record_keys'][0]??null,'filter_values'=>$cell['filter_values'],'intensity'=>0.0];}
		$minimum=$numeric===[]?null:min($numeric);$maximum=$numeric===[]?null:max($numeric);foreach($cells as&$cell){if(($number=$this->number($cell['value']))!==null){$cell['intensity']=$minimum===$maximum?1.0:round(($number-(float)$minimum)/((float)$maximum-(float)$minimum),6);}}unset($cell);
		usort($cells,static fn(array$a,array$b):int=>[$a['row_key'],$a['column_key']]<=>[$b['row_key'],$b['column_key']]);
		return['rows'=>$rows,'columns'=>$columns,'cells'=>$cells,'aggregate'=>$spec->aggregate(),'minimum'=>$minimum,'maximum'=>$maximum];
	}

	/** @param list<array{key:string,position:int,visible:bool,data:array<string,mixed>}> $records @param array<string,int> $diagnostics @return array<string,mixed> */
	private function tree(PanelDataCanvasSpec $spec,array $records,array &$diagnostics):array {
		$titleField=(string)$spec->role('title');$parentField=(string)$spec->role('parent');$nodes=[];$parents=[];
		foreach($records as$record){$parent=$record['data'][$parentField]??null;if($parent===null||$parent===''){$parent=null;}elseif(is_int($parent)||is_string($parent)){$parent=(string)$parent;}else{$parent=null;$this->diagnostic($diagnostics,'invalid_parent');}$parents[$record['key']]=$parent;$nodes[$record['key']]=['key'=>$record['key'],'title'=>$this->display($record['data'][$titleField]??$record['key']),'description'=>$this->roleDisplay($spec,$record,'description'),'parent'=>$parent,'depth'=>0,'orphan'=>false,'cycle'=>false,'position'=>$record['position'],'visible'=>$record['visible'],'filter_values'=>$this->filterValues($spec,[$record])];}
		$cycles=[];foreach(array_keys($parents)as$rawKey){$key=(string)$rawKey;$path=[];$indexes=[];$current=$key;while($current!==null&&isset($parents[$current])){if(isset($indexes[$current])){foreach(array_slice($path,$indexes[$current])as$member){$cycles[$member]=true;}break;}$indexes[$current]=count($path);$path[]=$current;$current=$parents[$current];}}
		$depths=[];$orphans=[];$resolve=function(string$key,array$trail=[])use(&$resolve,&$depths,&$orphans,$parents,$cycles):int{if(isset($depths[$key])){return$depths[$key];}if(isset($cycles[$key])||isset($trail[$key])){return$depths[$key]=0;}$parent=$parents[$key]??null;if($parent===null){return$depths[$key]=0;}if(!array_key_exists($parent,$parents)){$orphans[$key]=true;return$depths[$key]=0;}$trail[$key]=true;$depth=$resolve($parent,$trail);if(isset($orphans[$parent])){$orphans[$key]=true;}return$depths[$key]=min(64,$depth+1);};
		foreach(array_keys($nodes)as$rawKey){$key=(string)$rawKey;$nodes[$key]['depth']=$resolve($key);$nodes[$key]['orphan']=isset($orphans[$key]);$nodes[$key]['cycle']=isset($cycles[$key]);}
		if($orphans!==[]){$diagnostics['missing_parent']=count($orphans);}if($cycles!==[]){$diagnostics['cycle']=count($cycles);}
		$list=array_values($nodes);usort($list,static fn(array$a,array$b):int=>[$a['position'],$a['key']]<=>[$b['position'],$b['key']]);$roots=[];foreach($list as$node){if($node['parent']===null||$node['orphan']||$node['cycle']){$roots[]=$node['key'];}}
		return['nodes'=>$list,'roots'=>$roots,'maximum_depth'=>$depths===[]?0:max($depths)];
	}

	/** @param list<array{key:string,position:int,visible:bool,data:array<string,mixed>}> $records @param array<string,int> $diagnostics @return array<string,mixed> */
	private function graph(PanelDataCanvasSpec $spec,array $records,array &$diagnostics):array {
		$sourceField=(string)$spec->role('source');$targetField=(string)$spec->role('target');$nodes=[];$edges=[];
		foreach($records as$record){$source=$this->category($record['data'][$sourceField]??null);$target=$this->category($record['data'][$targetField]??null);if($source===null||$target===null){$this->diagnostic($diagnostics,'invalid_edge');continue;}
			foreach([$source,$target]as$node){$nodes[$node['key']]??=['key'=>$node['key'],'label'=>$node['label'],'in_degree'=>0,'out_degree'=>0,'x'=>0.0,'y'=>0.0];}$nodes[$source['key']]['out_degree']++;$nodes[$target['key']]['in_degree']++;
			$edges[]=['key'=>$record['key'],'source'=>$source['key'],'target'=>$target['key'],'label'=>$this->roleDisplay($spec,$record,'title')??$source['label'].' → '.$target['label'],'filter_values'=>$this->filterValues($spec,[$record])];
		}
		$nodes=array_values($nodes);usort($nodes,static fn(array$a,array$b):int=>[$a['label'],$a['key']]<=>[$b['label'],$b['key']]);$count=count($nodes);foreach($nodes as$index=>&$node){$angle=$count<2?0.0:(2*M_PI*$index/$count)-M_PI/2;$radius=$count===1?0.0:42.0;$node['x']=round(50+cos($angle)*$radius,4);$node['y']=round(50+sin($angle)*$radius,4);}unset($node);
		return['nodes'=>$nodes,'edges'=>$edges,'directed'=>true];
	}

	/** @param list<array{key:string,position:int,visible:bool,data:array<string,mixed>}> $records @param array<string,int> $diagnostics @return array<string,mixed> */
	private function gantt(PanelDataCanvasSpec $spec,array $records,array &$diagnostics):array {
		$titleField=(string)$spec->role('title');$startField=(string)$spec->role('start');$endField=(string)$spec->role('end');$tasks=[];$minimum=null;$maximum=null;
		foreach($records as$record){$start=$this->date($record['data'][$startField]??null);$end=$this->date($record['data'][$endField]??null);if($start===null||$end===null||$end<$start){$this->diagnostic($diagnostics,'invalid_interval');continue;}$progressField=$spec->role('progress');$progress=$progressField===null?0.0:$this->number($record['data'][$progressField]??null);if($progress===null){$progress=0.0;$this->diagnostic($diagnostics,'invalid_progress');}elseif($progress<0||$progress>100){$progress=max(0,min(100,$progress));$this->diagnostic($diagnostics,'clamped_progress');}
			$minimum=$minimum===null?$start:min($minimum,$start);$maximum=$maximum===null?$end:max($maximum,$end);$tasks[]=['key'=>$record['key'],'title'=>$this->display($record['data'][$titleField]??$record['key']),'start'=>$this->dateString($start),'end'=>$this->dateString($end),'start_epoch'=>$start,'end_epoch'=>$end,'duration_seconds'=>$end-$start,'progress'=>round($progress,4),'left'=>0.0,'width'=>0.0,'filter_values'=>$this->filterValues($spec,[$record])];}
		$span=max(1,(int)(($maximum??0)-($minimum??0)));foreach($tasks as&$task){$task['left']=round((($task['start_epoch']-($minimum??0))/$span)*100,6);$task['width']=round((($task['end_epoch']-$task['start_epoch'])/$span)*100,6);unset($task['start_epoch'],$task['end_epoch']);}unset($task);
		return['tasks'=>$tasks,'range_start'=>$minimum===null?null:$this->dateString($minimum),'range_end'=>$maximum===null?null:$this->dateString($maximum),'span_seconds'=>$minimum===null?0:$span];
	}

	/** @param list<array{key:string,position:int,visible:bool,data:array<string,mixed>}> $records @param array<string,int> $diagnostics @return array<string,mixed> */
	private function map(PanelDataCanvasSpec $spec,array $records,array &$diagnostics):array {
		$titleField=(string)$spec->role('title');$latitudeField=(string)$spec->role('latitude');$longitudeField=(string)$spec->role('longitude');$points=[];$latitudes=[];$longitudes=[];
		foreach($records as$record){$latitude=$this->number($record['data'][$latitudeField]??null);$longitude=$this->number($record['data'][$longitudeField]??null);if($latitude===null||$longitude===null||$latitude< -90||$latitude>90||$longitude< -180||$longitude>180){$this->diagnostic($diagnostics,'invalid_coordinate');continue;}$latitudes[]=$latitude;$longitudes[]=$longitude;$points[]=['key'=>$record['key'],'title'=>$this->display($record['data'][$titleField]??$record['key']),'latitude'=>$latitude,'longitude'=>$longitude,'x'=>round((($longitude+180)/360)*100,6),'y'=>round(((90-$latitude)/180)*100,6),'filter_values'=>$this->filterValues($spec,[$record])];}
		return['points'=>$points,'bounds'=>$points===[]?null:['north'=>max($latitudes),'east'=>max($longitudes),'south'=>min($latitudes),'west'=>min($longitudes)],'projection'=>'equirectangular'];
	}

	/** @param list<array{key:string,position:int,visible:bool,data:array<string,mixed>}> $records @param array<string,int> $diagnostics @return array<string,mixed> */
	private function canvas(PanelDataCanvasSpec $spec,array $records,array &$diagnostics):array {
		$titleField=(string)$spec->role('title');$xField=(string)$spec->role('x');$yField=(string)$spec->role('y');$items=[];$minimumX=null;$minimumY=null;$maximumX=null;$maximumY=null;
		foreach($records as$record){$x=$this->number($record['data'][$xField]??null);$y=$this->number($record['data'][$yField]??null);$widthField=$spec->role('width');$heightField=$spec->role('height');$width=$widthField===null?1.0:$this->number($record['data'][$widthField]??null);$height=$heightField===null?1.0:$this->number($record['data'][$heightField]??null);if($x===null||$y===null||$width===null||$height===null||$width<=0||$height<=0||max(abs($x),abs($y),$width,$height)>1000000000){$this->diagnostic($diagnostics,'invalid_geometry');continue;}$minimumX=$minimumX===null?$x:min($minimumX,$x);$minimumY=$minimumY===null?$y:min($minimumY,$y);$maximumX=$maximumX===null?$x+$width:max($maximumX,$x+$width);$maximumY=$maximumY===null?$y+$height:max($maximumY,$y+$height);$items[]=['key'=>$record['key'],'title'=>$this->display($record['data'][$titleField]??$record['key']),'description'=>$this->roleDisplay($spec,$record,'description'),'raw_x'=>$x,'raw_y'=>$y,'raw_width'=>$width,'raw_height'=>$height,'x'=>0.0,'y'=>0.0,'width'=>0.0,'height'=>0.0,'filter_values'=>$this->filterValues($spec,[$record])];}
		$spanX=max(1.0,(float)(($maximumX??0)-($minimumX??0)));$spanY=max(1.0,(float)(($maximumY??0)-($minimumY??0)));foreach($items as&$item){$item['x']=round((($item['raw_x']-($minimumX??0))/$spanX)*100,6);$item['y']=round((($item['raw_y']-($minimumY??0))/$spanY)*100,6);$item['width']=round(($item['raw_width']/$spanX)*100,6);$item['height']=round(($item['raw_height']/$spanY)*100,6);unset($item['raw_x'],$item['raw_y'],$item['raw_width'],$item['raw_height']);}unset($item);
		return['items'=>$items,'bounds'=>$items===[]?null:['minimum_x'=>$minimumX,'minimum_y'=>$minimumY,'maximum_x'=>$maximumX,'maximum_y'=>$maximumY],'snap_to_grid'=>$spec->snapToGrid(),'zoom'=>$spec->zoom(),'editable'=>$spec->editable()];
	}

	/** @param array<string,mixed> $cell */
	private function aggregate(string $aggregate,array $cell):mixed {
		$values=$cell['values'];return match($aggregate){
			'count'=>$cell['count'],'sum'=>$values===[]?null:array_sum($values),'average'=>$values===[]?null:array_sum($values)/count($values),
			'minimum'=>$values===[]?null:min($values),'maximum'=>$values===[]?null:max($values),'first'=>$cell['first_set']?$cell['first']:null,
			default=>throw new \LogicException('Unsupported Panel DataCanvas aggregate.'),
		};
	}

	/** @return ?array{key:string,value:mixed,label:string} */
	private function category(mixed $value):?array {if(!$this->scalar($value)){return null;}$canonical=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);return['key'=>hash('sha256',$canonical),'value'=>$value,'label'=>$this->display($value)];}
	private function scalar(mixed $value):bool{return$value===null||is_string($value)||is_int($value)||is_float($value)&&is_finite($value)||is_bool($value);}
	private function number(mixed $value):?float {if(is_int($value)||is_float($value)&&is_finite($value)){return(float)$value;}if(is_string($value)&&$value!==''&&is_numeric($value)){$number=(float)$value;return is_finite($number)?$number:null;}return null;}
	private function date(mixed $value):?int {try{if(is_int($value)){return$value;}if(!is_string($value)||trim($value)===''||strlen($value)>128){return null;}return(new \DateTimeImmutable($value))->getTimestamp();}catch(\Throwable){return null;}}
	private function dateString(int $timestamp):string{return(new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);}
	private function display(mixed $value):string {if($value===null){return'No value';}if(is_bool($value)){return$value?'Yes':'No';}if(is_scalar($value)){$text=trim((string)$value);return$text===''?'No value':PanelDataSurfaceGuard::boundedString($text,'canvas label',2048);}return'Unsupported value';}
	/** @param array{data:array<string,mixed>} $record */
	private function roleDisplay(PanelDataCanvasSpec $spec,array $record,string $role):?string {$field=$spec->role($role);if($field===null||!array_key_exists($field,$record['data'])||$record['data'][$field]===null){return null;}return$this->display($record['data'][$field]);}
	/** @param list<array{data:array<string,mixed>}> $records @return list<mixed> */
	private function filterValues(?PanelDataCanvasSpec $spec,array $records):array {if(!$spec instanceof PanelDataCanvasSpec||!$spec->crossFilterEnabled()){return[];}$field=(string)$spec->crossFilterField();$values=[];foreach($records as$record){$value=$record['data'][$field]??null;if($this->scalar($value)){$values[]=$value;}}return array_slice(PanelDataSurfaceInteraction::normalizeValues($values),0,100);}
	/** @param list<mixed> $left @param list<mixed> $right @param array<string,int> $diagnostics @return list<mixed> */
	private function mergeValues(array $left,array $right,array &$diagnostics):array {
		$merged=[];$seen=[];foreach(array_merge($left,$right)as$value){$key=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);if(isset($seen[$key])){continue;}$seen[$key]=true;if(count($merged)===100){$this->diagnostic($diagnostics,'filter_values_truncated');break;}$merged[]=$value;}return$merged;
	}
	/** @param array<string,int> $diagnostics */private function diagnostic(array &$diagnostics,string $code):void{$diagnostics[$code]=($diagnostics[$code]??0)+1;}
}
