<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Consumable page root containing the registered PanelPage and its typed materialized surfaces. */
final class PanelStudioPageBundle implements \JsonSerializable {
	/** @var array<string,object> */ private readonly array $surfaces;
	public function __construct(private readonly PanelPage $page,array $surfaces){foreach($surfaces as$path=>$surface){if(!is_string($path)||preg_match('/^root\.children\[\d+\]$/',$path)!==1||!is_object($surface)||$surface instanceof \Closure){throw new \InvalidArgumentException('Studio page bundle surfaces are invalid.');}}ksort($surfaces,SORT_STRING);$this->surfaces=$surfaces;}
	public function page():PanelPage{return$this->page;}
	public function surfaces():array{return$this->surfaces;}
	public function surface(string $path):?object{return$this->surfaces[$path]??null;}
	/** @return list<ResourceForm> */ public function forms():array{return$this->of(ResourceForm::class);}
	/** @return list<ResourceTable> */ public function tables():array{return$this->of(ResourceTable::class);}
	/** @return list<Infolist> */ public function infolists():array{return$this->of(Infolist::class);}
	/** @return list<PanelDataSurfaceDefinition> */ public function dataSurfaces():array{return$this->of(PanelDataSurfaceDefinition::class);}
	/** @return list<WorkflowDefinition> */ public function workflows():array{return$this->of(WorkflowDefinition::class);}
	/** @return list<Resource> */ public function resources():array{return$this->of(Resource::class);}
	/** @return list<ActionGroup> */ public function actionGroups():array{return$this->of(ActionGroup::class);}
	/** @return list<PanelStudioBuilderCollection> */ public function collections():array{return$this->of(PanelStudioBuilderCollection::class);}
	public function register(PanelInstance|PanelManager $host):PanelPage{return$host->registerPage($this->page);}
	/** @return list<Resource> */ public function registerResources(PanelInstance|PanelManager $host):array{$registered=[];foreach($this->resources()as$resource){$registered[]=$host->register($resource);}return$registered;}
	public function registerDataSurfaces(PanelDataSurfaceRegistry $registry,bool $replace=false):PanelDataSurfaceRegistry{foreach($this->dataSurfaces()as$surface){$registry->register($surface,$replace);}return$registry;}
	public function registerWorkflows(WorkflowEngine $engine):WorkflowEngine{foreach($this->workflows()as$workflow){$engine->register($workflow);}return$engine;}
	public function registerAll(PanelInstance|PanelManager $host,?PanelDataSurfaceRegistry $dataSurfaces=null,bool $replaceDataSurfaces=false,?WorkflowEngine $workflows=null):PanelPage{$this->registerResources($host);if($dataSurfaces instanceof PanelDataSurfaceRegistry){$this->registerDataSurfaces($dataSurfaces,$replaceDataSurfaces);}if($workflows instanceof WorkflowEngine){$this->registerWorkflows($workflows);}return$this->register($host);}
	public function jsonSerialize():array{$kinds=[];foreach($this->surfaces as$path=>$surface){$symbol=match(true){$surface instanceof ResourceForm=>'resource_form',$surface instanceof ResourceTable=>'resource_table',$surface instanceof PanelDataSurfaceDefinition=>'data_surface_definition',$surface instanceof WorkflowDefinition=>'workflow_definition',$surface instanceof Infolist=>'infolist',$surface instanceof Resource=>'resource_board',$surface instanceof ActionGroup=>'action_group',$surface instanceof Schema=>'schema',$surface instanceof PanelStudioBuilderCollection=>'builder_collection',default=>'panel_builder'};$kinds[$path]=$symbol;}return['type'=>'panel_studio_page_bundle','version'=>3,'page_name'=>$this->page->name(),'surface_count'=>count($this->surfaces),'surfaces'=>$kinds,'resource_count'=>count($this->resources()),'data_surface_count'=>count($this->dataSurfaces()),'workflow_count'=>count($this->workflows()),'runtime'=>['host_registerable'=>true,'resource_registerable'=>true,'data_surface_registerable'=>true,'workflow_registerable'=>true,'objects_serialized'=>false]];}
	private function of(string $class):array{$items=[];foreach($this->surfaces as$surface){if($surface instanceof $class){$items[]=$surface;}}return$items;}
}
