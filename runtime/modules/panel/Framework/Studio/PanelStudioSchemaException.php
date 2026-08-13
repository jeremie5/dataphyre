<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Fail-closed exception retaining bounded path diagnostics for schema or artifact mismatch. */
final class PanelStudioSchemaException extends \RuntimeException implements \JsonSerializable {
	/** @var list<PanelStudioDiagnostic> */ private readonly array $diagnostics;
	/** @param list<PanelStudioDiagnostic> $diagnostics */
	public function __construct(string $message,array $diagnostics){if($diagnostics===[]){throw new \InvalidArgumentException('Studio schema exceptions require at least one diagnostic.');}foreach($diagnostics as$diagnostic){if(!$diagnostic instanceof PanelStudioDiagnostic){throw new \InvalidArgumentException('Studio schema exception diagnostics are invalid.');}}$this->diagnostics=array_values($diagnostics);parent::__construct($message);}
	public function diagnostics():array{return$this->diagnostics;}
	public function jsonSerialize():array{return['type'=>'panel_studio_schema_exception','version'=>1,'message'=>$this->getMessage(),'diagnostics'=>array_map(static fn(PanelStudioDiagnostic $diagnostic):array=>$diagnostic->jsonSerialize(),$this->diagnostics)];}
}
