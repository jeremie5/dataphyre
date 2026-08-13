<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Typed handler output: durable result plus the events committed to the outbox. */
final class PanelCommandOutcome {
	/** @param list<PanelEventDraft> $events @param array<string,mixed> $metadata */public function __construct(private readonly mixed $result=null,private readonly array $events=[],private readonly array $metadata=[]){foreach($events as$event){if(!$event instanceof PanelEventDraft){throw new \InvalidArgumentException('Command outcome events must be PanelEventDraft instances.');}}PanelOperationsGuard::safeMetadata($metadata,512);}
	public function result():mixed{return$this->result;}/** @return list<PanelEventDraft> */public function events():array{return$this->events;}/** @return array<string,mixed> */public function metadata():array{return$this->metadata;}
	/** @param list<PanelEventDraft> $events @param array<string,mixed> $metadata */public static function make(mixed $result=null,array $events=[],array $metadata=[]):self{return new self($result,$events,$metadata);}
}
