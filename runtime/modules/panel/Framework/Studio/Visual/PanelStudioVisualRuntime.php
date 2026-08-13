<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/**
 * First-party route-neutral adapter from trusted Studio materializations to
 * sandboxed visual Panel output.
 */
final class PanelStudioVisualRuntime implements \JsonSerializable {
	public const RUNTIME_VERSION=2;

	public function renderSession(PanelStudioEditorSession $session,?PanelStudioVisualDataset $dataset=null,?PanelRequest $request=null):PanelStudioVisualPreview{
		$manager=$session->manager();$this->assertAttached($manager);
		$manager->head($session->document()->tenantId(),$session->document()->id(),$session->principalId());
		$validation=$manager->registry()->validate($session->definition())->assertValid();$definition=$validation->definition();
		if(!$definition instanceof PanelStudioDefinition){throw new \LogicException('Studio visual validation lost its source definition.');}
		$materialization=$manager->materializer()->materialize($definition,$manager->registry());
		$dataset??=PanelStudioVisualDataset::sample($definition);$selectedStructural=self::structuralPath($definition,$session->selectedPath());
		$revision=!$session->dirty()&&$session->baseRevision()>0?$session->baseRevision():null;
		return$this->compose('session',$revision,$session->selectedPath(),$selectedStructural,$definition,$materialization,$dataset,$request);
	}

	public function renderSigned(PanelStudioManager $manager,string $token,string $tenantId,string $documentId,string $principalId,?PanelStudioVisualDataset $dataset=null,?PanelRequest $request=null,string $audience='panel_studio_preview'):PanelStudioVisualPreview{
		$this->assertAttached($manager);$intent=$manager->verifyPreview($token,$tenantId,$documentId,$principalId,null,$audience);$revision=(int)$intent->claims()['revision'];$materialization=$manager->materialize($tenantId,$documentId,$principalId,$revision);$stored=$manager->head($tenantId,$documentId,$principalId);
		if(!$stored instanceof PanelStudioRevision||$stored->number()!==$revision){throw new \RuntimeException('Studio signed visual preview revision is unavailable.');}
		$definition=$stored->definition();$dataset??=PanelStudioVisualDataset::sample($definition);$selected=(string)$definition->root()['key'];
		return$this->compose('signed',$revision,$selected,'root',$definition,$materialization,$dataset,$request);
	}

	public function renderPublished(PanelStudioManager $manager,string $tenantId,string $documentId,string $principalId,?PanelStudioVisualDataset $dataset=null,?PanelRequest $request=null):PanelStudioVisualPreview{
		$this->assertAttached($manager);$published=$manager->published($tenantId,$documentId,$principalId);if(!$published instanceof PanelStudioRevision){throw new \OutOfBoundsException('Studio document has no published revision.');}
		$materialization=$manager->materializePublished($tenantId,$documentId,$principalId);$definition=$published->definition();$dataset??=PanelStudioVisualDataset::sample($definition);$selected=(string)$definition->root()['key'];
		return$this->compose('published',$published->number(),$selected,'root',$definition,$materialization,$dataset,$request);
	}

	public function manifest():array{return[
		'type'=>'panel_studio_visual_runtime','version'=>self::RUNTIME_VERSION,'renderer'=>'sandboxed_panel_srcdoc','supported_kinds'=>PanelStudioDefinition::KINDS,'complete_definition_kind_coverage'=>true,
		'limits'=>['surfaces'=>PanelStudioVisualPreview::MAX_SURFACES,'frame_bytes'=>PanelStudioVisualSurface::MAX_FRAME_BYTES,'total_frame_bytes'=>PanelStudioVisualPreview::MAX_TOTAL_FRAME_BYTES],
		'capabilities'=>['unsaved_session_preview'=>true,'signed_revision_preview'=>true,'published_revision_preview'=>true,'actual_panel_builders'=>true,'data_surface_preview'=>true,'advanced_data_canvas_preview'=>true,'workflow_graph_preview'=>true,'workflow_analysis_preview'=>true,'conditional_refresh'=>true,'content_bound_etag'=>true,'synthetic_data'=>true,'host_data'=>true,'selected_surface_preview'=>true],
		'integration'=>['routes_registered'=>false,'transport_registered'=>false,'host_authorization_required'=>true,'host_attachment_required'=>true],
		'security'=>['sandboxed_frames'=>true,'same_origin'=>false,'scripts'=>false,'forms'=>false,'mutation_authority'=>false,'data_sources_executed'=>false,'callbacks_added'=>false,'raw_html_from_definition'=>false,'preview_tokens_serialized'=>false,'dataset_values_serialized_in_manifests'=>false,'first_party_styles_inlined'=>true,'external_assets_loaded'=>false],
	];}
	public function jsonSerialize():array{return$this->manifest();}

