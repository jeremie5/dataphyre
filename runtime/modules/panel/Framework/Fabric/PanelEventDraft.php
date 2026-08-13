<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Unsigned event proposed by a command handler and signed by the fabric store. */
final class PanelEventDraft implements \JsonSerializable {
	/** @param array<string,mixed> $payload @param array<string,mixed> $metadata */public function __construct(private readonly string $type,private readonly string $aggregateType,private readonly string $aggregateId,private readonly array $payload=[],private readonly array $metadata=[]){PanelOperationsGuard::name($type,'fabric event type',160);PanelOperationsGuard::name($aggregateType,'fabric aggregate type');PanelOperationsGuard::identifier($aggregateId,'fabric aggregate id');PanelOperationsGuard::object($payload,'fabric event payload',4096);PanelOperationsGuard::canonical($payload);PanelOperationsGuard::safeMetadata($metadata,512);}
	public function type():string{return$this->type;}public function aggregateType():string{return$this->aggregateType;}public function aggregateId():string{return$this->aggregateId;}/** @return array<string,mixed> */public function payload():array{return$this->payload;}/** @return array<string,mixed> */public function metadata():array{return$this->metadata;}public function jsonSerialize():array{return['type'=>$this->type,'aggregate_type'=>$this->aggregateType,'aggregate_id'=>$this->aggregateId,'payload'=>$this->payload,'metadata'=>$this->metadata];}
}
