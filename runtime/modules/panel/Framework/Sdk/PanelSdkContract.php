<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable, host-bound corpus for generated Panel application clients. */
final class PanelSdkContract implements \JsonSerializable {
	public const FORMAT='dataphyre.panel.sdk-contract.v1';
	/** @var array<string,PanelSdkOperation> */private readonly array $operations;
	/** @var array<string,PanelSdkSchema> */private readonly array $events;
	/** @var array<string,PanelSdkSchema> */private readonly array $artifacts;
	/** @var array<string,string> */private readonly array $bindings;
	/** @var array<string,mixed> */private readonly array $metadata;
	private readonly string $fingerprint;

	/** @param array<string,PanelSdkOperation> $operations @param array<string,PanelSdkSchema> $events @param array<string,PanelSdkSchema> $artifacts @param array<string,string> $bindings @param array<string,mixed> $metadata */
	private function __construct(private readonly string $id,private readonly string $version,private readonly string $label,private readonly string $description,array $operations,array $events,array $artifacts,array $bindings,array $metadata){
		PanelSdkGuard::packageId($id);PanelSdkGuard::version($version);PanelSdkGuard::label($label,'SDK contract label',160);PanelSdkGuard::label($description,'SDK contract description',1000,true);
		if(count($operations)>512||count($events)>512||count($artifacts)>512){throw new \LengthException('Panel SDK contract exceeds its member bounds.');}
		ksort($operations,SORT_STRING);ksort($events,SORT_STRING);ksort($artifacts,SORT_STRING);ksort($bindings,SORT_STRING);
		$this->operations=$operations;$this->events=$events;$this->artifacts=$artifacts;$this->bindings=self::normalizeBindings($bindings);$this->metadata=PanelSdkGuard::metadata($metadata);$this->assertRouteUniqueness();$this->fingerprint=PanelSdkGuard::fingerprint($this->contract());
	}

	/** @param array{label?:string,description?:string,bindings?:array<string,string>,metadata?:array<string,mixed>} $options */
	public static function make(string $id,string $version,array $options=[]):self {
		$unknown=array_diff(array_keys($options),['label','description','bindings','metadata']);if($unknown!==[]){throw new \InvalidArgumentException('Panel SDK contract options contain unsupported keys.');}
		$label=(string)($options['label']??ucwords(str_replace(['-','_'],' ',$id)));return new self(PanelSdkGuard::packageId($id),PanelSdkGuard::version($version),$label,(string)($options['description']??''),[],[],[],(array)($options['bindings']??[]),(array)($options['metadata']??[]));
	}

	/** @param array<string,mixed> $payload */
	public static function fromArray(array $payload):self {
		if(($payload['type']??null)!=='panel_sdk_contract'||($payload['format']??null)!==self::FORMAT||!is_string($payload['id']??null)||!is_string($payload['version']??null)||!is_string($payload['label']??null)||!is_string($payload['description']??null)||!is_array($payload['operations']??null)||!is_array($payload['events']??null)||!is_array($payload['artifacts']??null)||!is_array($payload['bindings']??null)||!is_array($payload['metadata']??null)){throw new \UnexpectedValueException('Panel SDK contract payload is malformed.');}
		$operations=[];foreach($payload['operations']as$name=>$operation){if(!is_array($operation)){throw new \UnexpectedValueException('Panel SDK contract operation is malformed.');}$value=PanelSdkOperation::fromArray($operation);if($name!==$value->name()){throw new \UnexpectedValueException('Panel SDK operation key does not match its name.');}$operations[$name]=$value;}
		$events=self::schemaMap($payload['events'],'event');$artifacts=self::schemaMap($payload['artifacts'],'artifact');
		$self=new self($payload['id'],$payload['version'],$payload['label'],$payload['description'],$operations,$events,$artifacts,$payload['bindings'],$payload['metadata']);
		if(!is_string($payload['fingerprint']??null)||!hash_equals($self->fingerprint(),$payload['fingerprint'])){throw new \UnexpectedValueException('Panel SDK contract fingerprint does not verify.');}
		return$self;
	}

	public function withOperation(PanelSdkOperation $operation,bool $replace=false):self {$operations=$this->operations;if(isset($operations[$operation->name()])&&!$replace){throw new \LogicException("Panel SDK operation '{$operation->name()}' is already registered.");}$operations[$operation->name()]=$operation;return$this->copy($operations,$this->events,$this->artifacts);}
	public function withEvent(string $name,PanelSdkSchema $schema,bool $replace=false):self {$name=self::channel($name,'event');$events=$this->events;if(isset($events[$name])&&!$replace){throw new \LogicException("Panel SDK event '{$name}' is already registered.");}$events[$name]=$schema;return$this->copy($this->operations,$events,$this->artifacts);}
	public function withArtifact(string $name,PanelSdkSchema $schema,bool $replace=false):self {$name=self::channel($name,'artifact');$artifacts=$this->artifacts;if(isset($artifacts[$name])&&!$replace){throw new \LogicException("Panel SDK artifact '{$name}' is already registered.");}$artifacts[$name]=$schema;return$this->copy($this->operations,$this->events,$artifacts);}
	/** @param array<string,string> $bindings */public function withBindings(array $bindings):self {return new self($this->id,$this->version,$this->label,$this->description,$this->operations,$this->events,$this->artifacts,array_replace($this->bindings,$bindings),$this->metadata);}