	private function assertAttached(PanelStudioManager $manager):void{if(!$manager->hasVisualRuntime()||$manager->visualRuntime()!==$this){throw new \LogicException('Studio visual runtime is not attached to this manager.');}}

	private function compose(string $source,?int $revision,string $selectedPath,string $selectedStructural,PanelStudioDefinition $definition,PanelStudioMaterialization $materialization,PanelStudioVisualDataset $dataset,?PanelRequest $request):PanelStudioVisualPreview{
		$targets=['root'=>$materialization->root()];
		if($selectedStructural!=='root'){$selected=$materialization->builder($selectedStructural);if($selected!==null){$targets[$selectedStructural]=$selected;}}
		$root=$materialization->root();if($root instanceof PanelStudioPageBundle){foreach($root->surfaces()as$path=>$surface){if(count($targets)>=PanelStudioVisualPreview::MAX_SURFACES){break;}$targets[$path]=$surface;}}
		$contract=$materialization->manifest()['builder_contract'];$surfaces=[];
		foreach($targets as$path=>$builder){$symbol=is_string($contract[$path]??null)?$contract[$path]:'panel_builder';$label=self::label($definition,$path,$symbol);$selected=$path===$selectedStructural;try{$result=self::selfContained($this->renderBuilder($builder,$path,$materialization,$dataset,$request));$surfaces[]=PanelStudioVisualSurface::success($path,$symbol,$label,$selected,$result);}catch(\Throwable $error){$code=$error instanceof \LengthException?'render_limit_exceeded':'render_failed';$surfaces[]=PanelStudioVisualSurface::failure($path,$symbol,$label,$selected,$code);}}
		return new PanelStudioVisualPreview($source,$revision,$selectedPath,$materialization,$dataset,$surfaces);
	}

	private function renderBuilder(object|array $builder,string $path,PanelStudioMaterialization $materialization,PanelStudioVisualDataset $dataset,?PanelRequest $request):PanelPageResult{
		if($builder instanceof PanelStudioPageBundle){return PanelRenderer::customPage($builder->page(),$this->request($request,'page',$builder->page()->name()));}
		if($builder instanceof Resource){$operation=$builder->hasStatusBoard()?'board':'index';return$builder->hasStatusBoard()?PanelRenderer::statusBoard($builder,$this->request($request,$operation,$builder->name()),$dataset->records()):PanelRenderer::index($builder,$this->request($request,$operation,$builder->name()),$dataset->records());}
		if($builder instanceof ResourceForm){return$this->form($builder,$dataset,$request);}
		if($builder instanceof ResourceTable){return$this->table($builder,$dataset,$request);}
		if($builder instanceof Infolist){return$this->show($builder,$dataset,$request);}
		if($builder instanceof PanelDataSurfaceDefinition){return$this->dataSurface($builder,$dataset,$request);}
		if($builder instanceof WorkflowDefinition){return$this->workflow($builder,$request);}
		if($builder instanceof WorkflowState){return$this->workflowState($builder,$request);}
		if($builder instanceof WorkflowTransition){return$this->workflowTransition($builder,$request);}
		if($builder instanceof Field){return$this->form(ResourceForm::make()->field($builder),$dataset,$request);}
		if($builder instanceof FormSection){$fields=[];foreach($this->descendants($materialization,$path,Field::class)as$field){$fields[]=$field->section((string)($builder->toArray()['label']??$builder->name()));}return$this->form(ResourceForm::make()->section($builder)->fields($fields),$dataset,$request);}
		if($builder instanceof Column){return$this->table(ResourceTable::make()->column($builder),$dataset,$request);}
		if($builder instanceof TableFilter){return$this->table(ResourceTable::make()->column(Column::make('name'))->filter($builder),$dataset,$request);}
		if($builder instanceof TableView){return$this->table(ResourceTable::make()->column(Column::make('name'))->view($builder),$dataset,$request);}
		if($builder instanceof PanelStudioBoardColumn){$records=[];foreach($dataset->records()as$record){$record['status']=$builder->status();$records[]=$record;}if($records===[]){$records=[array_replace($dataset->record(),['status'=>$builder->status()])];}$resource=self::resource()->statusField('status')->statusBoardColumns([$builder->resourceDefinition()]);return PanelRenderer::statusBoard($resource,$this->request($request,'board',$resource->name()),$records);}
		if($builder instanceof InfolistEntry){return$this->show(Infolist::make([$builder]),$dataset,$request);}
		if($builder instanceof Action){return$this->page(PanelPage::make('studio_visual_action')->label('Action preview')->action($builder),$request);}
		if($builder instanceof ActionGroup){return$this->page(PanelPage::make('studio_visual_actions')->label('Action group preview')->action($builder),$request);}
		if($builder instanceof Widget){return$this->page(PanelPage::make('studio_visual_widget')->label('Widget preview')->widget($builder),$request);}
		if($builder instanceof Schema){return$this->form($builder->toForm(),$dataset,$request);}
		if($builder instanceof SchemaComponent){return$this->form(Schema::make([$builder])->toForm(),$dataset,$request);}
		if($builder instanceof NavigationItem){return$this->navigation([$builder],$request);}
		if($builder instanceof PanelStudioBuilderCollection){return$this->collection($builder,$dataset,$request);}
		throw new \LogicException('Studio visual runtime received an unsupported builder.');
	}

