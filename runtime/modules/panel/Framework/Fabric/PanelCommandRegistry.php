<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Layered exact/prefix command routing with provenance and rollback checkpoints. */
final class PanelCommandRegistry implements PanelCheckpointableService,\JsonSerializable {
	/** @var array<string,list<array{handler:\Closure,contributor:string,priority:int,order:int}>> */
	private array $routes=[];
	private int $revision=0;
	private int $order=0;
	private readonly string $owner;

	public function __construct(private readonly string $conflictPolicy='deny'){
		if(!in_array($conflictPolicy,['deny','priority'],true)){
			throw new \InvalidArgumentException('Command registry conflict policy is invalid.');
		}
		$this->owner=bin2hex(random_bytes(16));
	}

	public function register(string $pattern,PanelCommandHandler|callable $handler,string $contributor='application',int $priority=0):self {
		$pattern=$this->pattern($pattern);
		$contributor=PanelOperationsGuard::name($contributor,'command route contributor',128);
		if($priority<-1000||$priority>1000){throw new \InvalidArgumentException('Command route priority is invalid.');}
		$existing=$this->routes[$pattern]??[];
		if($existing!==[]&&$this->conflictPolicy==='deny'){
			throw new \LogicException("Command route '{$pattern}' is already registered.");
		}
		foreach($existing as $route){
			if($route['contributor']===$contributor||($this->conflictPolicy==='priority'&&$route['priority']===$priority)){
				throw new \LogicException("Command route '{$pattern}' has an ambiguous contribution.");
			}
		}
		$closure=$handler instanceof PanelCommandHandler
			? \Closure::fromCallable([$handler,'handle'])
			: \Closure::fromCallable($handler);
		$this->routes[$pattern][]=[
			'handler'=>$closure,'contributor'=>$contributor,'priority'=>$priority,'order'=>++$this->order,
		];
		$this->revision++;
		return $this;
	}

	public function unregisterContributor(string $contributor):self {
		$contributor=PanelOperationsGuard::name($contributor,'command route contributor',128);
		$changed=false;
		foreach($this->routes as $pattern=>$routes){
			$next=array_values(array_filter($routes,static fn(array $route):bool=>$route['contributor']!==$contributor));
			if(count($next)!==count($routes)){$changed=true;}
			if($next===[]){unset($this->routes[$pattern]);}else{$this->routes[$pattern]=$next;}
		}
		if($changed){$this->revision++;}
		return $this;
	}

	/** @return array{handler:\Closure,pattern:string,contributor:string,priority:int} */
	public function resolve(string $command):array {
		$command=PanelOperationsGuard::name($command,'routed command',160);
		$matches=[];
		foreach($this->routes as $pattern=>$routes){
			if(!$this->matches($pattern,$command)){continue;}
			foreach($routes as $route){
				$matches[]=$route+['pattern'=>$pattern,'specificity'=>$pattern==='*'?0:strlen(rtrim($pattern,'*'))];
			}
		}
		if($matches===[]){throw new \OutOfBoundsException("No command handler is registered for '{$command}'.");}
		usort($matches,static fn(array $a,array $b):int=>
			[$b['specificity'],$b['priority'],$b['order']]<=>[$a['specificity'],$a['priority'],$a['order']]
		);
		$winner=$matches[0];
		return ['handler'=>$winner['handler'],'pattern'=>$winner['pattern'],'contributor'=>$winner['contributor'],'priority'=>$winner['priority']];
	}

	public function revision():int{return $this->revision;}
	public function checkpointType():string{return 'panel_command_registry_v1';}

	/** @return array<string,mixed> */
	public function checkpoint():array {
		return [
			'owner'=>$this->owner,'routes'=>$this->routes,'revision'=>$this->revision,'order'=>$this->order,
			'digest'=>$this->checkpointDigest($this->routes,$this->revision,$this->order),
		];
	}

	/** @param array<string,mixed> $checkpoint */
	public function restore(array $checkpoint):self {
		if(
			array_keys($checkpoint)!==['owner','routes','revision','order','digest']
			||!is_string($checkpoint['owner']??null)
			||!hash_equals($this->owner,$checkpoint['owner'])
			||!is_array($checkpoint['routes']??null)
			||!is_int($checkpoint['revision']??null)||$checkpoint['revision']<0
			||!is_int($checkpoint['order']??null)||$checkpoint['order']<0
			||!is_string($checkpoint['digest']??null)
			||!hash_equals($checkpoint['digest'],$this->checkpointDigest($checkpoint['routes'],$checkpoint['revision'],$checkpoint['order']))
		){
			throw new \InvalidArgumentException('Command registry checkpoint is invalid.');
		}
		foreach($checkpoint['routes'] as $pattern=>$routes){
			$this->pattern((string)$pattern);
			if(!is_array($routes)||!array_is_list($routes)){
				throw new \InvalidArgumentException('Command registry checkpoint routes are invalid.');
			}
			foreach($routes as $route){
				if(
					!is_array($route)||array_keys($route)!==['handler','contributor','priority','order']
					||!($route['handler'] instanceof \Closure)
					||!is_string($route['contributor'])||!is_int($route['priority'])||!is_int($route['order'])
				){
					throw new \InvalidArgumentException('Command registry checkpoint route is invalid.');
				}
			}
		}
		$this->routes=$checkpoint['routes'];
		$this->revision=$checkpoint['revision'];
		$this->order=$checkpoint['order'];
		return $this;
	}

	public function jsonSerialize():array {
		$routes=[];
		foreach($this->routes as $pattern=>$layers){
			$routes[$pattern]=array_map(static fn(array $route):array=>[
				'contributor'=>$route['contributor'],'priority'=>$route['priority'],'order'=>$route['order'],
			],$layers);
		}
		ksort($routes,SORT_STRING);
		return [
			'type'=>'panel_command_registry','version'=>1,'revision'=>$this->revision,
			'conflict_policy'=>$this->conflictPolicy,'routes'=>$routes,'handlers_exposed'=>false,
		];
	}

	private function pattern(string $pattern):string {
		$pattern=strtolower(trim($pattern));
		if($pattern!=='*'&&preg_match('/^[a-z][a-z0-9_.-]*(?:\.\*)?$/D',$pattern)!==1){
			throw new \InvalidArgumentException('Command route pattern is invalid.');
		}
		return $pattern;
	}

	private function matches(string $pattern,string $command):bool {
		return $pattern==='*'||$pattern===$command||(str_ends_with($pattern,'.*')&&str_starts_with($command,substr($pattern,0,-1)));
	}

	/** @param array<string,mixed> $routes */
	private function checkpointDigest(array $routes,int $revision,int $order):string {
		$values=[];
		foreach($routes as $pattern=>$items){
			if(!is_array($items)){continue;}
			foreach($items as $item){
				$handler=is_array($item)&&($item['handler']??null) instanceof \Closure?$item['handler']:null;
				$values[(string)$pattern][]=[
					'handler'=>$handler instanceof \Closure?spl_object_id($handler):null,
					'contributor'=>is_array($item)?($item['contributor']??null):null,
					'priority'=>is_array($item)?($item['priority']??null):null,
					'order'=>is_array($item)?($item['order']??null):null,
				];
			}
		}
		return PanelOperationsGuard::digest(['owner'=>$this->owner,'routes'=>$values,'revision'=>$revision,'order'=>$order]);
	}
}
