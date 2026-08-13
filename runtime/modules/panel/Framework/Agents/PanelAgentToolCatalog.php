<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Instance-owned layered catalog with explicit provenance and collision policy. */
final class PanelAgentToolCatalog implements PanelCheckpointableService, \JsonSerializable {
	private const MAX_TOOLS=512;
	private const MAX_LAYERS=2048;
	/** @var array<string,list<array{tool:PanelAgentTool,executor:PanelAgentToolExecutor,contributor:string,priority:int,order:int}>> */
	private array $layers=[];
	private int $revision=0;
	private int $order=0;
	private string $checkpointOwner;

	public function __construct(private readonly string $conflictPolicy='deny') {
		if(!in_array($conflictPolicy, ['deny','replace','priority'], true)){ throw new \InvalidArgumentException('Panel agent catalog conflict policy is invalid.'); }
		$this->checkpointOwner=bin2hex(random_bytes(16));
	}

	public function register(PanelAgentTool $tool, PanelAgentToolExecutor $executor, string $contributor, int $priority=0): self {
		$contributor=PanelAgentGuard::identifier($contributor, 'tool contributor', 128);
		if($priority<-1000 || $priority>1000){ throw new \InvalidArgumentException('Panel agent tool priority must be between -1000 and 1000.'); }
		$name=$tool->name(); $existing=$this->layers[$name] ?? [];
		if($existing!==[] && $this->conflictPolicy==='deny'){ throw new \LogicException("Panel agent tool '{$name}' is already registered."); }
		foreach($existing as $layer){
			if($layer['contributor']===$contributor){ throw new \LogicException("Panel agent contributor '{$contributor}' already registered tool '{$name}'."); }
			if($this->conflictPolicy==='priority' && $layer['priority']===$priority){ throw new \LogicException("Panel agent tool '{$name}' has an ambiguous priority."); }
		}
		if(!isset($this->layers[$name]) && count($this->layers)>=self::MAX_TOOLS){ throw new \LengthException('Panel agent tool catalog exceeds its tool limit.'); }
		if($this->layerCount()>=self::MAX_LAYERS){ throw new \LengthException('Panel agent tool catalog exceeds its layer limit.'); }
		$this->layers[$name][]=['tool'=>$tool,'executor'=>$executor,'contributor'=>$contributor,'priority'=>$priority,'order'=>++$this->order];
		$this->revision++;
		return $this;
	}

	public function unregisterContributor(string $contributor): self {
		$contributor=PanelAgentGuard::identifier($contributor, 'tool contributor', 128); $changed=false;
		foreach($this->layers as $name=>$layers){
			$remaining=array_values(array_filter($layers, static fn(array $layer): bool=>$layer['contributor']!==$contributor));
			if(count($remaining)!==count($layers)){ $changed=true; }
			if($remaining===[]){ unset($this->layers[$name]); } else { $this->layers[$name]=$remaining; }
		}
		if($changed){ $this->revision++; }
		return $this;
	}

	public function revision(): int { return $this->revision; }
	public function conflictPolicy(): string { return $this->conflictPolicy; }
	public function has(string $name, bool $includeHidden=false): bool { return $this->tool($name, $includeHidden) instanceof PanelAgentTool; }
	public function tool(string $name, bool $includeHidden=false): ?PanelAgentTool {
		$registration=$this->active($name);
		if($registration===null || (!$includeHidden && $registration['tool']->hidden())){ return null; }
		return $registration['tool'];
	}
	public function executor(string $name, bool $includeHidden=false): ?PanelAgentToolExecutor {
		$registration=$this->active($name);
		if($registration===null || (!$includeHidden && $registration['tool']->hidden())){ return null; }
		return $registration['executor'];
	}
	public function contributor(string $name, bool $includeHidden=false): ?string {
		$registration=$this->active($name);
		if($registration===null || (!$includeHidden && $registration['tool']->hidden())){ return null; }
		return $registration['contributor'];
	}

	/** @return array<string,PanelAgentTool> */
	public function tools(bool $includeHidden=false): array {
		$result=[];
		foreach(array_keys($this->layers) as $name){ $tool=$this->tool($name, $includeHidden); if($tool instanceof PanelAgentTool){ $result[$name]=$tool; } }
		ksort($result, SORT_STRING);
		return $result;
	}

	public function fingerprint(): string {
		$active=[];
		foreach($this->tools(true) as $name=>$tool){ $active[$name]=['fingerprint'=>$tool->fingerprint(),'contributor'=>$this->contributor($name, true)]; }
		return hash('sha256', PanelAgentGuard::canonicalJson(['policy'=>$this->conflictPolicy,'active'=>$active]));
	}

	public function checkpointType(): string { return 'panel_agent_tool_catalog_v2'; }
	public function checkpoint(): array {
		return ['type'=>$this->checkpointType(),'owner'=>$this->checkpointOwner,'revision'=>$this->revision,'order'=>$this->order,'layers'=>$this->layers,'digest'=>$this->snapshotDigest($this->layers,$this->revision,$this->order)];
	}
	public function restore(array $checkpoint): PanelCheckpointableService {
		if(array_keys($checkpoint)!==['type','owner','revision','order','layers','digest'] || $checkpoint['type']!==$this->checkpointType() || $checkpoint['owner']!==$this->checkpointOwner || !is_int($checkpoint['revision']) || $checkpoint['revision']<0 || !is_int($checkpoint['order']) || $checkpoint['order']<0 || $checkpoint['revision']<$checkpoint['order'] || !is_array($checkpoint['layers']) || !is_string($checkpoint['digest']) || preg_match('/^[a-f0-9]{64}$/D',$checkpoint['digest'])!==1){
			throw new \InvalidArgumentException('Panel agent tool catalog checkpoint is invalid.');
		}
		$layers=$this->validatedCheckpointLayers($checkpoint['layers'],$checkpoint['order']);
		$digest=$this->snapshotDigest($layers,$checkpoint['revision'],$checkpoint['order']);
		if(!hash_equals($checkpoint['digest'],$digest)){ throw new \InvalidArgumentException('Panel agent tool catalog checkpoint integrity check failed.'); }
		$this->layers=$layers; $this->revision=$checkpoint['revision']; $this->order=$checkpoint['order'];
		return $this;
	}