	public function id():string{return$this->id;}public function version():string{return$this->version;}public function label():string{return$this->label;}public function description():string{return$this->description;}public function fingerprint():string{return$this->fingerprint;}
	/** @return array<string,PanelSdkOperation> */public function operations():array{return$this->operations;}/** @return array<string,PanelSdkSchema> */public function events():array{return$this->events;}/** @return array<string,PanelSdkSchema> */public function artifacts():array{return$this->artifacts;}/** @return array<string,string> */public function bindings():array{return$this->bindings;}/** @return array<string,mixed> */public function metadata():array{return$this->metadata;}
	public function operation(string $name):?PanelSdkOperation{return$this->operations[PanelSdkGuard::identifier($name,'SDK operation name')]??null;}
	public function ready():bool{return$this->operations!==[]||$this->events!==[]||$this->artifacts!==[];}
	public function assertReady():void {if(!$this->ready()){throw new \LogicException('Panel SDK contract must expose at least one operation, event, or artifact.');}}

	/** @return array<string,mixed> */
	public function jsonSerialize():array {return PanelManifestContract::stamp(['type'=>'panel_sdk_contract','format'=>self::FORMAT]+$this->contract()+['fingerprint'=>$this->fingerprint,'capabilities'=>['deterministic_codegen'=>true,'request_validation'=>true,'response_validation'=>true,'compatibility_analysis'=>true,'host_bound_routes'=>true,'typed_events'=>$this->events!==[],'typed_artifacts'=>$this->artifacts!==[]],'security'=>['credentials_embedded'=>false,'auth_owned_by_host'=>true,'csrf_owned_by_host'=>true,'same_origin_paths'=>true]]);}

	/** @return array<string,mixed> */
	private function contract():array {
		$operations=[];foreach($this->operations as$name=>$operation){$operations[$name]=$operation->jsonSerialize();}$events=[];foreach($this->events as$name=>$schema){$events[$name]=$schema->definition();}$artifacts=[];foreach($this->artifacts as$name=>$schema){$artifacts[$name]=$schema->definition();}
		return['id'=>$this->id,'version'=>$this->version,'label'=>$this->label,'description'=>$this->description,'panel_contract'=>['schema_version'=>PanelManifestContract::SCHEMA_VERSION,'api_version'=>PanelManifestContract::API_VERSION],'operations'=>$operations,'events'=>$events,'artifacts'=>$artifacts,'bindings'=>$this->bindings,'metadata'=>$this->metadata];
	}

	/** @param array<string,PanelSdkOperation> $operations @param array<string,PanelSdkSchema> $events @param array<string,PanelSdkSchema> $artifacts */private function copy(array $operations,array $events,array $artifacts):self{return new self($this->id,$this->version,$this->label,$this->description,$operations,$events,$artifacts,$this->bindings,$this->metadata);}

	private function assertRouteUniqueness():void {$seen=[];foreach($this->operations as$name=>$operation){$key=$operation->method().' '.$operation->path();if(isset($seen[$key])){throw new \LogicException("Panel SDK operations '{$seen[$key]}' and '{$name}' share one HTTP method and path.");}$seen[$key]=$name;}}

	/** @param array<string,string> $bindings @return array<string,string> */
	private static function normalizeBindings(array $bindings):array {if(count($bindings)>32){throw new \LengthException('Panel SDK contract has too many bindings.');}$out=[];foreach($bindings as$name=>$digest){if(!is_string($name)||!is_string($digest)){throw new \InvalidArgumentException('Panel SDK contract bindings must map names to SHA-256 digests.');}$name=PanelSdkGuard::identifier($name,'SDK binding name');if(preg_match('/token|secret|password|credential|private_key/i',$name)===1){throw new \InvalidArgumentException('Panel SDK binding names cannot describe credentials.');}$out[$name]=PanelSdkGuard::digest($digest,'SDK binding digest');}ksort($out,SORT_STRING);return$out;}

	private static function channel(string $name,string $label):string {$name=strtolower(trim($name));if(preg_match('/^[a-z][a-z0-9_]*(?:[.:][a-z][a-z0-9_]*){0,15}$/D',$name)!==1||strlen($name)>160){throw new \InvalidArgumentException("Panel SDK {$label} name is invalid.");}return$name;}

	/** @param array<string,mixed> $payload @return array<string,PanelSdkSchema> */
	private static function schemaMap(array $payload,string $label):array {$out=[];foreach($payload as$name=>$schema){if(!is_string($name)||!is_array($schema)){throw new \UnexpectedValueException("Panel SDK {$label} schema map is malformed.");}$name=self::channel($name,$label);$out[$name]=PanelSdkSchema::fromArray($schema);}return$out;}
}
