<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Exact server-owned write contract; it never changes the read-only HTTP capability pin. */
final class PanelHttpDataMutationCapabilityPin implements \JsonSerializable {
	private const KEYS=['adapter','mutations','mutation_operations','mutation_batch','mutation_atomic_batch','mutation_max_batch','mutation_optimistic_concurrency','mutation_idempotency','mutation_idempotency_scope','mutation_tenant','mutation_authorization','mutation_returning','stable_record_keys','record_key_field'];
	/** @var array<string,mixed> */private readonly array $capabilities;
	private readonly string $fingerprint;

	/** @param array<string,mixed> $capabilities */
	private function __construct(private readonly int $version,array $capabilities){
		if($version<1||$version>1000000){throw new \InvalidArgumentException('Remote mutation capability versions must be between 1 and 1000000.');}
		PanelHttpDataSourceValue::exactKeys($capabilities,self::KEYS,'Remote mutation capability pin');
		if(($capabilities['adapter']??null)!=='http_remote_mutation'||($capabilities['mutations']??null)!==true||($capabilities['mutation_idempotency']??null)!==true||($capabilities['mutation_authorization']??null)!==true||($capabilities['stable_record_keys']??null)!==true){throw new \InvalidArgumentException('Remote mutation pins require writable, idempotent, authorized stable-key semantics.');}
		foreach(['mutation_batch','mutation_atomic_batch','mutation_optimistic_concurrency','mutation_tenant','mutation_returning']as$key){if(!is_bool($capabilities[$key])){throw new \InvalidArgumentException("Remote mutation capability '{$key}' must be boolean.");}}
		if($capabilities['mutation_optimistic_concurrency']!==true){throw new \InvalidArgumentException('Remote mutations require optimistic concurrency.');}
		if($capabilities['mutation_atomic_batch']&&!$capabilities['mutation_batch']){throw new \InvalidArgumentException('Remote atomic mutation batches require batch support.');}
		$operations=$capabilities['mutation_operations'];if(!is_array($operations)||!array_is_list($operations)||$operations===[]){throw new \InvalidArgumentException('Remote mutation operations must be a non-empty list.');}
		$clean=[];foreach($operations as$operation){if(!is_string($operation)||!in_array($operation,PanelDataMutation::OPERATIONS,true)){throw new \InvalidArgumentException('Remote mutation operation is invalid.');}$clean[$operation]=true;}$capabilities['mutation_operations']=array_keys($clean);
		$max=$capabilities['mutation_max_batch'];if(!is_int($max)||$max<1||$max>100||(!$capabilities['mutation_batch']&&$max!==1)){throw new \InvalidArgumentException('Remote mutation max_batch is invalid.');}
		if($capabilities['mutation_idempotency_scope']!=='upstream_persistent'){throw new \InvalidArgumentException('Remote mutation idempotency must be upstream-persistent.');}
		$field=$capabilities['record_key_field'];if(!is_string($field)||PanelQueryPath::make($field)->value()!==$field){throw new \InvalidArgumentException('Remote mutation record_key_field must be normalized.');}
		PanelDataMutationCapabilities::fromArray($capabilities);
		$this->capabilities=$capabilities;$this->fingerprint=hash('sha256',PanelHttpDataSourceValue::canonical(['version'=>$version,'capabilities'=>$capabilities]));
	}

	/** @param array<string,mixed> $overrides */
	public static function writable(string $recordKeyField='id',array $overrides=[],int $version=1):self{
		$unknown=array_values(array_diff(array_keys($overrides),self::KEYS));if($unknown!==[]){throw new \InvalidArgumentException('Unknown remote mutation capability: '.(string)$unknown[0]);}
		$base=['adapter'=>'http_remote_mutation','mutations'=>true,'mutation_operations'=>PanelDataMutation::OPERATIONS,'mutation_batch'=>true,'mutation_atomic_batch'=>true,'mutation_max_batch'=>100,'mutation_optimistic_concurrency'=>true,'mutation_idempotency'=>true,'mutation_idempotency_scope'=>'upstream_persistent','mutation_tenant'=>true,'mutation_authorization'=>true,'mutation_returning'=>true,'stable_record_keys'=>true,'record_key_field'=>PanelQueryPath::make($recordKeyField)->value()];
		return new self($version,array_replace($base,$overrides));
	}
	/** @param array<string,mixed> $capabilities */public static function fromArray(int $version,array $capabilities):self{return new self($version,$capabilities);}
	public function version():int{return$this->version;}public function fingerprint():string{return$this->fingerprint;}
	/** @return array<string,mixed> */public function capabilities():array{return$this->capabilities;}
	public function recordKeyField():string{return(string)$this->capabilities['record_key_field'];}
	public function assertSupports(PanelDataMutation|PanelDataMutationBatch $request):void{PanelDataMutationCapabilities::fromArray($this->capabilities)->assertSupports($request);}
	/** @return array<string,mixed> */public function jsonSerialize():array{return['type'=>'panel_http_data_mutation_capability_pin','version'=>$this->version,'fingerprint'=>$this->fingerprint,'capabilities'=>$this->capabilities];}
}