	public function jsonSerialize(): array {
		$tools=[];
		foreach($this->tools() as $name=>$tool){
			$manifest=$tool->manifest(); $manifest['provenance']=['contributor'=>$this->contributor($name),'layer_count'=>count($this->layers[$name])]; $tools[]=$manifest;
		}
		return [
			'type'=>'panel_agent_tool_catalog','version'=>1,'revision'=>$this->revision,'conflict_policy'=>$this->conflictPolicy,
			'fingerprint'=>$this->fingerprint(),'tool_count'=>count($tools),'layer_count'=>$this->layerCount(),'tools'=>$tools,
			'hidden_tools_omitted'=>count($this->tools(true))-count($tools),'executors_exposed'=>false,'secrets_exposed'=>false,
		];
	}

	/** @return ?array{tool:PanelAgentTool,executor:PanelAgentToolExecutor,contributor:string,priority:int,order:int} */
	private function active(string $name): ?array {
		$name=PanelAgentGuard::identifier($name, 'tool', 128); $layers=$this->layers[$name] ?? [];
		if($layers===[]){ return null; }
		usort($layers, function(array $left, array $right): int {
			if($this->conflictPolicy==='priority'){ return $right['priority']<=>$left['priority'] ?: $right['order']<=>$left['order']; }
			return $right['order']<=>$left['order'];
		});
		return $layers[0];
	}

	private function layerCount(): int { return array_sum(array_map('count', $this->layers)); }
	/** @param array<mixed> $layers @return array<string,list<array{tool:PanelAgentTool,executor:PanelAgentToolExecutor,contributor:string,priority:int,order:int}>> */
	private function validatedCheckpointLayers(array $layers, int $order): array {
		if(array_is_list($layers) && $layers!==[]){ throw new \InvalidArgumentException('Panel agent tool catalog checkpoint layers must be an object-like map.'); }
		if(count($layers)>self::MAX_TOOLS){ throw new \InvalidArgumentException('Panel agent tool catalog checkpoint exceeds its tool limit.'); }
		$total=0; $orders=[];
		foreach($layers as $name=>$registrations){
			if(!is_string($name) || PanelAgentGuard::identifier($name,'checkpoint tool',128)!==$name || !is_array($registrations) || !array_is_list($registrations) || $registrations===[]){ throw new \InvalidArgumentException('Panel agent tool catalog checkpoint tool layers are invalid.'); }
			$total+=count($registrations); if($total>self::MAX_LAYERS){ throw new \InvalidArgumentException('Panel agent tool catalog checkpoint exceeds its layer limit.'); }
			$contributors=[]; $priorities=[];
			foreach($registrations as $registration){
				if(!is_array($registration) || array_keys($registration)!==['tool','executor','contributor','priority','order'] || !$registration['tool'] instanceof PanelAgentTool || !$registration['executor'] instanceof PanelAgentToolExecutor || !is_string($registration['contributor']) || !is_int($registration['priority']) || !is_int($registration['order'])){ throw new \InvalidArgumentException('Panel agent tool catalog checkpoint registration is invalid.'); }
				$contributor=PanelAgentGuard::identifier($registration['contributor'],'checkpoint contributor',128);
				if($registration['tool']->name()!==$name || $contributor!==$registration['contributor'] || $registration['priority']<-1000 || $registration['priority']>1000 || $registration['order']<1 || $registration['order']>$order || isset($contributors[$contributor]) || isset($orders[$registration['order']])){ throw new \InvalidArgumentException('Panel agent tool catalog checkpoint registration invariants are invalid.'); }
				if($this->conflictPolicy==='priority' && isset($priorities[$registration['priority']])){ throw new \InvalidArgumentException('Panel agent tool catalog checkpoint priorities are ambiguous.'); }
				$contributors[$contributor]=true; $priorities[$registration['priority']]=true; $orders[$registration['order']]=true;
			}
			if($this->conflictPolicy==='deny' && count($registrations)!==1){ throw new \InvalidArgumentException('Panel agent deny catalog checkpoint contains layered tools.'); }
		}
		return $layers;
	}
	/** @param array<string,list<array{tool:PanelAgentTool,executor:PanelAgentToolExecutor,contributor:string,priority:int,order:int}>> $layers */
	private function snapshotDigest(array $layers, int $revision, int $order): string {
		$manifest=[];
		foreach($layers as $name=>$registrations){ foreach($registrations as $registration){ $manifest[$name][]=['tool'=>$registration['tool']->fingerprint(),'executor'=>spl_object_id($registration['executor']),'contributor'=>$registration['contributor'],'priority'=>$registration['priority'],'order'=>$registration['order']]; } }
		return hash('sha256',PanelAgentGuard::canonicalJson(['policy'=>$this->conflictPolicy,'revision'=>$revision,'order'=>$order,'layers'=>$manifest]));
	}
}
