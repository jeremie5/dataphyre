<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable document/revision pair used by optimistic Studio editing. */
final class PanelStudioDraft implements \JsonSerializable {
	public function __construct(private readonly PanelStudioDocument $document,private readonly PanelStudioRevision $revision){if($revision->state()!=='draft'){throw new \InvalidArgumentException('Studio drafts must reference a draft revision.');}}
	public function document():PanelStudioDocument{return$this->document;}
	public function revision():PanelStudioRevision{return$this->revision;}
	public function expectedRevision():int{return$this->revision->number();}
	public function jsonSerialize():array{return['type'=>'panel_studio_draft','version'=>1,'document'=>$this->document->jsonSerialize(),'revision'=>$this->revision->jsonSerialize(),'expected_revision'=>$this->expectedRevision()];}
}
