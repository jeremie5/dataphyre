<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Accessible SSR for every first-party DataSurface type; enhancement is optional. */
final class PanelDataSurfaceRenderer {
	/** @param array<string,mixed> $options */
	public static function render(
		PanelDataSurfaceDefinition $definition,
		PanelDataSurfaceWindowResult $window,
		?PanelDataSurfaceWindowIntent $refresh=null,
		array $options=[]
	): string {
		if($window->definition()!==$definition->id() || $window->surface()!==$definition->surface()){
			throw new \InvalidArgumentException('Panel DataSurface renderer received a mismatched definition and window.');
		}
		$rootId=self::id((string)($options['id'] ?? 'dp-data-surface-'.$definition->id()));
		$title=self::text($options['title'] ?? $definition->option('title', ucfirst(str_replace('_',' ',$definition->id()))), 'Data surface', 256);
		$description=self::text($options['description'] ?? $definition->option('description', ''), '', 512);
		$endpoint=self::endpoint((string)($options['endpoint'] ?? $definition->option('endpoint', '')));
		$canvas=$definition->canvas();$canvasModel=$window->canvas();
		if($definition->surface()->advanced()&&(!$canvas instanceof PanelDataCanvasSpec||!$canvasModel instanceof PanelDataCanvasModel)){throw new \InvalidArgumentException('Panel DataSurface renderer requires a projected advanced canvas model.');}
		$headingId=$rootId.'-title'; $statusId=$rootId.'-status';
		$config=[
			'version'=>1,'definition'=>$definition->id(),'surface'=>$definition->surface()->value,
			'endpoint'=>$endpoint,'estimated_item_size'=>(int)$definition->option('estimated_item_size',52),
			'virtualize'=>!$definition->surface()->advanced()&&$definition->option('virtualize',true)===true,
			'projection'=>$definition->projection()->jsonSerialize(),
			'canvas'=>$canvas?->jsonSerialize(),
			'refresh_intent'=>$refresh?->token(),'previous_intent'=>$window->previousIntent()?->token(),'next_intent'=>$window->nextIntent()?->token(),
			'messages'=>['loading'=>'Loading more records…','loaded'=>'Records updated.','failed'=>'Records could not be loaded.','empty'=>self::text($definition->option('empty_message','No records to display.'),'No records to display.',256)],
		];
		$configJson=json_encode($config, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
		$attributes=' class="dp-data-surface dp-data-surface--'.self::e($definition->surface()->value).'" data-dp-data-surface data-dp-data-surface-version="'.($definition->surface()->advanced()?'2':'1').'" data-dp-data-surface-type="'.self::e($definition->surface()->value).'" id="'.self::e($rootId).'" aria-labelledby="'.self::e($headingId).'" aria-describedby="'.self::e($statusId).'"';
		if($canvas instanceof PanelDataCanvasSpec){$attributes.=' data-dp-data-canvas-selection="'.self::e($canvas->selection()).'"';if($canvas->crossFilterGroup()!==null){$attributes.=' data-dp-data-canvas-group="'.self::e($canvas->crossFilterGroup()).'"';}}
		$html='<section'.$attributes.'>';
		$html.='<header class="dp-data-surface__header"><div><h2 id="'.self::e($headingId).'">'.self::e($title).'</h2>';
		if($description!==''){ $html.='<p class="dp-data-surface__description">'.self::e($description).'</p>'; }
		$html.='</div><p class="dp-data-surface__status" id="'.self::e($statusId).'" data-dp-data-surface-status role="status" aria-live="polite" aria-atomic="true">'.self::e(self::summary($window)).'</p></header>';
		$html.='<div class="dp-data-surface__spacer" data-dp-data-surface-spacer="before" aria-hidden="true"></div>';
		$html.='<div class="dp-data-surface__viewport" data-dp-data-surface-viewport>';
		$html.=$definition->surface()->advanced()?self::canvas($definition,$window,$title):($definition->surface()===PanelDataSurfaceType::TABLE ? self::table($definition,$window,$title) : self::collection($definition,$window,$title));
		$html.='</div>';
		$html.='<div class="dp-data-surface__spacer" data-dp-data-surface-spacer="after" aria-hidden="true"></div>';
		$html.=self::controls($window,$refresh,$endpoint!=='');
		$html.='<script type="application/json" data-dp-data-surface-config>'.$configJson.'</script></section>';
		return $html;
	}

	private static function table(PanelDataSurfaceDefinition $definition, PanelDataSurfaceWindowResult $window, string $title): string {
		$projection=$definition->projection(); $fields=$projection->fields();
		$html='<div class="dp-data-surface__table-shell"><table class="dp-data-surface__table" data-dp-data-surface-items><caption class="dp-panel-visually-hidden">'.self::e($title).'</caption><thead><tr>';
		foreach($fields as $field){ $html.='<th scope="col">'.self::e($projection->label($field)).'</th>'; }
		$html.='</tr></thead><tbody>';
		foreach($window->records() as $index=>$record){
			$html.='<tr data-dp-data-surface-item data-key="'.self::e($record['key']).'" data-position="'.$record['position'].'" data-visible="'.($record['visible']?'1':'0').'" tabindex="'.($index===0?'0':'-1').'">';
			foreach($fields as $field){ $html.='<td data-label="'.self::e($projection->label($field)).'">'.self::value($record['data'][$field] ?? null).'</td>'; }
			$html.='</tr>';
		}
		if($window->records()===[]){ $html.='<tr class="dp-data-surface__empty"><td colspan="'.max(1,count($fields)).'">'.self::e(self::text($definition->option('empty_message','No records to display.'),'No records to display.',256)).'</td></tr>'; }
		return $html.'</tbody></table></div>';
	}

	private static function collection(PanelDataSurfaceDefinition $definition, PanelDataSurfaceWindowResult $window, string $title): string {
		$tag=$definition->surface()===PanelDataSurfaceType::TIMELINE ? 'ol' : 'ul';
		$html='<'.$tag.' class="dp-data-surface__items" data-dp-data-surface-items role="list" aria-label="'.self::e($title).'">';
		foreach($window->records() as $index=>$record){ $html.=self::item($definition,$record,$index===0); }
		if($window->records()===[]){ $html.='<li class="dp-data-surface__empty">'.self::e(self::text($definition->option('empty_message','No records to display.'),'No records to display.',256)).'</li>'; }
		return $html.'</'.$tag.'>';
	}

	private static function canvas(PanelDataSurfaceDefinition $definition,PanelDataSurfaceWindowResult $window,string $title):string {
		$spec=$definition->canvas();$canvas=$window->canvas();if(!$spec instanceof PanelDataCanvasSpec||!$canvas instanceof PanelDataCanvasModel){throw new \InvalidArgumentException('Panel DataCanvas renderer received an incomplete window.');}$model=$canvas->model();
		return match($definition->surface()){
			PanelDataSurfaceType::SPREADSHEET=>self::spreadsheet($definition,$window,$spec,$model,$title),
			PanelDataSurfaceType::PIVOT,PanelDataSurfaceType::HEATMAP=>self::matrix($definition,$spec,$model,$title),
			PanelDataSurfaceType::TREE=>self::tree($definition,$spec,$model,$title),
			PanelDataSurfaceType::GRAPH=>self::graph($definition,$spec,$model,$title),
			PanelDataSurfaceType::GANTT=>self::gantt($definition,$spec,$model,$title),
			PanelDataSurfaceType::MAP=>self::map($definition,$spec,$model,$title),
			PanelDataSurfaceType::CANVAS=>self::freeform($definition,$spec,$model,$title),
			default=>throw new \LogicException('Unsupported Panel DataCanvas surface.'),
		};
	}

	/** @param array<string,mixed> $model */
	private static function spreadsheet(PanelDataSurfaceDefinition $definition,PanelDataSurfaceWindowResult $window,PanelDataCanvasSpec $spec,array $model,string $title):string {
		$fields=is_array($model['fields']??null)?$model['fields']:[];$rowModels=is_array($model['rows']??null)?$model['rows']:[];$records=[];foreach($window->records()as$record){$records[$record['key']]=$record;}
		$html='<div class="dp-data-canvas__table-shell dp-data-canvas__spreadsheet-shell" role="region" aria-label="'.self::e($title).' spreadsheet"><table class="dp-data-canvas__table dp-data-canvas__spreadsheet" data-dp-data-surface-items><caption class="dp-panel-visually-hidden">'.self::e($title).'</caption><thead><tr><th scope="col" class="dp-data-canvas__row-number">#</th>';
		foreach($fields as$field){if(!is_array($field)){continue;}$name=(string)($field['name']??'');$label=(string)($field['label']??$name);$html.='<th scope="col" data-field="'.self::e($name).'"'.(($field['frozen']??false)===true?' data-frozen="true"':'').'>'.self::e($label).'</th>';}
		$html.='</tr></thead><tbody>';
		foreach($rowModels as$index=>$row){if(!is_array($row)||!isset($records[(string)($row['key']??'')])){continue;}$record=$records[(string)$row['key']];$html.='<tr class="dp-data-canvas__record"'.self::canvasItemAttributes($spec,$record['key'],$record['position'],$record['visible'],is_array($row['filter_values']??null)?$row['filter_values']:[],$index).'><th scope="row" class="dp-data-canvas__row-number">'.($record['position']+1).'</th>';foreach($fields as$field){if(!is_array($field)){continue;}$name=(string)($field['name']??'');$html.='<td data-label="'.self::e((string)($field['label']??$name)).'"'.(($field['frozen']??false)===true?' data-frozen="true"':'').'>'.self::value($record['data'][$name]??null).'</td>';}$html.='</tr>';}
		if($rowModels===[]){$html.='<tr class="dp-data-surface__empty"><td colspan="'.max(1,count($fields)+1).'">'.self::e(self::text($definition->option('empty_message','No records to display.'),'No records to display.',256)).'</td></tr>';}
		return$html.'</tbody></table></div>';
	}

	/** @param array<string,mixed> $model */
	private static function matrix(PanelDataSurfaceDefinition $definition,PanelDataCanvasSpec $spec,array $model,string $title):string {
		$rows=is_array($model['rows']??null)?$model['rows']:[];$columns=is_array($model['columns']??null)?$model['columns']:[];$cells=[];foreach(is_array($model['cells']??null)?$model['cells']:[]as$cell){if(is_array($cell)){$cells[(string)($cell['row_key']??'')."\0".(string)($cell['column_key']??'')]=$cell;}}
		$html='<div class="dp-data-canvas__table-shell dp-data-canvas__matrix-shell" role="region" aria-label="'.self::e($title).' '.self::e($definition->surface()->value).'"><table class="dp-data-canvas__table dp-data-canvas__matrix" data-dp-data-surface-items role="grid"><caption class="dp-panel-visually-hidden">'.self::e($title).'</caption><thead><tr><th scope="col">'.self::e(ucfirst($spec->aggregate())).'</th>';foreach($columns as$column){if(is_array($column)){$html.='<th scope="col">'.self::e((string)($column['label']??'No value')).'</th>';}}$html.='</tr></thead><tbody>';
		$position=0;foreach($rows as$row){if(!is_array($row)){continue;}$html.='<tr><th scope="row">'.self::e((string)($row['label']??'No value')).'</th>';foreach($columns as$column){if(!is_array($column)){continue;}$columnLabel=(string)($column['label']??'No value');$cell=$cells[(string)($row['key']??'')."\0".(string)($column['key']??'')]??null;if(!is_array($cell)){$html.='<td data-label="'.self::e($columnLabel).'"><span aria-label="No value">Not set</span></td>';continue;}$intensity=max(0,min(1,(float)($cell['intensity']??0)));$key=is_string($cell['record_key']??null)?$cell['record_key']:(string)($row['key']??'').'-'.(string)($column['key']??'');$values=is_array($cell['filter_values']??null)?$cell['filter_values']:[];$current=$position++;$html.='<td class="dp-data-canvas__matrix-cell" data-label="'.self::e($columnLabel).'" style="--dp-data-canvas-intensity:'.self::number($intensity).'">';$html.='<div class="dp-data-canvas__matrix-value"'.self::canvasItemAttributes($spec,$key,$current,true,$values,$current,'gridcell').'><strong>'.self::value($cell['value']??null).'</strong><span>'.(int)($cell['count']??0).' record'.((int)($cell['count']??0)===1?'':'s').'</span>'.self::drill($spec,$cell['record_key']??null).'</div></td>';}$html.='</tr>';}
		if($rows===[]){$html.='<tr class="dp-data-surface__empty"><td colspan="'.max(1,count($columns)+1).'">'.self::e(self::text($definition->option('empty_message','No records to display.'),'No records to display.',256)).'</td></tr>';}
		return$html.'</tbody></table></div>';
	}

	/** @param array<string,mixed> $model */
	private static function tree(PanelDataSurfaceDefinition $definition,PanelDataCanvasSpec $spec,array $model,string $title):string {
		$nodes=is_array($model['nodes']??null)?$model['nodes']:[];$html='<ol class="dp-data-canvas__tree" data-dp-data-surface-items role="tree" aria-label="'.self::e($title).'">';
		foreach($nodes as$index=>$node){if(!is_array($node)){continue;}$depth=max(0,min(64,(int)($node['depth']??0)));$states=[];if(($node['orphan']??false)===true){$states[]='Missing parent';}if(($node['cycle']??false)===true){$states[]='Circular relationship';}$html.='<li class="dp-data-canvas__tree-node" role="treeitem" aria-level="'.($depth+1).'" style="--dp-data-tree-depth:'.$depth.'"'.self::canvasItemAttributes($spec,(string)($node['key']??''),(int)($node['position']??$index),(bool)($node['visible']??true),is_array($node['filter_values']??null)?$node['filter_values']:[],$index).'><span class="dp-data-canvas__tree-branch" aria-hidden="true"></span><div><strong>'.self::e((string)($node['title']??$node['key']??'Record')).'</strong>';if(is_string($node['description']??null)&&$node['description']!==''){$html.='<span>'.self::e($node['description']).'</span>';}if($states!==[]){$html.='<small>'.self::e(implode(' · ',$states)).'</small>';}$html.=self::drill($spec,$node['key']??null).'</div></li>';}
		if($nodes===[]){$html.='<li class="dp-data-surface__empty">'.self::e(self::text($definition->option('empty_message','No records to display.'),'No records to display.',256)).'</li>';}
		return$html.'</ol>';
	}

	/** @param array<string,mixed> $model */
	private static function graph(PanelDataSurfaceDefinition $definition,PanelDataCanvasSpec $spec,array $model,string $title):string {
		$nodes=is_array($model['nodes']??null)?$model['nodes']:[];$edges=is_array($model['edges']??null)?$model['edges']:[];$nodeMap=[];foreach($nodes as$node){if(is_array($node)){$nodeMap[(string)($node['key']??'')]=$node;}}
		$html='<div class="dp-data-canvas__graph"><div class="dp-data-canvas__graph-stage" aria-hidden="true"><svg viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet" focusable="false">';foreach($edges as$edge){if(!is_array($edge)){continue;}$source=$nodeMap[(string)($edge['source']??'')]??null;$target=$nodeMap[(string)($edge['target']??'')]??null;if(is_array($source)&&is_array($target)){$html.='<line x1="'.self::number($source['x']??0).'" y1="'.self::number($source['y']??0).'" x2="'.self::number($target['x']??0).'" y2="'.self::number($target['y']??0).'" vector-effect="non-scaling-stroke"></line>';}}$html.='</svg>';foreach($nodes as$node){if(!is_array($node)){continue;}$html.='<span class="dp-data-canvas__graph-node" style="--dp-canvas-x:'.self::number($node['x']??0).'%;--dp-canvas-y:'.self::number($node['y']??0).'%"><strong>'.self::e((string)($node['label']??'Node')).'</strong><small>'.((int)($node['in_degree']??0)+(int)($node['out_degree']??0)).' links</small></span>';}$html.='</div><ol class="dp-data-canvas__graph-edges" data-dp-data-surface-items role="'.($spec->selection()==='none'?'list':'listbox').'" aria-label="'.self::e($title).' relationships">';foreach($edges as$index=>$edge){if(!is_array($edge)){continue;}$html.='<li class="dp-data-canvas__graph-edge"'.self::canvasItemAttributes($spec,(string)($edge['key']??$index),$index,true,is_array($edge['filter_values']??null)?$edge['filter_values']:[],$index,$spec->selection()==='none'?'listitem':'option').'><span aria-hidden="true">↗</span><strong>'.self::e((string)($edge['label']??'Relationship')).'</strong>'.self::drill($spec,$edge['key']??null).'</li>';}$html.='</ol>';
		if($edges===[]){$html.='<p class="dp-data-surface__empty">'.self::e(self::text($definition->option('empty_message','No relationships to display.'),'No relationships to display.',256)).'</p>';}
		return$html.'</div>';
	}

	/** @param array<string,mixed> $model */
	private static function gantt(PanelDataSurfaceDefinition $definition,PanelDataCanvasSpec $spec,array $model,string $title):string {
		$tasks=is_array($model['tasks']??null)?$model['tasks']:[];$html='<ol class="dp-data-canvas__gantt" data-dp-data-surface-items role="'.($spec->selection()==='none'?'list':'listbox').'" aria-label="'.self::e($title).' schedule">';foreach($tasks as$index=>$task){if(!is_array($task)){continue;}$left=max(0,min(100,(float)($task['left']??0)));$width=max(0,min(100-$left,(float)($task['width']??0)));$progress=max(0,min(100,(float)($task['progress']??0)));$html.='<li class="dp-data-canvas__gantt-task"'.self::canvasItemAttributes($spec,(string)($task['key']??$index),$index,true,is_array($task['filter_values']??null)?$task['filter_values']:[],$index,$spec->selection()==='none'?'listitem':'option').'><div class="dp-data-canvas__gantt-label"><strong>'.self::e((string)($task['title']??'Task')).'</strong><span><time datetime="'.self::e((string)($task['start']??'')).'">'.self::e((string)($task['start']??'')).'</time> to <time datetime="'.self::e((string)($task['end']??'')).'">'.self::e((string)($task['end']??'')).'</time></span></div><div class="dp-data-canvas__gantt-track" aria-label="'.self::number($progress).'% complete"><span style="--dp-gantt-left:'.self::number($left).'%;--dp-gantt-width:'.self::number($width).'%;--dp-gantt-progress:'.self::number($progress).'%"></span></div>'.self::drill($spec,$task['key']??null).'</li>';}
		if($tasks===[]){$html.='<li class="dp-data-surface__empty">'.self::e(self::text($definition->option('empty_message','No tasks to display.'),'No tasks to display.',256)).'</li>';}
		return$html.'</ol>';
	}

	/** @param array<string,mixed> $model */
	private static function map(PanelDataSurfaceDefinition $definition,PanelDataCanvasSpec $spec,array $model,string $title):string {
		$points=is_array($model['points']??null)?$model['points']:[];$html='<div class="dp-data-canvas__map" data-dp-data-surface-items role="'.($spec->selection()==='none'?'list':'listbox').'" aria-label="'.self::e($title).' locations">';foreach($points as$index=>$point){if(!is_array($point)){continue;}$x=max(0,min(100,(float)($point['x']??0)));$y=max(0,min(100,(float)($point['y']??0)));$label=(string)($point['title']??'Location');$html.='<article class="dp-data-canvas__map-point" style="--dp-canvas-x:'.self::number($x).'%;--dp-canvas-y:'.self::number($y).'%"'.self::canvasItemAttributes($spec,(string)($point['key']??$index),$index,true,is_array($point['filter_values']??null)?$point['filter_values']:[],$index,$spec->selection()==='none'?'listitem':'option').'><span class="dp-data-canvas__map-pin" aria-hidden="true"></span><strong>'.self::e($label).'</strong><small>'.self::number($point['latitude']??0).', '.self::number($point['longitude']??0).'</small>'.self::drill($spec,$point['key']??null).'</article>';}
		if($points===[]){$html.='<p class="dp-data-surface__empty">'.self::e(self::text($definition->option('empty_message','No locations to display.'),'No locations to display.',256)).'</p>';}
		return$html.'</div>';
	}

	/** @param array<string,mixed> $model */
	private static function freeform(PanelDataSurfaceDefinition $definition,PanelDataCanvasSpec $spec,array $model,string $title):string {
		$items=is_array($model['items']??null)?$model['items']:[];$html='<div class="dp-data-canvas__freeform" data-dp-data-surface-items role="'.($spec->selection()==='none'?'list':'listbox').'" aria-label="'.self::e($title).' canvas">';foreach($items as$index=>$item){if(!is_array($item)){continue;}$x=max(0,min(100,(float)($item['x']??0)));$y=max(0,min(100,(float)($item['y']??0)));$width=max(.5,min(100-$x,(float)($item['width']??1)));$height=max(.5,min(100-$y,(float)($item['height']??1)));$html.='<article class="dp-data-canvas__freeform-item" style="--dp-canvas-x:'.self::number($x).'%;--dp-canvas-y:'.self::number($y).'%;--dp-canvas-width:'.self::number($width).'%;--dp-canvas-height:'.self::number($height).'%"'.self::canvasItemAttributes($spec,(string)($item['key']??$index),$index,true,is_array($item['filter_values']??null)?$item['filter_values']:[],$index,$spec->selection()==='none'?'listitem':'option').'><strong>'.self::e((string)($item['title']??'Item')).'</strong>';if(is_string($item['description']??null)&&$item['description']!==''){$html.='<span>'.self::e($item['description']).'</span>';}$html.=self::drill($spec,$item['key']??null).'</article>';}
		if($items===[]){$html.='<p class="dp-data-surface__empty">'.self::e(self::text($definition->option('empty_message','No canvas items to display.'),'No canvas items to display.',256)).'</p>';}
		return$html.'</div>';
	}

	/** @param list<mixed> $values */
	private static function canvasItemAttributes(PanelDataCanvasSpec $spec,string $key,int $position,bool $visible,array $values,int $index,?string $role=null):string {
		$html=' data-dp-data-surface-item data-key="'.self::e($key).'" data-position="'.max(0,$position).'" data-visible="'.($visible?'1':'0').'" tabindex="'.($index===0?'0':'-1').'"';
		if($role!==null){$html.=' role="'.self::e($role).'"';}
		if($spec->selection()!=='none'){$json=json_encode(PanelDataSurfaceInteraction::normalizeValues($values),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$html.=' data-dp-data-canvas-select data-dp-data-canvas-values="'.self::e($json).'" aria-selected="false"';}
		return$html;
	}

	private static function drill(PanelDataCanvasSpec $spec,mixed $key):string {if($spec->drillUrl()===null||(!is_string($key)&&!is_int($key))){return'';}$url=self::drillUrl($spec->drillUrl(),$spec->drillParameter(),(string)$key);return'<a class="dp-data-canvas__drill" href="'.self::e($url).'">Open details<span class="dp-panel-visually-hidden"> for '.self::e((string)$key).'</span></a>';}
	private static function drillUrl(string $url,string $parameter,string $value):string {$fragment='';$position=strpos($url,'#');if($position!==false){$fragment=substr($url,$position);$url=substr($url,0,$position);}$separator=str_contains($url,'?')?'&':'?';return$url.$separator.rawurlencode($parameter).'='.rawurlencode($value).$fragment;}
	private static function number(mixed $value):string {$number=is_int($value)||is_float($value)?(float)$value:0.0;if(!is_finite($number)){return'0';}return rtrim(rtrim(number_format($number,6,'.',''),'0'),'.')?:'0';}

	/** @param array{key:string,position:int,visible:bool,data:array<string,mixed>} $record */
	private static function item(PanelDataSurfaceDefinition $definition, array $record, bool $focusable): string {
		$projection=$definition->projection(); $data=$record['data'];
		$title=self::slot($projection,$data,'title') ?? $record['key'];
		$description=self::slot($projection,$data,'description');
		$meta=self::slot($projection,$data,'meta'); $badge=self::slot($projection,$data,'badge');
		$time=self::slot($projection,$data,'time') ?? self::slot($projection,$data,'start');
		$image=self::slot($projection,$data,'image'); $alt=self::slot($projection,$data,'alt') ?? '';
		$html='<li class="dp-data-surface__item" data-dp-data-surface-item data-key="'.self::e($record['key']).'" data-position="'.$record['position'].'" data-visible="'.($record['visible']?'1':'0').'" tabindex="'.($focusable?'0':'-1').'"><article>';
		if($image!==null && ($url=self::mediaUrl($image))!==''){ $html.='<img class="dp-data-surface__image" src="'.self::e($url).'" alt="'.self::e($alt).'" loading="lazy" decoding="async">'; }
		$html.='<div class="dp-data-surface__item-body"><h3>'.self::e($title).'</h3>';
		if($time!==null){ $date=self::dateTime($time); $html.='<time'.($date!==''?' datetime="'.self::e($date).'"':'').'>'.self::e($time).'</time>'; }
		if($description!==null){ $html.='<p>'.self::e($description).'</p>'; }
		if($meta!==null || $badge!==null){ $html.='<footer>'; if($meta!==null){$html.='<span>'.self::e($meta).'</span>';} if($badge!==null){$html.='<span class="dp-data-surface__badge">'.self::e($badge).'</span>';} $html.='</footer>'; }
		return $html.'</div></article></li>';
	}

	private static function controls(PanelDataSurfaceWindowResult $window, ?PanelDataSurfaceWindowIntent $refresh, bool $enabled): string {
		$html='<nav class="dp-data-surface__controls" data-dp-data-surface-controls aria-label="Data window controls" hidden>';
		foreach([
			['previous','Previous',$window->previousIntent()],['refresh','Refresh',$refresh],['next','Next',$window->nextIntent()],
		] as [$action,$label,$intent]){
			$disabled=!$enabled || !$intent instanceof PanelDataSurfaceWindowIntent;
			$html.='<button type="button" class="dp-panel-button dp-panel-button-secondary" data-dp-data-surface-intent="'.$action.'"'.($disabled?' disabled aria-disabled="true"':' data-intent="'.self::e($intent->token()).'"').'>'.self::e($label).'</button>';
		}
		return $html.'</nav>';
	}

	private static function summary(PanelDataSurfaceWindowResult $window): string {
		$count=0; foreach($window->records() as $record){ if($record['visible']){$count++;} }
		if($window->total()!==null){ return $count.' of '.$window->total().' records shown.'; }
		return $count.' record'.($count===1?'':'s').' shown; total unknown.';
	}

	/** @param array<string,mixed> $data */
	private static function slot(PanelDataSurfaceProjection $projection, array $data, string $slot): ?string {
		$field=$projection->slot($slot); if($field===null || !array_key_exists($field,$data)){ return null; }
		$value=$data[$field]; if($value===null){return null;} if(is_bool($value)){return $value?'Yes':'No';} if(is_scalar($value)){return self::text($value,'',2048);}
		return self::text(json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'',2048);
	}

	private static function value(mixed $value): string {
		if($value===null){ return '<span aria-label="No value">Not set</span>'; }
		if(is_bool($value)){ return $value ? 'Yes' : 'No'; }
		if(is_scalar($value)){ return self::e(self::text($value,'',4096)); }
		try{ return self::e(self::text(json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'',4096)); }
		catch(\Throwable){ return '<span aria-label="Unavailable value">Not available</span>'; }
	}

	private static function endpoint(string $endpoint): string {
		$endpoint=trim($endpoint);
		if($endpoint==='' || strlen($endpoint)>2048 || preg_match('/[\x00-\x20\x7F]/',$endpoint)===1 || str_starts_with($endpoint,'//') || str_starts_with($endpoint,'\\')){ return ''; }
		if(preg_match('/\A([A-Za-z][A-Za-z0-9+.-]*):/',$endpoint,$match)===1 && !in_array(strtolower($match[1]),['http','https'],true)){ return ''; }
		return $endpoint;
	}
	private static function mediaUrl(string $url): string {
		$url=trim($url); if($url==='' || strlen($url)>2048 || preg_match('/[\x00-\x20\x7F]/',$url)===1 || str_starts_with($url,'//') || str_starts_with($url,'\\')){return '';}
		if(preg_match('/\A([A-Za-z][A-Za-z0-9+.-]*):/',$url,$match)===1 && !in_array(strtolower($match[1]),['http','https'],true)){return '';}
		return $url;
	}
	private static function dateTime(string $value): string { try{return (new \DateTimeImmutable($value))->format(DATE_ATOM);}catch(\Throwable){return '';} }
	private static function id(string $value): string { $value=strtolower(trim($value)); $value=preg_replace('/[^a-z0-9_-]+/','-',$value)??''; return trim(substr($value,0,128),'-_') ?: 'dp-data-surface'; }
	private static function text(mixed $value, string $fallback, int $maximum): string { if(!is_scalar($value) && !$value instanceof \Stringable){return $fallback;} $value=trim((string)$value); return $value!=='' && strlen($value)<=$maximum && preg_match('//u',$value)===1 ? $value : $fallback; }
	private static function e(string $value): string { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}