	private function form(ResourceForm $form,PanelStudioVisualDataset $dataset,?PanelRequest $request):PanelPageResult{$resource=self::resource()->form($form);return PanelRenderer::form($resource,$this->request($request,'edit',$resource->name()),$dataset->record(),'edit');}
	private function table(ResourceTable $table,PanelStudioVisualDataset $dataset,?PanelRequest $request):PanelPageResult{$resource=self::resource()->resourceTable($table);return PanelRenderer::index($resource,$this->request($request,'index',$resource->name()),$dataset->records());}
	private function show(Infolist $infolist,PanelStudioVisualDataset $dataset,?PanelRequest $request):PanelPageResult{$resource=self::resource()->infolist($infolist);return PanelRenderer::show($resource,$this->request($request,'show',$resource->name()),$dataset->record());}
	private function page(PanelPage $page,?PanelRequest $request):PanelPageResult{return PanelRenderer::customPage($page,$this->request($request,'page',$page->name()));}
	private function dataSurface(PanelDataSurfaceDefinition $definition,PanelStudioVisualDataset $dataset,?PanelRequest $request):PanelPageResult{
		$records=[];foreach($dataset->records()as$index=>$record){$projected=$definition->projection()->project($record);$records[]=['key'=>$projected['key'],'position'=>$index,'visible'=>true,'data'=>$projected['data']];}
		$canvas=$definition->surface()->advanced()?(new PanelDataCanvasProjector())->project($definition,$records):null;$window=new PanelDataSurfaceWindowResult($definition->id(),$definition->resource(),$definition->surface(),$definition->projection(),$records,$definition->defaultRange(),count($records),false,false,null,null,$canvas);
		$html=PanelDataSurfaceRenderer::render($definition,$window,null,['id'=>'dp-studio-data-surface-'.$definition->id(),'endpoint'=>'']);$title=trim((string)$definition->option('title',''))?:ucwords(str_replace(['_','-','.'],' ',$definition->id()));
		return$this->page(PanelPage::make('studio_visual_'.$definition->id())->label($title)->content($html),$request);
	}
	private function workflow(WorkflowDefinition $workflow,?PanelRequest $request):PanelPageResult{
		$analysis=(new PanelWorkflowSimulator())->analyze($workflow);$html='<section class="dp-studio-workflow-preview" aria-labelledby="dp-studio-workflow-title"><header><h2 id="dp-studio-workflow-title">'.self::e($workflow->label()).'</h2><p>Initial state: <strong>'.self::e($workflow->initialState()).'</strong></p></header><ol class="dp-studio-workflow-states" aria-label="Workflow states">';
		foreach($workflow->states()as$state){$html.='<li data-initial="'.($state->name()===$workflow->initialState()?'true':'false').'" data-terminal="'.($state->terminal()?'true':'false').'"><strong>'.self::e($state->label()).'</strong><span>'.self::e($state->name()).'</span>'.($state->terminal()?'<small>Terminal</small>':'').'</li>';}$html.='</ol><h3>Transitions</h3><ol class="dp-studio-workflow-transitions">';
		foreach($workflow->transitions()as$transition){$label=(string)($transition->metadataValues()['label']??$transition->name());$html.='<li><strong>'.self::e($label).'</strong><span>'.self::e(implode(', ',$transition->from())).' -&gt; '.self::e($transition->to()).'</span>'.($transition->approvalPolicy() instanceof WorkflowApprovalPolicy?'<small>Approval required</small>':'').'</li>';}$html.='</ol><p>'.count($analysis->reachableStates()).' reachable state'.(count($analysis->reachableStates())===1?'':'s').'. '.count($analysis->cycles()).' cycle'.(count($analysis->cycles())===1?'':'s').'.</p></section>';
		return$this->page(PanelPage::make('studio_visual_workflow_'.$workflow->name())->label($workflow->label())->content($html),$request);
	}
	private function workflowState(WorkflowState $state,?PanelRequest $request):PanelPageResult{$html='<section class="dp-studio-workflow-state-preview"><h2>'.self::e($state->label()).'</h2><p>State: <strong>'.self::e($state->name()).'</strong></p><p>'.($state->terminal()?'Terminal':($state->draft()?'Draft':'Active')).'</p></section>';return$this->page(PanelPage::make('studio_visual_workflow_state_'.$state->name())->label($state->label())->content($html),$request);}
	private function workflowTransition(WorkflowTransition $transition,?PanelRequest $request):PanelPageResult{$label=(string)($transition->metadataValues()['label']??ucwords(str_replace('_',' ',$transition->name())));$html='<section class="dp-studio-workflow-transition-preview"><h2>'.self::e($label).'</h2><p>'.self::e(implode(', ',$transition->from())).' -&gt; '.self::e($transition->to()).'</p>'.($transition->approvalPolicy() instanceof WorkflowApprovalPolicy?'<p>Approval required.</p>':'').'</section>';return$this->page(PanelPage::make('studio_visual_workflow_transition_'.$transition->name())->label($label)->content($html),$request);}

