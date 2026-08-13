<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Strict, bounded public request accepted by widget runtime adapters. */
final class PanelWidgetInteractionRequest implements \JsonSerializable {
	public const SCHEMA_VERSION=1;
	private const OPERATIONS=['mount','hydrate','action','refresh','unmount'];
	private const KEYS=['schema_version','operation','island_id','action','payload','expected_version','idempotency_key','snapshot','binding_tag'];
	private const OPERATION_KEYS=[
		'mount'=>['schema_version','operation','island_id','idempotency_key'],
		'hydrate'=>['schema_version','operation','island_id','idempotency_key','snapshot','binding_tag'],
		'action'=>['schema_version','operation','island_id','action','payload','expected_version','idempotency_key','snapshot','binding_tag'],
		'refresh'=>['schema_version','operation','island_id','expected_version','idempotency_key','snapshot','binding_tag'],
		'unmount'=>['schema_version','operation','island_id','expected_version','idempotency_key','snapshot','binding_tag'],
	];

	/** @param array<string,mixed> $payload */
	private function __construct(
		private readonly string $operation,
		private readonly string $islandId,
		private readonly ?string $action,
		private readonly array $payload,
		private readonly ?int $expectedVersion,
		private readonly string $idempotencyKey,
		private readonly ?string $snapshot,
		private readonly ?string $bindingTag
	){}

	public static function mount(string $islandId, string $idempotencyKey): self {
		return self::fromArray([
			'operation'=>'mount',
			'island_id'=>$islandId,
			'idempotency_key'=>$idempotencyKey,
		]);
	}

	/** @param array<string,mixed> $request */
	public static function fromArray(array $request): self {
		if(array_is_list($request)){ throw new \InvalidArgumentException('Widget interaction requests must be object-like maps.'); }
		$unknown=array_diff(array_keys($request), self::KEYS);
		if($unknown!==[]){ throw new \InvalidArgumentException('Unknown widget interaction request key: '.(string)reset($unknown)); }
		$version=$request['schema_version'] ?? self::SCHEMA_VERSION;
		if(!is_int($version) || $version!==self::SCHEMA_VERSION){ throw new \UnexpectedValueException('Unsupported widget interaction request version.'); }
		$operation=is_string($request['operation'] ?? null) ? strtolower(trim($request['operation'])) : '';
		if(!in_array($operation, self::OPERATIONS, true)){ throw new \InvalidArgumentException('Widget interaction operation is not supported.'); }
		$ignored=array_diff(array_keys($request), self::OPERATION_KEYS[$operation]);
		if($ignored!==[]){ throw new \InvalidArgumentException('Widget interaction operation "'.$operation.'" does not accept key: '.(string)reset($ignored)); }
		$islandId=PanelWidgetInteractionValue::safeIdentifier(is_string($request['island_id'] ?? null) ? $request['island_id'] : '', 'Widget island id', 96);
		$action=null;
		if(array_key_exists('action', $request) && $request['action']!==null){
			if(!is_string($request['action'])){ throw new \InvalidArgumentException('Widget action names must be strings.'); }
			$action=PanelWidgetInteractionValue::safeIdentifier($request['action'], 'Widget action', 64);
		}
		if($operation==='action' && $action===null){ throw new \InvalidArgumentException('Widget action requests require a named action.'); }
		if($operation!=='action' && $action!==null){ throw new \InvalidArgumentException('Only widget action requests may carry an action name.'); }
		$payload=$request['payload'] ?? [];
		if(!is_array($payload)){ throw new \InvalidArgumentException('Widget interaction payloads must be maps.'); }
		$payload=PanelWidgetInteractionValue::assertMap($payload, 'widget interaction payload');
		$expected=$request['expected_version'] ?? null;
		if($expected!==null && (!is_int($expected) || $expected<1)){ throw new \InvalidArgumentException('Widget expected versions must be positive integers.'); }
		if(in_array($operation, ['action','refresh','unmount'], true) && $expected===null){
			throw new \InvalidArgumentException('Mutating widget interaction requests require an expected version.');
		}
		$idempotency=PanelWidgetInteractionValue::boundedString(is_string($request['idempotency_key'] ?? null) ? $request['idempotency_key'] : '', 'Widget idempotency key', 128);
		$snapshot=self::optionalOpaque($request['snapshot'] ?? null, 'Widget snapshot', 8192);
		$binding=self::optionalOpaque($request['binding_tag'] ?? null, 'Widget binding tag', 160);
		if($operation!=='mount' && ($snapshot===null || $binding===null)){
			throw new \InvalidArgumentException('Mounted widget requests require an adapter snapshot and public binding tag.');
		}
		return new self($operation, $islandId, $action, $payload, $expected, $idempotency, $snapshot, $binding);
	}

	public function operation(): string { return $this->operation; }
	public function islandId(): string { return $this->islandId; }
	public function action(): ?string { return $this->action; }
	/** @return array<string,mixed> */ public function payload(): array { return $this->payload; }
	public function expectedVersion(): ?int { return $this->expectedVersion; }
	public function idempotencyKey(): string { return $this->idempotencyKey; }
	public function snapshot(): ?string { return $this->snapshot; }
	public function bindingTag(): ?string { return $this->bindingTag; }

	/** @return array<string,mixed> */
	public function toArray(): array {
		$request=[
			'schema_version'=>self::SCHEMA_VERSION,
			'operation'=>$this->operation,
			'island_id'=>$this->islandId,
			'idempotency_key'=>$this->idempotencyKey,
		];
		if($this->operation==='action'){
			$request['action']=$this->action;
			$request['payload']=$this->payload;
		}
		if(in_array($this->operation, ['action','refresh','unmount'], true)){ $request['expected_version']=$this->expectedVersion; }
		if($this->operation!=='mount'){
			$request['snapshot']=$this->snapshot;
			$request['binding_tag']=$this->bindingTag;
		}
		return $request;
	}

	public function jsonSerialize(): array { return $this->toArray(); }
	public function fingerprint(): string { return hash('sha256', PanelWidgetInteractionValue::canonical($this->toArray())); }

	private static function optionalOpaque(mixed $value, string $label, int $max): ?string {
		if($value===null){ return null; }
		if(!is_string($value)){ throw new \InvalidArgumentException($label.' must be a string.'); }
		return PanelWidgetInteractionValue::boundedString($value, $label, $max);
	}
}
