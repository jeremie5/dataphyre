<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** A reserved record paired with the only worker proof allowed to mutate it. */
final class PanelOperationReservation implements \JsonSerializable {
	public function __construct(private readonly PanelOperationRecord $record,private readonly PanelOperationLease $lease){
		if($record->id()!==$lease->operationId() || $record->worker()!==$lease->worker() || !PanelOperationStatus::active($record->status())){ throw new \InvalidArgumentException('Panel operation reservation record and lease do not describe the same active worker.'); }
	}
	public function record():PanelOperationRecord { return $this->record; }
	public function lease():PanelOperationLease { return $this->lease; }
	/** @return array<string,mixed> */ public function jsonSerialize():array { return ['type'=>'panel_operation_reservation','schema_version'=>1,'operation'=>['id'=>$this->record->id(),'status'=>$this->record->status(),'revision'=>$this->record->revision(),'attempt'=>$this->record->attempt()],'lease'=>$this->lease->jsonSerialize()]; }
}