	private function collection(PanelStudioBuilderCollection $collection,PanelStudioVisualDataset $dataset,?PanelRequest $request):PanelPageResult{
		$items=$collection->items();
		return match($collection->kind()){
			'filters'=>$this->table(ResourceTable::make()->column(Column::make('name'))->filters(array_values(array_filter($items,static fn(object $item):bool=>$item instanceof TableFilter))),$dataset,$request),
			'table_views'=>$this->table(ResourceTable::make()->column(Column::make('name'))->views(array_values(array_filter($items,static fn(object $item):bool=>$item instanceof TableView))),$dataset,$request),
			'widget_grid'=>$this->page(PanelPage::make('studio_visual_widgets')->label('Widget grid preview')->widgets(array_values(array_filter($items,static fn(object $item):bool=>$item instanceof Widget))),$request),
			'navigation'=>$this->navigation(array_values(array_filter($items,static fn(object $item):bool=>$item instanceof NavigationItem)),$request),
			default=>throw new \LogicException('Studio visual collection kind is unsupported.'),
		};
	}

	/** @param list<NavigationItem> $items */
	private function navigation(array $items,?PanelRequest $request):PanelPageResult{
		$html='<nav class="dp-panel-navigation" aria-label="Studio navigation preview">'.self::navigationItems(array_map(static fn(NavigationItem $item):array=>$item->toArray(),$items)).'</nav>';
		return$this->page(PanelPage::make('studio_visual_navigation')->label('Navigation preview')->content($html),$request);
	}

	/** @param list<array<string,mixed>> $items */
	private static function navigationItems(array $items):string{
		if($items===[]){return'<p>No navigation items.</p>';}$html='<ul>';
		foreach($items as$item){$label=trim((string)($item['label']??$item['name']??''))?:'Navigation item';$description=trim((string)($item['description']??''));$badge=$item['badge']??null;$html.='<li><span><strong>'.self::e($label).'</strong>'.($description!==''?'<small>'.self::e($description).'</small>':'').'</span>'.($badge!==null?'<em>'.self::e((string)$badge).'</em>':'');$children=is_array($item['children']??null)?array_values(array_filter($item['children'],'is_array')):[];if($children!==[]){$html.=self::navigationItems($children);}$html.='</li>';}
		return$html.'</ul>';
	}

