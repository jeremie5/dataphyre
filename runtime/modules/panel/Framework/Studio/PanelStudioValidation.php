<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable trusted-registry validation result with deterministic path diagnostics. */
final class PanelStudioValidation implements \JsonSerializable {
	/** @var list<PanelStudioDiagnostic> */ private readonly array $diagnostics;
	/** @param list<PanelStudioDiagnostic> $diagnostics */
	public function __construct(private readonly ?PanelStudioDefinition $definition,private readonly ?PanelStudioDefinition $normalized,array $diagnostics,private readonly string $registryVersion,private readonly string $registryFingerprint){
		foreach($diagnostics as$diagnostic){if(!$diagnostic instanceof PanelStudioDiagnostic){throw new \InvalidArgumentException('Studio validation diagnostics are invalid.');}}
		usort($diagnostics,static fn(PanelStudioDiagnostic $left,PanelStudioDiagnostic $right):int=>[$left->path(),$left->code(),$left->message()]<=>[$right->path(),$right->code(),$right->message()]);$this->diagnostics=array_values($diagnostics);
		if(preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/',$registryVersion)!==1||preg_match('/^[a-f0-9]{64}$/',$registryFingerprint)!==1){throw new \InvalidArgumentException('Studio validation registry identity is invalid.');}
	}
	public function valid():bool{return$this->diagnostics===[]&&$this->definition instanceof PanelStudioDefinition&&$this->normalized instanceof PanelStudioDefinition;}
	public function diagnostics():array{return$this->diagnostics;}
	public function definition():?PanelStudioDefinition{return$this->definition;}
	public function normalized():PanelStudioDefinition{if(!$this->valid()){throw new PanelStudioSchemaException('Studio definition does not satisfy the trusted schema registry.',$this->diagnostics);}return$this->normalized;}
	public function registryVersion():string{return$this->registryVersion;}
	public function registryFingerprint():string{return$this->registryFingerprint;}
	public function assertValid():self{if(!$this->valid()){throw new PanelStudioSchemaException('Studio definition does not satisfy the trusted schema registry.',$this->diagnostics);}return$this;}
	public function jsonSerialize():array{return['type'=>'panel_studio_validation','version'=>1,'valid'=>$this->valid(),'definition_hash'=>$this->definition?->hash(),'normalized_hash'=>$this->normalized?->hash(),'registry_version'=>$this->registryVersion,'registry_fingerprint'=>$this->registryFingerprint,'diagnostics'=>array_map(static fn(PanelStudioDiagnostic $diagnostic):array=>$diagnostic->jsonSerialize(),$this->diagnostics)];}
}
