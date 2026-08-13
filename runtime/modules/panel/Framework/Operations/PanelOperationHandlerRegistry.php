<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Runtime registry that resolves persisted operation types to executable handlers. */
final class PanelOperationHandlerRegistry implements PanelCheckpointableService, \JsonSerializable {
	private const MAX_HANDLERS=4096;

	/** @var array<string, \Closure> */
	private array $handlers=[];
	private int $revision=0;
	private readonly string $checkpointOwner;
	public function __construct(){$this->checkpointOwner=bin2hex(random_bytes(16));}

	public function register(string $type, callable $handler, bool $replace=false): self {
		$type=$this->type($type);
		if(isset($this->handlers[$type]) && !$replace){ throw new \LogicException("Panel operation handler '{$type}' is already registered."); }
		if(!isset($this->handlers[$type]) && count($this->handlers)>=self::MAX_HANDLERS){ throw new \OverflowException('Panel operation handler registry capacity is exhausted.'); }
		$this->handlers[$type]=\Closure::fromCallable($handler);
		$this->revision++;
		return $this;
	}

	public function forget(string $type): self { $type=$this->type($type);if(isset($this->handlers[$type])){unset($this->handlers[$type]);$this->revision++;}return $this; }
	public function has(string $type): bool { return isset($this->handlers[$this->type($type)]); }
	public function resolve(string $type): \Closure {
		$type=$this->type($type);
		return $this->handlers[$type] ?? throw new \OutOfBoundsException("No Panel operation handler is registered for '{$type}'.");
	}
	/** @return list<string> */
	public function types(): array { $types=array_keys($this->handlers); sort($types, SORT_STRING); return $types; }
	public function revision():int{return$this->revision;}
	public function checkpointType():string{return'panel_operation_handler_registry_v1';}
	/** @return array{owner:string,handlers:array<string,\Closure>,revision:int,digest:string} */
	public function checkpoint():array{return['owner'=>$this->checkpointOwner,'handlers'=>$this->handlers,'revision'=>$this->revision,'digest'=>$this->checkpointDigest($this->handlers,$this->revision)];}
	/** @param array<string,mixed> $checkpoint */
	public function restore(array $checkpoint):self{
		if(array_keys($checkpoint)!==['owner','handlers','revision','digest']||!is_string($checkpoint['owner'])||!hash_equals($this->checkpointOwner,$checkpoint['owner'])||!is_array($checkpoint['handlers'])||count($checkpoint['handlers'])>self::MAX_HANDLERS||!is_int($checkpoint['revision'])||$checkpoint['revision']<0||!is_string($checkpoint['digest'])){throw new \InvalidArgumentException('Invalid Panel operation handler registry checkpoint.');}
		foreach($checkpoint['handlers']as$type=>$handler){if(!is_string($type)||$this->type($type)!==$type||!$handler instanceof \Closure){throw new \InvalidArgumentException('Invalid Panel operation handler registry checkpoint.');}}
		if(!hash_equals($this->checkpointDigest($checkpoint['handlers'],$checkpoint['revision']),$checkpoint['digest'])){throw new \InvalidArgumentException('Invalid Panel operation handler registry checkpoint.');}
		$this->handlers=$checkpoint['handlers'];$this->revision=$checkpoint['revision'];return$this;
	}
	/** @return array{type:string,count:int,types:list<string>,revision:int} */
	public function jsonSerialize(): array { return ['type'=>'panel_operation_handler_registry', 'count'=>count($this->handlers), 'types'=>$this->types(),'revision'=>$this->revision]; }
	/** @param array<string,\Closure> $handlers */ private function checkpointDigest(array $handlers,int $revision):string{$identities=[];foreach($handlers as$type=>$handler){$identities[$type]=spl_object_id($handler);}return hash('sha256',json_encode(['owner'=>$this->checkpointOwner,'handlers'=>$identities,'revision'=>$revision],JSON_THROW_ON_ERROR));}

	private function type(string $type): string {
		$type=strtolower(trim($type));
		$type=preg_replace('/[^a-z0-9]+/', '_', $type) ?? '';
		$type=trim($type, '_');
		if($type===''){ throw new \InvalidArgumentException('Panel operation handler type cannot be blank.'); }
		return substr($type, 0, 100);
	}
}