	private function request(?PanelRequest $source,string $operation,string $resource):PanelRequest{
		$query=[];$sourceQuery=$source?->query();if(is_array($sourceQuery)){foreach(['q','page','per_page','density','view','group','sort','direction']as$key){$value=$sourceQuery[$key]??null;if(is_scalar($value)||$value===null){if($value!==null){$query[$key]=$value;}}}}
		return PanelRequest::fromArray(['method'=>'GET','resource'=>$resource,'operation'=>$operation,'query'=>$query,'tenant'=>$source?->tenantKey()]);
	}

	/** Removes executable/external assets and embeds only allow-listed first-party Panel CSS for opaque-origin srcdoc frames. */
	private static function selfContained(PanelPageResult $result):PanelPageResult{
		$content=(string)preg_replace('#<script\b[^>]*>.*?</script\s*>#is','',$result->content());
		$content=(string)preg_replace_callback('#<link\b[^>]*>#i',static fn(array $match):string=>self::inlineStyleTag((string)($match[0]??'')),$content);
		$content=(string)preg_replace('#<base\b[^>]*>#i','',$content);
		return new PanelPageResult($content,$result->status(),$result->headers(),$result->data(),$result->notifications(),$result->redirectTo());
	}

	private static function inlineStyleTag(string $tag):string{
		if(strtolower(self::tagAttribute($tag,'rel'))!=='stylesheet'){return'';}
		$url=self::tagAttribute($tag,'href');$query=[];parse_str((string)(parse_url($url,PHP_URL_QUERY)?:''),$query);$path=(string)(parse_url($url,PHP_URL_PATH)?:'');
		$asset=trim((string)($query['operation']??$query['dp_panel_asset']??basename($path)));
		$physicalStyle=preg_match('/\Apanel-style-[a-z0-9][a-z0-9-]{0,63}\.css\z/',$asset)===1;
		if(!in_array($asset,['panel.css','panel-platform.css'],true)&&!$physicalStyle){return'';}
		$token=is_scalar($query['dp_panel_caps']??null)?trim((string)$query['dp_panel_caps']):'';$capabilities=$token===''?null:PanelAssetCapabilityManifest::decodeToken($token);if($token!==''&&$capabilities===null){return'';}
		$payload=PanelRenderer::assetContent($asset,($asset==='panel.css'||$physicalStyle)?$capabilities:null);if($payload===null||!str_starts_with((string)$payload['content_type'],'text/css')){return'';}
		$css=str_ireplace('</style','<\\/style',(string)$payload['content']);return'<style data-dp-studio-asset="'.self::e($asset).'">'.$css.'</style>';
	}

	private static function tagAttribute(string $tag,string $name):string{
		if(preg_match('/\b'.preg_quote($name,'/').'\s*=\s*(["\'])(.*?)\1/is',$tag,$match)!==1){return'';}
		return html_entity_decode((string)$match[2],ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8');
	}

	private static function resource():Resource{return Resource::make('studio_visual_preview')->label('Studio preview')->pluralLabel('Studio preview records')->recordKeyUsing('id')->recordTitleUsing('title')->recordSubtitleUsing('name');}

	/** @return list<object> */
	private function descendants(PanelStudioMaterialization $materialization,string $path,string $class):array{$items=[];$prefix=$path.'.children[';foreach($materialization->builders()as$candidatePath=>$candidate){if(str_starts_with($candidatePath,$prefix)&&$candidate instanceof $class){$items[]=$candidate;}}return$items;}

	private static function structuralPath(PanelStudioDefinition $definition,string $keyPath):string{
		$segments=explode('/',$keyPath);$node=$definition->root();if(array_shift($segments)!==$node['key']){throw new \LogicException('Studio visual selection does not begin at the definition root.');}$path='root';
		foreach($segments as$segment){$found=false;foreach($node['children']as$index=>$child){if($child['key']===$segment){$path.='.children['.$index.']';$node=$child;$found=true;break;}}if(!$found){throw new \LogicException('Studio visual selection cannot be resolved in the definition.');}}
		return$path;
	}

	private static function label(PanelStudioDefinition $definition,string $path,string $symbol):string{
		$node=$definition->root();if($path!=='root'){preg_match_all('/\.children\[(\d+)\]/',$path,$matches);foreach($matches[1]as$index){$node=$node['children'][(int)$index]??$node;}}
		$label=trim((string)($node['properties']['label']??''));return$label!==''?$label:ucwords(str_replace(['_','-','.'],' ',(string)($node['key']??$symbol)));
	}
	private static function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8');}
}
