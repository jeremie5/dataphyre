<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Versioned, dependency-aware, optionally reversible migration definition. */
final class PanelMigrationDefinition implements \JsonSerializable {
	private readonly \Closure $up; private readonly ?\Closure $down; private readonly ?\Closure $preflight;
	/** @param list<string> $dependencies @param list<string> $capabilities */
	private function __construct(private readonly string $id,private readonly string $scope,private readonly PanelMigrationVersion $from,private readonly PanelMigrationVersion $to,callable $up,?callable $down,?callable $preflight,private readonly array $dependencies,private readonly array $capabilities,private readonly int $batchSize,private readonly string $tenantMode,private readonly bool $idempotent,private readonly ?string $deprecatedBy,private readonly ?string $deprecationGuidance){$this->up=\Closure::fromCallable($up);$this->down=$down===null?null:\Closure::fromCallable($down);$this->preflight=$preflight===null?null:\Closure::fromCallable($preflight);}
	/** @param array<string,mixed> $options */
	public static function make(string $id,string $scope,PanelMigrationVersion $from,PanelMigrationVersion $to,callable $up,array $options=[]):self {
		$id=PanelMigrationIntegrity::identifier($id,'migration id');$scope=PanelMigrationIntegrity::identifier($scope,'scope');
		if(!$from->before($to)){throw new \InvalidArgumentException('Panel migrations must move monotonically to a strictly newer semantic/schema version.');}
		$dependencies=self::identifiers($options['dependencies']??[],'dependency');$capabilities=self::identifiers($options['capabilities']??[],'capability');
		$batch=(int)($options['batch_size']??500);if($batch<1||$batch>10000){throw new \InvalidArgumentException('Panel migration batch sizes must be between 1 and 10000.');}$mode=strtolower(trim((string)($options['tenant_mode']??'optional')));
		if(!in_array($mode,['shared','optional','required'],true)){throw new \InvalidArgumentException('Panel migration tenant mode must be shared, optional, or required.');}
		foreach(['down','preflight']as$callback){if(array_key_exists($callback,$options)&&$options[$callback]!==null&&!is_callable($options[$callback])){throw new \InvalidArgumentException("Panel migration {$callback} must be callable or null.");}}if(array_key_exists('idempotent',$options)&&!is_bool($options['idempotent'])){throw new \InvalidArgumentException('Panel migration idempotent must be boolean.');}
		$deprecatedBy=isset($options['deprecated_by'])?PanelMigrationIntegrity::identifier((string)$options['deprecated_by'],'replacement migration id'):null;$guidance=isset($options['deprecation_guidance'])?trim((string)$options['deprecation_guidance']):null;
		if($deprecatedBy===$id){throw new \InvalidArgumentException('A deprecated Panel migration cannot replace itself.');}if($deprecatedBy!==null&&($guidance===null||$guidance==='')){throw new \InvalidArgumentException('Deprecated Panel migrations require explicit migration guidance.');}
		return new self($id,$scope,$from,$to,$up,$options['down']??null,$options['preflight']??null,$dependencies,$capabilities,$batch,$mode,$options['idempotent']??true,$deprecatedBy,$guidance);
	}
	public function id():string{return$this->id;} public function scope():string{return$this->scope;} public function from():PanelMigrationVersion{return$this->from;} public function to():PanelMigrationVersion{return$this->to;}
	/** @return list<string> */ public function dependencies():array{return$this->dependencies;} /** @return list<string> */ public function capabilities():array{return$this->capabilities;}
	public function batchSize():int{return$this->batchSize;} public function tenantMode():string{return$this->tenantMode;} public function idempotent():bool{return$this->idempotent;} public function reversible():bool{return$this->down!==null;} public function hasPreflight():bool{return$this->preflight!==null;}
	public function deprecatedBy():?string{return$this->deprecatedBy;} public function deprecationGuidance():?string{return$this->deprecationGuidance;}
	public function supportsTenant(?string $tenant):bool{$tenant=PanelMigrationIntegrity::tenant($tenant);return$this->tenantMode==='optional'||($this->tenantMode==='required'&&$tenant!==null)||($this->tenantMode==='shared'&&$tenant===null);}
	public function migrate(PanelMigrationContext $context):PanelMigrationBatch{$result=($this->up)($context);if(!$result instanceof PanelMigrationBatch){throw new \UnexpectedValueException("Panel migration '{$this->id}' up handler must return PanelMigrationBatch.");}return$result;}
	public function compensate(PanelMigrationContext $context):PanelMigrationBatch{if($this->down===null){throw new \LogicException("Panel migration '{$this->id}' does not define compensation.");}$result=($this->down)($context);if(!$result instanceof PanelMigrationBatch){throw new \UnexpectedValueException("Panel migration '{$this->id}' down handler must return PanelMigrationBatch.");}return$result;}
	public function inspect(PanelMigrationContext $context):mixed{return$this->preflight===null?true:($this->preflight)($context);}
	public function digest():string{return PanelMigrationIntegrity::digest($this->jsonSerialize());}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return['type'=>'panel_migration_definition','manifest_version'=>1,'id'=>$this->id,'scope'=>$this->scope,'from'=>$this->from->jsonSerialize(),'to'=>$this->to->jsonSerialize(),'dependencies'=>$this->dependencies,'required_capabilities'=>$this->capabilities,'batch_size'=>$this->batchSize,'tenant_mode'=>$this->tenantMode,'idempotent'=>$this->idempotent,'reversible'=>$this->reversible(),'preflight'=>$this->hasPreflight(),'deprecated'=>$this->deprecatedBy!==null,'deprecated_by'=>$this->deprecatedBy,'deprecation_guidance'=>$this->deprecationGuidance];}
	/** @return list<string> */ private static function identifiers(mixed $values,string $label):array{if(!is_array($values)){throw new \InvalidArgumentException("Panel migration {$label} list must be an array.");}$out=[];foreach($values as$value){$out[]=PanelMigrationIntegrity::identifier((string)$value,$label);}return array_values(array_unique($out));}
}
